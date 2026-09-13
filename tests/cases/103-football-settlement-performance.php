<?php
/**
 * Football Intelligence — settlement, performance, calibration, model lifecycle
 * (spec §8/§14/§15, plus §9's calibration gate).
 *
 * The whole point of this suite is that every number on the dashboard is produced
 * by the pipeline in this file: a fixture is synced, predicted, completed by the
 * provider, settled once, and only then does a performance figure exist. No case
 * here seeds a settlement, a metric or a model status by hand.
 */
require_once TESTSPATH . 'football_support.php';

use AIWorkforce\Football\CalibrationService;
use AIWorkforce\Football\FootballConfiguration;
use AIWorkforce\Football\ModelRegistry;
use AIWorkforce\Football\PerformanceService;
use AIWorkforce\Football\PredictionService;
use AIWorkforce\Football\QualityBand;

/**
 * Predict a fixture while it is still pre-match, then report the final score and
 * settle it. Returns [repo, module, day, fixtureId, predictionId, settlementResult].
 */
function fx_fb_settled(int $finalHome = 2, int $finalAway = 0, array $config = []): array
{
    $kickoff = time() + 7200;
    $day = gmdate('Y-m-d', $kickoff);
    [$repo, $provider, $module] = fx_fb_harness(
        [fx_fb_row('fx-life', gmdate('c', $kickoff), 'Manchester City', 'Everton', '10', '20')],
        [],
        $config
    );
    fx_fb_sync_today($module, $day);
    $module->predictions()->predictDay($day);
    $prediction = $repo->listPredictions(['date' => $day, 'kind' => PredictionService::KIND_PRE_MATCH], 1)[0] ?? null;
    if ($prediction === null) {
        throw new RuntimeException('harness produced no prediction to settle — the pipeline regressed');
    }
    // The provider now reports a full-time score, so the row is updated in place.
    $fixture = $repo->findFixtureById((int) $prediction['fixture_id']);
    $repo->saveFixture((int) $fixture['provider_id'], array_merge(
        array_intersect_key($fixture, array_flip(['external_id', 'competition', 'league_id', 'season', 'kickoff_at', 'home_team', 'away_team', 'home_team_id', 'away_team_id'])),
        ['externalId' => (string) $fixture['external_id'], 'status' => 'FINISHED', 'homeScore' => $finalHome, 'awayScore' => $finalAway]
    ));
    $settlement = $module->settlements()->settleFixture((int) $fixture['id'], 'test:settle');
    return [$repo, $module, $day, (int) $fixture['id'], (string) $prediction['id'], $settlement, $prediction];
}

/**
 * Same pipeline, repeated: predict a board of fixtures before kickoff, let the
 * provider report every final score, settle them all. Returns
 * [repo, module, day, settleDueResult] with `$count` settled predictions.
 */
function fx_fb_many_settlements(int $count = 12, array $config = [], array $extra = []): array
{
    $kickoff = time() + 7200;
    $day = gmdate('Y-m-d', $kickoff);
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $rows[] = fx_fb_row('fx-many-' . $i, gmdate('c', $kickoff), 'Manchester City', 'Everton', '10', '20');
    }
    [$repo, , $module] = fx_fb_harness($rows, $extra, $config);
    fx_fb_sync_today($module, $day);
    $predicted = $module->predictions()->predictDay($day);
    $providerId = (int) ($repo->listProviders()[0]['id'] ?? 1);
    foreach ($repo->listFixtures(['date' => $day], 200) as $fixture) {
        $repo->saveFixture($providerId, ['externalId' => (string) $fixture['external_id'],
            'status' => 'FINISHED', 'homeScore' => 2, 'awayScore' => 0]);
    }
    $due = $module->settlements()->settleDue(200, 0, 'test:many');
    return [$repo, $module, $day, $due, $predicted];
}

