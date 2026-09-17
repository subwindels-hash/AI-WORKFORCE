<?php
use AIWorkforce\Sports\TicketOptimizer;
function fx_candidate(int $match, float $odds, float $ev, string $league = 'League'): array { return ['matchId' => $match, 'competition' => $league, 'market' => 'TOTAL_GOALS', 'value' => ['qualified' => true, 'odds' => $odds, 'expectedValue' => $ev], 'risk' => ['approved' => true, 'classification' => 'LOW'], 'confidence' => ['confidence' => 90], 'quality' => ['score' => 90], 'match' => ['competition' => $league]]; }
test('ticket optimizer returns no qualified ticket instead of padding invalid odds', function () {
    $out = (new TicketOptimizer())->optimize([fx_candidate(1, 1.3, .1)], ['targetOddsMin' => 5, 'targetOddsMax' => 8]);
    assert_equals('NO_QUALIFIED_TICKET', $out['status']);
});
test('ticket optimizer selects qualifying low-correlation combination', function () {
    $out = (new TicketOptimizer())->optimize([fx_candidate(1, 2, .05, 'L1'), fx_candidate(2, 3, .08, 'L2'), fx_candidate(3, 1.5, .02, 'L3')], ['targetOddsMin' => 5, 'targetOddsMax' => 7, 'maxSelections' => 3]);
    assert_equals('QUALIFIED', $out['status']); assert_close(6, $out['totalOdds'], .001); assert_equals(2, $out['selectionCount']);
});
test('ticket optimizer tie-breaks equal-score candidates on lower risk then fresher odds', function () {
    // Identical ranking score/risk/EV; the fresher quote (smaller
    // oddsAgeSeconds) must sort ahead and be taken as the single leg.
    $fresher = array_merge(fx_candidate(1, 5.0, .50, 'L1'), ['oddsAgeSeconds' => 120]);
    $older   = array_merge(fx_candidate(2, 6.0, .50, 'L2'), ['oddsAgeSeconds' => 18000]);
    $out = (new TicketOptimizer())->optimize([$older, $fresher], ['targetOddsMin' => 5, 'targetOddsMax' => 8, 'maxSelections' => 1]);
    assert_equals('QUALIFIED', $out['status']);
    assert_equals(1, $out['selectionCount']);
    assert_close(5.0, (float) $out['selections'][0]['value']['odds'], 0.001, 'the fresher candidate wins the tie-break');
    assert_equals(1, (int) $out['selections'][0]['matchId']);

    // A MEDIUM-risk candidate loses to an otherwise identical LOW-risk one.
    $medium = array_merge(fx_candidate(3, 5.1, .50, 'L3'), ['oddsAgeSeconds' => 1, 'risk' => ['approved' => true, 'classification' => 'MEDIUM']]);
    $low    = array_merge(fx_candidate(4, 5.1, .50, 'L4'), ['oddsAgeSeconds' => 99999, 'risk' => ['approved' => true, 'classification' => 'LOW']]);
    $out2 = (new TicketOptimizer())->optimize([$medium, $low], ['targetOddsMin' => 5, 'targetOddsMax' => 8, 'maxSelections' => 1]);
    assert_equals('QUALIFIED', $out2['status']);
    assert_equals(4, (int) $out2['selections'][0]['matchId'], 'lower risk outranks freshness');
});

test('ticket optimizer never combines same-match selections', function () {
    $out = (new TicketOptimizer())->optimize([fx_candidate(1, 2.5, .1), fx_candidate(1, 2.5, .1)], ['targetOddsMin' => 5, 'targetOddsMax' => 7]);
    assert_equals('NO_QUALIFIED_TICKET', $out['status']);
});

