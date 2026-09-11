<?php
/**
 * Automatic daily odds-prediction generation — the "nothing ever generated"
 * lock-out.
 *
 * Every piece of the chain existed and was individually correct:
 *
 *   CronScheduler::JOBS['sports']  defaultEnabled = true, every 15 minutes
 *   CronRunner::sports()           → SportsCronService::runAll()
 *   SportsCronService::jobTicket() → DailyTicketService::runDaily()
 *
 * …and yet a fresh deployment produced no ticket, because jobs only execute
 * when something TRIGGERS the runner. With no hosting cron configured, that
 * something is the dashboard auto-trigger — and it read an UNSET
 * `cron.auto_run` setting as "off". So the shipped state was: every job
 * enabled, every job due, and nothing ever running. Generation only happened
 * when an operator pressed Run-now by hand, which is the opposite of
 * automatic.
 *
 * The fix is a real default (CronAutoRun::DEFAULT_ENABLED) that matches the
 * jobs' own defaultEnabled, applied through ONE accessor so the dashboard
 * checkbox, the save handler and the trigger can never disagree.
 *
 * Pinned here: the default is on, an operator's explicit off still wins, the
 * trigger stays throttled and due-gated (defaulting it on must not turn it
 * into a hot loop), and the sports→ticket chain is really wired.
 */
use AIWorkforce\Cron\CronAutoRun;
use AIWorkforce\Cron\CronScheduler;
use AIWorkforce\Cron\CronStateStore;

function ci151_store(array $seed = []): CronStateStore
{
    return new class($seed) implements CronStateStore {
        public function __construct(private array $d) {}
        public function get(string $key): ?string { return $this->d[$key] ?? null; }
        public function set(string $key, string $value): void { $this->d[$key] = $value; }
    };
}

test('auto-run is ON by default so a fresh install generates without being asked', function () {
    $store = ci151_store();
    assert_null($store->get(CronAutoRun::ENABLED_KEY), 'the setting is genuinely unset on a fresh install');
    assert_true(CronAutoRun::isEnabled($store), 'an unset auto-run must not read as OFF');
    assert_true(CronAutoRun::DEFAULT_ENABLED, 'the documented default matches the jobs defaultEnabled');

    // An empty string (a store that writes '' for "no value") is also unset.
    assert_true(CronAutoRun::isEnabled(ci151_store([CronAutoRun::ENABLED_KEY => ''])), 'blank is treated as unset');
});

test('an operator who turns auto-run OFF is still obeyed', function () {
    $store = ci151_store([CronAutoRun::ENABLED_KEY => '0']);
    assert_false(CronAutoRun::isEnabled($store), 'an explicit opt-out always wins over the default');

    $scheduler = new CronScheduler($store, fn() => 1_000_000);
    $hits = [];
    $result = CronAutoRun::maybeTrigger($scheduler, $store, 'https://x/cron/run?key=k',
        function (string $u) use (&$hits) { $hits[] = $u; return true; }, 1_000_000, 'apache2handler');
    assert_equals('disabled', $result, 'the trigger respects the opt-out');
    assert_equals(0, count($hits), 'nothing is dispatched when an operator disabled it');

    // …and switching it back on works.
    $store->set(CronAutoRun::ENABLED_KEY, '1');
    assert_true(CronAutoRun::isEnabled($store));
});

test('a fresh install actually fires the trigger — the whole point of the fix', function () {
    $store = ci151_store();
    $scheduler = new CronScheduler($store, fn() => 1_000_000);
    $hits = [];
    $dispatch = function (string $u) use (&$hits) { $hits[] = $u; return true; };

    $result = CronAutoRun::maybeTrigger($scheduler, $store, 'https://x/cron/run?key=k', $dispatch, 1_000_000, 'apache2handler');
    assert_equals('triggered', $result, 'an untouched deployment triggers due jobs by itself');
    assert_equals(['https://x/cron/run?key=k'], $hits, 'the secret runner URL is what gets called');
});

