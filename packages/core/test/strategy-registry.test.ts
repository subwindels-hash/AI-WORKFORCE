import { describe, expect, it, beforeEach } from 'vitest';
import { StrategyRegistry, validateMetrics, DEFAULT_VALIDATION_CRITERIA } from '../src/strategies/registry';
import { MemoryStore } from '../src/store/memory-store';
import { EventBus } from '../src/events/events';
import type { BacktestRecord, BacktestMetrics, StrategyVersionRecord } from '../src/store/types';
import type { TradingStrategy } from '../src/strategies/types';
import type { Candle } from '../src/types';

function passingMetrics(): BacktestMetrics {
  return {
    totalReturnPct: 12, finalEquity: 11_200, trades: 40, winRate: 0.55, lossRate: 0.45,
    profitFactor: 1.6, expectancyR: 0.25, expectancyPnl: 30, avgWin: 120, avgLoss: -70,
    avgTrade: 30, sharpe: 1.4, sortino: 2.1, maxDrawdownPct: 8, maxDrawdownAbs: 900,
    longestWinStreak: 6, longestLossStreak: 4, exposurePct: 35, totalFees: 300, totalSlippage: 120,
  };
}

function backtestRecord(strategyKey: string, metrics: BacktestMetrics, seq = 0): BacktestRecord {
  const [id, version] = strategyKey.split('@');
  return {
    id: `bt-${seq}-${Math.random().toString(36).slice(2)}`,
    createdAt: new Date(Date.now() + seq * 1000).toISOString(),
    request: {
      strategyId: id, strategyVersion: version,
      symbol: 'BTCUSDT', marketClass: 'crypto', timeframe: '1h',
      initialEquity: 10_000, riskPct: 0.01, feeBps: 2, spreadBps: 2, slippageBps: 2, allowShorts: false,
    },
    dataProvenance: { source: 'synthetic-demo', synthetic: true, candles: 500, from: '', to: '' },
    metrics, equityCurve: [], trades: [], warnings: [],
  };
}

const aiStrategy: TradingStrategy = {
  id: 'ai-experiment', version: '0.1.0', name: 'AI experiment', description: 'generated',
  marketClasses: ['crypto'] as never, timeframes: ['1h'] as never,
  params: { stopAtr: 2 }, supportsShorts: false,
  evaluate: () => ({ action: 'HOLD', reason: 'n/a', confidence: 0 }),
};

