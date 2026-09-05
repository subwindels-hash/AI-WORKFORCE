<?php
namespace AIWorkforce\Persistence;

/**
 * FootballRepository over CodeIgniter 3's query builder (MySQL in production,
 * pdo_sqlite for the offline runtime).
 *
 * Conventions that keep the no-fabrication promise structural:
 *  - Numeric provider fields are written as int|float|null. A provider that did
 *    not answer a field yields NULL — it is never coerced to 0, and the
 *    `data_state`/`coverage` columns record which fields were missing.
 *  - Every `save*()` is an upsert keyed by the provider's own identity, so a
 *    repeated sync refreshes facts instead of duplicating them.
 *  - `saveSettlement()` is insert-only: the row that records how a prediction
 *    turned out can be created once and never edited.
 */
class FootballRepositoryDatabase implements FootballRepository
{
    /** JSON-encoded columns decoded back into arrays on read. */
    private const JSON_COLUMNS = [
        'capabilities', 'coverage', 'payload', 'quality_components', 'feature_snapshot',
        'probabilities_matrix', 'alternative_scores', 'evidence', 'outcome', 'rejection_reasons',
        'parameters', 'lifecycle_history', 'reliability_bins', 'matches', 'last_matches', 'errors',
    ];

    public function __construct(private object $db) {}

    // ── providers ───────────────────────────────────────────────────────────

