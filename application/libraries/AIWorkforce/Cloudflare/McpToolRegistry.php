<?php
namespace AIWorkforce\Cloudflare;

/**
 * MCP Tool Registry — Centralized tool discovery and execution
 *
 * Every external service exposes standardized tools through this registry.
 * Agents discover tools by name, check parameters, and execute them through
 * this layer — never calling external APIs directly.
 *
 * This implements the Model Context Protocol (MCP) pattern where tools are
 * self-describing with parameters, permissions, and approval requirements.
 *
 * Tool categories:
 * - crypto.*       — Crypto market data
 * - forex.*        — Forex market data
 * - sports.*       — Sports data and analysis
 * - lottery.*      — Lottery results and generation
 * - broker.*       — Broker account and trade operations
 * - language.*     — Language learning tools
 * - video.*        — Video generation
 * - lead.*         — Lead discovery tools
 * - stt.*          — Speech-to-text
 * - tts.*          — Text-to-speech
 * - pronunciation.* — Pronunciation analysis
 */
class McpToolRegistry
{
    /** @var array<string,McpTool> Registered tools */
    private array $tools = [];

    /** @var callable|null Audit logger */
    private $audit;

    /** @var callable|null Approval handler */
    private $approval;

    /** Tool categories for grouping */
    public const CATEGORIES = [
        'crypto' => 'Cryptocurrency Market Data',
        'forex' => 'Forex Market Data',
        'sports' => 'Sports Intelligence',
        'lottery' => 'Lottery Intelligence',
        'broker' => 'Trading Broker Operations',
        'language' => 'Language Learning',
        'video' => 'Video Generation',
        'lead' => 'Lead Discovery',
        'stt' => 'Speech-to-Text',
        'tts' => 'Text-to-Speech',
        'pronunciation' => 'Pronunciation Analysis',
        'llm' => 'AI Language Model',
        'system' => 'System Operations',
    ];

    /** Tools requiring human approval */
    public const APPROVAL_REQUIRED = [
        'broker.submitTrade',
        'lottery.purchaseTicket',
        'payment.send',
        'data.delete',
        'communication.send',
    ];

    public function __construct(?callable $audit = null, ?callable $approval = null)
    {
        $this->audit = $audit;
        $this->approval = $approval;
        $this->registerDefaultTools();
    }

