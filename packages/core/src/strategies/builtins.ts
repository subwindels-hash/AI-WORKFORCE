import type { MarketClass, Timeframe } from '../types';
import type { TradingStrategy, StrategySignal, TradingContext } from './types';
import { clamp } from '../utils/math';

const ALL_CLASSES: MarketClass[] = ['forex', 'crypto', 'stock', 'etf', 'commodity', 'futures', 'indices'];
const ALL_TFS: Timeframe[] = ['5m', '15m', '1h', '4h', '1d'];

function hold(): StrategySignal {
  return { action: 'HOLD', reason: 'no entry condition', confidence: 0 };
}

function num(params: Record<string, number | boolean | string>, key: string, fallback: number): number {
  const v = params[key];
  return typeof v === 'number' && Number.isFinite(v) ? v : fallback;
}

// ---------------------------------------------------------------------------
// 1. Trend Following — EMA(20/50) cross with ADX trend-strength filter
// ---------------------------------------------------------------------------

export class TrendFollowingStrategy implements TradingStrategy {
  readonly id = 'trend-following';
  readonly version = '1.0.0';
  readonly name = 'Trend Following (EMA cross + ADX)';
  readonly description =
    'Long when EMA20 crosses above EMA50 with ADX >= threshold; exit on opposite cross. Shorts mirror when enabled. Stops at ATR multiple, targets at R multiple.';
  readonly marketClasses = ALL_CLASSES;
  readonly timeframes = ALL_TFS;
  readonly supportsShorts = true;

  constructor(readonly params: Record<string, number | boolean | string> = {
    fast: 20, slow: 50, adxMin: 25, stopAtr: 2, targetR: 3,
  }) {}

  evaluate(ctx: TradingContext): StrategySignal {
    const p = {
      fast: num(this.params, 'fast', 20),
      slow: num(this.params, 'slow', 50),
      adxMin: num(this.params, 'adxMin', 25),
      stopAtr: num(this.params, 'stopAtr', 2),
      targetR: num(this.params, 'targetR', 3),
    };
    const v = ctx.view;
    const i = v.index;
    if (i < p.slow + 2) return hold();

    const fast = v.ema20(i);
    const slow = v.ema50(i);
    const fastPrev = v.ema20(i - 1);
    const slowPrev = v.ema50(i - 1);
    const adx = v.adx14(i);
    const atr = v.atr14(i);
    if (fast === null || slow === null || fastPrev === null || slowPrev === null || adx === null || atr === null) return hold();

    const crossedUp = fastPrev <= slowPrev && fast > slow;
    const crossedDown = fastPrev >= slowPrev && fast < slow;

    if (ctx.position?.direction === 'LONG' && crossedDown) {
      return { action: 'CLOSE', reason: 'EMA20 crossed below EMA50 — trend flipped', confidence: 0.6 };
    }
    if (ctx.position?.direction === 'SHORT' && crossedUp) {
      return { action: 'CLOSE', reason: 'EMA20 crossed above EMA50 — trend flipped', confidence: 0.6 };
    }
    if (!ctx.position && crossedUp && adx >= p.adxMin) {
      const stop = v.close(i) - p.stopAtr * atr;
      return {
        action: 'BUY',
        reason: `EMA${p.fast} crossed above EMA${p.slow} with ADX ${adx.toFixed(1)} >= ${p.adxMin}`,
        confidence: clamp(adx / 60, 0.2, 0.95),
        stopLoss: stop,
        takeProfit: v.close(i) + p.targetR * (v.close(i) - stop),
      };
    }
    if (!ctx.position && crossedDown && adx >= p.adxMin) {
      const stop = v.close(i) + p.stopAtr * atr;
      return {
        action: 'SELL',
        reason: `EMA${p.fast} crossed below EMA${p.slow} with ADX ${adx.toFixed(1)} >= ${p.adxMin}`,
        confidence: clamp(adx / 60, 0.2, 0.95),
        stopLoss: stop,
        takeProfit: v.close(i) - p.targetR * (stop - v.close(i)),
      };
    }
    return hold();
  }
}

// ---------------------------------------------------------------------------
// 2. Mean Reversion — Bollinger touch + RSI extreme, exit at mid band
// ---------------------------------------------------------------------------

export class MeanReversionStrategy implements TradingStrategy {
  readonly id = 'mean-reversion';
  readonly version = '1.0.0';
  readonly name = 'Mean Reversion (Bollinger + RSI)';
  readonly description =
    'Long when close pierces the lower Bollinger band with RSI oversold; exit at the mid band or stop. Shorts mirror at the upper band. Range regime only.';
  readonly marketClasses = ALL_CLASSES;
  readonly timeframes = ALL_TFS;
  readonly supportsShorts = true;

