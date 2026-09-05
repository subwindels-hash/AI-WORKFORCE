<?php
namespace AIWorkforce\Football;

use AIWorkforce\Persistence\FootballRepository;

/**
 * Normalized football features for one fixture.
 *
 * Every feature carries a value AND a data-state, because "we measured 0.8
 * goals conceded" and "we could not measure it" are different facts and must
 * never collapse into the same number. Nothing here is inferred from a default
 * league average: the only fallbacks are explicit and recorded in `provenance`.
 */
final class FeatureBuilder
{
    public function __construct(private FootballRepository $repo, private StatisticsCollector $stats, private FootballConfiguration $config) {}

    /**
     * @return array{fixture:array, teams:array<string,array>, competition:?array,
     *               headToHead:array, coverage:array<string,string>, provenance:array<string,string>, provider:array}
     */
    public function build(array $fixture): array
    {
        $providerId = (int) ($fixture['provider_id'] ?? 0);
        $providerRow = $this->providerOf($fixture);
        $competitionExternal = (string) ($fixture['competition_external_id'] ?? '');
        if ($competitionExternal === '') {
            $payload = is_array($fixture['payload'] ?? null) ? $fixture['payload'] : [];
            $competitionExternal = (string) ($payload['leagueId'] ?? '');
        }
        $season = (string) ($fixture['season'] ?? $fixture['competition_season'] ?? '');
        $coverage = [];
        $provenance = [];
        $teams = [];
        foreach (['HOME' => ['home_team_id', 'home_team'], 'AWAY' => ['away_team_id', 'away_team']] as $venue => [$idKey, $nameKey]) {
            $teamExternalId = (string) ($fixture[$idKey] ?? '');
            $teamName = (string) ($fixture[$nameKey] ?? '');
            $statistics = $teamExternalId === '' ? null : $this->repo->findTeamStatistics($providerId, $teamExternalId, $competitionExternal !== '' ? $competitionExternal : null, $season !== '' ? $season : null);
            $formAll = $teamExternalId === '' ? null : $this->stats->deriveForm($providerId, $teamExternalId, null, 10);
            $formVenue = $teamExternalId === '' ? null : $this->stats->deriveForm($providerId, $teamExternalId, strtolower($venue) === 'home' ? 'home' : 'away', 10);
            $formLast5 = $teamExternalId === '' ? null : $this->stats->deriveForm($providerId, $teamExternalId, null, 5);
            $teams[$venue] = $this->teamProfile($venue, $teamExternalId, $teamName, $statistics, is_array($statistics['payload'] ?? null) ? $statistics['payload'] : [], $formAll, $formVenue, $formLast5, $coverage, $provenance);
        }
        $headToHead = $this->headToHead($providerId, $fixture, $coverage, $provenance);
        $competition = $this->competition($fixture, $coverage, $provenance);
        $fixtureQuality = $this->fixtureCompleteness($fixture, $coverage);
        $inMatch = ($fixture['status'] ?? '') === 'LIVE' || ($fixture['status'] ?? '') === 'FINISHED'
            ? $this->repo->findFixtureStatistics((int) $fixture['id'], 'MATCH') : null;
        $freshness = $this->freshness($fixture, $coverage, $provenance);
        $reliability = $this->providerReliability($providerRow, $coverage, $provenance);

        $score = $this->qualityScore([
            'fixtureCompleteness' => $fixtureQuality,
            'recentMatchCoverage' => min($teams['HOME']['recentMatchCoverage'], $teams['AWAY']['recentMatchCoverage']),
            'teamStatCoverage' => min($teams['HOME']['statCoverage'], $teams['AWAY']['statCoverage']),
            'leagueStatCoverage' => $competition === null ? 0.0 : 100.0 * ($competition['hasStats'] ? 1 : 0.25),
            'headToHead' => $headToHead['coverage'],
            'freshness' => $freshness,
            'providerReliability' => $reliability,
        ]);

        return [
            'fixture' => $fixture,
            'teams' => $teams,
            'competition' => $competition,
            'headToHead' => $headToHead,
            'inMatch' => $inMatch,
            'dataQuality' => $score,
            'coverage' => $coverage,
            'provenance' => $provenance,
            'provider' => [
                'id' => (string) ($providerRow['provider_code'] ?? DataState::UNAVAILABLE),
                'name' => (string) ($providerRow['display_name'] ?? $providerRow['provider_code'] ?? DataState::UNAVAILABLE),
                'status' => strtoupper((string) ($providerRow['status'] ?? 'NOT_CONFIGURED')),
            ],
            'generatedAt' => gmdate('c'),
        ];
    }

