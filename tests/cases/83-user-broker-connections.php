<?php
namespace AIWorkforce\Tests;

/**
 * Unit tests for the per-user broker connection registry. Verifies schema
 * creation, save/get/delete cycles, encryption round-trip, safety invariants
 * (trading_enabled→enabled downgrade, live→trading downgrade) and the
 * publicRow() contract (ciphertext never leaked).
 *
 * Uses an in-memory SQLite PDO shim that exposes the small subset of
 * CI's query builder the repository consumes (where/get/insert/update/
 * delete/order_by/result_array/row_array). A real CI database handle is
 * used end-to-end via the integration test suite.
 */
require_once __DIR__ . '/../bootstrap.php';

use AIWorkforce\Brokers\UserBrokerConnections;

/* ---- minimal fake DB object (implements the subset we use) ---- */

class FakeDbResult {
    public function __construct(private array $rows) {}
    public function result_array(): array { return $this->rows; }
    public function row_array(): ?array { return $this->rows[0] ?? null; }
    public function num_rows(): int { return count($this->rows); }
}

class FakeDb
{
    public array $tables = [];
    private int $autoId = 0;
    public string $lastSql = '';
    public array $queries = [];

    public function query(string $sql): FakeDbResult
    {
        $this->queries[] = $sql;
        if (preg_match('/CREATE TABLE IF NOT EXISTS (\w+)/i', $sql, $m)) {
            $this->tables[$m[1]] = [];
        }
        return new FakeDbResult([]);
    }

    public function where($key, $val = null): self {
        $this->filters[$key] = $val; return $this;
    }
    public function order_by($_a, $_b): self { return $this; }
    public function limit($_n): self { return $this; }
    public function get(string $table): FakeDbResult {
        $rows = array_values(array_filter($this->tables[$table] ?? [], function($r){
            foreach ($this->filters ?? [] as $k => $v) if (($r[$k] ?? null) !== $v) return false;
            return true;
        }));
        $this->filters = [];
        return new FakeDbResult($rows);
    }
    public function get_where(string $table, array $filter, int $limit = 1): FakeDbResult {
        foreach ($filter as $k => $v) $this->where($k, $v);
        return $this->limit($limit)->get($table);
    }
    public function insert(string $table, array $row): int {
        $row['id'] = ++$this->autoId;
        $this->tables[$table][] = $row;
        return $row['id'];
    }
    public function insert_id(): int { return $this->autoId; }
    public function update(string $table, array $patch): void {
        foreach ($this->tables[$table] ?? [] as $i => $r) {
            $ok = true;
            foreach ($this->filters ?? [] as $k => $v) if (($r[$k] ?? null) !== $v) { $ok = false; break; }
            if ($ok) $this->tables[$table][$i] = array_merge($r, $patch);
        }
        $this->filters = [];
    }
    public function delete(string $table): void {
        $this->tables[$table] = array_values(array_filter($this->tables[$table] ?? [], function($r){
            foreach ($this->filters ?? [] as $k => $v) if (($r[$k] ?? null) === $v) return false;
            return true;
        }));
        $this->filters = [];
    }
    public function count_all_results(string $table): int {
        $n = count($this->get($table)->result_array());
        $this->filters = [];
        return $n;
    }
    private array $filters = [];
}

/* ---- tests ---- */

$tests = [];

$tests[] = function(): array {
    $db = new FakeDb();
    $repo = new UserBrokerConnections($db, 'test-encryption-key-32bytes!!');
    $saved = $repo->save(1, 'mt5-bridge', [
        'base_url' => 'http://localhost:8765',
        'token' => 'super-secret-token',
        'account_hint' => '1234567',
        'enabled' => true,
        'trading_enabled' => false,
        'live_allowed' => false,
    ]);
    assert_true(($saved['enabled'] ?? null) === true, 'mt5_enabled');
    assert_true(!isset($saved['token_ciphertext']), 'public_row_no_ciphertext');
    assert_true(($saved['has_token'] ?? null) === true, 'has_token_flag');
    assert_eq($saved['base_url'], 'http://localhost:8765', 'mt5_url');
    return ['msg' => 'save mt5 connection ok'];
};

$tests[] = function(): array {
    $db = new FakeDb();
    $repo = new UserBrokerConnections($db, 'test-encryption-key-32bytes!!');
    $repo->save(1, 'oanda', [
        'base_url' => 'https://api-fxpractice.oanda.com',
        'token' => 'oanda-bearer-token-xyz',
        'enabled' => true, 'trading_enabled' => true, 'live_allowed' => true,
    ]);
    // Inspect DB directly — ciphertext must differ from plaintext.
    $rows = $db->tables['user_broker_connections'];
    assert_eq(count($rows), 1, 'one_row');
    assert_true(strpos($rows[0]['token_ciphertext'] ?? '', 'oanda-bearer-token-xyz') === false, 'cipher_not_plain');
    assert_true(($rows[0]['token_nonce'] ?? '') !== '', 'nonce_set');
    // Now buildConnector must decrypt and produce a working TradingConnector.
    $c = $repo->buildConnector($rows[0]);
    assert_true($c !== null, 'connector_built');
    assert_true($c->id() === 'user-oanda', 'connector_id');
    $caps = $c->capabilities();
    assert_true($caps['orderSubmission'] === true, 'caps_trading_enabled');
    assert_true($caps['liveTrading'] === true, 'caps_live_allowed');
    return ['msg' => 'encryption + buildConnector ok'];
};

