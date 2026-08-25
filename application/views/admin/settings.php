<?php defined('BASEPATH') or exit('No direct script access allowed');
$set = $settings ?? [];
$general = $set['general'] ?? [];
$ai = $set['ai'] ?? [];
$security = $set['security'] ?? [];
$accounts = $set['accounts'] ?? [];
$smtp = $smtp ?? [];
?>
<div class="page-head">
  <div>
    <p class="eyebrow">Administration</p>
    <h2>System Settings</h2>
    <p>Settings are grouped by category. SMTP passwords and API keys stay in the server environment and are never shown here.</p>
  </div>
</div>

<section class="panel" id="general">
  <h3>General</h3>
  <div class="body">
    <form method="post" action="/admin/settings/save" class="admin-form">
      <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
      <input type="hidden" name="category" value="general">
      <label>Product name<input name="product_name" maxlength="120" value="<?= e($general['product_name'] ?? 'WINDELS AI WORKFORCE') ?>"></label>
      <label>Contact name<input name="contact_name" maxlength="120" value="<?= e($general['contact_name'] ?? '') ?>"></label>
      <label>Contact email<input type="email" name="contact_email" maxlength="190" value="<?= e($general['contact_email'] ?? '') ?>"></label>
      <button class="btn primary" type="submit">Save general</button>
    </form>
  </div>
</section>

<section class="panel" id="ai" style="margin-top:14px">
  <h3>AI</h3>
  <div class="body">
    <p class="dim">Feature flags stored for operators. They do not invent usage numbers.</p>
    <form method="post" action="/admin/settings/save" class="admin-form">
      <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
      <input type="hidden" name="category" value="ai">
      <label class="choice"><input type="checkbox" name="ai_analysis_enabled" value="1" <?= ($ai['ai_analysis_enabled'] ?? '1') === '1' ? 'checked' : '' ?>> AI analysis marked available</label>
      <label class="choice"><input type="checkbox" name="language_learning_enabled" value="1" <?= ($ai['language_learning_enabled'] ?? '1') === '1' ? 'checked' : '' ?>> Language learning marked available</label>
      <button class="btn primary" type="submit">Save AI flags</button>
    </form>
  </div>
</section>

<section class="panel" id="email" style="margin-top:14px">
  <h3>Email</h3>
  <div class="body">
    <p class="dim">Outbound mail is configured on the server only. Credentials are never stored in this form or returned to the browser.</p>
    <div class="stat-grid" style="margin:10px 0">
      <div class="stat"><div class="k">Status</div><div class="v"><span class="badge <?= !empty($smtp['enabled']) ? 'b-green' : 'b-gray' ?>"><?= !empty($smtp['enabled']) ? 'ENABLED' : 'DISABLED' ?></span></div></div>
      <div class="stat"><div class="k">Host</div><div class="v" style="font-size:13px"><?= e($smtp['host'] ?? '—') ?></div></div>
      <div class="stat"><div class="k">Port / TLS</div><div class="v" style="font-size:13px"><?= e((string) ($smtp['port'] ?? '—')) ?> · <?= e(strtoupper((string) ($smtp['crypto'] ?? '')) ?: '—') ?></div></div>
      <div class="stat"><div class="k">Auth</div><div class="v"><span class="badge <?= !empty($smtp['usernameConfigured']) && !empty($smtp['passwordConfigured']) ? 'b-green' : 'b-gray' ?>"><?= (!empty($smtp['usernameConfigured']) && !empty($smtp['passwordConfigured'])) ? 'READY' : 'MISSING' ?></span></div></div>
    </div>
    <?php if (!empty($smtpOk)): ?><div class="notice ok"><?= e($smtpOk) ?></div><?php endif; ?>
    <?php if (!empty($smtpError)): ?><div class="notice err"><?= e($smtpError) ?></div><?php endif; ?>
    <form method="post" action="/admin/test-email" class="admin-form">
      <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
      <label>Send a test email to<input type="email" name="to" required placeholder="you@example.com"></label>
      <label>Format<select name="variant"><option value="html">HTML</option><option value="plain">Plain text</option></select></label>
      <button class="btn primary" type="submit">Send test email</button>
    </form>
  </div>
</section>

<section class="panel" id="security" style="margin-top:14px">
  <h3>Security</h3>
  <div class="body">
    <form method="post" action="/admin/settings/save" class="admin-form">
      <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
      <input type="hidden" name="category" value="security">
      <label>Failed login attempts before lockout<input type="number" name="login_max_attempts" min="3" max="20" value="<?= e($security['login_max_attempts'] ?? '5') ?>"></label>
      <label>Lockout duration (seconds)<input type="number" name="login_lockout_seconds" min="60" max="86400" value="<?= e($security['login_lockout_seconds'] ?? '900') ?>"></label>
      <button class="btn primary" type="submit">Save security</button>
    </form>
    <p class="dim" style="margin-top:12px">Session cookie: <?= (int) ($session['expiration'] ?? 0) ?>s · HttpOnly <?= !empty($session['httponly']) ? 'on' : 'off' ?> · SameSite <?= e($session['samesite'] ?? '') ?> · Secure <?= !empty($session['secure']) ? 'on' : 'off' ?>. Those values come from server configuration and are not edited here.</p>
  </div>
</section>

<section class="panel" id="accounts" style="margin-top:14px">
  <h3>User Accounts</h3>
  <div class="body">
    <form method="post" action="/admin/settings/save" class="admin-form">
      <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
      <input type="hidden" name="category" value="accounts">
      <label class="choice"><input type="checkbox" name="registration_enabled" value="1" <?= ($accounts['registration_enabled'] ?? '1') === '1' ? 'checked' : '' ?>> Allow public registration</label>
      <button class="btn primary" type="submit">Save account settings</button>
    </form>
  </div>
</section>
