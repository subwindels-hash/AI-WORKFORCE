<?php
namespace AIWorkforce\Sports;

use AIWorkforce\Backtest\Backtester;
use AIWorkforce\Persistence\AuditRepository;
use AIWorkforce\Persistence\SportsRepository;
use AIWorkforce\Sports\Providers\ProviderException;
use AIWorkforce\Sports\Providers\SportsDataProvider;
use AIWorkforce\Sports\Providers\SportsProviderManager;

/**
 * AI Ticket Engine (spec §16/§17/§19) — the daily end-to-end pipeline:
 *
 *   fixtures sync → odds sync → data quality → match intelligence → features
 *   → prediction → calibration → value → confidence → risk → correlation
 *   → ticket optimization → governance (user approval by default)
 *
 * Idempotent per (date, configuration version): running the same job twice
 * never creates duplicate tickets. When nothing qualifies the engine stores
 * NO_QUALIFIED_TICKET with the exact rejection summary — an expected,
 * first-class outcome (spec §3).
 *
 * A provider outage is NOT that outcome. When every configured data provider
 * fails (quota exhausted, 400/404 misconfiguration, offline) the run stores
 * DATA_UNAVAILABLE with the per-provider status codes, and the run's execution
 * key is released so the next sweep can retry once a provider recovers — a
 * blocked engine must never masquerade as "no qualified games today".
 */
class DailyTicketService
{
    private FormResolver $formResolver;

    public function __construct(
        private SportsRepository $repo,
        private AuditRepository $audit,
        private SportsProviderManager $providers,
        private ConfigurationService $config,
        private DataQualityEngine $quality,
        private PredictionPipeline $pipeline,
        private TicketOptimizer $optimizer,
        private TicketGovernance $governance,
        private DecisionRecorder $decisions,
        ?FormResolver $formResolver = null,
    ) {
        $this->formResolver = $formResolver ?? new FormResolver();
    }

