<?php
/**
 * Calibration cold-start bootstrap + form-enrichment observability.
 *
 * Reported failure mode (2026-09-10): NO_QUALIFIED_TICKET with 100% of the
 * fresh-odds fixtures rejected INSUFFICIENT_DATA and — once form is present —
 * a hard lock-out because the engine demands an APPROVED calibration that can
 * only be fitted from 20+ settled predictions the engine itself must write
 * first. Asserted here:
 *
 *   1. CalibrationBootstrap creates an identity (0, 1) calibration as
 *      PENDING — never self-approving — and refuses when an APPROVED
 *      calibration already exists or a bootstrap row is already pending;
 *   2. approving the bootstrapped row unblocks the daily ticket engine
 *      (predictions are generated where MODEL_NOT_CALIBRATED blocked them);
 *   3. the run diagnostics expose WHY recentForm is missing: the funnel
 *      carries fixturesWithRecentForm + the FormResolver's own stats
 *      (budget spent, lookup failures, budget skips, provider capability);
 *   4. the no-ticket message names the two dead ends instead of only the
 *      funnel counters.
 */
use AIWorkforce\Persistence\AuditRepository;
use AIWorkforce\Sports\CalibrationBootstrap;
use AIWorkforce\Sports\ConfidenceEngine;
use AIWorkforce\Sports\ConfigurationService;
use AIWorkforce\Sports\CorrelationEngine;
use AIWorkforce\Sports\DailyTicketService;
use AIWorkforce\Sports\DataQualityEngine;
use AIWorkforce\Sports\DecisionRecorder;
use AIWorkforce\Sports\FeatureEngineeringEngine;
use AIWorkforce\Sports\FormResolver;
use AIWorkforce\Sports\MatchIntelligenceEngine;
use AIWorkforce\Sports\OddsFreshnessEngine;
use AIWorkforce\Sports\PredictionEngine;
use AIWorkforce\Sports\PredictionPipeline;
use AIWorkforce\Sports\Providers\ApiFootballProvider;
use AIWorkforce\Sports\Providers\SportsDataProvider;
use AIWorkforce\Sports\Providers\SportsProviderManager;
use AIWorkforce\Sports\RiskEngine;
use AIWorkforce\Sports\TicketGovernance;
use AIWorkforce\Sports\TicketOptimizer;
use AIWorkforce\Sports\ValueEngine;

function cb_audit(): AuditRepository
{
    return new class implements AuditRepository {
        public array $events = [];
        public function emit(string $t, string $s, array $d = [], string $a = 'system'): void { $this->events[] = ['type' => $t, 'actor' => $a, 'detail' => $d]; }
        public function recent(int $l = 100): array { return []; }
    };
}

