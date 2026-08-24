<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<div class="page-head"><div><h2>Conversation — <?= e($view['scenario'] ?? '') ?></h2><p>Correction mode: <?= e(str_replace('_', ' ', $view['correctionMode'] ?? '')) ?></p></div></div>
<?php if (!empty($error)): ?><div class="notice err"><?= e($error) ?></div><?php endif; ?>
<?php if (!empty($view['aiOpens'])): ?><div class="panel"><div class="body"><b>AI:</b> <?= e($view['aiOpens']) ?></div></div><?php endif; ?>
<?php foreach (($view['history'] ?? []) as $h): ?>
  <div class="notice <?= $h['ok'] ? 'ok' : 'warnbox' ?>" style="margin:6px 0"><b>You:</b> <?= e($h['text']) ?> <?= $h['ok'] ? '✓' : '✗' ?></div>
<?php endforeach; ?>
<?php if (($view['status'] ?? '') === 'ACTIVE' && !empty($view['turn'])): ?>
  <?php if (!empty($view['lastFeedback']) && $view['lastFeedback'] !== null): $f = $view['lastFeedback']; ?>
    <div class="notice <?= $f['ok'] ? 'ok' : 'warnbox' ?>">
      <?php if ($f['ok']): ?>Good! <?php else: ?>Not quite — expected <?= e($f['expected'] ?? 'a different phrasing') ?>.<?php endif; ?>
      <?php if (!empty($f['example'])): ?><div class="dim">Try something like: <b><?= e($f['example']) ?></b></div><?php endif; ?>
    </div>
  <?php endif; ?>
  <div class="panel">
    <div class="body" style="padding-top:12px">
      <p style="font-weight:600">Turn <?= (int) $view['turn']['index'] ?>/<?= (int) $view['turn']['total'] ?>: <?= e($view['turn']['instruction']) ?></p>
      <form method="post" action="/app/languages/c/<?= e($view['sessionId']) ?>/say" class="inline">
        <input class="sel" type="text" name="text" required placeholder="Reply in the language…" autocomplete="off">
        <button class="btn primary">Say it</button>
      </form>
    </div>
  </div>
<?php elseif (($view['status'] ?? '') === 'COMPLETED'): ?>
  <?php $s = $view['summary']; ?>
  <div class="panel">
    <h3>Conversation complete — <?= (int) $s['scorePct'] ?>%</h3>
    <div class="body" style="padding-top:12px">
      <p><?= (int) $s['unassisted'] ?>/<?= (int) $s['turns'] ?> turns unassisted.</p>
      <?php foreach ($s['history'] as $h): ?>
        <div class="dim" style="margin:4px 0"><b>You:</b> <?= e($h['text']) ?> <?= $h['ok'] ? '✓' : '✗' ?></div>
      <?php endforeach; ?>
      <a class="btn" href="/app/languages/conv/<?= e($view['sessionId'] ? 0 : 0) ? '' : '' ?><?= '' ?>"></a>
      <a class="btn primary" href="/app/languages">Back to My Languages</a>
    </div>
  </div>
<?php endif; ?>
