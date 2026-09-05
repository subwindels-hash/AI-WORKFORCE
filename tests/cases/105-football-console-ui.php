<?php
/**
 * Football Intelligence — surface wiring: routes, controllers, permissions and the
 * console screens (spec §1/§2/§10/§16/§17).
 *
 * These cases check that the documented endpoints exist, that a mutation cannot
 * be reached without the RBAC capability *and* the CSRF token the form carries,
 * and that each panel of the football story is rendered on exactly one screen.
 * Markup rendering itself is exercised through the running application in
 * `e2e/football-views.php`; here it is guarded, because only CodeIgniter has a
 * view loader.
 */
require_once TESTSPATH . 'football_support.php';

use AIWorkforce\Football\FootballDiagnostics;
use AIWorkforce\Football\PerformanceService;

/**
 * Read an application source file. Inside CI this is FCPATH-relative; the flat
 * php-wasm harness copies the same files under dashed names, so both run the
 * same assertions instead of one of them silently skipping.
 */
function fx_fb_source(string $relative): string
{
    $candidates = [];
    if (defined('FCPATH')) $candidates[] = FCPATH . $relative;
    if (defined('TESTSPATH')) $candidates[] = rtrim(TESTSPATH, '/\\') . '/../../' . $relative;
    $candidates[] = '/app/src/' . str_replace(['/', '.php'], ['-', ''], $relative) . '.php';
    foreach ($candidates as $path) {
        if (is_file($path)) return (string) file_get_contents($path);
    }
    throw new RuntimeException('cannot read ' . $relative . ' (looked in: ' . implode(', ', $candidates) . ')');
}

test('football: every documented endpoint is routed to a real controller method', function () {
    $routes = fx_fb_source('application/config/routes.php');
    $expected = [
        'api/football/fixtures' => 'api_football/fixtures',
        'api/football/fixtures/today' => 'api_football/fixtures_today',
        'api/football/fixtures/live' => 'api_football/fixtures_live',
        'api/football/matches/(:num)' => 'api_football/show_match/$1',
        'api/football/matches/(:num)/analysis' => 'api_football/analysis/$1',
        'api/football/matches/(:num)/prediction' => 'api_football/prediction/$1',
        'api/football/predictions/today' => 'api_football/predictions_today',
        'api/football/predictions/history' => 'api_football/predictions_history',
        'api/football/performance' => 'api_football/performance',
        'api/football/models' => 'api_football/models',
        'api/football/models/active' => 'api_football/models_active',
        'api/football/calibrations' => 'api_football/calibrations',
        'api/football/provider/status' => 'api_football/provider_status',
        'api/admin/football/models/(:num)/approve' => 'api_football/approve_model/$1',
        'api/admin/football/models/(:num)/activate' => 'api_football/activate_model/$1',
        // The console screens the module was asked for.
        'football' => 'football',
        'football/live' => 'football/live',
        'football/models' => 'football/models',
        'football/match/(:num)' => 'football/match/$1',
    ];
    $api = fx_fb_source('application/controllers/Api_football.php');
    $controller = fx_fb_source('application/controllers/Football.php');
    foreach ($expected as $key => $target) {
        $line = '$route[\'' . $key . '\'] = \'' . $target . '\';';
        assert_contains($line, $routes, $key . ' is routed to ' . $target);
        $parts = explode('/', $target);
        $class = $parts[0];
        $method = $parts[1] ?? 'index';
        $source = $class === 'api_football' ? $api : $controller;
        assert_contains('public function ' . $method . '(', $source, $class . '::' . $method . '() exists');
    }
    // CI3 anchors every route, so a (:num) parent can never shadow its sub-path.
    assert_contains("or exit('No direct script access allowed');", $api);
});

