<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<div class="page-head">
  <div>
    <p class="eyebrow"><a href="/admin/inbox" style="color:var(--muted)">Inbox</a></p>
    <h2>Email templates</h2>
    <p>Organized, reusable templates for contact replies, auto-replies, account notifications and internal alerts.</p>
  </div>
  <a class="btn solid" href="/admin/inbox/templates/new">+ New template</a>
</div>

<div class="panel">
  <div class="inbox-toolbar">
    <div class="tabs">
      <?php foreach (['all','contact','account','internal','marketing','general'] as $cat): ?>
        <a class="tab <?= $category === $cat ? 'on' : '' ?>" href="?category=<?= $cat ?>"><?= ucfirst($cat) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="body table-scroll">
    <?php if (!$templates): ?>
      <div class="empty-state"><p>No templates in this category yet.</p></div>
    <?php else: ?>
      <table class="tbl">
        <thead><tr><th>Name</th><th>Code</th><th>Category</th><th>Status</th><th>Subject</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($templates as $t): ?>
            <tr>
              <td><a href="/admin/inbox/templates/<?= (int)$t['id'] ?>/edit"><strong><?= e($t['name']) ?></strong></a>
                <?php if (!empty($t['description'])): ?><div class="dim" style="font-size:12px"><?= e($t['description']) ?></div><?php endif; ?>
              </td>
              <td class="mono"><?= e($t['code']) ?> <?= !empty($t['is_system']) ? '<span class="badge b-blue">system</span>' : '' ?></td>
              <td><span class="badge b-gray"><?= e($t['category']) ?></span></td>
              <td><?= !empty($t['is_active']) ? '<span class="badge b-green">Active</span>' : '<span class="badge b-gray">Disabled</span>' ?></td>
              <td class="dim" style="max-width:360px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($t['subject']) ?></td>
              <td>
                <div class="admin-actions">
                  <a class="btn small" href="/admin/inbox/templates/<?= (int)$t['id'] ?>/edit">Edit</a>
                  <?php if (empty($t['is_system'])): ?>
                    <form method="post" action="/admin/inbox/templates/<?= (int)$t['id'] ?>/delete" onsubmit="return confirm('Delete this template?')" style="display:inline">
                      <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                      <button class="btn small danger" type="submit">Delete</button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
<div class="panel" style="margin-top:16px">
  <h3>Merge variables</h3>
  <div class="body">
    <p class="dim" style="margin-top:0">Use <code>{{variable_name}}</code> in subject and body. Available variables depend on the email; common ones include:</p>
    <p class="mono" style="background:#f8fafc;padding:10px;border-radius:6px;font-size:12px">{{site_name}} · {{name}} · {{email}} · {{phone}} · {{address}} · {{subject}} · {{message}} · {{reply_body}} · {{original_message}} · {{original_message_date}} · {{signature_name}} · {{temporary_password}} · {{login_url}} · {{contact_email}} · {{inbox_url}}</p>
  </div>
</div>
<style>
  .inbox-toolbar { display:flex; padding:12px 16px; border-bottom:1px solid var(--line); flex-wrap:wrap; gap:12px; }
  .tabs { display:flex; gap:4px; flex-wrap:wrap; }
  .tab { padding:6px 12px; border-radius:6px; font-size:13px; font-weight:600; color:var(--muted); }
  .tab:hover { background:var(--panel); color:var(--text); text-decoration:none; }
  .tab.on { background:var(--brand-soft); color:#fff; }
  .btn.danger { background:#dc2626; color:#fff; border-color:#dc2626 }
</style>