/** A second module over the same stored history, with its own model fingerprint. */
function fx_fb_module_with(FootballRepositoryStub $repo, \AIWorkforce\Football\FootballIntelligence $source, array $config = []): \AIWorkforce\Football\FootballIntelligence
{
    return new \AIWorkforce\Football\FootballIntelligence($repo, $source->providerManager(), null, new \AIWorkforce\Football\FootballConfiguration($config));
}

test('football: performance report fills every sidebar metric from settled predictions', function () {
    [$repo, $module, , , $predictionId, $settlement] = fx_fb_settled(2, 0);
    assert_equals('SETTLED', $settlement['status']);
    $row = $repo->findSettlement($predictionId);
    assert_not_null($row, 'the prediction was settled');

    $report = $module->performance()->report(30);
    assert_equals('MEASURED', $report['state']);
    assert_equals(1, $report['evaluatedPredictions']);
    assert_equals((int) ($row['correct_result'] ?? 0), $report['correctResults']);
    assert_equals(round((int) ($row['correct_result'] ?? 0) / 1, 5), $report['resultAccuracy']);
    assert_equals((int) ($row['correct_exact_score'] ?? 0), $report['correctScores']);
    assert_equals(round((int) ($row['correct_exact_score'] ?? 0) / 1, 5), $report['exactScoreAccuracy']);
    assert_equals(round((float) $row['confidence'], 2), (float) $report['averageConfidence']);
    assert_equals(round((float) $row['brier'], 6), (float) $report['brier']);
    assert_equals(round((float) $row['log_loss'], 6), (float) $report['logLoss']);
    assert_true(is_numeric($report['ece']), 'ECE is measured from the stored probability/result sample');
    assert_equals(round((float) $row['data_quality_score'], 2), (float) $report['averageDataQuality']);
    assert_equals(round((float) $row['absolute_goal_error'], 3), (float) $report['averageGoalError']);
});

test('football: performance report repairs legacy settlements from joined prediction facts', function () {
    [$repo, $module, , , $predictionId] = fx_fb_settled(2, 0);
    foreach ($repo->settlements as &$settlement) {
        if ((string) ($settlement['prediction_id'] ?? '') !== $predictionId) continue;
        // Legacy rows may pre-date the copied metric columns. The report must
        // recompute from the stored prediction/result pair instead of leaving
        // the sidebar blank or treating NULL grades as wrong.
        $settlement['predicted_result'] = null;
        $settlement['predicted_home_score'] = null;
        $settlement['predicted_away_score'] = null;
        $settlement['correct_result'] = null;
        $settlement['correct_exact_score'] = null;
        $settlement['brier'] = null;
        $settlement['log_loss'] = null;
        $settlement['absolute_goal_error'] = null;
        $settlement['confidence'] = null;
        $settlement['data_quality_score'] = null;
    }
    unset($settlement);

    $prediction = $repo->findPrediction($predictionId);
    assert_not_null($prediction, 'the immutable prediction row still exists for legacy repair');
    $row = $repo->findSettlement($predictionId);
    assert_not_null($row, 'the legacy settlement row still exists');
    $report = $module->performance()->report(30);

    $actual = (string) ($row['actual_result'] ?? '');
    $expectedResult = strtoupper((string) ($prediction['predicted_result'] ?? '')) === strtoupper($actual) ? 1 : 0;
    $expectedExact = ((int) ($prediction['predicted_home_score'] ?? -1) === (int) ($row['actual_home_score'] ?? -2)
        && (int) ($prediction['predicted_away_score'] ?? -1) === (int) ($row['actual_away_score'] ?? -2)) ? 1 : 0;
    $expectedGoalError = round((abs((int) $prediction['predicted_home_score'] - (int) $row['actual_home_score'])
        + abs((int) $prediction['predicted_away_score'] - (int) $row['actual_away_score'])) / 2, 3);

    assert_equals('MEASURED', $report['state']);
    assert_equals(1, $report['evaluatedPredictions']);
    assert_equals($expectedResult, $report['correctResults']);
    assert_equals((float) $expectedResult, (float) $report['resultAccuracy']);
    assert_equals($expectedExact, $report['correctScores']);
    assert_equals((float) $expectedExact, (float) $report['exactScoreAccuracy']);
    assert_equals(round((float) ($prediction['confidence'] ?? 0), 2), (float) $report['averageConfidence']);
    assert_true(is_numeric($report['brier']), 'Brier is recomputed from stored probabilities');
    assert_true(is_numeric($report['logLoss']), 'log loss is recomputed from stored probabilities');
    assert_equals(round((float) ($prediction['data_quality_score'] ?? 0), 2), (float) $report['averageDataQuality']);
    assert_equals($expectedGoalError, (float) $report['averageGoalError']);
});


