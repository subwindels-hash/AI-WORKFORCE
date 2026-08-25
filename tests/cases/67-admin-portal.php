<?php
/**
 * Complete Admin Dashboard Portal: RBAC, user management, impersonation
 * audit trail, settings and the user/admin separation.
 */
require_once FCPATH . 'tools/rbac.php';

function fx_admin_portal(): \AIWorkforce\AdminPortal
{
    $portal = new \AIWorkforce\AdminPortal(platform()->model);
    $portal->ensureSchema();
    return $portal;
}

function fx_make_user(string $prefix, string $role = 'platform_member', int $active = 1): array
{
    $repo = platform()->model->identity;
    $now = gmdate('c');
    $user = $repo->createUser([
        'email' => $prefix . '-' . uniqid() . '@example.com',
        'password_hash' => password_hash('long-password-123456', PASSWORD_DEFAULT),
        'display_name' => $prefix,
        'username' => $repo->generateUniqueUsername($prefix),
        'active' => $active,
        'created_at' => $now,
        'updated_at' => $now,
        'last_login_at' => null,
    ]);
    $roleId = $repo->ensureRole($role, ucwords(str_replace('_', ' ', $role)));
    $repo->assignRole((int) $user['id'], $roleId);
    $user['permissions'] = $repo->permissionsForUser((int) $user['id']);
    $user['roles'] = $repo->rolesForUser((int) $user['id']);
    unset($user['password_hash']);
    return $user;
}

test('admin portal schema, routes and views are installed', function () {
    $portal = fx_admin_portal();
    $db = platform()->model->db;
    foreach (['admin_activity_logs', 'impersonation_sessions', 'platform_settings', 'api_providers'] as $table) {
        assert_true($db->table_exists($table), "table $table exists");
    }
    $routes = file_get_contents(FCPATH . 'application/config/routes.php');
    foreach (['admin/users', 'admin/users/(:num)', 'admin/users/(:num)/impersonate', 'admin/impersonation/return', 'admin/admins', 'admin/logs', 'admin/settings', 'admin/security', 'admin/api', 'admin/api/create', 'admin/api/(:num)'] as $r) {
        assert_contains("\$route['{$r}']", $routes, "route $r");
    }
    foreach (['index', 'users/index', 'users/show', 'users/create', 'users/edit', 'layout/header', 'layout/footer', 'logs', 'admins', 'settings', 'security', 'api/index', 'api/form'] as $view) {
        assert_true(is_file(FCPATH . "application/views/admin/{$view}.php"), "view admin/{$view}.php");
    }
    $admin = file_get_contents(FCPATH . 'application/controllers/Admin.php');
    assert_contains('requireAdminPage', $admin);
    assert_contains('system.super_admin', $admin);
    assert_contains('admin.users.impersonate', $admin);
    assert_contains('password_hash(', $admin, 'new passwords are hashed before storage');
});

test('admin RBAC: Super Admin, Admin and Support Admin have distinct permissions', function () {
    assert_in_array('admin', array_keys(AI_WORKFORCE_RBAC_ROLES));
    assert_in_array('support_admin', array_keys(AI_WORKFORCE_RBAC_ROLES));
    assert_in_array('admin.access', array_keys(AI_WORKFORCE_RBAC_PERMISSIONS));
    assert_in_array('admin.users.impersonate', AI_WORKFORCE_RBAC_GRANTS['admin']);
    assert_false(in_array('admin.admins.manage', AI_WORKFORCE_RBAC_GRANTS['admin'], true), 'normal admin cannot manage admins');
    assert_false(in_array('admin.settings.manage', AI_WORKFORCE_RBAC_GRANTS['admin'], true), 'normal admin cannot change system settings');
    assert_in_array('admin.api.view', AI_WORKFORCE_RBAC_GRANTS['admin']);
    assert_in_array('admin.api.test', AI_WORKFORCE_RBAC_GRANTS['admin']);
    assert_false(in_array('admin.api.manage', AI_WORKFORCE_RBAC_GRANTS['admin'], true), 'normal admin cannot change API providers');
    assert_false(in_array('admin.api.credentials', AI_WORKFORCE_RBAC_GRANTS['admin'], true), 'normal admin cannot change API credentials');
    assert_false(in_array('admin.api.view', AI_WORKFORCE_RBAC_GRANTS['support_admin'], true), 'support cannot open API Management');
    assert_in_array('admin.users.view', AI_WORKFORCE_RBAC_GRANTS['support_admin']);
    assert_false(in_array('admin.users.manage', AI_WORKFORCE_RBAC_GRANTS['support_admin'], true), 'support cannot manage users');
    assert_false(in_array('admin.users.delete', AI_WORKFORCE_RBAC_GRANTS['support_admin'], true));
    assert_equals(array_keys(AI_WORKFORCE_RBAC_PERMISSIONS), AI_WORKFORCE_RBAC_GRANTS['super_admin']);
});

