<?php
namespace AIWorkforce\Football;

use AIWorkforce\Persistence\AuditRepository;
use AIWorkforce\Persistence\FootballRepository;

/**
 * Prediction orchestration: features → quality gate → score model → calibration
 * → stored, versioned prediction.
 *
 * Persistence rules that keep the record honest:
 *  - a pre-match prediction is written while the fixture is still SCHEDULED and
 *    its kickoff has not passed; once the match starts the original row is
 *    frozen (re-running the job returns the stored row and says so);
 *  - each row cites the model version and calibration version that produced it;
 *  - a REJECTED fixture stores no prediction at all — the reason rows are
 *    returned to the caller, never silently dropped.
 */
final class PredictionService
{
    public const KIND_PRE_MATCH = 'PRE_MATCH';
    public const KIND_LIVE = 'LIVE';

    public function __construct(
        private FootballRepository $repo,
        private FeatureBuilder $features,
        private OutcomePredictor $predictor,
        private ModelRegistry $models,
        private FootballConfiguration $config,
        private ?AuditRepository $audit = null,
    ) {}

    /**
     * Predict one stored fixture and (when allowed) store the result.
     *
     * @return array<string,mixed> the output contract plus `stored` metadata
     */
    public function predictFixture(int $fixtureId, bool $persist = true, string $kind = self::KIND_PRE_MATCH): array
    {
        $fixture = $this->repo->findFixtureById($fixtureId);
        if ($fixture === null) {
            return ['status' => 'NOT_FOUND', 'fixtureId' => $fixtureId, 'reason' => 'Fixture ' . $fixtureId . ' is not stored — nothing was fetched from the provider for it.'];
        }
        return $this->predict($fixture, $persist, $kind);
    }

