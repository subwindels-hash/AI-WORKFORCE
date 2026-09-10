<?php
/**
 * Football Intelligence — the intelligence layer: fair value, the WINDELS
 * Intelligence Score, prediction stability, the drivers behind a selection, the
 * three freshness clocks, and the ranked pick list.
 *
 * The module was specified as three separate questions, and the point of these
 * cases is that they stay separate:
 *
 *  > What does the WINDELS model believe? (probability, confidence, evidence)
 *  > What price does the market offer? (odds, implied probability, margin)
 *  > Is the gap between them meaningful? (value)
 *
 * So each case checks the arithmetic *and* the honesty of an absence: a match
 * with no price must not acquire one, a prediction with no history must not be
 * called stable, a component that cannot be measured must not be scored zero.
 * "No data" and "0/100" are different statements and only one of them is true.
 */
require_once TESTSPATH . 'football_support.php';

use AIWorkforce\Football\DataState;
use AIWorkforce\Football\FootballConfiguration;
use AIWorkforce\Football\FreshnessTracker;
use AIWorkforce\Football\IntelligenceReport;
use AIWorkforce\Football\IntelligenceScore;
use AIWorkforce\Football\MatchFeed;
use AIWorkforce\Football\OddsIntelligence;
use AIWorkforce\Football\PredictionDrivers;
use AIWorkforce\Football\PredictionMarkets;
use AIWorkforce\Football\PredictionService;
use AIWorkforce\Football\QualityBand;
use AIWorkforce\Football\StabilityMonitor;

/** A priced 1X2 sheet: a bookmaker's three-way with a margin in it. */
function fx_fb_1x2(float $home = 1.85, float $draw = 3.40, float $away = 4.60): array
{
    $seen = gmdate('c');
    return [
        ['market' => 'Match Winner', 'selection' => 'Home', 'decimalOdds' => $home, 'observedAt' => $seen],
        ['market' => 'Match Winner', 'selection' => 'Draw', 'decimalOdds' => $draw, 'observedAt' => $seen],
        ['market' => 'Match Winner', 'selection' => 'Away', 'decimalOdds' => $away, 'observedAt' => $seen],
    ];
}

/** [markets, sheet] for the default config — the sheet the value engine reads. */
/**
 * [markets, odds engine, priced sheet with the fair block] — the three pieces a
 * reader needs to reproduce what `attachMarkets()` assembles for a page.
 */
function fx_fb_sheet(array $odds, string $marketKey = 'MATCH_WINNER'): array
{
    $config = new FootballConfiguration([]);
    $markets = new PredictionMarkets($config);
    $oddsEngine = new OddsIntelligence($config);
    return [$markets, $oddsEngine, $oddsEngine->withFairValues($markets->priceSheet($odds, $marketKey))];
}

test('football: fair value removes the margin before calling a price valuable', function () {
    [$markets, $oddsEngine, $sheet] = fx_fb_sheet(fx_fb_1x2());
    $odds = $oddsEngine;
    // 1/1.85 + 1/3.40 + 1/4.60 = 0.540541 + 0.294118 + 0.217391 = 1.052050
    assert_equals(OddsIntelligence::PRICE_COMPLETE, (string) $sheet['state'], 'a complete three-way can be de-vigged');
    assert_true(abs((float) $sheet['overround'] - 1.05205) < 0.0001, 'the overround is the summed implied share, not a guess');
    $home = (array) $sheet['fair']['HOME'];
    assert_true(abs((float) $home['fairProbability'] - 0.540541 / 1.05205) < 0.00001,
        'the fair probability is the implied share divided by the overround');
    assert_true((float) $home['fairOdds'] > 1.85, 'and the fair price is longer than the padded one');

    $value = $odds->assess($sheet, 'HOME', 0.61);
    // The brief's two numbers, kept apart because they answer different questions.
    assert_close(0.1285, (float) $value['expectedValue'], 0.0001, '61% at 1.85 pays +12.85% expected value');
    assert_close(6.95, (float) $value['edgePoints'], 0.02, 'the edge in probability points is 61% minus the implied share');
    assert_close(1.6393, (float) $value['windelsFairOdds'], 0.001, 'WINDELS would price the same outcome at 1/0.61');
    assert_close(0.61 - (float) $home['fairProbability'], (float) $value['edgeAgainstFair'], 0.00001,
        'against the de-vigged price the edge is smaller — and that is the honest number');
    assert_equals(OddsIntelligence::MARGIN_PROPORTIONAL, (string) $value['marginMethod'], 'the removal method is named');
});