    /**
     * Weighted data-quality score (0–100) with the documented weights. Each
     * component is a 0–100 measurement of *coverage*, not of confidence in the
     * outcome: a complete, recent, reliable feed scores high even when the
     * match itself is a coin flip.
     *
     * @param array<string,float|int> $components
     * @return array{score:int, status:string, band:string, components:array<string,array{value:float,weight:float,contribution:float}>, reasons:list<string>, reasonsAbsent:list<string>}
     */
    public function qualityScore(array $components): array
    {
        $weights = [
            'fixtureCompleteness' => 0.15,
            'recentMatchCoverage' => 0.20,
            'teamStatCoverage' => 0.20,
            'leagueStatCoverage' => 0.12,
            'headToHead' => 0.08,
            'freshness' => 0.15,
            'providerReliability' => 0.10,
        ];
        $total = 0.0; $detail = []; $reasons = []; $absent = [];
        foreach ($weights as $key => $weight) {
            $value = max(0.0, min(100.0, (float) ($components[$key] ?? 0.0)));
            $contribution = $value * $weight;
            $total += $contribution;
            $detail[$key] = ['value' => round($value, 1), 'weight' => $weight, 'contribution' => round($contribution, 2)];
            if ($value >= 70) $reasons[] = self::LABEL[$key] . ' available';
            elseif ($value >= 40) $reasons[] = self::LABEL[$key] . ' limited';
            else $absent[] = self::LABEL[$key] . ' unavailable';
        }
        $score = (int) round($total);
        $band = QualityBand::forScore($score);
        return ['score' => $score, 'status' => $band, 'band' => $band, 'components' => $detail, 'reasons' => $reasons, 'reasonsAbsent' => $absent];
    }

    private const LABEL = [
        'fixtureCompleteness' => 'Fixture detail',
        'recentMatchCoverage' => 'Recent-match coverage',
        'teamStatCoverage' => 'Team statistics',
        'leagueStatCoverage' => 'League statistics',
        'headToHead' => 'Head-to-head sample',
        'freshness' => 'Data freshness',
        'providerReliability' => 'Provider reliability',
    ];

