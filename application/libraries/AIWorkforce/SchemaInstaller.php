<?php
namespace AIWorkforce;

/**
 * Single source of truth for which SQL modules make a working database.
 *
 * Used by php tools/install.php, php index.php tools install, and the first
 * request against an empty sqlite file so the offline runtime can boot
 * without a separate install step.
 */
final class SchemaInstaller
{
    /** SQL stems under application/database/{stem}.{sqlite|mysql}.sql */
    public const MODULES = [
        'schema',
        'sports_identity',
        'sports',
        'sports_decisions',
        'sports_results',
        'sports_intelligence',
        'langlearn',
        'lottery',
        'admin_portal',
    ];

    /** Every table created by the module files. Installers verify this list. */
    public const EXPECTED_TABLES = [
        'ci_sessions', 'platform_state', 'strategies', 'backtests', 'analysis_runs',
        'journal_entries', 'paper_accounts', 'paper_orders', 'paper_positions',
        'paper_trades', 'paper_deployments', 'audit_logs',
        'leads', 'lead_notes', 'lead_activities', 'collections', 'collection_leads',
        'search_history', 'duplicate_candidates', 'duplicate_resolutions', 'export_history',
        'lead_outreach', 'lead_organizations', 'lead_organization_members',
        'trade_proposals', 'trade_executions', 'notifications',
        'users', 'roles', 'permissions', 'user_roles', 'role_permissions', 'auth_events',
        'sports_data_sources', 'sports_provider_health', 'sports_matches', 'sports_odds',
        'sports_data_quality_assessments', 'sports_sync_runs', 'sports_model_versions',
        'sports_predictions', 'sports_tickets', 'sports_ticket_selections', 'sports_results',
        'sports_configurations', 'sports_calibrations', 'sports_job_runs', 'sports_backtests',
        'sports_model_metrics', 'sports_daily_tickets', 'sports_performance_snapshots',
        'languages', 'user_language_profiles', 'language_assessments', 'learning_paths',
        'learning_modules', 'lesson_attempts', 'study_sessions', 'language_progress',
        'conversation_sessions', 'writing_attempts', 'vocabulary', 'user_vocabulary',
        'listening_attempts', 'speaking_attempts', 'daily_learning_plans', 'ai_learning_recommendations',
        'lotteries', 'lottery_rules', 'lottery_data_sources', 'lottery_provider_health',
        'lottery_draws', 'lottery_draw_numbers', 'lottery_sync_runs',
        'lottery_combinations', 'lottery_ai_decisions', 'lottery_tickets', 'lottery_ticket_lines',
        'lottery_backtests', 'lottery_model_versions',
        'admin_activity_logs', 'impersonation_sessions', 'platform_settings', 'api_providers',
    ];

    /** Representative tables — if any are missing, re-apply CREATE IF NOT EXISTS. */
    public const CORE_TABLES = ['users', 'languages', 'leads', 'lotteries', 'api_providers', 'sports_matches'];

    private static bool $done = false;

    public static function databaseDir(): string
    {
        if (defined('APPPATH')) {
            return rtrim((string) APPPATH, '/\\') . DIRECTORY_SEPARATOR . 'database';
        }
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'database';
    }

    public static function isSqlite(object|string $dbOrDriver): bool
    {
        if (is_string($dbOrDriver)) {
            return str_contains($dbOrDriver, 'sqlite');
        }
        $driver = (string) ($dbOrDriver->dbdriver ?? $dbOrDriver->platform ?? '');
        $sub = (string) ($dbOrDriver->subdriver ?? '');
        if (method_exists($dbOrDriver, 'platform')) {
            try { $driver .= ' ' . (string) $dbOrDriver->platform(); } catch (\Throwable $e) { /* ignore */ }
        }
        return str_contains($driver, 'sqlite') || $sub === 'sqlite';
    }

    /** @return list<string> existing module SQL paths */
    public static function files(bool $sqlite): array
    {
        $dir = self::databaseDir();
        $ext = $sqlite ? 'sqlite' : 'mysql';
        $out = [];
        foreach (self::MODULES as $stem) {
            $path = $dir . DIRECTORY_SEPARATOR . $stem . '.' . $ext . '.sql';
            if (is_file($path)) $out[] = $path;
        }
        return $out;
    }

    /** @return list<string> */
    public static function statementsFrom(string $sql): array
    {
        $sql = preg_replace('/^\s*--[^\r\n]*[\r\n]?/m', '', $sql) ?? $sql;
        $parts = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
        $out = [];
        foreach ($parts as $stmt) {
            $stmt = trim($stmt);
            $stmt = rtrim($stmt, ';');
            $stmt = trim($stmt);
            if ($stmt !== '') $out[] = $stmt;
        }
        return $out;
    }

