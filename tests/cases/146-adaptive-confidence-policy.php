<?php
/**
 * Adaptive confidence policy + progressive data sufficiency.
 *
 * These pin the second half of the 2026-09-11 fix — the part that turned
 * "6 real candidates, all rejected LOW_CONFIDENCE against a fixed 75%" into a
 * qualified ticket, WITHOUT touching a single reported number:
 *
 *  1. The confidence a prediction must reach depends on the DATA QUALITY
 *     behind it, resolved from configuration (requirements #1 and #8).
 *  2. The measured confidence is reported exactly as measured — 68 stays 68.
 *  3. Below the reject band nothing qualifies, however high the confidence.
 *  4. Thin evidence is confined to the safer markets, not simply waved through.
 *  5. Features are built PROGRESSIVELY: a partly answered stats lookup no
 *     longer throws away the data that DID arrive (requirement #2), but a
 *     fixture with no real numbers is still an honest INSUFFICIENT_DATA.
 *  6. Every rejection states what was required and what was missing (#13).
 */

require_once __DIR__ . '/145-odds-ticket-engine-funnel.php'; // fx145_audit() and the shared fixtures it brings

use AIWorkforce\Sports\ConfidencePolicy;
use AIWorkforce\Sports\ConfigurationService;
use AIWorkforce\Sports\FeatureEngineeringEngine;
use AIWorkforce\Sports\PredictionEngine;
use AIWorkforce\Sports\Providers\SportsProviderManager;
use AIWorkforce\Sports\ResultVerificationEngine;

// ─────────────────────────────────────────────────────────────────────────
// 1. THE LADDER ITSELF
// ─────────────────────────────────────────────────────────────────────────

test('adaptive policy: the stock configuration produces the required data-quality bands', function () {
    $policy = ConfidencePolicy::fromConfiguration(ConfigurationService::defaults());

    // The shipped quality default is 55 (operator decision 2026-09-17), so
    // the derived ladder brackets it: EXCELLENT one step above (60), GOOD one
    // step below (50), LIMITED a further step down (40). Confidence relief is
    // clamped at the 30% hard gate.
    $tiers = $policy->tiers();
    assert_true(count($tiers) >= 2, 'the derived ladder still has distinct bands');
    assert_equals('EXCELLENT', $tiers[0]['tier']);
    assert_equals(60, (int) $tiers[0]['minDataQuality']);
    assert_equals(30.0, (float) $tiers[0]['minConfidence'], 'the configured floor is the requirement at the best evidence');
    assert_equals('GOOD', $tiers[1]['tier']);
    assert_equals(50, (int) $tiers[1]['minDataQuality']);
    // Relief is applied per tier and clamped at the confidence gate (30),
    // which spec §6 makes the lowest requirement any band may demand.
    assert_equals(30.0, (float) $tiers[1]['minConfidence']);
    foreach ($tiers as $tier) {
        assert_equals(30.0, (float) $tier['minConfidence'], 'every band requires exactly the 30% confidence floor');
        assert_true((int) $tier['minDataQuality'] >= 30, 'no band admits data quality below the 30 floor');
    }
    assert_equals(40, $policy->minimumDataQuality(), 'the stock reject band sits one LIMITED step under the 55 default');

    // The 30/30 corner is still CONFIGURABLE: an operator who lowers the
    // configured value back to the floor gets the old collapsed ladder.
    $atFloor = ConfidencePolicy::fromConfiguration(['min_confidence' => 30.0, 'min_data_quality' => 30]);
    assert_equals(30, $atFloor->minimumDataQuality(), 'below the hard 30 gate nothing is predictable');
});

