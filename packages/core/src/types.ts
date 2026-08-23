/**
 * AEGIS — Core domain types.
 *
 * These types are the single source of truth for the whole platform.
 * Downstream layers (agents, engine, risk, API, dashboard) must depend on
 * these types — never on a concrete provider or broker.
 */

// ---------------------------------------------------------------------------
// Markets
// ---------------------------------------------------------------------------

export type MarketClass =
  | 'forex'
  | 'crypto'
  | 'stock'
  | 'etf'
  | 'commodity'
  | 'futures'
  | 'options'
  | 'indices'
  | 'bonds';

export const TIMEFRAMES = ['1m', '5m', '15m', '1h', '4h', '1d'] as const;
export type Timeframe = (typeof TIMEFRAMES)[number];

export const TIMEFRAME_MS: Record<Timeframe, number> = {
  '1m': 60_000,
  '5m': 300_000,
  '15m': 900_000,
  '1h': 3_600_000,
  '4h': 14_400_000,
  '1d': 86_400_000,
};

/** A data point is considered stale if the newest candle is older than this. */
export const STALENESS_MS: Record<Timeframe, number> = {
  '1m': 5 * 60_000,
  '5m': 15 * 60_000,
  '15m': 45 * 60_000,
  '1h': 3 * 3_600_000,
  '4h': 12 * 3_600_000,
  // Daily ECB reference rates publish once per business day; allow weekends + holidays.
  '1d': 4 * 86_400_000,
};

export interface Candle {
  /** UTC epoch milliseconds of the candle OPEN time. */
  timestamp: number;
  open: number;
  high: number;
  low: number;
  close: number;
  volume: number;
}

export interface Quote {
  symbol: string;
  bid?: number;
  ask?: number;
  last: number;
  /** UTC epoch milliseconds of the underlying data point. */
  timestamp: number;
}

// ---------------------------------------------------------------------------
// Data provenance — Rule 2: synthetic data must never be silent.
// ---------------------------------------------------------------------------

export interface DataProvenance {
  /** Provider that actually served the data (e.g. "binance", "synthetic-demo"). */
  source: string;
  /** True when data was generated, not observed from a market. */
  synthetic: boolean;
  /** True when data comes from a live/exchange feed. */
  live: boolean;
  /** True when the provider explicitly marks its feed as delayed. */
  delayed: boolean;
  /** UTC epoch ms when the platform fetched the data. */
  fetchedAt: number;
  /** UTC epoch ms of the newest underlying data point. */
  dataTimestamp: number;
  dataAgeMs: number;
  /** True when dataTimestamp is older than the staleness threshold for the timeframe. */
  stale: boolean;
  /** Attempted providers that failed before this source answered. */
  fallbackChain: string[];
}

export interface CandleValidation {
  ok: boolean;
  droppedCount: number;
  gapCount: number;
  expectedIntervalMs: number;
  coveredIntervalMs: number;
  minTimestamp: number;
  maxTimestamp: number;
  issues: string[];
}

export interface CandleSeries {
  symbol: string;
  marketClass: MarketClass;
  timeframe: Timeframe;
  candles: Candle[];
  provenance: DataProvenance;
  validation: CandleValidation;
}

// ---------------------------------------------------------------------------
// Market data providers
// ---------------------------------------------------------------------------

export interface CandleRequest {
  symbol: string;
  timeframe: Timeframe;
  limit: number;
}

export type ProviderStatus = 'UP' | 'DEGRADED' | 'DOWN' | 'UNKNOWN';

export interface ProviderHealth {
  name: string;
  status: ProviderStatus;
  synthetic: boolean;
  latencyMs?: number;
  lastCheckAt: number;
  lastError?: string;
  detail?: string;
  circuitState?: 'CLOSED' | 'OPEN' | 'HALF_OPEN';
}

export interface ProviderCapabilities {
  marketClasses: MarketClass[];
  timeframes: Timeframe[];
  /** Whether the provider explicitly flags delayed data. */
  delayed: boolean;
  notes: string;
}

/**
 * Universal market-data abstraction. The rest of the system must never
 * depend on a concrete provider implementation.
 */
export interface MarketDataProvider {
  readonly name: string;
  readonly synthetic: boolean;
  readonly priority: number; // lower = preferred

