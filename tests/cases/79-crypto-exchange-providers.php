<?php
/**
 * Crypto exchange provider coverage.
 *
 * Exercises Binance + the four newly added exchanges (Bybit, OKX, Coinbase,
 * Kraken) with a fake HTTP transport so we validate:
 *   - canonical OHLCV shape for each exchange's klines response
 *   - bid/ask/last parsing for tickers
 *   - error-envelope rejection (invented candles never returned)
 *   - zero/invalid prices are rejected
 *   - symbol + timeframe gating per provider
 */

use AIWorkforce\Http;
use AIWorkforce\Providers\BinanceProvider;
use AIWorkforce\Providers\BybitProvider;
use AIWorkforce\Providers\CoinbaseProvider;
use AIWorkforce\Providers\KrakenProvider;
use AIWorkforce\Providers\OkxProvider;

function fakeHttp(array $responsesBySubstr): Http {
    return new Http(function (string $url) use ($responsesBySubstr) {
        foreach ($responsesBySubstr as $needle => $body) {
            if (str_contains($url, $needle)) return $body;
        }
        throw new RuntimeException('unmatched URL: ' . $url);
    });
}

function makeKlinesRows(int $n, float $start = 38000.0): array {
    $out = [];
    $ts = 1700000000000;
    for ($i = 0; $i < $n; $i++) {
        $o = $start + $i * 0.5;
        $c = $o + 0.4;
        $out[] = [$ts + $i * 60000, (string)$o, (string)($c+0.1), (string)($o-0.1), (string)$c, '10.0'];
    }
    return $out;
}

$cases = [
    'binance' => [
        'class'   => BinanceProvider::class,
        'klines'  => json_encode(array_map(fn($r)=>[$r[0],$r[1],$r[2],$r[3],$r[4],$r[5]], makeKlinesRows(60))),
        'ticker'  => json_encode(['symbol'=>'BTCUSDT','bidPrice'=>'38000.0','askPrice'=>'38001.5']),
        'err'     => json_encode(['code'=>-1121,'msg'=>'Invalid symbol.']),
        'zero'    => json_encode(['symbol'=>'BTCUSDT','bidPrice'=>'0','askPrice'=>'0']),
        'sym'     => 'BTCUSDT',
    ],
    'bybit' => [
        'class'   => BybitProvider::class,
        'klines'  => json_encode(['retCode'=>0,'retMsg'=>'OK','result'=>['list'=>array_reverse(makeKlinesRows(60))]]),
        'ticker'  => json_encode(['retCode'=>0,'retMsg'=>'OK','result'=>['list'=>[['symbol'=>'BTCUSDT','bid1Price'=>'38000.0','ask1Price'=>'38001.5','lastPrice'=>'38000.8']]]]),
        'err'     => json_encode(['retCode'=>-1121,'retMsg'=>'Invalid symbol']),
        'zero'    => json_encode(['retCode'=>0,'result'=>['list'=>[['symbol'=>'BTCUSDT','bid1Price'=>'0','ask1Price'=>'0','lastPrice'=>'0']]]]),
        'sym'     => 'BTCUSDT',
    ],
    'okx' => [
        'class'   => OkxProvider::class,
        'klines'  => json_encode(['code'=>'0','msg'=>'','data'=>array_reverse(array_map(fn($r)=>[(string)$r[0],$r[1],$r[2],$r[3],$r[4],$r[5]], makeKlinesRows(60)))]),
        'ticker'  => json_encode(['code'=>'0','data'=>[['instId'=>'BTC-USDT','last'=>'38000.8','bidPx'=>'38000.0','askPx'=>'38001.5','ts'=>'1700000000000']]]),
        'err'     => json_encode(['code'=>'51001','msg'=>'Invalid instId']),
        'zero'    => json_encode(['code'=>'0','data'=>[['instId'=>'BTC-USDT','last'=>'0','bidPx'=>'0','askPx'=>'0']]]),
        'sym'     => 'BTCUSDT',
    ],
    'coinbase' => [
        'class'   => CoinbaseProvider::class,
        // [time(sec), low, high, open, close, volume]  newest first
        'klines'  => json_encode(array_reverse(array_map(fn($r)=>[ (int)($r[0]/1000), (string)($r[3]-0.1), (string)($r[2]+0.1), $r[1], $r[4], $r[5] ], makeKlinesRows(60)))),
        'ticker'  => json_encode(['bids'=>[['38000.0','2.0','2']],'asks'=>[['38001.5','3.0','3']]]),
        'err'     => json_encode(['message'=>'NotFound']),
        'zero'    => json_encode(['bids'=>[['0','2.0']],'asks'=>[['0','3.0']]]),
        'sym'     => 'BTCUSDT',
    ],
    'kraken' => [
        'class'   => KrakenProvider::class,
        'klines'  => json_encode(['error'=>[],'result'=>['XBTUSDT'=>array_map(fn($r)=>[(string)($r[0]/1000),$r[1],$r[2],$r[3],$r[4],'38000.0',$r[5],'7'], makeKlinesRows(60)),'last'=>1700003600]]),
        'ticker'  => json_encode(['error'=>[],'result'=>['XBTUSDT'=>['a'=>['38001.5','1','1'],'b'=>['38000.0','2','2'],'c'=>['38000.8','0.02']]]]),
        'err'     => json_encode(['error'=>['EQuery:Unknown asset pair']]),
        'zero'    => json_encode(['error'=>[],'result'=>['XBTUSDT'=>['a'=>['0','1'],'b'=>['0','1'],'c'=>['0','0']]]]),
        'sym'     => 'BTCUSDT',
    ],
];

