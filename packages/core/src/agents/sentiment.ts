import type { SentimentAgentReport } from '../types';
import { ANALYSIS_DEFAULTS } from '../config/defaults';
import { makeVote, type AnalysisContext, type TradingAgent } from './base';

/**
 * Sentiment Analysis Agent — Phase 1.
 *
 * Spec §11: if no real news/social provider is configured, the agent must
 * clearly state that sentiment is unavailable and must NOT disguise
 * price/volume proxies as sentiment. So this agent abstains from voting.
 *
 * When a news/social provider is added (Phase 6), this implementation is
 * replaced by a real one — the report shape already carries the fields.
 */
export class SentimentAgent implements TradingAgent<SentimentAgentReport> {
  readonly id = 'sentiment';
  readonly title = 'Sentiment Analysis Agent';
  readonly description =
    'News and social sentiment. DISABLED until a real news/social provider is configured — reports explicit unavailability and abstains from the consensus vote.';
  readonly marketClasses = ['*'];

  isApplicable(): boolean {
    return true;
  }

  async analyze(ctx: AnalysisContext): Promise<SentimentAgentReport> {
    return {
      agent: 'sentiment',
      title: this.title,
      generatedAt: ctx.now,
      dataQuality: 0,
      dataLimitations: [
        'No financial-news provider configured',
        'No social-sentiment provider configured',
      ],
      warnings: ['Sentiment unavailable — consensus is computed without any sentiment input'],
      vote: {
        directionalScore: 0,
        signal: 'NEUTRAL',
        weight: ANALYSIS_DEFAULTS.agentWeights.sentiment,
        votes: false, // ABSTAINS — no data, no vote
        reason: 'No sentiment providers configured — abstaining',
      },
      news: { available: false, reason: 'No financial-news API configured (Phase 6)' },
      social: { available: false, reason: 'No social-sentiment API configured (Phase 6)' },
      note: 'Price/volume proxies are handled by the Technical Agent and are deliberately NOT presented as sentiment.',
    };
  }
}
