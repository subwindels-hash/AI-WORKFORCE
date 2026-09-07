<?php
/**
 * AUTOMATIC KILL SWITCH — MT4/MT5 / Expert Advisor integration (§10).
 *
 * An EA runs on a Windows host inside the terminal, so protection has to reach
 * it through a heartbeat/decision contract: the EA reports what it sees, the
 * platform answers with the decision it must obey, and the EA enforces the same
 * rules locally when the platform is unreachable.
 *
 * These cases drive the platform half of that contract with realistic
 * heartbeats. They assert that every condition in §10's list is evaluated, that
 * the decision blocks new trades, that recovery needs confirmation scans
 * (§7) and that a terminal that stops reporting is paused rather than trusted
 * (§12).
 */
use AIWorkforce\TradingProtection\AutomaticProtection as AP;
use AIWorkforce\TradingProtection\EaBridgeClient;
use AIWorkforce\TradingProtection\EaProtection;
use AIWorkforce\TradingProtection\ProtectionPolicy as PP;

/** Mutable clock, so "the EA stopped reporting" is testable without sleeping. */
function ea_clock(?int $at = null): int
{
    if ($at !== null) $GLOBALS['EA_NOW'] = $at;
    return $GLOBALS['EA_NOW'] ??= 1_800_000_000;
}

function ea_protection(): EaProtection
{
    $protection = platform()->eaProtection;
    $protection->setClock(fn() => ea_clock());
    return $protection;
}

/**
 * Snapshot, then empty the registry so no test can inherit another's
 * deployments (a failed assertion must not leak into the next case).
 */
function ea_snapshot(): array
{
    $state = platform()->model->state->load();
    $snapshot = ['ea' => $state[EaProtection::STATE_KEY] ?? null, 'now' => $GLOBALS['EA_NOW'] ?? null];
    unset($state[EaProtection::STATE_KEY]);
    platform()->model->state->save($state);
    return $snapshot;
}

function ea_restore(array $snapshot): void
{
    $state = platform()->model->state->load();
    if ($snapshot['ea'] === null) unset($state[EaProtection::STATE_KEY]);
    else $state[EaProtection::STATE_KEY] = $snapshot['ea'];
    platform()->model->state->save($state);
    $GLOBALS['EA_NOW'] = $snapshot['now'];
}

/**
 * Install a policy built from the documented defaults plus the case's
 * overrides, so a case never inherits another case's thresholds.
 */
function ea_policy(array $overrides = []): array
{
    $policy = PP::normalize($overrides);
    $state = platform()->model->state->load();
    $state[AP::STATE_KEY]['policy'] = $policy;
    platform()->model->state->save($state);
    return $policy;
}

/** A healthy terminal heartbeat; cases override the fields they care about. */
function ea_heartbeat(string $id = 'ea-trade-manager', array $metrics = [], array $connection = [], array $news = []): array
{
    return [
        'eaId' => $id,
        'name' => 'Trade Manager',
        'terminal' => 'MT5',
        'account' => '5123456',
        'broker' => 'Demo Broker Ltd',
        'symbol' => 'EURUSD',
        'magic' => 900001,
        'version' => '1.0.0',
        'at' => gmdate('c'),
        'atTs' => ea_clock(),
        'metrics' => array_merge([
            'equity' => 10000.0, 'balance' => 10000.0, 'dailyPnl' => 0.0, 'drawdownPct' => 0.0,
            'peakEquity' => 10000.0, 'openPositions' => 0, 'pendingOrders' => 0,
            'marginLevelPct' => 800.0, 'freeMargin' => 9000.0,
            'spreadPoints' => 1.2, 'slippagePoints' => 0.0, 'orderFailures' => 0, 'symbol' => 'EURUSD',
        ], $metrics),
        'connection' => array_merge(['terminal' => true, 'broker' => true, 'dataFeed' => true, 'lastTickAgeSeconds' => 1], $connection),
        'news' => array_merge(['configured' => true, 'ok' => true, 'minutesToNextHighImpact' => null], $news),
        'actions' => ['closedPositions' => 0, 'cancelledOrders' => 0, 'blockedOrders' => 0],
    ];
}

