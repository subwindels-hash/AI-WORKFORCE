<?php
/**
 * Regression: the "Live now" panel must show ONLY matches a provider is
 * reporting in play right now.
 *
 * The panel was holding finished matches for hours. Four independent defects
 * produced that, and each one alone is enough to bring the bug back, so each
 * gets its own case here:
 *
 *   1. Liveness was judged from `updated_at`. Any unrelated write — an odds
 *      refresh, a settlement pass, the read path caching a row — moved that
 *      column and made a finished match look freshly live.
 *   2. `expireMissingLiveFixtures()` only ran when the sweep had NO errors, so
 *      a single unparseable row in the provider payload cancelled the takedown
 *      for every other match in the same sweep.
 *   3. Nothing removed a stale row if the live job stopped running, so the
 *      last sweep's cards stayed on the panel indefinitely.
 *   4. `RefreshPolicy` blocked a provider job when ANY provider was in backoff,
 *      even though the gateway falls through to the next healthy provider — so
 *      one permanently broken secondary feed could stop the live sweep for
 *      good while the primary was online the whole time.
 *
 * Liveness is now carried by `live_confirmed_at`, which only a sweep that
 * actually saw the match in play may stamp.
 */

use AIWorkforce\Football\FootballConfiguration;
use AIWorkforce\Football\RefreshPolicy;
use AIWorkforce\Sports\Providers\SportsDataProvider;
use AIWorkforce\Sports\Providers\SportsProviderManager;

require_once TESTSPATH . 'football_support.php';

/** The live board's fixture ids, which is exactly what the panel renders. */
function fx_live_panel_ids(array $board): array
{
    return array_map(
        static fn(array $match): int => (int) (($match['fixture'] ?? [])['id'] ?? 0),
        array_values((array) ($board['matches'] ?? []))
    );
}

/** Move a stored fixture's live confirmation into the past. */
function fx_live_age_confirmation(object $repo, int $fixtureId, int $secondsAgo): void
{
    $repo->mutateFixture($fixtureId, ['live_confirmed_at' => gmdate('c', time() - $secondsAgo)]);
}

/** The stub assigns provider ids in creation order; never assume it is 1. */
function fx_live_provider_id(object $repo, string $code = 'apifootball'): int
{
    foreach ($repo->listProviders() as $row) {
        if ((string) ($row['provider_code'] ?? '') === $code) return (int) $row['id'];
    }
    return (int) (($repo->listProviders()[0] ?? ['id' => 0])['id']);
}

/** One stored fixture by external id, whichever provider row owns it. */
function fx_live_fixture(object $repo, string $externalId): array
{
    foreach ($repo->listFixtures([], 500) as $row) {
        if ((string) ($row['external_id'] ?? '') === $externalId) return $row;
    }
    return [];
}

/** True when the row carries no live confirmation (absent or explicitly null). */
function fx_live_unconfirmed(array $row): bool
{
    return ($row['live_confirmed_at'] ?? null) === null;
}

test('football live panel: a finished match leaves the panel the moment the provider stops reporting it', function () {
    $kickoff = gmdate('c', time() - 3600);
    [$repo, $provider, $module] = fx_fb_harness(
        [fx_fb_row('fx-live-1', $kickoff, 'Manchester City', 'Everton', '10', '20', 'LIVE', 1, 0, 55)],
        ['skipHistory' => true]
    );
    fx_fb_sync_today($module, gmdate('Y-m-d', strtotime($kickoff)));

    // The provider reports it in play: it belongs on the panel.
    $provider->setLiveFixtures([fx_fb_row('fx-live-1', $kickoff, 'Manchester City', 'Everton', '10', '20', 'LIVE', 1, 0, 55)]);
    $module->fixtures()->syncLive('test:live:1');
    $board = $module->live()->board(false);
    assert_equals(1, count(fx_live_panel_ids($board)), 'a genuinely live match is shown');

    // The match ends. The next successful sweep simply does not contain it,
    // which is the only signal a feed gives that a match is over.
    $provider->setLiveFixtures([]);
    $sync = $module->fixtures()->syncLive('test:live:2');
    assert_true((int) ($sync['expiredLive'] ?? 0) >= 1, 'the sweep reports the takedown it performed');

    $board = $module->live()->board(false);
    assert_equals([], fx_live_panel_ids($board), 'the finished match is gone from the Live now panel');
    assert_equals('NO_LIVE_FIXTURES', (string) ($board['state'] ?? ''), 'and the panel says so plainly');

    $row = fx_live_fixture($repo, 'fx-live-1');
    assert_equals('STALE_LIVE', (string) ($row['status'] ?? ''), 'the stored row is marked stale, not left claiming to be live');
    assert_equals(true, fx_live_unconfirmed($row), 'and its live confirmation is cleared');
});

