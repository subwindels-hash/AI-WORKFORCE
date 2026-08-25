<?php
/**
 * Shared RBAC default matrix — the single source of truth used by BOTH
 * tools/install.php (direct PDO, script installs) and Tools::seedAccessControls
 * (CI3 model layer, controller installs). Roles/permissions are idempotent.
 *
 * Consumed by ai_workforce_seed_rbac(callable $ensureRole, callable $ensurePermission,
 * callable $grant): all callables return/accept integer ids.
 */

if (!defined('AI_WORKFORCE_RBAC_ROLES')) {
    define('AI_WORKFORCE_RBAC_ROLES', [
        'super_admin' => 'Super administrator',
        'sports_admin' => 'Sports administrator',
        'sports_viewer' => 'Sports viewer',
        'trading_operator' => 'Trading operator (control + execution)',
        'trading_viewer' => 'Trading viewer (read-only)',
        'lottery_admin' => 'Lottery administrator',
        'lottery_viewer' => 'Lottery viewer',
        'platform_member' => 'Platform member',
        'admin' => 'Administrator',
        'support_admin' => 'Support administrator',
    ]);
    define('AI_WORKFORCE_RBAC_PERMISSIONS', [
        'system.super_admin' => 'Full platform administration',
        'sports.view' => 'View sports intelligence',
        'sports.manage' => 'Manage sports providers and configuration',
        'sports.approve' => 'Approve sports tickets',
        'sports.settle' => 'Override sports settlements',
        'trading.view' => 'View trading status, proposals and executions',
        'trading.control' => 'Kill switch, trading mode, risk and automation limits',
        'trading.execute' => 'Propose, approve and route trades through the Execution Supervisor',
        'lottery.view' => 'View lottery intelligence (draws, statistics, tickets, performance)',
        'lottery.manage' => 'Manage lottery providers, data sync and configuration',
        'admin.access' => 'Access the administrator portal',
        'admin.users.view' => 'View user accounts',
        'admin.users.manage' => 'Create, edit, suspend and reset user accounts',
        'admin.users.delete' => 'Delete user accounts',
        'admin.users.impersonate' => 'Sign in as a user without their password',
        'admin.admins.manage' => 'Manage administrator accounts and roles',
        'admin.settings.manage' => 'Change platform system settings',
        'admin.logs.view' => 'View administrator activity logs',
        'admin.analytics.view' => 'View platform analytics and reports',
        'admin.security.view' => 'View security controls and login protection',
        'admin.api.view' => 'View API / provider settings',
        'admin.api.manage' => 'Add, edit, enable, disable or delete API providers',
        'admin.api.test' => 'Test API provider connections',
        'admin.api.credentials' => 'View and change API credentials',
    ]);
    define('AI_WORKFORCE_RBAC_GRANTS', [
        'super_admin' => array_keys(AI_WORKFORCE_RBAC_PERMISSIONS), // everything
        'sports_admin' => ['sports.view', 'sports.manage', 'sports.approve', 'sports.settle'],
        'sports_viewer' => ['sports.view'],
        'trading_operator' => ['trading.view', 'trading.control', 'trading.execute'],
        'trading_viewer' => ['trading.view'],
        'lottery_admin' => ['lottery.view', 'lottery.manage'],
        'lottery_viewer' => ['lottery.view'],
        'platform_member' => ['trading.view', 'sports.view', 'lottery.view'],
        'admin' => [
            'admin.access', 'admin.users.view', 'admin.users.manage', 'admin.users.delete',
            'admin.users.impersonate', 'admin.logs.view', 'admin.analytics.view', 'admin.security.view',
            'admin.api.view', 'admin.api.test',
        ],
        'support_admin' => [
            'admin.access', 'admin.users.view', 'admin.users.impersonate', 'admin.logs.view',
        ],
    ]);
}

/**
 * @param callable(string, string): int $ensureRole
 * @param callable(string, string): int $ensurePermission
 * @param callable(int, int): void $grant
 */
function ai_workforce_seed_rbac(callable $ensureRole, callable $ensurePermission, callable $grant): void
{
    $roleIds = [];
    foreach (AI_WORKFORCE_RBAC_ROLES as $code => $name) $roleIds[$code] = $ensureRole($code, $name);
    $permissionIds = [];
    foreach (AI_WORKFORCE_RBAC_PERMISSIONS as $code => $name) $permissionIds[$code] = $ensurePermission($code, $name);
    foreach (AI_WORKFORCE_RBAC_GRANTS as $role => $permissions) {
        foreach ($permissions as $permission) {
            $grant($roleIds[$role], $permissionIds[$permission]);
        }
    }
}
