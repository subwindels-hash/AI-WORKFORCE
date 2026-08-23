<?php
defined('BASEPATH') or exit('No direct script access allowed');

/*
|--------------------------------------------------------------------------
| AEGIS database configuration
|--------------------------------------------------------------------------
| Production target: MySQL / MariaDB via the mysqli driver (default).
| Offline dev runtime: the same CodeIgniter application can run on
| pdo_sqlite by setting AEGIS_DB_DRIVER=pdo_sqlite (used by the sandbox
| demo + tests, where no MySQL server is reachable). The schema is
| installed by tools/install.php for either driver.
|
| Credentials come from environment variables when available.
*/
$active_group = 'default';
$query_builder = true;

$driver = getenv('AEGIS_DB_DRIVER') ?: 'mysqli';

if ($driver === 'pdo_sqlite') {
    $db['default'] = [
        'dsn' => 'sqlite:' . (getenv('AEGIS_SQLITE_PATH') ?: dirname(__DIR__) . '/data/aegis.sqlite'),
        'hostname' => '',
        'username' => '',
        'password' => '',
        'database' => '',
        'dbdriver' => 'pdo',
        'subdriver' => 'sqlite',
        'dbprefix' => '',
        'pconnect' => false,
        'db_debug' => (getenv('AEGIS_DB_DEBUG') === '1'),
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
        'hostname' => getenv('AEGIS_DB_HOST') ?: '127.0.0.1',
        'username' => getenv('AEGIS_DB_USER') ?: 'aegis',
        'password' => getenv('AEGIS_DB_PASS') ?: 'aegis',
        'database' => getenv('AEGIS_DB_NAME') ?: 'aegis_trading',
        'dbdriver' => 'mysqli',
        'dbprefix' => '',
        'pconnect' => false,
        'db_debug' => (getenv('AEGIS_DB_DEBUG') === '1'),
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