test('football: authorization follows the RBAC matrix, and only health is public', function () {
    $api = fx_fb_source('application/controllers/Api_football.php');
    foreach (['sports.view', 'sports.manage', 'sports.approve', 'sports.settle'] as $permission) {
        assert_contains("'" . $permission . "'", $api, $permission . ' governs the matching endpoint');
    }
    foreach (['provider_status', 'status'] as $public) {
        $start = strpos($api, 'public function ' . $public . '(');
        assert_true($start !== false, $public . '() is implemented');
        $rest = substr($api, (int) $start);
        $body = (string) substr($rest, 0, (int) strpos($rest, 'public function '));
        assert_true(!str_contains($body, 'requirePermission'), $public . '() stays readable without a session');
        assert_true(!str_contains(strtolower($body), 'token') && !str_contains(strtolower($body), 'credential'),
            $public . '() returns no secrets');
    }
    // A console mutation needs the capability and the token, in that order.
    $controller = fx_fb_source('application/controllers/Football.php');
    foreach (['sports.manage', 'sports.approve', 'sports.settle'] as $permission) {
        assert_contains("requireFootballPermission('" . $permission . "'", $controller, $permission . ' gates the console action');
    }
    assert_contains("hash_equals(\$known, \$sent)", $controller, 'with a constant-time CSRF check');
    assert_contains("\$this->input->method(true) !== 'POST'", $controller, 'and no state change on a GET');
    // The two status endpoints are the only public API surface.
    assert_equals(2, preg_match_all('/public function (?:provider_status|status)\(\)/', $api), 'both spellings, and no more');
});

/**
 * The console reuses the seeded sports.* capabilities rather than minting a football
 * permission set — which is only honest while every permission and every role it
 * names exists. A refusal telling an operator to "assign the Football administrator
 * role" when no such role is seeded is a dead end dressed up as guidance, so the
 * catalogue is read here and the prose is checked against it.
 */
test('football: every permission and role it points at is one the seed defines', function () {
    $rbac = fx_fb_source('tools/rbac.php');
    $permissions = [];
    if (preg_match("/AI_WORKFORCE_RBAC_PERMISSIONS',\s*\[(.*?)\n\s*\]\);/s", $rbac, $m) === 1) {
        preg_match_all("/'([a-z_]+(?:\.[a-z_]+)+)'\s*=>/", $m[1], $found);
        $permissions = $found[1];
    }
    assert_true(count($permissions) > 10, 'the seeded permission catalogue was read (found ' . count($permissions) . ')');
    $roles = [];
    if (preg_match("/AI_WORKFORCE_RBAC_ROLES',\s*\[(.*?)\n\s*\]\);/s", $rbac, $m) === 1) {
        preg_match_all("/=> '([^']+)'/", $m[1], $found);
        foreach ($found[1] as $label) {
            $roles[] = $label;
            $roles[] = trim((string) explode('(', $label)[0]);   // "Trading operator (read-only)" is referred to by its prefix
        }
    }
    assert_true(in_array('Sports administrator', $roles, true), 'the seeded role catalogue was read');

    foreach ([
        'application/controllers/Football.php',
        'application/controllers/Api_football.php',
        'application/views/football/index.php',
        'application/views/football/match.php',
        'application/views/football/models.php',
    ] as $file) {
        $source = fx_fb_source($file);
        preg_match_all('/(?<![A-Za-z_])(?:sports|football|lottery|trading|admin|system)\.[a-z_]+(?:\.[a-z_]+)*/', $source, $codes);
        foreach (array_unique($codes[0]) as $code) {
            assert_true(in_array($code, $permissions, true),
                $file . ' points at ' . $code . ', which no role can be granted (absent from tools/rbac.php)');
        }
        preg_match_all('/\b[A-Z][a-z]+(?: [A-Z][a-z]+){0,2} (?:administrator|viewer|operator|member)\b/', $source, $labels);
        foreach (array_unique($labels[0]) as $label) {
            assert_true(in_array($label, $roles, true),
                $file . ' tells the operator to use the "' . $label . '" role, which the RBAC seed does not define');
        }
    }
    // And the guidance stays actionable: it names the role that really carries the capability.
    assert_contains('Sports administrator', fx_fb_source('application/controllers/Football.php'),
        'the refusal names the seeded role that grants the football capabilities');
});

