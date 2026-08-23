import {
  STALENESS_MS,
  TIMEFRAME_MS,
  type Candle,
  type CandleRequest,
  type CandleSeries,
  type DataProvenance,
  type MarketClass,
  type MarketDataProvider,
  type ProviderHealth,
  type Quote,
  type Timeframe,
} from '../types';
import { SYMBOL_MARKET_CLASS } from '../config/defaults';
import { normalizeCandles } from '../utils/validate';
import { TtlCache } from './cache';
import { sleep } from './http';

export interface ProviderManagerOptions {
  /** Cache TTL as a fraction of the timeframe interval (default 0.25, min 15s). */
  cacheTtlFraction?: number;
  onFallback?: (info: { symbol: string; failed: string[]; used: string }) => void;
}

interface FetchOutcome {
  candles: Candle[];
  provider: MarketDataProvider;
  failedProviders: string[];
}

/**
 * Resolves market data from the provider registry with:
 * caching -> health/circuit-breaker gating -> priority order -> fallback chain.
 *
 * The synthetic provider sits last in the chain. When it ends up serving data,
 * the returned provenance carries `synthetic: true` so downstream layers can
 * display the mandatory SIMULATION banner and (optionally) refuse trade setups.
 */
export class ProviderManager {
  private providers: MarketDataProvider[] = [];
  private candleCache = new TtlCache<{ candles: Candle[]; providerName: string }>(60_000);
  private quoteCache = new TtlCache<{ quote: Quote; providerName: string }>(15_000);
  private healthCache = new TtlCache<ProviderHealth>(10_000);
  private failureLog: Record<string, string> = {};

  constructor(private readonly options: ProviderManagerOptions = {}) {}

  register(provider: MarketDataProvider): this {
    this.providers.push(provider);
    this.providers.sort((a, b) => a.priority - b.priority);
    return this;
  }

  registerAll(providers: MarketDataProvider[]): this {
    providers.forEach((p) => this.register(p));
    return this;
  }

  listProviders(): MarketDataProvider[] {
    return [...this.providers];
  }

  /** Providers that could plausibly serve this symbol/timeframe, in priority order. */
  candidatesFor(symbol: string, timeframe: Timeframe): MarketDataProvider[] {
    return this.providers
      .filter((p) => p.supportsSymbol(symbol))
      .sort((a, b) => a.priority - b.priority)
      // Move providers that refuse this timeframe to the very end (they can
      // only serve via full synthetic generation anyway).
      .sort((a, b) => Number(b.supportsTimeframe(symbol, timeframe)) - Number(a.supportsTimeframe(symbol, timeframe)));
  }

  async getCandleSeries(
    symbol: string,
    marketClass: MarketClass,
    timeframe: Timeframe,
    limit: number,
  ): Promise<CandleSeries> {
    const fetchedAt = Date.now();
    const { candles, provider, failedProviders } = await this.fetchCandles({ symbol, timeframe, limit });
    const { candles: normalized, validation } = normalizeCandles(candles, timeframe);

    const dataTimestamp = normalized.length ? normalized[normalized.length - 1].timestamp : 0;
    const staleThreshold = STALENESS_MS[timeframe];
    const dataAgeMs = Math.max(0, fetchedAt - dataTimestamp);

    const provenance: DataProvenance = {
      source: provider.name,
      synthetic: provider.synthetic,
      live: !provider.synthetic,
      delayed: provider.capabilities().delayed,
      fetchedAt,
      dataTimestamp,
      dataAgeMs,
      stale: dataTimestamp === 0 || dataAgeMs > staleThreshold,
      fallbackChain: failedProviders,
    };

    return { symbol, marketClass, timeframe, candles: normalized, provenance, validation };
  }

