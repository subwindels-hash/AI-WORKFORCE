<?php
namespace AIWorkforce\Sports;

use AIWorkforce\Backtest\Backtester;
use AIWorkforce\Persistence\AuditRepository;
use AIWorkforce\Persistence\SportsRepository;
use AIWorkforce\Sports\Providers\SportsProviderManager;

/**
 * Idempotent scheduled jobs (spec §31).
 *
 * Every job is guarded by an execution key: running the same job twice never
 * creates duplicates. Every run records start/end, status, records
 * processed/created/updated, errors, and provider. Jobs fail gracefully —
 * one failing job never aborts the sweep, and failures are audited.
 *
 *   php index.php tools sports-cron [job]
 */
class SportsCronService
{
    public const JOBS = ['fixtures', 'odds', 'live', 'results', 'quality', 'ticket', 'settlement', 'performance', 'monitoring', 'cleanup'];
    public const ODDS_BATCH_SIZE = 50;

    public function __construct(
        private SportsRepository $repo,
        private AuditRepository $audit,
        private SportsIntelligence $sports
    ) {}

    public function runAll(?string $date = null, array $options = []): array
    {
        $config = $this->sports->configuration->active();
        $date = DailyTicketDate::normalize($date, (string) ($config['system_timezone'] ?? 'UTC'));
        $options['scheduled'] = $options['scheduled'] ?? true;
        $summary = [];
        foreach (self::JOBS as $job) {
            try {
                $summary[$job] = $this->run($job, $date, $options);
            } catch (\Throwable $e) {
                $summary[$job] = ['status' => 'FAILED', 'error' => mb_substr($e->getMessage(), 0, 300)];
                $this->audit->emit('SPORTS_JOB_FAILED', "Sports job {$job} failed: " . $e->getMessage(), ['job' => $job]);
            }
        }
        $this->audit->emit('SPORTS_CRON_RUN', 'Sports scheduled jobs: ' . json_encode(array_map(fn($s) => $s['status'] ?? 'FAILED', $summary)), $summary, 'system');
        return $summary;
    }

    public function run(string $job, ?string $date = null, array $options = []): array
    {
        $config = $this->sports->configuration->active();
        $date = DailyTicketDate::normalize($date, (string) ($config['system_timezone'] ?? 'UTC'));
        return match ($job) {
            'fixtures' => $this->jobFixtures($date),
            'odds' => $this->jobOdds($date),
            'live' => $this->jobLive($date),
            'results' => $this->jobResults($date),
            'quality' => $this->jobQuality($date),
            'ticket' => $this->jobTicket($date, $options),
            'settlement' => $this->jobSettlement($date),
            'performance' => $this->jobPerformance($date),
            'monitoring' => $this->jobMonitoring($date),
            'cleanup' => $this->jobCleanup($date),
            default => throw new \InvalidArgumentException('unknown sports job: ' . $job),
        };
    }

    /**
     * The automatic odds-prediction cycle used after enable/configuration and
     * by focused workers. It reuses the normal idempotent jobs and does not run
     * unrelated settlement/performance work.
     */
    public function runDailyGenerationCycle(?string $date = null, array $options = []): array
    {
        $config = $this->sports->configuration->active();
        $timezone = (string) ($config['system_timezone'] ?? 'UTC');
        $date = DailyTicketDate::normalize($date, $timezone);

        // A persisted ticket short-circuits provider work as well as generation.
        $slot = $this->repo->findDailyTicket($date);
        if (is_array($slot) && !empty($slot['ticket_id'])) {
            $ticket = $this->repo->findTicket((string) $slot['ticket_id']);
            if ($ticket !== null && $this->repo->ticketSelections((string) $slot['ticket_id']) !== []) {
                return ['date' => $date, 'timezone' => $timezone, 'ticket' => [
                    'status' => 'GENERATED', 'generationStatus' => 'GENERATED',
                    'ticketId' => (string) $slot['ticket_id'], 'existing' => true,
                    'message' => 'Existing persisted daily ticket returned; provider sync was not repeated',
                ]];
            }
        }

        $options['scheduled'] = $options['scheduled'] ?? false;
        $summary = ['date' => $date, 'timezone' => $timezone];
        foreach (['fixtures', 'odds', 'quality', 'ticket'] as $job) {
            try { $summary[$job] = $this->run($job, $date, $options); }
            catch (\Throwable $e) {
                $summary[$job] = ['status' => 'FAILED', 'error' => mb_substr($e->getMessage(), 0, 300)];
                if ($job !== 'ticket') continue;
            }
        }
        return $summary;
    }

