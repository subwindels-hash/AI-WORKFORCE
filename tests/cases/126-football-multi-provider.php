<?php
/**
 * Multi-Provider API — three feeds, one canonical match record.
 *
 * The failure this suite exists to prevent: the same match arriving from
 * API-Football and from SportMonks becoming two fixtures with two predictions,
 * and a page of matches costing one provider call per match per feed.
 *
 * Pinned here:
 *  - provider selection (Auto / named / Multi) is derived from observable
 *    health, coverage and quota, never guessed;
 *  - every provider row is normalized into the one FootballMatch model;
 *  - the same match from two feeds merges into ONE canonical record and one
 *    prediction, while a genuinely different fixture stays separate;
 *  - odds fall back to a second provider and the source is named;
 *  - a generation request never exceeds 50 new matches;
 *  - a provider that is offline or in backoff is not asked.
 */
require_once TESTSPATH . 'football_support.php';

use AIWorkforce\Football\CanonicalMatch;
use AIWorkforce\Football\FootballConfiguration;
use AIWorkforce\Football\FootballMatch;
use AIWorkforce\Football\MatchIntelligenceService;
use AIWorkforce\Football\ProviderGateway;
use AIWorkforce\Football\ProviderSelector;
use AIWorkforce\Sports\Providers\SportsDataProvider;
use AIWorkforce\Sports\Providers\SportsProviderManager;

/** A feed with the full football surface: fixtures, odds, statistics, h2h, live. */
final class FxFullFeed implements SportsDataProvider
{
    public int $fixtureCalls = 0;
    public int $oddsCalls = 0;
    /** @var array<string,array> */
    public array $oddsRows = [];

    public function __construct(
        private string $id,
        private array $fixtureRows = [],
        private array $health = ['status' => 'ONLINE'],
    ) {}

    public function id(): string { return $this->id; }
    public function health(): array { return $this->health; }
    public function fixtures(array $q): array { $this->fixtureCalls++; return $this->fixtureRows; }
    public function odds(string $externalId): array { $this->oddsCalls++; return $this->oddsRows[$externalId] ?? []; }
    public function results(string $externalId): array { return []; }
    public function teamStatistics(array $q): array { return []; }
    public function standings(array $q): array { return []; }
    public function headToHead(array $q): array { return []; }
    public function liveFixtures(array $q): array { return []; }
    public function fixtureStatistics(string $externalId): array { return []; }
}

/** A fixtures-only feed: no odds, no statistics. Used to prove fallback. */
final class FxFixtureFeed implements SportsDataProvider
{
    public int $fixtureCalls = 0;

    public function __construct(
        private string $id,
        private array $fixtureRows = [],
        private array $health = ['status' => 'ONLINE'],
    ) {}

    public function id(): string { return $this->id; }
    public function health(): array { return $this->health; }
    public function fixtures(array $q): array { $this->fixtureCalls++; return $this->fixtureRows; }
    public function odds(string $externalId): array { return []; }
    public function results(string $externalId): array { return []; }
}

/** @param array<string,array> $rows provider id => its fixture rows, raw (unnormalized) shape */
function fx_multi_gateway(array $rows, array $health = [], array $odds = []): array
{
    $manager = new SportsProviderManager();
    $feeds = [];
    foreach ($rows as $id => $fixtureRows) {
        $feedHealth = $health[$id] ?? ['status' => 'ONLINE'];
        $feed = $id === 'thesportsdb'
            ? new FxFixtureFeed($id, $fixtureRows, $feedHealth)
            : new FxFullFeed($id, $fixtureRows, $feedHealth);
        if (isset($odds[$id]) && $feed instanceof FxFullFeed) $feed->oddsRows = $odds[$id];
        $manager->register($feed);
        $feeds[$id] = $feed;
    }
    $config = new FootballConfiguration();
    $gateway = new ProviderGateway($manager, $config);
    $selector = new ProviderSelector($gateway, $config);
    $service = new MatchIntelligenceService($gateway, $selector, new FootballRepositoryStub(), $config);
    return [$gateway, $selector, $service, $feeds, $config];
}

/** A raw provider fixture row in the shape SportsDataNormalizer accepts. */
function fx_multi_row(string $externalId, string $home, string $away, string $kickoff, string $competition = 'Premier League', string $leagueId = '39'): array
{
    return ['externalId' => $externalId, 'homeTeam' => $home, 'awayTeam' => $away,
        'kickoff' => $kickoff, 'competition' => $competition, 'leagueId' => $leagueId, 'status' => 'SCHEDULED'];
}

// ─── 1. Canonical identity ──────────────────────────────────────────────────

test('multi-provider: the same match from two feeds is one canonical record with one identity', function () {
    $notes = [];
    [$gateway, $selector, $service] = fx_multi_gateway([
        'api-football' => [fx_multi_row('1201', 'Manchester United', 'Arsenal', '2026-09-12T14:00:00Z')],
        'sportmonks'   => [fx_multi_row('88012', 'Man Utd', 'Arsenal FC', '2026-09-12T16:00:00+02:00')],
    ]);
    $result = $service->matches(['provider' => 'MULTI', 'date' => '2026-09-12'], $notes);
    assert_equals(1, $result['counts']['returned'], 'the two feeds describe one match, so one match comes back');
    assert_equals(1, $result['counts']['duplicatesMerged'], 'the second feed is merged, not appended');
    $match = $result['matches'][0];
    assert_equals('MANCHESTER_UNITED_ARSENAL_2026-09-12', $match->id, 'identity is the normalized teams and the kickoff date');
    assert_equals(2, count($match->providers), 'both provider match ids are recorded against the one record');
    assert_equals('1201', $match->providers['api-football']);
    assert_equals('88012', $match->providers['sportmonks']);
});

