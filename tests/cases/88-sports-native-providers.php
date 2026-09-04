<?php
/**
 * Tests for the three native Sports Intelligence providers:
 *   - API-Football (api-football.com)
 *   - TheSportsDB (thesportsdb.com)
 *   - SportMonks (sportmonks.com)
 */
use AIWorkforce\Sports\Providers\ApiFootballProvider;
use AIWorkforce\Sports\Providers\TheSportsDbProvider;
use AIWorkforce\Sports\Providers\SportMonksProvider;
use AIWorkforce\Sports\Providers\FootballApiProvider;
use AIWorkforce\Sports\Providers\ProviderException;
use AIWorkforce\Sports\Providers\SportsDataProvider;
use AIWorkforce\Sports\Providers\SportsProviderManager;
use AIWorkforce\Sports\SportsDataNormalizer;

// ─── Helper: build a fake HTTP transport ────────────────────────────────────

function makeTransport(int $status, string $body = '{}'): callable
{
    return fn(string $url, array $headers) => ['status' => $status, 'body' => $body];
}

// ═══════════════════════════════════════════════════════════════════════════
// 1. API-FOOTBALL PROVIDER
// ═══════════════════════════════════════════════════════════════════════════

test('api-football provider reports online health on valid status response', function () {
    $body = json_encode(['response' => ['requests' => 42, 'limit_day' => 100]]);
    $p = new ApiFootballProvider('test-key', 'https://v3.football.api-sports.io', 10, makeTransport(200, $body));
    $health = $p->health();
    assert_equals('ONLINE', $health['status']);
    assert_equals('api-football', $p->id());
    assert_equals(42, $health['requestsToday']);
    assert_equals(100, $health['limitDaily']);
});

test('api-football provider classifies auth failure', function () {
    $p = new ApiFootballProvider('bad-key', 'https://v3.football.api-sports.io', 10, makeTransport(401));
    $health = $p->health();
    assert_equals('AUTHENTICATION_ERROR', $health['status']);
});

test('api-football maps fixtures correctly', function () {
    $body = json_encode(['response' => [
        [
            'fixture' => ['id' => 12345, 'date' => '2026-09-15T19:00:00+00:00', 'status' => ['short' => 'NS'], 'referee' => 'John Doe', 'venue' => ['name' => 'Old Trafford']],
            'teams' => [
                'home' => ['id' => 33, 'name' => 'Manchester United', 'logo' => 'https://logo/33.png'],
                'away' => ['id' => 40, 'name' => 'Liverpool', 'logo' => 'https://logo/40.png'],
            ],
            'league' => ['id' => 39, 'name' => 'Premier League', 'season' => 2026],
        ],
    ]]);
    $p = new ApiFootballProvider('test-key', 'https://v3.football.api-sports.io', 10, makeTransport(200, $body));
    $fixtures = $p->fixtures(['from' => '2026-09-15', 'to' => '2026-09-15']);
    assert_equals(1, count($fixtures));
    $f = $fixtures[0];
    assert_equals('12345', $f['externalId']);
    assert_equals('Manchester United', $f['homeTeam']);
    assert_equals('Liverpool', $f['awayTeam']);
    assert_equals('Premier League', $f['competition']);
    assert_equals('SCHEDULED', $f['status']);
    assert_equals('football', $f['sport']);
    assert_equals('Old Trafford', $f['venue']);
    assert_equals('John Doe', $f['referee']);
    assert_equals('33', $f['homeTeamId']);
    assert_equals('40', $f['awayTeamId']);
});

test('api-football maps live status correctly', function () {
    $body = json_encode(['response' => [
        ['fixture' => ['id' => 1, 'date' => '2026-09-15T19:00:00+00:00', 'status' => ['short' => '2H']],
         'teams' => ['home' => ['id' => 1, 'name' => 'A'], 'away' => ['id' => 2, 'name' => 'B']],
         'league' => ['id' => 1, 'name' => 'Test', 'season' => 2026]],
    ]]);
    $p = new ApiFootballProvider('k', 'https://api.test', 10, makeTransport(200, $body));
    $fixtures = $p->fixtures([]);
    assert_equals('LIVE', $fixtures[0]['status']);
});

test('api-football maps odds correctly', function () {
    $body = json_encode(['response' => [
        ['fixture' => ['id' => 123], 'bookmakers' => [
            ['name' => 'Bet365', 'bets' => [
                ['name' => 'Match Winner', 'values' => [
                    ['value' => 'Home', 'odd' => 1.85],
                    ['value' => 'Draw', 'odd' => 3.40],
                    ['value' => 'Away', 'odd' => 4.50],
                ]],
            ]],
        ]],
    ]]);
    $p = new ApiFootballProvider('k', 'https://api.test', 10, makeTransport(200, $body));
    $odds = $p->odds('123');
    assert_equals(3, count($odds));
    // Providers emit the pipeline's canonical market/selection keys
    // (see 89-sports-odds-form-e2e.php: 'Match Winner' → MATCH_RESULT / HOME).
    assert_equals('MATCH_RESULT', $odds[0]['market']);
    assert_equals('HOME', $odds[0]['selection']);
    assert_equals(1.85, $odds[0]['decimalOdds']);
    assert_equals('Bet365', $odds[0]['bookmaker']);
});

