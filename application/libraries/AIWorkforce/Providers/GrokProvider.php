<?php
namespace AIWorkforce\Providers;

use AIWorkforce\ApiProviders;

/**
 * xAI Grok REST provider (https://x.ai / https://console.x.ai).
 *
 * Covers the server-side Grok API surface:
 *   - Chat Completions       (/chat/completions)
 *   - Responses API          (/responses / chat fallback)
 *   - Structured output      (JSON schema / JSON object via chat)
 *   - Embeddings             (/embeddings)
 *   - Model listing          (/models)
 *
 * Features:
 *   - Automatic base URL normalization: handles console URLs (console.x.ai),
 *     team URLs (console.x.ai/team/<team-id>), markdown links, and naked domains,
 *     always canonicalizing to https://api.x.ai/v1.
 *   - Automatic team ID extraction from team console URLs.
 *   - Shared HTTP transport with ApiProviders::http() for testing and proxy compatibility.
 *   - Fail-closed error contract: missing keys or bad requests return error arrays
 *     without throwing exceptions.
 */
class GrokProvider
{
    public const DEFAULT_BASE_URL = 'https://api.x.ai/v1';
    public const DEFAULT_MODEL = 'grok-2-latest';

    private string $baseUrl;
    private string $apiKey;
    private ?string $teamId;
    private string $defaultModel;
    private ?string $lastError = null;
    private int $lastStatus = 0;

    public function __construct(array $config)
    {
        $this->apiKey = (string) ($config['secrets']['api_key'] ?? $config['api_key'] ?? '');
        $extractedTeamId = null;
        $this->baseUrl = self::normalizeBase((string) ($config['base_url'] ?? ''), $extractedTeamId);
        $this->teamId = (string) ($config['extra']['team_id'] ?? $config['team_id'] ?? $extractedTeamId ?? '') ?: null;
        $this->defaultModel = (string) ($config['extra']['model'] ?? $config['model'] ?? self::DEFAULT_MODEL);
        if ($this->defaultModel === '') {
            $this->defaultModel = self::DEFAULT_MODEL;
        }
    }

    /**
     * Canonicalize a pasted base URL into the xAI API root (https://api.x.ai/v1).
     * Accepts:
     *   - Empty string (defaults to https://api.x.ai/v1)
     *   - Console URL: https://console.x.ai/team/97c85eb6-af25-4109-8554-4c6cbff12ee8?utm_source=...
     *   - Markdown link: [https://console.x.ai/...](https://console.x.ai/...)
     *   - Naked domain: x.ai, api.x.ai, console.x.ai
     *   - Endpoint paths: https://api.x.ai/v1/chat/completions -> https://api.x.ai/v1
     */
    public static function normalizeBase(string $base, ?string &$extractedTeamId = null): string
    {
        $base = trim($base);
        // Strip markdown link wrapping if present: [url](target) or [text](url)
        if (preg_match('#\[(.*?)\]\((https?://[^)]+)\)#i', $base, $m)) {
            $base = $m[2];
        }

        // Extract team ID if found in a console URL path
        if (preg_match('#(?:console\.x\.ai|x\.ai)/team/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})#i', $base, $tm)) {
            $extractedTeamId = $tm[1];
        }

        // Strip query string and fragment
        $base = (string) preg_replace('/[?#].*$/', '', $base);
        $base = rtrim(trim($base), '/');

        // Strip endpoint paths
        $base = (string) preg_replace(
            '#/(chat/completions|responses|embeddings|images/generations|moderations|models)$#i',
            '',
            $base
        );

        if ($base === '') {
            return self::DEFAULT_BASE_URL;
        }

        $host = strtolower((string) (parse_url($base, PHP_URL_HOST) ?? ''));

        // Handle x.ai and console.x.ai hostnames
        if (preg_match('#(^|\.)x\.ai$#i', $host)) {
            // Strip /team/<id> or other console subpaths if present
            if ($host === 'console.x.ai' || str_contains($base, '/team/')) {
                return self::DEFAULT_BASE_URL;
            }
            if (!preg_match('#/v\d+$#i', $base)) {
                return 'https://api.x.ai/v1';
            }
            return 'https://' . ltrim(preg_replace('#^https?://#i', '', $base), '/');
        }

        // If naked URL without scheme
        if (!preg_match('#^https?://#i', $base)) {
            $base = 'https://' . $base;
        }

        return $base;
    }

