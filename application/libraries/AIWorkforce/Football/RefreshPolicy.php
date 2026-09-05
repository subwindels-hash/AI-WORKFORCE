<?php
namespace AIWorkforce\Football;

use AIWorkforce\Persistence\FootballRepository;

/**
 * Provider-aware refresh scheduling.
 *
 * A job runs when ALL of these hold: its configured interval has elapsed, the
 * provider is not in backoff, the previous run did not ask to be deferred, and
 * there is actually work for it (a live match, a fixture waiting for a result,
 * an open prediction). That last clause is what keeps the module off the
 * provider's quota overnight instead of polling on a fixed five-minute timer.
 *
 * Intervals are per bucket (fixtures 6 h, upcoming 1 h, live 90 s, results
 * 15 m, statistics 12 h, predict 30 m, settle 15 m, performance 1 h, cleanup
 * 1 d) and every one of them is an environment override, so an operator can
 * match a plan's request budget without editing code.
 */
final class RefreshPolicy
{
    /** job id ⇒ [bucket, capability, precondition] */
    private const JOBS = [
        'football-fixtures' => ['fixtures', 'today'],
        'football-upcoming' => ['upcoming', 'window'],
        'football-live' => ['live', 'live'],
        'football-results' => ['results', 'pending-results'],
        'football-statistics' => ['statistics', 'statistics'],
        'football-predict' => ['predict', 'predictable'],
        'football-settle' => ['settle', 'settleable'],
        'football-performance' => ['performance', 'measurable'],
        'football-cleanup' => ['cleanup', 'always'],
    ];

    public function __construct(private FootballRepository $repo, private FootballConfiguration $config, private ProviderGateway $gateway) {}

    /** @return list<string> */
    public static function jobIds(): array
    {
        return array_keys(self::JOBS);
    }

    public function interval(string $job): int
    {
        $bucket = self::JOBS[$job][0] ?? null;
        return $bucket === null ? 3600 : $this->config->refreshInterval($bucket);
    }

    /**
     * Decide whether a job should run now.
     *
     * @return array{job:string, bucket:string, due:bool, reason:string, interval:int,
     *               nextRunAt:?string, lastRunAt:?string, requests:int|null, detail:array<string,mixed>}
     */
    public function evaluate(string $job, ?array $lastRun = null): array
    {
        $meta = self::JOBS[$job] ?? null;
        if ($meta === null) {
            return ['job' => $job, 'bucket' => 'unknown', 'due' => false, 'reason' => 'UNKNOWN_JOB', 'interval' => 0, 'nextRunAt' => null, 'lastRunAt' => null, 'requests' => null, 'detail' => []];
        }
        [$bucket, $precondition] = $meta;
        $interval = $this->config->refreshInterval($bucket);
        $lastRun ??= $this->repo->lastSyncRun(RefreshPolicy::logKey($job));
        $now = time();
        $base = [
            'job' => $job, 'bucket' => $bucket, 'interval' => $interval,
            'nextRunAt' => $lastRun['next_run_at'] ?? null, 'lastRunAt' => $lastRun['started_at'] ?? null,
            'requests' => isset($lastRun['requests_made']) ? (int) $lastRun['requests_made'] : null,
            'detail' => [],
        ];
        // array_merge, not `+`: the reason and due flag below must override the
        // defaults, and `+` silently keeps left-hand keys.
        $verdict = static fn(array $overrides): array => array_merge($base, ['due' => false, 'reason' => 'DUE', 'detail' => []], $overrides);
        if (!$this->gateway->configured()) {
            return $verdict(['due' => false, 'reason' => 'PROVIDER_NOT_CONFIGURED', 'detail' => ['message' => 'No football data provider is registered; no request will be made.']]);
        }
        if ($precondition !== 'always' && !$this->config->enabled()) {
            return $verdict(['due' => false, 'reason' => 'MODULE_DISABLED']);
        }
        // The gateway reports the stored backoff as `backoffUntil`; the provider
        // row's own column name is `backoff_until`, so accept either spelling.
        $backoffUntil = null;
        foreach ((array) ($this->gateway->status()['providers'] ?? []) as $provider) {
            $until = $provider['backoffUntil'] ?? ($provider['backoff_until'] ?? null);
            if (is_string($until) && $until !== '' && strtotime($until) > $now) $backoffUntil = $until;
        }
        if ($backoffUntil !== null) {
            return $verdict(['due' => false, 'reason' => 'PROVIDER_BACKOFF', 'detail' => ['until' => $backoffUntil]]);
        }
        $deferral = $lastRun['next_run_at'] ?? null;
        if (is_string($deferral) && $deferral !== '' && strtotime($deferral) > $now) {
            return $verdict(['due' => false, 'reason' => $lastRun['status'] === 'DEFERRED' ? 'PROVIDER_DEFERRED' : 'CADENCE', 'detail' => ['nextRunAt' => $deferral, 'status' => $lastRun['status'] ?? null]]);
        }
        $lastStarted = $lastRun['started_at'] ?? null;
        if (is_string($lastStarted) && $lastStarted !== '' && ($now - (int) strtotime($lastStarted)) < $interval) {
            return $verdict(['due' => false, 'reason' => 'CADENCE', 'detail' => ['elapsed' => $now - (int) strtotime($lastStarted)]]);
        }
        $work = $this->work($precondition);
        if (!$work['present']) {
            return $verdict(['due' => false, 'reason' => 'NO_WORK', 'detail' => $work]);
        }
        $budget = $this->config->requestBudget($bucket);
        if ($budget === 0 && in_array($precondition, ['live', 'today', 'window', 'pending-results', 'statistics'], true)) {
            return $verdict(['due' => false, 'reason' => 'REQUEST_BUDGET_EXHAUSTED', 'detail' => $work]);
        }
        return $verdict(['due' => true, 'reason' => 'DUE', 'detail' => array_merge($work, ['budget' => $budget])]);
    }

