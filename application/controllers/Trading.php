<?php
defined('BASEPATH') or exit('No direct script access allowed');
require_once APPPATH . 'core/App_Controller.php';

/**
 * User-facing trading workspace — comprehensive 6-tab interface.
 *
 * Tabs: Overview (chart + signals) | Trade | Positions | Accounts | AI Analysis | Performance & Risk
 *
 * Components delivered:
 *   1. TradingSignals  — AI signals with confidence, risk, one-click trade
 *   2. TradingChart    — Interactive SVG candlestick chart with timeframes
 *   3. RiskManagement  — Alerts, limits, kill switch, autonomous controls
 *   4. TradingPerformance — Win rate, profit factor, equity curve, journal
 */
class Trading extends App_Controller
{
    public function index()
    {
        $data = $this->base('My Trading');
        $userId = (int) $this->identity['id'];
        $connections = $this->platform->userBrokers->listForUser($userId);

        // ── Accounts (portfolio) ──────────────────────────────────────
        $accounts = [];
        $positions = [];
        $totalEquity = 0;
        $totalUnrealizedPnl = 0;
        $brokerStatus = [];
        foreach ($connections as $row) {
            $info = ['broker' => $row['broker'], 'label' => $row['label'] ?? '', 'enabled' => !empty($row['enabled']), 'trading' => !empty($row['trading_enabled'])];
            if (empty($row['enabled'])) { $info['status'] = 'disabled'; $brokerStatus[] = $info; continue; }
            try {
                $c = $this->platform->userBrokers->buildConnector($row);
                if (!$c) { $info['status'] = 'unavailable'; $brokerStatus[] = $info; continue; }
                $status = $c->status();
                $info['status'] = ($status['state'] ?? 'UNKNOWN');
                $info['latencyMs'] = $status['latencyMs'] ?? null;
                $a = $c->account();
                $a['broker'] = $row['broker']; $a['label'] = $row['label']; $a['connectionId'] = $row['id'] ?? null;
                $accounts[] = $a;
                $totalEquity += (float) ($a['equity'] ?? $a['balance'] ?? 0);
                if (method_exists($c, 'positions')) {
                    foreach ((array) $c->positions() as $p) {
                        $p['broker'] = $row['broker']; $p['label'] = $row['label'];
                        $positions[] = $p;
                        $totalUnrealizedPnl += (float) ($p['unrealizedPnl'] ?? $p['pnl'] ?? 0);
                    }
                }
            } catch (\Throwable $e) {
                $info['status'] = 'error'; $info['error'] = mb_substr($e->getMessage(), 0, 120);
                $accounts[] = ['broker' => $row['broker'], 'label' => $row['label'], 'error' => 'Unavailable'];
            }
            $brokerStatus[] = $info;
        }

        $executions = $this->platform->execution->executions(20);
        $proposals = $this->platform->execution->proposals(null, 20);
        $state = $this->platform->state();
        $riskLimits = \AIWorkforce\ExecutionSupervisor::automationLimits($state);
        $analysis = $this->platform->providers->getAllHealth();
        $history = $this->platform->model->analysis->history(10);

        // ── Performance (Journal Analytics) ───────────────────────────
        $journalEntries = [];
        $perfSummary = ['overall' => ['closedTrades' => 0, 'winRate' => null, 'profitFactor' => null, 'totalPnl' => 0, 'avgRMultiple' => null, 'expectancyPnl' => null, 'maxDrawdownAbs' => 0], 'groups' => []];
        $calibration = ['sufficientData' => false, 'verdict' => 'No data yet', 'buckets' => []];
        try {
            $journalEntries = $this->platform->model->journal->list([], 200);
            $perfSummary = \AIWorkforce\Journal\Analytics::analyze($journalEntries, 'symbol');
            $calibration = \AIWorkforce\Journal\Analytics::calibration($journalEntries);
        } catch (\Throwable $e) { /* graceful */ }

        // ── Risk monitor ──────────────────────────────────────────────
        $riskAlerts = [];
        try { $riskReport = $this->platform->monitor->scan(); $riskAlerts = $riskReport['alerts'] ?? []; } catch (\Throwable $e) { /* graceful */ }

        // ── Counters ──────────────────────────────────────────────────
        $openPositions = count($positions);
        $todayExecutions = count(array_filter($executions, fn($x) => substr((string)($x['created_at'] ?? $x['createdAt'] ?? ''), 0, 10) === gmdate('Y-m-d')));
        $approvedProposals = count(array_filter($proposals, fn($p) => ($p['status'] ?? '') === 'APPROVED'));
        $rejectedProposals = count(array_filter($proposals, fn($p) => ($p['status'] ?? '') === 'REJECTED'));
        $pendingProposals = count(array_filter($proposals, fn($p) => in_array($p['status'] ?? '', ['PENDING', 'PENDING_APPROVAL'], true)));

        $data = array_merge($data, [
            'connections' => $connections, 'brokerStatus' => $brokerStatus,
            'accounts' => $accounts, 'positions' => $positions,
            'totalEquity' => $totalEquity, 'totalUnrealizedPnl' => $totalUnrealizedPnl,
            'executions' => $executions, 'proposals' => $proposals,
            'riskLimits' => $riskLimits, 'analysis' => $analysis, 'history' => $history,
            'openPositions' => $openPositions, 'todayExecutions' => $todayExecutions,
            'approvedProposals' => $approvedProposals, 'rejectedProposals' => $rejectedProposals,
            'pendingProposals' => $pendingProposals, 'tradingMode' => $state['tradingMode'],
            'killSwitchActive' => !empty($state['killSwitch']['active']),
            'automationLimits' => $riskLimits,
            'supportedBrokers' => \AIWorkforce\Brokers\UserBrokerConnections::SUPPORTED,
            'journalEntries' => $journalEntries, 'perfSummary' => $perfSummary,
            'calibration' => $calibration, 'riskAlerts' => $riskAlerts,
        ]);

        $this->load->view('layout/header', $data);
        $this->load->view('trading/index', $data);
        $this->load->view('layout/footer');
    }

