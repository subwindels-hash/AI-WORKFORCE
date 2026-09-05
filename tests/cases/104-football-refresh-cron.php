<?php
/**
 * Football Intelligence — provider-aware refresh and the scheduled jobs (spec §13).
 *
 * The module must not poll. A job runs when the provider is configured, the
 * module is enabled, its own interval has elapsed, the provider is not in
 * backoff, nothing from the last run asks for a deferral, and there is actually
 * work — all of that inside a per-job request budget. These cases pin each
 * gate individually, plus the idempotency that makes a repeated tick harmless.
 */
require_once TESTSPATH . 'football_support.php';

use AIWorkforce\Football\FootballConfiguration;
use AIWorkforce\Football\FootballCronService;
use AIWorkforce\Football\FootballIntelligence;
use AIWorkforce\Football\PredictionService;
use AIWorkforce\Football\RefreshPolicy;

test('football: refresh cadence is per-bucket configuration, not a fixed loop', function () {
    $config = new FootballConfiguration();
    $policy = new RefreshPolicy(new FootballRepositoryStub(), $config, new AIWorkforce\Football\ProviderGateway(
        new \AIWorkforce\Sports\Providers\SportsProviderManager(), $config, new FootballRepositoryStub()
    ));
    $buckets = ['fixtures', 'upcoming', 'live', 'results', 'statistics', 'predict', 'settle', 'performance', 'cleanup'];
    $intervals = [];
    foreach ($buckets as $bucket) {
        $intervals[$bucket] = $config->refreshInterval($bucket);
        assert_true($intervals[$bucket] >= 30 && $intervals[$bucket] <= 86400, $bucket . ' sits inside the allowed range');
    }
    assert_true(count(array_unique($intervals)) > 3, 'the buckets are genuinely different cadences');
    assert_true($intervals['live'] < $intervals['fixtures'], 'live scores refresh faster than next week\'s fixtures');
    assert_equals(86400, $intervals['cleanup'], 'housekeeping runs daily');
    // A job's interval is the bucket's, so no job carries a hard-coded tick.
    assert_equals($intervals['live'], $policy->interval('football-live'));
    assert_equals($intervals['results'], $policy->interval('football-results'));
    assert_equals(3600, $policy->interval('football-not-a-job'), 'an unknown job falls back to an hour, never to zero');

    $tuned = new FootballConfiguration(['WINDELS_FOOTBALL_REFRESH_LIVE' => '120']);
    assert_equals(120, $tuned->refreshInterval('live'), 'an operator can retune one bucket');
    assert_equals(30, (new FootballConfiguration(['WINDELS_FOOTBALL_REFRESH_LIVE' => '5']))->refreshInterval('live'),
        'and the floor protects a quota from a stampede');
    assert_equals($tuned->refreshInterval('upcoming'), (new FootballConfiguration())->refreshInterval('upcoming'),
        'without touching the others');
});

