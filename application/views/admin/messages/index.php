<?php defined('BASEPATH') or exit('No direct script access allowed');
/** @var array $threads @var array $counts @var string $search @var bool $canManage @var string $csrfToken */
?>
<div class="page-head">
  <div>
    <p class="eyebrow">Support</p>
    <h2>Messages</h2>
    <p>Direct conversations between members and the support team. <?= (int) ($counts['unreadThreads'] ?? 0) ?> conversation<?= ($counts['unreadThreads'] ?? 0) === 1 ? '' : 's' ?> waiting for a reply<?= ($counts['unreadThreads'] ?? 0) === 1 ? '' : 's' ?>.</p>
  </div>
  <div class="page-actions">
    <span class="badge b-sky"><?= (int) ($counts['threads'] ?? 0) ?> threads</span>
    <?php if (($counts['unreadMessages'] ?? 0) > 0): ?><span class="badge b-red"><?= (int) $counts['unreadMessages'] ?> unread</span><?php endif; ?>
  </div>
</div>

<?php if (!empty($canManage)): ?>
<section class="panel">
  <h3><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/></svg> Start a conversation</h3>
  <div class="body">
    <form method="post" action="/admin/messages/send">
      <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
      <div class="admin-filters" style="margin:0 0 12px">
        <label>Recipient
          <input type="text" name="user" placeholder="User ID, username or email" maxlength="190" required>
        </label>
      </div>
      <label class="auth-field" style="display:grid;gap:6px;margin-bottom:12px">
        <span style="font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:var(--dim);font-weight:700">Message</span>
        <textarea name="body" rows="3" maxlength="<?= \AIWorkforce\Messaging\DirectMessages::MAX_BODY ?>" required placeholder="Write the first message to this member…"></textarea>
      </label>
      <button class="btn primary" type="submit">Send message</button>
      <span class="dim" style="font-size:12px;margin-left:10px">Opens (or continues) the member's support thread.</span>
    </form>
  </div>
</section>
<?php endif; ?>

<form class="admin-filters" method="get" action="/admin/messages">
  <label>Search<input type="search" name="q" value="<?= e($search) ?>" placeholder="Member or message text" maxlength="80"></label>
  <button class="btn" type="submit">Apply</button>
  <?php if ($search !== ''): ?><a class="btn ghost" href="/admin/messages">Clear</a><?php endif; ?>
</form>

<section class="panel">
  <div class="body table-scroll">
    <?php if (!$threads): ?>
      <div class="empty-state">
        <p><?= $search !== '' ? 'No conversations match this search.' : 'No member conversations yet. Members start threads from the Messages page in their dashboard.' ?></p>
      </div>
    <?php else: ?>
      <table class="tbl">
        <thead>
          <tr>
            <th>Member</th>
            <th>Status</th>
            <th>Last message</th>
            <th>Unread</th>
            <th>Last activity</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($threads as $t): ?>
            <tr>
              <td>
                <a href="/admin/users/<?= (int) $t['user_id'] ?>"><b><?= e($t['username'] ?? $t['display_name'] ?? ('user ' . $t['user_id'])) ?></b></a>
                <div class="dim mono" style="font-size:11px"><?= e($t['user_uid'] ?? '') ?> · <?= e($t['email'] ?? '') ?></div>
              </td>
              <td><span class="badge <?= !empty($t['active']) ? 'b-green' : 'b-gray' ?>"><?= !empty($t['active']) ? 'Active' : 'Suspended' ?></span></td>
              <td style="max-width:380px">
                <span class="dim" style="font-size:11px"><?= ($t['last_sender_role'] ?? '') === 'admin' ? 'You (' . e($t['last_sender_label'] ?? '') . ')' : 'Member' ?>:</span>
                <?= e(\AIWorkforce\Messaging\DirectMessages::preview((string) $t['last_body'])) ?>
              </td>
              <td><?php if (($t['unread'] ?? 0) > 0): ?><span class="badge b-red"><?= (int) $t['unread'] ?></span><?php else: ?><span class="dim">—</span><?php endif; ?></td>
              <td class="dim"><?= admin_dt($t['last_at'] ?? null) ?></td>
              <td><a class="btn small" href="/admin/messages/user/<?= (int) $t['user_id'] ?>">Open</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>