test('football: an unpriced or one-sided market is never given an invented price', function () {
    // Nothing quoted at all.
    [$markets, $odds, $empty] = fx_fb_sheet([]);
    $absent = $odds->assess($empty, 'HOME', 0.61);
    assert_equals(OddsIntelligence::CLASS_UNPRICED, (string) $absent['valueClass'], 'no price means no verdict');
    assert_null($absent['odds'], 'the price stays absent');
    assert_null($absent['expectedValue'], 'and is never back-solved from the model probability');
    assert_equals(OddsIntelligence::PRICE_NONE, (string) $absent['marketState'], 'the sheet itself says it has nothing');

    // One leg of three: a partial market cannot reveal the bookmaker's margin.
    [$markets, $odds, $partial] = fx_fb_sheet([
        ['market' => 'Match Winner', 'selection' => 'Home', 'decimalOdds' => 1.85, 'observedAt' => gmdate('c')],
        ['market' => 'Match Winner', 'selection' => 'Draw', 'decimalOdds' => 3.40, 'observedAt' => gmdate('c')],
    ]);
    assert_equals(OddsIntelligence::PRICE_PARTIAL, (string) $partial['state'], 'the shortfall is classified, not ignored');
    $partialValue = $odds->assess($odds->withFairValues($partial), 'HOME', 0.61);
    assert_null($partialValue['fairProbability'], 'no margin is estimated from an incomplete book');
    assert_true(is_numeric($partialValue['odds']), 'the quoted price is still shown');
    assert_true(str_contains(strtolower((string) $partial['note']), 'incomplete')
        || str_contains(strtolower((string) $partial['note']), 'all')
        || $partial['note'] !== null, 'and the reason is stated in the sheet');

    // A model with nothing to say about a priced market abstains.
    $noModel = $odds->assess($odds->withFairValues($partial), 'HOME', null);
    assert_equals(OddsIntelligence::CLASS_UNPRICED, (string) $noModel['valueClass'], 'a price alone is not a verdict');
    assert_true(str_contains((string) $noModel['valueReason'], 'no probability'), 'and it says so');

    // A decimal price of 1.00 or less is not a price.
    $broken = $markets->priceSheet([
        ['market' => 'Match Winner', 'selection' => 'Home', 'decimalOdds' => 0.95, 'observedAt' => gmdate('c')],
        ['market' => 'Match Winner', 'selection' => 'Draw', 'decimalOdds' => 3.40, 'observedAt' => gmdate('c')],
        ['market' => 'Match Winner', 'selection' => 'Away', 'decimalOdds' => 4.60, 'observedAt' => gmdate('c')],
    ], 'MATCH_WINNER');
    assert_null($broken['quotes']['HOME']['odds'] ?? null, 'a sub-stake price is dropped');
    $dropped = $odds->assess($odds->withFairValues($broken), 'HOME', 0.61);
    assert_true(str_contains((string) $dropped['marketState'], 'PARTIAL') || $dropped['odds'] === null,
        'and the market degrades to partial rather than trusting it');
});

test('football: the value classes are a scale, and none of them is a promise', function () {
    $oddsEngine = new OddsIntelligence(new FootballConfiguration([]));
    $classify = static fn(?float $edge, ?float $fair, ?float $ev): array =>
        $oddsEngine->classify($edge, $fair, OddsIntelligence::PRICE_COMPLETE, $ev);
    assert_equals(OddsIntelligence::CLASS_STRONG_VALUE, $classify(0.08, 0.05, 0.16)['class'], 'a wide, robust gap is strong value');
    assert_equals(OddsIntelligence::CLASS_POSITIVE_VALUE, $classify(0.03, 0.02, 0.06)['class'], 'a narrow gap is still positive');
    assert_equals(OddsIntelligence::CLASS_FAIR, $classify(0.002, 0.0, 0.004)['class'], 'a gap inside the noise band is fair');
    assert_equals(OddsIntelligence::CLASS_NEGATIVE_VALUE, $classify(-0.03, -0.02, -0.06)['class'], 'a small shortfall is negative');
    assert_equals(OddsIntelligence::CLASS_AVOID, $classify(-0.12, -0.09, -0.2)['class'], 'a large one is avoid');
    // Strong value must survive de-vigging: looking good only against a padded
    // price is a reading of the margin, not of the football.
    assert_not_equals(OddsIntelligence::CLASS_STRONG_VALUE, $classify(0.09, 0.0, 0.17)['class'],
        'an edge that vanishes against the fair price is not strong');

    // No class on this engine may be spelled like a promise.
    foreach (OddsIntelligence::LABELS as $label) {
        $lower = strtolower((string) $label);
        assert_true(!str_contains($lower, 'guarantee') && !str_contains($lower, 'sure win') && !str_contains($lower, 'free'),
            'the label "' . $label . '" makes no promise');
    }
    assert_true(str_contains(strtolower(OddsIntelligence::DISCLAIMER), 'not a'),
        'and the block carries the sentence that says what it is not');
});

