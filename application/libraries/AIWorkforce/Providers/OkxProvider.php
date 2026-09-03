<?php
namespace AIWorkforce\Providers;

/**
 * REAL crypto market data from OKX public REST v5 (no key required for
 * market data; instType=SPOT). Docs: https://www.okx.com/docs-v5/en/
 *
 * Symbol mapping: unified "BTCUSDT" -> OKX instId "BTC-USDT".
 *
 *   GET /api/v5/market/candles?instId=BTC-USDT&bar=1H&limit=100
 *   GET /api/v5/market/ticker?instId=BTC-USDT
 */
class OkxProvider extends CryptoExchangeProvider
{
    public const SYMBOLS = [
        'BTCUSDT', 'ETHUSDT', 'SOLUSDT', 'BNBUSDT', 'XRPUSDT', 'ADAUSDT',
        'DOGEUSDT', 'AVAXUSDT', 'LINKUSDT', 'DOTUSDT', 'MATICUSDT', 'LTCUSDT',
    ];
    private const TF_MAP = [
        '1m'=>'1m','5m'=>'5m','15m'=>'15m','30m'=>'30m',
        '1h'=>'1H','4h'=>'4H','1d'=>'1D','1w'=>'1W',
    ];

    public function name(): string { return 'okx'; }
    protected function defaultBaseUrl(): string { return 'https://www.okx.com'; }
    protected function envBaseVar(): ?string { return 'OKX_API_BASE'; }
    protected function symbols(): array { return self::SYMBOLS; }
    protected function supportedTimeframes(): array { return array_keys(self::TF_MAP); }
    public function priority(): int { return 13; }

    protected function normalizeSymbol(string $symbol): string
    {
        $s = strtoupper($symbol);
        // Convert BTCUSDT -> BTC-USDT (USDT / USDC quote detection)
        foreach (['USDT','USDC','BTC','ETH'] as $quote) {
            if (str_ends_with($s, $quote) && strlen($s) > strlen($quote)) {
                return substr($s, 0, -strlen($quote)) . '-' . $quote;
            }
        }
        return $s;
    }
    protected function normalizeTf(string $tf): string { return self::TF_MAP[$tf] ?? '1H'; }

    protected function klinesPath(string $exchSymbol, string $interval, int $limit): string
    {
        return '/api/v5/market/candles?instId=' . $exchSymbol
            . '&bar=' . $interval . '&limit=' . min(300, max(1, $limit));
    }
    protected function tickerPath(string $exchSymbol): string
    {
        return '/api/v5/market/ticker?instId=' . $exchSymbol;
    }
    protected function healthPath(): string { return '/api/v5/public/time'; }

    protected function parseKlines(array $json): array
    {
        if (($json['code'] ?? '0') !== '0') {
            throw new \RuntimeException('okx klines failed: ' . (string) ($json['msg'] ?? 'error envelope'));
        }
        $rows = $json['data'] ?? null;
        if (!is_array($rows)) throw new \RuntimeException('okx klines: missing data');
        // OKX returns newest-first — reverse
        $rows = array_reverse($rows);
        // Column order: [ts, o, h, l, c, vol, volCcy, volCcyQuote, confirm]
        $candles = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $vals = array_values($r);
            if (count($vals) < 6) continue;
            if (!is_numeric($vals[0]) || !is_numeric($vals[1])) continue;
            $candles[] = [
                'timestamp' => (int) ((float) $vals[0]), // ms
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
        if (($json['code'] ?? '0') !== '0') {
            throw new \RuntimeException('okx ticker failed: ' . (string) ($json['msg'] ?? 'error'));
        }
        $data = $json['data'] ?? [];
        $t = is_array($data) && isset($data[0]) && is_array($data[0]) ? $data[0] : null;
        if (!$t) throw new \RuntimeException('okx ticker: empty data for ' . $requestedSymbol);
        // bidPx / askPx / last
        $bid = (float) ($t['bidPx'] ?? 0);
        $ask = (float) ($t['askPx'] ?? 0);
        $last = (float) ($t['last'] ?? (($bid + $ask) / 2));
        if ($bid <= 0 || $ask <= 0 || $ask < $bid) {
            throw new \RuntimeException('okx ticker returned invalid prices for ' . $requestedSymbol);
        }
        $ts = isset($t['ts']) && is_numeric($t['ts']) ? (int) $t['ts'] : (int) (microtime(true) * 1000);
        return ['bid' => $bid, 'ask' => $ask, 'last' => $last, 'timestamp' => $ts];
    }
}
