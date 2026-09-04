<?php
defined('BASEPATH') or exit('No direct script access allowed');
require_once APPPATH . 'core/App_Controller.php';

use AIWorkforce\MultiplierIntelligence\SimulationProvider;
use AIWorkforce\MultiplierIntelligence\MultiplierIntelligenceEngine;

/**
 * Multiplier Intelligence Console
 * 
 * AI-powered crash game analytics with multi-agent prediction system.
 * Demonstrates the WINDELS AI agent orchestration for real-time prediction.
 * 
 * IMPORTANT DISCLAIMER: This system uses statistical analysis for
 * educational and analytical purposes. Crash games are inherently
 * random and no system can guarantee predictions. Always gamble
 * responsibly.
 */
class Multiplier extends App_Controller
{
    private function engine(): MultiplierIntelligenceEngine
    {
        // Use simulation provider by default
        $provider = new SimulationProvider();
        
        // Generate some historical data if empty
        if (empty($provider->allRounds())) {
            for ($i = 0; $i < 100; $i++) {
                $provider->startRound();
                // Simulate round progression
                usleep(1000);
                $provider->endRound();
            }
        }
        
        // Get database from platform model
        $db = null;
        try {
            $db = $this->platform->model->db ?? null;
        } catch (\Throwable $e) {
            // DB optional for this module
        }
        
        return new MultiplierIntelligenceEngine($provider, $db);
    }
    
    public function index()
    {
        $user = $this->identity;
        $data = $this->base('AI Multiplier Intelligence', 'multiplier');
        
        $engine = $this->engine();
        $dashboard = $engine->dashboard();
        
        // Generate initial signal
        $signal = $engine->generateSignal();
        
        $data['dashboard'] = $dashboard;
        $data['signal'] = $signal;
        $data['accuracy'] = $engine->accuracyStats(100);
        $data['history'] = $engine->provider()->history(20);
        
        $this->load->view('layout/header', $data);
        $this->load->view('multiplier/index', $data);
        $this->load->view('layout/footer');
    }
    
