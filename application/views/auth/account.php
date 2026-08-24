<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<div class="page-head">
  <div><p class="eyebrow">Secure account</p><h2>Your account</h2><p>Review your identity, access scope and session controls.</p></div>
  <form method="post" action="/logout"><input type="hidden" name="csrf_token" value="<?= e((string) $this->session->userdata('csrf_token')) ?>"><button class="btn danger" type="submit">Sign out</button></form>
</div>
<?php if (!empty($notice)): ?><div class="notice ok"><?= e($notice) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="notice err"><?= e($error) ?></div><?php endif; ?>
<div class="grid cols-main">
  <section class="panel"><h3>Profile</h3><div class="body">
    <div class="account-hero"><img src="/assets/images/aegis-mark.png" alt="AEGIS" class="account-mark"><div><h3><?= e((string) ($user['display_name'] ?? 'Platform user')) ?></h3><p class="dim"><?= e((string) ($user['email'] ?? '')) ?></p></div></div>
    <table class="tbl mono" style="margin-top:16px"><tr><td class="dim">User ID</td><td><?= e((string) $user['id']) ?></td></tr><tr><td class="dim">Status</td><td><span class="badge b-green">ACTIVE</span></td></tr><tr><td class="dim">Last login</td><td><?= e((string) ($user['last_login_at'] ?? 'Not recorded')) ?></td></tr></table>
  </div></section>
  <section class="panel"><h3>Permissions</h3><div class="body"><p class="dim" style="font-size:12px">Your access is determined by assigned RBAC roles. Sensitive actions still require CSRF and the relevant permission.</p><div class="permission-list"><?php foreach (($user['permissions'] ?? []) as $permission): ?><span class="badge b-violet"><?= e((string) $permission) ?></span><?php endforeach; ?></div></div></section>
</div>
<div class="panel" style="margin-top:14px"><h3>Security</h3><div class="body"><p class="dim">Authentication uses the password hash stored in the database and sessions use the configured server-side session driver. Preserve <span class="mono">VP_ENCRYPTION_KEY</span> when migrating this installation to another cPanel account.</p><?php if ($this->platform->identity->can($user, 'system.super_admin')): ?><a class="btn" href="/admin">Open administrator controls</a><?php endif; ?></div></div>