test('api-football maps results correctly', function () {
    $body = json_encode(['response' => [
        ['fixture' => ['id' => 123, 'status' => ['short' => 'FT']], 'goals' => ['home' => 2, 'away' => 1],
         'score' => ['halftime' => ['home' => 1, 'away' => 0]]],
    ]]);
    $p = new ApiFootballProvider('k', 'https://api.test', 10, makeTransport(200, $body));
    $results = $p->results('123');
    assert_equals(1, count($results));
    assert_equals('FINISHED', $results[0]['status']);
    assert_equals(2, $results[0]['homeScore']);
    assert_equals(1, $results[0]['awayScore']);
    assert_equals(1, $results[0]['halfTimeHome']);
    assert_equals(0, $results[0]['halfTimeAway']);
});

test('api-football skips fixtures with missing essential fields', function () {
    $body = json_encode(['response' => [
        ['fixture' => ['id' => 1, 'date' => null], 'teams' => ['home' => ['name' => 'A'], 'away' => ['name' => 'B']], 'league' => ['name' => 'L']],
        ['fixture' => ['id' => 2, 'date' => '2026-09-15T12:00:00Z'], 'teams' => ['home' => ['id' => 1, 'name' => 'Home'], 'away' => ['id' => 2, 'name' => 'Away']], 'league' => ['id' => 1, 'name' => 'League', 'season' => 2026]],
    ]]);
    $p = new ApiFootballProvider('k', 'https://api.test', 10, makeTransport(200, $body));
    $fixtures = $p->fixtures([]);
    assert_equals(1, count($fixtures));
    assert_equals('Home', $fixtures[0]['homeTeam']);
});

test('api-football throws ProviderException on rate limit', function () {
    $p = new ApiFootballProvider('k', 'https://api.test', 10, makeTransport(429));
    try { $p->fixtures([]); assert_true(false); }
    catch (ProviderException $e) { assert_equals(ProviderException::RATE_LIMITED, $e->status); }
});

// ═══════════════════════════════════════════════════════════════════════════
// 2. THESPORTSDB PROVIDER
// ═══════════════════════════════════════════════════════════════════════════

test('thesportsdb provider reports online health on valid response', function () {
    $body = json_encode(['events' => []]);
    $p = new TheSportsDbProvider('3', 'https://www.thesportsdb.com/api/v1/json', 10, makeTransport(200, $body));
    $health = $p->health();
    assert_equals('ONLINE', $health['status']);
    assert_equals('thesportsdb', $p->id());
    assert_equals('free', $health['tier']);
});

test('thesportsdb premium tier is reported in health', function () {
    $body = json_encode(['events' => []]);
    $p = new TheSportsDbProvider('premium-key-123', 'https://www.thesportsdb.com/api/v1/json', 10, makeTransport(200, $body));
    $health = $p->health();
    assert_equals('ONLINE', $health['status']);
    assert_equals('premium', $health['tier']);
});

test('thesportsdb maps fixtures correctly', function () {
    $body = json_encode(['events' => [
        [
            'idEvent' => '600123',
            'strEvent' => 'Man United vs Liverpool',
            'strHomeTeam' => 'Manchester United',
            'strAwayTeam' => 'Liverpool',
            'strLeague' => 'English Premier League',
            'idLeague' => '4328',
            'dateEvent' => '2026-09-15',
            'strTime' => '20:00:00',
            'strStatus' => 'Scheduled',
            'idHomeTeam' => '1001',
            'idAwayTeam' => '1002',
            'strVenue' => 'Old Trafford',
            'strSeason' => '2026-2027',
        ],
    ]]);
    $p = new TheSportsDbProvider('3', 'https://www.thesportsdb.com/api/v1/json', 10, makeTransport(200, $body));
    $fixtures = $p->fixtures(['from' => '2026-09-15', 'to' => '2026-09-15']);
    assert_equals(1, count($fixtures));
    $f = $fixtures[0];
    assert_equals('600123', $f['externalId']);
    assert_equals('Manchester United', $f['homeTeam']);
    assert_equals('Liverpool', $f['awayTeam']);
    assert_equals('English Premier League', $f['competition']);
    assert_equals('SCHEDULED', $f['status']);
    assert_equals('football', $f['sport']);
    assert_equals('4328', $f['leagueId']);
    assert_equals('2026-2027', $f['season']);
});

test('thesportsdb maps finished results correctly', function () {
    $body = json_encode(['event' => [
        'idEvent' => '600123',
        'strHomeTeam' => 'Team A',
        'strAwayTeam' => 'Team B',
        'intHomeScore' => '3',
        'intAwayScore' => '1',
        'intHomeHalfScore' => '1',
        'intAwayHalfScore' => '0',
        'strStatus' => 'Match Finished',
    ]]);
    $p = new TheSportsDbProvider('3', 'https://www.thesportsdb.com/api/v1/json', 10, makeTransport(200, $body));
    $results = $p->results('600123');
    assert_equals(1, count($results));
    assert_equals('FINISHED', $results[0]['status']);
    assert_equals(3, $results[0]['homeScore']);
    assert_equals(1, $results[0]['awayScore']);
    assert_equals(1, $results[0]['halfTimeHome']);
    assert_equals(0, $results[0]['halfTimeAway']);
});

