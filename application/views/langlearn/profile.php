<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<div class="page-head">
  <div>
    <h2><?= e($language['name']) ?> <span class="dim"><?= e($language['native_name']) ?></span></h2>
    <p>Progress from real activity only. <?= e(strtoupper($language['direction'])) ?> · <?= e($language['writing_system']) ?> · explanations in <?= e(strtoupper($profile['explanation_language'])) ?>.</p>
  </div>
</div>
<?php if (!empty($notice)): ?><div class="notice ok"><?= e($notice) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="notice err"><?= e($error) ?></div><?php endif; ?>

<?php $ob = $progress['onboarding'] ?? ['next' => 'learn']; ?>
<?php if (($ob['next'] ?? 'learn') !== 'learn'): ?>
  <div class="notice warnbox" style="margin-bottom:14px">
    <?php if ($ob['next'] === 'set_goal'): ?>
      <b>Next:</b> tell the teacher your goal.
      <form method="post" action="/app/languages/p/<?= (int) $profile['id'] ?>/goal" class="inline" style="margin-top:8px;flex-wrap:wrap">
        <select class="sel" name="goal" required>
          <?php foreach (\AIWorkforce\LangLearn\TeacherCoach::goalOptions() as $label): ?>
            <option value="<?= e($label) ?>"><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="hidden" name="explanationLanguage" value="<?= e($profile['explanation_language'] ?? 'en') ?>">
        <input type="hidden" name="dailyMinutes" value="<?= (int) ($profile['daily_minutes'] ?? 20) ?>">
        <button class="btn primary small">Save goal</button>
      </form>
    <?php elseif ($ob['next'] === 'assess'): ?>
      <b>Next:</b> take the AI level assessment. Your starting level will be computed from your answers.
      <form method="post" action="/app/languages/p/<?= (int) $profile['id'] ?>/assessment/start" style="margin-top:8px"><button class="btn primary">Start assessment</button></form>
    <?php elseif ($ob['next'] === 'path'): ?>
      <b>Next:</b> generate a personalized learning path from your assessed level.
      <form method="post" action="/app/languages/p/<?= (int) $profile['id'] ?>/path/generate" style="margin-top:8px"><button class="btn primary">Generate learning path</button></form>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="grid cols-main">
  <div class="panel">
    <h3><?= e(strtoupper($language['name'])) ?> <span class="dim" style="font-weight:400">(from real activity only)</span></h3>
    <div class="body" style="padding-top:12px">
      <table class="tbl">
        <tr><td class="dim">Current level</td><td><span class="badge big b-sky"><?= e($progress['level']) ?></span> <span class="dim" style="font-size:10px"><?= e($progress['levelSource']) ?></span></td></tr>
        <tr><td class="dim">Progress to <?= e($progress['nextLevel'] ?? 'next level') ?></td><td><?= $progress['progressToNextLevelPct'] !== null ? e(rtrim(rtrim(number_format((float) $progress['progressToNextLevelPct'], 1), '0'), '.')) . '%' : '<span class="dim">no modules at that level yet</span>' ?></td></tr>
        <tr><td class="dim">Vocabulary</td><td><?= (int) ($progress['vocabularyWords'] ?? 0) ?> words in your list</td></tr>
        <?php foreach ($progress['skills'] as $skill => $s): ?>
          <tr>
            <td class="dim"><?= e(ucfirst($skill)) ?></td>
            <td>
              <?php if (!empty($s['level'])): ?><span class="badge b-green"><?= e($s['level']) ?></span><?php endif; ?>
              <?php if ($s['pct'] !== null): ?> <?= e(rtrim(rtrim(number_format((float) $s['pct'], 1), '0'), '.')) ?>% <span class="dim" style="font-size:10px"><?= (int) ($s['attempts'] ?? 0) ?> attempts</span>
              <?php elseif (empty($s['level'])): ?><span class="dim">— <?= e(str_replace('_', ' ', $s['source'])) ?></span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <tr><td class="dim">Path completion</td><td><?= $progress['pathCompletionPct'] !== null ? e(rtrim(rtrim(number_format($progress['pathCompletionPct'], 1), '0'), '.')) . '%' : '<span class="dim">no path yet</span>' ?></td></tr>
        <tr><td class="dim">Study streak</td><td class="num"><?= (int) $progress['studyStreakDays'] ?> days · <?= (int) $progress['activeDays'] ?> active days</td></tr>
        <tr><td class="dim">Goal</td><td><?php $g = trim((string) ($progress['goal'] ?? $profile['goal'] ?? '')); echo $g !== '' ? e($g) : '<span class="dim">not set</span>'; ?></td></tr>
      </table>
    </div>
  </div>

  <div class="panel">
    <h3>AI level assessment</h3>
    <div class="body" style="padding-top:12px">
      <?php if (!empty($language['features']['adaptive_assessment'])): ?>
        <p class="dim" style="font-size:11px">Adaptive: answer well and questions get harder, struggle and they ease off. Your level is computed from your actual answers — never random. Verified ceiling for this language: <b><?= e($language['features']['assessment_ceiling']) ?></b>.</p>
        <?php if (!empty($latest) && $latest['result']): $r = $latest['result']; ?>
          <table class="tbl" style="margin-top:8px">
            <tr><td class="dim">Last result</td><td><span class="badge b-green"><?= e($r['overallLevel']) ?></span> <span class="dim" style="font-size:10px"><?= e(substr((string) $latest['completed_at'], 0, 16)) ?></span></td></tr>
            <?php foreach ($r['perSkill'] as $skill => $s): ?>
              <tr><td class="dim"><?= e($skill) ?></td><td><?= e($s['level']) ?> <span class="dim">(<?= (int) $s['correct'] ?>/<?= (int) $s['total'] ?>)</span></td></tr>
            <?php endforeach; ?>
            <?php if ($r['strengths']): ?><tr><td class="dim">Strengths</td><td class="up"><?= e(implode(', ', $r['strengths'])) ?></td></tr><?php endif; ?>
            <?php if ($r['weaknesses']): ?><tr><td class="dim">Focus areas</td><td class="down"><?= e(implode(', ', $r['weaknesses'])) ?></td></tr><?php endif; ?>
          </table>
        <?php endif; ?>
        <form method="post" action="/app/languages/p/<?= (int) $profile['id'] ?>/assessment/start" style="margin-top:10px">
          <button class="btn primary"><?= empty($latest) ? 'Start assessment' : 'Re-assess' ?></button>
        </form>
      <?php else: ?>
        <p class="dim">This language is registered, but its assessment item bank is still being authored. No level can be verified yet — none will be invented.</p>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="panel" style="margin-top:14px">
  <h3>Learning path</h3>
  <div class="body" style="padding-top:12px">
    <?php if (empty($path['path'])): ?>
      <p class="dim">No path yet. <?= $profile['level'] === 'Beginner' ? 'Take the assessment for a calibrated start, or' : '' ?> generate your personalized CEFR path now.</p>
      <form method="post" action="/app/languages/p/<?= (int) $profile['id'] ?>/path/generate"><button class="btn primary">Generate learning path</button></form>
    <?php else: ?>
      <p class="dim" style="font-size:11px">From <?= e($path['path']['from_level']) ?> toward <?= e($path['path']['target_level']) ?> — modules unlock in order; each ends with a real checkpoint quiz drawn from the item bank.</p>
      <div style="display:flex;flex-wrap:wrap;gap:8px;margin:10px 0">
        <a class="btn small primary" href="/app/languages/d/<?= (int) $profile['id'] ?>">Today's plan</a>
        <a class="btn small" href="/app/languages/conv/<?= (int) $profile['id'] ?>">AI conversation</a>
        <a class="btn small" href="/app/languages/l/<?= (int) $profile['id'] ?>">Listening</a>
        <a class="btn small" href="/app/languages/s/<?= (int) $profile['id'] ?>">Speaking</a>
        <a class="btn small" href="/app/languages/v/<?= (int) $profile['id'] ?>">Vocabulary</a>
        <a class="btn small" href="/app/languages/w/<?= (int) $profile['id'] ?>">Writing practice</a>
        <a class="btn small" href="/app/languages/g/<?= (int) $profile['id'] ?>">Grammar</a>
        <a class="btn small" href="/app/languages/h/<?= (int) $profile['id'] ?>">History</a>
      </div>
      <table class="tbl" style="margin-top:8px">
        <thead><tr><th>#</th><th>Module</th><th>Focus</th><th>Status</th><th class="num"></th></tr></thead>
        <tbody>
          <?php foreach ($path['modules'] as $m): ?>
            <tr>
              <td class="dim"><?= (int) $m['sequence'] ?></td>
              <td style="font-weight:600"><?= e($m['title']) ?></td>
              <td class="dim"><?= e($m['focus_skill']) ?></td>
              <td><span class="badge <?= ['COMPLETED' => 'b-green', 'IN_PROGRESS' => 'b-amber', 'AVAILABLE' => 'b-sky', 'LOCKED' => 'b-gray'][$m['status']] ?>"><?= e($m['status']) ?></span></td>
              <td class="num">
                <?php if (!in_array($m['status'], ['LOCKED', 'COMPLETED'], true)): ?>
                  <a class="btn small primary" href="/app/languages/m/<?= e($m['id']) ?>/lesson">lesson</a>
                  <a class="btn small" href="/app/languages/m/<?= e($m['id']) ?>/checkpoint">checkpoint</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
