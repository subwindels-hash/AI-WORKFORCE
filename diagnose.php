<?php
/**
 * WINDELS AI WORKFORCE — Boot Diagnostic
 *
 * Upload this file to your public_html alongside index.php, then open
 * https://windelsai.com/diagnose.php in your browser. It will test each
 * step of the application boot process and show exactly where it fails.
 *
 * DELETE THIS FILE after you've fixed the issue — it exposes server info.
 */
header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html><head><meta charset="utf-8"><title>WINDELS AI Diagnostic</title>';
echo '<style>body{font-family:system-ui,sans-serif;max-width:800px;margin:40px auto;padding:0 20px;color:#0f172a}';
echo '.ok{color:#16a34a}.fail{color:#dc2626}.warn{color:#d97706}';
echo 'pre{background:#f1f5f9;padding:12px;border-radius:6px;overflow-x:auto;font-size:13px}';
echo 'h2{border-bottom:1px solid #e2e8f0;padding-bottom:8px}';
echo '</style></head><body>';
echo '<h1>🔍 WINDELS AI WORKFORCE — Boot Diagnostic</h1>';

$step = 0;
function check(string $label, callable $fn): void {
    global $step;
    $step++;
    echo "<p><b>Step {$step}:</b> {$label} … ";
    try {
        $result = $fn();
        if ($result === true || $result === null) {
            echo '<span class="ok">✅ OK</span>';
        } else {
            echo '<span class="ok">✅ OK</span> — <i>' . htmlspecialchars((string)$result) . '</i>';
        }
    } catch (\Throwable $e) {
        echo '<span class="fail">❌ FAIL</span>';
        echo '<pre class="fail">' . htmlspecialchars(get_class($e) . ': ' . $e->getMessage()
            . "\nFile: " . $e->getFile() . ':' . $e->getLine()
            . "\n\n" . $e->getTraceAsString()) . '</pre>';
        echo '</p>';
        // Don't die — keep testing other steps
        return;
    }
    echo '</p>';
}

// 1. PHP version
check('PHP version (need ≥ 8.1)', function () {
    $v = PHP_VERSION;
    if (PHP_VERSION_ID < 80100) {
        throw new \RuntimeException("PHP {$v} is too old! Need 8.1+. Fix: cPanel → Select PHP Version → 8.2");
    }
    return "PHP {$v}";
});

// 2. Required extensions
check('Required PHP extensions (mysqli, mbstring, json)', function () {
    $missing = [];
    foreach (['mysqli', 'mbstring', 'json'] as $ext) {
        if (!extension_loaded($ext)) $missing[] = $ext;
    }
    if ($missing) {
        throw new \RuntimeException('Missing extensions: ' . implode(', ', $missing) . '. Enable them in cPanel → Select PHP Version → Extensions');
    }
    return 'all loaded';
});

// 3. .env file
check('.env file exists and is readable', function () {
    $path = __DIR__ . '/.env';
    if (!is_file($path)) {
        throw new \RuntimeException('.env file not found at ' . $path . '. Copy .env.example to .env and edit it.');
    }
    if (!is_readable($path)) {
        throw new \RuntimeException('.env exists but is not readable. Check file permissions (should be 0644).');
    }
    $size = filesize($path);
    return "found ({$size} bytes)";
});

// 4. Load env
check('Load .env values', function () {
    $envFile = __DIR__ . '/application/config/env.php';
    if (!is_file($envFile)) {
        throw new \RuntimeException('application/config/env.php not found');
    }
    require_once $envFile;
    vp_load_env(__DIR__ . '/.env');
    $env = getenv('CI_ENV') ?: 'not set';
    return "CI_ENV={$env}";
});

// 5. Database credentials
check('Database credentials configured', function () {
    $host = getenv('VP_DB_HOST') ?: getenv('AI_WORKFORCE_DB_HOST') ?: '';
    $name = getenv('VP_DB_NAME') ?: getenv('AI_WORKFORCE_DB_NAME') ?: '';
    $user = getenv('VP_DB_USER') ?: getenv('AI_WORKFORCE_DB_USER') ?: '';
    if ($name === '' || $user === '') {
        throw new \RuntimeException("Database credentials missing in .env (VP_DB_NAME={$name}, VP_DB_USER={$user}). "
            . "Set VP_DB_HOST, VP_DB_NAME, VP_DB_USER, VP_DB_PASS in your .env file.");
    }
    return "host={$host}, db={$name}, user={$user}";
});