    /**
     * Register all default tools
     */
    private function registerDefaultTools(): void
    {
        // ── Crypto Market Tools ──────────────────────────────────
        $this->register(new McpTool(
            'crypto.getPrice',
            'Get current cryptocurrency price',
            ['symbol' => ['type' => 'string', 'required' => true, 'description' => 'Trading pair (e.g., BTCUSD)']],
            requiresApproval: false,
            category: 'crypto',
            handler: fn($args) => $this->cryptoGetPrice($args),
        ));

        $this->register(new McpTool(
            'crypto.getMarketData',
            'Get comprehensive crypto market data',
            [
                'symbol' => ['type' => 'string', 'required' => true],
                'timeframe' => ['type' => 'string', 'default' => '1h'],
            ],
            requiresApproval: false,
            category: 'crypto',
        ));

        // ── Forex Market Tools ───────────────────────────────────
        $this->register(new McpTool(
            'forex.getRate',
            'Get current forex exchange rate',
            ['pair' => ['type' => 'string', 'required' => true, 'description' => 'Currency pair (e.g., EURUSD)']],
            requiresApproval: false,
            category: 'forex',
        ));

        // ── Sports Intelligence Tools ────────────────────────────
        $this->register(new McpTool(
            'sports.getFixtures',
            'Get upcoming sports fixtures',
            [
                'sport' => ['type' => 'string', 'default' => 'football'],
                'limit' => ['type' => 'integer', 'default' => 10],
            ],
            requiresApproval: false,
            category: 'sports',
        ));

        $this->register(new McpTool(
            'sports.getMatchStats',
            'Get match statistics and analysis',
            ['matchId' => ['type' => 'string', 'required' => true]],
            requiresApproval: false,
            category: 'sports',
        ));

        // ── Lottery Intelligence Tools ───────────────────────────
        $this->register(new McpTool(
            'lottery.getResults',
            'Get historical lottery draw results',
            [
                'lottery' => ['type' => 'string', 'default' => 'euromillions'],
                'limit' => ['type' => 'integer', 'default' => 10],
            ],
            requiresApproval: false,
            category: 'lottery',
        ));

        $this->register(new McpTool(
            'lottery.generateCombinations',
            'Generate AI-powered lottery number combinations',
            [
                'lottery' => ['type' => 'string', 'default' => 'euromillions'],
                'count' => ['type' => 'integer', 'default' => 5],
                'mode' => ['type' => 'string', 'default' => 'balanced'],
            ],
            requiresApproval: false,
            category: 'lottery',
        ));

        $this->register(new McpTool(
            'lottery.purchaseTicket',
            'Purchase a lottery ticket (requires authorization)',
            [
                'lottery' => ['type' => 'string', 'required' => true],
                'numbers' => ['type' => 'array', 'required' => true],
            ],
            requiresApproval: true,
            category: 'lottery',
        ));

        // ── Broker/Trading Tools ─────────────────────────────────
        $this->register(new McpTool(
            'broker.getAccount',
            'Get broker account information',
            ['broker' => ['type' => 'string', 'required' => false]],
            requiresApproval: false,
            category: 'broker',
        ));

        $this->register(new McpTool(
            'broker.getPositions',
            'Get open trading positions',
            ['broker' => ['type' => 'string', 'required' => false]],
            requiresApproval: false,
            category: 'broker',
        ));

        $this->register(new McpTool(
            'broker.submitTrade',
            'Submit a trade proposal for approval',
            [
                'symbol' => ['type' => 'string', 'required' => true],
                'side' => ['type' => 'string', 'required' => true, 'enum' => ['BUY', 'SELL']],
                'volume' => ['type' => 'number', 'required' => true],
                'reasoning' => ['type' => 'string', 'required' => true],
            ],
            requiresApproval: true,
            category: 'broker',
        ));

        // ── Language Learning Tools ──────────────────────────────
        $this->register(new McpTool(
            'language.analyzePronunciation',
            'Analyze pronunciation accuracy',
            [
                'audio' => ['type' => 'string', 'required' => true, 'description' => 'Base64 audio data'],
                'expected' => ['type' => 'string', 'required' => true, 'description' => 'Expected text'],
                'language' => ['type' => 'string', 'default' => 'en'],
            ],
            requiresApproval: false,
            category: 'language',
        ));

        $this->register(new McpTool(
            'language.translate',
            'Translate text between languages',
            [
                'text' => ['type' => 'string', 'required' => true],
                'target' => ['type' => 'string', 'required' => true],
                'source' => ['type' => 'string', 'default' => 'auto'],
            ],
            requiresApproval: false,
            category: 'language',
        ));

        // ── STT/TTS Tools ────────────────────────────────────────
        $this->register(new McpTool(
            'stt.transcribe',
            'Convert speech audio to text',
            [
                'audio' => ['type' => 'string', 'required' => true, 'description' => 'Base64 audio data'],
                'language' => ['type' => 'string', 'default' => 'auto'],
            ],
            requiresApproval: false,
            category: 'stt',
        ));

        $this->register(new McpTool(
            'tts.synthesize',
            'Convert text to speech audio',
            [
                'text' => ['type' => 'string', 'required' => true],
                'voice' => ['type' => 'string', 'default' => 'default'],
                'language' => ['type' => 'string', 'default' => 'en'],
            ],
            requiresApproval: false,
            category: 'tts',
        ));

        // ── Video Generation Tools ───────────────────────────────
        $this->register(new McpTool(
            'video.create',
            'Generate video from text or image',
            [
                'prompt' => ['type' => 'string', 'required' => true],
                'duration' => ['type' => 'integer', 'default' => 5],
                'style' => ['type' => 'string', 'default' => 'realistic'],
            ],
            requiresApproval: false,
            category: 'video',
        ));

        // ── Lead Discovery Tools ─────────────────────────────────
        $this->register(new McpTool(
            'lead.search',
            'Search for businesses and leads',
            [
                'query' => ['type' => 'string', 'required' => true],
                'location' => ['type' => 'string', 'required' => true],
                'limit' => ['type' => 'integer', 'default' => 20],
            ],
            requiresApproval: false,
            category: 'lead',
        ));

        $this->register(new McpTool(
            'lead.enrich',
            'Enrich a lead with additional data',
            ['leadId' => ['type' => 'string', 'required' => true]],
            requiresApproval: false,
            category: 'lead',
        ));
    }

    /**
     * Register a tool
     */
    public function register(McpTool $tool): void
    {
        $this->tools[$tool->name] = $tool;
    }

    /**
     * Get a tool by name
     */
    public function get(string $name): ?McpTool
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * List all tools, optionally filtered by category
     */
    public function list(?string $category = null): array
    {
        if ($category === null) {
            return $this->tools;
        }
        return array_filter($this->tools, fn($t) => $t->category === $category);
    }

    /**
     * List tools available to a specific agent
     */
    public function listForAgent(array $allowedTools): array
    {
        if (in_array('*', $allowedTools, true)) {
            return $this->tools;
        }
        return array_intersect_key($this->tools, array_flip($allowedTools));
    }

