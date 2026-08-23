<?php
/** Providers: manager fallback/provenance, breaker, normalizer, synthetic honesty. */
use Aegis\CandleNormalizer;
use Aegis\CircuitBreaker;
use Aegis\ProviderManager;
use Aegis\Providers\MarketDataProvider;
use Aegis\Providers\SyntheticProvider;

class FakeProvider implements MarketDataProvider
{
    public function __construct(private string $nm, private int $prio, private ?array $data, private bool $failAlways = false) {}
    public function name(): string { return $this->nm; }
    public function synthetic(): bool { return false; }
    public function priority(): int { return $this->prio; }
    public function supportsSymbol(string $s): bool { return true; }
    public function supportsTimeframe(string $s, string $t): bool { return true; }
    public function getCandles(array $req): array {
        if ($this->failAlways) throw new RuntimeException('boom');
        return $this->data ?? [];
    }
    public function getQuote(string $s): array { throw new RuntimeException('no quote'); }
    public function healthCheck(): array { return ['name' => $this->nm, 'status' => 'UP', 'synthetic' => false, 'checkedAt' => time()]; }
    public function capabilities(): array { return ['marketClasses' => ['crypto'], 'timeframes' => ['1h'], 'delayed' => false, 'notes' => 'fake']; }
}

test('provider manager falls back and records the chain', function () {
    $pm = new ProviderManager();
    $fellBack = null;
    $pm->setFallbackHandler(function ($info) use (&$fellBack) { $fellBack = $info; });
    $pm->register(new FakeProvider('broken', 1, null, true));
    $pm->register(new FakeProvider('working', 2, fx_candles(100)));
    $series = $pm->getCandleSeries('BTCUSDT', 'crypto', '1h', 100);
    assert_equals('working', $series['provenance']['source']);
    assert_equals(['broken'], $series['provenance']['fallbackChain']);
    assert_equals(['symbol' => 'BTCUSDT', 'failed' => ['broken'], 'used' => 'working'], $fellBack);
});

test('synthetic provider marks provenance and generates consistent OHLC', function () {
    $pm = new ProviderManager();
    $pm->register(new FakeProvider('real-down', 1, null, true));
    $pm->register(new SyntheticProvider());
    $series = $pm->getCandleSeries('BTCUSDT', 'crypto', '1h', 100);
    assert_equals('synthetic-demo', $series['provenance']['source']);
    assert_true($series['provenance']['synthetic']);
    assert_false($series['provenance']['live']);
    foreach ($series['candles'] as $c) {
        assert_true($c['high'] >= max($c['open'], $c['close']));
        assert_true($c['low'] <= min($c['open'], $c['close']));
    }
});

test('synthetic generator is deterministic', function () {
    $a = SyntheticProvider::generate('BTCUSDT', '1h', 100, 1700000000000);
    $b = SyntheticProvider::generate('BTCUSDT', '1h', 100, 1700000000000);
    assert_equals($a, $b);
});

test('circuit breaker opens and half-opens', function () {
    $cb = new CircuitBreaker('t', 3, 60000, 20);
    assert_true($cb->canCall());
    $cb->recordFailure(); $cb->recordFailure();
    assert_true($cb->canCall());
    $cb->recordFailure();
    assert_false($cb->canCall());
    assert_equals('OPEN', $cb->currentState());
    usleep(30000);
    assert_equals('HALF_OPEN', $cb->currentState());
    assert_true($cb->canCall());
    $cb->recordSuccess();
    assert_equals('CLOSED', $cb->currentState());
});

test('normalizer sorts, dedupes, drops NaN and counts gaps', function () {
    $raw = [
        ['timestamp' => 3000000, 'open' => 3, 'high' => 3, 'low' => 3, 'close' => 3, 'volume' => 1],
        ['timestamp' => 1000000, 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 1, 'volume' => 1],
        ['timestamp' => 1000000, 'open' => 2, 'high' => 2, 'low' => 2, 'close' => 2, 'volume' => 1],
        ['timestamp' => 2000000, 'open' => NAN, 'high' => 2, 'low' => 2, 'close' => 2, 'volume' => 1],
        ['timestamp' => 9000000, 'open' => 9, 'high' => 9, 'low' => 9, 'close' => 9, 'volume' => 1],
    ];
    $res = CandleNormalizer::normalize($raw, '1h');
    assert_equals(3, count($res['candles']));
    assert_equals(2, $res['validation']['droppedCount']);
    assert_true($res['validation']['gapCount'] >= 1);
});
