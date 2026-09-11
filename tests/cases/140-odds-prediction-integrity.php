<?php
use AIWorkforce\Football\FootballConfiguration;
use AIWorkforce\Football\PredictionMarkets;
use AIWorkforce\Persistence\AuditRepository;
use AIWorkforce\Sports\ConfidenceEngine;
use AIWorkforce\Sports\ConfigurationService;
use AIWorkforce\Sports\CorrelationEngine;
use AIWorkforce\Sports\DailyTicketService;
use AIWorkforce\Sports\DataQualityEngine;
use AIWorkforce\Sports\DecisionRecorder;
use AIWorkforce\Sports\FeatureEngineeringEngine;
use AIWorkforce\Sports\MatchIntelligenceEngine;
use AIWorkforce\Sports\OddsBounds;
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

function fx140_audit(): AuditRepository
{
    return new class implements AuditRepository { public array $events = []; public function emit(string $t, string $s, array $d = [], string $a = 'system'): void { $this->events[] = ['type' => $t, 'actor' => $a, 'detail' => $d]; } public function recent(int $l = 100): array { return []; } };
}

function fx140_pipeline(): PredictionPipeline
{
    return new PredictionPipeline(new MatchIntelligenceEngine(new OddsFreshnessEngine()), new FeatureEngineeringEngine(), new PredictionEngine(), new ValueEngine(), new RiskEngine(), new CorrelationEngine(), new ConfidenceEngine());
}

