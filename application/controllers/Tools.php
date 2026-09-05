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
        if (!is_cli() && getenv('AI_WORKFORCE_ALLOW_HTTP_TOOLS') !== '1') {
            show_404();
        }
    }

    public function index()
    {
        // Both job lists are read from the live registries instead of being typed out
        // here: these strings are the operator's only reference for which
        // `tools scheduler <group>` and `tools football-cron <job>` ids are valid, and
        // a hard-coded copy goes stale the moment a job is added or renamed.
        $groups = class_exists(\AIWorkforce\Cron\CronScheduler::class)
            ? implode('|', array_keys(\AIWorkforce\Cron\CronScheduler::JOBS))
            : 'ops|sports|lottery';
        $footballJobs = class_exists(\AIWorkforce\Football\FootballCronService::class)
            ? implode('|', \AIWorkforce\Football\FootballCronService::JOBS)
            : 'fixtures|upcoming|live|results|statistics|predict|settle|performance|cleanup';
        echo "AI Workforce tools:\n  php index.php tools install           — (re)install schemas and seed RBAC defaults\n  php index.php tools bootstrap_admin   — create initial super-admin from environment variables\n  php index.php tools tests             — run the full test suite\n  php index.php tools marketdata        — market-data connectivity report (add --activate to go live, --probe to fetch real bars)\n  php index.php tools cron              — scheduled operations: portfolio risk scan, broker transitions, proposal expiry\n  php index.php tools scheduler [job]   — unified scheduler: runs every enabled + due job ({$groups})\n  php index.php tools sports-cron [job] — sports scheduled jobs (fixtures|odds|results|quality|ticket|settlement|performance|monitoring|cleanup)\n  php index.php tools football-cron [job] — football refresh jobs ({$footballJobs}); --force bypasses cadence\n  php index.php tools lottery-cron [job] — lottery scheduled jobs (sync|health|statistics|systems|tickets|backtests|cleanup)\n  php index.php tools lottery-smoke     — live check of the configured lottery feed (LoteriasAPI / authorized feed)\n";
    }

    public function install()
    {
        // Same modules, expected tables, and upgrades as php tools/install.php.
        \AIWorkforce\SchemaInstaller::installCi($this->db);
        $this->seedAccessControls();
        echo 'OK — schemas installed and RBAC defaults seeded on driver "' . $this->db->platform() . "\".\n";
    }

    /** CLI only: creates the initial super-admin from environment values. */
    public function bootstrap_admin()
    {
        $email = strtolower(trim((string) getenv('AI_WORKFORCE_BOOTSTRAP_ADMIN_EMAIL')));
        $password = (string) getenv('AI_WORKFORCE_BOOTSTRAP_ADMIN_PASSWORD');
        $name = trim((string) (getenv('AI_WORKFORCE_BOOTSTRAP_ADMIN_NAME') ?: 'Platform Administrator'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 14) {
            fwrite(STDERR, "Set AI_WORKFORCE_BOOTSTRAP_ADMIN_EMAIL and a 14+ character AI_WORKFORCE_BOOTSTRAP_ADMIN_PASSWORD.\n"); return;
        }
        $this->seedAccessControls();
        $user = $this->AIWorkforce_model->identity->findUserByEmail($email);
        if ($user) { echo "Admin already exists; no change made.\n"; return; }
        $now = gmdate('c');
        $user = $this->AIWorkforce_model->identity->createUser(['email' => $email, 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'display_name' => $name, 'active' => 1, 'created_at' => $now, 'updated_at' => $now, 'last_login_at' => null]);
        $role = $this->AIWorkforce_model->identity->ensureRole('super_admin', 'Super administrator');
        $this->AIWorkforce_model->identity->assignRole((int) $user['id'], $role);
        $this->AIWorkforce_model->audit->emit('ADMIN_BOOTSTRAPPED', 'Initial super administrator created', ['userId' => $user['id']], 'system');
        echo "Admin created. Remove bootstrap environment variables now.\n";
    }

    private function seedAccessControls(): void
    {
        require_once __DIR__ . '/../../tools/rbac.php';
        $identity = $this->AIWorkforce_model->identity;
        ai_workforce_seed_rbac(
            fn(string $code, string $name): int => $identity->ensureRole($code, $name),
            fn(string $code, string $name): int => $identity->ensurePermission($code, $name),
            fn(int $roleId, int $permissionId): bool => (bool) $identity->grantRolePermission($roleId, $permissionId)
        );
    }

    /**
     * Scheduled operations worker — safe to run every minute from cron:
     *   * * * * * php /path/to/index.php tools cron >> /var/log/ai_workforce-cron.log 2>&1
     * Portfolio risk scan (with broker READY/DOWN transition detection and
     * operator notifications) plus stale-proposal expiry (spec §5).
     */
    public function cron()
    {
        $summary = \AIWorkforce\Cron\CronRunner::ops($this);
        echo json_encode($summary, JSON_UNESCAPED_SLASHES), "\n";
    }

    /**
     * Unified scheduler — run every minute from system cron; only enabled +
     * due jobs execute (per-job locks prevent overlaps):
     *   * * * * * php /path/to/index.php tools scheduler >> /var/log/ai_workforce-cron.log 2>&1
     * Optional single job: php index.php tools scheduler sports
     */
    public function scheduler()
    {
        $store = new \AIWorkforce\Cron\PlatformSettingsCronStore($this->AIWorkforce_model->db);
        $scheduler = new \AIWorkforce\Cron\CronScheduler($store);
        $runners = \AIWorkforce\Cron\CronRunner::runners($this);
        $only = trim((string) ($_SERVER['argv'][3] ?? ''));
        if ($only !== '' && !isset($runners[$only])) {
            fwrite(STDERR, 'unknown job. Valid: ' . implode(', ', array_keys($runners)) . "\n");
            exit(1);
        }
        $result = $only !== ''
            ? [$only => $scheduler->runJob($only, $runners[$only])]
            : $scheduler->runDue(fn(string $id) => $runners[$id] ?? null);
        echo json_encode($result, JSON_UNESCAPED_SLASHES), "\n";
    }

    /**
     * Sports Intelligence scheduled jobs (spec §31) — idempotent, safe to run
     * from cron every 15 minutes (use the standard "every 15 minutes" cron
     * expression) e.g.: php /path/to/index.php tools sports-cron
     * Individual jobs: fixtures | odds | results | quality | ticket |
     *                  settlement | performance | monitoring | cleanup
     */
    public function sports_cron()
    {
        $job = trim((string) ($_SERVER['argv'][3] ?? ''));
        $service = new \AIWorkforce\Sports\SportsCronService($this->AIWorkforce_model->sports, $this->AIWorkforce_model->audit, $this->platform->sports);
        if ($job !== '') {
            if (!in_array($job, \AIWorkforce\Sports\SportsCronService::JOBS, true)) {
                fwrite(STDERR, 'unknown job. Valid: ' . implode(', ', \AIWorkforce\Sports\SportsCronService::JOBS) . "\n");
                exit(1);
            }
            $summary = $service->run($job);
        } else {
            $summary = $service->runAll();
        }
        echo json_encode($summary, JSON_UNESCAPED_SLASHES), "\n";
    }

    /**
     * Football Intelligence refresh sweep (spec §13). Cadence, provider backoff
     * and request budgets are enforced by RefreshPolicy, so running this on a
     * tight schedule is harmless: a job that is not due reports SKIPPED with the
     * reason and never touches the provider.
     *
     *   php index.php tools football-cron                 — every due job
     *   php index.php tools football-cron live           — one job
     *   php index.php tools football-cron fixtures --force — run now, ignore cadence
     */
    public function football_cron()
    {
        $argv = (array) ($_SERVER['argv'] ?? []);
        $job = trim((string) ($argv[3] ?? ''));
        $force = in_array('--force', $argv, true);
        $service = $this->platform->football->cron();
        if ($job !== '' && $job !== '--force') {
            if (!in_array($job, \AIWorkforce\Football\FootballCronService::JOBS, true)) {
                fwrite(STDERR, 'unknown job. Valid: ' . implode(', ', \AIWorkforce\Football\FootballCronService::JOBS) . "\n");
                exit(1);
            }
            $summary = $service->run($job, null, $force);
        } else {
            $summary = $service->runAll($force);
        }
        echo json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
    }

    /**
     * Sports provider live smoke test — proves the configured providers
     * actually work against the real APIs, layer by layer (health/auth,
     * fixtures, odds, top players). Read-only; costs a few API requests.
     *
     *   php index.php tools sports-live              — all configured providers
     *   php index.php tools sports-live api-football — one provider
     *
     * Exit codes: 0 all pass, 1 one or more failed, 2 none configured.
     */
    public function sports_live()
    {
        $only = trim((string) ($_SERVER['argv'][3] ?? ''));
        $report = (new \AIWorkforce\Sports\SportsLiveSmoke())->run($this->platform->sports->providers, $only);
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
        if (empty($report['configured'])) exit(2);
        exit(!empty($report['pass']) ? 0 : 1);
    }

    /**
     * Live connectivity smoke test for the configured lottery feed
     * (LoteriasAPI / authorized official feed).
     *   php index.php tools lottery-smoke
     * Exit codes: 0 = live data received, 1 = configured but unreachable,
     * 2 = no provider configured. Never prints credentials.
     */
    public function lottery_smoke()
    {
        $provider = $this->platform->lottery->provider;
        $health = $provider->health();
        $report = [
            'provider' => $provider->id(),
            'name' => $provider->name(),
            'health' => $health,
            'draws' => [],
            'jackpot' => null,
        ];
        if (($health['state'] ?? '') === 'ONLINE') {
            foreach ($provider->draws(null, null, 3) as $draw) {
                $report['draws'][] = [
                    'externalId' => $draw['externalId'] ?? null,
                    'drawDate' => $draw['drawDate'] ?? null,
                    'main' => $draw['main'] ?? null,
                    'stars' => $draw['stars'] ?? null,
                    'source' => $draw['source'] ?? null,
                ];
            }
            $report['jackpot'] = $provider->jackpotInfo();
        }
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
        if (in_array($health['state'] ?? '', ['UNCONFIGURED', 'DISABLED'], true)) exit(2);
        exit($report['draws'] !== [] ? 0 : 1);
    }

    /**
     * WINDELS Lottery Intelligence scheduled jobs (spec §40).
     * php /path/to/index.php tools lottery-cron [job]
     * Individual jobs: sync | health | statistics | cleanup
     */
    public function lottery_cron()
    {
        $job = trim((string) ($_SERVER['argv'][3] ?? ''));
        $service = new \AIWorkforce\Lottery\LotteryCronService($this->AIWorkforce_model->lottery, $this->AIWorkforce_model->audit, $this->platform->lottery);
        if ($job !== '') {
            if (!in_array($job, \AIWorkforce\Lottery\LotteryCronService::JOBS, true)) {
                fwrite(STDERR, 'unknown job. Valid: ' . implode(', ', \AIWorkforce\Lottery\LotteryCronService::JOBS) . "\n");
                exit(1);
            }
            $summary = $service->run($job);
        } else {
            $summary = $service->runAll();
        }
        echo json_encode($summary, JSON_UNESCAPED_SLASHES), "\n";
    }

    /**
     * Market-data connectivity report + "make it live" switch.
     *
     *   php index.php tools marketdata              — report only (no changes)
     *   php index.php tools marketdata --activate   — enable the keyless public
     *                                                 feeds that are connected
     *                                                 but not yet serving
     *   php index.php tools marketdata --probe      — also fetch real bars and
     *                                                 report LIVE/SYNTHETIC per
     *                                                 market class
     *
     * Safe to run any time: report mode changes nothing, and --activate only
     * promotes a public no-key feed (Binance / Frankfurter) that an operator
     * already saved in Admin → API. It never enables custom_http, a licensed
     * feed or anything that needs a credential, and never touches a service
     * that is already live.
     */
    public function marketdata()
    {
        $argv = $_SERVER['argv'] ?? [];
        $flags = array_values(array_filter(array_slice($argv, 3), fn($a) => str_starts_with((string) $a, '--')));
        $activate = in_array('--activate', $flags, true);
        $probe = in_array('--probe', $flags, true) || $activate;

        $db = $this->AIWorkforce_model->db;
        \AIWorkforce\ApiProviders::ensureSchema($db);

        if (getenv('AI_WORKFORCE_DISABLE_REAL_PROVIDERS') === '1') {
            fwrite(STDERR, "AI_WORKFORCE_DISABLE_REAL_PROVIDERS=1 — every market-data call is forced onto the labelled SIMULATION provider. Unset it to go live.\n");
        }

        $services = [];
        $activated = [];
        foreach (\AIWorkforce\ApiProviders::MARKET_DATA_SERVICES as $service) {
            $before = \AIWorkforce\ApiProviders::serviceState($db, $service);
            $action = null;
            if ($activate && !$before['live']) {
                $action = \AIWorkforce\ApiProviders::activateKeylessFeed($db, $service);
                if ($action['ok'] && $action['action'] === 'activated') {
                    $this->AIWorkforce_model->audit->emit(
                        'MARKET_DATA_ACTIVATED',
                        sprintf('%s switched to LIVE from the CLI (%s)', $before['label'], $action['driver'] ?? ''),
                        ['service' => $service, 'providerId' => $action['id'] ?? null, 'driver' => $action['driver'] ?? null],
                        'system'
                    );
                }
            }
            $after = $activate ? \AIWorkforce\ApiProviders::serviceState($db, $service) : $before;
            if ($action) $activated[$service] = $action;
            $services[$service] = $after;
        }

        // Rebuild the chain in this process so the report reflects the change.
        $registry = $activate ? $this->platform->refreshMarketDataProviders() : null;

        $health = [];
        foreach ($this->platform->providers->getAllHealth(true) as $h) {
            if (!empty($h['synthetic'])) continue; // the fallback is not a connection
            $health[] = [
                'name' => $h['name'] ?? '?',
                'status' => $h['status'] ?? '?',
                'latencyMs' => $h['latencyMs'] ?? null,
                'detail' => $h['detail'] ?? ($h['lastError'] ?? null),
            ];
        }

        $live = [];
        if ($probe) {
            foreach ([['crypto', 'BTCUSDT', '1h'], ['forex', 'EURUSD', '1d'], ['stock', 'AAPL', '1d']] as [$class, $symbol, $tf]) {
                try {
                    $series = $this->platform->providers->getCandleSeries($symbol, $class, $tf, 60);
                    $p = $series['provenance'];
                    $reason = !empty($p['synthetic']) ? 'SYNTHETIC'
                        : (!empty($p['stale']) ? 'STALE' : (!empty($p['delayed']) ? 'DELAYED' : 'LIVE'));
                    $last = count($series['candles']) ? end($series['candles']) : null;
                    $live[$class] = [
                        'symbol' => $symbol, 'timeframe' => $tf, 'live' => $reason === 'LIVE', 'reason' => $reason,
                        'source' => $p['source'], 'bars' => count($series['candles']),
                        'lastClose' => $last ? (float) $last['close'] : null,
                        'barTime' => $last ? gmdate('c', (int) ($last['timestamp'] / 1000)) : null,
                        'fallbackChain' => $p['fallbackChain'] ?? [],
                    ];
                } catch (Throwable $e) {
                    $live[$class] = ['symbol' => $symbol, 'timeframe' => $tf, 'live' => false, 'reason' => 'NO_PROVIDER', 'error' => $e->getMessage()];
                }
            }
        }

        $anyLive = false;
        foreach ($live as $v) if (!empty($v['live'])) $anyLive = true;

        echo json_encode([
            'ranAt' => gmdate('c'),
            'realProvidersAllowed' => getenv('AI_WORKFORCE_DISABLE_REAL_PROVIDERS') !== '1',
            'activated' => $activated,
            'registry' => $registry,
            'services' => $services,
            'providerHealth' => $health,
            'live' => $live ?: null,
            'marketDataLive' => $probe ? $anyLive : null,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    }

    public function tests()
    {
        require_once TESTSPATH . 'framework.php';
        // Provide the shared helpers (run(), assert_eq(), domain autoloading)
        // for the newer suites before any case loads, so a filtered run of a
        // single case still works.
        require_once TESTSPATH . 'bootstrap.php';
        $suites = glob(TESTSPATH . 'cases/*.php') ?: [];
        sort($suites);
        $filter = trim((string) (getenv('AI_WORKFORCE_TEST_FILTER') ?: ($_SERVER['argv'][3] ?? '')));
        if ($filter !== '') {
            $needles = array_filter(array_map('trim', explode(',', $filter)));
            $suites = array_values(array_filter($suites, function (string $file) use ($needles): bool {
                $base = basename($file);
                foreach ($needles as $n) {
                    if ($n !== '' && str_contains($base, $n)) return true;
                }
                return false;
            }));
        }
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
        if (PHP_SAPI === 'cli' && !defined('AI_WORKFORCE_NO_EXIT')) {
            exit($failures > 0 ? 1 : 0);
        }
    }

}