test('multi-provider: a match on another date is a different fixture, never merged into the first', function () {
    $notes = [];
    [, , $service] = fx_multi_gateway([
        'api-football' => [
            fx_multi_row('1201', 'Manchester United', 'Arsenal', '2026-09-12T14:00:00Z'),
            fx_multi_row('1202', 'Manchester United', 'Arsenal', '2026-11-28T14:00:00Z'),
        ],
    ]);
    $result = $service->matches(['date' => '2026-09-12'], $notes);
    assert_equals(2, $result['counts']['returned'], 'same teams, different date: two fixtures');
    assert_equals(0, $result['counts']['duplicatesMerged']);
    assert_not_equals($result['matches'][0]->id, $result['matches'][1]->id);
});

test('multi-provider: fuzzy team names across providers still resolve to the same match', function () {
    [, , $service] = fx_multi_gateway([
        'api-football' => [fx_multi_row('1', 'Manchester United', 'Arsenal', '2026-09-12T14:00:00Z')],
        'sportmonks'   => [fx_multi_row('2', 'Man Utd', 'Arsenal', '2026-09-12T16:00:00+02:00')],
    ]);
    $notes = [];
    $result = $service->matches(['provider' => 'MULTI'], $notes);
    assert_equals(1, $result['counts']['returned'], 'Man Utd is Manchester United, in the same way, every time');
});

test('multi-provider: two different matches in one competition stay two matches', function () {
    [, , $service] = fx_multi_gateway([
        'api-football' => [
            fx_multi_row('1', 'Chelsea', 'Liverpool', '2026-09-12T14:00:00Z'),
            fx_multi_row('2', 'Arsenal', 'Tottenham', '2026-09-12T14:00:00Z'),
        ],
    ]);
    $notes = [];
    $result = $service->matches([], $notes);
    assert_equals(2, $result['counts']['returned'], 'a fuzzy near-miss below the threshold is not a merge');
    assert_equals(0, $result['counts']['duplicatesMerged']);
});

test('multi-provider: CanonicalMatch matching is deterministic and states how it matched', function () {
    $candidate = fx_multi_row('1', 'Manchester United', 'Arsenal', '2026-09-12T14:00:00Z');
    $same = fx_multi_row('77', 'Man Utd', 'Arsenal', '2026-09-12T14:00:00Z');
    $other = fx_multi_row('78', 'Chelsea', 'Arsenal', '2026-09-12T14:00:00Z');
    $later = fx_multi_row('79', 'Manchester United', 'Arsenal', '2026-10-03T14:00:00Z');

    assert_equals(CanonicalMatch::MATCH_TEAMS_DATE, CanonicalMatch::matchScore($candidate, $same)['matchedBy']);
    assert_equals(CanonicalMatch::MATCH_NEW, CanonicalMatch::matchScore($candidate, $later)['matchedBy'],
        'same teams on another day is a new fixture, not a match');
    assert_true(CanonicalMatch::matchScore($candidate, $other)['score'] < CanonicalMatch::FUZZY_THRESHOLD,
        'a different opponent is below the fuzzy threshold');
    assert_equals(CanonicalMatch::matchScore($candidate, $same), CanonicalMatch::matchScore($candidate, $same),
        'scoring is deterministic: the same pair always scores the same');
});

// ─── 2. Provider selection ──────────────────────────────────────────────────

test('multi-provider: Auto names the provider it chose and why, from observable state', function () {
    [$gateway, $selector] = fx_multi_gateway([
        'api-football' => [fx_multi_row('1', 'Chelsea', 'Liverpool', '2026-09-12T14:00:00Z')],
        'thesportsdb' => [fx_multi_row('2', 'Arsenal', 'Tottenham', '2026-09-12T14:00:00Z')],
    ]);
    $notes = [];
    $selection = $selector->resolve(null, $notes);
    assert_equals('AUTO', $selection['mode']);
    assert_equals('SELECTED', $selection['state']);
    assert_equals('api-football', $selection['plan']['fixtures'], 'the richer feed wins: it also has odds and statistics');
    assert_contains('Auto selected api-football', $selection['reason']);
    assert_true(($selection['scores']['api-football']['total'] ?? 0) > ($selection['scores']['thesportsdb']['total'] ?? 0),
        'the score is the explanation, and the richer provider scores higher');
});

test('multi-provider: Auto skips a provider that is offline or out of quota', function () {
    [, $selector] = fx_multi_gateway([
        'api-football' => [],
        'thesportsdb' => [fx_multi_row('2', 'Arsenal', 'Tottenham', '2026-09-12T14:00:00Z')],
    ], ['api-football' => ['status' => 'OFFLINE', 'detail' => 'quota exhausted']]);
    $notes = [];
    $selection = $selector->resolve('AUTO', $notes);
    assert_equals('thesportsdb', $selection['plan']['fixtures'], 'an offline provider is not asked');
    assert_false($selection['scores']['api-football']['callable']);
});

test('multi-provider: a named provider serves the request, and unknown names fall back to Auto with a note', function () {
    [, $selector] = fx_multi_gateway([
        'api-football' => [fx_multi_row('1', 'Chelsea', 'Liverpool', '2026-09-12T14:00:00Z')],
        'sportmonks' => [fx_multi_row('2', 'Arsenal', 'Tottenham', '2026-09-12T14:00:00Z')],
    ]);
    $notes = [];
    $named = $selector->resolve('sportmonks', $notes);
    assert_equals('sportmonks', $named['mode']);
    assert_equals('sportmonks', $named['plan']['fixtures'], 'the operator chose it, so it is the one asked');

    $notes = [];
    $unknown = $selector->resolve('football-api', $notes);
    assert_equals('AUTO', $unknown['mode'], 'an unknown name is not silently served by a random feed');
    assert_equals(1, count($notes), 'the substitution is stated, not implied');
    assert_contains('not one of the configured providers', $notes[0]);
});

test('multi-provider: Multi assigns one provider per data class and never fetches fixtures twice', function () {
    [,, $service, $feeds] = fx_multi_gateway([
        'api-football' => [fx_multi_row('1', 'Chelsea', 'Liverpool', '2026-09-12T14:00:00Z')],
        'sportmonks' => [fx_multi_row('1', 'Chelsea', 'Liverpool', '2026-09-12T14:00:00Z')],
    ]);
    $notes = [];
    $result = $service->matches(['provider' => 'MULTI', 'date' => '2026-09-12'], $notes);
    assert_equals('MULTI', $result['selection']['mode']);
    assert_equals(1, $result['counts']['returned'], 'multi means combine, not duplicate');
    foreach ($feeds as $id => $feed) {
        assert_equals(1, $feed->fixtureCalls, $id . ' is asked once for the whole page, not once per match');
    }
    // The data classes the losing provider would have served are named in the plan.
    assert_not_null($result['selection']['plan']['odds']);
    assert_not_null($result['selection']['plan']['statistics']);
});