test('football: the models screen posts exactly what the controller accepts', function () {
    $view = fx_fb_source('application/views/football/models.php');
    $routes = fx_fb_source('application/config/routes.php');
    $controller = fx_fb_source('application/controllers/Football.php');
    preg_match_all('#<form method="post" action="([^"]+)"#', $view, $matches);
    $targets = $matches[1] ?? [];
    assert_true(count($targets) >= 2, 'the screen offers its operator actions as forms');
    foreach ($targets as $action) {
        // The id is interpolated by the view; for the route lookup it stands in
        // for the (:num) segment the router matches.
        $pattern = trim(preg_replace('#<\?=.*?\?>#', '(:num)', $action));
        $key = ltrim($pattern, '/');
        assert_contains('$route[\'' . $key . '\']', $routes, $key . ' is routed');
        $target = '';
        if (preg_match('#\$route\[\'' . preg_quote($key, '#') . '\'\] = \'([^\']+)\'#', $routes, $m)) $target = $m[1];
        assert_true($target !== '', 'with a target for ' . $key);
        $method = explode('/', (string) $target)[1] ?? '';
        assert_contains('public function ' . $method, $controller, 'handled by Football::' . $method . '()');
    }
    // Every POST carries the CSRF token the gate checks.
    assert_equals(
        substr_count($view, 'method="post"'),
        substr_count($view, 'name="csrf_token"'),
        'one token per form — no unguarded mutation'
    );
    // The approve/activate distinction is a single field the controller reads.
    assert_contains('name="activate" value="0"', $view, 'approve posts activate=0');
    assert_contains('name="activate" value="1"', $view, 'activate posts activate=1');
    assert_contains("\$this->input->post('activate') === '1'", $controller, 'and the controller compares against exactly that');
    // Read-only identities are not shown a form that would only refuse them.
    assert_contains("!empty(\$caps['approve'])", $view, 'approve/activate need sports.approve');
    assert_contains('needs sports.approve', $view, 'and the reason is stated on the page');
});

test('football: the football screens own their panels — no duplication, no leftovers', function () {
    $console = fx_fb_source('application/views/football/index.php');
    $models = fx_fb_source('application/views/football/models.php');
    $match = fx_fb_source('application/views/football/match.php');
    $sports = fx_fb_source('application/views/sports/index.php');
    $tickets = fx_fb_source('application/views/sports/tickets.php');
    $workspace = fx_fb_source('application/views/workspace/index.php');

    // §10/§11: the board and its card vocabulary live here, once.
    assert_equals(1, substr_count($console, "TODAY'S FOOTBALL PREDICTIONS"), 'the board heading appears once');
    assert_equals(1, substr_count($console, '<h3>30-day performance'), 'the 30-day panel appears once');
    assert_contains('$board[\'categories\']', $console, 'the view iterates the confidence categories the board produced');
    // The categories themselves are a data contract, so they are checked where
    // they are produced.
    [$repo, , $module] = fx_fb_harness([fx_fb_row('fx-ui-tier', gmdate('c', time() + 7200), 'Manchester City', 'Everton', '10', '20')]);
    $tierDay = gmdate('Y-m-d', time() + 7200);
    fx_fb_sync_today($module, $tierDay);
    $module->predictions()->predictDay($tierDay);
    $board = $module->board()->forDate($tierDay);
    $labels = array_column((array) $board['categories'], 'label');
    assert_equals(['Highest Confidence', 'Strong Predictions', 'Standard Predictions', 'Limited Data'], $labels,
        'the four §10 categories exist in order');
    assert_equals('80–100', (string) $board['categories'][0]['range'], 'with the documented cut line');
    assert_equals(75.0, (float) $board['categories'][1]['min']);
    assert_equals(70.0, (float) $board['categories'][2]['min']);
    $assigned = [];
    foreach ($board['categories'] as $category) {
        foreach ($category['items'] as $item) $assigned[] = $category['key'];
    }
    assert_equals(count($board['cards']), count($assigned), 'every card sits in exactly one category');
    assert_true(count($board['cards']) >= 1, 'the harness fixture produced at least one card');
    // §1: the models panel belongs to /football/models, not to the board too.
    assert_true(stripos($console, '<h3>Models &amp; calibration') === false || substr_count($models, 'Model version') > 0,
        'model state is reported on the models screen');
    assert_equals(0, substr_count($console, '<h3>Models &amp; calibration'), 'and not duplicated on the board');
    // §15/§16: what /sports must no longer claim.
    foreach (['>Brier<', '>ECE<', '>Model accuracy<', '>Prediction accuracy<'] as $markup) {
        foreach (['sports' => $sports, 'tickets' => $tickets, 'workspace' => $workspace] as $name => $source) {
            assert_equals(0, substr_count($source, $markup), $name . ' does not render the football metric ' . $markup);
        }
    }
    foreach (['30-day performance (settled predictions)', 'Fixtures found', 'Qualified'] as $markup) {
        assert_true(substr_count($console, $markup) >= 1, 'the board reports ' . $markup);
    }
    assert_contains('30-day ticket performance (stored settlements only)', $sports, 'the ticket screen keeps only ticket figures');
    // §12: the match screen is read-only — nothing here rewrites a prediction.
    assert_equals(0, substr_count($match, 'method="post"'), 'the match view contains no form at all');
    assert_contains('never rewritten', $match, 'and says plainly that the frozen prediction is not rewritten');
    assert_contains('Stored as separate LIVE rows', $match, 'with the live estimate kept in its own rows');
    assert_contains('separate stored rows', $console, 'and the board says they are separate rows');
});

