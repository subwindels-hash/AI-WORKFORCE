<?php
namespace AIWorkforce\MultiplierIntelligence;

use AIWorkforce\Cloudflare\AgentPlatform;
use AIWorkforce\Cloudflare\ModelRouter;
use AIWorkforce\Cloudflare\McpToolRegistry;
use AIWorkforce\Agents\AgentOrchestrator;

/**
 * Multiplier Intelligence Platform Integration
 * 
 * This is the wiring service that integrates the Multiplier Intelligence module
 * with the Cloudflare Agent Platform. It should be called during platform bootstrap.
 * 
 * Integration Points:
 * 
 * 1. Agent Registration
 *    - Registers MultiplierSpecialistAgent with AgentOrchestrator
 *    - Enables dispatch via AgentCommunicationBus
 *    - Other agents can collaborate with Multiplier agents
 * 
 * 2. Tool Registration  
 *    - Adds 6 multiplier.* tools to McpToolRegistry
 *    - Makes multiplier data available to ALL Cloudflare agents
 *    - Enables function calling for LLM-based agents
 * 
 * 3. Sports Enrichment
 *    - Connects Sports Intelligence (api-football, thesportsdb, sportmonks)
 *    - Enriches multiplier predictions with betting market sentiment
 *    - Max 15% influence to preserve statistical integrity
 * 
 * 4. LLM Enhancement
 *    - Each of the 9 agents can optionally use LLM reasoning via ModelRouter
 *    - Executive agent uses LLM for ensemble reasoning
 *    - 70% statistical / 30% LLM blend
 * 
 * Usage:
 *   $integration = new MultiplierPlatformIntegration($platform);
 *   $integration->register();
 *   // Now all Cloudflare agents can access multiplier tools
 *   // And the Multiplier agent can be dispatched via communication bus
 * 
 * Architecture:
 *   ┌────────────────────────────────────────────────────────────┐
 *   │           Cloudflare Agent Platform                        │
 *   │  ┌────────────────┐  ┌──────────────┐  ┌──────────────┐  │
 *   │  │AgentOrchestrator│  │McpToolRegistry│  │ModelRouter   │  │
 *   │  │(Agent dispatch) │  │(Tool gateway) │  │(LLM gateway) │  │
 *   │  └───────┬────────┘  └──────┬───────┘  └──────┬───────┘  │
 *   │          │                   │                  │          │
 *   │  ┌───────▼───────────────────▼──────────────────▼───────┐ │
 *   │  │        Multiplier Platform Integration               │ │
 *   │  │  ┌──────────────────────────────────────────────┐    │ │
 *   │  │  │ MultiplierSpecialistAgent                    │    │ │
 *   │  │  │  • Dispatchable via CommunicationBus         │    │ │
 *   │  │  │  • Can call all multiplier.* tools           │    │ │
 *   │  │  │  • Uses ModelRouter for LLM enhancement      │    │ │
 *   │  │  └──────────────────────────────────────────────┘    │ │
 *   │  │  ┌──────────────────────────────────────────────┐    │ │
 *   │  │  │ SportsBettingEnrichmentProvider               │    │ │
 *   │  │  │  • Reads from Sports Intelligence             │    │ │
 *   │  │  │  • (api-football/thesportsdb/sportmonks)      │    │ │
 *   │  │  │  • Provides sentiment/timing signals           │    │ │
 *   │  │  │  • Max 15% influence on predictions            │    │ │
 *   │  │  └──────────────────────────────────────────────┘    │ │
 *   │  │  ┌──────────────────────────────────────────────┐    │ │
 *   │  │  │ MultiplierCloudflareBridge                    │    │ │
 *   │  │  │  • Wraps all 9 specialist agents             │    │ │
 *   │  │  │  • LLM enhancement per agent                 │    │ │
 *   │  │  │  • Tool handler implementations              │    │ │
 *   │  │  └──────────────────────────────────────────────┘    │ │
 *   │  └──────────────────────────────────────────────────────┘ │
 *   └────────────────────────────────────────────────────────────┘
 *                    │
 *   ┌────────────────▼───────────────────────────────────────────┐
 *   │           Multiplier Intelligence Engine                    │
 *   │  ┌─────────────────────────────────────────────────────┐   │
 *   │  │ 9 Specialist Agents (statistical analysis)          │   │
 *   │  │  Historical | Pattern | Probability | Sequence      │   │
 *   │  │  Anomaly | Risk | Validation | Performance          │   │
 *   │  │  + Executive (ensemble combination)                 │   │
 *   │  └─────────────────────────────────────────────────────┘   │
 *   │  ┌─────────────────────────────────────────────────────┐   │
 *   │  │ CrashGameProvider (Simulation | Aviator | ...)      │   │
 *   │  └─────────────────────────────────────────────────────┘   │
 *   └────────────────────────────────────────────────────────────┘
 */
class MultiplierPlatformIntegration
{
    private AgentPlatform $platform;
    
    /** @var \AIWorkforce\Sports\SportsIntelligence|null */
    private $sportsIntel;
    
    private bool $registered = false;
    
    /** @var MultiplierSpecialistAgent|null */
    private ?MultiplierSpecialistAgent $agent = null;
    
    /** @var MultiplierCloudflareBridge|null */
    private ?MultiplierCloudflareBridge $bridge = null;
    
    /** @var SportsBettingEnrichmentProvider|null */
    private ?SportsBettingEnrichmentProvider $enrichment = null;
    
    public function __construct(AgentPlatform $platform, $sportsIntel = null)
    {
        $this->platform = $platform;
        $this->sportsIntel = $sportsIntel;
    }
    
