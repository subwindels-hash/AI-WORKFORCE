<?php
namespace AIWorkforce\Football;

use AIWorkforce\Persistence\FootballRepository;

/**
 * Backtesting over stored historical matches: for every FINISHED fixture in a
 * window whose final score is stored, the engine rebuilds the features it can
 * see, runs the same prediction path a live match goes through (persist=false —
 * the backtest writes nothing to the prediction ledger) and grades the result
 * against the final score.
 *
 * One honesty rule is carried in the payload itself: the team statistics the
 * features read are the CURRENT stored rows, which may include data recorded
 * after the matches being re-scored. The report is therefore labelled a
 * directional engine validation, never a clean out-of-sample test. Matches the
 * data-quality gate refuses are counted as skipped, never dressed up as losses.
 */
final class BacktestService
{
    public const CAVEAT = 'Backtest uses currently stored team statistics, which may include data recorded after these matches. Treat the figures as a directional engine validation, not a clean out-of-sample test.';

    public function __construct(
        private FootballRepository $repo,
        private FeatureBuilder $features,
        private OutcomePredictor $predictor,
        private ModelRegistry $models,
        private FootballConfiguration $config,
    ) {}

    /**
     * @param string $from YYYY-MM-DD (inclusive)
     * @param string $to   YYYY-MM-DD (inclusive)
     * @return array{state:string, from:string, to:string, evaluated:int, correctResults:int,
     *               resultAccuracy:?float, correctScores:int, exactScoreAccuracy:?float,
     *               byCategory:array, averageConfidence:?float, averageDataQuality:?float,
     *               brier:?float, ece:?float, skipped:int, skippedReasons:array<string,int>,
     *               caveat:string, model:array, generatedAt:string}
     */
    public function run(string $from, string $to, int $limit = 200): array
    {
        $from = $this->validDate($from);
        $to = $this->validDate($to);
        if ($to < $from) [$from, $to] = [$to, $from];
        $filter = ['from' => $from . 'T00:00:00+00:00', 'to' => $to . 'T23:59:59+00:00', 'status' => 'FINISHED'];
        $fixtures = $this->repo->listFixtures($filter, max(1, min(500, $limit)));
        $playable = array_values(array_filter($fixtures, static fn(array $f) => $f['home_score'] !== null && $f['away_score'] !== null));
        $model = $this->models->usable();
        if ($playable === []) {
            return ['state' => 'NO_DATA', 'from' => $from, 'to' => $to, 'evaluated' => 0, 'correctResults' => 0,
                'resultAccuracy' => null, 'correctScores' => 0, 'exactScoreAccuracy' => null, 'byCategory' => [],
                'averageConfidence' => null, 'averageDataQuality' => null, 'brier' => null, 'ece' => null,
                'skipped' => 0, 'skippedReasons' => [], 'caveat' => self::CAVEAT,
                'model' => ['state' => (string) $model['state'], 'version' => $model['model']['model_version'] ?? null],
                'message' => 'No finished fixture with a stored final score exists in ' . $from . '…' . $to . '. The backtest refuses to run on an empty window rather than invent one.',
                'generatedAt' => gmdate('c')];
        }
        $evaluated = 0; $correctResults = 0; $correctScores = 0;
        $byCategory = ['A' => ['evaluated' => 0, 'correct' => 0, 'scores' => 0], 'B' => ['evaluated' => 0, 'correct' => 0, 'scores' => 0], 'C' => ['evaluated' => 0, 'correct' => 0, 'scores' => 0]];
        $confidences = []; $hits = []; $briers = []; $qualities = []; $skipped = 0;
        $skippedReasons = [];
        foreach ($playable as $fixture) {
            $home = (int) $fixture['home_score'];
            $away = (int) $fixture['away_score'];
            $actual = $home > $away ? 'HOME' : ($home === $away ? 'DRAW' : 'AWAY');
            try {
                $features = $this->features->build($fixture);
                $payload = $this->predictor->predict($features, $model['state'] === 'NONE' ? null : $model['model'], false);
            } catch (\Throwable $e) {
                $skipped++;
                $skippedReasons['ERROR'] = ($skippedReasons['ERROR'] ?? 0) + 1;
                continue;
            }
            if (($payload['status'] ?? '') !== 'PREDICTED') {
                $skipped++;
                $code = (string) ($payload['code'] ?? 'NO_PREDICTION');
                $skippedReasons[$code] = ($skippedReasons[$code] ?? 0) + 1;
                continue;
            }
            $evaluated++;
            $predicted = strtoupper((string) ($payload['result'] ?? ''));
            $category = (string) ($payload['category']['key'] ?? '');
            if (isset($byCategory[$category])) {
                $byCategory[$category]['evaluated']++;
                if ($predicted === $actual) $byCategory[$category]['correct']++;
                if ((int) $payload['predictedScore']['home'] === $home && (int) $payload['predictedScore']['away'] === $away) {
                    $byCategory[$category]['scores']++;
                }
            }
            $correct = $predicted === $actual ? 1 : 0;
            $correctResults += $correct;
            $exact = (int) $payload['predictedScore']['home'] === $home && (int) $payload['predictedScore']['away'] === $away ? 1 : 0;
            $correctScores += $exact;
            $probabilities = $payload['probabilities'];
            $top = max($probabilities);
            $argmax = self::argmax($probabilities);
            $confidences[] = $top;
            $hits[] = $argmax === strtolower($actual) ? 1 : 0;
            $briers[] = self::brier($probabilities, $actual);
            $qualities[] = (int) ($payload['dataQuality']['score'] ?? 0);
        }
        $reliability = $confidences === [] ? null : \AIWorkforce\Football\CalibrationService::reliability($confidences, $hits);
        $avg = static fn(array $values): ?float => $values === [] ? null : round(array_sum($values) / count($values), 4);
        return [
            'state' => 'MEASURED',
            'from' => $from, 'to' => $to,
            'evaluated' => $evaluated,
            'correctResults' => $correctResults,
            'resultAccuracy' => $evaluated === 0 ? null : round($correctResults / $evaluated, 5),
            'correctScores' => $correctScores,
            'exactScoreAccuracy' => $evaluated === 0 ? null : round($correctScores / $evaluated, 5),
            'byCategory' => array_map(static fn(array $c) => [
                'evaluated' => $c['evaluated'], 'correct' => $c['correct'],
                'accuracy' => $c['evaluated'] === 0 ? null : round($c['correct'] / $c['evaluated'], 5),
                'correctScores' => $c['scores'],
                'exactScoreAccuracy' => $c['evaluated'] === 0 ? null : round($c['scores'] / $c['evaluated'], 5),
            ], $byCategory),
            'averageConfidence' => $confidences === [] ? null : round(100 * (array_sum($confidences) / count($confidences)), 2),
            'averageDataQuality' => $avg($qualities),
            'brier' => $briers === [] ? null : round(array_sum($briers) / count($briers), 6),
            'ece' => $reliability['ece'] ?? null,
            'mce' => $reliability['mce'] ?? null,
            'reliabilityBins' => $reliability['bins'] ?? [],
            'skipped' => $skipped,
            'skippedReasons' => $skippedReasons,
            'caveat' => self::CAVEAT,
            'model' => ['state' => (string) $model['state'], 'version' => $model['model']['model_version'] ?? null],
            'message' => null,
            'generatedAt' => gmdate('c'),
        ];
    }

    private static function argmax(array $values): string
    {
        $best = 'home'; $bestValue = -INF;
        foreach (['home', 'draw', 'away'] as $key) {
            if ((float) ($values[$key] ?? 0) > $bestValue) { $bestValue = (float) $values[$key]; $best = $key; }
        }
        return $best;
    }

    private static function brier(array $probabilities, string $actual): float
    {
        $oneHot = ['home' => 0.0, 'draw' => 0.0, 'away' => 0.0];
        $oneHot[strtolower($actual)] = 1.0;
        $sum = 0.0;
        foreach ($oneHot as $key => $target) {
            $p = (float) ($probabilities[$key] ?? 0);
            $sum += ($p - $target) ** 2;
        }
        return $sum;
    }

    private function validDate(string $date): string
    {
        $matches = [];
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches) && checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            return $date;
        }
        return gmdate('Y-m-d');
    }
}
