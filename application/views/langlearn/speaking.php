<?php defined('BASEPATH') or exit('No direct script access allowed');
/** @var array $speaking @var array $history @var int $profileId */
$lcodes = ['nl' => 'nl-NL', 'es' => 'es-ES', 'fr' => 'fr-FR', 'de' => 'de-DE', 'it' => 'it-IT', 'pt' => 'pt-PT', 'en' => 'en-GB', 'sw' => 'sw-KE', 'yo' => 'yo-NG', 'ig' => 'ig-NG', 'ha' => 'ha-NG', 'af' => 'af-ZA', 'zu' => 'zu-ZA', 'ar' => 'ar-SA', 'zh' => 'zh-CN', 'ja' => 'ja-JP', 'ko' => 'ko-KR', 'ru' => 'ru-RU', 'hi' => 'hi-IN', 'tr' => 'tr-TR'];
$locale = $lcodes[$langCode ?? 'en'] ?? 'en-GB';
?>
<div class="page-head"><div><h2>Speaking practice</h2><p>Say the prompt aloud. The browser captures a real transcript in <?= e($locale) ?>. You get word accuracy only — pronunciation scores are never invented.</p></div></div>
<?php if (!empty($notice)): ?><div class="notice ok"><?= e($notice) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="notice err"><?= e($error) ?></div><?php endif; ?>
<div class="notice warnbox" id="stt-missing" style="display:none"></div>
<div class="notice warnbox" id="tts-note" style="display:none"></div>

<?php if (empty($speaking['available'])): ?>
  <div class="panel"><div class="body"><p class="dim"><?= e($speaking['note'] ?? 'Not available yet.') ?></p></div></div>
<?php else: foreach ($speaking['prompts'] as $p): ?>
  <div class="panel" style="margin-bottom:12px" data-prompt>
    <h3><?= e($p['level']) ?> · say it aloud <span class="badge b-sky"><?= e($locale) ?></span></h3>
    <div class="body" style="padding-top:12px">
      <p style="font-size:17px;font-weight:700"><?= e($p['text']) ?> <button class="btn small" type="button" data-listen="<?= e($p['text']) ?>">🔊 Listen</button></p>
      <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px">
        <button class="btn small tts-play" data-say="<?= e($p['text']) ?>" data-rate="1">▶ 1x</button>
        <button class="btn small tts-play" data-say="<?= e($p['text']) ?>" data-rate="0.75">▶ 0.75x</button>
        <button class="btn small tts-play" data-say="<?= e($p['text']) ?>" data-rate="1.25">▶ 1.25x</button>
        <button class="btn small" type="button" data-stop>⏹ Stop</button>
      </div>
      <div style="margin-top:8px;font-size:11px;color:var(--dim)">Voice: <select class="sel tts-voice" data-locale="<?= e($locale) ?>"><option>Loading…</option></select> | Rate: <input type="range" min="0.5" max="1.5" step="0.1" value="1" class="tts-rate" style="width:100px"> <span class="tts-rate-val">1.0×</span></div>

      <form method="post" action="/app/languages/s/<?= (int) $profileId ?>/attempt" class="stt-form" style="margin-top:12px">
        <input type="hidden" name="promptId" value="<?= e($p['id']) ?>">
        <input type="hidden" name="provider" value="browser_webspeech">
        <div class="inline" style="flex-wrap:wrap">
          <button class="btn small primary stt-btn" type="button" data-target="tr-<?= e($p['id']) ?>">🎤 Speak now (<?= e($locale) ?>)</button>
          <button class="btn small stt-stop" type="button" disabled>⏹ Stop</button>
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