    public function runDaily(?string $date = null, ?string $executionKey = null): array
    {
        $date = $date ?? gmdate('Y-m-d');
        $config = $this->config->active();
        $key = $executionKey ?? 'daily-ticket:' . $date . ':v' . $config['version'];
        $run = $this->repo->startJobRun(['id' => Backtester::uuid(), 'jobType' => 'DAILY_TICKET', 'executionKey' => $key]);
        if ($run === null) return ['status' => 'DUPLICATE_SKIPPED', 'executionKey' => $key];

        $errors = [];
        $status = 'NO_QUALIFIED_TICKET';
        $message = '';
        $ticketId = null;
        $evaluated = 0;
        $recorded = 0;
        $rejections = 0;
        $rejectionSummary = [];
        $provider = null;
        $modelVersionId = null;
        $providerFailures = [];   // providerId → "STATUS: detail" (redacted)
        $providerStatuses = [];   // providerId → STATUS
        $dataState = 'OK';        // OK | DATA_UNAVAILABLE | NO_PROVIDER | DISABLED

        try {
            if (!(bool) $config['module_enabled']) {
                $message = 'Sports Intelligence module is disabled';
                $dataState = 'DISABLED';
            } elseif (!(bool) $config['ticket_engine_enabled']) {
                $message = 'AI Ticket Engine is disabled';
                $dataState = 'DISABLED';
            } elseif (!in_array($config['engine_mode'], ['AI_TICKET_GENERATION', 'USER_APPROVAL_REQUIRED', 'AUTOMATED_EXECUTION'], true)) {
                $message = 'engine mode ' . $config['engine_mode'] . ' does not generate tickets';
                $dataState = 'DISABLED';
            } elseif (!$this->providers->configured()) {
                $message = 'no sports provider configured (DISABLED_NO_PROVIDER) — nothing is fabricated';
                $dataState = 'NO_PROVIDER';
            } else {
                $attempt = $this->providers->withFallback('fixtures', fn($p) => $p->fixtures(['from' => $date, 'to' => $date]));
                if (!$attempt['ok']) {
                    // Every provider failed. This is a DATA outage, not a
                    // prediction outcome: report it as such, keep the
                    // per-provider status codes, and do not claim "no
                    // qualified games" for a day nobody could look at.
                    $status = 'DATA_UNAVAILABLE';
                    $dataState = 'DATA_UNAVAILABLE';
                    $providerFailures = $attempt['failures'];
                    $providerStatuses = $attempt['failureStatuses'] ?? [];
                    $message = 'all configured sports-data providers failed — ' . ($attempt['summary'] ?: SportsProviderManager::summarize('fixtures', $providerStatuses));
                    $errors[] = 'provider failure: ' . json_encode($attempt['failures']);
                } else {
                    $provider = $attempt['provider'];
                    $providerId = (int) $this->repo->ensureProvider($provider, $provider)['id'];
                    // Enrich raw fixtures with recentForm from team statistics
                    $enrichedFixtures = $this->formResolver->enrich($this->providers->provider($provider), $attempt['result']);
                    // Bulk-fetch the day's odds in one round() call per
                    // matchday when the provider exposes the round endpoint.
                    // Matches it does not cover fall back to per-fixture odds().
                    $roundOdds = $this->fetchRoundOdds($provider, $this->providers->provider($provider), $enrichedFixtures, $errors);
                    $candidates = [];
                    foreach ($enrichedFixtures as $rawFixture) {
                        try {
                            $match = SportsDataNormalizer::fixture($rawFixture, $provider);
                            $saved = $this->repo->saveMatch($providerId, $match);
                        } catch (\Throwable $e) {
                            $errors[] = 'fixture rejected: ' . mb_substr($e->getMessage(), 0, 200);
                            continue;
                        }
                        $evaluated++;
                        $matchRow = $this->repo->findMatchById((int) $saved['id']);
                        if ($matchRow === null) continue;

                        $oddsRow = $this->repo->latestOdds((int) $saved['id'], 'TOTAL_GOALS', 'OVER_1_5');
                        if ($oddsRow === null) {
                            // Prefer the bulk round fetch (one request per
                            // matchday) over a per-fixture odds() call.
                            $rawOdds = $roundOdds[$match['externalId']] ?? null;
                            if ($rawOdds === null) {
                                $oddsAttempt = $this->providers->withFallback('odds', fn($p) => $p->odds($match['externalId']), $provider);
                                if ($oddsAttempt['ok'] && is_array($oddsAttempt['result'] ?? null)) $rawOdds = $oddsAttempt['result'];
                            }
                            if (is_array($rawOdds) && $rawOdds !== []) {
                                foreach ($rawOdds as $rawOddsRow) {
                                    try {
                                        $this->repo->saveOdds((int) $saved['id'], $providerId, SportsDataNormalizer::odds($rawOddsRow, $provider));
                                    } catch (\Throwable $e) {
                                        $errors[] = 'odds rejected: ' . mb_substr($e->getMessage(), 0, 200);
                                    }
                                }
                                $oddsRow = $this->repo->latestOdds((int) $saved['id'], 'TOTAL_GOALS', 'OVER_1_5');
                            }
                        }
                        $odds = $oddsRow ? ['market' => $oddsRow['market'], 'selection' => $oddsRow['selection'], 'decimalOdds' => (float) $oddsRow['decimal_odds'], 'observedAt' => $oddsRow['observed_at']] : null;

                        $health = $this->providers->provider($provider)?->health() ?? [];
                        $quality = $this->quality->assess($match, $this->qualityContext($match, $odds, (float) ($health['reliability'] ?? 0)));
                        $this->repo->saveQuality((int) $saved['id'], $quality);

                        $calibration = $this->calibrationFor($matchRow);
                        $candidate = $this->pipeline->evaluate($matchRow, $odds, $quality, $calibration, $config);

                        $factors = array_merge(['market' => $candidate['market'], 'selection' => $candidate['selection']], $candidate['factors']);
                        $predictionId = $this->decisions->recordPrediction(
                            (int) $saved['id'],
                            $candidate['prediction'] + ['market' => $candidate['market'], 'selection' => $candidate['selection']],
                            $candidate['value'],
                            $candidate['risk'],
                            $quality,
                            $factors,
                            is_numeric($candidate['confidence']['confidence'] ?? null) ? (float) $candidate['confidence']['confidence'] : null,
                            $candidate['odds'],
                            $candidate['oddsTimestamp'],
                            'LOW'
                        );
                        $candidate['predictionId'] = $predictionId;
                        $recorded++;
                        if ($modelVersionId === null) $modelVersionId = $this->modelVersionIdFor($candidate['prediction']);

                        if ($candidate['decision'] === 'REJECTED') {
                            $rejections++;
                            foreach ($candidate['rejectionReasons'] as $r) $rejectionSummary[$r] = ($rejectionSummary[$r] ?? 0) + 1;
                        } else {
                            $candidates[] = $candidate;
                        }
                    }

                    if (count($candidates) > 0) {
                        $optimized = $this->optimizer->optimize($candidates, [
                            'targetOddsMin' => (float) $config['target_odds_min'],
                            'targetOddsMax' => (float) $config['target_odds_max'],
                            'maxSelections' => (int) $config['max_selections'],
                            'minConfidence' => (float) $config['min_confidence'],
                            'minDataQuality' => (int) $config['min_data_quality'],
                            'maxCorrelation' => $config['max_correlation'],
                            'allowedMarkets' => $config['allowed_markets'],
                            'allowedLeagues' => $config['allowed_leagues'],
                        ]);
                        if ($optimized['status'] === 'QUALIFIED') {
                            $rec = $this->governance->record($optimized, (string) $config['version'], $modelVersionId, $config);
                            if (($rec['status'] ?? '') !== 'NO_QUALIFIED_TICKET') {
                                $status = $rec['status'] === 'APPROVED_NOT_EXECUTED' ? 'APPROVED' : 'PENDING_USER_APPROVAL';
                                $ticketId = $rec['ticketId'];
                                $message = $status === 'APPROVED' ? 'ticket generated and auto-approved (AUTOMATED_EXECUTION); no external execution' : 'ticket generated; awaiting user approval';
                            }
                        } else {
                            $message = $optimized['reason'] ?? 'no compliant combination';
                        }
                    } else {
                        $message = $evaluated === 0 ? 'no fixtures received for ' . $date : 'no candidate passed the risk/value/calibration gates';
                    }
                }
            }
        } catch (\Throwable $e) {
            $message = 'unexpected failure: ' . $e->getMessage();
            $errors[] = $message;
        }

        // The rejection summary doubles as the provider-failure ledger on a
        // DATA_UNAVAILABLE day: PROVIDER:<id> → status, so the stored row, the
        // dashboard and the API all show WHICH feed failed and WHY.
        $storedSummary = $rejectionSummary;
        if ($dataState === 'DATA_UNAVAILABLE') {
            foreach ($providerStatuses as $pid => $st) $storedSummary['PROVIDER:' . $pid] = $st;
        }
        $this->repo->saveDailyTicket([
            'date' => $date, 'ticket_id' => $ticketId, 'status' => $status,
            'configuration_version' => (int) $config['version'],
            'candidates_evaluated' => $evaluated, 'predictions_recorded' => $recorded,
            'rejections' => $rejections, 'rejection_summary' => json_encode($storedSummary),
            'message' => mb_substr($message, 0, 500), 'provider' => $provider, 'run_id' => $run['id'],
            'created_at' => gmdate('c'), 'updated_at' => gmdate('c'),
        ]);
        // A data outage is a FAILED run, and its execution key must not block
        // the retry: the next sweep (after the quota reset / config fix) gets
        // a fresh idempotency slot instead of DUPLICATE_SKIPPED all day.
        $runStatus = $dataState === 'DATA_UNAVAILABLE' ? 'FAILED' : 'COMPLETED';
        $this->repo->finishJobRun($run['id'], ['status' => $runStatus, 'processed' => $evaluated, 'created' => $recorded, 'updated' => 0, 'errors' => $errors]);
        if ($dataState === 'DATA_UNAVAILABLE' && method_exists($this->repo, 'releaseJobRun')) {
            try { $this->repo->releaseJobRun($run['id']); } catch (\Throwable $e) { /* best effort */ }
        }
        $this->audit->emit($dataState === 'DATA_UNAVAILABLE' ? 'SPORTS_DAILY_TICKET_BLOCKED' : 'SPORTS_DAILY_TICKET_RUN', 'Daily ticket run ' . $date . ' → ' . $status, [
            'date' => $date, 'status' => $status, 'dataState' => $dataState, 'ticketId' => $ticketId, 'evaluated' => $evaluated,
            'rejections' => $rejections, 'rejectionSummary' => $rejectionSummary, 'message' => $message, 'provider' => $provider,
            'providerFailures' => $providerFailures, 'providerStatuses' => $providerStatuses, 'errors' => $errors,
        ]);
        return [
            'status' => $status, 'dataState' => $dataState, 'ticketId' => $ticketId, 'date' => $date, 'message' => $message,
            'evaluated' => $evaluated, 'predictionsRecorded' => $recorded, 'rejections' => $rejections, 'rejectionSummary' => $rejectionSummary,
            'provider' => $provider, 'providerFailures' => $providerFailures, 'providerStatuses' => $providerStatuses,
            'runId' => $run['id'], 'errors' => $errors,
        ];
    }

