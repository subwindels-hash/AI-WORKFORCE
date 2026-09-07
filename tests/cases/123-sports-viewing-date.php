<?php
use AIWorkforce\Persistence\AuditRepository;
use AIWorkforce\Sports\SportsIntelligence;

function fx_vd_audit(): AuditRepository
{
    return new class implements AuditRepository { public array $events = []; public function emit(string $t, string $s, array $d = [], string $a = 'system'): void { $this->events[] = ['type' => $t, 'actor' => $a, 'detail' => $d]; } public function recent(int $l = 100): array { return []; } };
}

/** Render the sports console index (header + page + footer) and return the HTML. */
function fx_vd_render_index(array $extra): string
{
    $ci = ci();
    $data = array_merge([
        'title' => 'Sports Intelligence', 'active' => 'sports',
        'status' => ['tradingMode' => 'ANALYSIS_ONLY', 'killSwitch' => ['active' => false], 'providers' => []],
        'notice' => null, 'error' => null,
        'caps' => ['sync' => true, 'approve' => true, 'settle' => true],
    ], $extra);
    ob_start();
    $ci->load->view('layout/header', $data);
    $ci->load->view('sports/index', $data);
    $ci->load->view('layout/footer');
    return (string) ob_get_clean();
}

/** Seed two days of stored state: one match, prediction and daily run per day. */
function fx_vd_seed(SportsRepositoryStub $repo): void
{
    $repo->ensureProvider('vd-test', 'VD Test');
    $repo->matches[] = ['id' => 9201, 'provider_id' => 1, 'external_id' => 'vd-1', 'sport' => 'football',
        'competition' => 'VD League', 'home_team' => 'SeptHome', 'away_team' => 'SeptAway',
        'kickoff_at' => '2026-09-01T15:00:00+00:00', 'status' => 'SCHEDULED',
        'source_timestamp' => '2026-09-01T10:00:00+00:00', 'updated_at' => '2026-09-01T10:00:00+00:00', 'payload' => []];
    $repo->matches[] = ['id' => 9202, 'provider_id' => 1, 'external_id' => 'vd-2', 'sport' => 'football',
        'competition' => 'VD League', 'home_team' => 'OctHome', 'away_team' => 'OctAway',
        'kickoff_at' => '2026-09-02T15:00:00+00:00', 'status' => 'SCHEDULED',
        'source_timestamp' => '2026-09-02T10:00:00+00:00', 'updated_at' => '2026-09-02T10:00:00+00:00', 'payload' => []];
    $repo->savePrediction(['id' => 'prd_vd_1', 'match_id' => 9201, 'model_version_id' => 1, 'market' => 'TOTAL_GOALS',
        'selection' => 'OVER_1_5', 'raw_probability' => 0.7, 'calibrated_probability' => 0.75, 'expected_value' => 0.5,
        'confidence' => 80.0, 'risk' => 'LOW', 'correlation' => 'LOW', 'data_quality_score' => 100,
        'decision' => 'PREDICTION_READY', 'rejection_reasons' => '[]', 'factors' => '{}',
        'input_version' => 'test', 'odds' => 2.0, 'odds_timestamp' => '2026-09-01T12:00:00+00:00', 'created_at' => '2026-09-01T16:00:00+00:00']);
    $repo->savePrediction(['id' => 'prd_vd_2', 'match_id' => 9202, 'model_version_id' => 1, 'market' => 'TOTAL_GOALS',
        'selection' => 'OVER_1_5', 'raw_probability' => 0.6, 'calibrated_probability' => 0.65, 'expected_value' => 0.3,
        'confidence' => 70.0, 'risk' => 'MEDIUM', 'correlation' => 'LOW', 'data_quality_score' => 100,
        'decision' => 'PREDICTION_READY', 'rejection_reasons' => '[]', 'factors' => '{}',
        'input_version' => 'test', 'odds' => 2.1, 'odds_timestamp' => '2026-09-02T12:00:00+00:00', 'created_at' => '2026-09-02T16:00:00+00:00']);
    foreach ([['2026-09-01', 'tkt_vd_1', 9201, 'prd_vd_1'], ['2026-09-02', 'tkt_vd_2', 9202, 'prd_vd_2']] as [$day, $ticketId, $matchId, $predId]) {
        $repo->saveTicket(['id' => $ticketId, 'created_at' => $day . 'T17:00:00+00:00', 'model_version_id' => 1,
            'configuration_version' => '0', 'total_odds' => 2.0, 'selection_count' => 1, 'combined_probability' => 0.5,
            'confidence' => 80.0, 'risk' => 'LOW', 'correlation' => 'LOW', 'data_quality_score' => 100, 'status' => 'PENDING',
            'approval_status' => 'PENDING_USER_APPROVAL', 'settlement_status' => 'PENDING', 'stake' => 10.0, 'pnl' => null]);
        $repo->saveTicketSelection(['ticket_id' => $ticketId, 'prediction_id' => $predId, 'match_id' => $matchId,
            'market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'odds' => 2.0, 'odds_timestamp' => $day . 'T12:00:00+00:00',
            'model_probability' => 0.7, 'calibrated_probability' => 0.75, 'expected_value' => 0.5, 'risk' => 'LOW',
            'result' => null, 'status' => 'PENDING']);
        $repo->saveDailyTicket(['date' => $day, 'ticket_id' => $ticketId, 'status' => 'PENDING_USER_APPROVAL',
            'configuration_version' => 0, 'candidates_evaluated' => 1, 'predictions_recorded' => 1, 'rejections' => 0,
            'rejection_summary' => json_encode([]), 'message' => 'vd seed', 'provider' => 'vd-test',
            'run_id' => 'run_vd_' . $day, 'created_at' => $day . 'T17:00:00+00:00', 'updated_at' => $day . 'T17:00:00+00:00']);
    }
}

