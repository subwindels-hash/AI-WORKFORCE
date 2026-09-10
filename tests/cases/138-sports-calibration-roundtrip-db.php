<?php
/**
 * The CALIBRATION_PERSIST_FAILED lock-out, pinned against the REAL database.
 *
 * The 2026-09-10 daily run reported NO_QUALIFIED_TICKET with every fresh-odds
 * fixture rejected MODEL_NOT_CALIBRATED because the identity bootstrap row
 * did not survive the database round-trip: the deployed method column was too
 * narrow for the marker, the insert was truncated/rejected, and the engine's
 * own read-back said "no such calibration". Case 136 pins the schema↔marker
 * width contract; case 133 pins the bootstrap semantics — but both run on
 * the in-memory stub repository, the one place a round-trip cannot fail.
 *
 * These cases run the SAME flow through the real (throwaway sqlite)
 * repository that the application uses — `platform()->model->sports` — which
 * is the only layer where an insert can genuinely come back different from
 * what was written:
 *
 *   1. bootstrapIdentity() on the real repository: the row is written, read
 *      back intact (marker + PENDING + 0/1), and the audit event survives
 *      its own insert;
 *   2. a legacy row carrying the truncated 16-char marker is recognised by
 *      prefix, reused and approved — never duplicated by a fresh insert;
 *   3. the full cold-start runDaily(): IDENTITY_AUTO_APPROVED, zero
 *      MODEL_NOT_CALIBRATED rejections, predictions generated, and the
 *      APPROVED row reads back with the system actor recorded;
 *   4. a re-run on the same day is idempotent: no second calibration row,
 *      no CALIBRATION_PERSIST_FAILED in the stored message.
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

const RT138_PROVIDER = 'rt138-sim';

/** The real repository under test — the same one the application serves from. */
function rt138_repo(): SportsRepository
{
    return platform()->model->sports;
}

/** The real audit sink — events must survive their own INSERT into audit_logs. */
function rt138_audit(): AuditRepository
{
    return platform()->model->audit;
}

function rt138_model_id(SportsRepository $repo): int
{
    return $repo->ensureModelVersion([
        'modelName' => PredictionEngine::MODEL_NAME,
        'modelVersion' => PredictionEngine::MODEL_VERSION,
        'featureVersion' => FeatureEngineeringEngine::VERSION,
    ]);
}

/**
 * Reset every row this case can have written, so each test starts from the
 * same clean state even though all tests share the throwaway database.
 */
function rt138_reset(): void
{
    $db = ci()->db;
    $repo = rt138_repo();
    $providerId = (int) $repo->ensureProvider(RT138_PROVIDER, RT138_PROVIDER)['id'];
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
    $db->delete('sports_calibrations', ['model_version_id' => rt138_model_id($repo)]);
    $db->where('provider', RT138_PROVIDER)->delete('sports_daily_tickets');
    $db->like('execution_key', 'daily-ticket:138', 'after')->delete('sports_job_runs');
}

