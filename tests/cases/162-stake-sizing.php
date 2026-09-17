<?php
/**
 * Stake sizing — flat units, flat percent-of-bankroll and fractional Kelly
 * (operator decision 2026-09-17).
 *
 * The stake on a ticket is a RECOMMENDATION derived from configuration; no
 * money moves (there is no external execution connector). What these cases
 * pin:
 *
 *   • FLAT mode keeps the fixed stake_amount, capped by max_exposure;
 *   • FLAT_PERCENT — the shipped default — stakes stake_percent of the
 *     configured bankroll per ticket (1.5% by default, inside the
 *     disciplined 1–2% band), capped by max_exposure;
 *   • FRACTIONAL_KELLY stakes kelly_fraction × full Kelly on the CALIBRATED
 *     combined probability and the real quoted odds, against bankroll;
 *   • a non-positive Kelly edge stakes NOTHING — no token minimum is
 *     invented for a bet the maths says not to make;
 *   • the caps (max_exposure, 4× flat unit) always hold, so one
 *     over-confident calibration can never bet a meaningful share of the
 *     bankroll on a single ticket;
 *   • every input that produced the number is returned beside it.
 */

use AIWorkforce\Sports\ConfigurationService;
use AIWorkforce\Sports\CorrelationEngine;
use AIWorkforce\Sports\StakeSizer;
use AIWorkforce\Sports\TicketGovernance;
use AIWorkforce\Persistence\AuditRepository;

function fx162_audit(): AuditRepository
{
    return new class implements AuditRepository {
        public function emit(string $t, string $s, array $d = [], string $a = 'system'): void {}
        public function recent(int $l = 100): array { return []; }
    };
}

test('flat staking returns the configured unit, capped by max_exposure', function () {
    $sizer = new StakeSizer();
    $out = $sizer->size(0.55, 2.4, ['staking_mode' => 'FLAT', 'stake_amount' => 10.0, 'max_exposure' => 100.0]);
    assert_equals('FLAT', $out['mode']);
    assert_equals(10.0, $out['stake']);

    // The unit can never exceed the exposure ceiling.
    $capped = $sizer->size(0.55, 2.4, ['staking_mode' => 'FLAT', 'stake_amount' => 500.0, 'max_exposure' => 100.0]);
    assert_equals(100.0, $capped['stake'], 'flat stake is capped by max_exposure');

    // An unknown/absent mode behaves as FLAT — the safe default.
    $default = $sizer->size(0.55, 2.4, ['stake_amount' => 10.0, 'max_exposure' => 100.0]);
    assert_equals('FLAT', $default['mode']);
});

test('flat-percent staking takes the configured percent of bankroll, capped by max_exposure', function () {
    $sizer = new StakeSizer();
    // 1.5% of a 1000 bankroll = 15.00 — the shipped defaults.
    $out = $sizer->size(0.55, 2.4, ['staking_mode' => 'FLAT_PERCENT', 'bankroll' => 1000.0, 'stake_percent' => 1.5, 'max_exposure' => 100.0]);
    assert_equals('FLAT_PERCENT', $out['mode']);
    assert_equals(15.0, $out['stake'], '1.5% of 1000 is 15');
    assert_equals(1.5, (float) $out['detail']['stakePercent'], 'the percent used is stored beside the stake');

    // 2% of 20000 would be 400 — max_exposure still caps the ticket.
    $capped = $sizer->size(0.55, 2.4, ['staking_mode' => 'FLAT_PERCENT', 'bankroll' => 20000.0, 'stake_percent' => 2.0, 'max_exposure' => 100.0]);
    assert_equals(100.0, $capped['stake'], 'max_exposure caps the percent stake');

    // An out-of-band percent (0 or > 5) falls back to the disciplined 1.5.
    $fallback = $sizer->size(0.55, 2.4, ['staking_mode' => 'FLAT_PERCENT', 'bankroll' => 1000.0, 'stake_percent' => 50.0, 'max_exposure' => 1000.0]);
    assert_equals(15.0, $fallback['stake'], 'a reckless percent is never honoured');

    // No bankroll figure → nothing staked, never a guessed amount.
    $none = $sizer->size(0.55, 2.4, ['staking_mode' => 'FLAT_PERCENT', 'bankroll' => 0.0, 'stake_percent' => 1.5, 'max_exposure' => 100.0]);
    assert_equals(0.0, $none['stake'], 'a zero bankroll stakes nothing');
});