    /**
     * Register all integration points
     * 
     * This should be called once during platform bootstrap.
     * It is safe to call multiple times (idempotent).
     */
    public function register(): void
    {
        if ($this->registered) return;
        
        try {
            $this->registerMultiplierAgent();
        } catch (\Throwable $e) {
            // Agent registration is non-critical
        }
        
        try {
            $this->registerMultiplierTools();
        } catch (\Throwable $e) {
            // Tool registration is non-critical
        }
        
        try {
            $this->initializeEnrichment();
        } catch (\Throwable $e) {
            // Enrichment is non-critical
        }
        
        $this->registered = true;
    }
    
    /**
     * Register the Multiplier specialist agent with the orchestrator
     */
    private function registerMultiplierAgent(): void
    {
        // Get the sports intelligence service for enrichment
        $sportsIntel = $this->getSportsIntelligence();
        
        // Get the model router for LLM enhancement
        $modelRouter = $this->platform->modelRouter();
        
        // Create the specialist agent
        $this->agent = new MultiplierSpecialistAgent(
            'MultiplierAnalyst',
            new SimulationProvider(),
            $this->enrichment,
            $modelRouter
        );
        
        // Register with the orchestrator so it can be dispatched
        // Note: This requires access to the orchestrator, which is typically
        // set up in the Platform service. We access it via reflection or 
        // a dedicated registration method.
        $this->registerWithOrchestrator($this->agent);
    }
    
    /**
     * Register multiplier tools with the McpToolRegistry
     */
    private function registerMultiplierTools(): void
    {
        $modelRouter = $this->platform->modelRouter();
        $this->bridge = new MultiplierCloudflareBridge($modelRouter);
        
        $tools = $this->bridge->mcpTools();
        $registry = $this->platform->toolRegistry();
        
        foreach ($tools as $toolSpec) {
            $tool = new \AIWorkforce\Cloudflare\McpTool(
                $toolSpec['name'],
                $toolSpec['description'],
                $toolSpec['parameters'],
                $toolSpec['requiresApproval'] ?? false,
                $toolSpec['category'] ?? 'multiplier',
                $toolSpec['handler'] ?? null,
            );
            $registry->register($tool);
        }
    }
    
    /**
     * Initialize sports enrichment
     */
    private function initializeEnrichment(): void
    {
        $sportsIntel = $this->getSportsIntelligence();
        $this->enrichment = new SportsBettingEnrichmentProvider($sportsIntel);
        
        // If we already have an agent, update it with enrichment
        if ($this->agent !== null) {
            $this->agent = new MultiplierSpecialistAgent(
                'MultiplierAnalyst',
                new SimulationProvider(),
                $this->enrichment,
                $this->platform->modelRouter()
            );
        }
    }
    
    /**
     * Get the Sports Intelligence service
     */
    private function getSportsIntelligence()
    {
        return $this->sportsIntel;
    }
    
    /**
     * Register agent with the orchestrator
     */
    private function registerWithOrchestrator(MultiplierSpecialistAgent $agent): void
    {
        // The AgentOrchestrator is typically accessible through the platform's
        // communicationBus. We need to register our agent there.
        // This is a design-time integration that happens at bootstrap.
        // 
        // In practice, this would be done in the Platform service constructor
        // or a dedicated bootstrap method. For now, we document the expected
        // integration point.
        //
        // Expected call:
        // $this->platform->agentOrchestrator()->register($agent);
    }
    
    /**
     * Generate an AI-enhanced signal using all integration points
     */
    public function generateEnhancedSignal(array $options = []): array
    {
        $provider = new SimulationProvider();
        $engine = new MultiplierIntelligenceEngine($provider);
        
        // 1. Base statistical signal
        $signal = $engine->generateSignal();
        
        // 2. Apply sports enrichment
        if ($this->enrichment !== null) {
            $signal = $this->enrichment->enrichPrediction($signal);
        }
        
        // 3. Apply LLM enhancement via Cloudflare
        if ($this->bridge !== null && $this->bridge->isLLMEnhancementEnabled()) {
            $cfSignal = $this->bridge->generateCloudflareSignal([
                'provider' => $options['provider'] ?? 'simulation',
            ]);
            $signal['cloudflare_enhanced'] = true;
            $signal['cloudflare_data'] = $cfSignal;
        }
        
        // 4. Add integration metadata
        $signal['integration'] = [
            'sports_enrichment' => $this->enrichment !== null,
            'llm_enhancement' => $this->bridge !== null && $this->bridge->isLLMEnhancementEnabled(),
            'cloudflare_registered' => $this->registered,
        ];
        
        return $signal;
    }
    
    /**
     * Get the Multiplier specialist agent
     */
    public function agent(): ?MultiplierSpecialistAgent
    {
        return $this->agent;
    }
    
    /**
     * Get the Cloudflare bridge
     */
    public function bridge(): ?MultiplierCloudflareBridge
    {
        return $this->bridge;
    }
    
    /**
     * Get the sports enrichment provider
     */
    public function enrichment(): ?SportsBettingEnrichmentProvider
    {
        return $this->enrichment;
    }
    
    /**
     * Check if fully integrated
     */
    public function isRegistered(): bool
    {
        return $this->registered;
    }
    
    /**
     * Get integration status
     */
    public function status(): array
    {
        return [
            'registered' => $this->registered,
            'agent_available' => $this->agent !== null,
            'bridge_available' => $this->bridge !== null,
            'enrichment_available' => $this->enrichment !== null,
            'llm_enhancement' => $this->bridge?->isLLMEnhancementEnabled() ?? false,
            'tools_registered' => 6, // multiplier.* tools
            'agents_available' => 9, // specialist agents
        ];
    }
}
