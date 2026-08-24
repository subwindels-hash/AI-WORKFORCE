<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<div class="page-head"><div><h2>AI Lesson</h2><p>Explain → examples → practice → feedback. Completing the practice (≥75%) completes the module.</p></div></div>
<?php if (!empty($error)): ?><div class="notice err"><?= e($error) ?></div><?php endif; ?>
<?php if (!empty($lessonView)): $L = $lessonView['lesson']; ?>
  <div class="panel">
    <h3><?= e($L['title']) ?></h3>
    <div class="body" style="padding-top:12px">
      <p class="dim">Goal: <?= e($L['goal']) ?></p>
      <p><?= e($L['teach']) ?></p>
      <h4 style="margin-top:12px">Examples</h4>
      <?php foreach ($L['examples'] as $ex): ?>
        <div style="background:var(--panel2);border:1px solid var(--line);border-radius:8px;padding:10px;margin:6px 0">
          <div><?= e($ex['prompt']) ?></div>
          <div class="up" style="font-weight:700;margin-top:4px"><?= e($ex['correct']) ?></div>
          <div class="dim" style="font-size:11px"><?= e($ex['why']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="panel" style="margin-top:14px">
    <h3>Practice (pass ≥ <?= (int) $L['passMarkPct'] ?>%)</h3>
    <div class="body" style="padding-top:12px">
      <form method="post" action="/app/languages/m/<?= e($lessonView['module']['id']) ?>/lesson/answer" style="display:grid;gap:16px">
        <?php foreach ($L['practiceItems'] as $qi => $item): ?>
          <div>
            <p style="font-weight:600"><?= (int) $qi + 1 ?>. <?= e($item['prompt']) ?> <span class="dim">(<?= e($item['skill']) ?>)</span></p>
            <?php foreach ($item['options'] as $i => $opt): ?>
              <label style="display:flex;gap:8px;align-items:center;margin:4px 0;cursor:pointer">
                <input type="radio" name="answers[<?= e($item['id']) ?>]" value="<?= (int) $i ?>" required style="accent-color:#0ea5e9">
                <span><?= e($opt) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
        <div><button class="btn primary">Submit lesson</button></div>
      </form>
    </div>
  </div>
<?php endif; ?>
