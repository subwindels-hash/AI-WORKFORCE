<?php
namespace AIWorkforce\Sports;

/**
 * Odds freshness with a configurable, market/provider-aware TTL.
 *
 * The maximum acceptable age is resolved per odds row, first match wins:
 *
 *   1. an explicit $maxAgeSeconds argument (tests, verifiedContext,
 *      point-in-time replay);
 *   2. a per-provider override  (provider id → seconds);
 *   3. a per-market override    (market → seconds);
 *   4. WINDELS_SPORTS_ODDS_MAX_AGE (seconds, environment);
 *   5. the built-in pre-match default (6 hours).
 *
 * The old engine hard-coded a 15-minute TTL, so odds synced by an earlier
 * sweep (the cron odds job is idempotent per fixture per DAY) were marked
 * stale hours before kickoff and every prediction of the day was rejected
 * as STALE_ODDS. The engine now only flags STALE when the configured
 * maximum age has actually been exceeded, and reports the full provenance
 * so each decision is reconstructable:
 *
 *   oddsUpdatedAt   when the provider observed the price (observedAt)
 *   oddsSource      which provider supplied the row
 *   oddsAgeSeconds  age at evaluation time
 *   oddsStatus      FRESH | STALE | UNAVAILABLE | INVALID_TIMESTAMP
 *   maxAgeSeconds   the TTL this assessment was made against
 */
class OddsFreshnessEngine
{
    /** Default pre-match TTL: bookmaker prices drift slowly pre-match. */
    public const DEFAULT_MAX_AGE_SECONDS = 21600; // 6 hours

    public const STATUS_FRESH = 'FRESH';
    public const STATUS_STALE = 'STALE';
    public const STATUS_UNAVAILABLE = 'UNAVAILABLE';
    /**
     * The bookmaker does not OFFER this market for this fixture — a coverage
     * gap, not a feed gap. Distinct from UNAVAILABLE ("we hold no price"):
     * a small book that simply never quotes Asian handicap on a lower
     * division is behaving normally, whereas a fixture whose 1X2 price we
     * failed to fetch is a data problem worth chasing. Collapsing the two
     * made every coverage gap look like a broken feed.
     */
    public const STATUS_MARKET_UNAVAILABLE = 'MARKET_UNAVAILABLE';
    public const STATUS_INVALID_TIMESTAMP = 'INVALID_TIMESTAMP';

    public const ENV_MAX_AGE = 'WINDELS_SPORTS_ODDS_MAX_AGE';

    /** @var array<string,int> market → TTL seconds */
    private array $marketMaxAge;
    /** @var array<string,int> provider id → TTL seconds */
    private array $providerMaxAge;
    private int $defaultMaxAge;

    /**
     * @param array<string,int> $marketMaxAge   market → TTL seconds
     * @param array<string,int> $providerMaxAge provider id → TTL seconds
     */
    public function __construct(array $marketMaxAge = [], array $providerMaxAge = [], ?int $defaultMaxAge = null)
    {
        $this->marketMaxAge = array_filter(array_map('intval', $marketMaxAge), fn($v) => $v > 0);
        $this->providerMaxAge = array_filter(array_map('intval', $providerMaxAge), fn($v) => $v > 0);
        $this->defaultMaxAge = $defaultMaxAge !== null && $defaultMaxAge > 0 ? $defaultMaxAge : self::envDefaultMaxAge();
    }

    /** Environment-configured default TTL (>= 60s), or the built-in default. */
    public static function envDefaultMaxAge(): int
    {
        $env = getenv(self::ENV_MAX_AGE);
        if (is_string($env) && $env !== '' && is_numeric($env)) {
            $value = (int) $env;
            if ($value >= 60) return $value;
        }
        return self::DEFAULT_MAX_AGE_SECONDS;
    }

    /**
     * Resolve the TTL for a market/provider combination.
     *
     * @param int|null $configured explicit override (config/context/test), wins over everything
     */
    public static function maxAgeFor(?string $market = null, ?string $provider = null, ?int $configured = null, array $marketMaxAge = [], array $providerMaxAge = []): int
    {
        if ($configured !== null && $configured > 0) return $configured;
        $provider = $provider !== null ? strtolower(trim((string) $provider)) : '';
        if ($provider !== '' && isset($providerMaxAge[$provider]) && $providerMaxAge[$provider] > 0) return (int) $providerMaxAge[$provider];
        $market = $market !== null ? strtoupper(trim((string) $market)) : '';
        if ($market !== '' && isset($marketMaxAge[$market]) && $marketMaxAge[$market] > 0) return (int) $marketMaxAge[$market];
        return self::envDefaultMaxAge();
    }

    /** TTL of this engine instance (instance overrides included). */
    public function maxAge(?string $market = null, ?string $provider = null): int
    {
        return self::maxAgeFor($market, $provider, null, $this->marketMaxAge, $this->providerMaxAge);
    }

