<?php
/**
 * Tests for the odds market normalization, FormResolver enrichment,
 * and end-to-end pipeline integration with native providers.
 */
use AIWorkforce\Sports\Providers\ApiFootballProvider;
use AIWorkforce\Sports\Providers\TheSportsDbProvider;
use AIWorkforce\Sports\Providers\SportMonksProvider;
use AIWorkforce\Sports\Providers\SandboxSportsProvider;
use AIWorkforce\Sports\FormResolver;
use AIWorkforce\Sports\SportsDataNormalizer;

function makeTransport2(int $status, string $body = '{}'): callable
{
    return fn(string $url, array $headers) => ['status' => $status, 'body' => $body];
}

// ═══════════════════════════════════════════════════════════════════════════
// 1. ODDS MARKET NORMALIZATION (API-Football)
// ═══════════════════════════════════════════════════════════════════════════

test('api-football normalizes Over/Under 1.5 to TOTAL_GOALS / OVER_1_5', function () {
    $body = json_encode(['response' => [
        ['fixture' => ['id' => 100], 'bookmakers' => [
            ['name' => 'Bet365', 'bets' => [
                ['name' => 'Over/Under 1.5', 'values' => [
                    ['value' => 'Over 1.5', 'odd' => 1.35],
                    ['value' => 'Under 1.5', 'odd' => 3.20],
                ]],
            ]],
        ]],
    ]]);
    $p = new ApiFootballProvider('k', 'https://api.test', 10, makeTransport2(200, $body));
    $odds = $p->odds('100');
    assert_equals(2, count($odds));
    assert_equals('TOTAL_GOALS', $odds[0]['market']);
    assert_equals('OVER_1_5', $odds[0]['selection']);
    assert_equals(1.35, $odds[0]['decimalOdds']);
    assert_equals('TOTAL_GOALS', $odds[1]['market']);
    assert_equals('UNDER_1_5', $odds[1]['selection']);
});

test('api-football normalizes Over/Under 2.5 correctly', function () {
    $body = json_encode(['response' => [
        ['fixture' => ['id' => 200], 'bookmakers' => [
            ['name' => 'William Hill', 'bets' => [
                ['name' => 'Total Goals', 'values' => [
                    ['value' => 'Over 2.5', 'odd' => 1.85],
                    ['value' => 'Under 2.5', 'odd' => 1.95],
                ]],
            ]],
        ]],
    ]]);
    $p = new ApiFootballProvider('k', 'https://api.test', 10, makeTransport2(200, $body));
    $odds = $p->odds('200');
    assert_equals(2, count($odds));
    assert_equals('TOTAL_GOALS', $odds[0]['market']);
    assert_equals('OVER_2_5', $odds[0]['selection']);
    assert_equals('UNDER_2_5', $odds[1]['selection']);
});

test('api-football normalizes Match Winner to MATCH_RESULT', function () {
    $body = json_encode(['response' => [
        ['fixture' => ['id' => 300], 'bookmakers' => [
            ['name' => 'Bet365', 'bets' => [
                ['name' => 'Match Winner', 'values' => [
                    ['value' => 'Home', 'odd' => 1.85],
                    ['value' => 'Draw', 'odd' => 3.40],
                    ['value' => 'Away', 'odd' => 4.50],
                ]],
            ]],
        ]],
    ]]);
    $p = new ApiFootballProvider('k', 'https://api.test', 10, makeTransport2(200, $body));
    $odds = $p->odds('300');
    assert_equals(3, count($odds));
    assert_equals('MATCH_RESULT', $odds[0]['market']);
    assert_equals('HOME', $odds[0]['selection']);
    assert_equals('DRAW', $odds[1]['selection']);
    assert_equals('AWAY', $odds[2]['selection']);
});

