<?php
namespace AIWorkforce\Providers;

/**
 * REAL crypto market data from Bybit public REST v5 (no key required for
 * market data; category=spot). Docs: https://bybit-exchange.github.io/docs/
 *
 *   GET /v5/market/kline?category=spot&symbol=BTCUSDT&interval=60&limit=200
 *   GET /v5/market/tickers?category=spot&symbol=BTCUSDT  (best bid/ask)
 *
 * Reports DOWN and falls back via the base class circuit breaker when the
 * host cannot reach api.bybit.com.
 */
class BybitProvider extends CryptoExchangeProvider
{
    public const SYMBOLS = [
        'BTCUSDT', 'ETHUSDT', 'SOLUSDT', 'BNBUSDT', 'XRPUSDT', 'ADAUSDT',
        'DOGEUSDT', 'AVAXUSDT', 'LINKUSDT', 'DOTUSDT', 'MATICUSDT', 'LTCUSDT',
    ];
    private const TF_MAP = [
        '1m'=>'1','5m'=>'5','15m'=>'15','30m'=>'30',
        '1h'=>'60','4h'=>'240','1d'=>'D','1w'=>'W',
    ];

    public function name(): string { return 'bybit'; }
    protected function defaultBaseUrl(): string { return 'https://api.bybit.com'; }
    protected function envBaseVar(): ?string { return 'BYBIT_API_BASE'; }
    protected function symbols(): array { return self::SYMBOLS; }
    protected function supportedTimeframes(): array { return array_keys(self::TF_MAP); }
    public function priority(): int { return 12; }

    protected function normalizeSymbol(string $symbol): string { return strtoupper($symbol); }
    protected function normalizeTf(string $tf): string { return self::TF_MAP[$tf] ?? '60'; }
    protected function fallbackHosts(): array { return ['https://api.bytick.com']; }

    protected function klinesPath(string $exchSymbol, string $interval, int $limit): string
    {
        return '/v5/market/kline?category=spot&symbol=' . $exchSymbol
            . '&interval=' . $interval . '&limit=' . min(1000, max(1, $limit));
    }

    protected function tickerPath(string $exchSymbol): string
    {
        return '/v5/market/tickers?category=spot&symbol=' . $exchSymbol;
    }

    protected function healthPath(): string { return '/v5/market/time'; }

    protected function parseKlines(array $json): array
    {
        // Bybit returns {retCode:0, result:{list:[{startTime,open,high,low,close,volume,...},...], symbol:...}}
        if ((int) ($json['retCode'] ?? -1) !== 0) {
            throw new \RuntimeException('bybit klines failed: ' . (string) ($json['retMsg'] ?? 'error envelope'));
        }
        $list = $json['result']['list'] ?? null;
        if (!is_array($list)) throw new \RuntimeException('bybit klines: missing result.list');
        // Bybit returns newest-first — reverse to chronological
        $rows = array_reverse($list);
        $candles = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $vals = array_values($r);
            if (count($vals) < 6) continue;
            // Order per docs: startTime(ms), open, high, low, close, volume, turnover
            if (!is_numeric($vals[0]) || !is_numeric($vals[1])) continue;
            $candles[] = [
                'timestamp' => (int) (((float) $vals[0])), // already ms
                'open' => (float) $vals[1],
                'high' => (float) $vals[2],
                'low'  => (float) $vals[3],
                'close'=> (float) $vals[4],
                'volume'=>(float) $vals[5],
            ];
        }
        return $candles;
    }

    protected function parseTicker(array $json, string $requestedSymbol): array
    {
        if ((int) ($json['retCode'] ?? -1) !== 0) {
            throw new \RuntimeException('bybit ticker failed: ' . (string) ($json['retMsg'] ?? 'error'));
        }
        $list = $json['result']['list'] ?? [];
        $t = is_array($list) && isset($list[0]) && is_array($list[0]) ? $list[0] : null;
        if (!$t) throw new \RuntimeException('bybit ticker: empty list for ' . $requestedSymbol);
        // bid1Price / ask1Price / lastPrice
        $bid = (float) ($t['bid1Price'] ?? 0);
        $ask = (float) ($t['ask1Price'] ?? 0);
        $last = (float) ($t['lastPrice'] ?? (($bid + $ask) / 2));
        if ($bid <= 0 || $ask <= 0 || $ask < $bid) {
            throw new \RuntimeException('bybit ticker returned invalid prices for ' . $requestedSymbol);
        }
        return ['bid' => $bid, 'ask' => $ask, 'last' => $last, 'timestamp' => (int) (microtime(true) * 1000)];
    }
}
