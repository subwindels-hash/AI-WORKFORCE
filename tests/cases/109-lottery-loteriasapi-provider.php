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

/**
 * The live-feed variant that keys rows by its own numeric id and publishes the
 * whole winning line (numbers followed by the stars) in ONE `combination`
 * array — the shape that used to be rejected as
 * "line must contain 5 main numbers (got 7)".
 */
function fx_loterias_flat_payload(string $date = '2026-09-04', array $combination = [7, 12, 29, 33, 45, 3, 9]): array
{
    $row = fx_loterias_live_payload($date);
    $row['id'] = (int) str_replace('-', '', $date);
    unset($row['drawId'], $row['resultData']);
    $row['combination'] = $combination;
    return $row;
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
    // Windows are walked NEWEST first: the plan-visible history is at the new
    // end, and a small plan must not pay for windows it cannot read.
    assert_contains('from=2024-12-31', $splitCalls[0]['url'], 'newest window queried first');
    assert_contains('from=2024-01-01', $splitCalls[1]['url']);
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
    // History has three sources, tried in order of richness:
    // /range → the paged listing route → /latest (single draw, last resort).
    assert_equals(3, count($calls), 'range, then the history listing, then the latest fallback');
    assert_contains('/range', $calls[0]['url']);
    assert_contains('page=1', $calls[1]['url']);
    assert_contains('/latest', $calls[2]['url']);
});

test('loteriasapi: a plan-capped page size is retried at the plan floor, not failed', function () {
    // Every plan caps results per request (Free 5 … Enterprise 200) and the
    // vendor enforces parameter limits with HTTP 400 — an oversized `limit`
    // used to kill every history call, degrading the sync to a single draw.
    [$provider, $calls] = fx_loterias_provider(function (string $url) {
        if (str_contains($url, '/latest')) return fx_loterias_json(fx_loterias_live_envelope(fx_loterias_live_payload()));
        if (str_contains($url, 'limit=100')) {
            return fx_loterias_json(['success' => false, 'error' => [
                'code' => 'VALIDATION_ERROR', 'message' => "El parametro 'limit' es invalido",
                'details' => ['field' => 'limit', 'value' => '100'], 'statusCode' => 400]], 400);
        }
        return fx_loterias_json(['success' => true, 'data' => [
            fx_loterias_live_payload('2026-04-10', '2026029'),
            fx_loterias_live_payload('2026-04-07', '2026028'),
        ], 'meta' => ['hasNext' => false, 'limit' => 5]]);
    });
    $draws = $provider->draws('2026-01-01', '2026-04-30', 100);
    assert_equals(2, count($draws), 'the rejected page size is retried at the documented Free-tier floor');
    assert_contains('limit=100', $calls[0]['url'], 'the first page asks for the full size');
    assert_contains('limit=5', $calls[1]['url'], 'the retry asks for 5 per request');
    assert_equals(2, count($calls), 'no further doomed requests once the plan size is learned');
});

