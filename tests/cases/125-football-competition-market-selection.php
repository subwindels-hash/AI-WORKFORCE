<?php
/**
 * Football Intelligence — competition selection, premium league, and the odds
 * prediction market.
 *
 * The module was specified as a flow:
 *
 * > Select the league → select the Premium League/competition → select the Odds
 * > Prediction market → generate a maximum of 50 matches → save them → use Next
 * > Page for the next batch → never regenerate matches that have already been
 * > generated.
 *
 * The cases below make each arrow falsifiable. Three of them matter most:
 *
 *  1. **Selection narrows generation.** Picking a competition spends the
 *     50-match budget inside it — the other leagues on the date are untouched.
 *  2. **A market is a view, not a generation.** Changing the market re-reads the
 *     stored prediction and the stored score grid; it cannot write a row and
 *     cannot cost a provider call.
 *  3. **A market with no stored input reports DATA_UNAVAILABLE.** Corners and
 *     cards are not modelled and are not priced here, so they must print an
 *     absence rather than a probability.
 */
require_once TESTSPATH . 'football_support.php';

use AIWorkforce\Football\DataState;
use AIWorkforce\Football\FootballConfiguration;
use AIWorkforce\Football\MatchFeed;
use AIWorkforce\Football\PredictionMarkets;
use AIWorkforce\Football\QualityBand;

/**
 * A date holding two competitions: `$premium` matches in the Premier League
 * (external id 39) and `$other` in La Liga (140). Returns [repo, module, day].
 */
function fx_fb_two_leagues(int $premium, int $other, array $config = []): array
{
    $day = gmdate('Y-m-d', time() + 2 * 86400);
    $base = (int) strtotime($day . 'T00:30:00+00:00');
    $rows = [];
    for ($i = 0; $i < $premium + $other; $i++) {
        $isPremium = $i < $premium;
        $rows[] = fx_fb_row('fx-sel-' . $i, gmdate('c', $base + $i * 60),
            $isPremium ? 'Manchester City' : 'Brighton', $isPremium ? 'Everton' : 'Burnley',
            $isPremium ? '10' : '30', $isPremium ? '20' : '40', 'SCHEDULED', null, null, null,
            $isPremium ? [] : ['leagueId' => '140', 'competition' => 'La Liga', 'country' => 'Spain']);
    }
    [$repo, , $module] = fx_fb_harness($rows, [], $config);
    fx_fb_sync_today($module, $day);
    return [$repo, $module, $day];
}

/** @return list<string> the match_id of every match on a page */
function fx_fb_page_ids(array $page): array
{
    return array_values(array_map(static fn(array $m): string => (string) ($m['matchId'] ?? ''), $page['matches'] ?? []));
}

test('football: the competition dropdown lists the leagues the provider sent, with their match counts', function () {
    [, $module, $day] = fx_fb_two_leagues(60, 30);
    $listing = $module->competitions($day);

    assert_equals(2, (int) $listing['total'], 'two competitions are stored for the date');
    $names = array_column($listing['competitions'], 'name');
    assert_true(in_array('Premier League', $names, true), 'the Premier League is offered');
    assert_true(in_array('La Liga', $names, true), 'and La Liga');
    $counts = [];
    foreach ($listing['competitions'] as $competition) $counts[(string) $competition['name']] = (int) $competition['matches'];
    assert_equals(['Premier League' => 60, 'La Liga' => 30], $counts, 'each is listed with how many matches it has on the date');
    // A league the provider never sent cannot appear: the list is data, not a constant.
    assert_false(in_array('Seria A', $names, true), 'a league with no stored match is not offered');
});

