<?php
/**
 * xAI Grok provider — chat completions, structured output, embeddings,
 * model listing, team ID extraction, base URL normalization, and connection test.
 *
 * No network: the injectable ApiProviders::$http transport is staged, so the
 * assertions exercise the exact request URLs, headers and payloads the provider
 * would send to the xAI REST API (https://api.x.ai/v1).
 */

use AIWorkforce\ApiProviders;
use AIWorkforce\Providers\GrokProvider;

function fx_grok_stub(array $responses, ?array &$calls): callable
{
    $calls = [];
    $i = 0;
    return function (string $url, array $headers = [], ?string $body = null) use (&$calls, &$i, $responses) {
        $calls[] = ['url' => $url, 'headers' => $headers, 'body' => $body];
        $r = $responses[$i] ?? ['status' => 0, 'error' => 'no staged response'];
        $i++;
        return $r;
    };
}

test('Grok provider canonicalizes pasted base URLs and console URLs', function () {
    $a = new GrokProvider(['api_key' => 'xai-test', 'base_url' => 'https://api.x.ai/v1/chat/completions']);
    assert_equals('https://api.x.ai/v1', $a->status()['baseUrl'], 'strips endpoint path');

    $b = new GrokProvider(['api_key' => 'xai-test', 'base_url' => 'https://console.x.ai/team/97c85eb6-af25-4109-8554-4c6cbff12ee8?utm_source=website&utm_medium=referral']);
    assert_equals('https://api.x.ai/v1', $b->status()['baseUrl'], 'normalizes team console URL to api root');
    assert_equals('97c85eb6-af25-4109-8554-4c6cbff12ee8', $b->status()['teamId'], 'extracts team ID from console URL');

    $c = new GrokProvider(['api_key' => 'xai-test', 'base_url' => '']);
    assert_equals('https://api.x.ai/v1', $c->status()['baseUrl'], 'defaults to xAI API root');

    $d = new GrokProvider(['api_key' => 'xai-test', 'base_url' => '[https://console.x.ai/team/97c85eb6-af25-4109-8554-4c6cbff12ee8](https://console.x.ai/team/97c85eb6-af25-4109-8554-4c6cbff12ee8)']);
    assert_equals('https://api.x.ai/v1', $d->status()['baseUrl'], 'handles markdown-wrapped URLs');
    assert_equals('97c85eb6-af25-4109-8554-4c6cbff12ee8', $d->status()['teamId'], 'extracts team ID from markdown link');

    $e = new GrokProvider(['api_key' => 'xai-test', 'base_url' => 'https://custom-grok-proxy.internal/v1']);
    assert_equals('https://custom-grok-proxy.internal/v1', $e->status()['baseUrl'], 'proxy host left untouched');
});

test('Grok chat completion posts to /chat/completions with Bearer auth and default model', function () {
    $prev = ApiProviders::$http;
    $calls = [];
    try {
        ApiProviders::$http = fx_grok_stub([
            ['status' => 200, 'body' => json_encode([
                'model' => 'grok-2-latest',
                'choices' => [['message' => ['content' => 'Hello from Grok!'], 'finish_reason' => 'stop']],
                'usage' => ['total_tokens' => 10],
            ])],
        ], $calls);
        $p = new GrokProvider([
            'api_key' => 'xai-test-key',
            'base_url' => 'https://api.x.ai/v1',
            'team_id' => '97c85eb6-af25-4109-8554-4c6cbff12ee8',
        ]);
        $r = $p->chat([['role' => 'user', 'content' => 'Hello Grok']]);
        assert_false(isset($r['error']), 'no error');
        assert_equals('Hello from Grok!', $r['content'], 'content returned');
        assert_equals('grok-2-latest', $r['model'], 'default model returned');
        assert_equals('https://api.x.ai/v1/chat/completions', $calls[0]['url'], 'chat url');
        $body = json_decode((string) $calls[0]['body'], true);
        assert_equals('grok-2-latest', $body['model'], 'model in payload');
        assert_equals('Hello Grok', $body['messages'][0]['content'], 'message forwarded');
        $headersText = implode("\n", $calls[0]['headers']);
        assert_contains('Authorization: Bearer xai-test-key', $headersText, 'bearer auth header');
        assert_contains('X-Team-Id: 97c85eb6-af25-4109-8554-4c6cbff12ee8', $headersText, 'team ID header');
    } finally {
        ApiProviders::$http = $prev;
    }
});

