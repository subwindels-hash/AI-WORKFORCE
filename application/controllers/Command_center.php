<?php
defined('BASEPATH') or exit('No direct script access allowed');
require_once APPPATH . 'core/App_Controller.php';

/**
 * Unified AI Command Center
 * 
 * Single dashboard showing status of all AI modules:
 * - Cloudflare AI Agent Platform
 * - Trading Intelligence
 * - Lottery Intelligence
 * - Multiplier Intelligence
 * - Language Learning AI
 * - Sports Intelligence
 * - Lead Discovery AI
 */
class Command_center extends App_Controller
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
        $data = $this->base('AI Command Center', 'command_center');
        
        // Gather status from all AI modules
        $data['modules'] = $this->getModuleStatus();
        $data['overview'] = $this->getOverview();
        $data['systemHealth'] = $this->getSystemHealth();
        $data['recentActivity'] = $this->getRecentActivity();
        
        $this->load->view('layout/header', $data);
        $this->load->view('command_center/index', $data);
        $this->load->view('layout/footer');
    }
    
    /**
     * Get status of all AI modules
     */
    private function getModuleStatus(): array
    {
        $modules = [];
        
        // 1. Cloudflare AI Agent Platform
        try {
            $cfStatus = $this->platform->cloudflare->status();
            $modules['cloudflare'] = [
                'name' => 'Cloudflare AI Agent Platform',
                'icon' => '⚡',
                'status' => !empty($cfStatus['modelRouter']['configured']) ? 'healthy' : 'degraded',
                'stats' => [
                    'agents' => count($cfStatus['communicationBus']['availableAgents'] ?? []),
                    'tools' => $cfStatus['toolRegistry']['totalTools'] ?? 0,
                    'models' => count($cfStatus['modelRouter']['providers'] ?? []),
                ],
                'link' => '/app/agent-platform',
            ];
        } catch (\Throwable $e) {
            $modules['cloudflare'] = [
                'name' => 'Cloudflare AI Agent Platform',
                'icon' => '⚡',
                'status' => 'error',
                'error' => $e->getMessage(),
                'link' => '/app/agent-platform',
            ];
        }
        
        // 2. Multiplier Intelligence
        try {
            $provider = new \AIWorkforce\MultiplierIntelligence\SimulationProvider();
            $engine = new \AIWorkforce\MultiplierIntelligence\MultiplierIntelligenceEngine($provider);
            $dashboard = $engine->dashboard();
            
            $modules['multiplier'] = [
                'name' => 'Multiplier Intelligence',
                'icon' => '🚀',
                'status' => 'healthy',
                'stats' => [
                    'agents' => 9,
                    'rounds' => count($dashboard['history'] ?? []),
                    'accuracy' => $dashboard['accuracy']['accuracy20'] ?? null,
                ],
                'link' => '/multiplier',
            ];
        } catch (\Throwable $e) {
            $modules['multiplier'] = [
                'name' => 'Multiplier Intelligence',
                'icon' => '🚀',
                'status' => 'error',
                'error' => $e->getMessage(),
                'link' => '/multiplier',
            ];
        }
        
        // 3. Lottery Intelligence
        try {
            $lotteryStatus = $this->platform->lottery->status();
            $modules['lottery'] = [
                'name' => 'Lottery Intelligence',
                'icon' => '🎰',
                'status' => ($lotteryStatus['status'] ?? 'UNKNOWN') === 'OK' ? 'healthy' : 'degraded',
                'stats' => [
                    'draws' => $lotteryStatus['imported'] ?? 0,
                    'jackpot' => $lotteryStatus['jackpot'] ?? null,
                ],
                'link' => '/lottery',
            ];
        } catch (\Throwable $e) {
            $modules['lottery'] = [
                'name' => 'Lottery Intelligence',
                'icon' => '🎰',
                'status' => 'error',
                'error' => $e->getMessage(),
                'link' => '/lottery',
            ];
        }
        
        // 4. Trading Intelligence
        try {
            $tradingStatus = [
                'mode' => $this->platform->state()['tradingMode'] ?? 'UNKNOWN',
                'killSwitch' => !empty($this->platform->state()['killSwitch']['active']),
            ];
            $modules['trading'] = [
                'name' => 'Trading Intelligence',
                'icon' => '💹',
                'status' => $tradingStatus['killSwitch'] ? 'warning' : 'healthy',
                'stats' => [
                    'mode' => $tradingStatus['mode'],
                    'killSwitch' => $tradingStatus['killSwitch'] ? 'ACTIVE' : 'OFF',
                ],
                'link' => '/app/trading',
            ];
        } catch (\Throwable $e) {
            $modules['trading'] = [
                'name' => 'Trading Intelligence',
                'icon' => '💹',
                'status' => 'error',
                'error' => $e->getMessage(),
                'link' => '/app/trading',
            ];
        }
        
        // 5. Sports Intelligence
        try {
            $sportsProviders = $this->platform->model->sports->listProviders(true);
            $modules['sports'] = [
                'name' => 'Sports Intelligence',
                'icon' => '⚽',
                'status' => !empty($sportsProviders) ? 'healthy' : 'degraded',
                'stats' => [
                    'providers' => count($sportsProviders),
                ],
                'link' => '/sports',
            ];
        } catch (\Throwable $e) {
            $modules['sports'] = [
                'name' => 'Sports Intelligence',
                'icon' => '⚽',
                'status' => 'error',
                'error' => $e->getMessage(),
                'link' => '/sports',
            ];
        }
        
        // 6. Language Learning AI
        try {
            $profiles = $this->platform->langlearn->profiles((int) $user['id']);
            $modules['language'] = [
                'name' => 'Language Learning AI',
                'icon' => '🗣️',
                'status' => 'healthy',
                'stats' => [
                    'profiles' => count($profiles),
                ],
                'link' => '/app/languages',
            ];
        } catch (\Throwable $e) {
            $modules['language'] = [
                'name' => 'Language Learning AI',
                'icon' => '🗣️',
                'status' => 'error',
                'error' => $e->getMessage(),
                'link' => '/app/languages',
            ];
        }
        
        // 7. Lead Discovery AI
        $modules['leads'] = [
            'name' => 'Lead Discovery AI',
            'icon' => '🔍',
            'status' => 'healthy',
            'stats' => [],
            'link' => '/leads',
        ];
        
        return $modules;
    }
    
    /**
     * Get overview statistics
     */
    private function getOverview(): array
    {
        return [
            'totalModules' => 7,
            'healthyModules' => 0, // Will be calculated in view
            'totalAgents' => 0,
            'totalTools' => 0,
            'systemUptime' => '99.9%',
        ];
    }
    
    /**
     * Get overall system health
     */
    private function getSystemHealth(): array
    {
        $modules = $this->getModuleStatus();
        $healthy = 0;
        $degraded = 0;
        $error = 0;
        
        foreach ($modules as $m) {
            if ($m['status'] === 'healthy') $healthy++;
            elseif ($m['status'] === 'degraded') $degraded++;
            elseif ($m['status'] === 'error') $error++;
        }
        
        $total = count($modules);
        $healthScore = $total > 0 ? round(($healthy / $total) * 100, 0) : 0;
        
        return [
            'score' => $healthScore,
            'healthy' => $healthy,
            'degraded' => $degraded,
            'error' => $error,
            'total' => $total,
            'status' => $error > 0 ? 'DEGRADED' : ($degraded > 0 ? 'WARNING' : 'HEALTHY'),
        ];
    }
    
    /**
     * Get recent activity across all modules
     */
    private function getRecentActivity(): array
    {
        $activities = [];
        
        // Try to get recent activities from audit log
        try {
            $recent = $this->platform->model->audit->recent(20);
            foreach ($recent as $r) {
                $activities[] = [
                    'time' => $r['created_at'] ?? '',
                    'type' => $r['type'] ?? 'UNKNOWN',
                    'summary' => $r['summary'] ?? '',
                    'module' => $this->inferModule($r['type'] ?? ''),
                ];
            }
        } catch (\Throwable $e) {
            // Skip if audit log unavailable
        }
        
        return $activities;
    }
    
    /**
     * Infer which module an audit event belongs to
     */
    private function inferModule(string $type): string
    {
        if (strpos($type, 'MULTIPLIER') !== false) return 'Multiplier';
        if (strpos($type, 'LOTTERY') !== false) return 'Lottery';
        if (strpos($type, 'TRADING') !== false || strpos($type, 'BROKER') !== false) return 'Trading';
        if (strpos($type, 'SPORTS') !== false) return 'Sports';
        if (strpos($type, 'LANGUAGE') !== false) return 'Language';
        if (strpos($type, 'AGENT') !== false || strpos($type, 'MODEL') !== false) return 'Cloudflare';
        if (strpos($type, 'LEAD') !== false) return 'Leads';
        return 'System';
    }
}
