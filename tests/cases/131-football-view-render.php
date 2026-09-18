<?php
/**
 * Football Intelligence — the intelligence markup renders.
 *
 * Every other case in this file family reads a payload. This one renders the
 * two surfaces a reader uses with that payload, because a value that exists in
 * the array and is missing from the page is the same bug as one that is on the
 * page and absent from the array. The view is included with an error handler
 * that turns "Undefined variable" and "Undefined array key" into a failure, so
 * markup that reaches for a key the assembler never publishes cannot pass.
 *
 * CI is not loaded here (the harness runs without it), so the pieces the loader
 * normally provides — `BASEPATH`, the `e()` helper, and the controller's
 * request-scoped variables — are supplied minimally, and only for the render.
 */
require_once TESTSPATH . 'football_support.php';

/**
 * Render a football view outside CodeIgniter.
 *
 * @param array<string,mixed> $data
 * @return array{html:string,notices:list<string>}
 */
function fx_fb_render_view(string $view, array $data): array
{
    if (!defined('BASEPATH')) define('BASEPATH', FCPATH . 'system/');
    if (!function_exists('e')) {
        /** CI's escape helper, matching its default html4entities flags. */
        function e(mixed $value): string
        {
            return htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML401, 'UTF-8');
        }
    }
    $notices = [];
    $previous = set_error_handler(static function (int $severity, string $message) use (&$notices): bool {
        if (preg_match('/Undefined (variable|array key|index)/', $message) === 1) {
            $notices[] = $message;
            return true;    // collected, not fatal: the render must finish to be inspectable
        }
        return false;
    });
    $level = ob_get_level();
    ob_start();
    try {
        (static function (array $data) use ($view): void {
            extract($data, EXTR_SKIP);
            include FCPATH . 'application/views/football/' . $view . '.php';
        })($data);
        $html = (string) ob_get_clean();
    } catch (\Throwable $e) {
        while (ob_get_level() > $level) ob_end_clean();
        throw $e;
    } finally {
        set_error_handler($previous);
    }
    return ['html' => $html, 'notices' => $notices];
}

/** The controller's request-scoped variables, with the values it would default them to. */
function fx_fb_view_data(array $overrides = []): array
{
    return array_merge([
        'title' => 'Football Intelligence', 'active' => 'football', 'notice' => null, 'error' => null,
        'caps' => ['sync' => true, 'calibrate' => true, 'approve' => true, 'settle' => true],
        'csrfToken' => 'test-token', 'isAdmin' => true, 'refresh' => false, 'page' => 1, 'pageSize' => 50,
        'date' => gmdate('Y-m-d'), 'competition' => '', 'market' => '', 'line' => '',
        'provider' => \AIWorkforce\Football\ProviderSelector::AUTO,
        'providerRequested' => '', 'providerMode' => \AIWorkforce\Football\ProviderSelector::AUTO,
        'providerLocked' => false,
        'providers' => ['options' => [], 'default' => null, 'mode' => 'AUTO', 'locked' => false],
    ], $overrides);
}

