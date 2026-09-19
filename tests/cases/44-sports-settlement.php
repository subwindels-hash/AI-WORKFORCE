<?php
use AIWorkforce\Persistence\AuditRepository;
use AIWorkforce\Sports\ResultVerificationEngine;
use AIWorkforce\Sports\TicketSettlementService;

function fx_settle_audit(): AuditRepository
{
    return new class implements AuditRepository { public array $events = []; public function emit(string $t, string $s, array $d = [], string $a = 'system'): void { $this->events[] = ['type' => $t, 'actor' => $a, 'detail' => $d]; } public function recent(int $l = 100): array { return []; } };
}

/** Build a ticket with n selections of the given odds on distinct matches. */
function fx_settle_ticket(SportsRepositoryStub $repo, array $odds, float $stake = 10.0): string
{
    $id = 'tkt_test_' . substr(md5(implode(',', $odds)), 0, 8);
    $repo->saveTicket(['id' => $id, 'created_at' => gmdate('c'), 'configuration_version' => '1', 'total_odds' => round(array_product($odds), 4), 'selection_count' => count($odds), 'combined_probability' => 0.5, 'confidence' => 80.0, 'risk' => 'LOW', 'correlation' => 'LOW', 'data_quality_score' => 90, 'status' => 'APPROVED', 'approval_status' => 'APPROVED_NOT_EXECUTED', 'settlement_status' => 'PENDING', 'stake' => $stake, 'pnl' => null]);
    $matchIds = [];
    foreach ($odds as $i => $o) {
        $matchId = 1000 + $i;
        $repo->matches[] = ['id' => $matchId, 'provider_id' => 1, 'external_id' => 'ext' . $matchId, 'competition' => 'L', 'home_team' => 'H', 'away_team' => 'A', 'kickoff_at' => gmdate('c'), 'status' => 'FINISHED', 'source_timestamp' => gmdate('c'), 'payload' => []];
        $matchIds[] = $matchId;
        $repo->saveTicketSelection(['ticket_id' => $id, 'prediction_id' => 'prd_x', 'match_id' => $matchId, 'market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'odds' => $o, 'odds_timestamp' => gmdate('c'), 'model_probability' => 0.7, 'calibrated_probability' => 0.75, 'expected_value' => 0.1, 'risk' => 'LOW', 'result' => null, 'status' => 'PENDING']);
    }
    return $id;
}

/** Store + verify a result for a match (total goals control the OVER_1.5 outcome). */
function fx_settle_result(SportsRepositoryStub $repo, int $matchId, int $home, int $away): void
{
    $repo->saveResult($matchId, 1, ['homeScore' => $home, 'awayScore' => $away, 'status' => 'FINISHED', 'sourceTimestamp' => gmdate('c'), 'payload' => ['externalId' => 'x', 'status' => 'FINISHED']]);
    $result = $repo->findResult($matchId, 1);
    $repo->verifyResult((int) $result['id']);
}

test('settlement: 4 WON + 1 LOST settles the ticket LOST with full stake loss', function () {
    $repo = new SportsRepositoryStub();
    $audit = fx_settle_audit();
    $svc = new TicketSettlementService($repo, new ResultVerificationEngine(), $audit);
    $id = fx_settle_ticket($repo, [1.5, 1.5, 1.5, 1.5, 1.5], 10.0);
    // matches 1000..1004 → goals: (2,0),(2,0),(1,1),(3,0) WIN (≥2 total); (0,0) LOSE
    $scores = [[2, 0], [2, 0], [1, 1], [3, 0], [0, 0]];
    foreach ($scores as $i => [$h, $a]) fx_settle_result($repo, 1000 + $i, $h, $a);
    $out = $svc->settlePending($id);
    assert_equals('LOST', $out['status']);
    assert_equals(-10.0, (float) $repo->findTicket($id)['pnl']);
});

test('settlement: all selections won settles WON with stake-based profit', function () {
    $repo = new SportsRepositoryStub();
    $audit = fx_settle_audit();
    $svc = new TicketSettlementService($repo, new ResultVerificationEngine(), $audit);
    $odds = [1.5, 2.0, 1.6];
    $id = fx_settle_ticket($repo, $odds, 20.0);
    // every selection must actually win OVER 1.5 (≥2 total goals)
    foreach ([[2, 0], [1, 1], [3, 0]] as $i => [$h, $a]) fx_settle_result($repo, 1000 + $i, $h, $a);
    $out = $svc->settlePending($id);
    assert_equals('WON', $out['status']);
    $effective = 1.5 * 2.0 * 1.6;
    assert_close(20.0 * ($effective - 1), (float) $repo->findTicket($id)['pnl'], 0.001);
    assert_close($effective, (float) $repo->findTicket($id)['total_odds'], 0.001);
});

test('settlement: RESTITUTE_ODDS refunds a void selection at reduced odds', function () {
    $repo = new SportsRepositoryStub();
    $audit = fx_settle_audit();
    $svc = new TicketSettlementService($repo, new ResultVerificationEngine(), $audit);
    $odds = [1.6, 2.0, 1.8];
    $id = fx_settle_ticket($repo, $odds, 10.0);
    fx_settle_result($repo, 1000, 2, 0);          // WON
    $repo->saveResult(1001, 1, ['homeScore' => null, 'awayScore' => null, 'status' => 'VOID', 'sourceTimestamp' => gmdate('c'), 'payload' => ['externalId' => 'x', 'status' => 'VOID']]);
    $r = $repo->findResult(1001, 1); $repo->verifyResult((int) $r['id']);
    fx_settle_result($repo, 1002, 1, 1);          // WON (2 goals > 1)
    $out = $svc->settlePending($id);
    assert_equals('WON', $out['status'], 'void is refunded, not a loss');
    assert_close(1.6 * 1.8, (float) $repo->findTicket($id)['total_odds'], 0.001);
    assert_close(10.0 * (1.6 * 1.8 - 1), (float) $repo->findTicket($id)['pnl'], 0.001);
});

test('settlement: all-void ticket settles VOID with zero P/L', function () {
    $repo = new SportsRepositoryStub();
    $audit = fx_settle_audit();
    $svc = new TicketSettlementService($repo, new ResultVerificationEngine(), $audit);
    $id = fx_settle_ticket($repo, [1.6, 2.0], 10.0);
    foreach ([0, 1] as $i) {
        $repo->saveResult(1000 + $i, 1, ['homeScore' => null, 'awayScore' => null, 'status' => 'VOID', 'sourceTimestamp' => gmdate('c'), 'payload' => ['externalId' => 'x', 'status' => 'VOID']]);
        $r = $repo->findResult(1000 + $i, 1); $repo->verifyResult((int) $r['id']);
    }
    $out = $svc->settlePending($id);
    assert_equals('VOID', $out['status']);
    assert_equals(0.0, (float) $repo->findTicket($id)['pnl']);
});

test('settlement: unverified or missing results keep the ticket PENDING', function () {
    // Pin the corroboration window so this test cannot depend on another
    // test's environment: a FRESH unverified result must not settle.
    putenv('WINDELS_SPORTS_RESULT_CORROBORATION_SECONDS=600');
    $repo = new SportsRepositoryStub();
    $audit = fx_settle_audit();
    $svc = new TicketSettlementService($repo, new ResultVerificationEngine(), $audit);
    $id = fx_settle_ticket($repo, [1.5, 1.5], 10.0);
    // one verified, one missing
    fx_settle_result($repo, 1000, 2, 0);
    $out = $svc->settlePending($id);
    assert_equals('PENDING', $out['status']);
    assert_equals('PENDING', $repo->findTicket($id)['settlement_status']);
    // unverified result must NOT settle
    $repo2 = new SportsRepositoryStub();
    $audit2 = fx_settle_audit();
    $svc2 = new TicketSettlementService($repo2, new ResultVerificationEngine(), $audit2);
    $id2 = fx_settle_ticket($repo2, [1.5, 1.5], 10.0);
    $repo2->saveResult(1000, 1, ['homeScore' => 2, 'awayScore' => 0, 'status' => 'FINISHED', 'sourceTimestamp' => gmdate('c'), 'payload' => []]);
    $repo2->saveResult(1001, 1, ['homeScore' => 1, 'awayScore' => 0, 'status' => 'FINISHED', 'sourceTimestamp' => gmdate('c'), 'payload' => []]);
    $out2 = $svc2->settlePending($id2);
    assert_equals('PENDING', $out2['status'], 'unverified results must not settle');
    assert_equals(0, count(array_filter($audit2->events, fn($e) => $e['type'] === 'SPORTS_RESULT_VERIFIED')),
        'a fresh unverified result is not promoted, so nothing was audited as verified');
});

test('settlement: a corroborated unverified result earns verification and settles', function () {
    // The workflow gap this fixes: results sync stores verified=0, and without
    // a promotion path nothing can ever settle — the measured-results panel
    // then stays 0/— forever even though real final results are stored.
    putenv('WINDELS_SPORTS_RESULT_CORROBORATION_SECONDS=600');
    $repo = new SportsRepositoryStub();
    $audit = fx_settle_audit();
    $svc = new TicketSettlementService($repo, new ResultVerificationEngine(), $audit);
    $id = fx_settle_ticket($repo, [1.5, 1.5], 10.0);
    // Both results final and stamped an hour ago — corroborated, but unverified.
    $old = gmdate('c', time() - 3600);
    $repo->saveResult(1000, 1, ['homeScore' => 2, 'awayScore' => 0, 'status' => 'FINISHED', 'sourceTimestamp' => $old, 'payload' => []]);
    $repo->saveResult(1001, 1, ['homeScore' => 1, 'awayScore' => 1, 'status' => 'FINISHED', 'sourceTimestamp' => $old, 'payload' => []]);
    $out = $svc->settlePending($id, 'user-42');
    assert_equals('WON', $out['status'], 'both OVER_1_5 legs won after promotion');
    assert_equals(2.25, (float) $repo->findTicket($id)['total_odds']);
    $verified = array_values(array_filter($audit->events, fn($e) => $e['type'] === 'SPORTS_RESULT_VERIFIED'));
    assert_equals(2, count($verified), 'each corroborated result audited as verified on promotion');
    foreach ($verified as $e) {
        assert_equals('user-42', $e['actor'] ?? null, 'promotion is audited under the acting identity');
        assert_true(!empty($e['detail']['automation']), 'the audit says the promotion was automatic');
    }
    assert_equals(1, (int) $repo->findResult(1000, 1)['verified'], 'the stored row is now verified');
});

test('settlement: corroboration window 0 promotes a terminal result on first sight', function () {
    putenv('WINDELS_SPORTS_RESULT_CORROBORATION_SECONDS=0');
    $repo = new SportsRepositoryStub();
    $audit = fx_settle_audit();
    $svc = new TicketSettlementService($repo, new ResultVerificationEngine(), $audit);
    $id = fx_settle_ticket($repo, [1.5], 10.0);
    $repo->saveResult(1000, 1, ['homeScore' => 0, 'awayScore' => 0, 'status' => 'FINISHED', 'sourceTimestamp' => gmdate('c'), 'payload' => []]);
    $out = $svc->settlePending($id);
    assert_equals('LOST', $out['status'], 'with no wait, a terminal result settles immediately');
    putenv('WINDELS_SPORTS_RESULT_CORROBORATION_SECONDS=600');
});

test('settlement: a non-terminal stored result is never promoted', function () {
    putenv('WINDELS_SPORTS_RESULT_CORROBORATION_SECONDS=0');
    $repo = new SportsRepositoryStub();
    $audit = fx_settle_audit();
    $svc = new TicketSettlementService($repo, new ResultVerificationEngine(), $audit);
    $id = fx_settle_ticket($repo, [1.5], 10.0);
    // SUSPENDED is stored by the normalizer but is not terminal for the
    // verification engine — it must never be promoted or settle anything.
    $old = gmdate('c', time() - 7200);
    $repo->saveResult(1000, 1, ['homeScore' => null, 'awayScore' => null, 'status' => 'SUSPENDED', 'sourceTimestamp' => $old, 'payload' => []]);
    $out = $svc->settlePending($id);
    assert_equals('PENDING', $out['status'], 'a suspended match honestly stays pending');
    assert_equals(0, count(array_filter($audit->events, fn($e) => $e['type'] === 'SPORTS_RESULT_VERIFIED')));
    putenv('WINDELS_SPORTS_RESULT_CORROBORATION_SECONDS=600');
});

test('settlement: an identical re-sync keeps an earned verification, a correction resets it', function () {
    $repo = new SportsRepositoryStub();
    $old = gmdate('c', time() - 3600);
    $repo->saveResult(1000, 1, ['homeScore' => 2, 'awayScore' => 1, 'status' => 'FINISHED', 'sourceTimestamp' => $old, 'payload' => []]);
    $row = $repo->findResult(1000, 1);
    $repo->verifyResult((int) $row['id']);
    // The nightly results job re-observes the same final score: the same
    // observation must not wipe the verification.
    $repo->saveResult(1000, 1, ['homeScore' => 2, 'awayScore' => 1, 'status' => 'FINISHED', 'sourceTimestamp' => gmdate('c'), 'payload' => []]);
    assert_equals(1, (int) $repo->findResult(1000, 1)['verified'], 'an identical re-observation keeps the earned verification');
    // A score correction is a NEW claim: it resets and must re-verify.
    $repo->saveResult(1000, 1, ['homeScore' => 2, 'awayScore' => 2, 'status' => 'FINISHED', 'sourceTimestamp' => gmdate('c'), 'payload' => []]);
    assert_equals(0, (int) $repo->findResult(1000, 1)['verified'], 'a changed result resets to unverified');
    // ...and null vs 0 are different claims (a VOID result carries no score).
    $repo->saveResult(1000, 1, ['homeScore' => null, 'awayScore' => null, 'status' => 'VOID', 'sourceTimestamp' => gmdate('c'), 'payload' => []]);
    assert_equals(0, (int) $repo->findResult(1000, 1)['verified'], 'a status change to VOID also resets');
});

test('settlement: settleAllPending attributes promotions to the acting identity', function () {
    putenv('WINDELS_SPORTS_RESULT_CORROBORATION_SECONDS=600');
    $repo = new SportsRepositoryStub();
    $audit = fx_settle_audit();
    $svc = new TicketSettlementService($repo, new ResultVerificationEngine(), $audit);
    $id = fx_settle_ticket($repo, [1.5], 10.0);
    $old = gmdate('c', time() - 3600);
    $repo->saveResult(1000, 1, ['homeScore' => 2, 'awayScore' => 0, 'status' => 'FINISHED', 'sourceTimestamp' => $old, 'payload' => []]);
    $out = $svc->settleAllPending(200, 'admin-7');
    assert_equals('COMPLETED', $out['status']);
    assert_equals(1, $out['settled'], 'the pending ticket settled from the corroborated result');
    $verified = array_values(array_filter($audit->events, fn($e) => $e['type'] === 'SPORTS_RESULT_VERIFIED'));
    assert_equals(1, count($verified));
    assert_equals('admin-7', $verified[0]['actor'] ?? null, 'promotion audited under the operator identity');
});

test('settlement refuses to apply a result that is not verified', function () {
    $repo = new SportsRepositoryStub();
    $audit = fx_settle_audit();
    $svc = new TicketSettlementService($repo, new ResultVerificationEngine(), $audit);
    $id = fx_settle_ticket($repo, [1.5], 10.0);
    $out = $svc->applyVerifiedResult($id, 1000, ['verified' => false, 'status' => 'FINISHED', 'homeScore' => 2, 'awayScore' => 0]);
    assert_equals('PENDING', $out['status']);
    assert_equals('RESULT_UNVERIFIED', $out['reason']);
});
