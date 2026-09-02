<?php
namespace AIWorkforce;

use AIWorkforce\Providers\YahooChartProvider;

/**
 * Single market-class inference used by analysis, paper trading and the
 * market-data API. Unknown 6-letter alpha pairs stay forex; other tickers
 * default to stock rather than being silently treated as EURUSD-style FX.
 */
final class MarketClasses
{
    private const KNOWN = [
        'EURUSD' => 'forex', 'GBPUSD' => 'forex', 'USDJPY' => 'forex', 'AUDUSD' => 'forex',
        'USDCAD' => 'forex', 'USDCHF' => 'forex', 'NZDUSD' => 'forex', 'EURGBP' => 'forex',
        'EURJPY' => 'forex', 'GBPJPY' => 'forex',
        'XAUUSD' => 'commodity', 'XAGUSD' => 'commodity',
    ];

    public static function infer(string $symbol): string
    {
        $s = strtoupper(trim($symbol));
        if ($s === '') return 'stock';
        if (isset(self::KNOWN[$s])) return self::KNOWN[$s];
        if (str_ends_with($s, 'USDT')) return 'crypto';
        $listed = YahooChartProvider::classOf($s);
        if ($listed !== null) return $listed;
        if (str_ends_with($s, '=F')) return 'futures';
        if (strlen($s) === 6 && ctype_alpha($s)) return 'forex';
        return 'stock';
    }
}
