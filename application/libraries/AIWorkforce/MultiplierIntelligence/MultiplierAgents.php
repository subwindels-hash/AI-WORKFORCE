<?php
namespace AIWorkforce\MultiplierIntelligence;

/**
 * Historical Analysis Agent
 * Analyzes historical multiplier data for patterns and distributions.
 */
class HistoricalAnalysisAgent extends AbstractMultiplierAgent
{
    public function type(): string { return 'historical_analysis'; }
    public function name(): string { return 'Historical Analysis Agent'; }
    public function description(): string { return 'Studies previous rounds for distribution patterns'; }
    
    public function analyze(array $context): array
    {
        $multipliers = $this->extractMultipliers($context);
        if (count($multipliers) < 5) {
            return ['estimate' => 2.0, 'confidence' => 0.3, 'reasoning' => 'Insufficient data'];
        }
        
        $mean = $this->mean($multipliers);
        $median = $this->median($multipliers);
        $stddev = $this->stddev($multipliers);
        $p25 = $this->percentile($multipliers, 25);
        $p75 = $this->percentile($multipliers, 75);
        
        // Weighted estimate favoring median (more robust to outliers)
        $estimate = round(($mean * 0.3) + ($median * 0.7), 2);
        $estimate = max(1.01, min($estimate, 50.0));
        
        // Confidence based on data consistency
        $cv = $this->safeDiv($stddev, $mean);
        $confidence = max(0.3, min(0.85, 1.0 - $cv));
        
        return [
            'estimate' => $estimate,
            'min' => round(max(1.01, $p25), 2),
            'max' => round(min(100.0, $p75 * 1.5), 2),
            'confidence' => round($confidence, 2),
            'stats' => [
                'mean' => round($mean, 4),
                'median' => round($median, 4),
                'stddev' => round($stddev, 4),
                'count' => count($multipliers),
                'p25' => round($p25, 2),
                'p75' => round($p75, 2),
            ],
            'reasoning' => 'Based on ' . count($multipliers) . ' historical rounds',
        ];
    }
}

/**
 * Pattern Detection Agent
 * Detects statistical patterns in multiplier sequences.
 */
class PatternDetectionAgent extends AbstractMultiplierAgent
{
    public function type(): string { return 'pattern_detection'; }
    public function name(): string { return 'Pattern Detection Agent'; }
    public function description(): string { return 'Detects statistical patterns in sequences'; }
    
    public function analyze(array $context): array
    {
        $multipliers = $this->extractMultipliers($context);
        if (count($multipliers) < 10) {
            return ['estimate' => 2.0, 'confidence' => 0.25, 'reasoning' => 'Need more data'];
        }
        
        $recent = array_slice($multipliers, -20);
        $patterns = $this->detectPatterns($recent);
        
        // Pattern-based estimate
        $estimate = 2.0;
        $confidence = 0.4;
        
        if ($patterns['streak'] > 0) {
            // Streak of high multipliers
            $estimate = 1.5 + ($patterns['streak'] * 0.3);
            $confidence = min(0.7, 0.4 + ($patterns['streak'] * 0.05));
        } elseif ($patterns['streak'] < 0) {
            // Streak of low multipliers
            $estimate = 1.2;
            $confidence = min(0.65, 0.4 + (abs($patterns['streak']) * 0.05));
        }
        
        if ($patterns['alternating']) {
            $estimate = $patterns['last'] < 2.0 ? 2.5 : 1.5;
            $confidence = 0.5;
        }
        
        return [
            'estimate' => round(max(1.01, min($estimate, 20.0)), 2),
            'confidence' => round($confidence, 2),
            'patterns' => $patterns,
            'reasoning' => 'Pattern analysis: ' . ($patterns['type'] ?? 'none detected'),
        ];
    }
    
    private function detectPatterns(array $recent): array
    {
        $high = array_filter($recent, fn($m) => $m >= 2.0);
        $low = array_filter($recent, fn($m) => $m < 2.0);
        
        // Detect streaks
        $streak = 0;
        $last = end($recent);
        $threshold = 2.0;
        foreach (array_reverse($recent) as $m) {
            if ($m >= $threshold && $last >= $threshold) $streak++;
            elseif ($m < $threshold && $last < $threshold) $streak--;
            else break;
        }
        
        // Detect alternation
        $alternations = 0;
        for ($i = 1; $i < count($recent); $i++) {
            $wasHigh = $recent[$i - 1] >= $threshold;
            $isHigh = $recent[$i] >= $threshold;
            if ($wasHigh !== $isHigh) $alternations++;
        }
        $alternating = $alternations > count($recent) * 0.6;
        
        $type = $streak > 2 ? 'high_streak' : ($streak < -2 ? 'low_streak' : ($alternating ? 'alternating' : 'random'));
        
        return [
            'streak' => $streak,
            'alternating' => $alternating,
            'highCount' => count($high),
            'lowCount' => count($low),
            'highRatio' => count($recent) > 0 ? count($high) / count($recent) : 0.5,
            'last' => $last,
            'type' => $type,
        ];
    }
}

