<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<div class="page-head">
  <div>
    <h2>Languages — your AI teacher</h2>
    <p>Phase 1: choose languages, take the adaptive AI level assessment (levels come only from your real answers), get a personalized
      CEFR learning path and per-language progress. Lessons, conversation and vocabulary SRS arrive in Phases 2–3.</p>
  </div>
</div>
<?php if (!empty($notice)): ?><div class="notice ok"><?= e($notice) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="notice err"><?= e($error) ?></div><?php endif; ?>

<div class="panel" style="margin-bottom:14px">
  <h3>AI Language Teacher</h3>
  <div class="body" style="padding-top:12px">
    <p style="margin:0">Choose any two languages, type a sentence, get an instant translation, listen to the correct pronunciation, then practice speaking — all on one page.</p>
    <p style="margin-top:10px"><a class="btn primary" href="/app/languages/teacher">Open the AI Teacher →</a></p>
  </div>
</div>

<?php if (empty($user)): ?>
  <div class="panel">
    <h3>Sign in</h3>
    <div class="body" style="padding-top:12px">
      <p class="dim">Language learning is per-user — sign in to keep separate progress for every language you learn.</p>
      <form method="post" action="/app/languages/login" class="inline">
        <label class="fld">Email <input class="sel" type="email" name="email" required placeholder="you@example.com"></label>
        <label class="fld">Password <input class="sel" type="password" name="password" required></label>
        <button class="btn primary">Sign in</button>
      </form>
    </div>
  </div>
<?php else: ?>
  <div class="panel">
    <h3>My Languages</h3>
    <div class="body" style="padding-top:12px">
      <?php if (empty($myProfiles)): ?>
        <p class="dim">Not learning anything yet — pick a language from the catalog below.</p>
      <?php else: ?>
        <table class="tbl">
          <thead><tr><th>Language</th><th>Level</th><th>Path</th><th>Streak</th><th class="num"></th></tr></thead>
          <tbody>
            <?php foreach ($myProfiles as $p): $pr = $p['progress']; ?>
              <tr>
                <td style="font-weight:700"><?= e(($p['language']['name'] ?? $p['language_code'])) ?> <span class="dim"><?= e($p['language']['native_name'] ?? '') ?></span></td>
                <td><span class="badge b-sky"><?= e($pr['level']) ?></span> <span class="dim" style="font-size:10px"><?= e($pr['levelSource']) ?></span></td>
                <td class="num"><?= $pr['pathCompletionPct'] !== null ? e(rtrim(rtrim(number_format($pr['pathCompletionPct'], 1), '0'), '.')) . '%' : '—' ?></td>
                <td class="num"><?= (int) $pr['studyStreakDays'] ?>d</td>
                <td class="num"><a class="btn small primary" href="/app/languages/p/<?= (int) $p['id'] ?>">continue</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<div class="panel" style="margin-top:14px">
  <h3>Language catalog <span class="dim" style="font-weight:400">(registry-driven — expandable)</span></h3>
  <div class="body scroll" style="padding-top:12px">
    <table class="tbl">
      <thead><tr><th>Language</th><th>Native</th><th>ISO</th><th>Script</th><th>Dir</th><th>Assessment</th><th class="num"></th></tr></thead>
      <tbody>
        <?php foreach ($languages as $l): $feat = $l['features']; ?>
          <tr>
            <td style="font-weight:700"><?= e($l['name']) ?></td>
            <td><?= e($l['native_name']) ?></td>
            <td class="mono dim"><?= e($l['iso_code']) ?></td>
            <td class="dim"><?= e($l['writing_system']) ?></td>
            <td class="dim"><?= e(strtoupper($l['direction'])) ?></td>
            <td>
              <?php if (!empty($feat['adaptive_assessment'])): ?>
                <span class="badge b-green">adaptive · ceiling <?= e((string) ($feat['assessment_ceiling'] ?? 'A1')) ?></span>
              <?php else: ?>
                <span class="badge b-gray">registered — bank pending</span>
              <?php endif; ?>
            </td>
            <td class="num">
              <?php if (!empty($user)): ?>
                <form method="post" action="/app/languages/start" style="display:inline">
                  <input type="hidden" name="code" value="<?= e($l['code']) ?>">
                  <button class="btn small">learn</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="dim" style="font-size:10px;margin-top:8px">Assessment levels are only awarded up to each language's verified bank ceiling. Listening/speaking/writing are not assessed in this build and are never faked.</p>
  </div>
</div>
