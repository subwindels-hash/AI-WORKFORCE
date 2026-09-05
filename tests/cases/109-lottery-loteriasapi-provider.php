<?php
/**
 * WINDELS Lottery Intelligence — loteriasapi.com (Spanish lottery API) adapter.
 *
 * Verifies the vendor contract documented at
 * https://loteriasapi.com/docs/getting-started and
 * https://loteriasapi.com/docs/results — base URL
 * https://api.loteriasapi.com/api/v1 (the /v1 root the marketing pages
 * advertise answers 404 on every route) — is mapped into the provider-neutral
 * draw shape, that credentials never leak, that failure modes are classified
 * honestly, and that a real vendor payload survives the full ingestion path
 * (validation → historical database).
 */
use AIWorkforce\Lottery\LoteriasApiProvider;
use AIWorkforce\Lottery\LotteryIntelligence;

/** Payload shape the live API serves (camelCase, integer cents + formatted). */
function fx_loterias_live_payload(string $date = '2026-04-10', string $drawId = '2026029'): array
{
    return [
        'id' => 'clx8j2k9m0002abcd1234efgh',
        'game' => ['slug' => 'euromillones', 'name' => 'Euromillones'],
        'drawId' => $drawId,
        'drawDate' => $date,
        'dayOfWeek' => 'Viernes',
        'year' => (int) substr($date, 0, 4),
        'status' => 'COMPLETED',
        'combination' => [7, 12, 29, 33, 45],
        'resultData' => ['estrellas' => [3, 9]],
        'jackpot' => '13000000000',            // integer cents
        'jackpotFormatted' => '130.000.000,00 €',
        'prizes' => [
            ['category' => 1, 'categoryName' => '5 + 2 estrellas', 'winners' => 0,
                'prizeAmount' => '13000000000', 'formattedPrize' => '130.000.000,00 €'],
            ['category' => 2, 'categoryName' => '5 + 1 estrella', 'winners' => 3,
                'prizeAmount' => '125000000', 'formattedPrize' => '1.250.000,00 €'],
        ],
    ];
}

/** Envelope the live API wraps a single draw in. */
function fx_loterias_live_envelope(array $draw, string $timestamp = '2026-04-10T22:00:00.000Z'): array
{
    return ['success' => true, 'data' => $draw, 'timestamp' => $timestamp];
}

/** Legacy snake_case payload from the older vendor documentation. */
function fx_loterias_payload(string $date = '2026-04-10', string $id = '2026/029'): array
{
    return [
        'game' => 'EUROMILLONES',
        'draw_date' => $date,
        'draw_id' => $id,
        'numbers' => [7, 12, 29, 33, 44],
        'stars' => [3, 11],
        'el_millon' => 'HXG12345',
        'prizes' => [
            ['category' => '1', 'match' => '5+2', 'winners' => 0, 'prize' => 0],
            ['category' => '2', 'match' => '5+1', 'winners' => 2, 'prize' => 412358.21],
            ['category' => '3', 'match' => '5', 'winners' => 8, 'prize' => 45123.87],
        ],
        'jackpot_next' => 130000000,
        'meta' => ['source' => 'SELAE', 'updated_at' => $date . 'T22:15:42Z'],
    ];
}

/** @return array{0:LoteriasApiProvider,1:ArrayObject} */
function fx_loterias_provider(callable $responder, array $overrides = []): array
{
    $calls = new ArrayObject();
    $transport = function (string $url, array $headers) use ($responder, $calls): array {
        $calls->append(['url' => $url, 'headers' => $headers]);
        return $responder($url, $headers);
    };
    $provider = new LoteriasApiProvider(
        $overrides['base_url'] ?? null,
        $overrides['api_key'] ?? 'live-key-secret-123',
        $overrides['game'] ?? null,
        $overrides['enabled'] ?? true,
        $transport,
    );
    return [$provider, $calls];
}

