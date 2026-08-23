import type { Candle, MarketClass, Timeframe } from '../types';
import type {
  BacktestRecord, BacktestTradeRecord, DataStore, JournalEntry,
} from '../store/types';
import { precomputeIndicators, SeriesView } from '../strategies/series-view';
import type { TradingStrategy, StrategySignal } from '../strategies/types';
import { computeMetrics } from './metrics';
import type { EventBus } from '../events/events';
import type { ProviderManager } from '../marketdata/provider-manager';
import { randomUUID } from 'node:crypto';

export interface BacktestRequest {
  strategyId: string;
  strategyVersion: string;
  symbol: string;
  marketClass: MarketClass;
  timeframe: Timeframe;
  from?: string; // ISO date, inclusive
  to?: string; // ISO date, inclusive
  limit: number; // candle fetch limit (default 720, max 5000)
  initialEquity: number; // default 10_000
  riskPct: number; // fraction of equity risked per trade, default 0.01
  feeBps: number; // commission per side, bps of notional (default 2)
  spreadBps: number; // full spread, bps (default 1) — half applied each side
  slippageBps: number; // adverse slippage per side, bps (default 2)
  allowShorts: boolean; // default false
  warmupBars: number; // bars reserved for indicator warmup (default 60)
  maxBarsInTrade: number; // time stop, default 200 (0 = disabled)
}

export const DEFAULT_BACKTEST_REQUEST: Partial<BacktestRequest> = {
  limit: 720,
  initialEquity: 10_000,
  riskPct: 0.01,
  feeBps: 2,
  spreadBps: 1,
  slippageBps: 2,
  allowShorts: false,
  warmupBars: 60,
  maxBarsInTrade: 200,
};

interface OpenSimPosition {
  direction: 'LONG' | 'SHORT';
  entryBar: number;
  entryTime: string;
  entryPrice: number; // actual fill (includes spread+slippage)
  rawEntryPrice: number; // bar open, pre-cost
  stopLoss: number;
  takeProfit: number;
  units: number;
  riskAmount: number;
  entryFee: number;
  entrySpreadCost: number;
  entrySlipCost: number;
  signalReason: string;
  confidence: number;
}

interface PendingOrder {
  kind: 'ENTRY' | 'EXIT';
  signal: StrategySignal;
  exitReason?: BacktestTradeRecord['exitReason'];
}

export interface SimulationResult {
  trades: BacktestTradeRecord[];
  equityCurve: { time: string; equity: number; drawdownPct: number }[];
  barsInMarket: number;
  warnings: string[];
  ignoredSignals: number;
}

/**
 * Event-driven backtester with deliberate anti-bias mechanics:
 *
 *  - NO LOOK-AHEAD: strategies see only bars <= current via SeriesView
 *    (accessing the future throws LookAheadError and fails the run).
 *  - REALISTIC FILLS: signals evaluated on a CLOSED bar fill at the NEXT
 *    bar's open (never the signal bar's close), adjusted for half-spread
 *    and adverse slippage; commissions charged per side on notional.
 *  - PESSIMISTIC AMBIGUITY: when a bar touches both stop and target, the
 *    STOP is assumed to fill first.
 *  - FULL COST ACCOUNTING: every trade reports commission, spread and
 *    slippage components; netPnl reconciles exactly with the equity curve.
 *  - PROVENANCE: the run records its data source incl. synthetic flag —
 *    synthetic results are labeled as simulation everywhere.
 *
 * Cost model (per side): fill = raw × (1 ± (halfSpread + slip)); commission =
 * notional × feeBps. Position size = (equity × riskPct) / stopDistance.
 */
