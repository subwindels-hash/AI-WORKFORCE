<?php
/**
 * The 2026-09-11 odds-prediction lock-out:
 *
 *   sports repository update on sports_daily_tickets failed:
 *   [1054] Unknown column 'Array' in 'SELECT' (SQL: UPDATE
 *   `sports_daily_tickets` SET `date` = '2026-09-11', ... )
 *
 * Cause: DailyTicketService::recoverUnlinkedDailyTicket() handed
 * saveDailyTicket() a decoded ARRAY for rejection_summary while every other
 * write path encodes it. CodeIgniter's query builder interpolates that array
 * with PHP array-to-string conversion, so the statement contained the bare
 * token `Array`, which MySQL resolves as an identifier — [1054] Unknown
 * column 'Array'. SQLite/TEXT hides nothing here either: the value is the
 * literal string "Array", i.e. the diagnostics payload is silently destroyed.
 *
 * These cases pin, against the REAL (throwaway sqlite) repository:
 *
 *   1. no daily-ticket write path can ever emit an array into SQL — the
 *      repository encodes rejection_summary on INSERT, UPDATE and patch;
 *   2. the round-trip returns a decoded structure, never the string "Array";
 *   3. the recovery path in DailyTicketService encodes its payload at source.
 */

function ci148_repo(): \AIWorkforce\Persistence\SportsRepository { return platform()->model->sports; }

function ci148_reset(string $date): void
{
    ci()->db->where('date', $date)->delete('sports_daily_tickets');
}

test('saveDailyTicket accepts a decoded rejection_summary array without emitting Array into SQL', function () {
    $date = '2026-09-11';
    ci148_reset($date);
    $repo = ci148_repo();

    $repo->saveDailyTicket([
        'date' => $date, 'ticket_type' => 'ODDS_PREDICTION', 'ticket_id' => null,
        'status' => 'PENDING_USER_APPROVAL', 'generation_status' => 'GENERATED',
        'configuration_version' => 1, 'candidates_evaluated' => 0,
        'predictions_recorded' => 0, 'rejections' => 0,
        // The exact shape recoverUnlinkedDailyTicket() used to pass.
        'rejection_summary' => ['_diagnostics' => ['recoveredAfterInterruptedLink' => true]],
        'message' => 'case 148 insert', 'provider' => null, 'run_id' => null,
        'attempt_count' => 0, 'next_retry_at' => null, 'last_error_code' => null,
        'generated_at' => gmdate('c'), 'created_at' => gmdate('c'), 'updated_at' => gmdate('c'),
    ]);

    $raw = ci()->db->get_where('sports_daily_tickets', ['date' => $date], 1)->row_array();
    assert_not_null($raw, 'the row exists — the write was not swallowed');
    assert_not_equals('Array', (string) $raw['rejection_summary'], 'the array was never stringified into the statement');
    $decoded = json_decode((string) $raw['rejection_summary'], true);
    assert_true(is_array($decoded), 'rejection_summary is stored as JSON text');
    assert_true(!empty($decoded['_diagnostics']['recoveredAfterInterruptedLink']), 'the diagnostics payload survived the write');

    $read = $repo->findDailyTicket($date);
    assert_true(is_array($read['rejection_summary']), 'the read-back decodes to a structure');
    assert_true(!empty($read['rejection_summary']['_diagnostics']['recoveredAfterInterruptedLink']), 'round-trip preserves diagnostics');
});

test('the UPDATE path of saveDailyTicket also encodes an array rejection_summary', function () {
    $date = '2026-09-11';
    $repo = ci148_repo();
    // Row already exists from the case above → this is the UPDATE branch,
    // the exact statement that produced [1054] Unknown column 'Array'.
    $repo->saveDailyTicket([
        'date' => $date, 'ticket_type' => 'ODDS_PREDICTION',
        'ticket_id' => 'd7ba3b2a-ed76-5b2c-ae4b-c6264d0bad1a',
        'status' => 'PENDING_USER_APPROVAL', 'generation_status' => 'GENERATED',
        'configuration_version' => 1, 'candidates_evaluated' => 3,
        'predictions_recorded' => 3, 'rejections' => 0,
        'rejection_summary' => ['_diagnostics' => ['marketsEvaluated' => 7]],
        'message' => 'case 148 update', 'provider' => 'ci148', 'run_id' => 'run-148',
        'attempt_count' => 1, 'next_retry_at' => null, 'last_error_code' => null,
        'generated_at' => gmdate('c'), 'created_at' => gmdate('c'), 'updated_at' => gmdate('c'),
    ]);

    $raw = ci()->db->get_where('sports_daily_tickets', ['date' => $date], 1)->row_array();
    assert_equals('d7ba3b2a-ed76-5b2c-ae4b-c6264d0bad1a', (string) $raw['ticket_id'], 'the UPDATE committed');
    assert_not_equals('Array', (string) $raw['rejection_summary'], 'UPDATE never stringifies the array');
    assert_equals(7, (int) json_decode((string) $raw['rejection_summary'], true)['_diagnostics']['marketsEvaluated']);
});

test('updateDailyTicket patches encode an array rejection_summary', function () {
    $date = '2026-09-11';
    ci148_repo()->updateDailyTicket($date, [
        'message' => 'case 148 patch',
        'rejection_summary' => ['_diagnostics' => ['patched' => true]],
    ]);
    $raw = ci()->db->get_where('sports_daily_tickets', ['date' => $date], 1)->row_array();
    assert_not_equals('Array', (string) $raw['rejection_summary'], 'patch never stringifies the array');
    assert_true(!empty(json_decode((string) $raw['rejection_summary'], true)['_diagnostics']['patched']));
    ci148_reset($date);
});

test('the daily-ticket recovery path encodes its rejection_summary at source', function () {
    $src = file_get_contents(APPPATH . 'libraries/AIWorkforce/Sports/DailyTicketService.php');
    assert_true(
        (bool) preg_match("/recoveredAfterInterruptedLink/", (string) $src),
        'the recovery payload still exists'
    );
    assert_false(
        (bool) preg_match("/'rejection_summary'\s*=>\s*\[/", (string) $src),
        'no daily-ticket write passes a raw array literal for rejection_summary'
    );
});