    /**
     * Extract a team UUID from a console.x.ai URL if present.
     */
    public static function extractTeamId(string $url): ?string
    {
        if (preg_match('#[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}#i', $url, $m)) {
            return strtolower($m[0]);
        }
        return null;
    }

    private function headers(): array
    {
        $h = ['Content-Type: application/json', 'Authorization: Bearer ' . $this->apiKey];
        if ($this->teamId !== null && $this->teamId !== '') {
            $h[] = 'X-Team-Id: ' . $this->teamId;
        }
        return $h;
    }

    /** POST JSON to a path under the xAI API root; returns decoded JSON or an error array. */
    private function post(string $path, array $payload): array
    {
        $url = $this->baseUrl . $path;
        $resp = ApiProviders::http(
            $url,
            $this->headers(),
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
        $status = (int) ($resp['status'] ?? 0);
        $body = (string) ($resp['body'] ?? '');
        $this->lastStatus = $status;
        $this->lastError = null;

        $decoded = json_decode($body, true);
        if ($status >= 200 && $status < 300) {
            if (!is_array($decoded)) {
                $this->lastError = 'Grok returned a non-JSON response (HTTP ' . $status . ').';
                return ['error' => $this->lastError, 'status' => $status];
            }
            return $decoded;
        }
        $this->lastError = $this->extractError($decoded, $body, $status);
        return ['error' => $this->lastError, 'status' => $status];
    }

    private function extractError(?array $decoded, string $body, int $status): string
    {
        $msg = '';
        if (is_array($decoded)) {
            $e = $decoded['error'] ?? null;
            if (is_array($e) && isset($e['message'])) {
                $msg = (string) $e['message'];
            } elseif (is_string($e)) {
                $msg = $e;
            } elseif (is_array($decoded['errors'] ?? null)) {
                $first = $decoded['errors'][0] ?? null;
                if (is_array($first) && isset($first['message'])) $msg = (string) $first['message'];
            }
        }
        if ($msg === '' && $body !== '') {
            $msg = preg_replace('/\s+/', ' ', $body) ?? $body;
        }
        $msg = trim($msg) !== '' ? trim($msg) : 'Grok API request failed (HTTP ' . $status . ').';
        return mb_substr($msg, 0, 255);
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->baseUrl !== '';
    }

    public function status(): array
    {
        return [
            'provider' => 'grok',
            'configured' => $this->isConfigured(),
            'baseUrl' => $this->baseUrl,
            'teamId' => $this->teamId,
            'defaultModel' => $this->defaultModel,
            'lastError' => $this->lastError,
            'lastStatus' => $this->lastStatus,
        ];
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    public function lastStatus(): int
    {
        return $this->lastStatus;
    }

    /** Rough token estimate (~4 characters/token). */
    public static function tokenCount(string $text): int
    {
        $text = trim($text);
        return $text === '' ? 0 : (int) max(1, ceil(mb_strlen($text) / 4));
    }

    /**
     * Chat completion with Grok.
     * Returns ['content' => ..., 'model' => ..., 'usage' => ...] or ['error' => ...].
     */
    public function chat(array $messages, array $options = []): ?array
    {
        if (!$this->isConfigured()) return ['error' => 'Grok API key is not configured.'];
        $model = (string) ($options['model'] ?? $this->defaultModel);
        if ($model === '') $model = self::DEFAULT_MODEL;

        $payload = ['model' => $model, 'messages' => array_values($messages)];
        if (isset($options['temperature'])) $payload['temperature'] = (float) $options['temperature'];
        if (isset($options['top_p'])) $payload['top_p'] = (float) $options['top_p'];
        if (isset($options['max_tokens'])) $payload['max_tokens'] = (int) $options['max_tokens'];
        if (isset($options['max_completion_tokens'])) $payload['max_completion_tokens'] = (int) $options['max_completion_tokens'];
        if (isset($options['stop'])) $payload['stop'] = $options['stop'];
        if (isset($options['seed'])) $payload['seed'] = (int) $options['seed'];
        if (!empty($options['tools']) && is_array($options['tools'])) $payload['tools'] = $options['tools'];
        if (isset($options['tool_choice'])) $payload['tool_choice'] = $options['tool_choice'];
        if (!empty($options['response_format']) && is_array($options['response_format'])) {
            $payload['response_format'] = $options['response_format'];
        }

        $decoded = $this->post('/chat/completions', $payload);
        if (isset($decoded['error'])) return $decoded;

        $choice = $decoded['choices'][0] ?? [];
        $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];
        if (!empty($message['tool_calls']) && is_array($message['tool_calls'])) {
            return [
                'tool_calls' => $message['tool_calls'],
                'model' => $decoded['model'] ?? $model,
                'usage' => $decoded['usage'] ?? null,
                'raw' => $decoded,
            ];
        }
        $content = $message['content'] ?? null;
        if (is_string($content) && trim($content) !== '') {
            return [
                'content' => trim($content),
                'model' => $decoded['model'] ?? $model,
                'finish_reason' => $choice['finish_reason'] ?? null,
                'usage' => $decoded['usage'] ?? null,
                'raw' => $decoded,
            ];
        }
        $this->lastError = 'Grok returned no message content.';
        return ['error' => $this->lastError, 'status' => $this->lastStatus];
    }

    /**
     * Responses API / single-input helper for Grok.
     */
    public function responses(string $input, array $options = []): ?array
    {
        $messages = [];
        if (!empty($options['instructions'])) {
            $messages[] = ['role' => 'system', 'content' => (string) $options['instructions']];
        }
        $messages[] = ['role' => 'user', 'content' => $input];
        return $this->chat($messages, $options);
    }

    /**
     * Structured JSON output via Grok chat completions.
     */
    public function structuredJson(array $messages, array $jsonSchema, array $options = []): ?array
    {
        $name = (string) ($options['schema_name'] ?? 'response');
        $name = (string) preg_replace('/[^A-Za-z0-9_-]/', '_', $name);
        if ($name === '') $name = 'response';
        unset($options['schema_name']);

        $options['response_format'] = [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => substr($name, 0, 64),
                'strict' => true,
                'schema' => $jsonSchema,
            ],
        ];
        $r = $this->chat($messages, $options);
        if (!$r || isset($r['error'])) return $r ?? ['error' => $this->lastError ?? 'Grok structured output failed.'];
        $decoded = json_decode((string) ($r['content'] ?? ''), true);
        return is_array($decoded) ? $decoded : ['error' => 'Grok returned invalid JSON for structured output.'];
    }