test('adaptive policy: the ladder is configuration, not hard-coded — moving the floors moves every tier', function () {
    $strict = ConfidencePolicy::fromConfiguration(['min_confidence' => 85.0, 'min_data_quality' => 90]);
    assert_equals(85.0, $strict->highestConfidenceRequirement(), 'a stricter operator gets a stricter top tier');
    assert_equals(80.0, $strict->requiredConfidence(88), 'and a stricter middle tier');
    // The configured min_data_quality is the ORDINARY band and the ladder
    // brackets it, so an operator who RAISES their floor still gets a
    // correspondingly strict reject floor — raising remains fully supported.
    assert_equals(75, $strict->minimumDataQuality(), 'the reject floor rises with the configured quality floor');
    assert_null($strict->requiredConfidence(70), 'quality 70 is not predictable under a 90 quality policy');
    assert_equals('LIMITED', $strict->tierFor(78)['tier'], 'the band below the configured floor is the restricted one');

    // A lenient operator may now go all the way down to the platform gate of
    // 30 — that is the point of the change. Nothing below 30 is ever admitted.
    $lenient = ConfidencePolicy::fromConfiguration(['min_confidence' => 60.0, 'min_data_quality' => 40]);
    assert_equals(60.0, $lenient->highestConfidenceRequirement());
    assert_equals(30, $lenient->minimumDataQuality(), 'a lenient policy reaches the 30 gate, never below it');
    assert_null($lenient->requiredConfidence(29), 'quality 29 is rejected however lenient the policy');
    assert_true($lenient->requiredConfidence(30) !== null, 'quality 30 is assessable');
    assert_true($lenient->requiredConfidence(74) !== null, 'quality 74 now qualifies instead of being rejected');
});

test('adaptive policy: an explicitly authored policy overrides the derived ladder, and garbage is refused', function () {
    $explicit = ConfidencePolicy::fromConfiguration([
        'min_confidence' => 75.0, 'min_data_quality' => 80,
        'confidence_policy' => json_encode(['tiers' => [
            ['tier' => 'HOUSE_HIGH', 'minDataQuality' => 90, 'minConfidence' => 82],
            ['tier' => 'HOUSE_LOW', 'minDataQuality' => 70, 'minConfidence' => 68],
        ]]),
    ]);
    assert_equals(82.0, $explicit->requiredConfidence(95), 'the authored tier wins over the derived one');
    // An authored band is honoured down to the platform gate (30). HOUSE_LOW
    // starts at 70, so 72 now resolves to it rather than being refused by a
    // stricter platform minimum sitting above the authored policy.
    assert_equals(68.0, $explicit->requiredConfidence(72), 'an authored band is honoured above the 30 gate');
    assert_null($explicit->requiredConfidence(29), 'nothing below the 30 gate is ever admitted');
    assert_equals(68.0, $explicit->requiredConfidence(76));
    assert_equals('HOUSE_LOW', $explicit->tierFor(76)['tier']);

    // Unusable input never silently becomes a policy.
    assert_null(ConfidencePolicy::normalizePolicy('not json'));
    assert_null(ConfidencePolicy::normalizePolicy([['minDataQuality' => 'high', 'minConfidence' => 70]]));
    assert_null(ConfidencePolicy::normalizePolicy([['minDataQuality' => 80, 'minConfidence' => 140]]), 'a >100% requirement is not a policy');
    assert_null(ConfidencePolicy::normalizePolicy([['minDataQuality' => 80, 'minConfidence' => 29.99]]), 'an authored tier cannot undercut the 30% confidence gate');
    assert_null(ConfidencePolicy::normalizePolicy([['minDataQuality' => 90, 'minConfidence' => 72], ['minDataQuality' => 80, 'minConfidence' => 29.99]], true), 'a mixed policy cannot hide a sub-30 tier behind a valid one');
    // …and a configuration carrying one is refused rather than ignored.
    $repo = new SportsRepositoryStub();
    $audit = fx145_audit();
    $svc = new ConfigurationService($repo, $audit);
    assert_false($svc->update(['confidence_policy' => 'not json'], 'admin')['ok'], 'an unreadable policy is rejected, never silently dropped');
    assert_false($svc->update(['confidence_policy' => [['minDataQuality' => 80, 'minConfidence' => 29.99]]], 'admin')['ok'], 'a sub-30 authored policy is rejected');
    assert_false($svc->update(['confidence_policy' => [['minDataQuality' => 90, 'minConfidence' => 72], ['minDataQuality' => 80, 'minConfidence' => 29.99]]], 'admin')['ok'], 'a mixed authored policy with a sub-30 tier is rejected');
    assert_true($svc->update(['confidence_policy' => [['minDataQuality' => 80, 'minConfidence' => 72]]], 'admin', 'house policy')['ok']);
});

