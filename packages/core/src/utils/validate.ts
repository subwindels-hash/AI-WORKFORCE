import { TIMEFRAME_MS, type Candle, type CandleValidation, type Timeframe } from '../types';

export interface NormalizeResult {
  candles: Candle[];
  validation: CandleValidation;
}

/**
 * Normalize and validate a raw candle series:
 *  - drop candles with non-finite or non-positive prices
 *  - drop candles with high < max(open, close) or low > min(open, close) after clamping attempt
 *  - enforce chronological order and de-duplicate timestamps
 *  - detect gaps relative to the expected timeframe interval
 *
 * Validation issues are reported honestly; the intelligence engine refuses to
 * produce a trade setup when validation fails hard (too little clean data).
 */
export function normalizeCandles(raw: Candle[], timeframe: Timeframe): NormalizeResult {
  const issues: string[] = [];
  const dropped: number[] = [];

  const clean: Candle[] = [];
  for (const c of raw) {
    const values = [c.open, c.high, c.low, c.close];
    const valid =
      values.every((v) => Number.isFinite(v) && v > 0) &&
      Number.isFinite(c.volume) &&
      c.volume >= 0 &&
      Number.isFinite(c.timestamp) &&
      c.timestamp > 0;
    if (!valid) {
      dropped.push(c.timestamp ?? 0);
      continue;
    }
    // Repairable inconsistency: clamp high/low into the o/c envelope.
    let { high, low } = c;
    if (high < Math.max(c.open, c.close)) {
      high = Math.max(c.open, c.close);
      issues.push(`Candle ${new Date(c.timestamp).toISOString()}: high clamped below close/open body`);
    }
    if (low > Math.min(c.open, c.close)) {
      low = Math.min(c.open, c.close);
      issues.push(`Candle ${new Date(c.timestamp).toISOString()}: low clamped above close/open body`);
    }
    clean.push({ ...c, high, low });
  }

  clean.sort((a, b) => a.timestamp - b.timestamp);

  const deduped: Candle[] = [];
  for (const c of clean) {
    if (deduped.length > 0 && deduped[deduped.length - 1].timestamp === c.timestamp) {
      issues.push(`Duplicate candle at ${new Date(c.timestamp).toISOString()} dropped`);
      continue;
    }
    deduped.push(c);
  }

  const interval = TIMEFRAME_MS[timeframe];
  let gapCount = 0;
  for (let i = 1; i < deduped.length; i++) {
    const delta = deduped[i].timestamp - deduped[i - 1].timestamp;
    if (delta > interval * 1.5) gapCount++;
  }

  const coveredIntervalMs =
    deduped.length > 1 ? deduped[deduped.length - 1].timestamp - deduped[0].timestamp : 0;

  const validation: CandleValidation = {
    ok: deduped.length >= 30 && gapCount <= Math.max(2, Math.floor(deduped.length * 0.1)),
    droppedCount: raw.length - deduped.length,
    gapCount,
    expectedIntervalMs: interval,
    coveredIntervalMs,
    minTimestamp: deduped.length ? deduped[0].timestamp : 0,
    maxTimestamp: deduped.length ? deduped[deduped.length - 1].timestamp : 0,
    issues: issues.slice(0, 20),
  };

  return { candles: deduped, validation };
}

export function candleCountGapRatio(candles: Candle[], timeframe: Timeframe): number {
  if (candles.length < 2) return 1;
  const interval = TIMEFRAME_MS[timeframe];
  const span = candles[candles.length - 1].timestamp - candles[0].timestamp;
  const expected = Math.round(span / interval) + 1;
  return candles.length / expected;
}
