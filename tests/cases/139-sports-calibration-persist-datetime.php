<?php
/**
 * The 2026-09-10 CALIBRATION_PERSIST_FAILED lock-out, second cause:
 *
 * The method column had already been widened and the marker shortened
 * (cases 133/136/138) — SQLite was green and STILL the production MySQL
 * engine created its identity bootstrap row that never survived. The
 * remaining, SQLite-invisible cause was the temporal LITERAL: the Phase-3
 * tables declare DATETIME columns (older sports tables use VARCHAR(32)),
 * the app writes gmdate('c') ('2026-09-10T19:10:08+00:00'), and the
 * production MySQL connection runs in STRICT mode (config 'stricton' =>
 * true). Strict MySQL/MariaDB rejects that literal for a DATETIME column
 * (error 1292 "Incorrect datetime value"); with db_debug=false the failed
 * INSERT was swallowed, the row never existed, and the engine stayed
 * MODEL_NOT_CALIBRATED.
 *
 * These cases pin, on the REAL (throwaway sqlite) repository:
 *
 *   1. the bootstrap row stores a MySQL-DATETIME-compatible canonical
 *      literal and round-trips the SAME record (insert → read-back →
 *      approve → read-back), including the auto-approved identity row the
 *      daily engine reads through activeCalibration();
 *   2. a failed repository write surfaces as an exception carrying the
 *      driver's REAL error/table/statement rather than a phantom id;
 *   3. CalibrationBootstrap and the daily engine propagate that exact
 *      database error (CALIBRATION_PERSIST_FAILED + dbError, message),
 *      including a swallowed APPROVE update;
 *   4. an 11-fixture cold start (the production shape: 11 fresh-odds
 *      fixtures) moves every fixture THROUGH the sufficient-data gate to
 *      a generated prediction, with zero MODEL_NOT_CALIBRATED;
 *   5. the sufficient-data gate is no longer a black box: each fresh-odds
 *      fixture has an explicit row naming the requirement it failed and
 *      the concrete missing field (recentForm).
 *
 * The strict-MySQL literal rejection itself is additionally proven against
 * a real MariaDB engine by runtime/verify-calibration-mysql.mjs (SQLite
 * TEXT accepts anything and cannot enforce it).
 */
use AIWorkforce\Persistence\AuditRepository;
use AIWorkforce\Persistence\SportsRepository;
use AIWorkforce\Sports\CalibrationBootstrap;
use AIWorkforce\Sports\ConfidenceEngine;
use AIWorkforce\Sports\ConfigurationService;
use AIWorkforce\Sports\CorrelationEngine;
use AIWorkforce\Sports\DailyTicketService;
use AIWorkforce\Sports\DataQualityEngine;
use AIWorkforce\Sports\DecisionRecorder;
use AIWorkforce\Sports\FeatureEngineeringEngine;
use AIWorkforce\Sports\MatchIntelligenceEngine;
use AIWorkforce\Sports\OddsFreshnessEngine;
use AIWorkforce\Sports\PredictionEngine;
use AIWorkforce\Sports\PredictionPipeline;
use AIWorkforce\Sports\Providers\SportsDataProvider;
use AIWorkforce\Sports\Providers\SportsProviderManager;
use AIWorkforce\Sports\RiskEngine;
use AIWorkforce\Sports\TicketGovernance;
use AIWorkforce\Sports\TicketOptimizer;
use AIWorkforce\Sports\ValueEngine;

const CG139_PROVIDER = 'cg139-sim';

function cg139_repo(): SportsRepository
{
    return platform()->model->sports;
}

function cg139_audit(): AuditRepository
{
    return platform()->model->audit;
}

function cg139_model_id(SportsRepository $repo): int
{
    return $repo->ensureModelVersion([
        'modelName' => PredictionEngine::MODEL_NAME,
        'modelVersion' => PredictionEngine::MODEL_VERSION,
        'featureVersion' => FeatureEngineeringEngine::VERSION,
    ]);
}

