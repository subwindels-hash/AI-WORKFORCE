<?php
/** Intraday ticket regeneration wired into the central sports cron sweep. */

require_once TESTSPATH . 'cases/148-odds-generation-workflow.php';

use AIWorkforce\Sports\SportsCronService;
use AIWorkforce\Sports\SportsIntelligence;

function ci160_harness(string $date): array
{
    [$repo, $audit, $providers] = fx148_ready($date);
    $sports = new SportsIntelligence($repo, $audit);
    foreach ($providers->all() as $provider) $sports->providers->register($provider);
    return [$repo, new SportsCronService($repo, $audit, $sports)];
}

test('scheduled ticket job refreshes a pending ticket when its interval is due', function () {
    $date = fx148_date(20);
    [$repo, $cron] = ci160_harness($date);

    $first = $cron->run('ticket', $date, ['scheduled' => true, 'ticketRefreshMinutes' => 60]);
    $firstId = (string) ($first['ticketId'] ?? '');
    assert_true($firstId !== '', 'initial scheduled pass generated a ticket');

    $repo->updateDailyTicket($date, ['generated_at' => gmdate('c', time() - 61 * 60)]);
    $second = $cron->run('ticket', $date, ['scheduled' => true, 'ticketRefreshMinutes' => 60]);
    $secondId = (string) ($second['ticketId'] ?? '');

    assert_true(!empty($second['refreshDue']), 'cron reports that the configured interval elapsed');
    assert_equals(60, (int) ($second['refreshIntervalMinutes'] ?? 0));
    assert_not_equals($firstId, $secondId, 'the due sweep rebuilt the ticket from current odds');
    assert_equals('SUPERSEDED', strtoupper((string) ($repo->findTicket($firstId)['approval_status'] ?? '')),
        'the prior pending ticket remains as a superseded audit record');
});

test('scheduled ticket job stays idempotent before the refresh interval', function () {
    $date = fx148_date(21);
    [$repo, $cron] = ci160_harness($date);

    $first = $cron->run('ticket', $date, ['scheduled' => true, 'ticketRefreshMinutes' => 120]);
    $firstId = (string) ($first['ticketId'] ?? '');
    $repo->updateDailyTicket($date, ['generated_at' => gmdate('c', time() - 30 * 60)]);
    $second = $cron->run('ticket', $date, ['scheduled' => true, 'ticketRefreshMinutes' => 120]);

    assert_false((bool) ($second['refreshDue'] ?? true), 'the interval has not elapsed');
    assert_equals($firstId, (string) ($second['ticketId'] ?? ''), 'the current ticket is returned unchanged');
    assert_true((bool) ($second['existing'] ?? false), 'the ordinary idempotent path was used');
});

test('scheduled refresh never supersedes an approved ticket', function () {
    $date = fx148_date(22);
    [$repo, $cron] = ci160_harness($date);

    $first = $cron->run('ticket', $date, ['scheduled' => true, 'ticketRefreshMinutes' => 15]);
    $ticketId = (string) ($first['ticketId'] ?? '');
    $repo->updateTicket($ticketId, ['status' => 'APPROVED', 'approval_status' => 'APPROVED_NOT_EXECUTED']);
    $repo->updateDailyTicket($date, ['generated_at' => gmdate('c', time() - 16 * 60)]);

    $after = $cron->run('ticket', $date, ['scheduled' => true, 'ticketRefreshMinutes' => 15]);
    assert_true(!empty($after['refreshDue']), 'the cadence gate did ask for a refresh');
    assert_equals($ticketId, (string) ($after['ticketId'] ?? ''), 'the approved ticket was protected');
    assert_equals('APPROVED_NOT_EXECUTED', strtoupper((string) ($repo->findTicket($ticketId)['approval_status'] ?? '')));
});

test('ticket refresh interval is bounded, disableable and has a safe default', function () {
    assert_equals(60, SportsCronService::ticketRefreshMinutes(null));
    assert_equals(0, SportsCronService::ticketRefreshMinutes('0'));
    assert_equals(15, SportsCronService::ticketRefreshMinutes('15'));
    assert_equals(1440, SportsCronService::ticketRefreshMinutes('1440'));
    assert_equals(60, SportsCronService::ticketRefreshMinutes('14'));
    assert_equals(60, SportsCronService::ticketRefreshMinutes('invalid'));
});

// Static wiring assertions catch regressions in the central runner/admin UI.
test('central cron runner and admin screen carry the ticket refresh setting', function () {
    $runner = file_get_contents(APPPATH . 'libraries/AIWorkforce/Cron/CronRunner.php');
    $admin = file_get_contents(APPPATH . 'controllers/Admin.php');
    $view = file_get_contents(APPPATH . 'views/admin/cron.php');
    assert_contains('TICKET_REFRESH_SETTING', $runner);
    assert_contains("['ticketRefreshMinutes' => \$refreshMinutes]", $runner);
    assert_contains('sports_ticket_refresh_minutes', $admin);
    assert_contains('sports_ticket_refresh_minutes', $view);
});
