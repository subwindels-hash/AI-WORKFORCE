import path from 'node:path';
import { loadNodeRuntime, useHostFilesystem } from '@php-wasm/node';
import { PHP, ProcessIdAllocator } from '@php-wasm/universal';

const APP_ROOT = path.resolve(new URL('..', import.meta.url).pathname);
const DB_PATH = path.join(APP_ROOT, 'application', 'data', 'ai_workforce.sqlite');

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

async function runJob(job) {
  const code = `<?php
chdir('${root}');
${env.map((e) => `putenv('${e}');`).join('\n')}
define('STDIN', fopen('php://stdin', 'r'));
define('STDOUT', fopen('php://stdout', 'w'));
define('STDERR', fopen('php://stderr', 'w'));
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
$_SERVER['argv'] = ['index.php', 'tools', 'football_cron', '${job}', '--force'];
$_SERVER['argc'] = count($_SERVER['argv']);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require '${root}/index.php';
`;
  const result = await php.run({ code }).catch((e) => ({ text: 'RUN-THREW: ' + String(e?.message ?? e) }));
  console.log(`=== ${job} ===`);
  console.log((result.text ?? '').trim());
}

for (const job of process.argv.slice(2)) {
  await runJob(job);
}
