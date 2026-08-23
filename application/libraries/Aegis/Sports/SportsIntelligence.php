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
    public readonly ValueEngine $value;
    public readonly RiskEngine $risk;
    public readonly CorrelationEngine $correlation;
    public readonly TicketOptimizer $tickets;
    public readonly DecisionRecorder $decisions;
    public readonly TicketGovernance $governance;
    public readonly ResultVerificationEngine $results;
    public readonly TicketSettlementService $settlement;
    public readonly PersistedResultVerifier $resultVerifier;
    public readonly PerformanceAnalytics $performance;
    public readonly SportsSyncService $sync;
    public function __construct(private SportsRepository $repository, AuditRepository $audit)
    {
        $this->providers = new SportsProviderManager();
        $this->quality = new DataQualityEngine();
        $this->oddsFreshness = new OddsFreshnessEngine();
        $this->matchIntelligence = new MatchIntelligenceEngine($this->oddsFreshness);
        $this->features = new FeatureEngineeringEngine();
        $this->predictions = new PredictionEngine();
        $this->value = new ValueEngine();
        $this->risk = new RiskEngine();
        $this->correlation = new CorrelationEngine();
        $this->tickets = new TicketOptimizer($this->correlation);
        $this->decisions = new DecisionRecorder($repository, $audit);
        $this->governance = new TicketGovernance($repository, $audit);
        $this->results = new ResultVerificationEngine();
        $this->settlement = new TicketSettlementService($repository, $this->results, $audit);
        $this->resultVerifier = new PersistedResultVerifier($repository, $audit);
        $this->performance = new PerformanceAnalytics();
        $this->sync = new SportsSyncService($repository, $audit, $this->quality);
    }
    public function performanceReport(array $filter = []): array
    {
        $tickets = $this->repository->listTickets($filter);
        return array_merge($this->performance->summarize($tickets), ['mode' => getenv('WINDELS_SPORTS_MODE') ?: 'SANDBOX', 'filter' => $filter]);
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
