<?php
/**
 * Shared fixtures for the Football Intelligence suites.
 *
 * Everything here is a *deterministic stand-in for a provider response* — the
 * same shape a real feed returns — so the cases can assert on what the module
 * does with the data it receives. Nothing here seeds a prediction, a
 * settlement or a model metric: those always come out of the pipeline under
 * test, which is the point.
 */

use AIWorkforce\Football\FootballConfiguration;
use AIWorkforce\Football\FootballIntelligence;
use AIWorkforce\Sports\Providers\SportsDataProvider;
use AIWorkforce\Sports\Providers\SportsProviderManager;

if (!class_exists(FxFootballProvider::class, false)) {
/**
 * Fake football data provider: serves fixed fixtures/standings/H2H/live rows and
 * counts calls, so a test can prove a refresh did or did not touch the network.
 */
final class FxFootballProvider implements SportsDataProvider
{
    public int $calls = 0;
    public bool $failFixtures = false;
    public bool $rateLimited = false;

    /** @param array{fixtures:list<array>,standings:list<array>,h2h:list<array>,live:list<array>,matchStats:list<array>} $data */
    public function __construct(private array $data, private string $providerId = 'apifootball') {}

    public function id(): string { return $this->providerId; }
    public function name(): string { return 'Fake Football'; }
    public function health(): array
    {
        return $this->rateLimited
            ? ['status' => 'RATE_LIMITED', 'detail' => 'daily quota reached', 'reliability' => 0.5, 'requestsToday' => $this->calls, 'limitDaily' => 10]
            : ['status' => 'ONLINE', 'detail' => 'harness', 'reliability' => 0.99, 'requestsToday' => $this->calls, 'limitDaily' => 500];
    }
    public function fixtures(array $query): array
    {
        $this->calls++;
        if ($this->failFixtures) {
            throw new \AIWorkforce\Sports\Providers\ProviderException('fixture endpoint unavailable', \AIWorkforce\Sports\Providers\ProviderException::DATA_ERROR);
        }
        $from = (string) ($query['from'] ?? '0000-01-01');
        $to = (string) ($query['to'] ?? '9999-12-31');
        return array_values(array_filter(
            $this->data['fixtures'],
            static fn(array $f): bool => ($f['kickoff'] >= $from . 'T00:00:00+00:00') && ($f['kickoff'] <= $to . 'T23:59:59+00:00')
        ));
    }
    public function odds(string $fixtureExternalId): array { return []; }
    public function results(string $fixtureExternalId): array
    {
        foreach ($this->data['fixtures'] as $f) {
            if (($f['externalId'] ?? '') === $fixtureExternalId) return [$f];
        }
        return [];
    }
    public function liveFixtures(): array { $this->calls++; return $this->data['live'] ?? []; }
    public function fixture(string $externalId): array
    {
        $this->calls++;
        foreach ($this->data['fixtures'] as $f) {
            if (($f['externalId'] ?? '') === $externalId) return $f;
        }
        return [];
    }
    public function headToHead(string $home, string $away, int $last = 10, ?string $league = null): array
    {
        $this->calls++;
        return $this->data['h2h'] ?? [];
    }
    public function fixtureStatistics(string $externalId): array { $this->calls++; return $this->data['matchStats'] ?? []; }
    public function standings(string $leagueId, string $season): array { $this->calls++; return $this->data['standings'] ?? []; }
    public function teamStatistics(string $teamId, string $leagueId, string $season): array
    {
        $this->calls++;
        return [];
    }
}
}

