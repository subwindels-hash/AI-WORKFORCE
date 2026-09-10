<?php
/**
 * Football Intelligence — paginated match feed (50 per page, 50 per generation).
 *
 * The module was specified with one rule at its centre:
 *
 * > Never regenerate an already-generated match simply because the user changed
 * > pages. Generate a maximum of 50 new matches per generation request, persist
 * > them, and paginate through the persisted results.
 *
 * Everything here exists to make that rule falsifiable. The cases deliberately
 * use more matches than fit on a page (120), because a suite that only ever
 * pages over 3 matches would pass whether or not paging worked. Each one asks a
 * question the module could answer wrongly:
 *
 *  - does page 2 hold the matches page 1 did not, with no overlap and no gaps?
 *  - is `limit` clamped at 50 server-side, and is the clamp *reported*?
 *  - does generating page 1 twice write 50 rows or 100?
 *  - does moving back to page 1 after generating page 2 regenerate anything?
 *  - does paging cost a provider call or an engine run?
 *  - is a match identified once (`match_id`), and can it be stored twice?
 */
require_once TESTSPATH . 'football_support.php';

use AIWorkforce\Football\FootballConfiguration;
use AIWorkforce\Football\FootballIntelligence;
use AIWorkforce\Football\MatchFeed;
use AIWorkforce\Football\PredictionService;
use AIWorkforce\Football\QualityBand;

/**
 * A date with `$count` fixtures stored and sync'd, in the future so every one
 * of them has an open pre-match slot. Returns [repo, provider, module, day].
 */
function fx_fb_paged_day(int $count, array $config = []): array
{
    // Two days out, so no fixture can be "kicked off already" however late the
    // suite runs, and every kickoff lands inside the same calendar day.
    $day = gmdate('Y-m-d', time() + 2 * 86400);
    $base = (int) strtotime($day . 'T00:30:00+00:00');
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        // A minute apart each keeps the page order meaningful: page 2 really is
        // the later 50 matches, not an arbitrary 50.
        $rows[] = fx_fb_row('fx-page-' . $i, gmdate('c', $base + $i * 60), 'Manchester City', 'Everton', '10', '20');
    }
    [$repo, $provider, $module] = fx_fb_harness($rows, [], $config);
    fx_fb_sync_today($module, $day);
    return [$repo, $provider, $module, $day];
}

/** @return list<string> the match_id of every match on a page */
function fx_fb_match_ids(array $page): array
{
    return array_values(array_map(static fn(array $m): string => (string) ($m['matchId'] ?? ''), $page['matches'] ?? []));
}

/**
 * The DDL a dialect installs, read from the file rather than from a live
 * connection. The duplicate guard is a database constraint, so it is asserted
 * where it is declared.
 */
function fx_fb_schema(string $dialect): string
{
    $relative = match ($dialect) {
        'mysql' => 'application/database/football_intelligence.mysql.sql',
        'pgsql' => 'application/database/football_intelligence.pgsql.sql',
        'sqlite' => 'application/database/football_intelligence.sqlite.sql',
        default => 'database/production.sql',
    };
    $path = defined('FCPATH') ? FCPATH . $relative : dirname(TESTSPATH) . '/' . $relative;
    if (!is_file($path)) throw new RuntimeException('cannot read the ' . $dialect . ' schema at ' . $path);
    return (string) file_get_contents($path);
}

