<?php
/**
 * Provider layer resilience — the failure the 2026-09-05 daily run exposed:
 *
 *   API-Football   daily quota exhausted   (HTTP 200 + errors.requests / HTTP 429)
 *   TheSportsDB    HTTP 400                (legacy "3" free key / bad base URL)
 *   SportMonks     HTTP 404                (wrong base URL)
 *   HTTP Provider  HTTP 404                (wrong base URL)
 *   → 0 matches → reported as NO_QUALIFIED_TICKET
 *
 * These tests pin the fixes: classification, circuit breaker (quota →
 * closed until 00:00 UTC), no retry storms, independent fallback, secret-free
 * diagnostics, DATA_UNAVAILABLE instead of "no qualified games", and the
 * readiness contract for the dashboard.
 */
use AIWorkforce\Sports\ProviderHealthMonitor;
use AIWorkforce\Sports\Providers\ApiFootballProvider;
use AIWorkforce\Sports\Providers\HttpSportsProvider;
use AIWorkforce\Sports\Providers\ProviderCircuitBreaker;
use AIWorkforce\Sports\Providers\ProviderException;
use AIWorkforce\Sports\Providers\ProviderHttp;
use AIWorkforce\Sports\Providers\SportMonksProvider;
use AIWorkforce\Sports\Providers\SportsDataProvider;
use AIWorkforce\Sports\Providers\SportsProviderManager;
use AIWorkforce\Sports\Providers\TheSportsDbProvider;

function fx_res_transport(int $status, string $body = '{}', array $headers = [], array &$urls = null): callable
{
    return function (string $url, array $h) use ($status, $body, $headers, &$urls) {
        if ($urls !== null) $urls[] = $url;
        return ['status' => $status, 'body' => $body, 'headers' => $headers];
    };
}

function fx_res_provider(string $id, callable $fixtures, array $health = ['status' => 'ONLINE']): SportsDataProvider
{
    return new class($id, $fixtures, $health) implements SportsDataProvider {
        public int $healthCalls = 0;
        public int $fixtureCalls = 0;
        public function __construct(private string $id, private $fixtures, private array $health) {}
        public function id(): string { return $this->id; }
        public function health(): array { $this->healthCalls++; return $this->health; }
        public function fixtures(array $q): array { $this->fixtureCalls++; return ($this->fixtures)($q); }
        public function odds(string $e): array { return []; }
        public function results(string $e): array { return []; }
    };
}

// ─── 1. Classification ──────────────────────────────────────────────────────

test('resilience: HTTP status codes classify into distinct, actionable provider states', function () {
    $c = fn(int $s, string $b = '', array $h = []) => ProviderHttp::classify(['status' => $s, 'body' => $b, 'headers' => $h], 'https://x.test/fixtures?api_token=SECRET');
    assert_null($c(200, '{"data":[]}'));
    assert_equals(ProviderException::BAD_REQUEST, $c(400, '{"message":"The given data was invalid."}')->status);
    assert_equals(ProviderException::AUTHENTICATION_ERROR, $c(401)->status);
    assert_equals(ProviderException::AUTHENTICATION_ERROR, $c(403)->status);
    assert_equals(ProviderException::NOT_FOUND, $c(404, '{"message":"The requested endpoint does not exist."}')->status);
    assert_equals(ProviderException::RATE_LIMITED, $c(429, '{"message":"Too many requests."}')->status);
    assert_equals(ProviderException::DATA_ERROR, $c(500)->status);
    assert_equals(ProviderException::DATA_ERROR, $c(503)->status);
    assert_equals(ProviderException::OFFLINE, $c(0)->status);
    assert_equals(ProviderException::TIMEOUT, ProviderHttp::classify(['status' => 0, 'body' => '', 'errno' => 28, 'error' => 'Operation timed out'], 'https://x.test/')->status);
});