    public function predict(array $fixture, bool $persist = true, string $kind = self::KIND_PRE_MATCH): array
    {
        // The closed-slot check comes first: whether kickoff has passed is the
        // categorical reason nothing may be recorded, and it must not be
        // reported as a data-quality verdict for a fixture that is simply too
        // late to predict.
        $frozen = $persist ? $this->frozenReason($fixture, $kind) : null;
        if ($frozen !== null) {
            $stored = $this->repo->listPredictions(['fixtureId' => (int) ($fixture['id'] ?? 0), 'kind' => $kind], 1)[0] ?? null;
            return [
                'status' => 'NO_PREDICTION',
                'code' => $frozen,
                'reason' => $stored !== null
                    ? 'The prediction for this fixture was made before kickoff and is never rewritten afterwards; the stored row remains the record.'
                    : 'Kickoff has passed, so no pre-match prediction can be created for this fixture any more. Nothing is back-filled.',
                'fixture' => self::fixtureSummary($fixture),
                'predictionFrozen' => true,
                'stored' => ['written' => false, 'reason' => $frozen, 'existingPredictionId' => $stored['id'] ?? null],
                'storedPrediction' => $stored === null ? null : $this->contract($stored, $fixture),
            ];
        }
        $model = $this->models->usable();
        $features = $this->features->build($fixture);
        $payload = $this->predictor->predict($features, $model['state'] === 'NONE' ? null : $model['model'], $kind === self::KIND_LIVE);
        $payload['fixture'] = self::fixtureSummary($fixture);
        $payload['model'] = array_merge((array) ($payload['model'] ?? []), [
            'state' => (string) $model['state'],
            'label' => (string) $model['label'],
            'registryReason' => $model['reason'],
        ]);
        if (($payload['status'] ?? '') !== 'PREDICTED') {
            $payload['stored'] = ['written' => false, 'reason' => 'NO_PREDICTION'];
            return $payload;
        }
        if (!$persist) {
            $payload['stored'] = ['written' => false, 'reason' => 'PERSIST_DISABLED'];
            return $payload;
        }
        $id = $this->predictionId($fixture, $kind, (int) ($payload['model']['modelVersionId'] ?? 0));
        $row = $this->repo->savePrediction([
            'id' => $id,
            'fixture_id' => (int) $fixture['id'],
            'provider_id' => (int) ($fixture['provider_id'] ?? 0),
            'model_version_id' => (int) ($payload['model']['modelVersionId'] ?? 0) ?: null,
            'calibration_version_id' => $payload['calibrationVersionId'] ?: null,
            'calibration_state' => (string) $payload['calibrationState'],
            'prediction_kind' => $kind,
            'generated_at' => (string) $payload['generatedAt'],
            'kickoff_at' => (string) ($fixture['kickoff_at'] ?? gmdate('c')),
            'status_at_prediction' => (string) ($fixture['status'] ?? 'SCHEDULED'),
            'predicted_result' => (string) $payload['result'],
            'predicted_home_score' => (int) $payload['predictedScore']['home'],
            'predicted_away_score' => (int) $payload['predictedScore']['away'],
            'probability_home' => $payload['probabilities']['home'],
            'probability_draw' => $payload['probabilities']['draw'],
            'probability_away' => $payload['probabilities']['away'],
            'raw_home' => $payload['rawProbabilities']['home'],
            'raw_draw' => $payload['rawProbabilities']['draw'],
            'raw_away' => $payload['rawProbabilities']['away'],
            'expected_total_goals' => $payload['expectedTotalGoals'],
            'confidence' => $payload['confidence'],
            'confidence_basis' => (string) $payload['confidenceBasis'],
            'data_quality_score' => (int) $payload['dataQuality']['score'],
            'data_quality_band' => (string) $payload['dataQuality']['status'],
            'quality_components' => json_encode($payload['dataQuality']['components'] ?? []),
            'feature_snapshot' => json_encode([
                'teams' => ['HOME' => self::compactTeam($features['teams']['HOME'] ?? []), 'AWAY' => self::compactTeam($features['teams']['AWAY'] ?? [])],
                'competition' => $features['competition'] ?? null,
                'headToHead' => $features['headToHead'] ?? null,
                'coverage' => $features['coverage'] ?? [],
                'provenance' => $features['provenance'] ?? [],
                'xgMethod' => $payload['xgMethod'] ?? null,
                'expectedGoals' => $payload['expectedGoals'] ?? null,
            ]),
            // Both provenances are kept: which score model produced the grid, and
            // where its expected-goals rates came from.
            'probabilities_matrix' => json_encode(['rows' => array_slice($payload['matrix']['rows'] ?? [], 0, 20), 'rho' => $payload['matrix']['rho'] ?? 0,
                'maxGoals' => $payload['matrix']['maxGoals'] ?? 0, 'gridCoverage' => $payload['matrix']['gridCoverage'] ?? null,
                'method' => (string) ($payload['matrix']['method'] ?? 'POISSON'), 'goalSource' => $payload['xgMethod'] ?? null]),
            'alternative_scores' => json_encode($payload['alternativeScores'] ?? []),
            'reason' => mb_substr((string) ($payload['reasoning'][0] ?? ''), 0, 600),
            'evidence' => json_encode($payload['evidence'] ?? []),
            'eligibility' => (string) $payload['dataQuality']['status'],
            'rejection_reasons' => json_encode($payload['dataQuality']['status'] === QualityBand::QUALIFIED ? [] : ['DATA_QUALITY_' . $payload['dataQuality']['status']]),
            'settlement_state' => 'OPEN',
        ]);
        $this->repo->saveScoreProbabilities($id, array_map(static fn(array $row) => [
            'home' => (int) $row['homeGoals'], 'away' => (int) $row['awayGoals'],
            'probability' => (float) $row['probability'], 'rank' => (int) $row['rank'],
            'isPrediction' => ((int) $row['homeGoals'] === (int) $payload['predictedScore']['home'] && (int) $row['awayGoals'] === (int) $payload['predictedScore']['away']),
        ], $payload['matrix']['rows'] ?? []));
        $payload['stored'] = ['written' => true, 'predictionId' => $id, 'predictionRowId' => $row['id'] ?? $id];
        $payload['contract'] = $this->contract(array_merge($row, ['id' => $id]), $fixture);
        $this->audit?->emit('FOOTBALL_PREDICTION_GENERATED', 'Football prediction ' . $payload['resultLabel'] . ' ' . ($payload['predictedScore']['home'] . '–' . $payload['predictedScore']['away']) . ' for ' . ($fixture['home_team'] ?? '') . ' v ' . ($fixture['away_team'] ?? ''), [
            'predictionId' => $id, 'confidence' => $payload['confidence'], 'confidenceBasis' => $payload['confidenceBasis'],
            'dataQuality' => $payload['dataQuality']['score'], 'band' => $payload['dataQuality']['status'],
            'modelVersionId' => $payload['model']['modelVersionId'] ?? null, 'calibrationVersion' => $payload['calibrationVersion'],
            'xgMethod' => $payload['xgMethod'],
        ], 'system');
        return $payload;
    }

