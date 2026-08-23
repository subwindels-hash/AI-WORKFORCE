import type { AgentReport, Bias, ConsensusDetail, SignalDirection } from '../types';
import { ANALYSIS_DEFAULTS } from '../config/defaults';
import { clamp } from '../utils/math';

export interface ConsensusResult {
  bias: Bias;
  confidence: number;
  confluenceScore: number;
  recommendation: 'BUY' | 'SELL' | 'HOLD' | 'NO_TRADE';
  reasoning: string[];
  consensus: ConsensusDetail;
}

/**
 * TRADING INTELLIGENCE AGENT — the orchestrating mind (spec §2.1).
 *
 * Collects every agent report, compares bullish vs bearish evidence,
 * computes the weighted confluence and the final confidence, detects
 * conflicting signals, and decides whether there is enough evidence to
 * propose a trade. It returns BULLISH / BEARISH / NEUTRAL / NO_TRADE —
 * and it never touches a broker (Rule 1).
 */
export class TradingIntelligenceAgent {
  readonly id = 'intelligence';
  readonly title = 'Trading Intelligence Agent';

  constructor(
    private readonly opts: {
      biasThreshold?: number;
      voteThreshold?: number;
      minConfidenceForRecommendation?: number;
    } = {},
  ) {}

  combine(reports: AgentReport[], input: { dataQuality: number; regimeClarity: number; freshnessFactor: number }): ConsensusResult {
    const voteThreshold = this.opts.voteThreshold ?? ANALYSIS_DEFAULTS.voteThreshold;
    const biasThreshold = this.opts.biasThreshold ?? ANALYSIS_DEFAULTS.biasThreshold;

    const voting = reports.filter((r) => r.vote.votes);
    const abstaining = reports.filter((r) => !r.vote.votes).map((r) => r.agent);

    // --- Weighted net score ---------------------------------------------------
    let wsum = 0;
    let acc = 0;
    for (const r of voting) {
      const w = r.vote.weight * r.dataQuality;
      acc += r.vote.directionalScore * w;
      wsum += w;
    }
    const netScore = wsum > 0 ? acc / wsum : 0;

    // --- Agreement / confluence -------------------------------------------------
    const netSign = Math.sign(netScore);
    let agreeW = 0;
    let totalW = 0;
    const conflicts: ConsensusDetail['conflicts'] = [];
    for (const r of voting) {
      const w = r.vote.weight * r.dataQuality;
      totalW += w;
      const rSign = Math.sign(r.vote.directionalScore);
      if (rSign === netSign && netSign !== 0) {
        agreeW += w;
      } else if (rSign !== 0 && netSign !== 0) {
        conflicts.push({
          agent: r.agent,
          theirBias: r.vote.signal,
          reason: r.vote.reason,
        });
      }
    }
    const agreement = totalW > 0 ? agreeW / totalW : 0;
    const confluenceScore = clamp(agreement * (0.5 + 0.5 * Math.abs(netScore)), 0, 1);

    // --- Bias -------------------------------------------------------------------
    let bias: Bias;
    if (voting.length === 0) {
      bias = 'NO_TRADE';
    } else if (Math.abs(netScore) < biasThreshold) {
      bias = 'NEUTRAL';
    } else {
      bias = netScore > 0 ? 'BULLISH' : 'BEARISH';
    }

    // --- Confidence ---------------------------------------------------------------
    const avgDataQuality =
      voting.length > 0 ? voting.reduce((a, r) => a + r.dataQuality, 0) / voting.length : input.dataQuality;
    const confidence = clamp(
      confluenceScore * 0.45 +
        Math.abs(netScore) * 0.25 +
        input.regimeClarity * 0.15 +
        avgDataQuality * 0.1 +
        input.freshnessFactor * 0.05,
      0,
      1,
    );

    // --- NO_TRADE overrides ----------------------------------------------------------
    const hardBlocks: string[] = [];
    if (input.dataQuality < 0.5) hardBlocks.push('data quality too low');
    if (input.freshnessFactor < 0.3) hardBlocks.push('data not fresh enough to act on');
    if (hardBlocks.length > 0 && bias !== 'NEUTRAL') {
      bias = 'NO_TRADE';
    }

    const minConf = this.opts.minConfidenceForRecommendation ?? ANALYSIS_DEFAULTS.minSetupConfidence;
    let recommendation: ConsensusResult['recommendation'];
    if (bias === 'NO_TRADE') recommendation = 'NO_TRADE';
    else if (bias === 'NEUTRAL' || confidence < minConf) recommendation = 'HOLD';
    else recommendation = bias === 'BULLISH' ? 'BUY' : 'SELL';

    // --- Reasoning ---------------------------------------------------------------------
    const reasoning: string[] = [];
    for (const r of voting) {
      const dir = r.vote.directionalScore > 0 ? 'bullish' : 'bearish';
      reasoning.push(`${r.title}: ${dir} (${r.vote.directionalScore.toFixed(2)}) — ${r.vote.reason}`);
    }
    if (abstaining.length > 0) {
      reasoning.push(`Abstaining (no data): ${abstaining.join(', ')}`);
    }
    if (conflicts.length > 0) {
      reasoning.push(`Conflicts detected: ${conflicts.map((c) => `${c.agent} leans ${c.theirBias}`).join('; ')}`);
    }
    reasoning.push(
      `Confluence ${(confluenceScore * 100).toFixed(0)}% (agreement ${(agreement * 100).toFixed(0)}%, net score ${netScore.toFixed(2)})`,
    );

    return {
      bias,
      confidence: Number(confidence.toFixed(2)),
      confluenceScore: Number(confluenceScore.toFixed(2)),
      recommendation,
      reasoning,
      consensus: {
        netScore: Number(netScore.toFixed(3)),
        agreement: Number(agreement.toFixed(2)),
        votingAgents: voting.map((r) => r.agent),
        abstainingAgents: abstaining,
        conflicts,
      },
    };
  }
}

export function signalFromScore(score: number, threshold = 0.15): SignalDirection {
  if (score > threshold) return 'BUY';
  if (score < -threshold) return 'SELL';
  return 'NEUTRAL';
}
