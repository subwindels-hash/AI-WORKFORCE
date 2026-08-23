import { describe, expect, it } from 'vitest';
import { detectRegime, regimeDirectionality } from '../src/analysis/regime';
import type { Candle, CandleSeries } from '../src/types';

const NOW = 1_755_000_000_000;

function series(closes: number[], volumes = 100): CandleSeries {
  const n = closes.length;
  const candles: Candle[] = closes.map((c, i) => {
    const prev = i > 0 ? closes[i - 1] : c;
    return {
      timestamp: NOW - (n - i) * 3_600_000,
      open: prev,
      // Multiplicative band keeps TR% roughly constant as price level changes.
      high: Math.max(prev, c) * 1.002,
      low: Math.min(prev, c) * 0.998,
      close: c,
      volume: volumes,
    };
  });
  return {
    symbol: 'TESTUSD', marketClass: 'forex', timeframe: '1h', candles,
    provenance: {
      source: 'test', synthetic: false, live: true, delayed: false,
      fetchedAt: NOW, dataTimestamp: candles[candles.length - 1].timestamp, dataAgeMs: 0, stale: false, fallbackChain: [],
    },
    validation: { ok: true, droppedCount: 0, gapCount: 0, expectedIntervalMs: 3_600_000, coveredIntervalMs: 0, minTimestamp: 0, maxTimestamp: 0, issues: [] },
  };
}

describe('detectRegime', () => {
  it('classifies a steady uptrend as TRENDING_UP with evidence', () => {
    const closes = Array.from({ length: 150 }, (_, i) => 100 + i * 0.4 + Math.sin(i / 5) * 0.3);
    const r = detectRegime(series(closes));
    expect(r.regime).toBe('TRENDING_UP');
    expect(r.evidence.length).toBeGreaterThan(0);
    expect(r.adx).not.toBeNull();
  });

  it('classifies a steady downtrend as TRENDING_DOWN', () => {
    const closes = Array.from({ length: 150 }, (_, i) => 200 - i * 0.4 + Math.sin(i / 5) * 0.3);
    const r = detectRegime(series(closes));
    expect(r.regime).toBe('TRENDING_DOWN');
  });

  it('classifies a jittered range as RANGING or LOW_VOLATILITY (never trending)', () => {
    // Deterministic jitter on a mean-reverting sine: irregular up/down moves
    // keep +DI ~ -DI so ADX stays low (a perfectly alternating series is a
    // degenerate case the DI-separation guard handles).
    const closes = Array.from({ length: 150 }, (_, i) => 100 + 0.3 * Math.sin(i / 2.5) + 0.05 * (((i * 37) % 13) - 6) / 6);
    const r = detectRegime(series(closes));
    expect(['RANGING', 'LOW_VOLATILITY']).toContain(r.regime);
  });

  it('detects a breakout above the 48-bar range with volume expansion', () => {
    const closes = Array.from({ length: 150 }, (_, i) => 100 + 0.3 * Math.sin(i / 2.5) + 0.05 * (((i * 37) % 13) - 6) / 6);
    closes.push(100.8); // decisively above the ~±0.35 range
    const s = series(closes);
    s.candles[s.candles.length - 1].volume = 500; // 5x average
    const r = detectRegime(s);
    expect(r.regime).toBe('BREAKOUT');
    expect(r.evidence.some((e) => /48-bar high/.test(e))).toBe(true);
  });

  it('returns UNKNOWN with low confidence for insufficient data', () => {
    const r = detectRegime(series(Array.from({ length: 30 }, (_, i) => 100 + i)));
    expect(r.regime).toBe('UNKNOWN');
    expect(r.confidence).toBeLessThan(0.5);
  });
});

describe('regimeDirectionality', () => {
  it('ranks regimes sensibly for directional risk', () => {
    expect(regimeDirectionality('TRENDING_UP')).toBeGreaterThan(regimeDirectionality('RANGING'));
    expect(regimeDirectionality('HIGH_VOLATILITY')).toBeLessThan(regimeDirectionality('TRENDING_DOWN'));
    expect(regimeDirectionality('UNKNOWN')).toBeLessThan(0.5);
  });
});