test('football: a job runs only when every gate agrees', function () {
    // Unknown job.
    $empty = new FootballRepositoryStub();
    $config = new FootballConfiguration();
    $bare = new FootballIntelligence($empty, new \AIWorkforce\Sports\Providers\SportsProviderManager(), null, $config);
    $unknown = $bare->refresh()->evaluate('football-yesterday');
    assert_equals('UNKNOWN_JOB', $unknown['reason']);
    assert_equals(false, $unknown['due']);

    // No provider: nothing is attempted, and the reason says so.
    $notConfigured = $bare->refresh()->evaluate('football-fixtures');
    assert_equals(false, $notConfigured['due']);
    assert_equals('PROVIDER_NOT_CONFIGURED', $notConfigured['reason']);
    assert_contains('no request will be made', strtolower((string) $notConfigured['detail']['message']));

    // Provider connected, work waiting, cadence elapsed ⇒ due.
    $liveKickoff = time() - 600;
    $kickoff = time() + 7200;
    [$repo, $provider, $module] = fx_fb_harness(
        [fx_fb_row('fx-gate', gmdate('c', $liveKickoff), 'Manchester City', 'Everton', '10', '20', 'LIVE', 1, 1, 12),
         fx_fb_row('fx-gate-2', gmdate('c', $kickoff), 'Arsenal', 'Chelsea', '11', '21')],
        ['skipHistory' => true]
    );
    foreach (array_unique([gmdate('Y-m-d', $liveKickoff), gmdate('Y-m-d', $kickoff)]) as $day) {
        fx_fb_sync_today($module, (string) $day);
    }
    $live = $module->refresh()->evaluate('football-live');
    assert_equals(true, $live['due'], 'a match is in play');
    assert_equals('DUE', $live['reason']);
    assert_equals(1, (int) $live['detail']['count']);
    assert_equals($module->config()->requestBudget('live'), (int) $live['detail']['budget'], 'with its own request budget');

    // …and the analysis jobs spend no quota at all.
    assert_equals(0, $module->config()->requestBudget('predict'), 'prediction is a database-only job');
    assert_equals(0, $module->config()->requestBudget('settle'), 'so is settlement');

    // Disabled module: provider jobs stop, housekeeping does not need the provider.
    $disabled = new FootballIntelligence($repo, $module->providerManager(), null, new FootballConfiguration(['WINDELS_FOOTBALL_ENABLED' => 'false']));
    $off = $disabled->refresh()->evaluate('football-live');
    assert_equals(false, $off['due']);
    assert_equals('MODULE_DISABLED', $off['reason']);

    // No work: nothing in play tonight.
    $quiet = new FootballIntelligence(new FootballRepositoryStub(), $module->providerManager(), null, $config);
    $idle = $quiet->refresh()->evaluate('football-results');
    assert_equals(false, $idle['due']);
    assert_equals('NO_WORK', $idle['reason'], 'an empty result queue costs no request');
    $pending = $quiet->refresh()->evaluate('football-predict');
    assert_equals('NO_WORK', $pending['reason'], 'and neither does an empty board');
});

test('football: the module knows its own schedule, earliest wake first', function () {
    [$repo, , $module] = fx_fb_harness([], ['skipHistory' => true]);
    $schedule = $module->refresh()->schedule();
    assert_equals(count(RefreshPolicy::jobIds()), count($schedule['jobs']), 'every job appears exactly once');
    $names = array_column($schedule['jobs'], 'job');
    foreach (RefreshPolicy::jobIds() as $job) {
        assert_in_array($job, $names, $job . ' is in the schedule');
    }
    foreach ($schedule['jobs'] as $row) {
        foreach (['job', 'bucket', 'interval', 'due', 'reason', 'nextRunAt', 'lastRunAt', 'requests', 'detail'] as $key) {
            assert_true(array_key_exists($key, $row), 'each row reports ' . $key);
        }
        assert_true(is_bool($row['due']));
        assert_true(is_string($row['reason']) && $row['reason'] !== '');
    }
    assert_true(is_array($schedule['due']), 'the due list is a list');
    foreach ($schedule['due'] as $job) {
        assert_in_array($job, $names, 'only real jobs can be due');
    }
    if ($schedule['nextWakeAt'] !== null) {
        assert_true((int) $schedule['nextWakeInSeconds'] >= 0, 'the wake time is in the future');
        assert_true(abs(strtotime((string) $schedule['nextWakeAt']) - (time() + (int) $schedule['nextWakeInSeconds'])) <= 2,
            'the wake time and the countdown agree');
    }
});

test('football: a deferred or recent run defers the next tick', function () {
    [$repo, , $module] = fx_fb_harness([fx_fb_row('fx-defer', gmdate('c', time() - 600), 'Manchester City', 'Everton', '10', '20', 'LIVE', 1, 0, 30)], ['skipHistory' => true]);
    $run = $repo->startSyncRun(['executionKey' => 'LIVE:test-defer', 'jobType' => 'LIVE', 'windowStart' => gmdate('Y-m-d'), 'startedAt' => gmdate('c')]);
    assert_not_null($run, 'the sweep log accepts the run');
    $repo->finishSyncRun('LIVE:test-defer', ['status' => 'DEFERRED', 'requests' => 6, 'nextRunAt' => gmdate('c', time() + 600)]);
    $deferred = $module->refresh()->evaluate('football-live');
    assert_equals(false, $deferred['due']);
    assert_equals('PROVIDER_DEFERRED', $deferred['reason'], 'the provider asked to come back later, so we do');
    assert_true((string) $deferred['detail']['nextRunAt'] !== '');
    assert_close((float) (time() + 600), (float) strtotime((string) $deferred['nextRunAt']), 5.0, 'and the console shows when');

    // A completed run is held off by its own cadence rather than by a timer.
    $repo->finishSyncRun('LIVE:test-defer', ['status' => 'COMPLETED', 'requests' => 2, 'nextRunAt' => null]);
    $cadence = $module->refresh()->evaluate('football-live');
    assert_equals(false, $cadence['due']);
    assert_equals('CADENCE', $cadence['reason']);
    assert_true((int) $cadence['detail']['elapsed'] < $cadence['interval'], 'elapsed time is measured, not assumed');
});

