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
    if (!function_exists('crest')) {
        /** The layout helper normally loaded before a view; no image is needed in this render harness. */
        function crest(mixed $url, int $size = 18): string { return ''; }
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

test('football: an awaiting fixture shows real fixture and odds information instead of five placeholder badges', function () {
    $day = gmdate('Y-m-d', time() + 2 * 86400);
    $kickoff = $day . 'T12:00:00+00:00';
    [$repo, , $module] = fx_fb_harness([
        fx_fb_row('fx-awaiting-display', $kickoff, 'Manchester City', 'Everton', '10', '20'),
    ]);
    fx_fb_sync_today($module, $day);
    $first = $module->board()->forDate($day, false, 1, 50);
    $matchId = (string) ($first['rows'][0]['matchId'] ?? '');
    $repo->marketOdds = [
        ['matchId' => $matchId, 'market' => 'Match Winner', 'selection' => 'Home', 'decimalOdds' => 2.10,
            'observedAt' => gmdate('c'), 'provider' => 'fixture-feed'],
    ];

    $dashboard = $module->dashboard($day, false, 1, 50, []);
    $render = fx_fb_render_view('index', fx_fb_view_data(['date' => $day, 'dashboard' => $dashboard]));
    assert_true($render['notices'] === [], 'the awaiting-state presentation reaches for no missing key');
    $article = [];
    assert_true(preg_match('/<article class="football-fixture".*?<\/article>/s', $render['html'], $article) === 1,
        'the fixture card renders');
    $html = $article[0];
    assert_contains('DQ pending', $html, 'an unrun assessment is pending, never the fabricated score 0/100');
    assert_contains('Fixture data available', $html, 'the fixture badge uses the stored data_state');
    assert_contains('Awaiting analysis', $html, 'the next action is plain language rather than an internal enum');
    assert_true(!str_contains($html, 'DQ 0/100'), 'zero is not used as a missing DQ score');
    assert_true(!str_contains($html, 'Data DATA_UNAVAILABLE'), 'the missing row key no longer overrides real fixture coverage');
    assert_true(!str_contains($html, 'Unrated — insufficient data'), 'an unrun fixture is not falsely described as rejected');
    assert_true(!str_contains($html, 'UNKNOWN risk'), 'risk is omitted until a prediction exists');
    assert_true(!str_contains($html, '>NOT_ANALYZED<'), 'internal state names are not presented to the reader');
    assert_contains('2.10', $html, 'the selected real bookmaker quote remains visible before model analysis');
    assert_contains('47.6%', $html, 'its implied probability is calculated from that real quote');
    // This fixture HAS a stored provider price but no analysis, so the card must
    // say precisely that: the price is real, the model verdict is absent, and the
    // one is not allowed to pass for the other.
    assert_contains('Not scored', $html, 'the model comparison is openly unscored');
    assert_contains('the fixture is not analyzed, so no WINDELS probability, edge or risk is invented', $html,
        'the boundary between provider information and missing model analysis is explicit');
});

test('football: a quality-gated fixture shows its measured score and refusal instead of looking unanalysed', function () {
    $day = gmdate('Y-m-d', time() + 2 * 86400);
    [$repo, , $module] = fx_fb_harness();
    $providerId = (int) ($repo->listProviders()[0]['id'] ?? 1);
    $repo->saveFixture($providerId, [
        'externalId' => 'fx-withheld-display', 'competition' => 'Unknown Cup', 'leagueId' => '99', 'season' => '2026',
        'kickoff' => $day . 'T14:00:00+00:00', 'status' => 'SCHEDULED', 'dataState' => 'AVAILABLE',
        'homeTeam' => 'Home United', 'awayTeam' => 'Away Rovers', 'homeTeamId' => '900', 'awayTeamId' => '901',
    ]);

    $dashboard = $module->dashboard($day, true, 1, 50, []);
    $row = (array) ($dashboard['board']['rows'][0] ?? []);
    assert_true(is_numeric($row['dataQualityScore'] ?? null), 'the engine measured this refusal');
    $render = fx_fb_render_view('index', fx_fb_view_data(['date' => $day, 'dashboard' => $dashboard]));
    assert_true($render['notices'] === [], 'the refusal presentation reaches for no missing key');
    $article = [];
    assert_true(preg_match('/<article class="football-fixture".*?<\/article>/s', $render['html'], $article) === 1);
    $html = $article[0];
    assert_contains('DQ ' . (int) $row['dataQualityScore'] . '/100 · REJECTED', $html, 'the actual measured score and band are shown');
    assert_contains('Fixture data available', $html, 'fixture coverage is not conflated with model-input quality');
    assert_contains('Prediction withheld', $html, 'the card says that an assessment happened');
    assert_contains('The fixture was assessed, but no prediction was published.', $html);
    assert_contains((string) $row['assessmentReason'], $html, 'the engine refusal reason reaches the reader');
    assert_true(!str_contains($html, 'Unrated — insufficient data'));
    assert_true(!str_contains($html, 'UNKNOWN risk'));
    assert_true(!str_contains($html, '>NOT_ANALYZED<'));
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
    assert_true(str_contains($overview[0], 'evidence below the floor'), 'withheld is labeled with the gate that withheld it');
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
    // premium leagues (top list limit 5, so 2 are ranked below the marked
    // list), one thin fixture the quality gate refuses and one postponed
    // fixture. The section must print the full accounting the picks payload
    // publishes — the considered/eligible/listed caption with every eligible
    // pick listed (top list marked, the remainder ranked below a divider),
    // the per-pick market, value classification, evidence, confidence, risk
    // and warnings — and every match that did not qualify, with the reason
    // that kept it out.
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
    assert_equals(5, (int) ($block['shown'] ?? 0), 'the configured top list still shows five');
    assert_equals(2, (int) ($block['beyondList'] ?? 0), 'and counts the two beyond it');
    assert_equals(5, count((array) ($block['picks'] ?? [])), 'picks stays the capped top list for API consumers');
    assert_equals(7, count((array) ($block['allPicks'] ?? [])), 'allPicks publishes every eligible pick, ranked');
    assert_equals(2, count((array) ($block['excluded'] ?? [])), 'the two ineligible matches are published with reasons');

    $render = fx_fb_render_view('index', fx_fb_view_data(['date' => $day, 'dashboard' => $dashboard, 'refresh' => true]));
    assert_true($render['notices'] === [], 'the picks section reaches for no key the payload does not publish'
        . ($render['notices'] === [] ? '' : ' — first: ' . (string) reset($render['notices'])));
    $html = $render['html'];
    $section = [];
    assert_true(preg_match('/<section class="panel football-section" id="football-picks".*?<\\/section>/s', $html, $section) === 1,
        'the Ranked reading section renders');
    $picksHtml = $section[0];

    // The caption carries the whole accounting — and every eligible pick is
    // listed, the top list marked, the remainder ranked below a divider.
    assert_true(str_contains($picksHtml, '7 eligible on this page · all 7 listed · top 5 marked'),
        'the caption reads eligibility, all-listed and the marked top list together');
    foreach (range(1, 7) as $pickRank) {
        assert_true(str_contains($picksHtml, '<td class="mono">' . $pickRank . '</td>'),
            'rank ' . $pickRank . ' is rendered — every eligible pick is listed');
    }
    assert_true(str_contains($picksHtml, 'Beyond the top 5 — still eligible on this page, ranked below the marked list'),
        'the divider states where the configured top list ends');
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
    // This match WAS analyzed: the engine ran, scored its evidence below the
    // floor and refused to store a row. It is therefore named with the refusal
    // and the score that caused it, not with the "not analyzed" sentence that
    // belongs to a match nobody has asked about yet — the two are different
    // findings and only one of them is answered by generating the page.
    assert_true(str_contains($picksHtml, 'Prediction withheld — data quality'),
        'a match the engine analyzed and refused is named as withheld, not as unanalyzed');
    assert_true(preg_match('/data quality \d+\/100 is below the 50-point minimum/', $picksHtml) === 1,
        'with the score that kept it out');
    assert_true(str_contains($picksHtml, 'Match status is POSTPONED'), 'including the terminal-status reason');
    assert_true(str_contains($picksHtml, '9 matches were considered on this page · 7 eligible · all 7 listed (top 5 marked, +2 ranked below it)'),
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

// ─── the Day overview explains its own six counters ──────────────────────────

test('football: a populated Day overview carries no status strip — the counts are the information', function () {
    $day = gmdate('Y-m-d', time() + 3 * 3600);
    [, , $module] = fx_fb_harness([
        fx_fb_row('fx-day-ok1', gmdate('c', time() + 3 * 3600), 'Manchester City', 'Everton', '10', '20'),
        fx_fb_row('fx-day-ok2', gmdate('c', time() + 3 * 3600 + 900), 'Brighton', 'Burnley', '30', '40'),
    ]);
    fx_fb_sync_today($module, $day);
    $module->predictions()->predictDay($day);
    $dashboard = $module->dashboard($day, false, 1, 50, []);
    $status = $dashboard['dayStatus'];
    assert_equals('POPULATED', $status['state']);
    assert_true($status['fixtures'] >= 2, 'the status carries the same count the Fixtures found tile prints');
    assert_true($status['analyzed'] >= 1, 'and the analyzed count the board published');
    assert_true(str_contains($status['detail'], (string) $status['qualified'] . ' qualified'),
        'the populated detail summarizes the tiles it sits above');
    $render = fx_fb_render_view('index', fx_fb_view_data(['date' => $day, 'dashboard' => $dashboard]));
    assert_true($render['notices'] === [], 'the strip adds no key the payload does not publish'
        . ($render['notices'] === [] ? '' : ' — first: ' . (string) reset($render['notices'])));
    assert_true(!str_contains($render['html'], 'football-day-status'),
        'a populated overview renders no strip: six real counts need no apology');
    assert_true(str_contains($render['html'], 'Fixtures found'), 'the tiles render');
});

test('football: a stored-but-unanalyzed day says generation is the missing step', function () {
    $day = gmdate('Y-m-d', time() + 2 * 86400);
    [, , $module] = fx_fb_harness([
        fx_fb_row('fx-day-gen', $day . 'T12:00:00+00:00', 'Arsenal', 'Aston Villa', '10', '20'),
    ]);
    fx_fb_sync_today($module, $day);
    $dashboard = $module->dashboard($day, false, 1, 50, []);
    $status = $dashboard['dayStatus'];
    assert_equals('GENERATION_OFF', $status['state'], 'fixtures stored, nothing analyzed, this read did not generate');
    assert_equals(1, $status['fixtures']);
    assert_true(str_contains($status['detail'], 'Generate this page'), 'the state names the action that fills Analyzed');
    $render = fx_fb_render_view('index', fx_fb_view_data(['date' => $day, 'dashboard' => $dashboard]));
    assert_equals(1, substr_count($render['html'], 'id="football-day-status"'), 'exactly one strip renders');
    assert_true(str_contains($render['html'], 'AWAITING GENERATION'), 'with its badge');
    assert_true(str_contains($render['html'], 'Generate this page'), 'and the remedy in the strip sentence');
    assert_true(str_contains($render['html'], '1</div>'), 'the Fixtures found tile still shows its real count');
});

test('football: a day nothing has swept yet names the missing sweep', function () {
    $day = gmdate('Y-m-d', time() + 86400);
    $repo = new FootballRepositoryStub();
    $manager = new \AIWorkforce\Sports\Providers\SportsProviderManager();
    $manager->register(new FxFootballProvider(fx_fb_provider_data([
        fx_fb_row('fx-day-never', $day . 'T15:00:00+00:00', 'Chelsea', 'Fulham', '10', '20'),
    ])));
    $module = new \AIWorkforce\Football\FootballIntelligence($repo, $manager, null, new \AIWorkforce\Football\FootballConfiguration());
    assert_equals(null, $repo->lastSyncRun('FIXTURES'), 'no fixtures sweep has run in this scenario');
    $dashboard = $module->dashboard($day, false, 1, 50, []);
    $status = $dashboard['dayStatus'];
    assert_equals('NEVER_SYNCED', $status['state']);
    assert_true(str_contains($status['detail'], 'Sync this date'), 'the state names the operator action');
    assert_true(str_contains($status['detail'], 'fixtures job'), 'and the automatic one');
    assert_true(str_contains($status['detail'], 'Fixtures found'), 'and ties the remedy to the tile it fills');
    $render = fx_fb_render_view('index', fx_fb_view_data(['date' => $day, 'dashboard' => $dashboard]));
    assert_true(str_contains($render['html'], 'FIXTURES SWEEP PENDING'), 'the badge renders');
    assert_true(str_contains($render['html'], 'No fixture has been stored'), 'the empty state still states its own fact');
});

test('football: with no feed connected the overview names the connection, not the sweep', function () {
    $day = gmdate('Y-m-d');
    $module = new \AIWorkforce\Football\FootballIntelligence(
        new FootballRepositoryStub(), new \AIWorkforce\Sports\Providers\SportsProviderManager());
    $dashboard = $module->dashboard($day, false, 1, 50, []);
    $status = $dashboard['dayStatus'];
    assert_equals('NO_PROVIDER', $status['state']);
    assert_false($status['providerConfigured']);
    assert_true(str_contains($status['detail'], 'No football data provider is connected'));
    $render = fx_fb_render_view('index', fx_fb_view_data(['date' => $day, 'dashboard' => $dashboard]));
    assert_true(str_contains($render['html'], 'FIXTURES UNAVAILABLE'), 'the badge renders');
    assert_true(str_contains($render['html'], 'Data feed panel'), 'and the strip points at where a feed is connected');
});

test('football: a day the engine ran on but published nothing says that, not "awaiting"', function () {
    // A mixed empty day: one fixture the quality gate refused (thin Unknown
    // Cup evidence) and one whose kickoff has passed. The overview must own
    // that this read ran and nothing was published — naming the split, not
    // promising a sweep that happened.
    $day = gmdate('Y-m-d', time() + 2 * 86400);
    [$repo, , $module] = fx_fb_harness();
    $providerId = (int) ($repo->listProviders()[0]['id'] ?? 1);
    $repo->saveFixture($providerId, [
        'externalId' => 'fx-day-thin', 'competition' => 'Unknown Cup', 'leagueId' => '99', 'season' => '2026',
        'kickoff' => $day . 'T12:00:00+00:00', 'status' => 'SCHEDULED', 'dataState' => 'AVAILABLE',
        'homeTeam' => 'Home United', 'awayTeam' => 'Away Rovers', 'homeTeamId' => '900', 'awayTeamId' => '901',
    ]);
    // POSTPONED is closed by the engine's rule, independent of the clock —
    // the test stays deterministic at any hour it runs.
    $repo->saveFixture($providerId, [
        'externalId' => 'fx-day-closed', 'competition' => 'Premier League', 'leagueId' => '39', 'season' => '2026',
        'kickoff' => $day . 'T15:00:00+00:00', 'status' => 'POSTPONED',
        'homeTeam' => 'Leeds', 'awayTeam' => 'Leicester', 'homeTeamId' => '10', 'awayTeamId' => '20',
    ]);
    $dashboard = $module->dashboard($day, true, 1, 50, []);
    $status = $dashboard['dayStatus'];
    assert_equals('ANALYZED_NONE', $status['state']);
    assert_equals(2, $status['fixtures'], 'the fixtures are stored — it is the predictions that are absent');
    assert_equals(1, $status['withheld'], 'one was assessed and refused');
    assert_equals(1, $status['closed'], 'one is past kickoff');
    assert_equals(0, $status['awaiting'], 'none is merely waiting: each has a durable answer');
    assert_true(str_contains($status['detail'], 'no prediction was published'), 'the state says what actually happened');
    assert_true(str_contains($status['detail'], '1 past kickoff or void, 1 withheld by the data-quality gate'),
        'and names the split in the tiles\' own words');
    $render = fx_fb_render_view('index', fx_fb_view_data(['date' => $day, 'dashboard' => $dashboard, 'refresh' => true]));
    assert_true(str_contains($render['html'], 'NO PREDICTION PUBLISHED'), 'the badge renders');
    assert_true(str_contains($render['html'], 'Withheld'), 'and the Withheld tile it references still renders');
    assert_true(str_contains($render['html'], 'no prediction rows · 1 past kickoff or void · 1 withheld by the quality gate'),
        'the Analyzed tile names why it is zero');
    assert_true(str_contains($render['html'], 'no prediction row yet · 1 past kickoff or void · 1 withheld by the quality gate'),
        'and the Awaiting tile names its exclusions instead of "answered on this page"');
});

test('football: a day where every stored fixture is past kickoff says so on the tiles themselves', function () {
    // The state the live board shows as "4 found / 0 / 0 / 0 / 0 / excludes 4
    // answered on this page": every stored fixture has kicked off, so no
    // pre-match prediction can ever be created. The overview must say THAT,
    // not five zeros with a cryptic exclusion.
    // POSTPONED statuses keep the scenario deterministic at any hour: closed
    // by the engine's rule, not by where the clock happens to be.
    $day = gmdate('Y-m-d', time() + 2 * 86400);
    [, , $module] = fx_fb_harness([
        fx_fb_row('fx-allclosed-1', $day . 'T15:00:00+00:00', 'Leeds', 'Leicester', '10', '20', 'POSTPONED'),
        fx_fb_row('fx-allclosed-2', $day . 'T17:00:00+00:00', 'Wolves', 'Watford', '11', '21', 'POSTPONED'),
    ]);
    fx_fb_sync_today($module, $day);
    $dashboard = $module->dashboard($day, true, 1, 50, []);
    $status = $dashboard['dayStatus'];
    assert_equals('ALL_CLOSED', $status['state']);
    assert_equals(2, $status['fixtures']);
    assert_equals(2, $status['closed'], 'both fixtures are past kickoff');
    assert_equals(0, $status['awaiting'], 'nothing awaits: the window is closed for all of them');
    assert_true(str_contains($status['detail'], 'no pre-match prediction can be created for any of them'),
        'the state says the board is historical');
    assert_true(str_contains($status['detail'], 'nothing is back-filled'), 'and that nothing is invented after the fact');
    $render = fx_fb_render_view('index', fx_fb_view_data(['date' => $day, 'dashboard' => $dashboard, 'refresh' => true]));
    assert_true(str_contains($render['html'], 'ALL FIXTURES PAST KICKOFF'), 'the badge renders');
    assert_true(str_contains($render['html'], 'no prediction rows · 2 past kickoff or void'),
        'the Analyzed tile explains its own zero');
    assert_true(str_contains($render['html'], 'no prediction row yet · 2 past kickoff or void'),
        'and the Awaiting tile explains its zero with the same words');
    assert_true(!str_contains($render['html'], 'answered on this page'), 'the cryptic page-scoped exclusion is gone');
    assert_true(str_contains($render['html'], '2 closed (kickoff already passed)'), 'the page line keeps its own count');
});

test('football: a partly analyzed day reports the remainder as awaiting analysis', function () {
    // One predicted, one still open without an assessment: POPULATED, with
    // the remainder named on the tile and in the detail — the Awaiting count
    // is the true date-wide figure, not a page approximation.
    $day = gmdate('Y-m-d', time() + 2 * 86400);
    [$repo, , $module] = fx_fb_harness([
        fx_fb_row('fx-part-predicted', $day . 'T15:00:00+00:00', 'Manchester City', 'Everton', '10', '20'),
        fx_fb_row('fx-part-awaiting', $day . 'T17:30:00+00:00', 'Brighton', 'Burnley', '30', '40'),
    ]);
    fx_fb_sync_today($module, $day);
    $fixtureId = fx_131_fixture_id($repo, 'fx-part-predicted');
    $module->predictions()->predictFixture($fixtureId);
    $dashboard = $module->dashboard($day, false, 1, 50, []);
    $status = $dashboard['dayStatus'];
    assert_equals('POPULATED', $status['state']);
    assert_equals(1, $status['analyzed']);
    assert_equals(1, $status['awaiting'], 'the open unanalyzed fixture is the remainder');
    assert_true(str_contains($status['detail'], '1 awaiting analysis'), 'the detail carries the remainder');
    $render = fx_fb_render_view('index', fx_fb_view_data(['date' => $day, 'dashboard' => $dashboard]));
    assert_true(preg_match('/Awaiting analysis<\/div><div class="v">1<\/div>/', $render['html']) === 1,
        'the Awaiting tile shows the true date-wide count');
    assert_true(str_contains($render['html'], 'no prediction row yet · whole selection'),
        'with no durable exclusions the label stays whole-selection');
    assert_true(!str_contains($render['html'], 'football-day-status'), 'a populated day carries no strip');
});

test('football: a swept day the feed had nothing for says that, and offers the day-scoped sync', function () {
    // The sweep ran (a FIXTURES run is recorded) but the feed returned no
    // fixture for this date: an empty match day or an uncovered league
    // package — a different fact from "the sweep never ran".
    $day = gmdate('Y-m-d', time() + 86400);
    [, , $module] = fx_fb_harness([], ['skipHistory' => true]);
    fx_fb_sync_today($module, $day);
    $dashboard = $module->dashboard($day, false, 1, 50, []);
    $status = $dashboard['dayStatus'];
    assert_equals('SYNCED_NO_FIXTURES', $status['state']);
    assert_true(is_array($status['lastFixturesSync']), 'the state can cite the sweep it is describing');
    assert_true(str_contains($status['detail'], 'no fixture is stored for this date'), 'it states the actual fact');
    assert_true(str_contains($status['detail'], 'Sync this date'), 'and the day-scoped remedy');
    $render = fx_fb_render_view('index', fx_fb_view_data(['date' => $day, 'dashboard' => $dashboard]));
    assert_true(str_contains($render['html'], 'NO FIXTURES FOR THIS DATE'), 'the badge renders');
    assert_true(str_contains($render['html'], 'No fixture has been stored'), 'and the empty state keeps its own sentence');
});

/** The stored fixture id for one external id (the harness seeds history days too). */
function fx_131_fixture_id(FootballRepositoryStub $repo, string $external): int
{
    foreach ($repo->fixtures as $row) {
        if ((string) ($row['external_id'] ?? '') === $external) return (int) ($row['id'] ?? 0);
    }
    return 0;
}

// ─── the match page's Prediction overview explains its own empty state ────────

test('football: a stored open match with no analysis says what creates its prediction', function () {
    $day = gmdate('Y-m-d', time() + 2 * 86400);
    [$repo, , $module] = fx_fb_harness([
        fx_fb_row('fx-match-awaiting', $day . 'T15:00:00+00:00', 'Arsenal', 'Aston Villa', '10', '20'),
    ]);
    fx_fb_sync_today($module, $day);
    $fixtureId = fx_131_fixture_id($repo, 'fx-match-awaiting');
    assert_true($fixtureId > 0, 'the fixture is stored');
    $prediction = $module->predictionFor($fixtureId);
    assert_equals('NO_PREDICTION', $prediction['status']);
    $status = $prediction['statusDetail'];
    assert_equals('AWAITING_ANALYSIS', $status['state'], 'stored and open, but no engine assessment has run');
    assert_true(str_contains($status['detail'], 'no analysis has run'), 'the state states the actual fact');
    assert_true(str_contains($status['detail'], 'Analyze this match'), 'and names the action that fills the section');
    $render = fx_fb_render_view('match', fx_fb_view_data([
        'fixtureId' => $fixtureId, 'analysis' => $module->analysis($fixtureId),
        'prediction' => $prediction, 'live' => null, 'settlement' => null,
    ]));
    assert_true($render['notices'] === [], 'the strip reaches for no key the payload does not publish'
        . ($render['notices'] === [] ? '' : ' — first: ' . (string) reset($render['notices'])));
    assert_contains('id="football-prediction-status"', $render['html'], 'the strip renders');
    assert_contains('AWAITING ANALYSIS', $render['html'], 'with its badge');
    assert_contains('No prediction row is stored for this fixture', $render['html'], 'the fact stays stated');
    assert_contains('Analyze this match — generate odds prediction', $render['html'], 'and the action is offered');
});

test('football: an assessed-and-refused match presents its refusal as the prediction state', function () {
    $day = gmdate('Y-m-d', time() + 2 * 86400);
    [$repo, , $module] = fx_fb_harness();
    $providerId = (int) ($repo->listProviders()[0]['id'] ?? 1);
    $repo->saveFixture($providerId, [
        'externalId' => 'fx-match-withheld', 'competition' => 'Unknown Cup', 'leagueId' => '99', 'season' => '2026',
        'kickoff' => $day . 'T14:00:00+00:00', 'status' => 'SCHEDULED', 'dataState' => 'AVAILABLE',
        'homeTeam' => 'Home United', 'awayTeam' => 'Away Rovers', 'homeTeamId' => '900', 'awayTeamId' => '901',
    ]);
    $fixtureId = fx_131_fixture_id($repo, 'fx-match-withheld');
    // One generating pass: the engine answers and refuses, storing the assessment.
    $module->predictions()->predictFixture($fixtureId);
    $prediction = $module->predictionFor($fixtureId);
    $status = $prediction['statusDetail'];
    assert_equals('WITHHELD_BY_QUALITY_GATE', $status['state'], 'an assessment exists — a finding, not an absence');
    assert_true(is_numeric($status['dataQualityScore']), 'the refusal carries its measured score');
    assert_true(str_contains($status['detail'], 'no prediction:'), 'and the engine reason reaches the reader');
    $render = fx_fb_render_view('match', fx_fb_view_data([
        'fixtureId' => $fixtureId, 'analysis' => $module->analysis($fixtureId),
        'prediction' => $prediction, 'live' => null, 'settlement' => null,
    ]));
    assert_true($render['notices'] === [], 'renders with no missing key'
        . ($render['notices'] === [] ? '' : ' — first: ' . (string) reset($render['notices'])));
    assert_contains('ANALYZED — NOT PUBLISHED', $render['html'], 'the badge names the finding');
    assert_contains('WAS analyzed', $render['html'], 'the strip says the match WAS analyzed');
    assert_contains('Analyze this match — generate odds prediction', $render['html'],
        're-analysis stays available while the window is open');
});

test('football: a match whose kickoff passed explains why no prediction can exist', function () {
    $day = gmdate('Y-m-d', time() - 7200);
    [$repo, , $module] = fx_fb_harness([
        fx_fb_row('fx-match-closed', $day . 'T00:15:00+00:00', 'Leeds', 'Leicester', '10', '20'),
    ]);
    fx_fb_sync_today($module, $day);
    $fixtureId = fx_131_fixture_id($repo, 'fx-match-closed');
    $prediction = $module->predictionFor($fixtureId);
    $status = $prediction['statusDetail'];
    assert_equals('PRE_MATCH_CLOSED', $status['state']);
    assert_equals('KICKOFF_PASSED', $status['code'], 'the engine\'s own closed-slot rule supplies the code');
    assert_true(str_contains($status['detail'], 'Nothing is back-filled'), 'the state says nothing can be back-filled');
    $render = fx_fb_render_view('match', fx_fb_view_data([
        'fixtureId' => $fixtureId, 'analysis' => $module->analysis($fixtureId),
        'prediction' => $prediction, 'live' => null, 'settlement' => null,
    ]));
    assert_true($render['notices'] === [], 'renders with no missing key'
        . ($render['notices'] === [] ? '' : ' — first: ' . (string) reset($render['notices'])));
    assert_contains('PRE-MATCH WINDOW CLOSED', $render['html'], 'the badge renders');
    assert_true(!str_contains($render['html'], '/analyze'), 'no analyze form is offered on a slot that can never be written');
    assert_contains('settlement', $render['html'], 'the strip points at where a finished match\'s record lives');
});

test('football: predictionStatus reports PREDICTED once a row is stored, and the view needs no strip', function () {
    $day = gmdate('Y-m-d', time() + 3 * 3600);
    [, , $module] = fx_fb_harness([
        fx_fb_row('fx-match-predicted', gmdate('c', time() + 3 * 3600), 'Manchester City', 'Everton', '10', '20'),
    ]);
    fx_fb_sync_today($module, $day);
    $module->predictions()->predictDay($day);
    // Locate the stored fixture id through the board rows (the harness repo is not in scope here).
    $board = $module->board()->forDate($day, false, 1, 50);
    $fixtureId = (int) ($board['rows'][0]['fixtureId'] ?? 0);
    assert_true($fixtureId > 0, 'the fixture is on the board');
    $status = $module->predictionStatus($fixtureId);
    assert_equals('PREDICTED', $status['state']);
    $prediction = $module->predictionFor($fixtureId);
    assert_equals('OK', $prediction['status']);
    assert_true($prediction['statusDetail'] === null, 'a populated overview carries no status block');
    $render = fx_fb_render_view('match', fx_fb_view_data([
        'fixtureId' => $fixtureId, 'analysis' => $module->analysis($fixtureId),
        'prediction' => $prediction, 'live' => null, 'settlement' => null,
    ]));
    assert_true($render['notices'] === [], 'renders with no missing key');
    assert_true(!str_contains($render['html'], 'football-prediction-status'), 'no strip: the prediction IS the information');
    assert_contains('Model outcome', $render['html'], 'the prediction grid renders');
});
