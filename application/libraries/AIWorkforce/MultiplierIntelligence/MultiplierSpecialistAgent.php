<?php
namespace AIWorkforce\MultiplierIntelligence;

use AIWorkforce\Agents\SpecialistAgent;

/**
 * Cloudflare Specialist Agent Adapter for Multiplier Intelligence
 * 
 * Wraps the Multiplier Intelligence system as a SpecialistAgent so it can be
 * dispatched through the Cloudflare AgentCommunicationBus alongside other agents.
 * 
 * This enables scenarios like:
 * - A user asks "What's the next multiplier signal?" → routes to this agent
 * - Cross-module analysis: Sports agent + Multiplier agent collaborate
 * - Agent debates: Risk agent from Trading + Risk agent from Multiplier
 * - Workflow orchestration: Multiplier signal → validation → notification
 * 
 * Registered tools: All multiplier.* tools
 */
final class MultiplierSpecialistAgent implements SpecialistAgent
{
    private string $role;
    private CrashGameProviderInterface $provider;
    
    /** @var object|null Sports enrichment provider */
    private $sportsEnrichment;
    
    /** @var \AIWorkforce\Cloudflare\ModelRouter|null */
    private $modelRouter;
    
    private bool $useLLM = false;
    
    public function __construct(
        string $role = 'MultiplierAnalyst',
        ?CrashGameProviderInterface $provider = null,
        $sportsEnrichment = null,
        $modelRouter = null
    ) {
        $this->role = $role;
        $this->provider = $provider ?? CrashProviderFactory::make();
        $this->sportsEnrichment = $sportsEnrichment;
        $this->modelRouter = $modelRouter;
        $this->useLLM = ($modelRouter !== null);
    }
    
    public function name(): string
    {
        return $this->role;
    }
    
    public function tools(): array
    {
        return [
            'multiplier.getCurrentMultiplier',
            'multiplier.getHistory',
            'multiplier.generateSignal',
            'multiplier.getAccuracy',
            'multiplier.listAgents',
            'multiplier.analyzeRound',
        ];
    }
    
    public function handle(array $request, array $context): array
    {
        $instruction = $request['instruction'] ?? 'Generate a multiplier signal';
        $facts = $request['facts'] ?? [];
        
        // Build the engine
        $engine = new MultiplierIntelligenceEngine($this->provider);
        
        // Generate base signal
        $signal = $engine->generateSignal();
        
        // Apply sports enrichment if available
        if ($this->sportsEnrichment !== null) {
            try {
                $signal = $this->sportsEnrichment->enrichPrediction($signal);
            } catch (\Throwable $e) {
                $signal['sports_enrichment'] = ['applied' => false, 'error' => $e->getMessage()];
            }
        }
        
        // Apply LLM enhancement if available
        if ($this->useLLM && $this->modelRouter !== null) {
            try {
                $bridge = new MultiplierCloudflareBridge($this->modelRouter);
                $signal['cloudflare_enhanced'] = true;
                $signal['cloudflare_signal'] = $bridge->generateCloudflareSignal([
                    'provider' => $this->provider->code(),
                ]);
            } catch (\Throwable $e) {
                $signal['cloudflare_enhanced'] = false;
                $signal['cloudflare_error'] = $e->getMessage();
            }
        }
        
        // Build answer for the AgentCommunicationBus
        $answer = $this->formatAnswer($signal, $instruction);
        
        return [
            'status' => 'COMPLETED',
            'role' => $this->role,
            'answer' => $answer,
            'signal' => $signal,
            'tools' => $this->tools(),
            'provider' => $this->provider->code(),
            'llm_enhanced' => $this->useLLM,
            'sports_enriched' => ($this->sportsEnrichment !== null),
        ];
    }
    
    /**
     * Format a human-readable answer from the signal
     */
    private function formatAnswer(array $signal, string $instruction): string
    {
        $predicted = $signal['predictedMultiplier'] ?? 2.0;
        $confidence = ($signal['confidence'] ?? 0.5) * 100;
        $risk = $signal['risk'] ?? 'MEDIUM';
        $agents = count($signal['agents'] ?? []);
        
        $lines = [];
        $lines[] = "Multiplier Intelligence Analysis:";
        $lines[] = sprintf("- Predicted Next Multiplier: %.2fx", $predicted);
        $lines[] = sprintf("- Confidence: %.0f%%", $confidence);
        $lines[] = sprintf("- Risk Level: %s", $risk);
        $lines[] = sprintf("- Agents Consulted: %d", $agents);
        
        if (!empty($signal['sports_enrichment']['applied'])) {
            $enrichment = $signal['sports_enrichment'];
            $lines[] = sprintf("- Sports Enrichment: %.2fx → %.2fx (%s)", 
                $enrichment['original'] ?? $predicted,
                $enrichment['enriched'] ?? $predicted,
                implode(', ', $enrichment['reasons'] ?? [])
            );
        }
        
        if (!empty($signal['cloudflare_enhanced'])) {
            $lines[] = "- Cloudflare AI Enhanced: Yes (LLM reasoning applied)";
        }
        
        $lines[] = "";
        $lines[] = "⚠️ Disclaimer: Crash games use provably fair RNG. This analysis is statistical";
        $lines[] = "and educational only. No prediction is guaranteed.";
        
        return implode("\n", $lines);
    }
}