test('football: the empty states and required wording are the shipped strings', function () {
    // The sentences are constants, so a view cannot paraphrase them away.
    assert_equals(
        'No settled predictions yet. Historical performance metrics will appear after predicted matches have completed.',
        PerformanceService::EMPTY_MESSAGE
    );
    assert_equals(
        'Football data provider not connected. Live fixtures and predictions are unavailable until a verified data source is configured.',
        FootballDiagnostics::NO_PROVIDER_MESSAGE
    );
    assert_equals('NO_SETTLED_PREDICTIONS', PerformanceService::NO_DATA, 'the state token the console keys on');
    $console = fx_fb_source('application/views/football/index.php');
    assert_contains('No fixtures currently satisfy the required prediction and data-quality thresholds.', $console);
    assert_contains('No settled predictions yet', $console);
    // The state tokens are constants shared by the module and its screens, so a
    // view can only ever print one of these.
    assert_equals('DATA_UNAVAILABLE', \AIWorkforce\Football\DataState::UNAVAILABLE);
    assert_equals('LIMITED', \AIWorkforce\Football\QualityBand::LIMITED, 'the band, as stored on a prediction row');
    assert_equals('LIMITED_DATA', \AIWorkforce\Football\DataState::LIMITED);
    assert_equals('CALIBRATION_PENDING', \AIWorkforce\Football\CalibrationService::PENDING);
    assert_equals('NOT_CONFIGURED', FootballDiagnostics::NOT_CONFIGURED);
    assert_equals('UNAVAILABLE', FootballDiagnostics::UNAVAILABLE);
    assert_equals('WAITING_FOR_DATA', FootballDiagnostics::WAITING_FOR_DATA);
    assert_contains('$d[\'diagnostics\']', $console, 'the console renders the diagnostics block');
    assert_contains("\$diag['checks']", $console, 'as one row per check, straight from the snapshot');
    $diagnostics = (new \AIWorkforce\Football\FootballIntelligence(new FootballRepositoryStub(),
        new \AIWorkforce\Sports\Providers\SportsProviderManager(), null, new \AIWorkforce\Football\FootballConfiguration()))
        ->diagnostics()->snapshot();
    $keys = array_column($diagnostics['checks'], 'key');
    foreach (['Provider', 'Fixtures', 'Statistics', 'Prediction Engine'] as $check) {
        assert_in_array($check, $keys, $check . ' is one of the admin diagnostics lines');
    }
    assert_true(!str_contains($console, 'With no sports data connected the module stays off'),
        'the old dismissive sentence is gone');
    assert_true(!str_contains($console, 'No sports data connected the module') && !str_contains(fx_fb_source('application/views/sports/index.php'), 'fabricates nothing'),
        'and it is not hiding on another screen');
});