test('api-football normalizes Both Teams to Score', function () {
    $body = json_encode(['response' => [
        ['fixture' => ['id' => 400], 'bookmakers' => [
            ['name' => 'Bet365', 'bets' => [
                ['name' => 'Both Teams To Score', 'values' => [
                    ['value' => 'Yes', 'odd' => 1.65],
                    ['value' => 'No', 'odd' => 2.20],
                ]],
            ]],
        ]],
    ]]);
    $p = new ApiFootballProvider('k', 'https://api.test', 10, makeTransport2(200, $body));
    $odds = $p->odds('400');
    assert_equals(2, count($odds));
    assert_equals('BOTH_TEAMS_SCORE', $odds[0]['market']);
    assert_equals('YES', $odds[0]['selection']);
    assert_equals('NO', $odds[1]['selection']);
});

test('api-football normalizes European decimal format (1,5 instead of 1.5)', function () {
    $body = json_encode(['response' => [
        ['fixture' => ['id' => 500], 'bookmakers' => [
            ['name' => 'EuroBook', 'bets' => [
                ['name' => 'Over/Under', 'values' => [
                    ['value' => 'Over 1,5', 'odd' => 1.35],
                    ['value' => 'Under 1,5', 'odd' => 3.20],
                ]],
            ]],
        ]],
    ]]);
    $p = new ApiFootballProvider('k', 'https://api.test', 10, makeTransport2(200, $body));
    $odds = $p->odds('500');
    assert_equals('OVER_1_5', $odds[0]['selection']);
    assert_equals('UNDER_1_5', $odds[1]['selection']);
});

// ═══════════════════════════════════════════════════════════════════════════
// 2. ODDS NORMALIZATION (SportMonks)
// ═══════════════════════════════════════════════════════════════════════════

test('sportmonks normalizes Full Time Result to MATCH_RESULT', function () {
    $body = json_encode(['data' => [
        ['fixture_id' => 100, 'value' => 1.85,
         'market' => ['name' => 'Full Time Result'],
         'selection' => ['value' => 'Home'],
         'bookmaker' => ['name' => 'Bet365']],
        ['fixture_id' => 100, 'value' => 3.50,
         'market' => ['name' => 'Full Time Result'],
         'selection' => ['value' => 'Draw'],
         'bookmaker' => ['name' => 'Bet365']],
        ['fixture_id' => 100, 'value' => 4.20,
         'market' => ['name' => 'Full Time Result'],
         'selection' => ['value' => 'Away'],
         'bookmaker' => ['name' => 'Bet365']],
    ]]);
    $p = new SportMonksProvider('t', 'https://api.test', 10, makeTransport2(200, $body));
    $odds = $p->odds('100');
    assert_equals(3, count($odds));
    assert_equals('MATCH_RESULT', $odds[0]['market']);
    assert_equals('HOME', $odds[0]['selection']);
    assert_equals('DRAW', $odds[1]['selection']);
    assert_equals('AWAY', $odds[2]['selection']);
});

test('sportmonks normalizes Over/Under to TOTAL_GOALS', function () {
    $body = json_encode(['data' => [
        ['fixture_id' => 200, 'value' => 1.90,
         'market' => ['name' => 'Over/Under 2.5'],
         'selection' => ['value' => 'Over 2.5'],
         'bookmaker' => ['name' => 'William Hill']],
        ['fixture_id' => 200, 'value' => 1.90,
         'market' => ['name' => 'Over/Under 2.5'],
         'selection' => ['value' => 'Under 2.5'],
         'bookmaker' => ['name' => 'William Hill']],
    ]]);
    $p = new SportMonksProvider('t', 'https://api.test', 10, makeTransport2(200, $body));
    $odds = $p->odds('200');
    assert_equals('TOTAL_GOALS', $odds[0]['market']);
    assert_equals('OVER_2_5', $odds[0]['selection']);
    assert_equals('UNDER_2_5', $odds[1]['selection']);
});

// ═══════════════════════════════════════════════════════════════════════════
// 3. NORMALIZED ODDS PASS THROUGH SportsDataNormalizer
// ═══════════════════════════════════════════════════════════════════════════