/**
 * Probability Agent
 * Calculates probability distributions.
 */
class ProbabilityAgent extends AbstractMultiplierAgent
{
    public function type(): string { return 'probability'; }
    public function name(): string { return 'Probability Agent'; }
    public function description(): string { return 'Calculates probability distributions'; }
    
    public function analyze(array $context): array
    {
        $multipliers = $this->extractMultipliers($context);
        if (count($multipliers) < 5) {
            return ['estimate' => 2.0, 'confidence' => 0.3, 'reasoning' => 'Insufficient data'];
        }
        
        // Build empirical distribution
        $buckets = $this->buildDistribution($multipliers);
        
        // Find mode (most likely bucket)
        $maxProb = 0;
        $modeBucket = '1.0-2.0';
        foreach ($buckets as $range => $prob) {
            if ($prob > $maxProb) {
                $maxProb = $prob;
                $modeBucket = $range;
            }
        }
        
        // Estimate from mode bucket midpoint
        $parts = explode('-', $modeBucket);
        $estimate = ((float)$parts[0] + (float)$parts[1]) / 2;
        
        // Confidence from how peaked the distribution is
        $entropy = $this->calculateEntropy(array_values($buckets));
        $maxEntropy = log(count($buckets));
        $confidence = max(0.3, min(0.8, 1.0 - ($entropy / $maxEntropy)));
        
        return [
            'estimate' => round($estimate, 2),
            'confidence' => round($confidence, 2),
            'distribution' => $buckets,
            'mode' => $modeBucket,
            'mostLikelyProb' => round($maxProb, 3),
            'reasoning' => "Most likely range: {$modeBucket} ({$maxProb} probability)",
        ];
    }
    
    private function buildDistribution(array $multipliers): array
    {
        $buckets = [
            '1.0-1.5' => 0,
            '1.5-2.0' => 0,
            '2.0-3.0' => 0,
            '3.0-5.0' => 0,
            '5.0-10.0' => 0,
            '10.0-20.0' => 0,
            '20.0-50.0' => 0,
            '50.0+' => 0,
        ];
        
        foreach ($multipliers as $m) {
            if ($m < 1.5) $buckets['1.0-1.5']++;
            elseif ($m < 2.0) $buckets['1.5-2.0']++;
            elseif ($m < 3.0) $buckets['2.0-3.0']++;
            elseif ($m < 5.0) $buckets['3.0-5.0']++;
            elseif ($m < 10.0) $buckets['5.0-10.0']++;
            elseif ($m < 20.0) $buckets['10.0-20.0']++;
            elseif ($m < 50.0) $buckets['20.0-50.0']++;
            else $buckets['50.0+']++;
        }
        
        $total = count($multipliers);
        foreach ($buckets as $k => $v) {
            $buckets[$k] = $total > 0 ? $v / $total : 0;
        }
        
        return $buckets;
    }
    
    private function calculateEntropy(array $probs): float
    {
        $entropy = 0;
        foreach ($probs as $p) {
            if ($p > 0) {
                $entropy -= $p * log($p);
            }
        }
        return $entropy;
    }
}

/**
 * Sequence Analysis Agent
 * Examines recent multiplier sequences for trends.
 */
class SequenceAnalysisAgent extends AbstractMultiplierAgent
{
    public function type(): string { return 'sequence_analysis'; }
    public function name(): string { return 'Sequence Analysis Agent'; }
    public function description(): string { return 'Examines recent multiplier sequences'; }
    
