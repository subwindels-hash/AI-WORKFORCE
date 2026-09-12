<?php
/**
 * Odds movement + market-scoped odds states (WINDELS odds record spec).
 *
 * Two things the odds layer was missing / had to be proven:
 *
 * 1. MOVEMENT. sports_odds is append-only — every observed quote is stored —
 *    but the daily engine collapsed that to the newest row per
 *    market:selection and discarded the rest. The only movement signal left
 *    was a provider-supplied `openingDecimalOdds`, a field most feeds never
 *    send, so `oddsMovement` was almost always null and RiskEngine's
 *    ODDS_VOLATILE upgrade could never fire on real data.
 *    OddsMovementEngine now reconstructs opening/previous/current/direction/
 *    percentage/history from the STORED observations.
 *
 * 2. MARKET SCOPE. "Odds unavailable", "odds stale" and "odds fresh" are
 *    per market:selection facts. A fixture must NOT be rejected because one
 *    market has no/stale odds while another supported market has a fresh
 *    price — only when NO supported market has a usable price.
 *
 * Honesty pins: one observation is INSUFFICIENT_HISTORY, never "STABLE";
 * a not-measured movement is null, never 0.0 (RiskEngine must be able to
 * tell "did not move" from "never measured").
 */
use AIWorkforce\Sports\OddsMovementEngine;

function ci149_obs(string $at, float $odds): array
{
    return ['decimalOdds' => $odds, 'observedAt' => $at];
}

test('movement: opening, previous, current and direction come from stored observations', function () {
    // The worked example from the spec: 2.10 → 1.80.
    $m = OddsMovementEngine::assess([
        ci149_obs('2026-09-11T12:00:00+00:00', 2.10),
        ci149_obs('2026-09-11T13:00:00+00:00', 2.00),
        ci149_obs('2026-09-11T14:00:00+00:00', 1.92),
        ci149_obs('2026-09-11T15:00:00+00:00', 1.95),
        ci149_obs('2026-09-11T16:00:00+00:00', 1.80),
    ]);

    assert_equals('MEASURED', $m['state']);
    assert_equals(2.10, $m['openingOdds'], 'opening is the OLDEST observation');
    assert_equals('OBSERVED', $m['openingSource']);
    assert_equals(1.95, $m['previousOdds'], 'previous is the price before the current one');
    assert_equals(1.80, $m['currentOdds']);
    assert_equals('DOWN', $m['movement'], 'the price shortened');
    assert_equals(-0.3, round((float) $m['movementAbsolute'], 4));
    // -0.30 / 2.10 = -14.29%
    assert_close(-14.29, (float) $m['movementPercentage'], 0.01, 'percentage is measured against the opening price');
    assert_equals(5, $m['observations']);
    assert_equals(5, count($m['oddsHistory']), 'the observation history travels with the record');
    assert_equals(2.10, $m['oddsHistory'][0]['odds'], 'history is chronological');
});

test('movement: out-of-order and invalid observations are sorted and dropped, never guessed', function () {
    $m = OddsMovementEngine::assess([
        ci149_obs('2026-09-11T16:00:00+00:00', 1.80),
        ci149_obs('2026-09-11T12:00:00+00:00', 2.10),
        ['decimalOdds' => 0.0, 'observedAt' => '2026-09-11T13:00:00+00:00'],   // not a decimal price
        ['decimalOdds' => 1.90, 'observedAt' => 'not-a-timestamp'],            // unusable stamp
        ['decimalOdds' => 1.95, 'observedAt' => ''],                           // no stamp
    ]);
    assert_equals(2, $m['observations'], 'only the two real observations count');
    assert_equals(2.10, $m['openingOdds']);
    assert_equals(1.80, $m['currentOdds']);
    assert_equals('DOWN', $m['movement']);
});

