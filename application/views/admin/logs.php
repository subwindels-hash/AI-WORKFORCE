<?php defined('BASEPATH') or exit('No direct script access allowed');
$q = (string) ($q ?? '');
$actionFilter = (string) ($actionFilter ?? '');
?>
<div class="page-head">
  <div>
    <p class="eyebrow">Administration</p>
    <h2>Activity Logs</h2>
    <p>Administrator actions are recorded with the actor, target, time, IP address and result. Impersonation cannot be silent.</p>
  </div>
</div>
<form class="admin-filters" method="get" action="/admin/logs">
  <label>Search<input type="search" name="q" value="<?= e($q) ?>" placeholder="Admin, target or action"></label>
  <label>Action
    <select name="action">
      <option value="">All</option>
      <?php foreach (['ADMIN_LOGIN','ADMIN_LOGOUT','USER_CREATED','USER_EDITED','USER_SUSPENDED','USER_ACTIVATED','USER_DELETED','PASSWORD_RESET','IMPERSONATION_STARTED','IMPERSONATION_ENDED','ADMIN_CREATED','ADMIN_PERMISSIONS_CHANGED','SETTINGS_CHANGED'] as $act): ?>
        <option value="<?= e($act) ?>" <?= $actionFilter === $act ? 'selected' : '' ?>><?= e(str_replace('_', ' ', $act)) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <button class="btn" type="submit">Filter</button>
</form>
<section class="panel">
  <div class="body table-scroll">
    <?php if (empty($rows)): ?>
      <div class="empty-state"><p>No matching log entries.</p></div>
    <?php else: ?>
      <table class="tbl">
        <thead><tr><th>Admin</th><th>Action</th><th>Target</th><th>Date/Time</th><th>IP</th><th>Result</th></tr></thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td><?= e($row['admin_label'] ?? '') ?></td>
              <td><?= e(str_replace('_', ' ', (string) ($row['action'] ?? ''))) ?></td>
              <td><?= e($row['target_label'] ?? $row['target_id'] ?? '—') ?></td>
              <td class="dim"><?= admin_dt($row['created_at'] ?? null) ?></td>
              <td class="mono dim"><?= e($row['ip'] ?? '—') ?></td>
              <td><span class="badge <?= ($row['result'] ?? '') === 'ok' ? 'b-green' : 'b-red' ?>"><?= e($row['result'] ?? '') ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
  <?php if (($pages ?? 1) > 1): ?>
    <div class="admin-pager">
      <?php if ($page > 1): ?><a class="btn small" href="?<?= e(http_build_query(['q' => $q, 'action' => $actionFilter, 'page' => $page - 1])) ?>">Previous</a><?php endif; ?>
      <span class="dim">Page <?= (int) $page ?> of <?= (int) $pages ?></span>
      <?php if ($page < $pages): ?><a class="btn small" href="?<?= e(http_build_query(['q' => $q, 'action' => $actionFilter, 'page' => $page + 1])) ?>">Next</a><?php endif; ?>
    </div>
  <?php endif; ?>
</section>