// ─────────────────────────────────────────────────────────────────────────
// 2. THE VERDICT — never inflated, always explained
// ─────────────────────────────────────────────────────────────────────────

test('adaptive policy: a real 68% on good data qualifies and is still reported as 68%', function () {
    $policy = ConfidencePolicy::fromConfiguration(['min_confidence' => 70.0, 'min_data_quality' => 75]);
    // Quality 78 → GOOD tier → 65% required. The measured 68.82 (the exact
    // figure the 2026-09-11 run produced) clears it.
    $verdict = $policy->evaluate(78, 68.82, 'TOTAL_GOALS', 'OVER_1_5');
    assert_true($verdict['qualified'], 'a legitimate 68.82% clears the tier its evidence earned');
    assert_equals(68.82, (float) $verdict['confidence'], 'the reported confidence is the MEASURED value — never rounded up to the threshold');
    assert_equals(65.0, (float) $verdict['requiredConfidence'], 'and the requirement is reported beside it');
    assert_equals('GOOD', $verdict['tier']);
    assert_equals([], $verdict['reasons']);
    assert_contains('68.82', $verdict['explanation']);
});

test('adaptive policy: below the reject band nothing qualifies, however confident the model is', function () {
    // Pinned at the configurable 30/30 corner (the shipped default is 55, but
    // the hard-gate behaviour must hold wherever the operator sets the floor).
    $policy = ConfidencePolicy::fromConfiguration(['min_confidence' => 30.0, 'min_data_quality' => 30]);
    // 29 is below the 30 gate; a 99% confidence reading cannot rescue it.
    $verdict = $policy->evaluate(29, 99.0, 'TOTAL_GOALS', 'OVER_1_5');
    assert_false($verdict['qualified'], 'quality 29 is not predictable at any confidence');
    assert_equals('REJECT', $verdict['tier']);
    assert_equals(['DATA_QUALITY_BELOW_MINIMUM'], $verdict['reasons']);
    // Requirement #13: the reason names the score AND the minimum allowed.
    assert_contains('29', $verdict['explanation']);
    assert_contains('30', $verdict['explanation']);

    // …while a score at the new floor is assessable on the very same policy.
    assert_true($policy->evaluate(30, 99.0, 'TOTAL_GOALS', 'OVER_1_5')['qualified'], 'quality 30 qualifies');
    assert_null($verdict['requiredConfidence'], 'no confidence requirement is quoted for an unpredictable fixture');

    // The SHIPPED default (55) rejects thin evidence one LIMITED step below
    // it: quality 39 is under the stock reject band even at 99% confidence.
    $stock = ConfidencePolicy::fromConfiguration(ConfigurationService::defaults());
    assert_false($stock->evaluate(39, 99.0, 'TOTAL_GOALS', 'OVER_1_5')['qualified'], 'quality 39 is under the stock 55-default ladder');
});

