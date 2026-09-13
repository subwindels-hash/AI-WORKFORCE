<?php
namespace AIWorkforce\Football;

use AIWorkforce\Persistence\FootballRepository;

/**
 * Historical performance, computed only from stored settlements.
 *
 * The dashboard never counts anything itself: every figure in `report()` comes
 * from stored `football_prediction_settlements` rows (SQL aggregates and frozen
 * settlement facts first, joined immutable prediction rows only to repair legacy
 * missing metric columns), so a number on screen can always be traced to the
 * settled predictions behind it.
 *
 * Empty history is a *state*, not a failure. `NO_SETTLED_PREDICTIONS` reports the
 * measurements as null (rendered as —) and explicitly says that live prediction
 * is unaffected: settlement history and forecasting ability are separate.
 */
final class PerformanceService
{
    public const NO_DATA = 'NO_SETTLED_PREDICTIONS';
    public const EMPTY_MESSAGE = 'No settled predictions yet. Historical performance metrics will appear after predicted matches have completed.';

    public function __construct(private FootballRepository $repo, private CalibrationService $calibration, private ModelRegistry $models) {}

    /**
     * @return array{state:string, windowDays:int, windowStart:string, windowEnd:string,
     *               evaluatedPredictions:int, correctResults:?int, resultAccuracy:?float,
     *               correctScores:?int, exactScoreAccuracy:?float, averageConfidence:?float,
     *               brier:?float, ece:?float, logLoss:?float, averageDataQuality:?float,
     *               averageGoalError:?float, byModel:list<array>, message:?string, note:?string,
     *               gatesPredictions:bool}
     */
    public function report(int $windowDays = 30, ?int $modelVersionId = null): array
    {
        $days = max(1, min(365, $windowDays));
        $to = gmdate('c');
        $from = gmdate('c', time() - ($days * 86400));
        $filter = ['from' => $from, 'to' => $to];
        if ($modelVersionId !== null && $modelVersionId > 0) $filter['modelVersionId'] = $modelVersionId;
        $aggregate = $this->repo->settlementAggregates($filter);
        $evaluated = (int) ($aggregate['evaluated'] ?? 0);
        if ($evaluated === 0) {
            return [
                'state' => self::NO_DATA,
                'windowDays' => $days, 'windowStart' => $from, 'windowEnd' => $to,
                'evaluatedPredictions' => 0, 'correctResults' => 0, 'resultAccuracy' => null,
                'correctScores' => 0, 'exactScoreAccuracy' => null, 'averageConfidence' => null,
                'brier' => null, 'ece' => null, 'logLoss' => null, 'averageDataQuality' => null,
                'averageGoalError' => null, 'byModel' => [],
                // The flag the console reads to confirm an empty history is not a gate:
                // settlement never disables forecasting.
                'gatesPredictions' => false,
                'message' => self::EMPTY_MESSAGE,
                'note' => 'Live predictions are unaffected by this: forecasting depends on provider data, statistics and the loaded model — not on how much history has been settled.',
                'modelVersionId' => $modelVersionId,
            ];
        }
        // Read the full settled window for ECE and legacy-column repair. Using
        // a fixed sample here would make ECE and repaired averages describe only
        // the newest rows while the sidebar says "30-day performance".
        $samples = $this->repo->listCalibrationSamples($filter + ['limit' => max(1000, $evaluated)]);
        $confidences = []; $hits = []; $goalErrors = []; $brierValues = []; $logLossValues = [];
        $confidenceValues = []; $qualityValues = []; $resultGrades = []; $scoreGrades = [];
        foreach ($samples as $sample) {
            $probabilities = null;
            $actual = self::sampleActualResult($sample);
            $hasActualResult = $actual !== null;
            $homeProbability = self::firstNumericValue($sample, ['settled_probability_home', 'probability_home']);
            $drawProbability = self::firstNumericValue($sample, ['settled_probability_draw', 'probability_draw']);
            $awayProbability = self::firstNumericValue($sample, ['settled_probability_away', 'probability_away']);
            if ($homeProbability !== null && $drawProbability !== null && $awayProbability !== null) {
                $probabilities = ['home' => $homeProbability, 'draw' => $drawProbability, 'away' => $awayProbability];
            }

            if ($probabilities !== null && $hasActualResult) {
                $outcome = strtolower($actual);
                $confidences[] = max($probabilities);
                $hits[] = self::argmax($probabilities) === $outcome ? 1 : 0;
            }

            if (is_numeric($sample['brier'] ?? null)) $brierValues[] = (float) $sample['brier'];
            elseif ($probabilities !== null && $hasActualResult) $brierValues[] = self::brierScore($probabilities, $actual);

            if (is_numeric($sample['log_loss'] ?? null)) $logLossValues[] = (float) $sample['log_loss'];
            elseif ($probabilities !== null && $hasActualResult) $logLossValues[] = self::logLossScore($probabilities, $actual);

            $confidence = self::firstNumericValue($sample, ['settled_confidence', 'confidence']);
            if ($confidence !== null) $confidenceValues[] = $confidence;
            $quality = self::firstNumericValue($sample, ['data_quality_score', 'prediction_data_quality_score']);
            if ($quality !== null) $qualityValues[] = $quality;

            $resultGrade = self::sampleResultGrade($sample);
            if ($resultGrade !== null) $resultGrades[] = $resultGrade;
            $scoreGrade = self::sampleScoreGrade($sample);
            if ($scoreGrade !== null) $scoreGrades[] = $scoreGrade;

            $goalError = is_numeric($sample['absolute_goal_error'] ?? null)
                ? (float) $sample['absolute_goal_error']
                : self::sampleGoalError($sample);
            if ($goalError !== null) $goalErrors[] = $goalError;
        }
        $calibration = CalibrationService::reliability($confidences, $hits);
        $resultAccuracyMeasured = count($resultGrades) === $evaluated || (int) ($aggregate['resultGradeMissing'] ?? 0) === 0;
        $scoreAccuracyMeasured = count($scoreGrades) === $evaluated || (int) ($aggregate['scoreGradeMissing'] ?? 0) === 0;
        $correctResults = $resultAccuracyMeasured
            ? (count($resultGrades) === $evaluated ? array_sum($resultGrades) : (int) ($aggregate['correctResults'] ?? 0))
            : null;
        $correctScores = $scoreAccuracyMeasured
            ? (count($scoreGrades) === $evaluated ? array_sum($scoreGrades) : (int) ($aggregate['correctScores'] ?? 0))
            : null;
        $brier = is_numeric($aggregate['brier'] ?? null) ? (float) $aggregate['brier']
            : (count($brierValues) === $evaluated ? self::average($brierValues, 6) : null);
        $logLoss = is_numeric($aggregate['logLoss'] ?? null) ? (float) $aggregate['logLoss']
            : (count($logLossValues) === $evaluated ? self::average($logLossValues, 6) : null);
        $averageConfidence = (int) ($aggregate['confidenceMissing'] ?? 0) === 0 && is_numeric($aggregate['averageConfidence'] ?? null)
            ? (float) $aggregate['averageConfidence']
            : (count($confidenceValues) === $evaluated ? self::average($confidenceValues, 2) : null);
        $averageDataQuality = (int) ($aggregate['dataQualityMissing'] ?? 0) === 0 && is_numeric($aggregate['averageDataQuality'] ?? null)
            ? (float) $aggregate['averageDataQuality']
            : (count($qualityValues) === $evaluated ? self::average($qualityValues, 2) : null);
        $averageGoalError = (int) ($aggregate['goalErrorMissing'] ?? 0) === 0 && is_numeric($aggregate['averageGoalError'] ?? null)
            ? (float) $aggregate['averageGoalError']
            : (count($goalErrors) === $evaluated ? self::average($goalErrors, 3) : null);
        $calibrationMeasured = count($confidences) === $evaluated;

        return [
            'state' => 'MEASURED',
            'windowDays' => $days, 'windowStart' => $from, 'windowEnd' => $to,
            'evaluatedPredictions' => $evaluated,
            'correctResults' => $correctResults === null ? null : (int) $correctResults,
            'resultAccuracy' => $correctResults === null ? null : round($correctResults / $evaluated, 5),
            'correctScores' => $correctScores === null ? null : (int) $correctScores,
            'exactScoreAccuracy' => $correctScores === null ? null : round($correctScores / $evaluated, 5),
            'averageConfidence' => $averageConfidence,
            'brier' => $brier,
            'ece' => $calibrationMeasured ? $calibration['ece'] : null,
            'mce' => $calibrationMeasured ? $calibration['mce'] : null,
            'reliabilityBins' => $calibrationMeasured ? $calibration['bins'] : [],
            'logLoss' => $logLoss,
            'averageDataQuality' => $averageDataQuality,
            'averageGoalError' => $averageGoalError,
            'calibrationSampleCount' => count($confidences),
            'byModel' => $this->byModel($from, $to),
            'gatesPredictions' => false,
            'message' => null,
            'note' => null,
            'modelVersionId' => $modelVersionId,
        ];
    }

