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

import { ProviderManager } from './marketdata/provider-manager';
import { BinanceProvider } from './marketdata/providers/binance';
import { FrankfurterProvider } from './marketdata/providers/frankfurter';
import { SyntheticDemoProvider } from './marketdata/providers/synthetic';
import { TradingIntelligenceEngine } from './engine/trading-intelligence-engine';
import { RiskEngine } from './risk/risk-engine';
import { EventBus, JsonlAuditSink } from './events/events';
import { DEFAULT_RISK_LIMITS } from './config/defaults';

export interface PlatformOptions {
  auditFilePath?: string;
  disableRealProviders?: boolean;
}

/** Wire the full Phase-1 platform: providers -> engine -> risk -> audit. */
export function createPlatform(opts: PlatformOptions = {}) {
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

  const riskEngine = new RiskEngine({ ...DEFAULT_RISK_LIMITS });
  const engine = new TradingIntelligenceEngine({ providerManager, riskEngine, eventBus });

  return { engine, providerManager, riskEngine, eventBus };
}
