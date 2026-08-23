import type { Candle } from '../types';
import { mean, stdev } from '../utils/math';

/**
 * Technical-analysis engine.
 *
 * Every function is pure and returns an array aligned 1:1 with the input
 * (leading values that cannot be computed are `null`) plus `last*` helpers.
 * Unit-tested against hand-computed fixtures in test/indicators.test.ts.
 */

// ---------------------------------------------------------------------------
// Moving averages
// ---------------------------------------------------------------------------

export function sma(values: number[], period: number): (number | null)[] {
  const out: (number | null)[] = [];
  let sum = 0;
  for (let i = 0; i < values.length; i++) {
    sum += values[i];
    if (i >= period) sum -= values[i - period];
    out.push(i >= period - 1 ? sum / period : null);
  }
  return out;
}

export function ema(values: number[], period: number): (number | null)[] {
  if (period <= 0) return values.map(() => null);
  const k = 2 / (period + 1);
  const out: (number | null)[] = [];
  let prev: number | null = null;
  let seedSum = 0;
  for (let i = 0; i < values.length; i++) {
    if (i < period - 1) {
      seedSum += values[i];
      out.push(null);
      continue;
    }
    if (prev === null) {
      seedSum += values[i];
      prev = seedSum / period; // seed with SMA of first `period` values
    } else {
      prev = values[i] * k + prev * (1 - k);
    }
    out.push(prev);
  }
  return out;
}

/** Wilder's smoothing (used by RSI/ATR/ADX). */
export function wilder(values: number[], period: number): (number | null)[] {
  const out: (number | null)[] = [];
  let prev: number | null = null;
  let seedSum = 0;
  for (let i = 0; i < values.length; i++) {
    if (i < period - 1) {
      seedSum += values[i];
      out.push(null);
      continue;
    }
    if (prev === null) {
      seedSum += values[i];
      prev = seedSum / period;
    } else {
      prev = (prev * (period - 1) + values[i]) / period;
    }
    out.push(prev);
  }
  return out;
}

// ---------------------------------------------------------------------------
// Oscillators
// ---------------------------------------------------------------------------

export function rsi(closes: number[], period = 14): (number | null)[] {
  const gains: number[] = [0];
  const losses: number[] = [0];
  for (let i = 1; i < closes.length; i++) {
    const d = closes[i] - closes[i - 1];
    gains.push(Math.max(0, d));
    losses.push(Math.max(0, -d));
  }
  const avgGain = wilder(gains.slice(1), period);
  const avgLoss = wilder(losses.slice(1), period);
  // wilder() consumed arrays offset by one bar; realign to closes length.
  const out: (number | null)[] = [null];
  for (let i = 1; i < closes.length; i++) {
    const g = avgGain[i - 1];
    const l = avgLoss[i - 1];
    if (g === null || l === null) {
      out.push(null);
      continue;
    }
    if (l === 0) {
      out.push(g === 0 ? 50 : 100);
      continue;
    }
    const rs = g / l;
    out.push(100 - 100 / (1 + rs));
  }
  return out;
}

export function macd(
  closes: number[],
  fast = 12,
  slow = 26,
  signalPeriod = 9,
): { macd: (number | null)[]; signal: (number | null)[]; histogram: (number | null)[] } {
  const emaFast = ema(closes, fast);
  const emaSlow = ema(closes, slow);
  const macdLine = closes.map((_, i) =>
    emaFast[i] !== null && emaSlow[i] !== null ? (emaFast[i] as number) - (emaSlow[i] as number) : null,
  );
  const defined = macdLine.map((v) => (v === null ? 0 : v));
  const firstIdx = macdLine.findIndex((v) => v !== null);
  const signalRaw = ema(defined.slice(firstIdx >= 0 ? firstIdx : 0), signalPeriod);
  const signal: (number | null)[] = closes.map(() => null);
  for (let i = 0; i < signalRaw.length; i++) signal[(firstIdx >= 0 ? firstIdx : 0) + i] = signalRaw[i];
  const histogram = macdLine.map((v, i) =>
    v !== null && signal[i] !== null ? v - (signal[i] as number) : null,
  );
  return { macd: macdLine, signal, histogram };
}

export function bollinger(
  closes: number[],
  period = 20,
  mult = 2,
): { upper: (number | null)[]; mid: (number | null)[]; lower: (number | null)[] } {
  const mid = sma(closes, period);
  const upper: (number | null)[] = [];
  const lower: (number | null)[] = [];
  for (let i = 0; i < closes.length; i++) {
    if (mid[i] === null) {
      upper.push(null);
      lower.push(null);
      continue;
    }
    const sd = stdev(closes.slice(i - period + 1, i + 1));
    if (sd === null) {
      upper.push(null);
      lower.push(null);
      continue;
    }
    upper.push((mid[i] as number) + mult * sd);
    lower.push((mid[i] as number) - mult * sd);
  }
  return { upper, mid, lower };
}