    private function jobFixtures(string $date): array
    {
        $config = $this->sports->configuration->active();
        $timezone = DailyTicketDate::configuredTimezone((string) ($config['system_timezone'] ?? 'UTC'));
        $from = $date;
        $to = gmdate('Y-m-d', strtotime($date . ' +13 days'));
        $out = [];
        foreach ($this->sports->providers->all() as $provider) {
            $key = 'fixtures:' . $from . ':' . $to . ':' . $provider->id();
            $result = $this->sports->sync->syncFixtures($provider, [
                'from' => $from, 'to' => $to, 'timezone' => $timezone,
                'limit' => 50, 'page' => 1,
            ], $key);
            $out[$provider->id()] = $result;
        }
        return $this->combine('FIXTURES_SYNC', $out, 'no providers configured; nothing synchronized (nothing fabricated)');
    }

    private function jobOdds(string $date): array
    {
        $config = $this->sports->configuration->active();
        $window = DailyTicketDate::utcWindow($date, (string) ($config['system_timezone'] ?? 'UTC'));
        $toInclusive = gmdate('Y-m-d\TH:i:sP', $window['endTimestamp'] - 1);
        $matches = $this->repo->listMatches(['from' => $window['start'], 'to' => $toInclusive, 'status' => 'SCHEDULED'], 1000);
        $sources = $this->repo->listProviders();
        $now = time();
        $freshReused = 0;
        $eligible = [];
        foreach ($matches as $match) {
            $kickoff = strtotime((string) ($match['kickoff_at'] ?? ''));
            if ($kickoff === false || $kickoff <= $now + 2 * 3600) continue;
            $provider = $this->providerById($sources, (int) $match['provider_id']);
            if ($provider === null) continue;
            $latest = $this->repo->latestOdds((int) $match['id']);
            $maxAge = OddsFreshnessEngine::maxAgeFor(
                $latest['market'] ?? null,
                $provider->id()
            );
            if ($latest !== null && $this->ageOf((string) ($latest['observed_at'] ?? '')) <= $maxAge) {
                $freshReused++;
                continue;
            }
            $eligible[] = $match;
        }

        // One worker invocation is one controlled batch. The next scheduled
        // retry skips rows made fresh here and naturally advances to the next
        // batch; it never requests all 2,000 stored fixtures at once.
        $batchSize = self::ODDS_BATCH_SIZE;
        $envBatch = getenv('WINDELS_SPORTS_ODDS_BATCH_SIZE');
        if (is_string($envBatch) && ctype_digit($envBatch) && (int) $envBatch > 0) $batchSize = min(self::ODDS_BATCH_SIZE, (int) $envBatch);
        $deferred = max(0, count($eligible) - $batchSize);
        $matches = array_slice($eligible, 0, $batchSize);
        $out = []; $processed = 0; $created = 0; $errors = [];

        // Round-addressable matches use one bulk request; everything else is
        // per fixture, still within the 50-fixture batch.
        $byRound = []; $legacy = [];
        foreach ($matches as $match) {
            $provider = $this->providerById($sources, (int) $match['provider_id']);
            if ($provider === null) continue;
            $roundId = (string) ($match['round_id'] ?? '');
            if ($roundId !== '' && method_exists($provider, 'round')) $byRound[$provider->id() . ':' . $roundId][] = $match;
            else $legacy[] = $match;
        }
        $bucket = gmdate('YmdHi', (int) (floor(time() / 900) * 900));
        foreach ($byRound as $groupKey => $groupMatches) {
            [$providerCode, $roundId] = explode(':', (string) $groupKey, 2);
            $provider = $this->sports->providers->provider($providerCode);
            if ($provider === null) { $legacy = array_merge($legacy, $groupMatches); continue; }
            $key = 'odds-round:' . $providerCode . ':' . $roundId . ':' . $date . ':' . $bucket;
            $result = $this->sports->sync->syncRound($provider, $roundId, $key, ['results' => false]);
            foreach ($groupMatches as $match) $out[(string) $match['id']] = $result['status'];
            $processed += count($groupMatches);
            if (($result['status'] ?? '') === 'COMPLETED') $created += (int) ($result['created'] ?? 0);
            if (($result['status'] ?? '') === 'FAILED') $errors[] = implode('; ', $result['errors'] ?? []);
        }
        foreach ($legacy as $match) {
            $provider = $this->providerById($sources, (int) $match['provider_id']);
            if ($provider === null) continue;
            $key = 'odds:' . (int) $match['id'] . ':' . $date . ':' . $provider->id() . ':' . $bucket;
            $result = $this->sports->sync->syncOdds($provider, (string) $match['external_id'], $key);
            $out[(string) $match['id']] = $result['status'];
            $processed++;
            if (($result['status'] ?? '') === 'COMPLETED') $created += (int) ($result['created'] ?? 0);
            if (($result['status'] ?? '') === 'FAILED') $errors[] = implode('; ', $result['errors'] ?? []);
        }
        $summary = sprintf('%d stale/missing-odds fixture(s) refreshed in a batch of at most %d; %d fresh reused; %d deferred', $processed, $batchSize, $freshReused, $deferred);
        return $this->combine('ODDS_SYNC', $out, $summary, $processed, $created, $errors) + [
            'eligibleFixtures' => count($matches) + $freshReused + $deferred,
            'freshOddsReused' => $freshReused, 'staleOrMissingOdds' => count($eligible),
            'batchSize' => $batchSize, 'deferred' => $deferred,
        ];
    }

