/**
 * WINDELS AI WORKFORCE — SpeechProvider
 * Real browser TTS + STT. Never fakes a voice that is not installed.
 *
 *   windelsSpeech.healthCheck()
 *   windelsSpeech.textToSpeech(text, { locale, rate, onEnd })
 *   windelsSpeech.pause() / resume() / stop()
 *   windelsSpeech.speechToText({ locale, onResult, onError, onEnd })
 *   windelsSpeech.bindMic(button, input, { localeFor, onStatus })
 */
class SpeechProvider {
  constructor() {
    this.synth = typeof window !== 'undefined' && 'speechSynthesis' in window ? window.speechSynthesis : null;
    this.SR = typeof window !== 'undefined' ? (window.SpeechRecognition || window.webkitSpeechRecognition || null) : null;
    this._voices = [];
    this._voicesReady = false;
    this._utter = null;
    this._rec = null;
    this._recording = false;
    this._listenUserStop = false;
    this._listenHold = false;
    this._listenOpts = null;
    this._listenAccum = '';
    this._listenStartedAt = 0;
    this._listenRestartTimer = null;
    if (this.synth) {
      this._voices = this.synth.getVoices();
      if (this._voices.length === 0) {
        this.synth.onvoiceschanged = () => {
          this._voices = this.synth.getVoices();
          this._voicesReady = true;
        };
      } else {
        this._voicesReady = true;
      }
    }
  }

  healthCheck() {
    return {
      tts: !!this.synth,
      stt: !!this.SR,
      voices: this.getSupportedVoices().length,
      voicesReady: this._voicesReady,
      recording: this._recording,
      speaking: !!(this.synth && this.synth.speaking),
      paused: !!(this.synth && this.synth.paused),
      ttsNote: this.synth ? null : 'Text-to-speech is not available in this browser.',
      sttNote: this.SR ? null : 'Microphone speech recognition is not available in this browser.',
    };
  }

  getSupportedVoices() {
    if (this.synth) this._voices = this.synth.getVoices();
    return this._voices.slice();
  }

  getVoicesForLocale(locale) {
    const voices = this.getSupportedVoices();
    if (!locale) return voices;
    const lower = String(locale).toLowerCase();
    const base = lower.split('-')[0];
    const matches = voices.filter((v) => {
      const vl = (v.lang || '').toLowerCase();
      return vl === lower || vl.startsWith(base + '-') || vl === base || vl.startsWith(base);
    });
    matches.sort((a, b) => {
      const al = (a.lang || '').toLowerCase();
      const bl = (b.lang || '').toLowerCase();
      if (al === lower && bl !== lower) return -1;
      if (bl === lower && al !== lower) return 1;
      return 0;
    });
    return matches;
  }

  hasVoiceFor(locale) {
    return this.getVoicesForLocale(locale).length > 0;
  }

  friendlySttError(err) {
    const code = (err && (err.error || err.name || err.message)) || 'unavailable';
    const map = {
      'not-allowed': 'Microphone access was blocked. Allow the microphone in your browser settings to speak.',
      'permission-denied': 'Microphone access was blocked. Allow the microphone in your browser settings to speak.',
      'NotAllowedError': 'Microphone access was blocked. Allow the microphone in your browser settings to speak.',
      'no-speech': 'Still listening — speak when you are ready, or tap Stop when you are finished.',
      'audio-capture': 'No microphone was found on this device.',
      'network': 'Speech recognition needs a network connection in this browser.',
      'service-not-allowed': 'Speech recognition is not allowed in this browser.',
      'aborted': 'Listening stopped.',
      'STT not available': 'Voice input is not available in this browser. You can still type.',
    };
    return map[code] || 'Voice input is not available right now. You can still type.';
  }

  /**
   * Spoken form of the brand: Win-dels (WIN + dels). On-screen spelling stays WINDELS.
   */
  speakableText(text) {
    return String(text == null ? '' : text).replace(/\bWINDELS\b/gi, 'Win-dels');
  }