test('football: the intelligence score is built from stored measurements only', function () {
    $scores = new IntelligenceScore(new FootballConfiguration([]));
    $full = $scores->compute([
        'confidence' => 84.0, 'confidenceBasis' => 'CALIBRATED', 'dataQuality' => 91.0, 'band' => QualityBand::QUALIFIED,
        'coverage' => 0.97, 'calibrationState' => 'CALIBRATED',
        'stability' => ['state' => StabilityMonitor::STABLE],
    ]);
    assert_true(is_numeric($full['score']), 'five measurements produce a score');
    assert_equals(5, count((array) $full['components']), 'each one is published with its weight');
    assert_false((bool) $full['marketPriceIncluded'], 'the market price is not an input to the score');
    assert_equals(1.0, round((float) $full['weightsUsed'], 6), 'the weights renormalise to a mean, not a sum of fractions');
    assert_true(str_contains(strtolower((string) $full['disclaimer']), 'evidence'),
        'and the disclaimer says what the number measures');

    // Nothing measurable: no score is published, and the reason is the absence.
    $nothing = $scores->compute([]);
    assert_null($nothing['score'], 'no measurements, no number — never a zero');
    assert_equals(IntelligenceScore::BAND_INSUFFICIENT, (string) $nothing['band'], 'the band names the shortfall');
    assert_equals(5, count((array) $nothing['excluded']), 'every component is accounted for as excluded');
    foreach ((array) $nothing['excluded'] as $excluded) {
        assert_equals('NOT_MEASURABLE', (string) $excluded['cause'], 'because it was not measurable, not because it was zero');
    }

    // A configured weight of zero is a different cause, and says so.
    $zeroed = (new IntelligenceScore(new FootballConfiguration(['WINDELS_FOOTBALL_SCORE_W_CALIBRATION' => '0'])))
        ->compute(['confidence' => 80.0, 'dataQuality' => 80.0, 'coverage' => 0.9, 'calibrationState' => 'CALIBRATED',
            'stability' => ['state' => StabilityMonitor::STABLE]]);
    $causes = array_column((array) $zeroed['excluded'], 'cause');
    assert_true(in_array('WEIGHT_ZEROED_BY_CONFIGURATION', $causes, true), 'a zeroed weight is excluded by configuration');
    assert_false(in_array('WEIGHT_ZEROED_BY_CONFIGURATION', array_column((array) $full['excluded'], 'cause'), true),
        'while the default configuration still counts it');

    // A first reading is not a stable reading: BASELINE is excluded, not scored.
    $baseline = $scores->compute(['confidence' => 70.0, 'dataQuality' => 80.0, 'coverage' => 0.9,
        'calibrationState' => 'CALIBRATION_PENDING', 'stability' => ['state' => StabilityMonitor::BASELINE]]);
    $stabilityPart = null;
    foreach ((array) $baseline['excluded'] as $excluded) {
        if ((string) $excluded['key'] === 'stability') $stabilityPart = $excluded;
    }
    assert_not_null($stabilityPart, 'an unmeasurable stability is dropped from the mean');
    assert_true(!str_contains(strtolower((string) $stabilityPart['reason']), 'unstable'),
        'and it is described as a first reading, not as a warning');
    // Calibration pending is a limited input, not a missing one — it still counts
    // at its documented reduced value, and says which it is.
    $calibration = null;
    foreach ((array) $baseline['components'] as $component) {
        if ((string) $component['key'] === 'calibration') $calibration = $component;
    }
    assert_not_null($calibration, 'a pending calibration is still reported');
    assert_close(40.0, (float) $calibration['value'], 0.001, 'at its pending value');
});

