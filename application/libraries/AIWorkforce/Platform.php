<?php
namespace AIWorkforce;

use AIWorkforce\Backtest\Backtester;
use AIWorkforce\Brokers\BrokerManager;
use AIWorkforce\Brokers\Mt5BridgeConnector;
use AIWorkforce\Brokers\UserBrokerConnections;
use AIWorkforce\Paper\PaperTradingEngine;
use AIWorkforce\Persistence\AuditRepository;
use AIWorkforce\Persistence\AnalysisRepository;
use AIWorkforce\Persistence\BacktestRepository;
use AIWorkforce\Persistence\JournalRepository;
use AIWorkforce\Persistence\PaperRepository;
use AIWorkforce\Persistence\PlatformStateRepository;
use AIWorkforce\Persistence\StrategyRepository;
use AIWorkforce\Providers\AlpacaProvider;
use AIWorkforce\Providers\BinanceProvider;
use AIWorkforce\Providers\BybitProvider;
use AIWorkforce\Providers\CoinbaseProvider;
use AIWorkforce\Providers\FrankfurterProvider;
use AIWorkforce\Providers\IbkrProvider;
use AIWorkforce\Providers\KrakenProvider;
use AIWorkforce\Providers\LicensedAssetMarketDataProvider;
use AIWorkforce\Providers\OandaProvider;
use AIWorkforce\Providers\OkxProvider;
use AIWorkforce\Providers\SyntheticProvider;
use AIWorkforce\Providers\YahooChartProvider;
use AIWorkforce\Lottery\OfficialLotteryProvider;
use AIWorkforce\Strategies\StrategyRegistry;
use AIWorkforce\Strategies\TradingStrategy;

/**
 * Service container wiring the whole platform from CI3's database handle.
 * Repositories are implemented by AIWorkforce_model (CI3 DB abstraction); the
 * domain layer never touches SQL itself.
 */
class Platform
{
    public readonly ProviderManager $providers;
    public readonly \AIWorkforce\Agents\AgentOrchestrator $agents;
    public readonly BrokerManager $brokers;
    public readonly UserBrokerConnections $userBrokers;
    public readonly ExecutionSupervisor $execution;
    public readonly \AIWorkforce\Sports\SportsIntelligence $sports;
    public readonly \AIWorkforce\Lottery\LotteryIntelligence $lottery;
    public readonly Identity $identity;
    public readonly RiskEngine $risk;
    public readonly StrategyRegistry $strategies;
    public readonly TradingIntelligenceEngine $engine;
    public readonly PaperTradingEngine $paper;
    public readonly \AIWorkforce\Portfolio\PortfolioRiskMonitor $monitor;
    public readonly \AIWorkforce\Notifications\Notifier $notifications;
    public readonly \AIWorkforce_model $model;
    public \AIWorkforce\LangLearn\LangLearnService $langlearn;
    public \AIWorkforce\LangLearn\TeacherService $langteacher;
    public \AIWorkforce\LangLearn\VocabularyService $vocabulary;
    public \AIWorkforce\LangLearn\AudioPracticeService $audiopractice;
    public \AIWorkforce\LangLearn\AdaptiveLearningService $adaptive;
    public \AIWorkforce\LangLearn\TeacherCoach $langcoach;
    public \AIWorkforce\LangLearn\Translator $translator;
    public \AIWorkforce\Cloudflare\AgentPlatform $cloudflare;
    public \AIWorkforce\MultiplierIntelligence\MultiplierPlatformIntegration $multiplierIntegration;

    /** True when AI_WORKFORCE_DISABLE_REAL_PROVIDERS=1 forces the simulated feed. */
    private bool $disableRealProviders = false;

