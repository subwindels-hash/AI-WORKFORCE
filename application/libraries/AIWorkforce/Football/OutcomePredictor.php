<?php
namespace AIWorkforce\Football;

/**
 * Turns a probability distribution into a published prediction.
 *
 * Rules encoded here, in order of how much they hurt to get wrong:
 *  1. REJECTED data quality (score < 50) yields NO prediction — with the list of
 *     which data families were missing, so the absence is actionable;
 *  2. displayed confidence is the calibrated probability, never the raw one, and
 *     it is labelled `RAW` plus capped by a quality ceiling when calibration is
 *     still pending;
 *  3. the highest-probability scoreline is read back out of the score matrix, so
 *     `predictedScore` and the result probabilities can never disagree;
 *  4. certainty is unreachable: the maximum displayable value is 99 %.
 */
final class OutcomePredictor
{
    public const MAX_DISPLAY_CONFIDENCE = 99.0;
    public const OUTCOMES = ['HOME' => 'home', 'DRAW' => 'draw', 'AWAY' => 'away'];

    public function __construct(
        private ExpectedGoalsResolver $expectedGoals,
        private ScoreProbabilityModel $scores,
        private CalibrationService $calibration,
        private FootballConfiguration $config,
    ) {}

    /**
     * @param array{teams:array,competition:?array,headToHead:array,dataQuality:array,coverage:array,provenance:array,fixture:array} $features
     * @param array<string,mixed>|null $model usable() entry from ModelRegistry
     * @return array<string,mixed> prediction payload (also the stored row source)
     */
    public function predict(array $features, ?array $model, bool $liveContext = false): array
    {
        $quality = $features['dataQuality'] ?? ['score' => 0, 'status' => QualityBand::REJECTED, 'components' => [], 'reasons' => [], 'reasonsAbsent' => []];
        $band = (string) ($quality['status'] ?? QualityBand::REJECTED);
        $score = (int) ($quality['score'] ?? 0);
        if ($band === QualityBand::REJECTED) {
            return [
                'status' => 'NO_PREDICTION',
                'code' => 'DATA_QUALITY_BELOW_THRESHOLD',
                'dataQuality' => ['score' => $score, 'status' => $band, 'band' => $band, 'components' => $quality['components'] ?? []],
                'reasoning' => $this->missingDataReasons($quality, $features),
                'reason' => 'Data quality ' . $score . '/100 is below the 50-point minimum for a published prediction.',
                'model' => self::modelBlock($model),
                'generatedAt' => gmdate('c'),
            ];
        }

        $xg = $this->expectedGoals->resolve($features['teams'] ?? [], $features['competition'] ?? null);
        if ($xg['home'] === null || $xg['away'] === null) {
            return [
                'status' => 'NO_PREDICTION',
                'code' => 'EXPECTED_GOALS_UNAVAILABLE',
                'xgMethod' => $xg['method'],
                'dataQuality' => ['score' => $score, 'status' => $band, 'band' => $band, 'components' => $quality['components'] ?? []],
                'reasoning' => array_merge($this->missingDataReasons($quality, $features), $xg['notes']),
                'reason' => 'No expected-goals rate could be computed from stored team statistics; nothing is imputed.',
                'model' => self::modelBlock($model),
                'generatedAt' => gmdate('c'),
            ];
        }
        $distribution = $this->scores->distribution($xg['home'], $xg['away'], $xg['method']);
        if (($distribution['rows'] ?? []) === []) {
            return [
                'status' => 'NO_PREDICTION',
                'code' => 'SCORE_DISTRIBUTION_DEGENERATE',
                'dataQuality' => ['score' => $score, 'status' => $band, 'band' => $band, 'components' => $quality['components'] ?? []],
                'reason' => 'The scoreline matrix produced no probability mass for these rates.',
                'reasoning' => $this->missingDataReasons($quality, $features),
                'model' => self::modelBlock($model),
                'generatedAt' => gmdate('c'),
            ];
        }
        $calibrated = $this->calibration->apply($model, $distribution['outcomes']);
        $argmax = self::argmax($calibrated['probabilities']);
        $rawArgmax = self::argmax($calibrated['raw']);
        $topScore = $distribution['rows'][0];
        $predictedScore = ['home' => (int) $topScore['homeGoals'], 'away' => (int) $topScore['awayGoals']];
        // Consistency guard: the published result must agree with the published
        // scoreline. If the modal scoreline contradicts the modal outcome, the
        // outcome wins and the next scoreline matching it is used — a card that
        // reads "Draw 34%" above "2–0" would otherwise be self-refuting.
        $scoreOutcome = $predictedScore['home'] > $predictedScore['away'] ? 'home' : ($predictedScore['home'] === $predictedScore['away'] ? 'draw' : 'away');
        if ($scoreOutcome !== $argmax) {
            foreach ($distribution['rows'] as $row) {
                $rowOutcome = $row['homeGoals'] > $row['awayGoals'] ? 'home' : ($row['homeGoals'] === $row['awayGoals'] ? 'draw' : 'away');
                if ($rowOutcome === $argmax) {
                    $predictedScore = ['home' => (int) $row['homeGoals'], 'away' => (int) $row['awayGoals']];
                    $topScore = $row;
                    break;
                }
            }
        }

        $ceiling = $this->confidenceCeiling($score, $calibrated['basis'], $band);
        $rawConfidence = 100.0 * max($calibrated['probabilities']);
        $confidence = min($rawConfidence, $ceiling);
        $confidence = round(min(self::MAX_DISPLAY_CONFIDENCE, $confidence), 1);
        $tiers = $this->tiers();
        $highConfidenceAllowed = $band === QualityBand::QUALIFIED
            && $calibrated['basis'] === 'CALIBRATED'
            && $confidence >= $tiers['highest'];

        return [
            'status' => 'PREDICTED',
            'fixtureId' => $features['fixture']['external_id'] ?? null,
            'fixtureDatabaseId' => (int) ($features['fixture']['id'] ?? 0),
            'result' => strtoupper($argmax),
            'resultLabel' => self::label(strtoupper($argmax), $features['teams'] ?? []),
            'predictedScore' => $predictedScore,
            'predictedScoreProbability' => $topScore['probability'],
            'alternativeScores' => array_values(array_filter(
                array_map(static fn(array $row) => ['score' => $row['homeGoals'] . '–' . $row['awayGoals'],
                    'home' => (int) $row['homeGoals'], 'away' => (int) $row['awayGoals'],
                    'probability' => $row['probability'], 'rank' => (int) $row['rank']],
                    array_slice($distribution['rows'], 1, 4)),
                static fn(array $row) => !($row['home'] === $predictedScore['home'] && $row['away'] === $predictedScore['away']),
            )),
            'probabilities' => [
                'home' => $calibrated['probabilities']['home'],
                'draw' => $calibrated['probabilities']['draw'],
                'away' => $calibrated['probabilities']['away'],
            ],
            'rawProbabilities' => ['home' => $distribution['outcomes']['home'], 'draw' => $distribution['outcomes']['draw'], 'away' => $distribution['outcomes']['away']],
            'expectedTotalGoals' => $distribution['expectedTotalGoals'],
            'expectedGoals' => ['home' => $distribution['home'], 'away' => $distribution['away'], 'method' => $xg['method'], 'source' => $calibrated['source'] ?? $xg['method']],
            'xgMethod' => $xg['method'],
            'teamTotals' => [
                'homeCleanSheet' => $distribution['homeCleanSheet'], 'awayCleanSheet' => $distribution['awayCleanSheet'],
                'homeFailedToScore' => $distribution['homeFailedToScore'], 'awayFailedToScore' => $distribution['awayFailedToScore'],
            ],
            'confidence' => $confidence,
            'confidenceBasis' => $calibrated['basis'],
            'confidenceCeiling' => round($ceiling, 1),
            'calibrationState' => $calibrated['state'],
            'calibrationVersion' => $calibrated['calibrationVersion'],
            'calibrationVersionId' => $calibrated['calibrationVersionId'],
            'temperature' => $calibrated['temperature'],
            'rawConfidence' => round($rawConfidence, 1),
            'tier' => self::tier($confidence, $band, $tiers),
            'tierThresholds' => $tiers,
            'highConfidenceLabel' => $highConfidenceAllowed ? 'HIGH_CONFIDENCE' : null,
            'dataQuality' => ['score' => $score, 'status' => $band, 'band' => $band, 'components' => $quality['components'] ?? []],
            'model' => self::modelBlock($model),
            'matrix' => ['rows' => $distribution['rows'], 'rho' => $distribution['rho'], 'maxGoals' => $distribution['maxGoals'], 'gridCoverage' => $distribution['gridCoverage'] ?? 1.0, 'method' => $distribution['method']],
            'features' => ['teams' => $features['teams'], 'competition' => $features['competition'], 'headToHead' => $features['headToHead'], 'inMatch' => $features['inMatch'] ?? null],
            'coverage' => $features['coverage'] ?? [],
            'provenance' => $features['provenance'] ?? [],
            'reasoning' => $this->reasoning($features, $xg, $distribution, $argmax, $calibrated, $scoreOutcome, $liveContext),
            'evidence' => $this->evidence($features),
            'liveContext' => $liveContext,
            'generatedAt' => gmdate('c'),
        ];
    }

