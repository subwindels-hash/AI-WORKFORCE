import { describe, expect, it } from 'vitest';
import { TechnicalAgent } from '../src/agents/technical';
import { MarketStructureAgent, DEFAULT_STRUCTURE_RULES } from '../src/agents/market-structure';
import { ForexAgent } from '../src/agents/forex';
import { CryptoAgent } from '../src/agents/crypto';
import { SentimentAgent } from '../src/agents/sentiment';
import type { AnalysisContext } from '../src/agents/base';
import type { Candle, CandleSeries, DataProvenance } from '../src/types';
import { generateSyntheticCandles } from '../src/marketdata/providers/synthetic';

function seriesFrom(candles: Candle[], symbol = 'EURUSD', marketClass: CandleSeries['marketClass'] = 'forex'): CandleSeries {
  const provenance: DataProvenance = {
    source: 'synthetic-demo', synthetic: true, live: false, delayed: false,
    fetchedAt: Date.now(), dataTimestamp: candles.length ? candles[candles.length - 1].timestamp : 0,
    dataAgeMs: 0, stale: false, fallbackChain: [],
  };
  return {
    symbol, marketClass, timeframe: '1h', candles, provenance,
    validation: {
      ok: true, droppedCount: 0, gapCount: 0, expectedIntervalMs: 3_600_000,
      coveredIntervalMs: 0, minTimestamp: 0, maxTimestamp: 0, issues: [],
    },
  };
}

const NOW = 1_755_000_000_000;

function ctx(candles: Candle[], marketClass: CandleSeries['marketClass'] = 'forex'): AnalysisContext {
  return { series: seriesFrom(candles, marketClass === 'crypto' ? 'BTCUSDT' : 'EURUSD', marketClass), now: NOW };
}

describe('TechnicalAgent', () => {
  it('returns the full structured report with a bounded vote', async () => {
    const candles = generateSyntheticCandles('EURUSD', '1h', 300, NOW);
    const report = await new TechnicalAgent().analyze(ctx(candles));
    expect(report.agent).toBe('technical');
    expect(report.indicators.rsi14).not.toBeNull();
    expect(report.indicators.atr14).toBeGreaterThan(0);
    expect(report.structure.support.length + report.structure.resistance.length).toBeGreaterThan(0);
    expect(report.signals.length).toBeGreaterThanOrEqual(7);
    expect(Math.abs(report.vote.directionalScore)).toBeLessThanOrEqual(1);
    expect(report.dataQuality).toBeGreaterThan(0);
  });

  it('scores a clean uptrend bullish', async () => {
    const candles: Candle[] = [];
    let price = 100;
    for (let i = 0; i < 200; i++) {
      const open = price;
      const close = open + 0.5 + Math.sin(i) * 0.1;
      candles.push({ timestamp: NOW - (200 - i) * 3_600_000, open, high: close + 0.2, low: open - 0.1, close, volume: 100 });
      price = close;
    }
    const report = await new TechnicalAgent().analyze(ctx(candles));
    expect(report.aggregateScore).toBeGreaterThan(0.3);
    expect(report.vote.signal).toBe('BUY');
  });
});