    // ─── API: AI Trading Signals ──────────────────────────────────────
    public function signals()
    {
        $history = $this->platform->model->analysis->history(30);
        $state = $this->platform->state();
        $killSwitch = !empty($state['killSwitch']['active']);
        $signals = [];
        foreach ($history as $h) {
            $bias = strtolower($h['bias'] ?? 'neutral');
            $dir = in_array($bias, ['bullish', 'long', 'buy']) ? 'BUY' : (in_array($bias, ['bearish', 'short', 'sell']) ? 'SELL' : 'NEUTRAL');
            $conf = (float) ($h['confidence'] ?? 0.5);
            $level = $conf >= 0.75 ? 'HIGH' : ($conf >= 0.5 ? 'MEDIUM' : 'LOW');
            $setup = is_array($h['setup'] ?? null) ? $h['setup'] : [];
            $rr = 0;
            if (!empty($setup['entry']) && !empty($setup['stopLoss']) && !empty($setup['takeProfit'])) {
                $risk = abs((float) $setup['entry'] - (float) $setup['stopLoss']);
                $reward = abs((is_array($setup['takeProfit']) ? $setup['takeProfit'][0] : $setup['takeProfit']) - (float) $setup['entry']);
                $rr = $risk > 0 ? round($reward / $risk, 2) : 0;
            }
            // Risk check: does it pass basic gates?
            $riskChecks = ['killSwitch' => !$killSwitch, 'hasStopLoss' => !empty($setup['stopLoss']), 'confidenceOk' => $conf >= 0.5, 'rrPositive' => $rr > 0];
            $riskPass = !in_array(false, $riskChecks, true);
            $signals[] = [
                'id' => $h['id'] ?? md5(json_encode($h)),
                'symbol' => $h['symbol'] ?? 'UNKNOWN',
                'direction' => $dir,
                'bias' => ucfirst($h['bias'] ?? 'Neutral'),
                'timeframe' => $h['timeframe'] ?? '1h',
                'confidence' => $conf,
                'confidenceLevel' => $level,
                'entry' => $setup['entry'] ?? null,
                'stopLoss' => $setup['stopLoss'] ?? null,
                'takeProfit' => $setup['takeProfit'] ?? null,
                'riskReward' => $rr,
                'riskPass' => $riskPass,
                'riskChecks' => $riskChecks,
                'reasoning' => $h['reasoning'] ?? $h['summary'] ?? '',
                'status' => 'ACTIVE',
                'createdAt' => $h['completed_at'] ?? gmdate('c'),
            ];
        }
        $this->json(['signals' => $signals, 'total' => count($signals), 'killSwitch' => $killSwitch, 'generatedAt' => gmdate('c')]);
    }