    /**
     * Uncalibrated confidence must not exceed what the data quality can support.
     * The ceiling is linear in the quality score (50 % at the reject boundary,
     * 95 % at a perfect score) and only ever applies to RAW confidence — a
     * calibrated figure is already a measured frequency, so capping it again
     * would double-penalise the model.
     */
    public function confidenceCeiling(int $dataQuality, string $basis, string $band): float
    {
        if ($basis === 'CALIBRATED') return self::MAX_DISPLAY_CONFIDENCE;
        $ratio = max(0.0, min(1.0, $dataQuality / 100.0));
        $ceiling = 50.0 + 45.0 * $ratio;
        if ($band === QualityBand::LIMITED) $ceiling = min($ceiling, 69.0);
        return round($ceiling, 1);
    }

    /** Tier cut lines, read from configuration so the board and the model agree. */
    private function tiers(): array
    {
        $thresholds = ['highest' => 80.0, 'strong' => 75.0, 'standard' => (float) QualityBand::QUALIFIED_MIN];
        foreach ($this->config->confidenceTiers() as $tier) {
            $key = (string) ($tier['key'] ?? '');
            if ($key !== '' && isset($tier['min']) && is_numeric($tier['min'])) $thresholds[$key] = (float) $tier['min'];
        }
        return $thresholds;
    }