describe('StrategyRegistry — lifecycle gates', () => {
  let store: MemoryStore;
  let bus: EventBus;
  let registry: StrategyRegistry;

  beforeEach(() => {
    store = new MemoryStore();
    bus = new EventBus();
    registry = new StrategyRegistry(store, bus);
  });

  it('seeds the four built-in strategies as DRAFT', async () => {
    await registry.seedBuiltins();
    const list = await registry.listVersions();
    expect(list.map((s) => s.strategyId).sort()).toEqual(['breakout', 'mean-reversion', 'momentum', 'trend-following']);
    expect(list.every((s) => s.lifecycle === 'DRAFT')).toBe(true);
    expect(list.every((s) => s.source === 'builtin')).toBe(true);
    expect(list.every((s) => typeof s.params.stopAtr === 'number')).toBe(true);
  });

  it('rejects skipped lifecycle stages', async () => {
    await registry.seedBuiltins();
    const res = await registry.transition({ strategyId: 'trend-following', version: '1.0.0', to: 'VALIDATED' });
    expect(res.ok).toBe(false);
    expect(res.reasons[0]).toMatch(/Invalid transition DRAFT -> VALIDATED/);
  });

  it('refuses BACKTESTED without any completed backtest', async () => {
    await registry.seedBuiltins();
    const res = await registry.transition({ strategyId: 'trend-following', version: '1.0.0', to: 'BACKTESTED' });
    expect(res.ok).toBe(false);
    expect(res.reasons[0]).toMatch(/No completed backtest/);
  });

  it('advances DRAFT -> BACKTESTED once a backtest exists, with audit event', async () => {
    await registry.seedBuiltins();
    await store.saveBacktest(backtestRecord('trend-following@1.0.0', passingMetrics()));
    const res = await registry.transition({ strategyId: 'trend-following', version: '1.0.0', to: 'BACKTESTED' });
    expect(res.ok).toBe(true);
    expect(res.record?.lifecycle).toBe('BACKTESTED');
    expect(bus.recent().some((e) => e.type === 'STRATEGY_STATUS_CHANGED')).toBe(true);
  });

  it('VALIDATED gate: rejects a too-small sample and accepts a passing one', async () => {
    await registry.seedBuiltins();
    const key = 'trend-following@1.0.0';
    await store.saveBacktest(backtestRecord(key, { ...passingMetrics(), trades: 4 }, 1));
    let res = await registry.transition({ strategyId: 'trend-following', version: '1.0.0', to: 'BACKTESTED' });
    expect(res.ok).toBe(true);
    res = await registry.transition({ strategyId: 'trend-following', version: '1.0.0', to: 'VALIDATED' });
    expect(res.ok).toBe(false);
    expect(res.reasons.join(' ')).toMatch(/Sample size too small: 4/);

    // now with a healthy sample on the LATEST backtest (later createdAt)
    await store.saveBacktest(backtestRecord(key, passingMetrics(), 2));
    res = await registry.transition({ strategyId: 'trend-following', version: '1.0.0', to: 'VALIDATED' });
    expect(res.ok).toBe(true);
  });

  it('RISK_REVIEWED gate passes for a sound builtin strategy', async () => {
    await registry.seedBuiltins();
    const key = 'trend-following@1.0.0';
    await store.saveBacktest(backtestRecord(key, passingMetrics()));
    await registry.transition({ strategyId: 'trend-following', version: '1.0.0', to: 'BACKTESTED' });
    await registry.transition({ strategyId: 'trend-following', version: '1.0.0', to: 'VALIDATED' });
    const res = await registry.transition({ strategyId: 'trend-following', version: '1.0.0', to: 'RISK_REVIEWED' });
    expect(res.ok).toBe(true);
    // The next stages are honestly blocked in Phase 2
    const paper = await registry.transition({ strategyId: 'trend-following', version: '1.0.0', to: 'PAPER_TRADING' });
    expect(paper.ok).toBe(false);
    expect(paper.reasons[0]).toMatch(/Phase 3/);
  });

  it('blocks AI-generated strategies at risk review (no automatic advancement)', async () => {
    await registry.registerImplementation(aiStrategy, 'ai');
    const key = 'ai-experiment@0.1.0';
    await store.saveBacktest(backtestRecord(key, passingMetrics()));
    await registry.transition({ strategyId: 'ai-experiment', version: '0.1.0', to: 'BACKTESTED' });
    await registry.transition({ strategyId: 'ai-experiment', version: '0.1.0', to: 'VALIDATED' });
    const res = await registry.transition({ strategyId: 'ai-experiment', version: '0.1.0', to: 'RISK_REVIEWED' });
    expect(res.ok).toBe(false);
    expect(res.reasons.join(' ')).toMatch(/AI-generated strategies require manual human risk sign-off/);
  });

  it('RETIRED is terminal', async () => {
    await registry.seedBuiltins();
    await registry.transition({ strategyId: 'momentum', version: '1.0.0', to: 'RETIRED' });
    const res = await registry.transition({ strategyId: 'momentum', version: '1.0.0', to: 'BACKTESTED' });
    expect(res.ok).toBe(false);
    expect(res.reasons[0]).toMatch(/RETIRED/);
  });
});

describe('validateMetrics (pure)', () => {
  const criteria = DEFAULT_VALIDATION_CRITERIA;

  it('accepts a healthy report', () => {
    const r = validateMetrics(passingMetrics(), criteria, 'BTCUSDT');
    expect(r.ok).toBe(true);
  });

  it('flags drawdown breaches, weak profit factor and negative expectancy', () => {
    const bad = validateMetrics(
      { ...passingMetrics(), maxDrawdownPct: 60, profitFactor: 0.9, expectancyPnl: -10 },
      criteria, 'BTCUSDT',
    );
    expect(bad.ok).toBe(false);
    expect(bad.reasons.some((x) => /drawdown/i.test(x))).toBe(true);
    expect(bad.reasons.some((x) => /Profit factor/.test(x))).toBe(true);
    expect(bad.reasons.some((x) => /Negative expectancy/.test(x))).toBe(true);
  });

  it('warns about suspiciously high Sharpe (over-fitting sentinel)', () => {
    const r = validateMetrics({ ...passingMetrics(), sharpe: 5.5 }, criteria, 'X');
    expect(r.ok).toBe(true);
    expect(r.warnings.some((w) => /suspiciously high/.test(w))).toBe(true);
  });
});

describe('strategy store records', () => {
  it('keeps lifecycle history per version', async () => {
    const store = new MemoryStore();
    const bus = new EventBus();
    const registry = new StrategyRegistry(store, bus);
    await registry.seedBuiltins();
    await store.saveBacktest(backtestRecord('trend-following@1.0.0', passingMetrics()));
    await registry.transition({ strategyId: 'trend-following', version: '1.0.0', to: 'BACKTESTED' });
    const record: StrategyVersionRecord | null = await store.getStrategy('trend-following', '1.0.0');
    expect(record?.lifecycleHistory.map((h) => h.to)).toEqual(['DRAFT', 'BACKTESTED']);
  });
});
