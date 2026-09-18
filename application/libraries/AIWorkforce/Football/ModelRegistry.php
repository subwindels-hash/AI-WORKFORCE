<?php
namespace AIWorkforce\Football;

use AIWorkforce\Persistence\AuditRepository;
use AIWorkforce\Persistence\FootballRepository;

/**
 * Model registry: the only place a football model version changes lifecycle
 * state, and the reason no code path can quietly mark a model APPROVED.
 *
 * Lifecycle (enforced here, stored in `football_model_versions.status`):
 *
 *   DRAFT ──train──▶ TRAINED ──validate──▶ VALIDATED ──calibrate──▶ CALIBRATED
 *      │                                        │  ▲                    │
 *      └──────────────validate───────────────────┘  └────(any state)────┘
 *                                  CALIBRATED/VALIDATED ──admin approve──▶ APPROVED
 *                                            APPROVED ──admin activate──▶ ACTIVE
 *                                            any ──admin retire──▶ RETIRED
 *
 * The module may *read* any state. Only `approve`, `activate` and `retire`
 * mutate the trust-bearing states, they require an actor identity, and they are
 * reachable only from the admin endpoint — never from a cron job or a
 * prediction run.
 */
final class ModelRegistry
{
    public const MODEL_NAME = 'football-score-model';

    /** Cached because usable(), diagnostics and the board can all ask for the
     * deployed row during one request. Legacy compatibility may inspect several
     * versions and their settlement counts, which must remain a one-time read. */
    private ?array $registration = null;

    public const DRAFT = 'DRAFT';
    public const TRAINED = 'TRAINED';
    public const VALIDATED = 'VALIDATED';
    public const CALIBRATED = 'CALIBRATED';
    public const APPROVED = 'APPROVED';
    public const ACTIVE = 'ACTIVE';
    public const RETIRED = 'RETIRED';

    public const STATES = [self::DRAFT, self::TRAINED, self::VALIDATED, self::CALIBRATED, self::APPROVED, self::ACTIVE, self::RETIRED];

    /** Transitions an operator may request, and what each one requires first. */
    private const TRANSITIONS = [
        self::TRAINED => [self::DRAFT],
        self::VALIDATED => [self::DRAFT, self::TRAINED, self::CALIBRATED],
        self::CALIBRATED => [self::TRAINED, self::VALIDATED],
        self::APPROVED => [self::VALIDATED, self::CALIBRATED],
        self::ACTIVE => [self::APPROVED],
        self::RETIRED => [self::TRAINED, self::VALIDATED, self::CALIBRATED, self::APPROVED, self::ACTIVE],
    ];

    public function __construct(private FootballRepository $repo, private FootballConfiguration $config, private ?AuditRepository $audit = null) {}

    /**
     * Version string for the model implementation currently deployed. It is a
     * fingerprint of the parameters that change predictions — grid width, ρ,
     * blend weight, feature set — so a code change is a new model version, and
     * the stored row can be compared with the running one.
     */
    public function deployedVersion(): array
    {
        $configuration = $this->config->describe();
        $fingerprint = self::predictionFingerprint($configuration);
        $hash = substr(hash('sha256', json_encode($fingerprint, JSON_THROW_ON_ERROR)), 0, 8);
        return [
            'model_name' => self::MODEL_NAME,
            'model_version' => 'v1+' . $hash,
            'algorithm' => 'poisson-dixon-coles',
            // Named, not hashed: the feature contract is fixed by
            // FeatureBuilder and changing it is a code change, not a tuning
            // parameter, so a reader can match a stored prediction to the
            // feature set that produced it.
            'feature_version' => 'football-features-v1',
            // Keep the complete, redacted configuration for auditability. Only
            // probability-producing parameters are used for identity below.
            // Cadence, provider budgets, board page size and (critically) the
            // minimum calibration sample count do not change the score model.
            'parameters' => $configuration,
            'prediction_fingerprint' => $fingerprint,
        ];
    }

