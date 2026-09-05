<?php
namespace AIWorkforce\Sports\Providers;

/**
 * Native adapters for api-football.com, thesportsdb.com and sportmonks.com.
 *
 * Each class implements the provider-neutral SportsDataProvider contract and
 * maps vendor-specific JSON shapes into the WINDELS-normalised payload that
 * SportsDataNormalizer / SportsSyncService persist.  Secrets stay server-side;
 * the browser only ever sees the normalised, masked status.
 *
 * All three vendors are registered automatically by SportsIntelligence when
 * their environment key is present, and may also be managed through
 * Admin → API (the central ApiProviders store).
 */

use AIWorkforce\Sports\Providers\ProviderException;
use AIWorkforce\Sports\Providers\SportsDataProvider;

// ─────────────────────────────────────────────────────────────────────────────
// Shared HTTP transport trait
// ─────────────────────────────────────────────────────────────────────────────

trait HttpTransport
{
    /** @var callable(string $url, array $headers): array{status:int, body:string} */
    private $transport;
    private int $lastResponseMs = 0;
    private int $requests = 0;
    private int $failures = 0;
    private ?string $lastSuccess = null;
    private ?string $lastFailure = null;
    private ?string $lastUrl = null;
    /** @var array<string,mixed>|null last classified failure (redacted) */
    private ?array $lastError = null;
    private ?int $dailyRemaining = null;
    private ?int $dailyLimit = null;
    /**
     * Non-fatal pagination degradations observed by the last call — e.g. an
     * endpoint that advertised more pages but refused the `page` parameter.
     * Surfaced through health()/paginationNotes() so truncated pulls stay
     * visible instead of passing as complete.
     * @var array<int,string>
     */
    private array $paginationNotes = [];

