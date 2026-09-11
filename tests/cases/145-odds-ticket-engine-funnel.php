<?php
/**
 * Odds Prediction Ticket Engine — the 2026-09-11 funnel regression.
 *
 * The reported production run was:
 *
 *   50 eligible → 1 with-form → 31 fresh-odds → 1 sufficient-data fixture
 *   → 7 predictions → 0 confidence-qualified → 4 positive-value
 *   → 4 risk-qualified → 0 final
 *
 *   INSUFFICIENT_DATA: 30 · SUPPORTED_ODDS_UNAVAILABLE: 19 · LOW_CONFIDENCE: 7
 *
 * That shape is arithmetically impossible for a healthy engine, and it had
 * three distinct causes — each covered by its own test here:
 *
 *  1. CONFIDENCE CEILING. ConfidenceEngine scored an approved calibration
 *     with no measured ECE (the identity bootstrap every fresh deployment
 *     runs on) as ZERO for 30% of the blend. A PERFECT fixture could reach
 *     only 0.5·100 + 0.3·0 + 0.2·100 = 70% — below the configured 75% floor.
 *     Hence "7 predictions → 0 confidence-qualified" with nothing actually
 *     wrong with the data.
 *
 *  2. SOFT REASONS TREATED AS HARD. DailyTicketService only put candidates
 *     whose EVERY pipeline stage passed into the ticket pool. A leg that
 *     missed the confidence floor was discarded before the optimizer ran, so
 *     the final stage saw an empty pool. Hence "4 positive-value, 4
 *     risk-qualified → 0 final".
 *
 *  3. FIXTURE-WIDE DATA GATE. One missing input rejected the whole fixture as
 *     INSUFFICIENT_DATA even when another supported market's own inputs were
 *     all present, and one market's missing odds hid the others.
 *
 * The tests below pin the fixed behaviour AND the honesty rules that must
 * survive it: nothing is fabricated, the 75% target stands, and a genuinely
 * empty day still reports no ticket.
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
use AIWorkforce\Sports\SportsDataNormalizer;
use AIWorkforce\Sports\TicketGovernance;
use AIWorkforce\Sports\TicketOptimizer;
use AIWorkforce\Sports\ValueEngine;

function fx145_audit(): AuditRepository
{
    return new class implements AuditRepository {
        public array $events = [];
        public function emit(string $t, string $s, array $d = [], string $a = 'system'): void { $this->events[] = ['type' => $t, 'actor' => $a, 'detail' => $d]; }
        public function recent(int $l = 100): array { return array_slice($this->events, -$l); }
    };
}

function fx145_pipeline(): PredictionPipeline
{
    return new PredictionPipeline(new MatchIntelligenceEngine(new OddsFreshnessEngine()), new FeatureEngineeringEngine(), new PredictionEngine(), new ValueEngine(), new RiskEngine(), new CorrelationEngine(), new ConfidenceEngine());
}

function fx145_service(SportsRepositoryStub $repo, AuditRepository $audit, SportsProviderManager $providers): DailyTicketService
{
    return new DailyTicketService(
        $repo, $audit, $providers, new ConfigurationService($repo, $audit), new DataQualityEngine(),
        fx145_pipeline(), new TicketOptimizer(new CorrelationEngine()),
        new TicketGovernance($repo, $audit, new CorrelationEngine()), new DecisionRecorder($repo, $audit)
    );
}

/**
 * A provider fixture with VERIFIED recent form. `$context` overrides let a
 * test remove optional feeds (or the form itself) without touching anything
 * else — that is how "missing optional data" is exercised honestly.
 */