    public function analyze(array $context): array
    {
        $multipliers = $this->extractMultipliers($context);
        if (count($multipliers) < 5) {
            return ['estimate' => 2.0, 'confidence' => 0.25, 'reasoning' => 'Need more data'];
        }
        
        $recent5 = array_slice($multipliers, -5);
        $recent10 = array_slice($multipliers, -10);
        
        // Trend analysis
        $trend5 = $this->calculateTrend($recent5);
        $trend10 = $this->calculateTrend($recent10);
        
        // Moving average convergence/divergence
        $ma5 = $this->mean($recent5);
        $ma10 = $this->mean($recent10);
        
        // Estimate based on trend
        $last = end($multipliers);
        $estimate = $last;
        
        if ($trend5 > 0 && $trend10 > 0) {
            // Uptrend
            $estimate = $last * (1 + ($trend5 * 0.1));
        } elseif ($trend5 < 0 && $trend10 < 0) {
            // Downtrend
            $estimate = max(1.01, $last * (1 + ($trend5 * 0.05)));
        } else {
            // Mixed - use MA
            $estimate = ($ma5 + $ma10) / 2;
        }
        
        $estimate = max(1.01, min($estimate, 30.0));
        
        // Confidence from trend consistency
        $trendConsistency = abs($trend5 - $trend10) < 0.1 ? 0.7 : 0.4;
        $confidence = round($trendConsistency, 2);
        
        return [
            'estimate' => round($estimate, 2),
            'confidence' => $confidence,
            'trend5' => round($trend5, 4),
            'trend10' => round($trend10, 4),
            'ma5' => round($ma5, 2),
            'ma10' => round($ma10, 2),
            'reasoning' => sprintf('Trend: 5r=%.2f, 10r=%.2f', $trend5, $trend10),
        ];
    }
    
    private function calculateTrend(array $values): float
    {
        $count = count($values);
        if ($count < 2) return 0.0;
        
        $xMean = ($count - 1) / 2;
        $yMean = $this->mean($values);
        
        $numerator = 0;
        $denominator = 0;
        foreach ($values as $x => $y) {
            $numerator += ($x - $xMean) * ($y - $yMean);
            $denominator += pow($x - $xMean, 2);
        }
        
        return $denominator != 0 ? $numerator / $denominator : 0.0;
    }
}

/**
 * Anomaly Detection Agent
 * Detects unusual behavior in the data.
 */
class AnomalyDetectionAgent extends AbstractMultiplierAgent
{
    public function type(): string { return 'anomaly_detection'; }
    public function name(): string { return 'Anomaly Detection Agent'; }
    public function description(): string { return 'Detects unusual behavior and outliers'; }
    
    public function analyze(array $context): array
    {
        $multipliers = $this->extractMultipliers($context);
        if (count($multipliers) < 10) {
            return ['estimate' => 2.0, 'confidence' => 0.4, 'reasoning' => 'Insufficient data for anomaly detection'];
        }
        
        $mean = $this->mean($multipliers);
        $stddev = $this->stddev($multipliers);
        $zScores = array_map(fn($m) => $stddev > 0 ? ($m - $mean) / $stddev : 0, $multipliers);
        
        // Detect anomalies (z-score > 2 or < -2)
        $anomalies = [];
        foreach ($zScores as $i => $z) {
            if (abs($z) > 2) {
                $anomalies[] = [
                    'index' => $i,
                    'value' => $multipliers[$i],
                    'zScore' => round($z, 2),
                    'type' => $z > 0 ? 'high' : 'low',
                ];
            }
        }
        
        $anomalyRatio = count($anomalies) / count($multipliers);
        
        // If many anomalies, be conservative
        if ($anomalyRatio > 0.2) {
            return [
                'estimate' => round($mean, 2),
                'confidence' => 0.35,
                'anomalies' => count($anomalies),
                'anomalyRatio' => round($anomalyRatio, 3),
                'reasoning' => 'High anomaly ratio - using conservative mean',
            ];
        }
        
        // Recent anomaly adjustment
        $recentZ = array_slice($zScores, -5);
        $recentAnomalies = array_filter($recentZ, fn($z) => abs($z) > 2);
        
        $estimate = $mean;
        if (!empty($recentAnomalies)) {
            // Recent anomaly detected - be conservative
            $estimate = max(1.5, $mean * 0.8);
        }
        
        return [
            'estimate' => round(max(1.01, min($estimate, 20.0)), 2),
            'confidence' => round(max(0.35, min(0.75, 0.7 - $anomalyRatio)), 2),
            'anomalies' => count($anomalies),
            'anomalyRatio' => round($anomalyRatio, 3),
            'recentAnomalies' => count($recentAnomalies),
            'reasoning' => count($anomalies) . ' anomalies detected in ' . count($multipliers) . ' rounds',
        ];
    }
}