/** Ingest a heartbeat and return the published decision. */
function ea_decide(array $heartbeat, array $overrides = []): array
{
    ea_clock();
    $policy = ea_policy($overrides);
    $protection = ea_protection();
    $ingest = $protection->ingest([$heartbeat]);
    $id = $ingest['ids'][0] ?? $heartbeat['eaId'];
    return $protection->evaluateOne($id, $protection->deployments()[$id] ?? [], $policy);
}

// ─── 1 — registration and the clean path ───────────────────────────
test('§10 — the first heartbeat registers the deployment and allows trading when every condition is clear', function () {
    $snapshot = ea_snapshot();
    ea_clock(1_800_000_000);
    $protection = ea_protection();
    $before = $protection->status();

    $ingest = $protection->ingest([ea_heartbeat()]);
    assert_equals([ 'ea-trade-manager' ], $ingest['registered'], 'an unknown EA must be registered on its first heartbeat');
    assert_equals(1, $ingest['accepted'], 'the heartbeat must be accepted');

    $decision = $protection->evaluateAll()['ea-trade-manager'];
    // A brand-new deployment has no verified history; a clean first look must
    // not be gated by confirmation scans (it would refuse the first minute).
    assert_equals(AP::RESUMED, $decision['state'], 'a clean first evaluation must resume, not wait for scans');
    assert_true($decision['allowNewTrades'], 'a clear terminal must be allowed to trade');
    assert_equals(0, $before['total'], 'the registry started empty for this fixture');

    $status = $protection->status();
    assert_equals(1, $status['total'], 'the deployment must be visible to operators');
    assert_equals(0, $status['blocked'], 'a clear deployment must not be counted as blocked');
    assert_equals('5123456', $status['deployments'][0]['account'], 'the reported account must be shown');
    assert_equals('MT5', $status['deployments'][0]['terminal'], 'the terminal must be shown');
    ea_restore($snapshot);
});

// ─── 14 / §12 — fail-safe when the terminal stops reporting ─────────
test('§12 — an EA that stops reporting is paused, never assumed safe', function () {
    $snapshot = ea_snapshot();
    ea_clock(1_800_000_000);
    $protection = ea_protection();
    $protection->ingest([ea_heartbeat()]);
    assert_true($protection->evaluateAll()['ea-trade-manager']['allowNewTrades'], 'fresh heartbeat → allowed');

    ea_clock(1_800_000_000 + 500); // 500 s later, past the 120 s default timeout
    $decision = $protection->evaluateAll()['ea-trade-manager'];
    assert_equals(AP::PAUSED, $decision['state'], 'a stale heartbeat must pause the deployment');
    assert_false($decision['allowNewTrades'], 'a stale heartbeat must block new trades');
    assert_equals('EA_HEARTBEAT_STALE', $decision['code'], 'the reason must name the stale heartbeat');
    assert_equals(500, $decision['heartbeatAgeSeconds'], 'the age must be reported for operators');
    assert_contains('paused', (string) $decision['reason'], 'the reason must say what happened');
    ea_restore($snapshot);
});

test('§12 — a deployment that has never reported is paused from the start', function () {
    $snapshot = ea_snapshot();
    ea_clock(1_800_000_000);
    $protection = ea_protection();
    $protection->register(['eaId' => 'ea-silent', 'name' => 'Silent EA', 'terminal' => 'MT4', 'account' => '999']);
    $decision = $protection->evaluateAll()['ea-silent'];
    assert_equals(AP::PAUSED, $decision['state'], 'no heartbeat at all must pause');
    assert_false($decision['allowNewTrades'], 'no heartbeat at all must block new trades');
    assert_equals('EA_HEARTBEAT_STALE', $decision['code'], 'the reason must name the missing heartbeat');
    ea_restore($snapshot);
});

