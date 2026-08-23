<?php
namespace Aegis\Sports;

use Aegis\Sports\Providers\SportsProviderManager;
use Aegis\Persistence\AuditRepository;
use Aegis\Persistence\SportsRepository;

/** Phase 2 sports service container. Starts with no provider rather than demo/fabricated fixtures. */
class SportsIntelligence
{
    public readonly SportsProviderManager $providers;
    public readonly DataQualityEngine $quality;
    public readonly OddsFreshnessEngine $oddsFreshness;
    public readonly MatchIntelligenceEngine $matchIntelligence;
    public readonly FeatureEngineeringEngine $features;
    public readonly PredictionEngine $predictions;
    public readonly SportsSyncService $sync;
    public function __construct(SportsRepository $repository, AuditRepository $audit)
    {
        $this->providers = new SportsProviderManager();
        $this->quality = new DataQualityEngine();
        $this->oddsFreshness = new OddsFreshnessEngine();
        $this->matchIntelligence = new MatchIntelligenceEngine($this->oddsFreshness);
        $this->features = new FeatureEngineeringEngine();
        $this->predictions = new PredictionEngine();
        $this->sync = new SportsSyncService($repository, $audit, $this->quality);
    }
    public function status(): array
    {
        return [
            'module' => 'WINDELS Sports Intelligence', 'enabled' => getenv('WINDELS_SPORTS_ENABLED') === '1',
            'mode' => getenv('WINDELS_SPORTS_MODE') ?: 'SANDBOX',
            'providersConfigured' => $this->providers->configured(), 'providers' => $this->providers->health(),
            'ticketEngine' => 'DISABLED_NO_PROVIDER',
            'message' => 'No sports provider is configured. No fixtures, odds, predictions, or tickets are fabricated.',
        ];
    }
}
