<?php
use AIWorkforce\Persistence\AuditRepository;
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

function fx_daily_audit(): AuditRepository
{
    return new class implements AuditRepository { public array $events = []; public function emit(string $t, string $s, array $d = [], string $a = 'system'): void { $this->events[] = ['type' => $t, 'actor' => $a, 'detail' => $d]; } public function recent(int $l = 100): array { return []; } };
}

/** Deterministic test provider: 5 upcoming fixtures with verified form context + fresh OVER_1.5 odds. */
function fx_daily_provider(array $oddsByExt): SportsDataProvider
{
    $fixtures = [];
    $odds = $oddsByExt;
    for ($i = 0; $i < 5; $i++) {
        $fixtures[] = [
            'externalId' => 'f' . $i,
            'homeTeam' => 'Home' . $i, 'awayTeam' => 'Away' . $i,
            'competition' => 'Test League ' . $i,
            'kickoff' => gmdate('Y-m-d\TH:i:00\+00:00', strtotime('+1 day ' . (10 + $i) . ':00:00')),
            'status' => 'SCHEDULED',
            'context' => [
                'recentForm' => [
                    'homeGoalsPerMatch' => 1.6, 'awayGoalsPerMatch' => 1.4,
                    'homeConcededPerMatch' => 1.0, 'awayConcededPerMatch' => 0.9,
                    'source' => 'test-verified', 'timestamp' => gmdate('c'),
                ],
                'marketLiquidity' => 50000,
            ],
        ];
    }
    return new class($fixtures, $odds) implements SportsDataProvider {
        public function __construct(private array $fixtures, private array $odds) {}
        public function id(): string { return 'daily-test'; }
        public function health(): array { return ['status' => 'ONLINE', 'reliability' => 0.9]; }
        public function fixtures(array $q): array { return $this->fixtures; }
        public function odds(string $e): array { return $this->odds[$e] ?? []; }
        public function results(string $e): array { return []; }
    };
}