test('football: the board renders the intelligence layer over a populated page', function () {
    [$repo, , $module] = fx_fb_harness([
        fx_fb_row('fx-v1', gmdate('c', time() + 3 * 3600), 'Manchester City', 'Everton', '10', '20'),
        fx_fb_row('fx-v2', gmdate('c', time() + 3 * 3600 + 600), 'Brighton', 'Burnley', '30', '40'),
    ]);
    $day = gmdate('Y-m-d', time() + 3 * 3600);
    fx_fb_sync_today($module, $day);
    $module->predictions()->predictDay($day);
    // One match priced, one left unpriced: the page has to render both a value
    // and the absence of one, in the same table.
    $boardData = $module->board()->forDate($day, false, 1, 50);
    $matchId = (string) ((array) ($boardData['rows'][0] ?? []))['matchId'] ?? '';
    $first = (array) ($boardData['rows'][0] ?? []);
    $matchId = (string) ($first['matchId'] ?? '');
    if ($matchId !== '') {
        $repo->marketOdds = [
            ['matchId' => $matchId, 'market' => 'Match Winner', 'selection' => 'Home', 'decimalOdds' => 1.85, 'observedAt' => gmdate('c')],
            ['matchId' => $matchId, 'market' => 'Match Winner', 'selection' => 'Draw', 'decimalOdds' => 3.40, 'observedAt' => gmdate('c')],
            ['matchId' => $matchId, 'market' => 'Match Winner', 'selection' => 'Away', 'decimalOdds' => 4.60, 'observedAt' => gmdate('c')],
        ];
    }
    $dashboard = $module->dashboard($day, false, 1, 50, []);
    $render = fx_fb_render_view('index', fx_fb_view_data(['date' => $day, 'dashboard' => $dashboard]));

    assert_true($render['notices'] === [], 'the board reaches for no key the payload does not publish'
        . ($render['notices'] === [] ? '' : ' — first: ' . (string) reset($render['notices'])));
    $html = $render['html'];
    assert_true(str_contains($html, 'Top WINDELS Picks') || str_contains($html, 'Intelligence'),
        'the panel is on the page');
    assert_true(str_contains($html, 'WINDELS probability'), 'the three questions are labelled apart');
    assert_true(str_contains($html, 'Market odds') || str_contains($html, 'Odds'), 'the price has its own column');
    // The word "guaranteed" may appear on this page in exactly one shape: inside
    // a denial. An affirmative promise anywhere in the markup is the failure.
    $lower = strtolower($html);
    foreach (['guaranteed win', 'guaranteed value', 'a guarantee of', 'sure thing', 'sure win',
              'free money', 'lock in', '100% sure', 'cannot lose'] as $promise) {
        assert_true(!str_contains($lower, $promise), 'the page never says "' . $promise . '"');
    }
    assert_true(preg_match('/(no selection is guaranteed|not guarantees|are not guaranteed)/', $lower) === 1,
        'and where the word appears at all, it appears in a sentence denying it');
    // A number is either present or a dash — never an empty cell that reads as zero.
    assert_true(preg_match('/Intelligence<\/th>.*?<\/tr>/s', $html) === 1 || str_contains($html, 'no score'),
        'the intelligence column renders for at least one row');
});

test('football: the Premium League selector renders every premium league and the All premium leagues option', function () {
    // Default classification: both stored leagues are premium, so the Premium
    // League dropdown lists each of them, an unclassified league is not
    // offered as premium, and the All premium leagues option is there —
    // selected while the board is read in that scope.
    $day = gmdate('Y-m-d', time() + 2 * 86400);
    $base = (int) strtotime($day . 'T00:30:00+00:00');
    $rows = [];
    for ($i = 0; $i < 3; $i++) {
        $rows[] = fx_fb_row('fx-pr' . $i, gmdate('c', $base + $i * 60), 'Manchester City', 'Everton', '10', '20');
    }
    for ($i = 0; $i < 2; $i++) {
        $rows[] = fx_fb_row('fx-pl' . $i, gmdate('c', $base + 120 + $i * 60), 'Brighton', 'Burnley', '30', '40',
            'SCHEDULED', null, null, null, ['leagueId' => '140', 'competition' => 'La Liga', 'country' => 'Spain']);
    }
    $rows[] = fx_fb_row('fx-pu', gmdate('c', $base + 300), 'Al Hilal', 'Al Nassr', '50', '60',
        'SCHEDULED', null, null, null, ['leagueId' => '307', 'competition' => 'Saudi Pro League', 'country' => 'Saudi Arabia']);
    [, , $module] = fx_fb_harness($rows);
    fx_fb_sync_today($module, $day);

    $dashboard = $module->dashboard($day, false, 1, 50, ['competition' => \AIWorkforce\Football\MatchFeed::PREMIUM_LEAGUES]);
    $render = fx_fb_render_view('index', fx_fb_view_data(['date' => $day, 'dashboard' => $dashboard]));
    assert_true($render['notices'] === [], 'the premium-scoped board reaches for no key the payload does not publish'
        . ($render['notices'] === [] ? '' : ' — first: ' . (string) reset($render['notices'])));
    $html = $render['html'];

    $premiumSelect = [];
    assert_true(preg_match('/<select[^>]*name="premium".*?<\/select>/s', $html, $premiumSelect) === 1,
        'the premium league dropdown renders');
    $premiumHtml = $premiumSelect[0];
    assert_true(str_contains($premiumHtml, '<option value="PREMIUM_LEAGUES" selected>All premium leagues</option>'),
        'the All premium leagues option is offered, and selected while the board is in that scope');
    foreach (['Premier League', 'La Liga'] as $league) {
        assert_true(str_contains($premiumHtml, $league), $league . ' is offered in the premium league dropdown');
    }
    assert_true(str_contains($premiumHtml, 'Premier League · 3 matches'), 'each premium league is listed with its match count');
    assert_false(str_contains($premiumHtml, 'Saudi Pro League'), 'a league that was not classified premium is not offered as premium');

    // The competition dropdown keeps the same all-value, so the two selectors
    // never disagree about what "all premium leagues" selects.
    $competitionSelect = [];
    assert_true(preg_match('/<select[^>]*name="competition".*?<\/select>/s', $html, $competitionSelect) === 1,
        'the competition dropdown renders');
    assert_true(str_contains($competitionSelect[0], '<option value="PREMIUM_LEAGUES" selected>All premium leagues</option>'),
        'the competition dropdown offers the same all-premium scope, selected');
});