    public function due(string $job): bool
    {
        return $this->evaluate($job)['due'];
    }

    /**
     * Everything at once, for the admin diagnostics table and for the loop that
     * decides how long to sleep: `nextWakeAt` is the earliest moment any job
     * becomes eligible, so the background runner idles instead of ticking.
     *
     * @return array{jobs:list<array>, due:list<string>, nextWakeAt:?string, nextWakeInSeconds:?int}
     */
    public function schedule(): array
    {
        $jobs = [];
        $due = [];
        $soonest = null;
        foreach (array_keys(self::JOBS) as $job) {
            $evaluation = $this->evaluate($job);
            $jobs[] = $evaluation;
            if ($evaluation['due']) $due[] = $job;
            $candidate = $evaluation['nextRunAt'] !== null ? (int) strtotime((string) $evaluation['nextRunAt']) : null;
            if ($candidate === null && !$evaluation['due']) $candidate = time() + (int) $evaluation['interval'];
            if ($candidate !== null && ($candidate > time() || $evaluation['due'])) {
                $soonest = min($soonest ?? PHP_INT_MAX, $candidate);
            }
        }
        return [
            'jobs' => $jobs,
            'due' => $due,
            'nextWakeAt' => $soonest === null ? null : gmdate('c', $soonest),
            'nextWakeInSeconds' => $soonest === null ? null : max(0, $soonest - time()),
        ];
    }

    /**
     * Cheap, database-only existence checks. No provider request is made to
     * decide whether to make a provider request.
     *
     * @return array{present:bool, count:int, note:string}
     */
    private function work(string $precondition): array
    {
        $now = time();
        switch ($precondition) {
            case 'today':
                $today = $this->repo->listFixtures(['date' => gmdate('Y-m-d')], 400);
                $fresh = $today === [] ? false : !$this->recentlySynced('FIXTURES');
                return ['present' => $fresh || $today === [], 'count' => count($today), 'note' => $today === [] ? 'no fixture stored for today' : 'stored fixture data older than the fixtures interval'];
            case 'window':
                $upcoming = $this->repo->listFixtures(['from' => gmdate('c', $now - 3600), 'to' => gmdate('c', $now + 3 * 86400)], 400);
                return ['present' => $upcoming !== [] || !$this->recentlySynced('FIXTURES'), 'count' => count($upcoming), 'note' => 'fixtures within 3 days of kickoff'];
            case 'live':
                $live = $this->repo->listFixtures(['status' => 'LIVE'], 200);
                $startingSoon = $this->repo->listFixtures(['status' => 'SCHEDULED', 'from' => gmdate('c', $now), 'to' => gmdate('c', $now + 3600)], 200);
                return ['present' => $live !== [] || $startingSoon !== [], 'count' => count($live), 'note' => 'starting within the hour: ' . count($startingSoon), 'imminent' => count($startingSoon)];
            case 'pending-results':
                $pending = $this->repo->listFixturesAwaitingResult(200);
                return ['present' => $pending !== [], 'count' => count($pending), 'note' => 'fixtures without a final stored score'];
            case 'statistics':
                $due = $this->repo->listFixtures(['from' => gmdate('c', $now), 'to' => gmdate('c', $now + 3 * 86400)], 400);
                $uncollected = 0;
                foreach ($due as $fixture) {
                    if ((string) ($fixture['data_state'] ?? '') !== DataState::AVAILABLE) $uncollected++;
                }
                return ['present' => $due !== [], 'count' => count($due), 'note' => $uncollected . ' without complete statistics coverage'];
            case 'predictable':
                $fixtures = $this->repo->listFixtures(['status' => 'SCHEDULED', 'from' => gmdate('c', $now), 'to' => gmdate('c', $now + 72 * 3600)], max(1, $this->config->analysisLimit()));
                $predictions = $this->repo->listPredictions(['from' => gmdate('c', $now - 12 * 3600), 'kind' => PredictionService::KIND_PRE_MATCH], 2000);
                $seen = array_fill_keys(array_map(static fn(array $row) => (int) $row['fixture_id'], $predictions), true);
                $todo = 0;
                foreach ($fixtures as $fixture) {
                    if (!isset($seen[(int) $fixture['id']])) $todo++;
                }
                return ['present' => $fixtures !== [], 'count' => $todo, 'stored' => count($predictions), 'note' => $todo . ' fixture(s) without a prediction inside the 72-hour window'];
            case 'settleable':
                $open = $this->repo->listPredictions(['settlementState' => 'OPEN'], 500);
                $ready = $this->repo->listFixtures(['unsettledFinished' => true], 200);
                $gradeable = 0;
                foreach ($ready as $fixture) {
                    if ($fixture['home_score'] !== null && $fixture['away_score'] !== null) $gradeable++;
                }
                return ['present' => $gradeable > 0, 'count' => $gradeable, 'openPredictions' => count($open), 'note' => 'finished fixtures with a stored score and an open prediction'];
            case 'measurable':
                $aggregate = $this->repo->settlementAggregates([]);
                return ['present' => (int) ($aggregate['evaluated'] ?? 0) > 0, 'count' => (int) ($aggregate['evaluated'] ?? 0), 'note' => 'settled predictions available to measure'];
            default:
                return ['present' => true, 'count' => 0, 'note' => 'housekeeping job'];
        }
    }

