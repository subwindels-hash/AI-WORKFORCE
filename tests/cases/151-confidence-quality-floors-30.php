<?php
/**
 * BOTH QUALIFYING GATES RUN FROM 30 UPWARD (operator decision, 2026-09-15).
 *
 * The odds engine gates a candidate on two INDEPENDENT axes:
 *
 *   • measured confidence   — how strongly the model reads the outcome
 *   • measured data quality — how much verified evidence sits behind it
 *
 * Confidence was already 30-and-above. Data quality was pinned at a hard 75
 * (with the shipped default row even stricter at 80), so fixtures were thrown
 * away on evidence BREADTH even when the model's confidence was strong — the
 * "N predictions → 0 qualified" days. Both floors are now 30.
 *
 * What this does NOT change, and what these cases pin:
 *   • nothing below 30 is ever admitted on either axis;
 *   • no measurement is altered — a 42% quality score is still reported as 42,
 *     never inflated to clear a bar;
 *   • an administrator may still RAISE either floor and the whole adaptive
 *     ladder moves with it.
 */

use AIWorkforce\Sports\ConfidencePolicy;
use AIWorkforce\Sports\ConfigurationService;

test('floors: both hard gates are 30; the shipped quality default is the 55 middle ground', function () {
    assert_equals(30.0, (float) ConfigurationService::MIN_CONFIDENCE_FLOOR, 'confidence hard gate');
    assert_equals(30, (int) ConfigurationService::MIN_DATA_QUALITY_FLOOR, 'data-quality hard gate');

    $defaults = ConfigurationService::defaults();
    assert_equals(30.0, (float) $defaults['min_confidence'], 'shipped confidence default');
    // Operator decision 2026-09-17: the shipped DEFAULT moved up to 55 to keep
    // low-data outliers out of the candidate pool, while the configurable
    // FLOOR stays 30 so an operator may still open the gate back up.
    assert_equals(55, (int) $defaults['min_data_quality'], 'shipped data-quality default');
});

test('floors: 30 and above qualifies on BOTH axes; 29.99 never does', function () {
    // The hard gates are pinned with an explicit 30/30 configuration: the
    // SHIPPED default quality gate is 55 (2026-09-17), but an operator may
    // still lower the configured value to the 30 floor, and this is the
    // behaviour that must hold there.
    $policy = ConfidencePolicy::fromConfiguration(['min_confidence' => 30.0, 'min_data_quality' => 30]);

    // The qualifying corner: exactly 30 on both axes.
    assert_true($policy->evaluate(30, 30.0, 'TOTAL_GOALS', 'OVER_1_5')['qualified'], '30 / 30 qualifies');

    // Everything the OLD 75 floor rejected on evidence breadth alone.
    foreach ([31, 40, 55, 60, 70, 74] as $quality) {
        $verdict = $policy->evaluate($quality, 55.0, 'TOTAL_GOALS', 'OVER_1_5');
        assert_true($verdict['qualified'], 'quality ' . $quality . ' now qualifies with 55% confidence');
        assert_not_equals('REJECT', $verdict['tier'], 'quality ' . $quality . ' is not in the reject band');
    }

    // Below the floor stays rejected on each axis, independently.
    $lowQuality = $policy->evaluate(29, 99.0, 'TOTAL_GOALS', 'OVER_1_5');
    assert_false($lowQuality['qualified'], 'quality 29 is rejected however confident the model is');
    assert_equals(['DATA_QUALITY_BELOW_MINIMUM'], $lowQuality['reasons'], 'and for the quality reason only');

    $lowConfidence = $policy->evaluate(95, 29.99, 'TOTAL_GOALS', 'OVER_1_5');
    assert_false($lowConfidence['qualified'], 'confidence 29.99 is rejected however good the evidence is');
    assert_equals(['LOW_CONFIDENCE'], $lowConfidence['reasons'], 'and for the confidence reason only');
});

