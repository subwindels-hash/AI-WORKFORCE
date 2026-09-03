<?php
defined('BASEPATH') or exit('No direct script access allowed');
require_once APPPATH . 'core/App_Controller.php';

/**
 * Broker Center — connector health, capability status, and per-user broker
 * connection management. Users can connect their own trading platforms
 * (MT4/MT5, OANDA, Alpaca, IBKR, Binance/Bybit/OKX/Coinbase/Kraken) from
 * this dashboard; saved credentials are encrypted server-side and scoped
 * to the owning user.
 *
 * SAFETY
 *   - Saving a connection only enables READ access (quotes/candles/account/
 *     positions/history). A separate opt-in checkbox flips trading_enabled,
 *     which itself is gated behind the platform kill switch, risk engine,
 *     human-approval pipeline, and per-connector LIVE_ALLOWED flag.
 *   - No page here places an order directly — routing lives exclusively in
 *     the Execution Supervisor.
 */
class Brokers extends App_Controller
{
    /** Honest capability matrix for adapters that are still scaffolded and
     *  not yet end-to-end verified. */
    private const PLANNED = [
        ['id' => 'mt4-bridge', 'name' => 'MetaTrader 4', 'status' => 'VERIFYING', 'detail' => 'Contract adapter scaffolded; verify against a real MT4 bridge'],
        ['id' => 'binance', 'name' => 'Binance', 'status' => 'VERIFYING', 'detail' => 'Market data provider verified; trading adapter pending sandbox verification'],
        ['id' => 'bybit', 'name' => 'Bybit', 'status' => 'VERIFYING', 'detail' => 'Market data provider verified; trading adapter pending sandbox verification'],
        ['id' => 'okx', 'name' => 'OKX', 'status' => 'VERIFYING', 'detail' => 'Market data provider verified; trading adapter pending sandbox verification'],
        ['id' => 'coinbase', 'name' => 'Coinbase', 'status' => 'VERIFYING', 'detail' => 'Market data provider verified; trading adapter pending sandbox verification'],
        ['id' => 'kraken', 'name' => 'Kraken', 'status' => 'VERIFYING', 'detail' => 'Market data provider verified; trading adapter pending sandbox verification'],
        ['id' => 'ib', 'name' => 'InteractiveBrokers', 'status' => 'VERIFYING', 'detail' => 'Requires a local Client Portal Gateway + SSO auth'],
        ['id' => 'alpaca', 'name' => 'Alpaca', 'status' => 'VERIFYING', 'detail' => 'Keyed equities/crypto — start with a paper-trading key'],
        ['id' => 'oanda', 'name' => 'OANDA', 'status' => 'VERIFYING', 'detail' => 'Start with an fxPractice (demo) token before enabling live'],
    ];

    /** Toggle the SIMULATED MT5 bridge (offline demo only). */
    public function sim_toggle()
    {
        $markerPath = APPPATH . 'data/mt5-demo.json';
        $enable = $this->input->post('enable') === '1';
        if ($enable) {
            \AIWorkforce\Brokers\DemoBridgeConfig::enable($markerPath, (int)(getenv('AI_WORKFORCE_SIM_BRIDGE_PORT') ?: 8790));
            $this->platform->model->audit->emit('BROKER_SIMULATION_ENABLED', 'SIMULATED MT5 bridge enabled (offline demo)', [], 'user');
            $this->flash('notice', 'SIMULATED MT5 bridge enabled — every fill is clearly labeled SIMULATION.');
        } else {
            \AIWorkforce\Brokers\DemoBridgeConfig::disable($markerPath);
            $this->platform->model->audit->emit('BROKER_SIMULATION_DISABLED', 'SIMULATED MT5 bridge disabled', [], 'user');
            $this->flash('notice', 'SIMULATED MT5 bridge disabled — routing blocked again.');
        }
        redirect('/brokers');
    }