    /**
     * Bulk-fetch the day's odds when the provider exposes the round endpoint
     * (SportMonks): one request per matchday instead of one per fixture.
     * Returns externalId → raw odds rows. Fixtures without a roundId, or a
     * failed round fetch, are simply absent — the per-match loop falls back
     * to the per-fixture odds() call for them (no fabricated odds, ever).
     */
    private function fetchRoundOdds(string $preferredId, ?SportsDataProvider $provider, array $rawFixtures, array &$errors): array
    {
        if ($provider === null || !method_exists($provider, 'round')) return [];
        $roundIds = [];
        foreach ($rawFixtures as $raw) {
            $roundId = (string) ($raw['roundId'] ?? '');
            if ($roundId !== '') $roundIds[$roundId] = true;
        }
        $out = [];
        foreach (array_keys($roundIds) as $roundId) {
            $attempt = $this->providers->withFallback('round', function (SportsDataProvider $p) use ($roundId) {
                if (!method_exists($p, 'round')) throw new ProviderException('round endpoint not supported', ProviderException::DATA_ERROR);
                return $p->round((string) $roundId);
            }, $preferredId);
            if (!$attempt['ok']) {
                $errors[] = 'round ' . $roundId . ' bulk odds fetch failed: ' . json_encode($attempt['failures']);
                continue;
            }
            foreach ((is_array($attempt['result'] ?? null) ? $attempt['result']['odds'] : []) ?? [] as $row) {
                if (is_array($row) && !empty($row['fixtureId'])) $out[(string) $row['fixtureId']][] = $row;
            }
        }
        return $out;
    }

