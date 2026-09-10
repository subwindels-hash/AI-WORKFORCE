<?php
/**
 * Form budget efficiency — the "14 with-form" dead end of the 2026-09-10
 * NO_QUALIFIED_TICKET run: 102 eligible fixtures, a 30-lookup budget, and
 * api-football whose per-team /teams/statistics endpoint costs TWO lookups
 * per fixture. 30 lookups → ~15 fixtures with form → the rest of the day is
 * INSUFFICIENT_DATA (67 rejections in the reported run).
 *
 * The league table (ONE request per league, every team's played /
 * goals-for / goals-against) is now the PRIMARY source; api-football's
 * per-team statistics are the FALLBACK for teams the table does not cover
 * (cup sides, mid-season moves, no games yet). Nothing is relaxed: a team
 * neither in the table nor served by per-team statistics stays an honest
 * INSUFFICIENT_DATA rejection.
 */
use AIWorkforce\Sports\FormResolver;
use AIWorkforce\Sports\Providers\ApiFootballProvider;

/** api-football /standings response covering the given teams (id => [for, against]). */
function fb_standings_body(array $teams): string
{
    $entries = [];
    foreach ($teams as $id => $g) {
        $entries[] = ['team' => ['id' => $id, 'name' => 'Team ' . $id], 'rank' => count($entries) + 1, 'all' => ['played' => 10, 'goals' => ['for' => $g[0], 'against' => $g[1]]]];
    }
    return json_encode(['response' => [['league' => ['name' => 'FB League', 'standings' => [$entries]]]]]);
}

test('api-football: one league-table request serves a whole day of fixtures', function () {
    // Ten teams across five fixtures in ONE league — the reported run's
    // shape: the table must carry every one of them.
    $table = [33 => [18, 9], 35 => [16, 10], 37 => [14, 12], 39 => [10, 16], 41 => [20, 5]];
    foreach ([40, 42, 44, 46, 48] as $id) $table[$id] = [12, 14];
    $urls = [];
    $transport = function (string $url) use (&$urls, $table) {
        $urls[] = $url;
        if (str_contains($url, '/standings')) {
            return ['status' => 200, 'body' => fb_standings_body($table)];
        }
        // Per-team endpoint would answer — but must never be asked for
        // table-covered teams.
        return ['status' => 200, 'body' => json_encode(['response' => ['fixtures' => ['played' => ['total' => 10]], 'goals' => ['for' => ['total' => ['total' => 99]], 'against' => ['total' => ['total' => 99]]]]])];
    };
    $p = new ApiFootballProvider('k', 'https://api.test', 10, $transport);
    $fixtures = [];
    for ($i = 0; $i < 5; $i++) {
        $fixtures[] = ['homeTeamId' => (string) (33 + 2 * $i), 'awayTeamId' => (string) (40 + 2 * $i), 'leagueId' => '39', 'season' => '2026'];
    }
    $resolver = new FormResolver(30);
    $enriched = $resolver->enrich($p, $fixtures);

    $standingsCalls = array_values(array_filter($urls, fn($u) => str_contains($u, '/standings')));
    $statsCalls = array_values(array_filter($urls, fn($u) => str_contains($u, '/teams/statistics')));
    assert_equals(1, count($standingsCalls), 'ONE table request for the whole league');
    assert_equals(0, count($statsCalls), 'per-team statistics are never asked for table-covered teams');
    assert_equals(1, (int) $resolver->stats()['lookupsUsed'], 'the budget spent is the league, not 2×the fixtures');
    foreach ($enriched as $fixture) {
        assert_true(!empty($fixture['context']['recentForm']), 'every fixture in the league carries verified form');
    }
    // Same numbers the old per-team path produced (18/10 = 1.8, 14/10 = 1.4).
    $form = $enriched[0]['context']['recentForm'];
    assert_equals(1.8, $form['homeGoalsPerMatch']);
    assert_equals(1.4, $form['awayConcededPerMatch']);
});

test('api-football: per-team statistics are the fallback for teams the table does not cover', function () {
    $urls = [];
    $transport = function (string $url) use (&$urls) {
        $urls[] = $url;
        if (str_contains($url, '/standings')) return ['status' => 200, 'body' => fb_standings_body([33 => [18, 9]])];
        if (str_contains($url, '/teams/statistics')) {
            return ['status' => 200, 'body' => json_encode(['response' => ['fixtures' => ['played' => ['total' => 10]], 'goals' => ['for' => ['total' => ['total' => 25]], 'against' => ['total' => ['total' => 10]]]]])];
        }
        return ['status' => 200, 'body' => '{}'];
    };
    $p = new ApiFootballProvider('k', 'https://api.test', 10, $transport);
    $enriched = (new FormResolver(30))->enrich($p, [
        // Team 99 is a cup side: absent from the league table, present in
        // its own per-team statistics.
        ['homeTeamId' => '33', 'awayTeamId' => '99', 'leagueId' => '39', 'season' => '2026'],
    ]);
    $statsCalls = array_values(array_filter($urls, fn($u) => str_contains($u, '/teams/statistics')));
    assert_equals(1, count($statsCalls), 'the uncovered team is resolved per-team');
    assert_equals(1, count(array_filter($urls, fn($u) => str_contains($u, '/standings'))), 'the table is still the first stop');
    assert_true(!empty($enriched[0]['context']['recentForm']), 'table + per-team fallback resolve the fixture');
    assert_equals(1.8, $enriched[0]['context']['recentForm']['homeGoalsPerMatch'], 'home side comes from the table');
    assert_equals(2.5, $enriched[0]['context']['recentForm']['awayGoalsPerMatch'], 'away side comes from per-team statistics');
});

test('api-football: a team in neither table nor per-team statistics stays unresolved', function () {
    $transport = function (string $url) {
        if (str_contains($url, '/standings')) return ['status' => 200, 'body' => fb_standings_body([33 => [18, 9]])];
        if (str_contains($url, '/teams/statistics')) {
            return ['status' => 200, 'body' => json_encode(['response' => ['fixtures' => ['played' => ['total' => 0]], 'goals' => []]])];
        }
        return ['status' => 200, 'body' => '{}'];
    };
    $p = new ApiFootballProvider('k', 'https://api.test', 10, $transport);
    $enriched = (new FormResolver(30))->enrich($p, [
        ['homeTeamId' => '33', 'awayTeamId' => '99', 'leagueId' => '39', 'season' => '2026'],
    ]);
    assert_true(empty($enriched[0]['context']['recentForm']), 'no evidence is invented — the fixture stays INSUFFICIENT_DATA downstream');
});
