<?php defined('BASEPATH') or exit('No direct script access allowed');
$t = $tpl ?? [];
$isEdit = !empty($t['id']);
$isSystem = !empty($t['is_system']);
$vars = is_array($t['variables'] ?? null) ? $t['variables'] : [];
?>
<div class="page-head">
  <div>
    <p class="eyebrow"><a href="/admin/inbox/templates" style="color:var(--muted)">Email templates</a></p>
    <h2><?= $isEdit ? 'Edit template' : 'New email template' ?></h2>
    <p><?= e($t['description'] ?? 'Build an HTML email your team can reuse when replying or sending notifications.') ?></p>
  </div>
  <?php if ($isSystem): ?><span class="badge b-blue">System template — can be edited but not deleted</span><?php endif; ?>
</div>

<form method="post" action="<?= $isEdit ? '/admin/inbox/templates/' . (int)$t['id'] . '/save' : '/admin/inbox/templates/save' ?>" class="panel template-form">
  <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
  <div class="form-grid">
    <label>Template code
      <input name="code" required pattern="^[a-z0-9_]{3,60}$" value="<?= e($t['code'] ?? '') ?>" <?= $isSystem ? 'readonly' : '' ?> placeholder="e.g. onboarding_welcome">
      <small>3–60 lowercase letters, digits and underscores. Used to reference this template from code.</small>
    </label>
    <label>Name
      <input name="name" required maxlength="120" value="<?= e($t['name'] ?? '') ?>" placeholder="Friendly name shown in the picker">
    </label>
    <label>Category
      <select name="category">
        <?php foreach ($categories as $c): ?>
          <option value="<?= $c ?>" <?= ($t['category'] ?? 'general') === $c ? 'selected' : '' ?>><?= ucfirst($c) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Status
      <select name="is_active">
        <option value="1" <?= !empty($t['is_active']) ? 'selected' : '' ?>>Active</option>
        <option value="0" <?= empty($t['is_active']) ? 'selected' : '' ?>>Disabled</option>
      </select>
    </label>
    <label class="full">Description
      <input name="description" maxlength="255" value="<?= e($t['description'] ?? '') ?>" placeholder="One-line purpose of this template">
    </label>
    <label class="full">Subject <span class="dim">— supports {{variables}}</span>
      <input name="subject" required maxlength="200" value="<?= e($t['subject'] ?? '') ?>">
    </label>
    <label class="full">HTML body <span class="dim">— email-safe HTML, supports {{variables}}</span>
      <textarea name="body_html" required rows="16" class="code"><?= e($t['body_html'] ?? '') ?></textarea>
    </label>
    <label class="full">Plain-text fallback <span class="dim">(optional)</span>
      <textarea name="body_text" rows="8"><?= e($t['body_text'] ?? '') ?></textarea>
    </label>
  </div>
  <?php if ($vars): ?>
    <div style="padding:12px 16px;border-top:1px solid var(--line)">
      <strong style="font-size:13px">Detected variables in this template:</strong>
      <div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap">
        <?php foreach ($vars as $v): ?><span class="badge b-gray mono">{{<?= e($v) ?>}}</span><?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
  <div style="padding:14px 16px;border-top:1px solid var(--line);display:flex;gap:8px;justify-content:space-between;align-items:center">
    <a class="btn ghost" href="/admin/inbox/templates">Cancel</a>
    <button class="btn solid" type="submit"><?= $isEdit ? 'Save template' : 'Create template' ?></button>
  </div>
</form>
<style>
  .template-form { padding:0; }
  .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px 18px; padding:18px 20px }
  .form-grid label { font-size:13px; font-weight:600; color:var(--muted); display:block }
  .form-grid label.full { grid-column:1 / -1 }
  .form-grid input, .form-grid select, .form-grid textarea { width:100%; margin-top:4px; padding:9px 10px; border-radius:6px; border:1px solid var(--line); background:#fff; color:var(--text); font-weight:400 }
  .form-grid textarea.code { font-family:ui-monospace,Menlo,monospace; font-size:13px; resize:vertical }
  .form-grid small { color:var(--muted); font-weight:400; font-size:12px }
  .b-blue { background:#dbeafe; color:#1d4ed8 }
</style>
