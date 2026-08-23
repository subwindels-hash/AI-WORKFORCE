<?php
namespace Aegis;

use Aegis\Backtest\Backtester;
use Aegis\Brokers\BrokerManager;
use Aegis\Brokers\Mt5BridgeConnector;
use Aegis\Paper\PaperTradingEngine;
use Aegis\Persistence\AuditRepository;
use Aegis\Persistence\AnalysisRepository;
use Aegis\Persistence\BacktestRepository;
use Aegis\Persistence\JournalRepository;
use Aegis\Persistence\PaperRepository;
use Aegis\Persistence\PlatformStateRepository;
use Aegis\Persistence\StrategyRepository;
use Aegis\Providers\BinanceProvider;
use Aegis\Providers\FrankfurterProvider;
use Aegis\Providers\SyntheticProvider;
use Aegis\Strategies\StrategyRegistry;
use Aegis\Strategies\TradingStrategy;

/**
 * Service container wiring the whole platform from CI3's database handle.
 * Repositories are implemented by Aegis_model (CI3 DB abstraction); the
 * domain layer never touches SQL itself.
 */
class Platform
{
    public readonly ProviderManager $providers;
    public readonly BrokerManager $brokers;
    public readonly ExecutionSupervisor $execution;
    public readonly \Aegis\Sports\SportsIntelligence $sports;
    public readonly Identity $identity;
    public readonly RiskEngine $risk;
    public readonly StrategyRegistry $strategies;
    public readonly TradingIntelligenceEngine $engine;
    public readonly PaperTradingEngine $paper;
    public readonly \Aegis_model $model;

    public function __construct(\Aegis_model $model, bool $disableRealProviders = false)
    {
        $this->model = $model;

        $this->providers = new ProviderManager();
        if (!$disableRealProviders) {
            $this->providers->register(new BinanceProvider());
            $this->providers->register(new FrankfurterProvider());
        }
        $this->providers->register(new SyntheticProvider()); // ALWAYS last
        $this->brokers = new BrokerManager();
        $this->brokers->register(new Mt5BridgeConnector());
        $this->providers->setFallbackHandler(function (array $info) use ($model) {
            $model->audit->emit('PROVIDER_FALLBACK', "{$info['symbol']}: providers [" . implode(', ', $info['failed']) . "] failed — falling back to {$info['used']}", $info);
        });

        $this->risk = new RiskEngine();
        $this->execution = new ExecutionSupervisor($model->audit, $model->state);
        $this->sports = new \Aegis\Sports\SportsIntelligence($model->sports, $model->audit);
        $this->identity = new Identity($model->identity);
        $this->strategies = new StrategyRegistry($model->strategies, $model->audit);
        $this->strategies->seedBuiltins();

        $state = $model->state->load();
        $this->engine = new TradingIntelligenceEngine(
            $this->providers, $this->risk, $model->analysis, $model->audit, $state
        );

        $this->paper = new PaperTradingEngine(
            $model->paper, $model->journal, $model->audit, $model->state,
            $this->providers, $this->risk, $this->strategies
        );
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
        $implemented = ['ANALYSIS_ONLY', 'PAPER_TRADING', 'HUMAN_APPROVAL'];
        if (!in_array($mode, $implemented, true)) {
            return ['ok' => false, 'message' => "Mode {$mode} is not implemented yet. Implemented: " . implode(', ', $implemented) . '. Semi- and fully-autonomous execution remain unavailable.'];
        }
        $state = $this->model->state->load();
        $prev = $state['tradingMode'];
        $state['tradingMode'] = $mode;
        $this->model->state->save($state);
        $this->model->audit->emit('TRADING_MODE_CHANGED', "Trading mode {$prev} -> {$mode}", ['previous' => $prev, 'mode' => $mode], 'user');
        return ['ok' => true, 'message' => "Trading mode set to {$mode}", 'state' => $state];
    }

    public function setKillSwitch(bool $active, ?string $reason = null): array
    {
        $state = $this->model->state->load();
        $state['killSwitch'] = ['active' => $active, 'activatedAt' => gmdate('c'), 'reason' => $reason ?? ($active ? 'engaged' : 'released')];
        $this->model->state->save($state);
        $this->model->audit->emit($active ? 'KILL_SWITCH_ACTIVATED' : 'KILL_SWITCH_DEACTIVATED', 'Kill switch ' . ($active ? 'ACTIVATED' : 'deactivated') . ($reason ? ": {$reason}" : ''), ['reason' => $reason], 'user');
        return $state['killSwitch'];
    }

    public function updateRiskLimits(array $patch): array
    {
        $limits = $this->risk->updateLimits($patch);
        $this->model->audit->emit('RISK_LIMITS_UPDATED', 'Risk limits updated', ['limits' => $limits], 'user');
        return $limits;
    }
}
