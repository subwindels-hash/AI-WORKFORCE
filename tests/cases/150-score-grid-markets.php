<?php
/**
 * Wider market set #1 — handicap and correct score, priced from the SCORE GRID.
 *
 * The baseline model reads 1X2/totals/BTTS in closed form. Handicap and
 * correct score cannot be read that way: they are sums over the JOINT
 * distribution of (home goals, away goals). Rather than invent a second
 * model, ScoreGridPricer reuses the Football module's ScoreProbabilityModel
 * (independent Poisson + Dixon-Coles low-score correction, renormalised), so
 * a scoreline means the same thing on the football board and on a ticket leg.
 *
 * The invariant that governs which markets may exist at all (already pinned
 * by case 146) is: a market is listed only when the engine can BOTH price it
 * from features AND settle it from a verified full-time score. Anything that
 * cannot be settled would leave a ticket leg PENDING forever.
 *
 * Hence the deliberate ABSENCES pinned here:
 *   • quarter lines (-0.25/-0.75) split the stake into a half-win the
 *     settlement layer has no status for — refused, never rounded;
 *   • corners/cards/HT-FT have no stored input — not in the catalogue;
 *   • a scoreline outside the configured grid is UNPRICEABLE, never 0.
 */
use AIWorkforce\Sports\FeatureEngineeringEngine;
use AIWorkforce\Sports\PredictionEngine;
use AIWorkforce\Sports\ResultVerificationEngine;
use AIWorkforce\Sports\ScoreGridPricer;

function ci150_features(): array
{
    return ['ok' => true, 'version' => FeatureEngineeringEngine::VERSION, 'inputSources' => [], 'features' => [
        'expectedGoalsProxy' => 2.6, 'homeAttack' => 1.7, 'awayAttack' => 1.2,
        'homeDefenseConceded' => 0.9, 'awayDefenseConceded' => 1.3,
    ]];
}

function ci150_calibration(): array
{
    return ['approved' => true, 'intercept' => 0.0, 'slope' => 1.0, 'version' => 'identity'];
}

function ci150_verified(int $home, int $away): array
{
    return ['verified' => true, 'terminalStatus' => 'FINISHED', 'homeScore' => $home, 'awayScore' => $away];
}

test('grid: the score distribution is a real normalised distribution', function () {
    $pricer = new ScoreGridPricer();
    $rows = $pricer->grid(ci150_features()['features']);
    assert_true($rows !== [], 'a grid is built from the four goal rates');

    $sum = 0.0;
    foreach ($rows as $row) {
        assert_true($row['probability'] >= 0.0, 'no negative mass');
        $sum += (float) $row['probability'];
    }
    assert_close(1.0, $sum, 0.001, 'the grid sums to 1 — it is normalised, not a scatter of guesses');
});

test('grid: goal expectancies come from the same features the baseline model uses', function () {
    // lambdaHome = (homeAttack + awayDefenseConceded)/2 = (1.7+1.3)/2 = 1.5
    // lambdaAway = (awayAttack + homeDefenseConceded)/2 = (1.2+0.9)/2 = 1.05
    $lambdas = ScoreGridPricer::lambdas(ci150_features()['features']);
    assert_close(1.5, $lambdas[0], 0.0001, 'home expectancy matches the model strength term');
    assert_close(1.05, $lambdas[1], 0.0001, 'away expectancy matches the model strength term');

    assert_null(ScoreGridPricer::lambdas(['homeAttack' => 1.2]), 'a partial feature set yields no expectancies');
    assert_null(ScoreGridPricer::lambdas([
        'homeAttack' => 0.0, 'awayAttack' => 0.0, 'homeDefenseConceded' => 0.0, 'awayDefenseConceded' => 0.0,
    ]), 'a degenerate all-zero fixture has no distribution — never a default one');
});

test('handicap: selections parse from the selected side point of view', function () {
    assert_equals(['side' => 'HOME', 'line' => -1.5], ScoreGridPricer::handicapSelection('HOME_MINUS_1_5'));
    assert_equals(['side' => 'AWAY', 'line' => 0.5], ScoreGridPricer::handicapSelection('AWAY_PLUS_0_5'));
    assert_equals(['side' => 'HOME', 'line' => -1.0], ScoreGridPricer::handicapSelection('HOME_MINUS_1'));
    assert_null(ScoreGridPricer::handicapSelection('HOME'), 'a bare side is not a handicap');
    assert_null(ScoreGridPricer::handicapSelection('OVER_2_5'), 'a totals line is not a handicap');
});