test('football: the premium league is the configured featured competition', function () {
    [, $module, $day] = fx_fb_two_leagues(60, 30);
    $premium = $module->competitions($day)['premium'];
    assert_equals('Premier League', (string) $premium['name'], 'the default premium competition is the English Premier League');
    assert_equals('39', (string) $premium['externalId'], 'resolved to the provider competition id');
    assert_equals('CONFIGURED_PREMIUM', (string) $premium['source'], 'and it says it came from configuration');

    // Configured, not hard-coded: another operator's flagship league is honoured.
    [, $other, $day2] = fx_fb_two_leagues(60, 30, ['WINDELS_FOOTBALL_PREMIUM_COMPETITION' => 'La Liga']);
    assert_equals('La Liga', (string) $other->competitions($day2)['premium']['name'], 'the premium league is configurable');

    // And a premium league with no match on the date is never silently dropped:
    // the competition with the most matches is featured, and labelled as such.
    [, $fallback, $day3] = fx_fb_two_leagues(60, 30, ['WINDELS_FOOTBALL_PREMIUM_COMPETITION' => 'Nigerian Premier Football League']);
    $fallbackPremium = $fallback->competitions($day3)['premium'];
    assert_equals('MOST_MATCHES_ON_DATE', (string) $fallbackPremium['source'], 'an absent premium league falls back, and says so');
});

test('football: selecting a competition narrows the page and the totals to that league', function () {
    [, $module, $day] = fx_fb_two_leagues(60, 30);
    $feed = $module->feed();

    $all = $feed->page($day, 1, 50, false, []);
    assert_equals(90, (int) $all['pagination']['totalMatches'], 'without a selection the date is paged whole');

    $premium = $feed->page($day, 1, 50, false, ['competition' => '39']);
    assert_equals(60, (int) $premium['pagination']['totalMatches'], 'the premium league holds 60 matches');
    assert_equals(2, (int) $premium['pagination']['totalPages'], 'so it pages over 2 pages of 50');
    assert_equals('Premier League', (string) $premium['filters']['competition']['name'], 'and names the competition it narrowed to');
    assert_true((bool) $premium['filters']['competition']['premium'], 'marking it as the premium league');
    foreach ($premium['matches'] as $match) {
        assert_equals('Premier League', (string) $match['competition'], 'every match on the page is from that competition');
    }

    $laLiga = $feed->page($day, 1, 50, false, ['competition' => 'La Liga']);
    assert_equals(30, (int) $laLiga['pagination']['totalMatches'], 'a competition can also be selected by name');
    foreach ($laLiga['matches'] as $match) {
        assert_equals('La Liga', (string) $match['competition'], 'and the page holds only its matches');
    }
});

test('football: paging inside a competition is 50 at a time, with no overlap and no gaps', function () {
    [, $module, $day] = fx_fb_two_leagues(60, 30);
    $feed = $module->feed();

    $one = $feed->page($day, 1, 50, false, ['competition' => '39']);
    $two = $feed->page($day, 2, 50, false, ['competition' => '39']);
    assert_equals(50, count($one['matches']), 'page 1 of the premium league: 50');
    assert_equals(10, count($two['matches']), 'page 2: the remaining 10, not padded to 50');
    assert_equals([], array_intersect(fx_fb_page_ids($one), fx_fb_page_ids($two)), 'the two pages do not overlap');
    assert_equals(60, count(array_unique(array_merge(fx_fb_page_ids($one), fx_fb_page_ids($two)))),
        'and together they are exactly the competition');

    // The pager carries the selection: a page link that dropped it would page
    // through a different set of matches than the one the operator chose.
    assert_equals('39', (string) $two['request']['competition'], 'page 2 still names the competition');
});

test('football: generating inside a competition spends the 50-match budget inside it', function () {
    [$repo, $module, $day] = fx_fb_two_leagues(60, 30);
    $feed = $module->feed();

    $page = $feed->generate($day, 1, 50, ['competition' => '39']);
    assert_equals(50, (int) $page['generation']['generated'], 'generating page 1 of the premium league writes 50 predictions');
    assert_equals(10, (int) $page['generation']['remainingOnDate'], 'leaving 10 of the competition unanalyzed');
    assert_equals(50, count($repo->predictions), 'and only those 50 rows exist — La Liga was not processed');

    $second = $feed->generate($day, 2, 50, ['competition' => '39']);
    assert_equals(10, (int) $second['generation']['generated'], 'page 2 generates the remaining 10');
    assert_equals(60, count($repo->predictions), 'the competition is complete at 60 rows');
    assert_equals(0, (int) $second['generation']['remainingOnDate'], 'with nothing left in it');

    // The other competition was never touched by either request.
    $laLiga = $feed->page($day, 1, 50, false, ['competition' => '140']);
    assert_equals(0, (int) $laLiga['summary']['analyzed'], 'La Liga is still unanalyzed: only the selected competition is processed');
});

