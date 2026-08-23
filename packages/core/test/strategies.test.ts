import { describe, expect, it } from 'vitest';
import {
  BreakoutStrategy, MomentumStrategy, MeanReversionStrategy, TrendFollowingStrategy,
} from '../src/strategies/builtins';
import { precomputeIndicators, SeriesView } from '../src/strategies/series-view';
import type { Candle } from '../src/types';

const NOW = 1_755_000_000_000;
const H = 3_600_000;

/** Deterministic LCG so fixtures are reproducible. */
function lcg(startSeed: number): () => number {
  let s = startSeed;
  return () => {
    s = (s * 1103515245 + 12345) % 2147483648;
    return s / 2147483648;
  };
}

function stamp(cs: Candle[]): Candle[] {
  return cs.map((c, i) => ({ ...c, timestamp: NOW - (cs.length - i) * H }));
}

function trending(n: number, drift: number, seed: number, noise = 0.4): Candle[] {
  const rand = lcg(seed);
  const out: Candle[] = [];
  let price = 100;
  for (let i = 0; i < n; i++) {
    const open = price;
    const close = open + drift + (rand() - 0.5) * noise;
    out.push({
      timestamp: 0,
      open,
      high: Math.max(open, close) + rand() * 0.2,
      low: Math.min(open, close) - rand() * 0.2,
      close,
      volume: 100 + rand() * 50,
    });
    price = close;
  }
  return out;
}

function noiseRange(n: number, seed = 7): Candle[] {
  const rand = lcg(seed);
  const out: Candle[] = [];
  let price = 100;
  for (let i = 0; i < n; i++) {
    const open = price;
    const close = open + (rand() - 0.5) * 0.8;
    out.push({
      timestamp: 0,
      open,
      high: Math.max(open, close) + rand() * 0.1,
      low: Math.min(open, close) - rand() * 0.1,
      close,
      volume: 100,
    });
    price = close;
  }
  return out;
}

function ctx(candles: Candle[], position = null) {
  const ind = precomputeIndicators(candles);
  const view = new SeriesView(candles, ind, candles.length - 1, { symbol: 'TEST', timeframe: '1h', marketClass: 'crypto' });
  return { view, position, equity: 10_000 };
}

describe('TrendFollowingStrategy', () => {
  it('emits BUY on the EMA20/50 cross-up in a fresh uptrend, with stop below and target above', () => {
    const combined = stamp([...trending(100, -0.2, 99).slice(0, 60), ...trending(140, 0.45, 42)]);
    const strat = new TrendFollowingStrategy();
    const ind = precomputeIndicators(combined);
    let buys = 0;
    for (let i = 55; i < combined.length; i++) {
      const view = new SeriesView(combined, ind, i, { symbol: 'TEST', timeframe: '1h', marketClass: 'crypto' });
      const sig = strat.evaluate({ view, position: null, equity: 10_000 });
      if (sig.action === 'BUY') {
        buys++;
        expect(sig.stopLoss).toBeDefined();
        expect(sig.stopLoss as number).toBeLessThan(view.close());
        expect(sig.takeProfit as number).toBeGreaterThan(view.close());
        expect(sig.reason).toContain('EMA20 crossed above EMA50');
        expect(sig.confidence).toBeGreaterThan(0);
      }
    }
    expect(buys).toBeGreaterThanOrEqual(1);
  });

  it('holds through a directionless noise range', () => {
    const strat = new TrendFollowingStrategy();
    expect(strat.evaluate(ctx(stamp(noiseRange(300)))).action).toBe('HOLD');
  });
});

describe('MeanReversionStrategy', () => {
  it('emits BUY when price pierces the lower band with oversold RSI in a low-ADX range', () => {
    const candles = stamp(noiseRange(200));
    const last = candles[candles.length - 1];
    candles.push({
      timestamp: last.timestamp + H,
      open: last.close, high: last.close,
      low: last.close - 3.2, close: last.close - 3.0, // piercing spike
      volume: 300,
    });
    const sig = new MeanReversionStrategy().evaluate(ctx(candles));
    expect(sig.action).toBe('BUY');
    expect(sig.reason).toMatch(/lower band/);
    expect(sig.stopLoss).toBeLessThan(candles[candles.length - 1].close);
    expect(sig.takeProfit).toBeGreaterThan(candles[candles.length - 1].close);
  });

  it('mirrors with SELL at the upper band with overbought RSI', () => {
    const candles = stamp(noiseRange(200));
    const last = candles[candles.length - 1];
    candles.push({
      timestamp: last.timestamp + H,
      open: last.close, high: last.close + 3.2,
      low: last.close, close: last.close + 3.0,
      volume: 300,
    });
    const sig = new MeanReversionStrategy().evaluate(ctx(candles));
    expect(sig.action).toBe('SELL');
    expect(sig.reason).toMatch(/upper band/);
  });

  it('refuses to fade when a strong trend is running (ADX filter)', () => {
    const candles = stamp([...trending(100, 0.5, 5)]);
    const last = candles[candles.length - 1];
    // Even a sharp counter-trend dip must not trigger while ADX is high.
    candles.push({
      timestamp: last.timestamp + H,
      open: last.close, high: last.close,
      low: last.close - 8, close: last.close - 7.8,
      volume: 300,
    });
    const sig = new MeanReversionStrategy().evaluate(ctx(candles));
    expect(sig.action).toBe('HOLD');
  });

  it('closes a long when price reverts to the mid band', () => {
    const candles = stamp(noiseRange(200));
    const v = ctx(candles).view;
    const mid = v.bbMid();
    if (mid === null) throw new Error('fixture warmup');
    if (v.close() < mid) {
      // price is below mid — no close yet
      const sig = new MeanReversionStrategy().evaluate({
        ...ctx(candles),
        position: { direction: 'LONG', entryPrice: v.close() - 1, entryBar: 100, stopLoss: v.close() - 3, takeProfit: mid, unrealizedPnl: 1 },
      });
      expect(sig.action).not.toBe('CLOSE');
    } else {
      const sig = new MeanReversionStrategy().evaluate({
        ...ctx(candles),
        position: { direction: 'LONG', entryPrice: v.close() - 2, entryBar: 100, stopLoss: v.close() - 4, takeProfit: mid, unrealizedPnl: 2 },
      });
      expect(sig.action).toBe('CLOSE');
      expect(sig.reason).toMatch(/mid band/);
    }
  });
});