    public function index()
    {
        $data = $this->base('Broker Center');
        $data['connectors'] = $this->platform->brokers->allStatus();
        $data['planned'] = self::PLANNED;
        $data['routable'] = $this->platform->brokers->tradingConnector() !== null;
        $data['sim'] = \AIWorkforce\Brokers\DemoBridgeConfig::describe(APPPATH . 'data/mt5-demo.json');
        $data['account'] = null;
        $data['quote'] = null;
        $data['quoteSymbol'] = strtoupper((string)$this->input->get('symbol'));
        $connector = $this->platform->brokers->get('mt5-bridge');
        if ($connector instanceof \AIWorkforce\Brokers\Mt5BridgeConnector) {
            try { $data['account'] = $connector->account(); } catch (\Throwable $e) { /* stays null */ }
            if ($data['quoteSymbol'] !== '') {
                try { $data['quote'] = $connector->quote($data['quoteSymbol']); } catch (\Throwable $e) { /* stays null */ }
            }
        }
        $data['myConnections'] = $this->platform->userBrokers->listForUser((int)$this->identity['id']);
        $data['supportedBrokers'] = \AIWorkforce\Brokers\UserBrokerConnections::SUPPORTED;
        $this->load->view('layout/header', $data);
        $this->load->view('brokers/index', $data);
        $this->load->view('layout/footer');
    }

    /* ------- per-user connection management ------- */

    public function connect($broker = null)
    {
        $broker = strtolower(trim((string)$broker));
        $supported = \AIWorkforce\Brokers\UserBrokerConnections::SUPPORTED;
        if ($broker === '' || !isset($supported[$broker])) {
            $this->flash('error', 'Unknown broker.');
            redirect('/brokers');
            return;
        }
        $data = $this->base('Connect ' . $supported[$broker]['label']);
        $data['brokerId'] = $broker;
        $data['broker'] = $supported[$broker];
        $data['connection'] = $this->platform->userBrokers->findForUser((int)$this->identity['id'], $broker);
        $this->load->view('layout/header', $data);
        $this->load->view('brokers/connect', $data);
        $this->load->view('layout/footer');
    }

    public function save($broker = null)
    {
        if (strtoupper((string)$this->input->server('REQUEST_METHOD')) !== 'POST') {
            show_404();
            return;
        }
        $broker = strtolower(trim((string)$broker));
        $supported = \AIWorkforce\Brokers\UserBrokerConnections::SUPPORTED;
        if (!isset($supported[$broker])) {
            $this->flash('error', 'Unknown broker.');
            redirect('/brokers');
            return;
        }
        try {
            $input = [
                'label'       => $this->input->post('label'),
                'base_url'    => $this->input->post('base_url') ?: $supported[$broker]['defaultUrl'],
                'extra_url'   => $this->input->post('extra_url'),
                'account_hint'=> $this->input->post('account_hint'),
                'enabled'     => $this->input->post('enabled') === '1',
                'trading_enabled' => $this->input->post('trading_enabled') === '1',
                'live_allowed' => $this->input->post('live_allowed') === '1',
            ];
            $token = $this->input->post('token');
            if ($token !== false && $token !== null) $input['token'] = (string)$token;
            $saved = $this->platform->userBrokers->save((int)$this->identity['id'], $broker, $input);
            $this->platform->model->audit->emit(
                'BROKER_CONNECTION_SAVED',
                "User saved {$broker} connection (enabled=".($saved['enabled']?'1':'0').", trading=".($saved['trading_enabled']?'1':'0').", live=".($saved['live_allowed']?'1':'0').")",
                ['broker' => $broker, 'label' => $saved['label']],
                'user'
            );
            // Run a test immediately on save so the user sees status.
            try {
                $status = $this->testConnection($broker, false);
                $msg = ($status['ok'] ? 'Connected — ' : 'Saved but test failed: ') . ($status['message'] ?? '');
                $this->flash('notice', $msg);
            } catch (\Throwable $e) {
                $this->flash('notice', 'Saved. Use "Test connection" to verify.');
            }
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
            redirect('/brokers/connect/' . $broker);
            return;
        } catch (\Throwable $e) {
            log_message('error', 'broker save failed: ' . $e->getMessage());
            $this->flash('error', 'Could not save connection: ' . $e->getMessage());
            redirect('/brokers/connect/' . $broker);
            return;
        }
        redirect('/brokers');
    }

