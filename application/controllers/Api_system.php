<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * System / governance API: status, features matrix, events, risk limits,
 * kill switch, trading mode.
 */
class Api_system extends Api_controller
{
    public const FEATURES = [
        // market data
        ['name' => 'Market-data abstraction (health/retry/timeout/breaker/cache/fallback)', 'category' => 'market-data', 'status' => 'TESTED', 'detail' => 'Provider interface with full provenance; synthetic never silent'],
        ['name' => 'Binance market data (crypto)', 'category' => 'market-data', 'status' => 'IMPLEMENTED', 'detail' => 'Real public REST klines/quotes; reports DOWN and falls back when host has no egress'],
        ['name' => 'Frankfurter/ECB (forex daily)', 'category' => 'market-data', 'status' => 'IMPLEMENTED', 'detail' => 'Real ECB daily reference rates; serves 1d only'],
        ['name' => 'Synthetic demo provider', 'category' => 'market-data', 'status' => 'TESTED', 'detail' => 'Deterministic generator, always labeled SIMULATION'],
        ['name' => 'Stock/ETF/futures/options data providers', 'category' => 'market-data', 'status' => 'PLANNED', 'detail' => 'Phase 6 — require licensed feeds'],
        // agents
        ['name' => 'Technical Analysis Agent', 'category' => 'agent', 'status' => 'TESTED', 'detail' => 'Full indicator suite ported with fixture tests'],
        ['name' => 'Market Structure Agent', 'category' => 'agent', 'status' => 'TESTED', 'detail' => 'Swings, close-confirmed BOS/CHoCH, zones, order blocks, FVGs'],
        ['name' => 'Forex / Crypto / Sentiment agents', 'category' => 'agent', 'status' => 'TESTED', 'detail' => 'Honest unavailability for macro/on-chain/sentiment data'],
        ['name' => 'Trading Intelligence consensus', 'category' => 'agent', 'status' => 'TESTED', 'detail' => 'Confluence, confidence, conflicts, NO_TRADE gates'],
        // engines
        ['name' => 'Trading Intelligence Engine + Risk Engine', 'category' => 'engine', 'status' => 'TESTED', 'detail' => 'Full pipeline with independent risk veto and kill-switch participation'],
        ['name' => 'Strategy Engine + lifecycle', 'category' => 'engine', 'status' => 'TESTED', 'detail' => '4 built-ins, evidence-gated lifecycle through PAPER_TRADING (Phase 3)'],
        ['name' => 'Backtesting Engine', 'category' => 'engine', 'status' => 'TESTED', 'detail' => 'Next-bar-open fills, cost model, pessimistic stop rule, look-ahead guard'],
        ['name' => 'Paper Trading Engine', 'category' => 'engine', 'status' => 'TESTED', 'detail' => 'Phase 3: simulated accounts/orders/fills with full governance chain, strategy deployments, journaling'],
        ['name' => 'Trade Execution Supervisor', 'category' => 'engine', 'status' => 'PLANNED', 'detail' => 'Phase 5 — 15-step pipeline'],
        ['name' => 'Kill switch', 'category' => 'engine', 'status' => 'TESTED', 'detail' => 'Ships ACTIVE in DB state; blocks all order placement (paper included)'],
        // platform
        ['name' => 'MySQL / MariaDB persistence', 'category' => 'module', 'status' => 'IMPLEMENTED', 'detail' => 'Canonical schema + mysqli config (application/database/schema.mysql.sql); the offline sandbox verifies the identical app+SQL through pdo_sqlite'],
        ['name' => 'SQLite dev driver', 'category' => 'module', 'status' => 'TESTED', 'detail' => 'Same CI3 app on pdo_sqlite for offline demo/tests (AEGIS_DB_DRIVER=pdo_sqlite)'],
        ['name' => 'CodeIgniter 3.1.13 MVC', 'category' => 'module', 'status' => 'TESTED', 'detail' => 'Traditional server-rendered MVC + JSON API layer'],
        ['name' => 'ANALYSIS_ONLY mode', 'category' => 'mode', 'status' => 'TESTED', 'detail' => 'Default'],
        ['name' => 'PAPER_TRADING mode', 'category' => 'mode', 'status' => 'TESTED', 'detail' => 'Phase 3 — simulated execution with real prices when reachable'],
        ['name' => 'HUMAN_APPROVAL / SEMI_AUTONOMOUS / FULLY_AUTOMATED', 'category' => 'mode', 'status' => 'PLANNED', 'detail' => 'Phase 5, gated on brokers + execution supervisor'],
        // brokers
        ['name' => 'MT5 / MT4 / crypto exchange / stock broker connectors', 'category' => 'broker', 'status' => 'PLANNED', 'detail' => 'Phase 4 — MT5 first (Python bridge); none implemented, none claimed'],
    ];

