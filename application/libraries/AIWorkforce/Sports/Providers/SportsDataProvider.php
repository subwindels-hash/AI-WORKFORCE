<?php
namespace AIWorkforce\Sports\Providers;

/** Provider-neutral boundary. No application layer may consume raw provider payloads. */
interface SportsDataProvider
{
    public function id(): string;
    /**
     * Live self-report. `status` is one of the ProviderHealth states:
     * ONLINE | DEGRADED | OFFLINE | TIMEOUT | RATE_LIMITED | DAILY_QUOTA_EXHAUSTED |
     * AUTHENTICATION_ERROR | BAD_REQUEST | NOT_FOUND | DATA_ERROR
     */
    public function health(): array;
    /** @return array<int,array<string,mixed>> normalized only by SportsDataNormalizer */
    public function fixtures(array $query): array;
    public function odds(string $fixtureExternalId): array;
    public function results(string $fixtureExternalId): array;
}

/**
 * Provider registry with a graceful, circuit-broken fallback chain.
 *
 * `withFallback()` runs an operation against the first provider that is
 * callable (circuit not OPEN), ONLINE and succeeds. Failures are classified
 * (ProviderException status), fed to the circuit breaker, reported through
 * the health observer, and the next provider is tried. If no provider can
 * serve the request the caller receives a STRUCTURED failure — per-provider
 * status codes plus a one-line summary — so the pipeline can say
 * "DATA_UNAVAILABLE: all providers failed" instead of "no qualified games".
 *
 * Providers are independent: a quota-exhausted api-football never blocks a
 * healthy SportMonks, and a misconfigured SportMonks (404) is skipped for the
 * configuration cooldown instead of being hammered on every fixture.
 */
class SportsProviderManager
{
    /** @var array<string,SportsDataProvider> */
    private array $providers = [];
    /** @var array<string,int> registration order */
    private array $order = [];
    /** @var (callable(SportsDataProvider, string, \Throwable|null, array): void)|null */
    private $observer = null;
    private ProviderCircuitBreaker $breaker;
    /** @var array<string,array> last live health per provider (this process) */
    private array $lastHealth = [];

    public function __construct(?ProviderCircuitBreaker $breaker = null)
    {
        $this->breaker = $breaker ?? new ProviderCircuitBreaker();
    }

    public function register(SportsDataProvider $provider): void
    {
        $this->providers[$provider->id()] = $provider;
        $this->order[] = $provider->id();
    }

    /** Observe every provider outcome for the health monitor. */
    public function setHealthObserver(callable $observer): void
    {
        $this->observer = $observer; // fn(provider, operation, error|null, payload)
    }

    public function breaker(): ProviderCircuitBreaker
    {
        return $this->breaker;
    }

    public function provider(string $id): ?SportsDataProvider
    {
        return $this->providers[$id] ?? null;
    }

    /** @return array<string,SportsDataProvider> in registration order */
    public function all(): array
    {
        $out = [];
        foreach ($this->order as $id) if (isset($this->providers[$id])) $out[$id] = $this->providers[$id];
        return $out;
    }

    /**
     * Live health of every provider, merged with its circuit state. A
     * provider whose circuit is OPEN is reported with the circuit's reason
     * (e.g. DAILY_QUOTA_EXHAUSTED) WITHOUT calling it — that is the point.
     */
    public function health(): array
    {
        $out = [];
        foreach ($this->all() as $id => $provider) {
            $circuit = $this->breaker->state($id);
            if ($circuit['state'] === ProviderCircuitBreaker::OPEN) {
                $h = $this->lastHealth[$id] ?? [];
                $h['status'] = $circuit['reason'] ?? 'OFFLINE';
                $h['detail'] = 'circuit open until ' . ($circuit['retryAt'] ?? '?') . ($circuit['detail'] ? ' — ' . $circuit['detail'] : '');
            } else {
                try { $h = $provider->health(); }
                catch (ProviderException $e) { $h = ['status' => $e->status, 'detail' => $e->getMessage()]; }
                catch (\Throwable $e) { $h = ['status' => ProviderException::DATA_ERROR, 'detail' => $e->getMessage()]; }
                $this->lastHealth[$id] = $h;
            }
            $h['circuit'] = $circuit;
            $h['operational'] = ($h['status'] ?? '') === 'ONLINE' && $circuit['state'] !== ProviderCircuitBreaker::OPEN;
            $out[$id] = array_merge(['id' => $id], $h);
        }
        return $out;
    }

    /**
     * Dashboard summary: how many providers can actually serve data right now.
     *
     * @return array{operational:int, total:int, engine:string, providers:array<string,array{status:string, detail:?string, circuit:string, retryAt:?string}>}
     */
    public function readiness(): array
    {
        $health = $this->health();
        $operational = 0;
        $providers = [];
        foreach ($health as $id => $h) {
            if (!empty($h['operational'])) $operational++;
            $providers[$id] = [
                'status' => (string) ($h['status'] ?? 'UNKNOWN'),
                'detail' => isset($h['detail']) ? (string) $h['detail'] : null,
                'circuit' => (string) ($h['circuit']['state'] ?? ProviderCircuitBreaker::CLOSED),
                'retryAt' => $h['circuit']['retryAt'] ?? null,
            ];
        }
        $total = count($health);
        return [
            'operational' => $operational,
            'total' => $total,
            'engine' => $total === 0 ? 'DISABLED_NO_PROVIDER' : ($operational > 0 ? 'READY' : 'BLOCKED'),
            'providers' => $providers,
        ];
    }