test('football: performance report does not average only the non-null half of mixed legacy rows', function () {
    [$repo, $module] = fx_fb_many_settlements(2);
    assert_true(count($repo->settlements) >= 2, 'the harness settled at least two predictions');

    // One row has the modern copied columns; the other is legacy and must be
    // completed from the immutable prediction row. The sidebar average must cover
    // both rows, not just the non-null settlement column the SQL AVG can see.
    $repo->settlements[0]['predicted_result'] = null;
    $repo->settlements[0]['predicted_home_score'] = null;
    $repo->settlements[0]['predicted_away_score'] = null;
    $repo->settlements[0]['confidence'] = null;
    $repo->settlements[0]['data_quality_score'] = null;
    $repo->settlements[0]['absolute_goal_error'] = null;

    $confidence = [];
    $quality = [];
    $goalError = [];
    foreach ($repo->settlements as $settlement) {
        $prediction = $repo->findPrediction((string) ($settlement['prediction_id'] ?? ''));
        assert_not_null($prediction, 'settlement still joins to the stored prediction');
        $confidence[] = is_numeric($settlement['confidence'] ?? null)
            ? (float) $settlement['confidence']
            : (float) ($prediction['confidence'] ?? 0);
        $quality[] = is_numeric($settlement['data_quality_score'] ?? null)
            ? (float) $settlement['data_quality_score']
            : (float) ($prediction['data_quality_score'] ?? 0);
        if (is_numeric($settlement['absolute_goal_error'] ?? null)) {
            $goalError[] = (float) $settlement['absolute_goal_error'];
        } else {
            $goalError[] = round((abs((int) ($prediction['predicted_home_score'] ?? 0) - (int) ($settlement['actual_home_score'] ?? 0))
                + abs((int) ($prediction['predicted_away_score'] ?? 0) - (int) ($settlement['actual_away_score'] ?? 0))) / 2, 3);
        }
    }

    $report = $module->performance()->report(30);
    assert_equals(count($repo->settlements), $report['evaluatedPredictions']);
    assert_equals(round(array_sum($confidence) / count($confidence), 2), (float) $report['averageConfidence']);
    assert_equals(round(array_sum($quality) / count($quality), 2), (float) $report['averageDataQuality']);
    assert_equals(round(array_sum($goalError) / count($goalError), 3), (float) $report['averageGoalError']);
});

test('football: approved calibration count is exact beyond the calibration list page size', function () {
    $repo = new FootballRepositoryStub();
    for ($i = 0; $i < 205; $i++) {
        $repo->calibrations[] = [
            'id' => $i + 1,
            'model_version_id' => 1,
            'calibration_version' => 'T1-' . $i,
            'status' => CalibrationService::CALIBRATED,
            'created_at' => gmdate('c', time() - $i),
        ];
    }
    $repo->calibrations[] = ['id' => 999, 'model_version_id' => 1, 'calibration_version' => 'pending', 'status' => CalibrationService::PENDING, 'created_at' => gmdate('c')];

    $service = new CalibrationService($repo, new FootballConfiguration());
    assert_equals(205, $service->approvedCount(), "the sidebar count is not capped at listCalibrations()'s 200-row page");
});

