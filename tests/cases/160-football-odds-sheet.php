<?php
/**
 * Football odds surface — the full API-Football odds coverage:
 *   - pre-match /odds  → stored sheet (bookmaker detail preserved)
 *   - /odds/live       → in-play snapshot with suspended/handicap/main flags
 *   - /odds/bookmakers and the TWO bet catalogs (/odds/bets vs /odds/live/bets)
 *
 * Discipline under test: a view never spends a provider request; a refresh is
 * billed through the gateway with the same normalizer the sports sync uses;
 * live prices are a snapshot with the vendor's flags carried through; and an
 * empty answer is DATA_UNAVAILABLE with the reason, never an invented price.
 */

require_once TESTSPATH . 'football_support.php';

use AIWorkforce\Football\DataState;
use AIWorkforce\Football\FootballConfiguration;
use AIWorkforce\Football\OddsSheetService;
use AIWorkforce\Football\ProviderGateway;
use AIWorkforce\Sports\Providers\ApiFootballProvider;
use AIWorkforce\Sports\Providers\SportsDataProvider;
use AIWorkforce\Sports\Providers\SportsProviderManager;

// ─── fixtures: real API-Football wire shapes ─────────────────────────────────

/** A pre-match /odds payload exactly as v3 serializes it. */
function fx_odds_wire(): array
{
    return ['response' => [[
        'fixture' => ['id' => 9001, 'update' => '2026-09-14T09:00:00+00:00'],
        'league' => ['id' => 39, 'season' => 2026],
        'bookmakers' => [
            ['id' => 1, 'name' => 'Bet365', 'bets' => [
                ['id' => 1, 'name' => 'Match Winner', 'update' => '2026-09-14T09:00:00+00:00', 'values' => [
                    ['value' => 'Home', 'odd' => '1.95'], ['value' => 'Draw', 'odd' => '3.60'], ['value' => 'Away', 'odd' => '4.20'],
                ]],
                ['id' => 5, 'name' => 'Goals Over/Under', 'values' => [
                    ['value' => 'Over 2.5', 'odd' => '1.80'], ['value' => 'Under 2.5', 'odd' => '2.00'],
                ]],
            ]],
            ['id' => 6, 'name' => 'Pinnacle', 'bets' => [
                ['id' => 1, 'name' => 'Match Winner', 'values' => [
                    ['value' => 'Home', 'odd' => '2.02'], ['value' => 'Draw', 'odd' => '3.55'], ['value' => 'Away', 'odd' => '4.05'],
                ]],
            ]],
        ],
    ]], 'paging' => ['current' => 1, 'total' => 1]];
}

/** An in-play /odds/live payload: no bookmaker grouping; handicap/main/suspended present. */
function fx_live_odds_wire(): array
{
    return ['response' => [[
        'fixture' => ['id' => 9001, 'status' => ['long' => 'Second Half', 'elapsed' => 62]],
        'league' => ['id' => 39, 'season' => 2026],
        'status' => ['stopped' => false, 'blocked' => false, 'finished' => false],
        'update' => '2026-09-14T10:02:00+00:00',
        'odds' => [
            ['id' => 59, 'name' => 'Fulltime Result', 'values' => [
                ['value' => 'Home', 'odd' => '1.40', 'handicap' => null, 'main' => null, 'suspended' => false],
                ['value' => 'Away', 'odd' => '9.00', 'handicap' => null, 'main' => null, 'suspended' => true],
            ]],
            ['id' => 25, 'name' => 'Over/Under Line', 'values' => [
                ['value' => 'Over', 'odd' => '1.85', 'handicap' => '2.5', 'main' => true, 'suspended' => false],
                ['value' => 'Over', 'odd' => '3.10', 'handicap' => '3.5', 'main' => false, 'suspended' => false],
            ]],
        ],
    ]], 'paging' => ['current' => 1, 'total' => 1]];
}

