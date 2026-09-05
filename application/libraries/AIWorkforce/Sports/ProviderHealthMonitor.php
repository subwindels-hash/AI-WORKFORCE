<?php
namespace AIWorkforce\Sports;

/**
 * Provider Health Monitor (spec §6).
 *
 * Derives the provider status from OBSERVED data only — health observations,
 * sync-run history, and recency — and computes a 0..1 reliability score used
 * by the Data Quality Engine. Never invents a status: with no observations
 * the status is UNKNOWN.
 *
 * Statuses: ONLINE | DEGRADED | OFFLINE | TIMEOUT | RATE_LIMITED |
 *           DAILY_QUOTA_EXHAUSTED | AUTHENTICATION_ERROR | BAD_REQUEST |
 *           NOT_FOUND | DATA_ERROR | UNKNOWN
 *
 * Terminal statuses keep their own name (they are not "degraded", they are
 * specific, actionable conditions) for as long as their cooldown runs:
 * quota exhaustion until the vendor's daily reset, configuration errors
 * (auth / 400 / 404) for the configuration window, throttling for a minute.
 */
class ProviderHealthMonitor
{
    public const STALE_AFTER_SECONDS = 6 * 3600;
    public const DEGRADED_ERROR_RATE = 0.5;
    public const CONFIG_ERROR_WINDOW = 1800;
    public const RATE_LIMIT_WINDOW = 120;

    public const FAILURE_STATUSES = ['OFFLINE', 'TIMEOUT', 'DATA_ERROR', 'RATE_LIMITED', 'DAILY_QUOTA_EXHAUSTED', 'AUTHENTICATION_ERROR', 'BAD_REQUEST', 'NOT_FOUND', 'DEGRADED'];
    /** Statuses whose name survives the "recent failure → DEGRADED" collapse. */
    public const TERMINAL_STATUSES = ['RATE_LIMITED', 'DAILY_QUOTA_EXHAUSTED', 'AUTHENTICATION_ERROR', 'BAD_REQUEST', 'NOT_FOUND'];

    /** True while a terminal status is still authoritative (its cooldown has not elapsed). */
    private function terminalStillActive(string $status, ?string $observedAt, int $now): bool
    {
        $at = $this->ts($observedAt);
        if ($at === null) return false;
        return match ($status) {
            'DAILY_QUOTA_EXHAUSTED' => $now < \AIWorkforce\Sports\Providers\ProviderHttp::nextUtcMidnight($at),
            'RATE_LIMITED' => ($now - $at) <= self::RATE_LIMIT_WINDOW,
            'AUTHENTICATION_ERROR', 'BAD_REQUEST', 'NOT_FOUND' => ($now - $at) <= self::CONFIG_ERROR_WINDOW,
            default => false,
        };
    }