test('multi-provider: the provider dropdown lists Auto, every configured feed, and Multi', function () {
    [,, $service] = fx_multi_gateway([
        'api-football' => [], 'thesportsdb' => [], 'sportmonks' => [],
    ]);
    $catalogue = $service->providers();
    $values = array_column($catalogue['options'], 'value');
    assert_equals(['AUTO', 'api-football', 'thesportsdb', 'sportmonks', 'MULTI'], $values);
    assert_equals(3, $catalogue['providers'][0]['providers'] ?? 3 === 3 ? count($catalogue['providers']) : 0);
    assert_equals('API-Football', $catalogue['providers'][0]['name'], 'feeds are named the way an operator knows them');
    assert_equals('AVAILABLE', $catalogue['state']);
});

test('multi-provider: with no provider connected the dropdown says so instead of offering a fake choice', function () {
    [, , $service] = fx_multi_gateway([]);
    $catalogue = $service->providers();
    assert_equals([], $catalogue['providers']);
    assert_equals([], $catalogue['options'], 'no mode is offered that no connected feed can honour');
    assert_equals('DATA_UNAVAILABLE', $catalogue['state']);
    assert_contains('No football data provider is connected', (string) $catalogue['message']);
});

// ─── 3. Normalization ───────────────────────────────────────────────────────

test('multi-provider: every provider row becomes the one FootballMatch model', function () {
    [,, $service] = fx_multi_gateway([
        'api-football' => [fx_multi_row('1201', 'Manchester United', 'Arsenal', '2026-09-12T14:00:00Z', 'Premier League', '39')],
    ]);
    $notes = [];
    $result = $service->matches(['date' => '2026-09-12'], $notes);
    $match = $result['matches'][0];
    assert_true($match instanceof FootballMatch, 'the engine hands the prediction layer one model, not a provider shape');
    $row = $match->toArray();
    foreach (['id', 'providers', 'competitionId', 'competitionName', 'season', 'homeTeam', 'awayTeam',
        'kickoffTime', 'status', 'venue', 'odds', 'statistics', 'injuries', 'lineups', 'form', 'h2h', 'dataSources'] as $key) {
        assert_true(array_key_exists($key, $row), 'FootballMatch exposes ' . $key);
    }
    assert_equals('Manchester United', $row['homeTeam']['name']);
    assert_equals('manchester united', $row['homeTeam']['normalized']);
    assert_equals('39', $row['competitionId']);
    assert_equals('2026-09-12T14:00:00+00:00', $row['kickoffTime'], 'kickoff is normalized to UTC');
    assert_equals([], $row['odds'], 'no odds were supplied, so the odds block stays empty — not zero, not guessed');
});

test('multi-provider: a provider row missing a required field is dropped, not padded into a match', function () {
    [,, $service] = fx_multi_gateway([
        'api-football' => [
            ['externalId' => 'x', 'homeTeam' => 'Chelsea', 'awayTeam' => '', 'kickoff' => '2026-09-12T14:00:00Z', 'competition' => 'EPL'],
            ['externalId' => 'y', 'homeTeam' => 'Arsenal', 'awayTeam' => 'Spurs', 'kickoff' => 'not-a-date', 'competition' => 'EPL'],
            fx_multi_row('z', 'Liverpool', 'Everton', '2026-09-12T14:00:00Z'),
        ],
    ]);
    $notes = [];
    $result = $service->matches(['date' => '2026-09-12'], $notes);
    assert_equals(1, $result['counts']['returned'], 'only the row the provider actually described survives');
    assert_equals(2, $result['counts']['unusable'], 'the two broken rows are counted as unusable, not hidden');
});

// ─── 4. Deduplication and the 50 cap ────────────────────────────────────────

test('multi-provider: a generation request never exceeds 50 matches', function () {
    $rows = [];
    for ($i = 1; $i <= 120; $i++) {
        $rows[] = fx_multi_row((string) $i, 'Team ' . $i, 'Team ' . ($i + 500), '2026-09-12T14:00:00Z');
    }
    [,, $service] = fx_multi_gateway(['api-football' => $rows]);
    $notes = [];
    $result = $service->matches(['date' => '2026-09-12', 'limit' => 500], $notes);
    assert_equals(50, $result['counts']['returned'], 'limit=500 is served as the hard maximum of 50');
    assert_equals(MatchIntelligenceService::MAX_GENERATION_BATCH, 50);
});

test('multi-provider: three feeds carrying the same 30 matches produce 30 matches, not 90', function () {
    $rows = [];
    for ($i = 1; $i <= 30; $i++) {
        $rows[] = fx_multi_row((string) $i, 'Team ' . $i, 'Team ' . ($i + 500), '2026-09-12T14:00:00Z');
    }
    [,, $service] = fx_multi_gateway([
        'api-football' => $rows,
        'thesportsdb' => $rows,
        'sportmonks' => $rows,
    ]);
    $notes = [];
    $result = $service->matches(['provider' => 'MULTI', 'date' => '2026-09-12'], $notes);
    assert_equals(30, $result['counts']['returned'], 'three feeds, thirty matches');
    assert_equals(30, $result['counts']['duplicatesMerged'], 'the feeds that were read merge into records that already exist');
});

