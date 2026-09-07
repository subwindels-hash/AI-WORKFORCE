<?php
namespace AIWorkforce\TradingProtection;

use AIWorkforce\ApiProviders;
use AIWorkforce\Persistence\PlatformStateRepository;

/**
 * High-impact economic event source (§1 News Protection).
 *
 * The calendar is an ordinary managed provider: the administrator adds an
 * Economic Calendar feed under Admin → API like every other service, so no
 * vendor is hard-coded and no licence ships with the platform.
 *
 * Expected JSON (any of these shapes; `at` may be ISO-8601 or epoch seconds):
 *
 *   {"events": [{"at": "2026-09-04T12:30:00Z", "name": "Nonfarm Payrolls",
 *                "impact": "high", "currency": "USD"}, …]}
 *
 *   [{"time": 1788522600, "title": "CPI (YoY)", "importance": "3"}, …]
 *
 * Results are cached in platform state for `news.feedMaxAgeMinutes` so the
 * per-minute protection scan does not hammer the feed.
 */
final class EconomicCalendar
{
    public const SERVICE = 'economic_calendar';

    /** Impact strings that count as high impact unless the admin overrides. */
    public const HIGH_IMPACT = ['high', 'red', '3', 'holiday-high'];

    public function __construct(
        private PlatformStateRepository $state,
        /** @var (callable(): int)|null */
        private $clock = null,
        private mixed $http = null,
    ) {}

    private function now(): int
    {
        return (int) ($this->clock ? call_user_func($this->clock) : time());
    }

    /**
     * Upcoming high-impact events.
     *
     * @return array{configured:bool, ok:bool, source:?string, events:array<int,array<string,mixed>>,
     *               fetchedAt:?string, ageSeconds:?int, error:?string}
     */
    public function upcoming(int $maxAgeMinutes = 180): array
    {
        $cached = $this->cached();
        if ($cached !== null && $cached['ageSeconds'] !== null && $cached['ageSeconds'] <= ($maxAgeMinutes * 60)) {
            return $cached;
        }
        $fresh = $this->fetch();
        $this->store($fresh);
        // A failed refresh degrades to the last good cache only when the cache
        // still exists: a stale calendar is better than none, but it must be
        // visibly stale so the engine can apply the §12 fail-safe.
        if (!$fresh['ok'] && $cached !== null && !empty($cached['events'])) {
            return array_merge($cached, ['error' => $fresh['error'], 'ok' => false, 'stale' => true]);
        }
        return $fresh;
    }

    /** @return array<string,mixed>|null */
    private function cached(): ?array
    {
        $state = $this->state->load();
        $row = $state[AutomaticProtection::STATE_KEY]['calendar'] ?? null;
        if (!is_array($row)) return null;
        $row['ageSeconds'] = isset($row['fetchedAtTs']) ? $this->now() - (int) $row['fetchedAtTs'] : null;
        return $row;
    }

    /**
     * Persist a calendar payload. Public so a feed adapter (or a test fixture)
     * can publish events without going through HTTP.
     */
    public function store(array $payload): void
    {
        $state = $this->state->load();
        $payload['fetchedAtTs'] = $this->now();
        $state[AutomaticProtection::STATE_KEY]['calendar'] = $payload;
        $this->state->save($state);
    }

    /** @return array<string,mixed> */
    private function fetch(): array
    {
        $cfg = class_exists(ApiProviders::class) ? ApiProviders::resolve(self::SERVICE) : null;
        if (!is_array($cfg)) {
            return self::emptyResult(false, null, 'No Economic Calendar provider is configured (Admin → API).');
        }
        $url = trim((string) ($cfg['base_url'] ?? ''));
        if ($url === '') {
            return self::emptyResult(false, $cfg['driver'] ?? null, 'Economic Calendar provider has no feed URL.');
        }

        $headers = ['Accept' => 'application/json'];
        $token = trim((string) ($cfg['secrets']['token'] ?? ($cfg['secrets']['api_key'] ?? '')));
        if ($token !== '') $headers['X-Api-Key'] = $token;

        try {
            $response = $this->http !== null
                ? call_user_func($this->http, $url, $headers)
                : ApiProviders::http($url, $headers);
        } catch (\Throwable $e) {
            return self::emptyResult(false, $cfg['driver'] ?? null, 'Calendar request failed: ' . $e->getMessage());
        }

        $status = (int) ($response['status'] ?? 0);
        $body = (string) ($response['body'] ?? '');
        if ($status < 200 || $status >= 300) {
            return self::emptyResult(false, $cfg['driver'] ?? null, "Calendar feed answered HTTP {$status}.");
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return self::emptyResult(false, $cfg['driver'] ?? null, 'Calendar feed did not return JSON.');
        }

        $events = self::extractEvents($decoded);
        usort($events, fn(array $a, array $b): int => ($a['atTs'] ?? 0) <=> ($b['atTs'] ?? 0));

        return [
            'configured' => true,
            'ok' => true,
            'source' => (string) ($cfg['driver'] ?? 'configured feed'),
            'events' => $events,
            'fetchedAt' => gmdate('c'),
            'ageSeconds' => 0,
            'error' => null,
        ];
    }

    /** @return array<string,mixed> */
    private static function emptyResult(bool $configured, ?string $source, string $error): array
    {
        return [
            'configured' => $configured,
            'ok' => false,
            'source' => $source,
            'events' => [],
            'fetchedAt' => null,
            'ageSeconds' => null,
            'error' => $error,
        ];
    }

    /**
     * Accept the common calendar shapes and normalise to
     * {at, atTs, name, impact, currency}.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function extractEvents(array $decoded): array
    {
        $rows = [];
        if (isset($decoded['events']) && is_array($decoded['events'])) $rows = $decoded['events'];
        elseif (isset($decoded['data']) && is_array($decoded['data'])) $rows = $decoded['data'];
        elseif (isset($decoded[0])) $rows = $decoded;

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $rawAt = $row['at'] ?? ($row['time'] ?? ($row['datetime'] ?? ($row['date_utc'] ?? ($row['date'] ?? null))));
            $ts = self::timestamp($rawAt);
            if ($ts === null) continue;
            $out[] = [
                'at' => gmdate('c', $ts),
                'atTs' => $ts,
                'name' => trim((string) ($row['name'] ?? ($row['title'] ?? ($row['event'] ?? 'Economic event')))),
                'impact' => self::impact($row['impact'] ?? ($row['importance'] ?? ($row['sentiment'] ?? ''))),
                'currency' => strtoupper(trim((string) ($row['currency'] ?? ($row['country'] ?? '')))),
            ];
        }
        return $out;
    }

    private static function timestamp(mixed $value): ?int
    {
        if (is_numeric($value)) {
            $n = (int) $value;
            // Heuristic: values that look like milliseconds.
            if ($n > 100000000000) $n = (int) ($n / 1000);
            return $n > 0 ? $n : null;
        }
        if (!is_string($value) || trim($value) === '') return null;
        $ts = strtotime($value);
        return $ts === false ? null : $ts;
    }

    /** Normalise vendor impact labels to high | medium | low. */
    public static function impact(mixed $value): string
    {
        $v = strtolower(trim((string) $value));
        if (in_array($v, ['high', 'red', '3', 'holiday-high'], true)) return 'high';
        if (in_array($v, ['medium', 'moderate', 'orange', '2'], true)) return 'medium';
        return 'low';
    }
}