test('football: a page is 50 matches, and no caller may ask for more', function () {
    [$repo, , $module, $day] = fx_fb_paged_day(120);
    $feed = $module->feed();

    $page = $feed->page($day, 1);
    assert_equals(50, count($page['matches']), 'the first page holds 50 matches');
    assert_equals(50, (int) $page['pagination']['limit'], 'the page size is 50');
    assert_equals(50, (int) $page['pagination']['maxLimit'], 'and 50 is the hard maximum');

    // ?limit=1000 is a request this module must refuse rather than honour.
    $greedy = $feed->page($day, 1, 1000);
    assert_equals(50, count($greedy['matches']), 'a request for 1000 matches is served 50');
    assert_equals(50, (int) $greedy['pagination']['limit'], 'with the applied limit reported back as 50');
    $notes = implode(' ', (array) ($greedy['request']['notes'] ?? []));
    assert_true(str_contains($notes, 'exceeds the hard maximum of 50'), 'and the caller is told, not silently trimmed');

    // The ceiling is a constant so no configuration can quietly lift it.
    assert_equals(50, MatchFeed::MAX_PAGE_SIZE, 'the hard maximum is 50');
    assert_equals(50, $module->config()->matchPageSize(), 'and the configured page size defaults to it');
    assert_equals(50, (new FootballConfiguration(['WINDELS_FOOTBALL_MATCH_PAGE_SIZE' => 5000]))->matchPageSize(),
        'a configuration asking for 5000 per page still gets 50');
});

test('football: page 2 holds the matches page 1 did not — no overlap, no gaps', function () {
    [$repo, , $module, $day] = fx_fb_paged_day(120);
    $feed = $module->feed();

    $one = $feed->page($day, 1);
    $two = $feed->page($day, 2);
    $three = $feed->page($day, 3);

    assert_equals(50, count($one['matches']), 'page 1: 50');
    assert_equals(50, count($two['matches']), 'page 2: 50');
    assert_equals(20, count($three['matches']), 'page 3: the remaining 20 — a short last page is not padded');

    assert_equals([1, 2, 3], [(int) $one['pagination']['page'], (int) $two['pagination']['page'], (int) $three['pagination']['page']]);
    assert_equals(120, (int) $one['pagination']['totalMatches'], 'the total is a COUNT over the date, not the size of a page');
    assert_equals(3, (int) $one['pagination']['totalPages'], '120 matches at 50 per page is 3 pages');
    assert_equals([1, 50], [(int) $one['pagination']['from'], (int) $one['pagination']['to']], 'page 1 covers 1–50');
    assert_equals([51, 100], [(int) $two['pagination']['from'], (int) $two['pagination']['to']], 'page 2 covers 51–100');
    assert_equals([101, 120], [(int) $three['pagination']['from'], (int) $three['pagination']['to']], 'page 3 covers 101–120');

    assert_true((bool) $one['pagination']['hasNext'], 'page 1 has a next page');
    assert_false((bool) $one['pagination']['hasPrevious'], 'and no previous one');
    assert_true((bool) $two['pagination']['hasPrevious'] && (bool) $two['pagination']['hasNext'], 'page 2 has both');
    assert_equals(2, (int) $one['pagination']['nextPage'], 'next is page 2');
    assert_equals(1, (int) $two['pagination']['previousPage'], 'previous is page 1');
    assert_false((bool) $three['pagination']['hasNext'], 'the last page has no next');

    // The three pages are a partition: together they are the date, twice over
    // they are not.
    $ids = array_merge(fx_fb_match_ids($one), fx_fb_match_ids($two), fx_fb_match_ids($three));
    assert_equals(120, count($ids), 'the pages hold 120 match entries');
    assert_equals(120, count(array_unique($ids)), 'every match_id appears exactly once across the pages');
    assert_equals([], array_intersect(fx_fb_match_ids($one), fx_fb_match_ids($two)), 'page 1 and page 2 do not overlap');

    // Ascending kickoff: page 2 really is later than page 1.
    $last = end($one['matches']);
    $first = reset($two['matches']);
    assert_true((string) $first['kickoff'] > (string) $last['kickoff'], 'page 2 starts after page 1 ends');
});

test('football: a page past the last one is an empty page with a stated reason, not a silent wrap', function () {
    [$repo, , $module, $day] = fx_fb_paged_day(120);
    $page = $module->feed()->page($day, 9);
    assert_equals([], $page['matches'], 'page 9 of 3 holds no match');
    assert_equals(9, (int) $page['pagination']['page'], 'the requested page is reported');
    assert_equals(3, (int) $page['pagination']['totalPages'], 'against the real page count');
    assert_false((bool) $page['pagination']['hasNext'], 'and it is not treated as the last page');
    assert_true(str_contains((string) $page['message'], 'past the last page'), 'with the reason stated');
    $notes = implode(' ', (array) ($page['request']['notes'] ?? []));
    assert_true(str_contains($notes, 'past the last page'), 'and repeated in the request notes');
});