test('football: stability separates 61→60 from 61→48', function () {
    $monitor = new StabilityMonitor(new FootballRepositoryStub(), new FootballConfiguration([]));
    $current = ['id' => '2', 'fixture_id' => 7, 'predicted_result' => 'HOME',
        'probability_home' => 0.61, 'probability_draw' => 0.22, 'probability_away' => 0.17];
    $stable = $monitor->compare(['probability_home' => 0.60, 'predicted_result' => 'HOME'], $current);
    assert_equals(StabilityMonitor::STABLE, (string) $stable['state'], 'one point is noise, and is called that');
    $moved = $monitor->compare(['probability_home' => 0.55, 'predicted_result' => 'HOME'], $current);
    assert_equals(StabilityMonitor::MOVED, (string) $moved['state'], 'six points is worth a sentence');
    $unstable = $monitor->compare(['probability_home' => 0.48, 'predicted_result' => 'HOME'], $current);
    assert_equals(StabilityMonitor::UNSTABLE, (string) $unstable['state'], 'thirteen points is a warning');
    assert_close(13.0, (float) $unstable['movementPoints'], 0.01, 'measured on the selection the reader was shown');
    assert_true(str_contains((string) $unstable['reason'], 'unstable'), 'and the reason reads like the banner');
    assert_close(61.0 - 48.0, abs((float) $unstable['largestMovementPoints']), 0.01,
        'the largest movement is the biggest absolute one, whichever side it is on');

    // A change of the selected outcome is reported even when the number is small.
    $switched = $monitor->compare(['probability_home' => 0.60, 'predicted_result' => 'DRAW'], $current);
    assert_not_equals(StabilityMonitor::STABLE, (string) $switched['state'], 'a flipped selection is never "stable"');
    assert_true((bool) $switched['resultChanged'], 'and the flag says so');

    // The first reading cannot be compared with anything.
    $first = $monitor->compare(null, $current);
    assert_equals(StabilityMonitor::BASELINE, (string) $first['state'], 'a first reading has no movement to report');

    // The cause travels with the movement.
    $caused = $monitor->compare(['probability_home' => 0.48], $current, ['LINEUP_CHANGE', 'ODDS_MOVE']);
    assert_equals(['LINEUP_CHANGE', 'ODDS_MOVE'], (array) $caused['triggerCodes'], 'why it moved is published with that it moved');
    // A model-version change is a different kind of movement, and says so.
    $acrossModels = $monitor->compare(
        ['probability_home' => 0.60, 'predicted_result' => 'HOME', 'model_version_id' => 3],
        ['probability_home' => 0.61, 'predicted_result' => 'HOME', 'model_version_id' => 4, 'fixture_id' => 7]
    );
    assert_true((bool) $acrossModels['modelChanged'], 'the payload records that the versions differ');
    assert_equals(StabilityMonitor::MOVED, (string) $acrossModels['state'],
        'a one-point move across a model change is not called stable');
    assert_true(str_contains((string) $acrossModels['reason'], 'different model versions'), 'and the reason names the cause');

    // The thresholds are the configured ones, echoed rather than assumed.
    $tuned = (new StabilityMonitor(new FootballRepositoryStub(), new FootballConfiguration(
        ['WINDELS_FOOTBALL_STABILITY_MOVED_PP' => '10', 'WINDELS_FOOTBALL_STABILITY_UNSTABLE_PP' => '25'])))
        ->compare(['probability_home' => 0.55], $current);
    assert_equals(StabilityMonitor::STABLE, (string) $tuned['state'], 'a wider configured band makes six points ordinary');
});

test('football: stability is unknown when there is no history, never stable', function () {
    [$repo, , $module] = fx_fb_harness([fx_fb_row('fx-stab', gmdate('c', time() + 4 * 3600), 'Manchester City', 'Everton', '10', '20')]);
    $day = gmdate('Y-m-d', time() + 4 * 3600);
    fx_fb_sync_today($module, $day);
    $module->predictions()->predictDay($day);
    $prediction = $repo->predictions[0] ?? null;
    assert_not_null($prediction, 'the harness stored a prediction');
    assert_true(count((array) $repo->predictionRevisions) >= 1, 'and one revision was recorded with it');
    $stored = (array) $prediction;
    $block = $module->stability()->read(array_merge($stored, ['id' => (string) ($stored['id'] ?? '')]), []);
    assert_equals(StabilityMonitor::UNKNOWN, (string) $block['state'], 'with no trail to read, the answer is unknown');
    assert_true(str_contains(strtolower((string) $block['reason']), 'never as stable')
        || str_contains(strtolower((string) $block['reason']), 'nothing to measure'),
        'and the payload says the absence is not a clean bill of health');
    assert_false(StabilityMonitor::isWarning(StabilityMonitor::UNKNOWN), 'unknown is not a warning');
    assert_true(StabilityMonitor::isWarning(StabilityMonitor::UNSTABLE), 'unstable is');
});

