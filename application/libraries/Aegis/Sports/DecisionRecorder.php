<?php
namespace Aegis\Sports;
use Aegis\Persistence\AuditRepository;
use Aegis\Persistence\SportsRepository;

/** Writes immutable decision inputs before a ticket can be presented for approval. */
class DecisionRecorder
{
    public function __construct(private SportsRepository $repo, private AuditRepository $audit) {}
    public function recordPrediction(int $matchId, array $prediction, array $value, array $risk, array $quality, array $factors): string
    {
        $modelId = $this->repo->ensureModelVersion(['modelName' => $prediction['modelName'], 'modelVersion' => $prediction['modelVersion'], 'featureVersion' => $prediction['featureVersion'], 'calibrationVersion' => $prediction['calibrationVersion'] ?? null]);
        $id = 'prd_' . bin2hex(random_bytes(12));
        $this->repo->savePrediction(['id' => $id, 'match_id' => $matchId, 'model_version_id' => $modelId, 'market' => $prediction['market'] ?? 'UNSPECIFIED', 'selection' => $prediction['selection'] ?? 'UNSPECIFIED', 'raw_probability' => $prediction['rawModelProbability'] ?? null, 'calibrated_probability' => $prediction['calibratedProbability'] ?? null, 'implied_probability' => $value['impliedProbability'] ?? null, 'expected_value' => $value['expectedValue'] ?? null, 'confidence' => null, 'risk' => $risk['classification'] ?? 'REJECTED', 'correlation' => $factors['correlation'] ?? 'UNKNOWN', 'data_quality_score' => $quality['score'] ?? 0, 'decision' => $prediction['decision'], 'rejection_reasons' => json_encode($risk['reasons'] ?? [$prediction['reason'] ?? null]), 'factors' => json_encode($factors), 'input_version' => $prediction['featureVersion'] ?? 'unknown', 'created_at' => gmdate('c')]);
        $this->audit->emit('SPORTS_DECISION_RECORDED', 'Sports prediction decision recorded', ['predictionId' => $id, 'matchId' => $matchId, 'decision' => $prediction['decision']]); return $id;
    }
}
