<?php
namespace AIWorkforce\MultiplierIntelligence;

/**
 * Multiplier Intelligence Engine
 * 
 * Core engine that orchestrates all specialized agents to produce
 * predictions with confidence scores and risk assessment.
 * 
 * Architecture:
 *   Live Data → Data Normalization → Feature Extraction → 
 *   Multiple AI Agents → Prediction → Confidence Score → Signal → 
 *   Actual Result → Accuracy Evaluation → Model Performance → 
 *   Model Improvement
 */
class MultiplierIntelligenceEngine
{
    /** @var MultiplierAgentInterface[] */
    private array $agents = [];
    
    private CrashGameProviderInterface $provider;
    
    /** @var callable|null Audit logger */
    private $audit;
    
    /** @var \CI_DB_query_builder */
    private $db;
    
    public function __construct(
        CrashGameProviderInterface $provider,
        $db = null,
        ?callable $audit = null
    ) {
        $this->provider = $provider;
        $this->db = $db;
        $this->audit = $audit;
        $this->registerDefaultAgents();
    }
    
    /**
     * Register all default specialist agents
     */
    private function registerDefaultAgents(): void
    {
        $this->agents = [
            'historical' => new HistoricalAnalysisAgent(),
            'pattern' => new PatternDetectionAgent(),
            'probability' => new ProbabilityAgent(),
            'sequence' => new SequenceAnalysisAgent(),
            'anomaly' => new AnomalyDetectionAgent(),
            'risk' => new RiskAgent(),
            'validation' => new ValidationAgent(),
            'performance' => new PerformanceAgent(),
            'prediction' => new PredictionAgent(),
        ];
    }
    
    /**
     * Register a custom agent
     */
    public function registerAgent(MultiplierAgentInterface $agent): void
    {
        $this->agents[$agent->type()] = $agent;
    }
    
    /**
     * Get all registered agents
     * @return MultiplierAgentInterface[]
     */
    public function agents(): array
    {
        return $this->agents;
    }
    
