<?php
/**
 * KILL-SWITCH SCOPE — the switch is a trading control, not a platform mute.
 *
 * `AIWorkforce\KillSwitchPolicy` is the single source of truth for:
 *  - which surfaces the switch governs (broker connectors — MT5, MT4,
 *    cryptocurrency exchanges, forex/stock brokers, per-user connections —
 *    plus the execution supervisor, the trading intelligence engine, paper
 *    order placement and the automation-mode gate)
 *  - which surfaces it must NEVER govern (sports, football, lottery,
 *    languages, leads, multiplier, messages, notifications, workforce agents,
 *    account/admin, and read-only market data)
 *  - unwind/read actions that stay available even inside a governed surface
 *  - the boot default (RELEASED) and the migration of the pre-scope installer
 *    default that used to block everything
 *  - the pages that render the indicator (trading + broker consoles only)
 *
 * The suite is deliberately self-contained (no helpers from other cases) so a
 * filtered run — AI_WORKFORCE_TEST_FILTER=117 — exercises the whole policy.
 */
use AIWorkforce\KillSwitchPolicy;

/** Source of a repo-relative file. */
function ks_src(string $rel): string
{
    $path = FCPATH . $rel;
    return is_file($path) ? (string) file_get_contents($path) : '';
}

test('kill switch scope: broker + trading-intelligence order paths are governed', function () {
    $governed = [
        'broker', 'broker.mt5-bridge', 'broker.mt4-bridge', 'broker.binance', 'broker.bybit',
        'broker.okx', 'broker.coinbase', 'broker.kraken', 'broker.oanda', 'broker.alpaca',
        'broker.ibkr', 'broker.user-mt5', 'broker.place_order',
        'execution', 'execution.propose', 'execution.route', 'execution.approve',
        'paper', 'paper.submit_order', 'paper.deploy_strategy',
        'trading', 'trading.submit_order', 'trading.intelligence', 'trading.automation_mode', 'trading.signals',
        'risk', 'risk.trade_veto',
    ];
    foreach ($governed as $surface) {
        assert_true(KillSwitchPolicy::governs($surface), "governed: {$surface}");
        assert_true(KillSwitchPolicy::isActive(['active' => true], $surface), "engaged switch blocks {$surface}");
        assert_false(KillSwitchPolicy::isActive(['active' => false], $surface), "released switch clears {$surface}");
    }
});

test('kill switch scope: non-trading modules are never governed', function () {
    $ungoverned = [
        'sports', 'sports.ticket_approval', 'sports.settle', 'sports.sync',
        'football', 'football.prediction',
        'lottery', 'lottery.ticket', 'lottery.generate',
        'language', 'language.lesson', 'language.pronunciation',
        'leads', 'leads.search', 'lead_discovery.export',
        'multiplier', 'multiplier.signal',
        'messages', 'messages.send', 'notifications', 'notifications.push',
        'workforce', 'workforce.agent_dispatch', 'agents', 'agents.dispatch', 'chat', 'chat.answer',
        'account', 'account.profile', 'admin', 'admin.api_providers',
        // Read-only market data keeps flowing: the switch stops ORDERS, not analysis.
        'market_data', 'market_data.candles', 'market_data.quote', 'analysis_read', 'analysis_read.chart',
    ];
    foreach ($ungoverned as $surface) {
        assert_false(KillSwitchPolicy::governs($surface), "not governed: {$surface}");
        assert_false(KillSwitchPolicy::isActive(['active' => true], $surface), "engaged switch must not block {$surface}");
        assert_false(KillSwitchPolicy::blocks(['killSwitch' => ['active' => true]], $surface), "blocks() must not block {$surface}");
    }
    foreach (KillSwitchPolicy::UNGOVERNED_SURFACES as $surface) {
        assert_false(KillSwitchPolicy::governs($surface), "declared un-governed surface stays un-governed: {$surface}");
    }
});

test('kill switch scope: unwind and read-only actions stay available inside governed surfaces', function () {
    // Reducing exposure must never be blocked by the control that stops new
    // exposure — the same rule the sports settlement path follows.
    $alwaysAvailable = [
        'broker.close_position', 'broker.mt5-bridge.close_position', 'broker.mt4-bridge.close',
        'broker.cancel_order', 'execution.cancel_order', 'paper.close_position', 'paper.settle',
        'broker.quote', 'broker.mt5-bridge.quote', 'broker.positions', 'broker.account',
        'broker.history', 'broker.health', 'broker.status', 'paper.history', 'trading.status',
    ];
    foreach ($alwaysAvailable as $surface) {
        assert_false(KillSwitchPolicy::governs($surface), "always available: {$surface}");
        assert_false(KillSwitchPolicy::isActive(['active' => true], $surface), "engaged switch must not block {$surface}");
    }
});