test('resilience: a 429 that says the DAY is used up is DAILY_QUOTA_EXHAUSTED, not a per-minute throttle', function () {
    $minute = ProviderHttp::classify(['status' => 429, 'body' => '{"message":"Too many requests"}', 'headers' => ['retry-after' => '12']], 'https://x.test/');
    assert_equals(ProviderException::RATE_LIMITED, $minute->status);
    assert_equals(12, $minute->details['retryAfterSeconds']);

    $day = ProviderHttp::classify(['status' => 429, 'body' => '{"message":"You have reached the request limit for the day."}'], 'https://x.test/');
    assert_equals(ProviderException::DAILY_QUOTA_EXHAUSTED, $day->status);
    assert_true(strtotime($day->details['retryAt']) > time(), 'retryAt is the next quota reset');
    assert_equals('00:00:00', gmdate('H:i:s', strtotime($day->details['retryAt'])), 'api-football resets at 00:00 UTC');

    $hdr = ProviderHttp::classify(['status' => 429, 'body' => '', 'headers' => ['x-ratelimit-requests-remaining' => '0']], 'https://x.test/');
    assert_equals(ProviderException::DAILY_QUOTA_EXHAUSTED, $hdr->status, 'x-ratelimit-requests-remaining: 0 means the daily quota is gone');
});

test('resilience: api-football soft errors (HTTP 200 + errors{}) classify by field', function () {
    assert_equals(ProviderException::DAILY_QUOTA_EXHAUSTED, ProviderHttp::classifySoftError('requests', 'You have reached the request limit for the day, please check your plan.'));
    assert_equals(ProviderException::RATE_LIMITED, ProviderHttp::classifySoftError('rateLimit', 'Too many requests. Your rate limit is 10 requests per minute.'));
    assert_equals(ProviderException::AUTHENTICATION_ERROR, ProviderHttp::classifySoftError('token', 'Error/Missing application key.'));
    assert_equals(ProviderException::BAD_REQUEST, ProviderHttp::classifySoftError('from', 'The From field need another parameter.'));
    assert_equals(ProviderException::BAD_REQUEST, ProviderHttp::classifySoftError('page', 'The Page field do not exist.'));
});

test('resilience: api-football "request limit for the day" with HTTP 200 opens as DAILY_QUOTA_EXHAUSTED', function () {
    $body = json_encode(['get' => 'fixtures', 'parameters' => ['date' => '2026-09-05'], 'errors' => ['requests' => 'You have reached the request limit for the day, please check your plan.'], 'results' => 0, 'response' => []]);
    $p = new ApiFootballProvider('k', 'https://v3.football.api-sports.io', 10, fx_res_transport(200, $body));
    try { $p->fixtures(['from' => '2026-09-05', 'to' => '2026-09-05']); assert_true(false, 'must throw'); }
    catch (ProviderException $e) {
        assert_equals(ProviderException::DAILY_QUOTA_EXHAUSTED, $e->status);
        assert_true(isset($e->details['retryAt']));
        assert_equals('requests', $e->details['errorField']);
    }
});

test('resilience: api-football /status detects an exhausted quota BEFORE spending a paid request', function () {
    $status = json_encode(['errors' => [], 'response' => ['account' => [], 'subscription' => ['plan' => 'Free', 'active' => true], 'requests' => ['current' => 100, 'limit_day' => 100]]]);
    $p = new ApiFootballProvider('k', 'https://v3.football.api-sports.io', 10, fx_res_transport(200, $status));
    $h = $p->health();
    assert_equals(ProviderException::DAILY_QUOTA_EXHAUSTED, $h['status']);
    assert_equals(0, $h['rateLimitRemaining']);
    assert_contains('100/100', $h['detail']);
    assert_contains('Free', $h['detail']);
    assert_true(isset($h['retryAt']));

    $ok = json_encode(['errors' => [], 'response' => ['subscription' => ['plan' => 'Free', 'active' => true], 'requests' => ['current' => 37, 'limit_day' => 100]]]);
    $p2 = new ApiFootballProvider('k', 'https://v3.football.api-sports.io', 10, fx_res_transport(200, $ok, ['x-ratelimit-requests-remaining' => '63', 'x-ratelimit-requests-limit' => '100']));
    $h2 = $p2->health();
    assert_equals('ONLINE', $h2['status']);
    assert_equals(63, $h2['rateLimitRemaining'], 'x-ratelimit-requests-remaining header is monitored');
    assert_equals(100, $h2['limitDaily']);
});

// ─── 2. Adapter configuration fixes (400 / 404) ─────────────────────────────

