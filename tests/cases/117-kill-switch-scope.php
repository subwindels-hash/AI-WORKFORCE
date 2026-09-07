<?php
/**
 * Kill switch scope — broker + trading intelligence only.
 *
 * The kill switch is an order-bound safeguard, not a global platform freeze.
 * These cases pin both halves of that contract so a future change cannot
 * silently widen it back into a global stop-everything flag, or quietly
 * narrow it until broker orders stop being gated:
 *
 *   1. gated surfaces fail closed while it is engaged (execution supervisor,
 *      broker orders, paper orders, automation envelopes);
 *   2. out-of-scope surfaces are never gated (market data, analysis, sports
 *      tickets, lottery, language learning, lead discovery, messaging);
 *   3. the indicator and control only render on broker / trading-intelligence
 *      console routes.
 */
use AIWorkforce\KillSwitchScope;

/** Slice of a source file starting at a method signature (assert on one method). */
function kss_method_body(string $src, string $from, int $length = 1200): string
{
    $a = strpos($src, $from);
    return $a === false ? '' : substr($src, $a, $length);
}

function kss_state(bool $active): array
{
    return ['tradingMode' => 'ANALYSIS_ONLY', 'killSwitch' => ['active' => $active, 'activatedAt' => $active ? gmdate('c') : null, 'reason' => 'test']];
}

test('kill switch scope: gated surfaces fail closed while engaged', function () {
    $engaged = kss_state(true);
    foreach (['execution_supervisor', 'broker_orders', 'paper_orders', 'automation_modes'] as $surface) {
        assert_true(KillSwitchScope::gates($surface), "{$surface} is in scope");
        assert_true(KillSwitchScope::blocks($surface, $engaged), "engaged kill switch blocks {$surface}");
        assert_false(KillSwitchScope::blocks($surface, kss_state(false)), "released kill switch allows {$surface}");
    }
});

test('kill switch scope: an unknown surface is never gated', function () {
    // Fail closed for trading, fail OPEN for everything else: an unrecognised
    // surface must not be blocked by a trading safeguard.
    assert_false(KillSwitchScope::gates('something_new'), 'unknown surface is out of scope');
    assert_false(KillSwitchScope::blocks('something_new', kss_state(true)), 'unknown surface is never blocked');
});

test('kill switch scope: market data and non-trading modules are never gated', function () {
    $engaged = kss_state(true);
    foreach (KillSwitchScope::UNGATED_SURFACES as $surface) {
        assert_false(KillSwitchScope::gates($surface), "{$surface} is out of scope");
        assert_false(KillSwitchScope::blocks($surface, $engaged), "engaged kill switch does not block {$surface}");
    }
});

test('kill switch scope: live platform state still drives the gated surfaces', function () {
    $p = platform();
    $p->setKillSwitch(true, 'scope test: engage');
    $state = $p->state();
    assert_true(KillSwitchScope::blocks('paper_orders', $state), 'engaged switch blocks paper orders from live state');
    assert_true(KillSwitchScope::blocks('broker_orders', $state), 'engaged switch blocks broker orders from live state');
    assert_false(KillSwitchScope::blocks('market_data', $state), 'market data keeps streaming while engaged');
    assert_false(KillSwitchScope::blocks('sports_tickets', $state), 'sports tickets stay approvable while engaged');
    $p->setKillSwitch(false, 'scope test: release');
    assert_false(KillSwitchScope::blocks('paper_orders', $p->state()), 'released switch unblocks paper orders');
});

test('kill switch scope: enforcement reads the scope, not raw state', function () {
    $es = file_get_contents(FCPATH . 'application/libraries/AIWorkforce/ExecutionSupervisor.php');
    assert_contains('KillSwitchScope::blocks(', $es, 'execution supervisor consults the kill switch scope');
    $paper = file_get_contents(FCPATH . 'application/libraries/AIWorkforce/Paper/PaperTradingEngine.php');
    assert_contains('KillSwitchScope::blocks(', $paper, 'paper engine consults the kill switch scope');
    $platform = file_get_contents(FCPATH . 'application/libraries/AIWorkforce/Platform.php');
    assert_contains('KillSwitchScope::blocks(', $platform, 'automation mode gate consults the kill switch scope');
});

test('kill switch scope: the indicator renders on broker + trading pages only', function () {
    foreach (['app/trading', 'app/trading/submit_order', 'trading', 'brokers', 'brokers/connect', 'execution', 'paper', 'risk', 'strategy', 'analysis'] as $route) {
        assert_true(KillSwitchScope::uiVisible($route), "kill switch is shown on /{$route}");
    }
    foreach (['dashboard', 'app/languages', 'app/languages/teacher', 'sports', 'lottery', 'leads', 'multiplier', 'messages', 'notifications', 'admin', 'command-center', 'app/workforce'] as $route) {
        assert_false(KillSwitchScope::uiVisible($route), "kill switch is hidden on /{$route}");
    }
    assert_false(KillSwitchScope::uiVisible(''), 'empty uri renders no kill switch');
    assert_true(KillSwitchScope::uiVisible('/brokers/'), 'trailing slash still matches');
    assert_false(KillSwitchScope::uiVisible('brokers-evil'), 'prefix match cannot be spoofed by a sibling route');
});

test('kill switch scope: out-of-scope consoles do not advertise the switch', function () {
    $header = file_get_contents(FCPATH . 'application/views/layout/header.php');
    assert_contains('KillSwitchScope::uiVisible(uri_string())', $header, 'the shared header scopes the kill switch pill');
    $dashboard = file_get_contents(FCPATH . 'application/views/workspace/index.php');
    assert_not_contains('killSwitch', $dashboard, 'the dashboard no longer reads or advertises the kill switch state');
    $risk = file_get_contents(FCPATH . 'application/views/risk/index.php');
    assert_contains('/kill-switch', $risk, 'Risk Center (a trading surface) keeps the release control');
});

test('kill switch scope: engaging or releasing it is an operator action', function () {
    $w = file_get_contents(FCPATH . 'application/controllers/Welcome.php');
    $body = kss_method_body($w, 'public function kill_switch(');
    assert_contains('trading.control', $body, 'the console toggle requires trading.control');
    assert_contains('refreshIdentityPermissions', $body, 'permissions are re-read from the database, not the session snapshot');
    $helper = file_get_contents(FCPATH . 'application/helpers/ai_workforce_helper.php');
    assert_contains('function ai_workforce_kill_switch_can_control', $helper, 'views have a render guard for the control');
});
