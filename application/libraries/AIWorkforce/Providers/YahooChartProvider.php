<?php
namespace AIWorkforce\Providers;

use AIWorkforce\CircuitBreaker;
use AIWorkforce\Http;

/**
 * DELAYED public equity / ETF / futures bars via the Yahoo Finance chart
 * endpoint. This is not a licensed real-time feed and is never a live-trading
 * source. 4h is refused (Yahoo has no native 4h interval) instead of being
 * synthesized. Unreachable hosts fall through to the labeled synthetic
 * provider via ProviderManager.
 */
class YahooChartProvider implements MarketDataProvider
{
    public const STOCKS = [
        'AAPL', 'MSFT', 'GOOGL', 'AMZN', 'NVDA', 'META', 'TSLA', 'JPM', 'V', 'UNH',
        'JNJ', 'XOM', 'PG', 'HD', 'COST', 'NFLX', 'AMD', 'INTC',
    ];
    public const ETFS = [
        'SPY', 'QQQ', 'IWM', 'DIA', 'VTI', 'VOO', 'GLD', 'SLV', 'TLT', 'HYG', 'EEM', 'IEF',
    ];
    public const FUTURES = [
        'ES=F', 'NQ=F', 'YM=F', 'CL=F', 'GC=F', 'SI=F', 'ZB=F', '6E=F',
    ];

    private const INTERVALS = [
        '1m' => ['1m', '7d'],
        '5m' => ['5m', '60d'],
        '15m' => ['15m', '60d'],
        '1h' => ['60m', '3mo'],
        '1d' => ['1d', '2y'],
    ];

    private string $assetClass;
    private string $baseUrl;
    private Http $http;
    private CircuitBreaker $breaker;

    public function __construct(string $assetClass, ?string $baseUrl = null, ?Http $http = null)
    {
        $assetClass = strtolower(trim($assetClass));
        if (!in_array($assetClass, ['stock', 'etf', 'futures'], true)) {
            throw new \InvalidArgumentException('yahoo chart asset class must be stock, etf or futures');
        }
        $this->assetClass = $assetClass;
        $this->baseUrl = rtrim($baseUrl ?? (getenv('AI_WORKFORCE_YAHOO_CHART_URL') ?: 'https://query1.finance.yahoo.com'), '/');
        $this->http = $http ?? new Http([$this, 'defaultTransport']);
        $this->breaker = new CircuitBreaker('yahoo-chart-' . $assetClass);
    }

    public static function classOf(string $symbol): ?string
    {
        $s = strtoupper(trim($symbol));
        if (in_array($s, self::STOCKS, true)) return 'stock';
        if (in_array($s, self::ETFS, true)) return 'etf';
        if (in_array($s, self::FUTURES, true)) return 'futures';
        return null;
    }

    public function name(): string { return 'yahoo-chart-' . $this->assetClass; }
    public function synthetic(): bool { return false; }
    public function priority(): int { return $this->assetClass === 'stock' ? 22 : ($this->assetClass === 'etf' ? 23 : 24); }

    public function supportsMarketClass(string $marketClass): bool
    {
        return strtolower($marketClass) === $this->assetClass;
    }

    public function supportsSymbol(string $symbol): bool
    {
        if (!$this->enabled()) return false;
        return self::classOf($symbol) === $this->assetClass;
    }

    public function supportsTimeframe(string $symbol, string $tf): bool
    {
        return $this->supportsSymbol($symbol) && isset(self::INTERVALS[$tf]);
    }

    public function getCandles(array $req): array
    {
        $symbol = strtoupper(trim((string) ($req['symbol'] ?? '')));
        $timeframe = (string) ($req['timeframe'] ?? '');
        $limit = max(1, min(5000, (int) ($req['limit'] ?? 200)));
        if (!$this->supportsSymbol($symbol)) throw new \RuntimeException($this->name() . ' does not list ' . $symbol);
        if (!isset(self::INTERVALS[$timeframe])) {
            throw new \RuntimeException($this->name() . ' does not offer timeframe ' . $timeframe . ' (4h is refused — Yahoo has no native 4h bar)');
        }
        [$interval, $range] = self::INTERVALS[$timeframe];
        $json = $this->fetchChart($symbol, $interval, $range);
        $candles = $this->parseCandles($json);
        return array_slice($candles, -$limit);
    }

    public function getQuote(string $symbol): array
    {
        $symbol = strtoupper(trim($symbol));
        if (!$this->supportsSymbol($symbol)) throw new \RuntimeException($this->name() . ' does not list ' . $symbol);
        $json = $this->fetchChart($symbol, '1d', '5d');
        $meta = $this->result($json)['meta'] ?? [];
        $last = $meta['regularMarketPrice'] ?? null;
        $at = $meta['regularMarketTime'] ?? null;
        if (!is_numeric($last)) {
            $candles = $this->parseCandles($json);
            $bar = end($candles);
            $last = $bar['close'];
            $at = $bar['timestamp'];
        }
        $ts = is_numeric($at) ? (int) $at : 0;
        if ($ts > 0 && $ts < 100000000000) $ts *= 1000;
        if ($ts <= 0 || (float) $last <= 0) throw new \RuntimeException($this->name() . ' returned no quote for ' . $symbol);
        return ['symbol' => $symbol, 'last' => (float) $last, 'timestamp' => $ts];
    }