test('identity: admin.access is required for the portal and super_admin still overrides', function () {
    $member = ['id' => 2, 'permissions' => ['trading.view']];
    $support = ['id' => 3, 'permissions' => ['admin.access', 'admin.users.view']];
    $root = ['id' => 1, 'permissions' => ['system.super_admin']];
    $id = platform()->identity;
    assert_false($id->canAccessAdmin($member));
    assert_true($id->canAccessAdmin($support));
    assert_true($id->canAccessAdmin($root));
    assert_true($id->can($root, 'admin.users.impersonate'));
    assert_false($id->can($support, 'admin.settings.manage'));
});

test('user search finds six-digit User ID, username and email without returning the password hash', function () {
    $user = fx_make_user('finder');
    $repo = platform()->model->identity;
    foreach ([$user['user_uid'], $user['username'], $user['email']] as $q) {
        $found = $repo->searchUsers(['q' => $q], 'created_at', 'DESC', 1, 10);
        assert_true($found['total'] >= 1, "search '$q' returns a row");
        $hit = null;
        foreach ($found['rows'] as $row) if ((int) $row['id'] === (int) $user['id']) $hit = $row;
        assert_not_null($hit, "search '$q' includes the created user");
        assert_false(isset($hit['password_hash']), 'search never returns a password hash');
        assert_equals($user['user_uid'], $hit['user_uid']);
    }
});

test('suspend blocks authentication and shows the unavailable path', function () {
    $repo = platform()->model->identity;
    $now = gmdate('c');
    $email = 'susp-' . uniqid() . '@example.com';
    $pass = 'long-password-123456';
    $user = $repo->createUser([
        'email' => $email, 'password_hash' => password_hash($pass, PASSWORD_DEFAULT),
        'display_name' => 'Suspended', 'active' => 1, 'created_at' => $now, 'updated_at' => $now,
    ]);
    assert_not_null(platform()->identity->authenticate($email, $pass));
    $repo->setActive((int) $user['id'], false);
    assert_null(platform()->identity->authenticate($email, $pass), 'suspended user cannot authenticate');
    assert_true(platform()->identity->isSuspendedWithPassword($email, $pass));
    assert_false(platform()->identity->isSuspendedWithPassword($email, 'wrong-password'), 'wrong password does not confirm suspension');
    $repo->setActive((int) $user['id'], true);
    assert_not_null(platform()->identity->authenticate($email, $pass), 'reactivated user can sign in');
});

test('successful login records last_login_at', function () {
    $repo = platform()->model->identity;
    $now = gmdate('c');
    $email = 'loginat-' . uniqid() . '@example.com';
    $user = $repo->createUser([
        'email' => $email, 'password_hash' => password_hash('long-password-123456', PASSWORD_DEFAULT),
        'display_name' => 'Login', 'active' => 1, 'created_at' => $now, 'updated_at' => $now, 'last_login_at' => null,
    ]);
    assert_null($user['last_login_at']);
    $authed = platform()->identity->authenticate($email, 'long-password-123456');
    assert_not_null($authed);
    $fresh = $repo->findUserById((int) $user['id']);
    assert_true(!empty($fresh['last_login_at']), 'last_login_at is written');
});