test('football live panel: an unrelated write does not make a finished match look live again', function () {
    // Defect 1, the core bug. `updated_at` moves for many reasons that have
    // nothing to do with a match being in play; only a live sweep is evidence.
    $kickoff = gmdate('c', time() - 7200);
    [$repo, $provider, $module] = fx_fb_harness(
        [fx_fb_row('fx-live-2', $kickoff, 'Manchester City', 'Everton', '10', '20', 'LIVE', 2, 1, 80)],
        ['skipHistory' => true]
    );
    fx_fb_sync_today($module, gmdate('Y-m-d', strtotime($kickoff)));
    $provider->setLiveFixtures([fx_fb_row('fx-live-2', $kickoff, 'Manchester City', 'Everton', '10', '20', 'LIVE', 2, 1, 80)]);
    $module->fixtures()->syncLive('test:live:3');

    $providerId = fx_live_provider_id($repo);
    $fixtureId = (int) (fx_live_fixture($repo, 'fx-live-2')['id'] ?? 0);
    assert_true($fixtureId > 0, 'the fixture is stored');
    assert_equals(1, count(fx_live_panel_ids($module->live()->board(false))), 'it starts on the panel');

    // The live confirmation ages out past the staleness window.
    fx_live_age_confirmation($repo, $fixtureId, 7200);
    assert_equals([], fx_live_panel_ids($module->live()->board(false)), 'an unconfirmed match drops off the panel');

    // Now touch the row the way any unrelated job would. This used to be
    // enough to resurrect the card, because `updated_at` became "now".
    $repo->saveFixture($providerId, ['externalId' => 'fx-live-2', 'venue' => 'Etihad Stadium']);
    $refreshed = fx_live_fixture($repo, 'fx-live-2');
    assert_true((string) ($refreshed['updated_at'] ?? '') !== '', 'the unrelated write did touch the row');

    assert_equals([], fx_live_panel_ids($module->live()->board(false)),
        'an unrelated write must NOT put a finished match back on the Live now panel');
});

test('football live panel: a live claim can only come from a sweep that saw the match in play', function () {
    // The write contract behind defect 1: no caller may forge a live
    // confirmation by simply asking for one on a non-live row.
    [$repo, , $module] = fx_fb_harness([], ['skipHistory' => true]);
    $providerId = fx_live_provider_id($repo);

    $finished = $repo->saveFixture($providerId, [
        'externalId' => 'fx-live-3', 'kickoff' => gmdate('c', time() - 9000), 'status' => 'FINISHED',
        'homeTeam' => 'Manchester City', 'awayTeam' => 'Everton', 'homeTeamId' => '10', 'awayTeamId' => '20',
        'homeScore' => 3, 'awayScore' => 1,
        // A caller claiming liveness on a finished match: must be refused.
        'liveConfirmed' => true, 'liveConfirmedAt' => gmdate('c'),
    ]);
    assert_equals(true, fx_live_unconfirmed($finished),
        'a FINISHED row cannot be stamped as live-confirmed whatever the caller passes');

    $live = $repo->saveFixture($providerId, [
        'externalId' => 'fx-live-4', 'kickoff' => gmdate('c', time() - 1800), 'status' => 'LIVE',
        'homeTeam' => 'Brighton', 'awayTeam' => 'Burnley', 'homeTeamId' => '30', 'awayTeamId' => '40',
        'homeScore' => 0, 'awayScore' => 0, 'liveConfirmed' => true,
    ]);
    assert_true((string) ($live['live_confirmed_at'] ?? '') !== '',
        'a LIVE row from a sweep that witnessed it does carry the confirmation');

    // And a write that never claims liveness never gains it, which is what
    // keeps the read path (which caches fixtures) from forging a live match.
    $quiet = $repo->saveFixture($providerId, [
        'externalId' => 'fx-live-5', 'kickoff' => gmdate('c', time() - 1800), 'status' => 'LIVE',
        'homeTeam' => 'Brighton', 'awayTeam' => 'Everton', 'homeTeamId' => '30', 'awayTeamId' => '20',
    ]);
    assert_equals(true, fx_live_unconfirmed($quiet),
        'a plain cache write of a LIVE-looking row is not evidence the match is in play');
});

