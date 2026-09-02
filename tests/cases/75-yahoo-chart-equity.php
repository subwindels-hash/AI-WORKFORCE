<?php
/** Delayed public Yahoo chart adapter for allow-listed stocks/ETFs/futures. */
use AIWorkforce\Http;
use AIWorkforce\MarketClasses;
use AIWorkforce\ProviderManager;
use AIWorkforce\Providers\LicensedAssetMarketDataProvider;
use AIWorkforce\Providers\YahooChartProvider;

if (!function_exists('fx_scaffold_env')) {
    function fx_scaffold_env(string $key, $value): void
    {
        putenv($key . '=' . $value);
    }
}

function fx_yahoo_chart(string $symbol, int $n = 40, float $price = 100.0): array
{
    $ts = []; $o = []; $h = []; $l = []; $c = []; $v = [];
    $t = 1700000000;
    for ($i = 0; $i < $n; $i++) {
        $open = $price + $i * 0.1;
        $close = $open + 0.2;
        $ts[] = $t + $i * 86400;
        $o[] = $open;
        $h[] = $close + 0.3;
        $l[] = $open - 0.3;
        $c[] = $close;
        $v[] = 1000 + $i;
    }
    return [
        'chart' => [
            'result' => [[
                'meta' => [
                    'symbol' => $symbol,
                    'regularMarketPrice' => $c[$n - 1],
                    'regularMarketTime' => $ts[$n - 1],
                    'instrumentType' => 'EQUITY',
                ],
                'timestamp' => $ts,
                'indicators' => ['quote' => [[
                    'open' => $o, 'high' => $h, 'low' => $l, 'close' => $c, 'volume' => $v,
                ]]],
            ]],
            'error' => null,
        ],
    ];
}

function fx_yahoo_http(array $payload): Http
{
    $json = json_encode($payload);
    return new Http(fn(string $url) => $json);
}

test('yahoo chart serves allow-listed stocks as delayed non-synthetic candles', function () {
    $p = new YahooChartProvider('stock', 'https://yahoo.test', fx_yahoo_http(fx_yahoo_chart('AAPL')));
    assert_true($p->supportsSymbol('AAPL'));
    assert_false($p->supportsSymbol('SPY'));
    assert_false($p->supportsSymbol('BTCUSDT'));
    assert_true($p->supportsTimeframe('AAPL', '1d'));
    assert_false($p->supportsTimeframe('AAPL', '4h'));
    $candles = $p->getCandles(['symbol' => 'AAPL', 'timeframe' => '1d', 'limit' => 10]);
    assert_equals(10, count($candles));
    assert_true($candles[9]['high'] >= $candles[9]['close']);
    assert_equals(false, $p->synthetic());
    assert_true($p->capabilities()['delayed']);
    assert_false($p->capabilities()['licenseConfigured']);
    $quote = $p->getQuote('AAPL');
    assert_equals('AAPL', $quote['symbol']);
    assert_true($quote['last'] > 0);
    assert_equals('UP', $p->healthCheck()['status']);
});

test('yahoo chart refuses 4h instead of inventing bars and rejects unknown symbols', function () {
    $p = new YahooChartProvider('stock', 'https://yahoo.test', fx_yahoo_http(fx_yahoo_chart('AAPL')));
    assert_throws(RuntimeException::class, fn() => $p->getCandles(['symbol' => 'AAPL', 'timeframe' => '4h', 'limit' => 40]));
    assert_throws(RuntimeException::class, fn() => $p->getQuote('SPY'));
});

test('yahoo chart rejects Yahoo error envelopes rather than inventing prices', function () {
    $payload = ['chart' => ['result' => null, 'error' => ['code' => 'Not Found', 'description' => 'No data found, symbol may be delisted']]];
    $p = new YahooChartProvider('etf', 'https://yahoo.test', fx_yahoo_http($payload));
    assert_throws(RuntimeException::class, fn() => $p->getCandles(['symbol' => 'SPY', 'timeframe' => '1d', 'limit' => 40]));
    assert_equals('DOWN', $p->healthCheck()['status']);
});

