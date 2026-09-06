<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<section class="page-hero">
  <p class="kicker">Safety &amp; trust</p>
  <h1>Your money never passes through us.</h1>
  <p class="lede">WINDELS AI WORKFORCE is software — not a bank, broker, or exchange. We never hold client deposits or third-party funds, and no deposits or withdrawals ever pass through our accounts. This page explains exactly how that works, and what we actually do.</p>
</section>

<section class="band" id="funds">
  <div class="section-head left">
    <p class="kicker">Our funds policy</p>
    <h2>We build the tools. Your broker holds the money.</h2>
    <p>There is no WINDELS wallet to send money to, and no WINDELS account your withdrawals travel through. Your funds stay in your own account, in your own name, with a broker or exchange you chose yourself. We only provide the analysis software you look at while you trade.</p>
  </div>
  <ol class="steps">
    <li><span>01</span><div><h3>You open your own account</h3><p>You register directly with a broker or exchange of your choice — MT5, Binance, Bybit, OANDA, or any provider you trust. That account is yours alone.</p></div></li>
    <li><span>02</span><div><h3>You deposit directly with them</h3><p>Every deposit goes straight from you to your broker. There is no step where money comes to WINDELS first, because we have no way to receive it.</p></div></li>
    <li><span>03</span><div><h3>You use our software to analyse</h3><p>Market data, AI analysis, backtests, paper trading and risk checks all run in your workspace. If you connect a broker API key, it is read-first and gated by the kill switch and human approval — it can never route your money to us.</p></div></li>
    <li><span>04</span><div><h3>You withdraw directly from them</h3><p>Every withdrawal goes straight from your broker to you. No payout, profit, or refund ever passes through a WINDELS account.</p></div></li>
  </ol>
</section>

<section class="band alt" id="do-dont">
  <div class="section-head">
    <p class="kicker">Plain and simple</p>
    <h2>What we do — and what we never do</h2>
  </div>
  <div class="cards two">
    <article class="card">
      <h3>What we do</h3>
      <ul class="checklist">
        <li>Build AI software for analysis, learning, and research</li>
        <li>Show you market data from providers you connect</li>
        <li>Simulate strategies with paper trading before you risk anything</li>
        <li>Guard every action with risk checks, a kill switch, and audit logs</li>
        <li>Charge only for software subscriptions and services</li>
      </ul>
    </article>
    <article class="card">
      <h3>What we never do</h3>
      <ul class="checklist">
        <li>Never accept deposits from clients</li>
        <li>Never hold or store client money or crypto</li>
        <li>Never process withdrawals or payouts</li>
        <li>Never manage funds or trade on your behalf</li>
        <li>Never ask you to send money to a wallet, account, or agent</li>
      </ul>
    </article>
  </div>
</section>

<section class="band" id="what-we-do">
  <div class="section-head">
    <p class="kicker">All we do</p>
    <h2>One workspace, five kinds of work</h2>
    <p>Everything below is software you use yourself. None of it moves, holds, or manages your money.</p>
  </div>
  <div class="cards three">
    <article class="card"><h3>AI language teacher</h3><p>Learn 20 languages with translation, listening, voice playback, and speaking practice.</p></article>
    <article class="card"><h3>Market analysis</h3><p>Multi-agent AI analysis, charts, backtesting, paper trading, journals, and risk controls.</p></article>
    <article class="card"><h3>Sports &amp; lottery research</h3><p>Statistical research tools over stored data. Historical figures only — no tickets, no predictions.</p></article>
    <article class="card"><h3>Lead discovery</h3><p>Find B2B businesses, organise collections and pipelines, and export clean lists.</p></article>
    <article class="card"><h3>AI assistants</h3><p>Chat helpers, dashboards, alerts, and analytics across your whole workspace.</p></article>
    <article class="card"><h3>Risk &amp; execution guardrails</h3><p>Kill switch, 15-step execution supervisor, and human approval before anything sensitive happens.</p></article>
  </div>
</section>

<section class="band alt" id="controls">
  <div class="section-head">
    <p class="kicker">Platform controls</p>
    <h2>Controls that stay on when nobody is watching</h2>
  </div>
  <div class="cards two">
    <article class="card"><h3>Authentication</h3><p>Sessions live server-side. Passwords are hashed. Login is rate-limited. Logout requires the CSRF token issued at sign-in.</p></article>
    <article class="card"><h3>Authorization</h3><p>Hiding a menu is not enough. Pages call requireLogin / requireAdminPage. APIs return 401 or 403. Writes re-check RBAC and CSRF.</p></article>
    <article class="card"><h3>Kill switch</h3><p>The platform boots with the kill switch active. Paper and broker orders are blocked until an authorized operator releases it.</p></article>
    <article class="card"><h3>Honest data</h3><p>Synthetic candles, sandbox sports and missing providers are labelled. Missing values stay null. CSV export is formula-safe.</p></article>
    <article class="card"><h3>Audit</h3><p>Logins, contact inquiries, user creation and trading events are written to audit_logs with an actor.</p></article>
    <article class="card"><h3>Broker writes</h3><p>Order submission needs an authenticated bridge, TRADING_ENABLED, and a demo account unless live is explicitly allowed.</p></article>
  </div>
</section>

<section class="band" id="scam-warning">
  <div class="section-head left">
    <p class="kicker">Protect yourself</p>
    <h2>If someone asks you to send money to WINDELS, it is a scam.</h2>
    <p>We have no deposit address, no investment plan, and no agent collecting funds on our behalf — so anyone claiming otherwise is impersonating us. Do not send anything. Report it to us through the <a href="/contact">contact page</a> and to your broker immediately.</p>
  </div>
  <p><a class="btn solid" href="/contact">Contact us</a> <a class="btn ghost" href="/faq">Read the FAQ</a></p>
</section>
