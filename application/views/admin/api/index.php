<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<div class="page-head">
  <div>
    <p class="eyebrow">Administration</p>
    <h2>API Management</h2>
    <p>Central credentials for every module. Secrets stay on the server and are never shown in full.</p>
  </div>
  <div class="page-actions">
    <?php if (!empty($canManage)): ?>
      <a class="btn primary" href="/admin/api/create">+ Add Provider</a>
    <?php endif; ?>
  </div>
</div>

<section class="panel">
  <h3>Providers</h3>
  <div class="body scroll">
    <table class="tbl">
      <thead>
        <tr>
          <th>Service</th>
          <th>Provider</th>
          <th>Status</th>
          <th>Primary</th>
          <th>Last test</th>
          <th class="num">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($dashboard as $row): $p = $row['provider']; ?>
          <tr>
            <td>
              <b><?= e($row['label']) ?></b>
              <div class="dim" style="font-size:11px"><?= e($row['group']) ?><?= ($row['kind'] ?? '') === 'action' ? ' · execution (separate authorization)' : '' ?></div>
            </td>
            <td><?= $p ? e($p['label']) : '—' ?></td>
            <td>
              <?php
                $st = $row['status'];
                $badge = $st === 'Connected' ? 'b-green' : ($st === 'Connection failed' ? 'b-red' : ($st === 'Disabled' ? 'b-amber' : 'b-gray'));
              ?>
              <span class="badge <?= $badge ?>"><?= e($st) ?></span>
            </td>
            <td><?= $row['primary'] ? 'Yes' : 'No' ?></td>
            <td class="dim">
              <?php if ($p && !empty($p['last_test_at'])): ?>
                <?= e(str_replace('T', ' ', substr((string) $p['last_test_at'], 0, 16))) ?>
                <?php if ($p['last_test_ms'] !== null): ?> · <?= (int) $p['last_test_ms'] ?>ms<?php endif; ?>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td class="num">
              <?php if ($p): ?>
                <a class="btn small" href="/admin/api/<?= (int) $p['id'] ?>">Manage</a>
              <?php elseif (!empty($canManage)): ?>
                <a class="btn small" href="/admin/api/create?service=<?= e($row['service']) ?>">Configure</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php
  $configured = [];
  foreach ($dashboard as $row) {
      foreach ($row['providers'] ?? [] as $item) $configured[] = $item + ['service_label' => $row['label']];
  }
?>
<?php if ($configured): ?>
<section class="panel" style="margin-top:16px">
  <h3>Configured providers</h3>
  <div class="body scroll">
    <table class="tbl">
      <thead>
        <tr>
          <th>Service</th>
          <th>Provider</th>
          <th>Role</th>
          <th>Status</th>
          <th class="num">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($configured as $item): ?>
          <tr>
            <td><?= e($item['service_label']) ?></td>
            <td><?= e($item['label']) ?></td>
            <td><?= e($item['role']) ?></td>
            <td>
              <?php if (!empty($item['enabled']) && (int) ($item['last_test_ok'] ?? -1) === 1): ?>
                <span class="badge b-green">Connected</span>
              <?php elseif (!empty($item['enabled'])): ?>
                <span class="badge b-gray">Enabled</span>
              <?php else: ?>
                <span class="badge b-amber">Disabled</span>
              <?php endif; ?>
            </td>
            <td class="num"><a class="btn small" href="/admin/api/<?= (int) $item['id'] ?>">Manage</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<section class="panel" style="margin-top:16px">
  <h3>Health</h3>
  <div class="body">
    <p class="dim" style="font-size:13px;margin:0 0 12px">Technical provider status stays in this portal. Members never see API keys, missing environment variables or connection internals.</p>
    <div class="stat-grid">
      <?php
        $connected = count(array_filter($dashboard, fn($r) => $r['status'] === 'Connected'));
        $failed = count(array_filter($dashboard, fn($r) => $r['status'] === 'Connection failed'));
        $missing = count(array_filter($dashboard, fn($r) => $r['status'] === 'Not configured'));
      ?>
      <div class="stat"><div class="k">Connected</div><div class="v"><?= (int) $connected ?></div></div>
      <div class="stat"><div class="k">Not configured</div><div class="v"><?= (int) $missing ?></div></div>
      <div class="stat"><div class="k">Failed</div><div class="v"><?= (int) $failed ?></div></div>
    </div>
  </div>
</section>
