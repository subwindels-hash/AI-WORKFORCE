<?php
namespace Aegis\Sports;

use Aegis\Sports\Providers\SportsProviderManager;

/** Phase 2 sports service container. Starts with no provider rather than demo/fabricated fixtures. */
class SportsIntelligence
{
    public readonly SportsProviderManager $providers;
    public readonly DataQualityEngine $quality;
    public function __construct()
    {
        $this->providers = new SportsProviderManager();
        $this->quality = new DataQualityEngine();
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
