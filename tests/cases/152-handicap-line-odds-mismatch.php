<?php
/**
 * THE PHANTOM 549% EDGE (2026-09-15, Sporting CP U23 vs Academico Viseu U23).
 *
 * The ticket displayed:
 *
 *   ASIAN_HANDICAP / AWAY_PLUS_2   odds 7.40   model 87.7%   fair 1.14
 *   → "549.29% model edge"
 *
 * Those numbers cannot describe one bet. "Away +2" loses only if the away side
 * loses by 3+, so a model reading of 87.7% and fair odds of ~1.14 are mutually
 * consistent — and a bookmaker would never price that at 7.40. 7.40 was the
 * price of a DIFFERENT line on the same fixture (Away -2).
 *
 * CAUSE: api-football reports the handicap line in its own field, beside the
 * label — {"value":"Away","handicap":"+2"} — and the PRE-MATCH mapper passed
 * only `value` to the selection normalizer. "Away +2" and "Away -2" therefore
 * both normalised to a bare AWAY: two different bets sharing one
 * market:selection key. Ingestion keeps the newest row per key, so one line's
 * price silently replaced the other's, and the model then priced AWAY_PLUS_2
 * against whichever price survived. The LIVE mapper already folded the line in
 * correctly; only the pre-match path was wrong, which is why this reached a
 * real generated ticket.
 *
 * These cases pin the price↔selection contract at the provider boundary: a
 * quoted price may only ever be attached to the exact bet it was quoted for.
 */

use AIWorkforce\Sports\Providers\ApiFootballProvider;

function ci152_provider(): ApiFootballProvider
{
    return new ApiFootballProvider('key', 'https://v3.football.api-sports.io', 10,
        fn() => ['status' => 200, 'body' => '{}']);
}

/** @return array<int,array> the mapper's canonical rows */
function ci152_map(array $values, string $betName = 'Asian Handicap'): array
{
    $provider = ci152_provider();
    $method = (new ReflectionClass($provider))->getMethod('mapOdds');
    $method->setAccessible(true);
    return $method->invoke($provider, [[
        'fixture' => ['id' => '999'],
        'bookmakers' => [['name' => 'Bet365', 'bets' => [['name' => $betName, 'values' => $values]]]],
    ]]);
}

test('REPRO: two handicap lines must never collapse onto one selection key', function () {
    $rows = ci152_map([
        ['value' => 'Away', 'handicap' => '+2', 'odd' => '1.14'],
        ['value' => 'Away', 'handicap' => '-2', 'odd' => '7.40'],
    ]);

    $keys = array_map(fn(array $r): string => $r['market'] . ':' . $r['selection'], $rows);
    assert_equals(2, count(array_unique($keys)), 'the two lines keep two distinct keys (this is the bug: they used to collide)');

    // And each price stays attached to the line it was quoted for.
    $bySelection = [];
    foreach ($rows as $r) $bySelection[$r['selection']] = (float) $r['decimalOdds'];
    assert_equals(1.14, $bySelection['AWAY_PLUS_2'] ?? null, 'the +2 line keeps its own 1.14 price');
    assert_equals(7.40, $bySelection['AWAY_MINUS_2'] ?? null, 'the -2 line keeps its own 7.40 price');
    // The exact corruption seen on the dashboard.
    assert_not_equals(7.40, $bySelection['AWAY_PLUS_2'] ?? null, 'AWAY_PLUS_2 never carries the -2 line price');
});

test('every handicap line is emitted line-qualified, never as a bare side', function () {
    $rows = ci152_map([
        ['value' => 'Home', 'handicap' => '-1', 'odd' => '1.90'],
        ['value' => 'Home', 'handicap' => '+1', 'odd' => '1.30'],
        ['value' => 'Away', 'handicap' => '-1.5', 'odd' => '4.20'],
        ['value' => 'Away', 'handicap' => '+0.5', 'odd' => '1.55'],
    ]);
    foreach ($rows as $r) {
        assert_not_equals('HOME', $r['selection'], 'a handicap leg is never a lineless HOME');
        assert_not_equals('AWAY', $r['selection'], 'a handicap leg is never a lineless AWAY');
        assert_true(
            (bool) preg_match('/^(HOME|AWAY)_(PLUS|MINUS)_/', (string) $r['selection']),
            'selection carries its side AND line: ' . $r['selection']
        );
    }
    assert_equals(4, count(array_unique(array_map(fn($r) => $r['selection'], $rows))), 'four lines, four selections');
});

test('a line already present in the label is never duplicated', function () {
    $rows = ci152_map([
        // Some feeds put the line in the label AND the field; folding twice
        // would produce nonsense like "Away +2 +2".
        ['value' => 'Away +2', 'handicap' => '+2', 'odd' => '1.14'],
    ]);
    assert_equals(1, count($rows));
    assert_equals('AWAY_PLUS_2', $rows[0]['selection'], 'the label line is used as-is');
});

test('markets without a line are untouched by the fix', function () {
    $rows = ci152_map([['value' => 'Home', 'odd' => '2.10']], 'Match Winner');
    assert_equals('MATCH_RESULT', $rows[0]['market']);
    assert_equals('HOME', $rows[0]['selection'], 'a 1X2 selection stays a bare side');
    assert_equals(2.10, (float) $rows[0]['decimalOdds']);

    // A null/empty handicap must not corrupt the label either.
    $rows = ci152_map([['value' => 'Home', 'handicap' => null, 'odd' => '2.10']], 'Match Winner');
    assert_equals('HOME', $rows[0]['selection'], 'a null line leaves the selection alone');
});

test('the raw provider selection is preserved for audit', function () {
    $rows = ci152_map([['value' => 'Away', 'handicap' => '+2', 'odd' => '1.14']]);
    assert_equals('Away', $rows[0]['providerSelection'] ?? null, 'the bookmaker-native label is retained');
    assert_equals('AWAY_PLUS_2', $rows[0]['selection'], 'alongside the canonical one');
});

test('the pre-match and live mappers agree on the same quote', function () {
    $preMatch = ci152_map([['value' => 'Away', 'handicap' => '+2', 'odd' => '1.14']]);

    // The live endpoint has a different wire shape but must canonicalise the
    // identical bet to the identical key — they share one helper now.
    $provider = ci152_provider();
    $rc = new ReflectionClass($provider);
    $m = $rc->getMethod('normalizeSelection');
    $m->setAccessible(true);
    $h = $rc->getMethod('withHandicapLine');
    $h->setAccessible(true);
    $live = $m->invoke(null, 'ASIAN_HANDICAP', $h->invoke(null, 'Away', '+2'));

    assert_equals($preMatch[0]['selection'], $live, 'pre-match and live cannot drift apart');
});