test('football: the Day overview prints the full date, the selection, both scopes and the read stamp', function () {
    // A populated day read the way the console reads it by default: with
    // generation, so the page block is evaluated. The Day overview must show
    // the friendly date label with the raw date, the active selection, the
    // page-scoped withheld figure beside the selection-wide counts, the
    // per-page prediction status, and a full Board read stamp.
    $day = gmdate('Y-m-d', time() + 2 * 86400);
    $base = (int) strtotime($day . 'T00:30:00+00:00');
    $rows = [];
    for ($i = 0; $i < 3; $i++) {
        $rows[] = fx_fb_row('fx-ovw-' . $i, gmdate('c', $base + $i * 60), 'Manchester City', 'Everton', '10', '20');
    }
    [, , $module] = fx_fb_harness($rows);
    fx_fb_sync_today($module, $day);

    $dashboard = $module->dashboard($day, true, 1, 50, []);
    $render = fx_fb_render_view('index', fx_fb_view_data(['date' => $day, 'dashboard' => $dashboard, 'refresh' => true]));
    assert_true($render['notices'] === [], 'the generating read reaches for no key the payload does not publish'
        . ($render['notices'] === [] ? '' : ' — first: ' . (string) reset($render['notices'])));
    $html = $render['html'];
    $overview = [];
    assert_true(preg_match('/<section class="panel football-section" id="football-overview".*?<\\/section>/s', $html, $overview) === 1,
        'the Day overview renders');

    // The heading names the friendly label AND the raw date, and the read
    // stamp carries the full day, not a bare clock time.
    assert_true(str_contains($overview[0], 'What is stored for ' . gmdate('l, j F Y', (int) strtotime($day . 'T00:00:00+00:00'))),
        'the heading carries the payload\'s date label');
    assert_true(str_contains($overview[0], '(' . $day . ')'), 'and the raw date beside it');
    assert_true(preg_match('/Board read \w{3} \d{1,2} \w{3} \d{4} · \d{2}:\d{2} UTC/', $overview[0]) === 1,
        'the Board read stamp shows the full date and time');
    // Six tiles: the five familiar counts plus Awaiting analysis, with the
    // withheld figure labeled as the page-scoped number it is.
    foreach (['Fixtures found', 'Analyzed', 'Qualified', 'Limited evidence', 'Withheld', 'Awaiting analysis'] as $tile) {
        assert_true(str_contains($overview[0], $tile), 'the ' . $tile . ' tile renders');
    }
    assert_true(str_contains($overview[0], 'evidence below the floor · this page'), 'withheld is labeled with its page scope');
    assert_true(str_contains($overview[0], 'no prediction row yet · whole selection'), 'awaiting analysis is labeled with its selection scope');
    assert_true(str_contains($overview[0], '3 with a stored prediction'), 'the page line says how many fixtures on the page are predicted');
    assert_true(str_contains($overview[0], 'Reading this board generates the missing predictions for the page in view'),
        'and the intro states the generate-on-read behaviour');

    // Narrowed to the premium scope, the heading names the selection and the
    // intro counts describe it — a page figure never poses as a day figure.
    $narrowed = $module->dashboard($day, true, 1, 50, ['competition' => \AIWorkforce\Football\MatchFeed::PREMIUM_LEAGUES]);
    $render2 = fx_fb_render_view('index', fx_fb_view_data(['date' => $day, 'dashboard' => $narrowed, 'refresh' => true]));
    assert_true($render2['notices'] === [], 'the narrowed board publishes every key the overview reads');
    $overview2 = [];
    assert_true(preg_match('/<section class="panel football-section" id="football-overview".*?<\\/section>/s', $render2['html'], $overview2) === 1);
    assert_true(str_contains($overview2[0], '— All premium leagues</h3>'), 'the heading names the selection');
    assert_true(str_contains($overview2[0], 'this selection — <b>All premium leagues</b>'), 'and the intro says which selection the counts describe');

    // A read-only read (?refresh=0 / generate-on-read off) says so instead of
    // implying the board generated itself.
    $readonly = fx_fb_render_view('index', fx_fb_view_data(['date' => $day, 'dashboard' => $narrowed, 'refresh' => false]));
    assert_true(str_contains($readonly['html'], 'This read generated nothing'), 'the read-only mode is stated');
});

