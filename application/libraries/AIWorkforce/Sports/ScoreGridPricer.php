<?php
namespace AIWorkforce\Sports;

use AIWorkforce\Football\FootballConfiguration;
use AIWorkforce\Football\ScoreProbabilityModel;

/**
 * Score-grid pricing for the ticket engine's wider market set.
 *
 * The baseline model prices 1X2/totals/BTTS with closed-form logistic
 * readings. Handicap and correct-score cannot be read that way: they are
 * sums over the JOINT distribution of (home goals, away goals). Rather than
 * invent a second model, this pricer reuses the Football module's
 * `ScoreProbabilityModel` — independent Poisson per side with the
 * Dixon–Coles low-score correction, renormalised — so a scoreline means the
 * same thing on the football board and on a ticket leg.
 *
 * The goal expectancies come from the SAME feature pair the baseline model
 * already uses for its strength terms, so the two layers cannot disagree
 * about which team is stronger:
 *
 *   lambdaHome = (homeAttack + awayDefenseConceded) / 2
 *   lambdaAway = (awayAttack + homeDefenseConceded) / 2
 *
 * Honesty rules:
 *   • only lines this engine can also SETTLE from a verified full-time score
 *     are priceable. Quarter lines (-0.25, -0.75) split the stake into a
 *     half-win/half-loss that the settlement layer has no status for, so
 *     they are refused rather than silently rounded to a neighbouring line;
 *   • a grid that could not be built returns null — never a default price;
 *   • correct-score probabilities are read out of the same normalised grid
 *     as the handicap sums, so the two can never contradict each other.
 */
final class ScoreGridPricer
{
    /** Correct-score lines beyond this are aggregated by the book, not priced here. */
    public const MAX_CORRECT_SCORE_GOALS = 5;

    private ScoreProbabilityModel $model;

    public function __construct(?ScoreProbabilityModel $model = null, ?FootballConfiguration $config = null)
    {
        $this->model = $model ?? new ScoreProbabilityModel($config ?? new FootballConfiguration());
    }

    /**
     * Goal expectancies from the baseline model's own feature set.
     *
     * @return array{0:float,1:float}|null null when a required rate is absent
     */
    public static function lambdas(array $features): ?array
    {
        foreach (['homeAttack', 'awayAttack', 'homeDefenseConceded', 'awayDefenseConceded'] as $key) {
            if (!isset($features[$key]) || !is_numeric($features[$key])) return null;
        }
        $home = ((float) $features['homeAttack'] + (float) $features['awayDefenseConceded']) / 2;
        $away = ((float) $features['awayAttack'] + (float) $features['homeDefenseConceded']) / 2;
        if ($home < 0 || $away < 0) return null;
        // A degenerate all-zero fixture has no distribution to speak of.
        if ($home <= 0.0 && $away <= 0.0) return null;
        return [$home, $away];
    }

    /**
     * Parse an Asian/European handicap selection.
     *
     * Accepted: HOME_MINUS_1, HOME_MINUS_1_5, AWAY_PLUS_0_5, HOME_PLUS_2 …
     * The line is expressed from the SELECTED side's point of view, which is
     * how a book quotes it ("Home -1.5").
     *
     * @return array{side:string,line:float}|null
     */
    public static function handicapSelection(string $selection): ?array
    {
        $selection = strtoupper(trim($selection));
        if (!preg_match('/^(HOME|AWAY)_(MINUS|PLUS)_(\d+)(?:_(\d+))?$/', $selection, $m)) return null;
        $line = (float) ($m[4] !== '' && isset($m[4]) ? $m[3] . '.' . $m[4] : $m[3]);
        if ($m[2] === 'MINUS') $line = -$line;
        return ['side' => $m[1], 'line' => $line];
    }

    /**
     * A handicap line is supported only when it settles cleanly to
     * WON / LOST / VOID. Half lines (x.5) can never push; whole lines push
     * on the exact margin. Quarter lines would need a half-win status the
     * settlement layer does not have.
     */
    public static function isSettleableHandicapLine(float $line): bool
    {
        $doubled = $line * 2;
        return abs($doubled - round($doubled)) < 1e-9;
    }

    /**
     * Parse a correct-score selection: SCORE_2_1 → 2–1.
     *
     * @return array{0:int,1:int}|null
     */
    public static function correctScoreSelection(string $selection): ?array
    {
        if (!preg_match('/^SCORE_(\d+)_(\d+)$/', strtoupper(trim($selection)), $m)) return null;
        return [(int) $m[1], (int) $m[2]];
    }

    /**
     * The joint distribution rows for a feature set, or [] when unavailable.
     *
     * @return list<array{homeGoals:int,awayGoals:int,probability:float}>
     */
    public function grid(array $features): array
    {
        $lambdas = self::lambdas($features);
        if ($lambdas === null) return [];
        $grid = $this->model->fullGrid($lambdas[0], $lambdas[1]);
        return $grid['rows'] ?? [];
    }

    /**
     * Probability of a market:selection from the score grid.
     *
     * @return float|null null when the market/selection is not grid-priceable
     *         or the grid could not be built — never a fallback number.
     */
    public function probability(string $market, string $selection, array $features): ?float
    {
        $market = strtoupper(trim($market));
        $selection = strtoupper(trim($selection));
        $rows = $this->grid($features);
        if ($rows === []) return null;

        if ($market === 'ASIAN_HANDICAP') {
            $parsed = self::handicapSelection($selection);
            if ($parsed === null || !self::isSettleableHandicapLine($parsed['line'])) return null;
            $win = 0.0;
            $push = 0.0;
            foreach ($rows as $row) {
                // Margin from the selected side's perspective, plus its line.
                $margin = $parsed['side'] === 'HOME'
                    ? $row['homeGoals'] - $row['awayGoals']
                    : $row['awayGoals'] - $row['homeGoals'];
                $adjusted = $margin + $parsed['line'];
                if (abs($adjusted) < 1e-9) $push += (float) $row['probability'];
                elseif ($adjusted > 0) $win += (float) $row['probability'];
            }
            // A pushed stake is returned, so the honest probability of
            // WINNING the bet is conditional on it not pushing.
            $decisive = 1.0 - $push;
            if ($decisive <= 1e-9) return null;
            return self::clamp($win / $decisive);
        }

        if ($market === 'CORRECT_SCORE') {
            $score = self::correctScoreSelection($selection);
            if ($score === null) return null;
            if ($score[0] > self::MAX_CORRECT_SCORE_GOALS || $score[1] > self::MAX_CORRECT_SCORE_GOALS) return null;
            foreach ($rows as $row) {
                if ((int) $row['homeGoals'] === $score[0] && (int) $row['awayGoals'] === $score[1]) {
                    return self::clamp((float) $row['probability']);
                }
            }
            // Inside the catalogue but outside the configured grid: state the
            // absence instead of returning zero as if it were measured.
            return null;
        }

        return null;
    }

    private static function clamp(float $p): float
    {
        return min(0.99, max(0.01, $p));
    }
}
