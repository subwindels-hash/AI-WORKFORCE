<?php
/**
 * First-run administrator setup: the no-terminal recovery path for deployments
 * whose database has no super administrator (failed/partial SQL import), plus
 * the wiring that keeps it fail-closed once an administrator exists.
 */

function fx_clear_super_admins(): void
{
    $repo = platform()->model->identity;
    foreach ($repo->listUsers() as $u) {
        if ($repo->userHasRole((int) $u['id'], 'super_admin')) $repo->deleteUser((int) $u['id']);
    }
}

test('first-run: superAdminExists tracks the super_admin role', function () {
    $repo = platform()->model->identity;
    assert_true(is_bool($repo->superAdminExists()), 'definitive bool when the database is available');
    fx_clear_super_admins();
    assert_false($repo->superAdminExists(), 'no super admin remains after clearing');
});

test('first-run: createFirstSuperAdmin creates the initial administrator with full access', function () {
    $identity = platform()->identity;
    $repo = platform()->model->identity;
    $user = $identity->createFirstSuperAdmin('first-admin@example.test', 'setup-password-12345', 'First Administrator');
    assert_true($repo->superAdminExists(), 'a super admin now exists');
    $perms = $repo->permissionsForUser((int) $user['id']);
    assert_in_array('system.super_admin', $perms, 'first admin holds system.super_admin');
    assert_in_array('admin.access', $perms, 'first admin holds admin.access');
    assert_equals('first-admin@example.test', $user['email']);
    assert_true($identity->canAccessAdmin($user), 'the first admin can open the portal');
    assert_false(isset($user['password_hash']), 'returned identity strips the password hash');
});

test('first-run: the path is fail-closed once an administrator exists', function () {
    $identity = platform()->identity;
    assert_throws(\RuntimeException::class, function () use ($identity) {
        $identity->createFirstSuperAdmin('second-admin@example.test', 'setup-password-12345', 'Second Administrator');
    }, 'second creation through the first-run path is refused');
    assert_true(platform()->model->identity->superAdminExists(), 'the original first-run admin is untouched');
});

test('first-run: wiring — setup view, guarded controller handler and documentation', function () {
    fx_clear_super_admins();
    assert_false(platform()->model->identity->superAdminExists(), 'suite left the database without a super admin');

    assert_true(is_file(FCPATH . 'application/views/auth/admin_setup.php'), 'setup view ships');
    $view = file_get_contents(FCPATH . 'application/views/auth/admin_setup.php');
    assert_contains('name="setup"', $view);
    assert_contains('name="csrf_token"', $view);
    assert_contains('/login/submit', $view);
    assert_contains('minlength="12"', $view, 'setup enforces the 12-character password policy');

    $auth = file_get_contents(FCPATH . 'application/controllers/Auth.php');
    assert_contains("input->post('setup') === '1'", $auth, 'setup posts are handled by the auth controller');
    assert_contains('superAdminExists()', $auth, 'handler re-checks that no admin exists (fail-closed)');
    assert_contains('createFirstSuperAdmin', $auth, 'handler uses the guarded domain method');
    assert_contains('ADMIN_BOOTSTRAPPED', $auth, 'first-run creation is audited');
    assert_contains('AI_WORKFORCE_DEMO_ADMIN_EMAIL', $auth, 'demo hint is env-injected, never production defaults');

    assert_contains('$demoHint', file_get_contents(FCPATH . 'application/views/auth/login.php'), 'login page can show the demo operator');
    $readme = file_get_contents(FCPATH . 'README.md');
    assert_contains('admin@example.com', $readme, 'README documents the initial administrator');
    assert_contains('Create the platform administrator', $readme, 'README documents the first-run setup');
});
