<?php
namespace AIWorkforce\Sports;

/**
 * Odds movement — the market-reaction layer of the WINDELS odds record.
 *
 * The repository already stores EVERY observed quote for a fixture
 * (sports_odds is append-only per observation), but the daily engine
 * collapsed that history to the newest row per market:selection and threw
 * the rest away. The only movement signal that survived was a provider
 * supplied `openingDecimalOdds` — a field most feeds never send — so
 * `oddsMovement` was almost always null and RiskEngine's ODDS_VOLATILE
 * upgrade could not fire on real data.
 *
 * This engine reconstructs movement from the stored observations of ONE
 * market:selection:
 *
 *   opening   the OLDEST observed price (or the provider's stated opening
 *             when it supplies one — that is the more honest opening)
 *   previous  the price before the current one
 *   current   the newest observed price
 *   direction DOWN (shortening), UP (drifting), STABLE, or UNKNOWN
 *
 * Honesty rules:
 *   • movement is only reported when at least TWO real observations exist.
 *     A single quote has no movement — the fields are null and the state is
 *     INSUFFICIENT_HISTORY. It is never reported as "stable", because an
 *     unmoved price and an unobserved price are different facts;
 *   • percentages are computed against the OPENING price (the conventional
 *     reading of "the price has shortened 14%"), never against an invented
 *     baseline;
 *   • the history is the stored observations, not a smoothed curve;
 *   • direction is decided against an explicit tolerance so float noise
 *     (1.7999999 vs 1.80) is never dressed up as a market move.
 */
final class OddsMovementEngine
{
    public const DIRECTION_DOWN = 'DOWN';       // price shortening — money coming
    public const DIRECTION_UP = 'UP';           // price drifting — market cooling
    public const DIRECTION_STABLE = 'STABLE';
    public const DIRECTION_UNKNOWN = 'UNKNOWN';

    public const STATE_MEASURED = 'MEASURED';
    public const STATE_INSUFFICIENT_HISTORY = 'INSUFFICIENT_HISTORY';

    /** Below this relative change a move is float noise, not a market move. */
    public const STABLE_TOLERANCE = 0.005; // 0.5%

    /** Observations kept on the record — enough to read the shape of a day. */
    public const MAX_HISTORY = 12;

    /**
     * Build the movement block for one market:selection.
     *
     * @param list<array{decimalOdds:float|string,observedAt:string}> $observations
     *        every stored quote for THIS market:selection, any order.
     * @param float|null $providerOpening the feed's own opening price, when supplied.
     */
    public static function assess(array $observations, $providerOpening = null): array
    {
        $points = [];
        foreach ($observations as $observation) {
            if (!is_array($observation)) continue;
            $odds = $observation['decimalOdds'] ?? $observation['decimal_odds'] ?? null;
            $at = $observation['observedAt'] ?? $observation['observed_at'] ?? null;
            if (!is_numeric($odds) || (float) $odds <= 1.0) continue;
            if (!is_string($at) || trim($at) === '') continue;
            try { $ts = (new \DateTimeImmutable($at))->getTimestamp(); }
            catch (\Throwable $e) { continue; }
            $points[] = ['odds' => (float) $odds, 'at' => $at, 'ts' => $ts];
        }

        if ($points === []) return self::empty(null);

        // Chronological, with a stable tie-break so two quotes sharing a
        // timestamp cannot reorder between runs.
        usort($points, fn(array $a, array $b) => [$a['ts'], $a['odds']] <=> [$b['ts'], $b['odds']]);

        $current = $points[count($points) - 1];
        $opening = $points[0];

        // The provider's stated opening price beats our oldest OBSERVATION:
        // the feed saw the market open, we only saw it when we first synced.
        $openingOdds = $opening['odds'];
        $openingFromProvider = false;
        if (is_numeric($providerOpening) && (float) $providerOpening > 1.0) {
            $openingOdds = (float) $providerOpening;
            $openingFromProvider = true;
        }

        $history = array_map(
            fn(array $p) => ['odds' => $p['odds'], 'observedAt' => $p['at']],
            array_slice($points, -self::MAX_HISTORY)
        );

        // A single observation and no provider opening: no movement exists.
        // Never call that STABLE.
        if (count($points) < 2 && !$openingFromProvider) {
            $out = self::empty($current['odds']);
            $out['oddsHistory'] = $history;
            $out['observations'] = count($points);
            return $out;
        }

        $previous = count($points) >= 2 ? $points[count($points) - 2]['odds'] : $openingOdds;
        $change = $current['odds'] - $openingOdds;
        $percentage = $openingOdds > 0 ? ($change / $openingOdds) * 100 : null;

        $direction = self::DIRECTION_STABLE;
        if ($percentage !== null && abs($percentage) >= self::STABLE_TOLERANCE * 100) {
            $direction = $change < 0 ? self::DIRECTION_DOWN : self::DIRECTION_UP;
        }

        return [
            'state' => self::STATE_MEASURED,
            'openingOdds' => round($openingOdds, 4),
            'openingSource' => $openingFromProvider ? 'PROVIDER' : 'OBSERVED',
            'previousOdds' => round($previous, 4),
            'currentOdds' => round($current['odds'], 4),
            'movement' => $direction,
            'movementAbsolute' => round($change, 4),
            'movementPercentage' => $percentage === null ? null : round($percentage, 2),
            'observations' => count($points),
            'firstObservedAt' => $opening['at'],
            'lastObservedAt' => $current['at'],
            'oddsHistory' => $history,
        ];
    }

    /**
     * The signed drift used by RiskEngine's ODDS_VOLATILE gate: the change
     * from opening to current, or null when it was never measured. Null and
     * 0.0 are different answers — "not measured" must never read as "did not
     * move", so the caller can tell the two apart.
     */
    public static function riskSignal(array $movement)
    {
        if (($movement['state'] ?? '') !== self::STATE_MEASURED) return null;
        $absolute = $movement['movementAbsolute'] ?? null;
        return is_numeric($absolute) ? (float) $absolute : null;
    }

    private static function empty($current): array
    {
        return [
            'state' => self::STATE_INSUFFICIENT_HISTORY,
            'openingOdds' => null,
            'openingSource' => null,
            'previousOdds' => null,
            'currentOdds' => $current === null ? null : round((float) $current, 4),
            'movement' => self::DIRECTION_UNKNOWN,
            'movementAbsolute' => null,
            'movementPercentage' => null,
            'observations' => 0,
            'firstObservedAt' => null,
            'lastObservedAt' => null,
            'oddsHistory' => [],
        ];
    }
}
