<?php
namespace AIWorkforce\Providers;

use AIWorkforce\CircuitBreaker;
use AIWorkforce\Http;

/**
 * Alpaca Markets real-time/delayed market data.
 *
 * Alpaca exposes crypto bars/trades/quotes without authentication at
 * https://data.alpaca.markets (e.g. GET /v1beta3/crypto/us/bars?symbols=BTC/USD),
 * and equities/bars at /v2/stocks/{symbol}/bars when APCA key+secret are
 * provided. This provider:
 *
 *   - ALWAYS registers for crypto (no key needed; live public data).
 *   - Registers for stocks/ETFs ONLY when ALPACA_API_KEY / ALPACA_API_SECRET
 *     are set (or ALPACA_EQUITIES_ENABLED=1 is forced); otherwise those asset
 *     classes report supportsSymbol() === false so the chain falls through
 *     to Yahoo / the labeled synthetic fallback.
 *
 * Priority 16 sits just below the dedicated crypto exchanges for crypto and
 * above Yahoo for keyed equities.
 */
class AlpacaProvider implements MarketDataProvider
{
    public const CRYPTO_PAIRS = [
        'BTC/USD', 'ETH/USD', 'SOL/USD', 'DOGE/USD', 'AVAX/USD',
        'LINK/USD', 'LTC/USD', 'BCH/USD', 'MATIC/USD',
    ];
    private const UNIFIED_TO_CRYPTO = [
        'BTCUSD' => 'BTC/USD', 'ETHUSD' => 'ETH/USD', 'SOLUSD' => 'SOL/USD',
        'DOGEUSD' => 'DOGE/USD', 'AVAXUSD' => 'AVAX/USD', 'LINKUSD' => 'LINK/USD',
        'LTCUSD' => 'LTC/USD', 'BCHUSD' => 'BCH/USD', 'MATICUSD' => 'MATIC/USD',
    ];
    public const EQUITIES = [
        'AAPL','MSFT','GOOGL','GOOG','AMZN','NVDA','META','TSLA','JPM','V','UNH',
        'JNJ','XOM','PG','HD','COST','NFLX','AMD','INTC','BAC','WMT','DIS',
    ];
    public const ETFS = [
        'SPY','QQQ','IWM','DIA','VTI','VOO','GLD','SLV','TLT','HYG','EEM','IEF',
        'ARKK','XLF','XLE','XLK','SOXX',
    ];

    private const TF_MAP = [
        '1m' => '1Min', '5m' => '5Min', '15m' => '15Min',
        '30m' => '30Min', '1h' => '1Hour', '4h' => '4Hour', '1d' => '1Day',
    ];

    private Http $http;
    private CircuitBreaker $breaker;
    private string $baseUrl;
    private ?string $apiKey;
    private ?string $apiSecret;
    private bool $equitiesEnabled;
    private bool $customTransport;