export function simulate(
  strategy: TradingStrategy,
  candles: Candle[],
  req: BacktestRequest,
  meta: { symbol: string; timeframe: Timeframe; marketClass: string },
): SimulationResult {
  const warnings: string[] = [];
  const trades: BacktestTradeRecord[] = [];
  const equityCurve: SimulationResult['equityCurve'] = [];
  const ind = precomputeIndicators(candles);
  const warmup = Math.min(req.warmupBars, Math.max(0, candles.length - 10));

  let equity = req.initialEquity;
  let peak = equity;
  let position: OpenSimPosition | null = null;
  let pending: PendingOrder | null = null;
  let barsInMarket = 0;
  let ignoredSignals = 0;

  const h = req.spreadBps / 2 / 10_000; // half-spread fraction
  const s = req.slippageBps / 10_000; // slippage fraction
  const feeRate = req.feeBps / 10_000;

  const closePosition = (
    pos: OpenSimPosition,
    rawExit: number,
    exitTime: string,
    exitReason: BacktestTradeRecord['exitReason'],
    exitBar: number,
  ): void => {
    const exitPrice = pos.direction === 'LONG' ? rawExit * (1 - h - s) : rawExit * (1 + h + s);
    const grossPnl = pos.direction === 'LONG'
      ? (exitPrice - pos.entryPrice) * pos.units
      : (pos.entryPrice - exitPrice) * pos.units;
    const exitNotional = exitPrice * pos.units;
    const exitFee = exitNotional * feeRate;
    const exitSpreadCost = rawExit * h * pos.units;
    const exitSlipCost = rawExit * s * pos.units;

    // Equity: entry fee was booked at entry; here the rest of the P&L lands.
    const equityDelta = grossPnl - exitFee;
    equity += equityDelta;
    // Reported net P&L includes ALL costs on both sides (reconciles with the
    // total equity change attributable to this trade).
    const netPnl = grossPnl - pos.entryFee - exitFee;

    trades.push({
      direction: pos.direction,
      entryTime: pos.entryTime,
      exitTime,
      entryPrice: pos.entryPrice,
      exitPrice,
      units: pos.units,
      notional: pos.entryPrice * pos.units,
      riskAmount: pos.riskAmount,
      stopLoss: pos.stopLoss,
      takeProfit: pos.takeProfit,
      fees: {
        entryFee: r6(pos.entryFee),
        exitFee: r6(exitFee),
        spreadCost: r6(pos.entrySpreadCost + exitSpreadCost),
        slippageCost: r6(pos.entrySlipCost + exitSlipCost),
        totalCost: r6(pos.entryFee + exitFee + pos.entrySpreadCost + exitSpreadCost + pos.entrySlipCost + exitSlipCost),
      },
      grossPnl: r6(grossPnl),
      netPnl: r6(netPnl),
      returnPct: r6((netPnl / req.initialEquity) * 100),
      rMultiple: pos.riskAmount > 0 ? r4(netPnl / pos.riskAmount) : 0,
      exitReason,
      barsHeld: exitBar - pos.entryBar,
      signalReason: pos.signalReason,
      confidence: pos.confidence,
    });
    position = null;
  };

  for (let i = warmup; i < candles.length; i++) {
    const bar = candles[i];

    // --- 1) Intrabar stop/target management for an open position ------------
    if (position) {
      barsInMarket++;
      const pos = position;
      const stopHit = pos.direction === 'LONG' ? bar.low <= pos.stopLoss : bar.high >= pos.stopLoss;
      const targetHit = pos.direction === 'LONG' ? bar.high >= pos.takeProfit : bar.low <= pos.takeProfit;
      // Pessimistic rule: if both touched in the same bar, the STOP fills first.
      if (stopHit) {
        closePosition(pos, pos.stopLoss, iso(bar.timestamp), 'STOP_LOSS', i);
        pending = null; // a pending exit is moot after a stop-out
      } else if (targetHit) {
        closePosition(pos, pos.takeProfit, iso(bar.timestamp), 'TAKE_PROFIT', i);
        pending = null;
      } else if (req.maxBarsInTrade > 0 && i - pos.entryBar >= req.maxBarsInTrade) {
        pending = { kind: 'EXIT', signal: { action: 'CLOSE', reason: 'time stop', confidence: 0 }, exitReason: 'TIME_STOP' };
      }
    }

    // --- 2) Fill the order queued by the PREVIOUS bar's signal at this open -
    if (pending) {
      if (pending.kind === 'EXIT') {
        if (position) closePosition(position, bar.open, iso(bar.timestamp), pending.exitReason ?? 'SIGNAL', i);
        pending = null;
      } else if (!position) {
        const sig = pending.signal;
        pending = null;
        const wantsShort = sig.action === 'SELL';
        const stop = sig.stopLoss;
        if ((sig.action === 'BUY' || wantsShort) && stop !== undefined && Number.isFinite(stop)) {
          const direction: 'LONG' | 'SHORT' = wantsShort ? 'SHORT' : 'LONG';
          const stopOnCorrectSide = direction === 'LONG' ? stop < bar.open : stop > bar.open;
          if (wantsShort && !req.allowShorts) {
            ignoredSignals++;
          } else if (!stopOnCorrectSide) {
            warnings.push(`Skipped ${sig.action} at ${iso(bar.timestamp)}: stop must sit beyond the entry fill on the correct side`);
          } else {
            const raw = bar.open;
            const fill = wantsShort ? raw * (1 - h - s) : raw * (1 + h + s);
            const stopDistance = Math.abs(fill - stop);
            const riskAmount = equity * req.riskPct;
            const units = riskAmount / stopDistance;
            const notional = units * fill;
            const entryFee = notional * feeRate;
            equity -= entryFee; // commission booked immediately; spread+slip embedded in the fill
            const entrySpreadCost = raw * h * units;
            const entrySlipCost = raw * s * units;
            position = {
              direction,
              entryBar: i,
              entryTime: iso(bar.timestamp),
              entryPrice: fill,
              rawEntryPrice: raw,
              stopLoss: stop,
              takeProfit:
                sig.takeProfit !== undefined && Number.isFinite(sig.takeProfit)
                  ? sig.takeProfit
                  : direction === 'LONG'
                    ? fill + 3 * stopDistance
                    : fill - 3 * stopDistance,
              units,
              riskAmount,
              entryFee,
              entrySpreadCost,
              entrySlipCost,
              signalReason: sig.reason,
              confidence: sig.confidence,
            };
            // The entry bar itself may already touch the stop/target.
            const stopHitNow = direction === 'LONG' ? bar.low <= stop : bar.high >= stop;
            const tp = position.takeProfit;
            const targetHitNow = direction === 'LONG' ? bar.high >= tp : bar.low <= tp;
            if (stopHitNow) closePosition(position, stop, iso(bar.timestamp), 'STOP_LOSS', i);
            else if (targetHitNow) closePosition(position, tp, iso(bar.timestamp), 'TAKE_PROFIT', i);
          }
        }
      } else {
        pending = null; // cannot enter while already in a position
      }
    }

    // --- 3) Evaluate the strategy on this CLOSED bar ------------------------
    const view = new SeriesView(candles, ind, i, meta);
    const unrealized = position
      ? (position.direction === 'LONG'
        ? (bar.close - position.entryPrice) * position.units
        : (position.entryPrice - bar.close) * position.units)
      : 0;
    const ctx = {
      view,
      position: position
        ? {
          direction: position.direction,
          entryPrice: position.entryPrice,
          entryBar: position.entryBar,
          stopLoss: position.stopLoss,
          takeProfit: position.takeProfit,
          unrealizedPnl: unrealized,
        }
        : null,
      equity: equity + unrealized,
    };
    let signal: StrategySignal;
    try {
      signal = strategy.evaluate(ctx);
    } catch (err) {
      if (err instanceof Error && err.name === 'LookAheadError') {
        throw err; // look-ahead bias is a fatal strategy bug — never continue
      }
      warnings.push(`Strategy threw at bar ${iso(bar.timestamp)}: ${err instanceof Error ? err.message : String(err)}`);
      signal = { action: 'HOLD', reason: 'strategy error', confidence: 0 };
    }

    if (signal.action === 'CLOSE' && position) {
      pending = { kind: 'EXIT', signal, exitReason: 'SIGNAL' };
    } else if ((signal.action === 'BUY' || signal.action === 'SELL') && !position && !pending) {
      if (signal.action === 'SELL' && !req.allowShorts) ignoredSignals++;
      else pending = { kind: 'ENTRY', signal };
    }

    // --- 4) Mark-to-market equity + drawdown --------------------------------
    const marked = equity + unrealized;
    peak = Math.max(peak, marked);
    equityCurve.push({
      time: iso(bar.timestamp),
      equity: r2(marked),
      drawdownPct: peak > 0 ? r4(((peak - marked) / peak) * 100) : 0,
    });
  }

  // End of data: close any open position at the final close.
  if (position) {
    const lastBar = candles[candles.length - 1];
    const pos = position;
    closePosition(pos, lastBar.close, iso(lastBar.timestamp), 'END_OF_DATA', candles.length - 1);
    if (equityCurve.length) {
      const lastPoint = equityCurve[equityCurve.length - 1];
      lastPoint.equity = r2(equity);
      lastPoint.drawdownPct = peak > 0 ? r4(((peak - equity) / peak) * 100) : 0;
    }
  }

  if (ignoredSignals > 0) {
    warnings.push(`${ignoredSignals} short signals ignored (allowShorts=false)`);
  }

  return { trades, equityCurve, barsInMarket, warnings, ignoredSignals };
}