    public function healthCheck(): array
    {
        $base = ['name' => $this->name(), 'synthetic' => false, 'checkedAt' => time()];
        if (!$this->enabled()) {
            return $base + ['status' => 'DISABLED', 'detail' => 'Public Yahoo chart adapter disabled (AI_WORKFORCE_YAHOO_CHART_ENABLED=0).'];
        }
        $started = microtime(true);
        $probe = $this->probeSymbol();
        try {
            $this->fetchChart($probe, '1d', '5d');
            return $base + [
                'status' => 'UP',
                'latencyMs' => (int) round((microtime(true) - $started) * 1000),
                'circuitState' => $this->breaker->currentState(),
                'detail' => 'Delayed public Yahoo Finance chart (' . $this->assetClass . '). Not licensed, not a live trading feed.',
                'marketClass' => $this->assetClass,
                'delayed' => true,
            ];
        } catch (\Throwable $e) {
            return $base + [
                'status' => 'DOWN',
                'latencyMs' => (int) round((microtime(true) - $started) * 1000),
                'lastError' => substr($e->getMessage(), 0, 240),
                'circuitState' => $this->breaker->currentState(),
                'detail' => 'Yahoo chart unreachable from this host — manager falls back and flags synthetic use.',
                'marketClass' => $this->assetClass,
            ];
        }
    }

    public function capabilities(): array
    {
        return [
            'marketClasses' => [$this->assetClass],
            'timeframes' => array_keys(self::INTERVALS),
            'delayed' => true,
            'notes' => 'DELAYED public Yahoo Finance chart. Not a licensed real-time feed and not used for live order routing. 4h is not offered.',
            'configuredSymbols' => count($this->allowList()),
            'licenseConfigured' => false,
        ];
    }

    /** @return list<string> */
    public function allowList(): array
    {
        return match ($this->assetClass) {
            'etf' => self::ETFS,
            'futures' => self::FUTURES,
            default => self::STOCKS,
        };
    }

    private function enabled(): bool
    {
        return getenv('AI_WORKFORCE_YAHOO_CHART_ENABLED') !== '0';
    }

    private function probeSymbol(): string
    {
        return $this->allowList()[0];
    }

    /** @return array<string,mixed> */
    private function fetchChart(string $symbol, string $interval, string $range): array
    {
        if (!$this->breaker->canCall()) {
            throw new \RuntimeException($this->name() . ' circuit breaker OPEN');
        }
        $path = '/v8/finance/chart/' . rawurlencode($symbol) . '?interval=' . rawurlencode($interval) . '&range=' . rawurlencode($range);
        $last = $this->name() . ' request failed';
        foreach ($this->hosts() as $host) {
            try {
                $json = $this->http->getJson($host . $path, 1);
                if (!is_array($json)) throw new \RuntimeException($this->name() . ' returned a non-object payload');
                $this->result($json);
                $this->breaker->recordSuccess();
                return $json;
            } catch (\Throwable $e) {
                $last = $e->getMessage();
            }
        }
        $this->breaker->recordFailure();
        throw new \RuntimeException($last);
    }

    /** @return list<string> */
    private function hosts(): array
    {
        $primary = rtrim($this->baseUrl, '/');
        $out = [$primary];
        if (str_contains($primary, 'finance.yahoo.com')) {
            $out[] = 'https://query1.finance.yahoo.com';
            $out[] = 'https://query2.finance.yahoo.com';
        }
        return array_values(array_unique(array_filter($out)));
    }

    /** @param array<string,mixed> $json @return array<string,mixed> */
    private function result(array $json): array
    {
        $error = $json['chart']['error'] ?? null;
        if (is_array($error) && trim((string) ($error['code'] ?? $error['description'] ?? '')) !== '') {
            throw new \RuntimeException($this->name() . ' error: ' . (string) ($error['description'] ?? $error['code']));
        }
        $result = $json['chart']['result'][0] ?? null;
        if (!is_array($result)) throw new \RuntimeException($this->name() . ' returned no chart result');
        return $result;
    }

    /**
     * @param array<string,mixed> $json
     * @return list<array{timestamp:int,open:float,high:float,low:float,close:float,volume:float}>
     */
    private function parseCandles(array $json): array
    {
        $result = $this->result($json);
        $timestamps = $result['timestamp'] ?? [];
        $quote = $result['indicators']['quote'][0] ?? null;
        if (!is_array($timestamps) || !is_array($quote)) {
            throw new \RuntimeException($this->name() . ' chart payload has no OHLCV arrays');
        }
        $opens = $quote['open'] ?? [];
        $highs = $quote['high'] ?? [];
        $lows = $quote['low'] ?? [];
        $closes = $quote['close'] ?? [];
        $volumes = $quote['volume'] ?? [];
        $out = [];
        $n = count($timestamps);
        for ($i = 0; $i < $n; $i++) {
            if (!is_numeric($timestamps[$i]) || !is_numeric($opens[$i] ?? null) || !is_numeric($highs[$i] ?? null)
                || !is_numeric($lows[$i] ?? null) || !is_numeric($closes[$i] ?? null)) {
                continue;
            }
            $open = (float) $opens[$i];
            $high = (float) $highs[$i];
            $low = (float) $lows[$i];
            $close = (float) $closes[$i];
            $volume = is_numeric($volumes[$i] ?? null) ? (float) $volumes[$i] : 0.0;
            if ($open <= 0 || $high <= 0 || $low <= 0 || $close <= 0
                || $high < max($open, $close) || $low > min($open, $close) || $volume < 0) {
                continue;
            }
            $ts = (int) $timestamps[$i];
            if ($ts < 100000000000) $ts *= 1000;
            $out[] = [
                'timestamp' => $ts,
                'open' => $open,
                'high' => $high,
                'low' => $low,
                'close' => $close,
                'volume' => $volume,
            ];
        }
        if ($out === []) throw new \RuntimeException($this->name() . ' returned no valid candles');
        return $out;
    }

    public function defaultTransport(string $url): ?string
    {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 8.0,
                'header' => "User-Agent: AI_WORKFORCE/0.5 (equity-chart)\r\nAccept: application/json\r\n",
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        return $body === false ? null : $body;
    }
}