test('thesportsdb returns empty array for odds (not supported)', function () {
    $p = new TheSportsDbProvider('3', 'https://www.thesportsdb.com/api/v1/json', 10, makeTransport(200, '{}'));
    $odds = $p->odds('any-id');
    assert_equals([], $odds);
});

test('thesportsdb skips fixtures with missing team names', function () {
    $body = json_encode(['events' => [
        ['idEvent' => '1', 'strHomeTeam' => null, 'strAwayTeam' => 'B', 'dateEvent' => '2026-09-15'],
        ['idEvent' => '2', 'strHomeTeam' => 'A', 'strAwayTeam' => 'B', 'dateEvent' => '2026-09-15', 'strTime' => '12:00:00', 'strLeague' => 'L'],
    ]]);
    $p = new TheSportsDbProvider('3', 'https://www.thesportsdb.com/api/v1/json', 10, makeTransport(200, $body));
    $fixtures = $p->fixtures([]);
    assert_equals(1, count($fixtures));
    assert_equals('2', $fixtures[0]['externalId']);
});

// ═══════════════════════════════════════════════════════════════════════════
// 3. SPORTMONKS PROVIDER
// ═══════════════════════════════════════════════════════════════════════════

test('sportmonks provider reports online health on valid response', function () {
    $body = json_encode(['data' => []]);
    $p = new SportMonksProvider('test-token', 'https://api.sportmonks.com/v3/football', 10, makeTransport(200, $body));
    $health = $p->health();
    assert_equals('ONLINE', $health['status']);
    assert_equals('sportmonks', $p->id());
});

test('sportmonks maps fixtures correctly', function () {
    $body = json_encode(['data' => [
        [
            'id' => 98765,
            'starting_at' => '2026-09-15T19:30:00+00:00',
            'status' => 6,
            'participants' => [
                ['id' => 55, 'name' => 'Real Madrid', 'meta' => ['location' => 'home'], 'image_path' => 'https://logo/55.png'],
                ['id' => 60, 'name' => 'Barcelona', 'meta' => ['location' => 'away'], 'image_path' => 'https://logo/60.png'],
            ],
            'league' => ['id' => 8, 'name' => 'La Liga'],
            'season' => ['id' => 2026],
            'venue' => ['name' => 'Santiago Bernabéu'],
            'referee' => ['name' => 'Mateu Lahoz'],
            'round' => ['name' => 'Round 5'],
        ],
    ]]);
    $p = new SportMonksProvider('token', 'https://api.sportmonks.com/v3/football', 10, makeTransport(200, $body));
    $fixtures = $p->fixtures(['from' => '2026-09-15', 'to' => '2026-09-15']);
    assert_equals(1, count($fixtures));
    $f = $fixtures[0];
    assert_equals('98765', $f['externalId']);
    assert_equals('Real Madrid', $f['homeTeam']);
    assert_equals('Barcelona', $f['awayTeam']);
    assert_equals('La Liga', $f['competition']);
    assert_equals('SCHEDULED', $f['status']);
    assert_equals('football', $f['sport']);
    assert_equals('Santiago Bernabéu', $f['venue']);
    assert_equals('Mateu Lahoz', $f['referee']);
    assert_equals('55', $f['homeTeamId']);
    assert_equals('60', $f['awayTeamId']);
    assert_equals('Round 5', $f['round']);
});

test('sportmonks maps live status codes correctly', function () {
    $body = json_encode(['data' => [
        ['id' => 1, 'starting_at' => '2026-09-15T19:00:00Z', 'status' => 7,
         'participants' => [['name' => 'A', 'meta' => ['location' => 'home']], ['name' => 'B', 'meta' => ['location' => 'away']]],
         'league' => ['name' => 'L'], 'season' => ['id' => 1]],
    ]]);
    $p = new SportMonksProvider('t', 'https://api.test', 10, makeTransport(200, $body));
    $fixtures = $p->fixtures([]);
    assert_equals('LIVE', $fixtures[0]['status']);
});

test('sportmonks maps finished status codes correctly', function () {
    $body = json_encode(['data' => [
        ['id' => 1, 'starting_at' => '2026-09-15T19:00:00Z', 'status' => 100,
         'participants' => [['name' => 'A', 'meta' => ['location' => 'home']], ['name' => 'B', 'meta' => ['location' => 'away']]],
         'league' => ['name' => 'L'], 'season' => ['id' => 1]],
    ]]);
    $p = new SportMonksProvider('t', 'https://api.test', 10, makeTransport(200, $body));
    $fixtures = $p->fixtures([]);
    assert_equals('FINISHED', $fixtures[0]['status']);
});

test('sportmonks maps results with scores correctly', function () {
    $body = json_encode(['data' => [
        ['id' => 98765, 'status' => 100,
         'scores' => ['home' => ['score' => 3, 'current' => 3, 'halftime' => ['score' => 2, 'current' => 2]],
                      'away' => ['score' => 1, 'current' => 1, 'halftime' => ['score' => 0, 'current' => 0]]]],
    ]]);
    $p = new SportMonksProvider('t', 'https://api.test', 10, makeTransport(200, $body));
    $results = $p->results('98765');
    assert_equals(1, count($results));
    assert_equals('FINISHED', $results[0]['status']);
    assert_equals(3, $results[0]['homeScore']);
    assert_equals(1, $results[0]['awayScore']);
    assert_equals(2, $results[0]['halfTimeHome']);
    assert_equals(0, $results[0]['halfTimeAway']);
});

