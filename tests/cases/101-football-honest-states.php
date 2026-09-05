<?php
/**
 * Football Intelligence — honesty rules (spec §3/§5/§16/§19).
 *
 * These are the guarantees the module was rewritten around: a band boundary is a
 * band boundary, a missing value stays missing, an unconnected provider costs no
 * requests and produces no rows, and an empty settlement history is a report
 * state rather than a reason to switch forecasting off.
 *
 * Runs on the in-memory repository (`FootballRepositoryStub`) plus a fake
 * provider, so no database, no network and no seeded demo fixture is involved.
 */
require_once TESTSPATH . 'football_support.php';

use AIWorkforce\Football\DataState;
use AIWorkforce\Football\FootballConfiguration;
use AIWorkforce\Football\FootballDiagnostics;
use AIWorkforce\Football\FootballIntelligence;
use AIWorkforce\Football\PerformanceService;
use AIWorkforce\Football\PredictionService;
use AIWorkforce\Football\QualityBand;
use AIWorkforce\Sports\Providers\SportsProviderManager;

test('football: data-quality bands use the documented cut lines', function () {
    assert_equals(QualityBand::QUALIFIED, QualityBand::forScore(100));
    assert_equals(QualityBand::QUALIFIED, QualityBand::forScore(70), '70 qualifies');
    assert_equals(QualityBand::LIMITED, QualityBand::forScore(69), '69 is the top of LIMITED');
    assert_equals(QualityBand::LIMITED, QualityBand::forScore(50), '50 still predicts, limited');
    assert_equals(QualityBand::REJECTED, QualityBand::forScore(49), 'below 50 never predicts');
    assert_equals(QualityBand::REJECTED, QualityBand::forScore(0));
    assert_equals(70, QualityBand::QUALIFIED_MIN);
    assert_equals(50, QualityBand::LIMITED_MIN);
});

test('football: an absent value is never rendered as zero', function () {
    assert_equals(DataState::UNAVAILABLE, DataState::value(null), 'null becomes DATA_UNAVAILABLE');
    assert_equals(DataState::UNAVAILABLE, DataState::value(''), 'empty string becomes DATA_UNAVAILABLE');
    assert_equals(DataState::UNAVAILABLE, DataState::value([]), 'empty list becomes DATA_UNAVAILABLE');
    assert_equals(0, DataState::value(0, DataState::AVAILABLE), 'a real 0-0 stays 0');
    assert_equals(DataState::UNAVAILABLE, DataState::value(3, DataState::UNAVAILABLE), 'state overrides a stray value');
    assert_equals(DataState::AVAILABLE, DataState::fromCoverage(['a', 'b', 'c'], 3));
    assert_equals(DataState::LIMITED, DataState::fromCoverage(['a', 'b', null], 3), 'two of three is thin, not missing');
    assert_equals(DataState::UNAVAILABLE, DataState::fromCoverage([null, null, null], 3));
});

test('football: with no provider connected nothing is requested and nothing is invented', function () {
    $repo = new FootballRepositoryStub();
    $module = new FootballIntelligence($repo, new SportsProviderManager(), null, new FootballConfiguration());

    $sync = $module->fixtures()->syncDay(gmdate('Y-m-d'), 'test:noprovider');
    assert_equals('SKIPPED', $sync['status'], 'sync refuses instead of guessing');
    assert_equals('FOOTBALL_PROVIDER_NOT_CONFIGURED', $sync['reason']);
    assert_equals(0, $module->gateway()->requestsMade(), 'not one provider request');
    assert_equals([], $repo->listFixtures(['date' => gmdate('Y-m-d')], 10), 'no fixture row was created');
    assert_equals([], $repo->listPredictions([], 10), 'no prediction row was created');

    $diagnostics = $module->diagnostics()->snapshot();
    assert_equals(FootballDiagnostics::NOT_CONFIGURED, $diagnostics['checks'][0]['value'], 'Provider: NOT_CONFIGURED');
    assert_equals(FootballDiagnostics::UNAVAILABLE, $diagnostics['checks'][1]['value'], 'Fixtures: UNAVAILABLE');
    assert_equals(FootballDiagnostics::UNAVAILABLE, $diagnostics['checks'][2]['value'], 'Statistics: UNAVAILABLE');
    assert_equals(FootballDiagnostics::WAITING_FOR_DATA, $diagnostics['checks'][3]['value'], 'Prediction Engine: WAITING_FOR_DATA');
    assert_in_array('FOOTBALL_PROVIDER_NOT_CONFIGURED', $diagnostics['blockers']);
    assert_equals(false, $diagnostics['canPredict']);
    assert_contains('Football data provider not connected. Live fixtures and predictions are unavailable until a verified data source is configured.', $diagnostics['message']);

    $board = $module->board()->forDate(gmdate('Y-m-d'));
    assert_equals('NO_FIXTURES_STORED', $board['state'], 'the board says there is nothing stored');
    assert_equals(0, $board['summary']['fixtures']);
    assert_equals(0, $board['summary']['qualified']);
    assert_contains('data-availability state', (string) $board['message']);
});