/** Run a backtest end-to-end: data -> simulate -> metrics -> persist -> journal -> audit. */
export async function runBacktest(
  deps: { providerManager: ProviderManager; store: DataStore; eventBus: EventBus; strategy: TradingStrategy },
  input: Partial<BacktestRequest> & {
    strategyId: string; strategyVersion: string; symbol: string; marketClass: MarketClass; timeframe: Timeframe;
  },
): Promise<BacktestRecord> {
  const req: BacktestRequest = { ...DEFAULT_BACKTEST_REQUEST, ...input } as BacktestRequest;
  req.limit = Math.min(Math.max(60, req.limit), 5000);
  if (req.initialEquity <= 0) throw new Error('initialEquity must be positive');
  if (req.riskPct <= 0 || req.riskPct > 0.05) throw new Error('riskPct must be in (0, 5%]');
  if (req.feeBps < 0 || req.spreadBps < 0 || req.slippageBps < 0) throw new Error('cost parameters cannot be negative');

  const startedAt = new Date().toISOString();
  deps.eventBus.emit('BACKTEST_STARTED', `Backtest ${req.strategyId}@${req.strategyVersion} on ${req.symbol} ${req.timeframe}`, {
    strategyId: req.strategyId,
    symbol: req.symbol,
    timeframe: req.timeframe,
  });

  const series = await deps.providerManager.getCandleSeries(req.symbol, req.marketClass, req.timeframe, req.limit);
  let candles = series.candles;
  if (req.from) {
    const fromMs = Date.parse(req.from);
    if (Number.isFinite(fromMs)) candles = candles.filter((c) => c.timestamp >= fromMs);
  }
  if (req.to) {
    const toMs = Date.parse(req.to) + 86_400_000; // inclusive day
    if (Number.isFinite(toMs)) candles = candles.filter((c) => c.timestamp < toMs);
  }
  if (candles.length < 120) {
    throw new Error(`Only ${candles.length} candles in range — need at least 120 for a meaningful backtest`);
  }

  const result = simulate(deps.strategy, candles, req, {
    symbol: req.symbol,
    timeframe: req.timeframe,
    marketClass: req.marketClass,
  });
  const metrics = computeMetrics(result.trades, result.equityCurve, req.initialEquity, req.timeframe, result.barsInMarket);

  const record: BacktestRecord = {
    id: randomUUID(),
    createdAt: startedAt,
    request: {
      strategyId: req.strategyId,
      strategyVersion: req.strategyVersion,
      symbol: req.symbol,
      marketClass: req.marketClass,
      timeframe: req.timeframe,
      from: req.from,
      to: req.to,
      initialEquity: req.initialEquity,
      riskPct: req.riskPct,
      feeBps: req.feeBps,
      spreadBps: req.spreadBps,
      slippageBps: req.slippageBps,
      allowShorts: req.allowShorts,
    },
    dataProvenance: {
      source: series.provenance.source,
      synthetic: series.provenance.synthetic,
      candles: candles.length,
      from: candles.length ? iso(candles[0].timestamp) : '',
      to: candles.length ? iso(candles[candles.length - 1].timestamp) : '',
    },
    metrics,
    equityCurve: result.equityCurve,
    trades: result.trades,
    warnings: [
      ...result.warnings,
      ...(series.provenance.synthetic
        ? ['Candles are SYNTHETIC — results are a simulation of the strategy logic, not market performance']
        : []),
    ],
  };

  await deps.store.saveBacktest(record);

  // Journal every backtest trade (spec §15).
  for (const t of record.trades) {
    const entry: JournalEntry = {
      id: randomUUID(),
      source: 'backtest',
      symbol: req.symbol,
      market: req.marketClass,
      strategy: req.strategyId,
      strategyVersion: req.strategyVersion,
      direction: t.direction,
      entry: { time: t.entryTime, price: t.entryPrice },
      exit: { time: t.exitTime, price: t.exitPrice },
      positionSize: t.units,
      stopLoss: t.stopLoss,
      takeProfit: t.takeProfit,
      fees: t.fees.totalCost,
      slippage: t.fees.slippageCost,
      pnl: t.netPnl,
      pnlPct: (t.netPnl / Math.max(1e-9, t.units * t.entryPrice)) * 100,
      rMultiple: t.rMultiple,
      reasonForTrade: t.signalReason,
      aiConfidence: t.confidence,
      confidenceSource: 'strategy',
      agentConsensus: null,
      riskScore: req.riskPct,
      executionTime: t.entryTime,
      backtestId: record.id,
    };
    await deps.store.saveEntry(entry);
  }

  deps.eventBus.emit('BACKTEST_COMPLETED', `Backtest ${req.strategyId}@${req.strategyVersion} on ${req.symbol}: ${metrics.trades} trades, return ${metrics.totalReturnPct.toFixed(2)}%`, {
    backtestId: record.id,
    strategyId: req.strategyId,
    symbol: req.symbol,
    trades: metrics.trades,
    totalReturnPct: metrics.totalReturnPct,
    synthetic: record.dataProvenance.synthetic,
  });

  return record;
}

function iso(ms: number): string {
  return new Date(ms).toISOString();
}

function r2(v: number): number {
  return Math.round(v * 100) / 100;
}
function r4(v: number): number {
  return Math.round(v * 10000) / 10000;
}
function r6(v: number): number {
  return Math.round(v * 1e6) / 1e6;
}