test('adaptive policy: the LIMITED tier admits safer markets only — thin data never prices a thin line', function () {
    // An operator who raises their quality floor to 90 lifts the whole ladder
    // and makes the restricted band land at 80-84. Raising a floor is still
    // fully supported; quality below their own configured floor is refused.
    $policy = ConfidencePolicy::fromConfiguration(['min_confidence' => 30.0, 'min_data_quality' => 90]);
    assert_equals('LIMITED', $policy->tierFor(78)['tier']);
    assert_null($policy->tierFor(74), 'quality 74 is below this operator\'s own 90 floor');

    // Safe: two of three outcomes, or a line the game almost always clears.
    assert_true($policy->marketAllowed(78, 'DOUBLE_CHANCE', 'HOME_OR_DRAW'));
    assert_true($policy->marketAllowed(78, 'DRAW_NO_BET', 'HOME'));
    assert_true($policy->marketAllowed(78, 'TOTAL_GOALS', 'OVER_0_5'));
    assert_true($policy->marketAllowed(78, 'TOTAL_GOALS', 'UNDER_3_5'));
    // Not safe on limited evidence.
    assert_false($policy->marketAllowed(78, 'MATCH_RESULT', 'HOME'));
    assert_false($policy->marketAllowed(78, 'BTTS', 'YES'));
    assert_false($policy->marketAllowed(78, 'TOTAL_GOALS', 'OVER_2_5'));
    // The same markets are all fine once the evidence is there.
    assert_true($policy->marketAllowed(92, 'MATCH_RESULT', 'HOME'));
    assert_true($policy->marketAllowed(92, 'BTTS', 'YES'));

    $verdict = $policy->evaluate(78, 90.0, 'BTTS', 'YES');
    assert_false($verdict['qualified'], 'a high confidence cannot buy a restricted market');
    assert_equals(['MARKET_RESTRICTED_AT_DATA_TIER'], $verdict['reasons']);
    // …and below the hard gate the market question never even arises.
    $belowGate = $policy->evaluate(74, 99.0, 'DOUBLE_CHANCE', 'HOME_OR_DRAW');
    assert_false($belowGate['qualified']);
    assert_equals(['DATA_QUALITY_BELOW_MINIMUM'], $belowGate['reasons']);
});

test('adaptive policy: an unmeasurable confidence is never treated as a passing one', function () {
    $policy = ConfidencePolicy::fromConfiguration(ConfigurationService::defaults());
    $verdict = $policy->evaluate(96, null, 'TOTAL_GOALS', 'OVER_1_5');
    assert_false($verdict['qualified']);
    assert_equals(['CONFIDENCE_UNMEASURED'], $verdict['reasons']);
    assert_null($verdict['confidence'], 'an absent measurement is null, never a substituted number');
});

// ─────────────────────────────────────────────────────────────────────────
// 3. PROGRESSIVE FEATURES — partial data is used, absent data still rejects
// ─────────────────────────────────────────────────────────────────────────

test('progressive data: a half-answered stats lookup no longer throws away the rates that DID arrive', function () {
    $engine = new FeatureEngineeringEngine();
    // The production shape: the provider answered for the home side and the
    // away attack, but not the away defence. Under the old all-or-nothing
    // gate this whole fixture died as INSUFFICIENT_DATA.
    $out = $engine->build(['decision' => 'INTELLIGENCE_READY', 'inputs' => [
        'recentForm' => [
            'homeGoalsPerMatch' => 1.8, 'awayGoalsPerMatch' => 1.2, 'homeConcededPerMatch' => 0.9,
            'source' => 'provider-a',
        ],
        // A REAL measurement from the same standings response — not a default.
        'competitionBaseline' => ['goalsPerMatch' => 1.35, 'concededPerMatch' => 1.35, 'source' => 'league-table'],
    ]]);
    assert_true($out['ok'], 'three measured rates plus a verified baseline is predictable');
    assert_equals(0.75, (float) $out['coverage'], 'coverage honestly reports 3 of 4 rates measured');
    assert_equals(['awayConcededPerMatch' => 'league-table'], $out['substitutedFields'], 'every substitution is named');
    assert_equals(1.35, (float) $out['features']['awayDefenseConceded'], 'the covered rate is the verified baseline, not an invention');
    assert_equals(1.8, (float) $out['features']['homeAttack'], 'measured rates are used exactly as measured');
});

test('progressive data: no verified baseline means the missing rate is named, not guessed', function () {
    $out = (new FeatureEngineeringEngine())->build(['decision' => 'INTELLIGENCE_READY', 'inputs' => [
        'recentForm' => ['homeGoalsPerMatch' => 1.8, 'awayGoalsPerMatch' => 1.2, 'homeConcededPerMatch' => 0.9],
    ]]);
    assert_false($out['ok'], 'nothing is substituted when no verified baseline exists');
    assert_equals('INSUFFICIENT_DATA', $out['reason']);
    assert_equals(['recentForm.awayConcededPerMatch'], $out['missingFields'], 'the exact missing field is named');
});