test('resilience: TheSportsDB legacy free key "3" is normalised to the documented "123"', function () {
    $urls = [];
    $p = new TheSportsDbProvider('3', 'https://www.thesportsdb.com/api/v1/json', 10, fx_res_transport(200, '{"events":[]}', [], $urls));
    $p->fixtures(['from' => '2026-09-05', 'to' => '2026-09-05']);
    assert_equals(1, count($urls));
    assert_contains('/api/v1/json/123/eventsday.php?d=2026-09-05&s=Soccer', $urls[0]);
    assert_false(str_contains($urls[0], '/json/3/'), 'legacy key never reaches the wire');
    assert_equals('free', $p->health()['tier']);
    // a premium key is untouched
    $urls = [];
    $prem = new TheSportsDbProvider('9876543210', 'https://www.thesportsdb.com/api/v1/json', 10, fx_res_transport(200, '{"sports":[{"idSport":"102"}]}', [], $urls));
    assert_equals('premium', $prem->health()['tier']);
    assert_contains('/json/9876543210/all_sports.php', $urls[0]);
});

test('resilience: TheSportsDB base URL with an embedded key or the v2 root is canonicalised', function () {
    assert_equals('https://www.thesportsdb.com/api/v1/json', TheSportsDbProvider::normalizeBaseUrl('https://www.thesportsdb.com/api/v1/json/3'));
    assert_equals('https://www.thesportsdb.com/api/v1/json', TheSportsDbProvider::normalizeBaseUrl('https://www.thesportsdb.com/api/v2/json'));
    assert_equals('https://www.thesportsdb.com/api/v1/json', TheSportsDbProvider::normalizeBaseUrl('thesportsdb.com'));
    assert_equals('https://www.thesportsdb.com/api/v1/json', TheSportsDbProvider::normalizeBaseUrl(''));
    assert_equals('https://proxy.internal/tsdb', TheSportsDbProvider::normalizeBaseUrl('https://proxy.internal/tsdb/'), 'a proxy is left alone');
});

test('resilience: TheSportsDB HTTP 400 on every day is a provider failure, not "no fixtures"', function () {
    $p = new TheSportsDbProvider('123', 'https://www.thesportsdb.com/api/v1/json', 10, fx_res_transport(400, 'Bad Request'));
    try { $p->fixtures(['from' => '2026-09-05', 'to' => '2026-09-05']); assert_true(false, 'must throw'); }
    catch (ProviderException $e) {
        assert_equals(ProviderException::BAD_REQUEST, $e->status);
        assert_equals(400, $e->details['httpStatus']);
        assert_contains('eventsday.php', $e->details['endpoint']);
        assert_contains('[redacted]', $e->details['endpoint'], 'key segment redacted from the diagnostic');
    }
    // a partially failing range still returns what it got (one bad day is counted, not fatal)
    $n = 0;
    $flaky = new TheSportsDbProvider('123', 'https://www.thesportsdb.com/api/v1/json', 10, function () use (&$n) { $n++; return $n === 1 ? ['status' => 500, 'body' => ''] : ['status' => 200, 'body' => '{"events":[]}']; });
    assert_equals([], $flaky->fixtures(['from' => '2026-09-05', 'to' => '2026-09-06']));
});

test('resilience: SportMonks base URL mistakes that 404 every call are canonicalised', function () {
    $canon = 'https://api.sportmonks.com/v3/football';
    assert_equals($canon, SportMonksProvider::normalizeBaseUrl('https://soccer.sportmonks.com/api/v2.0'), 'v2 host');
    assert_equals($canon, SportMonksProvider::normalizeBaseUrl('https://api.sportmonks.com/v3'), 'missing sport segment');
    assert_equals($canon, SportMonksProvider::normalizeBaseUrl('https://api.sportmonks.com'), 'bare host');
    assert_equals($canon, SportMonksProvider::normalizeBaseUrl('https://www.sportmonks.com/football-api'), 'marketing site');
    assert_equals($canon, SportMonksProvider::normalizeBaseUrl('api.sportmonks.com/v3/football/'), 'no scheme, trailing slash');
    assert_equals($canon, SportMonksProvider::normalizeBaseUrl(''));
    assert_equals('https://api.sportmonks.com/v3/core', SportMonksProvider::normalizeBaseUrl('https://api.sportmonks.com/v3/core'), 'other v3 products are legitimate');
    assert_equals('https://proxy.internal/sm', SportMonksProvider::normalizeBaseUrl('https://proxy.internal/sm'), 'a proxy is left alone');

    $urls = [];
    $p = new SportMonksProvider('tok', 'https://soccer.sportmonks.com/api/v2.0', 10, fx_res_transport(200, '{"data":[]}', [], $urls));
    $p->fixtures(['from' => '2026-09-05', 'to' => '2026-09-05']);
    assert_contains('https://api.sportmonks.com/v3/football/fixtures/date/2026-09-05', $urls[0]);
});

