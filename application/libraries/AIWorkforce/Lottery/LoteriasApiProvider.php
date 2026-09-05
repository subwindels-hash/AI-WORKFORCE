<?php
namespace AIWorkforce\Lottery;

/**
 * loteriasapi.com — Spanish lottery (EuroMillions) REST adapter.
 *
 * Vendor contract (https://loteriasapi.com/docs/getting-started and
 * https://loteriasapi.com/docs/results):
 *
 *   Base  https://api.loteriasapi.com/api/v1   <-- the /api prefix is required
 *   Auth  header `X-API-Key: <key>`
 *   GET /results/{game}/latest                       -> { success, data: {draw}, timestamp }
 *   GET /results/{game}/range?from=&to=&page=&limit= -> { success, data: [draw...], meta: {...} }
 *   GET /results/{game}/date/{yyyy-mm-dd}            -> { success, data: [draw...] }
 *   GET /results/{game}?page=&limit=&sort=&order=    -> { success, data: [draw...], meta: {...} }
 *
 * The vendor's marketing pages (and the earlier revision of this adapter)
 * advertise https://api.loteriasapi.com/v1 — that path answers 404
 * `Cannot GET /v1/results/...` for every route, which surfaced in Admin →
 * API Management as "Endpoint not found (HTTP 404)". normalizeBaseUrl()
 * therefore rewrites a pasted /vN base to the real /api/vN root, so both the
 * stored configuration and a copy/paste of the docs URL work.
 *
 * Draw payload (camelCase; amounts are integer cents plus a formatted string):
 *   { id, game: {slug, name}, drawId: "2024003", drawDate: "2024-01-12",
 *     dayOfWeek, year, status: "COMPLETED", combination: [7, 12, 29, 33, 45],
 *     resultData: { estrellas: [3, 9] }, jackpot: "13000000000",
 *     jackpotFormatted: "130.000.000,00 €",
 *     prizes: [{ category: 1, categoryName: "5 + 2 estrellas", winners: 0,
 *                prizeAmount: "13000000000", formattedPrize: "130.000.000,00 €" }] }
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
 *   WINDELS_LOTTERY_LOTERIASAPI_BASE_URL (default https://api.loteriasapi.com/api/v1)
 *   WINDELS_LOTTERY_LOTERIASAPI_GAME     (default euromillones)
 *   WINDELS_LOTTERY_LOTERIASAPI_TIMEOUT  (default 8 seconds)
 */
final class LoteriasApiProvider implements LotteryProvider
{
    /** Real API root — the vendor 404s on the /v1 path its marketing pages advertise. */
    public const DEFAULT_BASE_URL = 'https://api.loteriasapi.com/api/v1';
    public const DEFAULT_GAME = 'euromillones';
    public const DEFAULT_SOURCE = 'loteriasapi.com (SELAE)';

    /** The vendor caps a /range query at 365 days per call. */
    private const MAX_RANGE_DAYS = 364;
    /** Paging guard — bounds how many calls one history sync may cost. */
    private const MAX_PAGES = 20;
    /** Page size asked for; the vendor may cap it lower per plan. */
    private const PAGE_SIZE = 100;
    /**
     * health() and jackpotInfo() both need the latest draw — one status render
     * should cost one request, not two, on plans with a small quota.
     */
    private const LATEST_TTL_SECONDS = 60;

    /** @var array<string,mixed>|null */
    private ?array $latestMemo = null;
    private int $latestMemoAt = 0;
    private ?string $latestEnvelopeTs = null;

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