/** Provider that serves fixtures WITH verified recentForm (sandbox-shaped) but has no team-statistics endpoint. */
function cb_provider(): SportsDataProvider
{
    $fixtures = [];
    for ($i = 0; $i < 3; $i++) {
        $fixtures[] = [
            'externalId' => 'cb' . $i,
            'homeTeam' => 'Home' . $i, 'awayTeam' => 'Away' . $i,
            'competition' => 'Bootstrap League',
            'kickoff' => gmdate('Y-m-d\TH:i:00\+00:00', strtotime('+1 day ' . (12 + $i) . ':00:00')),
            'status' => 'SCHEDULED',
            'context' => [
                'recentForm' => [
                    'homeGoalsPerMatch' => 1.7, 'awayGoalsPerMatch' => 1.3,
                    'homeConcededPerMatch' => 1.0, 'awayConcededPerMatch' => 1.1,
                    'source' => 'cb-verified', 'timestamp' => gmdate('c'),
                ],
            ],
        ];
    }
    return new class($fixtures) implements SportsDataProvider {
        public function __construct(private array $fixtures) {}
        public function id(): string { return 'cb-test'; }
        public function health(): array { return ['status' => 'ONLINE', 'reliability' => 0.9]; }
        public function fixtures(array $q): array { return $this->fixtures; }
        public function odds(string $e): array
        {
            return [['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 1.9, 'observedAt' => gmdate('c')]];
        }
        public function results(string $e): array { return []; }
    };
}

/** @return array{0:SportsRepositoryStub,1:AuditRepository,2:DailyTicketService} */
function cb_stack(): array
{
    $repo = new SportsRepositoryStub();
    $audit = cb_audit();
    $providers = new SportsProviderManager();
    $providers->register(cb_provider());
    $config = new ConfigurationService($repo, $audit);
    $quality = new DataQualityEngine();
    $pipeline = new PredictionPipeline(new MatchIntelligenceEngine(new OddsFreshnessEngine()), new FeatureEngineeringEngine(), new PredictionEngine(), new ValueEngine(), new RiskEngine(), new CorrelationEngine(), new ConfidenceEngine());
    $governance = new TicketGovernance($repo, $audit, new CorrelationEngine());
    $service = new DailyTicketService($repo, $audit, $providers, $config, $quality, $pipeline, new TicketOptimizer(new CorrelationEngine()), $governance, new DecisionRecorder($repo, $audit));
    return [$repo, $audit, $service];
}

// ═══════════════════════════════════════════════════════════════════════════
// 1. Bootstrap semantics
// ═══════════════════════════════════════════════════════════════════════════

test('calibration bootstrap creates a PENDING identity calibration', function () {
    $repo = new SportsRepositoryStub();
    $audit = cb_audit();
    $svc = new CalibrationBootstrap($repo, $audit);
    $result = $svc->bootstrapIdentity('admin-1');
    assert_true($result['ok'], 'bootstrap succeeds on a fresh installation');
    assert_equals('PENDING', $result['status']);
    $cal = $repo->findCalibration((int) $result['calibrationId']);
    assert_equals(0.0, (float) $cal['intercept']);
    assert_equals(1.0, (float) $cal['slope']);
    assert_equals(CalibrationBootstrap::method(), $cal['method']);
    assert_equals('PENDING', $cal['status']);
    // Never self-approves: approval stays an explicit admin act.
    assert_true($repo->activeCalibration((int) $result['modelVersionId']) === null, 'bootstrap must not be approved automatically');
    $types = array_map(fn($e) => $e['type'], $audit->events);
    assert_true(in_array('SPORTS_CALIBRATION_BOOTSTRAPPED', $types, true), 'bootstrap is audited');
});

test('calibration bootstrap is idempotent while pending', function () {
    $repo = new SportsRepositoryStub();
    $svc = new CalibrationBootstrap($repo, cb_audit());
    $first = $svc->bootstrapIdentity('admin-1');
    $second = $svc->bootstrapIdentity('admin-1');
    assert_true(empty($second['ok']));
    assert_equals('IDENTITY_ALREADY_PENDING', $second['reason']);
    assert_equals((int) $first['calibrationId'], (int) $second['calibrationId']);
    $pending = $repo->listCalibrations((int) $first['modelVersionId'], 'PENDING');
    assert_equals(1, count($pending), 'no duplicate bootstrap rows');
});

test('calibration bootstrap refuses when an APPROVED calibration exists', function () {
    $repo = new SportsRepositoryStub();
    $modelId = $repo->ensureModelVersion(['modelName' => PredictionEngine::MODEL_NAME, 'modelVersion' => PredictionEngine::MODEL_VERSION, 'featureVersion' => FeatureEngineeringEngine::VERSION]);
    $repo->saveCalibration(['model_version_id' => $modelId, 'method' => 'platt', 'intercept' => 0.1, 'slope' => 1.2, 'samples' => 40, 'status' => 'APPROVED', 'created_by' => 'admin', 'created_at' => gmdate('c')]);
    $result = (new CalibrationBootstrap($repo, cb_audit()))->bootstrapIdentity('admin-2');
    assert_true(empty($result['ok']));
    assert_equals('APPROVED_CALIBRATION_EXISTS', $result['reason']);
});

// ═══════════════════════════════════════════════════════════════════════════
// 2. Deadlock broken end-to-end
// ═══════════════════════════════════════════════════════════════════════════

test('daily ticket engine breaks the calibration cold start with an audited identity bootstrap', function () {
    [$repo, $audit, $service] = cb_stack();

    // No calibration at all: the engine bootstraps the identity calibration
    // (0,1) and auto-approves it as an audited SYSTEM act — the deadlock the
    // 2026-09-10 run hit (MODEL_NOT_CALIBRATED on a fresh install) is broken
    // without a manual API call. Tickets remain user-approved by default.
    $run = $service->runDaily(gmdate('Y-m-d'));
    assert_equals('IDENTITY_AUTO_APPROVED', $run['diagnostics']['calibrationBootstrap'], 'the funnel records the cold-start break');
    assert_equals(0, (int) ($run['rejectionSummary']['MODEL_NOT_CALIBRATED'] ?? 0), 'no calibration rejections on a fresh install');
    assert_equals(3, (int) ($run['diagnostics']['predictionsGenerated'] ?? 0), 'the identity calibration unblocks prediction');

    $approved = $repo->listCalibrations(null, 'APPROVED');
    assert_equals(1, count($approved), 'exactly one approved calibration exists');
    assert_equals(CalibrationBootstrap::method(), (string) $approved[0]['method'], 'only the identity bootstrap is auto-approved');
    assert_equals(0.0, (float) $approved[0]['intercept']);
    assert_equals(1.0, (float) $approved[0]['slope']);
    assert_equals('system:daily-ticket', (string) ($approved[0]['approved_by'] ?? ''), 'the system actor is recorded on the row');
    $types = array_map(fn($e) => $e['type'], $audit->events);
    assert_true(in_array('SPORTS_CALIBRATION_BOOTSTRAPPED', $types, true), 'the bootstrap is audited');
    assert_true(in_array('SPORTS_CALIBRATION_AUTO_APPROVED', $types, true), 'the auto-approval is audited separately');
});

test('an existing PENDING identity bootstrap is reused, never duplicated', function () {
    [$repo, $audit, $service] = cb_stack();
    $boot = (new CalibrationBootstrap($repo, $audit))->bootstrapIdentity('admin-1');
    assert_true($boot['ok']);

    $run = $service->runDaily(gmdate('Y-m-d'), 'daily-ticket:' . gmdate('Y-m-d') . ':reuse-pending');
    assert_equals('IDENTITY_AUTO_APPROVED', $run['diagnostics']['calibrationBootstrap']);
    assert_equals([], $repo->listCalibrations(null, 'PENDING'), 'the pending row was approved, not left behind');
    $approved = $repo->listCalibrations(null, 'APPROVED');
    assert_equals(1, count($approved), 'no duplicate bootstrap row');
    assert_equals((int) $boot['calibrationId'], (int) $approved[0]['id'], 'the admin-created row is the one approved');
});

test('an admin-REJECTED identity bootstrap is honoured, not resurrected', function () {
    [$repo, $audit, $service] = cb_stack();
    $boot = (new CalibrationBootstrap($repo, $audit))->bootstrapIdentity('admin-1');
    $repo->updateCalibrationStatus((int) $boot['calibrationId'], 'REJECTED', 'admin-1');

    $run = $service->runDaily(gmdate('Y-m-d'), 'daily-ticket:' . gmdate('Y-m-d') . ':veto');
    assert_equals('NO_QUALIFIED_TICKET', $run['status']);
    assert_equals('REJECTED_BY_ADMIN', $run['diagnostics']['calibrationBootstrap'], 'the veto is visible in the funnel');
    assert_equals(3, (int) ($run['rejectionSummary']['MODEL_NOT_CALIBRATED'] ?? 0), 'the engine stays blocked behind an explicit veto');
    assert_equals(1, count($repo->listCalibrations(null, 'REJECTED')), 'no duplicate bootstrap row');
    assert_equals(0, count($repo->listCalibrations(null, 'APPROVED')), 'nothing was auto-approved over the veto');
    assert_contains('REJECTED by an administrator', $run['message'], 'the message says the veto is what blocks');
});

test('a veto-blocked day stays retryable without a config bump', function () {
    [$repo, $audit, $service] = cb_stack();
    $boot = (new CalibrationBootstrap($repo, $audit))->bootstrapIdentity('admin-1');
    $repo->updateCalibrationStatus((int) $boot['calibrationId'], 'REJECTED', 'admin-1');
    $date = gmdate('Y-m-d');
    $first = $service->runDaily($date); // veto → blocked, nothing stored
    assert_equals('NO_QUALIFIED_TICKET', $first['status']);
    $second = $service->runDaily($date); // same date, same config version
    assert_not_equals('DUPLICATE_SKIPPED', $second['status'], 'a blocked day must stay retryable once the operator fixes it');
});

// ── The 2026-09-10 deployed lock-out: a narrow sports_calibrations.method
// column (VARCHAR(16)) truncated the old 18-char 'identity-bootstrap'
// marker, so the engine never recognized its own bootstrap row and told
// the operator to run the same broken bootstrap manually. The marker now
// fits the narrowest deployed column, identity rows are recognised by
// prefix, and every write is verified against a read-back. ──────────────

test('the identity marker fits the narrowest deployed method column', function () {
    assert_true(strlen(CalibrationBootstrap::method()) <= 16,
        'the marker must fit the pre-widening VARCHAR(16) column that live MySQL databases still have');
    assert_true(CalibrationBootstrap::isIdentityMethod(CalibrationBootstrap::method()), 'the marker recognizes itself');
    assert_false(CalibrationBootstrap::isIdentityMethod('platt'), 'fitted rows are never mistaken for the bootstrap');
    // Legacy shapes written by the 18-char marker must be recognized.
    assert_true(CalibrationBootstrap::isIdentityMethod('identity-bootstrap'), 'the old full marker is recognized');
    assert_true(CalibrationBootstrap::isIdentityMethod('identity-bootstr'), 'the old truncated (VARCHAR(16)) marker is recognized');
});

test('a legacy truncated identity row is recognised, reused and approved', function () {
    [$repo, $audit, $service] = cb_stack();
    $modelId = $repo->ensureModelVersion(['modelName' => PredictionEngine::MODEL_NAME, 'modelVersion' => PredictionEngine::MODEL_VERSION, 'featureVersion' => FeatureEngineeringEngine::VERSION]);
    // Exactly what a non-strict MySQL install stored when the engine
    // bootstrapped with the old 18-char marker into a 16-char column.
    $legacyId = $repo->saveCalibration([
        'model_version_id' => $modelId, 'method' => 'identity-bootstr', 'intercept' => 0.0, 'slope' => 1.0,
        'samples' => 0, 'status' => 'PENDING', 'created_by' => 'system:daily-ticket', 'created_at' => gmdate('c'),
    ]);

    $run = $service->runDaily(gmdate('Y-m-d'), 'daily-ticket:' . gmdate('Y-m-d') . ':legacy-truncated');
    assert_equals('IDENTITY_AUTO_APPROVED', $run['diagnostics']['calibrationBootstrap'], 'the truncated row is the bootstrap — reuse and heal it');
    assert_equals(0, (int) ($run['rejectionSummary']['MODEL_NOT_CALIBRATED'] ?? 0), 'prediction unblocks behind the legacy row');
    $approved = $repo->listCalibrations(null, 'APPROVED');
    assert_equals(1, count($approved), 'no duplicate bootstrap row was created next to the legacy one');
    assert_equals($legacyId, (int) $approved[0]['id'], 'the legacy row is the one approved');
});

test('a bootstrap that does not survive the round-trip reports an honest failure', function () {
    $repo = new SportsRepositoryStub();
    $audit = cb_audit();
    // Simulate the deployed failure: the insert is swallowed (silently
    // failing driver / too-narrow column in strict mode) — an id comes back
    // for a row that does not exist.
    $broken = new class($repo) extends SportsRepositoryStub {
        public function __construct(private SportsRepositoryStub $inner) {}
        public function saveCalibration(array $c): int { return 999999; }
        public function findCalibration(int $id): ?array { return $this->inner->findCalibration($id); }
        public function listCalibrations(?int $m = null, ?string $s = null, int $l = 50): array { return $this->inner->listCalibrations($m, $s, $l); }
        public function activeCalibration(int $m): ?array { return $this->inner->activeCalibration($m); }
        public function ensureModelVersion(array $m): int { return $this->inner->ensureModelVersion($m); }
        public function updateCalibrationStatus(int $id, string $status, ?string $actor = null): void { $this->inner->updateCalibrationStatus($id, $status, $actor); }
    };
    $result = (new CalibrationBootstrap($broken, $audit))->bootstrapIdentity('admin-1');
    assert_true(empty($result['ok']), 'a phantom insert id is never reported as success');
    assert_equals('CALIBRATION_PERSIST_FAILED', $result['reason'] ?? '');
    $types = array_map(fn($e) => $e['type'], $audit->events);
    assert_in_array('SPORTS_CALIBRATION_PERSIST_FAIL', $types, 'the failure is audited');
    assert_false(in_array('SPORTS_CALIBRATION_BOOTSTRAPPED', $types, true), 'no success event for a failed write');
});

test('the run says the bootstrap failed to persist — not the un-actionable manual hint', function () {
    $repo = new class extends SportsRepositoryStub {
        public function saveCalibration(array $c): int { return 999999; } // insert swallowed
    };
    $audit = cb_audit();
    $providers = new SportsProviderManager();
    $providers->register(cb_provider());
    $config = new ConfigurationService($repo, $audit);
    $pipeline = new PredictionPipeline(new MatchIntelligenceEngine(new OddsFreshnessEngine()), new FeatureEngineeringEngine(), new PredictionEngine(), new ValueEngine(), new RiskEngine(), new CorrelationEngine(), new ConfidenceEngine());
    $service = new DailyTicketService($repo, $audit, $providers, $config, new DataQualityEngine(), $pipeline, new TicketOptimizer(new CorrelationEngine()), new TicketGovernance($repo, $audit, new CorrelationEngine()), new DecisionRecorder($repo, $audit));

    $run = $service->runDaily(gmdate('Y-m-d'), 'daily-ticket:' . gmdate('Y-m-d') . ':persist-fail');
    assert_equals('NO_QUALIFIED_TICKET', $run['status']);
    assert_equals('CALIBRATION_PERSIST_FAILED', (string) $run['diagnostics']['calibrationBootstrap']);
    assert_contains('did not survive the database round-trip', $run['message'], 'the message names the schema/persistence failure');
    assert_contains('CALIBRATION_PERSIST_FAILED', $run['message'], 'and carries the machine-readable state');
    assert_false(str_contains($run['message'], 'bootstrap-identity, then approve it'), 'no hint to repeat the call that just failed');
    assert_equals(3, (int) ($run['rejectionSummary']['MODEL_NOT_CALIBRATED'] ?? 0), 'fixtures are still honestly blocked, never predicted on a phantom calibration');
});

// ── Configuration rows are append-only and may predate a column; the
// stored row must never read as two different truths at two call sites
// (that asymmetry is the hard lock-out variant of the 2026-09-10 alert). ──

test('a legacy configuration row missing require_calibration still runs the cold-start break', function () {
    [$repo, $audit, $service] = cb_stack();
    // A v1 row as a pre-require_calibration schema would have stored it:
    // every column of its time, none of the one added later.
    $legacy = ConfigurationService::defaults();
    unset($legacy['require_calibration']);
    $legacy['version'] = 1;
    $legacy['updated_by'] = 'admin-1';
    $legacy['reason'] = 'row authored before the require_calibration column existed';
    $repo->saveConfiguration($legacy);

    $configService = new ConfigurationService($repo, $audit);
    $active = $configService->active();
    assert_equals(1, (int) $active['require_calibration'], 'the absent key arrives as the documented default, never as absent');
    assert_equals(1, $active['version'], 'the stored row still wins wherever it has a value');
    $run = $service->runDaily(gmdate('Y-m-d'), 'daily-ticket:' . gmdate('Y-m-d') . ':legacy-config');
    assert_equals('IDENTITY_AUTO_APPROVED', $run['diagnostics']['calibrationBootstrap'], 'a defaulted-required calibration is bootstrapped like an explicit one');
    assert_equals(0, (int) ($run['rejectionSummary']['MODEL_NOT_CALIBRATED'] ?? 0), 'nothing is demanded that the engine refused to provide');
    assert_equals(3, (int) $run['diagnostics']['predictionsGenerated'], 'the day can produce predictions again');
});

test('a legacy row with require_calibration explicitly off skips the bootstrap and still predicts', function () {
    [$repo, $audit, $service] = cb_stack();
    $legacy = ConfigurationService::defaults();
    $legacy['require_calibration'] = '0'; // how MySQL hands back a tinyint
    $legacy['version'] = 2;
    $legacy['updated_by'] = 'admin-1';
    $repo->saveConfiguration($legacy);

    $run = $service->runDaily(gmdate('Y-m-d'), 'daily-ticket:' . gmdate('Y-m-d') . ':require-off');
    assert_equals('', (string) ($run['diagnostics']['calibrationBootstrap'] ?? ''), 'an explicit veto by configuration is honoured — no bootstrap, no auto-approval');
    assert_equals(0, (int) ($run['rejectionSummary']['MODEL_NOT_CALIBRATED'] ?? 0), 'enforcement is off, exactly as configured');
    assert_equals(3, (int) $run['diagnostics']['predictionsGenerated'], 'predictions run through the raw-model identity mapping');
    assert_equals(0, count($repo->listCalibrations(null, 'APPROVED')), 'no calibration row was created behind the operator');
});

// ═══════════════════════════════════════════════════════════════════════════
// 3. Form-enrichment observability in the funnel
// ═══════════════════════════════════════════════════════════════════════════

test('diagnostics funnel reports form enrichment and the resolver stats', function () {
    [$repo, $audit, $service] = cb_stack();
    $modelId = $repo->ensureModelVersion(['modelName' => PredictionEngine::MODEL_NAME, 'modelVersion' => PredictionEngine::MODEL_VERSION, 'featureVersion' => FeatureEngineeringEngine::VERSION]);
    $repo->saveCalibration(['model_version_id' => $modelId, 'method' => 'platt', 'intercept' => 0.0, 'slope' => 1.0, 'samples' => 30, 'ece' => 0.05, 'status' => 'APPROVED', 'created_by' => 'admin', 'created_at' => gmdate('c')]);

    $run = $service->runDaily(gmdate('Y-m-d'), 'daily-ticket:' . gmdate('Y-m-d') . ':funnel-form');
    $diag = $run['diagnostics'];
    assert_equals(3, (int) ($diag['fixturesWithRecentForm'] ?? 0), 'fixtures carrying recentForm are counted');
    assert_true(is_array($diag['formResolver'] ?? null), 'resolver stats are on the funnel');
    assert_equals(false, (bool) $diag['formResolver']['providerCapable'], 'the stub provider has no team-statistics endpoint');
    assert_equals(0, (int) $diag['formResolver']['lookupsUsed']);
});

test('FormResolver stats expose budget skips and lookup failures', function () {
    $statsBody = json_encode(['response' => [
        'fixtures' => ['played' => ['total' => 20]],
        'goals' => ['for' => ['total' => ['total' => 40]], 'against' => ['total' => ['total' => 25]]],
    ]]);
    $okTransport = fn(string $url, array $headers) => ['status' => 200, 'body' => $statsBody];
    $fixture = [[
        'externalId' => '1', 'homeTeam' => 'Team A', 'awayTeam' => 'Team B',
        'competition' => 'League', 'kickoff' => '2026-09-15T19:00:00Z', 'status' => 'SCHEDULED',
        'homeTeamId' => '33', 'awayTeamId' => '40', 'leagueId' => '39', 'season' => '2026',
    ]];

    // Budget of TWO lookups: the league table costs one (this transport
    // answers it with team-statistics JSON, so it covers no team), the home
    // side then gets its per-team statistics, and the away side hits the cap.
    $resolver = new FormResolver(2);
    $enriched = $resolver->enrich(new ApiFootballProvider('k', 'https://api.test', 10, $okTransport), $fixture);
    $stats = $resolver->stats();
    assert_equals(2, (int) $stats['lookupsUsed'], 'table + one per-team lookup within budget');
    assert_equals(1, (int) $stats['budgetSkips'], 'the away side hit the budget cap');
    assert_true(empty($enriched[0]['context']['recentForm']), 'a half-resolved fixture keeps no recentForm');

    // A dead quota: every lookup throws and is counted, never swallowed silently.
    $deadTransport = fn(string $url, array $headers) => ['status' => 429, 'body' => '{"errors":{"requests":"limit"}}'];
    $resolver2 = new FormResolver(30);
    $enriched2 = $resolver2->enrich(new ApiFootballProvider('k', 'https://api.test', 10, $deadTransport), $fixture);
    $stats2 = $resolver2->stats();
    assert_true((int) $stats2['lookupFailures'] >= 1, 'lookup failures are counted');
    assert_true(empty($enriched2[0]['context']['recentForm']));
    assert_equals(true, (bool) $stats2['providerCapable']);
});

test('partial form coverage names the exhausted lookup budget in the message', function () {
    // The 2026-09-10 alert shape: 95 eligible fixtures, a 30-lookup budget,
    // 27 with form — and the old message said nothing about form because
    // SOME fixtures had it. Partial coverage driven by a systematic cause
    // must be named: it is what turns the day into INSUFFICIENT_DATA.
    $repo = new SportsRepositoryStub();
    $audit = cb_audit();
    $formProvider = new class implements SportsDataProvider {
        public function id(): string { return 'cb-form'; }
        public function health(): array { return ['status' => 'ONLINE', 'reliability' => 0.9]; }
        public function fixtures(array $q): array {
            $mk = fn(string $x, string $league, string $h, string $a, string $hour) => [
                'externalId' => $x, 'homeTeam' => 'Home' . $x, 'awayTeam' => 'Away' . $x,
                'competition' => 'Budget League ' . $league,
                'kickoff' => gmdate('Y-m-d\\TH:i:00\\+00:00', strtotime('+1 day ' . $hour)),
                'status' => 'SCHEDULED',
                'homeTeamId' => $h, 'awayTeamId' => $a, 'leagueId' => $league, 'season' => '2026',
            ];
            return [$mk('b0', '39', '33', '34', '12:00'), $mk('b1', '40', '51', '52', '13:00')];
        }
        public function odds(string $e): array
        {
            return [['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 1.9, 'observedAt' => gmdate('c')]];
        }
        public function results(string $e): array { return []; }
        /** League 39 only — and a ONE-lookup budget means league 40 is never asked. */
        public function standings(string $leagueId, string $season): array
        {
            if ($leagueId !== '39') return [];
            return [
                ['teamId' => '33', 'played' => 10, 'goalsFor' => 18, 'goalsAgainst' => 9],
                ['teamId' => '34', 'played' => 10, 'goalsFor' => 12, 'goalsAgainst' => 11],
            ];
        }
    };
    $providers = new SportsProviderManager();
    $providers->register($formProvider);
    $config = new ConfigurationService($repo, $audit);
    $pipeline = new PredictionPipeline(new MatchIntelligenceEngine(new OddsFreshnessEngine()), new FeatureEngineeringEngine(), new PredictionEngine(), new ValueEngine(), new RiskEngine(), new CorrelationEngine(), new ConfidenceEngine());
    $service = new DailyTicketService($repo, $audit, $providers, $config, new DataQualityEngine(), $pipeline, new TicketOptimizer(new CorrelationEngine()), new TicketGovernance($repo, $audit, new CorrelationEngine()), new DecisionRecorder($repo, $audit), new FormResolver(1));

    $run = $service->runDaily(gmdate('Y-m-d'), 'daily-ticket:' . gmdate('Y-m-d') . ':partial-form');
    $diag = $run['diagnostics'];
    assert_equals(2, (int) $diag['formEnrichmentCandidates']);
    assert_equals(1, (int) $diag['fixturesWithRecentForm'], 'only the first league was affordable within the budget');
    assert_equals(2, (int) $diag['formResolver']['budgetSkips']);
    assert_equals(1, (int) ($run['rejectionSummary']['INSUFFICIENT_DATA'] ?? 0), 'the unfunded fixture is an honest INSUFFICIENT_DATA rejection');
    assert_contains('resolved for only 1 of 2 ticket-eligible fixtures', $run['message'], 'the partial coverage is stated with its counts');
    assert_contains('WINDELS_SPORTS_FORM_LOOKUPS = 1', $run['message'], 'the message names the budget that starved the rest');
    assert_contains('form lookups skipped at the 1-lookup budget', $run['message'], 'and the compact funnel carries it too');
});

test('no-form runs explain themselves in the stored message', function () {
    [$repo, $audit, $service] = cb_stack();
    // Replace the provider with one whose fixtures carry NO recentForm.
    $bare = new class implements SportsDataProvider {
        public function id(): string { return 'cb-bare'; }
        public function health(): array { return ['status' => 'ONLINE', 'reliability' => 0.9]; }
        public function fixtures(array $q): array {
            return [[
                'externalId' => 'bare0', 'homeTeam' => 'NoForm FC', 'awayTeam' => 'NoStats United',
                'competition' => 'Bootstrap League',
                'kickoff' => gmdate('Y-m-d\TH:i:00\+00:00', strtotime('+1 day 14:00:00')),
                'status' => 'SCHEDULED',
            ]];
        }
        public function odds(string $e): array { return [['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 1.9, 'observedAt' => gmdate('c')]]; }
        public function results(string $e): array { return []; }
    };
    $providers = new SportsProviderManager();
    $providers->register($bare);
    $config = new ConfigurationService($repo, $audit);
    $pipeline = new PredictionPipeline(new MatchIntelligenceEngine(new OddsFreshnessEngine()), new FeatureEngineeringEngine(), new PredictionEngine(), new ValueEngine(), new RiskEngine(), new CorrelationEngine(), new ConfidenceEngine());
    $service2 = new DailyTicketService($repo, $audit, $providers, $config, new DataQualityEngine(), $pipeline, new TicketOptimizer(new CorrelationEngine()), new TicketGovernance($repo, $audit, new CorrelationEngine()), new DecisionRecorder($repo, $audit));

    $run = $service2->runDaily(gmdate('Y-m-d'), 'daily-ticket:' . gmdate('Y-m-d') . ':no-form');
    assert_equals(0, (int) ($run['diagnostics']['fixturesWithRecentForm'] ?? 0));
    assert_true(($run['rejectionSummary']['INSUFFICIENT_DATA'] ?? 0) === 1);
    assert_contains('recent form could not be resolved', $run['message'], 'the message diagnoses the form dead end');
    assert_contains('no team-statistics endpoint', $run['message'], 'the message names the capability gap');
});
