<?php
namespace AIWorkforce\Agents;

use AIWorkforce\ApiProviders;

/**
 * Enhanced Specialist Agent
 *
 * Bridges the existing SpecialistAgent interface with the configured
 * AI provider, enabling rich multi-model AI capabilities across all
 * specialist roles.
 *
 * Supports:
 *   - Recommended model selection per agent role
 *   - OpenAI-compatible inference
 *   - Fallback to local knowledge when provider unavailable
 *   - Tool-aware prompting with role-specific expertise
 *   - Structured JSON output for machine-readable responses
 */
final class EnhancedCloudflareAgent implements SpecialistAgent
{
    private string $role;
    private array $allowedTools;

    /** Recommended model per agent role */
    private const ROLE_MODELS = [
        'general'        => 'gpt-4o-mini',
        'market'         => 'gpt-4o',
        'sports'         => 'gpt-4o-mini',
        'lead_discovery' => 'gpt-4o-mini',
        'lottery'        => 'gpt-4o-mini',
        'language'       => 'gpt-4o-mini',
        'trading'        => 'gpt-4o',
        'video'          => 'gpt-4o-mini',
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
                'reason' => 'No AI provider configured. Ask an administrator to configure an OpenAI-compatible provider.',
            ];
        }

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

        // Call the configured provider via the standard OpenAI-compatible client
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
            'model' => (string) ($cfg['extra']['model'] ?? ''),
            'tools' => $this->allowedTools,
        ];
    }

    /**
     * Get the recommended model for a role
     */
    public static function modelFor(string $role): string
    {
        return self::ROLE_MODELS[$role] ?? 'gpt-4o-mini';
    }

    /**
     * Get all available roles with their models
     */
    public static function allRoleModels(): array
    {
        return self::ROLE_MODELS;
    }
}