test('handicap: quarter lines are refused, never rounded to a neighbour', function () {
    assert_true(ScoreGridPricer::isSettleableHandicapLine(-0.5), 'half lines settle cleanly');
    assert_true(ScoreGridPricer::isSettleableHandicapLine(-1.0), 'whole lines push on the exact margin');
    assert_false(ScoreGridPricer::isSettleableHandicapLine(-0.25), 'quarter lines need a half-win status we do not have');
    assert_false(ScoreGridPricer::isSettleableHandicapLine(-0.75));

    // And no quarter line is in the catalogue at all.
    foreach (PredictionEngine::SUPPORTED_MARKETS['ASIAN_HANDICAP'] as $selection) {
        $parsed = ScoreGridPricer::handicapSelection($selection);
        assert_not_null($parsed, $selection . ' parses');
        assert_true(ScoreGridPricer::isSettleableHandicapLine($parsed['line']),
            $selection . ' must settle cleanly to WON/LOST/VOID');
    }
});

test('handicap: the probability is conditional on the bet not pushing', function () {
    $pricer = new ScoreGridPricer();
    $features = ci150_features()['features'];

    // A whole line can push; the win probability must exclude that mass,
    // because a pushed stake is returned rather than lost.
    $wholeLine = $pricer->probability('ASIAN_HANDICAP', 'HOME_MINUS_1', $features);
    assert_true($wholeLine > 0.0 && $wholeLine < 1.0, 'a real conditional probability');

    // Giving a bigger start must never be less likely to win than giving less.
    $easier = $pricer->probability('ASIAN_HANDICAP', 'HOME_PLUS_1_5', $features);
    $harder = $pricer->probability('ASIAN_HANDICAP', 'HOME_MINUS_1_5', $features);
    assert_true($easier > $harder, 'a +1.5 start beats a -1.5 giveaway on the same fixture');

    // Home and away at mirrored half lines are complements: exactly one wins.
    $homeMinus = $pricer->probability('ASIAN_HANDICAP', 'HOME_MINUS_0_5', $features);
    $awayPlus = $pricer->probability('ASIAN_HANDICAP', 'AWAY_PLUS_0_5', $features);
    assert_close(1.0, $homeMinus + $awayPlus, 0.005, 'mirrored half lines are exact complements');
});

test('correct score: probabilities are read from the same grid and are internally consistent', function () {
    $pricer = new ScoreGridPricer();
    $features = ci150_features()['features'];

    $oneNil = $pricer->probability('CORRECT_SCORE', 'SCORE_1_0', $features);
    $nilOne = $pricer->probability('CORRECT_SCORE', 'SCORE_0_1', $features);
    assert_true($oneNil > 0.0 && $oneNil < 1.0);
    // Home is the stronger side here, so 1-0 must outrank 0-1.
    assert_true($oneNil > $nilOne, 'the stronger side has the likelier winning scoreline');

    // The catalogue scorelines never sum past 1 — they are disjoint cells of
    // one distribution, not independent guesses.
    $total = 0.0;
    foreach (PredictionEngine::SUPPORTED_MARKETS['CORRECT_SCORE'] as $selection) {
        $p = $pricer->probability('CORRECT_SCORE', $selection, $features);
        assert_not_null($p, $selection . ' is priceable');
        $total += $p;
    }
    assert_true($total <= 1.0, 'disjoint scorelines cannot exceed total probability 1 (got ' . round($total, 4) . ')');
});

test('a scoreline outside the grid is UNPRICEABLE, never silently zero', function () {
    $pricer = new ScoreGridPricer();
    $features = ci150_features()['features'];
    assert_null($pricer->probability('CORRECT_SCORE', 'SCORE_9_9', $features),
        'beyond the configured grid the absence is stated, not floored to 0');
    assert_null($pricer->probability('ASIAN_HANDICAP', 'HOME_MINUS_0_25', $features),
        'an unsettleable line is never priced');
    assert_null($pricer->probability('CORNERS', 'OVER_9_5', $features), 'unmodelled markets are not priced');
});