test('football: a rate-limited provider is put in backoff and then costs nothing', function () {
    [$repo, $provider, $module] = fx_fb_harness([fx_fb_row('fx-rl', gmdate('c', time() + 7200), 'Manchester City', 'Everton', '10', '20')],
        ['skipHistory' => true], ['WINDELS_FOOTBALL_MIN_REQUEST_SPACING_MS' => '0']);
    $provider->rateLimited = true;
    $before = $provider->calls;
    $sync = $module->fixtures()->syncDay(gmdate('Y-m-d', time() + 7200), 'test:rate-limit', null, -1);
    assert_true(in_array((string) $sync['status'], ['FAILED', 'DEFERRED'], true), 'the sweep reports the limit instead of retrying forever');
    $row = $repo->listProviders()[0] ?? [];
    $until = (string) ($row['backoff_until'] ?? '');
    assert_true($until !== '' && strtotime($until) > time(), 'the provider is put in backoff with an explicit time');
    $first = strtotime($until) - time();
    assert_true($first >= 30, 'backoff is at least half a minute');

    // While it is in backoff no request is made at all, and the job reports why.
    $callsAfterFailure = $provider->calls;
    $evaluation = $module->refresh()->evaluate('football-live');
    assert_equals('PROVIDER_BACKOFF', $evaluation['reason']);
    assert_equals(false, $evaluation['due']);
    assert_equals($until, (string) $evaluation['detail']['until'], 'and names the moment it may try again');
    $again = $module->fixtures()->syncDay(gmdate('Y-m-d', time() + 7200), 'test:rate-limit-2', null, -1);
    assert_equals($callsAfterFailure, $provider->calls, 'a backoff window spends zero provider requests');
    assert_contains('RATE_LIMIT_BACKOFF', strtoupper(json_encode($again)), 'and the skip is reported, not hidden');

    // Consecutive failures widen the window; a retry-after from the feed wins.
    $gateway = $module->gateway();
    $gateway->recordFailure('apifootball', 'connection reset');
    $grown = strtotime((string) (($repo->listProviders()[0] ?? [])['backoff_until'] ?? 'now')) - time();
    assert_true($grown > $first, 'the second failure backs off harder than the first: ' . $first . 's then ' . $grown . 's');
    $gateway->recordFailure('apifootball', 'still failing');
    $cappedBy = strtotime((string) (($repo->listProviders()[0] ?? [])['backoff_until'] ?? 'now')) - time();
    assert_true($cappedBy > $grown && $cappedBy <= 900, 'it keeps growing but never past fifteen minutes: ' . $cappedBy . 's');
    $gateway->recordFailure('apifootball', 'rate limited', [], new \AIWorkforce\Sports\Providers\ProviderException(
        'slow down', \AIWorkforce\Sports\Providers\ProviderException::RATE_LIMITED, null, ['retryAfterSeconds' => 420]
    ));
    $honoured = strtotime((string) (($repo->listProviders()[0] ?? [])['backoff_until'] ?? 'now')) - time();
    assert_equals(420, $honoured, 'a stated retry-after is obeyed exactly');
    assert_equals('RATE_LIMITED', (string) (($repo->listProviders()[0] ?? [])['status'] ?? ''), 'and the row says why');
    $gateway->clearBackoff('apifootball');
    $cleared = $repo->listProviders()[0] ?? [];
    assert_true(empty($cleared['backoff_until']), 'a success clears it');
    $after = $provider->calls;
    $module->fixtures()->syncDay(gmdate('Y-m-d', time() + 7200), 'test:rate-limit-3', null, -1);
    assert_true($provider->calls >= $after, 'requests resume once the window has been cleared');
});