    /** Per-model breakdown over the same window, so version comparison is real. */
    private function byModel(string $from, string $to): array
    {
        $out = [];
        foreach ($this->repo->listModelVersions(null, 5) as $model) {
            $id = (int) ($model['id'] ?? 0);
            if ($id <= 0) continue;
            $aggregate = $this->repo->settlementAggregates(['modelVersionId' => $id, 'from' => $from, 'to' => $to]);
            $evaluated = (int) ($aggregate['evaluated'] ?? 0);
            if ($evaluated === 0) continue;
            $out[] = [
                'modelVersionId' => $id,
                'modelName' => (string) ($model['model_name'] ?? ''),
                'modelVersion' => (string) ($model['model_version'] ?? ''),
                'status' => (string) ($model['status'] ?? ''),
                'evaluated' => $evaluated,
                'correctResults' => (int) ($aggregate['correctResults'] ?? 0),
                'resultAccuracy' => round((int) ($aggregate['correctResults'] ?? 0) / $evaluated, 5),
                'correctScores' => (int) ($aggregate['correctScores'] ?? 0),
                'exactScoreAccuracy' => round((int) ($aggregate['correctScores'] ?? 0) / $evaluated, 5),
                'averageConfidence' => $aggregate['averageConfidence'] ?? null,
                'brier' => $aggregate['brier'] ?? null,
                'logLoss' => $aggregate['logLoss'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * Persist the window's figures for trend history. Stored rows are snapshots of
     * what the aggregates said at that moment — they are never read back as the
     * source of the live panel.
     *
     * @return array<string,mixed>
     */
    public function snapshot(int $windowDays = 30, ?int $modelVersionId = null): array
    {
        $report = $this->report($windowDays, $modelVersionId);
        $row = $this->repo->savePerformanceSnapshot([
            'model_version_id' => $modelVersionId,
            'calibration_version_id' => $this->calibrationFor($modelVersionId),
            'window_days' => $report['windowDays'],
            'window_start' => $report['windowStart'],
            'window_end' => $report['windowEnd'],
            'evaluated_predictions' => $report['evaluatedPredictions'],
            'correct_results' => (int) ($report['correctResults'] ?? 0),
            'correct_scores' => (int) ($report['correctScores'] ?? 0),
            'result_accuracy' => $report['resultAccuracy'],
            'exact_score_accuracy' => $report['exactScoreAccuracy'],
            'average_confidence' => $report['averageConfidence'],
            'average_data_quality' => $report['averageDataQuality'],
            'brier' => $report['brier'],
            'ece' => $report['ece'],
            'log_loss' => $report['logLoss'],
            'payload' => $report,
            'computed_at' => gmdate('c'),
        ]);
        // A model version's stored metrics are refreshed from the same report the
        // dashboard reads, keeping "last evaluated" honest.
        if ($modelVersionId !== null && $modelVersionId > 0 && $report['state'] === 'MEASURED') {
            $this->models->recordEvaluation($modelVersionId, [
                'validation_sample_size' => $report['evaluatedPredictions'],
                'accuracy' => $report['resultAccuracy'],
                'log_loss' => $report['logLoss'],
                'brier_score' => $report['brier'],
                'ece' => $report['ece'],
                'last_evaluated_at' => gmdate('c'),
            ]);
        }
        return ['status' => 'STORED', 'snapshot' => $row, 'report' => $report];
    }

    public function latestSnapshot(int $windowDays = 30, ?int $modelVersionId = null): ?array
    {
        return $this->repo->latestPerformanceSnapshot($windowDays, $modelVersionId);
    }

    /**
     * History of the model's own validation sample, so a version can be compared
     * with what it achieved when it was validated.
     */
    public function modelEvaluation(?int $modelVersionId): array
    {
        if ($modelVersionId === null || $modelVersionId <= 0) return ['state' => 'MODEL_NOT_LOADED', 'samples' => 0, 'metrics' => []];
        $model = $this->repo->findModelVersion($modelVersionId);
        if ($model === null) return ['state' => 'MODEL_NOT_FOUND', 'samples' => 0, 'metrics' => []];
        return [
            'state' => (string) ($model['status'] ?? ModelRegistry::DRAFT),
            'samples' => (int) ($model['validation_sample_size'] ?? 0),
            'metrics' => array_filter([
                'accuracy' => $model['accuracy'] ?? null,
                'logLoss' => $model['log_loss'] ?? null,
                'brierScore' => $model['brier_score'] ?? null,
                'ece' => $model['ece'] ?? null,
            ], static fn($value) => $value !== null),
            'trainedAt' => $model['trained_at'] ?? null,
            'validatedAt' => $model['validated_at'] ?? null,
            'calibratedAt' => $model['calibrated_at'] ?? null,
            'approvedAt' => $model['approved_at'] ?? null,
            'approvedBy' => $model['approved_by'] ?? null,
            'activatedAt' => $model['activated_at'] ?? null,
            'lastEvaluatedAt' => $model['last_evaluated_at'] ?? null,
            'calibrationVersionId' => $model['calibration_version_id'] ?? null,
        ];
    }

    private function calibrationFor(?int $modelVersionId): ?int
    {
        if ($modelVersionId === null || $modelVersionId <= 0) return null;
        $model = $this->repo->findModelVersion($modelVersionId);
        $id = (int) ($model['calibration_version_id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    private static function firstNumericValue(array $row, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (is_numeric($row[(string) $key] ?? null)) return (float) $row[(string) $key];
        }
        return null;
    }

    private static function firstStringValue(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($row[(string) $key] ?? ''));
            if ($value !== '') return $value;
        }
        return null;
    }

    private static function sampleActualResult(array $sample): ?string
    {
        $actual = strtoupper((string) ($sample['actual_result'] ?? ''));
        if (in_array($actual, ['HOME', 'DRAW', 'AWAY'], true)) return $actual;
        if (is_numeric($sample['actual_home_score'] ?? null) && is_numeric($sample['actual_away_score'] ?? null)) {
            $home = (int) $sample['actual_home_score'];
            $away = (int) $sample['actual_away_score'];
            return $home > $away ? 'HOME' : ($home === $away ? 'DRAW' : 'AWAY');
        }
        return null;
    }

    /** @param list<float|int> $values */
    private static function average(array $values, int $precision): ?float
    {
        if ($values === []) return null;
        return round(array_sum($values) / count($values), $precision);
    }

    /** @param array{home:float,draw:float,away:float} $probabilities */
    private static function brierScore(array $probabilities, string $actualResult): float
    {
        $actual = strtolower($actualResult);
        $sum = 0.0;
        foreach (['home', 'draw', 'away'] as $outcome) {
            $sum += ((float) ($probabilities[$outcome] ?? 0.0) - ($outcome === $actual ? 1.0 : 0.0)) ** 2;
        }
        return round($sum / 3, 6);
    }

    /** @param array{home:float,draw:float,away:float} $probabilities */
    private static function logLossScore(array $probabilities, string $actualResult): float
    {
        $actual = strtolower($actualResult);
        $value = max(1e-12, min(1.0, (float) ($probabilities[$actual] ?? 0.0)));
        return round(-log($value), 6);
    }

    private static function sampleResultGrade(array $sample): ?int
    {
        if (is_numeric($sample['correct_result'] ?? null)) return (int) $sample['correct_result'] === 1 ? 1 : 0;
        $predicted = strtoupper((string) (self::firstStringValue($sample, ['predicted_result', 'prediction_predicted_result']) ?? ''));
        $actual = self::sampleActualResult($sample);
        if (!in_array($predicted, ['HOME', 'DRAW', 'AWAY'], true) || $actual === null) return null;
        return $predicted === $actual ? 1 : 0;
    }

    private static function sampleScoreGrade(array $sample): ?int
    {
        if (is_numeric($sample['correct_exact_score'] ?? null)) return (int) $sample['correct_exact_score'] === 1 ? 1 : 0;
        $predictedHome = self::firstNumericValue($sample, ['predicted_home_score', 'prediction_predicted_home_score']);
        $predictedAway = self::firstNumericValue($sample, ['predicted_away_score', 'prediction_predicted_away_score']);
        $actualHome = self::firstNumericValue($sample, ['actual_home_score']);
        $actualAway = self::firstNumericValue($sample, ['actual_away_score']);
        if ($predictedHome === null || $predictedAway === null || $actualHome === null || $actualAway === null) return null;
        return (int) $predictedHome === (int) $actualHome && (int) $predictedAway === (int) $actualAway ? 1 : 0;
    }

    private static function sampleGoalError(array $sample): ?float
    {
        $predictedHome = self::firstNumericValue($sample, ['predicted_home_score', 'prediction_predicted_home_score']);
        $predictedAway = self::firstNumericValue($sample, ['predicted_away_score', 'prediction_predicted_away_score']);
        $actualHome = self::firstNumericValue($sample, ['actual_home_score']);
        $actualAway = self::firstNumericValue($sample, ['actual_away_score']);
        if ($predictedHome === null || $predictedAway === null || $actualHome === null || $actualAway === null) return null;
        return round((abs((int) $predictedHome - (int) $actualHome) + abs((int) $predictedAway - (int) $actualAway)) / 2, 3);
    }

    private static function argmax(array $values): string
    {
        $best = 'home'; $bestValue = -INF;
        foreach (['home', 'draw', 'away'] as $key) {
            if ((float) ($values[$key] ?? 0) > $bestValue) { $bestValue = (float) $values[$key]; $best = $key; }
        }
        return $best;
    }
}