  constructor(readonly params: Record<string, number | boolean | string> = {
    rsiLow: 30, rsiHigh: 70, adxMax: 30, stopAtr: 2.5,
  }) {}

  evaluate(ctx: TradingContext): StrategySignal {
    const p = {
      rsiLow: num(this.params, 'rsiLow', 30),
      rsiHigh: num(this.params, 'rsiHigh', 70),
      adxMax: num(this.params, 'adxMax', 30),
      stopAtr: num(this.params, 'stopAtr', 2.5),
    };
    const v = ctx.view;
    const i = v.index;
    if (i < 52) return hold();

    const lower = v.bbLower(i);
    const upper = v.bbUpper(i);
    const mid = v.bbMid(i);
    const rsi = v.rsi14(i);
    const adx = v.adx14(i);
    const atr = v.atr14(i);
    if (lower === null || upper === null || mid === null || rsi === null || adx === null || atr === null) return hold();

    // Exit logic first: mean reversion targets the mid band.
    if (ctx.position?.direction === 'LONG') {
      if (v.close(i) >= mid) return { action: 'CLOSE', reason: 'price reverted to the Bollinger mid band', confidence: 0.7 };
      return hold();
    }
    if (ctx.position?.direction === 'SHORT') {
      if (v.close(i) <= mid) return { action: 'CLOSE', reason: 'price reverted to the Bollinger mid band', confidence: 0.7 };
      return hold();
    }

    // Only fade ranges: skip when a strong trend is running.
    if (adx > p.adxMax) return hold();

    if (v.close(i) < lower && rsi < p.rsiLow) {
      const stop = v.close(i) - p.stopAtr * atr;
      return {
        action: 'BUY',
        reason: `close below lower band with RSI ${rsi.toFixed(1)} < ${p.rsiLow} in a range (ADX ${adx.toFixed(1)})`,
        confidence: clamp((p.rsiLow - rsi) / 15 + 0.5, 0.2, 0.9),
        stopLoss: stop,
        takeProfit: mid,
      };
    }
    if (v.close(i) > upper && rsi > p.rsiHigh) {
      const stop = v.close(i) + p.stopAtr * atr;
      return {
        action: 'SELL',
        reason: `close above upper band with RSI ${rsi.toFixed(1)} > ${p.rsiHigh} in a range (ADX ${adx.toFixed(1)})`,
        confidence: clamp((rsi - p.rsiHigh) / 15 + 0.5, 0.2, 0.9),
        stopLoss: stop,
        takeProfit: mid,
      };
    }
    return hold();
  }
}

// ---------------------------------------------------------------------------
// 3. Breakout — N-bar range break with volume expansion
// ---------------------------------------------------------------------------

export class BreakoutStrategy implements TradingStrategy {
  readonly id = 'breakout';
  readonly version = '1.0.0';
  readonly name = 'Breakout (range break + volume expansion)';
  readonly description =
    'Long when close breaks the N-bar high with volume >= multiple of average; stop at ATR multiple below the break, target at R multiple. Shorts mirror the N-bar low.';
  readonly marketClasses = ALL_CLASSES;
  readonly timeframes = ALL_TFS;
  readonly supportsShorts = true;

  constructor(readonly params: Record<string, number | boolean | string> = {
    lookback: 48, volMult: 1.5, stopAtr: 1.5, targetR: 2.5,
  }) {}