// 6. Database connection
check('Database connection (mysqli)', function () {
    $host = getenv('VP_DB_HOST') ?: (getenv('AI_WORKFORCE_DB_HOST') ?: 'localhost');
    $port = (int)(getenv('VP_DB_PORT') ?: (getenv('AI_WORKFORCE_DB_PORT') ?: 3306));
    $name = getenv('VP_DB_NAME') ?: getenv('AI_WORKFORCE_DB_NAME') ?: '';
    $user = getenv('VP_DB_USER') ?: getenv('AI_WORKFORCE_DB_USER') ?: '';
    $pass = getenv('VP_DB_PASS') ?: getenv('AI_WORKFORCE_DB_PASS') ?: '';

    $mysqli = @new \mysqli($host, $user, $pass, $name, $port);
    if ($mysqli->connect_error) {
        throw new \RuntimeException("Cannot connect to MySQL: [{$mysqli->connect_errno}] {$mysqli->connect_error}. "
            . "Check VP_DB_HOST/VP_DB_USER/VP_DB_PASS/VP_DB_NAME in .env and make sure the user has ALL PRIVILEGES on the database.");
    }
    $version = $mysqli->server_info;
    $mysqli->close();
    return "connected (MySQL {$version})";
});

// 7. Encryption key
check('Encryption key configured', function () {
    $key = getenv('VP_ENCRYPTION_KEY') ?: getenv('AI_WORKFORCE_ENCRYPTION_KEY') ?: '';
    if ($key === '' || $key === 'replace-with-existing-long-encryption-key') {
        echo '<span class="warn">⚠️ </span>';
        return 'WARNING: VP_ENCRYPTION_KEY is empty or default — sessions and remember-me cookies will not work';
    }
    return 'set (' . strlen($key) . ' chars)';
});

// 8. Writable directories
check('Writable directories (cache, logs, sessions)', function () {
    $dirs = [
        __DIR__ . '/application/cache',
        __DIR__ . '/application/logs',
        __DIR__ . '/runtime/sessions',
    ];
    $problems = [];
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            $problems[] = basename($dir) . ': directory missing';
        } elseif (!is_writable($dir)) {
            $problems[] = basename($dir) . ': not writable (chmod 0755 or 0775)';
        }
    }
    if ($problems) {
        throw new \RuntimeException(implode('; ', $problems));
    }
    return 'all writable';
});

// 9. CodeIgniter bootstrap
check('CodeIgniter system directory', function () {
    if (!is_dir(__DIR__ . '/system')) {
        throw new \RuntimeException('system/ directory not found. Was application-deployment.zip fully extracted?');
    }
    if (!is_file(__DIR__ . '/system/core/CodeIgniter.php')) {
        throw new \RuntimeException('system/core/CodeIgniter.php not found. Re-upload application-deployment.zip.');
    }
    return 'found';
});

// 10. Domain autoload
check('AI Workforce domain loader (113 PHP files)', function () {
    $loader = __DIR__ . '/application/libraries/AIWorkforce/autoload.php';
    if (!is_file($loader)) {
        throw new \RuntimeException('application/libraries/AIWorkforce/autoload.php not found');
    }
    require_once $loader;
    return 'loaded successfully';
});

// 11. Key classes exist
check('Key domain classes loadable', function () {
    $classes = [
        'AIWorkforce\\Platform',
        'AIWorkforce\\Identity',
        'AIWorkforce\\IdentitySchema',
        'AIWorkforce\\SchemaInstaller',
        'AIWorkforce\\RiskEngine',
        'AIWorkforce\\ExecutionSupervisor',
        'AIWorkforce\\Sports\\SportsIntelligence',
        'AIWorkforce\\Lottery\\LotteryIntelligence',
        'AIWorkforce\\LangLearn\\LangLearnService',
        'AIWorkforce\\Brokers\\Mt5BridgeConnector',
    ];
    $missing = [];
    foreach ($classes as $cls) {
        if (!class_exists($cls) && !interface_exists($cls)) {
            $missing[] = $cls;
        }
    }
    if ($missing) {
        throw new \RuntimeException('Missing classes: ' . implode(', ', $missing));
    }
    return count($classes) . ' classes OK';
});

