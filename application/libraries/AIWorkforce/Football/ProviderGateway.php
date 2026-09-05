<?php
namespace AIWorkforce\Football;

use AIWorkforce\Sports\Providers\ProviderException;
use AIWorkforce\Sports\Providers\SportsDataProvider;
use AIWorkforce\Sports\Providers\SportsProviderManager;

/**
 * Provider-aware access layer for the football module.
 *
 * It reuses the SPORTS provider registry (one credential layer, one health
 * history) and adds what football needs on top of it:
 *
 *  - a capability map, so a feature is only asked of a provider that can serve
 *    it (an api-football host with /fixtures/headtohead vs TheSportsDB without);
 *  - a per-sweep request budget and a rate-limit backoff, so "refresh" means
 *    "as often as the provider allows", not a fixed five-minute loop;
 *  - honest failures: a provider that is not configured is reported as
 *    NOT_CONFIGURED and every field it would have supplied stays
 *    DATA_UNAVAILABLE. Nothing here ever returns a placeholder row.
 */
final class ProviderGateway
{
    /** Football-specific reads resolved through provider capability probing. */
    private const CAPABILITIES = [
        'fixtures' => 'fixtures',
        'live' => 'liveFixtures',
        'fixture' => 'fixture',
        'odds' => 'odds',
        'results' => 'results',
        'headToHead' => 'headToHead',
        'recentTeamFixtures' => 'recentTeamFixtures',
        'teamStatistics' => 'teamStatistics',
        'standings' => 'standings',
        'fixtureStatistics' => 'fixtureStatistics',
    ];

    /** Requests one sweep may make before the rest is deferred to the next run. */
    private int $budget = 0;
    private int $requestsMade = 0;
    /** @var array<string,int> per-provider request counters for this sweep */
    private array $perProvider = [];
    private ?string $lastError = null;

    public function __construct(
        private SportsProviderManager $providers,
        private FootballConfiguration $config,
        private ?object $syncLog = null,
    ) {}

    public function configured(): bool
    {
        return $this->providers->configured();
    }

    /** @return list<string> registered provider ids, in fallback order */
    public function providerIds(): array
    {
        return array_keys($this->providers->all());
    }

    /**
     * Start a sweep with an explicit provider-request budget. A negative budget
     * means "unbounded" (an operator-triggered sync); 0 means the job must not
     * touch the provider at all — analysis, settlement and performance jobs read
     * the database only.
     */
    public function beginSweep(int $budget): void
    {
        $this->budget = $budget;
        $this->requestsMade = 0;
        $this->perProvider = [];
        $this->lastError = null;
    }

    public function requestsRemaining(): int
    {
        return $this->budget < 0 ? PHP_INT_MAX : max(0, $this->budget - $this->requestsMade);
    }

