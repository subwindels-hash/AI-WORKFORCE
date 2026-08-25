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
          <thead><tr><th>User ID</th><th>Username</th><th>Email</th><th>Status</th><th>Created</th></tr></thead>
          <tbody>
            <?php foreach ($recent as $u): ?>
              <tr>
                <td class="mono"><a href="/admin/users/<?= (int) $u['id'] ?>"><?= e($u['user_uid'] ?? '') ?></a></td>
                <td><?= e($u['username'] ?? $u['display_name'] ?? '') ?></td>
                <td class="dim"><?= e($u['email'] ?? '') ?></td>
                <td><span class="badge <?= !empty($u['active']) ? 'b-green' : 'b-gray' ?>"><?= !empty($u['active']) ? 'Active' : 'Suspended' ?></span></td>
                <td class="dim"><?= admin_dt($u['created_at'] ?? null) ?></td>
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
