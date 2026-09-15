<?php
/**
 * END-TO-END REPRODUCTION of the 2026-09-15 lock-out, against the REAL engine.
 *
 *   Odds prediction ticket generation failed: sports repository update on
 *   sports_daily_tickets failed: [1406] Data too long for column
 *   'rejection_summary' at row 1
 *
 * Case 149 unit-tests the encoder in isolation. This case proves the thing
 * that actually matters: a real DailyTicketService::runDaily() over a busy
 * fixture day, persisting through a repository that enforces MySQL's TEXT
 * ceiling exactly as strict mode does, no longer fails the run.
 *
 * The stub below is the ONLY simulated part, and it simulates the database
 * constraint faithfully: MySQL measures TEXT in BYTES (65,535) and, in strict
 * mode, raises errno 1406 rather than truncating. Everything upstream of it —
 * discovery, odds, prediction, the rejection audit, the diagnostics payload —
 * is the production code path.
 */

use AIWorkforce\Sports\DailyTicketService;

// This case reuses the odds-generation harness (case 148) so it drives the
// exact same real engine wiring. Under a filtered run that file may not have
// loaded, so require it explicitly — every helper there is guarded/idempotent.
require_once TESTSPATH . 'cases/148-odds-generation-workflow.php';

/** MySQL TEXT: 65,535 bytes, strict mode → [1406], never a silent truncation. */
const CI150_TEXT_LIMIT = 65535;

/**
 * A repository that enforces the legacy TEXT width on rejection_summary the
 * way MySQL strict mode does. Wraps the ordinary harness stub so the whole
 * rest of the engine behaves normally.
 */
final class Ci150StrictColumnRepo extends SportsRepositoryStub
{
    public int $limit = CI150_TEXT_LIMIT;
    public array $written = [];

    private function enforce(array $row): void
    {
        if (!array_key_exists('rejection_summary', $row)) return;
        $value = $row['rejection_summary'];
        // An array never reaches a real driver as a value; that was bug #148.
        if (is_array($value)) $value = json_encode($value);
        $bytes = strlen((string) $value);
        $this->written[] = $bytes;
        if ($bytes > $this->limit) {
            throw new \RuntimeException(
                'sports repository update on sports_daily_tickets failed: [1406] '
                . "Data too long for column 'rejection_summary' at row 1"
            );
        }
    }

    public function saveDailyTicket(array $d): void { $this->enforce($d); parent::saveDailyTicket($d); }
    public function updateDailyTicket(string $date, array $patch): void { $this->enforce($patch); parent::updateDailyTicket($date, $patch); }
}

/**
 * A deliberately punishing day: many fixtures, each with a full market board.
 * This is what generates the large rejection audit + diagnostics payload that
 * overflowed the column in production.
 */
function ci150_busy_day(string $date, int $fixtures = 40): array
{
    $out = [];
    for ($i = 0; $i < $fixtures; $i++) {
        $out[] = fx148_fixture(
            'ci150-' . $i,
            'Extremely Long Home Team Name Football Club ' . $i,
            'Equally Long Away Team Name Athletic United ' . $i,
            'A Competition With A Very Long Descriptive Name, Division ' . ($i % 7),
            fx148_kickoff($date, $i % 6)
        );
    }
    return $out;
}

/** Wire the busy day to the real service through the strict-column repo. */
function ci150_ready(string $date): array
{
    $repo = new Ci150StrictColumnRepo();
    $audit = fx148_audit();
    $fixtures = ci150_busy_day($date);
    $providers = new \AIWorkforce\Sports\Providers\SportsProviderManager();
    $providers->register(fx148_provider('apifootball', $fixtures));
    fx148_approve_calibration($repo);
    // Odds priced so many candidates are evaluated and then hard-rejected,
    // which is exactly what fills the rejection audit with rows.
    fx148_seed($repo, 'apifootball', $fixtures, 600);
    return [$repo, $audit, $providers, fx148_service($repo, $audit, $providers)];
}

