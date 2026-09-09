<?php
/**
 * Football Intelligence — every football match has its own ID once it exists.
 *
 * The failure this suite exists to prevent: some console rows showed no usable
 * identity — a blank "Match ID" and a team link pointed at /football/match/0 —
 * so a match that had been generated (its fixture stored) could not be opened
 * by its own page. The page id of a match is its stored fixture id: the number
 * `/football/match/<fixtureId>` is served under.
 *
 * Pinned here:
 *  - `MatchFeed::matchId()` is never blank for a stored match: the provider's
 *    own id when the feed supplied one, the stored fixture id (`fixture:<id>`)
 *    when it did not;
 *  - every board row carries `fixtureId > 0` and a non-blank `matchId`, each
 *    row's ids are its own (no two rows share an id on a page), and the row's
 *    page is `/football/match/<fixtureId>`;
 *  - the same holds for matches that were just generated, matches that were
 *    reused, and matches that still await analysis;
 *  - the console never renders a dead `/football/match/0` link: rows and cards
 *    link only when a stored id exists, and the printed Match ID is the same
 *    stored id the link carries.
 */
require_once TESTSPATH . 'football_support.php';

use AIWorkforce\Football\MatchFeed;

/** Twelve scheduled fixtures on one future date. Returns [repo, module, day]. */
function fx_fb129_twelve(): array
{
    $day = gmdate('Y-m-d', time() + 2 * 86400);
    $base = (int) strtotime($day . 'T00:30:00+00:00');
    $rows = [];
    for ($i = 0; $i < 12; $i++) {
        $rows[] = fx_fb_row('fx-129-' . $i, gmdate('c', $base + $i * 60),
            'Manchester City', 'Everton', '10', '20', 'SCHEDULED');
    }
    [$repo, , $module] = fx_fb_harness($rows);
    fx_fb_sync_today($module, $day);
    return [$repo, $module, $day];
}

test('football match ids: a stored match is never keyed by a blank id', function () {
    assert_equals('api-football:1201', MatchFeed::matchId(['provider_code' => 'api-football', 'external_id' => '1201']),
        'a feed-supplied id stays provider-scoped');
    assert_equals('1201', MatchFeed::matchId(['external_id' => '1201']),
        'an external id without a provider code is still the match id, as before');
    // A stored row whose feed gave no external id (legacy row, or a feed that
    // omitted one) falls back to the fixture's own stored id — the number its
    // page is served under — so the match keeps a unique identity.
    assert_equals('fixture:77', MatchFeed::matchId(['id' => 77, 'provider_code' => 'api-football', 'external_id' => '']),
        'a stored row without a provider id falls back to its fixture id');
    // Only a match that is not stored anywhere has no identity at all.
    assert_equals('', MatchFeed::matchId(['external_id' => '']),
        'an unstored row (no fixture id either) still has no identity');
});

test('football match ids: every board row carries its own id and its own page', function () {
    [$repo, $module, $day] = fx_fb129_twelve();

    $board = $module->board()->forDate($day, false, 1, 12);
    $rows = (array) $board['rows'];
    assert_equals(12, count($rows), 'all twelve matches are listed');
    $storedIds = array_map(static fn(array $m): int => (int) ($m['id'] ?? 0),
        $repo->listFixtures(['date' => $day]));
    assert_equals(12, count($storedIds), 'twelve stored fixtures to compare against');
    foreach ($rows as $index => $row) {
        assert_true((int) ($row['fixtureId'] ?? 0) > 0,
            'row ' . $index . ' carries its stored fixture id');
        assert_true((string) ($row['matchId'] ?? '') !== '',
            'row ' . $index . ' carries a non-blank match identity');
        assert_equals($storedIds[$index] ?? 0, (int) ($row['fixtureId'] ?? 0),
            'row ' . $index . ' is the stored fixture it belongs to');
        assert_true(str_starts_with((string) $row['matchId'], 'apifootball:fx-129-'),
            'row ' . $index . ' keeps the feed identity that stored it');
    }
    assert_equals(count($rows), count(array_unique(array_map(
        static fn(array $r): string => (string) ($r['matchId'] ?? ''), $rows))),
        'no two rows on the page share an id');
    assert_equals(count($rows), count(array_unique(array_map(
        static fn(array $r): int => (int) ($r['fixtureId'] ?? 0), $rows))),
        'and no two rows share a page id');
});

