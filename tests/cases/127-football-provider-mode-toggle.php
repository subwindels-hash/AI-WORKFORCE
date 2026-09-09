<?php
/**
 * Football Intelligence — admin Auto / Manual provider mode.
 *
 * The Data Provider selector on /football is admin-controlled (Admin → System
 * Settings → Football): AUTO (default) locks the console and the API to Auto /
 * Smart, MANUAL unlocks the operator dropdown with the admin's manual default
 * pre-selected. Pinned here:
 *
 *  - the default is AUTO, and any unreadable mode fails closed to AUTO;
 *  - manualProvider() only ever yields a value the dropdown can honour;
 *  - the mode is visible in describe() (admin diagnostics) alongside the rest.
 */
require_once TESTSPATH . 'football_support.php';

use AIWorkforce\Football\FootballConfiguration;

test('football provider mode: AUTO by default, MANUAL only when spelled so', function () {
    $envMode = getenv('WINDELS_FOOTBALL_PROVIDER_MODE');
    $envManual = getenv('WINDELS_FOOTBALL_MANUAL_PROVIDER');
    // Pin the environment aside: these cases pin the *defaults*, not the
    // ambient shell of the machine that happens to run them.
    putenv('WINDELS_FOOTBALL_PROVIDER_MODE');
    putenv('WINDELS_FOOTBALL_MANUAL_PROVIDER');
    try {
        $default = new FootballConfiguration();
        assert_equals('AUTO', $default->providerMode(), 'no knob set anywhere means AUTO');
        assert_true($default->providerLockedToAuto(), 'and the selector is locked');

        assert_equals('MANUAL', (new FootballConfiguration(['WINDELS_FOOTBALL_PROVIDER_MODE' => 'MANUAL']))->providerMode());
        assert_equals('MANUAL', (new FootballConfiguration(['WINDELS_FOOTBALL_PROVIDER_MODE' => 'manual']))->providerMode(), 'case is not load-bearing');
        assert_false((new FootballConfiguration(['WINDELS_FOOTBALL_PROVIDER_MODE' => 'MANUAL']))->providerLockedToAuto());

        foreach (['', 'auto', 'AUTO', 'smart', 'MULTI', 'api-football', 'yes', '0', 'garbage'] as $other) {
            assert_equals('AUTO', (new FootballConfiguration(['WINDELS_FOOTBALL_PROVIDER_MODE' => $other]))->providerMode(),
                var_export($other, true) . ' fails closed to AUTO');
        }
    } finally {
        if ($envMode === false) putenv('WINDELS_FOOTBALL_PROVIDER_MODE'); else putenv('WINDELS_FOOTBALL_PROVIDER_MODE=' . $envMode);
        if ($envManual === false) putenv('WINDELS_FOOTBALL_MANUAL_PROVIDER'); else putenv('WINDELS_FOOTBALL_MANUAL_PROVIDER=' . $envManual);
    }
});

test('football provider mode: the manual default is only ever an offerable value', function () {
    $envManual = getenv('WINDELS_FOOTBALL_MANUAL_PROVIDER');
    putenv('WINDELS_FOOTBALL_MANUAL_PROVIDER');
    try {
        assert_equals('', (new FootballConfiguration())->manualProvider(), 'unset means Auto / Smart');
        assert_equals('', (new FootballConfiguration(['WINDELS_FOOTBALL_MANUAL_PROVIDER' => '']))->manualProvider());
        assert_equals('', (new FootballConfiguration(['WINDELS_FOOTBALL_MANUAL_PROVIDER' => 'AUTO']))->manualProvider());
        assert_equals('', (new FootballConfiguration(['WINDELS_FOOTBALL_MANUAL_PROVIDER' => 'smart']))->manualProvider());
        assert_equals('MULTI', (new FootballConfiguration(['WINDELS_FOOTBALL_MANUAL_PROVIDER' => 'multi']))->manualProvider());
        assert_equals('api-football', (new FootballConfiguration(['WINDELS_FOOTBALL_MANUAL_PROVIDER' => 'API-FOOTBALL']))->manualProvider());
        assert_equals('api-football', (new FootballConfiguration(['WINDELS_FOOTBALL_MANUAL_PROVIDER' => 'apifootball']))->manualProvider(), 'the legacy spelling still lands');
        assert_equals('thesportsdb', (new FootballConfiguration(['WINDELS_FOOTBALL_MANUAL_PROVIDER' => 'TheSportsDB']))->manualProvider());
        assert_equals('sportmonks', (new FootballConfiguration(['WINDELS_FOOTBALL_MANUAL_PROVIDER' => 'sportmonks']))->manualProvider());
        assert_equals('http-provider', (new FootballConfiguration(['WINDELS_FOOTBALL_MANUAL_PROVIDER' => 'http-provider']))->manualProvider());
        foreach (['bet365', 'unknown-feed', 'AUTO EXTRA', '1'] as $bogus) {
            assert_equals('', (new FootballConfiguration(['WINDELS_FOOTBALL_MANUAL_PROVIDER' => $bogus]))->manualProvider(),
                var_export($bogus, true) . ' collapses to Auto / Smart rather than pre-selecting a lie');
        }
    } finally {
        if ($envManual === false) putenv('WINDELS_FOOTBALL_MANUAL_PROVIDER'); else putenv('WINDELS_FOOTBALL_MANUAL_PROVIDER=' . $envManual);
    }
});

test('football provider mode: the admin settings store carries the switch', function () {
    $defaults = AIWorkforce\AdminPortal::SETTING_DEFAULTS['football'] ?? null;
    assert_true(is_array($defaults), 'a football settings category exists');
    assert_equals('AUTO', $defaults['football_provider_mode'] ?? null, 'AUTO by default');
    assert_equals('', $defaults['football_manual_provider'] ?? null, 'no pinned feed by default');
});

test('football provider mode: describe() exposes the switch for diagnostics', function () {
    $described = (new FootballConfiguration([
        'WINDELS_FOOTBALL_PROVIDER_MODE' => 'MANUAL',
        'WINDELS_FOOTBALL_MANUAL_PROVIDER' => 'sportmonks',
    ]))->describe();
    assert_equals('MANUAL', $described['providerMode'] ?? null);
    assert_equals('sportmonks', $described['manualProvider'] ?? null);
});