test('football: an unusable page or limit falls back to the documented default and says so', function () {
    [$repo, , $module, $day] = fx_fb_paged_day(5);
    $feed = $module->feed();

    $notes = [];
    assert_equals([1, 50], $feed->resolve(0, 0, $notes), 'page 0 → 1 and limit 0 → 50');
    assert_true(count($notes) === 2, 'both substitutions are recorded');
    $notes = [];
    assert_equals([1, 50], $feed->resolve(-3, -1, $notes), 'negative values fall back too');
    $notes = [];
    assert_equals([100000, 50], $feed->resolve(999999, 9999, $notes), 'absurd values are clamped, not rejected silently');
    assert_true(str_contains(implode(' ', $notes), 'hard maximum of 50'), 'the clamp is explained');
    $notes = [];
    assert_equals([4, 25], $feed->resolve(4, 25, $notes), 'a valid page and size are used exactly as given');
    assert_equals([], $notes, 'with no note when nothing had to change');

    // And the read endpoint routes them through the same rule.
    $source = fx_fb_read('application/controllers/Api_football.php');
    assert_true(str_contains($source, "RequestParams::int(\$g, 'page'"), 'page is read through RequestParams');
    assert_true(str_contains($source, "RequestParams::int(\$g, 'limit'"), 'limit is read through RequestParams');
    assert_true(str_contains($source, 'MatchFeed::MAX_PAGE_SIZE'), 'and clamped to the documented maximum');
});

test('football: generating page 1 writes 50 predictions, and generating it again writes none', function () {
    [$repo, , $module, $day] = fx_fb_paged_day(120);
    $feed = $module->feed();

    $first = $feed->generate($day, 1);
    assert_equals(50, (int) $first['generation']['generated'], 'one generation request produces 50 new predictions');
    assert_equals(0, (int) $first['generation']['reused'], 'there was nothing to reuse yet');
    assert_equals(50, $repo->countPredictions(['date' => $day, 'kind' => PredictionService::KIND_PRE_MATCH]), 'and 50 rows are stored');
    $idsAfterFirst = array_column($repo->listPredictions(['date' => $day, 'kind' => PredictionService::KIND_PRE_MATCH], 500), 'id');

    // The second request is the whole point of the module: the same page, with
    // every match already predicted, must cost the engine nothing.
    $second = $feed->generate($day, 1);
    assert_equals(0, (int) $second['generation']['generated'], 'the same page generates nothing the second time');
    assert_equals(50, (int) $second['generation']['reused'], 'all 50 were served from the stored rows');
    assert_equals(50, (int) $second['generation']['skippedStored'], 'and reported as skipped, not as generated');
    assert_equals(50, $repo->countPredictions(['date' => $day, 'kind' => PredictionService::KIND_PRE_MATCH]),
        'the second request did not add rows');
    $idsAfterSecond = array_column($repo->listPredictions(['date' => $day, 'kind' => PredictionService::KIND_PRE_MATCH], 500), 'id');
    assert_equals($idsAfterFirst, $idsAfterSecond, 'the stored prediction ids are identical — nothing was rewritten');
});

