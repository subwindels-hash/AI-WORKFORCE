import { describe, expect, it } from 'vitest';
import {
  longestStreak, maxDrawdownAbs, sharpeRatio, sortinoRatio,
} from '../src/backtesting/metrics';
import type { BacktestTradeRecord } from '../src/store/types';

describe('sharpe / sortino', () => {
  it('returns null without variance', () => {
    expect(sharpeRatio([0.01, 0.01, 0.01], 8760)).toBeNull();
    expect(sharpeRatio([0.01], 8760)).toBeNull();
  });

  it('computes annualized Sharpe on a hand-calculated fixture', () => {
    const r = [0.1, -0.05, 0.1, -0.05];
    // mean 0.025, population sd 0.075 -> sharpe = (1/3)*sqrt(barsPerYear)
    const barsPerYear = (365 * 24 * 3_600_000) / 3_600_000; // 1h timeframe -> 8760
    expect(sharpeRatio(r, barsPerYear)).toBeCloseTo((1 / 3) * Math.sqrt(barsPerYear), 3);
  });

  it('computes annualized Sortino with full-sample downside deviation', () => {
    const r = [0.1, -0.05, 0.1, -0.05];
    const barsPerYear = 8760;
    const dd = Math.sqrt((0.05 ** 2 * 2) / 4);
    expect(sortinoRatio(r, barsPerYear)).toBeCloseTo((0.025 / dd) * Math.sqrt(barsPerYear), 3);
  });

  it('returns null Sortino when there is no downside and positive mean', () => {
    expect(sortinoRatio([0.02, 0.03], 8760)).toBeNull();
  });
});

describe('streaks and drawdown', () => {
  const t = (netPnl: number): BacktestTradeRecord =>
    ({ netPnl }) as BacktestTradeRecord;

  it('finds the longest win/loss streaks', () => {
    const pnls = [10, 5, -3, 8, 7, 6, -2, -1, -4, 2];
    expect(longestStreak(pnls.map(t), (x) => x.netPnl > 0)).toBe(3);
    expect(longestStreak(pnls.map(t), (x) => x.netPnl <= 0)).toBe(3);
  });

  it('computes max drawdown from an equity curve', () => {
    const curve = [
      { time: 'a', equity: 100, drawdownPct: 0 },
      { time: 'b', equity: 120, drawdownPct: 0 },
      { time: 'c', equity: 90, drawdownPct: 25 },
      { time: 'd', equity: 110, drawdownPct: 8.3 },
      { time: 'e', equity: 95, drawdownPct: 20.8 },
    ];
    expect(maxDrawdownAbs(curve)).toBe(30); // 120 -> 90
  });
});
