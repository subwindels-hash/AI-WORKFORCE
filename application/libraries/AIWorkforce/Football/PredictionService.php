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
    /**
     * A durable record of an engine attempt that could not publish a pre-match
     * prediction. This uses the generic fixture-statistics store so a rejected
     * assessment survives the redirect after "Generate this page" without
     * pretending that a prediction row exists.
     */
    public const ASSESSMENT_KIND = 'PREDICTION_ASSESSMENT';

    /** How a match was treated by a (bounded) generation request. */
    public const MISSING_GENERATED = 'GENERATED';   // a new prediction was written
    public const MISSING_STORED = 'STORED';         // one already existed → reused
    public const MISSING_REFUSED = 'REFUSED';       // the engine answered without storing: data too thin
    public const MISSING_DEFERRED = 'DEFERRED';     // the batch was full; try again
    public const MISSING_FROZEN = 'FROZEN';         // kickoff passed, or the fixture is void
    public const MISSING_FAILED = 'FAILED';         // the engine threw

    /**
     * Predictions already read during this request, keyed
     * `fixtureId|kind|modelVersionId`.
     *
     * Paging through a date asks "does this match have a prediction?" fifty
     * times per page. The answer cannot change inside a request, so it is read
     * once and remembered — including the misses, which is what stops a page
     * from re-asking for a match it already learned is missing.
     *
     * @var array<string,array<string,mixed>|null>
     */
    private array $resolved = [];
    private ?RegenerationPolicy $regeneration = null;
    /**
     * Why the calculation now in flight is happening, carried from the
     * regeneration decision that authorised it. A movement without its cause is
     * half a finding, so `predictMissing()` sets these codes immediately before
     * it asks for a fresh prediction and the revision records them.
     *
     * @var list<string>
     */
    private array $pendingTriggers = [];

    public function __construct(
        private FootballRepository $repo,
        private FeatureBuilder $features,
        private OutcomePredictor $predictor,
        private ModelRegistry $models,
        private FootballConfiguration $config,
        private ?AuditRepository $audit = null,
        private ?StabilityMonitor $stability = null,
    ) {}

    /**
     * The revision trail. It is optional by construction: a caller that assembles
     * this service without it still gets predictions, they simply carry
     * `DATA_UNAVAILABLE` for stability rather than an invented verdict.
     */
    public function stability(): StabilityMonitor
    {
        return $this->stability ??= new StabilityMonitor($this->repo, $this->config);
    }

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
            // A quality-gated answer is still an assessment. Persist that answer
            // separately from football_match_predictions so the next read can
            // show the measured DQ score and the exact refusal reason instead of
            // falling back to the fictitious-looking "0/100 / NOT_ANALYZED"
            // placeholders. A read-only prediction never writes this snapshot.
            if ($persist && $kind === self::KIND_PRE_MATCH) {
                $payload['assessment'] = $this->storeAssessment($fixture, $payload, $model);
            }
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
                // Classification is part of the forecast record as well as a
                // display field. Future threshold changes must not relabel the
                // prediction that settlement later measures.
                'category' => $payload['category'] ?? null,
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
        // The row is stored, so now — and only now — is there something to compare
        // with. The revision is written from the stored values, which keeps the
        // trail describing what a reader can actually go and read.
        $row = array_merge($row, ['id' => $id]);
        $payload['stability'] = $this->stability->record($row, $fixture, $this->pendingTriggers, $kind);
        $payload['contract'] = $this->contract($row, $fixture);
        $this->audit?->emit('FOOTBALL_PREDICTION_GENERATED', 'Football prediction ' . $payload['resultLabel'] . ' ' . ($payload['predictedScore']['home'] . '–' . $payload['predictedScore']['away']) . ' for ' . ($fixture['home_team'] ?? '') . ' v ' . ($fixture['away_team'] ?? ''), [
            'predictionId' => $id, 'confidence' => $payload['confidence'], 'confidenceBasis' => $payload['confidenceBasis'],
            'dataQuality' => $payload['dataQuality']['score'], 'band' => $payload['dataQuality']['status'],
            'modelVersionId' => $payload['model']['modelVersionId'] ?? null, 'calibrationVersion' => $payload['calibrationVersion'],
            'xgMethod' => $payload['xgMethod'],
        ], 'system');
        return $payload;
    }

    /**
     * Store an honest, non-prediction assessment for a fixture.
     *
     * The generic fixture-statistics table is appropriate here because the row
     * records measured input coverage, not an outcome forecast. The payload is
     * deliberately small and contains no invented probability, confidence,
     * category or risk value.
     *
     * @param array<string,mixed> $fixture
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $model usable() response from ModelRegistry
     * @return array{stored:bool,kind:string,generatedAt:?string}
     */
    private function storeAssessment(array $fixture, array $payload, array $model): array
    {
        $fixtureId = (int) ($fixture['id'] ?? 0);
        $providerId = (int) ($fixture['provider_id'] ?? 0);
        $generatedAt = isset($payload['generatedAt']) && trim((string) $payload['generatedAt']) !== ''
            ? (string) $payload['generatedAt'] : gmdate('c');
        if ($fixtureId <= 0 || $providerId <= 0) {
            return ['stored' => false, 'kind' => self::ASSESSMENT_KIND, 'generatedAt' => $generatedAt];
        }
        $quality = is_array($payload['dataQuality'] ?? null) ? $payload['dataQuality'] : [];
        $score = is_numeric($quality['score'] ?? null) ? (int) $quality['score'] : null;
        $band = isset($quality['status']) && trim((string) $quality['status']) !== ''
            ? (string) $quality['status'] : null;
        $reasoning = array_values(array_filter((array) ($payload['reasoning'] ?? []), static fn($reason): bool => is_string($reason) && trim($reason) !== ''));
        $snapshot = [
            'status' => 'ASSESSED_NO_PREDICTION',
            'code' => (string) ($payload['code'] ?? 'NO_PREDICTION'),
            'reason' => mb_substr((string) ($payload['reason'] ?? 'The engine did not publish a prediction.'), 0, 600),
            'reasoning' => array_map(static fn(string $reason): string => mb_substr($reason, 0, 240), array_slice($reasoning, 0, 8)),
            'dataQuality' => [
                'score' => $score,
                'status' => $band,
                'band' => $band,
                'components' => is_array($quality['components'] ?? null) ? $quality['components'] : [],
            ],
            'predictionKind' => self::KIND_PRE_MATCH,
            'modelVersionId' => (int) ($model['model']['id'] ?? 0) ?: null,
            'modelVersion' => $model['model']['model_version'] ?? null,
            'generatedAt' => $generatedAt,
        ];
        try {
            $this->repo->saveFixtureStatistics($fixtureId, $providerId, self::ASSESSMENT_KIND, $snapshot, [
                // AVAILABLE means the assessment itself was stored successfully;
                // the separate quality band still says whether its inputs were
                // sufficient for a prediction.
                'state' => DataState::AVAILABLE,
                'qualityScore' => $score,
                'qualityBand' => $band,
            ]);
            return ['stored' => true, 'kind' => self::ASSESSMENT_KIND, 'generatedAt' => $generatedAt];
        } catch (\Throwable) {
            // The prediction refusal remains the truthful response even if its
            // convenience snapshot cannot be written. Never turn missing data
            // into a 500 or fabricate a row to hide a persistence failure.
            return ['stored' => false, 'kind' => self::ASSESSMENT_KIND, 'generatedAt' => $generatedAt];
        }
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
        $confidence = is_numeric($prediction['confidence'] ?? null) ? (float) $prediction['confidence'] : null;
        $dataQualityBand = (string) ($prediction['data_quality_band'] ?? QualityBand::REJECTED);
        $expectedGoals = self::expectedGoalsSummary($prediction);
        $category = self::storedCategory($prediction, $this->config);
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
            'dataState' => (string) ($fixture['data_state'] ?? DataState::UNAVAILABLE),
            'score' => (isset($fixture['home_score'], $fixture['away_score']))
                ? ['home' => (int) $fixture['home_score'], 'away' => (int) $fixture['away_score'], 'minute' => $fixture['minute'] ?? null] : null,
            'prediction' => [
                'result' => (string) ($prediction['predicted_result'] ?? ''),
                'predictedScore' => ['home' => $prediction['predicted_home_score'], 'away' => $prediction['predicted_away_score']],
                'probabilities' => ['home' => $prediction['probability_home'], 'draw' => $prediction['probability_draw'], 'away' => $prediction['probability_away']],
                'confidence' => $confidence,
                'confidenceBasis' => (string) ($prediction['confidence_basis'] ?? 'RAW'),
                'category' => $category,
                // These are the two model rates that created the stored
                // scoreline matrix. Values absent from an older snapshot stay
                // null; they are never reconstructed from a final score or
                // substituted with a league average at read time.
                'expectedGoals' => $expectedGoals,
                'expectedHomeGoals' => $expectedGoals['home'],
                'expectedAwayGoals' => $expectedGoals['away'],
                'expectedTotalGoals' => $prediction['expected_total_goals'] ?? null,
                'calibrationState' => (string) ($prediction['calibration_state'] ?? CalibrationService::PENDING),
            ],
            // Both probability sets are published side by side on purpose (§9):
            // the model's own shares and the calibrated value actually displayed.
            'rawProbabilities' => ['home' => $prediction['raw_home'] ?? null, 'draw' => $prediction['raw_draw'] ?? null, 'away' => $prediction['raw_away'] ?? null],
            'dataQuality' => ['score' => (int) ($prediction['data_quality_score'] ?? 0), 'status' => $dataQualityBand, 'components' => $qualityComponents],
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
     * The expected-goal rates captured when this prediction was generated.
     *
     * The repository deliberately keeps this in the immutable feature snapshot
     * with the source/method. It means a historic prediction continues to show
     * the rates it actually used even after new team statistics arrive. Older
     * prediction rows did not store this detail, so they return null rather
     * than having their values retroactively inferred.
     *
     * @return array{home:?float,away:?float,method:?string,source:?string}
     */
    public static function expectedGoalsSummary(array $prediction): array
    {
        $snapshot = is_array($prediction['feature_snapshot'] ?? null)
            ? $prediction['feature_snapshot']
            : json_decode((string) ($prediction['feature_snapshot'] ?? '{}'), true);
        $snapshot = is_array($snapshot) ? $snapshot : [];
        $stored = is_array($snapshot['expectedGoals'] ?? null) ? $snapshot['expectedGoals'] : [];
        return [
            'home' => is_numeric($stored['home'] ?? null) ? round((float) $stored['home'], 3) : null,
            'away' => is_numeric($stored['away'] ?? null) ? round((float) $stored['away'], 3) : null,
            'method' => isset($stored['method']) && trim((string) $stored['method']) !== '' ? (string) $stored['method']
                : (isset($snapshot['xgMethod']) && trim((string) $snapshot['xgMethod']) !== '' ? (string) $snapshot['xgMethod'] : null),
            'source' => isset($stored['source']) && trim((string) $stored['source']) !== '' ? (string) $stored['source'] : null,
        ];
    }

    /**
     * Category as it was written with this forecast. Historic rows that
     * pre-date stored categories are mapped conservatively from their own
     * confidence/data-quality values, which is the only honest fallback they
     * contain.
     *
     * @return array{code:?string,label:string,tier:string,reason:string}
     */
    public static function storedCategory(array $prediction, FootballConfiguration $config): array
    {
        $snapshot = is_array($prediction['feature_snapshot'] ?? null)
            ? $prediction['feature_snapshot']
            : json_decode((string) ($prediction['feature_snapshot'] ?? '{}'), true);
        $snapshot = is_array($snapshot) ? $snapshot : [];
        $stored = is_array($snapshot['category'] ?? null) ? $snapshot['category'] : [];
        if (array_key_exists('code', $stored) && isset($stored['label'], $stored['tier'])) {
            $code = $stored['code'];
            return [
                'code' => in_array($code, ['A', 'B', 'C'], true) ? $code : null,
                'label' => (string) $stored['label'],
                'tier' => (string) $stored['tier'],
                'reason' => isset($stored['reason']) ? (string) $stored['reason'] : 'Category stored when this prediction was generated.',
            ];
        }
        return $config->predictionCategory(
            ['home' => $prediction['probability_home'] ?? null, 'draw' => $prediction['probability_draw'] ?? null, 'away' => $prediction['probability_away'] ?? null],
            (string) ($prediction['data_quality_band'] ?? QualityBand::REJECTED),
        );
    }

    /**
     * The stored prediction for one match, if there is one.
     *
     * This is the "check match_id against the database" step: a match carries
     * at most one pre-match prediction per model version — enforced by
     * `UNIQUE(fixture_id, prediction_kind, model_version_id)` — so a hit here
     * means the prediction is returned as it is and the engine is never asked
     * to produce it a second time.
     *
     * A model version of 0 is a real state (a prediction written before a model
     * row existed) and is matched as such, not treated as "any version".
     *
     * @param array<string,mixed> $fixture
     * @param bool $refresh re-read after a write instead of trusting the cache
     * @return array<string,mixed>|null
     */
    public function existing(array $fixture, int $modelVersionId, string $kind = self::KIND_PRE_MATCH, bool $refresh = false): ?array
    {
        $fixtureId = (int) ($fixture['id'] ?? 0);
        if ($fixtureId <= 0) return null;
        $key = $fixtureId . '|' . $kind . '|' . $modelVersionId;
        if (!$refresh && array_key_exists($key, $this->resolved)) return $this->resolved[$key];
        $rows = $this->repo->listPredictionsForFixtures([$fixtureId], $kind, $modelVersionId);
        return $this->resolved[$key] = $rows[$fixtureId] ?? null;
    }

    /**
     * Pre-load the stored predictions for a whole page, in one query, so the
     * per-match lookups that follow are answered from memory.
     *
     * @param list<int> $fixtureIds
     */
    public function prime(array $fixtureIds, int $modelVersionId, string $kind = self::KIND_PRE_MATCH): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $fixtureIds), static fn(int $id): bool => $id > 0)));
        if ($ids === []) return;
        $rows = $this->repo->listPredictionsForFixtures($ids, $kind, $modelVersionId);
        foreach ($ids as $id) {
            $key = $id . '|' . $kind . '|' . $modelVersionId;
            // A miss is cached too: it is a fact about this request, and it is
            // what keeps paging from re-asking about the same empty match.
            $this->resolved[$key] = $rows[$id] ?? null;
        }
    }

    /**
     * Why this match cannot receive a new prediction at all — kickoff has
     * passed, or the fixture was postponed or cancelled. `null` means the slot
     * is open and generation may be attempted.
     *
     * @param array<string,mixed> $fixture
     * @return array{code:string, reason:string}|null
     */
    public function refusal(array $fixture, string $kind = self::KIND_PRE_MATCH): ?array
    {
        $code = $this->frozenReason($fixture, $kind);
        if ($code === null) return null;
        return ['code' => $code, 'reason' => match ($code) {
            'KICKOFF_PASSED' => 'Kickoff has passed, so no pre-match prediction can be created for this match any more. Nothing is back-filled.',
            'FIXTURE_POSTPONED', 'FIXTURE_CANCELLED' => 'The fixture is ' . strtolower(substr($code, 8)) . '; there is no match to predict.',
            default => 'The fixture status is not one a pre-match prediction may be written for.',
        }];
    }

    /** The rule that decides whether a stored prediction may be replaced. */
    public function regeneration(): RegenerationPolicy
    {
        return $this->regeneration ??= new RegenerationPolicy($this->config);
    }

    /**
     * The regeneration signals for the matches that already have a prediction.
     *
     * Only one signal is derived here — how far the quoted price has moved
     * since the prediction was written — because it is the one that is already
     * stored and can therefore be measured rather than assumed. It is read in
     * a single batched query for the page, and it is only computed when a
     * prediction exists to compare against. Lineup, injury and news signals
     * come from the caller (`$signals`) when it has them; when it does not,
     * they stay unknown, and an unknown is never a reason to regenerate.
     *
     * @param array<int,array<string,mixed>> $fixtures
     * @param array<string,array<string,mixed>> $signals keyed by match id
     * @return array<string,array<string,mixed>> keyed by match id
     */
    private function oddsHistory(array $fixtures, int $modelVersionId, string $kind, array $signals): array
    {
        $matchIds = [];
        foreach ($fixtures as $fixture) {
            $matchId = MatchFeed::matchId($fixture);
            if ($matchId === '') continue;
            if ($this->existing($fixture, $modelVersionId, $kind) === null) continue;
            $matchIds[$matchId] = (string) ($this->existing($fixture, $modelVersionId, $kind)['generated_at'] ?? '');
        }
        if ($matchIds === []) return [];
        $rows = $this->repo->listMarketOdds(array_keys($matchIds));
        $out = [];
        foreach ($matchIds as $matchId => $generatedAt) {
            $movement = $this->priceMovement($rows[$matchId] ?? [], $generatedAt);
            $signal = (array) ($signals[$matchId] ?? []);
            if ($movement !== null && !array_key_exists('oddsMovement', $signal)) $signal['oddsMovement'] = $movement;
            if (array_key_exists('oddsMovement', $signal)) $signal['oddsMovementMeasured'] = true;
            $out[$matchId] = $signal;
        }
        return $out;
    }

    /**
     * The largest move in implied probability between the price that stood when
     * the prediction was written and the latest price the feed has quoted.
     *
     * @param list<array{market:string,selection:string,decimalOdds:float,observedAt:?string}> $rows
     */
    private function priceMovement(array $rows, string $generatedAt): ?float
    {
        if ($rows === []) return null;
        $generated = $generatedAt !== '' ? strtotime($generatedAt) : null;
        if ($generated === null || $generated === false) return null;
        $latest = [];
        $latestAt = [];
        $baseline = [];
        $baselineAt = [];
        foreach ($rows as $row) {
            $price = (float) ($row['decimalOdds'] ?? 0);
            if ($price <= 0) continue;
            $key = (string) ($row['market'] ?? '') . '|' . (string) ($row['selection'] ?? '');
            $observed = strtotime((string) ($row['observedAt'] ?? ''));
            if ($observed === false) continue;
            // Chosen by the timestamp on the row, not by the order it arrived
            // in: which quote is the newest and which one stood when the
            // prediction was written must not depend on the query's ordering.
            if (!isset($latestAt[$key]) || $observed > $latestAt[$key]) { $latestAt[$key] = $observed; $latest[$key] = $price; }
            if ($observed <= $generated && (!isset($baselineAt[$key]) || $observed > $baselineAt[$key])) {
                $baselineAt[$key] = $observed; $baseline[$key] = $price;
            }
        }
        if ($latest === [] || $baseline === []) return null;
        $worst = 0.0;
        foreach ($latest as $key => $price) {
            if (!isset($baseline[$key])) continue;
            $move = abs((1 / $price) - (1 / $baseline[$key]));
            if ($move > $worst) $worst = $move;
        }
        return round($worst, 6);
    }

    /**
     * Generate predictions for the matches that do not have one yet — never
     * more than `$limit` of them, and never more than one page
     * (`MatchFeed::MAX_PAGE_SIZE`) in a single call.
     *
     * The rule this enforces is the one the paginated module is built on: a
     * match that already has a stored prediction is *returned*, not
     * recomputed. Changing pages, reloading a page or sweeping the same date
     * twice therefore costs the engine nothing for those matches.
     *
     * @param array<int,array<string,mixed>> $fixtures candidate rows, already paged
     * @return array{requested:int, limit:int, generated:int, skipped:int, deferred:int, frozen:int, failed:int, matches:array<int,array<string,mixed>>, errors:list<string>, modelVersionId:int|null, modelVersion:string|null}
     */
    public function predictMissing(array $fixtures, int $limit = MatchFeed::MAX_PAGE_SIZE, string $kind = self::KIND_PRE_MATCH, array $signals = []): array
    {
        // This service can be called outside the paginated feed (for
        // example by a console action), so enforce the configured cycle here
        // too rather than relying on every caller to remember it.
        $limit = max(0, min(MatchFeed::MAX_PAGE_SIZE, $this->config->analysisBatchSize(), $limit));
        $model = $this->models->usable();
        $modelVersionId = (int) ($model['model']['id'] ?? 0);
        $policy = $this->regeneration();
        $out = [
            'requested' => count($fixtures),
            // The ceiling is repeated back to the caller: what was asked for and
            // what was allowed are both visible, and they are not the same thing
            // when someone asks for 500.
            'limit' => $limit,
            'generated' => 0, 'skipped' => 0, 'refreshed' => 0, 'refused' => 0, 'deferred' => 0, 'frozen' => 0, 'failed' => 0,
            'matches' => [], 'errors' => [],
            'modelVersionId' => $modelVersionId ?: null,
            'modelVersion' => $model['model']['model_version'] ?? null,
        ];
        $budget = $limit;
        // One batched read of the quoted prices for the matches that already
        // have a prediction: it is what lets a real price move — rather than a
        // reload, a page change or a second sweep — be the reason a prediction
        // is replaced.
        $history = $this->oddsHistory($fixtures, $modelVersionId, $kind, $signals);
        foreach ($fixtures as $index => $fixture) {
            $identity = ['fixtureId' => (int) ($fixture['id'] ?? 0), 'externalId' => $fixture['external_id'] ?? null,
                'matchId' => MatchFeed::matchId($fixture)];
            $stored = $this->existing($fixture, $modelVersionId, $kind);
            if ($stored !== null) {
                $decision = $policy->decide($stored, $fixture, $history[$identity['matchId']] ?? [], $modelVersionId);
                if ($decision['action'] === RegenerationPolicy::REFRESH && $budget > 0) {
                    // A reason that justifies it, and budget left for it: the
                    // stored row is replaced by a fresh one, and both counts
                    // say so — this is a regeneration, not a second prediction.
                    $budget--;
                    $this->pendingTriggers = $decision['codes'];
                    try {
                        $payload = $this->predict($fixture, true, $kind);
                    } finally {
                        $this->pendingTriggers = [];
                    }
                    $replaced = $this->existing($fixture, $modelVersionId, $kind, true);
                    if ($replaced !== null) {
                        $out['refreshed']++;
                        $out['matches'][$index] = $identity + ['state' => self::MISSING_GENERATED,
                            'source' => MatchFeed::SOURCE_GENERATED, 'regenerated' => true,
                            'regeneration' => ['codes' => $decision['codes'], 'reasons' => $decision['reasons']],
                            'predictionId' => (string) ($replaced['id'] ?? ''),
                            'reason' => 'The stored prediction was replaced because: ' . implode(' ', $decision['reasons'])];
                        continue;
                    }
                    $out['failed']++;
                    $out['errors'][] = 'fixture ' . ($fixture['external_id'] ?? $fixture['id'])
                        . ': regeneration was justified but the engine stored no new row.';
                    $out['matches'][$index] = $identity + ['state' => self::MISSING_FAILED, 'source' => MatchFeed::SOURCE_FAILED,
                        'code' => 'REGENERATION_NOT_STORED',
                        'reason' => 'Regeneration was justified (' . implode(', ', $decision['codes'])
                            . ') but no replacement prediction was stored; the existing one is shown.'];
                    continue;
                }
                $out['skipped']++;
                $out['matches'][$index] = $identity + ['state' => self::MISSING_STORED, 'source' => MatchFeed::SOURCE_STORED,
                    'regeneration' => ['action' => $decision['action'], 'codes' => $decision['codes'],
                        'reasons' => $decision['reasons']],
                    'reason' => 'A prediction for this match is already stored; it was reused instead of being generated again.'];
                continue;
            }
            $refusal = $this->refusal($fixture, $kind);
            if ($refusal !== null) {
                $out['frozen']++;
                $out['matches'][$index] = $identity + ['state' => self::MISSING_FROZEN, 'source' => MatchFeed::SOURCE_REFUSED,
                    'code' => $refusal['code'], 'reason' => $refusal['reason']];
                continue;
            }
            if ($budget <= 0) {
                $out['deferred']++;
                $out['matches'][$index] = $identity + ['state' => self::MISSING_DEFERRED, 'source' => MatchFeed::SOURCE_DEFERRED,
                    'code' => 'BATCH_LIMIT_REACHED',
                    'reason' => 'This request already generated ' . $limit . ' new predictions; the match is picked up by the next one.'];
                continue;
            }
            $budget--;
            try {
                $payload = $this->predict($fixture, true, $kind);
            } catch (\Throwable $e) {
                $out['failed']++;
                $out['errors'][] = 'fixture ' . ($fixture['external_id'] ?? $fixture['id']) . ': ' . mb_substr($e->getMessage(), 0, 200);
                $out['matches'][$index] = $identity + ['state' => self::MISSING_FAILED, 'source' => MatchFeed::SOURCE_FAILED,
                    'code' => 'ENGINE_ERROR', 'reason' => mb_substr($e->getMessage(), 0, 200)];
                continue;
            }
            if (!empty($payload['predictionFrozen'])) {
                $out['frozen']++;
                $out['matches'][$index] = $identity + ['state' => self::MISSING_FROZEN, 'source' => MatchFeed::SOURCE_REFUSED,
                    'code' => (string) ($payload['code'] ?? 'KICKOFF_PASSED'),
                    'reason' => (string) ($payload['reason'] ?? 'The pre-match slot for this match is closed.')];
                continue;
            }
            $stored = $this->existing($fixture, $modelVersionId, $kind, true);
            $band = (string) ($payload['dataQuality']['status'] ?? ($stored['data_quality_band'] ?? QualityBand::REJECTED));
            if ($stored === null) {
                // The engine answered without writing a prediction row — the
                // quality gate refused it, or a required model input was absent.
                // Preserve the measured assessment in this request as well as in
                // the durable PREDICTION_ASSESSMENT snapshot. Dropping the score
                // here was what made an actually measured rejection render as
                // the made-up default "DQ 0/100" immediately afterwards.
                $out['refused']++;
                $quality = is_array($payload['dataQuality'] ?? null) ? $payload['dataQuality'] : [];
                $score = is_numeric($quality['score'] ?? null) ? (int) $quality['score'] : null;
                $out['matches'][$index] = $identity + ['state' => self::MISSING_REFUSED, 'source' => MatchFeed::SOURCE_REFUSED,
                    'code' => (string) ($payload['code'] ?? 'DATA_QUALITY_' . $band), 'band' => $band,
                    'score' => $score,
                    'assessment' => [
                        'status' => 'ASSESSED_NO_PREDICTION',
                        'code' => (string) ($payload['code'] ?? 'DATA_QUALITY_' . $band),
                        'reason' => (string) ($payload['reason'] ?? ('The stored data for this match is ' . $band . '; no prediction row was written.')),
                        'reasoning' => array_values((array) ($payload['reasoning'] ?? [])),
                        'dataQuality' => ['score' => $score, 'status' => $band, 'band' => $band,
                            'components' => is_array($quality['components'] ?? null) ? $quality['components'] : []],
                        'modelVersionId' => $modelVersionId ?: null,
                        'generatedAt' => $payload['generatedAt'] ?? gmdate('c'),
                    ],
                    // The engine's own reason, which names the missing data —
                    // more actionable than restating the band.
                    'reason' => (string) ($payload['reason'] ?? ('The stored data for this match is ' . $band . '; no prediction row was written.'))];
                continue;
            }
            $out['generated']++;
            $out['matches'][$index] = $identity + ['state' => self::MISSING_GENERATED, 'source' => MatchFeed::SOURCE_GENERATED,
                'predictionId' => (string) ($stored['id'] ?? ''), 'band' => $band,
                'score' => (int) ($stored['data_quality_score'] ?? 0),
                'result' => $payload['result'] ?? null, 'confidence' => $payload['confidence'] ?? null];
        }
        return $out;
    }

    /**
     * The same classification without generating anything — what a read-only
     * page request uses to explain, per match, why a prediction is or is not
     * attached to it.
     *
     * @param array<int,array<string,mixed>> $fixtures
     * @return array{requested:int, limit:int, generated:int, skipped:int, deferred:int, frozen:int, failed:int, matches:array<int,array<string,mixed>>, errors:list<string>, modelVersionId:int|null, modelVersion:string|null}
     */
    public function reportOnly(array $fixtures, int $limit = MatchFeed::MAX_PAGE_SIZE, string $kind = self::KIND_PRE_MATCH): array
    {
        $limit = max(0, min(MatchFeed::MAX_PAGE_SIZE, $this->config->analysisBatchSize(), $limit));
        $model = $this->models->usable();
        $modelVersionId = (int) ($model['model']['id'] ?? 0);
        $out = ['requested' => count($fixtures), 'limit' => $limit, 'generated' => 0, 'skipped' => 0,
            'refused' => 0, 'deferred' => 0, 'frozen' => 0, 'failed' => 0, 'matches' => [], 'errors' => [],
            'modelVersionId' => $modelVersionId ?: null, 'modelVersion' => $model['model']['model_version'] ?? null];
        foreach ($fixtures as $index => $fixture) {
            $identity = ['fixtureId' => (int) ($fixture['id'] ?? 0), 'externalId' => $fixture['external_id'] ?? null,
                'matchId' => MatchFeed::matchId($fixture)];
            if ($this->existing($fixture, $modelVersionId, $kind) !== null) {
                $out['skipped']++;
                $out['matches'][$index] = $identity + ['state' => self::MISSING_STORED, 'source' => MatchFeed::SOURCE_STORED];
                continue;
            }
            $refusal = $this->refusal($fixture, $kind);
            if ($refusal !== null) {
                $out['frozen']++;
                $out['matches'][$index] = $identity + ['state' => self::MISSING_FROZEN, 'source' => MatchFeed::SOURCE_REFUSED,
                    'code' => $refusal['code'], 'reason' => $refusal['reason']];
                continue;
            }
            $out['refused']++;
            $out['matches'][$index] = $identity + [
                'state' => self::MISSING_REFUSED, 'source' => MatchFeed::SOURCE_REFUSED,
                'code' => 'NOT_GENERATED',
                'reason' => 'No prediction is stored for this match yet. Generating this page creates at most '
                    . $this->config->analysisBatchSize() . ' new predictions in this configured cycle.',
            ];
        }
        return $out;
    }

    /**
     * Predict the configured cycle's stored fixtures for a date that do not
     * have a prediction yet, in a batch of at most fifty.
     *
     * Matches that already carry a prediction for the model version in use are
     * skipped rather than rewritten: re-running this job is how a partly-filled
     * date gets finished, so it must cost nothing for the matches it already
     * handled. `$maxNew` caps how many *new* predictions one call may write
     * (default: the analysis limit). `$maxFixtures` is an internal caller cap
     * for a shared multi-date cycle; it limits fixtures *evaluated*, including
     * those honestly rejected on data quality, so an automatic cycle cannot
     * exceed its configured total merely because some rows are not publishable.
     *
     * @return array{status:string, date:string, fixtures:int, analyzed:int, qualified:int, limited:int, rejected:int, predictions:list<array>, errors:list<string>, provider:string|null, model:array, skipped:int, frozen:int, failed:int, batches:int, maxNew:int}
     */
    public function predictDay(string $date, ?string $providerId = null, ?int $maxNew = null, ?int $maxFixtures = null): array
    {
        $filter = ['date' => $date];
        if ($providerId !== null) $filter['providerId'] = (int) $providerId;
        // One scheduled/on-demand day pass is one configured batch. The
        // configuration itself is hard-capped at MatchFeed::MAX_PAGE_SIZE (50),
        // which guarantees this path cannot silently turn a 50-match cycle into
        // several 50-row sub-batches. A caller coordinating several dates may
        // reserve a smaller part of that one cycle via `$maxFixtures`.
        $configuredBatchSize = $this->config->analysisBatchSize();
        $analysisLimit = $maxFixtures === null
            ? $configuredBatchSize
            : max(0, min($configuredBatchSize, $maxFixtures));
        $model = $this->models->usable();
        $modelVersionId = (int) ($model['model']['id'] ?? 0);
        // Select pending fixtures ahead of already-stored ones. Without this,
        // a 10-match cycle would keep re-reading the same first ten fixtures
        // forever and never reach fixture eleven on a busy matchday. The scan
        // only checks persisted identities in small database pages; it neither
        // calls the provider nor calculates a prediction for a fixture outside
        // this cycle's selected batch.
        $availableFixtures = $this->repo->countFixtures($filter);
        $fixtures = $analysisLimit > 0
            ? $this->cycleFixtures($filter, $modelVersionId, $analysisLimit, $availableFixtures)
            : [];
        $out = ['status' => 'COMPLETED', 'date' => $date, 'fixtures' => count($fixtures),
            'configuredBatchSize' => $configuredBatchSize, 'candidateLimit' => $analysisLimit,
            'availableFixtures' => $availableFixtures, 'deferredFixtures' => max(0, $availableFixtures - count($fixtures)),
            'analyzed' => 0, 'qualified' => 0, 'limited' => 0, 'rejected' => 0,
            // Fixtures whose pre-match slot had already closed are tallied apart,
            // so "we did not predict it in time" is never mistaken for "the data
            // was too thin to predict".
            'frozen' => 0, 'predictions' => [], 'errors' => [], 'skipped' => 0, 'refused' => 0, 'failed' => 0, 'batches' => 0,
            'maxNew' => min($analysisLimit, max(0, $maxNew ?? $analysisLimit)), 'batchSize' => $analysisLimit];
        $out['model'] = ['state' => $model['state'], 'label' => $model['label'], 'version' => $model['model']['model_version'] ?? null, 'reason' => $model['reason']];
        if ($fixtures === []) {
            if ($analysisLimit <= 0) {
                $out['status'] = 'SKIPPED';
                $out['reason'] = 'The shared prediction-cycle limit was spent on an earlier date; this date was not evaluated in this cycle.';
            } else {
                $out['status'] = DataState::UNAVAILABLE;
                $out['reason'] = 'No fixture for ' . $date . ' is stored. The provider has not been reached for this date, so no prediction is produced.';
            }
            return $out;
        }
        // `$maxNew` is accepted for callers that intentionally choose a
        // smaller run, never a larger one than the configured cycle.
        $budget = max(0, min($analysisLimit, $maxNew ?? $analysisLimit));
        $attempted = [];
        while ($budget > 0) {
            $batch = [];
            foreach ($fixtures as $index => $fixture) {
                if (isset($attempted[$index])) continue;
                $batch[$index] = $fixture;
                if (count($batch) >= MatchFeed::MAX_PAGE_SIZE) break;
            }
            if ($batch === []) break;
            $result = $this->predictMissing($batch, min(MatchFeed::MAX_PAGE_SIZE, $budget), self::KIND_PRE_MATCH);
            $out['batches']++;
            $budget -= max(0, $result['generated']);
            $out['skipped'] += (int) $result['skipped'];
            $out['frozen'] += (int) $result['frozen'];
            $out['refused'] += (int) $result['refused'];
            $out['failed'] += (int) $result['failed'];
            $out['errors'] = array_merge($out['errors'], $result['errors']);
            foreach ($result['matches'] as $index => $outcome) {
                // A deferred match was never attempted, so it stays eligible for
                // the next batch; everything else is settled for this call.
                if ((string) ($outcome['state'] ?? '') !== self::MISSING_DEFERRED) $attempted[$index] = true;
                if ((string) ($outcome['state'] ?? '') === self::MISSING_STORED) continue;
                $out['predictions'][] = ['fixtureId' => $outcome['fixtureId'] ?? null, 'externalId' => $outcome['externalId'] ?? null,
                    'matchId' => $outcome['matchId'] ?? null, 'status' => (string) ($outcome['state'] ?? 'UNKNOWN'),
                    'code' => $outcome['code'] ?? null, 'band' => $outcome['band'] ?? null, 'score' => $outcome['score'] ?? null,
                    'predictionId' => $outcome['predictionId'] ?? null, 'result' => $outcome['result'] ?? null,
                    'confidence' => $outcome['confidence'] ?? null];
                // A match the engine answered on is analyzed — whether it stored a
                // prediction (GENERATED) or refused it on data quality (REFUSED).
                // Only the closed-slot cases are FROZEN, and they are tallied apart.
                if (!in_array((string) ($outcome['state'] ?? ''), [self::MISSING_GENERATED, self::MISSING_REFUSED], true)) continue;
                $out['analyzed']++;
                $band = (string) ($outcome['band'] ?? QualityBand::REJECTED);
                if ($band === QualityBand::QUALIFIED) $out['qualified']++;
                elseif ($band === QualityBand::LIMITED) $out['limited']++;
                else $out['rejected']++;
            }
        }
        return $out;
    }

    /**
     * Pick the next persisted fixtures for one bounded prediction cycle.
     *
     * New slots are selected before already-predicted slots, allowing repeated
     * scheduled cycles to progress through a matchday larger than the configured
     * batch. When every fixture already has a record, the oldest page is used as
     * the fallback so RegenerationPolicy can still refresh a prediction only
     * when its evidence justifies it. This is a database-only selection pass;
     * provider calls and prediction arithmetic remain limited to the returned
     * fixtures.
     *
     * @param array<string,mixed> $filter
     * @return list<array<string,mixed>>
     */
    private function cycleFixtures(array $filter, int $modelVersionId, int $limit, ?int $total = null): array
    {
        $limit = max(1, min(MatchFeed::MAX_PAGE_SIZE, $limit));
        $total ??= $this->repo->countFixtures($filter);
        $pending = [];
        $fallback = [];
        for ($offset = 0; $offset < $total && count($pending) < $limit; $offset += MatchFeed::MAX_PAGE_SIZE) {
            $page = $this->repo->listFixtures($filter, MatchFeed::MAX_PAGE_SIZE, $offset);
            if ($page === []) break;
            $ids = array_values(array_filter(array_map(static fn(array $fixture): int => (int) ($fixture['id'] ?? 0), $page)));
            $stored = $ids === [] ? [] : $this->repo->listPredictionsForFixtures($ids, self::KIND_PRE_MATCH, $modelVersionId);
            foreach ($page as $fixture) {
                $fixtureId = (int) ($fixture['id'] ?? 0);
                if ($fixtureId > 0 && !isset($stored[$fixtureId])) {
                    $pending[] = $fixture;
                    if (count($pending) >= $limit) break;
                } elseif (count($fallback) < $limit) {
                    $fallback[] = $fixture;
                }
            }
        }
        return $pending !== [] ? $pending : array_slice($fallback, 0, $limit);
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
        $started = in_array($status, array_merge(FixtureSyncService::LIVE_STATUSES, ['FINISHED', 'SUSPENDED']), true);
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
            'homeTeamLogo' => MatchFeed::fixtureLogo($fixture, 'home'),
            'awayTeamLogo' => MatchFeed::fixtureLogo($fixture, 'away'),
            'score' => ['home' => $fixture['home_score'] ?? null, 'away' => $fixture['away_score'] ?? null],
            'redCards' => ['home' => $fixture['home_red_cards'] ?? null, 'away' => $fixture['away_red_cards'] ?? null],
            'venue' => $fixture['venue'] ?? null,
            'dataState' => (string) ($fixture['data_state'] ?? DataState::UNAVAILABLE),
        ];
    }
}
