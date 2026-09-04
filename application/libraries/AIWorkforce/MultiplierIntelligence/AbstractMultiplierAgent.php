<?php
namespace AIWorkforce\MultiplierIntelligence;

/**
 * Multiplier Intelligence Agent Interface
 * 
 * All specialized agents must implement this interface.
 */
interface MultiplierAgentInterface
{
    /** Agent type identifier */
    public function type(): string;
    
    /** Human-readable agent name */
    public function name(): string;
    
    /** Agent description */
    public function description(): string;
    
    /** Analyze data and return agent output */
    public function analyze(array $context): array;
}

/**
 * Abstract base for multiplier agents
 */
abstract class AbstractMultiplierAgent implements MultiplierAgentInterface
{
    protected array $config = [];
    
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }
    
    /**
     * Safe division avoiding division by zero
     */
    protected function safeDiv(float $numerator, float $denominator, float $default = 0.0): float
    {
        return $denominator != 0 ? $numerator / $denominator : $default;
    }
    
    /**
     * Calculate mean of array
     */
    protected function mean(array $values): float
    {
        return empty($values) ? 0.0 : array_sum($values) / count($values);
    }
    
    /**
     * Calculate median
     */
    protected function median(array $values): float
    {
        if (empty($values)) return 0.0;
        sort($values);
        $count = count($values);
        $mid = (int) floor($count / 2);
        return $count % 2 === 0 ? ($values[$mid - 1] + $values[$mid]) / 2 : $values[$mid];
    }
    
    /**
     * Calculate standard deviation
     */
    protected function stddev(array $values): float
    {
        $count = count($values);
        if ($count < 2) return 0.0;
        $mean = $this->mean($values);
        $variance = array_sum(array_map(fn($x) => pow($x - $mean, 2), $values)) / $count;
        return sqrt($variance);
    }
    
    /**
     * Get percentile
     */
    protected function percentile(array $values, int $p): float
    {
        if (empty($values)) return 0.0;
        sort($values);
        $count = count($values);
        $index = ($p / 100) * ($count - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);
        $weight = $index - $lower;
        return $values[$lower] * (1 - $weight) + $values[$upper] * $weight;
    }
    
    /**
     * Extract multiplier history from context
     */
    protected function extractMultipliers(array $context): array
    {
        if (isset($context['multipliers']) && is_array($context['multipliers'])) {
            return $context['multipliers'];
        }
        if (isset($context['rounds']) && is_array($context['rounds'])) {
            return array_column($context['rounds'], 'multiplier');
        }
        return [];
    }
}
