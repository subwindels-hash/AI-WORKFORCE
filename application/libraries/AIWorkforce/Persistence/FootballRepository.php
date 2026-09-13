<?php
namespace AIWorkforce\Persistence;

/**
 * Football Intelligence persistence contract.
 *
 * The repository is the only path between the football domain services and SQL.
 * Two rules are baked into the interface itself, because they are the rules the
 * module keeps being asked about:
 *
 *  1. Nothing is invented on read. `find*()` return null (not a zeroed row) when
 *     a provider never supplied the record, and every entity carries a
 *     `data_state` (AVAILABLE | LIMITED_DATA | DATA_UNAVAILABLE) plus the
 *     component `coverage` that produced it. A missing score is NULL, never 0.
 *  2. Stored decisions are append-only once settled. `savePrediction()` may be
 *     re-run for a fixture BEFORE kickoff; `findSettlement()`/`saveSettlement()`
 *     are idempotent per prediction, and a settled prediction row is never
 *     rewritten — so live scoring can never corrupt historical evaluation.
 *
 * Implemented over CI3's query builder (application/models/AIWorkforce_model.php)
 * and by an in-memory stub for tests (tests/framework.php).
 */
interface FootballRepository
{
    // ── providers ───────────────────────────────────────────────────────────
    /** @return array<string,mixed> the provider row (created when absent) */
    public function ensureProvider(string $code, array $attributes = []): array;
    /** @param array<string,mixed> $patch */
    public function updateProvider(int $id, array $patch): void;
    /** @return array<int,array<string,mixed>> */
    public function listProviders(bool $enabledOnly = false): array;

    // ── competitions / teams ────────────────────────────────────────────────
    /** @return array<string,mixed> stored row */
    public function saveCompetition(int $providerId, array $row): array;
    /** @return array<string,mixed>|null */
    public function findCompetition(int $providerId, string $externalId, ?string $season = null): ?array;

    /**
     * Competition mapping: the internal competition id a provider's own league
     * id stands for, with the deployment's own classification (tier, premium,
     * active). A premium league is an application-level label — no provider
     * numbers competitions the same way, so the mapping is what makes the
     * premium league resolvable whichever feed answered.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed> stored row
     */
    public function saveCompetitionMapping(array $row): array;
    /** @return array<string,mixed>|null */
    public function findCompetitionMapping(string $providerCode, string $providerCompetitionId): ?array;
    /**
     * @param array<string,mixed> $filter keys: premium, active, internalId, providerCode
     * @return array<int,array<string,mixed>>
     */
    public function listCompetitionMappings(array $filter = [], int $limit = 500): array;

    /**
     * Record that a provider's own match id is this canonical match. One
     * internal match may carry a row per provider; the same match from a second
     * feed links to the identity it already has instead of becoming a second
     * fixture.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed> stored row
     */
    public function saveProviderMatch(array $row): array;
    /** @return array<string,mixed>|null */
    public function findProviderMatch(string $providerCode, string $providerMatchId): ?array;
    /** @return array<int,array<string,mixed>> every provider row behind one internal match */
    public function listProviderMatches(string $internalMatchId): array;
    /**
     * The provider rows behind a whole page of internal matches, in one read —
     * source attribution is part of every prediction result, and it must not
     * cost one query per match.
     *
     * @param list<string> $internalMatchIds
     * @return array<string,list<array<string,mixed>>> keyed by internal match id
     */
    public function listProviderMatchesFor(array $internalMatchIds): array;
    /**
     * The canonical match a provider row belongs to, searched by identity and
     * then by normalized teams + kickoff date. Returns null when nothing
     * matches — the caller then creates a new identity.
     *
     * @param array<string,mixed> $candidate keys: providerCode, providerMatchId, homeTeam, awayTeam, kickoff
     * @return array{row:array<string,mixed>, score:float, matchedBy:string}|null
     */
    public function resolveCanonicalMatch(array $candidate): ?array;
    /** @return array<string,mixed> */
    public function saveTeam(int $providerId, array $row): array;
    /** @return array<string,mixed>|null */
    public function findTeam(int $providerId, string $externalId): ?array;

    // ── fixtures ────────────────────────────────────────────────────────────
    /** Upsert keyed by (provider, provider fixture id). Never clears stored
     *  scores when the provider omits them — absent means absent. */
    /** @return array<string,mixed> */
    public function saveFixture(int $providerId, array $fixture): array;
    /** @return array<string,mixed>|null */
    public function findFixtureById(int $id): ?array;
    /** @return array<string,mixed>|null */
    public function findFixture(int $providerId, string $externalId): ?array;
    /**
     * Filter keys: date, from, to, status (string or list), competition, team,
     * providerId, unsettledOnly, competitionExternalId (one league),
     * competitionExternalIds (a group of leagues — the "all premium leagues"
     * selection; an empty list matches nothing). Rows are ordered by kickoff
     * then id, so a page boundary is stable: match 51 of a date is the same row
     * on every call.
     *
     * @return array<int,array<string,mixed>>
     */
    public function listFixtures(array $filter = [], int $limit = 500, int $offset = 0): array;

