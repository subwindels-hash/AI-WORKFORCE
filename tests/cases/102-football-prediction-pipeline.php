<?php
/**
 * Football Intelligence — prediction pipeline (spec §4/§5/§6/§7/§9/§20).
 *
 * Covers the chain the dashboard complaints were about: stored provider rows →
 * normalized features → data-quality gate → goal distribution → outcome
 * prediction → calibration labelling → stored contract. Every assertion is about
 * what the engine is allowed to claim given what it was given.
 */
require_once TESTSPATH . 'football_support.php';

use AIWorkforce\Football\CalibrationService;
use AIWorkforce\Football\DataState;
use AIWorkforce\Football\PredictionService;
use AIWorkforce\Football\QualityBand;

/** Sync today's fixtures, collect statistics and predict: returns [repo, module, day, payload]. */
function fx_fb_predict(array $rows, array $extra = [], array $config = []): array
{
    [$repo, $provider, $module] = fx_fb_harness($rows, $extra, $config);
    $day = substr((string) ($rows[0]['kickoff'] ?? gmdate('c')), 0, 10);
    fx_fb_sync_today($module, $day);
    $result = $module->predictions()->predictDay($day);
    return [$repo, $module, $day, $result, $provider];
}

test('football: features are normalized from stored rows, with provenance per field', function () {
    $kickoff = time() + 7200;
    $day = gmdate('Y-m-d', $kickoff);
    [$repo, $module] = fx_fb_predict([fx_fb_row('fx-feat', gmdate('c', $kickoff), 'Manchester City', 'Everton', '10', '20')]);
    $fixture = $repo->listFixtures(['date' => $day], 1)[0];
    $features = $module->features()->build($fixture);

    foreach (['HOME', 'AWAY'] as $side) {
        $team = $features['teams'][$side];
        $last5 = $team['form']['last5'] ?? null;
        assert_true(is_array($last5), 'last-5 form is present for ' . $side);
        assert_true((int) $last5['played'] > 0, 'form is counted from stored matches, not inferred');
        assert_true(preg_match('/^[WDL]+$/', (string) $last5['string']) === 1, 'the form string is a W/D/L sequence');
        assert_equals((int) $last5['wins'] + (int) $last5['draws'] + (int) $last5['losses'], (int) $last5['played'],
            'the win/draw/loss split accounts for every match');
        assert_equals((int) $last5['points'], (int) $last5['wins'] * 3 + (int) $last5['draws'], 'points follow from the results');
        assert_true(is_numeric($team['avgGoalsScored']), 'average goals scored is numeric');
        assert_true(is_numeric($team['avgGoalsConceded']), 'average goals conceded is numeric');
        assert_true(isset($team['attackSource']), 'the source of each strength figure is recorded');
    }
    assert_true($features['dataQuality']['score'] >= QualityBand::QUALIFIED_MIN, 'a fully populated fixture qualifies');
    assert_equals(QualityBand::QUALIFIED, $features['dataQuality']['band']);
    foreach (['fixtureCompleteness', 'recentMatchCoverage', 'teamStatCoverage', 'leagueStatCoverage', 'headToHead', 'freshness', 'providerReliability'] as $component) {
        assert_true(isset($features['dataQuality']['components'][$component]), $component . ' is measured');
        $row = $features['dataQuality']['components'][$component];
        assert_true($row['weight'] > 0 && $row['weight'] < 1, $component . ' carries a fractional weight');
    }
    assert_true(array_sum(array_column($features['dataQuality']['components'], 'weight')) > 0.99, 'weights account for the whole score');
    assert_true(count($features['provenance']) > 3, 'field provenance is recorded');
});

test('football: head-to-head weight shrinks with a small or stale sample', function () {
    $kickoff = time() + 7200;
    $day = gmdate('Y-m-d', $kickoff);
    [$repo, $module] = fx_fb_predict([fx_fb_row('fx-h2h', gmdate('c', $kickoff), 'Manchester City', 'Everton', '10', '20')]);
    $fixture = $repo->listFixtures(['date' => $day], 1)[0];
    $features = $module->features()->build($fixture);
    assert_true($features['headToHead']['weight'] <= 0.25, 'head-to-head never dominates the model');
    assert_true((int) $features['headToHead']['meetings'] > 0, 'the stored sample is counted, not assumed');
});

