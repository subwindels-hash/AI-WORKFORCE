/**
 * Dev-only verification script: syncs today's football fixtures through the
 * SANDBOX provider (same simulation used by rerun-daily-ticket.mjs) and
 * prints the assembled board, so the /football console's logo/status
 * plumbing can be checked without a real provider key.
 *
 *   node runtime/rerun-football-board.mjs                 — today (UTC)
 *   node runtime/rerun-football-board.mjs 2026-09-12      — a specific date
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
  console.error('SCHEMA INSTALL FAILED');
  process.exit(2);
}
console.log('schema: installed/upgraded (' + (install.match(/OK — \d+ tables verified\./)?.[0] ?? 'verified') + ')');

const sync = await runPhp(`<?php
chdir('${root}');
${env.map((e) => `putenv('${e}');`).join('\n')}
define('STDIN', fopen('php://stdin', 'r'));
define('STDOUT', fopen('php://stdout', 'w'));
define('STDERR', fopen('php://stderr', 'w'));
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
$_SERVER['argv'] = ['index.php', 'tools', 'football_cron', 'fixtures', '--force'];
$_SERVER['argc'] = count($_SERVER['argv']);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require '${root}/index.php';
`);
console.log('\n=== football-cron fixtures --force ===');
console.log(sync.trim());

const predict = await runPhp(`<?php
chdir('${root}');
${env.map((e) => `putenv('${e}');`).join('\n')}
define('STDIN', fopen('php://stdin', 'r'));
define('STDOUT', fopen('php://stdout', 'w'));
define('STDERR', fopen('php://stderr', 'w'));
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
$_SERVER['argv'] = ['index.php', 'tools', 'football_cron', 'predict', '--force'];
$_SERVER['argc'] = count($_SERVER['argv']);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require '${root}/index.php';
`);
console.log('\n=== football-cron predict --force ===');
console.log(predict.trim());
console.log('\nDone — fixtures + predictions for ' + DATE + ' are now in ' + DB_PATH + '.');
console.log('View them at http://localhost:8080/football (dev server reads the same sqlite file).');
