<?php
/**
 * Live market data after connecting a provider.
 *
 * Covers the two things that stop the chart from streaming real bars:
 *   1. a connected-but-not-enabled provider row silently dropping the live
 *      feed back to the labelled synthetic provider, and
 *   2. no way to rebuild the provider chain in-process after enabling one.
 *
 * Plus the honesty rules the live badge must never relax: synthetic data is
 * never presented as LIVE, and auto-refresh is withheld for it.
 */

/** Snapshot the market-data rows so these tests cannot leak into other suites. */
function fx_md_snapshot(): array
{
    $db = platform()->model->db;
    \AIWorkforce\ApiProviders::ensureSchema($db);
    $rows = [];
    try {
        $rows = $db->where_in('service', \AIWorkforce\ApiProviders::MARKET_DATA_SERVICES)
            ->get('api_providers')->result_array();
    } catch (Throwable $e) { $rows = []; }
    return is_array($rows) ? $rows : [];
}

function fx_md_restore(array $snapshot): void
{
    $db = platform()->model->db;
    try {
        $db->where_in('service', \AIWorkforce\ApiProviders::MARKET_DATA_SERVICES)->delete('api_providers');
        foreach ($snapshot as $row) {
            unset($row['id']);
            $db->insert('api_providers', $row);
        }
    } catch (Throwable $e) { /* best effort */ }
    // Leave the registry reflecting the restored store, not the test rows.
    platform()->refreshMarketDataProviders();
}

test('serviceState distinguishes "never connected" from "connected but not serving"', function () {
    $snapshot = fx_md_snapshot();
    $db = platform()->model->db;
    try {
        $db->where_in('service', \AIWorkforce\ApiProviders::MARKET_DATA_SERVICES)->delete('api_providers');

        // No rows at all: the keyless public feeds default to ON.
        $clean = \AIWorkforce\ApiProviders::serviceState($db, 'crypto_market');
        assert_false($clean['configured'], 'no rows means not configured');
        assert_true(\AIWorkforce\ApiProviders::serviceEnabled($db, 'crypto_market', true), 'keyless feed defaults to enabled');

        // THE REGRESSION: an operator saves a Binance row but leaves Enable
        // unticked. Before the fix this silently turned the live feed off.
        $saved = \AIWorkforce\ApiProviders::save($db, [
            'service' => 'crypto_market', 'driver' => 'binance_public', 'label' => 'Binance public',
            'role' => 'unused', 'enabled' => 0, 'base_url' => 'https://api.binance.com',
        ], null, 1, true);
        assert_true((int) ($saved['id'] ?? 0) > 0, 'row saved');

        $state = \AIWorkforce\ApiProviders::serviceState($db, 'crypto_market');
        assert_true($state['configured'], 'a row exists, so the service is configured');
        assert_false($state['live'], 'but nothing is serving yet');
        assert_equals(1, $state['rows']);
        assert_equals(0, $state['enabled_rows']);
        assert_null($state['driver'], 'no active driver while nothing is enabled');
        assert_false(\AIWorkforce\ApiProviders::serviceEnabled($db, 'crypto_market', true), 'registration gate is closed');
    } finally {
        fx_md_restore($snapshot);
    }
});

