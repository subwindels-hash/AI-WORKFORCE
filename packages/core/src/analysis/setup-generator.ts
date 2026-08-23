import type {
  Bias, MarketStructureAgentReport, Scenario, TechnicalAgentReport, Timeframe, TradeSetup,
} from '../types';
import { TIMEFRAME_MS } from '../types';
import { ANALYSIS_DEFAULTS } from '../config/defaults';
import type { CandleSeries } from '../types';
import { lastOf, atr } from '../indicators/indicators';
import { clamp, round } from '../utils/math';

/**
 * Trade Setup Generator.
 *
 * Converts intelligence (bias, confidence, structure, S/R, ATR) into a fully
 * structured setup: entry ZONE (not a magic number), invalidation stop,
 * R-multiple targets capped by structure, R:R, expiry and invalidation
 * reasons. The setup is a PROPOSAL — it is always routed to the Risk Engine
 * and is never executed by anything in Phase 1.
 */
export function generateSetup(
  series: CandleSeries,
  technical: TechnicalAgentReport,
  structure: MarketStructureAgentReport,
  bias: Bias,
  confidence: number,
): TradeSetup | null {
  if (bias !== 'BULLISH' && bias !== 'BEARISH') return null;
  if (confidence < ANALYSIS_DEFAULTS.minSetupConfidence) return null;

  const { candles, timeframe } = series;
  const price = candles[candles.length - 1].close;
  const atr14 = lastOf(atr(candles, 14)) ?? price * 0.005;
  const action = bias === 'BULLISH' ? 'BUY' : 'SELL';

  const supports = technical.structure.support;
  const resistances = technical.structure.resistance;

  // --- Entry zone -----------------------------------------------------------
  // BUY: prefer a pullback zone between the nearest support and current price,
  // bounded to at most 0.75 ATR below price. SELL: mirror against resistance.
  let entryMin: number;
  let entryMax: number;
  if (action === 'BUY') {
    const nearestSupport = supports.length ? supports[supports.length - 1] : price - 0.5 * atr14;
    entryMax = price + 0.1 * atr14;
    entryMin = Math.max(nearestSupport, price - 0.75 * atr14);
    if (entryMax - entryMin < 0.2 * atr14) entryMin = entryMax - 0.3 * atr14;
  } else {
    const nearestResistance = resistances.length ? resistances[0] : price + 0.5 * atr14;
    entryMin = price - 0.1 * atr14;
    entryMax = Math.min(nearestResistance, price + 0.75 * atr14);
    if (entryMax - entryMin < 0.2 * atr14) entryMax = entryMin + 0.3 * atr14;
  }
  const entryRef = (entryMin + entryMax) / 2;

  // --- Stop loss (invalidation) ----------------------------------------------
  let stop: number;
  const invalidationReasons: string[] = [];
  if (action === 'BUY') {
    const belowSupport = supports.length ? Math.min(...supports) : entryMin - atr14;
    stop = Math.min(entryMin - 0.4 * atr14, belowSupport - 0.2 * atr14);
    // Cap the stop distance at 2 ATR — wider invalidations get rejected later by risk.
    if (entryRef - stop > 2 * atr14) stop = entryRef - 2 * atr14;
    invalidationReasons.push('close below the structural support invalidates the long thesis');
    if (structure.events.changeOfCharacter.detected && structure.events.changeOfCharacter.direction === 'SELL') {
      invalidationReasons.push('bearish change of character would flip the structure');
    }
  } else {
    const aboveResistance = resistances.length ? Math.max(...resistances) : entryMax + atr14;
    stop = Math.max(entryMax + 0.4 * atr14, aboveResistance + 0.2 * atr14);
    if (stop - entryRef > 2 * atr14) stop = entryRef + 2 * atr14;
    invalidationReasons.push('close above the structural resistance invalidates the short thesis');
    if (structure.events.changeOfCharacter.detected && structure.events.changeOfCharacter.direction === 'BUY') {
      invalidationReasons.push('bullish change of character would flip the structure');
    }
  }

  const stopDistance = Math.abs(entryRef - stop);
  if (stopDistance <= 0) return null;

  // --- Take profits: R-multiple ladder snapped to structure when close -------
  const targets: number[] = [];
  for (const rMult of [1.5, 2.5, 3.5]) {
    const raw = action === 'BUY' ? entryRef + rMult * stopDistance : entryRef - rMult * stopDistance;
    targets.push(raw);
  }
  // Snap targets to structure levels when a level sits within 0.35 R.
  const structureLevels = action === 'BUY' ? resistances : [...supports].reverse();
  const snapped = targets.map((t) => {
    for (const lvl of structureLevels) {
      if (Math.abs(lvl - t) < 0.35 * stopDistance) return lvl;
    }
    return t;
  });
  // Enforce monotonic distances.
  for (let i = 1; i < snapped.length; i++) {
    const minGap = 0.5 * stopDistance;
    if (action === 'BUY' && snapped[i] <= snapped[i - 1] + minGap) snapped[i] = snapped[i - 1] + minGap;
    if (action === 'SELL' && snapped[i] >= snapped[i - 1] - minGap) snapped[i] = snapped[i - 1] - minGap;
  }

  const riskReward = Math.abs(snapped[0] - entryRef) / stopDistance;

  const digits = price >= 100 ? 2 : price >= 10 ? 3 : price >= 1 ? 5 : 6;
  const expiryBars = ANALYSIS_DEFAULTS.setupExpiryBars;

  return {
    action,
    symbol: series.symbol,
    marketClass: series.marketClass,
    timeframe: timeframe as Timeframe,
    entry: {
      type: 'ZONE',
      min: round(Math.min(entryMin, entryMax), digits + 1),
      max: round(Math.max(entryMin, entryMax), digits + 1),
      reference: round(entryRef, digits + 1),
    },
    stopLoss: round(stop, digits + 1),
    takeProfit: snapped.map((t) => round(t, digits + 1)),
    riskReward: Number(riskReward.toFixed(2)),
    confidence: Number(confidence.toFixed(2)),
    expiration: new Date(Date.now() + expiryBars * TIMEFRAME_MS[timeframe]).toISOString(),
    invalidationReasons,
    rationale: [
      `${action} aligned with ${bias.toLowerCase()} confluence at ${confidence.toFixed(2)} confidence`,
      `entry zone anchored to ${action === 'BUY' ? 'support' : 'resistance'} structure, stop padded by 0.4 ATR`,
      `targets ladder at 1.5R/2.5R/3.5R snapped to ${action === 'BUY' ? 'resistance' : 'support'} levels`,
      `setup expires after ${expiryBars} bars (${timeframe})`,
    ],
  };
}