function fx_loterias_audit(): \AIWorkforce\Persistence\AuditRepository
{
    return new class implements \AIWorkforce\Persistence\AuditRepository {
        public array $events = [];
        public function emit(string $t, string $s, array $d = [], string $a = 'system'): void { $this->events[] = ['type' => $t, 'actor' => $a, 'detail' => $d]; }
        public function recent(int $l = 100): array { return []; }
    };
}

function fx_loterias_json(array $body, int $status = 200): array
{
    return ['status' => $status, 'body' => json_encode($body)];
}

test('loteriasapi: base URL is canonicalised onto the /api/v1 root the vendor serves', function () {
    assert_equals('https://api.loteriasapi.com/api/v1', LoteriasApiProvider::normalizeBaseUrl(''));
    assert_equals('https://api.loteriasapi.com/api/v1', LoteriasApiProvider::normalizeBaseUrl('https://loteriasapi.com/en/euromillions-api'));
    assert_equals('https://api.loteriasapi.com/api/v1', LoteriasApiProvider::normalizeBaseUrl('https://api.loteriasapi.com'));
    assert_equals('https://api.loteriasapi.com/api/v1', LoteriasApiProvider::normalizeBaseUrl('https://api.loteriasapi.com/api/v1/'));
    // The /v1 root advertised on the marketing pages 404s on every route — it
    // is rewritten so an existing stored configuration heals itself.
    assert_equals('https://api.loteriasapi.com/api/v1', LoteriasApiProvider::normalizeBaseUrl('https://api.loteriasapi.com/v1'));
    assert_equals('https://api.loteriasapi.com/api/v1', LoteriasApiProvider::normalizeBaseUrl('http://api.loteriasapi.com/v1/'));
    assert_equals('https://api.loteriasapi.com/api/v2', LoteriasApiProvider::normalizeBaseUrl('https://api.loteriasapi.com/v2/'));
    // A pasted docs URL is reduced to its versioned root.
    assert_equals('https://api.loteriasapi.com/api/v1', LoteriasApiProvider::normalizeBaseUrl('https://api.loteriasapi.com/api/v1/results/euromillones/latest'));
    // A custom gateway stays exactly as configured.
    assert_equals('https://lottery.internal.example/api/v1', LoteriasApiProvider::normalizeBaseUrl('https://lottery.internal.example/api/v1/'));

    assert_equals('euromillones', LoteriasApiProvider::normalizeGame(''));
    assert_equals('euromillones', LoteriasApiProvider::normalizeGame('EuroMillions'));
    assert_equals('primitiva', LoteriasApiProvider::normalizeGame('Primitiva'));

    [$provider, $calls] = fx_loterias_provider(fn() => fx_loterias_json(fx_loterias_live_envelope(fx_loterias_live_payload())));
    assert_equals('https://api.loteriasapi.com/api/v1', $provider->baseUrl(), 'a stored legacy base URL is upgraded');
    $health = $provider->health();
    assert_equals('ONLINE', $health['state']);
    assert_equals('https://api.loteriasapi.com/api/v1/results/euromillones/latest', $calls[0]['url']);
    assert_true(in_array('x-api-key: live-key-secret-123', $calls[0]['headers'], true), 'auth uses the x-api-key header');
    assert_false($health['synthetic']);
    assert_equals('2026-04-10', $health['latestDrawDate']);
});

