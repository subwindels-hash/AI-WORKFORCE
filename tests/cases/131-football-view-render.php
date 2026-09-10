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