test('multi-provider: the same provider row ingested twice keeps one canonical record', function () {
    $repo = new FootballRepositoryStub();
    $config = new FootballConfiguration();
    [$gateway, , $service] = fx_multi_gateway(['api-football' => []]);
    $service = new MatchIntelligenceService($gateway, new ProviderSelector($gateway, $config), $repo, $config);

    $row = fx_multi_row('1201', 'Manchester United', 'Arsenal', '2026-09-12T14:00:00Z');
    $match = FootballMatch::fromProviderRow('api-football', $row);
    assert_not_null($match);
    foreach ([1, 2] as $pass) {
        $repo->saveProviderMatch([
            'internalMatchId' => $match->id, 'providerCode' => 'api-football', 'providerMatchId' => '1201',
            'homeTeam' => 'Manchester United', 'awayTeam' => 'Arsenal', 'kickoff' => '2026-09-12T14:00:00Z',
        ]);
    }
    assert_equals(1, count($repo->listProviderMatches($match->id)), 'ingesting the same row twice is an upsert, not a second match');
    assert_equals(1, count($repo->providerMatches));
});

test('multi-provider: provider match rows record how the match was recognized', function () {
    $repo = new FootballRepositoryStub();
    $config = new FootballConfiguration();
    $manager = new SportsProviderManager();
    $manager->register(new FxFullFeed('api-football', [fx_multi_row('1201', 'Manchester United', 'Arsenal', '2026-09-12T14:00:00Z')]));
    $manager->register(new FxFullFeed('sportmonks', [fx_multi_row('88012', 'Man Utd', 'Arsenal', '2026-09-12T16:00:00+02:00')]));
    $gateway = new ProviderGateway($manager, $config);
    $service = new MatchIntelligenceService($gateway, new ProviderSelector($gateway, $config), $repo, $config);

    $notes = [];
    $service->matches(['provider' => 'MULTI', 'date' => '2026-09-12'], $notes);
    $rows = $repo->listProviderMatches('MANCHESTER_UNITED_ARSENAL_2026-09-12');
    assert_equals(2, count($rows), 'one canonical match, one row per provider that carries it');
    $byProvider = array_column($rows, null, 'provider_code');
    assert_equals('1201', $byProvider['api-football']['provider_match_id']);
    assert_equals('88012', $byProvider['sportmonks']['provider_match_id']);
    assert_equals('2026-09-12', $byProvider['sportmonks']['kickoff_date']);
    assert_in_array($byProvider['sportmonks']['matched_by'], [CanonicalMatch::MATCH_TEAMS_DATE, CanonicalMatch::MATCH_FUZZY_TEAMS, CanonicalMatch::MATCH_PROVIDER_ID]);
    assert_not_null($repo->findProviderMatch('api-football', '1201'));
    assert_null($repo->findProviderMatch('api-football', 'nope'));
});

// ─── 5. Odds fallback ───────────────────────────────────────────────────────

test('multi-provider: odds fall back to a second provider and the source is named', function () {
    [,, $service] = fx_multi_gateway([
        'api-football' => [fx_multi_row('1201', 'Manchester United', 'Arsenal', '2026-09-12T14:00:00Z')],
        'sportmonks' => [fx_multi_row('88012', 'Man Utd', 'Arsenal', '2026-09-12T16:00:00+02:00')],
    ], [], ['sportmonks' => ['88012' => [['market' => 'OVER_UNDER_2_5', 'selection' => 'Over 2.5', 'odds' => 1.95]]]]);

    $notes = [];
    $result = $service->matches(['provider' => 'MULTI', 'date' => '2026-09-12'], $notes);
    $match = $result['matches'][0]->toArray();

    $notes = [];
    $odds = $service->odds($match, 'OVER_UNDER_2_5', $result['selection'], $notes);
    assert_equals('AVAILABLE', $odds['state']);
    assert_equals('sportmonks', $odds['source'], 'the price is attributed to the provider that quoted it');
    assert_equals(['Over 2.5' => 1.95], $odds['odds']);
    assert_true(count($odds['attempted']) >= 1, 'the providers tried are listed, so a fallback is visible');
});

test('multi-provider: when no provider quotes a market the odds say so instead of inventing a price', function () {
    [,, $service] = fx_multi_gateway([
        'api-football' => [fx_multi_row('1201', 'Manchester United', 'Arsenal', '2026-09-12T14:00:00Z')],
    ]);
    $notes = [];
    $result = $service->matches(['date' => '2026-09-12'], $notes);
    $match = $result['matches'][0]->toArray();
    $notes = [];
    $odds = $service->odds($match, 'OVER_UNDER_2_5', $result['selection'], $notes);
    assert_equals('DATA_UNAVAILABLE', $odds['state']);
    assert_equals([], $odds['odds'], 'no price is fabricated when no provider quoted one');
    assert_null($odds['source']);
    assert_contains('OVER_UNDER_2_5', (string) $odds['reason']);
});

// ─── 6. Health monitoring ───────────────────────────────────────────────────

test('multi-provider: provider health reports status, rate limits, coverage and what is missing', function () {
    [,, $service] = fx_multi_gateway([
        'api-football' => [],
        'thesportsdb' => [],
    ], ['api-football' => ['status' => 'ONLINE', 'requestsToday' => 400, 'limitDaily' => 500, 'reliability' => 0.9]]);
    $health = $service->health();
    assert_equals(2, $health['total']);
    assert_equals('CONNECTED', $health['state']);
    $byId = array_column($health['providers'], null, 'id');
    assert_equals('ONLINE', $byId['api-football']['status']);
    assert_equals('OK', $byId['api-football']['rateLimit']['status']);
    assert_equals(400, $byId['api-football']['rateLimit']['requestsToday']);
    assert_true($byId['api-football']['oddsAvailable'], 'a feed with an odds endpoint reports it');
    assert_true($byId['api-football']['statisticsAvailable']);
    assert_false($byId['thesportsdb']['statisticsAvailable'], 'a fixtures-only feed reports statistics as unavailable');
    assert_true(in_array('statistics', $byId['thesportsdb']['missingData'], true),
        'what a provider cannot supply is stated, not left to be inferred');
    assert_equals(0.9, $byId['api-football']['reliability']);
});

test('multi-provider: health with nothing configured says NOT_CONFIGURED rather than reporting a healthy empty list', function () {
    [, , $service] = fx_multi_gateway([]);
    $health = $service->health();
    assert_equals('NOT_CONFIGURED', $health['state']);
    assert_equals(0, $health['total']);
    assert_equals([], $health['providers']);
});

