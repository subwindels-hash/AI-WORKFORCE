<?php
namespace AIWorkforce\MultiplierIntelligence;

/**
 * Aviator Provider (Demo Implementation)
 * 
 * This is a DEMONSTRATION provider showing the structure for integrating
 * with a real Aviator crash game API. In production, this would connect
 * to the actual Aviator game server (Spribe's Aviator or similar).
 * 
 * Real Aviator API would provide:
 * - Live multiplier updates (every 100ms)
 * - Round history (last 100-500 rounds)
 * - Round status (WAITING, IN_PROGRESS, CRASHED)
 * - Provably fair hash verification
 * - Player bet data (optional)
 * 
 * Architecture for Production:
 * ┌─────────────────────────────────────────────────────────┐
 * │  Real Aviator Game Server (Spribe/Provider)             │
 * │  • WebSocket API for live updates                       │
 * │  • REST API for history                                 │
 * │  • Provably fair verification                           │
 * └────────────────────────┬────────────────────────────────┘
 *                          │
 *                          ▼
 * ┌─────────────────────────────────────────────────────────┐
 * │  AviatorProvider (This Class)                           │
 * │  • Fetches live multiplier via WebSocket                │
 * │  • Fetches round history via REST                       │
 * │  • Implements CrashGameProviderInterface                │
 * └────────────────────────┬────────────────────────────────┘
 *                          │
 *                          ▼
 * ┌─────────────────────────────────────────────────────────┐
 * │  Multiplier Intelligence Engine                         │
 * │  • 9 specialist agents                                  │
 * │  • Real-time analysis                                   │
 * │  • Prediction generation                                │
 * └─────────────────────────────────────────────────────────┘
 * 
 * For DEMO purposes, this provider simulates the Aviator API
 * structure with realistic data patterns.
 */
class AviatorProvider implements CrashGameProviderInterface
{
    private string $code = 'aviator';
    private string $name = 'Aviator (Demo)';
    
    /** @var array Round history */
    private array $rounds = [];
    
    /** @var int Maximum rounds to keep in memory */
    private int $maxRounds = 500;
    
    /** @var float House edge (3% for Aviator) */
    private float $houseEdge = 0.03;
    
    /** @var string|null Current round ID */
    private ?string $currentRoundId = null;
    
    /** @var bool Whether a round is in progress */
    private bool $inRound = false;
    
    /** @var float Current multiplier (if in round) */
    private float $currentMultiplier = 1.0;
    
    /** @var int Round start time (microseconds) */
    private int $roundStartTime = 0;
    
    /** @var float Crash point for current round */
    private float $crashPoint = 0.0;
    
    /** @var string|null API key for real Aviator API */
    private ?string $apiKey;
    
    /** @var string Base URL for Aviator API */
    private string $baseUrl;
    
    /** @var bool Use real API or demo mode */
    private bool $demoMode;
    
    public function __construct(?string $apiKey = null, string $baseUrl = '', bool $demoMode = true)
    {
        $this->apiKey = $apiKey;
        $this->baseUrl = $baseUrl ?: 'https://aviator-api.example.com';
        $this->demoMode = $demoMode || empty($apiKey);
        
        // Generate initial history for demo
        if ($this->demoMode) {
            $this->generateInitialHistory(100);
        }
    }
    
    public function code(): string
    {
        return $this->code;
    }
    
    public function name(): string
    {
        return $this->name;
    }
    
    /**
     * Get round history
     */
    public function history(int $limit = 100): array
    {
        return array_slice($this->rounds, -$limit);
    }
    
    /**
     * Get all rounds
     */
    public function allRounds(): array
    {
        return $this->rounds;
    }
    
    /**
     * Start a new round
     */
    public function startRound(): string
    {
        if ($this->inRound) {
            throw new \RuntimeException('Round already in progress');
        }
        
        $this->currentRoundId = 'aviator_' . bin2hex(random_bytes(8));
        $this->inRound = true;
        $this->currentMultiplier = 1.0;
        $this->roundStartTime = (int)(microtime(true) * 1000000);
        
        // Generate crash point using geometric distribution
        // P(X > x) = (1 - houseEdge) / x
        $this->crashPoint = $this->generateCrashPoint();
        
        return $this->currentRoundId;
    }
    
    /**
     * Update multiplier (called during round)
     */
    public function updateMultiplier(): array
    {
        if (!$this->inRound) {
            return [
                'currentMultiplier' => 1.0,
                'roundId' => $this->currentRoundId,
                'inRound' => false,
                'elapsedMs' => 0,
            ];
        }
        
        // Calculate elapsed time
        $elapsed = (int)((microtime(true) * 1000000 - $this->roundStartTime) / 1000);
        
        // Multiplier increases exponentially: 1.0 * e^(0.00006 * elapsed)
        // This gives ~1.06x per second, reaching 2x in ~11 seconds
        $this->currentMultiplier = 1.0 * exp(0.00006 * $elapsed);
        
        // Check if we've hit crash point
        if ($this->currentMultiplier >= $this->crashPoint) {
            return $this->endRound();
        }
        
        return [
            'currentMultiplier' => round($this->currentMultiplier, 2),
            'roundId' => $this->currentRoundId,
            'inRound' => true,
            'elapsedMs' => $elapsed,
            'crashPoint' => round($this->crashPoint, 2),
        ];
    }
    