    /**
     * Live score sweep — self-gated by LiveScoreService's throttle, so this
     * job is safe to tick every minute: it spends a provider request only
     * when WINDELS_SPORTS_LIVE_REFRESH_SECONDS (default 60) has elapsed, and
     * records a SPORTS_GOAL_SCORED audit event per goal it observes.
     */
    private function jobLive(string $date): array
    {
        $result = $this->sports->liveScores->refresh();
        return [
            'status' => $result['status'],
            'providers' => $result['providers'] ?? [],
            'goals' => count($result['goalEvents'] ?? []),
            'errors' => $result['errors'] ?? [],
        ];
    }

    private function jobResults(string $date): array
    {
        $since = gmdate('Y-m-d', strtotime($date . ' -2 days')) . 'T00:00:00+00:00';
        $matches = $this->repo->listMatches(['from' => $since, 'to' => $date . 'T23:59:59+00:00'], 500);
        $out = []; $processed = 0; $errors = [];
        foreach ($matches as $match) {
            if ($this->repo->findResultByMatch((int) $match['id']) !== null) continue;
            $provider = $this->providerById($this->repo->listProviders(), (int) $match['provider_id']);
            if ($provider === null) continue;
            $key = 'results:' . (int) $match['id'] . ':' . $date . ':' . $provider->id();
            $result = $this->sports->sync->syncResults($provider, (string) $match['external_id'], $key);
            $out[(string) $match['id']] = $result['status'];
            $processed++;
            if (($result['status'] ?? '') === 'FAILED') $errors[] = implode('; ', $result['errors'] ?? []);
        }
        return $this->combine('RESULTS_SYNC', $out, count($out) . ' match(es) checked', $processed, $processed, $errors);
    }

    private function jobQuality(string $date): array
    {
        $hour = gmdate('H');
        $key = 'quality:' . $date . ':' . $hour;
        $run = $this->repo->startJobRun(['id' => Backtester::uuid(), 'jobType' => 'QUALITY_RECALC', 'executionKey' => $key]);
        if ($run === null) return ['status' => 'DUPLICATE_SKIPPED', 'executionKey' => $key];
        $config = $this->sports->configuration->active();
        $timezone = DailyTicketDate::configuredTimezone((string) ($config['system_timezone'] ?? 'UTC'));
        $window = DailyTicketDate::utcWindow($date, $timezone);
        $end = gmdate('c', $window['endTimestamp'] - 1);
        $matches = array_merge($this->repo->listMatches(['from' => $window['start'], 'to' => $end, 'status' => 'SCHEDULED'], 500), $this->repo->listMatches(['status' => \AIWorkforce\Sports\LiveScoreService::LIVE_STATUSES], 200));
        $updated = 0; $errors = [];
        foreach ($matches as $match) {
            try {
                $payload = SportsDataNormalizer::document($match['payload'] ?? null);
                $matchArr = array_merge($match, ['externalId' => $match['external_id'], 'homeTeam' => $match['home_team'], 'awayTeam' => $match['away_team'], 'kickoff' => $match['kickoff_at'], 'context' => $payload['context'] ?? null, 'sourceTimestamp' => $match['source_timestamp']]);
                // Any supported market counts: the recalc judges the newest stored
                // price, not one hard-coded selection.
                $odds = $this->repo->latestOdds((int) $match['id']);
                $provider = $this->providerById($this->repo->listProviders(), (int) $match['provider_id']);
                $reliability = 0.0;
                if ($provider !== null) {
                    $health = $provider->health();
                    $reliability = (float) ($health['reliability'] ?? 0);
                }
                // Odds freshness is judged against the configurable odds TTL
                // (market/provider aware) — NOT a hard-coded hour, which marked
                // every once-a-day odds sync stale for the rest of the day.
                $maxOddsAge = OddsFreshnessEngine::maxAgeFor(null, $provider !== null ? $provider->id() : null);
                $quality = $this->sports->quality->assess($matchArr, [
                    'oddsAvailable' => $odds !== null,
                    'recentFormAvailable' => !empty($matchArr['context']['recentForm']),
                    'providerReliability' => $reliability,
                    'oddsAgeSeconds' => $odds ? $this->ageOf($odds['observed_at']) : $this->ageOf($match['source_timestamp']),
                    'maxOddsAgeSeconds' => $maxOddsAge,
                    'oddsFresh' => $odds !== null && $this->ageOf($odds['observed_at']) <= $maxOddsAge,
                ]);
                $this->repo->saveQuality((int) $match['id'], $quality);
                $updated++;
            } catch (\Throwable $e) {
                $errors[] = mb_substr($e->getMessage(), 0, 200);
            }
        }
        $this->repo->finishJobRun($run['id'], ['status' => 'COMPLETED', 'processed' => count($matches), 'created' => 0, 'updated' => $updated, 'errors' => $errors]);
        $this->audit->emit('SPORTS_QUALITY_RECALC', 'Data quality recalculated for ' . $updated . ' active match(es)', ['matches' => count($matches), 'errors' => $errors]);
        return ['status' => 'COMPLETED', 'matches' => count($matches), 'updated' => $updated, 'errors' => $errors];
    }

