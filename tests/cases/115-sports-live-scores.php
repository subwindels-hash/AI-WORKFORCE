<?php
// Sports Intelligence live goal scores — normalization, live sweep + goal
// detection, the throttled LiveScoreService refresh/board contract, the
// sandbox live simulation, and the wiring (route, API, console panel, cron).
use AIWorkforce\Persistence\AuditRepository;
use AIWorkforce\Sports\DataQualityEngine;
use AIWorkforce\Sports\LiveScoreService;
use AIWorkforce\Sports\Providers\SandboxSportsProvider;
use AIWorkforce\Sports\Providers\SportsDataProvider;
use AIWorkforce\Sports\Providers\SportsProviderManager;
use AIWorkforce\Sports\SportsDataNormalizer;
use AIWorkforce\Sports\SportsIntelligence;
use AIWorkforce\Sports\SportsSyncService;

/** Audit stub that keeps full rows (type, at, summary, detail) for the goal feed. */
function fx_live_audit(): AuditRepository
{
    return new class implements AuditRepository {
        public array $rows = [];
        public function emit(string $type, string $summary, array $detail = [], string $actor = 'system'): void
        {
            $this->rows[] = ['type' => $type, 'at' => gmdate('c'), 'actor' => $actor, 'summary' => $summary, 'detail' => $detail];
        }
        public function recent(int $limit = 100): array
        {
            return array_slice(array_reverse($this->rows), 0, $limit);
        }
    };
}

/**
 * Provider with a controllable live board: the test mutates $liveRows between
 * sweeps to simulate goals being scored, and $liveCalls counts provider hits
 * so throttling can be proven (no request = counter unchanged).
 */
function fx_live_provider(array $liveRows): object
{
    return new class($liveRows) implements SportsDataProvider {
        public array $liveRows;
        public int $liveCalls = 0;
        public function __construct(array $rows) { $this->liveRows = $rows; }
        public function id(): string { return 'live-test'; }
        public function health(): array { return ['status' => 'ONLINE', 'reliability' => 0.9]; }
        public function fixtures(array $query): array { return $this->liveRows; }
        public function odds(string $fixtureExternalId): array { return []; }
        public function results(string $fixtureExternalId): array { return []; }
        public function liveFixtures(): array { $this->liveCalls++; return $this->liveRows; }
    };
}

/** Plain provider WITHOUT a live endpoint (the interface minimum). */
function fx_live_noendpoint_provider(string $id): SportsDataProvider
{
    return new class($id) implements SportsDataProvider {
        public function __construct(private string $pid) {}
        public function id(): string { return $this->pid; }
        public function health(): array { return ['status' => 'ONLINE']; }
        public function fixtures(array $query): array { return []; }
        public function odds(string $fixtureExternalId): array { return []; }
        public function results(string $fixtureExternalId): array { return []; }
    };
}

function fx_live_row(int $home, int $away, int $minute = 34): array
{
    return [
        'externalId' => 'lv-1', 'homeTeam' => 'HomeFC', 'awayTeam' => 'AwayFC',
        'competition' => 'Live League', 'kickoff' => '2026-09-06T14:00:00Z',
        'status' => 'LIVE', 'sport' => 'football',
        'minute' => $minute, 'homeScore' => $home, 'awayScore' => $away,
        'sourceTimestamp' => gmdate('c'), 'simulated' => false,
    ];
}

/** Stored match row by external id (the stub keeps matches as a public list). */
function fx_live_find(SportsRepositoryStub $repo, string $externalId): ?array
{
    foreach ($repo->matches as $m) if (($m['external_id'] ?? '') === $externalId) return $m;
    return null;
}

/** Bare sweep triple over an EMPTY repository: [sync, repo, audit, provider]. */
function fx_live_sweep(array $liveRows): array
{
    $repo = new SportsRepositoryStub();
    $audit = fx_live_audit();
    $sync = new SportsSyncService($repo, $audit, new DataQualityEngine());
    return [$sync, $repo, $audit, fx_live_provider($liveRows)];
}

/** Full live-score stack over the stub repository: [sync, liveService, repo, audit, provider]. */
function fx_live_stack(array $liveRows, int $intervalSeconds = 10): array
{
    putenv('WINDELS_SPORTS_LIVE_REFRESH_SECONDS=' . $intervalSeconds);
    $repo = new SportsRepositoryStub();
    $audit = fx_live_audit();
    $sync = new SportsSyncService($repo, $audit, new DataQualityEngine());
    $provider = fx_live_provider($liveRows);
    // Pre-store the baseline LIVE match — the state a fixture sync leaves
    // behind — so the in-play window gate lets the throttled refresh sweep.
    if ($liveRows !== []) {
        $source = $repo->ensureProvider('live-test', 'live-test');
        $repo->saveMatch((int) $source['id'], SportsDataNormalizer::fixture($liveRows[0], 'live-test'));
    }
    $manager = new SportsProviderManager();
    $manager->register($provider);
    $service = new LiveScoreService($repo, $audit, $sync, $manager);
    return [$sync, $service, $repo, $audit, $provider];
}

