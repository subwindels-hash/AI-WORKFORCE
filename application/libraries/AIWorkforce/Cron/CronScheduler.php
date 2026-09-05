<?php
namespace AIWorkforce\Cron;

/**
 * Central cron registry: every scheduled job in one place with its cadence,
 * enable flag and last-run record. Driven by (in order of reliability):
 *
 *   1. hosting cron hitting GET /cron/run?key=SECRET every minute, or
 *      `php index.php tools scheduler` every minute from system cron;
 *   2. the super-admin dashboard auto-trigger (best-effort fallback);
 *   3. the Run-now buttons on /admin/cron.
 *
 * Jobs only run when enabled AND due, so hitting the runner every minute is
 * cheap and safe; a per-job lock prevents overlapping runs.
 */
interface CronStateStore
{
    public function get(string $key): ?string;
    public function set(string $key, string $value): void;
}

class CronScheduler
{
    public const JOBS = [
        'ops' => [
            'label' => 'Platform operations',
            'group' => 'Platform',
            'interval' => 300,
            'schedule' => 'Every 5 minutes',
            'description' => 'Portfolio risk scan, broker READY/DOWN transitions, operator notifications and stale proposal expiry.',
            'defaultEnabled' => true,
        ],
        'sports' => [
            'label' => 'Sports sweep',
            'group' => 'Sports Intelligence',
            'interval' => 900,
            'schedule' => 'Every 15 minutes',
            'description' => 'Fixtures, odds, results, quality, daily ticket, settlement, performance, monitoring and cleanup.',
            'defaultEnabled' => true,
        ],
        'lottery' => [
            'label' => 'Lottery sweep',
            'group' => 'Lottery Intelligence',
            'interval' => 21600,
            'schedule' => 'Every 6 hours',
            'description' => 'Draw sync, health, statistics, system builds, ticket checks, backtests and cleanup.',
            'defaultEnabled' => true,
        ],
    ];

    /** Stale locks older than this are treated as crashed and ignored. */
    public const LOCK_TTL = 900;

    /** @var callable|null returns int unix time (injectable for tests) */
    private $clock;

    public function __construct(private CronStateStore $store, ?callable $clock = null)
    {
        $this->clock = $clock;
    }

    public function now(): int
    {
        return (int) ($this->clock ? call_user_func($this->clock) : time());
    }

    /** @return array{id:string,label:string,group:string,interval:int,schedule:string,description:string,defaultEnabled:bool} */
    public function definition(string $id): array
    {
        if (!isset(self::JOBS[$id])) throw new \InvalidArgumentException('unknown cron job: ' . $id);
        return array_merge(['id' => $id], self::JOBS[$id]);
    }

    public function isEnabled(string $id): bool
    {
        $def = $this->definition($id);
        $raw = $this->store->get('cron.enabled.' . $id);
        if ($raw === null) return (bool) $def['defaultEnabled'];
        return $raw === '1';
    }

    public function setEnabled(string $id, bool $enabled): void
    {
        $this->definition($id);
        $this->store->set('cron.enabled.' . $id, $enabled ? '1' : '0');
    }

