<?php
/**
 * AUTOMATIC KILL SWITCH — per-account and per-EA policy overrides (§9).
 *
 * The platform policy is the widest scope, an account narrows it, and a single
 * Expert Advisor narrows it further. These cases pin that chain: who wins,
 * what "inherit" means when the wider scope changes, that an override cannot
 * smuggle in a value the policy layer would reject, and that the numbers which
 * actually governed a decision are reported back to the operator and to the
 * terminal.
 *
 * They run against the EA registry because that is where a terminal account is
 * a first-class concept: an EA reports its account, so the account override can
 * follow it.
 */
use AIWorkforce\TradingProtection\AutomaticProtection as AP;
use AIWorkforce\TradingProtection\EaProtection;
use AIWorkforce\TradingProtection\ProtectionPolicy as PP;

/** Mutable clock so staleness is testable without sleeping. */
function ov_clock(?int $at = null): int
{
    if ($at !== null) $GLOBALS['OV_NOW'] = $at;
    return $GLOBALS['OV_NOW'] ??= 1_810_000_000;
}

function ov_protection(): EaProtection
{
    $protection = platform()->eaProtection;
    $protection->setClock(fn() => ov_clock());
    return $protection;
}

/** Snapshot + clear, so no case inherits another's registry. */
function ov_snapshot(): array
{
    $state = platform()->model->state->load();
    $snapshot = [
        'ea' => $state[EaProtection::STATE_KEY] ?? null,
        'policy' => $state[AP::STATE_KEY]['policy'] ?? null,
        'now' => $GLOBALS['OV_NOW'] ?? null,
    ];
    unset($state[EaProtection::STATE_KEY]);
    $state[AP::STATE_KEY]['policy'] = PP::normalize([]);   // documented defaults
    platform()->model->state->save($state);
    return $snapshot;
}

function ov_restore(array $snapshot): void
{
    $state = platform()->model->state->load();
    if ($snapshot['ea'] === null) unset($state[EaProtection::STATE_KEY]);
    else $state[EaProtection::STATE_KEY] = $snapshot['ea'];
    if ($snapshot['policy'] === null) unset($state[AP::STATE_KEY]['policy']);
    else $state[AP::STATE_KEY]['policy'] = $snapshot['policy'];
    platform()->model->state->save($state);
    $GLOBALS['OV_NOW'] = $snapshot['now'];
}

/** Audit rows emitted after $mark (every case shares one audit log). */
function ov_events_since(int $mark, string $type): array
{
    $rows = array_slice(platform()->model->audit->rows, $mark);
    return array_values(array_filter($rows, fn(array $r): bool => ($r['type'] ?? '') === $type));
}

/** A heartbeat for a deployment on a given account (EA id derived from it). */
function ov_heartbeat(string $account, string $name = 'TradeManager', array $metrics = []): array
{
    return [
        'eaId' => $account . '-EURUSD-900001-' . $name,
        'name' => $name, 'terminal' => 'MT5', 'account' => $account, 'broker' => 'Demo Broker',
        'symbol' => 'EURUSD', 'magic' => 900001, 'at' => gmdate('c'), 'atTs' => ov_clock(),
        'metrics' => array_merge([
            'equity' => 10000.0, 'balance' => 10000.0, 'dailyPnl' => 0.0, 'drawdownPct' => 0.0,
            'peakEquity' => 10000.0, 'openPositions' => 0, 'pendingOrders' => 0,
            'marginLevelPct' => 800.0, 'freeMargin' => 9000.0, 'spreadPoints' => 1.0,
            'slippagePoints' => 0.0, 'orderFailures' => 0, 'symbol' => 'EURUSD',
        ], $metrics),
        'connection' => ['terminal' => true, 'broker' => true, 'dataFeed' => true, 'lastTickAgeSeconds' => 1],
        'news' => ['configured' => false, 'ok' => true, 'minutesToNextHighImpact' => null],
        'actions' => ['closedPositions' => 0, 'cancelledOrders' => 0, 'blockedOrders' => 0],
    ];
}

/** Register a deployment and evaluate it against whatever policy applies. */
function ov_decide(array $heartbeat): array
{
    $protection = ov_protection();
    $ingest = $protection->ingest([$heartbeat]);
    $id = $ingest['ids'][0];
    return $protection->evaluateAll()[$id];
}

