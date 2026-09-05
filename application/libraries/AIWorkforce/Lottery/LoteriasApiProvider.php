<?php
namespace AIWorkforce\Lottery;

/**
 * loteriasapi.com — EuroMillions / Lottery REST adapter.
 *
 * Vendor contract (https://loteriasapi.com/en/euromillions-api):
 *   GET {base}/results/{game}/latest          -> single result object
 *   GET {base}/results/{game}?from=&to=       -> list of result objects
 *   GET {base}/results/{game}/{yyyy}/{nnn}    -> single result object
 * Auth: header `x-api-key: <key>`. Base: https://api.loteriasapi.com/v1
 *
 * Result payload:
 *   { game, draw_date, draw_id, numbers[5], stars[2], el_millon,
 *     prizes[], jackpot_next, meta:{source, updated_at} }
 *
 * The adapter maps that payload into the provider-neutral shape the rest of
 * WINDELS Lottery Intelligence consumes (externalId / drawDate / main /
 * stars / jackpot / rollover / winners / source / sourceTimestamp). It never
 * marks a draw VERIFIED on its own: LotteryIntelligence still validates
 * counts, ranges, dates, source and timestamps before anything is stored.
 *
 * Credentials come from API Management (service `lottery`, driver
 * `loteriasapi`) or from the environment — never from committed code:
 *   WINDELS_LOTTERY_LOTERIASAPI_KEY      (required)
 *   WINDELS_LOTTERY_LOTERIASAPI_ENABLED  (1 to enable; a key alone also enables)
 *   WINDELS_LOTTERY_LOTERIASAPI_BASE_URL (default https://api.loteriasapi.com/v1)
 *   WINDELS_LOTTERY_LOTERIASAPI_GAME     (default euromillones)
 *   WINDELS_LOTTERY_LOTERIASAPI_TIMEOUT  (default 8 seconds)
 */
final class LoteriasApiProvider implements LotteryProvider
{
    public const DEFAULT_BASE_URL = 'https://api.loteriasapi.com/v1';
    public const DEFAULT_GAME = 'euromillones';
    public const DEFAULT_SOURCE = 'loteriasapi.com (SELAE)';

    private string $baseUrl;
    private string $game;
    private string $apiKey;
    private string $source;
    private int $timeout;
    private bool $enabled;
    /** @var callable(string $url, array<int,string> $headers): array{status:int,body:string} */
    private $transport;

    public function __construct(
        ?string $baseUrl = null,
        ?string $apiKey = null,
        ?string $game = null,
        ?bool $enabled = null,
        ?callable $transport = null,
        ?int $timeout = null,
        ?string $source = null,
    ) {
        $managed = self::managedConfig();
        $this->baseUrl = self::normalizeBaseUrl($baseUrl
            ?? (string) (($managed['base_url'] ?? '') ?: (getenv('WINDELS_LOTTERY_LOTERIASAPI_BASE_URL') ?: '')));
        $this->apiKey = trim($apiKey
            ?? (string) (($managed['secrets']['api_key'] ?? $managed['secrets']['token'] ?? '') ?: (getenv('WINDELS_LOTTERY_LOTERIASAPI_KEY') ?: '')));
        $this->game = self::normalizeGame($game
            ?? (string) (($managed['extra']['game'] ?? '') ?: (getenv('WINDELS_LOTTERY_LOTERIASAPI_GAME') ?: '')));
        $this->source = trim($source
            ?? (string) (($managed['extra']['source'] ?? '') ?: (getenv('WINDELS_LOTTERY_LOTERIASAPI_SOURCE') ?: ''))) ?: self::DEFAULT_SOURCE;
        $timeoutRaw = $timeout ?? (int) (($managed['extra']['timeout'] ?? 0) ?: (getenv('WINDELS_LOTTERY_LOTERIASAPI_TIMEOUT') ?: 0));
        $this->timeout = $timeoutRaw > 0 ? min(60, $timeoutRaw) : 8;
        $this->enabled = $enabled ?? (
            (!empty($managed) && !empty($managed['enabled']))
            || getenv('WINDELS_LOTTERY_LOTERIASAPI_ENABLED') === '1'
            || (getenv('WINDELS_LOTTERY_LOTERIASAPI_KEY') !== false && trim((string) getenv('WINDELS_LOTTERY_LOTERIASAPI_KEY')) !== '')
        );
        $this->transport = $transport ?? [$this, 'defaultTransport'];
    }

    /** @return array<string,mixed> */
    private static function managedConfig(): array
    {
        if (!class_exists(\AIWorkforce\ApiProviders::class)) return [];
        $cfg = \AIWorkforce\ApiProviders::resolve('lottery');
        if (!is_array($cfg)) return [];
        // Only adopt the managed row when it is actually this driver.
        if (($cfg['driver'] ?? '') !== 'loteriasapi') return [];
        return $cfg;
    }

