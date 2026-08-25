<?php
defined('BASEPATH') or exit('No direct script access allowed');
$row = $row ?? null;
$csrf = (string) ($csrfToken ?? $this->session->userdata('csrf_token'));
$preService = $row['service'] ?? (string) ($this->input->get('service') ?? 'lead_discovery');
$action = $row ? '/admin/api/' . (int) $row['id'] . '/save' : '/admin/api/save';
?>
<div class="page-head">
  <div>
    <p class="eyebrow">API Management</p>
    <h2><?= $row ? 'Manage provider' : 'Add provider' ?></h2>
    <p>Only HTTPS endpoints are accepted. Existing secrets stay masked after save.</p>
  </div>
  <div class="page-actions"><a class="btn" href="/admin/api">Back</a></div>
</div>

<section class="panel">
  <h3>Configuration</h3>
  <div class="body">
    <form method="post" action="<?= e($action) ?>" class="admin-form" id="api-provider-form">
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
      <label>Service category
        <select name="service" id="api-service" <?= $row ? '' : '' ?>>
          <?php foreach ($services as $code => $meta): ?>
            <option value="<?= e($code) ?>" <?= $preService === $code ? 'selected' : '' ?>><?= e($meta['group'] . ' — ' . $meta['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Provider
        <select name="driver" id="api-driver"></select>
      </label>
      <label>Display name
        <input name="label" maxlength="190" value="<?= e((string) ($row['label'] ?? '')) ?>" placeholder="Optional label">
      </label>
      <label>Environment
        <select name="environment">
          <option value="live" <?= ($row['environment'] ?? 'live') === 'live' ? 'selected' : '' ?>>Live</option>
          <option value="sandbox" <?= ($row['environment'] ?? '') === 'sandbox' ? 'selected' : '' ?>>Sandbox</option>
        </select>
      </label>
      <label>Role
        <select name="role">
          <option value="primary" <?= ($row['role'] ?? '') === 'primary' ? 'selected' : '' ?>>Primary</option>
          <option value="fallback" <?= ($row['role'] ?? '') === 'fallback' ? 'selected' : '' ?>>Fallback</option>
          <option value="unused" <?= ($row['role'] ?? 'unused') === 'unused' ? 'selected' : '' ?>>Unused</option>
        </select>
      </label>
      <label class="auth-check"><input type="checkbox" name="enabled" value="1" <?= !empty($row['enabled']) ? 'checked' : '' ?>> Enabled</label>
      <div id="api-fields" class="admin-form"></div>
      <?php if (!empty($canManage)): ?>
        <div class="page-actions">
          <button class="btn primary" type="submit">Save Provider</button>
        </div>
      <?php endif; ?>
    </form>

    <?php if ($row): ?>
      <div class="page-actions" style="margin-top:16px">
        <?php if (!empty($canTest)): ?>
          <form method="post" action="/admin/api/<?= (int) $row['id'] ?>/test">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <button class="btn" type="submit">Test Connection</button>
          </form>
        <?php endif; ?>
        <?php if (!empty($canManage)): ?>
          <form method="post" action="/admin/api/<?= (int) $row['id'] ?>/<?= !empty($row['enabled']) ? 'disable' : 'enable' ?>">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <button class="btn" type="submit"><?= !empty($row['enabled']) ? 'Disable' : 'Enable' ?></button>
          </form>
          <form method="post" action="/admin/api/<?= (int) $row['id'] ?>/primary">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <button class="btn" type="submit">Make primary</button>
          </form>
          <form method="post" action="/admin/api/<?= (int) $row['id'] ?>/delete" onsubmit="return confirm('Delete this provider configuration?');">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <button class="btn danger" type="submit">Delete</button>
          </form>
        <?php endif; ?>
      </div>
      <?php if (!empty($row['last_test_at'])): ?>
        <p class="dim" style="margin-top:12px">Last test: <?= !empty($row['last_test_ok']) ? '✓ Connected' : '✕ Connection failed' ?>
          · <?= e(str_replace('T', ' ', substr((string) $row['last_test_at'], 0, 16))) ?>
          <?php if ($row['last_test_ms'] !== null): ?> · <?= (int) $row['last_test_ms'] ?>ms<?php endif; ?>
          <?php if (!empty($row['last_test_message'])): ?> · <?= e($row['last_test_message']) ?><?php endif; ?>
        </p>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>

<script>
(function () {
  var SERVICES = <?= json_encode($services) ?>;
  var DRIVERS = <?= json_encode($drivers) ?>;
  var CURRENT = <?= json_encode($row ?: new stdClass()) ?>;
  var CAN_SECRETS = <?= !empty($canSecrets) ? 'true' : 'false' ?>;
  var serviceSel = document.getElementById('api-service');
  var driverSel = document.getElementById('api-driver');
  var fields = document.getElementById('api-fields');

  function fillDrivers() {
    var svc = SERVICES[serviceSel.value] || { drivers: [] };
    var keep = CURRENT && CURRENT.driver;
    driverSel.innerHTML = '';
    svc.drivers.forEach(function (code) {
      var o = document.createElement('option');
      o.value = code;
      o.textContent = (DRIVERS[code] && DRIVERS[code].label) || code;
      if (keep === code) o.selected = true;
      driverSel.appendChild(o);
    });
    fillFields();
  }

  function fillFields() {
    var spec = DRIVERS[driverSel.value] || { fields: [] };
    fields.innerHTML = '';
    spec.fields.forEach(function (f) {
      var wrap = document.createElement('label');
      wrap.textContent = f.label + (f.required ? ' *' : '');
      var input = document.createElement('input');
      input.name = f.name;
      input.autocomplete = 'off';
      if (f.secret) {
        input.type = 'password';
        input.placeholder = (CURRENT.masked && CURRENT.masked[f.name]) ? CURRENT.masked[f.name] : '••••••••••••';
        if (!CAN_SECRETS) { input.disabled = true; input.placeholder = 'Hidden'; }
      } else {
        input.type = 'text';
        if (f.name === 'base_url') input.value = CURRENT.base_url || '';
        else if (f.name === 'account_id') input.value = CURRENT.account_id || '';
        else if (CURRENT.extra && CURRENT.extra[f.name]) input.value = CURRENT.extra[f.name];
      }
      if (f.hint) {
        var hint = document.createElement('span');
        hint.className = 'auth-hint';
        hint.textContent = f.hint;
        wrap.appendChild(input);
        wrap.appendChild(hint);
      } else wrap.appendChild(input);
      fields.appendChild(wrap);
    });
    if (!spec.fields.length) {
      var p = document.createElement('p');
      p.className = 'dim';
      p.textContent = 'This provider does not need server credentials.';
      fields.appendChild(p);
    }
  }

  serviceSel.addEventListener('change', fillDrivers);
  driverSel.addEventListener('change', fillFields);
  fillDrivers();
})();
</script>