  textToSpeech(text, opts) {
    opts = opts || {};
    if (!this.synth || !text) {
      if (opts.onError) opts.onError(new Error('TTS not available'));
      return null;
    }
    const spoken = this.speakableText(text);
    const locale = opts.locale || 'en-US';
    const run = () => {
      try { this.synth.cancel(); } catch (e) { /* ignore */ }
      try { this.synth.resume(); } catch (e) { /* Chrome needs resume after cancel */ }
      const utter = new SpeechSynthesisUtterance(spoken);
      utter.lang = locale;
      utter.rate = typeof opts.rate === 'number' ? opts.rate : 1;
      utter.volume = typeof opts.volume === 'number' ? opts.volume : 1;
      utter.pitch = typeof opts.pitch === 'number' ? opts.pitch : 1;

      if (opts.voice && typeof opts.voice === 'object' && opts.voice.lang) {
        utter.voice = opts.voice;
        utter.lang = opts.voice.lang;
      } else if (typeof opts.voice === 'number') {
        const voices = this.getVoicesForLocale(utter.lang);
        if (voices[opts.voice]) {
          utter.voice = voices[opts.voice];
          utter.lang = voices[opts.voice].lang;
        }
      } else {
        const matches = this.getVoicesForLocale(utter.lang);
        if (matches.length) {
          utter.voice = matches[0];
          utter.lang = matches[0].lang;
        } else {
          const any = this.getSupportedVoices();
          if (any.length) {
            utter.voice = any[0];
          } else if (opts.requireVoice) {
            if (opts.onError) opts.onError(new Error('No voice for this language'));
            return null;
          }
        }
      }

      if (opts.onStart) utter.onstart = opts.onStart;
      utter.onend = () => { this._utter = null; if (opts.onEnd) opts.onEnd(); };
      utter.onerror = (e) => { this._utter = null; if (opts.onError) opts.onError(e); };
      this._utter = utter;
      this.synth.speak(utter);
      // Chrome sometimes drops the first utterance until voices have loaded.
      if (this.getSupportedVoices().length === 0) {
        const once = () => {
          this._voices = this.synth.getVoices();
          this._voicesReady = true;
          if (this._utter === utter && !this.synth.speaking) {
            try { this.synth.speak(utter); } catch (e) { /* ignore */ }
          }
        };
        this.synth.addEventListener('voiceschanged', once, { once: true });
      }
      return utter;
    };
    return run();
  }

  pause() {
    if (this.synth && this.synth.speaking && !this.synth.paused) this.synth.pause();
  }

  resume() {
    if (this.synth && this.synth.paused) this.synth.resume();
  }

  stop() {
    if (this.synth) this.synth.cancel();
    this._utter = null;
    this.stopListening();
  }

  isSpeaking() { return !!(this.synth && this.synth.speaking && !this.synth.paused); }
  isPaused() { return !!(this.synth && this.synth.paused); }
  isRecording() { return this._recording; }

  speechToText(opts) {
    opts = opts || {};
    if (!this.SR) {
      if (opts.onError) opts.onError(new Error('STT not available'));
      return null;
    }
    if (this._listenRestartTimer) {
      clearTimeout(this._listenRestartTimer);
      this._listenRestartTimer = null;
    }
    if (this._rec) {
      try { this._rec.onend = null; this._rec.stop(); } catch (e) { /* ignore */ }
      this._rec = null;
    }
    this._listenUserStop = false;
    this._listenOpts = opts;
    this._listenHold = opts.holdUntilStop !== false;
    this._listenMinMs = typeof opts.minListenMs === 'number' ? opts.minListenMs : 30000;
    this._listenStartedAt = Date.now();
    this._listenAccum = '';
    this._recording = true;
    return this._openRecognition();
  }

  _openRecognition() {
    const opts = this._listenOpts || {};
    if (!this.SR || this._listenUserStop) return null;
    const rec = new this.SR();
    rec.lang = opts.locale || 'en-US';
    rec.interimResults = opts.interimResults !== false;
    rec.maxAlternatives = opts.maxAlternatives || 1;
    rec.continuous = true;
    rec.onresult = (ev) => {
      let interim = '';
      let finals = '';
      for (let i = ev.resultIndex; i < ev.results.length; i++) {
        const piece = ev.results[i][0] ? ev.results[i][0].transcript : '';
        if (ev.results[i].isFinal) finals += piece + ' ';
        else interim += piece;
      }
      if (finals.trim()) {
        this._listenAccum = (this._listenAccum + ' ' + finals).replace(/\s+/g, ' ').trim();
      }
      const shown = (this._listenAccum + (interim ? ' ' + interim : '')).replace(/\s+/g, ' ').trim();
      if (opts.onResult && shown) opts.onResult(shown, ev);
    };
    rec.onerror = (ev) => {
      const code = ev && ev.error ? ev.error : '';
      if (code === 'no-speech' || code === 'aborted') {
        if (this._listenHold && !this._listenUserStop) {
          if (opts.onStatus) opts.onStatus('Still listening — speak when you are ready, or tap Stop when you are finished.');
          return;
        }
      }
      if (!this._listenHold || this._listenUserStop) {
        this._recording = false;
        if (opts.onError) opts.onError(ev);
      } else if (opts.onStatus) {
        opts.onStatus(this.friendlySttError(ev));
      }
    };
    rec.onend = () => {
      this._rec = null;
      if (this._listenUserStop) {
        this._recording = false;
        if (opts.onEnd) opts.onEnd({ reason: 'stop', transcript: this._listenAccum });
        return;
      }
      if (this._listenHold) {
        const elapsed = Date.now() - this._listenStartedAt;
        if (opts.onStatus) {
          opts.onStatus(elapsed < this._listenMinMs
            ? 'Still listening — no speech yet. Keep going, or tap Stop when you are finished.'
            : 'Still listening for more. Tap Stop when you have finished talking.');
        }
        this._listenRestartTimer = setTimeout(() => {
          this._listenRestartTimer = null;
          if (!this._listenUserStop) this._openRecognition();
        }, 180);
        return;
      }
      this._recording = false;
      if (opts.onEnd) opts.onEnd({ reason: 'end', transcript: this._listenAccum });
    };
    rec.onstart = () => {
      this._recording = true;
      if (opts.onStart) opts.onStart();
    };
    try {
      rec.start();
      this._rec = rec;
    } catch (e) {
      this._listenRestartTimer = setTimeout(() => {
        this._listenRestartTimer = null;
        if (!this._listenUserStop) this._openRecognition();
      }, 400);
    }
    return rec;
  }