    public function ensureProvider(string $code, array $attributes = []): array
    {
        $row = $this->db->get_where('football_providers', ['provider_code' => $code], 1)->row_array();
        $now = gmdate('c');
        if (!$row) {
            $insert = [
                'provider_code' => $code,
                'display_name' => (string) ($attributes['displayName'] ?? $code),
                'status' => (string) ($attributes['status'] ?? 'NOT_CONFIGURED'),
                'capabilities' => json_encode($attributes['capabilities'] ?? []),
                'requests_budget' => isset($attributes['requestsBudget']) ? (int) $attributes['requestsBudget'] : null,
                'rate_limit_per_minute' => isset($attributes['rateLimitPerMinute']) ? (int) $attributes['rateLimitPerMinute'] : null,
                'demo_mode' => !empty($attributes['demoMode']) ? 1 : 0,
                'enabled' => !empty($attributes['enabled']) ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $this->db->insert('football_providers', $insert);
            $row = $this->db->get_where('football_providers', ['provider_code' => $code], 1)->row_array()
                ?: array_merge($insert, ['id' => (int) $this->db->insert_id()]);
        }
        return $this->decode($row);
    }

    public function updateProvider(int $id, array $patch): void
    {
        if (!$patch) return;
        $data = [];
        foreach ($patch as $key => $value) {
            $column = match ($key) {
                'displayName' => 'display_name',
                'requestsUsed' => 'requests_used',
                'requestsBudget' => 'requests_budget',
                'requestsUsedDate' => 'requests_used_date',
                'rateLimitPerMinute' => 'rate_limit_per_minute',
                'backoffUntil' => 'backoff_until',
                'lastSuccessAt' => 'last_success_at',
                'lastFailureAt' => 'last_failure_at',
                'lastError' => 'last_error',
                'demoMode' => 'demo_mode',
                'default' => $key,
            };
            $data[$column] = match (true) {
                $key === 'capabilities' => json_encode((array) $value),
                $key === 'demoMode' => !empty($value) ? 1 : 0,
                default => $value,
            };
        }
        if ($data) {
            $data['updated_at'] = gmdate('c');
            $this->db->where('id', $id)->update('football_providers', $data);
        }
    }

    public function listProviders(bool $enabledOnly = false): array
    {
        if ($enabledOnly) $this->db->where('enabled', 1);
        $rows = $this->db->order_by('id', 'ASC')->get('football_providers')->result_array();
        return array_map(fn(array $r) => $this->decode($r), $rows);
    }

    // ── competitions / teams ────────────────────────────────────────────────

    public function saveCompetition(int $providerId, array $row): array
    {
        $externalId = (string) ($row['externalId'] ?? $row['external_id'] ?? '');
        if ($externalId === '') throw new \InvalidArgumentException('competition requires externalId');
        $season = isset($row['season']) && (string) $row['season'] !== '' ? (string) $row['season'] : null;
        $data = [
            'name' => (string) ($row['name'] ?? 'DATA_UNAVAILABLE'),
            'country' => self::nullableString($row['country'] ?? null),
            'code' => self::nullableString($row['code'] ?? null),
            'season' => $season,
            'tier' => self::nullableInt($row['tier'] ?? null),
            'coefficient' => self::nullableFloat($row['coefficient'] ?? null),
            'reliability' => self::nullableFloat($row['reliability'] ?? null),
            'data_state' => (string) ($row['dataState'] ?? 'DATA_UNAVAILABLE'),
            'payload' => json_encode($row['payload'] ?? []),
            'fetched_at' => (string) ($row['fetchedAt'] ?? gmdate('c')),
            'updated_at' => gmdate('c'),
        ];
        $existing = $this->db->where(['provider_id' => $providerId, 'external_id' => $externalId, 'season' => $season])
            ->get('football_competitions', 1)->row_array();
        if ($existing) {
            $this->db->where('id', (int) $existing['id'])->update('football_competitions', $data);
            return $this->decode(array_merge($existing, $data));
        }
        $this->db->insert('football_competitions', array_merge(['provider_id' => $providerId, 'external_id' => $externalId, 'created_at' => gmdate('c')], $data));
        return $this->decode(array_merge($data, ['id' => (int) $this->db->insert_id(), 'provider_id' => $providerId, 'external_id' => $externalId]));
    }

    public function findCompetition(int $providerId, string $externalId, ?string $season = null): ?array
    {
        $this->db->where(['provider_id' => $providerId, 'external_id' => $externalId]);
        if ($season !== null) $this->db->where('season', $season);
        $row = $this->db->order_by('updated_at', 'DESC')->get('football_competitions', 1)->row_array();
        return $row ? $this->decode($row) : null;
    }

    public function saveTeam(int $providerId, array $row): array
    {
        $externalId = (string) ($row['externalId'] ?? $row['external_id'] ?? '');
        if ($externalId === '') throw new \InvalidArgumentException('team requires externalId');
        $data = [
            'name' => (string) ($row['name'] ?? 'DATA_UNAVAILABLE'),
            'short_code' => self::nullableString($row['shortCode'] ?? null),
            'logo' => self::nullableString($row['logo'] ?? null),
            'venue' => self::nullableString($row['venue'] ?? null),
            'country' => self::nullableString($row['country'] ?? null),
            'data_state' => (string) ($row['dataState'] ?? 'DATA_UNAVAILABLE'),
            'payload' => json_encode($row['payload'] ?? []),
            'fetched_at' => (string) ($row['fetchedAt'] ?? gmdate('c')),
            'updated_at' => gmdate('c'),
        ];
        $existing = $this->db->get_where('football_teams', ['provider_id' => $providerId, 'external_id' => $externalId], 1)->row_array();
        if ($existing) {
            $this->db->where('id', (int) $existing['id'])->update('football_teams', $data);
            return $this->decode(array_merge($existing, $data));
        }
        $this->db->insert('football_teams', array_merge(['provider_id' => $providerId, 'external_id' => $externalId, 'created_at' => gmdate('c')], $data));
        return $this->decode(array_merge($data, ['id' => (int) $this->db->insert_id(), 'provider_id' => $providerId, 'external_id' => $externalId]));
    }

    public function findTeam(int $providerId, string $externalId): ?array
    {
        $row = $this->db->get_where('football_teams', ['provider_id' => $providerId, 'external_id' => $externalId], 1)->row_array();
        return $row ? $this->decode($row) : null;
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    public function saveFixture(int $providerId, array $fixture): array
    {
        $externalId = (string) ($fixture['externalId'] ?? $fixture['external_id'] ?? '');
        if ($externalId === '') throw new \InvalidArgumentException('fixture requires externalId');
        foreach (['homeTeam', 'awayTeam'] as $required) {
            if (trim((string) ($fixture[$required] ?? '')) === '') throw new \InvalidArgumentException("fixture requires {$required}");
        }
        $now = gmdate('c');
        $data = [
            'competition_id' => self::nullableInt($fixture['competitionId'] ?? null),
            'competition' => (string) (($fixture['competition'] ?? '') !== '' ? $fixture['competition'] : 'DATA_UNAVAILABLE'),
            'country' => self::nullableString($fixture['country'] ?? null),
            'season' => self::nullableString($fixture['season'] ?? null),
            'round' => self::nullableString($fixture['round'] ?? null),
            'kickoff_at' => self::iso((string) ($fixture['kickoff'] ?? '')) ?: $now,
            'status' => strtoupper((string) ($fixture['status'] ?? 'SCHEDULED')),
            'match_state' => strtoupper((string) ($fixture['matchState'] ?? 'PRE_MATCH')),
            // Scores/minute stay NULL unless the provider actually reported them.
            'minute' => self::nullableInt($fixture['minute'] ?? null),
            'extra_minute' => self::nullableInt($fixture['extraMinute'] ?? null),
            'home_team' => (string) $fixture['homeTeam'],
            'away_team' => (string) $fixture['awayTeam'],
            'home_team_id' => self::nullableString($fixture['homeTeamId'] ?? null),
            'away_team_id' => self::nullableString($fixture['awayTeamId'] ?? null),
            'home_score' => self::nullableInt($fixture['homeScore'] ?? null),
            'away_score' => self::nullableInt($fixture['awayScore'] ?? null),
            'half_time_home' => self::nullableInt($fixture['halfTimeHome'] ?? null),
            'half_time_away' => self::nullableInt($fixture['halfTimeAway'] ?? null),
            'home_red_cards' => self::nullableInt($fixture['homeRedCards'] ?? null),
            'away_red_cards' => self::nullableInt($fixture['awayRedCards'] ?? null),
            'venue' => self::nullableString($fixture['venue'] ?? null),
            'data_state' => (string) ($fixture['dataState'] ?? 'DATA_UNAVAILABLE'),
            'coverage' => json_encode($fixture['coverage'] ?? []),
            'payload' => json_encode($fixture['payload'] ?? $fixture),
            'source_timestamp' => self::iso((string) ($fixture['sourceTimestamp'] ?? '')) ?: $now,
            'updated_at' => $now,
        ];
        $existing = $this->db->get_where('football_fixtures', ['provider_id' => $providerId, 'external_id' => $externalId], 1)->row_array();
        if ($existing) {
            // A finished match keeps its final score: a later provider response
            // that omits the score must not blank it out.
            if ($data['home_score'] === null && $existing['home_score'] !== null) {
                unset($data['home_score'], $data['away_score']);
            }
            if (in_array((string) ($existing['status'] ?? ''), ['FINISHED', 'CANCELLED', 'POSTPONED'], true)
                && !in_array($data['status'], ['FINISHED', 'CANCELLED', 'POSTPONED'], true)) {
                unset($data['status']);
            }
            $this->db->where('id', (int) $existing['id'])->update('football_fixtures', $data);
            return $this->decode(array_merge($existing, $data));
        }
        $this->db->insert('football_fixtures', array_merge([
            'provider_id' => $providerId, 'external_id' => $externalId,
            'created_at' => $now,
        ], $data));
        return $this->decode(array_merge($data, [
            'id' => (int) $this->db->insert_id(),
            'provider_id' => $providerId,
            'external_id' => $externalId,
            'created_at' => $now,
        ]));
    }

    public function findFixtureById(int $id): ?array
    {
        $row = $this->db->get_where('football_fixtures', ['id' => $id], 1)->row_array();
        $rows = $row ? $this->withCompetitionRef([$this->decode($row)]) : [];
        return $rows[0] ?? null;
    }

    public function findFixture(int $providerId, string $externalId): ?array
    {
        $row = $this->db->get_where('football_fixtures', ['provider_id' => $providerId, 'external_id' => $externalId], 1)->row_array();
        $rows = $row ? $this->withCompetitionRef([$this->decode($row)]) : [];
        return $rows[0] ?? null;
    }

    public function listFixtures(array $filter = [], int $limit = 500): array
    {
        if (!empty($filter['providerId'])) $this->db->where('provider_id', (int) $filter['providerId']);
        if (!empty($filter['status'])) $this->db->where('status', strtoupper((string) $filter['status']));
        if (!empty($filter['matchState'])) $this->db->where('match_state', strtoupper((string) $filter['matchState']));
        if (!empty($filter['date'])) {
            $date = (string) $filter['date'];
            $this->db->where('kickoff_at >=', $date . 'T00:00:00+00:00');
            $this->db->where('kickoff_at <=', $date . 'T23:59:59+00:00');
        }
        if (!empty($filter['from'])) $this->db->where('kickoff_at >=', (string) $filter['from']);
        if (!empty($filter['to'])) $this->db->where('kickoff_at <=', (string) $filter['to']);
        if (!empty($filter['competition'])) $this->db->like('competition', (string) $filter['competition'], 'after');
        if (!empty($filter['team'])) {
            $team = (string) $filter['team'];
            $this->db->group_start()->like('home_team', $team)->or_like('away_team', $team)->group_end();
        }
        if (!empty($filter['settledOnly'])) $this->db->where('settled_at IS NOT NULL');
        if (!empty($filter['unsettledFinished'])) {
            $this->db->where('status', 'FINISHED');
            $this->db->where('settled_at', null);
        }
        $rows = $this->db->order_by('kickoff_at', 'ASC')->limit(min(2000, max(1, $limit)))->get('football_fixtures')->result_array();
        return $this->withCompetitionRef(array_map(fn(array $r) => $this->decode($r), $rows));
    }

    public function markFixtureSettled(int $id, string $at): void
    {
        $this->db->where('id', $id)->update('football_fixtures', ['settled_at' => $at, 'updated_at' => gmdate('c')]);
    }

    public function linkFixtureCompetition(int $fixtureId, int $competitionId): void
    {
        $this->db->where('id', $fixtureId)->update('football_fixtures', ['competition_id' => $competitionId, 'updated_at' => gmdate('c')]);
    }

    public function listFixturesAwaitingResult(int $limit = 200, ?int $providerId = null): array
    {
        $this->db->group_start();
        $this->db->where('status', 'LIVE')
            ->or_group_start()->where('status', 'SCHEDULED')->where('kickoff_at <=', gmdate('c'))->group_end()
            ->or_group_start()->where('status', 'FINISHED')->where('home_score', null)->group_end()
            ->or_group_start()->where('status', 'FINISHED')->where('settled_at', null)->group_end();
        $this->db->group_end();
        if ($providerId !== null) $this->db->where('provider_id', $providerId);
        $rows = $this->db->order_by('kickoff_at', 'DESC')->limit(min(500, max(1, $limit)))->get('football_fixtures')->result_array();
        return $this->withCompetitionRef(array_map(fn(array $r) => $this->decode($r), $rows));
    }

    // ── statistics ──────────────────────────────────────────────────────────

    public function saveTeamStatistics(int $providerId, array $row): array
    {
        $teamId = (string) ($row['teamExternalId'] ?? '');
        if ($teamId === '') throw new \InvalidArgumentException('team statistics require teamExternalId');
        $competitionId = self::nullableString($row['competitionExternalId'] ?? null);
        $season = self::nullableString($row['season'] ?? null);
        $data = [];
        foreach ([
            'played', 'wins', 'draws', 'losses', 'goals_for', 'goals_against', 'points', 'position',
            'home_played', 'home_wins', 'home_draws', 'home_losses', 'home_goals_for', 'home_goals_against',
            'away_played', 'away_wins', 'away_draws', 'away_losses', 'away_goals_for', 'away_goals_against',
            'clean_sheets', 'failed_to_score',
        ] as $column) {
            $key = self::camel($column);
            $data[$column] = array_key_exists($key, $row) ? self::nullableInt($row[$key]) : null;
        }
        $data['team'] = (string) ($row['team'] ?? 'DATA_UNAVAILABLE');
        $data['form_last5'] = self::nullableString($row['formLast5'] ?? null);
        $data['form_last10'] = self::nullableString($row['formLast10'] ?? null);
        $data['last_matches'] = json_encode($row['lastMatches'] ?? []);
        $data['data_state'] = (string) ($row['dataState'] ?? 'DATA_UNAVAILABLE');
        $data['coverage'] = json_encode($row['coverage'] ?? []);
        $data['payload'] = json_encode($row['payload'] ?? $row);
        $data['fetched_at'] = (string) ($row['fetchedAt'] ?? gmdate('c'));
        $data['updated_at'] = gmdate('c');
        $existing = $this->db->where(['provider_id' => $providerId, 'team_external_id' => $teamId])
            ->where('competition_external_id', $competitionId)->where('season', $season)
            ->get('football_team_statistics', 1)->row_array();
        if ($existing) {
            $this->db->where('id', (int) $existing['id'])->update('football_team_statistics', $data);
            return $this->decode(array_merge($existing, $data));
        }
        $this->db->insert('football_team_statistics', array_merge([
            'provider_id' => $providerId, 'team_external_id' => $teamId,
            'competition_external_id' => $competitionId, 'season' => $season, 'created_at' => gmdate('c'),
        ], $data));
        return $this->decode(array_merge($data, [
            'id' => (int) $this->db->insert_id(), 'provider_id' => $providerId,
            'team_external_id' => $teamId, 'competition_external_id' => $competitionId, 'season' => $season,
        ]));
    }

    public function findTeamStatistics(int $providerId, string $teamExternalId, ?string $competitionExternalId = null, ?string $season = null): ?array
    {
        $this->db->where(['provider_id' => $providerId, 'team_external_id' => $teamExternalId]);
        // null means "not recorded by the provider" — match the NULL column so a
        // generic lookup cannot silently pick a different competition's row.
        $this->db->where('competition_external_id', $competitionExternalId);
        if ($season !== null) $this->db->where('season', $season);
        $row = $this->db->order_by('fetched_at', 'DESC')->get('football_team_statistics', 1)->row_array();
        return $row ? $this->decode($row) : null;
    }

    public function listTeamRecentResults(int $providerId, string $teamExternalId, int $limit = 10): array
    {
        if ($teamExternalId === '') return [];
        $rows = $this->db->where('provider_id', $providerId)
            ->where('status', 'FINISHED')
            ->where('home_score IS NOT NULL')
            ->group_start()->where('home_team_id', $teamExternalId)->or_where('away_team_id', $teamExternalId)->group_end()
            ->order_by('kickoff_at', 'DESC')
            ->limit(min(50, max(1, $limit)))
            ->get('football_fixtures')->result_array();
        return array_map(fn(array $r) => $this->decode($r), $rows);
    }

    public function saveFixtureStatistics(int $fixtureId, int $providerId, string $kind, array $payload, array $coverage = []): array
    {
        $data = [
            'payload' => json_encode($payload),
            'data_state' => $coverage === [] ? 'DATA_UNAVAILABLE' : (isset($coverage['state']) ? (string) $coverage['state'] : 'LIMITED_DATA'),
            'coverage' => json_encode($coverage),
            'fetched_at' => gmdate('c'),
        ];
        $existing = $this->db->get_where('football_fixture_statistics', ['fixture_id' => $fixtureId, 'provider_id' => $providerId, 'kind' => $kind], 1)->row_array();
        if ($existing) {
            $this->db->where('id', (int) $existing['id'])->update('football_fixture_statistics', $data);
            return $this->decode(array_merge($existing, $data));
        }
        $this->db->insert('football_fixture_statistics', array_merge([
            'fixture_id' => $fixtureId, 'provider_id' => $providerId, 'kind' => $kind, 'created_at' => gmdate('c'),
        ], $data));
        return $this->decode(array_merge($data, ['id' => (int) $this->db->insert_id(), 'fixture_id' => $fixtureId, 'kind' => $kind]));
    }

    public function findFixtureStatistics(int $fixtureId, ?string $kind = null): ?array
    {
        $this->db->where('fixture_id', $fixtureId);
        if ($kind !== null) $this->db->where('kind', $kind);
        $row = $this->db->order_by('fetched_at', 'DESC')->get('football_fixture_statistics', 1)->row_array();
        return $row ? $this->decode($row) : null;
    }

    public function saveHeadToHead(int $providerId, array $row): array
    {
        $homeId = (string) ($row['homeTeamExternalId'] ?? '');
        $awayId = (string) ($row['awayTeamExternalId'] ?? '');
        if ($homeId === '' || $awayId === '') throw new \InvalidArgumentException('head-to-head requires both team ids');
        $competitionId = self::nullableString($row['competitionExternalId'] ?? null);
        $data = [
            'meetings' => (int) ($row['meetings'] ?? 0),
            'home_wins' => (int) ($row['homeWins'] ?? 0),
            'draws' => (int) ($row['draws'] ?? 0),
            'away_wins' => (int) ($row['awayWins'] ?? 0),
            'avg_home_goals' => self::nullableFloat($row['avgHomeGoals'] ?? null),
            'avg_away_goals' => self::nullableFloat($row['avgAwayGoals'] ?? null),
            'both_teams_scored' => self::nullableInt($row['bothTeamsScored'] ?? null),
            'over_15' => self::nullableInt($row['over15'] ?? null),
            'over_25' => self::nullableInt($row['over25'] ?? null),
            'oldest_kickoff' => self::nullableString($row['oldestKickoff'] ?? null),
            'newest_kickoff' => self::nullableString($row['newestKickoff'] ?? null),
            'sample_age_days' => self::nullableInt($row['sampleAgeDays'] ?? null),
            'weight' => round((float) ($row['weight'] ?? 0), 4),
            'data_state' => (string) ($row['dataState'] ?? 'DATA_UNAVAILABLE'),
            'matches' => json_encode($row['matches'] ?? []),
            'fetched_at' => (string) ($row['fetchedAt'] ?? gmdate('c')),
            'updated_at' => gmdate('c'),
        ];
        $existing = $this->db->where(['provider_id' => $providerId, 'home_team_external_id' => $homeId, 'away_team_external_id' => $awayId])
            ->where('competition_external_id', $competitionId)->get('football_head_to_head', 1)->row_array();
        if ($existing) {
            $this->db->where('id', (int) $existing['id'])->update('football_head_to_head', $data);
            return $this->decode(array_merge($existing, $data));
        }
        $this->db->insert('football_head_to_head', array_merge([
            'provider_id' => $providerId, 'home_team_external_id' => $homeId, 'away_team_external_id' => $awayId,
            'competition_external_id' => $competitionId, 'created_at' => gmdate('c'),
        ], $data));
        return $this->decode(array_merge($data, ['id' => (int) $this->db->insert_id()]));
    }

    public function findHeadToHead(int $providerId, string $homeTeamExternalId, string $awayTeamExternalId, ?string $competitionExternalId = null): ?array
    {
        if ($homeTeamExternalId === '' || $awayTeamExternalId === '') return null;
        $this->db->where(['provider_id' => $providerId, 'home_team_external_id' => $homeTeamExternalId, 'away_team_external_id' => $awayTeamExternalId]);
        $this->db->where('competition_external_id', $competitionExternalId);
        $row = $this->db->order_by('fetched_at', 'DESC')->get('football_head_to_head', 1)->row_array();
        return $row ? $this->decode($row) : null;
    }

    // ── model + calibration registry ────────────────────────────────────────

    public function saveModelVersion(array $row): array
    {
        $name = (string) ($row['model_name'] ?? $row['modelName'] ?? '');
        $version = (string) ($row['model_version'] ?? $row['modelVersion'] ?? '');
        if ($name === '' || $version === '') throw new \InvalidArgumentException('model version requires model_name and model_version');
        $data = self::only($row, [
            'model_id', 'model_name', 'model_version', 'algorithm', 'feature_version', 'training_dataset_version',
            'status', 'trained_at', 'validated_at', 'calibrated_at', 'approved_at', 'approved_by', 'activated_at',
            'activated_by', 'retired_at', 'last_evaluated_at', 'calibration_version_id', 'validation_sample_size',
            'training_sample_size', 'accuracy', 'log_loss', 'brier_score', 'ece', 'parameters', 'lifecycle_history',
            'rejection_reason',
        ]);
        $data['model_id'] = (string) ($data['model_id'] ?? ('football-' . substr(hash('sha256', $name . '@' . $version), 0, 10)));
        // Fail closed: a model version never enters the registry already approved.
        $data['status'] = (string) ($data['status'] ?? 'DRAFT');
        $data['updated_at'] = gmdate('c');
        $existing = $this->findModelVersionByName($name, $version);
        if ($existing !== null) {
            $this->db->where('id', (int) $existing['id'])->update('football_model_versions', $data);
            return $this->decode(array_merge($existing, $data));
        }
        $this->db->insert('football_model_versions', array_merge($data, ['created_at' => gmdate('c')]));
        $id = (int) $this->db->insert_id();
        if ($id === 0) $existing = $this->findModelVersionByName($name, $version);
        return $this->decode(array_merge($data, ['id' => $id !== 0 ? $id : ($existing['id'] ?? 0)]));
    }

    public function findModelVersion(int $id): ?array
    {
        $row = $this->db->get_where('football_model_versions', ['id' => $id], 1)->row_array();
        return $row ? $this->decode($row) : null;
    }

    public function findModelVersionByName(string $modelName, string $modelVersion): ?array
    {
        $row = $this->db->get_where('football_model_versions', ['model_name' => $modelName, 'model_version' => $modelVersion], 1)->row_array();
        return $row ? $this->decode($row) : null;
    }

    public function listModelVersions(?string $status = null, int $limit = 50): array
    {
        if ($status !== null) $this->db->where('status', $status);
        $rows = $this->db->order_by('id', 'DESC')->limit(min(200, max(1, $limit)))->get('football_model_versions')->result_array();
        return array_map(fn(array $r) => $this->decode($r), $rows);
    }

    public function updateModelVersion(int $id, array $patch): void
    {
        $data = self::only($patch, [
            'model_id', 'algorithm', 'feature_version', 'training_dataset_version', 'status', 'trained_at', 'validated_at',
            'calibrated_at', 'approved_at', 'approved_by', 'activated_at', 'activated_by', 'retired_at',
            'last_evaluated_at', 'calibration_version_id', 'validation_sample_size', 'training_sample_size',
            'accuracy', 'log_loss', 'brier_score', 'ece', 'parameters', 'lifecycle_history', 'rejection_reason',
        ]);
        if (!$data) return;
        $data['updated_at'] = gmdate('c');
        $this->db->where('id', $id)->update('football_model_versions', $data);
    }

    public function saveCalibration(array $row): array
    {
        $data = self::only($row, [
            'model_version_id', 'calibration_version', 'method', 'parameters', 'sample_size', 'accuracy', 'log_loss',
            'brier', 'ece', 'mce', 'reliability_bins', 'training_window_start', 'training_window_end', 'status',
            'created_by', 'approved_by', 'approved_at', 'rejected_by', 'rejected_at', 'reason',
        ]);
        if (empty($data['model_version_id'])) throw new \InvalidArgumentException('calibration requires model_version_id');
        $data['status'] = (string) ($data['status'] ?? 'PENDING');
        $now = gmdate('c');
        $data['updated_at'] = $now;
        $existing = $this->db->get_where('football_calibration_versions', [
            'model_version_id' => (int) $data['model_version_id'],
            'calibration_version' => (string) ($data['calibration_version'] ?? ''),
        ], 1)->row_array();
        if ($existing) return $this->decode($existing);   // deterministic version → stored once
        $this->db->insert('football_calibration_versions', array_merge($data, ['created_at' => $now]));
        return $this->decode(array_merge($data, ['id' => (int) $this->db->insert_id(), 'created_at' => $now]));
    }

    public function findCalibration(int $id): ?array
    {
        $row = $this->db->get_where('football_calibration_versions', ['id' => $id], 1)->row_array();
        return $row ? $this->decode($row) : null;
    }

    public function listCalibrations(?int $modelVersionId = null, ?string $status = null, int $limit = 50): array
    {
        if ($modelVersionId !== null) $this->db->where('model_version_id', $modelVersionId);
        if ($status !== null) $this->db->where('status', $status);
        $rows = $this->db->order_by('created_at', 'DESC')->limit(min(200, max(1, $limit)))->get('football_calibration_versions')->result_array();
        return array_map(fn(array $r) => $this->decode($r), $rows);
    }

    public function updateCalibration(int $id, array $patch): void
    {
        $data = self::only($patch, ['status', 'approved_by', 'approved_at', 'rejected_by', 'rejected_at', 'reason', 'parameters', 'sample_size', 'accuracy', 'log_loss', 'brier', 'ece', 'mce', 'reliability_bins']);
        if (!$data) return;
        $data['updated_at'] = gmdate('c');
        $this->db->where('id', $id)->update('football_calibration_versions', $data);
    }

    // ── predictions ─────────────────────────────────────────────────────────

    public function savePrediction(array $row): array
    {
        $id = (string) ($row['id'] ?? '');
        if ($id === '') throw new \InvalidArgumentException('prediction requires an id');
        $data = self::only($row, [
            'fixture_id', 'provider_id', 'model_version_id', 'calibration_version_id', 'calibration_state',
            'prediction_kind', 'supersedes_prediction_id', 'generated_at', 'kickoff_at', 'status_at_prediction',
            'predicted_result', 'predicted_home_score', 'predicted_away_score', 'probability_home', 'probability_draw',
            'probability_away', 'raw_home', 'raw_draw', 'raw_away', 'expected_total_goals', 'confidence',
            'confidence_basis', 'data_quality_score', 'data_quality_band', 'quality_components', 'feature_snapshot',
            'probabilities_matrix', 'alternative_scores', 'reason', 'evidence', 'outcome', 'eligibility',
            'rejection_reasons', 'settlement_state',
        ]);
        $now = gmdate('c');
        $data['updated_at'] = $now;
        $existing = $this->db->get_where('football_match_predictions', ['id' => $id], 1)->row_array();
        if ($existing) {
            // An evaluated prediction is immutable: once settled, the row that
            // produced the scorecard is frozen for reproducibility.
            if (in_array((string) ($existing['settlement_state'] ?? 'OPEN'), ['SETTLED', 'VOID'], true)) {
                return $this->decode($existing);
            }
            // Re-predicting the same fixture+kind replaces the stored forecast
            // only while the match has not kicked off (enforced upstream).
            $this->db->where('id', $id)->update('football_match_predictions', $data);
            return $this->decode(array_merge($existing, $data));
        }
        $this->db->insert('football_match_predictions', array_merge([
            'id' => $id, 'created_at' => $now,
        ], array_map(static fn($v) => $v, $data)));
        return $this->decode(array_merge($data, ['id' => $id, 'created_at' => $now]));
    }

    public function findPrediction(string $id): ?array
    {
        $row = $this->db->get_where('football_match_predictions', ['id' => $id], 1)->row_array();
        return $row ? $this->decode($row) : null;
    }

    public function listPredictions(array $filter = [], int $limit = 500): array
    {
        if (!empty($filter['fixtureId'])) $this->db->where('fixture_id', (int) $filter['fixtureId']);
        if (!empty($filter['kind'])) $this->db->where('prediction_kind', (string) $filter['kind']);
        if (!empty($filter['eligibility'])) $this->db->where('eligibility', (string) $filter['eligibility']);
        if (!empty($filter['modelVersionId'])) $this->db->where('model_version_id', (int) $filter['modelVersionId']);
        if (!empty($filter['settlementState'])) $this->db->where('settlement_state', (string) $filter['settlementState']);
        if (!empty($filter['date'])) {
            $date = (string) $filter['date'];
            $this->db->where('kickoff_at >=', $date . 'T00:00:00+00:00');
            $this->db->where('kickoff_at <=', $date . 'T23:59:59+00:00');
        }
        if (!empty($filter['from'])) $this->db->where('generated_at >=', (string) $filter['from']);
        if (!empty($filter['to'])) $this->db->where('generated_at <=', (string) $filter['to']);
        $rows = $this->db->order_by('generated_at', 'DESC')->limit(min(2000, max(1, $limit)))->get('football_match_predictions')->result_array();
        return array_map(fn(array $r) => $this->decode($r), $rows);
    }

    public function saveScoreProbabilities(string $predictionId, array $rows): void
    {
        $this->db->where('prediction_id', $predictionId)->delete('football_score_probabilities');
        $now = gmdate('c');
        foreach ($rows as $row) {
            $home = self::nullableInt($row['home'] ?? $row['home_goals'] ?? null);
            $away = self::nullableInt($row['away'] ?? $row['away_goals'] ?? null);
            if ($home === null || $away === null) continue;
            $this->db->insert('football_score_probabilities', [
                'prediction_id' => $predictionId,
                'home_goals' => $home,
                'away_goals' => $away,
                'probability' => round((float) ($row['probability'] ?? 0), 6),
                'rank' => (int) ($row['rank'] ?? 0),
                'is_prediction' => !empty($row['isPrediction']) ? 1 : 0,
                'created_at' => $now,
            ]);
        }
    }

    public function listScoreProbabilities(string $predictionId, int $limit = 20): array
    {
        $rows = $this->db->where('prediction_id', $predictionId)->order_by('rank', 'ASC')
            ->limit(min(100, max(1, $limit)))->get('football_score_probabilities')->result_array();
        foreach ($rows as &$row) {
            $row['probability'] = $row['probability'] !== null ? (float) $row['probability'] : null;
        }
        return $rows;
    }

    // ── settlements + performance ─────────────────────────────────────────────

    public function saveSettlement(array $row): array
    {
        $predictionId = (string) ($row['prediction_id'] ?? '');
        if ($predictionId === '') throw new \InvalidArgumentException('settlement requires prediction_id');
        $existing = $this->db->get_where('football_prediction_settlements', ['prediction_id' => $predictionId], 1)->row_array();
        if ($existing) return ['row' => $this->decode($existing), 'created' => false];
        $data = self::only($row, [
            'prediction_id', 'fixture_id', 'actual_home_score', 'actual_away_score', 'actual_result', 'predicted_result',
            'predicted_home_score', 'predicted_away_score', 'probability_home', 'probability_draw', 'probability_away',
            'confidence', 'data_quality_score', 'model_version_id', 'calibration_version_id', 'correct_result',
            'correct_exact_score', 'brier', 'log_loss', 'absolute_goal_error', 'result_source', 'settled_at',
        ]);
        $data['created_at'] = gmdate('c');
        $this->db->insert('football_prediction_settlements', $data);
        return ['row' => $this->decode(array_merge($data, ['id' => (int) $this->db->insert_id()])), 'created' => true];
    }

    public function findSettlement(string $predictionId): ?array
    {
        $row = $this->db->get_where('football_prediction_settlements', ['prediction_id' => $predictionId], 1)->row_array();
        return $row ? $this->decode($row) : null;
    }

    public function listSettlements(array $filter = [], int $limit = 2000): array
    {
        if (!empty($filter['modelVersionId'])) $this->db->where('model_version_id', (int) $filter['modelVersionId']);
        if (!empty($filter['fixtureId'])) $this->db->where('fixture_id', (int) $filter['fixtureId']);
        if (!empty($filter['from'])) $this->db->where('settled_at >=', (string) $filter['from']);
        if (!empty($filter['to'])) $this->db->where('settled_at <=', (string) $filter['to']);
        $rows = $this->db->order_by('settled_at', 'DESC')->limit(min(5000, max(1, $limit)))->get('football_prediction_settlements')->result_array();
        return array_map(fn(array $r) => $this->decode($r), $rows);
    }

    /**
     * Aggregates computed by the database engine over the settlement table —
     * the dashboard reads these numbers instead of counting anything itself.
     */
    public function settlementAggregates(array $filter = []): array
    {
        $select = 'COUNT(*) AS evaluated, '
            . 'COALESCE(SUM(CASE WHEN correct_result = 1 THEN 1 ELSE 0 END), 0) AS correct_results, '
            . 'COALESCE(SUM(CASE WHEN correct_exact_score = 1 THEN 1 ELSE 0 END), 0) AS correct_scores, '
            . 'AVG(confidence) AS avg_confidence, AVG(data_quality_score) AS avg_data_quality, '
            . 'SUM(brier) AS sum_brier, SUM(log_loss) AS sum_log_loss, '
            . 'SUM(CASE WHEN brier IS NULL THEN 1 ELSE 0 END) AS brier_missing, '
            . 'SUM(CASE WHEN log_loss IS NULL THEN 1 ELSE 0 END) AS log_loss_missing';
        $this->db->select($select, false);
        if (!empty($filter['modelVersionId'])) $this->db->where('model_version_id', (int) $filter['modelVersionId']);
        if (!empty($filter['from'])) $this->db->where('settled_at >=', (string) $filter['from']);
        if (!empty($filter['to'])) $this->db->where('settled_at <=', (string) $filter['to']);
        $row = $this->db->get('football_prediction_settlements')->row_array() ?: [];
        $evaluated = (int) ($row['evaluated'] ?? 0);
        return [
            'evaluated' => $evaluated,
            'correctResults' => (int) ($row['correct_results'] ?? 0),
            'correctScores' => (int) ($row['correct_scores'] ?? 0),
            'averageConfidence' => $row['avg_confidence'] !== null ? round((float) $row['avg_confidence'], 2) : null,
            'averageDataQuality' => $row['avg_data_quality'] !== null ? round((float) $row['avg_data_quality'], 2) : null,
            'brier' => $evaluated > 0 && (int) ($row['brier_missing'] ?? 0) === 0 && $row['sum_brier'] !== null
                ? round((float) $row['sum_brier'] / $evaluated, 6) : null,
            'logLoss' => $evaluated > 0 && (int) ($row['log_loss_missing'] ?? 0) === 0 && $row['sum_log_loss'] !== null
                ? round((float) $row['sum_log_loss'] / $evaluated, 6) : null,
        ];
    }

    public function listCalibrationSamples(array $filter = []): array
    {
        $select = 's.prediction_id, s.fixture_id, s.actual_home_score, s.actual_away_score, s.actual_result, '
            . 's.correct_result, s.correct_exact_score, s.brier, s.log_loss, s.confidence AS settled_confidence, '
            . 's.data_quality_score, s.model_version_id, s.calibration_version_id, s.settled_at, '
            . 'p.raw_home, p.raw_draw, p.raw_away, p.probability_home, p.probability_draw, p.probability_away, '
            . 'p.confidence, p.confidence_basis, p.calibration_state, p.data_quality_band, p.eligibility, p.kickoff_at, p.generated_at';
        $this->db->select($select, false);
        $this->db->from('football_prediction_settlements s');
        $this->db->join('football_match_predictions p', 'p.id = s.prediction_id', 'inner');
        $this->db->where('s.actual_home_score IS NOT NULL');
        $this->db->where('s.actual_away_score IS NOT NULL');
        if (!empty($filter['modelVersionId'])) $this->db->where('s.model_version_id', (int) $filter['modelVersionId']);
        if (!empty($filter['calibrationState'])) $this->db->where('p.calibration_state', (string) $filter['calibrationState']);
        if (!empty($filter['from'])) $this->db->where('s.settled_at >=', (string) $filter['from']);
        if (!empty($filter['to'])) $this->db->where('s.settled_at <=', (string) $filter['to']);
        $limit = (int) ($filter['limit'] ?? 5000);
        $rows = $this->db->order_by('s.settled_at', 'DESC')->limit(min(10000, max(1, $limit)))->get()->result_array();
        foreach ($rows as &$row) {
            foreach (['raw_home', 'raw_draw', 'raw_away', 'probability_home', 'probability_draw', 'probability_away', 'confidence', 'settled_confidence', 'brier', 'log_loss', 'data_quality_score'] as $key) {
                if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') $row[$key] = (float) $row[$key];
            }
            foreach (['correct_result', 'correct_exact_score'] as $key) {
                if (array_key_exists($key, $row)) $row[$key] = $row[$key] === null ? null : (int) $row[$key];
            }
        }
        unset($row);
        return $rows;
    }

    public function savePerformanceSnapshot(array $row): array
    {
        $data = self::only($row, [
            'model_version_id', 'calibration_version_id', 'window_days', 'window_start', 'window_end',
            'evaluated_predictions', 'correct_results', 'correct_scores', 'result_accuracy', 'exact_score_accuracy',
            'average_confidence', 'average_data_quality', 'brier', 'ece', 'log_loss', 'payload', 'computed_at',
        ]);
        $data['computed_at'] = (string) ($data['computed_at'] ?? gmdate('c'));
        $data['payload'] = json_encode($data['payload'] ?? []);
        $existing = $this->db->get_where('football_model_performance', [
            'model_version_id' => $data['model_version_id'] ?? null,
            'window_days' => (int) ($data['window_days'] ?? 30),
            'window_start' => (string) ($data['window_start'] ?? ''),
            'window_end' => (string) ($data['window_end'] ?? ''),
        ], 1)->row_array();
        if ($existing) {
            $this->db->where('id', (int) $existing['id'])->update('football_model_performance', $data);
            return $this->decode(array_merge($existing, $data));
        }
        $this->db->insert('football_model_performance', $data);
        return $this->decode(array_merge($data, ['id' => (int) $this->db->insert_id()]));
    }

    public function latestPerformanceSnapshot(int $windowDays, ?int $modelVersionId = null): ?array
    {
        $this->db->where('window_days', $windowDays);
        if ($modelVersionId !== null) $this->db->where('model_version_id', $modelVersionId);
        $row = $this->db->order_by('computed_at', 'DESC')->get('football_model_performance', 1)->row_array();
        return $row ? $this->decode($row) : null;
    }

    // ── provider sync log ─────────────────────────────────────────────────────

    public function startSyncRun(array $run): ?array
    {
        $key = (string) ($run['executionKey'] ?? '');
        if ($key === '') throw new \InvalidArgumentException('sync run requires executionKey');
        if ($this->db->get_where('football_provider_sync_logs', ['execution_key' => $key], 1)->row_array()) return null;
        $row = [
            'provider_id' => self::nullableInt($run['providerId'] ?? null),
            'provider_code' => self::nullableString($run['providerCode'] ?? null),
            'job_type' => (string) ($run['jobType'] ?? 'SYNC'),
            'status' => 'RUNNING',
            'execution_key' => $key,
            'window_start' => self::nullableString($run['windowStart'] ?? null),
            'window_end' => self::nullableString($run['windowEnd'] ?? null),
            'attempts' => (int) ($run['attempts'] ?? 1),
            'started_at' => (string) ($run['startedAt'] ?? gmdate('c')),
        ];
        $this->db->insert('football_provider_sync_logs', $row);
        return array_merge($row, ['id' => (int) $this->db->insert_id()]);
    }

    public function finishSyncRun(string $executionKey, array $result): void
    {
        $data = [
            'status' => (string) ($result['status'] ?? 'COMPLETED'),
            'ended_at' => gmdate('c'),
            'records_processed' => (int) ($result['processed'] ?? 0),
            'records_created' => (int) ($result['created'] ?? 0),
            'records_updated' => (int) ($result['updated'] ?? 0),
            'requests_made' => (int) ($result['requests'] ?? 0),
            'rate_limit_remaining' => self::nullableInt($result['rateLimitRemaining'] ?? null),
            'retry_after_seconds' => self::nullableInt($result['retryAfterSeconds'] ?? null),
            'next_run_at' => self::nullableString($result['nextRunAt'] ?? null),
            'errors' => json_encode(array_values(array_slice((array) ($result['errors'] ?? []), 0, 25))),
        ];
        $this->db->where('execution_key', $executionKey)->update('football_provider_sync_logs', $data);
    }

    public function listSyncRuns(?string $jobType = null, int $limit = 50): array
    {
        if ($jobType !== null) $this->db->where('job_type', $jobType);
        $rows = $this->db->order_by('started_at', 'DESC')->limit(min(500, max(1, $limit)))->get('football_provider_sync_logs')->result_array();
        return array_map(fn(array $r) => $this->decode($r), $rows);
    }

    public function pruneSyncLogs(int $olderThanDays = 120): int
    {
        $cutoff = gmdate('c', time() - max(1, $olderThanDays) * 86400);
        $this->db->where('started_at <', $cutoff);
        $this->db->delete('football_provider_sync_logs');
        return is_object($this->db) && method_exists($this->db, 'affected_rows') ? (int) $this->db->affected_rows() : 0;
    }

    public function pruneOrphanScoreRows(): int
    {
        // Score rows are only ever garbage when their prediction row is gone;
        // a prediction is kept even after settlement, so this never touches
        // evidence behind a published figure.
        $this->db->select('s.id', false);
        $this->db->from('football_score_probabilities s');
        $this->db->join('football_match_predictions p', 'p.id = s.prediction_id', 'left');
        $this->db->where('p.id IS NULL');
        $ids = array_column($this->db->get()->result_array(), 'id');
        if ($ids === []) return 0;
        $this->db->where_in('id', $ids);
        $this->db->delete('football_score_probabilities');
        return count($ids);
    }

    public function lastSyncRun(?string $jobType = null, ?int $providerId = null): ?array
    {
        if ($jobType !== null) $this->db->where('job_type', $jobType);
        if ($providerId !== null) $this->db->where('provider_id', $providerId);
        $row = $this->db->order_by('started_at', 'DESC')->get('football_provider_sync_logs', 1)->row_array();
        return $row ? $this->decode($row) : null;
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * Attach the competition's external id and country to fixture rows in one
     * extra query, so downstream code can key statistics by provider league id
     * without a per-fixture lookup.
     */
    private function withCompetitionRef(array $rows): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn(array $r) => (int) ($r['competition_id'] ?? 0), $rows))));
        if ($ids === []) {
            return array_map(static function (array $row) {
                $row['competition_external_id'] = null;
                $row['competition_country'] = null;
                return $row;
            }, $rows);
        }
        $this->db->where_in('id', $ids);
        $lookup = [];
        foreach ($this->db->get('football_competitions')->result_array() as $competition) {
            $lookup[(int) $competition['id']] = $competition;
        }
        $providerIds = array_values(array_unique(array_filter(array_map(static fn(array $r) => (int) ($r['provider_id'] ?? 0), $rows))));
        $providers = [];
        if ($providerIds !== []) {
            $this->db->where_in('id', $providerIds);
            foreach ($this->db->get('football_providers')->result_array() as $provider) {
                $providers[(int) $provider['id']] = $provider;
            }
        }
        foreach ($rows as &$row) {
            $ref = $lookup[(int) ($row['competition_id'] ?? 0)] ?? null;
            $row['competition_external_id'] = $ref['external_id'] ?? null;
            $row['competition_country'] = $ref['country'] ?? null;
            $row['competition_season'] = $ref['season'] ?? null;
            $row['competition_data_state'] = $ref['data_state'] ?? null;
            $provider = $providers[(int) ($row['provider_id'] ?? 0)] ?? null;
            $row['provider_code'] = $provider['provider_code'] ?? null;
            $row['provider_status'] = $provider['status'] ?? null;
        }
        unset($row);
        return $rows;
    }


    /** @param list<string> $columns */
    private static function only(array $row, array $columns): array
    {
        $out = [];
        foreach ($columns as $column) {
            if (array_key_exists($column, $row)) $out[$column] = $row[$column];
        }
        return $out;
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === false) return null;
        return is_numeric($value) ? (int) $value : null;
    }

    private static function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '' || $value === false) return null;
        if (!is_numeric($value)) return null;
        $float = (float) $value;
        return is_finite($float) ? $float : null;
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) return null;
        $string = trim((string) $value);
        return $string === '' ? null : $string;
    }

    private static function camel(string $snake): string
    {
        return preg_replace_callback('/_([a-z])/', static fn(array $m) => strtoupper($m[1]), $snake) ?? $snake;
    }

    /** Provider timestamps → UTC ISO-8601 so string range filters stay correct. */
    private static function iso(string $value): ?string
    {
        if ($value === '') return null;
        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('c');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function decode(array $row): array
    {
        foreach (self::JSON_COLUMNS as $column) {
            if (array_key_exists($column, $row) && is_string($row[$column]) && $row[$column] !== '') {
                $decoded = json_decode($row[$column], true);
                $row[$column] = $decoded ?? $row[$column];
            } elseif (array_key_exists($column, $row) && $row[$column] === null) {
                $row[$column] = null;
            }
        }
        foreach (['probability_home', 'probability_draw', 'probability_away', 'raw_home', 'raw_draw', 'raw_away',
            'confidence', 'expected_total_goals', 'accuracy', 'log_loss', 'brier_score', 'ece', 'brier', 'mce',
            'result_accuracy', 'exact_score_accuracy', 'average_confidence', 'average_data_quality', 'weight',
            'avg_home_goals', 'avg_away_goals', 'coefficient', 'reliability'] as $column) {
            if (array_key_exists($column, $row) && $row[$column] !== null && $row[$column] !== '') {
                $row[$column] = (float) $row[$column];
            }
        }
        return $row;
    }
}
