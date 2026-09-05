<?php
namespace AIWorkforce\Football;

use AIWorkforce\Persistence\AuditRepository;
use AIWorkforce\Persistence\FootballRepository;
use AIWorkforce\Sports\Providers\SportsProviderManager;

/**
 * Football intelligence: the single entry point both the console page and the
 * JSON API use, so a figure can never exist in one surface and not the other.
 *
 * Wiring lives here and nowhere else: gateway → sync → statistics → features →
 * quality gate → score model → calibration → storage → settlement → performance →
 * board. Construction is lazy so a page that only reads the board never pays for
 * a provider sweep, and every service is replaceable (the test harness injects
 * an in-memory repository through `fromParts()`).
 */
final class FootballIntelligence
{
    private ?ProviderGateway $gateway = null;
    private ?FixtureSyncService $fixtures = null;
    private ?StatisticsCollector $statistics = null;
    private ?FeatureBuilder $features = null;
    private ?ExpectedGoalsResolver $expectedGoals = null;
    private ?ScoreProbabilityModel $scores = null;
    private ?OutcomePredictor $predictor = null;
    private ?CalibrationService $calibration = null;
    private ?ModelRegistry $models = null;
    private ?PredictionService $predictions = null;
    private ?LiveMatchService $live = null;
    private ?SettlementService $settlements = null;
    private ?PerformanceService $performance = null;
    private ?PredictionBoard $board = null;
    private ?RefreshPolicy $refresh = null;
    private ?FootballDiagnostics $diagnostics = null;
    private ?FootballCronService $cron = null;

    public function __construct(
        private FootballRepository $repo,
        private ?SportsProviderManager $providers,
        private ?AuditRepository $audit = null,
        private ?FootballConfiguration $config = null,
    ) {
        $this->config = $config ?? new FootballConfiguration();
    }

    /** Explicit assembly — used by the test harness and by callers with their own gateway. */
    public static function fromParts(FootballRepository $repo, ?ProviderGateway $gateway, ?AuditRepository $audit = null, ?FootballConfiguration $config = null): self
    {
        $self = new self($repo, null, $audit, $config);
        $self->gateway = $gateway;
        return $self;
    }

    public function config(): FootballConfiguration
    {
        return $this->config;
    }

    public function repository(): FootballRepository
    {
        return $this->repo;
    }

    public function gateway(): ProviderGateway
    {
        return $this->gateway ??= new ProviderGateway($this->providers ?? new SportsProviderManager(), $this->config, $this->repo);
    }

    public function providerManager(): SportsProviderManager
    {
        $this->gateway();
        return $this->providers ?? new SportsProviderManager();
    }

    public function fixtures(): FixtureSyncService
    {
        return $this->fixtures ??= new FixtureSyncService($this->repo, $this->gateway(), $this->config, $this->audit);
    }

    public function statistics(): StatisticsCollector
    {
        return $this->statistics ??= new StatisticsCollector($this->repo, $this->gateway(), $this->config, $this->audit);
    }

    public function features(): FeatureBuilder
    {
        return $this->features ??= new FeatureBuilder($this->repo, $this->statistics(), $this->config);
    }

    public function expectedGoals(): ExpectedGoalsResolver
    {
        return $this->expectedGoals ??= new ExpectedGoalsResolver($this->config);
    }

    public function scores(): ScoreProbabilityModel
    {
        return $this->scores ??= new ScoreProbabilityModel($this->config);
    }

    public function calibration(): CalibrationService
    {
        return $this->calibration ??= new CalibrationService($this->repo, $this->config, $this->audit);
    }

    public function predictor(): OutcomePredictor
    {
        return $this->predictor ??= new OutcomePredictor($this->expectedGoals(), $this->scores(), $this->calibration(), $this->config);
    }

    public function models(): ModelRegistry
    {
        return $this->models ??= new ModelRegistry($this->repo, $this->config, $this->audit);
    }

    public function predictions(): PredictionService
    {
        return $this->predictions ??= new PredictionService($this->repo, $this->features(), $this->predictor(), $this->models(), $this->config, $this->audit);
    }

    public function live(): LiveMatchService
    {
        return $this->live ??= new LiveMatchService($this->repo, $this->features(), $this->predictor(), $this->models(), $this->predictions(), $this->fixtures(), $this->audit);
    }

