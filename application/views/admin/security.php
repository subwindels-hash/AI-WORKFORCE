<?php defined('BASEPATH') or exit('No direct script access allowed');
$o = $overview ?? [];
?>
<div class="page-head">
  <div>
    <p class="eyebrow">Administration</p>
    <h2>Security</h2>
    <p>Login protection, session policy and recent authentication failures. Password hashes are never listed.</p>
  </div>
</div>
<div class="grid four">
  <div class="kp-card"><div class="k">Lockout after</div><div class="v"><?= (int) ($o['loginMaxAttempts'] ?? 5) ?></div><div class="trend">failed attempts</div></div>
  <div class="kp-card"><div class="k">Lockout length</div><div class="v"><?= (int) ($o['loginLockoutSeconds'] ?? 900) ?>s</div><div class="trend">configurable in settings</div></div>
  <div class="kp-card"><div class="k">Registration</div><div class="v"><?= !empty($o['registrationEnabled']) ? 'Open' : 'Closed' ?></div><div class="trend">Public sign-up</div></div>
  <div class="kp-card"><div class="k">Session</div><div class="v"><?= (int) ($session['expiration'] ?? 0) ?>s</div><div class="trend">HttpOnly <?= !empty($session['httponly']) ? 'on' : 'off' ?> · <?= e($session['samesite'] ?? '') ?></div></div>
</div>
<div class="grid cols-main" style="margin-top:16px">
  <section class="panel">
    <h3>Failed logins</h3>
    <div class="body">
      <?php if (empty($o['failedLogins'])): ?>
        <div class="empty-state"><p>No failed login events recorded.</p></div>
      <?php else: ?>
        <div class="feed">
          <?php foreach ($o['failedLogins'] as $ev): ?>
            <div class="row">
              <span class="t">User #<?= (int) ($ev['user_id'] ?? 0) ?></span>
              <span class="d"><?= admin_dt($ev['at'] ?? null) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>
  <section class="panel">
    <h3>Suspended-account sign-in attempts</h3>
    <div class="body">
      <?php if (empty($o['blockedSuspended'])): ?>
        <div class="empty-state"><p>No blocked attempts recorded.</p></div>
      <?php else: ?>
        <div class="feed">
          <?php foreach ($o['blockedSuspended'] as $ev): ?>
            <div class="row">
              <span class="t">User #<?= (int) ($ev['user_id'] ?? 0) ?></span>
              <span class="d"><?= admin_dt($ev['at'] ?? null) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>
</div>
