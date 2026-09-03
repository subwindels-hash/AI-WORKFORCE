<?php
namespace AIWorkforce\Providers;

use AIWorkforce\CircuitBreaker;
use AIWorkforce\Http;

/**
 * Interactive Brokers Client Portal Web API market-data provider.
 *
 * IBKR does not expose a public internet REST endpoint — market data flows
 * through the Client Portal Gateway you run alongside TWS/IB Gateway (defaults
 * to https://localhost:5000). The provider is opt-in via IBKR_ENABLED=1 and
 * mirrors the safety pattern used by the MT5 bridge:
 *
 *   - Defaults to DISABLED and reports capabilities honestly.
 *   - Health probes /v1/api/tickle and flags DEGRADED when the gateway is
 *     reachable but the session is not SSO-authenticated.
 *   - NEVER routes orders. Order execution stays in the separate
 *     InteractiveBrokersConnector in the Brokers/ layer.
 *
 * Endpoints used:
 *   GET /v1/api/tickle                                   → session health
 *   GET /v1/api/iserver/marketdata/history?conid=...
 *   GET /v1/api/iserver/marketdata/snapshot?conids=...&fields=31,84,85
 */
class IbkrProvider implements MarketDataProvider
{
    /** Small conid map for major assets (Smart-Routed US contracts). Operators
     * can extend through the LicensedAssetMarketDataProvider adapter. */
    public const SYMBOLS = [
        'AAPL' => 265598, 'MSFT' => 272093, 'GOOGL' => 208813719, 'AMZN' => 3691937,
        'NVDA' => 4815743, 'META' => 107113386, 'TSLA' => 76792991, 'JPM' => 240409266,
        'SPY'  => 756733, 'QQQ'  => 320227571,
        'ES=F' => 11005434, 'NQ=F' => 11005424, 'YM=F' => 11005429,
        'EURUSD' => 12087792, 'GBPUSD' => 12087797, 'USDJPY' => 15016059,
    ];

    private const BAR_MAP = [
        '1m' => '1min', '5m' => '5mins', '15m' => '15mins',
        '30m' => '30mins', '1h' => '1h', '4h' => '4h', '1d' => '1d',
    ];
    private const PERIOD_MAP = [
        '1m' => '1d', '5m' => '2d', '15m' => '5d', '30m' => '10d',
        '1h' => '10d', '4h' => '30d', '1d' => '1y',
    ];

    private Http $http;
    private CircuitBreaker $breaker;
    private string $baseUrl;
    private bool $enabled;
    private bool $customTransport;