    public static function normalizeBaseUrl(string $baseUrl): string
    {
        $baseUrl = trim($baseUrl);
        if ($baseUrl === '') return self::DEFAULT_BASE_URL;
        $baseUrl = rtrim($baseUrl, '/');
        $host = strtolower((string) (parse_url($baseUrl, PHP_URL_HOST) ?? ''));
        // The marketing site (loteriasapi.com) serves HTML, not JSON — rewrite
        // it to the API host so a pasted docs URL still works.
        if ($host === 'loteriasapi.com' || $host === 'www.loteriasapi.com') return self::DEFAULT_BASE_URL;
        if ($host === 'api.loteriasapi.com' && !preg_match('#/v\d+$#', $baseUrl)) return self::DEFAULT_BASE_URL;
        return $baseUrl;
    }

    /** EuroMillions is `euromillones` upstream; accept the English spelling too. */
    public static function normalizeGame(string $game): string
    {
        $game = strtolower(trim($game));
        if ($game === '' ) return self::DEFAULT_GAME;
        $game = preg_replace('/[^a-z0-9_\-]/', '', $game) ?? '';
        if ($game === '' || $game === 'euromillions' || $game === 'euromillion') return self::DEFAULT_GAME;
        return $game;
    }

    public function id(): string { return 'loteriasapi'; }
    public function name(): string { return 'LoteriasAPI (loteriasapi.com) — EuroMillions results'; }
    public function game(): string { return $this->game; }
    public function baseUrl(): string { return $this->baseUrl; }

    public function configured(): bool
    {
        return $this->enabled && $this->apiKey !== '' && $this->httpsUrl($this->baseUrl);
    }

    public function health(): array
    {
        if (!$this->enabled) {
            return ['state' => 'DISABLED', 'licensed' => false, 'synthetic' => false,
                'message' => 'LoteriasAPI is disabled — enable the lottery provider in Admin → API Management'];
        }
        if ($this->apiKey === '' || !$this->httpsUrl($this->baseUrl)) {
            return ['state' => 'UNCONFIGURED', 'licensed' => false, 'synthetic' => false,
                'message' => 'LoteriasAPI needs an API key (x-api-key) and an HTTPS base URL — free key at https://loteriasapi.com/auth/register'];
        }
        try {
            $payload = $this->get($this->endpoint('/results/' . rawurlencode($this->game) . '/latest'));
            $draw = $this->normalizeDraw(is_array($payload['data'] ?? null) ? $payload['data'] : $payload);
            return [
                'state' => 'ONLINE', 'licensed' => true, 'synthetic' => false,
                'message' => 'LoteriasAPI reachable' . ($draw['drawDate'] !== '' ? ' — latest draw ' . $draw['drawDate'] : ''),
                'source' => $this->source,
                'licenseConfigured' => true,
                'game' => $this->game,
                'latestDrawDate' => $draw['drawDate'] !== '' ? $draw['drawDate'] : null,
            ];
        } catch (\Throwable $e) {
            return ['state' => 'OFFLINE', 'licensed' => true, 'synthetic' => false,
                'message' => 'LoteriasAPI is configured but unavailable: ' . $this->safeMessage($e->getMessage()),
                'source' => $this->source, 'licenseConfigured' => true, 'game' => $this->game];
        }
    }

    /**
     * Historical draws. Without an explicit window we ask for a range wide
     * enough to cover `limit` draws (EuroMillions draws twice a week) and fall
     * back to the /latest endpoint when the range endpoint yields nothing.
     *
     * @return list<array<string,mixed>>
     */
    public function draws(?string $from = null, ?string $to = null, int $limit = 100): array
    {
        if (!$this->configured()) return [];
        $limit = min(1000, max(1, $limit));
        $explicitFrom = $this->validDate($from) ? $from : null;
        $explicitTo = $this->validDate($to) ? $to : null;
        $queryTo = $explicitTo ?? gmdate('Y-m-d');
        if ($explicitFrom !== null) {
            $queryFrom = $explicitFrom;
        } else {
            // EuroMillions draws twice a week — ask for a window wide enough
            // to cover `limit` draws plus a margin for schedule changes.
            $weeks = (int) ceil($limit / 2) + 2;
            $queryFrom = gmdate('Y-m-d', strtotime($queryTo . ' -' . $weeks . ' weeks'));
        }

        $rows = [];
        try {
            $payload = $this->get($this->endpoint('/results/' . rawurlencode($this->game), ['from' => $queryFrom, 'to' => $queryTo]));
            $rows = $this->rows($payload);
        } catch (\Throwable $e) {
            $rows = [];
        }
        if ($rows === []) {
            try {
                $payload = $this->get($this->endpoint('/results/' . rawurlencode($this->game) . '/latest'));
                $single = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
                $rows = isset($single['numbers']) || isset($single['draw_date']) ? [$single] : [];
            } catch (\Throwable $e) {
                // No data is preferable to fabricated data; health() reports why.
                return [];
            }
        }

        $out = [];
        foreach ($rows as $row) {
            $draw = $this->normalizeDraw($row);
            if ($draw['drawDate'] === '') continue;
            // Only an explicit caller window is enforced locally; otherwise the
            // upstream range filter is authoritative.
            if ($explicitFrom !== null && $draw['drawDate'] < $explicitFrom) continue;
            if ($explicitTo !== null && $draw['drawDate'] > $explicitTo) continue;
            $out[] = $draw;
        }
        usort($out, static fn(array $a, array $b): int => strcmp((string) $b['drawDate'], (string) $a['drawDate']));
        return array_slice($out, 0, $limit);
    }

