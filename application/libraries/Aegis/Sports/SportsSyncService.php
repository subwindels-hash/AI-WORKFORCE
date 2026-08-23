<?php
namespace Aegis\Sports;

use Aegis\Backtest\Backtester;
use Aegis\Persistence\AuditRepository;
use Aegis\Persistence\SportsRepository;
use Aegis\Sports\Providers\SportsDataProvider;

/** Idempotent fixture ingestion. Provider exceptions and malformed records are counted, never hidden. */
class SportsSyncService
{
    public function __construct(private SportsRepository $repo, private AuditRepository $audit, private DataQualityEngine $quality) {}

    public function syncFixtures(SportsDataProvider $provider, array $query, string $executionKey): array
    {
        $source = $this->repo->ensureProvider($provider->id(), $provider->id());
        $run = ['id' => Backtester::uuid(), 'providerId' => (int) $source['id'], 'jobType' => 'FIXTURES', 'executionKey' => $executionKey];
        if ($this->repo->startSync($run) === null) return ['status' => 'DUPLICATE_SKIPPED', 'executionKey' => $executionKey];
        $created = 0; $updated = 0; $invalid = 0; $processed = 0; $errors = [];
        try {
            $health = $provider->health();
            $this->repo->saveHealth((int) $source['id'], array_merge($health, ['status' => $health['status'] ?? 'DATA_ERROR']));
            if (($health['status'] ?? '') !== 'ONLINE') throw new \RuntimeException('provider is not ONLINE');
            foreach ($provider->fixtures($query) as $raw) {
                $processed++;
                try {
                    $match = SportsDataNormalizer::fixture($raw, $provider->id());
                    $existing = $this->repo->saveMatch((int) $source['id'], $match);
                    // Repository upserts; source payload decides whether this was logically created/updated.
                    !empty($existing['created_at']) && $existing['created_at'] === $existing['updated_at'] ? $created++ : $updated++;
                    $assessment = $this->quality->assess($match, ['oddsAvailable' => false, 'recentFormAvailable' => false, 'providerReliability' => (float) ($health['reliability'] ?? 0), 'dataAgeSeconds' => 0]);
                    $this->repo->saveQuality((int) $existing['id'], $assessment);
                } catch (\Throwable $e) { $invalid++; $errors[] = mb_substr($e->getMessage(), 0, 200); }
            }
            $result = ['status' => 'COMPLETED', 'processed' => $processed, 'created' => $created, 'updated' => $updated, 'errors' => $errors];
        } catch (\Throwable $e) {
            $result = ['status' => 'FAILED', 'processed' => $processed, 'created' => $created, 'updated' => $updated, 'errors' => [mb_substr($e->getMessage(), 0, 200)]];
        }
        $this->repo->finishSync($run['id'], $result);
        $this->audit->emit($result['status'] === 'COMPLETED' ? 'SPORTS_FIXTURE_SYNC_COMPLETED' : 'SPORTS_FIXTURE_SYNC_FAILED', 'Sports fixture sync ' . strtolower($result['status']), ['provider' => $provider->id(), 'runId' => $run['id'], 'result' => $result]);
        return array_merge(['runId' => $run['id']], $result);
    }
}
