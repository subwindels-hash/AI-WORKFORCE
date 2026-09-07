<?php
/**
 * OpenAI provider — chat, Responses API, structured output, embeddings, image
 * generation, moderation and model listing, plus the OpenAI connection test.
 *
 * No network: the injectable ApiProviders::$http transport is staged, so the
 * assertions exercise the exact request URLs, headers and payloads the provider
 * would send to the OpenAI REST API.
 */

use AIWorkforce\ApiProviders;
use AIWorkforce\Providers\OpenAIProvider;

function fx_openai_stub(array $responses, ?array &$calls): callable
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

test('OpenAI provider canonicalizes pasted base URLs', function () {
    $a = new OpenAIProvider(['api_key' => 'sk-test', 'base_url' => 'https://api.openai.com/v1/chat/completions']);
    assert_equals('https://api.openai.com/v1', $a->status()['baseUrl'], 'strips endpoint path');

    $b = new OpenAIProvider(['api_key' => 'sk-test', 'base_url' => 'https://api.openai.com']);
    assert_equals('https://api.openai.com/v1', $b->status()['baseUrl'], 'appends /v1 to OpenAI host');

    $c = new OpenAIProvider(['api_key' => 'sk-test', 'base_url' => '']);
    assert_equals('https://api.openai.com/v1', $c->status()['baseUrl'], 'defaults to OpenAI root');

    $d = new OpenAIProvider(['api_key' => 'sk-test', 'base_url' => 'https://llm.example/v1']);
    assert_equals('https://llm.example/v1', $d->status()['baseUrl'], 'compatible host left untouched');
});

test('OpenAI chat completion posts to /chat/completions with auth', function () {
    $prev = ApiProviders::$http;
    $calls = [];
    try {
        ApiProviders::$http = fx_openai_stub([
            ['status' => 200, 'body' => json_encode([
                'model' => 'gpt-4o-mini',
                'choices' => [['message' => ['content' => 'Hello!'], 'finish_reason' => 'stop']],
                'usage' => ['total_tokens' => 9],
            ])],
        ], $calls);
        $p = new OpenAIProvider(['api_key' => 'sk-test', 'base_url' => 'https://api.openai.com/v1', 'model' => 'gpt-4o-mini']);
        $r = $p->chat([['role' => 'user', 'content' => 'Hi']]);
        assert_false(isset($r['error']), 'no error');
        assert_equals('Hello!', $r['content'], 'content returned');
        assert_equals('gpt-4o-mini', $r['model'], 'model returned');
        assert_equals('https://api.openai.com/v1/chat/completions', $calls[0]['url'], 'chat url');
        $body = json_decode((string) $calls[0]['body'], true);
        assert_equals('gpt-4o-mini', $body['model'], 'model in payload');
        assert_equals('Hi', $body['messages'][0]['content'], 'message forwarded');
        assert_contains('Authorization: Bearer sk-test', implode("\n", $calls[0]['headers']), 'bearer auth');
    } finally {
        ApiProviders::$http = $prev;
    }
});

test('OpenAI Responses API posts to /responses and returns output_text', function () {
    $prev = ApiProviders::$http;
    $calls = [];
    try {
        ApiProviders::$http = fx_openai_stub([
            ['status' => 200, 'body' => json_encode([
                'model' => 'gpt-6-astra',
                'output_text' => 'Once upon a time...',
                'usage' => ['total_tokens' => 12],
            ])],
        ], $calls);
        $r = (new OpenAIProvider(['api_key' => 'sk-test', 'base_url' => 'https://api.openai.com/v1', 'model' => 'gpt-6-astra']))
            ->responses('Tell me a bedtime story');
        assert_false(isset($r['error']), 'no error');
        assert_equals('Once upon a time...', $r['content'], 'output_text mapped to content');
        assert_equals('https://api.openai.com/v1/responses', $calls[0]['url'], 'responses url');
        $body = json_decode((string) $calls[0]['body'], true);
        assert_equals('Tell me a bedtime story', $body['input'], 'input forwarded');
    } finally {
        ApiProviders::$http = $prev;
    }
});