  stopListening() {
    this._listenUserStop = true;
    if (this._listenRestartTimer) {
      clearTimeout(this._listenRestartTimer);
      this._listenRestartTimer = null;
    }
    if (this._rec) {
      try { this._rec.stop(); } catch (e) { /* already stopped */ }
      this._rec = null;
    }
    this._recording = false;
  }

  /**
   * Wire a microphone button to an input/textarea.
   * Listens through silence for at least 30s; only ends when the user taps Stop.
   */
  bindMic(button, input, opts) {
    opts = opts || {};
    if (!button || !input) return;
    const idle = opts.idleLabel || '🎤 Tap to Speak';
    const recLabel = opts.recordingLabel || '🎤 Listening…';
    const status = opts.onStatus || function () {};
    const stopBtn = typeof opts.stopButton === 'string' ? document.querySelector(opts.stopButton) : opts.stopButton;
    const self = this;
    button.type = 'button';
    if (!button.textContent.trim()) button.textContent = idle;

    const setIdle = () => {
      button.classList.remove('is-recording');
      button.setAttribute('aria-pressed', 'false');
      button.textContent = idle;
      if (stopBtn) stopBtn.disabled = true;
    };
    const setRec = () => {
      button.classList.add('is-recording');
      button.setAttribute('aria-pressed', 'true');
      button.textContent = recLabel;
      if (stopBtn) {
        stopBtn.hidden = false;
        stopBtn.disabled = false;
      }
    };

    if (!this.SR) {
      button.hidden = !!opts.hideIfUnavailable;
      if (!opts.hideIfUnavailable) {
        button.disabled = true;
        button.title = 'Voice input is not available in this browser.';
      }
      if (stopBtn) stopBtn.hidden = true;
      return;
    }

    const finish = function (msg) {
      setIdle();
      status(msg);
    };

    const startListen = function () {
      const locale = typeof opts.localeFor === 'function' ? opts.localeFor() : (opts.locale || 'en-GB');
      setRec();
      status('Listening… take your time. Silence does not end this. Tap Stop when you have finished talking.');
      self.speechToText({
        locale: locale,
        interimResults: true,
        holdUntilStop: true,
        minListenMs: opts.minListenMs || 30000,
        onStatus: status,
        onResult: function (transcript) {
          if (!transcript) return;
          if (input.tagName === 'TEXTAREA' || input.tagName === 'INPUT') {
            input.value = transcript;
            input.dispatchEvent(new Event('input', { bubbles: true }));
          }
          status('Heard you — still listening for more. Tap Stop when you are finished.');
        },
        onError: function (err) {
          const code = err && (err.error || err.message);
          if (code === 'no-speech' || code === 'aborted') {
            status('Still listening — speak when you are ready, or tap Stop when you are finished.');
            return;
          }
          finish(self.friendlySttError(err));
        },
        onEnd: function (info) {
          const got = String((info && info.transcript) || input.value || '').trim();
          if (info && info.reason === 'stop') {
            finish(got ? 'Stopped. Review what was captured.' : 'Stopped. Nothing was captured — tap Speak to try again.');
          } else {
            finish(got ? 'Listening ended. Review what was captured.' : 'Still waiting — tap Speak to listen again.');
          }
        },
      });
    };

    button.addEventListener('click', function (ev) {
      ev.preventDefault();
      if (self._recording) {
        self.stopListening();
        finish(String(input.value || '').trim() ? 'Stopped. Review what was captured.' : 'Stopped.');
        return;
      }
      startListen();
    });

    if (stopBtn) {
      stopBtn.type = 'button';
      stopBtn.disabled = true;
      stopBtn.addEventListener('click', function (ev) {
        ev.preventDefault();
        self.stopListening();
        finish(String(input.value || '').trim() ? 'Stopped. Review what was captured.' : 'Stopped.');
      });
    }
  }
}

if (typeof window !== 'undefined') {
  window.SpeechProvider = SpeechProvider;
  window.windelsSpeech = window.windelsSpeech || new SpeechProvider();
}