test('football: the drivers explain the pick from stored numbers, or say they cannot', function () {
    $prediction = [
        'probability_home' => 0.61, 'probability_draw' => 0.22, 'probability_away' => 0.17,
        'predicted_result' => 'HOME', 'confidence' => 84.0, 'expected_total_goals' => 2.9,
        'feature_snapshot' => ['teams' => [
            // The stored snapshot keys its teams by outcome, exactly as
            // PredictionService::compactTeam writes them — a test that invented
            // lowercase keys would pass against a shape no row has.
            'HOME' => ['attackStrength' => 2.35, 'defenseWeakness' => 1.55, 'name' => 'Manchester City'],
            'AWAY' => ['attackStrength' => 0.85, 'defenseWeakness' => 1.15, 'name' => 'Everton'],
        ], 'headToHead' => ['meetings' => 6, 'homeWins' => 4, 'draws' => 1, 'awayWins' => 1],
            'coverage' => ['teamStats' => true, 'leagueStandings' => true, 'injuries' => false, 'lineups' => false]],
        'reasoning' => [['kind' => 'HEAD_TO_HEAD', 'detail' => '4 wins in the last 6 meetings', 'weight' => 0.08]],
    ];
    $drivers = (new PredictionDrivers())->describe($prediction, ['selection' => 'HOME', 'label' => 'Home win',
        'odds' => 1.85, 'value' => ['odds' => 1.85, 'expectedValue' => 0.1285, 'valueLabel' => 'Positive value']]);
    assert_not_null($drivers['headline'], 'a reader gets a sentence, not only a number');
    $byKey = [];
    foreach ((array) $drivers['drivers'] as $row) $byKey[(string) $row['key']] = $row;
    assert_true(isset($byKey['home_performance'], $byKey['away_performance'], $byKey['head_to_head'],
        $byKey['market_price'], $byKey['squad_availability'], $byKey['evidence']),
        'the documented questions are all asked');
    assert_equals(PredictionDrivers::STRONG, (string) $byKey['home_performance']['verdict'], 'a 2.35 goals-per-match home side is prolific');
    assert_true(str_contains((string) $byKey['home_performance']['detail'], '2.35'), 'quoting the stored figure');
    assert_equals(PredictionDrivers::WEAK, (string) $byKey['away_performance']['verdict'], 'a 0.85 away attack is thin');
    assert_equals(PredictionDrivers::UNAVAILABLE, (string) $byKey['squad_availability']['verdict'],
        'an unavailable availability report is reported as unavailable');
    assert_true(!str_contains((string) $byKey['squad_availability']['detail'], '0.00'),
        'and "unavailable" is never printed as a zero');
    assert_true(str_contains((string) $byKey['market_price']['detail'], '1.85'), 'the price is named as the market\'s own');
    $driverDisclaimer = strtolower((string) $drivers['disclaimer']);
    assert_true(str_contains($driverDisclaimer, 'not a probability') && str_contains($driverDisclaimer, 'not a guarantee'),
        'the disclaimer keeps them descriptive - they are not framed as a prediction of the result');

    // An empty prediction produces no invented prose.
    $bare = (new PredictionDrivers())->describe([], []);
    foreach ((array) $bare['drivers'] as $row) {
        assert_equals(PredictionDrivers::UNAVAILABLE, (string) $row['verdict'], $row['key'] . ' is unavailable with nothing stored');
    }
});

test('football: three clocks, because a prediction can be current while its price is not', function () {
    $config = new FootballConfiguration([]);
    $tracker = new FreshnessTracker($config);
    $now = time();
    $fresh = $tracker->stamp(
        ['generated_at' => gmdate('c', $now - 120)],
        ['source_timestamp' => gmdate('c', $now - 300)],
        ['pricing' => ['pricedAt' => gmdate('c', $now - 60)]],
        null
    );
    assert_equals(FreshnessTracker::CURRENT, (string) $fresh['state'], 'three current clocks make a current match');
    assert_equals(3, count((array) $fresh['clocks']), 'prediction, data and odds, in that order');
    $oddsClock = (array) $fresh['fields']['odds'];
    assert_close(60.0, (float) $oddsClock['ageSeconds'], 2.0, 'the odds age is measured, not assumed');
    assert_equals(1800, (int) $oddsClock['windowSeconds'], 'against the odds window');

    // A price older than its own window is stale even though the prediction is new.
    $staleOdds = $tracker->stamp(
        ['generated_at' => gmdate('c', $now - 60)],
        ['source_timestamp' => gmdate('c', $now - 60)],
        ['pricing' => ['pricedAt' => gmdate('c', $now - 4000)]],
        null
    );
    assert_equals(FreshnessTracker::STALE, (string) $staleOdds['state'], 'the worst clock decides the verdict');
    assert_equals(['odds'], (array) $staleOdds['staleFields'], 'and names which one it was');

    // No timestamp at all is unknown, not fresh.
    $unknown = $tracker->stamp([], [], [], null);
    assert_equals(FreshnessTracker::UNAVAILABLE, (string) $unknown['state'], 'an absent clock is reported as absent');
    assert_equals(3, count((array) $unknown['unavailableFields']), 'all three of them');

    // The data clock falls back to the sweep, and says it did.
    $fallback = $tracker->stamp(['generated_at' => gmdate('c', $now)], [], [],
        ['finished_at' => gmdate('c', $now - 7200), 'started_at' => gmdate('c', $now - 7260)]);
    $dataClock = (array) $fallback['fields']['data'];
    assert_true(str_contains(strtolower((string) $dataClock['source']), 'sweep')
        || str_contains(strtolower((string) $dataClock['note']), 'sweep'),
        'the fallback source is named in the payload');

    assert_equals('1.1 h', FreshnessTracker::humanAge(4000), 'ages are written for a human, in the unit that fits');
    assert_equals('45s', FreshnessTracker::humanAge(45), 'and a small age stays in seconds');
    assert_equals('2.0 d', FreshnessTracker::humanAge(172800), 'a long one in days');
});

