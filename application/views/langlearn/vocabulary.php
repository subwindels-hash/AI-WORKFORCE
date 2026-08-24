<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<div class="page-head"><div><h2>Vocabulary</h2><p>Word bank with spaced repetition: remember → 1 → 3 → 7 → 14 → 30 → 90 days; forget → back to tomorrow. Progress counts come only from your real reviews.</p></div></div>
<?php if (!empty($notice)): ?><div class="notice ok"><?= e($notice) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="notice err"><?= e($error) ?></div><?php endif; ?>

<?php if (!empty($progress)): ?>
  <div class="grid cols-main">
    <div class="panel">
      <h3>My vocabulary</h3>
      <div class="body" style="padding-top:12px">
        <table class="tbl">
          <tr><td class="dim">In list / bank</td><td><?= (int) $progress['inList'] ?> / <?= (int) $progress['bankSize'] ?></td></tr>
          <tr><td class="dim">Learned (stage ≥ 4)</td><td class="up"><?= (int) $progress['learned'] ?></td></tr>
          <tr><td class="dim">Learning</td><td><?= (int) $progress['learning'] ?></td></tr>
          <tr><td class="dim">Not yet studied</td><td><?= (int) $progress['notYetStudied'] ?></td></tr>
          <tr><td class="dim">Due now</td><td><b><?= (int) $progress['dueNow'] ?></b></td></tr>
          <tr><td class="dim">Average familiarity</td><td><?= e((string) $progress['averageFamiliarity']) ?> / 1.0</td></tr>
          <tr><td class="dim">Mastery</td><td><?= e((string) $progress['masteryPct']) ?>%</td></tr>
        </table>
      </div>
    </div>
    <div class="panel">
      <h3>Daily review</h3>
      <div class="body" style="padding-top:12px">
        <p><b><?= (int) $dueCount ?></b> word(s) due now.</p>
        <a class="btn primary" href="/app/languages/vr/<?= (int) $profileId ?>/quiz">Quiz review</a>
        <a class="btn" href="/app/languages/vr/<?= (int) $profileId ?>/flashcard">Flashcards</a>
        <p class="dim" style="font-size:10px;margin-top:8px">Word audio uses your browser's own speech synthesis when a voice exists for the language — no fake audio otherwise.</p>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="panel" style="margin-top:14px">
  <h3>Word bank</h3>
  <div class="body" style="padding-top:12px">
    <form method="post" action="/app/languages/v/<?= (int) $profileId ?>/add" style="margin-bottom:10px">
      <input type="hidden" name="starter" value="1">
      <button class="btn small">add the starter pack (first 10 words)</button>
    </form>
    <form method="post" action="/app/languages/v/<?= (int) $profileId ?>/add">
      <table class="tbl">
        <thead><tr><th></th><th>Word</th><th>Translation</th><th>Pronunciation</th><th>Category</th><th>Level</th><th>Stage</th><th>Next review</th></tr></thead>
        <tbody>
          <?php foreach ($catalog as $w): ?>
            <tr>
              <td><input type="checkbox" name="vocabularyIds[]" value="<?= (int) $w['id'] ?>" <?= $w['inList'] ? 'disabled checked' : '' ?> style="accent-color:#0ea5e9"></td>
              <td style="font-weight:700"><?= e($w['word']) ?></td>
              <td><?= e($w['translation']) ?></td>
              <td class="dim"><?= e($w['pronunciation'] ?? '—') ?></td>
              <td class="dim"><?= e($w['category']) ?></td>
              <td class="dim"><?= e($w['level']) ?></td>
              <td><?= $w['stage'] !== null ? (int) $w['stage'] : '—' ?></td>
              <td class="dim"><?= $w['nextReviewAt'] ? e(substr((string) $w['nextReviewAt'], 0, 10)) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <button class="btn small primary" style="margin-top:8px">add selected words</button>
    </form>
  </div>
</div>