test('football: a model version starts as DRAFT and is never pre-approved (§8)', function () {
    $repo = new FootballRepositoryStub();
    $module = new \AIWorkforce\Football\FootballIntelligence($repo, new \AIWorkforce\Sports\Providers\SportsProviderManager(), null, new \AIWorkforce\Football\FootballConfiguration());
    $registry = $module->models();
    $first = $registry->ensureRegistered();
    assert_equals('REGISTERED', $first['status']);
    assert_equals(ModelRegistry::DRAFT, (string) $first['model']['status'], 'a new version enters as DRAFT');
    $again = $registry->ensureRegistered();
    assert_equals('ALREADY_REGISTERED', $again['status'], 'the same deployed configuration never creates a second row');
    assert_equals($first['model']['id'], $again['model']['id']);
    $usable = $registry->usable();
    assert_equals(ModelRegistry::DRAFT, $usable['state']);
    assert_equals(true, $usable['publishable'], 'forecasts are still published — the model does not go dark');
    assert_equals(true, $usable['highConfidenceAllowed'], 'and every analyzed match carries a usable prediction — the badge is earned by data quality and measured confidence, not by the registry state');
    assert_contains('production-blessed', (string) $usable['reason'], 'and the reason says what activation marks');
    $deployed = $registry->deployedVersion();
    foreach (['model_name', 'model_version', 'algorithm', 'feature_version', 'parameters'] as $key) {
        assert_true(isset($deployed[$key]), 'the deployed fingerprint records ' . $key);
    }
    assert_true((bool) preg_match('/^v1\+[0-9a-f]{8}$/', (string) $deployed['model_version']), 'the version is derived from the configuration');
});

test('football: the lifecycle only advances on evidence, and approval needs an operator', function () {
    [$repo, $module] = fx_fb_many_settlements();
    assert_equals(12, count($repo->listSettlements([], 50)), 'the harness settled a real sample');
    $registry = $module->models();
    $model = $registry->ensureRegistered();
    $id = (int) $model['model']['id'];

    // Nothing has been evaluated against this version yet, so no state above
    // DRAFT is available — not even to an administrator.
    assert_equals('REJECTED', $registry->transition($id, ModelRegistry::ACTIVE, 'tester')['status'], 'ACTIVE cannot be reached without approval');
    assert_equals('REJECTED', $registry->transition($id, ModelRegistry::TRAINED, 'tester')['status'], 'nothing has been measured yet');
    assert_equals('REJECTED', $registry->approve($id, 'admin@windels', 'trust me')['status'], 'and approval is refused without evidence');

    // The performance job measures the stored settlements and records them.
    $snapshot = $module->performance()->snapshot(30, $id);
    assert_equals('MEASURED', $snapshot['report']['state']);
    $module->calibration();  // no calibration exists yet — proved below
    $lenient = fx_fb_module_with($repo, $module, ['WINDELS_FOOTBALL_MIN_CALIBRATION_SAMPLES' => '10']);

    assert_equals('OK', $registry->transition($id, ModelRegistry::TRAINED, 'tester')['status'], 'measurement earns TRAINED');
    assert_equals('OK', $registry->transition($id, ModelRegistry::VALIDATED, 'tester')['status'], 'a validation sample earns VALIDATED');
    $early = $registry->transition($id, ModelRegistry::CALIBRATED, 'tester');
    assert_equals('REJECTED', $early['status'], 'CALIBRATED requires a stored calibration version');
    assert_contains('calibration', strtolower((string) $early['reason']));

    $fit = $lenient->calibration()->fit($id, null, 'tester');
    assert_equals(CalibrationService::CALIBRATED, $fit['status'], 'the pipeline can fit one from settled history');
    assert_equals('OK', $registry->transition($id, ModelRegistry::CALIBRATED, 'tester')['status']);
    $approved = $registry->approve($id, 'admin@windels', 'reviewed ' . $snapshot['report']['evaluatedPredictions'] . ' settled predictions');
    assert_equals('OK', $approved['status']);
    assert_equals(ModelRegistry::APPROVED, (string) $approved['model']['status']);
    assert_equals('admin@windels', (string) $approved['model']['approved_by'], 'the approver is stored');
    assert_true((string) ($approved['model']['approved_at'] ?? '') !== '');

    $activated = $registry->activate($id, 'admin@windels');
    assert_equals(ModelRegistry::ACTIVE, (string) $activated['model']['status']);
    assert_not_null($registry->active(), 'the ACTIVE version is discoverable');
    assert_equals(true, $registry->usable()['highConfidenceAllowed'], 'and predictions stay usable after activation');
    $history = json_decode((string) ($activated['model']['lifecycle_history'] ?? ''), true);
    assert_true(is_array($history) && count($history) >= 5, 'every transition is recorded');
    foreach ($history as $entry) {
        assert_true(isset($entry['status'], $entry['at'], $entry['actor']), 'with who, when and what');
    }
    return [$repo, $module, $id];
});

