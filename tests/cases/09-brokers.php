<?php
use Aegis\Brokers\BrokerManager;
use Aegis\Brokers\Mt5BridgeConnector;

test('MT5 connector is disabled and has no execution capability by default', function () {
    $connector = new Mt5BridgeConnector('', false);
    $status = $connector->status();
    assert_equals('DISABLED', $status['state']);
    assert_false($connector->capabilities()['orderSubmission']);
    assert_false($status['configured']);
});

test('MT5 connector only becomes ready from a successful health probe', function () {
    $connector = new Mt5BridgeConnector('https://mt5-bridge.internal', true, fn(string $url, string $path, ?string $token) => ['ok' => true, 'version' => '1.2.3']);
    $status = $connector->status();
    assert_equals('READY', $status['state']);
    assert_equals('1.2.3', $status['bridgeVersion']);
    assert_false($connector->capabilities()['orderSubmission']);
});

test('MT5 connector rejects unsafe or malformed bridge URLs', function () {
    $connector = new Mt5BridgeConnector('ftp://user:secret@host', true);
    $status = $connector->status();
    assert_equals('NOT_CONFIGURED', $status['state']);
    assert_false($status['configured']);
});

test('MT5 account and quote reads require a token and use read-only bridge paths', function () {
    $calls = [];
    $connector = new Mt5BridgeConnector('https://bridge.example', true,
        function (string $url, string $path, ?string $token) use (&$calls) {
            $calls[] = [$path, $token];
            if ($path === '/v1/account') return ['data' => ['accountId' => '123', 'balance' => 1000.0, 'equity' => 995.0, 'currency' => 'USD']];
            if ($path === '/v1/quotes/EURUSD') return ['data' => ['symbol' => 'EURUSD', 'bid' => 1.08, 'ask' => 1.081]];
            return ['ok' => true];
        }, 'test-token');
    assert_equals(1000.0, $connector->account()['balance']);
    assert_equals('EURUSD', $connector->quote('eurusd')['symbol']);
    assert_equals(['/v1/account', 'test-token'], $calls[0]);
    assert_equals(['/v1/quotes/EURUSD', 'test-token'], $calls[1]);
    assert_false($connector->capabilities()['orderSubmission']);
});

test('broker data contracts normalize account and quote payloads', function () {
    $account = \Aegis\Brokers\BrokerDataNormalizer::account(['login' => 7, 'currency' => 'usd', 'balance' => 1200, 'equity' => 1190, 'margin' => 100], 'test');
    assert_equals('USD', $account['currency']);
    assert_close(1090, $account['freeMargin'], 0.001);
    $quote = \Aegis\Brokers\BrokerDataNormalizer::quote(['symbol' => 'eurusd', 'bid' => 1.1, 'ask' => 1.1002], 'test');
    assert_equals('EURUSD', $quote['symbol']);
    assert_close(0.0002, $quote['spread'], 0.000001);
});

test('broker manager reports connector health without an execution surface', function () {
    $manager = new BrokerManager();
    $manager->register(new Mt5BridgeConnector('https://bridge.example', true, fn(string $url, string $path, ?string $token) => ['ok' => false]));
    $all = $manager->allStatus();
    assert_equals('DOWN', $all['mt5-bridge']['state']);
    assert_false($all['mt5-bridge']['capabilities']['orderSubmission']);
});
