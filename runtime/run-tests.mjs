/**
 * Runs the PHP test suite (tools/tests through CodeIgniter) inside WASM PHP.
 * Equivalent on a native host: `php index.php tools tests`.
 */
import path from 'node:path';
import { loadNodeRuntime, useHostFilesystem } from '@php-wasm/node';
import { PHP, ProcessIdAllocator } from '@php-wasm/universal';

const APP_ROOT = path.resolve(new URL('..', import.meta.url).pathname);
process.env.AI_WORKFORCE_DB_DRIVER = 'pdo_sqlite';
// The dev runtime is sqlite-only. Pin BOTH driver variables so a deployment
// reference file named `env` (loaded by index.php's fallback) cannot flip the
// suite onto mysqli — inside php-wasm that connection cannot exist.
process.env.VP_DB_DRIVER = 'pdo_sqlite';
// Tests use a THROWAWAY database so they never pollute the demo data.
import fs from 'node:fs';
const TEST_DB = path.join(APP_ROOT, 'application', 'data', 'ai_workforce-test.sqlite');
process.env.AI_WORKFORCE_SQLITE_PATH = TEST_DB;
// Tests assume a pristine database — remove the throwaway file so state from
// a previous run (strategies mid-lifecycle, alert baselines…) cannot leak.
fs.rmSync(TEST_DB, { force: true });

const allocator = new ProcessIdAllocator();
const runtime = await loadNodeRuntime(process.env.PHP_VERSION ?? '8.2', {
  emscriptenOptions: { processId: allocator.claim() },
});
const php = new PHP(runtime);
useHostFilesystem(php);

const root = APP_ROOT.replaceAll("'", "\\'");
const code = `<?php
chdir('${root}');
// Hermetic suite, in strict order: (1) drop every ambient config variable the
// host may carry — deployment provider keys, production-mode switches and
// credentials belong to the deployment, never to assertions written against a
// clean environment; (2) pin the runner's own settings AFTER the strip.
foreach (array_keys(getenv()) as $ambientKey) {
    if (preg_match('/^(AI_WORKFORCE_|VP_|WINDELS_|APOLLO|AI_CHAT|LLM_|LANGUAGE_AI|RESEND_|POSTMARK_|GOOGLE_PLACES|ALPACA|OANDA|IBKR_|BYBIT|OKX_|COINBASE|KRAKEN|BINANCE|MULTIPLIER_|NEXT_PUBLIC|DATABASE_URL|REDIS_URL|CORS_ORIGINS|LEAD_|OUTREACH_|DEMO_MODE|TTS_|STT_|PRONUNCIATION_|AGENTS_ENABLED|MCP_TOOLS_ENABLED|WORKFLOWS_ENABLED|OBSERVABILITY_|AUDIT_LOG_)/', $ambientKey)) {
        putenv($ambientKey);
        unset($_ENV[$ambientKey], $_SERVER[$ambientKey]);
    }
}
// index.php honours AI_WORKFORCE_SKIP_ENV_FILE, so a deployment reference file
// named "env" is never loaded here; and because vp_load_env cannot override an
// already-set variable, these pins also win over any future .env in the repo.
putenv('AI_WORKFORCE_DB_DRIVER=pdo_sqlite');
putenv('VP_DB_DRIVER=pdo_sqlite');
putenv('AI_WORKFORCE_SQLITE_PATH=${process.env.AI_WORKFORCE_SQLITE_PATH.replaceAll("'", "\'")}');
putenv('AI_WORKFORCE_TEST_FILTER=${(process.env.AI_WORKFORCE_TEST_FILTER || '').replaceAll("'", "\'")}');
putenv('AI_WORKFORCE_SKIP_ENV_FILE=1');
// The suite must not depend on the public internet. Real market-data providers
// (exchanges, Yahoo…) are not registered here, so a sandbox without network —
// or an outage — cannot engage the market-data kill switch and fail cases that
// have nothing to do with the feed. Providers that need the network are tested
// by constructing them directly.
putenv('AI_WORKFORCE_DISABLE_REAL_PROVIDERS=1');
ini_set('display_errors', '1');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
// php-wasm runs with PHP_SAPI='wasm' — defining STDIN makes CI3's is_cli() true.
define('STDIN', fopen('php://stdin', 'r'));
define('STDOUT', fopen('php://stdout', 'w'));
define('STDERR', fopen('php://stderr', 'w'));
register_shutdown_function(function () {
  $e = error_get_last();
  while (ob_get_level() > 0) { @ob_end_flush(); }
  if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
    echo "FATAL: {$e['message']} @ {$e['file']}:{$e['line']}\n";
  }
});
$_SERVER['argv'] = ['index.php', 'tools', 'tests'];
$_SERVER['argc'] = 3;
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
// Install the schema into the (throwaway) test database first.
require '${root}/tools/install.php';
try {
  require '${root}/index.php';
} catch (Throwable $e) {
  while (ob_get_level() > 0) { @ob_end_flush(); }
  echo 'CAUGHT ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL;
  echo $e->getTraceAsString() . PHP_EOL;
}
`;

const result = await php.run({ code }).catch((e) => ({ text: (e.response?.text ?? '') + '\nRUN-THREW: ' + e.message.slice(0, 400) }));
console.log(result.text);
const m = /TESTS-RESULT:\s*(\d+)/.exec(result.text ?? '');
const fails = m ? Number(m[1]) : 99;
const inst = /INSTALL-RESULT:\s*(\d+)/.exec(result.text ?? '');
if (inst && inst[1] !== '0') process.exit(2);
process.exit(fails > 0 ? 1 : 0);
