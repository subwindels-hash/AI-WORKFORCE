<?php
namespace Aegis\LangLearn\Persistence;

/**
 * Language-learning persistence contract. Implemented by Aegis_model over
 * CI3's query builder (mysqli in production, pdo_sqlite in the dev runtime).
 */
interface LangLearnRepository
{
    public function upsertLanguage(array $row): void;
    /** @return array<int, array<string, mixed>> */
    public function listLanguages(bool $activeOnly = true): array;
    /** @return array<string, mixed>|null */
    public function findLanguage(string $code): ?array;

    /** Insert-or-update by id; returns the stored record with its id. */
    public function saveProfile(array $profile): array;
    public function findProfile(int $id): ?array;
    public function findProfileByUserLanguage(int $userId, string $code): ?array;
    /** @return array<int, array<string, mixed>> */
    public function listProfilesByUser(int $userId): array;

    /** Insert-or-update by id. state/result accept arrays (encoded as JSON). */
    public function saveAssessment(array $assessment): array;
    public function findAssessment(string $id): ?array;
    public function latestCompletedAssessment(int $profileId): ?array;

    public function savePath(array $path): array;
    public function activePath(int $profileId): ?array;

    public function saveModule(array $module): array;
    public function findModule(string $id): ?array;
    /** @return array<int, array<string, mixed>> ordered by sequence */
    public function listModules(string $pathId): array;

    public function saveAttempt(array $attempt): array;
    public function saveSession(array $session): void;
    /** @return array<int, string> distinct UTC study days, newest first */
    public function sessionDays(int $profileId): array;

    /** Upsert keyed on (profile_id, skill, source). */
    public function upsertProgress(array $row): void;
    /** @return array<int, array<string, mixed>> */
    public function listProgress(int $profileId): array;
}
