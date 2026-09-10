<?php
namespace AIWorkforce\Sports;

use AIWorkforce\Persistence\AuditRepository;
use AIWorkforce\Persistence\SportsRepository;

/**
 * Calibration bootstrap for a fresh deployment.
 *
 * The ticket engine never predicts without an APPROVED calibration
 * (PredictionEngine rejects MODEL_NOT_CALIBRATED), and a real Platt
 * calibration can only be fitted from 20+ SETTLED stored predictions —
 * which the ticket engine is the only writer of. A fresh installation is
 * therefore locked out until an operator breaks the cycle explicitly.
 *
 * This service is that explicit break: it registers an IDENTITY calibration
 * (intercept 0, slope 1 — "use the raw model probability unchanged", the
 * same mapping the backtester uses for its labeled identity replay) with
 * status PENDING. It never approves anything itself: the existing
 * approve/reject flow (admin act, recorded actor) decides it, exactly like
 * a fitted calibration. Nothing is fabricated — an identity mapping adds no
 * information, it only removes the cold-start deadlock.
 *
 * The daily ticket engine invokes this bootstrap itself when a run starts
 * without an APPROVED calibration (DailyTicketService::ensureIdentityCalibration)
 * and auto-approves the resulting IDENTITY row as an audited system act —
 * the only auto-approved calibration in the system; fitted calibrations
 * stay a human decision, and an admin-rejected bootstrap is never
 * resurrected. Manual use of this endpoint and the
 * api/sports/calibrations/{id}/approve flow remain available.
 *
 * Once enough predictions have settled, fit a real calibration
 * (api/sports/calibrations/fit) and approve it; the newest APPROVED row
 * wins, so the bootstrap naturally retires itself.
 */
final class CalibrationBootstrap
{
    public function __construct(private SportsRepository $repo, private AuditRepository $audit) {}

    /**
     * Create a PENDING identity calibration for the deployed model version.
     *
     * @return array{ok:bool, reason?:string, calibrationId?:int, modelVersionId?:int, status?:string}
     */
    public function bootstrapIdentity(string $actor): array
    {
        $modelId = $this->repo->ensureModelVersion([
            'modelName' => PredictionEngine::MODEL_NAME,
            'modelVersion' => PredictionEngine::MODEL_VERSION,
            'featureVersion' => FeatureEngineeringEngine::VERSION,
        ]);

        if ($this->repo->activeCalibration($modelId) !== null) {
            return ['ok' => false, 'reason' => 'APPROVED_CALIBRATION_EXISTS', 'modelVersionId' => $modelId];
        }

        // Never stack duplicate bootstrap rows: one pending identity is enough.
        foreach ($this->repo->listCalibrations($modelId, 'PENDING') as $pending) {
            if ((string) ($pending['method'] ?? '') === self::method()) {
                return ['ok' => false, 'reason' => 'IDENTITY_ALREADY_PENDING', 'calibrationId' => (int) ($pending['id'] ?? 0), 'modelVersionId' => $modelId, 'status' => 'PENDING'];
            }
        }

        $id = $this->repo->saveCalibration([
            'model_version_id' => $modelId,
            'method' => self::method(),
            'intercept' => 0.0,
            'slope' => 1.0,
            'brier' => null,
            'ece' => null,
            'samples' => 0,
            'bins' => json_encode([]),
            'status' => 'PENDING',
            'created_by' => $actor,
            'created_at' => gmdate('c'),
        ]);
        $this->audit->emit(
            'SPORTS_CALIBRATION_BOOTSTRAPPED',
            'Identity calibration bootstrap created (PENDING approval) for ' . PredictionEngine::MODEL_NAME . ' ' . PredictionEngine::MODEL_VERSION,
            ['calibrationId' => $id, 'modelVersionId' => $modelId, 'intercept' => 0.0, 'slope' => 1.0],
            $actor
        );
        return ['ok' => true, 'calibrationId' => $id, 'modelVersionId' => $modelId, 'status' => 'PENDING'];
    }

    /** Distinct from fitted ('platt') rows so the bootstrap is recognizable. */
    public static function method(): string
    {
        return 'identity-bootstrap';
    }
}
