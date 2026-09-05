<?php
/**
 * Tests for the central cron scheduler (registry, due/enabled logic, locks,
 * failure isolation), the URL secret, the dashboard auto-trigger, and the
 * admin wiring (routes, controller, sidebar).
 */
use AIWorkforce\Cron\CronAutoRun;
use AIWorkforce\Cron\CronScheduler;
use AIWorkforce\Cron\CronSecrets;
use AIWorkforce\Cron\CronStateStore;

class Fx97MemoryStore implements CronStateStore
{
    public array $data = [];
    public function get(string $key): ?string { return $this->data[$key] ?? null; }
    public function set(string $key, string $value): void { $this->data[$key] = $value; }
}

function fx97_scheduler(?int &$now = null): array
{
    $store = new Fx97MemoryStore();
    $now = 1_800_000_000;
    $clock = function () use (&$now) { return $now; };
    return [new CronScheduler($store, $clock), $store];
}

test('cron scheduler rejects unknown jobs', function () {
    [$scheduler] = fx97_scheduler($now);
    assert_throws(\InvalidArgumentException::class, fn() => $scheduler->definition('nope'));
    assert_throws(\InvalidArgumentException::class, fn() => $scheduler->runJob('nope', fn() => []));
    assert_throws(\InvalidArgumentException::class, fn() => $scheduler->setEnabled('nope', true));
});

test('cron jobs are enabled by default and due when never run', function () {
    [$scheduler] = fx97_scheduler($now);
    foreach (array_keys(CronScheduler::JOBS) as $id) {
        assert_true($scheduler->isEnabled($id), $id . ' enabled by default');
        assert_true($scheduler->isDue($id), $id . ' due when never run');
    }
});

test('cron runDue runs enabled + due jobs and records last runs', function () {
    [$scheduler, $store] = fx97_scheduler($now);
    $ran = [];
    $result = $scheduler->runDue(function (string $id) use (&$ran) {
        return function () use ($id, &$ran) { $ran[] = $id; return ['summary' => $id . ' ok']; };
    });
    assert_equals(['ops', 'sports', 'lottery'], $ran);
    foreach (['ops', 'sports', 'lottery'] as $id) {
        assert_true($result[$id]['ran']);
        assert_equals('COMPLETED', $result[$id]['status']);
        $last = $scheduler->lastRun($id);
        assert_equals('COMPLETED', $last['status']);
        assert_equals($id . ' ok', $last['summary']);
        assert_false($scheduler->isDue($id), $id . ' not due right after a run');
    }
    // Second sweep immediately after: everything skipped, nothing re-ran.
    $ran = [];
    $result = $scheduler->runDue(fn(string $id) => function () use ($id, &$ran) { $ran[] = $id; return []; });
    assert_equals([], $ran);
    assert_equals('SKIPPED_NOT_DUE', $result['ops']['status']);
});

test('cron runDue skips disabled jobs and honors per-job cadence', function () {
    [$scheduler, $store] = fx97_scheduler($now);
    $scheduler->setEnabled('sports', false);
    $scheduler->runDue(fn(string $id) => fn() => []);
    assert_equals('SKIPPED_DISABLED', $scheduler->runDue(fn(string $id) => fn() => [])['sports']['status'] ?? null);
    assert_null($scheduler->lastRun('sports'), 'disabled job never runs');
    // ops (5 min) is due again after 5 minutes; sports stays parked.
    $now += 300;
    $scheduler->setEnabled('sports', true);
    $ran = [];
    $result = $scheduler->runDue(function (string $id) use (&$ran) {
        return function () use ($id, &$ran) { $ran[] = $id; return []; };
    });
    assert_in_array('ops', $ran);
    assert_in_array('sports', $ran, 're-enabled job runs because it never ran');
    assert_true(!in_array('lottery', $ran), 'lottery (6h) still not due');
    assert_equals('SKIPPED_NOT_DUE', $result['lottery']['status']);
});

test('cron runDue isolates failures and marks partial sweeps honestly', function () {
    [$scheduler] = fx97_scheduler($now);
    $ran = [];
    $result = $scheduler->runDue(function (string $id) use (&$ran) {
        return function () use ($id, &$ran) {
            $ran[] = $id;
            if ($id === 'sports') throw new \RuntimeException('provider down');
            return [];
        };
    });
    assert_equals(['ops', 'sports', 'lottery'], $ran, 'one failure does not abort the sweep');
    assert_equals('FAILED', $result['sports']['status']);
    assert_contains('provider down', $result['sports']['error']);
    assert_equals('COMPLETED', $result['ops']['status']);
    // Sub-job style results surface partial failure in the recorded status.
    $mixed = $scheduler->runJob('ops', fn() => ['a' => ['status' => 'COMPLETED'], 'b' => ['status' => 'FAILED']]);
    assert_equals('COMPLETED_WITH_ERRORS', $mixed['status']);
});

