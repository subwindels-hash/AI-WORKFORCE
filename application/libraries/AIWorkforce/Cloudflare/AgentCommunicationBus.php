<?php
namespace AIWorkforce\Cloudflare;

/**
 * Agent Communication Bus — Agent-to-agent delegation and messaging
 *
 * Enables agents to communicate and delegate work to other agents:
 * - Direct delegation (request + response)
 * - Async messaging (fire-and-forget)
 * - Agent discovery (what agents are available?)
 * - Task routing (which agent should handle this?)
 *
 * Architecture rule: Agents don't duplicate logic.
 * The Market Agent doesn't analyze sports data — it delegates to the Sports Agent.
 *
 * Example flow:
 * User: "Analyze this football match and determine if there's a trading opportunity."
 * Main Agent → Sports Agent (match analysis)
 * Main Agent → Market Agent (market data)
 * Main Agent → Risk Agent (risk assessment)
 * Main Agent → User (final response)
 */
class AgentCommunicationBus
{
    /** @var \AIWorkforce\Agents\AgentOrchestrator */
    private $orchestrator;

    /** @var callable|null Audit logger */
    private $audit;

    /** @var array<string,array> Message queue */
    private array $queue = [];

    /** @var array<string,array> Routing rules */
    private array $routes = [];

    public function __construct($orchestrator, ?callable $audit = null)
    {
        $this->orchestrator = $orchestrator;
        $this->audit = $audit;
        $this->setupDefaultRoutes();
    }

    /**
     * Send a message to another agent (synchronous delegation)
     */
    public function delegate(string $fromAgent, string $toAgent, string $instruction, array $context = []): array
    {
        $delegationId = 'del_' . bin2hex(random_bytes(8));
        $start = microtime(true);

        $this->auditLog('AGENT_DELEGATION_START', [
            'delegationId' => $delegationId,
            'from' => $fromAgent,
            'to' => $toAgent,
            'instruction' => mb_substr($instruction, 0, 200),
        ]);

        // Check if target agent exists
        $agents = $this->orchestrator->agents();
        if (!isset($agents[$toAgent])) {
            return [
                'ok' => false,
                'error' => "Agent '{$toAgent}' not available",
                'delegationId' => $delegationId,
            ];
        }

        // Dispatch to target agent
        $result = $this->orchestrator->dispatch($toAgent, [
            'instruction' => $instruction,
            'facts' => $context['facts'] ?? [],
            'delegated_from' => $fromAgent,
            'delegation_id' => $delegationId,
        ], $context);

        $latencyMs = round((microtime(true) - $start) * 1000);

        $this->auditLog('AGENT_DELEGATION_COMPLETE', [
            'delegationId' => $delegationId,
            'from' => $fromAgent,
            'to' => $toAgent,
            'ok' => $result['ok'] ?? false,
            'latencyMs' => $latencyMs,
        ]);

        return array_merge($result, [
            'delegationId' => $delegationId,
            'from' => $fromAgent,
            'to' => $toAgent,
            'latencyMs' => $latencyMs,
        ]);
    }

    /**
     * Route a request to the best agent based on content
     */
    public function route(string $instruction, array $context = []): array
    {
        $target = $this->determineBestAgent($instruction, $context);
        if (!$target) {
            return ['ok' => false, 'error' => 'No suitable agent found for this request'];
        }

        return $this->delegate('router', $target, $instruction, $context);
    }

    /**
     * Determine the best agent for a request
     */
    public function determineBestAgent(string $instruction, array $context = []): ?string
    {
        $lower = strtolower($instruction);

        // Check routing rules
        foreach ($this->routes as $pattern => $agent) {
            if (is_string($pattern) && preg_match($pattern, $lower)) {
                return $agent;
            }
        }

        // Default routing by keyword
        if (preg_match('/\b(crypto|bitcoin|btc|eth|forex|currency|exchange|price)\b/i', $lower)) {
            return 'market';
        }
        if (preg_match('/\b(football|soccer|basketball|tennis|match|fixture|sport)\b/i', $lower)) {
            return 'sports';
        }
        if (preg_match('/\b(lottery|euromillions|lotto|numbers?|draw)\b/i', $lower)) {
            return 'lottery';
        }
        if (preg_match('/\b(lead|business|company|search|discover)\b/i', $lower)) {
            return 'lead_discovery';
        }
        if (preg_match('/\b(trade|position|portfolio|broker|order)\b/i', $lower)) {
            return 'trading';
        }
        if (preg_match('/\b(language|learn|translate|pronunciation|speak)\b/i', $lower)) {
            return 'language';
        }
        if (preg_match('/\b(video|generate.*video|render)\b/i', $lower)) {
            return 'video';
        }

        return 'general';
    }

