<?php
namespace AIWorkforce\Football;

use AIWorkforce\Persistence\AuditRepository;
use AIWorkforce\Persistence\FootballRepository;

/**
 * Live match view.
 *
 * Two separate things are shown and never conflated:
 *  - `preMatchPrediction`: the frozen row that was written before kickoff, with
 *    the score/probabilities as they stood then. This class reads it and does not
 *    touch it (prediction persistence for a started match is refused upstream);
 *  - `liveModelEstimate`: a freshly computed estimate from the current stored
 *    state (score, minute, red cards, in-match statistics when the provider
 *    supplies them), stored as its own `LIVE` prediction row so the two can be
 *    compared afterwards.
 *
 * When the provider reports no live detail, the response says DATA_UNAVAILABLE —
 * the pre-match prediction is not silently re-labelled as a live estimate.
 */
final class LiveMatchService
{
    public function __construct(
        private FootballRepository $repo,
        private FeatureBuilder $features,
        private OutcomePredictor $predictor,
        private ModelRegistry $models,
        private PredictionService $predictions,
        private ?FixtureSyncService $sync = null,
        private ?AuditRepository $audit = null,
    ) {}

    /**
     * The live board: every fixture the module currently believes is in play,
     * with its frozen pre-match prediction beside the live estimate.
     *
     * @return array{status:string, state:string, matches:list<array>, errors:list<string>, refreshed:?array}
     */
    public function board(bool $refresh = true): array
    {
        $errors = [];
        $refreshed = null;
        if ($refresh && $this->sync !== null) {
            try {
                $refreshed = $this->sync->syncLive('live:' . gmdate('Ymd\TH:i'));
                if (($refreshed['status'] ?? '') === 'DEFERRED' || ($refreshed['status'] ?? '') === 'FAILED') {
                    $errors = array_merge($errors, array_map(static fn($e) => 'live sync: ' . (string) $e, (array) ($refreshed['errors'] ?? [])));
                }
            } catch (\Throwable $e) {
                $errors[] = 'live sync: ' . mb_substr($e->getMessage(), 0, 160);
            }
        }
        $fixtures = $this->repo->listFixtures(['status' => 'LIVE'], 200);
        $matches = [];
        foreach ($fixtures as $fixture) {
            $matches[] = $this->matchView($fixture, $errors);
        }
        return [
            'status' => $matches === [] ? DataState::UNAVAILABLE : 'OK',
            'state' => $matches === [] ? 'NO_LIVE_FIXTURES' : 'LIVE',
            'matches' => $matches,
            'errors' => $errors,
            'refreshed' => $refreshed === null ? null : ['status' => $refreshed['status'] ?? null, 'processed' => $refreshed['processed'] ?? 0, 'requests' => $refreshed['requests'] ?? 0],
        ];
    }

    /**
     * One match, fully described. Public so `/matches/:id` can reuse it for a
     * fixture that is no longer live (then `live` reports what is missing).
     *
     * @return array<string,mixed>
     */
    public function matchView(array $fixture, array &$errors = []): array
    {
        $fixtureId = (int) $fixture['id'];
        $preMatch = $this->repo->listPredictions(['fixtureId' => $fixtureId, 'kind' => PredictionService::KIND_PRE_MATCH], 1)[0] ?? null;
        $liveRow = $this->repo->listPredictions(['fixtureId' => $fixtureId, 'kind' => PredictionService::KIND_LIVE], 1)[0] ?? null;
        $state = $this->matchState($fixture);
        $view = [
            'fixture' => PredictionService::fixtureSummary($fixture),
            'live' => $state,
            'preMatchPrediction' => $preMatch === null ? null : $this->predictions->contract($preMatch, $fixture),
            'preMatchPredictionState' => $preMatch === null ? 'NOT_STORED' : (string) ($preMatch['settlement_state'] ?? 'OPEN'),
            'liveModelEstimate' => null,
            'providers' => ['code' => (string) ($fixture['provider_code'] ?? 'DATA_UNAVAILABLE')],
        ];
        if ($liveRow !== null) $view['liveModelEstimate'] = $this->predictions->contract($liveRow, $fixture);
        if ($state['state'] === DataState::UNAVAILABLE) {
            $view['liveModelEstimate'] = ['state' => DataState::UNAVAILABLE, 'reason' => $state['reason']];
            return $view;
        }
        if ($state['state'] === 'PRE_MATCH' || $state['state'] === 'COMPLETED') {
            // Nothing to estimate: the match either has not started or is final,
            // and in both cases the frozen pre-match prediction above is the
            // authoritative record. Recomputing here would put a scoreless label
            // on numbers that were never conditioned on the current state.
            $view['liveModelEstimate'] = ['state' => $state['state'] === 'PRE_MATCH' ? 'MATCH_NOT_STARTED' : 'MATCH_COMPLETED',
                'reason' => $state['state'] === 'PRE_MATCH'
                    ? 'The match has not kicked off, so there is no live state to estimate from.'
                    : 'The match is final; settlement holds the comparison with the pre-match prediction.',
                'score' => $state['score']];
            return $view;
        }
        $estimate = $this->estimate($fixture);
        if (($estimate['status'] ?? '') === 'PREDICTED') {
            $view['liveModelEstimate'] = [
                'result' => $estimate['result'],
                'resultLabel' => $estimate['resultLabel'],
                'probabilities' => $estimate['probabilities'],
                'rawProbabilities' => $estimate['rawProbabilities'],
                'mostLikelyScore' => $estimate['predictedScore'],
                'alternativeScores' => $estimate['alternativeScores'],
                'expectedTotalGoals' => $estimate['expectedTotalGoals'],
                'confidence' => $estimate['confidence'],
                'confidenceBasis' => $estimate['confidenceBasis'],
                'calibrationState' => $estimate['calibrationState'],
                'dataQuality' => $estimate['dataQuality'],
                'reasoning' => $estimate['reasoning'],
                'generatedAt' => $estimate['generatedAt'],
                'state' => 'ESTIMATE',
            ];
            $this->store($fixture, $estimate, $liveRow);
        } else {
            $view['liveModelEstimate'] = ['state' => (string) ($estimate['code'] ?? 'NO_ESTIMATE'), 'reason' => (string) ($estimate['reason'] ?? 'the live estimate could not be computed from stored data')];
        }
        return $view;
    }

