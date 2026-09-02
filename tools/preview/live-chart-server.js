/**
 * UI preview harness for the live market-data chart.
 *
 * WHY THIS EXISTS: the sandbox has no PHP binary and no egress to
 * api.binance.com / api.frankfurter.dev / Yahoo, so the real CodeIgniter app
 * cannot run here. This serves the UNMODIFIED production assets
 * (assets/js/market-chart.js + assets/css/ai_workforce.css) against a
 * simulated /api/market-data/live so the actual shipping component can be seen
 * and driven in a browser.
 *
 * It is a preview only. The data is simulated and the page says so permanently.
 * Nothing here is part of the application; delete tools/preview/ to remove it.
 */
const http = require('http');
const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..', '..');
const PORT = parseInt(process.env.PORT || '8123', 10);
const HOST = '0.0.0.0';

// ---- simulated feed ---------------------------------------------------------
// Deterministic-per-seed random walk shaped EXACTLY like the payload
// ProviderManager::getCandleSeries() produces, so the real client code path
// (provenance -> live verdict -> badge) is exercised unchanged.
const TF_MS = { '1m': 60e3, '5m': 300e3, '15m': 900e3, '1h': 3600e3, '4h': 14400e3, '1d': 86400e3 };
const BASE = { BTCUSDT: 61250, ETHUSDT: 3180, SOLUSDT: 148, BNBUSDT: 585, XRPUSDT: 0.52,
  EURUSD: 1.0865, GBPUSD: 1.2715, USDJPY: 151.4, AUDUSD: 0.6585,
  AAPL: 227.5, MSFT: 415.2, GOOGL: 171.8, AMZN: 186.4, NVDA: 118.6, META: 512.3, TSLA: 246.1, JPM: 209.7,
  SPY: 561.2, QQQ: 487.9, IWM: 223.4, DIA: 421.8, VTI: 231.6, GLD: 238.9,
  'ES=F': 5580, 'NQ=F': 19240, 'CL=F': 71.35, 'GC=F': 2365 };
const REAL = new Set(Object.keys(BASE));   // symbols a real provider would serve

function rng(seed) { let s = seed >>> 0; return () => { s = (s * 1664525 + 1013904223) >>> 0; return s / 4294967296; }; }

function series(symbol, timeframe, limit, mode) {
  const tf = TF_MS[timeframe] || TF_MS['1h'];
  const base = BASE[symbol] || 100;
  const rand = rng(symbol.split('').reduce((a, c) => a + c.charCodeAt(0), 7) * 977 + tf);
  const now = Date.now();
  const start = now - tf * limit;
  const vol = timeframe === '1d' ? 0.012 : (timeframe === '1m' ? 0.0016 : 0.004);
  let px = base;
  const candles = [];
  for (let i = 0; i < limit; i++) {
    const drift = (rand() - 0.495) * vol * px;
    const open = px;
    const close = Math.max(base * 0.2, open + drift);
    const high = Math.max(open, close) * (1 + rand() * vol * 0.6);
    const low = Math.min(open, close) * (1 - rand() * vol * 0.6);
    candles.push({ timestamp: start + i * tf, open: +open.toFixed(6), high: +high.toFixed(6),
      low: +low.toFixed(6), close: +close.toFixed(6), volume: +(base * (0.4 + rand() * 1.8) * 1000).toFixed(2) });
    px = close;
  }

  const synthetic = mode === 'sim' || !REAL.has(symbol);
  const delayed = !synthetic && !['BTCUSDT', 'ETHUSDT', 'SOLUSDT', 'BNBUSDT', 'XRPUSDT',
    'EURUSD', 'GBPUSD', 'USDJPY', 'AUDUSD'].includes(symbol);   // Yahoo equities are delayed
  const stale = mode === 'stale';
  const source = synthetic ? 'synthetic-demo' : (delayed ? 'yahoo-chart-stock' : (/USDT$/.test(symbol) ? 'binance' : 'frankfurter-ecb'));
  const last = candles[candles.length - 1];
  const dataAgeMs = stale ? tf * 6 : Math.round(tf * 0.08);

  const prov = { source, synthetic, live: !synthetic, delayed,
    fetchedAt: now, dataTimestamp: last.timestamp, dataAgeMs, stale,
    fallbackChain: synthetic && REAL.has(symbol) ? ['binance', 'frankfurter-ecb'] : [] };
  const reason = synthetic ? 'SYNTHETIC' : (stale ? 'STALE' : (delayed ? 'DELAYED' : 'LIVE'));

  return { symbol, marketClass: /USDT$/.test(symbol) ? 'crypto' : (REAL.has(symbol) && !/^(ES|NQ|CL|GC)=F$/.test(symbol) && !['SPY','QQQ','IWM','DIA','VTI','GLD'].includes(symbol) ? 'forex' : 'stock'),
    timeframe, candles, provenance: prov, validation: { ok: true, dropped: 0 },
    quote: { quote: { bid: +(last.close * 0.9999).toFixed(6), ask: +(last.close * 1.0001).toFixed(6),
      last: +last.close.toFixed(6), timestamp: now }, source, synthetic },
    live: { live: reason === 'LIVE', reason, source, synthetic, stale, delayed,
      dataAgeMs, dataTimestamp: last.timestamp, fetchedAt: now, fallbackChain: prov.fallbackChain },
    // Mirrors Api_marketdata::refreshSeconds(): 25% of the bar period, clamped.
    refreshSeconds: Math.max(15, Math.min(120, Math.round((tf / 1000) * 0.25))),
    serverTime: now };
}