    public function settlements(): SettlementService
    {
        return $this->settlements ??= new SettlementService($this->repo, $this->audit, $this->fixtures());
    }

    public function performance(): PerformanceService
    {
        return $this->performance ??= new PerformanceService($this->repo, $this->calibration(), $this->models());
    }

    public function board(): PredictionBoard
    {
        return $this->board ??= new PredictionBoard($this->repo, $this->predictions(), $this->models(), $this->config);
    }

    public function refresh(): RefreshPolicy
    {
        return $this->refresh ??= new RefreshPolicy($this->repo, $this->config, $this->gateway());
    }

    /**
     * The scheduled jobs behind `php index.php tools football-cron` and the
     * platform cron sweep. Built here so `CronRunner` reaches it the same way it
     * reaches every other module (`platform->football->cron()`).
     */
    public function cron(): FootballCronService
    {
        return $this->cron ??= new FootballCronService($this, $this->repo, $this->audit);
    }

    public function diagnostics(): FootballDiagnostics
    {
        return $this->diagnostics ??= new FootballDiagnostics($this->repo, $this->gateway(), $this->config, $this->models(), $this->calibration(), $this->refresh(), $this->statistics());
    }

    // ── read models ───────────────────────────────────────────────────────────

    /**
     * The console payload: board + diagnostics + performance + models, assembled
     * once so the view renders each panel exactly one time.
     *
     * @return array<string,mixed>
     */
    public function dashboard(?string $date = null, bool $refresh = false): array
    {
        $date = $date ?? gmdate('Y-m-d');
        $diagnostics = $this->diagnostics()->snapshot();
        return [
            'date' => $date,
            'board' => $this->board()->forDate($date, $refresh),
            'diagnostics' => $diagnostics,
            'performance' => $this->performance()->report(30),
            'live' => $this->live()->board(false),
            'models' => $this->modelSummary(),
            'generatedAt' => gmdate('c'),
        ];
    }

