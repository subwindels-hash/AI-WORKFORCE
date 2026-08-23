import type { CryptoAgentReport } from '../types';
import { ANALYSIS_DEFAULTS } from '../config/defaults';
import { lastOf, atr, ema, sma } from '../indicators/indicators';
import { dataQualityOf, makeVote, type AnalysisContext, type TradingAgent } from './base';

/**
 * Cryptocurrency Intelligence Agent.
 *
 * Real, data-grounded: multi-horizon price action, volume trend vs average,
 * and volatility regime from the actual candle series.
 *
 * Everything requiring a provider that is NOT configured (on-chain metrics,
 * funding rates, open interest, dominance, exchange flows, whale activity)
 * is reported as `dataAvailable: false` with an explicit warning — never
 * simulated (spec §2.3).
 */
export class CryptoAgent implements TradingAgent<CryptoAgentReport> {
  readonly id = 'crypto';
  readonly title = 'Cryptocurrency Intelligence Agent';
  readonly description =
    'Price action, volume and liquidity analysis from real candles. On-chain, derivatives and dominance data require dedicated providers (not configured in Phase 1) and are reported unavailable.';
  readonly marketClasses = ['crypto'];

  isApplicable(ctx: AnalysisContext): boolean {
    return ctx.series.marketClass === 'crypto';
  }

  async analyze(ctx: AnalysisContext): Promise<CryptoAgentReport> {
    const { candles } = ctx.series;
    const closes = candles.map((c) => c.close);
    const price = closes[closes.length - 1];

    const bars24 = Math.min(24, closes.length - 1);
    const bars7d = Math.min(168, closes.length - 1);
    const changePct24h = bars24 > 0 ? ((price - closes[closes.length - 1 - bars24]) / closes[closes.length - 1 - bars24]) * 100 : null;
    const changePct7d = bars7d > 0 ? ((price - closes[closes.length - 1 - bars7d]) / closes[closes.length - 1 - bars7d]) * 100 : null;

    const volumes = candles.map((c) => c.volume);
    const volAvg = lastOf(sma(volumes, 30));
    const latestVolume = volumes[volumes.length - 1];
    const hasVolume = candles.some((c) => c.volume > 0);
    const latestVsAverage = hasVolume && volAvg !== null && volAvg > 0 ? latestVolume / volAvg : null;

    const atr14 = lastOf(atr(candles, 14));
    const atrPct = atr14 !== null ? (atr14 / price) * 100 : null;
    const volLabel: CryptoAgentReport['volatility']['label'] =
      atrPct === null ? 'normal' : atrPct > 3.5 ? 'high' : atrPct < 0.8 ? 'low' : 'normal';

    const ema20 = lastOf(ema(closes, 20));
    const ema50 = lastOf(ema(closes, 50));
    const trendLabel =
      ema20 !== null && ema50 !== null ? (ema20 > ema50 ? 'short-term uptrend' : 'short-term downtrend') : 'undetermined';

    let score = 0;
    const reasons: string[] = [];
    if (changePct24h !== null) {
      score += Math.max(-0.35, Math.min(0.35, changePct24h / 6));
      reasons.push(`24h move ${changePct24h.toFixed(2)}%`);
    }
    if (changePct7d !== null) {
      score += Math.max(-0.25, Math.min(0.25, changePct7d / 15));
      reasons.push(`7d move ${changePct7d.toFixed(2)}%`);
    }
    if (ema20 !== null && ema50 !== null) {
      score += ema20 > ema50 ? 0.25 : -0.25;
      reasons.push(trendLabel);
    }
    if (latestVsAverage !== null) {
      if (latestVsAverage > 1.5 && changePct24h !== null && changePct24h > 0) { score += 0.15; reasons.push('volume expansion confirms buying'); }
      if (latestVsAverage > 1.5 && changePct24h !== null && changePct24h < 0) { score -= 0.15; reasons.push('volume expansion confirms selling'); }
    }

    const warnings: string[] = [];
    if (ctx.series.provenance.synthetic) warnings.push('Candles are SYNTHETIC — analysis is a simulation, not market reality');

    return {
      agent: 'crypto',
      title: this.title,
      generatedAt: ctx.now,
      dataQuality: dataQualityOf(ctx.series),
      dataLimitations: [
        'On-chain data: no provider configured',
        'Funding rates & open interest: no derivatives provider configured',
        'Market dominance: no aggregator configured',
        'Exchange flows / whale activity: no provider configured',
        ...(hasVolume ? [] : ['Provider supplies no volume data — volume analysis unavailable']),
      ],
      warnings,
      vote: makeVote(score, ANALYSIS_DEFAULTS.agentWeights.crypto, reasons.join('; ') || 'no decisive crypto edge'),
      priceAction: {
        changePct24h: changePct24h === null ? null : Number(changePct24h.toFixed(2)),
        changePct7d: changePct7d === null ? null : Number(changePct7d.toFixed(2)),
        trendLabel,
      },
      volume: {
        latestVsAverage: latestVsAverage === null ? null : Number(latestVsAverage.toFixed(2)),
        trendLabel: !hasVolume ? 'unavailable (no volume data)' : latestVsAverage === null ? 'undetermined' : latestVsAverage > 1.5 ? 'expansion' : latestVsAverage < 0.6 ? 'contraction' : 'average',
      },
      volatility: { atrPct: atrPct === null ? null : Number(atrPct.toFixed(2)), label: volLabel },
      onChain: {
        dataAvailable: false,
        warning: 'On-chain provider not configured',
      },
      derivatives: {
        dataAvailable: false,
        warning: 'Derivatives provider not configured — funding rates and open interest analysis disabled',
      },
      marketDominance: {
        dataAvailable: false,
        warning: 'Market-cap aggregator not configured — dominance analysis disabled',
      },
    };
  }
}
