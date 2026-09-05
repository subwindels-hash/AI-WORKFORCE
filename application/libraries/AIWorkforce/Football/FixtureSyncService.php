<?php
namespace AIWorkforce\Football;

use AIWorkforce\Persistence\AuditRepository;
use AIWorkforce\Persistence\FootballRepository;
use AIWorkforce\Sports\Providers\ProviderException;
use AIWorkforce\Sports\Providers\SportsDataProvider;

/**
 * Fixture synchronization for one date (or a live sweep).
 *
 * What it does: ask every configured provider for its fixtures, keep the rows
 * it actually returns, and record for each row which fields arrived
 * (`coverage`) and which did not (`data_state`).
 *
 * What it never does: create a fixture the provider did not send, fill a
 * missing score with 0, or report a successful sync when the provider was
 * unreachable. A failed sweep returns FAILED carrying the provider's own error
 * text, and the previously stored fixture keeps its last known state.
 */
final class FixtureSyncService
{
    /** Fields the read model treats as required for a complete fixture row. */
    private const CORE_FIELDS = ['externalId', 'homeTeam', 'awayTeam', 'competition', 'kickoff', 'status'];
    private const STATUSES = ['SCHEDULED', 'LIVE', 'FINISHED', 'POSTPONED', 'CANCELLED', 'SUSPENDED'];

    public function __construct(
        private FootballRepository $repo,
        private ProviderGateway $gateway,
        private FootballConfiguration $config,
        private ?AuditRepository $audit = null,
    ) {}

    /** Pull one calendar day (UTC) and upsert it. */
    public function syncDay(string $date, string $executionKey, ?string $providerId = null, ?int $budget = null): array
    {
        return $this->sweep('FIXTURES', 'fixtures', $date, $date, $executionKey, function (SportsDataProvider $provider) use ($date) {
            return method_exists($provider, 'fixtures')
                ? $provider->fixtures(['from' => $date, 'to' => $date, 'date' => $date])
                : [];
        }, $providerId, $budget ?? $this->config->requestBudget('fixtures'));
    }

    /** Refresh currently live matches (score + minute + cards). */
    public function syncLive(string $executionKey, ?string $providerId = null, ?int $budget = null): array
    {
        $date = gmdate('Y-m-d');
        return $this->sweep('LIVE', 'live', $date, $date, $executionKey, function (SportsDataProvider $provider) {
            if (!method_exists($provider, 'liveFixtures')) {
                throw new ProviderException('live fixtures are not supported by this provider', ProviderException::DATA_ERROR);
            }
            return $provider->liveFixtures();
        }, $providerId, $budget ?? $this->config->requestBudget('live'));
    }

    /**
     * Results sweep for matches whose final score has not been stored yet — the
     * only input the settlement engine trusts.
     */
    public function syncResults(string $executionKey, ?string $providerId = null, int $limit = 120, ?int $budget = null): array
    {
        $date = gmdate('Y-m-d');
        $pending = $this->repo->listFixturesAwaitingResult($limit);
        $ids = [];
        $awaiting = [];
        foreach ($pending as $fixture) {
            $external = (string) ($fixture['external_id'] ?? '');
            if ($external === '' || isset($awaiting[$external])) continue;
            $awaiting[$external] = $fixture;
            $ids[] = $external;
        }
        if ($ids === []) {
            $run = $this->repo->startSyncRun(['executionKey' => $executionKey, 'jobType' => 'RESULTS', 'windowStart' => $date, 'windowEnd' => $date]);
            if ($run === null) return ['status' => 'DUPLICATE_SKIPPED', 'processed' => 0, 'created' => 0, 'updated' => 0, 'errors' => [], 'deferred' => false, 'provider' => null, 'requests' => 0];
            $this->repo->finishSyncRun($executionKey, ['status' => 'COMPLETED', 'processed' => 0, 'created' => 0, 'updated' => 0, 'requests' => 0, 'errors' => [], 'nextRunAt' => gmdate('c', time() + $this->config->refreshInterval('results'))]);
            return ['status' => 'COMPLETED', 'processed' => 0, 'created' => 0, 'updated' => 0, 'errors' => [], 'deferred' => false, 'provider' => null, 'requests' => 0, 'note' => 'no fixture is waiting for a final result'];
        }
        // Each pending fixture is re-read within the sweep budget; a provider
        // without the single-fixture endpoint falls back to its results() call.
        return $this->sweep('RESULTS', 'fixture', $date, $date, $executionKey, function (SportsDataProvider $provider) use ($ids, $awaiting) {
            $rows = [];
            foreach ($ids as $externalId) {
                $stored = $awaiting[$externalId];
                $context = ['externalId' => $externalId,
                    'kickoff' => (string) ($stored['kickoff_at'] ?? gmdate('c')),
                    'homeTeam' => (string) ($stored['home_team'] ?? ''),
                    'awayTeam' => (string) ($stored['away_team'] ?? ''),
                    'competition' => (string) ($stored['competition'] ?? '')];
                if (method_exists($provider, 'fixture')) {
                    $row = $provider->fixture($externalId);
                    if ($row !== []) $rows[] = $row + $context;
                    continue;
                }
                if (!method_exists($provider, 'results')) continue;
                foreach ($provider->results($externalId) as $result) {
                    if (!is_array($result)) continue;
                    $rows[] = ['externalId' => (string) ($result['externalId'] ?? $externalId),
                        'status' => $result['status'] ?? 'FINISHED',
                        'homeScore' => $result['homeScore'] ?? null, 'awayScore' => $result['awayScore'] ?? null,
                        'halfTimeHome' => $result['halfTimeHome'] ?? null, 'halfTimeAway' => $result['halfTimeAway'] ?? null,
                        'sourceTimestamp' => (string) ($result['sourceTimestamp'] ?? gmdate('c'))] + $context;
                }
            }
            return $rows;
        }, $providerId, $budget ?? $this->config->requestBudget('results'), false);
    }