    public function disconnect($broker = null)
    {
        $broker = strtolower(trim((string)$broker));
        if (strtoupper((string)$this->input->server('REQUEST_METHOD')) !== 'POST'
            || !isset(\AIWorkforce\Brokers\UserBrokerConnections::SUPPORTED[$broker])) {
            show_404();
            return;
        }
        $this->platform->userBrokers->delete((int)$this->identity['id'], $broker);
        $this->platform->model->audit->emit('BROKER_CONNECTION_DELETED', "User deleted {$broker} connection", ['broker' => $broker], 'user');
        $this->flash('notice', ucfirst($broker) . ' connection removed.');
        redirect('/brokers');
    }

    public function test($broker = null)
    {
        $broker = strtolower(trim((string)$broker));
        if (!isset(\AIWorkforce\Brokers\UserBrokerConnections::SUPPORTED[$broker])) {
            $this->flash('error', 'Unknown broker.');
            redirect('/brokers');
            return;
        }
        $res = $this->testConnection($broker, true);
        $this->flash($res['ok'] ? 'notice' : 'error', ($res['ok'] ? 'Connected — ' : 'Test failed — ') . ($res['message'] ?? ''));
        redirect('/brokers');
    }

    private function testConnection(string $broker, bool $record): array
    {
        $this->load->database();
        $row = $this->db->get_where('user_broker_connections',
                    ['user_id' => (int)$this->identity['id'], 'broker' => $broker], 1)->row_array();
        if (!$row) {
            $msg = 'No saved connection for ' . $broker;
            if ($record) $this->platform->userBrokers->recordTestResult((int)$this->identity['id'], $broker, false, $msg);
            return ['ok' => false, 'message' => $msg];
        }
        $connector = $this->platform->userBrokers->buildConnector($row);
        if (!$connector) {
            $msg = 'Connection disabled; enable it first.';
            if ($record) $this->platform->userBrokers->recordTestResult((int)$this->identity['id'], $broker, false, $msg);
            return ['ok' => false, 'message' => $msg];
        }
        try {
            $status = $connector->status();
            $ok = ($status['state'] ?? '') === 'READY';
            $msg = $status['message'] ?? ($ok ? 'adapter reachable' : 'adapter not reachable');
            if ($record) $this->platform->userBrokers->recordTestResult((int)$this->identity['id'], $broker, $ok, $msg);
            // Try to pull an account snapshot for the success message.
            $accountInfo = '';
            try {
                if ($ok && method_exists($connector, 'account')) {
                    $acct = $connector->account();
                    $accountInfo = ' account=' . ($acct['accountId'] ?? '?')
                                 . ' balance=' . ($acct['balance'] ?? '?')
                                 . ' ' . ($acct['currency'] ?? '');
                }
            } catch (\Throwable $_) { /* account info is optional */ }
            return ['ok' => $ok, 'message' => $msg . $accountInfo];
        } catch (\Throwable $e) {
            if ($record) $this->platform->userBrokers->recordTestResult((int)$this->identity['id'], $broker, false, $e->getMessage());
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    private function base(string $title): array
    {
        $state = $this->platform->state();
        return [
            'title' => $title, 'active' => 'brokers',
            'status' => ['tradingMode' => $state['tradingMode'], 'killSwitch' => $state['killSwitch'],
                'providers' => $this->platform->providers->getAllHealth()],
            'notice' => $this->flashGet('notice'), 'error' => $this->flashGet('error'),
        ];
    }

    private function flash(string $key, string $msg): void
    {
        $this->session->set_flashdata($key, $msg);
    }
    private function flashGet(string $key): ?string
    {
        $msg = $this->session->flashdata($key);
        return is_string($msg) && $msg !== '' ? $msg : null;
    }
}