test('admin activity log and impersonation sessions persist start and end', function () {
    $portal = fx_admin_portal();
    $admin = fx_make_user('rooty', 'super_admin');
    $member = fx_make_user('target');
    $sessionId = $portal->startImpersonation($admin, $member, '203.0.113.10');
    assert_true($sessionId > 0);
    $open = platform()->model->db->get_where('impersonation_sessions', ['id' => $sessionId], 1)->row_array();
    assert_not_null($open);
    assert_null($open['ended_at']);
    assert_equals('203.0.113.10', $open['ip']);
    $portal->endImpersonation($sessionId, $admin, $member, '203.0.113.10');
    $closed = platform()->model->db->get_where('impersonation_sessions', ['id' => $sessionId], 1)->row_array();
    assert_true(!empty($closed['ended_at']));
    $logs = $portal->activityLogs(['action' => 'IMPERSONATION_STARTED'], 1, 20);
    $hit = false;
    foreach ($logs['rows'] as $row) {
        if ((int) $row['admin_id'] === (int) $admin['id'] && ($row['target_id'] ?? '') === (string) $member['user_uid']) $hit = true;
    }
    assert_true($hit, 'impersonation start is logged against the six-digit User ID');
});

test('password reset hashes a new secret and never stores the plaintext', function () {
    $repo = platform()->model->identity;
    $user = fx_make_user('resetme');
    $before = $repo->findUserById((int) $user['id']);
    $temp = 'TempPass-' . bin2hex(random_bytes(4));
    $repo->updateUser((int) $user['id'], ['password_hash' => password_hash($temp, PASSWORD_DEFAULT)]);
    $after = $repo->findUserById((int) $user['id']);
    assert_true(password_verify($temp, $after['password_hash']));
    assert_false(str_contains(json_encode($after), $temp), 'plaintext temporary password is not stored on the user row');
    assert_true($before['password_hash'] !== $after['password_hash']);
    unset($after['password_hash']);
    assert_false(isset($after['password_hash']));
});

test('platform settings persist non-secret values and never accept smtp passwords', function () {
    $portal = fx_admin_portal();
    $portal->saveSettings(['product_name' => 'WINDELS Test', 'contact_email' => 'ops@example.com', 'contact_name' => 'Ops'], 'general', 1);
    $portal->saveSettings(['registration_enabled' => '0'], 'accounts', 1);
    assert_equals('WINDELS Test', $portal->setting('product_name'));
    assert_equals('0', $portal->setting('registration_enabled', '1'));
    $all = $portal->settingsByCategory();
    $blob = json_encode($all);
    assert_false(str_contains($blob, 'VP_SMTP_PASS'));
    assert_false(str_contains($blob, 'SMTP_PASS'));
});

test('dashboard statistics are real database counts', function () {
    $portal = fx_admin_portal();
    $before = $portal->dashboardStats();
    fx_make_user('statuser');
    $after = $portal->dashboardStats();
    assert_true($after['users'] >= $before['users'] + 1);
    assert_true($after['users'] === $after['active'] + $after['suspended']);
});

test('user dashboard chrome stays intact and does not host admin management', function () {
    $header = file_get_contents(FCPATH . 'application/views/layout/header.php');
    assert_contains('href="/dashboard"', $header);
    assert_contains('AI Workforce', $header);
    assert_contains('My Account', $header);
    assert_false(str_contains($header, 'Login as User'), 'impersonation is not on the user dashboard');
    assert_true(is_file(FCPATH . 'application/views/partials/impersonation_banner.php'));
    $banner = file_get_contents(FCPATH . 'application/views/partials/impersonation_banner.php');
    assert_contains('You are currently viewing this account as an administrator.', $banner);
    assert_contains('Return to Admin Account', $banner);
    $adminHeader = file_get_contents(FCPATH . 'application/views/admin/layout/header.php');
    foreach (['Dashboard', 'Users', 'AI Workforce', 'Language Learning', 'Conversations', 'Analytics', 'Notifications', 'Reports', 'System Settings', 'Admin Accounts', 'Activity Logs', 'Security', 'Logout'] as $item) {
        assert_contains($item, $adminHeader, "admin sidebar has $item");
    }
    $css = file_get_contents(FCPATH . 'assets/css/ai_workforce.css');
    assert_contains('.sidebar a svg { width: 20px; height: 20px;', $css);
});

test('admin user views never expose a password field for the existing secret', function () {
    $show = file_get_contents(FCPATH . 'application/views/admin/users/show.php');
    $edit = file_get_contents(FCPATH . 'application/views/admin/users/edit.php');
    assert_contains('Reset Password', $show);
    assert_false(str_contains($show, 'name="current_password"'));
    assert_false(str_contains($edit, 'password_hash'));
    assert_contains('current password is never displayed', $edit);
});
