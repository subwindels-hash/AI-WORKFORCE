<?php
namespace AIWorkforce\Football;

use AIWorkforce\Persistence\AuditRepository;
use AIWorkforce\Persistence\FootballRepository;

/**
 * Settlement: the one-way evaluation of a stored prediction against the final
 * score the provider reported.
 *
 * Guarantees:
 *  - only a fixture with `status = FINISHED` AND both scores stored is settled;
 *  - the settlement row is inserted once and never updated (idempotent — a
 *    second run returns the existing row and reports SKIPPED);
 *  - the prediction row itself is copied forward into the settlement (predicted
 *    result, score, probabilities, confidence, data quality, model version,
 *    calibration version) and then frozen; the original is never recomputed;
 *  - a postponed or cancelled fixture is VOID, not a loss, and contributes to
 *    no metric;
 *  - `correct_result` / `correct_exact_score` are derived only here, and only
 *    from the final score. No path can mark a prediction correct before this.
 */
final class SettlementService
{
    public function __construct(private FootballRepository $repo, private ?AuditRepository $audit = null, private ?FixtureSyncService $sync = null) {}

    /**
     * Settle one fixture's open predictions.
     *
     * @return array{status:string, fixtureId:int, settled:int, skipped:int, voided:int, reasons:list<string>}
     */
    public function settleFixture(int $fixtureId, string $executionKey = ''): array
    {
        $fixture = $this->repo->findFixtureById($fixtureId);
        if ($fixture === null) return ['status' => 'NOT_FOUND', 'fixtureId' => $fixtureId, 'settled' => 0, 'skipped' => 0, 'voided' => 0, 'reasons' => ['fixture not stored']];
        $status = strtoupper((string) ($fixture['status'] ?? ''));
        if (in_array($status, ['POSTPONED', 'CANCELLED'], true)) return $this->voidFixture($fixture, 'FIXTURE_' . $status);
        if ($status !== 'FINISHED') {
            return ['status' => 'WAITING_FOR_RESULT', 'fixtureId' => $fixtureId, 'settled' => 0, 'skipped' => 0, 'voided' => 0,
                'reasons' => ['fixture status is ' . $status]];
        }
        $home = $fixture['home_score'];
        $away = $fixture['away_score'];
        if ($home === null || $away === null) {
            return ['status' => 'RESULT_UNAVAILABLE', 'fixtureId' => $fixtureId, 'settled' => 0, 'skipped' => 0, 'voided' => 0,
                'reasons' => ['the provider has not reported a final score for this fixture']];
        }
        $home = (int) $home; $away = (int) $away;
        $actualResult = $home > $away ? 'HOME' : ($home === $away ? 'DRAW' : 'AWAY');
        $predictions = $this->repo->listPredictions(['fixtureId' => $fixtureId], 50);
        $open = array_values(array_filter($predictions, static fn(array $row) => (string) ($row['settlement_state'] ?? 'OPEN') === 'OPEN'));
        if ($open === []) {
            return ['status' => $predictions === [] ? 'NO_PREDICTIONS' : 'ALREADY_SETTLED', 'fixtureId' => $fixtureId,
                'settled' => 0, 'skipped' => count($predictions), 'voided' => 0, 'reasons' => []];
        }
        $settled = 0; $skipped = 0; $created = 0;
        foreach ($open as $prediction) {
            $id = (string) $prediction['id'];
            $result = $this->repo->saveSettlement([
                'prediction_id' => $id,
                'fixture_id' => $fixtureId,
                'actual_home_score' => $home,
                'actual_away_score' => $away,
                'actual_result' => $actualResult,
                'predicted_result' => (string) ($prediction['predicted_result'] ?? ''),
                'predicted_home_score' => $prediction['predicted_home_score'],
                'predicted_away_score' => $prediction['predicted_away_score'],
                'probability_home' => $prediction['probability_home'],
                'probability_draw' => $prediction['probability_draw'],
                'probability_away' => $prediction['probability_away'],
                'confidence' => $prediction['confidence'],
                'data_quality_score' => $prediction['data_quality_score'],
                'model_version_id' => $prediction['model_version_id'],
                'calibration_version_id' => $prediction['calibration_version_id'],
                'correct_result' => self::gradeResult($prediction, $actualResult),
                'correct_exact_score' => self::gradeExactScore($prediction, $home, $away),
                'brier' => self::brier($prediction, $actualResult),
                'log_loss' => self::logLoss($prediction, $actualResult),
                'absolute_goal_error' => self::goalError($prediction, $home, $away),
                'result_source' => 'PROVIDER_FIXTURE',
                'settled_at' => gmdate('c'),
            ]);
            if (!empty($result['created'])) $created++;
            else $skipped++;
            $this->repo->savePrediction(array_merge($prediction, ['id' => $id, 'settlement_state' => 'SETTLED', 'outcome' => mb_substr(sprintf(
                'Final %d–%d (%s). Predicted %s %d–%d. Result %s; exact score %s.',
                $home, $away, self::teamLabel($fixture, $actualResult),
                (string) ($prediction['predicted_result'] ?? ''),
                (int) ($prediction['predicted_home_score'] ?? 0), (int) ($prediction['predicted_away_score'] ?? 0),
                (int) ($result['row']['correct_result'] ?? 0) === 1 ? 'correct' : 'incorrect',
                (int) ($result['row']['correct_exact_score'] ?? 0) === 1 ? 'correct' : 'incorrect'
            ), 0, 600)]));
            $settled++;
        }
        // The fixture itself is stamped once, after every open prediction of
        // that match has been graded.
        if ($settled > 0) $this->repo->markFixtureSettled($fixtureId, gmdate('c'));
        $this->audit?->emit('FOOTBALL_PREDICTIONS_SETTLED', 'Football settlement for ' . ($fixture['home_team'] ?? '') . ' v ' . ($fixture['away_team'] ?? '') . ' (' . $home . '–' . $away . ')', [
            'fixtureId' => $fixtureId, 'settled' => $settled, 'inserted' => $created, 'alreadyPresent' => $skipped,
            'executionKey' => $executionKey, 'actualResult' => $actualResult,
        ], 'system');
        return ['status' => 'SETTLED', 'fixtureId' => $fixtureId, 'settled' => $settled, 'skipped' => $skipped, 'voided' => 0,
            'actual' => ['home' => $home, 'away' => $away, 'result' => $actualResult], 'reasons' => []];
    }