test('progressive data: a fixture with almost no real numbers is still an honest rejection', function () {
    $engine = new FeatureEngineeringEngine();
    // One measured rate + a baseline could technically fill the grid, but a
    // single rate is the league average wearing a team name. The gate is
    // loosened, not removed.
    $out = $engine->build(['decision' => 'INTELLIGENCE_READY', 'inputs' => [
        'recentForm' => ['homeGoalsPerMatch' => 1.8],
        'competitionBaseline' => ['goalsPerMatch' => 1.35, 'source' => 'league-table'],
    ]]);
    assert_false($out['ok']);
    assert_equals('INSUFFICIENT_DATA', $out['reason']);
    assert_contains('1 of 4', $out['note'], 'the rejection states how much real evidence there was');

    // And no form at all remains exactly what it always was.
    $none = $engine->build(['decision' => 'INTELLIGENCE_READY', 'inputs' => ['recentForm' => []]]);
    assert_false($none['ok']);
    assert_equals(0.0, (float) $none['coverage']);
});

// ─────────────────────────────────────────────────────────────────────────
// 4. THE WIDER MARKET SET — priced AND settleable
// ─────────────────────────────────────────────────────────────────────────

test('markets: every supported selection can be both priced by the model and settled from a score', function () {
    $model = new PredictionEngine();
    $verifier = new ResultVerificationEngine();
    $features = ['ok' => true, 'version' => FeatureEngineeringEngine::VERSION, 'inputSources' => [], 'features' => [
        'expectedGoalsProxy' => 2.6, 'homeAttack' => 1.7, 'awayAttack' => 1.2,
        'homeDefenseConceded' => 0.9, 'awayDefenseConceded' => 1.3,
    ]];
    $calibration = ['approved' => true, 'intercept' => 0.0, 'slope' => 1.0, 'version' => 'identity'];
    $verified = ['verified' => true, 'terminalStatus' => 'FINISHED', 'homeScore' => 2, 'awayScore' => 1];

    foreach (PredictionEngine::SUPPORTED_MARKETS as $market => $selections) {
        foreach ($selections as $selection) {
            $prediction = $model->predict($market, $selection, $features, $calibration);
            assert_equals('PREDICTION_READY', $prediction['decision'], $market . ':' . $selection . ' must be priceable');
            $probability = (float) $prediction['calibratedProbability'];
            assert_true($probability > 0.0 && $probability < 1.0, $market . ':' . $selection . ' has a usable probability');

            // A market the engine can price but not settle would leave a leg
            // PENDING forever — the invariant that keeps the two lists in step.
            $settled = $verifier->settleSelection(['market' => $market, 'selection' => $selection], $verified);
            assert_not_equals('PENDING', $settled['status'], $market . ':' . $selection . ' must settle from a verified score');
            assert_not_equals('MARKET_SETTLEMENT_RULE_UNAVAILABLE', (string) ($settled['reason'] ?? ''), $market . ':' . $selection . ' needs a settlement rule');
        }
    }
});

test('markets: the new selections settle by the real rules, and Draw No Bet pushes on a draw', function () {
    $verifier = new ResultVerificationEngine();
    $homeWin = ['verified' => true, 'terminalStatus' => 'FINISHED', 'homeScore' => 2, 'awayScore' => 0];
    $draw = ['verified' => true, 'terminalStatus' => 'FINISHED', 'homeScore' => 1, 'awayScore' => 1];

    // Draw No Bet: won outright, refunded on the draw.
    assert_equals('WON', $verifier->settleSelection(['market' => 'DRAW_NO_BET', 'selection' => 'HOME'], $homeWin)['status']);
    assert_equals('LOST', $verifier->settleSelection(['market' => 'DRAW_NO_BET', 'selection' => 'AWAY'], $homeWin)['status']);
    $push = $verifier->settleSelection(['market' => 'DRAW_NO_BET', 'selection' => 'HOME'], $draw);
    assert_equals('VOID', $push['status'], 'the draw refunds the stake');
    assert_equals('DRAW_NO_BET_PUSH', $push['reason']);

    // BTTS NO — 2-0 means one side failed to score.
    assert_equals('WON', $verifier->settleSelection(['market' => 'BTTS', 'selection' => 'NO'], $homeWin)['status']);
    assert_equals('LOST', $verifier->settleSelection(['market' => 'BTTS', 'selection' => 'YES'], $homeWin)['status']);
    assert_equals('WON', $verifier->settleSelection(['market' => 'BTTS', 'selection' => 'YES'], $draw)['status']);

    // The new goal lines settle on the same shared rule.
    assert_equals('WON', $verifier->settleSelection(['market' => 'TOTAL_GOALS', 'selection' => 'OVER_0_5'], $homeWin)['status']);
    assert_equals('WON', $verifier->settleSelection(['market' => 'TOTAL_GOALS', 'selection' => 'UNDER_4_5'], $homeWin)['status']);
});

