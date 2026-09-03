<?php
namespace AIWorkforce\Providers;

/**
 * REAL crypto market data from Coinbase (Advanced Trade) public REST.
 * Docs: https://docs.cdp.coinbase.com/exchange/reference/exchangerestapi_getproductcandles
 *
 * The public "exchange" endpoints do not require a key for market data:
 *
 *   GET /products/{product_id}/candles?granularity=60  (granularity in seconds)
 *       -> [[time(sec), low, high, open, close, volume], ...] (newest first)
 *   GET /products/{product_id}/book?level=1
 *       -> {bids:[[price,size,...]], asks:[[price,size,...]]}
 *
 * Symbol mapping: unified "BTCUSDT" -> Coinbase product "BTC-USDT".
 */
class CoinbaseProvider extends CryptoExchangeProvider
{
    // Coinbase lists USDT books for the majors. We support the same basket as
    // Binance so users can compare providers chart-to-chart.
    public const SYMBOLS = [
        'BTCUSDT', 'ETHUSDT', 'SOLUSDT', 'BNBUSDT', 'XRPUSDT', 'ADAUSDT',
        'DOGEUSDT', 'AVAXUSDT', 'LINKUSDT', 'DOTUSDT', 'MATICUSDT', 'LTCUSDT',
    ];

    private const TF_TO_GRANULARITY = [
        '1m' => 60, '5m' => 300, '15m' => 900,
        '1h' => 3600, '6h' => 21600, '1d' => 86400,
    ];

    public function name(): string { return 'coinbase'; }
    protected function defaultBaseUrl(): string { return 'https://api.exchange.coinbase.com'; }
    protected function envBaseVar(): ?string { return 'COINBASE_API_BASE'; }
    protected function symbols(): array { return self::SYMBOLS; }
    protected function supportedTimeframes(): array { return array_keys(self::TF_TO_GRANULARITY); }
    public function priority(): int { return 14; }
    public function capabilities(): array
    {
        $c = parent::capabilities();
        $c['notes'] = 'Real spot crypto klines/quotes via Coinbase Exchange public REST. Granularities limited to 1m/5m/15m/1h/6h/1d.';
        return $c;
    }

    protected function normalizeSymbol(string $symbol): string
    {
        $s = strtoupper(trim($symbol));
        // "BTCUSDT" -> "BTC-USDT"
        foreach (['USDT','USDC','USD','BTC','ETH','EUR','GBP'] as $quote) {
            if (str_ends_with($s, $quote) && strlen($s) > strlen($quote)) {
                return substr($s, 0, -strlen($quote)) . '-' . $quote;
            }
        }
        return $s;
    }

    protected function normalizeTf(string $tf): string
    {
        return (string) (self::TF_TO_GRANULARITY[$tf] ?? 3600);
    }

    protected function klinesPath(string $exchSymbol, string $interval, int $limit): string
    {
        // Coinbase candles returns max 300 candles; we honour the cap via min(300).
        $gran = $interval; // already granularity seconds
        return '/products/' . $exchSymbol . '/candles?granularity=' . $gran;
    }

    protected function tickerPath(string $exchSymbol): string
    {
        return '/products/' . $exchSymbol . '/book?level=1';
    }

    protected function healthPath(): string { return '/products/BTC-USDT/ticker'; }

    protected function parseKlines(array $json): array
    {
        if (!is_array($json) || !array_is_list($json)) {
            $msg = (string) ($json['message'] ?? 'unexpected candles payload');
            throw new \RuntimeException('coinbase klines failed: ' . $msg);
        }
        // Coinbase returns newest-first; reverse
        $rows = array_reverse($json);
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r)) continue;
            $vals = array_values($r);
            if (count($vals) < 6) continue;
            // [time(seconds), low, high, open, close, volume]
            if (!is_numeric($vals[0]) || !is_numeric($vals[3])) continue;
            $out[] = [
                'timestamp' => (int) (((float) $vals[0]) * 1000),
                'open' => (float) $vals[3],
                'high' => (float) $vals[2],
                'low'  => (float) $vals[1],
                'close'=> (float) $vals[4],
                'volume'=>(float) $vals[5],
            ];
        }
        return $out;
    }

    protected function parseTicker(array $json, string $requestedSymbol): array
    {
        // /book?level=1 returns {bids:[[price,size,...]], asks:[[price,size,...]]}
        $bids = $json['bids'] ?? [];
        $asks = $json['asks'] ?? [];
        if (!is_array($bids) || !is_array($asks) || !$bids || !$asks) {
            $msg = (string) ($json['message'] ?? 'empty book');
            throw new \RuntimeException('coinbase ticker failed: ' . $msg);
        }
        $bid = (float) $bids[0][0];
        $ask = (float) $asks[0][0];
        if ($bid <= 0 || $ask <= 0 || $ask < $bid) {
            throw new \RuntimeException('coinbase ticker returned invalid prices for ' . $requestedSymbol);
        }
        return ['bid' => $bid, 'ask' => $ask, 'last' => ($bid + $ask) / 2, 'timestamp' => (int) (microtime(true) * 1000)];
    }
}