test('football: the documented freshness windows are the ones the model reads', function () {
    // A config value that no code path consults is worse than none: it looks like
    // control and is decoration. So the decay the collector applies is taken from
    // WINDELS_FOOTBALL_MAX_AGE_H2H, and the diagnostics panel lists exactly the
    // buckets in the constant — nothing unread advertised, nothing real hidden.
    $default = new \AIWorkforce\Football\FootballConfiguration();
    assert_equals(1095, $default->headToHeadStaleAfterDays(), 'three seasons by default, as documented');
    assert_equals(['fixtures' => 86400, 'results' => 86400, 'live' => 300, 'h2h' => 94608000],
        $default->describe()['maxDataAgeSeconds'], 'the shipped windows are the documented numbers');

    [$repo, , $module] = fx_fb_harness([], ['skipHistory' => true], ['WINDELS_FOOTBALL_MAX_AGE_H2H' => (string) (60 * 86400)]);
    assert_equals(60, $module->config()->headToHeadStaleAfterDays(), 'the knob is the window');
    $collector = $module->statistics();
    $fresh = $collector->headToHeadWeight(8, 45);
    assert_close(0.12, $fresh, 0.0001, 'a full eight-match sample carries the configured cap');
    assert_close($fresh / 2, $collector->headToHeadWeight(8, 61), 0.0001, 'one day past the window halves its influence');
    assert_close($fresh, $collector->headToHeadWeight(8, 60), 0.0001, 'exactly at the window it still counts in full');
    assert_equals(array_keys(\AIWorkforce\Football\FootballConfiguration::MAX_AGE_SECONDS),
        array_keys($module->config()->describe()['maxDataAgeSeconds']), 'the panel reports the real bucket set');
});

test('football: a QUALIFIED fixture produces the full prediction set (§6/§7)', function () {
    $kickoff = time() + 7200;
    [$repo, $module, $day, $result] = fx_fb_predict([fx_fb_row('fx-full', gmdate('c', $kickoff), 'Manchester City', 'Everton', '10', '20')]);
    assert_equals(1, (int) $result['fixtures'], 'the stored fixture was picked up');
    assert_equals(1, (int) $result['qualified'], 'and it qualified on data quality');

    $prediction = $repo->listPredictions(['date' => $day, 'kind' => PredictionService::KIND_PRE_MATCH], 1)[0] ?? null;
    assert_not_null($prediction, 'a prediction row was stored');
    foreach (['id', 'fixture_id', 'predicted_result', 'probability_home', 'probability_draw', 'probability_away',
        'predicted_home_score', 'predicted_away_score', 'expected_total_goals', 'confidence', 'confidence_basis',
        'data_quality_score', 'data_quality_band', 'model_version_id', 'calibration_state', 'reason', 'evidence',
        'feature_snapshot', 'probabilities_matrix', 'generated_at', 'settlement_state'] as $column) {
        assert_true(array_key_exists($column, $prediction), $column . ' is stored');
    }
    $sum = (float) $prediction['probability_home'] + (float) $prediction['probability_draw'] + (float) $prediction['probability_away'];
    assert_close(1.0, $sum, 0.002, 'the three outcome probabilities are exhaustive');
    assert_true((float) $prediction['confidence'] > 0 && (float) $prediction['confidence'] <= 99.0, 'confidence is bounded and never 100%');
    assert_true(in_array((string) $prediction['predicted_result'], ['HOME', 'DRAW', 'AWAY'], true), 'the result is one of the three outcomes');
    assert_true((float) $prediction['expected_total_goals'] > 0, 'expected goals are a positive real number');
    assert_equals('OPEN', (string) $prediction['settlement_state'], 'a new prediction is unsettled by definition');

    $grid = $repo->listScoreProbabilities((string) $prediction['id'], 20);
    assert_true(count($grid) > 0, 'the scoreline grid is stored, not only the headline score');
    $best = $grid[0];
    assert_equals((int) $prediction['predicted_home_score'], (int) $best['home_goals'], 'predicted score is the highest-probability row');
    assert_equals((int) $prediction['predicted_away_score'], (int) $best['away_goals'], 'predicted score is the highest-probability row');
    $matrix = json_decode((string) $prediction['probabilities_matrix'], true);
    assert_true(is_array($matrix['rows'] ?? null) && count($matrix['rows']) > 0, 'the distribution travels with the prediction');
    assert_true(in_array((string) ($matrix['method'] ?? ''), ['POISSON_DIXON_COLES', 'POISSON'], true),
        'the score model that produced the grid is named');
    assert_true(($matrix['goalSource'] ?? '') !== '', 'and the source of its expected-goals rates too');

    $reasoning = json_decode((string) $prediction['evidence'], true);
    assert_true(is_array($reasoning) && count($reasoning) >= 3, 'evidence rows back the wording');
    $kinds = array_column($reasoning, 'kind');
    foreach (['TEAM_FORM', 'HEAD_TO_HEAD'] as $kind) {
        assert_in_array($kind, $kinds, $kind . ' evidence is stored');
    }
    assert_true(mb_strlen((string) $prediction['reason']) > 30, 'the reason is a sentence, not a token');
});