test('fractional Kelly stakes the configured fraction of the full-Kelly edge', function () {
    $sizer = new StakeSizer();
    // p=0.55 at 2.4: b=1.4, full Kelly = (1.4*0.55 - 0.45)/1.4 = 0.228571...
    // quarter Kelly on a 1000 bankroll = 57.14 — above the 4×10 flat cap, so 40.
    $out = $sizer->size(0.55, 2.4, [
        'staking_mode' => 'FRACTIONAL_KELLY', 'stake_amount' => 10.0,
        'bankroll' => 1000.0, 'kelly_fraction' => 0.25, 'max_exposure' => 100.0,
    ]);
    assert_equals('FRACTIONAL_KELLY', $out['mode']);
    assert_close(0.228571, (float) $out['detail']['fullKelly'], 0.0001);
    assert_close(57.14, (float) $out['detail']['uncappedStake'], 0.01);
    assert_equals(40.0, $out['stake'], 'the 4x flat-unit cap holds');

    // A wider flat unit lets the true fractional-Kelly figure through.
    $uncapped = $sizer->size(0.55, 2.4, [
        'staking_mode' => 'FRACTIONAL_KELLY', 'stake_amount' => 25.0,
        'bankroll' => 1000.0, 'kelly_fraction' => 0.25, 'max_exposure' => 100.0,
    ]);
    assert_close(57.14, (float) $uncapped['stake'], 0.01, 'inside every cap the Kelly figure is used as-is');

    // max_exposure is still absolute.
    $exposure = $sizer->size(0.55, 2.4, [
        'staking_mode' => 'FRACTIONAL_KELLY', 'stake_amount' => 25.0,
        'bankroll' => 100000.0, 'kelly_fraction' => 0.25, 'max_exposure' => 75.0,
    ]);
    assert_equals(75.0, $exposure['stake'], 'max_exposure caps the Kelly stake');
});

test('a non-positive Kelly edge stakes nothing — never a token minimum bet', function () {
    $sizer = new StakeSizer();
    // p=0.40 at 2.4: b=1.4, full Kelly = (0.56-0.60)/1.4 < 0 → stake 0.
    $out = $sizer->size(0.40, 2.4, [
        'staking_mode' => 'FRACTIONAL_KELLY', 'stake_amount' => 10.0,
        'bankroll' => 1000.0, 'kelly_fraction' => 0.25, 'max_exposure' => 100.0,
    ]);
    assert_equals(0.0, $out['stake']);
    assert_equals('NON_POSITIVE_KELLY_EDGE', $out['detail']['reason']);

    // Un-stakeable inputs are refused, not guessed around.
    foreach ([
        'no odds edge' => [0.55, 1.0],
        'certain win is an artefact' => [1.0, 2.4],
        'certain loss is an artefact' => [0.0, 2.4],
    ] as $label => [$p, $odds]) {
        $bad = $sizer->size($p, $odds, [
            'staking_mode' => 'FRACTIONAL_KELLY', 'stake_amount' => 10.0,
            'bankroll' => 1000.0, 'kelly_fraction' => 0.25, 'max_exposure' => 100.0,
        ]);
        assert_equals(0.0, $bad['stake'], $label . ' stakes nothing');
    }
});

