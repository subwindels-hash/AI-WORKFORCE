import type { Timeframe } from '../types';
import { TIMEFRAME_MS } from '../types';
import type { BacktestMetrics, BacktestTradeRecord } from '../store/types';

/**
 * Pure performance metrics over a trade list + equity curve (spec §13).
 * Every function is deterministic and unit-tested against hand-computed
 * fixtures.
 */

export interface EquityPoint {
  time: string;
  equity: number;
  drawdownPct: number;
}

export function computeMetrics(
  trades: BacktestTradeRecord[],
  equityCurve: EquityPoint[],
  initialEquity: number,
  timeframe: Timeframe,
  barsInMarket: number,
): BacktestMetrics {
  const wins = trades.filter((t) => t.netPnl > 0);
  const losses = trades.filter((t) => t.netPnl <= 0);
  const grossWin = wins.reduce((a, t) => a + t.netPnl, 0);
  const grossLoss = Math.abs(losses.reduce((a, t) => a + t.netPnl, 0));
  const finalEquity = equityCurve.length ? equityCurve[equityCurve.length - 1].equity : initialEquity;

  const perBarReturns = equityCurve.length > 1
    ? equityCurve.slice(1).map((p, i) => (equityCurve[i].equity > 0 ? p.equity / equityCurve[i].equity - 1 : 0))
    : [];
  const barsPerYear = (365 * 24 * 3_600_000) / TIMEFRAME_MS[timeframe];

  return {
    totalReturnPct: ((finalEquity - initialEquity) / initialEquity) * 100,
    finalEquity: round2(finalEquity),
    trades: trades.length,
    winRate: trades.length ? round4(wins.length / trades.length) : null,
    lossRate: trades.length ? round4(losses.length / trades.length) : null,
    profitFactor: trades.length === 0 ? null : grossLoss === 0 ? null : round4(grossWin / grossLoss),
    expectancyR: trades.length ? round4(trades.reduce((a, t) => a + t.rMultiple, 0) / trades.length) : null,
    expectancyPnl: trades.length ? round2(trades.reduce((a, t) => a + t.netPnl, 0) / trades.length) : null,
    avgWin: wins.length ? round2(grossWin / wins.length) : null,
    avgLoss: losses.length ? round2(-grossLoss / losses.length) : null,
    avgTrade: trades.length ? round2((grossWin - grossLoss) / trades.length) : null,
    sharpe: sharpeRatio(perBarReturns, barsPerYear),
    sortino: sortinoRatio(perBarReturns, barsPerYear),
    maxDrawdownPct: equityCurve.reduce((m, p) => Math.max(m, p.drawdownPct), 0),
    maxDrawdownAbs: round2(maxDrawdownAbs(equityCurve)),
    longestWinStreak: longestStreak(trades, (t) => t.netPnl > 0),
    longestLossStreak: longestStreak(trades, (t) => t.netPnl <= 0),
    exposurePct: equityCurve.length > 1 ? round2((barsInMarket / (equityCurve.length - 1)) * 100) : 0,
    totalFees: round2(trades.reduce((a, t) => a + t.fees.totalCost, 0)),
    totalSlippage: round2(trades.reduce((a, t) => a + t.fees.slippageCost, 0)),
  };
}

export function sharpeRatio(perBarReturns: number[], barsPerYear: number): number | null {
  if (perBarReturns.length < 2) return null;
  const mean = perBarReturns.reduce((a, b) => a + b, 0) / perBarReturns.length;
  const variance = perBarReturns.reduce((a, b) => a + (b - mean) ** 2, 0) / perBarReturns.length;
  const sd = Math.sqrt(variance);
  if (sd === 0 || !Number.isFinite(sd)) return null;
  return round4((mean / sd) * Math.sqrt(barsPerYear));
}

export function sortinoRatio(perBarReturns: number[], barsPerYear: number): number | null {
  if (perBarReturns.length < 2) return null;
  const mean = perBarReturns.reduce((a, b) => a + b, 0) / perBarReturns.length;
  const downside = perBarReturns.filter((r) => r < 0).map((r) => r * r);
  const dd = downside.length ? Math.sqrt(downside.reduce((a, b) => a + b, 0) / perBarReturns.length) : 0;
  if (dd === 0) return mean > 0 ? null : 0; // no downside -> undefined-ish; report null for positive mean
  return round4((mean / dd) * Math.sqrt(barsPerYear));
}

export function longestStreak(trades: BacktestTradeRecord[], predicate: (t: BacktestTradeRecord) => boolean): number {
  let best = 0;
  let cur = 0;
  for (const t of trades) {
    if (predicate(t)) {
      cur++;
      best = Math.max(best, cur);
    } else {
      cur = 0;
    }
  }
  return best;
}

export function maxDrawdownAbs(equityCurve: EquityPoint[]): number {
  let peak = -Infinity;
  let maxDd = 0;
  for (const p of equityCurve) {
    peak = Math.max(peak, p.equity);
    maxDd = Math.max(maxDd, peak - p.equity);
  }
  return maxDd;
}

function round2(v: number): number {
  return Math.round(v * 100) / 100;
}
function round4(v: number): number {
  return Math.round(v * 10000) / 10000;
}