    private static function tier(float $confidence, string $band, array $tiers): string
    {
        if ($band !== QualityBand::QUALIFIED) return 'LIMITED_DATA';
        if ($confidence >= $tiers['highest']) return 'HIGHEST_CONFIDENCE';
        if ($confidence >= $tiers['strong']) return 'STRONG';
        if ($confidence >= $tiers['standard']) return 'STANDARD';
        return 'LIMITED_DATA';
    }

    /**
     * Concise, evidence-based explanation: only facts that were measured, and an
     * explicit clause for anything that was missing.
     *
     * @return list<string>
     */
    private function reasoning(array $features, array $xg, array $distribution, string $argmax, array $calibrated, string $scoreOutcome, bool $liveContext): array
    {
        $reasons = [];
        $teams = $features['teams'] ?? [];
        $home = $teams['HOME'] ?? [];
        $away = $teams['AWAY'] ?? [];
        $format = static fn(?float $value): string => $value === null ? DataState::UNAVAILABLE : number_format($value, 2);
        $side = strtoupper($argmax) === 'HOME' ? ($home['name'] ?? 'HOME') : (strtoupper($argmax) === 'AWAY' ? ($away['name'] ?? 'AWAY') : 'THE DRAW');
        $reasons[] = sprintf(
            '%s is the most likely outcome at %s%% (model share of the scoreline grid, %s).',
            $side,
            number_format(100 * max($calibrated['probabilities']), 1),
            $calibrated['basis'] === 'CALIBRATED' ? 'calibrated at T=' . number_format((float) $calibrated['temperature'], 3) : 'raw, calibration pending'
        );
        if ($xg['home'] !== null && $xg['away'] !== null) {
            $reasons[] = sprintf('Expected goals %s–%s from %s (total %.2f).', number_format((float) $distribution['home'], 2), number_format((float) $distribution['away'], 2), self::methodLabel($xg['method']), (float) $distribution['expectedTotalGoals']);
        }
        foreach (['HOME' => $home, 'AWAY' => $away] as $venue => $team) {
            $form = $team['form']['last5'] ?? null;
            if (is_array($form) && (int) $form['played'] > 0) {
                $reasons[] = sprintf('%s recent form %s (%dW %dD %dL, %d goals scored, %d conceded over %d matches).', $team['name'] ?? $venue, (string) $form['string'], (int) $form['wins'], (int) $form['draws'], (int) $form['losses'], (int) $form['goalsFor'], (int) $form['goalsAgainst'], (int) $form['played']);
            } else {
                $reasons[] = sprintf('%s recent form: %s.', $team['name'] ?? $venue, DataState::UNAVAILABLE);
            }
            $reasons[] = sprintf('%s %s: %s per match, concession %s per match (source: %s).', $team['name'] ?? $venue, $venue === 'HOME' ? 'home attack' : 'away attack', $format($team['attackStrength'] ?? null), $format($team['defenseWeakness'] ?? null), (string) ($team['attackSource'] ?? DataState::UNAVAILABLE));
        }
        $h2h = $features['headToHead'] ?? [];
        if ((int) ($h2h['meetings'] ?? 0) > 0) {
            $reasons[] = sprintf('Head to head (%d meetings, weight %d%% of the model input): %d home wins, %d draws, %d away wins.', (int) $h2h['meetings'], (int) round(100 * (float) $h2h['weight']), (int) $h2h['homeWins'], (int) $h2h['draws'], (int) $h2h['awayWins']);
        } else {
            $reasons[] = 'Head to head: ' . DataState::UNAVAILABLE . ' — excluded from the estimate.';
        }
        $competition = $features['competition'] ?? null;
        $aggregate = is_array($competition['aggregate'] ?? null) ? $competition['aggregate'] : null;
        if ($aggregate !== null) {
            $reasons[] = sprintf('League scoring environment: %.2f home goals and %.2f away goals per match over %d stored teams.', (float) ($aggregate['avgHomeGoals'] ?? 0), (float) ($aggregate['avgAwayGoals'] ?? 0), (int) ($aggregate['teams'] ?? 0));
        } else {
            $reasons[] = 'League statistics: ' . DataState::UNAVAILABLE . ' — team-baseline rates used instead of a league-adjusted pairing.';
        }
        $quality = $features['dataQuality'] ?? [];
        $missing = $quality['reasonsAbsent'] ?? [];
        if ($missing !== []) {
            $reasons[] = 'Missing inputs: ' . implode(', ', array_map(static fn($item) => strtolower((string) $item), $missing)) . '.';
        }
        if ($liveContext) {
            $reasons[] = 'Live estimate from the current stored state; the pre-match prediction is kept unchanged.';
        }
        return $reasons;
    }

