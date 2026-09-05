<?php
/**
 * Permission freshness — the bug behind "Refused: signed-in identity lacks
 * 'sports.manage'": the session identity is a snapshot taken at sign-in, so a
 * role granted afterwards stayed invisible until the next sign-in. Permission
 * decisions must be taken against the database, not the snapshot.
 *
 * MY_Controller::refreshIdentityPermissions() is exercised directly with stub
 * session/model collaborators (the CI bootstrap is skipped for the throwaway
 * controller, so no request, database or view is involved).
 */

/** Minimal CI_Session stand-in: the two methods the refresh path uses. */
final class FxFreshSession
{
    public array $data = [];
    public function __construct(array $data = []) { $this->data = $data; }
    public function userdata($key = null) { return $key === null ? $this->data : ($this->data[$key] ?? null); }
    public function set_userdata($key, $value = null): void
    {
        if (is_array($key)) { foreach ($key as $k => $v) $this->data[$k] = $v; return; }
        $this->data[$key] = $value;
    }
}

/** Minimal identity repository: returns whatever permissions the test queues. */
class FxFreshIdentity
{
    public int $calls = 0;
    /** @var array<int, array> */
    public array $permissions = [];
    public function permissionsForUser(int $userId): array
    {
        $this->calls++;
        return $this->permissions[$userId] ?? [];
    }
}

/** Same rule as AIWorkforce\Identity::can() — permission or the super-admin override. */
function fx_fresh_can(array $user, string $permission): bool
{
    $held = (array) ($user['permissions'] ?? []);
    return in_array($permission, $held, true) || in_array('system.super_admin', $held, true);
}

/** @return object a controller exposing the protected refresh helper */
function fx_fresh_controller(FxFreshSession $session, FxFreshIdentity $identity): object
{
    return new class($session, $identity) extends MY_Controller
    {
        public $session;
        public $AIWorkforce_model;

        public function __construct(FxFreshSession $session, FxFreshIdentity $identity)
        {
            // Deliberately skip parent::__construct(): no CI bootstrap, no
            // database, no view — only the permission refresh is under test.
            $this->session = $session;
            $this->AIWorkforce_model = new class($identity) {
                public $identity;
                public function __construct($identity) { $this->identity = $identity; }
            };
        }

        public function refresh(?array $user = null): ?array
        {
            return $this->refreshIdentityPermissions($user);
        }
    };
}

test('permissions: a role granted after sign-in applies on the next action', function () {
    $session = new FxFreshSession(['identity' => ['id' => 7, 'email' => 'member@example.test', 'permissions' => ['sports.view']]]);
    $identity = new FxFreshIdentity();
    // An administrator assigned the Sports administrator role after sign-in.
    $identity->permissions[7] = ['sports.manage', 'sports.view', 'sports.approve', 'sports.settle'];
    $granted = ['sports.approve', 'sports.manage', 'sports.settle', 'sports.view']; // stored sorted

    $user = fx_fresh_controller($session, $identity)->refresh();

    assert_not_null($user);
    assert_equals($granted, $user['permissions'] ?? [], 'fresh permissions are returned');
    assert_equals($granted, $session->userdata('identity')['permissions'] ?? [], 'the session snapshot is rewritten');
    assert_true(fx_fresh_can($user, 'sports.manage'), 'the granted permission is usable without re-signing in');
});

test('permissions: a revoked permission stops working immediately', function () {
    $session = new FxFreshSession(['identity' => ['id' => 8, 'permissions' => ['sports.view', 'sports.manage']]]);
    $identity = new FxFreshIdentity();
    $identity->permissions[8] = ['sports.view']; // the role was taken away

    $user = fx_fresh_controller($session, $identity)->refresh();

    assert_not_null($user);
    assert_false(fx_fresh_can($user, 'sports.manage'), 'revocation is effective at once, not at next sign-in');
    assert_true(fx_fresh_can($user, 'sports.view'), 'the remaining permission still applies');
});

test('permissions: refresh is a single database read per request and is anonymous-safe', function () {
    $session = new FxFreshSession(['identity' => ['id' => 9, 'permissions' => []]]);
    $identity = new FxFreshIdentity();
    $identity->permissions[9] = ['sports.view'];
    $controller = fx_fresh_controller($session, $identity);

    $controller->refresh();
    $controller->refresh();
    $controller->refresh();
    assert_equals(1, $identity->calls, 'permissions are re-read once per request, not per check');

    $anonIdentity = new FxFreshIdentity();
    assert_null(fx_fresh_controller(new FxFreshSession(), $anonIdentity)->refresh(), 'nobody signed in → no identity');
    assert_equals(0, $anonIdentity->calls, 'no database read when there is no session identity');
});

test('permissions: a database failure keeps the signed-in snapshot instead of changing access', function () {
    $session = new FxFreshSession(['identity' => ['id' => 10, 'permissions' => ['sports.manage']]]);
    $identity = new class extends FxFreshIdentity {
        public function permissionsForUser(int $userId): array { throw new RuntimeException('database unavailable'); }
    };

    $user = fx_fresh_controller($session, $identity)->refresh();

    assert_not_null($user);
    assert_equals(['sports.manage'], $user['permissions'] ?? [], 'an outage must not silently revoke or grant access');
});

test('permissions: the sports console gates its controls on the fresh identity', function () {
    $controller = file_get_contents(FCPATH . 'application/controllers/Sports.php');
    assert_contains('private function sportsCaps()', $controller);
    assert_contains("'caps' => \$this->sportsCaps()", $controller, 'views receive the identity capabilities');
    assert_contains('refreshIdentityPermissions($this->identity)', $controller, 'the guard reads permissions from the database');
    assert_contains('no sign-out is needed', $controller, 'the refusal explains how to recover');

    $index = file_get_contents(FCPATH . 'application/views/sports/index.php');
    assert_contains("\$caps['sync']", $index);
    assert_contains('Sync now (needs sports.manage)', $index);
    assert_contains("\$caps['approve']", $index);
    assert_contains("\$caps['settle']", $index);

    $tickets = file_get_contents(FCPATH . 'application/views/sports/tickets.php');
    assert_contains("\$caps['approve']", $tickets);
    assert_contains("\$caps['settle']", $tickets);
});