    /**
     * Sweep every finished fixture that still has an open prediction. Called from
     * cron; the idempotent insert keeps a repeated run harmless.
     *
     * @return array{status:string, scanned:int, settled:int, waiting:int, voided:int, errors:list<string>, fixtures:list<array>}
     */
    public function settleDue(int $limit = 200, int $beforeId = 0, string $executionKey = ''): array
    {
        $candidates = $this->repo->listFixtures(['unsettledFinished' => true], max(1, min(500, $limit)));
        $settled = 0; $waiting = 0; $voided = 0; $errors = []; $details = [];
        foreach ($candidates as $fixture) {
            if ($beforeId > 0 && (int) $fixture['id'] >= $beforeId) continue;
            $outcome = $this->settleFixture((int) $fixture['id'], $executionKey);
            switch ((string) $outcome['status']) {
                case 'SETTLED': $settled += (int) ($outcome['settled'] ?? 0); break;
                case 'ALREADY_SETTLED': case 'NO_PREDICTIONS': case 'RESULT_UNAVAILABLE': $waiting++; break;
                case 'FIXTURE_POSTPONED': case 'FIXTURE_CANCELLED': $voided++; break;
                default: $errors[] = (string) $fixture['id'] . ': ' . implode(', ', $outcome['reasons'] ?? [$outcome['status']]);
            }
            $details[] = ['fixtureId' => (int) $fixture['id'], 'externalId' => $fixture['external_id'] ?? null, 'status' => $outcome['status']];
        }
        return ['status' => $settled > 0 ? 'COMPLETED' : 'NOTHING_TO_SETTLE', 'scanned' => count($candidates), 'settled' => $settled,
            'waiting' => $waiting, 'voided' => $voided, 'errors' => $errors, 'fixtures' => array_slice($details, 0, 50)];
    }

