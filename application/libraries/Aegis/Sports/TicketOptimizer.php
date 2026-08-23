<?php
namespace Aegis\Sports;

/** Exhaustive bounded search; selects only compliant combinations, never pads odds. */
class TicketOptimizer
{
    public function __construct(private CorrelationEngine $correlation = new CorrelationEngine()) {}
    public function optimize(array $candidates, array $config = []): array
    {
        $min = (float) ($config['targetOddsMin'] ?? 5.0); $max = (float) ($config['targetOddsMax'] ?? 8.0); $limit = (int) ($config['maxSelections'] ?? 5);
        $pool = array_values(array_filter($candidates, fn($c) => !empty($c['risk']['approved']) && ($c['risk']['classification'] ?? '') !== 'HIGH' && !empty($c['value']['qualified'])));
        $best = null;
        $search = function (array $chosen, int $start, float $odds) use (&$search, &$best, $pool, $min, $max, $limit) {
            if ($chosen && $odds >= $min && $odds <= $max) {
                $score = array_sum(array_map(fn($c) => (float) $c['value']['expectedValue'], $chosen));
                if ($best === null || $score > $best['score']) $best = ['score' => $score, 'selections' => $chosen, 'totalOdds' => $odds];
            }
            if (count($chosen) >= $limit || $odds >= $max) return;
            for ($i = $start; $i < count($pool); $i++) {
                $candidate = $pool[$i]; $corr = $this->correlation->assess($candidate, $chosen);
                if ($corr['classification'] === 'HIGH') continue;
                $next = $odds * (float) $candidate['value']['odds']; if ($next > $max) continue;
                $search(array_merge($chosen, [$candidate]), $i + 1, $next);
            }
        };
        $search([], 0, 1.0);
        if ($best === null) return ['status' => 'NO_QUALIFIED_TICKET', 'reason' => 'No candidate combination satisfies odds, risk, value, and correlation constraints', 'config' => $config];
        return ['status' => 'QUALIFIED', 'ticketId' => 'tkt_' . bin2hex(random_bytes(8)), 'totalOdds' => round($best['totalOdds'], 4), 'selectionCount' => count($best['selections']), 'selections' => $best['selections'], 'optimizationScore' => round($best['score'], 6), 'config' => $config];
    }
}
