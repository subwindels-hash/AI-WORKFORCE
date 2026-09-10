<?php
namespace AIWorkforce\Football;

/**
 * The WINDELS Intelligence Score: one number for *how well evidenced this read
 * of the match is*.
 *
 * It is deliberately not a fourth probability. The module keeps three questions
 * apart, and the score is a reading of the first and the evidence behind it:
 *
 *  - **prediction** — what the stored data says will happen (`OutcomePredictor`);
 *  - **confidence** — how sharply separated the model's own top outcome is
 *    (`OutcomePredictor::confidenceCeiling`);
 *  - **value** — how the model's estimate compares with a price
 *    (`OddsIntelligence`).
 *
 * The score answers "how much weight should the reader give the prediction and
 * its confidence", and it is the reason **the price is excluded**: a market that
 * agrees with us is not evidence that we are right, it is the thing being
 * measured. Mixing the two would let a busy betting line inflate a thin football
 * read, which is precisely the failure the module exists to avoid.
 *
 * Four rules:
 *
 *  1. **Every component is a stored measurement.** Confidence, data quality,
 *     grid coverage, calibration state and stability are all read off persisted
 *     rows; none is judged by feel, and each row prints the figure it used.
 *  2. **A component that cannot be measured is excluded, not zeroed.** No
 *     stability history means "unknown", so the remaining weights are
 *     renormalised over what is known and the exclusion is listed. Scoring an
 *     unknown as zero would punish a match for the absence of a record — and
 *     scoring it as average would invent one.
 *  3. **The arithmetic is published.** `score` is `Σ(value × weight) / Σ(weight)`
 *     over the components that were used, and the payload carries each term, so
 *     87 can always be shown to be 87 rather than asserted.
 *  4. **The band is not a licence.** `EXCELLENT` describes evidence, not outcome;
 *     a match can be excellently evidenced and still finish the other way, which
 *     is why the disclaimer travels with the score.
 */
final class IntelligenceScore
{
    public const BAND_EXCELLENT = 'EXCELLENT';
    public const BAND_STRONG = 'STRONG';
    public const BAND_MODERATE = 'MODERATE';
    public const BAND_THIN = 'THIN';
    public const BAND_INSUFFICIENT = 'INSUFFICIENT_EVIDENCE';

    /** The disclaimer that must travel with a score. */
    public const DISCLAIMER = 'The intelligence score measures how well evidenced a prediction is. '
        . 'It is not the probability that the prediction comes true, and no score makes an outcome certain.';

    private const LABELS = [
        'confidence' => 'Model confidence',
        'dataQuality' => 'Data quality',
        'coverage' => 'Scoreline coverage',
        'calibration' => 'Calibration',
        'stability' => 'Prediction stability',
    ];

    public function __construct(private FootballConfiguration $config) {}

