<?php defined('BASEPATH') or exit('No direct script access allowed');
/** @var array $language @var array $goals @var array $explanationLanguages */
?>
<div class="page-head">
  <div>
    <h2>Start <?= e($language['name']) ?></h2>
    <p>Tell the AI teacher your goal, then take a real level assessment. Nothing is invented.</p>
  </div>
</div>
<?php if (!empty($notice)): ?><div class="notice ok"><?= e($notice) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="notice err"><?= e($error) ?></div><?php endif; ?>

<div class="panel">
  <h3><?= e($language['name']) ?> <span class="dim"><?= e($language['native_name'] ?? '') ?></span></h3>
  <div class="body" style="padding-top:12px">
    <p class="dim" style="font-size:13px">ISO <?= e($language['iso_code'] ?? $language['code']) ?> · <?= e($language['writing_system'] ?? '') ?> · <?= e(strtoupper($language['direction'] ?? 'ltr')) ?></p>
    <form method="post" action="/app/languages/start" style="display:grid;gap:12px;max-width:520px;margin-top:12px">
      <input type="hidden" name="code" value="<?= e($language['code']) ?>">
      <label class="fld">Learning goal
        <select class="sel" name="goal" required>
          <option value="">Choose a goal…</option>
          <?php foreach ($goals as $id => $label): ?>
            <option value="<?= e($label) ?>" <?= (($existing['goal'] ?? '') === $label) ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="fld">Explain things to me in
        <select class="sel" name="explanationLanguage">
          <?php foreach ($explanationLanguages as $el): ?>
            <option value="<?= e($el['code']) ?>" <?= (($existing['explanation_language'] ?? 'en') === $el['code'] || (($existing['explanation_language'] ?? '') === '' && $el['code'] === 'en')) ? 'selected' : '' ?>><?= e($el['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="fld">Minutes I can study each day
        <select class="sel" name="dailyMinutes">
          <?php foreach ([15, 20, 30, 45, 60] as $m): ?>
            <option value="<?= $m ?>" <?= ((int) ($existing['daily_minutes'] ?? 20) === $m) ? 'selected' : '' ?>><?= $m ?> minutes</option>
          <?php endforeach; ?>
        </select>
      </label>
      <div>
        <button class="btn primary">Save and continue</button>
        <a class="btn" href="/app/languages">Cancel</a>
      </div>
    </form>
    <p class="dim" style="font-size:12px;margin-top:14px">Next the teacher will test your current level. The starting level is computed from your answers — never assigned at random.</p>
  </div>
</div>
