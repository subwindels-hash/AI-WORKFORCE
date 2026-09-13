<?php
/**
 * Football Intelligence — configurable, real-data prediction cycles.
 *
 * Pins the operational promises that are easy to regress when the board is
 * changed: the default cycle never exceeds 50 matches, an operator can lower
 * the cycle, later cycles advance to unpredicted stored fixtures, and the
 * immutable prediction contract retains the individual expected-goal rates
 * and conservative A/B/C classification used for display.
 */
require_once TESTSPATH . 'football_support.php';

use AIWorkforce\Football\FootballConfiguration;
use AIWorkforce\Football\MatchFeed;
use AIWorkforce\Football\PredictionService;

test('football batch configuration: defaults to fifty, honours a lower admin-style override and caps invalid highs', function () {
    assert_equals(50, (new FootballConfiguration([]))->analysisBatchSize(), 'the default prediction cycle is 50 fixtures');
    assert_equals(20, (new FootballConfiguration(['WINDELS_FOOTBALL_ANALYSIS_BATCH_SIZE' => 20]))->analysisBatchSize(), 'a configured 20-match cycle is honoured');
    assert_equals(50, (new FootballConfiguration(['WINDELS_FOOTBALL_ANALYSIS_BATCH_SIZE' => 500]))->analysisBatchSize(), 'a cycle cannot be raised above fifty');
    assert_equals(10, (new FootballConfiguration(['WINDELS_FOOTBALL_ANALYSIS_LIMIT' => 10]))->analysisBatchSize(), 'the legacy deployment variable remains a supported fallback');
});

test('football prediction cycle: processes only its configured batch then advances through pending stored fixtures', function () {
    $day = gmdate('Y-m-d', time() + 2 * 86400);
    $start = (int) strtotime($day . 'T00:30:00+00:00');
    $fixtures = [];
    for ($i = 0; $i < 35; $i++) {
        $fixtures[] = fx_fb_row('fx-cycle-' . $i, gmdate('c', $start + $i * 60),
            'Manchester City', 'Everton', '10', '20');
    }
    [$repo, , $module] = fx_fb_harness($fixtures, [], ['WINDELS_FOOTBALL_ANALYSIS_BATCH_SIZE' => 20]);
    fx_fb_sync_today($module, $day);

    $first = $module->predictions()->predictDay($day);
    assert_equals(20, (int) $first['fixtures'], 'only twenty stored fixtures enter the first cycle');
    assert_equals(20, (int) $first['batchSize'], 'the result reports the active cycle ceiling');
    assert_true((int) $first['analyzed'] <= 20, 'no more than the configured cycle was analyzed');
    assert_equals(15, (int) $first['deferredFixtures'], 'the remaining stored fixtures are honestly deferred');

    $second = $module->predictions()->predictDay($day);
    assert_equals(15, (int) $second['fixtures'], 'the next cycle advances to fixtures without stored predictions');
    assert_true((int) $repo->countPredictions(['date' => $day, 'kind' => PredictionService::KIND_PRE_MATCH]) <= 35,
        'no duplicate prediction rows are created while advancing');
});

test('football output contract: preserves per-team expected goals and a conservative category from the stored prediction', function () {
    $day = gmdate('Y-m-d', time() + 2 * 86400);
    [$repo, , $module] = fx_fb_harness([
        fx_fb_row('fx-contract-goals', $day . 'T12:00:00+00:00', 'Manchester City', 'Everton', '10', '20'),
    ]);
    fx_fb_sync_today($module, $day);
    $module->predictions()->predictDay($day);

    $stored = $repo->listPredictions(['date' => $day, 'kind' => PredictionService::KIND_PRE_MATCH], 1)[0] ?? null;
    assert_not_null($stored, 'a supported stored provider fixture produced a prediction');
    $contract = $module->predictions()->contract($stored);
    $prediction = (array) ($contract['prediction'] ?? []);
    $goals = (array) ($prediction['expectedGoals'] ?? []);
    $category = (array) ($prediction['category'] ?? []);

    assert_true(is_numeric($goals['home'] ?? null), 'expected home goals are retained from the generation snapshot');
    assert_true(is_numeric($goals['away'] ?? null), 'expected away goals are retained from the generation snapshot');
    assert_true(isset($goals['method']) && (string) $goals['method'] !== '', 'the expected-goals basis is named');
    assert_true(in_array((string) ($contract['dataState'] ?? ''), ['AVAILABLE', 'LIMITED_DATA', 'DATA_UNAVAILABLE'], true),
        'the contract explicitly states fixture data availability rather than implying it from a prediction');
    assert_true(is_array($contract['alternativeScores'] ?? null), 'stored alternative scorelines are included in the contract when available');
    assert_true(array_key_exists('code', $category) && array_key_exists('label', $category), 'the displayed category is explicit');
    assert_true(in_array($category['code'], ['A', 'B', 'C', null], true), 'only A/B/C or an honest unrated state is possible');
    $snapshot = is_array($stored['feature_snapshot'] ?? null) ? $stored['feature_snapshot'] : json_decode((string) ($stored['feature_snapshot'] ?? '{}'), true);
    assert_true(is_array($snapshot['category'] ?? null), 'the category is captured with the immutable stored forecast');
    assert_equals($snapshot['category']['code'] ?? null, $category['code'] ?? null, 'the contract reads the category that was stored at generation time');

    $board = $module->board()->forDate($day, false, 1, MatchFeed::MAX_PAGE_SIZE);
    $row = (array) ($board['rows'][0] ?? []);
    assert_true(array_key_exists('expectedGoals', $row), 'the board exposes the per-team goal rates for each fixture');
    assert_true(array_key_exists('category', $row), 'the board exposes the conservative category for each fixture');
});

test('football scheduled prediction job: today and tomorrow share one configured fixture-evaluation cap', function () {
    $today = gmdate('Y-m-d', time() + 2 * 86400);
    $tomorrow = gmdate('Y-m-d', strtotime($today . ' +1 day'));
    $fixtures = [];
    for ($i = 0; $i < 15; $i++) {
        $fixtures[] = fx_fb_row('fx-cron-today-' . $i, $today . 'T' . sprintf('%02d', 1 + $i) . ':00:00+00:00',
            'Manchester City', 'Everton', '10', '20');
        $fixtures[] = fx_fb_row('fx-cron-tomorrow-' . $i, $tomorrow . 'T' . sprintf('%02d', 1 + $i) . ':00:00+00:00',
            'Manchester City', 'Everton', '10', '20');
    }
    [, , $module] = fx_fb_harness($fixtures, [], ['WINDELS_FOOTBALL_ANALYSIS_BATCH_SIZE' => 20]);
    fx_fb_sync_today($module, $today);
    fx_fb_sync_today($module, $tomorrow);

    $run = $module->cron()->run('predict', $today, true);
    assert_equals(20, (int) $run['batchSize'], 'the cron job reports its configured shared cap');
    assert_true((int) $run['processed'] <= 20,
        'today plus tomorrow cannot evaluate more than the configured match count in one scheduled cycle');
    assert_equals(0, (int) $run['remainingAfterToday'],
        'after twenty evaluated fixtures from the busy current date, tomorrow is honestly deferred to a later cycle');
});
