<?php defined('BASEPATH') or exit('No direct script access allowed'); $o = $overview ?? []; ?>
<div class="page-head">
  <div>
    <p class="eyebrow">Platform</p>
    <h2>AI Workforce</h2>
    <p>Stored analysis runs from the live application. Nothing here is estimated.</p>
  </div>
</div>
<div class="grid four">
  <div class="kp-card"><div class="k">Analysis runs</div><div class="v"><?= (int) ($o['totalRuns'] ?? 0) ?></div><div class="trend">Persisted in analysis_runs</div></div>
</div>
<section class="panel" style="margin-top:16px">
  <h3>Recent runs</h3>
  <div class="body table-scroll">
    <?php if (empty($o['recent'])): ?>
      <div class="empty-state"><p>No AI analysis runs have been stored yet.</p></div>
    <?php else: ?>
      <table class="tbl">
        <thead><tr><th>Symbol</th><th>Timeframe</th><th>Bias</th><th>Source</th><th>Completed</th></tr></thead>
        <tbody>
          <?php foreach ($o['recent'] as $row): ?>
            <tr>
              <td class="mono"><?= e($row['symbol'] ?? '') ?></td>
              <td><?= e($row['timeframe'] ?? '') ?></td>
              <td><?= e($row['bias'] ?? '') ?></td>
              <td class="dim"><?= e($row['source'] ?? '') ?></td>
              <td class="dim"><?= admin_dt($row['completed_at'] ?? null) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>