    /**
     * Send an async message (fire-and-forget)
     */
    public function sendMessage(string $fromAgent, string $toAgent, string $message, array $data = []): string
    {
        $msgId = 'msg_' . bin2hex(random_bytes(8));
        $this->queue[] = [
            'id' => $msgId,
            'from' => $fromAgent,
            'to' => $toAgent,
            'message' => $message,
            'data' => $data,
            'created_at' => gmdate('c'),
            'status' => 'queued',
        ];

        $this->auditLog('AGENT_MESSAGE_QUEUED', [
            'messageId' => $msgId, 'from' => $fromAgent, 'to' => $toAgent,
        ]);

        return $msgId;
    }

    /**
     * Process queued messages
     */
    public function processQueue(int $limit = 20): array
    {
        $processed = [];
        $messages = array_splice($this->queue, 0, $limit);

        foreach ($messages as $msg) {
            $result = $this->delegate($msg['from'], $msg['to'], $msg['message'], [
                'facts' => $msg['data'] ?? [],
            ]);
            $processed[] = [
                'messageId' => $msg['id'],
                'result' => $result,
            ];
        }

        return $processed;
    }

    /**
     * Get available agents with their capabilities
     */
    public function discoverAgents(): array
    {
        $agents = $this->orchestrator->agents();
        $discovered = [];

        foreach ($agents as $name => $agent) {
            $discovered[$name] = [
                'name' => $name,
                'label' => $this->agentLabel($name),
                'tools' => $agent->tools(),
                'capabilities' => $this->agentCapabilities($name),
            ];
        }

        return $discovered;
    }

    /**
     * Add a routing rule
     */
    public function addRoute(string $pattern, string $agent): void
    {
        $this->routes[$pattern] = $agent;
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

    private function agentCapabilities(string $name): array
    {
        return [
            'general' => ['qa', 'research', 'analysis', 'summarization'],
            'market' => ['crypto_prices', 'forex_rates', 'technical_analysis', 'sentiment'],
            'sports' => ['fixtures', 'results', 'statistics', 'predictions'],
            'lead_discovery' => ['search', 'enrichment', 'deduplication', 'scoring'],
            'lottery' => ['results', 'frequency', 'statistics', 'generation'],
            'language' => ['translation', 'pronunciation', 'conversation', 'exercises'],
            'trading' => ['analysis', 'signals', 'proposals', 'portfolio'],
            'video' => ['generation', 'editing', 'captions'],
        ][$name] ?? [];
    }

    private function setupDefaultRoutes(): void
    {
        // Regex patterns for routing
        $this->routes = [
            '/\b(crypto|bitcoin|btc|eth|forex|currency|exchange|market\s*data)\b/i' => 'market',
            '/\b(football|soccer|basketball|tennis|baseball|match|fixture|sport|nfl|nba|premier\s*league)\b/i' => 'sports',
            '/\b(lottery|euromillions|lotto|draw|winning\s*numbers?)\b/i' => 'lottery',
            '/\b(lead|business|company|search|discover|crm)\b/i' => 'lead_discovery',
            '/\b(trade|position|portfolio|broker|order|execution)\b/i' => 'trading',
            '/\b(language|learn|translate|pronunciation|speak|lesson|vocabulary)\b/i' => 'language',
            '/\b(video|generate.*video|render|clip)\b/i' => 'video',
        ];
    }

    private function auditLog(string $type, array $detail): void
    {
        if ($this->audit) {
            try { ($this->audit)($type, $type, $detail); } catch (\Throwable $e) {}
        }
    }
}
