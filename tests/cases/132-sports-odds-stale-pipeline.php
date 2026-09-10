<?php
/**
 * Regression suite for the reported 2026-09-10 ticket-engine failure:
 *
 *   NO_QUALIFIED_TICKET: 668 predictions rejected as BOTH STALE_ODDS and
 *   INSUFFICIENT_DATA (169 evaluated, 741 rejections).
 *
 * Root causes fixed and asserted here:
 *   1. a hard-coded 15-minute odds TTL marked a once-a-day odds sync stale
 *      for the rest of the day → configurable market/provider TTL, and odds
 *      inside the TTL are used as-is (never re-fetched, never stale);
 *   2. odds were only fetched when a match had ZERO stored rows → stale odds
 *      are now refreshed, walking every configured provider that has a
 *      VERIFIED fixture id (cross-references included) before rejecting;
 *   3. an all-or-nothing data gate rejected every prediction whose optional
 *      enrichment was missing → market-mandatory fields are defined per
 *      market, everything else only feeds the Data Quality Score;
 *   4. every rejection reason of every prediction was counted → one primary
 *      blocking reason per rejected candidate/fixture, all reasons kept on
 *      the decision record;
 *   5. shared upstream failures (odds, mandatory data, calibration, quality
 *      floor) reject the FIXTURE once instead of generating N per-market
 *      predictions and rejecting them all;
 *   6. the response now carries a diagnostics funnel with top rejection
 *      reasons and the provider that caused each failure.
 */
use AIWorkforce\Sports\ConfigurationService;
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
use AIWorkforce\Sports\CorrelationEngine;
use AIWorkforce\Sports\ConfidenceEngine;
use AIWorkforce\Sports\ValueEngine;

function fx_stale_audit(): \AIWorkforce\Persistence\AuditRepository
{
    return new class implements \AIWorkforce\Persistence\AuditRepository {
        public array $events = [];
        public function emit(string $t, string $s, array $d = [], string $a = 'system'): void { $this->events[] = ['type' => $t, 'actor' => $a, 'detail' => $d]; }
        public function recent(int $l = 100): array { return []; }
    };
}

/**
 * Configurable test provider. $fixtures are returned verbatim; odds() is
 * counted (and can serve rows or fail) so tests can prove exactly how many
 * provider requests the engine made.
 */
function fx_stale_provider(string $id, array $fixtures, array $oddsByExt = [], bool $oddsFails = false): SportsDataProvider
{
    return new class($id, $fixtures, $oddsByExt, $oddsFails) implements SportsDataProvider {
        public int $oddsCalls = 0;
        public function __construct(private string $pid, private array $fixtures, private array $odds, private bool $oddsFails) {}
        public function id(): string { return $this->pid; }
        public function health(): array { return ['status' => 'ONLINE', 'reliability' => 0.9]; }
        public function fixtures(array $q): array { return $this->fixtures; }
        public function odds(string $e): array
        {
            $this->oddsCalls++;
            if ($this->oddsFails) throw new \AIWorkforce\Sports\Providers\ProviderException('odds endpoint down', \AIWorkforce\Sports\Providers\ProviderException::DATA_ERROR);
            return $this->odds[$e] ?? [];
        }
        public function results(string $e): array { return []; }
    };
}

/** N eligible fixtures, each with verified recent form (or without). */
function fx_stale_fixtures(int $n, bool $withForm = true, bool $withApiFootballId = false): array
{
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $row = [
            'externalId' => 'f' . $i,
            'homeTeam' => 'Home' . $i, 'awayTeam' => 'Away' . $i,
            'competition' => 'Repro League',
            'kickoff' => gmdate('Y-m-d\TH:i:00\+00:00', strtotime('+1 day ' . (10 + $i % 12) . ':30:00')),
            'status' => 'SCHEDULED',
            'context' => $withForm ? [
                'recentForm' => [
                    'homeGoalsPerMatch' => 1.6, 'awayGoalsPerMatch' => 1.4,
                    'homeConcededPerMatch' => 1.0, 'awayConcededPerMatch' => 0.9,
                    'source' => 'test-verified', 'timestamp' => gmdate('c'),
                ],
            ] : [],
        ];
        if ($withApiFootballId) $row['apiFootballId'] = 'af-' . $i;
        $out[] = $row;
    }
    return $out;
}