test('live scores: normalizer carries provider live state through unchanged', function () {
    $match = SportsDataNormalizer::fixture(fx_live_row(1, 0, 67) + ['statusShort' => '2H'], 'live-test');
    assert_equals(['minute' => 67, 'homeScore' => 1, 'awayScore' => 0, 'statusShort' => '2H'], $match['live'], 'live state is preserved');
    // Absent detail stays absent — never a fabricated 0-0.
    $scheduled = SportsDataNormalizer::fixture(['externalId' => 's-1', 'homeTeam' => 'H', 'awayTeam' => 'A', 'competition' => 'L', 'kickoff' => '2026-09-06T14:00:00Z'], 'live-test');
    assert_null($scheduled['live'], 'a scheduled fixture has no live state');
    // Non-numeric garbage is dropped rather than coerced.
    $junk = SportsDataNormalizer::fixture(fx_live_row(1, 0) + ['minute' => 'n/a'], 'live-test');
    assert_false(isset($junk['live']['minute']), 'a non-numeric minute is dropped');
    assert_equals(1, $junk['live']['homeScore'], 'valid fields beside it survive');
});

test('live scores: sweep stores the live state and audits nothing on first observation', function () {
    [$sync, $repo, $audit, $provider] = fx_live_sweep([fx_live_row(0, 0, 12)]);
    $result = $sync->syncLive($provider, 'live-first');
    assert_equals('COMPLETED', $result['status']);
    assert_equals([], $result['goalEvents'], 'a first observation is not a goal');
    $stored = fx_live_find($repo, 'lv-1');
    assert_not_null($stored, 'match persisted');
    assert_equals(0, $stored['payload']['live']['homeScore'], 'score stored in payload');
    assert_equals(12, $stored['payload']['live']['minute'], 'minute stored in payload');
    assert_equals('LIVE', $stored['status']);
    assert_in_array('SPORTS_LIVE_SYNC_COMPLETED', array_column($audit->rows, 'type'), 'sweep completion audited');
    assert_equals(1, $provider->liveCalls, 'one live-endpoint request per sweep');
});

test('live scores: goal scored → stored score updates + SPORTS_GOAL_SCORED audited immediately', function () {
    [$sync, $repo, $audit, $provider] = fx_live_sweep([fx_live_row(0, 0, 20)]);
    $sync->syncLive($provider, 'live-g-1');                       // baseline 0-0
    $provider->liveRows = [fx_live_row(1, 0, 23)];                // HOME scores
    $result = $sync->syncLive($provider, 'live-g-2');
    assert_equals(1, count($result['goalEvents']), 'one goal event detected');
    $event = $result['goalEvents'][0];
    assert_equals('home', $event['side'], 'the scoring side is identified');
    assert_equals(['home' => 1, 'away' => 0], $event['score']);
    assert_equals(23, $event['minute']);
    $storedRow = fx_live_find($repo, 'lv-1');
    assert_equals((int) $storedRow['id'], $event['matchId']);
    assert_in_array('SPORTS_GOAL_SCORED', array_column($audit->rows, 'type'), 'goal audited');
    assert_equals(1, fx_live_find($repo, 'lv-1')['payload']['live']['homeScore'], 'stored score updated');
});

test('live scores: unchanged score, correction and missing score never emit goals', function () {
    [$sync, $repo, , $provider] = fx_live_sweep([fx_live_row(1, 1, 55)]);
    $sync->syncLive($provider, 'live-x-1');                       // baseline 1-1
    $same = $sync->syncLive($provider, 'live-x-2');               // same score re-observed
    assert_equals([], $same['goalEvents'], 'unchanged score is not a goal');
    $provider->liveRows = [fx_live_row(0, 0, 90)];                // provider corrects downwards
    $corrected = $sync->syncLive($provider, 'live-x-3');
    assert_equals([], $corrected['goalEvents'], 'a downward correction is not a goal');
    $provider->liveRows = [fx_live_row(1, 1, 55) + ['homeScore' => null]];  // score withdrawn
    $withdrawn = $sync->syncLive($provider, 'live-x-4');
    assert_equals([], $withdrawn['goalEvents'], 'a withdrawn score cannot be a goal');
});