/** One upcoming fixture with verified form context, shaped like a provider row. */
function fx140_fixture(string $externalId, string $home, string $away, string $kickoff): array
{
    return [
        'externalId' => $externalId,
        'homeTeam' => $home, 'awayTeam' => $away,
        'competition' => 'Test League',
        'kickoff' => $kickoff,
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

function fx140_provider(string $id, array $fixtures, array $oddsByExt): SportsDataProvider
{
    return new class($id, $fixtures, $oddsByExt) implements SportsDataProvider {
        public function __construct(private string $id, private array $fixtures, private array $odds) {}
        public function id(): string { return $this->id; }
        public function health(): array { return ['status' => 'ONLINE', 'reliability' => 0.9]; }
        public function fixtures(array $q): array { return $this->fixtures; }
        public function odds(string $e): array { return $this->odds[$e] ?? []; }
        public function results(string $e): array { return []; }
    };
}

function fx140_service(SportsRepositoryStub $repo, AuditRepository $audit, SportsProviderManager $providers): DailyTicketService
{
    return new DailyTicketService($repo, $audit, $providers, new ConfigurationService($repo, $audit), new DataQualityEngine(), fx140_pipeline(), new TicketOptimizer(new CorrelationEngine()), new TicketGovernance($repo, $audit, new CorrelationEngine()), new DecisionRecorder($repo, $audit));
}

function fx140_approve_calibration(SportsRepositoryStub $repo): void
{
    $modelId = $repo->ensureModelVersion(['modelName' => PredictionEngine::MODEL_NAME, 'modelVersion' => PredictionEngine::MODEL_VERSION, 'featureVersion' => FeatureEngineeringEngine::VERSION]);
    $repo->saveCalibration(['model_version_id' => $modelId, 'method' => 'platt', 'intercept' => 0.2, 'slope' => 1.5, 'samples' => 40, 'ece' => 0.02, 'status' => 'APPROVED', 'created_by' => 'admin', 'created_at' => gmdate('c')]);
}

test('odds integrity: plausibility bounds reject garbage prices', function () {
    assert_true(OddsBounds::validDecimalOdds(1.85), 'real quote accepted');
    assert_true(OddsBounds::validDecimalOdds('2.10'), 'numeric string accepted');
    assert_true(OddsBounds::validDecimalOdds(1.85, 'TOTAL_GOALS'), 'in-cap market price accepted');
    assert_false(OddsBounds::validDecimalOdds(0), 'zero is not a price');
    assert_false(OddsBounds::validDecimalOdds(1.0), 'stake-back is not a price');
    assert_false(OddsBounds::validDecimalOdds(-2.5), 'negative is not a price');
    assert_false(OddsBounds::validDecimalOdds(null), 'null is not a price');
    assert_false(OddsBounds::validDecimalOdds(''), 'empty is not a price');
    assert_false(OddsBounds::validDecimalOdds('abc'), 'non-numeric is not a price');
    assert_false(OddsBounds::validDecimalOdds(INF), 'infinite is not a price');
    assert_false(OddsBounds::validDecimalOdds(NAN), 'NaN is not a price');
    assert_false(OddsBounds::validDecimalOdds(101.0, 'TOTAL_GOALS'), 'TOTAL_GOALS above its 100 cap rejected');
    assert_false(OddsBounds::validDecimalOdds(5000.0), 'absurd price above the default cap rejected');
    assert_true(OddsBounds::maxFor('TOTAL_GOALS') === 100.0, 'TOTAL_GOALS cap is 100');
    assert_true(OddsBounds::maxFor('NO_SUCH_MARKET') === 1000.0, 'unknown markets fall back to the default cap');
});

test('odds integrity: ingestion rejects invalid rows and keeps provenance', function () {
    $row = SportsDataNormalizer::odds(
        ['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 1.8, 'observedAt' => gmdate('c'),
            'bookmaker' => 'bk-7', 'fixtureId' => 'fx-1', 'updatedAt' => gmdate('c'), 'openingDecimalOdds' => 1.9],
        'alpha'
    );
    assert_equals('alpha', $row['provider']);
    assert_equals(1.8, $row['decimalOdds']);
    assert_equals('bk-7', $row['bookmaker'], 'bookmaker provenance preserved');
    assert_equals('fx-1', $row['fixtureId'], 'provider fixture id preserved');
    assert_true(isset($row['updatedAt'], $row['openingDecimalOdds']), 'provider stamps preserved');
    foreach ([0, 0.0, -1.5, null, 'nope'] as $bad) {
        assert_throws(InvalidArgumentException::class, fn() => SportsDataNormalizer::odds(
            ['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => $bad, 'observedAt' => gmdate('c')], 'alpha'
        ), 'price ' . var_export($bad, true) . ' rejected at ingestion');
    }
    assert_throws(InvalidArgumentException::class, fn() => SportsDataNormalizer::odds(
        ['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 5000.0, 'observedAt' => gmdate('c')], 'alpha'
    ), 'absurd price rejected at ingestion');
    assert_throws(InvalidArgumentException::class, fn() => SportsDataNormalizer::odds(
        ['market' => '', 'selection' => 'OVER_1_5', 'decimalOdds' => 1.8, 'observedAt' => gmdate('c')], 'alpha'
    ), 'empty market rejected at ingestion');
});

test('odds integrity: value engine refuses absurd prices', function () {
    $engine = new ValueEngine();
    $ready = ['decision' => 'PREDICTION_READY', 'calibratedProbability' => 0.6, 'rawModelProbability' => 0.6];
    $noOdds = $engine->assess($ready, ['decimalOdds' => 0]);
    assert_equals('ODDS_UNAVAILABLE', $noOdds['reason']);
    assert_false($noOdds['qualified']);
    $absurd = $engine->assess($ready, ['decimalOdds' => 5000.0, 'market' => 'TOTAL_GOALS']);
    assert_equals('UNREALISTIC_ODDS', $absurd['reason'], 'absurd price rejected, never priced');
    assert_false($absurd['qualified']);
    $real = $engine->assess($ready, ['decimalOdds' => 1.8, 'market' => 'TOTAL_GOALS']);
    assert_true($real['qualified'], 'real price still prices');
    assert_true($real['expectedValue'] > 0, '0.6 × 1.8 − 1 is positive value');
});

test('odds integrity: pipeline without odds has no market and is rejected as ODDS_UNAVAILABLE', function () {
    $match = fx140_fixture('m1', 'HomeA', 'AwayA', gmdate('c', time() + 86400)) + ['id' => 1, 'payload' => ['context' => ['marketLiquidity' => 50000]]];
    $out = fx140_pipeline()->evaluate($match, null, ['score' => 90, 'band' => 'HIGH'], null, [], null, []);
    assert_null($out['market'], 'no odds row means no market — never a defaulted TOTAL_GOALS');
    assert_null($out['selection'], 'no odds row means no selection — never a defaulted OVER_1_5');
    assert_equals('REJECTED', $out['decision']);
    assert_in_array('ODDS_UNAVAILABLE', $out['rejectionReasons']);
    assert_equals('ODDS_UNAVAILABLE', $out['primaryReason']);
});

test('odds integrity: decision recorder refuses identity-less predictions', function () {
    $repo = new SportsRepositoryStub();
    $recorder = new DecisionRecorder($repo, fx140_audit());
    assert_throws(InvalidArgumentException::class,
        fn() => $recorder->recordPrediction(0, ['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5'], [], [], [], []),
        'match id 0 refused');
    assert_throws(InvalidArgumentException::class,
        fn() => $recorder->recordPrediction(1, [], [], [], [], []),
        'missing market/selection refused');
    assert_equals(0, count($repo->listPredictions([], 10)), 'nothing stored');
});

test('odds integrity: ticket governance refuses legs without id, market or price', function () {
    $repo = new SportsRepositoryStub();
    $governance = new TicketGovernance($repo, fx140_audit(), new CorrelationEngine());
    $good = ['matchId' => 7, 'market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'odds' => 1.8,
        'oddsTimestamp' => gmdate('c'), 'value' => ['odds' => 1.8]];
    foreach ([
        'no match id' => array_merge($good, ['matchId' => 0]),
        'missing match id' => (function () use ($good) { unset($good['matchId']); return $good; })(),
        'no market' => array_merge($good, ['market' => '']),
        'unsupported market' => array_merge($good, ['market' => 'NO_SUCH_MARKET', 'selection' => 'X']),
        'no price' => array_merge($good, ['odds' => 0, 'value' => ['odds' => 0]]),
        'absurd price' => array_merge($good, ['odds' => 5000.0, 'value' => ['odds' => 5000.0]]),
        'no odds timestamp' => array_merge($good, ['oddsTimestamp' => null]),
    ] as $label => $leg) {
        assert_throws(InvalidArgumentException::class,
            fn() => $governance->record(['status' => 'QUALIFIED', 'selections' => [$leg]], 'v1'),
            $label . ' aborts the ticket');
    }
    assert_equals(0, count($repo->tickets), 'no partial ticket stored');
});

test('odds integrity: collectAll gathers every provider and isolates failures', function () {
    $manager = new SportsProviderManager();
    $manager->register(fx140_provider('alpha', [], []));
    $manager->register(fx140_provider('beta', [], []));
    $collected = $manager->collectAll('fixtures', function (SportsDataProvider $provider): array {
        if ($provider->id() === 'beta') throw new RuntimeException('beta is down');
        return ['seen' => $provider->id()];
    });
    assert_equals(['alpha'], array_keys($collected['results']), 'answering provider collected');
    assert_true(isset($collected['failures']['beta']), 'failing provider recorded, not fatal');
    assert_equals('', $collected['summary'], 'no summary needed when a provider answered');
    $empty = $manager->collectAll('fixtures', function (SportsDataProvider $p): array { throw new RuntimeException('all down'); });
    assert_equals([], $empty['results']);
    assert_true($empty['summary'] !== '', 'total outage still summarised');
});

test('odds integrity: football price sheet ignores invalid legs and names sources', function () {
    $markets = new PredictionMarkets(new FootballConfiguration());
    $now = gmdate('c');
    $sheet = $markets->priceSheet([
        ['market' => 'MATCH_WINNER', 'selection' => 'HOME', 'decimalOdds' => 2.1, 'observedAt' => $now, 'provider' => 'alpha'],
        ['market' => 'MATCH_WINNER', 'selection' => 'DRAW', 'decimalOdds' => 0, 'observedAt' => $now, 'provider' => 'alpha'],
        ['market' => 'MATCH_WINNER', 'selection' => 'AWAY', 'decimalOdds' => 9999.0, 'observedAt' => $now, 'provider' => 'beta'],
    ], 'MATCH_WINNER');
    assert_true(isset($sheet['quotes']['HOME']), 'real quote kept');
    assert_equals(2.1, $sheet['quotes']['HOME']['odds']);
    assert_equals('alpha', $sheet['quotes']['HOME']['source'], 'quote names its feed');
    assert_false(isset($sheet['quotes']['DRAW']), 'zero price never becomes a quote');
    assert_false(isset($sheet['quotes']['AWAY']), 'absurd price never becomes a quote');
    assert_true($sheet['ignored'] >= 2, 'both invalid legs counted as ignored');
    assert_true(is_string($sheet['note']) && $sheet['note'] !== '', 'the gap is disclosed, not silent');
});

test('odds integrity E2E: merged providers, invalid odds rejected, nothing invented', function () {
    $repo = new SportsRepositoryStub();
    $audit = fx140_audit();
    $kickoff = gmdate('Y-m-d\\TH:i:00\\+00:00', strtotime('+1 day 15:00:00'));
    $now = gmdate('c');
    $providers = new SportsProviderManager();
    // alpha and beta both list the same match (different external ids) — it is
    // stored once per provider but must be evaluated ONCE, not per copy.
    $providers->register(fx140_provider('alpha', [
        fx140_fixture('a1', 'AlphaHome', 'AlphaAway', $kickoff),
        fx140_fixture('a2', 'ZeroHome', 'ZeroAway', gmdate('Y-m-d\\TH:i:00\\+00:00', strtotime('+1 day 17:00:00'))),
    ], [
        'a1' => [['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 1.8, 'observedAt' => $now]],
        // every price on a2 is garbage: zero, negative, null, absurd
        'a2' => [
            ['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 0, 'observedAt' => $now],
            ['market' => 'TOTAL_GOALS', 'selection' => 'OVER_2_5', 'decimalOdds' => -1.5, 'observedAt' => $now],
            ['market' => 'TOTAL_GOALS', 'selection' => 'UNDER_3_5', 'decimalOdds' => null, 'observedAt' => $now],
            ['market' => 'TOTAL_GOALS', 'selection' => 'OVER_3_5', 'decimalOdds' => 5000.0, 'observedAt' => $now],
        ],
    ]));
    $providers->register(fx140_provider('beta', [
        fx140_fixture('b1', 'AlphaHome', 'AlphaAway', $kickoff),
        fx140_fixture('b3', 'BetaHome', 'BetaAway', gmdate('Y-m-d\\TH:i:00\\+00:00', strtotime('+1 day 19:00:00'))),
    ], [
        'b1' => [['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 1.9, 'observedAt' => $now]],
        'b3' => [['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 2.0, 'observedAt' => $now]],
    ]));
    fx140_approve_calibration($repo);
    $run = fx140_service($repo, $audit, $providers)->runDaily(gmdate('Y-m-d', strtotime('+1 day')));
    // 1) provider-scoped persistence, merged evaluation: all 4 raw fixtures
    // stored under their own provider ids, while the cross-provider
    // duplicate is evaluated ONCE (every AlphaHome prediction names the
    // same internal match row — never one prediction per provider copy).
    assert_equals(4, count($repo->matches), 'every provider fixture persisted');
    $alphaRows = array_values(array_filter($repo->matches, fn($m) => ($m['home_team'] ?? '') === 'AlphaHome'));
    assert_equals(2, count($alphaRows), 'the merged match keeps one row per provider');
    assert_not_equals($alphaRows[0]['provider_id'], $alphaRows[1]['provider_id'], 'provider ids kept separate');
    $alphaIds = array_map(fn($m) => (int) $m['id'], $alphaRows);
    $alphaPredMatchIds = array_values(array_unique(array_map(
        fn($p) => (int) $p['match_id'],
        array_filter($repo->listPredictions([], 100), fn($p) => in_array((int) $p['match_id'], $alphaIds, true))
    )));
    assert_equals(1, count($alphaPredMatchIds), 'merged duplicate evaluated once, under a single match id');
    // 2) not one invalid price reaches storage
    foreach ($repo->odds as $stored) {
        assert_true((float) $stored['decimal_odds'] > 1.0, 'stored price is quotable');
        assert_true(trim((string) $stored['market']) !== '' && trim((string) $stored['selection']) !== '', 'stored row names its market');
    }
    $zeroRows = array_values(array_filter($repo->matches, fn($m) => ($m['home_team'] ?? '') === 'ZeroHome'));
    if ($zeroRows !== []) {
        assert_equals([], $repo->listOdds((int) $zeroRows[0]['id']), 'fixture with only garbage prices keeps no odds');
    }
    // 3) every recorded prediction carries a real match id, market and price
    $preds = $repo->listPredictions([], 100);
    assert_true(count($preds) >= 1, 'merged pool still predicts on its valid odds');
    foreach ($preds as $p) {
        assert_true((int) $p['match_id'] > 0, 'prediction names a real internal match');
        assert_true(trim((string) ($p['market'] ?? '')) !== '', 'prediction names its market');
        assert_true(trim((string) ($p['selection'] ?? '')) !== '', 'prediction names its selection');
        assert_true($p['odds'] === null || (float) $p['odds'] > 1.0, 'recorded price is quotable');
        // The mandatory provenance/separation contract for a priced prediction:
        // it names the odds source and last-update, and the model probability is
        // never stored as the bookmaker price.
        if ($p['odds'] !== null) {
            $factors = is_array($p['factors'] ?? null) ? $p['factors'] : [];
            $oddsBlock = is_array($factors['odds'] ?? null) ? $factors['odds'] : [];
            assert_true(trim((string) ($oddsBlock['oddsSource'] ?? '')) !== '', 'priced prediction names its odds source');
            assert_true(trim((string) ($oddsBlock['observedAt'] ?? ($p['odds_timestamp'] ?? ''))) !== '', 'priced prediction carries the odds timestamp');
            assert_true(in_array((string) ($oddsBlock['oddsStatus'] ?? ''), ['FRESH', 'STALE'], true), 'odds freshness status is explicit');
            $implied = (float) ($p['implied_probability'] ?? 0);
            $model = (float) ($p['calibrated_probability'] ?? 0);
            assert_true($model >= 0 && $model <= 1, 'model probability is a 0..1 WINDELS number');
            assert_true(array_key_exists('implied_probability', $p) && array_key_exists('calibrated_probability', $p) && $implied >= 0 && $implied <= 1, 'bookmaker implied probability is a separate column from the model probability');
        }
    }
    // 4) the engine either qualifies a ticket or honestly refuses to force one
    assert_in_array($run['status'], ['PENDING_USER_APPROVAL', 'NO_QUALIFIED_TICKET']);
    assert_true($run['evaluated'] >= 2, 'every fixture evaluated');
    if (($run['ticketId'] ?? null) !== null) {
        $sels = $repo->ticketSelections($run['ticketId']);
        assert_true(count($sels) >= 1, 'ticket carries legs');
        foreach ($sels as $sel) {
            assert_true((int) ($sel['match_id'] ?? 0) > 0, 'leg names a real internal match');
            assert_true(trim((string) ($sel['market'] ?? '')) !== '' && $sel['market'] !== 'UNSPECIFIED', 'leg names a real market');
            assert_true((float) ($sel['odds'] ?? 0) > 1.0, 'leg carries a quotable price');
            assert_true(trim((string) ($sel['odds_source'] ?? '')) !== '', 'leg names the odds source behind its bookmaker price');
            assert_true(trim((string) ($sel['odds_timestamp'] ?? '')) !== '', 'leg carries the odds last-update timestamp');
            assert_true($sel['fair_odds'] === null || (float) $sel['fair_odds'] > 1.0, 'WINDELS fair odds are quotable when present');
            assert_true(array_key_exists('odds', $sel) && array_key_exists('fair_odds', $sel) && array_key_exists('calibrated_probability', $sel), 'bookmaker odds, WINDELS fair odds and model probability are separate columns');
        }
    }
});

test('odds integrity E2E: fixtures without any odds evaluate fully but force no ticket', function () {
    $repo = new SportsRepositoryStub();
    $audit = fx140_audit();
    $providers = new SportsProviderManager();
    $providers->register(fx140_provider('alpha', [
        fx140_fixture('n1', 'NoOddsHome1', 'NoOddsAway1', gmdate('Y-m-d\\TH:i:00\\+00:00', strtotime('+1 day 15:00:00'))),
        fx140_fixture('n2', 'NoOddsHome2', 'NoOddsAway2', gmdate('Y-m-d\\TH:i:00\\+00:00', strtotime('+1 day 17:00:00'))),
    ], []));
    fx140_approve_calibration($repo);
    $run = fx140_service($repo, $audit, $providers)->runDaily(gmdate('Y-m-d', strtotime('+1 day')));
    assert_equals('NO_QUALIFIED_TICKET', $run['status'], 'no odds anywhere means no forced ticket');
    assert_equals(0, count($repo->tickets), 'nothing stored');
    assert_equals(0, count($repo->listPredictions([], 100)), 'no odds rows means no predictions recorded');
    assert_equals([], $repo->odds, 'no odds rows means no stored odds');
});

test('qualified policy: built-in defaults demand 75%+ confidence, 80+ quality, LOW correlation', function () {
    $defaults = ConfigurationService::defaults();
    assert_equals(75.0, (float) $defaults['min_confidence'], 'weak 66/68% tickets are rejected by default');
    assert_equals(80, (int) $defaults['min_data_quality']);
    assert_equals('LOW', $defaults['max_correlation']);
    assert_equals(5, (int) $defaults['max_selections']);
    assert_equals('CONSERVATIVE', $defaults['risk_level']);
    assert_equals(5.0, (float) $defaults['target_odds_min']);
    assert_equals(8.0, (float) $defaults['target_odds_max']);
    assert_equals('USER_APPROVAL_REQUIRED', $defaults['engine_mode']);
    // Operators may still lower the floors explicitly (append-only, audited).
    $service = new ConfigurationService(new SportsRepositoryStub(), fx140_audit());
    assert_true($service->update(['min_confidence' => 30.0, 'min_data_quality' => 60], 'admin', 'test override')['ok'], 'explicit lower floors remain permitted');
    assert_false($service->update(['min_confidence' => 20.0], 'admin', 'test override')['ok'], 'self-contradictory gates are still refused');
});

test('qualified policy: 66/68/74% confidence legs are rejected, 75%+ legs are eligible', function () {
    $mk = fn(int $id, string $league, float $conf, float $odds) => [
        'matchId' => $id, 'competition' => $league, 'market' => 'TOTAL_GOALS',
        'value' => ['qualified' => true, 'odds' => $odds, 'expectedValue' => 0.1],
        'risk' => ['approved' => true, 'classification' => 'LOW'],
        'confidence' => ['confidence' => $conf], 'quality' => ['score' => 95],
        'match' => ['competition' => $league],
    ];
    $config = ['targetOddsMin' => 1.1, 'targetOddsMax' => 99, 'maxSelections' => 5,
        'minConfidence' => 75.0, 'minDataQuality' => 80, 'maxCorrelation' => 'LOW'];
    foreach ([66.0, 68.0, 74.99] as $weak) {
        $out = (new TicketOptimizer())->optimize([$mk(1, 'L1', $weak, 6.0)], $config);
        assert_equals('NO_QUALIFIED_TICKET', $out['status'], $weak . '% confidence is rejected');
        assert_equals(0, $out['poolSize'], 'a weak leg never enters the pool');
    }
    $ok = (new TicketOptimizer())->optimize([$mk(1, 'L1', 75.0, 6.0), $mk(2, 'L2', 89.0, 5.5)], $config);
    assert_equals('QUALIFIED', $ok['status'], '75%+ legs are eligible');
    assert_equals(2, $ok['poolSize']);
});

test('qualified policy E2E: same-league singles below 5.00 cannot combine under LOW correlation', function () {
    $repo = new SportsRepositoryStub();
    $audit = fx140_audit();
    $providers = new SportsProviderManager();
    $fixtures = [];
    $odds = [];
    foreach ([1.55, 1.75, 1.9] as $i => $decimal) {
        $fixtures[] = fx140_fixture('s' . $i, 'SameHome' . $i, 'SameAway' . $i, gmdate('Y-m-d\\TH:i:00\\+00:00', strtotime('+1 day ' . (15 + $i) . ':00:00')));
        $odds['s' . $i] = [['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => $decimal, 'observedAt' => gmdate('c')]];
    }
    $providers->register(fx140_provider('alpha', $fixtures, $odds));
    fx140_approve_calibration($repo);
    $run = fx140_service($repo, $audit, $providers)->runDaily(gmdate('Y-m-d', strtotime('+1 day')));
    assert_equals('NO_QUALIFIED_TICKET', $run['status'], 'LOW correlation forbids the all-same-league combination');
    assert_contains('did not meet the configured prediction requirements', $run['message']);
    assert_equals(0, count($repo->tickets), 'no weak ticket forced');
    assert_true($run['predictionsRecorded'] >= 3, 'every usable odds row still evaluated and recorded');
});

test('qualified policy: the model prices Over 2.5 / Under 3.5 and settlement honors every line', function () {
    $engine = new PredictionEngine();
    $features = ['ok' => true, 'version' => FeatureEngineeringEngine::VERSION,
        'features' => ['expectedGoalsProxy' => 2.0], 'inputSources' => ['test']];
    $cal = ['approved' => true, 'intercept' => 0.0, 'slope' => 1.0, 'version' => 'test'];
    $over25 = $engine->predict('TOTAL_GOALS', 'OVER_2_5', $features, $cal);
    assert_equals('PREDICTION_READY', $over25['decision']);
    assert_close(0.3775, (float) $over25['calibratedProbability'], 0.001, 'logistic(2.0 - 2.5) prices the line');
    $under35 = $engine->predict('TOTAL_GOALS', 'UNDER_3_5', $features, $cal);
    assert_equals('PREDICTION_READY', $under35['decision']);
    assert_close(0.8176, (float) $under35['calibratedProbability'], 0.001, 'the under is the complement of its line');
    $under15 = $engine->predict('TOTAL_GOALS', 'UNDER_1_5', $features, $cal);
    assert_equals('UNSUPPORTED_MARKET', $under15['reason'], 'Under 1.5 stays an overround companion, never a candidate');
    assert_equals([2.5, 'OVER'], PredictionEngine::totalsLine('OVER_2_5'));
    assert_equals([3.5, 'UNDER'], PredictionEngine::totalsLine('UNDER_3_5'));
    assert_null(PredictionEngine::totalsLine('HOME'));
    $verify = new \AIWorkforce\Sports\ResultVerificationEngine();
    $finished = fn(int $h, int $a) => ['verified' => true, 'terminalStatus' => 'FINISHED', 'homeScore' => $h, 'awayScore' => $a];
    assert_equals('WON', $verify->settleSelection(['market' => 'TOTAL_GOALS', 'selection' => 'OVER_2_5'], $finished(2, 1))['status']);
    assert_equals('LOST', $verify->settleSelection(['market' => 'TOTAL_GOALS', 'selection' => 'OVER_2_5'], $finished(1, 0))['status']);
    assert_equals('WON', $verify->settleSelection(['market' => 'TOTAL_GOALS', 'selection' => 'UNDER_3_5'], $finished(1, 1))['status']);
    assert_equals('LOST', $verify->settleSelection(['market' => 'TOTAL_GOALS', 'selection' => 'UNDER_3_5'], $finished(3, 2))['status']);
});
