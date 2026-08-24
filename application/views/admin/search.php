<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<div class="page-head">
  <div>
    <p class="eyebrow">Search</p>
    <h2>Find a user</h2>
    <p>Look up a six-digit User ID, username or email address.</p>
  </div>
</div>
<form class="admin-filters" method="get" action="/admin/search">
  <label>Query<input type="search" name="q" value="<?= e($q ?? '') ?>" placeholder="482731 or jane_doe or person@example.com" maxlength="80" autofocus></label>
  <button class="btn primary" type="submit">Search</button>
</form>
<section class="panel">
  <div class="body table-scroll">
    <?php if (($q ?? '') === ''): ?>
      <div class="empty-state"><p>Enter a User ID, username or email to search.</p></div>
    <?php elseif (empty($results)): ?>
      <div class="empty-state"><p>No matching accounts.</p></div>
    <?php else: ?>
      <table class="tbl">
        <thead><tr><th>User ID</th><th>Username</th><th>Email</th><th>Status</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($results as $u): ?>
            <tr>
              <td class="mono"><?= e($u['user_uid'] ?? '') ?></td>
              <td><?= e($u['username'] ?? '') ?></td>
              <td class="dim"><?= e($u['email'] ?? '') ?></td>
              <td><span class="badge <?= !empty($u['active']) ? 'b-green' : 'b-gray' ?>"><?= !empty($u['active']) ? 'Active' : 'Suspended' ?></span></td>
              <td><a class="btn small" href="/admin/users/<?= (int) $u['id'] ?>">Open</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>
