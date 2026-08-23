import type { CandleSeries, ForexAgentReport } from '../types';
import { TIMEFRAME_MS } from '../types';
import { ANALYSIS_DEFAULTS } from '../config/defaults';
import { lastOf, atr, ema } from '../indicators/indicators';
import { clamp } from '../utils/math';
import { dataQualityOf, makeVote, type AnalysisContext, type TradingAgent } from './base';
import { splitPair } from '../marketdata/providers/frankfurter';

const MAJORS = new Set(['EURUSD', 'GBPUSD', 'USDJPY', 'USDCHF', 'USDCAD', 'AUDUSD', 'NZDUSD']);
const USD_PAIR_MATRIX = ['EURUSD', 'GBPUSD', 'USDJPY', 'AUDUSD', 'USDCAD', 'USDCHF', 'NZDUSD'];

/**
 * Forex Analysis Agent.
 *
 * Does real, data-grounded work: pair classification, ATR volatility regime,
 * EMA trend alignment, trading-session clock, and USD-cross currency strength
 * derived from PRICE MOMENTUM (explicitly labeled as such — it is not news
 * and not fundamentals).
 *
 * Macro inputs (rate differentials, CPI/NFP/FOMC calendar, central-bank
 * events) require a dedicated macro provider. None is configured in Phase 1,
 * so the report returns an explicit `macro.available = false` — it never
 * invents economic events.
 */
export class ForexAgent implements TradingAgent<ForexAgentReport> {
  readonly id = 'forex';
  readonly title = 'Forex Analysis Agent';
  readonly description =
    'Pair classification, volatility regime, trend alignment, session timing and price-momentum currency strength. Rate/calendar data requires a macro provider (not configured in Phase 1).';
  readonly marketClasses = ['forex', 'commodity'];

  isApplicable(ctx: AnalysisContext): boolean {
    return ctx.series.marketClass === 'forex' || ctx.series.marketClass === 'commodity';
  }

  async analyze(ctx: AnalysisContext): Promise<ForexAgentReport> {
    const { candles, symbol } = ctx.series;
    const closes = candles.map((c) => c.close);
    const price = closes[closes.length - 1];
    const { base, quote } = splitPair(symbol);
    const atr14 = lastOf(atr(candles, 14));
    const atrPct = atr14 !== null ? (atr14 / price) * 100 : null;
    const ema20 = lastOf(ema(closes, 20));
    const ema50 = lastOf(ema(closes, 50));
    const trendAlignment = ema20 !== null && ema50 !== null ? ema20 > ema50 : null;

    const volLabel: ForexAgentReport['volatility']['label'] =
      atrPct === null ? 'normal' : atrPct > 1.2 ? 'high' : atrPct < 0.25 ? 'low' : 'normal';

    const session = this.sessionInfo(ctx.now);

    const strength = this.currencyStrength(ctx, base, quote);

    // Vote: trend alignment + relative currency strength of the pair legs.
    let score = 0;
    const reasons: string[] = [];
    if (trendAlignment !== null) {
      score += trendAlignment ? 0.4 : -0.4;
      reasons.push(`EMA20 ${trendAlignment ? 'above' : 'below'} EMA50`);
    }
    const baseScore = strength.scores.find((s) => s.currency === base)?.score ?? 0;
    const quoteScore = strength.scores.find((s) => s.currency === quote)?.score ?? 0;
    if (strength.strongest && strength.strongest === base) { score += 0.25; reasons.push(`${base} is the strongest leg of the USD matrix`); }
    if (strength.strongest && strength.strongest === quote) { score -= 0.25; reasons.push(`${quote} is the strongest leg of the USD matrix`); }
    if (strength.weakest && strength.weakest === base) { score -= 0.2; reasons.push(`${base} is the weakest leg of the USD matrix`); }
    if (strength.weakest && strength.weakest === quote) { score += 0.2; reasons.push(`${quote} is the weakest leg of the USD matrix`); }
    score += clamp((baseScore - quoteScore) * 0.5, -0.2, 0.2);

    const limitations: string[] = [
      'Interest-rate differentials: no macro provider configured',
      'Economic calendar (CPI/NFP/FOMC): no macro provider configured',
      'Central-bank events: no macro provider configured',
    ];
    if (!MAJORS.has(symbol.toUpperCase()) && symbol.toUpperCase() !== 'XAUUSD') {
      limitations.push('Minor/exotic classification uses static pair table');
    }

    return {
      agent: 'forex',
      title: this.title,
      generatedAt: ctx.now,
      dataQuality: dataQualityOf(ctx.series),
      dataLimitations: limitations,
      warnings: strength.synthetic ? ['Currency strength computed from SYNTHETIC candles — not representative of real markets'] : [],
      vote: makeVote(score, ANALYSIS_DEFAULTS.agentWeights.forex, reasons.join('; ') || 'no decisive forex edge'),
      pair: {
        symbol: symbol.toUpperCase(),
        base,
        quote,
        classification: symbol.toUpperCase() === 'XAUUSD' ? 'other' : MAJORS.has(symbol.toUpperCase()) ? 'major' : ECB_MINORS.has(symbol.toUpperCase()) ? 'minor' : 'exotic',
      },
      volatility: { atrPct, label: volLabel },
      trendAlignment: {
        emaFastAboveSlow: trendAlignment,
        detail: ema20 !== null && ema50 !== null ? `EMA20 ${ema20.toFixed(5)} vs EMA50 ${ema50.toFixed(5)}` : 'insufficient data',
      },
      session,
      macro: {
        available: false,
        reason: 'No economic-calendar / macro provider configured. Rate differentials, CPI, NFP and FOMC analysis will remain disabled until one is added.',
      },
      currencyStrength: strength,
    };
  }