test('loteriasapi: the live vendor payload maps to the provider-neutral draw contract', function () {
    [$provider] = fx_loterias_provider(fn() => fx_loterias_json(fx_loterias_live_envelope(fx_loterias_live_payload())));
    $draw = $provider->normalizeDraw(fx_loterias_live_payload(), '2026-04-10T22:00:00.000Z');
    assert_equals('2026029', $draw['externalId'], 'drawId is the vendor key');
    assert_equals('2026-04-10', $draw['drawDate']);
    assert_equals([7, 12, 29, 33, 45], $draw['main'], 'combination maps to main');
    assert_equals([3, 9], $draw['stars'], 'resultData.estrellas maps to stars');
    assert_equals('130000000.00', $draw['jackpot'], 'the formatted euro amount is authoritative');
    assert_equals('2026-04-10T22:00:00.000Z', $draw['sourceTimestamp'], 'the envelope timestamp is the source timestamp');
    assert_true(str_contains($draw['source'], 'loteriasapi'), 'source attribution names the feed');
    assert_true($draw['rollover'], 'zero 5+2 winners is a rollover');
    assert_equals('0', $draw['winners']);
    assert_equals('COMPLETED', $draw['extra']['status']);
    assert_equals(2, $draw['extra']['prizeTiers']);

    // Integer cents are converted when no formatted string is present.
    $centsOnly = fx_loterias_live_payload('2026-04-07', '2026028');
    unset($centsOnly['jackpotFormatted']);
    assert_equals('130000000.00', $provider->normalizeDraw($centsOnly)['jackpot'], '13000000000 cents = 130.000.000,00 EUR');
});

test('loteriasapi: the legacy snake_case payload is still mapped', function () {
    [$provider] = fx_loterias_provider(fn() => fx_loterias_json(fx_loterias_payload()));
    $draw = $provider->normalizeDraw(fx_loterias_payload());
    assert_equals('2026/029', $draw['externalId']);
    assert_equals('2026-04-10', $draw['drawDate']);
    assert_equals([7, 12, 29, 33, 44], $draw['main']);
    assert_equals([3, 11], $draw['stars']);
    assert_equals('2026-04-10T22:15:42Z', $draw['sourceTimestamp']);
    assert_true($draw['rollover'], 'zero 5+2 winners is a rollover');
    assert_equals('0', $draw['winners']);
    assert_equals('HXG12345', $draw['extra']['elMillon']);
    assert_equals(130000000.0, $draw['extra']['jackpotNext']);
    assert_equals('SELAE', $draw['extra']['feedSource']);
});

test('loteriasapi: history uses the paged /range endpoint with an explicit window', function () {
    [$provider, $calls] = fx_loterias_provider(function (string $url) {
        if (str_contains($url, '/latest')) return fx_loterias_json(fx_loterias_live_envelope(fx_loterias_live_payload()));
        return fx_loterias_json([
            'success' => true,
            'data' => [
                fx_loterias_live_payload('2026-04-03', '2026027'),
                fx_loterias_live_payload('2026-04-10', '2026029'),
                fx_loterias_live_payload('2026-04-07', '2026028'),
            ],
            'meta' => ['total' => 3, 'page' => 1, 'limit' => 2, 'totalPages' => 2, 'hasNext' => true, 'hasPrev' => false],
        ]);
    });
    $draws = $provider->draws('2026-04-01', '2026-04-30', 2);
    assert_equals(2, count($draws));
    assert_equals('2026-04-10', $draws[0]['drawDate'], 'newest first');
    assert_equals('2026-04-07', $draws[1]['drawDate']);
    assert_contains('/api/v1/results/euromillones/range', $calls[0]['url']);
    assert_true(str_contains($calls[0]['url'], 'from=2026-04-01'), 'range query uses from/to');
    assert_true(str_contains($calls[0]['url'], 'to=2026-04-30'));
    assert_true(str_contains($calls[0]['url'], 'page=1'), 'paging starts at page 1');
});

