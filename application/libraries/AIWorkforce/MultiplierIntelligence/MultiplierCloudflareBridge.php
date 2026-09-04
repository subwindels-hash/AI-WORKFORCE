<?php
namespace AIWorkforce\MultiplierIntelligence;

use AIWorkforce\Cloudflare\ModelRouter;

/**
 * Multiplier Cloudflare Bridge
 * 
 * Integrates the 9 Multiplier specialist agents with Cloudflare Agent Platform:
 * 
 * 1. LLM Enhancement: Each agent can optionally call an LLM through ModelRouter
 *    to enhance its statistical analysis with AI reasoning
 * 
 * 2. Tool Registration: Multiplier tools become available to ALL Cloudflare agents
 *    (e.g., a sports analyst agent can query crash game data)
 * 
 * 3. Agent Dispatch: Multiplier agents can be invoked through the AgentCommunicationBus
 *    alongside other specialist agents
 * 
 * Architecture:
 *   ┌──────────────────────────────────────────────┐
 *   │  Cloudflare Agent Platform                    │
 *   │  ┌─────────────┐  ┌───────────────────────┐  │
 *   │  │ModelRouter   │  │McpToolRegistry        │  │
 *   │  │(LLM gateway) │  │(Tool discovery)       │  │
 *   │  └──────┬───────┘  └──────────┬────────────┘  │
 *   │         │                      │               │
 *   │  ┌──────▼──────────────────────▼────────────┐ │
 *   │  │  Multiplier Cloudflare Bridge            │ │
 *   │  │  - LLM-enhanced agent reasoning          │ │
 *   │  │  - Tool registration (multiplier.*)      │ │
 *   │  │  - Agent dispatch via CommunicationBus   │ │
 *   │  └──────┬──────────────────────┬────────────┘ │
 *   │         │                      │               │
 *   │  ┌──────▼───────┐  ┌──────────▼────────────┐ │
 *   │  │9 Multiplier  │  │ CrashGameProvider     │ │
 *   │  │Agents        │  │ (Simulation/Aviator)  │ │
 *   │  └──────────────┘  └───────────────────────┘ │
 *   └──────────────────────────────────────────────┘
 */
class MultiplierCloudflareBridge
{
    private ModelRouter $modelRouter;
    
    /** @var array Multiplier agent instances for dispatch */
    private array $multiplierAgents = [];
    
    private bool $llmEnhancementEnabled = true;
    
    public function __construct(ModelRouter $modelRouter)
    {
        $this->modelRouter = $modelRouter;
        $this->initializeMultiplierAgents();
    }
    
    /**
     * Initialize all 9 multiplier agents for Cloudflare dispatch
     */
    private function initializeMultiplierAgents(): void
    {
        $this->multiplierAgents = [
            'multiplier.historical' => new HistoricalAnalysisAgent(),
            'multiplier.pattern' => new PatternDetectionAgent(),
            'multiplier.probability' => new ProbabilityAgent(),
            'multiplier.sequence' => new SequenceAnalysisAgent(),
            'multiplier.anomaly' => new AnomalyDetectionAgent(),
            'multiplier.risk' => new RiskAgent(),
            'multiplier.validation' => new ValidationAgent(),
            'multiplier.performance' => new PerformanceAgent(),
            'multiplier.prediction' => new PredictionAgent(),
        ];
    }
    
    /**
     * Get all multiplier agent descriptors for the AgentCommunicationBus
     */
    public function agentDescriptors(): array
    {
        $descriptors = [];
        foreach ($this->multiplierAgents as $key => $agent) {
            $descriptors[] = [
                'name' => $key,
                'displayName' => $agent->name(),
                'type' => $agent->type(),
                'description' => $agent->description(),
                'domain' => 'crash_game',
                'capabilities' => ['multiplier_analysis', 'pattern_detection', 'risk_assessment'],
            ];
        }
        return $descriptors;
    }
    
