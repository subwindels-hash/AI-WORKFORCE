<?php
/**
 * Football Intelligence — backtesting on stored historical matches (spec §11).
 *
 * A backtest re-scores finished matches the engine can see: it rebuilds the
 * features, runs the exact prediction path a live match goes through with
 * persist=false, and grades the result against the stored final score. The
 * prediction ledger must stay untouched — a backtest is a question, not a
 * record — and the report carries its own caveat that the statistics being
 * re-used may include data recorded after the matches.
 */
require_once TESTSPATH . 'football_support.php';

use AIWorkforce\Football\BacktestService;
use AIWorkforce\Football\PredictionService;

/** Two finished fixtures on a day twelve days in the past, plus the module. */
function fx_fb_backtest_day(int $daysAgo = 12): array
{
    $kickoff = time() - $daysAgo * 86400;
    $day = gmdate('Y-m-d', $kickoff);
    [$repo, $provider, $intel, $audit] = fx_fb_harness([
        fx_fb_row('fx-bt-1', gmdate('c', $kickoff), 'Manchester City', 'Everton', '10', '20', 'FINISHED', 2, 0),
        fx_fb_row('fx-bt-2', gmdate('c', $kickoff + 3600), 'Brighton', 'Burnley', '30', '40', 'FINISHED', 1, 1),
    ]);
    $intel->fixtures()->syncDay($day, 'test:backtest:' . $day, null, -1);
    return [$repo, $intel, $day];
}

test('football: a backtest over an empty window is NO_DATA, never an invented run', function () {
    [, , $intel] = fx_fb_harness([], ['skipHistory' => true]);
    $from = gmdate('Y-m-d', time() - 30 * 86400);
    $to = gmdate('Y-m-d', time() - 8 * 86400);
    $report = $intel->backtest($from, $to);
    assert_equals('NO_DATA', $report['state']);
    assert_equals(0, $report['evaluated']);
    assert_null($report['resultAccuracy'], 'accuracy is null, not 0/0, when nothing was evaluated');
    assert_contains('refuses', (string) $report['message'], 'the empty window says what it refuses');
});

test('football: finished fixtures without a stored final score are not playable', function () {
    $kickoff = time() - 10 * 86400;
    $day = gmdate('Y-m-d', $kickoff);
    [, , $intel] = fx_fb_harness([
        fx_fb_row('fx-bt-noscore', gmdate('c', $kickoff), 'Manchester City', 'Everton', '10', '20', 'FINISHED'),
    ], ['skipHistory' => true]);
    $intel->fixtures()->syncDay($day, 'test:backtest-noscore', null, -1);
    $report = $intel->backtest($day, $day);
    assert_equals('NO_DATA', $report['state'], 'a scoreless finished match cannot be graded and is not graded');
});

test('football: the backtest re-scores the window and writes nothing to the prediction ledger', function () {
    [$repo, $intel, $day] = fx_fb_backtest_day();
    $from = gmdate('Y-m-d', time() - 14 * 86400);
    $to = gmdate('Y-m-d', time() - 10 * 86400);
    $report = $intel->backtest($from, $to);

    assert_equals('MEASURED', $report['state']);
    assert_equals($from, $report['from']);
    assert_equals($to, $report['to']);
    assert_equals(2, $report['evaluated'], 'both finished, scored fixtures in the window are evaluated');
    assert_equals(0, $report['skipped'], 'every playable fixture is graded, nothing is skipped');
    assert_equals([], $report['skippedReasons'], 'no skip reason was recorded');

    $byCategory = $report['byCategory'];
    assert_equals(
        $report['evaluated'],
        (int) $byCategory['A']['evaluated'] + (int) $byCategory['B']['evaluated'] + (int) $byCategory['C']['evaluated'],
        'every evaluated match is bucketed by category'
    );
    foreach (['A', 'B', 'C'] as $key) {
        if ($byCategory[$key]['evaluated'] > 0) {
            assert_true($byCategory[$key]['correct'] <= $byCategory[$key]['evaluated'], "category {$key}: correct never exceeds evaluated");
            assert_true($byCategory[$key]['correctScores'] <= $byCategory[$key]['evaluated'], "category {$key}: exact scores never exceed evaluated");
        }
    }

    assert_true($report['correctResults'] <= $report['evaluated'], 'correct results are bounded by evaluated');
    assert_close($report['correctResults'] / $report['evaluated'], (float) $report['resultAccuracy'], 0.0001, 'result accuracy follows from the counts');
    assert_close($report['correctScores'] / $report['evaluated'], (float) $report['exactScoreAccuracy'], 0.0001, 'exact-score accuracy follows from the counts');
    assert_true(is_numeric($report['brier']) && $report['brier'] >= 0.0 && $report['brier'] <= 2.0, 'Brier score is measured and in range');
    assert_contains('directional', $report['caveat'], 'the report is labelled a directional validation, not out-of-sample');

    // The whole point: re-scoring history must not pollute the record.
    assert_equals(0, count($repo->listPredictions([], 500)), 'the backtest wrote no prediction rows');
    assert_equals(0, count($repo->listSettlements([], 500)), 'the backtest wrote no settlement rows');
    assert_equals(0, count($repo->listCalibrationSamples([])), '…and no calibration samples');
});

test('football: the backtest window is normalised before it runs', function () {
    [, $intel, $day] = fx_fb_backtest_day(12);
    // Reversed bounds: the service swaps them instead of returning an empty run.
    $report = $intel->backtest($day, gmdate('Y-m-d', strtotime($day . ' -3 days')));
    assert_equals('MEASURED', $report['state'], 'a reversed window is normalised, not treated as empty');
    assert_true($report['from'] <= $report['to'], 'the echoed window is ordered');
    assert_equals(2, $report['evaluated']);
});
