<?php
namespace AIWorkforce\Sports;

/**
 * Versioned deterministic baseline; not an AI claim and never predicts without
 * calibration approval. The model only emits probabilities for explicitly
 * supported football ticket markets and only from verified numeric recent-form
 * features produced by FeatureEngineeringEngine.
 *
 * The WINDELS prediction is fully separated from bookmaker odds:
 *   • rawModelProbability / calibratedProbability — the model's own probability;
 *   • fairOdds — 1 / calibrated probability, the model's own fair price;
 *   • market odds, implied probability and value/edge live in ValueEngine and
 *     are never copied back into the prediction.
 *
 * Required features are defined per market (see REQUIRED_FEATURES): a Match
 * Winner prediction needs the four team-form rates the model actually uses —
 * never "every possible statistic". A market whose required features are
 * missing is rejected with the exact list of missing fields.
 */
class PredictionEngine
{
    public const MODEL_NAME = 'WINDELS Sports Baseline';
    // Unchanged on purpose: the model math is identical; only its output
    // shape (fairOdds, missingFields) grew. Bumping this would orphan every
    // approved calibration, which is keyed by (model, version).
    public const MODEL_VERSION = '1.1.0';

    /**
     * Every market:selection this model can BOTH price from its features and
     * settle from a verified score (see ResultVerificationEngine). A market is
     * listed here only when both are true — pricing something that could never
     * be settled would leave permanently PENDING legs, and settling something
     * the model cannot price would mean inventing a probability.
     *
     * @var array<string,list<string>>
     */
    public const SUPPORTED_MARKETS = [
        'MATCH_RESULT' => ['HOME', 'DRAW', 'AWAY'],
        // Every totals line the goal-expectancy model can price. UNDER_1_5
        // deliberately stays out: it is an overround companion price, never
        // a ticket candidate.
        'TOTAL_GOALS' => ['OVER_0_5', 'OVER_1_5', 'OVER_2_5', 'OVER_3_5', 'UNDER_2_5', 'UNDER_3_5', 'UNDER_4_5'],
        'BTTS' => ['YES', 'NO'],
        'DOUBLE_CHANCE' => ['HOME_OR_DRAW', 'AWAY_OR_DRAW', 'HOME_OR_AWAY'],
        // Draw No Bet: the draw refunds the stake, so it settles as VOID.
        'DRAW_NO_BET' => ['HOME', 'AWAY'],
        // Handicap and correct score are sums over the SCORE GRID
        // (ScoreGridPricer), not closed-form readings. Only lines that
        // settle cleanly to WON/LOST/VOID from a full-time score are
        // listed: quarter lines (-0.25/-0.75) split the stake into a
        // half-win the settlement layer has no status for, so they are
        // deliberately absent rather than rounded to a neighbour.
        'ASIAN_HANDICAP' => [
            'HOME_MINUS_0_5', 'HOME_MINUS_1', 'HOME_MINUS_1_5', 'HOME_MINUS_2',
            'HOME_PLUS_0_5', 'HOME_PLUS_1', 'HOME_PLUS_1_5',
            'AWAY_MINUS_0_5', 'AWAY_MINUS_1', 'AWAY_MINUS_1_5',
            'AWAY_PLUS_0_5', 'AWAY_PLUS_1', 'AWAY_PLUS_1_5', 'AWAY_PLUS_2',
        ],
        'CORRECT_SCORE' => [
            'SCORE_0_0', 'SCORE_1_0', 'SCORE_0_1', 'SCORE_1_1',
            'SCORE_2_0', 'SCORE_0_2', 'SCORE_2_1', 'SCORE_1_2',
            'SCORE_2_2', 'SCORE_3_0', 'SCORE_0_3', 'SCORE_3_1',
            'SCORE_1_3', 'SCORE_3_2', 'SCORE_2_3', 'SCORE_3_3',
        ],
    ];

    /**
     * Selections that exist only to complete a market's overround (so the
     * de-vigged fair price can be computed) and must never become a ticket
     * candidate on their own.
     *
     * @var array<string,list<string>>
     */
    public const COMPANION_SELECTIONS = [
        'TOTAL_GOALS' => ['UNDER_0_5', 'UNDER_1_5', 'OVER_4_5'],
    ];

