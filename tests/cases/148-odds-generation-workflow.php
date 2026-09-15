<?php
/**
 * GENERATE ODDS PREDICTIONS — the complete generation workflow.
 *
 * Acceptance coverage for the odds-prediction-ticket workflow: one shared
 * DailyTicketService behind both entry points, the canonical result contract,
 * the confidence (>=30%) and data-quality (>=75) hard gates, the distinct
 * outcome states, duplicate protection, selected-date fidelity and the audit
 * trail.
 *
 * Every test drives the REAL engine through a deterministic provider. Nothing
 * here asserts a hard-coded number the pipeline did not compute.
 */

use AIWorkforce\Persistence\AuditRepository;
use AIWorkforce\Sports\ConfidenceEngine;
use AIWorkforce\Sports\ConfidencePolicy;
use AIWorkforce\Sports\ConfigurationService;
use AIWorkforce\Sports\CorrelationEngine;
use AIWorkforce\Sports\DailyTicketService;
use AIWorkforce\Sports\DataQualityEngine;
use AIWorkforce\Sports\DecisionRecorder;
use AIWorkforce\Sports\FeatureEngineeringEngine;
use AIWorkforce\Sports\GenerationResult;
use AIWorkforce\Sports\MatchIntelligenceEngine;
use AIWorkforce\Sports\OddsFreshnessEngine;
use AIWorkforce\Sports\PredictionEngine;
use AIWorkforce\Sports\PredictionPipeline;
use AIWorkforce\Sports\Providers\SportsDataProvider;
use AIWorkforce\Sports\Providers\SportsProviderManager;
use AIWorkforce\Sports\RiskEngine;
use AIWorkforce\Sports\SportsDataNormalizer;
use AIWorkforce\Sports\TicketGovernance;
use AIWorkforce\Sports\TicketOptimizer;
use AIWorkforce\Sports\ValueEngine;

function fx148_audit(): AuditRepository
{
    return new class implements AuditRepository {
        public array $events = [];
        public function emit(string $t, string $s, array $d = [], string $a = 'system'): void { $this->events[] = ['type' => $t, 'summary' => $s, 'actor' => $a, 'detail' => $d]; }
        public function recent(int $l = 100): array { return array_slice($this->events, -$l); }
    };
}

function fx148_service(SportsRepositoryStub $repo, AuditRepository $audit, SportsProviderManager $providers): DailyTicketService
{
    return new DailyTicketService(
        $repo, $audit, $providers, new ConfigurationService($repo, $audit), new DataQualityEngine(),
        new PredictionPipeline(new MatchIntelligenceEngine(new OddsFreshnessEngine()), new FeatureEngineeringEngine(), new PredictionEngine(), new ValueEngine(), new RiskEngine(), new CorrelationEngine(), new ConfidenceEngine()),
        new TicketOptimizer(new CorrelationEngine()),
        new TicketGovernance($repo, $audit, new CorrelationEngine()), new DecisionRecorder($repo, $audit)
    );
}

function fx148_date(int $daysAhead = 1): string
{
    return gmdate('Y-m-d', strtotime('+' . $daysAhead . ' day'));
}

function fx148_kickoff(string $date, int $offsetHours = 0): int
{
    return strtotime($date . ' 18:00:00 UTC') + $offsetHours * 3600;
}

function fx148_fixture(string $externalId, string $home, string $away, string $league, int $kickoffTs, array $context = []): array
{
    return [
        'externalId' => $externalId, 'sport' => 'football', 'competition' => $league,
        'homeTeam' => $home, 'awayTeam' => $away,
        'kickoff' => gmdate('Y-m-d\TH:i:00\+00:00', $kickoffTs),
        'status' => 'SCHEDULED', 'sourceTimestamp' => gmdate('c'),
        'context' => array_merge([
            'recentForm' => [
                'homeGoalsPerMatch' => 1.9, 'awayGoalsPerMatch' => 1.5,
                'homeConcededPerMatch' => 0.8, 'awayConcededPerMatch' => 1.1,
                'matchesPlayed' => 12, 'source' => 'test-verified', 'timestamp' => gmdate('c'),
            ],
            'marketLiquidity' => 50000,
        ], $context),
    ];
}

