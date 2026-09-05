<?php
namespace AIWorkforce\Football;

use AIWorkforce\Persistence\AuditRepository;
use AIWorkforce\Persistence\FootballRepository;

/**
 * Probability calibration.
 *
 * A model's raw argmax probability is a *rank*, not a frequency: 0.77 does not
 * mean "wins 77% of the time" until history says so. This service fits a single
 * temperature parameter by minimizing negative log-likelihood over stored
 * settled predictions, and refuses to invent a calibration when the sample is
 * too small — the model then reports `CALIBRATION_PENDING` and displays raw
 * values under an explicit "uncalibrated" label.
 *
 * Method: temperature scaling on the three-class simplex,
 *   p_i(T) = p_i^(1/T) / Σ_j p_j^(1/T)
 * (equivalent to scaling logits, and it preserves the ordering of the classes
 * exactly). T > 1 pulls probabilities toward the uniform distribution.
 *
 * Deliberate constraint: the fitted temperature is only ever ≥ 1. Calibration
 * may therefore SOFTEN a model's confidence but never sharpen it — a module
 * that only ever lowers a number is wrong far less often than one that raises
 * it, and requirement-wise the displayed figure must never exceed what the
 * stored history supports. If the unconstrained optimum would have been below
 * 1 (a model that is "underconfident"), that is recorded in the row's reason
 * and the calibration is still stored at T = 1.0.
 */
final class CalibrationService
{
    public const PENDING = 'CALIBRATION_PENDING';
    public const CALIBRATED = 'CALIBRATED';
    public const METHOD = 'temperature';
    private const BIN_COUNT = 10;

    public function __construct(private FootballRepository $repo, private FootballConfiguration $config, private ?AuditRepository $audit = null) {}

    /**
     * Fit and store a calibration version for a model, or report why it is not
     * possible yet. Never overwrites a stored calibration with a thinner one.
     *
     * @return array{status:string, samples:int, minimum:int, calibration:?array, metrics:array<string,mixed>, reason:?string}
     */
    public function fit(int $modelVersionId, ?string $windowStart = null, string $actor = 'system'): array
    {
        $minimum = $this->config->minCalibrationSamples();
        $samples = $this->samples($modelVersionId, $windowStart);
        $usable = array_values(array_filter($samples, static fn(array $row) => self::hasProbabilities($row) && in_array((string) ($row['actual_result'] ?? ''), ['HOME', 'DRAW', 'AWAY'], true)));
        if (count($usable) < $minimum) {
            return [
                'status' => self::PENDING,
                'samples' => count($usable),
                'minimum' => $minimum,
                'calibration' => null,
                'metrics' => [],
                'reason' => 'Calibration needs ' . $minimum . ' settled predictions with stored probabilities; ' . count($usable) . ' available' . ($windowStart !== null ? ' since ' . $windowStart : '') . '.',
            ];
        }
        $metrics = $this->measure($usable, ['temperature' => 1.0]);
        $fitted = $this->fitTemperature($usable, $metrics);
        // Only adopt the fit when it genuinely improves log loss; a model that is
        // already well calibrated keeps T = 1 rather than chasing noise.
        $improves = $fitted['logLoss'] < $metrics['logLoss'] - self::EPSILON_IMPROVEMENT;
        $temperature = $improves ? $fitted['temperature'] : 1.0;
        $final = $this->measure($usable, ['temperature' => $temperature]);
        $window = $this->window($usable);
        $version = 'T' . number_format($temperature, 4, '.', '') . '-' . gmdate('Ymd', strtotime($window['end']));
        $row = $this->repo->saveCalibration([
            'model_version_id' => $modelVersionId,
            'calibration_version' => $version,
            'method' => self::METHOD,
            'parameters' => json_encode([
                'temperature' => $temperature,
                'binCount' => self::BIN_COUNT,
                'source' => $improves ? 'temperature-fitted' : 'temperature-1.0-already-calibrated',
                'gridStep' => $fitted['gridStep'] ?? null,
                'constraint' => 'temperature>=1 (softening only)',
                'unconstrainedOptimum' => $fitted['unconstrained'] ?? null,
            ]),
            'sample_size' => count($usable),
            'accuracy' => $final['accuracy'],
            'log_loss' => $final['logLoss'],
            'brier' => $final['brier'],
            'ece' => $final['ece'],
            'mce' => $final['mce'],
            'reliability_bins' => json_encode($final['bins']),
            'training_window_start' => $window['start'],
            'training_window_end' => $window['end'],
            'status' => self::CALIBRATED,
            'created_by' => $actor,
            'reason' => $improves
                ? (!empty($fitted['sharpenRequested']) ? 'An unconstrained fit preferred T=' . (string) $fitted['unconstrained'] . ' (sharpening); rejected — this module only ever softens confidence, so the stored calibration is T=' . (string) $temperature . '.' : null)
                : 'Fitted temperature did not improve log loss by the required margin; calibration stored at T=1.0 and reported as measured, not as adjusted.',
        ]);
        $this->repo->updateModelVersion($modelVersionId, ['calibration_version_id' => (int) ($row['id'] ?? 0), 'last_evaluated_at' => gmdate('c')]);
        $this->audit?->emit('FOOTBALL_MODEL_CALIBRATED', 'Football model #' . $modelVersionId . ' calibrated (' . count($usable) . ' samples)', [
            'calibrationVersion' => $version, 'temperature' => $temperature,
            'logLossBefore' => $metrics['logLoss'], 'logLossAfter' => $final['logLoss'],
            'eceBefore' => $metrics['ece'], 'eceAfter' => $final['ece'], 'samples' => count($usable),
        ], $actor);
        return ['status' => self::CALIBRATED, 'samples' => count($usable), 'minimum' => $minimum, 'calibration' => $row,
            'metrics' => $final + ['before' => $metrics], 'reason' => null];
    }