    /**
     * Mandatory model features per market (the model's actual inputs).
     * TOTAL_GOALS only needs the goal-expectancy proxy; the head-to-head
     * markets additionally need both teams' attack/defence rates.
     * @var array<string,list<string>>
     */
    public const REQUIRED_FEATURES = [
        'TOTAL_GOALS' => ['expectedGoalsProxy'],
        'MATCH_RESULT' => ['expectedGoalsProxy', 'homeAttack', 'awayAttack', 'homeDefenseConceded', 'awayDefenseConceded'],
        'BTTS' => ['expectedGoalsProxy', 'homeAttack', 'awayAttack', 'homeDefenseConceded', 'awayDefenseConceded'],
        'DOUBLE_CHANCE' => ['expectedGoalsProxy', 'homeAttack', 'awayAttack', 'homeDefenseConceded', 'awayDefenseConceded'],
        'DRAW_NO_BET' => ['expectedGoalsProxy', 'homeAttack', 'awayAttack', 'homeDefenseConceded', 'awayDefenseConceded'],
        // The score grid is built from the four goal rates; the aggregate
        // proxy is not one of its inputs.
        'ASIAN_HANDICAP' => ['homeAttack', 'awayAttack', 'homeDefenseConceded', 'awayDefenseConceded'],
        'CORRECT_SCORE' => ['homeAttack', 'awayAttack', 'homeDefenseConceded', 'awayDefenseConceded'],
    ];

    /** Is this a companion (overround-only) price rather than a candidate? */
    public static function isCompanionSelection(?string $market, ?string $selection): bool
    {
        $market = strtoupper(trim((string) $market));
        $selection = strtoupper(trim((string) $selection));
        return in_array($selection, self::COMPANION_SELECTIONS[$market] ?? [], true);
    }

    public static function isSupportedMarketSelection(?string $market, ?string $selection): bool
    {
        $market = strtoupper(trim((string) $market));
        $selection = strtoupper(trim((string) $selection));
        return isset(self::SUPPORTED_MARKETS[$market]) && in_array($selection, self::SUPPORTED_MARKETS[$market], true);
    }

    /** Features the model needs for a market (empty list for unknown markets). */
    public static function requiredFeatures(string $market): array
    {
        return self::REQUIRED_FEATURES[strtoupper(trim($market))] ?? [];
    }

    public static function supportedMarkets(): array
    {
        return array_keys(self::SUPPORTED_MARKETS);
    }

    /**
     * Parse a totals selection (OVER_2_5, UNDER_3_5, …) into [line, side].
     * Null when the selection is not a totals line. Shared by the model,
     * settlement and the backtester so a line means the same everywhere:
     * the over wins when the total exceeds the line.
     *
     * @return array{0:float,1:string}|null
     */
    public static function totalsLine(string $selection): ?array
    {
        if (!preg_match('/^(OVER|UNDER)_(\d+)_(\d+)$/', strtoupper(trim($selection)), $m)) return null;
        return [(float) ($m[2] . '.' . $m[3]), $m[1]];
    }

    private ?ScoreGridPricer $gridPricer = null;

    /** The shared score-grid pricer (Dixon-Coles), built on first use. */
    private function gridPricer(): ScoreGridPricer
    {
        return $this->gridPricer ??= new ScoreGridPricer();
    }

    /** Markets whose probability is a sum over the joint score grid. */
    public static function isScoreGridMarket(?string $market): bool
    {
        return in_array(strtoupper(trim((string) $market)), ['ASIAN_HANDICAP', 'CORRECT_SCORE'], true);
    }

    public function predictOver15(array $featureSet, array $calibration): array
    {
        return $this->predict('TOTAL_GOALS', 'OVER_1_5', $featureSet, $calibration);
    }

    public function predict(string $market, string $selection, array $featureSet, array $calibration): array
    {
        $market = strtoupper(trim($market));
        $selection = strtoupper(trim($selection));
        if (!self::isSupportedMarketSelection($market, $selection)) return $this->reject('UNSUPPORTED_MARKET', $featureSet, $market, $selection);
        if (empty($featureSet['ok'])) return $this->reject($featureSet['reason'] ?? 'INSUFFICIENT_DATA', $featureSet, $market, $selection, $featureSet['missingFields'] ?? []);
        if (empty($calibration['approved']) || !isset($calibration['intercept'], $calibration['slope'])) return $this->reject('MODEL_NOT_CALIBRATED', $featureSet, $market, $selection);

        $features = $featureSet['features'];
        $missing = [];
        foreach (self::requiredFeatures($market) as $key) {
            if (!isset($features[$key]) || !is_numeric($features[$key])) $missing[] = $key;
        }
        if ($missing !== []) return $this->reject('INSUFFICIENT_DATA', $featureSet, $market, $selection, $missing);

        // A score-grid market can legitimately fail to produce a price (a
        // line outside the configured grid, degenerate expectancies). That
        // is stated as UNPRICEABLE_MARKET, never floored to a token 0.01.
        if (self::isScoreGridMarket($market)) {
            $gridProbability = $this->gridPricer()->probability($market, $selection, $features);
            if ($gridProbability === null) return $this->reject('UNPRICEABLE_MARKET', $featureSet, $market, $selection);
        }

        $raw = $this->rawProbability($market, $selection, $features);
        $calibrated = min(0.99, max(0.01, (float)$calibration['intercept'] + (float)$calibration['slope'] * $raw));
        return [
            'decision' => 'PREDICTION_READY',
            'market' => $market,
            'selection' => $selection,
            'rawModelProbability' => round($raw, 6),
            'calibratedProbability' => round($calibrated, 6),
            // The model's own fair price — 1 / calibrated probability.
            // This is a WINDELS number, never a copy of bookmaker odds.
            'fairOdds' => round(1 / max(0.01, $calibrated), 4),
            'modelName' => self::MODEL_NAME,
            'modelVersion' => self::MODEL_VERSION,
            'featureVersion' => $featureSet['version'],
            'calibrationVersion' => (string)($calibration['version'] ?? 'unspecified'),
            'inputSources' => $featureSet['inputSources'],
        ];
    }