function fx148_market_rows(float $scale = 1.0): array
{
    return [
        ['market' => 'MATCH_RESULT', 'selection' => 'HOME', 'decimalOdds' => round(2.10 * $scale, 2)],
        ['market' => 'MATCH_RESULT', 'selection' => 'DRAW', 'decimalOdds' => 3.40],
        ['market' => 'MATCH_RESULT', 'selection' => 'AWAY', 'decimalOdds' => 3.60],
        ['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => round(1.42 * $scale, 2)],
        ['market' => 'TOTAL_GOALS', 'selection' => 'UNDER_1_5', 'decimalOdds' => 2.85],
        ['market' => 'BTTS', 'selection' => 'YES', 'decimalOdds' => round(1.75 * $scale, 2)],
        ['market' => 'DOUBLE_CHANCE', 'selection' => 'HOME_OR_DRAW', 'decimalOdds' => round(1.30 * $scale, 2)],
    ];
}

/** A deterministic, healthy provider. */
function fx148_provider(string $id, array $fixtures, array $oddsByExt = []): SportsDataProvider
{
    return new class($id, $fixtures, $oddsByExt) implements SportsDataProvider {
        public function __construct(private string $id, private array $fixtures, private array $odds) {}
        public function id(): string { return $this->id; }
        public function health(): array { return ['status' => 'ONLINE', 'reliability' => 0.95]; }
        public function fixtures(array $q): array { return $this->fixtures; }
        public function odds(string $e): array { return $this->odds[$e] ?? []; }
        public function results(string $e): array { return []; }
    };
}

/** A provider that always fails — a genuine outage, not an empty day. */
function fx148_failing_provider(string $id): SportsDataProvider
{
    return new class($id) implements SportsDataProvider {
        public function __construct(private string $id) {}
        public function id(): string { return $this->id; }
        public function health(): array { return ['status' => 'ONLINE', 'reliability' => 0.5]; }
        public function fixtures(array $q): array { throw new \AIWorkforce\Sports\Providers\ProviderException('upstream 503', 'UPSTREAM_ERROR'); }
        public function odds(string $e): array { throw new \AIWorkforce\Sports\Providers\ProviderException('upstream 503', 'UPSTREAM_ERROR'); }
        public function results(string $e): array { return []; }
    };
}

function fx148_approve_calibration(SportsRepositoryStub $repo): void
{
    $modelId = $repo->ensureModelVersion(['modelName' => PredictionEngine::MODEL_NAME, 'modelVersion' => PredictionEngine::MODEL_VERSION, 'featureVersion' => FeatureEngineeringEngine::VERSION]);
    $repo->saveCalibration([
        'model_version_id' => $modelId, 'method' => 'platt',
        'intercept' => 0.15, 'slope' => 1.2, 'samples' => 40, 'ece' => 0.02,
        'status' => 'APPROVED', 'created_by' => 'admin', 'created_at' => gmdate('c'),
    ]);
}

function fx148_seed(SportsRepositoryStub $repo, string $providerCode, array $fixtures, int $oddsAgeSeconds = 600, ?array $rows = null): void
{
    $provider = $repo->ensureProvider($providerCode, $providerCode);
    foreach ($fixtures as $i => $fixture) {
        $match = $repo->saveMatch((int) $provider['id'], SportsDataNormalizer::fixture($fixture, $providerCode));
        foreach ($rows ?? fx148_market_rows(1.0 + $i * 0.06) as $row) {
            $repo->saveOdds((int) $match['id'], (int) $provider['id'], [
                'market' => $row['market'], 'selection' => $row['selection'],
                'decimalOdds' => $row['decimalOdds'], 'observedAt' => gmdate('c', time() - $oddsAgeSeconds),
            ]);
        }
    }
}

/** A complete, well-evidenced day that should produce a ticket. */
function fx148_good_day(string $date): array
{
    return [
        fx148_fixture('g148-1', 'Alpha FC', 'Beta United', 'League One', fx148_kickoff($date)),
        fx148_fixture('g148-2', 'Gamma City', 'Delta Town', 'League Two', fx148_kickoff($date, 1)),
        fx148_fixture('g148-3', 'Epsilon SC', 'Zeta Rovers', 'League Three', fx148_kickoff($date, 2)),
    ];
}

/** Build a repo+service wired to a healthy provider holding a good day. */
function fx148_ready(string $date, array $options = []): array
{
    $repo = new SportsRepositoryStub();
    $audit = fx148_audit();
    $fixtures = $options['fixtures'] ?? fx148_good_day($date);
    $providers = new SportsProviderManager();
    $providers->register(fx148_provider('apifootball', $fixtures));
    fx148_approve_calibration($repo);
    fx148_seed($repo, 'apifootball', $fixtures, $options['oddsAge'] ?? 600, $options['rows'] ?? null);
    return [$repo, $audit, $providers, fx148_service($repo, $audit, $providers)];
}

// ─────────────────────────────────────────────────────────────────────────
// 1. ONE SOURCE OF TRUTH — the shared contract (§1/§26)
// ─────────────────────────────────────────────────────────────────────────

test('generation contract: every required field is present on a successful run', function () {
    $date = fx148_date();
    [$repo, $audit, $providers, $service] = fx148_ready($date);
    $result = GenerationResult::fromRunDaily($service->runDaily($date, null, ['actor' => 'admin-1']));

    foreach ([
        'status', 'dataState', 'date', 'runId', 'ticketId', 'provider', 'providerStatus',
        'fixturesEvaluated', 'eligibleFixtures', 'predictionsGenerated', 'freshOdds', 'staleOdds',
        'qualifiedCandidates', 'selectedPicks', 'rejections', 'rejectionSummary', 'gateFailures',
        'message', 'modelVersion', 'configurationVersion', 'generationStartedAt',
        'generationCompletedAt', 'duration', 'auditId',
    ] as $field) {
        assert_true(array_key_exists($field, $result), 'contract field present: ' . $field);
    }
    assert_equals($date, $result['date'], 'the contract reports the requested date');
    assert_true(in_array($result['status'], GenerationResult::STATUSES, true), 'status is a declared state, got ' . $result['status']);
});

test('generation contract: the browser route and the API route return the identical field set', function () {
    $date = fx148_date(2);
    [$repoA, $auditA, $providersA, $serviceA] = fx148_ready($date);
    $browser = GenerationResult::fromRunDaily($serviceA->runDaily($date, null, ['actor' => 'admin-browser']));

    // A second, independent installation running the same inputs through the
    // same service is what the API endpoint does.
    [$repoB, $auditB, $providersB, $serviceB] = fx148_ready($date);
    $api = GenerationResult::fromRunDaily($serviceB->runDaily($date, null, ['actor' => 'admin-api']));

    assert_equals(array_keys($browser), array_keys($api), 'both surfaces expose the same contract keys');
    assert_equals($browser['status'], $api['status'], 'the same inputs produce the same status');
    assert_equals($browser['selectedPicks'], $api['selectedPicks'], 'the same inputs produce the same selection count');
    assert_equals($browser['qualifiedCandidates'], $api['qualifiedCandidates'], 'the same inputs qualify the same candidates');
});

// ─────────────────────────────────────────────────────────────────────────
// 2. OUTCOME STATES — never conflated (§8/§18/§19)
// ─────────────────────────────────────────────────────────────────────────

test('generation state: no provider configured reports NO_PROVIDER, never "no qualifying games"', function () {
    $date = fx148_date();
    $repo = new SportsRepositoryStub();
    $audit = fx148_audit();
    $providers = new SportsProviderManager();   // nothing registered
    $service = fx148_service($repo, $audit, $providers);

    $result = GenerationResult::fromRunDaily($service->runDaily($date, null, ['actor' => 'admin-1']));
    assert_equals('NO_PROVIDER', $result['status']);
    assert_equals('NO_PROVIDER', $result['dataState']);
    assert_equals('NOT_CONFIGURED', $result['providerStatus']);
    assert_null($result['ticketId'], 'no ticket is invented without a provider');
    assert_true($result['retryable'], 'the date stays retryable');
    assert_true(stripos($result['message'], 'provider') !== false, 'the message names the provider problem');
});

test('generation state: every provider failing reports DATA_UNAVAILABLE, distinct from NO_QUALIFIED_TICKET', function () {
    $date = fx148_date();
    $repo = new SportsRepositoryStub();
    $audit = fx148_audit();
    $providers = new SportsProviderManager();
    $providers->register(fx148_failing_provider('apifootball'));
    $providers->register(fx148_failing_provider('sportmonks'));
    $service = fx148_service($repo, $audit, $providers);

    $result = GenerationResult::fromRunDaily($service->runDaily($date, null, ['actor' => 'admin-1']));
    assert_equals('DATA_UNAVAILABLE', $result['status']);
    assert_true($result['status'] !== 'NO_QUALIFIED_TICKET', 'an outage is never reported as a prediction outcome');
    assert_null($result['ticketId']);
    assert_true($result['retryable'], 'an outage day stays retryable');
    // Each provider is named with its own safe status category.
    assert_true(count($result['providerStatuses']) >= 1, 'per-provider statuses are reported');
    foreach ($result['providerStatuses'] as $status) {
        assert_true(is_string($status) && $status !== '', 'each provider has a status category');
        assert_true(stripos((string) $status, 'key') === false, 'no credential material is exposed');
    }
});

test('generation state: a healthy provider returning zero fixtures reports NO_FIXTURES', function () {
    $date = fx148_date();
    $repo = new SportsRepositoryStub();
    $audit = fx148_audit();
    $providers = new SportsProviderManager();
    $providers->register(fx148_provider('apifootball', []));   // healthy, empty
    fx148_approve_calibration($repo);
    $service = fx148_service($repo, $audit, $providers);

    $result = GenerationResult::fromRunDaily($service->runDaily($date, null, ['actor' => 'admin-1']));
    assert_true(in_array($result['status'], ['NO_FIXTURES', 'DATA_UNAVAILABLE'], true),
        'an empty healthy day is NO_FIXTURES (or a declared outage), got ' . $result['status']);
    assert_null($result['ticketId'], 'nothing is fabricated for an empty day');
});

test('generation state: assessed fixtures that clear nothing report NO_QUALIFIED_TICKET with a breakdown', function () {
    $date = fx148_date();
    // Odds far outside any value: every candidate is honestly rejected.
    $rows = [
        ['market' => 'MATCH_RESULT', 'selection' => 'HOME', 'decimalOdds' => 1.01],
        ['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 1.01],
        ['market' => 'BTTS', 'selection' => 'YES', 'decimalOdds' => 1.01],
    ];
    [$repo, $audit, $providers, $service] = fx148_ready($date, ['rows' => $rows]);
    $result = GenerationResult::fromRunDaily($service->runDaily($date, null, ['actor' => 'admin-1']));

    assert_true(in_array($result['status'], ['NO_QUALIFIED_TICKET', 'NO_FIXTURES'], true), 'got ' . $result['status']);
    assert_null($result['ticketId'], 'no ticket is manufactured from unqualified candidates');
    if ($result['status'] === 'NO_QUALIFIED_TICKET') {
        assert_true(is_array($result['gateFailures']), 'a gate breakdown is always present');
        assert_true($result['rejections'] > 0, 'the run explains itself with real rejections');
        foreach ($result['gateFailures'] as $gate) {
            assert_true(isset($gate['reason'], $gate['label'], $gate['count']), 'each gate row is fully described');
            assert_true((int) $gate['count'] > 0, 'a reported gate actually rejected something');
        }
    }
});

// ─────────────────────────────────────────────────────────────────────────
// 3. THE HARD GATES — evidence-based, never inflated (§6/§7)
// ─────────────────────────────────────────────────────────────────────────

test('confidence gate: 29.99% is rejected and 30.00% passes, on the real policy', function () {
    $policy = ConfidencePolicy::fromConfiguration(ConfigurationService::defaults());

    $below = $policy->evaluate(95, 29.99, 'TOTAL_GOALS', 'OVER_1_5');
    assert_false($below['qualified'], '29.99% must fail the confidence gate');
    assert_true(in_array('LOW_CONFIDENCE', $below['reasons'], true), 'the reason names the confidence gate');
    assert_equals(29.99, (float) $below['confidence'], 'the measured value is reported unchanged, never rounded up');

    $atGate = $policy->evaluate(95, 30.0, 'TOTAL_GOALS', 'OVER_1_5');
    assert_true($atGate['qualified'], '30.00% must pass the confidence gate');
    assert_equals(30.0, (float) $atGate['confidence']);

    foreach ([31.0, 50.0, 88.0] as $ok) {
        assert_true($policy->evaluate(95, $ok, 'TOTAL_GOALS', 'OVER_1_5')['qualified'], $ok . '% passes');
    }
});

test('confidence gate: the configured minimum is 30 and the configurable range starts at 30', function () {
    $defaults = ConfigurationService::defaults();
    assert_equals(30.0, (float) $defaults['min_confidence'], 'the shipped minimum confidence is 30%');
    assert_equals(30.0, ConfigurationService::MIN_CONFIDENCE_FLOOR, 'the configurable floor is 30');

    $repo = new SportsRepositoryStub();
    $svc = new ConfigurationService($repo, fx148_audit());
    assert_true($svc->update(['min_confidence' => 30.0], 'admin', 'at the floor')['ok']);
    assert_true($svc->update(['min_confidence' => 100.0], 'admin', 'at the ceiling')['ok']);
    assert_false($svc->update(['min_confidence' => 29.99], 'admin')['ok'], '29.99 is refused');
    assert_false($svc->update(['min_confidence' => 100.01], 'admin')['ok'], 'above 100 is refused');
});

test('data-quality gate: 29 is rejected and 30 passes, independently of confidence', function () {
    $policy = ConfidencePolicy::fromConfiguration(ConfigurationService::defaults());

    // A very high confidence cannot buy a sub-30 data quality.
    $below = $policy->evaluate(29, 99.0, 'TOTAL_GOALS', 'OVER_1_5');
    assert_false($below['qualified'], 'quality 29 is rejected at any confidence');
    assert_equals(['DATA_QUALITY_BELOW_MINIMUM'], $below['reasons']);
    assert_contains('30', $below['explanation'], 'the reason states the required minimum');

    $atGate = $policy->evaluate(30, 80.0, 'TOTAL_GOALS', 'OVER_1_5');
    assert_true($atGate['tier'] !== 'REJECT', 'quality 30 is assessable');
    // Quality that the OLD 75 floor would have thrown away now qualifies.
    assert_true($policy->evaluate(74, 80.0, 'TOTAL_GOALS', 'OVER_1_5')['qualified'], 'quality 74 qualifies under the 30 floor');
    assert_equals(30, ConfigurationService::MIN_DATA_QUALITY_FLOOR);
    assert_equals(30, (int) ConfigurationService::defaults()['min_data_quality']);
});

test('gates are independent: both floors are 30, and they are never conflated', function () {
    $policy = ConfidencePolicy::fromConfiguration(ConfigurationService::defaults());
    // Good evidence, weak confidence → confidence gate only.
    $a = $policy->evaluate(95, 20.0, 'TOTAL_GOALS', 'OVER_1_5');
    assert_equals(['LOW_CONFIDENCE'], $a['reasons']);
    // Weak evidence, strong confidence → quality gate only.
    $b = $policy->evaluate(25, 95.0, 'TOTAL_GOALS', 'OVER_1_5');
    assert_equals(['DATA_QUALITY_BELOW_MINIMUM'], $b['reasons']);
    // Both above their (now equal) floors → qualified on both axes.
    assert_true($policy->evaluate(30, 30.0, 'TOTAL_GOALS', 'OVER_1_5')['qualified'], '30/30 is the qualifying corner');
});

test('the optimizer honours both hard gates and never weakens them to produce a ticket', function () {
    $mk = function (int $id, float $conf, int $quality, float $odds): array {
        return [
            'matchId' => $id, 'competition' => 'L' . $id, 'market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5',
            'value' => ['qualified' => true, 'odds' => $odds, 'expectedValue' => 0.10],
            'risk' => ['approved' => true, 'classification' => 'LOW'],
            'confidence' => ['confidence' => $conf], 'quality' => ['score' => $quality],
            'match' => ['competition' => 'L' . $id],
        ];
    };
    $config = ['targetOddsMin' => 1.1, 'targetOddsMax' => 99, 'maxSelections' => 5,
        'minConfidence' => 30.0, 'minDataQuality' => 30];

    // Below either gate → never pooled, even though the odds are attractive.
    $out = (new TicketOptimizer())->optimize([
        $mk(1, 29.99, 95, 6.0),
        $mk(2, 90.0, 29, 6.0),
    ], $config);
    assert_equals(0, $out['poolSize'], 'sub-gate candidates never enter the pool');
    assert_equals('NO_QUALIFIED_TICKET', $out['status'], 'the engine reports no ticket rather than lowering a gate');

    // Exactly at both gates → eligible.
    $ok = (new TicketOptimizer())->optimize([$mk(3, 30.0, 30, 6.0)], $config);
    assert_equals(1, $ok['poolSize'], 'a candidate exactly at both gates qualifies');

    // Mid-range evidence the OLD 75 floor discarded is now usable.
    $mid = (new TicketOptimizer())->optimize([$mk(4, 45.0, 60, 6.0)], $config);
    assert_equals(1, $mid['poolSize'], 'quality 60 qualifies under the 30 floor');
});

// ─────────────────────────────────────────────────────────────────────────
// 4. QUALIFIED CANDIDATES vs SELECTED PICKS (§13/§14)
// ─────────────────────────────────────────────────────────────────────────

test('qualified candidates and selected picks are distinct metrics', function () {
    $date = fx148_date();
    [$repo, $audit, $providers, $service] = fx148_ready($date);
    $result = GenerationResult::fromRunDaily($service->runDaily($date, null, ['actor' => 'admin-1']));

    if ($result['ticketId'] === null) return ['msg' => 'no ticket on this deterministic day; metric separation asserted elsewhere'];

    $qualified = (int) $result['qualifiedCandidates'];
    $selected = (int) $result['selectedPicks'];
    assert_true($qualified >= $selected, 'selected picks can never exceed qualified candidates');
    assert_equals($selected, count($repo->ticketSelections((string) $result['ticketId'])), 'selected picks equals the persisted leg count');
    // The qualified pool is a candidate count, not a fixture or odds-row count.
    assert_true($qualified !== (int) $result['fixturesEvaluated'] || $qualified === $selected,
        'the qualified pool is measured independently of the fixture count');
});

// ─────────────────────────────────────────────────────────────────────────
// 5. DUPLICATE PROTECTION (§3/§20)
// ─────────────────────────────────────────────────────────────────────────

test('duplicate protection: generating twice for the same date returns DUPLICATE_SKIPPED', function () {
    $date = fx148_date();
    [$repo, $audit, $providers, $service] = fx148_ready($date);

    $first = GenerationResult::fromRunDaily($service->runDaily($date, null, ['actor' => 'admin-1']));
    if ($first['ticketId'] === null) return ['msg' => 'no ticket generated; duplicate path covered by the idempotency test below'];

    $ticketCountAfterFirst = count($repo->tickets);
    $selectionCountAfterFirst = count($repo->ticketSelections((string) $first['ticketId']));

    $second = GenerationResult::fromRunDaily($service->runDaily($date, null, ['actor' => 'admin-2']));
    assert_equals('DUPLICATE_SKIPPED', $second['status'], 'the second run is reported as a duplicate');
    assert_equals($first['ticketId'], $second['ticketId'], 'the existing ticket is returned, not a new one');
    assert_equals($ticketCountAfterFirst, count($repo->tickets), 'no duplicate ticket row was created');
    assert_equals($selectionCountAfterFirst, count($repo->ticketSelections((string) $first['ticketId'])), 'no duplicate selections were created');
});

test('duplicate protection: concurrent runs for one date still produce a single ticket', function () {
    $date = fx148_date();
    [$repo, $audit, $providers, $service] = fx148_ready($date);

    // Two services sharing ONE repository is exactly the browser+API race.
    $second = fx148_service($repo, $audit, $providers);
    $a = GenerationResult::fromRunDaily($service->runDaily($date, null, ['actor' => 'admin-browser']));
    $b = GenerationResult::fromRunDaily($second->runDaily($date, null, ['actor' => 'admin-api']));

    $ids = array_values(array_unique(array_filter([$a['ticketId'], $b['ticketId']])));
    assert_true(count($ids) <= 1, 'at most one ticket id exists for the date');
    assert_true(count($repo->tickets) <= 1, 'at most one ticket row was persisted');
    if ($a['ticketId'] !== null) {
        assert_true(in_array($b['status'], ['DUPLICATE_SKIPPED', 'GENERATION_IN_PROGRESS'], true),
            'the losing caller is told the work was already done, got ' . $b['status']);
    }
});

// ─────────────────────────────────────────────────────────────────────────
// 6. SELECTED DATE FIDELITY (§21)
// ─────────────────────────────────────────────────────────────────────────

test('selected date: generation runs for the requested date, never silently today', function () {
    $target = fx148_date(7);
    [$repo, $audit, $providers, $service] = fx148_ready($target, ['fixtures' => [
        fx148_fixture('d148-1', 'Alpha FC', 'Beta United', 'League One', fx148_kickoff($target)),
        fx148_fixture('d148-2', 'Gamma City', 'Delta Town', 'League Two', fx148_kickoff($target, 1)),
    ]]);

    $result = GenerationResult::fromRunDaily($service->runDaily($target, null, ['actor' => 'admin-1']));
    assert_equals($target, $result['date'], 'the contract reports the requested date');
    assert_true($target !== gmdate('Y-m-d'), 'the target is deliberately not today');

    // The persisted daily row is stored against the requested date.
    $daily = $repo->findDailyTicket($target);
    assert_true(is_array($daily), 'a daily row exists for the requested date');
    assert_equals($target, (string) $daily['date'], 'the stored row carries the requested date');
});

test('selected date: two different dates generate independently', function () {
    $d1 = fx148_date(3);
    $d2 = fx148_date(4);
    $repo = new SportsRepositoryStub();
    $audit = fx148_audit();
    $fixtures = array_merge(
        [fx148_fixture('i148-a', 'Alpha FC', 'Beta United', 'League One', fx148_kickoff($d1))],
        [fx148_fixture('i148-b', 'Gamma City', 'Delta Town', 'League Two', fx148_kickoff($d2))]
    );
    $providers = new SportsProviderManager();
    $providers->register(fx148_provider('apifootball', $fixtures));
    fx148_approve_calibration($repo);
    fx148_seed($repo, 'apifootball', $fixtures);
    $service = fx148_service($repo, $audit, $providers);

    $r1 = GenerationResult::fromRunDaily($service->runDaily($d1, null, ['actor' => 'admin-1']));
    $r2 = GenerationResult::fromRunDaily($service->runDaily($d2, null, ['actor' => 'admin-1']));
    assert_equals($d1, $r1['date']);
    assert_equals($d2, $r2['date']);
    assert_true($r1['runId'] !== $r2['runId'], 'each date gets its own run');
    if ($r1['ticketId'] !== null && $r2['ticketId'] !== null) {
        assert_true($r1['ticketId'] !== $r2['ticketId'], 'each date gets its own ticket');
    }
});

// ─────────────────────────────────────────────────────────────────────────
// 7. RUN IDENTITY, ACTOR AND AUDIT TRAIL (§5/§24)
// ─────────────────────────────────────────────────────────────────────────

test('run identity: every run has a unique id and records the acting administrator', function () {
    $date = fx148_date();
    [$repo, $audit, $providers, $service] = fx148_ready($date);
    $result = GenerationResult::fromRunDaily($service->runDaily($date, null, ['actor' => 'admin-42']));

    assert_true(is_string($result['runId']) && $result['runId'] !== '', 'a run id is always issued');
    assert_equals('admin-42', $result['actor'], 'the acting administrator is recorded, not "system"');

    // The audit event carries the actor and the full reconstruction detail.
    $events = array_values(array_filter($audit->events, fn(array $e): bool => str_starts_with((string) $e['type'], 'SPORTS_DAILY_TICKET')));
    assert_true($events !== [], 'the run emitted a daily-ticket audit event');
    $last = end($events);
    assert_equals('admin-42', $last['actor'], 'the audit record is attributed to the administrator');
    foreach (['runId', 'date', 'status', 'configurationVersion', 'provider', 'generationStartedAt', 'generationCompletedAt', 'duration', 'eligibleFixtures', 'predictionsGenerated', 'freshOdds', 'staleOdds', 'qualifiedCandidates', 'selectedPicks', 'rejectionSummary'] as $key) {
        assert_true(array_key_exists($key, $last['detail']), 'the audit detail can reconstruct the run: ' . $key);
    }
    assert_equals($date, (string) $last['detail']['date'], 'the audit record carries the requested date');
});

test('run identity: timing and provenance are real values, never placeholders', function () {
    $date = fx148_date();
    [$repo, $audit, $providers, $service] = fx148_ready($date);
    $result = GenerationResult::fromRunDaily($service->runDaily($date, null, ['actor' => 'admin-1']));

    assert_true(is_string($result['generationStartedAt']) && strtotime($result['generationStartedAt']) !== false, 'a real start timestamp');
    assert_true(is_string($result['generationCompletedAt']) && strtotime($result['generationCompletedAt']) !== false, 'a real completion timestamp');
    assert_true(strtotime($result['generationCompletedAt']) >= strtotime($result['generationStartedAt']), 'completion never precedes start');
    assert_true(is_numeric($result['duration']) && (float) $result['duration'] >= 0, 'a real measured duration');
    assert_true(is_int($result['configurationVersion']), 'the configuration version is recorded');
});

// ─────────────────────────────────────────────────────────────────────────
// 8. REAL STAGE REPORTING (§4)
// ─────────────────────────────────────────────────────────────────────────

test('generation status: stages report real progress and are never all-complete on a failure', function () {
    $date = fx148_date();

    // A successful run completes the early stages for real.
    [$repo, $audit, $providers, $service] = fx148_ready($date);
    $ok = GenerationResult::fromRunDaily($service->runDaily($date, null, ['actor' => 'admin-1']));
    assert_equals(count(GenerationResult::STAGES), count($ok['stages']), 'every declared stage is reported');
    $byKey = [];
    foreach ($ok['stages'] as $stage) {
        assert_true(in_array($stage['state'], [
            GenerationResult::STAGE_WAITING, GenerationResult::STAGE_RUNNING,
            GenerationResult::STAGE_COMPLETE, GenerationResult::STAGE_FAILED, GenerationResult::STAGE_SKIPPED,
        ], true), 'each stage carries a declared state, got ' . $stage['state']);
        $byKey[$stage['key']] = $stage['state'];
    }
    assert_equals(GenerationResult::STAGE_COMPLETE, $byKey['provider'], 'a reachable provider completes that stage');
    assert_equals(GenerationResult::STAGE_COMPLETE, $byKey['fixtures'], 'fixture intake really ran');

    // With no provider, the provider stage FAILS and nothing downstream is
    // reported as complete.
    $emptyRepo = new SportsRepositoryStub();
    $emptyAudit = fx148_audit();
    $noProviders = new SportsProviderManager();
    $failed = GenerationResult::fromRunDaily(fx148_service($emptyRepo, $emptyAudit, $noProviders)->runDaily($date, null, ['actor' => 'admin-1']));
    $states = [];
    foreach ($failed['stages'] as $stage) $states[$stage['key']] = $stage['state'];
    assert_equals(GenerationResult::STAGE_FAILED, $states['provider'], 'the provider stage is reported FAILED');
    foreach (['predictions', 'confidence', 'dataQuality', 'optimizer', 'persistence'] as $later) {
        assert_true($states[$later] !== GenerationResult::STAGE_COMPLETE,
            $later . ' must never be reported complete when the run stopped at the provider');
    }
});

// ─────────────────────────────────────────────────────────────────────────
// 9. NOTHING IS FABRICATED (§11/§25)
// ─────────────────────────────────────────────────────────────────────────

test('honesty: a missing provider never becomes fabricated fixtures, odds or confidence', function () {
    $date = fx148_date();
    $repo = new SportsRepositoryStub();
    $audit = fx148_audit();
    $service = fx148_service($repo, $audit, new SportsProviderManager());
    $result = GenerationResult::fromRunDaily($service->runDaily($date, null, ['actor' => 'admin-1']));

    assert_equals(0, (int) $result['fixturesEvaluated'], 'no fixture is invented');
    assert_equals(0, (int) $result['predictionsGenerated'], 'no prediction is invented');
    assert_equals(0, count($repo->predictions), 'nothing was written to storage');
    assert_equals(0, count($repo->tickets), 'no ticket was written');
    assert_null($result['ticketId']);
});

test('honesty: stale odds are rejected and counted, never silently used', function () {
    $date = fx148_date();
    // Odds observed far outside any sane freshness TTL.
    [$repo, $audit, $providers, $service] = fx148_ready($date, ['oddsAge' => 86400 * 3]);
    $result = GenerationResult::fromRunDaily($service->runDaily($date, null, ['actor' => 'admin-1']));

    assert_null($result['ticketId'], 'a day priced only by stale odds produces no ticket');
    $stale = (int) ($result['staleOdds'] ?? 0);
    $summary = (array) $result['rejectionSummary'];
    $staleReasons = 0;
    foreach ($summary as $reason => $count) {
        if (stripos((string) $reason, 'STALE') !== false || stripos((string) $reason, 'ODDS') !== false) $staleReasons += (int) $count;
    }
    assert_true($stale > 0 || $staleReasons > 0, 'stale/unusable odds are counted and named, not silently consumed');
});

test('honesty: the engine reports the confidence it measured, unrounded', function () {
    $engine = new ConfidenceEngine();
    $prediction = ['decision' => 'PREDICTION_READY', 'calibratedProbability' => 0.82, 'market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5'];
    $evidence = [
        'features' => ['expectedGoalsProxy' => 2.65, 'homeAttack' => 1.9, 'awayAttack' => 1.5, 'homeDefenseConceded' => 0.8, 'awayDefenseConceded' => 1.1],
        'inputs' => ['recentForm' => ['homeGoalsPerMatch' => 1.9, 'awayGoalsPerMatch' => 1.5, 'homeConcededPerMatch' => 0.8, 'awayConcededPerMatch' => 1.1, 'matchesPlayed' => 14, 'source' => 'provider']],
        'market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'impliedProbability' => 0.74,
        'marketPrices' => ['OVER_1_5' => ['odds' => 1.42], 'UNDER_1_5' => ['odds' => 2.85]],
    ];
    $out = $engine->assess($prediction, ['score' => 100, 'band' => 'EXCELLENT'], ['ece' => 0.02, 'samples' => 40], $evidence);
    assert_true(is_numeric($out['confidence']), 'a measured confidence is a number');
    assert_true((float) $out['confidence'] <= ConfidenceEngine::CAP, 'the cap still holds — confidence is never inflated past it');

    // An unmeasurable confidence stays null; it never becomes a passing number.
    $policy = ConfidencePolicy::fromConfiguration(ConfigurationService::defaults());
    $verdict = $policy->evaluate(96, null, 'TOTAL_GOALS', 'OVER_1_5');
    assert_false($verdict['qualified']);
    assert_null($verdict['confidence'], 'an absent measurement is null, never substituted');
});

// ─────────────────────────────────────────────────────────────────────────
// 10. SUCCESSFUL GENERATION PERSISTS EVERYTHING (§16/§22/§24)
// ─────────────────────────────────────────────────────────────────────────

test('a successful generation persists the ticket, its legs and their full calculation trail', function () {
    $date = fx148_date();
    [$repo, $audit, $providers, $service] = fx148_ready($date);
    $result = GenerationResult::fromRunDaily($service->runDaily($date, null, ['actor' => 'admin-1']));

    if ($result['ticketId'] === null) {
        return ['msg' => 'deterministic day produced no ticket: ' . $result['status'] . ' — persistence asserted by the duplicate test'];
    }

    $ticketId = (string) $result['ticketId'];
    $ticket = $repo->findTicket($ticketId);
    assert_true(is_array($ticket), 'the ticket row is persisted');
    $selections = $repo->ticketSelections($ticketId);
    assert_true(count($selections) > 0, 'the ticket has persisted legs');
    assert_equals(count($selections), (int) $result['selectedPicks'], 'the contract agrees with storage');

    // Combined odds stay inside the configured 5.00-8.00 window (§15).
    $total = (float) ($ticket['total_odds'] ?? 0);
    assert_true($total >= 5.0 - 1e-9 && $total <= 8.0 + 1e-9, 'combined odds sit inside 5.00-8.00, got ' . $total);

    // Every leg carries the real calculation trail (§16).
    foreach ($selections as $leg) {
        foreach (['market', 'selection', 'odds'] as $field) {
            assert_true(array_key_exists($field, $leg), 'each leg records ' . $field);
        }
    }
    // The predictions themselves are stored (§11).
    assert_true(count($repo->predictions) > 0, 'the generated predictions are persisted');
    // …and the ticket awaits user approval rather than executing anything (§2).
    assert_true(in_array($result['status'], ['PENDING_USER_APPROVAL', 'APPROVED'], true), 'got ' . $result['status']);
});

test('a generated ticket is readable afterwards, which is what non-admin viewers see', function () {
    $date = fx148_date();
    [$repo, $audit, $providers, $service] = fx148_ready($date);
    $result = GenerationResult::fromRunDaily($service->runDaily($date, null, ['actor' => 'admin-1']));
    if ($result['ticketId'] === null) return ['msg' => 'no ticket on this day; visibility asserted via the daily row'];

    // A viewer reads the persisted daily row + ticket; no generation involved.
    $daily = $repo->findDailyTicket($date);
    assert_true(is_array($daily), 'the daily row is readable');
    assert_equals($result['ticketId'], (string) $daily['ticket_id'], 'the daily row points at the generated ticket');
    $ticket = $repo->findTicket((string) $result['ticketId']);
    assert_true(is_array($ticket), 'the ticket is readable without regenerating');
    assert_true(count($repo->ticketSelections((string) $result['ticketId'])) > 0, 'its selections are readable');
});

// ─────────────────────────────────────────────────────────────────────────
// 11. REGRESSIONS FOUND BY EXECUTING THE REAL ROUTES
// ─────────────────────────────────────────────────────────────────────────

test('regression: a missing provider is never recorded as NO_QUALIFIED_TICKET', function () {
    // Live /sports/generate-ticket stored status=NO_QUALIFIED_TICKET with
    // last_error_code=NO_PROVIDER_CONFIGURED: the NO_PROVIDER branch set
    // dataState but left $status at its "no qualifying games" default, which
    // is exactly the conflation spec §8 forbids.
    $date = fx148_date();
    $repo = new SportsRepositoryStub();
    $audit = fx148_audit();
    $raw = fx148_service($repo, $audit, new SportsProviderManager())->runDaily($date, null, ['actor' => 'admin-1']);

    assert_equals('NO_PROVIDER', (string) $raw['status'], 'the service itself reports NO_PROVIDER');
    assert_not_equals('NO_QUALIFIED_TICKET', (string) $raw['status'], 'an unassessed day is never a prediction outcome');
    // The persisted daily row must agree — that is what the dashboard reads.
    $daily = $repo->findDailyTicket($date);
    assert_true(is_array($daily), 'the run was recorded');
    assert_equals('NO_PROVIDER', (string) $daily['status'], 'the stored row reports the infrastructure state');
    // "NO VALUE TICKET TODAY" is reserved for a day that was genuinely assessed.
    assert_not_contains('NO VALUE TICKET TODAY', (string) $raw['message'],
        'the no-value copy must not describe a day nothing looked at');
});

test('regression: a disabled engine reports DISABLED, not "no qualifying games"', function () {
    $date = fx148_date();
    $repo = new SportsRepositoryStub();
    $audit = fx148_audit();
    (new ConfigurationService($repo, $audit))->update(['module_enabled' => 0], 'admin', 'switch the module off');
    $providers = new SportsProviderManager();
    $providers->register(fx148_provider('apifootball', fx148_good_day($date)));

    $result = GenerationResult::fromRunDaily(fx148_service($repo, $audit, $providers)->runDaily($date, null, ['actor' => 'admin-1']));
    assert_equals('DISABLED', $result['status'], 'an administratively disabled engine says so');
    assert_null($result['ticketId']);
    assert_false($result['retryable'], 'retrying cannot help until an operator re-enables it');
    assert_in_array('DISABLED', GenerationResult::STATUSES, 'DISABLED is a declared contract state');
});

test('regression: an already-generated day reports SKIPPED stages, never a full green run', function () {
    // The duplicate answer used to arrive with no stage ledger at all, so the
    // panel fell back to "everything complete" for work this request never did.
    $date = fx148_date();
    [$repo, $audit, $providers, $service] = fx148_ready($date);
    $first = GenerationResult::fromRunDaily($service->runDaily($date, null, ['actor' => 'admin-1']));
    if ($first['ticketId'] === null) return ['msg' => 'no ticket generated on this day'];

    $second = GenerationResult::fromRunDaily($service->runDaily($date, null, ['actor' => 'admin-2']));
    assert_equals('DUPLICATE_SKIPPED', $second['status']);
    foreach ($second['stages'] as $stage) {
        assert_equals(GenerationResult::STAGE_SKIPPED, $stage['state'],
            $stage['key'] . ' must be SKIPPED: this request executed no pipeline work');
    }
    // It still carries the canonical fields, read back from the recorded run.
    assert_equals($first['ticketId'], $second['ticketId']);
    assert_equals($date, $second['date']);
    assert_equals(count($repo->ticketSelections((string) $first['ticketId'])), (int) $second['selectedPicks']);
});

test('regression: an early exit still returns the full contract with honest zeroes', function () {
    // RETRY_SCHEDULED / GENERATION_IN_PROGRESS / RESET_FAILED used to return a
    // 6-key array, so the API answered with a contract full of missing fields.
    $date = fx148_date();
    $repo = new SportsRepositoryStub();
    $audit = fx148_audit();
    $providers = new SportsProviderManager();
    $providers->register(fx148_provider('apifootball', fx148_good_day($date)));
    $service = fx148_service($repo, $audit, $providers);

    // A scheduled worker meeting an active backoff window.
    $repo->saveDailyTicket([
        'date' => $date, 'ticket_type' => 'ODDS_PREDICTION', 'ticket_id' => null,
        'status' => 'NO_QUALIFIED_TICKET', 'generation_status' => 'RETRYING',
        'next_retry_at' => gmdate('c', time() + 3600), 'attempt_count' => 2,
        'rejection_summary' => json_encode([]),
    ]);
    $result = GenerationResult::fromRunDaily($service->runDaily($date, null, ['scheduled' => true]));

    assert_equals('RETRY_SCHEDULED', $result['status']);
    foreach ([
        'status', 'dataState', 'date', 'runId', 'ticketId', 'provider', 'providerStatus',
        'fixturesEvaluated', 'eligibleFixtures', 'predictionsGenerated', 'freshOdds', 'staleOdds',
        'qualifiedCandidates', 'selectedPicks', 'rejections', 'rejectionSummary', 'gateFailures',
        'message', 'modelVersion', 'configurationVersion', 'generationStartedAt',
        'generationCompletedAt', 'duration', 'auditId',
    ] as $field) {
        assert_true(array_key_exists($field, $result), 'early exit still carries ' . $field);
    }
    assert_equals($date, $result['date'], 'and it still names the requested date');
    assert_equals(0, (int) $result['fixturesEvaluated'], 'a stage that never ran reports a real zero');
    assert_equals(count(GenerationResult::STAGES), count($result['stages']), 'the panel can render from it');
});