export function buildScenarios(
  series: CandleSeries,
  technical: TechnicalAgentReport,
  bias: Bias,
  price: number,
): { bullish: Scenario; bearish: Scenario; neutral: Scenario } {
  const { support, resistance } = technical.structure;
  const nearestRes = resistance.length ? resistance[0] : price * 1.01;
  const nearestSup = support.length ? support[support.length - 1] : price * 0.99;

  const bullish: Scenario = {
    summary: 'Bulls take control — break and hold above nearby resistance',
    triggers: [
      `close above ${nearestRes.toFixed(5)}`,
      technical.indicators.macd ? 'MACD histogram expanding positive' : 'momentum turning up',
    ],
    targets: resistance.length ? resistance.slice(0, 3) : [price * 1.02],
    invalidation: `close below ${nearestSup.toFixed(5)}`,
    probabilityHint: bias === 'BULLISH' ? 'primary' : 'alternate',
  };
  const bearish: Scenario = {
    summary: 'Bears press the break — lose support and extend lower',
    triggers: [
      `close below ${nearestSup.toFixed(5)}`,
      'MACD histogram expanding negative',
    ],
    targets: support.length ? [...support].reverse().slice(0, 3) : [price * 0.98],
    invalidation: `close above ${nearestRes.toFixed(5)}`,
    probabilityHint: bias === 'BEARISH' ? 'primary' : 'alternate',
  };
  const neutral: Scenario = {
    summary: 'Rotation continues between support and resistance',
    triggers: ['volume contraction inside the range', 'no confirmed break of structure'],
    targets: [nearestRes, nearestSup],
    invalidation: `decisive close beyond ${nearestSup.toFixed(5)} or ${nearestRes.toFixed(5)}`,
    probabilityHint: bias === 'NEUTRAL' || bias === 'NO_TRADE' ? 'base' : 'alternate',
  };

  void series;
  return { bullish, bearish, neutral };
}

/** Clamp helper kept for sizing symmetry. */
export function clampConfidence(c: number): number {
  return clamp(c, 0, 1);
}