/** Remove every row previous cases/this case could have written. */
function cg139_reset(): void
{
    $db = ci()->db;
    $repo = cg139_repo();
    $providerId = (int) $repo->ensureProvider(CG139_PROVIDER, CG139_PROVIDER)['id'];
    $matchIds = array_column($db->get_where('sports_matches', ['provider_id' => $providerId])->result_array(), 'id');
    if ($matchIds !== []) {
        $db->where_in('match_id', $matchIds)->delete('sports_odds');
        $db->where_in('match_id', $matchIds)->delete('sports_data_quality_assessments');
        $predIds = array_column($db->where_in('match_id', $matchIds)->get('sports_predictions')->result_array(), 'id');
        if ($predIds !== []) {
            $ticketIds = array_values(array_unique(array_column($db->where_in('prediction_id', $predIds)->get('sports_ticket_selections')->result_array(), 'ticket_id')));
            if ($ticketIds !== []) {
                $db->where_in('ticket_id', $ticketIds)->delete('sports_ticket_selections');
                $db->where_in('id', $ticketIds)->delete('sports_tickets');
            }
            $db->where_in('id', $predIds)->delete('sports_predictions');
        }
        $db->where_in('match_id', $matchIds)->delete('sports_results');
        $db->where_in('id', $matchIds)->delete('sports_matches');
    }
    $db->delete('sports_calibrations', ['model_version_id' => cg139_model_id($repo)]);
    $db->where('provider', CG139_PROVIDER)->delete('sports_daily_tickets');
    $db->like('execution_key', 'daily-ticket:139', 'after')->delete('sports_job_runs');
}

/**
 * Deterministic provider: $count fixtures tomorrow, each with verified
 * recentForm (unless $withForm is false) and one fresh OVER_1_5 quote.
 */
