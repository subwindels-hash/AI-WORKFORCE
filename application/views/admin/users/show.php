<?php defined('BASEPATH') or exit('No direct script access allowed');
$m = $member;
$bundle = $bundle ?? [];
$roles = $bundle['roles'] ?? [];
$events = $bundle['authEvents'] ?? [];
$profiles = $bundle['languageProfiles'] ?? [];
$initial = strtoupper(mb_substr(preg_replace('/[^A-Za-z0-9 ]/', '', (string) ($m['display_name'] ?? $m['username'] ?? 'U')), 0, 1) ?: 'U');
?>
<div class="page-head">
  <div>
    <p class="eyebrow">User profile</p>
    <h2><?= e($m['username'] ?? $m['display_name'] ?? 'User') ?></h2>
    <p>Permanent User ID <span class="mono"><?= e($m['user_uid'] ?? '') ?></span>. The current password is never displayed.</p>
  </div>
  <div class="page-actions">
    <a class="btn" href="/admin/users">Back to users</a>
    <?php if (!empty($canManage)): ?><a class="btn" href="/admin/users/<?= (int) $m['id'] ?>/edit">Edit</a><?php endif; ?>
    <?php if (!empty($canImpersonate)): ?>
      <form method="post" action="/admin/users/<?= (int) $m['id'] ?>/impersonate" onsubmit="return confirm('Sign in as this user? The action is recorded in the activity log.');">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <button class="btn primary" type="submit">Login as User</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($tempPassword)): ?>
  <div class="notice warnbox">
    <strong>Temporary password (shown once):</strong>
    <code class="mono"><?= e($tempPassword) ?></code>
    <span>Give this to the user and ask them to change it after signing in. It is not stored in readable form.</span>
  </div>
<?php endif; ?>

<section class="panel">
  <h3>Account</h3>
  <div class="body">
    <div class="account-hero">
      <?php if (!empty($m['profile_image'])): ?>
        <img class="account-mark" src="<?= e($m['profile_image']) ?>" alt="">
      <?php else: ?>
        <div class="avatar-lg avatar-lg--initial"><?= e($initial) ?></div>
      <?php endif; ?>
      <div>
        <h3><?= e($m['display_name'] ?? $m['username'] ?? '') ?></h3>
        <p class="dim"><?= e($m['email'] ?? '') ?></p>
      </div>
    </div>
    <table class="tbl" style="margin-top:16px">
      <tbody>
        <tr><td class="dim">User ID</td><td class="mono"><?= e($m['user_uid'] ?? '') ?></td></tr>
        <tr><td class="dim">Username</td><td><?= e($m['username'] ?? '') ?></td></tr>
        <tr><td class="dim">Email</td><td><?= e($m['email'] ?? '') ?></td></tr>
        <tr><td class="dim">Account status</td><td><span class="badge <?= !empty($m['active']) ? 'b-green' : 'b-gray' ?>"><?= !empty($m['active']) ? 'Active' : 'Suspended' ?></span></td></tr>
        <tr><td class="dim">Registration date</td><td><?= admin_dt($m['created_at'] ?? null) ?></td></tr>
        <tr><td class="dim">Last login</td><td><?= admin_dt($m['last_login_at'] ?? null, 'Never') ?></td></tr>
        <tr><td class="dim">Roles</td><td><?php foreach ($roles as $role): ?><span class="badge b-violet"><?= e($role['name'] ?? $role['code']) ?></span> <?php endforeach; ?></td></tr>
      </tbody>
    </table>
    <?php if (!empty($canManage)): ?>
      <div class="admin-actions" style="margin-top:16px">
        <?php if (!empty($m['active'])): ?>
          <form method="post" action="/admin/users/<?= (int) $m['id'] ?>/suspend" onsubmit="return confirm('Suspend this account? The user will not be able to sign in.');">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <button class="btn danger" type="submit">Suspend Account</button>
          </form>
        <?php else: ?>
          <form method="post" action="/admin/users/<?= (int) $m['id'] ?>/activate">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <button class="btn primary" type="submit">Activate Account</button>
          </form>
        <?php endif; ?>
        <form method="post" action="/admin/users/<?= (int) $m['id'] ?>/reset-password" onsubmit="return confirm('Reset this password? The current password is never shown. A temporary password will be displayed once.');">
          <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
          <button class="btn" type="submit">Reset Password</button>
        </form>
        <?php if (!empty($canDelete)): ?>
          <form method="post" action="/admin/users/<?= (int) $m['id'] ?>/delete" onsubmit="return confirm('Delete this user permanently? Prefer suspend when related records exist.');">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <button class="btn danger" type="submit">Delete user</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<div class="grid cols-main" style="margin-top:16px">
  <section class="panel">
    <h3>Language-learning activity</h3>
    <div class="body">
      <?php if (!$profiles): ?>
        <div class="empty-state"><p>No language profiles for this user.</p></div>
      <?php else: ?>
        <table class="tbl">
          <thead><tr><th>Language</th><th>Level</th><th>Status</th><th>Updated</th></tr></thead>
          <tbody>
            <?php foreach ($profiles as $p): ?>
              <tr>
                <td><?= e($p['language_code'] ?? '') ?></td>
                <td><?= e($p['level'] ?? '') ?></td>
                <td><?= e($p['status'] ?? '') ?></td>
                <td class="dim"><?= admin_dt($p['updated_at'] ?? null) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
      <p class="dim" style="margin-top:12px"><?= (int) ($bundle['studySessions'] ?? 0) ?> study sessions · <?= (int) ($bundle['conversations'] ?? 0) ?> conversations</p>
    </div>
  </section>
  <section class="panel">
    <h3>Account activity</h3>
    <div class="body">
      <?php if (!$events): ?>
        <div class="empty-state"><p>No authentication events recorded.</p></div>
      <?php else: ?>
        <div class="feed">
          <?php foreach ($events as $ev): ?>
            <div class="row">
              <span class="t"><?= e(str_replace('_', ' ', (string) ($ev['type'] ?? ''))) ?></span>
              <span class="d"><?= admin_dt($ev['at'] ?? null) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>
</div>