// 12. Full CodeIgniter bootstrap (without routing)
check('Full CI3 bootstrap', function () {
    // Define the constants CI3 needs
    if (!defined('ENVIRONMENT')) define('ENVIRONMENT', 'development');
    if (!defined('SELF')) define('SELF', 'index.php');
    if (!defined('BASEPATH')) define('BASEPATH', __DIR__ . '/system/');
    if (!defined('FCPATH')) define('FCPATH', __DIR__ . '/');
    if (!defined('SYSDIR')) define('SYSDIR', 'system');
    if (!defined('APPPATH')) define('APPPATH', __DIR__ . '/application/');
    if (!defined('VIEWPATH')) define('VIEWPATH', __DIR__ . '/application/views/');
    if (!defined('TESTSPATH')) define('TESTSPATH', __DIR__ . '/tests/');

    // Load the core without running the full dispatch
    require_once BASEPATH . 'core/Common.php';
    return 'CI3 core loaded';
});

// 13. Database + model
check('AIWorkforce_model instantiation (schema check)', function () {
    // Try to actually connect and check core tables
    $host = getenv('VP_DB_HOST') ?: (getenv('AI_WORKFORCE_DB_HOST') ?: 'localhost');
    $port = (int)(getenv('VP_DB_PORT') ?: (getenv('AI_WORKFORCE_DB_PORT') ?: 3306));
    $name = getenv('VP_DB_NAME') ?: getenv('AI_WORKFORCE_DB_NAME') ?: '';
    $user = getenv('VP_DB_USER') ?: getenv('AI_WORKFORCE_DB_USER') ?: '';
    $pass = getenv('VP_DB_PASS') ?: getenv('AI_WORKFORCE_DB_PASS') ?: '';

    $mysqli = @new \mysqli($host, $user, $pass, $name, $port);
    if ($mysqli->connect_error) {
        throw new \RuntimeException("DB connection failed: {$mysqli->connect_error}");
    }

    $coreTables = ['users', 'platform_state', 'strategies', 'languages', 'sports_matches', 'lotteries', 'api_providers'];
    $missing = [];
    foreach ($coreTables as $t) {
        $r = $mysqli->query("SHOW TABLES LIKE '{$t}'");
        if (!$r || $r->num_rows === 0) {
            $missing[] = $t;
        }
    }
    $mysqli->close();
    if ($missing) {
        throw new \RuntimeException('Missing tables: ' . implode(', ', $missing)
            . '. Import database/production.sql via phpMyAdmin.');
    }
    return count($coreTables) . ' core tables present';
});

// 14. Administrator account present (root cause of "Administrator access was not granted")
check('Administrator account exists in the database', function () {
    $host = getenv('VP_DB_HOST') ?: (getenv('AI_WORKFORCE_DB_HOST') ?: 'localhost');
    $port = (int)(getenv('VP_DB_PORT') ?: (getenv('AI_WORKFORCE_DB_PORT') ?: 3306));
    $name = getenv('VP_DB_NAME') ?: getenv('AI_WORKFORCE_DB_NAME') ?: '';
    $user = getenv('VP_DB_USER') ?: getenv('AI_WORKFORCE_DB_USER') ?: '';
    $pass = getenv('VP_DB_PASS') ?: getenv('AI_WORKFORCE_DB_PASS') ?: '';

    $mysqli = @new \mysqli($host, $user, $pass, $name, $port);
    if ($mysqli->connect_error) {
        throw new \RuntimeException("DB connection failed: {$mysqli->connect_error}");
    }
    $count = static function (string $sql) use ($mysqli): int {
        $r = $mysqli->query($sql);
        return $r ? (int) ($r->fetch_row()[0] ?? 0) : 0;
    };
    $users = $count('SELECT COUNT(*) FROM users');
    if ($users === 0) {
        $mysqli->close();
        throw new \RuntimeException("The users table is EMPTY — database/production.sql was not imported into the database the site uses ({$name}). "
            . "Import it into '{$name}' via phpMyAdmin (not any other database). That import creates the "
            . "initial administrator (admin@example.com — credentials in docs/CPANEL_DEPLOYMENT.md).");
    }
    $admins = $count("SELECT COUNT(DISTINCT u.id) FROM users u"
        . " JOIN user_roles ur ON ur.user_id = u.id"
        . " JOIN role_permissions rp ON rp.role_id = ur.role_id"
        . " JOIN permissions p ON p.id = rp.permission_id"
        . " WHERE u.active = 1 AND p.code IN ('admin.access','system.super_admin')");
    $seeded = $count("SELECT COUNT(*) FROM users WHERE email = 'admin@example.com'");
    $mysqli->close();
    if ($admins === 0) {
        $hint = $seeded > 0
            ? 'admin@example.com exists but has no admin role — re-import database/production.sql so the roles, role_permissions and user_roles rows are present.'
            : "Import database/production.sql into '{$name}' (it creates the documented initial administrator), or have an existing administrator grant your account an admin role.";
        $setup = ' Alternatively, open /admin/login: when NO administrator exists it shows a one-time setup form that creates the first administrator from the browser (no terminal required).';
        throw new \RuntimeException("{$users} user(s) found but NONE with admin permission — this is why login says \"Administrator access was not granted.\". {$hint} {$setup}");
    }
    return $admins . ' admin-capable account(s) out of ' . $users . ' user(s)'
        . ($seeded > 0 ? ' — production.sql administrator (admin@example.com) is present' : '');
});