  supportsSymbol(symbol: string): boolean;
  supportsTimeframe(symbol: string, timeframe: Timeframe): boolean;
  getCandles(req: CandleRequest): Promise<Candle[]>;
  getQuote(symbol: string): Promise<Quote>;
  healthCheck(): Promise<ProviderHealth>;
  capabilities(): ProviderCapabilities;
}

// ---------------------------------------------------------------------------
// Agents
// ---------------------------------------------------------------------------

export type Bias = 'BULLISH' | 'BEARISH' | 'NEUTRAL' | 'NO_TRADE';
export type SignalDirection = 'BUY' | 'SELL' | 'NEUTRAL';

/**
 * Every agent must report a directional score in [-1, 1] (bearish..bullish),
 * a weight for consensus, and an honest assessment of its own data quality.
 * Agents NEVER place orders (Rule 1).
 */
export interface AgentReportMeta {
  agent: string;
  title: string;
  /** UTC epoch ms when the report was produced. */
  generatedAt: number;
  /** 0..1 — how much of this agent's ideal input data was actually available. */
  dataQuality: number;
  /** Honest list of data the agent did NOT have. */
  dataLimitations: string[];
  warnings: string[];
}

export interface AgentVote {
  /** Directional opinion in [-1, 1]. */
  directionalScore: number;
  signal: SignalDirection;
  /** Relative weight in the consensus computation. */
  weight: number;
  /** False when the agent abstains (e.g. no data) — abstains do not vote. */
  votes: boolean;
  reason: string;
}

export interface TechnicalSignal {
  name: string;
  value: number | null;
  signal: SignalDirection;
  detail: string;
}

export interface TechnicalAgentReport extends AgentReportMeta {
  agent: 'technical';
  vote: AgentVote;
  indicators: {
    sma20: number | null;
    sma50: number | null;
    sma200: number | null;
    ema20: number | null;
    ema50: number | null;
    rsi14: number | null;
    macd: { macd: number | null; signal: number | null; histogram: number | null } | null;
    macdBias: SignalDirection;
    bollinger: { upper: number | null; mid: number | null; lower: number | null; bandwidthPct: number | null } | null;
    atr14: number | null;
    atrPct: number | null;
    adx14: { adx: number | null; plusDi: number | null; minusDi: number | null } | null;
    vwap: number | null;
    stochastic: { k: number | null; d: number | null } | null;
  };
  structure: {
    trend: 'up' | 'down' | 'sideways';
    trendStrength: number; // 0..1
    momentum: SignalDirection;
    support: number[];
    resistance: number[];
    pivots: { p: number | null; r1: number | null; r2: number | null; r3: number | null; s1: number | null; s2: number | null; s3: number | null };
    volumeProfile: { poc: number | null; valueAreaHigh: number | null; valueAreaLow: number | null };
  };
  signals: TechnicalSignal[];
  aggregateScore: number; // -1..1
}

export interface MarketStructureAgentReport extends AgentReportMeta {
  agent: 'market-structure';
  vote: AgentVote;
  swingSequence: ('HH' | 'HL' | 'LH' | 'LL')[];
  trendLabel: 'uptrend' | 'downtrend' | 'range';
  events: {
    breakOfStructure: { detected: boolean; direction: SignalDirection; level: number | null; confirmedBy: 'CLOSE' | 'WICK' | 'NONE'; barsAgo: number | null };
    changeOfCharacter: { detected: boolean; direction: SignalDirection; level: number | null; confirmedBy: 'CLOSE' | 'WICK' | 'NONE' };
  };
  liquidityZones: { type: 'buy-side' | 'sell-side'; price: number; formedAt: number }[];
  supplyZones: { min: number; max: number; formedAt: number }[];
  demandZones: { min: number; max: number; formedAt: number }[];
  orderBlocks: { side: 'bullish' | 'bearish'; min: number; max: number; formedAt: number }[];
  fairValueGaps: { direction: 'bullish' | 'bearish'; min: number; max: number; formedAt: number }[];
}