  /**
   * Currency strength from USD-pair momentum over the last ~24 bars.
   * Explicitly labeled `derivedFrom: 'price-momentum'`.
   */
  private currencyStrength(ctx: AnalysisContext, base: string, quote: string): ForexAgentReport['currencyStrength'] {
    const bars = Math.min(24, Math.floor(TIMEFRAME_MS[ctx.series.timeframe] ? 24 : 24));
    const contributions: Record<string, number[]> = {};

    const collect = (symbol: string, series: CandleSeries | undefined) => {
      if (!series || series.candles.length < 10) return;
      const cs = series.candles.slice(-bars);
      const ret = (cs[cs.length - 1].close - cs[0].close) / cs[0].close;
      const { base: b, quote: q } = splitPair(symbol);
      (contributions[b] ??= []).push(ret);
      (contributions[q] ??= []).push(-ret);
    };

    for (const ref of ctx.referenceSeries ?? []) collect(ref.symbol, ref.series);
    if (ctx.referenceSeries === undefined || ctx.referenceSeries.length === 0) {
      collect(ctx.series.symbol, ctx.series); // single-pair fallback
    }

    const scores = Object.entries(contributions)
      .map(([currency, rets]) => ({
        currency,
        score: Number((rets.reduce((a, b) => a + b, 0) / rets.length).toFixed(5)),
      }))
      .sort((a, b) => b.score - a.score);

    const synthetic = ctx.series.provenance.synthetic ||
      (ctx.referenceSeries ?? []).some((r) => r.series.provenance.synthetic);

    return {
      derivedFrom: 'price-momentum',
      synthetic,
      scores,
      strongest: scores[0]?.currency ?? null,
      weakest: scores.length ? scores[scores.length - 1].currency : null,
      note: `Computed from ${Object.keys(contributions).length ? USD_PAIR_MATRIX.length + '-pair' : 'available'} price momentum only — this is NOT news or fundamental data.`,
    };
  }

  private sessionInfo(now: number): ForexAgentReport['session'] {
    const h = new Date(now).getUTCHours();
    let name = 'Off-hours';
    if (h >= 0 && h < 7) name = 'Asia (Tokyo)';
    else if (h >= 7 && h < 12) name = 'London';
    else if (h >= 12 && h < 17) name = 'London/New York overlap';
    else if (h >= 17 && h < 21) name = 'New York';
    const active = name !== 'Off-hours';
    return {
      name,
      utcHour: h,
      active,
      note: active ? 'Session liquidity is generally available' : 'Thin liquidity — wider spreads and false moves are more likely',
    };
  }
}

const ECB_MINORS = new Set(['EURGBP', 'EURJPY', 'GBPJPY', 'AUDJPY', 'EURCHF', 'AUDNZD', 'EURAUD', 'CADJPY', 'CHFJPY']);