test('sportmonks maps odds with optional add-on', function () {
    $body = json_encode(['data' => [
        ['fixture_id' => 100, 'value' => 1.85,
         'market' => ['name' => 'Full Time Result'],
         'selection' => ['value' => 'Home'],
         'bookmaker' => ['name' => 'William Hill']],
        ['fixture_id' => 100, 'value' => 3.50,
         'market' => ['name' => 'Full Time Result'],
         'selection' => ['value' => 'Draw'],
         'bookmaker' => ['name' => 'William Hill']],
    ]]);
    $p = new SportMonksProvider('t', 'https://api.test', 10, makeTransport(200, $body));
    $odds = $p->odds('100');
    assert_equals(2, count($odds));
    // Canonical market keys ('Full Time Result' → MATCH_RESULT / HOME).
    assert_equals('MATCH_RESULT', $odds[0]['market']);
    assert_equals('HOME', $odds[0]['selection']);
    assert_equals(1.85, $odds[0]['decimalOdds']);
    assert_equals('William Hill', $odds[0]['bookmaker']);
});

test('sportmonks returns empty odds when add-on not subscribed', function () {
    $p = new SportMonksProvider('t', 'https://api.test', 10, makeTransport(403));
    $odds = $p->odds('100');
    assert_equals([], $odds);
});

test('sportmonks handles auth failure gracefully', function () {
    $p = new SportMonksProvider('bad-token', 'https://api.test', 10, makeTransport(401));
    $health = $p->health();
    assert_equals('AUTHENTICATION_ERROR', $health['status']);
});

// ═══════════════════════════════════════════════════════════════════════════
// 3b. SPORTMONKS ROUND ENDPOINT (single request → fixtures + odds + results)
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Modeled on the real SportMonks v3 GET /rounds/{id} response
 * (Brazil Serie A, round 25). Note: the participants array is NOT
 * home-first — meta.location decides the sides.
 */
function sportmonksRoundPayload(): array
{
    $fulltime = ['id' => 1, 'name' => 'Fulltime Result', 'developer_name' => 'FULLTIME_RESULT', 'has_winning_calculations' => true];
    $bet365 = ['id' => 2, 'legacy_id' => 2, 'name' => 'bet365'];
    return [
        'id' => 396698,
        'sport_id' => 1,
        'league_id' => 648,
        'season_id' => 26763,
        'stage_id' => 77479548,
        'name' => '25',
        'finished' => true,
        'is_current' => false,
        'starting_at' => '2026-08-29',
        'ending_at' => '2026-08-31',
        'games_in_current_week' => false,
        'fixtures' => [
            [
                'id' => 19621838,
                'league_id' => 648,
                'season_id' => 26763,
                'round_id' => 396698,
                'state_id' => 5,
                'venue_id' => 10494,
                'name' => 'Corinthians vs Santos',
                'starting_at' => '2026-08-30 19:00:00',
                'starting_at_timestamp' => 1788116400,
                'result_info' => 'Santos won after full-time.',
                'leg' => '1/1',
                'length' => 90,
                'has_odds' => true,
                'has_premium_odds' => true,
                'state' => ['id' => 5, 'state' => 'FT', 'name' => 'Full Time', 'short_name' => 'FT', 'developer_name' => 'FT'],
                'venue' => ['id' => 10494, 'name' => 'Neo Química Arena'],
                'odds' => [
                    ['id' => 242217199009, 'fixture_id' => 19621838, 'market_id' => 1, 'bookmaker_id' => 2, 'label' => 'Away', 'value' => '4.50', 'original_label' => '2', 'probability' => '22.22%', 'winning' => true, 'latest_bookmaker_update' => '2026-08-30 18:50:47', 'market' => $fulltime, 'bookmaker' => $bet365],
                    ['id' => 242217199008, 'fixture_id' => 19621838, 'market_id' => 1, 'bookmaker_id' => 2, 'label' => 'Draw', 'value' => '3.30', 'original_label' => 'Draw', 'probability' => '30.3%', 'winning' => false, 'latest_bookmaker_update' => '2026-08-30 18:50:47', 'market' => $fulltime, 'bookmaker' => $bet365],
                    ['id' => 242217199007, 'fixture_id' => 19621838, 'market_id' => 1, 'bookmaker_id' => 2, 'label' => 'Home', 'value' => '1.90', 'original_label' => '1', 'probability' => '52.63%', 'winning' => false, 'latest_bookmaker_update' => '2026-08-30 18:50:47', 'market' => $fulltime, 'bookmaker' => $bet365],
                ],
                'participants' => [
                    ['id' => 3684, 'name' => 'Santos', 'short_code' => 'STS', 'image_path' => 'https://cdn.sportmonks.com/images/soccer/teams/4/3684.png', 'meta' => ['location' => 'away', 'winner' => true, 'position' => 14]],
                    ['id' => 303, 'name' => 'Corinthians', 'short_code' => 'CTH', 'image_path' => 'https://cdn.sportmonks.com/images/soccer/teams/15/303.png', 'meta' => ['location' => 'home', 'winner' => false, 'position' => 10]],
                ],
                'scores' => ['home' => ['score' => 0, 'halftime' => ['score' => 0]], 'away' => ['score' => 1, 'halftime' => ['score' => 1]]],
            ],
            [
                'id' => 19621837,
                'league_id' => 648,
                'season_id' => 26763,
                'round_id' => 396698,
                'state_id' => 5,
                'name' => 'Mirassol vs Palmeiras',
                'starting_at' => '2026-08-30 21:30:00',
                'starting_at_timestamp' => 1788125400,
                'result_info' => 'Game ended in draw.',
                'state' => ['id' => 5, 'state' => 'FT', 'name' => 'Full Time', 'short_name' => 'FT', 'developer_name' => 'FT'],
                'venue' => ['id' => 7424, 'name' => 'Maião'],
                'odds' => [
                    ['id' => 242217061156, 'fixture_id' => 19621837, 'label' => 'Home', 'value' => '3.50', 'original_label' => '1', 'probability' => '28.57%', 'winning' => false, 'market' => $fulltime, 'bookmaker' => $bet365],
                    ['id' => 242217061157, 'fixture_id' => 19621837, 'label' => 'Draw', 'value' => '3.40', 'original_label' => 'Draw', 'probability' => '29.41%', 'winning' => true, 'market' => $fulltime, 'bookmaker' => $bet365],
                    ['id' => 242217061158, 'fixture_id' => 19621837, 'label' => 'Away', 'value' => '2.15', 'original_label' => '2', 'probability' => '46.51%', 'winning' => false, 'market' => $fulltime, 'bookmaker' => $bet365],
                ],
                'participants' => [
                    ['id' => 11126, 'name' => 'Mirassol', 'short_code' => 'MIR', 'image_path' => 'https://cdn.sportmonks.com/images/soccer/teams/22/11126.png', 'meta' => ['location' => 'home', 'winner' => false, 'position' => 18]],
                    ['id' => 3422, 'name' => 'Palmeiras', 'short_code' => 'PAL', 'image_path' => 'https://cdn.sportmonks.com/images/soccer/teams/30/3422.png', 'meta' => ['location' => 'away', 'winner' => false, 'position' => 1]],
                ],
                'scores' => ['home' => ['score' => 1], 'away' => ['score' => 1]],
            ],
        ],
        'league' => ['id' => 648, 'sport_id' => 1, 'country_id' => 5, 'name' => 'Serie A', 'active' => true, 'short_code' => 'BRA CB', 'type' => 'league', 'sub_type' => 'domestic', 'country' => ['id' => 5, 'name' => 'Brazil', 'iso2' => 'BR', 'iso3' => 'BRA']],
    ];
}