export interface ForexAgentReport extends AgentReportMeta {
  agent: 'forex';
  vote: AgentVote;
  pair: {
    symbol: string;
    base: string;
    quote: string;
    classification: 'major' | 'minor' | 'exotic' | 'other';
  };
  volatility: { atrPct: number | null; label: 'low' | 'normal' | 'high' };
  trendAlignment: { emaFastAboveSlow: boolean | null; detail: string };
  session: { name: string; utcHour: number; active: boolean; note: string };
  /** Rate differential & calendar REQUIRE a macro provider; none is configured in Phase 1. */
  macro: {
    available: false;
    reason: string;
  };
  currencyStrength: {
    /** Price-momentum derived — explicitly NOT news/fundamental data. */
    derivedFrom: 'price-momentum';
    synthetic: boolean;
    scores: { currency: string; score: number }[];
    strongest: string | null;
    weakest: string | null;
    note: string;
  };
}

export interface CryptoAgentReport extends AgentReportMeta {
  agent: 'crypto';
  vote: AgentVote;
  priceAction: { changePct24h: number | null; changePct7d: number | null; trendLabel: string };
  volume: { latestVsAverage: number | null; trendLabel: string };
  volatility: { atrPct: number | null; label: 'low' | 'normal' | 'high' };
  onChain: {
    dataAvailable: false;
    warning: string;
  };
  derivatives: {
    dataAvailable: false;
    warning: string;
  };
  marketDominance: {
    dataAvailable: false;
    warning: string;
  };
}

export interface SentimentAgentReport extends AgentReportMeta {
  agent: 'sentiment';
  vote: AgentVote;
  news: { available: false; reason: string };
  social: { available: false; reason: string };
  /** Explicitly NOT used as a sentiment proxy. Price-derived signals belong to the technical agent. */
  note: string;
}

export type AgentReport =
  | TechnicalAgentReport
  | MarketStructureAgentReport
  | ForexAgentReport
  | CryptoAgentReport
  | SentimentAgentReport;

// ---------------------------------------------------------------------------
// Intelligence engine output
// ---------------------------------------------------------------------------

export type MarketRegime =
  | 'TRENDING_UP'
  | 'TRENDING_DOWN'
  | 'RANGING'
  | 'HIGH_VOLATILITY'
  | 'LOW_VOLATILITY'
  | 'BREAKOUT'
  | 'UNKNOWN';

export interface RegimeAssessment {
  regime: MarketRegime;
  confidence: number; // 0..1
  evidence: string[];
  volatilityPct: number | null;
  adx: number | null;
}

export interface ConsensusDetail {
  netScore: number; // -1..1 weighted
  agreement: number; // 0..1 weighted fraction of voting agents aligned with net bias
  votingAgents: string[];
  abstainingAgents: string[];
  conflicts: { agent: string; theirBias: SignalDirection; reason: string }[];
}

export interface Scenario {
  summary: string;
  triggers: string[];
  targets: number[];
  invalidation: string;
  probabilityHint: 'primary' | 'alternate' | 'base';
}

export type TradeAction = 'BUY' | 'SELL';

export interface TradeSetup {
  action: TradeAction;
  symbol: string;
  marketClass: MarketClass;
  timeframe: Timeframe;
  entry: { type: 'ZONE'; min: number; max: number; reference: number };
  stopLoss: number;
  takeProfit: number[];
  riskReward: number;
  confidence: number;
  expiration: string; // ISO-8601 UTC
  invalidationReasons: string[];
  rationale: string[];
}

export interface AnalysisRequest {
  symbol: string;
  marketClass: MarketClass;
  timeframe: Timeframe;
}

export interface AnalysisRun {
  id: string;
  request: AnalysisRequest;
  startedAt: string;
  completedAt: string;
  symbol: string;
  timeframe: Timeframe;
  marketRegime: MarketRegime;
  regimeAssessment: RegimeAssessment;
  bias: Bias;
  confidence: number;
  confluence: number;
  recommendation: 'BUY' | 'SELL' | 'HOLD' | 'NO_TRADE';
  reasoning: string[];
  conflicts: ConsensusDetail['conflicts'];
  consensus: ConsensusDetail;
  signals: TechnicalSignal[];
  scenarios: { bullish: Scenario; bearish: Scenario; neutral: Scenario };
  tradeSetup: TradeSetup | null;
  riskDecision: RiskDecision | null;
  agents: AgentReport[];
  provenance: DataProvenance;
  validation: CandleValidation;
  quote: Quote | null;
}

// ---------------------------------------------------------------------------
// Risk engine
// ---------------------------------------------------------------------------

