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
    /** Filter keys: date, from, to, status, competition, team, providerId,
     *  unsettledOnly. @return array<int,array<string,mixed>> */
    public function listFixtures(array $filter = [], int $limit = 500): array;
    public function markFixtureSettled(int $id, string $at): void;
    /** Point a stored fixture at its competition row without touching provider facts. */
    public function linkFixtureCompetition(int $fixtureId, int $competitionId): void;
    /**
     * Fixtures still waiting for a trustworthy final state: in play, or finished
     * without a stored score, or finished with a score but never settled.
     * @return array<int,array<string,mixed>>
     */
    public function listFixturesAwaitingResult(int $limit = 200, ?int $providerId = null): array;

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
    /** @param array<string,mixed> $patch */
    public function updateCalibration(int $id, array $patch): void;

    // ── predictions ─────────────────────────────────────────────────────────
    /** @return array<string,mixed> */
    public function savePrediction(array $row): array;
    /** @return array<string,mixed>|null */
    public function findPrediction(string $id): ?array;
    /** Filter keys: fixtureId, date, from, to, kind, eligibility, modelVersionId,
     *  settlementState. @return array<int,array<string,mixed>> */
    public function listPredictions(array $filter = [], int $limit = 500): array;
    /** Replaces the score grid of a NOT-yet-settled prediction. */
    public function saveScoreProbabilities(string $predictionId, array $rows): void;
    /** @return array<int,array<string,mixed>> */
    public function listScoreProbabilities(string $predictionId, int $limit = 20): array;

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
     *  the UI layer). Keys: evaluated, correctResults, correctScores,
     *  avgConfidence, avgDataQuality, sumBrier, sumLogLoss. */
    public function settlementAggregates(array $filter = []): array;
    /**
     * Settled predictions joined to their stored probability row — the only
     * sample set calibration and performance measurement may use.
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