    // ─── API: Chart data ──────────────────────────────────────────────
    public function chart_data()
    {
        $symbol = strtoupper((string) ($this->input->get('symbol') ?: 'EURUSD'));
        $timeframe = (string) ($this->input->get('timeframe') ?: '1h');
        $limit = min(200, max(20, (int) ($this->input->get('limit') ?: 100)));
        try {
            $series = $this->platform->providers->getCandleSeries($symbol, $this->inferMarketClass($symbol), $timeframe, $limit);
            $candles = $series['candles'] ?? [];
            $provenance = $series['provenance'] ?? [];
            $this->json([
                'symbol' => $symbol, 'timeframe' => $timeframe,
                'candles' => array_map(fn($c) => ['t' => (int) $c['timestamp'], 'o' => (float) $c['open'], 'h' => (float) $c['high'], 'l' => (float) $c['low'], 'c' => (float) $c['close'], 'v' => (float) ($c['volume'] ?? 0)], $candles),
                'live' => !empty($provenance['live']),
                'synthetic' => !empty($provenance['synthetic']),
                'delayed' => !empty($provenance['delayed']),
                'source' => $provenance['source'] ?? 'unknown',
                'quote' => !empty($candles) ? ['o' => (float) end($candles)['open'], 'h' => (float) end($candles)['high'], 'l' => (float) end($candles)['low'], 'c' => (float) end($candles)['close']] : null,
            ]);
        } catch (\Throwable $e) {
            $this->json(['symbol' => $symbol, 'timeframe' => $timeframe, 'candles' => [], 'error' => $e->getMessage()], 200);
        }
    }

    // ─── API: Risk Dashboard ──────────────────────────────────────────
    public function risk_dashboard()
    {
        $state = $this->platform->state();
        $limits = \AIWorkforce\ExecutionSupervisor::automationLimits($state);
        $alerts = [];
        try { $riskReport = $this->platform->monitor->scan(); $alerts = $riskReport['alerts'] ?? []; } catch (\Throwable $e) { /* graceful */ }
        // Portfolio concentration from open positions
        $userId = (int) $this->identity['id'];
        $concentrations = [];
        try {
            $connections = $this->platform->userBrokers->listForUser($userId);
            foreach ($connections as $row) {
                if (empty($row['enabled'])) continue;
                try {
                    $c = $this->platform->userBrokers->buildConnector($row);
                    if ($c && method_exists($c, 'positions')) {
                        foreach ((array) $c->positions() as $p) {
                            $sym = $p['symbol'] ?? 'UNKNOWN';
                            $concentrations[$sym] = ($concentrations[$sym] ?? 0) + (float) ($p['notional'] ?? $p['volume'] ?? 1);
                        }
                    }
                } catch (\Throwable $e) { /* skip */ }
            }
        } catch (\Throwable $e) { /* graceful */ }
        arsort($concentrations);
        $this->json([
            'killSwitch' => !empty($state['killSwitch']['active']),
            'tradingMode' => $state['tradingMode'],
            'automationLimits' => $limits,
            'alerts' => $alerts,
            'alertCount' => count($alerts),
            'concentrations' => $concentrations,
            'maxExposure' => $limits['maxTradeNotionalUsd'] ?? null,
            'maxDailyTrades' => $limits['maxDailyTrades'] ?? null,
            'maxRiskPct' => isset($limits['maxRiskPerTradePct']) ? round($limits['maxRiskPerTradePct'] * 100, 2) : null,
            'approvedSymbols' => $limits['approvedSymbols'] ?? [],
        ]);
    }

