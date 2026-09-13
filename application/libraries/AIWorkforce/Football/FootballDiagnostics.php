<?php
namespace AIWorkforce\Football;

use AIWorkforce\Persistence\FootballRepository;

/**
 * Admin diagnostics + prediction readiness.
 *
 * Two questions, kept strictly apart:
 *
 *  "Can the module forecast today?" — provider, stored fixtures, statistics,
 *  model registration, feature generation. Answered by `readiness()`.
 *
 *  "How has it performed?" — accuracy, Brier, ECE, ROI-style figures. Answered
 *  by PerformanceService from stored settlements only.
 *
 * An empty settlement history therefore never appears as a blocker here: it is
 * reported as NO_SETTLED_PREDICTIONS in its own section while forecasting stays
 * available, which is exactly the separation the module was criticised for
 * missing.
 */
final class FootballDiagnostics
{
    public const NOT_CONFIGURED = 'NOT_CONFIGURED';
    public const UNAVAILABLE = 'UNAVAILABLE';
    public const WAITING_FOR_DATA = 'WAITING_FOR_DATA';
    public const READY = 'READY';
    public const DEGRADED = 'DEGRADED';

    public function __construct(
        private FootballRepository $repo,
        private ProviderGateway $gateway,
        private FootballConfiguration $config,
        private ModelRegistry $models,
        private CalibrationService $calibration,
        private RefreshPolicy $refresh,
        private StatisticsCollector $stats,
    ) {}

