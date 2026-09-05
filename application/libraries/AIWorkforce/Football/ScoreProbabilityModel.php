<?php
namespace AIWorkforce\Football;

/**
 * Scoreline distribution: independent Poisson for each side, corrected for the
 * low-scoring dependence that makes 0–0 / 1–0 / 0–1 / 1–1 too frequent under an
 * independent model (Dixon–Coles tau), then renormalized so the grid sums to 1.
 *
 * Two rules make this a measurement rather than a guess:
 *  - every published probability is read back OUT of the normalized matrix (the
 *    outcome probabilities and the expected total are marginals of the same
 *    grid, so a card can never show 1–0 while the matrix says 2–1 is likelier);
 *  - `maxGoals` is finite and configurable; the residual tail mass beyond the
 *    grid is renormalized away and reported, never hidden.
 */
final class ScoreProbabilityModel
{
    public function __construct(private FootballConfiguration $config) {}

    /**
     * @return array{home:?float, away:?float, rows:list<array{homeGoals:int,awayGoals:int,probability:float,rank:int}>,
     *               outcomes:array{home:float,draw:float,away:float}, expectedTotalGoals:float,
     *               homeCleanSheet:float, awayCleanSheet:float, homeFailedToScore:float, awayFailedToScore:float,
     *               tailMass:float, rho:float, maxGoals:int, method:string, goalSource:string}
     */
    public function distribution(?float $lambdaHome, ?float $lambdaAway, string $goalSource = 'LEAGUE_BASELINE'): array
    {
        $max = $this->config->maxGoals();
        if ($lambdaHome === null || $lambdaAway === null) {
            return $this->empty($max, $goalSource);
        }
        $rho = $this->config->dixonColesRho();
        $rows = [];
        $homeMarginal = [];
        $awayMarginal = [];
        for ($h = 0; $h <= $max; $h++) $homeMarginal[$h] = self::poisson($lambdaHome, $h);
        for ($a = 0; $a <= $max; $a++) $awayMarginal[$a] = self::poisson($lambdaAway, $a);
        foreach ($homeMarginal as $h => $ph) {
            foreach ($awayMarginal as $a => $pa) {
                $rows[] = ['homeGoals' => $h, 'awayGoals' => $a, 'raw' => $ph * $pa * self::tau($h, $a, $lambdaHome, $lambdaAway, $rho)];
            }
        }
        // Share of the independent-Poisson mass that falls inside the 0…max grid.
        // Reported, not hidden: the grid is renormalized, so a reader can see how
        // much of the distribution was outside it.
        $gridCoverage = round(min(1.0, array_sum($homeMarginal) * array_sum($awayMarginal)), 6);
        $sum = array_sum(array_column($rows, 'raw'));
        if (!is_finite($sum) || $sum <= 0.0) return $this->empty($max, $goalSource);
        $outcomes = ['home' => 0.0, 'draw' => 0.0, 'away' => 0.0];
        $expectedTotal = 0.0;
        $homeCleanSheet = 0.0; $awayCleanSheet = 0.0;
        $homeFailedToScore = 0.0; $awayFailedToScore = 0.0;
        foreach ($rows as &$row) {
            $row['probability'] = round($row['raw'] / $sum, 6);
            $row['rank'] = 0;
            if ($row['homeGoals'] > $row['awayGoals']) $outcomes['home'] += $row['raw'];
            elseif ($row['homeGoals'] === $row['awayGoals']) $outcomes['draw'] += $row['raw'];
            else $outcomes['away'] += $row['raw'];
            $expectedTotal += ($row['homeGoals'] + $row['awayGoals']) * $row['raw'];
            if ($row['awayGoals'] === 0) $homeCleanSheet += $row['raw'];
            if ($row['homeGoals'] === 0) $awayCleanSheet += $row['raw'];
            if ($row['homeGoals'] === 0) $homeFailedToScore += $row['raw'];
            if ($row['awayGoals'] === 0) $awayFailedToScore += $row['raw'];
            unset($row['raw']);
        }
        unset($row);
        $expectedTotal /= $sum;
        $outcomes = [
            'home' => round($outcomes['home'] / $sum, 6),
            'draw' => round($outcomes['draw'] / $sum, 6),
            'away' => round($outcomes['away'] / $sum, 6),
        ];
        // Keep the three outcome probabilities summing to exactly 1 after rounding.
        $drift = 1.0 - array_sum($outcomes);
        if (abs($drift) > 1e-9) {
            $largest = $outcomes['home'] >= $outcomes['draw']
                ? ($outcomes['home'] >= $outcomes['away'] ? 'home' : 'away')
                : ($outcomes['draw'] >= $outcomes['away'] ? 'draw' : 'away');
            $outcomes[$largest] = round($outcomes[$largest] + $drift, 6);
        }
        usort($rows, static fn(array $a, array $b) => $b['probability'] <=> $a['probability']);
        $floor = $this->config->scoreRowMinProbability();
        $kept = [];
        foreach ($rows as $index => $row) {
            if ($row['probability'] < $floor && $kept !== []) continue;
            $row['rank'] = count($kept) + 1;
            $row['label'] = $row['homeGoals'] . '–' . $row['awayGoals'];
            $kept[] = $row;
            if (count($kept) >= 20) break;
        }
        $tailMass = max(0.0, round(1.0 - $gridCoverage, 6));
        return [
            'home' => round($lambdaHome, 4), 'away' => round($lambdaAway, 4),
            'rows' => $kept,
            'allRows' => count($rows),
            'outcomes' => $outcomes,
            'expectedTotalGoals' => round($expectedTotal, 3),
            'homeCleanSheet' => round($homeCleanSheet, 4),
            'awayCleanSheet' => round($awayCleanSheet, 4),
            'homeFailedToScore' => round($homeFailedToScore, 4),
            'awayFailedToScore' => round($awayFailedToScore, 4),
            'tailMass' => $tailMass,
            'gridCoverage' => $gridCoverage,
            'rho' => $rho,
            'maxGoals' => $max,
            // Which *score model* produced the grid, and where its goal rates
            // came from — two different provenances, both reported.
            'method' => $rho < 0.0 ? 'POISSON_DIXON_COLES' : 'POISSON',
            'goalSource' => $goalSource,
        ];
    }

