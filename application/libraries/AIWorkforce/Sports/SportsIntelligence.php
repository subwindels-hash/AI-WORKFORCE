<?php
namespace AIWorkforce\Sports;

use AIWorkforce\Notifications\Notifier;
use AIWorkforce\Persistence\AuditRepository;
use AIWorkforce\Persistence\SportsRepository;
use AIWorkforce\Sports\Providers\ApiFootballProvider;
use AIWorkforce\Sports\Providers\HttpSportsProvider;
use AIWorkforce\Sports\Providers\FootballApiProvider;
use AIWorkforce\Sports\Providers\ProviderException;
use AIWorkforce\Sports\Providers\SandboxSportsProvider;
use AIWorkforce\Sports\Providers\SportMonksProvider;
use AIWorkforce\Sports\Providers\SportsProviderManager;
use AIWorkforce\Sports\Providers\TheSportsDbProvider;

/**
 * WINDELS Sports Intelligence — domain service container (spec §2/§43).
 *
 * Boots from the environment:
 *  - WINDELS_SPORTS_ENABLED=1      module switch
 *  - WINDELS_SPORTS_MODE           SANDBOX | PAPER | PRODUCTION
 *  - WINDELS_SPORTS_SANDBOX=1      explicitly enables the labeled simulation
 *                                  provider (SANDBOX mode only)
 *  - WINDELS_SPORTS_HTTP_{ID,BASE_URL,TOKEN,TIMEOUT}  real REST provider
 *
 * With no provider the module reports DISABLED_NO_PROVIDER and never creates
 * fixtures, odds, predictions, or tickets.
 */
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
    public readonly ConfidenceEngine $confidence;
    public readonly CalibrationEngine $calibration;
    public readonly TicketOptimizer $tickets;
    public readonly DecisionRecorder $decisions;
    public readonly TicketGovernance $governance;
    public readonly ResultVerificationEngine $results;
    public readonly TicketSettlementService $settlement;
    public readonly PersistedResultVerifier $resultVerifier;
    public readonly PerformanceAnalytics $performance;
    public readonly SportsSyncService $sync;
    public readonly ConfigurationService $configuration;
    public readonly PredictionPipeline $pipeline;
    public readonly DailyTicketService $dailyTickets;
    public readonly ProviderHealthMonitor $providerHealth;
    public readonly ModelPerformanceService $modelPerformance;
    public readonly ModelDriftMonitor $driftMonitor;
    public readonly SportsBacktester $backtester;

    public function __construct(private SportsRepository $repository, AuditRepository $audit, ?Notifier $notifications = null)
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
        $this->confidence = new ConfidenceEngine();
        $this->calibration = new CalibrationEngine();

        $this->decisions = new DecisionRecorder($repository, $audit);
        $this->governance = new TicketGovernance($repository, $audit, $this->correlation);
        $this->results = new ResultVerificationEngine();
        $this->settlement = new TicketSettlementService($repository, $this->results, $audit);
        $this->resultVerifier = new PersistedResultVerifier($repository, $audit);
        $this->performance = new PerformanceAnalytics();
        $this->sync = new SportsSyncService($repository, $audit, $this->quality);
        $this->configuration = new ConfigurationService($repository, $audit);
        $this->pipeline = new PredictionPipeline($this->matchIntelligence, $this->features, $this->predictions, $this->value, $this->risk, $this->correlation, $this->confidence);
        $this->dailyTickets = new DailyTicketService($repository, $audit, $this->providers, $this->configuration, $this->quality, $this->pipeline, new TicketOptimizer($this->correlation), $this->governance, $this->decisions);
        $this->providerHealth = new ProviderHealthMonitor();
        $this->modelPerformance = new ModelPerformanceService($repository, $audit);
        $this->driftMonitor = new ModelDriftMonitor($repository, $audit, $notifications);
        $this->backtester = new SportsBacktester($repository, $audit, $this->pipeline, $this->quality, $this->modelPerformance);

        $this->registerProviders();
        $this->providers->setHealthObserver(function (object $provider, string $operation, ?\Throwable $error, array $payload) use ($repository, $audit) {
            try {
                $source = $repository->ensureProvider($provider->id(), $provider->id());
                $health = $provider->health();
                if ($error instanceof ProviderException) $health['status'] = $error->status;
                $health['lastFailureAt'] = $error !== null ? gmdate('c') : ($health['lastFailureAt'] ?? null);
                $health['lastSuccessAt'] = $error === null ? gmdate('c') : ($health['lastSuccessAt'] ?? null);
                $health['lastOddsSyncAt'] = $operation === 'odds' && $error === null ? gmdate('c') : ($health['lastOddsSyncAt'] ?? null);
                $health['lastFixtureSyncAt'] = $operation === 'fixtures' && $error === null ? gmdate('c') : ($health['lastFixtureSyncAt'] ?? null);
                $health['lastResultSyncAt'] = $operation === 'results' && $error === null ? gmdate('c') : ($health['lastResultSyncAt'] ?? null);
                $repository->saveHealth((int) $source['id'], $health);
                if ($error !== null) $audit->emit('SPORTS_PROVIDER_FAILURE', "Provider {$provider->id()} failed ({$error->status}) on {$operation}", ['provider' => $provider->id(), 'operation' => $operation, 'status' => $error->status, 'message' => mb_substr($error->getMessage(), 0, 300)]);
            } catch (\Throwable $e) { /* health recording must never break the pipeline */ }
        });
    }

    /** Register providers from environment configuration only — no secrets in code. */
    private function registerProviders(): void
    {
        // First-class vendor adapters. Each is optional and only registered when
        // its server-side key is present; this makes fallback and health status
        // work consistently across all three vendors.
        $vendors = [
            ['api-football', 'WINDELS_API_FOOTBALL_KEY', 'https://v3.football.api-sports.io', 'api-football'],
            ['thesportsdb', 'WINDELS_THESPORTSDB_KEY', 'https://www.thesportsdb.com/api/v1/json', 'thesportsdb'],
            ['sportmonks', 'WINDELS_SPORTMONKS_TOKEN', 'https://api.sportmonks.com/v3/football', 'sportmonks'],
        ];
        foreach ($vendors as [$id, $keyName, $defaultBase, $kind]) {
            $key = getenv($keyName);
            if (is_string($key) && $key !== '') {
                $base = (string)(getenv('WINDELS_'.strtoupper(str_replace('-', '_', $id)).'_BASE_URL') ?: $defaultBase);
                $this->providers->register(new FootballApiProvider($id, $base, $key, $kind, (int)(getenv('WINDELS_SPORTS_HTTP_TIMEOUT') ?: 10)));
            }
        }
        // Also discover native providers from the central ApiProviders store
        // (Admin → API). These can coexist with or supplement env-based keys.
        $this->registerFromStore();
        if ($this->mode() === 'SANDBOX' && getenv('WINDELS_SPORTS_SANDBOX') === '1') {
            $this->providers->register(new SandboxSportsProvider());
        }
        $baseUrl = getenv('WINDELS_SPORTS_HTTP_BASE_URL');
        if (is_string($baseUrl) && $baseUrl !== '' && filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            $id = (string) (getenv('WINDELS_SPORTS_HTTP_ID') ?: 'http-provider');
            $this->providers->register(new HttpSportsProvider(
                $id,
                rtrim($baseUrl, '/'),
                (string) (getenv('WINDELS_SPORTS_HTTP_TOKEN') ?: ''),
                (int) (getenv('WINDELS_SPORTS_HTTP_TIMEOUT') ?: 10)
            ));
        }
        $managed = \AIWorkforce\ApiProviders::resolve('sports');
        if (is_array($managed) && !empty($managed['base_url']) && filter_var($managed['base_url'], FILTER_VALIDATE_URL)) {
            $driver = $managed['driver'] ?? '';
            // Skip native drivers here — they are registered via registerFromStore()
            if (!in_array($driver, ['api_football', 'thesportsdb', 'sportmonks'], true)) {
                $this->providers->register(new HttpSportsProvider(
                    'managed-sports-' . (int) ($managed['id'] ?? 0),
                    rtrim((string) $managed['base_url'], '/'),
                    (string) ($managed['secrets']['token'] ?? $managed['secrets']['api_key'] ?? ''),
                    (int) ($managed['extra']['timeout'] ?? 10)
                ));
            }
        }
    }

    /**
     * Discover and register native sports providers from the central
     * ApiProviders store (Admin → API). This allows operators to manage
     * provider credentials through the dashboard rather than environment files.
     */
    private function registerFromStore(): void
    {
        try {
            $chain = \AIWorkforce\ApiProviders::chain('sports');
        } catch (\Throwable $e) { return; }
        foreach ($chain as $cfg) {
            $driver = $cfg['driver'] ?? '';
            $id = (int) ($cfg['id'] ?? 0);
            $timeout = (int) ($cfg['extra']['timeout'] ?? 10);
            $secrets = $cfg['secrets'] ?? [];
            $key = $secrets['api_key'] ?? $secrets['token'] ?? '';
            if ($key === '') continue;
            try {
                if ($driver === 'api_football') {
                    $base = $cfg['base_url'] ?: 'https://v3.football.api-sports.io';
                    $this->providers->register(new ApiFootballProvider(
                        $key, rtrim($base, '/'), $timeout
                    ));
                } elseif ($driver === 'thesportsdb') {
                    $base = $cfg['base_url'] ?: 'https://www.thesportsdb.com/api/v1/json';
                    $this->providers->register(new TheSportsDbProvider(
                        $key, rtrim($base, '/'), $timeout
                    ));
                } elseif ($driver === 'sportmonks') {
                    $base = $cfg['base_url'] ?: 'https://api.sportmonks.com/v3/football';
                    $this->providers->register(new SportMonksProvider(
                        $key, rtrim($base, '/'), $timeout
                    ));
                }
            } catch (\Throwable $e) { /* skip invalid store entries silently */ }
        }
    }

    public function mode(): string
    {
        $mode = (string) (getenv('WINDELS_SPORTS_MODE') ?: 'SANDBOX');
        return in_array($mode, ConfigurationService::PLATFORM_MODES, true) ? $mode : 'SANDBOX';
    }

    public function performanceReport(array $filter = []): array
    {
        $tickets = $this->repository->listTickets($filter);
        $selections = $this->repository->settledSelections($filter);
        $outcomes = $this->repository->predictionOutcomes(empty($filter['modelVersionId']) ? null : (int) $filter['modelVersionId']);
        $since = $filter['from'] ?? null;
        if ($since) {
            $selections = array_values(array_filter($selections, fn($s) => ($s['ticket_created_at'] ?? $s['created_at'] ?? '') >= $since));
            $outcomes = array_values(array_filter($outcomes, fn($o) => ($o['created_at'] ?? '') >= $since));
        }
        $report = $this->performance->report($tickets, $selections, $outcomes, ['mode' => $this->mode(), 'totalPredictions' => count($this->repository->listPredictions($filter, 1000))]);
        return array_merge($report, ['filter' => $filter]);
    }

    public function status(): array
    {
        $providers = [];
        foreach ($this->repository->listProviders() as $p) {
            $health = $this->providerHealth->assess($p, $this->repository->listHealth((int) $p['id'], 20), $this->repository->listJobRuns(null, 50));
            $providers[] = array_merge($p, ['derivedStatus' => $health['status'], 'reliability' => $health['reliability'], 'detail' => $health['detail']]);
        }
        return [
            'module' => 'WINDELS Sports Intelligence',
            'enabled' => getenv('WINDELS_SPORTS_ENABLED') === '1',
            'mode' => $this->mode(),
            'isDemoData' => $this->mode() === 'SANDBOX',
            'providersConfigured' => $this->providers->configured(),
            'providers' => array_values($providers),
            'liveHealth' => $this->providers->health(),
            'ticketEngine' => $this->providers->configured() ? $this->configuration->active()['engine_mode'] : 'DISABLED_NO_PROVIDER',
            'configuration' => $this->configuration->active(),
            'message' => $this->providers->configured() ? 'sports data providers available' : 'This feature is temporarily unavailable. Please try again later.',
        ];
    }

    /** Dashboard aggregation (spec §37) — everything from stored data. */
    public function dashboard(): array
    {
        $status = $this->status();
        $config = $status['configuration'];
        $today = gmdate('Y-m-d');
        $dayEnd = $today . 'T23:59:59+00:00';
        $dayStart = $today . 'T00:00:00+00:00';
        $upcoming = $this->repository->listMatches(['status' => 'SCHEDULED', 'from' => $dayStart, 'to' => $dayEnd], 50);
        $live = $this->repository->listMatches(['status' => 'LIVE'], 50);
        $todayPredictions = $this->repository->listPredictions(['from' => $dayStart, 'to' => $dayEnd], 500);
        $qualified = array_values(array_filter($todayPredictions, fn($p) => ($p['decision'] ?? '') === 'PREDICTION_READY'));
        $rejected = array_values(array_filter($todayPredictions, fn($p) => ($p['decision'] ?? '') !== 'PREDICTION_READY'));
        $daily = $this->repository->findDailyTicket($today);
        $ticket = $daily['ticket_id'] ? $this->repository->findTicket((string) $daily['ticket_id']) : null;
        $confidenceValues = array_values(array_filter(array_map(fn($p) => is_numeric($p['confidence']) ? (float) $p['confidence'] : null, $todayPredictions)));
        $riskDist = ['LOW' => 0, 'MEDIUM' => 0, 'HIGH' => 0, 'REJECTED' => 0];
        foreach ($todayPredictions as $p) {
            $r = $p['risk'] ?? 'REJECTED';
            $riskDist[$r] = ($riskDist[$r] ?? 0) + 1;
        }
        $perf = $this->performanceReport(['from' => gmdate('Y-m-d', strtotime($today . ' -29 days')) . 'T00:00:00+00:00', 'to' => $dayEnd]);
        $models = $this->modelPerformance->listModels();
        $calibrations = $this->repository->listCalibrations(null, 'APPROVED', 10);
        $lastSyncs = array_slice($this->repository->listJobRuns(null, 30), 0, 8);
        $qualityRows = array_map(fn($m) => ['match' => $m, 'quality' => $this->repository->latestQuality((int) $m['id'])], array_merge($upcoming, $live));
        return [
            'systemStatus' => [
                'enabled' => $status['enabled'], 'mode' => $status['mode'], 'isDemoData' => $status['isDemoData'],
                'providers' => $status['providers'], 'liveHealth' => $status['liveHealth'],
                'lastSyncs' => $lastSyncs, 'ticketEngine' => $status['ticketEngine'],
            ],
            'todayIntelligence' => [
                'date' => $today,
                'upcomingCount' => count($upcoming),
                'upcoming' => $upcoming,
                'live' => $live,
                'qualifiedPredictions' => count($qualified),
                'rejectedPredictions' => count($rejected),
                'averageConfidence' => $confidenceValues ? round(array_sum($confidenceValues) / count($confidenceValues), 2) : null,
                'riskDistribution' => $riskDist,
                'dataQuality' => array_values(array_filter(array_map(fn($q) => $q['quality'] ? ['matchId' => $q['match']['id'], 'score' => $q['quality']['score'], 'band' => $q['quality']['band']] : null, $qualityRows))),
            ],
            'ticketEngine' => [
                'configuration' => $config,
                'today' => $daily,
                'ticket' => $ticket,
                'ticketSelections' => $ticket ? $this->repository->ticketSelections((string) $ticket['id']) : [],
            ],
            'performance' => $perf,
            'models' => ['versions' => $models, 'approvedCalibrations' => $calibrations],
        ];
    }
}