    /**
     * Shared sweep: idempotent per execution key, budget-bounded, provider
     * errors surfaced verbatim, each stored fixture annotated with its own
     * data coverage.
     *
     * @param callable(SportsDataProvider):array $fetch
     */
    public function sweep(string $jobType, string $capability, string $from, string $to, string $executionKey, callable $fetch, ?string $preferredProvider = null, int $budget = 0, bool $requireOnline = true): array
    {
        $run = $this->repo->startSyncRun([
            'executionKey' => $executionKey, 'jobType' => $jobType,
            'windowStart' => $from, 'windowEnd' => $to, 'startedAt' => gmdate('c'),
        ]);
        if ($run === null) {
            return ['status' => 'DUPLICATE_SKIPPED', 'job' => $jobType, 'processed' => 0, 'created' => 0, 'updated' => 0, 'errors' => [], 'deferred' => false, 'provider' => null, 'requests' => 0];
        }
        if (!$this->gateway->configured()) {
            $this->repo->finishSyncRun($executionKey, ['status' => 'SKIPPED', 'processed' => 0, 'created' => 0, 'updated' => 0, 'errors' => ['FOOTBALL_PROVIDER_NOT_CONFIGURED'], 'nextRunAt' => $this->nextRunAt('FAILED', 'fixtures')]);
            return ['status' => 'SKIPPED', 'job' => $jobType, 'reason' => 'FOOTBALL_PROVIDER_NOT_CONFIGURED', 'processed' => 0, 'created' => 0, 'updated' => 0, 'errors' => [], 'deferred' => false, 'provider' => null, 'requests' => 0];
        }
        $this->gateway->beginSweep($budget);
        $processed = 0; $created = 0; $updated = 0; $errors = [];
        $attempt = $this->gateway->call($capability, $fetch, $preferredProvider, $requireOnline);
        if (!$attempt['ok']) {
            $errors = array_values($attempt['failures']);
            $status = !empty($attempt['deferred']) ? 'DEFERRED' : 'FAILED';
            $this->repo->finishSyncRun($executionKey, [
                'status' => $status, 'processed' => 0, 'created' => 0, 'updated' => 0,
                'requests' => $this->gateway->requestsMade(), 'errors' => $errors,
                'nextRunAt' => $this->nextRunAt($status, $capability),
            ]);
            $this->audit?->emit('FOOTBALL_SYNC_' . $status, 'Football ' . strtolower($jobType) . ' sync ' . strtolower($status), ['window' => $from . ' → ' . $to, 'errors' => $errors], 'system');
            return ['status' => $status, 'job' => $jobType, 'processed' => 0, 'created' => 0, 'updated' => 0, 'errors' => $errors, 'deferred' => (bool) $attempt['deferred'], 'provider' => null, 'requests' => $this->gateway->requestsMade()];
        }

        $providerCode = (string) $attempt['provider'];
        $providerRow = $this->repo->ensureProvider($providerCode, ['displayName' => $providerCode, 'status' => 'ONLINE', 'enabled' => true]);
        // The row exists now, so the requests this sweep spent can be recorded
        // against the provider's daily budget instead of vanishing with the run.
        $this->gateway->noteProviderReady($providerCode);
        foreach ((array) $attempt['result'] as $raw) {
            if (!is_array($raw)) continue;
            try {
                $row = $this->normalize($raw, $providerCode);
                $stored = $this->repo->saveFixture((int) $providerRow['id'], $row);
                $processed++;
                (($stored['created_at'] ?? '') === ($stored['updated_at'] ?? '')) ? $created++ : $updated++;
                $this->upsertReferences((int) $providerRow['id'], $raw, $stored);
            } catch (\Throwable $e) {
                // A malformed row is counted and named; it never aborts the day
                // and is never replaced by a synthetic fixture.
                $errors[] = mb_substr($e->getMessage(), 0, 200);
            }
        }
        if ($processed === 0 && $errors === []) $errors[] = 'provider returned no fixtures for ' . $from;
        $status = $processed > 0 ? 'COMPLETED' : ($errors === [] ? 'COMPLETED' : 'FAILED');
        $this->repo->finishSyncRun($executionKey, [
            'status' => $status, 'processed' => $processed, 'created' => $created, 'updated' => $updated,
            'requests' => $this->gateway->requestsMade(), 'errors' => $errors,
            'rateLimitRemaining' => null, 'nextRunAt' => $this->nextRunAt($status, $capability),
        ]);
        $this->audit?->emit('FOOTBALL_SYNC_' . $status, 'Football ' . strtolower($jobType) . ' sync ' . strtolower($status) . ' for ' . $from, [
            'provider' => $providerCode, 'processed' => $processed, 'created' => $created,
            'updated' => $updated, 'errors' => array_slice($errors, 0, 5), 'requests' => $this->gateway->requestsMade(),
        ], 'system');
        return ['status' => $status, 'job' => $jobType, 'date' => $from, 'provider' => $providerCode,
            'processed' => $processed, 'created' => $created, 'updated' => $updated, 'errors' => $errors,
            'deferred' => false, 'requests' => $this->gateway->requestsMade()];
    }

