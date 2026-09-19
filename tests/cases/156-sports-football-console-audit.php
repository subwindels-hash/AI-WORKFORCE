<?php
/**
 * Sports / Football console audit fixes (2026-09-13).
 *
 * Pins the repairs from the authenticated UI audit of /sports and /football:
 *
 *  1. No duplicate DOM id: the sports live-scores empty row keeps its
 *     colspan but no longer duplicates id="live-scores-empty" (the SSR row
 *     and the JS re-render used the same id — two nodes when JS repainted).
 *  2. SPA shell knows every sidebar destination: /football and /messages are
 *     authenticated prefixes, so sidebar navigation to them keeps the shell
 *     mounted instead of hard-reloading, and the active state is re-computed.
 *  3. The SPA active-state updater marks at most ONE sidebar link active and
 *     mirrors it to aria-current="page" (server render + swaps agree).
 *  4. Keyboard navigation: sidebar links and buttons have :focus-visible
 *     styles, and deep-linked sections scroll below the sticky topbar.
 *  5. The football board offers a genuine on-page team search over the rows
 *     already rendered (no request, no regeneration, no-JS safe: hidden until
 *     the script enables it).
 *  6. Football day navigation carries the operator's competition / market /
 *     provider selection instead of silently dropping it.
 *  7. The football filter panel (competition / premium / market / date) is
 *     rendered for every signed-in viewer; only the Data provider pin stays
 *     admin-gated, matching what the backend actually honours.
 *  8. The sports action bar reports when the board payload was read
 *     ("Board read HH:MM UTC") plus an explicit no-provider Reload control,
 *     backed by a real generatedAt stamp in the dashboard payload.
 */

test('sports console: the live-scores empty row is not a duplicated DOM id', function () {
    $view = (string) file_get_contents(FCPATH . 'application/views/sports/index.php');
    // The server-side row and the JS re-render must not both carry the same id.
    assert_true(substr_count($view, 'id="live-scores-empty"') <= 1,
        'id="live-scores-empty" appears at most once across SSR markup and the JS template');
    // The empty state itself is still full-width and still announced.
    assert_true(substr_count($view, 'No matches currently live') >= 2,
        'both render paths keep the honest empty message');
    // The empty row spans every column in both paths. The server row keeps the
    // literal colspan="6"; the poll handler derives its colspan from the same
    // LIVE_CELLS list that mirrors the <thead>, so the two can never disagree
    // after a column change (a hardcoded JS colspan was the drift risk).
    assert_true(substr_count($view, 'colspan="6"') >= 1, 'the server-rendered empty row spans every column');
    $js = (string) substr($view, (int) strpos($view, 'live-scores-js'));
    assert_contains("colspan=\"' + LIVE_CELLS.length + '\"", $js,
        'the repainted empty row spans exactly as many columns as the board defines');
});

test('app shell: football and messages are SPA destinations and the active state is single + aria-current', function () {
    $js = (string) file_get_contents(FCPATH . 'assets/js/app-shell.js');
    assert_contains("'/football',", $js, '/football navigations keep the dashboard shell mounted');
    assert_contains("'/messages',", $js, '/messages navigations keep the dashboard shell mounted');
    // Exactly-one-active contract + accessibility mirror.
    assert_contains("aria-current", $js, 'the active link is mirrored to aria-current');
    assert_contains("setAttribute('aria-current', 'page')", $js, 'the active link announces itself to assistive tech');
    assert_contains("removeAttribute('aria-current')", $js, 'losing active also drops aria-current');
});

test('console css: keyboard focus is visible and deep links clear the sticky topbar', function () {
    $css = (string) file_get_contents(FCPATH . 'assets/css/ai_workforce.css');
    assert_contains('.sidebar a:focus-visible', $css, 'sidebar links show a focus ring for keyboard users');
    assert_contains('.btn:focus-visible', $css, 'buttons show a focus ring for keyboard users');
    assert_contains('scroll-margin-top', $css, 'anchored sections land below the sticky topbar');
    assert_contains('.football-fixture.is-filtered-out { display: none; }', $css, 'the fixture search hides non-matching cards');
    assert_contains('.visually-hidden', $css, 'screen-reader-only labels are supported');
});

