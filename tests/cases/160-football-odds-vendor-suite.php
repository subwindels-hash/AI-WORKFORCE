<?php
/**
 * The api-football vendor odds family on ApiFootballProvider.
 *
 * The vendor's dashboard tester exercises SIX odds endpoints; until now the
 * adapter only implemented /odds?fixture=… . These cases pin the five new
 * capabilities — oddsByQuery (every documented /odds filter, page-following),
 * oddsMapping, oddsLive (tolerant row shapes + the live-only flags), and the
 * three reference catalogs with TTL memoization — plus the pass-throughs on
 * the legacy FootballApiProvider wrapper.
 *
 * The wire bodies mirror tests/mock-api-football/server.mjs, which speaks the
 * same shapes end-to-end over HTTP (mock key mock-key-12345, port 9377) when
 * the app is pointed at it with API_FOOTBALL_BASE_URL=http://127.0.0.1:9377.
 * Cases here stay transport-canned so the suite never needs the node process.
 */
use AIWorkforce\Sports\Providers\ApiFootballProvider;
use AIWorkforce\Sports\Providers\FootballApiProvider;
use AIWorkforce\Sports\Providers\ProviderException;

// ─── Helpers ────────────────────────────────────────────────────────────────

/** Transport that records every URL and answers with canned bodies in order
 *  (the last body repeats once the list is exhausted). */
final class OddsRecordingTransport
{
    public array $urls = [];
    public int $calls = 0;

    public function __construct(private array $bodies) {}

    public function __invoke(string $url, array $headers): array
    {
        $this->calls++;
        $this->urls[] = $url;
        $body = $this->bodies[min($this->calls - 1, count($this->bodies) - 1)];
        return ['status' => 200, 'body' => is_string($body) ? $body : json_encode($body)];
    }
}

