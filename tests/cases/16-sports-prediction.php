<?php
use AIWorkforce\Sports\FeatureEngineeringEngine;
use AIWorkforce\Sports\PredictionEngine;

test('sports features require verified recent-form metrics', function () {
    $features = (new FeatureEngineeringEngine())->build(['decision' => 'INTELLIGENCE_READY', 'inputs' => ['recentForm' => ['source' => 'feed']]]);
    assert_false($features['ok']); assert_equals('INSUFFICIENT_DATA', $features['reason']);
});
test('sports prediction refuses uncalibrated models', function () {
    $features = ['ok' => true, 'version' => 'sports-features-v1', 'features' => ['expectedGoalsProxy' => 2.4], 'inputSources' => []];
    $prediction = (new PredictionEngine())->predictOver15($features, []);
    assert_equals('NO_PREDICTION', $prediction['decision']); assert_equals('MODEL_NOT_CALIBRATED', $prediction['reason']);
});
test('sports prediction keeps raw and calibrated probabilities separate', function () {
    $features = ['ok' => true, 'version' => 'sports-features-v1', 'features' => ['expectedGoalsProxy' => 2.4], 'inputSources' => ['recentForm' => 'provider-a']];
    $prediction = (new PredictionEngine())->predictOver15($features, ['approved' => true, 'intercept' => .02, 'slope' => .95, 'version' => 'cal-v1']);
    assert_equals('PREDICTION_READY', $prediction['decision']); assert_true($prediction['rawModelProbability'] !== $prediction['calibratedProbability']); assert_equals('cal-v1', $prediction['calibrationVersion']);
});

test('sports prediction supports only approved odds ticket markets from verified features', function () {
    $features = ['ok' => true, 'version' => 'sports-features-v1', 'features' => [
        'expectedGoalsProxy' => 2.6,
        'homeAttack' => 1.7,
        'awayAttack' => 1.5,
        'homeDefenseConceded' => 1.1,
        'awayDefenseConceded' => 1.2,
    ], 'inputSources' => ['recentForm' => 'provider-a']];
    $cal = ['approved' => true, 'intercept' => .02, 'slope' => .95, 'version' => 'cal-v1'];
    $engine = new PredictionEngine();
    assert_equals('PREDICTION_READY', $engine->predict('BTTS', 'YES', $features, $cal)['decision']);
    assert_equals('PREDICTION_READY', $engine->predict('DOUBLE_CHANCE', 'HOME_OR_DRAW', $features, $cal)['decision']);
    assert_equals('NO_PREDICTION', $engine->predict('CORRECT_SCORE', '1_0', $features, $cal)['decision']);
});
