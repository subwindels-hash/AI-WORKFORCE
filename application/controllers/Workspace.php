<?php
defined('BASEPATH') or exit('No direct script access allowed');
require_once APPPATH . 'core/App_Controller.php';

/** Signed-in user home. Real counts only — empty modules stay empty. */
class Workspace extends App_Controller
{
    public function index()
    {
        $user = $this->identity;
        $state = $this->platform->state();
        $inbox = $this->platform->notifications->inbox((int) $user['id'], false, 8);
        $history = $this->platform->model->analysis->history(6);
        $accounts = $this->platform->model->paper->listAccounts();
        $profiles = [];
        try { $profiles = $this->platform->langlearn->profiles((int) $user['id']); } catch (Throwable $e) { $profiles = []; }
        $data = [
            'title' => 'Dashboard',
            'active' => 'home',
            'user' => $user,
            'admin' => $this->isAdmin($user),
            'status' => [
                'tradingMode' => $state['tradingMode'],
                'killSwitch' => $state['killSwitch'],
                'providers' => $this->platform->providers->getAllHealth(),
            ],
            'inbox' => $inbox,
            'history' => $history,
            'paperAccounts' => count($accounts),
            'languageProfiles' => count($profiles),
            'messagesUnread' => $this->AIWorkforce_model->messages->unreadForUser((int) $user['id']),
            'latestMessage' => $this->AIWorkforce_model->messages->latestForUser((int) $user['id']),
            'notice' => $this->session->flashdata('notice'),
            'error' => $this->session->flashdata('error'),
            'lotteryWidget' => $this->lotteryWidgetData((int) $user['id']),
            'tradingWidget' => $this->tradingWidgetData((int) $user['id']),
        ];
        $this->load->view('layout/header', $data);
        $this->load->view('workspace/index', $data);
        $this->load->view('layout/footer');
    }

    private function lotteryWidgetData(int $userId): array
    {
        $out = [
            'status' => 'NO_DATA',
            'jackpot' => null,
            'jackpotFormatted' => '—',
            'nextEstimated' => null,
            'lastDraw' => null,
            'recentDraws' => [],
            'imported' => 0,
            'myTicketsCount' => 0,
            'rules' => ['mains' => 5, 'mainMax' => 50, 'stars' => 2, 'starMax' => 12],
            'lotteries' => [['code' => 'euromillions', 'name' => 'EuroMillions']],
        ];
        
        try {
            $status = $this->platform->lottery->status();
            $out['status'] = $status['status'] ?? 'NO_DATA';
            $out['jackpot'] = $status['jackpot'] ?? null;
            $out['nextEstimated'] = $status['nextEstimated'] ?? null;
            $out['lastDraw'] = $status['lastDraw'] ?? null;
            $out['imported'] = $status['imported'] ?? 0;
            $out['rules'] = $status['rules'] ?? $out['rules'];
            $out['lotteries'] = $status['lotteries'] ?? $out['lotteries'];
            
            // Format jackpot with currency
            if ($out['jackpot'] !== null) {
                $jp = (float) $out['jackpot'];
                if ($jp >= 1000000) {
                    $out['jackpotFormatted'] = '€' . number_format($jp / 1000000, 1) . 'M';
                } elseif ($jp >= 1000) {
                    $out['jackpotFormatted'] = '€' . number_format($jp / 1000, 0) . 'K';
                } else {
                    $out['jackpotFormatted'] = '€' . number_format($jp, 0);
                }
            }
            
            // Get recent draws
            $out['recentDraws'] = $this->platform->lottery->listDraws(3, null, null);
            
            // Get user's tickets count
            try {
                $myTickets = $this->platform->lottery->listMyTickets($userId, 100);
                $out['myTicketsCount'] = count($myTickets);
            } catch (\Throwable $e) {
                $out['myTicketsCount'] = 0;
            }
        } catch (\Throwable $e) {
            // Lottery widget is non-critical, return defaults
        }
        
        return $out;
    }

    private function tradingWidgetData(int $userId): array
    {
        $out = ['totalEquity' => 0, 'totalPnl' => 0, 'openPositions' => 0, 'connectedBrokers' => 0, 'totalBrokers' => 0, 'brokers' => []];
        try {
            $connections = $this->platform->userBrokers->listForUser($userId);
            $out['totalBrokers'] = count($connections);
            foreach ($connections as $row) {
                $card = ['broker' => $row['broker'], 'label' => $row['label'] ?? '', 'connected' => !empty($row['enabled'])];
                if (!empty($row['enabled'])) {
                    $out['connectedBrokers']++;
                    try {
                        $c = $this->platform->userBrokers->buildConnector($row);
                        if ($c) {
                            $a = $c->account();
                            $out['totalEquity'] += (float) ($a['equity'] ?? $a['balance'] ?? 0);
                            $card['equity'] = $a['equity'] ?? $a['balance'] ?? null;
                            $card['currency'] = $a['currency'] ?? '';
                            if (method_exists($c, 'positions')) {
                                $pos = (array) $c->positions();
                                $out['openPositions'] += count($pos);
                                foreach ($pos as $p) $out['totalPnl'] += (float) ($p['unrealizedPnl'] ?? $p['pnl'] ?? 0);
                            }
                        }
                    } catch (\Throwable $e) { $card['error'] = 'unavailable'; }
                }
                $out['brokers'][] = $card;
            }
        } catch (\Throwable $e) { /* trading widget is non-critical */ }
        return $out;
    }
}