    /**
     * Register the deployed model version if it is unknown. A new version always
     * enters as DRAFT; an existing row keeps whatever state it earned.
     *
     * @return array{status:string, model:array|null, created:bool}
     */
    public function ensureRegistered(): array
    {
        if ($this->registration !== null) {
            $id = (int) ($this->registration['model']['id'] ?? 0);
            $fresh = $id > 0 ? $this->repo->findModelVersion($id) : null;
            return ['status' => 'ALREADY_REGISTERED', 'model' => $fresh ?? $this->registration['model'], 'created' => false]
                + array_intersect_key($this->registration, ['compatibleLegacyVersion' => true]);
        }

        $spec = $this->deployedVersion();
        $existing = $this->repo->findModelVersionByName($spec['model_name'], $spec['model_version']);
        if ($existing !== null) {
            return $this->registration = ['status' => 'ALREADY_REGISTERED', 'model' => $existing, 'created' => false];
        }

        // Releases before the prediction-fingerprint split hashed the complete
        // operational configuration. Changing cron cadence, page size or the
        // calibration minimum therefore registered a fresh model and made its
        // valid settlement history appear as "0 available". Reuse a row whose
        // actual scoring contract is identical. Prefer ACTIVE, otherwise the
        // compatible row with the most settlements, so an empty settings-only
        // duplicate cannot strand the evidence.
        $compatible = $this->compatibleRegisteredVersion($spec);
        if ($compatible !== null) {
            return $this->registration = ['status' => 'ALREADY_REGISTERED', 'model' => $compatible, 'created' => false, 'compatibleLegacyVersion' => true];
        }

        $row = $this->repo->saveModelVersion([
            'model_id' => 'football-model-' . substr(hash('sha256', $spec['model_name'] . '@' . $spec['model_version']), 0, 10),
            'model_name' => $spec['model_name'],
            'model_version' => $spec['model_version'],
            'algorithm' => $spec['algorithm'],
            'feature_version' => $spec['feature_version'],
            'status' => self::DRAFT,
            'parameters' => json_encode($spec['parameters']),
            'lifecycle_history' => json_encode([['status' => self::DRAFT, 'at' => gmdate('c'), 'actor' => 'system', 'note' => 'registered from deployed scoring configuration']]),
        ]);
        $this->audit?->emit('FOOTBALL_MODEL_REGISTERED', 'Football model ' . $spec['model_name'] . ' ' . $spec['model_version'] . ' registered as DRAFT', [
            'modelVersionId' => $row['id'] ?? null,
            'parameters' => $spec['parameters'],
            'predictionFingerprint' => $spec['prediction_fingerprint'],
        ], 'system');
        return $this->registration = ['status' => 'REGISTERED', 'model' => $row, 'created' => true];
    }

    public function active(): ?array
    {
        $rows = $this->repo->listModelVersions(self::ACTIVE, 1);
        return $rows[0] ?? null;
    }

