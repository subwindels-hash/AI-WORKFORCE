<?php
namespace Aegis\Sports;
class RiskEngine
{
    public function assess(array $value, array $quality, array $config = []): array
    {
        $minQuality = (int) ($config['minDataQuality'] ?? 75); $minEv = (float) ($config['minExpectedValue'] ?? 0.02);
        $reasons = [];
        if (!$value['qualified']) $reasons[] = $value['reason'];
        if (($quality['score'] ?? 0) < $minQuality) $reasons[] = 'LOW_DATA_QUALITY';
        if (empty($quality['eligibleForTicket'])) $reasons[] = 'STALE_OR_INCOMPLETE_DATA';
        if (($value['expectedValue'] ?? -1) < $minEv) $reasons[] = 'LOW_MODEL_EDGE';
        if ($reasons) return ['classification' => 'REJECTED', 'approved' => false, 'reasons' => array_values(array_unique($reasons))];
        $risk = ($quality['score'] >= 90 && $value['expectedValue'] >= .08) ? 'LOW' : (($quality['score'] >= 80) ? 'MEDIUM' : 'HIGH');
        return ['classification' => $risk, 'approved' => $risk !== 'HIGH', 'reasons' => $risk === 'HIGH' ? ['HIGH_RISK'] : []];
    }
}
