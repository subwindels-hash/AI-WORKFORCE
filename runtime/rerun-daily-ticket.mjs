/**
 * Re-runs the daily sports ticket engine on the offline dev runtime and
 * prints the verification a CALIBRATION_PERSIST_FAILED alert asks for:
 *
 *   1. the sports_calibrations rows — method column content vs the bootstrap
 *      marker, status, intercept/slope, actors;
 *   2. the stored daily-ticket row (status, funnel, rejection summary);
 *   3. the durable audit trail around the run (and the CI error log, the
 *      dev-runtime equivalent of "the DB error log").
 *
 * Dev bridge only (like run-tests.mjs): hosts the same CodeIgniter app in
 * WASM PHP on the pdo_sqlite driver. The real (non-simulated) providers need
 * outbound network the sandbox does not have, so the run uses the clearly
 * labeled SANDBOX simulation provider — same engine, same gates, same
 * storage path; fixtures are marked simulated.
 *
 *   node runtime/rerun-daily-ticket.mjs                 — today (UTC)
 *   node runtime/rerun-daily-ticket.mjs 2026-09-10      — a specific date
 */
import path from 'node:path';
import fs from 'node:fs';
import { loadNodeRuntime, useHostFilesystem } from '@php-wasm/node';
import { PHP, ProcessIdAllocator } from '@php-wasm/universal';

const APP_ROOT = path.resolve(new URL('..', import.meta.url).pathname);
const DATE = /^\d{4}-\d{2}-\d{2}$/.test(process.argv[2] ?? '') ? process.argv[2] : new Date().toISOString().slice(0, 10);
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
  // SANDBOX simulation provider — the offline equivalent of the deployed
  // fixture feed; every record it emits is labeled simulated.
  'WINDELS_SPORTS_MODE=SANDBOX',
  'WINDELS_SPORTS_SANDBOX=1',
];

/** One fresh PHP process per step (CI3's front controller is not re-entrant). */
async function runPhp(code) {
  const result = await php.run({ code }).catch((e) => ({ text: 'RUN-THREW: ' + String(e?.message ?? e) }));
  return result.text ?? '';
}

// ── Step 1: schema install/upgrade (idempotent; widens narrow columns) ─────
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
  console.error('SCHEMA INSTALL FAILED');
  process.exit(2);
}
console.log('schema: installed/upgraded (' + (install.match(/OK — \d+ tables verified\./)?.[0] ?? 'verified') + ')');

// ── Step 2: the re-run itself — the same entry point cron uses ─────────────
const ticket = await runPhp(`<?php
chdir('${root}');
${env.map((e) => `putenv('${e}');`).join('\n')}
define('STDIN', fopen('php://stdin', 'r'));
define('STDOUT', fopen('php://stdout', 'w'));
define('STDERR', fopen('php://stderr', 'w'));
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
$_SERVER['argv'] = ['index.php', 'tools', 'sports_cron', 'ticket', '${DATE}'];
$_SERVER['argc'] = 5;
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require '${root}/index.php';
`);
console.log('\n=== daily ticket job (' + DATE + ') ===');
console.log(ticket.trim());

// ── Step 3: verification the alert asked for — through the app's own DB ────
const verify = await runPhp(`<?php
chdir('${root}');
${env.map((e) => `putenv('${e}');`).join('\n')}
define('STDIN', fopen('php://stdin', 'r'));
define('STDOUT', fopen('php://stdout', 'w'));
define('STDERR', fopen('php://stderr', 'w'));
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
$_SERVER['argv'] = ['index.php', 'tools', 'sports_calibration_check', '${DATE}'];
$_SERVER['argc'] = 4;
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require '${root}/index.php';
`);
console.log('\n=== verification: sports_calibrations + stored run + DB error ledger + audit trail ===');
console.log(verify.trim());

// ── Step 4: the CI application log (the dev-runtime "DB error log") ────────
const logFile = path.join(APP_ROOT, 'application', 'logs', 'log-' + new Date().toISOString().slice(0, 10) + '.php');
console.log('\n=== CI application log (today) ===');
if (fs.existsSync(logFile)) {
  const lines = fs.readFileSync(logFile, 'utf8').split('\n').filter(Boolean);
  console.log(lines.slice(-15).join('\n'));
} else {
  console.log('(no log file — no errors were recorded today)');
}
