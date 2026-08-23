import { describe, expect, it } from 'vitest';
import {
  adx, atr, bollinger, ema, findSwings, macd, pivotPoints, rsi, sma,
  stochastic, supportResistance, volumeProfile, vwap,
} from '../src/indicators/indicators';
import type { Candle } from '../src/types';

function candle(ts: number, open: number, high: number, low: number, close: number, volume = 100): Candle {
  return { timestamp: ts, open, high, low, close, volume };
}

describe('moving averages', () => {
  it('sma computes windowed means with leading nulls', () => {
    expect(sma([1, 2, 3, 4, 5], 3)).toEqual([null, null, 2, 3, 4]);
  });

  it('ema seeds with SMA then applies the multiplier', () => {
    // seed = SMA(1,2,3) = 2; k = 0.5; then 4*0.5+2*0.5=3, 5*0.5+3*0.5=4
    expect(ema([1, 2, 3, 4, 5], 3)).toEqual([null, null, 2, 3, 4]);
  });
});

describe('rsi (Wilder)', () => {
  it('returns 100 for a purely rising series after warmup', () => {
    const closes = Array.from({ length: 30 }, (_, i) => 100 + i);
    const out = rsi(closes, 14);
    for (let i = 15; i < out.length; i++) expect(out[i]).toBeCloseTo(100, 5);
  });

  it('returns 0 for a purely falling series after warmup', () => {
    const closes = Array.from({ length: 30 }, (_, i) => 100 - i);
    const out = rsi(closes, 14);
    for (let i = 15; i < out.length; i++) expect(out[i]).toBeCloseTo(0, 5);
  });

  it('oscillates tightly around 50 for an alternating series (equal up/down moves)', () => {
    // closes alternate 100, 101, 100, 101... — Wilder smoothing leaves a small
    // ±3.6 bounded oscillation around 50 depending on bar parity.
    const closes = Array.from({ length: 31 }, (_, i) => (i % 2 === 0 ? 100 : 101));
    const out = rsi(closes, 14);
    for (let i = 15; i < out.length; i++) {
      expect(Math.abs((out[i] as number) - 50)).toBeLessThan(5);
    }
  });
});

describe('macd', () => {
  it('histogram = macd - signal and both rise in an accelerating uptrend', () => {
    // Exponential growth keeps the MACD line itself rising (a linear ramp
    // converges to a constant MACD where macd == signal).
    const closes = Array.from({ length: 80 }, (_, i) => 100 * Math.pow(1.01, i));
    const { macd: m, signal: s, histogram: h } = macd(closes);
    const last = closes.length - 1;
    expect(h[last]).toBeCloseTo((m[last] as number) - (s[last] as number), 6);
    expect(m[last]).toBeGreaterThan(s[last] as number);
  });
});

describe('bollinger', () => {
  it('matches hand-computed SMA +- 2 sigma', () => {
    const closes = Array.from({ length: 20 }, (_, i) => i + 1); // 1..20
    const { upper, mid, lower } = bollinger(closes, 20, 2);
    const mean = 10.5;
    const sd = Math.sqrt(33.25); // variance of uniform 1..20 = (n^2-1)/12
    expect(mid[19]).toBeCloseTo(mean, 8);
    expect(upper[19]).toBeCloseTo(mean + 2 * sd, 6);
    expect(lower[19]).toBeCloseTo(mean - 2 * sd, 6);
  });

  it('collapses to zero width on a constant series', () => {
    const closes = new Array(25).fill(50);
    const { upper, mid, lower } = bollinger(closes, 20, 2);
    expect(upper[24]).toBe(50);
    expect(lower[24]).toBe(50);
    expect(mid[24]).toBe(50);
  });
});

describe('atr / adx', () => {
  it('atr equals the constant range of 2 with no gaps', () => {
    const candles = Array.from({ length: 30 }, (_, i) => candle(i, 101, 102, 100, 101));
    const a = atr(candles, 14);
    expect(a[29]).toBeCloseTo(2, 6);
  });

  it('adx stays in bounds and +DI dominates in an uptrend', () => {
    const candles: Candle[] = [];
    for (let i = 0; i < 80; i++) {
      const open = 100 + i * 0.7;
      const close = open + 0.6;
      candles.push(candle(i, open, close + 0.2, open - 0.2, close));
    }
    const { adx: adxArr, plusDi, minusDi } = adx(candles, 14);
    const last = candles.length - 1;
    expect(adxArr[last]).not.toBeNull();
    expect(adxArr[last] as number).toBeGreaterThan(0);
    // A noiseless monotonic trend legitimately saturates ADX at 100.
    expect(adxArr[last] as number).toBeLessThanOrEqual(100);
    expect(plusDi[last] as number).toBeGreaterThan(minusDi[last] as number);
  });
});