    public function status()
    {
        $state = $this->platform->state();
        $this->json([
            'platform' => 'AEGIS Trading Intelligence (CodeIgniter 3 / PHP MVC edition)',
            'phase' => 3,
            'version' => '0.3.0',
            'stack' => 'CodeIgniter ' . CI_VERSION . ' / PHP ' . PHP_VERSION . ' / ' . $this->db->platform(),
            'tradingMode' => $state['tradingMode'],
            'implementedTradingModes' => ['ANALYSIS_ONLY', 'PAPER_TRADING'],
            'supportedTradingModes' => ['ANALYSIS_ONLY', 'PAPER_TRADING', 'HUMAN_APPROVAL', 'SEMI_AUTONOMOUS', 'FULLY_AUTOMATED'],
            'killSwitch' => $state['killSwitch'],
            'providers' => $this->platform->providers->getAllHealth(),
        ]);
    }

    public function features()
    {
        $this->json(self::FEATURES);
    }

    public function events(int $limit = 100)
    {
        $this->json(['events' => $this->platform->model->audit->recent(min($limit, 500))]);
    }

    public function risk_limits()
    {
        $this->json(['limits' => $this->platform->risk->getLimits()]);
    }

    public function update_risk_limits()
    {
        $body = $this->jsonBody();
        $allowed = ['riskPerTradePct', 'maxRiskPerTradePct', 'minRiskReward', 'maxPositionNotionalUsd', 'maxLeverage',
            'maxOpenPositions', 'maxDailyLossPct', 'maxWeeklyLossPct', 'maxDrawdownPct', 'maxSymbolExposurePct',
            'maxPortfolioExposurePct', 'minDataQuality', 'blockSyntheticData', 'blockStaleData'];
        $patch = array_intersect_key($body, array_flip($allowed));
        if (!$patch) return $this->jsonError('no valid limit fields supplied');
        $this->json(['limits' => $this->platform->updateRiskLimits($patch)]);
    }

    public function kill_switch()
    {
        $body = $this->jsonBody();
        if (!isset($body['active']) || !is_bool($body['active'])) {
            return $this->jsonError('body must be {active: boolean, reason?: string}');
        }
        $ks = $this->platform->setKillSwitch($body['active'], $body['reason'] ?? null);
        $this->json(['killSwitch' => $ks]);
    }

    public function synthetic_paper()
    {
        $body = $this->jsonBody();
        if (!isset($body['allow']) || !is_bool($body['allow'])) {
            return $this->jsonError('body must be {allow: boolean}');
        }
        $state = $this->platform->state();
        $state['allowSyntheticPaperData'] = $body['allow'];
        $this->platform->model->state->save($state);
        $this->platform->model->audit->emit('RISK_LIMITS_UPDATED',
            'Paper-trading synthetic prices ' . ($body['allow'] ? 'ALLOWED (dev)' : 'BLOCKED'),
            ['allow' => $body['allow']], 'user');
        $this->json(['allowSyntheticPaperData' => $body['allow']]);
    }

    public function trading_mode()
    {
        $body = $this->jsonBody();
        $mode = $body['mode'] ?? '';
        $result = $this->platform->setTradingMode($mode);
        if (!$result['ok']) return $this->jsonError($result['message'], 409);
        $this->json(['tradingMode' => $result['state']['tradingMode'], 'message' => $result['message']]);
    }
}