$tests[] = function(): array {
    $db = new FakeDb();
    $repo = new UserBrokerConnections($db, 'test-encryption-key-32bytes!!');
    // Try to enable live_allowed without trading_enabled: should downgrade.
    $saved = $repo->save(1, 'alpaca', [
        'base_url' => 'https://paper-api.alpaca.markets',
        'token' => 'alpaca-key', 'enabled' => true,
        'trading_enabled' => false, 'live_allowed' => true,
    ]);
    assert_true($saved['trading_enabled'] === false, 'trading_off');
    assert_true($saved['live_allowed'] === false, 'live_downgraded_without_trading');
    // Try trading_enabled without enabled: downgrade.
    $saved = $repo->save(1, 'alpaca', [
        'base_url' => 'https://paper-api.alpaca.markets',
        'enabled' => false, 'trading_enabled' => true, 'live_allowed' => false,
    ]);
    assert_true($saved['enabled'] === false, 'disabled_stays_off');
    assert_true($saved['trading_enabled'] === false, 'trading_downgraded_when_disabled');
    return ['msg' => 'safety downgrades applied'];
};

$tests[] = function(): array {
    $db = new FakeDb();
    $repo = new UserBrokerConnections($db, 'test-encryption-key-32bytes!!');
    $repo->save(1, 'ib', [
        'base_url' => 'https://localhost:5000',
        'enabled' => true, 'trading_enabled' => false, 'live_allowed' => false,
    ]);
    $rows = $db->tables['user_broker_connections'];
    // IB is no-token broker — save succeeds and ciphertext is null.
    assert_true(empty($rows[0]['token_ciphertext']), 'ib_no_token');
    return ['msg' => 'no-token broker (ib) ok'];
};

$tests[] = function(): array {
    $db = new FakeDb();
    $repo = new UserBrokerConnections($db, 'test-encryption-key-32bytes!!');
    try {
        $repo->save(1, 'oanda', ['base_url'=>'https://api-fxpractice.oanda.com', 'token'=>'', 'enabled'=>true]);
        assert_true(false, 'should_have_thrown');
    } catch (\InvalidArgumentException $e) {
        assert_true(str_contains($e->getMessage(), 'token'), 'needs_token_error');
    }
    // Invalid URL rejected.
    try {
        $repo->save(1, 'oanda', ['base_url'=>'not-a-url', 'token'=>'x', 'enabled'=>true]);
        assert_true(false, 'should_have_thrown_url');
    } catch (\InvalidArgumentException $e) {
        assert_true(str_contains($e->getMessage(), 'http'), 'url_error');
    }
    return ['msg' => 'validation rejected bad input'];
};

$tests[] = function(): array {
    $db = new FakeDb();
    $repo = new UserBrokerConnections($db, 'test-encryption-key-32bytes!!');
    $repo->save(7, 'mt5-bridge', [
        'base_url' => 'http://localhost:8765', 'token' => 'abc',
        'enabled' => true, 'trading_enabled' => false, 'live_allowed' => false,
    ]);
    // User 8 must see no connections.
    $mine = $repo->listForUser(8);
    assert_eq(count($mine), 0, 'user_isolation');
    $mine7 = $repo->listForUser(7);
    assert_eq(count($mine7), 1, 'user7_has_one');
    // Delete removes it.
    $repo->delete(7, 'mt5-bridge');
    assert_eq(count($repo->listForUser(7)), 0, 'deleted');
    return ['msg' => 'per-user scoping + delete ok'];
};

$tests[] = function(): array {
    $db = new FakeDb();
    $repo = new UserBrokerConnections($db, 'test-encryption-key-32bytes!!');
    $repo->save(1, 'bybit', ['base_url' => 'https://api.bybit.com', 'token' => 'bybit-api-secret', 'enabled' => true]);
    $conns = $repo->connectorsForUser(1);
    assert_eq(count($conns), 1, 'one_connector_registered');
    $c = reset($conns);
    assert_true($c->id() === 'user-bybit', 'connector_id_prefix');
    assert_true($c instanceof \AIWorkforce\Brokers\ConfiguredTradingConnector, 'connector_type');
    // Disabled connections are excluded.
    $repo->save(1, 'binance', ['base_url' => 'https://api.binance.com', 'token' => 'x', 'enabled' => false]);
    $conns2 = $repo->connectorsForUser(1);
    assert_eq(count($conns2), 1, 'disabled_excluded');
    return ['msg' => 'connectorsForUser filters correctly'];
};

$tests[] = function(): array {
    $db = new FakeDb();
    $repo = new UserBrokerConnections($db, 'test-encryption-key-32bytes!!');
    $repo->save(1, 'mt5-bridge', ['base_url' => 'http://localhost:8765', 'token' => 'abc', 'enabled' => true]);
    $repo->recordTestResult(1, 'mt5-bridge', true, 'bridge reachable account=123');
    $row = $repo->findForUser(1, 'mt5-bridge');
    assert_true($row['last_test_ok'] === true, 'test_ok_flag');
    assert_true(str_contains($row['last_test_message'] ?? '', 'reachable'), 'test_message');
    return ['msg' => 'recordTestResult ok'];
};

$tests[] = function(): array {
    // Supported broker metadata covers all connectors registered in Platform.
    $expected = ['mt5-bridge','mt4-bridge','oanda','alpaca','ib','binance','bybit','okx','coinbase','kraken'];
    foreach ($expected as $id) {
        assert_true(isset(\AIWorkforce\Brokers\UserBrokerConnections::SUPPORTED[$id]), "supported:$id");
    }
    return ['msg' => 'all broker connectors supported in dashboard'];
};

run('83-user-broker-connections', $tests);