    /**
     * The output contract (§20) — the shape every endpoint and the board share.
     * Values come from the stored row, so what a caller reads always matches
     * what was persisted and, later, what settlement was judged against.
     */
    public function contract(array $prediction, ?array $fixture = null): array
    {
        $fixture ??= $this->repo->findFixtureById((int) ($prediction['fixture_id'] ?? 0)) ?? [];
        $qualityComponents = is_array($prediction['quality_components'] ?? null) ? $prediction['quality_components'] : [];
        return [
            'predictionId' => (string) ($prediction['id'] ?? ''),
            'fixtureId' => (string) ($prediction['fixture_id'] ?? '') !== '' ? (string) ($fixture['external_id'] ?? $prediction['fixture_id']) : null,
            'fixtureDatabaseId' => (int) ($prediction['fixture_id'] ?? 0),
            'homeTeam' => (string) ($fixture['home_team'] ?? 'DATA_UNAVAILABLE'),
            'awayTeam' => (string) ($fixture['away_team'] ?? 'DATA_UNAVAILABLE'),
            'competition' => (string) ($fixture['competition'] ?? DataState::UNAVAILABLE),
            'country' => $fixture['country'] ?? null,
            'kickoff' => $fixture['kickoff_at'] ?? null,
            'status' => (string) ($fixture['status'] ?? 'UNKNOWN'),
            'matchState' => (string) ($fixture['match_state'] ?? 'PRE_MATCH'),
            'score' => (isset($fixture['home_score'], $fixture['away_score']))
                ? ['home' => (int) $fixture['home_score'], 'away' => (int) $fixture['away_score'], 'minute' => $fixture['minute'] ?? null] : null,
            'prediction' => [
                'result' => (string) ($prediction['predicted_result'] ?? ''),
                'predictedScore' => ['home' => $prediction['predicted_home_score'], 'away' => $prediction['predicted_away_score']],
                'probabilities' => ['home' => $prediction['probability_home'], 'draw' => $prediction['probability_draw'], 'away' => $prediction['probability_away']],
                'confidence' => $prediction['confidence'] ?? null,
                'confidenceBasis' => (string) ($prediction['confidence_basis'] ?? 'RAW'),
                'expectedTotalGoals' => $prediction['expected_total_goals'] ?? null,
                'calibrationState' => (string) ($prediction['calibration_state'] ?? CalibrationService::PENDING),
            ],
            // Both probability sets are published side by side on purpose (§9):
            // the model's own shares and the calibrated value actually displayed.
            'rawProbabilities' => ['home' => $prediction['raw_home'] ?? null, 'draw' => $prediction['raw_draw'] ?? null, 'away' => $prediction['raw_away'] ?? null],
            'dataQuality' => ['score' => (int) ($prediction['data_quality_score'] ?? 0), 'status' => (string) ($prediction['data_quality_band'] ?? QualityBand::REJECTED), 'components' => $qualityComponents],
            'model' => [
                'version' => (string) ($prediction['model_version'] ?? $this->modelVersionLabel((int) ($prediction['model_version_id'] ?? 0))),
                'modelVersionId' => $prediction['model_version_id'] ?? null,
                'calibrationVersion' => (string) ($prediction['calibration_version'] ?? '') !== '' ? (string) $prediction['calibration_version'] : null,
                'calibrationVersionId' => $prediction['calibration_version_id'] ?? null,
            ],
            'reason' => (string) ($prediction['reason'] ?? ''),
            'alternativeScores' => is_array($prediction['alternative_scores'] ?? null) ? $prediction['alternative_scores'] : json_decode((string) ($prediction['alternative_scores'] ?? '[]'), true),
            'settlementState' => (string) ($prediction['settlement_state'] ?? 'OPEN'),
            'generatedAt' => (string) ($prediction['generated_at'] ?? ''),
        ];
    }

