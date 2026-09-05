<?php
// Top players (api-football /players/top* — Players tag) wired through
// SportsIntelligence and the /api/sports/topplayers read endpoint:
// provider resolution (explicit + health-aware auto fallback), error
// classification, route and permission gating.
use AIWorkforce\Persistence\AuditRepository;
use AIWorkforce\Sports\Providers\ProviderException;
use AIWorkforce\Sports\Providers\SportsDataProvider;
use AIWorkforce\Sports\SportsIntelligence;

function fx_tp_audit(): AuditRepository
{
    return new class implements AuditRepository { public array $events = []; public function emit(string $t, string $s, array $d = [], string $a = 'system'): void { $this->events[] = ['type' => $t, 'detail' => $d]; } public function recent(int $l = 100): array { return []; } };
}

/** Fake provider WITHOUT top-player support (e.g. TheSportsDB). */
function fx_tp_plain_provider(string $id): SportsDataProvider
{
    return new class($id) implements SportsDataProvider {
        public function __construct(private string $id) {}
        public function id(): string { return $this->id; }
        public function health(): array { return ['status' => 'ONLINE', 'reliability' => 0.9]; }
        public function fixtures(array $q): array { return []; }
        public function odds(string $fixtureExternalId): array { return []; }
        public function results(string $fixtureExternalId): array { return []; }
    };
}

/** Fake provider WITH a counting topPlayers() method (api-football shape). */
function fx_tp_top_provider(string $id, ?ProviderException $fail = null): object
{
    return new class($id, $fail) implements SportsDataProvider {
        public int $topCalls = 0;
        public array $lastArgs = [];
        public function __construct(private string $id, private ?ProviderException $fail) {}
        public function id(): string { return $this->id; }
        public function health(): array { return ['status' => 'ONLINE', 'reliability' => 0.95]; }
        public function fixtures(array $q): array { return []; }
        public function odds(string $fixtureExternalId): array { return []; }
        public function results(string $fixtureExternalId): array { return []; }
        public function topPlayers(string $leagueId, string $season, string $type = 'scorers'): array
        {
            if ($this->fail !== null) throw $this->fail;
            $this->topCalls++;
            $this->lastArgs = ['leagueId' => $leagueId, 'season' => $season, 'type' => $type];
            return [
                'leagueId' => $leagueId, 'season' => $season, 'type' => $type, 'league' => 'Test League',
                'players' => [['rank' => 1, 'playerId' => '7', 'name' => 'Top Player', 'team' => 'Test FC', 'value' => 9, 'statistics' => ['Yellow Cards' => 9]]],
            ];
        }
    };
}

function fx_tp_intelligence(array $providers): SportsIntelligence
{
    $intel = new SportsIntelligence(new SportsRepositoryStub(), fx_tp_audit());
    foreach ($providers as $p) $intel->providers->register($p);
    return $intel;
}

test('top players: auto mode picks the first provider with topPlayers support', function () {
    $plain = fx_tp_plain_provider('plain-only');
    $top = fx_tp_top_provider('top-capable');
    $intel = fx_tp_intelligence([$plain, $top]);
    $out = $intel->topPlayers(null, '61', '2020', 'yellow_cards');

    assert_equals('top-capable', $out['provider'], 'served by the capable provider');
    assert_equals(1, $top->topCalls, 'exactly one upstream call');
    assert_equals(['leagueId' => '61', 'season' => '2020', 'type' => 'yellow_cards'], $top->lastArgs, 'args forwarded verbatim');
    assert_equals('61', $out['leagueId']);
    assert_equals(9, $out['players'][0]['value'], 'payload merged with provider id');
});

test('top players: explicit provider is used directly and reported', function () {
    $top = fx_tp_top_provider('api-football');
    $intel = fx_tp_intelligence([$top]);
    $out = $intel->topPlayers('api-football', '39', '2026', 'scorers');
    assert_equals('api-football', $out['provider']);
    assert_equals(1, $top->topCalls);
});

test('top players: unknown provider id is rejected, not guessed', function () {
    $intel = fx_tp_intelligence([fx_tp_top_provider('top-capable')]);
    assert_throws(\InvalidArgumentException::class, fn() => $intel->topPlayers('nope', '61', '2020', 'scorers'), 'unregistered provider must fail loudly');
});

test('top players: provider without support is rejected for explicit requests', function () {
    $plain = fx_tp_plain_provider('plain-only');
    $intel = fx_tp_intelligence([$plain]);
    assert_throws(\InvalidArgumentException::class, fn() => $intel->topPlayers('plain-only', '61', '2020', 'scorers'), 'unsupported provider must fail loudly');
});

test('top players: auto mode with no supporting provider fails cleanly', function () {
    $intel = fx_tp_intelligence([fx_tp_plain_provider('plain-only')]);
    assert_throws(\InvalidArgumentException::class, fn() => $intel->topPlayers(null, '61', '2020', 'scorers'), 'no support → clean failure, no fabricated data');
});

test('top players: upstream provider errors propagate (explicit provider)', function () {
    $top = fx_tp_top_provider('api-football', new ProviderException('authentication rejected (HTTP 401)', ProviderException::AUTHENTICATION_ERROR));
    $intel = fx_tp_intelligence([$top]);
    assert_throws(ProviderException::class, fn() => $intel->topPlayers('api-football', '61', '2020', 'scorers'), 'upstream auth failure must surface');
});

test('top players: route, permission and driver capability are wired', function () {
    $routes = file_get_contents(FCPATH . 'application/config/routes.php');
    assert_contains('$route[\'api/sports/topplayers\'] = \'api_sports/top_players\';', $routes, 'route registered');

    $api = file_get_contents(FCPATH . 'application/controllers/Api_sports.php');
    $body = substr($api, strpos($api, 'public function top_players()'), strpos($api, '// ------------------------------------------------------------------ admin'));
    assert_contains("requirePermission('sports.view'", (string) $body, 'endpoint gated on sports.view');
    assert_contains("league and season are required", (string) $body, 'required params validated');
    assert_contains("'capabilities' => ['fixtures', 'odds', 'results', 'standings', 'team_statistics', 'top_players', 'leagues']", (string) $api, 'api-football driver advertises top_players');
});