test('football: a competition that is not stored is reported, not silently widened to every league', function () {
    [, $module, $day] = fx_fb_two_leagues(60, 30);
    $page = $module->feed()->page($day, 1, 50, false, ['competition' => 'Seria A']);

    assert_equals(0, (int) $page['pagination']['totalMatches'], 'the page is narrowed to it and holds nothing');
    assert_equals('NOT_FOUND', (string) $page['filters']['competition']['state'], 'and says the competition was not found');
    $notes = implode(' ', (array) ($page['request']['notes'] ?? []));
    assert_true(str_contains($notes, 'Seria A'), 'the note names the selection that could not be honoured');
    assert_true(str_contains($notes, 'Premier League'), 'and the competitions that are available');
});

test('football: the odds prediction dropdown carries every documented market', function () {
    $markets = new PredictionMarkets(new FootballConfiguration([]));
    $keys = array_column($markets->catalog(), 'key');
    foreach (['MATCH_WINNER', 'DOUBLE_CHANCE', 'DRAW_NO_BET', 'OVER_0_5', 'OVER_1_5', 'OVER_2_5', 'OVER_3_5',
        'UNDER_1_5', 'UNDER_2_5', 'UNDER_3_5', 'BTTS', 'BTTS_AND_OVER_2_5', 'FIRST_HALF_WINNER',
        'FIRST_HALF_OVER_UNDER', 'HALF_TIME_FULL_TIME', 'CORRECT_SCORE', 'ASIAN_HANDICAP', 'CORNERS', 'CARDS'] as $key) {
        assert_true(in_array($key, $keys, true), 'the catalogue offers ' . $key);
    }
    // A market the engine does not offer is answered with the default and a note.
    $notes = [];
    $resolved = $markets->resolve('GOAL_IN_FIRST_MINUTE', $notes);
    assert_equals('MATCH_WINNER', (string) $resolved['key'], 'an unknown market falls back to the documented default');
    assert_true(count($notes) === 1 && str_contains($notes[0], 'GOAL_IN_FIRST_MINUTE'), 'and the caller is told');
    // Provider spellings are accepted rather than rejected as unknown.
    assert_equals('MATCH_WINNER', (string) $markets->resolve('1X2', $notes)['key'], '1X2 is the match-winner market');
    assert_equals('BTTS', (string) $markets->resolve('GG', $notes)['key'], 'GG is both-teams-to-score');
});

