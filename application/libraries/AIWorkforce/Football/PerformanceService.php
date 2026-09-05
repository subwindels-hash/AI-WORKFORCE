<?php
namespace AIWorkforce\Football;

use AIWorkforce\Persistence\FootballRepository;

/**
 * Historical performance, computed only from stored settlements.
 *
 * The dashboard never counts anything itself: every figure in `report()` comes
 * from SQL aggregates over `football_prediction_settlements` (plus the
 * calibration service's binned ECE over the same rows), so a number on screen can
 * always be traced to the settled predictions behind it.
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
     *               evaluatedPredictions:int, correctResults:int, resultAccuracy:?float,
     *               correctScores:int, exactScoreAccuracy:?float, averageConfidence:?float,
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
        $samples = $this->repo->listCalibrationSamples($filter + ['limit' => 5000]);
        $confidences = []; $hits = []; $goalErrors = [];
        foreach ($samples as $sample) {
            if (is_numeric($sample['probability_home'] ?? null) && is_numeric($sample['probability_draw'] ?? null) && is_numeric($sample['probability_away'] ?? null)
                && in_array((string) ($sample['actual_result'] ?? ''), ['HOME', 'DRAW', 'AWAY'], true)) {
                $probabilities = ['home' => (float) $sample['probability_home'], 'draw' => (float) $sample['probability_draw'], 'away' => (float) $sample['probability_away']];
                $outcome = strtolower((string) $sample['actual_result']);
                $confidences[] = max($probabilities);
                $hits[] = self::argmax($probabilities) === $outcome ? 1 : 0;
            }
            if (is_numeric($sample['absolute_goal_error'] ?? null)) $goalErrors[] = (float) $sample['absolute_goal_error'];
        }
        $calibration = CalibrationService::reliability($confidences, $hits);
        $correctResults = (int) ($aggregate['correctResults'] ?? 0);
        $correctScores = (int) ($aggregate['correctScores'] ?? 0);
        return [
            'state' => 'MEASURED',
            'windowDays' => $days, 'windowStart' => $from, 'windowEnd' => $to,
            'evaluatedPredictions' => $evaluated,
            'correctResults' => $correctResults,
            'resultAccuracy' => round($correctResults / $evaluated, 5),
            'correctScores' => $correctScores,
            'exactScoreAccuracy' => round($correctScores / $evaluated, 5),
            'averageConfidence' => $aggregate['averageConfidence'] ?? null,
            'brier' => $aggregate['brier'] ?? null,
            'ece' => $confidences === [] ? null : $calibration['ece'],
            'mce' => $confidences === [] ? null : $calibration['mce'],
            'reliabilityBins' => $calibration['bins'],
            'logLoss' => $aggregate['logLoss'] ?? null,
            'averageDataQuality' => $aggregate['averageDataQuality'] ?? null,
            'averageGoalError' => $goalErrors === [] ? null : round(array_sum($goalErrors) / count($goalErrors), 3),
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
        foreach ($this->repo->listModelVersions(null, 20) as $model) {
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
            'correct_results' => $report['correctResults'],
            'correct_scores' => $report['correctScores'],
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

    private static function argmax(array $values): string
    {
        $best = 'home'; $bestValue = -INF;
        foreach (['home', 'draw', 'away'] as $key) {
            if ((float) ($values[$key] ?? 0) > $bestValue) { $bestValue = (float) $values[$key]; $best = $key; }
        }
        return $best;
    }
}