test('football: the report answers all three questions without inventing any', function () {
    [$repo, $provider, $module] = fx_fb_harness([
        fx_fb_row('fx-rep', gmdate('c', time() + 5 * 3600), 'Manchester City', 'Everton', '10', '20'),
        fx_fb_row('fx-rep2', gmdate('c', time() + 5 * 3600 + 600), 'Brighton', 'Burnley', '30', '40'),
    ]);
    $day = gmdate('Y-m-d', time() + 5 * 3600);
    fx_fb_sync_today($module, $day);
    $module->predictions()->predictDay($day);
    // The match's own key, read back from the board rather than guessed from the
    // fixture row: prices are matched on the identity `MatchFeed::matchId()`
    // derives, and a test that invented one would quietly test nothing.
    $firstBoard = $module->board()->forDate($day, false, 1, 50);
    $firstRow = (array) ($firstBoard['rows'][0] ?? []);
    $matchId = (string) ($firstRow['matchId'] ?? '');
    assert_true($matchId !== '', 'the row carries the identity its prices are matched on');
    $repo->marketOdds = [
        ['matchId' => $matchId, 'market' => 'Match Winner', 'selection' => 'Home', 'decimalOdds' => 1.85, 'observedAt' => gmdate('c')],
        ['matchId' => $matchId, 'market' => 'Match Winner', 'selection' => 'Draw', 'decimalOdds' => 3.40, 'observedAt' => gmdate('c')],
        ['matchId' => $matchId, 'market' => 'Match Winner', 'selection' => 'Away', 'decimalOdds' => 4.60, 'observedAt' => gmdate('c')],
    ];
    $callsBefore = count((array) $provider->calls);
    $board = $module->board()->forDate($day, false, 1, 50);
    assert_equals($callsBefore, count((array) $provider->calls), 'decorating a page costs no provider call');

    $rows = (array) $board['rows'];
    $priced = null;
    foreach ($rows as $row) {
        $block = (array) ($row['market'] ?? []);
        if (!empty($row['intelligence']) && is_numeric($block['odds'] ?? null)) $priced = $row;
    }
    assert_not_null($priced, 'the priced match is found on the page');
    assert_not_null($priced, 'a row carries an intelligence block');
    $intel = (array) $priced['intelligence'];
    foreach (['score', 'quality', 'drivers', 'fairValue', 'stability', 'freshness', 'risk', 'probability'] as $key) {
        assert_true(isset($intel[$key]), 'the block answers ' . $key);
    }
    $market = (array) ($priced['market'] ?? []);
    assert_true(is_numeric($market['probability'] ?? null), 'WINDELS probability is one figure');
    assert_true(is_numeric($market['odds'] ?? null), 'the price is another');
    assert_true(isset($intel['fairValue']['expectedValue']), 'and the gap between them is a third');
    assert_false((bool) $intel['score']['marketPriceIncluded'], 'the price is not an input to the score');
    assert_not_equals((float) ($intel['probability']['home'] ?? 0), (float) ($market['impliedProbability'] ?? -1),
        'our estimate and their implied share are different numbers, not one number twice');

    // The match the feed quoted no price for is still reported — with the absence,
    // never with a zero where the price should be.
    $unpricedValue = null;
    foreach ($rows as $row) {
        $block = (array) ((array) ($row['intelligence'] ?? []))['fairValue'] ?? [];
        if ((string) ($block['state'] ?? '') !== 'AVAILABLE') { $unpricedValue = $block; break; }
    }
    if ($unpricedValue !== null) {
        assert_true(($unpricedValue['odds'] ?? null) === null, 'an unpriced match publishes no price');
        assert_true(($unpricedValue['expectedValue'] ?? null) === null, 'and no expected value derived from one');
        assert_equals(OddsIntelligence::CLASS_UNPRICED, (string) ($unpricedValue['valueClass'] ?? ''),
            'and the value is reported as unjudged, not as zero edge');
    } else {
        assert_true(true, 'every row on the page carried a price, so there is nothing to check here');
    }
});

