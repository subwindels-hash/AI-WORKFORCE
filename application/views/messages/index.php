<?php defined('BASEPATH') or exit('No direct script access allowed');
/** @var array $thread @var string $csrfToken @var string|null $notice @var string|null $error */
?>
<div class="page-head">
  <div>
    <h2>Messages</h2>
    <p>Your direct line to the support team. Ask a question, report a problem or request account help — administrators answer from the operations portal and their replies land in this thread.</p>
  </div>
</div>

<?php if (!empty($notice)): ?><div class="notice ok"><?= e($notice) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="notice err"><?= e($error) ?></div><?php endif; ?>

<section class="panel">
  <h3><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"/></svg> Support conversation</h3>
  <div class="body">
    <?php if (!$thread): ?>
      <div class="empty-state">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"/></svg>
        <p>No messages yet — start the conversation below.</p>
      </div>
    <?php else: ?>
      <div class="dm-thread" id="dm-thread">
        <?php foreach ($thread as $m): $fromAdmin = ($m['sender_role'] ?? '') === 'admin'; ?>
          <div class="dm-msg <?= $fromAdmin ? 'dm-incoming' : 'dm-outgoing' ?>">
            <div class="dm-meta">
              <span class="dm-who"><?= $fromAdmin ? e($m['sender_label'] ?: 'Support team') : 'You' ?></span>
              <span><?= e(str_replace('T', ' ', substr((string) $m['created_at'], 0, 16))) ?></span>
            </div>
            <div class="dm-body"><?= e($m['body']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="post" action="/messages/send" class="dm-send">
      <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
      <label class="auth-field" style="flex:1">
        <textarea name="body" rows="3" maxlength="<?= \AIWorkforce\Messaging\DirectMessages::MAX_BODY ?>" required
          placeholder="Write a message to the support team…"></textarea>
      </label>
      <button class="btn primary" type="submit">Send message</button>
    </form>
    <p class="dim" style="margin:10px 0 0;font-size:12px">Messages are delivered to platform administrators. Please never include passwords or broker API tokens.</p>
  </div>
</section>

<script>
  (function () {
    var el = document.getElementById('dm-thread');
    if (el) el.scrollTop = el.scrollHeight;
  })();
</script>