/** An ApiFootballProvider whose transport answers from a routing table; counts requests. */
function fx_odds_provider(array $routes, ?array &$log = null): ApiFootballProvider
{
    $log = [];
    return new ApiFootballProvider('test-key', 'https://v3.football.api-sports.io', 10,
        function (string $url, array $headers) use ($routes, &$log): array {
            $log[] = $url;
            foreach ($routes as $needle => $payload) {
                if (str_contains($url, $needle)) return ['status' => 200, 'body' => json_encode($payload)];
            }
            return ['status' => 200, 'body' => json_encode(['response' => []])];
        });
}

/** A wired OddsSheetService over the stub football repo + an in-memory sports store. */
function fx_odds_harness(array $routes, ?array &$log = null): array
{
    $repo = new FootballRepositoryStub();
    $providerRow = $repo->ensureProvider('api-football', ['displayName' => 'API-Football']);
    $fixture = $repo->saveFixture((int) $providerRow['id'], [
        'externalId' => '9001', 'homeTeam' => 'Arsenal', 'awayTeam' => 'Brighton',
        'competition' => 'Premier League', 'kickoff' => gmdate('c', time() + 7200), 'status' => 'SCHEDULED',
        'sourceTimestamp' => gmdate('c'),
    ]);
    $provider = fx_odds_provider($routes, $log);
    $manager = new SportsProviderManager();
    $manager->register($provider);
    $config = new FootballConfiguration();
    $gateway = new ProviderGateway($manager, $config);
    $service = new OddsSheetService($repo, $gateway, $config, sys_get_temp_dir());
    $store = new SportsRepositoryStub();
    $service->bindSportsStore($store);
    return [$service, $repo, $store, $fixture, $gateway];
}

// ─── 1. adapter: /odds/live wire mapping ─────────────────────────────────────

test('api-football liveOdds maps the documented in-play wire shape, flags intact', function () {
    $p = fx_odds_provider(['/odds/live' => fx_live_odds_wire()]);
    $rows = $p->liveOdds('9001');
    assert_equals(4, count($rows), 'four live prices arrive as four rows');
    $home = $rows[0];
    assert_equals('MATCH_RESULT', $home['market'], 'Fulltime Result normalizes to MATCH_RESULT');
    assert_equals('HOME', $home['selection']);
    assert_equals(1.40, $home['decimalOdds']);
    assert_true($home['live'] === true, 'live rows are flagged live');
    assert_false($home['suspended'], 'an open price is not suspended');
    $away = $rows[1];
    assert_true($away['suspended'], 'the bookmaker-suspended price carries its flag instead of being dropped');
    // The handicapped Over lines keep their line and main flag.
    $over25 = $rows[2];
    assert_equals('2.5', $over25['handicap']);
    assert_true($over25['main'], 'the primary line is marked main');
    assert_equals('OVER_2_5', $over25['selection'], 'handicap folds into the canonical selection');
    $over35 = $rows[3];
    assert_equals('OVER_3_5', $over35['selection']);
    assert_false($over35['main']);
    // Match-level betting state travels on every row.
    assert_false($over25['blocked']);
    assert_equals('2026-09-14T10:02:00+00:00', $over25['updatedAt'], 'the vendor update clock is preserved');
});

test('api-football liveOdds answers [] for a fixture the feed does not quote', function () {
    $p = fx_odds_provider([]);
    assert_equals([], $p->liveOdds('404404'), 'no quotes ⇒ empty list, never an invented price');
});

// ─── 2. adapter: reference catalogs ──────────────────────────────────────────

