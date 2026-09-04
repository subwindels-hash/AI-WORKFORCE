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
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => $timeout,
                    'header' => implode("\r\n", $headers),
                    'ignore_errors' => true,
                ],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $body = @file_get_contents($url, false, $ctx);
            $status = 0;
            if (isset($http_response_header[0]) && preg_match('#HTTP/\S+\s+(\d+)#', $http_response_header[0], $m)) {
                $status = (int) $m[1];
            }
            return ['status' => $status, 'body' => (string) $body];
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
        $this->initTransport($timeout, $transport);
    }

    public function id(): string { return 'api-football'; }

    public function health(): array
    {
        try {
            $resp = $this->doRequest('/status');
            $json = $this->decodeJson($resp);
            $rateInfo = $json['response'] ?? [];
            return [
                'status' => 'ONLINE',
                'reliability' => $this->reliability(),
                'errorRate' => $this->errorRate(),
                'responseMs' => $this->lastResponseMs,
                'lastSuccessAt' => $this->lastSuccess,
                'lastFailureAt' => $this->lastFailure,
                'requestsToday' => $rateInfo['requests'] ?? null,
                'limitDaily' => $rateInfo['limit_day'] ?? null,
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
        $qs = http_build_query($params);
        $resp = $this->doRequest('/fixtures?' . $qs);
        $rows = $this->extractList($this->decodeJson($resp));
        return $this->mapFixtures($rows);
    }

    public function odds(string $fixtureExternalId): array
    {
        $resp = $this->doRequest('/odds?fixture=' . rawurlencode($fixtureExternalId));
        $rows = $this->extractList($this->decodeJson($resp));
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

    /** Fetch available leagues for football. */
    public function leagues(?string $country = null): array
    {
        $params = $country ? ['country' => $country] : [];
        $qs = http_build_query($params);
        $resp = $this->doRequest('/leagues' . ($qs ? '?' . $qs : ''));
        $rows = $this->extractList($this->decodeJson($resp));
        return array_map(fn($r) => [
            'leagueId' => (string) ($r['league']['id'] ?? ''),
            'name' => $r['league']['name'] ?? '',
            'country' => $r['country']['name'] ?? '',
            'seasons' => array_map(fn($s) => (string) ($s['year'] ?? ''), $r['seasons'] ?? []),
        ], $rows);
    }

    private function doRequest(string $path): array
    {
        $headers = [
            'Accept: application/json',
            'x-apisports-key: ' . $this->apiKey,
        ];
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
                        $out[] = [
                            'market' => (string) ($bet['name'] ?? 'UNKNOWN'),
                            'selection' => (string) ($v['value'] ?? ''),
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
        $qs = http_build_query(array_merge($params, ['include' => $includes]));
        $resp = $this->doRequest('/fixtures?filter[' . $filter . ']&' . $qs);
        $rows = $this->extractList($this->decodeJson($resp));
        return $this->mapFixtures($rows);
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
                'status' => $this->mapSportMonksStatus($r['status'] ?? 0),
                'sport' => 'football',
                'venue' => $r['venue']['name'] ?? null,
                'referee' => $r['referee']['name'] ?? null,
                'homeTeamId' => $this->participantId($r, 'home'),
                'awayTeamId' => $this->participantId($r, 'away'),
                'homeTeamLogo' => $this->participantLogo($r, 'home'),
                'awayTeamLogo' => $this->participantLogo($r, 'away'),
                'round' => $r['round']['name'] ?? null,
                'sourceTimestamp' => gmdate('c'),
            ];
        }
        return $out;
    }

    private function mapOdds(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $market = $r['market'] ?? [];
            $selection = $r['selection'] ?? [];
            $bookmaker = $r['bookmaker'] ?? [];
            if (!isset($selection['value']) || !isset($r['value'])) continue;
            $out[] = [
                'market' => (string) ($market['name'] ?? 'UNKNOWN'),
                'selection' => (string) ($selection['value'] ?? ''),
                'decimalOdds' => (float) $r['value'],
                'observedAt' => gmdate('c'),
                'bookmaker' => (string) ($bookmaker['name'] ?? ''),
                'fixtureId' => (string) ($r['fixture_id'] ?? ''),
            ];
        }
        return $out;
    }

    private function mapResults(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $scores = $r['scores'] ?? [];
            $out[] = [
                'externalId' => (string) ($r['id'] ?? ''),
                'status' => $this->mapSportMonksStatus($r['status'] ?? 0),
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

    private function mapSportMonksStatus(int $statusCode): string
    {
        // SportMonks status codes (v3):
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
