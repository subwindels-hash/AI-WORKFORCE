import type { CandleSeries, MarketRegime, RegimeAssessment } from '../types';
import { adx, atr, bollinger, ema, lastOf } from '../indicators/indicators';

/**
 * Market regime detection from indicator evidence.
 * Order of precedence: BREAKOUT > HIGH/LOW_VOLATILITY > TRENDING_* > RANGING.
 */
export function detectRegime(series: CandleSeries): RegimeAssessment {
  const { candles } = series;
  const closes = candles.map((c) => c.close);
  const evidence: string[] = [];

  if (candles.length < 60) {
    return {
      regime: 'UNKNOWN',
      confidence: 0.2,
      evidence: ['insufficient candles (<60) for regime classification'],
      volatilityPct: null,
      adx: null,
    };
  }

  const adxRes = adx(candles, 14);
  const adx14 = lastOf(adxRes.adx);
  const plusDi = lastOf(adxRes.plusDi);
  const minusDi = lastOf(adxRes.minusDi);
  const atr14 = lastOf(atr(candles, 14));
  const price = closes[closes.length - 1];
  const atrPct = atr14 !== null ? (atr14 / price) * 100 : null;

  // Volatility percentile: ATR% across the series history.
  const atrHist: number[] = [];
  for (let i = 0; i < candles.length; i++) {
    const a = lastOf(atr(candles.slice(0, i + 1), 14));
    if (a !== null) atrHist.push((a / closes[i]) * 100);
  }
  const atrPctNow = atrPct ?? null;
  const atrSorted = [...atrHist].sort((a, b) => a - b);
  const volPctile = atrPctNow !== null && atrSorted.length > 20 ? atrSorted.filter((v) => v <= atrPctNow).length / atrSorted.length : null;

  const ema20 = lastOf(ema(closes, 20));
  const ema50 = lastOf(ema(closes, 50));
  const bb = bollinger(closes, 20, 2);
  const bbUpper = lastOf(bb.upper);
  const bbLower = lastOf(bb.lower);
  const bbMid = lastOf(bb.mid);
  const bandwidthPct = bbUpper !== null && bbLower !== null && bbMid ? ((bbUpper - bbLower) / bbMid) * 100 : null;

  // Breakout: close beyond the 48-bar range (excluding the last 2 bars) with volume expansion.
  const lookback = candles.slice(-50, -2);
  const rangeHigh = Math.max(...lookback.map((c) => c.high));
  const rangeLow = Math.min(...lookback.map((c) => c.low));
  const volAvg = lookback.reduce((a, c) => a + c.volume, 0) / Math.max(1, lookback.length);
  const lastCandle = candles[candles.length - 1];
  const volumeExpansion = volAvg > 0 ? lastCandle.volume / volAvg : null;
  const isBreakoutUp = price > rangeHigh && (volumeExpansion === null || volumeExpansion > 1.2);
  const isBreakoutDown = price < rangeLow && (volumeExpansion === null || volumeExpansion > 1.2);

  let regime: MarketRegime = 'UNKNOWN';
  let confidence = 0.4;

  // Precedence: BREAKOUT > directional trend > volatility extremes > range.
  // Elevated volatility inside a trend is surfaced as evidence, not a regime
  // override — direction governs risk first, volatility second.
  // The DI-separation guard (>= 3 points) keeps degenerate series where
  // +DI ~ -DI ~ 0 from saturating DX and faking a trend.
  const diSeparation = plusDi !== null && minusDi !== null ? Math.abs(plusDi - minusDi) : null;
  const trendUp = adx14 !== null && adx14 >= 25 && ema20 !== null && ema50 !== null &&
    ema20 > ema50 && diSeparation !== null && diSeparation >= 3 && plusDi !== null && minusDi !== null && plusDi > minusDi;
  const trendDown = adx14 !== null && adx14 >= 25 && ema20 !== null && ema50 !== null &&
    ema20 < ema50 && diSeparation !== null && diSeparation >= 3 && plusDi !== null && minusDi !== null && minusDi > plusDi;

  if (isBreakoutUp || isBreakoutDown) {
    regime = 'BREAKOUT';
    confidence = 0.7;
    evidence.push(`close beyond ${isBreakoutUp ? '48-bar high' : '48-bar low'} (${isBreakoutUp ? rangeHigh.toFixed(5) : rangeLow.toFixed(5)})`);
    if (volumeExpansion !== null) evidence.push(`volume ${volumeExpansion.toFixed(1)}× average on the break`);
  } else if (trendUp || trendDown) {
    regime = trendUp ? 'TRENDING_UP' : 'TRENDING_DOWN';
    confidence = Math.min(0.9, 0.5 + (adx14 ?? 0) / 100);
    evidence.push(`ADX ${adx14?.toFixed(1)} with ${trendUp ? '+DI above -DI and EMA20 > EMA50' : '-DI above +DI and EMA20 < EMA50'}`);
    if (volPctile !== null && volPctile >= 0.85) evidence.push(`note: ATR% is elevated (${Math.round(volPctile * 100)}th percentile) despite the trend`);
  } else if (volPctile !== null && volPctile >= 0.9) {
    regime = 'HIGH_VOLATILITY';
    confidence = 0.6;
    evidence.push(`ATR% at the ${Math.round(volPctile * 100)}th percentile of its own history with no directional trend`);
  } else if (volPctile !== null && volPctile <= 0.1) {
    regime = 'LOW_VOLATILITY';
    confidence = 0.55;
    evidence.push(`ATR% at the ${Math.round(volPctile * 100)}th percentile of its own history`);
  }

  if (regime === 'UNKNOWN' && adx14 !== null && adx14 < 20) {
    regime = 'RANGING';
    confidence = 0.5;
    evidence.push(`ADX ${adx14.toFixed(1)} below 20 — no directional trend`);
    if (bandwidthPct !== null) evidence.push(`Bollinger bandwidth ${bandwidthPct.toFixed(2)}%`);
  }

  if (regime === 'UNKNOWN') {
    evidence.push('mixed evidence — trend, volatility and breakout tests disagree');
  }

  return {
    regime,
    confidence: Number(confidence.toFixed(2)),
    evidence,
    volatilityPct: atrPct === null ? null : Number(atrPct.toFixed(3)),
    adx: adx14 === null ? null : Number(adx14.toFixed(1)),
  };
}

/** How friendly the regime is for directional risk (0..1). */
export function regimeDirectionality(regime: MarketRegime): number {
  switch (regime) {
    case 'TRENDING_UP':
    case 'TRENDING_DOWN':
      return 1.0;
    case 'BREAKOUT':
      return 0.8;
    case 'RANGING':
      return 0.4;
    case 'LOW_VOLATILITY':
      return 0.5;
    case 'HIGH_VOLATILITY':
      return 0.3;
    default:
      return 0.2;
  }
}