    public function __construct(\AIWorkforce_model $model, bool $disableRealProviders = false)
    {
        $this->model = $model;
        $auditFn = function (string $type, ?string $id, string $agent, array $data) use ($model): void {
            $model->audit->emit($type, "Agent {$agent} execution {$id}", ['executionId' => $id, 'agent' => $agent, 'data' => $data], 'agent');
        };
        $this->agents = new \AIWorkforce\Agents\AgentOrchestrator($auditFn);

        foreach ([
            ['general', []],
            ['market', ['crypto.getPrice', 'forex.getRate']],
            ['sports', ['sports.getFixtures', 'sports.getMatchStats']],
            ['lead_discovery', []],
            ['lottery', ['lottery.getResults', 'lottery.generateCombinations']],
            ['language', ['language.analyzePronunciation']],
            ['trading', ['broker.getAccount', 'broker.getPositions']],
            ['video', ['video.create']],
            ['multiplier', ['multiplier.getCurrentMultiplier', 'multiplier.getHistory', 'multiplier.generateSignal', 'multiplier.getAccuracy', 'multiplier.listAgents', 'multiplier.analyzeRound']],
        ] as [$role, $tools]) {
            // Use EnhancedCloudflareAgent for multi-model Cloudflare support,
            // falling back to CloudflareSpecialistAgent if the enhanced class is unavailable.
            if (class_exists(\AIWorkforce\Agents\EnhancedCloudflareAgent::class)) {
                $this->agents->register(new \AIWorkforce\Agents\EnhancedCloudflareAgent($role, $tools));
            } else {
                $this->agents->register(new \AIWorkforce\Agents\CloudflareSpecialistAgent($role, $tools));
            }
        }

        $this->providers = new ProviderManager();
        $this->disableRealProviders = $disableRealProviders;
        $this->registerMarketDataProviders();
        $this->brokers = new BrokerManager();
        $this->brokers->register(new Mt5BridgeConnector());
        $this->brokers->register(new \AIWorkforce\Brokers\Mt4BridgeConnector());
        $this->brokers->register(new \AIWorkforce\Brokers\BinanceTradingConnector());
        $this->brokers->register(new \AIWorkforce\Brokers\BybitTradingConnector());
        $this->brokers->register(new \AIWorkforce\Brokers\OkxTradingConnector());
        $this->brokers->register(new \AIWorkforce\Brokers\CoinbaseTradingConnector());
        $this->brokers->register(new \AIWorkforce\Brokers\KrakenTradingConnector());
        $this->brokers->register(new \AIWorkforce\Brokers\InteractiveBrokersConnector());
        $this->brokers->register(new \AIWorkforce\Brokers\AlpacaConnector());
        $this->brokers->register(new \AIWorkforce\Brokers\OandaConnector());
        $this->userBrokers = new UserBrokerConnections($model->db);

        $this->providers->setFallbackHandler(function (array $info) use ($model) {
            $model->audit->emit('PROVIDER_FALLBACK', "{$info['symbol']}: providers [" . implode(', ', $info['failed']) . "] failed — falling back to {$info['used']}", $info);
        });

        $this->risk = new RiskEngine();
        $this->notifications = new \AIWorkforce\Notifications\Notifier($model->notifications);
        // Pass the DB handle explicitly so Sports Intelligence can resolve
        // encrypted provider credentials from Admin → API during bootstrap.
        $this->sports = new \AIWorkforce\Sports\SportsIntelligence(
            $model->sports,
            $model->audit,
            $this->notifications,
            $model->db
        );
        $officialLottery = new OfficialLotteryProvider();
        $lotteryProvider = $officialLottery->configured()
            ? $officialLottery
            : (getenv('WINDELS_LOTTERY_SANDBOX') === '1'
                ? new \AIWorkforce\Lottery\SandboxLotteryProvider()
                : new \AIWorkforce\Lottery\UnavailableLotteryProvider());
        $this->lottery = new \AIWorkforce\Lottery\LotteryIntelligence($model->lottery, $model->audit, $lotteryProvider);
        $this->identity = new Identity($model->identity);
        $this->strategies = new StrategyRegistry($model->strategies, $model->audit, $model->journal);
        $this->strategies->seedBuiltins();

        $state = $model->state->load();
        $this->engine = new TradingIntelligenceEngine(
            $this->providers, $this->risk, $model->analysis, $model->audit, $state
        );

        $this->paper = new PaperTradingEngine(
            $model->paper, $model->journal, $model->audit, $model->state,
            $this->providers, $this->risk, $this->strategies
        );

        $this->langlearn = new \AIWorkforce\LangLearn\LangLearnService($model->langlearn);
        $this->langteacher = new \AIWorkforce\LangLearn\TeacherService($model->langlearn, $this->langlearn);
        $this->vocabulary = new \AIWorkforce\LangLearn\VocabularyService($model->langlearn, $this->langlearn);
        $this->audiopractice = new \AIWorkforce\LangLearn\AudioPracticeService($model->langlearn, $this->langlearn);
        $this->adaptive = new \AIWorkforce\LangLearn\AdaptiveLearningService($model->langlearn, $this->langlearn);
        $this->langcoach = new \AIWorkforce\LangLearn\TeacherCoach($this->langlearn, $this->langteacher);
        $this->translator = new \AIWorkforce\LangLearn\Translator();
        $this->execution = new ExecutionSupervisor(
            $model->audit, $model->state, $model->proposals,
            $this->risk, $this->brokers, $this->strategies,
            null, $this->notifications
        );
        $this->monitor = new \AIWorkforce\Portfolio\PortfolioRiskMonitor(
            $model->paper, $this->paper, $this->risk, $this->brokers, $model->audit, $model->state,
            $this->notifications
        );

        // ── Cloudflare AI Agent Platform ───────────────────────────
        $this->cloudflare = new \AIWorkforce\Cloudflare\AgentPlatform(
            $model->db,
            $auditFn,
            null, // Approval handler — set by ExecutionSupervisor when needed
            $this->agents
        );

        // ── Multiplier Intelligence + Cloudflare Integration ───────
        // Wires Multiplier specialist agents into the Cloudflare platform:
        // - Registers MultiplierSpecialistAgent with orchestrator (for CommunicationBus dispatch)
        // - Registers 6 multiplier.* MCP tools (available to ALL Cloudflare agents)
        // - Connects Sports Intelligence enrichment (api-football/thesportsdb/sportmonks)
        // - Enables LLM enhancement via ModelRouter (70% stat / 30% LLM blend)
        try {
            $this->multiplierIntegration = new \AIWorkforce\MultiplierIntelligence\MultiplierPlatformIntegration(
                $this->cloudflare,
                $this->sports ?? null
            );
            $this->multiplierIntegration->register();

            // Register the dedicated MultiplierAnalyst specialist agent
            if ($this->multiplierIntegration->agent()) {
                $this->agents->register($this->multiplierIntegration->agent());
            }
        } catch (\Throwable $e) {
            // Multiplier integration is non-critical — don't break platform bootstrap
            $auditFn('INTEGRATION_WARNING', null, 'multiplier', [
                'component' => 'MultiplierPlatformIntegration',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Register every market-data provider this host is allowed to use.
     *
     * Order matters: ProviderManager sorts by priority() and the synthetic
     * provider is ALWAYS registered last as the labelled fallback of last
     * resort, so a live feed can never be silently replaced by simulation.
     *
     * Crypto and forex are gated on the Admin → API store: once an operator
     * saves a row for that service, only an ENABLED row keeps the real feed
     * registered (see ApiProviders::serviceEnabled). Call
     * refreshMarketDataProviders() after changing those flags in-process.
     */
    private function registerMarketDataProviders(): void
    {
        if (!$this->disableRealProviders) {
            // Crypto exchanges: register every enabled exchange. ProviderManager
            // picks the highest-priority healthy provider per symbol, so
            // multiple exchanges give transparent redundancy. Each provider
            // accepts an explicit base URL from the environment (useful when
            // a regional CDN is required) but defaults to the public REST root.
            //
            // To disable an individual exchange, set e.g. BINANCE_ENABLED=0,
            // BYBIT_ENABLED=0, etc. in .env. All are enabled by default so the
            // operator sees health/capability without any admin action.
            $cryptoEnv = function (string $key, bool $default = true): bool {
                $v = getenv($key);
                return $v === false ? $default : (strtolower(trim((string) $v)) !== '0');
            };
            // Register crypto exchanges only when BOTH the per-service gate is
            // open (a disabled crypto_market row closes it) and the coarse
            // CRYPTO_EXCHANGES_ENABLED kill-switch is not off. The previous
            // `||` let the default-true env var bypass the row gate entirely,
            // so a connected-but-disabled provider never dropped the feeds.
            if (ApiProviders::enabled('crypto_market', true) && $cryptoEnv('CRYPTO_EXCHANGES_ENABLED', true)) {
                if ($cryptoEnv('BINANCE_ENABLED', true)) {
                    $binanceBase = trim((string) (getenv('BINANCE_API_BASE') ?: ''));
                    $this->providers->register(new BinanceProvider($binanceBase !== '' ? $binanceBase : null));
                }
                if ($cryptoEnv('BYBIT_ENABLED', true)) {
                    $bybitBase = trim((string) (getenv('BYBIT_API_BASE') ?: ''));
                    $this->providers->register(new BybitProvider($bybitBase !== '' ? $bybitBase : null));
                }
                if ($cryptoEnv('OKX_ENABLED', true)) {
                    $okxBase = trim((string) (getenv('OKX_API_BASE') ?: ''));
                    $this->providers->register(new OkxProvider($okxBase !== '' ? $okxBase : null));
                }
                if ($cryptoEnv('COINBASE_ENABLED', true)) {
                    $cbBase = trim((string) (getenv('COINBASE_API_BASE') ?: ''));
                    $this->providers->register(new CoinbaseProvider($cbBase !== '' ? $cbBase : null));
                }
                if ($cryptoEnv('KRAKEN_ENABLED', true)) {
                    $krBase = trim((string) (getenv('KRAKEN_API_BASE') ?: ''));
                    $this->providers->register(new KrakenProvider($krBase !== '' ? $krBase : null));
                }
                // Alpaca's public crypto feed works without keys; equities/ETFs
                // only activate when ALPACA_API_KEY + ALPACA_API_SECRET are set
                // (or ALPACA_EQUITIES_ENABLED=1 is forced). Priority 16 sits
                // after the dedicated crypto exchanges.
                if ($cryptoEnv('ALPACA_ENABLED', true)) {
                    $alpacaBase = trim((string) (getenv('ALPACA_API_BASE') ?: ''));
                    $this->providers->register(new AlpacaProvider($alpacaBase !== '' ? $alpacaBase : null));
                }
            }
            if (ApiProviders::enabled('forex_market', true)) {
                $fx = ApiProviders::resolve('forex_market');
                $fxBase = is_array($fx) ? trim((string) ($fx['base_url'] ?? '')) : '';
                // OANDA has priority over Frankfurter when a token is configured
                // (it provides bid/ask candles at sub-daily granularity), but
                // Frankfurter always registers as the fallback for daily ECB
                // reference rates.
                if (getenv('OANDA_API_KEY') || getenv('OANDA_TOKEN')) {
                    $oandaBase = trim((string) (getenv('OANDA_API_BASE') ?: ''));
                    $this->providers->register(new OandaProvider($oandaBase !== '' ? $oandaBase : null));
                }
                $this->providers->register(new FrankfurterProvider($fxBase !== '' ? $fxBase : null));
            }
            // These adapters are inert until a licensed feed, explicit
            // ENABLED flag and symbol allow-list are supplied. Registering
            // them here makes capability/health state observable without
            // allowing a missing integration to fabricate data.
            $this->providers->register(new LicensedAssetMarketDataProvider('stock', 'stock-licensed', 'Licensed stock data', 'AI_WORKFORCE_STOCK_DATA', null, null, null, null, null, null, 12));
            $this->providers->register(new LicensedAssetMarketDataProvider('etf', 'etf-licensed', 'Licensed ETF data', 'AI_WORKFORCE_ETF_DATA', null, null, null, null, null, null, 13));
            $this->providers->register(new LicensedAssetMarketDataProvider('futures', 'futures-licensed', 'Licensed futures data', 'AI_WORKFORCE_FUTURES_DATA', null, null, null, null, null, null, 14));
            $this->providers->register(new LicensedAssetMarketDataProvider('options', 'options-licensed', 'Licensed options data', 'AI_WORKFORCE_OPTIONS_DATA', null, null, null, null, null, null, 15));
            // Delayed public Yahoo chart for allow-listed stocks/ETFs/futures.
            // Disabled with AI_WORKFORCE_YAHOO_CHART_ENABLED=0. Not licensed.
            if (getenv('AI_WORKFORCE_YAHOO_CHART_ENABLED') !== '0') {
                $this->providers->register(new YahooChartProvider('stock'));
                $this->providers->register(new YahooChartProvider('etf'));
                $this->providers->register(new YahooChartProvider('futures'));
            }
            // Interactive Brokers Client Portal Gateway. Opt-in: only
            // registers when IBKR_ENABLED=1 and IBKR_API_BASE points at a
            // running (authenticated) local gateway. Priority 20 sits between
            // licensed feeds and Yahoo so configured brokerage data wins over
            // the public delayed feed, without ever fabricating.
            if (strtolower((string)(getenv('IBKR_ENABLED') ?: '0')) === '1') {
                $ibBase = trim((string)(getenv('IBKR_API_BASE') ?: 'https://localhost:5000'));
                $this->providers->register(new IbkrProvider($ibBase !== '' ? $ibBase : null));
            }
        }
        $this->providers->register(new SyntheticProvider()); // ALWAYS last
    }

    /**
     * Register a user's personal broker connections into the broker manager
     * for this request. User connectors are prefixed 'user-{broker}' and
     * only expose connectors that the user saved and enabled. If the user
     * saves no connections, nothing is added and the platform-scoped env
     * connectors (admin/CLI configured) keep working as before.
     */
    public function bindUserConnectors(int $userId): void
    {
        foreach ($this->userBrokers->connectorsForUser($userId) as $connector) {
            $this->brokers->register($connector);
        }
    }

    /**
     * Re-read the Admin → API store and rebuild the market-data chain in the
     * current process. This is what makes the chart go LIVE immediately after
     * an operator connects/enables a provider, without waiting for the next
     * page load to rebuild the Platform.
     *
     * Only the provider registry is rebuilt — brokers, repositories, engines,
     * risk and the execution supervisor are untouched, so nothing about the
     * trading governance chain changes. The fallback audit handler is
     * re-attached because it lives on the registry being replaced.
     *
     * @return array{refreshed:bool,realProvidersAllowed:bool,registered:list<string>,syntheticOnly:bool}
     */
    public function refreshMarketDataProviders(): array
    {
        $model = $this->model;
        // $this->providers is readonly — rebuild the registry in place instead
        // of reassigning it. reset() also clears the health/failure caches so a
        // just-connected provider is probed fresh rather than inheriting a
        // stale DOWN/DEGRADED verdict.
        $this->providers->reset();
        $this->registerMarketDataProviders();
        $this->providers->setFallbackHandler(function (array $info) use ($model) {
            $model->audit->emit('PROVIDER_FALLBACK', "{$info['symbol']}: providers [" . implode(', ', $info['failed']) . "] failed — falling back to {$info['used']}", $info);
        });

        $registered = [];
        $syntheticOnly = true;
        foreach ($this->providers->listProviders() as $p) {
            $registered[] = $p->name();
            if (!$p->synthetic()) $syntheticOnly = false;
        }
        return [
            'refreshed' => true,
            'realProvidersAllowed' => !$this->disableRealProviders,
            'registered' => $registered,
            'syntheticOnly' => $syntheticOnly,
        ];
    }

    /**
     * Phase 6: parameter optimization with walk-forward verification
     * (in-sample 70% / out-of-sample 30%). Optionally registers the winning
     * parameters as a NEW version with source 'ai' — which then needs the
     * full lifecycle plus human sign-off like any AI-generated strategy.
     */
    public function optimizeStrategy(array $input): array
    {
        $id = (string) ($input['strategyId'] ?? '');
        $factory = \AIWorkforce\Strategies\builtinStrategyFactory($id);
        if ($factory === null) {
            throw new \InvalidArgumentException('optimization requires a builtin strategy (trend-following, mean-reversion, breakout, momentum)');
        }
        $record = $this->strategies->findRecord($id, $input['strategyVersion'] ?? null);
        if ($record === null) throw new \InvalidArgumentException("strategy {$id} is not registered");
        $impl = $this->strategies->implementation($id, $record['version']);
        if ($impl === null) throw new \InvalidArgumentException("strategy {$id}@{$record['version']} has no executable implementation");

        $symbol = strtoupper((string) ($input['symbol'] ?? 'BTCUSDT'));
        $marketClass = (string) ($input['marketClass'] ?? $this->paper->inferMarketClass($symbol));
        $timeframe = (string) ($input['timeframe'] ?? '1h');
        $limit = max(420, min(2000, (int) ($input['limit'] ?? 800)));
        $series = $this->providers->getCandleSeries($symbol, $marketClass, $timeframe, $limit);

        $report = \AIWorkforce\Optimization\StrategyOptimizer::optimize(
            $factory, $impl->params(), $impl->paramGrid(), $series['candles'],
            array_intersect_key($input, \AIWorkforce\Backtest\Backtester::DEFAULTS)
        );
        $report['request'] = ['strategyId' => $id, 'strategyVersion' => $record['version'], 'symbol' => $symbol, 'marketClass' => $marketClass, 'timeframe' => $timeframe, 'limit' => $limit];
        $report['dataProvenance'] = $series['provenance'];

        if (!empty($input['register']) && $report['recommendation']['adopt']) {
            $inner = $factory($report['recommendation']['params']);
            $version = $this->nextVariantVersion($id);
            $now = gmdate('c');
            $variant = new \AIWorkforce\Strategies\VersionedStrategyDecorator($inner, $version, $report['recommendation']['params']);
            $variantRecord = [
                'strategy_id' => $id, 'version' => $version,
                'name' => $inner->name() . " (optimized {$version})", 'description' => $inner->description(),
                'market_classes' => $inner->marketClasses(), 'timeframes' => $inner->timeframes(),
                'params' => $variant->params(), 'source' => 'ai', 'lifecycle' => 'DRAFT',
                'created_at' => $now, 'updated_at' => $now,
                'lifecycle_history' => [['from' => null, 'to' => 'DRAFT', 'at' => $now,
                    'reason' => "optimizer variant from @{$record['version']}; walk-forward verified (OOS PF " . ($report['recommendation']['params'] !== null ? 'passed' : 'n/a') . ')']],
            ];
            $this->strategies->registerVariant($variant, $variantRecord);
            $report['registeredVariant'] = ['strategyId' => $id, 'version' => $version, 'lifecycle' => 'DRAFT',
                'note' => 'source ai — the full lifecycle plus human sign-off apply before paper/live'];
        }

        $this->model->audit->emit('OPTIMIZATION_RUN', sprintf('Optimized %s@%s on %s %s: %d combinations, adopt=%s', $id, $record['version'], $symbol, $timeframe, $report['searchSpace']['combinationsEvaluated'], $report['recommendation']['adopt'] ? 'yes' : 'no'), [
            'strategyId' => $id, 'symbol' => $symbol, 'timeframe' => $timeframe,
            'adopt' => $report['recommendation']['adopt'], 'synthetic' => !empty($series['provenance']['synthetic']),
        ]);
        return $report;
    }

    private function nextVariantVersion(string $id): string
    {
        $max = [0, 0, 0];
        foreach ($this->model->strategies->all() as $r) {
            if ($r['strategy_id'] !== $id) continue;
            $parts = array_map('intval', explode('.', (string) $r['version']));
            if (count($parts) !== 3) continue;
            if ($parts[0] > $max[0] || ($parts[0] === $max[0] && ($parts[1] > $max[1] || ($parts[1] === $max[1] && $parts[2] > $max[2])))) {
                $max = $parts;
            }
        }
        return sprintf('%d.%d.%d', $max[0], $max[1], $max[2] + 1);
    }

    public function runBacktest(array $input): array
    {
        $impl = $this->strategies->implementation($input['strategyId'], $input['strategyVersion'] ?? '');
        if (!$impl) {
            throw new \InvalidArgumentException("Strategy {$input['strategyId']}@{$input['strategyVersion']} is not registered");
        }
        return Backtester::run(
            $impl, $input, $this->providers,
            $this->model->backtests, $this->model->journal, $this->model->audit
        );
    }

    /** @return array{tradingMode:string,killSwitch:array} */
    public function state(): array
    {
        return $this->model->state->load();
    }

    public function setTradingMode(string $mode): array
    {
        $supported = ['ANALYSIS_ONLY', 'PAPER_TRADING', 'HUMAN_APPROVAL', 'SEMI_AUTONOMOUS', 'FULLY_AUTOMATED'];
        if (!in_array($mode, $supported, true)) {
            return ['ok' => false, 'message' => "Mode {$mode} is not supported. Supported: " . implode(', ', $supported) . '.'];
        }
        $state = $this->model->state->load();
        if (in_array($mode, ['SEMI_AUTONOMOUS', 'FULLY_AUTOMATED'], true)) {
            $gate = $this->automationModeGate($mode, $state);
            if (!$gate['ok']) return ['ok' => false, 'message' => "Mode {$mode} refused: " . implode('; ', $gate['reasons'])];
        }
        $prev = $state['tradingMode'];
        $state['tradingMode'] = $mode;
        $this->model->state->save($state);
        $this->model->audit->emit('TRADING_MODE_CHANGED', "Trading mode {$prev} -> {$mode}", ['previous' => $prev, 'mode' => $mode], 'user');
        return ['ok' => true, 'message' => "Trading mode set to {$mode}", 'state' => $state];
    }

    /**
     * Spec §7 gating: automated modes require configured automation limits,
     * an approved-symbol list and (FULLY_AUTONOMOUS) an order-capable READY
     * broker plus a released kill switch and active audit trail.
     * @return array{ok: bool, reasons: array<int, string>}
     */
    public function automationModeGate(string $mode, ?array $state = null): array
    {
        $state = $state ?? $this->model->state->load();
        $reasons = [];
        $limits = ExecutionSupervisor::automationLimits($state);
        if ($limits['approvedSymbols'] === []) $reasons[] = 'automationLimits.approvedSymbols is empty — configure approved symbols first (POST /api/trading/limits)';
        if ($limits['updatedAt'] === null) $reasons[] = 'automation limits were never explicitly configured';
        if ($mode === 'FULLY_AUTOMATED') {
            if ($this->brokers->tradingConnector() === null) $reasons[] = 'no broker connector is READY with effective order submission';
            if (($state['killSwitch']['active'] ?? true) === true) $reasons[] = 'kill switch is ACTIVE — release it before enabling fully-automated trading';
        }
        return ['ok' => count($reasons) === 0, 'reasons' => $reasons];
    }

    /** Configure the SEMI_AUTONOMOUS / FULLY_AUTOMATED execution envelope. */
    public function updateAutomationLimits(array $patch): array
    {
        $state = $this->model->state->load();
        $limits = ExecutionSupervisor::automationLimits($state);
        if (isset($patch['maxTradeNotionalUsd'])) {
            $v = (float) $patch['maxTradeNotionalUsd'];
            if ($v <= 0 || $v > 100000) throw new \InvalidArgumentException('maxTradeNotionalUsd must be within (0, 100000]');
            $limits['maxTradeNotionalUsd'] = $v;
        }
        if (isset($patch['maxDailyTrades'])) {
            $v = (int) $patch['maxDailyTrades'];
            if ($v < 1 || $v > 100) throw new \InvalidArgumentException('maxDailyTrades must be within [1, 100]');
            $limits['maxDailyTrades'] = $v;
        }
        if (isset($patch['maxRiskPerTradePct'])) {
            $v = (float) $patch['maxRiskPerTradePct'];
            if ($v <= 0 || $v > 0.02) throw new \InvalidArgumentException('maxRiskPerTradePct must be within (0, 0.02] (2%)');
            $limits['maxRiskPerTradePct'] = $v;
        }
        if (isset($patch['approvedSymbols'])) {
            if (!is_array($patch['approvedSymbols'])) throw new \InvalidArgumentException('approvedSymbols must be an array of symbols');
            $symbols = [];
            foreach ($patch['approvedSymbols'] as $s) {
                $s = strtoupper(trim((string) $s));
                if (!preg_match('/^[A-Z0-9._:=-]{1,32}$/', $s)) throw new \InvalidArgumentException("invalid symbol in approvedSymbols: {$s}");
                $symbols[] = $s;
            }
            $limits['approvedSymbols'] = array_values(array_unique($symbols));
        }
        $limits['updatedAt'] = gmdate('c');
        $state['automationLimits'] = $limits;
        $this->model->state->save($state);
        $this->model->audit->emit('AUTOMATION_LIMITS_UPDATED', 'Automation limits updated', $limits, 'user');
        return $limits;
    }

    public function setKillSwitch(bool $active, ?string $reason = null): array
    {
        $state = $this->model->state->load();
        $state['killSwitch'] = ['active' => $active, 'activatedAt' => gmdate('c'), 'reason' => $reason ?? ($active ? 'engaged' : 'released')];
        $this->model->state->save($state);
        $this->model->audit->emit($active ? 'KILL_SWITCH_ACTIVATED' : 'KILL_SWITCH_DEACTIVATED', 'Kill switch ' . ($active ? 'ACTIVATED' : 'deactivated') . ($reason ? ": {$reason}" : ''), ['reason' => $reason], 'user');
        if ($active) {
            $this->notifications->notify('KILL_SWITCH', 'critical', 'KILL SWITCH ACTIVATED — all order placement blocked', ['reason' => $reason], 'kill-switch:active');
        }
        return $state['killSwitch'];
    }

    public function updateRiskLimits(array $patch): array
    {
        $limits = $this->risk->updateLimits($patch);
        $this->model->audit->emit('RISK_LIMITS_UPDATED', 'Risk limits updated', ['limits' => $limits], 'user');
        return $limits;
    }
}