    private function rawProbability(string $market, string $selection, array $f): float
    {
        $homeStrength = ((float)$f['homeAttack'] + (float)$f['awayDefenseConceded']) / 2;
        $awayStrength = ((float)$f['awayAttack'] + (float)$f['homeDefenseConceded']) / 2;
        $total = (float)$f['expectedGoalsProxy'];

        if ($market === 'TOTAL_GOALS') {
            // Every totals line uses the same goal-expectancy model: the
            // over probability is the logistic distance above the line, the
            // under probability its complement.
            $line = self::totalsLine($selection);
            if ($line === null) return 0.01;
            $over = $this->logistic($total - $line[0]);
            return $line[1] === 'OVER' ? $over : 1.0 - $over;
        }
        if ($market === 'BTTS') {
            $bothScorePressure = min($homeStrength, $awayStrength) - 0.75;
            $yes = $this->logistic($bothScorePressure);
            // NO is the exact complement of YES — the same single model
            // number read from the other side, not a second guess.
            return $selection === 'NO' ? 1.0 - $yes : $yes;
        }
        if ($market === 'MATCH_RESULT') {
            $home = $this->logistic($homeStrength - $awayStrength + 0.18);
            $draw = max(0.08, min(0.32, 0.28 - min(0.2, abs($homeStrength - $awayStrength) / 4)) );
            $away = max(0.01, 1 - $home - $draw);
            $sum = $home + $draw + $away;
            $probs = ['HOME' => $home / $sum, 'DRAW' => $draw / $sum, 'AWAY' => $away / $sum];
            return $probs[$selection] ?? 0.01;
        }
        if ($market === 'DOUBLE_CHANCE') {
            $home = $this->rawProbability('MATCH_RESULT', 'HOME', $f);
            $draw = $this->rawProbability('MATCH_RESULT', 'DRAW', $f);
            $away = $this->rawProbability('MATCH_RESULT', 'AWAY', $f);
            $probs = ['HOME_OR_DRAW' => $home + $draw, 'AWAY_OR_DRAW' => $away + $draw, 'HOME_OR_AWAY' => $home + $away];
            return min(0.99, max(0.01, $probs[$selection] ?? 0.01));
        }
        if (self::isScoreGridMarket($market)) {
            // Summed over the SAME normalised grid the football board reads,
            // so a scoreline cannot mean two different things.
            $gridProbability = $this->gridPricer()->probability($market, $selection, $f);
            return $gridProbability ?? 0.01;
        }
        if ($market === 'DRAW_NO_BET') {
            // The draw refunds the stake, so the market is the 1X2 model
            // renormalised over the two non-draw outcomes only.
            $home = $this->rawProbability('MATCH_RESULT', 'HOME', $f);
            $away = $this->rawProbability('MATCH_RESULT', 'AWAY', $f);
            $decisive = $home + $away;
            if ($decisive <= 0) return 0.01;
            $probs = ['HOME' => $home / $decisive, 'AWAY' => $away / $decisive];
            return min(0.99, max(0.01, $probs[$selection] ?? 0.01));
        }
        return 0.01;
    }

    private function logistic(float $x): float
    {
        return 1 / (1 + exp(-$x));
    }

    private function reject(string $reason, array $featureSet, ?string $market = null, ?string $selection = null, array $missingFields = []): array
    {
        $out = [
            'decision' => 'NO_PREDICTION',
            'reason' => $reason,
            'market' => $market,
            'selection' => $selection,
            'modelName' => self::MODEL_NAME,
            'modelVersion' => self::MODEL_VERSION,
            'featureVersion' => $featureSet['version'] ?? FeatureEngineeringEngine::VERSION,
        ];
        if ($missingFields !== []) $out['missingFields'] = array_values($missingFields);
        return $out;
    }
}
