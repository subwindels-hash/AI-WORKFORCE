<?php
/**
 * Canonical database: both installers apply the same modules, MySQL/SQLite
 * table names match, and identity + lead columns exist in the SQL files.
 */
test('schema installer lists every module SQL file for both drivers', function () {
    $dir = FCPATH . 'application/database/';
    foreach (\AIWorkforce\SchemaInstaller::MODULES as $stem) {
        assert_true(is_file($dir . $stem . '.mysql.sql'), $stem . '.mysql.sql');
        assert_true(is_file($dir . $stem . '.sqlite.sql'), $stem . '.sqlite.sql');
    }
    assert_in_array('langlearn', \AIWorkforce\SchemaInstaller::MODULES);
    assert_in_array('sports_identity', \AIWorkforce\SchemaInstaller::MODULES);
    assert_in_array('admin_portal', \AIWorkforce\SchemaInstaller::MODULES);
});

test('CI tools install and tools/install.php both use SchemaInstaller', function () {
    $tools = file_get_contents(FCPATH . 'application/controllers/Tools.php');
    $cli = file_get_contents(FCPATH . 'tools/install.php');
    $model = file_get_contents(FCPATH . 'application/models/AIWorkforce_model.php');
    assert_contains('SchemaInstaller::installCi', $tools);
    assert_false(str_contains($tools, 'schemaFiles'), 'Tools.php must not keep a private schema file list');
    assert_contains('SchemaInstaller::installPdo', $cli);
    assert_contains('VP_DB_DRIVER', $cli);
    assert_contains('SchemaInstaller::ensure', $model);
    assert_contains("'langlearn'", file_get_contents(FCPATH . 'application/libraries/AIWorkforce/SchemaInstaller.php'));
});

test('installer expected tables cover language learning, leads, lottery and identity', function () {
    $expected = \AIWorkforce\SchemaInstaller::EXPECTED_TABLES;
    foreach ([
        'users', 'roles', 'languages', 'user_language_profiles', 'daily_learning_plans',
        'leads', 'lead_outreach', 'lead_organizations',
        'lottery_combinations', 'lottery_ai_decisions', 'lottery_tickets', 'lottery_ticket_lines',
        'lottery_backtests', 'lottery_model_versions',
        'api_providers', 'sports_matches', 'ci_sessions',
    ] as $table) {
        assert_in_array($table, $expected, $table);
    }
    $src = file_get_contents(FCPATH . 'application/libraries/AIWorkforce/SchemaInstaller.php');
    assert_contains("'lottery_combinations', 'lottery_ai_decisions', 'lottery_tickets', 'lottery_ticket_lines',", $src);
    assert_contains("'lottery_backtests', 'lottery_model_versions'", $src);
});

test('mysql and sqlite module files create the same tables', function () {
    $mysql = [];
    $sqlite = [];
    foreach (\AIWorkforce\SchemaInstaller::MODULES as $stem) {
        foreach (['mysql' => &$mysql, 'sqlite' => &$sqlite] as $ext => &$bag) {
            $sql = file_get_contents(FCPATH . 'application/database/' . $stem . '.' . $ext . '.sql');
            if (preg_match_all('/CREATE TABLE IF NOT EXISTS\s+(\w+)/i', $sql, $m)) {
                foreach ($m[1] as $name) $bag[$name] = true;
            }
        }
        unset($bag);
    }
    $mysqlNames = array_keys($mysql);
    $sqliteNames = array_keys($sqlite);
    sort($mysqlNames);
    sort($sqliteNames);
    assert_equals($mysqlNames, $sqliteNames, 'mysql/sqlite table names must match');
    foreach (\AIWorkforce\SchemaInstaller::EXPECTED_TABLES as $table) {
        assert_true(isset($mysql[$table]), 'expected table in SQL files: ' . $table);
    }
});

