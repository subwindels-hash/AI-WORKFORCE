<?php
/**
 * The 2026-09-15 odds-prediction lock-out:
 *
 *   Odds prediction ticket generation failed: sports repository update on
 *   sports_daily_tickets failed: [1406] Data too long for column
 *   'rejection_summary' at row 1 (SQL: UPDATE sports_daily_tickets SET
 *   date = '2026-09-16', ticket_type = 'ODDS_PREDICTION', ...)
 *
 * Cause: the diagnostics payload persisted with the daily row grows with the
 * run — the stage ledger, up to 100 rejection-audit rows, per-candidate
 * decisions and the per-failure-code run summary (five worked examples per
 * code). On a busy fixture day that JSON passes the 65,535-byte MySQL TEXT
 * ceiling, strict mode rejects the entire UPDATE, and the generation run fails
 * AFTER the ticket itself was already produced and verified.
 *
 * Fix, in two layers, both pinned here:
 *
 *   1. the column is MEDIUMTEXT in the MySQL CREATE statements, and the
 *      request-time SchemaInstaller widens existing databases;
 *   2. DailyTicketService budgets the encoded summary against the SMALLEST
 *      width a deployment can still be on (legacy TEXT), shedding the bulk
 *      collections newest-cost-first and recording exactly what it omitted —
 *      so an un-migrated database also stops failing, and never stores
 *      truncated (undecodable) JSON.
 */

test('the MySQL schema declares rejection_summary as MEDIUMTEXT', function () {
    foreach ([
        APPPATH . 'database/sports_intelligence.mysql.sql',
        FCPATH . 'database/production.sql',
    ] as $file) {
        if (!is_file($file)) continue;
        $sql = (string) file_get_contents($file);
        assert_contains('rejection_summary MEDIUMTEXT', $sql, basename($file) . ' ships the widened column');
    }
});

test('SchemaInstaller widens rejection_summary on existing MySQL databases', function () {
    $statements = [];
    \AIWorkforce\SchemaInstaller::upgrade(function (string $sql) use (&$statements) { $statements[] = $sql; }, 'mysql');
    $joined = implode("\n", $statements);
    assert_contains('MODIFY rejection_summary MEDIUMTEXT', $joined, 'the MySQL upgrade path repairs legacy TEXT columns');

    // SQLite/PostgreSQL store TEXT unbounded; the MySQL-only MODIFY must not
    // leak into those dialects, where it is a syntax error.
    foreach (['sqlite', 'pgsql'] as $dialect) {
        $other = [];
        \AIWorkforce\SchemaInstaller::upgrade(function (string $sql) use (&$other) { $other[] = $sql; }, $dialect);
        assert_not_contains('MODIFY rejection_summary', implode("\n", $other), $dialect . ' never emits a MySQL MODIFY');
    }
});

test('an oversized diagnostics payload is budgeted down to valid, readable JSON', function () {
    $encode = new \ReflectionMethod(\AIWorkforce\Sports\DailyTicketService::class, 'encodeRejectionSummary');
    $encode->setAccessible(true);

    // A realistic worst case: 100 audited rejection rows plus candidate
    // decisions, well past the legacy 64KB TEXT ceiling.
    $rows = [];
    for ($i = 0; $i < 100; $i++) {
        $rows[] = [
            'fixture' => 'Extremely Long Home Team Name FC vs Equally Long Away Team Name United ' . $i,
            'market' => 'MATCH_ODDS', 'selection' => 'HOME', 'provider' => 'provider-with-a-long-identifier',
            'failureCode' => 'LOW_CONFIDENCE', 'failureReason' => str_repeat('detailed rejection narrative ', 20),
            'failedStage' => 'CONFIDENCE_GATE', 'predictedProbability' => 0.4123, 'confidence' => 27.5,
            'minConfidence' => 30, 'dataQuality' => 71.25, 'minDataQuality' => 80,
            'expectedValue' => -0.031, 'minimumRequiredValue' => 0.0, 'fallbackAttempted' => false,
        ];
    }
    $summary = ['LOW_CONFIDENCE' => 100, '_diagnostics' => [
        'stageLedger' => [['stage' => 'DISCOVERY', 'state' => 'COMPLETED']],
        'generationStartedAt' => gmdate('c'), 'generationCompletedAt' => gmdate('c'),
        'durationSeconds' => 12.5, 'actor' => 'system:daily-ticket',
        'eligibleFixtures' => 42, 'fixturesWithFreshOdds' => 40, 'selectedPicks' => 0,
        'rejectionAudit' => ['limit' => 100, 'truncated' => false, 'rows' => $rows],
        'candidateDecisions' => $rows,
        'runSummary' => ['rejectionsByFailureCode' => [['failureCode' => 'LOW_CONFIDENCE', 'count' => 100, 'examples' => array_slice($rows, 0, 5)]]],
    ]];

    $raw = json_encode($summary);
    assert_true(strlen((string) $raw) > 65535, 'the fixture really does exceed the legacy TEXT ceiling');

    $json = $encode->invoke(null, $summary);
    assert_true(strlen($json) <= 65535, 'the encoded payload fits a legacy TEXT column (got ' . strlen($json) . ' bytes)');

    $decoded = json_decode($json, true);
    assert_true(is_array($decoded), 'the stored value is still valid JSON, not a truncated fragment');
    assert_equals(100, (int) ($decoded['LOW_CONFIDENCE'] ?? 0), 'the rejection counts — what the UI reads — survive');
    assert_equals(42, (int) ($decoded['_diagnostics']['eligibleFixtures'] ?? 0), 'the funnel counters survive');
    assert_true(!empty($decoded['_diagnostics']['stageLedger']), 'the stage ledger survives');
    assert_true(!empty($decoded['_diagnostics']['diagnosticsTruncated']), 'the omission is recorded truthfully, never silent');
    assert_true(!empty($decoded['_diagnostics']['diagnosticsTruncated']['omitted']), 'it names what was dropped');
});

test('a normal-sized payload is stored verbatim', function () {
    $encode = new \ReflectionMethod(\AIWorkforce\Sports\DailyTicketService::class, 'encodeRejectionSummary');
    $encode->setAccessible(true);
    $summary = ['LOW_CONFIDENCE' => 2, '_diagnostics' => ['eligibleFixtures' => 5, 'selectedPicks' => 3]];
    $json = $encode->invoke(null, $summary);
    assert_equals(json_encode($summary), $json, 'an in-budget summary is not altered');
    $decoded = json_decode($json, true);
    assert_true(empty($decoded['_diagnostics']['diagnosticsTruncated']), 'no truncation marker on a healthy run');
});

test('a full generation run persists a rejection_summary that fits the column', function () {
    $encode = new \ReflectionMethod(\AIWorkforce\Sports\DailyTicketService::class, 'encodeRejectionSummary');
    $encode->setAccessible(true);
    $src = (string) file_get_contents(APPPATH . 'libraries/AIWorkforce/Sports/DailyTicketService.php');
    assert_contains(
        "'rejection_summary' => self::encodeRejectionSummary(\$storedSummary)",
        $src,
        'the main generation write goes through the size-budgeted encoder'
    );
    assert_false(
        (bool) preg_match("/'rejection_summary'\s*=>\s*json_encode\(\\\$storedSummary\)/", $src),
        'the unbounded json_encode() of the run summary is gone'
    );
});