test('movement: drifting and stable are distinguished, and noise is not a move', function () {
    $up = OddsMovementEngine::assess([
        ci149_obs('2026-09-11T12:00:00+00:00', 1.80),
        ci149_obs('2026-09-11T16:00:00+00:00', 2.10),
    ]);
    assert_equals('UP', $up['movement'], 'the price drifted');
    assert_close(16.67, (float) $up['movementPercentage'], 0.01);

    $noise = OddsMovementEngine::assess([
        ci149_obs('2026-09-11T12:00:00+00:00', 1.80),
        ci149_obs('2026-09-11T16:00:00+00:00', 1.8009),
    ]);
    assert_equals('STABLE', $noise['movement'], 'float noise below tolerance is not a market move');
});

test('movement: a single observation is INSUFFICIENT_HISTORY, never STABLE', function () {
    $m = OddsMovementEngine::assess([ci149_obs('2026-09-11T16:00:00+00:00', 1.80)]);
    assert_equals('INSUFFICIENT_HISTORY', $m['state'], 'one quote has no movement');
    assert_equals('UNKNOWN', $m['movement'], 'an unmoved price and an unobserved price are different facts');
    assert_null($m['openingOdds'], 'no opening is invented from a single quote');
    assert_null($m['movementPercentage']);
    assert_equals(1.80, $m['currentOdds'], 'the price itself is still reported');

    // ... and RiskEngine must receive null, not 0.0.
    assert_null(OddsMovementEngine::riskSignal($m), 'not-measured must never read as did-not-move');

    $none = OddsMovementEngine::assess([]);
    assert_equals('INSUFFICIENT_HISTORY', $none['state']);
    assert_null($none['currentOdds']);
});

test("movement: the provider's stated opening price beats our oldest observation", function () {
    $m = OddsMovementEngine::assess([
        ci149_obs('2026-09-11T15:00:00+00:00', 1.90),
        ci149_obs('2026-09-11T16:00:00+00:00', 1.80),
    ], 2.50);
    assert_equals(2.50, $m['openingOdds'], 'the feed saw the market open; we only saw our first sync');
    assert_equals('PROVIDER', $m['openingSource'], 'the opening provenance is stated, never implied');
    assert_equals(1.90, $m['previousOdds']);
    assert_equals('DOWN', $m['movement']);

    // A provider opening also rescues the single-observation case.
    $single = OddsMovementEngine::assess([ci149_obs('2026-09-11T16:00:00+00:00', 1.80)], 2.00);
    assert_equals('MEASURED', $single['state']);
    assert_equals(2.00, $single['openingOdds']);
    assert_equals('DOWN', $single['movement']);
});

test('movement: the risk signal is the signed opening-to-current drift', function () {
    $m = OddsMovementEngine::assess([
        ci149_obs('2026-09-11T12:00:00+00:00', 2.10),
        ci149_obs('2026-09-11T16:00:00+00:00', 1.80),
    ]);
    assert_close(-0.30, (float) OddsMovementEngine::riskSignal($m), 0.0001, 'signed drift, so direction survives');
});

test('movement: history is capped but keeps the most recent observations', function () {
    $observations = [];
    for ($i = 0; $i < 30; $i++) {
        $observations[] = ci149_obs(gmdate('c', strtotime('2026-09-11T00:00:00+00:00') + $i * 600), 2.00 + $i * 0.01);
    }
    $m = OddsMovementEngine::assess($observations);
    assert_equals(30, $m['observations'], 'every observation is counted');
    assert_equals(OddsMovementEngine::MAX_HISTORY, count($m['oddsHistory']), 'the stored history is bounded');
    $last = $m['oddsHistory'][count($m['oddsHistory']) - 1];
    assert_equals($m['currentOdds'], $last['odds'], 'the newest observation is retained');
    assert_equals(2.00, $m['openingOdds'], 'the opening is still the true oldest observation');
});

// ═══════════════════════════════════════════════════════════════════════
// Market-scoped odds states: one dead market never kills the fixture
// ═══════════════════════════════════════════════════════════════════════