test('kill switch scope: governed surfaces fail closed on a missing or malformed state row', function () {
    assert_true(KillSwitchPolicy::isActive(null, 'execution.propose'), 'missing row fails closed inside scope');
    assert_true(KillSwitchPolicy::isActive([], 'broker.mt5-bridge'), 'row without an active flag fails closed');
    assert_true(KillSwitchPolicy::blocks([], 'paper.submit_order'), 'empty state fails closed');
    assert_true(KillSwitchPolicy::blocks(null, 'trading.submit_order'), 'null state fails closed');
    // ... and an ungoverned surface is never blocked, whatever the state says.
    assert_false(KillSwitchPolicy::isActive(null, 'sports.ticket_approval'), 'out-of-scope surface ignores a broken state');
    assert_false(KillSwitchPolicy::blocks(null, 'lottery.ticket'), 'out-of-scope surface ignores a null state');
});

test('kill switch boot default: released, scoped, and the pre-scope installer row migrates once', function () {
    putenv(KillSwitchPolicy::BOOT_ACTIVE_ENV); // deterministic: no host override in play
    $default = KillSwitchPolicy::defaultState();
    assert_false((bool) $default['active'], 'boots RELEASED');
    assert_null($default['activatedAt'], 'never engaged at boot');
    assert_contains('scoped to broker', (string) $default['reason'], 'the boot reason documents the scope');
    assert_equals(KillSwitchPolicy::GOVERNED_SURFACES, $default['scope'], 'the row carries its scope');
    assert_contains('MT5', KillSwitchPolicy::scopeLabel(), 'the scope label names the broker surfaces');

    // A row written by the OLD installer (active + the old boot reason) was
    // never an operator decision — it is migrated, not honoured.
    $legacy = ['active' => true, 'activatedAt' => null, 'reason' => KillSwitchPolicy::LEGACY_BOOT_REASON];
    assert_true(KillSwitchPolicy::isLegacyBootDefault($legacy), 'legacy boot default detected');
    $migrated = KillSwitchPolicy::normalize($legacy);
    assert_false((bool) $migrated['active'], 'legacy boot default migrates to released');
    assert_equals(KillSwitchPolicy::BOOT_REASON, $migrated['reason'], 'migration records the scoped boot reason');

    // A real operator engagement records its own reason and survives untouched.
    $engaged = ['active' => true, 'activatedAt' => '2026-09-07T00:00:00+00:00', 'reason' => 'operator: bridge misbehaving'];
    assert_false(KillSwitchPolicy::isLegacyBootDefault($engaged), 'operator engagement is not a legacy default');
    $kept = KillSwitchPolicy::normalize($engaged);
    assert_true((bool) $kept['active'], 'operator engagement stays engaged');
    assert_equals('operator: bridge misbehaving', $kept['reason'], 'operator reason preserved');
    assert_equals('2026-09-07T00:00:00+00:00', $kept['activatedAt'], 'operator timestamp preserved');
    assert_equals(KillSwitchPolicy::GOVERNED_SURFACES, $kept['scope'], 'scope filled in on old rows');

    // stateFor() is what Platform::setKillSwitch() persists.
    $on = KillSwitchPolicy::stateFor(true, 'drill');
    assert_true((bool) $on['active']);
    assert_equals('drill', $on['reason']);
    assert_equals(KillSwitchPolicy::GOVERNED_SURFACES, $on['scope']);
    $off = KillSwitchPolicy::stateFor(false, null);
    assert_false((bool) $off['active']);
    assert_equals('released', $off['reason'], 'released without an explicit reason says so');

    // Hosts that want the previous posture back can force an engaged boot —
    // that changes the boot flag only, never the scope.
    putenv(KillSwitchPolicy::BOOT_ACTIVE_ENV . '=1');
    $forced = KillSwitchPolicy::defaultState();
    assert_true((bool) $forced['active'], 'AI_WORKFORCE_KILL_SWITCH_BOOT_ACTIVE=1 installs the switch engaged');
    assert_contains('AI_WORKFORCE_KILL_SWITCH_BOOT_ACTIVE', (string) $forced['reason'], 'the engaged boot records why');
    assert_true(KillSwitchPolicy::isActive($forced, 'broker.mt5-bridge'), 'engaged boot blocks broker order paths');
    assert_false(KillSwitchPolicy::governs('sports.ticket_approval'), 'the env override never widens the scope');
    assert_false(KillSwitchPolicy::isActive($forced, 'lottery.ticket'), 'the env override never blocks non-trading modules');
    putenv(KillSwitchPolicy::BOOT_ACTIVE_ENV);
    assert_false((bool) KillSwitchPolicy::defaultState()['active'], 'released again once the override is removed');
});

