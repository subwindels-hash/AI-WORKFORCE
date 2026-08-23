import { describe, expect, it } from 'vitest';
import { simulate, type BacktestRequest } from '../src/backtesting/engine';
import type { TradingStrategy, StrategySignal, TradingContext } from '../src/strategies/types';
import { LookAheadError } from '../src/strategies/series-view';
import type { Candle } from '../src/types';

const NOW = 1_755_000_000_000;
const H = 3_600_000;

interface Spec {
  open: number; high: number; low: number; close: number; volume?: number;
}

function candlesFrom(specs: Spec[]): Candle[] {
  return specs.map((s, i) => ({
    timestamp: NOW - (specs.length - i) * H,
    open: s.open, high: s.high, low: s.low, close: s.close, volume: s.volume ?? 100,
  }));
}

function flatSpecs(n: number, price = 100): Spec[] {
  return Array.from({ length: n }, () => ({ open: price, high: price + 0.2, low: price - 0.2, close: price }));
}

/** Scripted strategy: emits configured signals at exact bar indices. */
class ScriptedStrategy implements TradingStrategy {
  readonly id = 'scripted';
  readonly version = '1.0.0';
  readonly name = 'Scripted test strategy';
  readonly description = 'emits signals at fixed bars';
  readonly marketClasses = ['crypto'] as never;
  readonly timeframes = ['1h'] as never;
  readonly params = {};
  readonly supportsShorts = true;

  constructor(
    private readonly script: Record<number, StrategySignal>,
    private readonly seenIndices: number[] = [],
  ) {}

  evaluate(ctx: TradingContext): StrategySignal {
    this.seenIndices.push(ctx.view.index);
    return this.script[ctx.view.index] ?? { action: 'HOLD', reason: 'none', confidence: 0 };
  }
}

function req(overrides: Partial<BacktestRequest> = {}): BacktestRequest {
  return {
    strategyId: 'scripted', strategyVersion: '1.0.0',
    symbol: 'TESTUSD', marketClass: 'crypto', timeframe: '1h',
    limit: 200, initialEquity: 10_000, riskPct: 0.01,
    feeBps: 2, spreadBps: 2, slippageBps: 2,
    allowShorts: false, warmupBars: 10, maxBarsInTrade: 0,
    ...overrides,
  };
}

const META = { symbol: 'TESTUSD', timeframe: '1h' as const, marketClass: 'crypto' };