    // ─── API: Performance Dashboard ───────────────────────────────────
    public function performance()
    {
        $groupBy = (string) ($this->input->get('groupBy') ?: 'symbol');
        try {
            $entries = $this->platform->model->journal->list([], 500);
            $summary = \AIWorkforce\Journal\Analytics::analyze($entries, $groupBy);
            $calibration = \AIWorkforce\Journal\Analytics::calibration($entries);
            // Streaks
            $streaks = $this->computeStreaks($entries);
            // Symbol breakdown
            $bySymbol = \AIWorkforce\Journal\Analytics::analyze($entries, 'symbol');
            // Strategy breakdown
            $byStrategy = \AIWorkforce\Journal\Analytics::analyze($entries, 'strategy');
            $this->json([
                'overall' => $summary['overall'] ?? [],
                'bySymbol' => $bySymbol['groups'] ?? [],
                'byStrategy' => $byStrategy['groups'] ?? [],
                'calibration' => $calibration,
                'streaks' => $streaks,
                'entries' => array_slice($entries, 0, 50),
                'totalEntries' => count($entries),
            ]);
        } catch (\Throwable $e) {
            $this->json(['overall' => ['closedTrades' => 0], 'error' => $e->getMessage()]);
        }
    }

    // ─── API: Kill switch control ─────────────────────────────────────
    public function toggle_kill_switch()
    {
        if (strtoupper($this->input->server('REQUEST_METHOD')) !== 'POST') { show_404(); return; }
        $body = $this->jsonBody() ?: [];
        $active = !empty($body['active']);
        $reason = (string) ($body['reason'] ?? ($active ? 'Engaged from My Trading' : 'Released from My Trading'));
        $result = $this->platform->setKillSwitch($active, $reason);
        $this->json(['ok' => true, 'killSwitch' => $result]);
    }

    // ─── Existing endpoints ───────────────────────────────────────────
    public function submit_order()
    {
        if (strtoupper($this->input->server('REQUEST_METHOD')) !== 'POST') { show_404(); return; }
        try {
            $input = $this->jsonBody() ?: [
                'symbol' => $this->input->post('symbol'), 'side' => strtoupper((string) $this->input->post('side')),
                'volume' => (float) $this->input->post('volume'),
                'stopLoss' => $this->input->post('stopLoss') ? (float) $this->input->post('stopLoss') : null,
                'takeProfit' => $this->input->post('takeProfit') ? array_map('floatval', (array) $this->input->post('takeProfit')) : null,
                'confidence' => (float) ($this->input->post('confidence') ?: 0.5),
                'reason' => (string) ($this->input->post('reason') ?: 'Manual trade from My Trading'),
                'broker' => $this->input->post('broker'),
            ];
            $state = $this->platform->state();
            if (!empty($state['killSwitch']['active'])) { $this->jsonError('Kill switch is active — all order placement is blocked.', 409); return; }
            $result = $this->platform->execution->propose([
                'symbol' => strtoupper((string) ($input['symbol'] ?? '')), 'side' => $input['side'] ?? 'BUY',
                'volume' => max(0.01, (float) ($input['volume'] ?? 0.01)),
                'stopLoss' => $input['stopLoss'] ?? null, 'takeProfit' => $input['takeProfit'] ?? null,
                'confidence' => min(1, max(0, (float) ($input['confidence'] ?? 0.5))),
                'reason' => $input['reason'] ?? 'Manual trade', 'userId' => (int) $this->identity['id'],
            ]);
            $this->platform->model->audit->emit('TRADE_PROPOSAL_SUBMITTED', "User submitted trade: {$input['side']} {$input['volume']} " . ($input['symbol'] ?? ''), ['symbol' => $input['symbol'] ?? '', 'side' => $input['side']], 'user');
            $this->json(['ok' => true, 'proposal' => $result, 'message' => 'Trade proposal submitted — awaiting risk review and approval.']);
        } catch (\Throwable $e) { $this->jsonError($e->getMessage(), 422); }
    }

    public function close_position()
    {
        if (strtoupper($this->input->server('REQUEST_METHOD')) !== 'POST') { show_404(); return; }
        $broker = strtolower((string) ($this->input->post('broker') ?? $this->jsonBody()['broker'] ?? ''));
        $positionId = (string) ($this->input->post('positionId') ?? $this->jsonBody()['positionId'] ?? '');
        if ($broker === '' || $positionId === '') { $this->jsonError('broker and positionId are required', 422); return; }
        try {
            $connection = $this->platform->userBrokers->findForUser((int) $this->identity['id'], $broker);
            if (!$connection || empty($connection['enabled'])) { $this->jsonError('Broker connection not found or disabled', 404); return; }
            $c = $this->platform->userBrokers->buildConnector($connection);
            if (!$c) { $this->jsonError('Broker connector unavailable', 503); return; }
            $result = method_exists($c, 'closePosition') ? $c->closePosition($positionId) : ['status' => 'NOT_SUPPORTED', 'message' => 'Connector does not support closing'];
            $this->platform->model->audit->emit('POSITION_CLOSE_REQUESTED', "User requested close of position {$positionId} on {$broker}", ['broker' => $broker, 'positionId' => $positionId], 'user');
            $this->json(['ok' => true, 'result' => $result]);
        } catch (\Throwable $e) { $this->jsonError($e->getMessage(), 422); }
    }