// ─── Precedence: platform ← account ← deployment ───────────────────
test('§9 — an account override governs every Expert Advisor on that account', function () {
    $snapshot = ov_snapshot();
    ov_clock(1_810_000_000);
    $protection = ov_protection();

    // Platform default is 3% — a 4% loss normally kills.
    $before = ov_decide(ov_heartbeat('111', 'Alpha', ['dailyPnl' => -400.0]));
    assert_equals(AP::KILL, $before['state'], '4% must breach the platform default of 3%');

    // Widen that account to 6%. The kill is already active, so widening does
    // not silently release it: recovery still needs its confirmation scans
    // (§7) — the state changes, trading resumes on the second clear look.
    $protection->setAccountOverride('mt5:111', ['dailyLoss' => ['percentLimit' => 0.06]]);
    $first = ov_decide(ov_heartbeat('111', 'Alpha', ['dailyPnl' => -400.0]));
    assert_equals(AP::RECOVERY, $first['state'], 'widening the limit starts recovery, it does not release instantly');
    assert_false($first['allowNewTrades'], 'recovery is still blocking');
    assert_equals(6.0, (float) $first['policy']['dailyLossPercent'], 'the decision reports the limit that applied');

    $after = ov_decide(ov_heartbeat('111', 'Alpha', ['dailyPnl' => -400.0]));
    assert_true($after['allowNewTrades'], 'the second clear scan releases the deployment');
    assert_not_equals(AP::KILL, $after['state'], '4% is inside the account override of 6%');

    // A fresh deployment on that account is allowed from its first look.
    $fresh = ov_decide(ov_heartbeat('111', 'Delta', ['dailyPnl' => -400.0]));
    assert_true($fresh['allowNewTrades'], 'a deployment that never breached is allowed immediately');

    // A second EA on the same account inherits it without being configured.
    $sibling = ov_decide(ov_heartbeat('111', 'Beta', ['dailyPnl' => -400.0]));
    assert_not_equals(AP::KILL, $sibling['state'], 'the override follows the account, not the EA');
    assert_equals('mt5:111', $sibling['accountKey'], 'the decision names the account it belongs to');

    // A different account keeps the platform default.
    $other = ov_decide(ov_heartbeat('222', 'Gamma', ['dailyPnl' => -400.0]));
    assert_equals(AP::KILL, $other['state'], 'another account must still use the platform policy');
    assert_equals(3.0, (float) $other['policy']['dailyLossPercent'], 'the platform default is reported for it');
    ov_restore($snapshot);
});

test('§9 — a deployment override beats its account override', function () {
    $snapshot = ov_snapshot();
    ov_clock(1_810_000_000);
    $protection = ov_protection();

    $protection->setAccountOverride('mt5:333', ['dailyLoss' => ['percentLimit' => 0.06]]);
    $relaxed = ov_decide(ov_heartbeat('333', 'Alpha', ['dailyPnl' => -400.0]));
    assert_not_equals(AP::KILL, $relaxed['state'], 'the account widened the limit to 6%');

    // Now tighten just this deployment to 2% — narrower wins.
    $id = '333-EURUSD-900001-Alpha';
    $protection->setPolicyOverride($id, ['dailyLoss' => ['percentLimit' => 0.02]]);
    $tight = ov_decide(ov_heartbeat('333', 'Alpha', ['dailyPnl' => -400.0]));
    assert_equals(AP::KILL, $tight['state'], 'the deployment override is the narrowest scope, so 2% wins');
    assert_equals(2.0, (float) $tight['policy']['dailyLossPercent'], 'the effective limit is the deployment one');

    // …and a sibling on the same account is untouched by that narrowing.
    $sibling = ov_decide(ov_heartbeat('333', 'Beta', ['dailyPnl' => -400.0]));
    assert_not_equals(AP::KILL, $sibling['state'], 'the sibling keeps the account override');
    assert_equals(6.0, (float) $sibling['policy']['dailyLossPercent'], 'the sibling still reports 6%');
    ov_restore($snapshot);
});

