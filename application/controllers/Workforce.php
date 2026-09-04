<?php
defined('BASEPATH') or exit('No direct script access allowed');
require_once APPPATH . 'core/App_Controller.php';

/**
 * AI Workforce Console — user-facing agent interaction page.
 * 
 * Users can interact with specialist AI agents powered by Cloudflare Workers AI:
 *   - General Agent — General knowledge and assistance
 *   - Market Agent — Crypto and forex market analysis
 *   - Sports Agent — Sports statistics and analysis
 *   - Lead Discovery Agent — Business discovery and lead enrichment
 *   - Lottery Agent — Statistical lottery analysis
 *   - Language Agent — Language learning assistance
 *   - Trading Agent — Trading analysis and proposals
 *   - Video Agent — Video generation assistance
 */
class Workforce extends App_Controller
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
        $data = $this->base('AI Workforce', 'workforce');

        // Gather agent information
        $agents = $this->platform->agents->agents();
        $agentList = [];
        foreach ($agents as $name => $agent) {
            $agentList[] = [
                'name' => $name,
                'label' => $this->agentLabel($name),
                'icon' => $this->agentIcon($name),
                'color' => $this->agentColor($name),
                'description' => $this->agentDescription($name),
                'tools' => $agent->tools(),
            ];
        }

        // Check Cloudflare configuration
        $llmStatus = \AIWorkforce\ApiProviders::publicStatus('llm');
        $cloudflareConfigured = !empty($llmStatus['configured']);

        $data['agents'] = $agentList;
        $data['agentCount'] = count($agentList);
        $data['cloudflareConfigured'] = $cloudflareConfigured;
        $data['llmStatus'] = $llmStatus;

        $this->load->view('layout/header', $data);
        $this->load->view('workforce/index', $data);
        $this->load->view('layout/footer');
    }

    private function agentLabel(string $name): string
    {
        return [
            'general' => 'General Assistant',
            'market' => 'Market Analyst',
            'sports' => 'Sports Intelligence',
            'lead_discovery' => 'Lead Scout',
            'lottery' => 'Lottery Analyst',
            'language' => 'Language Coach',
            'trading' => 'Trading Analyst',
            'video' => 'Video Assistant',
        ][$name] ?? ucfirst(str_replace('_', ' ', $name));
    }

    private function agentIcon(string $name): string
    {
        return [
            'general' => '🤖',
            'market' => '📈',
            'sports' => '⚽',
            'lead_discovery' => '🔍',
            'lottery' => '🎰',
            'language' => '🗣️',
            'trading' => '💹',
            'video' => '🎬',
        ][$name] ?? '🧠';
    }

    private function agentColor(string $name): string
    {
        return [
            'general' => '#6366f1',
            'market' => '#22c55e',
            'sports' => '#f59e0b',
            'lead_discovery' => '#3b82f6',
            'lottery' => '#a855f7',
            'language' => '#ef4444',
            'trading' => '#14b8a6',
            'video' => '#ec4899',
        ][$name] ?? '#6b7280';
    }

    private function agentDescription(string $name): string
    {
        return [
            'general' => 'General knowledge, research assistance, and multi-domain analysis.',
            'market' => 'Crypto and forex market analysis with real-time price data.',
            'sports' => 'Sports fixtures, match statistics, and performance analysis.',
            'lead_discovery' => 'Business discovery, lead enrichment, and duplicate detection.',
            'lottery' => 'EuroMillions statistical analysis and number frequency patterns.',
            'language' => 'Language learning support, pronunciation analysis, and translation.',
            'trading' => 'Trading analysis, portfolio insights, and trade proposals.',
            'video' => 'Video generation and visual content assistance.',
        ][$name] ?? 'AI specialist agent.';
    }
}
