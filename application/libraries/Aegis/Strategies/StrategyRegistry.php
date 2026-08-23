<?php
namespace Aegis\Strategies;

use Aegis\Strategies\TrendFollowingStrategy;
use Aegis\Strategies\MeanReversionStrategy;
use Aegis\Strategies\BreakoutStrategy;
use Aegis\Strategies\MomentumStrategy;

function builtinStrategies(): array
{
    return [
        new TrendFollowingStrategy(),
        new MeanReversionStrategy(),
        new BreakoutStrategy(),
        new MomentumStrategy(),
    ];
}

/**
 * Versioned strategy registry with the evidence-gated lifecycle (spec §12):
 * DRAFT -> BACKTESTED -> VALIDATED -> RISK_REVIEWED -> PAPER_TRADING -> APPROVED.
 * Backed by the database via a thin repo interface (CI3 model implements it).
 */
class StrategyRegistry
{
    public const ORDER = ['DRAFT', 'BACKTESTED', 'VALIDATED', 'RISK_REVIEWED', 'PAPER_TRADING', 'APPROVED'];
    public const CRITERIA = ['minTrades' => 10, 'minProfitFactor' => 1.0, 'maxDrawdownPct' => 50.0, 'requirePositiveExpectancy' => true];

    /** @var array<string, TradingStrategy> keyed id@version */
    private array $implementations = [];

    public function __construct(
        private readonly \Aegis\Persistence\StrategyRepository $repo,
        private readonly \Aegis\Persistence\AuditRepository $audit,
    ) {}

    public function seedBuiltins(): void
    {
        foreach (builtinStrategies() as $strategy) {
            $this->implementations["{$strategy->id()}@{$strategy->version()}"] = $strategy;
            $existing = $this->repo->find($strategy->id(), $strategy->version());
            if (!$existing) {
                $record = $this->newRecord($strategy, 'builtin');
                $this->repo->save($record);
                $this->audit->emit('STRATEGY_REGISTERED', sprintf('Strategy %s@%s registered (builtin)', $strategy->id(), $strategy->version()), [
                    'strategyId' => $strategy->id(), 'version' => $strategy->version(), 'source' => 'builtin',
                ]);
            }
        }
    }

    private function newRecord(TradingStrategy $s, string $source): array
    {
        $now = gmdate('c');
        return [
            'strategy_id' => $s->id(),
            'version' => $s->version(),
            'name' => $s->name(),
            'description' => $s->description(),
            'market_classes' => $s->marketClasses(),
            'timeframes' => $s->timeframes(),
            'params' => $s->params(),
            'source' => $source,
            'lifecycle' => 'DRAFT',
            'created_at' => $now,
            'updated_at' => $now,
            'lifecycle_history' => [['from' => null, 'to' => 'DRAFT', 'at' => $now, 'reason' => 'registered']],
        ];
    }

    public function implementation(string $id, string $version): ?TradingStrategy
    {
        return $this->implementations["{$id}@{$version}"] ?? null;
    }

    /** Public record lookup used by the paper-deployment gate. */
    public function findRecordForPaper(string $id, string $version): ?array
    {
        return $this->repo->find($id, $version);
    }

    public function transition(string $strategyId, string $version, string $to, ?string $reason = null): array
    {
        $record = $this->repo->find($strategyId, $version);
        if (!$record) {
            return ['ok' => false, 'reasons' => ["Strategy {$strategyId}@{$version} not found"], 'warnings' => []];
        }
        if ($record['lifecycle'] === 'RETIRED') {
            return ['ok' => false, 'reasons' => ['Strategy is RETIRED — lifecycle is terminal'], 'warnings' => []];
        }
        $from = $record['lifecycle'];
        if ($to === 'RETIRED') {
            $this->apply($record, 'RETIRED', $reason ?? 'retired by user');
            return ['ok' => true, 'reasons' => [], 'warnings' => [], 'strategy' => $record];
        }
        $expected = self::nextStage($from);
        if ($to !== $expected) {
            return ['ok' => false, 'reasons' => ["Invalid transition {$from} -> {$to}. Expected next stage: " . ($expected ?: '(terminal)') . ' (stages may not be skipped)'], 'warnings' => []];
        }
        if ($to === 'BACKTESTED') {
            $count = $this->repo->countBacktests($strategyId, $version);
            if ($count === 0) {
                return ['ok' => false, 'reasons' => ['No completed backtest for this strategy version — run a backtest first'], 'warnings' => []];
            }
        }
        if ($to === 'VALIDATED') {
            $latest = $this->repo->latestBacktest($strategyId, $version);
            if (!$latest) {
                return ['ok' => false, 'reasons' => ['No backtest results available'], 'warnings' => []];
            }
            $report = self::validateMetrics($latest['metrics'], $latest['request']['symbol'] ?? '');
            if (!$report['ok']) return $report + ['strategy' => $record];
        }
        if ($to === 'RISK_REVIEWED') {
            $report = $this->riskReview($record);
            if (!$report['ok']) return $report + ['strategy' => $record];
        }
        if ($to === 'PAPER_TRADING') {
            // Phase 3: paper deployment IS this stage. The gate blocks
            // under-reviewed and AI-source strategies; the normal path is
            // the Paper Trading console (deployStrategy), which calls this.
            $gate = $this->canDeployToPaper($record);
            if (!$gate['ok']) return ['ok' => false, 'reasons' => $gate['reasons'], 'warnings' => [], 'strategy' => $record];
        }
        if ($to === 'APPROVED') {
            return ['ok' => false, 'reasons' => ['Live approval requires the Trade Execution Supervisor and broker connectors (Phase 4–5).'], 'warnings' => [], 'strategy' => $record];
        }

        $this->apply($record, $to, $reason ?? "gate checks passed ({$from} -> {$to})");
        return ['ok' => true, 'reasons' => [], 'warnings' => [], 'strategy' => $record];
    }

