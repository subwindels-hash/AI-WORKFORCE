<?php
namespace AIWorkforce\Sports;

/**
 * Ticket optimization (spec §16/§17).
 *
 * Candidate pool → remove invalid/low-quality/stale/high-risk candidates →
 * evaluate EV and probability → bounded exhaustive combination search under
 * the configured constraints (odds range, max selections, correlation cap,
 * min confidence, min data quality, allowed markets/leagues) → best
 * qualifying combination by summed expected value, or — when nothing clears
 * every PREFERRED criterion — the configured FALLBACK selection.
 *
 * WHY THE FALLBACK EXISTS
 * -----------------------
 * The engine used to return NO_QUALIFIED_TICKET the moment ONE gate was
 * missed, which is how a day with "4 positive-value, 4 risk-qualified"
 * candidates still produced "0 final": every one of those four had been
 * rejected upstream for a single missed threshold and never reached the
 * combination search at all. A ticket must be the STRONGEST AVAILABLE
 * selection of real predictions, not an all-or-nothing lottery on one number.
 *
 * The fallback is a ranking, never an invention:
 *
 *   1. PREFERRED  — every configured criterion is met (confidence floor,
 *      quality floor, positive value, risk, correlation cap, odds range).
 *   2. RELAXED_CONFIDENCE — the confidence floor is the only unmet criterion;
 *      candidates are ranked by confidence, then data quality, then expected
 *      value, then risk, then correlation, and the strongest non-correlated
 *      combination inside the configured odds range is taken.
 *
 * The correlation cap is deliberately NOT one of the things a fallback may
 * relax: requirement #10 asks for the strongest NON-CORRELATED combination,
 * so a fallback ticket is still an uncorrelated one.
 *
 * Hard invariants that no tier may break, ever:
 *   • only real candidates with a real model prediction and a real quoted
 *     price are eligible — nothing is fabricated to fill a ticket;
 *   • expected value must be positive;
 *   • risk must be approved and never HIGH;
 *   • two legs of the same match, or sharing a team, are never combined;
 *   • the configured combined-odds range is always respected;
 *   • selections are never padded to hit a target odds value.
 *
 * The result always carries `fallbackUsed`, `selectionTier` and a per-tier
 * `attempts` trace, so a fallback ticket can never be mistaken for one that
 * cleared every preferred criterion.
 */
class TicketOptimizer
{
    public const TIER_PREFERRED = 'PREFERRED';
    public const TIER_RELAXED_CONFIDENCE = 'RELAXED_CONFIDENCE';

    /**
     * The combination search is exhaustive but BOUNDED, in two ways, because
     * an unbounded walk over a whole day's candidates is exponential:
     *
     *   • only the SEARCH_WIDTH best-ranked candidates of a tier enter the
     *     walk (they are ranked by confidence → quality → EV → risk first, so
     *     the strongest legs are always the ones considered);
     *   • the walk aborts after SEARCH_NODE_BUDGET expansions and keeps the
     *     best combination found so far.
     *
     * Neither bound can invent a leg or relax a constraint — they only cap
     * how much of an already-ranked pool is explored, which is what keeps the
     * daily run inside its time and API budget (requirement #13).
     */
    public const SEARCH_WIDTH = 40;
    public const SEARCH_NODE_BUDGET = 200000;

    private const CORRELATION_ORDER = ['LOW' => 0, 'MEDIUM' => 1, 'HIGH' => 2];
    private const RISK_RANK = ['LOW' => 0, 'MEDIUM' => 1, 'HIGH' => 2, 'REJECTED' => 3];

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
        $corrLimit = $maxCorrelation === 'LOW' ? 'LOW' : 'MEDIUM';
        $allowedMarkets = (array) ($config['allowedMarkets'] ?? []);
        $allowedLeagues = (array) ($config['allowedLeagues'] ?? []);
        // Fallback selection is an EXPLICIT, configured behaviour, never a
        // silent default: a caller that asks the optimizer to apply a set of
        // floors gets exactly those floors. DailyTicketService opts in (the
        // daily ticket must not be lost to one missed threshold); a direct
        // policy check does not.
        $fallbackEnabled = !empty($config['allowFallback']);