test('football: page 2 generates only its own 50, and page 1 is left exactly as it was', function () {
    [$repo, , $module, $day] = fx_fb_paged_day(120);
    $feed = $module->feed();

    $one = $feed->generate($day, 1);
    $pageOneIds = array_column($repo->listPredictions(['date' => $day, 'kind' => PredictionService::KIND_PRE_MATCH], 500), 'id');
    $pageOneGeneratedAt = [];
    foreach ($repo->listPredictions(['date' => $day, 'kind' => PredictionService::KIND_PRE_MATCH], 500) as $row) {
        $pageOneGeneratedAt[(int) $row['fixture_id']] = (string) $row['generated_at'];
    }

    $two = $feed->generate($day, 2);
    assert_equals(50, (int) $two['generation']['generated'], 'page 2 generates its own 50');
    assert_equals(0, (int) $two['generation']['reused'], 'none of them existed yet');
    assert_equals(100, $repo->countPredictions(['date' => $day, 'kind' => PredictionService::KIND_PRE_MATCH]), '100 rows now: 50 + 50');

    // Page 1's rows are untouched — same ids, same timestamps.
    $still = [];
    foreach ($repo->listPredictions(['date' => $day, 'kind' => PredictionService::KIND_PRE_MATCH], 500) as $row) {
        $fixtureId = (int) $row['fixture_id'];
        if (isset($pageOneGeneratedAt[$fixtureId])) $still[$fixtureId] = (string) $row['generated_at'];
    }
    assert_equals($pageOneGeneratedAt, $still, 'the 50 predictions from page 1 were not regenerated by page 2');

    // ...and going back to page 1 generates nothing at all.
    $back = $feed->generate($day, 1);
    assert_equals(0, (int) $back['generation']['generated'], 'returning to page 1 regenerates nothing');
    assert_equals(50, (int) $back['generation']['reused'], 'its 50 are reused');
    assert_equals(100, $repo->countPredictions(['date' => $day, 'kind' => PredictionService::KIND_PRE_MATCH]), 'still 100 rows');

    // The last page holds the remaining 20.
    $three = $feed->generate($day, 3);
    assert_equals(20, (int) $three['generation']['generated'], 'the short last page generates its 20');
    assert_equals(120, $repo->countPredictions(['date' => $day, 'kind' => PredictionService::KIND_PRE_MATCH]), '120 rows in total');
    assert_equals(0, (int) $three['generation']['remainingOnDate'], 'with nothing left on the date');
    assert_true(count($pageOneIds) === 50, 'page 1 still has its 50 prediction ids');
});

test('football: no generation request ever produces more than 50 new predictions', function () {
    // 120 matches, all missing, and the caller asks for the largest page the
    // API accepts: the batch must still stop at 50.
    [$repo, , $module, $day] = fx_fb_paged_day(120);
    $result = $module->feed()->generate($day, 1, MatchFeed::MAX_PAGE_SIZE);
    assert_equals(50, (int) $result['generation']['generated'], 'a single request is capped at 50');
    assert_equals(50, (int) $result['generation']['batchLimit'], 'the cap is reported as the batch limit');
    assert_equals(70, (int) $result['generation']['remainingOnDate'], 'and the other 70 are reported as still outstanding');

    // The engine itself refuses to exceed the cap even when handed a whole day
    // and told it may write analysis-limit many.
    $batch = $module->predictions()->predictMissing(
        $repo->listFixtures(['date' => $day], 500),
        9999,
        PredictionService::KIND_PRE_MATCH,
    );
    assert_equals(50, (int) $batch['limit'], 'predictMissing clamps its own limit to 50');
    assert_true((int) $batch['generated'] <= 50, 'and generated at most 50');
    assert_true((int) $batch['deferred'] > 0, 'the rest are deferred to the next request, not silently generated');
});

