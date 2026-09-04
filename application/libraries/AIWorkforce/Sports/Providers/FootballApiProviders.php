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
                    ]);
                    $body = curl_exec($ch);
                    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    if ($body !== false) {
                        return ['status' => $status, 'body' => (string) $body];
                    }
                    if ($status > 0) {
                        return ['status' => $status, 'body' => ''];
                    }
                }
            }
            if (!ini_get('allow_url_fopen')) {
                return ['status' => 0, 'body' => ''];
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
            $body = @file_get_contents($url, false, $ctx);
            $status = 0;
            if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d+)#', $http_response_header[0], $m)) {
                $status = (int) $m[1];
            }
            return ['status' => $status, 'body' => is_string($body) ? $body : ''];
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
            throw new ProviderException('provider transport failure: ' . $e->getMessage(), ProviderException::OFFLINE, $e);
        }
        $this->lastResponseMs = (int) round((microtime(true) - $t0) * 1000);
        $s = (int) ($resp['status'] ?? 0);
        if ($s === 0) { $this->failures++; $this->lastFailure = gmdate('c'); throw new ProviderException('no HTTP response (timeout/unreachable)', ProviderException::OFFLINE); }
        if ($s === 401 || $s === 403) { $this->failures++; $this->lastFailure = gmdate('c'); throw new ProviderException('authentication rejected (HTTP ' . $s . ')', ProviderException::AUTHENTICATION_ERROR); }
        if ($s === 429) { $this->failures++; $this->lastFailure = gmdate('c'); throw new ProviderException('rate limited (HTTP 429)', ProviderException::RATE_LIMITED); }
        if ($s >= 500) { $this->failures++; $this->lastFailure = gmdate('c'); throw new ProviderException('provider server error (HTTP ' . $s . ')', ProviderException::DATA_ERROR); }
        if ($s >= 400) { $this->failures++; $this->lastFailure = gmdate('c'); throw new ProviderException('provider client error (HTTP ' . $s . ')', ProviderException::DATA_ERROR); }
        $this->lastSuccess = gmdate('c');
        return $resp;
    }

    protected function decodeJson(array $resp): array
    {
        $decoded = json_decode($resp['body'] ?? '', true);
        if (!is_array($decoded)) throw new ProviderException('invalid JSON response', ProviderException::DATA_ERROR);
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
            return [
                'status' => 'ONLINE',
                'reliability' => $this->reliability(),
                'errorRate' => $this->errorRate(),
                'responseMs' => $this->lastResponseMs,
                'lastSuccessAt' => $this->lastSuccess,
                'lastFailureAt' => $this->lastFailure,
                'requestsToday' => $used,
                'limitDaily' => $limit,
            ];
        } catch (ProviderException $e) {
            return [
                'status' => $e->status,
                'reliability' => $this->reliability(),
                'errorRate' => $this->errorRate(),
                'responseMs' => $this->lastResponseMs,
                'lastFailureAt' => $this->lastFailure ?? gmdate('c'),
                'lastSuccessAt' => $this->lastSuccess,
                'detail' => $e->getMessage(),
            ];
        }
    }

    public function fixtures(array $query): array
    {
        $from = (string) ($query['from'] ?? gmdate('Y-m-d'));
        $to = (string) ($query['to'] ?? $from);
        $params = ['from' => $from, 'to' => $to];
        if (!empty($query['league'])) $params['league'] = (string) $query['league'];
        if (!empty($query['season'])) $params['season'] = (string) $query['season'];
        if (!empty($query['status'])) $params['status'] = (string) $query['status'];
        $rows = $this->fetchAllPages('/fixtures', $params);
        return $this->mapFixtures($rows);
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
                    $out[] = [
                        'leagueId' => $leagueId,
                        'season' => $season,
                        'rank' => (int) ($entry['rank'] ?? 0),
                        'team' => $team['name'] ?? '',
                        'teamId' => (string) ($team['id'] ?? ''),
                        'played' => (int) ($entry['all']['played'] ?? 0),
                        'wins' => (int) ($entry['all']['win'] ?? 0),
                        'draws' => (int) ($entry['all']['draw'] ?? 0),
                        'losses' => (int) ($entry['all']['lose'] ?? 0),
                        'goalsFor' => (int) ($entry['all']['goals']['for'] ?? 0),
                        'goalsAgainst' => (int) ($entry['all']['goals']['against'] ?? 0),
                        'points' => (int) ($entry['points'] ?? 0),
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
        $out['goalsForAverage'] = round(($goals['for']['average']['home'] ?? 0 + $goals['for']['average']['away'] ?? 0) / 2, 2);
        $out['goalsAgainstAverage'] = round(($goals['against']['average']['home'] ?? 0 + $goals['against']['average']['away'] ?? 0) / 2, 2);
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
     * api-football v3 list endpoints (fixtures, odds, leagues, ...) report
     * `paging: {current, total}` in each response and are paged with the
     * `page` parameter (50/page for fixtures, 10/page for odds, ...). Reading
     * only the first page silently truncates busy days, full-season queries
     * and fixtures with many bookmaker markets. Follow the pages until
     * current >= total, hard-capped at 40 pages so a bad `total` cannot loop.
     */
    private function fetchAllPages(string $path, array $params, int $maxPages = 40): array
    {
        $rows = [];
        $page = 1;
        do {
            $resp = $this->doRequest($path . '?' . http_build_query($params + ['page' => $page]));
            $json = $this->decodeJson($resp);
            $rows = array_merge($rows, $this->extractList($json));
            $paging = is_array($json['paging'] ?? null) ? $json['paging'] : [];
            $current = (int) ($paging['current'] ?? $page);
            $total = (int) ($paging['total'] ?? 1);
            $page++;
        } while ($current < $total && $page <= $maxPages);
        return $rows;
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
                'sourceTimestamp' => gmdate('c'),
            ];
        }
        return $out;
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

    public function __construct(
        private string $apiKey = '3',
        private string $baseUrl = 'https://www.thesportsdb.com/api/v1/json',
        private int $timeout = 10,
        ?callable $transport = null,
    ) {
        $this->initTransport($timeout, $transport);
    }

    public function id(): string { return 'thesportsdb'; }

    public function health(): array
    {
        try {
            $resp = $this->doRequest('/eventsday.php?d=' . gmdate('Y-m-d') . '&s=Soccer');
            $this->decodeJson($resp);
            return [
                'status' => 'ONLINE',
                'reliability' => $this->reliability(),
                'errorRate' => $this->errorRate(),
                'responseMs' => $this->lastResponseMs,
                'lastSuccessAt' => $this->lastSuccess,
                'lastFailureAt' => $this->lastFailure,
                'tier' => $this->apiKey === '3' ? 'free' : 'premium',
            ];
        } catch (ProviderException $e) {
            return [
                'status' => $e->status,
                'reliability' => $this->reliability(),
                'errorRate' => $this->errorRate(),
                'responseMs' => $this->lastResponseMs,
                'lastFailureAt' => $this->lastFailure ?? gmdate('c'),
                'lastSuccessAt' => $this->lastSuccess,
                'detail' => $e->getMessage(),
            ];
        }
    }

    public function fixtures(array $query): array
    {
        $from = (string) ($query['from'] ?? gmdate('Y-m-d'));
        $to = (string) ($query['to'] ?? $from);
        $all = [];
        $day = $from;
        $guard = 0;
        while ($day <= $to && $guard++ < 62) {
            try {
                $resp = $this->doRequest('/eventsday.php?d=' . rawurlencode($day) . '&s=Soccer');
                $json = $this->decodeJson($resp);
                $events = $json['events'] ?? [];
                if (is_array($events)) $all = array_merge($all, $events);
            } catch (ProviderException $e) {
                // single-day failure: skip and continue
            }
            $day = gmdate('Y-m-d', strtotime($day . ' +1 day'));
        }
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
        ];
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
                'sourceTimestamp' => gmdate('c'),
            ];
        }
        return $out;
    }

    private function mapResults(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $status = $this->mapStatus($r['strStatus'] ?? '');
            $out[] = [
                'externalId' => (string) ($r['idEvent'] ?? ''),
                'status' => $status,
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
        $this->initTransport($timeout, $transport);
    }

    public function id(): string { return 'sportmonks'; }

    public function health(): array
    {
        try {
            $resp = $this->doRequest('/leagues');
            $json = $this->decodeJson($resp);
            return [
                'status' => 'ONLINE',
                'reliability' => $this->reliability(),
                'errorRate' => $this->errorRate(),
                'responseMs' => $this->lastResponseMs,
                'lastSuccessAt' => $this->lastSuccess,
                'lastFailureAt' => $this->lastFailure,
            ];
        } catch (ProviderException $e) {
            return [
                'status' => $e->status,
                'reliability' => $this->reliability(),
                'errorRate' => $this->errorRate(),
                'responseMs' => $this->lastResponseMs,
                'lastFailureAt' => $this->lastFailure ?? gmdate('c'),
                'lastSuccessAt' => $this->lastSuccess,
                'detail' => $e->getMessage(),
            ];
        }
    }

    public function fixtures(array $query): array
    {
        $from = (string) ($query['from'] ?? gmdate('Y-m-d'));
        $to = (string) ($query['to'] ?? $from);
        $params = [];
        if (!empty($query['league'])) $params['leagues'] = (string) $query['league'];
        $filter = 'starts_between:' . $from . ',' . $to;
        $includes = 'participants;scores;league;venue;referee';
        // /fixtures paginates (25 per page by default). Follow the cursor
        // until has_more=false so a busy multi-league day is not silently
        // truncated to the first page. Hard cap: 40 pages (2000 fixtures).
        $rows = [];
        $cursor = null;
        $pages = 0;
        do {
            $pageParams = $params;
            if ($cursor !== null) {
                $pageParams['cursor'] = $cursor;
            } else {
                $pageParams['per_page'] = 50;
            }
            $qs = http_build_query(array_merge($pageParams, ['include' => $includes]));
            $resp = $this->doRequest('/fixtures?filter[' . $filter . ']&' . $qs);
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
            if ($e->status !== ProviderException::DATA_ERROR) throw $e;
            // The odds include may not be on this subscription (include
            // exception 5013) — retry once without odds so the round is
            // still usable for fixtures + results. Mirrors the odds()
            // add-on fallback.
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
        try {
            $resp = $this->doRequest('/odds/fixtures/' . rawurlencode($fixtureExternalId) . '?include=bookmaker;market;selection');
            $rows = $this->extractList($this->decodeJson($resp));
            return $this->mapOdds($rows);
        } catch (ProviderException $e) {
            // Odds add-on not subscribed — graceful fallback
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

    /** Fetch standings for a league + season. */
    public function standings(string $leagueId, string $season): array
    {
        $resp = $this->doRequest('/standings/seasons/' . rawurlencode($season) . '?leagues=' . rawurlencode($leagueId));
        $rows = $this->extractList($this->decodeJson($resp));
        $out = [];
        foreach ($rows as $r) {
            foreach (($r['standings'] ?? []) as $entry) {
                $out[] = [
                    'leagueId' => $leagueId,
                    'season' => $season,
                    'rank' => (int) ($entry['rank'] ?? 0),
                    'team' => $entry['team']['name'] ?? '',
                    'teamId' => (string) ($entry['team']['id'] ?? ''),
                    'played' => (int) ($entry['results']['played'] ?? 0),
                    'wins' => (int) ($entry['results']['wins'] ?? 0),
                    'draws' => (int) ($entry['results']['draws'] ?? 0),
                    'losses' => (int) ($entry['results']['losses'] ?? 0),
                    'goalsFor' => (int) ($entry['results']['goals_scored'] ?? 0),
                    'goalsAgainst' => (int) ($entry['results']['goals_conceded'] ?? 0),
                    'points' => (int) ($entry['results']['points'] ?? 0),
                ];
            }
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

    /** Fetch squad/lineup for a fixture. */
    public function lineups(string $fixtureId): array
    {
        try {
            $resp = $this->doRequest('/lineups/fixtures/' . rawurlencode($fixtureId) . '?include=player;position');
            $rows = $this->extractList($this->decodeJson($resp));
            return array_map(fn($r) => [
                'fixtureId' => $fixtureId,
                'teamId' => (string) ($r['team']['id'] ?? ''),
                'team' => $r['team']['name'] ?? '',
                'playerId' => (string) ($r['player']['id'] ?? ''),
                'player' => $r['player']['name'] ?? '',
                'position' => $r['position'] ?? '',
                'starter' => !empty($r['starter']),
            ], $rows);
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
                'round' => $r['round']['name'] ?? null,
                'roundId' => isset($r['round_id']) && $r['round_id'] !== null ? (string) $r['round_id'] : '',
                'sourceTimestamp' => gmdate('c'),
            ];
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