    private function initTransport(int $timeout, ?callable $override = null): void
    {
        $this->transport = $override ?? function (string $url, array $headers) use ($timeout): array {
            $headerList = [];
            foreach ($headers as $h) {
                $h = trim((string) $h);
                if ($h !== '') $headerList[] = $h;
            }
            // Prefer cURL on cPanel hosts where allow_url_fopen is often off.
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                if ($ch !== false) {
                    $respHeaders = [];
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_MAXREDIRS => 3,
                        CURLOPT_CONNECTTIMEOUT => max(3, min(30, $timeout)),
                        CURLOPT_TIMEOUT => max(3, min(60, $timeout)),
                        CURLOPT_HTTPHEADER => $headerList,
                        CURLOPT_USERAGENT => 'WINDELS-Sports/1.0',
                        CURLOPT_SSL_VERIFYPEER => true,
                        CURLOPT_SSL_VERIFYHOST => 2,
                        CURLOPT_ENCODING => '',
                        // Response headers carry the vendor's quota counters
                        // (api-football: x-ratelimit-requests-remaining).
                        CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$respHeaders): int {
                            $pos = strpos($line, ':');
                            if ($pos !== false) $respHeaders[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                            return strlen($line);
                        },
                    ]);
                    $body = curl_exec($ch);
                    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $errno = (int) curl_errno($ch);
                    $error = (string) curl_error($ch);
                    curl_close($ch);
                    if ($body !== false) {
                        return ['status' => $status, 'body' => (string) $body, 'headers' => $respHeaders];
                    }
                    if ($status > 0) {
                        return ['status' => $status, 'body' => '', 'headers' => $respHeaders];
                    }
                    return ['status' => 0, 'body' => '', 'headers' => [], 'errno' => $errno, 'error' => $error];
                }
            }
            if (!ini_get('allow_url_fopen')) {
                return ['status' => 0, 'body' => '', 'error' => 'no HTTP client available (curl missing, allow_url_fopen off)'];
            }
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => $timeout,
                    'header' => implode("\r\n", $headerList),
                    'ignore_errors' => true,
                ],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $t0 = microtime(true);
            $body = @file_get_contents($url, false, $ctx);
            $status = 0;
            $respHeaders = [];
            if (isset($http_response_header) && is_array($http_response_header)) {
                foreach ($http_response_header as $line) {
                    if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) { $status = (int) $m[1]; continue; }
                    $pos = strpos($line, ':');
                    if ($pos !== false) $respHeaders[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                }
            }
            $out = ['status' => $status, 'body' => is_string($body) ? $body : '', 'headers' => $respHeaders];
            if ($status === 0 && (microtime(true) - $t0) >= $timeout) $out['error'] = 'timed out after ' . $timeout . 's';
            return $out;
        };
    }

    protected function doGet(string $url, array $headers): array
    {
        $this->requests++;
        $t0 = microtime(true);
        try {
            $resp = ($this->transport)($url, $headers);
        } catch (\Throwable $e) {
            $this->failures++;
            $this->lastFailure = gmdate('c');
            throw new ProviderException('provider transport failure: ' . ProviderHttp::redact($e->getMessage()), ProviderException::OFFLINE, $e, ProviderHttp::diagnostic($url, 'GET', 0, ''));
        }
        $this->lastResponseMs = (int) round((microtime(true) - $t0) * 1000);
        $this->lastUrl = ProviderHttp::redactUrl($url);
        $respHeaders = is_array($resp['headers'] ?? null) ? $resp['headers'] : [];
        $this->noteRateLimitHeaders($respHeaders);
        if (!isset($resp['error']) && (int) ($resp['status'] ?? 0) === 0 && $this->lastResponseMs >= $this->timeoutSeconds() * 1000) {
            $resp['error'] = 'timed out after ' . $this->timeoutSeconds() . 's';
        }
        $failure = ProviderHttp::classify(is_array($resp) ? $resp : [], $url, 'GET');
        if ($failure !== null) {
            $this->failures++;
            $this->lastFailure = gmdate('c');
            $this->lastError = ['status' => $failure->status, 'message' => $failure->getMessage()] + $failure->details;
            throw $failure->withDetails(['provider' => $this->id()]);
        }
        $this->lastSuccess = gmdate('c');
        return $resp;
    }

    /** Remember the vendor's quota counters when it sends them. */
    private function noteRateLimitHeaders(array $headers): void
    {
        $h = [];
        foreach ($headers as $k => $v) $h[strtolower((string) $k)] = is_array($v) ? (string) end($v) : (string) $v;
        if (isset($h['x-ratelimit-requests-remaining']) && is_numeric($h['x-ratelimit-requests-remaining'])) $this->dailyRemaining = (int) $h['x-ratelimit-requests-remaining'];
        if (isset($h['x-ratelimit-requests-limit']) && is_numeric($h['x-ratelimit-requests-limit'])) $this->dailyLimit = (int) $h['x-ratelimit-requests-limit'];
    }

    /** Last classified failure (redacted), for diagnostics — never the credential. */
    public function lastError(): ?array
    {
        return $this->lastError;
    }

    private function timeoutSeconds(): int
    {
        return property_exists($this, 'timeout') ? (int) $this->timeout : 10;
    }

    /** Health fields shared by every adapter (quota counters, last error, timings). */
    private function baseHealth(): array
    {
        $h = [
            'reliability' => $this->reliability(),
            'errorRate' => $this->errorRate(),
            'responseMs' => $this->lastResponseMs,
            'lastSuccessAt' => $this->lastSuccess,
            'lastFailureAt' => $this->lastFailure,
        ];
        if ($this->dailyRemaining !== null) $h['rateLimitRemaining'] = $this->dailyRemaining;
        if ($this->dailyLimit !== null) $h['limitDaily'] = $this->dailyLimit;
        if ($this->lastError !== null) $h['lastError'] = $this->lastError;
        return $h;
    }

    /** Health payload for a classified failure. */
    private function failedHealth(ProviderException $e): array
    {
        $h = $this->baseHealth() + ['status' => $e->status, 'detail' => $e->getMessage()];
        $h['lastFailureAt'] = $this->lastFailure ?? gmdate('c');
        if (isset($e->details['retryAt'])) $h['retryAt'] = $e->details['retryAt'];
        if (isset($e->details['endpoint'])) $h['endpoint'] = $e->details['endpoint'];
        if (isset($e->details['httpStatus'])) $h['httpStatus'] = $e->details['httpStatus'];
        return $h;
    }

    /**
     * Pagination degradations recorded since this object was created, newest
     * last. A truncated pull is still a pull: callers keep the rows, the
     * reason they are incomplete is never dropped.
     *
     * @return array<int,string>
     */
    public function paginationNotes(): array
    {
        return $this->paginationNotes;
    }

    /** Forget recorded pagination notes (start of a fresh diagnostic round). */
    public function resetPaginationNotes(): void
    {
        $this->paginationNotes = [];
    }

    protected function decodeJson(array $resp): array
    {
        $decoded = json_decode($resp['body'] ?? '', true);
        if (!is_array($decoded)) throw new ProviderException('invalid JSON response', ProviderException::DATA_ERROR);
        // api-football answers some failures (missing key, bad parameters)
        // with HTTP 200 and a non-empty `errors` object. Surface it as a
        // classified error instead of letting callers read the empty
        // `response` as "no data" (a blank key must never read as ONLINE).
        if (!empty($decoded['errors'])) {
            $errors = (array) $decoded['errors'];
            $field = key($errors);      // api-football keys soft errors by the offending parameter
            $first = current($errors);
            $msg = is_string($first) ? $first : json_encode($decoded['errors']);
            // api-football: errors.token → bad key; errors.requests → "You have
            // reached the request limit for the day." (HTTP 200!). The latter
            // must open the circuit until 00:00 UTC, not read as a data error.
            $status = ProviderHttp::classifySoftError(is_string($field) ? $field : '', (string) $msg);
            $extra = $status === ProviderException::DAILY_QUOTA_EXHAUSTED ? ['retryAt' => gmdate('c', ProviderHttp::nextUtcMidnight())] : [];
            $hint = '';
            if (str_contains(strtolower((string) $msg), 'from field')) {
                $hint = ' — api-football from/to requires league+season or team+season (e.g. &league=39&season=2026). '
                    . 'For all fixtures in a date range omit league/season (per-day date mode is used) or pass &date=YYYY-MM-DD for a single day.';
            }
            throw new ProviderException(
                'provider error: ' . ProviderHttp::redact(mb_substr((string) $msg, 0, 200)) . $hint,
                $status,
                null,
                (is_string($field) && $field !== '' ? ['errorField' => $field] : []) + $extra + ($this->lastUrl !== null ? ['endpoint' => $this->lastUrl] : []),
            );
        }
        return $decoded;
    }

    protected function extractList(array $json): array
    {
        foreach (['response', 'data', 'events'] as $key) {
            if (isset($json[$key]) && is_array($json[$key])) return $json[$key];
        }
        return array_is_list($json) ? $json : [];
    }

    private function errorRate(): float
    {
        return $this->requests > 0 ? round($this->failures / $this->requests, 4) : 0.0;
    }

    private function reliability(): float
    {
        return round(1 - $this->errorRate(), 4);
    }

    /**
     * Normalize a provider-specific market name to the pipeline's canonical names.
     * The prediction pipeline expects: TOTAL_GOALS, MATCH_RESULT, BOTH_TEAMS_SCORE, etc.
     */
    protected static function normalizeMarket(string $raw): string
    {
        $r = strtolower(trim($raw));
        // Total Goals / Over-Under
        if (preg_match('/over.?under|total.?goals|goals.?over|goals.?total/', $r)) return 'TOTAL_GOALS';
        // Match Result / 1X2 / Match Winner
        if (preg_match('/match.?result|match.?winner|1x2|full.?time.?result|result/', $r)) return 'MATCH_RESULT';
        // Both Teams to Score
        if (preg_match('/both.?teams|btts|goal.*goal/', $r)) return 'BOTH_TEAMS_SCORE';
        // Double Chance
        if (preg_match('/double.?chance/', $r)) return 'DOUBLE_CHANCE';
        // Correct Score
        if (preg_match('/correct.?score|exact.?score/', $r)) return 'CORRECT_SCORE';
        // Half Time / HT
        if (preg_match('/half.?time|ht/', $r) && preg_match('/over|under|goal/', $r)) return 'HALF_TIME_GOALS';
        // Return as-is but uppercased
        return strtoupper(preg_replace('/[^A-Za-z0-9_]/', '_', $raw));
    }

    /**
     * Normalize a provider-specific selection name to the pipeline's canonical names.
     * For TOTAL_GOALS: OVER_1_5, UNDER_1_5, OVER_2_5, UNDER_2_5, etc.
     * For MATCH_RESULT: HOME, DRAW, AWAY
     */
    protected static function normalizeSelection(string $market, string $raw): string
    {
        $r = strtolower(trim($raw));
        if ($market === 'TOTAL_GOALS' || $market === 'HALF_TIME_GOALS') {
            // Match "Over 1.5", "Over 1,5", "Over1.5", "1.5 Over", etc.
            if (preg_match('/over.*?(\d+[.,]\d+)/', $r, $m)) {
                $line = str_replace(',', '.', $m[1]);
                return 'OVER_' . str_replace('.', '_', $line);
            }
            if (preg_match('/under.*?(\d+[.,]\d+)/', $r, $m)) {
                $line = str_replace(',', '.', $m[1]);
                return 'UNDER_' . str_replace('.', '_', $line);
            }
            if (preg_match('/(\d+[.,]\d+).*over/', $r, $m)) {
                $line = str_replace(',', '.', $m[1]);
                return 'OVER_' . str_replace('.', '_', $line);
            }
            if (preg_match('/(\d+[.,]\d+).*under/', $r, $m)) {
                $line = str_replace(',', '.', $m[1]);
                return 'UNDER_' . str_replace('.', '_', $line);
            }
        }
        if ($market === 'MATCH_RESULT') {
            if (preg_match('/home|1$/', $r)) return 'HOME';
            if (preg_match('/draw|x$/', $r)) return 'DRAW';
            if (preg_match('/away|2$/', $r)) return 'AWAY';
        }
        if ($market === 'BOTH_TEAMS_SCORE') {
            if (preg_match('/yes|1/', $r)) return 'YES';
            if (preg_match('/no|0/', $r)) return 'NO';
        }
        return strtoupper(preg_replace('/[^A-Za-z0-9_.]/', '_', $raw));
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. API-FOOTBALL (api-football.com / api-sports.io)
//    Docs: https://www.api-football.com/documentation-v3
// ─────────────────────────────────────────────────────────────────────────────

class ApiFootballProvider implements SportsDataProvider
{
    use HttpTransport;

    public function __construct(
        private string $apiKey,
        private string $baseUrl = 'https://v3.football.api-sports.io',
        private int $timeout = 10,
        ?callable $transport = null,
    ) {
        // Always canonicalize marketing hosts (api-football.com / football.com)
        // onto the real v3 API root so a saved website URL cannot break live calls.
        if (class_exists(\AIWorkforce\ApiProviders::class)) {
            $this->baseUrl = \AIWorkforce\ApiProviders::normalizeApiFootballBaseUrl($this->baseUrl);
        } else {
            $host = strtolower((string) (parse_url(
                preg_match('#^https?://#i', $this->baseUrl) ? $this->baseUrl : 'https://' . $this->baseUrl,
                PHP_URL_HOST
            ) ?? ''));
            if ($host === '' || str_contains($host, 'api-football.com') || $host === 'football.com' || $host === 'www.football.com') {
                $this->baseUrl = 'https://v3.football.api-sports.io';
            }
        }
        $this->initTransport($timeout, $transport);
    }

    public function id(): string { return 'api-football'; }

    public function health(): array
    {
        try {
            // /status does not count against the daily quota and returns the
            // plan's counters — the authoritative place to detect exhaustion
            // BEFORE spending a paid request on fixtures.
            $resp = $this->doRequest('/status');
            $json = $this->decodeJson($resp);
            $rateInfo = $json['response'] ?? [];
            $requests = $rateInfo['requests'] ?? null;
            $used = is_array($requests)
                ? ($requests['current'] ?? $requests['used'] ?? null)
                : $requests;
            $limit = is_array($requests)
                ? ($requests['limit_day'] ?? null)
                : ($rateInfo['limit_day'] ?? null);
            $plan = is_array($rateInfo['subscription'] ?? null) ? ($rateInfo['subscription']['plan'] ?? null) : null;
            $active = is_array($rateInfo['subscription'] ?? null) ? ($rateInfo['subscription']['active'] ?? null) : null;
            $health = $this->baseHealth() + [
                'status' => 'ONLINE',
                'requestsToday' => $used,
                'limitDaily' => $limit,
                'plan' => $plan,
            ];
            if (is_numeric($used) && is_numeric($limit) && (int) $limit > 0) {
                $health['rateLimitRemaining'] = max(0, (int) $limit - (int) $used);
                if ((int) $used >= (int) $limit) {
                    $health['status'] = ProviderException::DAILY_QUOTA_EXHAUSTED;
                    $health['detail'] = sprintf('daily quota used (%d/%d on the %s plan); resets 00:00 UTC', (int) $used, (int) $limit, (string) ($plan ?? 'current'));
                    $health['retryAt'] = gmdate('c', ProviderHttp::nextUtcMidnight());
                    return $health;
                }
            }
            if ($active === false) {
                $health['status'] = ProviderException::AUTHENTICATION_ERROR;
                $health['detail'] = 'api-football subscription is not active';
                return $health;
            }
            // A truncated pull still passes as ONLINE; the truncation is
            // reported alongside it so it cannot hide behind a green status.
            if ($this->paginationNotes !== []) $health['paginationNotes'] = $this->paginationNotes;
            return $health;
        } catch (ProviderException $e) {
            return $this->failedHealth($e);
        }
    }

    public function fixtures(array $query): array
    {
        // Direct date query (single day) — the documented way to fetch all
        // fixtures for a calendar day across leagues.
        if (!empty($query['date'])) {
            $date = (string) $query['date'];
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                throw new ProviderException('fixtures: date must be YYYY-MM-DD', ProviderException::DATA_ERROR);
            }
            $params = ['date' => $date];
            if (!empty($query['league'])) $params['league'] = (string) $query['league'];
            if (!empty($query['season'])) $params['season'] = (string) $query['season'];
            if (!empty($query['team'])) $params['team'] = (string) $query['team'];
            if (!empty($query['status'])) $params['status'] = (string) $query['status'];
            if (!empty($query['timezone'])) $params['timezone'] = (string) $query['timezone'];
            if (!empty($query['round'])) $params['round'] = (string) $query['round'];
            if (!empty($query['venue'])) $params['venue'] = (string) $query['venue'];
            $rows = $this->fetchAllPages('/fixtures', $params);
            return $this->mapFixtures($rows);
        }

        $from = (string) ($query['from'] ?? gmdate('Y-m-d'));
        $to = (string) ($query['to'] ?? $from);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) !== 1) {
            throw new ProviderException('fixtures: from/to must be YYYY-MM-DD dates', ProviderException::DATA_ERROR);
        }

        // api-football's /fixtures?from=&to= requires a disambiguating
        // filter (league+season, team+season, etc.). When none is provided
        // the API answers 200 with errors.from = "The From field need
        // another parameter." — that surfaced as a provider error and the
        // sync stored nothing. For the common "all fixtures in a date
        // range" case (cron, daily ticket, smoke, generic sync) we fall
        // back to per-day date queries, which IS the documented way to
        // fetch all fixtures for a calendar day:
        //   GET /fixtures?date=YYYY-MM-DD
        // (optionally combined with league/season/team/status).
        $hasDisambiguatingFilter = !empty($query['league']) || !empty($query['season'])
            || !empty($query['team']) || !empty($query['round']) || !empty($query['venue'])
            || !empty($query['ids']) || !empty($query['live']) || !empty($query['next'])
            || !empty($query['last']);

        if ($hasDisambiguatingFilter) {
            $params = ['from' => $from, 'to' => $to];
            if (!empty($query['league'])) $params['league'] = (string) $query['league'];
            if (!empty($query['season'])) $params['season'] = (string) $query['season'];
            if (!empty($query['team'])) $params['team'] = (string) $query['team'];
            if (!empty($query['status'])) $params['status'] = (string) $query['status'];
            if (!empty($query['timezone'])) $params['timezone'] = (string) $query['timezone'];
            if (!empty($query['round'])) $params['round'] = (string) $query['round'];
            if (!empty($query['venue'])) $params['venue'] = (string) $query['venue'];
            $rows = $this->fetchAllPages('/fixtures', $params);
            return $this->mapFixtures($rows);
        }

        // No disambiguating filter: use per-day date queries.
        $start = strtotime($from . ' 00:00:00 UTC');
        $end = strtotime($to . ' 00:00:00 UTC');
        $days = (int) floor(($end - $start) / 86400);
        if ($days < 0) {
            throw new ProviderException('fixtures: from must not be after to', ProviderException::DATA_ERROR);
        }
        if ($days > 31) {
            throw new ProviderException(
                'fixtures: from/to range without league/team/season is limited to 31 days — narrow the window or add league & season filters',
                ProviderException::DATA_ERROR
            );
        }
        $allRows = [];
        $current = $from;
        $guard = 0;
        while ($current <= $to && $guard++ < 32) {
            $params = ['date' => $current];
            if (!empty($query['status'])) $params['status'] = (string) $query['status'];
            if (!empty($query['timezone'])) $params['timezone'] = (string) $query['timezone'];
            if (!empty($query['league'])) $params['league'] = (string) $query['league'];
            if (!empty($query['season'])) $params['season'] = (string) $query['season'];
            if (!empty($query['team'])) $params['team'] = (string) $query['team'];
            if (!empty($query['round'])) $params['round'] = (string) $query['round'];
            if (!empty($query['venue'])) $params['venue'] = (string) $query['venue'];
            $rows = $this->fetchAllPages('/fixtures', $params);
            $allRows = array_merge($allRows, $rows);
            $current = gmdate('Y-m-d', strtotime($current . ' +1 day'));
        }
        return $this->mapFixtures($allRows);
    }

    public function odds(string $fixtureExternalId): array
    {
        // /odds paginates at 10 rows per page — a fixture with many bookmaker
        // markets needs several pages; following them keeps the odds complete.
        $rows = $this->fetchAllPages('/odds', ['fixture' => $fixtureExternalId]);
        return $this->mapOdds($rows);
    }

    public function results(string $fixtureExternalId): array
    {
        $resp = $this->doRequest('/fixtures?id=' . rawurlencode($fixtureExternalId));
        $rows = $this->extractList($this->decodeJson($resp));
        return $this->mapResults($rows);
    }

    /** Fetch standings for a league + season. */
    public function standings(string $leagueId, string $season): array
    {
        $resp = $this->doRequest('/standings?league=' . rawurlencode($leagueId) . '&season=' . rawurlencode($season));
        $rows = $this->extractList($this->decodeJson($resp));
        $out = [];
        foreach ($rows as $row) {
            $league = $row['league'] ?? [];
            foreach (($league['standings'] ?? []) as $tierRows) {
                foreach ($tierRows as $entry) {
                    $team = $entry['team'] ?? [];
                    $all = is_array($entry['all'] ?? null) ? $entry['all'] : [];
                    $home = is_array($entry['home'] ?? null) ? $entry['home'] : [];
                    $away = is_array($entry['away'] ?? null) ? $entry['away'] : [];
                    $out[] = [
                        'leagueId' => $leagueId,
                        'season' => $season,
                        'rank' => (int) ($entry['rank'] ?? 0),
                        'team' => $team['name'] ?? '',
                        'teamId' => (string) ($team['id'] ?? ''),
                        'played' => (int) ($all['played'] ?? 0),
                        'wins' => (int) ($all['win'] ?? 0),
                        'draws' => (int) ($all['draw'] ?? 0),
                        'losses' => (int) ($all['lose'] ?? 0),
                        'goalsFor' => (int) ($all['goals']['for'] ?? 0),
                        'goalsAgainst' => (int) ($all['goals']['against'] ?? 0),
                        'points' => (int) ($entry['points'] ?? 0),
                        // Venue splits — the football model needs home vs away
                        // rates, and the same response already carries them.
                        'homePlayed' => isset($home['played']) ? (int) $home['played'] : null,
                        'homeWins' => isset($home['win']) ? (int) $home['win'] : null,
                        'homeDraws' => isset($home['draw']) ? (int) $home['draw'] : null,
                        'homeLosses' => isset($home['lose']) ? (int) $home['lose'] : null,
                        'homeGoalsFor' => isset($home['goals']['for']) ? (int) $home['goals']['for'] : null,
                        'homeGoalsAgainst' => isset($home['goals']['against']) ? (int) $home['goals']['against'] : null,
                        'awayPlayed' => isset($away['played']) ? (int) $away['played'] : null,
                        'awayWins' => isset($away['win']) ? (int) $away['win'] : null,
                        'awayDraws' => isset($away['draw']) ? (int) $away['draw'] : null,
                        'awayLosses' => isset($away['lose']) ? (int) $away['lose'] : null,
                        'awayGoalsFor' => isset($away['goals']['for']) ? (int) $away['goals']['for'] : null,
                        'awayGoalsAgainst' => isset($away['goals']['against']) ? (int) $away['goals']['against'] : null,
                        'group' => (string) ($league['name'] ?? ''),
                    ];
                }
            }
        }
        return $out;
    }

    /** Fetch team statistics for a given league/season. */
    public function teamStatistics(string $teamId, string $leagueId, string $season): array
    {
        $resp = $this->doRequest('/teams/statistics?team=' . rawurlencode($teamId) . '&league=' . rawurlencode($leagueId) . '&season=' . rawurlencode($season));
        $json = $this->decodeJson($resp);
        $data = $json['response'] ?? [];
        $out = ['teamId' => $teamId, 'leagueId' => $leagueId, 'season' => $season];
        $fixtures = $data['fixtures'] ?? [];
        $goals = $data['goals'] ?? [];
        $out['played'] = (int) ($fixtures['played']['total'] ?? 0);
        $out['winsHome'] = (int) ($fixtures['wins']['home'] ?? 0);
        $out['winsAway'] = (int) ($fixtures['wins']['away'] ?? 0);
        $out['goalsForTotal'] = (int) ($goals['for']['total']['total'] ?? 0);
        $out['goalsAgainstTotal'] = (int) ($goals['against']['total']['total'] ?? 0);
        // NOTE: `??` binds looser than `+`, so the previous inline average of
        // home/away goals silently read only one side of the response. Each
        // side is now fetched through num(), then combined explicitly.
        $avg = static function (array $branch, string $side): ?float {
            $value = $branch['average'][$side] ?? null;
            return is_numeric($value) ? (float) $value : null;
        };
        $forAverage = [$avg($goals['for'] ?? [], 'home'), $avg($goals['for'] ?? [], 'away')];
        $againstAverage = [$avg($goals['against'] ?? [], 'home'), $avg($goals['against'] ?? [], 'away')];
        $mean = static function (array $values): ?float {
            $kept = array_values(array_filter($values, static fn($v) => $v !== null));
            return $kept === [] ? null : round(array_sum($kept) / count($kept), 3);
        };
        $out['goalsForAverage'] = $mean($forAverage);
        $out['goalsAgainstAverage'] = $mean($againstAverage);
        // Venue detail the football model uses directly (rate per match at home
        // / away), kept null when the provider did not answer that field.
        $out['goalsForHomeAverage'] = $forAverage[0];
        $out['goalsForAwayAverage'] = $forAverage[1];
        $out['goalsAgainstHomeAverage'] = $againstAverage[0];
        $out['goalsAgainstAwayAverage'] = $againstAverage[1];
        $out['playedHome'] = isset($fixtures['played']['home']) ? (int) $fixtures['played']['home'] : null;
        $out['playedAway'] = isset($fixtures['played']['away']) ? (int) $fixtures['played']['away'] : null;
        $out['drawsHome'] = isset($fixtures['draws']['home']) ? (int) $fixtures['draws']['home'] : null;
        $out['drawsAway'] = isset($fixtures['draws']['away']) ? (int) $fixtures['draws']['away'] : null;
        $out['losesHome'] = isset($fixtures['loses']['home']) ? (int) $fixtures['loses']['home'] : null;
        $out['losesAway'] = isset($fixtures['loses']['away']) ? (int) $fixtures['loses']['away'] : null;
        return $out;
    }

    /**
     * Top players for a league + season (api-football /players/top*).
     *
     * Docs: https://www.api-football.com/documentation-v3 (Players tag)
     *   'scorers'      → /players/topscorers
     *   'assists'      → /players/topassists
     *   'yellow_cards' → /players/topyellowcards
     *   'red_cards'    → /players/topredcards
     *
     * `league` and `season` are required by the API. The response holds the
     * top 20 players in ranked order, each with their full per-season
     * statistical profile — sourced from the provider, never fabricated.
     *
     * @param string $leagueId
     * @param string $season
     * @param string $type scorers|assists|yellow_cards|red_cards
     * @return array{leagueId:string, season:string, type:string, league:?string,
     *               players:array<int,array<string,mixed>>}
     */
    public function topPlayers(string $leagueId, string $season, string $type = 'scorers'): array
    {
        $path = match ($type) {
            'scorers' => 'topscorers',
            'assists' => 'topassists',
            'yellow_cards' => 'topyellowcards',
            'red_cards' => 'topredcards',
            default => throw new ProviderException('unsupported top players type: ' . $type, ProviderException::DATA_ERROR),
        };
        $resp = $this->doRequest('/players/' . $path . '?league=' . rawurlencode($leagueId) . '&season=' . rawurlencode($season));
        $rows = $this->extractList($this->decodeJson($resp));
        $out = ['leagueId' => $leagueId, 'season' => $season, 'type' => $type, 'league' => null, 'players' => []];
        $rank = 0;
        foreach ($rows as $r) {
            $player = is_array($r['player'] ?? null) ? $r['player'] : [];
            $name = trim((string) ($player['name'] ?? ''));
            if ($name === '') continue;
            $league = is_array($r['league'] ?? null) ? $r['league'] : [];
            if ($out['league'] === null && trim((string) ($league['name'] ?? '')) !== '') {
                $out['league'] = (string) $league['name'];
            }
            $rank++;
            $stats = $this->mapPlayerStatistics($r['statistics'] ?? [], $type);
            $out['players'][] = [
                'rank' => $rank,
                'playerId' => (string) ($player['id'] ?? ''),
                'name' => $name,
                'position' => (string) ($player['position'] ?? ''),
                'nationality' => (string) ($player['nationality'] ?? ''),
                'team' => (string) ($player['team']['name'] ?? ''),
                'teamId' => (string) ($player['team']['id'] ?? ''),
                'photo' => $player['photo'] ?? null,
                'value' => $stats['value'],
                'statistics' => $stats['profile'],
            ];
        }
        return $out;
    }

    /**
     * Normalize a top-players statistics payload into a keyed profile plus the
     * headline number for the ranked metric.
     *
     * Canonical shape: a list of {"type": "Yellow Cards", "value": 7} entries.
     * Some v3 payloads embed a season-statistics object instead ({"cards":
     * {"yellow": 7, ...}}) — the known keys of that shape are flattened too.
     */
    private function mapPlayerStatistics(mixed $statistics, string $type): array
    {
        $profile = [];
        if (is_array($statistics) && array_is_list($statistics)) {
            foreach ($statistics as $entry) {
                if (!is_array($entry)) continue;
                $val = $this->statValue($entry['value'] ?? null);
                if (isset($entry['type']) && $val !== null) {
                    $profile[trim((string) $entry['type'])] = $val;
                } else {
                    $profile += $this->flattenSeasonStatistics($entry);
                }
            }
        } elseif (is_array($statistics)) {
            $profile = $this->flattenSeasonStatistics($statistics);
        }
        $headline = match ($type) {
            'scorers' => 'Goals',
            'assists' => 'Assists',
            'yellow_cards' => 'Yellow Cards',
            'red_cards' => 'Red Cards',
            default => null,
        };
        return ['profile' => $profile, 'value' => $headline !== null ? ($profile[$headline] ?? null) : null];
    }

    /** Flatten the known keys of a nested season-statistics object. */
    private function flattenSeasonStatistics(array $s): array
    {
        $out = [];
        $flat = ['played' => 'Played', 'goals' => 'Goals', 'assists' => 'Assists'];
        foreach ($flat as $key => $label) {
            $v = $s[$key] ?? null;
            if (is_array($v)) $v = $v['total'] ?? null;
            $val = $this->statValue($v);
            if ($val !== null) $out[$label] = $val;
        }
        $cards = is_array($s['cards'] ?? null) ? $s['cards'] : [];
        $cardMap = ['yellow' => 'Yellow Cards', 'red' => 'Red Cards', 'yellowred' => 'Yellow-Red Cards'];
        foreach ($cardMap as $key => $label) {
            $val = $this->statValue($cards[$key] ?? null);
            if ($val !== null) $out[$label] = $val;
        }
        $fouls = is_array($s['fouls'] ?? null) ? $s['fouls'] : [];
        $foulMap = ['drawn' => 'Fouls Drawn', 'committed' => 'Fouls Committed'];
        foreach ($foulMap as $key => $label) {
            $val = $this->statValue($fouls[$key] ?? null);
            if ($val !== null) $out[$label] = $val;
        }
        $penalty = is_array($s['penalty'] ?? null) ? $s['penalty'] : [];
        $penaltyMap = ['won' => 'Penalty Won', 'scored' => 'Penalty Scored', 'missed' => 'Penalty Missed', 'saved' => 'Penalty Saved'];
        foreach ($penaltyMap as $key => $label) {
            $val = $this->statValue($penalty[$key] ?? null);
            if ($val !== null) $out[$label] = $val;
        }
        return $out;
    }

    /** int|float when numeric (whole numbers stay ints), null otherwise. */
    private function statValue(mixed $v): int|float|null
    {
        if (!is_numeric($v)) return null;
        $f = (float) $v;
        if (!is_finite($f)) return null;
        return ($f == floor($f)) ? (int) $f : $f;
    }

    /** Fetch available leagues for football. */
    public function leagues(?string $country = null): array
    {
        $params = $country ? ['country' => $country] : [];
        $rows = $this->fetchAllPages('/leagues', $params);
        return array_map(fn($r) => [
            'leagueId' => (string) ($r['league']['id'] ?? ''),
            'name' => $r['league']['name'] ?? '',
            'country' => $r['country']['name'] ?? '',
            'seasons' => array_map(fn($s) => (string) ($s['year'] ?? ''), $r['seasons'] ?? []),
        ], $rows);
    }

    /**
     * Fetch every page of a paginated list endpoint.
     *
     * api-football v3 answers every list endpoint with `paging: {current,
     * total}`; the ones that actually paginate (/players 20/page, /odds
     * 10/page, ...) take a `page` parameter. The ones that do not — /fixtures
     * returns a whole day or a whole season in one response, /leagues returns
     * everything — reject an explicit `page` with HTTP 200 and
     * `errors: {"page": "The Page field do not exist."}`.
     *
     * Page 1 is the provider default, so the first request never sends
     * `page`: sending `page=1` failed the entire call on the non-paginated
     * endpoints (a whole matchday lost over an optional parameter). Follow-up
     * requests send `page=N` from 2 upwards while `current < total`, hard-
     * capped at 40 pages so a bad `total` cannot loop.
     *
     * If a follow-up page is rejected because the endpoint does not accept
     * `page`, the rows collected so far are kept and the truncation is
     * recorded in paginationNotes() — the data we did receive is real and
     * usable, and losing it would make the whole sync report "nothing stored".
     * Any other provider error still propagates.
     */
    private function fetchAllPages(string $path, array $params, int $maxPages = 40): array
    {
        $rows = [];
        $page = 1;
        do {
            $query = $page > 1 ? $params + ['page' => $page] : $params;
            try {
                $json = $this->decodeJson($this->doRequest($path . ($query === [] ? '' : '?' . http_build_query($query))));
            } catch (ProviderException $e) {
                if ($page > 1 && $this->isRejectedPageParameter($e)) {
                    $this->notePagination($path, $page, count($rows), $e->getMessage());
                    break;
                }
                throw $e;
            }
            $rows = array_merge($rows, $this->extractList($json));
            $paging = is_array($json['paging'] ?? null) ? $json['paging'] : [];
            $current = (int) ($paging['current'] ?? $page);
            $total = (int) ($paging['total'] ?? 1);
            $page++;
        } while ($current < $total && $page <= $maxPages);
        return $rows;
    }

    /**
     * True when the provider refused the `page` query parameter itself (an
     * endpoint that does not paginate), as opposed to any other failure.
     * Keyed off the machine-readable error field first, with the message as a
     * fallback for providers that key their errors differently.
     */
    private function isRejectedPageParameter(ProviderException $e): bool
    {
        $field = strtolower((string) ($e->details['errorField'] ?? ''));
        if ($field === 'page' || $field === 'pages') return true;
        $msg = strtolower($e->getMessage());
        return str_contains($msg, 'page') && str_contains($msg, 'field') && str_contains($msg, 'exist');
    }

    /** Record a truncated pull (bounded: the newest 10 notes are kept). */
    private function notePagination(string $path, int $page, int $kept, string $reason): void
    {
        $this->paginationNotes[] = sprintf(
            '%s: page %d refused (%s) — %d row(s) kept, remaining pages not read',
            $path,
            $page,
            mb_substr(preg_replace('/^provider error:\s*/i', '', $reason) ?: $reason, 0, 120),
            $kept,
        );
        $this->paginationNotes = array_slice($this->paginationNotes, -10);
    }

    private function doRequest(string $path): array
    {
        $headers = [
            'Accept: application/json',
            'x-apisports-key: ' . $this->apiKey,
        ];
        $host = strtolower((string) (parse_url($this->baseUrl, PHP_URL_HOST) ?? ''));
        if (str_contains($host, 'rapidapi.com')) {
            $headers[] = 'x-rapidapi-key: ' . $this->apiKey;
            $headers[] = 'x-rapidapi-host: ' . $host;
        }
        $url = rtrim($this->baseUrl, '/') . $path;
        return $this->doGet($url, $headers);
    }

    private function mapFixtures(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $fixture = $r['fixture'] ?? [];
            $teams = $r['teams'] ?? [];
            $league = $r['league'] ?? [];
            $venue = $r['fixture']['venue'] ?? [];
            $home = $teams['home']['name'] ?? null;
            $away = $teams['away']['name'] ?? null;
            $time = $fixture['date'] ?? null;
            if (!$home || !$away || !$time) continue;
            $status = $this->mapApiFootballStatus($fixture['status']['short'] ?? '');
            $out[] = [
                'externalId' => (string) ($fixture['id'] ?? ''),
                'homeTeam' => $home,
                'awayTeam' => $away,
                'competition' => $league['name'] ?? 'Football',
                'leagueId' => (string) ($league['id'] ?? ''),
                'season' => (string) ($league['season'] ?? ''),
                'kickoff' => $time,
                'status' => $status,
                'sport' => 'football',
                'venue' => $venue['name'] ?? null,
                'referee' => $fixture['referee'] ?? null,
                'homeTeamId' => (string) ($teams['home']['id'] ?? ''),
                'awayTeamId' => (string) ($teams['away']['id'] ?? ''),
                'homeTeamLogo' => $teams['home']['logo'] ?? null,
                'awayTeamLogo' => $teams['away']['logo'] ?? null,
                'country' => $league['country']['name'] ?? null,
                // Match-state detail, present only when the provider sent it.
                // Absent scores stay absent: the reader shows DATA_UNAVAILABLE
                // instead of defaulting a match to 0-0.
                'statusShort' => $fixture['status']['short'] ?? null,
                'statusLong' => $fixture['status']['long'] ?? null,
                'minute' => self::intOrNull($fixture['status']['elapsed'] ?? null),
                'extraMinute' => self::intOrNull($fixture['status']['extra'] ?? null),
                'homeScore' => self::intOrNull($r['goals']['home'] ?? null),
                'awayScore' => self::intOrNull($r['goals']['away'] ?? null),
                'halfTimeHome' => self::intOrNull($r['score']['halftime']['home'] ?? null),
                'halfTimeAway' => self::intOrNull($r['score']['halftime']['away'] ?? null),
                'sourceTimestamp' => gmdate('c'),
            ];
        }
        return $out;
    }

    /** Numeric field or null — never a silent 0 for "the API did not say". */
    private static function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) return null;
        return (int) $value;
    }

    /**
     * Live fixtures only (GET /fixtures?live=all) — the provider's documented
     * live endpoint, so a sweep does not re-pull the whole day to learn that a
     * score changed. Rows carry the current score and minute.
     */
    public function liveFixtures(): array
    {
        return $this->mapFixtures($this->extractList($this->decodeJson($this->doRequest('/fixtures?live=all'))));
    }

    /** Single fixture detail (status, minute, scores, venue, referee). */
    public function fixture(string $fixtureExternalId): array
    {
        $rows = $this->extractList($this->decodeJson($this->doRequest('/fixtures?id=' . rawurlencode($fixtureExternalId))));
        $mapped = $this->mapFixtures($rows);
        return $mapped[0] ?? [];
    }

    /**
     * Head-to-head meetings between two teams (GET /fixtures?h2h=home-away).
     * Optional `league` + `last` narrow the sample; only completed matches with
     * a score are returned, so the caller never has to filter out gaps.
     *
     * @return array<int,array<string,mixed>> past fixtures (newest first)
     */
    public function headToHead(string $homeTeamId, string $awayTeamId, int $last = 10, ?string $leagueId = null): array
    {
        if ($homeTeamId === '' || $awayTeamId === '') return [];
        $path = '/fixtures?h2h=' . rawurlencode($homeTeamId . '-' . $awayTeamId) . '&last=' . max(1, min(20, $last));
        if ($leagueId !== null && $leagueId !== '') $path .= '&league=' . rawurlencode($leagueId);
        $rows = $this->extractList($this->decodeJson($this->doRequest($path)));
        $out = [];
        foreach ($this->mapFixtures($rows) as $row) {
            if (($row['status'] ?? '') !== 'FINISHED') continue;
            if ($row['homeScore'] === null || $row['awayScore'] === null) continue;
            $out[] = $row;
        }
        usort($out, static fn(array $a, array $b) => strcmp((string) ($b['kickoff'] ?? ''), (string) ($a['kickoff'] ?? '')));
        return $out;
    }

    /**
     * Per-fixture team statistics (GET /fixtures/statistics?fixture=ID) — the
     * only place red cards, shots and possession come from for an in-play
     * board. Flattened to {teamId => {label => value}}; an empty array means
     * the provider has no statistics for this fixture yet.
     */
    public function fixtureStatistics(string $fixtureExternalId): array
    {
        $rows = $this->extractList($this->decodeJson($this->doRequest('/fixtures/statistics?fixture=' . rawurlencode($fixtureExternalId))));
        $out = [];
        foreach ($rows as $row) {
            $teamId = (string) ($row['team']['id'] ?? '');
            if ($teamId === '') continue;
            $label = trim((string) ($row['type'] ?? ''));
            if ($label === '') continue;
            $value = $row['value'];
            if (!is_numeric($value)) continue;
            $out[$teamId][$label] = (int) (float) $value;
            $out[$teamId]['_teamName'] = (string) ($row['team']['name'] ?? '');
        }
        return $out;
    }

    /**
     * A team's recent league fixtures with scores
     * (GET /fixtures?team=ID&last=N&next=0) — the source of the form column.
     */
    public function recentTeamFixtures(string $teamId, int $last = 10, ?string $leagueId = null): array
    {
        if ($teamId === '') return [];
        $path = '/fixtures?team=' . rawurlencode($teamId) . '&last=' . max(1, min(20, $last));
        if ($leagueId !== null && $leagueId !== '') $path .= '&league=' . rawurlencode($leagueId);
        $rows = $this->mapFixtures($this->extractList($this->decodeJson($this->doRequest($path))));
        return array_values(array_filter($rows, static fn(array $r) => ($r['status'] ?? '') === 'FINISHED' && $r['homeScore'] !== null && $r['awayScore'] !== null));
    }

    private function mapOdds(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            foreach (($r['bookmakers'] ?? []) as $bookmaker) {
                foreach (($bookmaker['bets'] ?? []) as $bet) {
                    foreach (($bet['values'] ?? []) as $v) {
                        if (!isset($v['odd'])) continue;
                        $market = self::normalizeMarket((string) ($bet['name'] ?? 'UNKNOWN'));
                        $selection = self::normalizeSelection($market, (string) ($v['value'] ?? ''));
                        $out[] = [
                            'market' => $market,
                            'selection' => $selection,
                            'decimalOdds' => (float) $v['odd'],
                            'observedAt' => gmdate('c'),
                            'bookmaker' => (string) ($bookmaker['name'] ?? ''),
                            'fixtureId' => (string) ($r['fixture']['id'] ?? ''),
                        ];
                    }
                }
            }
        }
        return $out;
    }

    private function mapResults(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $fixture = $r['fixture'] ?? [];
            $goals = $r['goals'] ?? [];
            $out[] = [
                'externalId' => (string) ($fixture['id'] ?? ''),
                'status' => $this->mapApiFootballStatus($fixture['status']['short'] ?? ''),
                'homeScore' => $goals['home'] !== null ? (int) $goals['home'] : null,
                'awayScore' => $goals['away'] !== null ? (int) $goals['away'] : null,
                'halfTimeHome' => isset($r['score']['halftime']['home']) ? (int) $r['score']['halftime']['home'] : null,
                'halfTimeAway' => isset($r['score']['halftime']['away']) ? (int) $r['score']['halftime']['away'] : null,
                'sourceTimestamp' => gmdate('c'),
            ];
        }
        return $out;
    }

    private function mapApiFootballStatus(string $short): string
    {
        return match ($short) {
            'NS' => 'SCHEDULED',
            '1H', 'HT', '2H', 'ET', 'BT', 'P', 'LIVE' => 'LIVE',
            'FT', 'AET', 'PEN' => 'FINISHED',
            'PST' => 'POSTPONED',
            'CANC' => 'CANCELLED',
            'SUSP' => 'SUSPENDED',
            'INT' => 'SUSPENDED',
            default => 'SCHEDULED',
        };
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 2. THESPORTSDB (thesportsdb.com)
//    Docs: https://www.thesportsdb.com/api/v1/json/3/all_sports.php
//    Free tier key = "3"; paid tiers get a dedicated key.
// ─────────────────────────────────────────────────────────────────────────────

class TheSportsDbProvider implements SportsDataProvider
{
    use HttpTransport;

    /**
     * TheSportsDB's public free key. The v1 docs publish "123"; the historic
     * "1"/"2"/"3" test keys are legacy aliases the vendor has been retiring and
     * a request built with one now commonly comes back HTTP 400. Any legacy
     * free key is normalised onto the documented one; a real premium key
     * (longer than 3 chars) is used untouched.
     */
    public const FREE_KEY = '123';
    public const LEGACY_FREE_KEYS = ['1', '2', '3', '123', ''];

    public function __construct(
        private string $apiKey = self::FREE_KEY,
        private string $baseUrl = 'https://www.thesportsdb.com/api/v1/json',
        private int $timeout = 10,
        ?callable $transport = null,
    ) {
        $this->apiKey = self::normalizeKey($this->apiKey);
        $this->baseUrl = self::normalizeBaseUrl($this->baseUrl);
        $this->initTransport($timeout, $transport);
    }

    public static function normalizeKey(string $key): string
    {
        $key = trim($key);
        return in_array($key, self::LEGACY_FREE_KEYS, true) ? self::FREE_KEY : $key;
    }

    /**
     * The v1 JSON base must be `https://www.thesportsdb.com/api/v1/json` — a
     * saved URL that already contains the key segment, the v2 base (header
     * auth, premium-only, different paths) or the marketing site would make
     * every request a 400/404.
     */
    public static function normalizeBaseUrl(string $baseUrl): string
    {
        $b = rtrim(trim($baseUrl), '/');
        if ($b === '') return 'https://www.thesportsdb.com/api/v1/json';
        if (!preg_match('#^https?://#i', $b)) $b = 'https://' . ltrim($b, '/');
        $host = strtolower((string) (parse_url($b, PHP_URL_HOST) ?? ''));
        if (!str_contains($host, 'thesportsdb.com')) return $b; // custom proxy — leave alone
        $b = preg_replace('#/api/v1/json/[^/]+$#i', '/api/v1/json', $b) ?? $b; // strip an embedded key
        if (!preg_match('#/api/v1/json$#i', $b)) return 'https://www.thesportsdb.com/api/v1/json';
        return $b;
    }

    public function id(): string { return 'thesportsdb'; }

    public function health(): array
    {
        try {
            // all_sports.php is the cheapest documented v1 call and is valid on
            // every tier — eventsday for "today" is a real fixture pull and on
            // the free tier is capped, so it made a bad health probe.
            $resp = $this->doRequest('/all_sports.php');
            $json = $this->decodeJson($resp);
            if (isset($json['error']) || (array_key_exists('sports', $json) && $json['sports'] === null)) {
                throw new ProviderException('TheSportsDB rejected the API key' . (isset($json['error']) ? ': ' . ProviderHttp::redact((string) $json['error']) : ''), ProviderException::AUTHENTICATION_ERROR, null, ['endpoint' => $this->lastUrl]);
            }
            return $this->baseHealth() + [
                'status' => 'ONLINE',
                'tier' => $this->apiKey === self::FREE_KEY ? 'free' : 'premium',
            ];
        } catch (ProviderException $e) {
            return $this->failedHealth($e);
        }
    }

    public function fixtures(array $query): array
    {
        $from = (string) ($query['from'] ?? gmdate('Y-m-d'));
        $to = (string) ($query['to'] ?? $from);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) !== 1) {
            throw new ProviderException('fixtures: from/to must be YYYY-MM-DD dates', ProviderException::BAD_REQUEST);
        }
        $all = [];
        $day = $from;
        $guard = 0;
        $daysTried = 0;
        $daysFailed = 0;
        $lastError = null;
        while ($day <= $to && $guard++ < 62) {
            $daysTried++;
            try {
                // Documented v1 schedule call: eventsday.php?d=YYYY-MM-DD&s=Soccer
                $resp = $this->doRequest('/eventsday.php?d=' . rawurlencode($day) . '&s=Soccer');
                $json = $this->decodeJson($resp);
                $events = $json['events'] ?? [];
                if (is_array($events)) $all = array_merge($all, $events);
            } catch (ProviderException $e) {
                $daysFailed++;
                $lastError = $e;
                // A throttle or a configuration error will fail every remaining
                // day the same way — stop instead of multiplying the damage.
                if ($e->isThrottled() || $e->isConfigurationError()) throw $e;
            }
            $day = gmdate('Y-m-d', strtotime($day . ' +1 day'));
        }
        // Every day failed → this is a provider failure, not "no fixtures".
        // Swallowing it here is exactly how an HTTP 400 used to surface as
        // "0 matches evaluated" downstream.
        if ($daysTried > 0 && $daysFailed === $daysTried && $lastError !== null) throw $lastError;
        return $this->mapFixtures($all);
    }

    /** Fetch fixtures for a specific league's most recent round. */
    public function leagueFixtures(string $leagueId, ?string $season = null): array
    {
        $path = $season
            ? '/eventsseason.php?id=' . rawurlencode($leagueId) . '&s=' . rawurlencode($season)
            : '/eventsnextleague.php?id=' . rawurlencode($leagueId);
        $resp = $this->doRequest($path);
        $json = $this->decodeJson($resp);
        $events = $json['events'] ?? [];
        return $this->mapFixtures(is_array($events) ? $events : []);
    }

    public function odds(string $fixtureExternalId): array
    {
        // TheSportsDB has NO odds API endpoint on any tier.
        return [];
    }

    public function results(string $fixtureExternalId): array
    {
        $resp = $this->doRequest('/lookupevent.php?id=' . rawurlencode($fixtureExternalId));
        $json = $this->decodeJson($resp);
        $event = $json['event'] ?? $json['events'] ?? null;
        if (!$event) {
            // fallback: lookup by event ID in list endpoint
            return [];
        }
        $list = isset($event[0]) ? $event : [$event];
        return $this->mapResults($list);
    }

    /** Look up results for a league. */
    public function leagueResults(string $leagueId): array
    {
        $resp = $this->doRequest('/eventspastleague.php?id=' . rawurlencode($leagueId));
        $json = $this->decodeJson($resp);
        $events = $json['events'] ?? [];
        return $this->mapResults(is_array($events) ? $events : []);
    }

    /** Fetch all leagues available on TheSportsDB for soccer. */
    public function soccerLeagues(): array
    {
        $resp = $this->doRequest('/all_leagues.php?s=Soccer');
        $json = $this->decodeJson($resp);
        $leagues = $json['leagues'] ?? [];
        return array_map(fn($l) => [
            'leagueId' => (string) ($l['idLeague'] ?? ''),
            'name' => $l['strLeague'] ?? '',
            'sport' => $l['strSport'] ?? 'Soccer',
            'country' => $l['strCountry'] ?? '',
        ], is_array($leagues) ? $leagues : []);
    }

    /** Fetch team details. */
    public function team(string $teamId): array
    {
        $resp = $this->doRequest('/lookupteam.php?id=' . rawurlencode($teamId));
        $json = $this->decodeJson($resp);
        $team = $json['teams'][0] ?? [];
        return [
            'teamId' => (string) ($team['idTeam'] ?? ''),
            'name' => $team['strTeam'] ?? '',
            'stadium' => $team['strStadium'] ?? '',
            'league' => $team['strLeague'] ?? '',
            'country' => $team['strCountry'] ?? '',
            'formed' => $team['intFormedYear'] ?? null,
            'badge' => $team['strBadge'] ?? null,
            'apiFootballId' => self::refId($team['idAPIfootball'] ?? null),
        ];
    }

    /**
     * Search teams by name (searchteams.php — the endpoint shown in the
     * TheSportsDB docs API examples). Returns the internal team shape,
     * including the idAPIfootball cross-reference when the vendor has it.
     */
    public function searchTeams(string $name, int $limit = 10): array
    {
        $resp = $this->doRequest('/searchteams.php?t=' . rawurlencode($name));
        $json = $this->decodeJson($resp);
        $teams = $json['teams'] ?? [];
        $out = [];
        foreach ((is_array($teams) ? $teams : []) as $t) {
            if (!is_array($t) || empty($t['strTeam'])) continue;
            $out[] = [
                'teamId' => (string) ($t['idTeam'] ?? ''),
                'name' => (string) $t['strTeam'],
                'shortName' => $t['strTeamShort'] ?? null,
                'stadium' => $t['strStadium'] ?? null,
                'league' => $t['strLeague'] ?? null,
                'leagueId' => (string) ($t['idLeague'] ?? ''),
                'country' => $t['strCountry'] ?? null,
                'location' => $t['strLocation'] ?? null,
                'formed' => isset($t['intFormedYear']) ? (int) $t['intFormedYear'] : null,
                'badge' => $t['strBadge'] ?? null,
                'apiFootballId' => self::refId($t['idAPIfootball'] ?? null),
            ];
            if (count($out) >= max(1, $limit)) break;
        }
        return $out;
    }

    /** Cross-reference ids are null/'' when the vendor has no mapping. */
    private static function refId(mixed $v): ?string
    {
        if ($v === null) return null;
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }

    private function doRequest(string $path): array
    {
        $url = rtrim($this->baseUrl, '/') . '/' . rawurlencode($this->apiKey) . '/' . ltrim($path, '/');
        return $this->doGet($url, ['Accept: application/json']);
    }

    private function mapFixtures(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $home = $r['strHomeTeam'] ?? null;
            $away = $r['strAwayTeam'] ?? null;
            $date = $r['dateEvent'] ?? null;
            $time = $r['strTime'] ?? '00:00:00';
            if (!$home || !$away || !$date) continue;
            $kickoff = $date . 'T' . $time . '+00:00';
            $status = $this->mapStatus($r['strStatus'] ?? '');
            $out[] = [
                'externalId' => (string) ($r['idEvent'] ?? ''),
                'homeTeam' => $home,
                'awayTeam' => $away,
                'competition' => $r['strLeague'] ?? 'Football',
                'leagueId' => (string) ($r['idLeague'] ?? ''),
                'season' => (string) ($r['strSeason'] ?? ''),
                'kickoff' => $kickoff,
                'status' => $status,
                'sport' => 'football',
            'venue' => $r['strVenue'] ?? null,
            'homeTeamId' => (string) ($r['idHomeTeam'] ?? ''),
            'awayTeamId' => (string) ($r['idAwayTeam'] ?? ''),
            'homeTeamLogo' => null,
            'awayTeamLogo' => null,
            // Match-state detail from the same response — TheSportsDB carries
            // scores and card counts on events; missing ones stay null.
            'statusShort' => self::blankToNull($r['strStatus'] ?? null),
            'minute' => self::intOrNull($r['intElapsed'] ?? null),
            'homeScore' => self::intOrNull($r['intHomeScore'] ?? null),
            'awayScore' => self::intOrNull($r['intAwayScore'] ?? null),
            'homeRedCards' => self::intOrNull($r['intHomeRedCards'] ?? null),
            'awayRedCards' => self::intOrNull($r['intAwayRedCards'] ?? null),
            // TheSportsDB cross-references api-football fixture ids — keep it
            // so the same match can be matched across providers.
            'apiFootballId' => self::refId($r['idAPIfootball'] ?? null),
            'sourceTimestamp' => gmdate('c'),
        ];
        }
        return $out;
    }

    private static function blankToNull(mixed $value): ?string
    {
        $string = trim((string) ($value ?? ''));
        return $string === '' ? null : $string;
    }

    private static function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) return null;
        return (int) $value;
    }

    /** Live matches (GET /livescore.php) — score + minute as the provider gives them. */
    public function liveFixtures(): array
    {
        $resp = $this->doRequest('/livescore.php?d=' . gmdate('Y-m-d') . '&s=Soccer');
        $events = $this->decodeJson($resp)['events'] ?? [];
        return $this->mapFixtures(is_array($events) ? $events : []);
    }

    /** One fixture by id (GET /lookupevent.php) — status, score, cards. */
    public function fixture(string $fixtureExternalId): array
    {
        $json = $this->decodeJson($this->doRequest('/lookupevent.php?id=' . rawurlencode($fixtureExternalId)));
        $event = $json['event'] ?? $json['events'] ?? null;
        if (!$event) return [];
        return $this->mapFixtures(isset($event[0]) && is_array($event[0]) ? $event : [$event])[0] ?? [];
    }

    private function mapResults(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $status = $this->mapStatus($r['strStatus'] ?? '');
            $out[] = [
                'externalId' => (string) ($r['idEvent'] ?? ''),
                'status' => $status,
                'apiFootballId' => self::refId($r['idAPIfootball'] ?? null),
                'homeScore' => isset($r['intHomeScore']) && $r['intHomeScore'] !== '' ? (int) $r['intHomeScore'] : null,
                'awayScore' => isset($r['intAwayScore']) && $r['intAwayScore'] !== '' ? (int) $r['intAwayScore'] : null,
                'halfTimeHome' => isset($r['intHomeHalfScore']) && $r['intHomeHalfScore'] !== '' ? (int) $r['intHomeHalfScore'] : null,
                'halfTimeAway' => isset($r['intAwayHalfScore']) && $r['intAwayHalfScore'] !== '' ? (int) $r['intAwayHalfScore'] : null,
                'sourceTimestamp' => gmdate('c'),
            ];
        }
        return $out;
    }

    private function mapStatus(string $raw): string
    {
        $s = strtoupper(trim($raw));
        return match (true) {
            $s === 'FINISHED' || $s === 'COMPLETE' || $s === 'MATCH FINISHED' => 'FINISHED',
            str_contains($s, 'LIVE') || str_contains($s, 'IN PROGRESS') => 'LIVE',
            $s === 'POSTPONED' => 'POSTPONED',
            $s === 'CANCELLED' || $s === 'CANCELED' => 'CANCELLED',
            $s === 'SUSPENDED' => 'SUSPENDED',
            default => 'SCHEDULED',
        };
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 3. SPORTMONKS (sportmonks.com)
//    Docs: https://docs.sportmonks.com/football/v3
//    Round-based bulk fetch: round() retrieves a whole matchday (fixtures +
//    odds + results) in one request via /rounds/{id} with nested includes;
//    seasonRounds() resolves the season's round ids.
// ─────────────────────────────────────────────────────────────────────────────

class SportMonksProvider implements SportsDataProvider
{
    use HttpTransport;

    public function __construct(
        private string $apiToken,
        private string $baseUrl = 'https://api.sportmonks.com/v3/football',
        private int $timeout = 10,
        ?callable $transport = null,
    ) {
        $this->baseUrl = self::normalizeBaseUrl($this->baseUrl);
        $this->initTransport($timeout, $transport);
    }

    /**
     * Every v3 football path lives under `https://api.sportmonks.com/v3/football`.
     * The three configuration mistakes that produce HTTP 404 on every call:
     * the v2 host (`soccer.sportmonks.com/api/v2.0`), the API root without the
     * `/football` sport segment, and the marketing site (`www.sportmonks.com`).
     * All are canonicalised here; a non-sportmonks host (proxy) is left alone.
     */
    public static function normalizeBaseUrl(string $baseUrl): string
    {
        $b = rtrim(trim($baseUrl), '/');
        if ($b === '') return 'https://api.sportmonks.com/v3/football';
        if (!preg_match('#^https?://#i', $b)) $b = 'https://' . ltrim($b, '/');
        $host = strtolower((string) (parse_url($b, PHP_URL_HOST) ?? ''));
        if (!str_contains($host, 'sportmonks.com')) return $b;
        $path = strtolower((string) (parse_url($b, PHP_URL_PATH) ?? ''));
        if ($host === 'api.sportmonks.com' && preg_match('#^/v3/football$#', $path)) return $b;
        if ($host === 'api.sportmonks.com' && $path === '/v3') return $b . '/football';
        if ($host === 'api.sportmonks.com' && preg_match('#^/v3/(core|odds)#', $path)) return $b; // other v3 products are legitimate
        return 'https://api.sportmonks.com/v3/football';
    }

    public function id(): string { return 'sportmonks'; }

    public function health(): array
    {
        try {
            // /leagues is the smallest authenticated v3 call. It also returns
            // the subscription + rate_limit envelope, which tells us how many
            // requests remain in the current hour and which plan is active.
            $resp = $this->doRequest('/leagues?per_page=1');
            $json = $this->decodeJson($resp);
            $health = $this->baseHealth() + ['status' => 'ONLINE'];
            $rl = is_array($json['rate_limit'] ?? null) ? $json['rate_limit'] : [];
            if (isset($rl['remaining']) && is_numeric($rl['remaining'])) {
                $health['rateLimitRemaining'] = (int) $rl['remaining'];
                if (isset($rl['resets_in_seconds']) && is_numeric($rl['resets_in_seconds'])) $health['rateLimitResetsInSeconds'] = (int) $rl['resets_in_seconds'];
                if ((int) $rl['remaining'] <= 0) {
                    $health['status'] = ProviderException::RATE_LIMITED;
                    $health['detail'] = 'SportMonks hourly request allowance used; resets in ' . (int) ($rl['resets_in_seconds'] ?? 3600) . 's';
                    $health['retryAt'] = gmdate('c', time() + (int) ($rl['resets_in_seconds'] ?? 3600));
                    return $health;
                }
            }
            $subs = is_array($json['subscription'] ?? null) ? $json['subscription'] : [];
            $plans = [];
            foreach ($subs as $sub) foreach ((array) ($sub['plans'] ?? []) as $plan) if (!empty($plan['plan'])) $plans[] = (string) $plan['plan'];
            if ($plans !== []) $health['plan'] = implode(', ', array_unique($plans));
            $health['leaguesVisible'] = is_array($json['data'] ?? null) ? count($json['data']) : null;
            return $health;
        } catch (ProviderException $e) {
            return $this->failedHealth($e);
        }
    }

    public function fixtures(array $query): array
    {
        $from = (string) ($query['from'] ?? gmdate('Y-m-d'));
        $to   = (string) ($query['to'] ?? $from);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) !== 1) {
            throw new ProviderException('fixtures: from/to must be YYYY-MM-DD dates', ProviderException::DATA_ERROR);
        }
        $start = strtotime($from . ' 00:00:00 UTC');
        $end   = strtotime($to . ' 00:00:00 UTC');
        $days  = (int) floor(($end - $start) / 86400);
        if ($days < 0) throw new ProviderException('fixtures: from must not be after to', ProviderException::DATA_ERROR);
        if ($days > 100) {
            throw new ProviderException('fixtures: the SportMonks v3 date-range endpoint allows at most 100 days per request — narrow the window', ProviderException::DATA_ERROR);
        }
        // The v3 API dates fixtures through DEDICATED path endpoints. The
        // v2-era `filter[starts_between:...]` query parameter no longer
        // exists and is SILENTLY IGNORED (the API would return its oldest
        // fixtures unfiltered), and the old top-level `leagues=` parameter
        // is invalid as well. Canonical v3 surface (verified against the
        // v3 docs, 2026-09):
        //   single day → GET /fixtures/date/{date}
        //   range      → GET /fixtures/between/{start}/{end}  (max 100 days)
        //   league     → &filters=fixtureLeagues:{id}
        //   season     → &filters=fixtureSeasons:{id}
        $path = $days === 0
            ? '/fixtures/date/' . $from
            : '/fixtures/between/' . $from . '/' . $to;
        $filters = [];
        if (!empty($query['league'])) $filters[] = 'fixtureLeagues:' . (string) $query['league'];
        if (!empty($query['season'])) $filters[] = 'fixtureSeasons:' . (string) $query['season'];
        $includes = 'participants;scores;league;venue;referee';
        // /fixtures paginates (25 per page by default). Follow the cursor
        // until has_more=false so a busy multi-league day is not silently
        // truncated to the first page. Hard cap: 40 pages (2000 fixtures).
        $rows = [];
        $cursor = null;
        $pages = 0;
        do {
            $params = ['include' => $includes];
            if ($cursor !== null) {
                $params['cursor'] = $cursor;
            } else {
                $params['per_page'] = 50;
            }
            $qs = http_build_query($params);
            foreach ($filters as $filter) {
                $qs .= '&' . rawurlencode('filters') . '=' . rawurlencode($filter);
            }
            $resp = $this->doRequest($path . '?' . $qs);
            $json = $this->decodeJson($resp);
            $rows = array_merge($rows, $this->extractList($json));
            $pagination = $json['pagination'] ?? [];
            $cursor = (!empty($pagination['has_more']) && !empty($pagination['next_cursor']))
                ? (string) $pagination['next_cursor']
                : null;
            $pages++;
        } while ($cursor !== null && $pages < 40);
        return $this->mapFixtures($rows);
    }

    /**
     * Fetch an entire matchday (round) in a SINGLE request.
     *
     * GET /rounds/{id} with nested includes: fixtures (odds with market and
     * bookmaker, participants, scores, venue, state) plus league (country).
     * One HTTP call returns the full round — fixtures, bookmaker odds and
     * results — instead of fixtures() + N×odds() + N×results().
     *
     * Round ids come from seasonRounds(). Odds still require the SportMonks
     * odds add-on; without it the fixtures simply carry no odds entries.
     *
     * @return array{roundId:string, name:?string, leagueId:string, league:?string,
     *               season:string, startingAt:?string, endingAt:?string, finished:bool,
     *               fixtures:array<int,array<string,mixed>>,
     *               odds:array<int,array<string,mixed>>,
     *               results:array<int,array<string,mixed>>}
     */
    public function round(string $roundExternalId): array
    {
        $fullIncludes = [
            'fixtures',
            'fixtures.odds', 'fixtures.odds.market', 'fixtures.odds.bookmaker',
            'fixtures.participants', 'fixtures.scores', 'fixtures.venue', 'fixtures.state',
            'league', 'league.country',
        ];
        $plainIncludes = [
            'fixtures',
            'fixtures.participants', 'fixtures.scores', 'fixtures.venue', 'fixtures.state',
            'league', 'league.country',
        ];
        try {
            $resp = $this->doRequest($this->roundPath($roundExternalId, $fullIncludes));
        } catch (ProviderException $e) {
            // The odds include may not be on this subscription (include
            // exception 5013 → HTTP 400 BAD_REQUEST) — retry once without
            // odds so the round is still usable for fixtures + results.
            // Throttling, auth, 404 and transport failures are NOT retried:
            // they would fail identically and only burn quota.
            if (!in_array($e->status, [ProviderException::DATA_ERROR, ProviderException::BAD_REQUEST], true)) throw $e;
            $resp = $this->doRequest($this->roundPath($roundExternalId, $plainIncludes));
        }
        $json = $this->decodeJson($resp);
        $data = $json['data'] ?? $json;
        if (!is_array($data) || !isset($data['id'])) {
            throw new ProviderException('round payload is malformed (missing round id)', ProviderException::DATA_ERROR);
        }
        $league = is_array($data['league'] ?? null) ? $data['league'] : [];
        $fixtures = [];
        foreach (($data['fixtures'] ?? []) as $f) {
            if (!is_array($f) || !isset($f['id'])) continue;
            // Round payloads carry the league at the round level; the
            // per-fixture mappers expect it on each row, so enrich the copy.
            if (!isset($f['league']) && $league !== []) $f['league'] = $league;
            if (!isset($f['season']) && isset($data['season_id'])) $f['season'] = ['id' => $data['season_id']];
            $fixtures[] = $f;
        }
        return [
            'roundId' => (string) $data['id'],
            'name' => isset($data['name']) && (string) $data['name'] !== '' ? (string) $data['name'] : null,
            'leagueId' => (string) ($league['id'] ?? $data['league_id'] ?? ''),
            'league' => isset($league['name']) ? (string) $league['name'] : null,
            'season' => (string) ($data['season_id'] ?? ''),
            'startingAt' => $data['starting_at'] ?? null,
            'endingAt' => $data['ending_at'] ?? null,
            'finished' => !empty($data['finished']),
            'fixtures' => $this->mapFixtures($fixtures),
            'odds' => $this->mapRoundOdds($fixtures),
            'results' => $this->mapResults($fixtures),
        ];
    }

    /** List a season's rounds (id + metadata) so round() can be addressed by round id. */
    public function seasonRounds(string $seasonId): array
    {
        $resp = $this->doRequest('/rounds/seasons/' . rawurlencode($seasonId));
        $rows = $this->extractList($this->decodeJson($resp));
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r) || !isset($r['id'])) continue;
            $out[] = [
                'roundId' => (string) $r['id'],
                'name' => isset($r['name']) && (string) $r['name'] !== '' ? (string) $r['name'] : null,
                'leagueId' => (string) ($r['league_id'] ?? ''),
                'season' => (string) ($r['season_id'] ?? ''),
                'finished' => !empty($r['finished']),
                'isCurrent' => !empty($r['is_current']),
                'startingAt' => $r['starting_at'] ?? null,
                'endingAt' => $r['ending_at'] ?? null,
            ];
        }
        return $out;
    }

    private function roundPath(string $roundExternalId, array $includes): string
    {
        return '/rounds/' . rawurlencode($roundExternalId) . '?include=' . rawurlencode(implode(';', $includes));
    }

    public function odds(string $fixtureExternalId): array
    {
        // SportMonks odds require the optional "odds" add-on subscription.
        // Attempt the endpoint and return empty if it fails.
        // Canonical v3 path (verified live, 2026-09): the standalone v2-era
        // path /odds/fixtures/{id} does NOT exist ("The requested endpoint
        // does not exist") — pre-match odds live under
        // GET /odds/pre-match/fixtures/{id} with includes market;bookmaker.
        // The base odd row already carries label/original_label, so no
        // undocumented `selection` include is requested (unknown includes
        // raise an include exception and would fail the whole request).
        try {
            $resp = $this->doRequest('/odds/pre-match/fixtures/' . rawurlencode($fixtureExternalId) . '?' . http_build_query([
                'include' => 'market;bookmaker',
            ]));
            $rows = $this->extractList($this->decodeJson($resp));
            return $this->mapOdds($rows);
        } catch (ProviderException $e) {
            // Throttling / quota / transport failures must reach the circuit
            // breaker — swallowing them here would hide a dead provider
            // behind "no odds". Only plan-related refusals (add-on not
            // subscribed → 400/403, no odds resource → 404) degrade to [].
            if ($e->isThrottled() || in_array($e->status, [ProviderException::OFFLINE, ProviderException::TIMEOUT], true)) throw $e;
            return [];
        }
    }

    public function results(string $fixtureExternalId): array
    {
        $resp = $this->doRequest('/fixtures/' . rawurlencode($fixtureExternalId) . '?include=scores;participants');
        $json = $this->decodeJson($resp);
        $data = $json['data'] ?? $json;
        if (!isset($data['id'])) {
            // might be a list
            $rows = is_array($data) ? $data : [$data];
            return $this->mapResults($rows);
        }
        return $this->mapResults([$data]);
    }

    /**
     * Fetch standings for a league + season.
     *
     * GET /standings/seasons/{season} with `filters=standingLeagues:{id}` —
     * the old top-level `leagues=` parameter is invalid in v3 and was
     * silently ignored (the API returned every league's standings). The
     * base standing row only carries position/points; the team name comes
     * from `include=participant` and W/D/L/goals from `include=details`
     * (standing-detail type ids per the v3 types definitions:
     * 129 played, 130 won, 131 draw, 132 lost, 133 goals for, 134 conceded).
     * The endpoint is not paginated (verified against the v3 docs).
     */
    public function standings(string $leagueId, string $season): array
    {
        $params = [
            'filters' => 'standingLeagues:' . $leagueId,
            'include' => 'participant;details',
        ];
        $resp = $this->doRequest('/standings/seasons/' . rawurlencode($season) . '?' . http_build_query($params));
        $rows = $this->extractList($this->decodeJson($resp));
        $out = [];
        foreach ($rows as $entry) {
            $participant = is_array($entry['participant'] ?? null) ? $entry['participant'] : [];
            $details = [];
            foreach (($entry['details'] ?? []) as $d) {
                if (is_array($d) && isset($d['type_id'], $d['value'])) {
                    $details[(int) $d['type_id']] = (int) $d['value'];
                }
            }
            $out[] = [
                'leagueId' => $leagueId,
                'season' => $season,
                'rank' => (int) ($entry['position'] ?? 0),
                'team' => (string) ($participant['name'] ?? ''),
                'teamId' => (string) ($participant['id'] ?? ''),
                'played' => $details[129] ?? 0,
                'wins' => $details[130] ?? 0,
                'draws' => $details[131] ?? 0,
                'losses' => $details[132] ?? 0,
                'goalsFor' => $details[133] ?? 0,
                'goalsAgainst' => $details[134] ?? 0,
                'points' => (int) ($entry['points'] ?? 0),
            ];
        }
        return $out;
    }

    /** Fetch available leagues. */
    public function leagues(): array
    {
        $resp = $this->doRequest('/leagues');
        $rows = $this->extractList($this->decodeJson($resp));
        return array_map(fn($r) => [
            'leagueId' => (string) ($r['id'] ?? ''),
            'name' => $r['name'] ?? '',
            'country' => $r['country']['name'] ?? '',
            'countryId' => (string) ($r['country']['id'] ?? ''),
        ], $rows);
    }

    /**
     * Fetch the team sheets for a fixture.
     *
     * v3 has NO /lineups/fixtures/{id} endpoint (that was v2). Lineups are
     * an include on the fixture itself:
     *   GET /fixtures/{id}?include=lineups;participants;lineups.position
     *
     * Each entry in data.lineups[] is flat: {player_id, team_id,
     * position_id, player_name, jersey_number, type_id} where
     * type_id 11 = starting player, 12 = substitute. The position NAME
     * requires the nested `lineups.position` include (Goalkeeper /
     * Defender / Midfielder / Attacker).
     */
    public function lineups(string $fixtureId): array
    {
        try {
            $resp = $this->doRequest('/fixtures/' . rawurlencode($fixtureId) . '?' . http_build_query([
                'include' => 'lineups;participants;lineups.position',
            ]));
            $json = $this->decodeJson($resp);
            $data = is_array($json['data'] ?? null) ? $json['data'] : [];
            $teams = [];
            foreach (($data['participants'] ?? []) as $pt) {
                if (isset($pt['id'], $pt['name'])) $teams[(string) $pt['id']] = (string) $pt['name'];
            }
            $out = [];
            foreach (($data['lineups'] ?? []) as $l) {
                $position = is_array($l['position'] ?? null) ? (string) ($l['position']['name'] ?? '') : '';
                $out[] = [
                    'fixtureId' => $fixtureId,
                    'teamId' => (string) ($l['team_id'] ?? ''),
                    'team' => $teams[(string) ($l['team_id'] ?? '')] ?? '',
                    'playerId' => (string) ($l['player_id'] ?? ''),
                    'player' => (string) ($l['player_name'] ?? (is_array($l['player'] ?? null) ? ($l['player']['name'] ?? '') : '')),
                    'position' => $position,
                    'jerseyNumber' => (int) ($l['jersey_number'] ?? 0),
                    'starter' => (int) ($l['type_id'] ?? 0) === 11,
                ];
            }
            return $out;
        } catch (ProviderException $e) {
            return [];
        }
    }

    private function doRequest(string $path): array
    {
        $sep = str_contains($path, '?') ? '&' : '?';
        $url = rtrim($this->baseUrl, '/') . $path . $sep . 'api_token=' . rawurlencode($this->apiToken);
        return $this->doGet($url, ['Accept: application/json']);
    }

    private function mapFixtures(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $home = $this->participant($r, 'home');
            $away = $this->participant($r, 'away');
            $time = $r['starting_at'] ?? $r['starting_at_timestamp'] ?? null;
            if (!$home || !$away || !$time) continue;
            $kickoff = is_numeric($time) ? gmdate('c', (int) $time) : $time;
            $out[] = [
                'externalId' => (string) ($r['id'] ?? ''),
                'homeTeam' => $home,
                'awayTeam' => $away,
                'competition' => $r['league']['name'] ?? 'Football',
                'leagueId' => (string) ($r['league']['id'] ?? ''),
                'season' => (string) ($r['season']['id'] ?? ''),
                'kickoff' => $kickoff,
                'status' => $this->fixtureStatus($r),
                'sport' => 'football',
                'venue' => $r['venue']['name'] ?? null,
                'referee' => $r['referee']['name'] ?? null,
                'homeTeamId' => $this->participantId($r, 'home'),
                'awayTeamId' => $this->participantId($r, 'away'),
                'homeTeamLogo' => $this->participantLogo($r, 'home'),
                'awayTeamLogo' => $this->participantLogo($r, 'away'),
                'country' => $r['league']['country']['name'] ?? ($r['country'] ?? null),
                'statusShort' => is_array($r['status'] ?? null) ? ($r['status']['short'] ?? null) : ($r['status'] ?? null),
                'statusLong' => is_array($r['status'] ?? null) ? ($r['status']['long'] ?? null) : null,
                // SportMonks keeps live scores under scores.{side}.current and
                // finished scores under .score; the elapsed minute comes from
                // the `state` include when the caller asked for it.
                'homeScore' => self::scoreOf($r, 'home'),
                'awayScore' => self::scoreOf($r, 'away'),
                'minute' => self::minuteOf($r),
                'round' => $r['round']['name'] ?? null,
                'roundId' => isset($r['round_id']) && $r['round_id'] !== null ? (string) $r['round_id'] : '',
                'sourceTimestamp' => gmdate('c'),
            ];
        }
        return $out;
    }

    /** Finished score when present, otherwise the live score; null when neither exists. */
    private static function scoreOf(array $r, string $side): ?int
    {
        $score = $r['scores'][$side]['score'] ?? null;
        if (!is_numeric($score)) $score = $r['scores'][$side]['current'] ?? null;
        return is_numeric($score) ? (int) $score : null;
    }

    /** Latest reported minute from SportMonks' `state` include (null when absent). */
    private static function minuteOf(array $r): ?int
    {
        $minute = null;
        foreach ((array) ($r['state'] ?? []) as $event) {
            if (!is_array($event) || !isset($event['minute']) || !is_numeric($event['minute'])) continue;
            $value = (int) $event['minute'];
            if ($value > ($minute ?? 0)) $minute = $value;
        }
        return $minute;
    }

    /** Live fixtures for a day (GET /fixtures/live/{date}) with current scores. */
    public function liveFixtures(): array
    {
        $resp = $this->doRequest('/fixtures/live/' . gmdate('Y-m-d') . '?include=state');
        return $this->mapFixtures($this->extractList($this->decodeJson($resp)));
    }

    /** Head-to-head meetings (GET /fixtures/headtohead/{league} filtered by both teams). */
    public function headToHead(string $homeTeamId, string $awayTeamId, int $last = 10, ?string $leagueId = null): array
    {
        if ($homeTeamId === '' || $awayTeamId === '' || $leagueId === null || $leagueId === '') return [];
        $path = '/fixtures/headtohead/' . rawurlencode($leagueId) . '?' . http_build_query([
            'filters' => 'h2h:' . $homeTeamId . ',' . $awayTeamId,
            'page' => 1,
        ]);
        $rows = $this->mapFixtures($this->extractList($this->decodeJson($this->doRequest($path))));
        $finished = array_values(array_filter($rows, static fn(array $r) => ($r['status'] ?? '') === 'FINISHED' && $r['homeScore'] !== null && $r['awayScore'] !== null));
        usort($finished, static fn(array $a, array $b) => strcmp((string) ($b['kickoff'] ?? ''), (string) ($a['kickoff'] ?? '')));
        return array_slice($finished, 0, max(1, min(20, $last)));
    }

    /** Per-fixture statistics (GET /fixtures/{id}?include=stats) — red cards live here. */
    public function fixtureStatistics(string $fixtureExternalId): array
    {
        $resp = $this->doRequest('/fixtures/' . rawurlencode($fixtureExternalId) . '?include=stats');
        $data = $this->decodeJson($resp)['data'] ?? [];
        $stats = is_array($data['stats'] ?? null) ? $data['stats'] : [];
        $out = [];
        // SportMonks stat type ids: 9 = Yellow Cards, 10 = Red Cards,
        // 11 = Yellow-Red Cards, 1 = Goals, 4 = Shots on Goal, 20 = Possession.
        $labels = [1 => 'Goals', 4 => 'Shots on Goal', 5 => 'Shots off Goal', 9 => 'Yellow Cards', 10 => 'Red Cards', 11 => 'Yellow-Red Cards', 20 => 'Ball Possession', 13 => 'Corner Kicks', 15 => 'Offsides', 14 => 'Fouls'];
        foreach ($stats as $stat) {
            $teamId = (string) ($stat['team_id'] ?? '');
            $type = (int) ($stat['type_id'] ?? 0);
            if ($teamId === '' || !isset($labels[$type])) continue;
            $value = $stat['value'];
            if (!is_numeric($value)) continue;
            $out[$teamId][$labels[$type]] = (float) $value;
        }
        return $out;
    }

    private function mapOdds(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            if (!isset($r['value'])) continue;
            $market = $r['market'] ?? [];
            $bookmaker = $r['bookmaker'] ?? [];
            // Selection source: the standalone /odds endpoint returns a
            // `selection` object; round-embedded odds carry `original_label`
            // (bookmaker-native, e.g. "1"/"Draw"/"2") or a display `label`.
            $rawSelection = null;
            if (isset($r['selection']['value'])) $rawSelection = (string) $r['selection']['value'];
            elseif (isset($r['original_label']) && (string) $r['original_label'] !== '') $rawSelection = (string) $r['original_label'];
            elseif (isset($r['label']) && (string) $r['label'] !== '') $rawSelection = (string) $r['label'];
            if ($rawSelection === null) continue;
            $normalizedMarket = self::normalizeMarket((string) ($market['name'] ?? 'UNKNOWN'));
            $normalizedSelection = self::normalizeSelection($normalizedMarket, $rawSelection);
            $row = [
                'market' => $normalizedMarket,
                'selection' => $normalizedSelection,
                'decimalOdds' => (float) $r['value'],
                'observedAt' => gmdate('c'),
                'bookmaker' => (string) ($bookmaker['name'] ?? ''),
                'fixtureId' => (string) ($r['fixture_id'] ?? ''),
            ];
            // Optional enrichment present on round-embedded odds.
            if (isset($r['probability'])) {
                $prob = (float) rtrim(trim((string) $r['probability']), '%');
                if (is_finite($prob) && $prob >= 0.0) $row['impliedProbability'] = round($prob / 100, 4);
            }
            if (isset($r['latest_bookmaker_update']) && (string) $r['latest_bookmaker_update'] !== '') {
                $row['updatedAt'] = (string) $r['latest_bookmaker_update'];
            }
            if (array_key_exists('winning', $r)) $row['winning'] = (bool) $r['winning'];
            $out[] = $row;
        }
        return $out;
    }

    /** Flatten the odds embedded in a round payload's fixtures into mapOdds() rows. */
    private function mapRoundOdds(array $fixtures): array
    {
        $rows = [];
        foreach ($fixtures as $f) {
            foreach (($f['odds'] ?? []) as $o) {
                if (is_array($o)) $rows[] = $o;
            }
        }
        return $this->mapOdds($rows);
    }

    private function mapResults(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $scores = $r['scores'] ?? [];
            $out[] = [
                'externalId' => (string) ($r['id'] ?? ''),
                'status' => $this->fixtureStatus($r),
                'homeScore' => $scores['home']['score'] ?? $scores['home']['current'] ?? null,
                'awayScore' => $scores['away']['score'] ?? $scores['away']['current'] ?? null,
                'halfTimeHome' => $scores['home']['halftime']['score'] ?? $scores['home']['halftime']['current'] ?? null,
                'halfTimeAway' => $scores['away']['halftime']['score'] ?? $scores['away']['halftime']['current'] ?? null,
                'sourceTimestamp' => gmdate('c'),
            ];
        }
        return $out;
    }

    private function participant(array $r, string $side): ?string
    {
        foreach (($r['participants'] ?? []) as $p) {
            $location = strtolower((string) ($p['meta']['location'] ?? ''));
            if ($location === $side) return $p['name'] ?? null;
        }
        // fallback: some SportMonks responses use nested home/away
        $fallback = $r[$side] ?? [];
        if (isset($fallback['name'])) return $fallback['name'];
        return null;
    }

    private function participantId(array $r, string $side): string
    {
        foreach (($r['participants'] ?? []) as $p) {
            $location = strtolower((string) ($p['meta']['location'] ?? ''));
            if ($location === $side) return (string) ($p['id'] ?? '');
        }
        return (string) (($r[$side]['id'] ?? ''));
    }

    private function participantLogo(array $r, string $side): ?string
    {
        foreach (($r['participants'] ?? []) as $p) {
            $location = strtolower((string) ($p['meta']['location'] ?? ''));
            if ($location === $side) return $p['image_path'] ?? $p['logo'] ?? null;
        }
        return $r[$side]['image_path'] ?? $r[$side]['logo'] ?? null;
    }

    /**
     * Resolve a SportMonks fixture's normalized status.
     *
     * Preference order (v3 payloads carry state_id or a `state` include
     * object; legacy v2 payloads carried a numeric `status` code):
     *   1. `state` include object (id/state/name/developer_name) — most explicit
     *   2. `state_id` — official v3 fixture state table (see mapStateId)
     *   3. legacy v2 numeric `status` codes (see mapSportMonksStatus)
     */
    private function fixtureStatus(array $r): string
    {
        if (isset($r['state']) && is_array($r['state'])) {
            $code = (string) ($r['state']['developer_name'] ?? $r['state']['state'] ?? '');
            $mapped = self::mapStateCode($code);
            if ($mapped !== null) return $mapped;
        }
        if (isset($r['state_id'])) return self::mapStateId((int) $r['state_id']);
        if (isset($r['status'])) return $this->mapSportMonksStatus((int) $r['status']);
        return 'SCHEDULED';
    }

    /** Official v3 fixture state table (docs.sportmonks.com → API 3.0 → States). */
    private static function mapStateId(int $stateId): string
    {
        return match ($stateId) {
            1, 13, 16, 19, 26 => 'SCHEDULED',    // NS, TBA, DELAYED, AU, PENDING
            2, 3, 4, 6, 9, 21, 22, 25 => 'LIVE', // 1st half, HT, ET break, ET, penalties, ET break, 2nd half, pen break
            5, 7, 8, 14, 17 => 'FINISHED',       // FT, AET, FT_PEN, WO, AWARDED
            10 => 'POSTPONED',                    // POSTPONED
            11, 15, 18 => 'SUSPENDED',            // SUSPENDED, ABANDONED, INTERRUPTED
            12, 20 => 'CANCELLED',                // CANCELLED, DELETED
            default => 'SCHEDULED',
        };
    }

    /** State codes from the `state` include object (FT, NS, INPLAY_1ST_HALF, …). */
    private static function mapStateCode(string $code): ?string
    {
        $c = strtoupper(trim($code));
        if ($c === '') return null;
        return match ($c) {
            'NS', 'TBA', 'DELAYED', 'AU', 'PENDING' => 'SCHEDULED',
            'LIVE', 'INPLAY_1ST_HALF', 'INPLAY_2ND_HALF', 'HT', 'BREAK', 'INPLAY_ET', 'EXTRA_TIME_BREAK', 'INPLAY_PENALTIES', 'PEN_BREAK' => 'LIVE',
            'FT', 'AET', 'FT_PEN', 'WO', 'AWARDED' => 'FINISHED',
            'POSTPONED' => 'POSTPONED',
            'SUSPENDED', 'ABANDONED', 'INTERRUPTED' => 'SUSPENDED',
            'CANCELLED', 'DELETED' => 'CANCELLED',
            default => null,
        };
    }

    /** Legacy v2 numeric `status` codes (v3 payloads use state_id instead). */
    private function mapSportMonksStatus(int $statusCode): string
    {
        // Legacy v2 status codes:
        // 6 = not started, 0 = TBD, 7/9/31 = live, 33/100 = finished
        // 40/50/60/70 = extras (extra time, penalties, etc.)
        // 35/45/55/80/100 = various end states
        // 60 = cancelled, 70 = postponed, 80 = suspended
        return match ($statusCode) {
            6, 0, 25, 26, 27 => 'SCHEDULED',
            7, 9, 10, 11, 31 => 'LIVE',
            33, 100, 40, 45, 55, 120 => 'FINISHED',
            50, 60, 110 => 'CANCELLED',
            70, 105 => 'POSTPONED',
            80, 90 => 'SUSPENDED',
            default => 'SCHEDULED',
        };
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Legacy single-class adapter — retained for backward compatibility with
// SportsIntelligence::registerProviders() and existing tests.
// Delegates to the dedicated provider classes above.
// ─────────────────────────────────────────────────────────────────────────────

class FootballApiProvider implements SportsDataProvider
{
    private SportsDataProvider $delegate;

    public function __construct(string $id, string $base, string $token, string $kind, int $timeout = 10, ?callable $transport = null)
    {
        $this->delegate = match ($kind) {
            'api-football' => new ApiFootballProvider($token, $base, $timeout, $transport),
            'thesportsdb' => new TheSportsDbProvider($token, $base, $timeout, $transport),
            'sportmonks' => new SportMonksProvider($token, $base, $timeout, $transport),
            default => new ApiFootballProvider($token, $base, $timeout, $transport),
        };
    }

    public function id(): string { return $this->delegate->id(); }
    public function health(): array { return $this->delegate->health(); }
    public function fixtures(array $query): array { return $this->delegate->fixtures($query); }
    public function odds(string $fixtureExternalId): array { return $this->delegate->odds($fixtureExternalId); }
    public function results(string $fixtureExternalId): array { return $this->delegate->results($fixtureExternalId); }
}
