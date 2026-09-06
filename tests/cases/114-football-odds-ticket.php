<?php
/**
 * Football Intelligence — the Odds Prediction Ticket read model (spec §6).
 *
 * The ticket is a re-reading of the stored, quality-gated predictions: entries
 * are ordered by kickoff (the order of the day, not a confidence leaderboard),
 * every entry carries the match, league, teams, H/D/A percentages, predicted
 * score, expected goals, confidence and its A/B/C category, and the ticket
 * states plainly that nothing on it is a guarantee. Filters (category, league
 * scope) narrow what is shown — they never promote a match into a category or
 * a league to fill a blank.
 */
require_once TESTSPATH . 'football_support.php';

use AIWorkforce\Football\PredictionService;
use AIWorkforce\Football\TicketService;
use AIWorkforce\Football\FootballConfiguration;

/**
 * A day with three stored, predicted fixtures at distinct kickoffs. Returns
 * [repo, provider, intelligence, audit, date].
 */
function fx_fb_ticket_day(): array
{
    // Three kickoffs at 10:00/11:00/12:00 UTC on one fixed future day, so the
    // scenario cannot straddle a date boundary at any real test time.
    $day = gmdate('Y-m-d', time() + 3 * 86400);
    $base = \DateTime::createFromFormat('Y-m-d H:i:s', $day . ' 00:00:00', new \DateTimeZone('UTC'))->getTimestamp();
    [$repo, $provider, $intel, $audit] = fx_fb_harness([
        fx_fb_row('fx-tk-1', gmdate('c', $base + 10 * 3600), 'Brighton', 'Burnley', '30', '40'),
        fx_fb_row('fx-tk-2', gmdate('c', $base + 11 * 3600), 'Manchester City', 'Everton', '10', '20'),
        fx_fb_row('fx-tk-3', gmdate('c', $base + 12 * 3600), 'Everton', 'Brighton', '20', '30'),
    ]);
    fx_fb_sync_today($intel, $day);
    $intel->predictions()->predictDay($day);
    $stored = $repo->listPredictions(['date' => $day, 'kind' => PredictionService::KIND_PRE_MATCH], 10);
    if (count($stored) < 3) {
        throw new RuntimeException('harness produced ' . count($stored) . ' predictions; the ticket cases need all three');
    }
    return [$repo, $provider, $intel, $audit, $day];
}

test('football: the ticket lists every stored prediction in kickoff order with the full entry layout', function () {
    [, , $intel, , $day] = fx_fb_ticket_day();
    $ticket = $intel->ticket()->ticket($day);

    assert_equals('POPULATED', $ticket['state']);
    assert_equals(3, $ticket['summary']['fixtures']);
    assert_equals(3, count($ticket['entries']), 'every stored prediction appears on the ticket');

    $kickoffs = array_map(static fn(array $e) => (string) $e['kickoff'], $ticket['entries']);
    $sorted = $kickoffs;
    sort($sorted);
    assert_equals($sorted, $kickoffs, 'entries are ordered by kickoff, not by confidence');
    $numbers = array_map(static fn(array $e) => (int) $e['entryNumber'], $ticket['entries']);
    assert_equals([1, 2, 3], $numbers, 'entry numbers run 1..n in ticket order');

    foreach ($ticket['entries'] as $entry) {
        assert_true($entry['homeTeam'] !== '' && $entry['awayTeam'] !== '', 'entry names both teams');
        assert_equals('Premier League', $entry['league'], 'entry carries the league');
        foreach (['home', 'draw', 'away'] as $side) {
            assert_true(is_numeric($entry['probabilities'][$side]), "entry carries the {$side} probability");
        }
        assert_true(is_array($entry['predictedScore']) && is_numeric($entry['predictedScore']['home']), 'entry carries the predicted score');
        assert_true(is_array($entry['expectedGoals']) && is_numeric($entry['expectedGoals']['home']), 'entry carries the expected goals for both sides');
        assert_true(is_numeric($entry['confidence']), 'every entry carries a confidence score');
        assert_contains('guarantee', $ticket['disclaimer'], 'the ticket states that no prediction is a guarantee');
    }
    // The first entry is the earliest kickoff: Brighton vs Burnley.
    assert_contains('Brighton', $ticket['entries'][0]['homeTeam']);
    assert_contains('Burnley', $ticket['entries'][0]['awayTeam']);
});