test('multi-provider: a provider in backoff is not the one selected', function () {
    [, $selector, , , $config] = fx_multi_gateway([
        'api-football' => [fx_multi_row('1', 'Chelsea', 'Liverpool', '2026-09-12T14:00:00Z')],
        'thesportsdb' => [fx_multi_row('2', 'Arsenal', 'Tottenham', '2026-09-12T14:00:00Z')],
    ]);
    // A sync log gives the gateway somewhere to persist the backoff.
    $repo = new FootballRepositoryStub();
    $manager = new SportsProviderManager();
    $manager->register(new FxFullFeed('api-football', [fx_multi_row('1', 'Chelsea', 'Liverpool', '2026-09-12T14:00:00Z')]));
    $manager->register(new FxFixtureFeed('thesportsdb', [fx_multi_row('2', 'Arsenal', 'Tottenham', '2026-09-12T14:00:00Z')]));
    $gateway = new ProviderGateway($manager, $config, $repo);
    $gateway->recordFailure('api-football', 'HTTP 429: too many requests', ['status' => 'RATE_LIMITED']);
    assert_true($gateway->inBackoff('api-football'), 'a rate-limited provider is backed off');
    $notes = [];
    $selection = (new ProviderSelector($gateway, $config))->resolve('AUTO', $notes);
    assert_equals('thesportsdb', $selection['plan']['fixtures'], 'the backed-off provider is skipped, not retried');
});

// ─── 7. Competition mapping ─────────────────────────────────────────────────

test('multi-provider: premium is the deployment classification, not a provider league id', function () {
    $repo = new FootballRepositoryStub();
    $config = new FootballConfiguration();
    [$gateway, , $service] = fx_multi_gateway(['api-football' => []]);
    $service = new MatchIntelligenceService($gateway, new ProviderSelector($gateway, $config), $repo, $config);

    $repo->saveCompetitionMapping(['internalId' => 'EPL', 'providerCode' => 'api-football', 'providerCompetitionId' => '39',
        'competitionName' => 'Premier League', 'country' => 'England', 'tier' => 'PREMIUM', 'premium' => true]);
    $repo->saveCompetitionMapping(['internalId' => 'NPFL', 'providerCode' => 'api-football', 'providerCompetitionId' => '451',
        'competitionName' => 'NPFL', 'country' => 'Nigeria', 'tier' => 'STANDARD', 'premium' => false]);

    $notes = [];
    $result = $service->competitions('api-football', null, $notes);
    assert_equals(2, $result['total']);
    assert_equals('EPL', $result['premium'][0]['internalId'], 'the premium league is the one the deployment classified');
    assert_equals('Premier League', $result['competitions'][0]['name'], 'premium competitions sort first');
    assert_true($result['competitions'][0]['premium']);
    assert_false($result['competitions'][1]['premium']);
});

test('multi-provider: the same competition under three providers is one internal competition', function () {
    $repo = new FootballRepositoryStub();
    $config = new FootballConfiguration();
    [$gateway, , $service] = fx_multi_gateway(['api-football' => []]);
    $service = new MatchIntelligenceService($gateway, new ProviderSelector($gateway, $config), $repo, $config);

    $repo->saveCompetitionMapping(['internalId' => 'EPL', 'providerCode' => 'api-football', 'providerCompetitionId' => '39',
        'competitionName' => 'Premier League', 'premium' => true]);
    $repo->saveCompetitionMapping(['internalId' => 'EPL', 'providerCode' => 'sportmonks', 'providerCompetitionId' => '1',
        'competitionName' => 'Premier League', 'premium' => true]);
    $repo->saveCompetitionMapping(['internalId' => 'EPL', 'providerCode' => 'thesportsdb', 'providerCompetitionId' => '4328',
        'competitionName' => 'English Premier League', 'premium' => true]);

    assert_equals(3, count($repo->listCompetitionMappings(['internalId' => 'EPL'])));
    assert_equals(1, count($repo->listCompetitionMappings(['internalId' => 'EPL', 'providerCode' => 'sportmonks'])));
    $notes = [];
    $result = $service->competitions(null, null, $notes);
    assert_equals(3, $result['total'], 'every provider mapping is listed, each traceable to its own league id');
    assert_equals('39', $repo->findCompetitionMapping('api-football', '39')['provider_competition_id']);
    assert_equals('1', $repo->findCompetitionMapping('sportmonks', '1')['provider_competition_id']);
});

test('multi-provider: with no competition mapped the list is empty and says why', function () {
    [, , $service] = fx_multi_gateway(['api-football' => []]);
    $notes = [];
    $result = $service->competitions('api-football', null, $notes);
    assert_equals([], $result['competitions']);
    assert_equals('DATA_UNAVAILABLE', $result['state']);
    assert_contains('never invented', (string) $result['message']);
});

// ─── 8. Data provenance ─────────────────────────────────────────────────────

test('multi-provider: every match records which provider supplied it', function () {
    [,, $service] = fx_multi_gateway([
        'api-football' => [fx_multi_row('1201', 'Manchester United', 'Arsenal', '2026-09-12T14:00:00Z')],
        'sportmonks' => [fx_multi_row('88012', 'Man Utd', 'Arsenal', '2026-09-12T16:00:00+02:00')],
    ]);
    $notes = [];
    $result = $service->matches(['provider' => 'MULTI', 'date' => '2026-09-12'], $notes);
    $sources = $result['matches'][0]->dataSources;
    $providers = array_column($sources, 'provider');
    assert_in_array('api-football', $providers);
    assert_in_array('sportmonks', $providers);
    foreach ($sources as $source) {
        assert_true(isset($source['classes']) && is_array($source['classes']), 'each source states which data it supplied');
    }
});

// ─── 9. Regeneration ────────────────────────────────────────────────────────