    /** @return list<array<string,mixed>> machine-readable evidence rows */
    private function evidence(array $features): array
    {
        $rows = [];
        foreach (['HOME', 'AWAY'] as $venue) {
            $team = $features['teams'][$venue] ?? [];
            $form = $team['form']['last5'] ?? null;
            $rows[] = [
                'kind' => 'TEAM_FORM', 'side' => $venue, 'team' => $team['name'] ?? null,
                'state' => $form ? DataState::AVAILABLE : DataState::UNAVAILABLE,
                'string' => $form['string'] ?? null, 'played' => $form['played'] ?? null,
                'points' => $form['points'] ?? null, 'goalsFor' => $form['goalsFor'] ?? null, 'goalsAgainst' => $form['goalsAgainst'] ?? null,
                'attack' => $team['attackStrength'] ?? null, 'defense' => $team['defenseWeakness'] ?? null,
                'attackSource' => $team['attackSource'] ?? null, 'defenseSource' => $team['defenseSource'] ?? null,
                'cleanSheetRate' => $team['cleanSheetRate'] ?? null, 'failedToScoreRate' => $team['failedToScoreRate'] ?? null,
            ];
        }
        $h2h = $features['headToHead'] ?? [];
        $rows[] = ['kind' => 'HEAD_TO_HEAD', 'state' => (int) ($h2h['meetings'] ?? 0) > 0 ? DataState::AVAILABLE : DataState::UNAVAILABLE,
            'meetings' => $h2h['meetings'] ?? 0, 'weight' => $h2h['weight'] ?? 0.0, 'summary' => $h2h['summary'] ?? DataState::UNAVAILABLE];
        $competition = $features['competition'] ?? null;
        $rows[] = ['kind' => 'LEAGUE_STATISTICS', 'state' => !empty($competition['hasStats']) ? DataState::AVAILABLE : DataState::UNAVAILABLE,
            'aggregate' => $competition['aggregate'] ?? null];
        return $rows;
    }

