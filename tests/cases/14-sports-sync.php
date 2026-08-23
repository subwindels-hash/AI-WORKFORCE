<?php
use Aegis\Persistence\AuditRepository;
use Aegis\Persistence\SportsRepository;
use Aegis\Sports\DataQualityEngine;
use Aegis\Sports\Providers\SportsDataProvider;
use Aegis\Sports\SportsSyncService;

function fx_sports_sync(): array {
    $repo = new class implements SportsRepository {
        public array $keys = []; public array $matches = []; public array $quality = []; public array $finished = [];
        public function ensureProvider(string $code, string $name): array { return ['id' => 1, 'provider_code' => $code]; }
        public function saveHealth(int $providerId, array $health): void {}
        public function saveMatch(int $providerId, array $match): array { $id = count($this->matches) + 1; $this->matches[] = $match; return array_merge($match, ['id' => $id, 'created_at' => 'x', 'updated_at' => 'x']); }
        public function findMatch(int $providerId, string $externalId): ?array { return ['id' => 1, 'external_id' => $externalId]; }
        public function saveOdds(int $matchId, int $providerId, array $odds): void {}
        public function saveResult(int $matchId, int $providerId, array $result): void {}
        public function findResult(int $matchId, int $providerId): ?array { return null; }
        public function verifyResult(int $id): void {}
        public function saveQuality(int $matchId, array $assessment): void { $this->quality[] = $assessment; }
        public function startSync(array $run): ?array { if (isset($this->keys[$run['executionKey']])) return null; $this->keys[$run['executionKey']] = true; return $run; }
        public function finishSync(string $id, array $result): void { $this->finished[] = $result; }
        public function ensureModelVersion(array $model): int { return 1; }
        public function savePrediction(array $prediction): void {}
        public function saveTicket(array $ticket): void {}
        public function saveTicketSelection(array $selection): void {}
        public function ticketSelections(string $ticketId): array { return []; }
        public function updateTicketSelection(int $id, array $patch): void {}
        public function findTicket(string $id): ?array { return null; }
        public function updateTicket(string $id, array $patch): void {}
    };
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