    /**
     * A successful live-provider response is the current live set for that
     * provider. Any previously in-play fixture from the same provider that is no
     * longer present is taken off the live board immediately, without deleting
     * the fixture or reusing its last live score as a final score.
     *
     * @param list<string> $activeExternalIds provider fixture ids still reported live
     * @return int number of fixture rows taken out of the live set
     */
    public function expireMissingLiveFixtures(int $providerId, array $activeExternalIds, string $observedAt): int;

    /**
     * How many fixtures a filter matches, without loading them. Pagination
     * needs the total to answer "Page 1 of 20" — it must never be guessed from
     * the size of one page.
     *
     * @param array<string,mixed> $filter
     */
    public function countFixtures(array $filter = []): int;
    public function markFixtureSettled(int $id, string $at): void;
    /** Point a stored fixture at its competition row without touching provider facts. */
    public function linkFixtureCompetition(int $fixtureId, int $competitionId): void;
    /**
     * Fixtures still waiting for a trustworthy final state: in play, or finished
     * without a stored score, or finished with a score but never settled.
     * @return array<int,array<string,mixed>>
     */
    public function listFixturesAwaitingResult(int $limit = 200, ?int $providerId = null): array;

    /**
     * The competitions the feed can be narrowed to, each with how many matches
     * it has. This is the dropdown's source: competitions the provider has
     * actually sent, never a hard-coded league list. `date` narrows the count to
     * one day; without it the count is everything stored.
     *
     * Filter keys: date, providerId.
     *
     * @param array<string,mixed> $filter
     * @return list<array<string,mixed>> rows of externalId, name, country, season, matches
     */
    public function listCompetitions(array $filter = [], int $limit = 200): array;

    // ── statistics ──────────────────────────────────────────────────────────
    /** @return array<string,mixed> */
    public function saveTeamStatistics(int $providerId, array $row): array;
    /** @return array<string,mixed>|null */
    public function findTeamStatistics(int $providerId, string $teamExternalId, ?string $competitionExternalId = null, ?string $season = null): ?array;
    /** @return array<int,array<string,mixed>> recent FINISHED fixtures of one team */
    public function listTeamRecentResults(int $providerId, string $teamExternalId, int $limit = 10): array;
    /** @return array<string,mixed> */
    public function saveFixtureStatistics(int $fixtureId, int $providerId, string $kind, array $payload, array $coverage = []): array;
    /** @return array<string,mixed>|null */
    public function findFixtureStatistics(int $fixtureId, ?string $kind = null): ?array;
    /** @return array<string,mixed> */
    public function saveHeadToHead(int $providerId, array $row): array;
    /** @return array<string,mixed>|null */
    public function findHeadToHead(int $providerId, string $homeTeamExternalId, string $awayTeamExternalId, ?string $competitionExternalId = null): ?array;

    // ── model + calibration registry ────────────────────────────────────────
    /** Upsert keyed by (model_name, model_version). status defaults to DRAFT —
     *  no code path may insert an APPROVED/ACTIVE model. */
    /** @return array<string,mixed> */
    public function saveModelVersion(array $row): array;
    /** @return array<string,mixed>|null */
    public function findModelVersion(int $id): ?array;
    /** @return array<string,mixed>|null */
    public function findModelVersionByName(string $modelName, string $modelVersion): ?array;
    /** @return array<int,array<string,mixed>> */
    public function listModelVersions(?string $status = null, int $limit = 50): array;
    /** @param array<string,mixed> $patch */
    public function updateModelVersion(int $id, array $patch): void;

    /** @return array<string,mixed> */
    public function saveCalibration(array $row): array;
    /** @return array<string,mixed>|null */
    public function findCalibration(int $id): ?array;
    /** @return array<int,array<string,mixed>> */
    public function listCalibrations(?int $modelVersionId = null, ?string $status = null, int $limit = 50): array;
    /** Exact count for model summaries; unlike listCalibrations(), this is never page-limited. */
    public function countCalibrations(?int $modelVersionId = null, ?string $status = null): int;
    /** @param array<string,mixed> $patch */
    public function updateCalibration(int $id, array $patch): void;

    // ── predictions ─────────────────────────────────────────────────────────
    /** @return array<string,mixed> */
    public function savePrediction(array $row): array;
    /** @return array<string,mixed>|null */
    public function findPrediction(string $id): ?array;
    /** Filter keys: fixtureId, date, from, to, kind, eligibility, modelVersionId,
     *  settlementState. @return array<int,array<string,mixed>> */
    public function listPredictions(array $filter = [], int $limit = 500, int $offset = 0): array;

    /**
     * How many stored predictions a filter matches. The board's date-wide
     * counts (analyzed / qualified / limited) are read from here rather than
     * counted in PHP over one page, so the summary describes the whole date
     * while the page shows 50 rows.
     *
     * @param array<string,mixed> $filter
     */
    public function countPredictions(array $filter = []): int;