    /**
     * Model identity is attached to every payload, including the ones that
     * refuse to predict, so a reader can always tell which version was asked.
     */
    private static function modelBlock(?array $model): array
    {
        if ($model === null) {
            return ['modelId' => '', 'modelName' => ModelRegistry::MODEL_NAME, 'modelVersion' => 'MODEL_NOT_LOADED',
                'modelVersionId' => 0, 'status' => ModelRegistry::DRAFT, 'trainingDatasetVersion' => null, 'featureVersion' => null];
        }
        return [
            'modelId' => (string) ($model['model_id'] ?? ''),
            'modelName' => (string) ($model['model_name'] ?? ModelRegistry::MODEL_NAME),
            'modelVersion' => (string) ($model['model_version'] ?? 'MODEL_NOT_LOADED'),
            'modelVersionId' => (int) ($model['id'] ?? 0),
            'status' => (string) ($model['status'] ?? ModelRegistry::DRAFT),
            'trainingDatasetVersion' => $model['training_dataset_version'] ?? null,
            'featureVersion' => $model['feature_version'] ?? null,
            'calibrationVersionId' => $model['calibration_version_id'] ?? null,
            'validatedAt' => $model['validated_at'] ?? null,
            'approvedAt' => $model['approved_at'] ?? null,
            'approvedBy' => $model['approved_by'] ?? null,
            'activatedAt' => $model['activated_at'] ?? null,
            'lastEvaluatedAt' => $model['last_evaluated_at'] ?? null,
            'accuracy' => $model['accuracy'] ?? null,
            'logLoss' => $model['log_loss'] ?? null,
            'brierScore' => $model['brier_score'] ?? null,
            'ece' => $model['ece'] ?? null,
            'validationSampleSize' => $model['validation_sample_size'] ?? null,
        ];
    }

    /** @return list<string> */
    private function missingDataReasons(array $quality, array $features): array
    {
        $lines = array_values(array_map(static fn($item) => (string) $item . ': unavailable', $quality['reasonsAbsent'] ?? []));
        foreach (['HOME', 'AWAY'] as $venue) {
            $team = $features['teams'][$venue] ?? [];
            if (($team['dataState'] ?? DataState::UNAVAILABLE) !== DataState::AVAILABLE) {
                $lines[] = 'Team statistics (' . ($team['name'] ?? $venue) . '): ' . (string) ($team['dataState'] ?? DataState::UNAVAILABLE);
            }
        }
        return $lines === [] ? ['Stored data is too incomplete to reach the 50-point quality minimum.'] : $lines;
    }

    private static function methodLabel(string $method): string
    {
        return match ($method) {
            ExpectedGoalsResolver::METHOD_LEAGUE_BASELINE => 'venue-adjusted rates over the league scoring baseline',
            ExpectedGoalsResolver::METHOD_TEAM_BASELINE => 'each team\'s own venue rates (no league baseline available)',
            default => 'no rate available',
        };
    }

    private static function label(string $result, array $teams): string
    {
        return match ($result) {
            'HOME' => (string) ($teams['HOME']['name'] ?? 'Home') . ' Win',
            'AWAY' => (string) ($teams['AWAY']['name'] ?? 'Away') . ' Win',
            default => 'Draw',
        };
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