    /**
     * Live estimate for the current state of a match: the remaining-minute
     * residual of the score model, conditioned on the goals already scored. This
     * is deliberately a *state estimate*, not a re-prediction, and it is never
     * written over the pre-match row.
     */
    public function estimate(array $fixture): array
    {
        $model = $this->models->usable();
        $features = $this->features->build($fixture);
        $payload = $this->predictor->predict($features, $model['state'] === 'NONE' ? null : $model['model'], true);
        $payload['fixture'] = PredictionService::fixtureSummary($fixture);
        $payload['model']['state'] = (string) $model['state'];
        $payload['model']['label'] = (string) $model['label'];
        return $payload;
    }

    /**
     * @return array{state:string, reason:?string, minute:?int, score:array<string,mixed>, redCards:array<string,mixed>, elapsed:bool}
     */
    private function matchState(array $fixture): array
    {
        $status = strtoupper((string) ($fixture['status'] ?? ''));
        $minute = $fixture['minute'] ?? null;
        $hasScore = isset($fixture['home_score'], $fixture['away_score']) && $fixture['home_score'] !== null;
        if ($status === 'UNKNOWN') {
            return ['state' => DataState::UNAVAILABLE, 'reason' => 'The provider did not report a match status for this fixture.', 'minute' => null, 'score' => null, 'redCards' => null, 'elapsed' => false];
        }
        if ($status === 'FINISHED') {
            return ['state' => 'COMPLETED', 'reason' => null, 'minute' => is_numeric($minute) ? (int) $minute : 90,
                'score' => $hasScore ? ['home' => (int) $fixture['home_score'], 'away' => (int) $fixture['away_score']] : null,
                'redCards' => ['home' => $fixture['home_red_cards'] ?? null, 'away' => $fixture['away_red_cards'] ?? null], 'elapsed' => true];
        }
        if ($status !== 'LIVE') {
            return ['state' => 'PRE_MATCH', 'reason' => 'The match has not started (status ' . $status . ').', 'minute' => is_numeric($minute) ? (int) $minute : null,
                'score' => $hasScore ? ['home' => (int) $fixture['home_score'], 'away' => (int) $fixture['away_score']] : null,
                'redCards' => ['home' => $fixture['home_red_cards'] ?? null, 'away' => $fixture['away_red_cards'] ?? null], 'elapsed' => false];
        }
        if (!is_numeric($minute)) {
            return ['state' => DataState::LIMITED, 'reason' => 'The match is live but the provider reported no minute, so elapsed time is unavailable.',
                'minute' => null, 'score' => $hasScore ? ['home' => (int) $fixture['home_score'], 'away' => (int) $fixture['away_score']] : ['home' => DataState::UNAVAILABLE, 'away' => DataState::UNAVAILABLE],
                'redCards' => ['home' => $fixture['home_red_cards'] ?? null, 'away' => $fixture['away_red_cards'] ?? null], 'elapsed' => false];
        }
        return ['state' => 'IN_PLAY', 'reason' => null, 'minute' => (int) $minute,
            'score' => $hasScore ? ['home' => (int) $fixture['home_score'], 'away' => (int) $fixture['away_score']] : ['home' => DataState::UNAVAILABLE, 'away' => DataState::UNAVAILABLE],
            'redCards' => ['home' => $fixture['home_red_cards'] ?? null, 'away' => $fixture['away_red_cards'] ?? null],
            'elapsed' => (int) $minute >= 90];
    }

