<?php
/**
 * Surfacing odds movement in the dashboard.
 *
 * OddsMovementEngine reconstructs opening/previous/current/direction from
 * the stored observation history, and the pipeline persists that block on
 * the decision record at factors.movement. Until now nothing rendered it:
 * a selection showed a bare current price, so a favourite that had
 * shortened 12% since opening — money arriving after our snapshot — looked
 * identical to one that had not moved at all.
 *
 * These cases pin the surfaced view AND the honesty rules it inherits:
 *
 *   • the movement shown is the one READ BACK from the decision record, so
 *     it is the evidence the model actually used and cannot silently drift
 *     from it when today's observations change;
 *   • fewer than two observations reads "not measured", NEVER "stable" —
 *     an unmoved price and an unobserved price are different facts;
 *   • percentages are labelled as being against the OPENING price;
 *   • a missing/corrupt factors blob degrades to "not measured" and still
 *     renders the pick, rather than failing the page.
 */
use AIWorkforce\Sports\OddsMovementEngine;

// Reuses the real view-rendering harness (fx_render_sports) from case 50 so
// these assertions run against the actual template, not a copy of it.
require_once __DIR__ . '/50-sports-dashboard-ui.php';

/** Render the tickets page with one selection carrying $movement. */
function fx154_render(?array $movement, array $selectionExtra = []): string
{
    $selection = array_merge([
        'prediction_id' => 'pred-154',
        'match_id' => 1,
        'home_team' => 'Alpha FC',
        'away_team' => 'Beta United',
        'competition' => 'League One',
        'kickoff_time' => gmdate('c'),
        'market' => 'MATCH_RESULT',
        'selection' => 'HOME',
        'odds' => 1.80,
        'odds_timestamp' => gmdate('c'),
        'odds_source' => 'ui-test',
        'fair_odds' => 1.65,
        'calibrated_probability' => 0.606,
        'confidence' => 72,
        'data_quality' => 81,
        'expected_value' => 0.09,
        'risk' => 'LOW',
        'status' => 'PENDING',
        'movement' => $movement,
    ], $selectionExtra);

    return fx_render_sports('tickets', [
        'tickets' => [],
        'dailyRuns' => [],
        'performance' => ['summary' => []],
        'todayIso' => gmdate('Y-m-d'),
        'todayRun' => ['ticket_id' => 'tkt-154', 'status' => 'GENERATED'],
        'todayTicket' => ['id' => 'tkt-154', 'total_odds' => 1.80, 'selection_count' => 1,
                          'approval_status' => 'PENDING_USER_APPROVAL', 'settlement_status' => 'PENDING'],
        'todaySelections' => [$selection],
    ]);
}

/** A measured block, as OddsMovementEngine would produce it. */
function fx154_measured(float $opening, float $current, string $source = 'OBSERVED'): array
{
    return OddsMovementEngine::assess([
        ['decimalOdds' => $opening, 'observedAt' => gmdate('c', time() - 7200)],
        ['decimalOdds' => ($opening + $current) / 2, 'observedAt' => gmdate('c', time() - 3600)],
        ['decimalOdds' => $current, 'observedAt' => gmdate('c', time() - 600)],
    ], $source === 'PROVIDER' ? $opening : null);
}

test('movement UI: the selections table has a movement column', function () {
    $html = fx154_render(fx154_measured(2.10, 1.80));
    assert_contains('Movement', $html, 'the column exists');
    assert_contains('vs open', $html, 'the percentage states its baseline is the opening price');
});

test('movement UI: a shortening price is shown as shortening, with its percentage', function () {
    // 2.00 → 1.80 is −10% against the opening: money arrived.
    $movement = fx154_measured(2.00, 1.80);
    assert_equals('DOWN', $movement['movement'], 'precondition: the engine calls this shortening');

    $html = fx154_render($movement);
    assert_contains('Shortening', $html);
    assert_contains('-10.00%', $html, 'the move is quantified against the opening price');
    // The legend explains the "not measured" wording on every render, so
    // scope the negative to the BADGE actually rendered for this row.
    assert_true(!str_contains($html, '>· not measured<'), 'a measured move is never badged unmeasured');
});

test('movement UI: a drifting price is distinguished from a shortening one', function () {
    $movement = fx154_measured(1.80, 2.00);
    assert_equals('UP', $movement['movement'], 'precondition: the engine calls this drifting');

    $html = fx154_render($movement);
    assert_contains('Drifting', $html);
    assert_contains('+11.11%', $html, 'a drift is signed positive against the opening');
    assert_true(!str_contains($html, 'Shortening'), 'the two directions are never conflated');
});

test('movement UI: a single observation reads "not measured", never "stable"', function () {
    // The central honesty rule. One quote has no movement; calling that
    // STABLE would assert the market held firm when we simply never looked.
    $movement = OddsMovementEngine::assess([
        ['decimalOdds' => 1.80, 'observedAt' => gmdate('c', time() - 600)],
    ], null);
    assert_equals(OddsMovementEngine::STATE_INSUFFICIENT_HISTORY, $movement['state'], 'precondition');

    $html = fx154_render($movement);
    assert_contains('not measured', $html);
    assert_true(!str_contains($html, '= Stable'), 'an unobserved price is never presented as a stable one');
    assert_true(!str_contains($html, '% vs open'), 'no percentage is shown for an unmeasured move');
});

test('movement UI: a genuinely unmoved price IS reported as stable', function () {
    // The counterpart: two real observations at the same price is a fact
    // about the market, and must not be hidden behind "not measured".
    $movement = fx154_measured(1.80, 1.80);
    assert_equals('STABLE', $movement['movement'], 'precondition: the engine calls this stable');

    $html = fx154_render($movement);
    assert_contains('= Stable', $html, 'the badge states it');
    assert_true(!str_contains($html, '>· not measured<'), 'a measured non-move is a real observation');
});

test('movement UI: a missing movement block degrades without losing the pick', function () {
    $html = fx154_render(null);
    assert_contains('not measured', $html, 'the absence is stated');
    assert_contains('Alpha FC', $html, 'and the selection itself still renders');
    assert_contains('1.80', $html, 'including its price');
});

test('movement UI: the provider opening is credited when it supplied one', function () {
    $movement = fx154_measured(2.00, 1.80, 'PROVIDER');
    assert_equals('PROVIDER', $movement['openingSource'], 'precondition');
    $html = fx154_render($movement);
    // e() escapes the apostrophe, so match the rendered entity form.
    assert_contains('provider&#039;s stated opening', $html,
        'the opening price names its origin, since a feed opening beats our first sync');
});

test('movement UI: the observed history travels with the price', function () {
    $movement = fx154_measured(2.00, 1.80);
    $html = fx154_render($movement);
    // The stored observations back the claim, so the number is auditable
    // rather than something the page asserts on its own authority.
    assert_contains('Observed:', $html);
    assert_contains('3 observations', $html, 'the sample size behind the move is stated');
    assert_contains('opened 2.00', $html);
    assert_contains('current 1.80', $html);
});

test('movement UI: the controller reads movement off the decision record', function () {
    // Not recomputed from today's odds: the row must show the evidence the
    // model actually used when it made the pick.
    $src = file_get_contents(APPPATH . 'controllers/Sports.php');
    assert_true(str_contains($src, 'findPrediction'), 'the movement comes from the stored prediction');
    assert_true((bool) preg_match('/factors.*movement/s', (string) $src), 'read from factors.movement');
    assert_true(str_contains($src, "\$selection['movement'] = null"),
        'it defaults to null so an unreadable record renders as "not measured", never a fabricated direction');
});
