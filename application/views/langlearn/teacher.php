<?php defined('BASEPATH') or exit('No direct script access allowed');
/** @var array $languages @var array $locales @var string $csrfToken @var array $examplePairs */
$langOptions = [];
foreach ($languages as $l) {
    $langOptions[$l['code']] = $l['name'] . ($l['native_name'] ? ' — ' . $l['native_name'] : '');
}
$localeMap = $locales ?? [];
?>
<style>
/* WINDELS AI WORKFORCE — AI Language Teacher enhanced
   Professional, responsive, balanced spacing, no overlap */
.tt-header { display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px }
.tt-target-banner { display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:12px;background:var(--panel2);border:1px solid var(--line2);font-size:13px }
.tt-target-banner b { color:#fff }
.tt-target-banner .badge { margin-left:6px }
.tt-modes { display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px }
.tt-mode { padding:6px 12px;border-radius:999px;border:1px solid var(--line2);background:var(--panel2);color:var(--muted);font-size:12px;font-weight:600;cursor:pointer;transition:border-color .15s,color .15s,background .15s }
.tt-mode:hover { border-color:var(--brand);color:#fff }
.tt-mode.active { background:var(--brand);border-color:var(--brand);color:#fff }
.tt-swapbar { display: grid; grid-template-columns: 1fr auto 1fr; gap: 10px; align-items: end; margin-bottom: 14px; }
.tt-side { display: grid; gap: 5px; min-width: 0; }
.tt-side > span { font-size: 10px; text-transform: uppercase; letter-spacing: .1em; color: var(--dim); font-weight: 700; }
.tt-side select { width: 100%; }
.tt-swap { width: 40px; height: 38px; display: grid; place-items: center; border: 1px solid var(--line2); border-radius: var(--radius-sm); background: var(--panel2); color: var(--muted); cursor: pointer; transition: color .15s, border-color .15s, transform .2s; }
.tt-swap:hover { color: #fff; border-color: var(--brand); }
.tt-swap:active { transform: rotate(180deg); }
.tt-swap svg { width: 17px; height: 17px; }
.tt-panes { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; align-items: start; }
.tt-pane { border: 1px solid var(--line); border-radius: var(--radius); background: var(--panel2); padding: 14px; min-width: 0; }
.tt-pane-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; min-height: 22px; margin-bottom: 8px; }
.tt-pane-label { font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); }
.tt-detect-hint { font-size: 11px; color: var(--sky); font-weight: 600; }
.tt-input { width: 100%; min-height: 96px; resize: vertical; background: #0b1119; color: var(--text); border: 1px solid var(--line2); border-radius: var(--radius-sm); padding: 10px 12px; font: inherit; font-size: 14px; line-height: 1.5; outline: none; }
.tt-input:focus { border-color: var(--brand); box-shadow: 0 0 0 3px var(--brand-soft); }
.tt-input.rtl { direction: rtl; text-align: right; }
.tt-input-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-top: 10px; }
.tt-count { font-size: 11px; color: var(--dim); }
.tt-translation { font-size: clamp(18px, 2.6vw, 24px); font-weight: 700; color: #fff; line-height: 1.35; margin: 4px 0 2px; word-break: break-word; }
.tt-translation.rtl { direction: rtl; text-align: right; }
.tt-original { font-size: 12.5px; color: var(--muted); margin-top: 8px; word-break: break-word; }
.tt-meta { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 6px; }
.tt-placeholder { color: var(--dim); font-size: 13px; padding: 22px 0; text-align: center; }
.tt-actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-top: 12px; }
.tt-voices { display: flex; flex-wrap: wrap; gap: 14px; align-items: center; margin-top: 10px; font-size: 12px; color: var(--muted); }
.tt-voices select, .tt-voices input[type=range] { background: var(--panel2); color: var(--text); border: 1px solid var(--line2); border-radius: 6px; }
.tt-voices select { padding: 4px 6px; }
.tt-rate { display: flex; align-items: center; gap: 6px; }
.tt-examples { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 14px; align-items: center; }
.tt-examples > span { font-size: 11px; color: var(--dim); font-weight: 700; text-transform: uppercase; letter-spacing: .08em; }
.tt-example { border: 1px solid var(--line2); background: var(--panel2); color: var(--muted); border-radius: 999px; padding: 4px 11px; font-size: 11.5px; cursor: pointer; }
.tt-example:hover { border-color: var(--brand); color: #fff; text-decoration: none; }
.tt-practice { border-top: 1px solid var(--line); margin-top: 14px; padding-top: 12px; }
.tt-practice-head { font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); margin-bottom: 6px; }
.tt-fb { margin-top: 12px; padding: 12px; border-radius: 10px; border: 1px solid var(--line2); background: var(--panel2); font-size: 13px; }
.tt-fb.good { border-color: #34d39955; background: #34d39914; }
.tt-fb.warn { border-color: #fbbf2455; background: #fbbf2414; }
.tt-history { display: flex; flex-direction: column; gap: 6px; }
.tt-history .row { display: flex; justify-content: space-between; gap: 10px; padding: 7px 10px; border: 1px solid var(--line); border-radius: 8px; background: var(--panel2); font-size: 12px; cursor: pointer; }
.tt-history .row:hover { border-color: var(--sky); }
.tt-loading { display: inline-block; width: 14px; height: 14px; border: 2px solid var(--line2); border-top-color: var(--sky); border-radius: 50%; animation: ttspin .7s linear infinite; vertical-align: -2px; }
@keyframes ttspin { to { transform: rotate(360deg); } }
.tt-lesson { border:1px solid var(--line);border-radius:var(--radius);background:var(--panel);padding:16px;margin-bottom:14px }
.tt-lesson h3 { margin:0 0 6px;font-size:13px }
.tt-lesson p { margin:0;color:var(--muted);font-size:12.5px }
.tt-explain { margin-top:10px;padding:10px 12px;border-radius:10px;background:var(--panel2);border:1px solid var(--line2);font-size:12.5px;color:var(--muted) }
.tt-explain b { color:var(--text) }
@media (max-width: 860px) { .tt-panes { grid-template-columns: 1fr; } }
@media (max-width: 600px) { .tt-swapbar { grid-template-columns: 1fr auto 1fr; gap: 6px; } .tt-side select { font-size: 12px; } }
</style>

<div class="page-head">
  <div>
    <h2>AI Language Teacher — WINDELS AI WORKFORCE</h2>
    <p>Automatic language detection, intelligent translation, real TTS voices with correct locales, and target-language protection — your target only changes when you explicitly select it.</p>
  </div>
</div>

<?php if (!empty($notice)): ?><div class="notice ok"><?= e($notice) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="notice err"><?= e($error) ?></div><?php endif; ?>
<div class="notice warnbox" id="tts-note" style="display:none"></div>

<!-- Target Language Banner + Easy Switching -->
<section class="panel">
  <h3>Target Language Management</h3>
  <div class="body" style="padding-top:14px">
    <div class="tt-header">
      <div class="tt-target-banner" id="target-banner">
        <span>TARGET LANGUAGE:</span> <b id="target-banner-name">Dutch</b> <span class="badge b-sky" id="target-banner-locale">nl-NL</span>
        <span class="dim" style="font-size:11px;margin-left:8px">Changes only on explicit selection</span>
      </div>
      <div style="display:flex;gap:6px;flex-wrap:wrap">
        <span class="dim" style="font-size:11px">Quick switch:</span>
        <button class="btn small" type="button" data-quick="nl">Dutch</button>
        <button class="btn small" type="button" data-quick="es">Spanish</button>
        <button class="btn small" type="button" data-quick="it">Italian</button>
        <button class="btn small" type="button" data-quick="ja">Japanese</button>
        <button class="btn small" type="button" data-quick="fr">French</button>
        <button class="btn small" type="button" data-quick="de">German</button>
      </div>
    </div>
    <p class="dim" style="font-size:11px;margin:0 0 10px">The flow: USER TYPES MESSAGE → DETECT INPUT LANGUAGE → COMPARE WITH TARGET LANGUAGE → SAME? Continue lesson. DIFFERENT? Translate/Explain/Correct → Continue learning. Target does NOT auto-change when you type another language for translation.</p>
  </div>
</section>

<!-- Learning Modes -->
<section class="panel" style="margin-top:14px">
  <h3>Learning Experience Modes</h3>
  <div class="body" style="padding-top:14px">
    <div class="tt-modes" id="mode-selector">
      <button class="tt-mode active" data-mode="conversation">Conversation Mode</button>
      <button class="tt-mode" data-mode="translation">Translation Mode</button>
      <button class="tt-mode" data-mode="learning">Learning Mode</button>
      <button class="tt-mode" data-mode="correction">Correction Mode</button>
      <button class="tt-mode" data-mode="vocabulary">Vocabulary Mode</button>
      <button class="tt-mode" data-mode="grammar">Grammar Mode</button>
    </div>
    <div id="mode-desc" class="tt-explain">Conversation Mode: Type naturally in your target language. AI responds in target, continues lesson. If you type another language, it translates to target + explains.</div>
  </div>
</section>

<!-- AI Teacher Lesson Example -->
<section class="tt-lesson" id="lesson-example">
  <h3>Today's Lesson: Basic Conversation — <span id="lesson-target">Dutch</span></h3>
  <p>AI Teacher: <b id="lesson-ai">Hallo! Hoe gaat het met je?</b> <button class="btn small" type="button" id="lesson-listen">🔊 Listen</button></p>
  <div class="tt-explain" id="lesson-explain" style="margin-top:10px">
    <b>Flow:</b> User types English "Hello, how are you?" → System detects English → Dutch translation: <b>Hallo! Hoe gaat het met je?</b> [🔊 Listen] → Explanation: "Hoe gaat het met je?" means "How are you?" — common way to ask. → Continue lesson in Dutch.
  </div>
</section>

<section class="panel">
  <h3>Translate &amp; Learn</h3>
  <div class="body" style="padding-top:14px">

    <!-- Two-sided language selection -->
    <div class="tt-swapbar">
      <label class="tt-side">
        <span>Left / source — you type (auto-detect)</span>
        <select id="tt-source" class="sel" aria-label="Source language">
          <option value="auto">Auto-detect</option>
          <?php foreach ($langOptions as $code => $label): ?>
            <option value="<?= e($code) ?>" <?= $code === 'en' ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="button" class="tt-swap" id="tt-swap" title="Swap languages" aria-label="Swap languages">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 4 3 8l4 4"/><path d="M3 8h13a4 4 0 0 1 4 4"/><path d="m17 20 4-4-4-4"/><path d="M21 16H8a4 4 0 0 1-4-4"/></svg>
      </button>
      <label class="tt-side">
        <span>Right / target — you learn (explicit change only)</span>
        <select id="tt-target" class="sel" aria-label="Target language">
          <?php foreach ($langOptions as $code => $label): ?>
            <option value="<?= e($code) ?>" <?= $code === 'nl' ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>

    <form id="tt-form">
      <div class="tt-panes">
        <div class="tt-pane">
          <div class="tt-pane-head">
            <span class="tt-pane-label" id="tt-source-label">Type in English</span>
            <span class="tt-detect-hint" id="tt-detect-hint"></span>
          </div>
          <textarea id="tt-input" class="tt-input" name="text" maxlength="500" autocomplete="off" spellcheck="false" placeholder="e.g. Hallo, hoe gaat het? or Hello, how are you?"></textarea>
          <div class="tt-input-row">
            <span class="tt-count" id="tt-count">0 / 500</span>
            <button class="btn primary" type="submit" id="tt-submit">Translate &amp; Learn</button>
          </div>
          <div class="tt-explain" style="margin-top:10px">
            <b>Auto-detection:</b> System detects your input language, compares with target (<span id="explain-target">Dutch</span>). Same? Continue lesson. Different? Translate + explain, keep target unchanged.
          </div>
        </div>

        <div class="tt-pane">
          <div class="tt-pane-head">
            <span class="tt-pane-label" id="tt-target-label">Translation — Dutch</span>
            <span class="badge b-gray" id="tt-method-badge" hidden></span>
          </div>
          <div id="tt-placeholder" class="tt-placeholder">Your translation will appear here with 🔊 Listen.</div>
          <div id="tt-result" hidden>
            <div class="tt-meta">
              <span class="badge b-sky" id="tt-source-badge">From: —</span>
              <span class="badge b-violet" id="tt-target-badge">To: —</span>
              <span class="badge b-gray" id="tt-detect-badge">Detected: —</span>
            </div>
            <div class="tt-translation" id="tt-translation" lang="" dir="ltr"></div>
            <div class="tt-original" id="tt-original"></div>
            <div id="tt-note" class="dim" style="margin-top:8px;font-size:12px"></div>

            <!-- Voice & Audio Pronunciation -->
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
              <span class="dim" style="font-size:11px">Locale: <b id="voice-locale">nl-NL</b> — correct language/locale where supported</span>
            </div>
            <div style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap">
              <button class="btn small" type="button" data-speed="0.75">0.75x</button>
              <button class="btn small primary" type="button" data-speed="1">1x</button>
              <button class="btn small" type="button" data-speed="1.25">1.25x</button>
            </div>

            <div class="tt-practice">
              <div class="tt-practice-head">Practice speaking — real STT</div>
              <p class="dim" style="font-size:12px;margin:0 0 10px">Say the translation aloud. Uses SpeechProvider.speechToText() with correct locale. Word accuracy only, never invented pronunciation scores.</p>
              <div class="notice warnbox" id="stt-note" style="display:none"></div>
              <div class="inline" style="flex-wrap:wrap;align-items:center">
                <button class="btn primary" type="button" id="tt-mic" disabled>🎤 Speak now</button>
                <input id="tt-transcript" class="sel" type="text" readonly placeholder="Transcript from your speech engine…" style="min-width:200px;flex:1">
                <button class="btn" type="button" id="tt-check" disabled>Check</button>
                <button class="btn" type="button" id="tt-retry" hidden>Try again</button>
              </div>
              <p class="dim" id="tt-mic-status" style="font-size:11px;margin:6px 0 0"></p>
              <div id="tt-feedback" class="tt-fb" hidden></div>
            </div>
          </div>
        </div>
      </div>
    </form>

    <div class="tt-examples">
      <span>Try</span>
      <?php foreach ($examplePairs as $ex): ?>
        <button type="button" class="tt-example"
                data-text="<?= e($ex['text']) ?>"
                data-src="<?= e($ex['source'] ?? 'auto') ?>"
                data-target="<?= e($ex['target']) ?>"><?= e($ex['text']) ?></button>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="panel" id="tt-history-panel" hidden>
  <h3>This session — translation history</h3>
  <div class="body scroll" style="padding-top:12px">
    <div class="tt-history" id="tt-history"></div>
    <p class="dim" style="font-size:11px;margin-top:10px">Tap a row to reload. Target language stays unchanged unless you explicitly select a new one.</p>
  </div>
</section>

<section class="panel">
  <h3>Continue learning — all modes</h3>
  <div class="body" style="padding-top:12px">
    <p class="dim" style="font-size:12px">The AI Teacher is your instant translator with voice. For structured study, your modules remain available. Target language only changes on explicit selection.</p>
    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:8px">
      <a class="btn" href="/app/languages">My languages &amp; catalog</a>
      <a class="btn" href="/app/languages/l/1">Listening practice</a>
      <a class="btn" href="/app/languages/s/1">Speaking practice</a>
      <a class="btn" href="/app/languages/v/1">Vocabulary (SRS)</a>
      <a class="btn" href="/app/languages/conv/1">Conversation</a>
    </div>
  </div>
</section>

<script src="/assets/js/speech-provider.js"></script>
<script>
(function () {
  'use strict';
  var CSRF = <?= json_encode((string) ($csrfToken ?? '')) ?>;
  var ENDPOINT = '/api/v1/language-learning/translate';
  var DETECT_ENDPOINT = '/api/v1/language-learning/detect';
  var LANG_NAMES = <?= json_encode(array_map(fn($l) => $l['name'], array_combine(array_column($languages, 'code'), $languages))) ?>;
  var LOCALES = <?= json_encode($localeMap) ?>;
  var RTL_LANGS = { ar: 1, he: 1, fa: 1, ur: 1 };
  var STORE_SRC = 'wl_lang_source';
  var STORE_TARGET = 'wl_lang_target';
  var STORE_MODE = 'wl_lang_mode';

  var form = document.getElementById('tt-form');
  var input = document.getElementById('tt-input');
  var sourceSel = document.getElementById('tt-source');
  var targetSel = document.getElementById('tt-target');
  var swapBtn = document.getElementById('tt-swap');
  var submitBtn = document.getElementById('tt-submit');
  var placeholderEl = document.getElementById('tt-placeholder');
  var resultEl = document.getElementById('tt-result');
  var sourceLabel = document.getElementById('tt-source-label');
  var targetLabel = document.getElementById('tt-target-label');
  var detectHint = document.getElementById('tt-detect-hint');
  var countEl = document.getElementById('tt-count');
  var targetBannerName = document.getElementById('target-banner-name');
  var targetBannerLocale = document.getElementById('target-banner-locale');
  var lessonTarget = document.getElementById('lesson-target');
  var lessonAi = document.getElementById('lesson-ai');
  var explainTarget = document.getElementById('explain-target');
  var voiceLocaleEl = document.getElementById('voice-locale');

  var current = null;
  var lastOriginal = '';
  var lastDetected = '';

  function langName(code) { return LANG_NAMES[code] || (code === 'auto' ? 'Auto-detect' : String(code).toUpperCase()); }
  function localeFor(code) { return LOCALES[code] || (code + '-' + code.toUpperCase()); }

  function remember() {
    try {
      sessionStorage.setItem(STORE_SRC, sourceSel.value);
      sessionStorage.setItem(STORE_TARGET, targetSel.value);
    } catch (e) {}
  }
  function recall() {
    try {
      var s = sessionStorage.getItem(STORE_SRC);
      var t = sessionStorage.getItem(STORE_TARGET);
      if (s !== null && sourceSel.querySelector('option[value=\"' + s + '\"]')) sourceSel.value = s;
      if (t !== null && targetSel.querySelector('option[value=\"' + t + '\"]')) targetSel.value = t;
    } catch (e) {}
  }

  function refreshChrome() {
    var src = sourceSel.value;
    var tgt = targetSel.value;
    sourceLabel.textContent = src === 'auto' ? 'Type in any language — auto-detected' : 'Type in ' + langName(src);
    targetLabel.textContent = 'Translation — ' + langName(tgt);
    targetBannerName.textContent = langName(tgt);
    var loc = localeFor(tgt);
    targetBannerLocale.textContent = loc;
    voiceLocaleEl.textContent = loc;
    lessonTarget.textContent = langName(tgt);
    explainTarget.textContent = langName(tgt);
    // Update lesson AI example based on target
    var examples = {
      nl: 'Hallo! Hoe gaat het met je?',
      es: '¡Hola! ¿Cómo estás?',
      it: 'Ciao! Come stai?',
      fr: 'Bonjour! Comment allez-vous?',
      de: 'Hallo! Wie geht es dir?',
      ja: 'こんにちは！お元気ですか？',
      en: 'Hello! How are you?'
    };
    lessonAi.textContent = examples[tgt] || examples['nl'];
    input.classList.toggle('rtl', src !== 'auto' && !!RTL_LANGS[src]);
    input.dir = src !== 'auto' && RTL_LANGS[src] ? 'rtl' : 'ltr';
    if (src !== 'auto') detectHint.textContent = '';
    remember();
    // Update speech provider voices for new target
    if (window.windelsSpeech) {
      populateVoices(loc);
    }
  }
  sourceSel.addEventListener('change', refreshChrome);
  targetSel.addEventListener('change', refreshChrome);

  // Quick switch buttons — explicit language change only
  document.querySelectorAll('[data-quick]').forEach(function(btn){
    btn.addEventListener('click', function(){
      var code = btn.getAttribute('data-quick');
      targetSel.value = code;
      refreshChrome();
      // Do NOT auto-translate previous input to new target unless user requests
      // This satisfies: target only changes when explicitly selected
    });
  });

  // Mode selector
  var modeDesc = document.getElementById('mode-desc');
  var modeMap = {
    conversation: 'Conversation Mode: Type naturally in your target language. AI responds in target, continues lesson. If you type another language, it translates to target + explains.',
    translation: 'Translation Mode: Type any sentence, get translation to target with Listen, explanation, and voice.',
    learning: 'Learning Mode: Structured lesson — teach → examples from verified bank → practice → grade → completion.',
    correction: 'Correction Mode: AI corrects your target-language input, shows corrected version with explanation.',
    vocabulary: 'Vocabulary Mode: 10-word authored bank per language, SRS schedule 1→3→7→14→30→90 days, quizzes deterministic.',
    grammar: 'Grammar Mode: Grammar rules with on-demand simpler explanations.'
  };
  document.querySelectorAll('.tt-mode').forEach(function(btn){
    btn.addEventListener('click', function(){
      document.querySelectorAll('.tt-mode').forEach(b=>b.classList.remove('active'));
      btn.classList.add('active');
      var mode = btn.getAttribute('data-mode');
      modeDesc.textContent = modeMap[mode] || '';
      try { sessionStorage.setItem(STORE_MODE, mode); } catch(e){}
    });
  });
  try {
    var savedMode = sessionStorage.getItem(STORE_MODE);
    if (savedMode && modeMap[savedMode]) {
      document.querySelectorAll('.tt-mode').forEach(b=>b.classList.remove('active'));
      var active = document.querySelector('.tt-mode[data-mode=\"' + savedMode + '\"]');
      if (active) { active.classList.add('active'); modeDesc.textContent = modeMap[savedMode]; }
    }
  } catch(e){}

  swapBtn.addEventListener('click', function () {
    var oldSrc = sourceSel.value;
    var newSrc = targetSel.value;
    var newTgt = oldSrc === 'auto' ? (lastDetected || 'en') : oldSrc;
    if (newSrc === newTgt) newTgt = newSrc === 'en' ? 'nl' : 'en';
    sourceSel.value = newSrc;
    targetSel.value = newTgt;
    refreshChrome();
    if (current && current.translation) {
      input.value = current.translation;
      countEl.textContent = input.value.length + ' / 500';
      runTranslate(current.translation);
    } else if (lastOriginal) {
      runTranslate(lastOriginal);
    }
  });

  var detectTimer = null;
  input.addEventListener('input', function () {
    countEl.textContent = input.value.length + ' / 500';
    if (sourceSel.value !== 'auto') return;
    var text = input.value.trim();
    if (detectTimer) clearTimeout(detectTimer);
    if (text.length < 3) { detectHint.textContent = ''; return; }
    detectTimer = setTimeout(function () {
      fetch(DETECT_ENDPOINT, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
        body: JSON.stringify({ text: text })
      }).then(function (r) { return r.ok ? r.json() : null; }).then(function (body) {
        if (!body || !body.detection) return;
        if (sourceSel.value === 'auto' && body.detection.name) {
          detectHint.textContent = 'Detected: ' + body.detection.name + ' (' + (body.detection.confidence*100).toFixed(0) + '%)';
          lastDetected = body.detection.code || '';
        }
      }).catch(function () {});
    }, 600);
  });

  // ---------- SpeechProvider integration ----------
  var provider = window.windelsSpeech || (window.SpeechProvider ? new window.SpeechProvider() : null);
  var ttsNote = document.getElementById('tts-note');
  var playBtn = document.getElementById('tt-play');
  var stopBtn = document.getElementById('tt-stop');
  var replayBtn = document.getElementById('tt-replay');
  var voiceSel = document.getElementById('tt-voice');
  var rateSel = document.getElementById('tt-rate');
  var rateVal = document.getElementById('tt-rate-val');
  var lessonListen = document.getElementById('lesson-listen');

  function populateVoices(locale) {
    if (!provider) return false;
    var matches = provider.getVoicesForLocale(locale);
    voiceSel.innerHTML = '';
    if (!matches.length) {
      voiceSel.disabled = true;
      voiceSel.innerHTML = '<option>No voice for this language</option>';
      return false;
    }
    voiceSel.disabled = false;
    matches.forEach(function (v, i) {
      var o = document.createElement('option');
      o.value = i;
      o.textContent = v.name + ' (' + v.lang + ')';
      voiceSel.appendChild(o);
    });
    voiceSel._matches = matches;
    return true;
  }

  function speak(text, locale) {
    if (!provider || !text) return;
    var rate = parseFloat(rateSel.value) || 1;
    var voiceIdx = parseInt(voiceSel.value, 10);
    var voice = voiceSel._matches && voiceSel._matches[voiceIdx] ? voiceSel._matches[voiceIdx] : null;
    provider.textToSpeech(text, {
      locale: locale,
      voice: voice,
      rate: rate,
      onEnd: function() { playBtn.disabled = false; stopBtn.disabled = true; },
      onError: function() { playBtn.disabled = false; stopBtn.disabled = true; }
    });
    playBtn.disabled = true;
    stopBtn.disabled = false;
  }

  if (provider) {
    var health = provider.healthCheck();
    if (!health.tts) {
      ttsNote.style.display = 'block';
      ttsNote.textContent = 'Text-to-speech not available in this browser. Translation still works — nothing is faked.';
      [playBtn, stopBtn, replayBtn, lessonListen].forEach(function(b){ if(b) b.disabled = true; });
    } else {
      // Try populate after voices load
      setTimeout(function(){ populateVoices(localeFor(targetSel.value)); }, 500);
      if (provider.synth) {
        provider.synth.onvoiceschanged = function(){ populateVoices(localeFor(targetSel.value)); };
      }
      playBtn.addEventListener('click', function () { if (current && current.translation) speak(current.translation, current.targetLocale); });
      replayBtn.addEventListener('click', function () { if (current && current.translation) speak(current.translation, current.targetLocale); });
      stopBtn.addEventListener('click', function () { provider.stop(); playBtn.disabled = false; stopBtn.disabled = true; });
      if (lessonListen) {
        lessonListen.addEventListener('click', function(){ speak(lessonAi.textContent, localeFor(targetSel.value)); });
      }
      rateSel.addEventListener('input', function () { rateVal.textContent = (parseFloat(rateSel.value) || 1).toFixed(1) + '×'; });
      document.querySelectorAll('[data-speed]').forEach(function(btn){
        btn.addEventListener('click', function(){
          var sp = btn.getAttribute('data-speed');
          rateSel.value = sp;
          rateVal.textContent = parseFloat(sp).toFixed(1) + '×';
          document.querySelectorAll('[data-speed]').forEach(b=>b.classList.remove('primary'));
          btn.classList.add('primary');
        });
      });
    }
  }

  // STT via SpeechProvider
  var SR = window.SpeechRecognition || window.webkitSpeechRecognition || null;
  var sttNote = document.getElementById('stt-note');
  var micBtn = document.getElementById('tt-mic');
  var transcriptInput = document.getElementById('tt-transcript');
  var checkBtn = document.getElementById('tt-check');
  var retryBtn = document.getElementById('tt-retry');
  var micStatus = document.getElementById('tt-mic-status');
  var feedback = document.getElementById('tt-feedback');

  function syncMicState() {
    var canMic = !!(provider && provider.healthCheck().stt && current && current.translation);
    micBtn.disabled = !canMic;
    checkBtn.disabled = !(canMic && transcriptInput.value.trim());
  }

  if (!provider || !provider.healthCheck().stt) {
    sttNote.style.display = 'block';
    sttNote.textContent = 'Your browser does not expose speech recognition, so the microphone cannot capture your voice here. You can still Listen to the correct pronunciation and type the translation to self-check.';
  }

  micBtn.addEventListener('click', function () {
    if (!provider || !current || !current.translation) return;
    transcriptInput.value = '';
    micStatus.textContent = 'Listening… say the translation aloud.';
    provider.speechToText({
      locale: current.targetLocale || 'en',
      onResult: function(transcript){
        transcriptInput.value = transcript;
        micStatus.textContent = 'Transcript captured — review and Check.';
        syncMicState();
      },
      onError: function(ev){
        var msg = ev.error || ev.message || 'unknown';
        micStatus.textContent = 'Speech engine error: ' + msg + ' — nothing was recorded.';
      },
      onEnd: function(){
        if (micStatus.textContent === 'Listening… say the translation aloud.') micStatus.textContent = 'Nothing captured — try again.';
      }
    });
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
      '<div class=\"dim\" style=\"margin-top:6px\">Target: ' + escapeHtml(current.translation) + '</div>' +
      '<div class=\"dim\">You said: ' + escapeHtml(spoken || '') + '</div>' +
      '<div class=\"dim\" style=\"margin-top:6px;font-size:11px\">Word accuracy compares the real speech-to-text transcript with the target. It is not a pronunciation or fluency score — those need a pronunciation-assessment provider that is not configured, and are never invented.</div>';
    retryBtn.hidden = false;
  }
  function normalize(s) { return (s || '').toLowerCase().replace(/[^\p{L}\p{N}\s]/gu, ' ').replace(/\s+/g, ' ').trim(); }
  function escapeHtml(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var text = input.value.trim();
    if (!text) { input.focus(); return; }
    runTranslate(text);
  });

  document.querySelectorAll('.tt-example').forEach(function (btn) {
    btn.addEventListener('click', function () {
      sourceSel.value = btn.getAttribute('data-src');
      targetSel.value = btn.getAttribute('data-target');
      refreshChrome();
      input.value = btn.getAttribute('data-text');
      countEl.textContent = input.value.length + ' / 500';
      runTranslate(input.value);
    });
  });

  function runTranslate(text) {
    lastOriginal = text;
    var payload = { text: text, target: targetSel.value };
    if (sourceSel.value !== 'auto') payload.source = sourceSel.value;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class=\"tt-loading\"></span> Translating…';
    fetch(ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
      body: JSON.stringify(payload)
    }).then(function (r) {
      return r.json().then(function (body) { if (!r.ok) throw new Error(body.error || 'Translation failed'); return body; });
    }).then(function (body) {
      render(body.translation, text);
    }).catch(function (err) {
      showError(err.message || 'The translator is unavailable.');
    }).finally(function () {
      submitBtn.disabled = false;
      submitBtn.textContent = 'Translate & Learn';
    });
  }

  function render(t, originalText) {
    if (!t) { showError('The translator returned no result.'); return; }
    current = t;
    lastDetected = (t.detected && t.detected.code) ? t.detected.code : (t.source || lastDetected);
    placeholderEl.hidden = true;
    resultEl.hidden = false;

    var srcName = langName(t.source) || (t.source ? t.source.toUpperCase() : '—');
    document.getElementById('tt-source-badge').textContent = 'From: ' + srcName + (sourceSel.value === 'auto' ? ' · detected' : '');
    document.getElementById('tt-target-badge').textContent = 'To: ' + t.targetName;
    document.getElementById('tt-detect-badge').textContent = 'Detected: ' + (t.detected && t.detected.name ? t.detected.name + ' ' + Math.round(t.detected.confidence*100) + '%' : srcName);

    var methodBadge = document.getElementById('tt-method-badge');
    if (t.method && t.method !== 'none' && t.method !== 'same-language') { methodBadge.hidden = false; methodBadge.textContent = t.method; } else { methodBadge.hidden = true; }

    var trEl = document.getElementById('tt-translation');
    trEl.textContent = t.translation || '(no fluent translation available)';
    var dir = RTL_LANGS[t.target] ? 'rtl' : 'ltr';
    trEl.dir = dir; trEl.lang = t.targetLocale || t.target;
    trEl.classList.toggle('rtl', dir === 'rtl');

    document.getElementById('tt-original').innerHTML = '<b>You typed:</b> ' + escapeHtml(originalText) + '<br><b>System:</b> Detected language: ' + escapeHtml(t.detected && t.detected.name ? t.detected.name : t.sourceName) + ' → Target: ' + escapeHtml(t.targetName) + (t.source === t.target ? ' (same language, continuing lesson)' : ' (different language, translating/explaining, target stays ' + escapeHtml(t.targetName) + ')');
    document.getElementById('tt-note').textContent = t.note || '';

    if (provider) {
      var ok = populateVoices(t.targetLocale || t.target);
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

    transcriptInput.value = ''; feedback.hidden = true; retryBtn.hidden = true; micStatus.textContent = '';
    syncMicState();
    pushHistory(t, originalText);
  }

  function showError(msg) {
    ttsNote.style.display = 'block';
    ttsNote.className = 'notice warnbox';
    ttsNote.textContent = msg;
    ttsNote.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  var historyPanel = document.getElementById('tt-history-panel');
  var historyBox = document.getElementById('tt-history');
  function pushHistory(t, originalText) {
    historyPanel.hidden = false;
    var srcAtSubmit = sourceSel.value;
    var tgtAtSubmit = targetSel.value;
    var row = document.createElement('div');
    row.className = 'row';
    row.innerHTML = '<span><b>' + escapeHtml(originalText) + '</b> <span class=\"dim\">→ ' + escapeHtml(t.translation || '—') + '</span></span><span class=\"dim\">' + escapeHtml(t.targetName) + '</span>';
    row.addEventListener('click', function () {
      sourceSel.value = srcAtSubmit;
      targetSel.value = tgtAtSubmit;
      refreshChrome();
      input.value = originalText;
      countEl.textContent = originalText.length + ' / 500';
      render(t, originalText);
    });
    historyBox.prepend(row);
  }

  recall();
  refreshChrome();
  input.focus();
})();
</script>