// Toggleable "connection" state so the CONNECTED / NOT ENABLED / LIVE states
// from ApiProviders::serviceState() can be seen.
const conn = { crypto_market: 'live', forex_market: 'live' };
const SERVICES = { crypto_market: 'Crypto Market Data', forex_market: 'Forex Market Data' };

// ---- page -------------------------------------------------------------------
function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

function chip(service, state) {
  const label = SERVICES[service];
  const badge = state === 'live' ? '<span class="badge b-green">LIVE</span>'
    : state === 'connected' ? '<span class="badge b-amber">CONNECTED · NOT ENABLED</span>'
    : '<span class="badge b-gray">NOT CONNECTED</span>';
  const driver = state === 'off' ? '' : `<span class="dim mono" style="font-size:10px">${service === 'crypto_market' ? 'binance_public' : 'frankfurter'}</span>`;
  const next = state === 'live' ? 'connected' : (state === 'connected' ? 'off' : 'live');
  return `<span class="livemarket-chip"><span class="livemarket-label">${esc(label)}</span>${badge}${driver}` +
    `<form method="post" action="/preview/state" style="display:inline">` +
    `<input type="hidden" name="service" value="${service}"><input type="hidden" name="state" value="${next}">` +
    `<button class="btn small ghost" type="submit">cycle</button></form></span>`;
}

function page(symbol, timeframe, mode) {
  const overlays = JSON.stringify({ support: [], resistance: [], setup: null });
  return `<!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Live market-data chart — UI preview</title>
<link rel="stylesheet" href="/assets/css/ai_workforce.css">
<style>
  body { background: var(--bg); color: var(--text); margin: 0; padding: 24px; font-family: inherit; }
  .preview-shell { max-width: 1180px; margin: 0 auto; }
  .preview-banner { border: 1px solid #f5a62366; background: #f5a62314; color: #ffd79a;
    padding: 12px 14px; border-radius: var(--radius-sm); font-size: 12.5px; margin-bottom: 16px; }
  .preview-banner b { color: #ffbf5e; }
  .preview-banner code { font-family: ui-monospace, Menlo, Consolas, monospace; }
  .preview-form { display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; margin-bottom:14px; }
</style></head>
<body><div class="preview-shell">

  <div class="preview-banner">
    <b>UI PREVIEW — SIMULATED DATA.</b> This sandbox has no PHP binary and no network egress to
    Binance / Frankfurter / Yahoo, so the real CodeIgniter app cannot run here. This page serves the
    <b>unmodified production assets</b> (<code>assets/js/market-chart.js</code>,
    <code>assets/css/ai_workforce.css</code>) against a simulated
    <code>/api/market-data/live</code>. Every price shown is generated locally.
    Run the real app on your host to see genuine bars.
  </div>

  <div class="livemarket-state">
    ${chip('crypto_market', conn.crypto_market)}
    ${chip('forex_market', conn.forex_market)}
    <span class="livemarket-hint dim">Cycle a service to see the three states <code>ApiProviders::serviceState()</code> reports.</span>
  </div>

  <form class="preview-form" method="get" action="/">
    <label class="fld"><span>Symbol</span>
      <select class="sel" name="symbol">
        ${Object.keys(BASE).map(s => `<option value="${s}"${s === symbol ? ' selected' : ''}>${s}</option>`).join('')}
        <option value="XAUUSD"${symbol === 'XAUUSD' ? ' selected' : ''}>XAUUSD (no real provider)</option>
      </select>
    </label>
    <label class="fld"><span>Timeframe</span>
      <select class="sel" name="timeframe">
        ${Object.keys(TF_MS).map(t => `<option value="${t}"${t === timeframe ? ' selected' : ''}>${t}</option>`).join('')}
      </select>
    </label>
    <label class="fld"><span>Feed mode</span>
      <select class="sel" name="mode">
        <option value="real"${mode === 'real' ? ' selected' : ''}>real provider reachable</option>
        <option value="sim"${mode === 'sim' ? ' selected' : ''}>forced simulation</option>
        <option value="stale"${mode === 'stale' ? ' selected' : ''}>stale feed</option>
      </select>
    </label>
    <button class="btn primary" type="submit">Load</button>
  </form>

  <div class="panel" style="margin-bottom:12px">
    <h3>${esc(symbol)} · ${esc(timeframe)} — candles · EMA</h3>
    <div class="body scroll" style="padding-top:12px">
      <div class="livechart" data-live-chart
           data-symbol="${esc(symbol)}" data-timeframe="${esc(timeframe)}" data-market-class=""
           data-limit="200" data-autostart="1" data-controls="1"
           data-overlays="${esc(overlays)}"></div>
    </div>
  </div>

</div>
<script src="/assets/js/market-chart.js" defer></script>
</body></html>`;
}

