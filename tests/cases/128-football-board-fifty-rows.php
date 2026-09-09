<?php
/**
 * Football Intelligence — the board renders 50 matches per page, analyzed or not.
 *
 * The failure this suite exists to prevent: the console's market table showed
 * only the few matches that already had a prediction instead of the full page
 * of 50, because the board skipped fixtures without a stored prediction when
 * building the table — which also paired the wrong fixture with the wrong
 * market block, since rows were matched to fixtures by position.
 *
 * Pinned here:
 *  - one table row per match on the page, even when only a few are analyzed;
 *  - every row carries its own fixture (fixtureId/matchId agree with the feed);
 *  - analyzed rows carry the stored prediction, unanalyzed rows state that.
 */
require_once TESTSPATH . 'football_support.php';

use AIWorkforce\Football\DataState;
use AIWorkforce\Football\MatchFeed;

/** Sixty scheduled fixtures on one future date. Returns [repo, module, day]. */
function fx_fb128_sixty(): array
{
    $day = gmdate('Y-m-d', time() + 2 * 86400);
    $base = (int) strtotime($day . 'T00:30:00+00:00');
    $rows = [];
    for ($i = 0; $i < 60; $i++) {
        $rows[] = fx_fb_row('fx-128-' . $i, gmdate('c', $base + $i * 60),
            'Manchester City', 'Everton', '10', '20', 'SCHEDULED');
    }
    [$repo, , $module] = fx_fb_harness($rows);
    fx_fb_sync_today($module, $day);
    return [$repo, $module, $day];
}

test('football board: a page holds 50 rows even when only a few matches are analyzed', function () {
    [, $module, $day] = fx_fb128_sixty();

    // Analyze the first 5 only: the page now holds 5 predictions and 45 gaps.
    $generated = $module->feed()->generate($day, 1, 5);
    assert_equals(5, (int) $generated['generation']['generated'], 'the setup analyzed 5 of the 60');

    $board = $module->board()->forDate($day, false, 1, 50);
    $rows = (array) $board['rows'];
    assert_equals(50, count($rows), 'the table holds all 50 matches on the page, not just the 5 analyzed');
    assert_equals(60, (int) $board['pagination']['totalMatches'], 'the totals still describe the whole date');
    assert_equals(50, (int) $board['pagination']['returned'], 'and the page reports 50 returned');
    assert_equals(5, (int) $board['pagination']['predicted'], '5 of them analyzed');
    assert_equals(45, (int) $board['pagination']['awaiting'], '45 still awaiting');

    $analyzed = array_values(array_filter($rows, static fn(array $r): bool => ($r['analysisState'] ?? '') === 'ANALYZED'));
    $waiting = array_values(array_filter($rows, static fn(array $r): bool => ($r['analysisState'] ?? '') === 'NOT_ANALYZED'));
    assert_equals(5, count($analyzed), '5 rows are analyzed');
    assert_equals(45, count($waiting), 'the other 45 say NOT_ANALYZED instead of going missing');
    foreach ($analyzed as $row) {
        assert_true(is_array($row['prediction'] ?? null), 'an analyzed row carries its stored prediction');
        assert_true(is_numeric($row['confidence'] ?? null), 'and its confidence');
    }
    foreach ($waiting as $row) {
        assert_null($row['prediction'] ?? null, 'an unanalyzed row carries no prediction');
        assert_equals(DataState::UNAVAILABLE, (string) ($row['market']['state'] ?? ''),
            'and its market block states the absence rather than inventing a price');
    }

    $second = $module->board()->forDate($day, false, 2, 50);
    assert_equals(10, count((array) $second['rows']), 'the last page holds the remaining 10');
});

test('football board: every row carries its own fixture, in feed order', function () {
    [, $module, $day] = fx_fb128_sixty();
    $module->feed()->generate($day, 1, 5);

    $feedIds = array_map(static fn(array $m): int => (int) ($m['fixtureId'] ?? 0),
        $module->feed()->page($day, 1, 50, false)['matches']);
    $board = $module->board()->forDate($day, false, 1, 50);
    $rows = (array) $board['rows'];
    assert_equals(50, count($feedIds), 'the feed page holds 50 to compare against');
    foreach ($rows as $index => $row) {
        assert_equals($feedIds[$index] ?? 0, (int) ($row['fixtureId'] ?? 0),
            'row ' . $index . ' is the feed\'s match ' . $index . ', not a later match shifted up by the gaps');
    }
    // The analyzed five are the page's first five — the rows did not slide.
    foreach (array_slice($rows, 0, 5) as $row) {
        assert_equals('ANALYZED', (string) ($row['analysisState'] ?? ''), 'the first five rows are the analyzed five');
    }
    foreach (array_slice($rows, 5) as $row) {
        assert_equals('NOT_ANALYZED', (string) ($row['analysisState'] ?? ''), 'everything after them awaits analysis');
    }
});

test('football board: a fully analyzed page still renders 50 complete rows', function () {
    [, $module, $day] = fx_fb128_sixty();
    $module->feed()->generate($day, 1, 50);

    $board = $module->board()->forDate($day, false, 1, 50);
    $rows = (array) $board['rows'];
    assert_equals(50, count($rows), '50 rows');
    foreach ($rows as $row) {
        assert_equals('ANALYZED', (string) ($row['analysisState'] ?? ''), 'every row analyzed');
        assert_true(is_array($row['prediction'] ?? null), 'with its stored prediction');
        assert_true(is_array($row['market'] ?? null), 'and the market block');
        foreach (['homeTeam', 'awayTeam', 'competition', 'kickoffLabel', 'prediction', 'market', 'confidence', 'risk'] as $key) {
            assert_true(array_key_exists($key, $row), 'the row carries ' . $key);
        }
    }
    assert_true(count($rows) === count(array_unique(array_map(static fn(array $r): int => (int) $r['fixtureId'], $rows))),
        'and no fixture appears twice');
    assert_equals(MatchFeed::MAX_PAGE_SIZE, 50, 'the page size the suite pages with is the documented 50');
});