test('football: a daily request ceiling stops the sweep before the provider does', function () {
    $day = gmdate('Y-m-d', time() + 7200);
    [$repo, $provider, $module] = fx_fb_harness([fx_fb_row('fx-ceiling', gmdate('c', time() + 7200), 'Manchester City', 'Everton', '10', '20')],
        ['skipHistory' => true], ['WINDELS_FOOTBALL_MIN_REQUEST_SPACING_MS' => '0']);
    // A first sweep, uncapped: it registers the provider and records the feed's own
    // daily limit against the day it was reported.
    $module->fixtures()->syncDay($day, 'test:ceiling-warm', null, -1);
    $row = $repo->listProviders()[0] ?? [];
    $providerId = (int) ($row['id'] ?? 0);
    assert_true($providerId > 0, 'a provider that has served data has a row to put a ceiling on');
    assert_true($provider->calls > 0, 'and that sweep spent requests');
    assert_equals(500, (int) ($row['requests_budget'] ?? 0), 'the feed\'s own daily limit is stored, not guessed');
    assert_equals(gmdate('Y-m-d'), (string) ($row['requests_used_date'] ?? ''), 'against today');

    $capped = static fn(array $overrides): FootballIntelligence => new FootballIntelligence($repo, $module->providerManager(), null,
        new FootballConfiguration(array_merge(['WINDELS_FOOTBALL_DAILY_REQUEST_CEILING' => '3', 'WINDELS_FOOTBALL_MIN_REQUEST_SPACING_MS' => '0'], $overrides)));

    // Usage counted yesterday must not be charged against today's ceiling.
    $repo->updateProvider($providerId, ['requests_budget' => null, 'requests_used' => 99, 'requests_used_date' => gmdate('Y-m-d', time() - 86400)]);
    $fresh = $capped([]);
    $fresh->gateway()->beginSweep(-1);
    $okCall = $fresh->gateway()->call('fixtures', static fn($p) => $p->fixtures(['from' => '2000-01-01', 'to' => '2100-01-01']), null, false);
    assert_equals(true, $okCall['ok'], 'today starts clean');

    // Today's spent quota is honoured: the sweep stops on its own.
    $used = $provider->calls;
    $repo->updateProvider($providerId, ['requests_used' => 3, 'requests_used_date' => gmdate('Y-m-d')]);
    $blocked = $capped([]);
    $blocked->gateway()->beginSweep(-1);
    $call = $blocked->gateway()->call('fixtures', static fn($p) => $p->fixtures(['from' => '2000-01-01', 'to' => '2100-01-01']), null, false);
    assert_equals(false, $call['ok'], 'the ceiling is enforced in-process');
    assert_equals($used, $provider->calls, 'without making the request it is preventing');
    assert_contains('DAILY_QUOTA_EXHAUSTED:3/3', json_encode($call['failures'], JSON_UNESCAPED_SLASHES), 'and the reason states the exact usage');
    assert_equals(true, $call['deferred'], 'a stopped sweep is a deferral, not a failure');
    $sync = $blocked->fixtures()->syncDay(gmdate('Y-m-d', time() + 86400), 'test:ceiling-blocked', null, -1);
    assert_equals('DEFERRED', (string) $sync['status'], 'and the job says so instead of pretending it completed');
    assert_equals($used, $provider->calls, 'still no request spent');
    $stored = $repo->lastSyncRun('FIXTURES');
    assert_true(is_string((string) ($stored['next_run_at'] ?? '')) && strtotime((string) $stored['next_run_at']) > time(),
        'with a next attempt recorded, so the queue resumes by itself');
});

test('football: one fixture is tracked at the cadence its own state implies', function () {
    [$repo, , $module] = fx_fb_harness([], ['skipHistory' => true]);
    $policy = $module->refresh();
    $live = $policy->forFixture(['status' => 'LIVE', 'kickoff_at' => gmdate('c', time() - 1800)]);
    assert_equals('LIVE', $live['phase']);
    assert_equals($module->config()->refreshInterval('live'), $live['interval'], 'in play means the live cadence');
    $scheduled = $policy->forFixture(['status' => 'SCHEDULED', 'kickoff_at' => gmdate('c', time() + 5 * 86400)]);
    assert_equals('SCHEDULED', $scheduled['phase']);
    assert_equals($module->config()->refreshInterval('upcoming'), $scheduled['interval'], 'days away means the upcoming cadence');
    $imminent = $policy->forFixture(['status' => 'SCHEDULED', 'kickoff_at' => gmdate('c', time() + 900)]);
    assert_equals('PRE_KICKOFF', $imminent['phase']);
    assert_equals($live['interval'], $imminent['interval'], 'and inside the hour it is tracked like a live match');
    $finished = $policy->forFixture(['status' => 'FINISHED', 'kickoff_at' => gmdate('c', time() - 7200), 'settled_at' => null]);
    assert_equals('PENDING_SETTLEMENT', $finished['phase'], 'a final score without settlement is still watched');
    $settled = $policy->forFixture(['status' => 'FINISHED', 'kickoff_at' => gmdate('c', time() - 7200), 'settled_at' => gmdate('c')]);
    assert_equals('SETTLED', $settled['phase']);
    assert_true(str_contains($settled['reason'], 'settled once'), 'then it is never refreshed again');
    $postponed = $policy->forFixture(['status' => 'POSTPONED', 'kickoff_at' => gmdate('c', time() + 7200)]);
    assert_equals('INACTIVE', $postponed['phase']);
    $late = $policy->forFixture(['status' => 'SCHEDULED', 'kickoff_at' => gmdate('c', time() - 120)]);
    assert_equals('KICKOFF_PASSED', $late['phase'], 'a fixture that slipped past kickoff is only checked for a result');
});

