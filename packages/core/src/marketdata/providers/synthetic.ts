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
import { TIMEFRAMES, TIMEFRAME_MS } from '../../types';
import { gaussian, hashString, seededRandom } from '../../utils/math';
import { alignToTimeframe } from '../http';

/**
 * SYNTHETIC DEMO PROVIDER — generates deterministic, reproducible market data.
 *
 * Purpose: offline development, demos, and automated tests when no real
 * market-data provider is reachable. EVERY candle it returns is flagged via
 * `provider.synthetic === true`, and the provider manager stamps
 * `provenance.synthetic = true` so the API and dashboard can display the
 * mandatory "SIMULATION / SYNTHETIC DATA" banner (Rule 2).
 *
 * This provider is always registered LAST (lowest priority); it only serves
 * data when every real provider has failed or is unreachable.
 */
const BASE_PRICES: Record<string, number> = {
  EURUSD: 1.085,
  GBPUSD: 1.27,
  USDJPY: 151.5,
  AUDUSD: 0.66,
  USDCAD: 1.36,
  USDCHF: 0.895,
  NZDUSD: 0.61,
  XAUUSD: 2320,
  BTCUSDT: 64000,
  ETHUSDT: 3300,
  SOLUSDT: 148,
  BNBUSDT: 590,
  XRPUSDT: 0.62,
};

function basePrice(symbol: string): number {
  if (BASE_PRICES[symbol]) return BASE_PRICES[symbol];
  return 10 + (hashString(symbol) % 5000) / 10;
}

/** Deterministic per-symbol candle generator with alternating market regimes. */
export function generateSyntheticCandles(symbol: string, timeframe: Timeframe, limit: number, now = Date.now()): Candle[] {
  const rand = seededRandom(hashString(`aegis:${symbol}:${timeframe}`));
  const interval = TIMEFRAME_MS[timeframe];
  const lastOpen = alignToTimeframe(now, timeframe) - interval;
  const candles: Candle[] = [];

  let price = basePrice(symbol);
  // Volatility scaled to the instrument class.
  const volScale = symbol.includes('USD') && !symbol.startsWith('BTC') && !symbol.startsWith('ETH') ? 0.0012 : 0.004;

  for (let i = 0; i < limit; i++) {
    const ts = lastOpen - (limit - 1 - i) * interval;

    // Alternating regime blocks of 40 bars: trend, range, chop, trend…
    const phase = Math.floor(i / 40) % 4;
    let drift = 0;
    let vol = volScale;
    if (phase === 0) drift = 0.0018; // steady uptrend
    else if (phase === 1) { drift = 0; vol = volScale * 0.7; } // quiet range
    else if (phase === 2) { drift = -0.0015; vol = volScale * 1.4; } // sell-off
    else { drift = 0.0004; vol = volScale * 1.1; } // mild drift

    const ret = drift + gaussian(rand) * vol;
    const open = price;
    const close = open * (1 + ret);
    const wick = Math.abs(gaussian(rand)) * vol * open * 0.8;
    const high = Math.max(open, close) + wick * rand();
    const low = Math.min(open, close) - wick * rand();
    const baseVolume = symbol.startsWith('BTC') || symbol.startsWith('ETH') ? 800 : 1_000_000;
    const volume = Math.round(baseVolume * (0.5 + rand() * 1.5) * (1 + Math.abs(ret) * 40));

    candles.push({
      timestamp: ts,
      open: round6(open),
      high: round6(high),
      low: round6(low),
      close: round6(close),
      volume: Math.max(volume, 1),
    });
    price = close;
  }
  return candles;
}

function round6(v: number): number {
  return Math.round(v * 1e6) / 1e6;
}

export class SyntheticDemoProvider implements MarketDataProvider {
  readonly name = 'synthetic-demo';
  readonly synthetic = true;
  readonly priority = 999;

  supportsSymbol(): boolean {
    return true; // generates data for any symbol — that is exactly why it must be labeled.
  }

  supportsTimeframe(): boolean {
    return true;
  }

  async getCandles(req: CandleRequest): Promise<Candle[]> {
    return generateSyntheticCandles(req.symbol, req.timeframe, req.limit);
  }

  async getQuote(symbol: string): Promise<Quote> {
    const candles = generateSyntheticCandles(symbol, '1m', 2);
    const last = candles[candles.length - 1];
    const spread = last.close * 0.0002;
    return {
      symbol,
      bid: round6(last.close - spread / 2),
      ask: round6(last.close + spread / 2),
      last: last.close,
      timestamp: last.timestamp,
    };
  }

  async healthCheck(): Promise<ProviderHealth> {
    return {
      name: this.name,
      status: 'UP',
      synthetic: true,
      latencyMs: 0,
      lastCheckAt: Date.now(),
      detail: 'Deterministic synthetic generator (SIMULATION ONLY — not market data)',
      circuitState: 'CLOSED',
    };
  }

  capabilities(): ProviderCapabilities {
    return {
      marketClasses: ['forex', 'crypto', 'stock', 'etf', 'commodity', 'futures', 'indices', 'bonds', 'options'] as MarketClass[],
      timeframes: [...TIMEFRAMES],
      delayed: false,
      notes: 'SYNTHETIC DATA — deterministic simulation for offline development/testing. Never represents real markets.',
    };
  }
}
