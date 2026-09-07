<?php
namespace AIWorkforce\Cloudflare;

use AIWorkforce\ApiProviders;

/**
 * Centralized AI Model Gateway / Router
 *
 * All agents call models through this abstraction. It supports:
 * - Multiple OpenAI-compatible providers with automatic failover
 * - Automatic failover between providers and models
 * - Rate limiting per provider/model
 * - Usage tracking (tokens, cost, latency)
 * - Provider health monitoring
 * - Request signing and retry logic
 *
 * Architecture rule: No agent ever calls a provider directly.
 * Every model call flows through this router.
 */
class ModelRouter
{
    /** @var array<string,array> Provider registry */
    private array $providers = [];

    /** @var array<string,array<string,float>> Rate limit state [provider => [minute => count]] */
    private array $rateState = [];

    /** @var array<string,array<string,mixed>> Usage counters */
    private array $usage = [
        'totalCalls' => 0,
        'totalTokens' => 0,
        'totalCostUsd' => 0.0,
        'byProvider' => [],
        'byModel' => [],
        'byAgent' => [],
    ];

    /** @var array<string,array<string,mixed>> Provider health state */
    private array $health = [];

    /** @var callable|null Audit logger */
    private $audit;

    public function __construct(?callable $audit = null)
    {
        $this->audit = $audit;
        $this->discoverProviders();
    }

    /**
     * Discover and register all configured model providers
     */
    private function discoverProviders(): void
    {
        // Register the configured LLM provider (OpenAI-compatible)
        $llmCfg = ApiProviders::resolve('llm');
        if (is_array($llmCfg) && ($llmCfg['driver'] ?? '') === 'openai_compatible') {
            $this->registerProvider('openai_compat', [
                'driver' => 'openai_compatible',
                'config' => $llmCfg,
                'priority' => 2,
                'models' => $this->openaiCompatibleModels(),
                'health' => ['status' => 'UNKNOWN', 'lastCheck' => null],
            ]);
        }

        // Register language_ai provider (if different from llm)
        $langCfg = ApiProviders::resolve('language_ai');
        if (is_array($langCfg) && ($langCfg['driver'] ?? '') !== ($llmCfg['driver'] ?? '')) {
            $this->registerProvider('language_ai', [
                'driver' => $langCfg['driver'] ?? 'unknown',
                'config' => $langCfg,
                'priority' => 3,
                'models' => ['default'],
                'health' => ['status' => 'UNKNOWN', 'lastCheck' => null],
            ]);
        }
    }

    /**
     * Register a model provider
     */
    public function registerProvider(string $name, array $config): void
    {
        $this->providers[$name] = array_merge([
            'driver' => 'unknown',
            'config' => [],
            'priority' => 10,
            'models' => [],
            'health' => ['status' => 'UNKNOWN', 'lastCheck' => null, 'latencyMs' => null],
            'rateLimit' => ['rpm' => 60, 'tpm' => 100000],
        ], $config);
    }

    /**
     * Complete a chat completion request with automatic failover
     *
     * @param array  $messages   [{role, content}, ...]
     * @param array  $options    [model, agent, max_tokens, temperature, ...]
     * @return array|null        [content, model, provider, latencyMs, tokens]
     */
    public function chat(array $messages, array $options = []): ?array
    {
        $requestedModel = $options['model'] ?? null;
        $agent = $options['agent'] ?? 'unknown';
        $maxTokens = (int) ($options['max_tokens'] ?? 512);
        $maxAttempts = 3;
        $lastError = null;

        // Build provider list: preferred first, then fallback
        $providers = $this->selectProviders($requestedModel);

        foreach ($providers as $providerName => $provider) {
            for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
                // Check rate limit
                if (!$this->checkRateLimit($providerName)) {
                    continue;
                }

                $start = microtime(true);
                try {
                    $model = $this->selectModel($provider, $requestedModel);
                    $result = $this->callProvider($providerName, $provider, $messages, $model, $maxTokens, $options);

                    $latencyMs = round((microtime(true) - $start) * 1000);

                    if ($result !== null) {
                        $tokens = $this->estimateTokens($messages, $result);
                        $cost = $this->estimateCost($providerName, $model, $tokens);

                        // Update health
                        $this->providers[$providerName]['health'] = [
                            'status' => 'HEALTHY',
                            'lastCheck' => gmdate('c'),
                            'latencyMs' => $latencyMs,
                            'lastSuccess' => gmdate('c'),
                        ];

                        // Track usage
                        $this->trackUsage($providerName, $model, $agent, $tokens, $cost, $latencyMs);

                        return [
                            'content' => $result,
                            'model' => $model,
                            'provider' => $providerName,
                            'driver' => $provider['driver'],
                            'latencyMs' => $latencyMs,
                            'tokens' => $tokens,
                            'costUsd' => $cost,
                            'attempt' => $attempt + 1,
                        ];
                    }
                } catch (\Throwable $e) {
                    $lastError = $e->getMessage();
                    $latencyMs = round((microtime(true) - $start) * 1000);
                    $this->recordFailure($providerName, $lastError, $latencyMs);
                }
            }
        }

        // All providers failed
        $this->auditLog('MODEL_ROUTER_ALL_FAILED', [
            'agent' => $agent,
            'model' => $requestedModel,
            'error' => $lastError,
            'providersTried' => array_keys($providers),
        ]);

