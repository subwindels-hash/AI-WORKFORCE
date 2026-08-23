import { loadNodeRuntime, useHostFilesystem } from '@php-wasm/node';
import { PHP, PHPRequestHandler, ProcessIdAllocator } from '@php-wasm/universal';
const APP_ROOT = '/home/user/Africa-Mobility';
const allocator = new ProcessIdAllocator();
const runtime = await loadNodeRuntime('8.2', { emscriptenOptions: { processId: allocator.claim() } });
const php = new PHP(runtime);
useHostFilesystem(php);

for (const rule of [
  { match: '^/(?!index[.]php|probe[.]php).*', replacement: '/index.php' },
  { match: /^\/(?!index[.]php|probe[.]php).*/, replacement: '/index.php' },
]) {
  const handler = new PHPRequestHandler({ php, documentRoot: APP_ROOT, absoluteUrl: 'http://127.0.0.1:9999/', rewriteRules: [rule] });
  const r = await handler.request({ method: 'GET', url: '/strategy', headers: { host: 'x' } });
  console.log('rule type:', typeof rule.match, '-> /strategy status:', r.httpStatusCode, 'bytes:', r.bytes?.length);
  if (r.httpStatusCode !== 404) break;
}
process.exit(0);
