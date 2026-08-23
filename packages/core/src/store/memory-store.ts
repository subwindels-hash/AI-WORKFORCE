import { randomUUID } from 'node:crypto';
import type {
  BacktestRecord, DataStore, JournalEntry, JournalSource, StrategyVersionRecord,
} from './types';

/**
 * In-memory reference store — deterministic, used by tests and by the API when
 * persistence is disabled. The JSON file store (json-file-store.ts) is the
 * persistent default; both implement the identical DataStore contract that a
 * PostgreSQL repository will implement in the production deployment (see
 * db/schema.sql).
 */
export class MemoryStore implements DataStore {
  readonly kind = 'memory' as const;
  private strategies = new Map<string, StrategyVersionRecord>(); // key: id@version
  private backtests = new Map<string, BacktestRecord>();
  private journal: JournalEntry[] = [];

  async listStrategies(): Promise<StrategyVersionRecord[]> {
    return [...this.strategies.values()];
  }

  async getStrategy(id: string, version?: string): Promise<StrategyVersionRecord | null> {
    if (version) return this.strategies.get(key(id, version)) ?? null;
    const all = [...this.strategies.values()].filter((s) => s.strategyId === id);
    if (all.length === 0) return null;
    all.sort((a, b) => (a.updatedAt > b.updatedAt ? 1 : -1));
    return all[all.length - 1];
  }

  async saveStrategy(record: StrategyVersionRecord): Promise<void> {
    this.strategies.set(key(record.strategyId, record.version), structuredClone(record));
  }

  async saveBacktest(record: BacktestRecord): Promise<void> {
    this.backtests.set(record.id, structuredClone(record));
  }

  async getBacktest(id: string): Promise<BacktestRecord | null> {
    return this.backtests.get(id) ?? null;
  }

  async listBacktests(filter?: { strategyId?: string; strategyVersion?: string; limit?: number }): Promise<BacktestRecord[]> {
    let all = [...this.backtests.values()];
    if (filter?.strategyId) all = all.filter((b) => b.request.strategyId === filter.strategyId);
    if (filter?.strategyVersion) all = all.filter((b) => b.request.strategyVersion === filter.strategyVersion);
    all.sort((a, b) => (a.createdAt > b.createdAt ? -1 : 1));
    return all.slice(0, filter?.limit ?? 50);
  }

  async countBacktests(strategyId: string, strategyVersion?: string): Promise<number> {
    return [...this.backtests.values()].filter(
      (b) => b.request.strategyId === strategyId && (!strategyVersion || b.request.strategyVersion === strategyVersion),
    ).length;
  }

  async saveEntry(entry: JournalEntry): Promise<void> {
    const idx = this.journal.findIndex((e) => e.id === entry.id);
    if (idx >= 0) this.journal[idx] = structuredClone(entry);
    else this.journal.push(structuredClone(entry));
  }

  async listEntries(filter?: { source?: JournalSource; strategy?: string; symbol?: string; limit?: number }): Promise<JournalEntry[]> {
    let all = [...this.journal];
    if (filter?.source) all = all.filter((e) => e.source === filter.source);
    if (filter?.strategy) all = all.filter((e) => e.strategy === filter.strategy);
    if (filter?.symbol) all = all.filter((e) => e.symbol === filter.symbol);
    all.sort((a, b) => (a.executionTime > b.executionTime ? -1 : 1));
    return all.slice(0, filter?.limit ?? 200);
  }
}

function key(id: string, version: string): string {
  return `${id}@${version}`;
}

export function newId(): string {
  return randomUUID();
}