    /** Next-draw jackpot as published by the feed (never used to infer outcomes). */
    public function jackpotInfo(): ?array
    {
        if (!$this->configured()) return null;
        try {
            $payload = $this->get($this->endpoint('/results/' . rawurlencode($this->game) . '/latest'));
        } catch (\Throwable $e) {
            return null;
        }
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        if (!is_array($data)) return null;
        $next = $data['jackpot_next'] ?? ($data['jackpotNext'] ?? null);
        if (!is_scalar($next) || (string) $next === '') return null;
        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
        return [
            'source' => $this->source,
            'value' => is_numeric($next) ? number_format((float) $next, 2, '.', '') : (string) $next,
            'currency' => 'EUR',
            'observedAt' => isset($meta['updated_at']) && is_scalar($meta['updated_at']) ? (string) $meta['updated_at'] : gmdate('c'),
            'note' => 'Published next-draw jackpot from LoteriasAPI; not used to infer future draw outcomes',
        ];
    }

    /** Single draw by vendor draw id, e.g. "2026/029". */
    public function drawById(string $drawId): ?array
    {
        if (!$this->configured()) return null;
        if (!preg_match('#^(\d{4})/(\d{1,4})$#', trim($drawId), $m)) return null;
        try {
            $payload = $this->get($this->endpoint('/results/' . rawurlencode($this->game) . '/' . $m[1] . '/' . $m[2]));
        } catch (\Throwable $e) {
            return null;
        }
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        if (!is_array($data) || !isset($data['numbers'])) return null;
        return $this->normalizeDraw($data);
    }

    // --------------------------------------------------------------- mapping

    /** @return list<array<string,mixed>> */
    private function rows($payload): array
    {
        if (!is_array($payload)) throw new \RuntimeException('LoteriasAPI returned invalid JSON');
        foreach ([$payload['results'] ?? null, $payload['data'] ?? null, $payload['draws'] ?? null, $payload] as $candidate) {
            if (is_array($candidate) && $this->isList($candidate)) {
                return array_values(array_filter($candidate, 'is_array'));
            }
            if (is_array($candidate) && is_array($candidate['results'] ?? null) && $this->isList($candidate['results'])) {
                return array_values(array_filter($candidate['results'], 'is_array'));
            }
        }
        return [];
    }