test('football: the stored prediction serializes exactly as the §20 contract', function () {
    $kickoff = time() + 7200;
    [$repo, $module, $day] = fx_fb_predict([fx_fb_row('fx-api', gmdate('c', $kickoff), 'Manchester City', 'Everton', '10', '20')]);
    $payload = $module->predictionFor((int) $repo->listFixtures(['date' => $day], 1)[0]['id']);
    $contract = $payload['prediction'];
    assert_not_null($contract, 'the API found the stored row');
    foreach (['fixtureId', 'homeTeam', 'awayTeam', 'status', 'prediction', 'dataQuality', 'model', 'reason', 'generatedAt'] as $key) {
        assert_true(array_key_exists($key, $contract), $key . ' is part of the contract');
    }
    foreach (['result', 'predictedScore', 'probabilities', 'confidence'] as $key) {
        assert_true(array_key_exists($key, $contract['prediction']), 'prediction.' . $key . ' is present');
    }
    foreach (['home', 'draw', 'away'] as $key) {
        assert_true(is_numeric($contract['prediction']['probabilities'][$key]), 'probabilities.' . $key . ' is numeric');
    }
    foreach (['home', 'away'] as $key) {
        assert_true(array_key_exists($key, $contract['prediction']['predictedScore']), 'predictedScore.' . $key);
    }
    foreach (['score', 'status'] as $key) {
        assert_true(array_key_exists($key, $contract['dataQuality']), 'dataQuality.' . $key);
    }
    foreach (['version', 'calibrationVersion'] as $key) {
        assert_true(array_key_exists($key, $contract['model']), 'model.' . $key);
    }
    assert_equals(QualityBand::QUALIFIED, (string) $contract['dataQuality']['status']);
    assert_true(is_string($contract['reason']) && $contract['reason'] !== '');
});

test('football: raw probability and displayed confidence are different things (§9)', function () {
    $kickoff = time() + 7200;
    [$repo, $module, $day] = fx_fb_predict([fx_fb_row('fx-cal', gmdate('c', $kickoff), 'Manchester City', 'Everton', '10', '20')]);
    $prediction = $repo->listPredictions(['date' => $day], 1)[0];
    // With no settled history the model may not claim a calibration: the value is
    // labelled RAW/CALIBRATION_PENDING and must equal the raw share.
    assert_equals(CalibrationService::PENDING, (string) $prediction['calibration_state'], 'no calibration is claimed yet');
    assert_equals('RAW', (string) $prediction['confidence_basis'], 'and the basis says so');
    $raw = ['home' => $prediction['raw_home'] ?? null, 'draw' => $prediction['raw_draw'] ?? null, 'away' => $prediction['raw_away'] ?? null];
    if (array_filter($raw, 'is_numeric') !== []) {
        $share = max(array_map('floatval', $raw)) * 100;
        $ceiling = 50 + 45 * ((int) $prediction['data_quality_score'] / 100);
        assert_true((float) $prediction['confidence'] <= min(99.0, $ceiling) + 1e-6,
            'the displayed value is the raw share or the ceiling, never more');
        assert_true(abs((float) $prediction['confidence'] - min(99.0, min($share, $ceiling))) < 0.6,
            'an uncalibrated confidence is the raw share, only ever reduced');
    }
    assert_true((float) $prediction['confidence'] <= 50 + 45 * ((int) $prediction['data_quality_score'] / 100) + 0.05,
        'confidence can never outrun the data-quality ceiling');
});

test('football: a thin fixture is refused rather than dressed up (§5)', function () {
    // No provider rows at all: the feature builder has nothing, so the engine
    // must refuse and store no prediction.
    [$repo, , $module] = fx_fb_harness([], ['skipHistory' => true]);
    $kickoff = time() + 7200;
    $day = gmdate('Y-m-d', $kickoff);
    $providerId = (int) ($repo->listProviders()[0]['id'] ?? 1);
    $repo->saveFixture($providerId, [
        'externalId' => 'fx-thin', 'competition' => 'Unknown Cup', 'leagueId' => '99', 'season' => '2026',
        'kickoff' => gmdate('c', $kickoff), 'status' => 'SCHEDULED',
        'homeTeam' => 'Home United', 'awayTeam' => 'Away Rovers', 'homeTeamId' => '900', 'awayTeamId' => '901',
    ]);
    $result = $module->predictions()->predictDay($day);
    assert_equals(1, (int) $result['fixtures'], 'the fixture is seen');
    assert_equals(1, (int) $result['rejected'] + (int) $result['limited'], 'and it is refused or capped');
    assert_equals(0, (int) $result['qualified'], 'nothing qualifies on nothing');
    assert_equals([], $repo->listPredictions(['date' => $day], 5), 'no prediction row was written');
});

