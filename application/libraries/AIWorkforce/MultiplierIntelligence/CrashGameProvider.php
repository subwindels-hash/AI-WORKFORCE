<?php
namespace AIWorkforce\MultiplierIntelligence;

/**
 * Crash Game Provider Interface
 * 
 * Provider-agnostic interface for crash game data ingestion.
 * All adapters must implement this interface.
 */
interface CrashGameProviderInterface
{
    /** Unique provider code */
    public function code(): string;
    
    /** Human-readable name */
    public function name(): string;
    
    /** Check if provider is configured and ready */
    public function isConfigured(): bool;
    
    /** Get current health status */
    public function health(): array;
    
    /** Get latest round data */
    public function latestRound(): ?array;
    
    /** Get historical rounds */
    public function history(int $limit = 100): array;
    
    /** Get current multiplier (live) */
    public function currentMultiplier(): ?float;
    
    /** Check if a round is currently in progress */
    public function isInRound(): bool;
    
    /** Get game metadata */
    public function metadata(): array;
}

/**
 * Abstract base class for crash game providers
 */
abstract class AbstractCrashGameProvider implements CrashGameProviderInterface
{
    protected array $config = [];
    protected ?array $lastHealth = null;
    
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }
    
    public function health(): array
    {
        if ($this->lastHealth === null) {
            $start = microtime(true);
            try {
                $this->latestRound();
                $this->lastHealth = [
                    'status' => 'HEALTHY',
                    'latencyMs' => round((microtime(true) - $start) * 1000),
                    'checkedAt' => gmdate('c'),
                ];
            } catch (\Throwable $e) {
                $this->lastHealth = [
                    'status' => 'DOWN',
                    'latencyMs' => round((microtime(true) - $start) * 1000),
                    'error' => $e->getMessage(),
                    'checkedAt' => gmdate('c'),
                ];
            }
        }
        return $this->lastHealth;
    }
}
