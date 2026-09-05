<?php
namespace AIWorkforce\Football;

use AIWorkforce\Persistence\AuditRepository;
use AIWorkforce\Persistence\FootballRepository;

/**
 * Scheduled football jobs.
 *
 * Idempotent by construction: every job runs under an execution key that the
 * sync-log table accepts only once, so an overlapping or repeated tick cannot
 * duplicate fixtures, settlements or snapshots. Cadence comes from
 * RefreshPolicy — which asks the provider's own health, the configured
 * intervals and whether any work exists — so this class contains no fixed sleep
 * or per-minute timer of its own.
 *
 *   php index.php tools football-cron [job] [--force]
 *
 * Jobs that touch the provider (fixtures, upcoming, live, results, statistics)
 * are budget-bounded; the ones that don't (predict, settle, performance,
 * cleanup) read only the stored rows, so the analysis pipeline never spends
 * quota by accident.
 */
final class FootballCronService
{
    public const JOBS = ['fixtures', 'upcoming', 'live', 'results', 'statistics', 'predict', 'settle', 'performance', 'cleanup'];
    private const PROVIDER_JOBS = ['fixtures', 'upcoming', 'live', 'results', 'statistics'];

    public function __construct(
        private FootballIntelligence $football,
        private FootballRepository $repo,
        private ?AuditRepository $audit = null,
    ) {}

    /**
     * Run every job that is due. `$force` bypasses the cadence gate (used by the
     * operator-triggered console action), never the idempotency keys.
     *
     * @return array<string,array<string,mixed>> + ['schedule' => …]
     */
    public function runAll(bool $force = false, ?string $date = null): array
    {
        $date ??= gmdate('Y-m-d');
        $summary = [];
        foreach (self::JOBS as $job) {
            try {
                $summary[$job] = $this->run($job, $date, $force);
            } catch (\Throwable $e) {
                $summary[$job] = ['status' => 'FAILED', 'error' => mb_substr($e->getMessage(), 0, 300)];
                $this->audit?->emit('FOOTBALL_JOB_FAILED', "Football job {$job} failed: " . $e->getMessage(), ['job' => $job], 'system');
            }
        }
        $summary['schedule'] = $this->football->refresh()->schedule();
        // Only an eventful sweep is audited. The scheduler ticks the module every
        // minute so a live match can be refreshed on its own cadence, and an
        // audit row per idle minute would drown the log — a job that ran records
        // its own sync-log row (and a failure emits FOOTBALL_JOB_FAILED above), so
        // nothing is lost by staying quiet when every job reported SKIPPED.
        $quiet = ['SKIPPED', 'NOTHING_TO_SETTLE', 'DUPLICATE_SKIPPED', 'NO_FIXTURES', 'NO_DATA'];
        $worked = array_filter(
            array_diff_key($summary, ['schedule' => 1]),
            static fn($state) => !is_array($state) || !in_array((string) ($state['status'] ?? 'SKIPPED'), $quiet, true)
        );
        if ($worked !== []) {
            $this->audit?->emit('FOOTBALL_CRON_RUN', 'Football scheduled jobs: ' . json_encode(array_map(
                static fn($s) => is_array($s) ? (string) ($s['status'] ?? 'SKIPPED') : (string) $s, $worked
            )), $worked, 'system');
        }
        return $summary;
    }