/**
 * Risk Agent
 * Calculates risk metrics for predictions.
 */
class RiskAgent extends AbstractMultiplierAgent
{
    public function type(): string { return 'risk_assessment'; }
    public function name(): string { return 'Risk Assessment Agent'; }
    public function description(): string { return 'Calculates risk level for predictions'; }
    
    public function analyze(array $context): array
    {
        $multipliers = $this->extractMultipliers($context);
        $agentOutputs = $context['agent_outputs'] ?? [];
        
        if (count($multipliers) < 5) {
            return ['risk' => 'HIGH', 'riskScore' => 0.8, 'reasoning' => 'Insufficient data'];
        }
        
        $mean = $this->mean($multipliers);
        $stddev = $this->stddev($multipliers);
        $cv = $this->safeDiv($stddev, $mean);
        
        // Calculate risk from multiple factors
        $volatilityRisk = min(1.0, $cv);
        
        // Confidence spread from agents
        $confidences = array_column($agentOutputs, 'confidence');
        $avgConfidence = !empty($confidences) ? $this->mean($confidences) : 0.5;
        $confidenceRisk = 1.0 - $avgConfidence;
        
        // Recent stability
        $recent5 = array_slice($multipliers, -5);
        $recent10 = array_slice($multipliers, -10);
        $recentStd = $this->stddev($recent5);
        $stabilityRisk = min(1.0, $recentStd / 3);
        
        // Overall risk score (0 = low, 1 = extreme)
        $riskScore = ($volatilityRisk * 0.4) + ($confidenceRisk * 0.35) + ($stabilityRisk * 0.25);
        
        // Risk level
        $risk = match (true) {
            $riskScore < 0.3 => 'LOW',
            $riskScore < 0.5 => 'MEDIUM',
            $riskScore < 0.75 => 'HIGH',
            default => 'EXTREME',
        };
        
        return [
            'risk' => $risk,
            'riskScore' => round($riskScore, 3),
            'components' => [
                'volatility' => round($volatilityRisk, 3),
                'confidence' => round($confidenceRisk, 3),
                'stability' => round($stabilityRisk, 3),
            ],
            'reasoning' => "Risk: {$risk} (score " . round($riskScore * 100) . "%)",
        ];
    }
}

/**
 * Validation Agent
 * Compares predictions with actual results (for learning).
 */
class ValidationAgent extends AbstractMultiplierAgent
{
    public function type(): string { return 'validation'; }
    public function name(): string { return 'Validation Agent'; }
    public function description(): string { return 'Validates predictions against actual results'; }
    
    public function analyze(array $context): array
    {
        $predictions = $context['recent_predictions'] ?? [];
        
        if (empty($predictions)) {
            return ['accuracy' => null, 'reasoning' => 'No validated predictions yet'];
        }
        
        $validated = array_filter($predictions, fn($p) => !empty($p['validated']) && isset($p['actual_multiplier']));
        
        if (empty($validated)) {
            return ['accuracy' => null, 'reasoning' => 'No validated predictions'];
        }
        
        $errors = [];
        $accurateWithin20 = 0;
        $accurateWithin50 = 0;
        
        foreach ($validated as $p) {
            $predicted = (float) $p['predicted_multiplier'];
            $actual = (float) $p['actual_multiplier'];
            $error = abs($predicted - $actual);
            $errorPct = $actual > 0 ? ($error / $actual) * 100 : 0;
            $errors[] = $errorPct;
            
            if ($errorPct <= 20) $accurateWithin20++;
            if ($errorPct <= 50) $accurateWithin50++;
        }
        
        $count = count($validated);
        
        return [
            'accuracy' => round($accurateWithin20 / $count * 100, 1),
            'accuracy50' => round($accurateWithin50 / $count * 100, 1),
            'avgErrorPct' => round($this->mean($errors), 2),
            'totalValidated' => $count,
            'reasoning' => "{$accurateWithin20}/{$count} within 20% accuracy",
        ];
    }
}

/**
 * Performance Agent
 * Tracks model performance over time.
 */
class PerformanceAgent extends AbstractMultiplierAgent
{
    public function type(): string { return 'performance'; }
    public function name(): string { return 'Performance Tracking Agent'; }
    public function description(): string { return 'Tracks prediction accuracy over time'; }
    