test('kill switch scope: every enforcement point routes through the policy', function () {
    $supervisor = ks_src('application/libraries/AIWorkforce/ExecutionSupervisor.php');
    assert_equals(2, substr_count($supervisor, 'KillSwitchPolicy::blocks('), 'supervisor step 1 + routing re-check');
    assert_contains("KillSwitchPolicy::blocks(\$state, 'execution.propose')", $supervisor);
    assert_contains("KillSwitchPolicy::blocks(\$state, 'execution.route')", $supervisor);

    $paper = ks_src('application/libraries/AIWorkforce/Paper/PaperTradingEngine.php');
    assert_contains("KillSwitchPolicy::blocks(\$state, 'paper.submit_order')", $paper, 'paper order placement is governed');
    assert_contains('use AIWorkforce\KillSwitchPolicy;', $paper, 'paper engine imports the policy');

    $engine = ks_src('application/libraries/AIWorkforce/TradingIntelligenceEngine.php');
    assert_contains("KillSwitchPolicy::blocks(\$this->state, 'trading.intelligence')", $engine, 'trading intelligence risk decision is governed');

    $platform = ks_src('application/libraries/AIWorkforce/Platform.php');
    assert_contains("KillSwitchPolicy::blocks(\$state, 'trading.automation_mode')", $platform, 'FULLY_AUTOMATED still needs the switch released');
    assert_contains('KillSwitchPolicy::stateFor($active, $reason)', $platform, 'setKillSwitch() persists through the policy');
    assert_contains('public function killSwitchBlocks(string $surface', $platform, 'Platform exposes the scoped check');

    $trading = ks_src('application/controllers/Trading.php');
    assert_contains("killSwitchBlocks('trading.submit_order'", $trading, 'My Trading order placement is governed');

    $model = ks_src('application/models/AIWorkforce_model.php');
    assert_contains('KillSwitchPolicy::defaultState()', $model, 'installer default comes from the policy');
    assert_contains('KillSwitchPolicy::normalize(', $model, 'stored rows are normalized on load');

    // The raw pre-scope default must not come back.
    assert_true(!str_contains($model, "'killSwitch' => ['active' => true"), 'no hard-coded fail-closed boot row');
    foreach (['application/controllers/Auth.php', 'application/controllers/Workspace.php', 'application/helpers/ai_workforce_helper.php'] as $rel) {
        assert_true(!str_contains(ks_src($rel), "'killSwitch' => ['active' => true]"), "no hard-coded engaged fallback in {$rel}");
    }
});

test('kill switch scope: no non-trading module gates on the switch', function () {
    $modules = [
        'application/controllers/Sports.php', 'application/controllers/Api_sports.php',
        'application/controllers/Football.php', 'application/controllers/Lottery.php',
        'application/controllers/Api_lottery.php', 'application/controllers/Lang_learn.php',
        'application/controllers/Multiplier.php', 'application/controllers/Multiplier_admin.php',
        'application/controllers/Messages.php', 'application/controllers/Notifications.php',
        'application/controllers/Workforce.php', 'application/controllers/Agent_platform.php',
    ];
    foreach ($modules as $rel) {
        $src = ks_src($rel);
        assert_true($src !== '', "{$rel} exists");
        assert_true(!str_contains($src, 'killSwitchBlocks('), "{$rel} must not gate on the trading kill switch");
        foreach (explode("\n", $src) as $i => $line) {
            $gates = preg_match('/\bif\s*\(.*killSwitch/i', $line) === 1;
            assert_true(!$gates, sprintf('%s:%d must not branch on the kill switch (%s)', $rel, $i + 1, trim($line)));
        }
    }
});

