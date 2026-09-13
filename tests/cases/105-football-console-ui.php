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
        // The paginated feed: 50 matches per page, 50 per generation request.
        'api/football/matches' => 'api_football/matches',
        'api/football/matches/generate' => 'api_football/generate_matches',
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
        'football/match/(:num)/analyze' => 'football/analyze/$1',
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
    $railStart = strpos($console, '<aside class="football-side');
    $performanceStart = strpos($console, 'id="football-performance"');
    assert_true($railStart !== false && $performanceStart !== false && $performanceStart > $railStart,
        'the complete performance panel lives in the sidebar rail');
    foreach (['Predictions evaluated', 'Correct results', 'Result accuracy', 'Correct exact scores',
        'Correct-score accuracy', 'Avg confidence', 'Brier score', 'Log loss', 'ECE',
        'Avg data quality', 'Avg goal error', 'Approved calibrations'] as $metric) {
        assert_equals(1, substr_count($console, '>' . $metric . '<'), $metric . ' appears once in the sidebar performance panel');
    }
    foreach (['evaluatedPredictions', 'correctResults', 'resultAccuracy', 'correctScores',
        'exactScoreAccuracy', 'averageConfidence', 'brier', 'logLoss', 'ece',
        'averageDataQuality', 'averageGoalError'] as $field) {
        assert_contains("\$perf['" . $field . "']", $console, $field . ' is bound to the stored performance report');
    }
    assert_contains("\$models['approvedCalibrationCount']", $console,
        'approved calibrations are bound to the stored model summary');
    assert_contains('countCalibrations(null, self::CALIBRATED)',
        fx_fb_source('application/libraries/AIWorkforce/Football/CalibrationService.php'),
        'approved calibration count uses an exact repository count, not a paged list');
    $repository = fx_fb_source('application/libraries/AIWorkforce/Persistence/FootballRepositoryDatabase.php');
    assert_contains('s.absolute_goal_error', $repository,
        'the settled-prediction sample query includes the stored goal-error metric shown in the sidebar');
    assert_contains('s.probability_home AS settled_probability_home', $repository,
        'ECE and repaired Brier/log loss prefer the frozen settlement probabilities before prediction-row fallback');
    assert_contains('p.data_quality_score AS prediction_data_quality_score', $repository,
        'legacy settlement rows can still fill average data quality from the immutable prediction row');
    assert_contains('p.predicted_result AS prediction_predicted_result', $repository,
        'legacy settlement rows can still grade result/exact score from the immutable prediction row');
    assert_contains('$board[\'categories\']', $console, 'the view iterates the confidence categories the board produced');
    // The categories themselves are a data contract, so they are checked where
    // they are produced.
    [$repo, , $module] = fx_fb_harness([fx_fb_row('fx-ui-tier', gmdate('c', time() + 7200), 'Manchester City', 'Everton', '10', '20')]);
    $tierDay = gmdate('Y-m-d', time() + 7200);
    fx_fb_sync_today($module, $tierDay);
    $module->predictions()->predictDay($tierDay);
    $board = $module->board()->forDate($tierDay);
    $labels = array_column((array) $board['categories'], 'label');
    assert_equals(['Highest Confidence', 'Strong Predictions', 'Standard Predictions', 'Limited Data — usable with caution', 'Developing'], $labels,
        'the five §10 categories exist in order');
    assert_equals('60–100', (string) $board['categories'][0]['range'], 'with the documented cut line');
    assert_equals(52.0, (float) $board['categories'][1]['min']);
    assert_equals(45.0, (float) $board['categories'][2]['min']);
    assert_equals('limitedData', (string) $board['categories'][3]['key'], 'the fourth category is the capped Limited Data bucket');
    assert_equals('developing', (string) $board['categories'][4]['key'], 'and the trailing one is Developing, not thin data');
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
    assert_contains('30-day odds prediction ticket performance (stored settlements only)', $sports, 'the odds prediction ticket screen keeps only ticket figures');
    // §12: the match screen never rewrites a prediction. Its one form —
    // Analyze — only writes the row when the match has none, reuses the stored
    // row otherwise (the controller refuses to regenerate), and carries the
    // same CSRF token every other console mutation carries.
    assert_equals(1, substr_count($match, 'method="post"'), 'the match view contains exactly one form: Analyze');
    assert_contains('/football/match/', $match, 'posting back at the match it analyzes');
    assert_contains('/analyze', $match, 'at the analyze endpoint');
    assert_contains('csrf_token', $match, 'guarded by the CSRF token');
    assert_contains('never rewritten', $match, 'and says plainly that the frozen prediction is not rewritten');
    assert_contains('Stored as separate LIVE rows', $match, 'with the live estimate kept in its own rows');
    // The compact board rail is deliberately live-match-only; prediction-row
    // provenance remains on the full match screen instead of cluttering it.
    assert_true(!str_contains($console, 'The pre-match prediction and live estimate are separate stored rows'),
        'the compact live panel no longer repeats match-analysis guidance');

    foreach (['Full odds &amp; fair-price sheet', 'Bookmaker quote &amp; information', 'Margin-free market',
        'WINDELS probability', 'WINDELS fair odds', 'Break-even', 'Expected return', 'Edge vs quote',
        'Edge after margin', 'market margin', 'Coverage', 'quote', 'range', 'UNPRICED'] as $label) {
        assert_contains($label, $console, 'the board odds sheet exposes ' . $label);
    }
    foreach (['Bookmaker quote &amp; information', 'Margin-free market', 'Potential Edge', 'Expected return',
        'Break-even', 'market margin', 'Coverage', 'range', 'UNPRICED'] as $label) {
        assert_contains($label, $match, 'the match odds sheet exposes ' . $label);
    }
    assert_contains('football-match-side stack', $match, 'match evidence uses the sticky context rail');
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
        'football_calibration_versions', 'football_competition_mapping', 'football_competitions',
        'football_fixture_statistics', 'football_fixtures', 'football_head_to_head', 'football_match_predictions',
        'football_model_performance', 'football_model_versions', 'football_prediction_settlements',
        'football_prediction_revisions', 'football_provider_matches', 'football_provider_sync_logs', 'football_providers',
        'football_score_probabilities', 'football_teams', 'football_team_statistics',
    ];
    sort($expected);
    foreach (['mysql' => $mysql, 'sqlite' => $sqlite, 'production.sql' => $prod] as $label => $tables) {
        $names = array_keys($tables);
        sort($names);
        assert_equals($expected, $names, $label . ' declares exactly the seventeen football entities');
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

test('football: a live match card carries the match date and time from the stored kickoff', function () {
    $console = fx_fb_source('application/views/football/index.php');
    assert_contains('<h3 id="live-heading">Live Match</h3>', $console, 'the live-match-only panel is on the football console');
    assert_contains("\$kickoffStamp(\$fx['kickoff']", $console, 'every live card prints its kickoff');
    assert_contains("gmdate('D j M Y · H:i'", $console, 'as the match date and the UTC time together');
    assert_contains("'DATA_UNAVAILABLE'", $console, 'and a fixture with no stored kickoff says so instead of printing a guessed one');

    $panelStart = strpos($console, 'id="football-live-panel"');
    $nextPanel = strpos($console, 'aria-labelledby="feed-heading"', (int) $panelStart);
    assert_true($panelStart !== false && $nextPanel !== false, 'the live panel can be isolated from the rest of the rail');
    $livePanel = substr($console, (int) $panelStart, (int) $nextPanel - (int) $panelStart);
    assert_contains('Auto-refresh on — live match updates appear here automatically, immediately after the provider reports them.', $livePanel,
        'the panel tells the reader that provider updates appear automatically');
    assert_contains('id="football-live-list"', $livePanel, 'the live-only list is the poll target');
    assert_true(!str_contains($livePanel, 'Refresh live'), 'there is no manual refresh control inside an always-on panel');
    assert_true(!str_contains($livePanel, 'pre-match prediction'), 'the compact panel displays only the live-match state');
    assert_contains('href="#football-live-panel">Live match</a>', $console,
        'the action-bar shortcut scrolls to the automatic panel instead of triggering a provider refresh');
    assert_true(!str_contains($console, 'href="/football/live"'),
        'the console offers no GET action that spends a provider request');
    $controller = fx_fb_source('application/controllers/Football.php');
    $legacyStart = strpos($controller, 'public function live()');
    $legacyEnd = strpos($controller, 'public function match(', (int) $legacyStart);
    $legacyLive = substr($controller, (int) $legacyStart, (int) $legacyEnd - (int) $legacyStart);
    assert_contains("redirect('/football#football-live-panel')", $legacyLive,
        'old live-view bookmarks land on the automatic panel');
    assert_true(!str_contains($legacyLive, 'syncLive'),
        'opening the legacy live-view URL never calls the provider');
    assert_contains("fetch('/api/football/fixtures/live'", $console, 'the live panel polls the stored football-live endpoint');
    assert_contains('kickoffStamp(fixture.kickoff)', $console, 'polled cards preserve the stored kickoff date and time');
    assert_contains('document.hidden', $console, 'polling pauses while the page is hidden');

    // The card renders the fixture summary the live board already returns, so
    // the printed date and time are the stored kickoff row — never the moment
    // the page happened to be rendered.
    $kickoff = gmdate('c', time() - 1800);
    [$repo, , $module] = fx_fb_harness([], ['skipHistory' => true]);
    $stored = $repo->saveFixture(1, [
        'externalId' => 'fx-live-kickoff', 'competition' => 'Premier League', 'leagueId' => '39', 'season' => '2026',
        'kickoff' => $kickoff, 'status' => 'LIVE', 'minute' => 34,
        'homeTeam' => 'Manchester City', 'awayTeam' => 'Everton', 'homeTeamId' => '10', 'awayTeamId' => '20',
        'homeScore' => 1, 'awayScore' => 1,
        // What a provider sweep stamps when its live snapshot lists the match.
        // Live Match admits a card on this confirmation alone, so a fixture
        // written without one is not in play as far as the panel is concerned.
        'liveConfirmed' => true, 'liveConfirmedAt' => gmdate('c'),
    ]);
    $match = ($module->live()->board(false)['matches'] ?? [])[0] ?? [];
    assert_equals((string) $stored['kickoff_at'], (string) ($match['fixture']['kickoff'] ?? ''),
        'the live board reads the stored kickoff');
    $shown = (string) ($match['fixture']['kickoff'] ?? '');
    assert_equals(gmdate('D j M Y', (int) strtotime($kickoff)), gmdate('D j M Y', (int) strtotime($shown)),
        'the card shows the match date');
    assert_equals(gmdate('H:i', (int) strtotime($kickoff)), gmdate('H:i', (int) strtotime($shown)),
        'and the kickoff time, in UTC');

    // Rendered output, when CodeIgniter's view loader is available: the string a
    // user reads on the live card.
    if (function_exists('get_instance')) {
        $day = gmdate('Y-m-d', (int) strtotime($kickoff));
        ob_start();
        ci()->load->view('football/index', [
            'title' => 'Football Intelligence', 'active' => 'football', 'notice' => null, 'error' => null,
            'caps' => ['sync' => false, 'calibrate' => false, 'approve' => false, 'settle' => false],
            'csrfToken' => 'test-token', 'dashboard' => $module->dashboard($day), 'date' => $day,
            'yesterday' => gmdate('Y-m-d', (int) strtotime($kickoff) - 86400),
            'tomorrow' => gmdate('Y-m-d', (int) strtotime($kickoff) + 86400), 'refresh' => false,
        ]);
        $html = (string) ob_get_clean();
        assert_contains('Kickoff ' . gmdate('D j M Y · H:i', (int) strtotime($kickoff)) . ' UTC', $html,
            'the rendered live card carries the match date and time');
        assert_true(!str_contains($html, 'Undefined array key'), 'no PHP warnings from the live card');
    }
});

/**
 * The three football screens are ONE reading system, not three layouts.
 *
 * Every block — board, match page and models page, feed column and context
 * rail alike — is the same object: a `.panel.football-section` whose heading
 * carries an eyebrow ("what is this"), a title, and, in the feed, a step
 * number that fixes the reading order. Bodies get their internal rhythm from a
 * single stylesheet rule, so no view needs an inline `margin-top` to look
 * right. This case pins that uniformity: the previous board mixed multi-line
 * heading blocks with cramped one-liners, shipped two `aria-labelledby`
 * references that pointed at ids no element had, and spread 34 inline layout
 * styles across the three views.
 */
test('football UI: every section on every football screen is the same numbered, labelled object', function () {
    $views = [
        'board' => fx_fb_source('application/views/football/index.php'),
        'match' => fx_fb_source('application/views/football/match.php'),
        'models' => fx_fb_source('application/views/football/models.php'),
    ];

    // 1. Reading order. Each screen numbers its feed; the board's performance
    // report is now part of the sidebar rail and therefore stays unnumbered.
    foreach ([1, 2, 3] as $step) {
        assert_contains('<span class="football-step" aria-hidden="true">' . $step . '</span>', $views['board'],
            'the board numbers feed step ' . $step);
        assert_contains('<span class="football-step" aria-hidden="true">' . $step . '</span>', $views['models'],
            'the models page numbers step ' . $step);
    }
    foreach ([1, 2, 3, 4] as $step) {
        assert_contains('<span class="football-step" aria-hidden="true">' . $step . '</span>', $views['match'],
            'the match page numbers step ' . $step);
    }
    // The rail is reference material, never part of the numbered order, so the
    // step count equals the number of feed sections and no more.
    assert_equals(3, substr_count($views['board'], 'class="football-step"'), 'the board numbers its feed and only its feed');

    // 2. Every aria-labelledby resolves. `live-heading` and
    // `performance-heading` used to point at nothing at all.
    foreach ($views as $name => $view) {
        preg_match_all('/aria-labelledby="([^"]+)"/', $view, $labelled);
        preg_match_all('/id="([^"]+)"/', $view, $ids);
        foreach (array_unique($labelled[1] ?? []) as $reference) {
            assert_true(in_array($reference, $ids[1] ?? [], true),
                $name . ' names a section with ' . $reference . ', so an element must carry that id');
        }
    }
    assert_contains('id="live-heading"', $views['board'], 'the live rail panel has the heading it claims');
    assert_contains('id="performance-heading"', $views['board'], 'and so does the 30-day panel');
    assert_contains('aria-labelledby="football-guide-heading"', $views['board'],
        'the reading guide is a named landmark like every other section');

    // 3. One heading object everywhere — eyebrow + title, never a bare <h3>
    // and never a cramped one-liner beside a multi-line block.
    foreach ($views as $name => $view) {
        assert_equals(
            substr_count($view, 'class="football-section__heading"'),
            substr_count($view, 'class="football-section__title"'),
            $name . ': every section heading carries the same title block'
        );
        // Every heading block opens with an eyebrow. The only eyebrow outside a
        // section heading is the page hero's, hence the +1.
        preg_match_all('#<div class="football-section__title">(.*?)</div>\s*</div>#s', $view, $titles);
        assert_equals(
            substr_count($view, 'class="football-section__heading"'),
            count($titles[0] ?? []),
            $name . ': every heading block is built the same way'
        );
        foreach ($titles[1] ?? [] as $title) {
            assert_true(str_contains($title, 'class="football-eyebrow">'),
                $name . ': every section says what it is before it shows a number');
        }
        assert_equals(
            substr_count($view, 'class="football-section__heading"') + 1,
            substr_count($view, 'class="football-eyebrow">'),
            $name . ': one eyebrow per section, plus the page hero'
        );
    }
    assert_true(substr_count($views['board'], 'class="panel football-section') >= 8,
        'the board feed and its rail share one section object');

    // 4. Layout lives in the stylesheet. All 34 inline styles are gone.
    foreach ($views as $name => $view) {
        assert_equals(0, substr_count($view, 'style="'),
            $name . ': layout belongs in the stylesheet, not in a style attribute');
    }
    foreach (['margin-top:12px', 'padding-top:12px', 'margin-top:10px', 'font-size:11px', 'display:inline', 'opacity:.45'] as $gone) {
        foreach ($views as $name => $view) {
            assert_true(!str_contains($view, $gone), $name . ': the ad-hoc rule "' . $gone . '" was replaced by a class');
        }
    }

    // 5. The rhythm rule those views now depend on.
    $css = fx_fb_source('assets/css/ai_workforce.css');
    assert_contains('.football-section > .body { padding: 16px 20px 20px; display: flex; flex-direction: column; gap: 12px; }', $css,
        'one gap rule gives every football section the same internal rhythm');
    assert_contains('.football-section > .body > * { margin: 0; }', $css,
        'so no child needs to bring its own margin');
    assert_contains('.football-section__title', $css);
    assert_contains('.football-step', $css);
    assert_contains('.football-section-intro', $css, 'each section can open with a plain-language write-up');
    assert_contains('.football-inline-form', $css, 'the forms that used inline styles have a class');

    // 6. The write-ups themselves: a reader is told what each section is for.
    foreach (['The counts below describe saved rows for this date only',
        'The strongest comparisons drawn from the fixtures in section 3',
        'Measured from stored settlements only'] as $writeUp) {
        assert_contains($writeUp, $views['board'], 'the board explains its section: ' . $writeUp);
    }
    // The live panel's eyebrow is "Live now": the section holds matches a
    // provider is reporting in play at this moment, and the wording is the
    // reader's cue that the list is current rather than a view of the date.
    foreach (['Day overview', 'Ranked reading', 'Fixture odds board', 'Measured results',
        'Reading the board', 'Live now', 'Data health', 'Governance', 'Automation'] as $eyebrow) {
        assert_contains('football-eyebrow">' . $eyebrow . '</p>', $views['board'],
            'the board introduces a section as "' . $eyebrow . '"');
    }
});

/**
 * Pinning the rail is a layout contract shared with /sports: the context column
 * stays put at EVERY scroll position, including the very end of the page, and
 * only the feed moves. It is asserted here too because /football owns three
 * screens that use it (`.football-side` and `.football-match-side`) and a
 * change to the football grid could break the pin without touching /sports.
 */
test('football UI: the context rail is pinned for the whole page, feed-only scrolling', function () {
    $css = fx_fb_source('assets/css/ai_workforce.css');
    $start = strpos($css, '@media (min-width: 1181px)');
    assert_true($start !== false, 'the desktop rail contract exists');
    $rail = substr($css, (int) $start, 1600);

    foreach (['.football-side', '.football-match-side'] as $selector) {
        assert_contains($selector, $rail, $selector . ' is part of the shared sticky contract');
    }
    assert_contains('--rail-top: 76px', $rail, 'the rail parks under the sticky topbar');
    assert_contains('--rail-tail: 136px', $rail, 'and reserves the page tail, so it cannot be pushed off at maximum scroll');
    assert_contains('align-self: start', $rail, 'a stretched grid item has no room to stick');
    assert_contains('overscroll-behavior: auto', $rail, 'the wheel chains back to the feed instead of being trapped');
    assert_true(!str_contains($rail, 'overscroll-behavior: contain'), 'never trap the wheel over the rail');
    assert_true((bool) preg_match('/max-height:\s*calc\(100dvh\s*-\s*var\(--rail-top\)\s*-\s*var\(--rail-tail\)\)/', $rail),
        'the cap subtracts the top offset and the untouchable page tail');

    // Both football grids feed the rail its variables.
    assert_contains('.football-layout', $rail, 'the board grid declares the rail variables');
    assert_contains('.football-match-layout', $rail, 'and so does the match grid');

    // One column below the breakpoint: the rail unpins and joins the feed.
    $mobile = substr($css, (int) strpos($css, '@media (max-width: 1180px)'), 600);
    assert_contains('position: static', $mobile, 'the rail unpins when it no longer sits beside the feed');
    assert_contains('max-height: none', $mobile);
    assert_contains('overflow: visible', $mobile);

    // Both rails are real asides with an accessible name.
    assert_contains('<aside class="football-side stack" aria-label=', fx_fb_source('application/views/football/index.php'));
    assert_contains('<aside class="football-match-side stack" aria-label=', fx_fb_source('application/views/football/match.php'));
    assert_contains('<aside class="football-side stack" aria-label=', fx_fb_source('application/views/football/models.php'));
});
