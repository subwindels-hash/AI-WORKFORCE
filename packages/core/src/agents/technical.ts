import type { CandleSeries, TechnicalAgentReport, TechnicalSignal } from '../types';
import { ANALYSIS_DEFAULTS } from '../config/defaults';
import { clamp } from '../utils/math';
import {
  adx, atr, bollinger, ema, lastOf, macd, pivotPoints, regressionSlopePct,
  rsi, sma, stochastic, supportResistance, volumeProfile, vwap,
} from '../indicators/indicators';
import { dataQualityOf, makeVote, type AnalysisContext, type TradingAgent } from './base';

/**
 * Technical Analysis Agent — pure quant, no external claims.
 * Computes the full indicator suite, derives individual signals plus an
 * aggregate score in [-1, 1].
 */
export class TechnicalAgent implements TradingAgent<TechnicalAgentReport> {
  readonly id = 'technical';
  readonly title = 'Technical Analysis Agent';
  readonly description =
    'SMA/EMA, RSI, MACD, Bollinger, ATR, ADX, VWAP, Stochastic, swing support/resistance, pivots and volume profile.';
  readonly marketClasses = ['*'];

  isApplicable(): boolean {
    return true;
  }

  async analyze(ctx: AnalysisContext): Promise<TechnicalAgentReport> {
    const { candles } = ctx.series;
    const closes = candles.map((c) => c.close);
    const last = candles[candles.length - 1];
    const price = last.close;

    const sma20 = lastOf(sma(closes, 20));
    const sma50 = lastOf(sma(closes, 50));
    const sma200 = lastOf(sma(closes, 200));
    const ema20 = lastOf(ema(closes, 20));
    const ema50 = lastOf(ema(closes, 50));
    const rsi14 = lastOf(rsi(closes, 14));
    const macdRes = macd(closes);
    const macdLast = lastOf(macdRes.macd);
    const macdSignal = lastOf(macdRes.signal);
    const macdHist = lastOf(macdRes.histogram);
    const bb = bollinger(closes, 20, 2);
    const bbUpper = lastOf(bb.upper);
    const bbMid = lastOf(bb.mid);
    const bbLower = lastOf(bb.lower);
    const atr14 = lastOf(atr(candles, 14));
    const atrPct = atr14 !== null ? (atr14 / price) * 100 : null;
    const adxRes = adx(candles, 14);
    const adx14 = { adx: lastOf(adxRes.adx), plusDi: lastOf(adxRes.plusDi), minusDi: lastOf(adxRes.minusDi) };
    const vwapLast = lastOf(vwap(candles));
    const stoch = stochastic(candles, 14, 3);
    const stochK = lastOf(stoch.k);
    const stochD = lastOf(stoch.d);
    const slopePct = regressionSlopePct(closes, 50);
    const { support, resistance } = supportResistance(candles, atr14, price);
    const pivots = pivotPoints(candles[candles.length - 2]);
    const vp = volumeProfile(candles, 24);

    const signals: TechnicalSignal[] = [];
    const push = (name: string, value: number | null, signal: TechnicalSignal['signal'], detail: string) =>
      signals.push({ name, value, signal, detail });

    // Trend signals
    if (ema20 !== null && ema50 !== null) {
      push('EMA20 vs EMA50', null, ema20 > ema50 ? 'BUY' : 'SELL', `EMA20 ${fmt(ema20)} ${ema20 > ema50 ? '>' : '<'} EMA50 ${fmt(ema50)}`);
    }
    if (sma50 !== null) {
      push('Price vs SMA50', null, price > sma50 ? 'BUY' : 'SELL', `close ${fmt(price)} vs SMA50 ${fmt(sma50)}`);
    }
    if (slopePct !== null) {
      push('Trend slope (50-bar regression)', slopePct, slopePct > 0.01 ? 'BUY' : slopePct < -0.01 ? 'SELL' : 'NEUTRAL', `${slopePct.toFixed(3)}%/bar`);
    }

    // Momentum signals
    if (rsi14 !== null) {
      const sig = rsi14 > 60 ? 'BUY' : rsi14 < 40 ? 'SELL' : 'NEUTRAL';
      push('RSI(14)', rsi14, sig, rsi14 > 70 ? 'overbought — treat longs with caution' : rsi14 < 30 ? 'oversold — treat shorts with caution' : 'mid-range');
    }
    if (macdHist !== null && macdLast !== null && macdSignal !== null) {
      push('MACD(12,26,9) histogram', macdHist, macdHist > 0 ? 'BUY' : 'SELL', `macd ${fmt(macdLast)} vs signal ${fmt(macdSignal)}`);
    }
    if (stochK !== null && stochD !== null) {
      const sig = stochK > 80 ? (stochK < stochD ? 'SELL' : 'NEUTRAL') : stochK < 20 ? (stochK > stochD ? 'BUY' : 'NEUTRAL') : stochK > stochD ? 'BUY' : 'SELL';
      push('Stochastic(14,3)', stochK, sig, `%K ${stochK.toFixed(1)} / %D ${stochD.toFixed(1)}`);
    }

    // Volatility / mean-reversion context
    if (bbUpper !== null && bbLower !== null && bbMid !== null) {
      const width = bbUpper - bbLower;
      const pos = width > 0 ? (price - bbLower) / width : 0.5;
      const sig = pos > 0.95 ? 'SELL' : pos < 0.05 ? 'BUY' : 'NEUTRAL';
      push('Bollinger position', pos, sig, `price at ${(pos * 100).toFixed(0)}% of band`);
    }
    if (vwapLast !== null) {
      push('VWAP', vwapLast, price > vwapLast ? 'BUY' : 'SELL', `close ${fmt(price)} vs VWAP ${fmt(vwapLast)}`);
    }
    if (adx14.adx !== null && adx14.plusDi !== null && adx14.minusDi !== null) {
      const trending = adx14.adx >= 25;
      const dirSig: TechnicalSignal['signal'] = !trending
        ? 'NEUTRAL'
        : adx14.plusDi > adx14.minusDi
          ? 'BUY'
          : 'SELL';
      push('ADX(14) / DI', adx14.adx, dirSig, `ADX ${adx14.adx.toFixed(1)} (${trending ? 'trending' : 'weak trend'}), +DI ${adx14.plusDi.toFixed(1)} / -DI ${adx14.minusDi.toFixed(1)}`);
    }

    // Aggregate score: weighted average of BUY(+1)/SELL(-1)/NEUTRAL(0) with weights
    const weights: Record<string, number> = {
      'EMA20 vs EMA50': 1.2,
      'Price vs SMA50': 1,
      'Trend slope (50-bar regression)': 1,
      'RSI(14)': 0.8,
      'MACD(12,26,9) histogram': 1,
      'Stochastic(14,3)': 0.6,
      'Bollinger position': 0.4,
      VWAP: 0.6,
      'ADX(14) / DI': 0.8,
    };
    let acc = 0;
    let wsum = 0;
    for (const s of signals) {
      const w = weights[s.name] ?? 0.5;
      acc += (s.signal === 'BUY' ? 1 : s.signal === 'SELL' ? -1 : 0) * w;
      wsum += w;
    }
    const aggregate = wsum === 0 ? 0 : acc / wsum;

    const trendStrengthRaw = adx14.adx !== null ? clamp(adx14.adx / 50, 0, 1) : 0.3;
    const trend: 'up' | 'down' | 'sideways' =
      ema20 !== null && ema50 !== null && trendStrengthRaw > 0.4 ? (ema20 > ema50 ? 'up' : 'down') : 'sideways';

    const volLabels: TechnicalSignal[] = signals; // alias for readability below

    return {
      agent: 'technical',
      title: this.title,
      generatedAt: ctx.now,
      dataQuality: dataQualityOf(ctx.series),
      dataLimitations: ctx.series.candles.length < 200 ? ['Fewer than 200 candles — SMA200 not available'] : [],
      warnings:
        rsi14 !== null && (rsi14 > 70 || rsi14 < 30)
          ? [`RSI ${rsi14.toFixed(1)} is ${rsi14 > 70 ? 'overbought' : 'oversold'} — counter-trend entries penalized`]
          : [],
      vote: makeVote(
        aggregate,
        ANALYSIS_DEFAULTS.agentWeights.technical,
        `${signals.filter((s) => s.signal === 'BUY').length} bullish / ${signals.filter((s) => s.signal === 'SELL').length} bearish of ${volLabels.length} indicators`,
      ),
      indicators: {
        sma20, sma50, sma200, ema20, ema50, rsi14,
        macd: { macd: macdLast, signal: macdSignal, histogram: macdHist },
        macdBias: macdHist !== null ? (macdHist > 0 ? 'BUY' : 'SELL') : 'NEUTRAL',
        bollinger: {
          upper: bbUpper, mid: bbMid, lower: bbLower,
          bandwidthPct: bbUpper !== null && bbLower !== null && bbMid ? ((bbUpper - bbLower) / bbMid) * 100 : null,
        },
        atr14, atrPct,
        adx14, vwap: vwapLast,
        stochastic: { k: stochK, d: stochD },
      },
      structure: {
        trend,
        trendStrength: trendStrengthRaw,
        momentum: macdHist !== null ? (macdHist > 0 ? 'BUY' : 'SELL') : 'NEUTRAL',
        support,
        resistance,
        pivots,
        volumeProfile: { poc: vp.poc, valueAreaHigh: vp.valueAreaHigh, valueAreaLow: vp.valueAreaLow },
      },
      signals,
      aggregateScore: aggregate,
    };
  }
}

function fmt(v: number): string {
  if (v >= 1000) return v.toFixed(1);
  if (v >= 10) return v.toFixed(3);
  return v.toFixed(5);
}