    /**
     * @return array{state:string, headline:string, message:?string, checks:list<array<string,mixed>>,
     *               blockers:list<string>, warnings:list<string>, canPredict:bool, counts:array<string,int>,
     *               cadence:array<string,mixed>, demoMode:bool, generatedAt:string}
     */
    public function snapshot(): array
    {
        $providerStatus = $this->gateway->status();
        $today = gmdate('Y-m-d');
        // Fast counts instead of hydrating 500 full fixture rows + 2 extra
        // withCompetitionRef queries per listFixtures call.
        $fixturesTodayCount = $this->repo->countFixtures(['date' => $today]);
        $fixturesToday = $fixturesTodayCount === 0 ? [] : $this->repo->listFixtures(['date' => $today], min(5, max(1, $this->config->analysisLimit())));
        $liveCount = $this->repo->countFixtures(['status' => FixtureSyncService::LIVE_STATUSES]);
        $finishedCount = $this->repo->countFixtures(['status' => 'FINISHED']);
        $predictionsCount = $this->repo->countPredictions(['date' => $today, 'kind' => PredictionService::KIND_PRE_MATCH]);
        $model = $this->models->usable();
        $modelRow = $model['model'] ?? null;
        $calibrationRow = $modelRow === null ? null : $this->calibration->activeCalibration((int) ($modelRow['calibration_version_id'] ?? 0));
        $settled = $this->repo->settlementAggregates([]);
        $providerState = (string) ($providerStatus['state'] ?? self::NOT_CONFIGURED);
        $fixtureState = $fixturesToday === [] ? self::UNAVAILABLE : 'AVAILABLE';
        $statisticsState = $this->statisticsState($fixturesToday);
        $engineState = match (true) {
            $providerState === self::NOT_CONFIGURED => self::WAITING_FOR_DATA,
            $fixtureState === self::UNAVAILABLE => self::WAITING_FOR_DATA,
            $model['state'] === 'NONE' => 'REGISTERED_AS_DRAFT',
            default => $model['state'],
        };
        $blockers = [];
        $warnings = [];
        if ($providerState === self::NOT_CONFIGURED) {
            $blockers[] = 'FOOTBALL_PROVIDER_NOT_CONFIGURED';
        } elseif ($providerState === 'DEGRADED') {
            $warnings[] = 'FOOTBALL_PROVIDER_DEGRADED';
        }
        if ($fixtureState === self::UNAVAILABLE) {
            $blockers[] = 'FOOTBALL_FIXTURES_' . self::UNAVAILABLE;
        }
        if ($statisticsState !== 'AVAILABLE') {
            $warnings[] = 'FOOTBALL_STATISTICS_' . $statisticsState;
        }
        if ($model['state'] === 'NONE') {
            $warnings[] = 'MODEL_NOT_LOADED';
        } elseif ($model['state'] !== ModelRegistry::ACTIVE) {
            $warnings[] = 'MODEL_' . $model['state'];
        }
        if ($calibrationRow === null) {
            $warnings[] = CalibrationService::PENDING;
        }
        if ($this->config->demoMode()) {
            $warnings[] = 'DEMO_MODE_ENABLED';
        }
        $canPredict = $blockers === [];
        $checks = [
            ['key' => 'Provider', 'value' => $providerState, 'state' => $providerState === 'CONNECTED' ? self::READY : ($providerState === self::NOT_CONFIGURED ? self::NOT_CONFIGURED : self::DEGRADED),
                'detail' => (string) ($providerStatus['detail'] ?? 'no provider registered'),
                'action' => $providerState === self::NOT_CONFIGURED
                    ? 'Configure a verified football data source (' . implode(' or ', \AIWorkforce\ApiProviders::footballKeyEnvNames())
                        . ' — the sports providers in Admin → API work too).'
                    : ($providerState === 'DEGRADED' ? 'Check the provider detail below and the quota counters; the sweep retries automatically.' : null)],
            ['key' => 'Fixtures', 'value' => $fixtureState === 'AVAILABLE' ? 'AVAILABLE' : self::UNAVAILABLE, 'state' => $fixtureState === 'AVAILABLE' ? self::READY : self::WAITING_FOR_DATA,
                'detail' => $fixturesTodayCount . ' stored for ' . $today, 'action' => $fixtureState === self::UNAVAILABLE ? 'Run a fixture sync for ' . $today . '.' : null],
            ['key' => 'Statistics', 'value' => $statisticsState === 'AVAILABLE' ? 'AVAILABLE' : self::UNAVAILABLE, 'state' => $statisticsState === 'AVAILABLE' ? self::READY : self::WAITING_FOR_DATA,
                'detail' => $statisticsState === 'AVAILABLE' ? 'team and league statistics stored' : 'no statistics rows for today\'s fixtures', 'action' => $statisticsState === 'AVAILABLE' ? null : 'Run the statistics job (league table first, per-team fallback).'],
            ['key' => 'Prediction Engine', 'value' => $engineState, 'state' => $canPredict ? self::READY : self::WAITING_FOR_DATA,
                'detail' => $predictionsCount . ' prediction rows today · ' . $liveCount . ' live', 'action' => null],
            ['key' => 'Model', 'value' => (string) ($modelRow['status'] ?? 'NONE'), 'state' => $modelRow === null ? 'NONE' : (string) $modelRow['status'],
                'detail' => $modelRow === null ? 'no model version registered' : trim(($modelRow['model_name'] ?? '') . ' ' . ($modelRow['model_version'] ?? '')),
                'action' => $modelRow === null || (string) ($modelRow['status'] ?? '') === ModelRegistry::DRAFT ? 'Validate, then approve and activate a model version from the admin panel.' : null],
            ['key' => 'Calibration', 'value' => $calibrationRow === null ? CalibrationService::PENDING : (string) ($calibrationRow['status'] ?? 'PENDING'),
                'state' => $calibrationRow === null ? CalibrationService::PENDING : 'CALIBRATED',
                'detail' => $calibrationRow === null
                    ? $this->calibrationPendingDetail()
                    : 'version ' . (string) ($calibrationRow['calibration_version'] ?? '') . ' · ' . (int) ($calibrationRow['sample_size'] ?? 0) . ' samples · ECE ' . number_format((float) ($calibrationRow['ece'] ?? 0), 4),
                'action' => $calibrationRow === null ? 'Calibration is fitted automatically once ' . $this->config->minCalibrationSamples() . ' settled predictions exist.' : null],
            ['key' => 'Settlement history', 'value' => (int) ($settled['evaluated'] ?? 0) . ' settled', 'state' => (int) ($settled['evaluated'] ?? 0) > 0 ? self::READY : PerformanceService::NO_DATA,
                'detail' => (int) ($settled['evaluated'] ?? 0) > 0
                    ? 'result accuracy ' . number_format(100 * ((int) ($settled['correctResults'] ?? 0) / max(1, (int) $settled['evaluated'])), 1) . '%'
                    : PerformanceService::EMPTY_MESSAGE,
                'action' => null, 'gatesPredictions' => false],
        ];
        $schedule = $this->refresh->schedule();
        $result = [
            'state' => $canPredict ? ($warnings === [] ? self::READY : self::DEGRADED) : self::WAITING_FOR_DATA,
            'headline' => match (true) {
                $canPredict && $warnings === [] => 'Football intelligence is reading live provider data.',
                $canPredict => 'Football intelligence is running with reduced data quality.',
                in_array('FOOTBALL_PROVIDER_NOT_CONFIGURED', $blockers, true) => self::NO_PROVIDER_MESSAGE,
                in_array('FOOTBALL_FIXTURES_' . self::UNAVAILABLE, $blockers, true) => 'No fixtures are stored for today yet. The football engine reads stored fixtures only — it will analyze the day as soon as a fixture sync succeeds.',
                default => 'The football pipeline is waiting for data: see the checks below for the exact blocker.',
            },
            'message' => $canPredict ? null : (in_array('FOOTBALL_PROVIDER_NOT_CONFIGURED', $blockers, true)
                ? self::NO_PROVIDER_MESSAGE
                : 'Football predictions are waiting for data. ' . (string) ($checks[0]['detail'] ?? '')),
            'checks' => $checks,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'canPredict' => $canPredict,
            'counts' => [
                'fixturesToday' => $fixturesTodayCount,
                'liveFixtures' => $liveCount,
                'finishedFixtures' => $finishedCount,
                'predictionsToday' => $predictionsCount,
                'settled' => (int) ($settled['evaluated'] ?? 0),
                'providers' => count((array) ($providerStatus['providers'] ?? [])),
            ],
            'cadence' => [
                'intervals' => $this->config->describe()['refreshIntervals'] ?? [],
                'budgets' => $this->config->describe()['requestBudget'] ?? [],
                'maxDataAgeSeconds' => $this->config->describe()['maxDataAgeSeconds'] ?? [],
                'due' => $schedule['due'],
                'nextWakeAt' => $schedule['nextWakeAt'],
                'nextWakeInSeconds' => $schedule['nextWakeInSeconds'],
                'jobs' => $schedule['jobs'],
            ],
            'demoMode' => $this->config->demoMode(),
            'generatedAt' => gmdate('c'),
        ];
        return $result;
    }