/** The 3 supported market rows the per-fixture odds sync would store. */
function fx_stale_odds_rows(float $awayOdds = 6.5): array
{
    return [
        ['market' => 'MATCH_RESULT', 'selection' => 'AWAY', 'decimalOdds' => $awayOdds, 'observedAt' => gmdate('c')],
        ['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 1.7, 'observedAt' => gmdate('c')],
        ['market' => 'BTTS', 'selection' => 'YES', 'decimalOdds' => 1.9, 'observedAt' => gmdate('c')],
    ];
}

function fx_stale_stack(SportsDataProvider $provider): array
{
    $repo = new SportsRepositoryStub();
    $audit = fx_stale_audit();
    $providers = new SportsProviderManager();
    $providers->register($provider);
    $config = new ConfigurationService($repo, $audit);
    $pipeline = new PredictionPipeline(new MatchIntelligenceEngine(new OddsFreshnessEngine()), new FeatureEngineeringEngine(), new PredictionEngine(), new ValueEngine(), new RiskEngine(), new CorrelationEngine(), new ConfidenceEngine());
    $service = new DailyTicketService($repo, $audit, $providers, $config, new DataQualityEngine(), $pipeline, new TicketOptimizer(new CorrelationEngine()), new TicketGovernance($repo, $audit, new CorrelationEngine()), new DecisionRecorder($repo, $audit));
    return [$repo, $audit, $providers, $service];
}

function fx_stale_approve_calibration(SportsRepositoryStub $repo): void
{
    $modelId = $repo->ensureModelVersion(['modelName' => PredictionEngine::MODEL_NAME, 'modelVersion' => PredictionEngine::MODEL_VERSION, 'featureVersion' => FeatureEngineeringEngine::VERSION]);
    $repo->saveCalibration(['model_version_id' => $modelId, 'method' => 'platt', 'intercept' => 0.2, 'slope' => 1.5, 'samples' => 40, 'ece' => 0.02, 'status' => 'APPROVED', 'created_by' => 'admin', 'created_at' => gmdate('c')]);
}

/** Pre-seed the repository like an earlier (cron) odds sync would have. */
function fx_stale_seed_odds(SportsRepositoryStub $repo, string $providerCode, array $fixtures, int $ageSeconds, ?array $rowsByFixture = null): void
{
    $provider = $repo->ensureProvider($providerCode, $providerCode);
    foreach ($fixtures as $i => $fixture) {
        $match = $repo->saveMatch((int) $provider['id'], \AIWorkforce\Sports\SportsDataNormalizer::fixture($fixture, $providerCode));
        $rows = $rowsByFixture !== null ? $rowsByFixture['f' . $i] : fx_stale_odds_rows();
        foreach ($rows as $row) {
            $repo->saveOdds((int) $match['id'], (int) $provider['id'], ['market' => $row['market'], 'selection' => $row['selection'], 'decimalOdds' => $row['decimalOdds'], 'observedAt' => gmdate('c', time() - $ageSeconds)]);
        }
    }
}

test('odds TTL: a once-a-day sync 3h old is FRESH — no re-fetch, no STALE_ODDS, ticket generated', function () {
    // THE reported failure mode: odds were synced by the daily cron and the
    // ticket run hours later marked all of them stale (15-minute TTL).
    $fixtures = fx_stale_fixtures(20, true);
    $provider = fx_stale_provider('repro-sync', $fixtures, [], false);
    [$repo, $audit, $providers, $service] = fx_stale_stack($provider);
    fx_stale_approve_calibration($repo);
    fx_stale_seed_odds($repo, 'repro-sync', $fixtures, 3 * 3600); // synced 3 hours ago
    $date = gmdate('Y-m-d', strtotime('+1 day'));
    $run = $service->runDaily($date);

    // Odds inside the TTL are used as-is: zero provider odds requests.
    assert_equals(0, $provider->oddsCalls, 'fresh-enough odds are never re-fetched');
    // 20 fixtures × 3 supported rows = 60 predictions, none rejected as stale.
    assert_equals(20, $run['evaluated']);
    assert_equals(60, $run['predictionsRecorded'], 'every supported market row is predicted');
    assert_equals(0, $run['rejections']);
    assert_equals([], array_filter($run['rejectionSummary'], fn($v) => $v === 'STALE_ODDS' || $v === 'INSUFFICIENT_DATA'), 'no stale/insufficient cascade');
    // A genuinely qualified candidate exists → a ticket is generated.
    assert_equals('PENDING_USER_APPROVAL', $run['status']);
    assert_not_null($run['ticketId']);
    $ticket = $repo->findTicket($run['ticketId']);
    assert_true($ticket['total_odds'] >= 5.0 && $ticket['total_odds'] <= 8.0, 'odds inside the configured range');
    assert_true((float) $ticket['confidence'] >= 70.0, '70%+ confidence gate enforced on the WINDELS confidence');
    // Diagnostics funnel is exposed with the full shape.
    $diag = $run['diagnostics'];
    assert_equals(20, $diag['eligibleFixtures']);
    assert_equals(20, $diag['fixturesWithFreshOdds']);
    assert_equals(20, $diag['sufficientDataFixtures']);
    assert_equals(60, $diag['predictionsGenerated']);
    assert_equals(60, $diag['sufficientDataCandidates']);
    assert_true($diag['confidenceQualifiedCandidates'] > 0);
    assert_true($diag['positiveValueCandidates'] > 0);
    assert_true($diag['riskQualifiedCandidates'] > 0);
    assert_true($diag['finalQualifiedCandidates'] >= 1);
    assert_equals(21600, $diag['thresholds']['oddsMaxAgeSeconds']);
});

test('odds provenance: every prediction record stores oddsUpdatedAt/Source/AgeSeconds/Status', function () {
    $fixtures = fx_stale_fixtures(4, true);
    $provider = fx_stale_provider('repro-prov', $fixtures);
    [$repo, , , $service] = fx_stale_stack($provider);
    fx_stale_approve_calibration($repo);
    fx_stale_seed_odds($repo, 'repro-prov', $fixtures, 3 * 3600);
    $run = $service->runDaily(gmdate('Y-m-d', strtotime('+1 day')));
    assert_equals(12, $run['predictionsRecorded']);
    foreach ($repo->listPredictions([], 100) as $p) {
        $oddsFactors = $p['factors']['odds'] ?? [];
        assert_equals('FRESH', $oddsFactors['oddsStatus'] ?? null, 'oddsStatus stored on the record');
        assert_equals('repro-prov', $oddsFactors['oddsSource'] ?? null, 'oddsSource stored on the record');
        assert_not_null($oddsFactors['oddsUpdatedAt'] ?? null, 'oddsUpdatedAt stored on the record');
        assert_true(is_numeric($oddsFactors['oddsAgeSeconds'] ?? null) && (int) $oddsFactors['oddsAgeSeconds'] >= 3 * 3600, 'oddsAgeSeconds stored on the record');
    }
    // WINDELS prediction is separated from the bookmaker price: the model's
    // fair odds differ from the market odds, and the record keeps both sides.
    $away = array_values(array_filter($repo->listPredictions([], 100), fn($p) => $p['market'] === 'MATCH_RESULT' && $p['selection'] === 'AWAY'))[0];
    assert_not_null($away['calibrated_probability']);
    assert_close(1 / (float) $away['odds'], (float) $away['implied_probability'], 0.0001, 'implied probability comes from the market odds');
    assert_close(1 / (float) $away['calibrated_probability'], (float) $away['factors']['model']['fairOdds'], 0.01, 'fair odds = 1 / model probability');
    assert_close(1 / (float) $away['calibrated_probability'], (float) $away['factors']['value']['fairOdds'], 0.01, 'value block carries the model fair odds');
    assert_close((float) $away['odds'], (float) $away['factors']['value']['marketOdds'], 0.0001, 'value block carries the real market odds');
    assert_true((float) $away['expected_value'] > 0, 'edge computed from model probability × market odds');
    assert_true(abs((float) $away['calibrated_probability'] - 1 / (float) $away['odds']) > 0.0001, 'model probability is NOT a copy of the implied probability');
});

test('stale beyond TTL: refresh walks the configured providers before rejecting (cross-reference fallback)', function () {
    // Fixtures from a provider WITHOUT odds coverage, carrying an api-football
    // cross-reference; the secondary provider serves fresh odds for that id.
    $fixtures = fx_stale_fixtures(6, true, true);
    $primary = fx_stale_provider('thesportsdb-repro', $fixtures); // odds() → [] (no coverage)
    $secondaryOdds = [];
    foreach ($fixtures as $i => $f) {
        $rows = [];
        foreach (fx_stale_odds_rows() as $r) $rows[] = array_merge($r, ['fixtureId' => 'af-' . $i]);
        $secondaryOdds['af-' . $i] = $rows;
    }
    $secondary = fx_stale_provider('api-football', [], $secondaryOdds);
    $repo = new SportsRepositoryStub();
    $audit = fx_stale_audit();
    $providers = new SportsProviderManager();
    $providers->register($primary);
    $providers->register($secondary);
    $config = new ConfigurationService($repo, $audit);
    $service = new DailyTicketService($repo, $audit, $providers, $config, new DataQualityEngine(), new PredictionPipeline(), new TicketOptimizer(new CorrelationEngine()), new TicketGovernance($repo, $audit, new CorrelationEngine()), new DecisionRecorder($repo, $audit));
    fx_stale_approve_calibration($repo);
    // Seed odds 30h old from the primary — beyond the 6h TTL.
    fx_stale_seed_odds($repo, 'thesportsdb-repro', $fixtures, 30 * 3600);

    $run = $service->runDaily(gmdate('Y-m-d', strtotime('+1 day')));
    // The primary had no usable odds; the secondary supplied fresh ones via
    // the cross-referenced fixture id — no rejection.
    assert_equals(18, $run['predictionsRecorded'], 'fresh odds from the secondary provider feed the predictions');
    assert_equals(6, $run['diagnostics']['oddsProvidersUsed']['api-football'], 'cross-referenced provider used for every fixture');
    assert_equals(6, $run['diagnostics']['oddsProvidersNoCoverage']['thesportsdb-repro'], 'no-coverage provider recorded, not counted as a failure');
    assert_equals(6, $run['diagnostics']['oddsRefreshedFixtures']);
    assert_equals('PENDING_USER_APPROVAL', $run['status'], 'the day qualifies once real fresh odds exist');
    // The stored odds rows are attributed to the provider that supplied them.
    $sources = [];
    foreach ($repo->odds as $row) $sources[$row['provider_id']] = true;
    assert_true(count($sources) >= 2, 'odds from both providers stored with their own attribution');
});

test('stale beyond TTL with no refresh available: ONE STALE_ODDS rejection per fixture, never per market', function () {
    // THE reported double-count: 668 STALE_ODDS + 668 INSUFFICIENT_DATA.
    // These fixtures have stale odds AND missing recent form — the odds stage
    // fails first and each fixture is counted exactly once, with no
    // INSUFFICIENT_DATA cascade at all.
    $fixtures = fx_stale_fixtures(10, false);
    $provider = fx_stale_provider('repro-stale', $fixtures, [], false); // odds() → [] (nothing to refresh from)
    [$repo, , , $service] = fx_stale_stack($provider);
    fx_stale_approve_calibration($repo);
    fx_stale_seed_odds($repo, 'repro-stale', $fixtures, 30 * 3600); // 30h old

    $run = $service->runDaily(gmdate('Y-m-d', strtotime('+1 day')));
    assert_equals('NO_QUALIFIED_TICKET', $run['status']);
    assert_equals(10, $run['evaluated']);
    assert_equals(10, $run['rejections'], 'one rejection per fixture, not 10 fixtures × 3 markets');
    assert_equals(10, (int) ($run['rejectionSummary']['STALE_ODDS'] ?? 0), 'STALE_ODDS counted once per fixture');
    assert_equals(0, (int) ($run['rejectionSummary']['INSUFFICIENT_DATA'] ?? 0), 'no double INSUFFICIENT_DATA count for the same predictions');
    assert_equals(0, $run['predictionsRecorded'], 'no predictions generated for a shared upstream failure');
    assert_equals(10, $run['diagnostics']['fixturesRejectedStaleOdds']);
    assert_equals(10, $run['diagnostics']['oddsRefreshAttempts'], 'refresh attempted once per fixture');
    assert_equals(10, $run['diagnostics']['oddsProvidersNoCoverage']['repro-stale']);
    // The message carries the human-readable funnel.
    assert_contains('funnel:', $run['message']);
    $daily = $repo->findDailyTicket(gmdate('Y-m-d', strtotime('+1 day')));
    assert_equals(10, (int) $daily['rejection_summary']['STALE_ODDS']);
    assert_true(isset($daily['rejection_summary']['_diagnostics']), 'diagnostics persisted with the daily row');
    assert_equals('repro-stale', ($daily['rejection_summary']['_diagnostics']['rejectionReasonsByProvider']['STALE_ODDS'] ?? [])['repro-stale'] ?? null ? 'repro-stale' : null, 'the failing provider is attributed');
});

test('missing mandatory market data: ONE INSUFFICIENT_DATA rejection per fixture with missing fields', function () {
    // Fresh odds, no recent form → the market-mandatory input is missing.
    // Optional sources (injuries, H2H, lineups) are absent too and must NOT
    // appear as rejection reasons — they only lower the quality score.
    $fixtures = fx_stale_fixtures(8, false);
    $oddsByExt = [];
    foreach ($fixtures as $i => $f) $oddsByExt['f' . $i] = fx_stale_odds_rows();
    $provider = fx_stale_provider('repro-noform', $fixtures, $oddsByExt);
    [$repo, , , $service] = fx_stale_stack($provider);
    fx_stale_approve_calibration($repo);

    $run = $service->runDaily(gmdate('Y-m-d', strtotime('+1 day')));
    assert_equals('NO_QUALIFIED_TICKET', $run['status']);
    assert_equals(8, (int) ($run['rejectionSummary']['INSUFFICIENT_DATA'] ?? 0), 'one INSUFFICIENT_DATA per fixture');
    assert_equals(0, (int) ($run['rejectionSummary']['STALE_ODDS'] ?? 0), 'fresh odds never add a stale rejection');
    assert_equals(0, $run['predictionsRecorded']);
    assert_equals(8, $run['diagnostics']['fixturesMissingMandatoryData']);
    assert_equals(8, $run['diagnostics']['fixturesWithFreshOdds'], 'odds stage passed first');
    // Top rejection reasons expose the primary reason + the causing provider.
    assert_equals(8, (int) ($run['diagnostics']['topRejectionReasons']['INSUFFICIENT_DATA'] ?? 0));
    assert_equals(8, (int) ($run['diagnostics']['rejectionReasonsByProvider']['INSUFFICIENT_DATA']['repro-noform'] ?? 0));
});

test('cross-provider safety: a provider without a verified fixture id is never asked for odds', function () {
    // Fixture ids are provider-specific: querying provider B with provider
    // A's id could silently attach ANOTHER match's odds. Providers without a
    // verified id (no cross-reference) must be skipped without a request.
    $fixtures = fx_stale_fixtures(5, true); // no apiFootballId cross-reference
    $primary = fx_stale_provider('iso-a', $fixtures, array_combine(
        array_map(fn($f) => $f['externalId'], $fixtures),
        array_map(fn($f) => fx_stale_odds_rows(), $fixtures)
    ));
    $foreign = fx_stale_provider('iso-b', $fixtures, ['f0' => fx_stale_odds_rows()]); // would answer — must never be asked
    $repo = new SportsRepositoryStub();
    $audit = fx_stale_audit();
    $providers = new SportsProviderManager();
    $providers->register($primary);
    $providers->register($foreign);
    $config = new ConfigurationService($repo, $audit);
    $service = new DailyTicketService($repo, $audit, $providers, $config, new DataQualityEngine(), new PredictionPipeline(), new TicketOptimizer(new CorrelationEngine()), new TicketGovernance($repo, $audit, new CorrelationEngine()), new DecisionRecorder($repo, $audit));
    fx_stale_approve_calibration($repo);
    $run = $service->runDaily(gmdate('Y-m-d', strtotime('+1 day')));
    assert_equals(0, $foreign->oddsCalls, 'the foreign provider was never asked for another namespace\'s fixture id');
    assert_equals(15, $run['predictionsRecorded'], 'the fixture provider itself served the odds');
});

test('a genuinely empty day stays NO_QUALIFIED_TICKET — no fabricated odds or predictions', function () {
    // Fresh odds + form + calibration, but no model edge exists: the prices
    // are so low that even a 0.99-clamped probability yields negative EV.
    // Nothing is loosened to force a ticket; the diagnostics prove the
    // pipeline itself worked.
    $fixtures = fx_stale_fixtures(5, true);
    $rows = [
        ['market' => 'MATCH_RESULT', 'selection' => 'AWAY', 'decimalOdds' => 1.005, 'observedAt' => gmdate('c')],
        ['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 1.005, 'observedAt' => gmdate('c')],
        ['market' => 'BTTS', 'selection' => 'YES', 'decimalOdds' => 1.005, 'observedAt' => gmdate('c')],
    ];
    $oddsByExt = [];
    foreach ($fixtures as $f) $oddsByExt[$f['externalId']] = $rows;
    $provider = fx_stale_provider('repro-noedge', $fixtures, $oddsByExt);
    [$repo, , , $service] = fx_stale_stack($provider);
    fx_stale_approve_calibration($repo);

    $run = $service->runDaily(gmdate('Y-m-d', strtotime('+1 day')));
    assert_equals('NO_QUALIFIED_TICKET', $run['status']);
    assert_equals(15, $run['predictionsRecorded'], 'the pipeline evaluated every candidate (data/odds stages healthy)');
    assert_equals(0, $run['diagnostics']['fixturesRejectedStaleOdds']);
    assert_equals(0, $run['diagnostics']['fixturesMissingMandatoryData']);
    assert_equals(15, $run['diagnostics']['predictionsGenerated']);
    assert_equals(0, $run['diagnostics']['positiveValueCandidates'], 'no fake edge was manufactured');
    assert_equals(0, count($repo->tickets), 'no ticket forced');
    assert_contains('NO VALUE TICKET TODAY', $run['message']);
});

test('data quality: optional enrichment improves the score but never blocks prediction', function () {
    $engine = new DataQualityEngine();
    $fixture = ['externalId' => 'f1', 'homeTeam' => 'Home', 'awayTeam' => 'Away', 'competition' => 'League', 'kickoff' => gmdate('c')];
    // Core-only: fixture + mandatory form + fresh odds. No injuries/H2H/lineups.
    $core = $engine->assess($fixture, [
        'mandatoryFields' => DataQualityEngine::mandatoryFieldsForMarket('MATCH_RESULT'),
        'availableFields' => ['recentForm'],
        'oddsAvailable' => true, 'oddsFresh' => true, 'oddsAgeSeconds' => 600, 'maxOddsAgeSeconds' => 21600,
        'providerReliability' => 0.9, 'minDataQuality' => 75,
    ]);
    assert_equals([], $core['missingMandatory'], 'core data satisfies the MATCH_RESULT mandatory set');
    assert_true($core['eligibleForPrediction'], 'a Match Winner prediction needs only its mandatory fields');
    assert_true($core['eligibleForTicket'], 'core data reaches the ticket floor');
    assert_equals(['recentForm'], $core['mandatoryFields'], 'mandatory fields are transparent');
    assert_not_contains('injuries', implode(',', $core['missing']), 'absent optional enrichment is not a failure');
    // Optional enrichment raises the score.
    $enriched = $engine->assess($fixture, [
        'mandatoryFields' => ['recentForm'],
        'availableFields' => ['recentForm', 'injuries', 'lineups', 'historical', 'marketLiquidity', 'restDays'],
        'oddsAvailable' => true, 'oddsFresh' => true, 'oddsAgeSeconds' => 600, 'maxOddsAgeSeconds' => 21600,
        'providerReliability' => 0.9, 'minDataQuality' => 75,
    ]);
    assert_true($enriched['score'] > $core['score'], 'optional enrichment improves the quality score');
    // Missing mandatory form data blocks prediction — explicitly, with the field named.
    $noForm = $engine->assess($fixture, ['availableFields' => [], 'oddsAvailable' => true, 'oddsFresh' => true, 'oddsAgeSeconds' => 0, 'providerReliability' => 0.9]);
    assert_equals(['recentForm'], $noForm['missingMandatory']);
    assert_false($noForm['eligibleForPrediction']);
    assert_contains('recentForm', implode(',', $noForm['missing']));
});