    public function requestsMade(): int
    {
        return $this->requestsMade;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /** @return array<string,bool> capability matrix for the diagnostics panel */
    public function capabilities(): array
    {
        $out = [];
        foreach ($this->providers->all() as $id => $provider) {
            foreach (self::CAPABILITIES as $label => $method) {
                $out[$id][$label] = method_exists($provider, $method);
            }
        }
        return $out;
    }

    /** True when at least one connected provider can serve the capability. */
    public function supports(string $capability): bool
    {
        foreach ($this->providers->all() as $provider) {
            $method = self::CAPABILITIES[$capability] ?? $capability;
            if (method_exists($provider, $method)) return true;
        }
        return false;
    }

    public function provider(string $id): ?SportsDataProvider
    {
        return $this->providers->provider($id);
    }

    /**
     * Run one provider call with fallback, budget and backoff.
     *
     * @param callable(SportsDataProvider):array $fn
     * @return array{ok:bool, provider:?string, result:mixed, failures:array<string,string>, deferred:bool}
     */
    public function call(string $operation, callable $fn, ?string $preferredId = null, bool $requireLiveProvider = true): array
    {
        $method = self::CAPABILITIES[$operation] ?? $operation;
        $failures = [];
        $deferred = false;
        $ids = $this->providers->all();
        if ($preferredId !== null && isset($ids[$preferredId])) {
            $ids = array_merge([$preferredId => $ids[$preferredId]], array_diff_key($ids, [$preferredId => true]));
        }
        foreach ($ids as $id => $provider) {
            if (!method_exists($provider, $method)) {
                $failures[$id] = 'UNSUPPORTED_CAPABILITY:' . $method;
                continue;
            }
            if ($this->inBackoff($id)) {
                $failures[$id] = 'RATE_LIMIT_BACKOFF';
                $deferred = true;
                continue;
            }
            if ($this->requestsRemaining() <= 0) {
                $failures[$id] = 'REQUEST_BUDGET_EXHAUSTED';
                $deferred = true;
                break;
            }
            // Daily quota guard. `football_providers` keeps the plan's daily
            // request ceiling and the usage recorded against it, so the sweep
            // stops on its own instead of discovering a 429 on request 5,001.
            $quota = $this->dailyQuotaState((string) $id);
            if ($quota !== null && $quota['exhausted']) {
                $failures[$id] = 'DAILY_QUOTA_EXHAUSTED:' . $quota['used'] . '/' . $quota['budget'];
                $deferred = true;
                continue;
            }
            try {
                if ($requireLiveProvider) {
                    $health = $provider->health();
                    if (($health['status'] ?? 'OFFLINE') !== 'ONLINE') {
                        $failures[$id] = 'PROVIDER_' . ($health['status'] ?? 'UNKNOWN');
                        $this->recordFailure($id, 'provider status ' . ($health['status'] ?? 'UNKNOWN'), $health);
                        continue;
                    }
                    $this->lastHealth[$id] = $health;
                    $this->noteQuota($id, $health);
                }
                $this->requestsMade++;
                $this->perProvider[$id] = ($this->perProvider[$id] ?? 0) + 1;
                $result = $fn($provider);
                $this->clearBackoff($id);
                return ['ok' => true, 'provider' => $id, 'result' => $result, 'failures' => $failures, 'deferred' => false];
            } catch (ProviderException $e) {
                $detail = self::redactSecrets($e->getMessage());
                $failures[$id] = $e->status . ': ' . $detail;
                $this->lastError = $id . ': ' . $e->status . ': ' . $detail;
                $this->recordFailure($id, $detail, [], $e);
                // Spacing between requests protects a free tier from a burst.
                $this->throttle();
            } catch (\Throwable $e) {
                $detail = self::redactSecrets($e->getMessage());
                $failures[$id] = 'DATA_ERROR: ' . $detail;
                $this->lastError = $id . ': ' . $detail;
                $this->recordFailure($id, $detail);
            }
        }
        return ['ok' => false, 'provider' => null, 'result' => null, 'failures' => $failures, 'deferred' => $deferred];
    }

    /**
     * Provider error text is displayed — it lands in the sync log's error list,
     * the operator's flash message and `football_providers.last_error`. HTTP
     * clients commonly quote the failing request in their exception, and on these
     * feeds the credential travels in a query parameter, so a raw provider message
     * can carry the key straight into the interface. The message keeps everything
     * that explains the failure and loses everything that looks like a secret.
     */
    public static function redactSecrets(string $message): string
    {
        // A query parameter on the URL the client just quoted (the common case).
        $out = preg_replace('/(?i)([?&](?:access|auth|api[_-]?key|apikey|x-[\w-]*key|token|key|secret)=)[^&#\s"]+/', '$1[redacted]', $message) ?? $message;
        // A header or "key: value" form. The separator is required so an ordinary
        // sentence ("invalid X-RapidAPI-Key header") is not mangled into mush.
        $out = preg_replace('/(?i)\b(bearer|basic)\s+([A-Za-z0-9._\-]{8,})/', '$1 [redacted]', $out) ?? $out;
        $out = preg_replace('/(?i)(\b(?:token|api[_-]?key|access[_-]?key|secret|x-[\w-]*key)\b\s*[=:]\s*)[A-Za-z0-9._\-]{6,}/', '$1[redacted]', $out) ?? $out;
        // A long high-entropy run that survived the above is a credential as far
        // as a log is concerned; request ids are shorter than 32 characters.
        $out = preg_replace('/\b[A-Za-z0-9_\-]{32,}\b/', '[redacted]', $out) ?? $out;
        return $out;
    }

    /** Aggregated provider status for the admin diagnostics panel. */
    public function status(): array
    {
        $providers = $this->providers->all();
        if ($providers === []) {
            return [
                'state' => 'NOT_CONFIGURED',
                'detail' => 'Football data provider not connected. Live fixtures and predictions are unavailable until a verified data source is configured.',
                'providers' => [],
                'capabilities' => [],
                'fixtures' => DataState::UNAVAILABLE,
                'statistics' => DataState::UNAVAILABLE,
            ];
        }
        $live = $this->providers->health();
        $statuses = [];
        $online = 0;
        foreach ($live as $id => $health) {
            $status = (string) ($health['status'] ?? 'UNKNOWN');
            $statuses[$id] = [
                'status' => $status,
                'detail' => (string) ($health['detail'] ?? ''),
                'reliability' => $health['reliability'] ?? null,
                'requestsToday' => $health['requestsToday'] ?? null,
                'limitDaily' => $health['limitDaily'] ?? null,
                'backoffUntil' => $this->backoffUntil($id),
                'requestsThisSweep' => $this->perProvider[$id] ?? 0,
            ];
            if ($status === 'ONLINE') $online++;
        }
        return [
            'state' => $online > 0 ? 'CONNECTED' : 'DEGRADED',
            'detail' => $online > 0
                ? $online . ' of ' . count($live) . ' configured feed(s) reporting ONLINE'
                : 'no configured feed is currently reachable',
            'providers' => $statuses,
            'capabilities' => $this->capabilities(),
            'fixtures' => $online > 0 ? DataState::AVAILABLE : DataState::UNAVAILABLE,
            'statistics' => $online > 0 && $this->supports('teamStatistics') ? DataState::AVAILABLE : DataState::LIMITED,
            'requestsMade' => $this->requestsMade,
            'requestsRemaining' => $this->requestsRemaining() === PHP_INT_MAX ? null : $this->requestsRemaining(),
        ];
    }

    // ── backoff (persisted in football_providers when a sync log is wired) ───

    public function inBackoff(string $providerId): bool
    {
        $until = $this->backoffUntil($providerId);
        return $until !== null && strtotime($until) > time();
    }

    /** Exponential backoff, capped at 15 minutes, honouring a provider retry-after. */
    public function recordFailure(string $providerId, string $message, array $health = [], ?ProviderException $error = null): void
    {
        if ($this->syncLog === null) return;
        $row = $this->providerRow($providerId);
        if ($row === null) {
            // There is not always a stored row to write to: a provider that has
            // never completed a sweep has none, because the sync service only
            // registers a provider once it has served data. The backoff still has
            // to be remembered — otherwise every cron tick re-tries a feed that is
            // telling us to slow down — so the row is created here, disabled, and
            // promoted by the first successful call.
            try {
                $row = $this->syncLog->ensureProvider($providerId, [
                    'displayName' => $providerId, 'status' => 'DEGRADED', 'enabled' => false,
                ]);
                $this->rows[$providerId] = $row;
            } catch (\Throwable $e) {
                return;
            }
        }
        // Exponential backoff on consecutive failures, not on total request count:
        // a busy day must not be punished as if it were a broken feed. The counter
        // is per process and resets on the next success (clearBackoff).
        $attempts = (int) ($this->consecutiveFailures[$providerId] ?? 0) + 1;
        $this->consecutiveFailures[$providerId] = $attempts;
        $retryAfter = $error !== null ? ($error->details['retryAfterSeconds'] ?? null) : null;
        $seconds = is_numeric($retryAfter)
            ? max(30, (int) $retryAfter)
            : min(900, 60 * (2 ** min(4, max(0, $attempts - 1))));
        $patch = [
            'status' => ($error !== null && $error->status === ProviderException::RATE_LIMITED) ? 'RATE_LIMITED' : 'DEGRADED',
            'backoff_until' => gmdate('c', time() + $seconds),
            'last_failure_at' => gmdate('c'),
            'last_error' => mb_substr($message, 0, 500),
        ];
        $this->syncLog->updateProvider((int) $row['id'], $patch);
        // The row is cached for the life of this gateway, so the write has to be
        // reflected there too: the very next check in the same process asks
        // "are we still in backoff?" and must not be answered from stale data.
        $this->rows[$providerId] = array_merge((array) $row, $patch);
    }

    public function clearBackoff(string $providerId): void
    {
        $row = $this->providerRow($providerId);
        if ($row === null || $this->syncLog === null) return;
        unset($this->consecutiveFailures[$providerId]);
        $patch = [
            'status' => 'ONLINE', 'backoff_until' => null, 'enabled' => 1,
            'last_success_at' => gmdate('c'),
        ];
        try {
            $this->syncLog->updateProvider((int) $row['id'], $patch);
            $this->rows[$providerId] = array_merge((array) $row, $patch);
        } catch (\Throwable $e) { /* bookkeeping must never break a sync */ }
        $this->persistQuota($providerId);
    }

    /**
     * Called by the sync service once it has registered the provider row for a
     * feed that just served data. Quota bookkeeping cannot be written before
     * that point — the row does not exist yet — so without this the first
     * successful sweep would report zero usage for the rest of the day.
     */
    public function noteProviderReady(string $providerId): void
    {
        $this->rowsLoaded = false;
        unset($this->rows[$providerId]);
        $this->persistQuota($providerId);
    }

    /**
     * Fold this process's requests into the stored daily counter. Counted per day:
     * yesterday's total is not carried into today's ceiling, and a limit the
     * provider itself reported is preferred over the configured fallback.
     */
    private function persistQuota(string $providerId): void
    {
        $row = $this->providerRow($providerId);
        if ($row === null || $this->syncLog === null) return;
        $patch = [
            'requests_used' => $this->spentToday($row) + (int) ($this->perProvider[$providerId] ?? 0),
            'requests_used_date' => $this->today(),
        ];
        $limit = $this->lastHealth[$providerId]['limitDaily'] ?? null;
        if (is_numeric($limit) && (int) $limit > 0) $patch['requests_budget'] = (int) $limit;
        try {
            $this->syncLog->updateProvider((int) $row['id'], $patch);
            $this->rows[$providerId] = array_merge((array) $row, $patch);
        } catch (\Throwable $e) { /* ignore */ }
    }

    private function noteQuota(string $providerId, array $health): void
    {
        $row = $this->providerRow($providerId);
        if ($row === null || $this->syncLog === null) return;
        if (($health['limitDaily'] ?? null) === null) return;
        try {
            // The provider's own counter is authoritative: store it with the day it
            // refers to, so the ceiling is compared against today's usage only.
            $this->syncLog->updateProvider((int) $row['id'], [
                'requests_budget' => (int) $health['limitDaily'],
                'requests_used' => (int) ($health['requestsToday'] ?? 0),
                'requests_used_date' => $this->today(),
            ]);
        } catch (\Throwable $e) { /* ignore */ }
    }

    /** Requests already counted for the current day (0 once the day rolled over). */
    private function spentToday(?array $row): int
    {
        if ($row === null) return 0;
        $date = (string) ($row['requests_used_date'] ?? '');
        if ($date === '' || substr($date, 0, 10) !== $this->today()) return 0;
        return max(0, (int) ($row['requests_used'] ?? 0));
    }

    private function today(): string
    {
        return gmdate('Y-m-d');
    }

    /**
     * The plan's daily ceiling for a provider, and what has been spent against it.
     *
     * A provider that reports its own `limitDaily` wins over configuration; for
     * one that does not, WINDELS_FOOTBALL_DAILY_REQUEST_CEILING is the fallback.
     * With neither, no daily guard exists and the per-sweep budget alone applies.
     *
     * @return array{budget:int,used:int,exhausted:bool,source:string}|null
     */
    private function dailyQuotaState(string $providerId): ?array
    {
        $row = $this->providerRow($providerId);
        $reported = (int) ($row['requests_budget'] ?? 0);
        $ceiling = $this->config->dailyRequestCeiling();
        $budget = $reported > 0 ? $reported : $ceiling;
        if ($budget <= 0) return null;
        $used = $this->spentToday($row);
        // Requests already spent inside this process have not been written back
        // yet, so count them too — otherwise one sweep can overshoot the ceiling.
        $used += (int) ($this->perProvider[$providerId] ?? 0);
        return ['budget' => $budget, 'used' => $used, 'exhausted' => $used >= $budget, 'source' => $reported > 0 ? 'provider' : 'configuration'];
    }

    private function backoffUntil(string $providerId): ?string
    {
        $row = $this->providerRow($providerId);
        return $row['backoff_until'] ?? null;
    }

    /** @var array<string,int> failures since the last success, for backoff growth */
    private array $consecutiveFailures = [];

    /** @var array<string,array<string,mixed>> last health payload per provider, for quota write-back */
    private array $lastHealth = [];

    /** @var array<string,array<string,mixed>|null> */
    private array $rows = [];
    private bool $rowsLoaded = false;

    private function loadRows(): void
    {
        if ($this->syncLog === null) return;
        try {
            foreach ($this->syncLog->listProviders() as $row) $this->rows[(string) ($row['provider_code'] ?? '')] = $row;
        } catch (\Throwable $e) {
            $this->rows = [];
        }
    }

    private function providerRow(string $providerId): ?array
    {
        if ($this->syncLog === null) return null;
        if (!$this->rowsLoaded) {
            $this->rowsLoaded = true;
            $this->loadRows();
        }
        if (isset($this->rows[$providerId])) return $this->rows[$providerId];
        // A row created *during* this process — the sync service registers a
        // provider as soon as it has served data — is worth one re-read, so the
        // quota and backoff bookkeeping that follows has somewhere to live.
        $this->loadRows();
        return $this->rows[$providerId] ?? null;
    }

    private function throttle(): void
    {
        $ms = $this->config->minRequestSpacingMs();
        if ($ms > 0 && function_exists('usleep')) {
            @usleep(min(2000000, $ms * 1000));
        }
    }
}
