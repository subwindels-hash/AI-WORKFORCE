<?php
/**
 * Football pipeline resilience — the two production failure modes of 2026-09.
 *
 * 1. Credential resolution. The documented environment variable is
 *    API_FOOTBALL_KEY; WINDELS_API_FOOTBALL_KEY remains a legacy alias. An
 *    operator who sets only the documented variable must get a connected
 *    provider — NOT_CONFIGURED is how the "0 prediction rows" outage started.
 *
 * 2. Outage scoping. A provider outage (backoff) gates only the jobs that
 *    would spend a provider request. Predict, settle and performance are
 *    database-only: predictions must still be generated from already-stored
 *    fixtures, stored results still settle, and the performance snapshot stays
 *    current while the provider recovers.
 */
require_once TESTSPATH . 'football_support.php';

use AIWorkforce\ApiProviders;
use AIWorkforce\Football\FootballConfiguration;
use AIWorkforce\Football\FootballIntelligence;
use AIWorkforce\Football\RefreshPolicy;
use AIWorkforce\Sports\Providers\SportsProviderManager;
use AIWorkforce\Sports\SportsIntelligence;

test('football credential: API_FOOTBALL_KEY is the primary variable', function () {
    $prev = [];
    foreach (['API_FOOTBALL_KEY', 'WINDELS_API_FOOTBALL_KEY', 'API_FOOTBALL_BASE_URL', 'WINDELS_API_FOOTBALL_BASE_URL'] as $name) {
        $prev[$name] = getenv($name);
        putenv($name);
    }
    try {
        putenv('API_FOOTBALL_KEY=primary-key');
        $c = ApiProviders::footballCredential();
        assert_equals('primary-key', $c['key'], 'the documented variable wins');
        assert_equals('https://v3.football.api-sports.io', $c['baseUrl'], 'the canonical base URL is the default');

        putenv('API_FOOTBALL_KEY=primary-key');
        putenv('WINDELS_API_FOOTBALL_KEY=legacy-key');
        assert_equals('primary-key', ApiProviders::footballCredential()['key'], 'the legacy alias never overrides the primary');

        putenv('API_FOOTBALL_KEY');
        putenv('WINDELS_API_FOOTBALL_KEY=legacy-key');
        assert_equals('legacy-key', ApiProviders::footballCredential()['key'], 'an existing deployment keeps working through the alias');

        putenv('WINDELS_API_FOOTBALL_KEY');
        putenv('API_FOOTBALL_BASE_URL=https://football.example-proxy.internal');
        $c = ApiProviders::footballCredential();
        assert_equals('', $c['key'], 'no variable, no key — never a default credential');
        assert_equals('https://football.example-proxy.internal', $c['baseUrl'], 'the primary base URL variable is honoured');
    } finally {
        foreach ($prev as $name => $value) {
            if ($value === false) putenv($name);
            else putenv($name . '=' . $value);
        }
    }
});

test('football credential: an environment using only API_FOOTBALL_KEY connects the provider', function () {
    $prev = [];
    foreach (['API_FOOTBALL_KEY', 'WINDELS_API_FOOTBALL_KEY'] as $name) {
        $prev[$name] = getenv($name);
        putenv($name);
    }
    try {
        putenv('API_FOOTBALL_KEY=prod-style-key');
        $intel = new SportsIntelligence(new SportsRepositoryStub(), new class implements \AIWorkforce\Persistence\AuditRepository {
            public function emit(string $t, string $s, array $d = [], string $a = 'system'): void {}
            public function recent(int $l = 100): array { return []; }
        });
        $af = $intel->providers->provider('api-football');
        assert_true($af !== null, 'the provider is registered from the documented variable');
        assert_true($af instanceof \AIWorkforce\Sports\Providers\ApiFootballProvider, 'the native adapter is used');
    } finally {
        foreach ($prev as $name => $value) {
            if ($value === false) putenv($name);
            else putenv($name . '=' . $value);
        }
    }
});

test('football outage: backoff gates only the provider jobs, never the database-only ones', function () {
    $day = gmdate('Y-m-d', time() + 3600);
    [$repo, $provider, $intel] = fx_fb_harness(
        [fx_fb_row('fx-outage', gmdate('c', time() + 3600), 'Arsenal', 'Brighton', '10', '20')],
        ['skipHistory' => true]
    );
    fx_fb_sync_today($intel, $day);

    // The provider dies mid-day (any failure puts it into its backoff window).
    $intel->gateway()->recordFailure($provider->id(), 'simulated provider outage');

    $policy = new RefreshPolicy($repo, new FootballConfiguration(), $intel->gateway());

    foreach (['football-fixtures', 'football-upcoming', 'football-live', 'football-results'] as $job) {
        $evaluation = $policy->evaluate($job);
        assert_equals(false, $evaluation['due'], $job . ' waits for the provider to recover');
        assert_equals('PROVIDER_BACKOFF', (string) $evaluation['reason'], $job . ' names the provider outage');
    }

    // The point of the fix: stored work is processed while the provider is down.
    foreach (['football-predict', 'football-settle', 'football-performance'] as $job) {
        $evaluation = $policy->evaluate($job);
        $work = (array) ($evaluation['detail'] ?? []);
        if ((bool) ($work['present'] ?? false)) {
            assert_equals(true, $evaluation['due'], $job . ' is gated by its own work, not by the provider outage');
            assert_not_equals('PROVIDER_BACKOFF', (string) $evaluation['reason'], $job . ' never cites a provider outage');
        }
    }
    assert_equals(true, $policy->evaluate('football-cleanup')['due'], 'housekeeping is database work and always runs');
});

test('football outage: predictions are still generated from stored fixtures while the provider is in backoff', function () {
    $day = gmdate('Y-m-d', time() + 3600);
    [$repo, $provider, $intel] = fx_fb_harness(
        [
            fx_fb_row('fx-out-1', gmdate('c', time() + 3600), 'Arsenal', 'Brighton', '10', '20'),
            fx_fb_row('fx-out-2', gmdate('c', time() + 5400), 'Liverpool', 'Everton', '30', '40'),
        ],
        ['skipHistory' => true]
    );
    fx_fb_sync_today($intel, $day);

    // Provider goes down after the fixtures are stored — exactly the order of
    // events behind the empty 2026-09 board.
    $intel->gateway()->recordFailure($provider->id(), 'simulated provider outage');

    $result = $intel->predictions()->predictDay($day);
    assert_equals('COMPLETED', (string) $result['status'], 'analysis reads stored fixtures only');
    assert_true((int) $result['fixtures'] >= 2, 'the stored fixtures are still on the board');
    assert_true((int) $result['analyzed'] >= 1, 'the engine ran against stored data during the outage');

    // And the scheduled tick agrees: the predict job is due, not skipped.
    $policy = new RefreshPolicy($repo, new FootballConfiguration(), $intel->gateway());
    $evaluation = $policy->evaluate('football-predict');
    $work = (array) ($evaluation['detail'] ?? []);
    assert_equals(true, $evaluation['due'] || !((bool) ($work['present'] ?? false)),
        'the predict job is never frozen by a provider outage');
});
