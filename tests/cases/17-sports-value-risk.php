<?php
use AIWorkforce\Sports\CorrelationEngine;
use AIWorkforce\Sports\RiskEngine;
use AIWorkforce\Sports\ValueEngine;

test('sports value engine separates model and market numbers', function () {
    $prediction = ['decision' => 'PREDICTION_READY', 'calibratedProbability' => .60];
    $v = (new ValueEngine())->assess($prediction, ['decimalOdds' => 1.5]);
    assert_false($v['qualified']); assert_equals('LOW_MODEL_EDGE', $v['reason']); assert_close(0.6666667, $v['impliedProbability'], .00001);
    // WINDELS numbers: model probability and fair odds are the model's own —
    // never copied from the bookmaker price.
    assert_equals(0.60, $v['modelProbability']);
    assert_close(1 / 0.60, $v['fairOdds'], .0001);
    assert_equals(1.5, $v['marketOdds']);
    assert_close(.60 - 1 / 1.5, $v['edge'], .00001);
    assert_close(.60 * 1.5 - 1, $v['expectedValue'], .00001);
});
test('sports value engine never invents market odds', function () {
    $v = (new ValueEngine())->assess(['decision' => 'PREDICTION_READY', 'calibratedProbability' => .60], ['decimalOdds' => 0]);
    assert_false($v['qualified']); assert_equals('ODDS_UNAVAILABLE', $v['reason']);
});
test('sports risk engine rejects low quality candidate with explicit reasons', function () {
    $risk = (new RiskEngine())->assess(['qualified' => true, 'expectedValue' => .1], ['score' => 50, 'eligibleForTicket' => false]);
    assert_false($risk['approved']); assert_equals('REJECTED', $risk['classification']);
    assert_contains('LOW_DATA_QUALITY', implode(',', $risk['reasons']));
});
test('sports risk engine no longer duplicates the confidence gate', function () {
    // The 70%+ confidence floor is gated ONCE by the pipeline (on the WINDELS
    // confidence value, in stage order) — the risk engine must not add a
    // second LOW_CONFIDENCE rejection for the same candidate.
    $value = ['qualified' => true, 'expectedValue' => 0.2, 'odds' => 2.0];
    $quality = ['score' => 100, 'eligibleForTicket' => true];
    $risk = (new RiskEngine())->assess($value, $quality, ['min_data_quality' => 75, 'min_confidence' => 80], ['confidence' => 50]);
    assert_equals('LOW', $risk['classification']);
    assert_not_contains('LOW_CONFIDENCE', implode(',', $risk['reasons']));
});
test('sports risk engine still rejects insufficient liquidity', function () {
    $value = ['qualified' => true, 'expectedValue' => 0.2, 'odds' => 2.0];
    $quality = ['score' => 100, 'eligibleForTicket' => true];
    $lowLiq = (new RiskEngine())->assess($value, $quality, ['min_data_quality' => 80, 'min_liquidity' => 10000], ['confidence' => 90, 'liquidity' => 500]);
    assert_equals('REJECTED', $lowLiq['classification']);
    assert_contains('INSUFFICIENT_LIQUIDITY', implode(',', $lowLiq['reasons']));
});
test('sports correlation blocks selections from same match', function () {
    $out = (new CorrelationEngine())->assess(['matchId' => 1, 'competition' => 'L'], [['matchId' => 1, 'competition' => 'L']]);
    assert_equals('HIGH', $out['classification']); assert_contains('SAME_MATCH', implode(',', $out['reasons']));
});