    private function jobTicket(string $date, array $options = []): array
    {
        // Never pre-skip on provider health: fresh stored fixtures/odds may be
        // sufficient to generate without a provider call. DailyTicketService
        // owns the persisted RUNNING/GENERATED/FAILED/RETRYING state and records
        // the outage if stored data cannot complete the run.
        $dailyOptions = [
            'scheduled' => (bool) ($options['scheduled'] ?? false),
        ];
        if (!empty($options['force'])) $dailyOptions['force'] = true;
        $result = $this->sports->dailyTickets->runDaily($date, null, $dailyOptions);
        return $result;
    }

    private function jobSettlement(string $date): array
    {
        $hour = gmdate('H');
        $key = 'settlement:' . $date . ':' . $hour;
        $run = $this->repo->startJobRun(['id' => Backtester::uuid(), 'jobType' => 'SETTLEMENT_SWEEP', 'executionKey' => $key]);
        if ($run === null) return ['status' => 'DUPLICATE_SKIPPED', 'executionKey' => $key];
        $settled = 0; $stillPending = 0; $errors = [];
        foreach ($this->repo->listTickets(['status' => 'PENDING'], 200) as $ticket) {
            try {
                $res = $this->sports->settlement->settlePending((string) $ticket['id']);
                if (($res['status'] ?? 'PENDING') !== 'PENDING') $settled++;
                else $stillPending++;
            } catch (\Throwable $e) {
                $errors[] = mb_substr($e->getMessage(), 0, 200);
            }
        }
        $this->repo->finishJobRun($run['id'], ['status' => 'COMPLETED', 'processed' => $settled + $stillPending, 'created' => 0, 'updated' => $settled, 'errors' => $errors]);
        $this->audit->emit('SPORTS_SETTLEMENT_SWEEP', 'Settlement sweep: ' . $settled . ' ticket(s) finalized, ' . $stillPending . ' still pending verified results', ['settled' => $settled, 'pending' => $stillPending, 'errors' => $errors]);
        return ['status' => 'COMPLETED', 'settled' => $settled, 'pending' => $stillPending, 'errors' => $errors];
    }

    private function jobPerformance(string $date): array
    {
        $hour = gmdate('H');
        $key = 'performance:' . $date . ':' . $hour;
        $run = $this->repo->startJobRun(['id' => Backtester::uuid(), 'jobType' => 'PERFORMANCE_SNAPSHOT', 'executionKey' => $key]);
        if ($run === null) return ['status' => 'DUPLICATE_SKIPPED', 'executionKey' => $key];
        $asOf = gmdate('c', strtotime(gmdate('Y-m-d H') . ':00:00'));
        $snapshots = 0; $errors = [];
        foreach (['7' => '7', '30' => '30', '90' => '90', 'ALL' => 'ALL'] as $window => $label) {
            try {
                $filter = $window === 'ALL' ? [] : ['from' => gmdate('Y-m-d', strtotime($date . ' -' . ($window === '7' ? 6 : $window) . ' days')) . 'T00:00:00+00:00', 'to' => $date . 'T23:59:59+00:00'];
                $this->repo->savePerformanceSnapshot($asOf, $window, $this->sports->performanceReport($filter));
                $snapshots++;
            } catch (\Throwable $e) {
                $errors[] = mb_substr($e->getMessage(), 0, 200);
            }
        }
        $this->repo->finishJobRun($run['id'], ['status' => 'COMPLETED', 'processed' => 4, 'created' => $snapshots, 'updated' => 0, 'errors' => $errors]);
        return ['status' => 'COMPLETED', 'snapshots' => $snapshots, 'errors' => $errors];
    }