// ─── "Inherit" really means inherit ───────────────────────────────
test('§9 — only the fields an override sets are pinned; the rest keep tracking the platform', function () {
    $snapshot = ov_snapshot();
    ov_clock(1_810_000_000);
    $protection = ov_protection();

    // Pin only the drawdown. Daily loss and spread must keep following the
    // platform policy, including future changes to it.
    $saved = $protection->setAccountOverride('mt5:444', ['drawdown' => ['percentLimit' => 0.20]]);
    assert_equals(['drawdown' => ['percentLimit' => 0.2]], $saved, 'only the submitted path is stored');

    $state = platform()->model->state->load();
    $state[AP::STATE_KEY]['policy'] = PP::normalize(['dailyLoss' => ['percentLimit' => 0.01]]);
    platform()->model->state->save($state);

    $decision = ov_decide(ov_heartbeat('444', 'Alpha', ['dailyPnl' => -150.0]));
    assert_equals(AP::KILL, $decision['state'], 'the platform policy change to 1% reached the account');
    assert_equals(1.0, (float) $decision['policy']['dailyLossPercent'], 'daily loss tracks the platform');
    assert_equals(20.0, (float) $decision['policy']['drawdownPercent'], 'drawdown stays pinned at 20%');
    ov_restore($snapshot);
});

test('§9 — an empty override clears it and the account inherits the platform again', function () {
    $snapshot = ov_snapshot();
    ov_clock(1_810_000_000);
    $protection = ov_protection();

    $protection->setAccountOverride('mt5:555', ['dailyLoss' => ['percentLimit' => 0.20]]);
    assert_equals(1, count($protection->accountOverrides()), 'the override is stored');

    $cleared = $protection->setAccountOverride('mt5:555', []);
    assert_equals([], $cleared, 'an empty patch clears the override');
    assert_equals([], $protection->accountOverrides(), 'nothing is left behind');

    $decision = ov_decide(ov_heartbeat('555', 'Alpha', ['dailyPnl' => -400.0]));
    assert_equals(AP::KILL, $decision['state'], 'the platform default of 3% applies again');
    assert_equals(3.0, (float) $decision['policy']['dailyLossPercent'], 'the platform value is reported again');

    // removeAccountOverride() is the explicit route and behaves the same.
    $protection->setAccountOverride('mt5:555', ['dailyLoss' => ['percentLimit' => 0.20]]);
    $protection->removeAccountOverride('mt5:555');
    assert_equals([], $protection->accountOverrides(), 'removeAccountOverride drops it too');
    ov_restore($snapshot);
});

// ─── Overrides cannot bypass validation ───────────────────────────
test('§9 — an override is validated and clamped exactly like the platform form', function () {
    $snapshot = ov_snapshot();
    ov_clock(1_810_000_000);
    $protection = ov_protection();

    $saved = $protection->setAccountOverride('mt5:666', [
        'dailyLoss' => ['percentLimit' => 999.0, 'fixedLimitUsd' => 250.0],
        'nonsense' => ['x' => 1],
        'spread' => ['maxPoints' => 12.0],
    ]);
    assert_equals(0.5, (float) $saved['dailyLoss']['percentLimit'], 'an absurd percentage is clamped to the policy maximum');
    assert_equals(250.0, (float) $saved['dailyLoss']['fixedLimitUsd'], 'a valid value survives');
    assert_false(isset($saved['nonsense']), 'unknown keys are dropped');
    assert_equals(12.0, (float) $saved['spread']['maxPoints'], 'a second section is stored too');

    // The percentage boundary is the one that bit the main form: the override
    // speaks fractions internally and percentages to the operator.
    $percent = $protection->setAccountOverride('mt5:667', ['drawdown' => ['percentLimit' => 0.25]]);
    assert_equals(0.25, (float) $percent['drawdown']['percentLimit'], '0.25 is stored as a fraction');
    $decision = ov_decide(ov_heartbeat('667', 'Alpha', ['drawdownPct' => 24.0]));
    assert_not_equals(AP::KILL, $decision['state'], '24% is inside a 25% limit');
    assert_equals(25.0, (float) $decision['policy']['drawdownPercent'], 'and it is reported back as 25%');

    assert_throws(InvalidArgumentException::class, fn() => $protection->setAccountOverride('', ['dailyLoss' => ['percentLimit' => 0.1]]), 'an empty account key must be rejected');
    assert_throws(InvalidArgumentException::class, fn() => $protection->setPolicyOverride('no-such-ea', ['dailyLoss' => ['percentLimit' => 0.1]]), 'an unknown deployment must be rejected');
    ov_restore($snapshot);
});