    /**
     * Dixon–Coles low-score adjustment. τ is only applied to the four cells it
     * was derived for; the rest of the grid keeps the independent product.
     */
    private static function tau(int $home, int $away, float $lambdaHome, float $lambdaAway, float $rho): float
    {
        if ($rho == 0.0) return 1.0;
        return match (true) {
            $home === 0 && $away === 0 => 1.0 - $lambdaHome * $lambdaAway * $rho,
            $home === 1 && $away === 0 => 1.0 + $lambdaAway * $rho,
            $home === 0 && $away === 1 => 1.0 + $lambdaHome * $rho,
            $home === 1 && $away === 1 => 1.0 - $rho,
            default => 1.0,
        };
    }

    private static function poisson(float $lambda, int $k): float
    {
        $log = -$lambda + $k * log(max($lambda, 1e-9)) - self::logFactorial($k);
        // Underflow beyond the grid is not an error: those scorelines are
        // impossible for these rates, and the matrix is renormalized anyway.
        return $log < -745.0 ? 0.0 : exp($log);
    }

    private static function logFactorial(int $k): float
    {
        static $cache = [0.0, 0.0];
        if (!isset($cache[$k])) {
            for ($i = count($cache); $i <= $k; $i++) $cache[$i] = $cache[$i - 1] + log((float) $i);
        }
        return $cache[$k];
    }

    private function empty(int $max, string $goalSource): array
    {
        return [
            'home' => null, 'away' => null, 'rows' => [], 'allRows' => 0,
            'outcomes' => ['home' => 0.0, 'draw' => 0.0, 'away' => 0.0],
            'expectedTotalGoals' => 0.0, 'homeCleanSheet' => 0.0, 'awayCleanSheet' => 0.0,
            'homeFailedToScore' => 0.0, 'awayFailedToScore' => 0.0, 'tailMass' => 0.0,
            'rho' => 0.0, 'maxGoals' => $max, 'method' => 'NONE', 'goalSource' => $goalSource, 'state' => DataState::UNAVAILABLE,
        ];
    }
}