test('resilience: SportMonks 404 is NOT_FOUND with a redacted endpoint (token never logged)', function () {
    $p = new SportMonksProvider('super-secret-token', 'https://api.sportmonks.com/v3/football', 10, fx_res_transport(404, '{"message":"The requested endpoint does not exist."}'));
    try { $p->fixtures(['from' => '2026-09-05', 'to' => '2026-09-05']); assert_true(false); }
    catch (ProviderException $e) {
        assert_equals(ProviderException::NOT_FOUND, $e->status);
        assert_true($e->isConfigurationError());
        assert_false(str_contains($e->getMessage(), 'super-secret-token'));
        assert_false(str_contains(json_encode($e->details), 'super-secret-token'), 'diagnostic carries no secret');
        assert_equals('[redacted]', $e->details['query']['api_token']);
        assert_equals(404, $e->details['httpStatus']);
        assert_equals('GET', $e->details['method']);
        assert_contains('endpoint does not exist', $e->details['bodySnippet']);
    }
    $h = $p->health();
    assert_equals(ProviderException::NOT_FOUND, $h['status']);
    assert_equals(404, $h['httpStatus']);
});

test('resilience: SportMonks odds add-on refusal degrades to [] but throttling propagates', function () {
    $p = new SportMonksProvider('t', 'https://api.sportmonks.com/v3/football', 10, fx_res_transport(403, '{"message":"not in your plan"}'));
    assert_equals([], $p->odds('1'), 'plan refusal → no odds, none fabricated');
    $q = new SportMonksProvider('t', 'https://api.sportmonks.com/v3/football', 10, fx_res_transport(429, '{"message":"Too many requests."}'));
    assert_throws(ProviderException::class, fn() => $q->odds('1'), 'a throttle must reach the breaker');
});

test('resilience: generic HTTP provider 404 is a configuration error naming the base URL', function () {
    $p = new HttpSportsProvider('http-provider', 'https://feeds.example.com/v1', 'bearer-secret-token-value', 5, fx_res_transport(404, 'Not Found'));
    try { $p->fixtures(['from' => '2026-09-05', 'to' => '2026-09-05']); assert_true(false); }
    catch (ProviderException $e) {
        assert_equals(ProviderException::NOT_FOUND, $e->status);
        assert_equals('https://feeds.example.com/v1/fixtures?from=2026-09-05&to=2026-09-05', $e->details['endpoint']);
        assert_false(str_contains(json_encode($e->details), 'bearer-secret-token-value'));
    }
    $h = $p->health();
    assert_equals(ProviderException::NOT_FOUND, $h['status']);
    assert_contains('feeds.example.com/v1', $h['detail']);
    assert_contains('/health, /fixtures contract', $h['detail']);
    assert_equals(ProviderException::BAD_REQUEST, (new HttpSportsProvider('h', 'https://x.test', 't', 5, fx_res_transport(400)))->health()['status']);
    assert_equals(ProviderException::TIMEOUT, (new HttpSportsProvider('h', 'https://x.test', 't', 5, fn() => ['status' => 0, 'body' => '', 'errno' => 28, 'error' => 'timed out']))->health()['status']);
});

