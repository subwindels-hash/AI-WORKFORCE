<?php
namespace Aegis\Sports;
class CorrelationEngine
{
    /** Same fixture is high correlation; same competition is a conservative medium signal. */
    public function assess(array $candidate, array $selected): array
    {
        $level = 'LOW'; $reasons = [];
        foreach ($selected as $other) {
            if (($candidate['matchId'] ?? null) !== null && ($candidate['matchId'] ?? null) === ($other['matchId'] ?? null)) { $level = 'HIGH'; $reasons[] = 'SAME_MATCH'; break; }
            if (($candidate['competition'] ?? null) && ($candidate['competition'] ?? null) === ($other['competition'] ?? null)) { if ($level === 'LOW') $level = 'MEDIUM'; $reasons[] = 'SAME_COMPETITION'; }
        }
        return ['classification' => $level, 'score' => $level === 'HIGH' ? 1.0 : ($level === 'MEDIUM' ? .5 : 0.0), 'reasons' => $reasons];
    }
}
