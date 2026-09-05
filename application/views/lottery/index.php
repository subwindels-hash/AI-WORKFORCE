<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<div class="page-head">
  <div>
    <h2>EuroMillions · Lottery Intelligence</h2>
    <p>Frequency, gap and hot/cold analysis, per-line AI combination reports, a 5-mode generator with lock/exclude, system (wheel) builder, saved tickets, and a Strategy Lab that always compares against a mandatory same-period random baseline.</p>
  </div>
  <?php if (!empty($canManage)): ?>
    <a class="btn" href="/admin/api/create?service=lottery">Official source & admin controls →</a>
  <?php endif; ?>
</div>

<?php if (!empty($notice)): ?><div class="notice ok"><?= e($notice) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="notice err"><?= e($error) ?></div><?php endif; ?>

<div id="lottery-app" class="lottery-shell">
  <div class="notice warnbox">Loading lottery modules… (status, draws, tickets, backtests, generator, system builder).</div>
</div>

<script>
window.__AI_LOTTERY_STATE__ = <?= $stateJson ?>;
</script>
<script src="/assets/js/lottery.js" defer></script>

<noscript>
  <div class="panel" style="margin-top:14px">
    <h3>JavaScript required</h3>
    <div class="body">
      <p class="dim">The EuroMillions dashboard uses an interactive JS client backed by the
         <span class="mono">/api/lottery/*</span> endpoints. All write actions are CSRF-protected and
         RBAC-gated on the server. Enable JavaScript to view draws, statistics and AI-generated lines.</p>
    </div>
  </div>
</noscript>

<style>
.lottery-shell { margin-top: 14px; }
.lottery-grid { display: grid; gap: 14px; grid-template-columns: repeat(auto-fit, minmax(320px,1fr)); }
.lottery-card { border: 1px solid var(--line); border-radius: var(--radius); background: var(--panel); padding: 14px; }
.lottery-card h3 { margin: 0 0 8px; font-size: 15px; letter-spacing: 0.02em; text-transform: uppercase; color: var(--muted); }
.lottery-jackpot { font-size: 22px; font-weight: 700; color: var(--brand); }
.ball, .lucky-star { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 50%; font-weight: 700; font-size: 13px; margin: 0 4px 4px 0; }
.ball { background: #ffd24a; color: #1b1b1b; }
.lucky-star { background: #7dd3fc; color: #0c2e46; }
.lottery-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px; }
.lottery-line { display: flex; align-items: center; gap: 2px; margin: 4px 0; }
.lottery-meta { color: var(--muted); font-size: 12px; }
.tab-row { display: flex; gap: 6px; margin: 14px 0; flex-wrap: wrap; border-bottom: 1px solid var(--line); padding-bottom: 8px; }
.tab-row button { background: transparent; border: 1px solid transparent; padding: 6px 10px; border-radius: var(--radius-sm); color: var(--muted); cursor: pointer; font: inherit; }
.tab-row button.active { background: var(--panel2); color: var(--text); border-color: var(--line); }
.tab-row button:hover { color: var(--text); }
</style>

<script>
/* Lightweight progressive-enhancement client: renders the hydrated state,
   switches tabs, and fetches fresh data on demand. If assets/lottery.js is
   present it will upgrade this with richer widgets; the markup here is a
   functional non-JS-free fallback once hydrated. */