test('football: the football path contains no randomness or synthetic fallback', function () {
    $sources = [];
    if (defined('FCPATH')) {
        foreach ([FCPATH . 'application/libraries/AIWorkforce/Football/*.php', FCPATH . 'application/views/football/*.php'] as $pattern) {
            foreach (glob($pattern) ?: [] as $file) $sources[basename($file)] = (string) file_get_contents($file);
        }
    } else {
        // Flat php-wasm directory: the module's own files by namespace, its views
        // by the name the harness copies them under.
        foreach (glob('/app/src/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            $isModule = str_contains($source, 'namespace AIWorkforce\\Football;');
            $isView = preg_match('#^view-football-#', basename($file)) === 1;
            if (!$isModule && !$isView) continue;
            $sources[basename($file)] = $source;
        }
    }
    assert_true(count($sources) >= 20, 'the whole football path was scanned (' . count($sources) . ' files)');
    foreach (['DataState.php', 'FootballConfiguration.php', 'FootballIntelligence.php', 'PredictionBoard.php'] as $expected) {
        assert_true(isset($sources[$expected]) || isset($sources['view-' . $expected]), $expected . ' was part of the scan');
    }
    foreach ($sources as $name => $source) {
        assert_true(preg_match('/\\b(?:rand|mt_rand|array_rand|shuffle|str_shuffle|random_int|random_bytes)\\s*\\(/', $source) !== 1,
            $name . ' contains no randomness: a prediction has to be reproducible from the stored rows it read');
        foreach (['seedDemo', 'fakeFixtures', 'mockFixtures', 'syntheticFixture', 'placeholderPrediction', 'demoFixture'] as $forbidden) {
            assert_true(!str_contains($source, $forbidden), $name . ' calls no ' . $forbidden);
        }
    }
    // Demo gating lives in one place, and it is a permission rather than a source.
    $config = $sources['FootballConfiguration.php'] ?? '';
    assert_contains('demoMode', $config, 'the configuration exposes the demo switch');
    assert_contains("DEMO_MODE", $config, 'reading DEMO_MODE (with the football-specific alias)');
});

test('football: the navigation reaches the console', function () {
    $header = fx_fb_source('application/views/layout/header.php');
    assert_contains('href="/football"', $header, 'the football console is in the nav');
    assert_contains("=== 'football'", $header, 'and it highlights on the right screen');
    assert_contains('href="/sports"', $header, 'without replacing the ticket console');
});

test('football: rendered console shows populated and empty states without warnings', function () {
    if (!function_exists('get_instance')) {
        assert_true(true, 'CI-only: markup rendering is covered by e2e/football-views.php outside CI');
        return;
    }
    $ci = ci();
    $kickoff = time() + 7200;
    $day = gmdate('Y-m-d', $kickoff);
    [$repo, $provider, $module] = fx_fb_harness([
        fx_fb_row('fx-ui-1', gmdate('c', $kickoff), 'Manchester City', 'Everton', '10', '20'),
    ]);
    fx_fb_sync_today($module, $day);
    $module->predictions()->predictDay($day);
    $render = static function (string $page, array $data) use ($ci): string {
        ob_start();
        $ci->load->view('layout/header', $data);
        $ci->load->view($page, $data);
        $ci->load->view('layout/footer');
        return (string) ob_get_clean();
    };
    $base = ['title' => 'Football Intelligence', 'active' => 'football', 'notice' => null, 'error' => null,
        'caps' => ['sync' => true, 'calibrate' => true, 'approve' => true, 'settle' => true], 'csrfToken' => 'test-token'];
    $html = $render('football/index', array_merge($base, [
        'dashboard' => $module->dashboard($day), 'date' => $day,
        'yesterday' => gmdate('Y-m-d', $kickoff - 86400), 'tomorrow' => gmdate('Y-m-d', $kickoff + 86400),
        'refresh' => false,
    ]));
    assert_contains("TODAY'S FOOTBALL PREDICTIONS", $html);
    assert_equals(1, substr_count($html, '<h3>30-day performance'), 'rendered once per page');
    assert_true(!str_contains($html, 'Call to a member function'), 'no fatal surfaced as text');
    assert_true(!str_contains($html, 'Undefined array key'), 'no PHP warnings');
    assert_true(!str_contains($html, 'Warning:</b>'), 'none at all');
    assert_true(str_ends_with(trim($html), '</html>'), 'the layout closed the document');
    $modelsHtml = $render('football/models', array_merge($base, [
        'models' => $module->modelSummary(), 'performance' => $module->performance()->report(30),
    ]));
    assert_contains('30-day performance by model version', $modelsHtml);
    assert_true(!str_contains($modelsHtml, "TODAY'S FOOTBALL PREDICTIONS"), 'the models screen does not repeat the board');
});

/**
 * The schema is part of the surface a user sees: if one DDL source is missing a
 * column the code writes, the install that uses it fails only in production
 * (MySQL on cPanel, SQLite for offline dev, and `database/production.sql` for a
 * fresh platform install). All three are maintained by hand, so parity is read
 * from the files rather than trusted.
 */
function fx_fb_ddl(string $dialect): string
{
    $candidates = match ($dialect) {
        'mysql' => ['application/database/football_intelligence.mysql.sql', '/app/src/football-ddl-mysql'],
        'sqlite' => ['application/database/football_intelligence.sqlite.sql', '/app/src/football-ddl-sqlite'],
        default => ['database/production.sql', '/app/src/football-ddl-production'],
    };
    foreach ($candidates as $path) {
        $full = str_starts_with($path, '/') ? $path : (defined('FCPATH') ? FCPATH . $path : $path);
        if (is_file($full)) return (string) file_get_contents($full);
    }
    throw new RuntimeException('cannot read the ' . $dialect . ' schema');
}

/** @return array<string,list<string>> table => ordered column names */
function fx_fb_ddl_tables(string $sql, string $prefix = 'football_'): array
{
    $out = [];
    $lower = strtolower($sql);
    $len = strlen($sql);
    $offset = 0;
    while (($hit = strpos($lower, 'create table', $offset)) !== false) {
        $open = strpos($sql, '(', $hit);
        if ($open === false) break;
        if (!preg_match('/`?(\w+)`?\s*$/m', substr($sql, $hit, $open - $hit), $nm)) { $offset = $open; continue; }
        $table = $nm[1];
        $depth = 0;
        $body = '';
        $i = $open;
        for (; $i < $len; $i++) {
            $ch = $sql[$i];
            if ($ch === '(') { $depth++; if ($depth === 1) continue; }
            elseif ($ch === ')') { $depth--; if ($depth === 0) break; }
            $body .= $ch;
        }
        $offset = $i + 1;
        if (!str_starts_with($table, $prefix)) continue;
        $parts = [];
        $depth = 0;
        $cur = '';
        for ($j = 0, $n = strlen($body); $j < $n; $j++) {
            $ch = $body[$j];
            if ($ch === '(') $depth++;
            elseif ($ch === ')') $depth--;
            if ($ch === ',' && $depth === 0) { $parts[] = $cur; $cur = ''; } else { $cur .= $ch; }
        }
        $parts[] = $cur;
        $columns = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || preg_match('/^(primary|unique|key|index|constraint|foreign)\b/i', $part)) continue;
            if (preg_match('/^`?(\w+)`?/', $part, $cm)) $columns[] = $cm[1];
        }
        $out[$table] = $columns;
    }
    return $out;
}