test('football: a generation request only sends matches that have no prediction', function () {
    [$repo, , $module, $day] = fx_fb_paged_day(120);
    $feed = $module->feed();
    $feed->generate($day, 1);

    // Delete the prediction of one match on page 1, then generate page 1 again:
    // exactly one new prediction may appear, and only for that match.
    $fixtures = $repo->listFixtures(['date' => $day], 50);
    $victim = $fixtures[7];
    $before = $repo->listPredictions(['date' => $day, 'kind' => PredictionService::KIND_PRE_MATCH], 500);
    $victimPrediction = $repo->listPredictionsForFixtures([(int) $victim['id']], PredictionService::KIND_PRE_MATCH)[(int) $victim['id']] ?? null;
    assert_not_null($victimPrediction, 'the victim had a prediction to remove');
    foreach ($repo->predictions as $index => $row) {
        if ((string) ($row['id'] ?? '') === (string) $victimPrediction['id']) unset($repo->predictions[$index]);
    }
    $repo->predictions = array_values($repo->predictions);
    assert_equals(49, $repo->countPredictions(['date' => $day, 'kind' => PredictionService::KIND_PRE_MATCH]), 'one row was removed');

    // A fresh module over the same repository, so no per-request cache can
    // remember the row that was deleted behind its back.
    $again = new FootballIntelligence($repo, $module->providerManager(), null, new FootballConfiguration());
    $result = $again->feed()->generate($day, 1);
    assert_equals(1, (int) $result['generation']['generated'], 'only the missing match was generated');
    assert_equals(49, (int) $result['generation']['reused'], 'the other 49 on the page were reused');
    assert_equals(50, $repo->countPredictions(['date' => $day, 'kind' => PredictionService::KIND_PRE_MATCH]), 'back to 50 rows');
    assert_true(count($before) === 50, 'the page held 50 predictions before the removal');
});

test('football: one match_id cannot hold two predictions for the same model version', function () {
    [$repo, , $module, $day] = fx_fb_paged_day(10);
    $feed = $module->feed();

    foreach ([1, 2, 3] as $attempt) {
        $feed->generate($day, 1);
    }
    assert_equals(10, $repo->countPredictions(['date' => $day, 'kind' => PredictionService::KIND_PRE_MATCH]),
        'three identical generation requests leave 10 rows, not 30');
    $ids = array_column($repo->listPredictions(['date' => $day, 'kind' => PredictionService::KIND_PRE_MATCH], 100), 'id');
    assert_equals(count($ids), count(array_unique($ids)), 'every stored prediction id is unique');

    // The identity a prediction is keyed by is provider-scoped, so two providers
    // that both use "1234" as their own id cannot collide.
    $fixture = $repo->listFixtures(['date' => $day], 1)[0];
    assert_equals('apifootball:' . (string) $fixture['external_id'], MatchFeed::matchId($fixture), 'match_id is provider-scoped');
    assert_equals(MatchFeed::matchId($fixture), MatchFeed::matchId($fixture), 'and stable across calls');

    // Every schema enforces it: UNIQUE(fixture_id, prediction_kind, model_version_id)
    // is the database-level duplicate guard behind these rules.
    foreach (['mysql', 'pgsql', 'sqlite'] as $dialect) {
        $ddl = fx_fb_schema($dialect);
        assert_true(str_contains($ddl, 'football_match_predictions'), $dialect . ' declares the prediction table');
        assert_true((bool) preg_match('/UNIQUE\s*(KEY\s*\w+\s*)?\(\s*"?fixture_id"?\s*,\s*"?prediction_kind"?\s*,\s*"?model_version_id"?\s*\)/i', $ddl),
            $dialect . ' enforces UNIQUE(fixture_id, prediction_kind, model_version_id) — one prediction per match_id per model version');
    }
    // And the fixture table keeps one row per provider match_id.
    assert_true((bool) preg_match('/UNIQUE\s*(KEY\s*\w+\s*)?\(\s*"?provider_id"?\s*,\s*"?external_id"?\s*\)/i', fx_fb_schema('mysql')),
        'a fixture is unique per (provider, external id)');
});

