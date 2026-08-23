import { describe, expect, it, beforeAll } from 'vitest';
import { createPlatform } from '../src/index';
import type { AnalysisRun } from '../src/types';

/**
 * END-TO-END Phase 1 pipeline test with real providers disabled:
 * data -> agents -> consensus -> regime -> scenarios -> setup -> risk decision.
 */
describe('TradingIntelligenceEngine — full pipeline', () => {
  const { engine, eventBus, riskEngine } = createPlatform({ disableRealProviders: true });

  beforeAll(() => {
    // deterministic tests: freeze the kill switch off/on as needed per test
  });

  it('runs a complete crypto analysis with honest synthetic provenance', async () => {
    const run = await engine.run({ symbol: 'BTCUSDT', marketClass: 'crypto', timeframe: '1h' });
    expect(run.symbol).toBe('BTCUSDT');
    expect(run.provenance.synthetic).toBe(true);
    expect(run.provenance.source).toBe('synthetic-demo');
    expect(run.marketRegime).toBeTruthy();
    expect(run.consensus.votingAgents).toContain('technical');
    expect(run.consensus.votingAgents).toContain('market-structure');
    expect(run.agents.some((a) => a.agent === 'crypto')).toBe(true);
    expect(run.agents.some((a) => a.agent === 'forex')).toBe(false); // not applicable to crypto
    expect(run.agents.some((a) => a.agent === 'sentiment')).toBe(true);
    expect(run.bias).toMatch(/BULLISH|BEARISH|NEUTRAL|NO_TRADE/);
    expect(run.confidence).toBeGreaterThanOrEqual(0);
    expect(run.confidence).toBeLessThanOrEqual(1);
    expect(run.scenarios.bullish.targets.length).toBeGreaterThan(0);
    expect(eventBus.recent().some((e) => e.type === 'TRADE_ANALYZED')).toBe(true);
  });

  it('runs a forex analysis with the forex agent reporting macro unavailability', async () => {
    const run = await engine.run({ symbol: 'EURUSD', marketClass: 'forex', timeframe: '1h' });
    const fx = run.agents.find((a) => a.agent === 'forex');
    expect(fx).toBeDefined();
    if (fx && fx.agent === 'forex') {
      expect(fx.macro.available).toBe(false);
      expect(fx.currencyStrength.derivedFrom).toBe('price-momentum');
    }
  });

  it('produces a structured setup when bias is directional, with a risk decision attached', async () => {
    // Sweep a few symbols; at least one should be directional in synthetic data.
    const symbols = ['BTCUSDT', 'ETHUSDT', 'SOLUSDT', 'EURUSD', 'GBPUSD'];
    let foundSetup = false;
    for (const s of symbols) {
      const run = await engine.run({
        symbol: s,
        marketClass: s.endsWith('USDT') ? 'crypto' : 'forex',
        timeframe: '1h',
      });
      if (run.tradeSetup) {
        foundSetup = true;
        const setup = run.tradeSetup;
        expect(setup.entry.min).toBeLessThan(setup.entry.max);
        expect(setup.takeProfit.length).toBe(3);
        // Direction-consistent levels
        if (setup.action === 'BUY') {
          expect(setup.stopLoss).toBeLessThan(setup.entry.min);
          expect(setup.takeProfit[0]).toBeGreaterThan(setup.entry.max);
        } else {
          expect(setup.stopLoss).toBeGreaterThan(setup.entry.max);
          expect(setup.takeProfit[0]).toBeLessThan(setup.entry.min);
        }
        expect(setup.riskReward).toBeGreaterThan(0);
        expect(new Date(setup.expiration).getTime()).toBeGreaterThan(Date.now());
        // Risk engine MUST have run — with synthetic data it vetoes (Rule 2 + 6)
        expect(run.riskDecision).not.toBeNull();
        expect(run.riskDecision!.approved).toBe(false);
        expect(run.riskDecision!.reasons.some((r) => /SYNTHETIC/.test(r))).toBe(true);
        break;
      }
    }
    expect(foundSetup).toBe(true);
  });

  it('vetoes every setup while the kill switch is active', async () => {
    engine.setKillSwitch(true, 'test');
    riskEngine.updateLimits({ blockSyntheticData: false }); // isolate the kill-switch check
    try {
      const run = await engine.run({ symbol: 'ETHUSDT', marketClass: 'crypto', timeframe: '1h' });
      if (run.riskDecision) {
        expect(run.riskDecision.approved).toBe(false);
        expect(run.riskDecision.reasons.some((r) => /Kill switch/.test(r))).toBe(true);
      }
    } finally {
      engine.setKillSwitch(false, 'test done');
      riskEngine.updateLimits({ blockSyntheticData: true });
    }
  });

  it('keeps history and serves runs by id', async () => {
    const run = await engine.run({ symbol: 'XRPUSDT', marketClass: 'crypto', timeframe: '15m' });
    expect(engine.getRun(run.id)?.id).toBe(run.id);
    expect(engine.history(5).some((r) => r.id === run.id)).toBe(true);
  });

  it('refuses unimplemented trading modes with an honest message (Rule 3/4)', () => {
    const res = engine.setTradingMode('FULLY_AUTOMATED');
    expect(res.ok).toBe(false);
    expect(res.message).toMatch(/not implemented in Phase 1/);
    const ok = engine.setTradingMode('ANALYSIS_ONLY');
    expect(ok.ok).toBe(true);
  });

  it('emits audit events for the critical path (Rule 5)', async () => {
    await engine.run({ symbol: 'BNBUSDT', marketClass: 'crypto', timeframe: '1h' });
    const types = eventBus.recent(50).map((e) => e.type);
    expect(types).toContain('TRADE_ANALYZED');
    expect(types.some((t) => t === 'SIGNAL_GENERATED' || t === 'NO_SIGNAL')).toBe(true);
  });

  it('consensus endpoint returns one summary per requested symbol', async () => {
    const summaries = await engine.consensus([
      { symbol: 'BTCUSDT', marketClass: 'crypto', timeframe: '1h' },
      { symbol: 'EURUSD', marketClass: 'forex', timeframe: '1h' },
    ]);
    expect(summaries).toHaveLength(2);
    expect(summaries.map((s) => s.symbol).sort()).toEqual(['BTCUSDT', 'EURUSD']);
    for (const s of summaries) {
      expect(s.synthetic).toBe(true);
      expect(s.bias).toMatch(/BULLISH|BEARISH|NEUTRAL|NO_TRADE/);
    }
  });
});

describe('AnalysisRun shape (spec contract)', () => {
  it('matches the documented output contract', async () => {
    const { engine } = createPlatform({ disableRealProviders: true });
    const run: AnalysisRun = await engine.run({ symbol: 'BTCUSDT', marketClass: 'crypto', timeframe: '1h' });
    expect(Object.keys(run)).toEqual(
      expect.arrayContaining([
        'symbol', 'timeframe', 'marketRegime', 'bias', 'confidence', 'confluence',
        'signals', 'scenarios', 'tradeSetup', 'riskDecision', 'agents', 'provenance',
      ]),
    );
    expect(Object.keys(run.scenarios)).toEqual(expect.arrayContaining(['bullish', 'bearish', 'neutral']));
  });
});