test('activateKeylessFeed switches a connected public feed to LIVE and promotes it to primary', function () {
    $snapshot = fx_md_snapshot();
    $db = platform()->model->db;
    try {
        $db->where_in('service', \AIWorkforce\ApiProviders::MARKET_DATA_SERVICES)->delete('api_providers');
        $binance = \AIWorkforce\ApiProviders::save($db, [
            'service' => 'crypto_market', 'driver' => 'binance_public', 'label' => 'Binance public',
            'role' => 'unused', 'enabled' => 0, 'base_url' => 'https://api.binance.com',
        ], null, 1, true);
        $fx = \AIWorkforce\ApiProviders::save($db, [
            'service' => 'forex_market', 'driver' => 'frankfurter', 'label' => 'Frankfurter ECB',
            'role' => 'unused', 'enabled' => 0,
        ], null, 1, true);

        $result = \AIWorkforce\ApiProviders::activateKeylessFeed($db, 'crypto_market');
        assert_true($result['ok'], 'activation succeeded');
        assert_equals('activated', $result['action']);
        assert_equals((int) $binance['id'], $result['id']);
        assert_equals('binance_public', $result['driver']);

        $state = \AIWorkforce\ApiProviders::serviceState($db, 'crypto_market');
        assert_true($state['live'], 'crypto market data is now serving');
        assert_equals('binance_public', $state['driver']);
        assert_equals(1, $state['enabled_rows']);

        // Idempotent: a second call must not demote or duplicate anything.
        $again = \AIWorkforce\ApiProviders::activateKeylessFeed($db, 'crypto_market');
        assert_true($again['ok']);
        assert_equals('already_live', $again['action']);

        \AIWorkforce\ApiProviders::activateKeylessFeed($db, 'forex_market');
        assert_true(\AIWorkforce\ApiProviders::serviceState($db, 'forex_market')['live'], 'forex goes live too');
        assert_true((int) $fx['id'] > 0);
    } finally {
        fx_md_restore($snapshot);
    }
});

test('activation never touches a service that is already live, and refuses non-keyless services', function () {
    $snapshot = fx_md_snapshot();
    $db = platform()->model->db;
    try {
        $db->where_in('service', \AIWorkforce\ApiProviders::MARKET_DATA_SERVICES)->delete('api_providers');

        // Operator deliberately enabled a custom feed: intent must win.
        $custom = \AIWorkforce\ApiProviders::save($db, [
            'service' => 'crypto_market', 'driver' => 'custom_http', 'label' => 'Licensed crypto feed',
            'role' => 'primary', 'enabled' => 1, 'base_url' => 'https://licensed.example/v1',
        ], null, 1, true);
        $keyless = \AIWorkforce\ApiProviders::save($db, [
            'service' => 'crypto_market', 'driver' => 'binance_public', 'label' => 'Binance public',
            'role' => 'unused', 'enabled' => 0, 'base_url' => 'https://api.binance.com',
        ], null, 1, true);

        $result = \AIWorkforce\ApiProviders::activateKeylessFeed($db, 'crypto_market');
        assert_true($result['ok']);
        assert_equals('already_live', $result['action'], 'does not override a live service');
        assert_equals((int) $custom['id'], $result['id'], 'the operator feed stays in charge');

        $active = \AIWorkforce\ApiProviders::activeConfig($db, 'crypto_market');
        assert_equals('custom_http', $active['driver'], 'custom feed is still primary');
        $untouched = \AIWorkforce\ApiProviders::find($db, (int) $keyless['id']);
        assert_equals(0, (int) $untouched['enabled'], 'the keyless row was not silently enabled');

        // Services with no keyless public driver must be refused outright.
        foreach (['lead_discovery', 'lottery', 'sports', 'trading_execution', 'llm'] as $service) {
            $refused = \AIWorkforce\ApiProviders::activateKeylessFeed($db, $service);
            assert_false($refused['ok'], $service . ' is not auto-activatable');
            assert_equals('skipped', $refused['action']);
        }

        // Nothing connected at all: honest not_connected, never a silent enable.
        $db->where_in('service', \AIWorkforce\ApiProviders::MARKET_DATA_SERVICES)->delete('api_providers');
        $missing = \AIWorkforce\ApiProviders::activateKeylessFeed($db, 'forex_market');
        assert_false($missing['ok']);
        assert_equals('not_connected', $missing['action']);
    } finally {
        fx_md_restore($snapshot);
    }
});