test('OpenAI structured output sends a JSON schema and decodes the reply', function () {
    $prev = ApiProviders::$http;
    $calls = [];
    try {
        ApiProviders::$http = fx_openai_stub([
            ['status' => 200, 'body' => json_encode([
                'choices' => [['message' => ['content' => '{"ok":true,"score":7}']]],
            ])],
        ], $calls);
        $r = (new OpenAIProvider(['api_key' => 'sk-test', 'base_url' => 'https://api.openai.com/v1', 'model' => 'gpt-4o-mini']))
            ->structuredJson([['role' => 'user', 'content' => 'score this']], [
                'type' => 'object',
                'properties' => ['ok' => ['type' => 'boolean'], 'score' => ['type' => 'integer']],
                'required' => ['ok', 'score'],
            ], ['schema_name' => 'my_result']);
        assert_equals(['ok' => true, 'score' => 7], $r, 'decoded structured object');
        $body = json_decode((string) $calls[0]['body'], true);
        assert_equals('json_schema', $body['response_format']['type'] ?? null, 'json schema mode');
        assert_equals('my_result', $body['response_format']['json_schema']['name'] ?? null, 'schema name');
        assert_true(($body['response_format']['json_schema']['strict'] ?? false) === true, 'strict mode');
    } finally {
        ApiProviders::$http = $prev;
    }
});

test('OpenAI embeddings return vectors', function () {
    $prev = ApiProviders::$http;
    $calls = [];
    try {
        ApiProviders::$http = fx_openai_stub([
            ['status' => 200, 'body' => json_encode([
                'model' => 'text-embedding-3-small',
                'data' => [['embedding' => [0.1, 0.2, 0.3]]],
                'usage' => ['total_tokens' => 4],
            ])],
        ], $calls);
        $r = (new OpenAIProvider(['api_key' => 'sk-test', 'base_url' => 'https://api.openai.com/v1', 'model' => 'text-embedding-3-small']))
            ->embedding('hello');
        assert_false(isset($r['error']), 'no error');
        assert_equals([[0.1, 0.2, 0.3]], $r['embeddings'], 'vector returned');
        assert_equals('https://api.openai.com/v1/embeddings', $calls[0]['url'], 'embeddings url');
    } finally {
        ApiProviders::$http = $prev;
    }
});

test('OpenAI image generation decodes b64_json', function () {
    $prev = ApiProviders::$http;
    $calls = [];
    try {
        ApiProviders::$http = fx_openai_stub([
            ['status' => 200, 'body' => json_encode([
                'created' => 1700000000,
                'data' => [['b64_json' => 'aGVsbG8=', 'revised_prompt' => 'revised']],
            ])],
        ], $calls);
        $r = (new OpenAIProvider(['api_key' => 'sk-test', 'base_url' => 'https://api.openai.com/v1', 'model' => 'gpt-image-1']))
            ->image('a cat');
        assert_false(isset($r['error']), 'no error');
        assert_equals('aGVsbG8=', $r['images'][0]['image'], 'image payload');
        assert_equals('b64_json', $r['images'][0]['format'], 'format');
        assert_equals('https://api.openai.com/v1/images/generations', $calls[0]['url'], 'images url');
        $body = json_decode((string) $calls[0]['body'], true);
        assert_equals('gpt-image-1', $body['model'], 'model in payload');
    } finally {
        ApiProviders::$http = $prev;
    }
});

test('OpenAI moderation reports flags and category scores', function () {
    $prev = ApiProviders::$http;
    $calls = [];
    try {
        ApiProviders::$http = fx_openai_stub([
            ['status' => 200, 'body' => json_encode([
                'model' => 'omni-moderation-latest',
                'results' => [[
                    'flagged' => true,
                    'categories' => ['hate' => true],
                    'category_scores' => ['hate' => 0.99],
                ]],
            ])],
        ], $calls);
        $r = (new OpenAIProvider(['api_key' => 'sk-test', 'base_url' => 'https://api.openai.com/v1']))
            ->moderate('some text');
        assert_false(isset($r['error']), 'no error');
        assert_true($r['results'][0]['flagged'], 'flagged');
        assert_equals(0.99, $r['results'][0]['category_scores']['hate'], 'score');
        assert_equals('https://api.openai.com/v1/moderations', $calls[0]['url'], 'moderations url');
    } finally {
        ApiProviders::$http = $prev;
    }
});

test('OpenAI models listing uses GET', function () {
    $prev = ApiProviders::$http;
    $calls = [];
    try {
        ApiProviders::$http = fx_openai_stub([
            ['status' => 200, 'body' => json_encode(['data' => [['id' => 'gpt-4o-mini'], ['id' => 'gpt-4o']]])],
        ], $calls);
        $r = (new OpenAIProvider(['api_key' => 'sk-test', 'base_url' => 'https://api.openai.com/v1']))->models();
        assert_false(isset($r['error']), 'no error');
        assert_equals(['gpt-4o-mini', 'gpt-4o'], $r['models'], 'ids listed');
        assert_equals('https://api.openai.com/v1/models', $calls[0]['url'], 'models url');
        assert_true($calls[0]['body'] === null, 'models list is a GET');
    } finally {
        ApiProviders::$http = $prev;
    }
});