test('Grok Responses API posts instructions and prompt to chat', function () {
    $prev = ApiProviders::$http;
    $calls = [];
    try {
        ApiProviders::$http = fx_grok_stub([
            ['status' => 200, 'body' => json_encode([
                'model' => 'grok-2-mini',
                'choices' => [['message' => ['content' => 'Succinct answer']]],
                'usage' => ['total_tokens' => 8],
            ])],
        ], $calls);
        $r = (new GrokProvider(['api_key' => 'xai-test', 'base_url' => 'https://api.x.ai/v1', 'model' => 'grok-2-mini']))
            ->responses('Analyze this dataset', ['instructions' => 'Be concise.']);
        assert_false(isset($r['error']), 'no error');
        assert_equals('Succinct answer', $r['content'], 'content mapped');
        assert_equals('https://api.x.ai/v1/chat/completions', $calls[0]['url'], 'chat completions url');
        $body = json_decode((string) $calls[0]['body'], true);
        assert_equals('Be concise.', $body['messages'][0]['content'], 'system instruction forwarded');
        assert_equals('Analyze this dataset', $body['messages'][1]['content'], 'user input forwarded');
    } finally {
        ApiProviders::$http = $prev;
    }
});

test('Grok structured output sends a JSON schema and decodes the result', function () {
    $prev = ApiProviders::$http;
    $calls = [];
    try {
        ApiProviders::$http = fx_grok_stub([
            ['status' => 200, 'body' => json_encode([
                'choices' => [['message' => ['content' => '{"sentiment":"bullish","confidence":0.95}']]],
            ])],
        ], $calls);
        $r = (new GrokProvider(['api_key' => 'xai-test', 'base_url' => 'https://api.x.ai/v1', 'model' => 'grok-2-latest']))
            ->structuredJson([['role' => 'user', 'content' => 'Classify BTC trend']], [
                'type' => 'object',
                'properties' => [
                    'sentiment' => ['type' => 'string'],
                    'confidence' => ['type' => 'number'],
                ],
                'required' => ['sentiment', 'confidence'],
            ], ['schema_name' => 'trend_sentiment']);
        assert_equals(['sentiment' => 'bullish', 'confidence' => 0.95], $r, 'decoded structured json');
        $body = json_decode((string) $calls[0]['body'], true);
        assert_equals('json_schema', $body['response_format']['type'] ?? null, 'json schema mode');
        assert_equals('trend_sentiment', $body['response_format']['json_schema']['name'] ?? null, 'schema name');
    } finally {
        ApiProviders::$http = $prev;
    }
});

test('Grok text embeddings return vectors', function () {
    $prev = ApiProviders::$http;
    $calls = [];
    try {
        ApiProviders::$http = fx_grok_stub([
            ['status' => 200, 'body' => json_encode([
                'model' => 'grok-2-latest',
                'data' => [['embedding' => [0.12, 0.34, 0.56]]],
                'usage' => ['total_tokens' => 5],
            ])],
        ], $calls);
        $r = (new GrokProvider(['api_key' => 'xai-test', 'base_url' => 'https://api.x.ai/v1']))
            ->embedding('market trend vector');
        assert_false(isset($r['error']), 'no error');
        assert_equals([[0.12, 0.34, 0.56]], $r['embeddings'], 'vector returned');
        assert_equals('https://api.x.ai/v1/embeddings', $calls[0]['url'], 'embeddings url');
    } finally {
        ApiProviders::$http = $prev;
    }
});

test('Grok models listing probes /models with GET', function () {
    $prev = ApiProviders::$http;
    $calls = [];
    try {
        ApiProviders::$http = fx_grok_stub([
            ['status' => 200, 'body' => json_encode([
                'data' => [
                    ['id' => 'grok-2-latest'],
                    ['id' => 'grok-2-mini'],
                    ['id' => 'grok-beta'],
                    ['id' => 'grok-3'],
                ],
            ])],
        ], $calls);
        $r = (new GrokProvider(['api_key' => 'xai-test', 'base_url' => 'https://api.x.ai/v1']))->models();
        assert_false(isset($r['error']), 'no error');
        assert_equals(['grok-2-latest', 'grok-2-mini', 'grok-beta', 'grok-3'], $r['models'], 'ids listed');
        assert_equals('https://api.x.ai/v1/models', $calls[0]['url'], 'models url');
        assert_true($calls[0]['body'] === null, 'models list is a GET');
    } finally {
        ApiProviders::$http = $prev;
    }
});

test('Grok provider fails closed on API errors and missing credentials', function () {
    $prev = ApiProviders::$http;
    try {
        ApiProviders::$http = function () {
            return ['status' => 401, 'body' => '{"error":{"message":"Invalid xAI API key provided"}}'];
        };
        $r = (new GrokProvider(['api_key' => 'xai-bad-key', 'base_url' => 'https://api.x.ai/v1', 'model' => 'grok-2-latest']))
            ->chat([['role' => 'user', 'content' => 'hi']]);
        assert_true(isset($r['error']), 'error surfaced');
        assert_contains('Invalid xAI API key provided', $r['error'], 'upstream error returned');
    } finally {
        ApiProviders::$http = $prev;
    }

    $unconfigured = (new GrokProvider(['api_key' => '', 'base_url' => 'https://api.x.ai/v1']))
        ->chat([['role' => 'user', 'content' => 'hi']]);
    assert_true(isset($unconfigured['error']), 'missing key fails closed');
});