    /**
     * Persist a live estimate as its own row. It references the pre-match row it
     * superseded for display purposes only — the pre-match row is never modified,
     * and a settled pre-match row is immutable anyway.
     */
    private function store(array $fixture, array $estimate, ?array $existingLive): void
    {
        if (($fixture['match_state'] ?? '') === 'COMPLETED') return;   // settlement owns the final record
        $id = $this->predictions->predictionId($fixture, PredictionService::KIND_LIVE, (int) ($estimate['model']['modelVersionId'] ?? 0));
        if ($existingLive !== null && (string) ($existingLive['id'] ?? '') === $id) {
            // A live row is only rewritten while the match is still in play and
            // not yet settled; savePrediction refuses settled rows regardless.
            if ((string) ($existingLive['settlement_state'] ?? 'OPEN') !== 'OPEN') return;
        }
        $preMatch = $this->repo->listPredictions(['fixtureId' => (int) $fixture['id'], 'kind' => PredictionService::KIND_PRE_MATCH], 1)[0] ?? null;
        $this->repo->savePrediction([
            'id' => $id,
            'fixture_id' => (int) $fixture['id'],
            'provider_id' => (int) ($fixture['provider_id'] ?? 0),
            'model_version_id' => (int) ($estimate['model']['modelVersionId'] ?? 0) ?: null,
            'calibration_version_id' => $estimate['calibrationVersionId'] ?: null,
            'calibration_state' => (string) ($estimate['calibrationState'] ?? CalibrationService::PENDING),
            'prediction_kind' => PredictionService::KIND_LIVE,
            'supersedes_prediction_id' => $preMatch['id'] ?? null,
            'generated_at' => (string) ($estimate['generatedAt'] ?? gmdate('c')),
            'kickoff_at' => (string) ($fixture['kickoff_at'] ?? gmdate('c')),
            'status_at_prediction' => (string) ($fixture['status'] ?? 'LIVE'),
            'predicted_result' => (string) ($estimate['result'] ?? ''),
            'predicted_home_score' => (int) ($estimate['predictedScore']['home'] ?? 0),
            'predicted_away_score' => (int) ($estimate['predictedScore']['away'] ?? 0),
            'probability_home' => $estimate['probabilities']['home'] ?? null,
            'probability_draw' => $estimate['probabilities']['draw'] ?? null,
            'probability_away' => $estimate['probabilities']['away'] ?? null,
            'raw_home' => $estimate['rawProbabilities']['home'] ?? null,
            'raw_draw' => $estimate['rawProbabilities']['draw'] ?? null,
            'raw_away' => $estimate['rawProbabilities']['away'] ?? null,
            'expected_total_goals' => $estimate['expectedTotalGoals'] ?? null,
            'confidence' => $estimate['confidence'] ?? null,
            'confidence_basis' => (string) ($estimate['confidenceBasis'] ?? 'RAW'),
            'data_quality_score' => (int) ($estimate['dataQuality']['score'] ?? 0),
            'data_quality_band' => (string) ($estimate['dataQuality']['status'] ?? QualityBand::REJECTED),
            'quality_components' => json_encode($estimate['dataQuality']['components'] ?? []),
            'feature_snapshot' => json_encode(['liveMinute' => $fixture['minute'] ?? null, 'score' => ['home' => $fixture['home_score'] ?? null, 'away' => $fixture['away_score'] ?? null]]),
            'probabilities_matrix' => json_encode(['rows' => array_slice($estimate['matrix']['rows'] ?? [], 0, 10)]),
            'alternative_scores' => json_encode($estimate['alternativeScores'] ?? []),
            'reason' => mb_substr((string) ($estimate['reasoning'][0] ?? ''), 0, 600),
            'eligibility' => (string) ($estimate['dataQuality']['status'] ?? QualityBand::REJECTED),
            'settlement_state' => 'OPEN',
        ]);
        $this->audit?->emit('FOOTBALL_LIVE_ESTIMATE', 'Football live estimate updated for fixture ' . ($fixture['external_id'] ?? $fixture['id']), [
            'minute' => $fixture['minute'] ?? null, 'confidence' => $estimate['confidence'] ?? null, 'predictionId' => $id,
        ], 'system');
    }
}
