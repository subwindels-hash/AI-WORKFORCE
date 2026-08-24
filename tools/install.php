<?php
/**
 * AEGIS database installer.
 *
 *   php tools/install.php
 *
 * Picks the schema by driver: MySQL/MariaDB (mysqli, default — production)
 * or pdo_sqlite (offline dev runtime). Creates the database (MySQL) and all
 * tables idempotently, then verifies each table exists.
 */
// Caller decides the exit code (the WASM runtime loses output on exit()).
define('AEGIS_NO_EXIT', true);
echo "AEGIS installer\n===============\n";

$driver = getenv('AEGIS_DB_DRIVER') ?: 'mysqli';
echo "Driver: {$driver}\n";

if ($driver === 'pdo_sqlite') {
    $path = getenv('AEGIS_SQLITE_PATH') ?: __DIR__ . '/../application/data/aegis.sqlite';
    @mkdir(dirname($path), 0775, true);
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $schemaFiles = [
        __DIR__ . '/../application/database/schema.sqlite.sql',
        __DIR__ . '/../application/database/sports_identity.sqlite.sql',
        __DIR__ . '/../application/database/sports.sqlite.sql',
        __DIR__ . '/../application/database/sports_decisions.sqlite.sql',
        __DIR__ . '/../application/database/sports_results.sqlite.sql',
        __DIR__ . '/../application/database/langlearn.sqlite.sql',
    ];
    $sql = implode("\n", array_map(fn($file) => file_get_contents($file), $schemaFiles));
} else {
    $host = getenv('AEGIS_DB_HOST') ?: '127.0.0.1';
    $user = getenv('AEGIS_DB_USER') ?: 'aegis';
    $pass = getenv('AEGIS_DB_PASS') ?: 'aegis';
    $name = getenv('AEGIS_DB_NAME') ?: 'aegis_trading';
    $rootPdo = new PDO("mysql:host={$host}", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $pdo = new PDO("mysql:host={$host};dbname={$name}", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $schemaFiles = [
        __DIR__ . '/../application/database/schema.mysql.sql',
        __DIR__ . '/../application/database/sports_identity.mysql.sql',
        __DIR__ . '/../application/database/sports.mysql.sql',
        __DIR__ . '/../application/database/sports_decisions.mysql.sql',
        __DIR__ . '/../application/database/sports_results.mysql.sql',
        __DIR__ . '/../application/database/langlearn.mysql.sql',
    ];
    $sql = implode("\n", array_map(fn($file) => file_get_contents($file), $schemaFiles));
}

require_once __DIR__ . '/rbac.php';

$statements = array_filter(array_map('trim', preg_split('/;\s*[\r\n]+/', $sql)));
foreach ($statements as $stmt) {
    // Strip comment LINES (a leading comment block glues to the first statement).
    $lines = preg_split('/\r?\n/', $stmt);
    $lines = array_filter($lines, fn($l) => !str_starts_with(ltrim($l), '--'));
    $stmt = trim(implode("\n", $lines));
    if ($stmt === '') continue;
    $pdo->exec($stmt);
}

$expected = ['platform_state', 'strategies', 'backtests', 'analysis_runs', 'journal_entries',
    'paper_accounts', 'paper_orders', 'paper_positions', 'paper_trades', 'paper_deployments', 'audit_logs',
    'trade_proposals', 'trade_executions', 'notifications', 'ci_sessions',
    'languages', 'user_language_profiles', 'language_assessments', 'learning_paths', 'learning_modules',
    'lesson_attempts', 'study_sessions', 'language_progress', 'conversation_sessions', 'writing_attempts',
    'vocabulary', 'user_vocabulary', 'listening_attempts', 'speaking_attempts', 'daily_learning_plans', 'ai_learning_recommendations',
    'users', 'roles', 'permissions', 'user_roles', 'role_permissions', 'auth_events',
    'sports_data_sources', 'sports_matches', 'sports_odds', 'sports_sync_runs', 'sports_model_versions',
    'sports_predictions', 'sports_tickets', 'sports_results'];
if ($driver === 'pdo_sqlite') {
    $rows = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
} else {
    $rows = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
}
$missing = array_diff($expected, $rows);
if ($missing) {
    if (defined('STDERR')) { fwrite(STDERR, 'MISSING TABLES: ' . implode(', ', $missing) . "\n"); }
    echo "INSTALL-RESULT: 1\n";
    if (PHP_SAPI === 'cli' && !defined('AEGIS_NO_EXIT')) {
        exit(1);
    }
    return;
}
echo 'OK — ' . count($expected) . " tables verified.\n";
// RBAC defaults (idempotent; unique keys make INSERT IGNORE safe on MySQL and SQLite).
$insertIgnore = $driver === 'pdo_sqlite' ? 'INSERT OR IGNORE INTO' : 'INSERT IGNORE INTO'; // both engines honor unique keys

// Best-effort upgrade for existing installs (fresh installs get the column in the schema).
foreach ($schemaFiles as $_f) {
    if (str_ends_with($_f, 'langlearn.' . ($driver === 'pdo_sqlite' ? 'sqlite' : 'mysql') . '.sql') && !str_contains((string) file_get_contents($_f), 'daily_minutes')) {
        // schema mirror lacks the column hint; nothing to do — handled by CREATE below
    }
}
try { $pdo->exec($driver === 'pdo_sqlite'
    ? 'ALTER TABLE user_language_profiles ADD COLUMN daily_minutes INTEGER NOT NULL DEFAULT 20'
    : 'ALTER TABLE user_language_profiles ADD COLUMN daily_minutes INT NOT NULL DEFAULT 20'); echo "upgrade: daily_minutes added\n"; }
catch (Throwable $e) { /* column already exists on upgraded installs */ }
aegis_seed_rbac(
    function (string $code, string $name) use ($pdo, $insertIgnore): int {
        $pdo->prepare("{$insertIgnore} roles (code, name) VALUES (?, ?)")->execute([$code, $name]);
        return (int) $pdo->query('SELECT id FROM roles WHERE code = ' . $pdo->quote($code))->fetchColumn();
    },
    function (string $code, string $name) use ($pdo, $insertIgnore): int {
        $pdo->prepare("{$insertIgnore} permissions (code, name) VALUES (?, ?)")->execute([$code, $name]);
        return (int) $pdo->query('SELECT id FROM permissions WHERE code = ' . $pdo->quote($code))->fetchColumn();
    },
    function (int $roleId, int $permissionId) use ($pdo, $insertIgnore): void {
        $pdo->prepare("{$insertIgnore} role_permissions (role_id, permission_id) VALUES (?, ?)")->execute([$roleId, $permissionId]);
    }
);
echo "INSTALL-RESULT: 0\n";
