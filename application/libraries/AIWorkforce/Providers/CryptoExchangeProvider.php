<?php
namespace AIWorkforce\Providers;

use AIWorkforce\CircuitBreaker;
use AIWorkforce\Http;

/**
 * Shared base for public REST crypto-exchange market-data providers.
 *
 * Concrete subclasses only need to declare their name/default base URL/symbol
 * list, and implement:
 *   - normalizeSymbol(string $symbol): string      — translate USDT pair to exchange-specific ticker
 *   - normalizeTf(string $tf): string               — translate unified timeframe to exchange interval
 *   - klinesPath(string $sym, string $int, int $limit): string
 *   - tickerPath(string $sym): string
 *   - parseKlinesResponse(array $json): array
 *   - parseTickerResponse(array $json, string $requested): array
 *
 * Health check is provided (pings klinesPath with limit=1); subclasses may
 * override with a cheaper /ping or /time endpoint by overriding healthPath()
 * and parseHealthResponse().
 *
 * This intentionally implements MarketDataProvider so that all crypto
 * exchanges plug straight into ProviderManager alongside BinanceProvider.
 * It does NOT implement any authenticated / trading endpoint — execution
 * connectors live under AIWorkforce\Brokers and follow their own safety gates.
 */
abstract class CryptoExchangeProvider implements MarketDataProvider
{
    protected CircuitBreaker $breaker;
    protected Http $http;
    protected string $baseUrl;
    protected ?string $lastError = null;

    /**
     * Subclasses MUST set these, or override the corresponding accessors.
     * Example:
     *   protected string $defaultBase = 'https://api.bybit.com';
     *   public function name(): string { return 'bybit'; }
     */

    public function __construct(?string $baseUrl = null, ?Http $http = null)
    {
        $env = $this->envBaseVar();
        $this->baseUrl = rtrim(
            $baseUrl
                ?? (is_string($env) && $env !== '' ? $env : $this->defaultBaseUrl()),
            '/'
        );
        $this->http = $http ?? new Http();
        $this->breaker = new CircuitBreaker($this->name());
    }

    /* ---- Subclass contract ---- */

    abstract public function name(): string;
    /** @return list<string> Unified USDT symbols this provider serves (e.g. BTCUSDT). */
    abstract protected function symbols(): array;
    abstract protected function defaultBaseUrl(): string;
    /** Name of the env var that can override the base URL. */
    protected function envBaseVar(): ?string { return null; }
    /** @return list<string> Unified timeframes supported (e.g. ['1m','5m','1h','1d']). */
    abstract protected function supportedTimeframes(): array;

    /** Convert unified symbol (BTCUSDT) to the exchange's ticker code. */
    abstract protected function normalizeSymbol(string $symbol): string;
    /** Convert unified timeframe to the exchange's interval parameter. */
    abstract protected function normalizeTf(string $tf): string;
    /** URL path (with query) for klines given exchange-normalized symbol + interval. */
    abstract protected function klinesPath(string $exchSymbol, string $interval, int $limit): string;
    /** URL path (with query) for ticker/bookTicker. */
    abstract protected function tickerPath(string $exchSymbol): string;
    /**
     * Decode the JSON body returned by klinesPath into a normalized OHLCV list.
     * @return array<int,array{timestamp:int,open:float,high:float,low:float,close:float,volume:float}>
     */
    abstract protected function parseKlines(array $json): array;
    /**
     * Decode ticker JSON into the standard quote shape.
     * @param string $requestedSymbol The EXCHANGE-NORMALIZED symbol, for error messages.
     * @return array{symbol:string,bid:float,ask:float,last:float,timestamp:int}
     */
    abstract protected function parseTicker(array $json, string $requestedSymbol): array;

    /** URL path for a cheap health probe. Override to use a /ping endpoint. */
    protected function healthPath(): string { return $this->klinesPath($this->normalizeSymbol('BTCUSDT'), $this->normalizeTf('1m'), 1); }

    /**
     * Extra host fallbacks (e.g. redundant cluster domains) after the primary.
     * @return list<string>
     */
    protected function fallbackHosts(): array { return []; }

    /* ---- MarketDataProvider defaults ---- */

    public function synthetic(): bool { return false; }
    public function priority(): int { return 11; } // Binance is 10; others follow but can override

    public function supportsSymbol(string $symbol): bool
    {
        return in_array(strtoupper(trim($symbol)), $this->symbols(), true);
    }

    public function supportsTimeframe(string $symbol, string $tf): bool
    {
        return in_array($tf, $this->supportedTimeframes(), true);
    }

    public function capabilities(): array
    {
        return [
            'marketClasses' => ['crypto'],
            'timeframes' => $this->supportedTimeframes(),
            'delayed' => false,
            'notes' => 'Real spot crypto klines/quotes via public REST. Authenticated/trading endpoints are NOT used by this provider.',
        ];
    }

