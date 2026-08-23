// Client-side mirror of the AEGIS core contract (packages/core/src/types.ts).
// Kept hand-written intentionally: the dashboard consumes the REST API only
// and must not depend on server internals.

export type Bias = 'BULLISH' | 'BEARISH' | 'NEUTRAL' | 'NO_TRADE';
export type SignalDirection = 'BUY' | 'SELL' | 'NEUTRAL';
export type MarketRegime =
  | 'TRENDING_UP' | 'TRENDING_DOWN' | 'RANGING'
  | 'HIGH_VOLATILITY' | 'LOW_VOLATILITY' | 'BREAKOUT' | 'UNKNOWN';

export interface Candle {
  timestamp: number;
  open: number;
  high: number;
  low: number;
  close: number;
  volume: number;
}

export interface DataProvenance {
  source: string;
  synthetic: boolean;
  live: boolean;
  delayed: boolean;
  fetchedAt: number;
  dataTimestamp: number;
  dataAgeMs: number;
  stale: boolean;
  fallbackChain: string[];
}

export interface TechnicalSignal {
  name: string;
  value: number | null;
  signal: SignalDirection;
  detail: string;
}

export interface Scenario {
  summary: string;
  triggers: string[];
  targets: number[];
  invalidation: string;
  probabilityHint: 'primary' | 'alternate' | 'base';
}

export interface TradeSetup {
  action: 'BUY' | 'SELL';
  symbol: string;
  marketClass: string;
  timeframe: string;
  entry: { type: 'ZONE'; min: number; max: number; reference: number };
  stopLoss: number;
  takeProfit: number[];
  riskReward: number;
  confidence: number;
  expiration: string;
  invalidationReasons: string[];
  rationale: string[];
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
    units: number | null;
    notionalUsd: number | null;
    impliedLeverage: number | null;
  } | null;
}

export interface AgentVote {
  directionalScore: number;
  signal: SignalDirection;
  weight: number;
  votes: boolean;
  reason: string;
}

export interface AgentReport {
  agent: string;
  title: string;
  generatedAt: number;
  dataQuality: number;
  dataLimitations: string[];
  warnings: string[];
  vote: AgentVote;
  [key: string]: unknown;
}

export interface TechnicalAgentReport extends AgentReport {
  agent: 'technical';
  indicators: Record<string, unknown>;
  structure: {
    trend: string;
    trendStrength: number;
    momentum: SignalDirection;
    support: number[];
    resistance: number[];
    volumeProfile: { poc: number | null; valueAreaHigh: number | null; valueAreaLow: number | null };
  };
  signals: TechnicalSignal[];
  aggregateScore: number;
}

export interface AnalysisRun {
  id: string;
  symbol: string;
  timeframe: string;
  marketRegime: MarketRegime;
  regimeAssessment: { regime: MarketRegime; confidence: number; evidence: string[]; volatilityPct: number | null; adx: number | null };
  bias: Bias;
  confidence: number;
  confluence: number;
  recommendation: 'BUY' | 'SELL' | 'HOLD' | 'NO_TRADE';
  reasoning: string[];
  conflicts: { agent: string; theirBias: string; reason: string }[];
  consensus: {
    netScore: number;
    agreement: number;
    votingAgents: string[];
    abstainingAgents: string[];
    conflicts: { agent: string; theirBias: string; reason: string }[];
  };
  signals: TechnicalSignal[];
  scenarios: { bullish: Scenario; bearish: Scenario; neutral: Scenario };
  tradeSetup: TradeSetup | null;
  riskDecision: RiskDecision | null;
  agents: AgentReport[];
  provenance: DataProvenance;
  quote: { symbol: string; last: number; bid?: number; ask?: number; timestamp: number } | null;
}

export interface SystemStatus {
  platform: string;
  phase: number;
  tradingMode: string;
  implementedTradingModes: string[];
  supportedTradingModes: string[];
  killSwitch: { active: boolean; activatedAt: string | null; reason: string | null };
  providers: { name: string; status: string; synthetic: boolean; latencyMs?: number; lastError?: string; detail?: string; circuitState?: string }[];
  cache: { size: number; hits: number; misses: number };
  time: string;
}

export interface ConsensusSummary {
  symbol: string;
  bias: Bias;
  recommendation: string;
  confidence: number;
  confluence: number;
  regime: string;
  synthetic: boolean;
  source: string;
}

export interface IntegrationStatusEntry {
  name: string;
  category: string;
  status: string;
  detail: string;
}

export interface AuditEvent {
  id: string;
  type: string;
  at: string;
  actor: string;
  summary: string;
}

export interface RiskLimitsView {
  limits: Record<string, number | boolean>;
  portfolio: { equity: number; cash: number; openPositions: number; dailyPnl: number; weeklyPnl: number; peakEquity: number };
}
