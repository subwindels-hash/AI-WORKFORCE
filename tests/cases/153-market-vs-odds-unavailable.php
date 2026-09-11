<?php
/**
 * Coverage gaps vs feed gaps — splitting MARKET_UNAVAILABLE from
 * ODDS_UNAVAILABLE.
 *
 * The daily engine rejected a fixture with a single verdict whenever no
 * SUPPORTED market:selection carried a usable price:
 *
 *     if ($supported === 0) → SUPPORTED_ODDS_UNAVAILABLE
 *
 * That one code hid two situations with opposite operational meanings:
 *
 *   ODDS_UNAVAILABLE   — we hold no price at all for the fixture. A FEED
 *                        gap: the sync failed, the provider has no odds
 *                        endpoint for it, or the fetch never happened.
 *                        Worth a retry and worth an operator's attention.
 *
 *   MARKET_UNAVAILABLE — the bookmaker DID quote the fixture, just not a
 *                        market this engine can price (a small book that
 *                        never offers Asian handicap in a lower division).
 *                        A COVERAGE gap: the feed is perfectly healthy and
 *                        chasing it only burns provider quota.
 *
 * Reported as one number, a day full of ordinary coverage gaps looked like
 * a broken feed. These cases pin the split, and the honesty rules around
 * it: a companion-only sheet is NOT evidence of coverage, and the markets
 * the book actually quoted are recorded as a fact rather than inferred.
 */
use AIWorkforce\Sports\OddsFreshnessEngine;
use AIWorkforce\Sports\PredictionEngine;

// The engine harness lives in case 145; these cases reuse it so the split is
// proven against the REAL DailyTicketService rather than a re-implementation.
require_once __DIR__ . '/145-odds-ticket-engine-funnel.php';

/** Run one generation over fixtures seeded with exactly $rows as their odds. */
function ci153_run(array $rows): array
{
    $repo = new SportsRepositoryStub();
    $audit = fx145_audit();
    $fixtures = [fx145_fixture('f153-1', 'Alpha FC', 'Beta United', 'League One', fx145_kickoff())];
    fx145_seed($repo, 'ci153-sim', $fixtures, 600, $rows);
    fx145_approve_calibration($repo);
    $providers = new \AIWorkforce\Sports\Providers\SportsProviderManager();
    $providers->register(fx145_provider('ci153-sim', $fixtures, []));
    $service = fx145_service($repo, $audit, $providers);
    return $service->runDaily(fx145_date(), null, ['force' => true]);
}

test('vocabulary: MARKET_UNAVAILABLE is a distinct, named odds state', function () {
    // The freshness engine owns the odds-state vocabulary; a coverage gap
    // must be nameable there rather than smuggled in as "unavailable".
    assert_equals('MARKET_UNAVAILABLE', OddsFreshnessEngine::STATUS_MARKET_UNAVAILABLE);
    $states = [
        OddsFreshnessEngine::STATUS_FRESH,
        OddsFreshnessEngine::STATUS_STALE,
        OddsFreshnessEngine::STATUS_UNAVAILABLE,
        OddsFreshnessEngine::STATUS_MARKET_UNAVAILABLE,
        OddsFreshnessEngine::STATUS_INVALID_TIMESTAMP,
    ];
    assert_equals(count($states), count(array_unique($states)), 'every odds state is a distinct fact');
});

test('feed gap: a fixture with NO stored price at all is ODDS_UNAVAILABLE', function () {
    $out = ci153_run([]); // no odds rows whatsoever
    $diag = (array) ($out['diagnostics'] ?? []);
    $reasons = (array) ($out['rejectionSummary'] ?? []);

    assert_equals(1, (int) ($diag['fixturesRejectedNoOdds'] ?? 0), 'counted as a FEED gap');
    assert_equals(0, (int) ($diag['fixturesRejectedMarketUnavailable'] ?? 0), 'not counted as a coverage gap');
    assert_true(($reasons['ODDS_UNAVAILABLE'] ?? 0) >= 1, 'the rejection names the feed gap');
    assert_equals(0, (int) ($reasons['MARKET_UNAVAILABLE'] ?? 0), 'a missing feed is never called a coverage gap');
});

test('coverage gap: a fixture priced ONLY in unsupported markets is MARKET_UNAVAILABLE', function () {
    // The book quoted this fixture — corners and cards — but nothing this
    // engine can price. The feed is healthy; the market simply is not offered.
    $out = ci153_run([
        ['market' => 'CORNERS', 'selection' => 'OVER_9_5', 'decimalOdds' => 1.90],
        ['market' => 'CARDS', 'selection' => 'OVER_3_5', 'decimalOdds' => 2.10],
    ]);
    $diag = (array) ($out['diagnostics'] ?? []);
    $reasons = (array) ($out['rejectionSummary'] ?? []);

    assert_equals(1, (int) ($diag['fixturesRejectedMarketUnavailable'] ?? 0), 'counted as a COVERAGE gap');
    assert_equals(0, (int) ($diag['fixturesRejectedNoOdds'] ?? 0), 'a healthy feed is never blamed');
    assert_true(($reasons['MARKET_UNAVAILABLE'] ?? 0) >= 1, 'the rejection names the coverage gap');
    assert_equals(0, (int) ($reasons['ODDS_UNAVAILABLE'] ?? 0), 'coverage is never reported as a missing feed');

    // The markets the book DID quote are recorded, so the gap is a fact.
    $quoted = (array) ($diag['unsupportedMarketsQuoted'] ?? []);
    assert_true(isset($quoted['CORNERS:OVER_9_5']), 'the quoted unsupported market is named');
    assert_true(isset($quoted['CARDS:OVER_3_5']), 'every quoted unsupported market is named');
});