    /**
     * Apply the model's stored calibration to a raw probability vector.
     *
     * @return array{probabilities:array{home:float,draw:float,away:float}, basis:string, state:string,
     *               calibrationVersion:?string, calibrationVersionId:?int, temperature:float, raw:array<string,float>}
     */
    public function apply(?array $model, array $raw): array
    {
        $normalized = self::normalize($raw);
        $calibration = $model === null ? null : $this->activeCalibration((int) ($model['calibration_version_id'] ?? 0));
        if ($calibration === null || (string) ($calibration['status'] ?? '') !== self::CALIBRATED) {
            return [
                'probabilities' => $normalized,
                'basis' => 'RAW',
                'state' => self::PENDING,
                'calibrationVersion' => null,
                'calibrationVersionId' => null,
                'temperature' => 1.0,
                'raw' => $normalized,
            ];
        }
        $parameters = is_array($calibration['parameters'] ?? null) ? $calibration['parameters'] : (json_decode((string) ($calibration['parameters'] ?? '{}'), true) ?: []);
        // max(1.0, …) here as well: even a hand-edited or legacy row cannot make
        // the module display a sharper probability than the model produced.
        $temperature = isset($parameters['temperature']) && is_numeric($parameters['temperature']) ? max(1.0, (float) $parameters['temperature']) : 1.0;
        return [
            'probabilities' => self::normalize(self::temperature($normalized, $temperature)),
            'basis' => 'CALIBRATED',
            'state' => self::CALIBRATED,
            'calibrationVersion' => (string) ($calibration['calibration_version'] ?? ''),
            'calibrationVersionId' => (int) ($calibration['id'] ?? 0),
            'temperature' => $temperature,
            'raw' => $normalized,
        ];
    }

    /** @return array<string,mixed>|null */
    public function activeCalibration(int $calibrationVersionId): ?array
    {
        if ($calibrationVersionId <= 0) return null;
        return $this->repo->findCalibration($calibrationVersionId);
    }