    /**
     * @param array<string,mixed>|null $statistics   stored team_statistics row (raw counts)
     * @param array<string,mixed>|null $statsPayload that row's payload, where the
     *        collector recorded the per-match rates it derived from those counts
     */
    private function teamProfile(string $venue, string $externalId, string $name, ?array $statistics, ?array $statsPayload, ?array $formAll, ?array $formVenue, ?array $formLast5, array &$coverage, array &$provenance): array
    {
        $payload = is_array($statsPayload) ? $statsPayload : [];
        $row = is_array($statistics) ? $statistics : [];
        $rate = static fn(mixed $value): ?float => is_numeric($value) && (float) $value >= 0 ? round((float) $value, 4) : null;
        $count = static fn(mixed $value): ?int => is_numeric($value) ? (int) $value : null;
        $divide = static fn(?int $total, ?int $matches): ?float => ($total === null || $matches === null || $matches <= 0) ? null : round($total / $matches, 4);

        $played = $count($row['played'] ?? null);
        $goalsFor = $count($row['goals_for'] ?? null);
        $goalsAgainst = $count($row['goals_against'] ?? null);

        // Preferred order — every step is stored provider data, and the step that
        // answered is recorded in provenance so a number can always be traced:
        //  1. venue rate derived from the league table's home/away split
        //  2. per-venue average the provider itself reported
        //  3. venue counts divided by venue matches
        //  4. season-wide rate (not venue-specific, and labelled as such)
        $side = strtolower($venue);
        $attack = $rate($payload[($venue === 'HOME' ? 'homeAttackRate' : 'awayAttackRate')] ?? null);
        $attackSource = $attack !== null ? 'venue-split:' . $side : null;
        if ($attack === null) {
            $attack = $rate($payload[($venue === 'HOME' ? 'goalsForHomeAverage' : 'goalsForAwayAverage')] ?? null);
            $attackSource = $attack !== null ? 'provider-average:' . $side : null;
        }
        if ($attack === null) {
            $attack = $divide($count($row[($venue === 'HOME' ? 'home_goals_for' : 'away_goals_for')] ?? null), $count($row[($venue === 'HOME' ? 'home_played' : 'away_played')] ?? null));
            $attackSource = $attack !== null ? 'venue-counts:' . $side : null;
        }
        if ($attack === null) {
            $attack = $divide($goalsFor, $played);
            $attackSource = $attack !== null ? 'season-total' : null;
        }

        $defense = $rate($payload[($venue === 'HOME' ? 'homeDefenseRate' : 'awayDefenseRate')] ?? null);
        $defenseSource = $defense !== null ? 'venue-split:' . $side : null;
        if ($defense === null) {
            $defense = $rate($payload[($venue === 'HOME' ? 'goalsAgainstHomeAverage' : 'goalsAgainstAwayAverage')] ?? null);
            $defenseSource = $defense !== null ? 'provider-average:' . $side : null;
        }
        if ($defense === null) {
            $defense = $divide($count($row[($venue === 'HOME' ? 'home_goals_against' : 'away_goals_against')] ?? null), $count($row[($venue === 'HOME' ? 'home_played' : 'away_played')] ?? null));
            $defenseSource = $defense !== null ? 'venue-counts:' . $side : null;
        }
        if ($defense === null) {
            $defense = $divide($goalsAgainst, $played);
            $defenseSource = $defense !== null ? 'season-total' : null;
        }

        $formFor = ($formAll['played'] ?? 0) > 0 ? round($formAll['goalsFor'] / $formAll['played'], 4) : null;
        $formAgainst = ($formAll['played'] ?? 0) > 0 ? round($formAll['goalsAgainst'] / $formAll['played'], 4) : null;
        if ($attack === null && $formFor !== null) { $attack = $formFor; $attackSource = 'last-' . (int) $formAll['played'] . '-matches'; }
        if ($defense === null && $formAgainst !== null) { $defense = $formAgainst; $defenseSource = 'last-' . (int) $formAll['played'] . '-matches'; }

        $cleanSheets = $count($row['clean_sheets'] ?? $payload['cleanSheets'] ?? null);
        $failedToScore = $count($row['failed_to_score'] ?? $payload['failToScore'] ?? null);
        $coverage['teamStatistics:' . $venue] = ($played ?? 0) > 0 ? DataState::AVAILABLE : ($row === [] ? DataState::UNAVAILABLE : DataState::LIMITED);
        $coverage['recentForm:' . $venue] = $formAll['state'] ?? DataState::UNAVAILABLE;
        $provenance['attack:' . $venue] = $attack === null ? DataState::UNAVAILABLE : $attackSource;
        $provenance['defense:' . $venue] = $defense === null ? DataState::UNAVAILABLE : $defenseSource;
        return [
            'externalId' => $externalId !== '' ? $externalId : null,
            'name' => $name !== '' ? $name : DataState::UNAVAILABLE,
            'venue' => $venue,
            'played' => $played,
            'wins' => $count($row['wins'] ?? null),
            'draws' => $count($row['draws'] ?? null),
            'losses' => $count($row['losses'] ?? null),
            'goalsFor' => $goalsFor,
            'goalsAgainst' => $goalsAgainst,
            // Per-match rates, derived arithmetically from the stored counts (and
            // only when those counts exist) — the console shows these next to the
            // strength figures so a reader can see what the model saw.
            'avgGoalsScored' => ($played ?? 0) > 0 ? round(((int) $goalsFor) / (int) $played, 4) : null,
            'avgGoalsConceded' => ($played ?? 0) > 0 ? round(((int) $goalsAgainst) / (int) $played, 4) : null,
            'points' => $count($row['points'] ?? null),
            'position' => $count($row['position'] ?? null),
            'form' => [
                'last5' => self::formSummary($formLast5),
                'last10' => self::formSummary($formAll),
                'home' => $venue === 'HOME' ? self::formSummary($formVenue) : null,
                'away' => $venue === 'AWAY' ? self::formSummary($formVenue) : null,
            ],
            'attackStrength' => $attack,
            'defenseWeakness' => $defense,
            'attackSource' => $attack === null ? DataState::UNAVAILABLE : $attackSource,
            'defenseSource' => $defense === null ? DataState::UNAVAILABLE : $defenseSource,
            'expectedGoalsTendency' => ($attack === null || $defense === null) ? null : round($attack - $defense, 4),
            'cleanSheetRate' => ($cleanSheets === null || ($played ?? 0) <= 0) ? null : round($cleanSheets / $played, 4),
            'failedToScoreRate' => ($failedToScore === null || ($played ?? 0) <= 0) ? null : round($failedToScore / $played, 4),
            'recentMatchCoverage' => self::coverageValue([
                ($formAll['played'] ?? 0) >= 5,
                ($formAll['played'] ?? 0) >= 10,
                ($formVenue['played'] ?? 0) >= 3,
            ]),
            'statCoverage' => self::coverageValue([
                ($played ?? 0) > 0,
                $goalsFor !== null,
                $goalsAgainst !== null,
                $attack !== null,
                $defense !== null,
            ]),
            'dataState' => $coverage['teamStatistics:' . $venue],
        ];
    }