test('kill switch scope: an engaged switch rejects a broker intent at step 1 and a released switch clears it', function () {
    $p = platform();
    $original = $p->model->state->load();
    $intent = [
        'symbol' => 'EURUSD', 'marketClass' => 'forex', 'side' => 'BUY', 'type' => 'MARKET',
        'volume' => 1000, 'stopLoss' => 1.075, 'takeProfit' => 1.090, 'reason' => 'kill-switch scope test',
    ];
    try {
        // Pin the mode so the pipeline always stops at step 2 once step 1
        // passes: this suite asserts the kill-switch gate, not broker routing,
        // and must never reach a connector.
        $pinned = $original;
        $pinned['tradingMode'] = 'ANALYSIS_ONLY';
        $p->model->state->save($pinned);

        $p->setKillSwitch(true, 'scope test: engage');
        assert_true($p->killSwitchBlocks('broker.mt5-bridge'), 'platform reports the governed surface as blocked');
        assert_false($p->killSwitchBlocks('sports.ticket_approval'), 'platform reports sports as unaffected');

        $engaged = $p->execution->evaluate($intent, false);
        assert_equals('REJECTED', $engaged['status'], 'engaged switch rejects the broker intent');
        assert_equals('kill-switch', $engaged['checks'][0]['check'], 'rejected at step 1');
        assert_false((bool) $engaged['checks'][0]['ok'], 'step 1 failed');
        assert_contains('kill switch', (string) $engaged['reason']);

        $p->setKillSwitch(false, 'scope test: release');
        assert_false($p->killSwitchBlocks('broker.mt5-bridge'), 'released switch clears the broker surface');
        $released = $p->execution->evaluate($intent, false);
        assert_equals('kill-switch', $released['checks'][0]['check'], 'step 1 still runs first');
        assert_true((bool) $released['checks'][0]['ok'], 'step 1 passes once released');
        assert_true(!str_contains((string) ($released['reason'] ?? ''), 'kill switch'), 'the pipeline no longer rejects on the kill switch');
        assert_equals('REJECTED', $released['status'], 'the pinned ANALYSIS_ONLY mode stops it at the next gate');
        assert_contains('trading mode', (string) $released['reason'], 'it moved past step 1 to the trading-mode gate');

        // The persisted row carries its scope, so every consumer can see what
        // the switch actually governs.
        $row = $p->state()['killSwitch'];
        assert_equals(KillSwitchPolicy::GOVERNED_SURFACES, $row['scope'] ?? null, 'the stored row records the governed surfaces');
        assert_contains('broker + trading intelligence', (string) ($row['scopeLabel'] ?? ''), 'the stored row records the scope label');
    } finally {
        // Leave the platform exactly as this suite found it.
        $p->model->state->save($original);
    }
});

test('kill switch scope: the indicator renders on trading/broker pages only', function () {
    foreach (['trading', 'execution', 'brokers', 'risk', 'paper', 'strategy', 'journal', 'dashboard', 'analysis'] as $page) {
        assert_true(KillSwitchPolicy::governsPage($page), "indicator shown on {$page}");
    }
    foreach (['home', 'command_center', 'sports', 'football', 'lottery', 'languages', 'teacher', 'leads', 'multiplier', 'messages', 'notifications', 'workforce', 'agent_platform', 'admin', 'account', '', null] as $page) {
        assert_false(KillSwitchPolicy::governsPage($page), 'no indicator on ' . var_export($page, true));
    }

    $header = ks_src('application/views/layout/header.php');
    assert_contains('KillSwitchPolicy::governsPage(', $header, 'the header scopes the pill through the policy');
    assert_contains('$ksScoped && $ks && !empty($ks[\'active\'])', $header, 'the pill needs both the page scope and an engaged switch');

    // Page keys used by the trading consoles match the policy list.
    assert_contains("'active' => 'dashboard'", ks_src('application/controllers/Welcome.php'), '/analysis console page key');
    foreach ([
        'application/controllers/Paper.php' => 'paper',
        'application/controllers/Strategy_lab.php' => 'strategy',
        'application/controllers/Journal.php' => 'journal',
        'application/controllers/Execution.php' => 'execution',
        'application/controllers/Brokers.php' => 'brokers',
        'application/controllers/Risk_center.php' => 'risk',
        'application/controllers/Trading.php' => 'trading',
    ] as $rel => $page) {
        assert_contains("'active' => '{$page}'", ks_src($rel), "{$rel} renders as {$page}");
        assert_in_array($page, KillSwitchPolicy::TRADING_PAGES, "{$page} is a trading page");
    }
});

test('kill switch scope: APIs and the console expose the scope', function () {
    putenv(KillSwitchPolicy::BOOT_ACTIVE_ENV); // deterministic boot default
    $scope = platform()->killSwitchScope();
    assert_equals(KillSwitchPolicy::scopeLabel(), $scope['label'], 'scope label');
    assert_equals(KillSwitchPolicy::GOVERNED_SURFACES, $scope['governs'], 'governed surfaces');
    assert_equals(KillSwitchPolicy::UNGOVERNED_SURFACES, $scope['neverGoverns'], 'never-governed surfaces');
    assert_equals('RELEASED', $scope['bootDefault'], 'boot default is published');

    assert_contains('killSwitchScope', ks_src('application/controllers/Api_system.php'), '/api/system/status publishes the scope');
    assert_contains('Scope: broker + trading-intelligence order paths', ks_src('application/views/paper/index.php'), 'the paper console states the scope');
    assert_contains('Scope: broker + trading-intelligence order paths', ks_src('application/views/execution/index.php'), 'the execution console states the scope');
    assert_contains('Scoped control', ks_src('application/views/welcome/index.php'), 'the trading console explains the scope next to the toggle');
});
