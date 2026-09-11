<?php
/**
 * Regression: the "50 evaluated → 0 eligible" no-ticket day.
 *
 * A stored fixture page is read kickoff-ASC and capped at one generation batch
 * (50). When intake started at the ticket day's 00:00, an afternoon run spent
 * the whole page on matches that had already kicked off or start inside the
 * two-hour eligibility lead — every one rejected FIXTURE_NOT_NS_OR_TOO_SOON —
 * while the day's genuinely predictable evening fixtures sat unread behind the
 * page. These tests pin the intake floor, the eligibility-first batch ordering
 * and the honest timing diagnosis.
 */
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

function fx143_audit(): AuditRepository
{
    return new class implements AuditRepository { public array $events = []; public function emit(string $t, string $s, array $d = [], string $a = 'system'): void { $this->events[] = ['type' => $t, 'actor' => $a, 'detail' => $d]; } public function recent(int $l = 100): array { return []; } };
}

/** A provider that serves odds for stored fixtures, and optionally a live fixture list. */
function fx143_provider(array $oddsByExt, array $fixtures = []): SportsDataProvider
{
    return new class($oddsByExt, $fixtures) implements SportsDataProvider {
        public int $fixtureCalls = 0;
        public function __construct(private array $odds, private array $liveFixtures) {}
        public function id(): string { return 'intake-test'; }
        public function health(): array { return ['status' => 'ONLINE', 'reliability' => 0.9]; }
        public function fixtures(array $q): array { $this->fixtureCalls++; return $this->liveFixtures; }
        public function odds(string $e): array { return $this->odds[$e] ?? []; }
        public function results(string $e): array { return []; }
    };
}

/** A provider-shaped fixture row (the shape fixtures() returns). */
function fx143_raw(string $externalId, int $kickoffTs): array
{
    return [
        'externalId' => $externalId,
        'sport' => 'football',
        'competition' => 'Intake League',
        'homeTeam' => 'Home ' . $externalId, 'awayTeam' => 'Away ' . $externalId,
        'kickoff' => gmdate('Y-m-d\TH:i:00\+00:00', $kickoffTs),
        'status' => 'SCHEDULED', 'sourceStatus' => 'NS',
        'sourceTimestamp' => gmdate('c'),
    ];
}

/** Store one SCHEDULED fixture row directly, exactly as a prior sync would have. */
function fx143_store(SportsRepositoryStub $repo, int $providerId, string $externalId, int $kickoffTs, bool $withForm = true): void
{
    $kickoff = gmdate('Y-m-d\TH:i:00\+00:00', $kickoffTs);
    $payload = [
        'externalId' => $externalId,
        'sport' => 'football',
        'competition' => 'Intake League',
        'homeTeam' => 'Home ' . $externalId, 'awayTeam' => 'Away ' . $externalId,
        'kickoff' => $kickoff,
        'status' => 'SCHEDULED', 'sourceStatus' => 'NS',
        'sourceTimestamp' => gmdate('c'),
    ];
    if ($withForm) {
        $payload['context'] = [
            'recentForm' => [
                'homeGoalsPerMatch' => 1.6, 'awayGoalsPerMatch' => 1.4,
                'homeConcededPerMatch' => 1.0, 'awayConcededPerMatch' => 0.9,
                'source' => 'test-verified', 'timestamp' => gmdate('c'),
            ],
            'marketLiquidity' => 50000,
        ];
    }
    $repo->saveMatch($providerId, $payload);
}

function fx143_service(SportsRepositoryStub $repo, AuditRepository $audit, SportsProviderManager $providers): DailyTicketService
{
    $pipeline = new PredictionPipeline(new MatchIntelligenceEngine(new OddsFreshnessEngine()), new FeatureEngineeringEngine(), new PredictionEngine(), new ValueEngine(), new RiskEngine(), new CorrelationEngine(), new ConfidenceEngine());
    return new DailyTicketService($repo, $audit, $providers, new ConfigurationService($repo, $audit), new DataQualityEngine(), $pipeline, new TicketOptimizer(new CorrelationEngine()), new TicketGovernance($repo, $audit, new CorrelationEngine()), new DecisionRecorder($repo, $audit));
}

function fx143_approve_calibration(SportsRepositoryStub $repo): void
{
    $modelId = $repo->ensureModelVersion(['modelName' => PredictionEngine::MODEL_NAME, 'modelVersion' => PredictionEngine::MODEL_VERSION, 'featureVersion' => FeatureEngineeringEngine::VERSION]);
    $repo->saveCalibration(['model_version_id' => $modelId, 'method' => 'platt', 'intercept' => 0.2, 'slope' => 1.5, 'samples' => 40, 'ece' => 0.02, 'status' => 'APPROVED', 'created_by' => 'admin', 'created_at' => gmdate('c')]);
}

test('intake window: the eligibility lead is one shared constant', function () {
    assert_equals(2 * 3600, DailyTicketService::ELIGIBILITY_LEAD_SECONDS, 'two-hour lead');
    assert_equals(50, DailyTicketService::MAX_GENERATION_CEILING, '50-fixture generation batch');
});