    private function headToHead(int $providerId, array $fixture, array &$coverage, array &$provenance): array
    {
        $homeId = (string) ($fixture['home_team_id'] ?? '');
        $awayId = (string) ($fixture['away_team_id'] ?? '');
        $empty = ['meetings' => 0, 'homeWins' => 0, 'draws' => 0, 'awayWins' => 0, 'avgHomeGoals' => null, 'avgAwayGoals' => null,
            'weight' => 0.0, 'coverage' => 0.0, 'state' => DataState::UNAVAILABLE, 'summary' => 'DATA_UNAVAILABLE', 'newestKickoff' => null, 'sampleAgeDays' => null];
        if ($homeId === '' || $awayId === '') {
            $provenance['headToHead'] = 'TEAM_IDS_UNAVAILABLE';
            $coverage['headToHead'] = DataState::UNAVAILABLE;
            return $empty;
        }
        $competitionExternal = (string) ($fixture['competition_external_id'] ?? '');
        $row = $this->repo->findHeadToHead($providerId, $homeId, $awayId, $competitionExternal !== '' ? $competitionExternal : null);
        if ($row === null) {
            $row = $this->repo->findHeadToHead($providerId, $homeId, $awayId, null);
        }
        if ($row === null) {
            $provenance['headToHead'] = 'NOT_STORED';
            $coverage['headToHead'] = DataState::UNAVAILABLE;
            return $empty;
        }
        $meetings = (int) ($row['meetings'] ?? 0);
        $weight = (float) ($row['weight'] ?? 0.0);
        $coverage['headToHead'] = (string) ($row['data_state'] ?? DataState::LIMITED);
        $provenance['headToHead'] = 'stored-snapshot:' . $meetings . '-meetings';
        $summary = $meetings === 0
            ? 'DATA_UNAVAILABLE'
            : sprintf('%d meetings: %d home wins, %d draws, %d away wins', $meetings, (int) ($row['home_wins'] ?? 0), (int) ($row['draws'] ?? 0), (int) ($row['away_wins'] ?? 0));
        return [
            'meetings' => $meetings,
            'homeWins' => (int) ($row['home_wins'] ?? 0),
            'draws' => (int) ($row['draws'] ?? 0),
            'awayWins' => (int) ($row['away_wins'] ?? 0),
            'avgHomeGoals' => self::nullableFloat($row['avg_home_goals'] ?? null),
            'avgAwayGoals' => self::nullableFloat($row['avg_away_goals'] ?? null),
            'bothTeamsScored' => self::nullableInt($row['both_teams_scored'] ?? $row['bothTeamsScored'] ?? null),
            'over15' => self::nullableInt($row['over_15'] ?? $row['over15'] ?? null),
            'over25' => self::nullableInt($row['over_25'] ?? $row['over25'] ?? null),
            'weight' => round($weight, 4),
            'coverage' => min(100.0, 100.0 * ($meetings > 0 ? min(1.0, $meetings / 8.0) : 0.0) * ($weight > 0 ? 1 : 0.5)),
            'state' => $coverage['headToHead'],
            'summary' => $summary,
            'newestKickoff' => $row['newest_kickoff'] ?? null,
            'sampleAgeDays' => self::nullableInt($row['sample_age_days'] ?? null),
        ];
    }

