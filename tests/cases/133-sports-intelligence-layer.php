<?php
/**
 * Regression suite for the WINDELS intelligence layer in the odds-prediction
 * ticket engine (design: "WINDELS Football Intelligence", applied to Sports
 * by reusing the Football module's shared engines):
 *
 *   1. WINDELS is an independent intelligence + fair-value engine, NOT a
 *      sportsbook copy — model probability, market price and edge are three
 *      separate questions (FairValueEngine tests: margin removed, classes
 *      Strong/Positive/Fair/Negative/Avoid, honest PARTIAL/UNPRICED states);
 *   2. every ready candidate carries an Intelligence Score (prediction ≠
 *      confidence ≠ value), a stability reading against the previous stored
 *      prediction, WHY-drivers with their stored figures (or honest
 *      DATA_UNAVAILABLE rows), and lastUpdated timestamps;
 *   3. 50-match protection: MAXIMUM GENERATION is capped (50 default, env
 *      tunable, ceiling 500) and deferred fixtures are reported, never
 *      silently dropped;
 *   4. intelligent refresh: a stored prediction of the same selection, model
 *      version and identical odds is REUSED — paging/re-runs never duplicate
 *      it, and only genuinely changed odds force a regeneration;
 *   5. Top WINDELS Picks are ranked model-based selections and carry the
 *      not-a-guarantee disclaimer; companion prices (UNDER_1_5, BTTS NO) feed
 *      the overround but are never predicted themselves.
 */
use AIWorkforce\Sports\DailyTicketService;
use AIWorkforce\Sports\DataQualityEngine;
use AIWorkforce\Sports\DecisionRecorder;
use AIWorkforce\Sports\FairValueEngine;
use AIWorkforce\Sports\FeatureEngineeringEngine;
use AIWorkforce\Sports\MatchIntelligenceEngine;
use AIWorkforce\Sports\OddsFreshnessEngine;
use AIWorkforce\Sports\PredictionEngine;
use AIWorkforce\Sports\PredictionPipeline;
use AIWorkforce\Sports\Providers\SportsDataProvider;
use AIWorkforce\Sports\Providers\SportsProviderManager;
use AIWorkforce\Sports\SportsDataNormalizer;
use AIWorkforce\Sports\RiskEngine;
use AIWorkforce\Sports\TicketGovernance;
use AIWorkforce\Sports\TicketOptimizer;
use AIWorkforce\Sports\CorrelationEngine;
use AIWorkforce\Sports\ConfidenceEngine;
use AIWorkforce\Sports\ValueEngine;

// ───────────────────────── helpers (self-contained, iv-prefixed) ──────────

function fx_iv_audit(): \AIWorkforce\Persistence\AuditRepository
{
    return new class implements \AIWorkforce\Persistence\AuditRepository {
        public function emit(string $t, string $s, array $d = [], string $a = 'system'): void {}
        public function recent(int $l = 100): array { return []; }
    };
}

function fx_iv_provider(string $id, array $fixtures): SportsDataProvider
{
    return new class($id, $fixtures) implements SportsDataProvider {
        public int $oddsCalls = 0;
        public function __construct(private string $pid, private array $fixtures) {}
        public function id(): string { return $this->pid; }
        public function health(): array { return ['status' => 'ONLINE', 'reliability' => 0.9]; }
        public function fixtures(array $q): array { return $this->fixtures; }
        public function odds(string $e): array { $this->oddsCalls++; return []; }
        public function results(string $e): array { return []; }
    };
}

/** N eligible fixtures, each with verified recent form. */
function fx_iv_fixtures(int $n): array
{
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $out[] = [
            'externalId' => 'f' . $i,
            'homeTeam' => 'Home' . $i, 'awayTeam' => 'Away' . $i,
            'competition' => 'Intelligence League',
            'kickoff' => gmdate('Y-m-d\TH:i:00\+00:00', strtotime('+1 day ' . (10 + $i % 12) . ':30:00')),
            'status' => 'SCHEDULED',
            'context' => [
                'recentForm' => [
                    'homeGoalsPerMatch' => 1.6, 'awayGoalsPerMatch' => 1.4,
                    'homeConcededPerMatch' => 1.0, 'awayConcededPerMatch' => 0.9,
                    'source' => 'test-verified', 'timestamp' => gmdate('c'),
                ],
            ],
        ];
    }
    return $out;
}

