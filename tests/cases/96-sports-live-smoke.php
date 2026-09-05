<?php
// SportsLiveSmoke — the "does the provider actually work?" diagnostic used
// by `php index.php tools sports-live`. Verifies the step logic, pass/fail
// classification and the honest no-configuration report.
use AIWorkforce\Sports\Providers\SportsDataProvider;
use AIWorkforce\Sports\Providers\SportsProviderManager;
use AIWorkforce\Sports\SportsLiveSmoke;

/** Configurable fake provider for smoke-test verification. */
function fx_smoke_provider(string $id, string $healthStatus = 'ONLINE', int $fixtureCount = 1, bool $withTopPlayers = true): object
{
    return new class($id, $healthStatus, $fixtureCount, $withTopPlayers) implements SportsDataProvider {
        public int $oddsCalls = 0;
        public int $topCalls = 0;
        public function __construct(private string $id, private string $healthStatus, private int $fixtureCount, private bool $withTopPlayers) {}
        public function id(): string { return $this->id; }
        public function health(): array
        {
            return $this->healthStatus === 'ONLINE'
                ? ['status' => 'ONLINE', 'requestsToday' => 12, 'limitDaily' => 100]
                : ['status' => $this->healthStatus, 'detail' => 'authentication rejected (HTTP 401)'];
        }
        public function fixtures(array $q): array
        {
            $out = [];
            for ($i = 0; $i < $this->fixtureCount; $i++) {
                $out[] = ['externalId' => 'fx-' . $i, 'homeTeam' => 'H' . $i, 'awayTeam' => 'A' . $i];
            }
            return $out;
        }
        public function odds(string $fixtureExternalId): array
        {
            $this->oddsCalls++;
            return [['market' => 'MATCH_RESULT', 'selection' => 'HOME', 'decimalOdds' => 2.0, 'bookmaker' => 'Book', 'fixtureId' => $fixtureExternalId]];
        }
        public function results(string $fixtureExternalId): array { return []; }
        public function topPlayers(string $leagueId, string $season, string $type = 'scorers'): array
        {
            $this->topCalls++;
            return ['leagueId' => $leagueId, 'season' => $season, 'type' => $type, 'league' => null, 'players' => [['rank' => 1, 'name' => 'P', 'value' => 5]]];
        }
    };
}

/** The fake's topPlayers must be optional — strip it by wrapping in a delegating object is overkill; instead use a closure-based provider. */
function fx_smoke_plain_provider(string $id): SportsDataProvider
{
    return new class($id) implements SportsDataProvider {
        public function __construct(private string $id) {}
        public function id(): string { return $this->id; }
        public function health(): array { return ['status' => 'ONLINE']; }
        public function fixtures(array $q): array { return [['externalId' => 'p-1', 'homeTeam' => 'H', 'awayTeam' => 'A']]; }
        public function odds(string $fixtureExternalId): array { return []; }
        public function results(string $fixtureExternalId): array { return []; }
    };
}

function fx_smoke_manager(array $providers): SportsProviderManager
{
    $m = new SportsProviderManager();
    foreach ($providers as $p) $m->register($p);
    return $m;
}

test('smoke: healthy provider passes all layers', function () {
    $fake = fx_smoke_provider('api-football', 'ONLINE', 2, true);
    $report = (new SportsLiveSmoke())->run(fx_smoke_manager([$fake]));

    assert_true($report['configured'] === true, 'configured');
    assert_true($report['pass'] === true, 'overall pass');
    $entry = $report['providers']['api-football'];
    assert_true($entry['pass'] === true, 'provider pass');
    assert_true($entry['steps']['health']['ok'] === true, 'health ok');
    assert_equals(12, $entry['steps']['health']['requestsToday'], 'quota usage surfaced');
    assert_true($entry['steps']['fixtures']['ok'] === true, 'fixtures ok');
    assert_equals(2, $entry['steps']['fixtures']['count']);
    assert_true($entry['steps']['odds']['ok'] === true, 'odds ok');
    assert_equals(1, $entry['steps']['odds']['rows']);
    assert_true($entry['steps']['topPlayers']['ok'] === true, 'top players probed');
    assert_equals(1, $entry['steps']['topPlayers']['players']);
    assert_equals(1, $fake->oddsCalls, 'odds probed once');
    assert_equals(1, $fake->topCalls, 'top players probed once');
});