    public function __construct(?string $baseUrl = null, ?Http $http = null, ?bool $enabled = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? (getenv('IBKR_API_BASE') ?: 'https://localhost:5000'), '/');
        $this->http = $http ?? new Http();
        $this->breaker = new CircuitBreaker('ibkr');
        $flag = getenv('IBKR_ENABLED');
        $this->enabled = $enabled !== null ? $enabled : (strtolower((string)$flag) === '1');
        $this->customTransport = ($http !== null);
    }

    public function name(): string { return 'ibkr'; }
    public function synthetic(): bool { return false; }
    public function priority(): int { return 20; }

    public function supportsMarketClass(string $marketClass): bool
    {
        return in_array(strtolower($marketClass), ['stock', 'etf', 'futures', 'forex', 'options'], true);
    }

    public function supportsSymbol(string $symbol): bool
    {
        return $this->enabled && $this->conIdFor(strtoupper(trim($symbol))) !== null;
    }

    public function supportsTimeframe(string $symbol, string $tf): bool
    {
        return $this->supportsSymbol($symbol) && isset(self::BAR_MAP[$tf]);
    }

    public function capabilities(): array
    {
        return ['marketClasses' => ['stock', 'etf', 'futures', 'forex', 'options'],
                'timeframes' => array_keys(self::BAR_MAP), 'delayed' => true,
                'notes' => $this->enabled
                    ? 'IBKR Client Portal Gateway (' . $this->baseUrl . '). Must be running AND SSO-authenticated.'
                    : 'Disabled: set IBKR_API_BASE + IBKR_ENABLED=1.'];
    }

    public function getCandles(array $req): array
    {
        $symbol = strtoupper(trim((string)($req['symbol'] ?? '')));
        $tf = (string)($req['timeframe'] ?? '1h');
        $limit = min(1000, max(1, (int)($req['limit'] ?? 200)));
        $conid = $this->conIdFor($symbol);
        if ($conid === null) throw new \RuntimeException("IBKR conid for {$symbol} not mapped");
        $bar = self::BAR_MAP[$tf] ?? '1h';
        $period = self::PERIOD_MAP[$tf] ?? '10d';
        $url = $this->baseUrl . '/v1/api/iserver/marketdata/history?conid=' . $conid
             . '&period=' . $period . '&bar=' . $bar . '&outsideRth=false';
        $json = $this->fetchJson($url);
        if (!is_array($json['data'] ?? null)) {
            $msg = is_string($json['error'] ?? null) ? $json['error'] : 'unexpected payload';
            throw new \RuntimeException('ibkr history: ' . $msg);
        }
        $out = [];
        foreach ($json['data'] as $pt) {
            if (!is_array($pt) || count($pt) < 6) continue;
            if (!is_numeric($pt[0]) || !is_numeric($pt[1])) continue;
            $out[] = ['timestamp' => (int)$pt[0], 'open' => (float)$pt[1],
                      'close' => (float)$pt[2], 'high' => (float)$pt[3],
                      'low' => (float)$pt[4], 'volume' => (float)($pt[5] ?? 0)];
        }
        return array_slice($out, -$limit);
    }

    public function getQuote(string $symbol): array
    {
        $symbol = strtoupper(trim($symbol));
        $conid = $this->conIdFor($symbol);
        if ($conid === null) throw new \RuntimeException("IBKR conid for {$symbol} not mapped");
        $url = $this->baseUrl . '/v1/api/iserver/marketdata/snapshot?conids=' . $conid . '&fields=31,84,85';
        $json = $this->fetchJson($url);
        $row = null;
        foreach (($json ?: []) as $r) {
            if (is_array($r) && (int)($r['conid'] ?? 0) === $conid) { $row = $r; break; }
        }
        if (!$row) {
            $this->fetchJson($this->baseUrl . '/v1/api/iserver/marketdata/snapshot?conids=' . $conid . '&fields=31,84,85');
            $json2 = $this->fetchJson($url);
            foreach (($json2 ?: []) as $r) {
                if (is_array($r) && (int)($r['conid'] ?? 0) === $conid) { $row = $r; break; }
            }
        }
        if (!$row) throw new \RuntimeException('ibkr snapshot: no quote returned for ' . $symbol);
        $bid = isset($row['84']) && is_numeric($row['84']) ? (float)$row['84'] : 0.0;
        $ask = isset($row['85']) && is_numeric($row['85']) ? (float)$row['85'] : 0.0;
        $last = isset($row['31']) && is_numeric($row['31']) ? (float)$row['31'] : (($bid + $ask) / 2);
        if ($bid <= 0 || $ask <= 0 || $ask < $bid) throw new \RuntimeException('ibkr snapshot: invalid prices for ' . $symbol);
        return ['symbol' => $symbol, 'bid' => $bid, 'ask' => $ask, 'last' => $last,
                'timestamp' => (int)(microtime(true) * 1000)];
    }

    public function healthCheck(): array
    {
        $started = microtime(true);
        if (!$this->enabled) {
            return ['name' => $this->name(), 'status' => 'DISABLED', 'synthetic' => false,
                    'checkedAt' => time(), 'circuitState' => $this->breaker->currentState(),
                    'detail' => 'IBKR_ENABLED=0 or gateway not configured.'];
        }
        try {
            $json = $this->fetchJson($this->baseUrl . '/v1/api/tickle', true);
            $authenticated = !empty($json['session']);
            return ['name' => $this->name(),
                    'status' => $authenticated ? 'UP' : 'DEGRADED', 'synthetic' => false,
                    'latencyMs' => (int)((microtime(true) - $started) * 1000), 'checkedAt' => time(),
                    'circuitState' => $this->breaker->currentState(),
                    'detail' => $authenticated ? 'IBKR gateway reachable; session authenticated.'
                                               : 'Gateway reachable but session unauthenticated (run SSO auth).'];
        } catch (\Throwable $e) {
            return ['name' => $this->name(), 'status' => 'DOWN', 'synthetic' => false,
                    'latencyMs' => (int)((microtime(true) - $started) * 1000), 'checkedAt' => time(),
                    'lastError' => $e->getMessage(), 'circuitState' => $this->breaker->currentState(),
                    'detail' => 'Client Portal unreachable at ' . $this->baseUrl . '.'];
        }
    }

    private function conIdFor(string $symbol): ?int { return self::SYMBOLS[$symbol] ?? null; }

    private function fetchJson(string $url, bool $isHealth = false): array
    {
        if (!$this->enabled) throw new \RuntimeException('ibkr not enabled (set IBKR_ENABLED=1)');
        if (!$this->breaker->canCall()) throw new \RuntimeException('ibkr circuit breaker OPEN');
        $saved = $this->http->transport;
        if (!$this->customTransport) {
            $allowSelfSigned = (bool)preg_match('#^https?://(localhost|127\.0\.0\.1)(:|/)#', $url);
            $sslOpts = $allowSelfSigned
                ? ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]
                : ['verify_peer' => true, 'verify_peer_name' => true];
            $this->http->transport = function (string $u) use ($sslOpts): ?string {
                $ctx = stream_context_create([
                    'http' => ['method' => 'GET', 'timeout' => 6,
                               'header' => "User-Agent: AI_WORKFORCE/0.4\r\nAccept: application/json\r\n",
                               'ignore_errors' => true],
                    'ssl' => $sslOpts,
                ]);
                $body = @file_get_contents($u, false, $ctx);
                return $body === false ? null : $body;
            };
        }
        try {
            $json = $this->http->getJson($url, 1);
        } catch (\Throwable $e) {
            $this->http->transport = $saved;
            if (!$isHealth) $this->breaker->recordFailure();
            throw $e;
        }
        $this->http->transport = $saved;
        if (isset($json['error']) && is_string($json['error'])) {
            if (!$isHealth) $this->breaker->recordFailure();
            throw new \RuntimeException('ibkr error: ' . $json['error']);
        }
        if (!$isHealth) $this->breaker->recordSuccess();
        return $json;
    }
}
