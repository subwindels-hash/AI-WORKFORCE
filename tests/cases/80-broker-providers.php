<?php
namespace AIWorkforce\Tests;

/**
 * Unit tests for the new Alpaca / OANDA / IBKR market-data providers.
 *
 * These tests exercise the parsers using a fake HTTP transport and never
 * hit the live internet, matching the style used by
 * 79-crypto-exchange-providers.php.
 */

require_once __DIR__ . '/../bootstrap.php';

use AIWorkforce\Providers\AlpacaProvider;
use AIWorkforce\Providers\IbkrProvider;
use AIWorkforce\Providers\OandaProvider;

/* -------------------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------------------- */

function makeAlpaca(array $responses, ?string $key = null, ?string $secret = null): AlpacaProvider
{
    $calls = 0;
    $http = new \AIWorkforce\Http(function (string $url, array $opts = []) use (&$calls, $responses) {
        $idx = $calls++;
        if ($idx >= count($responses)) {
            throw new \RuntimeException("alpaca fake transport out of responses for $url");
        }
        // Http::getJson() json_decode()s the transport body, so the fake must
        // return a JSON string, not the already-decoded array.
        return json_encode($responses[$idx]);
    });
    return new AlpacaProvider('https://data.alpaca.markets', $http, $key, $secret);
}

function makeOanda(array $responses, string $token = 'fake-token'): OandaProvider
{
    $calls = 0;
    $http = new \AIWorkforce\Http(function (string $url, array $opts = []) use (&$calls, $responses) {
        $idx = $calls++;
        if ($idx >= count($responses)) {
            throw new \RuntimeException("oanda fake transport out of responses for $url");
        }
        return json_encode($responses[$idx]);
    });
    return new OandaProvider('https://api-fxpractice.oanda.com', $http, $token);
}

function makeIbkr(array $responses, bool $enabled = true): IbkrProvider
{
    $calls = 0;
    $http = new \AIWorkforce\Http(function (string $url, array $opts = []) use (&$calls, $responses) {
        $idx = $calls++;
        if ($idx >= count($responses)) {
            throw new \RuntimeException("ibkr fake transport out of responses for $url");
        }
        return json_encode($responses[$idx]);
    });
    return new IbkrProvider('https://localhost:5000', $http, $enabled);
}

/* -------------------------------------------------------------------------
 * Tests
 * ------------------------------------------------------------------------- */

$tests = [];

// 1. Alpaca public crypto bars parse and produce chronological OHLCV -------
$tests[] = function (): array {
    $p = makeAlpaca([
        ['bars' => ['BTC/USD' => [
            ['t' => '2024-01-02T12:00:00Z', 'o' => 42000.0, 'h' => 42100.0, 'l' => 41900.0, 'c' => 42050.0, 'v' => 12.5],
            ['t' => '2024-01-02T12:01:00Z', 'o' => 42050.0, 'h' => 42200.0, 'l' => 42040.0, 'c' => 42180.0, 'v' => 8.1],
        ]]],
    ]);
    $c = $p->getCandles(['symbol' => 'BTC/USD', 'timeframe' => '1m', 'limit' => 10]);
    assert_eq(count($c), 2, 'alpaca_crypto_bar_count');
    assert_true($c[0]['timestamp'] < $c[1]['timestamp'], 'alpaca_crypto_chronological');
    assert_true($c[0]['open'] === 42000.0 && $c[0]['close'] === 42050.0, 'alpaca_crypto_ohlc');
    return ['msg' => 'Alpaca crypto bars: ok'];
};

// 2. Alpaca crypto quote parsing -------------------------------------------
$tests[] = function (): array {
    $p = makeAlpaca([
        ['quotes' => ['BTC/USD' => ['bp' => 42000.0, 'ap' => 42001.5, 'lp' => 42000.8]]],
    ]);
    $q = $p->getQuote('BTC/USD');
    assert_true($q['bid'] < $q['last'] && $q['last'] < $q['ask'], 'alpaca_quote_spread');
    assert_true($q['bid'] === 42000.0 && $q['ask'] === 42001.5, 'alpaca_quote_prices');
    return ['msg' => 'Alpaca crypto quote: ok'];
};