    /** True when the named job ran inside its own interval. */
    private function recentlySynced(string $logKey): bool
    {
        $run = $this->repo->lastSyncRun($logKey);
        $started = $run['started_at'] ?? null;
        if (!is_string($started) || $started === '') return false;
        return (time() - (int) strtotime($started)) < $this->config->refreshInterval('fixtures');
    }

    private static function logKey(string $job): string
    {
        return match ($job) {
            'football-fixtures' => 'FIXTURES',
            'football-upcoming' => 'FIXTURES',
            'football-live' => 'LIVE',
            'football-results' => 'RESULTS',
            'football-statistics' => 'STATISTICS',
            'football-predict' => 'PREDICT',
            'football-settle' => 'SETTLE',
            'football-performance' => 'PERFORMANCE',
            'football-cleanup' => 'CLEANUP',
            default => strtoupper($job),
        };
    }

    /**
     * How a single fixture should be tracked right now — used by the cron service
     * to decide which per-fixture refreshes to perform, instead of one global
     * timer for everything.
     *
     * @return array{phase:string, interval:int, reason:string}
     */
    public function forFixture(array $fixture): array
    {
        $status = strtoupper((string) ($fixture['status'] ?? ''));
        $kickoff = (string) ($fixture['kickoff_at'] ?? '');
        $untilKickoff = $kickoff === '' ? null : ((int) strtotime($kickoff) - time());
        if ($status === 'LIVE') {
            return ['phase' => 'LIVE', 'interval' => $this->config->refreshInterval('live'), 'reason' => 'in play — refresh at the live cadence, bounded by the provider rate limit'];
        }
        if ($status === 'FINISHED') {
            $settled = ($fixture['settled_at'] ?? null) !== null;
            return ['phase' => $settled ? 'SETTLED' : 'PENDING_SETTLEMENT', 'interval' => $this->config->refreshInterval($settled ? 'cleanup' : 'results'),
                'reason' => $settled ? 'settled once; no further refresh' : 'final score recorded but predictions are not settled yet'];
        }
        if (in_array($status, ['POSTPONED', 'CANCELLED'], true)) {
            return ['phase' => 'INACTIVE', 'interval' => $this->config->refreshInterval('fixtures'), 'reason' => 'fixture ' . strtolower($status) . ' — checked at the scheduling cadence only'];
        }
        if ($untilKickoff !== null && $untilKickoff > 0 && $untilKickoff <= 3600) {
            return ['phase' => 'PRE_KICKOFF', 'interval' => $this->config->refreshInterval('live'), 'reason' => 'kickoff within the hour — refreshed at the live cadence'];
        }
        if ($untilKickoff !== null && $untilKickoff <= 0) {
            return ['phase' => 'KICKOFF_PASSED', 'interval' => $this->config->refreshInterval('results'), 'reason' => 'kickoff passed without a live status: result lookup cadence applies, and the pre-match prediction is frozen'];
        }
        return ['phase' => 'SCHEDULED', 'interval' => $this->config->refreshInterval('upcoming'), 'reason' => 'outside the kickoff window — refreshed on the upcoming cadence'];
    }
}