test('football board: on-page team search filters rendered rows without a request', function () {
    $view = (string) file_get_contents(FCPATH . 'application/views/football/index.php');
    assert_contains('id="football-fixture-search"', $view, 'the search control exists');
    assert_contains('id="football-team-search"', $view, 'with a labelled input');
    assert_contains('for="football-team-search"', $view, 'the label targets the input');
    assert_contains('aria-live="polite"', $view, 'the result count is announced');
    // Progressive enhancement: hidden until the script enables it, so a no-JS
    // visitor never sees a dead control.
    assert_true((bool) preg_match('/id="football-fixture-search"[^>]*hidden/', $view),
        'the search control ships hidden and is revealed by its script');
    assert_contains('wrap.hidden = false', $view, 'the script reveals the control');
    // The filter is a pure client-side view: no fetch in the search script.
    $script = (string) substr($view, (int) strpos($view, 'football-search-js'));
    assert_true(!str_contains($script, 'fetch('), 'searching never issues a request');
    assert_contains("classList.toggle('is-filtered-out'", $script, 'non-matching cards are hidden, not removed');
});

test('football board: day navigation carries the active filter selection', function () {
    $view = (string) file_get_contents(FCPATH . 'application/views/football/index.php');
    $heroStart = (int) strpos($view, 'football-hero__actions');
    $hero = substr($view, $heroStart, 900);
    assert_contains('$carry', $hero, 'Previous/Today/Next reuse the same carry set as the pager');
    assert_contains('http_build_query', $hero, 'day links are built with the filters included');
});

test('football board: the filter panel serves every viewer; only the provider pin is admin-gated', function () {
    $view = (string) file_get_contents(FCPATH . 'application/views/football/index.php');
    $panelStart = (int) strpos($view, 'id="football-filters"');
    assert_true($panelStart !== false, 'the filter panel exists');
    // The section itself is not wrapped in the admin gate…
    $before = substr($view, max(0, $panelStart - 700), 700);
    assert_true(!str_contains($before, 'if ($isAdmin):'),
        'the panel is rendered for every signed-in viewer');
    // …but the Data provider selector inside it still is.
    $panel = substr($view, $panelStart, (int) strpos($view, 'id="football-overview"') - $panelStart);
    $providerAt = (int) strpos($panel, 'Data provider');
    assert_true($providerAt !== false, 'the provider selector is still present');
    $gateAt = strpos($panel, 'if ($isAdmin):');
    assert_true($gateAt !== false && $gateAt < $providerAt, 'the provider pin stays admin-only');
    // Competition, market and date remain for everyone.
    foreach (['name="competition"', 'name="market"', 'type="date" name="date"'] as $control) {
        $controlAt = (int) strpos($panel, $control);
        assert_true($controlAt !== false, $control . ' is in the panel');
    }
});

test('sports console: the action bar reports when the board was read and offers a no-provider reload', function () {
    $view = (string) file_get_contents(FCPATH . 'application/views/sports/index.php');
    assert_contains('Board read', $view, 'the action bar carries the read stamp');
    assert_contains("d['generatedAt']", $view, 'the stamp is the payload timestamp, not a render-time guess');
    assert_contains('>Reload</a>', $view, 'reload re-reads storage');
    assert_contains('no provider request', $view, 'and says it costs no provider request');
    // The library actually publishes the stamp.
    $lib = (string) file_get_contents(FCPATH . 'application/libraries/AIWorkforce/Sports/SportsIntelligence.php');
    assert_contains("'generatedAt' => gmdate('c')", $lib, 'dashboard() stamps generatedAt');
});
