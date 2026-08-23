import { loadNodeRuntime, useHostFilesystem } from '@php-wasm/node';
import { PHP, ProcessIdAllocator } from '@php-wasm/universal';
const allocator = new ProcessIdAllocator();
const runtime = await loadNodeRuntime('8.2', { emscriptenOptions: { processId: allocator.claim() } });
const php = new PHP(runtime);
useHostFilesystem(php);
const r = await php.run({ code: `<?php
define('BASEPATH', '/home/user/Africa-Mobility/system/');
try {
  require '/home/user/Africa-Mobility/application/libraries/Aegis/autoload.php';
  echo 'autoload OK; classes=' . count(get_declared_classes()) . PHP_EOL;
  echo 'Platform exists: ' . var_export(class_exists('Aegis\\\\Platform'), true) . PHP_EOL;
} catch (Throwable $e) {
  echo 'AUTOLOAD FAIL: ' . get_class($e) . ' ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL;
}
` });
console.log(r.text);
process.exit(0);