test('ticket optimizer honours the configured window down to the 1.01 sanity floor', function () {
    // The configured window is respected exactly: a 4.9 leg under a 5.0–8.0
    // window cannot make a ticket — the day honestly returns nothing rather
    // than a below-window ticket.
    $low = (new TicketOptimizer())->optimize([fx_candidate(1, 4.9, .50)], ['targetOddsMin' => 5, 'targetOddsMax' => 8, 'maxSelections' => 1]);
    assert_equals('NO_QUALIFIED_TICKET', $low['status'], '4.9 is below the configured minimum — no ticket');

    // Exactly the configured minimum is allowed.
    $atFloor = (new TicketOptimizer())->optimize([fx_candidate(1, 5.0, .50)], ['targetOddsMin' => 5, 'targetOddsMax' => 8, 'maxSelections' => 1]);
    assert_equals('QUALIFIED', $atFloor['status'], '5.0 exactly is a valid ticket under a 5.0+ window');
    assert_close(5.0, (float) $atFloor['totalOdds'], 0.001);

    // Low-variance windows are now CONFIGURABLE (operator decision
    // 2026-09-17): a 2.0–4.0 window admits a single 3.0 leg.
    $lowVariance = (new TicketOptimizer())->optimize([fx_candidate(1, 3.0, .50)], ['targetOddsMin' => 2.0, 'targetOddsMax' => 4.0, 'maxSelections' => 1]);
    assert_equals('QUALIFIED', $lowVariance['status'], 'a 2.0–4.0 window is honoured, not clamped to 5.0');
    assert_close(3.0, (float) $lowVariance['totalOdds'], 0.001);

    // The 1.01 sanity floor is absolute: a caller cannot configure a window
    // that would accept un-stakeable odds. A "leg" at 1.005 makes no ticket.
    $insane = (new TicketOptimizer())->optimize([fx_candidate(1, 1.005, .50)], ['targetOddsMin' => 0.5, 'targetOddsMax' => 1.008, 'maxSelections' => 1]);
    assert_equals('NO_QUALIFIED_TICKET', $insane['status'], 'odds at or below 1.01 are never a ticket');

    // Two legs that multiply into the window qualify.
    $combo = (new TicketOptimizer())->optimize([fx_candidate(1, 2.4, .10, 'L1'), fx_candidate(2, 2.3, .10, 'L2')], ['targetOddsMin' => 5, 'targetOddsMax' => 8, 'maxSelections' => 3]);
    assert_equals('QUALIFIED', $combo['status']);
    assert_true((float) $combo['totalOdds'] >= 5.0, 'a multi-leg ticket lands inside the configured window');
});

test('ticket optimizer enforces WINDELS daily ticket hard floors', function () {
    // Both hard gates are 30. An explicit admin setting is honoured when it is
    // STRICTER, and can never lower either gate. 29.99 is rejected on either
    // axis; 30 passes. Confidence is never inflated to clear the bar.
    $out = (new TicketOptimizer())->optimize([
        fx_candidate(1, 4.9, .50),
        array_merge(fx_candidate(2, 5.5, .50), ['confidence' => ['confidence' => 29.99]]),
        array_merge(fx_candidate(3, 5.6, .50), ['quality' => ['score' => 29]]),
        fx_candidate(4, 8.01, .50),
        fx_candidate(5, 6.0, .50),
    ], ['targetOddsMin' => 1.1, 'targetOddsMax' => 99, 'maxSelections' => 1, 'minConfidence' => 10, 'minDataQuality' => 10]);
    assert_equals('QUALIFIED', $out['status']);
    assert_equals(3, $out['poolSize'], 'below-gate candidates are excluded even when the caller asks for 10');
    assert_equals(1, $out['selectionCount']);

    // Exactly at the gates: a measured 30.00% on quality exactly 30 qualifies.
    $atGate = (new TicketOptimizer())->optimize([
        array_merge(fx_candidate(6, 5.5, .50), ['confidence' => ['confidence' => 30.0], 'quality' => ['score' => 30]]),
    ], ['targetOddsMin' => 1.1, 'targetOddsMax' => 99, 'maxSelections' => 1, 'minConfidence' => 10, 'minDataQuality' => 10]);
    assert_equals(1, $atGate['poolSize'], 'a measured 30% on quality 30 is eligible for consideration');

    // Mid-range evidence the old 75 floor discarded is now usable.
    $mid = (new TicketOptimizer())->optimize([
        array_merge(fx_candidate(7, 5.5, .50), ['confidence' => ['confidence' => 45.0], 'quality' => ['score' => 60]]),
    ], ['targetOddsMin' => 1.1, 'targetOddsMax' => 99, 'maxSelections' => 1, 'minConfidence' => 10, 'minDataQuality' => 10]);
    assert_equals(1, $mid['poolSize'], 'quality 60 is eligible under the 30 floor');

    // A stricter operator setting is honoured above the gates.
    $usable = (new TicketOptimizer())->optimize([
        array_merge(fx_candidate(2, 5.5, .50), ['confidence' => ['confidence' => 35.0]]),
        array_merge(fx_candidate(3, 5.6, .50), ['quality' => ['score' => 80]]),
    ], ['targetOddsMin' => 1.1, 'targetOddsMax' => 99, 'maxSelections' => 12, 'minConfidence' => 30, 'minDataQuality' => 80]);
    // Both legs clear the stricter 80 floor (fx_candidate ships quality 90 and
    // the second leg is explicitly 80), so both enter the pool.
    assert_equals(2, $usable['poolSize'], 'candidates clearing BOTH stricter floors enter the pool');
    assert_equals('QUALIFIED', $usable['status']);
});
