import path from 'node:path';
import { buildApp } from './server';

const port = Number(process.env.PORT ?? 4000);
const host = process.env.HOST ?? '0.0.0.0';

const app = buildApp({
  auditFilePath: path.join(process.cwd(), 'data', 'audit-log.jsonl'),
  disableRealProviders: process.env.DISABLE_REAL_PROVIDERS === '1',
});

app.listen({ port, host }).then(() => {
  app.log.info(`AEGIS API listening on ${host}:${port} (ANALYSIS_ONLY, Phase 1)`);
}).catch((err) => {
  app.log.error(err);
  process.exit(1);
});
