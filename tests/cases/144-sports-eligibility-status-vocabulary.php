<?php
/**
 * Regression: "50 evaluated → 0 eligible → 50 × FIXTURE_NOT_NS_OR_TOO_SOON".
 *
 * Two independent causes produced that no-ticket day:
 *
 *  1. The eligibility gate matched the LITERAL provider short code "NS". A
 *     feed that spells a not-started fixture "Not Started", "TBD", "PENDING"
 *     or "DELAYED" had every one of its rows rejected as "not NS or too soon",
 *     even though the canonical status stored on the very same row said
 *     SCHEDULED and kickoff was hours away.
 *
 *  2. A stored page containing no fixture able to pass the first gate was
 *     still returned as intake, so the run dead-ended on a stale cache while
 *     the configured provider held fresh not-started fixtures.
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

function fx144_audit(): AuditRepository
{
    return new class implements AuditRepository { public array $events = []; public function emit(string $t, string $s, array $d = [], string $a = 'system'): void { $this->events[] = ['type' => $t, 'actor' => $a, 'detail' => $d]; } public function recent(int $l = 100): array { return []; } };
}

function fx144_provider(array $oddsByExt, array $fixtures = []): SportsDataProvider
{
    return new class($oddsByExt, $fixtures) implements SportsDataProvider {
        public int $fixtureCalls = 0;
        public function __construct(private array $odds, private array $liveFixtures) {}
        public function id(): string { return 'vocab-test'; }
        public function health(): array { return ['status' => 'ONLINE', 'reliability' => 0.9]; }
        public function fixtures(array $q): array {
            $this->fixtureCalls++;
            $limit = isset($q['candidateLimit']) ? (int) $q['candidateLimit'] : (int) ($q['limit'] ?? 50);
            return array_slice($this->liveFixtures, 0, max(0, $limit));
        }
        public function odds(string $e): array { return $this->odds[$e] ?? []; }
        public function results(string $e): array { return []; }
    };
}

/** A predictable fixture row carrying verified form, in the provider's own status vocabulary. */
function fx144_raw(string $externalId, int $kickoffTs, string $status, ?string $sourceStatus = null): array
{
    $raw = [
        'externalId' => $externalId,
        'sport' => 'football',
        'competition' => 'Vocabulary League ' . $externalId,
        'homeTeam' => 'Home ' . $externalId, 'awayTeam' => 'Away ' . $externalId,
        'kickoff' => gmdate('Y-m-d\TH:i:00\+00:00', $kickoffTs),
        'status' => $status,
        'sourceTimestamp' => gmdate('c'),
        'context' => [
            'recentForm' => [
                'homeGoalsPerMatch' => 1.6, 'awayGoalsPerMatch' => 1.4,
                'homeConcededPerMatch' => 1.0, 'awayConcededPerMatch' => 0.9,
                'source' => 'test-verified', 'timestamp' => gmdate('c'),
            ],
            'marketLiquidity' => 50000,
        ],
    ];
    if ($sourceStatus !== null) $raw['sourceStatus'] = $sourceStatus;
    return $raw;
}

/**
 * Store the fixture exactly as a prior sync would have: normalized (so the
 * stored `status` column is canonical) while the provider's own spelling is
 * preserved inside the payload as `sourceStatus`.
 */
function fx144_store(SportsRepositoryStub $repo, int $providerId, array $raw): void
{
    $repo->saveMatch($providerId, \AIWorkforce\Sports\SportsDataNormalizer::fixture($raw, 'vocab-test'));
}

function fx144_service(SportsRepositoryStub $repo, AuditRepository $audit, SportsProviderManager $providers): DailyTicketService
{
    $pipeline = new PredictionPipeline(new MatchIntelligenceEngine(new OddsFreshnessEngine()), new FeatureEngineeringEngine(), new PredictionEngine(), new ValueEngine(), new RiskEngine(), new CorrelationEngine(), new ConfidenceEngine());
    return new DailyTicketService($repo, $audit, $providers, new ConfigurationService($repo, $audit), new DataQualityEngine(), $pipeline, new TicketOptimizer(new CorrelationEngine()), new TicketGovernance($repo, $audit, new CorrelationEngine()), new DecisionRecorder($repo, $audit));
}

function fx144_approve_calibration(SportsRepositoryStub $repo): void
{
    $modelId = $repo->ensureModelVersion(['modelName' => PredictionEngine::MODEL_NAME, 'modelVersion' => PredictionEngine::MODEL_VERSION, 'featureVersion' => FeatureEngineeringEngine::VERSION]);
    $repo->saveCalibration(['model_version_id' => $modelId, 'method' => 'platt', 'intercept' => 0.2, 'slope' => 1.5, 'samples' => 40, 'ece' => 0.02, 'status' => 'APPROVED', 'created_by' => 'admin', 'created_at' => gmdate('c')]);
}

