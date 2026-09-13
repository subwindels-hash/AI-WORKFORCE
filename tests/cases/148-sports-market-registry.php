<?php
/**
 * Canonical sports market registry — provider naming must never reach the
 * prediction engine or the frontend.
 *
 * The market-coverage update requires one internal vocabulary that every
 * provider is mapped into (requirement #9), an extensible catalogue covering
 * the listed market types (#2), and an absolute refusal to invent a market or
 * a price that no provider supplied (#8).
 */

use AIWorkforce\Sports\SportsDataNormalizer;
use AIWorkforce\Sports\SportsMarketRegistry as Registry;

test('market registry: every provider spelling of a market resolves to one canonical key', function () {
    // The same market, as four different feeds actually name it.
    foreach (['Match Winner', 'Full Time Result', '1X2', 'Match Result', 'MATCH_WINNER'] as $spelling) {
        $resolved = Registry::normalize($spelling);
        assert_equals('MATCH_RESULT', $resolved['market'], $spelling . ' resolves to the canonical result market');
        assert_equals(true, $resolved['recognized'], $spelling . ' is recognised');
    }
    foreach (['Over/Under', 'Goals Over/Under', 'Total Goals', 'O/U'] as $spelling) {
        assert_equals('TOTAL_GOALS', Registry::canonical($spelling), $spelling . ' is the totals market');
    }
    foreach (['Both Teams Score', 'BTTS', 'GG', 'Goal Goal'] as $spelling) {
        assert_equals('BTTS', Registry::canonical($spelling), $spelling . ' is BTTS');
    }
    // The football board's own catalogue spelling maps into the same
    // vocabulary, so the two engines can no longer disagree about one market.
    assert_equals('MATCH_RESULT', Registry::canonical('MATCH_WINNER'), 'the board and ticket vocabularies interoperate');
    assert_equals('TEAM_TOTAL_GOALS', Registry::canonical('HOME_TEAM_TOTAL_GOALS'), 'team totals are one canonical family');
});

test('market registry: specific market families win over the broad words inside them', function () {
    // Each of these contains a word that a broader pattern would otherwise
    // claim ("shots", "goals", "result", "half"). Order must resolve them.
    $cases = [
        'Player Shots on Target' => 'PLAYER_SHOTS_ON_TARGET',
        'Player Shots' => 'PLAYER_SHOTS',
        'Anytime Goalscorer' => 'PLAYER_GOALSCORER',
        'First Goalscorer' => 'PLAYER_GOALSCORER',
        'Goalkeeper Saves' => 'PLAYER_SAVES',
        'Player Assists' => 'PLAYER_ASSISTS',
        'Player Tackles' => 'PLAYER_TACKLES',
        'Player Fouls' => 'PLAYER_FOULS',
        'Player Offsides' => 'PLAYER_OFFSIDES',
        'Half Time / Full Time' => 'HALF_TIME_FULL_TIME',
        'Asian Handicap' => 'ASIAN_HANDICAP',
        'Draw No Bet' => 'DRAW_NO_BET',
        'Double Chance' => 'DOUBLE_CHANCE',
        'Correct Score' => 'CORRECT_SCORE',
        'Corners' => 'CORNERS',
        'Cards' => 'CARDS',
        'Offsides' => 'OFFSIDES',
    ];
    foreach ($cases as $raw => $expected) {
        assert_equals($expected, Registry::canonical($raw), $raw . ' resolves correctly');
    }
});

test('market registry: an unknown market is preserved and flagged, never guessed', function () {
    // Requirement #8: do not assume a market exists, and never bend an
    // unrecognised name into a near neighbour — that would silently attach one
    // market's price to another market's probability.
    $resolved = Registry::normalize('Some Exotic Proprietary Market');
    assert_equals(false, $resolved['recognized'], 'an unknown market is reported as unknown');
    assert_equals('SOME_EXOTIC_PROPRIETARY_MARKET', $resolved['market'], 'but its name is preserved, not discarded');
    assert_equals(null, Registry::describe('Some Exotic Proprietary Market'), 'and it has no fabricated definition');
    assert_equals(false, Registry::isModelled('Some Exotic Proprietary Market'), 'nothing unknown is ever treated as modelled');

    $empty = Registry::normalize('');
    assert_equals(false, $empty['recognized'], 'an empty market name is not a market');
});

