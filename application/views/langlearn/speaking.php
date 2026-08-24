<?php defined('BASEPATH') or exit('No direct script access allowed');
/** @var array $speaking @var array $history @var int $profileId */
$lcodes = ['nl' => 'nl-NL', 'es' => 'es-ES', 'fr' => 'fr-FR', 'de' => 'de-DE', 'it' => 'it-IT', 'pt' => 'pt-PT', 'en' => 'en-GB', 'sw' => 'sw', 'yo' => 'yo-NG', 'ig' => 'ig-NG', 'ha' => 'ha-NG', 'af' => 'af-ZA', 'zu' => 'zu-ZA', 'ar' => 'ar', 'zh' => 'zh-CN', 'ja' => 'ja-JP', 'ko' => 'ko-KR', 'ru' => 'ru-RU', 'hi' => 'hi-IN', 'tr' => 'tr-TR'];
?>
<div class="page-head"><div><h2>Speaking practice</h2><p>Say the prompt aloud. Speech-to-text uses your browser's speech recognition (e.g. Chrome) — feature-detected, never faked. Scoring compares the REAL returned transcript with the prompt: word accuracy only. <b>Pronunciation/fluency scores require a pronunciation-assessment provider (not configured) and are never invented.</b></p></div></div>
<?php if (!empty($notice)): ?><div class="notice ok"><?= e($notice) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="notice err"><?= e($error) ?></div><?php endif; ?>
<div class="notice warnbox" id="stt-missing" style="display:none">Your browser does not expose speech recognition — speaking practice cannot capture your voice here. (Grading of a typed transcript is offered ONLY for manual review by a teacher; it is not speech scoring.)</div>

<?php if (empty($speaking['available'])): ?>
  <div class="panel"><div class="body"><p class="dim"><?= e($speaking['note'] ?? 'Not available yet.') ?></p></div></div>
<?php else: foreach ($speaking['prompts'] as $p): ?>
  <div class="panel" style="margin-bottom:12px">
    <h3><?= e($p['level']) ?> · say it aloud</h3>
    <div class="body" style="padding-top:12px">
      <p style="font-size:17px;font-weight:700"><?= e($p['text']) ?></p>
      <form method="post" action="/app/languages/s/<?= (int) $profileId ?>/attempt" class="stt-form">
        <input type="hidden" name="promptId" value="<?= e($p['id']) ?>">
        <input type="hidden" name="provider" value="browser_webspeech">
        <div class="inline" style="flex-wrap:wrap">
          <button class="btn small primary stt-btn" type="button" data-target="tr-<?= e($p['id']) ?>">🎤 speak now</button>
          <input class="sel stt-transcript" id="tr-<?= e($p['id']) ?>" name="transcript" type="text" placeholder="Transcript from your speech engine…" readonly style="min-width:240px" autocomplete="off">
          <button class="btn small">submit</button>
        </div>
        <p class="dim stt-status" style="font-size:10px;margin:6px 0 0"></p>
      </form>
    </div>
  </div>
<?php endforeach; endif; ?>

<?php if (!empty($history)): ?>
  <div class="panel">
    <h3>Speaking history (real transcripts)</h3>
    <div class="body scroll" style="padding-top:12px">
      <table class="tbl">
        <thead><tr><th>At</th><th>Prompt</th><th>Transcript</th><th class="num">Word accuracy</th></tr></thead>
        <tbody>
          <?php foreach ($history as $h): ?>
            <tr>
              <td class="dim"><?= e(substr((string) $h['created_at'], 5, 14)) ?></td>
              <td><?= e(mb_substr((string) $h['prompt_text'], 0, 40)) ?></td>
              <td class="dim"><?= e(mb_substr((string) ($h['transcript'] ?? '(no transcript)'), 0, 40)) ?></td>
              <td class="num"><?= $h['word_accuracy_pct'] !== null ? e((string) $h['word_accuracy_pct']) . '%' : '—' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<script>
(function () {
  var LANG = <?= json_encode($lcodes[$langCode ?? 'en'] ?? 'en') ?>;
  var SR = window.SpeechRecognition || window.webkitSpeechRecognition || null;
  document.addEventListener('DOMContentLoaded', function () {
    if (!SR) document.getElementById('stt-missing').style.display = 'block';
  });
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('.stt-btn');
    if (!btn || !SR) return;
    var input = document.getElementById(btn.getAttribute('data-target'));
    var status = btn.closest('.stt-form').querySelector('.stt-status');
    var rec = new SR();
    rec.lang = LANG;
    rec.interimResults = false;
    rec.maxAlternatives = 1;
    status.textContent = 'listening…';
    rec.onresult = function (event) {
      input.value = event.results[0][0].transcript;
      status.textContent = 'transcript captured — review and submit';
    };
    rec.onerror = function (event) {
      status.textContent = 'speech engine error: ' + event.error + ' — nothing was recorded';
    };
    rec.onend = function () { if (status.textContent === 'listening…') status.textContent = 'nothing captured'; };
    try { rec.start(); } catch (e) { status.textContent = 'could not start: ' + e.message; }
  });
})();
</script>