describe('MarketStructureAgent — confirmation rules', () => {
  /**
   * Sine-wave range: highs peak near 101.5, lows trough near 98.5 with real
   * fractal variation (equal-high blocks would never form swings).
   */
  function rangeCandles(n: number): Candle[] {
    const candles: Candle[] = [];
    for (let i = 0; i < n; i++) {
      const close = 100 + 1.5 * Math.sin(i / 3);
      const prev = i > 0 ? 100 + 1.5 * Math.sin((i - 1) / 3) : close;
      // Per-bar wick variation ensures strict fractal dominance at peaks.
      const wickUp = 0.05 + 0.04 * Math.sin(i * 1.3);
      const wickDown = 0.05 + 0.04 * Math.cos(i * 1.3);
      candles.push({
        timestamp: NOW - (n - i) * 3_600_000,
        open: prev,
        high: Math.max(prev, close) + wickUp,
        low: Math.min(prev, close) - wickDown,
        close,
        volume: 50,
      });
    }
    return candles;
  }

  it('does NOT confirm a break from a wick alone (default rule)', async () => {
    const candles = rangeCandles(60);
    const swingHigh = Math.max(...candles.map((c) => c.high)); // ~101.55
    // Final candle: wick above the swing high but closes back inside the range
    candles.push({
      timestamp: candles[candles.length - 1].timestamp + 3_600_000,
      open: 100.1, high: swingHigh + 0.5, low: 100, close: 100.1, volume: 80,
    });
    const report = await new MarketStructureAgent().analyze(ctx(candles));
    expect(report.events.breakOfStructure.detected).toBe(true);
    expect(report.events.breakOfStructure.direction).toBe('BUY');
    expect(report.events.breakOfStructure.confirmedBy).toBe('WICK');
    expect(report.warnings.some((w) => /wick/i.test(w))).toBe(true);
  });

  it('confirms a break when price CLOSES beyond the level with a real body', async () => {
    const candles = rangeCandles(60);
    const swingHigh = Math.max(...candles.map((c) => c.high));
    candles.push({
      timestamp: candles[candles.length - 1].timestamp + 3_600_000,
      open: 100.8, high: swingHigh + 0.5, low: 100.6, close: swingHigh + 0.3, volume: 120,
    });
    const report = await new MarketStructureAgent().analyze(ctx(candles));
    expect(report.events.breakOfStructure.detected).toBe(true);
    expect(report.events.breakOfStructure.confirmedBy).toBe('CLOSE');
  });

  it('classifies swing sequences into a trend label', async () => {
    const candles: Candle[] = [];
    let price = 100;
    for (let i = 0; i < 160; i++) {
      const close = price + 0.15; // steady drift up
      const wiggleUp = Math.abs(Math.sin(i / 2)) * 1.5; // wiggle dominates drift => swings form
      const wiggleDown = Math.abs(Math.cos(i / 2)) * 1.5;
      candles.push({
        timestamp: NOW - (160 - i) * 3_600_000,
        open: price,
        high: close + 0.2 + wiggleUp,
        low: price - 0.2 - wiggleDown,
        close,
        volume: 50,
      });
      price = close;
    }
    const report = await new MarketStructureAgent().analyze(ctx(candles));
    expect(report.trendLabel).toBe('uptrend');
    expect(report.swingSequence.length).toBeGreaterThan(0);
  });

  it('exposes configurable confirmation rules', () => {
    expect(DEFAULT_STRUCTURE_RULES.requireCloseBeyond).toBe(true);
    expect(DEFAULT_STRUCTURE_RULES.minBodyRatio).toBeGreaterThan(0);
  });
});

describe('ForexAgent — honesty about macro data', () => {
  it('reports macro as unavailable (no provider) and derives strength from price only', async () => {
    const candles = generateSyntheticCandles('EURUSD', '1h', 200, NOW);
    const report = await new ForexAgent().analyze(ctx(candles));
    expect(report.macro.available).toBe(false);
    expect(report.macro.reason).toMatch(/No economic-calendar/i);
    expect(report.currencyStrength.derivedFrom).toBe('price-momentum');
    expect(report.pair.classification).toBe('major');
    expect(report.session.utcHour).toBe(new Date(NOW).getUTCHours());
    // Limitations must be explicit
    expect(report.dataLimitations.join(' ')).toMatch(/Interest-rate differentials/);
    expect(report.dataLimitations.join(' ')).toMatch(/CPI\/NFP\/FOMC/);
  });
});

describe('CryptoAgent — honesty about on-chain data', () => {
  it('returns dataAvailable:false with warning instead of fake on-chain analysis', async () => {
    const candles = generateSyntheticCandles('BTCUSDT', '1h', 200, NOW);
    const report = await new CryptoAgent().analyze(ctx(candles, 'crypto'));
    expect(report.onChain).toEqual({
      dataAvailable: false,
      warning: 'On-chain provider not configured',
    });
    expect(report.derivatives.dataAvailable).toBe(false);
    expect(report.marketDominance.dataAvailable).toBe(false);
    expect(report.priceAction.changePct24h).not.toBeNull();
  });

  it('warns when candles are synthetic', async () => {
    const candles = generateSyntheticCandles('BTCUSDT', '1h', 200, NOW);
    const report = await new CryptoAgent().analyze(ctx(candles, 'crypto'));
    expect(report.warnings.some((w) => /SYNTHETIC/.test(w))).toBe(true);
  });
});

describe('SentimentAgent — abstains without providers', () => {
  it('abstains from voting and states unavailability', async () => {
    const candles = generateSyntheticCandles('EURUSD', '1h', 100, NOW);
    const report = await new SentimentAgent().analyze(ctx(candles));
    expect(report.vote.votes).toBe(false);
    expect(report.news.available).toBe(false);
    expect(report.social.available).toBe(false);
    expect(report.note).toMatch(/NOT presented as sentiment/);
  });
});