test('live scores: goal detection also works when the stored payload is a JSON string (SQL backend shape)', function () {
    [$sync, $repo, , $provider] = fx_live_sweep([fx_live_row(0, 0, 30)]);
    $sync->syncLive($provider, 'live-json-1');
    foreach ($repo->matches as &$m) $m['payload'] = json_encode($m['payload']);   // mirror the database row shape
    unset($m);
    $provider->liveRows = [fx_live_row(0, 2, 33)];                // AWAY scores twice in one sweep
    $result = $sync->syncLive($provider, 'live-json-2');
    assert_equals(1, count($result['goalEvents']), 'one event for the multi-goal jump');
    assert_equals(2, $result['goalEvents'][0]['delta'], 'the delta reports both goals');
    assert_equals('away', $result['goalEvents'][0]['side']);
});

test('live scores: refresh skips provider polling entirely when no match can be in play', function () {
    // Nothing stored, nothing LIVE, no kickoff near now → the gate must keep
    // the provider untouched (quota protection for idle hours).
    putenv('WINDELS_SPORTS_LIVE_REFRESH_SECONDS=10');
    $repo = new SportsRepositoryStub();
    $audit = fx_live_audit();
    $sync = new SportsSyncService($repo, $audit, new DataQualityEngine());
    $provider = fx_live_provider([fx_live_row(0, 0, 5)]);
    $manager = new SportsProviderManager();
    $manager->register($provider);
    $idle = new LiveScoreService($repo, $audit, $sync, $manager);
    $result = $idle->refresh();
    assert_equals('SKIPPED_NO_MATCHES_IN_PLAY', $result['status']);
    assert_equals(0, $provider->liveCalls, 'zero provider requests while nothing is in play');
});

test('live scores: refresh throttles to one provider sweep per interval for every consumer', function () {
    [, $service, , , $provider] = fx_live_stack([fx_live_row(0, 0, 5)], 10);
    $first = $service->refresh();
    assert_equals('COMPLETED', $first['status']);
    $callsAfterFirst = $provider->liveCalls;
    $second = $service->refresh();
    assert_equals('THROTTLED', $second['status'], 'an immediate second refresh is throttled');
    assert_equals($callsAfterFirst, $provider->liveCalls, 'no provider request while throttled');
    assert_true($second['retryInSeconds'] >= 1, 'the response says when to retry');
    // After the interval elapses the same service sweeps again — and detects the goal.
    $provider->liveRows = [fx_live_row(1, 0, 18)];
    $third = $service->refresh(time() + 11);
    assert_equals('COMPLETED', $third['status']);
    assert_equals(1, count($third['goalEvents']), 'the goal lands on the next due sweep');
    assert_equals(1, $provider->liveCalls - $callsAfterFirst, 'exactly one provider request for the due sweep');
});

test('live scores: board serves stored scores and replays goal events since a timestamp', function () {
    [, $service, , , $provider] = fx_live_stack([fx_live_row(0, 0, 1)]);
    $service->refresh();
    $provider->liveRows = [fx_live_row(2, 1, 88)];
    $result = $service->refresh(time() + 11);
    assert_equals(1, count($result['goalEvents']));
    $board = $service->board('1970-01-01T00:00:00+00:00');
    assert_equals('LIVE', $board['status']);
    assert_equals(1, count($board['matches']), 'live match listed from storage');
    $m = $board['matches'][0];
    assert_equals(2, $m['homeScore']);
    assert_equals(1, $m['awayScore']);
    assert_equals(88, $m['minute']);
    assert_true($m['scoreKnown']);
    assert_equals(1, count($board['goalEvents']), 'goal event replayed for the UI flash');
    assert_equals('GOAL', $board['goalEvents'][0]['type']);
    $empty = $service->board(gmdate('c', time() + 3600));
    assert_equals([], $empty['goalEvents'], 'nothing replayed after the newest event');
});

test('live scores: providers without a live endpoint are skipped, never guessed', function () {
    putenv('WINDELS_SPORTS_LIVE_REFRESH_SECONDS=10');
    $repo = new SportsRepositoryStub();
    $audit = fx_live_audit();
    $sync = new SportsSyncService($repo, $audit, new DataQualityEngine());
    // Seed an in-play match so the window gate lets the sweep proceed.
    $source = $repo->ensureProvider('no-live-endpoint', 'no-live-endpoint');
    $repo->saveMatch((int) $source['id'], SportsDataNormalizer::fixture([
        'externalId' => 'n-1', 'homeTeam' => 'H', 'awayTeam' => 'A', 'competition' => 'L',
        'kickoff' => gmdate('c', time() - 900), 'status' => 'LIVE',
        'minute' => 9, 'homeScore' => 0, 'awayScore' => 0, 'sourceTimestamp' => gmdate('c'),
    ], 'no-live-endpoint'));
    $manager = new SportsProviderManager();
    $manager->register(fx_live_noendpoint_provider('no-live-endpoint'));
    $service = new LiveScoreService($repo, $audit, $sync, $manager);
    $result = $service->refresh();
    assert_equals('SKIPPED', $result['status'], 'no provider could serve live data');
    assert_equals('SKIPPED', $result['providers']['no-live-endpoint']['status']);
    assert_contains('no live endpoint', (string) $result['providers']['no-live-endpoint']['reason']);
    assert_equals([], $result['goalEvents']);
});