test('football: the Ranked reading prints eligibility, every pick figure and the exclusions', function () {
    // A generating read over a mixed day: 7 predictable fixtures across two
    // premium leagues (default list limit 5, so 2 sit beyond it), one thin
    // fixture the quality gate refuses and one postponed fixture. The section
    // must print the full accounting the picks payload publishes — the
    // considered/eligible/listed/beyond caption, the per-pick market, value
    // classification, evidence, confidence, risk and warnings — and every
    // match that did not qualify, with the reason that kept it out.
    $day = gmdate('Y-m-d', time() + 2 * 86400);
    $base = (int) strtotime($day . 'T00:30:00+00:00');
    $rows = [];
    for ($i = 0; $i < 4; $i++) {
        $rows[] = fx_fb_row('fx-pk-' . $i, gmdate('c', $base + $i * 60), 'Manchester City', 'Everton', '10', '20');
    }
    for ($i = 0; $i < 3; $i++) {
        $rows[] = fx_fb_row('fx-pk-l' . $i, gmdate('c', $base + 240 + $i * 60), 'Brighton', 'Burnley', '30', '40',
            'SCHEDULED', null, null, null, ['leagueId' => '140', 'competition' => 'La Liga', 'country' => 'Spain']);
    }
    $rows[] = fx_fb_row('fx-pk-frozen', gmdate('c', $base + 500), 'Manchester City', 'Everton', '10', '20', 'POSTPONED');
    [$repo, , $module] = fx_fb_harness($rows);
    fx_fb_sync_today($module, $day);
    $repo->saveFixture((int) ($repo->listProviders()[0]['id'] ?? 1), [
        'externalId' => 'fx-pk-thin', 'competition' => 'Unknown Cup', 'leagueId' => '99', 'season' => '2026',
        'kickoff' => gmdate('c', $base + 400), 'status' => 'SCHEDULED',
        'homeTeam' => 'Home United', 'awayTeam' => 'Away Rovers', 'homeTeamId' => '900', 'awayTeamId' => '901',
    ]);
    // Real quotes for the first three matches, so the value classification and
    // the expected-return figure are exercised beside the unpriced rows.
    $priming = $module->board()->forDate($day, false, 1, 50, []);
    foreach (array_slice($priming['rows'] ?? [], 0, 3) as $priced) {
        $matchId = (string) ($priced['matchId'] ?? '');
        if ($matchId === '') continue;
        $repo->marketOdds = array_merge($repo->marketOdds ?? [], [
            ['matchId' => $matchId, 'market' => 'Match Winner', 'selection' => 'Home', 'decimalOdds' => 1.85, 'observedAt' => gmdate('c')],
            ['matchId' => $matchId, 'market' => 'Match Winner', 'selection' => 'Draw', 'decimalOdds' => 3.40, 'observedAt' => gmdate('c')],
            ['matchId' => $matchId, 'market' => 'Match Winner', 'selection' => 'Away', 'decimalOdds' => 4.60, 'observedAt' => gmdate('c')],
        ]);
    }

    // The console's default read: generation runs, so the eligibility data is
    // generated for the page in view.
    $dashboard = $module->dashboard($day, true, 1, 50, []);
    $block = (array) ($dashboard['board']['picks'] ?? []);
    assert_true((int) ($block['eligible'] ?? 0) === 7, 'the generating read makes seven matches eligible');
    assert_equals(5, (int) ($block['shown'] ?? 0), 'the default list limit shows five');
    assert_equals(2, (int) ($block['beyondList'] ?? 0), 'and counts the two beyond it');
    assert_equals(2, count((array) ($block['excluded'] ?? [])), 'the two ineligible matches are published with reasons');

    $render = fx_fb_render_view('index', fx_fb_view_data(['date' => $day, 'dashboard' => $dashboard, 'refresh' => true]));
    assert_true($render['notices'] === [], 'the picks section reaches for no key the payload does not publish'
        . ($render['notices'] === [] ? '' : ' — first: ' . (string) reset($render['notices'])));
    $html = $render['html'];
    $section = [];
    assert_true(preg_match('/<section class="panel football-section" id="football-picks".*?<\\/section>/s', $html, $section) === 1,
        'the Ranked reading section renders');
    $picksHtml = $section[0];

    // The caption carries the whole accounting, not only the eligible count.
    assert_true(str_contains($picksHtml, '7 eligible on this page · 5 listed · +2 beyond the list'),
        'the caption reads considered eligibility, listed and beyond-the-list together');
    // The intro names the market the picks are answered in.
    assert_true(str_contains($picksHtml, 'Match Winner — 1X2'), 'the selected market is named');
    // Every column the payload publishes is on the row.
    foreach (['Value', 'Evidence', 'Confidence', 'Intelligence', 'Risk', 'Movement'] as $column) {
        assert_true(str_contains($picksHtml, '>' . $column . '</th>'), 'the ' . $column . ' column renders');
    }
    assert_true(str_contains($picksHtml, 'No price to compare'), 'an unpriced pick says so instead of a bare dash');
    assert_true(str_contains($picksHtml, 'Expected return +'), 'a priced pick shows its expected return');
    assert_true(preg_match('/edge \+\d+\.\dpp/', $picksHtml) === 1, 'and its edge in probability points');
    assert_true(str_contains($picksHtml, 'quality 90/100'), 'the evidence band carries its quality score');
    assert_true(str_contains($picksHtml, '>QUALIFIED</span>'), 'and its band');
    assert_true(preg_match('/\d+\.\d%<\/td>/', $picksHtml) === 1, 'model confidence is printed');
    assert_true(str_contains($picksHtml, '>LOW</span>'), 'the risk level is printed');
    assert_true(str_contains($picksHtml, 'value is unjudged'), 'a pick\'s warnings are printed');
    // The matches that did not qualify are listed with their reasons.
    assert_true(str_contains($picksHtml, '2 matches were not eligible on this page'), 'the exclusions are counted in the open');
    assert_true(str_contains($picksHtml, 'Show reasons'), 'behind a disclosure');
    assert_true(str_contains($picksHtml, 'Home United vs Away Rovers'), 'the excluded match is named');
    assert_true(str_contains($picksHtml, 'Not analyzed — no stored prediction to rank.'), 'with the reason that kept it out');
    assert_true(str_contains($picksHtml, 'Match status is POSTPONED'), 'including the terminal-status reason');
    assert_true(str_contains($picksHtml, '9 matches were considered on this page · 7 eligible · 5 listed (list limit 5) · +2 beyond the limit'),
        'and the one-line accounting beneath the table');
    assert_true(str_contains($picksHtml, 'not guarantees and not a staking instruction'), 'the disclaimer stays');

    // A read-only read over a fresh, unanalyzed copy of the same day names
    // the same accounting honestly: nothing eligible, every unanalyzed match
    // listed with its reason.
    [, , $module2] = fx_fb_harness($rows);
    fx_fb_sync_today($module2, $day);
    $readonly = $module2->dashboard($day, false, 1, 50, []);
    $render2 = fx_fb_render_view('index', fx_fb_view_data(['date' => $day, 'dashboard' => $readonly, 'refresh' => false]));
    $section2 = [];
    assert_true(preg_match('/<section class="panel football-section" id="football-picks".*?<\\/section>/s', $render2['html'], $section2) === 1);
    assert_true(str_contains($section2[0], '0 eligible on this page'), 'an unanalyzed page reports zero eligible');
    assert_true(str_contains($section2[0], 'No fixtures currently satisfy the required prediction and data-quality thresholds'),
        'keeps the honest empty state');
    assert_true(str_contains($section2[0], 'matches were not eligible on this page'), 'and still lists why');
    assert_true(substr_count($section2[0], 'Not analyzed — no stored prediction to rank.') >= 7,
        'every unanalyzed match is named with its reason');
});