test('REPRO: the pre-fix payload really did exceed the MySQL TEXT column', function () {
    // Reconstruct the pre-fix behaviour: the raw json_encode() of the same
    // diagnostics structure the engine builds on a busy day. This pins that
    // the bug was real and that the fixture is representative, so the test
    // below is not proving something vacuous.
    $rows = [];
    for ($i = 0; $i < 100; $i++) {
        $rows[] = [
            'fixture' => 'Extremely Long Home Team Name Football Club ' . $i . ' vs Equally Long Away Team Name Athletic United ' . $i,
            'market' => 'MATCH_RESULT', 'selection' => 'HOME', 'provider' => 'apifootball',
            'failureCode' => 'LOW_CONFIDENCE',
            'failureReason' => 'measured confidence 27.50% is below the 30% floor required at data-quality tier GOOD',
            'failedStage' => 'CONFIDENCE_GATE', 'predictedProbability' => 0.4123, 'confidence' => 27.5,
            'minConfidence' => 30, 'dataQuality' => 71.25, 'minDataQuality' => 80,
            'expectedValue' => -0.031, 'minimumRequiredValue' => 0.0, 'fallbackAttempted' => false,
        ];
    }
    $prefix = ['_diagnostics' => [
        'rejectionAudit' => ['limit' => 100, 'truncated' => false, 'rows' => $rows],
        'candidateDecisions' => $rows,
        'runSummary' => ['rejectionsByFailureCode' => [['failureCode' => 'LOW_CONFIDENCE', 'count' => 100, 'examples' => array_slice($rows, 0, 5)]]],
    ]];
    assert_true(
        strlen((string) json_encode($prefix)) > CI150_TEXT_LIMIT,
        'the unbounded payload overflows a legacy TEXT column — the bug being fixed'
    );
});

test('a busy generation run completes without the [1406] column overflow', function () {
    $date = fx148_date(5);
    [$repo, $audit, $providers, $service] = ci150_ready($date);

    // Before the fix this threw the [1406] RuntimeException out of runDaily().
    $result = $service->runDaily($date, null, ['actor' => 'admin-ci150']);

    assert_true(is_array($result), 'the run returned a contract instead of throwing');
    assert_true($repo->written !== [], 'the daily row was actually persisted at least once');
    foreach ($repo->written as $bytes) {
        assert_true($bytes <= CI150_TEXT_LIMIT, 'every rejection_summary write fits the column (got ' . $bytes . ' bytes)');
    }

    // The run must not have been quietly downgraded into an error state by the
    // persistence layer — the whole point is that diagnostics never break it.
    $message = (string) ($result['message'] ?? '');
    assert_not_contains('1406', $message, 'no column-overflow error leaked into the run message');
    assert_not_contains('Data too long', $message, 'no column-overflow error leaked into the run message');
});

test('the persisted summary is still valid, readable JSON after a busy run', function () {
    $date = fx148_date(6);
    [$repo, $audit, $providers, $service] = ci150_ready($date);
    $service->runDaily($date, null, ['actor' => 'admin-ci150']);

    $daily = $repo->findDailyTicket($date);
    assert_not_null($daily, 'the daily row exists');

    // findDailyTicket() decodes the column; a truncated fragment would have
    // decoded to null/empty here rather than a structure.
    $summary = $daily['rejection_summary'];
    if (is_string($summary)) $summary = json_decode($summary, true);
    assert_true(is_array($summary), 'the stored summary decodes to a structure, not a truncated fragment');

    // The operator-facing diagnostics that the /sports funnel panel reads must
    // survive the budgeting, whether or not anything had to be shed.
    $diagnostics = $summary['_diagnostics'] ?? [];
    assert_true(is_array($diagnostics), 'the diagnostics block survives');
    assert_true(array_key_exists('stageLedger', $diagnostics), 'the stage ledger survives — the panel renders from it');
    assert_true(array_key_exists('generationCompletedAt', $diagnostics), 'run timing survives');
});

test('an un-migrated database (legacy TEXT) and a migrated one both succeed', function () {
    // MEDIUMTEXT install: nothing should be shed at all on a normal day.
    $date = fx148_date(7);
    [$repo, , , $service] = ci150_ready($date);
    $repo->limit = 16777215; // MEDIUMTEXT
    $service->runDaily($date, null, ['actor' => 'admin-mediumtext']);
    assert_true($repo->written !== [], 'the MEDIUMTEXT install persisted the row');

    // Legacy TEXT install that has not yet run the SchemaInstaller upgrade.
    $date2 = fx148_date(8);
    [$repo2, , , $service2] = ci150_ready($date2);
    $repo2->limit = CI150_TEXT_LIMIT;
    $service2->runDaily($date2, null, ['actor' => 'admin-legacy-text']);
    foreach ($repo2->written as $bytes) {
        assert_true($bytes <= CI150_TEXT_LIMIT, 'the legacy TEXT install also stays inside its column');
    }
});
