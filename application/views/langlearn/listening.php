<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<div class="page-head"><div><h2>Listening practice</h2><p>Audio is spoken by YOUR browser's speech synthesis — real playback when a voice for the language exists, an honest notice when it does not. Replay, slow/normal speed, transcript on demand.</p></div></div>
<?php if (!empty($notice)): ?><div class="notice ok"><?= e($notice) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="notice err"><?= e($error) ?></div><?php endif; ?>
<div class="notice warnbox" id="tts-support" style="display:none">Your browser has no speech-synthesis voice for this language — playback is unavailable (the exercises and grading still work; nothing is faked).</div>

<?php if (empty($listening['available'])): ?>
  <div class="panel"><div class="body"><p class="dim"><?= e($listening['note'] ?? 'Not available yet.') ?></p></div></div>
<?php else: foreach ($listening['exercises'] as $ex): ?>
  <div class="panel" style="margin-bottom:12px" data-exercise>
    <h3><?= e($ex['level']) ?> listening · <?= e(str_replace('-', '-', $ex['itemId'])) ?></h3>
    <div class="body" style="padding-top:12px">
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn small primary tts-play" data-say="<?= e($ex['speakText']) ?>" data-rate="1">▶ play (normal)</button>
        <button class="btn small tts-play" data-say="<?= e($ex['speakText']) ?>" data-rate="0.7">▶ play (slow)</button>
        <button class="btn small" type="button" onclick="const d=this.closest('.body').querySelector('.transcript');d.style.display=d.style.display==='none'?'block':'none'">show transcript</button>
      </div>
      <div class="transcript dim" style="display:none;margin-top:8px"><?= e($ex['transcript']) ?></div>

      <form method="post" action="/app/languages/l/<?= (int) $profileId ?>/attempt" style="margin-top:12px">
        <input type="hidden" name="itemId" value="<?= e($ex['itemId']) ?>">
        <input type="hidden" name="mode" value="comprehension">
        <p style="font-weight:600"><?= e($ex['comprehension']['question']) ?></p>
        <?php foreach ($ex['comprehension']['options'] as $i => $opt): ?>
          <label style="display:flex;gap:8px;align-items:center;margin:4px 0;cursor:pointer">
            <input type="radio" name="answer" value="<?= (int) $i ?>" required style="accent-color:#0ea5e9"><span><?= e($opt) ?></span>
          </label>
        <?php endforeach; ?>
        <button class="btn small primary" style="margin-top:6px">answer</button>
      </form>

      <form method="post" action="/app/languages/l/<?= (int) $profileId ?>/attempt" style="margin-top:10px">
        <input type="hidden" name="itemId" value="<?= e($ex['itemId']) ?>">
        <input type="hidden" name="mode" value="transcription">
        <div class="inline">
          <input class="sel" type="text" name="transcript" required placeholder="Write what you heard…" autocomplete="off">
          <button class="btn small">check transcription</button>
        </div>
      </form>
    </div>
  </div>
<?php endforeach; endif; ?>

<?php if (!empty($history)): ?>
  <div class="panel">
    <h3>Listening history</h3>
    <div class="body scroll" style="padding-top:12px">
      <table class="tbl">
        <thead><tr><th>At</th><th>Mode</th><th class="num">Score</th><th>Detail</th></tr></thead>
        <tbody>
          <?php foreach ($history as $h): ?>
            <tr>
              <td class="dim"><?= e(substr((string) $h['created_at'], 5, 14)) ?></td>
              <td><?= e($h['mode']) ?></td>
              <td class="num"><?= e((string) $h['score_pct']) ?>%</td>
              <td class="dim"><?= e(mb_substr(json_encode($h['detail']), 0, 80)) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<script>
(function () {
  var LANG = <?= json_encode($langCode ?? 'en') ?>;
  function pickVoice() {
    if (!('speechSynthesis' in window)) return null;
    var voices = speechSynthesis.getVoices();
    return voices.find(function (v) { return v.lang && v.lang.toLowerCase().indexOf(LANG) === 0; }) || null;
  }
  function supported() { return 'speechSynthesis' in window && pickVoice() !== null; }
  document.addEventListener('DOMContentLoaded', function () {
    if ('speechSynthesis' in window && speechSynthesis.getVoices().length === 0) {
      speechSynthesis.onvoiceschanged = function () { check(); };
    }
    check();
    function check() {
      var has = 'speechSynthesis' in window;
      var voice = pickVoice();
      document.querySelectorAll('.tts-play').forEach(function (btn) {
        btn.disabled = !has || !voice;
        btn.title = !has ? 'Speech synthesis unavailable in this browser'
          : (!voice ? 'No voice installed for this language' : '');
      });
      var note = document.getElementById('tts-support');
      if (note && has && !voice) note.style.display = 'block';
    }
  });
  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('.tts-play');
    if (!btn || btn.disabled) return;
    var voice = pickVoice();
    var utter = new SpeechSynthesisUtterance(btn.getAttribute('data-say'));
    utter.lang = voice ? voice.lang : LANG;
    if (voice) utter.voice = voice;
    utter.rate = parseFloat(btn.getAttribute('data-rate')) || 1;
    speechSynthesis.cancel();
    speechSynthesis.speak(utter);
  });
})();
</script>
