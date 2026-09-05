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

$driverRaw = strtolower((string) (getenv('AI_WORKFORCE_DB_DRIVER') ?: (getenv('VP_DB_DRIVER') ?: 'mysqli')));
$driver = match (true) {
    in_array($driverRaw, ['pdo_sqlite', 'sqlite', 'sqlite3'], true) => 'pdo_sqlite',
    in_array($driverRaw, ['pdo_pgsql', 'pgsql', 'postgres', 'postgresql', 'postgre'], true) => 'pdo_pgsql',
    default => 'mysqli',
};
echo "Driver: {$driver}\n";

require_once __DIR__ . '/../application/libraries/AIWorkforce/SchemaInstaller.php';
require_once __DIR__ . '/rbac.php';

if ($driver === 'pdo_sqlite') {
    $path = getenv('AI_WORKFORCE_SQLITE_PATH') ?: __DIR__ . '/../application/data/ai_workforce.sqlite';
    @mkdir(dirname($path), 0775, true);
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} elseif ($driver === 'pdo_pgsql') {
    $host = getenv('AI_WORKFORCE_DB_HOST') ?: (getenv('VP_DB_HOST') ?: '127.0.0.1');
    $port = (int) (getenv('AI_WORKFORCE_DB_PORT') ?: (getenv('VP_DB_PORT') ?: 5432));
    $user = getenv('AI_WORKFORCE_DB_USER') ?: (getenv('VP_DB_USER') ?: 'ai_workforce');
    $pass = getenv('AI_WORKFORCE_DB_PASS') ?: (getenv('VP_DB_PASS') ?: 'ai_workforce');
    $name = getenv('AI_WORKFORCE_DB_NAME') ?: (getenv('VP_DB_NAME') ?: 'ai_workforce_trading');
    // PostgreSQL has no CREATE DATABASE IF NOT EXISTS: connect to the
    // maintenance DB, create the target only when it is absent, then connect.
    $maint = new PDO("pgsql:host={$host};port={$port};dbname=postgres", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $exists = (int) $maint->query('SELECT 1 FROM pg_database WHERE datname = ' . $maint->quote($name))->fetchColumn();
    if ($exists !== 1) {
        $maint->exec('CREATE DATABASE "' . str_replace('"', '""', $name) . '"');
    }
    $pdo = new PDO("pgsql:host={$host};port={$port};dbname={$name}", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
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

$insertIgnore = match ($driver) {
    'pdo_sqlite' => 'INSERT OR IGNORE INTO',
    'pdo_pgsql' => 'INSERT INTO',
    default => 'INSERT IGNORE INTO',
};
$onConflict = $driver === 'pdo_pgsql' ? ' ON CONFLICT DO NOTHING' : '';
ai_workforce_seed_rbac(
    function (string $code, string $name) use ($pdo, $insertIgnore, $onConflict): int {
        $pdo->prepare("{$insertIgnore} roles (code, name) VALUES (?, ?){$onConflict}")->execute([$code, $name]);
        return (int) $pdo->query('SELECT id FROM roles WHERE code = ' . $pdo->quote($code))->fetchColumn();
    },
    function (string $code, string $name) use ($pdo, $insertIgnore, $onConflict): int {
        $pdo->prepare("{$insertIgnore} permissions (code, name) VALUES (?, ?){$onConflict}")->execute([$code, $name]);
        return (int) $pdo->query('SELECT id FROM permissions WHERE code = ' . $pdo->quote($code))->fetchColumn();
    },
    function (int $roleId, int $permissionId) use ($pdo, $insertIgnore, $onConflict): void {
        $pdo->prepare("{$insertIgnore} role_permissions (role_id, permission_id) VALUES (?, ?){$onConflict}")->execute([$roleId, $permissionId]);
    }
);
echo "INSTALL-RESULT: 0\n";