    public function getCandles(array $req): array
    {
        $symbol = strtoupper(trim((string) ($req['symbol'] ?? '')));
        $tf = (string) ($req['timeframe'] ?? '1h');
        $limit = min(1000, max(1, (int) ($req['limit'] ?? 500)));
        if (!$this->supportsSymbol($symbol)) {
            throw new \RuntimeException($this->name() . " does not list {$symbol}");
        }
        $exchSym = $this->normalizeSymbol($symbol);
        $interval = $this->normalizeTf($tf);
        $path = $this->klinesPath($exchSym, $interval, $limit);
        $json = $this->fetchJson($path);
        $candles = $this->parseKlines($json);
        if ($candles === []) throw new \RuntimeException($this->name() . ' returned no klines for ' . $symbol);
        return $candles;
    }

    public function getQuote(string $symbol): array
    {
        $symbol = strtoupper(trim($symbol));
        if (!$this->supportsSymbol($symbol)) {
            throw new \RuntimeException($this->name() . " does not list {$symbol}");
        }
        $exchSym = $this->normalizeSymbol($symbol);
        $json = $this->fetchJson($this->tickerPath($exchSym));
        $quote = $this->parseTicker($json, $exchSym);
        $quote['symbol'] = $symbol;
        return $quote;
    }

    public function healthCheck(): array
    {
        $started = microtime(true);
        try {
            $this->fetchJson($this->healthPath());
            $this->lastError = null;
            return [
                'name' => $this->name(), 'status' => 'UP', 'synthetic' => false,
                'latencyMs' => (int) ((microtime(true) - $started) * 1000), 'checkedAt' => time(),
                'circuitState' => $this->breaker->currentState(),
                'detail' => 'Public market-data REST API (no key required)',
            ];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return [
                'name' => $this->name(), 'status' => 'DOWN', 'synthetic' => false,
                'latencyMs' => (int) ((microtime(true) - $started) * 1000), 'checkedAt' => time(),
                'lastError' => $this->lastError,
                'circuitState' => $this->breaker->currentState(),
                'detail' => 'Unreachable from this host — manager falls back to next provider.',
            ];
        }
    }

    /* ---- Helpers used by subclasses ---- */

    /** @return list<string> */
    protected function hosts(): array
    {
        return array_values(array_unique(array_filter(array_merge([$this->baseUrl], $this->fallbackHosts()))));
    }

    /**
     * Fetch a JSON endpoint using the circuit breaker and host fallback list.
     * Subclasses call this from parseKlines/parseTicker — those methods receive
     * the decoded associative array.
     */
    protected function fetchJson(string $path): array
    {
        if (!$this->breaker->canCall()) {
            throw new \RuntimeException($this->name() . ' circuit breaker OPEN');
        }
        $last = $this->name() . ' request failed';
        foreach ($this->hosts() as $host) {
            try {
                $url = (str_starts_with($path, 'http') ? '' : $host) . $path;
                $json = $this->http->getJson($url, 1);
                if (!is_array($json)) throw new \RuntimeException($this->name() . ' returned a non-object payload');
                $this->breaker->recordSuccess();
                return $json;
            } catch (\Throwable $e) {
                $last = $e->getMessage();
            }
        }
        $this->breaker->recordFailure();
        throw new \RuntimeException($last);
    }

    /**
     * Helper for subclasses whose klines arrays are nested under a top-level key
     * (e.g. Bybit "result.list", OKX "data"). Call from parseKlines after extracting
     * the raw row list.
     * @param array<int, mixed> $rows
     * @return array<int, array{timestamp:int,open:float,high:float,low:float,close:float,volume:float}>
     */
    protected function normalizeOhlcvRows(array $rows, int $tsIdx, int $oIdx, int $hIdx, int $lIdx, int $cIdx, int $vIdx, int $tsMsDivisor = 1): array
    {
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $vals = array_values($r);
            if (count($vals) <= max($tsIdx, $oIdx, $hIdx, $lIdx, $cIdx, $vIdx)) continue;
            if (!is_numeric($vals[$tsIdx]) || !is_numeric($vals[$oIdx]) || !is_numeric($vals[$hIdx])
                || !is_numeric($vals[$lIdx]) || !is_numeric($vals[$cIdx]) || !is_numeric($vals[$vIdx])) {
                continue;
            }
            $out[] = [
                'timestamp' => (int) (((float) $vals[$tsIdx]) / $tsMsDivisor),
                'open' => (float) $vals[$oIdx],
                'high' => (float) $vals[$hIdx],
                'low' => (float) $vals[$lIdx],
                'close' => (float) $vals[$cIdx],
                'volume' => (float) $vals[$vIdx],
            ];
        }
        return $out;
    }
}
