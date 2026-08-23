import type { MarketClass, Timeframe } from '../types';
import type { SeriesView } from './series-view';

export type StrategyAction = 'BUY' | 'SELL' | 'CLOSE' | 'HOLD';

/**
 * What a strategy emits for the current bar. Signals are PROPOSALS — the
 * backtester (and, from Phase 5 on, the execution supervisor) applies them
 * through position management and the Risk Engine. Strategies never place
 * orders and never see a broker.
 */
export interface StrategySignal {
  action: StrategyAction;
  /** Human-readable justification (stored in the trade journal). */
  reason: string;
  /** Strategy's own conviction 0..1 — journaled and used for confidence analytics. */
  confidence: number;
  /** Invalidation stop for the resulting position (price). */
  stopLoss?: number;
  /** Primary target (price). */
  takeProfit?: number;
}

/** Position state passed to strategies while a simulated trade is open. */
export interface OpenPositionView {
  direction: 'LONG' | 'SHORT';
  entryPrice: number;
  entryBar: number;
  stopLoss: number;
  takeProfit: number;
  unrealizedPnl: number;
}

export interface TradingContext {
  view: SeriesView;
  position: OpenPositionView | null;
  /** Equity of the simulated account at this bar (for context only). */
  equity: number;
}

export interface TradingStrategy {
  id: string;
  version: string;
  name: string;
  description: string;
  marketClasses: MarketClass[];
  timeframes: Timeframe[];
  params: Record<string, number | boolean | string>;
  supportsShorts: boolean;
  evaluate(ctx: TradingContext): StrategySignal;
}
