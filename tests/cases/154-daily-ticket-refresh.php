<?php
/**
 * MULTIPLE GENERATIONS PER DAY — "refresh" mode (operator request 2026-09-15).
 *
 * The engine was strictly one ticket per calendar date: a deterministic ticket
 * id (sha256 of type|date), a UNIQUE(ticket_type, date) row, and an
 * existing-ticket short circuit that returned early before any work ran.
 * Odds move through the day, so the operator asked to be able to regenerate.
 *
 * The chosen behaviour is REFRESH, not accumulate: one CURRENT ticket per day.
 * Each refresh supersedes the previous ticket (kept, readable, as that pass's
 * audit trail) and builds a new one from current odds.
 *
 * THE SAFETY RULE, which these cases exist to protect: an APPROVED ticket is
 * never superseded. By approval time the operator may have staked real money,
 * so no refresh — manual or scheduled — may cancel it underneath them.
 */

require_once TESTSPATH . 'cases/148-odds-generation-workflow.php';

function ci154_ready(string $date): array
{
    return fx148_ready($date);
}

/** Run one generation pass; $refresh asks for a rebuild of an existing ticket. */
function ci154_run(object $service, string $date, bool $refresh = false): array
{
    $options = ['actor' => 'admin-154'];
    if ($refresh) { $options['refresh'] = true; $options['force'] = true; }
    return $service->runDaily($date, null, $options);
}

test('baseline: a second ordinary run still returns the SAME ticket (no accidental duplicates)', function () {
    $date = fx148_date(11);
    [$repo, , , $service] = ci154_ready($date);

    $first = ci154_run($service, $date);
    $second = ci154_run($service, $date);

    assert_not_null($first['ticketId'] ?? null, 'the first run produced a ticket');
    assert_equals($first['ticketId'], $second['ticketId'] ?? null, 'an ordinary re-run is still idempotent');
    assert_true((bool) ($second['existing'] ?? false), 'and reports that it returned the existing ticket');
});

test('refresh: supersedes the pending ticket and generates a NEW one', function () {
    $date = fx148_date(12);
    [$repo, , , $service] = ci154_ready($date);

    $first = ci154_run($service, $date);
    $firstId = (string) ($first['ticketId'] ?? '');
    assert_true($firstId !== '', 'the first pass produced a ticket');

    $second = ci154_run($service, $date, true);
    $secondId = (string) ($second['ticketId'] ?? '');
    assert_true($secondId !== '', 'the refresh produced a ticket');
    assert_not_equals($firstId, $secondId, 'the refresh produced a DIFFERENT ticket');
    assert_false((bool) ($second['existing'] ?? false), 'the refresh really regenerated rather than returning the old one');

    // The superseded ticket is retained and readable — it is the audit trail
    // of that pass, not deleted history.
    $old = $repo->findTicket($firstId);
    assert_not_null($old, 'the superseded ticket still exists');
    assert_equals('SUPERSEDED', strtoupper((string) ($old['approval_status'] ?? '')), 'and is marked SUPERSEDED');
    assert_true($repo->ticketSelections($firstId) !== [], 'its legs are kept as the audit trail');

    // The day now points at the new ticket.
    $daily = $repo->findDailyTicket($date);
    assert_equals($secondId, (string) ($daily['ticket_id'] ?? ''), 'the daily row tracks the current ticket');
});

test('refresh: an APPROVED ticket is NEVER superseded', function () {
    $date = fx148_date(13);
    [$repo, , , $service] = ci154_ready($date);

    $first = ci154_run($service, $date);
    $firstId = (string) ($first['ticketId'] ?? '');
    assert_true($firstId !== '', 'a ticket exists to approve');

    // The operator approves it — from here it may be a real, staked position.
    $repo->updateTicket($firstId, [
        'status' => 'APPROVED',
        'approval_status' => 'APPROVED_NOT_EXECUTED',
    ]);

    $after = ci154_run($service, $date, true);

    assert_equals($firstId, (string) ($after['ticketId'] ?? ''), 'the approved ticket is returned, not replaced');
    $ticket = $repo->findTicket($firstId);
    assert_equals('APPROVED_NOT_EXECUTED', strtoupper((string) ($ticket['approval_status'] ?? '')), 'it is still approved');
    assert_not_equals('SUPERSEDED', strtoupper((string) ($ticket['approval_status'] ?? '')), 'it was NOT superseded');
    assert_not_equals('CANCELLED', strtoupper((string) ($ticket['status'] ?? '')), 'and NOT cancelled');
    assert_true($repo->ticketSelections($firstId) !== [], 'its legs are intact');
});

test('refresh: a settled ticket is history and is never superseded', function () {
    $date = fx148_date(14);
    [$repo, , , $service] = ci154_ready($date);

    $first = ci154_run($service, $date);
    $firstId = (string) ($first['ticketId'] ?? '');
    $repo->updateTicket($firstId, ['settlement_status' => 'WON']);

    $after = ci154_run($service, $date, true);
    assert_equals($firstId, (string) ($after['ticketId'] ?? ''), 'the settled ticket is left alone');
    assert_equals('WON', strtoupper((string) ($repo->findTicket($firstId)['settlement_status'] ?? '')), 'its settlement stands');
});

test('refresh: repeated refreshes keep one CURRENT ticket and never collide', function () {
    $date = fx148_date(15);
    [$repo, , , $service] = ci154_ready($date);

    $ids = [];
    $ids[] = (string) (ci154_run($service, $date)['ticketId'] ?? '');
    for ($i = 0; $i < 3; $i++) {
        $ids[] = (string) (ci154_run($service, $date, true)['ticketId'] ?? '');
    }
    $ids = array_values(array_filter($ids));

    assert_equals(count($ids), count(array_unique($ids)), 'every pass wrote its own ticket id — no overwrite');

    // Exactly one is current; all the earlier ones are superseded.
    $current = (string) ($repo->findDailyTicket($date)['ticket_id'] ?? '');
    assert_equals(end($ids), $current, 'the newest ticket is the current one');
    foreach (array_slice($ids, 0, -1) as $old) {
        assert_equals('SUPERSEDED', strtoupper((string) ($repo->findTicket($old)['approval_status'] ?? '')),
            'earlier pass ' . $old . ' is superseded');
    }
});

test('refresh on a day with no ticket yet simply generates one', function () {
    $date = fx148_date(16);
    [$repo, , , $service] = ci154_ready($date);
    $result = ci154_run($service, $date, true);
    assert_not_null($result['ticketId'] ?? null, 'a refresh with nothing to replace still generates');
});

test('ticket identity: generation 0 keeps the original day-only id', function () {
    // Existing deployed databases must keep the ids they already stored, so
    // the un-refreshed identity may not change.
    $service = fx148_service(new SportsRepositoryStub(), fx148_audit(), new \AIWorkforce\Sports\Providers\SportsProviderManager());
    $m = (new ReflectionClass($service))->getMethod('dailyTicketId');
    $m->setAccessible(true);

    $date = '2026-09-16';
    $expected = hash('sha256', 'ODDS_PREDICTION|' . $date);
    $legacy = substr($expected, 0, 8) . '-' . substr($expected, 8, 4) . '-5' . substr($expected, 13, 3)
        . '-a' . substr($expected, 17, 3) . '-' . substr($expected, 20, 12);

    assert_equals($legacy, $m->invoke($service, $date), 'the day-only id is unchanged');
    assert_equals($legacy, $m->invoke($service, $date, 0), 'generation 0 is that same id');
    assert_not_equals($legacy, $m->invoke($service, $date, 1), 'generation 1 is a distinct id');
});
