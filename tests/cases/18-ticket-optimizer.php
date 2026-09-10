<?php
use AIWorkforce\Sports\TicketOptimizer;
function fx_candidate(int $match, float $odds, float $ev): array { return ['matchId' => $match, 'competition' => 'League', 'market' => 'TOTAL_GOALS', 'value' => ['qualified' => true, 'odds' => $odds, 'expectedValue' => $ev], 'risk' => ['approved' => true, 'classification' => 'LOW'], 'confidence' => ['confidence' => 90], 'quality' => ['score' => 90], 'match' => ['competition' => 'League']]; }
test('ticket optimizer returns no qualified ticket instead of padding invalid odds', function () {
    $out = (new TicketOptimizer())->optimize([fx_candidate(1, 1.3, .1)], ['targetOddsMin' => 5, 'targetOddsMax' => 8]);
    assert_equals('NO_QUALIFIED_TICKET', $out['status']);
});
test('ticket optimizer selects qualifying low-correlation combination', function () {
    $out = (new TicketOptimizer())->optimize([fx_candidate(1, 2, .05), fx_candidate(2, 3, .08), fx_candidate(3, 1.5, .02)], ['targetOddsMin' => 5, 'targetOddsMax' => 7, 'maxSelections' => 3]);
    assert_equals('QUALIFIED', $out['status']); assert_close(6, $out['totalOdds'], .001); assert_equals(2, $out['selectionCount']);
});
test('ticket optimizer never combines same-match selections', function () {
    $out = (new TicketOptimizer())->optimize([fx_candidate(1, 2.5, .1), fx_candidate(1, 2.5, .1)], ['targetOddsMin' => 5, 'targetOddsMax' => 7]);
    assert_equals('NO_QUALIFIED_TICKET', $out['status']);
});

test('ticket optimizer enforces WINDELS daily ticket hard floors', function () {
    // The absolute floors are 30/30 — an explicit admin setting is honoured,
    // never clamped back up to a hard-coded 70/75 — so only sub-30 candidates
    // are excluded when the caller asks for 10.
    $out = (new TicketOptimizer())->optimize([
        fx_candidate(1, 4.9, .50),
        array_merge(fx_candidate(2, 5.5, .50), ['confidence' => ['confidence' => 29.99]]),
        array_merge(fx_candidate(3, 5.6, .50), ['quality' => ['score' => 29]]),
        fx_candidate(4, 8.01, .50),
        fx_candidate(5, 6.0, .50),
    ], ['targetOddsMin' => 1.1, 'targetOddsMax' => 99, 'maxSelections' => 1, 'minConfidence' => 10, 'minDataQuality' => 10]);
    assert_equals('QUALIFIED', $out['status']);
    assert_equals(3, $out['poolSize'], 'below-30 candidates are excluded even when the caller asks for 10');
    assert_equals(1, $out['selectionCount']);
    // ...and a 35% confidence candidate is admitted at the new 30% floor
    // (the data-quality floor stays 50 — only the confidence gate moved).
    $usable = (new TicketOptimizer())->optimize([
        array_merge(fx_candidate(2, 5.5, .50), ['confidence' => ['confidence' => 35.0]]),
        array_merge(fx_candidate(3, 5.6, .50), ['quality' => ['score' => 60]]),
    ], ['targetOddsMin' => 1.1, 'targetOddsMax' => 99, 'maxSelections' => 12, 'minConfidence' => 30, 'minDataQuality' => 60]);
    assert_equals(2, $usable['poolSize'], 'a 35% confidence candidate enters the pool at the 30% floor');
    assert_equals('QUALIFIED', $usable['status']);
});
