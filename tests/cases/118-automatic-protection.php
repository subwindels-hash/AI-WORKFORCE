<?php
/**
 * AUTOMATIC KILL SWITCH — engine behaviour (§1–§14).
 *
 * There is no manual switch anywhere in the product, so these cases drive the
 * engine the only way it can be driven: by presenting it with risk conditions
 * and checking that it protects the account by itself, refuses new trades,
 * recovers without help and leaves an audit trail.
 *
 * The engine owns global platform state, so every case snapshots the keys it
 * touches and restores them afterwards — under try/finally, because the test
 * runner catches a failed assertion at the case level and moves on: an
 * un-restored seed (an ACTIVE kill switch, an injected +120 s calendar event)
 * used to leak into whichever case ran next (e.g. 28-portfolio-monitor's
 * paper orders being rejected for 'Nonfarm Payrolls in 2 minute(s)') — one
 * red case cascading into red files that had nothing to do with it.
 */
use AIWorkforce\TradingProtection\AutomaticProtection as AP;
use AIWorkforce\TradingProtection\EconomicCalendar;
use AIWorkforce\TradingProtection\ProtectionPolicy as PP;

/** @return array<string,mixed> full platform state */
function ap_state(): array
{
    return platform()->model->state->load();
}

/** Snapshot only the keys the automatic engine owns. */
function ap_snapshot(): array
{
    $state = ap_state();
    return [
        'protection' => $state[AP::STATE_KEY] ?? null,
        'killSwitch' => $state['killSwitch'] ?? null,
    ];
}

/** Undo everything a case did to policy, status and the order gate. */
function ap_restore(array $snapshot): void
{
    $state = ap_state();
    if ($snapshot['protection'] === null) {
        unset($state[AP::STATE_KEY]);
    } else {
        $state[AP::STATE_KEY] = $snapshot['protection'];
    }
    if ($snapshot['killSwitch'] === null) {
        unset($state['killSwitch']);
    } else {
        $state['killSwitch'] = $snapshot['killSwitch'];
    }
    platform()->model->state->save($state);
    // Drop any calendar injected by the case so later scans use the real feed.
    platform()->protection->setCalendar(new EconomicCalendar(platform()->model->state));
}

/** Seed a status so the next scan is guaranteed to be a transition. */
function ap_seed_status(string $state, ?string $code = null): void
{
    $snapshot = ap_state();
    $snapshot[AP::STATE_KEY]['status'] = [
        'state' => $state, 'reason' => 'seeded by tests', 'code' => $code,
        'since' => gmdate('c'), 'previousState' => null, 'triggers' => [], 'metrics' => [],
        'evaluatedAt' => gmdate('c'), 'evaluatedAtTs' => time(), 'clearScans' => 0, 'unverified' => false,
    ];
    platform()->model->state->save($snapshot);
}

/** Publish a canned economic calendar (no HTTP, no provider). */
function ap_calendar(array $events, bool $configured = true, bool $ok = true, ?string $error = null): EconomicCalendar
{
    $calendar = new EconomicCalendar(platform()->model->state);
    $calendar->store([
        'configured' => $configured, 'ok' => $ok, 'source' => $configured ? 'test feed' : null, 'error' => $error,
        'fetchedAt' => gmdate('c'),
        'events' => array_map(static function (int $offset, string $name): array {
            return ['at' => gmdate('c', time() + $offset), 'atTs' => time() + $offset, 'name' => $name, 'impact' => 'high', 'currency' => 'USD'];
        }, array_keys($events), array_values($events)),
    ]);
    platform()->protection->setCalendar($calendar);
    return $calendar;
}

test('automatic protection: defaults are the recommended safe configuration (§14)', function () {
    $policy = platform()->protection->policy();
    assert_true($policy['enabled'], 'protection is on by default');
    assert_true($policy['news']['enabled'], 'news protection on');
    assert_equals(5, $policy['news']['minutesBefore'], '5 minutes before the event');
    assert_equals(30, $policy['news']['minutesAfter'], '30 minutes after the event');
    assert_equals(0.03, $policy['dailyLoss']['percentLimit'], '3% daily loss limit');
    assert_equals(0.10, $policy['drawdown']['percentLimit'], '10% maximum drawdown');
    assert_true($policy['spread']['enabled'], 'spread protection on');
    assert_true($policy['slippage']['enabled'], 'slippage protection on');
    assert_true($policy['technical']['brokerDisconnect'], 'connection-loss protection on');
    assert_true($policy['technical']['staleData'], 'stale-data protection on');
    assert_true($policy['recovery']['enabled'], 'automatic recovery on');
    assert_true($policy['recovery']['requireAllClear'], 'resume only when every condition is clear');
    assert_false($policy['emergency']['closePositionsOnKill'], 'closing positions is opt-in, never the default');
    assert_false($policy['emergency']['cancelPendingOrdersOnKill'], 'cancelling orders is opt-in');
    assert_false($policy['news']['pauseWhenNoProvider'], 'a fresh install with no calendar warns, it does not freeze');
});

