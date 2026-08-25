<?php
namespace AIWorkforce;

/**
 * Administrator portal domain service. SQL stays in AIWorkforce_model; this class
 * never exposes passwords, hashes, SMTP secrets or API keys.
 */
class AdminPortal
{
    public const ADMIN_ROLES = ['super_admin', 'admin', 'support_admin'];

    public const MEMBER_ROLES = [
        'platform_member', 'trading_operator', 'trading_viewer',
        'sports_admin', 'sports_viewer', 'lottery_admin', 'lottery_viewer',
    ];

    public const SETTING_DEFAULTS = [
        'general' => [
            'product_name' => 'WINDELS AI WORKFORCE',
            'contact_email' => '',
            'contact_name' => '',
        ],
        'ai' => [
            'ai_analysis_enabled' => '1',
            'language_learning_enabled' => '1',
        ],
        'security' => [
            'login_max_attempts' => '5',
            'login_lockout_seconds' => '900',
        ],
        'accounts' => [
            'registration_enabled' => '1',
        ],
    ];

    public function __construct(private \AIWorkforce_model $model) {}

    /** Create portal tables on upgraded installs that have not re-run the installer. */
    public function ensureSchema(): void
    {
        $db = $this->model->db;
        $driver = (string) $db->dbdriver;
        $isSqlite = str_contains($driver, 'sqlite');
        if ($isSqlite) {
            $db->query("CREATE TABLE IF NOT EXISTS admin_activity_logs (
              id INTEGER PRIMARY KEY AUTOINCREMENT, admin_id INTEGER NOT NULL, admin_label TEXT NOT NULL,
              action TEXT NOT NULL, target_type TEXT, target_id TEXT, target_label TEXT,
              result TEXT NOT NULL DEFAULT 'ok', ip TEXT, detail TEXT, created_at TEXT NOT NULL)");
            $db->query("CREATE TABLE IF NOT EXISTS impersonation_sessions (
              id INTEGER PRIMARY KEY AUTOINCREMENT, admin_id INTEGER NOT NULL, target_user_id INTEGER NOT NULL,
              started_at TEXT NOT NULL, ended_at TEXT, ip TEXT)");
            $db->query("CREATE TABLE IF NOT EXISTS platform_settings (
              k TEXT NOT NULL PRIMARY KEY, v TEXT NOT NULL, category TEXT NOT NULL DEFAULT 'general',
              updated_at TEXT NOT NULL, updated_by INTEGER)");
        } else {
            $db->query("CREATE TABLE IF NOT EXISTS admin_activity_logs (
              id INT AUTO_INCREMENT PRIMARY KEY, admin_id INT NOT NULL, admin_label VARCHAR(190) NOT NULL,
              action VARCHAR(64) NOT NULL, target_type VARCHAR(32) NULL, target_id VARCHAR(64) NULL,
              target_label VARCHAR(190) NULL, result VARCHAR(16) NOT NULL DEFAULT 'ok', ip VARCHAR(45) NULL,
              detail LONGTEXT NULL, created_at VARCHAR(32) NOT NULL,
              INDEX idx_admin_logs_created (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $db->query("CREATE TABLE IF NOT EXISTS impersonation_sessions (
              id INT AUTO_INCREMENT PRIMARY KEY, admin_id INT NOT NULL, target_user_id INT NOT NULL,
              started_at VARCHAR(32) NOT NULL, ended_at VARCHAR(32) NULL, ip VARCHAR(45) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $db->query("CREATE TABLE IF NOT EXISTS platform_settings (
              k VARCHAR(80) NOT NULL PRIMARY KEY, v LONGTEXT NOT NULL, category VARCHAR(32) NOT NULL DEFAULT 'general',
              updated_at VARCHAR(32) NOT NULL, updated_by INT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        \AIWorkforce\ApiProviders::ensureSchema($db);
    }

    public function setting(string $key, string $default = ''): string
    {
        $row = $this->model->db->get_where('platform_settings', ['k' => $key], 1)->row_array();
        return $row ? (string) $row['v'] : $default;
    }

    public function settingsByCategory(): array
    {
        $out = self::SETTING_DEFAULTS;
        $rows = $this->model->db->get('platform_settings')->result_array();
        foreach ($rows as $row) {
            $cat = (string) ($row['category'] ?? 'general');
            if (!isset($out[$cat])) $out[$cat] = [];
            $out[$cat][(string) $row['k']] = (string) $row['v'];
        }
        return $out;
    }

    public function saveSettings(array $values, string $category, ?int $actorId): void
    {
        $allowed = self::SETTING_DEFAULTS[$category] ?? [];
        foreach ($values as $key => $value) {
            if (!array_key_exists($key, $allowed)) continue;
            $value = is_string($value) ? trim($value) : (string) $value;
            if (strlen($value) > 500) $value = substr($value, 0, 500);
            $existing = $this->model->db->get_where('platform_settings', ['k' => $key], 1)->row_array();
            $row = ['k' => $key, 'v' => $value, 'category' => $category, 'updated_at' => gmdate('c'), 'updated_by' => $actorId];
            if ($existing) $this->model->db->where('k', $key)->update('platform_settings', $row);
            else $this->model->db->insert('platform_settings', $row);
        }
    }

    public function log(array $actor, string $action, string $result = 'ok', ?array $target = null, array $detail = [], ?string $ip = null): void
    {
        try {
            $this->model->db->insert('admin_activity_logs', [
                'admin_id' => (int) ($actor['id'] ?? 0),
                'admin_label' => $this->label($actor),
                'action' => mb_substr($action, 0, 64),
                'target_type' => $target['type'] ?? null,
                'target_id' => isset($target['id']) ? (string) $target['id'] : null,
                'target_label' => $target['label'] ?? null,
                'result' => in_array($result, ['ok', 'denied', 'error'], true) ? $result : 'ok',
                'ip' => $ip,
                'detail' => $detail ? json_encode($detail) : null,
                'created_at' => gmdate('c'),
            ]);
        } catch (\Throwable $e) { /* audit must never break the request */ }
        $this->model->audit->emit(
            'ADMIN_' . $action,
            $this->label($actor) . ' ' . strtolower(str_replace('_', ' ', $action)),
            array_merge($detail, ['target' => $target, 'result' => $result, 'ip' => $ip]),
            (string) ($actor['id'] ?? 'admin')
        );
    }

    public function activityLogs(array $filter = [], int $page = 1, int $perPage = 25): array
    {
        $db = $this->model->db;
        if (!empty($filter['action'])) $db->where('action', $filter['action']);
        if (!empty($filter['q'])) {
            $q = $filter['q'];
            $db->group_start()->like('admin_label', $q)->or_like('target_label', $q)->or_like('action', $q)->group_end();
        }
        $total = (int) $db->count_all_results('admin_activity_logs');
        $db->reset_query();
        if (!empty($filter['action'])) $db->where('action', $filter['action']);
        if (!empty($filter['q'])) {
            $q = $filter['q'];
            $db->group_start()->like('admin_label', $q)->or_like('target_label', $q)->or_like('action', $q)->group_end();
        }
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $rows = $db->order_by('id', 'DESC')->limit($perPage, ($page - 1) * $perPage)->get('admin_activity_logs')->result_array();
        foreach ($rows as &$row) {
            $row['detail'] = $row['detail'] ? (json_decode((string) $row['detail'], true) ?: []) : [];
        }
        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $perPage)), 'perPage' => $perPage];
    }

    public function dashboardStats(): array
    {
        $id = $this->model->identity;
        $counts = $id->accountCounts();
        $since7 = gmdate('c', time() - 7 * 86400);
        $since30 = gmdate('c', time() - 30 * 86400);
        return [
            'users' => $counts['total'],
            'active' => $counts['active'],
            'suspended' => $counts['suspended'],
            'newUsers' => $id->countCreatedSince($since7),
            'recentLogins' => $id->countLoggedInSince($since30),
            'aiUsage' => $this->safeCount('analysis_runs'),
            'languageProfiles' => $this->safeCount('user_language_profiles'),
            'languageSessions' => $this->safeCount('study_sessions'),
            'conversations' => $this->safeCount('conversation_sessions'),
            'recentUsers' => $id->recentRegistrations(8),
            'recentAdmin' => $this->activityLogs([], 1, 8)['rows'],
        ];
    }

    public function userProfileBundle(array $user): array
    {
        $uid = (int) $user['id'];
        $profiles = [];
        try { $profiles = $this->model->langlearn->listProfilesByUser($uid); } catch (\Throwable $e) { $profiles = []; }
        $conversations = 0;
        $sessions = 0;
        try {
            foreach ($profiles as $p) {
                $conversations += count($this->model->langlearn->listConversations((int) $p['id'], 100));
            }
            $sessions = $this->countWhere('study_sessions', ['user_id' => $uid]);
        } catch (\Throwable $e) { /* tables may be empty on a fresh install */ }
        $aiRuns = $this->safeCount('analysis_runs');
        return [
            'roles' => $this->model->identity->rolesForUser($uid),
            'permissions' => $this->model->identity->permissionsForUser($uid),
            'authEvents' => $this->model->identity->listAuthEvents($uid, 20),
            'languageProfiles' => $profiles,
            'conversations' => $conversations,
            'studySessions' => $sessions,
            'aiUsageNote' => $aiRuns,
        ];
    }

    public function searchUsers(string $q, int $limit = 12): array
    {
        return $this->model->identity->searchUsers(['q' => $q], 'created_at', 'DESC', 1, $limit)['rows'];
    }

    public function startImpersonation(array $admin, array $target, ?string $ip): int
    {
        $this->model->db->insert('impersonation_sessions', [
            'admin_id' => (int) $admin['id'],
            'target_user_id' => (int) $target['id'],
            'started_at' => gmdate('c'),
            'ended_at' => null,
            'ip' => $ip,
        ]);
        $id = (int) $this->model->db->insert_id();
        $this->log($admin, 'IMPERSONATION_STARTED', 'ok', $this->userTarget($target), [], $ip);
        return $id;
    }

    public function endImpersonation(int $sessionId, array $admin, array $target, ?string $ip): void
    {
        $this->model->db->where('id', $sessionId)->update('impersonation_sessions', ['ended_at' => gmdate('c')]);
        $this->log($admin, 'IMPERSONATION_ENDED', 'ok', $this->userTarget($target), ['sessionId' => $sessionId], $ip);
    }

    public function publicUser(array $user): array
    {
        unset($user['password_hash']);
        return $user;
    }

    public function label(array $user): string
    {
        $name = trim((string) ($user['username'] ?? $user['display_name'] ?? ''));
        $email = (string) ($user['email'] ?? '');
        $uid = (string) ($user['user_uid'] ?? '');
        if ($name !== '') return $uid !== '' ? "{$name} ({$uid})" : $name;
        return $uid !== '' ? "{$email} ({$uid})" : $email;
    }

    public function userTarget(array $user): array
    {
        return ['type' => 'user', 'id' => (string) ($user['user_uid'] ?? $user['id'] ?? ''), 'label' => $this->label($user)];
    }

    public function primaryRole(array $user): string
    {
        $roles = $user['roles'] ?? $this->model->identity->rolesForUser((int) $user['id']);
        $codes = array_column($roles, 'code');
        foreach (self::ADMIN_ROLES as $code) {
            if (in_array($code, $codes, true)) return $code;
        }
        return $codes[0] ?? 'platform_member';
    }

    public function isAdminAccount(array $user): bool
    {
        $role = $this->primaryRole($user);
        return in_array($role, self::ADMIN_ROLES, true);
    }

    public function assignableRoles(array $actor): array
    {
        $all = $this->model->identity->listRoles();
        $super = in_array('system.super_admin', $actor['permissions'] ?? [], true);
        if ($super) return $all;
        return array_values(array_filter($all, fn($r) => !in_array($r['code'], self::ADMIN_ROLES, true)));
    }

    public function workforceOverview(): array
    {
        $history = [];
        try { $history = $this->model->analysis->history(20); } catch (\Throwable $e) { $history = []; }
        return [
            'totalRuns' => $this->safeCount('analysis_runs'),
            'recent' => $history,
        ];
    }

    public function languageOverview(): array
    {
        $languages = [];
        try { $languages = $this->model->langlearn->listLanguages(false); } catch (\Throwable $e) { $languages = []; }
        return [
            'languages' => count($languages),
            'profiles' => $this->safeCount('user_language_profiles'),
            'sessions' => $this->safeCount('study_sessions'),
            'assessments' => $this->safeCount('language_assessments'),
            'catalog' => $languages,
        ];
    }

    public function conversationOverview(): array
    {
        $rows = [];
        try {
            $rows = $this->model->db->order_by('started_at', 'DESC')->limit(50)->get('conversation_sessions')->result_array();
        } catch (\Throwable $e) { $rows = []; }
        return [
            'total' => $this->safeCount('conversation_sessions'),
            'recent' => $rows,
        ];
    }

    public function analyticsOverview(): array
    {
        return [
            'users' => $this->model->identity->accountCounts(),
            'aiRuns' => $this->safeCount('analysis_runs'),
            'languageProfiles' => $this->safeCount('user_language_profiles'),
            'conversations' => $this->safeCount('conversation_sessions'),
            'studySessions' => $this->safeCount('study_sessions'),
            'notifications' => $this->safeCount('notifications'),
            'failedLogins' => $this->countAuthType('LOGIN_FAILED'),
            'successLogins' => $this->countAuthType('LOGIN_SUCCEEDED'),
        ];
    }

    public function securityOverview(): array
    {
        $failed = [];
        try {
            $failed = $this->model->db->where('type', 'LOGIN_FAILED')->order_by('id', 'DESC')->limit(25)->get('auth_events')->result_array();
        } catch (\Throwable $e) { $failed = []; }
        $blocked = [];
        try {
            $blocked = $this->model->db->where('type', 'LOGIN_BLOCKED_SUSPENDED')->order_by('id', 'DESC')->limit(15)->get('auth_events')->result_array();
        } catch (\Throwable $e) { $blocked = []; }
        return [
            'failedLogins' => $failed,
            'blockedSuspended' => $blocked,
            'loginMaxAttempts' => (int) $this->setting('login_max_attempts', '5'),
            'loginLockoutSeconds' => (int) $this->setting('login_lockout_seconds', '900'),
            'registrationEnabled' => $this->setting('registration_enabled', '1') === '1',
        ];
    }

    public function notifyAdmins(string $type, string $severity, string $title, array $detail = [], ?string $dedupe = null): void
    {
        try {
            $this->model->notifications->save([
                'userId' => null,
                'type' => $type,
                'severity' => $severity,
                'title' => $title,
                'detail' => $detail,
                'dedupeKey' => $dedupe,
                'createdAt' => gmdate('c'),
            ]);
        } catch (\Throwable $e) { /* notifications must never break registration/login */ }
    }

    private function safeCount(string $table): int
    {
        try { return (int) $this->model->db->count_all($table); }
        catch (\Throwable $e) { return 0; }
    }

    private function countWhere(string $table, array $where): int
    {
        try { return (int) $this->model->db->where($where)->count_all_results($table); }
        catch (\Throwable $e) { return 0; }
    }

    private function countAuthType(string $type): int
    {
        try { return (int) $this->model->db->where('type', $type)->count_all_results('auth_events'); }
        catch (\Throwable $e) { return 0; }
    }
}
