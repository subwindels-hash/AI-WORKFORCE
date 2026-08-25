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
      'no-speech': 'Nothing was heard. Try speaking a little closer to the microphone.',
      'audio-capture': 'No microphone was found on this device.',
      'network': 'Speech recognition needs a network connection in this browser.',
      'service-not-allowed': 'Speech recognition is not allowed in this browser.',
      'aborted': 'Listening stopped.',
      'STT not available': 'Voice input is not available in this browser. You can still type.',
    };
    return map[code] || 'Voice input is not available right now. You can still type.';
  }

  textToSpeech(text, opts) {
    opts = opts || {};
    if (!this.synth || !text) {
      if (opts.onError) opts.onError(new Error('TTS not available'));
      return null;
    }
    this.synth.cancel();
    const utter = new SpeechSynthesisUtterance(text);
    utter.lang = opts.locale || 'en-US';
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
      } else if (opts.requireVoice) {
        if (opts.onError) opts.onError(new Error('No voice for this language'));
        return null;
      }
    }

    if (opts.onStart) utter.onstart = opts.onStart;
    utter.onend = () => { this._utter = null; if (opts.onEnd) opts.onEnd(); };
    utter.onerror = (e) => { this._utter = null; if (opts.onError) opts.onError(e); };
    this._utter = utter;
    this.synth.speak(utter);
    return utter;
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
    this.stopListening();
    const rec = new this.SR();
    rec.lang = opts.locale || 'en-US';
    rec.interimResults = !!opts.interimResults;
    rec.maxAlternatives = opts.maxAlternatives || 1;
    rec.continuous = !!opts.continuous;
    rec.onresult = (ev) => {
      let transcript = '';
      for (let i = ev.resultIndex; i < ev.results.length; i++) {
        transcript += ev.results[i][0] ? ev.results[i][0].transcript : '';
      }
      if (opts.onResult) opts.onResult(transcript.trim(), ev);
    };
    rec.onerror = (ev) => { this._recording = false; if (opts.onError) opts.onError(ev); };
    rec.onend = () => { this._recording = false; this._rec = null; if (opts.onEnd) opts.onEnd(); };
    rec.onstart = () => { this._recording = true; if (opts.onStart) opts.onStart(); };
    try {
      rec.start();
      this._rec = rec;
    } catch (e) {
      this._recording = false;
      if (opts.onError) opts.onError(e);
      return null;
    }
    return rec;
  }

  stopListening() {
    if (this._rec) {
      try { this._rec.stop(); } catch (e) { /* already stopped */ }
      this._rec = null;
    }
    this._recording = false;
  }

  /**
   * Wire a microphone button to an input/textarea.
   * Button labels: "🎤 Tap to Speak" → "Recording..." → "Stop"
   */
  bindMic(button, input, opts) {
    opts = opts || {};
    if (!button || !input) return;
    const idle = opts.idleLabel || '🎤 Tap to Speak';
    const recLabel = opts.recordingLabel || 'Recording… Stop';
    const status = opts.onStatus || function () {};
    const self = this;
    button.type = 'button';
    if (!button.textContent.trim()) button.textContent = idle;

    const setIdle = () => {
      button.classList.remove('is-recording');
      button.setAttribute('aria-pressed', 'false');
      button.textContent = idle;
    };
    const setRec = () => {
      button.classList.add('is-recording');
      button.setAttribute('aria-pressed', 'true');
      button.textContent = recLabel;
    };

    if (!this.SR) {
      button.hidden = !!opts.hideIfUnavailable;
      if (!opts.hideIfUnavailable) {
        button.disabled = true;
        button.title = 'Voice input is not available in this browser.';
      }
      return;
    }

    button.addEventListener('click', function (ev) {
      ev.preventDefault();
      if (self._recording) {
        self.stopListening();
        setIdle();
        status('Stopped.');
        return;
      }
      const locale = typeof opts.localeFor === 'function' ? opts.localeFor() : (opts.locale || 'en-GB');
      setRec();
      status('Listening… speak now.');
      self.speechToText({
        locale: locale,
        interimResults: true,
        onResult: function (transcript) {
          if (!transcript) return;
          if (input.tagName === 'TEXTAREA' || input.tagName === 'INPUT') {
            input.value = transcript;
            input.dispatchEvent(new Event('input', { bubbles: true }));
          }
          status('Recognized. Review and send.');
        },
        onError: function (err) {
          setIdle();
          status(self.friendlySttError(err));
        },
        onEnd: function () {
          setIdle();
          if (!String(input.value || '').trim()) status('Nothing was captured. Tap to speak again.');
        },
      });
    });
  }
}

if (typeof window !== 'undefined') {
  window.SpeechProvider = SpeechProvider;
  window.windelsSpeech = new SpeechProvider();
}