/** A vendor /odds fixture row: one fixture, two bookmakers, two bet families. */
function oddsVendorRow(): array
{
    $update = '2026-09-14T10:00:00+00:00';
    return [
        'fixture' => ['id' => 718244, 'update' => $update],
        'league' => ['id' => 39, 'name' => 'Premier League', 'country' => 'England', 'season' => 2026],
        'bookmakers' => [
            ['id' => 1, 'name' => 'Bet365', 'bets' => [
                ['id' => 1, 'name' => 'Match Winner', 'update' => $update, 'values' => [
                    ['value' => 'Home', 'odd' => '2.10'],
                    ['value' => 'Draw', 'odd' => '3.40'],
                    ['value' => 'Away', 'odd' => '3.20'],
                ]],
                ['id' => 5, 'name' => 'Goals Over/Under', 'update' => $update, 'values' => [
                    ['value' => 'Over 2.5', 'odd' => '2.05'],
                    ['value' => 'Under 2.5', 'odd' => '1.80'],
                ]],
            ]],
            ['id' => 2, 'name' => 'Bwin', 'bets' => [
                ['id' => 1, 'name' => 'Match Winner', 'update' => $update, 'values' => [
                    ['value' => 'Home', 'odd' => '2.05'],
                ]],
            ]],
        ],
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// oddsByQuery — GET /odds with every documented filter
// ═══════════════════════════════════════════════════════════════════════════

test('oddsByQuery maps a fixture query into the normalized odds shape', function () {
    $t = new OddsRecordingTransport([['response' => [oddsVendorRow()], 'paging' => ['current' => 1, 'total' => 1], 'results' => 1]]);
    $p = new ApiFootballProvider('k', 'https://api.test', 10, $t);
    $rows = $p->oddsByQuery(['fixture' => 718244]);
    assert_equals(1, $t->calls, 'one page was requested');
    assert_contains('/odds?fixture=718244', $t->urls[0], 'the fixture filter is on the wire');
    // 5 selections from Bet365 + 1 from Bwin — every bookmaker is kept, not just the first.
    assert_equals(6, count($rows));
    $home = $rows[0];
    assert_equals('MATCH_RESULT', $home['market']);
    assert_equals('HOME', $home['selection']);
    assert_equals(2.10, $home['decimalOdds']);
    assert_equals('Bet365', $home['bookmaker']);
    assert_equals('718244', $home['fixtureId']);
    assert_not_null($home['observedAt'], 'each observation is timestamped');
    $over = $rows[3];
    assert_equals('TOTAL_GOALS', $over['market']);
    assert_equals('OVER_2_5', $over['selection']);
    assert_equals('Bwin', $rows[5]['bookmaker'], 'the second bookmaker is not dropped');
});

test('oddsByQuery follows the vendor 10-rows-per-page pagination', function () {
    $rowA = oddsVendorRow();
    $rowB = oddsVendorRow();
    $rowB['fixture']['id'] = 718245;
    $t = new OddsRecordingTransport([
        ['response' => [$rowA], 'paging' => ['current' => 1, 'total' => 2], 'results' => 10],
        ['response' => [$rowB], 'paging' => ['current' => 2, 'total' => 2], 'results' => 7],
    ]);
    $p = new ApiFootballProvider('k', 'https://api.test', 10, $t);
    $rows = $p->oddsByQuery(['league' => 39, 'season' => 2026, 'date' => '2026-09-15']);
    assert_equals(2, $t->calls, 'both pages were walked');
    assert_contains('league=39&season=2026&date=2026-09-15', $t->urls[0], 'the league+season+date filters are on the wire');
    assert_contains('page=2', $t->urls[1], 'page 2 is requested explicitly');
    assert_equals(12, count($rows), 'rows from both pages are merged');
    assert_equals('718245', $rows[6]['fixtureId'], 'the second page fixture arrives');
});

test('oddsByQuery passes bet and bookmaker filters through', function () {
    $t = new OddsRecordingTransport([['response' => [], 'paging' => ['current' => 1, 'total' => 1], 'results' => 0]]);
    $p = new ApiFootballProvider('k', 'https://api.test', 10, $t);
    $p->oddsByQuery(['league' => 39, 'season' => 2026, 'bet' => 5, 'bookmaker' => 2]);
    assert_contains('bet=5', $t->urls[0]);
    assert_contains('bookmaker=2', $t->urls[0]);
});

test('oddsByQuery rejects an unfiltered query instead of burning the quota', function () {
    $t = new OddsRecordingTransport([]);
    $p = new ApiFootballProvider('k', 'https://api.test', 10, $t);
    $threw = false;
    try {
        $p->oddsByQuery([]);
    } catch (ProviderException $e) {
        $threw = true;
        assert_equals(ProviderException::DATA_ERROR, $e->status);
        assert_contains('date=', $e->getMessage(), 'the error points at the date filter for an "all odds today" pull');
    }
    assert_true($threw, 'an unfiltered /odds pull is refused');
    assert_equals(0, $t->calls, 'no request was made');
});

test('oddsByQuery rejects non-numeric ids and malformed dates', function () {
    $p = new ApiFootballProvider('k', 'https://api.test', 10, new OddsRecordingTransport([]));
    foreach ([['fixture' => 'abc'], ['league' => '1e3'], ['bet' => 'twelve']] as $query) {
        try {
            $p->oddsByQuery($query);
            assert_true(false, 'a non-numeric id must be refused: ' . json_encode($query));
        } catch (ProviderException $e) {
            assert_equals(ProviderException::DATA_ERROR, $e->status);
        }
    }
    try {
        $p->oddsByQuery(['date' => '15-09-2026']);
        assert_true(false, 'a non-ISO date must be refused');
    } catch (ProviderException $e) {
        assert_equals(ProviderException::DATA_ERROR, $e->status);
        assert_contains('YYYY-MM-DD', $e->getMessage());
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// oddsMapping — GET /odds/mapping
// ═══════════════════════════════════════════════════════════════════════════

test('oddsMapping maps the league+season grouped shape with the vendor paging block', function () {
    $t = new OddsRecordingTransport([[
        'response' => [['league' => ['id' => 39, 'season' => 2026], 'fixtures' => [718244, 718245, 718246]]],
        'paging' => ['current' => 1, 'total' => 3],
        'results' => 250,
    ]]);
    $p = new ApiFootballProvider('k', 'https://api.test', 10, $t);
    $payload = $p->oddsMapping(['league' => 39, 'season' => 2026]);
    assert_equals(1, $t->calls, 'one page per call — walking the mapping stays the caller decision');
    assert_contains('/odds/mapping?league=39&season=2026', $t->urls[0]);
    assert_equals(1, count($payload['rows']));
    $row = $payload['rows'][0];
    assert_equals(39, $row['leagueId']);
    assert_equals(2026, $row['season']);
    assert_equals([718244, 718245, 718246], $row['fixtureIds']);
    assert_equals(3, $row['fixtureCount']);
    assert_equals(['current' => 1, 'total' => 3], $payload['paging'], 'the vendor paging block is passed through');
    assert_equals(250, $payload['results']);
});

test('oddsMapping tolerates fixture-keyed rows (a vendor reshape must not read as zero fixtures)', function () {
    $t = new OddsRecordingTransport([[
        'response' => [
            ['league' => ['id' => 39, 'season' => 2026], 'fixture' => ['id' => 718244, 'date' => '2026-09-15T19:00:00+00:00']],
            ['league' => ['id' => 39, 'season' => 2026], 'fixture' => ['id' => 718245, 'date' => '2026-09-15T21:00:00+00:00']],
        ],
        'paging' => ['current' => 1, 'total' => 1],
        'results' => 2,
    ]]);
    $p = new ApiFootballProvider('k', 'https://api.test', 10, $t);
    $payload = $p->oddsMapping(['date' => '2026-09-15']);
    assert_equals(2, count($payload['rows']));
    assert_equals([718244], $payload['rows'][0]['fixtureIds']);
    assert_equals('2026-09-15T19:00:00+00:00', $payload['rows'][0]['fixtureDate']);
    assert_equals([718245], $payload['rows'][1]['fixtureIds']);
});

test('oddsMapping asks for page 2 explicitly and validates its filters', function () {
    $t = new OddsRecordingTransport([['response' => [], 'paging' => ['current' => 2, 'total' => 2], 'results' => 0]]);
    $p = new ApiFootballProvider('k', 'https://api.test', 10, $t);
    $payload = $p->oddsMapping(['league' => 39, 'season' => 2026, 'page' => 2]);
    assert_contains('page=2', $t->urls[0]);
    assert_equals(2, $payload['paging']['current']);
    try {
        $p->oddsMapping(['league' => 'premier']);
        assert_true(false, 'a non-numeric league must be refused');
    } catch (ProviderException $e) {
        assert_equals(ProviderException::DATA_ERROR, $e->status);
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// oddsLive — GET /odds/live (in-play only, NO season, live-only flags)
// ═══════════════════════════════════════════════════════════════════════════

/** A vendor /odds/live row for an in-play fixture. */
function oddsLiveVendorRow(): array
{
    return [
        'fixture' => ['id' => 700001, 'date' => '2026-09-14T16:35:00+00:00', 'status' => ['long' => 'Second Half', 'short' => '2H', 'elapsed' => 63]],
        'league' => ['id' => 39, 'name' => 'Premier League', 'country' => 'England', 'season' => 2026],
        'odds' => [
            'stopped' => false,
            'blocked' => false,
            'finished' => false,
            'bets' => [
                ['id' => 3, 'name' => 'Match Winner', 'values' => [
                    ['value' => 'Home', 'odd' => '1.90'],
                    ['value' => 'Draw', 'odd' => '3.10'],
                    ['value' => 'Away', 'odd' => '4.20'],
                ]],
                ['id' => 5, 'name' => 'Goals Over/Under', 'values' => [
                    ['value' => 'Over 1.5', 'odd' => '1.25', 'main' => true],
                    ['value' => 'Under 1.5', 'odd' => '3.80', 'main' => true],
                    ['value' => 'Over 2.5', 'odd' => 'x-invalid'],   // no price → skipped, never 0.0
                ]],
            ],
        ],
    ];
}

test('oddsLive maps the documented shape with live flags, main markers and live bet ids', function () {
    $t = new OddsRecordingTransport([['response' => [oddsLiveVendorRow()], 'paging' => ['current' => 1, 'total' => 1], 'results' => 1]]);
    $p = new ApiFootballProvider('k', 'https://api.test', 10, $t);
    $rows = $p->oddsLive(700001);
    assert_contains('api.test/odds/live?fixture=700001', $t->urls[0]);
    // 3 match-winner + 2 over/under selections; the price-less value is skipped.
    assert_equals(5, count($rows));
    $first = $rows[0];
    assert_equals('700001', $first['fixtureId']);
    assert_equals(39, $first['leagueId']);
    assert_equals('MATCH_RESULT', $first['market']);
    assert_equals('HOME', $first['selection']);
    assert_equals(1.90, $first['decimalOdds']);
    assert_equals(3, $first['betId'], 'live bet ids are carried (a separate id space from pre-match)');
    assert_false($first['stopped']);
    assert_false($first['blocked']);
    assert_false($first['finished']);
    assert_not_null($first['observedAt']);
    $over = $rows[3];
    assert_equals('TOTAL_GOALS', $over['market']);
    assert_equals('OVER_1_5', $over['selection']);
    assert_equals('Over 1.5', $over['valueLabel'], 'the vendor label is preserved next to the canonical selection');
    assert_true($over['main'], 'the main-line marker survives the mapping');
    assert_equals(5, $over['betId']);
});

test('oddsLive sends league and bet filters, and only those', function () {
    $t = new OddsRecordingTransport([['response' => [], 'paging' => ['current' => 1, 'total' => 1], 'results' => 0]]);
    $p = new ApiFootballProvider('k', 'https://api.test', 10, $t);
    $p->oddsLive(null, 39, 5);
    assert_contains('api.test/odds/live?league=39&bet=5', $t->urls[0]);
    // The signature has no season parameter at all — the endpoint documents
    // none, and the controller rejects one with the reason before this layer.
    $t2 = new OddsRecordingTransport([['response' => [], 'paging' => ['current' => 1, 'total' => 1], 'results' => 0]]);
    $p2 = new ApiFootballProvider('k', 'https://api.test', 10, $t2);
    $p2->oddsLive();
    assert_equals('https://api.test/odds/live', $t2->urls[0], 'an unfiltered live pull asks for the whole in-play board');
});

test('oddsLive tolerates odds-as-bet-list and top-level bets shapes', function () {
    // Shape A: `odds` is directly the list of bet groups (no flag wrapper).
    $rowA = oddsLiveVendorRow();
    $bets = $rowA['odds']['bets'];
    $rowA['odds'] = $bets;
    // Shape B: the bets sit at the row top level.
    $rowB = oddsLiveVendorRow();
    $rowB['bets'] = $rowB['odds']['bets'];
    unset($rowB['odds']);
    $t = new OddsRecordingTransport([['response' => [$rowA, $rowB], 'paging' => ['current' => 1, 'total' => 1], 'results' => 2]]);
    $p = new ApiFootballProvider('k', 'https://api.test', 10, $t);
    $rows = $p->oddsLive();
    assert_equals(10, count($rows), 'both tolerated shapes map (5 selections each)');
    foreach ($rows as $r) {
        assert_equals('700001', $r['fixtureId']);
        assert_false($r['stopped'], 'missing flags default to false, not null');
    }
});

test('oddsLive propagates the vendor soft error when season reaches the wire', function () {
    // The controller rejects season= for /odds/live before calling the
    // provider; if a future caller bypasses that, the vendor's own soft-error
    // envelope must surface as a classified failure — never as "no odds".
    $t = new OddsRecordingTransport([[
        'response' => [],
        'errors' => ['season' => 'This endpoint does not accept the season parameter. Use league, fixture or bet.'],
        'results' => 0,
        'paging' => ['current' => 1, 'total' => 1],
    ]]);
    $p = new ApiFootballProvider('k', 'https://api.test', 10, $t);
    try {
        $p->oddsLive();
        assert_true(false, 'the soft-error envelope must throw');
    } catch (ProviderException $e) {
        assert_not_equals(ProviderException::DATA_ERROR, $e->status, 'a parameter refusal is classified, not a data problem');
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// Catalogs — /odds/bets, /odds/bookmakers, /odds/live/bets (TTL memoized)
// ═══════════════════════════════════════════════════════════════════════════

test('oddsBets returns the catalog and filters id/search locally', function () {
    $t = new OddsRecordingTransport([[
        'response' => [
            ['id' => 1, 'name' => 'Match Winner'],
            ['id' => 5, 'name' => 'Goals Over/Under'],
            ['id' => 6, 'name' => 'Goals Over/Under First Half'],
            ['id' => 8, 'name' => 'Both Teams Score'],
        ],
        'paging' => ['current' => 1, 'total' => 1],
        'results' => 4,
    ]]);
    $p = new ApiFootballProvider('k', 'https://api.test', 10, $t);
    $all = $p->oddsBets();
    assert_equals(4, count($all));
    assert_equals(['id' => 5, 'name' => 'Goals Over/Under'], $all[1], 'rows are normalized to int ids');
    // id and search are memo-local lookups: no second paid call each time.
    assert_equals([['id' => 5, 'name' => 'Goals Over/Under']], $p->oddsBets(5));
    $overs = $p->oddsBets(null, 'OVER');
    assert_equals(2, count($overs), 'search is case-insensitive substring');
    assert_equals(1, $t->calls, 'id/search hit the memo, not the wire');
});

test('oddsBookmakers and oddsLiveBets memoize with separate cache keys', function () {
    $t = new OddsRecordingTransport([
        ['response' => [['id' => 1, 'name' => 'Bet365'], ['id' => 2, 'name' => 'Bwin']], 'results' => 2],  // /odds/bookmakers
        ['response' => [['id' => 1, 'name' => 'Over/Under Extra Time'], ['id' => 3, 'name' => 'Match Winner']], 'results' => 2], // /odds/live/bets
    ]);
    $p = new ApiFootballProvider('k', 'https://api.test', 10, $t);
    assert_equals(2, count($p->oddsBookmakers()));
    assert_equals(2, count($p->oddsLiveBets()));
    assert_equals(2, $t->calls, 'one call per catalog');
    // Repeat reads stay free within the TTL…
    $p->oddsBookmakers(2);
    $p->oddsLiveBets();
    assert_equals(2, $t->calls);
    // …and the two catalogs never bleed into each other: live bet id 1 is
    // "Over/Under Extra Time", NOT pre-match "Match Winner".
    assert_equals('Over/Under Extra Time', $p->oddsLiveBets()[0]['name']);
    assert_equals('Bwin', $p->oddsBookmakers(2)[0]['name']);
    assert_equals('/odds/bookmakers', parse_url($t->urls[0], PHP_URL_PATH));
    assert_equals('/odds/live/bets', parse_url($t->urls[1], PHP_URL_PATH));
});

// ═══════════════════════════════════════════════════════════════════════════
// Legacy FootballApiProvider wrapper — pass-throughs must not lose capabilities
// ═══════════════════════════════════════════════════════════════════════════

test('the legacy wrapper delegates the odds suite to the api-football adapter', function () {
    $t = new OddsRecordingTransport([['response' => [['id' => 5, 'name' => 'Goals Over/Under']], 'results' => 1]]);
    $w = new FootballApiProvider('feed', 'https://api.test', 'k', 'api-football', 10, $t);
    assert_equals([['id' => 5, 'name' => 'Goals Over/Under']], $w->oddsBets());
    $t2 = new OddsRecordingTransport([['response' => [oddsLiveVendorRow()], 'results' => 1]]);
    $w2 = new FootballApiProvider('feed', 'https://api.test', 'k', 'api-football', 10, $t2);
    assert_equals(5, count($w2->oddsLive(700001)));
});

test('the legacy wrapper refuses the odds suite honestly for non-api-football delegates', function () {
    $w = new FootballApiProvider('feed', 'https://api.test', 'k', 'thesportsdb');
    foreach (['oddsByQuery' => [[]], 'oddsMapping' => [[]], 'oddsLive' => [null], 'oddsBets' => [null], 'oddsBookmakers' => [null], 'oddsLiveBets' => []] as $method => $args) {
        try {
            $w->{$method}(...$args);
            assert_true(false, $method . ' must refuse on a thesportsdb delegate');
        } catch (ProviderException $e) {
            assert_equals(ProviderException::DATA_ERROR, $e->status);
            assert_contains('does not', $e->getMessage());
        }
    }
});