test('football: empty settlement history is a report state, never a prediction gate', function () {
    $repo = new FootballRepositoryStub();
    $module = new FootballIntelligence($repo, new SportsProviderManager(), null, new FootballConfiguration());

    $report = $module->performance()->report(30);
    assert_equals(PerformanceService::NO_DATA, $report['state']);
    assert_equals(PerformanceService::EMPTY_MESSAGE, $report['message'], 'the required sentence appears verbatim');
    assert_equals(0, $report['evaluatedPredictions'], 'counted from the database: zero, not missing');
    foreach (['resultAccuracy', 'exactScoreAccuracy', 'averageConfidence', 'brier', 'ece', 'logLoss', 'averageDataQuality'] as $metric) {
        assert_null($report[$metric], $metric . ' stays unavailable instead of becoming 0');
    }
    assert_equals(false, $report['gatesPredictions'], 'settlement history never gates forecasting');

    // …and the diagnostics say the same thing: it is a warning, not a blocker.
    $diagnostics = $module->diagnostics()->snapshot();
    $settlement = null;
    foreach ($diagnostics['checks'] as $check) {
        if (($check['key'] ?? '') === 'Settlement history') $settlement = $check;
    }
    assert_not_null($settlement, 'the panel reports the settlement history explicitly');
    assert_equals(PerformanceService::NO_DATA, $settlement['state']);
    assert_equals(false, $settlement['gatesPredictions'], 'and marks it as non-gating');
    assert_contains(PerformanceService::EMPTY_MESSAGE, $settlement['detail']);
    assert_equals([], array_filter($diagnostics['blockers'], static fn(string $b): bool => str_contains($b, 'SETTLE')));
});

test('football: a provider error is surfaced verbatim and stores nothing new', function () {
    [$repo, $provider, $module] = fx_fb_harness([]);
    $provider->failFixtures = true;
    $future = gmdate('Y-m-d', time() + 86400);
    $sync = $module->fixtures()->syncDay($future, 'test:failure', null, -1);
    assert_equals('FAILED', $sync['status']);
    assert_equals(0, (int) ($sync['processed'] ?? 0), 'a failed fetch creates no fixture');
    $errors = implode('|', (array) ($sync['errors'] ?? []));
    assert_contains('DATA_ERROR', $errors, 'the provider status is preserved, not swallowed');
    assert_equals([], $repo->listFixtures(['date' => $future], 10));
    $failures = $module->providerStatus();
    assert_true(in_array($failures['state'], ['DEGRADED', 'NOT_CONFIGURED', 'CONNECTED'], true), 'status stays a known token');
});

test('football: demo data stays behind an explicit environment switch', function () {
    assert_equals(false, (new FootballConfiguration(['DEMO_MODE' => false]))->demoMode(), 'explicit false stays false');
    assert_equals(false, (new FootballConfiguration([]))->demoMode() && getenv('DEMO_MODE') === false,
        'production default is off');
    assert_equals(true, (new FootballConfiguration(['DEMO_MODE' => '1']))->demoMode(), 'the platform-wide flag is honoured');
    assert_equals(true, (new FootballConfiguration(['WINDELS_FOOTBALL_DEMO_MODE' => 'true']))->demoMode(), 'the module alias is honoured');
    // Demo mode is a permission, not a data source: with the flag on and no
    // provider, the module still refuses to produce a fixture.
    $module = new FootballIntelligence(new FootballRepositoryStub(), new SportsProviderManager(), null,
        new FootballConfiguration(['WINDELS_FOOTBALL_DEMO_MODE' => 'true']));
    $sync = $module->fixtures()->syncDay(gmdate('Y-m-d'), 'test:demo');
    assert_equals('SKIPPED', $sync['status'], 'no simulated provider is silently substituted');
    assert_in_array('DEMO_MODE_ENABLED', $module->diagnostics()->snapshot()['warnings'],
        'and the state is disclosed in diagnostics');
});

test('football: disabling the module stops provider jobs and keeps stored data readable', function () {
    $kickoff = time() + 7200;
    $day = gmdate('Y-m-d', $kickoff);
    [$repo, , $module] = fx_fb_harness([fx_fb_row('fx-off', gmdate('c', $kickoff), 'Manchester City', 'Everton', '10', '20')]);
    fx_fb_sync_today($module, $day);
    $disabled = new FootballIntelligence($repo, $module->providerManager(), null, new FootballConfiguration(['WINDELS_FOOTBALL_ENABLED' => 'false']));
    assert_equals(false, $disabled->config()->enabled());

    $live = $disabled->refresh()->evaluate('football-live');
    assert_equals(false, $live['due']);
    assert_equals('MODULE_DISABLED', $live['reason'], 'a disabled module makes no provider request');

    // Reading what is already stored is not a provider job, so the console and
    // the API keep serving the stored state instead of going blank.
    $board = $disabled->board()->forDate($day);
    assert_equals(1, $board['summary']['fixtures']);
    $cleanup = $disabled->refresh()->evaluate('football-cleanup');
    assert_not_equals('MODULE_DISABLED', $cleanup['reason'], 'cleanup is not blocked by the module switch');
});