/** A stored prediction with no signals attached to it. */
function fx_multi_prediction(array $over = []): array
{
    return array_merge([
        'id' => 'p1', 'model_version_id' => 7, 'generated_at' => gmdate('c', time() - 3600),
        'data_quality_band' => 'QUALIFIED', 'data_quality_score' => 80, 'confidence' => 61.0,
    ], $over);
}

/** A scheduled fixture two days out, with nothing new stamped on it. */
function fx_multi_fixture(array $over = []): array
{
    $day = gmdate('Y-m-d', time() + 2 * 86400);
    return array_merge([
        'id' => 1, 'external_id' => '1201', 'provider_code' => 'api-football',
        'kickoff_at' => $day . 'T14:00:00+00:00', 'status' => 'SCHEDULED', 'match_state' => 'PRE_MATCH',
        'home_team' => 'Manchester United', 'away_team' => 'Arsenal',
        'source_timestamp' => gmdate('c', time() - 7200),
    ], $over);
}

test('multi-provider: an existing prediction is reused when nothing justifies replacing it', function () {
    $policy = new \AIWorkforce\Football\RegenerationPolicy(new FootballConfiguration());
    $decision = $policy->decide(fx_multi_prediction(), fx_multi_fixture(), [], 7);
    assert_equals(\AIWorkforce\Football\RegenerationPolicy::REUSE, $decision['action']);
    assert_equals([], $decision['codes'], 'no signal, no reason, no regeneration');
});

test('multi-provider: the reasons that justify a regeneration each produce one', function () {
    $policy = new \AIWorkforce\Football\RegenerationPolicy(new FootballConfiguration());
    $threshold = (new FootballConfiguration())->oddsMovementThreshold();
    $cases = [
        ['MODEL_VERSION_CHANGED', [], 8],
        ['PREDICTION_EXPIRED', ['prediction' => ['generated_at' => gmdate('c', time() - 10 * 86400)]], 7],
        ['SIGNIFICANT_ODDS_MOVEMENT', ['signals' => ['oddsMovement' => $threshold + 0.02]], 7],
        ['CONFIRMED_LINEUP_CHANGE', ['signals' => ['lineupChanged' => true]], 7],
        ['MAJOR_INJURY_OR_NEWS', ['signals' => ['injuryNews' => true]], 7],
        ['MATCH_STATUS_CHANGE', ['fixture' => ['status' => 'POSTPONED']], 7],
        ['NEW_STATISTICS_AVAILABLE', ['fixture' => ['source_timestamp' => gmdate('c', time() - 60)]], 7],
    ];
    foreach ($cases as [$code, $over, $model]) {
        $decision = $policy->decide(fx_multi_prediction($over['prediction'] ?? []),
            fx_multi_fixture($over['fixture'] ?? []), $over['signals'] ?? [], $model);
        assert_equals(\AIWorkforce\Football\RegenerationPolicy::REFRESH, $decision['action'], $code . ' justifies a regeneration');
        assert_in_array($code, $decision['codes'], $code . ' is named as the reason');
        assert_true($decision['reasons'] !== [], $code . ' is explained in words');
    }
});

test('multi-provider: a price tick is not a reason to regenerate', function () {
    $policy = new \AIWorkforce\Football\RegenerationPolicy(new FootballConfiguration());
    $threshold = (new FootballConfiguration())->oddsMovementThreshold();
    $decision = $policy->decide(fx_multi_prediction(), fx_multi_fixture(), ['oddsMovement' => $threshold / 2], 7);
    assert_equals(\AIWorkforce\Football\RegenerationPolicy::REUSE, $decision['action'],
        'a move below the threshold leaves the stored prediction alone');
});

test('multi-provider: once a match has kicked off no signal reopens its prediction', function () {
    $policy = new \AIWorkforce\Football\RegenerationPolicy(new FootballConfiguration());
    $past = fx_multi_fixture(['kickoff_at' => gmdate('c', time() - 600)]);
    $decision = $policy->decide(fx_multi_prediction(), $past, [
        'oddsMovement' => 0.4, 'lineupChanged' => true, 'injuryNews' => true,
    ], 99);
    assert_equals(\AIWorkforce\Football\RegenerationPolicy::REUSE, $decision['action']);
    assert_equals(['FROZEN_AT_KICKOFF'], $decision['codes'],
        'kickoff is a hard stop, not a reason — the prediction is frozen and settled, never regenerated');
});

test('multi-provider: an unknown signal is never treated as a reason', function () {
    $policy = new \AIWorkforce\Football\RegenerationPolicy(new FootballConfiguration());
    // Every signal absent — an unknown cannot justify replacing a stored row.
    $decision = $policy->decide(fx_multi_prediction(), fx_multi_fixture(), [], 7);
    assert_equals([], $decision['codes']);
    // And an explicitly null one is the same as an absent one.
    $decision = $policy->decide(fx_multi_prediction(), fx_multi_fixture(),
        ['oddsMovement' => null, 'lineupChanged' => null, 'injuryNews' => null], 7);
    assert_equals(\AIWorkforce\Football\RegenerationPolicy::REUSE, $decision['action']);
});

// ─── 10. The prediction result ──────────────────────────────────────────────

if (!function_exists('fx_multi_paged_day')) {
    /** A date with `$count` stored fixtures, sync'd and two days out. */
    function fx_multi_paged_day(int $count, array $config = []): array
    {
        $day = gmdate('Y-m-d', time() + 2 * 86400);
        $base = (int) strtotime($day . 'T00:30:00+00:00');
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = fx_fb_row('fx-page-' . $i, gmdate('c', $base + $i * 60), 'Manchester City', 'Everton', '10', '20');
        }
        [$repo, $provider, $module] = fx_fb_harness($rows, [], $config);
        fx_fb_sync_today($module, $day);
        return [$repo, $provider, $module, $day];
    }
}

