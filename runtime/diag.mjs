import fs from 'node:fs';
import { loadNodeRuntime, useHostFilesystem } from '@php-wasm/node';
import { PHP, ProcessIdAllocator } from '@php-wasm/universal';
const APP_ROOT = '/home/user/AI-WORKFORCE';
const allocator = new ProcessIdAllocator();
const runtime = await loadNodeRuntime('8.2', { emscriptenOptions: { processId: allocator.claim() } });
const php = new PHP(runtime);
useHostFilesystem(php);
const file = process.argv[2] ?? 'diag-statistics.php';
const code = fs.readFileSync(`${APP_ROOT}/runtime/${file}`, 'utf8');
setTimeout(() => { console.log('DIAG-TIMEOUT'); process.exit(124); }, 110000);
try {
  const result = await php.run({ code });
  const out = Buffer.from(result.bytes ?? []).toString();
  const i = out.indexOf('{');
  console.log(i >= 0 ? out.slice(i, i + 2600) : out.slice(0, 800));
  if (result.errors) console.log('ERRS:', result.errors.slice(0, 300));
} catch (e) { console.log('CAUGHT', (e?.message ?? e).toString().slice(0, 600)); }