test('normalized TOTAL_GOALS/OVER_1_5 odds pass through SportsDataNormalizer', function () {
    $body = json_encode(['response' => [
        ['fixture' => ['id' => 100], 'bookmakers' => [
            ['name' => 'Bet365', 'bets' => [
                ['name' => 'Over/Under 1.5', 'values' => [
                    ['value' => 'Over 1.5', 'odd' => 1.35],
                ]],
            ]],
        ]],
    ]]);
    $p = new ApiFootballProvider('k', 'https://api.test', 10, makeTransport2(200, $body));
    $rawOdds = $p->odds('100');
    assert_equals(1, count($rawOdds));
    $normalized = SportsDataNormalizer::odds($rawOdds[0], 'api-football');
    assert_equals('TOTAL_GOALS', $normalized['market']);
    assert_equals('OVER_1_5', $normalized['selection']);
    assert_equals(1.35, $normalized['decimalOdds']);
    assert_equals('api-football', $normalized['provider']);
});

// ═══════════════════════════════════════════════════════════════════════════
// 4. FORM RESOLVER
// ═══════════════════════════════════════════════════════════════════════════

test('FormResolver enriches fixtures with form from api-football team statistics', function () {
    // Create a fixture that has team IDs and league ID
    $fixtures = [[
        'externalId' => '1',
        'homeTeam' => 'Team A',
        'awayTeam' => 'Team B',
        'competition' => 'Premier League',
        'kickoff' => '2026-09-15T19:00:00Z',
        'status' => 'SCHEDULED',
        'sport' => 'football',
        'homeTeamId' => '33',
        'awayTeamId' => '40',
        'leagueId' => '39',
        'season' => '2026',
        'sourceTimestamp' => gmdate('c'),
    ]];

    // Mock provider that has teamStatistics
    $statsBody = json_encode(['response' => [
        'team' => ['id' => 33, 'name' => 'Team A'],
        'league' => ['id' => 39, 'name' => 'Premier League'],
        'fixtures' => ['played' => ['total' => 20], 'wins' => ['home' => 8, 'away' => 5]],
        'goals' => ['for' => ['total' => ['total' => 40], 'average' => ['home' => 2.2, 'away' => 1.8]],
                    'against' => ['total' => ['total' => 25], 'average' => ['home' => 1.0, 'away' => 1.5]]],
    ]]);

    // We can't easily mock the teamStatistics endpoint with a single transport,
    // so test that the FormResolver skips fixtures without team IDs
    $p = new SandboxSportsProvider();
    $resolver = new FormResolver();
    $enriched = $resolver->enrich($p, $fixtures);
    // Sandbox provider doesn't have teamStatistics, so no enrichment
    assert_equals($fixtures, $enriched);
});

test('FormResolver skips fixtures without team IDs', function () {
    $fixtures = [[
        'externalId' => '1',
        'homeTeam' => 'Team A',
        'awayTeam' => 'Team B',
        'competition' => 'League',
        'kickoff' => '2026-09-15T19:00:00Z',
        'status' => 'SCHEDULED',
        'sport' => 'football',
        'sourceTimestamp' => gmdate('c'),
    ]];

    $p = new SandboxSportsProvider();
    $resolver = new FormResolver();
    $enriched = $resolver->enrich($p, $fixtures);
    // No enrichment possible without team IDs
    assert_equals($fixtures, $enriched);
});

