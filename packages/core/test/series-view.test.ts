import { describe, expect, it } from 'vitest';
import { LookAheadError, precomputeIndicators, SeriesView } from '../src/strategies/series-view';
import type { Candle } from '../src/types';

const NOW = 1_755_000_000_000;
const H = 3_600_000;

function candles(n: number): Candle[] {
  return Array.from({ length: n }, (_, i) => ({
    timestamp: NOW - (n - i) * H,
    open: 100 + i * 0.1,
    high: 100.5 + i * 0.1,
    low: 99.5 + i * 0.1,
    close: 100.2 + i * 0.1,
    volume: 100,
  }));
}

describe('SeriesView — look-ahead protection', () => {
  const cs = candles(100);
  const ind = precomputeIndicators(cs);

  it('allows reading the current bar and all history', () => {
    const v = new SeriesView(cs, ind, 60, { symbol: 'TEST', timeframe: '1h', marketClass: 'forex' });
    expect(v.close(60)).toBeCloseTo(cs[60].close, 8);
    expect(v.close(0)).toBe(cs[0].close);
    expect(v.close(59)).toBe(cs[59].close);
    expect(v.barsVisible).toBe(61);
  });

  it('throws LookAheadError on any future access', () => {
    const v = new SeriesView(cs, ind, 60, { symbol: 'TEST', timeframe: '1h', marketClass: 'forex' });
    expect(() => v.close(61)).toThrow(LookAheadError);
    expect(() => v.ema20(99)).toThrow(LookAheadError);
    expect(() => v.high(1000)).toThrow(/Look-ahead/);
    expect(() => v.close(-1)).toThrow(/Look-ahead/);
  });

  it('throws for future access via range helpers', () => {
    const v = new SeriesView(cs, ind, 60, { symbol: 'TEST', timeframe: '1h', marketClass: 'forex' });
    expect(() => v.highestHigh(10, 70)).toThrow(LookAheadError);
    expect(() => v.averageVolume(5, 65)).toThrow(LookAheadError);
  });

  it('range helpers exclude the reference bar (classic breakout range semantics)', () => {
    const v = new SeriesView(cs, ind, 60, { symbol: 'TEST', timeframe: '1h', marketClass: 'forex' });
    const hi = v.highestHigh(10, 60);
    // bars 50..59 highs, none of bar 60
    const expected = Math.max(...cs.slice(50, 60).map((c) => c.high));
    expect(hi).toBeCloseTo(expected, 10);
  });

  it('indicators are causal — values at bar i are identical to a fresh computation on the prefix', async () => {
    const { ema } = await import('../src/indicators/indicators');
    const v = new SeriesView(cs, ind, 80, { symbol: 'TEST', timeframe: '1h', marketClass: 'forex' });
    const freshEma = ema(cs.slice(0, 81).map((c) => c.close), 20);
    expect(v.ema20(80)).toBeCloseTo(freshEma[80] as number, 10);
  });
});