describe('backtester — fill mechanics', () => {
  it('fills entries at the NEXT bar open (never the signal close), adjusted for costs', () => {
    const specs = flatSpecs(80);
    specs[30] = { open: 100, high: 100.2, low: 99.8, close: 101 }; // signal bar closes at 101
    specs[31] = { open: 102, high: 102.2, low: 101.8, close: 102 }; // next open 102
    const strategy = new ScriptedStrategy({
      30: { action: 'BUY', reason: 'test entry', confidence: 0.8, stopLoss: 98, takeProfit: 110 },
    });
    const res = simulate(strategy, candlesFrom(specs), req(), META);
    expect(res.trades).toHaveLength(1);
    const t = res.trades[0];
    // fill = 102 * (1 + halfSpread(1bp) + slip(2bp)) = 102 * 1.0003
    expect(t.entryPrice).toBeCloseTo(102 * 1.0003, 8);
    expect(t.entryTime).toBe(new Date(NOW - (80 - 31) * H).toISOString());
    // sizing: riskAmount 100 / stopDistance (102*1.0003 - 98)
    const stopDistance = 102 * 1.0003 - 98;
    expect(t.units).toBeCloseTo(100 / stopDistance, 6);
    expect(t.riskAmount).toBeCloseTo(100, 8);
    // exit at end of data: raw close of the final bar (flat 100) -> 100*(1-0.0003)
    expect(t.exitReason).toBe('END_OF_DATA');
    expect(t.exitPrice).toBeCloseTo(100 * 0.9997, 8);
  });

  it('fills CLOSE-signal exits at the next bar open with reason SIGNAL', () => {
    const specs = flatSpecs(80);
    const strategy = new ScriptedStrategy({
      20: { action: 'BUY', reason: 'in', confidence: 0.5, stopLoss: 90, takeProfit: 120 },
      40: { action: 'CLOSE', reason: 'trend gone', confidence: 0.5 },
    });
    const res = simulate(strategy, candlesFrom(specs), req(), META);
    expect(res.trades).toHaveLength(1);
    const t = res.trades[0];
    expect(t.exitReason).toBe('SIGNAL');
    // exit raw = open of bar 41 = 100, fill = 100*(1-0.0003)
    expect(t.exitPrice).toBeCloseTo(100 * 0.9997, 8);
    expect(t.barsHeld).toBe(41 - 21);
  });

  it('assumes the STOP fills first when a bar touches both stop and target (pessimistic)', () => {
    const specs = flatSpecs(80);
    specs[31] = { open: 100, high: 112, low: 97.5, close: 100 }; // hits stop (98) AND target (110)
    const strategy = new ScriptedStrategy({
      30: { action: 'BUY', reason: 'in', confidence: 0.5, stopLoss: 98, takeProfit: 110 },
    });
    const res = simulate(strategy, candlesFrom(specs), req(), META);
    const t = res.trades[0];
    expect(t.exitReason).toBe('STOP_LOSS');
    expect(t.exitPrice).toBeCloseTo(98 * 0.9997, 8); // stop with adverse costs
    expect(t.netPnl).toBeLessThan(0);
  });

  it('can stop out on the entry bar itself', () => {
    const specs = flatSpecs(80);
    specs[31] = { open: 100, high: 100.2, low: 97, close: 99 }; // low pierces the 98 stop
    const strategy = new ScriptedStrategy({
      30: { action: 'BUY', reason: 'in', confidence: 0.5, stopLoss: 98, takeProfit: 110 },
    });
    const res = simulate(strategy, candlesFrom(specs), req(), META);
    expect(res.trades[0].exitReason).toBe('STOP_LOSS');
    expect(res.trades[0].barsHeld).toBe(0);
  });

  it('takes profit at the target when touched without the stop', () => {
    const specs = flatSpecs(80);
    specs[33] = { open: 100, high: 111, low: 99.8, close: 110 };
    const strategy = new ScriptedStrategy({
      30: { action: 'BUY', reason: 'in', confidence: 0.5, stopLoss: 98, takeProfit: 110 },
    });
    const res = simulate(strategy, candlesFrom(specs), req(), META);
    const t = res.trades[0];
    expect(t.exitReason).toBe('TAKE_PROFIT');
    expect(t.exitPrice).toBeCloseTo(110 * 0.9997, 8);
    expect(t.netPnl).toBeGreaterThan(0);
  });

  it('computes SHORT trades as the mirror of longs when allowShorts=true', () => {
    const specs = flatSpecs(80);
    // price falls after the short entry so the 95 target is reached
    specs[32] = { open: 99, high: 99.2, low: 98.5, close: 98.8 };
    specs[33] = { open: 98.5, high: 98.6, low: 94.5, close: 96 };
    const strategy = new ScriptedStrategy({
      30: { action: 'SELL', reason: 'short', confidence: 0.6, stopLoss: 102, takeProfit: 95 },
    });
    const res = simulate(strategy, candlesFrom(specs), req({ allowShorts: true }), META);
    const t = res.trades[0];
    expect(t.direction).toBe('SHORT');
    expect(t.entryPrice).toBeCloseTo(100 * 0.9997, 8); // sell fill = open*(1 - costs)
    expect(t.exitReason).toBe('TAKE_PROFIT');
    expect(t.exitPrice).toBeCloseTo(95 * 1.0003, 8); // buy-back fill = open*(1 + costs)
    expect(t.netPnl).toBeGreaterThan(0);
  });

  it('ignores short signals (with warning) when allowShorts=false', () => {
    const specs = flatSpecs(80);
    const strategy = new ScriptedStrategy({
      30: { action: 'SELL', reason: 'short', confidence: 0.6, stopLoss: 102, takeProfit: 95 },
    });
    const res = simulate(strategy, candlesFrom(specs), req({ allowShorts: false }), META);
    expect(res.trades).toHaveLength(0);
    expect(res.ignoredSignals).toBe(1);
    expect(res.warnings.some((w) => /short signals ignored/.test(w))).toBe(true);
  });

  it('skips signals whose stop is on the wrong side of the fill', () => {
    const specs = flatSpecs(80);
    const strategy = new ScriptedStrategy({
      30: { action: 'BUY', reason: 'bad stop', confidence: 0.5, stopLoss: 105, takeProfit: 110 }, // stop ABOVE entry
    });
    const res = simulate(strategy, candlesFrom(specs), req(), META);
    expect(res.trades).toHaveLength(0);
    expect(res.warnings.some((w) => /stop must sit beyond/.test(w))).toBe(true);
  });

  it('applies the time stop after maxBarsInTrade bars', () => {
    const specs = flatSpecs(80);
    const strategy = new ScriptedStrategy({
      30: { action: 'BUY', reason: 'in', confidence: 0.5, stopLoss: 50, takeProfit: 200 },
    });
    const res = simulate(strategy, candlesFrom(specs), req({ maxBarsInTrade: 5 }), META);
    const t = res.trades[0];
    expect(t.exitReason).toBe('TIME_STOP');
    expect(t.barsHeld).toBeGreaterThanOrEqual(5);
  });
});