    /**
     * Generate a new signal via AJAX
     */
    public function generate_signal()
    {
        if (strtoupper($this->input->server('REQUEST_METHOD')) !== 'POST') {
            show_404();
            return;
        }
        
        try {
            $engine = $this->engine();
            $signal = $engine->generateSignal();
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'signal' => $signal]);
        } catch (\Throwable $e) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }
    
    /**
     * Verify integration with Cloudflare Agent Platform and Sports Intelligence
     */
    public function verify_integration()
    {
        $results = [
            'ok' => true,
            'timestamp' => date('c'),
            'checks' => [],
        ];
        
        // 1. Check Multiplier Intelligence Engine
        try {
            $provider = new \AIWorkforce\MultiplierIntelligence\SimulationProvider();
            $engine = new \AIWorkforce\MultiplierIntelligence\MultiplierIntelligenceEngine($provider);
            $signal = $engine->generateSignal();
            $results['checks']['multiplier_engine'] = [
                'status' => 'OK',
                'agents' => count($signal['agents'] ?? []),
                'prediction' => $signal['predictedMultiplier'] ?? null,
                'confidence' => $signal['confidence'] ?? null,
            ];
        } catch (\Throwable $e) {
            $results['checks']['multiplier_engine'] = ['status' => 'FAIL', 'error' => $e->getMessage()];
            $results['ok'] = false;
        }
        
        // 2. Check Cloudflare ModelRouter
        try {
            $modelRouter = $this->platform->cloudflare->modelRouter();
            $configured = $modelRouter->configured();
            $status = $modelRouter->status();
            $results['checks']['cloudflare_model_router'] = [
                'status' => $configured ? 'OK' : 'NOT_CONFIGURED',
                'providers' => $configured ? count($status['providers'] ?? []) : 0,
                'llm_enhancement_available' => $configured,
            ];
        } catch (\Throwable $e) {
            $results['checks']['cloudflare_model_router'] = ['status' => 'FAIL', 'error' => $e->getMessage()];
        }
        
        // 3. Check McpToolRegistry for multiplier tools
        try {
            $registry = $this->platform->cloudflare->toolRegistry();
            $multiplierTools = $registry->list('multiplier');
            $results['checks']['mcp_multiplier_tools'] = [
                'status' => count($multiplierTools) > 0 ? 'OK' : 'NOT_YET_REGISTERED',
                'tools_registered' => count($multiplierTools),
                'tools' => array_keys($multiplierTools),
                'note' => 'Tools registered when MultiplierPlatformIntegration::register() is called',
            ];
        } catch (\Throwable $e) {
            $results['checks']['mcp_multiplier_tools'] = ['status' => 'FAIL', 'error' => $e->getMessage()];
        }
        
        // 4. Check MultiplierCloudflareBridge
        try {
            $modelRouter = $this->platform->cloudflare->modelRouter();
            $bridge = new \AIWorkforce\MultiplierIntelligence\MultiplierCloudflareBridge($modelRouter);
            $agents = $bridge->agentDescriptors();
            $tools = $bridge->mcpTools();
            $results['checks']['cloudflare_bridge'] = [
                'status' => 'OK',
                'agents_available' => count($agents),
                'tools_available' => count($tools),
                'llm_enhancement' => $bridge->isLLMEnhancementEnabled(),
            ];
        } catch (\Throwable $e) {
            $results['checks']['cloudflare_bridge'] = ['status' => 'FAIL', 'error' => $e->getMessage()];
        }
        
        // 5. Check SportsBettingEnrichmentProvider
        try {
            $enrichment = new \AIWorkforce\MultiplierIntelligence\SportsBettingEnrichmentProvider();
            $signals = $enrichment->getEnrichmentSignals();
            $results['checks']['sports_enrichment'] = [
                'status' => $signals['data_available'] ? 'OK' : 'AWAITING_SPORTS_CONFIG',
                'data_available' => $signals['data_available'],
                'source' => $signals['source'] ?? 'none',
                'enrichment_weight' => $enrichment->getEnrichmentWeight(),
                'note' => 'Becomes active when Sports Intelligence providers (api-football, thesportsdb, sportmonks) are configured with API keys',
            ];
        } catch (\Throwable $e) {
            $results['checks']['sports_enrichment'] = ['status' => 'FAIL', 'error' => $e->getMessage()];
        }
        
        // 6. Check MultiplierSpecialistAgent
        try {
            $agent = new \AIWorkforce\MultiplierIntelligence\MultiplierSpecialistAgent();
            $result = $agent->handle(['instruction' => 'Test signal'], []);
            $results['checks']['specialist_agent'] = [
                'status' => ($result['status'] ?? '') === 'COMPLETED' ? 'OK' : 'FAIL',
                'role' => $agent->name(),
                'tools' => count($agent->tools()),
                'dispatchable' => true,
                'implements' => 'SpecialistAgent (Cloudflare)',
            ];
        } catch (\Throwable $e) {
            $results['checks']['specialist_agent'] = ['status' => 'FAIL', 'error' => $e->getMessage()];
        }
        
        // 7. Check Platform Integration
        try {
            $integration = new \AIWorkforce\MultiplierIntelligence\MultiplierPlatformIntegration($this->platform->cloudflare);
            $integration->register();
            $status = $integration->status();
            $results['checks']['platform_integration'] = [
                'status' => $status['registered'] ? 'OK' : 'REGISTERED_PARTIAL',
                'registered' => $status['registered'],
                'agent_available' => $status['agent_available'],
                'bridge_available' => $status['bridge_available'],
                'enrichment_available' => $status['enrichment_available'],
                'llm_enhancement' => $status['llm_enhancement'],
            ];
        } catch (\Throwable $e) {
            $results['checks']['platform_integration'] = ['status' => 'FAIL', 'error' => $e->getMessage()];
        }
        
        // Summary
        $totalChecks = count($results['checks']);
        $okChecks = count(array_filter($results['checks'], fn($c) => in_array($c['status'] ?? '', ['OK', 'NOT_CONFIGURED', 'AWAITING_SPORTS_CONFIG', 'NOT_YET_REGISTERED'])));
        $results['summary'] = [
            'total_checks' => $totalChecks,
            'passed_or_configurable' => $okChecks,
            'fully_operational' => $okChecks === $totalChecks,
            'integration_ready' => $okChecks >= 4,
            'message' => $okChecks === $totalChecks
                ? 'All systems operational — full integration ready'
                : 'Integration architecture verified — configure providers to activate',
        ];
        
        header('Content-Type: application/json');
        echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
    
    /**
     * Get live multiplier data
     */
    public function live()
    {
        try {
            $engine = $this->engine();
            $provider = $engine->provider();
            
            // Update current round
            if ($provider instanceof SimulationProvider) {
                $provider->updateMultiplier();
            }
            
            header('Content-Type: application/json');
            echo json_encode([
                'ok' => true,
                'currentMultiplier' => $provider->currentMultiplier(),
                'isInRound' => $provider->isInRound(),
                'latestRound' => $provider->latestRound(),
            ]);
        } catch (\Throwable $e) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }
    
    /**
     * Get dashboard data
     */
    public function dashboard_data()
    {
        try {
            $engine = $this->engine();
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'dashboard' => $engine->dashboard()]);
        } catch (\Throwable $e) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }
    
    /**
     * Get accuracy statistics
     */
    public function accuracy()
    {
        try {
            $engine = $this->engine();
            $window = (int) ($this->input->get('window') ?: 100);
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'accuracy' => $engine->accuracyStats($window)]);
        } catch (\Throwable $e) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }
    
    /**
     * Validate past predictions
     */
    public function validate()
    {
        if (strtoupper($this->input->server('REQUEST_METHOD')) !== 'POST') {
            show_404();
            return;
        }
        
        try {
            $engine = $this->engine();
            $result = $engine->validatePredictions();
            header('Content-Type: application/json');
            echo json_encode($result);
        } catch (\Throwable $e) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }
}
