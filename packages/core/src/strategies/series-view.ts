import type { Candle, Timeframe } from '../types';
import {
  adx as adxFn, atr as atrFn, bollinger, ema, lastOf, macd as macdFn, rsi as rsiFn,
  sma, stochastic, vwap as vwapFn,
} from '../indicators/indicators';

/**
 * Look-ahead error — thrown when a strategy (or anything else) tries to read a
 * bar beyond the current evaluation point. The backtester treats this as a
 * hard failure: look-ahead bias is a bug, not a warning.
 */
export class LookAheadError extends Error {
  constructor(requested: number, current: number) {
    super(`Look-ahead access denied: strategy requested bar ${requested} but current bar is ${current}`);
    this.name = 'LookAheadError';
  }
}

interface Precomputed {
  ema20: (number | null)[];
  ema50: (number | null)[];
  sma50: (number | null)[];
  rsi14: (number | null)[];
  macdHist: (number | null)[];
  adx14: (number | null)[];
  plusDi: (number | null)[];
  minusDi: (number | null)[];
  atr14: (number | null)[];
  bbUpper: (number | null)[];
  bbLower: (number | null)[];
  bbMid: (number | null)[];
  stochK: (number | null)[];
  stochD: (number | null)[];
  vwap: (number | null)[];
}

/**
 * A strictly causal window over a candle series.
 *
 * Indicators are precomputed ONCE over the full series (mathematically
 * identical to computing them on every prefix — EMA/RSI/ADX etc. only depend on
 * past bars), and every accessor enforces `i <= current` so strategies cannot
 * peek at the future even by accident. The backtester advances `current` one
 * bar at a time.
 */
export class SeriesView {
  readonly symbol: string;
  readonly timeframe: Timeframe;
  readonly marketClass: string;
  /** Index of the current (most recently CLOSED) bar. */
  readonly index: number;

  constructor(
    private readonly candles: Candle[],
    private readonly ind: Precomputed,
    current: number,
    meta: { symbol: string; timeframe: Timeframe; marketClass: string },
  ) {
    this.index = current;
    this.symbol = meta.symbol;
    this.timeframe = meta.timeframe;
    this.marketClass = meta.marketClass;
  }

  private check(i: number): void {
    if (i > this.index || i < 0) throw new LookAheadError(i, this.index);
  }

  get barsVisible(): number {
    return this.index + 1;
  }

  open(i: number = this.index): number { this.check(i); return this.candles[i].open; }
  high(i: number = this.index): number { this.check(i); return this.candles[i].high; }
  low(i: number = this.index): number { this.check(i); return this.candles[i].low; }
  close(i: number = this.index): number { this.check(i); return this.candles[i].close; }
  volume(i: number = this.index): number { this.check(i); return this.candles[i].volume; }
  time(i: number = this.index): number { this.check(i); return this.candles[i].timestamp; }

  /** Highest high over the `n` bars STRICTLY BEFORE the given bar (classic breakout range). */
  highestHigh(n: number, before: number = this.index): number {
    this.check(before);
    const from = Math.max(0, before - n);
    let hi = -Infinity;
    for (let i = from; i < before; i++) hi = Math.max(hi, this.candles[i].high);
    return hi;
  }

  lowestLow(n: number, before: number = this.index): number {
    this.check(before);
    const from = Math.max(0, before - n);
    let lo = Infinity;
    for (let i = from; i < before; i++) lo = Math.min(lo, this.candles[i].low);
    return lo;
  }

  averageVolume(n: number, upTo: number = this.index): number {
    this.check(upTo);
    const from = Math.max(0, upTo - n + 1);
    let sum = 0;
    for (let i = from; i <= upTo; i++) sum += this.candles[i].volume;
    return sum / Math.max(1, upTo - from + 1);
  }

  ema20(i: number = this.index): number | null { this.check(i); return this.ind.ema20[i]; }
  ema50(i: number = this.index): number | null { this.check(i); return this.ind.ema50[i]; }
  sma50(i: number = this.index): number | null { this.check(i); return this.ind.sma50[i]; }
  rsi14(i: number = this.index): number | null { this.check(i); return this.ind.rsi14[i]; }
  macdHistogram(i: number = this.index): number | null { this.check(i); return this.ind.macdHist[i]; }
  adx14(i: number = this.index): number | null { this.check(i); return this.ind.adx14[i]; }
  plusDi(i: number = this.index): number | null { this.check(i); return this.ind.plusDi[i]; }
  minusDi(i: number = this.index): number | null { this.check(i); return this.ind.minusDi[i]; }
  atr14(i: number = this.index): number | null { this.check(i); return this.ind.atr14[i]; }
  bbUpper(i: number = this.index): number | null { this.check(i); return this.ind.bbUpper[i]; }
  bbMid(i: number = this.index): number | null { this.check(i); return this.ind.bbMid[i]; }
  bbLower(i: number = this.index): number | null { this.check(i); return this.ind.bbLower[i]; }
  stochK(i: number = this.index): number | null { this.check(i); return this.ind.stochK[i]; }
  stochD(i: number = this.index): number | null { this.check(i); return this.ind.stochD[i]; }
  vwap(i: number = this.index): number | null { this.check(i); return this.ind.vwap[i]; }
}

export function precomputeIndicators(candles: Candle[]): Precomputed {
  const closes = candles.map((c) => c.close);
  const macdRes = macdFn(closes);
  const bb = bollinger(closes, 20, 2);
  const adxRes = adxFn(candles, 14);
  const stoch = stochastic(candles, 14, 3);
  return {
    ema20: ema(closes, 20),
    ema50: ema(closes, 50),
    sma50: sma(closes, 50),
    rsi14: rsiFn(closes, 14),
    macdHist: macdRes.histogram,
    adx14: adxRes.adx,
    plusDi: adxRes.plusDi,
    minusDi: adxRes.minusDi,
    atr14: atrFn(candles, 14),
    bbUpper: bb.upper,
    bbMid: bb.mid,
    bbLower: bb.lower,
    stochK: stoch.k,
    stochD: stoch.d,
    vwap: vwapFn(candles),
  };
}

export { lastOf };