  evaluate(ctx: TradingContext): StrategySignal {
    const p = {
      lookback: Math.round(num(this.params, 'lookback', 48)),
      volMult: num(this.params, 'volMult', 1.5),
      stopAtr: num(this.params, 'stopAtr', 1.5),
      targetR: num(this.params, 'targetR', 2.5),
    };
    const v = ctx.view;
    const i = v.index;
    if (i < Math.max(p.lookback, 20) + 2) return hold();

    const atr = v.atr14(i);
    if (atr === null) return hold();
    const rangeHigh = v.highestHigh(p.lookback, i);
    const rangeLow = v.lowestLow(p.lookback, i);
    const avgVol = v.averageVolume(30, i - 1);
    const volOk = avgVol > 0 ? v.volume(i) >= p.volMult * avgVol : true;

    const brokeUp = v.close(i) > rangeHigh;
    const brokeDown = v.close(i) < rangeLow;

    if (ctx.position) return hold(); // breakouts manage exits via stop/target only

    if (brokeUp && volOk) {
      const stop = v.close(i) - p.stopAtr * atr;
      return {
        action: 'BUY',
        reason: `close ${v.close(i).toFixed(5)} broke the ${p.lookback}-bar high ${rangeHigh.toFixed(5)} with ${(v.volume(i) / avgVol).toFixed(1)}× volume`,
        confidence: clamp(0.5 + Math.min(0.4, (v.volume(i) / Math.max(avgVol, 1) - 1) / 4), 0.3, 0.95),
        stopLoss: stop,
        takeProfit: v.close(i) + p.targetR * (v.close(i) - stop),
      };
    }
    if (brokeDown && volOk) {
      const stop = v.close(i) + p.stopAtr * atr;
      return {
        action: 'SELL',
        reason: `close ${v.close(i).toFixed(5)} broke the ${p.lookback}-bar low ${rangeLow.toFixed(5)} with ${(v.volume(i) / avgVol).toFixed(1)}× volume`,
        confidence: clamp(0.5 + Math.min(0.4, (v.volume(i) / Math.max(avgVol, 1) - 1) / 4), 0.3, 0.95),
        stopLoss: stop,
        takeProfit: v.close(i) - p.targetR * (stop - v.close(i)),
      };
    }
    return hold();
  }
}

// ---------------------------------------------------------------------------
// 4. Momentum — rate-of-change + MACD confirmation
// ---------------------------------------------------------------------------

export class MomentumStrategy implements TradingStrategy {
  readonly id = 'momentum';
  readonly version = '1.0.0';
  readonly name = 'Momentum (ROC + MACD)';
  readonly description =
    'Long when N-bar rate of change exceeds a threshold with a positive and rising MACD histogram; exit when MACD histogram flips. Shorts mirror.';
  readonly marketClasses = ALL_CLASSES;
  readonly timeframes = ALL_TFS;
  readonly supportsShorts = true;

  constructor(readonly params: Record<string, number | boolean | string> = {
    rocPeriod: 20, rocMinPct: 1.5, stopAtr: 2, targetR: 3,
  }) {}

  evaluate(ctx: TradingContext): StrategySignal {
    const p = {
      rocPeriod: Math.round(num(this.params, 'rocPeriod', 20)),
      rocMinPct: num(this.params, 'rocMinPct', 1.5),
      stopAtr: num(this.params, 'stopAtr', 2),
      targetR: num(this.params, 'targetR', 3),
    };
    const v = ctx.view;
    const i = v.index;
    if (i < Math.max(p.rocPeriod, 52) + 2) return hold();

    const hist = v.macdHistogram(i);
    const histPrev = v.macdHistogram(i - 1);
    const atr = v.atr14(i);
    if (hist === null || histPrev === null || atr === null) return hold();

    const past = v.close(i - p.rocPeriod);
    const roc = past > 0 ? ((v.close(i) - past) / past) * 100 : 0;

    if (ctx.position?.direction === 'LONG' && hist < 0) {
      return { action: 'CLOSE', reason: 'MACD histogram turned negative — momentum faded', confidence: 0.6 };
    }
    if (ctx.position?.direction === 'SHORT' && hist > 0) {
      return { action: 'CLOSE', reason: 'MACD histogram turned positive — momentum faded', confidence: 0.6 };
    }
    if (!ctx.position && roc > p.rocMinPct && hist > 0 && hist > histPrev) {
      const stop = v.close(i) - p.stopAtr * atr;
      return {
        action: 'BUY',
        reason: `ROC${p.rocPeriod} ${roc.toFixed(2)}% > ${p.rocMinPct}% with rising positive MACD histogram`,
        confidence: clamp(0.4 + Math.min(0.5, roc / (p.rocMinPct * 6)), 0.3, 0.95),
        stopLoss: stop,
        takeProfit: v.close(i) + p.targetR * (v.close(i) - stop),
      };
    }
    if (!ctx.position && roc < -p.rocMinPct && hist < 0 && hist < histPrev) {
      const stop = v.close(i) + p.stopAtr * atr;
      return {
        action: 'SELL',
        reason: `ROC${p.rocPeriod} ${roc.toFixed(2)}% < -${p.rocMinPct}% with falling negative MACD histogram`,
        confidence: clamp(0.4 + Math.min(0.5, -roc / (p.rocMinPct * 6)), 0.3, 0.95),
        stopLoss: stop,
        takeProfit: v.close(i) - p.targetR * (stop - v.close(i)),
      };
    }
    return hold();
  }
}

export function builtinStrategies(): TradingStrategy[] {
  return [
    new TrendFollowingStrategy(),
    new MeanReversionStrategy(),
    new BreakoutStrategy(),
    new MomentumStrategy(),
  ];
}