test('smoke: auth failure fails the provider and surfaces the reason', function () {
    $fake = fx_smoke_provider('api-football', 'AUTHENTICATION_ERROR', 1, true);
    $report = (new SportsLiveSmoke())->run(fx_smoke_manager([$fake]));

    assert_true($report['pass'] === false, 'overall must fail');
    $entry = $report['providers']['api-football'];
    assert_true($entry['pass'] === false);
    assert_true($entry['steps']['health']['ok'] === false, 'health step failed');
    assert_equals('AUTHENTICATION_ERROR', $entry['steps']['health']['status']);
    assert_contains('authentication rejected', (string) ($entry['steps']['health']['error'] ?? ''), 'provider detail surfaced');
    assert_true(isset($entry['steps']['fixtures']), 'remaining layers still probed for a complete diagnosis');
    assert_equals(1, $fake->oddsCalls, 'odds probed once despite the auth failure');
});

test('smoke: provider without topPlayers support is not probed for it', function () {
    $report = (new SportsLiveSmoke())->run(fx_smoke_manager([fx_smoke_plain_provider('thesportsdb')]));

    $entry = $report['providers']['thesportsdb'];
    assert_true($entry['pass'] === true, 'provider still passes on health + fixtures');
    assert_true(!isset($entry['steps']['topPlayers']), 'no topPlayers step for unsupported providers');
    assert_true(isset($entry['steps']['odds']), 'odds step present (empty result is fine)');
});

test('smoke: no providers configured reports an honest hint', function () {
    $report = (new SportsLiveSmoke())->run(fx_smoke_manager([]));
    assert_true($report['configured'] === false, 'not configured');
    assert_contains('WINDELS_API_FOOTBALL_KEY', (string) ($report['hint'] ?? ''), 'hint names the env key');
});

test('smoke: unknown provider filter fails cleanly with the registered list', function () {
    $report = (new SportsLiveSmoke())->run(fx_smoke_manager([fx_smoke_plain_provider('thesportsdb')]), 'api-football');
    assert_true($report['configured'] === false);
    assert_contains('api-football', (string) ($report['error'] ?? ''));
    assert_equals(['thesportsdb'], $report['registered']);
});

test('smoke: tools sports-live command is wired to the smoke class', function () {
    $tools = file_get_contents(FCPATH . 'application/controllers/Tools.php');
    assert_contains('public function sports_live()', (string) $tools, 'CLI command exists');
    $start = strpos($tools, 'public function sports_live()');
    $next = strpos($tools, 'public function lottery_cron', $start);
    $method = substr($tools, $start, $next !== false ? $next - $start : strlen($tools));
    assert_contains('SportsLiveSmoke', (string) $method, 'command runs the smoke class');
    assert_contains('exit(2)', (string) $method, 'exit 2 when nothing configured');
});

test('cli: documented hyphen commands have explicit routes (translate_uri_dashes is off)', function () {
    $routes = file_get_contents(FCPATH . 'application/config/routes.php');
    assert_true(str_contains($routes, '$route[\'translate_uri_dashes\'] = false;'), 'dashes are not translated');
    assert_contains('$route[\'tools/sports-cron\'] = \'tools/sports_cron\';', $routes, 'documented sports-cron form routed');
    assert_contains('$route[\'tools/lottery-cron\'] = \'tools/lottery_cron\';', $routes, 'documented lottery-cron form routed');
    assert_contains('$route[\'tools/sports-live\'] = \'tools/sports_live\';', $routes, 'sports-live form routed');
});