// ─── 9 / 10 — daily loss, percentage AND fixed amount (§2) ──────────
test('§2 — daily loss protection fires on the percentage limit and on the fixed amount', function () {
    $snapshot = ea_snapshot();
    $policy = ['dailyLoss' => ['enabled' => true, 'percentLimit' => 0.03, 'fixedLimitUsd' => null]];

    // 3.5% of 10,000 equity — over the 3% default.
    $percent = ea_decide(ea_heartbeat('ea-loss-pct', ['dailyPnl' => -350.0]), $policy);
    assert_equals(AP::KILL, $percent['state'], 'a 3.5% daily loss must kill');
    assert_equals('EA_DAILY_LOSS_LIMIT', $percent['code'], 'the reason must name the daily loss limit');
    assert_false($percent['allowNewTrades'], 'the kill must block new trades');
    assert_contains('3.50%', (string) $percent['reason'], 'the reason must carry the measured loss');

    // Under the same limit — no kill.
    $ok = ea_decide(ea_heartbeat('ea-loss-ok', ['dailyPnl' => -100.0]), $policy);
    assert_not_equals(AP::KILL, $ok['state'], '1% must be inside the 3% limit');

    // Fixed amount only: 3% is not breached, but $120 exceeds a $100 limit.
    $fixed = ea_decide(ea_heartbeat('ea-loss-fixed', ['dailyPnl' => -120.0]), [
        'dailyLoss' => ['enabled' => true, 'percentLimit' => 0.50, 'fixedLimitUsd' => 100.0],
    ]);
    assert_equals(AP::KILL, $fixed['state'], 'a fixed $100 limit must be enforceable on its own');
    assert_equals('EA_DAILY_LOSS_LIMIT', $fixed['code'], 'the fixed breach must be reported as a daily-loss kill');
    assert_contains('$100.00', (string) $fixed['reason'], 'the reason must name the fixed limit');

    // A profit is never a loss, however small the equity.
    $profit = ea_decide(ea_heartbeat('ea-profit', ['dailyPnl' => 250.0]), $policy);
    assert_not_equals(AP::KILL, $profit['state'], 'a profitable day must never trigger the loss limit');
    ea_restore($snapshot);
});

test('§2 — approaching the daily loss limit warns without blocking', function () {
    $snapshot = ea_snapshot();
    $decision = ea_decide(ea_heartbeat('ea-warn', ['dailyPnl' => -250.0]), [
        'dailyLoss' => ['enabled' => true, 'percentLimit' => 0.03, 'warnAtFraction' => 0.8],
    ]);
    assert_equals(AP::WARNING, $decision['state'], '2.5% of a 3% limit must warn');
    assert_true($decision['allowNewTrades'], 'a warning must not stop trading');
    assert_equals('EA_DAILY_LOSS_APPROACHING', $decision['code'], 'the warning must be labelled');
    ea_restore($snapshot);
});

// ─── 11 — maximum drawdown (§3) ────────────────────────────────────
test('§3 — maximum drawdown kills and approaching it warns', function () {
    $snapshot = ea_snapshot();
    $kill = ea_decide(ea_heartbeat('ea-dd', ['drawdownPct' => 12.5]), ['drawdown' => ['enabled' => true, 'percentLimit' => 0.10]]);
    assert_equals(AP::KILL, $kill['state'], '12.5% drawdown must breach the 10% limit');
    assert_equals('EA_MAX_DRAWDOWN', $kill['code'], 'the reason must name the drawdown limit');

    $warn = ea_decide(ea_heartbeat('ea-dd-warn', ['drawdownPct' => 8.5]), [
        'drawdown' => ['enabled' => true, 'percentLimit' => 0.10, 'warnAtFraction' => 0.8],
    ]);
    assert_equals(AP::WARNING, $warn['state'], '85% of the limit must warn');
    assert_true($warn['allowNewTrades'], 'a warning must not stop trading');

    $ok = ea_decide(ea_heartbeat('ea-dd-ok', ['drawdownPct' => 2.0]), ['drawdown' => ['enabled' => true, 'percentLimit' => 0.10]]);
    assert_not_equals(AP::KILL, $ok['state'], '2% drawdown must be inside the limit');
    ea_restore($snapshot);
});