    /** @return ?array{at:string,status:string,durationMs:int,summary:string,error:string} */
    public function lastRun(string $id): ?array
    {
        $this->definition($id);
        $raw = $this->store->get('cron.last_run.' . $id);
        if ($raw === null || $raw === '') return null;
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function isDue(string $id): bool
    {
        $def = $this->definition($id);
        $last = $this->lastRun($id);
        if ($last === null || empty($last['at'])) return true;
        $at = strtotime((string) $last['at']);
        if ($at === false) return true;
        return $this->now() - $at >= (int) $def['interval'];
    }

    /** Full dashboard state for every registered job. */
    public function status(): array
    {
        $now = $this->now();
        $out = [];
        foreach (self::JOBS as $id => $def) {
            $last = $this->lastRun($id);
            $at = ($last !== null && !empty($last['at'])) ? strtotime((string) $last['at']) : false;
            $due = $at === false ? true : ($now - $at >= (int) $def['interval']);
            $out[$id] = array_merge(['id' => $id], $def, [
                'enabled' => $this->isEnabled($id),
                'lastRun' => $last,
                'due' => $due,
                'nextDueAt' => $at === false ? gmdate('c', $now) : gmdate('c', $at + (int) $def['interval']),
            ]);
        }
        return $out;
    }

    /**
     * Run one job now (lock-guarded, result recorded). The runner returns an
     * arbitrary summary array; throwing marks the run FAILED.
     */
    public function runJob(string $id, callable $runner): array
    {
        $this->definition($id);
        $now = $this->now();
        if (!$this->acquireLock($id, $now)) {
            return ['job' => $id, 'ran' => false, 'status' => 'SKIPPED_LOCKED', 'reason' => 'another run is in progress'];
        }
        $start = microtime(true);
        try {
            $result = $runner();
            $record = [
                'at' => gmdate('c', $now),
                'status' => $this->deriveStatus($result),
                'durationMs' => (int) ((microtime(true) - $start) * 1000),
                'summary' => mb_substr($this->summarize($result), 0, 500),
                'error' => '',
            ];
        } catch (\Throwable $e) {
            $record = [
                'at' => gmdate('c', $now),
                'status' => 'FAILED',
                'durationMs' => (int) ((microtime(true) - $start) * 1000),
                'summary' => '',
                'error' => mb_substr($e->getMessage(), 0, 500),
            ];
        }
        $this->releaseLock($id);
        $this->store->set('cron.last_run.' . $id, json_encode($record));
        return array_merge(['job' => $id, 'ran' => true], $record);
    }

    /**
     * Run every enabled + due job. One failing job never aborts the sweep.
     * @param callable(string):mixed $resolve maps a job id to its runner
     */
    public function runDue(callable $resolve): array
    {
        $out = [];
        foreach (self::JOBS as $id => $def) {
            if (!$this->isEnabled($id)) {
                $out[$id] = ['job' => $id, 'ran' => false, 'status' => 'SKIPPED_DISABLED'];
                continue;
            }
            if (!$this->isDue($id)) {
                $out[$id] = ['job' => $id, 'ran' => false, 'status' => 'SKIPPED_NOT_DUE'];
                continue;
            }
            try {
                $runner = $resolve($id);
            } catch (\Throwable $e) {
                $runner = self::failingRunner($e);
            }
            if (!is_callable($runner)) {
                $runner = self::failingRunner(new \RuntimeException('no runner registered for cron job: ' . $id));
            }
            $out[$id] = $this->runJob($id, $runner);
        }
        return $out;
    }

    private static function failingRunner(\Throwable $e): callable
    {
        return function () use ($e) { throw $e; };
    }

    private function acquireLock(string $id, int $now): bool
    {
        $raw = $this->store->get('cron.lock.' . $id);
        if ($raw !== null && $raw !== '' && (int) $raw > $now) return false;
        $this->store->set('cron.lock.' . $id, (string) ($now + self::LOCK_TTL));
        return true;
    }

    private function releaseLock(string $id): void
    {
        $this->store->set('cron.lock.' . $id, '0');
    }

    private function deriveStatus(mixed $result): string
    {
        if (is_array($result) && isset($result['status']) && is_string($result['status']) && $result['status'] !== '') {
            return $result['status'];
        }
        if (is_array($result)) {
            $saw = false; $failed = 0;
            foreach ($result as $sub) {
                if (is_array($sub) && isset($sub['status'])) {
                    $saw = true;
                    if ($sub['status'] === 'FAILED') $failed++;
                }
            }
            if ($saw) return $failed > 0 ? 'COMPLETED_WITH_ERRORS' : 'COMPLETED';
        }
        return 'COMPLETED';
    }

    private function summarize(mixed $result): string
    {
        if (is_array($result) && isset($result['summary']) && is_string($result['summary'])) return $result['summary'];
        if (is_string($result)) return $result;
        $json = json_encode($result);
        return is_string($json) ? $json : 'ok';
    }
}

/** CronStateStore backed by the platform_settings table (category 'cron'). */
class PlatformSettingsCronStore implements CronStateStore
{
    public function __construct(private object $db) {}

    public function get(string $key): ?string
    {
        $row = $this->db->get_where('platform_settings', ['k' => $key], 1)->row_array();
        return $row ? (string) $row['v'] : null;
    }

    public function set(string $key, string $value): void
    {
        $row = ['k' => $key, 'v' => $value, 'category' => 'cron', 'updated_at' => gmdate('c'), 'updated_by' => null];
        if ($this->get($key) === null) $this->db->insert('platform_settings', $row);
        else $this->db->where('k', $key)->update('platform_settings', $row);
    }
}

/** Secret token gating the public /cron/run URL. */
class CronSecrets
{
    public const KEY = 'cron.secret';

    public static function ensure(CronStateStore $store): string
    {
        $raw = (string) ($store->get(self::KEY) ?? '');
        if (preg_match('/^[0-9a-f]{32,128}$/', $raw)) return $raw;
        return self::regenerate($store);
    }

    public static function regenerate(CronStateStore $store): string
    {
        $secret = bin2hex(random_bytes(32));
        $store->set(self::KEY, $secret);
        return $secret;
    }

    public static function check(CronStateStore $store, string $given): bool
    {
        $raw = (string) ($store->get(self::KEY) ?? '');
        if ($given === '' || $raw === '') return false;
        return hash_equals($raw, $given);
    }
}