function fx_daily_stack(): array
{
    $repo = new SportsRepositoryStub();
    $audit = fx_daily_audit();
    $providers = new SportsProviderManager();
    $providers->register(fx_daily_provider([
        'f0' => [['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 1.55, 'observedAt' => gmdate('c')]],
        'f1' => [['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 1.75, 'observedAt' => gmdate('c')]],
        'f2' => [['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 1.9, 'observedAt' => gmdate('c')]],
        'f3' => [['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 2.1, 'observedAt' => gmdate('c')]],
        'f4' => [['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 2.3, 'observedAt' => gmdate('c')]],
    ]));
    $config = new ConfigurationService($repo, $audit);
    $quality = new DataQualityEngine();
    $pipeline = new PredictionPipeline(new MatchIntelligenceEngine(new OddsFreshnessEngine()), new FeatureEngineeringEngine(), new PredictionEngine(), new ValueEngine(), new RiskEngine(), new CorrelationEngine(), new ConfidenceEngine());
    $governance = new TicketGovernance($repo, $audit, new CorrelationEngine());
    $service = new DailyTicketService($repo, $audit, $providers, $config, $quality, $pipeline, new TicketOptimizer(new CorrelationEngine()), $governance, new DecisionRecorder($repo, $audit));
    return [$repo, $audit, $service, $providers];
}

function fx_approve_calibration(SportsRepositoryStub $repo): void
{
    $modelId = $repo->ensureModelVersion(['modelName' => PredictionEngine::MODEL_NAME, 'modelVersion' => PredictionEngine::MODEL_VERSION, 'featureVersion' => FeatureEngineeringEngine::VERSION]);
    $repo->saveCalibration(['model_version_id' => $modelId, 'method' => 'platt', 'intercept' => 0.2, 'slope' => 1.5, 'samples' => 40, 'ece' => 0.02, 'status' => 'APPROVED', 'created_by' => 'admin', 'created_at' => gmdate('c')]);
}

test('daily ticket E2E: qualified ticket awaits user approval', function () {
    [$repo, $audit, $service] = fx_daily_stack();
    fx_approve_calibration($repo);
    $date = gmdate('Y-m-d', strtotime('+1 day'));
    $run = $service->runDaily($date);
    assert_equals('PENDING_USER_APPROVAL', $run['status']);
    assert_not_null($run['ticketId']);
    assert_equals(5, $run['evaluated']);
    assert_equals(5, $run['predictionsRecorded']);
    $ticket = $repo->findTicket($run['ticketId']);
    assert_equals('PENDING_USER_APPROVAL', $ticket['approval_status']);
    assert_true($ticket['total_odds'] >= 5.0 && $ticket['total_odds'] <= 8.0, 'odds inside configured range');
    assert_true($ticket['selection_count'] >= 1 && $ticket['selection_count'] <= 5);
    assert_true($ticket['confidence'] >= 75.0, '75%+ minimum confidence enforced on the ticket');
    assert_equals(10.0, (float) $ticket['stake']);
    $daily = $repo->findDailyTicket($date);
    assert_equals('PENDING_USER_APPROVAL', $daily['status']);
    assert_equals($run['ticketId'], $daily['ticket_id']);
    // decisions stored for every evaluated match with full factors
    $preds = $repo->listPredictions([], 100);
    assert_equals(5, count($preds));
    $p = $preds[0];
    assert_not_null($p['odds']);
    assert_true(isset($p['factors']['drivers'], $p['factors']['gate'], $p['factors']['calibration']));
    // Idempotency is based on the persisted ticket, not the attempt row: the
    // same date returns the existing ticket and never creates a second one.
    $again = $service->runDaily($date);
    assert_equals('GENERATED', $again['status']);
    assert_true($again['existing']);
    assert_equals($run['ticketId'], $again['ticketId']);
    assert_equals(1, count($repo->tickets));
});

test('daily ticket E2E: scheduler cycle generates automatically and second cycle returns it', function () {
    $repo = new SportsRepositoryStub();
    $audit = fx_daily_audit();
    fx_approve_calibration($repo);
    $sports = new AIWorkforce\Sports\SportsIntelligence($repo, $audit);
    $sports->providers->register(fx_daily_provider([
        'f0' => [['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 1.55, 'observedAt' => gmdate('c')]],
        'f1' => [['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 1.75, 'observedAt' => gmdate('c')]],
        'f2' => [['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 1.9, 'observedAt' => gmdate('c')]],
        'f3' => [['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 2.1, 'observedAt' => gmdate('c')]],
        'f4' => [['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 2.3, 'observedAt' => gmdate('c')]],
    ]));
    $cron = new AIWorkforce\Sports\SportsCronService($repo, $audit, $sports);
    $date = gmdate('Y-m-d', strtotime('+1 day'));

    $first = $cron->runDailyGenerationCycle($date, ['scheduled' => true]);
    assert_true(isset($first['fixtures'], $first['odds'], $first['quality'], $first['ticket']), 'focused automatic cycle runs every prerequisite in order');
    assert_not_null($first['ticket']['ticketId'] ?? null);
    assert_equals('GENERATED', $repo->findDailyTicket($date)['generation_status']);
    assert_equals(1, count($repo->tickets));
    $dashboard = $sports->dashboard($date);
    assert_equals('GENERATED', $dashboard['ticketEngine']['today']['generation_status'], 'dashboard refresh reads the persisted generation state');
    assert_equals($first['ticket']['ticketId'], $dashboard['ticketEngine']['ticket']['id']);

    $second = $cron->runDailyGenerationCycle($date, ['scheduled' => true]);
    assert_equals('GENERATED', $second['ticket']['status']);
    assert_true(!empty($second['ticket']['existing']));
    assert_equals($first['ticket']['ticketId'], $second['ticket']['ticketId']);
    assert_true(!isset($second['fixtures']), 'persisted ticket short-circuits repeated provider work');
    assert_equals(1, count($repo->tickets));
});

test('daily ticket E2E: a stale job-attempt key cannot block first-time generation', function () {
    [$repo, , $service] = fx_daily_stack();
    fx_approve_calibration($repo);
    $date = gmdate('Y-m-d', strtotime('+1 day'));
    $base = 'daily-ticket:test:stale-attempt';
    $stale = $repo->startJobRun(['id' => 'stale-attempt', 'jobType' => 'DAILY_TICKET', 'executionKey' => $base . ':attempt:1']);
    assert_not_null($stale);

    $run = $service->runDaily($date, $base);
    assert_equals('PENDING_USER_APPROVAL', $run['status'], 'the owned daily slot recovers under a distinct telemetry key');
    assert_not_null($run['ticketId']);
    assert_equals('GENERATED', $repo->findDailyTicket($date)['generation_status']);
    assert_equals(1, count($repo->tickets));
});

test('daily ticket E2E: failed/deleted attempt metadata cannot hide an existing ticket', function () {
    [$repo, , $service] = fx_daily_stack();
    fx_approve_calibration($repo);
    $date = gmdate('Y-m-d', strtotime('+1 day'));
    $first = $service->runDaily($date);
    assert_not_null($first['ticketId']);

    // Simulate damaged attempt metadata while retaining the canonical daily
    // ticket reference. Persisted ticket truth must be checked first.
    $repo->updateDailyTicket($date, [
        'generation_status' => 'FAILED', 'last_error_code' => 'SIMULATED_ATTEMPT_DELETE',
        'next_retry_at' => gmdate('c', time() + 86400),
    ]);
    $again = $service->runDaily($date, null, ['scheduled' => true]);
    assert_equals('GENERATED', $again['status']);
    assert_true($again['existing']);
    assert_equals($first['ticketId'], $again['ticketId']);
    assert_equals(1, count($repo->tickets));
});

test('daily ticket E2E: interrupted daily linking recovers the persisted deterministic ticket', function () {
    [$repo, , $service] = fx_daily_stack();
    fx_approve_calibration($repo);
    $date = gmdate('Y-m-d', strtotime('+1 day'));
    $first = $service->runDaily($date);
    assert_not_null($first['ticketId']);
    $repo->dailyTickets = []; // simulate crash/deletion after ticket + legs commit

    $again = $service->runDaily($date);
    assert_equals('GENERATED', $again['status']);
    assert_true($again['existing']);
    assert_equals($first['ticketId'], $again['ticketId']);
    assert_equals('GENERATED', $repo->findDailyTicket($date)['generation_status']);
    assert_equals(1, count($repo->tickets), 'recovery links the winner instead of generating a duplicate');
});

test('daily ticket E2E: an active atomic claim reports generation in progress', function () {
    [$repo, , $service] = fx_daily_stack();
    fx_approve_calibration($repo);
    $date = gmdate('Y-m-d', strtotime('+1 day'));
    $claim = $repo->claimDailyTicketGeneration($date, 'ODDS_PREDICTION', 'worker-a', 1, 'UTC', $date . 'T00:00:00+00:00', gmdate('Y-m-d\\T00:00:00+00:00', strtotime($date . ' +1 day')), 900);
    assert_true($claim['claimed']);

    $run = $service->runDaily($date);
    assert_equals('GENERATION_IN_PROGRESS', $run['status']);
    assert_equals('RUNNING', $run['generationStatus']);
    assert_equals(0, count($repo->tickets));
});

test('daily ticket local dates produce DST-safe exclusive UTC windows', function () {
    $kiritimati = AIWorkforce\Sports\DailyTicketDate::utcWindow('2026-09-11', 'Pacific/Kiritimati');
    assert_equals('2026-09-10T10:00:00+00:00', $kiritimati['start']);
    assert_equals('2026-09-11T10:00:00+00:00', $kiritimati['endExclusive']);

    $dst = AIWorkforce\Sports\DailyTicketDate::utcWindow('2026-11-01', 'America/New_York');
    assert_equals(25 * 3600, $dst['endTimestamp'] - $dst['startTimestamp'], 'fall-back local day is 25 hours, not a hard-coded UTC day');
});

test('daily ticket E2E: approval flow with audit attribution', function () {
    [$repo, $audit, $service, $providers] = fx_daily_stack();
    fx_approve_calibration($repo);
    $date = gmdate('Y-m-d', strtotime('+1 day'));
    $run = $service->runDaily($date);
    $governance = new TicketGovernance($repo, $audit, new CorrelationEngine());
    $decision = $governance->decide($run['ticketId'], true, 'admin-9', 'looks right');
    assert_equals('APPROVED_NOT_EXECUTED', $decision['approvalStatus']);
    assert_false($decision['externalExecution']);
    $ticket = $repo->findTicket($run['ticketId']);
    assert_equals('APPROVED', $ticket['status']);
    $ev = array_values(array_filter($audit->events, fn($e) => $e['type'] === 'SPORTS_TICKET_APPROVED'));
    assert_equals(1, count($ev));
    assert_equals('admin-9', $ev[0]['actor']);
    assert_throws(RuntimeException::class, fn() => $governance->decide($run['ticketId'], false, 'admin-9'));
});

test('daily ticket E2E: a fresh install breaks the calibration cold start itself', function () {
    // No calibration seeded: the engine bootstraps the identity calibration
    // (0,1) and auto-approves it as an audited system act, so a fresh
    // installation predicts on day one instead of locking itself out.
    // (An admin REJECTED identity bootstrap still blocks — see case 133.)
    [$repo, $audit, $service] = fx_daily_stack();
    $date = gmdate('Y-m-d', strtotime('+1 day'));
    $run = $service->runDaily($date);
    assert_equals('IDENTITY_AUTO_APPROVED', $run['diagnostics']['calibrationBootstrap'], 'the funnel records the cold-start break');
    assert_equals(0, (int) ($run['rejectionSummary']['MODEL_NOT_CALIBRATED'] ?? 0), 'no calibration rejections on a fresh install');
    assert_equals(5, (int) $run['diagnostics']['predictionsGenerated'], 'all five fixtures predict');
    $approved = $repo->listCalibrations(null, 'APPROVED');
    assert_equals(1, count($approved), 'exactly one approved calibration (the bootstrap)');
    assert_true(
        \AIWorkforce\Sports\CalibrationBootstrap::isIdentityMethod((string) $approved[0]['method']),
        'the approved row is the identity bootstrap (recognised by prefix, so legacy long/truncated markers also pass)'
    );
    assert_equals('system:daily-ticket', (string) $approved[0]['approved_by'], 'the system actor is recorded');
    $types = array_map(fn($e) => $e['type'], $audit->events);
    assert_true(in_array('SPORTS_CALIBRATION_BOOTSTRAPPED', $types, true));
    assert_true(in_array('SPORTS_CALIBRATION_AUTO_APPROVED', $types, true));
});

test('daily ticket E2E: stored fixtures and fresh odds work without another provider pull', function () {
    $repo = new SportsRepositoryStub();
    $audit = fx_daily_audit();
    $source = fx_daily_provider([
        'f0' => [['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 1.55, 'observedAt' => gmdate('c')]],
        'f1' => [['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 1.75, 'observedAt' => gmdate('c')]],
        'f2' => [['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 1.9, 'observedAt' => gmdate('c')]],
        'f3' => [['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 2.1, 'observedAt' => gmdate('c')]],
        'f4' => [['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 2.3, 'observedAt' => gmdate('c')]],
    ]);
    $providerId = (int) $repo->ensureProvider('daily-test', 'daily-test')['id'];
    foreach ($source->fixtures([]) as $fixture) {
        $saved = $repo->saveMatch($providerId, AIWorkforce\Sports\SportsDataNormalizer::fixture($fixture, 'daily-test'));
        foreach ($source->odds((string) $fixture['externalId']) as $odds) {
            $repo->saveOdds((int) $saved['id'], $providerId, AIWorkforce\Sports\SportsDataNormalizer::odds($odds, 'daily-test'));
        }
    }
    fx_approve_calibration($repo);
    $emptyProviders = new SportsProviderManager();
    $config = new ConfigurationService($repo, $audit);
    $pipeline = new PredictionPipeline(new MatchIntelligenceEngine(new OddsFreshnessEngine()), new FeatureEngineeringEngine(), new PredictionEngine(), new ValueEngine(), new RiskEngine(), new CorrelationEngine(), new ConfidenceEngine());
    $service = new DailyTicketService($repo, $audit, $emptyProviders, $config, new DataQualityEngine(), $pipeline, new TicketOptimizer(new CorrelationEngine()), new TicketGovernance($repo, $audit, new CorrelationEngine()), new DecisionRecorder($repo, $audit));

    $run = $service->runDaily(gmdate('Y-m-d', strtotime('+1 day')));
    assert_equals('PENDING_USER_APPROVAL', $run['status']);
    assert_equals('STORED', $run['diagnostics']['fixtureInput']);
    assert_equals(5, $run['evaluated']);
    assert_equals(1, count($repo->tickets));
});

test('daily ticket E2E: no provider configured → DISABLED_NO_PROVIDER, nothing fabricated', function () {
    $repo = new SportsRepositoryStub();
    $audit = fx_daily_audit();
    $providers = new SportsProviderManager();
    $service = new DailyTicketService($repo, $audit, $providers, new ConfigurationService($repo, $audit), new DataQualityEngine(), new PredictionPipeline(), new TicketOptimizer(), new TicketGovernance($repo, $audit), new DecisionRecorder($repo, $audit));
    $run = $service->runDaily(gmdate('Y-m-d'));
    assert_equals('NO_QUALIFIED_TICKET', $run['status']);
    assert_contains('DISABLED_NO_PROVIDER', $run['message']);
    assert_equals(0, count($repo->matches));
    assert_equals(0, count($repo->tickets));
});

test('daily ticket E2E: provider failure is DATA_UNAVAILABLE, never "no qualified games"', function () {
    $repo = new SportsRepositoryStub();
    $audit = fx_daily_audit();
    $providers = new SportsProviderManager();
    $broken = new class implements SportsDataProvider {
        public function id(): string { return 'broken'; }
        public function health(): array { return ['status' => 'ONLINE']; }
        public function fixtures(array $q): array { throw new \AIWorkforce\Sports\Providers\ProviderException('upstream down', \AIWorkforce\Sports\Providers\ProviderException::OFFLINE); }
        public function odds(string $e): array { return []; }
        public function results(string $e): array { return []; }
    };
    $providers->register($broken);
    $service = new DailyTicketService($repo, $audit, $providers, new ConfigurationService($repo, $audit), new DataQualityEngine(), new PredictionPipeline(), new TicketOptimizer(), new TicketGovernance($repo, $audit), new DecisionRecorder($repo, $audit));
    $date = gmdate('Y-m-d');
    $run = $service->runDaily($date);
    assert_equals('DATA_UNAVAILABLE', $run['status'], 'a provider outage is not a prediction outcome');
    assert_equals('DATA_UNAVAILABLE', $run['dataState']);
    assert_contains('all configured sports-data providers failed', $run['message']);
    assert_equals(['broken' => 'OFFLINE'], $run['providerStatuses'], 'per-provider status codes are returned');
    assert_equals(0, $run['evaluated']);
    assert_equals(0, count($repo->tickets));
    // stored row carries the same verdict + the provider ledger
    $daily = $repo->findDailyTicket($date);
    assert_equals('DATA_UNAVAILABLE', $daily['status']);
    assert_equals('RETRYING', $daily['generation_status']);
    assert_equals('SPORTS_PROVIDER_UNAVAILABLE', $daily['last_error_code']);
    assert_true(strtotime((string) $daily['next_retry_at']) > time(), 'temporary provider failure records controlled retry time');
    assert_equals('OFFLINE', $daily['rejection_summary']['PROVIDER:broken'] ?? null);
    // audited as BLOCKED, not as a normal run
    $blocked = array_values(array_filter($audit->events, fn($e) => $e['type'] === 'SPORTS_DAILY_TICKET_BLOCKED'));
    assert_equals(1, count($blocked));
    // the execution key is released: once a provider recovers the SAME day
    // can be re-run instead of being DUPLICATE_SKIPPED until tomorrow.
    $again = $service->runDaily($date);
    assert_not_equals('DUPLICATE_SKIPPED', $again['status'], 'a blocked day must stay retryable');
});

test('daily ticket E2E: a quota-dead primary falls through to a healthy fallback (independent providers)', function () {
    $repo = new SportsRepositoryStub();
    $audit = fx_daily_audit();
    $providers = new SportsProviderManager();
    $quota = new class implements SportsDataProvider {
        public int $healthCalls = 0;
        public int $dataCalls = 0;
        public function id(): string { return 'api-football'; }
        public function health(): array { $this->healthCalls++; return ['status' => 'ONLINE']; }
        public function fixtures(array $q): array { $this->dataCalls++; throw new \AIWorkforce\Sports\Providers\ProviderException('You have reached the request limit for the day', \AIWorkforce\Sports\Providers\ProviderException::DAILY_QUOTA_EXHAUSTED); }
        public function odds(string $e): array { $this->dataCalls++; return []; }
        public function results(string $e): array { $this->dataCalls++; return []; }
    };
    $providers->register($quota);
    $providers->register(fx_daily_provider([
        'f0' => [['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 1.55, 'observedAt' => gmdate('c')]],
        'f1' => [['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 1.75, 'observedAt' => gmdate('c')]],
        'f2' => [['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 1.9, 'observedAt' => gmdate('c')]],
        'f3' => [['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 2.1, 'observedAt' => gmdate('c')]],
        'f4' => [['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 2.3, 'observedAt' => gmdate('c')]],
    ]));
    $config = new ConfigurationService($repo, $audit);
    $pipeline = new PredictionPipeline(new MatchIntelligenceEngine(new OddsFreshnessEngine()), new FeatureEngineeringEngine(), new PredictionEngine(), new ValueEngine(), new RiskEngine(), new CorrelationEngine(), new ConfidenceEngine());
    $service = new DailyTicketService($repo, $audit, $providers, $config, new DataQualityEngine(), $pipeline, new TicketOptimizer(new CorrelationEngine()), new TicketGovernance($repo, $audit, new CorrelationEngine()), new DecisionRecorder($repo, $audit));
    fx_approve_calibration($repo);
    $date = gmdate('Y-m-d', strtotime('+1 day'));
    $run = $service->runDaily($date);
    assert_equals('PENDING_USER_APPROVAL', $run['status'], 'fallback provider served the day');
    assert_equals('daily-test', $run['provider']);
    assert_equals('OK', $run['dataState']);
    assert_equals(5, $run['evaluated']);
    // the quota-dead provider was called exactly once and its circuit is now OPEN until the reset
    $circuit = $providers->breaker()->state('api-football');
    assert_equals('OPEN', $circuit['state']);
    assert_equals('DAILY_QUOTA_EXHAUSTED', $circuit['reason']);
    assert_true($circuit['retryAt'] !== null && strtotime($circuit['retryAt']) > time(), 'circuit opens until the vendor quota reset');
    // subsequent calls do NOT touch the dead provider again — not even a health probe
    $h = $quota->healthCalls; $d = $quota->dataCalls;
    assert_equals(1, $d, 'the quota-dead provider was asked for data exactly once');
    $out = $providers->withFallback('odds', fn($p) => $p->odds('f0'));
    assert_true($out['ok']);
    assert_equals('daily-test', $out['provider']);
    assert_equals($h, $quota->healthCalls, 'no health probe while the circuit is OPEN');
    assert_equals($d, $quota->dataCalls, 'no data request while the circuit is OPEN');
    assert_contains('circuit open', $out['failures']['api-football']);
    // and the dashboard readiness reflects 1/2 operational → READY
    $ready = $providers->readiness();
    assert_equals(1, $ready['operational']);
    assert_equals(2, $ready['total']);
    assert_equals('READY', $ready['engine']);
    assert_equals('DAILY_QUOTA_EXHAUSTED', $ready['providers']['api-football']['status']);
});

/**
 * Deterministic round-capable test provider: 3 fixtures sharing one round
 * (roundId "r1"). round() returns all three OVER_1.5 odds in one call; the
 * per-fixture odds() call is counted so tests can prove the N+1 path was
 * (or was not) taken.
 */
function fx_daily_round_provider(bool $roundFails): SportsDataProvider
{
    $fixtures = [];
    $oddsByExt = [];
    $roundOdds = [];
    $oddsValues = [1.55, 1.75, 1.90];
    for ($i = 0; $i < 3; $i++) {
        $fixtures[] = [
            'externalId' => 'f' . $i,
            'homeTeam' => 'Home' . $i, 'awayTeam' => 'Away' . $i,
            'competition' => 'Test League ' . $i,
            'kickoff' => gmdate('Y-m-d\TH:i:00\+00:00', strtotime('+1 day ' . (10 + $i) . ':00:00')),
            'status' => 'SCHEDULED',
            'roundId' => 'r1',
            'context' => [
                'recentForm' => [
                    'homeGoalsPerMatch' => 1.6, 'awayGoalsPerMatch' => 1.4,
                    'homeConcededPerMatch' => 1.0, 'awayConcededPerMatch' => 0.9,
                    'source' => 'test-verified', 'timestamp' => gmdate('c'),
                ],
                'marketLiquidity' => 50000,
            ],
        ];
        $row = ['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => $oddsValues[$i], 'observedAt' => gmdate('c')];
        $oddsByExt['f' . $i] = [$row];
        $roundOdds[] = array_merge($row, ['bookmaker' => 'test-book', 'fixtureId' => 'f' . $i]);
    }
    return new class($fixtures, $oddsByExt, $roundOdds, $roundFails) implements SportsDataProvider {
        public int $roundCalls = 0;
        public int $oddsCalls = 0;
        public function __construct(private array $fixtures, private array $odds, private array $roundOdds, private bool $roundFails) {}
        public function id(): string { return 'daily-round-test'; }
        public function health(): array { return ['status' => 'ONLINE', 'reliability' => 0.9]; }
        public function fixtures(array $q): array { return $this->fixtures; }
        public function round(string $roundId): array
        {
            $this->roundCalls++;
            if ($this->roundFails) throw new \AIWorkforce\Sports\Providers\ProviderException('round endpoint down', \AIWorkforce\Sports\Providers\ProviderException::DATA_ERROR);
            return [
                'roundId' => $roundId, 'name' => '1', 'leagueId' => '1', 'league' => 'Test League',
                'season' => '2026', 'startingAt' => null, 'endingAt' => null, 'finished' => false,
                'fixtures' => $this->fixtures, 'odds' => $this->roundOdds, 'results' => [],
            ];
        }
        public function odds(string $e): array { $this->oddsCalls++; return $this->odds[$e] ?? []; }
        public function results(string $e): array { return []; }
    };
}

function fx_daily_round_stack(bool $roundFails): array
{
    $repo = new SportsRepositoryStub();
    $audit = fx_daily_audit();
    $provider = fx_daily_round_provider($roundFails);
    $providers = new SportsProviderManager();
    $providers->register($provider);
    $config = new ConfigurationService($repo, $audit);
    $quality = new DataQualityEngine();
    $pipeline = new PredictionPipeline(new MatchIntelligenceEngine(new OddsFreshnessEngine()), new FeatureEngineeringEngine(), new PredictionEngine(), new ValueEngine(), new RiskEngine(), new CorrelationEngine(), new ConfidenceEngine());
    $governance = new TicketGovernance($repo, $audit, new CorrelationEngine());
    $service = new DailyTicketService($repo, $audit, $providers, $config, $quality, $pipeline, new TicketOptimizer(new CorrelationEngine()), $governance, new DecisionRecorder($repo, $audit));
    return [$repo, $audit, $service, $provider];
}

test('daily ticket E2E: bulk round odds replaces per-fixture odds calls', function () {
    [$repo, $audit, $service, $provider] = fx_daily_round_stack(false);
    fx_approve_calibration($repo);
    $date = gmdate('Y-m-d', strtotime('+1 day'));
    $run = $service->runDaily($date);
    assert_equals('PENDING_USER_APPROVAL', $run['status']);
    assert_equals(3, $run['evaluated']);
    assert_equals(1, $provider->roundCalls, 'one round() call for the whole matchday');
    assert_equals(0, $provider->oddsCalls, 'no per-fixture odds() calls when the round covers all fixtures');
    assert_not_null($run['ticketId']);
    $ticket = $repo->findTicket($run['ticketId']);
    assert_true($ticket['total_odds'] >= 5.0 && $ticket['total_odds'] <= 8.0, 'odds inside configured range');
    // odds were persisted for every evaluated match via the bulk fetch
    assert_true(count($repo->odds) >= 3, 'bulk round odds persisted');
});

test('daily ticket E2E: round failure falls back to per-fixture odds', function () {
    [$repo, $audit, $service, $provider] = fx_daily_round_stack(true);
    fx_approve_calibration($repo);
    $date = gmdate('Y-m-d', strtotime('+1 day'));
    $run = $service->runDaily($date);
    assert_equals('PENDING_USER_APPROVAL', $run['status']);
    assert_equals(1, $provider->roundCalls, 'round attempted once per matchday');
    assert_equals(3, $provider->oddsCalls, 'per-fixture odds used after the round failure');
    assert_not_null($run['ticketId']);
    $bulkFailures = array_values(array_filter($run['errors'], fn($e) => str_contains($e, 'bulk odds fetch failed')));
    assert_equals(1, count($bulkFailures), 'round failure is reported, not hidden');
});

test('daily ticket E2E: engine mode VIEW_ONLY never generates tickets', function () {
    [$repo, $audit, $service] = fx_daily_stack();
    (new ConfigurationService($repo, $audit))->update(['engine_mode' => 'VIEW_ONLY'], 'admin', 'view only');
    $run = $service->runDaily(gmdate('Y-m-d', strtotime('+1 day')));
    assert_equals('NO_QUALIFIED_TICKET', $run['status']);
    assert_contains('VIEW_ONLY', $run['message']);
    assert_equals(0, count($repo->tickets));
});