test('football: a stored prediction is reused, and it says which model version and date made it', function () {
    [$repo, , $module, $day] = fx_fb_paged_day(3);
    $generated = $module->feed()->generate($day, 1);
    $read = $module->feed()->page($day, 1);

    assert_equals(3, count($read['matches']), 'three matches on the page');
    assert_equals(0, (int) $read['generation']['generated'], 'reading a page generates nothing');
    assert_equals(3, (int) $generated['generation']['generated'], 'the page was generated on request');
    foreach ($read['matches'] as $match) {
        assert_equals(MatchFeed::SOURCE_STORED, (string) $match['predictionSource'], 'the prediction came from storage');
        assert_equals('ANALYZED', (string) $match['analysisState'], 'and the match is reported as analyzed');
        assert_not_null($match['prediction'], 'with the stored prediction attached');
        // What distinguishes one prediction from a later refresh of the same
        // match: which model version produced it, and when.
        assert_not_null($match['prediction']['modelVersionId'], 'the prediction cites its model version');
        assert_equals(gmdate('Y-m-d'), (string) $match['prediction']['predictionDate'], 'and the date it was produced');
        assert_true((string) $match['prediction']['generatedAt'] !== '', 'with a timestamp');
    }
    // The generation block names the model that would be used.
    assert_not_null($read['generation']['predictionModelVersion'], 'the generation block names the model version');
    assert_equals(gmdate('Y-m-d'), (string) $read['generation']['predictionDate'], 'and the prediction date');
});

test('football: paging costs no provider call and no engine run', function () {
    [$repo, $provider, $module, $day] = fx_fb_paged_day(120);
    $module->feed()->generate($day, 1);
    $callsAfterGeneration = $provider->calls;
    $rowsAfterGeneration = count($repo->predictions);

    foreach ([1, 2, 3, 2, 1] as $page) {
        $module->feed()->page($day, $page);
    }
    assert_equals($callsAfterGeneration, $provider->calls, 'moving between pages makes no provider request');
    assert_equals($rowsAfterGeneration, count($repo->predictions), 'and writes no prediction row');

    // A read page reports that it did not generate anything.
    $read = $module->feed()->page($day, 1);
    assert_false((bool) $read['generation']['requested'], 'generation was not requested');
    assert_equals(0, (int) $read['generation']['generated'], 'so nothing was generated');
});

test('football: the board pages too, over the same 50-match windows', function () {
    [$repo, , $module, $day] = fx_fb_paged_day(120);
    $board = $module->board();

    // One page at a time; every card on a page belongs to that page's matches.
    foreach ([1, 2, 3] as $page) {
        $module->feed()->generate($day, $page);
    }
    $first = $board->forDate($day, false, 1);
    $second = $board->forDate($day, false, 2);
    assert_equals(50, count($first['cards']), 'the board renders page 1 as 50 cards');
    assert_equals(50, count($second['cards']), 'and page 2 as 50');
    assert_equals(120, (int) $first['summary']['analyzed'], 'while the summary counts the whole date');
    assert_equals(120, (int) $first['summary']['fixtures'], 'fixtures found is the whole date too');
    assert_equals([], array_intersect(array_column($first['cards'], 'fixtureId'), array_column($second['cards'], 'fixtureId')),
        'the two pages of cards are disjoint');
    assert_equals(3, (int) $first['pagination']['totalPages'], 'the board reports the page count');
    assert_true((bool) $first['pagination']['hasNext'], 'and a next page');
    assert_equals(50, (int) $first['pagination']['maxLimit'], 'with the same hard maximum');

    // refresh=true fills in the page on screen and no more.
    [$repo2, , $fresh, $day2] = fx_fb_paged_day(120);
    $refreshed = $fresh->board()->forDate($day2, true, 2);
    assert_equals(50, $repo2->countPredictions(['date' => $day2, 'kind' => PredictionService::KIND_PRE_MATCH]),
        'refreshing page 2 wrote 50 predictions — not the whole date');
    assert_equals(50, count($refreshed['cards']), 'and the refreshed page shows them');
    $again = $fresh->board()->forDate($day2, true, 2);
    assert_equals(50, $repo2->countPredictions(['date' => $day2, 'kind' => PredictionService::KIND_PRE_MATCH]),
        'refreshing the same page twice does not add rows');
    assert_equals(50, count($again['cards']), 'and the page still shows its 50');
});

