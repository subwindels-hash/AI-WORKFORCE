<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<div class="page-head">
  <div>
    <p class="eyebrow">Directory</p>
    <h2>Create user</h2>
    <p>A unique six-digit User ID is assigned automatically and never reused. The password is hashed immediately and is not shown again.</p>
  </div>
  <a class="btn" href="/admin/users">Cancel</a>
</div>
<section class="panel">
  <div class="body">
    <form method="post" action="/admin/users/create" class="admin-form">
      <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
      <label>Username<input name="username" required maxlength="20" pattern="[a-z][a-z0-9_]{2,19}" placeholder="jane_doe"></label>
      <label>Email<input type="email" name="email" required maxlength="190" placeholder="person@example.com"></label>
      <label>Temporary password<input type="password" name="password" required minlength="12" autocomplete="new-password"></label>
      <label>Role
        <select name="role">
          <?php foreach ($roles as $role): ?>
            <option value="<?= e($role['code']) ?>"><?= e($role['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button class="btn primary" type="submit">Create account</button>
    </form>
  </div>
</section>