    public function configured(): bool { return count($this->providers) > 0; }

    /**
     * Run `$fn(provider)` with provider fallback.
     *
     * @param string $operation fixtures|odds|results|round|...
     * @param callable(SportsDataProvider): array $fn
     * @return array{ok:bool, provider?:string, result?:array, failures:array<string,string>, failureStatuses:array<string,string>, summary:string}
     */
    public function withFallback(string $operation, callable $fn, ?string $preferredId = null): array
    {
        $failures = [];
        $statuses = [];
        $ids = $this->order;
        if ($preferredId !== null && in_array($preferredId, $ids, true)) {
            $ids = array_merge([$preferredId], array_values(array_diff($ids, [$preferredId])));
        }
        foreach ($ids as $id) {
            $provider = $this->providers[$id];
            // 1. Circuit breaker: an OPEN circuit is skipped without any network call.
            $circuit = $this->breaker->state($id);
            if ($circuit['state'] === ProviderCircuitBreaker::OPEN) {
                $statuses[$id] = (string) ($circuit['reason'] ?? 'OFFLINE');
                $failures[$id] = $statuses[$id] . ': circuit open until ' . ($circuit['retryAt'] ?? '?') . ' (skipped, no request sent)';
                $this->notify($provider, $operation, null, ['skipped' => $statuses[$id], 'circuit' => $circuit]);
                continue;
            }
            try {
                // 2. Live health probe. Non-ONLINE self-reports are classified and
                //    fed to the breaker too, so a provider whose /status says
                //    "quota exhausted" is not probed again on the next fixture.
                $health = $provider->health();
                $this->lastHealth[$id] = $health;
                $status = (string) ($health['status'] ?? ProviderException::OFFLINE);
                if ($status !== 'ONLINE') {
                    $statuses[$id] = $status;
                    $failures[$id] = $status . ': ' . (string) ($health['detail'] ?? 'provider self-reported ' . $status);
                    $probe = new ProviderException((string) ($health['detail'] ?? 'provider status ' . $status), self::asExceptionStatus($status), null, $health['retryAt'] ?? null ? ['retryAt' => $health['retryAt']] : []);
                    $this->breaker->recordFailure($id, $probe);
                    $this->notify($provider, $operation, $probe, ['skipped' => $status]);
                    continue;
                }
                $result = $fn($provider);
                $this->breaker->recordSuccess($id);
                $this->notify($provider, $operation, null, ['ok' => true]);
                return ['ok' => true, 'provider' => $id, 'result' => $result, 'failures' => $failures, 'failureStatuses' => $statuses, 'summary' => ''];
            } catch (ProviderException $e) {
                $statuses[$id] = $e->status;
                $failures[$id] = $e->status . ': ' . ProviderHttp::redact($e->getMessage());
                $this->breaker->recordFailure($id, $e);
                $this->notify($provider, $operation, $e, []);
            } catch (\Throwable $e) {
                $wrapped = new ProviderException(ProviderHttp::redact($e->getMessage()), ProviderException::DATA_ERROR, $e);
                $statuses[$id] = ProviderException::DATA_ERROR;
                $failures[$id] = 'DATA_ERROR: ' . $wrapped->getMessage();
                $this->breaker->recordFailure($id, $wrapped);
                $this->notify($provider, $operation, $wrapped, []);
            }
        }
        return ['ok' => false, 'failures' => $failures, 'failureStatuses' => $statuses, 'summary' => self::summarize($operation, $statuses)];
    }

    /** "fixtures: all 4 provider(s) failed — api-football DAILY_QUOTA_EXHAUSTED, sportmonks NOT_FOUND, ..." */
    public static function summarize(string $operation, array $statuses): string
    {
        if ($statuses === []) return $operation . ': no provider configured';
        $parts = [];
        foreach ($statuses as $id => $st) $parts[] = $id . ' ' . $st;
        return sprintf('%s: all %d provider(s) failed — %s', $operation, count($statuses), implode(', ', $parts));
    }

    /** Map a health-report status onto a ProviderException status for the breaker. */
    private static function asExceptionStatus(string $status): string
    {
        return match ($status) {
            ProviderException::DAILY_QUOTA_EXHAUSTED, ProviderException::RATE_LIMITED,
            ProviderException::AUTHENTICATION_ERROR, ProviderException::BAD_REQUEST,
            ProviderException::NOT_FOUND, ProviderException::TIMEOUT, ProviderException::OFFLINE,
            ProviderException::DEGRADED, ProviderException::DATA_ERROR => $status,
            default => ProviderException::OFFLINE,
        };
    }

    private function notify(SportsDataProvider $provider, string $operation, ?\Throwable $error, array $payload): void
    {
        if ($this->observer !== null) {
            try { ($this->observer)($provider, $operation, $error, $payload); }
            catch (\Throwable $e) { /* health observation must never break sync */ }
        }
    }
}