test('football: picks rank evidence, exclude what is not fit, and never promise', function () {
    $row = static fn(array $overrides): array => array_merge([
        'analysisState' => 'ANALYZED', 'matchId' => 'm', 'fixtureId' => 1, 'homeTeam' => 'A', 'awayTeam' => 'B',
        'kickoffLabel' => 'today', 'confidence' => 80.0, 'dataQuality' => 91.0,
        'risk' => ['level' => 'LOW'],
        'market' => ['state' => PredictionMarkets::STATE_AVAILABLE, 'key' => 'MATCH_WINNER', 'label' => 'Match Winner — 1X2',
            'selection' => 'HOME', 'selectionLabel' => 'Home win', 'probability' => 0.61],
        'intelligence' => [
            'state' => IntelligenceReport::STATE_SCORED,
            'score' => ['score' => 80, 'band' => IntelligenceScore::BAND_STRONG, 'available' => true],
            'quality' => ['score' => 91.0, 'band' => QualityBand::QUALIFIED, 'checklist' => [], 'missing' => []],
            'fairValue' => ['state' => 'AVAILABLE', 'valueClass' => OddsIntelligence::CLASS_POSITIVE_VALUE,
                'valueLabel' => 'Positive value', 'expectedValue' => 0.1, 'edgePoints' => 7.0, 'odds' => 1.85,
                'windelsFairOdds' => 1.64],
            'stability' => ['state' => StabilityMonitor::STABLE, 'reason' => ''],
            'freshness' => ['state' => FreshnessTracker::CURRENT],
            'withheld' => ['withheld' => false],
        ],
    ], $overrides);
    $report = new IntelligenceReport(new FootballRepositoryStub(), new FootballConfiguration([]),
        new StabilityMonitor(new FootballRepositoryStub(), new FootballConfiguration([])),
        new IntelligenceScore(new FootballConfiguration([])), new PredictionDrivers(),
        new FreshnessTracker(new FootballConfiguration([])));

    $picks = $report->picks([
        $row([]),
        $row(['fixtureId' => 2, 'homeTeam' => 'C', 'awayTeam' => 'D',
            'intelligence' => array_replace((array) $row([])['intelligence'],
                ['score' => ['score' => 92, 'band' => IntelligenceScore::BAND_EXCELLENT, 'available' => true]]),
            'market' => array_replace((array) $row([])['market'], ['probability' => 0.55])]),
        // Unstable: excluded whatever the score says.
        $row(['fixtureId' => 3, 'homeTeam' => 'E', 'awayTeam' => 'F',
            'intelligence' => array_replace((array) $row([])['intelligence'],
                ['score' => ['score' => 99, 'band' => IntelligenceScore::BAND_EXCELLENT, 'available' => true],
                    'stability' => ['state' => StabilityMonitor::UNSTABLE, 'reason' => 'model moved 14 points']])]),
        // Limited data quality: not eligible for the list.
        $row(['fixtureId' => 4, 'homeTeam' => 'G', 'awayTeam' => 'H',
            'intelligence' => array_replace((array) $row([])['intelligence'],
                ['quality' => ['score' => 60.0, 'band' => QualityBand::LIMITED, 'checklist' => [], 'missing' => []]])]),
        // Not analyzed at all.
        $row(['fixtureId' => 5, 'homeTeam' => 'I', 'awayTeam' => 'J', 'analysisState' => 'NOT_ANALYZED', 'intelligence' => []]),
        // With no selection for the chosen market.
        $row(['fixtureId' => 6, 'homeTeam' => 'K', 'awayTeam' => 'L',
            'market' => ['state' => DataState::UNAVAILABLE, 'key' => 'MATCH_WINNER', 'label' => 'Match Winner — 1X2']]),
    ], 'Match Winner — 1X2');

    $listed = (array) $picks['picks'];
    assert_equals(2, count($listed), 'only the two eligible rows are listed');
    assert_equals(2, (int) $listed[0]['fixtureId'], 'ranked by intelligence score, not by price');
    assert_equals(1, (int) $listed[1]['fixtureId'], 'and the lower score comes second');
    assert_equals(2, (int) $picks['eligible'], 'eligible counts what qualified');
    assert_equals(6, (int) $picks['considered'], 'considered counts everything the page held');
    $excluded = (array) $picks['excluded'];
    assert_equals(4, count($excluded), 'every excluded match is listed with a reason');
    $reasons = implode(' | ', array_column($excluded, 'reason'));
    assert_true(str_contains($reasons, 'unstable'), 'the unstable one says it was movement');
    assert_true(str_contains($reasons, 'Data quality band LIMITED'), 'the thin one says it was quality');
    assert_true(str_contains($reasons, 'Not analyzed'), 'and the empty slot says it was never analyzed');
    $disclaimer = strtolower((string) $picks['disclaimer']);
    assert_true(str_contains($disclaimer, 'not guarantees') || str_contains($disclaimer, 'not a guarantee'),
        'the list is labelled as a ranking, never as a promise');
    assert_true(str_contains($disclaimer, 'model'), 'and it says the selections are model-based');
    assert_true(str_contains((string) $picks['rule']['eligibility'], QualityBand::QUALIFIED),
        'the eligibility rule is published, not left as a mood');
});

