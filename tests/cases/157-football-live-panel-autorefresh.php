<?php
/**
 * Regression: the "Live now" panel must actually be live.
 *
 * The panel promises, in its own words, that "live match updates appear here
 * automatically, immediately after the provider reports them". That sentence
 * was not true. The browser polled `/api/football/fixtures/live` every few
 * seconds, but the endpoint called `board(refresh: false)`, which only re-reads
 * stored rows. Those rows are written by ONE thing: the scheduled
 * `football-live` job. So:
 *
 *   - a goal was invisible to every open page until the next cron tick;
 *   - on a deployment where the platform cron was never installed (a plain
 *     shared host, the documented "no CLI" case), the panel would poll forever
 *     and never change — a permanently frozen scoreboard that kept describing
 *     itself as automatic.
 *
 * The read now performs the provider sweep itself when the live cadence is due.
 * The cases below pin both halves of that: it really does fetch (so the panel
 * is current with no scheduler at all), and it is still gated (so the fix does
 * not turn each open tab into an uncontrolled provider bill).
 */

use AIWorkforce\Football\FootballConfiguration;
use AIWorkforce\Football\FootballIntelligence;
use AIWorkforce\Sports\Providers\SportsProviderManager;

require_once TESTSPATH . 'football_support.php';

/** The scores the Live now panel is currently showing, keyed by external id. */
function fx_auto_scores(array $board): array
{
    $out = [];
    foreach ((array) ($board['matches'] ?? []) as $match) {
        $external = (string) (($match['fixture'] ?? [])['externalId'] ?? '');
        $score = ($match['live'] ?? [])['score'] ?? null;
        $out[$external] = is_array($score) ? (($score['home'] ?? '-') . '-' . ($score['away'] ?? '-')) : null;
    }
    return $out;
}

/**
 * Simulate the live window elapsing while a reader sits on the page.
 *
 * Three things change when real time passes, and a faithful stand-in has to
 * move all three or the sweep is refused for the wrong reason:
 *   - `started_at`: the interval gate in RefreshPolicy;
 *   - `next_run_at`: the deferral recorded by the previous run;
 *   - the LIVE run's `execution_key`, which is bucketed on wall-clock time
 *     (`live:<time/interval>`). After a window passes, the stored run belongs
 *     to an EARLIER bucket, which is precisely what frees the current bucket's
 *     key for the next sweep to claim.
 */
function fx_auto_age_sweeps(object $repo, int $secondsAgo): void
{
    foreach ($repo->syncRuns as &$run) {
        $run['started_at'] = gmdate('c', time() - $secondsAgo);
        if (!empty($run['next_run_at'])) $run['next_run_at'] = gmdate('c', time() - max(1, intdiv($secondsAgo, 2)));
        $key = (string) ($run['execution_key'] ?? '');
        if (preg_match('/^(live:)(\d+)$/', $key, $m) === 1) {
            $run['execution_key'] = $m[1] . (string) ((int) $m[2] - 1000);
        }
    }
    unset($run);
}

test('football live panel: a goal reaches an open page with no scheduler running at all', function () {
    // The headline case. Nothing here ever calls the cron service: the only
    // thing that happens between the two reads is that the provider changed
    // its answer, exactly as it does when somebody scores.
    $kickoff = gmdate('c', time() - 1800);
    $before = fx_fb_row('fx-auto-1', $kickoff, 'Manchester City', 'Everton', '10', '20', 'LIVE', 0, 0, 20);
    [$repo, $provider, $module] = fx_fb_harness([$before], ['live' => [$before], 'skipHistory' => true],
        ['WINDELS_FOOTBALL_REFRESH_LIVE' => 60]);
    fx_fb_sync_today($module, gmdate('Y-m-d', strtotime($kickoff)));

    $board = $module->live()->board(false, true);
    assert_equals(['fx-auto-1' => '0-0'], fx_auto_scores($board), 'the match starts goalless on the panel');

    // 1–0. The provider knows; nothing has written it to the database yet.
    $provider->setLiveFixtures([fx_fb_row('fx-auto-1', $kickoff, 'Manchester City', 'Everton', '10', '20', 'LIVE', 1, 0, 24)]);
    fx_auto_age_sweeps($repo, 600);

    $board = $module->live()->board(false, true);
    assert_equals(['fx-auto-1' => '1-0'], fx_auto_scores($board),
        'the goal appears on the panel from a plain page read, with no cron tick in between');
    assert_equals(true, (bool) ($board['sweep']['ran'] ?? false), 'and the read reports that it performed the sweep');
});