    /** @param callable(string):mixed $exec */
    public static function applyFiles(callable $exec, bool $sqlite): void
    {
        foreach (self::files($sqlite) as $file) {
            $sql = (string) file_get_contents($file);
            if (str_contains(basename($file), 'langlearn.') && !str_contains($sql, 'daily_minutes')) {
                throw new \RuntimeException('langlearn schema is missing daily_minutes: ' . $file);
            }
            foreach (self::statementsFrom($sql) as $stmt) {
                $exec($stmt);
            }
        }
    }

    /** Idempotent ALTERs + indexes for databases created from older schemas. */
    /** @param callable(string):mixed $exec */
    public static function upgrade(callable $exec, bool $sqlite): void
    {
        $text = $sqlite ? 'TEXT' : 'VARCHAR(255) NULL';
        $alters = [
            $sqlite
                ? 'ALTER TABLE user_language_profiles ADD COLUMN daily_minutes INTEGER NOT NULL DEFAULT 20'
                : 'ALTER TABLE user_language_profiles ADD COLUMN daily_minutes INT NOT NULL DEFAULT 20',
            $sqlite ? 'ALTER TABLE sports_tickets ADD COLUMN stake REAL' : 'ALTER TABLE sports_tickets ADD COLUMN stake DECIMAL(12,2) NULL',
            $sqlite ? 'ALTER TABLE sports_tickets ADD COLUMN pnl REAL' : 'ALTER TABLE sports_tickets ADD COLUMN pnl DECIMAL(14,4) NULL',
            $sqlite ? 'ALTER TABLE sports_predictions ADD COLUMN odds REAL' : 'ALTER TABLE sports_predictions ADD COLUMN odds DECIMAL(14,6) NULL',
            $sqlite ? 'ALTER TABLE sports_predictions ADD COLUMN odds_timestamp TEXT' : 'ALTER TABLE sports_predictions ADD COLUMN odds_timestamp VARCHAR(32) NULL',
            $sqlite ? 'ALTER TABLE lottery_sync_runs ADD COLUMN payload TEXT' : 'ALTER TABLE lottery_sync_runs ADD COLUMN payload MEDIUMTEXT NULL',
            'ALTER TABLE users ADD COLUMN username ' . ($sqlite ? 'TEXT' : 'VARCHAR(64) NULL'),
            'ALTER TABLE users ADD COLUMN user_uid ' . ($sqlite ? 'TEXT' : 'CHAR(6) NULL'),
            'ALTER TABLE users ADD COLUMN profile_image ' . ($sqlite ? 'TEXT' : 'VARCHAR(255) NULL'),
            'ALTER TABLE users ADD COLUMN phone ' . ($sqlite ? 'TEXT' : 'VARCHAR(40) NULL'),
            'ALTER TABLE users ADD COLUMN address ' . ($sqlite ? 'TEXT' : 'VARCHAR(255) NULL'),
            'ALTER TABLE users ADD COLUMN security_pin ' . ($sqlite ? 'TEXT' : 'CHAR(4) NULL'),
            'ALTER TABLE users ADD COLUMN security_question ' . ($sqlite ? 'TEXT' : 'VARCHAR(255) NULL'),
            'ALTER TABLE users ADD COLUMN security_answer ' . ($sqlite ? 'TEXT' : 'VARCHAR(255) NULL'),
            'ALTER TABLE leads ADD COLUMN email ' . $text,
            'ALTER TABLE leads ADD COLUMN job_title ' . $text,
            'ALTER TABLE leads ADD COLUMN company_name ' . $text,
            'ALTER TABLE leads ADD COLUMN linkedin_url ' . ($sqlite ? 'TEXT' : 'TEXT NULL'),
            $sqlite
                ? "ALTER TABLE leads ADD COLUMN lead_kind TEXT NOT NULL DEFAULT 'business'"
                : "ALTER TABLE leads ADD COLUMN lead_kind VARCHAR(20) NOT NULL DEFAULT 'business'",
        ];
        foreach ($alters as $sql) {
            try { $exec($sql); } catch (\Throwable $e) { /* column already exists */ }
        }

        $outreach = $sqlite
            ? "CREATE TABLE IF NOT EXISTS lead_outreach (
                  id TEXT PRIMARY KEY, organization_id TEXT NOT NULL, lead_id TEXT NOT NULL,
                  actor_id INTEGER, channel TEXT NOT NULL, subject TEXT, body TEXT NOT NULL,
                  status TEXT NOT NULL, detail TEXT NOT NULL DEFAULT '{}', created_at TEXT NOT NULL
                )"
            : "CREATE TABLE IF NOT EXISTS lead_outreach (
                  id VARCHAR(36) PRIMARY KEY, organization_id VARCHAR(80) NOT NULL, lead_id VARCHAR(36) NOT NULL,
                  actor_id INT NULL, channel VARCHAR(20) NOT NULL, subject VARCHAR(200) NULL, body TEXT NOT NULL,
                  status VARCHAR(20) NOT NULL, detail LONGTEXT NOT NULL, created_at VARCHAR(32) NOT NULL,
                  KEY idx_outreach_lead (organization_id, lead_id, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        try { $exec($outreach); } catch (\Throwable $e) { /* already exists */ }

        $indexes = [
            $sqlite
                ? 'CREATE UNIQUE INDEX IF NOT EXISTS idx_users_username ON users(username)'
                : 'CREATE UNIQUE INDEX uq_users_username ON users(username)',
            $sqlite
                ? 'CREATE UNIQUE INDEX IF NOT EXISTS idx_users_user_uid ON users(user_uid)'
                : 'CREATE UNIQUE INDEX uq_users_user_uid ON users(user_uid)',
            $sqlite
                ? 'CREATE INDEX IF NOT EXISTS idx_outreach_lead ON lead_outreach(organization_id, lead_id, created_at)'
                : 'CREATE INDEX idx_outreach_lead ON lead_outreach(organization_id, lead_id, created_at)',
            $sqlite
                ? 'CREATE INDEX IF NOT EXISTS idx_sports_odds_provider ON sports_odds (provider_id, observed_at)'
                : 'CREATE INDEX idx_sports_odds_provider ON sports_odds (provider_id, observed_at)',
            $sqlite
                ? 'CREATE INDEX IF NOT EXISTS idx_sports_matches_provider_kickoff ON sports_matches (provider_id, kickoff_at)'
                : 'CREATE INDEX idx_sports_matches_provider_kickoff ON sports_matches (provider_id, kickoff_at)',
            $sqlite
                ? 'CREATE INDEX IF NOT EXISTS idx_sports_predictions_market ON sports_predictions (market, created_at)'
                : 'CREATE INDEX idx_sports_predictions_market ON sports_predictions (market, created_at)',
            $sqlite
                ? 'CREATE INDEX IF NOT EXISTS idx_sports_selections_market ON sports_ticket_selections (market, selection)'
                : 'CREATE INDEX idx_sports_selections_market ON sports_ticket_selections (market, selection)',
            $sqlite
                ? 'CREATE INDEX IF NOT EXISTS idx_sports_selections_match ON sports_ticket_selections (match_id)'
                : 'CREATE INDEX idx_sports_selections_match ON sports_ticket_selections (match_id)',
            $sqlite
                ? 'CREATE INDEX IF NOT EXISTS idx_sports_predictions_created ON sports_predictions (created_at)'
                : 'CREATE INDEX idx_sports_predictions_created ON sports_predictions (created_at)',
            $sqlite
                ? 'CREATE INDEX IF NOT EXISTS idx_sports_health_provider ON sports_provider_health (provider_id, observed_at)'
                : 'CREATE INDEX idx_sports_health_provider ON sports_provider_health (provider_id, observed_at)',
        ];
        foreach ($indexes as $sql) {
            try { $exec($sql); } catch (\Throwable $e) { /* already exists */ }
        }
    }