test('football: a second sweep of the same date regenerates nothing', function () {
    [$repo, , $module, $day] = fx_fb_paged_day(30);
    $first = $module->predictions()->predictDay($day);
    assert_equals(30, (int) $first['analyzed'], 'the first pass analyzed all 30');
    assert_equals(30, (int) $first['qualified'] + (int) $first['limited'] + (int) $first['rejected'], 'and every one was classified');

    $second = $module->predictions()->predictDay($day);
    assert_equals(0, (int) $second['analyzed'], 'the second pass analyzed nothing new');
    assert_equals(30, (int) $second['skipped'], 'all 30 were skipped as already predicted');
    assert_equals(30, $repo->countPredictions(['date' => $day, 'kind' => PredictionService::KIND_PRE_MATCH]), 'and there are still 30 rows');

    // predictDay still batches: one page at a time, never a thousand at once.
    assert_equals(MatchFeed::MAX_PAGE_SIZE, (int) $first['batchSize'], 'the batch size is the page size');
    assert_true((int) $first['batches'] >= 1, 'and the work was done in batches');
});

test('football: the paged endpoints are routed, guarded and clamped', function () {
    $routes = fx_fb_read('application/config/routes.php');
    $api = fx_fb_read('application/controllers/Api_football.php');
    $console = fx_fb_read('application/controllers/Football.php');
    $view = fx_fb_read('application/views/football/index.php');

    assert_contains("\$route['api/football/matches'] = 'api_football/matches';", $routes, 'the paginated feed is routed');
    assert_contains("\$route['api/football/matches/generate'] = 'api_football/generate_matches';", $routes, 'and its generation endpoint');
    assert_contains('public function matches(', $api, 'Api_football::matches() exists');
    assert_contains('public function generate_matches(', $api, 'Api_football::generate_matches() exists');
    // Reading is sports.view; generating is sports.manage.
    $matches = substr((string) $api, (int) strpos((string) $api, 'public function matches('), 1800);
    assert_contains("requirePermission('sports.view'", $matches, 'reading the feed needs sports.view');
    assert_contains("requirePermission('sports.manage'", $matches, 'generating on a read needs sports.manage');
    assert_true(str_contains(substr((string) $api, (int) strpos((string) $api, 'public function generate_matches('), 900), "requirePermission('sports.manage'"),
        'the generation endpoint needs sports.manage');
    // The console is page-aware and the pager is rendered from the board payload.
    assert_contains("RequestParams::int(\$get, 'page'", $console, 'the console reads page through RequestParams');
    assert_contains("\$board['pagination']", $view, 'the console renders the pager from the board payload');
    assert_contains('Page ', $view, 'with "Page N of M"');
    assert_contains('matches per page', $view, 'and the page size');
    assert_contains('Generate this page', $view, 'plus one page-scoped generation action');
});

test('football: the feed payload is complete, finite and honest about what it did', function () {
    [$repo, , $module, $day] = fx_fb_paged_day(60);
    $page = $module->feed()->generate($day, 1);

    foreach (['state', 'date', 'matches', 'pagination', 'generation', 'summary', 'model', 'request', 'generatedAt'] as $key) {
        assert_true(array_key_exists($key, $page), 'the payload carries ' . $key);
    }
    $flat = [];
    $walk = function ($value, string $path) use (&$walk, &$flat): void {
        if (is_array($value)) { foreach ($value as $k => $v) $walk($v, $path === '' ? (string) $k : $path . '.' . $k); return; }
        $flat[$path] = $value;
    };
    $walk($page, '');
    foreach ($flat as $path => $value) {
        if (is_float($value)) assert_true(is_finite($value), $path . ' is finite');
    }
    assert_true((string) json_encode($page, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) !== '', 'the page encodes as JSON');

    // Every match row identifies itself and states where its prediction came from.
    foreach ($page['matches'] as $match) {
        assert_true((string) $match['matchId'] !== '', 'a match carries its match_id');
        assert_true((int) $match['fixtureId'] > 0, 'and its database id');
        assert_in_array((string) $match['predictionSource'], [MatchFeed::SOURCE_STORED, MatchFeed::SOURCE_GENERATED,
            MatchFeed::SOURCE_DEFERRED, MatchFeed::SOURCE_REFUSED, MatchFeed::SOURCE_FAILED], 'with a stated source');
        if ($match['prediction'] === null) {
            assert_not_null($match['predictionRefusal'], 'a match with no prediction states why');
        }
    }
    // An empty date is a state, never a fabricated match.
    $empty = $module->feed()->page(gmdate('Y-m-d', time() - 30 * 86400));
    assert_equals([], $empty['matches'], 'a date with no fixtures yields no matches');
    assert_equals('DATA_UNAVAILABLE', (string) $empty['state'], 'and says the data is unavailable');
    assert_equals(0, (int) $empty['pagination']['totalMatches'], 'with a zero total, not a guess');
    assert_equals(1, (int) $empty['pagination']['totalPages'], 'and one empty page');
});

