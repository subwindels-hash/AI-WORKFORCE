<?php defined('BASEPATH') or exit('No direct script access allowed');
$notes = $inbox['notifications'] ?? [];
?>
<div class="page-head">
  <div>
    <p class="eyebrow">Operations</p>
    <h2>Notifications</h2>
    <p>Send announcements to one member or every active account, and review the operations feed below. Member-facing notifications appear in the Alerts page of their dashboard.</p>
  </div>
</div>

<?php if (!empty($canSend)): ?>
<section class="panel" id="send">
  <h3><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13"/><path d="m22 2-7 20-4-9-9-4z"/></svg> Send a notification</h3>
  <div class="body">
    <form method="post" action="/admin/notifications/send">
      <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
      <div class="admin-filters" style="margin:0 0 12px">
        <label>Recipient
          <select name="target" id="notif-target">
            <option value="all">All active users (<?= (int) ($activeUsers ?? 0) ?>)</option>
            <option value="user">One user</option>
          </select>
        </label>
        <label>User (single recipient)
          <input type="text" name="user" id="notif-user" placeholder="User ID, username or email" maxlength="190">
        </label>
        <label>Severity
          <select name="severity">
            <option value="info">Info</option>
            <option value="warning">Warning</option>
            <option value="critical">Critical</option>
          </select>
        </label>
      </div>
      <label class="auth-field" style="display:grid;gap:6px;margin-bottom:12px">
        <span style="font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:var(--dim);font-weight:700">Title</span>
        <input type="text" name="title" maxlength="200" required placeholder="e.g. Scheduled maintenance this Sunday">
      </label>
      <label class="auth-field" style="display:grid;gap:6px;margin-bottom:12px">
        <span style="font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:var(--dim);font-weight:700">Message</span>
        <textarea name="message" rows="3" maxlength="2000" required placeholder="What members should read in their Alerts inbox…"></textarea>
      </label>
      <button class="btn primary" type="submit">Send notification</button>
      <span class="dim" style="font-size:12px;margin-left:10px">Each recipient gets their own copy, so read state is per member.</span>
    </form>
  </div>
</section>
<script>
  (function () {
    var target = document.getElementById('notif-target');
    var user = document.getElementById('notif-user');
    if (!target || !user) return;
    var sync = function () { user.disabled = target.value !== 'user'; };
    target.addEventListener('change', sync); sync();
  })();
</script>
<?php endif; ?>

<section class="panel">
  <h3><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9a6 6 0 0 1 12 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 19a2 2 0 0 0 4 0"/></svg> Operations feed</h3>
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
