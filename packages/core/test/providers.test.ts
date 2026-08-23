import { describe, expect, it, vi } from 'vitest';
import { ProviderManager } from '../src/marketdata/provider-manager';
import { CircuitBreaker } from '../src/marketdata/circuit-breaker';
import { SyntheticDemoProvider, generateSyntheticCandles } from '../src/marketdata/providers/synthetic';
import { BinanceProvider } from '../src/marketdata/providers/binance';
import { FrankfurterProvider } from '../src/marketdata/providers/frankfurter';
import { fetchJson } from '../src/marketdata/http';
import type { Candle, MarketDataProvider, ProviderHealth, Quote } from '../src/types';

function fakeProvider(overrides: Partial<MarketDataProvider> & { name: string; priority: number }): MarketDataProvider {
  const base: MarketDataProvider = {
    name: overrides.name,
    synthetic: false,
    priority: overrides.priority,
    supportsSymbol: () => true,
    supportsTimeframe: () => true,
    getCandles: async () => [],
    getQuote: async () => ({ symbol: 'X', last: 1, timestamp: Date.now() }),
    healthCheck: async () => ({ name: overrides.name, status: 'UP', synthetic: false, lastCheckAt: Date.now() }),
    capabilities: () => ({ marketClasses: ['forex'], timeframes: ['1h'], delayed: false, notes: 'test' }),
    ...overrides,
  } as MarketDataProvider;
  return base;
}

const oneHour = 3_600_000;
function candlesFor(n: number): Candle[] {
  const now = Date.now();
  return Array.from({ length: n }, (_, i) => ({
    timestamp: now - (n - i) * oneHour,
    open: 100, high: 101, low: 99, close: 100.5, volume: 10,
  }));
}

describe('ProviderManager fallback & provenance', () => {
  it('falls back to the next provider and records the failed chain', async () => {
    const broken = fakeProvider({
      name: 'broken',
      priority: 1,
      getCandles: async () => { throw new Error('boom'); },
    });
    const working = fakeProvider({
      name: 'working',
      priority: 2,
      getCandles: async () => candlesFor(50),
    });
    const onFallback = vi.fn();
    const pm = new ProviderManager({ onFallback });
    pm.registerAll([broken, working]);

    const series = await pm.getCandleSeries('EURUSD', 'forex', '1h', 50);
    expect(series.provenance.source).toBe('working');
    expect(series.provenance.fallbackChain).toEqual(['broken']);
    expect(series.provenance.synthetic).toBe(false);
    expect(onFallback).toHaveBeenCalledOnce();
  });

  it('marks synthetic provenance when only the synthetic provider can serve', async () => {
    const broken = fakeProvider({
      name: 'real-but-down',
      priority: 1,
      getCandles: async () => { throw new Error('network unreachable'); },
    });
    const pm = new ProviderManager();
    pm.registerAll([broken, new SyntheticDemoProvider()]);

    const series = await pm.getCandleSeries('BTCUSDT', 'crypto', '1h', 100);
    expect(series.provenance.source).toBe('synthetic-demo');
    expect(series.provenance.synthetic).toBe(true);
    expect(series.provenance.live).toBe(false);
    expect(series.provenance.fallbackChain).toContain('real-but-down');
  });

  it('throws when no provider at all can serve', async () => {
    const broken = fakeProvider({ name: 'only', priority: 1, getCandles: async () => { throw new Error('down'); } });
    const pm = new ProviderManager();
    pm.register(broken);
    await expect(pm.getCandleSeries('EURUSD', 'forex', '1h', 50)).rejects.toThrow(/No provider/);
  });

  it('caches candle responses within the TTL', async () => {
    let calls = 0;
    const counting = fakeProvider({
      name: 'counting',
      priority: 1,
      getCandles: async () => { calls++; return candlesFor(50); },
    });
    const pm = new ProviderManager();
    pm.register(counting);
    await pm.getCandleSeries('EURUSD', 'forex', '1h', 50);
    await pm.getCandleSeries('EURUSD', 'forex', '1h', 50);
    expect(calls).toBe(1);
  });
});

describe('CircuitBreaker', () => {
  it('opens after repeated failures and fails fast', () => {
    const cb = new CircuitBreaker('t', 3, 1000, 5000);
    expect(cb.canCall()).toBe(true);
    cb.recordFailure();
    cb.recordFailure();
    expect(cb.canCall()).toBe(true);
    cb.recordFailure();
    expect(cb.canCall()).toBe(false); // OPEN
    expect(cb.currentState()).toBe('OPEN');
  });

  it('half-opens after cooldown and closes on success', async () => {
    const cb = new CircuitBreaker('t', 2, 1000, 10);
    cb.recordFailure();
    cb.recordFailure();
    expect(cb.canCall()).toBe(false);
    await new Promise((r) => setTimeout(r, 30));
    expect(cb.currentState()).toBe('HALF_OPEN');
    expect(cb.canCall()).toBe(true);
    cb.recordSuccess();
    expect(cb.currentState()).toBe('CLOSED');
  });
});