test('refreshMarketDataProviders rebuilds the chain in-process and keeps synthetic last', function () {
    $snapshot = fx_md_snapshot();
    $db = platform()->model->db;
    try {
        $db->where_in('service', \AIWorkforce\ApiProviders::MARKET_DATA_SERVICES)->delete('api_providers');

        $report = platform()->refreshMarketDataProviders();
        assert_true($report['refreshed']);
        assert_in_array('binance', $report['registered'], 'crypto live feed registered when unconfigured');
        assert_in_array('frankfurter-ecb', $report['registered'], 'forex live feed registered when unconfigured');
        assert_in_array('synthetic-demo', $report['registered'], 'labelled fallback is always present');
        assert_false($report['syntheticOnly'], 'real providers are registered');
        assert_equals('synthetic-demo', end($report['registered']), 'synthetic stays last so it can never pre-empt a live feed');

        // A connected-but-disabled row closes the gate: live feeds disappear.
        \AIWorkforce\ApiProviders::save($db, [
            'service' => 'crypto_market', 'driver' => 'binance_public', 'label' => 'Binance public',
            'role' => 'unused', 'enabled' => 0, 'base_url' => 'https://api.binance.com',
        ], null, 1, true);
        $dark = platform()->refreshMarketDataProviders();
        assert_false(in_array('binance', $dark['registered'], true), 'disabled row unregisters the live crypto feed');

        // ...and activating it brings the chain straight back, same process.
        \AIWorkforce\ApiProviders::activateKeylessFeed($db, 'crypto_market');
        $live = platform()->refreshMarketDataProviders();
        assert_in_array('binance', $live['registered'], 'live feed returns after activation');
        assert_false($live['syntheticOnly']);

        $names = array_map(fn($p) => $p->name(), platform()->providers->listProviders());
        assert_equals($names, array_values(array_unique($names)), 'refresh does not duplicate providers');
    } finally {
        fx_md_restore($snapshot);
    }
});

test('ProviderManager::reset clears health and failure state so a reconnected provider is probed fresh', function () {
    $pm = new \AIWorkforce\ProviderManager();
    $pm->register(new FakeProvider('flaky', 1, null, true));
    try { $pm->getQuote('BTCUSDT'); } catch (Throwable $e) { /* expected: records a failure */ }
    $before = $pm->getAllHealth(true);
    assert_equals(1, count($before));

    $pm->reset();
    assert_equals([], $pm->listProviders(), 'registry emptied');
    assert_equals([], $pm->getAllHealth(true), 'no stale health after reset');

    $pm->register(new FakeProvider('flaky', 1, null, true));
    $after = $pm->getAllHealth(true);
    assert_equals('UP', $after[0]['status'], 'failure log was cleared, so the provider is not pre-judged DEGRADED');
});

test('the live chart endpoint and its honesty rules are wired', function () {
    $routes = file_get_contents(FCPATH . 'application/config/routes.php');
    assert_contains("\$route['api/market-data/live']", $routes);
    assert_contains("\$route['api/market-data/refresh']", $routes);

    $api = file_get_contents(FCPATH . 'application/controllers/Api_marketdata.php');
    assert_contains('public function live', $api);
    assert_contains('public function refresh', $api);
    assert_contains('refreshMarketDataProviders', $api, 'refresh rebuilds the chain before reporting');
    // LIVE must require all three: real, fresh and undelayed.
    assert_contains('$live = !$synthetic && !$stale && !$delayed;', $api);
    assert_contains("'SYNTHETIC'", $api);
    assert_contains("'STALE'", $api);
    assert_contains("'DELAYED'", $api);
    // A refused quote must not fail the whole chart feed.
    assert_contains("'unavailable' => true", $api);

    $js = file_get_contents(FCPATH . 'assets/js/market-chart.js');
    assert_contains('/api/market-data/live', $js);
    assert_contains('/api/market-data/refresh', $js);
    assert_contains('SIMULATION', $js, 'synthetic data is badged as simulation');
    assert_contains('if (synthetic)', $js, 'auto-refresh is withheld for synthetic data');
    assert_contains('visibilitychange', $js, 'polling pauses on a hidden tab');

    $partial = file_get_contents(FCPATH . 'application/views/welcome/partials/chart.php');
    assert_contains('data-live-chart', $partial);
    assert_contains("data-autostart=\"<?= \$liveReason === 'LIVE' ? '1' : '0' ?>\"", $partial, 'only a real fresh feed auto-starts');
    assert_contains('data-overlays', $partial, 'support/resistance + setup survive a symbol or timeframe change');

    $footer = file_get_contents(FCPATH . 'application/views/layout/footer.php');
    assert_contains('market-chart.js', $footer);

    $css = file_get_contents(FCPATH . 'assets/css/ai_workforce.css');
    assert_contains('.livechart-bar', $css);

    $tools = file_get_contents(FCPATH . 'application/controllers/Tools.php');
    assert_contains('public function marketdata', $tools);
    assert_contains('--activate', $tools);
    assert_contains('MARKET_DATA_ACTIVATED', $tools, 'going live from the CLI is audited');
});

