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

test('ticket optimizer never generates below the 5.0 combined-odds floor', function () {
    // A single 4.9 leg cannot make a ticket: 4.9 < 5.0, so the day honestly
    // returns nothing rather than a sub-floor ticket.
    $low = (new TicketOptimizer())->optimize([fx_candidate(1, 4.9, .50)], ['targetOddsMin' => 5, 'targetOddsMax' => 8, 'maxSelections' => 1]);
    assert_equals('NO_QUALIFIED_TICKET', $low['status'], '4.9 is below the floor — no ticket');

    // Exactly 5.0 is allowed — five is the LOWEST a ticket may be.
    $atFloor = (new TicketOptimizer())->optimize([fx_candidate(1, 5.0, .50)], ['targetOddsMin' => 5, 'targetOddsMax' => 8, 'maxSelections' => 1]);
    assert_equals('QUALIFIED', $atFloor['status'], '5.0 exactly is a valid ticket');
    assert_close(5.0, (float) $atFloor['totalOdds'], 0.001);
    assert_true((float) $atFloor['totalOdds'] >= 5.0, 'the generated ticket is 5.0 and above');

    // A caller asking for a LOWER minimum cannot lower the floor: 2.0 is
    // clamped up to 5.0, so a 3.0 leg still cannot make a ticket.
    $cannotLower = (new TicketOptimizer())->optimize([fx_candidate(1, 3.0, .50)], ['targetOddsMin' => 2.0, 'targetOddsMax' => 4.0, 'maxSelections' => 1]);
    assert_equals('NO_QUALIFIED_TICKET', $cannotLower['status'], 'the 5.0 floor cannot be lowered by config');

    // Two legs that multiply to >= 5.0 qualify; the total is never below 5.0.
    $combo = (new TicketOptimizer())->optimize([fx_candidate(1, 2.4, .10, 'L1'), fx_candidate(2, 2.3, .10, 'L2')], ['targetOddsMin' => 5, 'targetOddsMax' => 8, 'maxSelections' => 3]);
    assert_equals('QUALIFIED', $combo['status']);
    assert_true((float) $combo['totalOdds'] >= 5.0, 'a multi-leg ticket is still 5.0 and above');
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