    /**
     * Predict every stored fixture for a date. Returns per-fixture outcomes plus
     * the board counts, and never inflates a fixture into a higher band.
     *
     * @return array{status:string, date:string, fixtures:int, analyzed:int, qualified:int, limited:int, rejected:int, predictions:list<array>, errors:list<string>, provider:string|null, model:array}
     */
    public function predictDay(string $date, ?string $providerId = null): array
    {
        $filter = ['date' => $date];
        if ($providerId !== null) $filter['providerId'] = (int) $providerId;
        $fixtures = $this->repo->listFixtures($filter, max(1, $this->config->analysisLimit()));
        $out = ['status' => 'COMPLETED', 'date' => $date, 'fixtures' => count($fixtures), 'analyzed' => 0, 'qualified' => 0, 'limited' => 0, 'rejected' => 0,
            // Fixtures whose pre-match slot had already closed are tallied apart,
            // so "we did not predict it in time" is never mistaken for "the data
            // was too thin to predict".
            'frozen' => 0, 'predictions' => [], 'errors' => []];
        $model = $this->models->usable();
        $out['model'] = ['state' => $model['state'], 'label' => $model['label'], 'version' => $model['model']['model_version'] ?? null, 'reason' => $model['reason']];
        if ($fixtures === []) {
            $out['status'] = DataState::UNAVAILABLE;
            $out['reason'] = 'No fixture for ' . $date . ' is stored. The provider has not been reached for this date, so no prediction is produced.';
            return $out;
        }
        foreach ($fixtures as $fixture) {
            $kind = self::KIND_PRE_MATCH;
            try {
                $payload = $this->predict($fixture, true, $kind);
                if (!empty($payload['predictionFrozen'])) {
                    $out['frozen']++;
                    $out['predictions'][] = ['fixtureId' => $fixture['id'], 'externalId' => $fixture['external_id'] ?? null,
                        'status' => 'NO_PREDICTION', 'code' => (string) ($payload['code'] ?? 'KICKOFF_PASSED'), 'band' => null];
                    continue;
                }
                $out['analyzed']++;
                $band = (string) ($payload['dataQuality']['status'] ?? QualityBand::REJECTED);
                if ($band === QualityBand::QUALIFIED) $out['qualified']++;
                elseif ($band === QualityBand::LIMITED) $out['limited']++;
                else $out['rejected']++;
                $out['predictions'][] = ['fixtureId' => $fixture['id'], 'externalId' => $fixture['external_id'] ?? null,
                    'status' => (string) ($payload['status'] ?? 'UNKNOWN'), 'code' => $payload['code'] ?? null,
                    'band' => $band, 'score' => (int) ($payload['dataQuality']['score'] ?? 0),
                    'predictionId' => $payload['stored']['predictionId'] ?? ($payload['stored']['existingPredictionId'] ?? null),
                    'result' => $payload['result'] ?? null, 'confidence' => $payload['confidence'] ?? null];
            } catch (\Throwable $e) {
                $out['errors'][] = 'fixture ' . ($fixture['external_id'] ?? $fixture['id']) . ': ' . mb_substr($e->getMessage(), 0, 200);
            }
        }
        return $out;
    }