test('automatic protection: admin input is validated and clamped, never widened (§9)', function () {
    $snapshot = ap_snapshot();
    try {
        $policy = platform()->protection->updatePolicy([
            'dailyLoss' => ['percentLimit' => 500, 'fixedLimitUsd' => '250'],   // 500 % → clamped
            'drawdown' => ['percentLimit' => 99],
            'news' => ['minutesBefore' => -20, 'onFeedFailure' => 'nonsense'],
            'unknownSection' => ['nope' => 1],
            'spread' => ['maxPoints' => 30, 'perSymbol' => ['EURUSD' => 12, 'bad' => 'x']],
        ]);
        assert_equals(0.5, $policy['dailyLoss']['percentLimit'], 'percentage clamped to the safe range');
        assert_equals(250.0, $policy['dailyLoss']['fixedLimitUsd'], 'fixed monetary limit accepted');
        assert_equals(0.9, $policy['drawdown']['percentLimit'], 'drawdown clamped');
        assert_equals(0, $policy['news']['minutesBefore'], 'negative minutes clamped to zero');
        assert_equals('pause', $policy['news']['onFeedFailure'], 'an unknown enum falls back to the fail-safe value');
        assert_false(isset($policy['unknownSection']), 'unknown sections are ignored');
        assert_equals(12.0, $policy['spread']['perSymbol']['EURUSD'], 'per-symbol override kept');
        assert_false(isset($policy['spread']['perSymbol']['bad']), 'a non-numeric override is dropped');
        assert_true(in_array('PROTECTION_POLICY_UPDATED', array_column(platform()->model->audit->recent(50), 'type'), true), 'the change is audited');
    } finally {
        ap_restore($snapshot);
    }
});


test('automatic protection: state machine walks NORMAL → PAUSED → KILL → RECOVERY → RESUMED (§7)', function () {
    $policy = PP::DEFAULTS;

    assert_equals(AP::NORMAL, AP::resolveNextState(AP::NORMAL, AP::NORMAL, $policy), 'nothing detected stays NORMAL');
    assert_equals(AP::WARNING, AP::resolveNextState(AP::NORMAL, AP::WARNING, $policy), 'approaching a limit warns');
    assert_equals(AP::PAUSED, AP::resolveNextState(AP::NORMAL, AP::PAUSED, $policy), 'an unsafe condition pauses');
    assert_equals(AP::KILL, AP::resolveNextState(AP::NORMAL, AP::KILL, $policy), 'a critical threshold kills');
    assert_equals(AP::KILL, AP::resolveNextState(AP::KILL, AP::PAUSED, $policy), 'a KILL is never downgraded to a PAUSE');
    assert_equals(AP::KILL, AP::resolveNextState(AP::RECOVERY, AP::KILL, $policy, 1), 'a breach during recovery re-arms protection');

    // Leaving a blocking state is always gated by recovery, never immediate.
    assert_equals(AP::RECOVERY, AP::resolveNextState(AP::PAUSED, AP::NORMAL, $policy, 0), 'conditions clear → recovery');
    assert_equals(AP::RECOVERY, AP::resolveNextState(AP::RECOVERY, AP::NORMAL, $policy, 0), 'first confirmation scan');
    assert_equals(AP::RESUMED, AP::resolveNextState(AP::RECOVERY, AP::NORMAL, $policy, 1), 'second confirmation scan resumes');
    assert_equals(AP::NORMAL, AP::resolveNextState(AP::RESUMED, AP::NORMAL, $policy), 'RESUMED settles to NORMAL');

    // Disabling recovery is an explicit administrative choice: resume at once.
    $noRecovery = $policy;
    $noRecovery['recovery']['enabled'] = false;
    assert_equals(AP::RESUMED, AP::resolveNextState(AP::PAUSED, AP::NORMAL, $noRecovery, 0));
});

