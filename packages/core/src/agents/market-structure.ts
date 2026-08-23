import type { Candle, MarketStructureAgentReport, SignalDirection } from '../types';
import { ANALYSIS_DEFAULTS } from '../config/defaults';
import { findSwings } from '../indicators/indicators';
import { lastOf, atr } from '../indicators/indicators';
import { dataQualityOf, makeVote, type AnalysisContext, type TradingAgent } from './base';

/**
 * Market Structure Agent — swing mapping, BOS/CHoCH, liquidity, zones.
 *
 * CONFIRMATION POLICY (configurable): a structural break is only "confirmed"
 * when a candle CLOSES beyond the reference level. A wick beyond the level is
 * explicitly reported as WICK-only and does NOT count as confirmation.
 */
export interface StructureConfirmationRules {
  /** Require a close beyond the level (default true). Wick-only breaks are reported but unconfirmed. */
  requireCloseBeyond: boolean;
  /** Minimum body/range ratio for the confirming candle (rejects doji spikes). */
  minBodyRatio: number;
  /** Swing strength in bars on each side. */
  swingStrength: number;
}

export const DEFAULT_STRUCTURE_RULES: StructureConfirmationRules = {
  requireCloseBeyond: true,
  minBodyRatio: 0.3,
  swingStrength: 2,
};

export class MarketStructureAgent implements TradingAgent<MarketStructureAgentReport> {
  readonly id = 'market-structure';
  readonly title = 'Market Structure Agent';
  readonly description =
    'HH/HL/LH/LL swing sequences, break of structure, change of character, liquidity zones, supply/demand, order blocks, fair value gaps. Close-based confirmation by default.';
  readonly marketClasses = ['*'];

  constructor(private readonly rules: StructureConfirmationRules = DEFAULT_STRUCTURE_RULES) {}

  isApplicable(): boolean {
    return true;
  }