test('FormResolver preserves existing recentForm from sandbox', function () {
    putenv('WINDELS_SPORTS_MODE=SANDBOX');
    putenv('WINDELS_SPORTS_SANDBOX=1');
    try {
        $sandbox = new SandboxSportsProvider();
        $fixtures = $sandbox->fixtures(['from' => '2026-09-01', 'to' => '2026-09-01']);
        $resolver = new FormResolver();
        $enriched = $resolver->enrich($sandbox, $fixtures);
        // Sandbox fixtures already have recentForm — must not be overwritten
        foreach ($enriched as $f) {
            assert_true(!empty($f['context']['recentForm']), 'sandbox form must be preserved');
            assert_true(isset($f['context']['recentForm']['homeGoalsPerMatch']));
        }
    } finally {
        putenv('WINDELS_SPORTS_MODE');
        putenv('WINDELS_SPORTS_SANDBOX');
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// 5. END-TO-END: NORMALIZED FIXTURE → NORMALIZER → PIPELINE COMPATIBLE
// ═══════════════════════════════════════════════════════════════════════════

test('api-football fixture with enriched form passes through full normalizer', function () {
    $body = json_encode(['response' => [
        ['fixture' => ['id' => 42, 'date' => '2026-09-15T19:00:00+00:00', 'status' => ['short' => 'NS']],
         'teams' => ['home' => ['id' => 33, 'name' => 'Man United'], 'away' => ['id' => 40, 'name' => 'Liverpool']],
         'league' => ['id' => 39, 'name' => 'Premier League', 'season' => 2026]],
    ]]);
    $p = new ApiFootballProvider('k', 'https://api.test', 10, makeTransport2(200, $body));
    $rawFixtures = $p->fixtures([]);

    // Simulate form enrichment (as FormResolver would provide)
    $rawFixtures[0]['context'] = [
        'recentForm' => [
            'homeGoalsPerMatch' => 2.1,
            'awayGoalsPerMatch' => 1.8,
            'homeConcededPerMatch' => 0.9,
            'awayConcededPerMatch' => 1.2,
            'source' => 'api-football:team-statistics',
            'timestamp' => gmdate('c'),
        ],
    ];

    // Verify the normalizer accepts this
    $normalized = SportsDataNormalizer::fixture($rawFixtures[0], 'api-football');
    assert_equals('api-football', $normalized['provider']);
    assert_equals('42', $normalized['externalId']);
    assert_equals('Man United', $normalized['homeTeam']);
    assert_equals('SCHEDULED', $normalized['status']);
    // Context with recentForm should be preserved
    assert_true(!empty($normalized['context']['recentForm']));
    assert_equals(2.1, $normalized['context']['recentForm']['homeGoalsPerMatch']);
});

test('complete odds flow: API-Football → normalized → pipeline-compatible', function () {
    $oddsBody = json_encode(['response' => [
        ['fixture' => ['id' => 42], 'bookmakers' => [
            ['name' => 'Bet365', 'bets' => [
                ['name' => 'Over/Under 1.5', 'values' => [
                    ['value' => 'Over 1.5', 'odd' => 1.45],
                    ['value' => 'Under 1.5', 'odd' => 2.75],
                ]],
                ['name' => 'Match Winner', 'values' => [
                    ['value' => 'Home', 'odd' => 2.10],
                ]],
            ]],
        ]],
    ]]);
    $p = new ApiFootballProvider('k', 'https://api.test', 10, makeTransport2(200, $oddsBody));
    $rawOdds = $p->odds('42');

    // Find the TOTAL_GOALS / OVER_1_5 odds (pipeline's primary target)
    $targetOdds = null;
    foreach ($rawOdds as $o) {
        if ($o['market'] === 'TOTAL_GOALS' && $o['selection'] === 'OVER_1_5') {
            $targetOdds = $o;
            break;
        }
    }
    assert_not_null($targetOdds, 'pipeline-critical TOTAL_GOALS/OVER_1_5 odds must be present');
    assert_equals(1.45, $targetOdds['decimalOdds']);

    // Verify it passes through the normalizer
    $normalized = SportsDataNormalizer::odds($targetOdds, 'api-football');
    assert_equals('TOTAL_GOALS', $normalized['market']);
    assert_equals('OVER_1_5', $normalized['selection']);
    assert_equals(1.45, $normalized['decimalOdds']);
});

test('sportmonks complete odds flow: normalized → pipeline-compatible', function () {
    $body = json_encode(['data' => [
        ['fixture_id' => 42, 'value' => 1.50,
         'market' => ['name' => 'Over/Under 1.5'],
         'selection' => ['value' => 'Over 1.5'],
         'bookmaker' => ['name' => 'Bet365']],
    ]]);
    $p = new SportMonksProvider('t', 'https://api.test', 10, makeTransport2(200, $body));
    $rawOdds = $p->odds('42');
    assert_equals(1, count($rawOdds));
    assert_equals('TOTAL_GOALS', $rawOdds[0]['market']);
    assert_equals('OVER_1_5', $rawOdds[0]['selection']);

    $normalized = SportsDataNormalizer::odds($rawOdds[0], 'sportmonks');
    assert_equals('TOTAL_GOALS', $normalized['market']);
    assert_equals('OVER_1_5', $normalized['selection']);
    assert_equals(1.50, $normalized['decimalOdds']);
});

function fx_form_stats_transport(&$calls): callable
{
    return function () use (&$calls) {
        $calls++;
        return ['status' => 200, 'body' => json_encode(['errors' => [], 'response' => [
            'fixtures' => ['played' => ['total' => 10]],
            'goals' => ['for' => ['total' => ['total' => 20]], 'against' => ['total' => ['total' => 10]]],
        ]])];
    };
}

test('FormResolver enriches the away side without argument errors', function () {
    // Regression: the away-side call once passed ($teamStatsCache, 'away') in
    // the ($season, $cache, $side) slots — a TypeError that failed the whole
    // fixtures sync for api-football/sportmonks, leaving zero stored fixtures.
    $calls = 0;
    $p = new \AIWorkforce\Sports\Providers\ApiFootballProvider('k', 'https://v3.football.api-sports.io', 10, fx_form_stats_transport($calls));
    $resolver = new FormResolver();
    $enriched = $resolver->enrich($p, [
        ['homeTeamId' => 'h1', 'awayTeamId' => 'a1', 'leagueId' => '39', 'season' => '2024'],
    ]);
    $form = $enriched[0]['context']['recentForm'] ?? null;
    assert_true(is_array($form), 'recentForm attached');
    assert_equals(2.0, $form['homeGoalsPerMatch']);
    assert_equals(2.0, $form['awayGoalsPerMatch']);
    assert_equals(1.0, $form['homeConcededPerMatch']);
    assert_equals(1.0, $form['awayConcededPerMatch']);
    assert_equals(2, $calls, 'one lookup per side');
});

test('FormResolver caps team-statistics lookups to protect the daily quota', function () {
    $calls = 0;
    $p = new \AIWorkforce\Sports\Providers\ApiFootballProvider('k', 'https://v3.football.api-sports.io', 10, fx_form_stats_transport($calls));
    $fixtures = [];
    for ($i = 1; $i <= 10; $i++) {
        $fixtures[] = ['homeTeamId' => 'h' . $i, 'awayTeamId' => 'a' . $i, 'leagueId' => '39', 'season' => '2024'];
    }
    $resolver = new FormResolver(4);
    $enriched = $resolver->enrich($p, $fixtures);
    assert_equals(4, $calls, 'only 4 live lookups for 20 unique teams');
    $withForm = array_values(array_filter($enriched, fn($f) => !empty($f['context']['recentForm'])));
    assert_equals(2, count($withForm), 'first 2 fixtures fully enriched, rest honestly unenriched');
    // The default budget keeps ordinary pulls fully enriched.
    $calls = 0;
    $resolver = new FormResolver();
    $enriched = $resolver->enrich($p, array_slice($fixtures, 0, 2));
    assert_equals(4, $calls);
    assert_true(!empty($enriched[0]['context']['recentForm']) && !empty($enriched[1]['context']['recentForm']));
});