function fx145_fixture(string $externalId, string $home, string $away, string $league, int $kickoffTs, array $context = []): array
{
    return [
        'externalId' => $externalId,
        'sport' => 'football',
        'competition' => $league,
        'homeTeam' => $home, 'awayTeam' => $away,
        'kickoff' => gmdate('Y-m-d\TH:i:00\+00:00', $kickoffTs),
        'status' => 'SCHEDULED',
        'sourceTimestamp' => gmdate('c'),
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

/** A complete multi-market price sheet: 1X2, Over 1.5, BTTS, Double Chance. */
function fx145_market_rows(float $scale = 1.0): array
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

function fx145_provider(string $id, array $fixtures, array $oddsByExt): SportsDataProvider
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

function fx145_approve_calibration(SportsRepositoryStub $repo, ?float $ece = 0.02, int $samples = 40): void
{
    $modelId = $repo->ensureModelVersion(['modelName' => PredictionEngine::MODEL_NAME, 'modelVersion' => PredictionEngine::MODEL_VERSION, 'featureVersion' => FeatureEngineeringEngine::VERSION]);
    $repo->saveCalibration([
        'model_version_id' => $modelId, 'method' => $ece === null ? 'identity-bootstrap' : 'platt',
        'intercept' => $ece === null ? 0.0 : 0.15, 'slope' => $ece === null ? 1.0 : 1.2,
        'samples' => $samples, 'ece' => $ece, 'status' => 'APPROVED',
        'created_by' => 'admin', 'created_at' => gmdate('c'),
    ]);
}

/** Seed fixtures + their full market sheets straight into storage. */
function fx145_seed(SportsRepositoryStub $repo, string $providerCode, array $fixtures, int $oddsAgeSeconds = 600, ?array $rows = null): void
{
    $provider = $repo->ensureProvider($providerCode, $providerCode);
    foreach ($fixtures as $i => $fixture) {
        $match = $repo->saveMatch((int) $provider['id'], SportsDataNormalizer::fixture($fixture, $providerCode));
        foreach ($rows ?? fx145_market_rows(1.0 + $i * 0.06) as $row) {
            $repo->saveOdds((int) $match['id'], (int) $provider['id'], [
                'market' => $row['market'], 'selection' => $row['selection'],
                'decimalOdds' => $row['decimalOdds'], 'observedAt' => gmdate('c', time() - $oddsAgeSeconds),
            ]);
        }
    }
}

/** Three well-evidenced fixtures in three different leagues, kicking off tomorrow. */
function fx145_date(): string
{
    return gmdate('Y-m-d', strtotime('+1 day'));
}

/** Kickoff inside the target day, comfortably beyond the 2h eligibility lead. */
function fx145_kickoff(int $offsetHours = 0): int
{
    return strtotime(fx145_date() . ' 18:00:00 UTC') + $offsetHours * 3600;
}

function fx145_three_fixtures(): array
{
    $base = fx145_kickoff();
    return [
        fx145_fixture('f145-1', 'Alpha FC', 'Beta United', 'League One', $base),
        fx145_fixture('f145-2', 'Gamma City', 'Delta Town', 'League Two', fx145_kickoff(1)),
        fx145_fixture('f145-3', 'Epsilon SC', 'Zeta Rovers', 'League Three', fx145_kickoff(2)),
    ];
}

// ─────────────────────────────────────────────────────────────────────────
// 1. CONFIDENCE — calculated from real data, and no longer capped below 75%
// ─────────────────────────────────────────────────────────────────────────

test('funnel regression: the bootstrap calibration no longer caps a perfect fixture below the 75% floor', function () {
    $engine = new ConfidenceEngine();
    $prediction = ['decision' => 'PREDICTION_READY', 'calibratedProbability' => 0.82, 'market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5'];
    $evidence = [
        'features' => ['expectedGoalsProxy' => 2.65, 'homeAttack' => 1.9, 'awayAttack' => 1.5, 'homeDefenseConceded' => 0.8, 'awayDefenseConceded' => 1.1],
        'inputs' => ['recentForm' => ['homeGoalsPerMatch' => 1.9, 'awayGoalsPerMatch' => 1.5, 'homeConcededPerMatch' => 0.8, 'awayConcededPerMatch' => 1.1, 'matchesPlayed' => 14, 'source' => 'provider']],
        'market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'impliedProbability' => 0.74,
        'marketPrices' => ['OVER_1_5' => ['odds' => 1.42], 'UNDER_1_5' => ['odds' => 2.85]],
    ];

    // The exact production shape: quality 100, approved calibration, no ECE.
    $out = $engine->assess($prediction, ['score' => 100, 'band' => 'EXCELLENT'], ['ece' => null, 'samples' => 0], $evidence);
    assert_true(is_numeric($out['confidence']), 'a fully evidenced candidate has a confidence figure');
    assert_true((float) $out['confidence'] >= 75.0,
        'the 70% arithmetic ceiling is gone — got ' . $out['confidence'] . '%');
    assert_true((float) $out['confidence'] <= ConfidenceEngine::CAP, 'the 95% cap still holds');
});

test('funnel regression: confidence is computed from every available data source, not assigned', function () {
    $engine = new ConfidenceEngine();
    $prediction = ['decision' => 'PREDICTION_READY', 'calibratedProbability' => 0.8, 'market' => 'MATCH_RESULT', 'selection' => 'HOME'];
    $features = ['expectedGoalsProxy' => 2.6, 'homeAttack' => 2.0, 'awayAttack' => 1.0, 'homeDefenseConceded' => 0.7, 'awayDefenseConceded' => 1.4];
    $full = $engine->assess($prediction, ['score' => 92, 'band' => 'EXCELLENT'], ['ece' => 0.03, 'samples' => 60], [
        'features' => $features,
        'inputs' => [
            'recentForm' => ['homeGoalsPerMatch' => 2.0, 'awayGoalsPerMatch' => 1.0, 'homeConcededPerMatch' => 0.7, 'awayConcededPerMatch' => 1.4, 'matchesPlayed' => 12, 'recentResults' => 'WWWDW', 'source' => 'provider'],
            'historical' => ['meetings' => 6],
            'standings' => ['home' => 2, 'away' => 15],
            'injuries' => [['player' => 'X', 'status' => 'OUT']],
            'lineups' => [],
        ],
        'market' => 'MATCH_RESULT', 'selection' => 'HOME', 'impliedProbability' => 0.76,
        'marketPrices' => ['HOME' => ['odds' => 1.31], 'DRAW' => ['odds' => 5.0], 'AWAY' => ['odds' => 9.0]],
    ]);

    // Every named data source in the requirement is a measured component.
    foreach (['dataQuality', 'calibration', 'modelProbability', 'teamForm', 'recentResults',
              'homeAwayPerformance', 'goalsScoredConceded', 'headToHead', 'leaguePosition',
              'injuriesNews', 'marketAgreement', 'marketConsistency'] as $component) {
        assert_true(isset($full['components'][$component]), $component . ' is measured when its data exists');
        assert_true(is_numeric($full['components'][$component]['value']), $component . ' carries a real figure');
        assert_true(($full['components'][$component]['note'] ?? '') !== '', $component . ' states the figure it used');
    }

    // The published arithmetic IS the score — nothing is asserted.
    $weighted = 0.0; $weight = 0.0;
    foreach ($full['components'] as $component) { $weighted += $component['value'] * $component['weight']; $weight += $component['weight']; }
    assert_close(min(ConfidenceEngine::CAP, $weighted / $weight), (float) $full['confidence'], 0.01,
        'confidence is exactly the renormalised weighted mean of its components');

    // The same candidate on WORSE data must score lower — the blend is real.
    $worse = $engine->assess(['decision' => 'PREDICTION_READY', 'calibratedProbability' => 0.52, 'market' => 'MATCH_RESULT', 'selection' => 'HOME'],
        ['score' => 62, 'band' => 'LIMITED'], ['ece' => 0.28, 'samples' => 60],
        ['features' => ['expectedGoalsProxy' => 1.2, 'homeAttack' => 0.6, 'awayAttack' => 0.6, 'homeDefenseConceded' => 0.6, 'awayDefenseConceded' => 0.6],
         'inputs' => ['recentForm' => ['homeGoalsPerMatch' => 0.6, 'awayGoalsPerMatch' => 0.6, 'homeConcededPerMatch' => 0.6, 'awayConcededPerMatch' => 0.6, 'matchesPlayed' => 2, 'source' => 'provider']],
         'market' => 'MATCH_RESULT', 'selection' => 'HOME', 'impliedProbability' => 0.5]);
    assert_true((float) $worse['confidence'] < (float) $full['confidence'], 'thin, contradictory evidence scores lower');
});

test('funnel regression: missing OPTIONAL data excludes a component instead of rejecting or zeroing it', function () {
    $engine = new ConfidenceEngine();
    $prediction = ['decision' => 'PREDICTION_READY', 'calibratedProbability' => 0.8, 'market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5'];
    $features = ['expectedGoalsProxy' => 2.7, 'homeAttack' => 1.9, 'awayAttack' => 1.5, 'homeDefenseConceded' => 0.8, 'awayDefenseConceded' => 1.1];
    $form = ['homeGoalsPerMatch' => 1.9, 'awayGoalsPerMatch' => 1.5, 'homeConcededPerMatch' => 0.8, 'awayConcededPerMatch' => 1.1, 'matchesPlayed' => 12, 'source' => 'provider'];

    // NO H2H, NO standings, NO injuries, NO lineups — only the core data.
    $sparse = $engine->assess($prediction, ['score' => 90, 'band' => 'EXCELLENT'], ['ece' => 0.03, 'samples' => 60], [
        'features' => $features, 'inputs' => ['recentForm' => $form],
        'market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'impliedProbability' => 0.7,
    ]);
    assert_true(is_numeric($sparse['confidence']), 'missing optional feeds never make confidence unmeasurable');
    $excluded = array_column($sparse['excluded'], 'component');
    foreach (['headToHead', 'leaguePosition', 'injuriesNews'] as $optional) {
        assert_in_array($optional, $excluded, $optional . ' is excluded, and its absence is named');
        assert_true(!isset($sparse['components'][$optional]), $optional . ' contributes no value at all');
    }
    // Excluding is not free-scoring: the same candidate WITH those feeds
    // present and strong must score at least as well, never worse.
    $rich = $engine->assess($prediction, ['score' => 90, 'band' => 'EXCELLENT'], ['ece' => 0.03, 'samples' => 60], [
        'features' => $features,
        'inputs' => ['recentForm' => $form, 'historical' => ['meetings' => 8], 'standings' => ['home' => 1, 'away' => 18], 'lineups' => [['x']]],
        'market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'impliedProbability' => 0.7,
    ]);
    assert_true((float) $rich['confidence'] >= (float) $sparse['confidence'],
        'a candidate with MORE strong evidence is never punished relative to a sparse one');
});

// ─────────────────────────────────────────────────────────────────────────
// 2. FINAL SELECTION — value/risk-qualified candidates can actually reach it
// ─────────────────────────────────────────────────────────────────────────

/** A ready ticket candidate in the pipeline's own shape. */
function fx145_candidate(int $matchId, string $league, string $market, string $selection, float $odds, float $ev, float $confidence, int $quality = 90, string $risk = 'LOW'): array
{
    return [
        'matchId' => $matchId,
        'market' => $market, 'selection' => $selection, 'odds' => $odds,
        'oddsTimestamp' => gmdate('c'), 'oddsAgeSeconds' => 300,
        'match' => ['homeTeam' => 'H' . $matchId, 'awayTeam' => 'A' . $matchId, 'competition' => $league, 'fixtureId' => 'x' . $matchId, 'kickoff' => gmdate('c', time() + 86400)],
        'prediction' => ['decision' => 'PREDICTION_READY', 'calibratedProbability' => round(min(0.95, (1 + $ev) / $odds), 6), 'rawModelProbability' => 0.5],
        'value' => ['qualified' => true, 'odds' => $odds, 'expectedValue' => $ev, 'edge' => 0.05],
        'risk' => ['approved' => true, 'classification' => $risk, 'reasons' => []],
        'confidence' => ['confidence' => $confidence],
        'quality' => ['score' => $quality],
        'correlation' => ['classification' => 'LOW', 'reasons' => []],
    ];
}

function fx145_optimizer_config(array $over = []): array
{
    return array_merge([
        'targetOddsMin' => 5.0, 'targetOddsMax' => 8.0, 'maxSelections' => 5,
        'minConfidence' => 75.0, 'minDataQuality' => 80, 'maxCorrelation' => 'LOW',
        'allowedMarkets' => ['MATCH_RESULT', 'TOTAL_GOALS', 'BTTS', 'DOUBLE_CHANCE'], 'allowedLeagues' => [],
        'allowFallback' => true,
    ], $over);
}

test('funnel regression: positive-value, risk-qualified candidates reach the final selection', function () {
    // The reported shape: several real candidates, all positive value, all
    // risk-approved. They must produce a ticket, not "0 final".
    $candidates = [
        fx145_candidate(1, 'L1', 'MATCH_RESULT', 'HOME', 2.10, 0.09, 82.0),
        fx145_candidate(2, 'L2', 'TOTAL_GOALS', 'OVER_1_5', 1.45, 0.06, 88.0),
        fx145_candidate(3, 'L3', 'BTTS', 'YES', 1.85, 0.07, 79.0),
        fx145_candidate(4, 'L4', 'DOUBLE_CHANCE', 'HOME_OR_DRAW', 1.32, 0.04, 91.0),
    ];
    $out = (new TicketOptimizer())->optimize($candidates, fx145_optimizer_config());
    assert_equals('QUALIFIED', $out['status'], 'four qualified candidates must produce a ticket: ' . ($out['reason'] ?? ''));
    assert_false((bool) $out['fallbackUsed'], 'every candidate cleared the preferred criteria — no fallback needed');
    assert_equals(TicketOptimizer::TIER_PREFERRED, $out['selectionTier']);
    assert_true($out['totalOdds'] >= 5.0 && $out['totalOdds'] <= 8.0, 'the ticket respects the configured odds range');
    assert_true($out['selectionCount'] >= 2, 'multiple strong markets are combined to reach the target');
});

test('funnel regression: the diagnostic names every candidate rejection reason', function () {
    $out = (new TicketOptimizer())->optimize([
        fx145_candidate(1, 'L1', 'MATCH_RESULT', 'HOME', 2.10, 0.09, 82.0),
        fx145_candidate(2, 'L2', 'TOTAL_GOALS', 'OVER_1_5', 1.45, 0.06, 60.0),          // low confidence
        array_replace_recursive(fx145_candidate(3, 'L3', 'BTTS', 'YES', 1.85, 0.07, 90.0), ['quality' => ['score' => 40]]), // low quality
        array_replace_recursive(fx145_candidate(4, 'L4', 'MATCH_RESULT', 'AWAY', 3.0, -0.05, 90.0), ['value' => ['qualified' => false]]), // no value
    ], fx145_optimizer_config());

    $rows = $out['candidateDecisions'];
    assert_true(count($rows) >= 4, 'every evaluated candidate is explained');
    foreach ($rows as $row) {
        // Requirement #14's exact columns.
        foreach (['fixture', 'market', 'selection', 'modelProbability', 'confidence',
                  'dataQuality', 'odds', 'expectedValue', 'risk', 'correlation', 'decision', 'reasons'] as $column) {
            assert_true(array_key_exists($column, $row), 'the trace exposes ' . $column);
        }
    }
    $byMatch = [];
    foreach ($rows as $row) $byMatch[(int) $row['matchId']] = $row;
    assert_in_array('LOW_CONFIDENCE', $byMatch[2]['reasons'], 'the low-confidence leg says so');
    assert_in_array('LOW_DATA_QUALITY', $byMatch[3]['reasons'], 'the low-quality leg says so');
    assert_in_array('NO_POSITIVE_VALUE', $byMatch[4]['reasons'], 'the negative-value leg says so');
});

// ─────────────────────────────────────────────────────────────────────────
// 3. FALLBACK — ranked, declared, and never a fabrication
// ─────────────────────────────────────────────────────────────────────────

test('funnel regression: fallback selects the strongest real candidates and declares itself', function () {
    // Nothing clears 75%, but every candidate is a real, positive-value,
    // risk-approved prediction. The day must not be lost to one threshold.
    $candidates = [
        fx145_candidate(1, 'L1', 'MATCH_RESULT', 'HOME', 2.40, 0.09, 71.0),
        fx145_candidate(2, 'L2', 'TOTAL_GOALS', 'OVER_1_5', 1.50, 0.06, 68.0),
        fx145_candidate(3, 'L3', 'BTTS', 'YES', 1.90, 0.04, 55.0),
    ];
    $out = (new TicketOptimizer())->optimize($candidates, fx145_optimizer_config());
    assert_equals('QUALIFIED', $out['status'], 'the fallback finds the strongest available combination');
    assert_true((bool) $out['fallbackUsed'], 'fallback mode is flagged');
    assert_equals(TicketOptimizer::TIER_RELAXED_CONFIDENCE, $out['selectionTier']);
    assert_contains('FALLBACK', (string) $out['fallbackReason'], 'the reason states fallback mode plainly');
    assert_contains('75', (string) $out['fallbackReason'], 'the reason names the floor that was not cleared');
    assert_true($out['totalOdds'] >= 5.0 && $out['totalOdds'] <= 8.0, 'the fallback still respects the odds range');

    // Ranked by confidence first: the 71% leg must be preferred over the 55%.
    $picked = array_map(fn(array $s): float => (float) $s['confidence']['confidence'], $out['selections']);
    assert_true(max($picked) >= 68.0, 'the strongest-confidence candidates are the ones taken');

    // The preferred tier was genuinely tried first and reported as empty.
    assert_equals(TicketOptimizer::TIER_PREFERRED, $out['attempts'][0]['tier']);
    assert_false((bool) $out['attempts'][0]['found'], 'the preferred tier ran and found nothing');
    assert_true((string) $out['attempts'][0]['reason'] !== '', 'the preferred tier says exactly why');
});

test('funnel regression: fallback never invents a leg and never relaxes a hard invariant', function () {
    $config = fx145_optimizer_config();
    // Only negative-value candidates exist: no tier may take them.
    $noValue = (new TicketOptimizer())->optimize([
        array_replace_recursive(fx145_candidate(1, 'L1', 'MATCH_RESULT', 'HOME', 6.0, -0.20, 95.0), ['value' => ['qualified' => false]]),
        array_replace_recursive(fx145_candidate(2, 'L2', 'BTTS', 'YES', 6.5, -0.10, 95.0), ['value' => ['qualified' => false]]),
    ], $config);
    assert_equals('NO_QUALIFIED_TICKET', $noValue['status'], 'negative value is never relaxed');

    // Only HIGH-risk candidates exist: no tier may take them.
    $highRisk = (new TicketOptimizer())->optimize([
        array_replace_recursive(fx145_candidate(1, 'L1', 'MATCH_RESULT', 'HOME', 6.0, 0.20, 95.0), ['risk' => ['approved' => false, 'classification' => 'HIGH']]),
    ], $config);
    assert_equals('NO_QUALIFIED_TICKET', $highRisk['status'], 'unapproved risk is never relaxed');

    // A genuinely empty pool produces no ticket at all.
    $empty = (new TicketOptimizer())->optimize([], $config);
    assert_equals('NO_QUALIFIED_TICKET', $empty['status'], 'no candidates means no ticket — never a fabricated one');
    assert_equals(0, $empty['eligiblePoolSize']);
});

test('funnel regression: correlation filtering survives the fallback', function () {
    $config = fx145_optimizer_config();
    // Two legs of the SAME match, both individually strong. Never combinable.
    $sameMatch = (new TicketOptimizer())->optimize([
        fx145_candidate(1, 'L1', 'MATCH_RESULT', 'HOME', 2.6, 0.10, 60.0),
        fx145_candidate(1, 'L1', 'BTTS', 'YES', 2.4, 0.10, 60.0),
    ], $config);
    assert_equals('NO_QUALIFIED_TICKET', $sameMatch['status'], 'two markets of one match can never form a ticket');

    // Different matches that SHARE A TEAM are equally forbidden.
    $shared = [
        fx145_candidate(1, 'L1', 'MATCH_RESULT', 'HOME', 2.6, 0.10, 60.0),
        fx145_candidate(2, 'L2', 'MATCH_RESULT', 'HOME', 2.4, 0.10, 60.0),
    ];
    $shared[1]['match']['homeTeam'] = $shared[0]['match']['homeTeam'];
    $sharedOut = (new TicketOptimizer())->optimize($shared, $config);
    assert_equals('NO_QUALIFIED_TICKET', $sharedOut['status'], 'a shared team is HIGH correlation at every tier');

    // Same competition under a LOW cap: also refused, even in fallback.
    $sameLeague = (new TicketOptimizer())->optimize([
        fx145_candidate(1, 'SameLeague', 'MATCH_RESULT', 'HOME', 2.6, 0.10, 60.0),
        fx145_candidate(2, 'SameLeague', 'BTTS', 'YES', 2.4, 0.10, 60.0),
    ], $config);
    assert_equals('NO_QUALIFIED_TICKET', $sameLeague['status'], 'the configured LOW correlation cap is not a fallback casualty');

    // …and the SAME pair is allowed once the operator configures MEDIUM.
    $medium = (new TicketOptimizer())->optimize([
        fx145_candidate(1, 'SameLeague', 'MATCH_RESULT', 'HOME', 2.6, 0.10, 60.0),
        fx145_candidate(2, 'SameLeague', 'BTTS', 'YES', 2.4, 0.10, 60.0),
    ], fx145_optimizer_config(['maxCorrelation' => 'MEDIUM']));
    assert_equals('QUALIFIED', $medium['status'], 'MEDIUM correlation admits two different matches in one league');
    assert_equals('MEDIUM', $medium['correlation']['classification']);
});

// ─────────────────────────────────────────────────────────────────────────
// 4. MULTI-MARKET EVALUATION AND DATA GATES, END TO END
// ─────────────────────────────────────────────────────────────────────────

test('funnel regression: every supported market of a fixture is evaluated, not just the first', function () {
    $repo = new SportsRepositoryStub();
    $audit = fx145_audit();
    fx145_approve_calibration($repo, null, 0);   // the identity bootstrap: no ECE
    $fixtures = fx145_three_fixtures();
    fx145_seed($repo, 'mm-test', $fixtures);
    $providers = new SportsProviderManager();
    $providers->register(fx145_provider('mm-test', $fixtures, []));

    $run = fx145_service($repo, $audit, $providers)->runDaily(fx145_date());
    $diag = $run['diagnostics'];

    assert_equals(3, (int) $diag['eligibleFixtures'], 'all three fixtures are ticket-eligible');
    assert_equals(3, (int) $diag['sufficientDataFixtures'], 'verified form makes every fixture predictable');
    assert_equals(0, (int) ($diag['topRejectionReasons']['INSUFFICIENT_DATA'] ?? 0), 'no fixture is rejected for data it does not need');
    assert_equals(0, (int) ($diag['topRejectionReasons']['SUPPORTED_ODDS_UNAVAILABLE'] ?? 0), 'the fixtures all have supported odds');

    // 4 supported markets per fixture (1X2 HOME/DRAW/AWAY, Over 1.5, BTTS
    // YES, Double Chance HOME_OR_DRAW) — the companions (UNDER_1_5) are
    // prices for the overround, never candidates.
    $markets = [];
    foreach ($repo->listPredictions([], 500) as $prediction) {
        $markets[strtoupper((string) $prediction['market'])] = true;
    }
    foreach (['MATCH_RESULT', 'TOTAL_GOALS', 'BTTS', 'DOUBLE_CHANCE'] as $market) {
        assert_true(isset($markets[$market]), $market . ' was evaluated for the day');
    }
    assert_true((int) $diag['predictionsGenerated'] >= 12, 'multiple markets per fixture are generated before filtering, got ' . $diag['predictionsGenerated']);
    assert_equals((int) $diag['predictionsGenerated'], (int) $diag['sufficientDataCandidates'], 'every generated prediction is a real candidate');
});

test('funnel regression: a fixture keeps the markets it CAN price when another market is unusable', function () {
    $repo = new SportsRepositoryStub();
    $audit = fx145_audit();
    fx145_approve_calibration($repo);
    // Only ONE supported market has a price (plus its companion). Under the
    // old gate this fixture died as SUPPORTED_ODDS_UNAVAILABLE / INSUFFICIENT
    // as soon as the other markets were absent.
    $fixtures = [fx145_fixture('f145-solo', 'Solo FC', 'Single Town', 'League One', fx145_kickoff())];
    fx145_seed($repo, 'partial-test', $fixtures, 600, [
        ['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => 1.44],
        ['market' => 'TOTAL_GOALS', 'selection' => 'UNDER_1_5', 'decimalOdds' => 2.80],
    ]);
    $providers = new SportsProviderManager();
    $providers->register(fx145_provider('partial-test', $fixtures, []));

    $run = fx145_service($repo, $audit, $providers)->runDaily(fx145_date());
    $diag = $run['diagnostics'];
    assert_equals(1, (int) $diag['sufficientDataFixtures'], 'one priceable market is enough to evaluate the fixture');
    assert_equals(0, (int) ($diag['topRejectionReasons']['SUPPORTED_ODDS_UNAVAILABLE'] ?? 0),
        'the markets that DO have odds are evaluated instead of eliminating the fixture');
    assert_true((int) $diag['predictionsGenerated'] >= 1, 'the priceable market produced a prediction');
});

test('funnel regression: a fixture with NO verified form is still an honest INSUFFICIENT_DATA rejection', function () {
    $repo = new SportsRepositoryStub();
    $audit = fx145_audit();
    fx145_approve_calibration($repo);
    // recentForm removed entirely: the model's mandatory input is genuinely
    // absent, so nothing may be predicted. Missing data must still reject —
    // the fix loosens the gate, it does not remove it.
    $fixtures = [fx145_fixture('f145-noform', 'Blank FC', 'Void United', 'League One', fx145_kickoff(), ['recentForm' => null])];
    fx145_seed($repo, 'noform-test', $fixtures);
    $providers = new SportsProviderManager();
    $providers->register(fx145_provider('noform-test', $fixtures, []));

    $run = fx145_service($repo, $audit, $providers)->runDaily(fx145_date());
    $diag = $run['diagnostics'];
    assert_equals(0, (int) $diag['sufficientDataFixtures'], 'no verified form means nothing can be priced');
    assert_equals(1, (int) ($diag['topRejectionReasons']['INSUFFICIENT_DATA'] ?? 0), 'the fixture is rejected once, honestly');
    assert_null($run['ticketId'], 'no ticket is fabricated out of absent data');
    assert_equals(0, count($repo->listPredictions([], 100)), 'no prediction is invented for a fixture with no inputs');
});

test('funnel regression: the daily run reports the complete funnel and the ticket it built', function () {
    $repo = new SportsRepositoryStub();
    $audit = fx145_audit();
    fx145_approve_calibration($repo, null, 0);   // the production identity bootstrap
    $fixtures = fx145_three_fixtures();
    fx145_seed($repo, 'e2e-test', $fixtures);
    $providers = new SportsProviderManager();
    $providers->register(fx145_provider('e2e-test', $fixtures, []));

    $run = fx145_service($repo, $audit, $providers)->runDaily(fx145_date());
    $diag = $run['diagnostics'];

    // THE HEADLINE: the funnel no longer collapses at the confidence stage.
    assert_true((int) $diag['confidenceQualifiedCandidates'] > 0,
        'real candidates now clear the 75% floor: ' . $diag['confidenceQualifiedCandidates'] . ' of ' . $diag['predictionsGenerated']);
    assert_true((int) $diag['positiveValueCandidates'] > 0, 'positive-value candidates exist');
    assert_true((int) $diag['riskQualifiedCandidates'] > 0, 'risk-qualified candidates exist');
    assert_not_equals(null, $run['ticketId'], 'the day produces a real ticket: ' . (string) $run['message']);
    assert_true((int) $diag['finalQualifiedCandidates'] > 0, 'the final stage selects legs instead of reporting zero');

    // The persisted ticket honours the configured odds range and is real.
    $ticket = $repo->findTicket((string) $run['ticketId']);
    assert_true(is_array($ticket), 'the ticket is persisted');
    assert_true((float) $ticket['total_odds'] >= 5.0 && (float) $ticket['total_odds'] <= 8.0, 'combined odds inside the configured 5.00–8.00 range');
    $legs = $repo->ticketSelections((string) $run['ticketId']);
    assert_true(count($legs) >= 1, 'the ticket has real legs');
    $seenMatches = [];
    foreach ($legs as $leg) {
        assert_true((int) $leg['match_id'] > 0, 'every leg links a real internal fixture');
        assert_true((float) $leg['odds'] > 1.0, 'every leg carries a real quoted price');
        assert_true(PredictionEngine::isSupportedMarketSelection((string) $leg['market'], (string) $leg['selection']), 'every leg is a supported market');
        assert_true(!isset($seenMatches[(int) $leg['match_id']]), 'no two legs come from the same fixture');
        $seenMatches[(int) $leg['match_id']] = true;
    }

    // The diagnostic trace is present and complete (requirement #14).
    assert_true(!empty($diag['candidateDecisions']), 'the per-candidate decision trace is reported');
    assert_true(!empty($diag['selectionAttempts']), 'the selection tiers tried are reported');
    assert_true(array_key_exists('fallbackUsed', $diag), 'the run always states whether fallback mode was used');
});

test('funnel regression: daily generation stays idempotent and does not re-consume the API', function () {
    $repo = new SportsRepositoryStub();
    $audit = fx145_audit();
    fx145_approve_calibration($repo, null, 0);
    $fixtures = fx145_three_fixtures();
    fx145_seed($repo, 'idem-test', $fixtures);

    // A provider that COUNTS its calls: a second run must not touch it again.
    $counting = new class('idem-test', $fixtures) implements SportsDataProvider {
        public int $fixtureCalls = 0;
        public int $oddsCalls = 0;
        public function __construct(private string $idValue, private array $fixtureRows) {}
        public function id(): string { return $this->idValue; }
        public function health(): array { return ['status' => 'ONLINE', 'reliability' => 0.95]; }
        public function fixtures(array $q): array { $this->fixtureCalls++; return $this->fixtureRows; }
        public function odds(string $e): array { $this->oddsCalls++; return []; }
        public function results(string $e): array { return []; }
    };
    $providers = new SportsProviderManager();
    $providers->register($counting);
    $service = fx145_service($repo, $audit, $providers);
    $date = fx145_date();

    $first = $service->runDaily($date);
    assert_not_equals(null, $first['ticketId'], 'the first run builds the ticket: ' . (string) $first['message']);
    $callsAfterFirst = $counting->fixtureCalls + $counting->oddsCalls;
    $ticketCount = count($repo->listTickets([], 100));

    $second = $service->runDaily($date);
    assert_equals((string) $first['ticketId'], (string) $second['ticketId'], 'the existing ticket is returned, never regenerated');
    assert_equals('GENERATED', (string) $second['status'], 'a persisted ticket short-circuits the run');
    assert_equals($callsAfterFirst, $counting->fixtureCalls + $counting->oddsCalls, 'no provider request is spent on a day that already has a ticket');
    assert_equals($ticketCount, count($repo->listTickets([], 100)), 'no duplicate ticket is created');
});

test('funnel regression: a genuinely empty day still reports NO QUALIFIED TICKET', function () {
    $repo = new SportsRepositoryStub();
    $audit = fx145_audit();
    fx145_approve_calibration($repo);
    $providers = new SportsProviderManager();
    $providers->register(fx145_provider('empty-test', [], []));

    $run = fx145_service($repo, $audit, $providers)->runDaily(fx145_date());
    assert_null($run['ticketId'], 'nothing is invented for an empty day');
    assert_equals(0, count($repo->listTickets([], 100)), 'no ticket row is written');
    assert_equals(0, count($repo->listPredictions([], 100)), 'no prediction is fabricated');
});
