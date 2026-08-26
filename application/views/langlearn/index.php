<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<div class="page-head">
  <div>
    <h2>Language Learning</h2>
    <p>Your personal AI language teacher. Choose a language, set a goal, take a real assessment, then learn — every number comes from stored activity.</p>
  </div>
  <div class="page-actions">
    <a class="btn primary" href="/app/languages/teacher">Ask the AI teacher</a>
  </div>
</div>
<?php if (!empty($notice)): ?><div class="notice ok"><?= e($notice) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="notice err"><?= e($error) ?></div><?php endif; ?>

<?php if (!empty($user)): ?>
  <div class="panel" style="margin-bottom:16px">
    <h3>Ask the AI teacher</h3>
    <div class="body">
      <form method="post" action="/app/languages/teacher/ask" class="inline" style="flex-wrap:wrap;gap:8px;width:100%">
        <input class="sel" type="text" name="message" required maxlength="400" placeholder="Teach me Dutch from the beginning." style="flex:1;min-width:220px">
        <button class="btn primary">Ask</button>
      </form>
      <p class="dim" style="font-size:12px;margin-top:8px">Try: “I want to learn Spanish.” · “Practice Italian conversation with me.” · “Correct my German.” · “Test my French level.”</p>
    </div>
  </div>
<?php endif; ?>

<?php if (empty($user)): ?>
  <div class="panel">
    <h3>Sign in to keep progress</h3>
    <div class="body">
      <p class="dim">Language learning is per-user. Sign in to keep a separate path for every language you study.</p>
      <form method="post" action="/app/languages/login" class="inline" style="margin-top:12px">
        <label class="fld">Email <input class="sel" type="email" name="email" required placeholder="you@example.com"></label>
        <label class="fld">Password <input class="sel" type="password" name="password" required></label>
        <button class="btn primary">Sign in</button>
      </form>
    </div>
  </div>
<?php else: ?>
  <div class="panel">
    <h3>My languages</h3>
    <div class="body">
      <?php if (empty($myProfiles)): ?>
        <div class="empty-state">
          <p>You are not learning a language yet. Pick one from the catalog below.</p>
        </div>
      <?php else: ?>
        <table class="tbl">
          <thead><tr><th>Language</th><th>Level</th><th>Path</th><th>Streak</th><th class="num"></th></tr></thead>
          <tbody>
            <?php foreach ($myProfiles as $p): $pr = $p['progress']; ?>
              <tr>
                <td style="font-weight:700"><?= e(($p['language']['name'] ?? $p['language_code'])) ?> <span class="dim"><?= e($p['language']['native_name'] ?? '') ?></span></td>
                <td><span class="badge b-sky"><?= e($pr['level']) ?></span> <span class="dim" style="font-size:11px"><?= e($pr['levelSource']) ?></span></td>
                <td class="num"><?= $pr['pathCompletionPct'] !== null ? e(rtrim(rtrim(number_format($pr['pathCompletionPct'], 1), '0'), '.')) . '%' : '—' ?></td>
                <td class="num"><?= (int) $pr['studyStreakDays'] ?>d</td>
                <td class="num"><a class="btn small primary" href="/app/languages/p/<?= (int) $p['id'] ?>">Continue</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<div class="panel" style="margin-top:16px">
  <h3>Language catalog</h3>
  <div class="body">
    <p class="dim" style="font-size:13px;margin:0 0 12px">Search <?= (int) ($catalogTotal ?? count($languages)) ?> languages by English name, native name or ISO code. Full AI learning, translation and voice are labelled honestly — a language is never shown as spoken if no voice exists.</p>
    <label class="fld" style="max-width:420px;margin-bottom:14px">Search language…
      <input class="sel" id="catalog-search" type="search" placeholder="Dutch, Nederlands, nl…" autocomplete="off">
    </label>
    <div class="scroll">
      <table class="tbl" id="catalog-table">
        <thead><tr><th>Language</th><th>Native</th><th>ISO</th><th>Support</th><th class="num"></th></tr></thead>
        <tbody id="catalog-body">
          <?php $preview = $catalogPreview ?? $languages; foreach ($preview as $l): ?>
            <tr>
              <td style="font-weight:700"><?= e($l['name']) ?></td>
              <td><?= e($l['native_name'] ?? '') ?></td>
              <td class="mono dim"><?= e($l['iso_code'] ?? $l['code']) ?></td>
              <td><span class="badge <?= !empty($l['full_ai']) || !empty($l['features']['adaptive_assessment']) ? 'b-green' : 'b-gray' ?>"><?= e($l['support_label'] ?? (!empty($l['features']['adaptive_assessment']) ? 'Supported for full AI learning' : 'Text only')) ?></span></td>
              <td class="num">
                <?php if (!empty($user)): ?>
                  <a class="btn small" href="/app/languages/begin?code=<?= e($l['code']) ?>">Learn</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="dim" style="font-size:12px;margin-top:12px">Drop the official SIL ISO 639-3 table into <span class="mono">application/data/iso639-3.tab</span> to expand the searchable catalog to the full living-language set. Voice buttons stay hidden when a language has no pronunciation engine.</p>
  </div>
</div>
<script>
(function () {
  var input = document.getElementById('catalog-search');
  var body = document.getElementById('catalog-body');
  if (!input || !body) return;
  var timer = null;
  var signedIn = <?= !empty($user) ? 'true' : 'false' ?>;
  function row(lang) {
    var badge = lang.full_ai ? 'b-green' : 'b-gray';
    var label = lang.support_label || 'Text only';
    var learn = signedIn
      ? '<form method="post" action="/app/languages/start" style="display:inline"><input type="hidden" name="code" value="' + (lang.code || '') + '"><button class="btn small">Learn</button></form>'
      : '';
    return '<tr><td style="font-weight:700">' + escapeHtml(lang.name || '') + '</td><td>' + escapeHtml(lang.native_name || '') + '</td><td class="mono dim">' + escapeHtml(lang.iso_code || lang.code || '') + '</td><td><span class="badge ' + badge + '">' + escapeHtml(label) + '</span></td><td class="num">' + learn + '</td></tr>';
  }
  function escapeHtml(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  input.addEventListener('input', function () {
    clearTimeout(timer);
    timer = setTimeout(function () {
      fetch('/api/v1/language-learning/catalog?limit=30&q=' + encodeURIComponent(input.value), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (body) {
          body.languages = body.languages || [];
          body.inner = '';
          body.languages.forEach(function (l) { body.inner += row(l); });
          document.getElementById('catalog-body').innerHTML = body.inner || '<tr><td colspan="5" class="dim">No matching language.</td></tr>';
        })
        .catch(function () {});
    }, 180);
  });
})();
</script>