test('football: the category filter narrows the ticket and an empty bucket says so', function () {
    [, , $intel, , $day] = fx_fb_ticket_day();
    $all = $intel->ticket()->ticket($day);
    $byCategory = $all['summary']['byCategory'];
    assert_true($byCategory['A'] + $byCategory['B'] + $byCategory['C'] + $byCategory['UNCLASSIFIED'] === 3,
        'the summary accounts for every entry across the four buckets');

    foreach (['A', 'B', 'C'] as $key) {
        $filtered = $intel->ticket()->ticket($day, $key);
        foreach ($filtered['entries'] as $entry) {
            assert_equals($key, $entry['category'], "every entry of the filtered ticket is category {$key}");
        }
        if ($byCategory[$key] === 0) {
            assert_equals('NONE_MATCH_FILTER', $filtered['state'], "an empty category reports NONE_MATCH_FILTER instead of inventing a match");
            assert_contains($key, (string) $filtered['message'], 'the message names the empty category');
        }
    }
});

test('football: the league scope is a display filter on the ticket and a gate on the prediction pass', function () {
    // Display side: predictions stored under an empty scope; a scoped ticket hides them all.
    [, , $intel, , $day] = fx_fb_ticket_day();
    $scopedTicket = new TicketService(
        $intel->repository(),
        $intel->board(),
        $intel->models(),
        new FootballConfiguration(['WINDELS_FOOTBALL_LEAGUE_SCOPE' => json_encode(['fxprov|9999'])])
    );
    $scoped = $scopedTicket->ticket($day);
    assert_equals('NONE_MATCH_FILTER', $scoped['state'], 'no stored prediction falls inside the foreign scope');
    assert_equals(3, $scoped['summary']['outsideScope'], 'the three out-of-scope predictions are counted, not silently dropped');

    // Prediction side: with the scope configured before the pass, nothing is predicted at all.
    $kickoff = time() + 7200;
    $day2 = gmdate('Y-m-d', $kickoff);
    [, , $intel2] = fx_fb_harness(
        [fx_fb_row('fx-tk-scoped', gmdate('c', $kickoff), 'Manchester City', 'Everton', '10', '20')],
        [],
        ['WINDELS_FOOTBALL_LEAGUE_SCOPE' => json_encode(['fxprov|9999'])]
    );
    fx_fb_sync_today($intel2, $day2);
    $result = $intel2->predictions()->predictDay($day2);
    assert_equals('OUT_OF_SCOPE', $result['status'], 'a fully out-of-scope day is a named state, not an empty success');
    assert_equals(0, count($intel2->repository()->listPredictions(['date' => $day2], 10)), 'no prediction is written outside the scope');
});

test('football: the ticket reports its empty states instead of fabricating a day', function () {
    [, , $intel, , $day] = fx_fb_ticket_day();
    $noFixtures = $intel->ticket()->ticket(gmdate('Y-m-d', strtotime($day . ' +3 days')));
    assert_equals('NO_FIXTURES_STORED', $noFixtures['state']);
    assert_equals([], $noFixtures['entries'], 'a fixtureless day carries no entries');

    // Fixtures stored but never analyzed: a separate day that was synced, not predicted.
    $kickoff = time() + 2 * 86400 + 7200;
    $dayB = gmdate('Y-m-d', $kickoff);
    [$repoB, , $intelB] = fx_fb_harness([
        fx_fb_row('fx-tk-later', gmdate('c', $kickoff), 'Manchester City', 'Everton', '10', '20'),
    ]);
    fx_fb_sync_today($intelB, $dayB);
    assert_true(count($repoB->listFixtures(['date' => $dayB])) === 1, 'the later day has stored fixtures');
    $unpredicted = $intelB->ticket()->ticket($dayB);
    assert_equals('NO_PREDICTIONS_STORED', $unpredicted['state'], 'stored-but-unpredicted is a named state, not a blank ticket');
    assert_equals(1, $unpredicted['summary']['fixtures']);

    $invalid = $intel->ticket()->ticket('31-02-2026');
    assert_equals('INVALID_DATE', $invalid['state'], 'an unparsable date is named, and today is shown instead');
    assert_equals(gmdate('Y-m-d'), $invalid['date']);
});