(function () {
  const state = window.__AI_LOTTERY_STATE__ || {};
  const root = document.getElementById('lottery-app');
  if (!root || !state.status) return;
  root.innerHTML = '';

  const s = state.status;
  const dataUnavailable = s.status === 'DATA UNAVAILABLE';
  const statusBadge = s.status === 'ONLINE' || s.status === 'OK'
    ? '<span class="badge b-green">'+e(s.status)+'</span>'
    : (dataUnavailable
        ? '<span class="badge b-red">DATA UNAVAILABLE</span>'
        : '<span class="badge b-amber">'+e(s.status)+'</span>');

  const syncBadgeClass = { OK: 'b-green', DEGRADED: 'b-amber', STALE: 'b-amber', FAILED: 'b-red', NEVER_SYNCED: 'b-amber' }[s.syncStatus] || 'b-amber';
  const verified = (s.verifiedDraws != null ? s.verifiedDraws : (s.imported || 0));
  const ds = s.historicalDataset || {};
  const js = s.jackpotSource || {};
  const jackpotOrigin = js.origin === 'PROVIDER_FEED'
    ? 'live Windels API response' + (js.observedAt ? ' (observed ' + e(js.observedAt) + ')' : '')
    : (js.origin === 'STORED_DRAW' ? 'stored verified draw' : 'no feed value — nothing displayed');

  const lastDraw = s.lastDraw;
  let lastDrawHtml = '<p class="dim">No verified draw imported yet. Connect an official EuroMillions source (e.g. Windels API) in Admin → API.</p>';
  if (lastDraw && lastDraw.numbers) {
    const mains = (lastDraw.numbers.main || []).map(n => '<span class="ball">'+n+'</span>').join('');
    const stars = (lastDraw.numbers.stars || []).map(n => '<span class="lucky-star">'+n+'</span>').join('');
    lastDrawHtml = '<div class="lottery-line">'+mains+stars+'</div>'
      + '<div class="lottery-meta">'+e(lastDraw.draw_date||'')+' · draw #'+e(lastDraw.draw_no||'')+'</div>';
  }

  root.innerHTML = `
    <div class="lottery-grid">
      <div class="lottery-card">
        <h3>Next draw ${statusBadge}</h3>
        <div class="lottery-jackpot">${e(s.jackpot ? '€' + Number(s.jackpot).toLocaleString('en-GB') : '—')}</div>
        <div class="lottery-meta">jackpot source: ${jackpotOrigin}</div>
        <div class="lottery-meta">provider: ${e((s.provider && (s.provider.source || s.provider.id)) || s.providerLabel || 'none')} · imported ${e(s.imported||s.drawsTracked||0)} verified draws</div>
        <div class="lottery-meta">${e((s.provider && s.provider.message) || '')}</div>
        <div class="lottery-actions">
          <a class="btn primary" href="/api/lottery/generate" data-lottery-generate>Generate 5 AI lines</a>
          <a class="btn" href="/lottery/tickets">My tickets</a>
          <a class="btn" href="/lottery/backtests">Strategy Lab</a>
        </div>
      </div>
      <div class="lottery-card">
        <h3>Last verified draw</h3>
        ${lastDrawHtml}
      </div>
      <div class="lottery-card" data-panel="sync-status">
        <h3>Historical data sync <span class="badge ${syncBadgeClass}">${e(s.syncStatus || 'UNKNOWN')}</span></h3>
        <div class="lottery-meta">verified draws in database: <strong data-verified-draws>${e(verified)}</strong></div>
        <div class="lottery-meta">last successful sync: <strong>${e(s.lastSuccessfulSync || 'never')}</strong></div>
        <div class="lottery-meta">last sync attempt: ${e(s.lastSyncAttempt || 'never')}</div>
        <div class="lottery-meta">dataset: ${ds.available ? e(ds.draws) + ' draws (' + e(ds.from) + ' → ' + e(ds.to) + ')' : 'DATA UNAVAILABLE — no verified historical draws stored'}</div>
        <div class="lottery-meta">${e(s.syncMessage || '')}</div>
      </div>
      <div class="lottery-card">
        <h3>Quick links</h3>
        <ul style="margin:0;padding-left:18px;line-height:1.8">
          <li><a href="/api/lottery/statistics?kind=frequency&window=1y">Frequency / hot-cold (1y)</a></li>
          <li><a href="/api/lottery/statistics?kind=gap&window=1y">Gap statistics</a></li>
          <li><a href="/api/lottery/statistics?kind=distribution&window=2y">Number distribution</a></li>
          <li><a href="/api/lottery/system">System (wheel) builder</a></li>
          <li><a href="/api/lottery/backtests">Backtests — random baseline required</a></li>
        </ul>
      </div>
    </div>

    <div class="tab-row">
      <button class="active" data-tab="draws">Recent draws</button>
      <button data-tab="combinations">Recent AI combinations</button>
      <button data-tab="tickets">My tickets (${(state.myTickets||[]).length})</button>
      <button data-tab="backtests">Backtests</button>
    </div>

    <div class="lottery-card" data-panel="draws">${renderDraws(state.draws)}</div>
    <div class="lottery-card" data-panel="combinations" style="display:none">${renderCombinations(state.recentCombinations)}</div>
    <div class="lottery-card" data-panel="tickets" style="display:none">${renderTickets(state.myTickets)}</div>
    <div class="lottery-card" data-panel="backtests" style="display:none">${renderBacktests(state.backtests)}</div>
  `;

  root.querySelectorAll('.tab-row button').forEach(b => {
    b.addEventListener('click', () => {
      root.querySelectorAll('.tab-row button').forEach(x => x.classList.remove('active'));
      b.classList.add('active');
      root.querySelectorAll('[data-panel]').forEach(p => p.style.display = (p.dataset.panel === b.dataset.tab) ? '' : 'none');
    });
  });

  function renderDraws(draws) {
    if (!draws || !draws.length) return '<p class="dim">No draws loaded yet.</p>';
    return '<table class="tbl mono"><thead><tr><th>Date</th><th>#</th><th>Numbers</th></tr></thead><tbody>' +
      draws.slice(0,10).map(d => {
        const mains = (d.main_numbers||d.numbers&&d.numbers.main||[]).slice().sort((a,b)=>a-b).map(n=>'<span class="ball">'+n+'</span>').join('');
        const stars = (d.lucky_stars||d.stars||(d.numbers&&d.numbers.stars)||[]).slice().sort((a,b)=>a-b).map(n=>'<span class="lucky-star">'+n+'</span>').join('');
        return '<tr><td>'+e(d.draw_date||d.draw_at||'')+'</td><td>'+e(d.draw_no||'')+'</td><td>'+mains+stars+'</td></tr>';
      }).join('') + '</tbody></table>';
  }
  function renderCombinations(list) {
    if (!list || !list.length) return '<p class="dim">No AI combinations saved yet. Generate your first set from the Action card.</p>';
    return '<table class="tbl mono"><thead><tr><th>When</th><th>Mode</th><th>Line</th></tr></thead><tbody>' +
      list.map(c => '<tr><td>'+e(c.created_at||'')+'</td><td>'+e(c.mode||c.generator_mode||'')+'</td><td>'+renderLine(c)+'</td></tr>').join('') +
      '</tbody></table>';
  }
  function renderTickets(list) {
    if (!list || !list.length) return '<p class="dim">No saved tickets yet. Build and save a ticket from the generator.</p>';
    return '<table class="tbl mono"><thead><tr><th>Label</th><th>Draw</th><th>Lines</th><th>Status</th></tr></thead><tbody>' +
      list.map(t => '<tr><td>'+e(t.label||t.name||'Ticket #'+t.id)+'</td><td>'+e(t.target_draw_date||'')+'</td><td>'+(t.line_count||'?')+'</td><td>'+e(t.status||'')+'</td></tr>').join('') +
      '</tbody></table>';
  }
  function renderBacktests(list) {
    if (!list || !list.length) return '<p class="dim">No backtests yet. Run one from Strategy Lab — a random baseline is always included for honest comparison.</p>';
    return '<table class="tbl mono"><thead><tr><th>Model</th><th>Window</th><th>Win rate</th><th>vs random</th></tr></thead><tbody>' +
      list.map(b => '<tr><td>'+e(b.model_version||b.model||'')+'</td><td>'+e(b.window||'')+'</td><td>'+e(typeof b.hit_rate==='number'?b.hit_rate.toFixed(2)+'%':(b.win_rate||'-'))+'</td><td>'+e(b.vs_random||b.baseline_delta||'—')+'</td></tr>').join('') +
      '</tbody></table>';
  }
  function renderLine(c) {
    const mains = (c.main_numbers||c.mains||[]).slice().sort((a,b)=>a-b).map(n=>'<span class="ball">'+n+'</span>').join('');
    const stars = (c.lucky_stars||c.stars||[]).slice().sort((a,b)=>a-b).map(n=>'<span class="lucky-star">'+n+'</span>').join('');
    return mains + stars;
  }
  function e(s) { return String(s==null?'':s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }
})();
</script>