test('markets: companion prices complete the overround but are never candidates', function () {
    // UNDER_1_5 is quoted so Over 1.5's margin can be removed; it must not be
    // predictable or pickable in its own right.
    assert_true(PredictionEngine::isCompanionSelection('TOTAL_GOALS', 'UNDER_1_5'));
    assert_false(PredictionEngine::isSupportedMarketSelection('TOTAL_GOALS', 'UNDER_1_5'));
    // BTTS NO, by contrast, is a real market the model prices and settles.
    assert_false(PredictionEngine::isCompanionSelection('BTTS', 'NO'));
    assert_true(PredictionEngine::isSupportedMarketSelection('BTTS', 'NO'));
});

test('markets: a goal line is de-vigged against its OWN opposite side, never a different line', function () {
    // Over 2.5 must be priced against Under 2.5. Pairing it with Under 1.5
    // would compute a margin across two different markets.
    assert_equals(['OVER_2_5', 'UNDER_2_5'], \AIWorkforce\Sports\FairValueEngine::outcomesFor('TOTAL_GOALS', 'OVER_2_5'));
    assert_equals(['OVER_3_5', 'UNDER_3_5'], \AIWorkforce\Sports\FairValueEngine::outcomesFor('TOTAL_GOALS', 'UNDER_3_5'));
    assert_equals(['HOME', 'DRAW', 'AWAY'], \AIWorkforce\Sports\FairValueEngine::outcomesFor('MATCH_RESULT', 'HOME'));
    assert_equals(['HOME', 'AWAY'], \AIWorkforce\Sports\FairValueEngine::outcomesFor('DRAW_NO_BET', 'HOME'));
});

// ─────────────────────────────────────────────────────────────────────────
// 5. END TO END — the exact 2026-09-11 shape now produces a ticket
// ─────────────────────────────────────────────────────────────────────────