test('market registry: the catalogue covers the required market types and describes each honestly', function () {
    $required = [
        'MATCH_RESULT', 'TOTAL_GOALS', 'ASIAN_HANDICAP', 'DOUBLE_CHANCE', 'BTTS', 'CORRECT_SCORE',
        'HALF_TIME_FULL_TIME', 'FIRST_HALF_WINNER', 'TEAM_TOTAL_GOALS', 'PLAYER_GOALSCORER',
        'PLAYER_GOALS', 'PLAYER_SHOTS', 'PLAYER_SHOTS_ON_TARGET', 'PLAYER_ASSISTS', 'PLAYER_TACKLES',
        'PLAYER_FOULS', 'PLAYER_CARDS', 'CARDS', 'CORNERS', 'OFFSIDES', 'PLAYER_SAVES', 'SCORE_BOTH_HALVES',
    ];
    $keys = Registry::keys();
    foreach ($required as $market) {
        assert_true(in_array($market, $keys, true), $market . ' is in the canonical catalogue');
        $definition = Registry::describe($market);
        assert_true(is_array($definition), $market . ' has a definition');
        assert_true(in_array($definition['scope'], ['MATCH', 'TEAM', 'PLAYER'], true), $market . ' declares what it attaches to');
        assert_true(in_array($definition['support'], ['MODELLED', 'PROVIDER_ONLY'], true), $market . ' states whether it is modelled');
    }

    // The platform must be honest about what it can and cannot forecast:
    // team-level goal markets are modelled, player markets are carried from
    // provider data only and are never presented as platform predictions.
    assert_equals(true, Registry::isModelled('MATCH_RESULT'), 'the result market is modelled');
    assert_equals(true, Registry::isModelled('TOTAL_GOALS'), 'totals are modelled');
    assert_equals(false, Registry::isModelled('PLAYER_SAVES'), 'goalkeeper saves are provider data, not a platform forecast');
    assert_equals(false, Registry::isModelled('PLAYER_SHOTS'), 'player shots are provider data, not a platform forecast');
    assert_equals('PLAYER', Registry::scopeOf('PLAYER_GOALSCORER'), 'player markets resolve for a named player');
    assert_equals('TEAM', Registry::scopeOf('TEAM_TOTAL_GOALS'), 'team totals resolve for a named team');
});

test('market registry: selections and their lines are canonicalised together', function () {
    $over = Registry::normalizeSelection('TOTAL_GOALS', 'Over 2.5');
    assert_equals('OVER', $over['selection'], 'the side is canonical');
    assert_equals(2.5, $over['line'], 'and the line travels with it instead of being baked into the name');

    // A comma decimal is the same line; providers disagree on the separator.
    assert_equals(1.5, Registry::normalizeSelection('TOTAL_GOALS', 'Under 1,5')['line'], 'comma decimals are the same line');
    assert_equals('UNDER', Registry::normalizeSelection('TOTAL_GOALS', 'Under 1,5')['selection']);

    assert_equals('HOME', Registry::normalizeSelection('MATCH_RESULT', 'Home')['selection']);
    assert_equals('DRAW', Registry::normalizeSelection('MATCH_RESULT', 'X')['selection']);
    assert_equals('AWAY', Registry::normalizeSelection('MATCH_RESULT', '2')['selection']);
    assert_equals('YES', Registry::normalizeSelection('BTTS', 'Yes')['selection']);
    assert_equals('HOME_OR_DRAW', Registry::normalizeSelection('DOUBLE_CHANCE', '1X')['selection']);
    assert_equals('ANYTIME', Registry::normalizeSelection('PLAYER_GOALSCORER', 'Anytime')['selection']);
    assert_equals('SCORE_2_1', Registry::normalizeSelection('CORRECT_SCORE', '2:1')['selection']);

    // A lined player market carries its line the same way a match total does.
    $shots = Registry::normalizeSelection('PLAYER_SHOTS', 'Over 1.5');
    assert_equals('OVER', $shots['selection']);
    assert_equals(1.5, $shots['line']);

    // An unlined market must not invent a line out of a number in its name.
    assert_equals(null, Registry::normalizeSelection('MATCH_RESULT', 'Home')['line'], 'unlined markets carry no line');
});

