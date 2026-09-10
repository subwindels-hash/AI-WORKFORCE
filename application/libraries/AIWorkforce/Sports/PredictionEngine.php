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

    /** @var array<string,list<string>> */
    public const SUPPORTED_MARKETS = [
        'MATCH_RESULT' => ['HOME', 'DRAW', 'AWAY'],
        'TOTAL_GOALS' => ['OVER_1_5'],
        'BTTS' => ['YES'],
        'DOUBLE_CHANCE' => ['HOME_OR_DRAW', 'AWAY_OR_DRAW', 'HOME_OR_AWAY'],
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
    ];

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
            return $this->logistic($total - 1.5);
        }
        if ($market === 'BTTS') {
            $bothScorePressure = min($homeStrength, $awayStrength) - 0.75;
            return $this->logistic($bothScorePressure);
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