// 3. Alpaca equities disabled without key ----------------------------------
$tests[] = function (): array {
    $p = makeAlpaca([[]]);
    assert_true(!$p->supportsSymbol('AAPL'), 'alpaca_no_key_no_equities');
    assert_true($p->supportsSymbol('BTC/USD'), 'alpaca_crypto_always');
    $caps = $p->capabilities();
    assert_true(!in_array('stock', $caps['marketClasses'], true), 'alpaca_no_stock_cap_without_key');
    return ['msg' => 'Alpaca equities off without key: ok'];
};

// 4. Alpaca rejects error envelope -----------------------------------------
$tests[] = function (): array {
    $p = makeAlpaca([['message' => 'forbidden: subscription required']]);
    $thrown = null;
    try { $p->getQuote('BTC/USD'); } catch (\Throwable $e) { $thrown = $e; }
    assert_true($thrown !== null && str_contains($thrown->getMessage(), 'forbidden'), 'alpaca_error_envelope');
    return ['msg' => 'Alpaca error envelope rejected: ok'];
};

// 5. Alpaca equities enabled with key parses stocks ------------------------
$tests[] = function (): array {
    $p = makeAlpaca([
        ['bars' => [
            ['t' => '2024-01-02T14:30:00Z', 'o' => 185.0, 'h' => 186.0, 'l' => 184.5, 'c' => 185.7, 'v' => 1200000],
        ]],
    ], 'PK_FAKE', 'SECRET_FAKE');
    assert_true($p->supportsSymbol('AAPL'), 'alpaca_keyed_supports_aapl');
    $c = $p->getCandles(['symbol' => 'AAPL', 'timeframe' => '1h', 'limit' => 1]);
    assert_eq(count($c), 1, 'alpaca_stock_bar_count');
    assert_true($c[0]['close'] === 185.7, 'alpaca_stock_close');
    return ['msg' => 'Alpaca keyed equities: ok'];
};

// 6. OANDA disabled without token ------------------------------------------
$tests[] = function (): array {
    $p = new OandaProvider('https://api-fxpractice.oanda.com');
    $h = $p->healthCheck();
    assert_eq($h['status'], 'DISABLED', 'oanda_disabled_status');
    assert_true(!$p->supportsSymbol('EURUSD'), 'oanda_disabled_no_symbols');
    return ['msg' => 'OANDA disabled without token: ok'];
};

// 7. OANDA candles parse mid prices ----------------------------------------
$tests[] = function (): array {
    $p = makeOanda([
        ['candles' => [
            ['time' => '2024-01-02T12:00:00.000000000Z', 'mid' => ['o'=>'1.0900','h'=>'1.0920','l'=>'1.0895','c'=>'1.0915'], 'volume' => 1250],
            ['time' => '2024-01-02T13:00:00.000000000Z', 'mid' => ['o'=>'1.0915','h'=>'1.0930','l'=>'1.0908','c'=>'1.0928'], 'volume' => 980],
        ]],
    ]);
    $c = $p->getCandles(['symbol' => 'EURUSD', 'timeframe' => '1h', 'limit' => 10]);
    assert_eq(count($c), 2, 'oanda_candle_count');
    assert_true($c[0]['timestamp'] < $c[1]['timestamp'], 'oanda_chronological');
    assert_true($c[0]['open'] === 1.0900 && $c[0]['close'] === 1.0915, 'oanda_ohlc');
    return ['msg' => 'OANDA candles parse: ok'];
};

// 8. OANDA quote bid/ask spread --------------------------------------------
$tests[] = function (): array {
    $p = makeOanda([
        ['prices' => [['bids' => [['price' => '1.0910']], 'asks' => [['price' => '1.0912']]]]],
    ]);
    $q = $p->getQuote('EURUSD');
    assert_true($q['bid'] < $q['ask'], 'oanda_quote_spread');
    assert_true(abs($q['last'] - 1.0911) < 0.00001, 'oanda_mid_price');
    return ['msg' => 'OANDA quote: ok'];
};

// 9. OANDA rejects error envelope ------------------------------------------
$tests[] = function (): array {
    $p = makeOanda([['errorMessage' => 'Insufficient authorization to perform request']]);
    $thrown = null;
    try { $p->getCandles(['symbol' => 'EURUSD', 'timeframe' => '1h']); } catch (\Throwable $e) { $thrown = $e; }
    assert_true($thrown !== null && str_contains($thrown->getMessage(), 'Insufficient'), 'oanda_error_envelope');
    return ['msg' => 'OANDA error envelope rejected: ok'];
};

