import { describe, expect, it, beforeAll, afterAll } from 'vitest';
import { buildApp } from '../src/server';
import type { FastifyInstance } from 'fastify';

let app: FastifyInstance;

beforeAll(async () => {
  app = await buildApp({ auditFilePath: undefined, disableRealProviders: true });
});

afterAll(async () => {
  await app.close();
});

describe('strategies endpoints', () => {
  it('lists the four built-in strategies as DRAFT with version info', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/strategies' });
    expect(res.statusCode).toBe(200);
    const body = res.json();
    const ids = body.strategies.map((s: { strategyId: string }) => s.strategyId).sort();
    expect(ids).toEqual(['breakout', 'mean-reversion', 'momentum', 'trend-following']);
    for (const s of body.strategies) {
      expect(s.latest.lifecycle).toBe('DRAFT');
      expect(s.latest.params.stopAtr).toBeGreaterThan(0);
      expect(s.versions).toHaveLength(1);
    }
  });

  it('returns strategy detail with next stage and honest blocked stages', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/strategies/trend-following' });
    expect(res.statusCode).toBe(200);
    const body = res.json();
    expect(body.supportsShorts).toBe(true);
    expect(body.nextStage).toBe('BACKTESTED');
    expect(body.blockedStages.PAPER_TRADING).toMatch(/Phase 3/);
    expect(body.blockedStages.APPROVED).toMatch(/Phase 4–5/);
  });

  it('404s for unknown strategies', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/strategies/nope' });
    expect(res.statusCode).toBe(404);
  });

  it('rejects lifecycle transitions without evidence (409)', async () => {
    const res = await app.inject({
      method: 'POST', url: '/api/strategies/trend-following/status',
      payload: { to: 'BACKTESTED' },
    });
    expect(res.statusCode).toBe(409);
    expect(res.json().reasons[0]).toMatch(/No completed backtest/);
  });

  it('rejects invalid transition payloads (400)', async () => {
    const res = await app.inject({
      method: 'POST', url: '/api/strategies/trend-following/status',
      payload: { to: 'NOT_A_STAGE' },
    });
    expect(res.statusCode).toBe(400);
  });
});

describe('backtesting endpoints', () => {
  it('runs a full backtest on synthetic data and records everything', async () => {
    const res = await app.inject({
      method: 'POST', url: '/api/backtesting/run',
      payload: {
        strategyId: 'trend-following',
        symbol: 'BTCUSDT',
        marketClass: 'crypto',
        timeframe: '1h',
        limit: 1500,
      },
    });
    expect(res.statusCode).toBe(200);
    const body = res.json();
    expect(body.dataProvenance.synthetic).toBe(true);
    expect(body.warnings.some((w: string) => /SYNTHETIC/.test(w))).toBe(true);
    expect(body.metrics).toMatchObject({
      trades: expect.any(Number),
      totalReturnPct: expect.any(Number),
      maxDrawdownPct: expect.any(Number),
    });
    expect(body.equityCurve.length).toBeGreaterThan(100);
    expect(Array.isArray(body.trades)).toBe(true);
    // lifecycle gate now passes
    const promote = await app.inject({
      method: 'POST', url: '/api/strategies/trend-following/status',
      payload: { to: 'BACKTESTED' },
    });
    expect(promote.statusCode).toBe(200);
    expect(promote.json().strategy.lifecycle).toBe('BACKTESTED');
  });

  it('validates request payloads', async () => {
    const res = await app.inject({
      method: 'POST', url: '/api/backtesting/run',
      payload: { strategyId: 'x' },
    });
    expect(res.statusCode).toBe(400);
  });

  it('404s for unknown strategies', async () => {
    const res = await app.inject({
      method: 'POST', url: '/api/backtesting/run',
      payload: { strategyId: 'does-not-exist', symbol: 'BTCUSDT', marketClass: 'crypto', timeframe: '1h' },
    });
    expect(res.statusCode).toBe(404);
  });

  it('lists persisted results with summaries', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/backtesting/results' });
    expect(res.statusCode).toBe(200);
    const body = res.json();
    expect(body.results.length).toBeGreaterThanOrEqual(1);
    const first = body.results[0];
    expect(first).toHaveProperty('metrics');
    expect(first).toHaveProperty('synthetic');

    const detail = await app.inject({ method: 'GET', url: `/api/backtesting/results/${first.id}` });
    expect(detail.statusCode).toBe(200);
    expect(detail.json().id).toBe(first.id);
  });
});

describe('journal & analytics endpoints', () => {
  it('lists backtest-sourced journal entries', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/journal?source=backtest' });
    expect(res.statusCode).toBe(200);
    const body = res.json();
    expect(body.entries.length).toBeGreaterThanOrEqual(1);
    const e = body.entries[0];
    expect(e).toMatchObject({
      source: 'backtest',
      symbol: 'BTCUSDT',
      strategy: 'trend-following',
      confidenceSource: 'strategy',
    });
    expect(e.aiConfidence).toBeGreaterThanOrEqual(0);
    expect(e.reasonForTrade).toBeTruthy();
  });

  it('records a validated manual journal entry', async () => {
    const res = await app.inject({
      method: 'POST', url: '/api/journal',
      payload: {
        symbol: 'EURUSD',
        market: 'forex',
        direction: 'LONG',
        entryTime: '2026-08-20T10:00:00.000Z',
        entryPrice: 1.086,
        exitTime: '2026-08-20T14:00:00.000Z',
        exitPrice: 1.0915,
        positionSize: 10000,
        stopLoss: 1.083,
        fees: 3,
        reasonForTrade: 'Manual journal import — test entry',
        aiConfidence: 0.72,
        confidenceSource: 'manual',
      },
    });
    expect(res.statusCode).toBe(201);
    const body = res.json();
    expect(body.pnl).toBeCloseTo((1.0915 - 1.086) * 10000 - 3, 6);
    expect(body.rMultiple).toBeGreaterThan(0);
  });

  it('rejects inconsistent manual entries', async () => {
    const res = await app.inject({
      method: 'POST', url: '/api/journal',
      payload: {
        symbol: 'EURUSD', market: 'forex', direction: 'LONG',
        entryTime: '2026-08-20T10:00:00.000Z', entryPrice: 1.086,
        exitTime: '2026-08-19T10:00:00.000Z', exitPrice: 1.09,
        positionSize: 1000, reasonForTrade: 'bad times',
      },
    });
    expect(res.statusCode).toBe(400);
  });

  it('summarizes analytics grouped by strategy', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/analytics/summary?groupBy=strategy' });
    expect(res.statusCode).toBe(200);
    const body = res.json();
    expect(body.groupBy).toBe('strategy');
    expect(body.overall.closedTrades).toBeGreaterThanOrEqual(1);
    expect(body.groups.some((g: { key: string }) => g.key === 'trend-following')).toBe(true);
  });

  it('answers the confidence calibration question', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/analytics/confidence-calibration' });
    expect(res.statusCode).toBe(200);
    const body = res.json();
    expect(body).toHaveProperty('verdict');
    expect(body).toHaveProperty('sufficientData');
    expect(Array.isArray(body.buckets)).toBe(true);
  });
});

describe('phase 2 audit events', () => {
  it('emits strategy and backtest events into the audit trail', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/events?limit=100' });
    const types = res.json().events.map((e: { type: string }) => e.type);
    expect(types).toContain('STRATEGY_REGISTERED');
    expect(types).toContain('BACKTEST_STARTED');
    expect(types).toContain('BACKTEST_COMPLETED');
  });
});