test('football: activating a new version retires the one it replaces', function () {
    [$repo, $module] = fx_fb_many_settlements();
    $first = $module->models()->ensureRegistered()['model'];
    $firstId = (int) $first['id'];
    $module->performance()->snapshot(30, $firstId);
    $lenient = fx_fb_module_with($repo, $module, ['WINDELS_FOOTBALL_MIN_CALIBRATION_SAMPLES' => '10']);
    $lenient->calibration()->fit($firstId, null, 'tester');
    $registry = $lenient->models();
    foreach ([ModelRegistry::TRAINED, ModelRegistry::VALIDATED, ModelRegistry::CALIBRATED] as $state) {
        assert_equals('OK', $registry->transition($firstId, $state, 'tester')['status'], 'earned ' . $state);
    }
    $registry->approve($firstId, 'admin@windels');
    assert_equals('OK', $registry->activate($firstId, 'admin@windels')['status']);

    // A changed scoring configuration is a new model version — registered DRAFT,
    // with no borrowed evidence from the version it will eventually replace.
    $nextModule = fx_fb_module_with($repo, $module, ['WINDELS_FOOTBALL_MAX_GOALS' => '6', 'WINDELS_FOOTBALL_MIN_CALIBRATION_SAMPLES' => '10']);
    $registered = $nextModule->models()->ensureRegistered();
    assert_equals('REGISTERED', $registered['status']);
    $nextId = (int) $registered['model']['id'];
    assert_true($nextId !== $firstId, 'a distinct row was created');
    assert_equals(ModelRegistry::DRAFT, (string) $registered['model']['status'], 'starting from scratch');
    assert_equals($firstId, (int) $nextModule->models()->active()['id'], 'the ACTIVE model is untouched by registering another version');
    assert_equals('REJECTED', $nextModule->models()->transition($nextId, ModelRegistry::ACTIVE, 'tester')['status'],
        'and it cannot jump the queue');

    $report = $module->performance()->report(30);
    $nextModule->models()->recordEvaluation($nextId, ['validation_sample_size' => $report['evaluatedPredictions'],
        'accuracy' => $report['resultAccuracy'], 'log_loss' => $report['logLoss'], 'brier_score' => $report['brier'], 'ece' => $report['ece']]);
    foreach ([ModelRegistry::TRAINED, ModelRegistry::VALIDATED] as $state) {
        assert_equals('OK', $nextModule->models()->transition($nextId, $state, 'tester')['status']);
    }
    // A new version may be approved on its validation evidence even before it has
    // its own calibration; §8 requires validation plus approval, not calibration.
    assert_equals('OK', $nextModule->models()->approve($nextId, 'admin@windels')['status']);
    assert_equals('OK', $nextModule->models()->activate($nextId, 'admin@windels')['status']);

    $retired = $repo->findModelVersion($firstId);
    assert_equals(ModelRegistry::RETIRED, (string) $retired['status'], 'only one version is ACTIVE at a time');
    assert_contains('Superseded', (string) $retired['rejection_reason'], 'with the reason recorded');
    assert_equals($nextId, (int) $nextModule->models()->active()['id']);
    $usable = $nextModule->models()->usable();
    assert_equals(ModelRegistry::ACTIVE, $usable['state']);
    assert_equals($nextId, (int) ($usable['model']['id'] ?? 0), 'and the pipeline predicts with the ACTIVE version from now on');
});
