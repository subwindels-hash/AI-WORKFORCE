<?php
/**
 * AI_WORKFORCE database installer.
 *
 *   php tools/install.php
 *
 * Picks the schema by driver: MySQL/MariaDB (mysqli, default — production)
 * or pdo_sqlite (offline dev runtime). Creates the database (MySQL) and all
 * tables idempotently, then verifies each table exists.
 *
 * Honors VP_DB_* (cPanel / .env.example) and AI_WORKFORCE_DB_* aliases.
 * Schema files, expected tables, and upgrades live in SchemaInstaller —
 * php index.php tools install must apply the same modules.
 */
// Caller decides the exit code (the WASM runtime loses output on exit()).
define('AI_WORKFORCE_NO_EXIT', true);
echo "AI_WORKFORCE installer\n===============\n";

$driver = getenv('AI_WORKFORCE_DB_DRIVER') ?: (getenv('VP_DB_DRIVER') ?: 'mysqli');
echo "Driver: {$driver}\n";

require_once __DIR__ . '/../application/libraries/AIWorkforce/SchemaInstaller.php';
require_once __DIR__ . '/rbac.php';

if ($driver === 'pdo_sqlite') {
    $path = getenv('AI_WORKFORCE_SQLITE_PATH') ?: __DIR__ . '/../application/data/ai_workforce.sqlite';
    @mkdir(dirname($path), 0775, true);
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} else {
    $host = getenv('AI_WORKFORCE_DB_HOST') ?: (getenv('VP_DB_HOST') ?: '127.0.0.1');
    $port = (int) (getenv('AI_WORKFORCE_DB_PORT') ?: (getenv('VP_DB_PORT') ?: 3306));
    $user = getenv('AI_WORKFORCE_DB_USER') ?: (getenv('VP_DB_USER') ?: 'ai_workforce');
    $pass = getenv('AI_WORKFORCE_DB_PASS') ?: (getenv('VP_DB_PASS') ?: 'ai_workforce');
    $name = getenv('AI_WORKFORCE_DB_NAME') ?: (getenv('VP_DB_NAME') ?: 'ai_workforce_trading');
    $rootPdo = new PDO("mysql:host={$host};port={$port}", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$name}", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

$missing = \AIWorkforce\SchemaInstaller::installPdo($pdo, $driver);
if ($missing) {
    if (defined('STDERR')) { fwrite(STDERR, 'MISSING TABLES: ' . implode(', ', $missing) . "\n"); }
    echo "INSTALL-RESULT: 1\n";
    if (PHP_SAPI === 'cli' && !defined('AI_WORKFORCE_NO_EXIT')) {
        exit(1);
    }
    return;
}
echo 'OK — ' . count(\AIWorkforce\SchemaInstaller::EXPECTED_TABLES) . " tables verified.\n";
echo "index upgrades applied\n";
echo "upgrade: account profile columns (username / user_uid / profile_image) ensured\n";
echo "upgrade: account unique indexes ensured\n";

$insertIgnore = $driver === 'pdo_sqlite' ? 'INSERT OR IGNORE INTO' : 'INSERT IGNORE INTO';
ai_workforce_seed_rbac(
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