    private function qualityContext(array $match, ?array $odds, float $reliability): array
    {
        $maxAge = 3600;
        $age = null;
        if ($odds !== null && !empty($odds['observedAt'])) {
            try { $age = max(0, time() - (int) (new \DateTimeImmutable((string) $odds['observedAt']))->getTimestamp()); }
            catch (\Throwable $e) { $age = PHP_INT_MAX; }
        } elseif (!empty($match['sourceTimestamp'])) {
            try { $age = max(0, time() - (int) (new \DateTimeImmutable((string) $match['sourceTimestamp']))->getTimestamp()); }
            catch (\Throwable $e) { $age = PHP_INT_MAX; }
        }
        return [
            'oddsAvailable' => $odds !== null,
            'recentFormAvailable' => !empty($match['context']['recentForm']),
            'providerReliability' => $reliability,
            'dataAgeSeconds' => $age ?? PHP_INT_MAX,
            'maxAgeSeconds' => $maxAge,
        ];
    }

    /** Approved calibration for the candidate's model version, or null (never invented). */
    private function calibrationFor(array $matchRow): ?array
    {
        $model = ['modelName' => PredictionEngine::MODEL_NAME, 'modelVersion' => PredictionEngine::MODEL_VERSION, 'featureVersion' => FeatureEngineeringEngine::VERSION];
        $modelId = $this->repo->ensureModelVersion($model);
        $cal = $this->repo->activeCalibration($modelId);
        if ($cal === null) return null;
        $cal['calibrationVersion'] = $this->calibrationVersionLabel($cal);
        return $cal;
    }

    private function calibrationVersionLabel(array $cal): string
    {
        return sprintf('cal-platt-i%s-s%s-n%d', $cal['intercept'] ?? '?', $cal['slope'] ?? '?', (int) ($cal['samples'] ?? 0));
    }

    private function modelVersionIdFor(array $prediction): ?int
    {
        $model = ['modelName' => $prediction['modelName'] ?? PredictionEngine::MODEL_NAME, 'modelVersion' => $prediction['modelVersion'] ?? PredictionEngine::MODEL_VERSION, 'featureVersion' => $prediction['featureVersion'] ?? FeatureEngineeringEngine::VERSION, 'calibrationVersion' => $prediction['calibrationVersion'] ?? null];
        return $this->repo->ensureModelVersion($model);
    }
}