    public function analyze(array $context): array
    {
        $performance = $context['performance_history'] ?? [];
        
        if (empty($performance)) {
            return ['trend' => 'UNKNOWN', 'reasoning' => 'No performance history'];
        }
        
        $recent = array_slice($performance, -10);
        $older = array_slice($performance, -50, -10);
        
        $recentAccuracy = $this->mean(array_column($recent, 'accuracy'));
        $olderAccuracy = !empty($older) ? $this->mean(array_column($older, 'accuracy')) : null;
        
        $trend = 'STABLE';
        if ($olderAccuracy !== null) {
            $delta = $recentAccuracy - $olderAccuracy;
            if ($delta > 5) $trend = 'IMPROVING';
            elseif ($delta < -5) $trend = 'DECLINING';
        }
        
        return [
            'recentAccuracy' => round($recentAccuracy, 2),
            'olderAccuracy' => $olderAccuracy !== null ? round($olderAccuracy, 2) : null,
            'trend' => $trend,
            'sampleSize' => count($performance),
            'reasoning' => "Trend: {$trend} (recent: " . round($recentAccuracy, 1) . "%)",
        ];
    }
}

/**
 * Prediction Agent (Executive)
 * Combines all agent outputs into final prediction.
 */
class PredictionAgent extends AbstractMultiplierAgent
{
    public function type(): string { return 'prediction'; }
    public function name(): string { return 'Executive Prediction Agent'; }
    public function description(): string { return 'Combines agent outputs into final prediction'; }
    
    /** Agent weights for ensemble */
    private const WEIGHTS = [
        'historical_analysis' => 0.25,
        'pattern_detection' => 0.15,
        'probability' => 0.20,
        'sequence_analysis' => 0.20,
        'anomaly_detection' => 0.10,
        'risk_assessment' => 0.10,
    ];
    
    public function analyze(array $context): array
    {
        $agentOutputs = $context['agent_outputs'] ?? [];
        
        if (empty($agentOutputs)) {
            return ['estimate' => 2.0, 'confidence' => 0.3, 'reasoning' => 'No agent data'];
        }
        
        // Weighted ensemble
        $weightedSum = 0;
        $weightedConfidence = 0;
        $totalWeight = 0;
        
        foreach ($agentOutputs as $output) {
            $type = $output['agent_type'] ?? '';
            $weight = self::WEIGHTS[$type] ?? 0.1;
            $confidence = (float) ($output['confidence'] ?? 0.5);
            $estimate = (float) ($output['estimate'] ?? 2.0);
            
            // Weight by both fixed weight and confidence
            $effectiveWeight = $weight * $confidence;
            
            $weightedSum += $estimate * $effectiveWeight;
            $weightedConfidence += $confidence * $effectiveWeight;
            $totalWeight += $effectiveWeight;
        }
        
        $estimate = $totalWeight > 0 ? $weightedSum / $totalWeight : 2.0;
        $confidence = $totalWeight > 0 ? $weightedConfidence / $totalWeight : 0.5;
        
        // Adjust confidence based on agent agreement
        $estimates = array_column($agentOutputs, 'estimate');
        $agreement = $this->calculateAgreement($estimates);
        $confidence *= $agreement;
        
        // Apply risk adjustment
        $riskOutput = null;
        foreach ($agentOutputs as $o) {
            if (($o['agent_type'] ?? '') === 'risk_assessment') {
                $riskOutput = $o;
                break;
            }
        }
        
        if ($riskOutput && ($riskOutput['risk'] ?? 'MEDIUM') === 'EXTREME') {
            $confidence *= 0.7; // Reduce confidence on extreme risk
        }
        
        // Determine range
        $stddev = !empty($estimates) ? $this->stddev($estimates) : 0.5;
        $min = round(max(1.01, $estimate - $stddev), 2);
        $max = round(min(100.0, $estimate + $stddev * 1.5), 2);
        
        return [
            'estimate' => round($estimate, 2),
            'min' => $min,
            'max' => $max,
            'confidence' => round(min(0.95, max(0.2, $confidence)), 2),
            'agreement' => round($agreement, 2),
            'agentCount' => count($agentOutputs),
            'reasoning' => sprintf('Ensemble of %d agents (agreement: %.0f%%)', count($agentOutputs), $agreement * 100),
        ];
    }
    
    private function calculateAgreement(array $estimates): float
    {
        if (count($estimates) < 2) return 0.5;
        
        $mean = $this->mean($estimates);
        if ($mean === 0.0) return 0.5;
        
        $cv = $this->stddev($estimates) / $mean;
        // Lower CV = higher agreement
        return max(0.3, min(1.0, 1.0 - $cv));
    }
}