export function stochastic(
  candles: Candle[],
  kPeriod = 14,
  dPeriod = 3,
): { k: (number | null)[]; d: (number | null)[] } {
  const k: (number | null)[] = [];
  for (let i = 0; i < candles.length; i++) {
    if (i < kPeriod - 1) {
      k.push(null);
      continue;
    }
    const window = candles.slice(i - kPeriod + 1, i + 1);
    const hh = Math.max(...window.map((c) => c.high));
    const ll = Math.min(...window.map((c) => c.low));
    k.push(hh === ll ? 50 : (100 * (candles[i].close - ll)) / (hh - ll));
  }
  const kDefined = k.map((v) => (v === null ? 0 : v));
  const dRaw = sma(kDefined, dPeriod);
  const d: (number | null)[] = k.map((v, i) => (v === null ? null : dRaw[i]));
  return { k, d };
}

// ---------------------------------------------------------------------------
// Volatility & trend strength
// ---------------------------------------------------------------------------

export function trueRange(candles: Candle[]): number[] {
  return candles.map((c, i) => {
    if (i === 0) return c.high - c.low;
    const pc = candles[i - 1].close;
    return Math.max(c.high - c.low, Math.abs(c.high - pc), Math.abs(c.low - pc));
  });
}

export function atr(candles: Candle[], period = 14): (number | null)[] {
  return wilder(trueRange(candles), period);
}

export function adx(
  candles: Candle[],
  period = 14,
): { adx: (number | null)[]; plusDi: (number | null)[]; minusDi: (number | null)[] } {
  const plusDM: number[] = [];
  const minusDM: number[] = [];
  for (let i = 0; i < candles.length; i++) {
    if (i === 0) {
      plusDM.push(0);
      minusDM.push(0);
      continue;
    }
    const up = candles[i].high - candles[i - 1].high;
    const down = candles[i - 1].low - candles[i].low;
    plusDM.push(up > down && up > 0 ? up : 0);
    minusDM.push(down > up && down > 0 ? down : 0);
  }
  const tr = wilder(trueRange(candles), period);
  const smPlus = wilder(plusDM, period);
  const smMinus = wilder(minusDM, period);

  const plusDi: (number | null)[] = [];
  const minusDi: (number | null)[] = [];
  const dx: (number | null)[] = [];
  for (let i = 0; i < candles.length; i++) {
    if (tr[i] === null || smPlus[i] === null || smMinus[i] === null || (tr[i] as number) === 0) {
      plusDi.push(null);
      minusDi.push(null);
      dx.push(null);
      continue;
    }
    const p = (100 * (smPlus[i] as number)) / (tr[i] as number);
    const m = (100 * (smMinus[i] as number)) / (tr[i] as number);
    plusDi.push(p);
    minusDi.push(m);
    dx.push(p + m === 0 ? 0 : (100 * Math.abs(p - m)) / (p + m));
  }
  const dxDefined = dx.map((v) => (v === null ? 0 : v));
  const firstIdx = dx.findIndex((v) => v !== null);
  const adxRaw = wilder(dxDefined.slice(firstIdx >= 0 ? firstIdx : 0), period);
  const adxOut: (number | null)[] = candles.map(() => null);
  for (let i = 0; i < adxRaw.length; i++) adxOut[(firstIdx >= 0 ? firstIdx : 0) + i] = adxRaw[i];
  return { adx: adxOut, plusDi, minusDi };
}

// ---------------------------------------------------------------------------
// Volume
// ---------------------------------------------------------------------------

export function vwap(candles: Candle[]): (number | null)[] {
  let cumPV = 0;
  let cumV = 0;
  return candles.map((c) => {
    if (c.volume <= 0) return null; // providers without volume report honestly
    const tp = (c.high + c.low + c.close) / 3;
    cumPV += tp * c.volume;
    cumV += c.volume;
    return cumV === 0 ? null : cumPV / cumV;
  });
}

export function volumeProfile(
  candles: Candle[],
  bins = 24,
): { poc: number | null; valueAreaHigh: number | null; valueAreaLow: number | null; binEdges: number[]; binVolumes: number[] } {
  const priced = candles.filter((c) => c.volume > 0);
  if (priced.length === 0) return { poc: null, valueAreaHigh: null, valueAreaLow: null, binEdges: [], binVolumes: [] };
  const lo = Math.min(...priced.map((c) => c.low));
  const hi = Math.max(...priced.map((c) => c.high));
  if (!(hi > lo)) return { poc: lo, valueAreaHigh: hi, valueAreaLow: lo, binEdges: [lo, hi], binVolumes: [priced.reduce((a, c) => a + c.volume, 0)] };

  const width = (hi - lo) / bins;
  const binVolumes = new Array<number>(bins).fill(0);
  for (const c of priced) {
    const tp = (c.high + c.low + c.close) / 3;
    const idx = Math.min(bins - 1, Math.max(0, Math.floor((tp - lo) / width)));
    binVolumes[idx] += c.volume;
  }
  const totalVolume = binVolumes.reduce((a, b) => a + b, 0);
  let pocIdx = 0;
  for (let i = 1; i < bins; i++) if (binVolumes[i] > binVolumes[pocIdx]) pocIdx = i;

  // 70% value area expanding around POC
  let lowIdx = pocIdx;
  let highIdx = pocIdx;
  let acc = binVolumes[pocIdx];
  while (acc < totalVolume * 0.7 && (lowIdx > 0 || highIdx < bins - 1)) {
    const below = lowIdx > 0 ? binVolumes[lowIdx - 1] : -1;
    const above = highIdx < bins - 1 ? binVolumes[highIdx + 1] : -1;
    if (above >= below) {
      highIdx++;
      acc += Math.max(above, 0);
    } else {
      lowIdx--;
      acc += Math.max(below, 0);
    }
  }
  const binEdges = Array.from({ length: bins + 1 }, (_, i) => lo + i * width);
  return {
    poc: lo + (pocIdx + 0.5) * width,
    valueAreaHigh: lo + (highIdx + 1) * width,
    valueAreaLow: lo + lowIdx * width,
    binEdges,
    binVolumes,
  };
}

