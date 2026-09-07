<?php
/**
 * Integration plan step 6 — production review (code-level posture).
 *
 * Pins the security requirements the review imposes on the sports mutation
 * surface:
 *  - console form POSTs self-guard with the session CSRF token (platform
 *    csrf_protection is off; privileged endpoints guard themselves — the
 *    same token the JSON API verifies as X-CSRF-Token)
 *  - approval and settlement are OUT OF SCOPE for the kill switch, which is
 *    now scoped to broker + trading-intelligence surfaces (no broker order
 *    and no money movement happens on a sports ticket in this deployment),
 *    so the gate on approval is RBAC (sports.approve) alone
 *  - the kill switch still boots fail-closed for the surfaces it does gate
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

test('sports prod review: approval and settlement are out of scope for the broker/trading kill switch', function () {
    $c = file_get_contents(FCPATH . 'application/controllers/Sports.php');
    assert_true(!str_contains(fx_prod_body($c, 'public function decide(', 'public function settle('), 'killSwitch'), 'console approval is NOT gated by the trading kill switch');
    assert_contains("requireSportsPermission('sports.approve'", fx_prod_body($c, 'public function decide(', 'public function settle('), 'console approval is still gated on sports.approve');
    assert_true(!str_contains(fx_prod_body($c, 'public function settle(', 'private function requireSportsPermission('), 'killSwitch'), 'console settlement (unwind path) stays open under the kill switch');

    $a = file_get_contents(FCPATH . 'application/controllers/Api_sports.php');
    $approve = fx_prod_body($a, 'public function decide_ticket(', 'public function verify_result(');
    assert_true(!str_contains($approve, 'killSwitch'), 'API approval is NOT gated by the trading kill switch');
    assert_contains("requirePermission('sports.approve')", $approve, 'API approval is still gated on sports.approve');
    assert_true(!str_contains(fx_prod_body($a, 'public function settle_ticket(', ''), 'killSwitch'), 'API settlement stays open under the kill switch');
});

test('sports prod review: kill switch stays fail-closed for trading and never leaks into sports', function () {
    $p = platform();
    $p->setKillSwitch(true, 'prod review: gate check');
    assert_true((bool) ($p->state()['killSwitch']['active'] ?? false), 'engaged kill switch persists and reloads');
    assert_true(\AIWorkforce\KillSwitchScope::blocks('sports_tickets', $p->state()) === false, 'an engaged kill switch never gates sports tickets');
    $p->setKillSwitch(false, 'prod review: release');
    assert_true(empty($p->state()['killSwitch']['active']), 'released kill switch reloads inactive');
    assert_contains('Default state at boot', file_get_contents(FCPATH . 'application/models/AIWorkforce_model.php'), 'fresh installs boot with the kill switch ACTIVE (fail closed)');
});
