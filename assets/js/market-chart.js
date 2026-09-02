/**
 * WINDELS AI WORKFORCE — live market-data chart.
 *
 * Streams real bars from /api/market-data/live and re-renders the same SVG the
 * server draws in welcome/partials/chart.php: candles, volume, EMA20/EMA50 and
 * the support/resistance + trade-setup overlays carried over from the analysis
 * run that produced the page.
 *
 * Honesty rules (mirror the server, never relax them):
 *   • The LIVE badge is driven entirely by the server's own verdict. Synthetic
 *     data renders with a SIMULATION badge and auto-refresh stays OFF — we do
 *     not animate fake prices and call it live.
 *   • STALE and DELAYED get their own badges; delayed feeds never claim LIVE.
 *   • Polling pauses on a hidden tab and stops on repeated errors.
 *
 * No dependencies, no external chart library — traditional MVC + one script.
 */
(() => {
  'use strict';

  const SVGNS = 'http://www.w3.org/2000/svg';

  // Same canvas geometry as the server-rendered partial.
  const W = 1000;
  const H = 380;
  const PADL = 6;
  const PADR = 74;
  const PADT = 10;
  const VOLH = 56;
  const PRICE_H = H - PADT - VOLH - 16;
  const MAX_BARS = 140;

  const COL = {
    grid: '#141926',
    axis: '#5b6478',
    up: '#26a69a',
    down: '#ef5350',
    volUp: '#134e4a',
    volDown: '#4c1d24',
    ema20: '#fbbf24',
    ema50: '#a78bfa',
    support: '#38bdf8',
    resistance: '#f87171',
    sl: '#ef4444',
    tp: '#22c55e',
  };

  const BADGES = {
    LIVE: { text: 'LIVE', cls: 'b-green' },
    DELAYED: { text: 'DELAYED', cls: 'b-gray' },
    STALE: { text: 'STALE', cls: 'b-gray' },
    SYNTHETIC: { text: 'SIMULATION', cls: 'b-red' },
    OFFLINE: { text: 'OFFLINE', cls: 'b-red' },
  };

  const MAX_ERRORS = 4;

  function el(tag, attrs, text) {
    const node = document.createElementNS(SVGNS, tag);
    if (attrs) Object.keys(attrs).forEach((k) => node.setAttribute(k, attrs[k]));
    if (text !== undefined && text !== null) node.textContent = String(text);
    return node;
  }

  function title(parent, text) {
    parent.appendChild(el('title', null, text));
  }

  function fmtPrice(p) {
    if (p === null || p === undefined || isNaN(p)) return '—';
    const digits = p >= 100 ? 1 : (p >= 10 ? 3 : 5);
    return Number(p).toFixed(digits);
  }

  function fmtClock(ms) {
    if (!ms) return '—';
    const d = new Date(ms);
    const p = (n) => String(n).padStart(2, '0');
    return p(d.getUTCHours()) + ':' + p(d.getUTCMinutes()) + ':' + p(d.getUTCSeconds()) + 'Z';
  }

  /** Causal EMA, identical recursion to the server partial. */
  function emaSeries(values, period) {
    const k = 2 / (period + 1);
    const out = [];
    let prev = null;
    let seed = 0;
    for (let i = 0; i < values.length; i++) {
      const v = values[i];
      if (i < period - 1) { seed += v; out.push(null); continue; }
      prev = prev === null ? (seed + v) / period : v * k + prev * (1 - k);
      out.push(prev);
    }
    return out;
  }

  class LiveChart {
    constructor(mount) {
      this.mount = mount;
      this.symbol = mount.dataset.symbol || 'BTCUSDT';
      this.timeframe = mount.dataset.timeframe || '1h';
      this.marketClass = mount.dataset.marketClass || '';
      this.limit = parseInt(mount.dataset.limit || '200', 10);

      // Overlays from the analysis run that rendered this page. They are a
      // snapshot, not tick data, and stay until the page is reloaded.
      let overlays = { support: [], resistance: [], setup: null };
      try {
        const parsed = JSON.parse(mount.dataset.overlays || '{}');
        if (parsed && typeof parsed === 'object') overlays = Object.assign(overlays, parsed);
      } catch (e) { /* keep the empty default */ }
      this.overlays = overlays;

      this.timer = null;
      this.countdown = null;
      this.nextIn = 0;
      this.errors = 0;
      this.running = false;
      this.lastPayload = null;

      this.build();
      this.bind();
    }

    build() {
      const m = this.mount;
      m.innerHTML = '';

      this.bar = document.createElement('div');
      this.bar.className = 'livechart-bar';

      this.badge = document.createElement('span');
      this.badge.className = 'badge b-gray';
      this.badge.textContent = '—';

      this.source = document.createElement('span');
      this.source.className = 'livechart-source dim';
      this.source.textContent = 'waiting for data';

      this.lastPrice = document.createElement('span');
      this.lastPrice.className = 'livechart-price mono';

      this.updated = document.createElement('span');
      this.updated.className = 'dim livechart-updated';

      const controls = document.createElement('span');
      controls.className = 'livechart-controls';

      this.liveBtn = document.createElement('button');
      this.liveBtn.type = 'button';
      this.liveBtn.className = 'btn small ghost';
      this.liveBtn.textContent = 'Go live';
      this.liveBtn.title = 'Re-read the connected providers and start streaming real bars';

      this.toggleBtn = document.createElement('button');
      this.toggleBtn.type = 'button';
      this.toggleBtn.className = 'btn small ghost';
      this.toggleBtn.textContent = 'Pause';
      this.toggleBtn.disabled = true;

      controls.appendChild(this.liveBtn);
      controls.appendChild(this.toggleBtn);

      this.bar.appendChild(this.badge);
      this.bar.appendChild(this.lastPrice);
      this.bar.appendChild(this.source);
      this.bar.appendChild(this.updated);
      this.bar.appendChild(controls);

      this.wrap = document.createElement('div');
      this.wrap.className = 'livechart-canvas scroll';

      this.notice = document.createElement('div');
      this.notice.className = 'livechart-notice dim';
      this.notice.hidden = true;

      m.appendChild(this.bar);
      m.appendChild(this.wrap);
      m.appendChild(this.notice);
    }

    bind() {
      this.toggleBtn.addEventListener('click', () => {
        if (this.running) this.stop('Paused.');
        else this.start();
      });

      // "Go live" = reconnect the provider registry, then stream.
      this.liveBtn.addEventListener('click', async () => {
        this.liveBtn.disabled = true;
        this.liveBtn.textContent = 'Connecting…';
        this.setNotice('Re-reading connected providers in Admin → API…');
        try {
          const res = await fetch('/api/market-data/refresh', {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
          });
          const body = await res.json().catch(() => ({}));
          if (!res.ok) throw new Error(body.error || ('HTTP ' + res.status));
          this.describeRefresh(body);
        } catch (err) {
          this.setNotice('Could not refresh providers: ' + err.message, true);
        } finally {
          this.liveBtn.disabled = false;
          this.liveBtn.textContent = 'Go live';
        }
        await this.fetchOnce();
        this.start();
      });

      // Never poll a hidden tab.
      document.addEventListener('visibilitychange', () => {
        if (document.hidden) this.clearTimers();
        else if (this.running) this.arm();
      });
    }

    describeRefresh(body) {
      if (!body || typeof body !== 'object') return;
      const bits = [];
      if (body.realProvidersAllowed === false) {
        bits.push('AI_WORKFORCE_DISABLE_REAL_PROVIDERS=1 is forcing the simulated feed.');
      }
      const services = body.services || {};
      Object.keys(services).forEach((key) => {
        const s = services[key] || {};
        if (s.configured && !s.live) {
          bits.push((s.label || key) + ' is connected but not enabled — tick Enable in Admin → API.');
        }
      });
      if (body.marketDataLive) bits.unshift('Market data is LIVE.');
      else if (!bits.length) bits.push('No live feed yet. Connect a provider in Admin → API, then press Go live.');
      this.setNotice(bits.join(' '));
    }

    setNotice(text, isError) {
      if (!text) { this.notice.hidden = true; this.notice.textContent = ''; return; }
      this.notice.hidden = false;
      this.notice.textContent = text;
      this.notice.classList.toggle('livechart-error', !!isError);
    }

    setBadge(reason) {
      const b = BADGES[reason] || BADGES.OFFLINE;
      this.badge.textContent = b.text;
      this.badge.className = 'badge ' + b.cls;
    }

    async fetchOnce() {
      const params = new URLSearchParams({
        symbol: this.symbol,
        timeframe: this.timeframe,
        limit: String(this.limit),
      });
      if (this.marketClass) params.set('marketClass', this.marketClass);

      let res;
      try {
        res = await fetch('/api/market-data/live?' + params.toString(), {
          credentials: 'same-origin',
          headers: { Accept: 'application/json' },
        });
      } catch (err) {
        this.onError('Network unreachable: ' + err.message);
        return null;
      }

      const body = await res.json().catch(() => ({}));
      if (!res.ok) {
        this.onError(body.error || ('HTTP ' + res.status));
        return null;
      }

      this.errors = 0;
      this.lastPayload = body;
      this.render(body);
      return body;
    }

    onError(message) {
      this.errors++;
      this.setBadge('OFFLINE');
      this.setNotice('Live feed error (' + this.errors + '/' + MAX_ERRORS + '): ' + message, true);
      if (this.errors >= MAX_ERRORS) {
        // Keep the underlying reason visible — "stopped" alone tells the
        // operator nothing about what to fix.
        this.running = false;
        this.clearTimers();
        this.toggleBtn.disabled = !this.lastPayload;
        this.toggleBtn.textContent = 'Resume';
        this.setNotice('Stopped after ' + MAX_ERRORS + ' failed polls — last error: ' + message +
          ' · Press Go live to re-read the connected providers and retry.', true);
      }
    }

    start() {
      if (!this.lastPayload) {
        // Nothing fetched yet — do one poll so we know whether auto-refresh is
        // even honest to run (synthetic data must not be animated as live).
        this.fetchOnce().then((body) => {
          if (body) this.armIfAllowed();
        });
        return;
      }
      this.armIfAllowed();
    }

    armIfAllowed() {
      const live = this.lastPayload && this.lastPayload.live;
      const synthetic = !!(live && live.synthetic);
      if (synthetic) {
        // Labelled simulation: render it, but never present it as streaming.
        this.running = false;
        this.clearTimers();
        this.toggleBtn.disabled = true;
        this.setNotice('Showing the labelled SIMULATION feed. Auto-refresh stays off — connect a real provider in Admin → API to stream live bars.');
        return;
      }
      this.running = true;
      this.toggleBtn.disabled = false;
      this.toggleBtn.textContent = 'Pause';
      this.setNotice('');
      this.arm();
    }

    arm() {
      this.clearTimers();
      if (!this.running || document.hidden) return;
      const secs = Math.max(10, parseInt((this.lastPayload && this.lastPayload.refreshSeconds) || 30, 10));
      this.nextIn = secs;
      this.timer = setTimeout(async () => {
        await this.fetchOnce();
        if (this.running) this.arm();
      }, secs * 1000);
      this.countdown = setInterval(() => {
        this.nextIn = Math.max(0, this.nextIn - 1);
        this.updated.textContent = 'next in ' + this.nextIn + 's';
      }, 1000);
    }

    stop(reason) {
      this.running = false;
      this.clearTimers();
      this.toggleBtn.disabled = !this.lastPayload;
      this.toggleBtn.textContent = 'Resume';
      if (reason) this.setNotice(reason);
    }

    clearTimers() {
      if (this.timer) clearTimeout(this.timer);
      if (this.countdown) clearInterval(this.countdown);
      this.timer = null;
      this.countdown = null;
    }

    /** @param {{symbol:string,timeframe:string,candles:Array,provenance:object,quote:object,live:object}} body */
    render(body) {
      const candles = Array.isArray(body.candles) ? body.candles.slice(-MAX_BARS) : [];
      const live = body.live || {};
      const prov = body.provenance || {};

      this.setBadge(live.reason || 'OFFLINE');

      const last = candles.length ? candles[candles.length - 1] : null;
      const q = body.quote && body.quote.quote ? body.quote.quote : null;
      const px = q && q.last ? q.last : (last ? last.close : null);
      const prev = candles.length > 1 ? candles[candles.length - 2].close : null;
      this.lastPrice.textContent = px === null ? '—' : fmtPrice(px);
      this.lastPrice.className = 'livechart-price mono' +
        (px !== null && prev !== null ? (px >= prev ? ' up' : ' down') : '');

      const srcBits = ['source: ' + (prov.source || 'none')];
      if (prov.delayed) srcBits.push('delayed');
      if (Array.isArray(prov.fallbackChain) && prov.fallbackChain.length) {
        srcBits.push('fell back from ' + prov.fallbackChain.join(', '));
      }
      this.source.textContent = srcBits.join(' · ');
      this.updated.textContent = 'bar ' + fmtClock(prov.dataTimestamp) + ' · fetched ' + fmtClock(prov.fetchedAt);

      if (!candles.length) {
        this.wrap.innerHTML = '';
        this.setNotice('No candles returned for ' + this.symbol + ' on ' + this.timeframe + '.', true);
        return;
      }
      this.draw(candles);
    }

    draw(candles) {
      const support = Array.isArray(this.overlays.support) ? this.overlays.support : [];
      const resistance = Array.isArray(this.overlays.resistance) ? this.overlays.resistance : [];
      const setup = this.overlays.setup || null;

      let lo = Math.min.apply(null, candles.map((c) => c.low));
      let hi = Math.max.apply(null, candles.map((c) => c.high));
      support.concat(resistance).forEach((l) => {
        if (l > lo * 0.9 && l < hi * 1.1) { lo = Math.min(lo, l); hi = Math.max(hi, l); }
      });
      if (setup && setup.stopLoss && setup.entry) {
        lo = Math.min(lo, setup.stopLoss, setup.entry.min);
        hi = Math.max(hi, setup.stopLoss, (setup.takeProfit || [])[0] || hi);
      }
      const pad = (hi - lo) * 0.04 || 1;
      lo -= pad; hi += pad;

      const n = candles.length;
      const step = (W - PADL - PADR) / Math.max(1, n);
      const x = (i) => PADL + i * step + step / 2;
      const y = (p) => PADT + PRICE_H - ((p - lo) / Math.max(1e-9, hi - lo)) * PRICE_H;
      const maxVol = Math.max(1, Math.max.apply(null, candles.map((c) => c.volume || 0)));
      const vy = (v) => H - 10 - ((v || 0) / maxVol) * VOLH;

      const svg = el('svg', {
        viewBox: '0 0 ' + W + ' ' + H,
        role: 'img',
        'aria-label': 'live candlestick chart for ' + this.symbol,
        class: 'livechart-svg',
      });

      // grid + price axis
      for (let g = 0; g <= 4; g++) {
        const gp = lo + (hi - lo) * g / 4;
        svg.appendChild(el('line', { x1: PADL, x2: W - PADR, y1: y(gp), y2: y(gp), stroke: COL.grid }));
        svg.appendChild(el('text', {
          x: W - PADR + 6, y: y(gp) + 3, 'font-size': 10, fill: COL.axis, 'font-family': 'monospace',
        }, fmtPrice(gp)));
      }

      const hline = (price, stroke, dash, label, opacity) => {
        if (price === null || price === undefined || isNaN(price)) return;
        const line = el('line', {
          x1: PADL, x2: W - PADR, y1: y(price), y2: y(price),
          stroke, 'stroke-dasharray': dash, opacity: opacity === undefined ? 0.5 : opacity,
        });
        title(line, label);
        svg.appendChild(line);
      };

      resistance.forEach((r) => hline(r, COL.resistance, '2 5', 'Resistance ' + fmtPrice(r)));
      support.forEach((s) => hline(s, COL.support, '2 5', 'Support ' + fmtPrice(s)));

      if (setup && setup.entry && setup.stopLoss) {
        const buy = setup.action === 'BUY';
        svg.appendChild(el('rect', {
          x: PADL, y: y(setup.entry.max), width: W - PADL - PADR,
          height: Math.max(2, y(setup.entry.min) - y(setup.entry.max)),
          fill: buy ? COL.tp : COL.sl, opacity: 0.12,
        }));
        hline(setup.stopLoss, COL.sl, '6 3', 'Stop loss', 1);
        svg.appendChild(el('text', {
          x: PADL + 4, y: y(setup.stopLoss) - 3, 'font-size': 10, fill: COL.resistance, 'font-family': 'monospace',
        }, 'SL ' + fmtPrice(setup.stopLoss)));
        (setup.takeProfit || []).forEach((tp, i) => {
          hline(tp, COL.tp, '6 3', 'Take profit ' + (i + 1), 0.9 - i * 0.25);
          svg.appendChild(el('text', {
            x: PADL + 4, y: y(tp) - 3, 'font-size': 10, fill: COL.tp, 'font-family': 'monospace',
          }, 'TP' + (i + 1) + ' ' + fmtPrice(tp)));
        });
      }

      // volume
      candles.forEach((c, i) => {
        const up = c.close >= c.open;
        const r = el('rect', {
          x: x(i) - step * 0.32, y: vy(c.volume),
          width: Math.max(1, step * 0.64), height: H - 10 - vy(c.volume),
          fill: up ? COL.volUp : COL.volDown,
        });
        title(r, 'vol ' + Math.round(c.volume || 0).toLocaleString('en-US'));
        svg.appendChild(r);
      });

      // candles
      candles.forEach((c, i) => {
        const up = c.close >= c.open;
        const colour = up ? COL.up : COL.down;
        const g = el('g');
        title(g, fmtClock(c.timestamp) + ' · O ' + fmtPrice(c.open) + ' H ' + fmtPrice(c.high) +
          ' L ' + fmtPrice(c.low) + ' C ' + fmtPrice(c.close));
        g.appendChild(el('line', { x1: x(i), x2: x(i), y1: y(c.high), y2: y(c.low), stroke: colour }));
        g.appendChild(el('rect', {
          x: x(i) - Math.max(1, step * 0.3),
          y: Math.min(y(c.open), y(c.close)),
          width: Math.max(1.5, step * 0.6),
          height: Math.max(1, Math.abs(y(c.close) - y(c.open))),
          fill: colour,
        }));
        svg.appendChild(g);
      });

      // EMAs (causal, full-series then offset like the server does)
      const closes = candles.map((c) => c.close);
      const poly = (period, stroke) => {
        const series = emaSeries(closes, period);
        const pts = [];
        series.forEach((v, i) => { if (v !== null) pts.push(x(i).toFixed(1) + ',' + y(v).toFixed(1)); });
        if (!pts.length) return;
        const line = el('polyline', { points: pts.join(' '), fill: 'none', stroke, 'stroke-width': 1.3, opacity: 0.9 });
        title(line, 'EMA' + period);
        svg.appendChild(line);
      };
      poly(20, COL.ema20);
      poly(50, COL.ema50);

      this.wrap.innerHTML = '';
      this.wrap.appendChild(svg);
    }

    destroy() {
      this.clearTimers();
      this.mount.innerHTML = '';
    }
  }

  function boot() {
    const mounts = document.querySelectorAll('[data-live-chart]');
    if (!mounts.length) return;
    const charts = [];
    mounts.forEach((mount) => {
      const chart = new LiveChart(mount);
      mount.__liveChart = chart;
      charts.push(chart);
      // Auto-start only when the server already told us this page is on a real
      // feed; otherwise the operator presses "Go live" after connecting.
      if (mount.dataset.autostart === '1') chart.start();
    });
    window.WindelsMarketChart = {
      charts,
      refreshAll() { charts.forEach((c) => c.fetchOnce()); },
    };
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