test('football: a card below the lowest confidence tier is still reported, not dropped', function () {
    // Paging reads the board page by page, so a card that silently falls out of
    // every category would look like a match the pager lost. The tiers are
    // pinned high here so the deliberately uneven fixtures below all fall under
    // the lowest cut line whatever the model outputs — qualified on data
    // quality, below every confidence cut line. None of them may disappear.
    $day = gmdate('Y-m-d', time() + 2 * 86400);
    $combos = [['10', '30'], ['30', '20'], ['40', '30'], ['20', '10']];
    $names = ['10' => 'Manchester City', '20' => 'Everton', '30' => 'Brighton', '40' => 'Burnley'];
    $rows = [];
    foreach ($combos as $n => [$home, $away]) {
        $rows[] = fx_fb_row('fx-tier-' . $n, gmdate('c', (int) strtotime($day . 'T00:' . (30 + $n) . ':00+00:00')),
            $names[$home], $names[$away], $home, $away);
    }
    [, , $module] = fx_fb_harness($rows, [], ['WINDELS_FOOTBALL_TIER_HIGHEST' => '95',
        'WINDELS_FOOTBALL_TIER_STRONG' => '90', 'WINDELS_FOOTBALL_TIER_STANDARD' => '85']);
    fx_fb_sync_today($module, $day);
    $module->predictions()->predictDay($day);
    $board = $module->board()->forDate($day);

    $cards = (array) ($board['cards'] ?? []);
    assert_equals(4, count($cards), 'every fixture produced a card');

    $placed = [];
    $belowTier = [];
    foreach ((array) $board['categories'] as $index => $category) {
        foreach ((array) ($category['items'] ?? []) as $item) {
            $placed[] = (string) ($item['predictionId'] ?? '');
            if ((string) ($category['key'] ?? '') === 'developing') $belowTier[] = (string) ($item['predictionId'] ?? '');
        }
    }
    assert_equals(count($cards), count($placed), 'every card sits in exactly one category');
    assert_equals(count($placed), count(array_unique($placed)), 'and in no category twice');

    $tiers = $module->config()->confidenceTiers();
    $lowest = 100.0;
    foreach ($tiers as $tier) $lowest = min($lowest, (float) ($tier['min'] ?? 100));
    assert_equals(85.0, $lowest, 'the pinned tiers are honoured');
    $qualifiedBelow = array_values(array_filter($cards, static fn(array $c): bool =>
        (string) $c['band'] === QualityBand::QUALIFIED && $c['confidence'] !== null && (float) $c['confidence'] < $lowest));
    assert_true(count($qualifiedBelow) >= 1, 'the fixtures really do include a qualified card below the cut line');
    foreach ($qualifiedBelow as $card) {
        assert_in_array((string) $card['predictionId'], $belowTier, 'a qualified card below the tier is reported, not dropped');
        assert_equals('Developing', (string) $card['tier'], 'and labelled as well-evidenced but uncertain — not as thin data');
    }
    $last = (array) end($board['categories']);
    assert_equals('developing', (string) ($last['key'] ?? ''), 'the trailing category is Developing');
    assert_equals('below ' . number_format($lowest, 0), (string) ($last['range'] ?? ''), 'the trailing category states its own cut line');
    $keys = array_column((array) $board['categories'], 'key');
    assert_in_array('limitedData', $keys, 'and the Limited Data category still exists for thinner evidence');
});
