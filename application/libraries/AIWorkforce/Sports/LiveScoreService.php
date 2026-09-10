<?php
namespace AIWorkforce\Sports;

use AIWorkforce\Persistence\AuditRepository;
use AIWorkforce\Persistence\SportsRepository;
use AIWorkforce\Sports\Providers\SportsProviderManager;

/**
 * Auto-updating live scores for Sports Intelligence.
 *
 * One throttled sweep serves every consumer: the console's live board polling
 * GET /api/sports/live, the `sports-live` cron tick and
 * `php index.php tools sports-cron live` all funnel into refresh(), which
 * touches a provider at most ONCE per WINDELS_SPORTS_LIVE_REFRESH_SECONDS
 * (default 60) no matter how many browsers are open — between sweeps every
 * consumer reads the stored state. So a goal appears on the board
 * automatically, at worst one refresh interval after the provider reported it.
 * When no stored match can be in play (nothing LIVE, no kickoff in the last
 * 3 h / next 10 min) the sweep is skipped outright: zero provider requests,
 * so an idle clock never eats a small daily quota.
 *
 * Goal detection lives in SportsSyncService::syncLive(): every sweep that sees
 * a higher total score than the stored one audits a SPORTS_GOAL_SCORED event;
 * goalEventsSince() replays those events so the UI can flash the new score
 * immediately after it happened (and nothing is fabricated — a match the
 * provider gave no score for shows "—", never 0-0).
 */
class LiveScoreService
{
    public const GOAL_EVENT = 'SPORTS_GOAL_SCORED';
    public const DEFAULT_REFRESH_SECONDS = 60;
    public const MIN_REFRESH_SECONDS = 10;
    public const MAX_REFRESH_SECONDS = 86400;
    /** Canonical statuses that mean the match is currently in progress. */
    public const LIVE_STATUSES = ['LIVE', 'HALFTIME', 'EXTRA_TIME', 'PENALTIES'];

    public function __construct(
        private SportsRepository $repo,
        private AuditRepository $audit,
        private SportsSyncService $sync,
        private SportsProviderManager $providers
    ) {}

    /**
     * Provider poll interval in seconds, from WINDELS_SPORTS_LIVE_REFRESH_SECONDS,
     * clamped to [MIN, MAX]. This is the worst-case delay between a real goal
     * and the board updating — lower it for faster updates on a paid feed,
     * raise it to stretch a small daily quota.
     */
    public function refreshIntervalSeconds(): int
    {
        $raw = (int) (getenv('WINDELS_SPORTS_LIVE_REFRESH_SECONDS') ?: 0);
        if ($raw <= 0) $raw = self::DEFAULT_REFRESH_SECONDS;
        return max(self::MIN_REFRESH_SECONDS, min(self::MAX_REFRESH_SECONDS, $raw));
    }

    /**
     * How long a stored live match may remain on the board without a fresh
     * provider confirmation before it is considered stale and hidden.
     * Three poll intervals, clamped to 5-10 minutes so a low interval does not
     * hide a match too eagerly and a high one does not retain stale data.
     */
    public function staleThresholdSeconds(): int
    {
        $interval = $this->refreshIntervalSeconds();
        return max(300, min(600, $interval * 3));
    }

