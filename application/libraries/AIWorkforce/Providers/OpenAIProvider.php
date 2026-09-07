<?php
namespace AIWorkforce\Providers;

use AIWorkforce\ApiProviders;

/**
 * OpenAI (and OpenAI-compatible) REST provider.
 *
 * Covers the server-side OpenAI API surface:
 *   - Chat Completions       (/chat/completions)
 *   - Responses API          (/responses) — the API the OpenAI quickstart uses
 *   - Structured output      (JSON schema / JSON object via chat)
 *   - Embeddings             (/embeddings)
 *   - Image generation       (/images/generations)
 *   - Moderation             (/moderations)
 *   - Model listing          (/models)
 *
 * Every request flows through ApiProviders::http() so the transport is shared
 * with the Admin → API connection test and stays stub-able in tests. HTTP
 * failures return an ['error' => ...] array (never a thrown exception for a
 * bad status code), matching the other provider classes. Credentials are
 * read from the same hydrated provider config ApiProviders::resolve() returns.
 *
 * Fail-closed: a missing key or model returns an error result, never a call.
 */
class OpenAIProvider
{
    private string $baseUrl;
    private string $apiKey;
    private string $organization;
    private string $project;
    private string $defaultModel;
    private ?string $lastError = null;
    private int $lastStatus = 0;

    public function __construct(array $config)
    {
        $this->apiKey = (string) ($config['secrets']['api_key'] ?? $config['api_key'] ?? '');
        $this->baseUrl = self::normalizeBase((string) ($config['base_url'] ?? ''));
        $this->organization = (string) ($config['extra']['organization'] ?? $config['organization'] ?? '');
        $this->project = (string) ($config['extra']['project'] ?? $config['project'] ?? '');
        $this->defaultModel = (string) ($config['extra']['model'] ?? $config['model'] ?? '');
    }

    /**
     * Canonicalize a pasted base URL into the API root the provider appends
     * its own paths to. Accepts "https://api.openai.com", ".../v1" or a
     * pasted ".../v1/chat/completions" and normalizes them all the same way.
     */
    private static function normalizeBase(string $base): string
    {
        $base = rtrim(trim($base), '/');
        // Strip a pasted endpoint path; this class appends the right one.
        $base = (string) preg_replace(
            '#/(chat/completions|responses|embeddings|images/generations|moderations|models)$#i',
            '',
            $base
        );
        if ($base === '') {
            return 'https://api.openai.com/v1';
        }
        $host = strtolower((string) (parse_url($base, PHP_URL_HOST) ?? ''));
        // The official OpenAI host needs an explicit /v1 prefix.
        if (!preg_match('#/v\d+$#i', $base) && preg_match('#(^|\.)openai\.com$#', $host)) {
            $base .= '/v1';
        }
        return $base;
    }

    private function headers(): array
    {
        $h = ['Content-Type: application/json', 'Authorization: Bearer ' . $this->apiKey];
        if ($this->organization !== '') $h[] = 'OpenAI-Organization: ' . $this->organization;
        if ($this->project !== '') $h[] = 'OpenAI-Project: ' . $this->project;
        return $h;
    }