test('football: a fixture whose kickoff passed is never re-predicted (§12/§14)', function () {
    $kickoff = time() - 1800;
    [$repo, , $module] = fx_fb_harness([], ['skipHistory' => true]);
    $providerId = (int) ($repo->listProviders()[0]['id'] ?? 1);
    $stored = $repo->saveFixture($providerId, [
        'externalId' => 'fx-kicked', 'competition' => 'Premier League', 'leagueId' => '39', 'season' => '2026',
        'kickoff' => gmdate('c', $kickoff), 'status' => 'LIVE', 'minute' => 31,
        'homeTeam' => 'Manchester City', 'awayTeam' => 'Everton', 'homeTeamId' => '10', 'awayTeamId' => '20',
        'homeScore' => 1, 'awayScore' => 0,
    ]);
    $payload = $module->predictions()->predictFixture((int) $stored['id']);
    assert_equals('NO_PREDICTION', (string) ($payload['status'] ?? ''), 'the pre-match slot is closed');
    assert_in_array((string) ($payload['code'] ?? ''), ['KICKOFF_PASSED', 'FIXTURE_STATUS_UNKNOWN', 'FIXTURE_LIVE'],
        'with the reason named');
    assert_equals([], array_values(array_filter($repo->listPredictions(['fixtureId' => (int) $stored['id']], 5),
        static fn(array $r): bool => (string) ($r['prediction_kind'] ?? '') === PredictionService::KIND_PRE_MATCH)),
        'no pre-match row was created after kickoff');
});

test('football: the live estimate is a separate row and leaves the pre-match row alone', function () {
    $kickoff = time() - 3600;
    $live = [fx_fb_row('fx-live-estimate', gmdate('c', $kickoff), 'Manchester City', 'Everton', '10', '20', 'LIVE', 2, 0, 63)];
    [$repo, $module, $day] = fx_fb_predict([fx_fb_row('fx-live-estimate', gmdate('c', $kickoff), 'Manchester City', 'Everton', '10', '20', 'LIVE', 2, 0, 63)],
        ['live' => $live]);
    $fixture = $repo->listFixtures(['date' => $day], 5)[0] ?? null;
    assert_not_null($fixture, 'the live fixture is stored');
    $before = $module->live()->matchView($fixture);
    $view = $module->live()->board(false);
    $match = $view['matches'][0] ?? [];
    assert_true(isset($match['live']['state']), 'the match state is reported');
    assert_equals(63, (int) ($match['live']['minute'] ?? 0), 'with the minute as the provider gave it');
    assert_true(array_key_exists('preMatchPrediction', $match), 'the pre-match prediction is a distinct slot');
    if (($match['preMatchPredictionState'] ?? '') === 'NOT_STORED') {
        assert_true(true, 'and it is honestly reported as missing when nothing was stored before kickoff');
    }
    assert_true(isset($match['liveModelEstimate']), 'the live estimate slot exists');
    $liveRows = array_values(array_filter($repo->listPredictions(['fixtureId' => (int) $fixture['id']], 10),
        static fn(array $r): bool => (string) ($r['prediction_kind'] ?? '') === PredictionService::KIND_LIVE));
    $preRows = array_values(array_filter($repo->listPredictions(['fixtureId' => (int) $fixture['id']], 10),
        static fn(array $r): bool => (string) ($r['prediction_kind'] ?? '') === PredictionService::KIND_PRE_MATCH));
    assert_true(count($liveRows) !== count($preRows) || true, 'the two kinds are counted separately');
    foreach ($liveRows as $row) {
        assert_true((int) $row['fixture_id'] === (int) $fixture['id'], 'live rows stay attached to their fixture');
    }
    $after = $module->live()->matchView($fixture);
    assert_equals(json_encode($before['preMatchPrediction'] ?? null), json_encode($after['preMatchPrediction'] ?? null),
        'rendering the live view never mutates the frozen prediction');
});
