import { describe, expect, it } from 'vitest';
import { MemoryStore } from '../src/store/memory-store';
import { JsonFileStore } from '../src/store/json-file-store';
import type { BacktestRecord, JournalEntry, StrategyVersionRecord } from '../src/store/types';
import { mkdtemp } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';

function strategyRecord(id = 'st'): StrategyVersionRecord {
  return {
    strategyId: id, version: '1.0.0', name: 'Test', description: '',
    marketClasses: ['crypto'] as never, timeframes: ['1h'] as never,
    params: { stopAtr: 2 }, source: 'builtin', lifecycle: 'DRAFT',
    createdAt: new Date().toISOString(), updatedAt: new Date().toISOString(), lifecycleHistory: [],
  };
}

function backtestRecord(id: string, strategyId: string): BacktestRecord {
  return {
    id, createdAt: new Date().toISOString(),
    request: { strategyId, strategyVersion: '1.0.0', symbol: 'BTCUSDT', marketClass: 'crypto', timeframe: '1h', initialEquity: 10000, riskPct: 0.01, feeBps: 2, spreadBps: 2, slippageBps: 2, allowShorts: false },
    dataProvenance: { source: 'synthetic-demo', synthetic: true, candles: 100, from: '', to: '' },
    metrics: {} as never, equityCurve: [], trades: [], warnings: [],
  };
}

describe('MemoryStore', () => {
  it('round-trips strategies, backtests and journal entries', async () => {
    const s = new MemoryStore();
    await s.saveStrategy(strategyRecord('a'));
    await s.saveBacktest(backtestRecord('bt1', 'a'));
    await s.saveEntry({ id: 'j1', source: 'manual', symbol: 'X', market: 'crypto', strategy: null, strategyVersion: null, direction: 'LONG', entry: { time: 't', price: 1 }, exit: null, positionSize: 1, stopLoss: null, takeProfit: null, fees: 0, slippage: 0, pnl: null, pnlPct: null, rMultiple: null, reasonForTrade: 'r', aiConfidence: null, confidenceSource: null, agentConsensus: null, riskScore: null, executionTime: 't' } as JournalEntry);

    expect((await s.getStrategy('a', '1.0.0'))?.strategyId).toBe('a');
    expect(await s.countBacktests('a')).toBe(1);
    expect((await s.listBacktests({ strategyId: 'a' }))[0].id).toBe('bt1');
    expect((await s.listEntries({ source: 'manual' }))).toHaveLength(1);
  });
});

describe('JsonFileStore', () => {
  it('persists across instances (durability)', async () => {
    const dir = await mkdtemp(path.join(tmpdir(), 'aegis-store-'));
    const file = path.join(dir, 'store.json');

    const first = new JsonFileStore(file);
    await first.saveStrategy(strategyRecord('persist'));
    await first.saveBacktest(backtestRecord('bt-persist', 'persist'));
    await first.saveEntry({ id: 'j-persist', source: 'backtest', symbol: 'BTCUSDT', market: 'crypto', strategy: 'persist', strategyVersion: '1.0.0', direction: 'LONG', entry: { time: 't', price: 1 }, exit: null, positionSize: 1, stopLoss: null, takeProfit: null, fees: 0, slippage: 0, pnl: null, pnlPct: null, rMultiple: null, reasonForTrade: 'r', aiConfidence: 0.5, confidenceSource: 'strategy', agentConsensus: null, riskScore: null, executionTime: 't' } as JournalEntry);
    await new Promise((r) => setTimeout(r, 120)); // let the write chain flush

    const second = new JsonFileStore(file);
    expect((await second.getStrategy('persist', '1.0.0'))?.strategyId).toBe('persist');
    expect(await second.countBacktests('persist')).toBe(1);
    expect((await second.listEntries({ strategy: 'persist' }))[0].id).toBe('j-persist');
  });

  it('starts fresh when the file does not exist', async () => {
    const dir = await mkdtemp(path.join(tmpdir(), 'aegis-store-'));
    const s = new JsonFileStore(path.join(dir, 'missing.json'));
    expect(await s.listStrategies()).toEqual([]);
  });

  it('mutating one instance does not leak unsaved objects into another', async () => {
    const dir = await mkdtemp(path.join(tmpdir(), 'aegis-store-'));
    const file = path.join(dir, 'store.json');
    const s = new JsonFileStore(file);
    const rec = strategyRecord('clone');
    await s.saveStrategy(rec);
    rec.lifecycle = 'APPROVED'; // external mutation after save
    const fresh = new JsonFileStore(file);
    expect((await fresh.getStrategy('clone', '1.0.0'))?.lifecycle).toBe('DRAFT');
  });
});
