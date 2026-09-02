<?php
defined('BASEPATH') or exit('No direct script access allowed');
require_once APPPATH . 'core/App_Controller.php';

class Welcome extends App_Controller
{
    public function index()
    {
        $state = $this->platform->state();
        $data = [
            'title' => 'AI Workforce',
            'active' => 'dashboard',
            'status' => $this->statusView(),
            'symbols' => $this->symbols(),
            'timeframes' => ['15m', '1h', '4h', '1d'],
            'symbol' => strtoupper((string)($this->input->post_get('symbol') ?: 'BTCUSDT')),
            'timeframe' => (string)($this->input->post_get('timeframe') ?: '1h'),
            'run' => null,
            'watch' => [],
            'error' => null,
            'events' => $this->platform->model->audit->recent(12),
            'notice' => $this->session->flashdata('notice'),
            'modeError' => $this->session->flashdata('modeError'),
            'history' => $this->platform->model->analysis->history(8),
            'chart' => null,
            'chartError' => null,
            'analysisChartRendered' => false,
            'marketState' => $this->marketStateView(),
        ];

        if ($this->input->post_get('symbol') !== null) {
            $marketClass = $this->platform->paper->inferMarketClass($data['symbol']);
            try {
                $data['run'] = $this->platform->engine->run($data['symbol'], $marketClass, $data['timeframe']);
                // chart candles from the same provenance for visual honesty
                $series = $this->platform->providers->getCandleSeries($data['symbol'], $marketClass, $data['timeframe'], 200);
                $data['candles'] = $series['candles'];
                $data['chart'] = ['candles' => $series['candles'], 'prov' => $series['provenance']];
                $data['analysisChartRendered'] = true;
            } catch (Throwable $e) {
                $data['error'] = $e->getMessage();
            }
        }

        // The live chart renders on load — no analysis run required — so market
        // data is visibly LIVE as soon as a provider is connected. Reuses the
        // per-request candle cache when the analysis above already fetched this
        // symbol, and never fabricates: if no provider can serve it, the panel
        // says so instead of drawing invented bars.
        if ($data['chart'] === null) {
            try {
                $chartClass = $this->platform->paper->inferMarketClass($data['symbol']);
                $series = $this->platform->providers->getCandleSeries($data['symbol'], $chartClass, $data['timeframe'], 200);
                $data['chart'] = [
                    'candles' => $series['candles'],
                    'prov' => $series['provenance'],
                    'symbol' => $series['symbol'],
                    'timeframe' => $series['timeframe'],
                    'marketClass' => $series['marketClass'],
                ];
            } catch (Throwable $e) {
                $data['chartError'] = $e->getMessage();
            }
        }
        try {
            $data['watch'] = $this->platform->engine->consensus(array_map(fn($s) => [
                'symbol' => $s,
                'marketClass' => $this->platform->paper->inferMarketClass($s),
                'timeframe' => $data['timeframe'],
            ], ['EURUSD', 'GBPUSD', 'XAUUSD', 'BTCUSDT', 'ETHUSDT', 'AAPL']));
        } catch (Throwable $e) { /* watchlist optional */ }

        $this->load->view('layout/header', $data);
        $this->load->view('welcome/index', $data);
        $this->load->view('layout/footer');
    }

    public function kill_switch()
    {
        $active = $this->input->post('active') === '1';
        $this->platform->setKillSwitch($active, $active ? 'engaged from dashboard' : 'released from dashboard');
        redirect('/analysis');
    }

    public function mode()
    {
        $mode = (string)$this->input->post('mode');
        $result = $this->platform->setTradingMode($mode);
        $this->session->set_flashdata($result['ok'] ? 'notice' : 'modeError', $result['message']);
        redirect('/analysis');
    }

    private function statusView(): array
    {
        $state = $this->platform->state();
        return [
            'tradingMode' => $state['tradingMode'],
            'killSwitch' => $state['killSwitch'],
            'providers' => $this->platform->providers->getAllHealth(),
        ];
    }

    /**
     * Per-service configured-vs-live state for the market-data strip.
     * serviceState() is defensive, so this degrades to NOT CONNECTED rather
     * than erroring when the api_providers schema has not been created yet.
     */
    private function marketStateView(): array
    {
        $out = [];
        $db = $this->AIWorkforce_model->db;
        foreach (\AIWorkforce\ApiProviders::MARKET_DATA_SERVICES as $service) {
            $out[$service] = \AIWorkforce\ApiProviders::serviceState($db, $service);
        }
        return $out;
    }

    private function symbols(): array
    {
        return [
            'Crypto' => ['BTCUSDT', 'ETHUSDT', 'SOLUSDT', 'BNBUSDT', 'XRPUSDT'],
            'Forex & Metals' => ['EURUSD', 'GBPUSD', 'USDJPY', 'AUDUSD', 'XAUUSD'],
            'Stocks (delayed)' => ['AAPL', 'MSFT', 'GOOGL', 'AMZN', 'NVDA', 'META', 'TSLA', 'JPM'],
            'ETFs (delayed)' => ['SPY', 'QQQ', 'IWM', 'DIA', 'VTI', 'GLD'],
            'Futures (delayed)' => ['ES=F', 'NQ=F', 'CL=F', 'GC=F'],
        ];
    }
}