    /**
     * Generate a prediction signal
     * 
     * This is the main entry point. It:
     * 1. Fetches historical data from provider
     * 2. Extracts features
     * 3. Runs all specialist agents
     * 4. Combines outputs via prediction agent
     * 5. Returns final signal with confidence
     */
    public function generateSignal(?string $modelCode = null): array
    {
        $startTime = microtime(true);
        $signalId = 'sig_' . bin2hex(random_bytes(8));
        
        // 1. Fetch data — never invent rounds if the live feed is empty
        $history = $this->provider->history(200);
        $multipliers = array_column($history, 'multiplier');
        if (count($multipliers) < 5) {
            return [
                'signalId' => $signalId,
                'modelCode' => $modelCode ?? 'MIXED-ENSEMBLE-v1',
                'modelName' => 'Mixed Ensemble',
                'provider' => $this->provider->code(),
                'predictedMultiplier' => null,
                'predictedMin' => null,
                'predictedMax' => null,
                'confidence' => 0,
                'risk' => 'EXTREME',
                'agents' => [],
                'features' => ['count' => count($multipliers)],
                'latencyMs' => round((microtime(true) - $startTime) * 1000),
                'status' => 'NO_DATA',
                'generatedAt' => gmdate('c'),
                'disclaimer' => 'Live crash history unavailable. No demo data is used.',
            ];
        }
        
        // Extract features
        $features = $this->extractFeatures($multipliers);
        
        // 2. Build context for agents
        $context = [
            'multipliers' => $multipliers,
            'rounds' => $history,
            'features' => $features,
            'provider' => $this->provider->code(),
            'currentRound' => $this->provider->latestRound(),
            'isInRound' => $this->provider->isInRound(),
        ];
        
        // 3. Run specialist agents (except prediction and validation)
        $agentOutputs = [];
        foreach ($this->agents as $key => $agent) {
            if (in_array($key, ['prediction', 'validation', 'performance'])) {
                continue;
            }
            
            $agentContext = $context;
            $agentContext['agent_outputs'] = $agentOutputs; // Pass previous outputs
            
            try {
                $output = $agent->analyze($agentContext);
                $output['agent_type'] = $agent->type();
                $output['agent_name'] = $agent->name();
                $agentOutputs[] = $output;
            } catch (\Throwable $e) {
                $this->logError('agent_error', [
                    'agent' => $agent->type(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        // 4. Run prediction agent (executive decision)
        $context['agent_outputs'] = $agentOutputs;
        $prediction = $this->agents['prediction']->analyze($context);
        
        // 5. Get risk assessment
        $riskOutput = null;
        foreach ($agentOutputs as $o) {
            if ($o['agent_type'] === 'risk_assessment') {
                $riskOutput = $o;
                break;
            }
        }
        
        // 6. Build final signal
        $latencyMs = round((microtime(true) - $startTime) * 1000);
        
        $signal = [
            'signalId' => $signalId,
            'modelCode' => $modelCode ?? 'MIXED-ENSEMBLE-v1',
            'modelName' => 'Mixed Ensemble',
            'provider' => $this->provider->code(),
            'predictedMultiplier' => $prediction['estimate'],
            'predictedMin' => $prediction['min'] ?? null,
            'predictedMax' => $prediction['max'] ?? null,
            'confidence' => $prediction['confidence'],
            'risk' => $riskOutput['risk'] ?? 'MEDIUM',
            'riskScore' => $riskOutput['riskScore'] ?? 0.5,
            'agentCount' => count($agentOutputs),
            'agents' => $agentOutputs,
            'features' => $features,
            'latencyMs' => $latencyMs,
            'status' => 'ACTIVE',
            'generatedAt' => gmdate('c'),
            'disclaimer' => 'PREDICTION ONLY - Not financial advice. Past performance does not guarantee future results.',
        ];
        
        // 7. Store prediction if DB available
        if ($this->db) {
            $this->storePrediction($signal, $history);
        }
        
        // 8. Audit log
        $this->auditLog('SIGNAL_GENERATED', [
            'signalId' => $signalId,
            'model' => $signal['modelCode'],
            'prediction' => $prediction['estimate'],
            'confidence' => $prediction['confidence'],
            'risk' => $signal['risk'],
            'agents' => count($agentOutputs),
            'latencyMs' => $latencyMs,
        ]);
        
        return $signal;
    }
    
    /**
     * Extract features from multiplier history
     */
    private function extractFeatures(array $multipliers): array
    {
        if (empty($multipliers)) {
            return [];
        }
        
        $count = count($multipliers);
        $mean = array_sum($multipliers) / $count;
        
        sort($multipliers);
        $median = $count % 2 === 0 
            ? ($multipliers[$count/2 - 1] + $multipliers[$count/2]) / 2 
            : $multipliers[(int)floor($count/2)];
        
        $variance = array_sum(array_map(fn($m) => pow($m - $mean, 2), $multipliers)) / $count;
        $stddev = sqrt($variance);
        
        // Recent features
        $recent5 = array_slice($multipliers, -5);
        $recent10 = array_slice($multipliers, -10);
        $recent20 = array_slice($multipliers, -20);
        
        // Streaks
        $highStreak = 0;
        $lowStreak = 0;
        foreach (array_reverse($multipliers) as $m) {
            if ($m >= 2.0) { $highStreak++; } else { break; }
        }
        foreach (array_reverse($multipliers) as $m) {
            if ($m < 2.0) { $lowStreak++; } else { break; }
        }
        
        return [
            'count' => $count,
            'mean' => round($mean, 4),
            'median' => round($median, 4),
            'stddev' => round($stddev, 4),
            'cv' => $mean > 0 ? round($stddev / $mean, 4) : 0,
            'min' => min($multipliers),
            'max' => max($multipliers),
            'p25' => $this->percentile($multipliers, 25),
            'p75' => $this->percentile($multipliers, 75),
            'recent5_mean' => round(array_sum($recent5) / count($recent5), 4),
            'recent10_mean' => round(array_sum($recent10) / count($recent10), 4),
            'recent20_mean' => round(array_sum($recent20) / count($recent20), 4),
            'highStreak' => $highStreak,
            'lowStreak' => $lowStreak,
            'below2x_ratio' => count(array_filter($multipliers, fn($m) => $m < 2.0)) / $count,
            'above5x_ratio' => count(array_filter($multipliers, fn($m) => $m >= 5.0)) / $count,
        ];
    }
    
    private function percentile(array $arr, int $p): float
    {
        $count = count($arr);
        if ($count === 0) return 0;
        $index = ($p / 100) * ($count - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);
        $weight = $index - $lower;
        return round($arr[$lower] * (1 - $weight) + $arr[$upper] * $weight, 4);
    }
    
    /**
     * Store prediction in database
     */
    private function storePrediction(array $signal, array $history): void
    {
        try {
            // Get provider ID
            $provider = $this->db->get_where('crash_game_providers', ['code' => $signal['provider']])->row_array();
            if (!$provider) return;
            
            // Get model ID
            $model = $this->db->get_where('crash_game_models', ['code' => $signal['modelCode']])->row_array();
            if (!$model) {
                // Create model if not exists
                $this->db->insert('crash_game_models', [
                    'code' => $signal['modelCode'],
                    'name' => $signal['modelName'],
                    'version' => '1.0',
                    'description' => 'Auto-created model',
                ]);
                $modelId = $this->db->insert_id();
            } else {
                $modelId = $model['id'];
            }
            
            $this->db->insert('crash_game_predictions', [
                'model_id' => $modelId,
                'provider_id' => $provider['id'],
                'predicted_multiplier' => $signal['predictedMultiplier'],
                'predicted_min' => $signal['predictedMin'],
                'predicted_max' => $signal['predictedMax'],
                'confidence' => $signal['confidence'],
                'risk_level' => $signal['risk'],
                'signal_type' => 'MULTIPLIER',
                'agents_json' => json_encode($signal['agents']),
                'features_json' => json_encode($signal['features']),
            ]);
            
            // Store agent executions
            $predictionId = $this->db->insert_id();
            foreach ($signal['agents'] as $agentOutput) {
                $this->db->insert('crash_game_agent_executions', [
                    'prediction_id' => $predictionId,
                    'agent_type' => $agentOutput['agent_type'] ?? 'unknown',
                    'agent_name' => $agentOutput['agent_name'] ?? 'unknown',
                    'output_json' => json_encode($agentOutput),
                    'confidence' => $agentOutput['confidence'] ?? null,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logError('store_prediction_failed', ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Validate past predictions against actual results
     */
    public function validatePredictions(): array
    {
        if (!$this->db) {
            return ['ok' => false, 'error' => 'No database'];
        }
        
        $history = $this->provider->history(500);
        $historyByRound = [];
        foreach ($history as $round) {
            $historyByRound[$round['roundId']] = $round;
        }
        
        // Find unvalidated predictions
        $predictions = $this->db
            ->where('validated', 0)
            ->order_by('predicted_at', 'DESC')
            ->limit(100)
            ->get('crash_game_predictions')
            ->result_array();
        
        $validated = 0;
        $totalError = 0;
        
        foreach ($predictions as $p) {
            // Find matching round (we can't match exactly without round_id, 
            // so we validate by time proximity)
            $predictedTime = strtotime($p['predicted_at']);
            
            // Find the next round after prediction
            $matchingRound = null;
            foreach ($history as $round) {
                $roundTime = strtotime($round['startedAt'] ?? $round['crashedAt'] ?? '');
                if ($roundTime > $predictedTime) {
                    $matchingRound = $round;
                    break;
                }
            }
            
            if ($matchingRound) {
                $actual = (float) $matchingRound['multiplier'];
                $predicted = (float) $p['predicted_multiplier'];
                $error = abs($predicted - $actual);
                $errorPct = $actual > 0 ? ($error / $actual) * 100 : 0;
                
                $this->db->where('id', $p['id'])->update('crash_game_predictions', [
                    'actual_multiplier' => $actual,
                    'error_value' => $error,
                    'error_pct' => $errorPct,
                    'validated' => 1,
                    'validated_at' => gmdate('Y-m-d H:i:s'),
                ]);
                
                $validated++;
                $totalError += $errorPct;
            }
        }
        
        return [
            'ok' => true,
            'validated' => $validated,
            'avgErrorPct' => $validated > 0 ? round($totalError / $validated, 2) : 0,
        ];
    }
    
    /**
     * Get accuracy statistics
     */
    public function accuracyStats(int $window = 100): array
    {
        if (!$this->db) {
            return ['available' => false];
        }
        
        $predictions = $this->db
            ->where('validated', 1)
            ->order_by('validated_at', 'DESC')
            ->limit($window)
            ->get('crash_game_predictions')
            ->result_array();
        
        if (empty($predictions)) {
            return ['available' => false, 'message' => 'No validated predictions'];
        }
        
        $accurate20 = 0;
        $accurate50 = 0;
        $errors = [];
        $confidences = [];
        
        foreach ($predictions as $p) {
            $errorPct = (float) ($p['error_pct'] ?? 0);
            $errors[] = $errorPct;
            $confidences[] = (float) $p['confidence'];
            
            if ($errorPct <= 20) $accurate20++;
            if ($errorPct <= 50) $accurate50++;
        }
        
        $count = count($predictions);
        
        return [
            'available' => true,
            'window' => $window,
            'total' => $count,
            'accuracy20' => round($accurate20 / $count * 100, 1),
            'accuracy50' => round($accurate50 / $count * 100, 1),
            'avgError' => round(array_sum($errors) / $count, 2),
            'avgConfidence' => round(array_sum($confidences) / $count, 2),
            'byRisk' => $this->accuracyByRisk($predictions),
        ];
    }
    
    private function accuracyByRisk(array $predictions): array
    {
        $byRisk = [];
        foreach ($predictions as $p) {
            $risk = $p['risk_level'] ?? 'UNKNOWN';
            if (!isset($byRisk[$risk])) {
                $byRisk[$risk] = ['count' => 0, 'accurate20' => 0, 'errors' => []];
            }
            $byRisk[$risk]['count']++;
            if (((float)($p['error_pct'] ?? 100)) <= 20) {
                $byRisk[$risk]['accurate20']++;
            }
            $byRisk[$risk]['errors'][] = (float) ($p['error_pct'] ?? 0);
        }
        
        foreach ($byRisk as $risk => &$data) {
            $data['accuracy'] = $data['count'] > 0 
                ? round($data['accurate20'] / $data['count'] * 100, 1) 
                : 0;
            $data['avgError'] = !empty($data['errors']) 
                ? round(array_sum($data['errors']) / count($data['errors']), 2) 
                : 0;
        }
        
        return $byRisk;
    }
    
    /**
     * Get provider info
     */
    public function provider(): CrashGameProviderInterface
    {
        return $this->provider;
    }
    
    /**
     * Get dashboard data
     */
    public function dashboard(): array
    {
        return [
            'provider' => [
                'code' => $this->provider->code(),
                'name' => $this->provider->name(),
                'health' => $this->provider->health(),
                'metadata' => $this->provider->metadata(),
                'isInRound' => $this->provider->isInRound(),
                'currentMultiplier' => $this->provider->currentMultiplier(),
            ],
            'agents' => array_map(fn($a) => [
                'type' => $a->type(),
                'name' => $a->name(),
                'description' => $a->description(),
            ], array_values($this->agents)),
            'accuracy' => $this->accuracyStats(100),
            'lastSignal' => null, // Will be populated from DB if available
            'history' => $this->provider->history(20),
        ];
    }
    
    private function auditLog(string $type, array $detail): void
    {
        if ($this->audit) {
            try { ($this->audit)($type, $type, $detail); } catch (\Throwable $e) {}
        }
    }
    
    private function logError(string $type, array $detail): void
    {
        $this->auditLog('MULTIPLIER_ERROR_' . strtoupper($type), $detail);
    }
}