    public function run(string $job, ?string $date = null, bool $force = false): array
    {
        $date ??= gmdate('Y-m-d');
        if (!in_array($job, self::JOBS, true)) throw new \InvalidArgumentException('unknown football job: ' . $job);
        if (!$force) {
            $evaluation = $this->football->refresh()->evaluate('football-' . $job);
            if (!$evaluation['due']) {
                return ['status' => 'SKIPPED', 'reason' => (string) $evaluation['reason'], 'job' => $job,
                    'nextRunAt' => $evaluation['nextRunAt'], 'interval' => $evaluation['interval'], 'detail' => $evaluation['detail']];
            }
        }
        // An automatic run reuses the same execution key inside its hour, so a
        // duplicated tick is a no-op; an operator-forced run gets a unique key and
        // really re-reads the provider (the data writes stay idempotent either
        // way — fixtures upsert by provider+external id, settlements insert once).
        $suffix = $force ? ':' . gmdate('Ymd\THis') : ':' . gmdate('Ymd\TH');
        $result = match ($job) {
            'fixtures' => $this->track('FIXTURES', fn() => $this->football->fixtures()->syncDay($date, 'fixtures:' . $date . $suffix), $suffix),
            'upcoming' => $this->track('UPCOMING', fn() => $this->jobUpcoming($date, $suffix), $suffix),
            'live' => $this->track('LIVE', fn() => $this->jobLive($suffix), $suffix),
            'results' => $this->track('RESULTS', fn() => $this->football->fixtures()->syncResults('results' . $suffix), $suffix),
            'statistics' => $this->track('STATISTICS', fn() => $this->football->collectStatisticsForDay($date, 24), $suffix),
            'predict' => $this->track('PREDICT', fn() => $this->jobPredict($date), $suffix),
            'settle' => $this->track('SETTLE', fn() => $this->football->settlements()->settleDue(200, 0, 'settle' . $suffix), $suffix),
            'performance' => $this->track('PERFORMANCE', fn() => $this->jobPerformance(), $suffix),
            'cleanup' => $this->track('CLEANUP', fn() => $this->jobCleanup(), $suffix),
        };
        return array_merge(is_array($result) ? $result : ['status' => (string) $result], ['job' => $job, 'forced' => $force]);
    }

    /**
     * Bookkeeping wrapper: jobs that never call a provider still record a run, so
     * "when did this last happen" is answerable for every job from one table.
     */
    private function track(string $jobType, callable $fn, string $suffix = ''): array
    {
        $key = $jobType . ($suffix === '' ? ':' . gmdate('Ymd\TH') : $suffix);
        $run = $this->repo->startSyncRun(['executionKey' => $key, 'jobType' => $jobType, 'windowStart' => gmdate('Y-m-d'), 'startedAt' => gmdate('c')]);
        if ($run === null) return ['status' => 'DUPLICATE_SKIPPED', 'executionKey' => $key];
        try {
            $result = $fn();
        } catch (\Throwable $e) {
            $this->repo->finishSyncRun($key, ['status' => 'FAILED', 'errors' => [mb_substr($e->getMessage(), 0, 300)], 'requests' => 0,
                'nextRunAt' => gmdate('c', time() + $this->football->config()->refreshInterval(strtolower($jobType)))]);
            throw $e;
        }
        $interval = $this->football->config()->refreshInterval(strtolower($jobType));
        $this->repo->finishSyncRun($key, [
            'status' => (string) ($result['status'] ?? 'COMPLETED'),
            'processed' => (int) ($result['processed'] ?? $result['fixtures'] ?? $result['scanned'] ?? 0),
            'created' => (int) ($result['created'] ?? 0),
            'updated' => (int) ($result['updated'] ?? 0),
            'requests' => (int) ($result['requests'] ?? 0),
            'errors' => (array) ($result['errors'] ?? []),
            'nextRunAt' => gmdate('c', time() + $interval),
        ]);
        return is_array($result) ? $result + ['executionKey' => $key] : ['status' => 'COMPLETED', 'executionKey' => $key];
    }

    /** Tomorrow + the next days' fixtures, so the board is ready before kickoff. */
    private function jobUpcoming(string $date, string $suffix = ''): array
    {
        $to = gmdate('Y-m-d', strtotime($date . ' +3 days'));
        return $this->football->fixtures()->sweep('UPCOMING', 'fixtures', $date, $to, 'upcoming:' . $date . $suffix, function ($provider) use ($date, $to) {
            return method_exists($provider, 'fixtures') ? $provider->fixtures(['from' => $date, 'to' => $to, 'date' => $date]) : [];
        }, null, $this->football->config()->requestBudget('upcoming'));
    }

    /**
     * Live sweep: refresh in-play matches, collect in-match statistics where the
     * provider has them, and refresh each live card's estimate.
     */
    private function jobLive(string $suffix = ''): array
    {
        $sync = $this->football->fixtures()->syncLive('live' . $suffix);
        $statistics = 0; $errors = (array) ($sync['errors'] ?? []);
        if (in_array((string) ($sync['status'] ?? ''), ['COMPLETED', 'DEFERRED'], true)) {
            foreach ($this->repo->listFixtures(['status' => 'LIVE'], 60) as $fixture) {
                $result = $this->football->statistics()->collectFixtureStatistics((int) $fixture['id'], (string) ($fixture['provider_code'] ?? ''), (string) ($fixture['external_id'] ?? ''));
                if (($result['status'] ?? '') === 'COMPLETED') $statistics++;
            }
        }
        $board = $this->football->live()->board(false);
        $errors = array_merge($errors, (array) ($board['errors'] ?? []));
        return ['status' => (string) ($sync['status'] ?? 'FAILED'), 'processed' => (int) ($sync['processed'] ?? 0),
            'created' => 0, 'updated' => (int) ($sync['processed'] ?? 0), 'liveMatches' => count($board['matches']),
            'fixtureStatistics' => $statistics, 'requests' => (int) ($sync['requests'] ?? 0), 'errors' => $errors];
    }

