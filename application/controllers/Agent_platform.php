<?php
defined('BASEPATH') or exit('No direct script access allowed');
require_once APPPATH . 'core/App_Controller.php';

/**
 * Agent Platform Console — observability, sessions, workflows, and agent interaction
 */
class Agent_platform extends App_Controller
{
    /** Shared layout scaffolding (title, nav state, platform status banner). */
    private function base(string $title, string $active): array
    {
        $state = $this->platform->state();
        return [
            'title' => $title, 'active' => $active,
            'status' => ['tradingMode' => $state['tradingMode'], 'killSwitch' => $state['killSwitch'],
                'providers' => $this->platform->providers->getAllHealth()],
            'notice' => $this->session->flashdata('notice'), 'error' => $this->session->flashdata('error'),
        ];
    }

    public function index()
    {
        $user = $this->identity;
        $data = $this->base('Agent Platform', 'agent_platform');

        $dashboard = [];
        $platformStatus = [];
        try {
            $dashboard = $this->platform->cloudflare->observability()->dashboard();
            $platformStatus = $this->platform->cloudflare->status();
        } catch (\Throwable $e) {
            $dashboard = ['error' => $e->getMessage()];
        }

        $data['dashboard'] = $dashboard;
        $data['platformStatus'] = $platformStatus;
        $data['agents'] = array_keys($this->platform->agents->agents());
        $data['tools'] = $this->platform->cloudflare->toolRegistry()->categories();

        $this->load->view('layout/header', $data);
        $this->load->view('agent_platform/index', $data);
        $this->load->view('layout/footer');
    }
}
