<?php
use AIWorkforce\Persistence\AuditRepository;
use AIWorkforce\Sports\FeatureEngineeringEngine;
use AIWorkforce\Sports\PredictionEngine;
use AIWorkforce\Sports\SportsIntelligence;

function fx_ui_audit(): AuditRepository
{
    return new class implements AuditRepository { public array $events = []; public function emit(string $t, string $s, array $d = [], string $a = 'system'): void { $this->events[] = ['type' => $t, 'actor' => $a, 'detail' => $d]; } public function recent(int $l = 100): array { return []; } };
}

/** Sports capabilities of a fully privileged identity (sports_admin / super admin). */
function fx_sports_caps_all(): array
{
    return ['sync' => true, 'approve' => true, 'settle' => true];
}

/** Sports capabilities of a read-only identity (platform_member: sports.view only). */
function fx_sports_caps_none(): array
{
    return ['sync' => false, 'approve' => false, 'settle' => false];
}

/** Render console views (header + page + footer) and return the HTML. */
function fx_render_sports(string $page, array $extra): string
{
    $ci = ci();
    $data = array_merge([
        'title' => 'Sports Intelligence', 'active' => 'sports',
        'status' => ['tradingMode' => 'ANALYSIS_ONLY', 'killSwitch' => ['active' => false], 'providers' => []],
        'notice' => null, 'error' => null,
        'caps' => fx_sports_caps_all(),
    ], $extra);
    ob_start();
    $ci->load->view('layout/header', $data);
    $ci->load->view('sports/' . $page, $data);
    $ci->load->view('layout/footer');
    return (string) ob_get_clean();
}