test('automatic protection: high-impact news pauses trading and blocks new orders (§1)', function () {
    $snapshot = ap_snapshot();
    try {
        $p = platform();
        $p->setKillSwitch(false, 'test setup');       // internal: released so the engine must re-engage it
        ap_seed_status(AP::NORMAL);

        ap_calendar([180 => 'Nonfarm Payrolls']);     // inside the 5-minute…30-minute window
        $report = $p->protection->evaluate();
        $status = $report['status'];

        assert_true(in_array($status['state'], AP::BLOCKING, true), 'the pause blocks trading', $status['state']);
        assert_true(in_array('NEWS_EVENT', array_column($report['triggers'], 'code'), true), 'the news trigger is reported');
        $trigger = null;
        foreach ($report['triggers'] as $candidate) {
            if ($candidate['code'] === 'NEWS_EVENT') $trigger = $candidate;
        }
        assert_not_null($trigger);
        assert_contains('Nonfarm Payrolls', (string) $trigger['reason'], 'the reason names the event');

        $gate = $p->protection->gate();
        assert_false($gate['allowed'], 'no new trade may open while paused');
        assert_true(!empty($p->state()['killSwitch']['active']), 'the engine engaged the order gate by itself');

        // 8 minutes out: outside the freeze window, inside the warning lead.
        ap_calendar([480 => 'Nonfarm Payrolls']);
        $report = $p->protection->evaluate();
        assert_true(in_array('NEWS_APPROACHING', array_column($report['triggers'], 'code'), true), 'an approaching event warns first');
    } finally {
        ap_restore($snapshot);
    }
});


test('automatic protection: an approaching event warns before it freezes (§1)', function () {
    $snapshot = ap_snapshot();
    try {
        ap_seed_status(AP::NORMAL);
        ap_calendar([600 => 'FOMC Rate Decision']);

        $report = platform()->protection->evaluate();
        assert_equals('NEWS_APPROACHING', $report['status']['code'], '10 minutes out is a warning');
        assert_equals(AP::WARNING, $report['status']['state'], 'WARNING, not a pause');
        assert_true(platform()->protection->gate()['allowed'], 'a warning does not block trading');
    } finally {
        ap_restore($snapshot);
    }
});


test('automatic protection: a broken calendar fails safe, an absent one warns (§12)', function () {
    $snapshot = ap_snapshot();
    try {
        ap_seed_status(AP::NORMAL);

        ap_calendar([], true, false, 'HTTP 500');
        $report = platform()->protection->evaluate();
        assert_true(in_array($status = $report['status']['state'], AP::BLOCKING, true), 'an unreadable calendar pauses trading', $status);
        assert_false(platform()->protection->gate()['allowed'], 'protection never assumes it is safe');

        // With the fail-safe downgraded by an administrator, the same feed only warns.
        platform()->protection->updatePolicy(['news' => ['onFeedFailure' => 'warn']]);
        ap_seed_status(AP::NORMAL);
        ap_calendar([], true, false, 'HTTP 500');
        assert_equals(AP::WARNING, platform()->protection->evaluate()['status']['state'], 'warn mode still reports the problem');
    } finally {
        ap_restore($snapshot);
    }
});


test('automatic protection: unverifiable protection state blocks trading (§12)', function () {
    $snapshot = ap_snapshot();
    try {
        $state = ap_state();
        $status = AP::unverifiedStatus('simulated unverifiable state');
        $status['evaluatedAtTs'] = time();
        $state[AP::STATE_KEY]['status'] = $status;
        platform()->model->state->save($state);

        $gate = platform()->protection->gate();
        assert_false($gate['allowed'], 'when safety cannot be determined the default is to pause new trading');
        assert_equals('PROTECTION_UNVERIFIED', $gate['code']);
    } finally {
        ap_restore($snapshot);
    }
});


test('automatic protection: the admin form speaks percentages, the policy stores fractions (§9)', function () {
    assert_equals(0.03, PP::fromPercent('3'), '3 % is stored as 0.03');
    assert_equals(0.10, PP::fromPercent(10), '10 % is stored as 0.10');
    assert_null(PP::fromPercent(''), 'a blank field means no percentage limit');
    assert_null(PP::fromPercent('abc'), 'garbage is rejected');
    assert_equals(3.0, PP::toPercent(0.03), 'and rendered back as 3 %');

    // An administrator typing "3" must get the 3 % default, not a clamped 50 %.
    $snapshot = ap_snapshot();
    try {
        $policy = platform()->protection->updatePolicy(['dailyLoss' => ['percentLimit' => PP::fromPercent('3')]]);
        assert_equals(0.03, $policy['dailyLoss']['percentLimit'], 'the form value round-trips');
        platform()->protection->updatePolicy(['drawdown' => ['percentLimit' => PP::fromPercent('10')]]);
        assert_equals(0.10, platform()->protection->policy()['drawdown']['percentLimit'], 'drawdown round-trips');
    } finally {
        ap_restore($snapshot);
    }
});


