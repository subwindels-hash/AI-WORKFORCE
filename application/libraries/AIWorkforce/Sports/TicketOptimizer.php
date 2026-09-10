<?php
namespace AIWorkforce\Sports;

/**
 * Ticket optimization (spec §16/§17).
 *
 * Candidate pool → remove invalid/low-quality/stale/high-risk candidates →
 * evaluate EV and probability → bounded exhaustive combination search under
 * the configured constraints (odds range, max selections, correlation cap,
 * min confidence, min data quality, allowed markets/leagues) → best
 * qualifying combination by summed expected value, or
 * NO_QUALIFIED_TICKET. Selections are never padded or forced to reach a
 * target odds value.
 */
class TicketOptimizer
{
    public function __construct(private CorrelationEngine $correlation = new CorrelationEngine()) {}

    public function optimize(array $candidates, array $config = []): array
    {
        $min = max(5.0, (float) ($config['targetOddsMin'] ?? 5.0));
        $max = min(8.0, (float) ($config['targetOddsMax'] ?? 8.0));
        $limit = min(6, max(1, (int) ($config['maxSelections'] ?? 5)));
        // Absolute floors mirror the configuration validation range [30, 100]:
        // an explicit admin setting is honoured, never clamped back up to a
        // hard-coded 70/75. Defaults match ConfigurationService::defaults().
        $minConfidence = max(30.0, isset($config['minConfidence']) && is_numeric($config['minConfidence']) ? (float) $config['minConfidence'] : 75.0);
        $minQuality = max(50, isset($config['minDataQuality']) && is_numeric($config['minDataQuality']) ? (int) $config['minDataQuality'] : 80);
        $maxCorrelation = strtoupper((string) ($config['maxCorrelation'] ?? 'LOW'));
        $allowedMarkets = (array) ($config['allowedMarkets'] ?? []);
        $allowedLeagues = (array) ($config['allowedLeagues'] ?? []);

        $pool = [];
        foreach ($candidates as $c) {
            if (empty($c['risk']['approved']) || ($c['risk']['classification'] ?? '') === 'HIGH') continue; // risk rejected
            if (empty($c['value']['qualified'])) continue; // no positive value
            if ($minConfidence !== null && !is_numeric($c['confidence']['confidence'] ?? null)) continue; // unmeasured confidence fails a configured floor
            if ($minConfidence !== null && (float) $c['confidence']['confidence'] < $minConfidence) continue;
            if ($minQuality !== null && (int) ($c['quality']['score'] ?? 0) < $minQuality) continue;
            if (count($allowedMarkets) > 0 && !in_array($c['market'] ?? null, $allowedMarkets, true)) continue;
            if (count($allowedLeagues) > 0 && !in_array($c['match']['competition'] ?? $c['competition'] ?? null, $allowedLeagues, true)) continue;
            $pool[] = $c;
        }
        usort($pool, function (array $a, array $b): int {
            $score = function (array $c): float {
                $riskWeight = ['LOW' => 20.0, 'MEDIUM' => 10.0, 'HIGH' => -100.0, 'REJECTED' => -1000.0];
                return 0.45 * (float) ($c['confidence']['confidence'] ?? 0)
                    + 0.25 * (float) ($c['quality']['score'] ?? 0)
                    + 100.0 * (float) ($c['value']['expectedValue'] ?? 0)
                    + ($riskWeight[$c['risk']['classification'] ?? 'HIGH'] ?? 0.0);
            };
            return $score($b) <=> $score($a);
        });

        $best = null;
        $corrLimit = $maxCorrelation === 'LOW' ? 'LOW' : 'MEDIUM'; // 'MEDIUM' permits LOW+MEDIUM pairs
        $order = ['LOW' => 0, 'MEDIUM' => 1, 'HIGH' => 2];
        $search = function (array $chosen, int $start, float $odds) use (&$search, &$best, $pool, $min, $max, $limit, $corrLimit, $order) {
            if ($chosen && $odds >= $min && $odds <= $max) {
                $score = array_sum(array_map(fn($c) => (float) ($c['value']['expectedValue'] ?? 0), $chosen));
                if ($best === null || $score > $best['score'] || ($score === $best['score'] && count($chosen) < count($best['selections']))) $best = ['score' => $score, 'selections' => $chosen, 'totalOdds' => $odds];
            }
            if (count($chosen) >= $limit || $odds >= $max) return;
            for ($i = $start; $i < count($pool); $i++) {
                $candidate = $pool[$i];
                $corr = $this->correlation->assess($candidate, $chosen);
                if ($order[$corr['classification']] > $order[$corrLimit]) continue; // correlation exceeds configured threshold → reject/replace
                $next = $odds * (float) ($candidate['value']['odds'] ?? 0);
                if ($next > $max) continue;
                $search(array_merge($chosen, [$candidate]), $i + 1, $next);
            }
        };
        $search([], 0, 1.0);

        if ($best === null) {
            $confidenceFloor = number_format($minConfidence, 0);
            return ['status' => 'NO_QUALIFIED_TICKET', 'reason' => 'no verified candidate combination satisfies the 5.00–8.00 odds, ' . $confidenceFloor . '%+ confidence, quality, risk, value, freshness and correlation constraints', 'poolSize' => count($pool), 'config' => $config];
        }
        return ['status' => 'QUALIFIED', 'ticketId' => 'tkt_' . bin2hex(random_bytes(8)), 'totalOdds' => round($best['totalOdds'], 4), 'selectionCount' => count($best['selections']), 'selections' => $best['selections'], 'optimizationScore' => round($best['score'], 6), 'poolSize' => count($pool), 'config' => $config];
    }
}
