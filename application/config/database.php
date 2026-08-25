<?php
defined('BASEPATH') or exit('No direct script access allowed');

/*
|--------------------------------------------------------------------------
| AI_WORKFORCE database configuration
|--------------------------------------------------------------------------
| Production target: MySQL / MariaDB via the mysqli driver (default).
| Offline dev runtime: the same CodeIgniter application can run on
| pdo_sqlite by setting AI_WORKFORCE_DB_DRIVER=pdo_sqlite (used by the sandbox
| demo + tests, where no MySQL server is reachable). The schema is
| installed by tools/install.php for either driver.
|
| Credentials come from environment variables when available.
*/
$active_group = 'default';
$query_builder = true;

$driver = getenv('VP_DB_DRIVER') ?: (getenv('AI_WORKFORCE_DB_DRIVER') ?: 'mysqli');

if ($driver === 'pdo_sqlite') {
    $db['default'] = [
        'dsn' => 'sqlite:' . (getenv('AI_WORKFORCE_SQLITE_PATH') ?: dirname(__DIR__) . '/data/ai_workforce.sqlite'),
        'hostname' => '',
        'username' => '',
        'password' => '',
        'database' => '',
        'dbdriver' => 'pdo',
        'subdriver' => 'sqlite',
        'dbprefix' => '',
        'pconnect' => false,
        'db_debug' => (getenv('AI_WORKFORCE_DB_DEBUG') === '1'),
        'cache_on' => false,
        'cachedir' => '',
        'char_set' => 'utf8mb4',
        'dbcollat' => 'utf8mb4_general_ci',
        'swap_pre' => '',
        'encrypt' => false,
        'compress' => false,
        'stricton' => false,
        'failover' => [],
        'save_queries' => true,
    ];
} else {
    $db['default'] = [
        'dsn' => '',
        'hostname' => getenv('VP_DB_HOST') ?: (getenv('AI_WORKFORCE_DB_HOST') ?: 'localhost'),
        'port' => (int)(getenv('VP_DB_PORT') ?: (getenv('AI_WORKFORCE_DB_PORT') ?: 3306)),
        'username' => getenv('VP_DB_USER') ?: (getenv('AI_WORKFORCE_DB_USER') ?: ''),
        'password' => getenv('VP_DB_PASS') ?: (getenv('AI_WORKFORCE_DB_PASS') ?: ''),
        'database' => getenv('VP_DB_NAME') ?: (getenv('AI_WORKFORCE_DB_NAME') ?: ''),
        'dbdriver' => 'mysqli',
        'dbprefix' => '',
        'pconnect' => false,
        'db_debug' => false,
        'cache_on' => false,
        'cachedir' => '',
        'char_set' => 'utf8mb4',
        'dbcollat' => 'utf8mb4_general_ci',
        'swap_pre' => '',
        'encrypt' => false,
        'compress' => false,
        'stricton' => true,
        'failover' => [],
        'save_queries' => true,
    ];
}
