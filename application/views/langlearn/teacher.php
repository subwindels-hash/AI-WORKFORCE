<?php defined('BASEPATH') or exit('No direct script access allowed');
/** @var array $languages @var array $locales @var string $csrfToken @var array $examplePairs */
$langOptions = [];
foreach ($languages as $l) {
    $langOptions[$l['code']] = $l['name'] . ' — ' . ($l['native_name'] ?? '');
}
?>
<style>
#ai-teacher { max-width: 880px; }
.tt-layout { display: grid; grid-template-columns: 1fr; gap: 14px; }
.tt-result { background: linear-gradient(145deg, #0b1c34, #0b1220); border: 1px solid #2a3f63; border-radius: 14px; padding: 18px; }
.tt-translation { font-size: clamp(20px, 3.4vw, 30px); font-weight: 800; color: #fff; line-height: 1.3; margin: 6px 0 4px; word-break: break-word; }
.tt-translation.rtl { direction: rtl; text-align: right; }
.tt-original { font-size: 14px; color: var(--muted); margin-top: 10px; word-break: break-word; }
.tt-meta { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; }
.tt-actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-top: 16px; }
.tt-voices { display: flex; flex-wrap: wrap; gap: 14px; align-items: center; margin-top: 12px; font-size: 12px; color: var(--muted); }
.tt-voices select, .tt-voices input[type=range] { background: var(--panel2); color: var(--text); border: 1px solid var(--line2); border-radius: 6px; }
.tt-voices select { padding: 4px 6px; }
.tt-rate { display: flex; align-items: center; gap: 6px; }
.tt-practice { background: var(--panel); border: 1px solid var(--line); border-radius: 12px; padding: 16px; }
.tt-fb { margin-top: 12px; padding: 12px; border-radius: 10px; border: 1px solid var(--line2); background: var(--panel2); font-size: 13px; }
.tt-fb.good { border-color: #34d39955; background: #34d39914; }
.tt-fb.warn { border-color: #fbbf2455; background: #fbbf2414; }
.tt-history { display: flex; flex-direction: column; gap: 6px; }
.tt-history .row { display: flex; justify-content: space-between; gap: 10px; padding: 7px 10px; border: 1px solid var(--line); border-radius: 8px; background: var(--panel2); font-size: 12px; cursor: pointer; }
.tt-history .row:hover { border-color: var(--sky); }
.tt-step { display: inline-flex; align-items: center; gap: 6px; color: var(--sky); font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .14em; }
.tt-step .n { width: 20px; height: 20px; border-radius: 50%; background: var(--sky); color: #06111e; display: inline-flex; align-items: center; justify-content: center; font-size: 11px; }
.tt-langbar { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
.tt-loading { display: inline-block; width: 14px; height: 14px; border: 2px solid var(--line2); border-top-color: var(--sky); border-radius: 50%; animation: ttspin .7s linear infinite; vertical-align: -2px; }
@keyframes ttspin { to { transform: rotate(360deg); } }
@media (max-width: 600px) { .tt-langbar { flex-direction: column; align-items: stretch; } }
</style>

<div class="page-head">
  <div>
    <h2>AI Language Teacher</h2>
    <p>Type a sentence, get an instant translation, listen to the correct pronunciation, then practice speaking — all without leaving the page.</p>
  </div>
  <div class="tt-step"><span class="n">1</span> Choose · <span class="n">2</span> Type · <span class="n">3</span> Translate · <span class="n">4</span> Listen · <span class="n">5</span> Speak</div>
</div>

<?php if (!empty($notice)): ?><div class="notice ok"><?= e($notice) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="notice err"><?= e($error) ?></div><?php endif; ?>
<div class="notice warnbox" id="tts-note" style="display:none"></div>

<div class="tt-layout">

  <!-- Step 1 + 2: choose language + type -->
  <section class="panel">
    <h3>What would you like to say?</h3>
    <div class="body" style="padding-top:14px">
      <form id="tt-form" class="inline" style="align-items:flex-end">
        <label class="fld" style="min-width:200px">
          I want to learn
          <select id="tt-target" class="sel" name="target">
            <?php foreach ($langOptions as $code => $label): ?>
              <option value="<?= e($code) ?>" <?= $code === 'fr' ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="fld" style="flex:1;min-width:240px">
          Say it in your own words
          <input id="tt-input" class="sel" name="text" type="text" maxlength="500" autocomplete="off" placeholder="e.g. Good morning, how are you?" style="min-width:240px;flex:1">
        </label>
        <button class="btn primary" type="submit" id="tt-submit">Translate</button>
      </form>
      <div style="margin-top:12px;font-size:12px;color:var(--dim)">
        Try:
        <?php foreach ($examplePairs as $i => $ex): ?>
          <a class="tt-example" href="#" data-text="<?= e($ex['text']) ?>" data-target="<?= e($ex['target']) ?>" style="color:var(--sky)"><?= e($ex['text']) ?></a><?= $i < count($examplePairs) - 1 ? ' · ' : '' ?>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- Step 3: translation result -->
  <div id="tt-empty" class="panel"><div class="body"><p class="dim">Your translation will appear here. The source language is detected automatically — type in any language.</p></div></div>

  <div id="tt-result-card" class="tt-result" hidden>
    <div class="tt-meta">
      <span class="badge b-sky" id="tt-source-badge">Detected: —</span>
      <span class="badge b-violet" id="tt-target-badge">Target: —</span>
      <span class="badge b-gray" id="tt-method-badge" hidden></span>
    </div>
    <div class="tt-translation" id="tt-translation" lang="" dir="ltr"></div>
    <div class="tt-original" id="tt-original"></div>
    <div id="tt-note" class="dim" style="margin-top:10px;font-size:12px"></div>

    <!-- Step 4: listen -->
    <div class="tt-actions" id="tt-listen-row">
      <button class="btn primary" type="button" id="tt-play">🔊 Listen</button>
      <button class="btn" type="button" id="tt-stop" disabled>⏹ Stop</button>
      <button class="btn" type="button" id="tt-replay">↻ Replay</button>
    </div>
    <div class="tt-voices">
      <label style="display:flex;align-items:center;gap:6px">Voice
        <select id="tt-voice" disabled></select>
      </label>
      <label class="tt-rate">Speed
        <input id="tt-rate" type="range" min="0.5" max="1.5" step="0.1" value="1">
        <span id="tt-rate-val" style="width:28px">1.0×</span>
      </label>
    </div>

    <!-- Step 5 + 6: practice speaking + feedback -->
    <div class="tt-practice" style="margin-top:16px">
      <div class="tt-step"><span class="n">5</span> Practice speaking</div>
      <p class="dim" style="font-size:12px;margin:6px 0 10px">Say the translation aloud. Speech-to-text uses your browser engine (e.g. Chrome) when available. We compare the REAL transcript to the target — word accuracy only. Pronunciation scoring is not available and is never invented.</p>
      <div class="notice warnbox" id="stt-note" style="display:none"></div>
      <div class="inline" style="flex-wrap:wrap;align-items:center">
        <button class="btn primary" type="button" id="tt-mic" disabled>🎤 Speak now</button>
        <input id="tt-transcript" class="sel" type="text" readonly placeholder="Transcript from your speech engine…" style="min-width:240px;flex:1">
        <button class="btn" type="button" id="tt-check" disabled>Check</button>
        <button class="btn" type="button" id="tt-retry" hidden>Try again</button>
      </div>
      <p class="dim" id="tt-mic-status" style="font-size:11px;margin:6px 0 0"></p>
      <div id="tt-feedback" class="tt-fb" hidden></div>
    </div>
  </div>

  <!-- Session history (kept on this page only) -->
  <section class="panel" id="tt-history-panel" hidden>
    <h3>This session</h3>
    <div class="body scroll" style="padding-top:12px">
      <div class="tt-history" id="tt-history"></div>
      <p class="dim" style="font-size:11px;margin-top:10px">Tap a row to reload it. History stays on this page while you practice.</p>
    </div>
  </section>

  <section class="panel">
    <h3>Continue learning</h3>
    <div class="body" style="padding-top:12px">
      <p class="dim" style="font-size:12px">The AI Teacher is your instant translator. For structured study, your existing modules remain available:</p>
      <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px">
        <a class="btn" href="/app/languages">My languages &amp; catalog</a>
        <a class="btn" href="/app/languages/l/1">Listening practice</a>
        <a class="btn" href="/app/languages/s/1">Speaking practice</a>
        <a class="btn" href="/app/languages/v/1">Vocabulary (SRS)</a>
      </div>
    </div>
  </section>

</div>

<script>
(function () {
  'use strict';
  var CSRF = <?= json_encode((string) ($csrfToken ?? '')) ?>;
  var LOCALES = <?= json_encode($locales ?? []) ?>;
  var ENDPOINT = '/api/v1/language-learning/translate';

  var form = document.getElementById('tt-form');
  var input = document.getElementById('tt-input');
  var targetSel = document.getElementById('tt-target');
  var submitBtn = document.getElementById('tt-submit');
  var emptyCard = document.getElementById('tt-empty');
  var resultCard = document.getElementById('tt-result-card');

  // translation state
  var current = null; // {source, sourceName, target, targetName, targetLocale, translation, method, note, original}

  // ---- TTS plumbing ------------------------------------------------------
  var synth = ('speechSynthesis' in window);
  var voices = synth ? speechSynthesis.getVoices() : [];
  var ttsNote = document.getElementById('tts-note');
  var playBtn = document.getElementById('tt-play');
  var stopBtn = document.getElementById('tt-stop');
  var replayBtn = document.getElementById('tt-replay');
  var voiceSel = document.getElementById('tt-voice');
  var rateSel = document.getElementById('tt-rate');
  var rateVal = document.getElementById('tt-rate-val');

  function refreshVoices() { if (synth) voices = speechSynthesis.getVoices(); }
  if (synth && voices.length === 0) speechSynthesis.onvoiceschanged = refreshVoices;

  function voicesForLocale(locale) {
    var lang = (locale || 'en').toLowerCase();
    var base = lang.split('-')[0];
    return voices.filter(function (v) {
      var vl = (v.lang || '').toLowerCase();
      return vl === lang || vl.indexOf(base) === 0;
    });
  }

  function populateVoices(locale) {
    voiceSel.innerHTML = '';
    var matches = voicesForLocale(locale);
    if (!matches.length) {
      voiceSel.disabled = true;
      voiceSel.innerHTML = '<option>No voice for this language</option>';
      return false;
    }
    voiceSel.disabled = false;
    matches.forEach(function (v, i) { var o = document.createElement('option'); o.value = i; o.textContent = v.name + ' (' + v.lang + ')'; voiceSel.appendChild(o); });
    voiceSel._matches = matches;
    return true;
  }

  function speak(text, locale) {
    if (!synth) return;
    if (!text) return;
    speechSynthesis.cancel();
    var u = new SpeechSynthesisUtterance(text);
    u.lang = locale || 'en';
    var matches = voicesForLocale(locale);
    if (matches.length) {
      var chosen = voiceSel._matches && voiceSel._matches[parseInt(voiceSel.value, 10)] ? voiceSel._matches[parseInt(voiceSel.value, 10)] : matches[0];
      u.voice = chosen;
      u.lang = chosen.lang;
    }
    u.rate = parseFloat(rateSel.value) || 1;
    u.onend = function () { ttsIdle(); };
    u.onerror = function () { ttsIdle(); };
    playBtn.disabled = true; stopBtn.disabled = false;
    speechSynthesis.speak(u);
  }
  function ttsIdle() { playBtn.disabled = false; stopBtn.disabled = true; }
  if (synth) {
    playBtn.addEventListener('click', function () { if (current && current.translation) speak(current.translation, current.targetLocale); });
    replayBtn.addEventListener('click', function () { if (current && current.translation) speak(current.translation, current.targetLocale); });
    stopBtn.addEventListener('click', function () { speechSynthesis.cancel(); ttsIdle(); });
    rateSel.addEventListener('input', function () { rateVal.textContent = (parseFloat(rateSel.value) || 1).toFixed(1) + '×'; });
  } else {
    [playBtn, stopBtn, replayBtn].forEach(function (b) { b.disabled = true; });
  }

  // ---- STT plumbing ------------------------------------------------------
  var SR = window.SpeechRecognition || window.webkitSpeechRecognition || null;
  var sttNote = document.getElementById('stt-note');
  var micBtn = document.getElementById('tt-mic');
  var transcriptInput = document.getElementById('tt-transcript');
  var checkBtn = document.getElementById('tt-check');
  var retryBtn = document.getElementById('tt-retry');
  var micStatus = document.getElementById('tt-mic-status');
  var feedback = document.getElementById('tt-feedback');

  function sttReady() { return !!SR && current && current.translation; }
  function syncMicState() {
    var canMic = sttReady();
    micBtn.disabled = !canMic;
    checkBtn.disabled = !(canMic && transcriptInput.value.trim());
  }

  if (!SR) {
    sttNote.style.display = 'block';
    sttNote.textContent = 'Your browser does not expose speech recognition, so the microphone cannot capture your voice here. You can still Listen to the correct pronunciation and type the translation to self-check.';
  }

  micBtn.addEventListener('click', function () {
    if (!SR || !current || !current.translation) return;
    var rec = new SR();
    rec.lang = current.targetLocale || 'en';
    rec.interimResults = false;
    rec.maxAlternatives = 1;
    transcriptInput.value = '';
    micStatus.textContent = 'Listening… say the translation aloud.';
    rec.onresult = function (ev) { transcriptInput.value = ev.results[0][0].transcript; micStatus.textContent = 'Transcript captured — review and Check.'; syncMicState(); };
    rec.onerror = function (ev) { micStatus.textContent = 'Speech engine error: ' + ev.error + ' — nothing was recorded.'; };
    rec.onend = function () { if (micStatus.textContent === 'Listening… say the translation aloud.') micStatus.textContent = 'Nothing captured — try again.'; };
    try { rec.start(); } catch (e) { micStatus.textContent = 'Could not start microphone: ' + e.message; }
  });

  checkBtn.addEventListener('click', function () { grade(transcriptInput.value); });
  retryBtn.addEventListener('click', function () {
    transcriptInput.value = ''; feedback.hidden = true; retryBtn.hidden = true; micStatus.textContent = ''; syncMicState();
  });

  function grade(spoken) {
    if (!current || !current.translation) return;
    var expected = normalize(current.translation);
    var given = normalize(spoken || '');
    if (!given) { feedback.hidden = false; feedback.className = 'tt-fb warn'; feedback.textContent = 'Nothing to compare yet — speak or type first.'; return; }
    var expWords = expected.split(/\s+/).filter(Boolean);
    var gotWords = given.split(/\s+/).filter(Boolean);
    var matched = 0; var seen = expWords.slice();
    gotWords.forEach(function (w) { var i = seen.indexOf(w); if (i >= 0) { matched++; seen.splice(i, 1); } });
    var pct = expWords.length ? Math.round((matched / expWords.length) * 100) : 0;
    var exact = expected === given;
    feedback.hidden = false;
    feedback.className = 'tt-fb ' + (pct >= 80 ? 'good' : 'warn');
    feedback.innerHTML =
      '<b>Word accuracy: ' + pct + '%</b>' + (exact ? ' · exact match ✓' : ' · ' + matched + '/' + expWords.length + ' words matched') +
      '<div class="dim" style="margin-top:6px">Target: ' + escapeHtml(current.translation) + '</div>' +
      '<div class="dim">You said: ' + escapeHtml(spoken || '') + '</div>' +
      '<div class="dim" style="margin-top:6px;font-size:11px">Word accuracy compares the real speech-to-text transcript with the target. It is not a pronunciation or fluency score — those need a pronunciation-assessment provider that is not configured, and are never invented.</div>';
    retryBtn.hidden = false;
  }
  function normalize(s) { return (s || '').toLowerCase().replace(/[^\p{L}\p{N}\s]/gu, ' ').replace(/\s+/g, ' ').trim(); }
  function escapeHtml(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

  // ---- Translation flow --------------------------------------------------
  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var text = input.value.trim();
    if (!text) return;
    runTranslate(text, targetSel.value);
  });
  document.querySelectorAll('.tt-example').forEach(function (a) {
    a.addEventListener('click', function (ev) {
      ev.preventDefault();
      input.value = a.getAttribute('data-text');
      targetSel.value = a.getAttribute('data-target');
      runTranslate(a.getAttribute('data-text'), a.getAttribute('data-target'));
    });
  });

  function runTranslate(text, target) {
    submitBtn.disabled = true;
    var original = submitBtn.textContent;
    submitBtn.innerHTML = '<span class="tt-loading"></span> Translating…';
    fetch(ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify({ text: text, target: target })
    }).then(function (r) {
      return r.json().then(function (body) { if (!r.ok) throw new Error(body.error || 'Translation failed'); return body; });
    }).then(function (body) {
      render(body.translation, text, target);
    }).catch(function (err) {
      showError(err.message || 'The translator is unavailable.');
    }).finally(function () {
      submitBtn.disabled = false; submitBtn.textContent = original;
    });
  }

  function render(t, originalText, targetCode) {
    if (!t) { showError('The translator returned no result.'); return; }
    current = t; current.original = originalText;
    emptyCard.hidden = true;
    resultCard.hidden = false;

    var det = t.detected || {};
    var srcName = (det.name || (t.source ? t.source.toUpperCase() : 'auto'));
    document.getElementById('tt-source-badge').textContent = 'Detected: ' + srcName + (det.method ? ' · ' + det.method : '');
    document.getElementById('tt-target-badge').textContent = 'Target: ' + t.targetName;

    var methodBadge = document.getElementById('tt-method-badge');
    if (t.method && t.method !== 'none') { methodBadge.hidden = false; methodBadge.textContent = t.method; } else { methodBadge.hidden = true; }

    var trEl = document.getElementById('tt-translation');
    var translationText = t.translation || '';
    trEl.textContent = translationText || '(no fluent translation available)';
    var dir = (targetCode === 'ar' || targetCode === 'he') ? 'rtl' : 'ltr';
    trEl.dir = dir; trEl.lang = t.targetLocale || targetCode;
    trEl.classList.toggle('rtl', dir === 'rtl');

    document.getElementById('tt-original').innerHTML = '<b>You typed:</b> ' + escapeHtml(originalText) + ' &nbsp;·&nbsp; <b>Source:</b> ' + escapeHtml(srcName);
    document.getElementById('tt-note').textContent = t.note || '';

    // Listen setup
    if (synth) {
      var ok = populateVoices(t.targetLocale || targetCode);
      if (!ok) {
        ttsNote.style.display = 'block';
        ttsNote.textContent = 'No text-to-speech voice is installed for ' + t.targetName + ' in this browser, so playback is unavailable. The translation and grading still work — nothing is faked.';
        playBtn.disabled = true;
      } else {
        ttsNote.style.display = 'none';
        playBtn.disabled = false;
      }
      stopBtn.disabled = true;
    }

    // Practice setup
    transcriptInput.value = ''; feedback.hidden = true; retryBtn.hidden = true; micStatus.textContent = '';
    syncMicState();
    pushHistory(t, originalText);
    resultCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  function showError(msg) {
    ttsNote.style.display = 'block';
    ttsNote.className = 'notice warnbox';
    ttsNote.textContent = msg;
    ttsNote.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  // ---- Session history ---------------------------------------------------
  var historyPanel = document.getElementById('tt-history-panel');
  var historyBox = document.getElementById('tt-history');
  function pushHistory(t, originalText) {
    historyPanel.hidden = false;
    var row = document.createElement('div');
    row.className = 'row';
    row.innerHTML = '<span><b>' + escapeHtml(originalText) + '</b> <span class="dim">→ ' + escapeHtml(t.translation || '—') + '</span></span><span class="dim">' + escapeHtml(t.targetName) + '</span>';
    row.addEventListener('click', function () {
      input.value = originalText; targetSel.value = t.target;
      render(t, originalText, t.target);
    });
    historyBox.prepend(row);
  }

  input.focus();
})();
</script>
