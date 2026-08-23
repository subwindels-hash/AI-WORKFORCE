import { randomUUID } from 'node:crypto';
import type {
  AgentReport, AnalysisRequest, AnalysisRun, Bias, CandleSeries, MarketClass,
  PortfolioState, RiskDecision, RiskLimits, SignalDirection, Timeframe, TradingMode,
} from '../types';
import { TIMEFRAMES } from '../types';
import { ANALYSIS_DEFAULTS, DEFAULT_KILL_SWITCH_ACTIVE, DEFAULT_RISK_LIMITS, DEFAULT_TRADING_MODE } from '../config/defaults';
import { ProviderManager } from '../marketdata/provider-manager';
import { TechnicalAgent } from '../agents/technical';
import { MarketStructureAgent } from '../agents/market-structure';
import { ForexAgent } from '../agents/forex';
import { CryptoAgent } from '../agents/crypto';
import { SentimentAgent } from '../agents/sentiment';
import { TradingIntelligenceAgent } from '../agents/intelligence';
import type { TradingAgent } from '../agents/base';
import { detectRegime, regimeDirectionality } from '../analysis/regime';
import { buildScenarios, generateSetup } from '../analysis/setup-generator';
import { RiskEngine } from '../risk/risk-engine';
import { EventBus } from '../events/events';
import { clamp } from '../utils/math';

export interface EngineDeps {
  providerManager: ProviderManager;
  riskEngine?: RiskEngine;
  eventBus?: EventBus;
  agents?: TradingAgent[];
  now?: () => number;
}

export interface ConsensusSummary {
  symbol: string;
  marketClass: MarketClass;
  timeframe: Timeframe;
  bias: Bias;
  recommendation: 'BUY' | 'SELL' | 'HOLD' | 'NO_TRADE';
  confidence: number;
  confluence: number;
  regime: string;
  synthetic: boolean;
  source: string;
  dataAgeMs: number;
}

/**
 * TRADING INTELLIGENCE ENGINE — the central pipeline (spec §3):
 *
 *   request data -> run applicable agents -> normalize results ->
 *   agreement/conflicts -> confluence -> confidence -> regime ->
 *   scenarios -> trade setup -> RISK ENGINE.
 *
 * In Phase 1 the pipeline terminates at the risk decision: the platform runs
 * in ANALYSIS_ONLY mode, nothing is ever routed to a broker (there is no
 * broker reference anywhere in this class — by design).
 */
export class TradingIntelligenceEngine {
  readonly platform = {
    tradingMode: DEFAULT_TRADING_MODE,
    killSwitch: {
      active: DEFAULT_KILL_SWITCH_ACTIVE,
      activatedAt: new Date().toISOString(),
      reason: 'Default state at boot — orders blocked until explicitly released',
    } as { active: boolean; activatedAt: string | null; reason: string | null },
  };

  private readonly agents: TradingAgent[];
  private readonly intelligence = new TradingIntelligenceAgent();
  private readonly runs = new Map<string, AnalysisRun>();
  private readonly now: () => number;

  constructor(private readonly deps: EngineDeps) {
    this.agents = deps.agents ?? [
      new TechnicalAgent(),
      new MarketStructureAgent(),
      new ForexAgent(),
      new CryptoAgent(),
      new SentimentAgent(),
    ];
    this.now = deps.now ?? (() => Date.now());
    deps.eventBus?.emit('SYSTEM_STARTED', 'Trading Intelligence Engine initialised (ANALYSIS_ONLY, Phase 1)', {
      agents: this.agents.map((a) => a.id),
      killSwitchActive: this.platform.killSwitch.active,
    });
  }

  get riskEngine(): RiskEngine {
    return this.deps.riskEngine ?? (this.deps.riskEngine = new RiskEngine());
  }

  get eventBus(): EventBus {
    return this.deps.eventBus ?? (this.deps.eventBus = new EventBus());
  }

  listAgents() {
    return this.agents.map((a) => ({
      id: a.id,
      title: a.title,
      description: a.description,
      marketClasses: a.marketClasses,
    }));
  }