// ─── 6 / 7 — spread and slippage (§5, §6) ──────────────────────────
test('§5 — a spread above the configured point limit pauses new trades', function () {
    $snapshot = ea_snapshot();
    $paused = ea_decide(ea_heartbeat('ea-spread', ['spreadPoints' => 45.0]), ['spread' => ['enabled' => true, 'maxPoints' => 30.0]]);
    assert_equals(AP::PAUSED, $paused['state'], '45 points must breach a 30 point spread limit');
    assert_equals('EA_SPREAD_EXCEEDED', $paused['code'], 'the reason must name the spread');
    assert_false($paused['allowNewTrades'], 'the pause must block new trades');
    assert_contains('30.0 point', (string) $paused['reason'], 'the reason must carry the limit');

    $ok = ea_decide(ea_heartbeat('ea-spread-ok', ['spreadPoints' => 12.0]), ['spread' => ['enabled' => true, 'maxPoints' => 30.0]]);
    assert_not_equals(AP::PAUSED, $ok['state'], '12 points must be inside the limit');

    // 30 points is 3 pips on a 5-digit EURUSD feed, not 30 pips.
    assert_close(0.00001, PP::pointSize('EURUSD'), 0.0000001, 'the point convention must match MT4/MT5');
    ea_restore($snapshot);
});

test('§5 — a per-symbol override applies to the EA that trades that symbol', function () {
    $snapshot = ea_snapshot();
    $policy = ['spread' => ['enabled' => true, 'maxPoints' => 30.0, 'perSymbol' => ['XAUUSD' => 60.0]]];
    $goldOk = ea_decide(ea_heartbeat('ea-gold', ['symbol' => 'XAUUSD', 'spreadPoints' => 45.0]), $policy);
    assert_not_equals(AP::PAUSED, $goldOk['state'], '45 points on XAUUSD is inside its 60 point override');

    $fxPaused = ea_decide(ea_heartbeat('ea-fx', ['symbol' => 'EURUSD', 'spreadPoints' => 45.0]), $policy);
    assert_equals(AP::PAUSED, $fxPaused['state'], 'the same spread on EURUSD must still breach the 30 point default');
    ea_restore($snapshot);
});

test('§6 — slippage above the configured limit pauses new trades', function () {
    $snapshot = ea_snapshot();
    $paused = ea_decide(ea_heartbeat('ea-slip', ['slippagePoints' => 18.0]), ['slippage' => ['enabled' => true, 'maxPoints' => 10.0]]);
    assert_equals(AP::PAUSED, $paused['state'], '18 points of slippage must breach a 10 point limit');
    assert_equals('EA_SLIPPAGE_EXCEEDED', $paused['code'], 'the reason must name the slippage');

    $ok = ea_decide(ea_heartbeat('ea-slip-ok', ['slippagePoints' => 4.0]), ['slippage' => ['enabled' => true, 'maxPoints' => 10.0]]);
    assert_not_equals(AP::PAUSED, $ok['state'], '4 points must be inside the limit');
    ea_restore($snapshot);
});

// ─── 1–5, 8 — technical conditions (§4) ────────────────────────────
test('§4 — terminal, broker and data-feed outages pause; escalation can make them a kill', function () {
    $snapshot = ea_snapshot();
    $base = ['technical' => ['brokerDisconnect' => true, 'staleData' => true, 'escalateToKill' => false]];

    $terminal = ea_decide(ea_heartbeat('ea-term', [], ['terminal' => false]), $base);
    assert_equals(AP::PAUSED, $terminal['state'], 'a disconnected terminal must pause');
    assert_equals('EA_TERMINAL_DISCONNECTED', $terminal['code'], 'the reason must name the terminal');

    $broker = ea_decide(ea_heartbeat('ea-broker', [], ['broker' => false]), $base);
    assert_equals('EA_BROKER_DISCONNECTED', $broker['code'], 'a lost trade server must pause');

    $feed = ea_decide(ea_heartbeat('ea-feed', [], ['dataFeed' => false]), $base);
    assert_equals('EA_DATA_FEED_UNAVAILABLE', $feed['code'], 'a dead market-data feed must pause');

    $stale = ea_decide(ea_heartbeat('ea-stale', [], ['lastTickAgeSeconds' => 240]), $base);
    assert_equals('EA_QUOTE_STALE', $stale['code'], 'a stale quote must pause');

    $abnormal = ea_decide(ea_heartbeat('ea-price', ['priceMovePercent' => 9.4]), $base);
    assert_equals('EA_ABNORMAL_PRICE', $abnormal['code'], 'an abnormal price move must pause');

    $failures = ea_decide(ea_heartbeat('ea-fails', ['orderFailures' => 3]), $base);
    assert_equals('EA_ORDER_FAILURES', $failures['code'], 'repeated order failures must pause');

    // §4 lets an administrator treat an infrastructure outage as critical.
    $escalated = ea_decide(ea_heartbeat('ea-kill', [], ['broker' => false]), [
        'technical' => ['brokerDisconnect' => true, 'escalateToKill' => true],
    ]);
    assert_equals(AP::KILL, $escalated['state'], 'escalateToKill must turn an outage into an automatic kill');
    assert_false($escalated['allowNewTrades'], 'the kill must block new trades');
    ea_restore($snapshot);
});