test('football: market probabilities are summed from the stored grid, not invented', function () {
    [$repo, $module, $day] = fx_fb_two_leagues(2, 0);
    $module->predictions()->predictDay($day);
    $page = $module->feed()->page($day, 1, 50, false, ['competition' => '39']);
    $predictionId = (string) ($page['matches'][0]['prediction']['predictionId'] ?? '');
    assert_true($predictionId !== '', 'the page holds a stored prediction');

    $grid = [];
    foreach ($module->repository()->listScoreProbabilities($predictionId, 200) as $gridRow) {
        $grid[] = ['home' => (int) $gridRow['home_goals'], 'away' => (int) $gridRow['away_goals'], 'probability' => (float) $gridRow['probability']];
    }
    $markets = $module->markets();
    // The row the feed evaluates: the stored prediction, not its summary. The
    // persisted grid is truncated, so a goal market is summed over the
    // distribution recomputed from the expected goals stored with the
    // prediction — and the basis says that is what happened.
    $row = $repo->findPrediction($predictionId);

    // Over 2.5 and Under 2.5 are complements of one distribution.
    $over = $markets->evaluate($row, $grid, [], $markets->market('OVER_2_5'));
    $under = $markets->evaluate($row, $grid, [], $markets->market('UNDER_2_5'));
    $overBySelection = array_column((array) $over['outcomes'], 'probability', 'selection');
    $underBySelection = array_column((array) $under['outcomes'], 'probability', 'selection');
    assert_equals(PredictionMarkets::STATE_AVAILABLE, (string) $over['state'], 'over 2.5 is answerable');
    assert_true(abs((float) $overBySelection['OVER'] + (float) $underBySelection['UNDER'] - 1.0) < 0.001,
        'over 2.5 + under 2.5 is the whole distribution');
    assert_true(abs((float) $overBySelection['UNDER'] - (float) $underBySelection['UNDER']) < 0.001,
        'and the two markets agree on the same side of the line');
    // The stored grid is truncated for display; a market must not be summed
    // over the truncated part, so the coverage it used is reported.
    assert_true((float) ($over['coverage'] ?? 0) >= 0.99, 'the share of the distribution the market could see is reported');
    assert_true(str_contains((string) $over['basis'], 'SCORE'), 'and the basis names the score model it came from');

    // Double chance covers every outcome twice over: each pair sums to the two
    // 1X2 legs it combines.
    $double = $markets->evaluate($row, $grid, [], $markets->market('DOUBLE_CHANCE'));
    $labels = array_column((array) $double['outcomes'], 'probability', 'selection');
    $home = (float) $row['probability_home']; $draw = (float) $row['probability_draw']; $away = (float) $row['probability_away'];
    assert_equals(round($home + $draw, 6), round((float) $labels['HOME_OR_DRAW'], 6), 'home or draw is home + draw');
    assert_equals(round($draw + $away, 6), round((float) $labels['AWAY_OR_DRAW'], 6), 'draw or away is draw + away');
    assert_equals(round($home + $away, 6), round((float) $labels['HOME_OR_AWAY'], 6), 'home or away is home + away');

    // Draw no bet removes the draw and renormalises: the two legs sum to 1.
    $dnb = $markets->evaluate($row, $grid, [], $markets->market('DRAW_NO_BET'));
    $legs = array_column((array) $dnb['outcomes'], 'probability');
    assert_equals(1.0, round((float) $legs[0] + (float) $legs[1], 6), 'draw no bet redistributes the draw, it does not drop it');

    // Both teams to score: a sum over the same grid, and the complement agrees.
    $btts = $markets->evaluate($row, $grid, [], $markets->market('BTTS'));
    $bttsBySelection = array_column((array) $btts['outcomes'], 'probability', 'selection');
    assert_equals(1.0, round((float) $bttsBySelection['YES'] + (float) $bttsBySelection['NO'], 6), 'btts yes + no is 1');
    assert_true((float) $bttsBySelection['YES'] > 0.0 && (float) $bttsBySelection['YES'] < 1.0, 'and it is a real probability, not 0 or 1');

    // Correct score is the top of the stored grid, not a fresh guess.
    $score = $markets->evaluate($row, $grid, [], $markets->market('CORRECT_SCORE'));
    $top = $grid;
    usort($top, static fn(array $a, array $b) => (float) $b['probability'] <=> (float) $a['probability']);
    assert_equals(round((float) $top[0]['probability'], 6), round((float) $score['probability'], 6),
        'the top correct score is the most probable scoreline in the stored grid');
});