    private function competition(array $fixture, array &$coverage, array &$provenance): ?array
    {
        $competitionExternal = (string) ($fixture['competition_external_id'] ?? '');
        $season = (string) ($fixture['season'] ?? $fixture['competition_season'] ?? '');
        $aggregate = $this->stats->leagueAggregateFor($fixture, (string) ($fixture['provider_code'] ?? ($this->providerCode($fixture) ?? '')));
        $coverage['leagueStatistics'] = $aggregate === null ? DataState::UNAVAILABLE : DataState::AVAILABLE;
        $provenance['leagueStatistics'] = $aggregate['source'] ?? 'NOT_COLLECTED';
        // No competition record and no collected aggregate: the module reports
        // the absence rather than substituting a generic league average.
        if ($aggregate === null && ($fixture['competition'] ?? '') === DataState::UNAVAILABLE) return null;
        return [
            'externalId' => $competitionExternal !== '' ? $competitionExternal : null,
            'name' => (string) ($fixture['competition'] ?? 'DATA_UNAVAILABLE'),
            'country' => $fixture['country'] ?? $fixture['competition_country'] ?? null,
            'season' => $season !== '' ? $season : null,
            'dataState' => (string) ($fixture['competition_data_state'] ?? ($aggregate === null ? DataState::LIMITED : DataState::AVAILABLE)),
            'hasStats' => $aggregate !== null,
            'aggregate' => $aggregate,
            'teams' => $aggregate['teams'] ?? null,
        ];
    }

    private function fixtureCompleteness(array $fixture, array &$coverage): float
    {
        $map = is_array($fixture['coverage'] ?? null) ? $fixture['coverage'] : [];
        $available = 0; $total = 0;
        foreach (['externalId', 'homeTeam', 'awayTeam', 'competition', 'kickoff', 'status'] as $field) {
            $total++;
            $state = $map[$field] ?? (($fixture[$field] ?? null) !== null && $fixture[$field] !== '' ? DataState::AVAILABLE : DataState::UNAVAILABLE);
            if ($state === DataState::AVAILABLE) $available++;
        }
        $detail = $map['detail'] ?? DataState::UNAVAILABLE;
        $total++;
        if ($detail === DataState::AVAILABLE) $available += 1;
        elseif ($detail === DataState::LIMITED) $available += 0.5;
        $coverage['fixture'] = $available >= $total ? DataState::AVAILABLE : ($available >= $total * 0.6 ? DataState::LIMITED : DataState::UNAVAILABLE);
        return $total > 0 ? 100.0 * $available / $total : 0.0;
    }