test('sportmonks round hits /rounds/{id} with nested includes and maps metadata', function () {
    $captured = null;
    $transport = function (string $url, array $headers) use (&$captured) {
        $captured = $url;
        return ['status' => 200, 'body' => json_encode(['data' => sportmonksRoundPayload()])];
    };
    $p = new SportMonksProvider('token', 'https://api.sportmonks.com/v3/football', 10, $transport);
    $round = $p->round('396698');

    assert_true(is_string($captured), 'transport was not called');
    assert_true(str_contains($captured, '/rounds/396698?include='), 'expected /rounds/{id}?include= in ' . $captured);
    assert_true(str_contains($captured, rawurlencode('fixtures.odds.market')), 'expected nested odds include');
    assert_true(str_contains($captured, rawurlencode('fixtures.participants')), 'expected participants include');
    assert_true(str_contains($captured, 'api_token='), 'expected api_token query param');

    assert_equals('396698', $round['roundId']);
    assert_equals('25', $round['name']);
    assert_equals('648', $round['leagueId']);
    assert_equals('Serie A', $round['league']);
    assert_equals('26763', $round['season']);
    assert_equals('2026-08-29', $round['startingAt']);
    assert_equals('2026-08-31', $round['endingAt']);
    assert_true($round['finished'], 'round should be finished');
});

test('sportmonks round maps fixtures using meta.location (participants are not home-first)', function () {
    $p = new SportMonksProvider('token', 'https://api.test', 10, makeTransport(200, json_encode(['data' => sportmonksRoundPayload()])));
    $round = $p->round('396698');
    assert_equals(2, count($round['fixtures']));

    // Fixture 1: away team (Santos) is listed FIRST in participants.
    $f = $round['fixtures'][0];
    assert_equals('19621838', $f['externalId']);
    assert_equals('Corinthians', $f['homeTeam']);
    assert_equals('Santos', $f['awayTeam']);
    assert_equals('303', $f['homeTeamId']);
    assert_equals('3684', $f['awayTeamId']);
    assert_equals('https://cdn.sportmonks.com/images/soccer/teams/15/303.png', $f['homeTeamLogo']);
    assert_equals('FINISHED', $f['status']);
    assert_equals('football', $f['sport']);
    assert_equals('Neo Química Arena', $f['venue']);
    assert_equals('Serie A', $f['competition']);
    assert_equals('26763', $f['season']);

    $f2 = $round['fixtures'][1];
    assert_equals('19621837', $f2['externalId']);
    assert_equals('Mirassol', $f2['homeTeam']);
    assert_equals('Palmeiras', $f2['awayTeam']);
    assert_equals('FINISHED', $f2['status']);
});