test('api-football bookmaker and bet catalogs map with the documented scope split', function () {
    $p = fx_odds_provider([
        '/odds/bookmakers' => ['response' => [['id' => 1, 'name' => 'Bet365'], ['id' => 6, 'name' => 'Pinnacle']]],
        '/odds/live/bets' => ['response' => [['id' => 59, 'name' => 'Fulltime Result']]],
        '/odds/bets' => ['response' => [['id' => 1, 'name' => 'Match Winner'], ['id' => 5, 'name' => 'Goals Over/Under']]],
    ]);
    $books = $p->oddsBookmakers();
    assert_equals(2, count($books));
    assert_equals('Bet365', $books[0]['name']);
    $pre = $p->oddsBetTypes(false);
    assert_equals('PRE_MATCH', $pre[0]['scope'], 'ids from /odds/bets are pre-match only');
    assert_equals('MATCH_RESULT', $pre[0]['canonicalMarket'], 'catalog names map into the canonical vocabulary');
    $live = $p->oddsBetTypes(true);
    assert_equals('LIVE', $live[0]['scope'], 'ids from /odds/live/bets are live only');
    assert_equals('MATCH_RESULT', $live[0]['canonicalMarket']);
});

// ─── 3. gateway capabilities ─────────────────────────────────────────────────

test('the gateway advertises the new odds capabilities for api-football', function () {
    $manager = new SportsProviderManager();
    $manager->register(fx_odds_provider([]));
    $gateway = new ProviderGateway($manager, new FootballConfiguration());
    foreach (['liveOdds', 'oddsBookmakers', 'oddsBetTypes'] as $capability) {
        assert_true($gateway->supports($capability), $capability . ' is a supported capability');
    }
    $caps = $gateway->capabilities()['api-football'];
    assert_true($caps['liveOdds'] && $caps['oddsBookmakers'] && $caps['oddsBetTypes']);
});

test('a provider without the live endpoint fails the capability honestly', function () {
    $manager = new SportsProviderManager();
    $manager->register(new class implements SportsDataProvider {
        public function id(): string { return 'basic-feed'; }
        public function health(): array { return ['status' => 'ONLINE']; }
        public function fixtures(array $query): array { return []; }
        public function odds(string $fixtureExternalId): array { return []; }
        public function results(string $fixtureExternalId): array { return []; }
    });
    $gateway = new ProviderGateway($manager, new FootballConfiguration());
    assert_false($gateway->supports('liveOdds'));
    $gateway->beginSweep(5);
    $outcome = $gateway->call('liveOdds', fn($p) => $p->liveOdds('1'));
    assert_false($outcome['ok']);
    assert_true(str_contains((string) $outcome['failures']['basic-feed'], 'UNSUPPORTED_CAPABILITY'),
        'the failure names the capability gap instead of silently skipping');
});

// ─── 4. the sheet: stored reads are free, refresh is billed ──────────────────

test('the stored odds sheet reports DATA_UNAVAILABLE with a reason before any odds exist', function () {
    [$service, , , $fixture] = fx_odds_harness([]);
    $sheet = $service->sheet((int) $fixture['id']);
    assert_equals(DataState::UNAVAILABLE, $sheet['state']);
    assert_true(str_contains((string) $sheet['reason'], 'No bookmaker odds are stored'));
    assert_equals([], $sheet['markets'], 'no price is invented for an empty sheet');
});

test('a missing fixture is NOT an empty sheet — it is named as missing', function () {
    [$service] = fx_odds_harness([]);
    $sheet = $service->sheet(777777);
    assert_equals(DataState::UNAVAILABLE, $sheet['state']);
    assert_true(str_contains((string) $sheet['reason'], 'not stored'));
});