foreach ($cases as $name => $c) {
    $cls = $c['class'];

    test($name . ' parses klines into normalized OHLCV', function () use ($cls, $c) {
        $http = fakeHttp(['kline' => $c['klines'], 'OHLC' => $c['klines'], 'candles' => $c['klines']]);
        $p = new $cls('https://x.test', $http);
        $candles = $p->getCandles(['symbol' => $c['sym'], 'timeframe' => '1h', 'limit' => 60]);
        assert_true(count($candles) >= 30, $name . ' should return >=30 candles');
        // chronological
        $prev = 0;
        foreach ($candles as $row) {
            assert_true($row['timestamp'] >= $prev, $name . ' candles not chronological');
            $prev = $row['timestamp'];
            foreach (['timestamp','open','high','low','close','volume'] as $k) {
                assert_true(array_key_exists($k, $row), $name . ' missing key ' . $k);
            }
            assert_true($row['high'] >= $row['low'], $name . ' high<low');
        }
    });

    test($name . ' parses quote (bid/ask/last)', function () use ($cls, $c) {
        $http = fakeHttp(['ticker' => $c['ticker'], 'book' => $c['ticker'], 'Ticker' => $c['ticker']]);
        $p = new $cls('https://x.test', $http);
        $q = $p->getQuote($c['sym']);
        assert_true($q['bid'] > 0, $name . ' bid>0');
        assert_true($q['ask'] > $q['bid'], $name . ' ask>bid');
        assert_true($q['last'] >= $q['bid'] && $q['last'] <= $q['ask'], $name . ' last between bid/ask');
    });

    test($name . ' rejects error envelopes', function () use ($cls, $c) {
        $http = fakeHttp(['kline' => $c['err'], 'OHLC' => $c['err'], 'candles' => $c['err']]);
        $p = new $cls('https://x.test', $http);
        assert_throws(RuntimeException::class, fn() => $p->getCandles(['symbol' => $c['sym'], 'timeframe' => '1h', 'limit' => 10]));
    });

    test($name . ' rejects zero prices', function () use ($cls, $c) {
        $http = fakeHttp(['ticker' => $c['zero'], 'book' => $c['zero'], 'Ticker' => $c['zero']]);
        $p = new $cls('https://x.test', $http);
        assert_throws(RuntimeException::class, fn() => $p->getQuote($c['sym']));
    });

    test($name . ' gates unsupported symbols', function () use ($cls, $c) {
        $p = new $cls('https://x.test', new Http(fn() => 'null'));
        assert_false($p->supportsSymbol('XYZZYX'), $name . ' should not support XYZZYX');
        assert_true($p->supportsSymbol($c['sym']), $name . ' should support ' . $c['sym']);
    });

    test($name . ' reports synthetic=false and crypto market class', function () use ($cls) {
        $p = new $cls('https://x.test', new Http(fn() => 'null'));
        assert_false($p->synthetic(), $name . ' must not be synthetic');
        $caps = $p->capabilities();
        assert_true(in_array('crypto', $caps['marketClasses'], true), $name . ' must serve crypto');
        assert_true(count($caps['timeframes']) >= 4, $name . ' must declare timeframes');
    });
}

test('ProviderManager falls through exchanges when primary is DOWN', function () {
    $pm = new \AIWorkforce\ProviderManager();

    $poison = new BinanceProvider('https://binance.invalid', new Http(fn() => json_encode(['code'=>-1,'msg'=>'nope'])));
    $ok = new OkxProvider('https://okx.test', new Http(fn() => json_encode([
        'code'=>'0','data'=>[
            ['instId'=>'BTC-USDT','last'=>'38000.8','bidPx'=>'38000.0','askPx'=>'38001.5','ts'=>'1700000000000'],
        ]
    ])));
    // klines for OKX must also return or fallback fails — wire klines too
    $klines = array_map(fn($r)=>[(string)$r[0],$r[1],$r[2],$r[3],$r[4],$r[5]], makeKlinesRows(80));
    $multiHttp = new Http(function (string $url) use ($klines) {
        if (str_contains($url, 'candles')) return json_encode(['code'=>'0','data'=>$klines]);
        return json_encode(['code'=>'0','data'=>[['instId'=>'BTC-USDT','last'=>'38000.8','bidPx'=>'38000.0','askPx'=>'38001.5','ts'=>'1700000000000']]]);
    });
    $okReal = new OkxProvider('https://okx.test', $multiHttp);

    $pm->register($poison); // prio 10, will fail
    $pm->register($okReal); // prio 13

    // Reset caches by constructing a fresh PM via register order is enough
    $q = $pm->getQuote('BTCUSDT');
    assert_equals('okx', $q['source'], 'fallback should serve from okx when binance is down');

    $candles = $pm->getCandleSeries('BTCUSDT', 'crypto', '1h', 60);
    assert_equals('okx', $candles['provenance']['source']);
    assert_false($candles['provenance']['synthetic']);
    assert_true(count($candles['candles']) >= 30);
});