    public function __construct(?string $baseUrl = null, ?Http $http = null,
                                ?string $apiKey = null, ?string $apiSecret = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? (getenv('ALPACA_API_BASE') ?: 'https://data.alpaca.markets'), '/');
        $this->http = $http ?? new Http();
        $this->customTransport = ($http !== null);
        $this->breaker = new CircuitBreaker('alpaca');
        $this->apiKey = $apiKey ?? (getenv('ALPACA_API_KEY') ?: null) ?: null;
        $this->apiSecret = $apiSecret ?? (getenv('ALPACA_API_SECRET') ?: null) ?: null;
        $explicit = strtolower((string)(getenv('ALPACA_EQUITIES_ENABLED') ?: '0'));
        $this->equitiesEnabled = ($explicit === '1' || $explicit === 'true' || $explicit === 'yes')
            ? true
            : !empty($this->apiKey) && !empty($this->apiSecret);
    }

    public function name(): string { return 'alpaca'; }
    public function synthetic(): bool { return false; }
    public function priority(): int { return 16; }

    public function supportsMarketClass(string $marketClass): bool
    {
        $c = strtolower($marketClass);
        if ($c === 'crypto') return true;
        if (($c === 'stock' || $c === 'etf') && $this->equitiesEnabled) return true;
        return false;
    }

    public function supportsSymbol(string $symbol): bool
    {
        $s = strtoupper(trim($symbol));
        if (isset(self::UNIFIED_TO_CRYPTO[$s])) return true;
        if (in_array($s, self::CRYPTO_PAIRS, true)) return true;
        if ($this->equitiesEnabled) {
            if (in_array($s, self::EQUITIES, true)) return true;
            if (in_array($s, self::ETFS, true)) return true;
        }
        return false;
    }

    public function supportsTimeframe(string $symbol, string $tf): bool
    {
        return $this->supportsSymbol($symbol) && isset(self::TF_MAP[$tf]);
    }

    public function capabilities(): array
    {
        $classes = ['crypto'];
        $notes = 'Real crypto bars/trades from Alpaca (public, no key required).';
        $delayed = false;
        if ($this->equitiesEnabled) {
            $classes[] = 'stock'; $classes[] = 'etf';
            $notes .= ' US equities/ETFs via keyed API (live on paid plan, delayed on free).';
        } else {
            $delayed = true;
            $notes .= ' Equities/ETFs disabled (set ALPACA_API_KEY + ALPACA_API_SECRET to enable).';
        }
        return ['marketClasses' => $classes, 'timeframes' => array_keys(self::TF_MAP),
                'delayed' => $delayed, 'notes' => $notes];
    }

    public function getCandles(array $req): array
    {
        $symbol = strtoupper(trim((string)($req['symbol'] ?? '')));
        $tf = (string)($req['timeframe'] ?? '1h');
        $limit = min(1000, max(1, (int)($req['limit'] ?? 500)));
        if (!$this->supportsSymbol($symbol)) throw new \RuntimeException("Alpaca does not list {$symbol}");
        $isCrypto = $this->isCrypto($symbol);
        $alpacaSym = $this->toAlpacaSymbol($symbol);
        $interval = self::TF_MAP[$tf] ?? '1Hour';

        if ($isCrypto) {
            $url = $this->baseUrl . '/v1beta3/crypto/us/bars?symbols=' . rawurlencode($alpacaSym)
                 . '&timeframe=' . rawurlencode($interval) . '&limit=' . $limit . '&sort=asc';
            $json = $this->fetchJson($url, false);
            if (!is_array($json) || !isset($json['bars'][$alpacaSym]) || !is_array($json['bars'][$alpacaSym])) {
                throw new \RuntimeException('alpaca crypto klines: unexpected payload');
            }
            return $this->normalizeBars($json['bars'][$alpacaSym]);
        }
        $url = $this->baseUrl . '/v2/stocks/' . rawurlencode($alpacaSym) . '/bars'
             . '?timeframe=' . rawurlencode($interval) . '&limit=' . $limit
             . '&adjustment=raw&feed=sip&sort=asc';
        $json = $this->fetchJson($url, true);
        if (!is_array($json) || !isset($json['bars']) || !is_array($json['bars'])) {
            $msg = is_string($json['message'] ?? null) ? $json['message'] : 'unexpected payload';
            throw new \RuntimeException('alpaca stock klines: ' . $msg);
        }
        return $this->normalizeBars($json['bars']);
    }

    public function getQuote(string $symbol): array
    {
        $symbol = strtoupper(trim($symbol));
        if (!$this->supportsSymbol($symbol)) throw new \RuntimeException("Alpaca does not list {$symbol}");
        $isCrypto = $this->isCrypto($symbol);
        $alpacaSym = $this->toAlpacaSymbol($symbol);

        if ($isCrypto) {
            $url = $this->baseUrl . '/v1beta3/crypto/us/quotes/latest?symbols=' . rawurlencode($alpacaSym);
            $json = $this->fetchJson($url, false);
            $q = $json['quotes'][$alpacaSym] ?? null;
            if (!is_array($q) || !is_numeric($q['bp'] ?? null) || !is_numeric($q['ap'] ?? null)) {
                throw new \RuntimeException('alpaca crypto quote: invalid payload');
            }
            $bid = (float)$q['bp']; $ask = (float)$q['ap'];
            $last = (float)($q['lp'] ?? (($bid + $ask) / 2));
        } else {
            $url = $this->baseUrl . '/v2/stocks/' . rawurlencode($alpacaSym) . '/quotes/latest?feed=sip';
            $json = $this->fetchJson($url, true);
            $q = $json['quote'] ?? null;
            if (!is_array($q) || !is_numeric($q['bp'] ?? null) || !is_numeric($q['ap'] ?? null)) {
                throw new \RuntimeException('alpaca stock quote: invalid payload');
            }
            $bid = (float)$q['bp']; $ask = (float)$q['ap'];
            $last = (float)($q['lp'] ?? (($bid + $ask) / 2));
        }
        if ($bid <= 0 || $ask <= 0 || $ask < $bid) {
            throw new \RuntimeException('alpaca quote returned invalid prices for ' . $symbol);
        }
        return ['symbol' => $symbol, 'bid' => $bid, 'ask' => $ask, 'last' => $last,
                'timestamp' => (int)(microtime(true) * 1000)];
    }

    public function healthCheck(): array
    {
        $started = microtime(true);
        try {
            $json = $this->fetchJson($this->baseUrl . '/v1beta3/crypto/us/bars?symbols=BTC%2FUSD&timeframe=1Min&limit=1', false);
            $up = is_array($json) && isset($json['bars']['BTC/USD']);
            return ['name' => $this->name(), 'status' => $up ? 'UP' : 'DOWN', 'synthetic' => false,
                    'latencyMs' => (int)((microtime(true) - $started) * 1000), 'checkedAt' => time(),
                    'circuitState' => $this->breaker->currentState(),
                    'detail' => $up
                        ? ('Alpaca Markets: crypto=live' . ($this->equitiesEnabled ? ', equities=authenticated' : ', equities=disabled (no key)'))
                        : 'Unrecognized response — manager falls back to next provider.'];
        } catch (\Throwable $e) {
            return ['name' => $this->name(), 'status' => 'DOWN', 'synthetic' => false,
                    'latencyMs' => (int)((microtime(true) - $started) * 1000), 'checkedAt' => time(),
                    'lastError' => $e->getMessage(), 'circuitState' => $this->breaker->currentState(),
                    'detail' => 'Unreachable from this host — manager falls back to next provider.'];
        }
    }

    private function isCrypto(string $symbol): bool
    {
        $s = strtoupper($symbol);
        return isset(self::UNIFIED_TO_CRYPTO[$s]) || in_array($s, self::CRYPTO_PAIRS, true);
    }

    private function toAlpacaSymbol(string $symbol): string
    {
        $s = strtoupper(trim($symbol));
        return self::UNIFIED_TO_CRYPTO[$s] ?? $s;
    }

    private function normalizeBars(array $bars): array
    {
        $out = [];
        foreach ($bars as $b) {
            if (!is_array($b) || !is_numeric($b['t'] ?? null) || !is_numeric($b['o'] ?? null)) continue;
            $out[] = [
                'timestamp' => $this->parseTs((string)$b['t']),
                'open' => (float)$b['o'], 'high' => (float)$b['h'],
                'low' => (float)$b['l'], 'close' => (float)$b['c'],
                'volume' => (float)($b['v'] ?? 0),
            ];
        }
        return $out;
    }

    private function parseTs(string $t): int
    {
        $dt = \DateTime::createFromFormat(\DateTime::RFC3339, $t) ?: \DateTime::createFromFormat('Y-m-d\TH:i:s.v\Z', $t);
        if ($dt) return (int)((float)$dt->format('U.u') * 1000);
        return (int)(strtotime($t) * 1000);
    }

    private function fetchJson(string $url, bool $withAuth): array
    {
        if (!$this->breaker->canCall()) throw new \RuntimeException('alpaca circuit breaker OPEN');
        $saved = $this->http->transport;
        if (!$this->customTransport) {
            $key = $this->apiKey; $secret = $this->apiSecret;
            $this->http->transport = function (string $u) use ($withAuth, $key, $secret): ?string {
                $headers = ["User-Agent: AI_WORKFORCE/0.4", "Accept: application/json"];
                if ($withAuth && $key && $secret) {
                    $headers[] = 'APCA-API-KEY-ID: ' . $key;
                    $headers[] = 'APCA-API-SECRET-KEY: ' . $secret;
                }
                $ctx = stream_context_create([
                    'http' => ['method' => 'GET', 'timeout' => 6,
                               'header' => implode("\r\n", $headers) . "\r\n",
                               'ignore_errors' => true],
                    'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
                ]);
                $body = @file_get_contents($u, false, $ctx);
                return $body === false ? null : $body;
            };
        }
        try {
            $json = $this->http->getJson($url, 1);
        } catch (\Throwable $e) {
            $this->http->transport = $saved;
            $this->breaker->recordFailure();
            throw $e;
        }
        $this->http->transport = $saved;
        $hasData = isset($json['bars']) || isset($json['quote']) || isset($json['quotes']);
        if (isset($json['message']) && is_string($json['message']) && !$hasData) {
            $this->breaker->recordFailure();
            throw new \RuntimeException('alpaca error: ' . $json['message']);
        }
        if (isset($json['error']) && is_string($json['error'])) {
            $this->breaker->recordFailure();
            throw new \RuntimeException('alpaca error: ' . $json['error']);
        }
        $this->breaker->recordSuccess();
        return $json;
    }
}
