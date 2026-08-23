<?php
namespace Aegis\Persistence;

/**
 * Thin repository interfaces over CI3's database layer. The concrete
 * implementation (Aegis_model) speaks MySQL/MariaDB in production and
 * SQLite for the offline dev runtime — identical SQL subset either way.
 */
interface StrategyRepository
{
    /** @return array<string, mixed>|null */
    public function find(string $id, string $version): ?array;
    /** @return array<int, array<string, mixed>> */
    public function all(): array;
    public function save(array $record): void;
    public function countBacktests(string $strategyId, string $version): int;
    /** @return array<string, mixed>|null */
    public function latestBacktest(string $strategyId, string $version): ?array;
}

interface BacktestRepository
{
    public function save(array $record): void;
    /** @return array<string, mixed>|null */
    public function find(string $id): ?array;
    /** @return array<int, array<string, mixed>> */
    public function list(?string $strategyId = null, int $limit = 50): array;
}

interface JournalRepository
{
    public function save(array $entry): void;
    /** @return array<int, array<string, mixed>> */
    public function list(array $filter = [], int $limit = 200): array;
}

interface AuditRepository
{
    /** Emit an audit event (type, summary, detail array). */
    public function emit(string $type, string $summary, array $detail = [], string $actor = 'system'): void;
    /** @return array<int, array<string, mixed>> */
    public function recent(int $limit = 100): array;
}

interface AnalysisRepository
{
    public function save(array $run): void;
    /** @return array<int, array<string, mixed>> summaries, newest first */
    public function history(int $limit = 20): array;
    /** @return array<string, mixed>|null */
    public function find(string $id): ?array;
}

interface PlatformStateRepository
{
    /** @return array<string, mixed> {tradingMode, killSwitch: {active, activatedAt, reason}} */
    public function load(): array;
    public function save(array $state): void;
}

/** Identity and access-control persistence. Password hashes only; never raw secrets. */
interface IdentityRepository
{
    public function findUserByEmail(string $email): ?array;
    public function findUserById(int $id): ?array;
    public function createUser(array $user): array;
    /** @return array<int,string> */
    public function permissionsForUser(int $userId): array;
    public function recordAuthEvent(int $userId, string $type, array $detail = []): void;
}

interface PaperRepository
{
    /** Saves and RETURNS the record with its generated id. */
    public function saveAccount(array $account): array;
    public function findAccount(int $id): ?array;
    /** @return array<int, array<string, mixed>> */
    public function listAccounts(): array;
    /** Saves and RETURNS the record with its generated id. */
    public function saveOrder(array $order): array;
    /** @return array<int, array<string, mixed>> */
    public function listOrders(int $accountId, ?string $status = null): array;
    public function findOpenOrder(int $accountId, string $symbol): ?array;
    /** Saves and RETURNS the record with its generated id. */
    public function savePosition(array $position): array;
    public function findPosition(int $id): ?array;
    public function findOpenPosition(int $accountId, string $symbol): ?array;
    /** @return array<int, array<string, mixed>> */
    public function listOpenPositions(int $accountId): array;
    public function saveTrade(array $trade): void;
    /** @return array<int, array<string, mixed>> */
    public function listTrades(int $accountId, int $limit = 100): array;
    /** Saves and RETURNS the record with its generated id. */
    public function saveDeployment(array $deployment): array;
    public function findDeployment(int $id): ?array;
    /** @return array<int, array<string, mixed>> */
    public function listDeployments(?int $accountId = null, ?bool $active = null): array;
}