test('football: asian handicap is settled over the grid, including the push', function () {
    [$repo, $module, $day] = fx_fb_two_leagues(2, 0);
    $module->predictions()->predictDay($day);
    $page = $module->feed()->page($day, 1, 50, false, ['competition' => '39']);
    $predictionId = (string) ($page['matches'][0]['prediction']['predictionId'] ?? '');
    $row = $repo->findPrediction($predictionId);
    $grid = [];
    foreach ($module->repository()->listScoreProbabilities($predictionId, 200) as $r) {
        $grid[] = ['home' => (int) $r['home_goals'], 'away' => (int) $r['away_goals'], 'probability' => (float) $r['probability']];
    }

    // A whole line can push: level stakes come back when the line lands exactly.
    $level = $module->markets()->evaluate($row, $grid, [], $module->markets()->market('ASIAN_HANDICAP'), 0.0);
    $home = (array) $level['outcomes'][0];
    assert_equals('Home 0', (string) $home['label'], 'the home side is quoted at level');
    assert_true($home['note'] !== null && str_contains((string) $home['note'], 'push'),
        'and the share of the stake returned on a push is stated, not hidden');

    // A quarter line splits the stake across the two bounding half lines, which
    // is how the market settles — -0.75 is half at -0.5 and half at -1.
    $quarter = $module->markets()->evaluate($row, $grid, [], $module->markets()->market('ASIAN_HANDICAP'), -0.75);
    $lower = $module->markets()->evaluate($row, $grid, [], $module->markets()->market('ASIAN_HANDICAP'), -0.5);
    $upper = $module->markets()->evaluate($row, $grid, [], $module->markets()->market('ASIAN_HANDICAP'), -1.0);
    $expected = ((float) $lower['outcomes'][0]['probability'] + (float) $upper['outcomes'][0]['probability']) / 2;
    assert_equals(round($expected, 6), round((float) $quarter['outcomes'][0]['probability'], 6),
        'a quarter line is the average of the two half lines it splits across');
});

test('football: a market with no stored input and no price reports DATA_UNAVAILABLE', function () {
    [, $module, $day] = fx_fb_two_leagues(2, 0);
    $module->predictions()->predictDay($day);
    $page = $module->feed()->page($day, 1, 50, false, ['competition' => '39']);
    $block = (array) ($page['matches'][0]['market'] ?? []);

    foreach (['CORNERS', 'CARDS', 'HALF_TIME_FULL_TIME'] as $key) {
        $market = $module->markets()->market($key);
        assert_not_null($market, $key . ' is in the catalogue');
    }
    $corners = $module->feed()->page($day, 1, 50, false, ['competition' => '39', 'market' => 'CORNERS']);
    $row = (array) ($corners['matches'][0]['market'] ?? []);
    assert_equals(DataState::UNAVAILABLE, (string) ($row['state'] ?? ''), 'corners are reported as unavailable');
    assert_null($row['probability'] ?? null, 'with no probability — not 0, not a guess');
    assert_null($row['selection'] ?? null, 'and no recommended selection');
    assert_true(str_contains((string) ($row['reason'] ?? ''), 'not modelled'), 'the reason names the absence');
    assert_equals([], (array) ($row['outcomes'] ?? ['x']), 'and no outcomes are invented');
    assert_not_null($block, 'the page still carries a market block for the analyzed market');
});

