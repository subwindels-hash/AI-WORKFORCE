<?php
namespace AIWorkforce\Sports;

/**
 * Risk Engine (spec §13). Rejected candidates can never enter a ticket.
 *
 * The confidence floor is NOT re-checked here: the pipeline gates confidence
 * on the WINDELS confidence value once, in stage order (Prediction →
 * Probability → Confidence → Data Quality → Value/Edge → Risk), and a
 * duplicated gate here produced double LOW_CONFIDENCE rejections for the
 * same candidate. Odds freshness is likewise gated upstream (before the
 * prediction is generated), so it is not repeated as a risk reason.
 *
 * A leg that passed every configured gate (quality floor, minimum edge,
 * liquidity, suspension) is at most MEDIUM risk and remains APPROVED: the
 * ticket optimizer simply ranks LOW-risk legs ahead of MEDIUM. HIGH risk is
 * never approved (only the explicit AGGRESSIVE level may take it), so a
 * missing optional enrichment — which lowers the quality score a few points
 * from 90 to 89 without failing the 80 floor — can no longer kill an
 * otherwise qualified day. Explicit risk signals (suspension, insufficient
 * liquidity, volatile price movement) still reject.
 *
 * $context may carry:
 *   marketSuspended (bool), liquidity (float), oddsMovement (float,
 *   absolute change from opening odds)
 */
class RiskEngine
{
    public const MAX_ODDS_MOVEMENT = 0.5; // 50% odds drift is treated as volatile

    public function assess(array $value, array $quality, array $config = [], array $context = []): array
    {
        $minQuality = (int) ($config['min_data_quality'] ?? $config['minDataQuality'] ?? 80);
        $minEv = (float) ($config['min_expected_value'] ?? $config['minExpectedValue'] ?? 0.02);
        $minLiquidity = $config['min_liquidity'] ?? $config['minLiquidity'] ?? null;
        $reasons = [];
        if (!empty($context['marketSuspended'])) $reasons[] = 'MARKET_SUSPENDED';
        if (empty($value['qualified'])) $reasons[] = $value['reason'] ?? 'NO_PREDICTION';
        if (($quality['score'] ?? 0) < $minQuality) $reasons[] = 'LOW_DATA_QUALITY';
        if (($value['expectedValue'] ?? -1) < $minEv) $reasons[] = 'LOW_MODEL_EDGE';
        if ($minLiquidity !== null && isset($context['liquidity']) && is_numeric($context['liquidity']) && (float) $context['liquidity'] < (float) $minLiquidity) $reasons[] = 'INSUFFICIENT_LIQUIDITY';
        if ($reasons) return ['classification' => 'REJECTED', 'approved' => false, 'reasons' => array_values(array_unique($reasons))];
        $risk = ($quality['score'] >= 90 && ($value['expectedValue'] ?? 0) >= .08) ? 'LOW' : (($quality['score'] >= 80) ? 'MEDIUM' : 'HIGH');
        // Volatile market movement upgrades any class to HIGH (never downgrades).
        $order = ['LOW' => 0, 'MEDIUM' => 1, 'HIGH' => 2];
        if (isset($context['oddsMovement']) && is_numeric($context['oddsMovement']) && abs((float) $context['oddsMovement']) > self::MAX_ODDS_MOVEMENT) {
            if ($order[$risk] < $order['HIGH']) $risk = 'HIGH';
            $reasons[] = 'ODDS_VOLATILE';
        }
        // LOW and MEDIUM legs passed every gate and are approved (the
        // optimizer ranks LOW first). HIGH risk is blocked under the default
        // CONSERVATIVE and MODERATE levels; only an explicit AGGRESSIVE
        // operator level may take a high-risk leg, never silently.
        $riskLevel = strtoupper((string) ($config['risk_level'] ?? $config['riskLevel'] ?? ''));
        $approved = $risk !== 'HIGH' || $riskLevel === 'AGGRESSIVE';
        return ['classification' => $risk, 'approved' => $approved, 'reasons' => array_values(array_unique($reasons))];
    }
}
