import { loadNodeRuntime, useHostFilesystem } from '@php-wasm/node';
import { PHP, ProcessIdAllocator } from '@php-wasm/universal';

async function run(code) {
  const runtime = await loadNodeRuntime('8.2', { emscriptenOptions: { processId: new ProcessIdAllocator().claim() } });
  const php = new PHP(runtime);
  useHostFilesystem(php);
  await php.writeFile('/tmp/sess.php', code);
  const res = await php.run({ scriptPath: '/tmp/sess.php' });
  return res.text.trim();
}

const write = await run(`<?php
$pdo = new PDO('sqlite:/home/user/Africa-Mobility/application/data/sess-probe.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE IF NOT EXISTS t (id TEXT PRIMARY KEY, v TEXT)');
$pdo->prepare('INSERT OR REPLACE INTO t VALUES (?,?)')->execute(['probe1','written-by-instance-a']);
echo 'ini sid: ' . ini_get('session.sid_length') . '/' . ini_get('session.sid_bits_per_character') . "\\n";
echo 'A wrote\\n';
`);
console.log(write);

const read = await run(`<?php
$pdo = new PDO('sqlite:/home/user/Africa-Mobility/application/data/sess-probe.sqlite');
$row = $pdo->query('SELECT v FROM t WHERE id = ' . $pdo->quote('probe1'))->fetchColumn();
echo 'B read: ' . var_export($row, true) . "\\n";
`);
console.log(read);