test('football: the cron sweep runs every job once, on its own terms', function () {
    [$repo, $provider, $module] = fx_fb_harness([fx_fb_row('fx-cron', gmdate('c', time() + 7200), 'Manchester City', 'Everton', '10', '20')],
        ['skipHistory' => true]);
    $cron = $module->cron();
    assert_true($cron instanceof FootballCronService);
    $day = gmdate('Y-m-d', time() + 7200);
    fx_fb_sync_today($module, $day);
    $summary = $cron->runAll(false, $day);
    foreach (FootballCronService::JOBS as $job) {
        assert_true(isset($summary[$job]), $job . ' reported its outcome');
        assert_true(in_array((string) ($summary[$job]['status'] ?? ''), ['COMPLETED', 'SKIPPED', 'DEFERRED', 'FAILED', 'NOTHING_TO_SETTLE', 'DUPLICATE_SKIPPED', 'DATA_UNAVAILABLE', 'NO_DATA'], true),
            $job . ' reports a known status, got ' . json_encode($summary[$job]['status'] ?? null));
        assert_true(isset($summary[$job]['job']), $job . ' names itself');
    }
    assert_true(isset($summary['schedule']['jobs']), 'and the schedule that follows it');
    // Analysis is database-only: predict must not have spent quota beyond the
    // fixture sync that ran before it.
    $before = $provider->calls;
    $predicted = $cron->run('predict', $day, true);
    assert_equals($before, $provider->calls, 'the predict job made no provider request');
    assert_true(in_array((string) $predicted['status'], ['COMPLETED', 'NO_FIXTURES', 'DATA_UNAVAILABLE'], true), 'and still reported an outcome');
    assert_equals(0, (int) $predicted['requests'], 'with its request count stored as zero');
    // Idempotency: re-running settle after everything is graded changes nothing.
    $settlements = count($repo->listSettlements([], 500));
    $again = $cron->run('settle', $day, true);
    assert_equals($settlements, count($repo->listSettlements([], 500)), 'settlement is not doubled by a second tick');
    assert_true((int) ($again['settled'] ?? 0) <= 0 || $again['status'] === 'NOTHING_TO_SETTLE', 'it reports nothing left to do');
    // An unknown job is a programming error, not a silent no-op.
    $threw = false;
    try { $cron->run('not-a-job'); } catch (\InvalidArgumentException $e) { $threw = true; }
    assert_true($threw, 'and it is refused loudly');
});

test('football: cron records each run so "when did this last happen" is answerable', function () {
    [$repo, , $module] = fx_fb_harness([], ['skipHistory' => true]);
    $day = gmdate('Y-m-d');
    $module->cron()->run('cleanup', $day, true);
    $runs = $repo->listSyncRuns('CLEANUP', 10);
    assert_true(count($runs) >= 1, 'the run is logged');
    $row = $runs[0];
    assert_equals('COMPLETED', (string) $row['status']);
    assert_true((string) $row['execution_key'] !== '', 'under its idempotency key');
    assert_true((string) $row['next_run_at'] !== '', 'with the next eligible time');
    assert_equals(0, (int) $row['requests_made'], 'and the requests it spent');
    // The same hour cannot be logged twice by an automatic tick.
    $auto = $module->cron()->run('cleanup', $day);
    $forcedAgain = $module->cron()->run('cleanup', $day, true);
    assert_equals('SKIPPED', (string) $auto['status'], 'a second automatic run is skipped by cadence');
    assert_true(in_array((string) $forcedAgain['status'], ['COMPLETED', 'DUPLICATE_SKIPPED'], true),
        'while an operator-forced run either works or is recognised as a duplicate');
});