test('sportmonks round maps embedded odds with labels, probability and winning flag', function () {
    $p = new SportMonksProvider('token', 'https://api.test', 10, makeTransport(200, json_encode(['data' => sportmonksRoundPayload()])));
    $round = $p->round('396698');
    assert_equals(6, count($round['odds']));

    // First fixture's odds (original_label 2 / Draw / 1).
    $o = $round['odds'][0];
    assert_equals('MATCH_RESULT', $o['market']);
    assert_equals('AWAY', $o['selection']);
    assert_equals(4.5, $o['decimalOdds']);
    assert_equals('bet365', $o['bookmaker']);
    assert_equals('19621838', $o['fixtureId']);
    assert_close(0.2222, (float) $o['impliedProbability'], 0.0001, 'implied probability');
    assert_true($o['winning'], 'away selection should be the winner');
    assert_equals('2026-08-30 18:50:47', $o['updatedAt']);

    $home = $round['odds'][2];
    assert_equals('HOME', $home['selection']);
    assert_equals(1.9, $home['decimalOdds']);
    assert_false($home['winning']);

    // Second fixture: draw won.
    $draw = $round['odds'][4];
    assert_equals('19621837', $draw['fixtureId']);
    assert_equals('DRAW', $draw['selection']);
    assert_equals(3.4, $draw['decimalOdds']);
    assert_true($draw['winning']);
});

test('sportmonks round maps results from embedded scores', function () {
    $p = new SportMonksProvider('token', 'https://api.test', 10, makeTransport(200, json_encode(['data' => sportmonksRoundPayload()])));
    $round = $p->round('396698');
    assert_equals(2, count($round['results']));
    assert_equals('19621838', $round['results'][0]['externalId']);
    assert_equals('FINISHED', $round['results'][0]['status']);
    assert_equals(0, $round['results'][0]['homeScore']);
    assert_equals(1, $round['results'][0]['awayScore']);
    assert_equals(0, $round['results'][0]['halfTimeHome']);
    assert_equals(1, $round['results'][0]['halfTimeAway']);
});

test('sportmonks round falls back to state_id when the state include is absent', function () {
    $body = json_encode(['data' => [
        'id' => 9, 'league_id' => 1, 'season_id' => 1, 'name' => '1', 'finished' => false,
        'fixtures' => [
            ['id' => 1, 'name' => 'A vs B', 'starting_at' => '2026-09-01 15:00:00', 'state_id' => 1,
             'participants' => [['name' => 'A', 'meta' => ['location' => 'home']], ['name' => 'B', 'meta' => ['location' => 'away']]]],
            ['id' => 2, 'name' => 'C vs D', 'starting_at' => '2026-09-01 15:00:00', 'state_id' => 2,
             'participants' => [['name' => 'C', 'meta' => ['location' => 'home']], ['name' => 'D', 'meta' => ['location' => 'away']]]],
            ['id' => 3, 'name' => 'E vs F', 'starting_at' => '2026-09-01 15:00:00', 'state_id' => 12,
             'participants' => [['name' => 'E', 'meta' => ['location' => 'home']], ['name' => 'F', 'meta' => ['location' => 'away']]]],
            ['id' => 4, 'name' => 'G vs H', 'starting_at' => '2026-09-01 15:00:00', 'state_id' => 10,
             'participants' => [['name' => 'G', 'meta' => ['location' => 'home']], ['name' => 'H', 'meta' => ['location' => 'away']]]],
        ],
    ]]);
    $p = new SportMonksProvider('t', 'https://api.test', 10, makeTransport(200, $body));
    $round = $p->round('9');
    assert_equals(['SCHEDULED', 'LIVE', 'CANCELLED', 'POSTPONED'], array_map(fn($f) => $f['status'], $round['fixtures']));
});

test('sportmonks round propagates provider errors', function () {
    $p = new SportMonksProvider('bad', 'https://api.test', 10, makeTransport(401));
    try { $p->round('1'); assert_true(false, 'expected ProviderException'); }
    catch (ProviderException $e) { assert_equals(ProviderException::AUTHENTICATION_ERROR, $e->status); }

    $r = new SportMonksProvider('t', 'https://api.test', 10, makeTransport(429));
    try { $r->round('1'); assert_true(false, 'expected ProviderException'); }
    catch (ProviderException $e) { assert_equals(ProviderException::RATE_LIMITED, $e->status); }
});

test('sportmonks round rejects malformed payloads', function () {
    $p = new SportMonksProvider('t', 'https://api.test', 10, makeTransport(200, json_encode(['data' => []])));
    try { $p->round('1'); assert_true(false, 'expected ProviderException'); }
    catch (ProviderException $e) { assert_equals(ProviderException::DATA_ERROR, $e->status); }
});

test('sportmonks round retries without odds include when the odds add-on is unavailable', function () {
    // Degraded (no odds) variant of the round payload.
    $plain = sportmonksRoundPayload();
    foreach ($plain['fixtures'] as &$f) { unset($f['odds']); }
    unset($f);
    $urls = [];
    $transport = function (string $url, array $headers) use (&$urls, $plain) {
        $urls[] = $url;
        if (str_contains($url, 'fixtures.odds')) {
            // Include exception 5013: odds add-on not subscribed.
            return ['status' => 400, 'body' => json_encode(['error' => ['status' => 5013, 'name' => 'Include not available']])];
        }
        return ['status' => 200, 'body' => json_encode(['data' => $plain])];
    };
    $p = new SportMonksProvider('token', 'https://api.test', 10, $transport);
    $round = $p->round('396698');
    assert_equals(2, count($urls), 'one retry without odds');
    assert_true(str_contains($urls[0], 'fixtures.odds'), 'first request asks for odds');
    assert_false(str_contains($urls[1], 'fixtures.odds'), 'retry drops the odds include');
    assert_equals('396698', $round['roundId']);
    assert_equals(2, count($round['fixtures']), 'fixtures still mapped from the degraded response');
    assert_equals(0, count($round['odds']), 'degraded round has no odds — none fabricated');
});

