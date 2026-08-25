<?php defined('BASEPATH') or exit('No direct script access allowed');
$q = (string) ($q ?? '');
$statusFilter = (string) ($statusFilter ?? '');
$sort = (string) ($sort ?? 'created_at');
$dir = (string) ($dir ?? 'DESC');
$nextDir = $dir === 'ASC' ? 'DESC' : 'ASC';
$qs = function(array $over) use ($q, $statusFilter, $sort, $dir) {
    return '?' . http_build_query(array_filter(array_merge([
        'q' => $q, 'status' => $statusFilter, 'sort' => $sort, 'dir' => $dir,
    ], $over), fn($v) => $v !== '' && $v !== null));
};
$col = function(string $key, string $label) use ($sort, $nextDir, $qs) {
    $href = $qs(['sort' => $key, 'dir' => $sort === $key ? $nextDir : 'ASC', 'page' => 1]);
    $mark = $sort === $key ? ' <span class="dim">↕</span>' : '';
    return '<a href="' . e($href) . '">' . e($label) . $mark . '</a>';
};
?>
<div class="page-head">
  <div>
    <p class="eyebrow">Directory</p>
    <h2>Users</h2>
    <p>Search by six-digit User ID, username or email. Status changes are enforced at sign-in.</p>
  </div>
  <div class="page-actions">
    <?php if (!empty($canManage)): ?><a class="btn primary" href="/admin/users/create">Create user</a><?php endif; ?>
  </div>
</div>

<form class="admin-filters" method="get" action="/admin/users">
  <label>Search<input type="search" name="q" value="<?= e($q) ?>" placeholder="User ID, username or email" maxlength="80"></label>
  <label>Status
    <select name="status">
      <option value="">All</option>
      <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
      <option value="suspended" <?= $statusFilter === 'suspended' ? 'selected' : '' ?>>Suspended</option>
    </select>
  </label>
  <input type="hidden" name="sort" value="<?= e($sort) ?>">
  <input type="hidden" name="dir" value="<?= e($dir) ?>">
  <button class="btn" type="submit">Apply</button>
</form>

<section class="panel">
  <div class="body table-scroll">
    <?php if (empty($rows)): ?>
      <div class="empty-state"><p>No users match this search.</p></div>
    <?php else: ?>
      <table class="tbl">
        <thead>
          <tr>
            <th><?= $col('user_uid', 'User ID') ?></th>
            <th><?= $col('username', 'Username') ?></th>
            <th><?= $col('email', 'Email') ?></th>
            <th><?= $col('active', 'Status') ?></th>
            <th><?= $col('created_at', 'Created') ?></th>
            <th><?= $col('last_login_at', 'Last login') ?></th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $u): ?>
            <tr>
              <td class="mono"><a href="/admin/users/<?= (int) $u['id'] ?>"><?= e($u['user_uid'] ?? '') ?></a></td>
              <td><?= e($u['username'] ?? '') ?></td>
              <td class="dim"><?= e($u['email'] ?? '') ?></td>
              <td><span class="badge <?= !empty($u['active']) ? 'b-green' : 'b-gray' ?>"><?= !empty($u['active']) ? 'Active' : 'Suspended' ?></span></td>
              <td class="dim"><?= admin_dt($u['created_at'] ?? null) ?></td>
              <td class="dim"><?= admin_dt($u['last_login_at'] ?? null, 'Never') ?></td>
              <td>
                <div class="admin-actions">
                  <a class="btn small" href="/admin/users/<?= (int) $u['id'] ?>">View</a>
                  <?php if (!empty($canManage)): ?><a class="btn small" href="/admin/users/<?= (int) $u['id'] ?>/edit">Edit</a><?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
  <?php if (($pages ?? 1) > 1): ?>
    <div class="admin-pager">
      <?php if ($page > 1): ?><a class="btn small" href="<?= e($qs(['page' => $page - 1])) ?>">Previous</a><?php endif; ?>
      <span class="dim">Page <?= (int) $page ?> of <?= (int) $pages ?> · <?= (int) $total ?> users</span>
      <?php if ($page < $pages): ?><a class="btn small" href="<?= e($qs(['page' => $page + 1])) ?>">Next</a><?php endif; ?>
    </div>
  <?php endif; ?>
</section>
