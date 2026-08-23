import type {
  PortfolioState, RiskDecision, RiskLimits, TradeSetup,
} from '../types';
import { DEFAULT_RISK_LIMITS } from '../config/defaults';

/**
 * RISK ENGINE — independent service with ABSOLUTE VETO POWER (Rule 6).
 *
 * Nothing in this class knows about brokers, orders or agents. It receives a
 * trade proposal plus portfolio state and returns an explicit, auditable
 * approve/reject decision. The AI stack can never bypass it: the execution
 * supervisor (Phase 5) refuses to submit any order without a RiskDecision
 * from this engine, and in Phase 1 the API exposes the decision directly on
 * every analysis so users see exactly why a setup would (not) pass.
 */
export class RiskEngine {
  private limits: RiskLimits;
  private portfolio: PortfolioState;

  constructor(
    limits: RiskLimits = { ...DEFAULT_RISK_LIMITS },
    portfolio: PortfolioState = defaultPortfolio(),
  ) {
    this.limits = limits;
    this.portfolio = portfolio;
  }

  getLimits(): RiskLimits {
    return { ...this.limits };
  }

  updateLimits(patch: Partial<RiskLimits>): RiskLimits {
    const merged = { ...this.limits, ...patch };
    // Enforce internal consistency.
    merged.riskPerTradePct = Math.min(merged.riskPerTradePct, merged.maxRiskPerTradePct);
    this.limits = merged;
    return this.getLimits();
  }

  getPortfolio(): PortfolioState {
    return { ...this.portfolio };
  }

  setPortfolio(patch: Partial<PortfolioState>): void {
    this.portfolio = { ...this.portfolio, ...patch };
  }

  /**
   * Evaluate a proposed setup. `killSwitchActive` is passed by the caller
   * (platform state) so the kill switch (Rule 7) participates in every check.
   */
  evaluate(
    setup: TradeSetup,
    context: { killSwitchActive: boolean; dataQuality: number; syntheticData: boolean; staleData: boolean },
  ): RiskDecision {
    const reasons: string[] = [];
    const warnings: string[] = [];
    const equity = this.portfolio.equity;

    // --- Data governance ------------------------------------------------------
    if (context.killSwitchActive) reasons.push('Kill switch is ACTIVE — all trade proposals are vetoed');
    if (context.syntheticData && this.limits.blockSyntheticData) {
      reasons.push('Setup is built on SYNTHETIC data — live risk decisions require real market data');
    }
    if (context.staleData && this.limits.blockStaleData) reasons.push('Market data is stale beyond the freshness threshold');
    if (context.dataQuality < this.limits.minDataQuality) {
      reasons.push(`Data quality ${context.dataQuality.toFixed(2)} below minimum ${this.limits.minDataQuality}`);
    }

    // --- Per-trade checks -----------------------------------------------------
    if (this.limits.requireStopLoss && !Number.isFinite(setup.stopLoss)) {
      reasons.push('Stop loss is required');
    }
    const riskPct = this.limits.riskPerTradePct;
    if (riskPct > this.limits.maxRiskPerTradePct) {
      reasons.push(`Configured risk per trade ${(riskPct * 100).toFixed(2)}% exceeds hard cap ${(this.limits.maxRiskPerTradePct * 100).toFixed(2)}%`);
    }
    if (setup.riskReward < this.limits.minRiskReward) {
      reasons.push(`Risk/reward ${setup.riskReward.toFixed(2)} below minimum ${this.limits.minRiskReward}`);
    }

    const entry = setup.entry.reference;
    const stopDistance = Math.abs(entry - setup.stopLoss);
    const riskAmount = equity * riskPct;
    const units = stopDistance > 0 ? riskAmount / stopDistance : null;
    const notional = units !== null ? units * entry : null;
    const impliedLeverage = notional !== null && equity > 0 ? notional / equity : null;

    if (notional !== null && notional > this.limits.maxPositionNotionalUsd) {
      reasons.push(
        `Position notional $${Math.round(notional)} exceeds limit $${this.limits.maxPositionNotionalUsd}`,
      );
      warnings.push(
        'Notional breaches the cap — reduce risk %, widen structure-anchored stop is NOT allowed; skip the trade instead',
      );
    }
    if (impliedLeverage !== null && impliedLeverage > this.limits.maxLeverage) {
      reasons.push(`Implied leverage ${impliedLeverage.toFixed(1)}× exceeds limit ${this.limits.maxLeverage}×`);
    }

    // --- Portfolio checks -----------------------------------------------------
    // Exposure is measured as CAPITAL AT RISK (stop-loss distance basis), not
    // notional: leveraged instruments routinely carry notional > equity. Pure
    // notional/leverage exposure is capped above (notional & leverage limits).
    const openRisk = Object.values(this.portfolio.openRiskBySymbol).reduce((a, b) => a + b, 0);
    const totalRisk = openRisk + riskAmount;
    if (equity > 0 && totalRisk / equity > this.limits.maxPortfolioExposurePct) {
      reasons.push(`Total open risk ${(totalRisk / equity * 100).toFixed(1)}% would exceed limit ${(this.limits.maxPortfolioExposurePct * 100).toFixed(0)}%`);
    }
    const symbolRisk = (this.portfolio.openRiskBySymbol[setup.symbol] ?? 0) + riskAmount;
    if (equity > 0 && symbolRisk / equity > this.limits.maxSymbolExposurePct) {
      reasons.push(`Risk concentration in ${setup.symbol} would exceed ${(this.limits.maxSymbolExposurePct * 100).toFixed(0)}% of equity`);
    }
    if (this.portfolio.openPositions + 1 > this.limits.maxOpenPositions) {
      reasons.push(`Open position count would exceed limit ${this.limits.maxOpenPositions}`);
    }
    if (equity > 0) {
      if (-this.portfolio.dailyPnl / equity > this.limits.maxDailyLossPct) {
        reasons.push('Daily loss limit exceeded');
      }
      if (-this.portfolio.weeklyPnl / equity > this.limits.maxWeeklyLossPct) {
        reasons.push('Weekly loss limit exceeded');
      }
      const drawdown = this.portfolio.peakEquity > 0 ? (this.portfolio.peakEquity - equity) / this.portfolio.peakEquity : 0;
      if (drawdown > this.limits.maxDrawdownPct) {
        reasons.push(`Maximum drawdown ${(drawdown * 100).toFixed(1)}% exceeds limit ${(this.limits.maxDrawdownPct * 100).toFixed(0)}%`);
      }
    }

    if (setup.entry.min >= setup.entry.max) warnings.push('Entry zone is degenerate');
    if (new Date(setup.expiration).getTime() < Date.now()) warnings.push('Setup already expired');

    const sizing = units !== null
      ? {
          equity,
          riskAmount: round2(riskAmount),
          riskPct,
          entryReference: entry,
          stopDistance,
          units: round2(units),
          notionalUsd: round2(notional ?? 0),
          impliedLeverage: impliedLeverage === null ? null : Number(impliedLeverage.toFixed(2)),
        }
      : null;

    return {
      approved: reasons.length === 0,
      checkedAt: new Date().toISOString(),
      reasons,
      warnings,
      sizing,
    };
  }
}

export function defaultPortfolio(): PortfolioState {
  return {
    equity: 10_000,
    cash: 10_000,
    openPositions: 0,
    openRiskBySymbol: {},
    dailyPnl: 0,
    weeklyPnl: 0,
    peakEquity: 10_000,
  };
}

function round2(v: number): number {
  return Math.round(v * 100) / 100;
}
