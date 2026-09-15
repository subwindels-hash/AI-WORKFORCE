<?php
/**
 * CLEANUP for the lineless-handicap corruption (see case 152).
 *
 * Before the mapper fix, every api-football handicap quote was stored with its
 * line stripped: "Away +2" and "Away -2" both became a bare AWAY on one
 * market:selection key, so the surviving row's price was attributed to
 * whichever line the model happened to price. Those rows are still sitting in
 * sports_odds on any database that synced before the fix.
 *
 * They are unusable rather than merely suspect — ScoreGridPricer cannot parse
 * a lineless handicap selection, so it refuses them outright. Left in place
 * they are dead records that can only ever be skipped; deleted, the next
 * provider sync repopulates the same fixtures with line-qualified rows.
 *
 * The danger in a DELETE is over-reach, so these cases pin the blast radius:
 * bare sides on a HANDICAP market go, and nothing else does.
 */

function ci153_seed(array $row): void
{
    ci()->db->insert('sports_odds', array_merge([
        'match_id' => 910001, 'provider_id' => 1, 'market' => 'ASIAN_HANDICAP',
        'selection' => 'AWAY', 'decimal_odds' => 7.40,
        'observed_at' => gmdate('c'), 'payload' => '{}',
    ], $row));
}

function ci153_selections(string $market): array
{
    $rows = ci()->db->where('match_id', 910001)->where('market', $market)->get('sports_odds')->result_array();
    $out = array_map(fn(array $r): string => (string) $r['selection'], $rows);
    sort($out);
    return $out;
}

function ci153_reset(): void
{
    ci()->db->where('match_id', 910001)->delete('sports_odds');
}

test('the purge deletes lineless handicap rows and keeps line-qualified ones', function () {
    ci153_reset();
    // The corruption: two different bets that collapsed onto one bare side.
    ci153_seed(['selection' => 'AWAY', 'decimal_odds' => 7.40]);
    ci153_seed(['selection' => 'HOME', 'decimal_odds' => 1.20]);
    // Correctly ingested rows, which must survive untouched.
    ci153_seed(['selection' => 'AWAY_PLUS_2', 'decimal_odds' => 1.14]);
    ci153_seed(['selection' => 'AWAY_MINUS_2', 'decimal_odds' => 7.40]);
    ci153_seed(['selection' => 'HOME_MINUS_1_5', 'decimal_odds' => 2.05]);

    \AIWorkforce\SchemaInstaller::upgrade(function (string $sql) { ci()->db->query($sql); }, 'sqlite');

    assert_equals(
        ['AWAY_MINUS_2', 'AWAY_PLUS_2', 'HOME_MINUS_1_5'],
        ci153_selections('ASIAN_HANDICAP'),
        'only the line-qualified handicap rows remain'
    );
    ci153_reset();
});

test('the purge never touches a market whose bare sides are legitimate', function () {
    ci153_reset();
    // MATCH_RESULT's HOME/DRAW/AWAY are the real, complete selections — a
    // careless DELETE on selection name alone would wipe the 1X2 board.
    foreach (['HOME', 'DRAW', 'AWAY'] as $selection) {
        ci153_seed(['market' => 'MATCH_RESULT', 'selection' => $selection, 'decimal_odds' => 2.50]);
    }
    ci153_seed(['market' => 'DOUBLE_CHANCE', 'selection' => 'HOME_OR_DRAW', 'decimal_odds' => 1.30]);
    ci153_seed(['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimal_odds' => 1.42]);
    ci153_seed(['market' => 'BTTS', 'selection' => 'YES', 'decimal_odds' => 1.75]);

    \AIWorkforce\SchemaInstaller::upgrade(function (string $sql) { ci()->db->query($sql); }, 'sqlite');

    assert_equals(['AWAY', 'DRAW', 'HOME'], ci153_selections('MATCH_RESULT'), 'the 1X2 board is untouched');
    assert_equals(['HOME_OR_DRAW'], ci153_selections('DOUBLE_CHANCE'), 'double chance is untouched');
    assert_equals(['OVER_1_5'], ci153_selections('TOTAL_GOALS'), 'totals are untouched');
    assert_equals(['YES'], ci153_selections('BTTS'), 'BTTS is untouched');
    ci153_reset();
});

test('the purge is idempotent and safe on a clean database', function () {
    ci153_reset();
    ci153_seed(['selection' => 'AWAY_PLUS_1', 'decimal_odds' => 1.50]);
    $run = fn() => \AIWorkforce\SchemaInstaller::upgrade(function (string $sql) { ci()->db->query($sql); }, 'sqlite');
    $run();
    $run();
    $run();
    assert_equals(['AWAY_PLUS_1'], ci153_selections('ASIAN_HANDICAP'), 'repeated runs change nothing further');
    ci153_reset();
});

test('a purged fixture is repopulated correctly by the fixed mapper', function () {
    // End to end: the rows the purge removes are exactly the rows the fixed
    // provider mapper now produces properly, so a sync heals the fixture.
    $provider = new \AIWorkforce\Sports\Providers\ApiFootballProvider(
        'key', 'https://v3.football.api-sports.io', 10, fn() => ['status' => 200, 'body' => '{}']
    );
    $method = (new ReflectionClass($provider))->getMethod('mapOdds');
    $method->setAccessible(true);
    $rows = $method->invoke($provider, [[
        'fixture' => ['id' => '910001'],
        'bookmakers' => [['name' => 'Bet365', 'bets' => [['name' => 'Asian Handicap', 'values' => [
            ['value' => 'Away', 'handicap' => '+2', 'odd' => '1.14'],
            ['value' => 'Away', 'handicap' => '-2', 'odd' => '7.40'],
        ]]]]],
    ]]);

    foreach ($rows as $row) {
        assert_not_equals('AWAY', $row['selection'], 'the mapper no longer produces the rows the purge deletes');
    }
    $bySelection = [];
    foreach ($rows as $row) $bySelection[$row['selection']] = (float) $row['decimalOdds'];
    assert_equals(1.14, $bySelection['AWAY_PLUS_2'] ?? null, 'the +2 line is restored with its own price');
});