test('live scores: sandbox provider simulates live matches honestly (labeled, in-window only)', function () {
    putenv('WINDELS_SPORTS_MODE=SANDBOX');
    putenv('WINDELS_SPORTS_SANDBOX=1');
    $sandbox = new SandboxSportsProvider();
    $rows = $sandbox->liveFixtures();
    assert_true(is_array($rows), 'live fixtures are a list (possibly empty when nothing is in play)');
    foreach ($rows as $row) {
        assert_true(!empty($row['simulated']), 'every sandbox live row is labeled simulated');
        assert_equals('LIVE', $row['status']);
        assert_true(is_int($row['homeScore']) && $row['homeScore'] >= 0, 'home score is a non-negative integer');
        assert_true(is_int($row['awayScore']) && $row['awayScore'] >= 0, 'away score is a non-negative integer');
        assert_true($row['minute'] >= 0 && $row['minute'] <= 90, 'minute inside the match window');
    }
    // Deterministic: the same instant always reports the same simulated score.
    if ($rows !== []) {
        $again = $sandbox->liveFixtures();
        assert_equals(count($rows), count($again));
        foreach ($rows as $i => $row) {
            assert_equals([$row['homeScore'], $row['awayScore'], $row['minute']],
                [$again[$i]['homeScore'], $again[$i]['awayScore'], $again[$i]['minute']],
                'repeated sweeps agree — no flickering scores');
        }
    }
    putenv('WINDELS_SPORTS_SANDBOX');
    putenv('WINDELS_SPORTS_MODE');
});

test('live scores: sandbox live provider stays offline when the simulation is not opted in', function () {
    putenv('WINDELS_SPORTS_MODE=PRODUCTION');
    putenv('WINDELS_SPORTS_SANDBOX');
    $offline = new SandboxSportsProvider();
    assert_equals('OFFLINE', $offline->health()['status']);
    try {
        $offline->liveFixtures();
        assert_true(false, 'liveFixtures must refuse outside SANDBOX mode');
    } catch (\AIWorkforce\Sports\Providers\ProviderException $e) {
        assert_equals(\AIWorkforce\Sports\Providers\ProviderException::OFFLINE, $e->status);
    }
    putenv('WINDELS_SPORTS_MODE');
});

test('live scores: dashboard exposes the stored live state for server-side rendering', function () {
    $repo = new SportsRepositoryStub();
    $source = $repo->ensureProvider('live-dash', 'Live Dash');
    $repo->saveMatch((int) $source['id'], SportsDataNormalizer::fixture(fx_live_row(2, 2, 71), 'live-dash'));
    $dash = (new SportsIntelligence($repo, fx_live_audit()))->dashboard();
    $live = $dash['todayIntelligence']['live'];
    assert_equals(1, count($live));
    assert_equals(2, $live[0]['liveState']['homeScore'], 'liveState surfaced on the dashboard row');
    assert_equals(71, $live[0]['liveState']['minute']);
});

test('live scores: live endpoint, route, console panel and cron jobs are wired', function () {
    $routes = file_get_contents(FCPATH . 'application/config/routes.php');
    assert_contains("\$route['api/sports/live'] = 'api_sports/live';", $routes, 'live board is routed');
    $api = file_get_contents(FCPATH . 'application/controllers/Api_sports.php');
    assert_contains('public function live()', $api, 'Api_sports::live exists');
    assert_contains("requirePermission('sports.view', false)", $api, 'live board is read-only (sports.view, no CSRF on GET)');
    assert_contains("'api-sync-live-'", $api, 'operator sync endpoint supports type=live');
    $view = file_get_contents(FCPATH . 'application/views/sports/index.php');
    assert_contains('id="live-scores-panel"', $view, 'console has a live scores panel');
    assert_contains("fetch('/api/sports/live?since='", $view, 'the panel polls the live endpoint');
    assert_contains('live-count-stat', $view, 'the Live stat updates with the board');
    $cron = file_get_contents(FCPATH . 'application/libraries/AIWorkforce/Cron/CronScheduler.php');
    assert_contains("'sports-live'", $cron, 'self-gated sports-live cron job registered');
    $runner = file_get_contents(FCPATH . 'application/libraries/AIWorkforce/Cron/CronRunner.php');
    assert_contains("'sports-live' => fn() => self::sportsLive(\$ci)", $runner, 'cron runner executes the live sweep');
    assert_in_array('live', \AIWorkforce\Sports\SportsCronService::JOBS, 'live is a sports-cron job');
});
