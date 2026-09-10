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
 * Schema constraint that must never be broken again: the deployed
 * sports_calibrations.method column was VARCHAR(16), and the previous
 * marker 'identity-bootstrap' is 18 characters — MySQL truncated (or, in
 * strict mode, rejected) the row, the read-back never matched, and the
 * engine's own cold-start break could never complete (the
 * BOOTSTRAP_UNREADABLE lock-out of the 2026-09-10 run). The marker is
 * therefore kept ≤ 16 characters, every write is verified against a
 * read-back, and identity rows are recognised by prefix so legacy
 * truncated rows are reused and healed instead of duplicated.
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
            if (self::isIdentityMethod((string) ($pending['method'] ?? ''))) {
                return ['ok' => false, 'reason' => 'IDENTITY_ALREADY_PENDING', 'calibrationId' => (int) ($pending['id'] ?? 0), 'modelVersionId' => $modelId, 'status' => 'PENDING'];
            }
        }

        $dbError = null;
        try {
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
                // 'Y-m-d H:i:s' UTC: the literal MySQL DATETIME / PostgreSQL
                // TIMESTAMP columns accept on every driver (the repository
                // normalises any RFC-3339 value anyway, but the bootstrap
                // itself writes the canonical form).
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // The repository no longer swallows a failed write (db_debug=
            // false): it throws with the driver's real code/message. Carry
            // that exact error so an operator fixes the DATABASE (schema,
            // constraint, grant), not the engine's gate.
            $id = 0;
            $dbError = mb_substr($e->getMessage(), 0, 500);
        }
        // Verify the row survived the database round-trip BEFORE reporting
        // success. A driver that silently truncates or swallows a failed
        // insert (CI3 db_debug=false) must produce an honest failure here —
        // not a phantom calibration id the caller would chase forever.
        $stored = $id > 0 ? $this->repo->findCalibration($id) : null;
        if ($stored === null
            || !self::isIdentityMethod((string) ($stored['method'] ?? ''))
            || strtoupper((string) ($stored['status'] ?? '')) !== 'PENDING') {
            $this->audit->emit(
                'SPORTS_CALIBRATION_PERSIST_FAIL',
                'Identity calibration bootstrap did not survive the database round-trip (check the sports_calibrations schema — the method column must fit the marker — and the DB error log)',
                ['modelVersionId' => $modelId, 'insertId' => $id, 'storedMethod' => $stored['method'] ?? null, 'storedStatus' => $stored['status'] ?? null, 'dbError' => $dbError],
                $actor
            );
            return ['ok' => false, 'reason' => 'CALIBRATION_PERSIST_FAILED', 'modelVersionId' => $modelId, 'dbError' => $dbError];
        }
        $this->audit->emit(
            'SPORTS_CALIBRATION_BOOTSTRAPPED',
            'Identity calibration bootstrap created (PENDING approval) for ' . PredictionEngine::MODEL_NAME . ' ' . PredictionEngine::MODEL_VERSION,
            ['calibrationId' => $id, 'modelVersionId' => $modelId, 'intercept' => 0.0, 'slope' => 1.0],
            $actor
        );
        return ['ok' => true, 'calibrationId' => $id, 'modelVersionId' => $modelId, 'status' => 'PENDING'];
    }

    /**
     * Distinct from fitted ('platt') rows so the bootstrap is recognizable.
     * Must stay within the NARROWEST deployed method column (VARCHAR(16)) —
     * deployments whose schema predates the widening must keep working
     * un-migrated, so this marker may never rely on the new 32-char width.
     */
    public static function method(): string
    {
        return 'identity';
    }

    /**
     * Identity rows are recognised by prefix, never by exact equality: the
     * old 18-char marker may exist stored in full (SQLite/Postgres dev
     * databases) or truncated to the column width ('identity-bootstr') on
     * MySQL installs written before the fix. Any of these is the same
     * bootstrap row — reuse and heal it, never duplicate it.
     */
    public static function isIdentityMethod(string $storedMethod): bool
    {
        return str_starts_with(trim($storedMethod), self::method());
    }
}