if (!function_exists('fx_fb_team_table')) {
/** The one league table the fake provider serves; four teams, real-looking splits. */
function fx_fb_team_table(): array
{
    return [
        '10' => ['name' => 'Manchester City', 'played' => 12, 'wins' => 9, 'draws' => 2, 'losses' => 1, 'gf' => 28, 'ga' => 9,
            'homePlayed' => 6, 'homeW' => 6, 'homeD' => 0, 'homeL' => 0, 'homeGF' => 17, 'homeGA' => 3,
            'awayPlayed' => 6, 'awayW' => 3, 'awayD' => 2, 'awayL' => 1, 'awayGF' => 11, 'awayGA' => 6],
        '20' => ['name' => 'Everton', 'played' => 12, 'wins' => 3, 'draws' => 4, 'losses' => 5, 'gf' => 12, 'ga' => 18,
            'homePlayed' => 6, 'homeW' => 3, 'homeD' => 2, 'homeL' => 1, 'homeGF' => 8, 'homeGA' => 6,
            'awayPlayed' => 6, 'awayW' => 0, 'awayD' => 2, 'awayL' => 4, 'awayGF' => 4, 'awayGA' => 12],
        '30' => ['name' => 'Brighton', 'played' => 12, 'wins' => 5, 'draws' => 3, 'losses' => 4, 'gf' => 18, 'ga' => 17,
            'homePlayed' => 6, 'homeW' => 4, 'homeD' => 1, 'homeL' => 1, 'homeGF' => 12, 'homeGA' => 7,
            'awayPlayed' => 6, 'awayW' => 1, 'awayD' => 2, 'awayL' => 3, 'awayGF' => 6, 'awayGA' => 10],
        '40' => ['name' => 'Burnley', 'played' => 12, 'wins' => 2, 'draws' => 2, 'losses' => 8, 'gf' => 9, 'ga' => 24,
            'homePlayed' => 6, 'homeW' => 2, 'homeD' => 1, 'homeL' => 3, 'homeGF' => 6, 'homeGA' => 10,
            'awayPlayed' => 6, 'awayW' => 0, 'awayD' => 1, 'awayL' => 5, 'awayGF' => 3, 'awayGA' => 14],
    ];
}
}

if (!function_exists('fx_fb_row')) {
/** One provider fixture row, in the shape a real feed returns. */
function fx_fb_row(string $external, string $kickoff, string $home, string $away, string $homeId, string $awayId,
    string $status = 'SCHEDULED', ?int $homeScore = null, ?int $awayScore = null, ?int $minute = null, array $extra = []): array
{
    return array_merge([
        'externalId' => $external, 'leagueId' => '39', 'competition' => 'Premier League', 'country' => 'England', 'season' => '2026',
        'kickoff' => $kickoff, 'status' => $status, 'minute' => $minute,
        'homeTeam' => $home, 'awayTeam' => $away, 'homeTeamId' => $homeId, 'awayTeamId' => $awayId,
        'homeScore' => $homeScore, 'awayScore' => $awayScore,
        'homeRedCards' => null, 'awayRedCards' => null, 'venue' => 'Etihad', 'sourceTimestamp' => gmdate('c'),
    ], $extra);
}
}

if (!function_exists('fx_fb_provider_data')) {
/**
 * League table, head-to-head sample and eight completed matches per team — the
 * recent results the feature builder needs before it can call a fixture
 * QUALIFIED. `$todays` are the fixtures under test.
 */
function fx_fb_provider_data(array $todays, array $extra = []): array
{
    $teams = fx_fb_team_table();
    $standings = [];
    $rank = 1;
    foreach ($teams as $id => $t) {
        $standings[] = ['leagueId' => '39', 'season' => '2026', 'rank' => $rank++, 'team' => $t['name'], 'teamId' => (string) $id,
            'played' => $t['played'], 'wins' => $t['wins'], 'draws' => $t['draws'], 'losses' => $t['losses'],
            'goalsFor' => $t['gf'], 'goalsAgainst' => $t['ga'], 'points' => $t['wins'] * 3 + $t['draws'],
            'homePlayed' => $t['homePlayed'], 'homeWins' => $t['homeW'], 'homeDraws' => $t['homeD'], 'homeLosses' => $t['homeL'],
            'homeGoalsFor' => $t['homeGF'], 'homeGoalsAgainst' => $t['homeGA'],
            'awayPlayed' => $t['awayPlayed'], 'awayWins' => $t['awayW'], 'awayDraws' => $t['awayD'], 'awayLosses' => $t['awayL'],
            'awayGoalsFor' => $t['awayGF'], 'awayGoalsAgainst' => $t['awayGA']];
    }
    $h2h = [];
    for ($i = 0; $i < 6; $i++) {
        $h2h[] = ['externalId' => 'h2h' . $i, 'kickoff' => gmdate('c', time() - (400 + $i * 200) * 86400), 'status' => 'FINISHED',
            'homeTeam' => 'Manchester City', 'awayTeam' => 'Everton', 'homeTeamId' => '10', 'awayTeamId' => '20',
            'homeScore' => 3 - ($i % 3), 'awayScore' => $i % 2, 'competition' => 'Premier League'];
    }
    $history = [];
    foreach (array_keys($teams) as $teamId) {
        foreach ([1, 2, 3, 4, 5, 6, 7, 8] as $index) {
            $opponent = (string) (10 + (($teamId + $index * 7) % 4) * 10);
            if ($opponent === $teamId) $opponent = (string) ((int) $teamId + 10);
            $isHome = $index % 2 === 0;
            $history[] = fx_fb_row('hist-' . $teamId . '-' . $index, gmdate('c', time() - (14 + $index * 9) * 86400),
                $isHome ? $teams[$teamId]['name'] : ($teams[$opponent]['name'] ?? 'Other'),
                $isHome ? ($teams[$opponent]['name'] ?? 'Other') : $teams[$teamId]['name'],
                $isHome ? $teamId : $opponent, $isHome ? $opponent : $teamId, 'FINISHED',
                1 + (($teamId + $index) % 3), ($index + $teamId) % 2);
        }
    }
    return ['fixtures' => array_merge($todays, $history), 'standings' => $standings, 'h2h' => $h2h,
        'live' => $extra['live'] ?? [], 'matchStats' => $extra['matchStats'] ?? []];
}
}