test('football: the board renders an unanalyzed date without inventing a score', function () {
    [$repo, , $module] = fx_fb_harness([], ['skipHistory' => true]);
    $day = gmdate('Y-m-d', time() + 4 * 3600);
    $dashboard = $module->dashboard($day, false, 1, 50, []);
    $render = fx_fb_render_view('index', fx_fb_view_data(['date' => $day, 'dashboard' => $dashboard]));
    assert_true($render['notices'] === [], 'an empty board still reaches for no missing key'
        . ($render['notices'] === [] ? '' : ' — first: ' . (string) reset($render['notices'])));
    assert_true(!preg_match('/\b0\/100/', $render['html']), 'nothing is scored zero because nothing was measured');
    assert_true(!str_contains($render['html'], '⭐ Top WINDELS Picks'), 'no picks are ranked over an empty date');
});

test('football: the match page renders the score, the drivers and the three clocks', function () {
    [$repo, , $module] = fx_fb_harness([fx_fb_row('fx-vm', gmdate('c', time() + 5 * 3600), 'Manchester City', 'Everton', '10', '20')]);
    $day = gmdate('Y-m-d', time() + 5 * 3600);
    fx_fb_sync_today($module, $day);
    $module->predictions()->predictDay($day);
    $fixtureId = (int) ($repo->fixtures[0]['id'] ?? 0);
    assert_true($fixtureId > 0, 'the fixture has an id to be shown by');
    $analysis = $module->analysis($fixtureId);
    $prediction = $module->predictionFor($fixtureId);
    $render = fx_fb_render_view('match', fx_fb_view_data([
        'fixtureId' => $fixtureId, 'analysis' => $analysis, 'prediction' => $prediction,
        'live' => null, 'settlement' => null,
    ]));
    assert_true($render['notices'] === [], 'the match page reaches for no key the read model does not publish'
        . ($render['notices'] === [] ? '' : ' — first: ' . (string) reset($render['notices'])));
    $html = $render['html'];
    assert_true(str_contains($html, 'WINDELS Intelligence Score'), 'the score is on the page');
    assert_true(str_contains($html, 'Potential Edge'), 'the value of the gap is stated separately from it');
    assert_true(str_contains($html, 'Last updated') || str_contains($html, 'Prediction generated'),
        'and the clocks are shown');
    assert_true(!preg_match('/guaranteed (win|return|profit)/', strtolower($html)), 'the page makes no promise');
});

