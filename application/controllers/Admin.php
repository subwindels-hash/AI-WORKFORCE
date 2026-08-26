<?php
defined('BASEPATH') or exit('No direct script access allowed');
require_once APPPATH . 'core/App_Controller.php';

/**
 * WINDELS AI WORKFORCE administrator portal.
 * Every action is authorized server-side. Passwords, hashes and secrets are never shown.
 */
class Admin extends App_Controller
{
    private \AIWorkforce\AdminPortal $portal;

    public function __construct()
    {
        parent::__construct();
        $this->portal = new \AIWorkforce\AdminPortal($this->AIWorkforce_model);
        $this->portal->ensureSchema();
    }

    public function index()
    {
        $user = $this->requireAdmin(); if (!$user) return;
        if (!$this->platform->identity->can($user, 'admin.analytics.view') && !$this->isSuperAdmin($user)) {
            // Support admins still see the overview with the numbers they can access.
        }
        $data = $this->base('Admin Dashboard', 'dashboard');
        $data['stats'] = $this->portal->dashboardStats();
        $data['smtp'] = \AIWorkforce\Mailer::configSummary();
        $this->render('admin/index', $data);
    }

    public function users()
    {
        $actor = $this->gate('admin.users.view'); if (!$actor) return;
        $q = trim((string) $this->input->get('q', true));
        $status = (string) $this->input->get('status', true);
        $sort = (string) $this->input->get('sort', true) ?: 'created_at';
        $dir = (string) $this->input->get('dir', true) ?: 'DESC';
        $page = max(1, (int) $this->input->get('page'));
        $result = $this->AIWorkforce_model->identity->searchUsers(
            ['q' => $q, 'status' => in_array($status, ['active', 'suspended'], true) ? $status : ''],
            $sort, $dir, $page, 20
        );
        $data = $this->base('Users', 'users');
        $data = array_merge($data, $result);
        $data['q'] = $q;
        $data['statusFilter'] = $status;
        $data['sort'] = $sort;
        $data['dir'] = $dir;
        $data['canManage'] = $this->platform->identity->can($actor, 'admin.users.manage');
        $data['canImpersonate'] = $this->platform->identity->can($actor, 'admin.users.impersonate');
        $data['canDelete'] = $this->platform->identity->can($actor, 'admin.users.delete');
        $this->render('admin/users/index', $data);
    }

    public function user($id = 0)
    {
        $actor = $this->gate('admin.users.view'); if (!$actor) return;
        $member = $this->findPublicUser((int) $id);
        if (!$member) { $this->flash('error', 'User not found.'); redirect('/admin/users'); return; }
        $data = $this->base('User ' . ($member['user_uid'] ?? $member['id']), 'users');
        $data['member'] = $member;
        $data['bundle'] = $this->portal->userProfileBundle($member);
        $data['canManage'] = $this->platform->identity->can($actor, 'admin.users.manage');
        $data['canImpersonate'] = $this->canImpersonate($actor, $member);
        $data['canDelete'] = $this->platform->identity->can($actor, 'admin.users.delete');
        $this->render('admin/users/show', $data);
    }

    public function create_user()
    {
        $actor = $this->gate('admin.users.manage'); if (!$actor) return;
        if ($this->input->method(true) !== 'POST') {
            $data = $this->base('Create user', 'users');
            $data['roles'] = $this->portal->assignableRoles($actor);
            $this->render('admin/users/create', $data);
            return;
        }
        if (!$this->validCsrf()) { $this->flash('error', 'Invalid security token.'); redirect('/admin/users/create'); return; }
        $username = strtolower(trim((string) $this->input->post('username')));
        $email = strtolower(trim((string) $this->input->post('email')));
        $password = (string) $this->input->post('password');
        $roleCode = trim((string) $this->input->post('role'));
        if (!$this->validUsername($username) || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
            $this->flash('error', 'Enter a valid username, email and a password of at least 12 characters.');
            redirect('/admin/users/create'); return;
        }
        if (!$this->roleAllowed($actor, $roleCode)) {
            $this->flash('error', 'That role cannot be assigned from your account.');
            redirect('/admin/users/create'); return;
        }
        if ($this->AIWorkforce_model->identity->findUserByEmail($email) || $this->AIWorkforce_model->identity->usernameTaken($username)) {
            $this->flash('error', 'That username or email is already in use.');
            redirect('/admin/users/create'); return;
        }
        $now = gmdate('c');
        $new = $this->AIWorkforce_model->identity->createUser([
            'username' => $username, 'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'display_name' => $username, 'active' => 1,
            'created_at' => $now, 'updated_at' => $now, 'last_login_at' => null,
        ]);
        $role = $this->AIWorkforce_model->identity->ensureRole($roleCode, ucwords(str_replace('_', ' ', $roleCode)));
        $this->AIWorkforce_model->identity->assignRole((int) $new['id'], $role);
        $this->portal->log($actor, 'USER_CREATED', 'ok', $this->portal->userTarget($new), ['role' => $roleCode], $this->ip());
        $this->flash('notice', 'User account created. User ID ' . ($new['user_uid'] ?? '') . '. The temporary password is not stored and will not be shown again.');
        redirect('/admin/users/' . (int) $new['id']);
    }