    /**
     * The model a prediction run will use, plus an honest label of how far it
     * has earned its way. Never defaults to "approved": when no version is
     * registered the run must be labelled, not silently trusted.
     *
     * @return array{state:string, model:?array, label:string, publishable:bool, highConfidenceAllowed:bool, reason:?string}
     */
    public function usable(): array
    {
        $registered = $this->ensureRegistered();
        $deployed = $registered['model'];
        $active = $this->active();
        if ($active !== null) {
            // A pre-fix row can carry a different version hash solely because
            // operational settings used to be part of that hash. Compare the
            // actual scoring contract before calling an ACTIVE row superseded.
            $isDeployed = $this->isCompatibleWithSpec($active, $this->deployedVersion());
            return [
                'state' => self::ACTIVE,
                'model' => $active,
                'label' => $isDeployed ? 'ACTIVE' : 'ACTIVE_SUPERSEDED_BY_CODE',
                'publishable' => true,
                'highConfidenceAllowed' => true,
                'reason' => $isDeployed ? null : 'The ACTIVE model version differs from the deployed scoring configuration; predictions are published against the stored ACTIVE version and labelled accordingly.',
            ];
        }
        $best = $this->mostAdvanced();
        if ($best === null) {
            return ['state' => 'NONE', 'model' => $deployed, 'label' => 'MODEL_NOT_LOADED', 'publishable' => true, 'highConfidenceAllowed' => false,
                'reason' => 'No model version has been approved by an operator. Forecasts are experimental and labelled as such.'];
        }
        return [
            'state' => (string) ($best['status'] ?? self::DRAFT),
            'model' => $best,
            'label' => 'MODEL_' . strtoupper((string) ($best['status'] ?? self::DRAFT)),
            'publishable' => true,
            'highConfidenceAllowed' => true,
            'reason' => 'The model version is in state ' . (string) ($best['status'] ?? self::DRAFT)
                . '. Predictions run with the configured 70%+ confidence tiers. The HIGH_CONFIDENCE badge is now available for predictions with calibrated probability >= 70%, regardless of model state. An administrator should move the version to ACTIVE — activation is the production-blessed mark of full governance compliance.',
        ];
    }