test('funnel end-to-end: the day that returned 0 predictions now produces a real, non-fallback ticket', function () {
    $repo = new SportsRepositoryStub();
    $audit = fx145_audit();
    fx145_approve_calibration($repo);
    $fixtures = fx145_three_fixtures();
    fx145_seed($repo, 'adaptive-e2e', $fixtures);
    $providers = new SportsProviderManager();
    $providers->register(fx145_provider('adaptive-e2e', $fixtures, []));

    $run = fx145_service($repo, $audit, $providers)->runDaily(fx145_date());
    $diag = $run['diagnostics'];

    assert_not_null($run['ticketId'], 'a well-evidenced day must yield a ticket: ' . (string) $run['message']);
    assert_equals(0, (int) ($diag['topRejectionReasons']['INSUFFICIENT_DATA'] ?? 0), 'no fixture is rejected for data it does not need');
    assert_equals(0, (int) ($diag['topRejectionReasons']['SUPPORTED_ODDS_UNAVAILABLE'] ?? 0));
    assert_equals(0, (int) ($diag['topRejectionReasons']['OUTSIDE_CONFIGURATION'] ?? 0),
        'a market the engine prices is a market the default configuration allows');

    // Requirement #14: every funnel counter the diagnosis needs, including
    // the distributions and the measured average.
    foreach ([
        'eligibleFixtures', 'fixturesWithFreshOdds', 'marketsEvaluated', 'predictionsGenerated',
        'confidenceQualifiedCandidates', 'positiveValueCandidates', 'riskQualifiedCandidates',
        'correlationQualifiedCandidates', 'finalQualifiedCandidates', 'averageConfidence',
        'confidenceDistribution', 'dataQualityDistribution', 'candidatesByDataTier', 'confidencePolicy',
    ] as $key) {
        assert_true(array_key_exists($key, $diag), 'the funnel reports ' . $key);
    }

    // The distributions must account for exactly the candidates evaluated.
    $confidenceTotal = array_sum(array_map('intval', (array) $diag['confidenceDistribution']));
    $qualityTotal = array_sum(array_map('intval', (array) $diag['dataQualityDistribution']));
    assert_equals((int) $diag['marketsEvaluated'], $confidenceTotal, 'every evaluated candidate lands in a confidence band');
    assert_equals((int) $diag['marketsEvaluated'], $qualityTotal, 'every evaluated candidate lands in a data-quality band');
    assert_true(is_numeric($diag['averageConfidence']), 'the average of the measured confidences is reported');

    // Multiple markets per fixture were evaluated and ranked (#5/#10).
    $markets = [];
    foreach ($repo->listPredictions([], 500) as $prediction) $markets[strtoupper((string) $prediction['market'])] = true;
    assert_true(count($markets) >= 3, 'several distinct markets were priced per day, got ' . implode(',', array_keys($markets)));

    // And the legs report the numbers they actually measured.
    foreach ($repo->ticketSelections((string) $run['ticketId']) as $leg) {
        assert_true(PredictionEngine::isSupportedMarketSelection((string) $leg['market'], (string) $leg['selection']));
        assert_true(is_numeric($leg['odds']) && (float) $leg['odds'] > 1.0, 'every leg carries a real quoted price');
        assert_true(is_numeric($leg['confidence']), 'every leg carries its measured confidence');
    }
});

test('funnel end-to-end: the adaptive requirement travels with every candidate decision row', function () {
    $repo = new SportsRepositoryStub();
    $audit = fx145_audit();
    fx145_approve_calibration($repo);
    $fixtures = fx145_three_fixtures();
    fx145_seed($repo, 'adaptive-trace', $fixtures);
    $providers = new SportsProviderManager();
    $providers->register(fx145_provider('adaptive-trace', $fixtures, []));

    $diag = fx145_service($repo, $audit, $providers)->runDaily(fx145_date())['diagnostics'];
    $rows = (array) ($diag['candidateDecisions'] ?? []);
    assert_true($rows !== [], 'the decision trace is populated');
    foreach ($rows as $row) {
        // Requirement #13: score, the minimum it was judged against, and the
        // tier that minimum came from — on every row, selected or not.
        assert_true(array_key_exists('minConfidence', $row), 'each row states the confidence required of it');
        assert_true(array_key_exists('minDataQuality', $row), 'each row states the data quality required of it');
        assert_true(array_key_exists('dataTier', $row), 'each row states the adaptive tier it was judged in');
        if ($row['confidence'] !== null && $row['minConfidence'] !== null) {
            $met = (float) $row['confidence'] + 1e-9 >= (float) $row['minConfidence'];
            $flagged = in_array('LOW_CONFIDENCE', (array) ($row['reasons'] ?? []), true);
            assert_true($met !== $flagged, 'LOW_CONFIDENCE is flagged exactly when the adaptive requirement was missed');
        }
    }
});

// ─────────────────────────────────────────────────────────────────────────
// 6. AUDITABLE REJECTIONS (requirement #13)
// ─────────────────────────────────────────────────────────────────────────

