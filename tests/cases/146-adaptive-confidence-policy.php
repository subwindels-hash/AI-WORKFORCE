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

    // Requirement #8, verbatim: >=85 normal/high, 75-84 moderate,
    // 65-74 lower + safer markets only, <65 rejected.
    $tiers = $policy->tiers();
    assert_equals(3, count($tiers), 'three predictable bands plus the reject floor');
    assert_equals('EXCELLENT', $tiers[0]['tier']);
    assert_equals(85, (int) $tiers[0]['minDataQuality']);
    assert_equals(75.0, (float) $tiers[0]['minConfidence'], 'the configured floor is the requirement at the best evidence');
    assert_equals('GOOD', $tiers[1]['tier']);
    assert_equals(75, (int) $tiers[1]['minDataQuality']);
    assert_equals(70.0, (float) $tiers[1]['minConfidence']);
    assert_equals('LIMITED', $tiers[2]['tier']);
    assert_equals(65, (int) $tiers[2]['minDataQuality']);
    assert_equals(65.0, (float) $tiers[2]['minConfidence']);
    assert_equals('SAFE', $tiers[2]['markets'], 'thin evidence is restricted to the safer markets');
    assert_equals(65, $policy->minimumDataQuality(), 'below 65 nothing is predictable');
});

test('adaptive policy: the ladder is configuration, not hard-coded — moving the floors moves every tier', function () {
    $strict = ConfidencePolicy::fromConfiguration(['min_confidence' => 85.0, 'min_data_quality' => 90]);
    assert_equals(85.0, $strict->highestConfidenceRequirement(), 'a stricter operator gets a stricter top tier');
    assert_equals(80.0, $strict->requiredConfidence(88), 'and a stricter middle tier');
    // The configured min_data_quality is the ORDINARY band, and the ladder
    // brackets it — so raising it to 90 lifts the reject floor from 65 to 75
    // (the stock 80 configuration is what produces the requested 65 floor).
    assert_equals(75, $strict->minimumDataQuality(), 'the reject floor rises with the configured quality floor');
    assert_null($strict->requiredConfidence(70), 'quality 70 is not predictable under a 90 quality policy');
    assert_equals('LIMITED', $strict->tierFor(78)['tier'], 'the band below the configured floor is the restricted one');

    $lenient = ConfidencePolicy::fromConfiguration(['min_confidence' => 60.0, 'min_data_quality' => 60]);
    assert_equals(60.0, $lenient->highestConfidenceRequirement());
    assert_equals(50, $lenient->minimumDataQuality(), 'never below the assessable absolute floor');
    assert_true($lenient->requiredConfidence(58) !== null, 'a deliberately lenient operator can reach further down');
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
    assert_equals(68.0, $explicit->requiredConfidence(72));
    assert_equals('HOUSE_LOW', $explicit->tierFor(72)['tier']);

    // Unusable input never silently becomes a policy.
    assert_null(ConfidencePolicy::normalizePolicy('not json'));
    assert_null(ConfidencePolicy::normalizePolicy([['minDataQuality' => 'high', 'minConfidence' => 70]]));
    assert_null(ConfidencePolicy::normalizePolicy([['minDataQuality' => 80, 'minConfidence' => 140]]), 'a >100% requirement is not a policy');
    // …and a configuration carrying one is refused rather than ignored.
    $repo = new SportsRepositoryStub();
    $audit = fx145_audit();
    $svc = new ConfigurationService($repo, $audit);
    assert_false($svc->update(['confidence_policy' => 'not json'], 'admin')['ok'], 'an unreadable policy is rejected, never silently dropped');
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
    $policy = ConfidencePolicy::fromConfiguration(ConfigurationService::defaults());
    $verdict = $policy->evaluate(41, 99.0, 'TOTAL_GOALS', 'OVER_1_5');
    assert_false($verdict['qualified'], 'quality 41 is not predictable at any confidence');
    assert_equals('REJECT', $verdict['tier']);
    assert_equals(['DATA_QUALITY_BELOW_MINIMUM'], $verdict['reasons']);
    // Requirement #13: the reason names the score AND the minimum allowed.
    assert_contains('41', $verdict['explanation']);
    assert_contains('65', $verdict['explanation']);
    assert_null($verdict['requiredConfidence'], 'no confidence requirement is quoted for an unpredictable fixture');
});

test('adaptive policy: the LIMITED tier admits safer markets only — thin data never prices a thin line', function () {
    $policy = ConfidencePolicy::fromConfiguration(ConfigurationService::defaults());
    assert_equals('LIMITED', $policy->tierFor(68)['tier']);

    // Safe: two of three outcomes, or a line the game almost always clears.
    assert_true($policy->marketAllowed(68, 'DOUBLE_CHANCE', 'HOME_OR_DRAW'));
    assert_true($policy->marketAllowed(68, 'DRAW_NO_BET', 'HOME'));
    assert_true($policy->marketAllowed(68, 'TOTAL_GOALS', 'OVER_0_5'));
    assert_true($policy->marketAllowed(68, 'TOTAL_GOALS', 'UNDER_3_5'));
    // Not safe on limited evidence.
    assert_false($policy->marketAllowed(68, 'MATCH_RESULT', 'HOME'));
    assert_false($policy->marketAllowed(68, 'BTTS', 'YES'));
    assert_false($policy->marketAllowed(68, 'TOTAL_GOALS', 'OVER_2_5'));
    // The same markets are all fine once the evidence is there.
    assert_true($policy->marketAllowed(92, 'MATCH_RESULT', 'HOME'));
    assert_true($policy->marketAllowed(92, 'BTTS', 'YES'));

    $verdict = $policy->evaluate(68, 90.0, 'BTTS', 'YES');
    assert_false($verdict['qualified'], 'a high confidence cannot buy a restricted market');
    assert_equals(['MARKET_RESTRICTED_AT_DATA_TIER'], $verdict['reasons']);
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