test('OpenAI provider fails closed on API errors and missing config', function () {
    $prev = ApiProviders::$http;
    try {
        ApiProviders::$http = function () {
            return ['status' => 401, 'body' => '{"error":{"message":"Incorrect API key provided"}}'];
        };
        $r = (new OpenAIProvider(['api_key' => 'sk-bad', 'base_url' => 'https://api.openai.com/v1', 'model' => 'gpt-4o-mini']))
            ->chat([['role' => 'user', 'content' => 'hi']]);
        assert_true(isset($r['error']), 'error surfaced');
        assert_contains('Incorrect API key provided', $r['error'], 'upstream message surfaced');
    } finally {
        ApiProviders::$http = $prev;
    }

    $unconfigured = (new OpenAIProvider(['api_key' => '', 'base_url' => 'https://api.openai.com/v1', 'model' => 'gpt-4o-mini']))
        ->chat([['role' => 'user', 'content' => 'hi']]);
    assert_true(isset($unconfigured['error']), 'missing key fails closed');
});

test('ApiProviders OpenAI capability helpers delegate through the provider config', function () {
    $prev = ApiProviders::$http;
    $calls = [];
    try {
        ApiProviders::$http = fx_openai_stub([
            ['status' => 200, 'body' => json_encode(['data' => [['embedding' => [0.5]]]])],
        ], $calls);
        $cfg = [
            'driver' => 'openai_compatible',
            'base_url' => 'https://api.openai.com/v1',
            'extra' => ['model' => 'text-embedding-3-small'],
            'secrets' => ['api_key' => 'sk-test'],
        ];
        $r = ApiProviders::openaiEmbedding($cfg, 'hello');
        assert_false(isset($r['error']), 'no error');
        assert_equals([[0.5]], $r['embeddings'], 'vector via static helper');
        assert_equals('https://api.openai.com/v1/embeddings', $calls[0]['url'], 'url via static helper');
    } finally {
        ApiProviders::$http = $prev;
    }
});

test('OpenAI connection test probes /models', function () {
    $prev = ApiProviders::$http;
    $calls = [];
    try {
        ApiProviders::$http = fx_openai_stub([
            ['status' => 200, 'body' => '{"data":[]}'],
        ], $calls);
        $res = ApiProviders::test(
            ['driver' => 'openai_compatible', 'service' => 'llm', 'base_url' => 'https://api.openai.com/v1/chat/completions', 'extra' => []],
            ['api_key' => 'sk-test']
        );
        assert_true($res['ok'], 'connected');
        assert_contains('Connected', $res['message'], 'connected message');
        assert_equals('https://api.openai.com/v1/models', $calls[0]['url'], 'probes /models');
    } finally {
        ApiProviders::$http = $prev;
    }
});

test('OpenAI connection test names the fix for a rejected key', function () {
    $prev = ApiProviders::$http;
    try {
        ApiProviders::$http = function () {
            return ['status' => 401, 'body' => '{"error":{"message":"bad key"}}'];
        };
        $res = ApiProviders::test(
            ['driver' => 'openai_compatible', 'service' => 'llm', 'base_url' => 'https://api.openai.com/v1', 'extra' => []],
            ['api_key' => 'sk-bad']
        );
        assert_false($res['ok'], 'rejected');
        assert_contains('rejected', $res['message'], 'names the auth fix');
    } finally {
        ApiProviders::$http = $prev;
    }
});

test('OpenAI capabilities are catalogued as services and drivers', function () {
    $services = ApiProviders::services();
    assert_true(isset($services['moderation']), 'moderation service catalogued');
    assert_true(in_array('openai_compatible', $services['moderation']['drivers'], true), 'moderation uses OpenAI driver');
    assert_true(in_array('openai_compatible', $services['image_generation']['drivers'], true), 'image generation uses OpenAI driver');
    assert_true(in_array('openai_compatible', $services['text_embeddings']['drivers'], true), 'embeddings use OpenAI driver');
    assert_true(in_array('openai_compatible', $services['llm']['drivers'], true), 'llm uses OpenAI driver');

    $drivers = ApiProviders::drivers();
    $fields = array_column($drivers['openai_compatible']['fields'], 'name');
    foreach (['base_url', 'api_key', 'model', 'organization', 'project'] as $f) {
        assert_true(in_array($f, $fields, true), "openai driver exposes field $f");
    }
});

test('OpenAI token counting is a sane heuristic', function () {
    assert_equals(0, OpenAIProvider::tokenCount(''), 'empty is zero');
    assert_equals(1, OpenAIProvider::tokenCount('hi'), 'short text is at least one');
    assert_true(OpenAIProvider::tokenCount('hello world') >= 2, 'grows with length');
});
