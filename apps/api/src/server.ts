import Fastify, { type FastifyInstance } from 'fastify';
import cors from '@fastify/cors';
import { z } from 'zod';
import {
  createPlatform, INTEGRATION_STATUS, MARKET_CLASS_SYMBOLS, TRADING_MODES,
  IMPLEMENTED_TRADING_MODES,
} from '@aegis/core';
import type { MarketClass, Timeframe } from '@aegis/core';
import path from 'node:path';

export interface BuildOptions {
  auditFilePath?: string;
  disableRealProviders?: boolean;
}

export function buildApp(opts: BuildOptions = {}) {
  const app = Fastify({ logger: { level: process.env.LOG_LEVEL ?? 'info' } });
  const { engine, providerManager, riskEngine, eventBus } = createPlatform({
    auditFilePath: opts.auditFilePath ?? path.join(process.cwd(), 'data', 'audit-log.jsonl'),
    disableRealProviders: opts.disableRealProviders,
  });

  void (async () => {
    await app.register(cors, { origin: true });
  })();

  const analysisRequestSchema = z.object({
    symbol: z.string().min(2).max(20),
    marketClass: z.enum(['forex', 'crypto', 'stock', 'etf', 'commodity', 'futures', 'options', 'indices', 'bonds']),
    timeframe: z.enum(['1m', '5m', '15m', '1h', '4h', '1d']),
  });

  const errorMsg = (message: string) => ({ error: message });

  // ---------------------------------------------------------------- system --
  app.get('/api/system/status', async () => ({
    platform: 'AEGIS Trading Intelligence',
    phase: 1,
    version: '0.1.0',
    tradingMode: engine.platform.tradingMode,
    implementedTradingModes: IMPLEMENTED_TRADING_MODES,
    supportedTradingModes: TRADING_MODES,
    killSwitch: engine.platform.killSwitch,
    providers: await providerManager.getAllHealth(),
    cache: providerManager.cacheStats(),
    eventBufferSize: eventBus.bufferSize,
    time: new Date().toISOString(),
  }));

  app.get('/api/system/features', async () => INTEGRATION_STATUS);

  app.get('/api/events', async (req) => {
    const limit = Math.min(Number((req.query as { limit?: string }).limit ?? 100) || 100, 500);
    return { events: eventBus.recent(limit) };
  });

  // ----------------------------------------------------------- market data --
  app.get('/api/market-data/providers', async () => ({
    providers: await providerManager.getAllHealth(true),
    registry: providerManager.listProviders().map((p) => ({
      name: p.name,
      synthetic: p.synthetic,
      priority: p.priority,
      capabilities: p.capabilities(),
    })),
  }));

  app.get('/api/market-data/candles', async (req, reply) => {
    const q = z
      .object({
        symbol: z.string().min(2),
        timeframe: z.enum(['1m', '5m', '15m', '1h', '4h', '1d']).default('1h'),
        limit: z.coerce.number().int().min(30).max(500).default(200),
        marketClass: z.string().optional(),
      })
      .safeParse(req.query);
    if (!q.success) return reply.code(400).send(errorMsg(q.error.issues[0]?.message ?? 'invalid query'));
    const marketClass = (q.data.marketClass as MarketClass) ?? inferMarketClass(q.data.symbol);
    try {
      const series = await providerManager.getCandleSeries(
        q.data.symbol.toUpperCase(), marketClass, q.data.timeframe as Timeframe, q.data.limit,
      );
      return {
        symbol: series.symbol, marketClass: series.marketClass, timeframe: series.timeframe,
        candles: series.candles, provenance: series.provenance, validation: series.validation,
      };
    } catch (err) {
      return reply.code(502).send(errorMsg(err instanceof Error ? err.message : 'market data unavailable'));
    }
  });

  app.get('/api/market-data/quote', async (req, reply) => {
    const q = z.object({ symbol: z.string().min(2) }).safeParse(req.query);
    if (!q.success) return reply.code(400).send(errorMsg('symbol required'));
    try {
      const { quote, provider, failedProviders } = await providerManager.getQuote(q.data.symbol.toUpperCase());
      return {
        quote,
        provenance: { source: provider.name, synthetic: provider.synthetic, fallbackChain: failedProviders },
      };
    } catch (err) {
      return reply.code(502).send(errorMsg(err instanceof Error ? err.message : 'quote unavailable'));
    }
  });

  // --------------------------------------------------------------- analysis --
  app.post('/api/analysis/run', async (req, reply) => {
    const parsed = analysisRequestSchema.safeParse(req.body);
    if (!parsed.success) {
      return reply.code(400).send({ error: 'Invalid request', issues: parsed.error.issues.map((i) => ({ path: i.path, message: i.message })) });
    }
    try {
      const run = await engine.run({
        symbol: parsed.data.symbol.toUpperCase(),
        marketClass: parsed.data.marketClass as MarketClass,
        timeframe: parsed.data.timeframe as Timeframe,
      });
      return run;
    } catch (err) {
      return reply.code(502).send(errorMsg(err instanceof Error ? err.message : 'analysis failed'));
    }
  });

  app.get('/api/analysis/history', async (req) => {
    const limit = Math.min(Number((req.query as { limit?: string }).limit ?? 20) || 20, 100);
    return {
      runs: engine.history(limit).map((r) => ({
        id: r.id, symbol: r.symbol, timeframe: r.timeframe, bias: r.bias,
        confidence: r.confidence, confluence: r.confluence, regime: r.marketRegime,
        recommendation: r.recommendation, synthetic: r.provenance.synthetic,
        source: r.provenance.source, completedAt: r.completedAt,
      })),
    };
  });

  app.get('/api/analysis/:id', async (req, reply) => {
    const { id } = req.params as { id: string };
    const run = engine.getRun(id);
    if (!run) return reply.code(404).send(errorMsg('analysis run not found'));
    return run;
  });

  // ----------------------------------------------------------------- agents --
  app.get('/api/agents', async () => ({ agents: engine.listAgents() }));

  app.post('/api/agents/consensus', async (req, reply) => {
    const parsed = z
      .object({
        requests: z.array(analysisRequestSchema).min(1).max(12).optional(),
        symbols: z.array(z.string()).min(1).max(12).optional(),
        timeframe: z.enum(['1m', '5m', '15m', '1h', '4h', '1d']).default('1h'),
      })
      .safeParse(req.body ?? {});
    if (!parsed.success) return reply.code(400).send(errorMsg('invalid consensus request'));
    let requests = parsed.data.requests;
    if (!requests) {
      const symbols = parsed.data.symbols ?? defaultWatchlist();
      requests = symbols.map((symbol) => ({
        symbol,
        marketClass: inferMarketClass(symbol),
        timeframe: parsed.data!.timeframe as Timeframe,
      }));
    }
    return { generatedAt: new Date().toISOString(), consensus: await engine.consensus(requests) };
  });

  // ------------------------------------------------------------------- risk --
  app.get('/api/risk/limits', async () => ({ limits: riskEngine.getLimits(), portfolio: riskEngine.getPortfolio() }));

  const riskLimitsSchema = z.object({
    riskPerTradePct: z.number().min(0).max(1).optional(),
    maxRiskPerTradePct: z.number().min(0).max(1).optional(),
    minRiskReward: z.number().min(0).max(100).optional(),
    maxPositionNotionalUsd: z.number().min(0).optional(),
    maxLeverage: z.number().min(1).max(100).optional(),
    maxOpenPositions: z.number().int().min(1).optional(),
    maxDailyLossPct: z.number().min(0).max(1).optional(),
    maxWeeklyLossPct: z.number().min(0).max(1).optional(),
    maxDrawdownPct: z.number().min(0).max(1).optional(),
    maxSymbolExposurePct: z.number().min(0).max(1).optional(),
    maxPortfolioExposurePct: z.number().min(0).max(1).optional(),
    minDataQuality: z.number().min(0).max(1).optional(),
    blockSyntheticData: z.boolean().optional(),
    blockStaleData: z.boolean().optional(),
  });

  app.post('/api/risk/limits', async (req, reply) => {
    const parsed = riskLimitsSchema.safeParse(req.body);
    if (!parsed.success) return reply.code(400).send(errorMsg('invalid risk limits'));
    return { limits: engine.updateRiskLimits(parsed.data) };
  });

  // ---------------------------------------------------------------- trading --
  app.post('/api/trading/kill-switch', async (req, reply) => {
    const parsed = z.object({ active: z.boolean(), reason: z.string().max(200).optional() }).safeParse(req.body ?? {});
    if (!parsed.success) return reply.code(400).send(errorMsg('body must be { active: boolean, reason?: string }'));
    engine.setKillSwitch(parsed.data.active, parsed.data.reason ?? null, 'user');
    return { killSwitch: engine.platform.killSwitch };
  });

  app.post('/api/trading/mode', async (req, reply) => {
    const parsed = z.object({ mode: z.enum(['ANALYSIS_ONLY', 'PAPER_TRADING', 'HUMAN_APPROVAL', 'SEMI_AUTONOMOUS', 'FULLY_AUTOMATED']) }).safeParse(req.body);
    if (!parsed.success) return reply.code(400).send(errorMsg('mode required'));
    const result = engine.setTradingMode(parsed.data.mode, 'user');
    if (!result.ok) return reply.code(409).send(errorMsg(result.message));
    return { tradingMode: engine.platform.tradingMode, message: result.message };
  });

  return app;
}

function defaultWatchlist(): string[] {
  return ['EURUSD', 'GBPUSD', 'USDJPY', 'XAUUSD', 'BTCUSDT', 'ETHUSDT', 'SOLUSDT'];
}

function inferMarketClass(symbol: string): MarketClass {
  const s = symbol.toUpperCase();
  const known = Object.entries(MARKET_CLASS_SYMBOLS).find(([, symbols]) => symbols.includes(s));
  if (known) return known[0] as MarketClass;
  if (s.endsWith('USDT')) return 'crypto';
  return 'forex';
}