    /** The four-line summary the module header used to fudge. */
    public function quickStatus(): array
    {
        $snapshot = $this->snapshot();
        $out = [];
        foreach ($snapshot['checks'] as $check) {
            if (in_array((string) $check['key'], ['Provider', 'Fixtures', 'Statistics', 'Prediction Engine', 'Model', 'Calibration'], true)) {
                $out[(string) $check['key']] = (string) $check['value'];
            }
        }
        return $out;
    }

    /**
     * Per-fixture tracking state, so an operator can see why a specific match is
     * or is not being refreshed.
     *
     * @return list<array<string,mixed>>
     */
    public function fixtureCadence(?string $date = null, int $limit = 40): array
    {
        $rows = $this->repo->listFixtures(['date' => $date ?? gmdate('Y-m-d')], max(1, min(200, $limit)));
        $out = [];
        foreach ($rows as $fixture) {
            $policy = $this->refresh->forFixture($fixture);
            $out[] = ['fixtureId' => (int) $fixture['id'], 'externalId' => (string) ($fixture['external_id'] ?? ''),
                'match' => trim((string) ($fixture['home_team'] ?? '') . ' v ' . (string) ($fixture['away_team'] ?? '')),
                'kickoff' => $fixture['kickoff_at'] ?? null, 'status' => (string) ($fixture['status'] ?? ''),
                'dataState' => (string) ($fixture['data_state'] ?? ''), 'coverage' => $fixture['coverage'] ?? [],
                'phase' => $policy['phase'], 'intervalSeconds' => $policy['interval'], 'reason' => $policy['reason']];
        }
        return $out;
    }

    private function statisticsState(array $fixturesToday): string
    {
        if ($fixturesToday === []) return self::UNAVAILABLE;
        // Sample at most 3 fixtures: the original loop did N * 2 queries
        // (findTeamStatistics + deriveForm->listTeamRecentResults) which on
        // a 40-fixture day is ~80 queries through WASM sqlite. Sampling
        // reduces to at most 6 queries while still distinguishing
        // AVAILABLE/LIMITED/UNAVAILABLE for the operator. When a day has many
        // fixtures the coverage is uniform enough that a 3-sample is
        // representative; an empty or single-fixture day is unchanged.
        $sample = array_slice($fixturesToday, 0, 3);
        $available = 0; $limited = 0;
        $seen = [];
        foreach ($sample as $fixture) {
            $providerId = (int) ($fixture['provider_id'] ?? 0);
            $teamId = (string) ($fixture['home_team_id'] ?? '');
            if ($teamId === '') continue;
            $key = $providerId . ':' . $teamId;
            if (isset($seen[$key])) {
                $hasTeamStats = $seen[$key]['hasTeamStats'];
                $hasForm = $seen[$key]['hasForm'];
            } else {
                $hasTeamStats = $this->repo->findTeamStatistics($providerId, $teamId, null, null) !== null;
                $hasForm = (($this->stats->deriveForm($providerId, $teamId, null, 5)['played'] ?? 0) > 0);
                $seen[$key] = ['hasTeamStats' => $hasTeamStats, 'hasForm' => $hasForm];
            }
            if ($hasTeamStats && $hasForm) $available++;
            elseif ($hasTeamStats || $hasForm) $limited++;
        }
        if ($available >= max(1, (int) ceil(count($sample) * 0.5))) return 'AVAILABLE';
        if ($available + $limited > 0) return 'LIMITED';
        return self::UNAVAILABLE;
    }

    private function calibrationPendingDetail(): string
    {
        $settled = (int) ($this->repo->settlementAggregates([])['evaluated'] ?? 0);
        $minimum = $this->config->minCalibrationSamples();
        return 'Calibration pending: ' . $settled . ' settled prediction(s) stored, ' . $minimum . ' required before a temperature fit is accepted.'
            . ' Predictions are labelled uncalibrated until then.';
    }

    public const NO_PROVIDER_MESSAGE = 'Football data provider not connected. Live fixtures and predictions are unavailable until a verified data source is configured.';
}