test('intake window: a full page of started/too-soon fixtures no longer hides the eligible ones', function () {
    $repo = new SportsRepositoryStub();
    $audit = fx143_audit();
    fx143_approve_calibration($repo);

    // 50 fixtures earlier today that already kicked off or start inside the
    // two-hour lead — exactly enough to fill the generation batch on their own.
    $providerId = (int) $repo->ensureProvider('intake-test', 'intake-test')['id'];
    $now = time();
    for ($i = 0; $i < 50; $i++) {
        fx143_store($repo, $providerId, 'past' . $i, $now - ($i + 1) * 600);
    }
    // …and five genuinely predictable fixtures later in the window.
    $oddsByExt = [];
    $prices = [1.55, 1.75, 1.90, 2.10, 2.30];
    for ($i = 0; $i < 5; $i++) {
        fx143_store($repo, $providerId, 'later' . $i, $now + (5 + $i) * 3600);
        $oddsByExt['later' . $i] = [['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => $prices[$i], 'observedAt' => gmdate('c')]];
    }

    $providers = new SportsProviderManager();
    $providers->register(fx143_provider($oddsByExt));
    $run = fx143_service($repo, $audit, $providers)->runDaily(gmdate('Y-m-d'));

    $diag = $run['diagnostics'] ?? [];
    // The eligible fixtures must have reached the first gate: before the fix
    // the page was 100% past fixtures and this was 0.
    assert_true((int) ($diag['eligibleFixtures'] ?? 0) > 0, 'later fixtures reached the eligibility gate, not just the expired ones');
    assert_true((int) ($diag['fixturesEvaluated'] ?? 0) > 0, 'fixtures were evaluated');
    // And none of the expired rows was read at all: the batch is spent on
    // fixtures that can still win a ticket.
    assert_equals(0, (int) ($run['rejectionSummary']['FIXTURE_NOT_NS_OR_TOO_SOON'] ?? 0), 'expired fixtures never consumed a batch slot');
});

test('intake window: a live provider page orders ticket-eligible fixtures into the batch first', function () {
    $repo = new SportsRepositoryStub();
    $audit = fx143_audit();
    fx143_approve_calibration($repo);

    // A worldwide provider page: 50 already-started matches arriving BEFORE the
    // eligible ones. Sorting by kickoff alone would fill the whole 50-fixture
    // batch with the expired rows and defer every predictable fixture.
    $now = time();
    $fixtures = [];
    for ($i = 0; $i < 50; $i++) $fixtures[] = fx143_raw('gone' . $i, $now - ($i + 1) * 600);
    $oddsByExt = [];
    $prices = [1.55, 1.75, 1.90, 2.10, 2.30];
    for ($i = 0; $i < 5; $i++) {
        $raw = fx143_raw('live' . $i, $now + (5 + $i) * 3600);
        $raw['context'] = [
            'recentForm' => [
                'homeGoalsPerMatch' => 1.6, 'awayGoalsPerMatch' => 1.4,
                'homeConcededPerMatch' => 1.0, 'awayConcededPerMatch' => 0.9,
                'source' => 'test-verified', 'timestamp' => gmdate('c'),
            ],
            'marketLiquidity' => 50000,
        ];
        $fixtures[] = $raw;
        $oddsByExt['live' . $i] = [['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => $prices[$i], 'observedAt' => gmdate('c')]];
    }

    $providers = new SportsProviderManager();
    $providers->register(fx143_provider($oddsByExt, $fixtures));
    // refreshFixtures forces the live provider path rather than stored intake.
    $run = fx143_service($repo, $audit, $providers)->runDaily(gmdate('Y-m-d'), null, ['refreshFixtures' => true]);

    assert_true((int) ($run['diagnostics']['eligibleFixtures'] ?? 0) >= 5,
        'all five eligible fixtures made the batch despite 50 expired rows arriving first');
});

test('intake window: an all-expired day is diagnosed as timing, not as a failed model gate', function () {
    $repo = new SportsRepositoryStub();
    $audit = fx143_audit();
    fx143_approve_calibration($repo);

    // Every stored fixture of the window is inside the lead, so the honest
    // verdict is "nothing was still eligible" — never "no candidate passed the
    // confidence/value/correlation gates", which never ran.
    $providerId = (int) $repo->ensureProvider('intake-test', 'intake-test')['id'];
    $now = time();
    for ($i = 0; $i < 6; $i++) {
        fx143_store($repo, $providerId, 'soon' . $i, $now + 900 + $i * 60);
    }

    $providers = new SportsProviderManager();
    $providers->register(fx143_provider([]));
    $run = fx143_service($repo, $audit, $providers)->runDaily(gmdate('Y-m-d'));

    assert_equals(0, (int) ($run['diagnostics']['eligibleFixtures'] ?? 0), 'nothing was eligible');
    assert_null($run['ticketId'], 'no ticket is fabricated');
    $message = (string) ($run['message'] ?? '');
    if ((int) ($run['rejectionSummary']['FIXTURE_NOT_NS_OR_TOO_SOON'] ?? 0) > 0) {
        assert_true(str_contains($message, 'ticket-eligible'), 'message names the eligibility/timing dead end: ' . $message);
        assert_true(str_contains($message, 'lead the engine requires'), 'message names the required lead: ' . $message);
        assert_false(str_contains($message, 'did not meet the configured prediction requirements'),
            'a timing dead end is not reported as a failed prediction gate: ' . $message);
    }
});