test('the server-rendered chart badge and the streamed badge use the same rule', function () {
    // Both must derive LIVE from provenance the same way, otherwise the first
    // paint could say LIVE and the stream could say SIMULATION.
    $partial = file_get_contents(FCPATH . 'application/views/welcome/partials/chart.php');
    assert_contains("\$liveReason = \$isSynthetic ? 'SYNTHETIC' : (\$isStale ? 'STALE' : (\$isDelayed ? 'DELAYED' : 'LIVE'));", $partial);
    assert_contains("\$isSynthetic = !empty(\$prov['synthetic']);", $partial, 'badge reads provenance.synthetic');
    assert_contains("\$isStale = !empty(\$prov['stale']);", $partial, 'badge reads provenance.stale');
    assert_contains("\$isDelayed = !empty(\$prov['delayed']);", $partial, 'badge reads provenance.delayed');

    $api = file_get_contents(FCPATH . 'application/controllers/Api_marketdata.php');
    assert_contains("if (\$synthetic) \$reason = 'SYNTHETIC';", $api);
    assert_contains("elseif (\$stale) \$reason = 'STALE';", $api);
    assert_contains("elseif (\$delayed) \$reason = 'DELAYED';", $api);

    // Provenance itself still labels synthetic data end-to-end.
    $pm = new \AIWorkforce\ProviderManager();
    $pm->register(new \AIWorkforce\Providers\SyntheticProvider());
    $series = $pm->getCandleSeries('BTCUSDT', 'crypto', '1h', 100);
    assert_true($series['provenance']['synthetic']);
    assert_false($series['provenance']['live'], 'synthetic is never live');
    assert_equals('synthetic-demo', $series['provenance']['source']);
});

test('the dashboard chart renders on load without an analysis run', function () {
    $welcome = file_get_contents(FCPATH . 'application/controllers/Welcome.php');
    assert_contains('public function index', $welcome);
    // A chart series is fetched even when no analysis was submitted.
    assert_contains("if (\$data['chart'] === null)", $welcome, 'standalone chart is fetched on load');
    assert_contains('getCandleSeries', $welcome);
    assert_contains("'chartError'", $welcome, 'a failure to serve is reported, never fabricated');
    assert_contains('marketStateView', $welcome);
    assert_contains('MARKET_DATA_SERVICES', $welcome, 'connection strip reads the market-data services');

    $view = file_get_contents(FCPATH . 'application/views/welcome/index.php');
    assert_contains("partials/live_market_panel.php", $view);
    assert_contains("if (empty(\$analysisChartRendered))", $view, 'the same symbol is never charted twice');
    assert_contains("\$run = null;", $view, 'standalone chart has no S/R or setup overlays');
    assert_contains("\$chartControls = true;", $view, 'standalone chart can switch symbol without a reload');
    assert_contains('No market data to chart yet', $view, 'honest empty state');
    assert_contains('tools marketdata --activate', $view, 'empty state tells the operator how to fix it');

    $partial = file_get_contents(FCPATH . 'application/views/welcome/partials/chart.php');
    // The partial must tolerate having no analysis run at all.
    assert_contains("\$run = isset(\$run) && is_array(\$run) ? \$run : null;", $partial);
    assert_contains("foreach ((\$run['agents'] ?? []) as \$a)", $partial, 'no run means no agents to scan');
    assert_contains('data-controls', $partial);
    assert_contains("<?= \$run ? ' · structure · setup' : '' ?>", $partial, 'heading adapts to a standalone chart');

    $strip = file_get_contents(FCPATH . 'application/views/welcome/partials/live_market_panel.php');
    assert_contains('CONNECTED · NOT ENABLED', $strip, 'the dark state is named explicitly');
    assert_contains('NOT CONNECTED', $strip);
    assert_contains("if (empty(\$marketState) || !is_array(\$marketState))", $strip, 'renders nothing without state');
});