    /**
     * Throttled live sweep across every configured provider that exposes a
     * live endpoint (liveFixtures). Returns the per-provider outcome plus
     * every goal event this sweep detected.
     *
     * Outcome statuses:
     *  - SKIPPED_NO_MATCHES_IN_PLAY — no stored match can currently be on the
     *    pitch (nothing LIVE, no kickoff in the last 3 h / next 10 min), so
     *    the sweep spends ZERO provider requests: an idle clock never eats a
     *    small daily quota;
     *  - THROTTLED — stored state is younger than the refresh interval; serve
     *    the board from storage. Concurrent callers inside one interval
     *    bucket are deduplicated by the sweep's execution key, so a page full
     *    of viewers costs one provider request per interval — not one each;
     *  - COMPLETED / PARTIAL / FAILED / SKIPPED / NO_PROVIDER — the sweep
     *    itself (per-provider detail in `providers`).
     */
    public function refresh(?int $now = null): array
    {
        $now = $now ?? time();
        $interval = $this->refreshIntervalSeconds();
        if ($this->providers->all() === []) {
            return ['status' => 'NO_PROVIDER', 'providers' => [], 'goalEvents' => [], 'errors' => [],
                'retryInSeconds' => $interval, 'refreshIntervalSeconds' => $interval];
        }
        if (!$this->matchWindowOpen($now)) {
            return ['status' => 'SKIPPED_NO_MATCHES_IN_PLAY', 'providers' => [], 'goalEvents' => [], 'errors' => [],
                'retryInSeconds' => 60, 'refreshIntervalSeconds' => $interval];
        }
        $last = $this->repo->listSyncRuns('LIVE', 1)[0] ?? null;
        if ($last !== null && ($last['status'] ?? '') !== 'FAILED') {
            $at = strtotime((string) ($last['started_at'] ?? ''));
            if ($at !== false) {
                $age = max(0, $now - $at);
                if ($age < $interval) {
                    return ['status' => 'THROTTLED', 'retryInSeconds' => max(1, $interval - $age),
                        'providers' => [], 'goalEvents' => [], 'errors' => [], 'refreshIntervalSeconds' => $interval];
                }
            }
        }
        $bucket = intdiv($now, $interval);
        $providers = []; $goalEvents = []; $errors = [];
        $attempted = 0; $completed = 0; $failed = 0;
        foreach ($this->providers->all() as $provider) {
            if (!method_exists($provider, 'liveFixtures')) {
                $providers[$provider->id()] = ['status' => 'SKIPPED', 'reason' => 'provider has no live endpoint', 'processed' => 0];
                continue;
            }
            $attempted++;
            $result = $this->sync->syncLive($provider, 'live:' . $provider->id() . ':' . $bucket);
            $status = (string) ($result['status'] ?? 'FAILED');
            $providers[$provider->id()] = ['status' => $status, 'processed' => (int) ($result['processed'] ?? 0), 'errors' => array_slice((array) ($result['errors'] ?? []), 0, 3)];
            if ($status === 'DUPLICATE_SKIPPED') { $attempted--; continue; }   // a concurrent caller is sweeping right now
            if ($status === 'COMPLETED') $completed++; else $failed++;
            foreach ((array) ($result['goalEvents'] ?? []) as $event) $goalEvents[] = $event;
            foreach ((array) ($result['errors'] ?? []) as $err) $errors[] = $provider->id() . ': ' . (string) $err;
        }
        $status = match (true) {
            $attempted === 0 && $providers === [] => 'NO_PROVIDER',
            $completed > 0 && $failed === 0 => 'COMPLETED',
            $completed > 0 => 'PARTIAL',
            $failed > 0 => 'FAILED',
            default => 'SKIPPED',   // every provider lacked a live endpoint or a concurrent sweep owns this bucket
        };
        return ['status' => $status, 'providers' => $providers, 'goalEvents' => $goalEvents, 'errors' => $errors,
            'processed' => array_sum(array_map(fn($p) => (int) ($p['processed'] ?? 0), $providers)),
            'syncedAt' => gmdate('c', $now), 'refreshIntervalSeconds' => $interval];
    }

    /**
     * True when a stored match could currently be on the pitch: any live
     * status (LIVE / HALFTIME / EXTRA_TIME / PENALTIES) that was confirmed
     * recently, or a kickoff within the last 3 h / next 10 min. The live
     * check respects staleness so an abandoned feed does not keep the window
     * open forever on stale rows.
     */
    private function matchWindowOpen(int $now): bool
    {
        $cutoff = $now - $this->staleThresholdSeconds();
        foreach ($this->repo->listMatches(['status' => self::LIVE_STATUSES], 200) as $row) {
            $updated = strtotime((string) ($row['updated_at'] ?? ''));
            if ($updated !== false && $updated >= $cutoff) return true;
            // Legacy rows without updated_at: fall back to kickoff window
            // instead of falsely keeping the sweep alive.
        }
        $from = gmdate('c', $now - 10800);
        $to = gmdate('c', $now + 600);
        return $this->repo->listMatches(['from' => $from, 'to' => $to], 1) !== [];
    }