test('football live panel: one malformed provider row cannot keep finished matches on the panel', function () {
    // Defect 2. Two matches are live; the next sweep reports neither, but
    // includes one unusable row. The takedown must still happen.
    $kickoff = gmdate('c', time() - 3600);
    $rows = [
        fx_fb_row('fx-live-6', $kickoff, 'Manchester City', 'Everton', '10', '20', 'LIVE', 1, 0, 40),
        fx_fb_row('fx-live-7', $kickoff, 'Brighton', 'Burnley', '30', '40', 'LIVE', 0, 0, 41),
    ];
    [$repo, $provider, $module] = fx_fb_harness($rows, ['skipHistory' => true]);
    fx_fb_sync_today($module, gmdate('Y-m-d', strtotime($kickoff)));
    $provider->setLiveFixtures($rows);
    $module->fixtures()->syncLive('test:live:4');
    assert_equals(2, count(fx_live_panel_ids($module->live()->board(false))), 'both matches start live');

    // Both matches have finished. The feed also emits one row the normalizer
    // cannot use (no external id, no kickoff) — a real and common occurrence.
    $provider->setLiveFixtures([['competition' => 'Premier League', 'status' => 'LIVE']]);
    $sync = $module->fixtures()->syncLive('test:live:5');

    assert_equals([], fx_live_panel_ids($module->live()->board(false)),
        'the bad row is reported as an error but does not protect finished matches');
    assert_true((int) ($sync['expiredLive'] ?? 0) >= 2, 'both finished matches were taken down in that sweep');
    assert_true(count((array) ($sync['errors'] ?? [])) >= 1, 'and the malformed row is still reported, not silently dropped');
});

test('football live panel: a stale card is withheld and expired even if the live job stopped running', function () {
    // Defect 3: the backstop. If the sweep dies, reading the board must not
    // keep serving yesterday's cards.
    $kickoff = gmdate('c', time() - 5400);
    [$repo, $provider, $module] = fx_fb_harness(
        [fx_fb_row('fx-live-8', $kickoff, 'Manchester City', 'Everton', '10', '20', 'LIVE', 2, 2, 90)],
        ['skipHistory' => true]
    );
    fx_fb_sync_today($module, gmdate('Y-m-d', strtotime($kickoff)));
    $provider->setLiveFixtures([fx_fb_row('fx-live-8', $kickoff, 'Manchester City', 'Everton', '10', '20', 'LIVE', 2, 2, 90)]);
    $module->fixtures()->syncLive('test:live:6');
    $fixtureId = (int) (fx_live_fixture($repo, 'fx-live-8')['id'] ?? 0);
    assert_true($fixtureId > 0, 'the live fixture is stored');

    // The live job stops. Time passes well beyond the staleness window.
    fx_live_age_confirmation($repo, $fixtureId, 6 * 3600);

    $board = $module->live()->board(false);
    assert_equals([], fx_live_panel_ids($board), 'the stale card is withheld from the panel');

    // Withholding alone is not enough: the row must actually leave the live
    // set, or every other reader still has to re-derive the same judgement.
    $row = fx_live_fixture($repo, 'fx-live-8');
    assert_equals('STALE_LIVE', (string) ($row['status'] ?? ''), 'reading the board also expired the abandoned row');
    assert_equals(true, fx_live_unconfirmed($row), 'and cleared its live confirmation');
});

test('football live panel: a match still being reported is never expired by the backstop', function () {
    // The other half of defect 3: the backstop must not remove a real match.
    $kickoff = gmdate('c', time() - 1800);
    $row = fx_fb_row('fx-live-9', $kickoff, 'Manchester City', 'Everton', '10', '20', 'LIVE', 1, 1, 30);
    [$repo, $provider, $module] = fx_fb_harness([$row], ['skipHistory' => true]);
    fx_fb_sync_today($module, gmdate('Y-m-d', strtotime($kickoff)));
    $provider->setLiveFixtures([$row]);
    $module->fixtures()->syncLive('test:live:7');

    for ($i = 0; $i < 3; $i++) {
        $board = $module->live()->board(false);
        assert_equals(1, count(fx_live_panel_ids($board)), 'a confirmed live match survives repeated reads');
    }
    assert_equals('LIVE', (string) (fx_live_fixture($repo, 'fx-live-9')['status'] ?? ''),
        'and is still stored as live');
});