test('market registry: ingested odds carry the canonical market plus full provider provenance', function () {
    // Requirement #12: a stored quote must remain traceable to the provider,
    // the market as the provider named it, and the canonical market used for
    // analysis — all three, so an administrator can audit the mapping.
    $row = SportsDataNormalizer::odds([
        'market' => 'Match Winner',
        'selection' => 'Home',
        'decimalOdds' => 1.85,
        'observedAt' => gmdate('c'),
        'bookmaker' => 'Demo Book',
        'fixtureId' => 'fx-100',
    ], 'apifootball');

    assert_equals('apifootball', $row['provider'], 'the provider is recorded');
    assert_equals('Match Winner', $row['providerMarket'], "the provider's own spelling is preserved");
    assert_equals('MATCH_RESULT', $row['canonicalMarket'], 'alongside the canonical market the engine reads');
    assert_equals(true, $row['marketRecognized'], 'and the mapping is marked as understood');
    assert_equals('HOME', $row['canonicalSelection'], 'the selection is canonical too');
    assert_equals('Home', $row['providerSelection'], "with the provider's wording kept");
    assert_equals(1.85, $row['decimalOdds'], 'the price is unchanged by normalisation');
    assert_equals('MATCH', $row['marketScope'] ?? null, 'the scope is recorded');

    // A lined market stores the line as a number rather than hiding it in a string.
    $total = SportsDataNormalizer::odds([
        'market' => 'Goals Over/Under', 'selection' => 'Over 2.5', 'decimalOdds' => 1.9, 'observedAt' => gmdate('c'),
    ], 'sportmonks');
    assert_equals('TOTAL_GOALS', $total['canonicalMarket']);
    assert_equals('OVER', $total['canonicalSelection']);
    assert_equals(2.5, $total['line'] ?? null, 'the goal line is stored as a number');

    // An unrecognised provider market is still STORED with its price and
    // provenance — it is simply never claimed to be understood.
    $exotic = SportsDataNormalizer::odds([
        'market' => 'Proprietary Special', 'selection' => 'Whatever', 'decimalOdds' => 3.0, 'observedAt' => gmdate('c'),
    ], 'apifootball');
    assert_equals(false, $exotic['marketRecognized'], 'an unknown market is flagged, not dropped and not guessed');
    assert_equals('Proprietary Special', $exotic['providerMarket'], 'and keeps its provider name for auditing');
    assert_equals(3.0, $exotic['decimalOdds'], 'its real price is still preserved');
});

test('market registry: a player market only names an entity the provider actually sent', function () {
    // Requirement #8: never fabricate a player or a statistic. The entity
    // fields appear only when the feed supplied them.
    $withPlayer = SportsDataNormalizer::odds([
        'market' => 'Anytime Goalscorer', 'selection' => 'Anytime', 'decimalOdds' => 2.4,
        'observedAt' => gmdate('c'), 'playerId' => 'p-9', 'playerName' => 'A. Striker',
    ], 'apifootball');
    assert_equals('PLAYER_GOALSCORER', $withPlayer['canonicalMarket']);
    assert_equals('PLAYER', $withPlayer['marketScope'] ?? null, 'it is recorded as a player-scoped market');
    assert_equals('p-9', $withPlayer['playerId'] ?? null, 'the provider-supplied player id is kept');
    assert_equals('A. Striker', $withPlayer['playerName'] ?? null, 'and the provider-supplied name');

    $withoutPlayer = SportsDataNormalizer::odds([
        'market' => 'Anytime Goalscorer', 'selection' => 'Anytime', 'decimalOdds' => 2.4, 'observedAt' => gmdate('c'),
    ], 'apifootball');
    assert_equals(null, $withoutPlayer['playerId'] ?? null, 'no player is invented when the feed sent none');
    assert_equals(null, $withoutPlayer['playerName'] ?? null, 'and no player name is invented');
});

test('market registry: normalisation never alters a price or accepts an impossible one', function () {
    // The registry classifies markets; it must never become a route around the
    // odds plausibility guard.
    $threw = false;
    try {
        SportsDataNormalizer::odds(['market' => 'Match Winner', 'selection' => 'Home', 'decimalOdds' => 1.0, 'observedAt' => gmdate('c')], 'apifootball');
    } catch (\InvalidArgumentException $e) {
        $threw = true;
    }
    assert_equals(true, $threw, 'a price of 1.00 or less is still rejected at ingestion');

    $absurd = false;
    try {
        SportsDataNormalizer::odds(['market' => 'Match Winner', 'selection' => 'Home', 'decimalOdds' => 99999, 'observedAt' => gmdate('c')], 'apifootball');
    } catch (\InvalidArgumentException $e) {
        $absurd = true;
    }
    assert_equals(true, $absurd, 'an implausible price is still rejected for the canonical market');
});
