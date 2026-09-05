<?php
namespace AIWorkforce\Sports\Providers;

/**
 * Per-provider circuit breaker for the sports-data layer.
 *
 *   provider fails N times (or once with a terminal status)
 *          ↓
 *   circuit OPEN — the manager stops calling it
 *          ↓
 *   cooldown elapses → HALF_OPEN → one probe call
 *          ↓
 *   success → CLOSED · failure → OPEN again
 *
 * The cooldown depends on WHY the provider failed, because the right reaction
 * differs:
 *
 *   DAILY_QUOTA_EXHAUSTED   open until the vendor's quota reset (api-football:
 *                           00:00 UTC) — retrying earlier only burns time
 *   RATE_LIMITED            open for the vendor's Retry-After (default 60s)
 *   AUTHENTICATION_ERROR /  configuration problems: open for the config
 *   BAD_REQUEST / NOT_FOUND cooldown (default 5 min) — a retry cannot fix a
 *                           wrong key, path or parameter, an operator can
 *   anything else           counted; the circuit opens after `threshold`
 *                           consecutive failures for `cooldownSeconds`
 *
 * State is in-memory per process and can be re-hydrated from the persisted
 * provider-health history (hydrate()), so a cron run at 09:00 still knows the
 * quota died at 08:40 and does not touch the provider again before midnight.
 */
final class ProviderCircuitBreaker
{
    public const CLOSED = 'CLOSED';
    public const OPEN = 'OPEN';
    public const HALF_OPEN = 'HALF_OPEN';

    /** @var array<string,array<string,mixed>> */
    private array $circuits = [];
    /** @var callable(): int */
    private $clock;

