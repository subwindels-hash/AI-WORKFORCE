<?php
namespace AIWorkforce\MultiplierIntelligence;

/**
 * Simulation Provider - Demo Data
 * 
 * Generates realistic crash game data for development and testing.
 * Uses a provably-fair algorithm based on geometric distribution
 * similar to real crash games.
 * 
 * IMPORTANT: This is for DEMONSTRATION ONLY. Real predictions
 * from simulated data have no real-world validity.
 */
class SimulationProvider extends AbstractCrashGameProvider
{
    private array $rounds = [];
    private int $currentRound = 1000;
    private float $currentMultiplier = 1.0;
    private bool $inRound = false;
    private float $crashPoint = 0;
    private float $roundStartTime = 0;
    
    /** House edge (similar to real crash games: 1-3%) */
    private float $houseEdge = 0.02;
    
    public function code(): string
    {
        return 'simulation';
    }
    
    public function name(): string
    {
        return 'Simulation (Demo Data)';
    }
    
    public function isConfigured(): bool
    {
        return true;
    }
    
    public function metadata(): array
    {
        return [
            'game' => 'aviator',
            'mode' => 'simulation',
            'disclaimer' => 'SIMULATION ONLY - Not real game data',
            'houseEdge' => $this->houseEdge,
            'totalRounds' => count($this->rounds),
        ];
    }
    
    /**
     * Generate a crash point using provably-fair algorithm
     * Based on geometric distribution: P(X > x) = (1-houseEdge) / x
     */
    public function generateCrashPoint(): float
    {
        // Use a random value to determine crash point
        // This simulates the distribution of real crash games
        $r = mt_rand() / mt_getrandmax();
        
        // Apply house edge
        if ($r < $this->houseEdge) {
            return 1.0; // Instant crash (house edge)
        }
        
        // Geometric distribution: crash = 1 / (1 - r)
        // With house edge adjustment
        $crash = (1 - $this->houseEdge) / (1 - $r);
        
        // Cap at reasonable maximum (real games often cap at 100x-1000x)
        return min(round($crash, 2), 100.0);
    }
    
    /**
     * Start a new round
     */
    public function startRound(): array
    {
        if ($this->inRound) {
            return $this->currentRoundData();
        }
        
        $this->currentRound++;
        $this->crashPoint = $this->generateCrashPoint();
        $this->currentMultiplier = 1.0;
        $this->roundStartTime = microtime(true);
        $this->inRound = true;
        
        return $this->currentRoundData();
    }
    
    /**
     * Update multiplier (call periodically during round)
     */
    public function updateMultiplier(): array
    {
        if (!$this->inRound) {
            return $this->startRound();
        }
        
        $elapsed = microtime(true) - $this->roundStartTime;
        
        // Multiplier grows exponentially (typical crash game curve)
        // Growth rate: ~0.06 per 100ms, accelerating
        $growthRate = 0.06 + ($elapsed * 0.01);
        $this->currentMultiplier = round(1.0 + ($elapsed * $growthRate * 10), 2);
        
        // Check if we've hit the crash point
        if ($this->currentMultiplier >= $this->crashPoint) {
            return $this->endRound();
        }
        
        return $this->currentRoundData();
    }
    
    /**
     * End the current round
     */
    public function endRound(): array
    {
        if (!$this->inRound) {
            return $this->currentRoundData();
        }
        
        $round = [
            'roundId' => 'SIM-' . $this->currentRound,
            'multiplier' => $this->crashPoint,
            'startedAt' => gmdate('Y-m-d H:i:s', (int) $this->roundStartTime),
            'crashedAt' => gmdate('Y-m-d H:i:s'),
            'durationMs' => round((microtime(true) - $this->roundStartTime) * 1000),
            'gameCode' => 'aviator',
            'verified' => true,
        ];
        
        $this->rounds[] = $round;
        $this->inRound = false;
        
        // Keep only last 1000 rounds in memory
        if (count($this->rounds) > 1000) {
            $this->rounds = array_slice($this->rounds, -1000);
        }
        
        return array_merge($round, ['status' => 'CRASHED']);
    }
    
    /**
     * Get current round data
     */
    private function currentRoundData(): array
    {
        return [
            'roundId' => 'SIM-' . $this->currentRound,
            'currentMultiplier' => $this->currentMultiplier,
            'crashPoint' => $this->crashPoint,
            'inRound' => $this->inRound,
            'elapsedMs' => $this->inRound ? round((microtime(true) - $this->roundStartTime) * 1000) : 0,
            'gameCode' => 'aviator',
            'status' => $this->inRound ? 'IN_PROGRESS' : 'WAITING',
        ];
    }
    
    public function latestRound(): ?array
    {
        return empty($this->rounds) ? null : end($this->rounds);
    }
    
    public function history(int $limit = 100): array
    {
        return array_slice($this->rounds, -$limit);
    }
    
    public function currentMultiplier(): ?float
    {
        return $this->inRound ? $this->currentMultiplier : null;
    }
    
    public function isInRound(): bool
    {
        return $this->inRound;
    }
    
    /**
     * Get all rounds (for analytics)
     */
    public function allRounds(): array
    {
        return $this->rounds;
    }
    
    /**
     * Get distribution statistics
     */
    public function distributionStats(): array
    {
        if (empty($this->rounds)) {
            return ['count' => 0];
        }
        
        $multipliers = array_column($this->rounds, 'multiplier');
        
        return [
            'count' => count($multipliers),
            'mean' => round(array_sum($multipliers) / count($multipliers), 4),
            'median' => $this->median($multipliers),
            'min' => min($multipliers),
            'max' => max($multipliers),
            'p10' => $this->percentile($multipliers, 10),
            'p25' => $this->percentile($multipliers, 25),
            'p75' => $this->percentile($multipliers, 75),
            'p90' => $this->percentile($multipliers, 90),
            'stddev' => $this->stddev($multipliers),
            'below2x' => count(array_filter($multipliers, fn($m) => $m < 2.0)),
            'above10x' => count(array_filter($multipliers, fn($m) => $m >= 10.0)),
        ];
    }
    
    private function median(array $arr): float
    {
        sort($arr);
        $count = count($arr);
        if ($count === 0) return 0;
        $mid = (int) floor($count / 2);
        return $count % 2 === 0 ? ($arr[$mid - 1] + $arr[$mid]) / 2 : $arr[$mid];
    }
    
    private function percentile(array $arr, int $p): float
    {
        sort($arr);
        $count = count($arr);
        if ($count === 0) return 0;
        $index = ($p / 100) * ($count - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);
        $weight = $index - $lower;
        return $arr[$lower] * (1 - $weight) + $arr[$upper] * $weight;
    }
    
    private function stddev(array $arr): float
    {
        $count = count($arr);
        if ($count < 2) return 0;
        $mean = array_sum($arr) / $count;
        $variance = array_sum(array_map(fn($x) => pow($x - $mean, 2), $arr)) / $count;
        return sqrt($variance);
    }
}