    /**
     * Dispatch to a specific multiplier agent (called by AgentCommunicationBus)
     */
    public function dispatch(string $agentName, array $request, array $context = []): array
    {
        if (!isset($this->multiplierAgents[$agentName])) {
            return ['ok' => false, 'error' => 'Unknown multiplier agent: ' . $agentName];
        }
        
        $agent = $this->multiplierAgents[$agentName];
        
        // Get crash game data from provider
        $provider = $this->getProvider($context);
        $history = $provider->history(200);
        $multipliers = array_column($history, 'multiplier');
        
        // Build analysis context
        $analysisContext = [
            'multipliers' => $multipliers,
            'rounds' => $history,
            'features' => $this->extractFeatures($multipliers),
            'provider' => $provider->code(),
            'currentRound' => $provider->latestRound(),
            'isInRound' => $provider->isInRound(),
        ];
        
        // Run the agent's statistical analysis
        $statResult = $agent->analyze($analysisContext);
        
        // Optionally enhance with LLM reasoning
        if ($this->llmEnhancementEnabled) {
            try {
                $llmResult = $this->enhanceWithLLM($agent, $statResult, $analysisContext, $request);
                $statResult['llm_enhancement'] = $llmResult;
                if (!empty($llmResult['adjusted_estimate'])) {
                    $statResult['estimate'] = $llmResult['adjusted_estimate'];
                }
            } catch (\Throwable $e) {
                $statResult['llm_enhancement'] = ['status' => 'UNAVAILABLE', 'reason' => $e->getMessage()];
            }
        }
        
        return [
            'ok' => true,
            'agent' => $agentName,
            'result' => $statResult,
        ];
    }
    
    /**
     * Generate a full signal using all agents + LLM enhancement
     * This is the main entry point for Cloudflare-enhanced predictions
     */
    public function generateCloudflareSignal(array $context = []): array
    {
        $provider = $this->getProvider($context);
        $engine = new MultiplierIntelligenceEngine($provider);
        
        // Get base signal from statistical agents
        $baseSignal = $engine->generateSignal();
        
        // Enhance with LLM reasoning if available
        if ($this->llmEnhancementEnabled && $this->modelRouter->configured()) {
            try {
                $llmEnhancement = $this->ensembleLLMReasoning($baseSignal, $context);
                if (!empty($llmEnhancement)) {
                    $baseSignal['cloudflare_enhanced'] = true;
                    $baseSignal['llm_analysis'] = $llmEnhancement;
                    
                    // Blend LLM suggestion with statistical prediction
                    if (isset($llmEnhancement['suggested_multiplier'])) {
                        $statWeight = 0.7; // 70% statistical, 30% LLM
                        $llmWeight = 0.3;
                        $blended = ($baseSignal['predictedMultiplier'] * $statWeight) 
                                 + ($llmEnhancement['suggested_multiplier'] * $llmWeight);
                        $baseSignal['predictedMultiplier'] = round(max(1.01, $blended), 2);
                    }
                }
            } catch (\Throwable $e) {
                $baseSignal['cloudflare_enhanced'] = false;
                $baseSignal['enhancement_error'] = $e->getMessage();
            }
        }
        
        return $baseSignal;
    }
    