test('football: a quoted price is shown, an unquoted one is not — and the edge is the difference', function () {
    [$repo, $module, $day] = fx_fb_two_leagues(2, 0);
    $module->predictions()->predictDay($day);
    $feed = $module->feed();
    $page = $feed->page($day, 1, 50, false, ['competition' => '39', 'market' => 'OVER_2_5']);
    $matchId = (string) ($page['matches'][0]['matchId'] ?? '');
    assert_true($matchId !== '', 'the match has an identity to price');

    // With nothing quoted, the odds column is an absence.
    $row = (array) ($page['matches'][0]['market'] ?? []);
    assert_null($row['odds'] ?? null, 'no price is shown when the feed quoted none');
    assert_null($row['impliedProbability'] ?? null, 'and no implied probability is derived from a price that does not exist');
    assert_equals(DataState::UNAVAILABLE, (string) ((array) ($row['outcomes'][0] ?? []))['oddsState'], 'per selection too');

    // Quoted prices are matched by the provider's own naming, and the line has
    // to agree: an Over 3.5 price is not the price of Over 2.5.
    $repo->marketOdds = [
        ['matchId' => $matchId, 'market' => 'Over/Under', 'selection' => 'Over 2.5', 'decimalOdds' => 2.00, 'observedAt' => gmdate('c')],
        ['matchId' => $matchId, 'market' => 'Over/Under', 'selection' => 'Under 2.5', 'decimalOdds' => 1.80, 'observedAt' => gmdate('c')],
        ['matchId' => $matchId, 'market' => 'Over/Under', 'selection' => 'Over 3.5', 'decimalOdds' => 9.99, 'observedAt' => gmdate('c')],
        ['matchId' => $matchId, 'market' => 'Both Teams to Score', 'selection' => 'Yes', 'decimalOdds' => 1.50, 'observedAt' => gmdate('c')],
    ];
    $priced = $feed->page($day, 1, 50, false, ['competition' => '39', 'market' => 'OVER_2_5']);
    $market = (array) ($priced['matches'][0]['market'] ?? []);
    $over = null; $under = null;
    foreach ((array) ($market['outcomes'] ?? []) as $outcome) {
        if ((string) $outcome['selection'] === 'OVER') $over = $outcome;
        if ((string) $outcome['selection'] === 'UNDER') $under = $outcome;
    }
    assert_not_null($over, 'the over selection is present');
    assert_equals(2.0, (float) $over['odds'], 'the quoted price is shown as quoted');
    assert_equals(0.5, round((float) $over['impliedProbability'], 6), 'the implied probability is 1/odds');
    assert_equals(round((float) $over['probability'] - 0.5, 6), round((float) $over['edge'], 6),
        'and the edge is the model probability minus the implied one');
    assert_equals(1.8, (float) $under['odds'], 'the other side carries its own price');
    assert_true((float) $over['odds'] !== 9.99, 'a price for a different line is not used for this one');
    // The BTTS quote belongs to another market and is not borrowed by this one.
    $btts = $feed->page($day, 1, 50, false, ['competition' => '39', 'market' => 'BTTS']);
    $bttsMarket = (array) ($btts['matches'][0]['market'] ?? []);
    $yes = null; $no = null;
    foreach ((array) ($bttsMarket['outcomes'] ?? []) as $outcome) {
        if ((string) $outcome['selection'] === 'YES') $yes = $outcome;
        if ((string) $outcome['selection'] === 'NO') $no = $outcome;
    }
    assert_equals(1.5, (float) ($yes['odds'] ?? 0), 'the btts price appears in the btts market, on the yes side');
    assert_null($no['odds'] ?? null, 'and a side that was not quoted has no price');
    // The recommended selection is the likelier side, whether or not it is priced.
    assert_true((float) ($bttsMarket['probability'] ?? 0) >= (float) ($yes['probability'] ?? 0),
        'the recommendation is the higher-probability selection, not the shorter price');
});

test('football: changing the market never regenerates a match', function () {
    [$repo, $module, $day] = fx_fb_two_leagues(60, 30);
    $feed = $module->feed();

    $feed->generate($day, 1, 50, ['competition' => '39', 'market' => 'MATCH_WINNER']);
    $afterFirst = count($repo->predictions);
    assert_equals(50, $afterFirst, 'the first request generated 50');

    // The whole point: reading the same page in a different market is a read.
    foreach (['OVER_2_5', 'BTTS', 'DOUBLE_CHANCE', 'ASIAN_HANDICAP', 'CORRECT_SCORE', 'UNDER_3_5'] as $market) {
        $page = $feed->page($day, 1, 50, false, ['competition' => '39', 'market' => $market]);
        assert_equals(0, (int) $page['generation']['generated'], 'reading ' . $market . ' generated nothing');
        assert_equals(50, (int) $page['generation']['reused'], 'and reused the 50 stored predictions');
        assert_equals($market, (string) $page['market']['key'], 'while answering in the selected market');
    }
    assert_equals($afterFirst, count($repo->predictions), 'seven market changes wrote no new row');

    // Nor does paging back and forth between markets.
    $feed->page($day, 2, 50, false, ['competition' => '39', 'market' => 'OVER_2_5']);
    $feed->page($day, 1, 50, false, ['competition' => '39', 'market' => 'BTTS']);
    assert_equals($afterFirst, count($repo->predictions), 'paging between markets still writes nothing');
});