test('resilience: redaction covers query tokens, path keys, bearer headers and raw secrets', function () {
    assert_equals('https://api.sportmonks.com/v3/football/leagues?api_token=[redacted]&per_page=1', ProviderHttp::redactUrl('https://api.sportmonks.com/v3/football/leagues?api_token=abcDEF123&per_page=1'));
    assert_equals('https://www.thesportsdb.com/api/v1/json/[redacted]/eventsday.php?d=2026-09-05', ProviderHttp::redactUrl('https://www.thesportsdb.com/api/v1/json/9876543210/eventsday.php?d=2026-09-05'));
    assert_contains('Bearer [redacted]', ProviderHttp::redact('Authorization: Bearer abcdefghijklmnop'));
    assert_contains('x-apisports-key: [redacted]', ProviderHttp::redact('x-apisports-key: 0123456789abcdef'));
    assert_false(str_contains(ProviderHttp::redact('key=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'), 'aaaaaaaaaa'));
});

// ─── 3. Circuit breaker ─────────────────────────────────────────────────────

test('resilience: circuit opens after N generic failures and half-opens after the cooldown', function () {
    $now = 1_000_000;
    $b = new ProviderCircuitBreaker(threshold: 3, cooldownSeconds: 600, clock: function () use (&$now) { return $now; });
    $err = new ProviderException('boom', ProviderException::DATA_ERROR);
    $b->recordFailure('p', $err); $b->recordFailure('p', $err);
    assert_true($b->canCall('p'), 'below threshold → still callable');
    $b->recordFailure('p', $err);
    assert_false($b->canCall('p'), '3 failures → OPEN');
    assert_equals('OPEN', $b->state('p')['state']);
    $now += 599;
    assert_false($b->canCall('p'));
    $now += 2;
    assert_true($b->canCall('p'), 'cooldown elapsed → HALF_OPEN probe allowed');
    assert_equals('HALF_OPEN', $b->state('p')['state']);
    $b->recordFailure('p', $err);
    assert_equals('OPEN', $b->state('p')['state'], 'probe failed → OPEN again');
    $now += 601;
    $b->recordSuccess('p');
    assert_equals('CLOSED', $b->state('p')['state']);
    assert_equals(0, $b->state('p')['failures']);
});

test('resilience: DAILY_QUOTA_EXHAUSTED opens the circuit immediately until the vendor reset (00:00 UTC)', function () {
    $now = strtotime('2026-09-05T08:40:00Z');
    $b = new ProviderCircuitBreaker(clock: function () use (&$now) { return $now; });
    $b->recordFailure('api-football', new ProviderException('You have reached the request limit for the day', ProviderException::DAILY_QUOTA_EXHAUSTED));
    $s = $b->state('api-football');
    assert_equals('OPEN', $s['state'], 'one quota failure is enough');
    assert_equals('DAILY_QUOTA_EXHAUSTED', $s['reason']);
    assert_equals('2026-09-06T00:00:00+00:00', $s['retryAt']);
    $now = strtotime('2026-09-05T23:59:59Z');
    assert_false($b->canCall('api-football'), 'still closed one second before midnight');
    $now = strtotime('2026-09-06T00:00:00Z');
    assert_true($b->canCall('api-football'), 'reopens at the reset');
});

test('resilience: RATE_LIMITED honours Retry-After; configuration errors get the config cooldown', function () {
    $now = 5000;
    $b = new ProviderCircuitBreaker(configCooldownSeconds: 300, rateLimitCooldownSeconds: 60, clock: function () use (&$now) { return $now; });
    $b->recordFailure('sm', new ProviderException('429', ProviderException::RATE_LIMITED, null, ['retryAfterSeconds' => 15]));
    assert_equals(gmdate('c', 5015), $b->state('sm')['retryAt']);
    $b->recordFailure('tsdb', new ProviderException('400', ProviderException::BAD_REQUEST));
    assert_equals('OPEN', $b->state('tsdb')['state'], 'a 400 is not retried on the next fixture');
    assert_equals(gmdate('c', 5300), $b->state('tsdb')['retryAt']);
    $b->recordFailure('http', new ProviderException('404', ProviderException::NOT_FOUND));
    assert_equals('OPEN', $b->state('http')['state']);
    $b->recordFailure('auth', new ProviderException('401', ProviderException::AUTHENTICATION_ERROR));
    assert_equals('OPEN', $b->state('auth')['state']);
    $b->reset('tsdb');
    assert_equals('CLOSED', $b->state('tsdb')['state'], 'operator reset');
});