    /** Model + calibration panel data, sourced from stored rows only. */
    public function modelSummary(): array
    {
        $usable = $this->models()->usable();
        $model = $usable['model'];
        $modelVersionId = (int) ($model['id'] ?? 0);
        $calibrations = $modelVersionId > 0 ? $this->calibration()->versions($modelVersionId) : [];
        $active = null;
        foreach ($calibrations as $row) {
            if ((string) $row['status'] === CalibrationService::CALIBRATED) { $active = $row; break; }
        }
        $versions = $this->models()->list();
        return [
            'state' => (string) $usable['state'],
            'label' => (string) $usable['label'],
            'reason' => $usable['reason'],
            'activeModel' => $model === null ? null : [
                'id' => $modelVersionId,
                'modelId' => (string) ($model['model_id'] ?? ''),
                'name' => (string) ($model['model_name'] ?? ''),
                'version' => (string) ($model['model_version'] ?? ''),
                'algorithm' => (string) ($model['algorithm'] ?? ''),
                'featureVersion' => (string) ($model['feature_version'] ?? ''),
                'status' => (string) ($model['status'] ?? ModelRegistry::DRAFT),
                'trainingDatasetVersion' => $model['training_dataset_version'] ?? null,
                'createdAt' => $model['created_at'] ?? null,
                'trainedAt' => $model['trained_at'] ?? null,
                'validatedAt' => $model['validated_at'] ?? null,
                'calibratedAt' => $model['calibrated_at'] ?? null,
                'approvedAt' => $model['approved_at'] ?? null,
                'approvedBy' => $model['approved_by'] ?? null,
                'activatedAt' => $model['activated_at'] ?? null,
                'lastEvaluatedAt' => $model['last_evaluated_at'] ?? null,
                'validationSampleSize' => isset($model['validation_sample_size']) ? (int) $model['validation_sample_size'] : null,
                'accuracy' => $model['accuracy'] ?? null,
                'logLoss' => $model['log_loss'] ?? null,
                'brierScore' => $model['brier_score'] ?? null,
                'ece' => $model['ece'] ?? null,
                'calibrationVersion' => $active['calibrationVersion'] ?? null,
                'calibrationStatus' => $active['status'] ?? CalibrationService::PENDING,
            ],
            'calibration' => $active ?? ['status' => CalibrationService::PENDING, 'samples' => 0, 'calibrationVersion' => null],
            'calibrationVersions' => $calibrations,
            'approvedCalibrationCount' => $this->calibration()->approvedCount(),
            'versions' => array_map(fn(array $row) => [
                'id' => (int) ($row['id'] ?? 0),
                'modelId' => (string) ($row['model_id'] ?? ''),
                'name' => (string) ($row['model_name'] ?? ''),
                'version' => (string) ($row['model_version'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'createdAt' => $row['created_at'] ?? null,
                'trainedAt' => $row['trained_at'] ?? null,
                'validatedAt' => $row['validated_at'] ?? null,
                'approvedAt' => $row['approved_at'] ?? null,
                'activatedAt' => $row['activated_at'] ?? null,
                'lastEvaluatedAt' => $row['last_evaluated_at'] ?? null,
                'validationSampleSize' => isset($row['validation_sample_size']) ? (int) $row['validation_sample_size'] : null,
                'accuracy' => $row['accuracy'] ?? null,
                'logLoss' => $row['log_loss'] ?? null,
                'brierScore' => $row['brier_score'] ?? null,
                'ece' => $row['ece'] ?? null,
                'trainingDatasetVersion' => $row['training_dataset_version'] ?? null,
                'calibrationVersionId' => $row['calibration_version_id'] ?? null,
                'approvedBy' => $row['approved_by'] ?? null,
                'states' => ModelRegistry::STATES,
            ], $versions),
        ];
    }

    /** @return array<string,mixed> */
    public function providerStatus(): array
    {
        $status = $this->gateway()->status();
        return $status + ['configured' => $this->gateway()->configured(), 'capabilities' => $this->gateway()->capabilities(), 'demoMode' => $this->config->demoMode()];
    }

    /**
     * Feature + quality view for one fixture (the `/matches/:id/analysis` read
     * model). Never predicts, so it is safe to call for any stored fixture.
     */
    public function analysis(int $fixtureId): array
    {
        $fixture = $this->repo->findFixtureById($fixtureId);
        if ($fixture === null) return ['status' => 'NOT_FOUND', 'fixtureId' => $fixtureId, 'dataState' => DataState::UNAVAILABLE];
        $features = $this->features()->build($fixture);
        $prediction = $this->repo->listPredictions(['fixtureId' => $fixtureId, 'kind' => PredictionService::KIND_PRE_MATCH], 1)[0] ?? null;
        return [
            'status' => 'OK',
            'fixture' => PredictionService::fixtureSummary($fixture),
            'teams' => $features['teams'],
            'competition' => $features['competition'],
            'headToHead' => $features['headToHead'],
            'inMatch' => $features['inMatch'] ?? null,
            'dataQuality' => $features['dataQuality'],
            'coverage' => $features['coverage'],
            'provenance' => $features['provenance'],
            'provider' => $features['provider'],
            'prediction' => $prediction === null ? null : $this->predictions()->contract($prediction, $fixture),
            'generatedAt' => gmdate('c'),
        ];
    }

    /** Live + historical prediction rows for one fixture (§17 `/matches/:id/prediction`). */
    public function predictionFor(int $fixtureId, bool $generate = false): array
    {
        $fixture = $this->repo->findFixtureById($fixtureId);
        if ($fixture === null) return ['status' => 'NOT_FOUND', 'fixtureId' => $fixtureId, 'dataState' => DataState::UNAVAILABLE];
        if ($generate) {
            $payload = $this->predictions()->predictFixture($fixtureId);
            if (($payload['status'] ?? '') !== 'PREDICTED') {
                return ['status' => (string) ($payload['status'] ?? 'NO_PREDICTION'), 'code' => $payload['code'] ?? null,
                    'reason' => $payload['reason'] ?? null, 'reasoning' => $payload['reasoning'] ?? [],
                    'dataQuality' => $payload['dataQuality'] ?? null, 'model' => $payload['model'] ?? null,
                    'fixture' => PredictionService::fixtureSummary($fixture), 'generatedAt' => gmdate('c')];
            }
        }
        $rows = $this->repo->listPredictions(['fixtureId' => $fixtureId], 10);
        $preMatch = null; $liveRows = [];
        foreach ($rows as $row) {
            if ((string) ($row['prediction_kind'] ?? '') === PredictionService::KIND_LIVE) $liveRows[] = $this->predictions()->contract($row, $fixture);
            else $preMatch = $this->predictions()->contract($row, $fixture);
        }
        $settlement = $preMatch === null ? null : $this->repo->findSettlement((string) ($preMatch['predictionId'] ?? ''));
        return [
            'status' => $preMatch === null ? 'NO_PREDICTION' : 'OK',
            'fixture' => PredictionService::fixtureSummary($fixture),
            'prediction' => $preMatch,
            'liveEstimates' => $liveRows,
            'settlement' => $settlement,
            'message' => $preMatch === null ? 'No prediction row is stored for this fixture' . ($generate ? ' — it was analyzed and refused (see dataQuality)' : '.') : null,
            'generatedAt' => gmdate('c'),
        ];
    }

    /**
     * Settlement-graded history for the history panel; graded rows only, and the
     * explicit empty state when there is nothing yet.
     */
    public function history(int $limit = 50, ?int $modelVersionId = null): array
    {
        $settled = $this->repo->listSettlements(array_filter(['modelVersionId' => $modelVersionId], static fn($v) => $v !== null), max(1, min(500, $limit)));
        $rows = [];
        foreach ($settled as $row) {
            $predictionId = (string) ($row['prediction_id'] ?? '');
            $prediction = $this->repo->findPrediction($predictionId) ?? [];
            $fixture = $this->repo->findFixtureById((int) ($row['fixture_id'] ?? 0)) ?? [];
            $rows[] = [
                'predictionId' => $predictionId,
                'fixture' => PredictionService::fixtureSummary($fixture),
                'predicted' => ['result' => (string) ($row['predicted_result'] ?? ''), 'score' => ['home' => $row['predicted_home_score'], 'away' => $row['predicted_away_score']],
                    'probabilities' => ['home' => $row['probability_home'], 'draw' => $row['probability_draw'], 'away' => $row['probability_away']],
                    'confidence' => $row['confidence'] ?? null, 'confidenceBasis' => $prediction['confidence_basis'] ?? 'RAW',
                    'dataQuality' => $row['data_quality_score'] ?? null,
                    'modelVersionId' => $row['model_version_id'] ?? null, 'calibrationVersionId' => $row['calibration_version_id'] ?? null],
                'actual' => ['score' => ['home' => $row['actual_home_score'], 'away' => $row['actual_away_score']], 'result' => (string) ($row['actual_result'] ?? ''), 'source' => (string) ($row['result_source'] ?? 'PROVIDER')],
                'correctResult' => $row['correct_result'] === null ? null : (int) $row['correct_result'] === 1,
                'correctExactScore' => $row['correct_exact_score'] === null ? null : (int) $row['correct_exact_score'] === 1,
                'brier' => $row['brier'] ?? null, 'logLoss' => $row['log_loss'] ?? null, 'absoluteGoalError' => $row['absolute_goal_error'] ?? null,
                'settledAt' => (string) ($row['settled_at'] ?? ''),
                'generatedAt' => (string) ($prediction['generated_at'] ?? ''),
            ];
        }
        return [
            'state' => $rows === [] ? PerformanceService::NO_DATA : 'MEASURED',
            'count' => count($rows),
            'rows' => $rows,
            'message' => $rows === [] ? PerformanceService::EMPTY_MESSAGE : null,
            'generatedAt' => gmdate('c'),
        ];
    }

    // ── write paths ───────────────────────────────────────────────────────────

    /**
     * Operator-triggered sync for a date. The request budget is deliberately
     * unbounded (-1) here — an explicit human action may walk the whole day —
     * while the minimum spacing between requests still applies, so even a
     * manual sweep cannot outrun the provider's rate limit.
     */
    public function syncDate(string $date, ?string $providerId = null): array
    {
        return $this->fixtures()->syncDay($date, 'manual:fixtures:' . $date . ':' . gmdate('Ymd\THis'), $providerId, -1);
    }

    public function syncLive(bool $force = true): array
    {
        return $this->fixtures()->syncLive(($force ? 'manual:live:' : 'live:') . gmdate('Ymd\THis'), null, $force ? -1 : null);
    }

    public function syncResults(int $limit = 120): array
    {
        return $this->fixtures()->syncResults('manual:results:' . gmdate('Ymd\THis'), null, $limit, -1);
    }

    /**
     * @param callable(object):array $fetch
     */
    public function syncWith(callable $fetch, string $jobType = 'FIXTURES', string $capability = 'fixtures', ?string $providerId = null): array
    {
        $date = gmdate('Y-m-d');
        return $this->fixtures()->sweep($jobType, $capability, $date, $date, 'manual:' . $jobType . ':' . gmdate('Ymd\THis'), $fetch, $providerId, -1);
    }

    public function collectStatisticsForDay(string $date, int $limit = 24): array
    {
        $fixtures = $this->repo->listFixtures(['date' => $date], max(1, min(200, $limit)));
        $errors = []; $leagues = []; $teams = 0; $h2h = 0; $requests = 0;
        foreach ($fixtures as $fixture) {
            $providerId = (int) ($fixture['provider_id'] ?? 0);
            $providerRow = null;
            foreach ($this->repo->listProviders() as $row) {
                if ((int) $row['id'] === $providerId) $providerRow = $row;
            }
            $providerCode = (string) ($providerRow['provider_code'] ?? '');
            if ($providerCode === '') continue;
            $league = (string) ($fixture['competition_external_id'] ?? '');
            $season = (string) ($fixture['season'] ?? $fixture['competition_season'] ?? '');
            if ($league !== '' && $season !== '') {
                $key = $providerCode . '|' . $league . '|' . $season;
                if (!isset($leagues[$key])) {
                    $result = $this->statistics()->collectLeagueStatistics($providerId, $providerCode, $league, $season);
                    $leagues[$key] = $result;
                    $teams += (int) ($result['teams'] ?? 0);
                    foreach ((array) ($result['errors'] ?? []) as $error) $errors[] = (string) $error;
                }
            }
            foreach (['home_team_id' => 'home_team', 'away_team_id' => 'away_team'] as $idKey => $nameKey) {
                $teamId = (string) ($fixture[$idKey] ?? '');
                if ($teamId === '' || $league === '' || $season === '') continue;
                $existing = $this->repo->findTeamStatistics($providerId, $teamId, $league, $season);
                if ($existing !== null && (string) ($existing['data_state'] ?? '') === DataState::AVAILABLE) continue;
                $fallback = $this->statistics()->collectTeamStatistics($providerId, $providerCode, $teamId, (string) ($fixture[$nameKey] ?? ''), $league, $season);
                if (($fallback['status'] ?? '') === 'COMPLETED') $teams++;
            }
            $headToHead = $this->statistics()->collectHeadToHead($providerId, $providerCode, $fixture);
            if (($headToHead['status'] ?? '') === 'COMPLETED') $h2h++;
        }
        $requests = $this->gateway()->requestsMade();
        return ['status' => $leagues === [] && $teams === 0 ? DataState::UNAVAILABLE : 'COMPLETED', 'date' => $date,
            'fixtures' => count($fixtures), 'leagues' => count($leagues), 'teamRows' => $teams, 'headToHead' => $h2h,
            'requests' => $requests, 'errors' => $errors, 'leagueResults' => $leagues];
    }

    public function settle(int $fixtureId): array
    {
        return $this->settlements()->settleFixture($fixtureId, 'manual:' . gmdate('Ymd\THi') . ':' . $fixtureId);
    }

    public function calibrate(?int $modelVersionId = null, string $actor = 'system'): array
    {
        if ($modelVersionId === null) {
            $usable = $this->models()->usable();
            $modelVersionId = (int) ($usable['model']['id'] ?? 0);
        }
        if ($modelVersionId <= 0) return ['status' => 'MODEL_NOT_LOADED', 'reason' => 'No model version is registered, so there is nothing to calibrate.'];
        return $this->calibration()->fit($modelVersionId, null, $actor);
    }

    public function approveModel(int $modelVersionId, string $actor, string $note = ''): array
    {
        return $this->models()->approve($modelVersionId, $actor, $note);
    }

    public function activateModel(int $modelVersionId, string $actor, string $note = ''): array
    {
        $result = $this->models()->activate($modelVersionId, $actor, $note);
        if (($result['status'] ?? '') === 'OK') {
            $report = $this->performance()->report(30, $modelVersionId);
            $this->performance()->snapshot(30, $modelVersionId);
            $result['report'] = $report;
        }
        return $result;
    }
}