// ---- server -----------------------------------------------------------------
const server = http.createServer((req, res) => {
  const u = new URL(req.url, 'http://localhost');

  if (req.method === 'POST' && u.pathname === '/preview/state') {
    let body = '';
    req.on('data', (c) => { body += c; });
    req.on('end', () => {
      const p = new URLSearchParams(body);
      const svc = p.get('service');
      if (SERVICES[svc]) conn[svc] = p.get('state') || 'live';
      res.writeHead(303, { Location: '/' });
      res.end();
    });
    return;
  }

  if (u.pathname === '/api/market-data/live') {
    const symbol = (u.searchParams.get('symbol') || 'BTCUSDT').toUpperCase();
    const timeframe = u.searchParams.get('timeframe') || '1h';
    const limit = Math.max(30, Math.min(1000, parseInt(u.searchParams.get('limit') || '200', 10)));
    const mode = u.searchParams.get('mode') || GLOBAL_MODE;
    const payload = series(symbol, timeframe, limit, mode);
    res.writeHead(200, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' });
    res.end(JSON.stringify(payload));
    return;
  }

  if (u.pathname === '/api/market-data/refresh') {
    res.writeHead(200, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' });
    res.end(JSON.stringify({ ok: true, refreshed: true, realProvidersAllowed: true,
      registered: ['binance', 'frankfurter-ecb', 'stock-licensed', 'yahoo-chart-stock', 'synthetic-demo'],
      syntheticOnly: false,
      services: {
        crypto_market: { service: 'crypto_market', label: SERVICES.crypto_market, configured: conn.crypto_market !== 'off', live: conn.crypto_market === 'live', driver: conn.crypto_market === 'live' ? 'binance_public' : null, rows: conn.crypto_market === 'off' ? 0 : 1, enabled_rows: conn.crypto_market === 'live' ? 1 : 0 },
        forex_market: { service: 'forex_market', label: SERVICES.forex_market, configured: conn.forex_market !== 'off', live: conn.forex_market === 'live', driver: conn.forex_market === 'live' ? 'frankfurter' : null, rows: conn.forex_market === 'off' ? 0 : 1, enabled_rows: conn.forex_market === 'live' ? 1 : 0 },
      },
      providerHealth: [{ name: 'binance', status: conn.crypto_market === 'live' ? 'UP' : 'DOWN', latencyMs: 42, detail: 'SIMULATED for this preview' }],
      marketDataLive: conn.crypto_market === 'live' || conn.forex_market === 'live',
      checkedAt: Date.now() }));
    return;
  }

  // Serve the real, unmodified production assets straight off disk.
  if (u.pathname.startsWith('/assets/')) {
    const file = path.join(ROOT, u.pathname.replace(/^\/+/, ''));
    if (!file.startsWith(path.join(ROOT, 'assets')) || !fs.existsSync(file)) {
      res.writeHead(404); res.end('not found'); return;
    }
    const type = file.endsWith('.js') ? 'application/javascript' : (file.endsWith('.css') ? 'text/css' : 'application/octet-stream');
    res.writeHead(200, { 'Content-Type': type, 'Cache-Control': 'no-store' });
    fs.createReadStream(file).pipe(res);
    return;
  }

  if (u.pathname === '/') {
    GLOBAL_MODE = u.searchParams.get('mode') || 'real';
    const html = page((u.searchParams.get('symbol') || 'BTCUSDT').toUpperCase(),
      u.searchParams.get('timeframe') || '1h', GLOBAL_MODE);
    res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' });
    res.end(html);
    return;
  }

  res.writeHead(404, { 'Content-Type': 'text/plain' });
  res.end('not found');
});

let GLOBAL_MODE = 'real';
server.listen(PORT, HOST, () => {
  console.log(`[preview] live market-data chart UI on http://${HOST}:${PORT}`);
  console.log('[preview] serving unmodified assets/js/market-chart.js + assets/css/ai_workforce.css');
  console.log('[preview] market data is SIMULATED — no PHP runtime and no egress in this sandbox');
});