test('resilience: circuits re-hydrate from the persisted health history across processes', function () {
    $now = strtotime('2026-09-05T09:05:00Z');
    $b = new ProviderCircuitBreaker(clock: function () use (&$now) { return $now; });
    $b->hydrate([
        'api-football' => ['status' => 'DAILY_QUOTA_EXHAUSTED', 'observed_at' => '2026-09-05T08:40:00+00:00'],
        'sportmonks' => ['status' => 'NOT_FOUND', 'observed_at' => '2026-09-05T09:03:00+00:00'],
        'thesportsdb' => ['status' => 'BAD_REQUEST', 'observed_at' => '2026-09-05T07:00:00+00:00'],   // config window elapsed → retry
        'ok' => ['status' => 'ONLINE', 'observed_at' => '2026-09-05T09:04:00+00:00'],
        'old' => ['status' => 'DAILY_QUOTA_EXHAUSTED', 'observed_at' => '2026-09-04T23:00:00+00:00'], // yesterday's quota → reset passed
    ]);
    assert_false($b->canCall('api-football'), 'quota death at 08:40 is remembered at 09:05');
    assert_equals('2026-09-06T00:00:00+00:00', $b->state('api-football')['retryAt']);
    assert_false($b->canCall('sportmonks'));
    assert_true($b->canCall('thesportsdb'), 'an old config error is retried');
    assert_true($b->canCall('ok'));
    assert_true($b->canCall('old'), 'yesterday\'s quota exhaustion does not block today');
});

// ─── 4. Manager: no hammering, independent fallback, structured failure ─────

test('resilience: manager never calls a provider whose circuit is OPEN — not even health()', function () {
    $m = new SportsProviderManager();
    $dead = fx_res_provider('api-football', fn() => throw new ProviderException('limit for the day', ProviderException::DAILY_QUOTA_EXHAUSTED));
    $ok = fx_res_provider('sportmonks', fn() => [['externalId' => 'x']]);
    $m->register($dead); $m->register($ok);
    for ($i = 0; $i < 25; $i++) {
        $out = $m->withFallback('fixtures', fn($p) => $p->fixtures([]));
        assert_true($out['ok']);
        assert_equals('sportmonks', $out['provider']);
    }
    assert_equals(1, $dead->fixtureCalls, '25 rounds → the quota-dead provider was asked once');
    assert_equals(1, $dead->healthCalls, 'and probed once');
    assert_equals(25, $ok->fixtureCalls);
});

test('resilience: a provider self-reporting a terminal status via health() opens its circuit too', function () {
    $m = new SportsProviderManager();
    $quota = fx_res_provider('api-football', fn() => [['externalId' => 'never']], ['status' => 'DAILY_QUOTA_EXHAUSTED', 'detail' => 'daily quota used (100/100 on the Free plan)', 'retryAt' => gmdate('c', ProviderHttp::nextUtcMidnight())]);
    $ok = fx_res_provider('thesportsdb', fn() => [['externalId' => 'x']]);
    $m->register($quota); $m->register($ok);
    for ($i = 0; $i < 5; $i++) $m->withFallback('fixtures', fn($p) => $p->fixtures([]));
    assert_equals(1, $quota->healthCalls, 'health() probed once, then the circuit is OPEN');
    assert_equals(0, $quota->fixtureCalls, 'a non-ONLINE provider is never asked for data');
    assert_equals('OPEN', $m->breaker()->state('api-football')['state']);
    assert_equals('DAILY_QUOTA_EXHAUSTED', $m->breaker()->state('api-football')['reason']);
});