test('loteriasapi: a silently capped page size is adopted for the next page', function () {
    $pageOne = [];
    for ($i = 1; $i <= 5; $i++) {
        $pageOne[] = fx_loterias_live_payload('2026-04-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), '20260' . $i);
    }
    [$provider, $calls] = fx_loterias_provider(function (string $url) use ($pageOne) {
        if (str_contains($url, 'page=2')) {
            return fx_loterias_json(['success' => true, 'data' => [fx_loterias_live_payload('2026-03-31', '2026090')],
                'meta' => ['hasNext' => false, 'limit' => 5]]);
        }
        if (str_contains($url, '/latest')) return fx_loterias_json(fx_loterias_live_envelope(fx_loterias_live_payload()));
        return fx_loterias_json(['success' => true, 'data' => $pageOne, 'meta' => ['hasNext' => true, 'limit' => 5]]);
    });
    $draws = $provider->draws('2026-03-01', '2026-04-30', 100);
    assert_equals(6, count($draws), 'both pages are collected');
    assert_contains('limit=100', $calls[0]['url']);
    assert_contains('limit=5', $calls[1]['url'], 'the served page size (meta.limit=5) is adopted for page 2');
});

test('loteriasapi: the implicit backfill walks newest-first and stops when a window adds nothing', function () {
    // A 7-day-history plan (Free) serves the newest window only — the sync
    // must cost two requests, not one per year of archive the plan cannot read.
    $today = gmdate('Y-m-d');
    [$provider, $calls] = fx_loterias_provider(function (string $url) use ($today) {
        if (str_contains($url, '/latest')) return fx_loterias_json(fx_loterias_live_envelope(fx_loterias_live_payload()));
        if (str_contains($url, 'to=' . $today)) {
            return fx_loterias_json(['success' => true, 'data' => [
                fx_loterias_live_payload('2026-09-04', '2026233'),
                fx_loterias_live_payload('2026-09-01', '2026232'),
            ], 'meta' => ['hasNext' => false]]);
        }
        // Older windows: inside the request cap but beyond the plan's history depth.
        return fx_loterias_json(['success' => true, 'data' => [], 'meta' => ['hasNext' => false]]);
    });
    $draws = $provider->draws(null, null, 5000);
    assert_equals(2, count($draws), 'the plan-visible draws are still imported');
    assert_equals(2, count($calls), 'newest window, one empty older window ends the walk — no /latest needed');
    assert_contains('to=' . $today, $calls[0]['url'], 'the newest window is queried first');
});

test('loteriasapi: an unfinished /latest placeholder is never offered as history', function () {
    // On draw day the feed answers the NEXT draw as an unfinished placeholder
    // (no numbers yet). Offering it as history produced the operator-facing
    // "0 imported, 0 unchanged, 1 rejected" with nothing stored.
    $pending = fx_loterias_live_payload('2026-09-08', '2026234');
    $pending['status'] = 'PENDING';
    unset($pending['combination'], $pending['resultData']);
    [$provider] = fx_loterias_provider(function (string $url) use ($pending) {
        if (str_contains($url, '/latest')) return fx_loterias_json(fx_loterias_live_envelope($pending));
        return fx_loterias_json(['success' => true, 'data' => [], 'meta' => ['hasNext' => false]]);
    });
    assert_equals([], $provider->draws(null, null, 5), 'a pending draw is no history — and no rejected draw either');

    // health() still explains the feed state honestly.
    $health = $provider->health();
    assert_equals('ONLINE', $health['state']);
    assert_contains('pending', strtolower((string) $health['message']), 'the unfinished status is named');
});

test('loteriasapi: the reported incident — capped plan + pending latest — syncs real draws', function () {
    // Reproduces the operator report verbatim: every paged call asked for
    // limit=100, the plan answered HTTP 400, and the only surviving source
    // (/latest) served an unfinished draw → "Sync complete: 0 imported,
    // 0 unchanged, 1 rejected; 0 verified draws stored."
    $pending = fx_loterias_live_payload('2026-09-08', '2026234');
    $pending['status'] = 'PENDING';
    unset($pending['combination'], $pending['resultData']);
    $today = gmdate('Y-m-d');
    [$provider] = fx_loterias_provider(function (string $url) use ($pending, $today) {
        if (str_contains($url, '/latest')) return fx_loterias_json(fx_loterias_live_envelope($pending));
        if (str_contains($url, 'limit=100')) {
            return fx_loterias_json(['success' => false, 'error' => [
                'code' => 'VALIDATION_ERROR', 'message' => "El parametro 'limit' es invalido",
                'details' => ['field' => 'limit', 'value' => '100'], 'statusCode' => 400]], 400);
        }
        if (str_contains($url, 'to=' . $today)) {
            return fx_loterias_json(['success' => true, 'data' => [
                fx_loterias_live_payload('2026-09-04', '2026233'),
                fx_loterias_live_payload('2026-09-01', '2026232'),
            ], 'meta' => ['hasNext' => false, 'limit' => 5]]);
        }
        return fx_loterias_json(['success' => true, 'data' => [], 'meta' => ['hasNext' => false]]);
    });
    $repo = new LotteryRepositoryStub();
    $intel = new LotteryIntelligence($repo, fx_loterias_audit(), $provider);
    $sync = $intel->sync(LotteryIntelligence::FULL_HISTORY_LIMIT);
    assert_equals('OK', $sync['status']);
    assert_equals(2, $sync['imported'], 'the plan-visible draws are stored');
    assert_equals(0, $sync['failed'], 'nothing is rejected — the placeholder never reaches the validator');
    assert_equals(2, $sync['verifiedDraws']);
    assert_contains('2 imported', $intel->syncNotice($sync));
});

test('loteriasapi: unfinished rows inside a range page are skipped, finished ones are not', function () {
    $pending = fx_loterias_live_payload('2026-09-08', '2026234');
    $pending['status'] = 'PENDING';
    [$provider] = fx_loterias_provider(function (string $url) use ($pending) {
        if (str_contains($url, '/latest')) return fx_loterias_json(fx_loterias_live_envelope(fx_loterias_live_payload()));
        return fx_loterias_json(['success' => true, 'data' => [
            $pending,
            fx_loterias_live_payload('2026-09-04', '2026233'),
        ], 'meta' => ['hasNext' => false]]);
    });
    $draws = $provider->draws('2026-08-01', '2026-09-30', 10);
    assert_equals(1, count($draws), 'only the finished draw is offered');
    assert_equals('2026-09-04', $draws[0]['drawDate']);
});

test('loteriasapi: the current documented /latest payload maps and survives validation', function () {
    // Payload verbatim from https://loteriasapi.com/docs/getting-started:
    // no drawId, string prize categories ("1a"), formattedPrize only, and a
    // jackpot formatted with an "EUR" suffix instead of ",00 €".
    $docs = [
        'game' => ['slug' => 'euromillones', 'name' => 'Euromillones'],
        'drawDate' => '2026-02-21',
        'dayOfWeek' => 'viernes',
        'status' => 'COMPLETED',
        'combination' => [7, 12, 29, 33, 45],
        'resultData' => ['estrellas' => [3, 9]],
        'jackpotFormatted' => '130.000.000 EUR',
        'prizes' => [
            ['category' => '1a', 'winners' => 0, 'formattedPrize' => '130.000.000 EUR'],
            ['category' => '2a', 'winners' => 3, 'formattedPrize' => '1.250.000 EUR'],
        ],
    ];
    [$provider] = fx_loterias_provider(function (string $url) use ($docs) {
        if (str_contains($url, '/latest')) return fx_loterias_json(fx_loterias_live_envelope($docs));
        return fx_loterias_json(['success' => true, 'data' => [], 'meta' => ['hasNext' => false]]);
    });
    $draws = $provider->draws(null, null, 5);
    assert_equals(1, count($draws));
    assert_equals('EUROMILLONES-2026-02-21', $draws[0]['externalId'], 'the date-keyed fallback id covers the missing drawId');
    assert_equals([7, 12, 29, 33, 45], $draws[0]['main']);
    assert_equals([3, 9], $draws[0]['stars']);
    assert_equals('130000000.00', $draws[0]['jackpot'], '"130.000.000 EUR" parses without a decimal part');
    assert_true($draws[0]['rollover'], 'string category "1a" with 0 winners is the top tier');
    assert_equals('0', $draws[0]['winners']);

    // And the full pipeline stores it.
    $repo = new LotteryRepositoryStub();
    $intel = new LotteryIntelligence($repo, fx_loterias_audit(), $provider);
    $sync = $intel->sync(10);
    assert_equals(1, $sync['imported']);
    assert_equals(0, $sync['failed']);
    assert_equals(1, $intel->verifiedDrawCount());
});

test('loteriasapi: defensive mapping — resultData as a JSON string and extraNumbers keys', function () {
    $provider = new LoteriasApiProvider(null, 'k', null, true, fn() => fx_loterias_json([]));
    $asString = $provider->normalizeDraw([
        'drawDate' => '2026-04-10', 'drawId' => '2026029', 'status' => 'COMPLETED',
        'combination' => [7, 12, 29, 33, 45],
        'resultData' => '{"estrellas":[3,9]}',
    ]);
    assert_equals([3, 9], $asString['stars'], 'a JSON-string resultData still yields the stars');

    $extraNumbers = $provider->normalizeDraw([
        'drawDate' => '2026-04-07', 'status' => 'COMPLETED',
        'combination' => [1, 2, 3, 4, 5], 'winningExtraNumbers' => [6, 7],
    ]);
    assert_equals([6, 7], $extraNumbers['stars'], 'the "extra numbers" vocabulary is mapped too');
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

    // The operator notice carries the first rejection reason — never a bare
    // "1 rejected" the operator has to decode through the audit log.
    $notice = $intel->syncNotice($sync);
    assert_contains('1 rejected', $notice);
    assert_contains('draw 2026030', $notice);
    assert_contains('line must contain 5 main numbers', $notice, 'the validator reason rides along');
});

test('loteriasapi: a whole winning line in one flat combination is re-grouped, not rejected', function () {
    [$provider] = fx_loterias_provider(fn() => fx_loterias_json(fx_loterias_live_envelope(fx_loterias_flat_payload())));

    // The vendor's own star field confirms the split.
    $withStars = fx_loterias_flat_payload();
    $withStars['resultData'] = ['estrellas' => [3, 9]];
    $draw = $provider->normalizeDraw($withStars);
    assert_equals([7, 12, 29, 33, 45], $draw['main'], 'the flat line is split into the 5 numbers');
    assert_equals([3, 9], $draw['stars'], 'the trailing values are the stars');
    assert_equals('flat-combination-split', $draw['extra']['numberLayout'], 'the interpretation is recorded');
    assert_equals([7, 12, 29, 33, 45, 3, 9], $draw['extra']['rawCombination'], 'the vendor line stays auditable');

    // Without any star field the split still applies — every value fits the game.
    $noStars = fx_loterias_flat_payload();
    $draw2 = $provider->normalizeDraw($noStars);
    assert_equals('20260904', $draw2['externalId'], 'the numeric vendor id is the draw key');
    assert_equals([7, 12, 29, 33, 45], $draw2['main']);
    assert_equals([3, 9], $draw2['stars']);
    assert_equals('flat-combination-split', $draw2['extra']['numberLayout']);

    // The same line published stars first — confirmed by the star field too.
    $starsFirst = fx_loterias_flat_payload('2026-09-04', [3, 9, 7, 12, 29, 33, 45]);
    $starsFirst['resultData'] = ['estrellas' => [3, 9]];
    $draw4 = $provider->normalizeDraw($starsFirst);
    assert_equals([7, 12, 29, 33, 45], $draw4['main']);
    assert_equals([3, 9], $draw4['stars']);
    assert_equals('flat-combination-split-stars-first', $draw4['extra']['numberLayout']);

    // A line that cannot be a 5+2 line is never reshaped into one: it stays
    // exactly as the feed sent it and is rejected by the validator instead.
    $implausible = fx_loterias_flat_payload('2026-09-04', [7, 12, 29, 33, 45, 44, 43]);
    $draw3 = $provider->normalizeDraw($implausible);
    assert_equals([7, 12, 29, 33, 45, 44, 43], $draw3['main'], 'an implausible split is refused');
    assert_null($draw3['extra']['numberLayout']);

    // Neither for a game whose number layout the adapter does not know.
    [$other] = fx_loterias_provider(fn() => fx_loterias_json([]), ['game' => 'primitiva']);
    assert_equals([7, 12, 29, 33, 45, 3, 9], $other->normalizeDraw(fx_loterias_flat_payload())['main'],
        'an unknown game layout is never guessed');
});

test('loteriasapi: a flat-combination feed syncs end to end instead of being rejected', function () {
    $repo = new LotteryRepositoryStub();
    $audit = fx_loterias_audit();
    [$provider] = fx_loterias_provider(function (string $url) {
        $payloads = [
            fx_loterias_flat_payload('2026-09-04', [7, 12, 29, 33, 45, 3, 9]),
            fx_loterias_flat_payload('2026-09-01', [4, 18, 27, 36, 50, 2, 11]),
        ];
        if (str_contains($url, '/latest')) return fx_loterias_json(fx_loterias_live_envelope($payloads[0]));
        return fx_loterias_json(['success' => true, 'data' => $payloads, 'meta' => ['hasNext' => false]]);
    });
    $intel = new LotteryIntelligence($repo, $audit, $provider);

    $sync = $intel->sync(10);
    assert_equals(0, $sync['failed'], 'a flat winning line is no longer a validation failure: ' . json_encode($sync['errors']));
    assert_equals(2, $sync['imported']);
    assert_equals(2, $intel->verifiedDrawCount());
    assert_equals(0, count(array_filter($audit->events, fn($e) => $e['type'] === 'LOTTERY_DRAW_VALIDATION_FAILED')));

    $stored = $intel->presentDraw($repo->listDraws(['lotteryCode' => 'EUROMILLIONS'], 1)[0]);
    assert_equals([7, 12, 29, 33, 45], $stored['main_numbers']);
    assert_equals([3, 9], $stored['lucky_stars']);
});

test('loteriasapi: the raw vendor payload is available for smoke diagnostics', function () {
    [$provider, $calls] = fx_loterias_provider(fn() => fx_loterias_json(fx_loterias_live_envelope(fx_loterias_flat_payload())));
    $raw = $provider->rawLatest();
    assert_equals([7, 12, 29, 33, 45, 3, 9], $raw['combination'], 'the vendor row is exposed unmapped for --raw');
    assert_equals(1, count($calls), 'diagnostics reuse the memoised /latest answer');

    // An unconfigured adapter exposes nothing rather than a synthetic row.
    $off = new LoteriasApiProvider(null, '', null, true, fn() => fx_loterias_json([]));
    assert_equals([], $off->rawLatest());

    // The CLI smoke test can print it next to the mapped draw.
    $tools = file_get_contents(FCPATH . 'application/controllers/Tools.php');
    assert_contains("'--raw'", $tools, 'lottery-smoke --raw prints what the feed actually sends');
    assert_contains('rawLatest()', $tools);
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

// ---------------------------------------------------------------------------
// Historical draw synchronization: API → validation → database → statistics →
// AI generation, plus the honest DATA UNAVAILABLE state (issue #66).
// ---------------------------------------------------------------------------

/** A provider serving `$count` distinct EuroMillions draws through /range. */
function fx_loterias_history_provider(int $count = 12, array $overrides = []): array
{
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $date = gmdate('Y-m-d', strtotime('2026-04-10 -' . ($i * 4) . ' days'));
        $draw = fx_loterias_live_payload($date, '2026' . str_pad((string) (100 - $i), 3, '0', STR_PAD_LEFT));
        // Distinct, valid combinations so the statistics have real variation.
        $draw['combination'] = [
            1 + ($i % 10), 11 + ($i % 9), 21 + ($i % 8), 31 + ($i % 7), 41 + ($i % 6),
        ];
        $draw['resultData'] = ['estrellas' => [1 + ($i % 6), 7 + ($i % 5)]];
        $rows[] = $draw;
    }
    return fx_loterias_provider(function (string $url) use ($rows) {
        if (str_contains($url, '/latest')) return fx_loterias_json(fx_loterias_live_envelope($rows[0]));
        return fx_loterias_json(['success' => true, 'data' => $rows, 'meta' => ['hasNext' => false]]);
    }, $overrides);
}

test('loteriasapi history sync: fetch → validate → store, with no duplicates', function () {
    $repo = new LotteryRepositoryStub();
    $audit = fx_loterias_audit();
    [$provider] = fx_loterias_history_provider(12);
    $intel = new LotteryIntelligence($repo, $audit, $provider);

    $sync = $intel->sync(50);
    assert_equals('OK', $sync['status']);
    assert_equals(12, $sync['imported'], 'every available historical draw is imported');
    assert_equals(0, $sync['failed']);
    assert_equals(12, $intel->drawCount());
    assert_equals(12, $intel->verifiedDrawCount(), 'stored draws are VERIFIED');
    assert_equals(12, $sync['verifiedDraws']);

    // Duplicate protection: a second sync stores nothing new.
    $again = $intel->sync(50);
    assert_equals(0, $again['imported']);
    assert_equals(12, $again['unchanged']);
    assert_equals(12, $intel->verifiedDrawCount(), 'no duplicate rows');

    // Every row carries date, 5 mains, 2 stars, jackpot, prizes and winners.
    $row = $repo->listDraws(['lotteryCode' => 'EUROMILLIONS'], 1)[0];
    assert_equals(5, count($row['payload']['main']));
    assert_equals(2, count($row['payload']['stars']));
    assert_equals('130000000.00', $row['jackpot'], 'jackpot stored from the feed');
    assert_not_null($row['payload']['prizes'], 'prize breakdown recorded');
    assert_equals(2, count($row['payload']['prizes']));
    assert_equals(3, $row['payload']['prizes'][1]['winners'], 'winner counts recorded per tier');
    assert_equals('0', $row['payload']['winners'], 'jackpot winner count recorded');
    assert_equals('VERIFIED', $row['verification_status']);
});

test('lottery dashboard: verified draw count, last successful sync and sync status', function () {
    $repo = new LotteryRepositoryStub();
    [$provider] = fx_loterias_history_provider(8);
    $intel = new LotteryIntelligence($repo, fx_loterias_audit(), $provider);

    $before = $intel->status();
    assert_equals(0, $before['verifiedDraws']);
    assert_equals('NEVER_SYNCED', $before['syncStatus']);
    assert_null($before['lastSuccessfulSync']);
    assert_false($before['dataAvailable']);

    $intel->sync(50);
    $after = $intel->status();
    assert_equals(8, $after['verifiedDraws']);
    assert_true($after['dataAvailable']);
    assert_equals('OK', $after['syncStatus']);
    assert_not_null($after['lastSuccessfulSync'], 'the dashboard reports the last successful sync');
    assert_not_null($after['lastSyncAttempt']);
    assert_equals(8, $after['historicalDataset']['draws']);
    assert_true($after['historicalDataset']['available']);
});

test('lottery jackpot is read from the LoteriasAPI response, never hardcoded', function () {
    $repo = new LotteryRepositoryStub();
    // A jackpot the code could not possibly contain as a literal.
    $payload = fx_loterias_live_payload();
    $payload['jackpotFormatted'] = '89.000.000,00 €';
    $payload['jackpot'] = '8900000000';
    $payload['prizes'] = [['category' => 1, 'categoryName' => '5 + 2 estrellas', 'winners' => 0,
        'prizeAmount' => '8900000000', 'formattedPrize' => '89.000.000,00 €']];
    [$provider] = fx_loterias_provider(fn() => fx_loterias_json(fx_loterias_live_envelope($payload)));
    $status = (new LotteryIntelligence($repo, fx_loterias_audit(), $provider))->status();
    assert_equals('89000000.00', $status['jackpot'], 'the displayed jackpot mirrors the feed');
    assert_equals('PROVIDER_FEED', $status['jackpotSource']['origin']);
    assert_equals('loteriasapi', $status['jackpotSource']['provider']);
    assert_false($status['jackpotSource']['hardcoded']);

    // Change the feed → the displayed jackpot changes with it.
    $payload['jackpotFormatted'] = '17.000.000,00 €';
    $payload['jackpot'] = '1700000000';
    $payload['prizes'][0]['formattedPrize'] = '17.000.000,00 €';
    [$moved] = fx_loterias_provider(fn() => fx_loterias_json(fx_loterias_live_envelope($payload)));
    $movedStatus = (new LotteryIntelligence(new LotteryRepositoryStub(), fx_loterias_audit(), $moved))->status();
    assert_equals('17000000.00', $movedStatus['jackpot'], 'the amount tracks the feed, so it is not a constant');

    // And no jackpot literal is committed in the module sources.
    foreach (['LoteriasApiProvider.php', 'LotteryIntelligence.php'] as $file) {
        $src = file_get_contents(FCPATH . 'application/libraries/AIWorkforce/Lottery/' . $file);
        assert_false((bool) preg_match('/89[.,]?000[.,]?000/', $src), 'no hardcoded jackpot in ' . $file);
    }
    assert_false((bool) preg_match('/89[.,]?000[.,]?000/', file_get_contents(FCPATH . 'application/views/lottery/index.php')));
});

test('Strategy Lab and Generate 5 AI Lines run on the stored historical dataset', function () {
    $repo = new LotteryRepositoryStub();
    [$provider] = fx_loterias_history_provider(30);
    $intel = new LotteryIntelligence($repo, fx_loterias_audit(), $provider);
    $intel->sync(50);

    $dataset = $intel->historicalDataset();
    assert_equals(30, count($dataset), 'the shared dataset accessor reads the stored draws');
    assert_true($dataset[0]['drawDate'] < $dataset[29]['drawDate'], 'oldest first for look-ahead safety');

    // Statistics (Strategy Lab input) name the dataset they used.
    $stats = $intel->statistics('frequency', 0);
    assert_equals('VERIFIED_HISTORICAL_DATABASE', $stats['dataset']['source']);
    assert_equals(30, $stats['dataset']['draws']);

    // Backtesting (Strategy Lab) replays the same stored dataset.
    $bt = $intel->backtest('HISTORICAL_FREQ', 1, 10);
    assert_equals(30, $bt['dataset']['draws']);
    assert_equals('VERIFIED_HISTORICAL_DATABASE', $bt['dataset']['source']);

    // Generate 5 AI lines from the historical dataset — not a random fallback.
    $report = $intel->generate(['mode' => 'HISTORICAL', 'count' => 5, 'seed' => 7]);
    assert_equals(5, count($report['lines']));
    assert_equals(30, $report['inputs']['drawsUsed'], 'generation consumed all 30 stored draws');
    assert_true($report['dataset']['usedForGeneration']);
    assert_false($report['dataset']['randomBaseline']);
    assert_equals('n=30;last=' . $dataset[29]['drawDate'], $report['inputs']['datasetVersion']);
    assert_true(count($report['factors']['topMainNumbers']) > 0, 'historical frequencies drove the sampling');
});

test('empty dataset: AI generation refuses to fake history instead of falling back to random', function () {
    $repo = new LotteryRepositoryStub();
    [$provider] = fx_loterias_history_provider(4);
    $intel = new LotteryIntelligence($repo, fx_loterias_audit(), $provider);

    assert_throws(InvalidArgumentException::class, fn() => $intel->generate(['mode' => 'HISTORICAL', 'count' => 5]));
    try {
        $intel->generate(['mode' => 'BALANCED', 'count' => 5]);
        assert_true(false, 'BALANCED must refuse an empty dataset');
    } catch (InvalidArgumentException $e) {
        assert_contains('DATA UNAVAILABLE', $e->getMessage());
    }
    // An explicit random baseline stays available and is labeled as such.
    $random = $intel->generate(['mode' => 'RANDOM', 'count' => 5, 'seed' => 1]);
    assert_equals(5, count($random['lines']));
    assert_true($random['dataset']['randomBaseline']);
    assert_false($random['dataset']['usedForGeneration']);
});

test('a down feed with stored history reads STORED DATA, never NO_DATA', function () {
    $repo = new LotteryRepositoryStub();
    [$live] = fx_loterias_history_provider(6);
    $intel = new LotteryIntelligence($repo, fx_loterias_audit(), $live);
    $intel->sync(50);
    assert_equals(6, $intel->verifiedDrawCount());

    // Same database, but the feed is now unreachable.
    [$down] = fx_loterias_provider(fn() => ['status' => 503, 'body' => 'gateway down']);
    $status = (new LotteryIntelligence($repo, fx_loterias_audit(), $down))->status();
    assert_equals('STORED DATA', $status['status'], 'stored verified draws are never reported as NO_DATA');
    assert_true($status['dataAvailable']);
    assert_equals(6, $status['verifiedDraws']);
    assert_equals(6, $status['historicalDataset']['draws'], 'statistics still have their dataset');
});

test('sync state falls back to the stored draws when the active provider has no health row', function () {
    $repo = new LotteryRepositoryStub();
    [$live] = fx_loterias_history_provider(5);
    (new LotteryIntelligence($repo, fx_loterias_audit(), $live))->sync(50);

    // A different provider instance (reconfigured feed) has no health history.
    [$other] = fx_loterias_provider(fn() => fx_loterias_json(fx_loterias_live_envelope(fx_loterias_live_payload())));
    $fresh = new LotteryIntelligence(new LotteryRepositoryStub(), fx_loterias_audit(), $other);
    assert_equals('NEVER_SYNCED', $fresh->status()['syncStatus'], 'an empty database really has never synced');

    $repo->health = [];   // drop the health history, keep the draws
    $stale = (new LotteryIntelligence($repo, fx_loterias_audit(), $other))->status();
    assert_equals('STALE', $stale['syncStatus']);
    assert_not_null($stale['lastSuccessfulSync'], 'the stored draws date the last successful ingestion');
    assert_contains('stored verified draws', $stale['syncMessage']);
});

test('lottery reports DATA UNAVAILABLE when LoteriasAPI cannot be reached', function () {
    $repo = new LotteryRepositoryStub();
    $audit = fx_loterias_audit();
    [$provider] = fx_loterias_provider(fn() => ['status' => 503, 'body' => 'gateway down']);
    $intel = new LotteryIntelligence($repo, $audit, $provider);

    $status = $intel->status();
    assert_equals('OFFLINE', $status['provider']['state']);
    assert_equals('DATA UNAVAILABLE', $status['status'], 'an unreachable feed is never reported as "no data exists"');
    assert_false($status['dataAvailable']);

    $sync = $intel->sync(10);
    assert_equals('DATA UNAVAILABLE', $sync['status']);
    assert_equals(0, $sync['imported']);
    assert_contains('DATA UNAVAILABLE', $sync['message']);
    assert_true(in_array('LOTTERY_SYNC_FAILED', array_column($audit->events, 'type'), true), 'the failure is audited');

    $after = $intel->status();
    assert_equals('FAILED', $after['syncStatus']);
    assert_contains('DATA UNAVAILABLE', $after['syncMessage']);
    assert_equals(0, $after['verifiedDraws']);
});

test('lottery dashboard view renders sync status, verified draws and the feed jackpot', function () {
    $view = file_get_contents(FCPATH . 'application/views/lottery/index.php');
    assert_contains('lastSuccessfulSync', $view, 'last successful sync is on the dashboard');
    assert_contains('syncStatus', $view, 'sync status is on the dashboard');
    assert_contains('verifiedDraws', $view, 'verified draw count is on the dashboard');
    assert_contains('DATA UNAVAILABLE', $view, 'the honest unavailable state is rendered');
    assert_contains('jackpot source', $view, 'the jackpot origin is disclosed');

    // The dashboard button must ask for a history-backed generation.
    $js = file_get_contents(FCPATH . 'assets/js/lottery.js');
    assert_contains("mode: 'HISTORICAL'", $js, 'Generate 5 AI lines uses the historical dataset');
    assert_contains('count: 5', $js, 'five lines, using the API field name');
});

test('loteriasapi: latest verified draw retrieval returns the newest VERIFIED draw', function () {
    $repo = new LotteryRepositoryStub();
    [$provider] = fx_loterias_history_provider(6);
    $intel = new LotteryIntelligence($repo, fx_loterias_audit(), $provider);

    assert_null($intel->latestVerifiedDraw(), 'no verified draw exists before the first sync');

    $sync = $intel->sync(50);
    assert_equals(6, $sync['imported']);

    $latest = $intel->latestVerifiedDraw();
    assert_not_null($latest);
    assert_equals('2026-04-10', $latest['draw_date'], 'the NEWEST verified draw is returned');
    assert_equals([1, 11, 21, 31, 41], $latest['main_numbers']);
    assert_equals([1, 7], $latest['lucky_stars']);
    assert_equals('VERIFIED', $latest['verification_status'], 'only verified rows are ever surfaced');
    assert_true(str_contains((string) $latest['source'], 'loteriasapi'));

    // The dashboard "Last Verified Draw" section reads the same accessor.
    $status = $intel->status();
    assert_not_null($status['lastDraw']);
    assert_equals('2026-04-10', $status['lastDraw']['draw_date']);
});

test('loteriasapi: a successful resync imports only the new draws and keeps the rest', function () {
    $repo = new LotteryRepositoryStub();
    $audit = fx_loterias_audit();

    // First sync: 5 draws are published.
    [$first] = fx_loterias_history_provider(5);
    $intel = new LotteryIntelligence($repo, $audit, $first);
    $one = $intel->sync(50);
    assert_equals(5, $one['imported']);
    assert_equals(5, $intel->verifiedDrawCount());

    // Later the feed carries the same 5 plus 3 freshly-drawn results.
    [$second] = fx_loterias_history_provider(8);
    $again = (new LotteryIntelligence($repo, $audit, $second))->sync(50);
    assert_equals('OK', $again['status']);
    assert_equals(3, $again['imported'], 'only the new draws are stored');
    assert_equals(5, $again['unchanged'], 'existing draws are recognised, not duplicated');
    assert_equals(0, $again['failed']);
    assert_equals(8, $intel->verifiedDrawCount(), 'no duplicate rows after resync');
    assert_equals(8, $intel->drawCount());
});

test('loteriasapi: a database failure during import is reported, not hidden', function () {
    $repo = new class extends LotteryRepositoryStub {
        public function saveDraw(array $d): array {
            throw new \RuntimeException('SQLSTATE[HY000] connection lost during saveDraw');
        }
    };
    $audit = fx_loterias_audit();
    [$provider] = fx_loterias_history_provider(4);
    $intel = new LotteryIntelligence($repo, $audit, $provider);

    $sync = $intel->sync(50);
    assert_equals(LotteryIntelligence::STATUS_DATA_UNAVAILABLE, $sync['status'], 'a DB write failure is a failed sync, not OK');
    assert_equals(0, $sync['imported']);
    assert_contains('DATA UNAVAILABLE', $sync['message']);
    assert_contains('connection lost', $sync['message'], 'the underlying database error is surfaced');
    assert_contains('connection lost', $intel->syncNotice($sync), 'the operator notice surfaces it too');
    assert_equals(0, $intel->verifiedDrawCount());
    assert_true(in_array('LOTTERY_SYNC_FAILED', array_column($audit->events, 'type'), true), 'the persistence failure is audited');
});

test('loteriasapi: Admin → API exposes a manual Sync Now route for the lottery service', function () {
    $routes = file_get_contents(FCPATH . 'application/config/routes.php');
    assert_contains("\$route['admin/api/(:num)/sync'] = 'admin/api_sync/\$1';", $routes);

    $c = file_get_contents(FCPATH . 'application/controllers/Admin.php');
    assert_contains('public function api_sync(', $c, 'the sync action exists');
    assert_contains("'lottery'", $c, 'sync is scoped to the lottery service');
    assert_contains('$this->platform->lottery->sync(', $c, 'the action delegates to the live sync engine');
    assert_contains('FULL_HISTORY_LIMIT', $c, 'manual sync backfills the whole archive');
    assert_contains('syncNotice(', $c, 'the flash surfaces the first rejection/failure reason');

    $view = file_get_contents(FCPATH . 'application/views/admin/api/form.php');
    assert_contains('Sync Now', $view, 'the manual sync button is rendered');
    assert_contains("=== 'lottery'", $view, 'the button only appears for the lottery service');
});

test('loteriasapi: a first-run backfill imports more than the old 520-draw ceiling', function () {
    $repo = new LotteryRepositoryStub();
    $audit = fx_loterias_audit();
    // EuroMillions has drawn far more than 520 times since 2004 — a full
    // first-run backfill must not truncate at the old 520-draw ceiling.
    [$provider] = fx_loterias_history_provider(600);
    $intel = new LotteryIntelligence($repo, $audit, $provider);

    $cron = new \AIWorkforce\Lottery\LotteryCronService($repo, $audit, $intel);
    $result = $cron->run('sync', '2026-04-11');
    assert_equals('OK', $result['status']);
    assert_equals(600, $intel->verifiedDrawCount(), 'the full archive is imported, not capped at 520');
    assert_equals(600, $result['imported']);

    // The shared backfill ceiling comfortably exceeds the EuroMillions archive.
    assert_true(LotteryIntelligence::FULL_HISTORY_LIMIT >= 5000, 'the backfill limit covers the whole archive');
});