if (!function_exists('fx_fb_harness')) {
/**
 * A wired module over an in-memory repository: returns
 * `[repo, provider, intelligence, audit]`. The audit collector lets a case
 * assert what was recorded, not only what was returned.
 */
function fx_fb_harness(array $fixtures = [], array $extra = [], array $config = []): array
{
    $repo = new FootballRepositoryStub();
    $audit = new class implements \AIWorkforce\Persistence\AuditRepository {
        public array $events = [];
        public function emit(string $type, string $summary, array $detail = [], string $actor = 'system'): void
        {
            $this->events[] = ['type' => $type, 'summary' => $summary, 'detail' => $detail, 'actor' => $actor];
        }
        public function recent(int $limit = 100): array { return array_slice($this->events, -$limit); }
    };
    $provider = new FxFootballProvider(fx_fb_provider_data($fixtures, $extra));
    $manager = new SportsProviderManager();
    $manager->register($provider);
    $intelligence = new FootballIntelligence($repo, $manager, $audit, new FootballConfiguration($config));
    // Yesterday's and last weeks' results are part of every scenario unless the
    // case opts out: without completed matches there is no form to feature.
    if (empty($extra['skipHistory'])) {
        for ($i = 0; $i < 9; $i++) {
            $intelligence->fixtures()->syncDay(gmdate('Y-m-d', time() - (14 + $i * 9) * 86400), 'seed:hist' . $i, null, -1);
        }
    }
    return [$repo, $provider, $intelligence, $audit];
}
}

if (!function_exists('fx_fb_sync_today')) {
/** Sync today's fixtures and collect the statistics the features need. */
function fx_fb_sync_today(FootballIntelligence $intel, ?string $date = null): array
{
    $date ??= gmdate('Y-m-d');
    $sync = $intel->fixtures()->syncDay($date, 'test:day:' . $date, null, -1);
    $stats = $intel->collectStatisticsForDay($date, 12);
    return ['sync' => $sync, 'statistics' => $stats];
}
}

if (!function_exists('fx_fb_read')) {
/**
 * Read a source file from either runtime: CodeIgniter resolves it against FCPATH,
 * the flat php-wasm harness copies the same files under dashed names. Both run the
 * same assertion rather than one of them skipping it.
 */
    function fx_fb_read(string $relative): string
    {
        $candidates = [];
        if (defined('FCPATH')) $candidates[] = FCPATH . $relative;
        if (defined('TESTSPATH')) $candidates[] = rtrim(TESTSPATH, '/\\') . '/../../' . $relative;
        $candidates[] = '/app/src/' . str_replace(['/', '.php'], ['-', ''], $relative) . '.php';
        foreach ($candidates as $path) {
            if (is_file($path)) return (string) file_get_contents($path);
        }
        throw new RuntimeException('cannot read ' . $relative . ' (looked in: ' . implode(', ', $candidates) . ')');
    }
}