test('football match ids: generated, reused and still-waiting matches each keep their own id', function () {
    [$repo, $module, $day] = fx_fb129_twelve();

    // Generate page 1 (first 6): six new predictions are written.
    $first = $module->feed()->generate($day, 1, 6);
    assert_equals(6, (int) $first['generation']['generated'], 'six were generated in the first call');
    $generatedRows = $module->board()->forDate($day, false, 1, 6)['rows'];
    assert_equals(6, count($generatedRows), 'the six generated matches are listed');
    foreach ($generatedRows as $row) {
        assert_equals('ANALYZED', (string) ($row['analysisState'] ?? ''),
            'a generated match is analyzed on the board');
        assert_true((int) ($row['fixtureId'] ?? 0) > 0, 'with its own stored fixture id');
        assert_true((string) ($row['matchId'] ?? '') !== '', 'and its own non-blank match id');
    }

    // Generate the same page again: nothing is rewritten, the same rows keep
    // the same ids — generation does not change a match's identity.
    $again = $module->feed()->generate($day, 1, 6);
    assert_equals(0, (int) $again['generation']['generated'], 'the second call generated nothing new');
    $reused = $module->board()->forDate($day, false, 1, 6)['rows'];
    foreach ($reused as $index => $row) {
        assert_equals((int) $generatedRows[$index]['fixtureId'], (int) $row['fixtureId'],
            'the reused row keeps the same fixture id');
        assert_equals((string) $generatedRows[$index]['matchId'], (string) $row['matchId'],
            'and the same match id');
    }

    // The matches on page 2 still await analysis — and they, too, each have
    // their own id and their own page even before any generation.
    $waiting = $module->board()->forDate($day, false, 2, 6)['rows'];
    assert_equals(6, count($waiting), 'the remaining six are listed');
    foreach ($waiting as $row) {
        assert_equals('NOT_ANALYZED', (string) ($row['analysisState'] ?? ''), 'still awaiting analysis');
        assert_true((int) ($row['fixtureId'] ?? 0) > 0, 'but already carrying its stored fixture id');
        assert_true((string) ($row['matchId'] ?? '') !== '', 'and its own match id');
    }
    $allIds = array_merge(
        array_map(static fn(array $r): int => (int) $r['fixtureId'], $generatedRows),
        array_map(static fn(array $r): int => (int) $r['fixtureId'], $waiting));
    assert_equals(12, count(array_unique($allIds)), 'all twelve matches across both pages have distinct page ids');
});

test('football match ids: the console cannot render a /football/match/0 link', function () {
    $view = fx_fb_read('application/views/football/index.php');

    // The old unconditional link interpolated the id without a guard, so a row
    // without a stored fixture rendered /football/match/0 — a dead page.
    assert_equals(0, substr_count($view, 'football/match/<?= (int)'),
        'no link is interpolated without a stored-id guard');
    assert_equals(1, substr_count($view, 'href="/football/match/<?= $rowPageId ?>"'),
        'each table row links to its own stored fixture id');
    assert_equals(1, substr_count($view, 'href="/football/match/<?= $cardPageId ?>"'),
        'each prediction card links to its own stored fixture id');
    // The printed Match ID is the same stored id the link carries.
    assert_equals(1, substr_count($view, 'Match ID: <?= $rowPageId > 0 ? $rowPageId'),
        'the row prints its stored fixture id as the Match ID, never a blank');
});