test('refresh() bills one provider call, persists through the normalizer, and the sheet reads it back grouped by bookmaker', function () {
    [$service, , $store, $fixture] = fx_odds_harness(['/odds?' => fx_odds_wire()], $log);
    $result = $service->refresh((int) $fixture['id']);
    assert_equals(DataState::AVAILABLE, $result['state']);
    assert_equals('api-football', $result['refreshed']['provider']);
    assert_equals(8, $result['refreshed']['fetched'], 'Bet365 MW(3) + O/U(2) + Pinnacle MW(3) = 8 flat rows');
    assert_equals(8, $result['refreshed']['stored']);
    assert_equals(0, $result['refreshed']['invalid']);
    // Grouping: MATCH_RESULT carries both books' newest quote per selection.
    $byMarket = [];
    foreach ($result['markets'] as $market) $byMarket[$market['market']] = $market;
    assert_true(isset($byMarket['MATCH_RESULT']) && isset($byMarket['TOTAL_GOALS']));
    $mw = $byMarket['MATCH_RESULT'];
    $home = null;
    foreach ($mw['selections'] as $selection) if ($selection['selection'] === 'HOME') $home = $selection;
    assert_true($home !== null);
    assert_equals(2, $home['quoteCount'], 'each bookmaker contributes one (newest) quote');
    assert_equals(2.02, $home['bestOdds'], 'best price = the highest stored book price');
    assert_equals(1.95, $home['lowOdds']);
    $books = array_map(static fn(array $q): ?string => $q['bookmaker'], $home['quotes']);
    sort($books);
    assert_equals(['Bet365', 'Pinnacle'], $books, 'bookmaker names survive persistence');
    assert_equals('FRESH', $mw['freshness'], 'a just-stored market is FRESH');
    // And a later view is free: sheet() must answer from the store without any new URL.
    $before = count($log);
    $view = $service->sheet((int) $fixture['id']);
    assert_equals($before, count($log), 'viewing the sheet costs zero provider requests');
    assert_equals(DataState::AVAILABLE, $view['state']);
});

test('an invalid provider price is counted invalid, not stored and not shown', function () {
    $wire = fx_odds_wire();
    // decimal odds of 1.0 and 0 are not real prices.
    $wire['response'][0]['bookmakers'][0]['bets'][0]['values'][0]['odd'] = '1.0';
    $wire['response'][0]['bookmakers'][0]['bets'][1]['values'][0]['odd'] = '0';
    [$service, , , $fixture] = fx_odds_harness(['/odds?' => $wire]);
    $result = $service->refresh((int) $fixture['id']);
    assert_equals(8, $result['refreshed']['fetched']);
    assert_equals(6, $result['refreshed']['stored']);
    assert_equals(2, $result['refreshed']['invalid'], 'both broken prices are counted, not hidden');
});

// ─── 5. live snapshots ───────────────────────────────────────────────────────

test('live() refuses to dress a pre-match fixture as in-play', function () {
    [$service, , , $fixture] = fx_odds_harness(['/odds/live' => fx_live_odds_wire()], $log);
    $result = $service->live((int) $fixture['id']);
    assert_equals('NOT_IN_PLAY', $result['state']);
    assert_equals([], array_filter($log, fn(string $u) => str_contains($u, '/odds/live')),
        'a fixture that is not live never triggers a live-odds request');
});

test('live() fetches once for an in-play fixture, persists the snapshot, then serves the stored snapshot inside the interval', function () {
    [$service, $repo, , $fixture] = fx_odds_harness(['/odds/live' => fx_live_odds_wire()], $log);
    // Flip the stored fixture to LIVE the same way a live sweep would.
    foreach ($repo->fixtures as &$row) if ((int) $row['id'] === (int) $fixture['id']) { $row['status'] = 'LIVE'; $row['match_state'] = 'IN_PLAY'; }
    unset($row);
    $first = $service->live((int) $fixture['id']);
    assert_equals(DataState::AVAILABLE, $first['state']);
    assert_true($first['fetched'], 'the first live read fetches');
    $snapshot = $first['snapshot'];
    assert_equals('api-football', $snapshot['provider']);
    assert_equals(4, $snapshot['rows']);
    $liveCalls = count(array_filter($log, fn(string $u) => str_contains($u, '/odds/live')));
    assert_equals(1, $liveCalls);
    // Suspended flag survives into the grouped snapshot.
    $mw = null;
    foreach ($snapshot['markets'] as $market) if ($market['market'] === 'MATCH_RESULT') $mw = $market;
    assert_true($mw !== null);
    assert_equals(1, $mw['suspended'], 'one MATCH_RESULT price is suspended and says so');
    // A second read inside the interval is served from the stored snapshot.
    $second = $service->live((int) $fixture['id']);
    assert_false($second['fetched'], 'inside the live interval the stored snapshot answers');
    assert_equals($liveCalls, count(array_filter($log, fn(string $u) => str_contains($u, '/odds/live'))),
        'no additional provider request was made');
    assert_true(is_int($second['ageSeconds']));
    // The snapshot is persisted as a LIVE_ODDS statistics row, auditable later.
    $stored = $repo->findFixtureStatistics((int) $fixture['id'], 'LIVE_ODDS');
    assert_true($stored !== null, 'the snapshot is a stored row, not only a response');
});