<script src="/assets/js/speech-provider.js"></script>
<script>
(function () {
  var LOCALE = <?= json_encode($locale) ?>;
  var provider = window.windelsSpeech || (window.SpeechProvider ? new window.SpeechProvider() : null);

  function initVoices() {
    if (!provider) return;
    var health = provider.healthCheck();
    var ttsNote = document.getElementById('tts-note');
    var sttNote = document.getElementById('stt-missing');
    var voices = provider.getVoicesForLocale(LOCALE);

    if (ttsNote) {
      if (!health.tts) { ttsNote.style.display='block'; ttsNote.textContent='TTS not available in this browser.'; }
      else if (voices.length===0) { ttsNote.style.display='block'; ttsNote.textContent='No voice for '+LOCALE+' installed — playback unavailable.'; }
      else ttsNote.style.display='none';
    }
    if (sttNote) {
      if (!health.stt) { sttNote.style.display='block'; sttNote.textContent='Speech recognition not available in this browser — speaking practice cannot capture voice.'; }
      else sttNote.style.display='none';
    }

    document.querySelectorAll('.tts-voice').forEach(function(sel){
      var loc = sel.getAttribute('data-locale') || LOCALE;
      var vs = provider.getVoicesForLocale(loc);
      sel.innerHTML='';
      if (!vs.length) { sel.innerHTML='<option>No voice for '+loc+'</option>'; sel.disabled=true; return; }
      vs.forEach(function(v,i){ var o=document.createElement('option'); o.value=i; o.textContent=v.name+' ('+v.lang+')'; sel.appendChild(o); });
      sel._voices = vs;
      sel.disabled=false;
    });

    document.querySelectorAll('.tts-play').forEach(function(btn){
      btn.disabled = !health.tts || voices.length===0;
    });
    document.querySelectorAll('.stt-btn').forEach(function(btn){
      btn.disabled = !health.stt;
    });
  }

  document.addEventListener('DOMContentLoaded', function(){
    if (provider && provider.synth && provider.getSupportedVoices().length===0) {
      provider.synth.onvoiceschanged = initVoices;
      setTimeout(initVoices, 800);
    }
    initVoices();
  });

  // Guard against duplicate delegation listeners: the app-shell re-runs this
  // inline script on every SPA navigation, and without a guard repeated
  // visits stack several handlers so one click triggers several TTS/STT calls.
  if (window.__windels_tt_listeners_bound) {
    initVoices();
    return;
  }
  window.__windels_tt_listeners_bound = true;

  document.addEventListener('click', function(ev){
    var btn = ev.target.closest('.tts-play');
    if (!btn || btn.disabled || !provider) return;
    var text = btn.getAttribute('data-say');
    var rate = parseFloat(btn.getAttribute('data-rate')) || 1;
    var panel = btn.closest('[data-prompt]');
    var voiceSel = panel ? panel.querySelector('.tts-voice') : null;
    var voice = null;
    if (voiceSel && voiceSel._voices) {
      var idx = parseInt(voiceSel.value,10);
      voice = voiceSel._voices[idx] || null;
    }
    var rateInput = panel ? panel.querySelector('.tts-rate') : null;
    if (rateInput) rate = parseFloat(rateInput.value) || rate;
    provider.textToSpeech(text, { locale: LOCALE, voice: voice, rate: rate });
  });

  document.addEventListener('click', function(ev){
    var btn = ev.target.closest('[data-listen]');
    if (!btn || !provider) return;
    var text = btn.getAttribute('data-listen');
    var spoken = provider.speakableText ? provider.speakableText(text) : text;
    var panel = btn.closest('[data-prompt]');
    var voiceSel = panel ? panel.querySelector('.tts-voice') : null;
    var voice = null;
    if (voiceSel && voiceSel._voices) {
      var idx = parseInt(voiceSel.value,10);
      voice = voiceSel._voices[idx] || null;
    }
    provider.textToSpeech(spoken, { locale: LOCALE, voice: voice, rate: 1 });
  });

  document.addEventListener('click', function(ev){
    if (ev.target.hasAttribute('data-stop')) {
      if (provider) provider.stop();
    }
  });

  document.addEventListener('input', function(ev){
    if (ev.target.classList.contains('tts-rate')) {
      var v = parseFloat(ev.target.value) || 1;
      var label = ev.target.parentElement.querySelector('.tts-rate-val') || ev.target.closest('[data-prompt]').querySelector('.tts-rate-val');
      if (label) label.textContent = v.toFixed(1)+'×';
    }
  });

  // STT
  document.addEventListener('click', function(ev){
    var btn = ev.target.closest('.stt-btn');
    if (!btn || !provider) return;
    var input = document.getElementById(btn.getAttribute('data-target'));
    var status = btn.closest('.stt-form').querySelector('.stt-status');
    var stopBtn = btn.closest('.stt-form').querySelector('.stt-stop');
    if (!input || !status) return;
    if (provider.isRecording()) {
      provider.stopListening();
      btn.textContent = btn.textContent.replace('Listening…','Speak now');
      if (stopBtn) stopBtn.disabled = true;
      status.textContent = input.value.trim() ? 'Stopped. Review and submit.' : 'Stopped. Nothing captured — tap Speak to try again.';
      return;
    }
    input.value = '';
    btn.textContent = '🎤 Listening…';
    if (stopBtn) stopBtn.disabled = false;
    status.textContent = 'Listening… take your time. Silence does not end this. Tap Stop when you have finished talking.';
    provider.speechToText({
      locale: LOCALE,
      holdUntilStop: true,
      minListenMs: 30000,
      onStatus: function(msg){ status.textContent = msg; },
      onResult: function(transcript){
        input.value = transcript;
        status.textContent = 'Heard you — still listening for more. Tap Stop when you are finished.';
      },
      onError: function(e){
        var code = e.error || e.message || '';
        if (code === 'no-speech' || code === 'aborted') {
          status.textContent = 'Still listening — speak when you are ready, or tap Stop when you are finished.';
          return;
        }
        btn.textContent = '🎤 Speak now';
        if (stopBtn) stopBtn.disabled = true;
        status.textContent = 'Speech error: ' + code;
      },
      onEnd: function(info){
        btn.textContent = '🎤 Speak now';
        if (stopBtn) stopBtn.disabled = true;
        var got = String((info && info.transcript) || input.value || '').trim();
        status.textContent = got ? 'Stopped. Review and submit.' : 'Stopped. Nothing captured — tap Speak to try again.';
      }
    });
  });

  document.addEventListener('click', function(ev){
    var stopBtn = ev.target.closest('.stt-stop');
    if (!stopBtn || !provider) return;
    var form = stopBtn.closest('.stt-form');
    var micBtn = form.querySelector('.stt-btn');
    var input = form.querySelector('.stt-transcript');
    var status = form.querySelector('.stt-status');
    provider.stopListening();
    micBtn.textContent = '🎤 Speak now';
    stopBtn.disabled = true;
    status.textContent = input.value.trim() ? 'Stopped. Review and submit.' : 'Stopped.';
  });
})();
</script>
