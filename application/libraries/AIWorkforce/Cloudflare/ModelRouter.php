<?php
namespace AIWorkforce\Cloudflare;

use AIWorkforce\ApiProviders;

/**
 * Centralized AI Model Gateway / Router
 *
 * All agents call models through this abstraction. It supports:
 * - Multiple providers (Cloudflare Workers AI, OpenAI-compatible)
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
        // Register Cloudflare Workers AI
        $llmCfg = ApiProviders::resolve('llm');
        if (is_array($llmCfg) && ($llmCfg['driver'] ?? '') === 'cloudflare_workers_ai') {
            $this->registerProvider('cloudflare', [
                'driver' => 'cloudflare_workers_ai',
                'config' => $llmCfg,
                'priority' => 1,
                'models' => $this->cloudflareModels(),
                'health' => ['status' => 'UNKNOWN', 'lastCheck' => null],
            ]);
        }

        // Register any OpenAI-compatible provider
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
        $driver = $provider['driver'];

        if ($driver === 'cloudflare_workers_ai') {
            return $this->callCloudflare($cfg, $messages, $model, $maxTokens);
        }

        // Fallback to standard ApiProviders::openaiChat
        return ApiProviders::openaiChat($cfg, $messages, $maxTokens);
    }

    /**
     * Call Cloudflare Workers AI directly
     */
    private function callCloudflare(array $cfg, array $messages, string $model, int $maxTokens): ?string
    {
        $account = (string) ($cfg['account_id'] ?? '');
        $token = (string) ($cfg['secrets']['token'] ?? '');
        $gateway = $cfg['extra']['gateway'] ?? null;

        if ($account === '' || $token === '') return null;

        if ($gateway) {
            $url = "https://gateway.ai.cloudflare.com/v1/{$account}/{$gateway}/workers-ai/{$model}";
        } else {
            $url = "https://api.cloudflare.com/client/v4/accounts/{$account}/ai/run/{$model}";
        }

        $body = json_encode([
            'messages' => $messages,
            'max_tokens' => $maxTokens,
        ], JSON_UNESCAPED_SLASHES);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode >= 400) {
            throw new \RuntimeException("Cloudflare API error: {$error} (HTTP {$httpCode})");
        }

        $payload = json_decode($response, true);
        $answer = $payload['result']['response'] ?? $payload['response'] ?? $payload['choices'][0]['message']['content'] ?? null;

        return is_string($answer) && trim($answer) !== '' ? mb_substr(trim($answer), 0, 4000) : null;
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
        // Cloudflare Workers AI: ~$0.01 per 1000 tokens (varies by model)
        $rates = [
            'cloudflare' => 0.00001,
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

    private function cloudflareModels(): array
    {
        return [
            '@cf/meta/llama-3.1-8b-instruct',
            '@cf/meta/llama-3.1-70b-instruct',
            '@cf/mistral/mistral-7b-instruct-v0.1',
            '@cf/google/gemma-7b-it',
            '@hf/microsoft/phi-2',
        ];
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