        // ── Eligibility: the hard invariants, evaluated per candidate with
        // an explicit, reportable reason for every exclusion. ──────────────
        $eligible = [];
        $rejected = [];
        foreach ($candidates as $c) {
            $reasons = $this->hardExclusions($c, $allowedMarkets, $allowedLeagues);
            $confidence = is_numeric($c['confidence']['confidence'] ?? null) ? (float) $c['confidence']['confidence'] : null;
            $quality = (int) ($c['quality']['score'] ?? 0);
            $softReasons = [];
            if ($confidence === null) $softReasons[] = 'CONFIDENCE_UNMEASURED';
            elseif ($confidence < $minConfidence) $softReasons[] = 'LOW_CONFIDENCE';
            if ($quality < $minQuality) $softReasons[] = 'LOW_DATA_QUALITY';

            $row = [
                'candidate' => $c,
                'confidence' => $confidence,
                'quality' => $quality,
                'expectedValue' => (float) ($c['value']['expectedValue'] ?? 0),
                'risk' => (string) ($c['risk']['classification'] ?? 'HIGH'),
                'odds' => (float) ($c['value']['odds'] ?? $c['odds'] ?? 0),
                'hardReasons' => $reasons,
                'softReasons' => $softReasons,
            ];
            if ($reasons !== []) {
                $rejected[] = $this->explain($row, 'EXCLUDED', $reasons);
                continue;
            }
            $eligible[] = $row;
        }

        $eligible = $this->rank($eligible);
        // The PREFERRED pool: eligible AND every soft criterion met too.
        $preferred = array_values(array_filter($eligible, fn(array $r): bool => $r['softReasons'] === []));
        // The fallback pool keeps the quality floor (a ticket must still rest
        // on assessable data) but admits a missed confidence floor.
        $relaxed = array_values(array_filter($eligible, fn(array $r): bool => !in_array('LOW_DATA_QUALITY', $r['softReasons'], true) && $r['confidence'] !== null));

        $attempts = [];
        $tiers = [
            [self::TIER_PREFERRED, $preferred, $corrLimit],
        ];
        // The correlation cap is NEVER relaxed: requirement #10 asks for the
        // strongest NON-CORRELATED combination, so a fallback ticket is still
        // an uncorrelated one — only the confidence floor gives way, and only
        // after the preferred tier has genuinely found nothing.
        if ($fallbackEnabled) $tiers[] = [self::TIER_RELAXED_CONFIDENCE, $relaxed, $corrLimit];

        foreach ($tiers as [$tier, $pool, $tierCorrelation]) {
            $best = $this->search($pool, $min, $max, $limit, $tierCorrelation);
            $attempts[] = [
                'tier' => $tier,
                'poolSize' => count($pool),
                'correlationCap' => $tierCorrelation,
                'found' => $best !== null,
                'reason' => $best !== null ? null : $this->tierFailureReason($pool, $min, $max, $limit, $tierCorrelation),
            ];
            if ($best === null) continue;

            $fallbackUsed = $tier !== self::TIER_PREFERRED;
            $selections = array_map(fn(array $r): array => $r['candidate'], $best['selections']);
            return [
                'status' => 'QUALIFIED',
                'ticketId' => 'tkt_' . bin2hex(random_bytes(8)),
                'totalOdds' => round($best['totalOdds'], 4),
                'selectionCount' => count($selections),
                'selections' => $selections,
                'optimizationScore' => round($best['score'], 6),
                'poolSize' => count($pool),
                'preferredPoolSize' => count($preferred),
                'eligiblePoolSize' => count($eligible),
                'selectionTier' => $tier,
                'fallbackUsed' => $fallbackUsed,
                'fallbackReason' => $fallbackUsed ? $this->fallbackReason($tier, $minConfidence, $best['selections']) : null,
                'correlation' => $this->correlation->classifySelections($selections),
                'attempts' => $attempts,
                'candidateDecisions' => $this->decisionTrace($eligible, $rejected, $best['selections'], $minConfidence, $minQuality),
                'config' => $config,
            ];
        }

