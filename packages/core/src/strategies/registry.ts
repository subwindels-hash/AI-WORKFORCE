import type { EventBus } from '../events/events';
import type { DataStore, StrategyVersionRecord } from '../store/types';
import type { BacktestMetrics } from '../store/types';
import type { TradingStrategy } from './types';
import { builtinStrategies } from './builtins';

/**
 * Strategy lifecycle pipeline (spec §12):
 *
 *   DRAFT -> BACKTESTED -> VALIDATED -> RISK_REVIEWED -> PAPER_TRADING -> APPROVED
 *
 * Each transition has hard, auditable gates:
 *  - BACKTESTED requires at least one completed backtest of this version.
 *  - VALIDATED requires the backtest evidence to pass statistical validation.
 *  - RISK_REVIEWED requires the Risk Engine's strategy review to pass.
 *  - PAPER_TRADING / APPROVED require Phase 3 / Phase 5 machinery — refused
 *    honestly (HTTP 409 at the API layer) until those phases ship.
 *  - AI-generated strategies can never move past RISK_REVIEWED automatically
 *    (spec: never auto-deploy AI strategies to live trading).
 */
export const LIFECYCLE_ORDER: StrategyVersionRecord['lifecycle'][] = [
  'DRAFT', 'BACKTESTED', 'VALIDATED', 'RISK_REVIEWED', 'PAPER_TRADING', 'APPROVED',
];

export interface ValidationCriteria {
  minTrades: number;
  minProfitFactor: number; // must exceed (strictly) — 1.0 = breakeven
  maxDrawdownPct: number; // fraction
  requirePositiveExpectancy: boolean;
}

export const DEFAULT_VALIDATION_CRITERIA: ValidationCriteria = {
  minTrades: 10,
  minProfitFactor: 1.0,
  maxDrawdownPct: 0.5,
  requirePositiveExpectancy: true,
};

export interface StrategyReviewReport {
  ok: boolean;
  reasons: string[];
  warnings: string[];
}

export interface TransitionRequest {
  strategyId: string;
  version: string;
  to: StrategyVersionRecord['lifecycle'];
  reason?: string;
}

export interface TransitionResult extends StrategyReviewReport {
  record?: StrategyVersionRecord;
}

export class StrategyRegistry {
  private implementations = new Map<string, TradingStrategy>(); // key id@version
  private criteria: ValidationCriteria;

  constructor(
    private readonly store: DataStore,
    private readonly eventBus: EventBus,
    criteria: ValidationCriteria = DEFAULT_VALIDATION_CRITERIA,
  ) {
    this.criteria = criteria;
  }

  /** Register built-ins (idempotent) — DRAFT lifecycle. */
  async seedBuiltins(): Promise<void> {
    for (const strategy of builtinStrategies()) {
      await this.registerImplementation(strategy);
      const existing = await this.store.getStrategy(strategy.id, strategy.version);
      if (!existing) {
        await this.saveNewRecord(strategy, 'builtin');
      }
    }
  }

  /** Register a strategy implementation + its record (used by built-ins and future user strategies). */
  async registerImplementation(strategy: TradingStrategy, source: StrategyVersionRecord['source'] = 'builtin'): Promise<StrategyVersionRecord> {
    this.implementations.set(`${strategy.id}@${strategy.version}`, strategy);
    const existing = await this.store.getStrategy(strategy.id, strategy.version);
    if (existing) return existing;
    return this.saveNewRecord(strategy, source);
  }

