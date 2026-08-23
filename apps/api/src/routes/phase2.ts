import type { FastifyInstance } from 'fastify';
import { z } from 'zod';
import { randomUUID } from 'node:crypto';
import type { DataStore, StrategyRegistry, EventBus } from '@aegis/core';
import { analyzeJournal, confidenceCalibration } from '@aegis/core';

export interface Phase2Deps {
  store: DataStore;
  strategyRegistry: StrategyRegistry;
  eventBus: EventBus;
  runBacktest: (input: Record<string, unknown> & { strategyId: string; strategyVersion: string; symbol: string; marketClass: string; timeframe: string }) => Promise<unknown>;
}

const marketClassEnum = z.enum(['forex', 'crypto', 'stock', 'etf', 'commodity', 'futures', 'options', 'indices', 'bonds']);
const timeframeEnum = z.enum(['1m', '5m', '15m', '1h', '4h', '1d']);
const lifecycleEnum = z.enum(['DRAFT', 'BACKTESTED', 'VALIDATED', 'RISK_REVIEWED', 'PAPER_TRADING', 'APPROVED', 'RETIRED']);

export function registerPhase2Routes(app: FastifyInstance, deps: Phase2Deps): void {
  const { store, strategyRegistry, eventBus } = deps;
  const errorMsg = (message: string) => ({ error: message });

  // ------------------------------------------------------------- strategies --
  app.get('/api/strategies', async () => {
    const versions = await store.listStrategies();
    const grouped = new Map<string, typeof versions>();
    for (const v of versions) {
      const list = grouped.get(v.strategyId) ?? [];
      list.push(v);
      grouped.set(v.strategyId, list);
    }
    return {
      strategies: [...grouped.entries()].map(([id, list]) => ({
        strategyId: id,
        latest: list[list.length - 1],
        versions: list.map((v) => ({ version: v.version, lifecycle: v.lifecycle, updatedAt: v.updatedAt })),
      })),
    };
  });

  app.get('/api/strategies/:id', async (req, reply) => {
    const { id } = req.params as { id: string };
    const version = (req.query as { version?: string }).version;
    const record = await store.getStrategy(id, version);
    if (!record) return reply.code(404).send(errorMsg(`strategy ${id} not found`));
    const impl = strategyRegistry.getImplementation(id, record.version);
    const next = record.lifecycle === 'RETIRED' ? null : nextStageOf(record.lifecycle);
    return {
      ...record,
      params: impl?.params ?? record.params,
      supportsShorts: impl?.supportsShorts ?? false,
      nextStage: next,
      blockedStages: {
        PAPER_TRADING: 'Phase 3 (paper trading engine)',
        APPROVED: 'Phase 4–5 (brokers + execution supervisor)',
      },
    };
  });

  app.post('/api/strategies/:id/status', async (req, reply) => {
    const { id } = req.params as { id: string };
    const parsed = z
      .object({ version: z.string().optional(), to: lifecycleEnum, reason: z.string().max(300).optional() })
      .safeParse({ ...(req.body as object), version: (req.body as { version?: string })?.version });
    if (!parsed.success) return reply.code(400).send(errorMsg('body must be { to, reason?, version? }'));
    const record = await store.getStrategy(id, parsed.data.version);
    if (!record) return reply.code(404).send(errorMsg(`strategy ${id} not found`));
    const result = await strategyRegistry.transition({
      strategyId: id,
      version: record.version,
      to: parsed.data.to,
      reason: parsed.data.reason,
    });
    if (!result.ok) return reply.code(409).send({ error: 'transition rejected', reasons: result.reasons, warnings: result.warnings });
    return { ok: true, strategy: result.record, warnings: result.warnings };
  });

  // ------------------------------------------------------------ backtesting --
  const backtestRequestSchema = z.object({
    strategyId: z.string().min(2),
    strategyVersion: z.string().optional(),
    symbol: z.string().min(2).max(20),
    marketClass: marketClassEnum,
    timeframe: timeframeEnum,
    from: z.string().datetime().optional(),
    to: z.string().datetime().optional(),
    limit: z.number().int().min(120).max(5000).optional(),
    initialEquity: z.number().positive().max(10_000_000).optional(),
    riskPct: z.number().positive().max(0.05).optional(),
    feeBps: z.number().min(0).max(200).optional(),
    spreadBps: z.number().min(0).max(200).optional(),
    slippageBps: z.number().min(0).max(200).optional(),
    allowShorts: z.boolean().optional(),
    warmupBars: z.number().int().min(10).max(400).optional(),
    maxBarsInTrade: z.number().int().min(0).max(2000).optional(),
  });

  app.post('/api/backtesting/run', async (req, reply) => {
    const parsed = backtestRequestSchema.safeParse(req.body);
    if (!parsed.success) {
      return reply.code(400).send({ error: 'invalid backtest request', issues: parsed.error.issues.map((i) => ({ path: i.path, message: i.message })) });
    }
    const body = parsed.data;
    const record = await strategyRegistry.getRecord(body.strategyId, body.strategyVersion);
    if (!record) return reply.code(404).send(errorMsg(`strategy ${body.strategyId} not found`));
    try {
      const result = await deps.runBacktest({
        ...body,
        strategyVersion: record.version,
        symbol: body.symbol.toUpperCase(),
      } as never);
      return result;
    } catch (err) {
      return reply.code(422).send(errorMsg(err instanceof Error ? err.message : 'backtest failed'));
    }
  });

  app.get('/api/backtesting/results', async (req) => {
    const q = req.query as { strategyId?: string; limit?: string };
    const results = await store.listBacktests({
      strategyId: q.strategyId,
      limit: Math.min(Number(q.limit ?? 20) || 20, 100),
    });
    return {
      results: results.map((r) => ({
        id: r.id,
        createdAt: r.createdAt,
        strategyId: r.request.strategyId,
        strategyVersion: r.request.strategyVersion,
        symbol: r.request.symbol,
        timeframe: r.request.timeframe,
        synthetic: r.dataProvenance.synthetic,
        candles: r.dataProvenance.candles,
        metrics: r.metrics,
        warnings: r.warnings,
      })),
    };
  });

  app.get('/api/backtesting/results/:id', async (req, reply) => {
    const { id } = req.params as { id: string };
    const result = await store.getBacktest(id);
    if (!result) return reply.code(404).send(errorMsg('backtest result not found'));
    return result;
  });

  // ---------------------------------------------------------------- journal --
  app.get('/api/journal', async (req) => {
    const q = req.query as { source?: 'backtest' | 'manual' | 'paper' | 'live'; strategy?: string; symbol?: string; limit?: string };
    const entries = await store.listEntries({
      source: q.source,
      strategy: q.strategy,
      symbol: q.symbol?.toUpperCase(),
      limit: Math.min(Number(q.limit ?? 100) || 100, 500),
    });
    return { entries };
  });

  const manualEntrySchema = z.object({
    symbol: z.string().min(2).max(20),
    market: marketClassEnum,
    direction: z.enum(['LONG', 'SHORT']),
    entryTime: z.string().datetime(),
    entryPrice: z.number().positive(),
    exitTime: z.string().datetime().optional(),
    exitPrice: z.number().positive().optional(),
    positionSize: z.number().positive(),
    stopLoss: z.number().positive().nullable().optional(),
    takeProfit: z.number().positive().nullable().optional(),
    fees: z.number().min(0).default(0),
    slippage: z.number().min(0).default(0),
    strategy: z.string().max(60).nullable().optional(),
    strategyVersion: z.string().max(20).nullable().optional(),
    reasonForTrade: z.string().min(3).max(500),
    aiConfidence: z.number().min(0).max(1).nullable().optional(),
    confidenceSource: z.enum(['strategy', 'ai-consensus', 'manual']).nullable().optional(),
    agentConsensus: z.string().max(120).nullable().optional(),
    riskScore: z.number().min(0).max(1).nullable().optional(),
    analysisRunId: z.string().uuid().nullable().optional(),
    notes: z.string().max(1000).optional(),
  });

  app.post('/api/journal', async (req, reply) => {
    const parsed = manualEntrySchema.safeParse(req.body);
    if (!parsed.success) {
      return reply.code(400).send({ error: 'invalid journal entry', issues: parsed.error.issues.map((i) => ({ path: i.path, message: i.message })) });
    }
    const b = parsed.data;
    if (b.exitTime && b.exitPrice) {
      if (Date.parse(b.exitTime) < Date.parse(b.entryTime)) {
        return reply.code(400).send(errorMsg('exitTime cannot precede entryTime'));
      }
    }
    const pnl = b.exitPrice !== undefined ? (b.direction === 'LONG' ? b.exitPrice - b.entryPrice : b.entryPrice - b.exitPrice) * b.positionSize - b.fees : null;
    const rMultiple = pnl !== null && b.stopLoss ? pnl / (Math.abs(b.entryPrice - b.stopLoss) * b.positionSize) : null;
    const entry = {
      id: randomUUID(),
      source: 'manual' as const,
      symbol: b.symbol.toUpperCase(),
      market: b.market,
      strategy: b.strategy ?? null,
      strategyVersion: b.strategyVersion ?? null,
      direction: b.direction,
      entry: { time: b.entryTime, price: b.entryPrice },
      exit: b.exitTime && b.exitPrice !== undefined ? { time: b.exitTime, price: b.exitPrice } : null,
      positionSize: b.positionSize,
      stopLoss: b.stopLoss ?? null,
      takeProfit: b.takeProfit ?? null,
      fees: b.fees,
      slippage: b.slippage,
      pnl,
      pnlPct: pnl !== null ? (pnl / (b.positionSize * b.entryPrice)) * 100 : null,
      rMultiple,
      reasonForTrade: b.reasonForTrade,
      aiConfidence: b.aiConfidence ?? null,
      confidenceSource: b.confidenceSource ?? (b.aiConfidence != null ? 'manual' : null),
      agentConsensus: b.agentConsensus ?? null,
      riskScore: b.riskScore ?? null,
      executionTime: b.entryTime,
      analysisRunId: b.analysisRunId ?? undefined,
      notes: b.notes,
    };
    await store.saveEntry(entry);
    eventBus.emit('JOURNAL_ENTRY_RECORDED', `Manual journal entry recorded for ${entry.symbol}`, { symbol: entry.symbol, id: entry.id });
    return reply.code(201).send(entry);
  });

  // -------------------------------------------------------------- analytics --
  app.get('/api/analytics/summary', async (req) => {
    const groupBy = (req.query as { groupBy?: string }).groupBy ?? 'strategy';
    const allowed = ['strategy', 'market', 'symbol', 'source', 'confidence'];
    if (!allowed.includes(groupBy)) return { error: `groupBy must be one of ${allowed.join(', ')}` };
    const entries = await store.listEntries({ limit: 500 });
    return analyzeJournal(entries, groupBy as 'strategy');
  });

  app.get('/api/analytics/confidence-calibration', async () => {
    const entries = await store.listEntries({ limit: 2000 });
    return confidenceCalibration(entries);
  });
}

function nextStageOf(lifecycle: string): string | null {
  const order = ['DRAFT', 'BACKTESTED', 'VALIDATED', 'RISK_REVIEWED', 'PAPER_TRADING', 'APPROVED'];
  const idx = order.indexOf(lifecycle);
  if (idx < 0 || idx >= order.length - 1) return null;
  return order[idx + 1];
}