test('§4 — a low margin level pauses before the account reaches stop-out', function () {
    $snapshot = ea_snapshot();
    ea_clock(1_800_000_000);
    $protection = ea_protection();
    $ingest = $protection->ingest([ea_heartbeat('ea-margin', ['marginLevelPct' => 120.0])]);
    $id = $ingest['ids'][0];
    $protection->setOverride($id, ['marginLevelFloorPercent' => 150.0]);
    $decision = $protection->evaluateOne($id, $protection->deployments()[$id], ea_policy());
    assert_equals(AP::PAUSED, $decision['state'], 'a margin level under the floor must pause');
    assert_equals('EA_MARGIN_LEVEL_LOW', $decision['code'], 'the reason must name the margin level');
    ea_restore($snapshot);
});

// ─── 13 — news window (§1) ─────────────────────────────────────────
test('§1 — high-impact news pauses before the event and holds after it', function () {
    $snapshot = ea_snapshot();
    $policy = ['news' => ['enabled' => true, 'minutesBefore' => 5, 'minutesAfter' => 30, 'warningLeadMinutes' => 15]];

    $before = ea_decide(ea_heartbeat('ea-news-in', [], [], ['minutesToNextHighImpact' => 3]), $policy);
    assert_equals(AP::PAUSED, $before['state'], 'an event 3 minutes away must pause');
    assert_equals('EA_NEWS_EVENT', $before['code'], 'the reason must name the news event');
    assert_false($before['allowNewTrades'], 'the news pause must block new trades');
    assert_contains('3 minute', (string) $before['reason'], 'the reason must carry the countdown');

    $after = ea_decide(ea_heartbeat('ea-news-out', [], [], ['minutesToNextHighImpact' => -12]), $policy);
    assert_equals(AP::PAUSED, $after['state'], 'an event 12 minutes ago must still hold (30 minute aftermath)');

    $warned = ea_decide(ea_heartbeat('ea-news-soon', [], [], ['minutesToNextHighImpact' => 14]), $policy);
    assert_equals(AP::WARNING, $warned['state'], 'an event 14 minutes away must warn');
    assert_true($warned['allowNewTrades'], 'the warning itself must not block trading');

    $clear = ea_decide(ea_heartbeat('ea-news-clear', [], [], ['minutesToNextHighImpact' => 90]), $policy);
    assert_not_equals(AP::PAUSED, $clear['state'], 'no event nearby must not pause');
    ea_restore($snapshot);
});

test('§12 — a calendar the terminal cannot read pauses trading, it is not assumed clear', function () {
    $snapshot = ea_snapshot();
    $paused = ea_decide(ea_heartbeat('ea-news-down', [], [], ['ok' => false]), ['news' => ['enabled' => true, 'onFeedFailure' => 'pause']]);
    assert_equals(AP::PAUSED, $paused['state'], 'a broken calendar must pause (fail-safe)');
    assert_equals('EA_NEWS_FEED_UNAVAILABLE', $paused['code'], 'the reason must name the unreadable calendar');

    $warned = ea_decide(ea_heartbeat('ea-news-warn', [], [], ['ok' => false]), ['news' => ['enabled' => true, 'onFeedFailure' => 'warn']]);
    assert_equals(AP::WARNING, $warned['state'], 'with onFeedFailure=warn the operator is told without a pause');
    assert_true($warned['allowNewTrades'], 'warn mode must not block trading');
    ea_restore($snapshot);
});

