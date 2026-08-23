import type {
  Candle,
  CandleRequest,
  MarketClass,
  MarketDataProvider,
  ProviderCapabilities,
  ProviderHealth,
  Quote,
  Timeframe,
} from '../../types';
import { TIMEFRAMES } from '../../types';
import { fetchJson, type HttpFetch } from '../http';
import { CircuitBreaker } from '../circuit-breaker';

const INTERVALS: Record<Timeframe, string> = {
  '1m': '1m',
  '5m': '5m',
  '15m': '15m',
  '1h': '1h',
  '4h': '4h',
  '1d': '1d',
};

const CRYPTO_SYMBOLS = new Set([
  'BTCUSDT', 'ETHUSDT', 'SOLUSDT', 'BNBUSDT', 'XRPUSDT', 'ADAUSDT', 'DOGEUSDT',
  'AVAXUSDT', 'LINKUSDT', 'DOTUSDT', 'MATICUSDT', 'LTCUSDT',
]);

interface BinanceKline extends Array<string | number> {
  // [openTime, open, high, low, close, volume, closeTime, ...]
}

/**
 * REAL crypto market data from Binance's public REST API (no API key needed
 * for market data). If the host cannot reach api.binance.com the provider
 * health check reports DOWN and the provider manager falls back — with the
 * fallback explicitly recorded in the provenance chain.
 */
export class BinanceProvider implements MarketDataProvider {
  readonly name = 'binance';
  readonly synthetic = false;
  readonly priority = 10;

  private breaker = new CircuitBreaker('binance');
  private lastError: string | undefined;

  constructor(
    private readonly baseUrl = process.env.BINANCE_API_BASE ?? 'https://api.binance.com',
    private readonly fetchImpl: HttpFetch = (globalThis.fetch as unknown as HttpFetch) ?? fallbackFetch,
  ) {}

  supportsSymbol(symbol: string): boolean {
    return CRYPTO_SYMBOLS.has(symbol.toUpperCase());
  }

  supportsTimeframe(_symbol: string, tf: Timeframe): boolean {
    return TIMEFRAMES.includes(tf);
  }

  async getCandles(req: CandleRequest): Promise<Candle[]> {
    if (!this.supportsSymbol(req.symbol)) {
      throw new Error(`Binance provider does not list ${req.symbol}`);
    }
    const url =
      `${this.baseUrl}/api/v3/klines?symbol=${encodeURIComponent(req.symbol.toUpperCase())}` +
      `&interval=${INTERVALS[req.timeframe]}&limit=${Math.min(req.limit, 1000)}`;
    const raw = await this.withBreaker<BinanceKline[]>(url);
    return raw.map((k) => ({
      timestamp: Number(k[0]),
      open: Number(k[1]),
      high: Number(k[2]),
      low: Number(k[3]),
      close: Number(k[4]),
      volume: Number(k[5]),
    }));
  }

  async getQuote(symbol: string): Promise<Quote> {
    if (!this.supportsSymbol(symbol)) {
      throw new Error(`Binance provider does not list ${symbol}`);
    }
    const url = `${this.baseUrl}/api/v3/ticker/bookTicker?symbol=${encodeURIComponent(symbol.toUpperCase())}`;
    const t = await this.withBreaker<{ bidPrice: string; askPrice: string }>(url);
    const bid = Number(t.bidPrice);
    const ask = Number(t.askPrice);
    return { symbol: symbol.toUpperCase(), bid, ask, last: (bid + ask) / 2, timestamp: Date.now() };
  }

  async healthCheck(): Promise<ProviderHealth> {
    const started = Date.now();
    try {
      const url = `${this.baseUrl}/api/v3/ping`;
      await this.withBreaker<unknown>(url, 1);
      this.lastError = undefined;
      return {
        name: this.name,
        status: 'UP',
        synthetic: false,
        latencyMs: Date.now() - started,
        lastCheckAt: Date.now(),
        circuitState: this.breaker.currentState(),
        detail: 'Public market-data REST API (no key required)',
      };
    } catch (err) {
      this.lastError = err instanceof Error ? err.message : String(err);
      return {
        name: this.name,
        status: 'DOWN',
        synthetic: false,
        latencyMs: Date.now() - started,
        lastCheckAt: Date.now(),
        lastError: this.lastError,
        circuitState: this.breaker.currentState(),
        detail: 'Unreachable from this host — provider manager will fall back and flag synthetic use.',
      };
    }
  }

  capabilities(): ProviderCapabilities {
    return {
      marketClasses: ['crypto'] as MarketClass[],
      timeframes: [...TIMEFRAMES],
      delayed: false,
      notes: 'Real spot crypto klines/quotes via public REST. Trading endpoints are NOT used in Phase 1.',
    };
  }

  private async withBreaker<T>(url: string, retries = 2): Promise<T> {
    if (!this.breaker.canCall()) {
      throw new Error(`binance circuit breaker OPEN (${this.breaker.snapshot().state})`);
    }
    try {
      const out = await fetchJson<T>(url, { fetchImpl: this.fetchImpl, retries, timeoutMs: 6000 });
      this.breaker.recordSuccess();
      return out;
    } catch (err) {
      this.breaker.recordFailure();
      throw err;
    }
  }
}

function fallbackFetch(): Promise<{ ok: boolean; status: number; text: () => Promise<string> }> {
  return Promise.reject(new Error('No HTTP client available'));
}
