import Fastify, { type FastifyInstance } from 'fastify';
import cors from '@fastify/cors';
import { createPlatform } from '@aegis/core';
import path from 'node:path';
import { registerCoreRoutes } from './routes/core';
import { registerPhase2Routes } from './routes/phase2';

export interface BuildOptions {
  auditFilePath?: string;
  disableRealProviders?: boolean;
  /** Persistent store file (JSON). Omit = in-memory (tests). */
  storeFilePath?: string;
}

export async function buildApp(opts: BuildOptions = {}): Promise<FastifyInstance> {
  const app = Fastify({ logger: { level: process.env.LOG_LEVEL ?? 'info' } });
  const platform = await createPlatform({
    auditFilePath: opts.auditFilePath,
    disableRealProviders: opts.disableRealProviders,
    storeFilePath: opts.storeFilePath,
  });

  await app.register(cors, { origin: true });

  registerCoreRoutes(app, {
    engine: platform.engine,
    providerManager: platform.providerManager,
    riskEngine: platform.riskEngine,
    eventBus: platform.eventBus,
  });

  registerPhase2Routes(app, {
    store: platform.store,
    strategyRegistry: platform.strategyRegistry,
    eventBus: platform.eventBus,
    runBacktest: platform.runBacktest as never,
  });

  return app;
}
