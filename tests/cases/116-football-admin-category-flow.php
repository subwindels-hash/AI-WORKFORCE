<?php
/**
 * Football Intelligence — admin configuration flow for the A/B/C rules
 * (spec §3 + §10) and the category's journey through the record:
 * predicted → stored → settled → reported by category → calibration sample.
 *
 * The admin panel is the only writer of the rule rows; the engine is the only
 * reader. This suite drives both sides through the public facade and checks
 * that a threshold saved from the panel changes what is stored, and that the
 * category is preserved on every downstream copy of the prediction.
 */
require_once TESTSPATH . 'football_support.php';

use AIWorkforce\Football\CategoryClassifier;
use AIWorkforce\Football\PredictionService;

/** One predicted pre-match fixture; returns [repo, intel, day, fixtureId, predictionId, predictionRow]. */
function fx_fb_predicted_fixture(array $config = []): array
{
    $kickoff = time() + 7200;
    $day = gmdate('Y-m-d', $kickoff);
    [$repo, $provider, $intel, $audit] = fx_fb_harness(
        [fx_fb_row('fx-flow', gmdate('c', $kickoff), 'Manchester City', 'Everton', '10', '20')],
        [],
        $config
    );
    fx_fb_sync_today($intel, $day);
    $intel->predictions()->predictDay($day);
    $prediction = $repo->listPredictions(['date' => $day, 'kind' => PredictionService::KIND_PRE_MATCH], 1)[0] ?? null;
    if ($prediction === null) {
        throw new RuntimeException('harness produced no prediction; the flow cases need one');
    }
    $fixture = $repo->findFixtureById((int) $prediction['fixture_id']);
    return [$repo, $intel, $day, (int) $fixture['id'], (string) $prediction['id'], $prediction];
}

test('football: a fresh rule store is seeded from the configured defaults, exactly once', function () {
    [, , $intel, $audit] = fx_fb_harness([]);
    $rules = $intel->categoryRules('admin:test');
    assert_true($rules['seeded'], 'the first read seeds the editable rows');
    assert_equals(['A', 'B', 'C'], array_map(static fn(array $r) => (string) $r['category_key'], $rules['rows']),
        'one editable row per category');
    $types = array_column($rules['rows'], 'rule_type');
    assert_in_array('HOME_EDGE', $types, 'A is the home-edge rule');
    assert_in_array('DRAW_OR_BALANCED', $types, 'B is the draw-or-balanced rule');
    assert_in_array('AWAY_EDGE', $types, 'C is the away-edge rule');
    $seeded = array_values(array_filter($audit->events, static fn(array $e) => $e['type'] === 'FOOTBALL_CATEGORY_RULES_SEEDED'));
    assert_equals(1, count($seeded), 'seeding is audited');

    $again = $intel->categoryRules('admin:test');
    assert_false($again['seeded'], 'a second read does not re-seed');
});

test('football: rules saved from the admin become the effective rule set for the engine', function () {
    [$repo, , $intel, $audit] = fx_fb_harness([]);
    $effective = $intel->saveCategoryRules([
        'edgePct' => 12.0,
        'drawSignificantPct' => 40.0,
        'labels' => ['A' => 'HOME FAVOUR', 'B' => 'BALANCED MATCH', 'C' => 'AWAY FAVOUR'],
        'enabled' => ['A' => true, 'B' => true, 'C' => false],
    ], 'admin:test');

    assert_equals(12.0, $effective['edgePct'], 'the saved edge is effective immediately');
    assert_equals(40.0, $effective['drawSignificantPct'], 'the saved draw line is effective immediately');
    assert_equals('STORED', $effective['source'], 'the engine now reads the stored rows, not the defaults');
    assert_equals('HOME FAVOUR', $effective['labels']['A'], 'the saved label is effective');
    assert_false((bool) $effective['enabled']['C'], 'a category disabled in the panel is disabled');

    $stored = $repo->listCategoryRules();
    $c = null;
    foreach ($stored as $row) if ($row['category_key'] === 'C') $c = $row;
    assert_false((bool) $c['enabled'], 'the disable flag is persisted, not just returned');
    $saved = array_values(array_filter($audit->events, static fn(array $e) => $e['type'] === 'FOOTBALL_CATEGORY_RULES_SAVED'));
    assert_equals(1, count($saved), 'the save is audited');
    assert_equals('admin:test', $saved[0]['actor'], 'the audit names the actor');

    // A previously away-favoured match can no longer be labelled C.
    $away = $intel->categories()->classify(['home' => 0.18, 'draw' => 0.27, 'away' => 0.55]);
    assert_equals('B', $away['key'], 'C is disabled, so the away-edge match is held in the balanced bucket');
});

/**
 * The same fixture predicted in a fresh harness, optionally after the admin
 * rules were saved through the facade — the order the admin panel implies:
 * configure, then let the prediction pass run. Burnley hosting Brighton is
 * the model's "away edge" fixture: a saved edge above the real margin must
 * move the stored verdict to balanced.
 */