// ─── §7 — state machine: pause, recovery scans, resume ─────────────
test('§7 — recovery takes consecutive clear scans and then resumes by itself', function () {
    $snapshot = ea_snapshot();
    ea_clock(1_800_000_000);
    $protection = ea_protection();
    $protection->ingest([ea_heartbeat('ea-lifecycle', ['spreadPoints' => 45.0])]);
    $policy = ea_policy(['spread' => ['enabled' => true, 'maxPoints' => 30.0], 'recovery' => ['enabled' => true, 'consecutiveClearScans' => 2]]);

    $paused = $protection->evaluateOne('ea-lifecycle', $protection->deployments()['ea-lifecycle'], $policy);
    assert_equals(AP::PAUSED, $paused['state'], 'the spread breach must pause');

    // Conditions clear, but confirmation scans must gate the way back in.
    $protection->ingest([ea_heartbeat('ea-lifecycle', ['spreadPoints' => 5.0])]);
    $first = $protection->evaluateOne('ea-lifecycle', $protection->deployments()['ea-lifecycle'], $policy);
    assert_equals(AP::RECOVERY, $first['state'], 'the first clear scan must only start recovery');
    assert_false($first['allowNewTrades'], 'recovery is still a blocking state');
    assert_equals(1, $first['clearScans'], 'the confirmation counter must advance');

    $second = $protection->evaluateOne('ea-lifecycle', $protection->deployments()['ea-lifecycle'], $policy);
    assert_equals(AP::RESUMED, $second['state'], 'the second clear scan must resume trading');
    assert_true($second['allowNewTrades'], 'resumed means the EA may trade again');

    $third = $protection->evaluateOne('ea-lifecycle', $protection->deployments()['ea-lifecycle'], $policy);
    assert_equals(AP::NORMAL, $third['state'], 'a resumed deployment settles back to NORMAL');

    // A breach during recovery puts it straight back to the blocking state.
    $protection->ingest([ea_heartbeat('ea-lifecycle-2', ['spreadPoints' => 45.0])]);
    $protection->evaluateOne('ea-lifecycle-2', $protection->deployments()['ea-lifecycle-2'], $policy);
    $protection->ingest([ea_heartbeat('ea-lifecycle-2', ['spreadPoints' => 5.0])]);
    $protection->evaluateOne('ea-lifecycle-2', $protection->deployments()['ea-lifecycle-2'], $policy);
    $protection->ingest([ea_heartbeat('ea-lifecycle-2', ['spreadPoints' => 90.0])]);
    $back = $protection->evaluateOne('ea-lifecycle-2', $protection->deployments()['ea-lifecycle-2'], $policy);
    assert_equals(AP::PAUSED, $back['state'], 'a breach during recovery must pause again immediately');
    assert_false($back['allowNewTrades'], 'the fresh breach must block new trades');
    ea_restore($snapshot);
});

// ─── Emergency policy (opt-in, off by default) ─────────────────────
test('§3 — closing positions is opt-in; blocking new trades never is', function () {
    $snapshot = ea_snapshot();
    $default = ea_decide(ea_heartbeat('ea-kill-default', ['dailyPnl' => -400.0]), [
        'dailyLoss' => ['enabled' => true, 'percentLimit' => 0.03],
    ]);
    assert_equals(AP::KILL, $default['state'], 'the daily loss kill must fire');
    assert_false($default['closePositions'], 'positions must NOT be closed unless the administrator opted in');
    assert_false($default['cancelPendingOrders'], 'pending orders must NOT be cancelled unless opted in');
    assert_false($default['allowNewTrades'], 'new trades are always blocked on a kill');

    $optIn = ea_decide(ea_heartbeat('ea-kill-optin', ['dailyPnl' => -400.0]), [
        'dailyLoss' => ['enabled' => true, 'percentLimit' => 0.03],
        'emergency' => ['closePositionsOnKill' => true, 'cancelPendingOrdersOnKill' => true],
    ]);
    assert_true($optIn['closePositions'], 'with the policy enabled the decision must tell the EA to close');
    assert_true($optIn['cancelPendingOrders'], 'with the policy enabled the decision must tell the EA to cancel');
    ea_restore($snapshot);
});

