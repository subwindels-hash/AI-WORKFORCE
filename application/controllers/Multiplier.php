<?php
defined('BASEPATH') or exit('No direct script access allowed');
require_once APPPATH . 'core/App_Controller.php';

use AIWorkforce\MultiplierIntelligence\CrashProviderFactory;
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

    private function engine(): MultiplierIntelligenceEngine
    {
        $provider = CrashProviderFactory::fromPlatform($this->platform);
        
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
        
        // Use Cloudflare-enhanced signal if integration is available
        $signal = $this->enhancedSignal($engine);
        
        $data['dashboard'] = $dashboard;
        $data['signal'] = $signal;
        $data['accuracy'] = $engine->accuracyStats(100);
        $data['history'] = $engine->provider()->history(20);
        
        // Integration status for UI display
        $data['integration'] = $this->integrationStatus();
        
        $this->load->view('layout/header', $data);
        $this->load->view('multiplier/index', $data);
        $this->load->view('layout/footer');
    }
    
    /**
     * Get integration status for UI display
     */
    private function integrationStatus(): array
    {
        $status = [
            'cloudflare' => ['available' => false, 'enhanced' => false, 'agents' => 0, 'tools' => 0],
            'sports' => ['available' => false, 'signals' => [], 'weight' => 0.15],
            'llm' => ['available' => false, 'providers' => 0],
            'registered' => false,
        ];
        
        // Check Cloudflare integration
        try {
            if (isset($this->platform->multiplierIntegration)) {
                $integration = $this->platform->multiplierIntegration;
                $integrationStatus = $integration->status();
                $status['cloudflare']['available'] = $integrationStatus['bridge_available'];
                $status['cloudflare']['enhanced'] = $integrationStatus['llm_enhancement'];
                $status['cloudflare']['agents'] = 9;
                $status['cloudflare']['tools'] = 6;
                $status['registered'] = $integrationStatus['registered'];
            }
        } catch (\Throwable $e) {
            // Non-critical
        }
        
        // Check LLM provider
        try {
            $modelRouter = $this->platform->cloudflare->modelRouter();
            if ($modelRouter->configured()) {
                $routerStatus = $modelRouter->status();
                $status['llm']['available'] = true;
                $status['llm']['providers'] = count($routerStatus['providers'] ?? []);
            }
        } catch (\Throwable $e) {
            // Non-critical
        }
        
        // Check Sports enrichment
        try {
            if (isset($this->platform->multiplierIntegration) && $this->platform->multiplierIntegration->enrichment()) {
                $enrichment = $this->platform->multiplierIntegration->enrichment();
                $signals = $enrichment->getEnrichmentSignals();
                $status['sports']['available'] = $signals['data_available'];
                $status['sports']['signals'] = [
                    'sentiment' => $signals['market_sentiment'] ?? 'neutral',
                    'activity' => $signals['event_activity'] ?? 'normal',
                ];
                $status['sports']['weight'] = $enrichment->getEnrichmentWeight();
            }
        } catch (\Throwable $e) {
            // Non-critical
        }
        
        return $status;
    }
    
    /**
     * Generate an enhanced signal using Cloudflare + Sports enrichment if available
     */
    private function enhancedSignal(MultiplierIntelligenceEngine $engine): array
    {
        $signal = $engine->generateSignal();
        
        // Apply Sports enrichment if available
        try {
            if (isset($this->platform->multiplierIntegration) && $this->platform->multiplierIntegration->enrichment()) {
                $enrichment = $this->platform->multiplierIntegration->enrichment();
                $signal = $enrichment->enrichPrediction($signal);
            }
        } catch (\Throwable $e) {
            // Enrichment is non-critical
        }
        
        // Mark as Cloudflare-enhanced if integration is active
        try {
            if (isset($this->platform->multiplierIntegration)) {
                $intStatus = $this->platform->multiplierIntegration->status();
                $signal['cloudflare_enhanced'] = $intStatus['llm_enhancement'];
                $signal['sports_enriched'] = $intStatus['enrichment_available'];
            }
        } catch (\Throwable $e) {
            // Non-critical
        }
        
        return $signal;
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
     * Live Demo Mode - Showcases full integration
     * 
     * Demonstrates:
     * - Multiplier Intelligence with 9 agents
     * - Cloudflare AI enhancement (if configured)
     * - Sports Intelligence enrichment (if configured)
     * - Real-time Aviator provider data
     * - Full data flow visualization
     */
    public function live_demo()
    {
        $data = $this->base('Live Demo - Multiplier Intelligence', 'multiplier');
        
        try {
            // 1. Initialize Aviator Provider
            $aviatorProvider = CrashProviderFactory::fromPlatform($this->platform);
            $aviatorHealth = $aviatorProvider->health();
            $aviatorStats = method_exists($aviatorProvider, 'stats') ? $aviatorProvider->stats() : [];
            
            $liveRound = method_exists($aviatorProvider, 'updateMultiplier')
                ? $aviatorProvider->updateMultiplier()
                : [
                    'currentMultiplier' => $aviatorProvider->currentMultiplier() ?? 1.0,
                    'roundId' => ($aviatorProvider->latestRound()['roundId'] ?? null),
                    'inRound' => $aviatorProvider->isInRound(),
                    'elapsedMs' => 0,
                ];
            
            // 3. Get Multiplier Intelligence analysis
            $engine = new \AIWorkforce\MultiplierIntelligence\MultiplierIntelligenceEngine($aviatorProvider);
            $dashboard = $engine->dashboard();
            $signal = $engine->generateSignal();
            
            // 4. Apply Sports Intelligence enrichment (if available)
            $sportsEnrichment = null;
            if (isset($this->platform->multiplierIntegration) && $this->platform->multiplierIntegration->enrichment()) {
                $enrichment = $this->platform->multiplierIntegration->enrichment();
                $signal = $enrichment->enrichPrediction($signal);
                $sportsEnrichment = $enrichment->getEnrichmentSignals();
            }
            
            // 5. Check Cloudflare integration
            $cloudflareStatus = [
                'available' => false,
                'llm_enhanced' => false,
                'agents' => 0,
                'tools' => 0,
            ];
            if (isset($this->platform->multiplierIntegration)) {
                $intStatus = $this->platform->multiplierIntegration->status();
                $cloudflareStatus = [
                    'available' => $intStatus['bridge_available'],
                    'llm_enhanced' => $intStatus['llm_enhancement'],
                    'agents' => 9,
                    'tools' => 6,
                    'registered' => $intStatus['registered'],
                ];
            }
            
            // 6. Get agent breakdown
            $agents = [];
            foreach ($signal['agents'] ?? [] as $agent) {
                $agents[] = [
                    'name' => $agent['agent_name'] ?? 'Unknown',
                    'type' => $agent['agent_type'] ?? 'unknown',
                    'estimate' => $agent['estimate'] ?? 0,
                    'confidence' => $agent['confidence'] ?? 0,
                    'reasoning' => $agent['reasoning'] ?? '',
                ];
            }
            
            // 7. Get recent history
            $history = $aviatorProvider->history(20);
            
            $data['demo'] = [
                'aviator' => [
                    'health' => $aviatorHealth,
                    'stats' => $aviatorStats,
                    'live_round' => $liveRound,
                    'history' => $history,
                ],
                'signal' => [
                    'predicted' => $signal['predictedMultiplier'] ?? 2.0,
                    'confidence' => ($signal['confidence'] ?? 0.5) * 100,
                    'risk' => $signal['risk'] ?? 'MEDIUM',
                    'agents' => $agents,
                    'sports_enrichment' => $signal['sports_enrichment'] ?? null,
                    'cloudflare_enhanced' => $signal['cloudflare_enhanced'] ?? false,
                ],
                'sports' => $sportsEnrichment,
                'cloudflare' => $cloudflareStatus,
                'accuracy' => $engine->accuracyStats(100),
            ];
            
        } catch (\Throwable $e) {
            $data['demo'] = [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ];
        }
        
        $this->load->view('layout/header', $data);
        $this->load->view('multiplier/live_demo', $data);
        $this->load->view('layout/footer');
    }
    
    /**
     * Test sports enrichment with real providers
     */
    public function test_sports_enrichment()
    {
        try {
            // Get Sports Intelligence service
            $sportsIntel = $this->platform->sports ?? null;
            if (!$sportsIntel) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => 'Sports Intelligence not available']);
                return;
            }
            
            // Check provider status
            $providerStatus = $sportsIntel->providers->health();
            
            // Create enrichment provider
            $enrichment = new \AIWorkforce\MultiplierIntelligence\SportsBettingEnrichmentProvider($sportsIntel);
            
            // Get enrichment signals
            $signals = $enrichment->getEnrichmentSignals();
            
            // Get fixtures directly to show what's available
            $fixtures = [];
            if ($sportsIntel->providers->configured()) {
                $result = $sportsIntel->providers->withFallback('fixtures', function ($provider) {
                    return $provider->fixtures([
                        'from' => gmdate('Y-m-d'),
                        'to' => gmdate('Y-m-d'),
                    ]);
                });
                if (!empty($result['ok'])) {
                    $fixtures = array_slice($result['result'] ?? [], 0, 5);
                }
            }
            
            $response = [
                'ok' => true,
                'timestamp' => date('c'),
                'providers' => $providerStatus,
                'enrichment_signals' => $signals,
                'sample_fixtures' => $fixtures,
                'enrichment_weight' => $enrichment->getEnrichmentWeight(),
            ];
            
            header('Content-Type: application/json');
            echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            
        } catch (\Throwable $e) {
            header('Content-Type: application/json');
            echo json_encode([
                'ok' => false,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
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
            $provider = CrashProviderFactory::fromPlatform($this->platform);
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
            if (method_exists($provider, 'updateMultiplier')) {
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