export interface RiskLimits {
  /** Fraction of equity risked per trade, e.g. 0.01 = 1%. */
  riskPerTradePct: number;
  /** Hard ceiling regardless of configuration elsewhere. */
  maxRiskPerTradePct: number;
  minRiskReward: number;
  requireStopLoss: boolean;
  maxPositionNotionalUsd: number;
  maxLeverage: number;
  maxOpenPositions: number;
  maxDailyLossPct: number;
  maxWeeklyLossPct: number;
  maxDrawdownPct: number;
  /** Exposure limits are CAPITAL-AT-RISK based (stop-distance basis), not notional. */
  maxSymbolExposurePct: number;
  maxPortfolioExposurePct: number;
  maxCorrelatedPositions: number;
  /** Minimum data quality (0..1) below which trades are refused. */
  minDataQuality: number;
  /** Refuse trades built on synthetic or stale data. */
  blockSyntheticData: boolean;
  blockStaleData: boolean;
}

export interface PortfolioState {
  /** Phase 1 has no broker; this is the paper-portfolio baseline (no fake P&L is reported). */
  equity: number;
  cash: number;
  openPositions: number;
  /** Open CAPITAL AT RISK per symbol (entry-stop distance × units), NOT notional. */
  openRiskBySymbol: Record<string, number>;
  dailyPnl: number;
  weeklyPnl: number;
  peakEquity: number;
}

export interface RiskDecision {
  approved: boolean;
  checkedAt: string;
  reasons: string[];
  warnings: string[];
  sizing: {
    equity: number;
    riskAmount: number;
    riskPct: number;
    entryReference: number;
    stopDistance: number;
    /** Units = riskAmount / stopDistance. Contract/pip conversion arrives with broker adapters (Phase 4). */
    units: number | null;
    notionalUsd: number | null;
    impliedLeverage: number | null;
  } | null;
}

// ---------------------------------------------------------------------------
// Platform state & events
// ---------------------------------------------------------------------------

export type TradingMode =
  | 'ANALYSIS_ONLY'
  | 'PAPER_TRADING'
  | 'HUMAN_APPROVAL'
  | 'SEMI_AUTONOMOUS'
  | 'FULLY_AUTOMATED';

export const TRADING_MODES: TradingMode[] = [
  'ANALYSIS_ONLY',
  'PAPER_TRADING',
  'HUMAN_APPROVAL',
  'SEMI_AUTONOMOUS',
  'FULLY_AUTOMATED',
];

/** Modes that are actually implemented in the current build. */
export const IMPLEMENTED_TRADING_MODES: TradingMode[] = ['ANALYSIS_ONLY'];

export interface PlatformState {
  tradingMode: TradingMode;
  killSwitch: {
    /** active = all order placement is blocked everywhere (Rule 7). */
    active: boolean;
    activatedAt: string | null;
    reason: string | null;
  };
  phase: 2;
  buildInfo: { version: string; phaseName: string };
}

export type PlatformEvent =
  | 'TRADE_ANALYZED'
  | 'SIGNAL_GENERATED'
  | 'NO_SIGNAL'
  | 'TRADE_REJECTED'
  | 'RISK_APPROVED'
  | 'RISK_REJECTED'
  | 'KILL_SWITCH_ACTIVATED'
  | 'KILL_SWITCH_DEACTIVATED'
  | 'TRADING_MODE_CHANGED'
  | 'RISK_LIMITS_UPDATED'
  | 'PROVIDER_FALLBACK'
  | 'SYSTEM_STARTED'
  | 'STRATEGY_REGISTERED'
  | 'STRATEGY_STATUS_CHANGED'
  | 'BACKTEST_STARTED'
  | 'BACKTEST_COMPLETED'
  | 'JOURNAL_ENTRY_RECORDED';

export interface AuditEvent {
  id: string;
  type: PlatformEvent;
  at: string;
  actor: 'system' | 'user';
  summary: string;
  detail?: Record<string, unknown>;
}

/** Integration honesty matrix (Rule 3). */
export type IntegrationStatus =
  | 'IMPLEMENTED'
  | 'TESTED'
  | 'PAPER_TRADING_READY'
  | 'LIVE_READY'
  | 'PLANNED'
  | 'DISABLED';

export interface IntegrationStatusEntry {
  name: string;
  category: 'market-data' | 'agent' | 'engine' | 'broker' | 'exchange' | 'module' | 'mode';
  status: IntegrationStatus;
  detail: string;
}
