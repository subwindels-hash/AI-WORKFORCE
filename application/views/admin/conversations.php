<?php defined('BASEPATH') or exit('No direct script access allowed'); $o = $overview ?? []; ?>
<div class="page-head">
  <div>
    <p class="eyebrow">Platform</p>
    <h2>Conversations</h2>
    <p>Teacher conversation sessions stored for every learner.</p>
  </div>
</div>
<div class="grid four">
  <div class="kp-card"><div class="k">Sessions</div><div class="v"><?= (int) ($o['total'] ?? 0) ?></div><div class="trend">conversation_sessions rows</div></div>
</div>
<section class="panel" style="margin-top:16px">
  <h3>Recent conversations</h3>
  <div class="body table-scroll">
    <?php if (empty($o['recent'])): ?>
      <div class="empty-state"><p>No conversation sessions have been stored yet.</p></div>
    <?php else: ?>
      <table class="tbl">
        <thead><tr><th>User</th><th>Language</th><th>Scenario</th><th>Turns</th><th>Status</th><th>Started</th></tr></thead>
        <tbody>
          <?php foreach ($o['recent'] as $row): ?>
            <tr>
              <td><a href="/admin/users/<?= (int) ($row['user_id'] ?? 0) ?>">#<?= (int) ($row['user_id'] ?? 0) ?></a></td>
              <td class="mono"><?= e($row['language_code'] ?? '') ?></td>
              <td><?= e($row['scenario'] ?? '') ?></td>
              <td><?= (int) ($row['turn_count'] ?? 0) ?></td>
              <td><?= e($row['status'] ?? '') ?></td>
              <td class="dim"><?= admin_dt($row['started_at'] ?? null) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>
