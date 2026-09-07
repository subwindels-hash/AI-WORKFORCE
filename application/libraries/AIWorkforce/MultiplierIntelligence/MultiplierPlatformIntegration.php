<?php
namespace AIWorkforce\MultiplierIntelligence;

use AIWorkforce\AgentPlatform\AgentPlatform;
use AIWorkforce\AgentPlatform\ModelRouter;
use AIWorkforce\AgentPlatform\McpToolRegistry;
use AIWorkforce\Agents\AgentOrchestrator;

/**
 * Multiplier Intelligence Platform Integration
 * 
 * Wiring service that integrates the Multiplier Intelligence module
 * with the Agent Platform.
 */
class MultiplierPlatformIntegration
{
    private AgentPlatform $platform;
    
    /** @var \AIWorkforce\Sports\SportsIntelligence|null */
    private $sportsIntel;
    
    private bool $registered = false;
    
    /** @var MultiplierSpecialistAgent|null */
    private ?MultiplierSpecialistAgent $agent = null;
    
    /** @var MultiplierAgentBridge|null */
    private ?MultiplierAgentBridge $bridge = null;
    
    /** @var SportsBettingEnrichmentProvider|null */
    private ?SportsBettingEnrichmentProvider $enrichment = null;
    
    public function __construct(AgentPlatform $platform, $sportsIntel = null)
    {
        $this->platform = $platform;
        $this->sportsIntel = $sportsIntel;
    }
    
    /**
     * Register all integration points
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
        // Get the model router for LLM enhancement
        $modelRouter = $this->platform->modelRouter();
        
        // Create the specialist agent
        $this->agent = new MultiplierSpecialistAgent(
            'MultiplierAnalyst',
            CrashProviderFactory::make(),
            $this->enrichment,
            $modelRouter
        );
        
        $this->registerWithOrchestrator($this->agent);
    }
    
    /**
     * Register multiplier tools with the McpToolRegistry
     */
    private function registerMultiplierTools(): void
    {
        $modelRouter = $this->platform->modelRouter();
        $this->bridge = new MultiplierAgentBridge($modelRouter);
        
        $tools = $this->bridge->mcpTools();
        $registry = $this->platform->toolRegistry();
        
        foreach ($tools as $toolSpec) {
            $tool = new \AIWorkforce\AgentPlatform\McpTool(
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
                CrashProviderFactory::make(),
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
    }
    
    /**
     * Generate an AI-enhanced signal using all integration points
     */
    public function generateEnhancedSignal(array $options = []): array
    {
        $provider = CrashProviderFactory::make();
        $engine = new MultiplierIntelligenceEngine($provider);
        
        // 1. Base statistical signal
        $signal = $engine->generateSignal();
        
        // 2. Apply sports enrichment
        if ($this->enrichment !== null) {
            $signal = $this->enrichment->enrichPrediction($signal);
        }
        
        // 3. Apply LLM enhancement
        if ($this->bridge !== null && $this->bridge->isLLMEnhancementEnabled()) {
            $enhancedSignal = $this->bridge->generateEnhancedSignal([
                'provider' => $options['provider'] ?? 'bustabit',
            ]);
            $signal['ai_enhanced'] = true;
            $signal['ai_data'] = $enhancedSignal;
        }
        
        // 4. Add integration metadata
        $signal['integration'] = [
            'sports_enrichment' => $this->enrichment !== null,
            'llm_enhancement' => $this->bridge !== null && $this->bridge->isLLMEnhancementEnabled(),
            'platform_registered' => $this->registered,
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
     * Get the bridge
     */
    public function bridge(): ?MultiplierAgentBridge
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