    /** Data age against the per-bucket freshness window; 0 when nothing is stored. */
    private function freshness(array $fixture, array &$coverage, array &$provenance): float
    {
        $candidates = array_filter([$fixture['source_timestamp'] ?? null, $fixture['updated_at'] ?? null]);
        $newest = $candidates === [] ? null : max($candidates);
        if ($newest === null) { $coverage['freshness'] = DataState::UNAVAILABLE; $provenance['freshness'] = 'NO_TIMESTAMP'; return 0.0; }
        $bucket = (string) ($fixture['status'] ?? '') === 'LIVE' ? 'live' : ((string) ($fixture['status'] ?? '') === 'FINISHED' ? 'results' : 'fixtures');
        $window = max(60, $this->config->maxDataAgeSeconds($bucket));
        try { $age = max(0, time() - (new \DateTimeImmutable((string) $newest))->getTimestamp()); } catch (\Throwable $e) { return 0.0; }
        $coverage['freshness'] = $age <= $window ? DataState::AVAILABLE : ($age <= $window * 4 ? DataState::LIMITED : DataState::UNAVAILABLE);
        $provenance['freshness'] = $age . 's vs ' . $window . 's window';
        if ($age <= $window) return 100.0;
        return max(0.0, 100.0 * (1 - min(1.0, ($age - $window) / max(1, $window * 4))));
    }

    /**
     * Provider reliability is measured, not asserted: quota head-room, the
     * recorded failure count and the provider's own status.
     */
    private function providerReliability(?array $providerRow, array &$coverage, array &$provenance): float
    {
        if ($providerRow === null) { $coverage['provider'] = DataState::UNAVAILABLE; $provenance['provider'] = 'NOT_CONFIGURED'; return 0.0; }
        $status = strtoupper((string) ($providerRow['status'] ?? 'NOT_CONFIGURED'));
        $failures = (int) ($providerRow['consecutive_failures'] ?? (substr((string) ($providerRow['last_failure_at'] ?? ''), 0, 10) === gmdate('Y-m-d') ? 1 : 0));
        $requests = (int) ($providerRow['requests_used'] ?? 0);
        $limit = (int) ($providerRow['requests_budget'] ?? 0);
        $base = match ($status) {
            'ONLINE' => 95.0,
            'DEGRADED' => 55.0,
            'RATE_LIMITED' => 35.0,
            'OFFLINE' => 10.0,
            'AUTH' => 5.0,
            default => 0.0,
        };
        $penalty = min(40.0, $failures * 5.0);
        if ($limit > 0) {
            $used = min(1.0, $requests / max(1, $limit));
            if ($used > 0.9) $base -= 15.0;
            elseif ($used > 0.75) $base -= 5.0;
        }
        $coverage['provider'] = $status === 'ONLINE' ? DataState::AVAILABLE : ($status === 'NOT_CONFIGURED' ? DataState::UNAVAILABLE : DataState::LIMITED);
        $provenance['provider'] = $status . ' · ' . $requests . '/' . ($limit > 0 ? $limit : '?') . ' requests · ' . $failures . ' failures';
        return max(0.0, min(100.0, $base - $penalty));
    }

    private function providerOf(array $fixture): ?array
    {
        $providerId = (int) ($fixture['provider_id'] ?? 0);
        if ($providerId <= 0) return null;
        foreach ($this->repo->listProviders() as $row) {
            if ((int) $row['id'] === $providerId) return $row;
        }
        return null;
    }

    private function providerCode(array $fixture): ?string
    {
        $row = $this->providerOf($fixture);
        return $row === null ? null : (string) ($row['provider_code'] ?? '');
    }

    private static function formSummary(?array $form): ?array
    {
        if ($form === null || (int) ($form['played'] ?? 0) === 0) return null;
        return ['string' => (string) $form['string'], 'played' => (int) $form['played'], 'wins' => (int) $form['wins'],
            'draws' => (int) $form['draws'], 'losses' => (int) $form['losses'], 'points' => (int) $form['points'],
            'goalsFor' => (int) $form['goalsFor'], 'goalsAgainst' => (int) $form['goalsAgainst'],
            'streak' => (int) $form['streak'], 'matches' => array_slice($form['matches'], 0, 5)];
    }

    /** Percentage of a list of measured data requirements that actually arrived. */
    private static function coverageValue(array $flags): float
    {
        $flags = array_values($flags);
        if ($flags === []) return 0.0;
        $kept = count(array_filter($flags, static fn($flag) => (bool) $flag));
        return round(100.0 * $kept / count($flags), 1);
    }

    private static function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
