<?php
namespace AIWorkforce\Sports;

/**
 * Correlation control layer (spec §14).
 *
 * HIGH  — same match, or both outcomes depend on the same team (a team's
 *         result cannot win both legs of two different matches).
 * MEDIUM — same competition (league-level conditions, e.g. a shared cup tie).
 * LOW   — unrelated matches.
 */
class CorrelationEngine
{
    public function assess(array $candidate, array $selected): array
    {
        $level = 'LOW';
        $reasons = [];
        $candidateTeams = $this->teams($candidate);
        foreach ($selected as $other) {
            if (($candidate['matchId'] ?? null) !== null && ($candidate['matchId'] ?? null) === ($other['matchId'] ?? null)) {
                $level = 'HIGH';
                $reasons[] = 'SAME_MATCH';
                break;
            }
            $sharedTeams = array_intersect($candidateTeams, $this->teams($other));
            if ($sharedTeams) {
                if ($level === 'LOW') $level = 'HIGH';
                $reasons[] = 'SAME_TEAM_' . implode('+', array_map('strval', array_values($sharedTeams)));
            }
            $competition = $this->competitionOf($candidate);
            if ($competition !== null && $competition === $this->competitionOf($other)) {
                if ($level === 'LOW') $level = 'MEDIUM';
                $reasons[] = 'SAME_COMPETITION';
            }
        }
        return ['classification' => $level, 'score' => $level === 'HIGH' ? 1.0 : ($level === 'MEDIUM' ? .5 : 0.0), 'reasons' => array_values(array_unique($reasons))];
    }

    /** Worst pairwise correlation class across a set of selections. */
    public function classifySelections(array $selections): array
    {
        $worst = 'LOW';
        $reasons = [];
        $n = count($selections);
        for ($i = 0; $i < $n; $i++) {
            $pair = $this->assess($selections[$i], array_slice($selections, $i + 1));
            if (in_array($pair['classification'], ['HIGH', 'MEDIUM'], true)) $reasons = array_merge($reasons, $pair['reasons']);
            $order = ['LOW' => 0, 'MEDIUM' => 1, 'HIGH' => 2];
            if ($order[$pair['classification']] > $order[$worst]) $worst = $pair['classification'];
        }
        return ['classification' => $worst, 'reasons' => array_values(array_unique($reasons))];
    }

    /**
     * Teams both shapes carry: flat candidates state them top-level, pipeline
     * candidates nest them under `match`. Reading only the top level silently
     * graded every same-team pair LOW in production — the nested read is what
     * makes SAME_TEAM fire on real ticket candidates.
     *
     * @return array<int,string>
     */
    private function teams(array $candidate): array
    {
        $t = [];
        $pools = [$candidate];
        if (is_array($candidate['match'] ?? null)) $pools[] = $candidate['match'];
        foreach ($pools as $pool) {
            foreach (['homeTeam', 'awayTeam', 'home_team', 'away_team'] as $key) {
                if (!empty($pool[$key])) $t[] = (string) $pool[$key];
            }
        }
        return array_values(array_unique($t));
    }

    /** Competition from either candidate shape (flat or pipeline-nested). */
    private function competitionOf(array $candidate): ?string
    {
        $raw = $candidate['competition'] ?? null;
        if (($raw === null || $raw === '') && is_array($candidate['match'] ?? null)) {
            $raw = $candidate['match']['competition'] ?? null;
        }
        return ($raw === null || $raw === '') ? null : (string) $raw;
    }
}