    /**
     * Canonicalise the base URL onto the root the vendor actually serves.
     *
     * - the marketing host (loteriasapi.com) answers HTML, not JSON;
     * - https://api.loteriasapi.com/v1 (advertised on the marketing pages)
     *   answers 404 on every route — the real root is /api/v1;
     * - a pasted docs URL (…/api/v1/results/euromillones/latest) is reduced
     *   to its versioned root.
     */
    public static function normalizeBaseUrl(string $baseUrl): string
    {
        $baseUrl = trim($baseUrl);
        if ($baseUrl === '') return self::DEFAULT_BASE_URL;
        $baseUrl = rtrim($baseUrl, '/');
        $host = strtolower((string) (parse_url($baseUrl, PHP_URL_HOST) ?? ''));
        if ($host === 'loteriasapi.com' || $host === 'www.loteriasapi.com') return self::DEFAULT_BASE_URL;
        // A custom gateway/proxy stays exactly as the operator configured it.
        if ($host !== 'api.loteriasapi.com') return $baseUrl;

        $path = (string) (parse_url($baseUrl, PHP_URL_PATH) ?? '');
        if (preg_match('#^/api/(v\d+)(?:/.*)?$#', $path, $m)) return 'https://api.loteriasapi.com/api/' . $m[1];
        if (preg_match('#^/(v\d+)(?:/.*)?$#', $path, $m)) return 'https://api.loteriasapi.com/api/' . $m[1];
        return self::DEFAULT_BASE_URL;
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
            $draw = $this->normalizeDraw($this->latest());
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
        // Full history (spec §3): EuroMillions has ~2,300 draws since its
        // 2004 launch, so the backfill cap must comfortably exceed that.
        // The vendor's 365-day /range cap still splits the window into
        // consecutive chunks, so the real bound is the plan's request quota,
        // not this ceiling.
        $limit = min(5000, max(1, $limit));
        $explicitFrom = $this->validDate($from) ? $from : null;
        $explicitTo = $this->validDate($to) ? $to : null;
        $queryTo = $explicitTo ?? gmdate('Y-m-d');
        if ($explicitFrom !== null) {
            $queryFrom = $explicitFrom;
        } else {
            // EuroMillions draws twice a week — ask for a window wide enough
            // to cover `limit` draws plus a margin for schedule changes.
            $weeks = (int) ceil($limit / 2) + 2;
            // Don't reach back before any supported lottery existed: an
            // unbounded 5000-draw backfill otherwise derives a ~48-year window
            // and wastes ~26 empty /range calls (pre-2004) against a plan with
            // a request quota. ~25 years still covers the whole EuroMillions
            // archive from its 2004 launch.
            $weeks = min($weeks, 1300);
            $queryFrom = gmdate('Y-m-d', strtotime($queryTo . ' -' . $weeks . ' weeks'));
        }

        $rows = [];
        foreach ($this->windows($queryFrom, $queryTo) as $window) {
            try {
                $rows = array_merge($rows, $this->rangeRows($window[0], $window[1], max(1, $limit - count($rows))));
            } catch (\Throwable $e) {
                // Keep whatever earlier windows returned; health() reports why
                // the rest is missing rather than inventing draws.
                break;
            }
            if (count($rows) >= $limit) break;
        }
        $fromLatest = false;
        if ($rows === []) {
            // Not every plan exposes /range. The documented listing route
            // (GET /results/{game}?page=&limit=&sort=&order=) is the second
            // source of history before we degrade to a single latest draw.
            try {
                $rows = $this->historyRows($limit);
            } catch (\Throwable $e) {
                $rows = [];
            }
        }
        if ($rows === []) {
            try {
                $single = $this->latest();
                $rows = ($single !== [] && (isset($single['combination']) || isset($single['numbers'])
                    || isset($single['drawDate']) || isset($single['draw_date']))) ? [$single] : [];
                $fromLatest = $rows !== [];
            } catch (\Throwable $e) {
                // No data is preferable to fabricated data; health() reports why.
                return [];
            }
        }

        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            // Only the /latest fallback borrows the envelope timestamp: it is
            // the vendor's own stamp for that single draw.
            $draw = $this->normalizeDraw($row, $fromLatest ? $this->latestEnvelopeTs : null);
            if ($draw['drawDate'] === '') continue;
            // Only an explicit caller window is enforced locally; otherwise the
            // upstream range filter is authoritative.
            if ($explicitFrom !== null && $draw['drawDate'] < $explicitFrom) continue;
            if ($explicitTo !== null && $draw['drawDate'] > $explicitTo) continue;
            // Windowed/paged queries can overlap — one draw id is one draw.
            $key = (string) $draw['externalId'] . '|' . (string) $draw['drawDate'];
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = $draw;
        }
        usort($out, static fn(array $a, array $b): int => strcmp((string) $b['drawDate'], (string) $a['drawDate']));
        return array_slice($out, 0, $limit);
    }

    /** Published jackpot from the feed (never used to infer future outcomes). */
    public function jackpotInfo(): ?array
    {
        if (!$this->configured()) return null;
        try {
            $data = $this->latest();
        } catch (\Throwable $e) {
            return null;
        }
        if ($data === []) return null;

        // Legacy feed: an explicit next-draw amount in euros.
        $next = $data['jackpot_next'] ?? ($data['jackpotNext'] ?? null);
        if (is_numeric($next)) {
            return $this->jackpotPayload(number_format((float) $next, 2, '.', ''), $data);
        }
        // Live API: the pot published with the latest draw (integer cents + a
        // formatted string). When nobody matched 5+2 that pot rolls over.
        $value = $this->parseMoney($data['jackpotFormatted'] ?? null)
            ?? $this->rawAmount($data['jackpot'] ?? null, true);
        if ($value === null) return null;
        return $this->jackpotPayload($value, $data);
    }

    /**
     * A single draw. The vendor keys draws by draw date, so both a date
     * ("2026-04-10") and a vendor draw id ("2026029" / "2026/029") are
     * accepted; an id is resolved through a windowed range query and matched
     * exactly — nothing is inferred from the id itself.
     */
    public function drawById(string $drawId): ?array
    {
        if (!$this->configured()) return null;
        $wanted = strtoupper(trim($drawId));

        if ($this->validDate($wanted)) {
            try {
                $rows = $this->rows($this->get($this->endpoint(
                    '/results/' . rawurlencode($this->game) . '/date/' . rawurlencode($wanted)
                )));
            } catch (\Throwable $e) {
                return null;
            }
            $first = $rows[0] ?? null;
            return is_array($first) ? $this->normalizeDraw($first) : null;
        }

        if (!preg_match('#^(\d{4})/?(\d{1,4})$#', $wanted, $m)) return null;
        $needle = $m[1] . $m[2];
        // EuroMillions draws ~2x/week from the start of the year: bracket the
        // draw date generously, then match the vendor drawId exactly.
        $approx = (int) strtotime(sprintf('%04d-01-01 00:00:00 UTC', (int) $m[1]) . ' +' . max(0, ((int) $m[2] - 1) * 4) . ' days');
        if ($approx <= 0) return null;
        $from = gmdate('Y-m-d', max($approx - 30 * 86400, (int) strtotime($m[1] . '-01-01 00:00:00 UTC') - 86400));
        $to = gmdate('Y-m-d', min($approx + 30 * 86400, (int) strtotime($m[1] . '-12-31 00:00:00 UTC') + 86400));
        try {
            $rows = $this->rangeRows($from, $to, self::PAGE_SIZE);
        } catch (\Throwable $e) {
            return null;
        }
        foreach ($rows as $row) {
            $draw = $this->normalizeDraw($row);
            if (strtoupper(str_replace(['/', '-'], '', (string) $draw['externalId'])) === $needle) return $draw;
        }
        return null;
    }

    // --------------------------------------------------------------- mapping

    /**
     * Paged range query: GET /results/{game}/range?from=&to=&page=&limit=.
     * @return list<array<string,mixed>>
     */
    private function rangeRows(string $from, string $to, int $limit): array
    {
        $rows = [];
        for ($page = 1; $page <= self::MAX_PAGES && count($rows) < $limit; $page++) {
            $payload = $this->get($this->endpoint('/results/' . rawurlencode($this->game) . '/range', [
                'from' => $from,
                'to' => $to,
                'page' => $page,
                'limit' => min(self::PAGE_SIZE, max(1, $limit - count($rows))),
            ]));
            $batch = $this->rows($payload);
            if ($batch === []) break;
            foreach ($batch as $row) $rows[] = $row;
            $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
            if (empty($meta['hasNext'])) break;
        }
        return $rows;
    }

    /**
     * Paged history listing: GET /results/{game}?page=&limit=&sort=&order=.
     * Used when /range yields nothing (plan restriction, empty window) so a
     * history sync still has a real source before falling back to /latest.
     * @return list<array<string,mixed>>
     */
    private function historyRows(int $limit): array
    {
        $rows = [];
        for ($page = 1; $page <= self::MAX_PAGES && count($rows) < $limit; $page++) {
            $payload = $this->get($this->endpoint('/results/' . rawurlencode($this->game), [
                'page' => $page,
                'limit' => min(self::PAGE_SIZE, max(1, $limit - count($rows))),
                'sort' => 'drawDate',
                'order' => 'desc',
            ]));
            $batch = $this->rows($payload);
            if ($batch === []) break;
            foreach ($batch as $row) $rows[] = $row;
            $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
            if (empty($meta['hasNext'])) break;
        }
        return $rows;
    }

    /**
     * The vendor answers at most 365 days per /range call — split longer
     * windows into consecutive chunks (oldest first).
     * @return list<array{0:string,1:string}>
     */
    private function windows(string $from, string $to): array
    {
        if ($from > $to) return [];
        $out = [];
        $cursor = $from;
        for ($guard = 0; $guard < 60 && $cursor <= $to; $guard++) {
            $endTs = (int) strtotime($cursor . ' +' . self::MAX_RANGE_DAYS . ' days');
            $end = $endTs > 0 ? gmdate('Y-m-d', $endTs) : $to;
            if ($end > $to || $end <= $cursor) $end = $to;
            $out[] = [$cursor, $end];
            if ($end === $to) break;
            $nextTs = (int) strtotime($end . ' +1 day');
            if ($nextTs <= 0) break;
            $cursor = gmdate('Y-m-d', $nextTs);
        }
        return $out;
    }

    /** GET /results/{game}/latest → the single draw object (never the envelope). */
    private function latest(): array
    {
        if ($this->latestMemo !== null && (time() - $this->latestMemoAt) < self::LATEST_TTL_SECONDS) {
            return $this->latestMemo;
        }
        $payload = $this->get($this->endpoint('/results/' . rawurlencode($this->game) . '/latest'));
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        // /results/latest (all games) nests one object per game slug.
        if (is_array($data['results'] ?? null) && is_array($data['results'][$this->game] ?? null)) {
            $data = $data['results'][$this->game];
        }
        // Some routes answer a one-element list — unwrap it rather than losing the draw.
        if ($data !== [] && $this->isList($data)) $data = reset($data);
        if (!is_array($data)) return [];
        // Only a successful answer is memoised; failures stay retryable.
        $this->latestMemo = $data;
        $this->latestMemoAt = time();
        $this->latestEnvelopeTs = isset($payload['timestamp']) && is_scalar($payload['timestamp'])
            ? (string) $payload['timestamp'] : null;
        return $data;
    }

    /** @return list<array<string,mixed>> */
    private function rows($payload): array
    {
        if (!is_array($payload)) throw new \RuntimeException('LoteriasAPI returned invalid JSON');
        foreach ([$payload['data'] ?? null, $payload['results'] ?? null, $payload['draws'] ?? null, $payload] as $candidate) {
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
    public function normalizeDraw(array $row, ?string $envelopeTimestamp = null): array
    {
        $resultData = is_array($row['resultData'] ?? null) ? $row['resultData'] : [];
        $meta = is_array($row['meta'] ?? null) ? $row['meta'] : [];
        $main = $this->firstList([$row['combination'] ?? null, $row['numbers'] ?? null, $row['main'] ?? null, $row['combinacion'] ?? null]);
        $stars = $this->firstList([$row['stars'] ?? null, $row['luckyStars'] ?? null, $row['estrellas'] ?? null, $resultData['estrellas'] ?? null]);

        $drawDate = (string) ($row['drawDate'] ?? ($row['draw_date'] ?? ($row['date'] ?? ($row['fecha'] ?? ''))));
        $drawId = (string) ($row['drawId'] ?? ($row['draw_id'] ?? ($row['id'] ?? '')));
        if ($drawId === '' && $drawDate !== '') $drawId = strtoupper($this->game) . '-' . $drawDate;

        $prizes = is_array($row['prizes'] ?? null) ? $row['prizes'] : [];
        $topTier = null;
        $jackpotWinners = null;
        $rollover = false;
        foreach ($prizes as $tier) {
            if (!is_array($tier) || !$this->isTopTier($tier)) continue;
            $topTier = $tier;
            if (isset($tier['winners']) && is_numeric($tier['winners'])) {
                $jackpotWinners = (string) (int) $tier['winners'];
                $rollover = (int) $tier['winners'] === 0;
            }
            break;
        }

        // The live API publishes integer cents (`jackpot`, `prizeAmount`) next
        // to a formatted string (`jackpotFormatted`, `formattedPrize`); the
        // formatted string is authoritative when present.
        $jackpot = $this->parseMoney($row['jackpotFormatted'] ?? null)
            ?? $this->rawAmount($row['jackpot'] ?? null, true);
        if ($jackpot === null && $topTier !== null) {
            $jackpot = $this->parseMoney($topTier['formattedPrize'] ?? null)
                ?? $this->rawAmount($topTier['prizeAmount'] ?? null, true)
                ?? $this->rawAmount($topTier['prize'] ?? null, false);
            // A rolled-over pot is not a zero prize — leave it unset instead.
            if ($jackpot !== null && (float) $jackpot <= 0) $jackpot = null;
        }

        // Full prize breakdown (category, label, winners, amount) so the
        // historical database records prize information and winners where the
        // vendor supplies them (spec §29: only what is reliably supported).
        $prizeRows = [];
        $totalWinners = null;
        foreach ($prizes as $tier) {
            if (!is_array($tier)) continue;
            $winners = isset($tier['winners']) && is_numeric($tier['winners']) ? (int) $tier['winners'] : null;
            if ($winners !== null) $totalWinners = (int) $totalWinners + $winners;
            $prizeRows[] = [
                'category' => isset($tier['category']) && is_scalar($tier['category']) ? (string) $tier['category'] : null,
                'label' => isset($tier['categoryName']) && is_scalar($tier['categoryName'])
                    ? (string) $tier['categoryName']
                    : (isset($tier['match']) && is_scalar($tier['match']) ? (string) $tier['match'] : null),
                'winners' => $winners,
                'amount' => $this->parseMoney($tier['formattedPrize'] ?? null)
                    ?? $this->rawAmount($tier['prizeAmount'] ?? null, true)
                    ?? $this->rawAmount($tier['prize'] ?? null, false),
                'currency' => 'EUR',
            ];
        }

        $timestamp = $row['sourceTimestamp'] ?? ($row['updatedAt'] ?? ($row['createdAt'] ?? ($row['updated_at']
            ?? ($meta['updated_at'] ?? $envelopeTimestamp))));

        return [
            'externalId' => $drawId,
            'drawDate' => $drawDate,
            'main' => $main,
            'stars' => $stars,
            'jackpot' => $jackpot,
            'rollover' => $rollover,
            'winners' => $jackpotWinners,
            'prizes' => $prizeRows,
            'totalWinners' => $totalWinners,
            'source' => $this->source,
            'sourceTimestamp' => is_scalar($timestamp) && trim((string) $timestamp) !== ''
                ? trim((string) $timestamp)
                : ($drawDate !== '' ? $drawDate . 'T21:00:00+00:00' : ''),
            'extra' => [
                'elMillon' => isset($row['el_millon']) && is_scalar($row['el_millon']) ? (string) $row['el_millon']
                    : (isset($resultData['elMillon']) && is_scalar($resultData['elMillon']) ? (string) $resultData['elMillon'] : null),
                'jackpotNext' => isset($row['jackpot_next']) && is_numeric($row['jackpot_next']) ? (float) $row['jackpot_next'] : null,
                'prizeTiers' => count($prizes),
                'feedSource' => isset($meta['source']) && is_scalar($meta['source']) ? (string) $meta['source'] : null,
                'status' => isset($row['status']) && is_scalar($row['status']) ? strtoupper((string) $row['status']) : null,
                'dayOfWeek' => isset($row['dayOfWeek']) && is_scalar($row['dayOfWeek']) ? (string) $row['dayOfWeek'] : null,
            ],
        ];
    }

    /** The 5+2 tier: category 1 / "1" / "1a", match "5+2" or "5 + 2 estrellas". */
    private function isTopTier(array $tier): bool
    {
        $category = strtolower(trim((string) ($tier['category'] ?? '')));
        if ($category === '1' || $category === '1a' || $category === '1ª') return true;
        if (strtoupper(trim((string) ($tier['match'] ?? ''))) === '5+2') return true;
        $name = strtolower((string) ($tier['categoryName'] ?? ''));
        $name = preg_replace('/\s+/', '', $name) ?? $name;
        return str_contains($name, '5+2');
    }

    /** First candidate that is a real list of numbers; otherwise null. */
    private function firstList(array $candidates)
    {
        foreach ($candidates as $candidate) {
            if (is_array($candidate) && $candidate !== []) return array_values($candidate);
        }
        return null;
    }

    /**
     * Vendor amounts. Integer values from `jackpot` / `prizeAmount` are cents
     * (documented vendor behaviour: "13000000000" = 130.000.000,00 €); a value
     * carrying a decimal point is already euros.
     */
    private function rawAmount($value, bool $integerIsCents): ?string
    {
        if (!is_scalar($value)) return null;
        $text = trim((string) $value);
        if ($text === '') return null;
        if (!is_numeric($text)) return $this->parseMoney($text);
        if ($integerIsCents && !str_contains($text, '.') && !str_contains($text, ',')) {
            return number_format(((int) $text) / 100, 2, '.', '');
        }
        return number_format((float) $text, 2, '.', '');
    }

    /**
     * "130.000.000,00 €" → "130000000.00"; "5.000.000,00 €" → "5000000.00".
     * A trailing group of three digits is a thousands group, not decimals.
     */
    private function parseMoney($value): ?string
    {
        if (!is_scalar($value)) return null;
        $text = trim((string) $value);
        if ($text === '') return null;
        $text = preg_replace('/[^\d.,]/u', '', $text) ?? '';
        if ($text === '' || !preg_match('/\d/', $text)) return null;

        $lastComma = strrpos($text, ',');
        $lastDot = strrpos($text, '.');
        $pos = max($lastComma === false ? -1 : $lastComma, $lastDot === false ? -1 : $lastDot);
        $decimals = '';
        if ($pos >= 0) {
            $tail = substr($text, $pos + 1);
            if ($tail !== '' && strlen($tail) <= 2) {
                $decimals = str_pad($tail, 2, '0');
                $text = substr($text, 0, $pos);
            }
        }
        $whole = str_replace(['.', ','], '', $text);
        if ($whole === '' || !ctype_digit($whole)) return null;
        return $whole . '.' . ($decimals !== '' ? $decimals : '00');
    }

    /** @param array<string,mixed> $data */
    private function jackpotPayload(string $value, array $data): array
    {
        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
        $observed = $meta['updated_at'] ?? ($data['timestamp'] ?? ($data['drawDate'] ?? null));
        return [
            'source' => $this->source,
            'value' => $value,
            'currency' => 'EUR',
            'observedAt' => is_scalar($observed) && (string) $observed !== '' ? (string) $observed : gmdate('c'),
            'note' => 'Published jackpot from LoteriasAPI; not used to infer future draw outcomes',
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
        if ($status === 429) throw new \RuntimeException('rate limited (HTTP 429) — the plan request quota is exhausted (limits: https://loteriasapi.com/planes)');
        if ($status === 404) throw new \RuntimeException('not found (HTTP 404) — the base URL must be https://api.loteriasapi.com/api/v1');
        if ($status === 400) throw new \RuntimeException('request rejected (HTTP 400) — check the game code and the date range (max 365 days)');
        if ($status === 0) throw new \RuntimeException('no HTTP response (network/SSL/firewall)');
        if ($status < 200 || $status >= 300) throw new \RuntimeException('upstream error (HTTP ' . $status . ')');
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) throw new \RuntimeException('invalid JSON payload');
        if (array_key_exists('success', $decoded) && $decoded['success'] === false) {
            $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
            $code = strtoupper((string) ($error['code'] ?? ''));
            $message = trim((string) ($error['message'] ?? ''));
            if ($code === 'UNAUTHORIZED' || $code === 'FORBIDDEN') {
                throw new \RuntimeException('authentication rejected (' . ($code !== '' ? $code : 'HTTP ' . $status) . ')');
            }
            throw new \RuntimeException('vendor error' . ($code !== '' ? ' (' . $code . ')' : '')
                . ($message !== '' ? ': ' . $message : ''));
        }
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