    public function widget_data()
    {
        $userId = (int) $this->identity['id'];
        $connections = $this->platform->userBrokers->listForUser($userId);
        $totalEquity = 0; $totalPnl = 0; $posCount = 0; $brokerCards = [];
        foreach ($connections as $row) {
            $card = ['broker' => $row['broker'], 'label' => $row['label'] ?? '', 'connected' => !empty($row['enabled'])];
            if (!empty($row['enabled'])) {
                try {
                    $c = $this->platform->userBrokers->buildConnector($row);
                    if ($c) {
                        $a = $c->account(); $totalEquity += (float) ($a['equity'] ?? $a['balance'] ?? 0);
                        $card['equity'] = $a['equity'] ?? $a['balance'] ?? null; $card['currency'] = $a['currency'] ?? '';
                        if (method_exists($c, 'positions')) {
                            $pos = $c->positions(); $posCount += count((array) $pos);
                            foreach ((array) $pos as $p) $totalPnl += (float) ($p['unrealizedPnl'] ?? $p['pnl'] ?? 0);
                        }
                    }
                } catch (\Throwable $e) { $card['error'] = 'unavailable'; }
            }
            $brokerCards[] = $card;
        }
        $state = $this->platform->state();
        $this->json(['totalEquity' => $totalEquity, 'totalPnl' => $totalPnl, 'openPositions' => $posCount, 'connectedBrokers' => count(array_filter($brokerCards, fn($b) => $b['connected'])), 'totalBrokers' => count($brokerCards), 'brokers' => $brokerCards, 'mode' => $state['tradingMode'], 'killSwitch' => !empty($state['killSwitch']['active'])]);
    }

    // ─── Helpers ──────────────────────────────────────────────────────
    private function computeStreaks(array $entries): array
    {
        $current = 0; $best = 0; $worst = 0; $curType = null;
        foreach ($entries as $e) {
            $pnl = (float) ($e['pnl'] ?? 0);
            $type = $pnl >= 0 ? 'W' : 'L';
            if ($type === $curType) { $current++; } else { $current = 1; $curType = $type; }
            if ($type === 'W') { $best = max($best, $current); } else { $worst = min($worst, -$current); }
        }
        return ['currentStreak' => ($curType === 'W' ? $current : -$current), 'bestWinStreak' => $best, 'worstLosingStreak' => abs($worst)];
    }

    private function inferMarketClass(string $symbol): string
    {
        if (preg_match('/(BTC|ETH|SOL|XRP|DOGE|ADA|AVAX|DOT|MATIC|LINK|UNI|ATOM|LTC|BCH|XLM|NEAR|APT|SUI|ARB|OP)USD/i', $symbol)) return 'crypto';
        if (preg_match('/(EUR|GBP|JPY|CHF|AUD|CAD|NZD|USD).{3}/i', $symbol)) return 'forex';
        return 'stock';
    }

    private function base(string $title): array
    {
        $s = $this->platform->state();
        return ['title' => $title, 'active' => 'trading', 'status' => ['tradingMode' => $s['tradingMode'], 'killSwitch' => $s['killSwitch'], 'providers' => $this->platform->providers->getAllHealth()], 'notice' => $this->session->flashdata('notice'), 'error' => $this->session->flashdata('error')];
    }
    private function jsonBody(): ?array { $raw = file_get_contents('php://input'); if (!$raw) return null; $d = json_decode($raw, true); return is_array($d) ? $d : null; }
    private function json(array $data, int $code = 200): void { http_response_code($code); header('Content-Type: application/json'); echo json_encode($data, JSON_UNESCAPED_SLASHES); }
    private function jsonError(string $msg, int $code = 400): void { $this->json(['ok' => false, 'error' => $msg], $code); }
}