test('football: the board narrows to the same competition and market as the feed', function () {
    [, $module, $day] = fx_fb_two_leagues(60, 30);
    $module->predictions()->predictDay($day);

    $board = $module->board()->forDate($day, false, 1, 50, ['competition' => '39', 'market' => 'OVER_2_5']);
    assert_equals(60, (int) $board['pagination']['totalMatches'], 'the board pages the selected competition');
    assert_equals(50, count((array) $board['rows']), 'and renders one table row per match on the page');
    assert_equals('OVER_2_5', (string) $board['market']['key'], 'answering in the selected market');
    foreach ((array) $board['rows'] as $row) {
        assert_equals('Premier League', (string) $row['competition'], 'every row is from the selected competition');
        assert_not_null($row['market'] ?? null, 'and carries the market block');
        // §7: match, competition, kickoff, prediction, odds, confidence.
        foreach (['homeTeam', 'awayTeam', 'competition', 'kickoffLabel', 'prediction', 'market', 'confidence', 'risk'] as $key) {
            assert_true(array_key_exists($key, $row), 'the row carries ' . $key);
        }
        assert_equals(['level', 'basis'], array_keys((array) $row['risk']), 'risk is a level with the facts it came from');
    }
    // The dropdown data comes from the same call, so the console and the API
    // cannot offer different leagues.
    assert_equals(2, (int) $board['filters']['competitions']['total'], 'the board lists both competitions');
    assert_equals('Premier League', (string) $board['filters']['competitions']['premium']['name'], 'with the premium league marked');
});

test('football: the competition and market endpoints are routed and permission-guarded', function () {
    $routes = (string) file_get_contents(dirname(TESTSPATH) . '/application/config/routes.php');
    assert_true(str_contains($routes, "\$route['api/football/competitions']"), 'the competitions endpoint is routed');
    assert_true(str_contains($routes, "\$route['api/football/markets']"), 'and the markets endpoint');
    assert_true(str_contains($routes, "\$route['api/football/matches/generate']"), 'alongside page generation');

    $api = (string) file_get_contents(dirname(TESTSPATH) . '/application/controllers/Api_football.php');
    assert_true(str_contains($api, 'public function competitions('), 'the competitions action exists');
    assert_true(str_contains($api, 'public function markets('), 'the markets action exists');
    foreach (['competitions', 'markets'] as $action) {
        $start = strpos($api, 'public function ' . $action . '(');
        assert_true($start !== false, 'the ' . $action . ' action is declared');
        $body = substr($api, (int) $start, 700);
        assert_true(str_contains($body, "requirePermission('sports.view', false)"),
            'the ' . $action . ' endpoint is readable by sports.view');
    }

    // The console wires the three selections into one form.
    $view = (string) file_get_contents(dirname(TESTSPATH) . '/application/views/football/index.php');
    assert_contains('name="competition"', $view, 'the competition dropdown');
    assert_contains('name="premium"', $view, 'the premium league dropdown');
    assert_contains('name="market"', $view, 'the odds prediction dropdown');
    assert_contains('Generate this page', $view, 'and one page-scoped generation action');
    assert_contains("name=\"competition\" value=", $view, 'generation carries the selection, so it stays in the chosen league');
});

test('football: the hard 50-match generation ceiling survives a competition and market selection', function () {
    [$repo, $module, $day] = fx_fb_two_leagues(60, 30);
    $feed = $module->feed();

    // A selection is not a way around the ceiling: 500 is still 50, and the
    // caller is told, and only the selected competition is processed.
    $page = $feed->generate($day, 1, 500, ['competition' => '39', 'market' => 'OVER_2_5']);
    assert_equals(50, (int) $page['pagination']['limit'], 'limit=500 is served as 50');
    assert_equals(50, (int) $page['generation']['generated'], 'and writes 50 new predictions, not 500');
    assert_equals(MatchFeed::MAX_PAGE_SIZE, 50, 'the ceiling is 50');
    assert_equals(50, count($repo->predictions), 'the database agrees');
    $notes = implode(' ', (array) ($page['request']['notes'] ?? []));
    assert_true(str_contains($notes, 'exceeds the hard maximum of 50'), 'and the clamp is reported');
});
