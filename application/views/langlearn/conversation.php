<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<div class="page-head"><div><h2>AI Conversation</h2><p>Structured conversation drills: each turn expects a real target-language response. Free-form conversation AI arrives with the conversation provider (not configured) — never simulated.</p></div></div>
<?php if (!empty($notice)): ?><div class="notice ok"><?= e($notice) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="notice err"><?= e($error) ?></div><?php endif; ?>
<div class="panel">
  <h3>Scenarios</h3>
  <div class="body" style="padding-top:12px">
    <?php if (empty($scenarios)): ?>
      <p class="dim">No conversation scenarios available for this language yet.</p>
    <?php else: foreach ($scenarios as $s): ?>
      <form method="post" action="/app/languages/conv/<?= (int) $profileId ?>/start" class="inline" style="margin:6px 0">
        <input type="hidden" name="scenario" value="<?= e($s['code']) ?>">
        <label class="fld"><?= e($s['title']) ?> (<?= (int) $s['turns'] ?> turns)
          <select name="correction" class="sel">
            <option value="important">correct only important mistakes</option>
            <option value="immediate">correct me immediately</option>
            <option value="after">correct me after I finish</option>
            <option value="conversation_only">conversation only</option>
          </select>
        </label>
        <button class="btn small primary">start</button>
      </form>
    <?php endforeach; endif; ?>
  </div>
</div>