// 15. PHP memory limit
check('PHP memory limit', function () {
    $limit = ini_get('memory_limit');
    $bytes = (int) $limit;
    if (preg_match('/^(\d+)\s*([KMG])$/i', $limit, $m)) {
        $bytes = (int) $m[1] * match (strtoupper($m[2])) { 'K' => 1024, 'M' => 1048576, 'G' => 1073741824, default => 1 };
    }
    if ($bytes > 0 && $bytes < 128 * 1048576) {
        echo '<span class="warn">⚠️ </span>';
        return "WARNING: memory_limit={$limit} — app may need 128M+. Increase in cPanel → MultiPHP INI Editor";
    }
    return "memory_limit={$limit}";
});

// Summary
echo '<hr>';
echo '<h2>📋 Server Info</h2>';
echo '<pre>';
echo 'PHP Version:    ' . PHP_VERSION . "\n";
echo 'Server:         ' . ($_SERVER['SERVER_SOFTWARE'] ?? 'unknown') . "\n";
echo 'Document Root:  ' . ($_SERVER['DOCUMENT_ROOT'] ?? 'unknown') . "\n";
echo 'Script Path:    ' . __FILE__ . "\n";
echo 'PHP SAPI:       ' . PHP_SAPI . "\n";
echo 'Memory Limit:   ' . ini_get('memory_limit') . "\n";
echo 'Max Exec Time:  ' . ini_get('max_execution_time') . "s\n";
echo 'Upload Max:     ' . ini_get('upload_max_filesize') . "\n";
echo 'Display Errors: ' . ini_get('display_errors') . "\n";
echo 'Error Log:      ' . ini_get('error_log') . "\n";
echo '</pre>';

echo '<h2>📂 Recent Error Log</h2>';
$errorLog = ini_get('error_log');
if ($errorLog && is_file($errorLog) && is_readable($errorLog)) {
    $lines = file($errorLog);
    $tail = array_slice($lines, -30);
    echo '<pre>' . htmlspecialchars(implode('', $tail)) . '</pre>';
} else {
    // Try common cPanel locations
    $cpanelLog = dirname($_SERVER['DOCUMENT_ROOT'] ?? '') . '/logs/error_log';
    if (is_file($cpanelLog) && is_readable($cpanelLog)) {
        $lines = file($cpanelLog);
        $tail = array_slice($lines, -30);
        echo '<pre>' . htmlspecialchars(implode('', $tail)) . '</pre>';
    } else {
        echo '<p class="warn">Cannot read error log. Check <b>cPanel → Metrics → Errors</b> for recent PHP errors.</p>';
    }
}

echo '<hr>';
echo '<p style="color:#dc2626"><b>⚠️ DELETE this file (diagnose.php) after diagnosis — it exposes server information.</b></p>';
echo '</body></html>';