function cg139_provider(int $count = 3, bool $withForm = true): SportsDataProvider
{
    $fixtures = [];
    for ($i = 0; $i < $count; $i++) {
        $f = [
            'externalId' => 'cg139-' . $i,
            'homeTeam' => 'Gate Home ' . $i, 'awayTeam' => 'Gate Away ' . $i,
            'competition' => 'Roundtrip League',
            'kickoff' => gmdate('Y-m-d\TH:i:00\+00:00', strtotime('+1 day ' . (12 + ($i % 8)) . ':00:00')),
            'status' => 'SCHEDULED',
            'context' => [],
        ];
        if ($withForm) {
            $f['context']['recentForm'] = [
                'homeGoalsPerMatch' => 1.7, 'awayGoalsPerMatch' => 1.3,
                'homeConcededPerMatch' => 1.0, 'awayConcededPerMatch' => 1.1,
                'source' => 'cg139-verified', 'timestamp' => gmdate('c'),
            ];
        }
        $fixtures[] = $f;
    }
    return new class($fixtures) implements SportsDataProvider {
        public function __construct(private array $fixtures) {}
        public function id(): string { return CG139_PROVIDER; }
        public function health(): array { return ['status' => 'ONLINE', 'reliability' => 0.9]; }
        public function fixtures(array $q): array { return $this->fixtures; }
        public function odds(string $e): array
        {
            return [['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 1.9, 'observedAt' => gmdate('c')]];
        }
        public function results(string $e): array { return []; }
    };
}

function cg139_service(SportsDataProvider $provider): DailyTicketService
{
    $repo = cg139_repo();
    $audit = cg139_audit();
    $providers = new SportsProviderManager();
    $providers->register($provider);
    $pipeline = new PredictionPipeline(new MatchIntelligenceEngine(new OddsFreshnessEngine()), new FeatureEngineeringEngine(), new PredictionEngine(), new ValueEngine(), new RiskEngine(), new CorrelationEngine(), new ConfidenceEngine());
    return new DailyTicketService(
        $repo, $audit, $providers, new ConfigurationService($repo, $audit), new DataQualityEngine(),
        $pipeline, new TicketOptimizer(new CorrelationEngine()), new TicketGovernance($repo, $audit, new CorrelationEngine()),
        new DecisionRecorder($repo, $audit)
    );
}

/** In-memory audit that remembers its events (the framework stub lacks one). */
function cg139_memory_audit(): AuditRepository
{
    return new class implements AuditRepository {
        public array $events = [];
        public function emit(string $t, string $s, array $d = [], string $a = 'system'): void { $this->events[] = ['type' => $t, 'summary' => $s, 'detail' => $d, 'actor' => $a]; }
        public function recent(int $l = 100): array { return $this->events; }
    };
}

/** Repository whose calibration INSERT fails exactly like a rejected MySQL write. */
class Cg139FailingInsertRepo extends SportsRepositoryStub
{
    public const MARKER = 'DRIVER-MARKER-INSERT-42';
    public function saveCalibration(array $c): int
    {
        throw new \RuntimeException('sports repository insert on sports_calibrations failed: [1292] Incorrect datetime value: ' . self::MARKER);
    }
}

/** Repository whose calibration APPROVE UPDATE fails. */
class Cg139FailingApproveRepo extends SportsRepositoryStub
{
    public const MARKER = 'DRIVER-MARKER-APPROVE-77';
    public function updateCalibrationStatus(int $id, string $status, ?string $actor = null): void
    {
        throw new \RuntimeException('sports repository update on sports_calibrations failed: [1292] Incorrect datetime value: ' . self::MARKER);
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// 1. The persisted timestamp is MySQL-DATETIME-compatible and round-trips
// ═══════════════════════════════════════════════════════════════════════════

test('real DB: bootstrap row stores a canonical DATETIME literal and survives insert → read-back → approve on the same record', function () {
    cg139_reset();
    $repo = cg139_repo();

    $result = (new CalibrationBootstrap($repo, cg139_audit()))->bootstrapIdentity('system:daily-ticket');
    assert_true(!empty($result['ok']), 'bootstrap persists: ' . json_encode($result));
    $id = (int) $result['calibrationId'];

    // Read back THE SAME RECORD the insert reported.
    $stored = $repo->findCalibration($id);
    assert_true($stored !== null, 'the inserted id resolves to a row');
    assert_equals('identity', (string) $stored['method']);
    assert_equals('PENDING', strtoupper((string) $stored['status']));
    // gmdate('c') ('...T...+00:00') is what strict MySQL rejects; the stored
    // value must be the canonical 'Y-m-d H:i:s' literal every driver accepts.
    assert_true(
        (bool) preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $stored['created_at']),
        'created_at is a MySQL-DATETIME-compatible literal, got: ' . var_export($stored['created_at'] ?? null, true)
    );

    // The approve UPDATE round-trips on the SAME id too.
    $repo->updateCalibrationStatus($id, 'APPROVED', 'system:daily-ticket');
    $approved = $repo->findCalibration($id);
    assert_equals('APPROVED', strtoupper((string) $approved['status']), 'the approve update survives on the same record');
    assert_true(
        (bool) preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) ($approved['approved_at'] ?? '')),
        'approved_at is a MySQL-DATETIME-compatible literal'
    );
    $active = $repo->activeCalibration(cg139_model_id($repo));
    assert_true($active !== null && (int) $active['id'] === $id, 'activeCalibration() reads back exactly the approved record');
});

// ═══════════════════════════════════════════════════════════════════════════
// 2. A failed repository write surfaces the real DB error
// ═══════════════════════════════════════════════════════════════════════════

test('real DB: a rejected calibration INSERT throws with the table and driver error instead of returning a phantom id', function () {
    cg139_reset();
    $repo = cg139_repo();
    // NOT NULL violation (created_at omitted): on every driver the write
    // fails; the repository must surface it rather than silently returning 0.
    $thrown = null;
    try {
        $repo->saveCalibration([
            'model_version_id' => cg139_model_id($repo), 'method' => 'identity',
            'intercept' => 0.0, 'slope' => 1.0, 'samples' => 0, 'bins' => '[]', 'status' => 'PENDING',
        ]);
    } catch (\Throwable $e) {
        $thrown = $e;
    }
    assert_true($thrown !== null, 'a failed calibration write throws instead of returning an id');
    assert_contains('sports_calibrations', $thrown->getMessage(), 'the error names the table');
});

test('bootstrap propagates the actual database error on CALIBRATION_PERSIST_FAILED', function () {
    $repo = new Cg139FailingInsertRepo();
    $audit = cg139_memory_audit();
    $result = (new CalibrationBootstrap($repo, $audit))->bootstrapIdentity('system:daily-ticket');
    assert_true(empty($result['ok']));
    assert_equals('CALIBRATION_PERSIST_FAILED', (string) $result['reason']);
    assert_contains(Cg139FailingInsertRepo::MARKER, (string) ($result['dbError'] ?? ''), 'the driver error travels with the result');
    $persistFail = null;
    foreach ($audit->events as $e) {
        if ($e['type'] === 'SPORTS_CALIBRATION_PERSIST_FAIL') $persistFail = $e;
    }
    assert_true($persistFail !== null, 'the persist failure is audited');
    assert_contains(Cg139FailingInsertRepo::MARKER, json_encode($persistFail['detail'] ?? []), 'the durable audit event carries the DB error');
});

test('a swallowed APPROVE update is reported as CALIBRATION_PERSIST_FAILED with the real error', function () {
    $repo = new Cg139FailingApproveRepo();
    $audit = cg139_memory_audit();
    $providers = new SportsProviderManager();
    $providers->register(cg139_provider(2));
    $pipeline = new PredictionPipeline(new MatchIntelligenceEngine(new OddsFreshnessEngine()), new FeatureEngineeringEngine(), new PredictionEngine(), new ValueEngine(), new RiskEngine(), new CorrelationEngine(), new ConfidenceEngine());
    $service = new DailyTicketService(
        $repo, $audit, $providers, new ConfigurationService($repo, $audit), new DataQualityEngine(),
        $pipeline, new TicketOptimizer(new CorrelationEngine()), new TicketGovernance($repo, $audit, new CorrelationEngine()),
        new DecisionRecorder($repo, $audit)
    );

    $run = $service->runDaily(gmdate('Y-m-d'), 'daily-ticket:139:approve-fail:' . uniqid());
    assert_equals('CALIBRATION_PERSIST_FAILED', (string) $run['diagnostics']['calibrationBootstrap'], 'the failed approve round-trip is reported, not claimed as approved');
    assert_contains(Cg139FailingApproveRepo::MARKER, (string) ($run['diagnostics']['calibrationBootstrapError'] ?? ''), 'the funnel carries the real DB error');
    assert_contains('database error', $run['message'], 'the stored/operator message surfaces the DB error rather than silent MODEL_NOT_CALIBRATED');
});

// ═══════════════════════════════════════════════════════════════════════════
// 3. The production funnel shape: 11 fresh-odds fixtures pass the gate
// ═══════════════════════════════════════════════════════════════════════════

test('real DB: an 11-fixture cold start moves every fresh-odds fixture through sufficient data to a prediction', function () {
    cg139_reset();
    $run = cg139_service(cg139_provider(11))->runDaily(gmdate('Y-m-d'), 'daily-ticket:139:eleven:' . uniqid());
    $d = $run['diagnostics'];

    assert_equals('IDENTITY_AUTO_APPROVED', (string) $d['calibrationBootstrap'], 'the cold-start identity calibration was persisted and auto-approved');
    assert_equals(0, (int) ($run['rejectionSummary']['MODEL_NOT_CALIBRATED'] ?? 0), 'no fixture dies on calibration');
    assert_equals(11, (int) $d['fixturesWithFreshOdds'], '11 fixtures present fresh odds (the production shape)');
    assert_equals(11, (int) $d['sufficientDataFixtures'], 'every fresh-odds fixture passes the sufficient-data gate');
    assert_equals(11, (int) $d['predictionsGenerated'], 'each sufficient fixture gets a generated prediction');
    assert_equals(11, (int) $d['sufficientDataCandidates'], 'each candidate reads as prediction-ready');

    $gate = $d['sufficientDataGate'];
    assert_equals(11, (int) $gate['passed'], 'the gate diagnostic counts 11 passes');
    assert_equals(0, (int) $gate['failed'], 'no gate failures');
    assert_equals(11, count($gate['fixtures']), 'one diagnostic row per fixture');
    foreach ($gate['fixtures'] as $row) {
        assert_true($row['passed'], $row['externalId'] . ' passed the gate');
        assert_equals(null, $row['failedRequirement']);
        foreach ($row['requirements'] as $name => $check) {
            assert_true(!empty($check['ok']), $row['externalId'] . ' met ' . $name);
        }
    }
    assert_equals([], (array) $d['sufficientDataFailuresByRequirement']);

    // The approved row the engine now actually reads.
    $approved = cg139_repo()->listCalibrations(cg139_model_id(cg139_repo()), 'APPROVED');
    assert_equals(1, count($approved));
    assert_equals('identity', (string) $approved[0]['method']);
});

// ═══════════════════════════════════════════════════════════════════════════
// 4. The sufficient-data gate names the exact failed requirement per fixture
// ═══════════════════════════════════════════════════════════════════════════

test('real DB: fixtures missing recentForm are named per-fixture in the gate diagnostic (no black-box zero)', function () {
    cg139_reset();
    // 2 fixtures WITHOUT verified form + 1 with: the two fail
    // MANDATORY_MODEL_DATA naming recentForm; the one passes.
    $run = cg139_service(cg139_provider(2, false))->runDaily(gmdate('Y-m-d'), 'daily-ticket:139:noform:' . uniqid());
    $d = $run['diagnostics'];

    assert_equals(2, (int) $d['fixturesWithFreshOdds']);
    assert_equals(0, (int) $d['sufficientDataFixtures'], 'no fixture has sufficient model data');
    assert_equals(2, (int) ($run['rejectionSummary']['INSUFFICIENT_DATA'] ?? 0), 'both are honest INSUFFICIENT_DATA rejections');
    assert_equals(2, (int) $d['sufficientDataGate']['failed']);
    assert_equals(0, (int) $d['sufficientDataGate']['passed']);
    assert_equals(2, (int) ($d['sufficientDataFailuresByRequirement']['MANDATORY_MODEL_DATA'] ?? 0));
    foreach ($d['sufficientDataGate']['fixtures'] as $row) {
        assert_false($row['passed']);
        assert_equals('MANDATORY_MODEL_DATA', $row['failedRequirement']);
        assert_equals('INSUFFICIENT_DATA', $row['primaryReason']);
        assert_true(in_array('recentForm', $row['requirements']['MANDATORY_MODEL_DATA']['missingMandatory'], true), 'the concrete missing field is named: ' . json_encode($row['requirements']));
        assert_true($row['requirements']['APPROVED_CALIBRATION']['ok'], 'calibration was NOT the blocker for these fixtures');
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// 5. Source-level contract: every temporal-column write is canonicalized
// ═══════════════════════════════════════════════════════════════════════════

test('the repository routes every Phase-3 temporal-column write through the DATETIME normalizer', function () {
    $src = (string) file_get_contents(APPPATH . 'models/AIWorkforce_model.php');
    assert_contains('private static function toSqlDateTime', $src, 'the canonicalizer exists');
    assert_true((bool) preg_match('/public function saveCalibration.*withSqlTimestamps/s', $src), 'calibration inserts normalize timestamps');
    assert_true((bool) preg_match('/public function saveConfiguration.*withSqlTimestamps/s', $src), 'configuration inserts normalize timestamps');
    assert_true((bool) preg_match('/public function saveBacktest.*withSqlTimestamps/s', $src), 'backtest inserts normalize timestamps');
    assert_true((bool) preg_match('/public function saveModelMetrics.*withSqlTimestamps/s', $src), 'model-metric inserts normalize timestamps');
    assert_true((bool) preg_match('/public function saveDailyTicket.*withSqlTimestamps/s', $src), 'daily-ticket writes normalize timestamps');
    assert_true((bool) preg_match('/public function savePerformanceSnapshot.*toSqlDateTime/s', $src), 'snapshot keys/inserts normalize timestamps');
    assert_contains("'started_at' => gmdate('Y-m-d H:i:s')", $src, 'job-run starts use the canonical literal');
    assert_contains("'ended_at' => gmdate('Y-m-d H:i:s')", $src, 'job-run ends use the canonical literal');
    foreach (['saveCalibration', 'saveConfiguration', 'startJobRun', 'finishJobRun', 'saveDailyTicket'] as $method) {
        assert_true((bool) preg_match('/public function ' . preg_quote($method, '/') . '.*mustWrite/s', $src), $method . ' surfaces a failed write');
    }
    // The bootstrap itself writes the canonical literal, never gmdate('c').
    $bootstrap = (string) file_get_contents(APPPATH . 'libraries/AIWorkforce/Sports/CalibrationBootstrap.php');
    assert_contains("'created_at' => gmdate('Y-m-d H:i:s')", $bootstrap);
});