    /**
     * Phase 3: deploying to a paper account is the PAPER_TRADING stage.
     * Requires RISK_REVIEWED (or better) and blocks AI-source strategies
     * without manual sign-off.
     */
    public function canDeployToPaper(array $record): array
    {
        $orderIdx = array_search($record['lifecycle'], self::ORDER, true);
        $riskIdx = array_search('RISK_REVIEWED', self::ORDER, true);
        if ($orderIdx < $riskIdx) {
            return ['ok' => false, 'reasons' => ["Strategy lifecycle is {$record['lifecycle']} — paper deployment requires RISK_REVIEWED (advance through backtesting, validation and risk review first)"]];
        }
        if ($record['source'] === 'ai') {
            return ['ok' => false, 'reasons' => ['AI-generated strategies require manual human risk sign-off before paper/live stages (auto-advancement blocked by design)']];
        }
        return ['ok' => true, 'reasons' => []];
    }

    public function riskReview(array $record): array
    {
        $reasons = [];
        $warnings = [];
        $impl = $this->implementations[$record['strategy_id'] . '@' . $record['version']] ?? null;
        if ($record['source'] === 'ai') {
            $reasons[] = 'AI-generated strategies require manual human risk sign-off before paper/live stages (auto-advancement blocked by design)';
        }
        if (!$impl) {
            $reasons[] = 'No executable implementation registered for this version';
            return ['ok' => false, 'reasons' => $reasons, 'warnings' => $warnings];
        }
        $stopAtr = $record['params']['stopAtr'] ?? null;
        if (!is_numeric($stopAtr) && $record['strategy_id'] !== 'mean-reversion') {
            $reasons[] = 'Strategy does not define a stop-loss distance parameter — stops are mandatory';
        }
        if (is_numeric($stopAtr)) {
            if ((float)$stopAtr <= 0) $reasons[] = 'Stop distance must be positive';
            elseif ((float)$stopAtr > 4) $warnings[] = "Stop distance {$stopAtr}x ATR is very wide — expect large per-trade risk";
        }
        return ['ok' => count($reasons) === 0, 'reasons' => $reasons, 'warnings' => $warnings];
    }

    private function apply(array &$record, string $to, string $reason): void
    {
        $from = $record['lifecycle'];
        $record['lifecycle'] = $to;
        $record['updated_at'] = gmdate('c');
        $record['lifecycle_history'][] = ['from' => $from, 'to' => $to, 'at' => $record['updated_at'], 'reason' => $reason];
        $this->repo->save($record);
        $this->audit->emit('STRATEGY_STATUS_CHANGED', sprintf('Strategy %s@%s: %s -> %s', $record['strategy_id'], $record['version'], $from, $to), [
            'strategyId' => $record['strategy_id'], 'version' => $record['version'], 'from' => $from, 'to' => $to, 'reason' => $reason,
        ]);
    }

    public static function nextStage(string $current): ?string
    {
        $idx = array_search($current, self::ORDER, true);
        if ($idx === false || $idx >= count(self::ORDER) - 1) return null;
        return self::ORDER[$idx + 1];
    }

    public static function validateMetrics(array $metrics, string $context): array
    {
        $reasons = [];
        $warnings = [];
        $trades = (int)($metrics['trades'] ?? 0);
        if ($trades < self::CRITERIA['minTrades']) $reasons[] = "Sample size too small: {$trades} trades < " . self::CRITERIA['minTrades'] . " required ({$context})";
        $pf = $metrics['profitFactor'] ?? null;
        if ($pf !== null && $pf <= self::CRITERIA['minProfitFactor']) $reasons[] = sprintf('Profit factor %s does not exceed %s', number_format($pf, 2), self::CRITERIA['minProfitFactor']);
        if (($metrics['maxDrawdownPct'] ?? 0) > self::CRITERIA['maxDrawdownPct']) $reasons[] = sprintf('Max drawdown %s%% exceeds the %s%% validation ceiling', number_format($metrics['maxDrawdownPct'], 1), self::CRITERIA['maxDrawdownPct']);
        $exp = $metrics['expectancyPnl'] ?? null;
        if ($exp !== null && $exp <= 0 && self::CRITERIA['requirePositiveExpectancy']) $reasons[] = sprintf('Negative expectancy per trade (%s)', number_format($exp, 2));
        if ($trades >= 1 && $trades < self::CRITERIA['minTrades'] * 2) $warnings[] = 'Trade count is modest — results may not be statistically robust';
        if (($metrics['sharpe'] ?? 0) > 4) $warnings[] = sprintf('Sharpe %s is suspiciously high — inspect for over-fitting or unrealistic fills', number_format($metrics['sharpe'], 2));
        return ['ok' => count($reasons) === 0, 'reasons' => $reasons, 'warnings' => $warnings];
    }
}
