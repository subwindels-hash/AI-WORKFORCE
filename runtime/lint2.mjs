import { loadNodeRuntime, useHostFilesystem } from '@php-wasm/node';
import { PHP, ProcessIdAllocator } from '@php-wasm/universal';
const allocator = new ProcessIdAllocator();
const runtime = await loadNodeRuntime('8.2', { emscriptenOptions: { processId: allocator.claim() } });
const php = new PHP(runtime);
useHostFilesystem(php);
const r = await php.run({ code: `<?php
$files = [
  '/home/user/Africa-Mobility/application/core/MY_Controller.php',
  '/home/user/Africa-Mobility/application/models/Aegis_model.php',
  '/home/user/Africa-Mobility/application/controllers/Welcome.php',
  '/home/user/Africa-Mobility/index.php',
];
define('BASEPATH', '/home/user/Africa-Mobility/system/');
define('APPPATH', '/home/user/Africa-Mobility/application/');
define('ENVIRONMENT', 'development');
define('EXT', '.php');
define('TESTSPATH', '/home/user/Africa-Mobility/tests/');
foreach ($files as $f) {
  try { include $f; echo "OK $f\\n"; }
  catch (Throwable $e) { echo 'FAIL ' . basename($f) . ': ' . get_class($e) . ' ' . $e->getMessage() . ' @ line ' . $e->getLine() . "\\n"; }
}
` });
console.log(r.text);
process.exit(0);