  /** Run the full pipeline for one symbol. */
  async run(request: AnalysisRequest): Promise<AnalysisRun> {
    const startedAt = new Date(this.now()).toISOString();
    if (!TIMEFRAMES.includes(request.timeframe)) {
      throw new Error(`Unsupported timeframe ${request.timeframe}`);
    }

    // 1) Market data via the provider abstraction (never a concrete provider).
    const series = await this.deps.providerManager.getCandleSeries(
      request.symbol.toUpperCase(),
      request.marketClass,
      request.timeframe,
      ANALYSIS_DEFAULTS.candleLimit,
    );

    // 2) Reference series for forex currency strength (parallel, best-effort).
    const referenceSeries =
      request.marketClass === 'forex' || request.marketClass === 'commodity'
        ? await this.fetchReferenceSeries(request.timeframe)
        : undefined;

    // 3) Run applicable agents.
    const ctx = { series, now: this.now(), referenceSeries };
    const reports: AgentReport[] = [];
    for (const agent of this.agents) {
      if (!agent.isApplicable(ctx)) continue;
      try {
        reports.push(await agent.analyze(ctx));
      } catch (err) {
        this.eventBus.emit('TRADE_REJECTED', `Agent ${agent.id} failed`, {
          error: err instanceof Error ? err.message : String(err),
        });
      }
    }

    // 4-7) Consensus + regime.
    const regime = detectRegime(series);
    const freshnessFactor = series.provenance.stale ? 0.2 : series.provenance.synthetic ? 0.5 : 1.0;
    // Data quality is averaged over agents that actually had data; abstaining
    // agents (e.g. sentiment without providers) report quality 0 and must not
    // drag the pipeline into NO_TRADE territory.
    const withData = reports.filter((r) => r.dataQuality > 0);
    const dataQuality = withData.length > 0 ? withData.reduce((a, r) => a + r.dataQuality, 0) / withData.length : 0.5;
    const consensus = this.intelligence.combine(reports, {
      dataQuality,
      regimeClarity: regime.confidence * regimeDirectionality(regime.regime),
      freshnessFactor,
    });

    // 8) Scenarios.
    const technical = reports.find((r): r is Extract<AgentReport, { agent: 'technical' }> => r.agent === 'technical');
    const price = series.candles.length ? series.candles[series.candles.length - 1].close : 0;
    const scenarios = technical
      ? buildScenarios(series, technical, consensus.bias, price)
      : {
          bullish: { summary: 'insufficient data', triggers: [], targets: [], invalidation: '', probabilityHint: 'alternate' as const },
          bearish: { summary: 'insufficient data', triggers: [], targets: [], invalidation: '', probabilityHint: 'alternate' as const },
          neutral: { summary: 'insufficient data', triggers: [], targets: [], invalidation: '', probabilityHint: 'base' as const },
        };

    // 9) Trade setup proposal.
    const structure = reports.find((r): r is Extract<AgentReport, { agent: 'market-structure' }> => r.agent === 'market-structure');
    let tradeSetup = null as AnalysisRun['tradeSetup'];
    if (technical && structure && (consensus.bias === 'BULLISH' || consensus.bias === 'BEARISH')) {
      tradeSetup = generateSetup(series, technical, structure, consensus.bias, consensus.confidence);
    }

    // 10) Risk Engine — mandatory pass-through, veto power (Rule 6).
    let riskDecision: RiskDecision | null = null;
    if (tradeSetup) {
      riskDecision = this.riskEngine.evaluate(tradeSetup, {
        killSwitchActive: this.platform.killSwitch.active,
        dataQuality,
        syntheticData: series.provenance.synthetic,
        staleData: series.provenance.stale,
      });
    }

    const run: AnalysisRun = {
      id: randomUUID(),
      request,
      startedAt,
      completedAt: new Date(this.now()).toISOString(),
      symbol: series.symbol,
      timeframe: request.timeframe,
      marketRegime: regime.regime,
      regimeAssessment: regime,
      bias: consensus.bias,
      confidence: consensus.confidence,
      confluence: consensus.confluenceScore,
      recommendation: consensus.recommendation,
      reasoning: consensus.reasoning,
      conflicts: consensus.consensus.conflicts,
      consensus: consensus.consensus,
      signals: technical ? technical.signals : [],
      scenarios,
      tradeSetup,
      riskDecision,
      agents: reports,
      provenance: series.provenance,
      validation: series.validation,
      quote: null,
    };

    try {
      const q = await this.deps.providerManager.getQuote(series.symbol);
      run.quote = q.quote;
    } catch {
      run.quote = null; // quote is optional for the analysis run
    }

    this.remember(run);

    this.eventBus.emit('TRADE_ANALYZED', `${run.symbol} ${run.timeframe}: ${run.bias} @ ${run.confidence.toFixed(2)} confidence`, {
      runId: run.id,
      regime: run.marketRegime,
      source: run.provenance.source,
      synthetic: run.provenance.synthetic,
    });
    if (tradeSetup) {
      this.eventBus.emit('SIGNAL_GENERATED', `${run.symbol} ${tradeSetup.action} setup proposed (R:R ${tradeSetup.riskReward})`, {
        runId: run.id,
        entry: tradeSetup.entry,
        stopLoss: tradeSetup.stopLoss,
        takeProfit: tradeSetup.takeProfit,
      });
      if (riskDecision) {
        this.eventBus.emit(
          riskDecision.approved ? 'RISK_APPROVED' : 'RISK_REJECTED',
          `${run.symbol} setup ${riskDecision.approved ? 'approved' : 'rejected'} by Risk Engine`,
          { runId: run.id, reasons: riskDecision.reasons },
        );
      }
    } else {
      this.eventBus.emit('NO_SIGNAL', `${run.symbol} ${run.timeframe}: no tradeable setup`, { runId: run.id, bias: run.bias });
    }

    return run;
  }