describe('stochastic', () => {
  it('returns 100 when close sits at the window high', () => {
    const candles = Array.from({ length: 20 }, (_, i) =>
      candle(i, 100 + i, 100 + i + 1, 100 + i - 1, 100 + i + 0.9),
    );
    const { k, d } = stochastic(candles, 14, 3);
    expect(k[19]).toBeGreaterThan(95);
    expect(d[19]).not.toBeNull();
  });
});

describe('vwap', () => {
  it('computes cumulative typical-price volume average', () => {
    const c1 = candle(0, 10, 10, 9, 9.5, 100); // tp 9.5
    const c2 = candle(1, 10.5, 11, 10, 10.5, 100); // tp 10.5
    const out = vwap([c1, c2]);
    expect(out[0]).toBeCloseTo(9.5, 6);
    expect(out[1]).toBeCloseTo(10.0, 6);
  });

  it('returns null for zero-volume candles (no fake volume)', () => {
    const c = candle(0, 1, 2, 0.5, 1.5, 0);
    expect(vwap([c])[0]).toBeNull();
  });
});

describe('volume profile', () => {
  it('places POC at the highest-volume price bucket', () => {
    // All volume concentrated in two buckets; the 10.0 area should win.
    const candles = [
      candle(0, 9.0, 9.2, 8.9, 9.1, 10),
      candle(1, 9.4, 9.6, 9.3, 9.5, 10),
      candle(2, 9.9, 10.1, 9.8, 10.0, 1000), // tp = 9.966.. in the 10 bucket
      candle(3, 10.2, 10.4, 10.1, 10.3, 10),
    ];
    const vp = volumeProfile(candles, 10);
    expect(vp.poc).not.toBeNull();
    expect(vp.poc as number).toBeGreaterThan(9.5);
    expect(vp.poc as number).toBeLessThan(10.5);
    expect(vp.valueAreaLow).toBeLessThanOrEqual(vp.poc as number);
    expect(vp.valueAreaHigh).toBeGreaterThanOrEqual(vp.poc as number);
  });
});

describe('swings and support/resistance', () => {
  it('finds fractal swing highs and lows', () => {
    // Zigzag: peak at index 4 (high 12), trough at index 9 (low 8)
    const highs = [10, 11, 11.5, 11.8, 12, 11.5, 11, 10.5, 10, 9.5, 9, 8, 8.5, 9, 9.5, 10];
    const candles = highs.map((h, i) => candle(i, h - 0.5, h, h - 1.5, h - 0.6));
    const swings = findSwings(candles, 2);
    const swingHigh = swings.find((s) => s.type === 'high');
    expect(swingHigh?.index).toBe(4);
    expect(swingHigh?.price).toBe(12);
  });

  it('splits clustered levels into support (below) and resistance (above)', () => {
    // Smooth sine between ~98 and ~102. Wicks vary per bar so fractal swings
    // actually form (identical highs on adjacent candles never qualify).
    const candles: Candle[] = [];
    for (let i = 0; i < 60; i++) {
      const close = 100 + 2 * Math.sin(i / 4);
      const prev = i > 0 ? 100 + 2 * Math.sin((i - 1) / 4) : close;
      const wickUp = 0.05 + 0.04 * Math.sin(i * 1.7);
      const wickDown = 0.05 + 0.04 * Math.cos(i * 1.7);
      candles.push({
        timestamp: i * 3_600_000,
        open: prev,
        high: Math.max(prev, close) + wickUp,
        low: Math.min(prev, close) - wickDown,
        close,
        volume: 50,
      });
    }
    const { support, resistance } = supportResistance(candles, null, 100);
    expect(support.every((s) => s < 100)).toBe(true);
    expect(resistance.every((r) => r > 100)).toBe(true);
    expect(support.length).toBeGreaterThan(0);
    expect(resistance.length).toBeGreaterThan(0);
  });
});

describe('pivot points', () => {
  it('computes classic floor-trader pivots', () => {
    const p = pivotPoints(candle(0, 9, 10, 8, 9));
    expect(p.p).toBeCloseTo(9, 8);
    expect(p.r1).toBeCloseTo(10, 8);
    expect(p.s1).toBeCloseTo(8, 8);
    expect(p.r2).toBeCloseTo(11, 8);
    expect(p.s2).toBeCloseTo(7, 8);
    expect(p.r3).toBeCloseTo(12, 8);
    expect(p.s3).toBeCloseTo(6, 8);
  });

  it('returns nulls without a previous candle', () => {
    const p = pivotPoints(undefined);
    expect(p.p).toBeNull();
  });
});
