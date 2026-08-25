<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<div class="page-head">
  <div>
    <p class="eyebrow">Administration</p>
    <h2>Admin Accounts</h2>
    <p>Super Admin, Admin and Support Admin. Permissions are assigned by role — not every administrator has full control.</p>
  </div>
</div>

<section class="panel">
  <h3>Create administrator</h3>
  <div class="body">
    <form method="post" action="/admin/admins/create" class="admin-form">
      <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
      <label>Username<input name="username" required maxlength="20" pattern="[a-z][a-z0-9_]{2,19}"></label>
      <label>Email<input type="email" name="email" required maxlength="190"></label>
      <label>Temporary password<input type="password" name="password" required minlength="12" autocomplete="new-password"></label>
      <label>Role
        <select name="role">
          <?php foreach ($adminRoles as $role): ?>
            <option value="<?= e($role['code']) ?>"><?= e($role['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button class="btn primary" type="submit">Create admin</button>
    </form>
  </div>
</section>

<section class="panel" style="margin-top:14px">
  <h3>Administrators</h3>
  <div class="body table-scroll">
    <table class="tbl">
      <thead><tr><th>Username</th><th>Email</th><th>Role</th><th>Status</th><th>Last login</th><th>Created</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($admins as $a): $role = $a['roles'][0]['code'] ?? ''; ?>
          <tr>
            <td><a href="/admin/users/<?= (int) $a['id'] ?>"><?= e($a['username'] ?? '') ?></a></td>
            <td class="dim"><?= e($a['email'] ?? '') ?></td>
            <td>
              <form method="post" action="/admin/admins/<?= (int) $a['id'] ?>" class="admin-inline">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <select name="role">
                  <?php foreach ($adminRoles as $opt): ?>
                    <option value="<?= e($opt['code']) ?>" <?= $role === $opt['code'] ? 'selected' : '' ?>><?= e($opt['name']) ?></option>
                  <?php endforeach; ?>
                </select>
                <input type="hidden" name="active" value="<?= !empty($a['active']) ? '1' : '0' ?>">
                <button class="btn small" type="submit">Update role</button>
              </form>
            </td>
            <td>
              <form method="post" action="/admin/admins/<?= (int) $a['id'] ?>" class="admin-inline">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="role" value="<?= e($role ?: 'admin') ?>">
                <input type="hidden" name="active" value="<?= !empty($a['active']) ? '0' : '1' ?>">
                <button class="btn small <?= !empty($a['active']) ? 'danger' : '' ?>" type="submit"><?= !empty($a['active']) ? 'Disable' : 'Enable' ?></button>
              </form>
            </td>
            <td class="dim"><?= admin_dt($a['last_login_at'] ?? null, 'Never') ?></td>
            <td class="dim"><?= admin_dt($a['created_at'] ?? null) ?></td>
            <td><span class="badge <?= !empty($a['active']) ? 'b-green' : 'b-gray' ?>"><?= !empty($a['active']) ? 'Active' : 'Disabled' ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