    /** Today + tomorrow's not-yet-kicked-off fixtures get a stored prediction. */
    private function jobPredict(string $date): array
    {
        $today = $this->football->predictions()->predictDay($date);
        $tomorrow = $this->football->predictions()->predictDay(gmdate('Y-m-d', strtotime($date . ' +1 day')));
        return [
            'status' => ($today['status'] ?? '') === DataState::UNAVAILABLE && ($tomorrow['status'] ?? '') === DataState::UNAVAILABLE ? DataState::UNAVAILABLE : 'COMPLETED',
            'processed' => (int) ($today['analyzed'] ?? 0) + (int) ($tomorrow['analyzed'] ?? 0),
            'created' => (int) ($today['qualified'] ?? 0) + (int) ($tomorrow['qualified'] ?? 0),
            'updated' => 0,
            'qualified' => (int) ($today['qualified'] ?? 0) + (int) ($tomorrow['qualified'] ?? 0),
            'limited' => (int) ($today['limited'] ?? 0) + (int) ($tomorrow['limited'] ?? 0),
            'rejected' => (int) ($today['rejected'] ?? 0) + (int) ($tomorrow['rejected'] ?? 0),
            'errors' => array_merge((array) ($today['errors'] ?? []), (array) ($tomorrow['errors'] ?? [])),
            'requests' => 0,
            'note' => 'analysis reads stored fixtures only; provider budget for this job is ' . $this->football->config()->requestBudget('predict'),
        ];
    }

    /**
     * Performance + calibration attempt. Calibration is fitted every pass and
     * simply reports CALIBRATION_PENDING until the sample supports it — the
     * difference between the two states is the sample count, never a switch
     * that quietly disables forecasting.
     */
    private function jobPerformance(): array
    {
        $model = $this->football->models()->usable();
        $modelVersionId = (int) ($model['model']['id'] ?? 0);
        $snapshot = $modelVersionId > 0 ? $this->football->performance()->snapshot(30, $modelVersionId) : $this->football->performance()->snapshot(30);
        $calibration = $modelVersionId > 0 ? $this->football->calibrate($modelVersionId) : ['status' => 'MODEL_NOT_LOADED'];
        $report = $snapshot['report'] ?? [];
        // A model becomes TRAINED/VALIDATED only through an operator; here we
        // only keep its measured figures current.
        if ($modelVersionId > 0 && (int) ($report['evaluatedPredictions'] ?? 0) > 0) {
            $this->football->models()->recordEvaluation($modelVersionId, [
                'validation_sample_size' => (int) $report['evaluatedPredictions'],
                'accuracy' => $report['resultAccuracy'] ?? null,
                'log_loss' => $report['logLoss'] ?? null,
                'brier_score' => $report['brier'] ?? null,
                'ece' => $report['ece'] ?? null,
                'last_evaluated_at' => gmdate('c'),
            ]);
        }
        return ['status' => 'COMPLETED', 'processed' => (int) ($report['evaluatedPredictions'] ?? 0), 'created' => 0, 'updated' => 0,
            'calibration' => (string) ($calibration['status'] ?? 'UNKNOWN'), 'calibrationSamples' => (int) ($calibration['samples'] ?? 0),
            'calibrationMinimum' => (int) ($calibration['minimum'] ?? 0), 'requests' => 0,
            'reason' => $calibration['reason'] ?? null];
    }

    /**
     * Housekeeping. Only operational history is pruned — predictions, score rows
     * for live predictions and settlements are retained indefinitely, because a
     * published forecast must stay checkable.
     */
    private function jobCleanup(): array
    {
        $pruned = $this->repo->pruneSyncLogs(120);
        $orphans = $this->repo->pruneOrphanScoreRows();
        return ['status' => 'COMPLETED', 'processed' => $pruned + $orphans, 'created' => 0, 'updated' => 0,
            'syncLogsRemoved' => $pruned, 'orphanScoreRowsRemoved' => $orphans, 'requests' => 0];
    }
}