test('football: every schema source declares the same tables and columns', function () {
    $mysql = fx_fb_ddl_tables(fx_fb_ddl('mysql'));
    $sqlite = fx_fb_ddl_tables(fx_fb_ddl('sqlite'));
    $prod = fx_fb_ddl_tables(fx_fb_ddl('prod'));
    $expected = [
        'football_calibration_versions', 'football_competitions', 'football_fixture_statistics', 'football_fixtures',
        'football_head_to_head', 'football_match_predictions', 'football_model_performance', 'football_model_versions',
        'football_prediction_settlements', 'football_provider_sync_logs', 'football_providers',
        'football_score_probabilities', 'football_teams', 'football_team_statistics',
    ];
    sort($expected);
    foreach (['mysql' => $mysql, 'sqlite' => $sqlite, 'production.sql' => $prod] as $label => $tables) {
        $names = array_keys($tables);
        sort($names);
        assert_equals($expected, $names, $label . ' declares exactly the fourteen football entities');
        foreach ($tables as $table => $columns) {
            assert_true(count($columns) >= 3, $label . ':' . $table . ' is not a stub');
        }
    }
    foreach ($expected as $table) {
        assert_true(isset($sqlite[$table], $prod[$table]), $table . ' exists in every schema source');
        if (!isset($sqlite[$table], $prod[$table])) continue;
        assert_equals($mysql[$table], $sqlite[$table], $table . ': the SQLite mirror carries the same columns in the same order');
        assert_equals($mysql[$table], $prod[$table], $table . ': the production install script carries the same columns');
    }
    // The one column added after the first release must exist in both dialects and
    // be repaired on the fly by SchemaInstaller::upgrade() for installs that predate it.
    foreach (['football_providers' => 'requests_used_date'] as $table => $column) {
        assert_in_array($column, $mysql[$table], $table . '.' . $column . ' is in the MySQL schema');
        assert_in_array($column, $sqlite[$table], $table . '.' . $column . ' is in the SQLite schema');
        assert_in_array($column, $prod[$table], $table . '.' . $column . ' is in production.sql');
        assert_contains('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column, fx_fb_source('application/libraries/AIWorkforce/SchemaInstaller.php'),
            $column . ' is added idempotently on boot for an existing install');
    }
    // Quota bookkeeping and the settle sweep both depend on indexed columns existing.
    assert_in_array('backoff_until', $mysql['football_providers'] ?? [], 'backoff survives a reboot');
    assert_in_array('execution_key', $mysql['football_provider_sync_logs'] ?? [], 'a sweep run is idempotent by execution key');
    assert_in_array('settlement_state', $mysql['football_match_predictions'] ?? [], 'settlement state is a stored column, not a join');
});
