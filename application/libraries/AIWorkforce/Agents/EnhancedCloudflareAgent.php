<?php
namespace AIWorkforce\Agents;

use AIWorkforce\ApiProviders;
use AIWorkforce\Providers\CloudflareProvider;

/**
 * Enhanced Cloudflare Specialist Agent
 * 
 * Bridges the existing SpecialistAgent interface with the new CloudflareProvider,
 * enabling rich multi-model AI capabilities across all specialist roles.
 * 
 * Supports:
 *   - Dynamic model selection per agent role
 *   - Cloudflare Workers AI for edge inference
 *   - Fallback to local knowledge when provider unavailable
 *   - Tool-aware prompting with role-specific expertise
 *   - Structured JSON output for machine-readable responses
 */
final class EnhancedCloudflareAgent implements SpecialistAgent
{
    private string $role;
    private array $allowedTools;
    private ?CloudflareProvider $provider = null;

    /** Model recommendations per agent role */
    private const ROLE_MODELS = [
        'general'        => '@cf/meta/llama-3.1-8b-instruct',
        'market'         => '@cf/meta/llama-3.1-70b-instruct',
        'sports'         => '@cf/meta/llama-3.1-8b-instruct',
        'lead_discovery' => '@cf/mistral/mistral-7b-instruct-v0.1',
        'lottery'        => '@cf/meta/llama-3.1-8b-instruct',
        'language'       => '@cf/meta/llama-3.1-8b-instruct',
        'trading'        => '@cf/meta/llama-3.1-70b-instruct',
        'video'          => '@cf/meta/llama-3.1-8b-instruct',
    ];

    /** System prompts per agent role */
    private const ROLE_SYSTEM = [
        'general'        => 'You are the general specialist in WINDELS AI WORKFORCE. Provide helpful, accurate, and concise responses. Use only the supplied facts. Never invent market data, sports results, lottery results, or user records.',
        'market'         => 'You are the market analysis specialist in WINDELS AI WORKFORCE. Analyze crypto and forex markets using the supplied data. Cite specific prices and trends. Never fabricate price data. Clearly state when data is unavailable or simulated.',
        'sports'         => 'You are the sports intelligence specialist in WINDELS AI WORKFORCE. Analyze sports data using the supplied facts. Reference specific teams, matches, and statistics. Never invent match results or statistics.',
        'lead_discovery' => 'You are the lead discovery specialist in WINDELS AI WORKFORCE. Help find and analyze business leads using the supplied data. Focus on relevance, quality, and actionable insights. Never fabricate business information.',
        'lottery'        => 'You are the lottery intelligence specialist in WINDELS AI WORKFORCE. Provide statistical analysis of lottery data only. IMPORTANT: Clearly state that all analysis is HISTORICAL and STATISTICAL — lottery draws are random events and past patterns do NOT predict future outcomes. Never claim to predict winning numbers.',
        'language'       => 'You are the language learning specialist in WINDELS AI WORKFORCE. Help users learn languages through conversation, correction, and explanation. Be patient, encouraging, and adaptive to the user\'s level. Provide corrections with clear explanations.',
        'trading'        => 'You are the trading analysis specialist in WINDELS AI WORKFORCE. Provide market analysis and trade insights using the supplied data. Trading is ANALYSIS and PROPOSAL ONLY — never authorize or guarantee execution. Always reference risk controls and the approval workflow. Never guarantee profits.',
        'video'          => 'You are the video generation specialist in WINDELS AI WORKFORCE. Help users plan and describe video content. Provide creative and practical guidance for video production concepts.',
    ];

    public function __construct(string $role, array $allowedTools = [])
    {
        $this->role = $role;
        $this->allowedTools = $allowedTools;
        $this->initProvider();
    }

    public function name(): string
    {
        return $this->role;
    }

    public function tools(): array
    {
        return $this->allowedTools;
    }

