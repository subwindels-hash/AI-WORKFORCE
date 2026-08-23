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
        echo "AEGIS tools:\n  php index.php tools install           — (re)install schemas and seed RBAC defaults\n  php index.php tools bootstrap_admin   — create initial super-admin from environment variables\n  php index.php tools tests             — run the full test suite\n";
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
        $variant = $driver === 'sqlite' ? 'sqlite' : 'mysql';
        $schemaFiles = [$schemaFile, APPPATH . 'database/sports_identity.' . $variant . '.sql', APPPATH . 'database/sports.' . $variant . '.sql', APPPATH . 'database/sports_decisions.' . $variant . '.sql', APPPATH . 'database/sports_results.' . $variant . '.sql'];
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
        $this->seedAccessControls();
        echo 'OK — schemas installed and RBAC defaults seeded on driver "' . $driver . "\".\n";
    }

    /** CLI only: creates the initial super-admin from environment values. */
    public function bootstrap_admin()
    {
        $email = strtolower(trim((string) getenv('AEGIS_BOOTSTRAP_ADMIN_EMAIL')));
        $password = (string) getenv('AEGIS_BOOTSTRAP_ADMIN_PASSWORD');
        $name = trim((string) (getenv('AEGIS_BOOTSTRAP_ADMIN_NAME') ?: 'Platform Administrator'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 14) {
            fwrite(STDERR, "Set AEGIS_BOOTSTRAP_ADMIN_EMAIL and a 14+ character AEGIS_BOOTSTRAP_ADMIN_PASSWORD.\n"); return;
        }
        $this->seedAccessControls();
        $user = $this->Aegis_model->identity->findUserByEmail($email);
        if ($user) { echo "Admin already exists; no change made.\n"; return; }
        $now = gmdate('c');
        $user = $this->Aegis_model->identity->createUser(['email' => $email, 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'display_name' => $name, 'active' => 1, 'created_at' => $now, 'updated_at' => $now, 'last_login_at' => null]);
        $role = $this->Aegis_model->identity->ensureRole('super_admin', 'Super administrator');
        $this->Aegis_model->identity->assignRole((int) $user['id'], $role);
        $this->Aegis_model->audit->emit('ADMIN_BOOTSTRAPPED', 'Initial super administrator created', ['userId' => $user['id']], 'system');
        echo "Admin created. Remove bootstrap environment variables now.\n";
    }

    private function seedAccessControls(): void
    {
        $identity = $this->Aegis_model->identity;
        $roles = ['super_admin' => 'Super administrator', 'sports_admin' => 'Sports administrator', 'sports_viewer' => 'Sports viewer'];
        $ids = []; foreach ($roles as $code => $name) $ids[$code] = $identity->ensureRole($code, $name);
        $permissions = ['system.super_admin' => 'Full platform administration', 'sports.view' => 'View sports intelligence', 'sports.manage' => 'Manage sports providers and configuration', 'sports.approve' => 'Approve sports tickets', 'sports.settle' => 'Override sports settlements'];
        foreach ($permissions as $code => $name) {
            $pid = $identity->ensurePermission($code, $name);
            $identity->grantRolePermission($ids['super_admin'], $pid);
            if (in_array($code, ['sports.view'], true)) $identity->grantRolePermission($ids['sports_viewer'], $pid);
            if (in_array($code, ['sports.view', 'sports.manage', 'sports.approve', 'sports.settle'], true)) $identity->grantRolePermission($ids['sports_admin'], $pid);
        }
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
