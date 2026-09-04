<?php
namespace AIWorkforce;

use AIWorkforce\Providers\CloudflareProvider;

/**
 * Cloudflare Agent Runtime
 * 
 * Integrates Cloudflare Workers AI with the WINDELS agent system.
 * Provides agent execution, tool calling, and multi-agent orchestration
 * powered by Cloudflare's edge AI infrastructure.
 * 
 * Features:
 * - Agent execution with Cloudflare LLMs
 * - Tool calling with approval workflow
 * - Multi-agent orchestration
 * - Conversation memory
 * - Streaming responses (future)
 */
class CloudflareAgentRuntime
{
    private CloudflareProvider $provider;
    private array $agents = [];
    private array $tools = [];
    private string $toolPolicy;
    
    public function __construct(array $config)
    {
        $this->provider = new CloudflareProvider($config);
        $this->toolPolicy = $config['tool_policy'] ?? 'approval_required';
        $this->registerDefaultAgents();
        $this->registerDefaultTools();
    }
    
    /**
     * Check if runtime is properly configured
     */
    public function isConfigured(): bool
    {
        return $this->provider->isConfigured();
    }
    
    /**
     * Get runtime status
     */
    public function status(): array
    {
        return [
            'provider' => $this->provider->status(),
            'agents' => array_keys($this->agents),
            'tools' => array_keys($this->tools),
            'toolPolicy' => $this->toolPolicy,
            'configured' => $this->isConfigured(),
        ];
    }
    
    /**
     * Register an agent
     */
    public function registerAgent(string $name, array $config): void
    {
        $this->agents[$name] = array_merge([
            'name' => $name,
            'model' => '@cf/meta/llama-3.1-8b-instruct',
            'systemPrompt' => 'You are a helpful AI assistant.',
            'tools' => [],
            'maxTurns' => 10,
        ], $config);
    }
    
    /**
     * Register a tool
     */
    public function registerTool(string $name, array $config): void
    {
        $this->tools[$name] = array_merge([
            'name' => $name,
            'description' => '',
            'parameters' => [],
            'requiresApproval' => false,
            'handler' => null,
        ], $config);
    }
    
    /**
     * Execute an agent with a user message
     */
    public function execute(string $agentName, string $userMessage, array $context = []): array
    {
        if (!isset($this->agents[$agentName])) {
            return ['error' => "Agent '{$agentName}' not found"];
        }
        
        $agent = $this->agents[$agentName];
        $conversation = $context['conversation'] ?? [];
        
        // Build messages
        $messages = [
            ['role' => 'system', 'content' => $agent['systemPrompt']],
        ];
        
        // Add conversation history
        foreach ($conversation as $msg) {
            $messages[] = $msg;
        }
        
        // Add user message
        $messages[] = ['role' => 'user', 'content' => $userMessage];
        
        // Execute agent loop
        $turns = 0;
        $maxTurns = $agent['maxTurns'];
        $toolCalls = [];
        
        while ($turns < $maxTurns) {
            $turns++;
            
            // Get LLM response
            $response = $this->provider->chat($messages, [
                'model' => $agent['model'],
                'max_tokens' => 1024,
            ]);
            
            if (!$response || isset($response['error'])) {
                return [
                    'error' => 'LLM call failed',
                    'details' => $response['error'] ?? 'Unknown error',
                ];
            }
            
            $assistantMessage = $response['result']['response'] ?? $response['response'] ?? '';
            
            // Check for tool calls (simplified - real implementation would parse structured output)
            $toolCall = $this->detectToolCall($assistantMessage, $agent['tools']);
            
            if ($toolCall) {
                // Execute tool
                $toolResult = $this->executeTool($toolCall['name'], $toolCall['arguments']);
                
                // Add tool call and result to conversation
                $messages[] = ['role' => 'assistant', 'content' => $assistantMessage];
                $messages[] = ['role' => 'system', 'content' => 'Tool result: ' . json_encode($toolResult)];
                $toolCalls[] = $toolCall;
                
                continue;
            }
            
            // No tool call, return response
            return [
                'agent' => $agentName,
                'response' => $assistantMessage,
                'turns' => $turns,
                'toolCalls' => $toolCalls,
                'model' => $agent['model'],
            ];
        }
        
        return [
            'error' => 'Max turns reached',
            'turns' => $turns,
        ];
    }
    
    /**
     * Detect if the assistant message contains a tool call
     */
    private function detectToolCall(string $message, array $availableTools): ?array
    {
        // Simple heuristic: look for tool call patterns
        // In production, use structured output or function calling
        
        foreach ($availableTools as $toolName) {
            if (stripos($message, "call_tool:{$toolName}") !== false) {
                // Extract arguments (simplified)
                $args = [];
                if (preg_match('/arguments:\s*({.*})/s', $message, $matches)) {
                    $args = json_decode($matches[1], true) ?? [];
                }
                
                return [
                    'name' => $toolName,
                    'arguments' => $args,
                ];
            }
        }
        
        return null;
    }
    
