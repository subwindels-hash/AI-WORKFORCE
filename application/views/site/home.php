<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<section class="hero">
  <div class="hero-copy">
    <p class="kicker">WINDELS AI WORKFORCE</p>
    <h1>Your AI-powered workforce, grounded in evidence.</h1>
    <p class="lede">One workspace for an AI language teacher, market analysis, sports intelligence, lottery research and lead discovery — without inventing data or bypassing risk controls.</p>
    <div class="hero-cta">
      <a class="btn solid" href="/register">Get started</a>
      <a class="btn ghost" href="/services">Explore services</a>
    </div>
    <div class="pills" style="margin-top:20px">
      <span>20 languages</span>
      <span>Real TTS voices</span>
      <span>Persistent dashboard</span>
      <span>Secure RBAC</span>
    </div>
  </div>
  <div class="hero-visual">
    <div class="hero-ai-wrap">
      <div class="hero-ai-card">
        <div class="hero-ai-card-head">
          <span class="ai-avatar" id="hero-ai-avatar">
            <img src="/assets/images/ai-agent-avatar.png" alt="WINDELS AI Agent" width="56" height="56" loading="eager" onerror="this.style.display='none';this.parentElement.classList.add('is-fallback');">
            <span class="ai-avatar-fallback">W</span>
          </span>
          <div>
            <strong style="display:block;font:700 14px system-ui,sans-serif">WINDELS Assistant</strong>
            <span style="font:600 12px system-ui,sans-serif;color:var(--brand)">Online · grounded answers</span>
          </div>
        </div>
        <div class="hero-ai-bubble agent">Hallo! Hoe gaat het met je?</div>
        <div class="hero-ai-bubble user">Hello, how are you?</div>
        <div class="hero-ai-bubble agent">Detected English → Dutch: <b>Hallo! Hoe gaat het met je?</b> Target stays Dutch.</div>
        <div class="hero-ai-meta">
          <span>nl-NL voice</span>
          <span>Listen · Replay</span>
          <span>No fake scores</span>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="band" id="what">
  <div class="section-head">
    <p class="kicker">What is WINDELS AI WORKFORCE?</p>
    <h2>One platform. Five working modules.</h2>
    <p>Behind login you use the tools that already ship in this product — a language teacher with real voices, plus research and analysis with an audit trail.</p>
  </div>
  <div class="split">
    <img src="/assets/images/about-workspace.jpg" alt="Team reviewing live workspace data" loading="lazy" width="800" height="550">
    <ul class="checklist">
      <li>Multi-agent market analysis with a mandatory risk engine</li>
      <li>Paper trading, strategy lab and a 15-step execution supervisor</li>
      <li>Sports intelligence that stays dark until a provider is configured</li>
      <li>Language learning across a 20-language registry with real TTS</li>
      <li>Lead discovery through Google Places when a key is set</li>
    </ul>
  </div>
</section>

<section class="band alt" id="capabilities">
  <div class="section-head">
    <p class="kicker">Capabilities</p>
    <h2>Choose a workspace, not a slogan</h2>
    <p>Every capability is real, tested, and labelled honestly.</p>
  </div>
  <div class="cards four">
    <article class="card"><h3>Trading intelligence</h3><p>Consensus, regime detection and risk-reviewed proposals. No orders leave this process from the public site.</p><a href="/services">Learn more</a></article>
    <article class="card"><h3>AI language teacher</h3><p>Translation, listening and speaking from authored banks. The target language only changes when you select it.</p><a href="/services">Learn more</a></article>
    <article class="card"><h3>Sports intelligence</h3><p>Daily ticket research from stored fixtures and odds. Disabled until a real provider is configured.</p><a href="/services">Learn more</a></article>
    <article class="card"><h3>Lead discovery</h3><p>Search, deduplicate and export real businesses. Coverage is calculated from stored fields.</p><a href="/services">Learn more</a></article>
  </div>
</section>

<section class="band" id="how-it-works">
  <div class="section-head">
    <p class="kicker">How it works</p>
    <h2>Four steps. Then the audit trail.</h2>
  </div>
  <ol class="steps">
    <li><span>01</span><div><h3>Create an account</h3><p>Register as a platform member. Public pages show Login, Register and Forgot password — never an admin login.</p></div></li>
    <li><span>02</span><div><h3>Open your workspace</h3><p>Members land on the dashboard. Administrators reach a private control centre. Role is decided by the server.</p></div></li>
    <li><span>03</span><div><h3>Use a real module</h3><p>Run analysis, paper-trade, study a language, review sports or search leads. The sidebar stays mounted.</p></div></li>
    <li><span>04</span><div><h3>Stay inside the rules</h3><p>Kill switch, RBAC, CSRF and labelled simulation stay on. Nothing is faked to look complete.</p></div></li>
  </ol>
  <p class="center" style="margin-top:24px"><a class="btn ghost" href="/how-it-works">See the full flow</a></p>
</section>

<section class="stats">
  <div><b><?= (int) ($languages ?? 20) ?></b><span>Languages in the authored registry</span></div>
  <div><b>15</b><span>Steps in the execution supervisor</span></div>
  <div><b>4</b><span>Built-in trading strategies</span></div>
  <div><b>0</b><span>Orders placed from the public website</span></div>
</section>

<section class="band alt" id="use-cases">
  <div class="section-head">
    <p class="kicker">Coverage</p>
    <h2>Software coverage, not invented depots</h2>
    <p>Use the real modules. The teacher registry, market watchlist and Places search are what exist in this codebase.</p>
  </div>
  <div class="pills">
    <span>Forex &amp; metals watchlist</span>
    <span>Crypto via Binance public REST</span>
    <span><?= (int) ($languages ?? 20) ?> languages in the learning registry</span>
    <span>EuroMillions research module</span>
    <span>Lead search wherever Places is configured</span>
  </div>
  <p class="center" style="margin-top:20px"><a class="btn ghost" href="/locations">View coverage</a></p>
</section>

<section class="cta" id="cta">
  <div>
    <h2>Ready to open a workspace?</h2>
    <p>Create a member account, or sign in if you already have one. Dashboards stay behind authentication.</p>
  </div>
  <div class="hero-cta">
    <a class="btn solid" href="/register">Create account</a>
    <a class="btn ghost" href="/login">Sign in</a>
  </div>
</section>

<section class="band" id="faq">
  <div class="section-head">
    <p class="kicker">FAQ</p>
    <h2>Short answers</h2>
  </div>
  <div class="faq">
    <details open><summary>Can I open the dashboard without an account?</summary><p>No. <span class="mono">/dashboard</span> and the module consoles redirect visitors to login.</p></details>
    <details><summary>What is WINDELS AI WORKFORCE?</summary><p>An AI-powered workforce platform for language learning, market analysis, sports and lottery research, and lead discovery. It never invents data to look complete.</p></details>
    <details><summary>Who can use the admin area?</summary><p>Only accounts with the super-administrator permission. Other users see Access denied.</p></details>
  </div>
  <p class="center" style="margin-top:20px"><a class="btn ghost" href="/faq">All questions</a></p>
</section>

<section class="band alt" id="contact">
  <div class="section-head">
    <p class="kicker">Contact</p>
    <h2>Talk to the operator</h2>
    <p>Messages are written to the audit trail. If SMTP is enabled on the server, a copy is emailed.</p>
  </div>
  <p class="center"><a class="btn solid" href="/contact">Open the contact form</a></p>
</section>