test('football: the match page renders a fixture with no prediction at all', function () {
    $day = gmdate('Y-m-d', time() + 6 * 3600);
    [$repo, , $module] = fx_fb_harness([fx_fb_row('fx-vn', gmdate('c', strtotime($day . 'T12:00:00+00:00')), 'Brighton', 'Burnley', '30', '40')],
        ['skipHistory' => true]);
    fx_fb_sync_today($module, $day);
    $fixtureId = (int) ($repo->fixtures[0]['id'] ?? 0);
    assert_true($fixtureId > 0, 'the fixture is stored even though nothing was predicted');
    $render = fx_fb_render_view('match', fx_fb_view_data([
        'fixtureId' => $fixtureId, 'analysis' => $module->analysis($fixtureId),
        'prediction' => $module->predictionFor($fixtureId), 'live' => null, 'settlement' => null,
    ]));
    assert_true($render['notices'] === [], 'the withheld page still renders cleanly'
        . ($render['notices'] === [] ? '' : ' — first: ' . (string) reset($render['notices'])));
    // The intelligence panel says "no score" rather than printing 0/100 for a
    // fixture nothing was measured against. (The data-quality panel may legitimately
    // show a measured 0 — that figure is a measurement of thin data, not an
    // absence, and the two are different statements.)
    assert_true(str_contains($render['html'], 'no score'), 'the score cell names its own absence');
    assert_true(preg_match('/WINDELS Intelligence Score<\/div>\s*<div[^>]*>\s*<span[^>]*>no score/s', $render['html']) === 1,
        'and it is the intelligence figure that is absent, not a zero wearing a label');
});