// ─── The terminal is told the numbers that govern it ───────────────
test('§9 — the decision carries the effective policy, so the terminal obeys the same numbers', function () {
    $snapshot = ov_snapshot();
    ov_clock(1_810_000_000);
    $protection = ov_protection();

    $id = '777-EURUSD-900001-Alpha';
    $protection->setAccountOverride('mt5:777', [
        'dailyLoss' => ['percentLimit' => 0.015, 'fixedLimitUsd' => 400.0],
        'drawdown' => ['percentLimit' => 0.05],
        'spread' => ['maxPoints' => 12.0],
        'slippage' => ['maxPoints' => 4.0],
        'news' => ['enabled' => false],
        'emergency' => ['closePositionsOnKill' => true, 'cancelPendingOrdersOnKill' => true],
    ]);

    $decision = ov_decide(ov_heartbeat('777', 'Alpha'));
    $policy = $decision['policy'];
    assert_equals(1.5, (float) $policy['dailyLossPercent'], 'percentages are converted for the terminal');
    assert_equals(400.0, (float) $policy['dailyLossFixedUsd'], 'the fixed limit travels too');
    assert_equals(5.0, (float) $policy['drawdownPercent'], 'drawdown is reported as a percentage');
    assert_equals(12.0, (float) $policy['maxSpreadPoints'], 'spread in points');
    assert_equals(4.0, (float) $policy['maxSlippagePoints'], 'slippage in points');
    assert_false($policy['newsEnabled'], 'news protection switched off for this account');
    assert_true($policy['closePositionsOnKill'], 'the emergency policy reaches the terminal as well');
    assert_true($policy['cancelPendingOrdersOnKill'], 'so does cancelling pending orders');
    ov_restore($snapshot);
});

test('§9 — an override applies to the deployed threshold, not only to the report', function () {
    $snapshot = ov_snapshot();
    ov_clock(1_810_000_000);
    $protection = ov_protection();

    // Spread: 20 points is inside the platform default of 30…
    $ok = ov_decide(ov_heartbeat('888', 'Alpha', ['spreadPoints' => 20.0]));
    assert_not_equals(AP::PAUSED, $ok['state'], '20 points is inside the default 30 point limit');

    // …and outside an account that trades a tighter book.
    $protection->setAccountOverride('mt5:888', ['spread' => ['maxPoints' => 10.0]]);
    $paused = ov_decide(ov_heartbeat('888', 'Alpha', ['spreadPoints' => 20.0]));
    assert_equals(AP::PAUSED, $paused['state'], 'the tighter account limit must actually block');
    assert_equals('EA_SPREAD_EXCEEDED', $paused['code'], 'for the spread reason');
    assert_false($paused['allowNewTrades'], 'and it blocks new trades');

    // Drawdown, the other way round: an account with more room.
    $protection->setAccountOverride('mt5:999', ['drawdown' => ['percentLimit' => 0.30]]);
    $wide = ov_decide(ov_heartbeat('999', 'Alpha', ['drawdownPct' => 12.0]));
    assert_not_equals(AP::KILL, $wide['state'], '12% is inside a 30% account limit');
    $narrow = ov_decide(ov_heartbeat('111000', 'Alpha', ['drawdownPct' => 12.0]));
    assert_equals(AP::KILL, $narrow['state'], 'the platform default of 10% still kills another account');
    ov_restore($snapshot);
});

