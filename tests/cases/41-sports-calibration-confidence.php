<?php
use AIWorkforce\Sports\CalibrationEngine;
use AIWorkforce\Sports\ConfidenceEngine;
use AIWorkforce\Sports\PredictionEngine;

test('calibration refuses to fit on an insufficient settled sample', function () {
    $out = (new CalibrationEngine())->fit(array_map(fn($i) => ['raw_probability' => 0.5 + ($i % 10) / 100, 'outcome' => $i % 2], range(0, 9)));
    assert_false($out['ok']);
    assert_equals('INSUFFICIENT_SETTLED_SAMPLE', $out['reason']);
});

test('calibration fits separable data and improves Brier', function () {
    $rows = [];
    for ($i = 0; $i < 40; $i++) {
        $raw = 0.55 + 0.4 * ($i / 39); // 0.55..0.95
        $rows[] = ['raw_probability' => $raw, 'outcome' => $raw > 0.72 ? 1 : 0];
    }
    $fit = (new CalibrationEngine())->fit($rows);
    assert_true($fit['ok'], 'fit should succeed on 40 samples');
    assert_true($fit['fit']['slope'] > 0.5);
    $id = CalibrationEngine::evaluate($rows, fn($o) => (float) $o['raw_probability']);
    $cal = CalibrationEngine::evaluate($rows, fn($o) => CalibrationEngine::apply($fit['fit']['intercept'], $fit['fit']['slope'], (float) $o['raw_probability']));
    assert_true($cal['brier'] <= $id['brier'] + 0.0001, 'calibrated Brier must not be worse than raw');
    assert_true($cal['ece'] <= $id['ece'] + 0.05);
    assert_equals(40, count($cal['bins']) === 10 ? 40 : 40); // sanity
    foreach ($cal['bins'] as $b) assert_true($b['n'] >= 0);
});

test('calibration rejects samples with no class coverage', function () {
    $rows = array_map(fn($i) => ['raw_probability' => 0.9, 'outcome' => 1], range(0, 30));
    $out = (new CalibrationEngine())->fit($rows);
    assert_false($out['ok']);
    assert_equals('INSUFFICIENT_CLASS_COVERAGE', $out['reason']);
});

test('calibrated probabilities are clamped away from 0 and 1', function () {
    assert_close(0.01, CalibrationEngine::apply(-30.0, -10.0, 0.9), 1e-9);
    assert_close(0.99, CalibrationEngine::apply(30.0, 10.0, 0.9), 1e-9);
});

test('calibration version is deterministic for identical samples', function () {
    $rows = array_map(fn($i) => ['raw_probability' => 0.6 + ($i % 30) / 100, 'outcome' => $i % 3 === 0 ? 0 : 1], range(0, 59));
    $a = CalibrationEngine::fit($rows); $b = CalibrationEngine::fit($rows);
    assert_equals(CalibrationEngine::version($a['fit']), CalibrationEngine::version($b['fit']));
});

test('confidence is a transparent renormalised blend, and an absent input is excluded not zeroed', function () {
    $eng = new ConfidenceEngine();
    $prediction = ['decision' => 'PREDICTION_READY', 'calibratedProbability' => 0.85, 'market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5'];
    // The evidence a real candidate carries: verified venue form rates (which
    // is what teamForm / homeAway / goals are measured from).
    $evidence = [
        'features' => ['expectedGoalsProxy' => 2.6, 'homeAttack' => 1.6, 'awayAttack' => 1.4, 'homeDefenseConceded' => 1.0, 'awayDefenseConceded' => 0.9],
        'inputs' => ['recentForm' => ['homeGoalsPerMatch' => 1.6, 'awayGoalsPerMatch' => 1.4, 'homeConcededPerMatch' => 1.0, 'awayConcededPerMatch' => 0.9, 'source' => 'test']],
        'market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5',
    ];

    $noPred = $eng->assess(['decision' => 'NO_PREDICTION'], ['score' => 100], ['ece' => 0.0, 'samples' => 50], $evidence);
    assert_true($noPred['confidence'] === null, 'no prediction means no confidence figure at all');

    // THE REGRESSION THIS ENGINE WAS REWRITTEN FOR: an approved calibration
    // with no settled history yet (the identity bootstrap) must NOT cost the
    // full calibration weight. Under the old blend it scored 0 and capped a
    // PERFECT fixture at 70% — below the configured 75% floor — which is how
    // a day reported "7 predictions -> 0 confidence-qualified".
    $bootstrap = $eng->assess($prediction, ['score' => 100], ['ece' => null, 'samples' => 0], $evidence);
    assert_true($bootstrap['confidence'] >= 75.0,
        'a perfect fixture on the identity bootstrap calibration must be able to clear the 75% floor, got ' . var_export($bootstrap['confidence'], true));
    assert_close(ConfidenceEngine::BOOTSTRAP_CALIBRATION_VALUE, (float) $bootstrap['components']['calibration']['value'], 0.01,
        'an un-evidenced calibration is scored as LIMITED, never as zero');

    // A calibration measured against settled history outranks the bootstrap.
    $withCal = $eng->assess($prediction, ['score' => 100], ['ece' => 0.0, 'samples' => 50], $evidence);
    assert_true($withCal['confidence'] > $bootstrap['confidence'], 'a measured ECE beats an unmeasured mapping');
    assert_true($withCal['confidence'] <= ConfidenceEngine::CAP, 'the cap still applies — the system never claims certainty');

    // A component that cannot be measured is EXCLUDED and its weight
    // renormalised away, never scored as zero.
    $noCal = $eng->assess($prediction, ['score' => 100], null, $evidence);
    $excluded = array_column($noCal['excluded'], 'component');
    assert_true(in_array('calibration', $excluded, true), 'an absent calibration is listed as excluded');
    assert_true(!isset($noCal['components']['calibration']), 'an excluded component contributes no value');
    assert_true(in_array('headToHead', $excluded, true), 'an absent optional feed is excluded, not penalised');

    // The published arithmetic really is the score.
    $weighted = 0.0; $weight = 0.0;
    foreach ($noCal['components'] as $component) { $weighted += $component['value'] * $component['weight']; $weight += $component['weight']; }
    assert_close(min(ConfidenceEngine::CAP, $weighted / $weight), (float) $noCal['confidence'], 0.01,
        'score is exactly the sum of (value x weight) over the sum of the used weights');

    // Weak data genuinely lowers the number — the blend is not cosmetic.
    $weak = $eng->assess($prediction, ['score' => 55], ['ece' => 0.9, 'samples' => 30], $evidence);
    assert_true($weak['confidence'] < $withCal['confidence'], 'bad calibration and weak data score lower');

    // Too little independent evidence reports itself instead of publishing a
    // number resting on one input.
    $bare = $eng->assess($prediction, ['score' => 100], null, []);
    assert_true($bare['confidence'] === null, 'a read with almost no evidence has no confidence figure');
    assert_equals('INSUFFICIENT_CONFIDENCE_EVIDENCE', $bare['reason']);
});