test('loteriasapi: meta.hasNext drives paging and long windows are split at 365 days', function () {
    [$provider, $calls] = fx_loterias_provider(function (string $url) {
        if (str_contains($url, 'page=2')) {
            return fx_loterias_json(['success' => true,
                'data' => [fx_loterias_live_payload('2026-03-31', '2026026')],
                'meta' => ['page' => 2, 'hasNext' => false]]);
        }
        if (str_contains($url, '/range')) {
            return fx_loterias_json(['success' => true,
                'data' => [fx_loterias_live_payload('2026-04-10', '2026029')],
                'meta' => ['page' => 1, 'hasNext' => true]]);
        }
        return fx_loterias_json(['success' => true, 'data' => []]);
    });

    $paged = $provider->draws('2026-03-01', '2026-04-30', 50);
    assert_equals(2, count($paged), 'both pages are collected');
    assert_equals('2026-04-10', $paged[0]['drawDate']);
    assert_equals('2026-03-31', $paged[1]['drawDate']);

    [$split, $splitCalls] = fx_loterias_provider(fn(string $url) => fx_loterias_json([
        'success' => true,
        'data' => str_contains($url, 'from=2024-01-01')
            ? [fx_loterias_live_payload('2024-01-12', '2024003')]
            : [fx_loterias_live_payload('2025-01-10', '2025004')],
        'meta' => ['hasNext' => false],
    ]));
    $long = $split->draws('2024-01-01', '2025-06-30', 10);
    assert_equals(2, count($long));
    assert_equals(2, count($splitCalls), 'the vendor caps a range call at 365 days — the window is chunked');
    assert_contains('from=2024-01-01', $splitCalls[0]['url']);
    assert_contains('from=2024-12-31', $splitCalls[1]['url']);
});

test('loteriasapi: falls back to /latest when the range endpoint returns nothing', function () {
    [$provider, $calls] = fx_loterias_provider(function (string $url) {
        if (str_contains($url, '/latest')) return fx_loterias_json(fx_loterias_live_envelope(fx_loterias_live_payload()));
        return fx_loterias_json(['success' => true, 'data' => [], 'meta' => ['hasNext' => false]]);
    });
    $draws = $provider->draws(null, null, 5);
    assert_equals(1, count($draws));
    assert_equals('2026029', $draws[0]['externalId']);
    assert_equals('2026-04-10T22:00:00.000Z', $draws[0]['sourceTimestamp'], 'the envelope timestamp attributes the draw');
    assert_equals(2, count($calls), 'range attempt then latest fallback');
});

test('loteriasapi: single draws are fetched by date or resolved by vendor draw id', function () {
    [$provider, $calls] = fx_loterias_provider(fn(string $url) => str_contains($url, '/date/')
        ? fx_loterias_json(['success' => true, 'data' => [fx_loterias_live_payload()]])
        : fx_loterias_json(fx_loterias_live_envelope(fx_loterias_live_payload())));
    $draw = $provider->drawById('2026-04-10');
    assert_not_null($draw);
    assert_equals('https://api.loteriasapi.com/api/v1/results/euromillones/date/2026-04-10', $calls[0]['url']);

    // A vendor draw id is matched exactly out of a windowed range query.
    [$byId, $idCalls] = fx_loterias_provider(fn() => fx_loterias_json([
        'success' => true,
        'data' => [fx_loterias_live_payload('2026-04-07', '2026028'), fx_loterias_live_payload('2026-04-10', '2026029')],
        'meta' => ['hasNext' => false],
    ]));
    assert_equals('2026029', $byId->drawById('2026/029')['externalId']);
    assert_equals('2026029', $byId->drawById('2026029')['externalId']);
    assert_contains('/range', $idCalls[0]['url']);
    assert_null($byId->drawById('2026/999'), 'an id the feed does not return is never invented');

    assert_equals(3, count($idCalls), 'each id lookup costs one windowed range query');
    assert_null($provider->drawById('not-a-draw-id'), 'malformed ids never reach the network');

    $jackpot = $provider->jackpotInfo();
    assert_equals('130000000.00', $jackpot['value']);
    assert_equals('EUR', $jackpot['currency']);
    assert_true(str_contains($jackpot['note'], 'not used to infer'), 'jackpot carries the honesty note');
});

