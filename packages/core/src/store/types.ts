import type { MarketClass, Timeframe } from '../types';

// ---------------------------------------------------------------------------
// Strategy records
// ---------------------------------------------------------------------------

export type StrategySource = 'builtin' | 'user' | 'ai';
export type StrategyLifecycle =
  | 'DRAFT'
  | 'BACKTESTED'
  | 'VALIDATED'
  | 'RISK_REVIEWED'
  | 'PAPER_TRADING'   // Phase 3
  | 'APPROVED'        // live deployment — Phase 5
  | 'RETIRED';

export interface StrategyVersionRecord {
  strategyId: string;
  version: string;
  name: string;
  description: string;
  marketClasses: MarketClass[];
  timeframes: Timeframe[];
  params: Record<string, number | boolean | string>;
  source: StrategySource;
  lifecycle: StrategyLifecycle;
  createdAt: string;
  updatedAt: string;
  lifecycleHistory: { from: StrategyLifecycle | null; to: StrategyLifecycle; at: string; reason: string }[];
}

// ---------------------------------------------------------------------------
// Backtest records
// ---------------------------------------------------------------------------

export interface BacktestTradeRecord {
  direction: 'LONG' | 'SHORT';
  entryTime: string;
  exitTime: string;
  entryPrice: number;
  exitPrice: number;
  units: number;
  notional: number;
  riskAmount: number;
  stopLoss: number;
  takeProfit: number;
  fees: { entryFee: number; exitFee: number; spreadCost: number; slippageCost: number; totalCost: number };
  grossPnl: number;
  netPnl: number;
  returnPct: number; // net pnl / equity at entry
  rMultiple: number; // net pnl / riskAmount
  exitReason: 'SIGNAL' | 'STOP_LOSS' | 'TAKE_PROFIT' | 'TIME_STOP' | 'END_OF_DATA';
  barsHeld: number;
  signalReason: string;
  confidence: number; // strategy signal confidence 0..1
}

export interface BacktestMetrics {
  totalReturnPct: number;
  finalEquity: number;
  trades: number;
  winRate: number | null;
  lossRate: number | null;
  profitFactor: number | null;
  expectancyR: number | null;
  expectancyPnl: number | null;
  avgWin: number | null;
  avgLoss: number | null;
  avgTrade: number | null;
  sharpe: number | null;
  sortino: number | null;
  maxDrawdownPct: number;
  maxDrawdownAbs: number;
  longestWinStreak: number;
  longestLossStreak: number;
  exposurePct: number;
  totalFees: number;
  totalSlippage: number;
}

export interface BacktestRecord {
  id: string;
  createdAt: string;
  request: {
    strategyId: string;
    strategyVersion: string;
    symbol: string;
    marketClass: MarketClass;
    timeframe: Timeframe;
    from?: string;
    to?: string;
    initialEquity: number;
    riskPct: number;
    feeBps: number;
    spreadBps: number;
    slippageBps: number;
    allowShorts: boolean;
  };
  dataProvenance: {
    source: string;
    synthetic: boolean;
    candles: number;
    from: string;
    to: string;
  };
  metrics: BacktestMetrics;
  equityCurve: { time: string; equity: number; drawdownPct: number }[];
  trades: BacktestTradeRecord[];
  warnings: string[];
}

// ---------------------------------------------------------------------------
// Trade journal (spec §15)
// ---------------------------------------------------------------------------

export type JournalSource = 'backtest' | 'manual' | 'paper' | 'live';

export interface JournalEntry {
  id: string;
  source: JournalSource;
  symbol: string;
  market: MarketClass;
  strategy: string | null;
  strategyVersion: string | null;
  direction: 'LONG' | 'SHORT';
  entry: { time: string; price: number };
  exit: { time: string; price: number } | null;
  positionSize: number; // units
  stopLoss: number | null;
  takeProfit: number | null;
  fees: number;
  slippage: number;
  pnl: number | null;      // net
  pnlPct: number | null;   // net / notional
  rMultiple: number | null;
  reasonForTrade: string;
  /** Confidence attached to the decision — from the strategy signal or an AI analysis run. */
  aiConfidence: number | null;
  confidenceSource: 'strategy' | 'ai-consensus' | 'manual' | null;
  agentConsensus: string | null;
  riskScore: number | null;
  executionTime: string;
  backtestId?: string;
  analysisRunId?: string;
  notes?: string;
}

// ---------------------------------------------------------------------------
// Store interfaces — swap to PostgreSQL in production (db/schema.sql);
// the JSON file store below is the tested default for Phase 2.
// ---------------------------------------------------------------------------

export interface StrategyStore {
  listStrategies(): Promise<StrategyVersionRecord[]>;
  getStrategy(id: string, version?: string): Promise<StrategyVersionRecord | null>;
  saveStrategy(record: StrategyVersionRecord): Promise<void>;
}

export interface BacktestStore {
  saveBacktest(record: BacktestRecord): Promise<void>;
  getBacktest(id: string): Promise<BacktestRecord | null>;
  listBacktests(filter?: { strategyId?: string; strategyVersion?: string; limit?: number }): Promise<BacktestRecord[]>;
  countBacktests(strategyId: string, strategyVersion?: string): Promise<number>;
}

export interface JournalStore {
  saveEntry(entry: JournalEntry): Promise<void>;
  listEntries(filter?: { source?: JournalSource; strategy?: string; symbol?: string; limit?: number }): Promise<JournalEntry[]>;
}

export interface DataStore extends StrategyStore, BacktestStore, JournalStore {
  kind: 'memory' | 'json-file';
}
