<?php
namespace AIWorkforce\Football;

use AIWorkforce\Persistence\AuditRepository;
use AIWorkforce\Persistence\FootballRepository;
use AIWorkforce\Sports\Providers\SportsDataProvider;

/**
 * Statistics collection: team season statistics, league statistics, recent-form
 * strings, head-to-head samples and in-match statistics — all from the
 * provider, all persisted with a coverage map.
 *
 * Quota discipline (the reason this class exists next to the naive "fetch per
 * team" approach): one `standings` call answers every team in a competition, so
 * it is tried first and cached per sweep. Per-team endpoints are only asked for
 * what the standings did not include. Recent form is derived from fixtures
 * ALREADY stored locally — deriving it costs nothing and cannot invent a match.
 */
final class StatisticsCollector
{
    /** @var array<string,array<string,mixed>> leagueId|season => stored aggregate */
    private array $leagueAggregate = [];
    /** @var array<string,bool> */
    private array $attempted = [];

    public function __construct(
        private FootballRepository $repo,
        private ProviderGateway $gateway,
        private FootballConfiguration $config,
        private ?AuditRepository $audit = null,
    ) {}

    /**
     * Pull the league table for a competition and store one team-statistics row
     * per team. Returns the number of rows written; a provider failure is
     * recorded, not swallowed, and leaves the previous rows untouched.
     *
     * @return array{status:string, teams:int, errors:list<string>, requests:int}
     */
    public function collectLeagueStatistics(int $providerId, string $providerCode, string $leagueExternalId, string $season): array
    {
        $key = $providerCode . '|' . $leagueExternalId . '|' . $season;
        if (isset($this->leagueAggregate[$key])) return ['status' => 'CACHED', 'teams' => count($this->leagueAggregate[$key]), 'errors' => [], 'requests' => 0];
        if (!$this->gateway->supports('standings')) {
            return ['status' => 'UNSUPPORTED', 'teams' => 0, 'errors' => ['standings not supported by ' . $providerCode], 'requests' => 0];
        }
        $attempt = $this->gateway->call('standings', function (SportsDataProvider $provider) use ($leagueExternalId, $season) {
            if (!method_exists($provider, 'standings')) return [];
            return $provider->standings($leagueExternalId, $season);
        }, $providerCode);
        if (!$attempt['ok']) {
            return ['status' => 'FAILED', 'teams' => 0, 'errors' => array_values($attempt['failures']), 'requests' => $this->gateway->requestsMade()];
        }
        $rows = (array) $attempt['result'];
        $stored = 0;
        $totals = ['played' => 0, 'goalsFor' => 0, 'goalsAgainst' => 0, 'homePlayed' => 0, 'homeGoalsFor' => 0, 'homeGoalsAgainst' => 0, 'awayPlayed' => 0, 'awayGoalsFor' => 0, 'awayGoalsAgainst' => 0, 'teams' => 0];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $teamId = (string) ($row['teamId'] ?? '');
            if ($teamId === '') continue;
            $coverage = [
                'played' => isset($row['played']) && (int) $row['played'] > 0,
                'goals' => isset($row['goalsFor'], $row['goalsAgainst']),
                'venue' => isset($row['homePlayed'], $row['awayPlayed']) && (int) $row['homePlayed'] > 0 && (int) $row['awayPlayed'] > 0,
            ];
            $present = count(array_filter($coverage));
            $this->repo->saveTeamStatistics($providerId, [
                'teamExternalId' => $teamId,
                'competitionExternalId' => $leagueExternalId,
                'season' => $season !== '' ? $season : null,
                'team' => (string) ($row['team'] ?? 'DATA_UNAVAILABLE'),
                'played' => $row['played'] ?? null, 'wins' => $row['wins'] ?? null, 'draws' => $row['draws'] ?? null, 'losses' => $row['losses'] ?? null,
                'goalsFor' => $row['goalsFor'] ?? null, 'goalsAgainst' => $row['goalsAgainst'] ?? null, 'points' => $row['points'] ?? null, 'position' => $row['rank'] ?? null,
                'homePlayed' => $row['homePlayed'] ?? null, 'homeWins' => $row['homeWins'] ?? null, 'homeDraws' => $row['homeDraws'] ?? null, 'homeLosses' => $row['homeLosses'] ?? null,
                'homeGoalsFor' => $row['homeGoalsFor'] ?? null, 'homeGoalsAgainst' => $row['homeGoalsAgainst'] ?? null,
                'awayPlayed' => $row['awayPlayed'] ?? null, 'awayWins' => $row['awayWins'] ?? null, 'awayDraws' => $row['awayDraws'] ?? null, 'awayLosses' => $row['awayLosses'] ?? null,
                'awayGoalsFor' => $row['awayGoalsFor'] ?? null, 'awayGoalsAgainst' => $row['awayGoalsAgainst'] ?? null,
                'dataState' => $present >= 3 ? DataState::AVAILABLE : ($present > 0 ? DataState::LIMITED : DataState::UNAVAILABLE),
                'coverage' => array_keys(array_filter($coverage)),
                // Venue rates are stored alongside the raw counts so the model
                // never has to guess a denominator: rate = goals / matches, and
                // null when that league table had no venue split at all.
                'payload' => $row + [
                    'source' => $providerCode . ':standings',
                    'attackRate' => self::rate($row['goalsFor'] ?? null, $row['played'] ?? null),
                    'defenseRate' => self::rate($row['goalsAgainst'] ?? null, $row['played'] ?? null),
                    'homeAttackRate' => self::rate($row['homeGoalsFor'] ?? null, $row['homePlayed'] ?? null),
                    'homeDefenseRate' => self::rate($row['homeGoalsAgainst'] ?? null, $row['homePlayed'] ?? null),
                    'awayAttackRate' => self::rate($row['awayGoalsFor'] ?? null, $row['awayPlayed'] ?? null),
                    'awayDefenseRate' => self::rate($row['awayGoalsAgainst'] ?? null, $row['awayPlayed'] ?? null),
                ],
                'fetchedAt' => gmdate('c'),
            ]);
            $stored++;
            $totals['teams']++;
            foreach (['played' => 'played', 'goalsFor' => 'goalsFor', 'goalsAgainst' => 'goalsAgainst'] as $total => $field) {
                $totals[$total] += (int) ($row[$field] ?? 0);
            }
            foreach (['homePlayed', 'homeGoalsFor', 'homeGoalsAgainst', 'awayPlayed', 'awayGoalsFor', 'awayGoalsAgainst'] as $field) {
                $totals[$field] += (int) ($row[$field] ?? 0);
            }
        }
        if ($totals['teams'] > 0) {
            // League baseline rates are derived from the same table, never from
            // a hard-coded "average football match" constant.
            $this->leagueAggregate[$key] = [
                'teams' => $totals['teams'],
                'avgGoalsPerTeam' => $totals['played'] > 0 ? round($totals['goalsFor'] / $totals['played'], 4) : null,
                'avgGoalsAgainstPerTeam' => $totals['played'] > 0 ? round($totals['goalsAgainst'] / $totals['played'], 4) : null,
                'avgHomeGoals' => $totals['homePlayed'] > 0 ? round($totals['homeGoalsFor'] / $totals['homePlayed'], 4) : null,
                'avgHomeConceded' => $totals['homePlayed'] > 0 ? round($totals['homeGoalsAgainst'] / $totals['homePlayed'], 4) : null,
                'avgAwayGoals' => $totals['awayPlayed'] > 0 ? round($totals['awayGoalsFor'] / $totals['awayPlayed'], 4) : null,
                'avgAwayConceded' => $totals['awayPlayed'] > 0 ? round($totals['awayGoalsAgainst'] / $totals['awayPlayed'], 4) : null,
                'source' => $providerCode . ':standings',
            ];
        }
        return ['status' => $stored > 0 ? 'COMPLETED' : 'EMPTY', 'teams' => $stored, 'errors' => [], 'requests' => $this->gateway->requestsMade(), 'aggregate' => $this->leagueAggregate[$key] ?? null];
    }

    /**
     * Per-team fallback when the league table lacks venue splits (or is
     * unavailable): one provider call, stored with its own coverage.
     */
    public function collectTeamStatistics(int $providerId, string $providerCode, string $teamExternalId, string $teamName, string $leagueExternalId, string $season): array
    {
        $key = $providerCode . '|team|' . $teamExternalId . '|' . $leagueExternalId . '|' . $season;
        if (isset($this->attempted[$key])) return ['status' => 'CACHED', 'stored' => false];
        $this->attempted[$key] = true;
        if (!$this->gateway->supports('teamStatistics')) {
            return ['status' => 'UNSUPPORTED', 'stored' => false, 'errors' => ['team statistics not supported by ' . $providerCode]];
        }
        $attempt = $this->gateway->call('teamStatistics', function (SportsDataProvider $provider) use ($teamExternalId, $leagueExternalId, $season) {
            if (!method_exists($provider, 'teamStatistics')) return [];
            return $provider->teamStatistics($teamExternalId, $leagueExternalId, $season);
        }, $providerCode);
        if (!$attempt['ok']) {
            return ['status' => 'FAILED', 'stored' => false, 'errors' => array_values($attempt['failures'])];
        }
        $stats = (array) $attempt['result'];
        if ($stats === []) return ['status' => 'EMPTY', 'stored' => false];
        $coverage = [
            'played' => (int) ($stats['played'] ?? 0) > 0,
            'goalsFor' => isset($stats['goalsForTotal']),
            'goalsAgainst' => isset($stats['goalsAgainstTotal']),
            'homeAttack' => isset($stats['goalsForHomeAverage']),
            'awayAttack' => isset($stats['goalsForAwayAverage']),
            'homeDefense' => isset($stats['goalsAgainstHomeAverage']),
            'awayDefense' => isset($stats['goalsAgainstAwayAverage']),
        ];
        $present = count(array_filter($coverage));
        $this->repo->saveTeamStatistics($providerId, [
            'teamExternalId' => $teamExternalId,
            'competitionExternalId' => $leagueExternalId,
            'season' => $season !== '' ? $season : null,
            'team' => $teamName !== '' ? $teamName : (string) ($stats['team'] ?? 'DATA_UNAVAILABLE'),
            'played' => $stats['played'] ?? null,
            'goalsFor' => $stats['goalsForTotal'] ?? null,
            'goalsAgainst' => $stats['goalsAgainstTotal'] ?? null,
            'homePlayed' => $stats['playedHome'] ?? null,
            'awayPlayed' => $stats['playedAway'] ?? null,
            'homeWins' => $stats['winsHome'] ?? null,
            'awayWins' => $stats['winsAway'] ?? null,
            'homeDraws' => $stats['drawsHome'] ?? null,
            'awayDraws' => $stats['drawsAway'] ?? null,
            'homeLosses' => $stats['losesHome'] ?? null,
            'awayLosses' => $stats['losesAway'] ?? null,
            'dataState' => $present >= 6 ? DataState::AVAILABLE : ($present >= 3 ? DataState::LIMITED : DataState::UNAVAILABLE),
            'coverage' => array_keys(array_filter($coverage)),
            'cleanSheets' => $stats['cleanSheets'] ?? null,
            'failedToScore' => $stats['failToScore'] ?? null,
            'payload' => $stats + ['source' => $providerCode . ':team-statistics'],
            'fetchedAt' => gmdate('c'),
        ]);
        return ['status' => 'COMPLETED', 'stored' => true];
    }

    /** Per-match rate, null-preserving: no division is faked as 0. */
    private static function rate(mixed $total, mixed $matches): ?float
    {
        if (!is_numeric($total) || !is_numeric($matches) || (int) $matches <= 0) return null;
        return round(((float) $total) / (int) $matches, 4);
    }

    /**
     * Head-to-head snapshot with an explicit, sample-driven weight.
     *
     * Two meetings of a five-year-old rivalry must not move a prediction the
     * way twenty recent meetings would, so the weight is computed from what was
     * actually found: sample size, then age decay. A provider that cannot answer
     * stores nothing and the caller reports DATA_UNAVAILABLE.
     */
    public function collectHeadToHead(int $providerId, string $providerCode, array $fixture): array
    {
        $homeId = (string) ($fixture['home_team_id'] ?? '');
        $awayId = (string) ($fixture['away_team_id'] ?? '');
        $leagueExternal = (string) ($this->competitionExternalId($fixture) ?? '');
        if ($homeId === '' || $awayId === '') {
            return ['status' => 'SKIPPED', 'reason' => 'TEAM_IDS_UNAVAILABLE', 'weight' => 0.0];
        }
        if (!$this->gateway->supports('headToHead')) {
            return ['status' => 'UNSUPPORTED', 'reason' => 'PROVIDER_HAS_NO_HEAD_TO_HEAD', 'weight' => 0.0];
        }
        $season = (string) ($fixture['season'] ?? '');
        $attempt = $this->gateway->call('headToHead', function (SportsDataProvider $provider) use ($homeId, $awayId, $leagueExternal) {
            if (!method_exists($provider, 'headToHead')) return [];
            return $provider->headToHead($homeId, $awayId, 10, $leagueExternal !== '' ? $leagueExternal : null);
        }, $providerCode);
        if (!$attempt['ok']) {
            return ['status' => 'FAILED', 'errors' => array_values($attempt['failures']), 'weight' => 0.0];
        }
        $meetings = array_values(array_filter((array) $attempt['result'], static fn($row) => is_array($row)
            && ($row['status'] ?? '') === 'FINISHED' && isset($row['homeScore'], $row['awayScore'])));
        if ($meetings === []) {
            return ['status' => 'EMPTY', 'reason' => 'NO_STORED_MEETINGS', 'weight' => 0.0];
        }
        $homeWins = $draws = $awayWins = 0; $homeGoals = 0; $awayGoals = 0; $btts = 0; $over15 = 0; $over25 = 0;
        $oldest = null; $newest = null; $rows = [];
        foreach ($meetings as $meeting) {
            $h = (int) $meeting['homeScore']; $a = (int) $meeting['awayScore'];
            if ($h > $a) $homeWins++; elseif ($h === $a) $draws++; else $awayWins++;
            $homeGoals += $h; $awayGoals += $a;
            if ($h > 0 && $a > 0) $btts++;
            if ($h + $a > 1.5) $over15++;
            if ($h + $a > 2.5) $over25++;
            $kickoff = (string) ($meeting['kickoff'] ?? '');
            if ($kickoff !== '') {
                $oldest = $oldest === null || $kickoff < $oldest ? $kickoff : $oldest;
                $newest = $newest === null || $kickoff > $newest ? $kickoff : $newest;
            }
            $rows[] = ['kickoff' => $kickoff, 'home' => $meeting['homeTeam'] ?? null, 'away' => $meeting['awayTeam'] ?? null,
                'homeScore' => $h, 'awayScore' => $a, 'competition' => $meeting['competition'] ?? null];
        }
        $count = count($meetings);
        $ageDays = null;
        if ($newest !== null) {
            try { $ageDays = max(0, (int) floor((time() - (new \DateTimeImmutable($newest))->getTimestamp()) / 86400)); } catch (\Throwable $e) { $ageDays = null; }
        }
        $weight = $this->headToHeadWeight($count, $ageDays);
        $this->repo->saveHeadToHead($providerId, [
            'homeTeamExternalId' => $homeId,
            'awayTeamExternalId' => $awayId,
            'competitionExternalId' => $leagueExternal !== '' ? $leagueExternal : null,
            'meetings' => $count,
            'homeWins' => $homeWins, 'draws' => $draws, 'awayWins' => $awayWins,
            'avgHomeGoals' => round($homeGoals / $count, 3),
            'avgAwayGoals' => round($awayGoals / $count, 3),
            'bothTeamsScored' => $btts, 'over15' => $over15, 'over25' => $over25,
            'oldestKickoff' => $oldest, 'newestKickoff' => $newest,
            'sampleAgeDays' => $ageDays,
            'weight' => $weight,
            'dataState' => $count >= 5 && $weight > 0 ? DataState::AVAILABLE : DataState::LIMITED,
            'matches' => array_slice($rows, 0, 10),
            'fetchedAt' => gmdate('c'),
        ]);
        return ['status' => 'COMPLETED', 'meetings' => $count, 'weight' => $weight, 'dataState' => $count >= 5 ? DataState::AVAILABLE : DataState::LIMITED];
    }

    /**
     * H2H influence, deliberately small and shrinking: 0.5 × the configured cap
     * for a two-match sample, the cap at eight matches, halved again once the
     * newest meeting is older than the configured H2H window
     * (WINDELS_FOOTBALL_MAX_AGE_H2H, three seasons by default).
     */
    public function headToHeadWeight(int $meetings, ?int $ageDays): float
    {
        $cap = $this->config->headToHeadMaxWeight();
        if ($meetings <= 0) return 0.0;
        $sample = min(1.0, $meetings / 8.0);
        $weight = $cap * $sample;
        if ($ageDays !== null && $ageDays > $this->config->headToHeadStaleAfterDays()) $weight *= 0.5;
        return round($weight, 4);
    }

    /**
     * In-match statistics for a live fixture (shots, cards, possession). Stored
     * per kind so a later analysis run can tell "the provider has no stats yet"
     * (no row) from "the provider answered with an empty set" (row,
     * DATA_UNAVAILABLE).
     */
    public function collectFixtureStatistics(int $fixtureId, string $providerCode, string $fixtureExternalId): array
    {
        if (!$this->gateway->supports('fixtureStatistics')) {
            return ['status' => 'UNSUPPORTED', 'stored' => false];
        }
        $attempt = $this->gateway->call('fixtureStatistics', function (SportsDataProvider $provider) use ($fixtureExternalId) {
            if (!method_exists($provider, 'fixtureStatistics')) return [];
            return $provider->fixtureStatistics($fixtureExternalId);
        }, $providerCode);
        if (!$attempt['ok']) return ['status' => 'FAILED', 'stored' => false, 'errors' => array_values($attempt['failures'])];
        $stats = (array) $attempt['result'];
        $providerRow = $this->repo->ensureProvider($providerCode);
        $this->repo->saveFixtureStatistics($fixtureId, (int) $providerRow['id'], 'MATCH', $stats, [
            'state' => $stats === [] ? DataState::UNAVAILABLE : DataState::AVAILABLE,
            'teams' => count($stats),
        ]);
        return ['status' => 'COMPLETED', 'stored' => true, 'empty' => $stats === []];
    }

    /**
     * Recent form from stored, provider-sourced finished fixtures — free of
     * provider cost and impossible to fabricate, because a form string can only
     * be built from results the sync actually wrote.
     *
     * @return array{played:int, wins:int, draws:int, losses:int, goalsFor:int, goalsAgainst:int,
     *               points:int, string:string, streak:int, state:string, matches:list<array>}
     */
    public function deriveForm(int $providerId, string $teamExternalId, ?string $venue = null, int $limit = 10): array
    {
        $fixtures = $this->repo->listTeamRecentResults($providerId, $teamExternalId, max(1, min(20, $limit)));
        $form = ['played' => 0, 'wins' => 0, 'draws' => 0, 'losses' => 0, 'goalsFor' => 0, 'goalsAgainst' => 0, 'points' => 0,
            'string' => '', 'streak' => 0, 'state' => DataState::UNAVAILABLE, 'matches' => []];
        $letters = '';
        foreach ($fixtures as $fixture) {
            $homeId = (string) ($fixture['home_team_id'] ?? '');
            $teamWasHome = $homeId === $teamExternalId;
            if (!$teamWasHome && (string) ($fixture['away_team_id'] ?? '') !== $teamExternalId) continue;
            if ($venue === 'home' && !$teamWasHome) continue;
            if ($venue === 'away' && $teamWasHome) continue;
            $isHome = $teamWasHome;
            $homeScore = $fixture['home_score']; $awayScore = $fixture['away_score'];
            if ($homeScore === null || $awayScore === null) continue;
            $for = (int) ($isHome ? $homeScore : $awayScore);
            $against = (int) ($isHome ? $awayScore : $homeScore);
            $letter = $for > $against ? 'W' : ($for === $against ? 'D' : 'L');
            $form['played']++;
            $form['goalsFor'] += $for;
            $form['goalsAgainst'] += $against;
            if ($letter === 'W') { $form['wins']++; $form['points'] += 3; }
            elseif ($letter === 'D') { $form['draws']++; $form['points']++; }
            else { $form['losses']++; }
            $letters .= $letter;
            $form['matches'][] = ['kickoff' => $fixture['kickoff_at'] ?? null, 'opponent' => $isHome ? ($fixture['away_team'] ?? null) : ($fixture['home_team'] ?? null),
                'venue' => $isHome ? 'HOME' : 'AWAY', 'for' => $for, 'against' => $against, 'result' => $letter,
                'competition' => $fixture['competition'] ?? null, 'fixtureId' => $fixture['id'] ?? null];
        }
        $form['string'] = $letters;
        // streak = consecutive identical results from the most recent match
        if ($letters !== '') {
            $first = $letters[0];
            $streak = 0;
            foreach (str_split($letters) as $char) { if ($char === $first) $streak++; else break; }
            $form['streak'] = $streak;
        }
        $form['state'] = match (true) {
            $form['played'] === 0 => DataState::UNAVAILABLE,
            $form['played'] < 5 => DataState::LIMITED,
            default => DataState::AVAILABLE,
        };
        return $form;
    }

    /** @return array<string,mixed>|null league aggregate for a fixture */
    public function leagueAggregateFor(array $fixture, string $providerCode): ?array
    {
        $league = (string) ($this->competitionExternalId($fixture) ?? '');
        $season = (string) ($fixture['season'] ?? '');
        if ($league === '') {
            // Fall back to a name-scoped aggregate when the provider did not send
            // a league id — still only data that was actually collected.
            $name = strtolower(trim((string) ($fixture['competition'] ?? '')));
            if ($name === '') return null;
            foreach ($this->leagueAggregate as $key => $aggregate) {
                if (str_starts_with($key, $providerCode . '|' . $name)) return $aggregate;
            }
            return null;
        }
        return $this->leagueAggregate[$providerCode . '|' . $league . '|' . $season] ?? null;
    }

    private function competitionExternalId(array $fixture): ?string
    {
        $payload = is_array($fixture['payload'] ?? null) ? $fixture['payload'] : [];
        foreach (['leagueId', 'league_id'] as $key) {
            if (isset($payload[$key]) && (string) $payload[$key] !== '') return (string) $payload[$key];
        }
        return isset($fixture['competition_external_id']) && (string) $fixture['competition_external_id'] !== ''
            ? (string) $fixture['competition_external_id']
            : null;
    }
}