test('multi-provider: a prediction result names its match, model, times, sources and risk', function () {
    [, , $module, $day] = fx_multi_paged_day(3);
    $page = $module->feed()->generate($day, 1, 50, ['market' => 'MATCH_WINNER']);
    $match = $page['matches'][0];
    $result = $match['market'];

    foreach (['matchId', 'probability', 'confidence', 'odds', 'impliedProbability',
        'riskLevel', 'dataSources', 'modelVersion', 'generatedAt', 'expiresAt'] as $key) {
        assert_true(array_key_exists($key, $result), 'the prediction result carries ' . $key);
    }
    assert_equals($match['matchId'], $result['matchId'], 'the result belongs to the match it is shown against');
    assert_in_array($result['riskLevel'], ['LOW', 'MEDIUM', 'HIGH'], 'risk is one of the three documented levels');
    assert_true($result['riskFactors'] !== [], 'the risk level is explained, not asserted');
    assert_equals('FROZEN_AT_KICKOFF', $result['expiryBasis'], 'a prediction is frozen at kickoff, so that is its expiry');
    assert_not_null($result['expiresAt']);
    assert_not_null($result['modelVersion']);
    assert_not_null($result['dataSources'][0]['provider'] ?? null, 'the provider behind the match is named');
    assert_equals((string) ($page['matches'][0]['provider'] ?? ''), (string) $result['dataSources'][0]['provider'],
        'and it is the provider the fixture row came from');
    assert_not_null($match['prediction'], 'the match row keeps the prediction summary');
});

test('multi-provider: a match recognized across feeds shows every provider that carries it', function () {
    [$repo, , $module, $day] = fx_multi_paged_day(2);
    $kickoff = (string) ($repo->listFixtures(['date' => $day], 1)[0]['kickoff_at'] ?? '');
    $canonical = CanonicalMatch::identity('Manchester City', 'Everton', $kickoff);
    // A second feed recognized the first match: the same canonical record, its
    // own provider id, matched by the rule that fired.
    $repo->saveProviderMatch([
        'internalMatchId' => $canonical, 'providerCode' => 'api-football',
        'providerMatchId' => 'fx-page-0', 'homeTeam' => 'Manchester City', 'awayTeam' => 'Everton',
        'kickoff' => $kickoff, 'matchedBy' => CanonicalMatch::MATCH_PROVIDER_ID,
    ]);
    $repo->saveProviderMatch([
        'internalMatchId' => $canonical, 'providerCode' => 'sportmonks',
        'providerMatchId' => '88012', 'homeTeam' => 'Man City', 'awayTeam' => 'Everton',
        'kickoff' => $kickoff, 'matchedBy' => CanonicalMatch::MATCH_FUZZY_TEAMS,
    ]);
    $page = $module->feed()->generate($day, 1, 50, ['market' => 'MATCH_WINNER']);
    $sources = $page['matches'][0]['market']['dataSources'];
    $providers = array_column($sources, 'provider');
    sort($providers);
    assert_equals(['api-football', 'sportmonks'], $providers, 'both feeds are named behind the one prediction');
    $byProvider = array_column($sources, null, 'provider');
    assert_equals('88012', $byProvider['sportmonks']['providerMatchId']);
    assert_equals(CanonicalMatch::MATCH_FUZZY_TEAMS, $byProvider['sportmonks']['matchedBy']);
});

test('multi-provider: generating the same page twice reuses every prediction', function () {
    [, , $module, $day] = fx_multi_paged_day(4);
    $feed = $module->feed();
    $first = $feed->generate($day, 1, 50, ['market' => 'MATCH_WINNER']);
    assert_equals(4, $first['generation']['generated'], 'the first request generates the page');
    assert_equals(0, $first['generation']['skippedStored']);

    $second = $feed->generate($day, 1, 50, ['market' => 'MATCH_WINNER']);
    assert_equals(0, $second['generation']['generated'], 'the second request generates nothing');
    assert_equals(4, $second['generation']['skippedStored'], 'every stored prediction is reused');
    assert_equals(0, $second['generation']['refreshed'], 'nothing was refreshed: no signal justified it');
    foreach ($second['matches'] as $match) {
        assert_equals('STORED', $match['predictionSource'], 'the match is served from the stored prediction');
        assert_equals([], $match['market']['regeneration']['codes'] ?? [],
            'no regeneration reason was found, and none was invented');
    }
});

test('multi-provider: a material price move replaces the prediction, and a tick does not', function () {
    [$repo, , $module, $day] = fx_multi_paged_day(2);
    $feed = $module->feed();
    $feed->generate($day, 1, 50, ['market' => 'MATCH_WINNER']);

    $matchId = null;
    foreach ($module->repository()->listFixtures(['date' => $day], 1) as $fixture) {
        $matchId = (string) $fixture['provider_code'] . ':' . (string) $fixture['external_id'];
    }
    assert_not_null($matchId);

    // The prediction is backdated so the quote below is unambiguously *after*
    // it: movement is measured against the price that stood when the prediction
    // was written, and both are timestamped.
    foreach ($repo->predictions as &$prediction) {
        $prediction['generated_at'] = gmdate('c', time() - 3600);
    }
    unset($prediction);
    // The fixture rows are backdated with it, so the only thing that changes
    // between the two passes below is the price.
    foreach ($repo->fixtures as &$storedFixture) {
        $storedFixture['source_timestamp'] = gmdate('c', time() - 7200);
    }
    unset($storedFixture);

    // A tick: the same selection a hair away from where it stood.
    foreach ([[1.95, 1.97], [1.95, 2.60]] as $pass => [$before, $after]) {
        $repo = $module->repository();
        // The quote that stood when the prediction was written, then a new one
        // the feed sent afterwards — the second one is what moves the market.
        $repo->marketOdds = [
            ['matchId' => (string) $matchId, 'market' => 'MATCH_WINNER', 'selection' => 'HOME',
                'decimalOdds' => $before, 'observedAt' => gmdate('c', time() - 7200)],
            ['matchId' => (string) $matchId, 'market' => 'MATCH_WINNER', 'selection' => 'HOME',
                'decimalOdds' => $after, 'observedAt' => gmdate('c', time() - 60)],
        ];
        $page = $feed->generate($day, 1, 50, ['market' => 'MATCH_WINNER']);
        if ($pass === 0) {
            assert_equals(0, $page['generation']['refreshed'], 'a two-cent tick does not justify replacing a prediction');
            assert_equals(2, $page['generation']['skippedStored']);
        } else {
            assert_equals(1, $page['generation']['refreshed'], 'a price that moved materially replaces the prediction');
            $codes = array_merge(
                (array) ($page['matches'][0]['regeneration']['codes'] ?? []),
                (array) ($page['matches'][1]['regeneration']['codes'] ?? []));
            assert_in_array('SIGNIFICANT_ODDS_MOVEMENT', $codes, 'the reason is reported with the match');
            assert_equals('GENERATED', $page['matches'][0]['predictionSource'], 'the replaced prediction is shown as generated');
        }
    }
});