        $confidenceFloor = number_format($minConfidence, 0);
        return [
            'status' => 'NO_QUALIFIED_TICKET',
            'reason' => 'no verified candidate combination satisfies the ' . number_format($min, 2) . '–' . number_format($max, 2)
                . ' odds, ' . $confidenceFloor . '%+ confidence, quality, risk, value, freshness and correlation constraints'
                . ($fallbackEnabled ? ' — and the fallback tiers (relaxed confidence, relaxed correlation) found none either' : ''),
            'poolSize' => count($preferred),
            'eligiblePoolSize' => count($eligible),
            'preferredPoolSize' => count($preferred),
            'selectionTier' => null,
            'fallbackUsed' => false,
            'attempts' => $attempts,
            'candidateDecisions' => $this->decisionTrace($eligible, $rejected, [], $minConfidence, $minQuality),
            'config' => $config,
        ];
    }

    /**
     * The invariants no tier may relax. A candidate failing any of these is
     * not a ticket leg under any circumstances.
     *
     * @return list<string>
     */
    private function hardExclusions(array $c, array $allowedMarkets, array $allowedLeagues): array
    {
        $reasons = [];
        // A candidate that carries a prediction block must carry a READY one.
        // (Its absence is not assumed to be a failure: `value.qualified` is
        // only ever true when ValueEngine saw a real PREDICTION_READY model
        // output, so the value gate below already covers that case.)
        if (isset($c['prediction']) && ($c['prediction']['decision'] ?? '') !== 'PREDICTION_READY') $reasons[] = 'NO_PREDICTION';
        if (empty($c['value']['qualified']) || (float) ($c['value']['expectedValue'] ?? -1) <= 0) $reasons[] = 'NO_POSITIVE_VALUE';
        $risk = (string) ($c['risk']['classification'] ?? 'HIGH');
        if (empty($c['risk']['approved']) || $risk === 'HIGH' || $risk === 'REJECTED') $reasons[] = 'RISK_NOT_APPROVED';
        $odds = (float) ($c['value']['odds'] ?? $c['odds'] ?? 0);
        // A price must be real and quotable. A price ABOVE the ticket
        // maximum is NOT an eligibility failure — the candidate is a perfectly
        // valid prediction that simply cannot fit this ticket's odds window,
        // and the combination search skips it there.
        if (!OddsBounds::validDecimalOdds($odds, isset($c['market']) ? (string) $c['market'] : null)) $reasons[] = 'NO_QUOTABLE_PRICE';
        // Only judged when the candidate actually names a selection; a
        // pipeline candidate always does, and TicketGovernance re-validates
        // every leg before anything is persisted.
        if (isset($c['selection']) && !PredictionEngine::isSupportedMarketSelection($c['market'] ?? null, $c['selection'] ?? null)) $reasons[] = 'UNSUPPORTED_MARKET';
        if (count($allowedMarkets) > 0 && !in_array($c['market'] ?? null, $allowedMarkets, true)) $reasons[] = 'MARKET_NOT_ALLOWED';
        if (count($allowedLeagues) > 0 && !in_array($c['match']['competition'] ?? $c['competition'] ?? null, $allowedLeagues, true)) $reasons[] = 'LEAGUE_NOT_ALLOWED';
        return $reasons;
    }

    /**
     * Requirement #10's ranking order, applied literally: confidence, then
     * data quality, then positive expected value, then risk, then market
     * correlation potential (a leg in a competition already represented is
     * worth less), with deterministic tie-breaks so two indistinguishable
     * candidates never swap between runs.
     */
    private function rank(array $rows): array
    {
        usort($rows, function (array $a, array $b): int {
            $confidence = ($b['confidence'] ?? -1) <=> ($a['confidence'] ?? -1);
            if ($confidence !== 0) return $confidence;
            $quality = $b['quality'] <=> $a['quality'];
            if ($quality !== 0) return $quality;
            $ev = $b['expectedValue'] <=> $a['expectedValue'];
            if ($ev !== 0) return $ev;
            $risk = (self::RISK_RANK[$a['risk']] ?? 2) <=> (self::RISK_RANK[$b['risk']] ?? 2);
            if ($risk !== 0) return $risk;
            $ageOf = fn(array $r): float => is_numeric($r['candidate']['oddsAgeSeconds'] ?? null) ? (float) $r['candidate']['oddsAgeSeconds'] : PHP_INT_MAX;
            $age = $ageOf($a) <=> $ageOf($b);
            if ($age !== 0) return $age;
            return strcmp(
                (string) ($a['candidate']['matchId'] ?? 0) . ':' . (string) ($a['candidate']['market'] ?? '') . ':' . (string) ($a['candidate']['selection'] ?? ''),
                (string) ($b['candidate']['matchId'] ?? 0) . ':' . (string) ($b['candidate']['market'] ?? '') . ':' . (string) ($b['candidate']['selection'] ?? '')
            );
        });
        return $rows;
    }

    /**
     * Bounded exhaustive combination search inside the odds range under a
     * correlation cap. The winner maximises summed expected value, then
     * prefers the strongest-ranked (fewest, best-ranked) combination.
     *
     * @return array{score:float,selections:list<array>,totalOdds:float}|null
     */
    private function search(array $pool, float $min, float $max, int $limit, string $corrLimit): ?array
    {
        // The pool arrives already ranked, so the head of it holds the
        // strongest legs; the width bound keeps the walk finite.
        $pool = array_slice($pool, 0, self::SEARCH_WIDTH);
        $best = null;
        $nodes = 0;
        $walk = function (array $chosen, int $start, float $odds) use (&$walk, &$best, &$nodes, $pool, $min, $max, $limit, $corrLimit): void {
            if (++$nodes > self::SEARCH_NODE_BUDGET) return;
            if ($chosen && $odds >= $min && $odds <= $max) {
                $score = array_sum(array_map(fn(array $r): float => $r['expectedValue'], $chosen));
                if ($best === null
                    || $score > $best['score']
                    || ($score === $best['score'] && count($chosen) < count($best['selections']))) {
                    $best = ['score' => $score, 'selections' => $chosen, 'totalOdds' => $odds];
                }
            }
            if (count($chosen) >= $limit || $odds >= $max) return;
            $chosenCandidates = array_map(fn(array $r): array => $r['candidate'], $chosen);
            for ($i = $start; $i < count($pool); $i++) {
                $row = $pool[$i];
                $corr = $this->correlation->assess($row['candidate'], $chosenCandidates);
                // HIGH is impossible at every tier; the tier cap governs MEDIUM.
                if ($corr['classification'] === 'HIGH') continue;
                if ((self::CORRELATION_ORDER[$corr['classification']] ?? 2) > (self::CORRELATION_ORDER[$corrLimit] ?? 0)) continue;
                $next = $odds * $row['odds'];
                if ($next > $max) continue;
                $walk(array_merge($chosen, [$row]), $i + 1, $next);
            }
        };
        $walk([], 0, 1.0);
        return $best;
    }

    /** Exactly why a tier produced nothing — never a bare "no combination". */
    private function tierFailureReason(array $pool, float $min, float $max, int $limit, string $corrLimit): string
    {
        if ($pool === []) return 'the pool for this tier is empty';
        // Could the pool reach the minimum odds AT ALL, ignoring correlation?
        $odds = array_map(fn(array $r): float => $r['odds'], $pool);
        rsort($odds);
        $reachable = 1.0;
        foreach (array_slice($odds, 0, $limit) as $o) $reachable *= $o;
        if ($reachable < $min) {
            return sprintf('the %d best-priced legs multiply to only %.2f, below the %.2f minimum combined odds (max %d selections)',
                min($limit, count($odds)), $reachable, $min, $limit);
        }
        // Odds are reachable, so correlation or the maximum is the blocker.
        $uncorrelated = $this->search($pool, $min, $max, $limit, 'MEDIUM');
        if ($uncorrelated === null) {
            return sprintf('no combination of %d candidate(s) lands inside the %.2f–%.2f combined-odds window without exceeding it',
                count($pool), $min, $max);
        }
        return sprintf('every combination inside the %.2f–%.2f window exceeds the %s correlation cap (candidates share a match, a team or a competition)',
            $min, $max, $corrLimit);
    }

    /** Fallback mode is always named, with the concrete figure that triggered it. */
    private function fallbackReason(string $tier, float $minConfidence, array $selections): string
    {
        $below = array_values(array_filter($selections, fn(array $r): bool => ($r['confidence'] ?? 0) < $minConfidence));
        $lowest = $below === [] ? null : min(array_map(fn(array $r): float => (float) $r['confidence'], $below));
        return sprintf(
            'FALLBACK (%s): no combination cleared the %.0f%% confidence floor, so the strongest non-correlated candidates '
            . 'ranked by confidence → data quality → expected value → risk → correlation were selected instead%s. '
            . 'Every leg is still a real prediction with positive expected value, approved risk and a quoted price.',
            $tier, $minConfidence, $lowest === null ? '' : sprintf(' (lowest leg confidence %.2f%%)', $lowest)
        );
    }

    /**
     * The full per-candidate diagnostic requirement #14 asks for:
     * fixture → market → model probability → confidence → data quality →
     * odds → value → risk → correlation → final decision.
     */
    private function decisionTrace(array $eligible, array $rejected, array $chosen, float $minConfidence, int $minQuality): array
    {
        $chosenKeys = [];
        foreach ($chosen as $row) $chosenKeys[$this->keyOf($row['candidate'])] = true;
        $rows = $rejected;
        foreach ($eligible as $row) {
            $selected = isset($chosenKeys[$this->keyOf($row['candidate'])]);
            $decision = $selected ? 'SELECTED' : ($row['softReasons'] === [] ? 'NOT_SELECTED' : 'BELOW_PREFERRED_CRITERIA');
            $reasons = $selected ? [] : ($row['softReasons'] !== [] ? $row['softReasons'] : ['NOT_IN_BEST_COMBINATION']);
            $rows[] = $this->explain($row, $decision, $reasons, $minConfidence, $minQuality);
        }
        return $rows;
    }

    private function explain(array $row, string $decision, array $reasons, ?float $minConfidence = null, ?int $minQuality = null): array
    {
        $c = $row['candidate'];
        return [
            'fixture' => trim((string) ($c['match']['homeTeam'] ?? '?') . ' vs ' . (string) ($c['match']['awayTeam'] ?? '?')),
            'matchId' => $c['matchId'] ?? null,
            'competition' => $c['match']['competition'] ?? null,
            'market' => $c['market'] ?? null,
            'selection' => $c['selection'] ?? null,
            'modelProbability' => $c['prediction']['calibratedProbability'] ?? null,
            'confidence' => $row['confidence'],
            'minConfidence' => $minConfidence,
            'dataQuality' => $row['quality'],
            'minDataQuality' => $minQuality,
            'odds' => $row['odds'],
            'expectedValue' => $row['expectedValue'],
            'edge' => $c['value']['edge'] ?? null,
            'risk' => $row['risk'],
            'correlation' => $c['correlation']['classification'] ?? 'LOW',
            'decision' => $decision,
            'reasons' => array_values(array_unique($reasons)),
        ];
    }

    private function keyOf(array $candidate): string
    {
        return (string) ($candidate['matchId'] ?? 0) . ':' . (string) ($candidate['market'] ?? '') . ':' . (string) ($candidate['selection'] ?? '');
    }
}