    /**
     * Compose the score from stored measurements.
     *
     * @param array{confidence:?float,confidenceBasis:?string,dataQuality:?int,band:?string,coverage:?float,calibrationState:?string,stability:?array} $inputs
     * @return array{score:?int,band:string,label:string,components:list<array<string,mixed>>,excluded:list<array<string,mixed>>,weights:array<string,float>,method:string,note:string,disclaimer:string}
     */
    public function compute(array $inputs): array
    {
        $weights = $this->config->intelligenceWeights();
        $band = strtoupper((string) ($inputs['band'] ?? QualityBand::REJECTED));
        $confidence = is_numeric($inputs['confidence'] ?? null) ? max(0.0, min(100.0, (float) $inputs['confidence'])) : null;
        $quality = is_numeric($inputs['dataQuality'] ?? null) ? (int) $inputs['dataQuality'] : null;
        $coverage = is_numeric($inputs['coverage'] ?? null) ? max(0.0, min(1.0, (float) $inputs['coverage'])) : null;
        // No default here on purpose: a prediction that does not say whether it was
        // calibrated has not supplied a measurement, and scoring that as
        // CALIBRATION_PENDING would be giving away 40 points for an absence.
        $calibration = strtoupper(trim((string) ($inputs['calibrationState'] ?? '')));
        $stability = is_array($inputs['stability'] ?? null) ? $inputs['stability'] : null;

        $parts = [];
        // Confidence enters the score as the share of certainty above the coin
        // flip it carries, scaled to 0–100: 50% separation is worth nothing in a
        // two-horse market, and a model that says "61%" is not describing a
        // near-certain event. The linear reading is stated in the component note
        // so nobody has to guess what the figure is a fraction of.
        if ($confidence !== null) {
            $parts['confidence'] = ['value' => round($confidence, 1), 'state' => DataState::AVAILABLE,
                'note' => 'model confidence ' . number_format($confidence, 1) . '%'
                    . (strtoupper((string) ($inputs['confidenceBasis'] ?? '')) === 'CALIBRATED'
                        ? ' (calibrated against settled history)' : ' (raw: not yet calibrated)')];
        } else {
            $parts['confidence'] = ['state' => DataState::UNAVAILABLE, 'note' => 'no confidence figure is stored for this match'];
        }
        if ($quality !== null) {
            $parts['dataQuality'] = ['value' => (float) $quality, 'state' => DataState::AVAILABLE,
                'note' => 'weighted evidence coverage ' . $quality . '/100, band ' . $band];
        } else {
            $parts['dataQuality'] = ['state' => DataState::UNAVAILABLE, 'note' => 'no data-quality assessment is stored for this match'];
        }
        if ($coverage !== null) {
            $parts['coverage'] = ['value' => round($coverage * 100.0, 1), 'state' => DataState::AVAILABLE,
                'note' => 'the stored scoreline grid covers ' . round($coverage * 100.0, 1) . '% of the distribution'];
        } else {
            $parts['coverage'] = ['state' => DataState::UNAVAILABLE, 'note' => 'no scoreline grid was stored for this match'];
        }
        // Calibration is a state, not a degree: a temperature fitted from settled
        // history is worth full marks, an uncalibrated figure is worth what an
        // uncalibrated figure is worth, and anything else is not scored at all.
        if ($calibration === CalibrationService::CALIBRATED) {
            $parts['calibration'] = ['value' => 100.0, 'state' => DataState::AVAILABLE,
                'note' => 'probabilities are calibrated against settled history'];
        } elseif ($calibration === CalibrationService::PENDING) {
            $parts['calibration'] = ['value' => 40.0, 'state' => DataState::LIMITED,
                'note' => CalibrationService::PENDING . ': the figure is the model\'s own, with no settled history to correct it against'];
        } else {
            $parts['calibration'] = ['state' => DataState::UNAVAILABLE, 'note' => 'calibration state is ' . $calibration];
        }
        if ($stability !== null) {
            $state = strtoupper((string) ($stability['state'] ?? ''));
            $movement = is_numeric($stability['movementPoints'] ?? null) ? (float) $stability['movementPoints'] : null;
            $detail = $movement === null ? 'stored stability state ' . $state : 'stored stability state ' . $state
                . ' (' . ($movement >= 0 ? '+' : '') . round($movement, 1) . ' points of movement on the last re-read)';
            if ($state === StabilityMonitor::STABLE) {
                $parts['stability'] = ['value' => 100.0, 'state' => DataState::AVAILABLE, 'note' => $detail];
            } elseif ($state === StabilityMonitor::MOVED) {
                $parts['stability'] = ['value' => 55.0, 'state' => DataState::LIMITED, 'note' => $detail];
            } elseif ($state === StabilityMonitor::UNSTABLE) {
                $parts['stability'] = ['value' => 10.0, 'state' => DataState::LIMITED, 'note' => $detail];
            } else {
                // BASELINE: one observation, so there is no movement to measure.
                // It is excluded rather than counted as either stable or broken.
                $parts['stability'] = ['state' => DataState::UNAVAILABLE,
                    'note' => 'BASELINE: this is the first stored reading of the match, so movement cannot be measured yet'];
            }
        } else {
            $parts['stability'] = ['state' => DataState::UNAVAILABLE, 'note' => 'no revision history exists for this prediction'];
        }

        $components = [];
        $excluded = [];
        $total = 0.0;
        $usedWeight = 0.0;
        foreach ($weights as $key => $weight) {
            $part = $parts[$key] ?? ['state' => DataState::UNAVAILABLE, 'note' => 'not supplied'];
            $measurable = $weight > 0.0 && isset($part['value']) && is_numeric($part['value'])
                && ($part['state'] ?? '') !== DataState::UNAVAILABLE;
            if (!$measurable) {
                $excluded[] = ['key' => $key, 'label' => self::LABELS[$key] ?? $key, 'weight' => $weight,
                    'state' => (string) ($part['state'] ?? DataState::UNAVAILABLE),
                    'reason' => (string) ($part['note'] ?? 'no stored measurement'),
                    // An operator who zeroes a weight is making a choice, and the
                    // score must say which choice it made rather than looking
                    // like a gap in the data.
                    'cause' => $weight <= 0.0 ? 'WEIGHT_ZEROED_BY_CONFIGURATION' : 'NOT_MEASURABLE'];
                continue;
            }
            $value = max(0.0, min(100.0, (float) $part['value']));
            $contribution = $value * $weight;
            $total += $contribution;
            $usedWeight += $weight;
            $components[] = ['key' => $key, 'label' => self::LABELS[$key] ?? $key, 'value' => round($value, 1),
                'weight' => round($weight, 4), 'contribution' => round($contribution, 2),
                'state' => (string) ($part['state'] ?? DataState::AVAILABLE), 'note' => (string) ($part['note'] ?? '')];
        }

        if ($usedWeight <= 0.0) {
            return $this->empty($weights, $excluded, 'No component of the score could be measured from stored data, '
                . 'so no score is given.');
        }
        // Renormalised over the components that were actually measured: excluding
        // a component must not silently scale the rest up to a perfect score. The
        // components are already held on a 0–100 scale, so the weighted mean *is*
        // the score — multiplying by 100 again would publish 8400/100.
        $score = (int) round(max(0.0, min(100.0, $total / $usedWeight)));
        $bands = $this->config->intelligenceBands();
        $bandKey = match (true) {
            $score >= $bands['excellent'] => self::BAND_EXCELLENT,
            $score >= $bands['strong'] => self::BAND_STRONG,
            $score >= $bands['moderate'] => self::BAND_MODERATE,
            $score >= $bands['thin'] => self::BAND_THIN,
            default => self::BAND_INSUFFICIENT,
        };
        return [
            'score' => $score,
            'band' => $bandKey,
            'label' => self::bandLabel($bandKey),
            'components' => $components,
            'excluded' => $excluded,
            'weights' => array_map(static fn(float $w): float => round($w, 4), $weights),
            'weightsUsed' => round($usedWeight, 4),
            'method' => 'WEIGHTED_MEAN_OF_STORED_MEASUREMENTS_RENORMALISED',
            'marketPriceIncluded' => false,
            'thresholds' => $bands,
            'note' => count($excluded) === 0
                ? 'All five stored components were measurable, so the score is the plain weighted sum.'
                : 'Scored on ' . count($components) . ' of 5 stored components; ' . count($excluded)
                    . ' could not be measured and was excluded, with the remaining weights renormalised.',
            'disclaimer' => self::DISCLAIMER,
            'generatedAt' => gmdate('c'),
        ];
    }

    /** @return array<string,mixed> */
    private function empty(array $weights, array $excluded, string $note): array
    {
        return ['score' => null, 'band' => self::BAND_INSUFFICIENT, 'label' => self::bandLabel(self::BAND_INSUFFICIENT),
            'components' => [], 'excluded' => $excluded,
            'weights' => array_map(static fn(float $w): float => round($w, 4), $weights), 'weightsUsed' => 0.0,
            'method' => 'WEIGHTED_MEAN_OF_STORED_MEASUREMENTS_RENORMALISED', 'marketPriceIncluded' => false,
            'thresholds' => $this->config->intelligenceBands(), 'note' => $note,
            'disclaimer' => self::DISCLAIMER, 'generatedAt' => gmdate('c')];
    }

    public static function bandLabel(string $band): string
    {
        return match ($band) {
            self::BAND_EXCELLENT => 'Excellent evidence',
            self::BAND_STRONG => 'Strong evidence',
            self::BAND_MODERATE => 'Moderate evidence',
            self::BAND_THIN => 'Thin evidence',
            default => 'Insufficient evidence to score',
        };
    }
}