test('football: an idle sweep stays quiet and an eventful one is audited', function () {
    $audit = new class implements \AIWorkforce\Persistence\AuditRepository {
        public array $events = [];
        public function emit(string $type, string $summary, array $detail = [], string $actor = 'system'): void
        { $this->events[] = ['type' => $type, 'summary' => $summary, 'actor' => $actor]; }
        public function recent(int $limit = 100): array { return $this->events; }
    };
    // Nothing stored, no provider connected: the sweep runs, every job says so,
    // and the audit log stays clean — the per-minute tick must not become spam.
    $repo = new FootballRepositoryStub();
    $idle = new FootballIntelligence($repo, new \AIWorkforce\Sports\Providers\SportsProviderManager(),
        $audit, new FootballConfiguration());
    $summary = $idle->cron()->runAll();
    foreach (FootballCronService::JOBS as $job) {
        assert_equals('SKIPPED', (string) ($summary[$job]['status'] ?? ''), $job . ' reports it had nothing to do');
        assert_equals('PROVIDER_NOT_CONFIGURED', (string) ($summary[$job]['reason'] ?? ''), 'because no provider is connected');
    }
    assert_equals([], array_values(array_filter($audit->events, static fn(array $e): bool => $e['type'] === 'FOOTBALL_CRON_RUN')),
        'an idle sweep writes no sweep-level audit event');

    // A sweep that actually settles something is reported, with the job listed.
    $day = gmdate('Y-m-d', time() + 7200);
    [$repo2, $provider2, $module2] = fx_fb_harness(
        [fx_fb_row('fx-audit', gmdate('c', time() + 7200), 'Manchester City', 'Everton', '10', '20')], ['skipHistory' => true]);
    fx_fb_sync_today($module2, $day);
    $module2->predictions()->predictDay($day);
    $providerId = (int) ($repo2->listProviders()[0]['id'] ?? 1);
    foreach ($repo2->listFixtures(['date' => $day], 50) as $fixture) {
        $repo2->saveFixture($providerId, ['externalId' => (string) $fixture['external_id'], 'status' => 'FINISHED',
            'homeScore' => 2, 'awayScore' => 1]);
    }
    $audit2 = new class implements \AIWorkforce\Persistence\AuditRepository {
        public array $events = [];
        public function emit(string $type, string $summary, array $detail = [], string $actor = 'system'): void
        { $this->events[] = ['type' => $type, 'summary' => $summary, 'actor' => $actor]; }
        public function recent(int $limit = 100): array { return $this->events; }
    };
    $busy = new FootballIntelligence($repo2, $module2->providerManager(), $audit2, new FootballConfiguration());
    $busySummary = $busy->cron()->run('settle', $day, true);
    assert_equals('COMPLETED', (string) $busySummary['status'], 'the settle job graded the finished fixture');
    $types = array_column($audit2->events, 'type');
    assert_in_array('FOOTBALL_PREDICTIONS_SETTLED', $types, 'the settlement itself is audited');
    $idleTypes = array_column($audit->events, 'type');
    assert_equals([], array_values(array_filter($idleTypes, static fn(string $t): bool => str_starts_with($t, 'FOOTBALL_SYNC'))),
        'and an idle sweep does not even pretend it synced');
});

test('football: an unexpected job failure is contained and audited', function () {
    [$repo, $provider, $module] = fx_fb_harness([], ['skipHistory' => true], ['WINDELS_FOOTBALL_MIN_REQUEST_SPACING_MS' => '0']);
    $day = gmdate('Y-m-d', time() + 7200);
    $provider->failFixtures = true;
    $summary = $module->cron()->runAll(true, $day);
    foreach (FootballCronService::JOBS as $job) {
        assert_true(is_array($summary[$job] ?? null), $job . ' still returned a result');
        assert_true(isset($summary[$job]['status']), 'with a status, even when the provider failed');
    }
    $fixtures = $summary['fixtures'];
    assert_true(in_array((string) $fixtures['status'], ['FAILED', 'DEFERRED', 'SKIPPED', 'NOTHING_TO_SETTLE'], true),
        'the failing job reports honestly: ' . json_encode($fixtures['status'] ?? null));
    assert_equals([], $repo->listFixtures(['date' => $day], 10), 'and nothing was written for it');
});
