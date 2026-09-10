<?php
use AIWorkforce\Sports\MatchIntelligenceEngine;
use AIWorkforce\Sports\OddsFreshnessEngine;
use AIWorkforce\Sports\PredictionPipeline;

test('odds freshness reports unavailable, stale and fresh odds with full provenance', function () {
    $engine = new OddsFreshnessEngine();
    $unavailable = $engine->assess(null);
    assert_equals('ODDS_UNAVAILABLE', $unavailable['reason']);
    assert_equals(OddsFreshnessEngine::STATUS_UNAVAILABLE, $unavailable['oddsStatus']);
    // Stale means the CONFIGURED maximum age was actually exceeded…
    $stale = $engine->assess(['observedAt' => '2026-01-01T00:00:00Z'], 900, strtotime('2026-01-01T01:00:00Z'));
    assert_false($stale['fresh']); assert_equals('STALE_ODDS', $stale['reason']);
    assert_equals(OddsFreshnessEngine::STATUS_STALE, $stale['oddsStatus']);
    assert_equals(3600, $stale['oddsAgeSeconds']);
    assert_equals('2026-01-01T00:00:00+00:00', $stale['oddsUpdatedAt']);
    // …and odds inside the TTL are FRESH no matter when the current run started.
    $fresh = $engine->assess(['observedAt' => gmdate('c', time() - 5 * 3600)], null, time());
    assert_true($fresh['fresh'], '5h-old pre-match odds are fresh under the 6h default TTL');
    assert_equals(OddsFreshnessEngine::STATUS_FRESH, $fresh['oddsStatus']);
    assert_equals(21600, $fresh['maxAgeSeconds']);
    // The TTL is configurable per market, provider and environment.
    assert_equals(900, OddsFreshnessEngine::maxAgeFor(null, null, 900));
    assert_equals(3600, OddsFreshnessEngine::maxAgeFor('MATCH_RESULT', null, null, ['MATCH_RESULT' => 3600]));
    assert_equals(600, OddsFreshnessEngine::maxAgeFor(null, 'api-football', null, [], ['api-football' => 600]));
    $invalid = $engine->assess(['observedAt' => 'not-a-timestamp']);
    assert_equals(OddsFreshnessEngine::STATUS_INVALID_TIMESTAMP, $invalid['oddsStatus']);
});

test('match intelligence reports unavailable odds instead of invalidating the match', function () {
    // Odds availability is gated downstream by the prediction pipeline (in
    // stage order) — the intelligence layer REPORTS it. One missing optional
    // source must never invalidate a match by itself.
    $intelligence = new MatchIntelligenceEngine();
    $out = $intelligence->analyze(['homeTeam' => 'Home', 'awayTeam' => 'Away', 'competition' => 'League', 'kickoff' => '2026-09-01T12:00:00Z', 'status' => 'SCHEDULED'], null, [], strtotime('2026-09-01T10:00:00Z'));
    assert_equals('INTELLIGENCE_READY', $out['decision']);
    assert_equals(OddsFreshnessEngine::STATUS_UNAVAILABLE, $out['oddsFreshness']['oddsStatus']);
    // …and the pipeline rejects the candidate with ODDS_UNAVAILABLE before
    // any prediction is generated (never invented odds).
    $pipeline = new PredictionPipeline();
    $candidate = $pipeline->evaluate(
        ['id' => 1, 'home_team' => 'Home', 'away_team' => 'Away', 'competition' => 'League', 'kickoff_at' => '2026-09-01T12:00:00+00:00', 'status' => 'SCHEDULED', 'payload' => ['context' => ['recentForm' => ['homeGoalsPerMatch' => 1.6, 'awayGoalsPerMatch' => 1.4, 'homeConcededPerMatch' => 1.0, 'awayConcededPerMatch' => 0.9, 'source' => 'v']]]],
        null,
        ['score' => 90, 'band' => 'GOOD', 'missing' => [], 'missingMandatory' => []],
        null,
        ['require_calibration' => 0],
        strtotime('2026-09-01T10:00:00Z')
    );
    assert_equals('REJECTED', $candidate['decision']);
    assert_equals('ODDS_UNAVAILABLE', $candidate['primaryReason']);
    assert_equals([], $candidate['rejectionDetail']['missingFields']);
});

test('match intelligence is ready only with fresh odds and supplied verified form', function () {
    $out = (new MatchIntelligenceEngine())->analyze(['homeTeam' => 'Home', 'awayTeam' => 'Away', 'competition' => 'League', 'kickoff' => '2026-09-01T12:00:00Z', 'status' => 'SCHEDULED'], ['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 1.5, 'observedAt' => '2026-09-01T10:00:00Z'], ['recentForm' => ['source' => 'verified-feed']], strtotime('2026-09-01T10:01:00Z'));
    assert_equals('INTELLIGENCE_READY', $out['decision']);
});