    /** Predictions currently stored for a date (the board's read path). */
    public function storedForDate(string $date): array
    {
        $rows = $this->repo->listPredictions(['date' => $date, 'kind' => self::KIND_PRE_MATCH], max(1, $this->config->analysisLimit()));
        $out = [];
        foreach ($rows as $row) {
            $fixture = $this->repo->findFixtureById((int) $row['fixture_id']);
            $out[] = $this->contract(array_merge($row, ['fixture' => $fixture]), $fixture ?? []);
            $out[count($out) - 1]['predictionRow'] = $row;
        }
        return $out;
    }

    /** A fixture whose kickoff has passed must keep its pre-match record. */
    private function frozenReason(array $fixture, string $kind): ?string
    {
        $status = strtoupper((string) ($fixture['status'] ?? ''));
        $kickoff = (string) ($fixture['kickoff_at'] ?? '');
        $started = in_array($status, ['LIVE', 'FINISHED', 'SUSPENDED'], true);
        if (!$started && $kickoff !== '') {
            try { $started = (new \DateTimeImmutable($kickoff))->getTimestamp() <= time(); } catch (\Throwable $e) { $started = false; }
        }
        if ($kind === self::KIND_PRE_MATCH && $started) return 'KICKOFF_PASSED';
        if (in_array($status, ['POSTPONED', 'CANCELLED'], true)) return 'FIXTURE_' . $status;
        if ($status === 'UNKNOWN') return 'FIXTURE_STATUS_' . DataState::UNAVAILABLE;
        return null;
    }

    /** Deterministic id: re-running the same day cannot create duplicate rows. */
    public function predictionId(array $fixture, string $kind, int $modelVersionId): string
    {
        return 'fpx-' . substr(hash('sha256', (string) ($fixture['id'] ?? $fixture['external_id'] ?? '') . '|' . $kind . '|' . $modelVersionId), 0, 24);
    }

    private function modelVersionLabel(int $modelVersionId): string
    {
        if ($modelVersionId <= 0) return DataState::UNAVAILABLE;
        $row = $this->repo->findModelVersion($modelVersionId);
        return $row === null ? DataState::UNAVAILABLE : (string) ($row['model_version'] ?? DataState::UNAVAILABLE);
    }

    private static function compactTeam(array $team): array
    {
        return array_intersect_key($team, array_flip(['externalId', 'name', 'venue', 'played', 'wins', 'draws', 'losses', 'goalsFor', 'goalsAgainst',
            'points', 'position', 'attackStrength', 'defenseWeakness', 'attackSource', 'defenseSource', 'cleanSheetRate', 'failedToScoreRate',
            'expectedGoalsTendency', 'recentMatchCoverage', 'statCoverage', 'dataState']));
    }

    public static function fixtureSummary(array $fixture): array
    {
        return [
            'id' => (int) ($fixture['id'] ?? 0),
            'externalId' => (string) ($fixture['external_id'] ?? ''),
            'competition' => (string) ($fixture['competition'] ?? DataState::UNAVAILABLE),
            'country' => $fixture['country'] ?? null,
            'season' => $fixture['season'] ?? null,
            'kickoff' => $fixture['kickoff_at'] ?? null,
            'status' => (string) ($fixture['status'] ?? 'UNKNOWN'),
            'matchState' => (string) ($fixture['match_state'] ?? 'PRE_MATCH'),
            'minute' => $fixture['minute'] ?? null,
            'homeTeam' => (string) ($fixture['home_team'] ?? DataState::UNAVAILABLE),
            'awayTeam' => (string) ($fixture['away_team'] ?? DataState::UNAVAILABLE),
            'score' => ['home' => $fixture['home_score'] ?? null, 'away' => $fixture['away_score'] ?? null],
            'redCards' => ['home' => $fixture['home_red_cards'] ?? null, 'away' => $fixture['away_red_cards'] ?? null],
            'venue' => $fixture['venue'] ?? null,
            'dataState' => (string) ($fixture['data_state'] ?? DataState::UNAVAILABLE),
        ];
    }
}
