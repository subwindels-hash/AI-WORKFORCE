import type { AgentReport, AgentVote, CandleSeries, SignalDirection } from '../types';

/**
 * Agent contract.
 *
 * RULE 1: agents analyze ONLY. They never receive a broker reference, never
 * place orders, and never mutate platform state. Their entire output is a
 * structured JSON report with an honest directional vote (or an abstention).
 */
export interface AnalysisContext {
  series: CandleSeries;
  /** Current UTC time (injectable for deterministic tests). */
  now: number;
  /** Cross-pair reference series for currency-strength computation (forex only). */
  referenceSeries?: { symbol: string; series: CandleSeries }[];
}

export interface TradingAgent<T extends AgentReport = AgentReport> {
  readonly id: string;
  readonly title: string;
  readonly description: string;
  /** Which market classes this agent applies to. */
  readonly marketClasses: string[];
  isApplicable(ctx: AnalysisContext): boolean;
  analyze(ctx: AnalysisContext): Promise<T>;
}

export function makeVote(
  directionalScore: number,
  weight: number,
  reason: string,
  voteThreshold = 0.15,
): AgentVote {
  const clamped = Math.max(-1, Math.min(1, directionalScore));
  const signal: SignalDirection = clamped > voteThreshold ? 'BUY' : clamped < -voteThreshold ? 'SELL' : 'NEUTRAL';
  return {
    directionalScore: clamped,
    signal,
    weight,
    votes: Math.abs(clamped) > voteThreshold,
    reason,
  };
}

export function dataQualityOf(series: CandleSeries): number {
  let q = 1;
  if (series.candles.length < 60) q *= 0.5;
  else if (series.candles.length < 120) q *= 0.8;
  if (series.provenance.synthetic) q *= 0.6;
  if (series.provenance.stale) q *= 0.7;
  if (series.validation.gapCount > series.candles.length * 0.1) q *= 0.8;
  return q;
}