test('sports viewing date: dashboard reports the requested day, not always today', function () {
    $repo = new SportsRepositoryStub();
    fx_vd_seed($repo);
    $intel = new SportsIntelligence($repo, fx_vd_audit());

    $dash = $intel->dashboard('2026-09-01');
    assert_equals('2026-09-01', $dash['todayIntelligence']['date']);
    assert_equals(1, $dash['todayIntelligence']['upcomingCount'], 'only the viewed day kickoffs are listed');
    assert_equals('SeptHome', $dash['todayIntelligence']['upcoming'][0]['home_team']);
    assert_equals(1, $dash['todayIntelligence']['qualifiedPredictions'], 'only the viewed day predictions count');
    assert_equals('tkt_vd_1', $dash['ticketEngine']['today']['ticket_id'], 'the viewed day daily run is picked');
    assert_equals('tkt_vd_1', $dash['ticketEngine']['ticket']['id']);
    assert_equals('2026-08-03T00:00:00+00:00', $dash['performance']['filter']['from'], 'trailing 30-day window ends on the viewed day');
    assert_equals('2026-09-01T23:59:59+00:00', $dash['performance']['filter']['to']);

    $other = $intel->dashboard('2026-09-02');
    assert_equals('2026-09-02', $other['todayIntelligence']['date']);
    assert_equals('OctHome', $other['todayIntelligence']['upcoming'][0]['home_team']);
    assert_equals('tkt_vd_2', $other['ticketEngine']['ticket']['id']);
});

test('sports viewing date: no date still reports today (backward compatibility)', function () {
    $repo = new SportsRepositoryStub();
    $intel = new SportsIntelligence($repo, fx_vd_audit());
    assert_equals(gmdate('Y-m-d'), $intel->dashboard()['todayIntelligence']['date']);
    assert_equals(gmdate('Y-m-d'), $intel->dashboard(null)['todayIntelligence']['date']);
});

test('sports viewing date: an unreadable day falls back to today, never rolls over', function () {
    $repo = new SportsRepositoryStub();
    $intel = new SportsIntelligence($repo, fx_vd_audit());
    $today = gmdate('Y-m-d');
    foreach (['2026-02-30', '2026-13-01', 'not-a-date', '09/01/2026', ''] as $bad) {
        assert_equals($today, $intel->dashboard($bad)['todayIntelligence']['date'], 'date=' . var_export($bad, true) . ' must not be answered with another day');
    }
    assert_not_equals('2026-03-02', $intel->dashboard('2026-02-30')['todayIntelligence']['date'], 'Feb 30 must not roll into March');
});

test('sports viewing date: console and API honor ?date=', function () {
    $controller = file_get_contents(FCPATH . 'application/controllers/Sports.php');
    assert_contains("RequestParams::date(\$get, 'date'", $controller, 'console validates the ?date= parameter');
    assert_contains('->dashboard($date)', $controller, 'console reports the validated day');
    assert_contains("'yesterday'", $controller);
    assert_contains("'tomorrow'", $controller);

    $api = file_get_contents(FCPATH . 'application/controllers/Api_sports.php');
    assert_contains("RequestParams::date(\$g, 'date'", $api, 'JSON dashboard accepts ?date=');
    assert_contains("'request'", $api, 'JSON dashboard states the day it reported');
});

test('sports viewing date: the console date is changeable via picker and prev/next', function () {
    $repo = new SportsRepositoryStub();
    fx_vd_seed($repo);
    $intel = new SportsIntelligence($repo, fx_vd_audit());
    $html = fx_vd_render_index([
        'dashboard' => $intel->dashboard('2026-09-01'),
        'date' => '2026-09-01', 'yesterday' => '2026-08-31', 'tomorrow' => '2026-09-02', 'isToday' => false,
    ]);
    assert_contains('name="date" value="2026-09-01"', $html, 'viewing-date picker holds the viewed day');
    assert_contains('/sports?date=2026-08-31', $html, 'prev-day link');
    assert_contains('/sports?date=2026-09-02', $html, 'next-day link');
    assert_contains('href="/sports">Today', $html, 'one click back to today when viewing another day');
    assert_contains('09/01/2026', $html, 'the m/d/Y label follows the viewed day');
    assert_contains('Intelligence — 2026-09-01', $html);
    assert_contains('Odds prediction ticket — 2026-09-01', $html);
    assert_contains('tkt_vd_1', $html, 'the viewed day ticket is shown');
    assert_contains('SeptHome vs SeptAway', $html, 'the viewed day fixture is shown');

    $empty = fx_vd_render_index([
        'dashboard' => $intel->dashboard('2026-09-05'),
        'date' => '2026-09-05', 'yesterday' => '2026-09-04', 'tomorrow' => '2026-09-06', 'isToday' => false,
    ]);
    assert_contains('No scheduled fixtures stored for 2026-09-05.', $empty);
    assert_contains('No daily run recorded for 2026-09-05 yet.', $empty);
});

test('sports viewing date: today keeps its labels and hides the Today link', function () {
    $repo = new SportsRepositoryStub();
    $intel = new SportsIntelligence($repo, fx_vd_audit());
    $today = gmdate('Y-m-d');
    $html = fx_vd_render_index([
        'dashboard' => $intel->dashboard(),
        'date' => $today,
        'yesterday' => gmdate('Y-m-d', strtotime($today . ' -1 day')),
        'tomorrow' => gmdate('Y-m-d', strtotime($today . ' +1 day')),
        'isToday' => true,
    ]);
    assert_contains("Today's intelligence", $html);
    assert_contains("Today's odds prediction ticket", $html);
    assert_not_contains('href="/sports">Today', $html, 'no Today link when already viewing today');
});