test('football live panel: a match that kicks off appears without anyone reloading', function () {
    // The other half of "immediately after the provider reports it": a fixture
    // that was not in the live set at all has to arrive on its own.
    $kickoff = gmdate('c', time() - 300);
    $scheduled = fx_fb_row('fx-auto-2', $kickoff, 'Brighton', 'Burnley', '30', '40', 'SCHEDULED');
    [$repo, $provider, $module] = fx_fb_harness([$scheduled], ['live' => [], 'skipHistory' => true],
        ['WINDELS_FOOTBALL_REFRESH_LIVE' => 60]);
    fx_fb_sync_today($module, gmdate('Y-m-d', strtotime($kickoff)));

    $board = $module->live()->board(false, true);
    assert_equals([], fx_auto_scores($board), 'nothing is live before kickoff');
    assert_equals('NO_LIVE_FIXTURES', (string) ($board['state'] ?? ''), 'and the panel says so plainly');

    // The match kicks off.
    $provider->setLiveFixtures([fx_fb_row('fx-auto-2', $kickoff, 'Brighton', 'Burnley', '30', '40', 'LIVE', 0, 0, 3)]);
    fx_auto_age_sweeps($repo, 600);

    $board = $module->live()->board(false, true);
    assert_equals(['fx-auto-2' => '0-0'], fx_auto_scores($board), 'the kicked-off match arrives on the panel by itself');
    assert_equals('LIVE', (string) ($board['state'] ?? ''), 'and the board reports it is carrying live matches');
});

test('football live panel: a kicked-off match makes the live sweep due, not just an upcoming one', function () {
    // The second defect behind a frozen panel, independent of who drives the
    // sweep. RefreshPolicy decides whether the live job has any work, and its
    // window only looked FORWARD ("starting within the hour"). A match that
    // kicked off ten minutes ago is still stored SCHEDULED until a live
    // snapshot promotes it — so the job that would have promoted it was judged
    // to have nothing to do. The live sweep stalled precisely when a match was
    // starting, and the panel stayed empty through the opening minutes.
    [$repo, , $module] = fx_fb_harness([], ['live' => [], 'skipHistory' => true], ['WINDELS_FOOTBALL_REFRESH_LIVE' => 60]);
    $providerId = (int) (($repo->listProviders()[0] ?? ['id' => 0])['id']);

    $repo->saveFixture($providerId, [
        'externalId' => 'fx-auto-kickoff', 'kickoff' => gmdate('c', time() - 600), 'status' => 'SCHEDULED',
        'homeTeam' => 'Manchester City', 'awayTeam' => 'Everton', 'homeTeamId' => '10', 'awayTeamId' => '20',
    ]);
    $evaluation = $module->refresh()->evaluate('football-live');
    assert_not_equals('NO_WORK', (string) $evaluation['reason'],
        'a match whose kickoff has passed is work for the live sweep — it is the only job that can confirm it started');
    assert_equals(true, (bool) $evaluation['due'], 'so the live job is due');

    // The bound is still real: a fixture left mis-stated long after kickoff
    // belongs to the results sweep and must not hold the live job open.
    [$stale, , $staleModule] = fx_fb_harness([], ['live' => [], 'skipHistory' => true], ['WINDELS_FOOTBALL_REFRESH_LIVE' => 60]);
    $staleProviderId = (int) (($stale->listProviders()[0] ?? ['id' => 0])['id']);
    $stale->saveFixture($staleProviderId, [
        'externalId' => 'fx-auto-longgone', 'kickoff' => gmdate('c', time() - 6 * 3600), 'status' => 'SCHEDULED',
        'homeTeam' => 'Brighton', 'awayTeam' => 'Burnley', 'homeTeamId' => '30', 'awayTeamId' => '40',
    ]);
    assert_equals('NO_WORK', (string) $staleModule->refresh()->evaluate('football-live')['reason'],
        'a fixture hours past kickoff does not keep the live sweep running forever');
});