test('eligibility: a not-started fixture is recognised in every provider status vocabulary', function () {
    $repo = new SportsRepositoryStub();
    $audit = fx144_audit();
    fx144_approve_calibration($repo);

    $providerId = (int) $repo->ensureProvider('vocab-test', 'vocab-test')['id'];
    $now = time();
    // The same not-started state, spelled the way five different feeds spell it.
    $spellings = [
        ['Not Started', null],
        ['TBD', null],
        ['PENDING', null],
        ['SCHEDULED', 'Not Started'],
        ['SCHEDULED', 'TBA'],
    ];
    $prices = [1.55, 1.75, 1.90, 2.10, 2.30];
    $oddsByExt = [];
    foreach ($spellings as $i => [$status, $sourceStatus]) {
        $ext = 'vocab' . $i;
        fx144_store($repo, $providerId, fx144_raw($ext, $now + (5 + $i) * 3600, $status, $sourceStatus));
        $oddsByExt[$ext] = [['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => $prices[$i], 'observedAt' => gmdate('c')]];
    }

    $providers = new SportsProviderManager();
    $providers->register(fx144_provider($oddsByExt));
    $run = fx144_service($repo, $audit, $providers)->runDaily(gmdate('Y-m-d'));

    assert_equals(0, (int) ($run['rejectionSummary']['FIXTURE_NOT_NS_OR_TOO_SOON'] ?? 0),
        'no not-started fixture is rejected merely for how its provider spells the status');
    assert_equals(count($spellings), (int) ($run['diagnostics']['eligibleFixtures'] ?? 0),
        'every not-started spelling reached the eligibility gate');
    assert_not_null($run['ticketId'], 'the day produces a real ticket instead of a status-vocabulary dead end');
});

test('eligibility: a started or finished fixture is still rejected, whatever its spelling', function () {
    $repo = new SportsRepositoryStub();
    $audit = fx144_audit();
    fx144_approve_calibration($repo);

    $providerId = (int) $repo->ensureProvider('vocab-test', 'vocab-test')['id'];
    $now = time();
    // Live/finished/abandoned rows must never become ticket candidates, and a
    // genuinely not-started row that kicks off inside the two-hour lead is a
    // real timing rejection.
    fx144_store($repo, $providerId, fx144_raw('live1', $now + 6 * 3600, 'SCHEDULED', 'Second Half'));
    fx144_store($repo, $providerId, fx144_raw('done1', $now + 6 * 3600, 'SCHEDULED', 'Match Finished'));
    fx144_store($repo, $providerId, fx144_raw('soon1', $now + 900, 'Not Started'));

    $providers = new SportsProviderManager();
    $providers->register(fx144_provider([]));
    $run = fx144_service($repo, $audit, $providers)->runDaily(gmdate('Y-m-d'));

    assert_equals(0, (int) ($run['diagnostics']['eligibleFixtures'] ?? 0),
        'in-play, finished and too-soon fixtures stay ineligible');
    assert_null($run['ticketId'], 'no ticket is fabricated from ineligible fixtures');
});

test('intake: a stored page with nothing eligible falls through to the live provider', function () {
    $repo = new SportsRepositoryStub();
    $audit = fx144_audit();
    fx144_approve_calibration($repo);

    $providerId = (int) $repo->ensureProvider('vocab-test', 'vocab-test')['id'];
    $now = time();
    // A stale cache: every stored row already kicked off.
    for ($i = 0; $i < 50; $i++) {
        fx144_store($repo, $providerId, fx144_raw('stale' . $i, $now - ($i + 1) * 600, 'Not Started'));
    }
    // The provider, meanwhile, holds fresh not-started fixtures.
    $fixtures = [];
    $oddsByExt = [];
    $prices = [1.55, 1.75, 1.90, 2.10, 2.30];
    for ($i = 0; $i < 5; $i++) {
        $ext = 'fresh' . $i;
        $fixtures[] = fx144_raw($ext, $now + (5 + $i) * 3600, 'Not Started');
        $oddsByExt[$ext] = [['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => $prices[$i], 'observedAt' => gmdate('c')]];
    }

    $providers = new SportsProviderManager();
    $provider = fx144_provider($oddsByExt, $fixtures);
    $providers->register($provider);
    $run = fx144_service($repo, $audit, $providers)->runDaily(gmdate('Y-m-d'));

    assert_true($provider->fixtureCalls > 0, 'a stale stored page does not suppress the live provider cycle');
    assert_equals('PROVIDER', (string) ($run['diagnostics']['fixtureInput'] ?? ''), 'intake came from the live feed');
    assert_true((int) ($run['diagnostics']['eligibleFixtures'] ?? 0) >= 5, 'the provider fixtures reached the gates');
    assert_not_null($run['ticketId'], 'the day produces a ticket instead of a stale-cache dead end');
});