test('defaulting auto-run ON does not turn it into a hot loop', function () {
    $store = ci151_store();
    $now = 2_000_000;
    $scheduler = new CronScheduler($store, fn() => $now);
    $hits = [];
    $dispatch = function (string $u) use (&$hits) { $hits[] = $u; return true; };

    assert_equals('triggered', CronAutoRun::maybeTrigger($scheduler, $store, 'https://x/c?key=k', $dispatch, $now, 'apache2handler'));
    // Immediately again: the throttle still holds.
    assert_equals('throttled', CronAutoRun::maybeTrigger($scheduler, $store, 'https://x/c?key=k', $dispatch, $now + 1, 'apache2handler'));
    assert_equals(1, count($hits), 'one dispatch per throttle window, default or not');

    // Past the throttle but with everything freshly run → nothing due.
    $later = $now + CronAutoRun::THROTTLE_SECONDS + 1;
    $scheduler2 = new CronScheduler($store, fn() => $later);
    $scheduler2->runDue(fn(string $id) => fn() => []);
    assert_equals('not_due', CronAutoRun::maybeTrigger($scheduler2, $store, 'https://x/c?key=k', $dispatch, $later, 'apache2handler'));
    assert_equals(1, count($hits), 'a default-on trigger still only fires when work is due');

    // CLI is never the dashboard trigger.
    assert_equals('cli', CronAutoRun::maybeTrigger($scheduler, ci151_store(), 'https://x/c?key=k', $dispatch, $now, 'cli'));
});

test('the sports sweep that generates the daily ticket is enabled and scheduled', function () {
    $store = ci151_store();
    $scheduler = new CronScheduler($store, fn() => 3_000_000);

    $sports = $scheduler->definition('sports');
    assert_true((bool) $sports['defaultEnabled'], 'the sports sweep ships enabled');
    assert_true($scheduler->isEnabled('sports'), 'and reads as enabled on a fresh store');
    assert_true((int) $sports['interval'] > 0 && (int) $sports['interval'] <= 3600,
        'the sweep runs at least hourly so a day cannot pass unattempted');

    // It is due immediately on a fresh install (never run yet).
    $status = [];
    foreach ($scheduler->status() as $job) $status[$job['id']] = $job;
    assert_true(!empty($status['sports']['enabled']), 'sports is enabled in the status the dashboard renders');
    assert_true(!empty($status['sports']['due']), 'sports is due on a fresh install');
});

test('the automatic chain is really wired: sweep → ticket job → runDaily', function () {
    // A runner must exist for the scheduler id, or an enabled+due job would
    // silently never execute.
    $runner = file_get_contents(APPPATH . 'libraries/AIWorkforce/Cron/CronRunner.php');
    assert_true((bool) preg_match("/'sports'\s*=>/", $runner), 'the scheduler id has a runner');
    assert_true(str_contains($runner, 'SportsCronService'), 'the sports runner drives the sports cron service');

    // The sweep must include the ticket job, and it must reach runDaily.
    assert_true(in_array('ticket', \AIWorkforce\Sports\SportsCronService::JOBS, true),
        'the daily ticket is part of the sweep, not a manual-only action');
    $service = file_get_contents(APPPATH . 'libraries/AIWorkforce/Sports/SportsCronService.php');
    assert_true((bool) preg_match('/jobTicket.*?dailyTickets->runDaily/s', $service),
        'the ticket job calls the real generation entry point');

    // Scheduled runs must be marked as such so backoff applies to them
    // (a manual retry is allowed to bypass it; an automatic one is not).
    assert_true((bool) preg_match("/\\\$options\['scheduled'\]\s*=\s*\\\$options\['scheduled'\]\s*\?\?\s*true;/", $service),
        'runAll marks its runs scheduled so retry backoff is honoured');
});

test('the dashboard checkbox and the trigger read the SAME default', function () {
    // The bug class this prevents: the view rendering "off" while the
    // trigger believes "on" (or vice versa) because two places re-implement
    // the default independently.
    $admin = file_get_contents(APPPATH . 'controllers/Admin.php');
    assert_true(str_contains($admin, 'CronAutoRun::isEnabled('),
        'the dashboard reads the shared accessor, not a raw === comparison');
    assert_false((bool) preg_match("/autoRun.*?get\('cron\.auto_run'\)\s*===\s*'1'/", $admin),
        'no re-implementation of the default in the controller');
    assert_true(str_contains($admin, 'CronAutoRun::ENABLED_KEY'),
        'the save handler writes the canonical key');
});
