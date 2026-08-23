import { mkdir, readFile, rename, writeFile } from 'node:fs/promises';
import path from 'node:path';
import type {
  BacktestRecord, DataStore, JournalEntry, JournalSource, StrategyVersionRecord,
} from './types';

interface FileShape {
  strategies: StrategyVersionRecord[];
  backtests: BacktestRecord[];
  journal: JournalEntry[];
}

/**
 * Durable single-file store: atomic-write JSON snapshot under DATA_DIR.
 *
 * Honest scope: this is a Phase 2 persistence implementation for a single API
 * process — not a multi-writer database. The production PostgreSQL repository
 * (db/schema.sql) implements the same DataStore contract once the stack runs
 * with a real database service.
 */
export class JsonFileStore implements DataStore {
  readonly kind = 'json-file' as const;
  private state: FileShape = { strategies: [], backtests: [], journal: [] };
  private loaded = false;
  private writeChain: Promise<void> = Promise.resolve();

  constructor(private readonly filePath: string) {}

  private async load(): Promise<void> {
    if (this.loaded) return;
    try {
      const raw = await readFile(this.filePath, 'utf8');
      const parsed = JSON.parse(raw) as Partial<FileShape>;
      this.state = {
        strategies: parsed.strategies ?? [],
        backtests: parsed.backtests ?? [],
        journal: parsed.journal ?? [],
      };
    } catch {
      this.state = { strategies: [], backtests: [], journal: [] }; // fresh start
    }
    this.loaded = true;
  }

  /** Serialize mutations: read-modify-write under a promise chain, atomic rename. */
  private async mutate<T>(fn: (state: FileShape) => T | Promise<T>): Promise<T> {
    await this.load();
    const result = await fn(this.state);
    this.writeChain = this.writeChain.then(() => this.flush());
    await this.writeChain; // data is on disk when the mutation resolves
    return result;
  }

  private async flush(): Promise<void> {
    await mkdir(path.dirname(this.filePath), { recursive: true });
    const tmp = `${this.filePath}.tmp`;
    await writeFile(tmp, JSON.stringify(this.state), 'utf8');
    await rename(tmp, this.filePath);
  }

  async listStrategies(): Promise<StrategyVersionRecord[]> {
    await this.load();
    return structuredClone(this.state.strategies);
  }

  async getStrategy(id: string, version?: string): Promise<StrategyVersionRecord | null> {
    await this.load();
    const all = this.state.strategies.filter((s) => s.strategyId === id);
    if (all.length === 0) return null;
    if (version) return structuredClone(all.find((s) => s.version === version) ?? null);
    all.sort((a, b) => (a.updatedAt > b.updatedAt ? 1 : -1));
    return structuredClone(all[all.length - 1]);
  }

  async saveStrategy(record: StrategyVersionRecord): Promise<void> {
    await this.mutate((state) => {
      const key = (s: StrategyVersionRecord) => `${s.strategyId}@${s.version}`;
      const idx = state.strategies.findIndex((s) => key(s) === key(record));
      if (idx >= 0) state.strategies[idx] = structuredClone(record);
      else state.strategies.push(structuredClone(record));
    });
  }

  async saveBacktest(record: BacktestRecord): Promise<void> {
    await this.mutate((state) => {
      const idx = state.backtests.findIndex((b) => b.id === record.id);
      if (idx >= 0) state.backtests[idx] = structuredClone(record);
      else state.backtests.push(structuredClone(record));
    });
  }

  async getBacktest(id: string): Promise<BacktestRecord | null> {
    await this.load();
    return structuredClone(this.state.backtests.find((b) => b.id === id) ?? null);
  }

  async listBacktests(filter?: { strategyId?: string; strategyVersion?: string; limit?: number }): Promise<BacktestRecord[]> {
    await this.load();
    let all = [...this.state.backtests];
    if (filter?.strategyId) all = all.filter((b) => b.request.strategyId === filter.strategyId);
    if (filter?.strategyVersion) all = all.filter((b) => b.request.strategyVersion === filter.strategyVersion);
    all.sort((a, b) => (a.createdAt > b.createdAt ? -1 : 1));
    return structuredClone(all.slice(0, filter?.limit ?? 50));
  }

  async countBacktests(strategyId: string, strategyVersion?: string): Promise<number> {
    await this.load();
    return this.state.backtests.filter(
      (b) => b.request.strategyId === strategyId && (!strategyVersion || b.request.strategyVersion === strategyVersion),
    ).length;
  }

  async saveEntry(entry: JournalEntry): Promise<void> {
    await this.mutate((state) => {
      const idx = state.journal.findIndex((e) => e.id === entry.id);
      if (idx >= 0) state.journal[idx] = entry;
      else state.journal.push(entry);
    });
  }

  async listEntries(filter?: { source?: JournalSource; strategy?: string; symbol?: string; limit?: number }): Promise<JournalEntry[]> {
    await this.load();
    let all = [...this.state.journal];
    if (filter?.source) all = all.filter((e) => e.source === filter.source);
    if (filter?.strategy) all = all.filter((e) => e.strategy === filter.strategy);
    if (filter?.symbol) all = all.filter((e) => e.symbol === filter.symbol);
    all.sort((a, b) => (a.executionTime > b.executionTime ? -1 : 1));
    return structuredClone(all.slice(0, filter?.limit ?? 200));
  }
}