test('loteriasapi: one status render costs one /latest request (plan quotas are small)', function () {
    [$provider, $calls] = fx_loterias_provider(fn() => fx_loterias_json(fx_loterias_live_envelope(fx_loterias_live_payload())));
    assert_equals('ONLINE', $provider->health()['state']);
    assert_equals('130000000.00', $provider->jackpotInfo()['value']);
    assert_equals(1, count($calls), 'health() and jackpotInfo() share the memoised latest draw');
    assert_equals('ONLINE', $provider->health()['state']);
    assert_equals(1, count($calls), 'a repeated health check within the TTL costs nothing');
});

test('loteriasapi: failure modes are classified honestly and never leak the key', function () {
    foreach ([401 => 'OFFLINE', 403 => 'OFFLINE', 429 => 'OFFLINE', 404 => 'OFFLINE', 400 => 'OFFLINE', 0 => 'OFFLINE', 500 => 'OFFLINE'] as $status => $state) {
        [$provider] = fx_loterias_provider(fn() => ['status' => $status, 'body' => 'live-key-secret-123 denied']);
        $health = $provider->health();
        assert_equals($state, $health['state'], 'HTTP ' . $status);
        assert_false(str_contains(json_encode($health), 'live-key-secret-123'), 'HTTP ' . $status . ' must not leak the key');
    }

    // The vendor answers 404 with a JSON error body when the path is wrong —
    // the operator must be pointed at the /api/v1 root.
    [$notFound] = fx_loterias_provider(fn() => ['status' => 404,
        'body' => json_encode(['success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'Cannot GET /v1/results/euromillones/latest', 'statusCode' => 404]])]);
    assert_contains('/api/v1', $notFound->health()['message']);

    // A 200 carrying { success: false } is still a failure, not a draw.
    [$softFail] = fx_loterias_provider(fn() => fx_loterias_json([
        'success' => false, 'error' => ['code' => 'UNAUTHORIZED', 'message' => 'API key required', 'statusCode' => 401],
    ]));
    assert_equals('OFFLINE', $softFail->health()['state']);
    assert_contains('authentication rejected', $softFail->health()['message']);
    assert_equals([], $softFail->draws(null, null, 5), 'an error envelope yields no draws');

    [$badJson] = fx_loterias_provider(fn() => ['status' => 200, 'body' => '<html>marketing page</html>']);
    assert_equals('OFFLINE', $badJson->health()['state']);
    assert_equals([], $badJson->draws(null, null, 5), 'no data is preferable to fabricated data');
});

test('loteriasapi: stays disabled without a key and is never treated as licensed', function () {
    $noKey = new LoteriasApiProvider(null, '', null, true, fn() => fx_loterias_json(fx_loterias_payload()));
    assert_false($noKey->configured());
    assert_equals('UNCONFIGURED', $noKey->health()['state']);
    assert_equals([], $noKey->draws());
    assert_null($noKey->jackpotInfo());

    $off = new LoteriasApiProvider(null, 'k', null, false, fn() => fx_loterias_json(fx_loterias_payload()));
    assert_false($off->configured());
    assert_equals('DISABLED', $off->health()['state']);

    // A custom host typed as plaintext is refused …
    $insecure = new LoteriasApiProvider('http://lottery.internal.example/api/v1', 'k', null, true, fn() => fx_loterias_json(fx_loterias_payload()));
    assert_false($insecure->configured(), 'plaintext HTTP to a custom host is refused');

    // … while the vendor host is always upgraded to HTTPS + /api/v1.
    $upgraded = new LoteriasApiProvider('http://api.loteriasapi.com/v1', 'k', null, true, fn() => fx_loterias_json(fx_loterias_payload()));
    assert_true($upgraded->configured(), 'the vendor host is only ever reached over HTTPS');
    assert_equals('https://api.loteriasapi.com/api/v1', $upgraded->baseUrl());
});

test('loteriasapi: draws flow through validation into the historical database', function () {
    $repo = new LotteryRepositoryStub();
    $audit = fx_loterias_audit();
    [$provider] = fx_loterias_provider(function (string $url) {
        if (str_contains($url, '/latest')) return fx_loterias_json(fx_loterias_live_envelope(fx_loterias_live_payload()));
        return fx_loterias_json(['success' => true, 'data' => [
            fx_loterias_live_payload('2026-04-10', '2026029'),
            fx_loterias_live_payload('2026-04-07', '2026028'),
        ], 'meta' => ['hasNext' => false]]);
    });
    $intel = new LotteryIntelligence($repo, $audit, $provider);

    $status = $intel->status();
    assert_equals('ONLINE', $status['provider']['state']);
    assert_equals('130000000.00', $status['jackpot'], 'published jackpot surfaces on the dashboard');

    $sync = $intel->sync(10);
    assert_equals('OK', $sync['status']);
    assert_equals('loteriasapi', $sync['provider']);
    assert_equals(2, $sync['imported']);
    assert_equals(0, $sync['failed']);
    assert_equals(2, $intel->drawCount());

    // Idempotent re-sync — verified draws are never silently rewritten.
    $again = $intel->sync(10);
    assert_equals(0, $again['imported']);
    assert_equals(2, $again['unchanged']);

    $stored = $intel->presentDraw($repo->listDraws(['lotteryCode' => 'EUROMILLIONS'], 1)[0]);
    assert_equals([7, 12, 29, 33, 45], $stored['main_numbers']);
    assert_equals([3, 9], $stored['lucky_stars']);
    assert_true(str_contains((string) $stored['source'], 'loteriasapi'));
    assert_false(str_contains(json_encode($repo->draws), 'live-key-secret-123'), 'credentials never reach storage');
});

test('loteriasapi: malformed vendor numbers are rejected, not stored as official results', function () {
    $repo = new LotteryRepositoryStub();
    $audit = fx_loterias_audit();
    $bad = fx_loterias_live_payload('2026-04-14', '2026030');
    $bad['combination'] = [7, 12, 29, 99];   // wrong count AND out of range
    [$provider] = fx_loterias_provider(function (string $url) use ($bad) {
        if (str_contains($url, '/latest')) return fx_loterias_json(fx_loterias_live_envelope(fx_loterias_live_payload()));
        return fx_loterias_json(['success' => true, 'data' => [$bad], 'meta' => ['hasNext' => false]]);
    });
    $intel = new LotteryIntelligence($repo, $audit, $provider);
    $sync = $intel->sync(5);
    assert_equals(0, $sync['imported']);
    assert_equals(1, $sync['failed']);
    assert_equals(0, $intel->drawCount());
    $types = array_column($audit->events, 'type');
    assert_true(in_array('LOTTERY_DRAW_VALIDATION_FAILED', $types, true), 'rejection is audited');
});

test('loteriasapi is registered in API Management with a working connectivity test', function () {
    $services = \AIWorkforce\ApiProviders::services();
    assert_true(in_array('loteriasapi', $services['lottery']['drivers'], true), 'driver offered for the lottery service');
    $drivers = \AIWorkforce\ApiProviders::drivers();
    assert_true(isset($drivers['loteriasapi']));
    $fields = array_column($drivers['loteriasapi']['fields'], 'name');
    foreach (['base_url', 'api_key', 'game', 'timeout'] as $field) {
        assert_true(in_array($field, $fields, true), 'field ' . $field . ' is configurable');
    }
    foreach ($drivers['loteriasapi']['fields'] as $field) {
        if ($field['name'] === 'api_key') assert_true($field['secret'], 'the API key is stored as a secret');
        if ($field['name'] === 'base_url') assert_contains('/api/v1', $field['hint'], 'operators are told the /api prefix is required');
    }

    $prev = \AIWorkforce\ApiProviders::$http;
    try {
        $seen = [];
        \AIWorkforce\ApiProviders::$http = function (string $url, array $headers = [], ?string $body = null) use (&$seen) {
            $seen[] = ['url' => $url, 'headers' => $headers];
            return ['status' => 200, 'body' => json_encode(fx_loterias_live_envelope(fx_loterias_live_payload()))];
        };
        $ok = \AIWorkforce\ApiProviders::test(
            ['driver' => 'loteriasapi', 'base_url' => '', 'extra' => []],
            ['api_key' => 'test-key-abc']
        );
        assert_true($ok['ok'], $ok['message']);
        assert_true(str_contains($ok['message'], '2026-04-10'));
        assert_equals('https://api.loteriasapi.com/api/v1/results/euromillones/latest', $seen[0]['url']);

        // A stored legacy /v1 base URL must self-heal, not 404.
        $legacy = \AIWorkforce\ApiProviders::test(
            ['driver' => 'loteriasapi', 'base_url' => 'https://api.loteriasapi.com/v1', 'extra' => []],
            ['api_key' => 'test-key-abc']
        );
        assert_true($legacy['ok'], $legacy['message']);
        assert_equals('https://api.loteriasapi.com/api/v1/results/euromillones/latest', $seen[1]['url']);

        \AIWorkforce\ApiProviders::$http = fn() => ['status' => 401, 'body' => json_encode([
            'success' => false, 'error' => ['code' => 'UNAUTHORIZED', 'message' => 'Invalid API key', 'statusCode' => 401]])];
        $bad = \AIWorkforce\ApiProviders::test(['driver' => 'loteriasapi', 'base_url' => '', 'extra' => []], ['api_key' => 'nope']);
        assert_false($bad['ok']);
        assert_equals('Invalid API key', $bad['message']);

        // A 200 error envelope is reported as an auth failure, never as success.
        \AIWorkforce\ApiProviders::$http = fn() => ['status' => 200, 'body' => json_encode([
            'success' => false, 'error' => ['code' => 'UNAUTHORIZED', 'message' => 'API key required', 'statusCode' => 401]])];
        $soft = \AIWorkforce\ApiProviders::test(['driver' => 'loteriasapi', 'base_url' => '', 'extra' => []], ['api_key' => 'nope']);
        assert_false($soft['ok']);
        assert_equals('Invalid API key', $soft['message']);

        \AIWorkforce\ApiProviders::$http = fn() => ['status' => 404, 'body' => json_encode([
            'success' => false, 'error' => ['code' => 'NOT_FOUND', 'message' => 'Cannot GET /v1/results/euromillones/latest', 'statusCode' => 404]])];
        $missing = \AIWorkforce\ApiProviders::test(['driver' => 'loteriasapi', 'base_url' => '', 'extra' => []], ['api_key' => 'k']);
        assert_false($missing['ok']);
        assert_contains('https://api.loteriasapi.com/api/v1', $missing['message']);

        $noKey = \AIWorkforce\ApiProviders::test(['driver' => 'loteriasapi', 'base_url' => '', 'extra' => []], []);
        assert_false($noKey['ok']);
        assert_true(str_contains($noKey['message'], 'loteriasapi.com'), 'operators are told where to get a key');
    } finally {
        \AIWorkforce\ApiProviders::$http = $prev;
    }
});

test('platform prefers a configured LoteriasAPI feed over the sandbox simulation', function () {
    $platform = file_get_contents(FCPATH . 'application/libraries/AIWorkforce/Platform.php');
    assert_contains('LoteriasApiProvider', $platform);
    $pos = strpos($platform, 'LoteriasApiProvider');
    $sandbox = strpos($platform, 'SandboxLotteryProvider');
    assert_true($pos !== false && $sandbox !== false && $pos < $sandbox, 'the real feed is selected before the simulation');
});

test('lottery status and dashboard surface the live feed identity honestly', function () {
    $repo = new LotteryRepositoryStub();
    [$provider] = fx_loterias_provider(fn() => fx_loterias_json(fx_loterias_live_envelope(fx_loterias_live_payload())));
    $status = (new LotteryIntelligence($repo, fx_loterias_audit(), $provider))->status();
    assert_equals('loteriasapi', $status['provider']['id'], 'status names the active provider');
    assert_equals('euromillones', $status['provider']['game']);
    assert_equals('ONLINE', $status['status']);
    assert_false(str_contains(json_encode($status), 'live-key-secret-123'), 'status never carries the key');

    // Admin deep-link must use the SERVICE code (lottery), not a driver name,
    // or the provider form cannot preselect the category.
    $view = file_get_contents(FCPATH . 'application/views/lottery/index.php');
    assert_contains('/admin/api/create?service=lottery', $view);
    assert_false(str_contains($view, 'service=official_lottery'), 'driver names are not service codes');

    // An unconfigured feed must still read honestly, never as ONLINE.
    $offline = new LoteriasApiProvider(null, '', null, true, fn() => fx_loterias_json([]));
    $offStatus = (new LotteryIntelligence(new LotteryRepositoryStub(), fx_loterias_audit(), $offline))->status();
    assert_equals('DISABLED_NO_PROVIDER', $offStatus['engine']);
    assert_equals('NO_DATA', $offStatus['status']);
});

test('scheduled lottery sync ingests LoteriasAPI draws and is idempotent per day', function () {
    $repo = new LotteryRepositoryStub();
    $audit = fx_loterias_audit();
    [$provider] = fx_loterias_provider(function (string $url) {
        if (str_contains($url, '/latest')) return fx_loterias_json(fx_loterias_live_envelope(fx_loterias_live_payload()));
        return fx_loterias_json(['success' => true, 'data' => [
            fx_loterias_live_payload('2026-04-10', '2026029'),
            fx_loterias_live_payload('2026-04-07', '2026028'),
        ], 'meta' => ['hasNext' => false]]);
    });
    $intel = new LotteryIntelligence($repo, $audit, $provider);
    $cron = new \AIWorkforce\Lottery\LotteryCronService($repo, $audit, $intel);

    $first = $cron->run('sync', '2026-04-11');
    assert_not_equals('SKIPPED_NO_PROVIDER', $first['status'] ?? '', 'a configured feed is never skipped');
    assert_equals(2, $intel->drawCount());

    $repeat = $cron->run('sync', '2026-04-11');
    assert_equals('ALREADY_RUN', $repeat['status'], 'same-day re-run is a no-op');

    $health = $cron->run('health', '2026-04-11');
    assert_true(is_array($health));
    assert_false(str_contains(json_encode($repo->health), 'live-key-secret-123'), 'health history never stores the key');
});

test('loteriasapi adapter obeys the module honesty rules and leaks no credential path', function () {
    $src = strtolower(file_get_contents(FCPATH . 'application/libraries/AIWorkforce/Lottery/LoteriasApiProvider.php'));
    foreach (['guarantee', 'win chance', 'win probability', 'winning numbers', 'certain win',
              'secret formula', 'sure win', 'jackpot prediction', '90% chance',
              'ai knows the next draw', 'predict'] as $banned) {
        assert_false(str_contains($src, $banned), 'banned wording: ' . $banned);
    }
    // No committed credentials and no plaintext endpoints.
    assert_false((bool) preg_match('#http://#', $src), 'no plaintext HTTP endpoints');
    assert_false((bool) preg_match("#api_key\s*=\s*['\"][a-z0-9]{8,}#", $src), 'no committed API key');

    // The key is read from managed config or the environment only.
    assert_true(str_contains($src, 'windels_lottery_loteriasapi_key'), 'environment credential path documented');
    assert_true(str_contains($src, "apiproviders::resolve('lottery')"), 'managed credentials resolved centrally');

    // A managed row belonging to a different driver must not be adopted.
    assert_contains("!== 'loteriasapi'", file_get_contents(FCPATH . 'application/libraries/AIWorkforce/Lottery/LoteriasApiProvider.php'));
    assert_contains("!== 'official_lottery'", file_get_contents(FCPATH . 'application/libraries/AIWorkforce/Lottery/OfficialLotteryProvider.php'));
});