    public function edit_user($id = 0)
    {
        $actor = $this->gate('admin.users.manage'); if (!$actor) return;
        $member = $this->findPublicUser((int) $id);
        if (!$member) { $this->flash('error', 'User not found.'); redirect('/admin/users'); return; }
        if ($this->input->method(true) !== 'POST') {
            $data = $this->base('Edit user', 'users');
            $data['member'] = $member;
            $data['roles'] = $this->portal->assignableRoles($actor);
            $data['currentRole'] = $this->portal->primaryRole($member);
            $this->render('admin/users/edit', $data);
            return;
        }
        if (!$this->validCsrf()) { $this->flash('error', 'Invalid security token.'); redirect('/admin/users/' . (int) $id . '/edit'); return; }
        $username = strtolower(trim((string) $this->input->post('username')));
        $email = strtolower(trim((string) $this->input->post('email')));
        $display = trim((string) $this->input->post('display_name'));
        $roleCode = trim((string) $this->input->post('role'));
        if (!$this->validUsername($username) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->flash('error', 'Enter a valid username and email address.');
            redirect('/admin/users/' . (int) $id . '/edit'); return;
        }
        if ($this->AIWorkforce_model->identity->usernameTaken($username, (int) $id) || $this->AIWorkforce_model->identity->emailTaken($email, (int) $id)) {
            $this->flash('error', 'That username or email is already in use.');
            redirect('/admin/users/' . (int) $id . '/edit'); return;
        }
        $patch = ['username' => $username, 'email' => $email, 'display_name' => $display !== '' ? $display : $username];
        $this->AIWorkforce_model->identity->updateUser((int) $id, $patch);
        if ($roleCode !== '' && $this->roleAllowed($actor, $roleCode)) {
            $role = $this->AIWorkforce_model->identity->findRoleByCode($roleCode);
            if ($role) $this->AIWorkforce_model->identity->replaceUserRoles((int) $id, [(int) $role['id']]);
        }
        $this->handleAvatarUpload((int) $id, $member);
        $fresh = $this->findPublicUser((int) $id);
        $this->portal->log($actor, 'USER_EDITED', 'ok', $this->portal->userTarget($fresh ?: $member), [], $this->ip());
        $this->flash('notice', 'User account updated.');
        redirect('/admin/users/' . (int) $id);
    }

    public function suspend_user($id = 0)
    {
        $actor = $this->gate('admin.users.manage'); if (!$actor) return;
        $this->setStatus((int) $id, $actor, false);
    }

    public function activate_user($id = 0)
    {
        $actor = $this->gate('admin.users.manage'); if (!$actor) return;
        $this->setStatus((int) $id, $actor, true);
    }

    /** Kept for the previous toggle route used by older bookmarks. */
    public function toggle_user(int $id)
    {
        $actor = $this->gate('admin.users.manage'); if (!$actor) return;
        $target = $this->AIWorkforce_model->identity->findUserById($id);
        if (!$target) { $this->flash('error', 'User not found.'); redirect('/admin/users'); return; }
        $this->setStatus($id, $actor, empty($target['active']));
    }

    public function delete_user($id = 0)
    {
        $actor = $this->gate('admin.users.delete'); if (!$actor) return;
        if (!$this->validCsrf()) { $this->flash('error', 'Invalid security token.'); redirect('/admin/users'); return; }
        $id = (int) $id;
        if ((int) $actor['id'] === $id) { $this->flash('error', 'You cannot delete your own account.'); redirect('/admin/users/' . $id); return; }
        $target = $this->findPublicUser($id);
        if (!$target) { $this->flash('error', 'User not found.'); redirect('/admin/users'); return; }
        if ($this->AIWorkforce_model->identity->userHasRole($id, 'super_admin') && $this->AIWorkforce_model->identity->countSuperAdmins() <= 1) {
            $this->flash('error', 'The last Super Admin account cannot be deleted.');
            redirect('/admin/users/' . $id); return;
        }
        $ok = $this->AIWorkforce_model->identity->deleteUser($id);
        $this->portal->log($actor, 'USER_DELETED', $ok ? 'ok' : 'error', $this->portal->userTarget($target), [], $this->ip());
        if (!$ok) {
            $this->flash('error', 'This account could not be deleted because related records still exist. Suspend the account instead.');
            redirect('/admin/users/' . $id); return;
        }
        $this->flash('notice', 'User account deleted.');
        redirect('/admin/users');
    }