    /**
     * Vendor payload → provider-neutral draw. Missing fields stay missing so
     * the central validator can reject and audit them.
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function normalizeDraw(array $row): array
    {
        $main = $row['numbers'] ?? ($row['main'] ?? ($row['combinacion'] ?? null));
        $stars = $row['stars'] ?? ($row['luckyStars'] ?? ($row['estrellas'] ?? null));
        $meta = is_array($row['meta'] ?? null) ? $row['meta'] : [];
        $drawDate = (string) ($row['draw_date'] ?? ($row['drawDate'] ?? ($row['date'] ?? ($row['fecha'] ?? ''))));
        $drawId = (string) ($row['draw_id'] ?? ($row['drawId'] ?? ($row['id'] ?? '')));
        if ($drawId === '' && $drawDate !== '') $drawId = strtoupper($this->game) . '-' . $drawDate;

        $prizes = is_array($row['prizes'] ?? null) ? $row['prizes'] : [];
        $jackpotWinners = null;
        $rollover = false;
        foreach ($prizes as $tier) {
            if (!is_array($tier)) continue;
            $isTop = (string) ($tier['category'] ?? '') === '1' || strtoupper((string) ($tier['match'] ?? '')) === '5+2';
            if (!$isTop) continue;
            if (isset($tier['winners']) && is_numeric($tier['winners'])) {
                $jackpotWinners = (string) (int) $tier['winners'];
                $rollover = (int) $tier['winners'] === 0;
            }
        }

        $jackpot = $row['jackpot'] ?? null;
        if (!is_scalar($jackpot) || (string) $jackpot === '') {
            foreach ($prizes as $tier) {
                if (is_array($tier) && ((string) ($tier['category'] ?? '') === '1' || strtoupper((string) ($tier['match'] ?? '')) === '5+2')
                    && isset($tier['prize']) && is_numeric($tier['prize']) && (float) $tier['prize'] > 0) {
                    $jackpot = $tier['prize'];
                }
            }
        }

        $timestamp = $meta['updated_at'] ?? ($row['sourceTimestamp'] ?? ($row['updated_at'] ?? null));

        return [
            'externalId' => $drawId,
            'drawDate' => $drawDate,
            'main' => is_array($main) ? array_values($main) : $main,
            'stars' => is_array($stars) ? array_values($stars) : $stars,
            'jackpot' => is_scalar($jackpot) && (string) $jackpot !== ''
                ? (is_numeric($jackpot) ? number_format((float) $jackpot, 2, '.', '') : (string) $jackpot)
                : null,
            'rollover' => $rollover,
            'winners' => $jackpotWinners,
            'source' => $this->source,
            'sourceTimestamp' => is_scalar($timestamp) && (string) $timestamp !== ''
                ? (string) $timestamp
                : ($drawDate !== '' ? $drawDate . 'T21:00:00+00:00' : ''),
            'extra' => [
                'elMillon' => isset($row['el_millon']) && is_scalar($row['el_millon']) ? (string) $row['el_millon'] : null,
                'jackpotNext' => isset($row['jackpot_next']) && is_numeric($row['jackpot_next']) ? (float) $row['jackpot_next'] : null,
                'prizeTiers' => count($prizes),
                'feedSource' => isset($meta['source']) && is_scalar($meta['source']) ? (string) $meta['source'] : null,
            ],
        ];
    }

    // ------------------------------------------------------------------ http

    private function endpoint(string $path, array $query = []): string
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        $query = array_filter($query, static fn($v) => $v !== null && $v !== '');
        return $query === [] ? $url : $url . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array<string,mixed> */
    private function get(string $url): array
    {
        $resp = ($this->transport)($url, [
            'Accept: application/json',
            'x-api-key: ' . $this->apiKey,
            'User-Agent: WINDELS-Lottery-LoteriasAPI/1.0',
        ]);
        $status = (int) ($resp['status'] ?? 0);
        $body = (string) ($resp['body'] ?? '');
        if ($status === 401 || $status === 403) throw new \RuntimeException('authentication rejected (HTTP ' . $status . ')');
        if ($status === 429) throw new \RuntimeException('rate limited (HTTP 429) — the free plan allows 1,000 requests/month');
        if ($status === 404) throw new \RuntimeException('not found (HTTP 404) — check the game code and base URL');
        if ($status === 0) throw new \RuntimeException('no HTTP response (network/SSL/firewall)');
        if ($status < 200 || $status >= 300) throw new \RuntimeException('upstream error (HTTP ' . $status . ')');
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) throw new \RuntimeException('invalid JSON payload');
        return $decoded;
    }

    /** @return array{status:int,body:string} */
    private function defaultTransport(string $url, array $headers): array
    {
        if (class_exists(\AIWorkforce\ApiProviders::class)) {
            $resp = \AIWorkforce\ApiProviders::http($url, $headers);
            return ['status' => (int) ($resp['status'] ?? 0), 'body' => (string) ($resp['body'] ?? '')];
        }
        $context = stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => $this->timeout, 'ignore_errors' => true,
                'header' => implode("\r\n", $headers)],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($url, false, $context);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d+)#', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }
        return ['status' => $status, 'body' => (string) $body];
    }

    private function safeMessage(string $msg): string
    {
        if ($this->apiKey !== '') $msg = str_replace($this->apiKey, '••••', $msg);
        $msg = preg_replace('/(x-api-key:\s*)\S+/i', '$1••••', $msg) ?? $msg;
        return substr($msg, 0, 180);
    }

    private function httpsUrl(string $url): bool
    {
        $parts = parse_url($url);
        return is_array($parts) && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && !empty($parts['host']) && empty($parts['user']) && empty($parts['pass']);
    }

    private function validDate(?string $date): bool
    {
        return is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1;
    }

    private function isList(array $value): bool
    {
        $i = 0;
        foreach (array_keys($value) as $key) if ($key !== $i++) return false;
        return true;
    }
}