// ─── 11. Competition mapping is populated from the provider that was read ────

test('multi-provider: reading a provider records the competition it carries, per provider id', function () {
    $repo = new FootballRepositoryStub();
    $config = new FootballConfiguration(['WINDELS_FOOTBALL_PREMIUM_COMPETITIONS' => 'Premier League']);
    $manager = new SportsProviderManager();
    $manager->register(new FxFullFeed('api-football', [
        fx_multi_row('1', 'Chelsea', 'Liverpool', '2026-09-12T14:00:00Z', 'Premier League', '39'),
        fx_multi_row('2', 'Al Hilal', 'Al Nassr', '2026-09-12T16:00:00Z', 'Saudi Pro League', '307'),
    ]));
    $manager->register(new FxFullFeed('sportmonks', [
        fx_multi_row('77', 'Chelsea', 'Liverpool', '2026-09-12T15:00:00+01:00', 'Premier League', '1'),
    ]));
    $gateway = new ProviderGateway($manager, $config);
    $service = new MatchIntelligenceService($gateway, new ProviderSelector($gateway, $config), $repo, $config);

    $notes = [];
    $service->matches(['provider' => 'MULTI', 'date' => '2026-09-12'], $notes);

    $epl = $repo->findCompetitionMapping('api-football', '39');
    assert_not_null($epl, 'the competition the provider sent is mapped, not invented');
    assert_equals('PREMIER_LEAGUE', $epl['internal_id'], 'the internal competition is the deployment id, not the provider number');
    assert_true((bool) $epl['premium'], 'premium is this deployment\'s classification of the league');
    assert_equals('PREMIUM', $epl['tier']);

    $saudi = $repo->findCompetitionMapping('api-football', '307');
    assert_not_null($saudi);
    assert_equals('SAUDI_PRO_LEAGUE', $saudi['internal_id']);
    assert_false((bool) $saudi['premium'], 'a league that was not classified premium is not premium');

    // The same competition under another feed's id is the same competition.
    $other = $repo->findCompetitionMapping('sportmonks', '1');
    assert_not_null($other);
    assert_equals($epl['internal_id'], $other['internal_id'], 'API-Football 39 and SportMonks 1 are one competition');
    assert_true((bool) $other['premium']);
});

test('multi-provider: a row the provider gave no competition id for is not mapped', function () {
    $repo = new FootballRepositoryStub();
    $config = new FootballConfiguration();
    $manager = new SportsProviderManager();
    $manager->register(new FxFullFeed('api-football', [
        fx_multi_row('1', 'Chelsea', 'Liverpool', '2026-09-12T14:00:00Z', 'Premier League', ''),
    ]));
    $gateway = new ProviderGateway($manager, $config);
    $service = new MatchIntelligenceService($gateway, new ProviderSelector($gateway, $config), $repo, $config);
    $notes = [];
    $service->matches([], $notes);
    assert_equals([], $repo->competitionMappings, 'no competition id, no mapping — a guessed league would merge two competitions');
});

test('multi-provider: premium is classification, so the same league under three ids is premium in all of them', function () {
    $config = new FootballConfiguration(['WINDELS_FOOTBALL_PREMIUM_COMPETITIONS' => 'Premier League, UEFA Champions League, 39']);
    assert_true($config->isPremiumCompetition('Premier League'), 'by name');
    assert_true($config->isPremiumCompetition('English Premier League'), 'a provider\'s longer name for the same league');
    assert_true($config->isPremiumCompetition('Saudi Pro League', '39'), 'by provider competition id');
    assert_false($config->isPremiumCompetition('Saudi Pro League', '307'));
    assert_false($config->isPremiumCompetition(null, null), 'nothing named means nothing is premium');
});

test('multi-provider: the Premium League selector offers every premium league on the date, not only the featured one', function () {
    require_once TESTSPATH . 'football_support.php';
    $config = ['WINDELS_FOOTBALL_PREMIUM_COMPETITIONS' => 'Premier League, La Liga, UEFA Champions League'];
    [$repo, , $module, $day] = fx_multi_paged_day(2, $config);
    // A second premium league with a match on the same date, stored the way a
    // sync stores one: a competition row and a fixture pointing at it.
    $laLiga = $repo->saveCompetition(1, ['externalId' => '140', 'name' => 'La Liga', 'country' => 'Spain']);
    foreach ($repo->fixtures as &$storedFixture) {
        if ((string) ($storedFixture['external_id'] ?? '') === 'fx-page-1') {
            $storedFixture['competition'] = 'La Liga';
            $storedFixture['competition_id'] = (int) ($laLiga['id'] ?? 140);
        }
    }
    unset($storedFixture);

    $listed = $module->feed()->competitions($day, null);
    $premiumNames = array_column((array) ($listed['premiumCompetitions'] ?? []), 'name');
    sort($premiumNames);
    assert_in_array('Premier League', $premiumNames, 'the configured premium league is offered');
    assert_in_array('La Liga', $premiumNames, 'so is the second one');
    assert_false(in_array('Saudi Pro League', $premiumNames, true), 'a league that was not classified premium is not offered as premium');
    // The featured competition is still named separately, and is one of them.
    assert_in_array((string) ($listed['premium']['name'] ?? ''), $premiumNames);
    foreach ((array) ($listed['competitions'] ?? []) as $competition) {
        $isPremium = in_array((string) ($competition['name'] ?? ''), $premiumNames, true);
        assert_equals($isPremium, !empty($competition['premium']), 'each competition is marked premium exactly when it was classified premium');
    }
});