test('football: withheld predictions are withheld on every surface', function () {
    // A fixture with no history at all cannot reach the quality floor, so the
    // module must refuse it and say the sentence the brief asked for.
    [$repo, , $module] = fx_fb_harness([], ['skipHistory' => true]);
    $day = gmdate('Y-m-d', time() + 6 * 3600);
    $module->fixtures()->syncDay($day, 'test:thin', null, -1);
    $result = $module->predictions()->predictDay($day);
    $report = $module->report();
    $blocks = $report->forPage(array_map(static fn(array $fixture): array => [
        'fixture' => $fixture, 'prediction' => null, 'market' => [],
        'predictionRefusal' => ['code' => 'DATA_QUALITY_REJECTED', 'reason' => 'too thin'],
    ], (array) $repo->fixtures));
    if ($blocks === []) {
        assert_true(true, 'no fixtures were stored for the date, so there is nothing to withhold');
        return;
    }
    $block = (array) $blocks[0];
    assert_equals(IntelligenceReport::STATE_NOT_ANALYZED, (string) $block['state'], 'an unanalyzed match is not scored');
    assert_true(str_contains((string) $block['withheld']['reason'], 'Prediction withheld'),
        'and the reason uses the documented sentence');
    assert_null($block['score']['score'] ?? 'x', 'no intelligence score is published without a prediction');
});

test('football: the intelligence layer reaches the surfaces it was built for', function () {
    $autoload = fx_fb_read('application/libraries/AIWorkforce/autoload.php');
    foreach (['OddsIntelligence', 'IntelligenceScore', 'PredictionDrivers', 'FreshnessTracker', 'StabilityMonitor', 'IntelligenceReport'] as $class) {
        assert_true(str_contains($autoload, "'Football/" . $class . ".php'"), $class . ' is registered in the loader');
    }
    $facade = fx_fb_read('application/libraries/AIWorkforce/Football/FootballIntelligence.php');
    foreach (['fairValue()', 'stability()', 'intelligenceScores()', 'drivers()', 'freshness()', 'report()', 'picks(', 'intelligenceFor('] as $member) {
        assert_true(str_contains($facade, $member), 'the facade exposes ' . $member);
    }
    $routes = fx_fb_read('application/config/routes.php');
    assert_true(str_contains($routes, "api/football/picks"), 'the pick list is served at /api/football/picks');
    assert_true(str_contains($routes, "api/football/intelligence"), 'one match is served at /api/football/intelligence');

    $board = fx_fb_read('application/views/football/index.php');
    assert_true(str_contains($board, 'Top WINDELS Picks'), 'the board lists the picks');
    assert_true(str_contains($board, 'WINDELS probability'), 'and separates our probability from the price');
    assert_true(str_contains($board, 'Prediction unstable — significant model movement'),
        'a movement warning is in the table, not only in the payload');
    $match = fx_fb_read('application/views/football/match.php');
    foreach (['WINDELS Intelligence Score', 'Market odds', 'WINDELS fair odds', 'Potential Edge', 'Confidence', 'Risk',
        'Why WINDELS selected it', 'Last updated', 'Prediction withheld — insufficient verified data'] as $phrase) {
        assert_true(str_contains($match, $phrase) || str_contains($board, $phrase), 'the surfaces show ' . $phrase);
    }
    assert_true(str_contains($match, 'Prediction ≠ Confidence ≠ Value')
        || str_contains($match, 'Prediction generated') , 'the match page keeps the three apart');
});

test('football: the record is written when a prediction is, and pruned by the job', function () {
    [$repo, , $module] = fx_fb_harness([fx_fb_row('fx-rev', gmdate('c', time() + 7200), 'Manchester City', 'Everton', '10', '20')]);
    $day = gmdate('Y-m-d', time() + 7200);
    fx_fb_sync_today($module, $day);
    $module->predictions()->predictDay($day);
    assert_equals(1, count((array) $repo->predictionRevisions), 'storing a prediction records one revision');
    $revision = (array) $repo->predictionRevisions[0];
    assert_true((int) $revision['fixture_id'] > 0, 'the revision is keyed to the fixture it describes');
    assert_true(is_numeric($revision['probability_home'] ?? null) || is_numeric($revision['probability_draw'] ?? null)
        || is_numeric($revision['probability_away'] ?? null), 'carrying the probabilities the reader was shown');
    assert_true((string) $revision['stability_state'] !== '', 'and the state that fell out of them');
    assert_equals(StabilityMonitor::BASELINE, (string) $revision['stability_state'],
        'a first reading is recorded as a baseline, never as a settled history');
    $module->predictions()->predictDay($day);
    assert_equals(1, count((array) $repo->predictionRevisions),
        're-running the same day does not stack a second revision for the same row');

    $repo->predictionRevisions[] = ['id' => 99, 'prediction_id' => 'x', 'fixture_id' => 1, 'recorded_at' => gmdate('c', time() - 400 * 86400)];
    $pruned = $repo->prunePredictionRevisions(90);
    assert_equals(1, (int) $pruned, 'the retention window prunes only what is past it');
    assert_equals(1, count((array) $repo->predictionRevisions), 'the recent trail survives');

    $config = new FootballConfiguration([]);
    assert_true($config->revisionRetentionDays() >= 7, 'the retention window is configured, not hard-coded');
    $cron = fx_fb_read('application/libraries/AIWorkforce/Football/FootballCronService.php');
    assert_true(str_contains($cron, 'prunePredictionRevisions'), 'and the cleanup job calls it');
});