// 10. IBKR disabled by default ---------------------------------------------
$tests[] = function (): array {
    $p = new IbkrProvider('https://localhost:5000');
    // constructor doesn't gate; supportsSymbol checks the enabled flag
    assert_true(!$p->supportsSymbol('AAPL'), 'ibkr_constructor_disabled');
    $h = $p->healthCheck();
    assert_eq($h['status'], 'DISABLED', 'ibkr_disabled_status');
    assert_true(!$p->supportsSymbol('AAPL'), 'ibkr_disabled_no_symbols');
    return ['msg' => 'IBKR disabled by default: ok'];
};

// 11. IBKR history parses array points -------------------------------------
$tests[] = function (): array {
    $p = makeIbkr([
        ['data' => [
            // IBKR format: [ts_ms, open, close, high, low, volume]
            [1704196800000, 185.0, 185.7, 186.0, 184.5, 1200000],
            [1704200400000, 185.7, 186.2, 186.5, 185.5, 980000],
        ]],
    ]);
    assert_true($p->supportsSymbol('AAPL'), 'ibkr_supports_aapl');
    $c = $p->getCandles(['symbol' => 'AAPL', 'timeframe' => '1h', 'limit' => 10]);
    assert_eq(count($c), 2, 'ibkr_bar_count');
    assert_true($c[0]['open'] === 185.0 && $c[0]['close'] === 185.7, 'ibkr_ohlc');
    assert_true($c[0]['timestamp'] === 1704196800000, 'ibkr_ts_ms');
    return ['msg' => 'IBKR history parse: ok'];
};

// 12. IBKR snapshot quote fields 84/85/31 ----------------------------------
$tests[] = function (): array {
    $p = makeIbkr([
        [['conid' => 265598, '84' => '185.10', '85' => '185.20', '31' => '185.15']],
    ]);
    $q = $p->getQuote('AAPL');
    assert_true($q['bid'] === 185.10 && $q['ask'] === 185.20 && $q['last'] === 185.15, 'ibkr_quote_fields');
    return ['msg' => 'IBKR snapshot quote: ok'];
};

// 13. IBKR tickle health DEGRADED when session empty -----------------------
$tests[] = function (): array {
    $p = makeIbkr([
        ['session' => '', 'ssoExpires' => 0, 'iserver' => ['authStatus' => ['authenticated' => false]]],
    ]);
    $h = $p->healthCheck();
    assert_eq($h['status'], 'DEGRADED', 'ibkr_degraded_unauth');
    return ['msg' => 'IBKR unauth reported DEGRADED: ok'];
};

// 14. Provider capabilities shape ------------------------------------------
$tests[] = function (): array {
    $alpaca = makeAlpaca([[]]);
    $caps = $alpaca->capabilities();
    assert_true(in_array('crypto', $caps['marketClasses'], true), 'caps_alpaca_crypto');
    assert_true(in_array('1m', $caps['timeframes'], true) && in_array('1d', $caps['timeframes'], true), 'caps_alpaca_tfs');

    $oanda = new OandaProvider();
    $oc = $oanda->capabilities();
    assert_true(in_array('forex', $oc['marketClasses'], true), 'caps_oanda_forex');

    $ibkr = new IbkrProvider();
    $ic = $ibkr->capabilities();
    assert_true(in_array('stock', $ic['marketClasses'], true) && in_array('futures', $ic['marketClasses'], true), 'caps_ibkr_classes');
    return ['msg' => 'Capabilities shapes: ok'];
};

// 15. All three synthetic() = false ----------------------------------------
$tests[] = function (): array {
    assert_true(!(makeAlpaca([[]]))->synthetic(), 'alpaca_not_synthetic');
    assert_true(!(new OandaProvider())->synthetic(), 'oanda_not_synthetic');
    assert_true(!(new IbkrProvider())->synthetic(), 'ibkr_not_synthetic');
    return ['msg' => 'None marked synthetic: ok'];
};

run('80-broker-providers', $tests);
