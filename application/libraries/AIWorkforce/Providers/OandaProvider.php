<?php
namespace AIWorkforce\Providers;

use AIWorkforce\CircuitBreaker;
use AIWorkforce\Http;

/**
 * OANDA v20 REST forex market data.
 *
 * OANDA requires a valid API token even for candles/pricing (practice or live).
 * The provider reports itself DISABLED when OANDA_API_KEY is missing so
 * ProviderManager falls back to Frankfurter/ECB (free daily reference rates)
 * without ever fabricating data. Priority 9 sits above Frankfurter (18) so
 * OANDA wins when configured.
 *
 * Docs: https://developer.oanda.com/rest-live-v20/instrument-ep/
 */
class OandaProvider implements MarketDataProvider
{
    public const INSTRUMENTS = [
        'EURUSD' => 'EUR_USD', 'GBPUSD' => 'GBP_USD', 'USDJPY' => 'USD_JPY',
        'USDCHF' => 'USD_CHF', 'USDCAD' => 'USD_CAD', 'AUDUSD' => 'AUD_USD',
        'NZDUSD' => 'NZD_USD', 'EURGBP' => 'EUR_GBP', 'EURJPY' => 'EUR_JPY',
        'GBPJPY' => 'GBP_JPY', 'AUDJPY' => 'AUD_JPY', 'EURCHF' => 'EUR_CHF',
        'AUDNZD' => 'AUD_NZD', 'EURAUD' => 'EUR_AUD',
        'CADJPY' => 'CAD_JPY', 'CHFJPY' => 'CHF_JPY',
        'XAUUSD' => 'XAU_USD', 'XAGUSD' => 'XAG_USD',
    ];
    private const GRANULARITY = [
        '1m' => 'M1', '5m' => 'M5', '15m' => 'M15', '30m' => 'M30',
        '1h' => 'H1', '4h' => 'H4', '1d' => 'D', '1w' => 'W',
    ];

    private Http $http;
    private CircuitBreaker $breaker;
    private string $baseUrl;
    private ?string $token;
    private ?string $accountId;
    /** @var callable(string):?string */
    private $authTransport;

    private ?bool $hasCustomTransport = null;

    public function __construct(?string $baseUrl = null, ?Http $http = null, ?string $token = null)
    {
        $this->baseUrl = rtrim(
            $baseUrl ?? (getenv('OANDA_API_BASE') ?: 'https://api-fxpractice.oanda.com'),
            '/'
        );
        $this->accountId = trim((string)(getenv('OANDA_ACCOUNT_ID') ?: '')) ?: null;
        $this->token = $token ?? (getenv('OANDA_API_KEY') ?: getenv('OANDA_TOKEN') ?: null) ?: null;
        $this->http = $http ?? new Http();
        $this->breaker = new CircuitBreaker('oanda');
        // Detect a custom-injected transport (tests) by reflecting the file/line
        // of the closure. In production use the bearer-token default transport.
        $this->hasCustomTransport = ($http !== null);
    }

    public function name(): string { return 'oanda'; }
    public function synthetic(): bool { return false; }
    public function priority(): int { return 9; }

    public function enabled(): bool { return !empty($this->token); }

    public function supportsMarketClass(string $marketClass): bool
    {
        $c = strtolower($marketClass);
        return in_array($c, ['forex', 'commodity'], true);
    }

    public function supportsSymbol(string $symbol): bool
    {
        if (!$this->enabled()) return false;
        $s = strtoupper(trim($symbol));
        return isset(self::INSTRUMENTS[$s]) || in_array($s, self::INSTRUMENTS, true);
    }

    public function supportsTimeframe(string $symbol, string $tf): bool
    {
        return $this->supportsSymbol($symbol) && isset(self::GRANULARITY[$tf]);
    }

    public function capabilities(): array
    {
        return ['marketClasses' => ['forex', 'commodity'],
                'timeframes' => array_keys(self::GRANULARITY), 'delayed' => false,
                'notes' => $this->enabled()
                    ? 'Live OANDA v20 candles/pricing (' . $this->baseUrl . ').'
                    : 'Disabled: OANDA_API_KEY not configured. Using Frankfurter/ECB.'];
    }

    public function getCandles(array $req): array
    {
        $symbol = strtoupper(trim((string)($req['symbol'] ?? '')));
        $tf = (string)($req['timeframe'] ?? '1h');
        $limit = min(500, max(1, (int)($req['limit'] ?? 200)));
        if (!$this->supportsSymbol($symbol)) throw new \RuntimeException("OANDA does not list {$symbol}");
        $inst = $this->toInstrument($symbol);
        $gran = self::GRANULARITY[$tf];
        $url = $this->baseUrl . '/v3/instruments/' . rawurlencode($inst) . '/candles'
             . '?price=BA&granularity=' . rawurlencode($gran) . '&count=' . $limit;
        $json = $this->fetchJson($url);
        if (!is_array($json['candles'] ?? null)) throw new \RuntimeException('oanda candles: unexpected payload');
        $out = [];
        foreach ($json['candles'] as $c) {
            if (!is_array($c)) continue;
            $mid = $c['mid'] ?? $c['bid'] ?? null;
            if (!is_array($mid) || !is_numeric($mid['o'] ?? null)) continue;
            $out[] = [
                'timestamp' => $this->parseTime((string)($c['time'] ?? '')),
                'open' => (float)$mid['o'], 'high' => (float)$mid['h'],
                'low' => (float)$mid['l'], 'close' => (float)$mid['c'],
                'volume' => (float)($c['volume'] ?? 0),
            ];
        }
        if ($out === []) throw new \RuntimeException('oanda returned no candles');
        return $out;
    }

