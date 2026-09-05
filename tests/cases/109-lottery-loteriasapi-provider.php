<?php
/**
 * WINDELS Lottery Intelligence — loteriasapi.com (EuroMillions API) adapter.
 *
 * Verifies the vendor contract documented at
 * https://loteriasapi.com/en/euromillions-api is mapped into the
 * provider-neutral draw shape, that credentials never leak, that failure
 * modes are classified honestly, and that a real vendor payload survives the
 * full ingestion path (validation → historical database).
 */
use AIWorkforce\Lottery\LoteriasApiProvider;
use AIWorkforce\Lottery\LotteryIntelligence;

/** Canonical vendor payload from the published documentation. */
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

test('loteriasapi: base URL, game code and auth header follow the published contract', function () {
    assert_equals('https://api.loteriasapi.com/v1', LoteriasApiProvider::normalizeBaseUrl(''));
    assert_equals('https://api.loteriasapi.com/v1', LoteriasApiProvider::normalizeBaseUrl('https://loteriasapi.com/en/euromillions-api'));
    assert_equals('https://api.loteriasapi.com/v1', LoteriasApiProvider::normalizeBaseUrl('https://api.loteriasapi.com'));
    assert_equals('https://api.loteriasapi.com/v2', LoteriasApiProvider::normalizeBaseUrl('https://api.loteriasapi.com/v2/'));
    assert_equals('euromillones', LoteriasApiProvider::normalizeGame(''));
    assert_equals('euromillones', LoteriasApiProvider::normalizeGame('EuroMillions'));
    assert_equals('primitiva', LoteriasApiProvider::normalizeGame('Primitiva'));

    [$provider, $calls] = fx_loterias_provider(fn() => fx_loterias_json(fx_loterias_payload()));
    $health = $provider->health();
    assert_equals('ONLINE', $health['state']);
    assert_equals('https://api.loteriasapi.com/v1/results/euromillones/latest', $calls[0]['url']);
    assert_true(in_array('x-api-key: live-key-secret-123', $calls[0]['headers'], true), 'auth uses the x-api-key header');
    assert_false($health['synthetic']);
});

test('loteriasapi: vendor result maps to the provider-neutral draw contract', function () {
    [$provider] = fx_loterias_provider(fn() => fx_loterias_json(fx_loterias_payload()));
    $draw = $provider->normalizeDraw(fx_loterias_payload());
    assert_equals('2026/029', $draw['externalId']);
    assert_equals('2026-04-10', $draw['drawDate']);
    assert_equals([7, 12, 29, 33, 44], $draw['main']);
    assert_equals([3, 11], $draw['stars']);
    assert_equals('2026-04-10T22:15:42Z', $draw['sourceTimestamp']);
    assert_true(str_contains($draw['source'], 'loteriasapi'), 'source attribution names the feed');
    assert_true($draw['rollover'], 'zero 5+2 winners is a rollover');
    assert_equals('0', $draw['winners']);
    assert_equals('HXG12345', $draw['extra']['elMillon']);
    assert_equals(130000000.0, $draw['extra']['jackpotNext']);
    assert_equals('SELAE', $draw['extra']['feedSource']);
});

test('loteriasapi: historical range query, ordering and limit', function () {
    [$provider, $calls] = fx_loterias_provider(function (string $url) {
        if (str_contains($url, '/latest')) return fx_loterias_json(fx_loterias_payload());
        return fx_loterias_json(['results' => [
            fx_loterias_payload('2026-04-03', '2026/027'),
            fx_loterias_payload('2026-04-10', '2026/029'),
            fx_loterias_payload('2026-04-07', '2026/028'),
        ]]);
    });
    $draws = $provider->draws('2026-04-01', '2026-04-30', 2);
    assert_equals(2, count($draws));
    assert_equals('2026-04-10', $draws[0]['drawDate'], 'newest first');
    assert_equals('2026-04-07', $draws[1]['drawDate']);
    assert_true(str_contains($calls[0]['url'], 'from=2026-04-01'), 'range query uses from/to');
    assert_true(str_contains($calls[0]['url'], 'to=2026-04-30'));
});

test('loteriasapi: falls back to /latest when the range endpoint returns nothing', function () {
    [$provider, $calls] = fx_loterias_provider(function (string $url) {
        if (str_contains($url, '/latest')) return fx_loterias_json(['data' => fx_loterias_payload()]);
        return fx_loterias_json(['results' => []]);
    });
    $draws = $provider->draws(null, null, 5);
    assert_equals(1, count($draws));
    assert_equals('2026/029', $draws[0]['externalId']);
    assert_equals(2, count($calls), 'range attempt then latest fallback');
});

