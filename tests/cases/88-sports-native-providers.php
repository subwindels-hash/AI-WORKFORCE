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
    assert_equals('Match Winner', $odds[0]['market']);
    assert_equals('Home', $odds[0]['selection']);
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
    assert_equals('Full Time Result', $odds[0]['market']);
    assert_equals('Home', $odds[0]['selection']);
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

    $apiFootballFixture = json_encode(['response' => [
        ['fixture' => ['id' => 1, 'date' => '2026-09-15T12:00:00Z', 'status' => ['short' => 'NS']],
         'teams' => ['home' => ['id' => 1, 'name' => 'Home'], 'away' => ['id' => 2, 'name' => 'Away']],
         'league' => ['id' => 1, 'name' => 'League', 'season' => 2026]],
    ]]);
    $sm = new SportMonksProvider('token', 'https://api.test', 10, makeTransport(200, $apiFootballFixture));
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
