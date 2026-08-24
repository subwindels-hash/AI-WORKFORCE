<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<section class="hero">
  <div class="hero-copy">
    <p class="kicker">Africa Mobility</p>
    <h1>Evidence first. Then you decide.</h1>
    <p class="lede">A modern workspace that connects people with market analysis, language learning, sports intelligence, lottery research and lead discovery — without inventing data or bypassing risk controls.</p>
    <div class="hero-cta">
      <a class="btn solid" href="/register">Get started</a>
      <a class="btn ghost" href="/services">Explore services</a>
    </div>
  </div>
  <div class="hero-visual">
    <img src="/assets/images/hero-africa-mobility.jpg" alt="A modern African city at golden hour">
  </div>
</section>

<section class="band" id="what">
  <div class="section-head">
    <p class="kicker">What we do</p>
    <h2>One platform. Five working modules.</h2>
    <p>Africa Mobility is the public face of this product. Behind login you use the real tools that already ship in this repository — not a catalogue of imagined transport routes.</p>
  </div>
  <div class="split">
    <img src="/assets/images/about-workspace.jpg" alt="Team reviewing live workspace data">
    <ul class="checklist">
      <li>Multi-agent market analysis with a mandatory risk engine</li>
      <li>Paper trading, strategy lab and execution governance</li>
      <li>Sports intelligence that stays dark until a provider is configured</li>
      <li>Language learning across a 20-language registry</li>
      <li>Lead discovery through Google Places when a key is set</li>
    </ul>
  </div>
</section>

<section class="band alt">
  <div class="section-head">
    <p class="kicker">Services</p>
    <h2>Choose a workspace, not a slogan</h2>
  </div>
  <div class="cards four">
    <article class="card"><h3>Trading intelligence</h3><p>Consensus, regime detection and risk-reviewed proposals. No orders leave this process from the public site.</p><a href="/services">Learn more</a></article>
    <article class="card"><h3>Language learning</h3><p>Adaptive assessments, lessons and spaced repetition from authored banks — never invented scores.</p><a href="/services">Learn more</a></article>
    <article class="card"><h3>Sports intelligence</h3><p>Daily ticket research from stored fixtures and odds. Disabled until a real provider is configured.</p><a href="/services">Learn more</a></article>
    <article class="card"><h3>Lead discovery</h3><p>Search, deduplicate and export real businesses. Google Places only — no synthetic companies.</p><a href="/services">Learn more</a></article>
  </div>
</section>

<section class="band">
  <div class="section-head">
    <p class="kicker">How it works</p>
    <h2>Four steps. Then the audit trail.</h2>
  </div>
  <ol class="steps">
    <li><span>01</span><div><h3>Create an account</h3><p>Register as a platform member. Administrators keep a separate control centre.</p></div></li>
    <li><span>02</span><div><h3>Open your workspace</h3><p>Login sends members to the user dashboard and administrators to the admin dashboard.</p></div></li>
    <li><span>03</span><div><h3>Use a real module</h3><p>Run analysis, paper-trade, study a language, review sports, or search leads.</p></div></li>
    <li><span>04</span><div><h3>Stay inside the rules</h3><p>Kill switch, RBAC, CSRF and labelled simulation stay on. Nothing is faked to look complete.</p></div></li>
  </ol>
  <p class="center"><a class="btn ghost" href="/how-it-works">See the full flow</a></p>
</section>

<section class="band alt">
  <div class="section-head">
    <p class="kicker">Why choose us</p>
    <h2>Fail closed. Label simulation. Keep an audit.</h2>
  </div>
  <div class="cards three">
    <article class="card"><h3>No silent fake data</h3><p>Synthetic candles, sandbox sports and missing lottery feeds are labelled. The risk engine can veto them.</p></article>
    <article class="card"><h3>Role-aware access</h3><p>Visitors see this site. Members see the user dashboard. Super administrators see /admin.</p></article>
    <article class="card"><h3>Governed execution</h3><p>Broker routing only happens through a verified connector and the 15-step supervisor.</p></article>
  </div>
</section>

<section class="band">
  <div class="section-head">
    <p class="kicker">Coverage</p>
    <h2>Software coverage, not invented depots</h2>
    <p>This is not a taxi or freight network. Coverage means the markets, languages and research tools that actually exist in the product.</p>
  </div>
  <div class="pills">
    <span>Forex &amp; metals watchlist</span>
    <span>Crypto via Binance public REST</span>
    <span><?= (int) ($languages ?? 20) ?> languages in the learning registry</span>
    <span>EuroMillions research module</span>
    <span>Lead search wherever Places is configured</span>
  </div>
  <p class="center"><a class="btn ghost" href="/locations">View coverage</a></p>
</section>

<section class="stats">
  <div><b><?= (int) ($languages ?? 20) ?></b><span>Languages in the authored registry</span></div>
  <div><b>15</b><span>Steps in the execution supervisor</span></div>
  <div><b>4</b><span>Built-in trading strategies</span></div>
  <div><b>0</b><span>Orders placed from the public website</span></div>
</section>

<section class="band alt">
  <div class="section-head">
    <p class="kicker">From operators</p>
    <h2>What the workspace is for</h2>
  </div>
  <div class="cards three">
    <blockquote class="quote"><p>“The dashboard only shows what the engines actually computed. If a provider is down, it says so.”</p><footer>Trading operator · paper workspace</footer></blockquote>
    <blockquote class="quote"><p>“Language levels come from answers in the bank. Listening and pronunciation scores are never invented.”</p><footer>Language learner</footer></blockquote>
    <blockquote class="quote"><p>“Sports stays DISABLED_NO_PROVIDER until we configure a feed. That honesty is the product.”</p><footer>Sports viewer</footer></blockquote>
  </div>
</section>

<section class="cta">
  <div>
    <h2>Ready to open a workspace?</h2>
    <p>Create a member account, or sign in if you already have one. Dashboards stay behind authentication.</p>
  </div>
  <div class="hero-cta">
    <a class="btn solid" href="/register">Create account</a>
    <a class="btn ghost" href="/login">Sign in</a>
  </div>
</section>

<section class="band">
  <div class="section-head">
    <p class="kicker">FAQ</p>
    <h2>Short answers</h2>
  </div>
  <div class="faq">
    <details open><summary>Can I open the dashboard without an account?</summary><p>No. <span class="mono">/dashboard</span>, <span class="mono">/admin</span> and the module consoles redirect visitors to login.</p></details>
    <details><summary>Is this a transport booking company?</summary><p>No. Africa Mobility here is an intelligence platform. We do not invent taxi, freight or ticket-booking networks that are not in the code.</p></details>
    <details><summary>Who can use the admin area?</summary><p>Only accounts with <span class="mono">system.super_admin</span>. Other signed-in users receive Access denied.</p></details>
  </div>
  <p class="center"><a class="btn ghost" href="/faq">All questions</a></p>
</section>

<section class="band alt" id="contact">
  <div class="section-head">
    <p class="kicker">Contact</p>
    <h2>Talk to the operator</h2>
    <p>Messages are written to the audit trail. If SMTP is enabled on the server, a copy is emailed.</p>
  </div>
  <p class="center"><a class="btn solid" href="/contact">Open the contact form</a></p>
</section>