test('sportmonks seasonRounds maps the season schedule', function () {
    $body = json_encode(['data' => [
        ['id' => 396698, 'league_id' => 648, 'season_id' => 26763, 'name' => '25', 'finished' => true, 'is_current' => false, 'starting_at' => '2026-08-29', 'ending_at' => '2026-08-31'],
        ['id' => 396701, 'league_id' => 648, 'season_id' => 26763, 'name' => '26', 'finished' => false, 'is_current' => true, 'starting_at' => '2026-09-05', 'ending_at' => '2026-09-07'],
    ]]);
    $p = new SportMonksProvider('t', 'https://api.test', 10, makeTransport(200, $body));
    $rounds = $p->seasonRounds('26763');
    assert_equals(2, count($rounds));
    assert_equals('396698', $rounds[0]['roundId']);
    assert_equals('25', $rounds[0]['name']);
    assert_true($rounds[0]['finished']);
    assert_false($rounds[1]['finished']);
    assert_true($rounds[1]['isCurrent']);
    assert_equals('648', $rounds[0]['leagueId']);
});

test('provider manager falls back to the first provider supporting round()', function () {
    $manager = new SportsProviderManager();
    // api-football has no round() — the guard must skip it and fall back.
    $manager->register(new ApiFootballProvider('k', 'https://api.test', 10, makeTransport(200, '{}')));
    $manager->register(new SportMonksProvider('t', 'https://api.test', 10, makeTransport(200, json_encode(['data' => sportmonksRoundPayload()]))));

    $attempt = $manager->withFallback('round', function (SportsDataProvider $provider) {
        if (!method_exists($provider, 'round')) throw new ProviderException('round endpoint not supported', ProviderException::DATA_ERROR);
        return $provider->round('396698');
    });
    assert_true($attempt['ok'], 'round attempt should succeed');
    assert_equals('sportmonks', $attempt['provider']);
    assert_equals('396698', $attempt['result']['roundId']);
    assert_equals(2, count($attempt['result']['fixtures']));
    assert_equals(6, count($attempt['result']['odds']));
});

// ═══════════════════════════════════════════════════════════════════════════
// 4. LEGACY FootballApiProvider WRAPPER
// ═══════════════════════════════════════════════════════════════════════════

test('legacy FootballApiProvider delegates to ApiFootballProvider for api-football kind', function () {
    $body = json_encode(['response' => ['requests' => 10, 'limit_day' => 100]]);
    $p = new FootballApiProvider('api-football', 'https://v3.football.api-sports.io', 'test-key', 'api-football', 10, makeTransport(200, $body));
    assert_equals('api-football', $p->id());
    $health = $p->health();
    assert_equals('ONLINE', $health['status']);
});

test('legacy FootballApiProvider delegates to TheSportsDbProvider for thesportsdb kind', function () {
    $body = json_encode(['events' => []]);
    $p = new FootballApiProvider('thesportsdb', 'https://www.thesportsdb.com/api/v1/json', '3', 'thesportsdb', 10, makeTransport(200, $body));
    assert_equals('thesportsdb', $p->id());
    $health = $p->health();
    assert_equals('ONLINE', $health['status']);
});

test('legacy FootballApiProvider delegates to SportMonksProvider for sportmonks kind', function () {
    $body = json_encode(['data' => []]);
    $p = new FootballApiProvider('sportmonks', 'https://api.sportmonks.com/v3/football', 'test-token', 'sportmonks', 10, makeTransport(200, $body));
    assert_equals('sportmonks', $p->id());
    $health = $p->health();
    assert_equals('ONLINE', $health['status']);
});

// ═══════════════════════════════════════════════════════════════════════════
// 5. PROVIDER MANAGER WITH ALL THREE NATIVE PROVIDERS
// ═══════════════════════════════════════════════════════════════════════════

test('provider manager falls back through native providers in order', function () {
    $manager = new SportsProviderManager();
    $down = new ApiFootballProvider('bad', 'https://api.test', 10, makeTransport(401));
    $down->health(); // trigger offline state

    // SportMonks-shaped payload for the SportMonks mock (its own parsing is
    // covered above); only the manager's fallback ORDER is under test here.
    $sportmonksFixture = json_encode(['data' => [
        ['id' => 1, 'starting_at' => '2026-09-15T12:00:00+00:00', 'status' => 6,
         'participants' => [
             ['id' => 1, 'name' => 'Home', 'meta' => ['location' => 'home']],
             ['id' => 2, 'name' => 'Away', 'meta' => ['location' => 'away']],
         ],
         'league' => ['id' => 1, 'name' => 'League'], 'season' => ['id' => 2026]],
    ]]);
    $sm = new SportMonksProvider('token', 'https://api.test', 10, makeTransport(200, $sportmonksFixture));
    $sm->health(); // should be ONLINE

    $manager->register($down);
    $manager->register($sm);
    $out = $manager->withFallback('fixtures', fn($p) => $p->fixtures([]));
    assert_true($out['ok']);
    assert_equals('sportmonks', $out['provider']);
    assert_equals(1, count($out['result']));
});