    public function reset_password($id = 0)
    {
        $actor = $this->gate('admin.users.manage'); if (!$actor) return;
        if (!$this->validCsrf()) { $this->flash('error', 'Invalid security token.'); redirect('/admin/users/' . (int) $id); return; }
        $target = $this->findPublicUser((int) $id);
        if (!$target) { $this->flash('error', 'User not found.'); redirect('/admin/users'); return; }
        $temporary = $this->randomPassword();
        $this->AIWorkforce_model->identity->updateUser((int) $id, ['password_hash' => password_hash($temporary, PASSWORD_DEFAULT)]);
        $this->portal->log($actor, 'PASSWORD_RESET', 'ok', $this->portal->userTarget($target), [], $this->ip());
        $this->session->set_flashdata('temp_password', $temporary);
        $this->flash('notice', 'Password reset. Give the user the temporary password shown below. It is not stored and will not be shown again.');
        redirect('/admin/users/' . (int) $id);
    }

    public function impersonate($id = 0)
    {
        $actor = $this->gate('admin.users.impersonate'); if (!$actor) return;
        if (!$this->validCsrf()) { $this->flash('error', 'Invalid security token.'); redirect('/admin/users'); return; }
        $target = $this->AIWorkforce_model->identity->findUserById((int) $id);
        if (!$target) { $this->flash('error', 'User not found.'); redirect('/admin/users'); return; }
        $public = $this->portal->publicUser($target);
        $public['permissions'] = $this->AIWorkforce_model->identity->permissionsForUser((int) $id);
        $public['roles'] = $this->AIWorkforce_model->identity->rolesForUser((int) $id);
        if (!$this->canImpersonate($actor, $public)) {
            $this->portal->log($actor, 'IMPERSONATION_STARTED', 'denied', $this->portal->userTarget($public), [], $this->ip());
            $this->flash('error', 'You cannot sign in as that account.');
            redirect('/admin/users/' . (int) $id); return;
        }
        $sessionId = $this->portal->startImpersonation($actor, $public, $this->ip());
        $this->session->sess_regenerate(true);
        $this->session->set_userdata([
            'identity' => $public,
            'impersonator' => $actor,
            'impersonation_id' => $sessionId,
            'csrf_token' => bin2hex(random_bytes(32)),
        ]);
        redirect('/dashboard');
    }

    /** Return from a user session to the original administrator. Must not require admin.access on the impersonated user. */
    public function stop_impersonation()
    {
        $current = $this->currentUser();
        $admin = $this->impersonator();
        if (!$current || !$admin) { redirect('/admin'); return; }
        if (!$this->validCsrf()) { $this->flash('error', 'Invalid security token.'); redirect('/dashboard'); return; }
        $sessionId = (int) $this->session->userdata('impersonation_id');
        if ($sessionId > 0) $this->portal->endImpersonation($sessionId, $admin, $current, $this->ip());
        $fresh = $this->AIWorkforce_model->identity->findUserById((int) $admin['id']);
        if (!$fresh || empty($fresh['active'])) {
            $this->session->sess_destroy();
            $this->flash('error', 'The administrator session could not be restored.');
            redirect('/admin/login'); return;
        }
        $fresh['permissions'] = $this->AIWorkforce_model->identity->permissionsForUser((int) $fresh['id']);
        unset($fresh['password_hash']);
        $this->session->sess_regenerate(true);
        $this->session->unset_userdata(['impersonator', 'impersonation_id']);
        $this->session->set_userdata(['identity' => $fresh, 'csrf_token' => bin2hex(random_bytes(32))]);
        $this->flash('notice', 'Returned to your administrator account.');
        redirect('/admin/users/' . (int) $current['id']);
    }

    public function search()
    {
        $actor = $this->gate('admin.users.view'); if (!$actor) return;
        $q = trim((string) $this->input->get('q', true));
        $data = $this->base('Search', 'users');
        $data['q'] = $q;
        $data['results'] = $q === '' ? [] : $this->portal->searchUsers($q, 30);
        $this->render('admin/search', $data);
    }

    public function workforce()
    {
        $actor = $this->requireAdmin(); if (!$actor) return;
        $data = $this->base('AI Workforce', 'workforce');
        $data['overview'] = $this->portal->workforceOverview();
        $this->render('admin/workforce', $data);
    }

    public function languages()
    {
        $actor = $this->requireAdmin(); if (!$actor) return;
        $data = $this->base('Language Learning', 'languages');
        $data['overview'] = $this->portal->languageOverview();
        $this->render('admin/languages', $data);
    }

    public function conversations()
    {
        $actor = $this->requireAdmin(); if (!$actor) return;
        $data = $this->base('Conversations', 'conversations');
        $data['overview'] = $this->portal->conversationOverview();
        $this->render('admin/conversations', $data);
    }

    public function analytics()
    {
        $actor = $this->gate('admin.analytics.view'); if (!$actor) return;
        $data = $this->base('Analytics', 'analytics');
        $data['overview'] = $this->portal->analyticsOverview();
        $this->render('admin/analytics', $data);
    }