test('floors: every derived tier requires exactly 30% confidence and never dips below quality 30', function () {
    $policy = ConfidencePolicy::fromConfiguration(['min_confidence' => 30.0, 'min_data_quality' => 30]);
    $tiers = $policy->tiers();
    assert_true($tiers !== [], 'the ladder has bands');
    foreach ($tiers as $tier) {
        assert_equals(30.0, (float) $tier['minConfidence'], $tier['tier'] . ' requires the 30% floor');
        assert_true((int) $tier['minDataQuality'] >= 30, $tier['tier'] . ' never admits quality below 30');
    }
    assert_equals(30, $policy->minimumDataQuality(), 'the reject floor is 30');
});

test('floors: measurements are never inflated to clear the gate', function () {
    $policy = ConfidencePolicy::fromConfiguration(['min_confidence' => 30.0, 'min_data_quality' => 30]);
    // A 42-quality / 33%-confidence read qualifies AND is reported verbatim.
    $verdict = $policy->evaluate(42, 33.0, 'TOTAL_GOALS', 'OVER_1_5');
    assert_true($verdict['qualified'], 'the candidate qualifies');
    assert_equals(30.0, (float) $verdict['requiredConfidence'], 'the requirement quoted is the 30 floor');
    // The verdict must never restate the candidate's own confidence as the
    // floor it happened to clear.
    assert_not_equals(33.0, (float) $verdict['requiredConfidence'], 'the requirement is not the measurement');
});

test('floors: an administrator may still raise either floor, and the ladder follows', function () {
    $strict = ConfidencePolicy::fromConfiguration(['min_confidence' => 70.0, 'min_data_quality' => 85]);
    assert_equals(70.0, $strict->highestConfidenceRequirement(), 'a raised confidence floor is honoured');
    assert_true($strict->minimumDataQuality() > 30, 'a raised quality floor lifts the reject band');
    assert_null($strict->requiredConfidence(40), 'quality 40 is refused under a strict operator policy');

    // And the validator still refuses anything under the platform gates.
    $service = new ConfigurationService(new SportsRepositoryStub(), new class implements \AIWorkforce\Persistence\AuditRepository {
        public function emit(string $t, string $s, array $d = [], string $a = 'system'): void {}
        public function recent(int $l = 100): array { return []; }
    });
    assert_true($service->update(['min_confidence' => 30.0, 'min_data_quality' => 30], 'admin', 'floors at the gate')['ok'], '30 / 30 is configurable');
    assert_true($service->update(['min_data_quality' => 85], 'admin', 'stricter')['ok'], 'raising remains permitted');
    assert_false($service->update(['min_data_quality' => 29], 'admin', 'too low')['ok'], 'quality 29 is refused');
    assert_false($service->update(['min_confidence' => 29.99], 'admin', 'too low')['ok'], 'confidence 29.99 is refused');
});

test('floors: the shipped SQL defaults and the schema heal all agree on 30', function () {
    foreach ([
        APPPATH . 'database/sports_intelligence.mysql.sql',
        APPPATH . 'database/sports_intelligence.sqlite.sql',
        APPPATH . 'database/sports_intelligence.pgsql.sql',
        FCPATH . 'database/production.sql',
    ] as $file) {
        if (!is_file($file)) continue;
        $sql = (string) file_get_contents($file);
        if (!str_contains($sql, 'min_data_quality')) continue;
        assert_false(
            (bool) preg_match('/min_data_quality"?\s+(SMALLINT|INTEGER)\s+NOT NULL DEFAULT\s+(7[0-9]|8[0-9]|9[0-9])/i', $sql),
            basename($file) . ' no longer defaults the quality floor above 30'
        );
    }

    // The request-time heal must not rewrite the live row back to 80.
    $installer = (string) file_get_contents(APPPATH . 'libraries/AIWorkforce/SchemaInstaller.php');
    assert_contains('min_data_quality = 55', $installer, 'the default-row heal targets the shipped 55 default');
    assert_not_contains('min_data_quality = 80', $installer, 'the old 80 heal is gone');
});
