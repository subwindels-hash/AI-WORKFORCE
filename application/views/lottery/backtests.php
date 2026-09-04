<?php defined('BASEPATH') or exit('No direct script access allowed');
$rows = is_array($backtests ?? null) ? $backtests : [];
?>
<div class="page-head">
  <div>
    <h2>EuroMillions · Backtests</h2>
    <p>HISTORICAL SIMULATION only. Every comparison includes a mandatory same-period random baseline. No strategy is declared “better”.</p>
  </div>
  <a class="btn" href="/lottery">← Lottery Intelligence</a>
</div>
<p class="dim"><?= e(\AIWorkforce\Lottery\LotteryStatisticsEngine::DISCLAIMER) ?></p>
<?php if ($rows === []): ?>
  <div class="panel"><div class="body"><p class="dim">No backtests yet. Run one from Strategy Lab (POST /api/lottery/backtest) — RANDOM_BASELINE is required in every comparison.</p></div></div>
<?php else: ?>
  <table class="tbl mono">
    <thead><tr><th>Id</th><th>Strategy</th><th>Model</th><th>Draws</th><th>Period</th><th>Created</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $b): ?>
      <tr>
        <td><a href="/api/lottery/backtests/<?= (int) ($b['id'] ?? 0) ?>"><?= (int) ($b['id'] ?? 0) ?></a></td>
        <td><?= e((string) ($b['strategy'] ?? '')) ?></td>
        <td><?= e((string) ($b['model_version'] ?? $b['model'] ?? '')) ?></td>
        <td><?= (int) ($b['draws_tested'] ?? 0) ?></td>
        <td><?= e((string) ($b['period_from'] ?? '')) ?> → <?= e((string) ($b['period_to'] ?? '')) ?></td>
        <td><?= e((string) ($b['created_at'] ?? '')) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
<p style="margin-top:16px"><a class="btn" href="/lottery/backtests">Open Strategy Lab</a></p>