  async getQuote(symbol: string): Promise<{ quote: Quote; provider: MarketDataProvider; failedProviders: string[] }> {
    const cached = this.quoteCache.get(`quote:${symbol}`);
    if (cached) {
      const p = this.providerByName(cached.providerName);
      if (p) return { quote: cached.quote, provider: p, failedProviders: [] };
    }

    const failed: string[] = [];
    for (const provider of this.providers.filter((p) => p.supportsSymbol(symbol)).sort((a, b) => a.priority - b.priority)) {
      try {
        const quote = await provider.getQuote(symbol);
        this.quoteCache.set(`quote:${symbol}`, { quote, providerName: provider.name });
        return { quote, provider, failedProviders: failed };
      } catch (err) {
        this.failureLog[provider.name] = err instanceof Error ? err.message : String(err);
        failed.push(provider.name);
      }
    }
    throw new Error(`No provider could serve a quote for ${symbol}. Failed: ${failed.join(', ') || 'none registered'}`);
  }

  async getAllHealth(force = false): Promise<ProviderHealth[]> {
    const results: ProviderHealth[] = [];
    for (const p of this.providers) {
      if (!force) {
        const cached = this.healthCache.get(`health:${p.name}`);
        if (cached) {
          results.push(cached);
          continue;
        }
      }
      let health: ProviderHealth;
      try {
        health = await p.healthCheck();
      } catch (err) {
        health = {
          name: p.name,
          status: 'DOWN',
          synthetic: p.synthetic,
          lastCheckAt: Date.now(),
          lastError: err instanceof Error ? err.message : String(err),
        };
      }
      if (this.failureLog[p.name] && health.status === 'UP') health.status = 'DEGRADED';
      this.healthCache.set(`health:${p.name}`, health);
      results.push(health);
    }
    return results;
  }

  recentFailure(name: string): string | undefined {
    return this.failureLog[name];
  }

  clearCaches(): void {
    this.candleCache.clear();
    this.quoteCache.clear();
    this.healthCache.clear();
  }

  cacheStats(): { size: number; hits: number; misses: number } {
    return this.candleCache.stats();
  }

  private async fetchCandles(req: CandleRequest): Promise<FetchOutcome> {
    const { symbol, timeframe, limit } = req;
    const cacheKey = `candles:${symbol}:${timeframe}:${limit}`;
    const cached = this.candleCache.get(cacheKey);
    if (cached) {
      const provider = this.providerByName(cached.providerName);
      if (provider) return { candles: cached.candles, provider, failedProviders: [] };
    }

    const candidates = this.candidatesFor(symbol, timeframe);
    const failed: string[] = [];

    for (const provider of candidates) {
      try {
        const candles = await provider.getCandles({ symbol, timeframe, limit });
        if (!Array.isArray(candles) || candles.length === 0) throw new Error('empty candle response');
        const ttl = Math.max(15_000, TIMEFRAME_MS[timeframe] * (this.options.cacheTtlFraction ?? 0.25));
        this.candleCache.set(cacheKey, { candles, providerName: provider.name }, ttl);
        if (failed.length > 0) {
          this.options.onFallback?.({ symbol, failed: [...failed], used: provider.name });
        }
        return { candles, provider, failedProviders: failed };
      } catch (err) {
        this.failureLog[provider.name] = err instanceof Error ? err.message : String(err);
        failed.push(provider.name);
        await sleep(50); // brief pause before trying the next provider
      }
    }
    throw new Error(
      `No provider could serve candles for ${symbol} ${timeframe}. Failed: ${failed.join(', ') || 'none registered'}`,
    );
  }

  private providerByName(name: string): MarketDataProvider | undefined {
    return this.providers.find((p) => p.name === name);
  }
}

/** Resolve the market class of a known symbol (USDT pairs are crypto; else forex-style parsing). */
export function marketClassOfSymbol(symbol: string): MarketClass {
  const s = symbol.toUpperCase();
  const known = SYMBOL_MARKET_CLASS[s];
  if (known) return known as MarketClass;
  if (s.endsWith('USDT')) return 'crypto';
  return 'forex';
}