    /**
     * The stored predictions for a specific set of fixtures — one query for a
     * whole page of matches instead of one per match.
     *
     * This is the "check match_id against the database" step: the caller hands
     * it the page's fixture ids and gets back only the predictions that already
     * exist, so the generation stage is handed the difference.
     *
     * @param list<int> $fixtureIds
     * @return array<int,array<string,mixed>> keyed by fixture id
     */
    public function listPredictionsForFixtures(array $fixtureIds, string $kind, ?int $modelVersionId = null): array;
    /** Replaces the score grid of a NOT-yet-settled prediction. */
    public function saveScoreProbabilities(string $predictionId, array $rows): void;
    /** @return array<int,array<string,mixed>> */
    public function listScoreProbabilities(string $predictionId, int $limit = 20): array;

    /**
     * The score grids of many predictions at once. Evaluating an odds market
     * for a page is 50 grids, and 50 queries per page is exactly the kind of
     * cost this module exists to avoid: one batched read keeps market
     * selection free of database amplification.
     *
     * @param list<string> $predictionIds
     * @return array<string,list<array{home:int,away:int,probability:float}>> keyed by prediction id
     */
    public function listScoreProbabilitiesFor(array $predictionIds, int $limitPerPrediction = 200): array;

    /**
     * The prices the connected odds feed has quoted, keyed by `matchId`
     * (`providerCode:externalId`) — the same identity a prediction is stored
     * under. A match with no quoted row is absent from the result, which the
     * caller reports as DATA_UNAVAILABLE rather than as a price of 0.
     *
     * @param list<string> $matchIds
     * @return array<string,list<array{market:string,selection:string,decimalOdds:float,observedAt:?string}>>
     */
    public function listMarketOdds(array $matchIds): array;

    // ── prediction revisions (the movement history) ───────────────────────────

    /**
     * Record one calculation of a fixture's prediction.
     *
     * A prediction row is unique per (fixture, kind, model version) and is
     * *replaced* when a stated reason justifies it, so the figure it carried
     * would otherwise disappear with the row. Movement can only be measured
     * against a record of what was said before, which is what this is for.
     *
     * Keyed by `prediction_id`: storing the same prediction twice — an idempotent
     * re-run of a sweep — returns the existing revision instead of adding another,
     * so a repeated job cannot manufacture a movement trail.
     *
     * @param array<string,mixed> $row
     * @return array{row:array<string,mixed>,created:bool}
     */
    public function savePredictionRevision(array $row): array;

    /**
     * The movement history of the given fixtures, newest first — one read for a
     * whole page, in the same shape as `listPredictionsForFixtures()`.
     *
     * @param list<int> $fixtureIds
     * @return array<int,list<array<string,mixed>>> keyed by fixture id
     */
    public function listPredictionRevisions(array $fixtureIds, string $kind, int $limitPerFixture = 5): array;

    /** How many revision rows are older than the retention window. */
    public function prunePredictionRevisions(int $olderThanDays = 90): int;

    // ── settlements + performance ───────────────────────────────────────────
    /** Insert-once keyed by prediction_id; a second call returns the existing
     *  row with created=false (idempotent settlement jobs). */
    /** @return array{row:array<string,mixed>, created:bool} */
    public function saveSettlement(array $row): array;
    /** @return array<string,mixed>|null */
    public function findSettlement(string $predictionId): ?array;
    /** Filter keys: modelVersionId, from, to. @return array<int,array<string,mixed>> */
    public function listSettlements(array $filter = [], int $limit = 2000): array;
    /** Aggregate counts computed in SQL over the settlement table (never in
     *  the UI layer). Keys include evaluated, correctResults, correctScores,
     *  averageConfidence, averageDataQuality, averageGoalError, brier, logLoss,
     *  plus metric-missing counters for legacy rows. */
    public function settlementAggregates(array $filter = []): array;
    /**
     * Settled predictions plus their frozen probabilities, with the prediction
     * row joined when it still exists for raw-probability calibration and legacy
     * metric repair.
     *
     * @param array{modelVersionId?:int,from?:string,to?:string,limit?:int,calibrationState?:string} $filter
     * @return list<array<string,mixed>>
     */
    public function listCalibrationSamples(array $filter = []): array;
    /** @return array<string,mixed> */
    public function savePerformanceSnapshot(array $row): array;
    /** @return array<string,mixed>|null */
    public function latestPerformanceSnapshot(int $windowDays, ?int $modelVersionId = null): ?array;

    // ── provider sync log (idempotency + quota bookkeeping) ─────────────────
    /** Returns null when the execution key was already used (duplicate job). */
    /** @return array<string,mixed>|null */
    public function startSyncRun(array $run): ?array;
    /** @param array<string,mixed> $result */
    public function finishSyncRun(string $executionKey, array $result): void;
    /** @return array<int,array<string,mixed>> newest first */
    public function listSyncRuns(?string $jobType = null, int $limit = 50): array;
    /** Remove operational sync-log history only; never prediction data. */
    public function pruneSyncLogs(int $olderThanDays = 120): int;
    /** Remove score rows whose prediction row no longer exists (orphans only). */
    public function pruneOrphanScoreRows(): int;
    /** @return array<string,mixed>|null */
    public function lastSyncRun(?string $jobType = null, ?int $providerId = null): ?array;
}