    /**
     * @return array{status:string, settled:int, pending:int, voided:int, evaluated:int}
     */
    public function status(): array
    {
        $finished = $this->repo->listFixtures(['unsettledFinished' => true], 500);
        $open = $this->repo->listPredictions(['settlementState' => 'OPEN'], 500);
        return [
            'status' => $finished === [] ? 'CURRENT' : 'PENDING',
            'settled' => count($this->repo->listSettlements([], 5000)),
            'pending' => count($finished),
            'voided' => count(array_filter($finished, static fn(array $row) => in_array(strtoupper((string) ($row['status'] ?? '')), ['POSTPONED', 'CANCELLED'], true))),
            'evaluated' => count($open),
        ];
    }

    /**
     * @return array{status:string, fixtureId:int, settled:int, voided:int, reasons:list<string>}
     */
    private function voidFixture(array $fixture, string $reason): array
    {
        $voided = 0;
        foreach ($this->repo->listPredictions(['fixtureId' => (int) $fixture['id']], 50) as $prediction) {
            if ((string) ($prediction['settlement_state'] ?? 'OPEN') !== 'OPEN') continue;
            $this->repo->savePrediction(array_merge($prediction, ['id' => (string) $prediction['id'], 'settlement_state' => 'VOID',
                'outcome' => 'No settlement: the fixture was ' . strtolower($reason) . ' and no final score was reported.']));
            $voided++;
        }
        $this->audit?->emit('FOOTBALL_PREDICTIONS_VOIDED', 'Football predictions voided for ' . ($fixture['external_id'] ?? $fixture['id']), ['reason' => $reason], 'system');
        return ['status' => 'VOIDED', 'fixtureId' => (int) $fixture['id'], 'settled' => 0, 'skipped' => 0, 'voided' => $voided, 'reasons' => [$reason]];
    }

    /**
     * A prediction without usable probabilities cannot be graded — the settlement
     * row keeps NULL for the grade rather than guessing "wrong".
     */
    private static function gradeResult(array $prediction, string $actualResult): ?int
    {
        $predicted = strtoupper((string) ($prediction['predicted_result'] ?? ''));
        if (!in_array($predicted, ['HOME', 'DRAW', 'AWAY'], true)) return null;
        return $predicted === $actualResult ? 1 : 0;
    }

    private static function gradeExactScore(array $prediction, int $home, int $away): ?int
    {
        $predictedHome = $prediction['predicted_home_score'];
        $predictedAway = $prediction['predicted_away_score'];
        if ($predictedHome === null || $predictedAway === null) return null;
        return ((int) $predictedHome === $home && (int) $predictedAway === $away) ? 1 : 0;
    }

    /** Multi-class Brier score over the stored (displayed) probabilities. */
    private static function brier(array $prediction, string $actualResult): ?float
    {
        $values = [];
        foreach (['HOME' => 'probability_home', 'DRAW' => 'probability_draw', 'AWAY' => 'probability_away'] as $outcome => $column) {
            if (!is_numeric($prediction[$column] ?? null)) return null;
            $values[$outcome] = (float) $prediction[$column];
        }
        $sum = 0.0;
        foreach ($values as $outcome => $value) {
            $sum += ($value - ($outcome === $actualResult ? 1.0 : 0.0)) ** 2;
        }
        return round($sum / 3, 6);
    }

    private static function logLoss(array $prediction, string $actualResult): ?float
    {
        $column = match ($actualResult) { 'HOME' => 'probability_home', 'DRAW' => 'probability_draw', 'AWAY' => 'probability_away', default => null };
        if ($column === null || !is_numeric($prediction[$column] ?? null)) return null;
        $value = max(1e-12, min(1.0, (float) $prediction[$column]));
        return round(-log($value), 6);
    }

    /** Mean absolute error of the predicted scoreline against the final score. */
    private static function goalError(array $prediction, int $home, int $away): ?float
    {
        if ($prediction['predicted_home_score'] === null || $prediction['predicted_away_score'] === null) return null;
        return round((abs((int) $prediction['predicted_home_score'] - $home) + abs((int) $prediction['predicted_away_score'] - $away)) / 2, 3);
    }

    private static function teamLabel(array $fixture, string $actualResult): string
    {
        return match ($actualResult) {
            'HOME' => (string) ($fixture['home_team'] ?? 'home side'),
            'AWAY' => (string) ($fixture['away_team'] ?? 'away side'),
            default => 'draw',
        };
    }
}