    public function __construct(
        private int $threshold = 3,
        private int $cooldownSeconds = 600,
        private int $configCooldownSeconds = 300,
        private int $rateLimitCooldownSeconds = 60,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    /** May the manager call this provider right now? (OPEN → no; HALF_OPEN → one probe.) */
    public function canCall(string $providerId): bool
    {
        $state = $this->state($providerId);
        return $state['state'] !== self::OPEN;
    }

    /** @return array{state:string, reason:?string, detail:?string, failures:int, openedAt:?string, retryAt:?string, lastFailureAt:?string, lastSuccessAt:?string} */
    public function state(string $providerId): array
    {
        $c = $this->circuits[$providerId] ?? $this->fresh();
        if ($c['state'] === self::OPEN && $c['retryAtTs'] !== null && $this->now() >= $c['retryAtTs']) {
            $c['state'] = self::HALF_OPEN;
            $this->circuits[$providerId] = $c;
        }
        return [
            'state' => $c['state'],
            'reason' => $c['reason'],
            'detail' => $c['detail'],
            'failures' => $c['failures'],
            'openedAt' => $c['openedAtTs'] !== null ? gmdate('c', $c['openedAtTs']) : null,
            'retryAt' => $c['retryAtTs'] !== null ? gmdate('c', $c['retryAtTs']) : null,
            'lastFailureAt' => $c['lastFailureTs'] !== null ? gmdate('c', $c['lastFailureTs']) : null,
            'lastSuccessAt' => $c['lastSuccessTs'] !== null ? gmdate('c', $c['lastSuccessTs']) : null,
        ];
    }

    public function recordSuccess(string $providerId): void
    {
        $c = $this->circuits[$providerId] ?? $this->fresh();
        $c['state'] = self::CLOSED;
        $c['failures'] = 0;
        $c['reason'] = null;
        $c['detail'] = null;
        $c['openedAtTs'] = null;
        $c['retryAtTs'] = null;
        $c['lastSuccessTs'] = $this->now();
        $this->circuits[$providerId] = $c;
    }

    /**
     * Record a classified failure. Terminal statuses open the circuit at once
     * with a status-specific cooldown; generic failures count towards the
     * threshold. A failure while HALF_OPEN re-opens immediately.
     */
    public function recordFailure(string $providerId, ProviderException $error): void
    {
        $now = $this->now();
        $c = $this->circuits[$providerId] ?? $this->fresh();
        $c['failures']++;
        $c['lastFailureTs'] = $now;
        $status = $error->status;
        $detail = mb_substr($error->getMessage(), 0, 200);

        $retryAt = null;
        if ($status === ProviderException::DAILY_QUOTA_EXHAUSTED) {
            $retryAt = $this->parseTs($error->details['retryAt'] ?? null) ?? ProviderHttp::nextUtcMidnight($now);
        } elseif ($status === ProviderException::RATE_LIMITED) {
            $after = (int) ($error->details['retryAfterSeconds'] ?? $this->rateLimitCooldownSeconds);
            $retryAt = $now + max(1, $after);
        } elseif ($error->isConfigurationError()) {
            $retryAt = $now + $this->configCooldownSeconds;
        } elseif ($c['state'] === self::HALF_OPEN || $c['failures'] >= $this->threshold) {
            $retryAt = $now + $this->cooldownSeconds;
        }

        if ($retryAt !== null) {
            $c['state'] = self::OPEN;
            $c['openedAtTs'] = $now;
            $c['retryAtTs'] = $retryAt;
            $c['reason'] = $status;
            $c['detail'] = $detail;
        } else {
            $c['reason'] = $status;
            $c['detail'] = $detail;
        }
        $this->circuits[$providerId] = $c;
    }

    /**
     * Re-open circuits from persisted health observations (newest row per
     * provider). Only statuses whose cooldown is still running are restored;
     * everything else starts CLOSED so a recovered provider is tried again.
     *
     * @param array<string,array<string,mixed>> $latestByProviderId providerCode → sports_provider_health row
     */
    public function hydrate(array $latestByProviderId): void
    {
        $now = $this->now();
        foreach ($latestByProviderId as $providerId => $row) {
            if (!is_array($row)) continue;
            $status = (string) ($row['status'] ?? '');
            $observed = $this->parseTs($row['observed_at'] ?? $row['observedAt'] ?? null);
            if ($observed === null || $observed > $now) continue;
            $retryAt = match ($status) {
                ProviderException::DAILY_QUOTA_EXHAUSTED => ProviderHttp::nextUtcMidnight($observed),
                ProviderException::RATE_LIMITED => $observed + $this->rateLimitCooldownSeconds,
                ProviderException::AUTHENTICATION_ERROR, ProviderException::BAD_REQUEST, ProviderException::NOT_FOUND => $observed + $this->configCooldownSeconds,
                default => null,
            };
            if ($retryAt === null || $retryAt <= $now) continue;
            $c = $this->fresh();
            $c['state'] = self::OPEN;
            $c['failures'] = 1;
            $c['reason'] = $status;
            $c['detail'] = 'restored from last observation at ' . gmdate('c', $observed);
            $c['openedAtTs'] = $observed;
            $c['retryAtTs'] = $retryAt;
            $c['lastFailureTs'] = $observed;
            $this->circuits[(string) $providerId] = $c;
        }
    }

    /** Force a circuit closed (operator "retry now"). */
    public function reset(string $providerId): void
    {
        unset($this->circuits[$providerId]);
    }

    /** @return array<string,array<string,mixed>> */
    public function snapshot(): array
    {
        $out = [];
        foreach (array_keys($this->circuits) as $id) $out[$id] = $this->state($id);
        return $out;
    }

    private function fresh(): array
    {
        return ['state' => self::CLOSED, 'failures' => 0, 'reason' => null, 'detail' => null, 'openedAtTs' => null, 'retryAtTs' => null, 'lastFailureTs' => null, 'lastSuccessTs' => null];
    }

    private function now(): int
    {
        return (int) ($this->clock)();
    }

    private function parseTs(mixed $value): ?int
    {
        if ($value === null || $value === '') return null;
        if (is_int($value)) return $value;
        try { return (new \DateTimeImmutable((string) $value))->getTimestamp(); }
        catch (\Throwable $e) { return null; }
    }
}