    /**
     * Assess one odds row.
     *
     * Accepts both the normalized ('observedAt') and repository-row
     * ('observed_at') key shapes; an empty or unparseable timestamp is never
     * "fresh" (reported as INVALID_TIMESTAMP, never silently trusted).
     *
     * @param array|null  $odds          odds row (normalized or repository shape)
     * @param int|null    $maxAgeSeconds explicit TTL override; null resolves per market/provider
     * @param int|null    $now           evaluation clock (defaults to now)
     */
    public function assess(?array $odds, ?int $maxAgeSeconds = null, ?int $now = null): array
    {
        if ($odds === null) {
            return [
                'available' => false, 'fresh' => false,
                'oddsStatus' => self::STATUS_UNAVAILABLE, 'ageSeconds' => null, 'oddsAgeSeconds' => null,
                'oddsUpdatedAt' => null, 'oddsSource' => null, 'maxAgeSeconds' => $this->maxAge(null, null),
                'score' => 0, 'reason' => 'ODDS_UNAVAILABLE',
            ];
        }
        $rawAt = (string) ($odds['observedAt'] ?? $odds['observed_at'] ?? '');
        $source = $this->sourceOf($odds);
        if ($rawAt === '') {
            return $this->row(true, false, self::STATUS_INVALID_TIMESTAMP, null, $rawAt, $source, $this->maxAge($odds['market'] ?? null, $source), 0, 'ODDS_TIMESTAMP_INVALID');
        }
        try { $observed = (new \DateTimeImmutable($rawAt))->getTimestamp(); }
        catch (\Throwable $e) {
            return $this->row(true, false, self::STATUS_INVALID_TIMESTAMP, null, $rawAt, $source, $this->maxAge($odds['market'] ?? null, $source), 0, 'ODDS_TIMESTAMP_INVALID');
        }
        // Prefer the BOOKMAKER's own odds-update timestamp when the feed
        // supplies one (that is the honest "when did this price last move"
        // clock the TTL is meant to measure). When the feed gives no update
        // time — or one in the future/unparseable — the observation time
        // remains the best honest proxy. A re-fetch of an unchanged price
        // never REJUVENATES the bookmaker clock, and a price not refreshed
        // this run is not stale merely because it was not re-requested.
        $at = $observed;
        $providerUpdated = $this->providerUpdatedAt($odds);
        if ($providerUpdated !== null && $providerUpdated <= (int) ($now ?? time())) {
            $at = min($observed, $providerUpdated);
        }
        $maxAge = self::maxAgeFor(
            isset($odds['market']) ? (string) $odds['market'] : null,
            $source,
            $maxAgeSeconds,
            $this->marketMaxAge,
            $this->providerMaxAge
        );
        $age = max(0, ((int) ($now ?? time())) - $at);
        $fresh = $age <= $maxAge;
        $score = $fresh ? max(1, (int) round(100 * (1 - $age / max(1, $maxAge)))) : 0;
        return $this->row(true, $fresh, $fresh ? self::STATUS_FRESH : self::STATUS_STALE, $age, gmdate('c', $at), $source, $maxAge, $score, $fresh ? null : 'STALE_ODDS');
    }

    /**
     * The bookmaker/provider's own "odds last updated" timestamp, or null
     * when the feed supplied none. Read from the decoded payload or a
     * top-level passthrough; never synthesized from the local clock.
     */
    private function providerUpdatedAt(array $odds): ?int
    {
        $payload = SportsDataNormalizer::document($odds['payload'] ?? null);
        foreach (['updatedAt', 'oddsUpdatedAt', 'latest_bookmaker_update'] as $key) {
            $value = $odds[$key] ?? $payload[$key] ?? null;
            if (!is_string($value) || trim($value) === '') continue;
            try { return (new \DateTimeImmutable($value))->getTimestamp(); }
            catch (\Throwable $e) { /* ignore one malformed stamp, try the next */ }
        }
        return null;
    }

    /** Provider attribution of an odds row (explicit key beats payload). */
    private function sourceOf(array $odds): ?string
    {
        foreach (['oddsSource', 'provider', 'source'] as $key) {
            if (isset($odds[$key]) && is_string($odds[$key]) && trim($odds[$key]) !== '') return trim($odds[$key]);
        }
        $payload = SportsDataNormalizer::document($odds['payload'] ?? null);
        foreach (['provider', 'source'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key]) && trim($payload[$key]) !== '') return trim($payload[$key]);
        }
        return null;
    }

    private function row(bool $available, bool $fresh, string $status, ?int $age, string $updatedAt, ?string $source, int $maxAge, int $score, ?string $reason): array
    {
        return [
            'available' => $available, 'fresh' => $fresh,
            'oddsStatus' => $status,
            'ageSeconds' => $age, 'oddsAgeSeconds' => $age,
            'oddsUpdatedAt' => $updatedAt !== '' ? $updatedAt : null,
            'oddsSource' => $source,
            'maxAgeSeconds' => $maxAge,
            'score' => $score, 'reason' => $reason,
        ];
    }
}