test('football live panel: one broken feed does not stop the live sweep while another is healthy', function () {
    // Defect 4. Production runs several feeds; one of them had been failing
    // for days. Backoff must be judged per capability across all providers
    // that can serve it, because the gateway falls through to the next one.
    $healthy = new FxFootballProvider(fx_fb_provider_data([]), 'apifootball');
    $broken = new FxFootballProvider(fx_fb_provider_data([]), 'thesportsdb');

    $repo = new FootballRepositoryStub();
    $audit = new class implements \AIWorkforce\Persistence\AuditRepository {
        public array $events = [];
        public function emit(string $type, string $summary, array $detail = [], string $actor = 'system'): void {}
        public function recent(int $limit = 100): array { return []; }
    };
    $manager = new SportsProviderManager();
    $manager->register($healthy);
    $manager->register($broken);
    $module = new \AIWorkforce\Football\FootballIntelligence($repo, $manager, $audit, new FootballConfiguration());

    // Give the live job real work, or it is skipped for a reason we are not testing.
    $kickoff = gmdate('c', time() - 1800);
    $repo->saveFixture(fx_live_provider_id($repo), [
        'externalId' => 'fx-live-10', 'kickoff' => $kickoff, 'status' => 'LIVE',
        'homeTeam' => 'Manchester City', 'awayTeam' => 'Everton', 'homeTeamId' => '10', 'awayTeamId' => '20',
        'liveConfirmed' => true,
    ]);

    $policy = new RefreshPolicy($repo, new FootballConfiguration(), $module->gateway());
    assert_equals(true, $policy->evaluate('football-live')['due'], 'with both feeds healthy the live job is due');

    // The secondary feed breaks and is put into backoff. The primary is fine.
    $module->gateway()->recordFailure('thesportsdb', 'simulated persistent outage');

    $evaluation = $policy->evaluate('football-live');
    assert_not_equals('PROVIDER_BACKOFF', (string) $evaluation['reason'],
        'one broken feed must not stop the live sweep while a healthy feed can serve it');
    assert_equals(true, $evaluation['due'], 'the live job still runs on the healthy feed');

    // When every capable feed is down, the job does wait — and says until when.
    $module->gateway()->recordFailure('apifootball', 'simulated outage');
    $blocked = $policy->evaluate('football-live');
    assert_equals(false, $blocked['due'], 'with no healthy feed left the job waits');
    assert_equals('PROVIDER_BACKOFF', (string) $blocked['reason'], 'and names the outage');
    assert_true((string) ($blocked['detail']['until'] ?? '') !== '', 'reporting when it may try again');
});

test('football live panel: the board reports whether the live sweep itself is current', function () {
    // "No match is live" and "the sweep has not run for hours" look identical
    // on a panel that only renders rows. The payload must distinguish them so
    // the page can say which one it is.
    [$repo, $provider, $module] = fx_fb_harness([], ['skipHistory' => true]);

    $board = $module->live()->board(false);
    $freshness = (array) ($board['provider'] ?? []);
    assert_equals('NEVER_RUN', (string) ($freshness['state'] ?? ''), 'a board with no live sweep behind it says so');

    $module->fixtures()->syncLive('test:live:8');
    $freshness = (array) ($module->live()->board(false)['provider'] ?? []);
    assert_equals('CURRENT', (string) ($freshness['state'] ?? ''), 'after a sweep the feed is reported current');
    assert_true((string) ($freshness['lastSweepAt'] ?? '') !== '', 'with the moment it last ran');
    assert_true(is_int($freshness['ageSeconds'] ?? null), 'and how long ago that was');
});

test('football live panel: the page filters and auto-refreshes on confirmed live state only', function () {
    $view = fx_fb_read('application/views/football/index.php');

    // The server-rendered first paint applies the same status test as the API.
    assert_contains('FixtureSyncService::LIVE_STATUSES', $view, 'the first paint filters on live statuses');

    // Auto-refresh: no button, no navigation, and a poll that keeps the panel
    // current without the reader doing anything.
    assert_contains("fetch('/api/football/fixtures/live'", $view, 'the panel refreshes itself from the live endpoint');
    assert_contains('document.hidden', $view, 'a hidden tab does not poll');
    assert_true(!str_contains($view, 'Refresh live'), 'there is no manual refresh button to press');

    // Cards are reconciled by fixture id, and the ones no longer reported are
    // removed — the DOM-level guarantee that old matches cannot linger.
    assert_contains('data-football-live-id', $view, 'cards are keyed by fixture id');
    assert_contains('.remove()', $view, 'cards that are no longer live are removed from the list');

    // The reader is told when the feed is behind rather than being shown an
    // empty panel that implies nothing is being played.
    assert_contains("provider.state === 'BEHIND'", $view, 'the page reacts to a stalled sweep');
    assert_contains('Live feed is behind', $view, 'and says so in words');
});
