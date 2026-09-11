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
        echo "AI Workforce tools:\n  php index.php tools install           — (re)install schemas and seed RBAC defaults\n  php index.php tools bootstrap_admin   — create initial super-admin from environment variables\n  php index.php tools tests             — run the full test suite\n  php index.php tools marketdata        — market-data connectivity report (add --activate to go live, --probe to fetch real bars)\n  php index.php tools cron              — scheduled operations: portfolio risk scan, broker transitions, proposal expiry\n  php index.php tools scheduler [job]   — unified scheduler: runs every enabled + due job ({$groups})\n  php index.php tools sports-cron [job] [date] — sports scheduled jobs (fixtures|odds|results|quality|ticket|settlement|performance|monitoring|cleanup); optional YYYY-MM-DD re-runs a job for that day\n  php index.php tools sports-calibration-check [date] — calibration persistence check after a CALIBRATION_PERSIST_FAILED alert\n  php index.php tools football-cron [job] — football refresh jobs ({$footballJobs}); --force bypasses cadence\n  php index.php tools lottery-cron [job] — lottery scheduled jobs (sync|health|statistics|systems|tickets|backtests|intelligence|cleanup)\n  php index.php tools lottery-smoke     — live check of the configured lottery feed (LoteriasAPI / authorized feed); add --raw to print the vendor's own payload\n";
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
     * Individual jobs: fixtures | odds | live | results | quality | ticket |
     *                  settlement | performance | monitoring | cleanup
     * `live` self-gates on WINDELS_SPORTS_LIVE_REFRESH_SECONDS, so it is also
     * safe on a every-minute schedule (tools sports-cron live).
     *
     * An optional UTC date re-runs a job for that day instead of today —
     * the operational retry path when a blocked day (e.g. a
     * CALIBRATION_PERSIST_FAILED ticket run) must be re-evaluated after the
     * fix: php /path/to/index.php tools sports-cron ticket 2026-09-10
     * Blocked days release their execution key, so the same date is
     * retryable without bumping the configuration version.
     */
    public function sports_cron()
    {
        $job = trim((string) ($_SERVER['argv'][3] ?? ''));
        $date = trim((string) ($_SERVER['argv'][4] ?? ''));
        // --force on the ticket job invalidates the day's ACTIVE candidate
        // state (old pass predictions/ticket/daily slot/unquotable odds)
        // before regenerating; settled/historical records are preserved.
        $options = in_array('--force', (array) ($_SERVER['argv'] ?? []), true) ? ['force' => true] : [];
        if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            // tolerate the flag appearing in the date slot
            if ($date === '--force') { $date = ''; } else {
                fwrite(STDERR, 'invalid date (expected YYYY-MM-DD): ' . $date . "\n");
                exit(1);
            }
        }
        $service = new \AIWorkforce\Sports\SportsCronService($this->AIWorkforce_model->sports, $this->AIWorkforce_model->audit, $this->platform->sports);
        if ($job !== '' && $job !== '--force') {
            if (!in_array($job, \AIWorkforce\Sports\SportsCronService::JOBS, true)) {
                fwrite(STDERR, 'unknown job. Valid: ' . implode(', ', \AIWorkforce\Sports\SportsCronService::JOBS) . "\n");
                exit(1);
            }
            $summary = $service->run($job, $date !== '' ? $date : null, $options);
        } else {
            $summary = $service->runAll($date !== '' ? $date : null, $options);
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
     * Calibration persistence check — the inspection a
     * CALIBRATION_PERSIST_FAILED alert asks for, in one read-only command:
     *
     *   php index.php tools sports-calibration-check [YYYY-MM-DD]
     *
     * Reports, as JSON:
     *   • the declared sports_calibrations.method column type and whether the
     *     bootstrap marker fits it (the column must fit the marker — a narrow
     *     legacy VARCHAR(16) is fine for the 8-char marker, anything under 8
     *     is not),
     *   • every stored calibration row with the bootstrap marker recognized
     *     by prefix (so legacy truncated 'identity-bootstr' rows are visible
     *     as what they are),
     *   • the stored daily-ticket row for the date (status + message),
     *   • the latest DAILY_TICKET job runs with their recorded errors (the
     *     DB-side error ledger),
     *   • the durable calibration/ticket audit trail.
     */
    /**
     * Odds Prediction Ticket Engine — the full diagnostic view of one day's
     * generation (requirement #14).
     *
     * Prints the complete funnel, the selection tiers that were tried and why
     * each failed, the per-candidate decision table
     *
     *   Fixture -> Market -> Model Probability -> Confidence -> Data Quality
     *           -> Odds -> Value -> Risk -> Correlation -> Final Decision
     *
     * and the generated ticket with its legs, when the data supports one.
     *
     *   php index.php tools ticket_funnel [YYYY-MM-DD] [--force]
     *
     * Read-only apart from the generation itself: it calls exactly the same
     * entry point the cron uses, so what it prints IS what the engine did.
     */
    public function ticket_funnel()
    {
        $date = trim((string) ($_SERVER['argv'][3] ?? ''));
        if ($date === '--force' || $date === '') $date = gmdate('Y-m-d');
        if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $date)) {
            fwrite(STDERR, 'invalid date (expected YYYY-MM-DD): ' . $date . "\n");
            return;
        }
        $options = in_array('--force', (array) ($_SERVER['argv'] ?? []), true) ? ['force' => true] : [];

        $sports = $this->platform->sports;
        $run = $sports->dailyTickets->runDaily($date, null, $options);
        $funnel = is_array($run['diagnostics'] ?? null) ? $run['diagnostics'] : [];
        $config = $sports->configuration->active();

        $line = str_repeat('=', 100);
        echo $line, "\n", 'ODDS PREDICTION TICKET ENGINE — ', $date, "\n", $line, "\n";
        echo 'status            : ', (string) ($run['status'] ?? '?'), "\n";
        echo 'ticket            : ', (string) ($run['ticketId'] ?? '(none)'), "\n";
        echo 'message           : ', (string) ($run['message'] ?? ''), "\n\n";

        echo "CONFIGURED THRESHOLDS\n";
        printf("  min confidence %.0f%%   min data quality %d   min EV %.3f   odds %.2f-%.2f   max selections %d   max correlation %s\n",
            (float) $config['min_confidence'], (int) $config['min_data_quality'], (float) $config['min_expected_value'],
            (float) $config['target_odds_min'], (float) $config['target_odds_max'], (int) $config['max_selections'], (string) $config['max_correlation']);

        // Requirements #1/#8: the ADAPTIVE ladder actually in force — the
        // confidence each data-quality band must reach, and the band below
        // which nothing is predictable at all.
        $policy = \AIWorkforce\Sports\ConfidencePolicy::fromConfiguration($config);
        echo "\nADAPTIVE CONFIDENCE POLICY (data quality band -> confidence required)\n";
        foreach ($policy->tiers() as $tier) {
            printf("  %-10s data quality >= %-4d -> %.0f%% confidence required   markets: %s\n",
                (string) $tier['tier'], (int) $tier['minDataQuality'], (float) $tier['minConfidence'],
                is_string($tier['markets']) ? (string) $tier['markets'] : 'custom list');
        }
        printf("  %-10s data quality <  %-4d -> no prediction is qualified at any confidence\n\n",
            'REJECT', $policy->minimumDataQuality());

        echo "FUNNEL\n";
        $stages = [
            'fixtures evaluated' => $run['evaluated'] ?? 0,
            'eligible' => $funnel['eligibleFixtures'] ?? 0,
            'with-form' => $funnel['fixturesWithRecentForm'] ?? 0,
            'fresh-odds' => $funnel['fixturesWithFreshOdds'] ?? 0,
            'sufficient-data fixtures' => $funnel['sufficientDataFixtures'] ?? 0,
            'markets evaluated' => $funnel['marketsEvaluated'] ?? 0,
            'predictions generated' => $funnel['predictionsGenerated'] ?? 0,
            'predictions reused' => $funnel['predictionsReused'] ?? 0,
            'confidence-qualified' => $funnel['confidenceQualifiedCandidates'] ?? 0,
            'positive-value' => $funnel['positiveValueCandidates'] ?? 0,
            'risk-qualified' => $funnel['riskQualifiedCandidates'] ?? 0,
            'eligible ticket pool' => $funnel['eligiblePoolSize'] ?? 0,
            'preferred pool' => $funnel['preferredPoolSize'] ?? 0,
            'correlation-qualified' => $funnel['correlationQualifiedCandidates'] ?? 0,
            'FINAL (ticket legs)' => $funnel['finalQualifiedCandidates'] ?? 0,
        ];
        foreach ($stages as $label => $value) printf("  %-26s %s\n", $label, (string) (int) $value);
        printf("  %-26s %s\n", 'average confidence',
            $funnel['averageConfidence'] === null ? 'Unavailable (nothing measurable)' : number_format((float) $funnel['averageConfidence'], 2) . '%');
        echo '  selection tier             ', (string) ($funnel['selectionTier'] ?? '(none)'),
            '   fallback used: ', !empty($funnel['fallbackUsed']) ? 'YES' : 'no', "\n";
        if (!empty($funnel['fallbackReason'])) echo '  fallback reason            ', (string) $funnel['fallbackReason'], "\n";
        echo "\n";

        // Requirement #14: the day's real spread, so an average can never
        // hide the shape of the distribution behind it.
        echo "CONFIDENCE DISTRIBUTION (evaluated candidates)\n";
        $confidenceBands = (array) ($funnel['confidenceDistribution'] ?? []);
        if ($confidenceBands === []) echo "  (no candidate was scored)\n";
        foreach ($confidenceBands as $band => $count) {
            printf("  %-18s %-4d %s\n", (string) $band, (int) $count, str_repeat('#', min(40, (int) $count)));
        }
        echo "\nDATA QUALITY DISTRIBUTION (evaluated candidates)\n";
        $qualityBands = (array) ($funnel['dataQualityDistribution'] ?? []);
        if ($qualityBands === []) echo "  (no candidate was scored)\n";
        foreach ($qualityBands as $band => $count) {
            printf("  %-18s %-4d %s\n", (string) $band, (int) $count, str_repeat('#', min(40, (int) $count)));
        }
        $byTier = (array) ($funnel['candidatesByDataTier'] ?? []);
        if ($byTier !== []) {
            echo "\nCANDIDATES BY ADAPTIVE TIER (the requirement each leg actually faced)\n";
            foreach ($byTier as $tier => $count) printf("  %-18s %d\n", (string) $tier, (int) $count);
            printf("  %-18s %d\n", 'market-restricted', (int) ($funnel['marketsRestrictedByDataTier'] ?? 0));
        }
        echo "\n";

        echo "REJECTIONS (one primary reason per rejected fixture/candidate)\n";
        $reasons = (array) ($funnel['topRejectionReasons'] ?? $run['rejectionSummary'] ?? []);
        if ($reasons === []) echo "  (none)\n";
        foreach ($reasons as $reason => $count) printf("  %-34s %s\n", (string) $reason, (string) $count);
        echo "\n";

        echo "SELECTION TIERS TRIED\n";
        $attempts = (array) ($funnel['selectionAttempts'] ?? []);
        if ($attempts === []) echo "  (the final selection stage was never reached)\n";
        foreach ($attempts as $attempt) {
            printf("  %-22s pool=%-3d cap=%-7s found=%-3s %s\n",
                (string) ($attempt['tier'] ?? '?'), (int) ($attempt['poolSize'] ?? 0),
                (string) ($attempt['correlationCap'] ?? '?'), !empty($attempt['found']) ? 'YES' : 'no',
                (string) ($attempt['reason'] ?? ''));
        }
        echo "\n";

        echo "CANDIDATE DECISIONS — fixture -> market -> model probability -> confidence -> data quality -> odds -> value -> risk -> correlation -> decision\n";
        $rows = (array) ($funnel['candidateDecisions'] ?? []);
        if ($rows === []) echo "  (no candidate reached the final selection stage)\n";
        foreach ($rows as $row) {
            printf("  %-28s %-14s %-14s p=%-8s conf=%-7s dq=%-4s odds=%-7s ev=%-9s risk=%-7s corr=%-7s => %-24s %s\n",
                mb_substr((string) ($row['fixture'] ?? '?'), 0, 28),
                (string) ($row['market'] ?? '-'), (string) ($row['selection'] ?? '-'),
                $row['modelProbability'] === null ? '-' : (string) round((float) $row['modelProbability'], 4),
                $row['confidence'] === null ? 'n/a' : (string) round((float) $row['confidence'], 2),
                (string) (int) ($row['dataQuality'] ?? 0),
                (string) round((float) ($row['odds'] ?? 0), 2),
                (string) round((float) ($row['expectedValue'] ?? 0), 4),
                (string) ($row['risk'] ?? '-'), (string) ($row['correlation'] ?? '-'),
                (string) ($row['decision'] ?? '-'), implode(',', (array) ($row['reasons'] ?? [])));
            // Requirement #13: the minimum THIS candidate was judged against,
            // and the tier that minimum came from — never a bare rejection.
            printf("      %-24s tier=%-10s required conf=%-6s required dq=%-4s\n", 'adaptive requirement',
                (string) ($row['dataTier'] ?? '-'),
                $row['minConfidence'] === null ? '-' : number_format((float) $row['minConfidence'], 2),
                (string) (int) ($row['minDataQuality'] ?? 0));
        }
        echo "\n";

        if (!empty($run['ticketId'])) {
            $ticket = $this->AIWorkforce_model->sports->findTicket((string) $run['ticketId']);
            echo $line, "\nGENERATED TICKET ", (string) $run['ticketId'], "\n", $line, "\n";
            if (is_array($ticket)) {
                printf("  combined odds %.4f   legs %d   confidence(min) %s   avg confidence %s   data quality(min) %s   risk %s   correlation %s   status %s\n\n",
                    (float) $ticket['total_odds'], (int) $ticket['selection_count'],
                    (string) ($ticket['confidence'] ?? '-'), (string) ($ticket['average_confidence'] ?? '-'),
                    (string) ($ticket['data_quality_score'] ?? '-'), (string) ($ticket['risk'] ?? '-'),
                    (string) ($ticket['correlation'] ?? '-'), (string) ($ticket['approval_status'] ?? $ticket['status'] ?? '-'));
            }
            foreach ($this->AIWorkforce_model->sports->ticketSelections((string) $run['ticketId']) as $i => $leg) {
                printf("  %d. %-22s vs %-22s  %-14s %-14s @ %-6s  model p=%-8s conf=%-7s dq=%-4s EV=%-8s risk=%s\n",
                    $i + 1, (string) ($leg['home_team'] ?? '?'), (string) ($leg['away_team'] ?? '?'),
                    (string) $leg['market'], (string) $leg['selection'], (string) round((float) $leg['odds'], 2),
                    (string) ($leg['calibrated_probability'] ?? '-'), (string) ($leg['confidence'] ?? '-'),
                    (string) ($leg['data_quality'] ?? '-'), (string) ($leg['expected_value'] ?? '-'),
                    (string) ($leg['risk'] ?? '-'));
            }
            echo "\n";
        }
        echo 'TICKET-FUNNEL-RESULT: ', empty($run['ticketId']) ? 'NO_TICKET' : 'TICKET', "\n";
    }

    public function sports_calibration_check()
    {
        $date = trim((string) ($_SERVER['argv'][3] ?? ''));
        if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            fwrite(STDERR, 'invalid date (expected YYYY-MM-DD): ' . $date . "\n");
            exit(1);
        }
        if ($date === '') $date = gmdate('Y-m-d');
        $sports = $this->AIWorkforce_model->sports;
        $marker = \AIWorkforce\Sports\CalibrationBootstrap::method();

        // Declared column type, introspected per driver (best effort — a
        // driver without information_schema access reports null, never a
        // failure of the check itself).
        $columnType = null;
        try {
            $db = $this->AIWorkforce_model->db;
            if (\AIWorkforce\SchemaInstaller::isSqlite($db)) {
                foreach ($db->query('PRAGMA table_info(sports_calibrations)')->result_array() as $col) {
                    if (($col['name'] ?? '') === 'method') $columnType = strtoupper((string) $col['type']);
                }
            } else {
                $row = $db->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sports_calibrations' AND COLUMN_NAME = 'method'")->row_array();
                $columnType = $row['COLUMN_TYPE'] ?? null;
            }
        } catch (\Throwable $e) { $columnType = null; }
        $columnWidth = preg_match('/\((\d+)\)/', (string) $columnType, $w) ? (int) $w[1] : null; // null = unbounded (TEXT) or unknown
        $markerFits = $columnWidth === null || $columnWidth >= strlen($marker);

        $calibrations = [];
        foreach ($sports->listCalibrations(null, null, 100) as $row) {
            $calibrations[] = [
                'id' => (int) $row['id'], 'model_version_id' => (int) $row['model_version_id'],
                'method' => (string) $row['method'], 'method_length' => strlen((string) $row['method']),
                'is_identity_bootstrap' => \AIWorkforce\Sports\CalibrationBootstrap::isIdentityMethod((string) $row['method']),
                'intercept' => (float) $row['intercept'], 'slope' => (float) $row['slope'],
                'samples' => (int) $row['samples'], 'status' => (string) $row['status'],
                'created_by' => $row['created_by'] ?? null, 'approved_by' => $row['approved_by'] ?? null,
                'approved_at' => $row['approved_at'] ?? null, 'created_at' => $row['created_at'] ?? null,
            ];
        }

        $jobRuns = [];
        foreach ($sports->listJobRuns('DAILY_TICKET', 5) as $run) {
            $jobRuns[] = [
                'id' => $run['id'], 'status' => $run['status'], 'started_at' => $run['started_at'], 'ended_at' => $run['ended_at'],
                'errors' => is_array($run['errors'] ?? null) ? $run['errors'] : json_decode((string) ($run['errors'] ?: '[]'), true),
                'execution_key' => $run['execution_key'] ?? null,
            ];
        }

        $auditTrail = [];
        foreach ($this->AIWorkforce_model->audit->recent(300) as $event) {
            $type = (string) ($event['type'] ?? '');
            if (str_starts_with($type, 'SPORTS_CALIBRATION') || str_starts_with($type, 'SPORTS_DAILY_TICKET')) {
                $auditTrail[] = ['type' => $type, 'at' => $event['at'] ?? null, 'actor' => $event['actor'] ?? null, 'summary' => $event['summary'] ?? null];
            }
            if (count($auditTrail) >= 15) break;
        }

        echo json_encode([
            'ranAt' => gmdate('c'),
            'date' => $date,
            'methodColumn' => [
                'declared_type' => $columnType, 'declared_width' => $columnWidth,
                'bootstrap_marker' => $marker, 'marker_length' => strlen($marker),
                'marker_fits_column' => $markerFits,
                'note' => $markerFits
                    ? 'the method column fits the bootstrap marker'
                    : 'COLUMN TOO NARROW FOR THE MARKER — widen sports_calibrations.method (VARCHAR(32)) and re-run',
            ],
            'calibrations' => $calibrations,
            'dailyTicket' => $sports->findDailyTicket($date),
            'recentDailyTicketJobRuns' => $jobRuns,
            'auditTrail' => $auditTrail,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    }

    /**
     * Live connectivity smoke test for the configured lottery feed
     * (LoteriasAPI / authorized official feed).
     *   php index.php tools lottery-smoke
     *   php index.php tools lottery-smoke --raw   (also print the vendor's own
     *     unmapped latest row — the way to see which payload shape a feed that
     *     gets rejected is actually sending)
     * Exit codes: 0 = live data received, 1 = configured but unreachable,
     * 2 = no provider configured. Never prints credentials.
     */
    public function lottery_smoke()
    {
        $flags = array_values(array_filter(array_slice($_SERVER['argv'] ?? [], 3), fn($a) => is_string($a) && str_starts_with($a, '--')));
        $raw = in_array('--raw', $flags, true);
        $provider = $this->platform->lottery->provider;
        $health = $provider->health();
        $report = [
            'provider' => $provider->id(),
            'name' => $provider->name(),
            'health' => $health,
            'draws' => [],
            'jackpot' => null,
        ];
        if ($raw && $provider instanceof \AIWorkforce\Lottery\LoteriasApiProvider) {
            $report['rawLatest'] = $provider->rawLatest();
        }
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
