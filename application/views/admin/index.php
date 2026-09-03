<?php defined('BASEPATH') or exit('No direct script access allowed');
/** @var array $stats @var array $smtp */
$s = $stats ?? [];
?>
<div class="page-head">
  <div>
    <p class="eyebrow">WINDELS AI WORKFORCE</p>
    <h2>Administrator dashboard</h2>
    <p>Live platform overview from the application database. Empty numbers mean no matching records yet.</p>
  </div>
  <div class="admin-badge"><img src="/assets/images/ai-agent-avatar.png" alt="" width="28" height="28"><span>Admin mode</span></div>
</div>

<div class="grid four">
  <a class="kp-card" href="/admin/users">
    <div class="kp-top"><div class="k">Total users</div></div>
    <div class="v"><?= (int) ($s['users'] ?? 0) ?></div>
    <div class="trend">Accounts in the directory</div>
  </a>
  <a class="kp-card" href="/admin/users?status=active">
    <div class="kp-top"><div class="k">Active users</div></div>
    <div class="v"><?= (int) ($s['active'] ?? 0) ?></div>
    <div class="trend">Can sign in</div>
  </a>
  <a class="kp-card" href="/admin/users?status=suspended">
    <div class="kp-top"><div class="k">Suspended</div></div>
    <div class="v"><?= (int) ($s['suspended'] ?? 0) ?></div>
    <div class="trend">Blocked from the dashboard</div>
  </a>
  <div class="kp-card">
    <div class="kp-top"><div class="k">New users (7 days)</div></div>
    <div class="v"><?= (int) ($s['newUsers'] ?? 0) ?></div>
    <div class="trend">Created in the last week</div>
  </div>
</div>

<div class="grid four" style="margin-top:16px">
  <a class="kp-card" href="/admin/workforce">
    <div class="kp-top"><div class="k">AI usage</div></div>
    <div class="v"><?= (int) ($s['aiUsage'] ?? 0) ?></div>
    <div class="trend">Stored analysis runs</div>
  </a>
  <a class="kp-card" href="/admin/languages">
    <div class="kp-top"><div class="k">Language learning</div></div>
    <div class="v"><?= (int) ($s['languageSessions'] ?? 0) ?></div>
    <div class="trend"><?= (int) ($s['languageProfiles'] ?? 0) ?> profiles</div>
  </a>
  <a class="kp-card" href="/admin/conversations">
    <div class="kp-top"><div class="k">Conversations</div></div>
    <div class="v"><?= (int) ($s['conversations'] ?? 0) ?></div>
    <div class="trend">Teacher sessions stored</div>
  </a>
  <a class="kp-card" href="/admin/inbox">
    <div class="kp-top"><div class="k">Inbox messages</div></div>
    <div class="v"><?= (int) ($s['inboxTotal'] ?? 0) ?><?php if ((int) ($s['inboxUnread'] ?? 0) > 0): ?> <span style="color:#dc2626;font-size:14px;font-weight:600">(<?= (int) $s['inboxUnread'] ?> unread)</span><?php endif; ?></div>
    <div class="trend">Contact-form submissions — reply from the inbox</div>
  </a>
  <div class="kp-card">
    <div class="kp-top"><div class="k">Recent logins (30 days)</div></div>
    <div class="v"><?= (int) ($s['recentLogins'] ?? 0) ?></div>
    <div class="trend">Active accounts with a recorded login</div>
  </div>
</div>

<div class="grid cols-main" style="margin-top:16px">
  <section class="panel">
    <h3>Recent registrations</h3>
    <div class="body table-scroll">
      <?php $recent = $s['recentUsers'] ?? []; ?>
      <?php if (!$recent): ?>
        <div class="empty-state"><p>No user accounts have been created yet.</p></div>
      <?php else: ?>
        <table class="tbl">
          <thead><tr><th>User ID</th><th>Username</th><th>Email</th><th>Status</th><th>Created</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($recent as $u): ?>
              <tr>
                <td class="mono"><a href="/admin/users/<?= (int) $u['id'] ?>"><?= e($u['user_uid'] ?? '') ?></a></td>
                <td><?= e($u['username'] ?? $u['display_name'] ?? '') ?></td>
                <td class="dim"><?= e($u['email'] ?? '') ?></td>
                <td><span class="badge <?= !empty($u['active']) ? 'b-green' : 'b-gray' ?>"><?= !empty($u['active']) ? 'Active' : 'Suspended' ?></span></td>
                <td class="dim"><?= admin_dt($u['created_at'] ?? null) ?></td>
                <td>
                  <div class="admin-actions">
                    <a class="btn small" href="/admin/users/<?= (int) $u['id'] ?>">View</a>
                    <?php $this->load->view('admin/partials/open_dashboard', ['target' => $u, 'csrfToken' => $csrfToken]); ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
    <a class="panel-foot-link" href="/admin/users">Open user management →</a>
  </section>
  <section class="panel">
    <h3>Recent admin activity</h3>
    <div class="body">
      <?php $logs = $s['recentAdmin'] ?? []; ?>
      <?php if (!$logs): ?>
        <div class="empty-state" style="padding:20px"><p>No administrator actions recorded yet.</p></div>
      <?php else: ?>
        <div class="feed">
          <?php foreach ($logs as $log): ?>
            <div class="row">
              <span class="t"><?= e($log['admin_label'] ?? '') ?> · <?= e(str_replace('_', ' ', strtolower((string) ($log['action'] ?? '')))) ?></span>
              <span class="d"><?= admin_dt($log['created_at'] ?? null) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php if (admin_can('admin.logs.view')): ?><a class="panel-foot-link" href="/admin/logs">Open activity logs →</a><?php endif; ?>
  </section>
</div>

<?php if (admin_can('admin.users.view')): ?>
<section class="panel" style="margin-top:16px">
  <h3 style="display:flex;align-items:center;justify-content:space-between">
    <span>Recent inbox messages</span>
    <a class="btn small ghost" href="/admin/inbox">Open inbox</a>
  </h3>
  <div class="body table-scroll">
    <?php $recentInbox = $s['recentInbox'] ?? []; ?>
    <?php if (!$recentInbox): ?>
      <div class="empty-state"><p>No contact messages yet. Submissions from the public Contact page will appear here.</p></div>
    <?php else: ?>
      <table class="tbl">
        <thead><tr><th></th><th>From</th><th>Subject</th><th>Status</th><th>Received</th></tr></thead>
        <tbody>
          <?php foreach ($recentInbox as $m): ?>
            <tr>
              <td style="width:16px"><?= empty($m['is_read']) ? '<span style="display:inline-block;width:8px;height:8px;border-radius:999px;background:#dc2626"></span>' : '' ?></td>
              <td><a href="/admin/inbox/<?= (int) $m['id'] ?>"><strong><?= e($m['sender_name'] ?? '') ?></strong></a><div class="dim" style="font-size:12px"><?= e($m['sender_email'] ?? '') ?></div></td>
              <td><a href="/admin/inbox/<?= (int) $m['id'] ?>"><?= e($m['subject'] ?? '') ?></a></td>
              <td><span class="badge <?= ((int)($m['is_read']??0) === 0 ? 'b-red' : 'b-gray') ?>"><?= e(($m['status'] ?? 'new')) ?></span></td>
              <td class="dim"><?= admin_dt($m['created_at'] ?? null) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
  <a class="panel-foot-link" href="/admin/inbox">Go to admin inbox →</a>
</section>
<?php endif; ?>
