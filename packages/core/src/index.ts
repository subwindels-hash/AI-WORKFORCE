export * from './types';
export * from './config/defaults';
export * from './utils/math';
export * from './utils/validate';
export * from './marketdata/provider-manager';
export * from './marketdata/circuit-breaker';
export * from './marketdata/cache';
export * from './marketdata/http';
export * from './marketdata/providers/synthetic';
export * from './marketdata/providers/binance';
export * from './marketdata/providers/frankfurter';
export * from './indicators/indicators';
export * from './agents/base';
export * from './agents/technical';
export * from './agents/market-structure';
export * from './agents/forex';
export * from './agents/crypto';
export * from './agents/sentiment';
export * from './agents/intelligence';
export * from './analysis/regime';
export * from './analysis/setup-generator';
export * from './risk/risk-engine';
export * from './events/events';
export * from './engine/trading-intelligence-engine';
export * from './status/integrations';
export * from './store/types';
export * from './store/memory-store';
export * from './store/json-file-store';
export * from './strategies/types';
export * from './strategies/series-view';
export * from './strategies/registry';
export * from './strategies/builtins';
export * from './backtesting/engine';
export * from './backtesting/metrics';
export * from './journal/analytics';

import { ProviderManager } from './marketdata/provider-manager';
import { BinanceProvider } from './marketdata/providers/binance';
import { FrankfurterProvider } from './marketdata/providers/frankfurter';
import { SyntheticDemoProvider } from './marketdata/providers/synthetic';
import { TradingIntelligenceEngine } from './engine/trading-intelligence-engine';
import { RiskEngine } from './risk/risk-engine';
import { EventBus, JsonlAuditSink } from './events/events';
import { DEFAULT_RISK_LIMITS } from './config/defaults';
import { MemoryStore, newId } from './store/memory-store';
import { JsonFileStore } from './store/json-file-store';
import type { DataStore } from './store/types';
import { StrategyRegistry } from './strategies/registry';
import { runBacktest } from './backtesting/engine';
import path from 'node:path';

export interface PlatformOptions {
  auditFilePath?: string;
  disableRealProviders?: boolean;
  /** Durable JSON-file store path; omit for an in-memory store (tests). */
  storeFilePath?: string;
}

/** Wire the full platform: providers -> engines -> risk -> strategies -> store -> audit. */
export async function createPlatform(opts: PlatformOptions = {}) {
  const eventBus = new EventBus();
  if (opts.auditFilePath) {
    const sink = new JsonlAuditSink(opts.auditFilePath);
    eventBus.subscribe((e) => {
      void sink.append(e).catch(() => undefined);
    });
  }

  const providerManager = new ProviderManager({
    onFallback: ({ symbol, failed, used }) => {
      eventBus.emit('PROVIDER_FALLBACK', `${symbol}: providers [${failed.join(', ')}] failed — falling back to ${used}`, {
        symbol,
        failed,
        used,
      });
    },
  });

  if (!opts.disableRealProviders) {
    providerManager.register(new BinanceProvider());
    providerManager.register(new FrankfurterProvider());
  }
  providerManager.register(new SyntheticDemoProvider()); // ALWAYS last — clearly labeled.

  const store: DataStore = opts.storeFilePath
    ? new JsonFileStore(opts.storeFilePath)
    : new MemoryStore();

  const riskEngine = new RiskEngine({ ...DEFAULT_RISK_LIMITS });
  const engine = new TradingIntelligenceEngine({ providerManager, riskEngine, eventBus });
  const strategyRegistry = new StrategyRegistry(store, eventBus);
  await strategyRegistry.seedBuiltins();

  return {
    engine,
    providerManager,
    riskEngine,
    eventBus,
    store,
    strategyRegistry,
    /** Run a backtest for a registered strategy version (all deps wired). */
    runBacktest: (input: Parameters<typeof runBacktest>[1]) => {
      const impl = strategyRegistry.getImplementation(input.strategyId, input.strategyVersion);
      if (!impl) {
        throw new Error(`Strategy ${input.strategyId}@${input.strategyVersion} is not registered`);
      }
      return runBacktest({ providerManager, store, eventBus, strategy: impl }, input);
    },
  };
}

export { newId };

