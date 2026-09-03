<?php defined('BASEPATH') or exit('No direct script access allowed');
$m = $msg ?? [];
?>
<div class="page-head">
  <div>
    <p class="eyebrow"><a href="/admin/inbox" style="color:var(--muted)">Inbox</a></p>
    <h2><?= e($m['subject'] ?? 'Message') ?></h2>
    <p>From <strong><?= e($m['sender_name'] ?? '') ?></strong> &lt;<?= e($m['sender_email'] ?? '') ?>&gt;</p>
  </div>
  <div style="display:flex;gap:8px">
    <?php
      $st = $m['status'] ?? 'new';
      $b = ['new'=>'b-red','open'=>'b-amber','replied'=>'b-green','closed'=>'b-gray','spam'=>'b-gray','archived'=>'b-gray'][$st] ?? 'b-gray';
    ?>
    <span class="badge <?= $b ?>"><?= e(ucfirst($st)) ?></span>
    <?= !empty($m['is_starred']) ? '<span class="badge b-amber">★ Starred</span>' : '' ?>
  </div>
</div>

<div class="grid cols-main">
  <div class="inbox-thread">
    <section class="panel">
      <div class="thread-header">
        <div>
          <strong><?= e($m['sender_name'] ?? '') ?></strong>
          <span class="dim">&lt;<?= e($m['sender_email'] ?? '') ?>&gt;</span>
          <?php if (!empty($m['sender_phone'])): ?><span class="dim"> · <?= e($m['sender_phone']) ?></span><?php endif; ?>
        </div>
        <div class="dim"><?= admin_dt($m['created_at'] ?? null) ?></div>
      </div>
      <?php if (!empty($m['sender_address'])): ?>
        <div class="dim" style="padding:0 20px 6px; font-size:13px"><?= e($m['sender_address']) ?></div>
      <?php endif; ?>
      <div class="thread-body">
        <p style="white-space:pre-wrap"><?= e($m['body'] ?? '') ?></p>
      </div>

      <?php foreach ($replies as $r): ?>
        <div class="thread-reply <?= ($r['direction'] ?? '') === 'inbound' ? 'inbound' : '' ?>">
          <div class="thread-header">
            <div>
              <strong><?= e($r['author_label'] ?? ($r['direction'] === 'inbound' ? $m['sender_name'] : 'Admin')) ?></strong>
              <?php if (!empty($r['to_email'])): ?><span class="dim"> → <?= e($r['to_email']) ?></span><?php endif; ?>
              <?php if (!empty($r['delivery_status']) && $r['delivery_status'] !== 'sent'): ?>
                <span class="badge b-red"><?= e($r['delivery_status']) ?></span>
              <?php endif; ?>
              <?php if (!empty($r['template_id'])): ?><span class="badge b-blue">template</span><?php endif; ?>
            </div>
            <div class="dim"><?= admin_dt($r['sent_at'] ?? null) ?></div>
          </div>
          <div class="reply-subject dim"><?= e($r['subject'] ?? '') ?></div>
          <div class="thread-body">
            <?php if (strip_tags((string)($r['body'] ?? '')) !== (string)($r['body'] ?? '')): ?>
              <?= $r['body'] /* already sanitized server-side & escaped by Mailer; rendered as HTML for composed replies */ ?>
            <?php else: ?>
              <p style="white-space:pre-wrap"><?= e($r['body'] ?? '') ?></p>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </section>

    <section class="panel">
      <h3>Reply to <?= e($m['sender_name'] ?? '') ?></h3>
      <?php if (empty($smtp['enabled'])): ?>
        <div class="empty-state" style="background:#fff7ed;border-color:#fdba74">
          <p><strong>Outbound email is not configured.</strong> Replies will still be saved to the thread, but they won't be delivered until <code>VP_SMTP_ENABLED=1</code> and SMTP credentials are set.</p>
        </div>
      <?php endif; ?>
      <form method="post" action="/admin/inbox/<?= (int)$m['id'] ?>/reply" class="reply-form">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <label>Templates
          <select name="template_id" id="tpl-select">
            <option value="">— Start from blank —</option>
            <?php foreach ($templates as $t): ?>
              <?php if (($t['code'] ?? '') === 'admin_reply'): ?>
                <option value="<?= (int)$t['id'] ?>" data-subject="<?= e($t['subject'] ?? '') ?>"><?= e($t['name'] ?? '') ?></option>
              <?php endif; ?>
            <?php endforeach; ?>
          </select>
        </label>
        <label>To <input type="text" value="<?= e($m['sender_email'] ?? '') ?>" readonly></label>
        <label>Subject <input name="subject" required value="Re: <?= e($m['subject'] ?? '') ?>"></label>
        <label>Reply <textarea name="body" required rows="12" placeholder="Write your reply here…"></textarea></label>
        <div class="reply-actions">
          <button class="btn solid" type="submit">Send reply</button>
          <span class="dim" style="font-size:12px">Sends from <code><?= e($smtp['fromEmail'] ?? 'system') ?></code> via <?= $smtp['enabled'] ? e($smtp['host'] . ':' . $smtp['port']) : 'SMTP (disabled)' ?></span>
        </div>
      </form>
    </section>
  </div>

  <aside class="inbox-side">
    <section class="panel">
      <h3>Conversation</h3>
      <form method="post" action="/admin/inbox/<?= (int)$m['id'] ?>/status" style="margin-bottom:12px">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <label>Status
          <select name="status" onchange="this.form.submit()">
            <?php foreach (['new','open','replied','closed','spam','archived'] as $s): ?>
              <option value="<?= $s ?>" <?= $st === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </form>
      <form method="post" action="/admin/inbox/<?= (int)$m['id'] ?>/star?return=<?= urlencode('/admin/inbox/' . (int)$m['id']) ?>">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <button class="btn small ghost" type="submit"><?= !empty($m['is_starred']) ? 'Remove star' : '★ Star conversation' ?></button>
      </form>
    </section>
    <section class="panel">
      <h3>Contact details</h3>
      <dl class="kv">
        <dt>Name</dt><dd><?= e($m['sender_name'] ?? '') ?></dd>
        <dt>Email</dt><dd><a href="mailto:<?= e($m['sender_email']) ?>"><?= e($m['sender_email']) ?></a></dd>
        <dt>Phone</dt><dd><?= e($m['sender_phone'] ?? '—') ?></dd>
        <dt>Address</dt><dd style="white-space:pre-wrap"><?= e($m['sender_address'] ?? '—') ?></dd>
        <dt>IP</dt><dd class="mono dim" style="font-size:12px"><?= e($m['ip'] ?? '—') ?></dd>
        <dt>Source</dt><dd><?= e($m['source'] ?? 'contact_form') ?></dd>
        <?php if (!empty($m['user_id'])): ?><dt>User</dt><dd><a href="/admin/users/<?= (int)$m['user_id'] ?>">#<?= (int)$m['user_id'] ?></a></dd><?php endif; ?>
      </dl>
    </section>
    <section class="panel danger">
      <h3>Danger</h3>
      <form method="post" action="/admin/inbox/<?= (int)$m['id'] ?>/delete" onsubmit="return confirm('Delete this entire conversation permanently?')">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <button class="btn small danger" type="submit">Delete conversation</button>
      </form>
    </section>
  </aside>