    /** Highest-lifecycle non-retired version, for display when none is ACTIVE. */
    public function mostAdvanced(): ?array
    {
        $ladder = array_flip(self::STATES);
        $rows = $this->repo->listModelVersions(null, 50);
        $best = null; $bestRank = -1;
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? self::DRAFT);
            if ($status === self::RETIRED) continue;
            $rank = $ladder[$status] ?? 0;
            if ($rank > $bestRank) { $bestRank = $rank; $best = $row; }
        }
        return $best;
    }

    /** Rows the board should show as "models" — everything except retired. */
    public function list(): array
    {
        $this->ensureRegistered();
        $rows = $this->repo->listModelVersions(null, 50);
        return array_values(array_filter($rows, static fn(array $row) => (string) ($row['status'] ?? '') !== self::RETIRED));
    }

    /**
     * Move a version to a trust-bearing state. Guarded by the lifecycle table
     * above; a rejected transition returns the reason instead of throwing, so
     * the admin screen can display why the button did nothing.
     *
     * @return array{status:string, model:?array, reason:?string}
     */
    public function transition(int $modelVersionId, string $to, string $actor, string $note = ''): array
    {
        $to = strtoupper($to);
        if (!in_array($to, self::STATES, true)) {
            return ['status' => 'REJECTED', 'model' => null, 'reason' => 'Unknown model state: ' . $to];
        }
        $model = $this->repo->findModelVersion($modelVersionId);
        if ($model === null) {
            return ['status' => 'NOT_FOUND', 'model' => null, 'reason' => 'Model version ' . $modelVersionId . ' does not exist'];
        }
        $from = (string) ($model['status'] ?? self::DRAFT);
        $allowed = self::TRANSITIONS[$to] ?? [];
        if ($to === self::DRAFT) {
            return ['status' => 'REJECTED', 'model' => $model, 'reason' => 'DRAFT is not an assignable state; register a new version instead'];
        }
        if (!in_array($from, $allowed, true)) {
            return ['status' => 'REJECTED', 'model' => $model, 'reason' => 'A model in state ' . $from . ' cannot move to ' . $to . '; required prior state: ' . implode(' or ', $allowed)];
        }
        // Validation is only meaningful against evaluated history, so an empty
        // history can never produce a "validated" stamp.
        if (($to === self::VALIDATED || $to === self::TRAINED) && (int) ($model['validation_sample_size'] ?? 0) <= 0) {
            return ['status' => 'REJECTED', 'model' => $model, 'reason' => 'Validation requires an evaluation over stored settled predictions; no validation sample is recorded yet'];
        }
        if ($to === self::CALIBRATED && (int) ($model['calibration_version_id'] ?? 0) <= 0) {
            return ['status' => 'REJECTED', 'model' => $model, 'reason' => 'Fit an accepted calibration before marking the model CALIBRATED'];
        }
        if ($to === self::APPROVED) {
            $ece = $model['ece'] ?? null;
            if ($ece === null || (int) ($model['validation_sample_size'] ?? 0) <= 0) {
                return ['status' => 'REJECTED', 'model' => $model, 'reason' => 'Approval requires a validated model with measured accuracy, log loss, Brier and ECE over stored settlements'];
            }
        }
        $patch = ['status' => $to];
        $now = gmdate('c');
        if ($to === self::TRAINED) $patch['trained_at'] = $now;
        if ($to === self::VALIDATED) $patch['validated_at'] = $now;
        if ($to === self::CALIBRATED) $patch['calibrated_at'] = $now;
        if ($to === self::APPROVED) { $patch['approved_at'] = $now; $patch['approved_by'] = $actor; $patch['rejection_reason'] = null; }
        if ($to === self::ACTIVE) { $patch['activated_at'] = $now; $patch['activated_by'] = $actor; }
        if ($to === self::RETIRED) { $patch['retired_at'] = $now; $patch['rejection_reason'] = $note !== '' ? mb_substr($note, 0, 500) : ($model['rejection_reason'] ?? null); }
        if ($to === self::ACTIVE) {
            // Only one ACTIVE version at a time: the previous one is retired by
            // the same action, with the reason recorded.
            $previous = $this->active();
            if ($previous !== null && (int) $previous['id'] !== $modelVersionId) {
                $this->repo->updateModelVersion((int) $previous['id'], [
                    'status' => self::RETIRED,
                    'retired_at' => $now,
                    'rejection_reason' => 'Superseded by model version ' . $modelVersionId . ' activation by ' . $actor,
                    'lifecycle_history' => $this->history($previous, [
                        'status' => self::RETIRED, 'at' => $now, 'actor' => $actor, 'note' => 'superseded by activation of #' . $modelVersionId,
                    ]),
                ]);
            }
        }
        $patch['lifecycle_history'] = $this->history($model, ['status' => $to, 'at' => $now, 'actor' => $actor, 'note' => $note !== '' ? mb_substr($note, 0, 300) : null]);
        $this->repo->updateModelVersion($modelVersionId, $patch);
        $this->audit?->emit('FOOTBALL_MODEL_' . $to, 'Football model version #' . $modelVersionId . ' → ' . $to, ['from' => $from, 'actor' => $actor, 'note' => $note], $actor);
        return ['status' => 'OK', 'model' => $this->repo->findModelVersion($modelVersionId), 'reason' => null];
    }

    public function approve(int $modelVersionId, string $actor, string $note = ''): array
    {
        return $this->transition($modelVersionId, self::APPROVED, $actor, $note);
    }

    public function activate(int $modelVersionId, string $actor, string $note = ''): array
    {
        $result = $this->transition($modelVersionId, self::ACTIVE, $actor, $note);
        if (($result['status'] ?? '') === 'OK') {
            $model = $result['model'] ?? [];
            $this->repo->updateModelVersion((int) $modelVersionId, ['last_evaluated_at' => gmdate('c')]);
            $this->audit?->emit('FOOTBALL_MODEL_ACTIVATED', 'Football model ' . ($model['model_name'] ?? '') . ' ' . ($model['model_version'] ?? '') . ' is now ACTIVE', ['modelVersionId' => $modelVersionId, 'actor' => $actor], $actor);
        }
        return $result;
    }

    public function retire(int $modelVersionId, string $actor, string $reason): array
    {
        return $this->transition($modelVersionId, self::RETIRED, $actor, $reason);
    }

    /**
     * Record measured performance for a version. These numbers must already
     * exist in the settlement-derived report; the registry only stores what it is
     * given and never derives an accuracy itself.
     */
    public function recordEvaluation(int $modelVersionId, array $metrics): array
    {
        $model = $this->repo->findModelVersion($modelVersionId);
        if ($model === null) return ['status' => 'NOT_FOUND', 'reason' => 'Model version ' . $modelVersionId . ' does not exist'];
        $patch = ['last_evaluated_at' => gmdate('c')];
        foreach (['validation_sample_size', 'training_sample_size', 'accuracy', 'log_loss', 'brier_score', 'ece', 'training_dataset_version', 'calibration_version_id'] as $field) {
            if (array_key_exists($field, $metrics) && $metrics[$field] !== null) $patch[$field] = is_numeric($metrics[$field]) ? $metrics[$field] : (string) $metrics[$field];
        }
        $this->repo->updateModelVersion($modelVersionId, $patch);
        return ['status' => 'OK', 'model' => $this->repo->findModelVersion($modelVersionId)];
    }

    /**
     * Only values that can change the raw 1X2 probability distribution belong
     * to model identity. Operational controls decide when/how many rows run; the
     * calibration minimum decides when evidence is sufficient. Neither changes
     * a prediction and neither may split that prediction's settlement history.
     *
     * @return array{maxGoals:int,dixonColesRho:float,marketBlendWeight:float}
     */
    private static function predictionFingerprint(array $configuration): array
    {
        $model = is_array($configuration['model'] ?? null) ? $configuration['model'] : [];
        return [
            'maxGoals' => (int) ($model['maxGoals'] ?? 8),
            'dixonColesRho' => round((float) ($model['dixonColesRho'] ?? -0.06), 8),
            'marketBlendWeight' => round((float) ($model['marketBlendWeight'] ?? 0.35), 8),
        ];
    }

    /** @param array<string,mixed> $spec */
    private function compatibleRegisteredVersion(array $spec): ?array
    {
        $candidates = [];
        $ladder = array_flip(self::STATES);
        foreach ($this->repo->listModelVersions(null, 200) as $row) {
            if (!$this->isCompatibleWithSpec($row, $spec)) continue;
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) continue;
            $settlements = (int) ($this->repo->settlementAggregates(['modelVersionId' => $id])['evaluated'] ?? 0);
            $candidates[] = [
                'row' => $row,
                'active' => (string) ($row['status'] ?? '') === self::ACTIVE ? 1 : 0,
                'settlements' => $settlements,
                'rank' => (int) ($ladder[(string) ($row['status'] ?? self::DRAFT)] ?? 0),
                'id' => $id,
            ];
        }
        usort($candidates, static function (array $a, array $b): int {
            foreach (['active', 'settlements', 'rank', 'id'] as $key) {
                $order = (int) $b[$key] <=> (int) $a[$key];
                if ($order !== 0) return $order;
            }
            return 0;
        });
        return $candidates[0]['row'] ?? null;
    }

    /** @param array<string,mixed> $spec */
    private function isCompatibleWithSpec(array $row, array $spec): bool
    {
        if ((string) ($row['model_name'] ?? '') !== (string) ($spec['model_name'] ?? '')) return false;
        if ((string) ($row['algorithm'] ?? '') !== (string) ($spec['algorithm'] ?? '')) return false;
        if ((string) ($row['feature_version'] ?? '') !== (string) ($spec['feature_version'] ?? '')) return false;
        $parameters = $row['parameters'] ?? null;
        if (is_string($parameters)) $parameters = json_decode($parameters, true);
        if (!is_array($parameters) || !is_array($parameters['model'] ?? null)) return false;
        return self::predictionFingerprint($parameters) === ($spec['prediction_fingerprint'] ?? null);
    }

    private function history(?array $model, array $entry): string
    {
        $entries = is_array($model['lifecycle_history'] ?? null) ? $model['lifecycle_history'] : [];
        if ($entries === [] && !empty($model['lifecycle_history']) && is_string($model['lifecycle_history'])) {
            $entries = json_decode($model['lifecycle_history'], true) ?: [];
        }
        $entries[] = $entry;
        return json_encode(array_slice($entries, -25));
    }
}