test('cron locks prevent overlapping runs but stale locks expire', function () {
    [$scheduler, $store] = fx97_scheduler($now);
    $store->set('cron.lock.ops', (string) ($now + 60));
    $ran = 0;
    $result = $scheduler->runJob('ops', function () use (&$ran) { $ran++; return []; });
    assert_false($result['ran']);
    assert_equals('SKIPPED_LOCKED', $result['status']);
    assert_equals(0, $ran);
    // A lock past its TTL is treated as crashed and ignored.
    $store->set('cron.lock.ops', (string) ($now - 1));
    $result = $scheduler->runJob('ops', function () use (&$ran) { $ran++; return []; });
    assert_true($result['ran']);
    assert_equals(1, $ran);
    assert_equals('0', $store->get('cron.lock.ops'), 'lock released after the run');
});

test('cron secrets are stable, checkable and regenerable', function () {
    [$scheduler, $store] = fx97_scheduler($now);
    $first = CronSecrets::ensure($store);
    assert_equals(64, strlen($first));
    assert_equals($first, CronSecrets::ensure($store), 'ensure is stable');
    assert_true(CronSecrets::check($store, $first));
    assert_false(CronSecrets::check($store, 'wrong'));
    assert_false(CronSecrets::check($store, ''));
    $second = CronSecrets::regenerate($store);
    assert_false($second === $first, 'regeneration rotates the secret');
    assert_false(CronSecrets::check($store, $first), 'old secret stops working');
    assert_true(CronSecrets::check($store, $second));
});

test('cron auto-trigger matrix: gating, throttle, due check, dispatch', function () {
    [$scheduler, $store] = fx97_scheduler($now);
    $hits = [];
    $dispatch = function (string $url) use (&$hits) { $hits[] = $url; return true; };
    assert_equals('cli', CronAutoRun::maybeTrigger($scheduler, $store, 'https://x/cron/run?key=k', $dispatch, $now, 'cli'));
    assert_equals('disabled', CronAutoRun::maybeTrigger($scheduler, $store, 'https://x/cron/run?key=k', $dispatch, $now, 'apache2handler'));
    $store->set('cron.auto_run', '1');
    assert_equals('triggered', CronAutoRun::maybeTrigger($scheduler, $store, 'https://x/cron/run?key=k', $dispatch, $now, 'apache2handler'));
    assert_equals(['https://x/cron/run?key=k'], $hits);
    assert_equals('throttled', CronAutoRun::maybeTrigger($scheduler, $store, 'https://x/cron/run?key=k', $dispatch, $now, 'apache2handler'));
    // Past the throttle window but with nothing due → quiet.
    $later = $now + CronAutoRun::THROTTLE_SECONDS + 1;
    $scheduler->runDue(fn(string $id) => fn() => []);
    $scheduler2 = new CronScheduler($store, fn() => $later);
    assert_equals('not_due', CronAutoRun::maybeTrigger($scheduler2, $store, 'https://x/cron/run?key=k', $dispatch, $later, 'apache2handler'));
    assert_equals(1, count($hits), 'no dispatch when nothing is due');
    // A failing dispatch is reported, not thrown.
    $store->set('cron.last_trigger', '0');
    $muchLater = $later + 901;
    $scheduler3 = new CronScheduler($store, fn() => $muchLater);
    assert_equals('dispatch_failed', CronAutoRun::maybeTrigger($scheduler3, $store, 'https://x/cron/run?key=k', fn(string $u) => false, $muchLater, 'apache2handler'));
});

test('cron admin wiring: routes, controller, runner and sidebar', function () {
    $routes = file_get_contents(FCPATH . 'application/config/routes.php');
    foreach (["admin/cron'] = 'admin/cron'", "admin/cron/save'] = 'admin/cron_save'", "admin/cron/run/(:any)'] = 'admin/cron_run/\$1'", "admin/cron/secret'] = 'admin/cron_secret'", "cron/run'] = 'cron/run'", "tools/scheduler'] = 'tools/scheduler'"] as $r) {
        assert_contains($r, $routes);
    }
    $admin = file_get_contents(FCPATH . 'application/controllers/Admin.php');
    foreach (['public function cron()', 'public function cron_save()', 'public function cron_run(', 'public function cron_secret()', 'CronAutoRun::maybeTrigger'] as $m) {
        assert_contains($m, $admin);
    }
    assert_contains("gate('admin.settings.manage')", $admin);
    $cron = file_get_contents(FCPATH . 'application/controllers/Cron.php');
    assert_contains('CronSecrets::check', $cron);
    assert_contains('runDue', $cron);
    $tools = file_get_contents(FCPATH . 'application/controllers/Tools.php');
    assert_contains('public function scheduler()', $tools);
    assert_contains('CronRunner::ops($this)', $tools);
    $sidebar = file_get_contents(FCPATH . 'application/views/admin/layout/header.php');
    assert_contains('/admin/cron', $sidebar);
    assert_contains('Cron Jobs', $sidebar);
    $view = file_get_contents(FCPATH . 'application/views/admin/cron.php');
    foreach (['/admin/cron/save', '/admin/cron/run/', '/admin/cron/secret', 'curl -fsS', 'cliCommand'] as $m) {
        assert_contains($m, $view);
    }
    assert_contains('tools scheduler', $admin, 'controller builds the CLI cron snippet');
});
