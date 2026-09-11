/**
 * Runs the Odds Prediction Ticket Engine for a date on the offline dev
 * runtime and prints the COMPLETE funnel, the selection-tier trace, the
 * per-candidate decision table and the generated ticket (when the data
 * supports one).
 *
 * This is the diagnostic view requirement #14 asks for:
 *
 *   Fixture -> Market -> Model Probability -> Confidence -> Data Quality
 *           -> Odds -> Value -> Risk -> Correlation -> Final Decision
 *
 * Dev bridge only (same approach as run-tests.mjs / rerun-daily-ticket.mjs):
 * it hosts the same CodeIgniter app in WASM PHP on the pdo_sqlite driver. The
 * real providers need outbound network the sandbox does not have, so the run
 * uses the clearly labeled SANDBOX simulation provider — same engine, same
 * gates, same storage path; its fixtures are marked simulated.
 *
 *   node runtime/show-ticket-funnel.mjs                 — today (UTC)
 *   node runtime/show-ticket-funnel.mjs 2026-09-11
 *   node runtime/show-ticket-funnel.mjs 2026-09-11 --force
 */
import path from 'node:path';
import fs from 'node:fs';
import { loadNodeRuntime, useHostFilesystem } from '@php-wasm/node';
import { PHP, ProcessIdAllocator } from '@php-wasm/universal';

const APP_ROOT = path.resolve(new URL('..', import.meta.url).pathname);
const DATE = /^\d{4}-\d{2}-\d{2}$/.test(process.argv[2] ?? '') ? process.argv[2] : new Date().toISOString().slice(0, 10);
const FORCE = process.argv.includes('--force');
const DB_PATH = path.join(APP_ROOT, 'application', 'data', 'ai_workforce.sqlite');
fs.mkdirSync(path.dirname(DB_PATH), { recursive: true });

const allocator = new ProcessIdAllocator();
const runtime = await loadNodeRuntime(process.env.PHP_VERSION ?? '8.2', {
  emscriptenOptions: { processId: allocator.claim() },
});
const php = new PHP(runtime);
useHostFilesystem(php);

const root = APP_ROOT.replaceAll("'", "\\'");
const env = [
  'AI_WORKFORCE_DB_DRIVER=pdo_sqlite',
  `AI_WORKFORCE_SQLITE_PATH=${DB_PATH}`,
  'WINDELS_SPORTS_MODE=SANDBOX',
  'WINDELS_SPORTS_SANDBOX=1',
];

async function runPhp(code) {
  const result = await php.run({ code }).catch((e) => ({ text: 'RUN-THREW: ' + String(e?.message ?? e) }));
  return result.text ?? '';
}

const install = await runPhp(`<?php
chdir('${root}');
${env.map((e) => `putenv('${e}');`).join('\n')}
define('STDIN', fopen('php://stdin', 'r'));
$_SERVER['argv'] = ['index.php', 'tools', 'install'];
$_SERVER['argc'] = 3;
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require '${root}/tools/install.php';
`);
if (!/INSTALL-RESULT: 0/.test(install)) {
  console.error(install);
  process.exit(2);
}

const out = await runPhp(`<?php
chdir('${root}');
${env.map((e) => `putenv('${e}');`).join('\n')}
define('STDIN', fopen('php://stdin', 'r'));
define('STDOUT', fopen('php://stdout', 'w'));
define('STDERR', fopen('php://stderr', 'w'));
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
define('AI_WORKFORCE_NO_EXIT', 1);
$_SERVER['argv'] = ['index.php', 'tools', 'ticket_funnel', '${DATE}'${FORCE ? ", '--force'" : ''}];
$_SERVER['argc'] = count($_SERVER['argv']);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require '${root}/index.php';
`);
console.log(out.trim());