// ─── Transitions are audited and notified (§13) ────────────────────
test('§13 — every transition is audited with the trigger, the account and the actions', function () {
    $snapshot = ea_snapshot();
    ea_clock(1_800_000_000);
    $before = platform()->model->audit->rows;

    $protection = ea_protection();
    $protection->ingest([ea_heartbeat('ea-audit', ['dailyPnl' => -500.0])]);
    $decision = $protection->evaluateOne('ea-audit', $protection->deployments()['ea-audit'], ea_policy(['dailyLoss' => ['enabled' => true, 'percentLimit' => 0.03]]));

    $events = array_values(array_filter(
        platform()->model->audit->rows,
        fn(array $row): bool => is_string($row['type'] ?? null) && str_starts_with((string) $row['type'], 'EA_PROTECTION')
    ));
    assert_true(count($events) >= 2, 'registration and the transition must both be audited');

    $transition = null;
    foreach ($events as $event) {
        if (($event['type'] ?? '') === 'EA_PROTECTION_' . AP::KILL && ($event['detail']['eaId'] ?? null) === 'ea-audit') { $transition = $event; break; }
    }
    assert_not_null($transition, 'the kill transition must be audited');
    assert_equals('ea-audit', $transition['detail']['eaId'] ?? null, 'the audit must name the deployment');
    assert_equals('5123456', $transition['detail']['account'] ?? null, 'the audit must name the account');
    assert_equals('EA_DAILY_LOSS_LIMIT', $transition['detail']['code'] ?? null, 'the audit must name the trigger');
    assert_equals('system', $transition['actor'] ?? null, 'the switch is automatic — the actor is the system, never a person');
    assert_true(count($decision['conditions']) >= 1, 'the decision must carry the conditions that fired');
    assert_true(count(platform()->model->audit->rows) > count($before), 'new audit rows were written');
    ea_restore($snapshot);
});

// ─── Per-deployment limits (§9) ────────────────────────────────────
test('§9 — per-EA limits are validated, clamped and applied to that deployment only', function () {
    $snapshot = ea_snapshot();
    ea_clock(1_800_000_000);
    $protection = ea_protection();
    $protection->ingest([ea_heartbeat('ea-limits')]);

    $limits = $protection->setOverride('ea-limits', ['heartbeatTimeoutSeconds' => 99999, 'marginLevelFloorPercent' => 200.0, 'enabled' => '1', 'nonsense' => 'x']);
    assert_equals(3600, $limits['heartbeatTimeoutSeconds'], 'an absurd timeout must be clamped to the maximum');
    assert_equals(200.0, $limits['marginLevelFloorPercent'], 'a valid override must be stored');
    assert_true($limits['enabled'], 'the enable flag must survive normalisation');
    assert_false(array_key_exists('nonsense', $limits), 'unknown keys must be ignored');

    $status = $protection->status();
    assert_equals(3600, $status['deployments'][0]['limits']['heartbeatTimeoutSeconds'], 'the override must be visible to operators');

    // A stale heartbeat under the raised timeout stays allowed.
    ea_clock(1_800_000_000 + 500);
    $decision = $protection->evaluateAll()['ea-limits'];
    assert_true($decision['allowNewTrades'], 'with a 3600 s timeout a 500 s gap is still fresh');

    assert_throws(InvalidArgumentException::class, fn() => $protection->setOverride('does-not-exist', ['enabled' => false]), 'an unknown deployment must be rejected');
    ea_restore($snapshot);
});

test('§9 — removing a deployment stops its protection and forgets its state', function () {
    $snapshot = ea_snapshot();
    ea_clock(1_800_000_000);
    $protection = ea_protection();
    $protection->ingest([ea_heartbeat('ea-gone')]);
    assert_equals(1, $protection->status()['total'], 'the deployment is registered');
    $protection->unregister('ea-gone');
    assert_equals(0, $protection->status()['total'], 'the deployment is gone');
    assert_null($protection->heartbeat('ea-gone'), 'its heartbeat is gone too');
    $decision = $protection->decision('ea-gone');
    assert_false($decision['allowNewTrades'], 'an unknown deployment must never be granted trading');
    assert_equals('EA_DECISION_UNAVAILABLE', $decision['code'], 'the reason must say no decision exists');
    ea_restore($snapshot);
});

