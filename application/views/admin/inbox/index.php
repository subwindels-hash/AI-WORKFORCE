<?php defined('BASEPATH') or exit('No direct script access allowed');
$c = $counts ?? [];
$filters = [
    'all' => 'All', 'new' => 'New', 'open' => 'Open', 'replied' => 'Replied',
    'unread' => 'Unread (' . (int)($c['unread']??0) . ')',
    'starred' => 'Starred', 'closed' => 'Closed', 'spam' => 'Spam', 'archived' => 'Archived',
];
if (!function_exists('e')) { function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('admin_dt')) { include __DIR__ . '/../layout/header.php'; }
?>
<div class="page-head">
  <div>
    <p class="eyebrow">WINDELS AI WORKFORCE</p>
    <h2>Inbox</h2>
    <p>Contact-form submissions, enquiries and replies. Reply via email directly from the thread.</p>
  </div>
  <?php if (admin_can('admin.settings.manage')): ?>
    <a class="btn ghost" href="/admin/inbox/templates">Email templates</a>
  <?php endif; ?>
</div>

<div class="grid four" style="margin-bottom:16px">
  <a class="kp-card" href="?status=all"><div class="kp-top"><div class="k">All messages</div></div><div class="v"><?= (int)($c['total']??0) ?></div></a>
  <a class="kp-card" href="?status=unread"><div class="kp-top"><div class="k">Unread</div></div><div class="v" style="color:#dc2626"><?= (int)($c['unread']??0) ?></div></a>
  <a class="kp-card" href="?status=open"><div class="kp-top"><div class="k">Open / pending reply</div></div><div class="v"><?= (int)(($c['total']??0) - ($c['unread']??0)) ?></div></a>
  <a class="kp-card" href="?status=starred"><div class="kp-top"><div class="k">Starred</div></div><div class="v"><?= (int)($c['starred']??0) ?></div></a>
</div>

<div class="panel">
  <div class="inbox-toolbar">
    <div class="tabs">
      <?php foreach ($filters as $k => $label): ?>
        <a class="tab <?= $status === $k ? 'on' : '' ?>" href="?status=<?= $k ?><?= $search ? '&q=' . urlencode($search) : '' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </div>
    <form class="inbox-search" method="get" action="/admin/inbox">
      <input type="hidden" name="status" value="<?= e($status) ?>">
      <input type="search" name="q" placeholder="Search name, email, subject or message…" value="<?= e($search) ?>">
      <button class="btn small" type="submit">Search</button>
    </form>
  </div>
  <div class="body table-scroll">
    <?php if (!$messages): ?>
      <div class="empty-state"><p>No messages in this folder. When visitors submit the public Contact form, they appear here.</p></div>
    <?php else: ?>
      <table class="tbl">
        <thead><tr><th></th><th></th><th>From</th><th>Subject</th><th>Status</th><th>Received</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($messages as $m): ?>
          <?php $unread = empty($m['is_read']); ?>
          <tr class="<?= $unread ? 'inbox-unread' : '' ?>">
            <td style="width:24px">
              <form method="post" action="/admin/inbox/<?= (int)$m['id'] ?>/star?return=<?= urlencode($_SERVER['REQUEST_URI'] ?? '/admin/inbox') ?>" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <button type="submit" class="btn-icon star-btn" title="<?= !empty($m['is_starred']) ? 'Unstar' : 'Star' ?>"><?= !empty($m['is_starred']) ? '★' : '☆' ?></button>
              </form>
            </td>
            <td style="width:14px"><?= $unread ? '<span style="display:inline-block;width:8px;height:8px;border-radius:999px;background:#2563eb"></span>' : '' ?></td>
            <td>
              <div style="font-weight:<?= $unread ? 700 : 500 ?>"><?= e($m['sender_name']) ?></div>
              <div class="dim" style="font-size:12px"><?= e($m['sender_email']) ?></div>
            </td>
            <td>
              <a href="/admin/inbox/<?= (int)$m['id'] ?>" style="font-weight:<?= $unread ? 700 : 400 ?>"><?= e($m['subject']) ?></a>
              <div class="dim" style="font-size:12px;max-width:480px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e(mb_substr((string)$m['body'], 0, 120)) ?></div>
            </td>
            <td>
              <?php $st = $m['status'] ?? 'new';
                $b = ['new'=>'b-red','open'=>'b-amber','replied'=>'b-green','closed'=>'b-gray','spam'=>'b-gray','archived'=>'b-gray'][$st] ?? 'b-gray'; ?>
              <span class="badge <?= $b ?>"><?= e(ucfirst($st)) ?></span>
            </td>
            <td class="dim"><?= admin_dt($m['created_at'] ?? null) ?></td>
            <td><a class="btn small" href="/admin/inbox/<?= (int)$m['id'] ?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
<style>
  .inbox-toolbar { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:12px 16px; border-bottom:1px solid var(--line); flex-wrap:wrap; }
  .tabs { display:flex; gap:4px; flex-wrap:wrap; }
  .tab { padding:6px 12px; border-radius:6px; font-size:13px; font-weight:600; color:var(--muted); }
  .tab:hover { background:var(--panel); color:var(--text); text-decoration:none; }
  .tab.on { background:var(--brand-soft); color:#fff; }
  .inbox-search { display:flex; gap:6px; }
  .inbox-search input[type=search] { padding:7px 10px; border-radius:6px; border:1px solid var(--line); background:var(--panel); color:var(--text); min-width:260px; }
  tr.inbox-unread { background:#0b39e908; }
  .star-btn { background:transparent; border:none; color:#d4a017; font-size:16px; cursor:pointer; padding:2px 4px; }
  .star-btn:hover { color:#f5b301; }
</style>