test('football live panel: a finished match leaves an open page by itself too', function () {
    // Removal has to be automatic for the same reason arrival does, or the
    // panel accumulates matches that ended hours ago.
    $kickoff = gmdate('c', time() - 7200);
    $row = fx_fb_row('fx-auto-3', $kickoff, 'Manchester City', 'Everton', '10', '20', 'LIVE', 2, 1, 90);
    [$repo, $provider, $module] = fx_fb_harness([$row], ['live' => [$row], 'skipHistory' => true],
        ['WINDELS_FOOTBALL_REFRESH_LIVE' => 60]);
    fx_fb_sync_today($module, gmdate('Y-m-d', strtotime($kickoff)));
    assert_equals(['fx-auto-3' => '2-1'], fx_auto_scores($module->live()->board(false, true)), 'it starts on the panel');

    $provider->setLiveFixtures([]);            // full time
    fx_auto_age_sweeps($repo, 600);

    $board = $module->live()->board(false, true);
    assert_equals([], fx_auto_scores($board), 'the finished match is gone from the panel without a reload');
    assert_equals('NO_LIVE_FIXTURES', (string) ($board['state'] ?? ''), 'and the panel reports an empty live set');
});

test('football live panel: rapid polling costs at most one provider call per live window', function () {
    // The guard that makes the fix affordable. The browser polls every few
    // seconds; the provider must still only be asked on the live cadence.
    $kickoff = gmdate('c', time() - 1800);
    $row = fx_fb_row('fx-auto-4', $kickoff, 'Manchester City', 'Everton', '10', '20', 'LIVE', 0, 0, 30);
    [$repo, $provider, $module] = fx_fb_harness([$row], ['live' => [$row], 'skipHistory' => true],
        ['WINDELS_FOOTBALL_REFRESH_LIVE' => 90]);
    fx_fb_sync_today($module, gmdate('Y-m-d', strtotime($kickoff)));
    $module->live()->board(false, true);

    $calls = $provider->calls;
    for ($i = 0; $i < 25; $i++) $module->live()->board(false, true);
    assert_equals(0, $provider->calls - $calls, '25 polls inside one live window spend no provider request at all');

    // The window passes: exactly one more request, however many readers there are.
    fx_auto_age_sweeps($repo, 600);
    $calls = $provider->calls;
    for ($i = 0; $i < 10; $i++) $module->live()->board(false, true);
    assert_equals(1, $provider->calls - $calls,
        'once the window elapses ten concurrent readers still produce a single provider call');
});

test('football live panel: the browser-driven sweep and the cron job do not double-spend', function () {
    // Both drivers must share one execution key per window, or a host that DOES
    // run the scheduler pays twice for the same data.
    $kickoff = gmdate('c', time() - 1800);
    $row = fx_fb_row('fx-auto-5', $kickoff, 'Manchester City', 'Everton', '10', '20', 'LIVE', 1, 1, 55);
    [$repo, $provider, $module] = fx_fb_harness([$row], ['live' => [$row], 'skipHistory' => true],
        ['WINDELS_FOOTBALL_REFRESH_LIVE' => 90]);
    fx_fb_sync_today($module, gmdate('Y-m-d', strtotime($kickoff)));

    fx_auto_age_sweeps($repo, 600);
    $calls = $provider->calls;
    $module->live()->board(false, true);                 // a reader sweeps this window
    $afterRead = $provider->calls - $calls;
    assert_equals(1, $afterRead, 'the page read performed this window\'s sweep');

    // The scheduler ticks inside the same window: it must find the work done.
    $cron = $module->cron()->run('live');
    assert_true(in_array((string) ($cron['status'] ?? ''), ['DUPLICATE_SKIPPED', 'SKIPPED'], true),
        'the cron tick in the same window is deduplicated rather than repeating the call, got: ' . (string) ($cron['status'] ?? ''));
    assert_equals(1, $provider->calls - $calls, 'and the window still cost exactly one provider request in total');
});