    /**
     * Provider fixture payload → stored fixture row.
     *
     * Scores, minute and cards are copied only when the provider sent them, and
     * the coverage map records exactly which core fields arrived, so the UI can
     * say LIMITED_DATA instead of pretending a partial row is complete. A
     * provider that never stated a status yields UNKNOWN — it is *not*
     * silently promoted to SCHEDULED.
     */
    public function normalize(array $raw, string $providerCode): array
    {
        $externalId = trim((string) ($raw['externalId'] ?? ''));
        if ($externalId === '') throw new \InvalidArgumentException('fixture missing externalId');
        $coverage = [];
        foreach (self::CORE_FIELDS as $field) {
            $value = $raw[$field] ?? null;
            $coverage[$field] = ($value === null || $value === '') ? DataState::UNAVAILABLE : DataState::AVAILABLE;
        }
        $status = strtoupper(trim((string) ($raw['status'] ?? '')));
        if ($status === '') {
            $status = 'UNKNOWN';
        } elseif (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('fixture status is not understood: ' . $status);
        }
        $kickoff = $this->isoTime((string) ($raw['kickoff'] ?? ''));
        if ($kickoff === null) throw new \InvalidArgumentException('fixture kickoff is invalid');
        if ($status === 'UNKNOWN' || $status === 'SCHEDULED') {
            // Teams are required: a fixture row without both sides is not a fixture.
            foreach (['homeTeam', 'awayTeam'] as $field) {
                if (trim((string) ($raw[$field] ?? '')) === '') throw new \InvalidArgumentException("fixture missing {$field}");
            }
        }
        $detailFields = ['minute', 'homeScore', 'awayScore', 'venue', 'country', 'season', 'homeTeamId', 'awayTeamId', 'homeRedCards', 'awayRedCards'];
        $present = 0;
        foreach ($detailFields as $field) {
            $value = $raw[$field] ?? null;
            if ($value !== null && $value !== '') $present++;
        }
        $coverage['detail'] = $present >= 7 ? DataState::AVAILABLE : ($present > 0 ? DataState::LIMITED : DataState::UNAVAILABLE);
        $missing = array_keys(array_filter($coverage, static fn($state) => $state === DataState::UNAVAILABLE));
        $dataState = match (true) {
            $missing === [] => DataState::AVAILABLE,
            count($missing) === 1 => DataState::LIMITED,
            default => DataState::UNAVAILABLE,
        };
        return [
            'externalId' => $externalId,
            'competition' => (string) ($raw['competition'] ?? ''),
            'country' => $raw['country'] ?? null,
            'season' => $raw['season'] ?? null,
            'round' => $raw['round'] ?? $raw['roundId'] ?? null,
            'kickoff' => $kickoff,
            'status' => $status,
            'matchState' => match ($status) {
                'LIVE' => 'IN_PLAY',
                'FINISHED' => 'COMPLETED',
                'POSTPONED', 'CANCELLED' => 'ABANDONED',
                'SUSPENDED' => 'SUSPENDED',
                default => 'PRE_MATCH',
            },
            'minute' => $raw['minute'] ?? null,
            'extraMinute' => $raw['extraMinute'] ?? null,
            'homeTeam' => (string) ($raw['homeTeam'] ?? 'DATA_UNAVAILABLE'),
            'awayTeam' => (string) ($raw['awayTeam'] ?? 'DATA_UNAVAILABLE'),
            'homeTeamId' => $raw['homeTeamId'] ?? null,
            'awayTeamId' => $raw['awayTeamId'] ?? null,
            'homeScore' => $raw['homeScore'] ?? null,
            'awayScore' => $raw['awayScore'] ?? null,
            'halfTimeHome' => $raw['halfTimeHome'] ?? null,
            'halfTimeAway' => $raw['halfTimeAway'] ?? null,
            'homeRedCards' => $raw['homeRedCards'] ?? null,
            'awayRedCards' => $raw['awayRedCards'] ?? null,
            'venue' => $raw['venue'] ?? null,
            'dataState' => $dataState,
            'coverage' => $coverage,
            'sourceTimestamp' => (string) ($raw['sourceTimestamp'] ?? gmdate('c')),
            'payload' => $raw + ['provider' => $providerCode, 'simulated' => !empty($raw['simulated'])],
        ];
    }