test('the model rejects an unpriceable grid market instead of emitting a token probability', function () {
    $model = new PredictionEngine();
    // A quarter line is not in the catalogue at all → UNSUPPORTED_MARKET.
    $quarter = $model->predict('ASIAN_HANDICAP', 'HOME_MINUS_0_25', ci150_features(), ci150_calibration());
    assert_equals('NO_PREDICTION', $quarter['decision']);
    assert_equals('UNSUPPORTED_MARKET', $quarter['reason']);

    // Degenerate expectancies: catalogued, but no grid can be built.
    $flat = ['ok' => true, 'version' => FeatureEngineeringEngine::VERSION, 'inputSources' => [], 'features' => [
        'expectedGoalsProxy' => 0.0, 'homeAttack' => 0.0, 'awayAttack' => 0.0,
        'homeDefenseConceded' => 0.0, 'awayDefenseConceded' => 0.0,
    ]];
    $dead = $model->predict('ASIAN_HANDICAP', 'HOME_MINUS_1', $flat, ci150_calibration());
    assert_equals('NO_PREDICTION', $dead['decision'], 'no grid means no price');
    assert_equals('UNPRICEABLE_MARKET', $dead['reason'], 'the reason is named, never a 0.01 placeholder');
});

test('grid markets require the four goal rates and say which one is missing', function () {
    $model = new PredictionEngine();
    $partial = ['ok' => true, 'version' => FeatureEngineeringEngine::VERSION, 'inputSources' => [], 'features' => [
        'expectedGoalsProxy' => 2.6, 'homeAttack' => 1.7, 'awayAttack' => 1.2,
    ]];
    $out = $model->predict('CORRECT_SCORE', 'SCORE_1_1', $partial, ci150_calibration());
    assert_equals('NO_PREDICTION', $out['decision']);
    assert_equals('INSUFFICIENT_DATA', $out['reason']);
    assert_true(in_array('homeDefenseConceded', $out['missingFields'], true), 'the concrete missing input is named');
});

// ═══════════════════════════════════════════════════════════════════════
// Settlement — the other half of the price-and-settle contract
// ═══════════════════════════════════════════════════════════════════════

test('handicap settles by the real rule, and a whole line pushes on the exact margin', function () {
    $verifier = new ResultVerificationEngine();

    // Home wins 2-0. Home -1.5 wins (margin 2 > 1.5); Home -2 pushes.
    assert_equals('WON', $verifier->settleSelection(['market' => 'ASIAN_HANDICAP', 'selection' => 'HOME_MINUS_1_5'], ci150_verified(2, 0))['status']);
    $push = $verifier->settleSelection(['market' => 'ASIAN_HANDICAP', 'selection' => 'HOME_MINUS_2'], ci150_verified(2, 0));
    assert_equals('VOID', $push['status'], 'the stake is returned on an exact-margin push');
    assert_equals('HANDICAP_PUSH', $push['reason'], 'the push is named, never scored as a loss');

    // Home -2 on a 3-0 win clears the line.
    assert_equals('WON', $verifier->settleSelection(['market' => 'ASIAN_HANDICAP', 'selection' => 'HOME_MINUS_2'], ci150_verified(3, 0))['status']);
    // Away +1.5 survives a one-goal defeat.
    assert_equals('WON', $verifier->settleSelection(['market' => 'ASIAN_HANDICAP', 'selection' => 'AWAY_PLUS_1_5'], ci150_verified(1, 0))['status']);
    assert_equals('LOST', $verifier->settleSelection(['market' => 'ASIAN_HANDICAP', 'selection' => 'AWAY_PLUS_1_5'], ci150_verified(3, 0))['status']);
});

test('correct score settles only on the exact scoreline', function () {
    $verifier = new ResultVerificationEngine();
    assert_equals('WON', $verifier->settleSelection(['market' => 'CORRECT_SCORE', 'selection' => 'SCORE_2_1'], ci150_verified(2, 1))['status']);
    assert_equals('LOST', $verifier->settleSelection(['market' => 'CORRECT_SCORE', 'selection' => 'SCORE_2_1'], ci150_verified(1, 2))['status'], 'the reverse scoreline is a different bet');
    assert_equals('LOST', $verifier->settleSelection(['market' => 'CORRECT_SCORE', 'selection' => 'SCORE_2_1'], ci150_verified(3, 1))['status']);
});

test('a void/cancelled fixture voids the new markets too', function () {
    $verifier = new ResultVerificationEngine();
    $void = ['verified' => true, 'terminalStatus' => 'VOID'];
    foreach (['ASIAN_HANDICAP' => 'HOME_MINUS_1', 'CORRECT_SCORE' => 'SCORE_1_1'] as $market => $selection) {
        assert_equals('VOID', $verifier->settleSelection(['market' => $market, 'selection' => $selection], $void)['status'],
            $market . ' follows the fixture into VOID');
    }
});