test('odds states are per market:selection — a fixture keeps its fresh markets', function () {
    $engine = new \AIWorkforce\Sports\OddsFreshnessEngine();
    $now = strtotime('2026-09-11T16:00:00+00:00');

    $fresh = $engine->assess(['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 1.35,
        'observedAt' => gmdate('c', $now - 60)], null, $now);
    $stale = $engine->assess(['market' => 'MATCH_RESULT', 'selection' => 'HOME', 'decimalOdds' => 1.85,
        'observedAt' => gmdate('c', $now - 86400)], null, $now);
    $missing = $engine->assess(null, null, $now);

    assert_equals('FRESH', $fresh['oddsStatus']);
    assert_equals('STALE', $stale['oddsStatus']);
    assert_equals('UNAVAILABLE', $missing['oddsStatus'], 'no market is a distinct state from a stale market');
    assert_true((bool) $fresh['fresh'], 'the usable market stays usable');
    assert_false((bool) $stale['fresh']);

    // The engine reports the three states independently; the daily engine
    // only rejects a fixture when NO supported market survives.
    $usable = array_values(array_filter([$fresh, $stale, $missing], fn(array $a) => !empty($a['fresh'])));
    assert_equals(1, count($usable), 'one dead market must not remove the fixture');
});

test('the daily engine rejects a fixture only when every supported market is unusable', function () {
    $src = file_get_contents(APPPATH . 'libraries/AIWorkforce/Sports/DailyTicketService.php');
    // Fixture-level STALE_ODDS is guarded by "no usable row survived",
    // never by "some row was stale".
    assert_true(
        (bool) preg_match('/if \(\$usable === \[\]\) \{.*?STALE_ODDS/s', (string) $src),
        'STALE_ODDS is a fixture verdict only when the usable set is empty'
    );
    // The no-supported-market verdict is now SPLIT by cause (case 153):
    // ODDS_UNAVAILABLE (feed gap) vs MARKET_UNAVAILABLE (coverage gap).
    assert_true(
        (bool) preg_match('/if \(\$supported === 0\) \{.*?MARKET_UNAVAILABLE.*?ODDS_UNAVAILABLE/s', (string) $src),
        'no-supported-market is a fixture verdict only when no supported market was priced, split by cause'
    );
    assert_false(
        (bool) preg_match('/\$staleCount > 0\)\s*\{?\s*return \[\s*.ok. => false/s', (string) $src),
        'a non-zero stale COUNT must never by itself reject the fixture'
    );
});

// ═══════════════════════════════════════════════════════════════════════
// End to end: movement reaches the decision record and drives risk
// ═══════════════════════════════════════════════════════════════════════

function ci149_pipeline(): \AIWorkforce\Sports\PredictionPipeline
{
    return new \AIWorkforce\Sports\PredictionPipeline(
        new \AIWorkforce\Sports\MatchIntelligenceEngine(new \AIWorkforce\Sports\OddsFreshnessEngine()),
        new \AIWorkforce\Sports\FeatureEngineeringEngine(),
        new \AIWorkforce\Sports\PredictionEngine(),
        new \AIWorkforce\Sports\ValueEngine(),
        new \AIWorkforce\Sports\RiskEngine(),
        new \AIWorkforce\Sports\CorrelationEngine(),
        new \AIWorkforce\Sports\ConfidenceEngine()
    );
}

function ci149_match(): array
{
    return [
        'id' => 149001, 'external_id' => 'ci149-1', 'sport' => 'football',
        'competition' => 'CI149 League', 'home_team' => 'Alpha', 'away_team' => 'Beta',
        'kickoff_at' => gmdate('c', time() + 7200), 'status' => 'SCHEDULED',
        'payload' => json_encode(['context' => ['recentForm' => [
            'homeGoalsPerMatch' => 1.9, 'awayGoalsPerMatch' => 1.5,
            'homeConcededPerMatch' => 0.8, 'awayConcededPerMatch' => 1.1,
            'matchesPlayed' => 12, 'source' => 'test-verified', 'timestamp' => gmdate('c'),
        ], 'marketLiquidity' => 50000]]),
    ];
}