</div>
<style>
  .inbox-thread { display:flex; flex-direction:column; gap:16px; min-width:0; }
  .thread-header { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; padding:14px 20px; border-bottom:1px solid var(--line); }
  .thread-body { padding:16px 20px; line-height:1.6; }
  .thread-reply { border-top:1px solid var(--line); background:#f8fafc; }
  .thread-reply.inbound { background:#f1f5f9; }
  .reply-subject { padding:0 20px; font-size:13px; }
  .reply-form label { display:block; margin-bottom:10px; font-size:13px; font-weight:600; color:var(--muted) }
  .reply-form input, .reply-form select, .reply-form textarea { width:100%; margin-top:4px; padding:9px 10px; border-radius:6px; border:1px solid var(--line); background:#fff; color:var(--text); font-weight:400 }
  .reply-form textarea { font-family:inherit; font-size:14px; resize:vertical }
  .reply-actions { display:flex; align-items:center; gap:12px; padding-top:6px }
  .inbox-side { display:flex; flex-direction:column; gap:16px; }
  .kv { display:grid; grid-template-columns:80px 1fr; gap:6px 12px; font-size:13px; margin:0 }
  .kv dt { color:var(--muted); margin:0 }
  .kv dd { margin:0; word-break:break-word }
  .panel.danger { border-color:#fecaca }
  .btn.danger { background:#dc2626; color:#fff; border-color:#dc2626 }
  .b-blue { background:#dbeafe; color:#1d4ed8 }
</style>
