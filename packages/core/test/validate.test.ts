import { describe, expect, it } from 'vitest';
import { normalizeCandles } from '../src/utils/validate';
import type { Candle } from '../src/types';

const c = (ts: number, close: number): Candle => ({ timestamp: ts, open: close, high: close, low: close, close, volume: 1 });

describe('normalizeCandles', () => {
  it('sorts unsorted input chronologically', () => {
    const { candles } = normalizeCandles([c(3000, 3), c(1000, 1), c(2000, 2)], '1h');
    expect(candles.map((x) => x.timestamp)).toEqual([1000, 2000, 3000]);
  });

  it('drops candles with NaN/negative prices and counts them', () => {
    const raw = [c(1000, 1), { ...c(2000, NaN) }, c(3000, -5), c(4000, 2)];
    const { candles, validation } = normalizeCandles(raw, '1h');
    expect(candles).toHaveLength(2);
    expect(validation.droppedCount).toBe(2);
  });

  it('drops duplicate timestamps', () => {
    const { candles, validation } = normalizeCandles([c(1000, 1), c(1000, 2), c(2000, 3)], '1h');
    expect(candles).toHaveLength(2);
    expect(validation.issues.some((i) => i.includes('Duplicate'))).toBe(true);
  });

  it('repairs high/low envelope violations and reports them', () => {
    const bad = { timestamp: 1000, open: 10, high: 9.5, low: 9.8, close: 10, volume: 1 };
    const { candles, validation } = normalizeCandles([bad], '1h');
    expect(candles[0].high).toBe(10);
    expect(candles[0].low).toBe(9.8);
    expect(validation.issues.length).toBeGreaterThan(0);
  });

  it('counts gaps relative to the timeframe interval', () => {
    const candles: Candle[] = [];
    for (let i = 0; i < 10; i++) candles.push(c(i * 3_600_000, 100 + i)); // hourly, no gaps
    candles.push(c(20 * 3_600_000, 110)); // one 10-hour hole
    const { validation } = normalizeCandles(candles, '1h');
    expect(validation.gapCount).toBe(1);
  });

  it('fails validation when data is too sparse', () => {
    const { validation } = normalizeCandles([c(1000, 1), c(2000, 2)], '1h');
    expect(validation.ok).toBe(false);
  });
});