describe('HTTP layer', () => {
  it('retries and surfaces errors (timeout handling)', async () => {
    const hanging = () => new Promise<never>((_, reject) => setTimeout(() => reject(new Error('late')), 500));
    await expect(
      fetchJson('http://x', { fetchImpl: hanging as never, timeoutMs: 30, retries: 1 }),
    ).rejects.toThrow();
  }, 10000);

  it('backs off on HTTP 429 rate limiting', async () => {
    let calls = 0;
    const impl = async () => {
      calls++;
      if (calls < 3) return { ok: false, status: 429, text: async () => '' };
      return { ok: true, status: 200, text: async () => '{"ok":true}' };
    };
    const out = await fetchJson<{ ok: boolean }>('http://x', {
      fetchImpl: impl as never,
      timeoutMs: 1000,
      retries: 3,
      rateLimitCooldownMs: 10,
    });
    expect(out.ok).toBe(true);
    expect(calls).toBe(3);
  });
});

describe('SyntheticDemoProvider', () => {
  it('is deterministic for the same seed', () => {
    const a = generateSyntheticCandles('BTCUSDT', '1h', 100, 1_700_000_000_000);
    const b = generateSyntheticCandles('BTCUSDT', '1h', 100, 1_700_000_000_000);
    expect(a).toEqual(b);
  });

  it('produces internally consistent OHLC', () => {
    const candles = generateSyntheticCandles('EURUSD', '1h', 200);
    for (const c of candles) {
      expect(c.high).toBeGreaterThanOrEqual(Math.max(c.open, c.close));
      expect(c.low).toBeLessThanOrEqual(Math.min(c.open, c.close));
      expect(c.volume).toBeGreaterThan(0);
    }
  });

  it('identifies itself as synthetic everywhere', async () => {
    const p = new SyntheticDemoProvider();
    expect(p.synthetic).toBe(true);
    const health: ProviderHealth = await p.healthCheck();
    expect(health.synthetic).toBe(true);
    expect(health.detail).toMatch(/SYNTHETIC/i);
    expect(p.capabilities().notes).toMatch(/SYNTHETIC/);
  });
});

describe('Binance provider (unit level)', () => {
  it('maps klines to normalized candles', async () => {
    const fetchImpl = async () => ({
      ok: true,
      status: 200,
      text: async () =>
        JSON.stringify([
          [1700000000000, '60000', '60100', '59900', '60050', '123.4', 1700003600000, 'x', 0, 0, 0, 0],
          [1700003600000, '60050', '60200', '60000', '60150', '100.0', 1700007200000, 'x', 0, 0, 0, 0],
        ]),
    });
    const p = new BinanceProvider('http://test', fetchImpl as never);
    const candles = await p.getCandles({ symbol: 'BTCUSDT', timeframe: '1h', limit: 2 });
    expect(candles).toHaveLength(2);
    expect(candles[0]).toMatchObject({ open: 60000, high: 60100, low: 59900, close: 60050, volume: 123.4 });
  });

  it('refuses unsupported symbols instead of inventing data', async () => {
    const p = new BinanceProvider('http://test', (async () => ({ ok: true, status: 200, text: async () => '[]' })) as never);
    await expect(p.getCandles({ symbol: 'EURUSD', timeframe: '1h', limit: 5 })).rejects.toThrow();
    expect(p.supportsSymbol('EURUSD')).toBe(false);
  });

  it('reports DOWN when the network is unreachable', async () => {
    const p = new BinanceProvider('http://definitely-not-reachable', (async () => {
      throw new Error('connect ECONNREFUSED');
    }) as never);
    const health = await p.healthCheck();
    expect(health.status).toBe('DOWN');
    expect(health.lastError).toBeTruthy();
  });
});

describe('Frankfurter provider (unit level)', () => {
  it('refuses intraday timeframes (ECB publishes daily only)', async () => {
    const p = new FrankfurterProvider('http://test', (async () => ({ ok: true, status: 200, text: async () => '{}' })) as never);
    await expect(p.getCandles({ symbol: 'EURUSD', timeframe: '1h', limit: 5 })).rejects.toThrow(/daily/i);
    expect(p.supportsTimeframe('EURUSD', '1h')).toBe(false);
    expect(p.supportsTimeframe('EURUSD', '1d')).toBe(true);
  });

  it('does not claim to cover metals like XAUUSD', () => {
    const p = new FrankfurterProvider('http://test', (async () => ({ ok: true, status: 200, text: async () => '{}' })) as never);
    expect(p.supportsSymbol('XAUUSD')).toBe(false);
  });

  it('builds daily candles from the ECB time series', async () => {
    const fetchImpl = async () => ({
      ok: true,
      status: 200,
      text: async () =>
        JSON.stringify({ base: 'EUR', rates: { USD: { '2026-08-19': 1.10, '2026-08-20': 1.11, '2026-08-21': 1.09 } } }),
    });
    const p = new FrankfurterProvider('http://test', fetchImpl as never);
    const candles = await p.getCandles({ symbol: 'EURUSD', timeframe: '1d', limit: 10 });
    expect(candles).toHaveLength(3);
    expect(candles[1].open).toBeCloseTo(1.1, 8);
    expect(candles[1].close).toBeCloseTo(1.11, 8);
    expect(candles[1].volume).toBe(0); // reference rates carry no volume — honest
  });
});
