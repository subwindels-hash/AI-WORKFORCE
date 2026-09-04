<?php
use AIWorkforce\Persistence\AuditRepository;
use AIWorkforce\Persistence\SportsRepository;
use AIWorkforce\Sports\DataQualityEngine;
use AIWorkforce\Sports\Providers\SportsDataProvider;
use AIWorkforce\Sports\SportsSyncService;

function fx_sports_sync(): array {
    $repo = new SportsRepositoryStub();
    $audit = new class implements AuditRepository { public array $events = []; public function emit(string $type, string $summary, array $detail = [], string $actor = 'system'): void { $this->events[] = $type; } public function recent(int $limit = 100): array { return []; } };
    return [new SportsSyncService($repo, $audit, new DataQualityEngine()), $repo, $audit];
}
function fx_sports_provider(array $fixtures, string $status = 'ONLINE'): SportsDataProvider { return new class($fixtures, $status) implements SportsDataProvider { public function __construct(private array $items, private string $state) {} public function id(): string { return 'test-sports'; } public function health(): array { return ['status' => $this->state, 'reliability' => .9]; } public function fixtures(array $query): array { return $this->items; } public function odds(string $fixtureExternalId): array { return []; } public function results(string $fixtureExternalId): array { return []; } }; }
test('sports fixture sync is idempotent and audits completion', function () {
    [$sync, $repo, $audit] = fx_sports_sync(); $p = fx_sports_provider([['externalId' => 'x', 'homeTeam' => 'H', 'awayTeam' => 'A', 'competition' => 'L', 'kickoff' => '2026-09-01T12:00:00Z']]);
    $first = $sync->syncFixtures($p, [], 'key-1'); assert_equals('COMPLETED', $first['status']); assert_equals(1, count($repo->matches)); assert_equals('SPORTS_FIXTURE_SYNC_COMPLETED', $audit->events[0]);
    assert_equals('DUPLICATE_SKIPPED', $sync->syncFixtures($p, [], 'key-1')['status']);
});
test('sports fixture sync counts invalid records rather than persisting them', function () {
    [$sync, $repo] = fx_sports_sync(); $result = $sync->syncFixtures(fx_sports_provider([['externalId' => 'bad']]), [], 'key-2');
    assert_equals('COMPLETED', $result['status']); assert_equals(1, count($result['errors'])); assert_equals(0, count($repo->matches));
});

/** Round-capable provider mock: counts provider calls so tests can prove the bulk path was used. */
function fx_sports_round_provider(array $round, string $status = 'ONLINE'): SportsDataProvider
{
    return new class($round, $status) implements SportsDataProvider {
        public int $roundCalls = 0;
        public int $oddsCalls = 0;
        public int $resultsCalls = 0;
        public function __construct(private array $round, private string $state) {}
        public function id(): string { return 'test-round'; }
        public function health(): array { return ['status' => $this->state, 'reliability' => .9]; }
        public function fixtures(array $query): array { return $this->round['fixtures'] ?? []; }
        public function round(string $roundId): array { $this->roundCalls++; return $this->round; }
        public function odds(string $fixtureExternalId): array { $this->oddsCalls++; return []; }
        public function results(string $fixtureExternalId): array { $this->resultsCalls++; return []; }
    };
}

function fx_sports_round(): array
{
    $odds = fn(string $ext, float $over) => [
        ['market' => 'TOTAL_GOALS', 'selection' => 'OVER_1_5', 'decimalOdds' => $over, 'observedAt' => gmdate('c'), 'bookmaker' => 'test', 'fixtureId' => $ext],
        ['market' => 'MATCH_RESULT', 'selection' => 'HOME', 'decimalOdds' => 2.0, 'observedAt' => gmdate('c'), 'bookmaker' => 'test', 'fixtureId' => $ext],
    ];
    return [
        'roundId' => 'r1', 'name' => '1', 'leagueId' => '1', 'league' => 'Test League', 'season' => '2026',
        'startingAt' => null, 'endingAt' => null, 'finished' => true,
        'fixtures' => [
            ['externalId' => 'a', 'homeTeam' => 'HA', 'awayTeam' => 'AA', 'competition' => 'Test League', 'kickoff' => '2026-08-30T19:00:00Z', 'status' => 'FINISHED'],
            ['externalId' => 'b', 'homeTeam' => 'HB', 'awayTeam' => 'AB', 'competition' => 'Test League', 'kickoff' => '2026-08-30T21:30:00Z', 'status' => 'FINISHED'],
        ],
        'odds' => array_merge($odds('a', 1.55), $odds('b', 1.75)),
        'results' => [
            ['externalId' => 'a', 'status' => 'FINISHED', 'homeScore' => 2, 'awayScore' => 1, 'halfTimeHome' => 1, 'halfTimeAway' => 1, 'sourceTimestamp' => gmdate('c')],
            ['externalId' => 'b', 'status' => 'FINISHED', 'homeScore' => 0, 'awayScore' => 0, 'halfTimeHome' => 0, 'halfTimeAway' => 0, 'sourceTimestamp' => gmdate('c')],
        ],
    ];
}

test('sports round sync bulk-saves fixtures, odds and results in one provider call', function () {
    [$sync, $repo, $audit] = fx_sports_sync();
    $p = fx_sports_round_provider(fx_sports_round());
    $result = $sync->syncRound($p, 'r1', 'round-key-1');
    assert_equals('COMPLETED', $result['status']);
    assert_equals(2, $result['processed']);
    assert_equals(2, count($repo->matches), 'both fixtures persisted');
    assert_equals(4, count($repo->odds), 'odds persisted from the same round call');
    assert_equals(2, count($repo->results), 'results persisted from the same round call');
    assert_equals(1, $p->roundCalls, 'exactly one provider round() call');
    assert_equals(0, $p->oddsCalls, 'no per-fixture odds() calls');
    assert_equals(0, $p->resultsCalls, 'no per-fixture results() calls');
    $auditTypes = array_values($audit->events);
    assert_true(in_array('SPORTS_ROUND_SYNC_COMPLETED', $auditTypes), 'round sync completion audited');
});

test('sports round sync is idempotent per execution key', function () {
    [$sync, $repo] = fx_sports_sync();
    $p = fx_sports_round_provider(fx_sports_round());
    assert_equals('COMPLETED', $sync->syncRound($p, 'r1', 'round-key-2')['status']);
    assert_equals('DUPLICATE_SKIPPED', $sync->syncRound($p, 'r1', 'round-key-2')['status']);
    assert_equals(1, $p->roundCalls, 'duplicate run does not re-fetch the round');
});

test('sports round sync fails clearly when the provider has no round endpoint', function () {
    [$sync, $repo] = fx_sports_sync();
    $p = fx_sports_provider([['externalId' => 'x', 'homeTeam' => 'H', 'awayTeam' => 'A', 'competition' => 'L', 'kickoff' => '2026-09-01T12:00:00Z']]);
    $result = $sync->syncRound($p, 'r1', 'round-key-3');
    assert_equals('FAILED', $result['status']);
    assert_contains('does not support round sync', implode('; ', $result['errors']));
    assert_equals(0, count($repo->matches));
});

test('sports round sync fails when the provider is not ONLINE', function () {
    [$sync, $repo] = fx_sports_sync();
    $p = fx_sports_round_provider(fx_sports_round(), 'OFFLINE');
    $result = $sync->syncRound($p, 'r1', 'round-key-4');
    assert_equals('FAILED', $result['status']);
    assert_contains('provider is not ONLINE', implode('; ', $result['errors']));
    assert_equals(0, $p->roundCalls, 'no round fetch for an offline provider');
});