    /**
     * Competition and team identity rows come from the same provider payload as
     * the fixture, so building the reference index costs no extra quota and adds
     * no invented names.
     */
    private function upsertReferences(int $providerId, array $raw, array $stored): void
    {
        try {
            $competitionId = null;
            if (isset($raw['leagueId']) && (string) $raw['leagueId'] !== '') {
                $competition = $this->repo->saveCompetition($providerId, [
                    'externalId' => (string) $raw['leagueId'],
                    'name' => (string) ($raw['competition'] ?? 'DATA_UNAVAILABLE'),
                    'country' => $raw['country'] ?? null,
                    'season' => $raw['season'] ?? null,
                    'dataState' => !empty($raw['country']) ? DataState::AVAILABLE : DataState::LIMITED,
                    'payload' => ['leagueId' => (string) $raw['leagueId'], 'competition' => $raw['competition'] ?? null, 'country' => $raw['country'] ?? null],
                    'fetchedAt' => gmdate('c'),
                ]);
                $competitionId = (int) ($competition['id'] ?? 0) ?: null;
            }
            if ($competitionId !== null && (int) ($stored['competition_id'] ?? 0) !== $competitionId && (int) ($stored['id'] ?? 0) > 0) {
                $this->repo->linkFixtureCompetition((int) $stored['id'], $competitionId);
            }
            foreach (['homeTeamId' => ['homeTeam', 'homeTeamLogo'], 'awayTeamId' => ['awayTeam', 'awayTeamLogo']] as $idKey => [$nameKey, $logoKey]) {
                $teamId = (string) ($raw[$idKey] ?? '');
                if ($teamId === '') continue;
                $this->repo->saveTeam($providerId, [
                    'externalId' => $teamId,
                    'name' => (string) ($raw[$nameKey] ?? 'DATA_UNAVAILABLE'),
                    'logo' => $raw[$logoKey] ?? null,
                    'venue' => $raw['venue'] ?? null,
                    'country' => $raw['country'] ?? null,
                    'dataState' => DataState::AVAILABLE,
                    'payload' => ['id' => $teamId, 'name' => $raw[$nameKey] ?? null],
                ]);
            }
        } catch (\Throwable $e) {
            // Reference rows are an index, not a source of truth — failing to
            // build one must never discard the fixture that did arrive.
        }
    }

    /** Cadence-aware next attempt; failures wait longer (never a tight loop). */
    private function nextRunAt(string $status, string $capability): string
    {
        $bucket = match ($capability) {
            'live' => 'live',
            'results' => 'results',
            default => 'fixtures',
        };
        $base = $this->config->refreshInterval($bucket);
        $seconds = $status === 'COMPLETED' ? $base : min(21600, $base * 2);
        return gmdate('c', time() + $seconds);
    }

    private function isoTime(string $value): ?string
    {
        if ($value === '') return null;
        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('c');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
