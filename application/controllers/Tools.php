<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * CLI utilities: database install + test runner.
 *   php index.php tools install
 *   php index.php tools tests
 */
class Tools extends MY_Controller
{
    public function __construct()
    {
        parent::__construct();
        if (!is_cli() && getenv('AEGIS_ALLOW_HTTP_TOOLS') !== '1') {
            show_404();
        }
    }

    public function index()
    {
        echo "AEGIS tools:\n  php index.php tools install   — (re)install the database schema\n  php index.php tools tests     — run the full test suite\n";
    }

    public function install()
    {
        $this->load->helper('file');
        $driver = $this->db->platform(); // mysql / sqlite
        $schemaFile = APPPATH . 'database/schema.' . ($driver === 'sqlite' ? 'sqlite' : 'mysql') . '.sql';
        if (!is_file($schemaFile)) {
            fwrite(STDERR, "schema not found: {$schemaFile}\n");
            exit(1);
        }
        $schemaFiles = [$schemaFile, APPPATH . 'database/sports_identity.' . ($driver === 'sqlite' ? 'sqlite' : 'mysql') . '.sql'];
        foreach ($schemaFiles as $file) {
            if (!is_file($file)) continue;
            $sql = file_get_contents($file);
            // Strip whole-line SQL comments before splitting: otherwise a leading
            // comment can cause the first CREATE TABLE statement to be skipped.
            $sql = preg_replace('/^\s*--[^\r\n]*[\r\n]?/m', '', $sql);
            $statements = array_filter(array_map('trim', preg_split('/;\s*[\r\n]+/', $sql)));
            foreach ($statements as $stmt) {
                if ($stmt === '') continue;
                $this->db->query($stmt);
            }
        }
        echo 'OK — schemas installed on driver "' . $driver . "\".\n";
    }

    public function tests()
    {
        require_once TESTSPATH . 'framework.php';
        $suites = glob(TESTSPATH . 'cases/*.php') ?: [];
        sort($suites);
        foreach ($suites as $file) {
            require_once $file;
        }
        $failures = run_all_tests();
        // Sentinel instead of exit(): the WASM runtime loses buffered output
        // when PHP exits non-zero; callers parse TESTS-RESULT for the code.
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        echo "TESTS-RESULT: {$failures}\n";
        if (PHP_SAPI === 'cli' && !defined('AEGIS_NO_EXIT')) {
            exit($failures > 0 ? 1 : 0);
        }
    }
}
