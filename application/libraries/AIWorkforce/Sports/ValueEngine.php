<?php
namespace AIWorkforce\Sports;

/**
 * Value / edge assessment — the ONLY place where the WINDELS model
 * probability meets real bookmaker odds. Both sides stay clearly labelled:
 *
 *   modelProbability    the WINDELS calibrated probability (never derived from odds)
 *   fairOdds            1 / model probability — the model's own fair price
 *   marketOdds          the real bookmaker decimal price (never invented)
 *   impliedProbability  1 / market odds — what the bookmaker's price implies
 *   edge                model probability − implied probability
 *   expectedValue       model probability × market odds − 1
 *
 * Nothing here is ever estimated: without a real market price (> 1.0) there
 * is no value assessment at all (ODDS_UNAVAILABLE), never a fabricated one —
 * and a price that fails plausibility (non-finite, or above the market's
 * ceiling) is rejected as UNREALISTIC_ODDS rather than priced.
 */
class ValueEngine
{
    public function assess(array $prediction, array $odds): array
    {
        if (($prediction['decision'] ?? '') !== 'PREDICTION_READY') return ['qualified' => false, 'reason' => $prediction['reason'] ?? 'NO_PREDICTION', 'missingFields' => $prediction['missingFields'] ?? []];
        $decimal = (float) ($odds['decimalOdds'] ?? $odds['decimal_odds'] ?? 0);
        if ($decimal <= 1) return ['qualified' => false, 'reason' => 'ODDS_UNAVAILABLE'];
        // A corrupted price (NaN/INF from bad arithmetic, or an absurd decimal
        // that slipped past ingestion) would manufacture expected value out of
        // nothing — p × 9999 − 1 is always "positive". Reject it as what it
        // is instead of letting it qualify a ticket leg.
        if (!is_finite($decimal) || $decimal > OddsBounds::maxFor(isset($odds['market']) ? (string) $odds['market'] : null)) {
            return ['qualified' => false, 'reason' => 'UNREALISTIC_ODDS', 'marketOdds' => $decimal, 'odds' => $decimal];
        }
        $implied = 1 / $decimal;
        $calibrated = (float) $prediction['calibratedProbability'];
        $fair = $calibrated > 0 ? 1 / $calibrated : null;
        $ev = $calibrated * $decimal - 1;
        $edge = $calibrated - $implied;
        return [
            'qualified' => $ev > 0,
            'reason' => $ev > 0 ? null : 'LOW_MODEL_EDGE',
            'marketOdds' => $decimal,
            'impliedProbability' => round($implied, 6),
            'modelProbability' => $calibrated,
            'fairOdds' => $fair !== null ? round($fair, 4) : null,
            'edge' => round($edge, 6),
            'expectedValue' => round($ev, 6),
            'odds' => $decimal,
        ];
    }
}
