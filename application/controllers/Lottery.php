<?php
defined('BASEPATH') or exit('No direct script access allowed');
require_once APPPATH . 'core/App_Controller.php';

/**
 * Lottery Intelligence dashboard — EuroMillions analysis, frequency/gap
 * statistics, the AI combination generator, the saved ticket builder, the
 * system builder (C(N,5) wheel), the strategy lab with mandatory random
 * baseline, and RBAC-gated admin controls for official source verification.
 *
 * The page itself is rendered from React-friendly JSON hydrated into
 * window.__AI_LOTTERY_STATE__ so the rich UI widgets stay responsive, but
 * every write/action still goes through the audited PHP endpoints and
 * enforces lottery.view / lottery.manage RBAC.
 */
class Lottery extends App_Controller
{
    public function index()
    {
        $user = $this->identity;
        $canManage = $this->platform->identity->can($user, 'lottery.manage')
                  || $this->platform->identity->can($user, 'lottery_admin');
        $canView = $canManage
                || $this->platform->identity->can($user, 'lottery.view')
                || $this->platform->identity->can($user, 'lottery_viewer');
        if (!$canView) {
            $this->session->set_flashdata('error', 'You do not have access to Lottery Intelligence.');
            redirect('/');
            return;
        }

        $state = $this->platform->state();
        try {
            $status = $this->platform->lottery->status();
        } catch (\Throwable $e) {
            $status = ['status' => 'NO_DATA', 'jackpot' => null, 'lastDraw' => null,
                       'nextEstimated' => null, 'imported' => 0, 'provider' => null,
                       'rules' => ['mains' => 5, 'mainMax' => 50, 'stars' => 2, 'starMax' => 12],
                       'lotteries' => [['code' => 'euromillions', 'name' => 'EuroMillions']]];
        }
        try {
            $draws = $this->platform->lottery->listDraws(20, null, null);
        } catch (\Throwable $e) { $draws = []; }
        try {
            $myTickets = $this->platform->lottery->listMyTickets((int)$user['id'], 20);
        } catch (\Throwable $e) { $myTickets = []; }
        try {
            $recentCombinations = $this->platform->lottery->listCombinations(10, 0);
        } catch (\Throwable $e) { $recentCombinations = []; }
        try {
            $backtests = $this->platform->lottery->listBacktests(10);
        } catch (\Throwable $e) { $backtests = []; }

        $data = [
            'title' => 'EuroMillions · Lottery Intelligence',
            'active' => 'lottery',
            'status' => ['tradingMode' => $state['tradingMode'], 'killSwitch' => $state['killSwitch'],
                         'providers' => $this->platform->providers->getAllHealth()],
            'notice' => $this->session->flashdata('notice'),
            'error' => $this->session->flashdata('error'),
            'canManage' => $canManage,
            'stateJson' => json_encode([
                'status' => $status,
                'draws' => $draws,
                'myTickets' => $myTickets,
                'recentCombinations' => $recentCombinations,
                'backtests' => $backtests,
                'me' => ['id' => (int)$user['id'], 'name' => (string)($user['name'] ?? ''), 'canManage' => $canManage],
                'endpoints' => [
                    'status' => '/api/lottery/status',
                    'rules' => '/api/lottery/rules',
                    'draws' => '/api/lottery/draws',
                    'statistics' => '/api/lottery/statistics',
                    'analyze' => '/api/lottery/analyze',
                    'generate' => '/api/lottery/generate',
                    'diversity' => '/api/lottery/diversity',
                    'combinations' => '/api/lottery/combinations',
                    'system' => '/api/lottery/system',
                    'systemBuild' => '/api/lottery/system-build',
                    'tickets' => '/api/lottery/tickets',
                    'backtests' => '/api/lottery/backtests',
                    'models' => '/api/lottery/models',
                    'performance' => '/api/lottery/performance',
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];

        $this->load->view('layout/header', $data);
        $this->load->view('lottery/index', $data);
        $this->load->view('layout/footer');
    }
}
