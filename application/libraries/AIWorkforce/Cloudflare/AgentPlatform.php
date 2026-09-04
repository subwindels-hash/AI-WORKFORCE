<?php
namespace AIWorkforce\Cloudflare;

/**
 * Cloudflare Agent Platform — Unified entry point for the AI agent infrastructure
 *
 * This is the central orchestrator that wires together:
 * - ModelRouter (multi-provider model gateway with failover)
 * - McpToolRegistry (centralized tool discovery and execution)
 * - AgentSessionManager (durable sessions and state)
 * - WorkflowEngine (long-running task workflows)
 * - AgentCommunicationBus (agent-to-agent delegation)
 * - AgentObservability (monitoring and analytics)
 *
 * Architecture:
 * - Existing Backend = Business/Application Control Plane
 * - Cloudflare Agents = AI/Agent Intelligence & Execution Plane
 * - External APIs = Specialized Data and Execution Providers
 *
 * The platform is lazily initialized — components are only created when needed.
 */
class AgentPlatform
{
    private ?ModelRouter $modelRouter = null;
    private ?McpToolRegistry $toolRegistry = null;
    private ?AgentSessionManager $sessionManager = null;
    private ?WorkflowEngine $workflowEngine = null;
    private ?AgentCommunicationBus $communicationBus = null;
    private ?AgentObservability $observability = null;

    /** @var \CI_DB_query_builder */
    private $db;

    /** @var callable|null Audit logger */
    private $audit;

    /** @var callable|null Approval handler */
    private $approval;

    /** @var \AIWorkforce\Agents\AgentOrchestrator|null */
    private $agentOrchestrator;

    private bool $initialized = false;

    public function __construct(
        $db,
        ?callable $audit = null,
        ?callable $approval = null,
        $agentOrchestrator = null
    ) {
        $this->db = $db;
        $this->audit = $audit;
        $this->approval = $approval;
        $this->agentOrchestrator = $agentOrchestrator;
    }

    /**
     * Get the Model Router (lazy init)
     */
    public function modelRouter(): ModelRouter
    {
        if ($this->modelRouter === null) {
            $this->modelRouter = new ModelRouter($this->audit);
        }
        return $this->modelRouter;
    }

    /**
     * Get the MCP Tool Registry (lazy init)
     */
    public function toolRegistry(): McpToolRegistry
    {
        if ($this->toolRegistry === null) {
            $this->toolRegistry = new McpToolRegistry($this->audit, $this->approval);
        }
        return $this->toolRegistry;
    }

    /**
     * Get the Agent Session Manager (lazy init)
     */
    public function sessionManager(): AgentSessionManager
    {
        if ($this->sessionManager === null) {
            $this->sessionManager = new AgentSessionManager($this->db, $this->audit);
        }
        return $this->sessionManager;
    }

    /**
     * Get the Workflow Engine (lazy init)
     */
    public function workflowEngine(): WorkflowEngine
    {
        if ($this->workflowEngine === null) {
            $this->workflowEngine = new WorkflowEngine($this->db, $this->audit);
        }
        return $this->workflowEngine;
    }

    /**
     * Get the Agent Communication Bus (lazy init)
     */
    public function communicationBus(): AgentCommunicationBus
    {
        if ($this->communicationBus === null) {
            if ($this->agentOrchestrator === null) {
                throw new \RuntimeException('AgentCommunicationBus requires an AgentOrchestrator');
            }
            $this->communicationBus = new AgentCommunicationBus($this->agentOrchestrator, $this->audit);
        }
        return $this->communicationBus;
    }

    /**
     * Get the Observability dashboard (lazy init)
     */
    public function observability(): AgentObservability
    {
        if ($this->observability === null) {
            $this->observability = new AgentObservability(
                $this->db,
                $this->modelRouter,
                $this->sessionManager,
                $this->workflowEngine
            );
        }
        return $this->observability;
    }

    /**
     * Complete platform status
     */
    public function status(): array
    {
        return [
            'initialized' => true,
            'modelRouter' => $this->modelRouter?->status() ?? ['configured' => false],
            'toolRegistry' => [
                'totalTools' => count($this->toolRegistry()->list()),
                'categories' => array_map(fn($c) => $c['count'], $this->toolRegistry()->categories()),
            ],
            'sessionManager' => $this->sessionManager?->stats() ?? ['configured' => false],
            'workflowEngine' => $this->workflowEngine?->stats() ?? ['configured' => false],
            'communicationBus' => [
                'availableAgents' => $this->agentOrchestrator ? count($this->agentOrchestrator->agents()) : 0,
            ],
        ];
    }

    /**
     * Execute an agent with full session management
     */
    public function executeAgent(string $agent, string $instruction, int $userId, array $context = []): array
    {
        // Get or create session
        $sessionId = $context['session_id'] ?? null;
        $session = $this->sessionManager()->getOrCreate($userId, $agent, $sessionId);

        // Add user message to history
        $this->sessionManager()->addMessage($session['id'], 'user', $instruction);

        // Build conversation context
        $history = $this->sessionManager()->conversationHistory($session['id'], 20);
        $context['conversation'] = $history;
        $context['session_id'] = $session['id'];
        $context['user_id'] = $userId;

        // Dispatch to agent
        if ($this->agentOrchestrator === null) {
            return ['ok' => false, 'error' => 'Agent orchestrator not available'];
        }

        $result = $this->agentOrchestrator->dispatch($agent, [
            'instruction' => $instruction,
            'facts' => $context['facts'] ?? [],
        ], $context);

        // Add assistant response to history
        if (!empty($result['ok']) && !empty($result['result']['answer'])) {
            $this->sessionManager()->addMessage($session['id'], 'assistant', $result['result']['answer']);
        }

        return array_merge($result, ['sessionId' => $session['id']]);
    }

    /**
     * Execute a tool through the MCP registry
     */
    public function executeTool(string $tool, array $arguments = [], array $context = []): array
    {
        return $this->toolRegistry()->execute($tool, $arguments, $context);
    }

    /**
     * Start a workflow
     */
    public function startWorkflow(string $type, array $params = [], array $options = []): array
    {
        return $this->workflowEngine()->start($type, $params, $options);
    }

    /**
     * Route a request to the best agent
     */
    public function routeRequest(string $instruction, int $userId, array $context = []): array
    {
        $target = $this->communicationBus()->determineBestAgent($instruction, $context);
        if (!$target) {
            return ['ok' => false, 'error' => 'No suitable agent found'];
        }

        return $this->executeAgent($target, $instruction, $userId, $context);
    }

    /**
     * Get the full dashboard
     */
    public function dashboard(): array
    {
        return $this->observability()->dashboard();
    }
}