    /** First-request / CI boot: create any missing modules, then run upgrades. */
    public static function ensure(object $db): void
    {
        if (self::$done) return;
        self::$done = true;
        $sqlite = self::isSqlite($db);
        $missing = false;
        foreach (self::CORE_TABLES as $table) {
            try {
                if (!method_exists($db, 'table_exists') || !$db->table_exists($table)) {
                    $missing = true;
                    break;
                }
            } catch (\Throwable $e) {
                $missing = true;
                break;
            }
        }
        $exec = function (string $sql) use ($db) {
            try { $db->query($sql); } catch (\Throwable $e) { /* duplicate / racing request */ }
        };
        if ($missing) self::applyFiles($exec, $sqlite);
        self::upgrade($exec, $sqlite);
    }

    public static function installCi(object $db): void
    {
        $sqlite = self::isSqlite($db);
        $exec = function (string $sql) use ($db) {
            $db->query($sql);
        };
        self::applyFiles($exec, $sqlite);
        self::upgrade(function (string $sql) use ($db) {
            try { $db->query($sql); } catch (\Throwable $e) { /* duplicate */ }
        }, $sqlite);
        self::$done = true;
    }

    /**
     * Direct PDO install used by tools/install.php.
     *
     * @return list<string> missing table names (empty = success)
     */
    public static function installPdo(\PDO $pdo, string $driver): array
    {
        $sqlite = self::isSqlite($driver);
        $exec = function (string $sql) use ($pdo) {
            $pdo->exec($sql);
        };
        self::applyFiles($exec, $sqlite);
        self::upgrade(function (string $sql) use ($pdo) {
            try { $pdo->exec($sql); } catch (\Throwable $e) { /* duplicate */ }
        }, $sqlite);
        if ($sqlite) {
            $rows = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(\PDO::FETCH_COLUMN);
        } else {
            $rows = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        }
        $have = array_map('strval', is_array($rows) ? $rows : []);
        return array_values(array_diff(self::EXPECTED_TABLES, $have));
    }
}