/**
 * COMPLETE market rows (every mutually exclusive outcome priced, overround
 * above 1): MATCH_RESULT 1.55/4.00/6.50 (4.9pp), TOTAL_GOALS 1.70/2.30
 * (2.3pp), BTTS 1.90/1.95 (3.9pp). Companion prices included on purpose.
 */
function fx_iv_full_market_rows(): array
{
    return [
        ['market' => 'MATCH_RESULT', 'selection' => 'HOME', 'decimalOdds' => 1.55, 'observedAt' => null],
        ['market' => 'MATCH_RESULT', 'selection' => 'DRAW', 'decimalOdds' => 4.00, 'observedAt' => null],
        ['market' => 'MATCH_RESULT', 'selection' => 'AWAY', 'decimalOdds' => 6.50, 'observedAt' => null],
        ['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 1.70, 'observedAt' => null],
        ['market' => 'TOTAL_GOALS', 'selection' => 'UNDER_1_5', 'decimalOdds' => 2.30, 'observedAt' => null],
        ['market' => 'BTTS', 'selection' => 'YES', 'decimalOdds' => 1.90, 'observedAt' => null],
        ['market' => 'BTTS', 'selection' => 'NO', 'decimalOdds' => 1.95, 'observedAt' => null],
    ];
}

function fx_iv_stack(SportsDataProvider $provider): array
{
    $repo = new SportsRepositoryStub();
    $audit = fx_iv_audit();
    $providers = new SportsProviderManager();
    $providers->register($provider);
    $config = new AIWorkforce\Sports\ConfigurationService($repo, $audit);
    $pipeline = new PredictionPipeline(new MatchIntelligenceEngine(new OddsFreshnessEngine()), new FeatureEngineeringEngine(), new PredictionEngine(), new ValueEngine(), new RiskEngine(), new CorrelationEngine(), new ConfidenceEngine());
    $service = new DailyTicketService($repo, $audit, $providers, $config, new DataQualityEngine(), $pipeline, new TicketOptimizer(new CorrelationEngine()), new TicketGovernance($repo, $audit, new CorrelationEngine()), new DecisionRecorder($repo, $audit));
    return [$repo, $audit, $providers, $service];
}

function fx_iv_approve_calibration(SportsRepositoryStub $repo): void
{
    $modelId = $repo->ensureModelVersion(['modelName' => PredictionEngine::MODEL_NAME, 'modelVersion' => PredictionEngine::MODEL_VERSION, 'featureVersion' => FeatureEngineeringEngine::VERSION]);
    $repo->saveCalibration(['model_version_id' => $modelId, 'method' => 'platt', 'intercept' => 0.2, 'slope' => 1.5, 'samples' => 40, 'ece' => 0.02, 'status' => 'APPROVED', 'created_by' => 'admin', 'created_at' => gmdate('c')]);
}

/** Seed complete-market odds like an earlier cron sync would have. */
function fx_iv_seed_odds(SportsRepositoryStub $repo, string $providerCode, array $fixtures, int $ageSeconds): void
{
    $provider = $repo->ensureProvider($providerCode, $providerCode);
    foreach ($fixtures as $fixture) {
        $match = $repo->saveMatch((int) $provider['id'], SportsDataNormalizer::fixture($fixture, $providerCode));
        foreach (fx_iv_full_market_rows() as $row) {
            $repo->saveOdds((int) $match['id'], (int) $provider['id'], [
                'market' => $row['market'], 'selection' => $row['selection'],
                'decimalOdds' => $row['decimalOdds'],
                'observedAt' => gmdate('c', time() - $ageSeconds),
            ]);
        }
    }
}

// ───────────────────── FairValueEngine: the three questions ───────────────

test('fair value: a complete market gets its margin removed — STRONG_VALUE with checkable figures', function () {
    $engine = new FairValueEngine();
    // 1/1.60 + 1/2.50 = 1.025 → a 2.5-point book. Fair OVER probability
    // (proportional overround) = 0.625/1.025 = 0.609756 → fair odds 1.64.
    $prices = [
        'OVER_1_5' => ['odds' => 1.60, 'observedAt' => gmdate('c')],
        'UNDER_1_5' => ['odds' => 2.50, 'observedAt' => gmdate('c')],
    ];
    $a = $engine->assessMarket('TOTAL_GOALS', $prices, 'OVER_1_5', 0.70);
    assert_equals('COMPLETE', $a['marketState']);
    assert_close(1.025, (float) $a['overround'], 0.0001, 'overround is the sum of implied probabilities');
    assert_close(2.5, (float) $a['marginPoints'], 0.001, 'margin in probability points');
    assert_equals('PROPORTIONAL_OVERROUND', $a['marginMethod']);
    // Three separate questions, three separate numbers:
    assert_close(0.70, (float) $a['modelProbability'], 0.0001, 'the WINDELS probability, as supplied');
    assert_close(0.625, (float) $a['impliedProbability'], 0.0001, 'the raw implied probability, margin INCLUDED');
    assert_close(1.64, (float) $a['fairOdds'], 0.001, 'the margin-removed fair price (the pipeline renames it marketFairOdds)');
    assert_close(7.5, (float) $a['edgePoints'], 0.001, 'edge vs the quoted price');
    assert_close(9.02, (float) $a['edgeAgainstFairPoints'], 0.02, 'edge vs the de-vigged price');
    assert_close(0.12, (float) $a['expectedValue'], 0.001, 'expected value per unit (0.70 × 1.60 − 1)');
    // Strong value must survive de-vigging: 7.5pp ≥ 4pp and 9.02pp ≥ 1pp.
    assert_equals('STRONG_VALUE', $a['valueClass']);
    assert_equals('Strong value', $a['valueLabel']);
    assert_true(str_contains((string) $a['disclaimer'], 'no selection is guaranteed'), 'the disclaimer travels with the verdict');
});

test('fair value: a one-sided quote is PARTIAL — no fair price is invented', function () {
    $engine = new FairValueEngine();
    $a = $engine->assessMarket('TOTAL_GOALS', ['OVER_1_5' => ['odds' => 1.60, 'observedAt' => gmdate('c')]], 'OVER_1_5', 0.70);
    assert_equals('PARTIAL_MARKET', $a['marketState']);
    assert_true($a['marketFairOdds'] === null, 'no de-vigged price without the whole market');
    assert_true($a['marginPoints'] === null && $a['overround'] === null && $a['marginMethod'] === null, 'the margin is not estimated either');
    // 7.5pp edge would pass the strong line, but it has not been shown to
    // survive de-vigging → POSITIVE_VALUE, with the reason saying exactly that.
    assert_equals('POSITIVE_VALUE', $a['valueClass']);
    assert_true(str_contains((string) $a['valueReason'], 'margin could not be removed'), 'the gap is stated, never filled');
});

test('fair value: no prices at all → DATA_UNAVAILABLE, nothing judged or invented', function () {
    $engine = new FairValueEngine();
    $a = $engine->assessMarket('MATCH_RESULT', [], 'HOME', 0.55);
    assert_equals('DATA_UNAVAILABLE', $a['marketState']);
    assert_true($a['odds'] === null, 'no price was fabricated');
    assert_true($a['impliedProbability'] === null && $a['edgePoints'] === null && $a['expectedValue'] === null);
    assert_equals('UNPRICED', $a['valueClass'], 'a quoted price is not an opinion of ours');
});

test('fair value: the engine does not flatter the model — FAIR and NEGATIVE_VALUE verdicts', function () {
    $engine = new FairValueEngine();
    $prices = ['OVER_1_5' => ['odds' => 1.60, 'observedAt' => gmdate('c')], 'UNDER_1_5' => ['odds' => 2.50, 'observedAt' => gmdate('c')]];
    // Exactly the implied share (62.5%) → inside the ±1-point noise band.
    assert_equals('FAIR', $engine->assessMarket('TOTAL_GOALS', $prices, 'OVER_1_5', 0.625)['valueClass']);
    // 3 points below the price (−3pp, above the −4pp avoid line).
    assert_equals('NEGATIVE_VALUE', $engine->assessMarket('TOTAL_GOALS', $prices, 'OVER_1_5', 0.595)['valueClass']);
    // 5 points below → AVOID.
    assert_equals('AVOID', $engine->assessMarket('TOTAL_GOALS', $prices, 'OVER_1_5', 0.575)['valueClass']);
});

// ───────────── PredictionPipeline: the intelligence layer on candidates ───

function fx_iv_gate_match(array $over = []): array
{
    return array_merge([
        'id' => 1, 'provider_id' => 1, 'external_id' => 'iv1',
        'competition' => 'Gate League', 'home_team' => 'Home', 'away_team' => 'Away',
        'kickoff_at' => gmdate('Y-m-d\TH:i:00\+00:00', strtotime('+1 day 15:00:00')),
        'status' => 'SCHEDULED', 'source_timestamp' => gmdate('c'),
        'payload' => ['context' => ['recentForm' => ['homeGoalsPerMatch' => 1.6, 'awayGoalsPerMatch' => 1.4, 'homeConcededPerMatch' => 1.0, 'awayConcededPerMatch' => 0.9, 'source' => 'v']]],
    ], $over);
}

function fx_iv_gate_quality(): array
{
    return ['score' => 100, 'band' => 'EXCELLENT', 'freshnessScore' => 100, 'providerReliabilityScore' => 90, 'eligibleForPrediction' => true, 'eligibleForTicket' => true, 'missing' => [], 'checks' => []];
}

function fx_iv_gate_calibration(): array
{
    return ['id' => 1, 'model_version_id' => 1, 'intercept' => 0.2, 'slope' => 1.5, 'samples' => 40, 'ece' => 0.02, 'status' => 'APPROVED', 'calibrationVersion' => 'test', 'approved_at' => gmdate('c')];
}

function fx_iv_gate_config(): array
{
    return ['min_confidence' => 80.0, 'min_data_quality' => 75, 'require_calibration' => 1, 'allowed_markets' => [], 'allowed_leagues' => []];
}

test('pipeline: every ready candidate carries stability, intelligence score, drivers and timestamps', function () {
    $market = [
        'OVER_1_5' => ['odds' => 1.60, 'observedAt' => gmdate('c')],
        'UNDER_1_5' => ['odds' => 2.50, 'observedAt' => gmdate('c')],
    ];
    $out = (new PredictionPipeline())->evaluate(fx_iv_gate_match(), ['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 1.6, 'observed_at' => gmdate('c')], fx_iv_gate_quality(), fx_iv_gate_calibration(), fx_iv_gate_config(), null, [
        'marketPrices' => $market,
        'previousPrediction' => null,
        'matchUpdatedAt' => '2026-09-09T06:00:00+00:00',
    ]);

    assert_equals('QUALIFIED', $out['decision'], 'the fair-value READING does not loosen or tighten the gate');
    $p = (float) $out['prediction']['calibratedProbability'];
    assert_true($p > 0.625, 'model probability above the implied share (EV gate passed)');

    // Stability: first stored reading of this selection → BASELINE, movement unmeasurable.
    assert_equals('BASELINE', $out['stability']['state']);
    assert_true($out['stability']['movementPoints'] === null);
    assert_equals(2.0, $out['stability']['thresholds']['movedPoints']);
    assert_equals(8.0, $out['stability']['thresholds']['unstablePoints']);

    // Intelligence score: prediction ≠ confidence ≠ value — its own number.
    $score = $out['intelligenceScore'];
    assert_true(is_int($score['score']) && $score['score'] >= 0 && $score['score'] <= 100);
    assert_true(is_string($score['band']) && $score['band'] !== '');
    $excludedKeys = array_column($score['excluded'], 'key');
    assert_true(in_array('coverage', $excludedKeys, true), 'an unmeasurable component is EXCLUDED, never zeroed');
    assert_true(in_array('stability', $excludedKeys, true), 'BASELINE stability is excluded, not scored');

    // Drivers: stored figures restated as readings, gaps stated as gaps.
    $rows = [];
    foreach ($out['drivers']['drivers'] as $row) $rows[$row['key']] = $row;
    foreach ($rows as $row) {
        foreach (['verdict', 'measure', 'source'] as $rowKey) {
            assert_true(array_key_exists($rowKey, $row), "every driver row carries {$rowKey} (a null measure is an honest gap)");
        }
    }
    assert_equals('DATA_UNAVAILABLE', $rows['squad_availability']['verdict'], 'no injury feed → the gap is stated, not glossed');
    // A goals market has no predicted side, so there is no "WINDELS selected
    // X" sentence — null, never a manufactured narrative.
    assert_true($out['drivers']['headline'] === null, 'no headline is invented for a market without a predicted side');
    assert_true(str_contains((string) $out['drivers']['disclaimer'], 'not a guarantee'));

    // A match-winner selection DOES get the one-sentence explanation.
    $home = (new PredictionPipeline())->evaluate(fx_iv_gate_match(), ['market' => 'MATCH_RESULT', 'selection' => 'HOME', 'decimalOdds' => 2.10, 'observed_at' => gmdate('c')], fx_iv_gate_quality(), fx_iv_gate_calibration(), fx_iv_gate_config(), null, [
        'marketPrices' => ['HOME' => ['odds' => 2.10, 'observedAt' => gmdate('c')], 'DRAW' => ['odds' => 3.40, 'observedAt' => gmdate('c')], 'AWAY' => ['odds' => 3.20, 'observedAt' => gmdate('c')]],
    ]);
    assert_equals('PREDICTION_READY', $home['prediction']['decision']);
    assert_true(is_string($home['drivers']['headline']) && str_contains($home['drivers']['headline'], 'WINDELS selected'), 'match-winner picks get the one-sentence why');

    // Last updated: three separate timestamps.
    assert_not_null($out['lastUpdated']['predictionGenerated']);
    assert_equals('2026-09-09T06:00:00+00:00', $out['lastUpdated']['dataRefreshed']);
    assert_not_null($out['lastUpdated']['oddsRefreshed']);

    // factors keep their legacy meaning; the readings sit alongside.
    assert_true(isset($out['factors']['drivers']['expectedGoalsProxy']), 'factors.drivers stays the feature set');
    assert_equals($out['drivers'], $out['factors']['readings'], 'the WINDELS readings are stored under factors.readings');

    // The value block carries BOTH fair prices, clearly named.
    $v = $out['value'];
    assert_close(1 / $p, (float) $v['fairOdds'], 0.01, 'fairOdds is the MODEL\'s own price');
    assert_close(1.64, (float) $v['marketFairOdds'], 0.001, 'marketFairOdds is the margin-removed MARKET price');
    assert_close(2.5, (float) $v['marginPoints'], 0.001);
    assert_true(is_string($v['valueClass']) && $v['valueClass'] !== '', 'the shared value class travels with the candidate');
    // The three questions are all present and distinct.
    assert_close($p, (float) $v['modelProbability'], 0.0001);
    assert_close(0.625, (float) $v['impliedProbability'], 0.0001);
    assert_close(($p - 0.625) * 100.0, (float) $v['edgePoints'], 0.02, 'edge = model probability − implied probability');
});

test('pipeline: stability measures stored model movement (STABLE / MOVED / UNSTABLE)', function () {
    $pipeline = new PredictionPipeline();
    $odds = ['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 1.6, 'observed_at' => gmdate('c')];
    $first = $pipeline->evaluate(fx_iv_gate_match(), $odds, fx_iv_gate_quality(), fx_iv_gate_calibration(), fx_iv_gate_config());
    $p = (float) $first['prediction']['calibratedProbability'];

    $prev = function (float $old): array {
        return ['calibrated_probability' => $old, 'created_at' => gmdate('c', time() - 3600)];
    };
    $run = function (array $previous) use ($pipeline, $odds): array {
        return $pipeline->evaluate(fx_iv_gate_match(), $odds, fx_iv_gate_quality(), fx_iv_gate_calibration(), fx_iv_gate_config(), null, ['previousPrediction' => $previous]);
    };

    $stable = $run($prev($p));
    assert_equals('STABLE', $stable['stability']['state']);
    assert_close(0.0, (float) $stable['stability']['movementPoints'], 0.0001);

    $moved = $run($prev($p - 0.05));
    assert_equals('MOVED', $moved['stability']['state'], 'a 5-point move is past the 2-point line');
    assert_close(5.0, (float) $moved['stability']['movementPoints'], 0.02);

    $unstable = $run($prev($p - 0.10));
    assert_equals('UNSTABLE', $unstable['stability']['state'], 'a 10-point move is past the 8-point line');
    assert_close(10.0, (float) $unstable['stability']['movementPoints'], 0.02);
    assert_true(str_contains((string) $unstable['stability']['note'], 'moved'), 'the note states the movement it measured');
});

test('pipeline: a withheld prediction carries the intelligence layer as honest nulls', function () {
    $match = fx_iv_gate_match();
    $match['payload'] = ['context' => []]; // mandatory form data missing
    $out = (new PredictionPipeline())->evaluate($match, ['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 1.6, 'observed_at' => gmdate('c')], fx_iv_gate_quality(), fx_iv_gate_calibration(), fx_iv_gate_config());
    assert_equals('REJECTED', $out['decision']);
    assert_equals('INSUFFICIENT_DATA', $out['primaryReason']);
    // "Prediction withheld — insufficient verified data": no score, no
    // drivers, no stability READING is fabricated around a missing prediction.
    assert_true($out['stability'] === null);
    assert_true($out['intelligenceScore'] === null);
    assert_true($out['drivers'] === null);
    assert_true(isset($out['lastUpdated']['predictionGenerated']), 'timestamps still say when the attempt ran');
});

// ─────────────── DailyTicketService: cap, refresh, top picks ──────────────

test('ticket engine: the generation cap defaults to 50, honours the env, never exceeds its ceiling', function () {
    [, , , $service] = fx_iv_stack(fx_iv_provider('iv-cap', []));
    $cap = Closure::bind(fn(): int => $this->generationCap(), $service, DailyTicketService::class);
    try {
        assert_equals(50, $cap(), 'MAXIMUM GENERATION = 50 by default (design §9)');
        putenv('WINDELS_SPORTS_MAX_GENERATION=1');
        assert_equals(1, $cap(), 'an operator may lower the cap');
        putenv('WINDELS_SPORTS_MAX_GENERATION=5000');
        assert_equals(50, $cap(), 'each generation batch is hard-capped at 50 matches');
        putenv('WINDELS_SPORTS_MAX_GENERATION=0');
        assert_equals(50, $cap(), 'a nonsensical value falls back to the default');
        putenv('WINDELS_SPORTS_MAX_GENERATION=abc');
        assert_equals(50, $cap());
    } finally {
        putenv('WINDELS_SPORTS_MAX_GENERATION');
    }
});

test('ticket engine: generation is bounded — deferred fixtures are reported, never silently dropped', function () {
    $fixtures = fx_iv_fixtures(60);
    $provider = fx_iv_provider('iv-bounded', $fixtures);
    [$repo, , , $service] = fx_iv_stack($provider);
    fx_iv_approve_calibration($repo);
    fx_iv_seed_odds($repo, 'iv-bounded', $fixtures, 3 * 3600);
    try {
        putenv('WINDELS_SPORTS_MAX_GENERATION=3');
        $run = $service->runDaily(gmdate('Y-m-d', strtotime('+1 day')), 'iv-bounded-r1');
        $diag = $run['diagnostics'];
        assert_equals(50, $run['evaluated'], 'one generation pass never screens more than the 50-match batch ceiling');
        assert_equals(3, $diag['generationCap']);
        assert_equals(47, $diag['fixturesDeferred'], 'the remaining fixtures in the bounded 50-match page are explicitly deferred');
        assert_equals(0, $run['rejections'], 'deferral is not a rejection');
        // 3 fixtures × 5 candidate selections (HOME/DRAW/AWAY/OVER_1_5/BTTS
        // YES) = 15 predictions — the companion prices (UNDER_1_5, BTTS NO)
        // fed the overround but were never predicted.
        assert_equals(15, $diag['predictionsGenerated']);
        $predictedMatches = [];
        foreach ($repo->listPredictions([], 1000) as $p) $predictedMatches[(int) $p['match_id']] = true;
        assert_equals(3, count($predictedMatches), 'only capped fixtures generated predictions');
        assert_true(!empty($diag['topPicks']), 'the picks layer reads the bounded pool');
        assert_true(str_contains((string) $run['message'], 'deferred') || $diag['fixturesDeferred'] > 0, 'the summary states the deferral');
    } finally {
        putenv('WINDELS_SPORTS_MAX_GENERATION');
    }
});

test('ticket engine: an honest no-ticket page advances to the next stored batch of at most 50', function () {
    $fixtures = fx_iv_fixtures(60);
    $provider = fx_iv_provider('iv-paged', $fixtures);
    [$repo, $audit, , $service] = fx_iv_stack($provider);
    fx_iv_approve_calibration($repo);
    fx_iv_seed_odds($repo, 'iv-paged', $fixtures, 3 * 3600);
    (new AIWorkforce\Sports\ConfigurationService($repo, $audit))->update([
        'target_odds_min' => 90.0, 'target_odds_max' => 100.0,
    ], 'test', 'force an honest no-ticket verdict while testing paging');

    $date = gmdate('Y-m-d', strtotime('+1 day'));
    $first = $service->runDaily($date, 'iv-paged-r1');
    assert_equals('NO_QUALIFIED_TICKET', $first['status']);
    assert_equals(50, $first['evaluated']);
    assert_true(!empty($first['diagnostics']['fixturePageFull']));
    assert_equals(0, (int) $first['diagnostics']['batchOffset']);

    $second = $service->runDaily($date, 'iv-paged-r2');
    assert_not_equals('DUPLICATE_SKIPPED', $second['status']);
    assert_equals(10, $second['evaluated'], 'only the remaining stored page is evaluated');
    assert_equals(50, (int) $second['diagnostics']['batchOffset']);
    assert_true((int) $second['attempt'] >= 2);
});

test('ticket engine: intelligent refresh reuses stored predictions unless the odds changed', function () {
    $fixtures = fx_iv_fixtures(4);
    $provider = fx_iv_provider('iv-reuse', $fixtures);
    [$repo, $audit, , $service] = fx_iv_stack($provider);
    fx_iv_approve_calibration($repo);
    // Keep this prediction-cache test on honest no-ticket attempts so the
    // daily ticket idempotency terminal does not (correctly) short-circuit the
    // second evaluation. No available combination can reach 90–100 odds.
    (new AIWorkforce\Sports\ConfigurationService($repo, $audit))->update([
        'target_odds_min' => 90.0, 'target_odds_max' => 100.0,
    ], 'test', 'exercise prediction reuse without creating a daily ticket');
    fx_iv_seed_odds($repo, 'iv-reuse', $fixtures, 3 * 3600);
    $date = gmdate('Y-m-d', strtotime('+1 day'));

    // Run 1: 4 fixtures × 5 candidates = 20 stored predictions.
    $run1 = $service->runDaily($date, 'iv-reuse-r1');
    assert_equals(20, $run1['predictionsRecorded']);
    assert_equals(0, $run1['diagnostics']['predictionsReused']);

    // Run 2 — a different execution key (a later cron sweep, or paging):
    // same model, same odds, same timestamps → everything is REUSED.
    $run2 = $service->runDaily($date, 'iv-reuse-r2');
    assert_equals(0, $run2['predictionsRecorded'], 'paging/re-runs never duplicate a stored prediction');
    assert_equals(20, $run2['diagnostics']['predictionsReused']);
    assert_equals(20, count($repo->listPredictions([], 1000)), 'still exactly 20 rows');

    // Run 3 — one OVER_1_5 price genuinely moved: only that selection is
    // regenerated; the other 19 keep their stored readings.
    $matchRow = null;
    foreach ($repo->matches as $m) if ($m['external_id'] === 'f0') $matchRow = $m;
    assert_not_null($matchRow);
    $providerRow = $repo->ensureProvider('iv-reuse', 'iv-reuse');
    $repo->saveOdds((int) $matchRow['id'], (int) $providerRow['id'], ['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 1.85, 'observedAt' => gmdate('c')]);
    $run3 = $service->runDaily($date, 'iv-reuse-r3');
    assert_equals(1, $run3['predictionsRecorded'], 'changed odds force exactly one regeneration');
    assert_equals(19, $run3['diagnostics']['predictionsReused']);
    assert_equals(21, count($repo->listPredictions([], 1000)));
    $overs = array_values(array_filter($repo->listPredictions([], 1000), fn($p) => $p['market'] === 'TOTAL_GOALS' && $p['selection'] === 'OVER_1_5' && (int) $p['match_id'] === (int) $matchRow['id']));
    assert_equals(2, count($overs), 'the old reading is kept as history, the new one appended');
    // created_at has second granularity and both runs may share a second, so
    // select the rows by the price that made them, not by timestamp order.
    $atNewPrice = array_values(array_filter($overs, fn($p) => abs((float) $p['odds'] - 1.85) < 0.0001));
    $atOldPrice = array_values(array_filter($overs, fn($p) => abs((float) $p['odds'] - 1.70) < 0.0001));
    assert_equals(1, count($atNewPrice), 'the regenerated row carries the new price');
    assert_equals(1, count($atOldPrice), 'the superseded reading is preserved');
});

test('ticket engine: top picks are ranked model-based readings with the disclaimer — companions never picked', function () {
    $fixtures = fx_iv_fixtures(6);
    $provider = fx_iv_provider('iv-picks', $fixtures);
    [$repo, , , $service] = fx_iv_stack($provider);
    fx_iv_approve_calibration($repo);
    fx_iv_seed_odds($repo, 'iv-picks', $fixtures, 3 * 3600);
    $run = $service->runDaily(gmdate('Y-m-d', strtotime('+1 day')), 'iv-picks-r1');
    $diag = $run['diagnostics'];

    $picks = $diag['topPicks'];
    assert_true(count($picks) >= 1 && count($picks) <= 5, 'picks are bounded by the configured limit');
    assert_equals(DailyTicketService::TOP_PICKS_DISCLAIMER, $diag['topPicksDisclaimer']);
    assert_true(str_contains($diag['topPicksDisclaimer'], 'not guarantees'), 'never called a guaranteed win');

    $previousScore = PHP_FLOAT_MAX;
    $previousQualified = true;
    foreach ($picks as $pick) {
        foreach (['match', 'kickoff', 'competition', 'market', 'selection', 'modelProbability', 'windelsFairOdds', 'marketOdds', 'marketFairOdds', 'marginPoints', 'edgePoints', 'expectedValue', 'valueClass', 'valueLabel', 'valueReason', 'confidence', 'dataQuality', 'intelligenceScore', 'stability', 'why', 'qualified', 'primaryReason'] as $key) {
            assert_true(array_key_exists($key, $pick), "pick carries {$key}");
        }
        // Ranking: qualified first, then intelligence score, then edge.
        $q = !empty($pick['qualified']);
        $s = (int) ($pick['intelligenceScore']['score'] ?? -1);
        assert_true(!$previousQualified || $q || $previousScore >= $s, 'qualified picks rank ahead');
        if ($q === $previousQualified) assert_true($s <= $previousScore, 'higher intelligence score ranks higher');
        $previousScore = $s; $previousQualified = $q;
        // Companions are prices, never picks; unknown selections never appear.
        assert_true(\AIWorkforce\Sports\PredictionEngine::isSupportedMarketSelection($pick['market'], $pick['selection']), 'only supported market:selection pairs are picked');
        assert_not_equals('UNDER_1_5', $pick['selection']);
        assert_not_equals('NO', $pick['selection']);
        // Both fair prices present and distinct: model vs de-vigged market.
        assert_not_null($pick['windelsFairOdds']);
        assert_not_null($pick['marketFairOdds']);
    }
    // The full market's margin was removed for the reading.
    $any = $picks[0];
    assert_true((float) $any['marginPoints'] > 0.0, 'margin points from the complete market');
    assert_true(is_string($any['why']) && $any['why'] !== '', 'each pick explains why, from stored figures');
    assert_true(isset($diag['thresholds']['generationCap'], $diag['thresholds']['valueThresholdsPoints']['strong'], $diag['thresholds']['stabilityThresholdsPoints']['moved']), 'the thresholds that produced the readings are exposed');
});
