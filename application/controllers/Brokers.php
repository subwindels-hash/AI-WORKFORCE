<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Phase 4/5 — Broker Center: connector health, capability status and
 * read-only MT5 account/quote views. No page here can place an order —
 * routing lives exclusively in the Execution Supervisor.
 */
class Brokers extends MY_Controller
{
    /** Honest capability matrix for connectors that are NOT implemented. */
    private const PLANNED = [
        ['id' => 'mt4-bridge', 'name' => 'MetaTrader 4', 'status' => 'PLANNED', 'detail' => 'After MT5 is verified against a real terminal'],
        ['id' => 'binance', 'name' => 'Binance', 'status' => 'PLANNED', 'detail' => 'Crypto exchanges are added one at a time after MT5'],
        ['id' => 'bybit', 'name' => 'Bybit', 'status' => 'PLANNED', 'detail' => '—'],
        ['id' => 'okx', 'name' => 'OKX', 'status' => 'PLANNED', 'detail' => '—'],
        ['id' => 'coinbase', 'name' => 'Coinbase', 'status' => 'PLANNED', 'detail' => '—'],
        ['id' => 'kraken', 'name' => 'Kraken', 'status' => 'PLANNED', 'detail' => '—'],
        ['id' => 'ib', 'name' => 'InteractiveBrokers', 'status' => 'PLANNED', 'detail' => '—'],
        ['id' => 'alpaca', 'name' => 'Alpaca', 'status' => 'PLANNED', 'detail' => '—'],
        ['id' => 'oanda', 'name' => 'OANDA', 'status' => 'PLANNED', 'detail' => '—'],
    ];

    public function index()
    {
        $data = $this->base('Broker Center');
        $data['connectors'] = $this->platform->brokers->allStatus();
        $data['planned'] = self::PLANNED;
        $data['routable'] = $this->platform->brokers->tradingConnector() !== null;
        $data['account'] = null;
        $data['quote'] = null;
        $data['quoteSymbol'] = strtoupper((string) $this->input->get('symbol'));
        $connector = $this->platform->brokers->get('mt5-bridge');
        if ($connector instanceof \Aegis\Brokers\Mt5BridgeConnector) {
            try { $data['account'] = $connector->account(); } catch (Throwable $e) { /* stays null */ }
            if ($data['quoteSymbol'] !== '') {
                try { $data['quote'] = $connector->quote($data['quoteSymbol']); } catch (Throwable $e) { /* stays null */ }
            }
        }
        $this->load->view('layout/header', $data);
        $this->load->view('brokers/index', $data);
        $this->load->view('layout/footer');
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