describe('BreakoutStrategy', () => {
  it('emits BUY when close breaks the 48-bar high with volume expansion', () => {
    const candles = stamp(noiseRange(120));
    const pre = precomputeIndicators(candles);
    const v0 = new SeriesView(candles, pre, candles.length - 1, { symbol: 'TEST', timeframe: '1h', marketClass: 'crypto' });
    const rangeHigh = v0.highestHigh(48, candles.length - 1);
    const last = candles[candles.length - 1];
    candles.push({
      timestamp: last.timestamp + H,
      open: last.close,
      high: rangeHigh + 1.5,
      low: last.close + 0.1,
      close: rangeHigh + 1.2, // decisively above the range
      volume: 600, // ~6x average
    });
    const sig = new BreakoutStrategy().evaluate(ctx(candles));
    expect(sig.action).toBe('BUY');
    expect(sig.reason).toMatch(/48-bar high/);
    expect(sig.stopLoss).toBeLessThan(candles[candles.length - 1].close);
    expect(sig.takeProfit).toBeGreaterThan(candles[candles.length - 1].close);
  });

  it('ignores a break without volume confirmation', () => {
    const candles = stamp(noiseRange(120));
    const pre = precomputeIndicators(candles);
    const v0 = new SeriesView(candles, pre, candles.length - 1, { symbol: 'TEST', timeframe: '1h', marketClass: 'crypto' });
    const rangeHigh = v0.highestHigh(48, candles.length - 1);
    const last = candles[candles.length - 1];
    candles.push({
      timestamp: last.timestamp + H,
      open: last.close,
      high: rangeHigh + 1.5,
      low: last.close + 0.1,
      close: rangeHigh + 1.2,
      volume: 10, // far below the 1.5x average threshold
    });
    expect(new BreakoutStrategy().evaluate(ctx(candles)).action).toBe('HOLD');
  });

  it('mirrors with SELL on a 48-bar low break', () => {
    const candles = stamp(noiseRange(120));
    const pre = precomputeIndicators(candles);
    const v0 = new SeriesView(candles, pre, candles.length - 1, { symbol: 'TEST', timeframe: '1h', marketClass: 'crypto' });
    const rangeLow = v0.lowestLow(48, candles.length - 1);
    const last = candles[candles.length - 1];
    candles.push({
      timestamp: last.timestamp + H,
      open: last.close,
      high: last.close - 0.1,
      low: rangeLow - 1.5,
      close: rangeLow - 1.2,
      volume: 600,
    });
    const sig = new BreakoutStrategy().evaluate(ctx(candles));
    expect(sig.action).toBe('SELL');
    expect(sig.reason).toMatch(/48-bar low/);
  });
});

describe('MomentumStrategy', () => {
  it('emits BUY on strong positive ROC with a rising positive MACD histogram', () => {
    const candles = stamp(trending(180, 0.45, 11));
    const strat = new MomentumStrategy();
    const ind = precomputeIndicators(candles);
    let buys = 0;
    for (let i = 60; i < candles.length; i++) {
      const view = new SeriesView(candles, ind, i, { symbol: 'TEST', timeframe: '1h', marketClass: 'crypto' });
      const sig = strat.evaluate({ view, position: null, equity: 10_000 });
      if (sig.action === 'BUY') {
        buys++;
        expect(sig.reason).toMatch(/ROC/);
        expect(sig.stopLoss).toBeLessThan(view.close());
      }
    }
    expect(buys).toBeGreaterThanOrEqual(1);
  });

  it('holds in a flat noise range (no momentum)', () => {
    expect(new MomentumStrategy().evaluate(ctx(stamp(noiseRange(300, 3)))).action).toBe('HOLD');
  });

  it('closes an open long when the MACD histogram flips negative', () => {
    const candles = stamp([...trending(80, 0.35, 21), ...trending(120, -0.2, 33)]);
    const strat = new MomentumStrategy();
    const ind = precomputeIndicators(candles);
    let closed = false;
    for (let i = 60; i < candles.length && !closed; i++) {
      const view = new SeriesView(candles, ind, i, { symbol: 'TEST', timeframe: '1h', marketClass: 'crypto' });
      if ((view.macdHistogram(i) ?? 0) < 0) {
        const sig = strat.evaluate({
          view,
          position: { direction: 'LONG', entryPrice: candles[i].close, entryBar: i - 10, stopLoss: 0, takeProfit: 0, unrealizedPnl: 0 },
          equity: 10_000,
        });
        if (sig.action === 'CLOSE') {
          closed = true;
          expect(sig.reason).toMatch(/MACD histogram turned negative/);
        }
      }
    }
    expect(closed).toBe(true);
  });
});