    /**
     * Execute a tool with full authorization and audit
     */
    public function execute(string $toolName, array $arguments = [], array $context = []): array
    {
        $tool = $this->get($toolName);
        if (!$tool) {
            return ['ok' => false, 'error' => "Tool '{$toolName}' not found"];
        }

        $executionId = 'tool_' . bin2hex(random_bytes(8));
        $agent = $context['agent'] ?? 'unknown';
        $userId = $context['userId'] ?? null;

        // Validate parameters
        $validation = $tool->validate($arguments);
        if ($validation !== null) {
            $this->auditLog('TOOL_VALIDATION_FAILED', [
                'tool' => $toolName, 'agent' => $agent, 'error' => $validation,
                'executionId' => $executionId,
            ]);
            return ['ok' => false, 'error' => $validation, 'executionId' => $executionId];
        }

        // Check approval requirement
        if ($tool->requiresApproval) {
            $this->auditLog('TOOL_APPROVAL_REQUIRED', [
                'tool' => $toolName, 'agent' => $agent, 'userId' => $userId,
                'arguments' => $this->sanitizeForLog($arguments),
                'executionId' => $executionId,
            ]);

            if ($this->approval) {
                $approvalResult = ($this->approval)($toolName, $arguments, $context);
                if (!empty($approvalResult['required']) && empty($approvalResult['approved'])) {
                    return [
                        'ok' => false,
                        'status' => 'PENDING_APPROVAL',
                        'tool' => $toolName,
                        'executionId' => $executionId,
                        'approval' => $approvalResult,
                    ];
                }
            } else {
                return [
                    'ok' => false,
                    'status' => 'PENDING_APPROVAL',
                    'tool' => $toolName,
                    'executionId' => $executionId,
                ];
            }
        }

        // Execute tool handler
        $start = microtime(true);
        try {
            if ($tool->handler) {
                $result = ($tool->handler)($arguments);
            } else {
                $result = ['status' => 'TOOL_REGISTERED', 'tool' => $toolName, 'message' => 'Tool is registered but has no handler. Connect a provider to execute.'];
            }

            $latencyMs = round((microtime(true) - $start) * 1000);

            $this->auditLog('TOOL_EXECUTED', [
                'tool' => $toolName, 'agent' => $agent, 'userId' => $userId,
                'latencyMs' => $latencyMs, 'executionId' => $executionId,
            ]);

            return [
                'ok' => true,
                'result' => $result,
                'tool' => $toolName,
                'executionId' => $executionId,
                'latencyMs' => $latencyMs,
            ];
        } catch (\Throwable $e) {
            $latencyMs = round((microtime(true) - $start) * 1000);

            $this->auditLog('TOOL_FAILED', [
                'tool' => $toolName, 'agent' => $agent, 'error' => $e->getMessage(),
                'executionId' => $executionId,
            ]);

            return [
                'ok' => false,
                'error' => 'Tool execution failed: ' . $e->getMessage(),
                'tool' => $toolName,
                'executionId' => $executionId,
                'latencyMs' => $latencyMs,
            ];
        }
    }

    /**
     * Get tool descriptions for LLM function calling
     */
    public function toolSchemas(array $allowedTools): array
    {
        $schemas = [];
        foreach ($this->listForAgent($allowedTools) as $name => $tool) {
            $schemas[] = [
                'name' => $name,
                'description' => $tool->description,
                'parameters' => $tool->parameterSchema(),
            ];
        }
        return $schemas;
    }

    /**
     * Get tool categories with counts
     */
    public function categories(): array
    {
        $cats = [];
        foreach (self::CATEGORIES as $code => $label) {
            $tools = $this->list($code);
            $cats[$code] = [
                'label' => $label,
                'count' => count($tools),
                'tools' => array_keys($tools),
            ];
        }
        return $cats;
    }

    private function auditLog(string $type, array $detail): void
    {
        if ($this->audit) {
            try { ($this->audit)($type, $type, $detail); } catch (\Throwable $e) {}
        }
    }

    private function sanitizeForLog(array $args): array
    {
        $out = [];
        foreach ($args as $k => $v) {
            if (is_string($v) && mb_strlen($v) > 200) {
                $out[$k] = mb_substr($v, 0, 200) . '...';
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /**
     * Default crypto price handler
     */
    private function cryptoGetPrice(array $args): array
    {
        return [
            'symbol' => $args['symbol'] ?? 'BTCUSD',
            'status' => 'PROVIDER_REQUIRED',
            'message' => 'Connect a crypto market data provider to get live prices.',
        ];
    }
}

/**
 * MCP Tool value object
 */
class McpTool
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $parameters = [],
        public readonly bool $requiresApproval = false,
        public readonly string $category = 'system',
        public readonly ?\Closure $handler = null,
    ) {}

    /**
     * Validate arguments against parameter schema
     */
    public function validate(array $args): ?string
    {
        foreach ($this->parameters as $param => $spec) {
            if (!empty($spec['required']) && !isset($args[$param])) {
                return "Missing required parameter: {$param}";
            }
            if (isset($args[$param]) && !empty($spec['enum'])) {
                if (!in_array($args[$param], $spec['enum'], true)) {
                    return "Parameter {$param} must be one of: " . implode(', ', $spec['enum']);
                }
            }
        }
        return null;
    }

    /**
     * Get JSON Schema for parameters
     */
    public function parameterSchema(): array
    {
        $schema = ['type' => 'object', 'properties' => [], 'required' => []];
        foreach ($this->parameters as $name => $spec) {
            $prop = ['type' => $spec['type'] ?? 'string'];
            if (isset($spec['description'])) $prop['description'] = $spec['description'];
            if (isset($spec['enum'])) $prop['enum'] = $spec['enum'];
            if (isset($spec['default'])) $prop['default'] = $spec['default'];
            $schema['properties'][$name] = $prop;
            if (!empty($spec['required'])) $schema['required'][] = $name;
        }
        return $schema;
    }
}