  /** Quick multi-symbol consensus (dashboard watchlist). */
  async consensus(
    requests: AnalysisRequest[],
    opts: { signalTimeoutMs?: number } = {},
  ): Promise<ConsensusSummary[]> {
    const out: ConsensusSummary[] = [];
    const timeout = opts.signalTimeoutMs ?? 20_000;
    await Promise.all(
      requests.map(async (req) => {
        try {
          const runP = this.run(req);
          const run = await Promise.race([
            runP,
            new Promise<null>((resolve) => setTimeout(() => resolve(null), timeout)),
          ]);
          if (!run) {
            out.push(this.errorSummary(req, 'analysis timed out'));
            return;
          }
          out.push({
            symbol: run.symbol,
            marketClass: req.marketClass,
            timeframe: run.timeframe,
            bias: run.bias,
            recommendation: run.recommendation,
            confidence: run.confidence,
            confluence: run.confluence,
            regime: run.marketRegime,
            synthetic: run.provenance.synthetic,
            source: run.provenance.source,
            dataAgeMs: run.provenance.dataAgeMs,
          });
        } catch (err) {
          out.push(this.errorSummary(req, err instanceof Error ? err.message : String(err)));
        }
      }),
    );
    // Preserve request order.
    return requests
      .map((r) => out.find((s) => s.symbol === r.symbol.toUpperCase() && s.timeframe === r.timeframe))
      .filter((s): s is ConsensusSummary => Boolean(s));
  }

  history(limit = 20): AnalysisRun[] {
    return [...this.remembered()].slice(0, limit);
  }

  getRun(id: string): AnalysisRun | undefined {
    return this.runs.get(id);
  }

  setTradingMode(mode: TradingMode, actor: 'user' | 'system' = 'user'): { ok: boolean; message: string } {
    const implemented: TradingMode[] = ['ANALYSIS_ONLY'];
    if (!implemented.includes(mode)) {
      return {
        ok: false,
        message: `Mode ${mode} is not implemented in Phase 1. Implemented modes: ${implemented.join(', ')}. PAPER_TRADING arrives in Phase 3, execution modes in Phase 5.`,
      };
    }
    const previous = this.platform.tradingMode;
    this.platform.tradingMode = mode;
    this.eventBus.emit('TRADING_MODE_CHANGED', `Trading mode ${previous} -> ${mode}`, { actor, previous, mode });
    return { ok: true, message: `Trading mode set to ${mode}` };
  }

  setKillSwitch(active: boolean, reason: string | null, actor: 'user' | 'system' = 'user'): void {
    this.platform.killSwitch = {
      active,
      activatedAt: new Date().toISOString(),
      reason,
    };
    this.eventBus.emit(
      active ? 'KILL_SWITCH_ACTIVATED' : 'KILL_SWITCH_DEACTIVATED',
      `Kill switch ${active ? 'ACTIVATED' : 'deactivated'}${reason ? `: ${reason}` : ''}`,
      { actor },
    );
  }

  updateRiskLimits(patch: Partial<RiskLimits>): RiskLimits {
    const limits = this.riskEngine.updateLimits(patch);
    this.eventBus.emit('RISK_LIMITS_UPDATED', 'Risk limits updated', { limits, actor: 'user' });
    return limits;
  }

  portfolio(): PortfolioState {
    return this.riskEngine.getPortfolio();
  }

  private remembered(): AnalysisRun[] {
    return [...this.runs.values()].reverse();
  }

  private remember(run: AnalysisRun): void {
    this.runs.set(run.id, run);
    if (this.runs.size > 200) {
      const oldest = this.runs.keys().next().value;
      if (oldest) this.runs.delete(oldest);
    }
  }

  private async fetchReferenceSeries(timeframe: Timeframe) {
    const symbols = ['EURUSD', 'GBPUSD', 'USDJPY', 'AUDUSD', 'USDCAD', 'USDCHF', 'NZDUSD'];
    const results = await Promise.allSettled(
      symbols.map((s) =>
        this.deps.providerManager.getCandleSeries(s, 'forex', timeframe, 60),
      ),
    );
    return results
      .filter((r): r is PromiseFulfilledResult<CandleSeries> => r.status === 'fulfilled')
      .map((r) => ({ symbol: r.value.symbol, series: r.value }));
  }

  private errorSummary(req: AnalysisRequest, message: string): ConsensusSummary {
    return {
      symbol: req.symbol.toUpperCase(),
      marketClass: req.marketClass,
      timeframe: req.timeframe,
      bias: 'NO_TRADE',
      recommendation: 'NO_TRADE',
      confidence: 0,
      confluence: 0,
      regime: 'UNKNOWN',
      synthetic: false,
      source: `error: ${message}`,
      dataAgeMs: 0,
    };
  }
}

export type { SignalDirection };
