<?php
namespace AIWorkforce\Sports;

/**
 * Produces versioned features from explicitly verified numeric inputs.
 *
 * PROGRESSIVE, NOT ALL-OR-NOTHING (requirements #2, #3 and #14)
 * -------------------------------------------------------------
 * The previous version demanded all FOUR venue goal rates and rejected the
 * fixture as INSUFFICIENT_DATA when even one was absent. On a real provider
 * day that is what produced "15 fresh-odds fixtures → 0 sufficient-data": a
 * team-statistics lookup that answered for the home side but not the away
 * side threw away the home side's real numbers as well.
 *
 * The engine now builds from what is actually verified:
 *
 *   • every rate a provider DID supply is used as measured;
 *   • a rate the provider did not supply may be covered by the fixture's own
 *     VERIFIED competition baseline (the league table's goals-per-match,
 *     computed from the same standings response the resolver already read —
 *     a real measurement carried on the fixture, never a guess). Every such
 *     substitution is named in `substitutions` and lowers `coverage`;
 *   • at least MIN_MEASURED_RATES of the four rates must be genuinely
 *     measured, otherwise there is no fixture-specific signal at all and the
 *     build is rejected INSUFFICIENT_DATA with the exact missing fields.
 *
 * Nothing is ever invented: with no measured rates and no verified baseline
 * the answer is still "not predictable", and the caller reports it as such.
 */
class FeatureEngineeringEngine
{
    public const VERSION = 'sports-features-v2';

    /** Verified numeric form inputs the features are built from. */
    public const REQUIRED_FORM_FIELDS = ['homeGoalsPerMatch', 'awayGoalsPerMatch', 'homeConcededPerMatch', 'awayConcededPerMatch'];

    /**
     * How many of the four rates must be MEASURED (not baseline-covered)
     * before a prediction carries fixture-specific information. Below it the
     * model would be pricing the league average, so the build is rejected.
     */
    public const MIN_MEASURED_RATES = 2;

    /** Which side/aspect each rate belongs to, for baseline coverage. */
    private const RATE_KIND = [
        'homeGoalsPerMatch' => 'attack',
        'awayGoalsPerMatch' => 'attack',
        'homeConcededPerMatch' => 'defense',
        'awayConcededPerMatch' => 'defense',
    ];

    public function build(array $intelligence): array
    {
        if (($intelligence['decision'] ?? '') !== 'INTELLIGENCE_READY') {
            return $this->reject(['recentForm'], [], 'match intelligence is not ready');
        }
        $inputs = is_array($intelligence['inputs'] ?? null) ? $intelligence['inputs'] : [];
        $form = is_array($inputs['recentForm'] ?? null) ? $inputs['recentForm'] : [];
        $baseline = self::baselineOf($inputs);

        $rates = [];
        $measured = [];
        $substituted = [];
        $missing = [];
        foreach (self::REQUIRED_FORM_FIELDS as $key) {
            if (isset($form[$key]) && is_numeric($form[$key]) && (float) $form[$key] >= 0) {
                $rates[$key] = (float) $form[$key];
                $measured[] = $key;
                continue;
            }
            $covered = $baseline === null ? null : ($baseline[self::RATE_KIND[$key]] ?? null);
            if ($covered !== null) {
                $rates[$key] = (float) $covered;
                $substituted[$key] = $baseline['source'];
                continue;
            }
            $missing[] = 'recentForm.' . $key;
        }

        if (count($measured) < self::MIN_MEASURED_RATES) {
            // Not enough of THIS fixture's own numbers exist. Name every rate
            // that is neither measured nor covered, so the rejection says what
            // is missing instead of a bare INSUFFICIENT_DATA.
            $unmeasured = array_values(array_diff(
                array_map(fn(string $k): string => 'recentForm.' . $k, self::REQUIRED_FORM_FIELDS),
                array_map(fn(string $k): string => 'recentForm.' . $k, $measured)
            ));
            return $this->reject($missing !== [] ? $missing : $unmeasured, $measured,
                sprintf('only %d of %d verified team goal rates are available (%d required)',
                    count($measured), count(self::REQUIRED_FORM_FIELDS), self::MIN_MEASURED_RATES));
        }
        if ($missing !== []) {
            return $this->reject($missing, $measured, 'no verified competition baseline is stored to cover the missing rate(s)');
        }

        $coverage = round(count($measured) / count(self::REQUIRED_FORM_FIELDS), 4);
        $features = [
            'expectedGoalsProxy' => round(($rates['homeGoalsPerMatch'] + $rates['awayGoalsPerMatch'] + $rates['homeConcededPerMatch'] + $rates['awayConcededPerMatch']) / 2, 4),
            'homeAttack' => $rates['homeGoalsPerMatch'], 'awayAttack' => $rates['awayGoalsPerMatch'],
            'homeDefenseConceded' => $rates['homeConcededPerMatch'], 'awayDefenseConceded' => $rates['awayConcededPerMatch'],
        ];
        return [
            'ok' => true,
            'version' => self::VERSION,
            'features' => $features,
            // How much of the model's input is this fixture's own measurement.
            'coverage' => $coverage,
            'measuredFields' => $measured,
            'substitutedFields' => $substituted,
            'inputSources' => ['recentForm' => $form['source'] ?? null]
                + ($substituted === [] ? [] : ['competitionBaseline' => $baseline['source']]),
        ];
    }

    /**
     * A fixture's VERIFIED competition baseline: goals scored and conceded per
     * team per match in this competition, as measured by the provider's own
     * league table. Absent or malformed → null (never a default constant).
     *
     * @return array{attack:float,defense:float,source:string}|null
     */
    public static function baselineOf(array $inputs): ?array
    {
        $candidates = [
            $inputs['competitionBaseline'] ?? null,
            is_array($inputs['recentForm'] ?? null) ? ($inputs['recentForm']['competitionBaseline'] ?? null) : null,
        ];
        foreach ($candidates as $baseline) {
            if (!is_array($baseline)) continue;
            $attack = $baseline['goalsPerMatch'] ?? $baseline['attack'] ?? null;
            $defense = $baseline['concededPerMatch'] ?? $baseline['defense'] ?? $attack;
            if (!is_numeric($attack) || (float) $attack <= 0) continue;
            if (!is_numeric($defense) || (float) $defense <= 0) continue;
            return [
                'attack' => (float) $attack,
                'defense' => (float) $defense,
                'source' => (string) ($baseline['source'] ?? 'competition-baseline'),
            ];
        }
        return null;
    }

    /** @param list<string> $missing */
    private function reject(array $missing, array $measured, string $note): array
    {
        return [
            'ok' => false,
            'reason' => 'INSUFFICIENT_DATA',
            'version' => self::VERSION,
            'features' => [],
            'coverage' => round(count($measured) / count(self::REQUIRED_FORM_FIELDS), 4),
            'measuredFields' => array_values($measured),
            'missingFields' => array_values(array_unique($missing !== [] ? $missing : ['recentForm'])),
            'note' => $note,
        ];
    }
}