test('automatic protection: point sizes and spread conversion (§5)', function () {
    assert_equals(0.00001, PP::pointSize('EURUSD'), 'a 5-digit FX pair');
    assert_equals(0.001, PP::pointSize('USDJPY'), 'a 3-digit JPY cross');
    assert_equals(0.01, PP::pointSize('XAUUSD'), 'metals');
    assert_equals(0.1, PP::pointSize('US30'), 'indices');
    assert_close(6.0, PP::pointSize('BTCUSDT', 60000.0), 0.0001, 'crypto points are 1 bp of price');

    // 30 points on a 5-digit EURUSD feed is 3 pips — the §5 example.
    $points = PP::toPoints(0.0003, 'EURUSD');
    assert_not_null($points);
    assert_close(30.0, $points, 0.001, '0.0003 on EURUSD is 30 points (3 pips)');
    assert_close(30.0, (float) PP::toPoints(0.30, 'XAUUSD'), 0.001, '$0.30 on gold is 30 points');
    assert_null(PP::toPoints(null, 'EURUSD'), 'an unreadable spread has no point value');

    assert_equals(30.0, PP::maxSpreadPoints(PP::DEFAULTS, 'EURUSD'), 'the global limit applies by default');
    $policy = PP::DEFAULTS;
    $policy['spread']['perSymbol'] = ['EURUSD' => 12.0];
    assert_equals(12.0, PP::maxSpreadPoints($policy, 'EURUSD'), 'a per-symbol override wins');
    assert_equals(30.0, PP::maxSpreadPoints($policy, 'GBPUSD'), 'other symbols keep the global limit');
});

test('automatic protection: calendar normalisation accepts common vendor shapes (§1)', function () {
    $events = EconomicCalendar::extractEvents(['events' => [
        ['at' => '2026-09-04T12:30:00Z', 'name' => 'Nonfarm Payrolls', 'impact' => 'high', 'currency' => 'USD'],
        ['time' => 1788522600, 'title' => 'CPI (YoY)', 'importance' => '3'],
        ['date' => 'garbage', 'name' => 'Unparseable'],
    ]]);
    assert_equals(2, count($events), 'rows without a usable timestamp are dropped');
    assert_equals('Nonfarm Payrolls', $events[0]['name']);
    assert_equals('high', $events[0]['impact']);
    assert_equals('USD', $events[0]['currency']);
    assert_equals('high', $events[1]['impact'], 'importance "3" normalises to high');

    assert_equals(1, count(EconomicCalendar::extractEvents([['at' => '2026-09-04T12:30:00Z', 'name' => 'FOMC', 'impact' => 'red']])), 'a bare array is accepted');
    assert_equals('high', EconomicCalendar::impact('RED'));
    assert_equals('medium', EconomicCalendar::impact('2'));
    assert_equals('low', EconomicCalendar::impact('low'));
});

test('automatic protection: every transition is audited with the full risk picture (§13)', function () {
    $snapshot = ap_snapshot();
    try {
        $p = platform();
        $p->setKillSwitch(false, 'test setup');
        ap_seed_status(AP::NORMAL);                    // guarantees the next scan is a transition
        ap_calendar([120 => 'Nonfarm Payrolls']);

        $status = $p->protection->evaluate()['status'];
        assert_true(in_array($status['state'], AP::BLOCKING, true), 'the scan blocks trading');

        $rows = $p->model->audit->recent(200);
        $types = array_column($rows, 'type');
        assert_true(in_array('AUTOMATIC_PROTECTION_' . $status['state'], $types, true), 'the transition itself is audited');
        assert_true(in_array('KILL_SWITCH_ACTIVATED', $types, true), 'engaging the order gate is audited');

        $row = null;
        foreach ($rows as $candidate) {
            if ($candidate['type'] === 'AUTOMATIC_PROTECTION_' . $status['state']) { $row = $candidate; break; }
        }
        assert_not_null($row);
        $detail = is_array($row['detail']) ? $row['detail'] : [];
        foreach (['from', 'to', 'reason', 'triggers'] as $key) {
            assert_true(array_key_exists($key, $detail), "the audit record carries $key");
        }
        assert_true(isset($detail['metrics']['equity'], $detail['metrics']['dailyPnl'], $detail['metrics']['drawdownPct']), 'the record carries equity, daily P&L and drawdown');
        assert_true(isset($detail['metrics']['openPositions']), 'the record carries open positions');
    } finally {
        ap_restore($snapshot);
    }
});


test('automatic protection: the engine owns the gate — no manual control exists (§8, §11)', function () {
    foreach (['engage', 'release', 'toggle', 'activate', 'deactivate', 'setActive'] as $method) {
        assert_false(method_exists(AP::class, $method), "the engine exposes no $method() — the state is derived, never commanded");
    }
    assert_equals([AP::PAUSED, AP::KILL, AP::RECOVERY], AP::BLOCKING, 'pause, kill and recovery all block new trades');
    assert_false(in_array(AP::WARNING, AP::BLOCKING, true), 'a warning alone never blocks trading');
    assert_false(in_array(AP::NORMAL, AP::BLOCKING, true), 'normal never blocks trading');
});
