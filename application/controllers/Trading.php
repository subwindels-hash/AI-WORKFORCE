<?php
defined('BASEPATH') or exit('No direct script access allowed');
require_once APPPATH . 'core/App_Controller.php';

/**
 * User-facing trading workspace — comprehensive 5-tab interface.
 *
 * Tabs: Overview | Trade | Positions | Accounts | AI Analysis
 *
 * All order execution remains behind the existing supervisor, risk gates,
 * kill switch, and approval workflow. This controller aggregates data from
 * the platform's broker connections, execution supervisor, and AI analysis.
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
            if (empty($row['enabled'])) {
                $info['status'] = 'disabled';
                $brokerStatus[] = $info;
                continue;
            }
            try {
                $c = $this->platform->userBrokers->buildConnector($row);
                if (!$c) { $info['status'] = 'unavailable'; $brokerStatus[] = $info; continue; }
                $status = $c->status();
                $info['status'] = ($status['state'] ?? 'UNKNOWN');
                $info['latencyMs'] = $status['latencyMs'] ?? null;
                $a = $c->account();
                $a['broker'] = $row['broker'];
                $a['label'] = $row['label'];
                $a['connectionId'] = $row['id'] ?? null;
                $accounts[] = $a;
                $totalEquity += (float) ($a['equity'] ?? $a['balance'] ?? 0);
                if (method_exists($c, 'positions')) {
                    foreach ((array) $c->positions() as $p) {
                        $p['broker'] = $row['broker'];
                        $p['label'] = $row['label'];
                        $positions[] = $p;
                        $totalUnrealizedPnl += (float) ($p['unrealizedPnl'] ?? $p['pnl'] ?? 0);
                    }
                }
            } catch (\Throwable $e) {
                $info['status'] = 'error';
                $info['error'] = mb_substr($e->getMessage(), 0, 120);
                $accounts[] = ['broker' => $row['broker'], 'label' => $row['label'], 'error' => 'Unavailable'];
            }
            $brokerStatus[] = $info;
        }

        // ── Executions & proposals ────────────────────────────────────
        $executions = $this->platform->execution->executions(20);
        $proposals = $this->platform->execution->proposals(null, 20);

        // ── Risk & limits ─────────────────────────────────────────────
        $riskLimits = \AIWorkforce\ExecutionSupervisor::automationLimits($this->platform->state());
        $state = $this->platform->state();

        // ── AI analysis providers ─────────────────────────────────────
        $analysis = $this->platform->providers->getAllHealth();

        // ── Recent analysis history ───────────────────────────────────
        $history = $this->platform->model->analysis->history(10);

        // ── Performance metrics ───────────────────────────────────────
        $openPositions = count($positions);
        $todayExecutions = count(array_filter($executions, fn($x) => substr((string)($x['created_at'] ?? $x['createdAt'] ?? ''), 0, 10) === gmdate('Y-m-d')));
        $approvedProposals = count(array_filter($proposals, fn($p) => ($p['status'] ?? '') === 'APPROVED'));
        $rejectedProposals = count(array_filter($proposals, fn($p) => ($p['status'] ?? '') === 'REJECTED'));
        $pendingProposals = count(array_filter($proposals, fn($p) => in_array($p['status'] ?? '', ['PENDING', 'PENDING_APPROVAL'], true)));

        $data = array_merge($data, [
            'connections' => $connections,
            'brokerStatus' => $brokerStatus,
            'accounts' => $accounts,
            'positions' => $positions,
            'totalEquity' => $totalEquity,
            'totalUnrealizedPnl' => $totalUnrealizedPnl,
            'executions' => $executions,
            'proposals' => $proposals,
            'riskLimits' => $riskLimits,
            'analysis' => $analysis,
            'history' => $history,
            'openPositions' => $openPositions,
            'todayExecutions' => $todayExecutions,
            'approvedProposals' => $approvedProposals,
            'rejectedProposals' => $rejectedProposals,
            'pendingProposals' => $pendingProposals,
            'tradingMode' => $state['tradingMode'],
            'killSwitchActive' => !empty($state['killSwitch']['active']),
            'automationLimits' => $riskLimits,
            'supportedBrokers' => \AIWorkforce\Brokers\UserBrokerConnections::SUPPORTED,
        ]);

        $this->load->view('layout/header', $data);
        $this->load->view('trading/index', $data);
        $this->load->view('layout/footer');
    }

    /**
     * API endpoint: submit a trade proposal through the execution supervisor.
     * POST /app/trading/submit_order
     */
    public function submit_order()
    {
        if (strtoupper($this->input->server('REQUEST_METHOD')) !== 'POST') {
            show_404();
            return;
        }
        try {
            $input = $this->jsonBody() ?: [
                'symbol' => $this->input->post('symbol'),
                'side' => strtoupper((string) $this->input->post('side')),
                'volume' => (float) $this->input->post('volume'),
                'stopLoss' => $this->input->post('stopLoss') ? (float) $this->input->post('stopLoss') : null,
                'takeProfit' => $this->input->post('takeProfit') ? array_map('floatval', (array) $this->input->post('takeProfit')) : null,
                'confidence' => (float) ($this->input->post('confidence') ?: 0.5),
                'reason' => (string) ($this->input->post('reason') ?: 'Manual trade from My Trading'),
                'broker' => $this->input->post('broker'),
            ];

            $state = $this->platform->state();
            $killSwitch = !empty($state['killSwitch']['active']);
            if ($killSwitch) {
                $this->jsonError('Kill switch is active — all order placement is blocked.', 409);
                return;
            }

            // Route through the execution supervisor
            $result = $this->platform->execution->propose([
                'symbol' => strtoupper((string) ($input['symbol'] ?? '')),
                'side' => $input['side'] ?? 'BUY',
                'volume' => max(0.01, (float) ($input['volume'] ?? 0.01)),
                'stopLoss' => $input['stopLoss'] ?? null,
                'takeProfit' => $input['takeProfit'] ?? null,
                'confidence' => min(1, max(0, (float) ($input['confidence'] ?? 0.5))),
                'reason' => $input['reason'] ?? 'Manual trade',
                'userId' => (int) $this->identity['id'],
            ]);

            $this->platform->model->audit->emit(
                'TRADE_PROPOSAL_SUBMITTED',
                "User submitted trade: {$input['side']} {$input['volume']} " . ($input['symbol'] ?? ''),
                ['symbol' => $input['symbol'] ?? '', 'side' => $input['side'], 'volume' => $input['volume'] ?? 0],
                'user'
            );

            $this->json(['ok' => true, 'proposal' => $result, 'message' => 'Trade proposal submitted — awaiting risk review and approval.']);
        } catch (\Throwable $e) {
            $this->jsonError($e->getMessage(), 422);
        }
    }

    /**
     * API endpoint: close a position.
     * POST /app/trading/close_position
     */
    public function close_position()
    {
        if (strtoupper($this->input->server('REQUEST_METHOD')) !== 'POST') {
            show_404();
            return;
        }
        $broker = strtolower((string) ($this->input->post('broker') ?? $this->jsonBody()['broker'] ?? ''));
        $positionId = (string) ($this->input->post('positionId') ?? $this->jsonBody()['positionId'] ?? '');
        if ($broker === '' || $positionId === '') {
            $this->jsonError('broker and positionId are required', 422);
            return;
        }
        try {
            $connection = $this->platform->userBrokers->findForUser((int) $this->identity['id'], $broker);
            if (!$connection || empty($connection['enabled'])) {
                $this->jsonError('Broker connection not found or disabled', 404);
                return;
            }
            $c = $this->platform->userBrokers->buildConnector($connection);
            if (!$c) {
                $this->jsonError('Broker connector unavailable', 503);
                return;
            }
            $result = method_exists($c, 'closePosition') ? $c->closePosition($positionId) : ['status' => 'NOT_SUPPORTED', 'message' => 'This connector does not support position closing'];
            $this->platform->model->audit->emit('POSITION_CLOSE_REQUESTED', "User requested close of position {$positionId} on {$broker}", ['broker' => $broker, 'positionId' => $positionId], 'user');
            $this->json(['ok' => true, 'result' => $result]);
        } catch (\Throwable $e) {
            $this->jsonError($e->getMessage(), 422);
        }
    }

    /**
     * API endpoint: get live trading status for the dashboard widget.
     * GET /app/trading/widget_data
     */
    public function widget_data()
    {
        $userId = (int) $this->identity['id'];
        $connections = $this->platform->userBrokers->listForUser($userId);
        $totalEquity = 0;
        $totalPnl = 0;
        $posCount = 0;
        $brokerCards = [];
        foreach ($connections as $row) {
            $card = ['broker' => $row['broker'], 'label' => $row['label'] ?? '', 'connected' => !empty($row['enabled'])];
            if (!empty($row['enabled'])) {
                try {
                    $c = $this->platform->userBrokers->buildConnector($row);
                    if ($c) {
                        $a = $c->account();
                        $totalEquity += (float) ($a['equity'] ?? $a['balance'] ?? 0);
                        $card['equity'] = $a['equity'] ?? $a['balance'] ?? null;
                        $card['currency'] = $a['currency'] ?? '';
                        if (method_exists($c, 'positions')) {
                            $pos = $c->positions();
                            $posCount += count((array) $pos);
                            foreach ((array) $pos as $p) $totalPnl += (float) ($p['unrealizedPnl'] ?? $p['pnl'] ?? 0);
                        }
                    }
                } catch (\Throwable $e) { $card['error'] = 'unavailable'; }
            }
            $brokerCards[] = $card;
        }
        $state = $this->platform->state();
        $this->json([
            'totalEquity' => $totalEquity,
            'totalPnl' => $totalPnl,
            'openPositions' => $posCount,
            'connectedBrokers' => count(array_filter($brokerCards, fn($b) => $b['connected'])),
            'totalBrokers' => count($brokerCards),
            'brokers' => $brokerCards,
            'mode' => $state['tradingMode'],
            'killSwitch' => !empty($state['killSwitch']['active']),
        ]);
    }

    private function base(string $title): array
    {
        $s = $this->platform->state();
        return [
            'title' => $title,
            'active' => 'trading',
            'status' => [
                'tradingMode' => $s['tradingMode'],
                'killSwitch' => $s['killSwitch'],
                'providers' => $this->platform->providers->getAllHealth(),
            ],
            'notice' => $this->session->flashdata('notice'),
            'error' => $this->session->flashdata('error'),
        ];
    }

    private function jsonBody(): ?array
    {
        $raw = file_get_contents('php://input');
        if (!$raw) return null;
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function json(array $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_SLASHES);
    }

    private function jsonError(string $message, int $code = 400): void
    {
        $this->json(['ok' => false, 'error' => $message], $code);
    }
}