    /**
     * End the current round
     */
    public function endRound(): array
    {
        if (!$this->inRound) {
            throw new \RuntimeException('No round in progress');
        }
        
        $elapsed = (int)((microtime(true) * 1000000 - $this->roundStartTime) / 1000);
        
        $round = [
            'roundId' => $this->currentRoundId,
            'multiplier' => round($this->crashPoint, 2),
            'timestamp' => gmdate('c'),
            'elapsedMs' => $elapsed,
            'hash' => $this->generateProvablyFairHash(),
        ];
        
        $this->rounds[] = $round;
        
        // Keep only last N rounds
        if (count($this->rounds) > $this->maxRounds) {
            $this->rounds = array_slice($this->rounds, -$this->maxRounds);
        }
        
        $this->inRound = false;
        $this->currentRoundId = null;
        $this->currentMultiplier = 1.0;
        $this->crashPoint = 0.0;
        
        return $round;
    }
    
    /**
     * Get latest round
     */
    public function latestRound(): ?array
    {
        return !empty($this->rounds) ? end($this->rounds) : null;
    }
    
    /**
     * Check if round is in progress
     */
    public function isInRound(): bool
    {
        return $this->inRound;
    }

    /**
     * CrashGameProviderInterface: the demo runtime is always ready; the
     * production API path is ready once an API key is present.
     */
    public function isConfigured(): bool
    {
        return $this->demoMode || !empty($this->apiKey);
    }

    /**
     * CrashGameProviderInterface: live multiplier while a round is running.
     */
    public function currentMultiplier(): ?float
    {
        return $this->inRound ? round($this->currentMultiplier, 2) : null;
    }

    /**
     * CrashGameProviderInterface: game metadata.
     */
    public function metadata(): array
    {
        return [
            'game' => 'aviator',
            'mode' => $this->demoMode ? 'demo' : 'production',
            'disclaimer' => 'DEMO ONLY - Simulated Aviator data, not real game data',
            'houseEdge' => $this->houseEdge,
            'totalRounds' => count($this->rounds),
            'baseUrl' => $this->baseUrl,
        ];
    }

    /**
     * Generate crash point using geometric distribution
     * This mimics real Aviator's provably fair algorithm
     */
    private function generateCrashPoint(): float
    {
        // Geometric distribution: P(X > x) = (1 - houseEdge) / x
        // Inverse: X = (1 - houseEdge) / U where U ~ Uniform(0, 1)
        $u = mt_rand() / mt_getrandmax();
        $crashPoint = (1 - $this->houseEdge) / $u;
        
        // Minimum crash point is 1.01x (instant crash is rare)
        return max(1.01, min($crashPoint, 1000.0));
    }
    
    /**
     * Generate provably fair hash (demo)
     * In production, this would be the actual hash from the game server
     */
    private function generateProvablyFairHash(): string
    {
        $seed = bin2hex(random_bytes(16));
        return hash('sha256', $seed . $this->currentRoundId);
    }
    
    /**
     * Generate initial round history
     */
    private function generateInitialHistory(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $roundId = 'aviator_' . bin2hex(random_bytes(8));
            $crashPoint = $this->generateCrashPoint();
            $elapsed = (int)(log($crashPoint) / 0.00006); // Reverse the formula
            
            $this->rounds[] = [
                'roundId' => $roundId,
                'multiplier' => round($crashPoint, 2),
                'timestamp' => gmdate('c', time() - ($count - $i) * 10),
                'elapsedMs' => $elapsed,
                'hash' => hash('sha256', $roundId . $crashPoint),
            ];
        }
    }
    
    /**
     * Health check
     */
    public function health(): array
    {
        return [
            'status' => $this->demoMode ? 'DEMO' : 'ONLINE',
            'mode' => $this->demoMode ? 'demo' : 'production',
            'rounds' => count($this->rounds),
            'inRound' => $this->inRound,
            'houseEdge' => $this->houseEdge,
            'apiKey' => $this->apiKey ? 'configured' : 'not_configured',
        ];
    }
    
    /**
     * Get provider statistics
     */
    public function stats(): array
    {
        $multipliers = array_column($this->rounds, 'multiplier');
        $count = count($multipliers);
        
        if ($count === 0) {
            return ['error' => 'No rounds available'];
        }
        
        sort($multipliers);
        $mean = array_sum($multipliers) / $count;
        $median = $multipliers[(int)($count / 2)];
        
        $variance = 0;
        foreach ($multipliers as $m) {
            $variance += ($m - $mean) ** 2;
        }
        $stddev = sqrt($variance / $count);
        
        return [
            'total_rounds' => $count,
            'mean' => round($mean, 2),
            'median' => round($median, 2),
            'stddev' => round($stddev, 2),
            'min' => round(min($multipliers), 2),
            'max' => round(max($multipliers), 2),
            'p25' => round($multipliers[(int)($count * 0.25)], 2),
            'p75' => round($multipliers[(int)($count * 0.75)], 2),
            'house_edge' => $this->houseEdge,
            'distribution' => 'geometric',
        ];
    }
}