test('connecting a market-data provider in Admin takes effect immediately and reports the truth', function () {
    $admin = file_get_contents(FCPATH . 'application/controllers/Admin.php');
    assert_contains('private function syncMarketData', $admin);
    assert_contains('refreshMarketDataProviders', $admin, 'the chain is rebuilt in-process');
    assert_contains('MARKET_DATA_LIVE', $admin, 'going live from the admin portal is logged');
    assert_contains('is LIVE — the market-data chart now streams real bars from', $admin);
    assert_contains('connected but NOT ENABLED', $admin, 'the dark state is surfaced, not hidden behind "saved"');
    // Every mutating provider action must sync, not just the enable toggle.
    foreach (['api_save', 'api_primary', 'api_delete', 'apiToggle'] as $method) {
        assert_contains('function ' . $method, $admin, $method . ' exists');
    }
    assert_true(substr_count($admin, '$this->syncMarketData(') >= 4,
        'save, primary, delete and the enable/disable toggle all sync market data');
    // Non-market services keep the ordinary confirmation.
    assert_contains("\$this->flash('notice', '✓ Changes saved successfully');", $admin);
});

test('the live chart offers only symbols a real provider can serve', function () {
    $js = file_get_contents(FCPATH . 'assets/js/market-chart.js');
    assert_contains('SYMBOL_GROUPS', $js);
    assert_contains("if (m.dataset.controls === '1')", $js, 'the switcher is opt-in per mount');
    assert_contains('switchTo(symbol, timeframe)', $js);
    assert_contains("this.errors = 0;", $js, 'switching resets the error backoff');
    assert_contains("this.marketClass = '';", $js, 'market class is re-inferred server-side per symbol');
    assert_contains('does not re-run the multi-agent analysis', $js, 'switching is not an analysis run');

    // XAUUSD is offered by the analysis form but has no real provider
    // (Frankfurter covers ECB currencies only, metals excluded), so it must not
    // be offered as a LIVE chart symbol.
    assert_false(str_contains($js, "'XAUUSD'"), 'no real feed serves XAUUSD — it is not offered as a live symbol');
    $welcome = file_get_contents(FCPATH . 'application/controllers/Welcome.php');
    assert_contains("'XAUUSD'", $welcome, 'but it stays available for analysis');

    // Crypto/forex/equity symbols must line up with the provider allow-lists.
    foreach (\AIWorkforce\Providers\BinanceProvider::SYMBOLS as $sym) {
        if (in_array($sym, ['BTCUSDT', 'ETHUSDT', 'SOLUSDT', 'BNBUSDT', 'XRPUSDT'], true)) {
            assert_contains("'" . $sym . "'", $js, 'binance-listed ' . $sym . ' is offered');
        }
    }
    foreach (['AAPL', 'MSFT', 'NVDA', 'TSLA'] as $sym) {
        assert_in_array($sym, \AIWorkforce\Providers\YahooChartProvider::STOCKS, $sym . ' is Yahoo-listed');
        assert_contains("'" . $sym . "'", $js);
    }
    foreach (['SPY', 'QQQ', 'GLD'] as $sym) {
        assert_in_array($sym, \AIWorkforce\Providers\YahooChartProvider::ETFS, $sym . ' is a Yahoo ETF');
    }
    foreach (['ES=F', 'NQ=F', 'CL=F', 'GC=F'] as $sym) {
        assert_in_array($sym, \AIWorkforce\Providers\YahooChartProvider::FUTURES, $sym . ' is a Yahoo future');
    }
    // Frankfurter serves ECB currencies; every forex pair offered must split
    // into two of them or the chart would silently fall back to simulation.
    foreach (['EURUSD', 'GBPUSD', 'USDJPY', 'AUDUSD'] as $pair) {
        [$base, $quote] = \AIWorkforce\Providers\FrankfurterProvider::splitPair($pair);
        assert_in_array($base, \AIWorkforce\Providers\FrankfurterProvider::ECB_CURRENCIES, $pair . ' base');
        assert_in_array($quote, \AIWorkforce\Providers\FrankfurterProvider::ECB_CURRENCIES, $pair . ' quote');
    }
});