test('a companion-only price sheet is NOT evidence of coverage', function () {
    // UNDER_1_5 exists only to complete an overround; it is never a ticket
    // candidate. A book that quoted nothing else has not offered us a
    // priceable market, but it HAS priced the fixture — so this is still a
    // coverage gap, and the companion must not be listed as an
    // "unsupported market the book offers".
    $out = ci153_run([
        ['market' => 'TOTAL_GOALS', 'selection' => 'UNDER_1_5', 'decimalOdds' => 2.85],
    ]);
    $diag = (array) ($out['diagnostics'] ?? []);

    assert_true(PredictionEngine::isCompanionSelection('TOTAL_GOALS', 'UNDER_1_5'), 'precondition: it is a companion');
    assert_equals(1, (int) ($diag['fixturesRejectedMarketUnavailable'] ?? 0),
        'the fixture WAS priced, so this is coverage — not a missing feed');
    $quoted = (array) ($diag['unsupportedMarketsQuoted'] ?? []);
    assert_false(isset($quoted['TOTAL_GOALS:UNDER_1_5']),
        'a companion price is not an unsupported market the book chose to offer');
});

test('a fixture with a supported market is neither gap', function () {
    $out = ci153_run(fx145_market_rows());
    $diag = (array) ($out['diagnostics'] ?? []);
    assert_equals(0, (int) ($diag['fixturesRejectedNoOdds'] ?? 0), 'no feed gap');
    assert_equals(0, (int) ($diag['fixturesRejectedMarketUnavailable'] ?? 0), 'no coverage gap');
    assert_true((int) ($diag['fixturesWithSupportedOdds'] ?? 0) >= 1, 'the fixture reached the engine');
});

test('the two gaps are counted separately across a mixed day', function () {
    $repo = new SportsRepositoryStub();
    $audit = fx145_audit();
    $provider = $repo->ensureProvider('ci153-mixed', 'ci153-mixed');
    $providerId = (int) $provider['id'];

    $fixtures = [
        fx145_fixture('f153-feed', 'Gamma', 'Delta', 'League One', fx145_kickoff()),
        fx145_fixture('f153-cover', 'Epsilon', 'Zeta', 'League Two', fx145_kickoff(1)),
        fx145_fixture('f153-good', 'Eta', 'Theta', 'League Three', fx145_kickoff(2)),
    ];
    $saved = [];
    foreach ($fixtures as $fixture) {
        $saved[$fixture['externalId']] = $repo->saveMatch($providerId,
            \AIWorkforce\Sports\SportsDataNormalizer::fixture($fixture, 'ci153-mixed'));
    }
    $at = gmdate('c', time() - 600);
    // f153-feed: nothing at all. f153-cover: corners only. f153-good: full sheet.
    $repo->saveOdds((int) $saved['f153-cover']['id'], $providerId,
        ['market' => 'CORNERS', 'selection' => 'OVER_9_5', 'decimalOdds' => 1.9, 'observedAt' => $at]);
    foreach (fx145_market_rows() as $row) {
        $repo->saveOdds((int) $saved['f153-good']['id'], $providerId,
            ['market' => $row['market'], 'selection' => $row['selection'],
             'decimalOdds' => $row['decimalOdds'], 'observedAt' => $at]);
    }
    fx145_approve_calibration($repo);
    $providers = new \AIWorkforce\Sports\Providers\SportsProviderManager();
    $providers->register(fx145_provider('ci153-mixed', $fixtures, []));

    $out = fx145_service($repo, $audit, $providers)->runDaily(fx145_date(), null, ['force' => true]);
    $diag = (array) ($out['diagnostics'] ?? []);

    assert_equals(1, (int) ($diag['fixturesRejectedNoOdds'] ?? 0), 'exactly one feed gap');
    assert_equals(1, (int) ($diag['fixturesRejectedMarketUnavailable'] ?? 0), 'exactly one coverage gap');
    assert_true((int) ($diag['fixturesWithSupportedOdds'] ?? 0) >= 1, 'and the healthy fixture still ran');

    // The distinction has to survive into the operator-facing summary.
    $reasons = (array) ($out['rejectionSummary'] ?? []);
    assert_true(($reasons['ODDS_UNAVAILABLE'] ?? 0) >= 1, 'the feed gap is named');
    assert_true(($reasons['MARKET_UNAVAILABLE'] ?? 0) >= 1, 'the coverage gap is named');
});

test('the console shows the coverage gap separately from the feed gap', function () {
    $view = file_get_contents(APPPATH . 'views/sports/index.php');
    assert_true(str_contains($view, 'fixturesRejectedMarketUnavailable'),
        'the funnel renders the coverage gap');
    assert_true(str_contains($view, 'unsupportedMarketsQuoted'),
        'and names which markets the book quoted instead');
    // The two stats must carry different explanations, or the split is
    // invisible to the person reading the board.
    assert_true(str_contains($view, 'FEED gap'), 'the feed gap says so');
    assert_true(str_contains($view, 'COVERAGE gap'), 'the coverage gap says so');
});
