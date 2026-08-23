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
    public readonly \Aegis\Portfolio\PortfolioRiskMonitor $monitor;
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
        $this->sports = new \Aegis\Sports\SportsIntelligence($model->sports, $model->audit);
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

        $this->execution = new ExecutionSupervisor(
            $model->audit, $model->state, $model->proposals,
            $this->risk, $this->brokers, $this->strategies
        );
        $this->monitor = new \Aegis\Portfolio\PortfolioRiskMonitor(
            $model->paper, $this->paper, $this->risk, $this->brokers, $model->audit, $model->state
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
        return $state['killSwitch'];
    }

    public function updateRiskLimits(array $patch): array
    {
        $limits = $this->risk->updateLimits($patch);
        $this->model->audit->emit('RISK_LIMITS_UPDATED', 'Risk limits updated', ['limits' => $limits], 'user');
        return $limits;
    }
}
