/* WINDELS Assistant — floating chat widget behaviour.
   Open/close animation (class-driven, so the panel transitions instead of
   popping), Escape/outside-click to close, aria wiring, and the grounded
   assistant endpoint call. No layout is touched from JS: positioning lives
   entirely in assets/css/chat-widget.css. */
(function () {
  'use strict';
  var root = document.getElementById('ai_workforce-chat');
  if (!root || root.dataset.ready === '1') return;
  root.dataset.ready = '1';

  var launch = root.querySelector('.ai_workforce-chat-launch');
  var panel = root.querySelector('.ai_workforce-chat-panel');
  var close = root.querySelector('.ai_workforce-chat-close');
  var form = root.querySelector('.ai_workforce-chat-form');
  var input = form && form.querySelector('input[name="message"]');
  var messages = root.querySelector('.ai_workforce-chat-messages');
  var endpoint = root.getAttribute('data-endpoint') || '/api/chat/respond';
  var closeTimer = null;

  function isOpen() { return root.classList.contains('is-open'); }

  function setOpen(open) {
    if (closeTimer) { clearTimeout(closeTimer); closeTimer = null; }
    launch.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) {
      panel.hidden = false;
      // Force a style flush so the transition from the closed state runs.
      void panel.offsetHeight;
      root.classList.add('is-open');
      if (input) input.focus({ preventScroll: true });
    } else {
      root.classList.remove('is-open');
      var done = function () {
        if (!isOpen()) panel.hidden = true;
        panel.removeEventListener('transitionend', done);
      };
      panel.addEventListener('transitionend', done);
      // Reduced-motion / no-transition fallback.
      closeTimer = setTimeout(done, 250);
    }
  }

  function addMessage(text, kind) {
    var item = document.createElement('div');
    item.className = 'ai_workforce-chat-message ' + kind;
    item.textContent = text;
    if (kind.indexOf('agent') === 0 && kind.indexOf('pending') === -1) {
      var listen = document.createElement('button');
      listen.type = 'button';
      listen.className = 'ai_workforce-chat-listen';
      listen.textContent = '🔊 Listen';
      listen.setAttribute('data-listen', text);
      item.appendChild(listen);
    }
    messages.appendChild(item);
    messages.scrollTop = messages.scrollHeight;
    return item;
  }

  launch.addEventListener('click', function () { setOpen(!isOpen()); });
  close.addEventListener('click', function () { setOpen(false); launch.focus({ preventScroll: true }); });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && isOpen()) setOpen(false);
  });
  document.addEventListener('click', function (event) {
    if (isOpen() && !root.contains(event.target)) setOpen(false);
  });

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    var message = input.value.trim();
    if (!message) return;
    input.value = '';
    addMessage(message, 'user');
    var pending = addMessage('Thinking…', 'agent pending');
    var button = form.querySelector('button');
    button.disabled = true;
    fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ message: message })
    })
      .then(function (response) {
        return response.json().then(function (body) {
          if (!response.ok) throw new Error(body.error || 'The assistant is unavailable.');
          return body;
        });
      })
      .then(function (body) {
        var reply = body.message || 'No response was returned.';
        pending.className = 'ai_workforce-chat-message agent';
        pending.textContent = reply;
        var listen = document.createElement('button');
        listen.type = 'button';
        listen.className = 'ai_workforce-chat-listen';
        listen.textContent = '🔊 Listen';
        listen.setAttribute('data-listen', reply);
        pending.appendChild(listen);
      })
      .catch(function (error) {
        pending.className = 'ai_workforce-chat-message error';
        pending.textContent = error.message || 'The assistant is unavailable.';
      })
      .finally(function () { button.disabled = false; });
  });

  var speech = window.windelsSpeech || (window.SpeechProvider ? new window.SpeechProvider() : null);
  var statusEl = document.getElementById('ai_workforce-chat-voice-status');
  var micBtn = document.getElementById('ai_workforce-chat-mic');
  if (speech && micBtn && input) {
    speech.bindMic(micBtn, input, {
      locale: 'en-GB',
      idleLabel: '🎤 Speak',
      recordingLabel: 'Recording… Stop',
      onStatus: function (msg) {
        if (!statusEl) return;
        statusEl.hidden = !msg;
        statusEl.textContent = msg || '';
      }
    });
  } else if (micBtn) {
    micBtn.hidden = true;
  }

  root.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-listen]');
    if (!btn || !speech) return;
    ev.preventDefault();
    var text = btn.getAttribute('data-listen');
    if (!text) return;
    if (speech.isSpeaking() && btn.classList.contains('is-playing')) {
      speech.stop();
      btn.classList.remove('is-playing');
      btn.textContent = '🔊 Listen';
      return;
    }
    btn.classList.add('is-playing');
    btn.textContent = '⏹ Stop';
    speech.textToSpeech(text, {
      locale: 'en-GB',
      onEnd: function () { btn.classList.remove('is-playing'); btn.textContent = '🔊 Listen'; },
      onError: function () { btn.classList.remove('is-playing'); btn.textContent = '🔊 Listen'; }
    });
  });
}());
