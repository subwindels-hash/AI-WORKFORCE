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
    assert_contains('max-height: calc(100dvh - 92px)', $css, 'an over-height rail stays bounded to the viewport');
    assert_contains('overflow-y: auto', $css, 'and can expose all of its own context without moving the content feed');
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