test('rejection audit: the quality assessment reports what was AVAILABLE, not only what was missing', function () {
    $engine = new \AIWorkforce\Sports\DataQualityEngine();
    $fixture = ['externalId' => 'f1', 'homeTeam' => 'Home', 'awayTeam' => 'Away', 'competition' => 'League', 'kickoff' => gmdate('c')];
    $assessment = $engine->assess($fixture, [
        'mandatoryFields' => \AIWorkforce\Sports\DataQualityEngine::mandatoryFieldsForMarket('MATCH_RESULT'),
        'availableFields' => ['recentForm', 'injuries'],
        'oddsAvailable' => true, 'oddsFresh' => true, 'oddsAgeSeconds' => 600, 'maxOddsAgeSeconds' => 21600,
        'providerReliability' => 0.9, 'minDataQuality' => 75,
    ]);

    // Both lists, so "Missing: H2H" can be read next to what the model DID see.
    assert_true(in_array('recentForm', $assessment['available'], true), 'the mandatory form it had is listed as available');
    assert_true(in_array('injuries', $assessment['available'], true), 'the optional enrichment it had is listed too');
    assert_equals(['recentForm'], $assessment['availableMandatory']);
    assert_true(in_array('injuries', $assessment['availableOptional'], true));
    // A field is never on both sides of the ledger.
    assert_equals([], array_intersect($assessment['available'], $assessment['missing']),
        'no field is reported as both available and missing');
    // Absent optional enrichment is still only a score effect, never a block.
    assert_true($assessment['eligibleForPrediction']);
});

test('rejection audit: every hard-rejected candidate carries Reason, Missing, Available and the minimum allowed', function () {
    $repo = new SportsRepositoryStub();
    $audit = fx145_audit();
    fx145_approve_calibration($repo);
    $fixtures = fx145_three_fixtures();
    // Price the markets so far above the model's probability that the value
    // gate rejects them: real candidates, hard-rejected, fully auditable.
    fx145_seed($repo, 'audit-test', $fixtures, 600, [
        ['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 1.01],
        ['market' => 'TOTAL_GOALS', 'selection' => 'UNDER_1_5', 'decimalOdds' => 1.01],
    ]);
    $providers = new SportsProviderManager();
    $providers->register(fx145_provider('audit-test', $fixtures, []));

    $diag = fx145_service($repo, $audit, $providers)->runDaily(fx145_date())['diagnostics'];
    $rows = (array) ($diag['rejectionAudit']['rows'] ?? []);
    assert_true($rows !== [], 'hard rejections are itemised, not only counted');

    foreach ($rows as $row) {
        foreach (['fixture', 'market', 'selection', 'reason', 'missing', 'available', 'dataQuality', 'minDataQuality'] as $key) {
            assert_true(array_key_exists($key, $row), 'the audit row carries ' . $key);
        }
        assert_true(is_string($row['reason']) && $row['reason'] !== '', 'every rejection names its reason');
        assert_true(is_array($row['missing']) && is_array($row['available']), 'both ledgers are lists');
        assert_true(is_numeric($row['dataQuality']), 'the data quality behind the rejection is stated');
        assert_true(is_numeric($row['minDataQuality']), 'and the minimum it was judged against');
        // Requirement #13 is about auditability, not blame: a fixture with
        // good data that failed on price must still show its evidence.
        assert_true($row['available'] !== [], 'the data the fixture DID have is listed');
    }

    // The itemised ledger and the aggregate counts must agree.
    $counted = array_sum(array_map('intval', (array) ($diag['topRejectionReasons'] ?? [])));
    assert_true($counted >= count($rows), 'the ledger never claims more rejections than were counted');
});

test('rejection audit: the ledger is capped but the counts never are', function () {
    $repo = new SportsRepositoryStub();
    $audit = fx145_audit();
    fx145_approve_calibration($repo);
    $service = fx145_service($repo, $audit, new SportsProviderManager());
    // The cap is a stored-diagnostics guard, declared on the funnel itself so
    // a reader knows the rows are a sample while the counts are complete.
    $diag = $service->runDaily(fx145_date())['diagnostics'];
    assert_true(isset($diag['rejectionAudit']['limit']), 'the ledger declares its cap');
    assert_true(array_key_exists('truncated', $diag['rejectionAudit']), 'and whether it was reached');
    assert_true((int) $diag['rejectionAudit']['limit'] > 0);
});