test('all three providers can be registered and health-checked', function () {
    $apiFootballBody = json_encode(['response' => ['requests' => 5, 'limit_day' => 100]]);
    $thesportsdbBody = json_encode(['events' => []]);
    $sportmonksBody = json_encode(['data' => []]);

    $manager = new SportsProviderManager();
    $manager->register(new ApiFootballProvider('key1', 'https://api.test', 10, makeTransport(200, $apiFootballBody)));
    $manager->register(new TheSportsDbProvider('3', 'https://api.test', 10, makeTransport(200, $thesportsdbBody)));
    $manager->register(new SportMonksProvider('token', 'https://api.test', 10, makeTransport(200, $sportmonksBody)));

    assert_true($manager->configured());
    assert_equals(3, count($manager->all()));

    $health = $manager->health();
    assert_equals('ONLINE', $health['api-football']['status']);
    assert_equals('ONLINE', $health['thesportsdb']['status']);
    assert_equals('ONLINE', $health['sportmonks']['status']);
});

test('preferred provider is tried first in fallback chain', function () {
    $manager = new SportsProviderManager();
    $af = new ApiFootballProvider('k', 'https://api.test', 10, makeTransport(200, json_encode(['response' => [['fixture' => ['id' => 1, 'date' => '2026-09-15T12:00:00Z', 'status' => ['short' => 'NS']], 'teams' => ['home' => ['id' => 1, 'name' => 'A'], 'away' => ['id' => 2, 'name' => 'B']], 'league' => ['id' => 1, 'name' => 'L', 'season' => 2026]]]])));
    $af->health(); // ONLINE
    $sm = new SportMonksProvider('t', 'https://api.test', 10, makeTransport(200, json_encode(['data' => []])));
    $sm->health(); // ONLINE
    $manager->register($af);
    $manager->register($sm);

    $out = $manager->withFallback('fixtures', fn($p) => $p->fixtures([]), 'api-football');
    assert_true($out['ok']);
    assert_equals('api-football', $out['provider']);
});

// ═══════════════════════════════════════════════════════════════════════════
// 6. NORMALIZER INTEGRATION
// ═══════════════════════════════════════════════════════════════════════════

test('api-football fixture output passes through SportsDataNormalizer', function () {
    $body = json_encode(['response' => [
        ['fixture' => ['id' => 42, 'date' => '2026-09-15T19:00:00+00:00', 'status' => ['short' => 'NS']],
         'teams' => ['home' => ['id' => 1, 'name' => 'Home FC'], 'away' => ['id' => 2, 'name' => 'Away FC']],
         'league' => ['id' => 39, 'name' => 'Premier League', 'season' => 2026]],
    ]]);
    $p = new ApiFootballProvider('k', 'https://api.test', 10, makeTransport(200, $body));
    $rawFixtures = $p->fixtures([]);
    assert_equals(1, count($rawFixtures));
    $normalized = SportsDataNormalizer::fixture($rawFixtures[0], 'api-football');
    assert_equals('api-football', $normalized['provider']);
    assert_equals('42', $normalized['externalId']);
    assert_equals('Home FC', $normalized['homeTeam']);
    assert_equals('SCHEDULED', $normalized['status']);
});

test('thesportsdb fixture output passes through SportsDataNormalizer', function () {
    $body = json_encode(['events' => [
        ['idEvent' => '100', 'strHomeTeam' => 'City', 'strAwayTeam' => 'United',
         'strLeague' => 'Premier League', 'dateEvent' => '2026-09-20', 'strTime' => '15:00:00', 'strStatus' => 'Scheduled'],
    ]]);
    $p = new TheSportsDbProvider('3', 'https://api.test', 10, makeTransport(200, $body));
    $rawFixtures = $p->fixtures([]);
    $normalized = SportsDataNormalizer::fixture($rawFixtures[0], 'thesportsdb');
    assert_equals('thesportsdb', $normalized['provider']);
    assert_equals('100', $normalized['externalId']);
    assert_equals('City', $normalized['homeTeam']);
});

test('sportmonks fixture output passes through SportsDataNormalizer', function () {
    $body = json_encode(['data' => [
        ['id' => 200, 'starting_at' => '2026-09-20T16:00:00+00:00', 'status' => 6,
         'participants' => [['name' => 'Chelsea', 'meta' => ['location' => 'home']], ['name' => 'Arsenal', 'meta' => ['location' => 'away']]],
         'league' => ['name' => 'Premier League'], 'season' => ['id' => 2026]],
    ]]);
    $p = new SportMonksProvider('t', 'https://api.test', 10, makeTransport(200, $body));
    $rawFixtures = $p->fixtures([]);
    $normalized = SportsDataNormalizer::fixture($rawFixtures[0], 'sportmonks');
    assert_equals('sportmonks', $normalized['provider']);
    assert_equals('200', $normalized['externalId']);
    assert_equals('Chelsea', $normalized['homeTeam']);
});
