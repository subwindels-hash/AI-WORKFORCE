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

test('football generate-on-read: the console read generates by default and an operator can switch it off', function () {
    assert_true((new FootballConfiguration([]))->generateOnRead(), 'reading the console generates the page\'s missing predictions by default');
    assert_true((new FootballConfiguration(['WINDELS_FOOTBALL_GENERATE_ON_READ' => 'true']))->generateOnRead(), 'an explicit true is honoured');
    assert_false((new FootballConfiguration(['WINDELS_FOOTBALL_GENERATE_ON_READ' => 'false']))->generateOnRead(), 'an operator can restore the fully read-only console');
    assert_false((new FootballConfiguration(['WINDELS_FOOTBALL_GENERATE_ON_READ' => '0']))->generateOnRead(), 'spelled as a flag, 0 is still off');

    // The controller wires the same rule: absent parameter takes the
    // configured default, an explicit value always wins, and ?refresh=0 is
    // the one-read opt-out. Source-level, the way the other console-contract
    // cases pin controller behaviour.
    $controller = (string) file_get_contents(dirname(TESTSPATH) . '/application/controllers/Football.php');
    assert_contains('generateOnRead()', $controller, 'the console default comes from the configuration, not a hard-coded switch');
    assert_contains("in_array(strtolower(\$refreshExplicit), ['1', 'true', 'yes', 'on'], true)", $controller,
        'an explicit refresh value is read as a flag');
    assert_contains('array_key_exists(\'refresh\', $get)', $controller, 'an absent parameter is told apart from refresh=0');
});

test('football generate-on-read: the board page block reports what the read evaluated for the page in view', function () {
    $day = gmdate('Y-m-d', time() + 2 * 86400);
    $start = (int) strtotime($day . 'T00:30:00+00:00');
    $rows = [
        fx_fb_row('fx-gr-1', gmdate('c', $start), 'Manchester City', 'Everton', '10', '20'),
        fx_fb_row('fx-gr-2', gmdate('c', $start + 60), 'Brighton', 'Burnley', '30', '40'),
        // A fixture no pre-match prediction may ever be written for.
        fx_fb_row('fx-gr-frozen', gmdate('c', $start + 120), 'Manchester City', 'Everton', '10', '20', 'POSTPONED'),
    ];
    [$repo, , $module] = fx_fb_harness($rows);
    fx_fb_sync_today($module, $day);
    // A thin fixture the quality gate must refuse: unknown teams, no stored
    // statistics, stored directly the way a legacy row or a partial feed lands.
    $providerId = (int) ($repo->listProviders()[0]['id'] ?? 1);
    $repo->saveFixture($providerId, [
        'externalId' => 'fx-gr-thin', 'competition' => 'Unknown Cup', 'leagueId' => '99', 'season' => '2026',
        'kickoff' => gmdate('c', $start + 180), 'status' => 'SCHEDULED',
        'homeTeam' => 'Home United', 'awayTeam' => 'Away Rovers', 'homeTeamId' => '900', 'awayTeamId' => '901',
    ]);

    // A generating read — what the console does by default.
    $board = $module->board()->forDate($day, true, 1, MatchFeed::MAX_PAGE_SIZE);
    $page = (array) ($board['page'] ?? []);
    assert_equals(4, (int) ($page['fixtures'] ?? -1), 'the page block counts the page\'s four fixtures');
    assert_equals(2, (int) ($page['predicted'] ?? -1), 'the two predictable fixtures carry stored predictions after the read');
    assert_equals(1, (int) ($page['withheld'] ?? -1), 'the thin fixture was refused by the quality gate and is named withheld');
    assert_equals(1, (int) ($page['frozen'] ?? -1), 'the postponed fixture is closed, never predictable');
    assert_equals(0, (int) ($page['notAttempted'] ?? -1), 'a generating read leaves nothing unattempted');
    assert_equals(2, (int) $repo->countPredictions(['date' => $day, 'kind' => PredictionService::KIND_PRE_MATCH]),
        'only the two predictable fixtures wrote rows');
    // The summary counts still describe the whole selection, not the page.
    assert_equals(4, (int) ($board['summary']['fixtures'] ?? -1), 'the summary counts stay date-wide');
    assert_equals(2, (int) ($board['summary']['analyzed'] ?? -1), 'and count the two stored prediction rows');

    // The engine did assess the thin fixture even though it correctly refused
    // to publish a prediction. Its measured result must replace the old display
    // defaults (DQ 0/100, DATA_UNAVAILABLE, Unrated, UNKNOWN risk, NOT_ANALYZED).
    $thinRow = null;
    foreach ((array) $board['rows'] as $row) {
        if ((string) ($row['externalId'] ?? '') === 'fx-gr-thin') $thinRow = $row;
    }
    assert_not_null($thinRow, 'the thin fixture remains on the every-match board');
    assert_true(is_numeric($thinRow['dataQualityScore'] ?? null), 'its measured DQ score is carried to the board');
    assert_true((int) $thinRow['dataQualityScore'] > 0, 'a missing default is never rendered as the fabricated score zero');
    assert_equals('REJECTED', (string) ($thinRow['band'] ?? ''), 'the score carries its measured band');
    assert_equals('PREDICTION_WITHHELD', (string) ($thinRow['assessmentState'] ?? ''), 'an engine refusal is not called unanalysed');
    assert_contains('Data quality ', (string) ($thinRow['assessmentReason'] ?? ''), 'the exact refusal explains what needs more data');
    assert_null($thinRow['riskStatus'] ?? null, 'no model risk is invented without a published prediction');
    assert_equals((string) ($repo->findFixtureById((int) $thinRow['fixtureId'])['data_state'] ?? 'DATA_UNAVAILABLE'),
        (string) ($thinRow['fixtureDataState'] ?? ''), 'the fixture data badge reads the stored state instead of a missing row-contract fallback');

    // The assessment is durable: the POST action redirects before the next
    // board read, and a read-only revisit must still show the measured answer.
    $assessmentRows = $repo->listFixtureStatisticsFor([(int) $thinRow['fixtureId']], PredictionService::ASSESSMENT_KIND);
    assert_true(isset($assessmentRows[(int) $thinRow['fixtureId']]), 'the refusal is stored separately from predictions');
    $revisit = $module->board()->forDate($day, false, 1, MatchFeed::MAX_PAGE_SIZE);
    $revisitedThin = null;
    foreach ((array) $revisit['rows'] as $row) if ((string) ($row['externalId'] ?? '') === 'fx-gr-thin') $revisitedThin = $row;
    assert_not_null($revisitedThin);
    assert_equals((int) $thinRow['dataQualityScore'], (int) ($revisitedThin['dataQualityScore'] ?? -1),
        'the redirect/revisit retains the measured score');
    assert_equals('PREDICTION_WITHHELD', (string) ($revisitedThin['assessmentState'] ?? ''));
    assert_equals(1, (int) ($revisit['page']['withheld'] ?? -1), 'the page overview retains the durable withheld classification too');
    assert_equals(0, (int) ($revisit['page']['notAttempted'] ?? -1), 'the persisted assessment is not counted a second time as awaiting');

    // A read-only read (refresh=0 / generate-on-read off) writes nothing and
    // still classifies what it can without the engine.
    [$repo2, , $module2] = fx_fb_harness($rows);
    fx_fb_sync_today($module2, $day);
    $repo2->saveFixture((int) ($repo2->listProviders()[0]['id'] ?? 1), [
        'externalId' => 'fx-gr-thin', 'competition' => 'Unknown Cup', 'leagueId' => '99', 'season' => '2026',
        'kickoff' => gmdate('c', $start + 180), 'status' => 'SCHEDULED',
        'homeTeam' => 'Home United', 'awayTeam' => 'Away Rovers', 'homeTeamId' => '900', 'awayTeamId' => '901',
    ]);
    $read = $module2->board()->forDate($day, false, 1, MatchFeed::MAX_PAGE_SIZE);
    assert_equals(0, (int) $repo2->countPredictions(['date' => $day, 'kind' => PredictionService::KIND_PRE_MATCH]),
        'a read-only read wrote no prediction row');
    assert_equals(1, (int) ($read['page']['frozen'] ?? -1), 'the closed fixture is classified without the engine');
    assert_equals(3, (int) ($read['page']['notAttempted'] ?? -1), 'the rest are named not-attempted, not silently blank');
    assert_equals(0, (int) ($read['page']['withheld'] ?? -1), 'nothing was evaluated, so nothing was refused yet');
    assert_equals(0, (int) ($read['page']['predicted'] ?? -1), 'and nothing is dressed up as predicted');
    $freshThin = null;
    foreach ((array) $read['rows'] as $row) if ((string) ($row['externalId'] ?? '') === 'fx-gr-thin') $freshThin = $row;
    assert_not_null($freshThin);
    assert_null($freshThin['dataQualityScore'] ?? null, 'an assessment that never ran stays null, not zero');
    assert_equals('AWAITING_ANALYSIS', (string) ($freshThin['assessmentState'] ?? ''), 'the UI can name the next action without claiming an analysis occurred');
});