// ─── Operator surface and audit ───────────────────────────────────
test('§9 — overrides are visible to operators and audited with their previous value', function () {
    $snapshot = ov_snapshot();
    ov_clock(1_810_000_000);
    $protection = ov_protection();
    $mark = count(platform()->model->audit->rows);
    $before = $mark;

    $protection->setAccountOverride('mt5:1234', ['dailyLoss' => ['percentLimit' => 0.02]]);
    $protection->ingest([ov_heartbeat('1234', 'Alpha')]);
    $protection->evaluateAll();

    $status = $protection->status();
    assert_equals(1, count($status['accounts']), 'the account override is listed');
    assert_equals('mt5:1234', $status['accounts'][0]['key'], 'with its key');
    assert_true(in_array('1234-EURUSD-900001-Alpha', (array) $status['accounts'][0]['deployments'], true), 'and the deployments it governs');

    $row = $status['deployments'][0];
    assert_equals('mt5:1234', $row['accountKey'], 'a deployment reports its account key');
    assert_equals(2.0, (float) $row['policy']['dailyLossPercent'], 'and the policy that governs it');
    assert_equals([], $row['policyOverride'], 'this deployment has no override of its own');

    $events = ov_events_since($mark, 'EA_ACCOUNT_POLICY_OVERRIDE');
    assert_equals(1, count($events), 'the override is audited once');
    assert_equals('mt5:1234', $events[0]['detail']['accountKey'] ?? null, 'with the account key');
    assert_equals(0.02, (float) ($events[0]['detail']['override']['dailyLoss']['percentLimit'] ?? 0), 'and the new value');
    assert_true(isset($events[0]['detail']['previous']), 'the previous override is kept for the trail');
    assert_equals('system', $events[0]['actor'] ?? null, 'the actor is the system');
    assert_true(count(platform()->model->audit->rows) > $before, 'audit rows were written');

    $protection->removeAccountOverride('mt5:1234');
    $reset = ov_events_since($mark, 'EA_ACCOUNT_POLICY_RESET');
    assert_equals(1, count($reset), 'removing it is audited too');
    ov_restore($snapshot);
});

test('§9 — a deployment override is audited and reported back on the deployment', function () {
    $snapshot = ov_snapshot();
    ov_clock(1_810_000_000);
    $protection = ov_protection();
    $mark = count(platform()->model->audit->rows);
    $id = '4321-EURUSD-900001-Alpha';
    $protection->ingest([ov_heartbeat('4321', 'Alpha')]);

    $saved = $protection->setPolicyOverride($id, ['slippage' => ['maxPoints' => 3.0]]);
    assert_equals(3.0, (float) $saved['slippage']['maxPoints'], 'the override is stored');

    $row = $protection->status()['deployments'][0];
    assert_equals(3.0, (float) ($row['policyOverride']['slippage']['maxPoints'] ?? 0), 'and shown on the deployment');
    assert_equals(3.0, (float) $row['policy']['maxSlippagePoints'], 'the effective policy reflects it');

    $events = ov_events_since($mark, 'EA_PROTECTION_POLICY_OVERRIDE');
    assert_equals(1, count($events), 'the override is audited');
    assert_equals($id, $events[0]['detail']['eaId'] ?? null, 'against the deployment');

    // Clearing it returns the deployment to the platform value.
    $protection->setPolicyOverride($id, []);
    $row = $protection->status()['deployments'][0];
    assert_equals([], $row['policyOverride'], 'the override is gone');
    assert_equals(10.0, (float) $row['policy']['maxSlippagePoints'], 'the platform default of 10 points applies again');
    ov_restore($snapshot);
});

test('§9 — the account key is derived from the terminal and the account number', function () {
    $snapshot = ov_snapshot();
    $protection = ov_protection();
    assert_equals('mt5:5123456', $protection->accountKeyFor(['terminal' => 'MT5', 'account' => '5123456']), 'MT5 accounts');
    assert_equals('mt4:99', $protection->accountKeyFor(['terminal' => 'mt4', 'account' => '99']), 'MT4 accounts are normalised');
    assert_equals('', $protection->accountKeyFor(['terminal' => 'MT5', 'account' => '']), 'no account means no account scope');
    assert_equals('mt5:7', $protection->accountKeyFor(['account' => '7']), 'the terminal defaults to MT5');

    // Odd characters cannot break the key out of its scope.
    $saved = $protection->setAccountOverride('MT5: 5123456 | drop', ['dailyLoss' => ['percentLimit' => 0.04]]);
    assert_equals(0.04, (float) $saved['dailyLoss']['percentLimit'], 'the override is stored');
    $keys = array_keys($protection->accountOverrides());
    assert_equals(1, count($keys), 'exactly one key is created');
    $key = (string) $keys[0];
    assert_equals(strtolower($key), $key, 'the key is lower-cased');
    assert_false(str_contains($key, ' '), 'spaces are stripped');
    assert_false(str_contains($key, '|'), 'separators are stripped');
    assert_true(str_starts_with($key, 'mt5:5123456'), 'the terminal and account survive sanitisation');
    ov_restore($snapshot);
});
