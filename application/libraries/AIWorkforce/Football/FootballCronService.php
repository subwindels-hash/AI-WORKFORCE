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
    public const JOBS = ['fixtures', 'upcoming', 'live', 'results', 'statistics', 'odds', 'predict', 'settle', 'performance', 'cleanup'];
    private const PROVIDER_JOBS = ['fixtures', 'upcoming', 'live', 'results', 'statistics', 'odds'];

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
        // An automatic run reuses the same execution key inside the job's own
        // refresh window, so an overlapping tick is a no-op and the next window
        // gets a fresh key — this is what lets the 90-second live cadence
        // actually tick while slower jobs dedupe per hour/day. (The key used to
        // be hour-bucketed for every job, which silently froze live scores,
        // results and settlement at one run per hour.) An operator-forced run
        // gets a unique key and really re-reads the provider (the data writes
        // stay idempotent either way — fixtures upsert by provider+external id,
        // settlements insert once).
        $jobInterval = $this->football->refresh()->interval('football-' . $job);
        $suffix = $force
            ? ':' . gmdate('Ymd\THis')
            : ':' . intdiv(time(), max(30, $jobInterval));
        $result = match ($job) {
            'fixtures' => $this->track('FIXTURES', fn() => $this->football->fixtures()->syncDay($date, 'fixtures:' . $date . $suffix), $suffix),
            'upcoming' => $this->track('UPCOMING', fn() => $this->jobUpcoming($date, $suffix), $suffix),
            'live' => $this->track('LIVE', fn() => $this->jobLive($suffix), $suffix),
            'results' => $this->track('RESULTS', fn() => $this->football->fixtures()->syncResults('results' . $suffix), $suffix),
            // Enrich the same bounded candidate set that the next prediction
            // cycle can analyze. A provider budget/rate limit may stop this
            // early, in which case the missing evidence is reported and the
            // prediction quality gate decides per fixture — no data is filled in.
            'statistics' => $this->track('STATISTICS', fn() => $this->football->collectStatisticsForDay(
                $date, $this->football->config()->analysisBatchSize()
            ), $suffix),
            // Bookmaker prices for the fixtures already stored for today and
            // tomorrow. Without this job nothing ever priced the board: the
            // fixtures sweep stores matches, the predict job models them, and
            // every quote stayed missing because only an operator opening a
            // single match page ever asked the odds endpoint for a price.
            'odds' => $this->track('ODDS', fn() => $this->jobOdds($date), $suffix),
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
        // A failed run is retried soon (≤15 min), not on the full bucket
        // cadence — see RefreshPolicy::evaluate() for the matching gate. The
        // provider's backoff circuit still throttles repeated upstream errors.
        $status = (string) ($result['status'] ?? 'COMPLETED');
        if ($status === 'FAILED') $interval = min($interval, RefreshPolicy::FAILED_RETRY_SECONDS);
        $this->repo->finishSyncRun($key, [
            'status' => $status,
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
            foreach ($this->repo->listFixtures(['status' => FixtureSyncService::LIVE_STATUSES], 60) as $fixture) {
                $result = $this->football->statistics()->collectFixtureStatistics((int) $fixture['id'], (string) ($fixture['provider_code'] ?? ''), (string) ($fixture['external_id'] ?? ''));
                if (($result['status'] ?? '') === 'COMPLETED') $statistics++;
            }
        }
        $board = $this->football->live()->board(false);
        $errors = array_merge($errors, (array) ($board['errors'] ?? []));
        return ['status' => (string) ($sync['status'] ?? 'FAILED'), 'processed' => (int) ($sync['processed'] ?? 0),
            'created' => 0, 'updated' => (int) ($sync['updated'] ?? $sync['processed'] ?? 0),
            'expiredLive' => (int) ($sync['expiredLive'] ?? 0), 'liveMatches' => count($board['matches']),
            'fixtureStatistics' => $statistics, 'requests' => (int) ($sync['requests'] ?? 0), 'errors' => $errors];
    }

    /**
     * Price today's board, then spend whatever request budget is left on
     * tomorrow's.
     *
     * Today comes first deliberately: a match kicking off in two hours is the
     * one whose price a reader is about to act on, and on a quota-bound plan
     * the day that runs out of budget must be the far one, not the near one.
     */
    private function jobOdds(string $date): array
    {
        $sheet = $this->football->oddsSheet();
        $today = $sheet->refreshDay($date);
        // A skipped sweep (no store, no provider, no odds capability) is a
        // standing condition, not something tomorrow would answer differently.
        if ((string) ($today['status'] ?? '') === 'SKIPPED') {
            return $today + ['scope' => $date, 'tomorrow' => null];
        }
        $tomorrowDate = gmdate('Y-m-d', strtotime($date . ' +1 day'));
        $tomorrow = $this->football->config()->requestBudget('odds') !== 0 && (int) ($today['deferred'] ?? 0) === 0
            ? $sheet->refreshDay($tomorrowDate)
            : null;
        $sum = static fn(string $key): int => (int) ($today[$key] ?? 0) + (int) ($tomorrow[$key] ?? 0);
        $errors = array_merge((array) ($today['errors'] ?? []), (array) ($tomorrow['errors'] ?? []));
        return [
            'status' => (string) ($today['status'] ?? 'COMPLETED'),
            'scope' => $tomorrow === null ? $date : $date . ' + ' . $tomorrowDate,
            'processed' => $sum('priced') + $sum('unpriced'),
            'created' => $sum('stored'),
            'updated' => 0,
            'priced' => $sum('priced'),
            'unpriced' => $sum('unpriced'),
            'stored' => $sum('stored'),
            'invalid' => $sum('invalid'),
            'freshReused' => $sum('freshReused'),
            'deferred' => $sum('deferred'),
            'requests' => $sum('requests'),
            'errors' => $errors,
            'today' => $today,
            'tomorrow' => $tomorrow,
        ];
    }

    /**
     * Today's and tomorrow's not-yet-kicked-off fixtures share one configured
     * prediction cycle. A busy two-day window must not turn a 50-match setting
     * into 50 today plus another 50 tomorrow: after the current date consumes
     * its evaluated-fixture allowance, tomorrow receives only what remains.
     */
    private function jobPredict(string $date): array
    {
        $batchSize = $this->football->config()->analysisBatchSize();
        $today = $this->football->predictions()->predictDay($date, null, null, $batchSize);
        $remaining = max(0, $batchSize - (int) ($today['analyzed'] ?? 0));
        $tomorrow = $this->football->predictions()->predictDay(
            gmdate('Y-m-d', strtotime($date . ' +1 day')), null, null, $remaining
        );
        $processed = (int) ($today['analyzed'] ?? 0) + (int) ($tomorrow['analyzed'] ?? 0);
        return [
            'status' => $processed === 0 && ($today['status'] ?? '') === DataState::UNAVAILABLE && ($tomorrow['status'] ?? '') === DataState::UNAVAILABLE
                ? DataState::UNAVAILABLE : 'COMPLETED',
            'processed' => $processed,
            'created' => (int) ($today['qualified'] ?? 0) + (int) ($tomorrow['qualified'] ?? 0),
            'updated' => 0,
            'batchSize' => $batchSize,
            'remainingAfterToday' => $remaining,
            'qualified' => (int) ($today['qualified'] ?? 0) + (int) ($tomorrow['qualified'] ?? 0),
            'limited' => (int) ($today['limited'] ?? 0) + (int) ($tomorrow['limited'] ?? 0),
            'rejected' => (int) ($today['rejected'] ?? 0) + (int) ($tomorrow['rejected'] ?? 0),
            'errors' => array_merge((array) ($today['errors'] ?? []), (array) ($tomorrow['errors'] ?? [])),
            'requests' => 0,
            'note' => 'analysis reads stored fixtures only; this run evaluated ' . $processed . ' of at most ' . $batchSize
                . ' fixture(s) across today and tomorrow. Provider budget for this job is ' . $this->football->config()->requestBudget('predict'),
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
        // The revision trail is operational history, not a published forecast: it
        // exists to explain a movement the reader can still see on the page. Once
        // the prediction it describes has aged out of the board, keeping it serves
        // nobody — but the window is configured, because an operator auditing a
        // model's drift over a season needs the trail to outlive the fixture list.
        $revisions = $this->repo->prunePredictionRevisions($this->football->config()->revisionRetentionDays());
        return ['status' => 'COMPLETED', 'processed' => $pruned + $orphans + $revisions, 'created' => 0, 'updated' => 0,
            'syncLogsRemoved' => $pruned, 'orphanScoreRowsRemoved' => $orphans, 'revisionRowsRemoved' => $revisions,
            'retentionDays' => $this->football->config()->revisionRetentionDays(), 'requests' => 0];
    }
}
