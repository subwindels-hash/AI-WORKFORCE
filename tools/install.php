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
    ];
    $sql = implode("\n", array_map(fn($file) => file_get_contents($file), $schemaFiles));
}

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
echo "INSTALL-RESULT: 0\n";