test('yahoo etf and futures adapters isolate market classes', function () {
    $etf = new YahooChartProvider('etf', 'https://yahoo.test', fx_yahoo_http(fx_yahoo_chart('SPY')));
    $fut = new YahooChartProvider('futures', 'https://yahoo.test', fx_yahoo_http(fx_yahoo_chart('ES=F')));
    assert_true($etf->supportsSymbol('SPY'));
    assert_false($etf->supportsSymbol('AAPL'));
    assert_true($fut->supportsSymbol('ES=F'));
    assert_false($fut->supportsSymbol('SPY'));
    assert_true($etf->supportsMarketClass('etf'));
    assert_false($fut->supportsMarketClass('stock'));
});

test('provider manager prefers a configured licensed stock feed over Yahoo', function () {
    fx_scaffold_env('AI_WORKFORCE_TEST_STOCK_DATA_LICENSE', 'contract-123');
    fx_scaffold_env('AI_WORKFORCE_TEST_STOCK_DATA_SYMBOLS', 'AAPL');
    try {
        $licensed = new LicensedAssetMarketDataProvider(
            'stock', 'stock-licensed-test', 'Licensed stocks', 'AI_WORKFORCE_TEST_STOCK_DATA',
            'https://feed.example/v1', true, 'token',
            function (string $url, ?string $token) {
                $candles = [];
                $base = 1700000000000;
                for ($i = 0; $i < 40; $i++) {
                    $candles[] = ['timestamp' => $base - (40 - $i) * 86400000, 'open' => 200 + $i, 'high' => 201 + $i, 'low' => 199 + $i, 'close' => 200.5 + $i, 'volume' => 10];
                }
                return ['data' => ['candles' => $candles]];
            },
            null, null, 12,
        );
        $yahoo = new YahooChartProvider('stock', 'https://yahoo.test', fx_yahoo_http(fx_yahoo_chart('AAPL', 40, 100)));
        $pm = new ProviderManager();
        $pm->register($yahoo);
        $pm->register($licensed);
        $series = $pm->getCandleSeries('AAPL', 'stock', '1d', 40);
        assert_equals('stock-licensed-test', $series['provenance']['source']);
        assert_false($series['provenance']['synthetic']);
    } finally {
        putenv('AI_WORKFORCE_TEST_STOCK_DATA_LICENSE');
        putenv('AI_WORKFORCE_TEST_STOCK_DATA_SYMBOLS');
    }
});

test('market class inference maps equities, ETFs, futures, crypto and FX honestly', function () {
    assert_equals('stock', MarketClasses::infer('AAPL'));
    assert_equals('etf', MarketClasses::infer('SPY'));
    assert_equals('futures', MarketClasses::infer('ES=F'));
    assert_equals('crypto', MarketClasses::infer('BTCUSDT'));
    assert_equals('forex', MarketClasses::infer('EURUSD'));
    assert_equals('commodity', MarketClasses::infer('XAUUSD'));
    assert_equals('stock', platform()->paper->inferMarketClass('NVDA'));
});

test('analysis console lists delayed equity symbols and infers their market class', function () {
    $welcome = file_get_contents(FCPATH . 'application/controllers/Welcome.php');
    assert_contains('inferMarketClass', $welcome);
    assert_contains('AAPL', $welcome);
    assert_contains('SPY', $welcome);
    assert_contains('ES=F', $welcome);
    assert_false(str_contains($welcome, "str_ends_with(\$data['symbol'], 'USDT') ? 'crypto' : 'forex'"));
    $features = file_get_contents(FCPATH . 'application/controllers/Api_system.php');
    assert_contains('Public delayed stock/ETF/futures data (Yahoo chart)', $features);
    $analysis = file_get_contents(FCPATH . 'application/controllers/Api_analysis.php');
    assert_contains('inferMarketClass($sym)', $analysis);
    assert_false(str_contains($analysis, "str_ends_with(\$sym, 'USDT') ? 'crypto' : 'forex'"));
});