  async analyze(ctx: AnalysisContext): Promise<MarketStructureAgentReport> {
    const { candles } = ctx.series;
    const k = this.rules.swingStrength;
    const swings = findSwings(candles, k);
    const atr14 = lastOf(atr(candles, 14));
    const lastClose = candles[candles.length - 1].close;

    // --- Swing sequence -----------------------------------------------------
    const swingSequence: MarketStructureAgentReport['swingSequence'] = [];
    for (let i = 2; i < swings.length; i++) {
      const cur = swings[i];
      const prevSame = [...swings.slice(0, i)].reverse().find((s) => s.type === cur.type);
      if (!prevSame) continue;
      if (cur.type === 'high') {
        swingSequence.push(cur.price > prevSame.price ? 'HH' : 'LH');
      } else {
        swingSequence.push(cur.price < prevSame.price ? 'LL' : 'HL');
      }
    }
    const tail = swingSequence.slice(-6);
    const hh = tail.filter((s) => s === 'HH').length;
    const hl = tail.filter((s) => s === 'HL').length;
    const lh = tail.filter((s) => s === 'LH').length;
    const ll = tail.filter((s) => s === 'LL').length;
    const trendLabel: MarketStructureAgentReport['trendLabel'] =
      hh + hl >= lh + ll + 2 ? 'uptrend' : lh + ll >= hh + hl + 2 ? 'downtrend' : 'range';

    // --- BOS / CHoCH with confirmation rules --------------------------------
    const lastHigh = [...swings].reverse().find((s) => s.type === 'high');
    const lastLow = [...swings].reverse().find((s) => s.type === 'low');
    const bos = this.detectBreak(candles, lastHigh?.price ?? null, lastLow?.price ?? null);
    const choch = this.detectChoch(swings, candles, bos);
    // --- Zones --------------------------------------------------------------
    const lookback = Math.min(candles.length, 60);
    const window = candles.slice(-lookback);
    const liquidityZones = swings.slice(-8).map((s) => ({
      type: (s.type === 'high' ? 'buy-side' : 'sell-side') as 'buy-side' | 'sell-side',
      price: s.price,
      formedAt: s.timestamp,
    }));

    const { supply, demand, orderBlocks, fvgs } = this.detectZones(window, atr14);

    // --- Vote ---------------------------------------------------------------
    let score = 0;
    const reasons: string[] = [];
    if (trendLabel === 'uptrend') { score += 0.35; reasons.push('swing sequence shows HH/HL dominance'); }
    if (trendLabel === 'downtrend') { score -= 0.35; reasons.push('swing sequence shows LH/LL dominance'); }
    if (bos.detected && bos.direction === 'BUY' && bos.confirmedBy === 'CLOSE') { score += 0.3; reasons.push('confirmed bullish break of structure (close beyond)'); }
    if (bos.detected && bos.direction === 'SELL' && bos.confirmedBy === 'CLOSE') { score -= 0.3; reasons.push('confirmed bearish break of structure (close beyond)'); }
    if (choch.detected && choch.confirmedBy === 'CLOSE') {
      score += choch.direction === 'BUY' ? 0.25 : -0.25;
      reasons.push(`change of character ${choch.direction === 'BUY' ? 'bullish' : 'bearish'} (close-confirmed)`);
    }
    if (bos.detected && bos.confirmedBy === 'WICK') {
      reasons.push('price wicked beyond structure but did NOT close beyond — unconfirmed');
    }
    // Price relative to the most recent demand/supply zone
    const nearestDemand = demand.filter((z) => z.max < lastClose).pop();
    const nearestSupply = [...supply.filter((z) => z.min > lastClose)].shift();
    if (nearestDemand && atr14 && lastClose - nearestDemand.max < atr14) { score += 0.15; reasons.push('price resting on demand zone'); }
    if (nearestSupply && atr14 && nearestSupply.min - lastClose < atr14) { score -= 0.15; reasons.push('price pressing into supply zone'); }

    return {
      agent: 'market-structure',
      title: this.title,
      generatedAt: ctx.now,
      dataQuality: dataQualityOf(ctx.series),
      dataLimitations: [
        `Zone detection uses the last ${lookback} bars only`,
        ...(swings.length < 4 ? ['Fewer than 4 swings detected — structure mapping is weak'] : []),
      ],
      warnings: bos.detected && bos.confirmedBy === 'WICK' ? ['Wick-only break NOT treated as confirmation (configured rule)'] : [],
      vote: makeVote(score, ANALYSIS_DEFAULTS.agentWeights['market-structure'], reasons.slice(0, 3).join('; ') || 'no dominant structure'),
      swingSequence: tail,
      trendLabel,
      events: {
        breakOfStructure: { ...bos, level: bos.level, confirmedBy: bos.confirmedBy, barsAgo: bos.barsAgo },
        changeOfCharacter: choch,
      },
      liquidityZones,
      supplyZones: supply,
      demandZones: demand,
      orderBlocks,
      fairValueGaps: fvgs,
    };
  }

  private detectBreak(
    candles: Candle[],
    swingHigh: number | null,
    swingLow: number | null,
  ): { detected: boolean; direction: SignalDirection; level: number | null; confirmedBy: 'CLOSE' | 'WICK' | 'NONE'; barsAgo: number | null } {
    const result = {
      detected: false as boolean,
      direction: 'NEUTRAL' as SignalDirection,
      level: null as number | null,
      confirmedBy: 'NONE' as 'CLOSE' | 'WICK' | 'NONE',
      barsAgo: null as number | null,
    };
    if (!swingHigh && !swingLow) return result;

    const scan = Math.min(candles.length, 10);
    for (let i = candles.length - scan; i < candles.length; i++) {
      const c = candles[i];
      const bodyRatio = c.high - c.low > 0 ? (Math.abs(c.close - c.open) / (c.high - c.low)) : 0;
      const bodyOk = bodyRatio >= this.rules.minBodyRatio;
      if (swingHigh !== null && (c.high > swingHigh || c.close > swingHigh)) {
        result.detected = true;
        result.direction = 'BUY';
        result.level = swingHigh;
        result.barsAgo = candles.length - 1 - i;
        if (c.close > swingHigh && (!this.rules.requireCloseBeyond || bodyOk)) {
          result.confirmedBy = 'CLOSE';
        } else if (c.high > swingHigh) {
          result.confirmedBy = 'WICK';
        }
        if (result.confirmedBy === 'CLOSE') break;
      }
      if (swingLow !== null && (c.low < swingLow || c.close < swingLow)) {
        result.detected = true;
        result.direction = 'SELL';
        result.level = swingLow;
        result.barsAgo = candles.length - 1 - i;
        if (c.close < swingLow && (!this.rules.requireCloseBeyond || bodyOk)) {
          result.confirmedBy = 'CLOSE';
        } else if (c.low < swingLow) {
          result.confirmedBy = 'WICK';
        }
        if (result.confirmedBy === 'CLOSE') break;
      }
    }
    return result;
  }