    public function handle(array $request, array $context): array
    {
        $cfg = ApiProviders::resolve('llm') ?: ApiProviders::resolve('language_ai');

        if (!is_array($cfg)) {
            return [
                'status' => 'UNAVAILABLE',
                'reason' => 'No AI provider configured. Ask an administrator to configure Cloudflare Workers AI or an OpenAI-compatible provider.',
            ];
        }

        // Determine model based on role
        $model = self::ROLE_MODELS[$this->role] ?? '@cf/meta/llama-3.1-8b-instruct';
        $system = self::ROLE_SYSTEM[$this->role] ?? self::ROLE_SYSTEM['general'];

        // Enhance system prompt with tool awareness
        if (!empty($this->allowedTools)) {
            $system .= "\n\nYou have access to these tools: " . implode(', ', $this->allowedTools) . ". Mention them when relevant but never execute them directly — tool execution is handled by the platform.";
        }

        // Build conversation
        $facts = json_encode($request['facts'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $instruction = (string) ($request['instruction'] ?? 'Analyze the supplied facts.');

        $messages = [
            ['role' => 'system', 'content' => $system],
        ];

        // Add conversation history if available
        if (!empty($context['conversation']) && is_array($context['conversation'])) {
            foreach (array_slice($context['conversation'], -6) as $msg) {
                if (isset($msg['role'], $msg['content'])) {
                    $messages[] = ['role' => $msg['role'], 'content' => (string) $msg['content']];
                }
            }
        }

        $messages[] = ['role' => 'user', 'content' => $instruction . "\n\nFACTS:\n" . $facts];

        // Try Cloudflare provider first (if configured)
        if ($this->provider && $this->provider->isConfigured()) {
            try {
                $result = $this->provider->chat($messages, [
                    'model' => $model,
                    'max_tokens' => 800,
                    'temperature' => 0.3,
                ]);

                if ($result && !isset($result['error'])) {
                    $answer = $result['result']['response'] ?? $result['response'] ?? null;
                    if (is_string($answer) && trim($answer) !== '') {
                        return [
                            'status' => 'COMPLETED',
                            'role' => $this->role,
                            'answer' => mb_substr(trim($answer), 0, 4000),
                            'provider' => 'cloudflare_workers_ai',
                            'model' => $model,
                            'tools' => $this->allowedTools,
                        ];
                    }
                }
            } catch (\Throwable $e) {
                // Fall through to standard provider
            }
        }

        // Fall back to standard ApiProviders::openaiChat
        $answer = ApiProviders::openaiChat($cfg, $messages, 800);

        if ($answer === null) {
            return [
                'status' => 'UNAVAILABLE',
                'reason' => 'AI provider request failed. The provider may be temporarily unavailable.',
            ];
        }

        return [
            'status' => 'COMPLETED',
            'role' => $this->role,
            'answer' => $answer,
            'provider' => $cfg['driver'] ?? 'configured-ai',
            'model' => $model,
            'tools' => $this->allowedTools,
        ];
    }

    /**
     * Initialize the Cloudflare provider from active config
     */
    private function initProvider(): void
    {
        try {
            $cfg = ApiProviders::resolve('llm');
            if (is_array($cfg) && ($cfg['driver'] ?? '') === 'cloudflare_workers_ai') {
                $this->provider = new CloudflareProvider([
                    'account_id' => $cfg['account_id'] ?? '',
                    'token' => $cfg['secrets']['token'] ?? '',
                    'gateway' => $cfg['extra']['gateway'] ?? null,
                    'base_url' => $cfg['base_url'] ?? '',
                    'timeout' => 30,
                ]);
            }
        } catch (\Throwable $e) {
            $this->provider = null;
        }
    }

    /**
     * Get the recommended model for a role
     */
    public static function modelFor(string $role): string
    {
        return self::ROLE_MODELS[$role] ?? '@cf/meta/llama-3.1-8b-instruct';
    }

    /**
     * Get all available roles with their models
     */
    public static function allRoleModels(): array
    {
        return self::ROLE_MODELS;
    }
}
