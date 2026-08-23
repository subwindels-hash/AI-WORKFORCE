<?php
namespace Aegis\Providers;

use Aegis\CircuitBreaker;
use Aegis\Http;

/**
 * REAL crypto market data from Binance public REST (no key required for
 * market data). Reports DOWN and falls back when the host cannot reach
 * api.binance.com — never silently serves synthetic data.
 */
class BinanceProvider implements MarketDataProvider
{
    public const SYMBOLS = [
        'BTCUSDT', 'ETHUSDT', 'SOLUSDT', 'BNBUSDT', 'XRPUSDT', 'ADAUSDT',
        'DOGEUSDT', 'AVAXUSDT', 'LINKUSDT', 'DOTUSDT', 'MATICUSDT', 'LTCUSDT',
    ];

    private CircuitBreaker $breaker;
    private Http $http;
    private ?string $lastError = null;

    public function __construct(?string $baseUrl = null, ?Http $http = null)
    {
        $this->baseUrl = $baseUrl ?? (getenv('BINANCE_API_BASE') ?: 'https://api.binance.com');
        $this->http = $http ?? new Http();
        $this->breaker = new CircuitBreaker('binance');
    }

    private string $baseUrl;

    public function name(): string { return 'binance'; }
    public function synthetic(): bool { return false; }
    public function priority(): int { return 10; }

    public function supportsSymbol(string $symbol): bool
    {
        return in_array(strtoupper($symbol), self::SYMBOLS, true);
    }

    public function supportsTimeframe(string $symbol, string $tf): bool
    {
        return in_array($tf, ['1m', '5m', '15m', '1h', '4h', '1d'], true);
    }

    public function getCandles(array $req): array
    {
        $symbol = strtoupper($req['symbol']);
        if (!$this->supportsSymbol($symbol)) {
            throw new \RuntimeException("Binance provider does not list {$symbol}");
        }
        $url = $this->baseUrl . '/api/v3/klines?symbol=' . urlencode($symbol)
            . '&interval=' . $req['timeframe'] . '&limit=' . min(1000, max(1, $req['limit']));
        $raw = $this->guarded(fn () => $this->http->getJson($url));
        $out = [];
        foreach ($raw as $k) {
            $out[] = [
                'timestamp' => (int)$k[0],
                'open' => (float)$k[1],
                'high' => (float)$k[2],
                'low' => (float)$k[3],
                'close' => (float)$k[4],
                'volume' => (float)$k[5],
            ];
        }
        return $out;
    }

    public function getQuote(string $symbol): array
    {
        $symbol = strtoupper($symbol);
        if (!$this->supportsSymbol($symbol)) {
            throw new \RuntimeException("Binance provider does not list {$symbol}");
        }
        $t = $this->guarded(fn () => $this->http->getJson($this->baseUrl . '/api/v3/ticker/bookTicker?symbol=' . urlencode($symbol)));
        $bid = (float)$t['bidPrice'];
        $ask = (float)$t['askPrice'];
        return ['symbol' => $symbol, 'bid' => $bid, 'ask' => $ask, 'last' => ($bid + $ask) / 2, 'timestamp' => (int)(microtime(true) * 1000)];
    }

    public function healthCheck(): array
    {
        $started = microtime(true);
        try {
            $this->guarded(fn () => $this->http->getJson($this->baseUrl . '/api/v3/ping', 1), true);
            $this->lastError = null;
            return [
                'name' => $this->name(), 'status' => 'UP', 'synthetic' => false,
                'latencyMs' => (int)((microtime(true) - $started) * 1000), 'checkedAt' => time(),
                'circuitState' => $this->breaker->currentState(),
                'detail' => 'Public market-data REST API (no key required)',
            ];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return [
                'name' => $this->name(), 'status' => 'DOWN', 'synthetic' => false,
                'latencyMs' => (int)((microtime(true) - $started) * 1000), 'checkedAt' => time(),
                'lastError' => $this->lastError,
                'circuitState' => $this->breaker->currentState(),
                'detail' => 'Unreachable from this host — manager falls back and flags synthetic use.',
            ];
        }
    }

    public function capabilities(): array
    {
        return [
            'marketClasses' => ['crypto'],
            'timeframes' => ['1m', '5m', '15m', '1h', '4h', '1d'],
            'delayed' => false,
            'notes' => 'Real spot crypto klines/quotes via public REST. Trading endpoints NOT used.',
        ];
    }

    private function guarded(callable $fn, bool $rethrow = false)
    {
        if (!$this->breaker->canCall()) {
            throw new \RuntimeException('binance circuit breaker OPEN');
        }
        try {
            $out = $fn();
            $this->breaker->recordSuccess();
            return $out;
        } catch (\Throwable $e) {
            $this->breaker->recordFailure();
            if ($rethrow) { throw $e; }
            throw $e;
        }
    }
}
