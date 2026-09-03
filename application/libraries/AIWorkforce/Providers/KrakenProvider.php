<?php
namespace AIWorkforce\Providers;

/**
 * REAL crypto market data from Kraken public REST (no key required).
 * Docs: https://docs.kraken.com/api/docs/rest-api/get-ohlc-data
 *
 * Kraken uses its own pair codes (e.g. "XBTUSDT" rather than "BTCUSDT") and
 * wraps OHLC in a nested "result" map keyed by the *altname*. We translate
 * unified symbols explicitly for clarity rather than guessing.
 *
 *   GET /0/public/Time                                 (health)
 *   GET /0/public/OHLC?pair=XBTUSDT&interval=60
 *   GET /0/public/Ticker?pair=XBTUSDT
 */
class KrakenProvider extends CryptoExchangeProvider
{
    /** Unified symbol -> Kraken altname used in public API. */
    public const PAIR_MAP = [
        'BTCUSDT'  => 'XBTUSDT',
        'ETHUSDT'  => 'ETHUSDT',
        'SOLUSDT'  => 'SOLUSDT',
        'BNBUSDT'  => 'BNBUSDT',
        'XRPUSDT'  => 'XRPUSDT',
        'ADAUSDT'  => 'ADAUSDT',
        'DOGEUSDT' => 'DOGEUSDT',
        'AVAXUSDT' => 'AVAXUSDT',
        'LINKUSDT' => 'LINKUSDT',
        'DOTUSDT'  => 'DOTUSDT',
        'MATICUSDT'=> 'MATICUSDT',
        'LTCUSDT'  => 'LTCUSDT',
    ];

    // Kraken interval codes (minutes).
    private const TF_MAP = [
        '1m' => 1, '5m' => 5, '15m' => 15, '30m' => 30,
        '1h' => 60, '4h' => 240, '1d' => 1440, '1w' => 10080,
    ];

    public function name(): string { return 'kraken'; }
    protected function defaultBaseUrl(): string { return 'https://api.kraken.com'; }
    protected function envBaseVar(): ?string { return 'KRAKEN_API_BASE'; }
    protected function symbols(): array { return array_keys(self::PAIR_MAP); }
    protected function supportedTimeframes(): array { return array_keys(self::TF_MAP); }
    public function priority(): int { return 15; }

    protected function normalizeSymbol(string $symbol): string
    {
        return self::PAIR_MAP[strtoupper($symbol)] ?? strtoupper($symbol);
    }
    protected function normalizeTf(string $tf): string
    {
        return (string) (self::TF_MAP[$tf] ?? 60);
    }

    protected function klinesPath(string $exchSymbol, string $interval, int $limit): string
    {
        // last parameter is "since" — when omitted Kraken returns the last ~720 bars,
        // which is more than enough; we slice to $limit after parsing.
        return '/0/public/OHLC?pair=' . $exchSymbol . '&interval=' . $interval;
    }
    protected function tickerPath(string $exchSymbol): string
    {
        return '/0/public/Ticker?pair=' . $exchSymbol;
    }
    protected function healthPath(): string { return '/0/public/Time'; }

    protected function parseKlines(array $json): array
    {
        $err = $json['error'] ?? null;
        if (is_array($err) && $err !== []) {
            throw new \RuntimeException('kraken ohlc failed: ' . implode('; ', $err));
        }
        $result = $json['result'] ?? null;
        if (!is_array($result)) throw new \RuntimeException('kraken ohlc: missing result');
        // Drop the "last" cursor key to find the OHLC list.
        $pairKey = null;
        foreach ($result as $k => $v) {
            if ($k === 'last') continue;
            $pairKey = $k;
            break;
        }
        if ($pairKey === null || !is_array($result[$pairKey])) {
            throw new \RuntimeException('kraken ohlc: missing pair data');
        }
        $rows = $result[$pairKey];
        $out = [];
        foreach ($rows as $r) {
            // [time(sec), open, high, low, close, vwap, volume, count]
            if (!is_array($r) || count($r) < 7) continue;
            if (!is_numeric($r[0]) || !is_numeric($r[1])) continue;
            $out[] = [
                'timestamp' => (int) (((float) $r[0]) * 1000),
                'open' => (float) $r[1],
                'high' => (float) $r[2],
                'low'  => (float) $r[3],
                'close'=> (float) $r[4],
                'volume'=>(float) $r[6],
            ];
        }
        return $out;
    }

    protected function parseTicker(array $json, string $requestedSymbol): array
    {
        $err = $json['error'] ?? null;
        if (is_array($err) && $err !== []) {
            throw new \RuntimeException('kraken ticker failed: ' . implode('; ', $err));
        }
        $result = $json['result'] ?? null;
        if (!is_array($result)) throw new \RuntimeException('kraken ticker: missing result');
        $t = null;
        foreach ($result as $v) { if (is_array($v)) { $t = $v; break; } }
        if (!$t) throw new \RuntimeException('kraken ticker: empty result for ' . $requestedSymbol);
        // a=ask array [price, ...], b=bid array [price, ...], c=last trade [price, ...]
        $bid = is_array($t['b'] ?? null) && isset($t['b'][0]) ? (float) $t['b'][0] : 0.0;
        $ask = is_array($t['a'] ?? null) && isset($t['a'][0]) ? (float) $t['a'][0] : 0.0;
        $last = is_array($t['c'] ?? null) && isset($t['c'][0]) ? (float) $t['c'][0] : (($bid + $ask) / 2);
        if ($bid <= 0 || $ask <= 0 || $ask < $bid) {
            throw new \RuntimeException('kraken ticker returned invalid prices for ' . $requestedSymbol);
        }
        return ['bid' => $bid, 'ask' => $ask, 'last' => $last, 'timestamp' => (int) (microtime(true) * 1000)];
    }
}
