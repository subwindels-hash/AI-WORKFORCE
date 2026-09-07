<?php
/**
 * Kill switch scope + "no manual switch" contract.
 *
 * The kill switch is an order-bound safeguard for broker and trading
 * intelligence surfaces, and it has NO manual control anywhere in the product:
 * the Automatic Kill Switch engine (`TradingProtection\AutomaticProtection`)
 * derives the state and drives the gate.
 *
 * These cases pin:
 *   1. gated surfaces fail closed while the switch is engaged;
 *   2. out-of-scope surfaces are never gated (market data keeps streaming);
 *   3. the indicator renders on broker/trading routes only;
 *   4. nothing in the codebase exposes a manual engage/release control.
 */
use AIWorkforce\KillSwitchScope;
use AIWorkforce\TradingProtection\AutomaticProtection;

/** Slice of a source file starting at a marker (assert on one method). */
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

test('automatic kill switch: enforcement reads the scope, not raw state', function () {
    $es = file_get_contents(FCPATH . 'application/libraries/AIWorkforce/ExecutionSupervisor.php');
    assert_contains('KillSwitchScope::blocks(', $es, 'execution supervisor consults the kill switch scope');
    $paper = file_get_contents(FCPATH . 'application/libraries/AIWorkforce/Paper/PaperTradingEngine.php');
    assert_contains('KillSwitchScope::blocks(', $paper, 'paper engine consults the kill switch scope');
    $platform = file_get_contents(FCPATH . 'application/libraries/AIWorkforce/Platform.php');
    assert_contains('KillSwitchScope::blocks(', $platform, 'automation mode gate consults the kill switch scope');
});

test('automatic kill switch: no manual engage or release control exists anywhere', function () {
    // The engine derives state; it must not expose a command surface.
    foreach (['engage', 'release', 'toggle', 'activate', 'deactivate', 'setActive'] as $method) {
        assert_false(method_exists(AutomaticProtection::class, $method), "AutomaticProtection must not expose {$method}()");
    }

    // No route may expose a manual switch.
    $routes = file_get_contents(FCPATH . 'application/config/routes.php');
    assert_not_contains('kill-switch', $routes, 'no /kill-switch route remains');
    assert_not_contains('toggle_kill_switch', $routes, 'no kill switch toggle route remains');
    assert_contains("admin/protection", $routes, 'administrators configure thresholds instead');

    // No controller action, and no view that posts to one.
    $welcome = file_get_contents(FCPATH . 'application/controllers/Welcome.php');
    assert_not_contains('kill_switch', $welcome, 'Welcome has no kill switch action');
    $api = file_get_contents(FCPATH . 'application/controllers/Api_system.php');
    assert_not_contains('public function kill_switch(', $api, 'the API has no kill switch endpoint');
    $trading = file_get_contents(FCPATH . 'application/controllers/Trading.php');
    assert_not_contains('toggle_kill_switch', $trading, 'My Trading has no kill switch toggle');

    foreach (['layout/header', 'welcome/index', 'risk/index', 'trading/index', 'workspace/index'] as $view) {
        $src = file_get_contents(FCPATH . 'application/views/' . $view . '.php');
        assert_not_contains('action="/kill-switch"', $src, "{$view} posts to no kill switch endpoint");
    }
    $helper = file_get_contents(FCPATH . 'application/helpers/ai_workforce_helper.php');
    assert_not_contains('kill_switch_can_control', $helper, 'the manual-control permission guard is gone');
});

test('automatic kill switch: the engine owns the order gate, not a human', function () {
    $src = file_get_contents(FCPATH . 'application/libraries/AIWorkforce/TradingProtection/AutomaticProtection.php');
    assert_contains('private function driveKillSwitch(', $src, 'the engine engages and releases the gate itself');
    assert_contains("state['killSwitch'] = [", $src, 'the gate it drives is the same kill switch every order path checks');
});