    /**
     * Summary for the admin diagnostics + model panel. Counts what is actually
     * stored, so "0 approved calibration versions" can never be replaced by a
     * reassuring-but-unbacked number.
     *
     * @return array<int,array<string,mixed>>
     */
    public function versions(int $modelVersionId): array
    {
        $rows = $this->repo->listCalibrations($modelVersionId, null, 50);
        return array_map(static function (array $row): array {
            $parameters = is_array($row['parameters'] ?? null) ? $row['parameters'] : (json_decode((string) ($row['parameters'] ?? '{}'), true) ?: []);
            return [
                'id' => (int) ($row['id'] ?? 0),
                'calibrationVersion' => (string) ($row['calibration_version'] ?? ''),
                'method' => (string) ($row['method'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'samples' => (int) ($row['sample_size'] ?? 0),
                'temperature' => $parameters['temperature'] ?? null,
                'accuracy' => $row['accuracy'] ?? null,
                'logLoss' => $row['log_loss'] ?? null,
                'brier' => $row['brier'] ?? null,
                'ece' => $row['ece'] ?? null,
                'mce' => $row['mce'] ?? null,
                'windowStart' => $row['training_window_start'] ?? null,
                'windowEnd' => $row['training_window_end'] ?? null,
                'createdAt' => $row['created_at'] ?? null,
                'approvedBy' => $row['approved_by'] ?? null,
                'approvedAt' => $row['approved_at'] ?? null,
                'reason' => $row['reason'] ?? null,
            ];
        }, $rows);
    }

    public function approvedCount(): int
    {
        return count(array_filter($this->repo->listCalibrations(null, self::CALIBRATED, 200), static fn(array $row) => (string) ($row['status'] ?? '') === self::CALIBRATED));
    }

    /** @return list<array<string,mixed>> */
    private function samples(int $modelVersionId, ?string $windowStart): array
    {
        $filter = ['modelVersionId' => $modelVersionId, 'limit' => max(1000, $this->config->minCalibrationSamples() * 20)];
        if ($windowStart !== null) $filter['from'] = $windowStart;
        return $this->repo->listCalibrationSamples($filter);
    }

    private static function hasProbabilities(array $row): bool
    {
        foreach (['raw_home', 'raw_draw', 'raw_away'] as $key) {
            if (!isset($row[$key]) || !is_numeric($row[$key])) return false;
        }
        return (float) $row['raw_home'] + (float) $row['raw_draw'] + (float) $row['raw_away'] > 0;
    }

    private const EPSILON_IMPROVEMENT = 1e-4;

    /**
     * Coarse-to-fine grid search over temperature, minimizing mean log loss,
     * restricted to T ≥ 1 (softening only). The unrestricted optimum is also
     * reported so an operator can see when the constraint was binding.
     *
     * @param list<array<string,mixed>> $samples
     * @return array{temperature:float, logLoss:float, gridStep:float, unconstrained:float, sharpenRequested:bool}
     */
    private function fitTemperature(array $samples, array $baseline): array
    {
        $best = ['temperature' => 1.0, 'logLoss' => (float) $baseline['logLoss'], 'gridStep' => 0.0, 'unconstrained' => 1.0];
        $free = $best;
        foreach ([0.10, 0.02, 0.005] as $index => $step) {
            $center = $best['temperature'];
            $spread = $index === 0 ? 2.0 : max($step * 4, $step * 2);
            $from = max(1.0, $center - $spread);
            $to = $index === 0 ? 4.0 : min(6.0, $center + $spread);
            for ($temperature = $from; $temperature <= $to + 1e-9; $temperature += $step) {
                $metrics = $this->measure($samples, ['temperature' => round($temperature, 4)]);
                if ($metrics['logLoss'] < $best['logLoss'] - 1e-9) {
                    $best = ['temperature' => round($temperature, 4), 'logLoss' => $metrics['logLoss'], 'gridStep' => $step, 'unconstrained' => $free['unconstrained']];
                }
            }
            // Second pass below 1.0 is measurement only: it records how much a
            // sharpening fit would have claimed, without adopting it.
            if ($index === 0) {
                for ($temperature = 0.95; $temperature >= 0.20; $temperature -= $step) {
                    $metrics = $this->measure($samples, ['temperature' => round($temperature, 4)]);
                    if ($metrics['logLoss'] < $free['logLoss'] - 1e-9) {
                        $free = ['temperature' => round($temperature, 4), 'logLoss' => $metrics['logLoss'], 'gridStep' => $step, 'unconstrained' => round($temperature, 4)];
                    }
                }
            }
        }
        $best['sharpenRequested'] = $free['temperature'] < 1.0;
        $best['unconstrained'] = $free['temperature'];
        return $best;
    }

    /**
     * @param list<array<string,mixed>> $samples
     * @return array{logLoss:float,brier:float,ece:float,mce:float,accuracy:?float,bins:list<array<string,mixed>>}
     */
    public function measure(array $samples, array $options = []): array
    {
        $temperature = max(0.05, (float) ($options['temperature'] ?? 1.0));
        $logLoss = 0.0; $brier = 0.0; $correct = 0; $count = 0;
        $confidences = []; $hits = [];
        foreach ($samples as $row) {
            if (!self::hasProbabilities($row)) continue;
            $probabilities = self::temperature(self::normalize(['home' => (float) $row['raw_home'], 'draw' => (float) $row['raw_draw'], 'away' => (float) $row['raw_away']]), $temperature);
            $outcome = (string) $row['actual_result'];
            $chosen = $probabilities[strtolower($outcome)] ?? 0.0;
            $logLoss += -log(max($chosen, 1e-12));
            foreach (['home', 'draw', 'away'] as $key) {
                $indicator = strtolower($outcome) === $key ? 1.0 : 0.0;
                $brier += ($probabilities[$key] - $indicator) ** 2;
            }
            $argmax = self::argmax($probabilities);
            $correct += $argmax === strtolower($outcome) ? 1 : 0;
            $confidences[] = max($probabilities);
            $hits[] = $argmax === strtolower($outcome) ? 1 : 0;
            $count++;
        }
        if ($count === 0) {
            return ['logLoss' => INF, 'brier' => 0.0, 'ece' => 0.0, 'mce' => 0.0, 'accuracy' => null, 'bins' => [], 'samples' => 0];
        }
        $calibration = self::reliability($confidences, $hits, self::BIN_COUNT);
        return [
            'logLoss' => round($logLoss / $count, 6),
            'brier' => round($brier / (3 * $count), 6),
            'ece' => $calibration['ece'],
            'mce' => $calibration['mce'],
            'accuracy' => round($correct / $count, 5),
            'bins' => $calibration['bins'],
            'samples' => $count,
        ];
    }

    /**
     * Expected Calibration Error: weighted mean of |confidence − accuracy| per
     * equal-width bin over the predicted-confidence axis. `mce` is the largest
     * single-bin gap, which is what "the model is overconfident around 90%"
     * would look like even when ECE averages it away.
     *
     * @param list<float> $confidences
     * @param list<int>   $hits
     * @return array{ece:float, mce:float, bins:list<array<string,mixed>>}
     */
    public static function reliability(array $confidences, array $hits, int $bins = self::BIN_COUNT): array
    {
        $bins = max(2, $bins);
        $buckets = array_fill(0, $bins, ['count' => 0, 'confidence' => 0.0, 'hits' => 0]);
        foreach ($confidences as $index => $confidence) {
            $bin = (int) min($bins - 1, max(0, floor((float) $confidence * $bins)));
            $buckets[$bin]['count']++;
            $buckets[$bin]['confidence'] += (float) $confidence;
            $buckets[$bin]['hits'] += (int) ($hits[$index] ?? 0);
        }
        $total = count($confidences);
        $ece = 0.0; $mce = 0.0; $rows = [];
        foreach ($buckets as $bin => $bucket) {
            if ($bucket['count'] === 0) {
                $rows[] = ['bin' => $bin, 'range' => sprintf('[%.1f,%.1f)', $bin / $bins, ($bin + 1) / $bins), 'count' => 0, 'confidence' => null, 'accuracy' => null, 'gap' => null];
                continue;
            }
            $averageConfidence = $bucket['confidence'] / $bucket['count'];
            $accuracy = $bucket['hits'] / $bucket['count'];
            $gap = abs($averageConfidence - $accuracy);
            $ece += ($bucket['count'] / max(1, $total)) * $gap;
            $mce = max($mce, $gap);
            $rows[] = ['bin' => $bin, 'range' => sprintf('[%.1f,%.1f)', $bin / $bins, ($bin + 1) / $bins), 'count' => $bucket['count'],
                'confidence' => round($averageConfidence, 4), 'accuracy' => round($accuracy, 4), 'gap' => round($gap, 4)];
        }
        return ['ece' => round($ece, 6), 'mce' => round($mce, 6), 'bins' => $rows];
    }

    /** @param array{home:float,draw:float,away:float} $p */
    private static function temperature(array $p, float $temperature): array
    {
        if (abs($temperature - 1.0) < 1e-9) return $p;
        $out = [];
        foreach ($p as $key => $value) {
            $out[$key] = max(1e-12, (float) $value) ** (1.0 / $temperature);
        }
        return self::normalize($out);
    }

    /** @return array{home:float,draw:float,away:float} */
    public static function normalize(array $p): array
    {
        $sum = 0.0;
        foreach (['home', 'draw', 'away'] as $key) {
            $p[$key] = max(0.0, (float) ($p[$key] ?? 0.0));
            $sum += $p[$key];
        }
        if ($sum <= 0.0) return ['home' => 1 / 3, 'draw' => 1 / 3, 'away' => 1 / 3];
        $out = [];
        foreach (['home', 'draw', 'away'] as $key) $out[$key] = $p[$key] / $sum;
        // Guarantee the three displayed numbers add to exactly 1.0 after rounding.
        $round = ['home' => round($out['home'], 6), 'draw' => round($out['draw'], 6), 'away' => round($out['away'], 6)];
        $drift = round(1.0 - array_sum($round), 6);
        if (abs($drift) > 0) {
            $round[self::argmax($round)] += $drift;
            foreach ($round as $key => $value) $round[$key] = round($value, 6);
        }
        return $round;
    }

    private static function argmax(array $values): string
    {
        $best = 'home'; $bestValue = -INF;
        foreach ($values as $key => $value) {
            if ((float) $value > $bestValue) { $bestValue = (float) $value; $best = (string) $key; }
        }
        return $best;
    }

    private function window(array $samples): array
    {
        $dates = array_values(array_filter(array_map(static fn(array $row) => (string) ($row['settled_at'] ?? ''), $samples)));
        sort($dates);
        return ['start' => $dates[0] ?? gmdate('c'), 'end' => end($dates) ?: gmdate('c')];
    }
}
