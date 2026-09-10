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

test('daily ticket engine is blocked without calibration, unblocked by bootstrap + approval', function () {
    [$repo, $audit, $service] = cb_stack();

    // Without any calibration the engine predicts nothing (the lock-out).
    $run = $service->runDaily(gmdate('Y-m-d'));
    assert_equals('NO_QUALIFIED_TICKET', $run['status']);
    assert_equals(0, (int) $run['predictionsRecorded']);
    assert_equals(3, (int) ($run['diagnostics']['fixturesWithoutCalibration'] ?? 0), 'every sufficient-data fixture hit the calibration gate');
    assert_true(($run['rejectionSummary']['MODEL_NOT_CALIBRATED'] ?? 0) === 3, 'MODEL_NOT_CALIBRATED is the primary reason');
    assert_contains('bootstrap-identity', $run['message'], 'the message names the fix');

    // Bootstrap (PENDING) alone changes nothing — approval is the act.
    $svc = new CalibrationBootstrap($repo, $audit);
    $boot = $svc->bootstrapIdentity('admin-1');
    assert_true($boot['ok']);
    // A fresh execution key: same day, but the previous run already stored its
    // diagnostics under the same (date, config version) slot.
    $runPending = $service->runDaily(gmdate('Y-m-d'), 'daily-ticket:' . gmdate('Y-m-d') . ':pending-cal');
    assert_equals(0, (int) $runPending['predictionsRecorded'], 'a PENDING calibration still predicts nothing');

    $repo->updateCalibrationStatus((int) $boot['calibrationId'], 'APPROVED', 'admin-1');
    $runApproved = $service->runDaily(gmdate('Y-m-d'), 'daily-ticket:' . gmdate('Y-m-d') . ':approved-cal');
    assert_equals(3, (int) ($runApproved['diagnostics']['predictionsGenerated'] ?? 0), 'the approved identity calibration unblocks prediction');
    assert_true(($runApproved['rejectionSummary']['MODEL_NOT_CALIBRATED'] ?? 0) === 0, 'no calibration rejections after approval');
});

test('a blocked day that stored nothing stays retryable without a config bump', function () {
    [$repo, $audit, $service] = cb_stack();
    $date = gmdate('Y-m-d');
    $first = $service->runDaily($date); // no calibration → nothing stored
    assert_equals('NO_QUALIFIED_TICKET', $first['status']);
    assert_equals(0, (int) $first['predictionsRecorded']);
    $second = $service->runDaily($date); // same date, same config version
    assert_not_equals('DUPLICATE_SKIPPED', $second['status'], 'a blocked day must stay retryable once the operator fixes it');
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

    // Budget of ONE lookup: home side is fetched, away side is skipped.
    $resolver = new FormResolver(1);
    $enriched = $resolver->enrich(new ApiFootballProvider('k', 'https://api.test', 10, $okTransport), $fixture);
    $stats = $resolver->stats();
    assert_equals(1, (int) $stats['lookupsUsed'], 'one live lookup within budget');
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
