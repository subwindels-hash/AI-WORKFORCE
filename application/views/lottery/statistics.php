<?php defined('BASEPATH') or exit('No direct script access allowed');
$kind = $kind ?? 'frequency';
$data = is_array($data ?? null) ? $data : [];
$disclaimer = (string) ($data['disclaimer'] ?? \AIWorkforce\Lottery\LotteryStatisticsEngine::DISCLAIMER);
$numbers = is_array($data['numbers'] ?? null) ? $data['numbers'] : [];
$hotCold = is_array($data['hotCold'] ?? null) ? $data['hotCold'] : (isset($data['hot']) ? $data : []);
$windowLabel = ($windowRequested ?? '') !== '' && ($windowRequested ?? '0') !== '0' ? (string) $windowRequested : 'all history';
?>
<div class="page-head">
  <div>
    <h2><?= e((string) ($title ?? 'EuroMillions statistics')) ?></h2>
    <p>Historical observations only. Window: <?= e($windowLabel) ?><?= !empty($window) ? ' · ' . (int) $window . ' draws' : '' ?>.</p>
  </div>
  <a class="btn" href="/lottery">← Lottery Intelligence</a>
</div>
<p class="dim" style="max-width:720px"><?= e($disclaimer) ?></p>
<div class="tab-row" style="display:flex;gap:8px;flex-wrap:wrap;margin:12px 0">
  <a class="btn <?= $kind === 'frequency' ? 'primary' : '' ?>" href="/api/lottery/statistics?kind=frequency&amp;window=1y">Frequency / hot-cold (1y)</a>
  <a class="btn <?= $kind === 'gap' ? 'primary' : '' ?>" href="/api/lottery/statistics?kind=gap&amp;window=1y">Gap statistics</a>
  <a class="btn <?= $kind === 'distribution' ? 'primary' : '' ?>" href="/api/lottery/statistics?kind=distribution&amp;window=2y">Number distribution</a>
</div>

<?php if (empty($data['totalDraws']) && empty($numbers) && empty($data['oddEven'])): ?>
  <div class="panel"><div class="body"><p class="dim">No verified draws imported yet. Connect an official EuroMillions source in Admin → API, or sync the sandbox provider.</p></div></div>
<?php endif; ?>

<?php if (!empty($hotCold['hot'])): ?>
  <div class="lottery-card" style="border:1px solid var(--line);border-radius:var(--radius);padding:14px;margin:12px 0;background:var(--panel)">
    <h3>Hot / cold (historical labels)</h3>
    <p class="dim"><?= e((string) ($hotCold['observation'] ?? '')) ?></p>
    <p><strong>Hot:</strong>
      <?php foreach ($hotCold['hot'] as $n => $c): ?><span class="ball" style="display:inline-flex;width:32px;height:32px;border-radius:50%;background:#ffd24a;color:#1b1b1b;align-items:center;justify-content:center;margin:0 4px 4px 0;font-weight:700"><?= (int)$n ?></span><span class="dim"><?= (int)$c ?></span><?php endforeach; ?>
    </p>
    <p><strong>Cold:</strong>
      <?php foreach ($hotCold['cold'] as $n => $c): ?><span class="ball" style="display:inline-flex;width:32px;height:32px;border-radius:50%;background:#94a3b8;color:#0f172a;align-items:center;justify-content:center;margin:0 4px 4px 0;font-weight:700"><?= (int)$n ?></span><span class="dim"><?= (int)$c ?></span><?php endforeach; ?>
    </p>
  </div>
<?php endif; ?>

<?php if ($numbers !== []): ?>
  <div class="lottery-card" style="border:1px solid var(--line);border-radius:var(--radius);padding:14px;margin:12px 0;background:var(--panel);overflow:auto">
    <h3><?= $kind === 'gap' ? 'Gap statistics' : 'Frequency' ?> · <?= (int) ($data['totalDraws'] ?? 0) ?> draws</h3>
    <table class="tbl mono">
      <thead><tr><th>#</th><th>Appearances</th><th>%</th><th>Last seen</th><th>Draws since</th><th>Avg gap</th><th>Max gap</th></tr></thead>
      <tbody>
      <?php foreach ($numbers as $row): ?>
        <tr>
          <td><?= (int) ($row['number'] ?? 0) ?></td>
          <td><?= (int) ($row['appearances'] ?? 0) ?></td>
          <td><?= e((string) ($row['appearancePct'] ?? '')) ?></td>
          <td><?= e((string) ($row['lastAppearance'] ?? '—')) ?></td>
          <td><?= $row['drawsSinceLast'] === null ? '—' : (int) $row['drawsSinceLast'] ?></td>
          <td><?= $row['avgGap'] === null ? '—' : e((string) $row['avgGap']) ?></td>
          <td><?= $row['maxGap'] === null ? '—' : e((string) $row['maxGap']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php if (!empty($data['oddEven'])): ?>
  <div class="lottery-card" style="border:1px solid var(--line);border-radius:var(--radius);padding:14px;margin:12px 0;background:var(--panel)">
    <h3>Number distribution · <?= (int) ($data['totalDraws'] ?? 0) ?> draws</h3>
    <h4>Odd / even</h4>
    <ul><?php foreach ($data['oddEven'] as $k => $v): ?><li><?= e((string)$k) ?>: <?= (int)$v ?> (<?= e((string)($data['oddEvenPct'][$k] ?? '')) ?>%)</li><?php endforeach; ?></ul>
    <h4>Low / high (low ≤ <?= (int) ($data['lowBound'] ?? 25) ?>)</h4>
    <ul><?php foreach ($data['lowHigh'] as $k => $v): ?><li><?= e((string)$k) ?>: <?= (int)$v ?></li><?php endforeach; ?></ul>
    <h4>Sum</h4>
    <p>min <?= e((string)($data['sum']['min'] ?? '—')) ?> · max <?= e((string)($data['sum']['max'] ?? '—')) ?> · avg <?= e((string)($data['sum']['avg'] ?? '—')) ?> · median <?= e((string)($data['sum']['median'] ?? '—')) ?></p>
    <h4>Spread</h4>
    <p>min <?= e((string)($data['spread']['min'] ?? '—')) ?> · max <?= e((string)($data['spread']['max'] ?? '—')) ?> · avg <?= e((string)($data['spread']['avg'] ?? '—')) ?></p>
    <h4>Consecutive</h4>
    <p><?= (int) ($data['consecutive']['drawsWithConsecutive'] ?? 0) ?> draws (<?= e((string)($data['consecutive']['pct'] ?? '0')) ?>%) had consecutive mains. Longest run <?= (int) ($data['consecutive']['longestRun'] ?? 0) ?>.</p>
  </div>
<?php endif; ?>