// ─── Bridge transport (§10) ────────────────────────────────────────
test('§10 — the bridge pulls heartbeats and pushes the decisions back', function () {
    $snapshot = ea_snapshot();
    ea_clock(1_800_000_000);
    $calls = [];
    $client = new EaBridgeClient('http://bridge.test', function (string $method, string $url, ?string $token, ?array $body) use (&$calls) {
        $calls[] = ['method' => $method, 'url' => $url, 'token' => $token, 'body' => $body];
        if ($method === 'GET' && str_contains($url, '/v1/ea/heartbeats')) {
            return ['ok' => true, 'heartbeats' => [
                ea_heartbeat('ea-bridge-ok'),
                ea_heartbeat('ea-bridge-risk', ['dailyPnl' => -500.0]),
            ]];
        }
        return ['ok' => true];
    }, 'secret-token', true);

    assert_true($client->configured(), 'a configured bridge must report itself as configured');
    ea_policy(); // documented defaults, so the fixture's -$500 day is 5% of equity
    $report = $client->sync(ea_protection());

    assert_true($report['ok'], 'the sync must succeed');
    assert_equals(2, $report['accepted'], 'both heartbeats must be ingested');
    assert_equals(1, $report['blocked'], 'only the breaching EA must be blocked');
    assert_true($report['pushed'], 'the decisions must be pushed back to the bridge');

    $push = null;
    foreach ($calls as $call) { if ($call['method'] === 'POST') { $push = $call; } }
    assert_not_null($push, 'a POST must have been issued');
    assert_contains('/v1/ea/decisions', (string) $push['url'], 'the decisions go to the decisions endpoint');
    assert_equals('secret-token', $push['token'], 'the shared token is sent with every call');
    assert_equals(2, count($push['body']['decisions']), 'one decision per deployment is published');

    $byId = [];
    foreach ($push['body']['decisions'] as $decision) { $byId[$decision['eaId']] = $decision; }
    assert_true($byId['ea-bridge-ok']['allowNewTrades'], 'the healthy EA may trade');
    assert_false($byId['ea-bridge-risk']['allowNewTrades'], 'the breaching EA may not');
    assert_equals(AP::KILL, $byId['ea-bridge-risk']['state'], 'the breaching EA is killed');
    ea_restore($snapshot);
});

test('§10 — an unreachable bridge is reported; nothing is silently trusted', function () {
    $snapshot = ea_snapshot();
    ea_clock(1_800_000_000);
    $client = new EaBridgeClient('http://bridge.test', fn() => null, 'secret-token', true);
    $report = $client->sync(ea_protection());
    assert_false($report['ok'], 'a failing transport must be reported as a failure');
    assert_false($report['skipped'], 'a failing transport is not the same as no bridge');
    assert_not_null($client->lastError(), 'the error must be available for the operator');
    assert_contains('Could not reach', (string) $report['reason'], 'the report must explain itself');

    $unconfigured = new EaBridgeClient('', null, '', false);
    assert_false($unconfigured->configured(), 'no URL and no token means no bridge');
    $skipped = $unconfigured->sync(ea_protection());
    assert_true($skipped['skipped'], 'with no bridge the sync is skipped, not failed');
    assert_contains('AI_WORKFORCE_MT5_BRIDGE_URL', (string) $skipped['reason'], 'the skip must say what to configure');
    ea_restore($snapshot);
});

test('§10 — a heartbeat posted straight to the platform is answered with a decision', function () {
    $snapshot = ea_snapshot();
    ea_clock(1_800_000_000);
    $protection = ea_protection();
    $ingest = $protection->ingest([
        ['eaId' => 'ea-direct', 'name' => 'Direct EA', 'terminal' => 'MT4', 'metrics' => ['equity' => 5000.0, 'dailyPnl' => -80.0], 'connection' => ['broker' => false]],
    ]);
    assert_equals(['ea-direct'], $ingest['registered'], 'a direct heartbeat registers the deployment');

    $policy = ea_policy();
    $decision = $protection->evaluateOne('ea-direct', $protection->deployments()['ea-direct'], $policy);
    assert_equals('EA_BROKER_DISCONNECTED', $decision['code'], 'the reported outage is what blocks it');
    assert_false($decision['allowNewTrades'], 'the answer must block new trades');

    // The same deployment reports healthy again.
    $protection->ingest([['eaId' => 'ea-direct', 'metrics' => ['equity' => 5000.0, 'dailyPnl' => 10.0], 'connection' => ['broker' => true]]]);
    $after = $protection->evaluateOne('ea-direct', $protection->deployments()['ea-direct'], $policy);
    assert_equals(AP::RECOVERY, $after['state'], 'the first clear report starts recovery, it does not resume instantly');
    ea_restore($snapshot);
});
