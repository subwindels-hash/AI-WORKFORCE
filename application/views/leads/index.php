<?php
defined('BASEPATH') or exit('No direct script access allowed');
/**
 * Lead Discovery workspace (Scout).
 *
 * Supports two discovery modes:
 *  - Business Mode: keywords (banking, commercial real estate, architecture...)
 *    combined with country + city targeting.
 *  - Person Mode: first-name list (Mark, David, John, Emma...) combined with
 *    country + city. Results are filtered to free webmail domains (gmail,
 *    yahoo, outlook, icloud, hotmail, aol, proton, live, me, mail, gmx, yandex).
 * Every lead carries an honest verification_status (verified / partial_verified
 * / provider_enriched / business_listing) — we never claim 100% verification.
 *
 * Cold outreach (email draft or send) is sent in-platform via POST /leads/{id}/outreach.
 * If an SMTP/Resend/Postmark transport is configured, the email is delivered
 * instantly; otherwise it's stored as a draft for manual follow-up.
 */
if (!function_exists('e')) {
    function e($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
}
$isPipeline = !empty($pipeline);
$pageTitle = $isPipeline ? 'Lead Pipeline' : 'Lead Discovery';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($pageTitle) ?> · WINDELS AI WORKFORCE</title>
<meta name="robots" content="noindex,nofollow">
<link rel="icon" type="image/png" href="/assets/images/windels-mark.png">
<style>
  :root{
    --bg:#0a0e17; --panel:#0f1623; --panel2:#131c2c; --line:#1e2738; --line2:#2a3548;
    --text:#e7ecf4; --muted:#9aa7bd; --dim:#5e6b82; --brand:#2f6bff; --brand-soft:#2f6bff22;
    --red:#fb5d6b; --green:#22c55e; --amber:#f59e0b; --purple:#a855f7;
  }
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--text);font:14px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,Inter,sans-serif}
  a{color:var(--brand);text-decoration:none}
  a:hover{text-decoration:underline}

  header{display:flex;flex-wrap:wrap;align-items:center;gap:14px;padding:12px 22px;border-bottom:1px solid var(--line);background:#0a0f1a;position:sticky;top:0;z-index:10}
  .brand{display:flex;align-items:center;gap:10px;color:#fff;font-weight:800;font-size:14px;letter-spacing:.04em}
  .brand img{width:28px;height:28px;border-radius:8px;object-fit:cover}
  .brand small{display:block;font-weight:600;font-size:9px;letter-spacing:.18em;text-transform:uppercase;color:var(--dim)}
  nav{display:flex;gap:4px;flex-wrap:wrap}
  nav a{padding:7px 12px;border-radius:8px;color:var(--muted);font-weight:600;font-size:13px}
  nav a:hover{background:var(--panel2);color:#fff;text-decoration:none}
  nav a.active{background:var(--brand-soft);color:#fff}
  .who{margin-left:auto;display:flex;align-items:center;gap:10px;color:var(--dim);font-size:12px}
  .who .chip{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--line2);background:var(--panel2);border-radius:999px;padding:4px 12px;color:var(--muted);font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.06em}

  .wrap{max-width:1280px;margin:24px auto;padding:0 22px}
  h1{font-size:20px;margin:0 0 4px;color:#fff}
  .sub{color:var(--muted);font-size:13px;margin:0 0 16px}

  .card{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:18px;margin-top:16px}
  .card h2{margin:0 0 10px;font-size:13px;color:#fff;letter-spacing:.04em}
  .search{display:flex;flex-wrap:wrap;gap:10px;background:var(--panel);padding:14px;border:1px solid var(--line);border-radius:12px}
  .search input,.search select,.search textarea{flex:1;min-width:220px}
  input,select,textarea{border-radius:8px;border:1px solid var(--line2);padding:10px 12px;background:#0b1119;color:var(--text);font:inherit;font-size:13.5px}
  textarea{min-height:72px;resize:vertical}
  input:focus,select:focus,textarea:focus{outline:none;border-color:var(--brand);box-shadow:0 0 0 3px var(--brand-soft)}
  button{border-radius:8px;border:1px solid var(--brand);background:var(--brand);color:#fff;font:inherit;font-size:12.5px;font-weight:700;padding:10px 14px;cursor:pointer}
  button:hover{background:#1f5ae0;border-color:#1f5ae0}
  button.ghost{background:var(--panel2);border-color:var(--line2);color:var(--text)}
  button.ghost:hover{border-color:var(--brand);color:#fff;background:var(--panel2)}
  button.danger{background:var(--red);border-color:var(--red)}
  .muted{color:var(--muted);font-size:12.5px}
  .error{color:var(--red);font-size:12.5px;min-height:1.2em;margin:8px 0 0}
  .row{display:flex;gap:10px;flex-wrap:wrap;width:100%}
  .row > *{flex:1;min-width:150px}
  .mode-tabs{display:flex;gap:8px;width:100%;margin-bottom:4px}
  .mode-btn{flex:0 0 auto;background:var(--panel2);border:1px solid var(--line2);color:var(--muted);padding:8px 14px;border-radius:999px}
  .mode-btn.active{background:var(--brand-soft);border-color:var(--brand);color:#fff}
  .pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:10.5px;font-weight:700;letter-spacing:.04em;text-transform:uppercase}
  .pill.verified{background:#22c55e22;color:#86efac;border:1px solid #22c55e55}
  .pill.partial{background:#f59e0b22;color:#fcd34d;border:1px solid #f59e0b55}
  .pill.enriched{background:#2f6bff22;color:#93c5fd;border:1px solid #2f6bff55}
  .pill.listing{background:#a855f722;color:#d8b4fe;border:1px solid #a855f755}
  .pill.person{background:#ec489922;color:#f9a8d4;border:1px solid #ec489955}
  .pill.business{background:#64748b22;color:#cbd5e1;border:1px solid #64748b55}

  #loginBox{max-width:440px;margin:9vh auto}
  #loginBox form{display:grid;gap:12px;margin-top:14px}
  #loginBox .actions{display:flex;align-items:center;gap:12px}
  .spin{display:inline-block;width:12px;height:12px;border:2px solid #ffffff59;border-top-color:#fff;border-radius:50%;animation:ldspin .7s linear infinite;vertical-align:-2px}
  @keyframes ldspin{to{transform:rotate(360deg)}}

  .stats{display:flex;gap:12px;flex-wrap:wrap;margin-top:16px}
  .stat{padding:14px 16px;background:var(--panel);border:1px solid var(--line);border-radius:10px;min-width:140px}
  .stat b{font-size:21px;display:block;color:#fff;margin-top:2px;font-variant-numeric:tabular-nums}
  table{width:100%;border-collapse:collapse;margin-top:12px;font-size:12.5px}
  th,td{text-align:left;border-bottom:1px solid var(--line);padding:10px;vertical-align:top}
  th{color:var(--dim);font-weight:700;font-size:10px;text-transform:uppercase;letter-spacing:.08em}
  tbody tr:hover{background:#0e1622}
  td select{padding:6px 8px;font-size:12px}
  .hidden{display:none !important}
  .board{display:grid;grid-template-columns:repeat(5,minmax(210px,1fr));gap:12px;overflow:auto;padding-bottom:6px}
  .column{background:var(--panel2);border:1px solid var(--line);border-radius:10px;padding:10px;min-height:280px}
  .column h3{margin:2px 0 8px;text-transform:uppercase;font-size:11px;color:var(--muted);letter-spacing:.08em}
  .leadCard{background:var(--panel);border:1px solid var(--line);border-radius:8px;padding:11px;margin:8px 0}
  .leadCard b{color:#fff;font-size:12.5px}
  .leadCard p{margin:5px 0;color:var(--muted);font-size:12px}
  .leadCard button{font-size:11px;padding:6px 10px;margin:6px 4px 0 0}
  pre{background:#05070b;border:1px solid var(--line);border-radius:8px;padding:10px;font-size:11px;overflow:auto;max-height:340px;color:#94a3b8}

  /* Modal */
  .modal-backdrop{position:fixed;inset:0;background:#0008;display:flex;align-items:center;justify-content:center;z-index:50;padding:20px}
  .modal{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:18px;max-width:560px;width:100%;max-height:90vh;overflow:auto}
  .modal h3{margin:0 0 10px;color:#fff;font-size:15px}
  .modal .row{margin-top:8px}
  .modal label{font-size:12px;color:var(--muted);display:block;margin:8px 0 4px}
  .modal .actions{display:flex;gap:8px;justify-content:flex-end;margin-top:14px}
  .domainChips{display:flex;flex-wrap:wrap;gap:6px;margin-top:6px}
  .domainChips label{margin:0;padding:6px 10px;border-radius:999px;background:var(--panel2);border:1px solid var(--line2);font-size:12px;color:var(--muted);cursor:pointer}
  .domainChips input{display:none}
  .domainChips input:checked + span{color:#fff}
  .domainChips label:has(input:checked){border-color:var(--brand);background:var(--brand-soft);color:#fff}
</style>
</head>
<body>
<header>
  <a class="brand" href="/dashboard">
    <img src="/assets/images/windels-mark.png" alt="WINDELS AI WORKFORCE" onerror="this.onerror=null;this.src='/assets/images/ai_workforce-mark.png'">
    <span>WINDELS<small>AI Workforce</small></span>
  </a>
  <nav aria-label="Lead workspace">
    <a href="/dashboard">← Dashboard</a>
    <a href="/leads" class="<?= $isPipeline ? '' : 'active' ?>">Discovery</a>
    <a href="/lead-pipeline" class="<?= $isPipeline ? 'active' : '' ?>">Pipeline</a>
  </nav>
  <div class="who">
    <span class="chip">Secure workspace</span>
    <span id="who">checking session…</span>
    <button type="button" class="ghost" id="signOutBtn" hidden>Sign out</button>
  </div>
</header>

<main class="wrap">
  <h1><?= e($pageTitle) ?></h1>
  <p class="sub"><?= $isPipeline ? 'Move stored leads through stages, assign an owner and review live counts.' : 'Search businesses or people, filter by country & city, and launch cold outreach from inside the platform.' ?></p>

  <section id="loginBox" class="card hidden">
    <h2>Sign in to your workspace</h2>
    <p class="muted">Lead data is isolated to your authenticated workspace. Use the same email and password as your platform account.</p>
    <form id="loginForm" novalidate>
      <input id="email" placeholder="Email address" type="email" autocomplete="username" required>
      <input id="password" placeholder="Password" type="password" autocomplete="current-password" required>
      <div class="actions">
        <button type="submit" id="loginBtn">Sign in</button>
        <a href="/login">Platform sign-in page →</a>
      </div>
    </form>
    <p id="loginErr" class="error" role="alert"></p>
  </section>

  <section id="app" class="hidden">
    <?php if (!$isPipeline): ?>
    <div class="card">
      <h2>Search</h2>
      <div class="mode-tabs">
        <button type="button" class="mode-btn active" data-mode="business" id="modeBusiness">🏢 Business Mode</button>
        <button type="button" class="mode-btn" data-mode="person" id="modePerson">👤 Person Mode</button>
      </div>

      <div class="search" id="businessFields" style="margin-top:10px">
        <input id="keywords" placeholder="Keywords (comma-separated): Banking, Commercial Real Estate, Architecture" style="min-width:320px;flex:2">
        <input id="country" placeholder="Country (e.g. Nigeria)">
        <input id="city" placeholder="City (e.g. Lagos)">
        <select id="provider">
          <option value="google_places">Google Places</option>
          <option value="apollo_io">Apollo.io (B2B contacts, emails, phones)</option>
        </select>
        <button type="button" id="searchBtn">Search businesses</button>
      </div>

      <div class="search hidden" id="personFields" style="margin-top:10px">
        <textarea id="names" placeholder="First names (comma/newline separated): Mark, David, John, Emma" style="flex:2;min-width:280px"></textarea>
        <input id="pCountry" placeholder="Country (e.g. Nigeria)">
        <input id="pCity" placeholder="City (e.g. Lagos)">
        <select id="seniorities" aria-label="Seniority (optional)">
          <option value="">Any seniority</option>
          <option value="owner">Owner</option>
          <option value="founder">Founder</option>
          <option value="c_suite">C-Suite</option>
          <option value="partner">Partner</option>
          <option value="vp">VP</option>
          <option value="director">Director</option>
          <option value="manager">Manager</option>
        </select>
        <div style="flex-basis:100%">
          <label class="muted" style="font-size:12px">Free email domains to include (personal inboxes only):</label>
          <div class="domainChips" id="freeDomains">
            <?php foreach (['icloud.com','gmail.com','yahoo.com','outlook.com','hotmail.com','aol.com','proton.me','live.com','me.com'] as $i=>$d): ?>
              <label><input type="checkbox" value="<?= e($d) ?>" checked><span>@<?= e($d) ?></span></label>
            <?php endforeach; ?>
          </div>
        </div>
        <button type="button" id="searchPersonBtn" style="margin-top:6px">Search people</button>
      </div>
      <p id="message" class="muted" style="margin:10px 2px 0">Enter keywords or names and a location to start. Apollo.io is required for Person Mode (it provides emails/phones).</p>
    </div>
    <?php endif; ?>

    <div class="stats">
      <div class="stat"><span class="muted">Stored leads</span><b id="count">—</b></div>
      <div class="stat"><span class="muted">Phone coverage</span><b id="phone">—</b></div>
      <div class="stat"><span class="muted">Website coverage</span><b id="site">—</b></div>
      <div class="stat"><span class="muted">Persons</span><b id="persons">—</b></div>
      <div class="stat"><span class="muted">Verified contacts</span><b id="verified">—</b></div>
    </div>

    <div class="card">
      <h2>Collections</h2>
      <div class="search" style="padding:0;border:0;background:transparent">
        <input id="collectionName" placeholder="New collection name">
        <button type="button" id="createCollectionBtn">Create</button>
        <select id="collectionSelect" aria-label="Collection"></select>
        <button type="button" class="ghost" id="addSelectedBtn">Add selected leads</button>
        <button type="button" class="ghost" id="exportBtn">Export CSV</button>
      </div>
      <p id="collectionInfo" class="muted">Create a collection, select lead rows, then add them in bulk.</p>
    </div>

    <div class="card" id="resultsCard">
      <h2>Lead results</h2>
      <div id="empty" class="muted">Your saved leads will appear here.</div>
      <table id="table" class="hidden">
        <thead>
          <tr>
            <th><input type="checkbox" id="selectAllBox" aria-label="Select all"></th>
            <th>Lead</th><th>Kind</th><th>Category / Title</th><th>Location</th><th>Contact</th><th>Verification</th><th>Pipeline</th><th>Actions</th>
          </tr>
        </thead>
        <tbody id="rows"></tbody>
      </table>
    </div>

    <div id="pipelineWorkspace" class="card hidden">
      <h2>Pipeline board</h2>
      <p class="muted">Move leads between stages, assign the current owner, and review live stage counts.</p>
      <div id="board" class="board"></div>
    </div>

    <div id="intelligenceWorkspace" class="card hidden">
      <h2>Data quality &amp; discovery ledger</h2>
      <div class="search" style="padding:0;border:0;background:transparent">
        <select id="missingField" aria-label="Missing field">
          <option value="phone">Missing phone</option>
          <option value="website">Missing website</option>
          <option value="address">Missing address</option>
          <option value="category">Missing category</option>
        </select>
        <button type="button" class="ghost" id="missingBtn">Review missing data</button>
        <button type="button" class="ghost" id="historyBtn">Search history</button>
        <button type="button" class="ghost" id="duplicatesBtn">Duplicate review</button>
        <button type="button" class="ghost" id="previewBtn">Export preview</button>
      </div>
      <div id="intelligenceOutput" class="muted" style="margin-top:12px">Choose a quality or ledger review action.</div>
    </div>
  </section>
</main>

<script>
(function () {
  'use strict';
  const isPipeline = <?= $isPipeline ? 'true' : 'false' ?>;
  const api = '/api/v1/lead-discovery';
  let currentUserId = null;
  let csrf = '';
  let currentMode = 'business';
  let allLeads = [];

  const $ = (id) => document.getElementById(id);
  const who = $('who'), loginBox = $('loginBox'), app = $('app'), messageEl = $('message');

  function escapeHtml(v) {
    const d = document.createElement('div');
    d.textContent = v == null ? '' : String(v);
    return d.innerHTML;
  }

  async function request(path, opt = {}) {
    const r = await fetch(api + path, {
      ...opt,
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, ...(opt.headers || {}) },
    });
    const type = r.headers.get('content-type') || '';
    const d = type.includes('json') ? await r.json() : await r.blob();
    if (!r.ok) throw new Error((d && d.error) ? d.error : 'Request failed');
    return d;
  }

  function enterWorkspace(user, csrfToken) {
    csrf = csrfToken || '';
    currentUserId = user.id;
    who.textContent = user.email;
    $('signOutBtn').hidden = false;
    loginBox.classList.add('hidden');
    app.classList.remove('hidden');
    load();
  }

  function showLogin() {
    who.textContent = 'not signed in';
    app.classList.add('hidden');
    $('signOutBtn').hidden = true;
    loginBox.classList.remove('hidden');
  }

  async function boot() {
    try {
      const r = await fetch('/api/auth/me', { credentials: 'same-origin' });
      const d = await r.json();
      if (!r.ok) { showLogin(); return; }
      enterWorkspace(d.user, d.csrfToken);
    } catch (e) { showLogin(); }
  }

  $('loginForm').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const btn = $('loginBtn'), err = $('loginErr');
    err.textContent = '';
    if (!$('email').value.trim() || !$('password').value) { err.textContent = 'Enter your email address and password.'; return; }
    btn.disabled = true; btn.innerHTML = '<span class="spin"></span> Signing in…';
    try {
      const r = await fetch('/api/auth/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email: $('email').value.trim(), password: $('password').value }) });
      const d = await r.json();
      if (!r.ok) throw new Error(d.error || 'Sign in failed');
      enterWorkspace(d.user, d.csrfToken);
    } catch (e) { err.textContent = e.message; }
    finally { btn.disabled = false; btn.textContent = 'Sign in'; }
  });

  $('signOutBtn').addEventListener('click', async () => {
    try { await fetch('/api/auth/logout', { method: 'POST', headers: { 'X-CSRF-Token': csrf } }); } catch (_) {}
    window.location.assign('/login');
  });

  function setMode(mode) {
    currentMode = mode;
    document.querySelectorAll('.mode-btn').forEach((b) => b.classList.toggle('active', b.dataset.mode === mode));
    $('businessFields').classList.toggle('hidden', mode !== 'business');
    $('personFields').classList.toggle('hidden', mode !== 'person');
    if (mode === 'person') {
      // Auto-select Apollo in business selector too (stays in sync).
      $('provider').value = 'apollo_io';
    }
  }
  if ($('modeBusiness')) $('modeBusiness').addEventListener('click', () => setMode('business'));
  if ($('modePerson')) $('modePerson').addEventListener('click', () => setMode('person'));

  function splitList(v) {
    return (v || '').split(/[,\n]+/).map((s) => s.trim()).filter(Boolean);
  }

  function runSearch(isPerson) {
    if (isPerson) {
      setMode('person');
      const names = splitList($('names').value);
      const country = $('pCountry').value.trim();
      const city = $('pCity').value.trim();
      const seniority = $('seniorities').value;
      const checkedDomains = [...document.querySelectorAll('#freeDomains input:checked')].map((i) => i.value);
      if (!names.length) { messageEl.textContent = 'Enter at least one first name.'; return; }
      if (!city && !country) { messageEl.textContent = 'Add a city/country to narrow person searches.'; return; }
      messageEl.textContent = 'Searching Apollo.io for people (filtered to free emails)…';
      $('searchPersonBtn').disabled = true;
      request('/search', { method: 'POST', body: JSON.stringify({ mode: 'person', provider: 'apollo_io', names, country, city, seniorities: seniority ? [seniority] : [], freeEmailDomains: checkedDomains }) })
        .then((d) => {
          messageEl.textContent = `${d.results.length} people with free emails · ${d.newLeadsCreated} new · ${d.duplicatesDetected} existing refreshed`;
          return load();
        }).catch((e) => { messageEl.textContent = e.message; })
        .finally(() => { $('searchPersonBtn').disabled = false; });
    } else {
      setMode('business');
      const keywords = splitList($('keywords').value);
      const country = $('country').value.trim();
      const city = $('city').value.trim();
      const provider = $('provider').value;
      if (!keywords.length && !city && !country) { messageEl.textContent = 'Enter at least one keyword (e.g. Banking, Commercial Real Estate) and a location.'; return; }
      messageEl.textContent = 'Searching…';
      $('searchBtn').disabled = true;
      request('/search', { method: 'POST', body: JSON.stringify({ mode: 'business', provider, keywords, country, city }) })
        .then((d) => {
          messageEl.textContent = `${d.results.length} live results · ${d.newLeadsCreated} new · ${d.duplicatesDetected} existing refreshed · provider ${d.providerStatus}`;
          return load();
        }).catch((e) => { messageEl.textContent = e.message; })
        .finally(() => { $('searchBtn').disabled = false; });
    }
  }
  if ($('searchBtn')) $('searchBtn').addEventListener('click', () => runSearch(false));
  if ($('searchPersonBtn')) $('searchPersonBtn').addEventListener('click', () => runSearch(true));

  async function load() {
    try {
      const d = await request('/leads');
      allLeads = d.leads;
      render(d.leads);
      const c = await request('/coverage');
      $('count').textContent = c.leadCount;
      $('phone').textContent = (c.fields.find((x) => x.field === 'Phone') || {}).coverage + '%';
      $('site').textContent = (c.fields.find((x) => x.field === 'Website') || {}).coverage + '%';
      const persons = d.leads.filter((l) => (l.lead_kind || 'business') === 'person').length;
      $('persons').textContent = persons;
      const verified = d.leads.filter((l) => (extractMeta(l).verification_status || '') === 'verified').length;
      $('verified').textContent = verified;
      const collections = await request('/collections');
      $('collectionSelect').innerHTML = collections.collections.length
        ? collections.collections.map((x) => `<option value="${x.id}">${escapeHtml(x.name)} (${x.leadCount})</option>`).join('')
        : '<option value="">No collections yet</option>';
      if (isPipeline) {
        $('pipelineWorkspace').classList.remove('hidden');
        $('intelligenceWorkspace').classList.remove('hidden');
        $('resultsCard').classList.add('hidden');
        await loadPipeline();
      }
    } catch (e) { messageEl.textContent = e.message; }
  }

  function extractMeta(x) {
    if (x && x.metadata && typeof x.metadata === 'object') return x.metadata;
    try { return JSON.parse(x && x.metadata) || {}; } catch (_) { return {}; }
  }

  function verificationPill(meta) {
    const s = meta.verification_status || 'enriched';
    if (s === 'verified') return '<span class="pill verified">✓ Verified</span>';
    if (s === 'partial_verified') return '<span class="pill partial">Partial</span>';
    if (s === 'business_listing') return '<span class="pill listing">Listing</span>';
    return '<span class="pill enriched">Enriched</span>';
  }

  function kindPill(lead) {
    const k = lead.lead_kind || 'business';
    return k === 'person' ? '<span class="pill person">Person</span>' : '<span class="pill business">Business</span>';
  }

  function render(leads) {
    $('empty').classList.toggle('hidden', leads.length);
    $('table').classList.toggle('hidden', !leads.length);
    $('rows').innerHTML = leads.map((x) => {
      const meta = extractMeta(x);
      const email = x.email || meta.email || '';
      const phone = x.phone || meta.phone || '';
      const website = x.website || '';
      const title = x.job_title || meta.title || x.category || '—';
      const company = x.company_name || meta.company || '';
      const linkedin = x.linkedin_url || meta.linkedin_url || '';
      const contactBits = [];
      if (phone) contactBits.push(`📞 ${escapeHtml(phone)}`);
      if (email) contactBits.push(`✉ <a href="mailto:${encodeURIComponent(email)}">${escapeHtml(email)}</a>`);
      if (website) contactBits.push(`<a href="${escapeHtml(website)}" target="_blank" rel="noopener">Website</a>`);
      if (linkedin) contactBits.push(`<a href="${escapeHtml(linkedin)}" target="_blank" rel="noopener">LinkedIn</a>`);
      return `<tr>
        <td><input class="leadSelect" type="checkbox" value="${x.id}"></td>
        <td><b>${escapeHtml(x.name)}</b>${company ? `<br><small class="muted">${escapeHtml(company)}</small>` : ''}</td>
        <td>${kindPill(x)}</td>
        <td>${escapeHtml(title)}</td>
        <td>${escapeHtml(x.address || [meta.city,meta.country].filter(Boolean).join(', ') || '—')}</td>
        <td>${contactBits.join('<br>') || '—'}</td>
        <td>${verificationPill(meta)}</td>
        <td><select onchange="window.scoutStatus('${x.id}', this.value)">
          ${['new','contacted','qualified','disqualified','converted'].map((s) => `<option ${x.status === s ? 'selected' : ''}>${s}</option>`).join('')}
        </select></td>
        <td>
          <button type="button" onclick="window.scoutOutreach('${x.id}')">Outreach</button>
        </td>
      </tr>`;
    }).join('');
  }

  window.scoutStatus = async function (id, v) {
    try {
      await request('/leads/' + id + '/status', { method: 'PATCH', body: JSON.stringify({ status: v }) });
      if (isPipeline) loadPipeline(); else load();
    } catch (e) { messageEl.textContent = e.message; load(); }
  };

  window.scoutOutreach = async function (id) {
    const lead = allLeads.find((l) => l.id === id);
    if (!lead) return;
    const meta = extractMeta(lead);
    const email = lead.email || meta.email || '';
    const name = lead.name;
    const company = lead.company_name || meta.company || '';
    const defaultSubject = 'Quick question' + (company ? ' about ' + company : '');
    const defaultBody = `Hi ${name.split(' ')[0]},\n\nI came across ${company || 'your profile'} and thought it might be worth a quick chat. Are you open to a 15-minute call this week?\n\nBest,\n—`;
    const channel = prompt('Channel? (email / linkedin / note / call)', 'email') || '';
    if (!channel) return;
    if (!['email','linkedin','note','call'].includes(channel)) { alert('Unsupported channel'); return; }
    let subject = '';
    if (channel === 'email') {
      if (!email) { alert('This lead has no email address on file.'); return; }
      subject = prompt('Subject', defaultSubject) || '';
      if (!subject) return;
    }
    const body = prompt('Message body:', defaultBody) || '';
    if (!body.trim()) return;
    try {
      const r = await request('/leads/' + id + '/outreach', { method: 'POST', body: JSON.stringify({ channel, subject, body }) });
      alert(r.delivered
        ? '✅ ' + channel + ' sent (' + (r.transport || 'stored') + '). Status flipped to contacted.'
        : '📝 Saved as draft/queued (' + (r.error || r.transport || 'no transport configured') + '). Lead marked as contacted.');
      if (isPipeline) loadPipeline(); else load();
    } catch (e) { alert('Outreach failed: ' + e.message); }
  };

  $('missingBtn').addEventListener('click', async () => {
    const sel = $('missingField');
    try {
      const d = await request('/coverage?missing=' + sel.value);
      $('intelligenceOutput').innerHTML = `<h3>${escapeHtml(sel.options[sel.selectedIndex].text)} · ${d.missingLeads.length}</h3>`
        + (d.missingLeads.map((x) => `<p><b>${escapeHtml(x.name)}</b> <span class="muted">${escapeHtml(x.address || 'No address')}</span></p>`).join('')
          || '<p>All stored leads have this field.</p>');
    } catch (e) { $('intelligenceOutput').textContent = e.message; }
  });

  $('historyBtn').addEventListener('click', async () => {
    try {
      const d = await request('/history');
      $('intelligenceOutput').innerHTML = '<h3>Recent searches</h3>'
        + (d.history.map((x) => {
          let f = {}; try { f = JSON.parse(x.filters || '{}'); } catch (_) {}
          const mode = f.mode || 'business';
          return `<p><b>[${mode}]</b> ${escapeHtml(x.query)} · ${x.results_returned} results · ${x.new_leads_created} new · ${escapeHtml(x.created_at)}</p>`;
        }).join('') || '<p>No searches recorded yet.</p>');
    } catch (e) { $('intelligenceOutput').textContent = e.message; }
  });

  $('duplicatesBtn').addEventListener('click', showDuplicates);
  async function showDuplicates() {
    try {
      const d = await request('/duplicates');
      $('intelligenceOutput').innerHTML = '<h3>Open duplicate candidates</h3>'
        + (d.duplicates.map((x) => `<p><b>${escapeHtml(x.leadAName)}</b> ↔ <b>${escapeHtml(x.leadBName)}</b> · ${Math.round((x.confidence||0) * 100)}%
            <button type="button" onclick="window.scoutResolveDuplicate('${x.id}','merge')">Merge</button>
            <button type="button" class="ghost" onclick="window.scoutResolveDuplicate('${x.id}','ignore')">Ignore</button></p>`).join('')
          || '<p>No open duplicate candidates.</p>');
    } catch (e) { $('intelligenceOutput').textContent = e.message; }
  }

  window.scoutResolveDuplicate = async function (candidateId, action) {
    try { await request('/duplicates/resolve', { method: 'POST', body: JSON.stringify({ candidateId, action }) }); showDuplicates(); loadPipeline(); }
    catch (e) { $('intelligenceOutput').textContent = e.message; }
  };

  $('previewBtn').addEventListener('click', async () => {
    try {
      const d = await request('/export/preview', { method: 'POST', body: JSON.stringify({}) });
      $('intelligenceOutput').innerHTML = `<h3>CSV-safe export preview · ${d.count} leads</h3><pre>${escapeHtml(JSON.stringify(d.rows, null, 2))}</pre>`;
    } catch (e) { $('intelligenceOutput').textContent = e.message; }
  });

  async function loadPipeline() {
    const d = await request('/pipeline');
    $('board').innerHTML = d.statuses.map((status) => `<div class="column">
      <h3>${status} · ${d.columns[status].length}</h3>
      ${d.columns[status].map((x) => {
        const meta = extractMeta(x);
        return `<article class="leadCard">
          <b>${escapeHtml(x.name)}</b>
          <p>${escapeHtml(x.job_title || meta.title || x.category || 'Uncategorized')}${x.company_name ? ' · ' + escapeHtml(x.company_name) : ''}</p>
          ${verificationPill(meta)} ${kindPill(x)}
          <div><select onchange="window.scoutStatus('${x.id}', this.value)" style="margin-top:6px">
            ${d.statuses.map((s) => `<option ${x.status === s ? 'selected' : ''}>${s}</option>`).join('')}
          </select></div>
          <button type="button" class="ghost" onclick="window.scoutAssignMe('${x.id}')">${x.owner_id ? 'Reassign to me' : 'Assign to me'}</button>
          <button type="button" class="ghost" onclick="window.scoutLeadNotes('${x.id}')">Notes</button>
          <button type="button" onclick="window.scoutOutreach('${x.id}')">Outreach</button>
        </article>`;
      }).join('') || '<p class="muted">No leads</p>'}
    </div>`).join('');
  }

  window.scoutAssignMe = async function (id) {
    try { await request('/leads/' + id + '/owner', { method: 'PATCH', body: JSON.stringify({ ownerId: currentUserId }) }); loadPipeline(); }
    catch (e) { messageEl.textContent = e.message; }
  };

  window.scoutLeadNotes = async function (id) {
    try {
      const d = await request('/leads/' + id + '/notes');
      const body = prompt('Notes:\n' + d.notes.map((n) => n.body).join('\n—\n') + '\n\nEnter a new note (optional):');
      if (body && body.trim()) { await request('/leads/' + id + '/notes', { method: 'POST', body: JSON.stringify({ body }) }); loadPipeline(); }
    } catch (e) { messageEl.textContent = e.message; }
  };

  $('selectAllBox').addEventListener('change', (ev) => {
    document.querySelectorAll('.leadSelect').forEach((x) => { x.checked = ev.target.checked; });
  });

  $('createCollectionBtn').addEventListener('click', async () => {
    const name = $('collectionName').value.trim();
    if (!name) { $('collectionInfo').textContent = 'Enter a collection name.'; return; }
    try { await request('/collections', { method: 'POST', body: JSON.stringify({ name }) }); $('collectionName').value=''; $('collectionInfo').textContent='Collection created.'; load(); }
    catch (e) { $('collectionInfo').textContent = e.message; }
  });

  $('addSelectedBtn').addEventListener('click', async () => {
    const id = $('collectionSelect').value;
    const leadIds = [...document.querySelectorAll('.leadSelect:checked')].map((x) => x.value);
    if (!id) { $('collectionInfo').textContent = 'Create or select a collection.'; return; }
    if (!leadIds.length) { $('collectionInfo').textContent = 'Select at least one lead.'; return; }
    try {
      const r = await request('/collections/' + id + '/leads', { method: 'POST', body: JSON.stringify({ leadIds }) });
      $('collectionInfo').textContent = `${r.added} lead(s) added to the collection.`;
      document.querySelectorAll('.leadSelect').forEach((x) => { x.checked = false; });
      load();
    } catch (e) { $('collectionInfo').textContent = e.message; }
  });

  $('exportBtn').addEventListener('click', async () => {
    try {
      const r = await fetch(api + '/export/csv', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: '{}' });
      if (!r.ok) throw new Error('Export failed');
      const a = document.createElement('a'); a.href = URL.createObjectURL(await r.blob()); a.download = 'leads.csv'; a.click();
    } catch (e) { messageEl.textContent = e.message; }
  });

  boot();
})();
</script>
</body>
</html>
