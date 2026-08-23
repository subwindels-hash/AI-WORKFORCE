import { describe, expect, it, beforeAll, afterAll } from 'vitest';
import { buildApp } from '../src/server';
import type { FastifyInstance } from 'fastify';

/**
 * API integration tests — run against the real app with real providers
 * disabled so they are deterministic offline (sandbox has no market-data
 * egress). The synthetic fallback is asserted end-to-end through HTTP.
 */
let app: FastifyInstance;

beforeAll(async () => {
  app = await buildApp({ auditFilePath: undefined, disableRealProviders: true });
});

afterAll(async () => {
  await app.close();
});

describe('system endpoints', () => {
  it('GET /api/system/status reports ANALYSIS_ONLY with kill switch ON by default', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/system/status' });
    expect(res.statusCode).toBe(200);
    const body = res.json();
    expect(body.tradingMode).toBe('ANALYSIS_ONLY');
    expect(body.killSwitch.active).toBe(true);
    expect(body.phase).toBe(2);
    expect(Array.isArray(body.providers)).toBe(true);
  });

  it('GET /api/system/features returns the honesty matrix with tested/planned statuses', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/system/features' });
    expect(res.statusCode).toBe(200);
    const features = res.json();
    const statuses = new Set(features.map((f: { status: string }) => f.status));
    expect(statuses.has('TESTED')).toBe(true);
    expect(statuses.has('PLANNED')).toBe(true);
    // Brokers must not claim to work in Phase 1
    const mt5 = features.find((f: { name: string }) => f.name.includes('MT5'));
    expect(mt5.status).toBe('PLANNED');
  });

  it('GET /api/events returns the audit trail', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/events?limit=10' });
    expect(res.statusCode).toBe(200);
    expect(Array.isArray(res.json().events)).toBe(true);
  });
});

describe('market-data endpoints', () => {
  it('GET /api/market-data/candles serves normalized candles with provenance', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/market-data/candles?symbol=BTCUSDT&timeframe=1h&limit=120' });
    expect(res.statusCode).toBe(200);
    const body = res.json();
    expect(body.candles.length).toBe(120);
    expect(body.provenance.synthetic).toBe(true);
    expect(body.provenance.source).toBe('synthetic-demo');
    expect(body.validation.ok).toBe(true);
  });

  it('rejects invalid query parameters', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/market-data/candles?symbol=&timeframe=1h' });
    expect(res.statusCode).toBe(400);
  });

  it('GET /api/market-data/providers lists the registry with capabilities', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/market-data/providers' });
    const body = res.json();
    expect(body.registry.length).toBe(1); // real providers disabled in this suite
    expect(body.registry[0].synthetic).toBe(true);
  });

  it('GET /api/market-data/quote returns a quote with source', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/market-data/quote?symbol=EURUSD' });
    expect(res.statusCode).toBe(200);
    expect(res.json().quote.last).toBeGreaterThan(0);
    expect(res.json().provenance.source).toBe('synthetic-demo');
  });
});

describe('analysis endpoints', () => {
  it('POST /api/analysis/run returns the full analysis contract', async () => {
    const res = await app.inject({
      method: 'POST',
      url: '/api/analysis/run',
      payload: { symbol: 'BTCUSDT', marketClass: 'crypto', timeframe: '1h' },
    });
    expect(res.statusCode).toBe(200);
    const run = res.json();
    expect(run.symbol).toBe('BTCUSDT');
    expect(run.marketRegime).toBeTruthy();
    expect(run.provenance.synthetic).toBe(true);
    expect(run.agents.length).toBeGreaterThanOrEqual(3);
    expect(run.scenarios.bullish).toBeTruthy();
  });

  it('validates the request body', async () => {
    const res = await app.inject({ method: 'POST', url: '/api/analysis/run', payload: { symbol: 'X' } });
    expect(res.statusCode).toBe(400);
  });

  it('history and by-id lookups work', async () => {
    const run = await app.inject({
      method: 'POST', url: '/api/analysis/run',
      payload: { symbol: 'ETHUSDT', marketClass: 'crypto', timeframe: '1h' },
    }).then((r) => r.json());

    const history = await app.inject({ method: 'GET', url: '/api/analysis/history' }).then((r) => r.json());
    expect(history.runs.some((h: { id: string }) => h.id === run.id)).toBe(true);

    const byId = await app.inject({ method: 'GET', url: `/api/analysis/${run.id}` });
    expect(byId.statusCode).toBe(200);
    expect(byId.json().id).toBe(run.id);

    const missing = await app.inject({ method: 'GET', url: '/api/analysis/nope' });
    expect(missing.statusCode).toBe(404);
  });
});

describe('agent endpoints', () => {
  it('GET /api/agents lists registered agents', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/agents' });
    const ids = res.json().agents.map((a: { id: string }) => a.id);
    expect(ids).toEqual(expect.arrayContaining(['technical', 'market-structure', 'forex', 'crypto', 'sentiment']));
  });

  it('POST /api/agents/consensus summarizes the watchlist', async () => {
    const res = await app.inject({
      method: 'POST',
      url: '/api/agents/consensus',
      payload: { symbols: ['BTCUSDT', 'EURUSD'], timeframe: '1h' },
    });
    expect(res.statusCode).toBe(200);
    const body = res.json();
    expect(body.consensus).toHaveLength(2);
    for (const c of body.consensus) {
      expect(c.bias).toMatch(/BULLISH|BEARISH|NEUTRAL|NO_TRADE/);
      expect(c.synthetic).toBe(true);
    }
  });
});

describe('risk & trading governance', () => {
  it('GET /api/risk/limits returns the safety defaults', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/risk/limits' });
    const body = res.json();
    expect(body.limits.riskPerTradePct).toBe(0.01);
    expect(body.limits.blockSyntheticData).toBe(true);
  });

  it('POST /api/risk/limits updates limits and rejects invalid payloads', async () => {
    const ok = await app.inject({ method: 'POST', url: '/api/risk/limits', payload: { minRiskReward: 2.0 } });
    expect(ok.statusCode).toBe(200);
    expect(ok.json().limits.minRiskReward).toBe(2.0);

    const bad = await app.inject({ method: 'POST', url: '/api/risk/limits', payload: { riskPerTradePct: 99 } });
    expect(bad.statusCode).toBe(400);
  });

  it('kill switch toggles are audited', async () => {
    const res = await app.inject({ method: 'POST', url: '/api/trading/kill-switch', payload: { active: false, reason: 'test release' } });
    expect(res.statusCode).toBe(200);
    expect(res.json().killSwitch.active).toBe(false);

    const events = await app.inject({ method: 'GET', url: '/api/events?limit=20' }).then((r) => r.json());
    expect(events.events.some((e: { type: string }) => e.type === 'KILL_SWITCH_DEACTIVATED')).toBe(true);

    await app.inject({ method: 'POST', url: '/api/trading/kill-switch', payload: { active: true, reason: 'restore' } });
  });

  it('refuses to enable unimplemented trading modes', async () => {
    const res = await app.inject({ method: 'POST', url: '/api/trading/mode', payload: { mode: 'FULLY_AUTOMATED' } });
    expect(res.statusCode).toBe(409);
    expect(res.json().error).toMatch(/Phase 1/);
  });
});