    public function getQuote(string $symbol): array
    {
        $symbol = strtoupper(trim($symbol));
        if (!$this->supportsSymbol($symbol)) throw new \RuntimeException("OANDA does not list {$symbol}");
        $inst = $this->toInstrument($symbol);
        $url = $this->baseUrl . '/v3/instruments/' . rawurlencode($inst) . '/pricing?instruments=' . rawurlencode($inst);
        $json = $this->fetchJson($url);
        $prices = $json['prices'] ?? [];
        if (!is_array($prices) || !$prices) throw new \RuntimeException('oanda pricing: empty response');
        $p = $prices[0];
        $bids = $p['bids'] ?? []; $asks = $p['asks'] ?? [];
        $bid = is_array($bids) && isset($bids[0]['price']) ? (float)$bids[0]['price'] : 0.0;
        $ask = is_array($asks) && isset($asks[0]['price']) ? (float)$asks[0]['price'] : 0.0;
        if ($bid <= 0 || $ask <= 0 || $ask < $bid) throw new \RuntimeException('oanda invalid prices for ' . $symbol);
        return ['symbol' => $symbol, 'bid' => $bid, 'ask' => $ask,
                'last' => ($bid + $ask) / 2, 'timestamp' => (int)(microtime(true) * 1000)];
    }

    public function healthCheck(): array
    {
        $started = microtime(true);
        if (!$this->enabled()) {
            return ['name' => $this->name(), 'status' => 'DISABLED', 'synthetic' => false,
                    'checkedAt' => time(), 'circuitState' => $this->breaker->currentState(),
                    'detail' => 'OANDA_API_KEY not configured.'];
        }
        try {
            $this->fetchJson($this->baseUrl . '/v3/accounts');
            return ['name' => $this->name(), 'status' => 'UP', 'synthetic' => false,
                    'latencyMs' => (int)((microtime(true) - $started) * 1000), 'checkedAt' => time(),
                    'circuitState' => $this->breaker->currentState(),
                    'detail' => 'Authenticated OANDA v20 API (' . $this->baseUrl . ')'];
        } catch (\Throwable $e) {
            return ['name' => $this->name(), 'status' => 'DOWN', 'synthetic' => false,
                    'latencyMs' => (int)((microtime(true) - $started) * 1000), 'checkedAt' => time(),
                    'lastError' => $e->getMessage(), 'circuitState' => $this->breaker->currentState(),
                    'detail' => 'Auth/network error — manager falls back to Frankfurter.'];
        }
    }

    private function toInstrument(string $symbol): string
    {
        $s = strtoupper(trim($symbol));
        return self::INSTRUMENTS[$s] ?? $s;
    }

    private function parseTime(string $t): int
    {
        $trim = preg_replace('/(\.\d+)Z$/', 'Z', $t) ?? $t;
        $dt = \DateTime::createFromFormat(\DateTime::RFC3339, $trim) ?: new \DateTime($t);
        return (int)((float)$dt->format('U.u') * 1000);
    }

    private function fetchJson(string $url): array
    {
        if (!$this->enabled()) throw new \RuntimeException('oanda not configured (set OANDA_API_KEY)');
        if (!$this->breaker->canCall()) throw new \RuntimeException('oanda circuit breaker OPEN');
        // When an Http transport was injected (tests), use it directly.
        // Otherwise build an Authorization-bearing default transport.
        $saved = $this->http->transport;
        $token = $this->token; $acct = $this->accountId;
        $authTransport = function (string $u) use ($token, $acct): ?string {
            $ctx = stream_context_create([
                'http' => ['method' => 'GET', 'timeout' => 6,
                           'header' => "User-Agent: AI_WORKFORCE/0.4\r\nAccept: application/json\r\nAuthorization: Bearer {$token}\r\n"
                               . ($acct ? "OANDA-Account: {$acct}\r\n" : ''),
                           'ignore_errors' => true],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $body = @file_get_contents($u, false, $ctx);
            return $body === false ? null : $body;
        };
        if (!$this->hasCustomTransport) {
            $this->http->transport = $authTransport;
        }
        try {
            $json = $this->http->getJson($url, 1);
        } catch (\Throwable $e) {
            $this->http->transport = $saved;
            $this->breaker->recordFailure();
            throw $e;
        }
        $this->http->transport = $saved;
        if (isset($json['errorMessage'])) {
            $this->breaker->recordFailure();
            throw new \RuntimeException('oanda: ' . (string)$json['errorMessage']);
        }
        $this->breaker->recordSuccess();
        return $json;
    }
}
