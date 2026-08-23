/**
 * AEGIS — offline dev runtime bridge (NOT part of the production stack).
 *
 * The repository is a standard CodeIgniter 3 application targeting PHP 8.x +
 * MySQL/MariaDB. This sandbox cannot run a native PHP process or a MySQL
 * server (no package mirrors), so for the live preview we host the SAME
 * CodeIgniter application inside a WebAssembly PHP runtime (php-wasm) with
 * the host filesystem mounted, using CI3's pdo_sqlite dev driver.
 *
 * Each HTTP request gets a FRESH PHP instance (CodeIgniter's front
 * controller is not re-entrant in a persistent interpreter: require_once
 * would skip the whole bootstrap on the second request). Warm WASM
 * instantiation is a few hundred milliseconds — acceptable for a demo.
 *
 * Production deployments use Apache/nginx + php-fpm + MariaDB unchanged.
 */
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { loadNodeRuntime, useHostFilesystem } from '@php-wasm/node';
import { PHP, PHPRequestHandler, ProcessIdAllocator } from '@php-wasm/universal';

const PORT = Number(process.env.PORT ?? 8080);
const HOST = process.env.HOST ?? '0.0.0.0';
const APP_ROOT = path.resolve(new URL('..', import.meta.url).pathname);
const PHP_VERSION = process.env.PHP_VERSION ?? '8.2';

const allocator = new ProcessIdAllocator();
let warming = null;

/** Create a fresh, filesystem-mounted PHP instance. */
async function createPhp() {
  const runtime = await loadNodeRuntime(PHP_VERSION, {
    emscriptenOptions: { processId: allocator.claim() },
  });
  const php = new PHP(runtime);
  useHostFilesystem(php);
  return php;
}

/** Verify toolchain + install/upgrade the SQLite schema at boot. */
/**
 * DEV BRIDGE ONLY: the demo sqlite database is gitignored and may be reset at
 * any time, so ensure a clearly-labeled demo operator exists for RBAC demos.
 * Production NEVER auto-creates accounts — admins come from
 * `php index.php tools bootstrap_admin` with real credentials.
 */
async function bootstrapDemoOperator() {
  const php = await createPhp();
  try {
    await php.run({
      code: `<?php
chdir('/home/user/Africa-Mobility');
putenv('AEGIS_DB_DRIVER=pdo_sqlite');
putenv('AEGIS_SQLITE_PATH=/home/user/Africa-Mobility/application/data/aegis.sqlite');
putenv('AEGIS_BOOTSTRAP_ADMIN_EMAIL=demo-operator@aegis.local');
putenv('AEGIS_BOOTSTRAP_ADMIN_PASSWORD=demo-only-long-password-123456');
putenv('AEGIS_BOOTSTRAP_ADMIN_NAME=Demo Operator (dev bridge)');
define('STDIN', fopen('php://stdin', 'r'));
define('STDOUT', fopen('php://stdout', 'w'));
define('STDERR', fopen('php://stderr', 'w'));
$_SERVER['argv'] = ['index.php', 'tools', 'bootstrap_admin'];
$_SERVER['argc'] = 3;
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require '/home/user/Africa-Mobility/index.php';
`,
    });
  } catch (e) {
    console.error('[aegis] demo operator bootstrap failed:', e?.message ?? e);
  }
}

async function installSchema() {
  const php = await createPhp();
  const root = APP_ROOT.replaceAll("'", "\\'");
  const result = await php.run({
    code: `<?php
chdir('${root}');
putenv('AEGIS_DB_DRIVER=pdo_sqlite');
putenv('AEGIS_SESSION_DRIVER=database'); // per-request instances share the DB, not the FS session files
putenv('AEGIS_SQLITE_PATH=${root}/application/data/aegis.sqlite');
define('AEGIS_NO_EXIT', true);
require '${root}/tools/install.php';
`,
  }).catch((e) => ({ text: 'INSTALL FAILED: ' + e.message }));
  console.log('[aegis] schema:', result.text.trim().split('\n').slice(-2).join(' | '));
}

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, `http://${req.headers.host}`);

  // Static assets straight from disk (no PHP round-trip).
  if (url.pathname.startsWith('/assets/')) {
    const file = path.join(APP_ROOT, url.pathname);
    if (file.startsWith(APP_ROOT) && fs.existsSync(file) && fs.statSync(file).isFile()) {
      res.setHeader('content-type', file.endsWith('.css') ? 'text/css' : 'application/octet-stream');
      res.end(fs.readFileSync(file));
      return;
    }
    res.statusCode = 404;
    res.end('not found');
    return;
  }

  const body = await new Promise((resolve) => {
    const chunks = [];
    req.on('data', (c) => chunks.push(c));
    req.on('end', () => resolve(Buffer.concat(chunks)));
    req.on('error', () => resolve(Buffer.alloc(0)));
  });

  try {
    // Fresh interpreter per request (see header comment).
    const php = await createPhp();
    const handler = new PHPRequestHandler({
      php,
      documentRoot: APP_ROOT,
      absoluteUrl: `http://127.0.0.1:${PORT}/`,
    });
    const response = await handler.request({
      method: req.method,
      // Front-controller pattern: always run index.php; the original request
      // target travels in X-Aegis-Orig-Uri (the fastcgi-param pattern).
      url: '/index.php',
      headers: {
        ...req.headers,
        host: req.headers.host ?? `127.0.0.1:${PORT}`,
        'x-aegis-orig-uri': req.url ?? '/',
        // php-wasm's request handler blanks the CGI HTTP_COOKIE value, so the
        // dev bridge carries the raw Cookie header here; index.php restores it.
        'x-aegis-cookie': req.headers.cookie ?? '',
      },
      body: body.length ? body : undefined,
    });
    if (response.httpStatusCode >= 400) {
      console.error('[aegis]', req.method, req.url, '->', response.httpStatusCode,
        '| php errors:', (response.errors || '').slice(0, 400) || '(none)',
        '| body:', Buffer.from(response.bytes ?? []).toString().slice(0, 300));
    }
    res.writeHead(response.httpStatusCode, response.headers);
    res.end(Buffer.from(response.bytes));
  } catch (err) {
    console.error('[aegis] request failed:', err?.message ?? err);
    res.writeHead(500, { 'content-type': 'text/plain' });
    res.end('AEGIS runtime error: ' + (err?.message ?? err));
  }
});

await installSchema();
await bootstrapDemoOperator();
server.listen(PORT, HOST, () => {
  console.log(`[aegis] CodeIgniter 3 app serving on http://${HOST}:${PORT} (WASM PHP ${PHP_VERSION}, sqlite dev driver)`);
});
