<?php
/**
 * Page-render performance guard — the bug behind "/sports is very slow".
 *
 * Sports::base() built its layout payload with
 * `$this->platform->providers->getAllHealth()`. That call probes EVERY
 * registered trading provider (Binance, Kraken, Alpaca, OANDA, ...) with a
 * blocking outbound HTTP request, and its cache lives on the ProviderManager
 * instance, so it expires with the request and never survives to the next one.
 * Measured cost on /sports: 5075 ms out of a 5.5 s response — 93% of TTFB —
 * while dashboard() itself took only 167 ms.
 *
 * Nothing rendered that data: layout/header.php reads only tradingMode and
 * killSwitch from $status, and the sports "Feed health" table is fed by
 * $sys['providers'], which comes from dashboard()'s systemStatus (stored
 * provider health from the database). The probes were pure latency.
 *
 * These are source-level assertions on purpose: the regression is "a blocking
 * network probe is wired into a page-render path", which is a property of the
 * call graph, not of any single return value. Workspace.php already carries the
 * same guard in a comment after the same bug was fixed there.
 */

/** Controller sources that must not probe providers while rendering a page. */
$fxReadController = static function (string $name): string {
    $path = FCPATH . 'application/controllers/' . $name;
    $src = @file_get_contents($path);
    assert_true(is_string($src) && $src !== '', "controller {$name} is readable");
    return (string) $src;
};

/**
 * Drop comments so the guard matches executable code only — the fix documents
 * itself with a comment naming getAllHealth(), which must not read as a call.
 */
$fxStripComments = static function (string $src): string {
    $out = '';
    foreach (token_get_all($src) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }
            $out .= $token[1];
            continue;
        }
        $out .= $token;
    }
    return $out;
};

test('perf: Sports::base() does not probe live providers on page render', function () use ($fxReadController, $fxStripComments) {
    $src = $fxStripComments($fxReadController('Sports.php'));

    // The base() payload is what every Sports page render pays for.
    $start = strpos($src, 'private function base(');
    assert_true($start !== false, 'Sports::base() exists');
    $end = strpos($src, 'private function render(', (int) $start);
    assert_true($end !== false, 'Sports::render() follows base()');
    $base = substr($src, (int) $start, (int) $end - (int) $start);

    // The exact regression: a blocking multi-provider HTTP sweep inside base().
    assert_true(
        strpos($base, 'getAllHealth(') === false,
        'Sports::base() must not call getAllHealth() — it is a blocking HTTP probe '
        . 'of every trading provider (~5s) and nothing in the sports views renders it'
    );

    // It must still hand the key to the layout so header.php stays well-formed.
    assert_contains("'providers' => []", $base);
    assert_contains("'tradingMode' => \$state['tradingMode']", $base);
    assert_contains("'killSwitch' => \$state['killSwitch']", $base);
});

test('perf: the sports views never read the layout provider-health payload', function () {
    // Justifies emptying it: the feed-health table uses $sys['providers'] from
    // dashboard()'s systemStatus instead, so no visible data is lost.
    foreach (['sports/index.php', 'sports/tickets.php'] as $view) {
        $src = @file_get_contents(FCPATH . 'application/views/' . $view);
        assert_true(is_string($src), "view {$view} is readable");
        assert_true(
            strpos((string) $src, "\$status['providers']") === false,
            "{$view} must not depend on \$status['providers']"
        );
    }

    $header = (string) @file_get_contents(FCPATH . 'application/views/layout/header.php');
    assert_true(
        strpos($header, "\$status['providers']") === false,
        'layout/header.php must not depend on $status[\'providers\']'
    );
    // The two keys the header does read must keep being supplied.
    assert_contains("\$status['tradingMode']", $header);
    assert_contains("\$status['killSwitch']", $header);
});

test('perf: the sports feed-health table still renders stored provider health', function () {
    // The fix must not have removed provider health from the page, only the
    // blocking probe. This is the surface that still shows it.
    $view = (string) @file_get_contents(FCPATH . 'application/views/sports/index.php');
    assert_contains("\$sys['providers']", $view);
    assert_contains('Feed health', $view);
});