/** Deterministic provider: three fixtures tomorrow with verified form + fresh OVER_1_5 odds. */
function rt138_provider(): SportsDataProvider
{
    $fixtures = [];
    for ($i = 0; $i < 3; $i++) {
        $fixtures[] = [
            'externalId' => 'rt138-' . $i,
            'homeTeam' => 'Roundtrip Home ' . $i, 'awayTeam' => 'Roundtrip Away ' . $i,
            'competition' => 'Roundtrip League',
            'kickoff' => gmdate('Y-m-d\TH:i:00\+00:00', strtotime('+1 day ' . (12 + $i) . ':00:00')),
            'status' => 'SCHEDULED',
            'context' => [
                'recentForm' => [
                    'homeGoalsPerMatch' => 1.7, 'awayGoalsPerMatch' => 1.3,
                    'homeConcededPerMatch' => 1.0, 'awayConcededPerMatch' => 1.1,
                    'source' => 'rt138-verified', 'timestamp' => gmdate('c'),
                ],
            ],
        ];
    }
    return new class($fixtures) implements SportsDataProvider {
        public function __construct(private array $fixtures) {}
        public function id(): string { return RT138_PROVIDER; }
        public function health(): array { return ['status' => 'ONLINE', 'reliability' => 0.9]; }
        public function fixtures(array $q): array { return $this->fixtures; }
        public function odds(string $e): array
        {
            return [['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 1.9, 'observedAt' => gmdate('c')]];
        }
        public function results(string $e): array { return []; }
    };
}

/** @return DailyTicketService wired on the REAL repository + audit trail. */
function rt138_service(SportsRepository $repo, AuditRepository $audit): DailyTicketService
{
    $providers = new SportsProviderManager();
    $providers->register(rt138_provider());
    $pipeline = new PredictionPipeline(new MatchIntelligenceEngine(new OddsFreshnessEngine()), new FeatureEngineeringEngine(), new PredictionEngine(), new ValueEngine(), new RiskEngine(), new CorrelationEngine(), new ConfidenceEngine());
    return new DailyTicketService(
        $repo, $audit, $providers, new ConfigurationService($repo, $audit), new DataQualityEngine(),
        $pipeline, new TicketOptimizer(new CorrelationEngine()), new TicketGovernance($repo, $audit, new CorrelationEngine()),
        new DecisionRecorder($repo, $audit)
    );
}

// ═══════════════════════════════════════════════════════════════════════════
// 1. The round-trip the alert named: insert → read back → intact
// ═══════════════════════════════════════════════════════════════════════════

test('real DB: the identity bootstrap row survives the database round-trip', function () {
    rt138_reset();
    $repo = rt138_repo();

    $result = (new CalibrationBootstrap($repo, rt138_audit()))->bootstrapIdentity('system:daily-ticket');
    assert_true(!empty($result['ok']), 'the bootstrap reports success only after the row reads back: ' . json_encode($result));
    assert_equals('PENDING', (string) $result['status']);

    $stored = $repo->findCalibration((int) $result['calibrationId']);
    assert_true($stored !== null, 'the inserted id resolves to a real row');
    assert_equals(CalibrationBootstrap::method(), (string) $stored['method'], 'the marker comes back exactly as written — no truncation, no mangling');
    assert_equals('PENDING', strtoupper((string) $stored['status']));
    assert_equals(0.0, (float) $stored['intercept'], 'identity intercept survives');
    assert_equals(1.0, (float) $stored['slope'], 'identity slope survives');
    assert_equals('system:daily-ticket', (string) $stored['created_by'], 'the actor survives the round-trip');

    // The audit event itself is a database write — the live DB once lost it
    // to a narrow actor column. It must be present in audit_logs, not only
    // in the in-process trail.
    $types = array_column(rt138_audit()->recent(200), 'type');
    assert_in_array('SPORTS_CALIBRATION_BOOTSTRAPPED', $types, 'the bootstrap audit event survived its own INSERT');
});

// ═══════════════════════════════════════════════════════════════════════════
// 2. A legacy truncated marker row is healed on the real database
// ═══════════════════════════════════════════════════════════════════════════

test('real DB: a legacy truncated identity row is approved, never duplicated', function () {
    rt138_reset();
    $repo = rt138_repo();
    $modelId = rt138_model_id($repo);
    // Exactly what a non-strict MySQL install stored when the old 18-char
    // marker met the 16-char column that live databases still had.
    $legacyId = $repo->saveCalibration([
        'model_version_id' => $modelId, 'method' => 'identity-bootstr', 'intercept' => 0.0, 'slope' => 1.0,
        'samples' => 0, 'status' => 'PENDING', 'created_by' => 'system:daily-ticket', 'created_at' => gmdate('c'),
    ]);
    assert_true($legacyId > 0, 'the legacy row is written');

    $run = rt138_service($repo, rt138_audit())->runDaily(gmdate('Y-m-d'), 'daily-ticket:138:legacy:' . uniqid());
    assert_equals('IDENTITY_AUTO_APPROVED', (string) $run['diagnostics']['calibrationBootstrap'], 'the truncated row is recognised by prefix and healed');
    assert_equals(0, (int) ($run['rejectionSummary']['MODEL_NOT_CALIBRATED'] ?? 0));

    $approved = $repo->listCalibrations($modelId, 'APPROVED');
    assert_equals(1, count($approved), 'exactly one approved calibration');
    assert_equals($legacyId, (int) $approved[0]['id'], 'the legacy row itself was approved');
    assert_equals(0, count($repo->listCalibrations($modelId, 'PENDING')), 'no fresh bootstrap row was stacked next to it');
});

// ═══════════════════════════════════════════════════════════════════════════
// 3. The cold start the 2026-09-10 run died on — end to end on real storage
// ═══════════════════════════════════════════════════════════════════════════

test('real DB: cold-start run bootstraps, auto-approves and predicts — no CALIBRATION_PERSIST_FAILED', function () {
    rt138_reset();
    $repo = rt138_repo();
    $audit = rt138_audit();
    $modelId = rt138_model_id($repo);
    assert_true($repo->activeCalibration($modelId) === null, 'the test starts from a genuinely uncalibrated installation');

    $run = rt138_service($repo, $audit)->runDaily(gmdate('Y-m-d'), 'daily-ticket:138:coldstart:' . uniqid());

    assert_equals('IDENTITY_AUTO_APPROVED', (string) $run['diagnostics']['calibrationBootstrap'], 'the funnel records the cold-start break');
    assert_equals(0, (int) ($run['rejectionSummary']['MODEL_NOT_CALIBRATED'] ?? 0), 'no fixture dies on a missing calibration');
    assert_equals(3, (int) ($run['diagnostics']['predictionsGenerated'] ?? 0), 'the identity calibration unblocks prediction on real storage');
    assert_false(str_contains((string) $run['message'], 'CALIBRATION_PERSIST_FAILED'), 'the run message carries no persist failure');
    assert_false(str_contains((string) $run['message'], 'BOOTSTRAP_UNREADABLE'), 'the run message carries no unreadable bootstrap');

    $approved = $repo->listCalibrations($modelId, 'APPROVED');
    assert_equals(1, count($approved), 'exactly one approved calibration exists after the run');
    assert_equals(CalibrationBootstrap::method(), (string) $approved[0]['method']);
    assert_equals(0.0, (float) $approved[0]['intercept']);
    assert_equals(1.0, (float) $approved[0]['slope']);
    assert_equals('system:daily-ticket', (string) $approved[0]['approved_by'], 'the system actor is recorded on the row');
    assert_true($repo->activeCalibration($modelId) !== null, 'activeCalibration() finds what the run approved — the read path agrees with the write path');

    $types = array_column($audit->recent(300), 'type');
    assert_in_array('SPORTS_CALIBRATION_BOOTSTRAPPED', $types, 'bootstrap audited on the durable trail');
    assert_in_array('SPORTS_CALIBRATION_AUTO_APPROVED', $types, 'auto-approval audited separately on the durable trail');
});

test('real DB: the unblocked day is idempotent — a re-run adds no calibration row', function () {
    // Depends on the cold-start run above having approved the identity row.
    $repo = rt138_repo();
    $modelId = rt138_model_id($repo);
    $before = count($repo->listCalibrations($modelId));

    $run = rt138_service($repo, rt138_audit())->runDaily(gmdate('Y-m-d'), 'daily-ticket:138:rerun:' . uniqid());
    assert_false(str_contains((string) $run['message'], 'CALIBRATION_PERSIST_FAILED'), 'no persist failure on the re-run');
    assert_equals(0, (int) ($run['rejectionSummary']['MODEL_NOT_CALIBRATED'] ?? 0), 'the approved calibration keeps unblocking');
    assert_equals($before, count($repo->listCalibrations($modelId)), 'no duplicate calibration row on a re-run');
    assert_true($repo->activeCalibration($modelId) !== null, 'the approved row is still the active one');

    rt138_reset(); // leave the shared throwaway DB as this case found it
});
