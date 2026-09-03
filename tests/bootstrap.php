<?php
/**
 * Shared bootstrap for the unit-test suites (80+, 83+, 84+, 85).
 *
 * The namespaced cases (AIWorkforce\Tests) build an array of closures and
 * register them with a `run($name, $tests)` helper instead of the global
 * `test()` collector. This file defines `run()` and the `assert_eq()`
 * assertion alias, and makes sure the AIWorkforce + LeadDiscovery domain
 * classes are loaded. Everything here is idempotent and safe to require from
 * inside the CI3 CLI harness.
 */

if (!defined('TESTSPATH')) {
    define('TESTSPATH', __DIR__ . '/');
}

// The harness already requires framework.php before the cases; avoid
// double-registering its global helper functions when loaded inside CI3.
if (!function_exists('test')) {
    require_once __DIR__ . '/framework.php';
}

// AIWorkforce\* domain classes (idempotent after MY_Controller's require).
if (defined('APPPATH') && is_file(APPPATH . 'libraries/AIWorkforce/autoload.php')) {
    require_once APPPATH . 'libraries/AIWorkforce/autoload.php';
}

// LeadDiscovery\* providers — required explicitly by Api_lead_discovery in the
// running app; load them here so tests can use them without a controller.
$leadDiscoveryFiles = [
    'LeadDiscoveryProvider.php',
    'ProviderException.php',
    'GooglePlacesProvider.php',
    'ApolloProvider.php',
    'ProviderRegistry.php',
    'Deduplicator.php',
];
foreach ($leadDiscoveryFiles as $file) {
    $path = APPPATH . 'libraries/LeadDiscovery/' . $file;
    if (is_file($path)) {
        require_once $path;
    }
}

if (!function_exists('assert_eq')) {
    /**
     * Alias of assert_equals() with a leading message argument ordering that
     * matches the namespaced suites (expected, actual, label).
     */
    function assert_eq($expected, $actual, string $msg = ''): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException('ASSERT: ' . ($msg ?: 'expected ' . var_export($expected, true) . ' got ' . var_export($actual, true)));
        }
    }
}

if (!function_exists('run')) {
    /**
     * Register a namespaced suite's closures with the global collector so the
     * harness's run_all_tests() executes and counts them. Running them here
     * directly would escape the per-test failure isolation.
     */
    function run(string $name, array $tests): void
    {
        foreach ($tests as $i => $t) {
            test($name . ' #' . ($i + 1), $t);
        }
    }
}