// ─── 6. catalogs through the service (cache discipline) ─────────────────────

test('catalogs fetch once and answer from cache afterwards', function () {
    foreach (glob(sys_get_temp_dir() . '/football_odds_catalog_*.json') ?: [] as $file) @unlink($file);
    [$service] = fx_odds_harness([
        '/odds/bookmakers' => ['response' => [['id' => 1, 'name' => 'Bet365']]],
        '/odds/live/bets' => ['response' => [['id' => 59, 'name' => 'Fulltime Result']]],
        '/odds/bets' => ['response' => [['id' => 1, 'name' => 'Match Winner']]],
    ], $log);
    $first = $service->bookmakers();
    assert_equals(DataState::AVAILABLE, $first['state']);
    assert_false($first['cached']);
    assert_equals(1, $first['count']);
    $second = $service->bookmakers();
    assert_true($second['cached'], 'the second read is served from cache');
    $calls = count(array_filter($log, fn(string $u) => str_contains($u, '/odds/bookmakers')));
    assert_equals(1, $calls, 'the bookmaker catalog was fetched exactly once');
    // Bet catalogs keep their scopes separate — different cache files, different rows.
    $pre = $service->betTypes('prematch');
    $live = $service->betTypes('live');
    assert_equals('Match Winner', $pre['rows'][0]['name']);
    assert_equals('Fulltime Result', $live['rows'][0]['name']);
    assert_equals('PRE_MATCH', $pre['rows'][0]['scope']);
    assert_equals('LIVE', $live['rows'][0]['scope']);
    foreach (glob(sys_get_temp_dir() . '/football_odds_catalog_*.json') ?: [] as $file) @unlink($file);
});

test('a failed catalog fetch with no cache is DATA_UNAVAILABLE with the failure map', function () {
    foreach (glob(sys_get_temp_dir() . '/football_odds_catalog_*.json') ?: [] as $file) @unlink($file);
    $repo = new FootballRepositoryStub();
    $manager = new SportsProviderManager();
    $manager->register(new ApiFootballProvider('k', 'https://v3.football.api-sports.io', 10,
        fn(string $url, array $headers) => ['status' => 500, 'body' => '{}']));
    $config = new FootballConfiguration();
    $service = new OddsSheetService($repo, new ProviderGateway($manager, $config), $config, sys_get_temp_dir());
    $result = $service->bookmakers();
    assert_equals(DataState::UNAVAILABLE, $result['state']);
    assert_true(isset($result['failures']['api-football']), 'the per-provider failure is reported verbatim');
    assert_equals([], $result['rows']);
});

// ─── 7. JSON contract ────────────────────────────────────────────────────────

test('every odds payload survives strict JSON encoding', function () {
    [$service, $repo, , $fixture] = fx_odds_harness([
        '/odds?' => fx_odds_wire(), '/odds/live' => fx_live_odds_wire(),
        '/odds/bookmakers' => ['response' => [['id' => 1, 'name' => 'Bet365']]],
    ]);
    foreach ($repo->fixtures as &$row) if ((int) $row['id'] === (int) $fixture['id']) $row['status'] = 'LIVE';
    unset($row);
    foreach ([
        $service->refresh((int) $fixture['id']),
        $service->sheet((int) $fixture['id']),
        $service->live((int) $fixture['id']),
        $service->bookmakers(true),
    ] as $payload) {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        assert_true(is_string($encoded) && $encoded !== '');
    }
    foreach (glob(sys_get_temp_dir() . '/football_odds_catalog_*.json') ?: [] as $file) @unlink($file);
});