test('ApiProviders Grok capability helpers delegate through provider config', function () {
    $prev = ApiProviders::$http;
    $calls = [];
    try {
        ApiProviders::$http = fx_grok_stub([
            ['status' => 200, 'body' => json_encode([
                'choices' => [['message' => ['content' => 'Grok static helper reply']]],
            ])],
            ['status' => 200, 'body' => json_encode([
                'choices' => [['message' => ['content' => 'Grok generic reply']]],
            ])],
        ], $calls);
        $cfg = [
            'driver' => 'grok',
            'base_url' => 'https://api.x.ai/v1',
            'extra' => ['model' => 'grok-2-latest'],
            'secrets' => ['api_key' => 'xai-test'],
        ];
        $r = ApiProviders::grokChat($cfg, [['role' => 'user', 'content' => 'hello']]);
        assert_equals('Grok static helper reply', $r, 'reply via static helper');
        assert_equals('https://api.x.ai/v1/chat/completions', $calls[0]['url'], 'url via static helper');

        // Also verify generic openaiChat helper routes Grok driver seamlessly
        $rGeneric = ApiProviders::openaiChat($cfg, [['role' => 'user', 'content' => 'hello generic']]);
        assert_equals('Grok generic reply', $rGeneric, 'generic openaiChat routes grok driver');
    } finally {
        ApiProviders::$http = $prev;
    }
});

test('Grok connection test probes /models and supports console URLs', function () {
    $prev = ApiProviders::$http;
    $calls = [];
    try {
        ApiProviders::$http = fx_grok_stub([
            ['status' => 200, 'body' => '{"data":[{"id":"grok-2-latest"}]}'],
        ], $calls);
        $res = ApiProviders::test(
            [
                'driver' => 'grok',
                'service' => 'llm',
                'base_url' => 'https://console.x.ai/team/97c85eb6-af25-4109-8554-4c6cbff12ee8?utm_source=website',
                'extra' => [],
            ],
            ['api_key' => 'xai-test']
        );
        assert_true($res['ok'], 'connected');
        assert_contains('Connected', $res['message'], 'connected message');
        assert_equals('https://api.x.ai/v1/models', $calls[0]['url'], 'probes /models after normalization');
    } finally {
        ApiProviders::$http = $prev;
    }
});

test('Grok connection test names the fix for a rejected key', function () {
    $prev = ApiProviders::$http;
    try {
        ApiProviders::$http = function () {
            return ['status' => 401, 'body' => '{"error":{"message":"Unauthorized"}}'];
        };
        $res = ApiProviders::test(
            ['driver' => 'grok', 'service' => 'llm', 'base_url' => 'https://api.x.ai/v1', 'extra' => []],
            ['api_key' => 'xai-bad']
        );
        assert_false($res['ok'], 'rejected');
        assert_contains('rejected', $res['message'], 'names the auth fix');
        assert_contains('https://console.x.ai/', $res['message'], 'points to console');
    } finally {
        ApiProviders::$http = $prev;
    }
});

test('Grok capabilities are catalogued as services and driver fields', function () {
    $services = ApiProviders::services();
    assert_true(in_array('grok', $services['llm']['drivers'], true), 'llm includes grok driver');
    assert_true(in_array('grok', $services['translation']['drivers'], true), 'translation includes grok driver');
    assert_true(in_array('grok', $services['language_ai']['drivers'], true), 'language_ai includes grok driver');
    assert_true(in_array('grok', $services['summarization']['drivers'], true), 'summarization includes grok driver');
    assert_true(in_array('grok', $services['classification']['drivers'], true), 'classification includes grok driver');
    assert_true(in_array('grok', $services['text_embeddings']['drivers'], true), 'embeddings include grok driver');
    assert_true(in_array('grok', $services['moderation']['drivers'], true), 'moderation includes grok driver');

    $drivers = ApiProviders::drivers();
    assert_true(isset($drivers['grok']), 'grok driver catalogued');
    $fields = array_column($drivers['grok']['fields'], 'name');
    foreach (['base_url', 'api_key', 'model', 'team_id'] as $f) {
        assert_true(in_array($f, $fields, true), "grok driver exposes field $f");
    }
});

test('Grok token counting heuristic behaves consistently', function () {
    assert_equals(0, GrokProvider::tokenCount(''), 'empty is zero');
    assert_equals(1, GrokProvider::tokenCount('hi'), 'short text is 1');
    assert_true(GrokProvider::tokenCount('hello grok xai workforce') >= 3, 'grows with length');
});