test('resilience: when every provider fails the manager returns per-provider statuses and a summary', function () {
    $m = new SportsProviderManager();
    $m->register(fx_res_provider('api-football', fn() => throw new ProviderException('You have reached the request limit for the day', ProviderException::DAILY_QUOTA_EXHAUSTED)));
    $m->register(fx_res_provider('thesportsdb', fn() => throw new ProviderException('HTTP 400', ProviderException::BAD_REQUEST)));
    $m->register(fx_res_provider('sportmonks', fn() => throw new ProviderException('HTTP 404', ProviderException::NOT_FOUND)));
    $m->register(fx_res_provider('http-provider', fn() => throw new ProviderException('HTTP 404', ProviderException::NOT_FOUND)));
    $out = $m->withFallback('fixtures', fn($p) => $p->fixtures([]));
    assert_false($out['ok']);
    assert_equals(['api-football' => 'DAILY_QUOTA_EXHAUSTED', 'thesportsdb' => 'BAD_REQUEST', 'sportmonks' => 'NOT_FOUND', 'http-provider' => 'NOT_FOUND'], $out['failureStatuses']);
    assert_equals('fixtures: all 4 provider(s) failed — api-football DAILY_QUOTA_EXHAUSTED, thesportsdb BAD_REQUEST, sportmonks NOT_FOUND, http-provider NOT_FOUND', $out['summary']);
    // readiness: 0/4 → BLOCKED, and no provider is re-probed to compute it
    $r = $m->readiness();
    assert_equals(0, $r['operational']);
    assert_equals(4, $r['total']);
    assert_equals('BLOCKED', $r['engine']);
    assert_equals('OPEN', $r['providers']['api-football']['circuit']);
    assert_equals('DAILY_QUOTA_EXHAUSTED', $r['providers']['api-football']['status']);
    assert_equals('NOT_FOUND', $r['providers']['sportmonks']['status']);
});

test('resilience: manager health() reports an OPEN circuit without touching the provider', function () {
    $m = new SportsProviderManager();
    $dead = fx_res_provider('api-football', fn() => throw new ProviderException('quota', ProviderException::DAILY_QUOTA_EXHAUSTED));
    $m->register($dead);
    $m->withFallback('fixtures', fn($p) => $p->fixtures([]));
    $calls = $dead->healthCalls;
    $h = $m->health();
    assert_equals($calls, $dead->healthCalls, 'no live probe while OPEN');
    assert_equals('DAILY_QUOTA_EXHAUSTED', $h['api-football']['status']);
    assert_equals('OPEN', $h['api-football']['circuit']['state']);
    assert_false($h['api-football']['operational']);
    assert_contains('circuit open until', $h['api-football']['detail']);
});

test('resilience: manager output never leaks a credential', function () {
    $m = new SportsProviderManager();
    $m->register(fx_res_provider('leaky', fn() => throw new \RuntimeException('GET https://api.sportmonks.com/v3/football/fixtures?api_token=SUPERSECRETTOKEN1234567890abcdef failed')));
    $out = $m->withFallback('fixtures', fn($p) => $p->fixtures([]));
    assert_false(str_contains(json_encode($out), 'SUPERSECRETTOKEN'));
    assert_contains('api_token=[redacted]', $out['failures']['leaky']);
});

// ─── 5. Health monitor keeps the specific states ─────────────────────────────

test('resilience: derived health keeps DAILY_QUOTA_EXHAUSTED / NOT_FOUND / BAD_REQUEST as their own states', function () {
    $mon = new ProviderHealthMonitor();
    $now = strtotime('2026-09-05T10:00:00Z');
    $p = ['id' => 1, 'provider_code' => 'api-football'];
    $quota = $mon->assess($p, [['status' => 'DAILY_QUOTA_EXHAUSTED', 'observed_at' => '2026-09-05T08:40:00+00:00']], [], $now);
    assert_equals('DAILY_QUOTA_EXHAUSTED', $quota['status']);
    assert_contains('resets 2026-09-06T00:00:00', $quota['detail']);
    $nf = $mon->assess($p, [['status' => 'NOT_FOUND', 'observed_at' => '2026-09-05T09:50:00+00:00']], [], $now);
    assert_equals('NOT_FOUND', $nf['status']);
    assert_contains('configuration problem', $nf['detail']);
    $br = $mon->assess($p, [['status' => 'BAD_REQUEST', 'observed_at' => '2026-09-05T09:58:00+00:00']], [], $now);
    assert_equals('BAD_REQUEST', $br['status']);
    // after the quota reset the same observation is merely stale history, not a live block
    $later = $mon->assess($p, [['status' => 'DAILY_QUOTA_EXHAUSTED', 'observed_at' => '2026-09-05T08:40:00+00:00']], [], strtotime('2026-09-06T00:30:00Z'));
    assert_not_equals('DAILY_QUOTA_EXHAUSTED', $later['status']);
});