test('pipeline: the reconstructed movement block travels onto the decision record', function () {
    $now = time();
    $movement = OddsMovementEngine::assess([
        ci149_obs(gmdate('c', $now - 14400), 2.10),
        ci149_obs(gmdate('c', $now - 3600), 1.95),
        ci149_obs(gmdate('c', $now - 300), 1.80),
    ]);

    $odds = [
        'market' => 'MATCH_RESULT', 'selection' => 'HOME', 'decimalOdds' => 1.80,
        'observedAt' => gmdate('c', $now - 300), 'oddsSource' => 'ci149',
        'movement' => $movement, 'payload' => [],
    ];

    $candidate = ci149_pipeline()->evaluate(
        ci149_match(), $odds, ['score' => 92, 'band' => 'EXCELLENT', 'missing' => [], 'missingMandatory' => []],
        null, ['require_calibration' => 0], $now,
        ['marketPrices' => ['HOME' => ['odds' => 1.80], 'DRAW' => ['odds' => 3.60], 'AWAY' => ['odds' => 4.50]]]
    );

    $recorded = $candidate['factors']['movement'] ?? null;
    assert_true(is_array($recorded), 'the movement block is on the decision record factors');
    assert_equals('MEASURED', $recorded['state']);
    assert_equals('DOWN', $recorded['movement'], 'the shortening market is recorded as DOWN');
    assert_equals(2.10, $recorded['openingOdds']);
    assert_equals(1.95, $recorded['previousOdds']);
    assert_equals(1.80, $recorded['currentOdds']);
    assert_equals(3, $recorded['observations']);
    assert_true(count($recorded['oddsHistory']) === 3, 'the price history is persisted with the decision');

    // The odds factor block (the "odds record") carries it too.
    assert_equals('DOWN', $candidate['factors']['odds']['movement']['movement'] ?? null,
        'the odds record itself states the market reaction');
});

test('pipeline: a violent swing reaches RiskEngine and flags ODDS_VOLATILE', function () {
    $now = time();
    // 2.00 → 4.00: a +2.0 drift, far beyond RiskEngine::MAX_ODDS_MOVEMENT (0.5).
    $movement = OddsMovementEngine::assess([
        ci149_obs(gmdate('c', $now - 7200), 2.00),
        ci149_obs(gmdate('c', $now - 300), 4.00),
    ]);
    assert_close(2.0, (float) OddsMovementEngine::riskSignal($movement), 0.0001);

    $risk = (new \AIWorkforce\Sports\RiskEngine())->assess(
        ['qualified' => true, 'expectedValue' => 0.12],
        ['score' => 95, 'band' => 'EXCELLENT'],
        [],
        ['oddsMovement' => OddsMovementEngine::riskSignal($movement)]
    );
    assert_equals('HIGH', $risk['classification'], 'a volatile market is never LOW risk');
    assert_true(in_array('ODDS_VOLATILE', $risk['reasons'], true), 'the volatility is named on the record');

    // The same candidate with an unmeasured movement must NOT be flagged —
    // absence of history is not evidence of stability.
    $unmeasured = OddsMovementEngine::assess([ci149_obs(gmdate('c', $now - 300), 4.00)]);
    $quiet = (new \AIWorkforce\Sports\RiskEngine())->assess(
        ['qualified' => true, 'expectedValue' => 0.12],
        ['score' => 95, 'band' => 'EXCELLENT'],
        [],
        ['oddsMovement' => OddsMovementEngine::riskSignal($unmeasured)]
    );
    assert_false(in_array('ODDS_VOLATILE', $quiet['reasons'], true), 'an unmeasured market is not accused of volatility');
});