describe('backtester — cost accounting & equity reconciliation', () => {
  it('reports the full cost decomposition and reconciles net P&L with the equity curve', () => {
    const specs = flatSpecs(80);
    const strategy = new ScriptedStrategy({
      30: { action: 'BUY', reason: 'in', confidence: 0.5, stopLoss: 98, takeProfit: 105 },
    });
    // let it hit the target on a later bar
    specs[40] = { open: 100, high: 106, low: 99.8, close: 105 };
    const r = req();
    const res = simulate(strategy, candlesFrom(specs), r, META);
    const t = res.trades[0];
    const h = 0.0001; // half spread (2bps full)
    const s = 0.0002; // slippage
    const fee = 0.0002;

    const rawEntry = 100; // open of bar 31
    const rawExit = 105; // target
    const fillEntry = rawEntry * (1 + h + s);
    const fillExit = rawExit * (1 - h - s);
    const units = 100 / (fillEntry - 98); // riskAmount / stopDistance at fill
    const entryFee = units * fillEntry * fee;
    const exitFee = units * fillExit * fee;

    expect(t.fees.entryFee).toBeCloseTo(entryFee, 4);
    expect(t.fees.exitFee).toBeCloseTo(exitFee, 4);
    expect(t.fees.spreadCost).toBeCloseTo((rawEntry + rawExit) * h * units, 4);
    expect(t.fees.slippageCost).toBeCloseTo((rawEntry + rawExit) * s * units, 4);
    expect(t.fees.totalCost).toBeCloseTo(entryFee + exitFee + (rawEntry + rawExit) * (h + s) * units, 4);

    // net = gross(fill-to-fill) - both commissions
    expect(t.netPnl).toBeCloseTo((fillExit - fillEntry) * units - entryFee - exitFee, 4);
    // reconciliation: raw-to-raw P&L minus ALL costs equals net
    const rawPnl = (rawExit - rawEntry) * units;
    expect(rawPnl - t.fees.totalCost).toBeCloseTo(t.netPnl, 4);

    // final equity = initial - entryFee + equityDelta at exit
    const finalEquity = res.equityCurve[res.equityCurve.length - 1].equity;
    expect(finalEquity).toBeCloseTo(10_000 - entryFee + ((fillExit - fillEntry) * units - exitFee), 2);
  });
});

describe('backtester — look-ahead protection', () => {
  it('fails the run when a strategy tries to read the future', () => {
    const cheating: TradingStrategy = {
      id: 'cheater', version: '1.0.0', name: 'cheater', description: 'reads ahead',
      marketClasses: ['crypto'] as never, timeframes: ['1h'] as never, params: {}, supportsShorts: false,
      evaluate(ctx) {
        void ctx.view.close(ctx.view.index + 1); // peek at tomorrow
        return { action: 'HOLD', reason: 'x', confidence: 0 };
      },
    };
    expect(() => simulate(cheating, candlesFrom(flatSpecs(80)), req(), META)).toThrow(LookAheadError);
  });

  it('only ever presents bars up to the current one to the strategy', () => {
    const seen: number[] = [];
    const strategy = new ScriptedStrategy({}, seen);
    simulate(strategy, candlesFrom(flatSpecs(50)), req({ warmupBars: 10 }), META);
    expect(seen).toEqual(Array.from({ length: 40 }, (_, i) => 10 + i));
  });
});
