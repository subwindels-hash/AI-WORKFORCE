import path from 'node:path';
import { buildApp } from './server';

const port = Number(process.env.PORT ?? 4000);
const host = process.env.HOST ?? '0.0.0.0';

async function main(): Promise<void> {
  const app = await buildApp({
    auditFilePath: path.join(process.cwd(), 'data', 'audit-log.jsonl'),
    storeFilePath: process.env.AEGIS_MEMORY_STORE === '1'
      ? undefined
      : path.join(process.cwd(), 'data', 'platform-store.json'),
    disableRealProviders: process.env.DISABLE_REAL_PROVIDERS === '1',
  });

  await app.listen({ port, host });
  app.log.info(`AEGIS API listening on ${host}:${port} (Phase 2: ANALYSIS_ONLY + strategy lab, no live trading)`);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