    public function notifications()
    {
        $actor = $this->requireAdmin(); if (!$actor) return;
        $inbox = $this->platform->notifications->inbox((int) $actor['id'], false, 80);
        $data = $this->base('Notifications', 'notifications');
        $data['inbox'] = $inbox;
        $this->render('admin/notifications', $data);
    }

    public function reports()
    {
        $actor = $this->gate('admin.analytics.view'); if (!$actor) return;
        $data = $this->base('Reports', 'reports');
        $data['overview'] = $this->portal->analyticsOverview();
        $data['stats'] = $this->portal->dashboardStats();
        $this->render('admin/reports', $data);
    }

    public function settings()
    {
        $actor = $this->gate('admin.settings.manage'); if (!$actor) return;
        $data = $this->base('System Settings', 'settings');
        $data['settings'] = $this->portal->settingsByCategory();
        $data['smtp'] = \AIWorkforce\Mailer::configSummary();
        $data['smtpOk'] = $this->session->flashdata('smtpOk');
        $data['smtpError'] = $this->session->flashdata('smtpError');
        $data['session'] = [
            'expiration' => (int) $this->config->item('sess_expiration'),
            'httponly' => (bool) $this->config->item('cookie_httponly'),
            'samesite' => (string) $this->config->item('sess_samesite'),
            'secure' => (bool) $this->config->item('cookie_secure'),
        ];
        $this->render('admin/settings', $data);
    }

    public function settings_save()
    {
        $actor = $this->gate('admin.settings.manage'); if (!$actor) return;
        if (!$this->validCsrf()) { $this->flash('error', 'Invalid security token.'); redirect('/admin/settings'); return; }
        $category = (string) $this->input->post('category');
        if (!isset(\AIWorkforce\AdminPortal::SETTING_DEFAULTS[$category])) {
            $this->flash('error', 'Unknown settings category.'); redirect('/admin/settings'); return;
        }
        $values = [];
        foreach (array_keys(\AIWorkforce\AdminPortal::SETTING_DEFAULTS[$category]) as $key) {
            $values[$key] = (string) $this->input->post($key);
        }
        if ($category === 'ai' || $category === 'accounts') {
            foreach ($values as $k => $v) $values[$k] = $v === '1' ? '1' : '0';
        }
        if ($category === 'security') {
            $values['login_max_attempts'] = (string) max(3, min(20, (int) ($values['login_max_attempts'] ?? 5)));
            $values['login_lockout_seconds'] = (string) max(60, min(86400, (int) ($values['login_lockout_seconds'] ?? 900)));
        }
        try {
            $this->portal->saveSettings($values, $category, (int) $actor['id']);
            $this->portal->log($actor, 'SETTINGS_CHANGED', 'ok', ['type' => 'settings', 'id' => $category, 'label' => $category], array_keys($values), $this->ip());
            $this->flash('notice', '✓ Changes saved successfully');
        } catch (Throwable $e) {
            log_message('error', 'admin settings_save failed: ' . $e->getMessage());
            $this->flash('error', 'Unable to save your changes. Please try again.');
        }
        redirect('/admin/settings#' . $category);
    }

