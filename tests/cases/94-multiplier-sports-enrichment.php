<?php
// Multiplier × Sports Intelligence enrichment: the sentiment pipeline that
// was silently broken (wrong fixture-id keys, wrong odds shape, per-fixture
// N+1, hard-coded 10 cap). Now: internal `externalId` + `roundId` keys,
// list-of-rows odds (market/selection/decimalOdds), one round() call per
// matchday with per-fixture fallback, and `competition` in major-event
// detection.
use AIWorkforce\Persistence\AuditRepository;
use AIWorkforce\Sports\Providers\SportsDataProvider;
use AIWorkforce\Sports\SportsIntelligence;
use AIWorkforce\MultiplierIntelligence\SportsBettingEnrichmentProvider;

function fx_mult_audit(): AuditRepository
{
    return new class implements AuditRepository { public array $events = []; public function emit(string $t, string $s, array $d = [], string $a = 'system'): void { $this->events[] = ['type' => $t, 'detail' => $d]; } public function recent(int $l = 100): array { return []; } };
}

/** Internal-shape odds rows: list of market/selection/decimalOdds. */
function fx_mult_odds_rows(string $fixtureId, float $home, float $away, string $at): array
{
    return [
        ['fixtureId' => $fixtureId, 'market' => 'MATCH_RESULT', 'selection' => 'HOME', 'decimalOdds' => $home, 'observedAt' => $at],
        ['fixtureId' => $fixtureId, 'market' => 'MATCH_RESULT', 'selection' => 'AWAY', 'decimalOdds' => $away, 'observedAt' => $at],
        ['fixtureId' => $fixtureId, 'market' => 'MATCH_RESULT', 'selection' => 'DRAW', 'decimalOdds' => 10.0, 'observedAt' => $at],
    ];
}

/**
 * SportsIntelligence with a counting fake provider.
 *
 * Fixtures mirror the internal shape emitted by SportsDataNormalizer:
 * externalId (NOT id), competition (NOT league), optional roundId.
 *
 * @param array $roundOdds      flat rows keyed by fixtureId (round() result)
 * @param array $perFixtureOdds fixtureId => rows (odds() result)
 * @param bool  $withRoundIds   omit roundId to force the per-fixture path
 * @return array{0: SportsIntelligence, 1: object}
 */
function fx_mult_sports(array $roundOdds = [], array $perFixtureOdds = [], bool $withRoundIds = true): array
{
    $repo = new SportsRepositoryStub();
    $audit = fx_mult_audit();
    $intel = new SportsIntelligence($repo, $audit);
    $now = gmdate('c');
    $fake = new class($now, $roundOdds, $perFixtureOdds, $withRoundIds) implements SportsDataProvider {
        public int $roundCalls = 0;
        public int $oddsCalls = 0;
        public function __construct(private string $now, private array $roundOdds, private array $perFixtureOdds, private bool $withRoundIds) {}
        public function id(): string { return 'multiplier-enrich-test'; }
        public function health(): array { return ['status' => 'ONLINE', 'reliability' => 0.95]; }
        public function fixtures(array $q): array
        {
            $f1 = ['externalId' => 'm1', 'homeTeam' => 'Alpha', 'awayTeam' => 'Beta', 'kickoff' => $this->now, 'status' => 'LIVE', 'competition' => 'Premier League'];
            $f2 = ['externalId' => 'm2', 'homeTeam' => 'Gamma', 'awayTeam' => 'Delta', 'kickoff' => $this->now, 'status' => 'SCHEDULED', 'competition' => 'Premier League'];
            if ($this->withRoundIds) { $f1['roundId'] = 'r1'; $f2['roundId'] = 'r1'; }
            return [$f1, $f2];
        }
        public function round(string $roundExternalId): array
        {
            $this->roundCalls++;
            return ['roundId' => $roundExternalId, 'fixtures' => [], 'odds' => $this->roundOdds, 'results' => []];
        }
        public function odds(string $fixtureExternalId): array
        {
            $this->oddsCalls++;
            return $this->perFixtureOdds[$fixtureExternalId] ?? [];
        }
        public function results(string $fixtureExternalId): array { return []; }
    };
    $intel->providers->register($fake);
    return [$intel, $fake];
}

function fx_mult_signals($intel): array
{
    return (new SportsBettingEnrichmentProvider($intel))->getEnrichmentSignals();
}

