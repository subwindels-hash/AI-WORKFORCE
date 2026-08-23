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
    $connector = new Mt5BridgeConnector('https://mt5-bridge.internal', true, fn(string $url) => ['ok' => true, 'version' => '1.2.3']);
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

test('broker manager reports connector health without an execution surface', function () {
    $manager = new BrokerManager();
    $manager->register(new Mt5BridgeConnector('https://bridge.example', true, fn(string $url) => ['ok' => false]));
    $all = $manager->allStatus();
    assert_equals('DOWN', $all['mt5-bridge']['state']);
    assert_false($all['mt5-bridge']['capabilities']['orderSubmission']);
});