test('identity SQL includes phone, address and auto-generated security PIN columns', function () {
    foreach (['mysql', 'sqlite'] as $ext) {
        $sql = file_get_contents(FCPATH . 'application/database/sports_identity.' . $ext . '.sql');
        foreach (['phone', 'address', 'security_pin', 'security_question', 'security_answer', 'username', 'user_uid', 'profile_image'] as $col) {
            assert_contains($col, $sql, "sports_identity.{$ext}.sql has {$col}");
        }
    }
});

test('lead discovery SQL includes contact fields and outreach storage', function () {
    foreach (['mysql', 'sqlite'] as $ext) {
        $sql = file_get_contents(FCPATH . 'application/database/schema.' . $ext . '.sql');
        foreach (['email', 'job_title', 'company_name', 'linkedin_url', 'lead_kind', 'lead_outreach'] as $needle) {
            assert_contains($needle, $sql, "schema.{$ext}.sql has {$needle}");
        }
    }
});

test('production language-progress DDL and the cPanel archive use the corrected schema', function () {
    $source = file_get_contents(FCPATH . 'database/production.sql');
    assert_equals(1, substr_count($source, 'CREATE TABLE IF NOT EXISTS language_progress'), 'one language_progress table');
    assert_true((bool) preg_match('/CREATE TABLE IF NOT EXISTS language_progress\\s*\\((.*?)\\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;/s', $source, $match), 'language_progress DDL');
    $ddl = $match[0];
    foreach ([
        'value_pct          DECIMAL(5,2) NULL',
        'source             VARCHAR(24) NOT NULL',
        'updated_at         VARCHAR(32) NOT NULL',
        'UNIQUE KEY uq_progress (profile_id, skill, source)',
        'KEY idx_progress_user (user_id)',
    ] as $expected) {
        assert_contains($expected, $ddl, 'language_progress: ' . $expected);
    }
    foreach ([
        '-nt|lesson (Phase 2)',
        'score_pct      DECIMAL(5,2) NULL',
        'KEY idx_attempts_profile (profile_id, created_at)',
    ] as $invalid) {
        assert_false(str_contains($ddl, $invalid), 'language_progress must not contain stale lesson_attempts SQL: ' . $invalid);
    }

    // ZipArchive is not compiled into every supported PHP runtime (including
    // some offline test builds), so retain source validation above everywhere
    // and verify the shipped release artifact whenever ZIP support is present.
    if (!class_exists('ZipArchive')) return;
    $zip = new ZipArchive();
    assert_equals(true, $zip->open(FCPATH . 'application-deployment.zip'), 'open deployment archive');
    try {
        $bundled = $zip->getFromName('database/production.sql');
        assert_true(is_string($bundled), 'deployment archive contains production.sql');
        assert_equals($source, $bundled, 'deployment archive must contain the current production SQL');
    } finally {
        $zip->close();
    }
});

test('runtime database has every core table and identity/lead columns', function () {
    $db = platform()->model->db;
    \AIWorkforce\SchemaInstaller::ensure($db);
    foreach (\AIWorkforce\SchemaInstaller::CORE_TABLES as $table) {
        assert_true($db->table_exists($table), $table . ' exists');
    }
    foreach (\AIWorkforce\SchemaInstaller::EXPECTED_TABLES as $table) {
        assert_true($db->table_exists($table), $table . ' exists');
    }
    foreach (['phone', 'address', 'security_pin', 'username', 'user_uid'] as $col) {
        assert_true(\AIWorkforce\IdentitySchema::has($db, $col), "users.{$col}");
    }
    $leadFields = $db->list_fields('leads');
    foreach (['email', 'job_title', 'company_name', 'linkedin_url', 'lead_kind'] as $col) {
        assert_true(in_array($col, $leadFields, true), "leads.{$col}");
    }
    $langFields = $db->list_fields('user_language_profiles');
    assert_true(in_array('daily_minutes', $langFields, true), 'user_language_profiles.daily_minutes');
});