test('football live panel: a read never sweeps while the provider is in backoff', function () {
    // The read must obey the same outage circuit as the scheduled job: an open
    // page cannot be allowed to hammer a feed that is already failing.
    $kickoff = gmdate('c', time() - 1800);
    $row = fx_fb_row('fx-auto-6', $kickoff, 'Manchester City', 'Everton', '10', '20', 'LIVE', 0, 0, 15);
    [$repo, $provider, $module] = fx_fb_harness([$row], ['live' => [$row], 'skipHistory' => true],
        ['WINDELS_FOOTBALL_REFRESH_LIVE' => 60]);
    fx_fb_sync_today($module, gmdate('Y-m-d', strtotime($kickoff)));
    fx_auto_age_sweeps($repo, 600);

    $module->gateway()->recordFailure('apifootball', 'simulated outage');
    $calls = $provider->calls;
    $board = $module->live()->board(false, true);

    assert_equals(0, $provider->calls - $calls, 'a feed in backoff is left alone by the page read');
    assert_equals('PROVIDER_BACKOFF', (string) ($board['sweep']['reason'] ?? ''), 'and the payload names the outage');
    assert_equals(false, (bool) ($board['sweep']['ran'] ?? true), 'no sweep is claimed to have run');
    // The rows already stored are still served: an outage hides nothing that
    // was legitimately confirmed live.
    assert_equals(['fx-auto-6' => '0-0'], fx_auto_scores($board), 'the last confirmed live state is still shown');
});

test('football live panel: a provider failure during a read never breaks the page', function () {
    // A read path that throws would take the whole console down with it.
    $kickoff = gmdate('c', time() - 1800);
    $row = fx_fb_row('fx-auto-7', $kickoff, 'Manchester City', 'Everton', '10', '20', 'LIVE', 0, 1, 62);
    [$repo, $provider, $module] = fx_fb_harness([$row], ['live' => [$row], 'skipHistory' => true],
        ['WINDELS_FOOTBALL_REFRESH_LIVE' => 60]);
    fx_fb_sync_today($module, gmdate('Y-m-d', strtotime($kickoff)));
    fx_auto_age_sweeps($repo, 600);

    $provider->failFixtures = true;                       // the feed starts erroring
    $board = $module->live()->board(false, true);

    assert_true(is_array($board['matches'] ?? null), 'the board still renders through a provider failure');
    assert_equals(['fx-auto-7' => '0-1'], fx_auto_scores($board), 'showing the last state the provider did confirm');
    assert_true(is_array($board['sweep'] ?? null), 'and the sweep outcome is reported to the page');
});

test('football live panel: with no provider connected the page says so instead of polling silently', function () {
    // An honest empty state: nothing is fetching, and the reason is nameable.
    $module = new FootballIntelligence(new FootballRepositoryStub(), new SportsProviderManager(), null, new FootballConfiguration());
    $board = $module->live()->board(false, true);

    assert_equals([], (array) ($board['matches'] ?? [null]), 'no provider means no live matches');
    assert_equals('PROVIDER_NOT_CONFIGURED', (string) ($board['sweep']['reason'] ?? ''),
        'and the payload says why nothing is being fetched');
    assert_equals(false, (bool) ($board['sweep']['ran'] ?? true), 'no sweep is attempted without a feed');
});

test('football live panel: the live endpoint drives the sweep and the page reacts to its state', function () {
    // The wiring, pinned at both ends: the controller must ask for the
    // self-sweeping read, and the view must understand what comes back.
    $controller = fx_fb_read('application/controllers/Api_football.php');
    $start = strpos($controller, 'public function fixtures_live()');
    assert_true($start !== false, 'the live endpoint exists');
    $endpoint = substr($controller, (int) $start, 600);
    assert_contains('board($refresh, !$refresh)', $endpoint,
        'the polled endpoint performs the cadence-gated sweep instead of only re-reading stored rows');

    $view = fx_fb_read('application/views/football/index.php');
    assert_contains('data.sweep', $view, 'the panel reads the sweep state the endpoint reports');
    assert_contains('no football data provider is connected', $view,
        'and tells the reader when nothing can be fetched at all');
});