  /** CHoCH: a confirmed break against the prevailing swing sequence direction. */
  private detectChoch(
    swings: ReturnType<typeof findSwings>,
    candles: Candle[],
    bos: { detected: boolean; direction: SignalDirection; level: number | null; confirmedBy: 'CLOSE' | 'WICK' | 'NONE' },
  ): MarketStructureAgentReport['events']['changeOfCharacter'] {
    const seq: MarketStructureAgentReport['swingSequence'] = [];
    for (let i = 2; i < swings.length; i++) {
      const cur = swings[i];
      const prevSame = [...swings.slice(0, i)].reverse().find((s) => s.type === cur.type);
      if (!prevSame) continue;
      if (cur.type === 'high') seq.push(cur.price > prevSame.price ? 'HH' : 'LH');
      else seq.push(cur.price < prevSame.price ? 'LL' : 'HL');
    }
    const tail = seq.slice(-6);
    const bullBefore = tail.slice(0, -1).filter((s) => s === 'HH' || s === 'HL').length >= 3;
    const bearBefore = tail.slice(0, -1).filter((s) => s === 'LH' || s === 'LL').length >= 3;

    if (bos.detected && bos.confirmedBy === 'CLOSE') {
      if (bos.direction === 'SELL' && bullBefore) {
        return { detected: true, direction: 'SELL', level: bos.level, confirmedBy: 'CLOSE' };
      }
      if (bos.direction === 'BUY' && bearBefore) {
        return { detected: true, direction: 'BUY', level: bos.level, confirmedBy: 'CLOSE' };
      }
    }
    return { detected: false, direction: 'NEUTRAL', level: null, confirmedBy: 'NONE' };
  }

  private detectZones(window: Candle[], atrValue: number | null) {
    const supply: MarketStructureAgentReport['supplyZones'] = [];
    const demand: MarketStructureAgentReport['demandZones'] = [];
    const orderBlocks: MarketStructureAgentReport['orderBlocks'] = [];
    const fvgs: MarketStructureAgentReport['fairValueGaps'] = [];

    for (let i = 2; i < window.length - 1; i++) {
      const c0 = window[i - 2], c1 = window[i - 1], c2 = window[i];
      const range = (c: Candle) => c.high - c.low;

      // Bearish impulse -> the last up candle before it is a supply zone / bearish OB
      const bearImpulse = c1.close < c1.open && range(c1) > 1.2 * (atrValue ?? range(c1));
      if (bearImpulse && c0.close > c0.open) {
        supply.push({ min: c0.low, max: c0.high, formedAt: c0.timestamp });
        orderBlocks.push({ side: 'bearish', min: c0.low, max: c0.high, formedAt: c0.timestamp });
      }
      // Bullish impulse -> last down candle is demand / bullish OB
      const bullImpulse = c1.close > c1.open && range(c1) > 1.2 * (atrValue ?? range(c1));
      if (bullImpulse && c0.close < c0.open) {
        demand.push({ min: c0.low, max: c0.high, formedAt: c0.timestamp });
        orderBlocks.push({ side: 'bullish', min: c0.low, max: c0.high, formedAt: c0.timestamp });
      }

      // Fair value gap: 3-candle imbalance (c0.high < c2.low bullish; c0.low > c2.high bearish)
      if (c0.high < c2.low) fvgs.push({ direction: 'bullish', min: c0.high, max: c2.low, formedAt: c1.timestamp });
      if (c0.low > c2.high) fvgs.push({ direction: 'bearish', min: c2.high, max: c0.low, formedAt: c1.timestamp });
    }

    // Keep the freshest zones (last 4 each) to avoid clutter.
    return {
      supply: supply.slice(-4),
      demand: demand.slice(-4),
      orderBlocks: orderBlocks.slice(-4),
      fvgs: fvgs.slice(-4),
    };
  }
}