    /**
     * The stored live board — no provider call. Only matches whose canonical
     * status is in LIVE_STATUSES (LIVE, HALFTIME, EXTRA_TIME, PENALTIES) and
     * whose last provider confirmation is recent are returned — finished
     * (FT/AET/PEN/ENDED/CANCELLED/POSTPONED) and stale rows are never shown
     * as live, and live is never inferred from kickoff time. A null score
     * stays null: the reader shows "—" rather than guessing 0-0.
     */
    public function board(?string $since = null, int $limit = 50, ?int $now = null): array
    {
        $now = $now ?? time();
        $threshold = $this->staleThresholdSeconds();
        $cutoff = $now - $threshold;
        $matches = [];
        foreach ($this->repo->listMatches(['status' => self::LIVE_STATUSES], min(200, max(1, $limit))) as $row) {
            $status = strtoupper((string) ($row['status'] ?? ''));
            if (!in_array($status, self::LIVE_STATUSES, true)) continue;
            $updatedAt = $row['updated_at'] ?? null;
            $updatedTs = $updatedAt !== null ? strtotime((string) $updatedAt) : false;
            if ($updatedTs === false) {
                // No timestamp to prove recency — treat as stale.
                continue;
            }
            if ($now - $updatedTs > $threshold) {
                continue;
            }
            $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
            $live = is_array($payload['live'] ?? null) ? $payload['live'] : [];
            $matches[] = [
                'id' => (int) ($row['id'] ?? 0),
                'homeTeam' => (string) ($row['home_team'] ?? '?'),
                'awayTeam' => (string) ($row['away_team'] ?? '?'),
                'competition' => (string) ($row['competition'] ?? ''),
                'kickoff' => $row['kickoff_at'] ?? null,
                'minute' => $live['minute'] ?? null,
                'extraMinute' => $live['extraMinute'] ?? null,
                'homeScore' => $live['homeScore'] ?? null,
                'awayScore' => $live['awayScore'] ?? null,
                'scoreKnown' => isset($live['homeScore'], $live['awayScore']),
                'statusDetail' => $live['statusShort'] ?? null,
                'status' => $status,
                'simulated' => !empty($payload['simulated']),
                'updatedAt' => $row['updated_at'] ?? null,
            ];
        }
        return [
            'status' => $matches === [] ? 'NO_LIVE_FIXTURES' : 'LIVE',
            'matches' => $matches,
            'goalEvents' => $since !== null ? $this->goalEventsSince($since) : [],
            'refreshIntervalSeconds' => $this->refreshIntervalSeconds(),
            'staleThresholdSeconds' => $threshold,
        ];
    }

    /**
     * Goal events audited at or after $since, newest first. Events at exactly
     * $since are included so a client replaying from its last-seen event
     * timestamp never misses one; it dedupes by identity (matchId + score).
     */
    public function goalEventsSince(string $since, int $limit = 20): array
    {
        $cut = strtotime($since);
        if ($cut === false) return [];
        $out = [];
        foreach ($this->audit->recent(400) as $event) {
            if (($event['type'] ?? '') !== self::GOAL_EVENT) continue;
            $at = strtotime((string) ($event['at'] ?? ''));
            if ($at === false || $at < $cut) continue;
            $detail = is_array($event['detail'] ?? null) ? $event['detail'] : [];
            $out[] = ['occurredAt' => (string) $event['at'], 'summary' => (string) ($event['summary'] ?? '')] + $detail;
            if (count($out) >= max(1, $limit)) break;
        }
        return $out;
    }
}