    public function test_email()
    {
        $actor = $this->gate('admin.settings.manage'); if (!$actor) return;
        if (!$this->validCsrf()) { $this->flash('error', 'Invalid security token.'); redirect('/admin/settings'); return; }
        $to = strtolower(trim((string) $this->input->post('to')));
        $variant = $this->input->post('variant') === 'plain' ? 'plain' : 'html';
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->flash('error', 'Enter a valid recipient email address.');
            redirect('/admin/settings#email'); return;
        }
        $res = \AIWorkforce\Mailer::sendTest($this, $to, $variant);
        $this->portal->log($actor, 'SETTINGS_CHANGED', $res['ok'] ? 'ok' : 'error', ['type' => 'email', 'id' => 'test', 'label' => $to], ['ok' => $res['ok']], $this->ip());
        $this->session->set_flashdata($res['ok'] ? 'smtpOk' : 'smtpError', $res['message']);
        redirect('/admin/settings#email');
    }

    public function admins()
    {
        $actor = $this->gate('admin.admins.manage'); if (!$actor) return;
        $data = $this->base('Admin Accounts', 'admins');
        $data['admins'] = $this->AIWorkforce_model->identity->listAdminAccounts();
        $data['adminRoles'] = array_values(array_filter(
            $this->AIWorkforce_model->identity->listRoles(),
            fn($r) => in_array($r['code'], \AIWorkforce\AdminPortal::ADMIN_ROLES, true)
        ));
        $this->render('admin/admins', $data);
    }

    public function create_admin()
    {
        $actor = $this->gate('admin.admins.manage'); if (!$actor) return;
        if (!$this->validCsrf()) { $this->flash('error', 'Invalid security token.'); redirect('/admin/admins'); return; }
        $username = strtolower(trim((string) $this->input->post('username')));
        $email = strtolower(trim((string) $this->input->post('email')));
        $password = (string) $this->input->post('password');
        $roleCode = trim((string) $this->input->post('role'));
        if (!in_array($roleCode, \AIWorkforce\AdminPortal::ADMIN_ROLES, true)) $roleCode = 'admin';
        if (!$this->validUsername($username) || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
            $this->flash('error', 'Enter a valid username, email and a password of at least 12 characters.');
            redirect('/admin/admins'); return;
        }
        if ($this->AIWorkforce_model->identity->findUserByEmail($email) || $this->AIWorkforce_model->identity->usernameTaken($username)) {
            $this->flash('error', 'That username or email is already in use.');
            redirect('/admin/admins'); return;
        }
        $now = gmdate('c');
        $new = $this->AIWorkforce_model->identity->createUser([
            'username' => $username, 'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'display_name' => $username, 'active' => 1,
            'created_at' => $now, 'updated_at' => $now, 'last_login_at' => null,
        ]);
        $role = $this->AIWorkforce_model->identity->ensureRole($roleCode, ucwords(str_replace('_', ' ', $roleCode)));
        $this->AIWorkforce_model->identity->assignRole((int) $new['id'], $role);
        $this->portal->log($actor, 'ADMIN_CREATED', 'ok', $this->portal->userTarget($new), ['role' => $roleCode], $this->ip());
        $this->flash('notice', 'Administrator account created. The temporary password is not stored.');
        redirect('/admin/admins');
    }

    public function update_admin($id = 0)
    {
        $actor = $this->gate('admin.admins.manage'); if (!$actor) return;
        if (!$this->validCsrf()) { $this->flash('error', 'Invalid security token.'); redirect('/admin/admins'); return; }
        $id = (int) $id;
        $target = $this->findPublicUser($id);
        if (!$target) { $this->flash('error', 'Administrator not found.'); redirect('/admin/admins'); return; }
        $roleCode = trim((string) $this->input->post('role'));
        $status = $this->input->post('active') === '1';
        if (!in_array($roleCode, \AIWorkforce\AdminPortal::ADMIN_ROLES, true)) {
            $this->flash('error', 'Choose a valid administrator role.'); redirect('/admin/admins'); return;
        }
        if ((int) $actor['id'] === $id && !$status) {
            $this->flash('error', 'You cannot disable your own administrator account.'); redirect('/admin/admins'); return;
        }
        if (!$status && $this->AIWorkforce_model->identity->userHasRole($id, 'super_admin') && $this->AIWorkforce_model->identity->countSuperAdmins() <= 1) {
            $this->flash('error', 'The last Super Admin cannot be disabled.'); redirect('/admin/admins'); return;
        }
        $role = $this->AIWorkforce_model->identity->findRoleByCode($roleCode);
        if ($role) $this->AIWorkforce_model->identity->replaceUserRoles($id, [(int) $role['id']]);
        $this->AIWorkforce_model->identity->setActive($id, $status);
        $this->portal->log($actor, 'ADMIN_PERMISSIONS_CHANGED', 'ok', $this->portal->userTarget($target), ['role' => $roleCode, 'active' => $status], $this->ip());
        $this->flash('notice', 'Administrator account updated.');
        redirect('/admin/admins');
    }

    public function logs()
    {
        $actor = $this->gate('admin.logs.view'); if (!$actor) return;
        $q = trim((string) $this->input->get('q', true));
        $action = trim((string) $this->input->get('action', true));
        $page = max(1, (int) $this->input->get('page'));
        $result = $this->portal->activityLogs(['q' => $q, 'action' => $action], $page, 30);
        $data = $this->base('Activity Logs', 'logs');
        $data = array_merge($data, $result);
        $data['q'] = $q;
        $data['actionFilter'] = $action;
        $this->render('admin/logs', $data);
    }

    public function security()
    {
        $actor = $this->gate('admin.security.view'); if (!$actor) return;
        $data = $this->base('Security', 'security');
        $data['overview'] = $this->portal->securityOverview();
        $data['session'] = [
            'expiration' => (int) $this->config->item('sess_expiration'),
            'httponly' => (bool) $this->config->item('cookie_httponly'),
            'samesite' => (string) $this->config->item('sess_samesite'),
            'secure' => (bool) $this->config->item('cookie_secure'),
            'regenerate' => (bool) $this->config->item('sess_regenerate_destroy'),
        ];
        $this->render('admin/security', $data);
    }

    public function api()
    {
        $actor = $this->gate('admin.api.view'); if (!$actor) return;
        \AIWorkforce\ApiProviders::ensureSchema($this->AIWorkforce_model->db);
        $data = $this->base('API Management', 'api');
        $data['dashboard'] = \AIWorkforce\ApiProviders::dashboard($this->AIWorkforce_model->db);
        $data['canManage'] = $this->platform->identity->can($actor, 'admin.api.manage');
        $data['canTest'] = $this->platform->identity->can($actor, 'admin.api.test');
        $this->render('admin/api/index', $data);
    }

    public function api_create()
    {
        $actor = $this->gate('admin.api.manage'); if (!$actor) return;
        $this->renderApiForm($actor, null);
    }

    public function api_show($id = 0)
    {
        $actor = $this->gate('admin.api.view'); if (!$actor) return;
        \AIWorkforce\ApiProviders::ensureSchema($this->AIWorkforce_model->db);
        $row = \AIWorkforce\ApiProviders::find($this->AIWorkforce_model->db, (int) $id);
        if (!$row) { $this->flash('error', 'Provider not found.'); redirect('/admin/api'); return; }
        $this->renderApiForm($actor, $row);
    }

    public function api_save($id = 0)
    {
        $actor = $this->gate('admin.api.manage'); if (!$actor) return;
        if (!$this->validCsrf()) { $this->flash('error', 'Invalid security token.'); redirect('/admin/api'); return; }
        $canSecrets = $this->platform->identity->can($actor, 'admin.api.credentials') || $this->isSuperAdmin($actor);
        try {
            $saved = \AIWorkforce\ApiProviders::save($this->AIWorkforce_model->db, $this->input->post() ?: [], $id ? (int) $id : null, (int) $actor['id'], $canSecrets);
            $this->portal->log($actor, $id ? 'API_PROVIDER_UPDATED' : 'API_PROVIDER_CREATED', 'ok', [
                'type' => 'api_provider', 'id' => (string) ($saved['id'] ?? ''), 'label' => (string) ($saved['label'] ?? ''),
            ], ['service' => $saved['service'] ?? '', 'driver' => $saved['driver'] ?? '', 'role' => $saved['role'] ?? ''], $this->ip());
            $this->flash('notice', '✓ Changes saved successfully');
            redirect('/admin/api/' . (int) ($saved['id'] ?? 0));
        } catch (Throwable $e) {
            log_message('error', 'api_save failed: ' . $e->getMessage());
            $this->flash('error', $e instanceof InvalidArgumentException ? $e->getMessage() : 'Unable to save your changes. Please try again.');
            redirect($id ? '/admin/api/' . (int) $id : '/admin/api/create');
        }
    }

    public function api_test($id = 0)
    {
        $actor = $this->gate('admin.api.test'); if (!$actor) return;
        if (!$this->validCsrf()) { $this->flash('error', 'Invalid security token.'); redirect('/admin/api'); return; }
        $row = \AIWorkforce\ApiProviders::find($this->AIWorkforce_model->db, (int) $id);
        if (!$row) { $this->flash('error', 'Provider not found.'); redirect('/admin/api'); return; }
        $secrets = \AIWorkforce\ApiProviders::findSecrets($this->AIWorkforce_model->db, (int) $id);
        $result = \AIWorkforce\ApiProviders::test($row, $secrets);
        \AIWorkforce\ApiProviders::recordTest($this->AIWorkforce_model->db, (int) $id, $result);
        $this->portal->log($actor, 'API_PROVIDER_TESTED', !empty($result['ok']) ? 'ok' : 'error', [
            'type' => 'api_provider', 'id' => (string) $id, 'label' => $row['label'],
        ], ['ok' => !empty($result['ok']), 'ms' => $result['ms'] ?? null], $this->ip());
        $this->flash(!empty($result['ok']) ? 'notice' : 'error', !empty($result['ok']) ? '✓ Connected' : '✕ Connection failed');
        redirect('/admin/api/' . (int) $id);
    }

    public function api_enable($id = 0)
    {
        $this->apiToggle((int) $id, true);
    }

    public function api_disable($id = 0)
    {
        $this->apiToggle((int) $id, false);
    }

    public function api_primary($id = 0)
    {
        $actor = $this->gate('admin.api.manage'); if (!$actor) return;
        if (!$this->validCsrf()) { $this->flash('error', 'Invalid security token.'); redirect('/admin/api'); return; }
        $row = \AIWorkforce\ApiProviders::find($this->AIWorkforce_model->db, (int) $id);
        if (!$row) { $this->flash('error', 'Provider not found.'); redirect('/admin/api'); return; }
        \AIWorkforce\ApiProviders::setRole($this->AIWorkforce_model->db, (int) $id, 'primary');
        $this->portal->log($actor, 'API_PROVIDER_UPDATED', 'ok', ['type' => 'api_provider', 'id' => (string) $id, 'label' => $row['label']], ['role' => 'primary'], $this->ip());
        $this->flash('notice', '✓ Changes saved successfully');
        redirect('/admin/api');
    }

    public function api_delete($id = 0)
    {
        $actor = $this->gate('admin.api.manage'); if (!$actor) return;
        if (!$this->validCsrf()) { $this->flash('error', 'Invalid security token.'); redirect('/admin/api'); return; }
        $row = \AIWorkforce\ApiProviders::find($this->AIWorkforce_model->db, (int) $id);
        if (!$row) { $this->flash('error', 'Provider not found.'); redirect('/admin/api'); return; }
        \AIWorkforce\ApiProviders::delete($this->AIWorkforce_model->db, (int) $id);
        $this->portal->log($actor, 'API_PROVIDER_DELETED', 'ok', ['type' => 'api_provider', 'id' => (string) $id, 'label' => $row['label']], ['service' => $row['service']], $this->ip());
        $this->flash('notice', '✓ Changes saved successfully');
        redirect('/admin/api');
    }

    public function add_language()
    {
        $actor = $this->isSuperAdmin($this->currentUser());
        if (!$actor) {
            $this->flash('error', 'Super admin access required.');
            redirect('/admin');
            return;
        }

        $code = strtolower(trim((string) $this->input->post('code')));
        $name = trim((string) $this->input->post('name'));
        $native_name = trim((string) ($this->input->post('native_name') ?: ''));
        $writing_system = trim((string) $this->input->post('writing_system'));
        $direction = strtolower(trim((string) $this->input->post('direction')));

        // Validate language code format (ISO 639-1/3: 2-3 lowercase letters)
        if (!preg_match('/^[a-z]{2,3}$/', $code)) {
            $this->flash('error', 'Language code must be 2-3 lowercase letters (ISO 639-1/3 format).');
            redirect('/admin/languages');
            return;
        }

        // Validate required fields
        if ($name === '') {
            $this->flash('error', 'English name is required.');
            redirect('/admin/languages');
            return;
        }

        if (!in_array($writing_system, ['latin', 'cyrillic', 'devanagari', 'arabic', 'han', 'kana', 'hangul'])) {
            $this->flash('error', 'Invalid writing system.');
            redirect('/admin/languages');
            return;
        }

        if (!in_array($direction, ['ltr', 'rtl'])) {
            $this->flash('error', 'Invalid direction.');
            redirect('/admin/languages');
            return;
        }

        // Register the language using the LanguageRegistry
        try {
            \AIWorkforce\LangLearn\LanguageRegistry::register([
                'code' => $code,
                'name' => $name,
                'native_name' => $native_name !== '' ? $native_name : $name,
                'iso_code' => $code,
                'writing_system' => $writing_system,
                'direction' => $direction,
                'active' => true,
            ]);
            $this->flash('notice', "Language '{$name}' ({$code}) registered successfully.");
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            $this->flash('error', 'Failed to register language: ' . $e->getMessage());
        }

        redirect('/admin/languages');
    }

    private function apiToggle(int $id, bool $enabled): void
    {
        $actor = $this->gate('admin.api.manage'); if (!$actor) return;
        if (!$this->validCsrf()) { $this->flash('error', 'Invalid security token.'); redirect('/admin/api'); return; }
        $row = \AIWorkforce\ApiProviders::find($this->AIWorkforce_model->db, $id);
        if (!$row) { $this->flash('error', 'Provider not found.'); redirect('/admin/api'); return; }
        \AIWorkforce\ApiProviders::setEnabled($this->AIWorkforce_model->db, $id, $enabled);
        $this->portal->log($actor, $enabled ? 'API_PROVIDER_ENABLED' : 'API_PROVIDER_DISABLED', 'ok', [
            'type' => 'api_provider', 'id' => (string) $id, 'label' => $row['label'],
        ], [], $this->ip());
        $this->flash('notice', '✓ Changes saved successfully');
        redirect('/admin/api');
    }

    private function renderApiForm(array $actor, ?array $row): void
    {
        $data = $this->base($row ? 'Manage provider' : 'Add provider', 'api');
        $data['row'] = $row;
        $data['services'] = \AIWorkforce\ApiProviders::services();
        $data['drivers'] = \AIWorkforce\ApiProviders::drivers();
        $data['canManage'] = $this->platform->identity->can($actor, 'admin.api.manage');
        $data['canTest'] = $this->platform->identity->can($actor, 'admin.api.test');
        $data['canSecrets'] = $this->platform->identity->can($actor, 'admin.api.credentials') || $this->isSuperAdmin($actor);
        $this->render('admin/api/form', $data);
    }

    /** Only administrators with portal access may enter. Super Admin holds system.super_admin. */
    private function requireAdmin(): ?array
    {
        return $this->requireAdminPage();
    }

    private function gate(string $permission): ?array
    {
        return $this->requireAdminPermission($permission);
    }

    private function setStatus(int $id, array $actor, bool $active): void
    {
        if (!$this->validCsrf()) { $this->flash('error', 'Invalid security token.'); redirect('/admin/users'); return; }
        if ((int) $actor['id'] === $id && !$active) {
            $this->flash('error', 'You cannot suspend your own administrator account.');
            redirect('/admin/users/' . $id); return;
        }
        $target = $this->findPublicUser($id);
        if (!$target) { $this->flash('error', 'User not found.'); redirect('/admin/users'); return; }
        if (!$active && $this->AIWorkforce_model->identity->userHasRole($id, 'super_admin') && $this->AIWorkforce_model->identity->countSuperAdmins() <= 1) {
            $this->flash('error', 'The last Super Admin cannot be suspended.');
            redirect('/admin/users/' . $id); return;
        }
        $this->AIWorkforce_model->identity->setActive($id, $active);
        $this->portal->log($actor, $active ? 'USER_ACTIVATED' : 'USER_SUSPENDED', 'ok', $this->portal->userTarget($target), [], $this->ip());
        $this->flash('notice', $active ? 'Account activated.' : 'Account suspended. The user can no longer sign in.');
        redirect('/admin/users/' . $id);
    }

    private function canImpersonate(array $actor, array $target): bool
    {
        if ((int) $actor['id'] === (int) $target['id']) return false;
        if (empty($target['active'])) return false;
        if (!$this->platform->identity->can($actor, 'admin.users.impersonate')) return false;
        if ($this->isSuperAdmin($actor)) return true;
        return !$this->portal->isAdminAccount($target);
    }

    private function roleAllowed(array $actor, string $roleCode): bool
    {
        foreach ($this->portal->assignableRoles($actor) as $role) {
            if ($role['code'] === $roleCode) return true;
        }
        return false;
    }

    private function findPublicUser(int $id): ?array
    {
        $user = $this->AIWorkforce_model->identity->findUserById($id);
        if (!$user) return null;
        $user = $this->portal->publicUser($user);
        $user['permissions'] = $this->AIWorkforce_model->identity->permissionsForUser($id);
        $user['roles'] = $this->AIWorkforce_model->identity->rolesForUser($id);
        return $user;
    }

    private function handleAvatarUpload(int $userId, array $member): void
    {
        if (empty($_FILES['avatar']) || (int) ($_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return;
        $tmp = (string) $_FILES['avatar']['tmp_name'];
        $size = (int) $_FILES['avatar']['size'];
        if ($size <= 0 || $size > 2 * 1024 * 1024) return;
        $info = @getimagesize($tmp);
        if ($info === false) return;
        $allowed = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
        $ext = $allowed[$info[2]] ?? null;
        if ($ext === null) return;
        $uploadDir = FCPATH . 'assets/uploads/avatars';
        if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);
        if (!is_dir($uploadDir) || !is_writable($uploadDir)) return;
        $filename = 'u' . $userId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (!@move_uploaded_file($tmp, $uploadDir . '/' . $filename)) return;
        $path = '/assets/uploads/avatars/' . $filename;
        $this->AIWorkforce_model->identity->updateUser($userId, ['profile_image' => $path]);
        $previous = (string) ($member['profile_image'] ?? '');
        if ($previous !== '' && str_starts_with($previous, '/assets/uploads/avatars/')) {
            $old = FCPATH . ltrim($previous, '/');
            if ($old !== $uploadDir . '/' . $filename && is_file($old)) @unlink($old);
        }
    }

    private function validUsername(string $username): bool
    {
        return (bool) preg_match('/^[a-z][a-z0-9_]{2,19}$/', $username);
    }

    private function randomPassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $out = '';
        for ($i = 0; $i < 16; $i++) $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        return $out;
    }

    private function validCsrf(): bool
    {
        $sent = (string) $this->input->post('csrf_token');
        $known = (string) $this->session->userdata('csrf_token');
        return $sent !== '' && $known !== '' && hash_equals($known, $sent);
    }

    private function ip(): string
    {
        return (string) $this->input->ip_address();
    }

    private function base(string $title, string $active): array
    {
        $state = $this->platform->state();
        $identity = $this->currentUser();
        return [
            'title' => $title,
            'active' => $active,
            'adminUser' => $identity,
            'adminPerms' => $identity['permissions'] ?? [],
            'isSuperAdmin' => $this->isSuperAdmin($identity),
            'status' => ['tradingMode' => $state['tradingMode'], 'killSwitch' => $state['killSwitch'], 'providers' => $this->platform->providers->getAllHealth()],
            'csrfToken' => (string) $this->session->userdata('csrf_token'),
            'notice' => $this->session->flashdata('notice'),
            'error' => $this->session->flashdata('error'),
            'tempPassword' => $this->session->flashdata('temp_password'),
            'productName' => $this->portal->setting('product_name', 'WINDELS AI WORKFORCE'),
        ];
    }

    private function render(string $view, array $data): void
    {
        $this->load->view('admin/layout/header', $data);
        $this->load->view($view, $data);
        $this->load->view('admin/layout/footer');
    }

    private function flash(string $key, string $msg): void
    {
        $this->session->set_flashdata($key, $msg);
    }
}