test('football generate-on-read: a page larger than the cycle reports its deferred remainder', function () {
    $day = gmdate('Y-m-d', time() + 2 * 86400);
    $start = (int) strtotime($day . 'T00:30:00+00:00');
    $rows = [];
    for ($i = 0; $i < 3; $i++) {
        $rows[] = fx_fb_row('fx-grd-' . $i, gmdate('c', $start + $i * 60), 'Manchester City', 'Everton', '10', '20');
    }
    [, , $module] = fx_fb_harness($rows, [], ['WINDELS_FOOTBALL_ANALYSIS_BATCH_SIZE' => 2]);
    fx_fb_sync_today($module, $day);

    $board = $module->board()->forDate($day, true, 1, MatchFeed::MAX_PAGE_SIZE);
    $page = (array) ($board['page'] ?? []);
    assert_equals(3, (int) ($page['fixtures'] ?? -1), 'the page holds three fixtures');
    assert_equals(2, (int) ($page['predicted'] ?? -1), 'the configured two-match cycle generated two');
    assert_equals(1, (int) ($page['deferred'] ?? -1), 'the third is deferred to the next cycle, not silently dropped');
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
    // A genuinely busy current date: MORE fixtures today than the 20-fixture
    // cap, so today alone must exhaust the shared allowance and tomorrow's
    // slice of this cycle is exactly zero.
    for ($i = 0; $i < 23; $i++) {
        $fixtures[] = fx_fb_row('fx-cron-today-' . $i, $today . 'T' . sprintf('%02d', 1 + ($i % 23)) . ':00:00+00:00',
            'Manchester City', 'Everton', '10', '20');
    }
    for ($i = 0; $i < 15; $i++) {
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
