import { describe, expect, it } from 'vitest';
import { analyzeJournal, bucketMetrics, confidenceCalibration } from '../src/journal/analytics';
import type { JournalEntry } from '../src/store/types';

let n = 0;
function entry(over: Partial<JournalEntry>): JournalEntry {
  n++;
  return {
    id: `e${n}`,
    source: 'backtest',
    symbol: 'BTCUSDT',
    market: 'crypto',
    strategy: 'trend-following',
    strategyVersion: '1.0.0',
    direction: 'LONG',
    entry: { time: `2026-08-${String(n % 28 + 1).padStart(2, '0')}T10:00:00Z`, price: 100 },
    exit: { time: `2026-08-${String(n % 28 + 1).padStart(2, '0')}T12:00:00Z`, price: 101 },
    positionSize: 10,
    stopLoss: 98,
    takeProfit: 105,
    fees: 1,
    slippage: 0.5,
    pnl: 10,
    pnlPct: 1,
    rMultiple: 0.5,
    reasonForTrade: 'test',
    aiConfidence: null,
    confidenceSource: null,
    agentConsensus: null,
    riskScore: 0.01,
    executionTime: `2026-08-${String(n % 28 + 1).padStart(2, '0')}T10:00:00Z`,
    ...over,
  };
}

describe('bucketMetrics', () => {
  it('computes win rate, profit factor and expectancy from closed trades only', () => {
    const entries = [
      entry({ pnl: 100, rMultiple: 1 }),
      entry({ pnl: -50, rMultiple: -0.5 }),
      entry({ pnl: 200, rMultiple: 2 }),
      entry({ pnl: -50, rMultiple: -0.5 }),
      entry({ pnl: null, exit: null, rMultiple: null }), // open trade ignored
    ];
    const m = bucketMetrics(entries);
    expect(m.count).toBe(4);
    expect(m.winRate).toBeCloseTo(0.5, 3);
    expect(m.profitFactor).toBeCloseTo(300 / 100, 3);
    expect(m.expectancyPnl).toBeCloseTo(50, 1);
    expect(m.avgWin).toBeCloseTo(150, 1);
    expect(m.avgLoss).toBeCloseTo(-50, 1);
    expect(m.avgRMultiple).toBeCloseTo(0.5, 3);
  });

  it('reports null profit factor when there are no losses', () => {
    const m = bucketMetrics([entry({ pnl: 50 }), entry({ pnl: 60 })]);
    expect(m.profitFactor).toBeNull();
  });
});

describe('analyzeJournal groupings', () => {
  it('groups by strategy with per-group metrics', () => {
    const entries = [
      entry({ strategy: 'trend-following', pnl: 100 }),
      entry({ strategy: 'trend-following', pnl: -40 }),
      entry({ strategy: 'mean-reversion', pnl: -30, symbol: 'EURUSD', market: 'forex' }),
    ];
    const r = analyzeJournal(entries, 'strategy');
    expect(r.groupBy).toBe('strategy');
    const trend = r.groups.find((g) => g.key === 'trend-following');
    const mr = r.groups.find((g) => g.key === 'mean-reversion');
    expect(trend?.metrics.count).toBe(2);
    expect(mr?.metrics.count).toBe(1);
    expect(r.overall.closedTrades).toBe(3);
  });

  it('groups by market and symbol', () => {
    const entries = [
      entry({ market: 'crypto', symbol: 'BTCUSDT', pnl: 10 }),
      entry({ market: 'crypto', symbol: 'ETHUSDT', pnl: -5 }),
      entry({ market: 'forex', symbol: 'EURUSD', pnl: 20 }),
    ];
    expect(analyzeJournal(entries, 'market').groups).toHaveLength(2);
    expect(analyzeJournal(entries, 'symbol').groups).toHaveLength(3);
  });

  it('empty confidence grouping yields an honest note', () => {
    const r = analyzeJournal([entry({ pnl: 5, aiConfidence: null })], 'confidence');
    expect(r.groups).toHaveLength(0);
    expect(r.note).toMatch(/No confidence-tagged trades/);
  });
});

describe('confidence calibration (the key question)', () => {
  it('reports insufficient data below 30 tagged trades', () => {
    const entries = Array.from({ length: 10 }, (_, i) => entry({ pnl: i % 2 === 0 ? 10 : -10, aiConfidence: 0.7 }));
    const r = confidenceCalibration(entries);
    expect(r.sufficientData).toBe(false);
    expect(r.verdict).toMatch(/Sample too small/);
  });

  it('detects monotonic calibration (high confidence -> higher win rate)', () => {
    const low = Array.from({ length: 20 }, (_, i) =>
      entry({ pnl: i % 3 === 0 ? 15 : -10, aiConfidence: 0.3 })); // 33% win
    const high = Array.from({ length: 20 }, (_, i) =>
      entry({ pnl: i % 4 !== 0 ? 15 : -10, aiConfidence: 0.9 })); // 75% win
    const r = confidenceCalibration([...low, ...high]);
    expect(r.sufficientData).toBe(true);
    expect(r.buckets).toHaveLength(2);
    const lowB = r.buckets.find((b) => b.key.startsWith('0–40'));
    const highB = r.buckets.find((b) => b.key.startsWith('80–100'));
    expect((highB?.winRate as number)).toBeGreaterThan(lowB?.winRate as number);
    expect(r.verdict).toMatch(/directionally informative/);
  });

  it('flags non-monotonic calibration honestly', () => {
    // LOW confidence wins MORE than high confidence -> calibration is broken.
    const lowConf = Array.from({ length: 20 }, (_, i) => entry({ pnl: i % 2 === 0 ? 15 : -10, aiConfidence: 0.3 })); // 50% win
    const highConf = Array.from({ length: 20 }, (_, i) => entry({ pnl: i % 3 === 0 ? 15 : -10, aiConfidence: 0.9 })); // 35% win
    const r = confidenceCalibration([...lowConf, ...highConf]);
    expect(r.sufficientData).toBe(true);
    expect(r.verdict).toMatch(/does NOT consistently increase/);
  });
});
