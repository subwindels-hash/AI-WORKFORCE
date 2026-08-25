<?php defined('BASEPATH') or exit('No direct script access allowed'); $m = $member; ?>
<div class="page-head">
  <div>
    <p class="eyebrow">Directory</p>
    <h2>Edit <?= e($m['username'] ?? 'user') ?></h2>
    <p>User ID <span class="mono"><?= e($m['user_uid'] ?? '') ?></span> is permanent and cannot be changed. The current password is never displayed.</p>
  </div>
  <a class="btn" href="/admin/users/<?= (int) $m['id'] ?>">Cancel</a>
</div>
<section class="panel">
  <div class="body">
    <form method="post" action="/admin/users/<?= (int) $m['id'] ?>/edit" class="admin-form" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
      <label>Username<input name="username" required maxlength="20" pattern="[a-z][a-z0-9_]{2,19}" value="<?= e($m['username'] ?? '') ?>"></label>
      <label>Display name<input name="display_name" maxlength="120" value="<?= e($m['display_name'] ?? '') ?>"></label>
      <label>Email<input type="email" name="email" required maxlength="190" value="<?= e($m['email'] ?? '') ?>"></label>
      <label>Role
        <select name="role">
          <?php foreach ($roles as $role): ?>
            <option value="<?= e($role['code']) ?>" <?= ($currentRole ?? '') === $role['code'] ? 'selected' : '' ?>><?= e($role['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Replace profile image<input type="file" name="avatar" accept="image/png,image/jpeg,image/gif,image/webp"></label>
      <button class="btn primary" type="submit">Save changes</button>
    </form>
  </div>
</section>