    /**
     * @param array  $provider   sports_data_sources row
     * @param array  $health     newest-first sports_provider_health rows
     * @param array  $jobRuns    newest-first sports_sync_runs rows for this provider
     * @param int|null $now
     * @return array{status:string, reliability:float, detail:string, checkedAt:string}
     */
    public function assess(array $provider, array $health, array $jobRuns, ?int $now = null): array
    {
        $now ??= time();
        if (!$health && !$jobRuns) return ['status' => 'UNKNOWN', 'reliability' => 0.0, 'detail' => 'no observations yet', 'checkedAt' => gmdate('c', $now)];

        $latest = $health[0] ?? null;
        $lastFailure = null;
        foreach ($health as $h) {
            $at = $this->ts($h['observed_at'] ?? null);
            if ($at !== null && $at <= $now && in_array($h['status'], self::FAILURE_STATUSES, true)) { $lastFailure = ['at' => $at, 'status' => $h['status']]; break; }
        }

        // Terminal statuses from the provider's own last report win for as long
        // as their cooldown runs: a quota-dead feed stays DAILY_QUOTA_EXHAUSTED
        // until 00:00 UTC, a 404 stays NOT_FOUND until someone fixes the URL.
        if ($latest !== null && in_array($latest['status'], self::TERMINAL_STATUSES, true) && $this->terminalStillActive((string) $latest['status'], $latest['observed_at'] ?? null, $now)) {
            $detail = 'last provider report: ' . $latest['status'];
            if ($latest['status'] === 'DAILY_QUOTA_EXHAUSTED') $detail .= ' — resets ' . gmdate('c', \AIWorkforce\Sports\Providers\ProviderHttp::nextUtcMidnight($this->ts($latest['observed_at']) ?? $now));
            elseif (in_array($latest['status'], ['BAD_REQUEST', 'NOT_FOUND', 'AUTHENTICATION_ERROR'], true)) $detail .= ' — configuration problem, retrying will not help';
            return ['status' => $latest['status'], 'reliability' => $this->reliability($jobRuns), 'detail' => $detail, 'checkedAt' => gmdate('c', $now)];
        }

        $successRuns = 0; $failRuns = 0; $lastSync = null;
        foreach ($jobRuns as $r) {
            $at = $this->ts($r['started_at'] ?? null);
            if ($at !== null && $at <= $now && $lastSync === null) $lastSync = $at;
            if (($r['status'] ?? '') === 'COMPLETED') $successRuns++;
            elseif (($r['status'] ?? '') === 'FAILED') $failRuns++;
        }
        $total = $successRuns + $failRuns;
        $errorRate = $total > 0 ? $failRuns / $total : null;

        if ($lastFailure !== null && ($now - $lastFailure['at']) <= 300) {
            $status = in_array($lastFailure['status'], self::TERMINAL_STATUSES, true) && $this->terminalStillActive($lastFailure['status'], gmdate('c', $lastFailure['at']), $now) ? $lastFailure['status'] : 'DEGRADED';
            return ['status' => $status, 'reliability' => $this->reliability($jobRuns), 'detail' => 'failing since ' . gmdate('c', $lastFailure['at']) . ' (' . $lastFailure['status'] . ')', 'checkedAt' => gmdate('c', $now)];
        }
        if ($latest !== null && ($now - $this->ts($latest['observed_at']) > self::STALE_AFTER_SECONDS || $this->ts($latest['observed_at']) === null) && ($lastSync === null || ($now - $lastSync) > self::STALE_AFTER_SECONDS)) {
            return ['status' => 'OFFLINE', 'reliability' => $this->reliability($jobRuns), 'detail' => 'no successful observation within ' . self::STALE_AFTER_SECONDS . 's', 'checkedAt' => gmdate('c', $now)];
        }
        if ($errorRate !== null && $errorRate >= self::DEGRADED_ERROR_RATE) {
            return ['status' => 'DEGRADED', 'reliability' => $this->reliability($jobRuns), 'detail' => sprintf('error rate %.0f%% over %d runs', $errorRate * 100, $total), 'checkedAt' => gmdate('c', $now)];
        }
        if ($latest !== null && ($latest['status'] ?? '') === 'ONLINE') {
            return ['status' => 'ONLINE', 'reliability' => $this->reliability($jobRuns, true), 'detail' => 'last observation ONLINE', 'checkedAt' => gmdate('c', $now)];
        }
        return ['status' => 'DEGRADED', 'reliability' => $this->reliability($jobRuns), 'detail' => 'no recent ONLINE observation', 'checkedAt' => gmdate('c', $now)];
    }

    /** 0..1 reliability from run history (with a recency boost for fresh success). */
    private function reliability(array $jobRuns, bool $boost = false): float
    {
        $success = 0; $total = 0;
        foreach ($jobRuns as $r) {
            if (($r['status'] ?? '') === 'COMPLETED') { $success++; $total++; }
            elseif (($r['status'] ?? '') === 'FAILED') $total++;
        }
        if ($total === 0) return $boost ? 0.5 : 0.0;
        return round(min(1.0, ($success / $total) * ($boost ? 1.0 : 0.95)), 4);
    }

    private function ts(?string $value): ?int
    {
        if (!$value) return null;
        try { return (new \DateTimeImmutable((string) $value))->getTimestamp(); }
        catch (\Throwable $e) { return null; }
    }

    private function recent(?string $value, int $now, int $window): bool
    {
        $at = $this->ts($value);
        return $at !== null && ($now - $at) <= $window;
    }
}