    /**
     * Enhance a single agent's output with LLM reasoning
     */
    private function enhanceWithLLM(
        MultiplierAgentInterface $agent,
        array $statResult,
        array $analysisContext,
        array $request
    ): array {
        if (!$this->modelRouter->configured()) {
            return ['status' => 'NO_MODEL_PROVIDER'];
        }
        
        $agentName = $agent->name();
        $agentType = $agent->type();
        
        // Build prompt for LLM
        $features = $analysisContext['features'] ?? [];
        $recentMultipliers = array_slice($analysisContext['multipliers'] ?? [], -10);
        
        $prompt = "You are the {$agentName} in a crash game analytics system.

Your statistical analysis produced:
- Estimate: " . ($statResult['estimate'] ?? 'N/A') . "x
- Confidence: " . (($statResult['confidence'] ?? 0) * 100) . "%
- Reasoning: " . ($statResult['reasoning'] ?? 'N/A') . "

Recent multipliers (last 10): " . implode(', ', array_map(fn($m) => $m . 'x', $recentMultipliers)) . "

Statistical features:
- Mean: " . round($features['mean'] ?? 0, 2) . "
- Median: " . round($features['median'] ?? 0, 2) . "  
- StdDev: " . round($features['stddev'] ?? 0, 2) . "
- Trend: " . ($features['trend'] ?? 'unknown') . "

Based on your statistical analysis and these patterns, provide a JSON response:
{\"adjusted_estimate\": <number>, \"reasoning\": \"<brief explanation>\", \"confidence_adjustment\": <number between -0.2 and 0.2>}

IMPORTANT: You are enhancing a statistical model, not replacing it. Stay grounded in the data.";

        $response = $this->modelRouter->complete([
            ['role' => 'system', 'content' => 'You are a crash game analytics specialist. Respond ONLY with valid JSON. Never guarantee outcomes. Always ground predictions in statistical evidence.'],
            ['role' => 'user', 'content' => $prompt],
        ], [
            'max_tokens' => 300,
            'temperature' => 0.3,
            'agent' => 'multiplier.' . $agentType,
        ]);
        
        if (empty($response['text'])) {
            return ['status' => 'MODEL_FAILED'];
        }
        
        // Parse JSON response
        $parsed = $this->parseJSON($response['text']);
        if ($parsed === null) {
            return ['status' => 'PARSE_ERROR', 'raw' => $response['text']];
        }
        
        return array_merge($parsed, [
            'status' => 'COMPLETED',
            'model_used' => $response['model'] ?? 'unknown',
            'tokens' => $response['tokens'] ?? 0,
        ]);
    }
    
    /**
     * Use LLM for ensemble reasoning across all agent outputs
     */
    private function ensembleLLMReasoning(array $baseSignal, array $context): array
    {
        if (!$this->modelRouter->configured()) {
            return [];
        }
        
        // Build summary of all agent analyses
        $agentSummaries = [];
        foreach ($baseSignal['agents'] ?? [] as $a) {
            $agentSummaries[] = sprintf(
                '- %s: estimate=%.2fx, confidence=%d%%, reasoning=%s',
                $a['agent_name'] ?? 'Unknown',
                $a['estimate'] ?? 0,
                ($a['confidence'] ?? 0) * 100,
                $a['reasoning'] ?? 'N/A'
            );
        }
        
        $prompt = "You are the Executive Decision agent for a crash game analytics system.

Statistical ensemble produced:
- Predicted Multiplier: " . ($baseSignal['predictedMultiplier'] ?? 'N/A') . "x
- Confidence: " . (($baseSignal['confidence'] ?? 0) * 100) . "%
- Risk Level: " . ($baseSignal['risk'] ?? 'MEDIUM') . "
- Agent Agreement: " . ($baseSignal['agentAgreement'] ?? 'N/A') . "

Individual Agent Analyses:
" . implode("\n", $agentSummaries) . "

Features:
- Mean: " . round($baseSignal['features']['mean'] ?? 0, 2) . "
- Trend: " . ($baseSignal['features']['trend'] ?? 'unknown') . "
- Anomalies: " . ($baseSignal['features']['anomaly_count'] ?? 0) . "

Provide a JSON response with your assessment:
{\"suggested_multiplier\": <number>, \"reasoning\": \"<explanation>\", \"confidence_override\": <0.0-1.0>, \"risk_assessment\": \"<LOW|MEDIUM|HIGH|EXTREME>\"}

Remember: Crash games use provably fair random number generation. Your analysis should be cautious and evidence-based. Never claim certainty.";

        $response = $this->modelRouter->complete([
            ['role' => 'system', 'content' => 'You are an expert crash game analyst. You combine statistical evidence with pattern recognition. Respond ONLY with valid JSON. Always include disclaimers about randomness.'],
            ['role' => 'user', 'content' => $prompt],
        ], [
            'max_tokens' => 400,
            'temperature' => 0.3,
            'agent' => 'multiplier.executive',
        ]);
        
        if (empty($response['text'])) {
            return [];
        }
        
        return $this->parseJSON($response['text']) ?? [];
    }
    
    /**
     * Get tools for McpToolRegistry registration
     */
    public function mcpTools(): array
    {
        return [
            [
                'name' => 'multiplier.getCurrentMultiplier',
                'description' => 'Get the current live multiplier value from a crash game',
                'parameters' => [
                    'provider' => ['type' => 'string', 'default' => 'bustabit', 'description' => 'Game provider (bustabit live, or simulation)'],
                ],
                'category' => 'multiplier',
                'requiresApproval' => false,
                'handler' => fn($args) => $this->toolGetCurrentMultiplier($args),
            ],
            [
                'name' => 'multiplier.getHistory',
                'description' => 'Get historical multiplier data from a crash game provider',
                'parameters' => [
                    'provider' => ['type' => 'string', 'default' => 'bustabit'],
                    'limit' => ['type' => 'integer', 'default' => 50, 'description' => 'Number of rounds to return'],
                ],
                'category' => 'multiplier',
                'requiresApproval' => false,
                'handler' => fn($args) => $this->toolGetHistory($args),
            ],
            [
                'name' => 'multiplier.generateSignal',
                'description' => 'Generate an AI-powered multiplier prediction signal using 9 specialist agents',
                'parameters' => [
                    'provider' => ['type' => 'string', 'default' => 'bustabit'],
                    'model' => ['type' => 'string', 'default' => 'MIXED-ENSEMBLE-v1'],
                ],
                'category' => 'multiplier',
                'requiresApproval' => false,
                'handler' => fn($args) => $this->toolGenerateSignal($args),
            ],
            [
                'name' => 'multiplier.getAccuracy',
                'description' => 'Get accuracy statistics for multiplier predictions',
                'parameters' => [
                    'window' => ['type' => 'integer', 'default' => 100, 'description' => 'Number of predictions to evaluate'],
                ],
                'category' => 'multiplier',
                'requiresApproval' => false,
                'handler' => fn($args) => $this->toolGetAccuracy($args),
            ],
            [
                'name' => 'multiplier.listAgents',
                'description' => 'List all 9 specialist AI agents in the Multiplier Intelligence system',
                'parameters' => [],
                'category' => 'multiplier',
                'requiresApproval' => false,
                'handler' => fn($args) => $this->toolListAgents($args),
            ],
            [
                'name' => 'multiplier.analyzeRound',
                'description' => 'Run a specific multiplier agent analysis on historical data',
                'parameters' => [
                    'agent' => ['type' => 'string', 'required' => true, 'description' => 'Agent name (historical, pattern, probability, sequence, anomaly, risk)'],
                    'provider' => ['type' => 'string', 'default' => 'bustabit'],
                ],
                'category' => 'multiplier',
                'requiresApproval' => false,
                'handler' => fn($args) => $this->toolAnalyzeRound($args),
            ],
        ];
    }
    
    // ── Tool Handlers ──────────────────────────────────────────────
    
    private function toolGetCurrentMultiplier(array $args): array
    {
        $provider = $this->getProvider(['provider' => $args['provider'] ?? 'bustabit']);
        $roundData = method_exists($provider, 'updateMultiplier')
            ? $provider->updateMultiplier()
            : ['currentMultiplier' => $provider->currentMultiplier() ?? 1.0, 'roundId' => null, 'inRound' => $provider->isInRound()];
        return [
            'currentMultiplier' => $roundData['currentMultiplier'] ?? 1.0,
            'roundId' => $roundData['roundId'] ?? null,
            'inRound' => $roundData['inRound'] ?? false,
            'provider' => $provider->code(),
        ];
    }
    
    private function toolGetHistory(array $args): array
    {
        $provider = $this->getProvider(['provider' => $args['provider'] ?? 'bustabit']);
        $limit = (int)($args['limit'] ?? 50);
        $history = $provider->history($limit);
        return [
            'rounds' => $history,
            'count' => count($history),
            'provider' => $provider->code(),
        ];
    }
    
    private function toolGenerateSignal(array $args): array
    {
        $provider = $this->getProvider(['provider' => $args['provider'] ?? 'bustabit']);
        $engine = new MultiplierIntelligenceEngine($provider);
        
        // Use Cloudflare-enhanced signal if LLM is available
        if ($this->llmEnhancementEnabled && $this->modelRouter->configured()) {
            return $this->generateCloudflareSignal(['provider' => $args['provider'] ?? 'bustabit']);
        }
        
        return $engine->generateSignal($args['model'] ?? null);
    }
    
    private function toolGetAccuracy(array $args): array
    {
        $provider = $this->getProvider(['provider' => 'bustabit']);
        $engine = new MultiplierIntelligenceEngine($provider);
        $window = (int)($args['window'] ?? 100);
        return $engine->accuracyStats($window);
    }
    
    private function toolListAgents(array $args): array
    {
        return [
            'agents' => $this->agentDescriptors(),
            'totalAgents' => count($this->multiplierAgents),
            'system' => 'Multiplier Intelligence',
        ];
    }
    
    private function toolAnalyzeRound(array $args): array
    {
        $agentKey = 'multiplier.' . ($args['agent'] ?? 'historical');
        return $this->dispatch($agentKey, ['instruction' => 'Analyze current data'], ['provider' => $args['provider'] ?? 'bustabit']);
    }
    
    // ── Helpers ────────────────────────────────────────────────────
    
    private function getProvider(array $context): CrashGameProviderInterface
    {
        $code = $context['provider'] ?? 'bustabit';
        return CrashProviderFactory::make(['code' => $code]);
    }
    
    private function extractFeatures(array $multipliers): array
    {
        if (count($multipliers) < 5) return [];
        
        $mean = array_sum($multipliers) / count($multipliers);
        sort($multipliers);
        $median = $multipliers[(int)(count($multipliers) / 2)];
        
        $variance = 0;
        foreach ($multipliers as $m) $variance += ($m - $mean) ** 2;
        $stddev = sqrt($variance / count($multipliers));
        
        // Simple trend
        $recent = array_slice($multipliers, -5);
        $trend = 'stable';
        if (count($recent) >= 2) {
            $avgRecent = array_sum($recent) / count($recent);
            $older = array_slice($multipliers, -10, -5);
            $avgOlder = count($older) > 0 ? array_sum($older) / count($older) : $avgRecent;
            if ($avgRecent > $avgOlder * 1.1) $trend = 'rising';
            elseif ($avgRecent < $avgOlder * 0.9) $trend = 'falling';
        }
        
        return [
            'mean' => $mean,
            'median' => $median,
            'stddev' => $stddev,
            'trend' => $trend,
            'count' => count($multipliers),
            'anomaly_count' => count(array_filter($multipliers, fn($m) => abs($m - $mean) > 2 * $stddev)),
        ];
    }
    
    private function parseJSON(string $text): ?array
    {
        // Try to extract JSON from markdown code blocks
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $text, $m)) {
            $text = $m[1];
        }
        // Try to extract JSON object from text
        if (preg_match('/\{[\s\S]*\}/', $text, $m)) {
            $text = $m[0];
        }
        
        $decoded = json_decode($text, true);
        return is_array($decoded) ? $decoded : null;
    }
    
    public function enableLLMEnhancement(bool $enabled): void
    {
        $this->llmEnhancementEnabled = $enabled;
    }
    
    public function isLLMEnhancementEnabled(): bool
    {
        return $this->llmEnhancementEnabled;
    }
}
