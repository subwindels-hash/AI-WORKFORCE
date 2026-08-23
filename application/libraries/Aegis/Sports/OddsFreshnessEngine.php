<?php
namespace Aegis\Sports;

class OddsFreshnessEngine
{
    public function assess(?array $odds, int $maxAgeSeconds = 900, ?int $now = null): array
    {
        if ($odds === null) return ['available' => false, 'fresh' => false, 'ageSeconds' => null, 'score' => 0, 'reason' => 'ODDS_UNAVAILABLE'];
        try { $at = (new \DateTimeImmutable((string) ($odds['observedAt'] ?? '')))->getTimestamp(); }
        catch (\Throwable $e) { return ['available' => true, 'fresh' => false, 'ageSeconds' => null, 'score' => 0, 'reason' => 'ODDS_TIMESTAMP_INVALID']; }
        $age = max(0, ($now ?? time()) - $at); $fresh = $age <= $maxAgeSeconds;
        return ['available' => true, 'fresh' => $fresh, 'ageSeconds' => $age, 'score' => $fresh ? (int) round(100 * (1 - $age / max(1, $maxAgeSeconds))) : 0, 'reason' => $fresh ? null : 'STALE_ODDS'];
    }
}