  private async saveNewRecord(strategy: TradingStrategy, source: StrategyVersionRecord['source']): Promise<StrategyVersionRecord> {
    const now = new Date().toISOString();
    const record: StrategyVersionRecord = {
      strategyId: strategy.id,
      version: strategy.version,
      name: strategy.name,
      description: strategy.description,
      marketClasses: strategy.marketClasses,
      timeframes: strategy.timeframes,
      params: strategy.params,
      source,
      lifecycle: 'DRAFT',
      createdAt: now,
      updatedAt: now,
      lifecycleHistory: [{ from: null, to: 'DRAFT', at: now, reason: 'registered' }],
    };
    await this.store.saveStrategy(record);
    this.eventBus.emit('STRATEGY_REGISTERED', `Strategy ${strategy.id}@${strategy.version} registered (${source})`, {
      strategyId: strategy.id,
      version: strategy.version,
      source,
    });
    return record;
  }

  getImplementation(id: string, version: string): TradingStrategy | undefined {
    return this.implementations.get(`${id}@${version}`);
  }

  async listVersions(): Promise<StrategyVersionRecord[]> {
    return this.store.listStrategies();
  }

  async getRecord(id: string, version?: string): Promise<StrategyVersionRecord | null> {
    return this.store.getStrategy(id, version);
  }

  /** Attempt a lifecycle transition with all gates enforced. */
  async transition(req: TransitionRequest): Promise<TransitionResult> {
    const record = await this.store.getStrategy(req.strategyId, req.version);
    if (!record) return { ok: false, reasons: [`Strategy ${req.strategyId}@${req.version} not found`], warnings: [] };
    if (record.lifecycle === 'RETIRED') {
      return { ok: false, reasons: ['Strategy is RETIRED — lifecycle is terminal'], warnings: [] };
    }

    const from = record.lifecycle;
    const expected = nextStage(from);
    if (req.to === 'RETIRED') {
      await this.applyTransition(record, 'RETIRED', req.reason ?? 'retired by user');
      return { ok: true, reasons: [], warnings: [], record };
    }
    if (req.to !== expected) {
      return {
        ok: false,
        reasons: [`Invalid transition ${from} -> ${req.to}. Expected next stage: ${expected ?? '(terminal)'} (stages may not be skipped)`],
        warnings: [],
      };
    }

    // --- Gates ----------------------------------------------------------------
    if (req.to === 'BACKTESTED') {
      const count = await this.store.countBacktests(req.strategyId, req.version);
      if (count === 0) {
        return { ok: false, reasons: ['No completed backtest for this strategy version — run a backtest first'], warnings: [] };
      }
    }

    if (req.to === 'VALIDATED') {
      const report = await this.validate(req.strategyId, req.version);
      if (!report.ok) return { ...report, record };
    }

    if (req.to === 'RISK_REVIEWED') {
      const report = await this.riskReview(record);
      if (!report.ok) return { ...report, record };
    }

    if (req.to === 'PAPER_TRADING') {
      return {
        ok: false,
        reasons: ['PAPER_TRADING requires the paper-trading engine, which arrives in Phase 3. The lifecycle is otherwise ready.'],
        warnings: [],
        record,
      };
    }
    if (req.to === 'APPROVED') {
      return {
        ok: false,
        reasons: ['Live approval requires the Trade Execution Supervisor and broker connectors (Phase 4–5).'],
        warnings: [],
        record,
      };
    }

    await this.applyTransition(record, req.to, req.reason ?? `gate checks passed (${from} -> ${req.to})`);
    return { ok: true, reasons: [], warnings: [], record };
  }

  /** Statistical validation over the LATEST backtest of this version. */
  async validate(strategyId: string, version: string): Promise<StrategyReviewReport> {
    const backtests = await this.store.listBacktests({ strategyId, strategyVersion: version, limit: 1 });
    const latest = backtests[0];
    if (!latest) return { ok: false, reasons: ['No backtest results available'], warnings: [] };
    return validateMetrics(latest.metrics, this.criteria, latest.request.symbol);
  }

