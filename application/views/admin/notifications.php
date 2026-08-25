<?php defined('BASEPATH') or exit('No direct script access allowed');
$notes = $inbox['notifications'] ?? [];
?>
<div class="page-head">
  <div>
    <p class="eyebrow">Operations</p>
    <h2>Notifications</h2>
    <p>New registrations, lockouts and system events. Technical detail stays on this portal and is not shown to members.</p>
  </div>
</div>
<section class="panel">
  <div class="body">
    <?php if (!$notes): ?>
      <div class="empty-state"><p>No administrator notifications yet.</p></div>
    <?php else: ?>
      <div class="feed">
        <?php foreach ($notes as $n): ?>
          <div class="row">
            <span class="t">
              <span class="badge <?= ($n['severity'] ?? '') === 'critical' ? 'b-red' : (($n['severity'] ?? '') === 'warning' ? 'b-amber' : 'b-sky') ?>"><?= e($n['severity'] ?? 'info') ?></span>
              <?= e($n['title'] ?? $n['type'] ?? 'Notice') ?>
            </span>
            <span class="d"><?= admin_dt($n['created_at'] ?? null) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