test('loteriasapi: draw-by-id endpoint and next-draw jackpot', function () {
    [$provider, $calls] = fx_loterias_provider(fn() => fx_loterias_json(fx_loterias_payload()));
    $draw = $provider->drawById('2026/029');
    assert_not_null($draw);
    assert_equals('https://api.loteriasapi.com/v1/results/euromillones/2026/029', $calls[0]['url']);
    assert_null($provider->drawById('not-a-draw-id'), 'malformed ids never reach the network');

    $jackpot = $provider->jackpotInfo();
    assert_equals('130000000.00', $jackpot['value']);
    assert_equals('EUR', $jackpot['currency']);
    assert_true(str_contains($jackpot['note'], 'not used to infer'), 'jackpot carries the honesty note');
});

test('loteriasapi: failure modes are classified honestly and never leak the key', function () {
    foreach ([401 => 'OFFLINE', 403 => 'OFFLINE', 429 => 'OFFLINE', 404 => 'OFFLINE', 0 => 'OFFLINE', 500 => 'OFFLINE'] as $status => $state) {
        [$provider] = fx_loterias_provider(fn() => ['status' => $status, 'body' => 'live-key-secret-123 denied']);
        $health = $provider->health();
        assert_equals($state, $health['state'], 'HTTP ' . $status);
        assert_false(str_contains(json_encode($health), 'live-key-secret-123'), 'HTTP ' . $status . ' must not leak the key');
    }
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

    $insecure = new LoteriasApiProvider('http://api.loteriasapi.com/v1', 'k', null, true, fn() => fx_loterias_json(fx_loterias_payload()));
    assert_false($insecure->configured(), 'plaintext HTTP is refused');
});

test('loteriasapi: draws flow through validation into the historical database', function () {
    $repo = new LotteryRepositoryStub();
    $audit = fx_loterias_audit();
    [$provider] = fx_loterias_provider(function (string $url) {
        if (str_contains($url, '/latest')) return fx_loterias_json(fx_loterias_payload());
        return fx_loterias_json(['results' => [
            fx_loterias_payload('2026-04-10', '2026/029'),
            fx_loterias_payload('2026-04-07', '2026/028'),
        ]]);
    });
    $intel = new LotteryIntelligence($repo, $audit, $provider);

    $status = $intel->status();
    assert_equals('ONLINE', $status['provider']['state']);
    assert_equals('130000000.00', $status['jackpot'], 'published next-draw jackpot surfaces on the dashboard');

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
    assert_equals([7, 12, 29, 33, 44], $stored['main_numbers']);
    assert_equals([3, 11], $stored['lucky_stars']);
    assert_true(str_contains((string) $stored['source'], 'loteriasapi'));
    assert_false(str_contains(json_encode($repo->draws), 'live-key-secret-123'), 'credentials never reach storage');
});

test('loteriasapi: malformed vendor numbers are rejected, not stored as official results', function () {
    $repo = new LotteryRepositoryStub();
    $audit = fx_loterias_audit();
    $bad = fx_loterias_payload('2026-04-14', '2026/030');
    $bad['numbers'] = [7, 12, 29, 99];   // wrong count AND out of range
    [$provider] = fx_loterias_provider(function (string $url) use ($bad) {
        if (str_contains($url, '/latest')) return fx_loterias_json(fx_loterias_payload());
        return fx_loterias_json(['results' => [$bad]]);
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
    }

    $prev = \AIWorkforce\ApiProviders::$http;
    try {
        $seen = [];
        \AIWorkforce\ApiProviders::$http = function (string $url, array $headers = [], ?string $body = null) use (&$seen) {
            $seen[] = ['url' => $url, 'headers' => $headers];
            return ['status' => 200, 'body' => json_encode(fx_loterias_payload())];
        };
        $ok = \AIWorkforce\ApiProviders::test(
            ['driver' => 'loteriasapi', 'base_url' => '', 'extra' => []],
            ['api_key' => 'test-key-abc']
        );
        assert_true($ok['ok'], $ok['message']);
        assert_true(str_contains($ok['message'], '2026-04-10'));
        assert_equals('https://api.loteriasapi.com/v1/results/euromillones/latest', $seen[0]['url']);

        \AIWorkforce\ApiProviders::$http = fn() => ['status' => 401, 'body' => 'denied'];
        $bad = \AIWorkforce\ApiProviders::test(['driver' => 'loteriasapi', 'base_url' => '', 'extra' => []], ['api_key' => 'nope']);
        assert_false($bad['ok']);
        assert_equals('Invalid API key', $bad['message']);

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
    [$provider] = fx_loterias_provider(fn() => fx_loterias_json(fx_loterias_payload()));
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
        if (str_contains($url, '/latest')) return fx_loterias_json(fx_loterias_payload());
        return fx_loterias_json(['results' => [
            fx_loterias_payload('2026-04-10', '2026/029'),
            fx_loterias_payload('2026-04-07', '2026/028'),
        ]]);
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