function fx_fb_predicted_with_rules(?array $rules): array
{
    // A future kickoff (the pre-match slot is closed once it passes); a single
    // fixture per harness, so no date-boundary coupling between the two runs.
    $kickoff = time() + 7200;
    $day = gmdate('Y-m-d', $kickoff);
    [$repo, $provider, $intel, $audit] = fx_fb_harness([
        fx_fb_row('fx-flow', gmdate('c', $kickoff), 'Burnley', 'Brighton', '40', '30'),
    ]);
    if ($rules !== null) {
        $intel->saveCategoryRules($rules, 'admin:test');
    }
    fx_fb_sync_today($intel, $day);
    $intel->predictions()->predictDay($day);
    $prediction = $repo->listPredictions(['date' => $day, 'kind' => PredictionService::KIND_PRE_MATCH], 1)[0] ?? null;
    if ($prediction === null) {
        throw new RuntimeException('harness produced no prediction; the flip case needs one');
    }
    return [$repo, $intel, $day, $prediction];
}

test('football: raising the saved edge above the margin moves the stored category to B', function () {
    [, , , $first] = fx_fb_predicted_with_rules(null);
    assert_in_array($first['category'] ?? '', ['A', 'B', 'C'], 'the stored prediction carries a category');
    if (!in_array($first['category'], ['A', 'C'], true)) {
        return; // the fixture landed in B already; the flip has nothing to prove
    }
    $margin = 100 * abs((float) $first['probability_home'] - (float) $first['probability_away']);
    assert_true($margin >= 5.0, 'an A/C verdict means the margin cleared the 5-point default edge');
    if ($margin >= 50.0) {
        return; // the margin exceeds the highest storable edge; the flip is not reachable
    }

    [, , , $second] = fx_fb_predicted_with_rules([
        'edgePct' => $margin + 0.5,
        'drawSignificantPct' => 30.0,
    ]);
    assert_equals('B', $second['category'] ?? null,
        'the same fixture, predicted under the saved higher edge, is stored as balanced');
    assert_not_equals($first['category'], $second['category'], 'the admin threshold changed the stored category');
});

test('football: the category survives settlement, performance-by-category and calibration', function () {
    [$repo, $intel, $day, $fixtureId, $predictionId, $prediction] = fx_fb_predicted_fixture();
    $category = (string) $prediction['category'];
    assert_in_array($category, ['A', 'B', 'C'], 'the prediction is categorised');

    // The classifier agrees with what was stored, from the stored probabilities.
    $relabel = $intel->categories()->classify([
        'home' => (float) $prediction['probability_home'],
        'draw' => (float) $prediction['probability_draw'],
        'away' => (float) $prediction['probability_away'],
    ]);
    assert_equals($category, (string) $relabel['key'], 'the stored category is the classifier verdict on the stored probabilities');

    // The provider reports a full-time score; the settlement copies the category.
    $fixture = $repo->findFixtureById($fixtureId);
    $repo->saveFixture((int) $fixture['provider_id'], array_merge(
        array_intersect_key($fixture, array_flip(['external_id', 'competition', 'league_id', 'season', 'kickoff_at', 'home_team', 'away_team', 'home_team_id', 'away_team_id'])),
        ['externalId' => (string) $fixture['external_id'], 'status' => 'FINISHED', 'homeScore' => 2, 'awayScore' => 0]
    ));
    $intel->settlements()->settleFixture($fixtureId, 'test:flow:settle');
    $settlement = $repo->findSettlement($predictionId);
    assert_not_null($settlement, 'the settlement exists');
    assert_equals($category, (string) ($settlement['category'] ?? null), 'the settlement row keeps the prediction category');

    // The performance report buckets the settled match by that category. byCategory
    // is a list of {category, evaluated, ...} rows, one per non-empty bucket.
    $report = $intel->performance()->report();
    $bucket = null;
    foreach ($report['byCategory'] as $row) {
        if ((string) $row['category'] === $category) $bucket = $row;
    }
    assert_not_null($bucket, "the settled match appears in the by-category report under {$category}");
    assert_equals(1, (int) $bucket['evaluated'], "the settled match is counted under category {$category}");
    $others = 0;
    foreach ($report['byCategory'] as $row) {
        if ((string) $row['category'] !== $category) $others += (int) $row['evaluated'];
    }
    assert_equals(0, $others, 'the settled match is not double-counted under another category');

    // The calibration sample joins the settlement to its prediction, category included.
    $samples = $repo->listCalibrationSamples([]);
    assert_equals(1, count($samples), 'one calibration sample exists');
    assert_equals($category, (string) ($samples[0]['category'] ?? null), 'the sample carries the category');
    assert_true(is_numeric($samples[0]['raw_home']) && is_numeric($samples[0]['confidence']), 'the sample joins the prediction probabilities');
});