  /** Risk review: does the strategy respect the platform's safety rules? */
  riskReview(record: StrategyVersionRecord): StrategyReviewReport {
    const reasons: string[] = [];
    const warnings: string[] = [];
    const impl = this.implementations.get(`${record.strategyId}@${record.version}`);

    if (record.source === 'ai') {
      reasons.push('AI-generated strategies require manual human risk sign-off before paper/live stages (auto-advancement blocked by design)');
    }
    if (!impl) {
      reasons.push('No executable implementation registered for this version');
      return { ok: reasons.length === 0, reasons, warnings };
    }
    if (!impl.supportsShorts && record.marketClasses.includes('crypto')) {
      warnings.push('Long-only strategy on crypto — short side will not be traded');
    }
    const stopAtr = typeof record.params.stopAtr === 'number' ? record.params.stopAtr : null;
    if (stopAtr === null && record.strategyId !== 'mean-reversion') {
      // mean-reversion defines its stop via band distance; others must carry an ATR stop param
      reasons.push('Strategy does not define a stop-loss distance parameter — stops are mandatory');
    }
    if (stopAtr !== null && (stopAtr <= 0 || stopAtr > 4)) {
      warnings.push(`Stop distance ${stopAtr}×ATR is ${stopAtr > 4 ? 'very wide — expect large per-trade risk' : 'invalid'}`);
      if (stopAtr <= 0) reasons.push('Stop distance must be positive');
    }
    return { ok: reasons.length === 0, reasons, warnings };
  }

  private async applyTransition(record: StrategyVersionRecord, to: StrategyVersionRecord['lifecycle'], reason: string): Promise<void> {
    const from = record.lifecycle;
    record.lifecycle = to;
    record.updatedAt = new Date().toISOString();
    record.lifecycleHistory.push({ from, to, at: record.updatedAt, reason });
    await this.store.saveStrategy(record);
    this.eventBus.emit('STRATEGY_STATUS_CHANGED', `Strategy ${record.strategyId}@${record.version}: ${from} -> ${to}`, {
      strategyId: record.strategyId,
      version: record.version,
      from,
      to,
      reason,
    });
  }
}

export function nextStage(current: StrategyVersionRecord['lifecycle']): StrategyVersionRecord['lifecycle'] | null {
  const idx = LIFECYCLE_ORDER.indexOf(current);
  if (idx < 0 || idx >= LIFECYCLE_ORDER.length - 1) return null;
  return LIFECYCLE_ORDER[idx + 1];
}

/** Pure validation over metrics — unit-tested independently of any store. */
export function validateMetrics(metrics: BacktestMetrics, criteria: ValidationCriteria, context: string): StrategyReviewReport {
  const reasons: string[] = [];
  const warnings: string[] = [];
  if (metrics.trades < criteria.minTrades) {
    reasons.push(`Sample size too small: ${metrics.trades} trades < ${criteria.minTrades} required (${context})`);
  }
  if (metrics.profitFactor !== null && metrics.profitFactor <= criteria.minProfitFactor) {
    reasons.push(`Profit factor ${metrics.profitFactor.toFixed(2)} does not exceed ${criteria.minProfitFactor}`);
  }
  if (metrics.maxDrawdownPct > criteria.maxDrawdownPct * 100) {
    reasons.push(`Max drawdown ${metrics.maxDrawdownPct.toFixed(1)}% exceeds the ${criteria.maxDrawdownPct * 100}% validation ceiling`);
  }
  if (criteria.requirePositiveExpectancy && metrics.expectancyPnl !== null && metrics.expectancyPnl <= 0) {
    reasons.push(`Negative expectancy per trade (${metrics.expectancyPnl.toFixed(2)})`);
  }
  if (metrics.trades >= 1 && metrics.trades < criteria.minTrades * 2) {
    warnings.push('Trade count is modest — results may not be statistically robust');
  }
  if (metrics.sharpe !== null && metrics.sharpe > 4) {
    warnings.push(`Sharpe ${metrics.sharpe.toFixed(2)} is suspiciously high — inspect for over-fitting or unrealistic fills`);
  }
  return { ok: reasons.length === 0, reasons, warnings };
}
