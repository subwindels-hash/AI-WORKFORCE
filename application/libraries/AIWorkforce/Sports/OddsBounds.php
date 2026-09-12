<?php
namespace AIWorkforce\Sports;

/**
 * Plausibility bounds for decimal odds — the single source of truth every
 * odds reader and writer validates against.
 *
 * A decimal price is only ever a REAL bookmaker quote when it is numeric,
 * finite and strictly greater than 1.0 (a price of 1.00 or less returns less
 * than the stake and cannot exist). On top of that floor, each market carries
 * a generous plausibility ceiling: real books quote extreme mismatches at
 * 200/1 and exotic scorelines far beyond that, so the ceilings sit well above
 * anything a legitimate feed sends — their only job is to catch corrupted,
 * mis-scaled or invented prices (9999, 1e12, a percentage passed as decimal
 * odds) before they can reach an overround, an expected-value computation or
 * a ticket leg. A ceiling is a data-integrity guard, never a pricing opinion.
 */
final class OddsBounds
{
    /**
     * Per-market plausibility ceilings, keyed by canonical market code.
     * Both the ticket engine's market codes (MATCH_RESULT, …) and the
     * Football board's catalogue keys (MATCH_WINNER, OVER_2_5, …) are covered;
     * anything unlisted falls back to DEFAULT_MAX_DECIMAL_ODDS.
     *
     * @var array<string,float>
     */
    private const MAX_DECIMAL_ODDS = [
        // Ticket-engine markets.
        'MATCH_RESULT' => 500.0,
        'TOTAL_GOALS' => 100.0,
        'BTTS' => 100.0,
        'DOUBLE_CHANCE' => 30.0,
        // Football-board catalogue keys.
        'MATCH_WINNER' => 500.0,
        'FIRST_HALF_WINNER' => 300.0,
        'DRAW_NO_BET' => 100.0,
        'BTTS_AND_OVER_2_5' => 250.0,
        'BTTS_AND_UNDER_2_5' => 250.0,
        'CORRECT_SCORE' => 1000.0,
        'ASIAN_HANDICAP' => 100.0,
        'FIRST_HALF_OVER_UNDER' => 100.0,
        'SECOND_HALF_OVER_UNDER' => 100.0,
        'FIRST_HALF_DOUBLE_CHANCE' => 50.0,
        'FIRST_HALF_BTTS' => 100.0,
        'SECOND_HALF_WINNER' => 300.0,
        'TOTAL_GOALS_ODD_EVEN' => 20.0,
        'TOTAL_GOALS_BAND' => 250.0,
        'HOME_TEAM_TOTAL_GOALS' => 100.0,
        'AWAY_TEAM_TOTAL_GOALS' => 100.0,
        'HOME_CLEAN_SHEET' => 50.0,
        'AWAY_CLEAN_SHEET' => 50.0,
        'WINNING_MARGIN' => 500.0,
        'RESULT_AND_BTTS' => 500.0,
        'HALF_TIME_FULL_TIME' => 500.0,
        'CORNERS' => 250.0,
        'CARDS' => 250.0,
    ];

    /** Goal-line totals (OVER_0_5 … UNDER_3_5) share one ceiling. */
    private const TOTALS_MAX_DECIMAL_ODDS = 100.0;

    /** Ceiling for markets this map does not name. Corruption, not pricing. */
    private const DEFAULT_MAX_DECIMAL_ODDS = 1000.0;

    /**
     * The plausibility ceiling for a market: a real quote never exceeds it.
     */
    public static function maxFor(?string $market): float
    {
        $code = strtoupper(trim((string) $market));
        if (isset(self::MAX_DECIMAL_ODDS[$code])) return self::MAX_DECIMAL_ODDS[$code];
        if (str_starts_with($code, 'OVER_') || str_starts_with($code, 'UNDER_')) return self::TOTALS_MAX_DECIMAL_ODDS;
        return self::DEFAULT_MAX_DECIMAL_ODDS;
    }

    /**
     * Whether a value is quotable as decimal odds for a market: numeric,
     * finite, strictly above 1.0 and inside the plausibility ceiling. Zero,
     * negative, null, NaN, infinite and absurd prices are all rejected.
     */
    public static function validDecimalOdds(mixed $decimal, ?string $market = null): bool
    {
        if (!is_numeric($decimal)) return false;
        $price = (float) $decimal;
        if (!is_finite($price) || $price <= 1.0) return false;
        return $price <= self::maxFor($market);
    }
}