    /** POST JSON to a path under the API root; returns the decoded body or an error array. */
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
                $this->lastError = 'OpenAI returned a non-JSON response (HTTP ' . $status . ').';
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
        $msg = trim($msg) !== '' ? trim($msg) : 'OpenAI API request failed (HTTP ' . $status . ').';
        return mb_substr($msg, 0, 255);
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->baseUrl !== '';
    }

    public function status(): array
    {
        return [
            'provider' => 'openai',
            'configured' => $this->isConfigured(),
            'baseUrl' => $this->baseUrl,
            'organization' => $this->organization !== '' ? $this->organization : null,
            'project' => $this->project !== '' ? $this->project : null,
            'defaultModel' => $this->defaultModel !== '' ? $this->defaultModel : null,
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

    /** Rough token estimate (~4 characters/token, OpenAI's documented heuristic). */
    public static function tokenCount(string $text): int
    {
        $text = trim($text);
        return $text === '' ? 0 : (int) max(1, ceil(mb_strlen($text) / 4));
    }

    /**
     * Chat completion. Returns ['content' => ..., 'model' => ..., 'usage' => ...]
     * or ['error' => ...]. Supports tools/function-calling and response_format
     * via $options.
     */
    public function chat(array $messages, array $options = []): ?array
    {
        if (!$this->isConfigured()) return ['error' => 'OpenAI API key is not configured.'];
        $model = (string) ($options['model'] ?? $this->defaultModel);
        if ($model === '') return ['error' => 'No OpenAI model is configured.'];
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
        $this->lastError = 'OpenAI returned no message content.';
        return ['error' => $this->lastError, 'status' => $this->lastStatus];
    }

    /**
     * Responses API call (the primary API in the OpenAI quickstart).
     * Returns ['content' => ..., 'model' => ..., 'usage' => ...] or ['error' => ...].
     */
    public function responses(string $input, array $options = []): ?array
    {
        if (!$this->isConfigured()) return ['error' => 'OpenAI API key is not configured.'];
        $model = (string) ($options['model'] ?? $this->defaultModel);
        if ($model === '') return ['error' => 'No OpenAI model is configured.'];
        $payload = ['model' => $model, 'input' => $input];
        if (isset($options['instructions'])) $payload['instructions'] = (string) $options['instructions'];
        if (isset($options['temperature'])) $payload['temperature'] = (float) $options['temperature'];
        if (isset($options['top_p'])) $payload['top_p'] = (float) $options['top_p'];
        if (isset($options['max_output_tokens'])) $payload['max_output_tokens'] = (int) $options['max_output_tokens'];
        if (isset($options['reasoning_effort'])) $payload['reasoning_effort'] = $options['reasoning_effort'];
        if (!empty($options['tools']) && is_array($options['tools'])) $payload['tools'] = $options['tools'];
        if (isset($options['tool_choice'])) $payload['tool_choice'] = $options['tool_choice'];
        if (!empty($options['text']) && is_array($options['text'])) $payload['text'] = $options['text'];

        $decoded = $this->post('/responses', $payload);
        if (isset($decoded['error'])) return $decoded;

        $text = '';
        if (isset($decoded['output_text']) && is_string($decoded['output_text'])) {
            $text = trim($decoded['output_text']);
        }
        if ($text === '' && isset($decoded['output']) && is_array($decoded['output'])) {
            $parts = [];
            foreach ($decoded['output'] as $item) {
                if (!is_array($item)) continue;
                foreach ((array) ($item['content'] ?? []) as $c) {
                    if (is_array($c) && isset($c['text']) && is_string($c['text'])) $parts[] = $c['text'];
                    elseif (is_string($c)) $parts[] = $c;
                }
            }
            $text = trim(implode("\n", $parts));
        }
        return [
            'content' => $text,
            'model' => $decoded['model'] ?? $model,
            'status' => $decoded['status'] ?? null,
            'usage' => $decoded['usage'] ?? null,
            'raw' => $decoded,
        ];
    }

    /**
     * Structured output via the chat completions JSON-schema mode.
     * Returns the decoded array, or ['error' => ...].
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
        if (!$r || isset($r['error'])) return $r ?? ['error' => $this->lastError ?? 'OpenAI structured output failed.'];
        $decoded = json_decode((string) ($r['content'] ?? ''), true);
        return is_array($decoded) ? $decoded : ['error' => 'OpenAI returned invalid JSON for structured output.'];
    }

    /** JSON-object mode (no schema). Returns the decoded array, or ['error' => ...]. */
    public function jsonObject(array $messages, array $options = []): ?array
    {
        $options['response_format'] = ['type' => 'json_object'];
        $r = $this->chat($messages, $options);
        if (!$r || isset($r['error'])) return $r ?? ['error' => $this->lastError ?? 'OpenAI JSON output failed.'];
        $decoded = json_decode((string) ($r['content'] ?? ''), true);
        return is_array($decoded) ? $decoded : ['error' => 'OpenAI returned invalid JSON.'];
    }

    /**
     * Text embeddings. Accepts a string or an array of strings.
     * Returns ['embeddings' => [ [float,...], ... ], 'model' => ..., 'usage' => ...].
     */
    public function embedding($input, array $options = []): ?array
    {
        if (!$this->isConfigured()) return ['error' => 'OpenAI API key is not configured.'];
        $model = (string) ($options['model'] ?? $this->defaultModel);
        if ($model === '') return ['error' => 'No OpenAI embedding model is configured.'];
        $payload = ['model' => $model, 'input' => $input];
        if (isset($options['dimensions'])) $payload['dimensions'] = (int) $options['dimensions'];
        $decoded = $this->post('/embeddings', $payload);
        if (isset($decoded['error'])) return $decoded;
        $vectors = [];
        foreach ((array) ($decoded['data'] ?? []) as $d) {
            if (is_array($d) && isset($d['embedding'])) $vectors[] = $d['embedding'];
        }
        if (!$vectors) {
            $this->lastError = 'OpenAI returned no embedding vectors.';
            return ['error' => $this->lastError, 'status' => $this->lastStatus];
        }
        return ['embeddings' => $vectors, 'model' => $decoded['model'] ?? $model, 'usage' => $decoded['usage'] ?? null, 'raw' => $decoded];
    }

    /**
     * Image generation (DALL·E / gpt-image). Returns
     * ['images' => [ ['image'=>..., 'format'=>'b64_json'|'url', 'revised_prompt'=>...], ... ]].
     */
    public function image(string $prompt, array $options = []): ?array
    {
        if (!$this->isConfigured()) return ['error' => 'OpenAI API key is not configured.'];
        $model = (string) ($options['model'] ?? $this->defaultModel);
        if ($model === '') $model = 'dall-e-3';
        $payload = ['model' => $model, 'prompt' => $prompt];
        if (isset($options['n'])) $payload['n'] = (int) $options['n'];
        if (isset($options['size'])) $payload['size'] = (string) $options['size'];
        if (isset($options['quality'])) $payload['quality'] = (string) $options['quality'];
        if (isset($options['style'])) $payload['style'] = (string) $options['style'];
        $payload['response_format'] = (string) ($options['response_format'] ?? ($model === 'dall-e-3' ? 'url' : 'b64_json'));
        $decoded = $this->post('/images/generations', $payload);
        if (isset($decoded['error'])) return $decoded;
        $images = [];
        foreach ((array) ($decoded['data'] ?? []) as $it) {
            if (!is_array($it)) continue;
            if (!empty($it['b64_json'])) {
                $images[] = ['image' => $it['b64_json'], 'format' => 'b64_json', 'revised_prompt' => $it['revised_prompt'] ?? null];
            } elseif (!empty($it['url'])) {
                $images[] = ['image' => $it['url'], 'format' => 'url', 'revised_prompt' => $it['revised_prompt'] ?? null];
            }
        }
        if (!$images) {
            $this->lastError = 'OpenAI returned no image data.';
            return ['error' => $this->lastError, 'status' => $this->lastStatus];
        }
        return ['images' => $images, 'model' => $decoded['model'] ?? $model, 'created' => $decoded['created'] ?? null, 'raw' => $decoded];
    }

    /**
     * Moderation. Accepts a string or an array of strings.
     * Returns ['results' => [ ['flagged'=>bool, 'categories'=>..., 'category_scores'=>...], ... ]].
     */
    public function moderate($input, array $options = []): ?array
    {
        if (!$this->isConfigured()) return ['error' => 'OpenAI API key is not configured.'];
        $model = (string) ($options['model'] ?? 'omni-moderation-latest');
        $payload = ['model' => $model, 'input' => $input];
        $decoded = $this->post('/moderations', $payload);
        if (isset($decoded['error'])) return $decoded;
        $results = [];
        foreach ((array) ($decoded['results'] ?? []) as $r) {
            if (!is_array($r)) continue;
            $results[] = [
                'flagged' => !empty($r['flagged']),
                'categories' => $r['categories'] ?? [],
                'category_scores' => $r['category_scores'] ?? [],
            ];
        }
        return ['results' => $results, 'model' => $decoded['model'] ?? $model, 'raw' => $decoded];
    }

    /** List available model IDs, or ['error' => ...]. */
    public function models(): ?array
    {
        if (!$this->isConfigured()) return ['error' => 'OpenAI API key is not configured.'];
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