    /**
     * Execute a tool
     */
    private function executeTool(string $toolName, array $arguments): array
    {
        if (!isset($this->tools[$toolName])) {
            return ['error' => "Tool '{$toolName}' not found"];
        }
        
        $tool = $this->tools[$toolName];
        
        // Check approval requirement
        if ($tool['requiresApproval'] && $this->toolPolicy === 'approval_required') {
            return [
                'status' => 'pending_approval',
                'tool' => $toolName,
                'arguments' => $arguments,
                'message' => 'Tool requires approval before execution',
            ];
        }
        
        // Execute tool handler
        if (is_callable($tool['handler'])) {
            try {
                return call_user_func($tool['handler'], $arguments);
            } catch (\Throwable $e) {
                return ['error' => 'Tool execution failed: ' . $e->getMessage()];
            }
        }
        
        return ['error' => 'Tool has no handler'];
    }
    
    /**
     * Get registered agents
     */
    public function agents(): array
    {
        return $this->agents;
    }
    
    /**
     * Get registered tools
     */
    public function tools(): array
    {
        return $this->tools;
    }
    
    /**
     * Register default agents
     */
    private function registerDefaultAgents(): void
    {
        // Trading Analyst Agent
        $this->registerAgent('trading_analyst', [
            'model' => '@cf/meta/llama-3.1-70b-instruct',
            'systemPrompt' => 'You are an expert trading analyst. Analyze market data, identify trends, and provide actionable insights. Always cite data sources and explain your reasoning.',
            'tools' => ['get_market_data', 'calculate_indicators', 'submit_trade_proposal'],
            'maxTurns' => 8,
        ]);
        
        // Language Tutor Agent
        $this->registerAgent('language_tutor', [
            'model' => '@cf/meta/llama-3.1-8b-instruct',
            'systemPrompt' => 'You are a patient and encouraging language tutor. Help users learn languages through conversation, translation, and pronunciation practice. Adapt to their level and learning style.',
            'tools' => ['translate', 'generate_exercises'],
            'maxTurns' => 15,
        ]);
        
        // Lead Discovery Agent
        $this->registerAgent('lead_scout', [
            'model' => '@cf/mistral/mistral-7b-instruct-v0.1',
            'systemPrompt' => 'You are a lead discovery specialist. Find relevant businesses, analyze their profiles, and identify high-quality leads. Always verify data sources and check for duplicates.',
            'tools' => ['search_businesses', 'enrich_lead', 'check_duplicates'],
            'maxTurns' => 10,
        ]);
        
        // Lottery Analyst Agent
        $this->registerAgent('lottery_analyst', [
            'model' => '@cf/meta/llama-3.1-8b-instruct',
            'systemPrompt' => 'You are a lottery statistics analyst. Analyze historical draw data, identify patterns, and generate balanced number combinations. Always clarify that your analysis is statistical, not predictive.',
            'tools' => ['get_draw_history', 'calculate_frequencies', 'generate_combination'],
            'maxTurns' => 6,
        ]);
    }
    
    /**
     * Register default tools
     */
    private function registerDefaultTools(): void
    {
        // Market data tool
        $this->registerTool('get_market_data', [
            'description' => 'Fetch market data for a symbol',
            'parameters' => [
                'symbol' => ['type' => 'string', 'required' => true],
                'timeframe' => ['type' => 'string', 'default' => '1h'],
            ],
            'requiresApproval' => false,
        ]);
        
        // Trade submission tool
        $this->registerTool('submit_trade_proposal', [
            'description' => 'Submit a trade proposal for approval',
            'parameters' => [
                'symbol' => ['type' => 'string', 'required' => true],
                'side' => ['type' => 'string', 'required' => true],
                'volume' => ['type' => 'number', 'required' => true],
                'reasoning' => ['type' => 'string', 'required' => true],
            ],
            'requiresApproval' => true,
        ]);
        
        // Translation tool
        $this->registerTool('translate', [
            'description' => 'Translate text between languages',
            'parameters' => [
                'text' => ['type' => 'string', 'required' => true],
                'target_lang' => ['type' => 'string', 'required' => true],
                'source_lang' => ['type' => 'string'],
            ],
            'requiresApproval' => false,
        ]);
        
        // Business search tool
        $this->registerTool('search_businesses', [
            'description' => 'Search for businesses in a location',
            'parameters' => [
                'query' => ['type' => 'string', 'required' => true],
                'location' => ['type' => 'string', 'required' => true],
                'limit' => ['type' => 'integer', 'default' => 20],
            ],
            'requiresApproval' => false,
        ]);
        
        // Lottery draw history tool
        $this->registerTool('get_draw_history', [
            'description' => 'Get historical lottery draw data',
            'parameters' => [
                'lottery' => ['type' => 'string', 'default' => 'euromillions'],
                'limit' => ['type' => 'integer', 'default' => 50],
            ],
            'requiresApproval' => false,
        ]);
    }
}