/** Seed a complete "today" state: provider, fixture, approved calibration, pending ticket. */
function fx_ui_today(SportsRepositoryStub $repo): string
{
    $repo->ensureProvider('ui-test', 'UI Test');
    $repo->saveHealth(1, ['status' => 'ONLINE', 'reliability' => 0.9]);
    $modelId = $repo->ensureModelVersion(['modelName' => PredictionEngine::MODEL_NAME, 'modelVersion' => PredictionEngine::MODEL_VERSION, 'featureVersion' => FeatureEngineeringEngine::VERSION]);
    $repo->saveCalibration(['model_version_id' => $modelId, 'method' => 'platt', 'intercept' => 0.2, 'slope' => 1.5, 'samples' => 40, 'ece' => 0.02, 'status' => 'APPROVED', 'created_by' => 'admin', 'created_at' => gmdate('c')]);

    // kickoff TODAY at 15:00 UTC — inside dashboard()'s [todayT00:00:00, todayT23:59:59] window
    // regardless of the run time. Double quotes so \T stays a literal T (gmdate's T = tz abbreviation).
    $kickoff = gmdate("Y-m-d\TH:i:00+00:00", strtotime('today 15:00:00'));
    $repo->matches[] = ['id' => 9001, 'provider_id' => 1, 'external_id' => 'ui-1', 'sport' => 'football', 'competition' => 'UI League', 'home_team' => 'HomeA', 'away_team' => 'AwayA', 'kickoff_at' => $kickoff, 'status' => 'SCHEDULED', 'source_timestamp' => gmdate('c'), 'payload' => ['context' => ['recentForm' => ['homeGoalsPerMatch' => 1.6, 'awayGoalsPerMatch' => 1.4, 'homeConcededPerMatch' => 1.0, 'awayConcededPerMatch' => 0.9, 'source' => 'test'], 'marketLiquidity' => 50000]]];

    $ticketId = 'tkt_ui_0001';
    $repo->saveTicket(['id' => $ticketId, 'created_at' => gmdate('c'), 'model_version_id' => $modelId, 'configuration_version' => '0', 'total_odds' => 6.4, 'selection_count' => 2, 'combined_probability' => 0.15, 'confidence' => 88.0, 'risk' => 'LOW', 'correlation' => 'LOW', 'data_quality_score' => 100, 'status' => 'PENDING', 'approval_status' => 'PENDING_USER_APPROVAL', 'settlement_status' => 'PENDING', 'stake' => 10.0, 'pnl' => null]);
    $repo->saveTicketSelection(['ticket_id' => $ticketId, 'prediction_id' => 'prd_ui_1', 'match_id' => 9001, 'market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'odds' => 2.0, 'odds_timestamp' => gmdate('c'), 'odds_source' => 'ui-test', 'fair_odds' => 1.33, 'confidence' => 88.0, 'data_quality' => 100.0, 'model_probability' => 0.7, 'calibrated_probability' => 0.75, 'expected_value' => 0.5, 'risk' => 'LOW', 'result' => null, 'status' => 'PENDING']);
    $repo->savePrediction(['id' => 'prd_ui_1', 'match_id' => 9001, 'model_version_id' => $modelId, 'market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'raw_probability' => 0.7, 'calibrated_probability' => 0.75, 'expected_value' => 0.5, 'confidence' => 88.0, 'risk' => 'LOW', 'correlation' => 'LOW', 'data_quality_score' => 100, 'decision' => 'PREDICTION_READY', 'rejection_reasons' => '[]', 'factors' => json_encode(['gate' => ['passed' => []], 'drivers' => ['expectedGoalsProxy' => 2.45]]), 'input_version' => FeatureEngineeringEngine::VERSION, 'odds' => 2.0, 'odds_timestamp' => gmdate('c'), 'created_at' => gmdate('c')]);
    $repo->saveDailyTicket(['date' => gmdate('Y-m-d'), 'ticket_type' => 'ODDS_PREDICTION', 'ticket_id' => $ticketId, 'status' => 'PENDING_USER_APPROVAL', 'generation_status' => 'GENERATED', 'configuration_version' => 0, 'candidates_evaluated' => 1, 'predictions_recorded' => 1, 'rejections' => 0, 'rejection_summary' => json_encode(['_diagnostics' => ['eligibleFixtures' => 1, 'fixturesWithFreshOdds' => 1, 'fixturesRejectedStaleOdds' => 0, 'marketsEvaluated' => 1, 'predictionsGenerated' => 1, 'correlationQualifiedCandidates' => 1, 'finalQualifiedCandidates' => 1]]), 'message' => 'odds prediction ticket generated; awaiting user approval', 'provider' => 'ui-test', 'run_id' => 'run_ui', 'attempt_count' => 1, 'generated_at' => gmdate('c'), 'created_at' => gmdate('c'), 'updated_at' => gmdate('c')]);
    return $ticketId;
}

test('sports UI: console routes and controller are wired', function () {
    $routes = file_get_contents(FCPATH . 'application/config/routes.php');
    assert_contains('$route[\'sports\'] = \'sports\';', $routes);
    assert_contains('$route[\'sports/tickets\'] = \'sports/tickets\';', $routes);
    assert_contains('$route[\'sports/match-odds-records\'] = \'sports/tickets\';', $routes);
    assert_contains('$route[\'sports/sync\'] = \'sports/sync\';', $routes);
    assert_contains('$route[\'sports/(:any)/decide\'] = \'sports/decide/$1\';', $routes);
    assert_contains('$route[\'sports/(:any)/settle\'] = \'sports/settle/$1\';', $routes);
    $controller = file_get_contents(FCPATH . 'application/controllers/Sports.php');
    foreach (['public function index()', 'public function tickets()', 'public function sync()', 'public function decide(string $id)', 'public function settle(string $id)'] as $m) assert_contains($m, $controller);
    // mutations must enforce the sports RBAC matrix
    assert_contains("requireSportsPermission('sports.manage'", $controller);
    assert_contains("requireSportsPermission('sports.approve'", $controller);
    assert_contains("requireSportsPermission('sports.settle'", $controller);
    // ...against permissions read from the database, not the sign-in snapshot
    $base = file_get_contents(FCPATH . 'application/core/MY_Controller.php');
    assert_contains('function refreshIdentityPermissions', $base);
    assert_contains('$this->refreshIdentityPermissions()', $base, 'API gate re-reads permissions');
    assert_contains('refreshIdentityPermissions(', $controller);
});

test('sports UI: console header links the Sports page', function () {
    $header = file_get_contents(FCPATH . 'application/views/layout/header.php');
    assert_contains('href="/sports"', $header);
    assert_contains("=== 'sports'", $header);
});

test('sports UI: dashboard renders the honest DISABLED_NO_PROVIDER state', function () {
    $repo = new SportsRepositoryStub();
    $intel = new SportsIntelligence($repo, fx_ui_audit());
    $dash = $intel->dashboard();
    assert_equals('DISABLED_NO_PROVIDER', $dash['systemStatus']['ticketEngine']);
    $html = fx_render_sports('index', ['dashboard' => $dash]);
    assert_contains('Sports Intelligence — odds prediction ticket engine', $html);
    assert_contains('DISABLED_NO_PROVIDER', $html);
    assert_contains('No providers registered', $html);
    assert_contains('Data feed', $html);
    assert_contains('/sports/sync', $html, 'console offers a one-click sync');
    assert_contains('Sync now', $html);
    assert_contains('<dt>Eligible fixtures</dt><dd class="mono">—</dd>', $html,
        'a date without a stored run remains unavailable rather than becoming a fabricated zero');
    assert_contains('No generation run is stored for', $html);
    assert_contains('</html>', $html);
});

test('sports UI: dashboard renders today odds prediction ticket with gated actions', function () {
    $repo = new SportsRepositoryStub();
    $ticketId = fx_ui_today($repo);
    $intel = new SportsIntelligence($repo, fx_ui_audit());
    $dash = $intel->dashboard();
    assert_equals(1, $dash['todayIntelligence']['upcomingCount']);
    assert_equals(1, $dash['todayIntelligence']['qualifiedPredictions']);
    $html = fx_render_sports('index', ['dashboard' => $dash]);
    assert_contains($ticketId, $html);
    assert_contains('PENDING_USER_APPROVAL', $html);
    assert_contains('HomeA vs AwayA', $html);
    // the approval forms post to the routed decide endpoint
    assert_contains('/sports/' . $ticketId . '/decide', $html);
    assert_contains('sports.approve', $html);
    assert_contains('sports.settle', $html);
    assert_contains('UI League', $html);
    foreach (['generation GENERATED', 'Ticket ID', 'Generated at', 'Real odds · source', 'WINDELS probability · fair', 'Confidence · quality', 'Edge / value', 'Selected picks', 'ui-test',
        'implied / break-even', 'fair odds', 'data quality', 'model edge', 'EV', 'status PENDING', 'How to read the odds'] as $field) {
        assert_contains($field, $html, 'generated ticket exposes ' . $field);
    }
    assert_contains('class="sports-main stack"', $html, 'the page has a dedicated content feed');
    assert_contains('class="sports-side stack"', $html, 'and a separate context rail');
    assert_true(strpos($html, "Today's intelligence") < strpos($html, "Today's odds prediction ticket"), 'overview precedes the ticket');
    assert_true(strpos($html, "Today's odds prediction ticket") < strpos($html, 'Live scores — auto-updating'), 'ticket precedes live scores');
    assert_true(strpos($html, 'Live scores — auto-updating') < strpos($html, '30-day odds prediction ticket performance'), 'live scores precede measured performance');

    $css = file_get_contents(FCPATH . 'assets/css/ai_workforce.css');
    assert_contains('.sports-side, .football-side, .football-match-side', $css, 'the local context rails share one sticky contract');
    assert_contains('position: sticky', $css);
    assert_contains('max-height: calc(100dvh - var(--rail-top) - var(--rail-tail))', $css, 'an over-height rail stays bounded to the viewport');
    assert_contains('overflow-y: auto', $css, 'and can expose all of its own context without moving the content feed');
});

/**
 * The rail must stay pinned to the END of the page, with only the feed moving.
 *
 * A sticky item can never paint outside its own grid area, and that area ends
 * where the feed ends — above the page's bottom padding and the footer. A rail
 * capped only against the viewport height is therefore taller than the space
 * left at maximum scroll: in the last stretch of the page it gets pushed up off
 * its `top` offset and visibly scrolls away, which is exactly the "sidebar does
 * not stay pinned at the end" report. Reserving the page tail in the cap keeps
 * the whole rail inside its area at every scroll position.
 *
 * The wheel must also never be trapped over the rail — `overscroll-behavior:
 * contain` stops the page (the feed) from scrolling once the rail bottoms out.
 */
test('sports UI: the context rail stays pinned at the end of the page and never traps the feed', function () {
    $css = (string) file_get_contents(FCPATH . 'assets/css/ai_workforce.css');
    $start = strpos($css, '@media (min-width: 1181px)');
    assert_true($start !== false, 'the desktop rail contract exists');
    $rail = substr($css, (int) $start, 1600);

    assert_contains('--rail-top: 76px', $rail, 'the rail parks under the sticky topbar');
    assert_contains('--rail-tail: 136px', $rail, 'and reserves the page tail (main padding + footer)');
    assert_contains('top: var(--rail-top)', $rail, 'the pin offset and the cap read the same value');
    assert_contains('align-self: start', $rail,
        'a stretched grid item fills its row and has no room to stick — the pin needs a start-aligned item');
    assert_contains('overscroll-behavior: auto', $rail,
        'scrolling chains back to the page so the feed keeps moving when the rail bottoms out');
    assert_true(!str_contains($rail, 'overscroll-behavior: contain'),
        'the wheel must never be trapped inside the rail');

    // The cap must subtract BOTH the top offset and the page tail; a bare
    // viewport height (the old 100dvh - 92px) is the regression this pins.
    assert_true((bool) preg_match('/max-height:\s*calc\(100dvh\s*-\s*var\(--rail-top\)\s*-\s*var\(--rail-tail\)\)/', $rail),
        'the cap subtracts the top offset and the untouchable page tail');
    assert_true(!str_contains($rail, 'calc(100dvh - 92px)'),
        'the viewport-only cap let the rail run past its grid area at maximum scroll');

    // Below the two-column breakpoint the rail is part of the single reading
    // order and must drop every pinning property.
    $mobile = substr($css, (int) strpos($css, '@media (max-width: 1180px)'), 600);
    assert_contains('position: static', $mobile, 'the rail unpins when it no longer sits beside the feed');
    assert_contains('max-height: none', $mobile);
    assert_contains('overflow: visible', $mobile, 'and never keeps a private scrollbar in one-column mode');
});

/**
 * Every block in the feed is the SAME object: one .sports-section panel with a
 * numbered heading and a body. That uniformity is what makes the page readable
 * top to bottom, so it is pinned here rather than left to drift back into a
 * pile of one-off panels with inline styles.
 */
test('sports UI: the feed sections are uniform, numbered and free of inline layout styles', function () {
    $repo = new SportsRepositoryStub();
    fx_ui_today($repo);
    $dash = (new SportsIntelligence($repo, fx_ui_audit()))->dashboard();
    $html = fx_render_sports('index', ['dashboard' => $dash]);
    $view = (string) file_get_contents(FCPATH . 'application/views/sports/index.php');

    // Four numbered steps in the feed, in reading order.
    foreach ([1, 2, 3, 4] as $step) {
        assert_contains('<span class="sports-step" aria-hidden="true">' . $step . '</span>', $html,
            'the feed numbers step ' . $step);
    }
    // Each section is a landmark with its own accessible name.
    foreach (['sports-overview-heading', 'sports-ticket-heading', 'sports-live-heading', 'sports-performance-heading',
        'sports-run-heading', 'sports-guide-heading', 'sports-system-heading'] as $id) {
        assert_contains('aria-labelledby="' . $id . '"', $html, $id . ' names its section');
        assert_contains('id="' . $id . '"', $html, $id . ' exists on the heading');
    }
    // One heading object everywhere: eyebrow + title, never a bare <h3>.
    assert_equals(substr_count($html, 'class="sports-section__heading"'), substr_count($html, 'class="sports-section__title"'),
        'every section heading carries the same title block');
    assert_true(substr_count($html, 'class="panel sports-section') >= 6, 'the feed and the rail share one section object');

    // Every block states what it is before showing numbers.
    foreach (['Day overview', 'Engine output', 'In play', 'Measured results', 'Generation funnel', 'Reading the numbers', 'Platform state'] as $eyebrow) {
        assert_contains($eyebrow, $html, 'the section is introduced as "' . $eyebrow . '"');
    }
    // The generation summary is a complete sidebar section, not a duplicate
    // stat grid buried inside the ticket body.
    $railStart = strpos($view, '<aside class="sports-side');
    $runStart = strpos($view, 'id="sports-run-summary"');
    $guideStart = strpos($view, 'sports-reading-guide', (int) $runStart);
    assert_true($railStart !== false && $runStart !== false && $guideStart !== false && $runStart > $railStart,
        'the generation-run summary lives in the sports sidebar rail');
    $runPanel = substr($view, (int) $runStart, (int) $guideStart - (int) $runStart);
    foreach (['Eligible fixtures', 'Fixtures evaluated', 'Predictions generated', 'Fresh odds',
        'Stale odds', 'Qualified candidates', 'Selected picks'] as $metric) {
        assert_contains('>' . $metric . '<', $runPanel, $metric . ' is present in the sidebar generation summary');
    }
    foreach (['eligibleFixtures', 'fixturesEvaluated', 'predictionsGenerated', 'fixturesWithFreshOdds',
        'fixturesRejectedStaleOdds', 'correlationQualifiedCandidates', 'finalQualifiedCandidates'] as $field) {
        assert_contains("\$runMetrics['" . $field . "']", $runPanel, $field . ' is bound to the stored run summary');
    }
    foreach (['Eligible fixtures', 'Fixtures evaluated', 'Predictions generated', 'Fresh odds',
        'Qualified candidates', 'Selected picks'] as $metric) {
        assert_contains('<dt>' . $metric . '</dt><dd class="mono">1</dd>', $html,
            $metric . ' renders the value from the seeded stored run');
    }
    assert_contains('<dt>Stale odds</dt><dd class="mono">0</dd>', $html,
        'a measured zero stale-odds count remains a real zero');
    // Sub-blocks inside a section use one shared sub-heading, not ad-hoc bold text.
    foreach (['Risk distribution', 'Scheduled fixtures', 'Selected picks'] as $sub) {
        assert_contains('<h4>' . $sub . '</h4>', $html, $sub . ' is a real sub-heading');
    }

    // Spacing comes from the stylesheet. The only inline style left in the view
    // is the risk meter's computed width, which is data, not layout.
    $inline = [];
    if (preg_match_all('/style="([^"]*)"/', $view, $m)) $inline = $m[1];
    foreach ($inline as $style) {
        assert_true(str_contains($style, 'width:<?='),
            'layout must live in the stylesheet, found inline style: ' . $style);
    }
    foreach (['padding-top:12px', 'margin-top:12px', 'display:flex;gap:8px', 'font-size:11px'] as $gone) {
        assert_true(!str_contains($view, $gone), 'the ad-hoc spacing "' . $gone . '" was replaced by the section rhythm');
    }

    $css = (string) file_get_contents(FCPATH . 'assets/css/ai_workforce.css');
    assert_contains('.sports-section > .body { padding: 16px 20px 20px; display: flex; flex-direction: column; gap: 12px; }', $css,
        'one gap rule gives every section the same internal rhythm');
    assert_contains('.sports-section__heading', $css);
    assert_contains('.sports-step', $css);
});

test('sports UI: sidebar generation funnel fills legacy daily rows from persisted facts', function () {
    $repo = new SportsRepositoryStub();
    $repo->ensureProvider('legacy-ui', 'Legacy UI');
    $day = gmdate('Y-m-d');
    $kickoff = $day . 'T15:00:00+00:00';
    $repo->matches[] = ['id' => 9301, 'provider_id' => 1, 'external_id' => 'legacy-1', 'sport' => 'football',
        'competition' => 'Legacy League', 'home_team' => 'LegacyHome', 'away_team' => 'LegacyAway',
        'kickoff_at' => $kickoff, 'status' => 'SCHEDULED', 'source_timestamp' => $day . 'T09:00:00+00:00',
        'updated_at' => $day . 'T09:00:00+00:00', 'payload' => []];
    $repo->matches[] = ['id' => 9302, 'provider_id' => 1, 'external_id' => 'legacy-2', 'sport' => 'football',
        'competition' => 'Legacy League', 'home_team' => 'SecondHome', 'away_team' => 'SecondAway',
        'kickoff_at' => $kickoff, 'status' => 'SCHEDULED', 'source_timestamp' => $day . 'T09:00:00+00:00',
        'updated_at' => $day . 'T09:00:00+00:00', 'payload' => []];
    $repo->savePrediction(['id' => 'prd_legacy_1', 'match_id' => 9301, 'model_version_id' => 1, 'market' => 'TOTAL_GOALS',
        'selection' => 'OVER_1_5', 'raw_probability' => 0.7, 'calibrated_probability' => 0.75, 'expected_value' => 0.5,
        'confidence' => 82.0, 'risk' => 'LOW', 'correlation' => 'LOW', 'data_quality_score' => 100,
        'decision' => 'PREDICTION_READY', 'rejection_reasons' => '[]', 'factors' => '{}',
        'input_version' => 'legacy-test', 'odds' => 2.0, 'odds_timestamp' => $day . 'T10:00:00+00:00', 'created_at' => $day . 'T11:00:00+00:00']);
    $repo->savePrediction(['id' => 'prd_legacy_2', 'match_id' => 9302, 'model_version_id' => 1, 'market' => 'MATCH_RESULT',
        'selection' => 'HOME', 'raw_probability' => 0.58, 'calibrated_probability' => 0.6, 'expected_value' => 0.2,
        'confidence' => 79.0, 'risk' => 'LOW', 'correlation' => 'LOW', 'data_quality_score' => 90,
        'decision' => 'PREDICTION_READY', 'rejection_reasons' => '[]', 'factors' => '{}',
        'input_version' => 'legacy-test', 'odds' => 2.1, 'odds_timestamp' => $day . 'T10:05:00+00:00', 'created_at' => $day . 'T11:05:00+00:00']);

    $ticketId = 'tkt_legacy_metrics';
    $repo->saveTicket(['id' => $ticketId, 'created_at' => $day . 'T12:00:00+00:00', 'model_version_id' => 1,
        'configuration_version' => '0', 'total_odds' => 2.0, 'selection_count' => 1, 'combined_probability' => 0.5,
        'confidence' => 82.0, 'risk' => 'LOW', 'correlation' => 'LOW', 'data_quality_score' => 100, 'status' => 'PENDING',
        'approval_status' => 'PENDING_USER_APPROVAL', 'settlement_status' => 'PENDING', 'stake' => 10.0, 'pnl' => null]);
    $repo->saveTicketSelection(['ticket_id' => $ticketId, 'prediction_id' => 'prd_legacy_1', 'match_id' => 9301,
        'market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'odds' => 2.0, 'odds_timestamp' => $day . 'T10:00:00+00:00',
        'model_probability' => 0.7, 'calibrated_probability' => 0.75, 'expected_value' => 0.5, 'risk' => 'LOW',
        'result' => null, 'status' => 'PENDING']);
    $repo->saveDailyTicket(['date' => $day, 'ticket_type' => 'ODDS_PREDICTION', 'ticket_id' => $ticketId,
        'status' => 'PENDING_USER_APPROVAL', 'generation_status' => 'GENERATED', 'configuration_version' => 0,
        'candidates_evaluated' => 4, 'predictions_recorded' => 2, 'rejections' => 2,
        // Legacy shape: no _diagnostics. The persisted counters still say 4 evaluated,
        // one fixture died before eligibility and one at the stale-odds gate.
        'rejection_summary' => json_encode(['FIXTURE_NOT_NS_OR_TOO_SOON' => 1, 'STALE_ODDS' => 1]),
        'message' => 'legacy run without diagnostics', 'provider' => 'legacy-ui', 'run_id' => 'run_legacy_metrics',
        'attempt_count' => 1, 'generated_at' => $day . 'T12:00:00+00:00', 'created_at' => $day . 'T12:00:00+00:00', 'updated_at' => $day . 'T12:00:00+00:00']);

    $dash = (new SportsIntelligence($repo, fx_ui_audit()))->dashboard($day);
    assert_equals(4, $dash['ticketEngine']['runMetrics']['fixturesEvaluated']);
    assert_equals(3, $dash['ticketEngine']['runMetrics']['eligibleFixtures']);
    assert_equals(2, $dash['ticketEngine']['runMetrics']['predictionsGenerated']);
    assert_equals(2, $dash['ticketEngine']['runMetrics']['fixturesWithFreshOdds']);
    assert_equals(1, $dash['ticketEngine']['runMetrics']['fixturesRejectedStaleOdds']);
    assert_equals(2, $dash['ticketEngine']['runMetrics']['correlationQualifiedCandidates']);
    assert_equals(1, $dash['ticketEngine']['runMetrics']['finalQualifiedCandidates']);

    $html = fx_render_sports('index', ['dashboard' => $dash, 'date' => $day]);
    foreach ([
        'Eligible fixtures' => 3,
        'Fixtures evaluated' => 4,
        'Predictions generated' => 2,
        'Fresh odds' => 2,
        'Stale odds' => 1,
        'Qualified candidates' => 2,
        'Selected picks' => 1,
    ] as $metric => $value) {
        assert_contains('<dt>' . $metric . '</dt><dd class="mono">' . $value . '</dd>', $html,
            $metric . ' is reconstructed from persisted legacy run facts');
    }
});

test('sports UI: the live scores board shows the match date and time', function () {
    $repo = new SportsRepositoryStub();
    $repo->ensureProvider('ui-test', 'UI Test');
    // One live match with a stored kickoff, one the provider gave none for: the
    // board prints the second as — rather than inventing a time.
    $kickoff = gmdate("Y-m-d\TH:i:00+00:00", strtotime('today 14:30:00'));
    $repo->matches[] = ['id' => 9101, 'provider_id' => 1, 'external_id' => 'ui-live-1', 'sport' => 'football',
        'competition' => 'UI League', 'home_team' => 'LiveHome', 'away_team' => 'LiveAway', 'kickoff_at' => $kickoff,
        'status' => 'LIVE', 'source_timestamp' => gmdate('c'), 'updated_at' => gmdate('c'),
        'payload' => ['live' => ['minute' => 63, 'homeScore' => 2, 'awayScore' => 1, 'statusShort' => '2H']]];
    $repo->matches[] = ['id' => 9102, 'provider_id' => 1, 'external_id' => 'ui-live-2', 'sport' => 'football',
        'competition' => 'UI League', 'home_team' => 'NoKickoffHome', 'away_team' => 'NoKickoffAway', 'kickoff_at' => null,
        'status' => 'LIVE', 'source_timestamp' => gmdate('c'), 'updated_at' => gmdate('c'),
        'payload' => ['live' => ['minute' => 12, 'homeScore' => 0, 'awayScore' => 0]]];
    $dash = (new SportsIntelligence($repo, fx_ui_audit()))->dashboard();
    assert_equals(2, count($dash['todayIntelligence']['live']), 'both live rows reach the board');
    $html = fx_render_sports('index', ['dashboard' => $dash]);
    assert_contains('Kickoff (UTC)</th>', $html, 'the live board has a match date and time column');
    assert_contains('<td class="mono dim live-kickoff-cell">' . gmdate('Y-m-d H:i', (int) strtotime($kickoff)) . '</td>', $html,
        'the live row prints its stored kickoff as date and time');
    assert_contains('<td class="mono dim live-kickoff-cell">—</td>', $html,
        'a match with no stored kickoff prints — instead of a time');
    assert_contains('NoKickoffHome vs NoKickoffAway', $html);
    assert_true(!str_contains($html, 'Undefined array key'), 'no PHP warnings');
    assert_true(!str_contains($html, '1970-01-01'), 'a missing kickoff is never rendered as the epoch');
});

test('sports UI: NO QUALIFIED TICKET panel names every funnel stage, the blocking field and the provider', function () {
    $diagnostics = [
        'fixturesEvaluated' => 4, 'eligibleFixtures' => 4, 'fixturesWithFreshOdds' => 3,
        'fixturesRejectedNoOdds' => 0, 'fixturesRejectedStaleOdds' => 1,
        'fixturesWithRecentForm' => 2, 'sufficientDataFixtures' => 2,
        'marketsEvaluated' => 7, 'predictionsGenerated' => 5,
        'confidenceQualifiedCandidates' => 3, 'positiveValueCandidates' => 3,
        'riskQualifiedCandidates' => 3, 'finalQualifiedCandidates' => 0,
        'generationCap' => 50, 'fixturesDeferred' => 1,
        'deferredFixtures' => ['truncated' => false, 'rows' => [
            ['matchId' => 9001, 'externalId' => 'DEF-1', 'provider' => 'api-football', 'kickoff' => gmdate('c', strtotime('+1 day'))],
        ]],
        'sufficientDataGate' => ['limit' => 100, 'truncated' => false, 'passed' => 2, 'failed' => 1, 'fixtures' => [
            [
                'matchId' => 7001, 'externalId' => 'GAP-1', 'homeTeam' => 'Gap Home', 'awayTeam' => 'Gap Away',
                'competition' => 'Gap League', 'kickoff' => gmdate('c', strtotime('+1 day')), 'provider' => 'sportmonks',
                'passed' => false, 'failedRequirement' => 'MANDATORY_MODEL_DATA', 'primaryReason' => 'INSUFFICIENT_DATA',
                'requirements' => ['MANDATORY_MODEL_DATA' => ['ok' => false, 'missingMandatory' => ['recentForm'], 'mandatoryFields' => ['recentForm']]],
            ],
        ]],
        'thresholds' => ['oddsMaxAgeSeconds' => 21600],
        'topRejectionReasons' => [], 'rejectionReasonsByProvider' => [],
    ];
    $daily = [
        'date' => gmdate('Y-m-d'), 'ticket_id' => null, 'status' => 'NO_QUALIFIED_TICKET',
        'candidates_evaluated' => 4, 'predictions_recorded' => 5, 'rejections' => 4,
        'rejection_summary' => ['_diagnostics' => $diagnostics],
        'message' => 'Today\'s available matches did not meet the configured prediction requirements',
        'provider' => 'api-football',
    ];
    $html = fx_render_sports('index', ['dashboard' => ['ticketEngine' => ['today' => $daily, 'ticket' => null, 'configuration' => ['engine_mode' => 'USER_APPROVAL_REQUIRED']]]]);
    assert_contains('Markets evaluated', $html);
    assert_contains('Stale odds', $html);
    assert_contains('No real odds', $html);
    assert_contains('Gap Home vs Gap Away', $html, 'the blocked fixture is named');
    assert_contains('sportmonks', $html, 'the provider behind the blocker is named');
    assert_contains('recentForm', $html, 'the concrete missing field is named');
    assert_contains('DEF-1', $html, 'the deferred fixture past the generation cap is named');
    assert_true(!str_contains($html, 'Undefined array key'), 'no PHP warnings');
    assert_true(!str_contains($html, 'Fatal error'), 'no render error');
});

test('sports UI: records console renders records, runs and performance', function () {
    $repo = new SportsRepositoryStub();
    $ticketId = fx_ui_today($repo);
    $intel = new SportsIntelligence($repo, fx_ui_audit());
    $html = fx_render_sports('tickets', [
        'tickets' => $repo->listTickets([], 100),
        'dailyRuns' => $repo->listDailyTickets(30),
        'performance' => $intel->performanceReport([]),
    ]);
    assert_contains($ticketId, $html);
    assert_contains('PENDING_USER_APPROVAL', $html);
    assert_contains('awaiting user approval', $html);
    assert_contains('/sports/' . $ticketId . '/settle', $html);
    assert_contains('DEMO / SANDBOX DATA', $html, 'sandbox statistics are clearly labeled');
    assert_true(!str_contains($html, 'No odds prediction tickets generated yet'), 'seeded ticket must be listed, not the empty state');
});

test('sports UI: a read-only identity is told why, instead of being handed a refused button', function () {
    $repo = new SportsRepositoryStub();
    $ticketId = fx_ui_today($repo);
    $intel = new SportsIntelligence($repo, fx_ui_audit());
    $dash = $intel->dashboard();

    $html = fx_render_sports('index', ['dashboard' => $dash, 'caps' => fx_sports_caps_none()]);
    // No POST targets an identity would only be refused from.
    assert_not_contains('/sports/sync', $html, 'sync form is rendered for sports.manage only');
    assert_not_contains('/sports/' . $ticketId . '/decide', $html, 'decide form needs sports.approve');
    assert_not_contains('/sports/' . $ticketId . '/settle', $html, 'settle form needs sports.settle');
    // Action labels stay clean; the missing permission is named in the title/help copy.
    assert_contains('Sync now', $html);
    assert_contains('🎯 Odds Prediction Ticket', $html);
    assert_contains(gmdate('m/d/Y'), $html, 'today\'s ticket date is shown as MM/DD/YYYY');
    assert_true(!str_contains($html, 'Sync now (needs sports.manage)'), 'sync label must not include the permission suffix');
    assert_true(!str_contains($html, '🎯 Odds Prediction Ticket — needs sports.manage'), 'ticket label must not include the permission suffix');
    assert_contains('Requires the sports.manage permission', $html);
    assert_contains('needs sports.approve', $html);
    assert_contains('needs sports.settle', $html);
    assert_contains('disabled', $html, 'unavailable controls are visibly disabled');

    $tickets = fx_render_sports('tickets', [
        'tickets' => $repo->listTickets([], 100),
        'dailyRuns' => $repo->listDailyTickets(30),
        'performance' => $intel->performanceReport([]),
        'caps' => fx_sports_caps_none(),
    ]);
    assert_not_contains('/sports/' . $ticketId . '/decide', $tickets);
    assert_not_contains('/sports/' . $ticketId . '/settle', $tickets);
    assert_contains('needs sports.approve', $tickets);
    assert_contains('needs sports.settle', $tickets);
});

test('sports UI: provider identities and diagnostics are hidden from read-only users', function () {
    $repo = new SportsRepositoryStub();
    $ticketId = fx_ui_today($repo);
    $intel = new SportsIntelligence($repo, fx_ui_audit());
    $dash = $intel->dashboard();
    // Simulate the operator-side readiness/health payload a real provider set produces.
    $dash['systemStatus']['ticketEngine'] = 'BLOCKED';
    $dash['systemStatus']['configuredIds'] = ['api-football', 'sportmonks'];
    $dash['systemStatus']['liveHealth'] = [
        'api-football' => ['status' => 'DAILY_QUOTA_EXHAUSTED', 'detail' => 'daily quota used (100/100 on the Free plan)', 'requestsToday' => 100, 'limitDaily' => 100, 'circuit' => ['state' => 'OPEN', 'retryAt' => '2026-09-06T00:00:00+00:00']],
        'sportmonks' => ['status' => 'NOT_FOUND', 'detail' => 'endpoint not found (HTTP 404)', 'endpoint' => 'https://api.sportmonks.com/v3/football/fixtures/date/2026-09-05?api_token=[redacted]', 'circuit' => ['state' => 'OPEN']],
    ];
    $dash['systemStatus']['readiness'] = ['operational' => 0, 'total' => 2, 'engine' => 'BLOCKED', 'providers' => [
        'api-football' => ['status' => 'DAILY_QUOTA_EXHAUSTED', 'circuit' => 'OPEN', 'retryAt' => '2026-09-06T00:00:00+00:00'],
        'sportmonks' => ['status' => 'NOT_FOUND', 'circuit' => 'OPEN', 'retryAt' => null],
    ]];
    $dash['ticketEngine']['today'] = ['status' => 'DATA_UNAVAILABLE', 'message' => 'all configured sports-data providers failed — fixtures: all 2 provider(s) failed — api-football DAILY_QUOTA_EXHAUSTED, sportmonks NOT_FOUND', 'rejection_summary' => ['PROVIDER:api-football' => 'DAILY_QUOTA_EXHAUSTED', 'PROVIDER:sportmonks' => 'NOT_FOUND'], 'candidates_evaluated' => 0, 'predictions_recorded' => 0];

    $user = fx_render_sports('index', ['dashboard' => $dash, 'caps' => fx_sports_caps_none()]);
    foreach (['api-football', 'sportmonks', 'Data feed', 'Operational providers', 'DAILY_QUOTA_EXHAUSTED', 'NOT_FOUND', 'HTTP 404', 'quota', 'Circuit', 'Feed 1', 'sportmonks.com', 'providers failed'] as $leak) {
        assert_not_contains($leak, $user, "read-only page must not expose '{$leak}'");
    }
    // ...but the user still learns, honestly, that data is unavailable and the engine is blocked.
    assert_contains('Sports data temporarily unavailable', $user);
    assert_contains('BLOCKED', $user);
    assert_contains('NO TICKET — DATA_UNAVAILABLE', $user);
    assert_contains('Sports data was unavailable', $user);

    // The operator (sports.manage) keeps the full diagnostic view.
    $op = fx_render_sports('index', ['dashboard' => $dash, 'caps' => fx_sports_caps_all()]);
    foreach (['api-football', 'sportmonks', 'Data feed', 'Operational providers', 'Daily quota exhausted', 'HTTP 404 (not found)', '100/100 used', 'Circuit', 'OPEN'] as $diag) {
        assert_contains($diag, $op, "operator page must show '{$diag}'");
    }

    // And the JSON provider endpoint requires the same operator permission.
    $api = file_get_contents(FCPATH . 'application/controllers/Api_sports.php');
    assert_true((bool) preg_match("/function providers\(\)\s*\{[^}]*requirePermission\('sports\.manage'/s", (string) $api), 'GET providers is gated by sports.manage');
});

/**
 * The ticket screen (/sports/odds-prediction-ticket) is the same reading
 * system as the board it belongs to.
 *
 * Before this contract it was the odd one out: a bare `page-head`, four
 * unlabelled `.panel`s stacked in one column with no context rail, and about
 * a hundred inline `style="..."` attributes carrying every bit of its spacing,
 * font size and colour. Two screens that show the same data in the same
 * product should not be built two different ways, so the page now uses the
 * hero + actionbar + numbered `.sports-section` feed + pinned rail that
 * /sports uses, and its layout lives in the stylesheet.
 */
test('sports UI: the ticket screen is one numbered feed beside a pinned rail', function () {
    $repo = new SportsRepositoryStub();
    fx_ui_today($repo);
    $intel = new SportsIntelligence($repo, fx_ui_audit());
    $html = fx_render_sports('tickets', [
        'tickets' => $repo->listTickets([], 100),
        'dailyRuns' => $repo->listDailyTickets(30),
        'performance' => $intel->performanceReport([]),
    ]);
    $view = (string) file_get_contents(FCPATH . 'application/views/sports/tickets.php');

    // 1. The shell: the same three objects the board opens with.
    assert_contains('class="sports-console"', $view, 'the screen is a sports console, not a bare page-head');
    assert_contains('class="sports-hero"', $view, 'with the standard hero');
    assert_contains('class="sports-actionbar"', $view, 'and the controls in their own bar, not crammed into the hero');
    assert_true(!str_contains($view, 'class="page-head"'), 'the generic page-head shell is gone');
    assert_contains('<div class="sports-layout">', $view, 'the feed and the rail share the two-column grid');
    assert_contains('<aside class="sports-side stack"', $view, 'the rail is a real landmark');

    // 2. Five numbered records, in reading order.
    foreach ([1, 2, 3, 4, 5] as $step) {
        assert_contains('<span class="sports-step" aria-hidden="true">' . $step . '</span>', $view,
            'the feed numbers step ' . $step);
    }
    assert_equals(5, substr_count($view, 'class="sports-step"'), 'the rail stays unnumbered reference');

    // 3. Every section is a named landmark whose label resolves.
    foreach (['ticket-today-heading', 'ticket-picks-heading', 'ticket-rejections-heading',
        'ticket-history-heading', 'ticket-runs-heading', 'ticket-guide-heading', 'ticket-states-heading'] as $id) {
        assert_contains('aria-labelledby="' . $id . '"', $view, $id . ' names its section');
        assert_contains('id="' . $id . '"', $view, $id . ' exists on the heading');
    }
    preg_match_all('/aria-labelledby="([^"]+)"/', $view, $labelled);
    preg_match_all('/id="([^"]+)"/', $view, $ids);
    foreach (array_unique($labelled[1] ?? []) as $reference) {
        assert_true(in_array($reference, $ids[1] ?? [], true), $reference . ' must exist as an id');
    }

    // 4. One heading object everywhere, each opening with an eyebrow.
    assert_equals(substr_count($view, 'class="sports-section__heading"'), substr_count($view, 'class="sports-section__title"'),
        'every section heading carries the same title block');
    foreach (['Today&rsquo;s ticket', 'Per-match predictions', 'Audit trail', 'Stored records', 'Run history',
        'Reading the numbers', 'Lifecycle'] as $eyebrow) {
        assert_contains('sports-eyebrow">' . $eyebrow . '</p>', $view, 'a section is introduced as "' . $eyebrow . '"');
    }
    assert_true(substr_count($view, 'class="panel sports-section') >= 7, 'feed and rail share one section object');

    // 5. Every section opens with a plain-language write-up.
    foreach (['The one combined ticket built for this date',
        'Every ticket persisted so far',
        'One row per day the engine ran'] as $writeUp) {
        assert_contains($writeUp, $view, 'the screen explains its section: ' . $writeUp);
    }

    // 6. Layout is in the stylesheet. The only inline style left is the
    // distribution bar's computed width, which is data, not layout.
    $inline = [];
    if (preg_match_all('/style="([^"]*)"/', $view, $m)) $inline = $m[1];
    foreach ($inline as $style) {
        assert_true(str_contains($style, "width:' . \$pct"),
            'layout must live in the stylesheet, found inline style: ' . $style);
    }
    foreach (['font-size:10px', 'font-size:11px', 'font-size:12px', 'margin-bottom:12px', 'padding-top:12px',
        'display:inline-flex', 'display:inline', 'font-weight:700'] as $gone) {
        assert_true(!str_contains($view, $gone), 'the ad-hoc rule "' . $gone . '" was replaced by a class');
    }

    // 7. It renders, and the reading guide reaches the page.
    assert_contains('What each column means', $html, 'the rail explains the columns');
    assert_contains('How a ticket moves', $html, 'and the ticket lifecycle');
    assert_contains('not measured', $html, 'the movement honesty rule survives the restructure');
    assert_true(!str_contains($html, 'Undefined array key'), 'no PHP warnings');
    assert_true(!str_contains($html, 'Fatal error'), 'no render error');

    // 8. The rail obeys the same pin contract as every other console rail.
    $css = (string) file_get_contents(FCPATH . 'assets/css/ai_workforce.css');
    $rail = substr($css, (int) strpos($css, '@media (min-width: 1181px)'), 1600);
    assert_contains('.sports-side', $rail, 'the ticket rail is covered by the sticky contract');
    assert_contains('--rail-tail: 136px', $rail, 'so it stays pinned at the end of the page, not just mid-scroll');
});
