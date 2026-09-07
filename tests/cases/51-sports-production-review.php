<?php
/**
 * Integration plan step 6 — production review (code-level posture).
 *
 * Pins the security requirements the review imposes on the sports mutation
 * surface:
 *  - console form POSTs self-guard with the session CSRF token (platform
 *    csrf_protection is off; privileged endpoints guard themselves — the
 *    same token the JSON API verifies as X-CSRF-Token)
 *  - approval and settlement are guarded by RBAC + CSRF + audit, NOT by the
 *    platform kill switch: the switch is a trading control scoped to broker +
 *    trading-intelligence order paths (AIWorkforce\KillSwitchPolicy), so it
 *    must never gate — or be blamed for — sports research (finding 2,
 *    superseded)
 *  - the kill switch still round-trips through live platform state and boots
 *    RELEASED with its scope recorded, while trading stays fail-closed through
 *    ANALYSIS_ONLY and the connector/automation gates
 */

/** Method body between two markers in a source file (to assert on one method precisely). */
function fx_prod_body(string $src, string $from, string $to): string
{
    $a = strpos($src, $from);
    if ($a === false) return '';
    if ($to === '') return substr($src, $a);
    $b = strpos($src, $to, $a + strlen($from));
    return $b === false ? substr($src, $a) : substr($src, $a, $b - $a);
}

test('sports prod review: console mutation forms carry the session CSRF token', function () {
    $c = file_get_contents(FCPATH . 'application/controllers/Sports.php');
    assert_contains("input->post('csrf_token')", $c);
    assert_contains('hash_equals', $c);
    assert_contains("'csrfToken' => (string) \$this->session->userdata('csrf_token')", $c);
    foreach (['index', 'tickets'] as $page) {
        $v = file_get_contents(FCPATH . 'application/views/sports/' . $page . '.php');
        $forms = substr_count($v, 'method="post"');
        // Count hidden inputs only — the views' polling JS also contains the
        // selector string input[name="csrf_token"], which is not a form field.
        $tokens = substr_count($v, '<input type="hidden" name="csrf_token"');
        assert_true($forms > 0, $page . ' view has mutation forms');
        assert_equals($forms, $tokens, 'every ' . $page . ' form carries a csrf_token field');
    }
});

test('sports prod review: the trading kill switch does not gate sports approval or settlement', function () {
    // Sports Intelligence is listed as an un-governed surface, so neither the
    // console nor the API approval path may refuse on the kill switch — a
    // trading emergency stop must not silently disable sports research, and a
    // sports approval must not be blocked by a control that cannot affect it.
    assert_false(AIWorkforce\KillSwitchPolicy::governs('sports.ticket_approval'), 'sports is out of kill-switch scope');
    assert_false(AIWorkforce\KillSwitchPolicy::governs('sports'), 'sports is out of kill-switch scope');
    assert_contains('sports', implode(',', AIWorkforce\KillSwitchPolicy::UNGOVERNED_SURFACES));

    $c = file_get_contents(FCPATH . 'application/controllers/Sports.php');
    $decide = fx_prod_body($c, 'public function decide(', 'public function settle(');
    assert_true($decide !== '', 'console decide() located');
    assert_true(!str_contains($decide, 'killSwitchActive()'), 'console approval is not gated on the kill switch');
    assert_true(!str_contains($decide, 'KillSwitchPolicy::blocks'), 'console approval is not gated on the scoped kill switch either');
    assert_contains("requireSportsPermission('sports.approve'", $decide, 'approval stays RBAC-guarded');
    assert_true(!str_contains(fx_prod_body($c, 'public function settle(', 'private function actor('), 'killSwitch'), 'console settlement (unwind path) stays open');
    assert_true(!str_contains($c, 'private function killSwitchActive('), 'the dead sports kill-switch helper is gone');

    $a = file_get_contents(FCPATH . 'application/controllers/Api_sports.php');
    $decideApi = fx_prod_body($a, 'public function decide_ticket(', 'public function verify_result(');
    assert_true($decideApi !== '', 'API decide_ticket() located');
    assert_true(!str_contains($decideApi, "killSwitch']['active"), 'API approval is not gated on the kill switch');
    assert_contains("requirePermission('sports.approve')", $decideApi, 'API approval stays RBAC-guarded');
    assert_true(!str_contains(fx_prod_body($a, 'public function settle_ticket(', ''), 'killSwitch'), 'API settlement stays open');
});

test('sports prod review: kill switch round-trips through live state and boots released + scoped', function () {
    $p = platform();
    // The installer default comes from the policy (single source of truth):
    // released, with the scope recorded on the row. The LIVE row may carry any
    // operator reason, so the boot posture is asserted on the policy default.
    $default = AIWorkforce\KillSwitchPolicy::defaultState();
    assert_false((bool) $default['active'], 'fresh installs boot with the scoped kill switch RELEASED');
    assert_contains('scoped to broker', (string) ($default['reason'] ?? ''), 'the boot reason documents the scope');
    $model = file_get_contents(FCPATH . 'application/models/AIWorkforce_model.php');
    assert_contains('KillSwitchPolicy::defaultState()', $model, 'the model boots from the scoped policy default');
    assert_contains('KillSwitchPolicy::isLegacyBootDefault(', $model, 'a pre-scope boot default is migrated on load');

    $p->setKillSwitch(true, 'prod review: gate check');
    $engaged = $p->state()['killSwitch'];
    assert_true((bool) ($engaged['active'] ?? false), 'engaged kill switch persists and reloads');
    assert_true($p->killSwitchBlocks('broker.mt5-bridge'), 'engaged switch still blocks broker order paths');
    assert_false($p->killSwitchBlocks('sports.ticket_approval'), 'engaged switch never blocks sports');

    $p->setKillSwitch(false, 'prod review: release');
    assert_true(empty($p->state()['killSwitch']['active']), 'released kill switch reloads inactive');
    assert_false(AIWorkforce\KillSwitchPolicy::blocks($p->state(), 'execution.propose'), 'released switch clears the governed order paths');
});
