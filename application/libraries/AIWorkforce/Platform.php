<?php
namespace AIWorkforce;

use AIWorkforce\Backtest\Backtester;
use AIWorkforce\Brokers\BrokerManager;
use AIWorkforce\Brokers\Mt5BridgeConnector;
use AIWorkforce\Paper\PaperTradingEngine;
use AIWorkforce\Persistence\AuditRepository;
use AIWorkforce\Persistence\AnalysisRepository;
use AIWorkforce\Persistence\BacktestRepository;
use AIWorkforce\Persistence\JournalRepository;
use AIWorkforce\Persistence\PaperRepository;
use AIWorkforce\Persistence\PlatformStateRepository;
use AIWorkforce\Persistence\StrategyRepository;
use AIWorkforce\Providers\BinanceProvider;
use AIWorkforce\Providers\FrankfurterProvider;
use AIWorkforce\Providers\LicensedAssetMarketDataProvider;
use AIWorkforce\Providers\SyntheticProvider;
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
    public readonly BrokerManager $brokers;
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

    public function __construct(\AIWorkforce_model $model, bool $disableRealProviders = false)
    {
        $this->model = $model;

        $this->providers = new ProviderManager();
        if (!$disableRealProviders) {
            if (ApiProviders::enabled('crypto_market', true)) {
                $crypto = ApiProviders::resolve('crypto_market');
                $cryptoBase = is_array($crypto) ? trim((string) ($crypto['base_url'] ?? '')) : '';
                $this->providers->register(new BinanceProvider($cryptoBase !== '' ? $cryptoBase : null));
            }
            if (ApiProviders::enabled('forex_market', true)) {
                $fx = ApiProviders::resolve('forex_market');
                $fxBase = is_array($fx) ? trim((string) ($fx['base_url'] ?? '')) : '';
                $this->providers->register(new FrankfurterProvider($fxBase !== '' ? $fxBase : null));
            }
            // These adapters are inert until a licensed feed, explicit
            // ENABLED flag and symbol allow-list are supplied. Registering
            // them here makes capability/health state observable without
            // allowing a missing integration to fabricate data.
            $this->providers->register(new LicensedAssetMarketDataProvider('stock', 'stock-licensed', 'Licensed stock data', 'AI_WORKFORCE_STOCK_DATA', null, null, null, null, null, null, 30));
            $this->providers->register(new LicensedAssetMarketDataProvider('etf', 'etf-licensed', 'Licensed ETF data', 'AI_WORKFORCE_ETF_DATA', null, null, null, null, null, null, 31));
            $this->providers->register(new LicensedAssetMarketDataProvider('futures', 'futures-licensed', 'Licensed futures data', 'AI_WORKFORCE_FUTURES_DATA', null, null, null, null, null, null, 32));
            $this->providers->register(new LicensedAssetMarketDataProvider('options', 'options-licensed', 'Licensed options data', 'AI_WORKFORCE_OPTIONS_DATA', null, null, null, null, null, null, 33));
        }
        $this->providers->register(new SyntheticProvider()); // ALWAYS last
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
        $this->providers->setFallbackHandler(function (array $info) use ($model) {
            $model->audit->emit('PROVIDER_FALLBACK', "{$info['symbol']}: providers [" . implode(', ', $info['failed']) . "] failed — falling back to {$info['used']}", $info);
        });

        $this->risk = new RiskEngine();
        $this->notifications = new \AIWorkforce\Notifications\Notifier($model->notifications);
        $this->sports = new \AIWorkforce\Sports\SportsIntelligence($model->sports, $model->audit, $this->notifications);
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
                if (!preg_match('/^[A-Z0-9._-]{1,32}$/', $s)) throw new \InvalidArgumentException("invalid symbol in approvedSymbols: {$s}");
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