        return null;
    }

    /**
     * Select providers in priority order, with fallback
     */
    private function selectProviders(?string $requestedModel): array
    {
        $sorted = $this->providers;
        uasort($sorted, fn($a, $b) => ($a['priority'] ?? 10) <=> ($b['priority'] ?? 10));

        // Filter to healthy providers first, then include degraded
        $healthy = array_filter($sorted, fn($p) => ($p['health']['status'] ?? 'UNKNOWN') !== 'DOWN');
        if (empty($healthy)) {
            return $sorted; // Return all if none are healthy
        }
        return $healthy;
    }

    /**
     * Select the best model for the provider
     */
    private function selectModel(array $provider, ?string $requested): string
    {
        if ($requested && in_array($requested, $provider['models'] ?? [], true)) {
            return $requested;
        }
        return $provider['models'][0] ?? 'default';
    }

    /**
     * Call a specific provider
     */
    private function callProvider(string $name, array $provider, array $messages, string $model, int $maxTokens, array $options): ?string
    {
        $cfg = $provider['config'];

        // All model calls go through the standard OpenAI-compatible chat client.
        return ApiProviders::openaiChat($cfg, $messages, $maxTokens);
    }

    /**
     * Check rate limit for a provider
     */
    private function checkRateLimit(string $provider): bool
    {
        $limit = $this->providers[$provider]['rateLimit']['rpm'] ?? 60;
        $minute = (int) (time() / 60);
        $key = "{$provider}:{$minute}";

        if (!isset($this->rateState[$key])) {
            $this->rateState[$key] = 0;
        }

        if ($this->rateState[$key] >= $limit) {
            return false;
        }

        $this->rateState[$key]++;
        return true;
    }

    /**
     * Record a provider failure
     */
    private function recordFailure(string $provider, string $error, float $latencyMs): void
    {
        $h = $this->providers[$provider]['health'] ?? ['status' => 'UNKNOWN'];
        $failures = ($h['failures'] ?? 0) + 1;
        $status = $failures >= 5 ? 'DOWN' : ($failures >= 2 ? 'DEGRADED' : ($h['status'] ?? 'UNKNOWN'));

        $this->providers[$provider]['health'] = [
            'status' => $status,
            'lastCheck' => gmdate('c'),
            'latencyMs' => $latencyMs,
            'lastError' => $error,
            'failures' => $failures,
            'lastFailure' => gmdate('c'),
        ];
    }

    /**
     * Track usage statistics
     */
    private function trackUsage(string $provider, string $model, string $agent, int $tokens, float $cost, float $latencyMs): void
    {
        $this->usage['totalCalls']++;
        $this->usage['totalTokens'] += $tokens;
        $this->usage['totalCostUsd'] += $cost;

        $this->usage['byProvider'][$provider] = ($this->usage['byProvider'][$provider] ?? 0) + 1;
        $this->usage['byModel'][$model] = ($this->usage['byModel'][$model] ?? 0) + 1;
        $this->usage['byAgent'][$agent] = ($this->usage['byAgent'][$agent] ?? 0) + 1;

        $this->auditLog('MODEL_CALL', [
            'provider' => $provider,
            'model' => $model,
            'agent' => $agent,
            'tokens' => $tokens,
            'costUsd' => $cost,
            'latencyMs' => $latencyMs,
        ]);
    }

    /**
     * Estimate token count
     */
    private function estimateTokens(array $messages, string $response): int
    {
        $input = 0;
        foreach ($messages as $m) {
            $input += (int) ceil(mb_strlen((string) ($m['content'] ?? '')) / 4);
        }
        $output = (int) ceil(mb_strlen($response) / 4);
        return $input + $output;
    }

    /**
     * Estimate cost in USD
     */
    private function estimateCost(string $provider, string $model, int $tokens): float
    {
        // Approximate per-token cost estimates (USD)
        $rates = [
            'openai_compat' => 0.00002,
            'language_ai' => 0.00001,
        ];
        $rate = $rates[$provider] ?? 0.00002;
        return round($tokens * $rate, 6);
    }

    /**
     * Get router status
     */
    public function status(): array
    {
        return [
            'providers' => array_map(fn($p) => [
                'driver' => $p['driver'],
                'priority' => $p['priority'],
                'health' => $p['health'],
                'models' => $p['models'],
                'rateLimit' => $p['rateLimit'],
            ], $this->providers),
            'usage' => $this->usage,
            'configured' => !empty($this->providers),
        ];
    }

    /**
     * Get usage statistics
     */
    public function usageStats(): array
    {
        return $this->usage;
    }

    /**
     * List available models
     */
    public function availableModels(): array
    {
        $out = [];
        foreach ($this->providers as $name => $p) {
            foreach ($p['models'] as $m) {
                $out[$m] = $out[$m] ?? [];
                $out[$m][] = $name;
            }
        }
        return $out;
    }

    private function openaiCompatibleModels(): array
    {
        return ['default'];
    }

    private function auditLog(string $type, array $detail): void
    {
        if ($this->audit) {
            try {
                ($this->audit)($type, $type, $detail);
            } catch (\Throwable $e) {
                // Silent
            }
        }
    }
}
