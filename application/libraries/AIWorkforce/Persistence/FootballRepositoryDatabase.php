<?php
namespace AIWorkforce\Persistence;

use AIWorkforce\Football\CanonicalMatch;

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
        'trigger_codes',
    ];

    public function __construct(private object $db) {}

    /**
     * Convert any timestamp (RFC3339 with T+offset, or DATETIME) to MySQL DATETIME literal
     * 'Y-m-d H:i:s' UTC — the only literal that survives strict mode on all drivers.
     */
    private static function toSqlDateTime(?string $value): ?string
    {
        if ($value === null || trim($value) === '') return null;
        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function nowSql(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    // ── providers ───────────────────────────────────────────────────────────

    public function ensureProvider(string $code, array $attributes = []): array
    {
        $row = $this->db->get_where('football_providers', ['provider_code' => $code], 1)->row_array();
        $now = self::nowSql();
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
                default => $key,
            };
            $data[$column] = match (true) {
                $key === 'capabilities' => json_encode((array) $value),
                $key === 'demoMode' => !empty($value) ? 1 : 0,
                default => $value,
            };
        }
        if ($data) {
            $data['updated_at'] = self::nowSql();
            $this->db->where('id', $id)->update('football_providers', $data);
        }
    }

    /** @var array{at:int,rows:array}|null — provider list changes rarely */
    private ?array $providersMemo = null;

    public function listProviders(bool $enabledOnly = false): array
    {
        if (!$enabledOnly && $this->providersMemo !== null && (time() - $this->providersMemo['at']) < 10) {
            return $this->providersMemo['rows'];
        }
        if ($enabledOnly) $this->db->where('enabled', 1);
        $rows = $this->db->order_by('id', 'ASC')->get('football_providers')->result_array();
        $decoded = array_map(fn(array $r) => $this->decode($r), $rows);
        if (!$enabledOnly) $this->providersMemo = ['at' => time(), 'rows' => $decoded];
        return $decoded;
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
            'fetched_at' => (string) ($row['fetchedAt'] ?? self::nowSql()),
            'updated_at' => self::nowSql(),
        ];
        $existing = $this->db->where(['provider_id' => $providerId, 'external_id' => $externalId, 'season' => $season])
            ->get('football_competitions', 1)->row_array();
        if ($existing) {
            $this->db->where('id', (int) $existing['id'])->update('football_competitions', $data);
            return $this->decode(array_merge($existing, $data));
        }
        $this->db->insert('football_competitions', array_merge(['provider_id' => $providerId, 'external_id' => $externalId, 'created_at' => self::nowSql()], $data));
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
            'fetched_at' => (string) ($row['fetchedAt'] ?? self::nowSql()),
            'updated_at' => self::nowSql(),
        ];
        $existing = $this->db->get_where('football_teams', ['provider_id' => $providerId, 'external_id' => $externalId], 1)->row_array();
        if ($existing) {
            $this->db->where('id', (int) $existing['id'])->update('football_teams', $data);
            return $this->decode(array_merge($existing, $data));
        }
        $this->db->insert('football_teams', array_merge(['provider_id' => $providerId, 'external_id' => $externalId, 'created_at' => self::nowSql()], $data));
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
        $now = self::nowSql();
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

    public function listFixtures(array $filter = [], int $limit = 500, int $offset = 0): array
    {
        $this->applyFixtureFilter($filter);
        // id breaks ties between two fixtures with the same kickoff: without a
        // total order the same match can appear on two pages, or on neither.
        $rows = $this->db->order_by('kickoff_at', 'ASC')->order_by('id', 'ASC')
            ->limit(min(2000, max(1, $limit)), max(0, $offset))->get('football_fixtures')->result_array();
        return $this->withCompetitionRef(array_map(fn(array $r) => $this->decode($r), $rows));
    }

    public function countFixtures(array $filter = []): int
    {
        $this->applyFixtureFilter($filter);
        return (int) $this->db->count_all_results('football_fixtures');
    }

    /** @param array<string,mixed> $filter */
    private function applyFixtureFilter(array $filter): void
    {
        // Resolved before anything else is added: CodeIgniter resets the query
        // builder when a query runs, so a lookup squeezed between two `where`
        // calls would silently drop the conditions that came before it.
        $competitionIds = null;
        if (array_key_exists('competitionExternalIds', $filter) && is_array($filter['competitionExternalIds'])) {
            // A group of leagues (the "all premium leagues" selection) filters
            // on every competition row whose provider external id is in the
            // group. An empty group is a real answer — none of the leagues
            // asked for is stored — and must narrow the page to nothing, never
            // widen it to every league.
            $externalIds = array_values(array_unique(array_filter(
                array_map('strval', $filter['competitionExternalIds']),
                static fn(string $v): bool => $v !== '')));
            if ($externalIds !== []) {
                $rows = $this->db->select('id')->where_in('external_id', $externalIds)
                    ->get('football_competitions')->result_array();
                $competitionIds = array_map(static fn(array $row): int => (int) $row['id'], $rows);
            } else {
                $competitionIds = [];
            }
        } elseif (!empty($filter['competitionExternalId'])) {
            // Narrowing the page to one league is a filter on the competition
            // row, not a free-text match on a name a provider may spell
            // differently: two competitions whose names share a prefix must not
            // end up on the same page.
            $rows = $this->db->select('id')->where('external_id', (string) $filter['competitionExternalId'])
                ->get('football_competitions')->result_array();
            $competitionIds = array_map(static fn(array $row): int => (int) $row['id'], $rows);
        }
        if (!empty($filter['providerId'])) $this->db->where('provider_id', (int) $filter['providerId']);
        if (!empty($filter['status'])) $this->db->where('status', strtoupper((string) $filter['status']));
        if (!empty($filter['matchState'])) $this->db->where('match_state', strtoupper((string) $filter['matchState']));
        if (!empty($filter['date'])) {
            $date = (string) $filter['date'];
            // Accept both ISO8601 and DATETIME stored values
            $this->db->group_start();
            $this->db->where('kickoff_at >=', self::isoForFilter($date, true));
            $this->db->where('kickoff_at <=', self::isoForFilter($date, false));
            $this->db->or_group_start();
            $this->db->where('kickoff_at >=', $date . 'T00:00:00+00:00');
            $this->db->where('kickoff_at <=', $date . 'T23:59:59+00:00');
            $this->db->group_end();
            $this->db->group_end();
        }
        if (!empty($filter['from'])) $this->db->where('kickoff_at >=', (string) $filter['from']);
        if (!empty($filter['to'])) $this->db->where('kickoff_at <=', (string) $filter['to']);
        if (!empty($filter['competition'])) $this->db->like('competition', (string) $filter['competition'], 'after');
        if ($competitionIds !== null) $this->db->where_in('competition_id', $competitionIds === [] ? [0] : $competitionIds);
        if (!empty($filter['team'])) {
            $team = (string) $filter['team'];
            $this->db->group_start()->like('home_team', $team)->or_like('away_team', $team)->group_end();
        }
        if (!empty($filter['settledOnly'])) $this->db->where('settled_at IS NOT NULL');
        if (!empty($filter['unsettledFinished'])) {
            $this->db->where('status', 'FINISHED');
            $this->db->where('settled_at', null);
        }
    }

    public function markFixtureSettled(int $id, string $at): void
    {
        $this->db->where('id', $id)->update('football_fixtures', ['settled_at' => $at, 'updated_at' => self::nowSql()]);
    }

    /**
     * Competitions come from the rows the provider sent, with the match count
     * for the requested date counted separately. A competition with no match on
     * that date is still listed — with 0, which is a real answer — so the
     * dropdown never silently shrinks to "what happens to be on today".
     *
     * @param array<string,mixed> $filter
     * @return list<array<string,mixed>>
     */
    /**
     * A provider's league id onto the internal competition. Stored per provider
     * so the same competition can be recognised under three different ids.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function saveCompetitionMapping(array $row): array
    {
        $providerCode = trim((string) ($row['providerCode'] ?? ''));
        $external = trim((string) ($row['providerCompetitionId'] ?? ''));
        if ($providerCode === '' || $external === '') throw new \InvalidArgumentException('competition mapping requires providerCode and providerCompetitionId');
        $now = self::nowSql();
        $data = [
            'internal_id' => trim((string) ($row['internalId'] ?? '')),
            'provider_id' => self::nullableInt($row['providerId'] ?? null),
            'provider_code' => $providerCode,
            'provider_competition_id' => $external,
            'competition_name' => trim((string) ($row['competitionName'] ?? '')),
            'country' => self::nullableString($row['country'] ?? null),
            'tier' => strtoupper((string) ($row['tier'] ?? 'STANDARD')),
            'premium' => !empty($row['premium']) ? 1 : 0,
            'active' => array_key_exists('active', $row) ? (!empty($row['active']) ? 1 : 0) : 1,
            'updated_at' => $now,
        ];
        $existing = $this->findCompetitionMapping($providerCode, $external);
        if ($existing !== null) {
            $this->db->where('id', (int) $existing['id'])->update('football_competition_mapping', $data);
            return array_merge($existing, $data);
        }
        $this->db->insert('football_competition_mapping', array_merge($data, ['created_at' => $now]));
        return array_merge($data, ['id' => (int) $this->db->insert_id(), 'created_at' => $now]);
    }

    public function findCompetitionMapping(string $providerCode, string $providerCompetitionId): ?array
    {
        $row = $this->db->where('provider_code', $providerCode)->where('provider_competition_id', $providerCompetitionId)
            ->get('football_competition_mapping', 1)->row_array();
        return $row ? $this->decode($row) : null;
    }

    /** @param array<string,mixed> $filter */
    public function listCompetitionMappings(array $filter = [], int $limit = 500): array
    {
        if (array_key_exists('premium', $filter)) $this->db->where('premium', !empty($filter['premium']) ? 1 : 0);
        if (array_key_exists('active', $filter)) $this->db->where('active', !empty($filter['active']) ? 1 : 0);
        if (!empty($filter['internalId'])) $this->db->where('internal_id', (string) $filter['internalId']);
        if (!empty($filter['providerCode'])) $this->db->where('provider_code', (string) $filter['providerCode']);
        $rows = $this->db->order_by('premium', 'DESC')->order_by('competition_name', 'ASC')
            ->get('football_competition_mapping', max(1, min(2000, $limit)))->result_array();
        return array_map(fn(array $row): array => $this->decode($row), $rows);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function saveProviderMatch(array $row): array
    {
        $providerCode = trim((string) ($row['providerCode'] ?? ''));
        $external = trim((string) ($row['providerMatchId'] ?? ''));
        $internal = trim((string) ($row['internalMatchId'] ?? ''));
        if ($providerCode === '' || $external === '' || $internal === '') {
            throw new \InvalidArgumentException('provider match requires providerCode, providerMatchId and internalMatchId');
        }
        $now = self::nowSql();
        $data = [
            'internal_match_id' => $internal,
            'provider_id' => self::nullableInt($row['providerId'] ?? null),
            'provider_code' => $providerCode,
            'provider_match_id' => $external,
            'home_team_normalized' => CanonicalMatch::normalizeTeam((string) ($row['homeTeam'] ?? '')),
            'away_team_normalized' => CanonicalMatch::normalizeTeam((string) ($row['awayTeam'] ?? '')),
            'kickoff_date' => CanonicalMatch::kickoffDate((string) ($row['kickoff'] ?? '')),
            'competition_internal_id' => self::nullableString($row['competitionInternalId'] ?? null),
            'matched_by' => (string) ($row['matchedBy'] ?? CanonicalMatch::MATCH_PROVIDER_ID),
            'confidence' => self::nullableFloat($row['confidence'] ?? null),
            'last_seen_at' => $now,
        ];
        $existing = $this->findProviderMatch($providerCode, $external);
        if ($existing !== null) {
            $this->db->where('id', (int) $existing['id'])->update('football_provider_matches', $data);
            return array_merge($existing, $data);
        }
        $this->db->insert('football_provider_matches', array_merge($data, ['first_seen_at' => $now]));
        return array_merge($data, ['id' => (int) $this->db->insert_id(), 'first_seen_at' => $now]);
    }

    public function findProviderMatch(string $providerCode, string $providerMatchId): ?array
    {
        $row = $this->db->where('provider_code', $providerCode)->where('provider_match_id', $providerMatchId)
            ->get('football_provider_matches', 1)->row_array();
        return $row ? $this->decode($row) : null;
    }

    public function listProviderMatches(string $internalMatchId): array
    {
        $rows = $this->db->where('internal_match_id', $internalMatchId)->order_by('provider_code', 'ASC')
            ->get('football_provider_matches')->result_array();
        return array_map(fn(array $row): array => $this->decode($row), $rows);
    }

    /** @param list<string> $internalMatchIds */
    public function listProviderMatchesFor(array $internalMatchIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $internalMatchIds), static fn(string $id): bool => $id !== '')));
        if ($ids === []) return [];
        $rows = $this->db->where_in('internal_match_id', $ids)->order_by('provider_code', 'ASC')
            ->get('football_provider_matches')->result_array();
        $out = [];
        foreach ($rows as $row) {
            $row = $this->decode($row);
            $out[(string) ($row['internal_match_id'] ?? '')][] = $row;
        }
        return $out;
    }

    /**
     * Resolve a provider row to a canonical match: exact identity first, then
     * the same provider match id, then normalized teams on the same kickoff
     * date (fuzzy spellings included). A near miss below the threshold is not a
     * match — forcing it would merge two different fixtures.
     *
     * @param array<string,mixed> $candidate
     * @return array{row:array<string,mixed>, score:float, matchedBy:string}|null
     */
    public function resolveCanonicalMatch(array $candidate): ?array
    {
        $internal = trim((string) ($candidate['internalMatchId'] ?? ''));
        if ($internal !== '') {
            $rows = $this->listProviderMatches($internal);
            if ($rows !== []) return ['row' => $rows[0], 'score' => 1.0, 'matchedBy' => CanonicalMatch::MATCH_TEAMS_DATE];
        }
        $home = CanonicalMatch::normalizeTeam((string) ($candidate['homeTeam'] ?? ''));
        $away = CanonicalMatch::normalizeTeam((string) ($candidate['awayTeam'] ?? ''));
        $date = CanonicalMatch::kickoffDate((string) ($candidate['kickoff'] ?? ''));
        if ($home === '' || $away === '' || $date === '') return null;
        // Candidate rows are the matches that day: a bounded read, not a scan
        // of every fixture ever stored.
        $this->db->where('kickoff_date', $date);
        $rows = $this->db->order_by('id', 'ASC')->get('football_provider_matches', 500)->result_array();
        $best = null;
        foreach ($rows as $row) {
            $row = $this->decode($row);
            $score = CanonicalMatch::matchScore($candidate, [
                'providerCode' => (string) ($row['provider_code'] ?? ''),
                'providerMatchId' => (string) ($row['provider_match_id'] ?? ''),
                'homeTeam' => (string) ($row['home_team_normalized'] ?? ''),
                'awayTeam' => (string) ($row['away_team_normalized'] ?? ''),
                'kickoff' => (string) ($row['kickoff_date'] ?? ''),
            ]);
            if ($score['score'] < CanonicalMatch::FUZZY_THRESHOLD) continue;
            if ($best === null || $score['score'] > $best['score']) $best = ['row' => $row, 'score' => $score['score'], 'matchedBy' => $score['matchedBy']];
        }
        return $best;
    }

    public function listCompetitions(array $filter = [], int $limit = 200): array
    {
        $this->db->order_by('name', 'ASC');
        if (!empty($filter['providerId'])) $this->db->where('provider_id', (int) $filter['providerId']);
        $competitions = $this->db->get('football_competitions', max(1, min(500, $limit)))->result_array();
        if ($competitions === []) return [];

        $this->db->select('competition_id, COUNT(*) AS matches')->where('competition_id IS NOT NULL');
        if (!empty($filter['date'])) {
            $date = (string) $filter['date'];
            // Accept both ISO8601 and DATETIME stored values
            $this->db->group_start();
            $this->db->where('kickoff_at >=', self::isoForFilter($date, true));
            $this->db->where('kickoff_at <=', self::isoForFilter($date, false));
            $this->db->or_group_start();
            $this->db->where('kickoff_at >=', $date . 'T00:00:00+00:00');
            $this->db->where('kickoff_at <=', $date . 'T23:59:59+00:00');
            $this->db->group_end();
            $this->db->group_end();
        }
        $counts = [];
        foreach ($this->db->group_by('competition_id')->get('football_fixtures')->result_array() as $row) {
            $counts[(int) $row['competition_id']] = (int) $row['matches'];
        }
        $out = [];
        foreach ($competitions as $row) {
            $row = $this->decode($row);
            $out[] = [
                'id' => (int) $row['id'],
                'providerId' => (int) $row['provider_id'],
                'externalId' => (string) $row['external_id'],
                'name' => (string) $row['name'],
                'country' => $row['country'] ?? null,
                'season' => $row['season'] ?? null,
                'matches' => $counts[(int) $row['id']] ?? 0,
            ];
        }
        usort($out, static fn(array $a, array $b) => [$b['matches'], $a['name']] <=> [$a['matches'], $b['name']]);
        return $out;
    }

    public function linkFixtureCompetition(int $fixtureId, int $competitionId): void
    {
        $this->db->where('id', $fixtureId)->update('football_fixtures', ['competition_id' => $competitionId, 'updated_at' => self::nowSql()]);
    }

    public function listFixturesAwaitingResult(int $limit = 200, ?int $providerId = null): array
    {
        $this->db->group_start();
        $this->db->where('status', 'LIVE')
            ->or_group_start()->where('status', 'SCHEDULED')->where('kickoff_at <=', self::nowSql())->group_end()
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
        $data['fetched_at'] = (string) ($row['fetchedAt'] ?? self::nowSql());
        $data['updated_at'] = self::nowSql();
        $existing = $this->db->where(['provider_id' => $providerId, 'team_external_id' => $teamId])
            ->where('competition_external_id', $competitionId)->where('season', $season)
            ->get('football_team_statistics', 1)->row_array();
        if ($existing) {
            $this->db->where('id', (int) $existing['id'])->update('football_team_statistics', $data);
            return $this->decode(array_merge($existing, $data));
        }
        $this->db->insert('football_team_statistics', array_merge([
            'provider_id' => $providerId, 'team_external_id' => $teamId,
            'competition_external_id' => $competitionId, 'season' => $season, 'created_at' => self::nowSql(),
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
            'fetched_at' => self::nowSql(),
        ];
        $existing = $this->db->get_where('football_fixture_statistics', ['fixture_id' => $fixtureId, 'provider_id' => $providerId, 'kind' => $kind], 1)->row_array();
        if ($existing) {
            $this->db->where('id', (int) $existing['id'])->update('football_fixture_statistics', $data);
            return $this->decode(array_merge($existing, $data));
        }
        $this->db->insert('football_fixture_statistics', array_merge([
            'fixture_id' => $fixtureId, 'provider_id' => $providerId, 'kind' => $kind, 'created_at' => self::nowSql(),
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
            'fetched_at' => (string) ($row['fetchedAt'] ?? self::nowSql()),
            'updated_at' => self::nowSql(),
        ];
        $existing = $this->db->where(['provider_id' => $providerId, 'home_team_external_id' => $homeId, 'away_team_external_id' => $awayId])
            ->where('competition_external_id', $competitionId)->get('football_head_to_head', 1)->row_array();
        if ($existing) {
            $this->db->where('id', (int) $existing['id'])->update('football_head_to_head', $data);
            return $this->decode(array_merge($existing, $data));
        }
        $this->db->insert('football_head_to_head', array_merge([
            'provider_id' => $providerId, 'home_team_external_id' => $homeId, 'away_team_external_id' => $awayId,
            'competition_external_id' => $competitionId, 'created_at' => self::nowSql(),
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
        $data['updated_at'] = self::nowSql();
        $existing = $this->findModelVersionByName($name, $version);
        if ($existing !== null) {
            $this->db->where('id', (int) $existing['id'])->update('football_model_versions', $data);
            return $this->decode(array_merge($existing, $data));
        }
        $this->db->insert('football_model_versions', array_merge($data, ['created_at' => self::nowSql()]));
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
        $data['updated_at'] = self::nowSql();
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
        $now = self::nowSql();
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
        $data['updated_at'] = self::nowSql();
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
        $now = self::nowSql();
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

    public function listPredictions(array $filter = [], int $limit = 500, int $offset = 0): array
    {
        $this->applyPredictionFilter($filter);
        $rows = $this->db->order_by('generated_at', 'DESC')->order_by('id', 'DESC')
            ->limit(min(2000, max(1, $limit)), max(0, $offset))->get('football_match_predictions')->result_array();
        return array_map(fn(array $r) => $this->decode($r), $rows);
    }

    public function countPredictions(array $filter = []): int
    {
        $this->applyPredictionFilter($filter);
        return (int) $this->db->count_all_results('football_match_predictions');
    }

    public function listPredictionsForFixtures(array $fixtureIds, string $kind, ?int $modelVersionId = null): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $fixtureIds), static fn(int $id): bool => $id > 0)));
        if ($ids === []) return [];
        $this->db->where_in('fixture_id', $ids)->where('prediction_kind', $kind);
        if ($modelVersionId !== null) {
            // A NULL model version is a real state (a prediction made before a
            // model row existed), so it is matched as NULL rather than skipped.
            $modelVersionId > 0
                ? $this->db->where('model_version_id', $modelVersionId)
                : $this->db->where('model_version_id IS NULL');
        }
        $rows = $this->db->order_by('generated_at', 'DESC')->get('football_match_predictions')->result_array();
        $out = [];
        foreach ($rows as $row) {
            $row = $this->decode($row);
            // One row per match: the newest prediction for that match wins,
            // which is also the row the unique key protects from duplicating.
            $out[(int) $row['fixture_id']] ??= $row;
        }
        return $out;
    }

    /** @param array<string,mixed> $filter */
    private function applyPredictionFilter(array $filter): void
    {
        // Resolved first, for the same reason as the fixture filter: a query
        // run in the middle of building this one would reset the builder and
        // drop every condition added before it.
        $fixtureIds = null;
        if (array_key_exists('competitionExternalIds', $filter) && is_array($filter['competitionExternalIds'])) {
            // A group of leagues (the "all premium leagues" selection) resolves
            // through every competition row whose external id is in the group.
            // An empty group must match nothing, never the whole date.
            $externalIds = array_values(array_unique(array_filter(
                array_map('strval', $filter['competitionExternalIds']),
                static fn(string $v): bool => $v !== '')));
            $competitionIds = [];
            if ($externalIds !== []) {
                $competitions = $this->db->select('id')->where_in('external_id', $externalIds)
                    ->get('football_competitions')->result_array();
                $competitionIds = array_map(static fn(array $row): int => (int) $row['id'], $competitions);
            }
            if ($competitionIds !== []) {
                $fixtures = $this->db->select('id')->where_in('competition_id', $competitionIds)
                    ->get('football_fixtures')->result_array();
                $fixtureIds = array_map(static fn(array $row): int => (int) $row['id'], $fixtures);
            } else {
                $fixtureIds = [];
            }
        } elseif (!empty($filter['competitionExternalId'])) {
            // A prediction stores its kickoff but not its league, so the
            // competition is resolved through the fixture it belongs to. A
            // date-wide count taken without this would report the whole date
            // while the page shows one league.
            $competitions = $this->db->select('id')->where('external_id', (string) $filter['competitionExternalId'])
                ->get('football_competitions')->result_array();
            $competitionIds = array_map(static fn(array $row): int => (int) $row['id'], $competitions);
            $fixtures = $competitionIds === [] ? [] : $this->db->select('id')->where_in('competition_id', $competitionIds)
                ->get('football_fixtures')->result_array();
            $fixtureIds = array_map(static fn(array $row): int => (int) $row['id'], $fixtures);
        }
        if (!empty($filter['fixtureId'])) $this->db->where('fixture_id', (int) $filter['fixtureId']);
        if (!empty($filter['kind'])) $this->db->where('prediction_kind', (string) $filter['kind']);
        if (!empty($filter['eligibility'])) $this->db->where('eligibility', (string) $filter['eligibility']);
        if (!empty($filter['modelVersionId'])) $this->db->where('model_version_id', (int) $filter['modelVersionId']);
        if (!empty($filter['settlementState'])) $this->db->where('settlement_state', (string) $filter['settlementState']);
        if (!empty($filter['date'])) {
            $date = (string) $filter['date'];
            // Accept both ISO8601 and DATETIME stored values
            $this->db->group_start();
            $this->db->where('kickoff_at >=', self::isoForFilter($date, true));
            $this->db->where('kickoff_at <=', self::isoForFilter($date, false));
            $this->db->or_group_start();
            $this->db->where('kickoff_at >=', $date . 'T00:00:00+00:00');
            $this->db->where('kickoff_at <=', $date . 'T23:59:59+00:00');
            $this->db->group_end();
            $this->db->group_end();
        }
        if (!empty($filter['from'])) $this->db->where('generated_at >=', (string) $filter['from']);
        if (!empty($filter['to'])) $this->db->where('generated_at <=', (string) $filter['to']);
        if ($fixtureIds !== null) $this->db->where_in('fixture_id', $fixtureIds === [] ? [0] : $fixtureIds);
    }

    public function saveScoreProbabilities(string $predictionId, array $rows): void
    {
        $this->db->where('prediction_id', $predictionId)->delete('football_score_probabilities');
        $now = self::nowSql();
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

    /**
     * One batched read for a page of grids. The rows are grouped in PHP rather
     * than with a dialect-specific aggregate so the same statement runs on all
     * three supported databases.
     *
     * @param list<string> $predictionIds
     * @return array<string,list<array{home:int,away:int,probability:float}>>
     */
    public function listScoreProbabilitiesFor(array $predictionIds, int $limitPerPrediction = 200): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn($id): string => (string) $id, $predictionIds))));
        if ($ids === []) return [];
        $cap = max(1, min(400, $limitPerPrediction));
        $out = [];
        foreach (array_chunk($ids, 200) as $chunk) {
            $rows = $this->db->where_in('prediction_id', $chunk)->order_by('probability', 'DESC')
                ->get('football_score_probabilities')->result_array();
            foreach ($rows as $row) {
                $predictionId = (string) $row['prediction_id'];
                if (count($out[$predictionId] ?? []) >= $cap) continue;
                $out[$predictionId][] = ['home' => (int) $row['home_goals'], 'away' => (int) $row['away_goals'],
                    'probability' => (float) $row['probability']];
            }
        }
        return $out;
    }

    /**
     * Prices the connected odds feed has quoted, read across the sports tables
     * by the one identity both modules share: the provider's own match id.
     *
     * The join is deliberately careful — football fixtures and sports matches
     * live in different tables with different provider ids, so a match is only
     * matched when the *provider code* and the *external id* agree. Anything it
     * cannot match comes back absent, which the caller prints as
     * DATA_UNAVAILABLE rather than as a price.
     *
     * @param list<string> $matchIds `providerCode:externalId`
     * @return array<string,list<array{market:string,selection:string,decimalOdds:float,observedAt:?string}>>
     */
    public function listMarketOdds(array $matchIds): array
    {
        $wanted = [];
        foreach ($matchIds as $matchId) {
            $matchId = trim((string) $matchId);
            if ($matchId === '') continue;
            $parts = explode(':', $matchId, 2);
            $external = $parts[1] ?? $parts[0];
            $code = $parts[1] ?? '' ? $parts[0] : '';
            if ($external === '') continue;
            $wanted[$code][] = $external;
        }
        if ($wanted === []) return [];
        foreach (['sports_data_sources', 'sports_matches', 'sports_odds'] as $table) {
            if (!$this->db->table_exists($table)) return [];
        }

        $out = [];
        foreach ($wanted as $code => $externals) {
            $sources = $this->db->where('provider_code', (string) $code)->get('sports_data_sources', 1)->result_array();
            if ($sources === []) continue;
            $providerId = (int) $sources[0]['id'];
            $matches = $this->db->select('id, external_id')->where('provider_id', $providerId)
                ->where_in('external_id', array_values(array_unique($externals)))->get('sports_matches')->result_array();
            if ($matches === []) continue;
            $ids = array_map(static fn(array $row): int => (int) $row['id'], $matches);
            $externalById = [];
            foreach ($matches as $row) $externalById[(int) $row['id']] = (string) $row['external_id'];
            $rows = $this->db->where_in('match_id', $ids)->order_by('observed_at', 'DESC')
                ->limit(4000)->get('sports_odds')->result_array();
            foreach ($rows as $row) {
                $external = $externalById[(int) $row['match_id']] ?? null;
                if ($external === null) continue;
                $price = is_numeric($row['decimal_odds'] ?? null) ? (float) $row['decimal_odds'] : null;
                // Validate odds: must be >1.0, ≤100, finite — reject unrealistic high odds
                if ($price === null || $price <= 1.0 || $price > 100.0 || !is_finite($price)) continue;
                $market = trim((string) ($row['market'] ?? ''));
                $selection = trim((string) ($row['selection'] ?? ''));
                if ($market === '' || $selection === '') continue;
                $key = $code === '' ? $external : $code . ':' . $external;
                $out[$key][] = ['market' => $market, 'selection' => $selection,
                    'decimalOdds' => $price, 'observedAt' => $row['observed_at'] ?? null];
            }
        }
        return $out;
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
        $data['created_at'] = self::nowSql();
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
        $data['computed_at'] = (string) ($data['computed_at'] ?? self::nowSql());
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
            'started_at' => (string) ($run['startedAt'] ?? self::nowSql()),
        ];
        $this->db->insert('football_provider_sync_logs', $row);
        return array_merge($row, ['id' => (int) $this->db->insert_id()]);
    }

    public function finishSyncRun(string $executionKey, array $result): void
    {
        $data = [
            'status' => (string) ($result['status'] ?? 'COMPLETED'),
            'ended_at' => self::nowSql(),
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
        $cutoff = gmdate('Y-m-d H:i:s', time() - max(1, $olderThanDays) * 86400);
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

    // ── prediction revisions (the movement history) ───────────────────────────

    /**
     * Columns of one revision. The probabilities are stored alongside the
     * movement measured from the previous revision so the number a reader sees
     * can be re-derived from the row without consulting the model again.
     */
    private const REVISION_COLUMNS = [
        'prediction_id', 'fixture_id', 'provider_id', 'model_version_id', 'prediction_kind',
        'probability_home', 'probability_draw', 'probability_away', 'predicted_result',
        'predicted_home_score', 'predicted_away_score', 'confidence', 'data_quality_score',
        'data_quality_band', 'movement_points', 'movement_selection', 'previous_prediction_id',
        'stability_state', 'trigger_codes', 'kickoff_at', 'recorded_at',
    ];

    public function savePredictionRevision(array $row): array
    {
        if (!$this->db->table_exists('football_prediction_revisions')) {
            // A database that has not run the migration yet cannot record a trail.
            // The read path reports BASELINE for that case, so nothing downstream
            // is entitled to infer stability from the absence of rows.
            return ['row' => [], 'created' => false];
        }
        $predictionId = (string) ($row['prediction_id'] ?? '');
        if ($predictionId === '') throw new \InvalidArgumentException('a prediction revision requires a prediction_id');
        $existing = $this->db->get_where('football_prediction_revisions', ['prediction_id' => $predictionId], 1)->row_array();
        if ($existing !== null && $existing !== []) return ['row' => $this->decode($existing), 'created' => false];
        $data = self::only($row, self::REVISION_COLUMNS);
        $data['prediction_id'] = $predictionId;
        foreach (['probability_home', 'probability_draw', 'probability_away', 'movement_points'] as $column) {
            if (array_key_exists($column, $data) && $data[$column] !== null) $data[$column] = (float) $data[$column];
        }
        foreach (['fixture_id', 'provider_id', 'model_version_id', 'predicted_home_score', 'predicted_away_score',
            'data_quality_score'] as $column) {
            if (array_key_exists($column, $data) && $data[$column] !== null) $data[$column] = (int) $data[$column];
        }
        if (isset($data['trigger_codes']) && !is_string($data['trigger_codes'])) {
            $data['trigger_codes'] = json_encode(array_values((array) $data['trigger_codes']));
        }
        $data['created_at'] = self::nowSql();
        $this->db->insert('football_prediction_revisions', $data);
        $stored = $this->db->get_where('football_prediction_revisions', ['prediction_id' => $predictionId], 1)->row_array();
        return ['row' => $stored ? $this->decode($stored) : $data, 'created' => true];
    }

    public function listPredictionRevisions(array $fixtureIds, string $kind, int $limitPerFixture = 5): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $fixtureIds), static fn(int $id): bool => $id > 0)));
        if ($ids === [] || !$this->db->table_exists('football_prediction_revisions')) return [];
        // One read for a whole page, capped rather than unbounded: 50 matches at
        // five revisions each is the most any board can show, and a page that
        // fetched the entire history of a fixture would grow forever.
        $rows = $this->db->where_in('fixture_id', $ids)->where('prediction_kind', $kind)
            ->order_by('recorded_at', 'DESC')
            ->limit(count($ids) * max(1, $limitPerFixture))
            ->get('football_prediction_revisions')->result_array();
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            $fixtureId = (int) ($row['fixture_id'] ?? 0);
            if (($seen[$fixtureId] ?? 0) >= max(1, $limitPerFixture)) continue;
            $seen[$fixtureId] = ($seen[$fixtureId] ?? 0) + 1;
            $out[$fixtureId][] = $this->decode($row);
        }
        return $out;
    }

    public function prunePredictionRevisions(int $olderThanDays = 90): int
    {
        if (!$this->db->table_exists('football_prediction_revisions')) return 0;
        $cutoff = gmdate('Y-m-d H:i:s', time() - max(1, $olderThanDays) * 86400);
        $this->db->where('recorded_at <', $cutoff);
        $this->db->delete('football_prediction_revisions');
        return is_object($this->db) && method_exists($this->db, 'affected_rows') ? (int) $this->db->affected_rows() : 0;
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * Attach the competition's external id and country to fixture rows in one
     * extra query, so downstream code can key statistics by provider league id
     * without a per-fixture lookup.
     */
    /** @var array<int,array> per-process memo for withCompetitionRef (WASM cost: 2 queries per listFixtures) */
    private array $competitionMemo = [];
    private array $providerMemo = [];

    private function withCompetitionRef(array $rows): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn(array $r) => (int) ($r['competition_id'] ?? 0), $rows))));
        $lookup = [];
        if ($ids !== []) {
            // NOT a static closure: the memo lives on $this, and a static
            // closure has no object context — `$this` inside one is a fatal
            // Error ("Using $this when not in object context") that took the
            // whole /football page down for every fixture that carries a
            // competition_id.
            $missing = array_values(array_filter($ids, fn(int $id): bool => !isset($this->competitionMemo[$id])));
            if ($missing !== []) {
                $this->db->where_in('id', $missing);
                foreach ($this->db->get('football_competitions')->result_array() as $competition) {
                    $this->competitionMemo[(int) $competition['id']] = $competition;
                }
                // For ids that returned no row, store null sentinel to avoid re-query
                foreach ($missing as $mid) {
                    if (!isset($this->competitionMemo[$mid])) $this->competitionMemo[$mid] = [];
                }
            }
            foreach ($ids as $id) {
                $row = $this->competitionMemo[$id] ?? null;
                if (is_array($row) && $row !== []) $lookup[$id] = $row;
            }
        }
        $providerIds = array_values(array_unique(array_filter(array_map(static fn(array $r) => (int) ($r['provider_id'] ?? 0), $rows))));
        $providers = [];
        if ($providerIds !== []) {
            // Same reason as competitionMemo above: $this needs a bound context.
            $missingP = array_values(array_filter($providerIds, fn(int $id): bool => !isset($this->providerMemo[$id])));
            if ($missingP !== []) {
                $this->db->where_in('id', $missingP);
                foreach ($this->db->get('football_providers')->result_array() as $provider) {
                    $this->providerMemo[(int) $provider['id']] = $provider;
                }
                foreach ($missingP as $pid) {
                    if (!isset($this->providerMemo[$pid])) $this->providerMemo[$pid] = [];
                }
            }
            foreach ($providerIds as $pid) {
                $row = $this->providerMemo[$pid] ?? null;
                if (is_array($row) && $row !== []) $providers[$pid] = $row;
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

    /** Provider timestamps → UTC DATETIME literal for MySQL strict mode. */
    private static function iso(string $value): ?string
    {
        if ($value === '') return null;
        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** For date range filters — accepts both c and Y-m-d H:i:s stored values. */
    private static function isoForFilter(string $date, bool $start): string
    {
        return $start ? $date . ' 00:00:00' : $date . ' 23:59:59';
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