test('every catalogued market can be BOTH priced and settled — including the new ones', function () {
    $model = new PredictionEngine();
    $verifier = new ResultVerificationEngine();
    $verified = ci150_verified(2, 1);

    $covered = [];
    foreach (PredictionEngine::SUPPORTED_MARKETS as $market => $selections) {
        foreach ($selections as $selection) {
            $prediction = $model->predict($market, $selection, ci150_features(), ci150_calibration());
            assert_equals('PREDICTION_READY', $prediction['decision'], $market . ':' . $selection . ' must be priceable');

            $settled = $verifier->settleSelection(['market' => $market, 'selection' => $selection], $verified);
            assert_not_equals('PENDING', $settled['status'], $market . ':' . $selection . ' must settle from a verified score');
            assert_not_equals('MARKET_SETTLEMENT_RULE_UNAVAILABLE', (string) ($settled['reason'] ?? ''),
                $market . ':' . $selection . ' needs a settlement rule');
            $covered[$market] = true;
        }
    }
    assert_true(isset($covered['ASIAN_HANDICAP']), 'handicap is in the catalogue');
    assert_true(isset($covered['CORRECT_SCORE']), 'correct score is in the catalogue');
});

test('unmodelled markets stay out of the ticket catalogue', function () {
    // Corners, cards and HT/FT have no stored input in this engine. They are
    // absent rather than present-and-guessed.
    foreach (['CORNERS', 'CARDS', 'HALF_TIME_FULL_TIME', 'PLAYER_SHOTS'] as $market) {
        assert_false(isset(PredictionEngine::SUPPORTED_MARKETS[$market]),
            $market . ' has no stored input and must not be priceable');
        assert_false(PredictionEngine::isSupportedMarketSelection($market, 'YES'));
    }
});

test('fair value: a handicap is de-vigged against its MIRROR line, never across lines', function () {
    // Home -1.5 pairs with Away +1.5; pairing across different lines would
    // compute an overround from two separate markets.
    assert_equals(['HOME_MINUS_1_5', 'AWAY_PLUS_1_5'],
        \AIWorkforce\Sports\FairValueEngine::outcomesFor('ASIAN_HANDICAP', 'HOME_MINUS_1_5'));
    assert_equals(['AWAY_PLUS_0_5', 'HOME_MINUS_0_5'],
        \AIWorkforce\Sports\FairValueEngine::outcomesFor('ASIAN_HANDICAP', 'AWAY_PLUS_0_5'));
    assert_equals(['HOME_MINUS_1', 'AWAY_PLUS_1'],
        \AIWorkforce\Sports\FairValueEngine::outcomesFor('ASIAN_HANDICAP', 'HOME_MINUS_1'), 'whole lines mirror too');

    $engine = new \AIWorkforce\Sports\FairValueEngine();
    $at = gmdate('c');
    $complete = $engine->assessMarket('ASIAN_HANDICAP', [
        'HOME_MINUS_1_5' => ['odds' => 2.10, 'observedAt' => $at],
        'AWAY_PLUS_1_5' => ['odds' => 1.75, 'observedAt' => $at],
    ], 'HOME_MINUS_1_5', 0.5);
    // 1/2.10 + 1/1.75 = 47.62% + 57.14% = 104.76% → 4.76pp of margin.
    assert_close(4.76, (float) $complete['marginPoints'], 0.01, 'the real overround is measured and removed');

    // One side only: no complete market, so no de-vigged price is invented.
    $partial = $engine->assessMarket('ASIAN_HANDICAP', [
        'HOME_MINUS_1_5' => ['odds' => 2.10, 'observedAt' => $at],
    ], 'HOME_MINUS_1_5', 0.5);
    assert_null($partial['marginPoints'], 'a one-sided quote gets no margin figure — the gap is stated');
});

test('fair value: correct score is never de-vigged from a partial scoreline sheet', function () {
    // The complete outcome set of correct score is the whole scoreline space,
    // which no book prices exhaustively. Claiming a de-vigged fair price from
    // a handful of quoted scorelines would be arithmetic on an incomplete
    // market, so the market stays PARTIAL and states the gap.
    assert_equals([], \AIWorkforce\Sports\FairValueEngine::outcomesFor('CORRECT_SCORE', 'SCORE_1_1'));

    $engine = new \AIWorkforce\Sports\FairValueEngine();
    $at = gmdate('c');
    $assessment = $engine->assessMarket('CORRECT_SCORE', [
        'SCORE_1_1' => ['odds' => 6.5, 'observedAt' => $at],
        'SCORE_1_0' => ['odds' => 7.0, 'observedAt' => $at],
    ], 'SCORE_1_1', 0.16);
    assert_null($assessment['marginPoints'], 'no margin is claimed from an incomplete scoreline sheet');
});
