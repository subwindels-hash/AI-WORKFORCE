import { describe, expect, it } from 'vitest';
import { RiskEngine } from '../src/risk/risk-engine';
import { DEFAULT_RISK_LIMITS } from '../src/config/defaults';
import type { TradeSetup } from '../src/types';

function setup(overrides: Partial<TradeSetup> = {}): TradeSetup {
  return {
    action: 'BUY',
    symbol: 'EURUSD',
    marketClass: 'forex',
    timeframe: '1h',
    entry: { type: 'ZONE', min: 1.0810, max: 1.0820, reference: 1.0815 },
    stopLoss: 1.0785,
    takeProfit: [1.0855, 1.0890, 1.0925],
    riskReward: 2.0,
    confidence: 0.7,
    expiration: new Date(Date.now() + 86_400_000).toISOString(),
    invalidationReasons: [],
    rationale: [],
    ...overrides,
  };
}

const cleanContext = { killSwitchActive: false, dataQuality: 0.9, syntheticData: false, staleData: false };

describe('RiskEngine — per-trade checks', () => {
  it('approves a clean setup and computes sizing math exactly', () => {
    const engine = new RiskEngine();
    const d = engine.evaluate(setup(), cleanContext);
    expect(d.approved).toBe(true);
    expect(d.reasons).toEqual([]);
    // equity 10000, risk 1% = 100; stop distance = 0.0030 => units = 33333.33
    expect(d.sizing?.riskAmount).toBe(100);
    expect(d.sizing?.stopDistance).toBeCloseTo(0.0030, 8);
    expect(d.sizing?.units).toBeCloseTo(33333.33, 1);
    expect(d.sizing?.notionalUsd).toBeCloseTo(33333.33 * 1.0815, 0);
  });

  it('rejects a setup below the minimum risk/reward', () => {
    const engine = new RiskEngine();
    const d = engine.evaluate(setup({ riskReward: 1.0 }), cleanContext);
    expect(d.approved).toBe(false);
    expect(d.reasons.some((r) => /Risk\/reward/.test(r))).toBe(true);
  });

  it('rejects when the stop loss is missing (stop mandatory)', () => {
    const engine = new RiskEngine();
    const d = engine.evaluate(setup({ stopLoss: NaN }), cleanContext);
    expect(d.approved).toBe(false);
    expect(d.reasons.some((r) => /Stop loss is required/.test(r))).toBe(true);
  });

  it('rejects notional above the cap and leverage above the limit', () => {
    const engine = new RiskEngine();
    // Very tight stop => huge units => huge notional & leverage.
    const d = engine.evaluate(
      setup({ entry: { type: 'ZONE', min: 1.0819, max: 1.0820, reference: 1.08195 }, stopLoss: 1.0819 }),
      cleanContext,
    );
    expect(d.approved).toBe(false);
    expect(d.reasons.some((r) => /notional/i.test(r))).toBe(true);
  });

  it('rejects any proposal while the kill switch is active (Rule 7)', () => {
    const engine = new RiskEngine();
    const d = engine.evaluate(setup(), { ...cleanContext, killSwitchActive: true });
    expect(d.approved).toBe(false);
    expect(d.reasons.some((r) => /Kill switch is ACTIVE/.test(r))).toBe(true);
  });

  it('rejects setups built on synthetic or stale data (Rule 2 enforcement)', () => {
    const engine = new RiskEngine();
    const synth = engine.evaluate(setup(), { ...cleanContext, syntheticData: true });
    expect(synth.approved).toBe(false);
    expect(synth.reasons[0]).toMatch(/SYNTHETIC/);

    const stale = engine.evaluate(setup(), { ...cleanContext, staleData: true });
    expect(stale.approved).toBe(false);
    expect(stale.reasons.some((r) => /stale/.test(r))).toBe(true);
  });

  it('rejects when data quality is below the configured floor', () => {
    const engine = new RiskEngine();
    const d = engine.evaluate(setup(), { ...cleanContext, dataQuality: 0.2 });
    expect(d.approved).toBe(false);
    expect(d.reasons.some((r) => /Data quality/.test(r))).toBe(true);
  });
});

describe('RiskEngine — portfolio checks & limits management', () => {
  it('rejects when the daily loss limit is exceeded', () => {
    const engine = new RiskEngine();
    engine.setPortfolio({ dailyPnl: -500 }); // -5% of 10k > 3% limit
    const d = engine.evaluate(setup(), cleanContext);
    expect(d.approved).toBe(false);
    expect(d.reasons).toContain('Daily loss limit exceeded');
  });

  it('rejects when drawdown exceeds the cap', () => {
    const engine = new RiskEngine();
    engine.setPortfolio({ equity: 8500, peakEquity: 10000 }); // 15% dd > 10%
    const d = engine.evaluate(setup(), cleanContext);
    expect(d.approved).toBe(false);
    expect(d.reasons.some((r) => /drawdown/i.test(r))).toBe(true);
  });

  it('rejects when symbol risk concentration would breach the cap', () => {
    const engine = new RiskEngine();
    engine.setPortfolio({ openRiskBySymbol: { EURUSD: 450 } }); // 4.5% at risk + 1% new = 5.5% > 5%
    const d = engine.evaluate(setup(), cleanContext);
    expect(d.approved).toBe(false);
    expect(d.reasons.some((r) => /Risk concentration/.test(r))).toBe(true);
  });

  it('clamps risk per trade to the hard cap on update', () => {
    const engine = new RiskEngine();
    const limits = engine.updateLimits({ riskPerTradePct: 0.5 }); // try 50%
    expect(limits.riskPerTradePct).toBe(limits.maxRiskPerTradePct);
  });

  it('rejects internally inconsistent configurations', () => {
    // Direct construction bypasses the update-time clamp: the evaluate() check
    // must catch a risk-per-trade above the hard cap on its own.
    const engine = new RiskEngine({ ...DEFAULT_RISK_LIMITS, riskPerTradePct: 0.5 });
    const d = engine.evaluate(setup(), cleanContext);
    expect(d.approved).toBe(false);
    expect(d.reasons.some((r) => /exceeds hard cap/.test(r))).toBe(true);
  });
});