    private function jobMonitoring(string $date): array
    {
        $hour = gmdate('H');
        $key = 'monitoring:' . $date . ':' . $hour;
        $run = $this->repo->startJobRun(['id' => Backtester::uuid(), 'jobType' => 'MONITORING', 'executionKey' => $key]);
        if ($run === null) return ['status' => 'DUPLICATE_SKIPPED', 'executionKey' => $key];
        $healthReports = [];
        foreach ($this->repo->listProviders() as $p) {
            $report = $this->sports->providerHealth->assess($p, $this->repo->listHealth((int) $p['id'], 20), array_filter($this->repo->listJobRuns(null, 100), fn($r) => ($r['provider'] ?? null) === ($p['provider_code'] ?? null)));
            $healthReports[$p['provider_code'] ?? (string) $p['id']] = $report;
        }
        $drift = $this->sports->driftMonitor->monitor();
        $this->repo->finishJobRun($run['id'], ['status' => 'COMPLETED', 'processed' => count($healthReports) + 1, 'created' => 0, 'updated' => 0, 'errors' => []]);
        $this->audit->emit('SPORTS_MONITORING', 'Provider health + model drift monitoring: ' . $drift['warnings'] . ' drift warning(s)', ['providers' => $healthReports, 'driftWarnings' => $drift['warnings']]);
        return ['status' => 'COMPLETED', 'providers' => $healthReports, 'driftWarnings' => $drift['warnings']];
    }

    private function jobCleanup(string $date): array
    {
        $key = 'cleanup:' . $date;
        $run = $this->repo->startJobRun(['id' => Backtester::uuid(), 'jobType' => 'DATA_CLEANUP', 'executionKey' => $key]);
        if ($run === null) return ['status' => 'DUPLICATE_SKIPPED', 'executionKey' => $key];
        $cutoff = gmdate('Y-m-d H:i:s', strtotime('-90 days'));
        // Retention: old job-run and health-observation rows are operational history,
        // never decision inputs — predictions/tickets/results are kept forever.
        $this->repo->deleteOldJobRuns($cutoff);
        $this->repo->deleteOldHealth($cutoff);
        $this->repo->finishJobRun($run['id'], ['status' => 'COMPLETED', 'processed' => 0, 'created' => 0, 'updated' => 0, 'errors' => []]);
        $this->audit->emit('SPORTS_DATA_CLEANUP', 'Retention cleanup applied (90-day operational history)', ['cutoff' => $cutoff]);
        return ['status' => 'COMPLETED', 'cutoff' => $cutoff];
    }

    private function providerById(array $providers, int $id): ?\AIWorkforce\Sports\Providers\SportsDataProvider
    {
        $code = null;
        foreach ($providers as $p) if ((int) $p['id'] === $id) { $code = $p['provider_code']; break; }
        if ($code === null) return null;
        return $this->sports->providers->provider($code);
    }

    private function ageOf(?string $value): int
    {
        if (!$value) return PHP_INT_MAX;
        try { return max(0, time() - (new \DateTimeImmutable((string) $value))->getTimestamp()); }
        catch (\Throwable $e) { return PHP_INT_MAX; }
    }

    private function combine(string $label, array $results, string $summary, int $processed = 0, int $created = 0, array $errors = []): array
    {
        $status = 'COMPLETED';
        if (!$results) {
            $status = 'SKIPPED';
        } elseif (array_filter($results, fn($r) => $r === 'FAILED')) {
            $status = 'PARTIAL';
        }
        $this->audit->emit('SPORTS_' . $label, 'Sports ' . strtolower(str_replace('_', ' ', $label)) . ': ' . $summary, ['results' => $results, 'errors' => $errors]);
        return ['status' => $status, 'results' => $results, 'summary' => $summary, 'processed' => $processed, 'created' => $created, 'errors' => $errors];
    }
}