test('enrichment: one round() call covers the day and reads internal odds shape', function () {
    $at = gmdate('c');
    [$intel, $fake] = fx_mult_sports(
        array_merge(fx_mult_odds_rows('m1', 1.2, 6.5, $at), fx_mult_odds_rows('m2', 1.55, 4.5, $at)),
        [],
        true
    );
    $signals = fx_mult_signals($intel);

    assert_true($signals['data_available'] === true, 'enrichment should report data: ' . json_encode($signals));
    assert_equals('sports_intelligence', $signals['source'], 'wrong source');
    assert_equals(2, $signals['active_fixtures'], 'active fixture count');
    assert_equals(1, $fake->roundCalls, 'exactly ONE round() call for both fixtures');
    assert_equals(0, $fake->oddsCalls, 'round path must not call per-fixture odds()');
    assert_true($signals['major_event'] === true, 'major event detected from the `competition` key');
    assert_equals('bullish', $signals['market_sentiment'], 'market sentiment from favorite strength');
    assert_close(0.739, $signals['sentiment_score'], 0.001, 'avg favorite probability');
    assert_equals('high', $signals['volatility_signal'], 'odds-spread volatility');
});

test('enrichment: fixtures without roundId fall back to per-fixture odds()', function () {
    $at = gmdate('c');
    [$intel, $fake] = fx_mult_sports([], [
        'm1' => fx_mult_odds_rows('m1', 1.2, 6.5, $at),
        'm2' => fx_mult_odds_rows('m2', 1.55, 4.5, $at),
    ], false);
    $signals = fx_mult_signals($intel);

    assert_equals(0, $fake->roundCalls, 'no roundIds → no round() calls');
    assert_equals(2, $fake->oddsCalls, 'one odds() call per fixture');
    assert_equals('bullish', $signals['market_sentiment'], 'sentiment survives the fallback path');
    assert_close(0.739, $signals['sentiment_score'], 0.001, 'avg favorite probability');
});

test('enrichment: round without odds degrades to per-fixture odds', function () {
    $at = gmdate('c');
    // round() succeeds (provider supports it) but returns no odds rows — the
    // odds add-on missing scenario — so every fixture must fall back.
    [$intel, $fake] = fx_mult_sports([], [
        'm1' => fx_mult_odds_rows('m1', 1.2, 6.5, $at),
        'm2' => fx_mult_odds_rows('m2', 1.55, 4.5, $at),
    ], true);
    $signals = fx_mult_signals($intel);

    assert_equals(1, $fake->roundCalls, 'one round() attempt');
    assert_equals(2, $fake->oddsCalls, 'per-fixture fallback for both fixtures');
    assert_equals('bullish', $signals['market_sentiment'], 'degraded path keeps sentiment');
});

test('enrichment: inert without Sports Intelligence', function () {
    $provider = new SportsBettingEnrichmentProvider(null);
    $signals = $provider->getEnrichmentSignals();
    assert_true($signals['data_available'] === false, 'must be marked unavailable');
    assert_equals('neutral', $signals['market_sentiment'], 'defaults stay neutral');
    assert_equals(0.5, $signals['sentiment_score'], 'defaults stay neutral');

    $prediction = $provider->enrichPrediction(['predictedMultiplier' => 2.0, 'confidence' => 0.5]);
    assert_true($prediction['sports_enrichment']['applied'] === false, 'enrichment must not apply without data');
    assert_equals(2.0, $prediction['predictedMultiplier'], 'prediction untouched');
});

test('enrichment: capped adjustment and volatility confidence scaling', function () {
    $at = gmdate('c');
    [$intel] = fx_mult_sports(
        array_merge(fx_mult_odds_rows('m1', 1.2, 6.5, $at), fx_mult_odds_rows('m2', 1.55, 4.5, $at)),
        [],
        true
    );
    $provider = new SportsBettingEnrichmentProvider($intel);
    $prediction = $provider->enrichPrediction(['predictedMultiplier' => 2.0, 'confidence' => 0.5]);
    $enr = $prediction['sports_enrichment'];

    assert_true($enr['applied'] === true, 'enrichment applies with data');
    assert_equals(2.0, $enr['original'], 'original recorded');
    // major event (−0.15) + bullish (−0.05) = −0.2, inside the 15% cap (±0.3)
    assert_equals(1.8, $enr['enriched'], 'capped downward adjustment');
    assert_equals(0.45, $prediction['confidence'], 'high volatility scales confidence ×0.9');
});
