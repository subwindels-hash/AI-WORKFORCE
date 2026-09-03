<?php defined('BASEPATH') or exit('No direct script access allowed');
/** @var array $member @var array $thread @var bool $canManage @var string $csrfToken */
?>
<div class="page-head">
  <div>
    <p class="eyebrow">Support conversation</p>
    <h2><?= e($member['username'] ?? $member['display_name'] ?? 'Member') ?></h2>
    <p>Permanent User ID <span class="mono"><?= e($member['user_uid'] ?? '') ?></span> · <?= e($member['email'] ?? '') ?></p>
  </div>
  <div class="page-actions">
    <a class="btn" href="/admin/messages">Back to messages</a>
    <a class="btn" href="/admin/users/<?= (int) $member['id'] ?>">View profile</a>
  </div>
</div>

<section class="panel">
  <h3><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"/></svg> Thread</h3>
  <div class="body">
    <?php if (!$thread): ?>
      <div class="empty-state">
        <p>No messages in this thread yet. Start the conversation below — the member sees it in their Messages page.</p>
      </div>
    <?php else: ?>
      <div class="dm-thread" id="dm-thread">
        <?php foreach ($thread as $m): $fromAdmin = ($m['sender_role'] ?? '') === 'admin'; ?>
          <div class="dm-msg <?= $fromAdmin ? 'dm-outgoing' : 'dm-incoming' ?>">
            <div class="dm-meta">
              <span class="dm-who"><?= $fromAdmin ? e($m['sender_label'] ?: 'Support') : e($member['username'] ?? 'Member') ?></span>
              <span><?= e(str_replace('T', ' ', substr((string) $m['created_at'], 0, 16))) ?></span>
            </div>
            <div class="dm-body"><?= e($m['body']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($canManage)): ?>
      <form method="post" action="/admin/messages/send" class="dm-send">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <input type="hidden" name="user" value="<?= (int) $member['id'] ?>">
        <label class="auth-field" style="flex:1">
          <textarea name="body" rows="3" maxlength="<?= \AIWorkforce\Messaging\DirectMessages::MAX_BODY ?>" required placeholder="Reply to <?= e($member['username'] ?? 'this member') ?>…"></textarea>
        </label>
        <button class="btn primary" type="submit">Send reply</button>
      </form>
      <p class="dim" style="margin:10px 0 0;font-size:12px">Your reply appears in the member's Messages page and raises their unread badge.</p>
    <?php else: ?>
      <p class="dim" style="margin-top:12px;font-size:12px">Read-only — reply permission (admin.users.manage) is required to send messages.</p>
    <?php endif; ?>
  </div>
</section>

<script>
  (function () {
    var el = document.getElementById('dm-thread');
    if (el) el.scrollTop = el.scrollHeight;
  })();
</script>