// ---------------------------------------------------------------------------
// Structure: swings, support/resistance, pivots
// ---------------------------------------------------------------------------

export interface Swing {
  index: number;
  timestamp: number;
  price: number;
  type: 'high' | 'low';
}

/** Fractal swings: a high/low that dominates `k` bars on both sides. */
export function findSwings(candles: Candle[], k = 2): Swing[] {
  const swings: Swing[] = [];
  for (let i = k; i < candles.length - k; i++) {
    let isHigh = true;
    let isLow = true;
    for (let j = i - k; j <= i + k; j++) {
      if (j === i) continue;
      if (candles[j].high >= candles[i].high) isHigh = false;
      if (candles[j].low <= candles[i].low) isLow = false;
    }
    if (isHigh) swings.push({ index: i, timestamp: candles[i].timestamp, price: candles[i].high, type: 'high' });
    if (isLow) swings.push({ index: i, timestamp: candles[i].timestamp, price: candles[i].low, type: 'low' });
  }
  return swings;
}

/** Cluster swing levels into zones; return the most-touched levels sorted by price. */
export function supportResistance(
  candles: Candle[],
  atrValue: number | null,
  currentPrice: number,
  opts: { clusterToleranceAtr?: number; maxLevels?: number } = {},
): { support: number[]; resistance: number[] } {
  const { clusterToleranceAtr = 0.5, maxLevels = 4 } = opts;
  const swings = findSwings(candles, 2);
  if (swings.length === 0) return { support: [], resistance: [] };
  const tolerance =
    atrValue !== null && Number.isFinite(atrValue) && atrValue > 0
      ? atrValue * clusterToleranceAtr
      : currentPrice * 0.002;

  const levels: { price: number; touches: number }[] = [];
  for (const s of swings) {
    const existing = levels.find((l) => Math.abs(l.price - s.price) <= tolerance);
    if (existing) {
      existing.price = (existing.price * existing.touches + s.price) / (existing.touches + 1);
      existing.touches++;
    } else {
      levels.push({ price: s.price, touches: 1 });
    }
  }
  levels.sort((a, b) => b.touches - a.touches);
  const chosen = levels.slice(0, maxLevels * 2);
  chosen.sort((a, b) => a.price - b.price);
  return {
    support: chosen.filter((l) => l.price < currentPrice).map((l) => l.price).slice(-maxLevels),
    resistance: chosen.filter((l) => l.price > currentPrice).map((l) => l.price).slice(0, maxLevels),
  };
}

/** Classic floor-trader pivots from the previous completed candle. */
export function pivotPoints(prev: Candle | undefined): {
  p: number | null; r1: number | null; r2: number | null; r3: number | null;
  s1: number | null; s2: number | null; s3: number | null;
} {
  if (!prev) return { p: null, r1: null, r2: null, r3: null, s1: null, s2: null, s3: null };
  const { high: h, low: l, close: c } = prev;
  const p = (h + l + c) / 3;
  return {
    p,
    r1: 2 * p - l,
    s1: 2 * p - h,
    r2: p + (h - l),
    s2: p - (h - l),
    r3: h + 2 * (p - l),
    s3: l - 2 * (h - p),
  };
}

/** Normalized slope of linear regression over the last `period` closes (per-bar % of price). */
export function regressionSlopePct(closes: number[], period = 50): number | null {
  if (closes.length < period) return null;
  const ys = closes.slice(-period);
  const n = ys.length;
  let sumX = 0, sumY = 0, sumXY = 0, sumXX = 0;
  for (let i = 0; i < n; i++) {
    sumX += i;
    sumY += ys[i];
    sumXY += i * ys[i];
    sumXX += i * i;
  }
  const denom = n * sumXX - sumX * sumX;
  if (denom === 0) return null;
  const slope = (n * sumXY - sumX * sumY) / denom;
  const mid = mean(ys) ?? 1;
  return (slope / mid) * 100;
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

export function lastOf(arr: (number | null)[]): number | null {
  for (let i = arr.length - 1; i >= 0; i--) {
    if (arr[i] !== null && Number.isFinite(arr[i] as number)) return arr[i] as number;
  }
  return null;
}