    /** JSON-object mode (no schema). */
    public function jsonObject(array $messages, array $options = []): ?array
    {
        $options['response_format'] = ['type' => 'json_object'];
        $r = $this->chat($messages, $options);
        if (!$r || isset($r['error'])) return $r ?? ['error' => $this->lastError ?? 'Grok JSON output failed.'];
        $decoded = json_decode((string) ($r['content'] ?? ''), true);
        return is_array($decoded) ? $decoded : ['error' => 'Grok returned invalid JSON.'];
    }

    /** Text embeddings via Grok embeddings endpoint. */
    public function embedding($input, array $options = []): ?array
    {
        if (!$this->isConfigured()) return ['error' => 'Grok API key is not configured.'];
        $model = (string) ($options['model'] ?? 'grok-2-latest');
        $payload = ['model' => $model, 'input' => $input];
        if (isset($options['dimensions'])) $payload['dimensions'] = (int) $options['dimensions'];
        $decoded = $this->post('/embeddings', $payload);
        if (isset($decoded['error'])) return $decoded;
        $vectors = [];
        foreach ((array) ($decoded['data'] ?? []) as $d) {
            if (is_array($d) && isset($d['embedding'])) $vectors[] = $d['embedding'];
        }
        if (!$vectors) {
            $this->lastError = 'Grok returned no embedding vectors.';
            return ['error' => $this->lastError, 'status' => $this->lastStatus];
        }
        return ['embeddings' => $vectors, 'model' => $decoded['model'] ?? $model, 'usage' => $decoded['usage'] ?? null, 'raw' => $decoded];
    }

    /** List available model IDs from xAI API. */
    public function models(): ?array
    {
        if (!$this->isConfigured()) return ['error' => 'Grok API key is not configured.'];
        $url = $this->baseUrl . '/models';
        $resp = ApiProviders::http($url, $this->headers());
        $status = (int) ($resp['status'] ?? 0);
        $body = (string) ($resp['body'] ?? '');
        $this->lastStatus = $status;
        $decoded = json_decode($body, true);
        if ($status >= 200 && $status < 300 && is_array($decoded)) {
            $this->lastError = null;
            $ids = [];
            foreach ((array) ($decoded['data'] ?? []) as $m) {
                if (is_array($m) && isset($m['id'])) $ids[] = (string) $m['id'];
            }
            return ['models' => $ids, 'raw' => $decoded];
        }
        $this->lastError = $this->extractError($decoded, $body, $status);
        return ['error' => $this->lastError, 'status' => $status];
    }
}