test('configuration validates and persists the staking discipline keys', function () {
    $svc = new ConfigurationService(new SportsRepositoryStub(), fx162_audit());
    $defaults = ConfigurationService::defaults();
    assert_equals('FLAT_PERCENT', $defaults['staking_mode'], 'the shipped default is a flat percent of bankroll');
    assert_equals(1000.0, (float) $defaults['bankroll']);
    assert_equals(1.5, (float) $defaults['stake_percent'], 'the shipped percent sits inside the 1-2% band');
    assert_equals(0.25, (float) $defaults['kelly_fraction'], 'quarter Kelly when an operator opts in');

    $ok = $svc->update(['staking_mode' => 'FRACTIONAL_KELLY', 'bankroll' => 2500.0, 'kelly_fraction' => 0.2], 'admin', 'enable kelly');
    assert_true($ok['ok'], 'a valid Kelly configuration is accepted');
    assert_equals('FRACTIONAL_KELLY', $ok['configuration']['staking_mode']);

    $pct = $svc->update(['staking_mode' => 'FLAT_PERCENT', 'stake_percent' => 2.0], 'admin', 'two percent flat');
    assert_true($pct['ok'], 'a valid flat-percent configuration is accepted');

    assert_false($svc->update(['staking_mode' => 'MARTINGALE'], 'admin', 'x')['ok'], 'unknown staking modes are refused — no chase systems');
    assert_false($svc->update(['bankroll' => 0], 'admin', 'x')['ok'], 'a zero bankroll is refused');
    assert_false($svc->update(['stake_percent' => 0], 'admin', 'x')['ok'], 'a zero percent would silently stake nothing forever');
    assert_false($svc->update(['stake_percent' => 10.0], 'admin', 'x')['ok'], 'beyond the 5% ceiling is refused — bankroll protection');
    assert_false($svc->update(['kelly_fraction' => 0], 'admin', 'x')['ok'], 'a zero fraction would silently stake nothing forever');
    assert_false($svc->update(['kelly_fraction' => 1.5], 'admin', 'x')['ok'], 'beyond full Kelly is refused');
});

test('governance records the sized stake and its full computation trail on the ticket', function () {
    $repo = new SportsRepositoryStub();
    $governance = new TicketGovernance($repo, fx162_audit(), new CorrelationEngine());
    $leg = [
        'matchId' => 7, 'market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'odds' => 2.4,
        'oddsTimestamp' => gmdate('c'), 'value' => ['odds' => 2.4, 'expectedValue' => 0.08],
        'prediction' => ['calibratedProbability' => 0.55],
        'confidence' => ['confidence' => 62.0], 'quality' => ['score' => 88],
        'risk' => ['classification' => 'LOW'],
        'match' => ['homeTeam' => 'H', 'awayTeam' => 'A'],
    ];
    $out = $governance->record(
        ['status' => 'QUALIFIED', 'ticketId' => 'tkt_162kelly', 'totalOdds' => 2.4, 'selections' => [$leg]],
        'v1', null,
        ['staking_mode' => 'FRACTIONAL_KELLY', 'stake_amount' => 10.0, 'bankroll' => 1000.0,
         'kelly_fraction' => 0.25, 'max_exposure' => 100.0]
    );
    assert_equals('PENDING_USER_APPROVAL', $out['status']);
    $ticket = $repo->findTicket('tkt_162kelly');
    assert_true(is_array($ticket), 'the ticket is persisted');
    assert_equals(40.0, (float) $ticket['stake'], 'the Kelly-sized stake (capped at 4x flat) is stored');
    $calc = json_decode((string) $ticket['odds_calculation'], true);
    assert_equals('FRACTIONAL_KELLY', $calc['staking']['mode'], 'the staking mode is stored with the ticket');
    assert_true(isset($calc['staking']['detail']['fullKelly']), 'the computation trail is reconstructable');

    // FLAT mode still stores the plain unit.
    $flat = $governance->record(
        ['status' => 'QUALIFIED', 'ticketId' => 'tkt_162flat', 'totalOdds' => 2.4, 'selections' => [$leg]],
        'v1', null, ['staking_mode' => 'FLAT', 'stake_amount' => 10.0, 'max_exposure' => 100.0]
    );
    assert_equals('PENDING_USER_APPROVAL', $flat['status']);
    assert_equals(10.0, (float) $repo->findTicket('tkt_162flat')['stake'], 'flat mode stores the configured unit');
});
