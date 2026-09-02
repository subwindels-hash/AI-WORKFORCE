<?php
defined('BASEPATH') OR exit('No direct script access allowed');
/* Branded error page — carries the same menu and footer as the rest of the site.
   Rendered by CodeIgniter's error handler, so it stays self-contained HTML. */
$navLinks = [
    '/' => 'Home', '/about' => 'About', '/services' => 'Services',
    '/how-it-works' => 'How it works', '/locations' => 'Coverage',
    '/safety' => 'Safety', '/faq' => 'FAQ', '/contact' => 'Contact',
];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo html_escape($heading); ?> · WINDELS AI WORKFORCE</title>
<link rel="icon" type="image/png" href="/assets/images/windels-mark.png">
<link rel="stylesheet" href="/assets/css/ai_workforce.css">
</head>
<body class="auth-page">
<header class="auth-nav" id="auth-nav">
  <a class="auth-nav-brand" href="/">
    <img src="/assets/images/windels-mark.png" alt="WINDELS AI WORKFORCE logo">
    <span>WINDELS AI WORKFORCE</span>
  </a>
  <button class="auth-nav-toggle" type="button" aria-expanded="false" aria-controls="auth-nav-menu">Menu</button>
  <nav id="auth-nav-menu" class="auth-nav-links" aria-label="Primary">
    <?php foreach ($navLinks as $href => $label): ?><a href="<?php echo $href; ?>"><?php echo $label; ?></a><?php endforeach; ?>
  </nav>
  <div class="auth-nav-actions">
    <a class="btn ghost" href="/login">Login</a>
    <a class="btn primary" href="/register">Get started</a>
  </div>
</header>

<main class="auth-shell">
  <section class="auth-card">
    <div class="auth-brand">
      <img src="/assets/images/windels-mark.png" alt="" class="auth-brand-mark">
      <span class="auth-brand-text">WINDELS AI Workforce</span>
    </div>
    <h1><?php echo html_escape($heading); ?></h1>
    <div class="auth-sub"><?php echo $message; ?></div>
    <div class="auth-foot"><a href="/"><b>Back to the website</b></a> · <a href="/contact">Contact support</a></div>
  </section>
</main>

<footer class="auth-foot-site">
  <div class="auth-foot-grid">
    <div>
      <strong>WINDELS AI WORKFORCE</strong>
      <p>An evidence-first AI-powered platform for language learning, market analysis, sports research, lottery study and lead discovery. Analysis and simulation software — not investment advice.</p>
    </div>
    <div>
      <span>Explore</span>
      <a href="/about">About</a><a href="/services">Services</a><a href="/how-it-works">How it works</a><a href="/safety">Safety</a>
    </div>
    <div>
      <span>Account</span>
      <a href="/login">Login</a><a href="/register">Register</a><a href="/contact">Contact</a><a href="/faq">FAQ</a>
    </div>
    <div>
      <span>Workspace</span>
      <a href="/dashboard">User dashboard</a><a href="/services">Modules</a>
    </div>
  </div>
  <p class="auth-foot-legal">&copy; <?php echo date('Y'); ?> WINDELS AI WORKFORCE. Dashboards require a signed-in account. Synthetic or sandbox data is always labelled.</p>
</footer>
<script>
(function () {
  var nav = document.getElementById('auth-nav');
  var toggle = nav && nav.querySelector('.auth-nav-toggle');
  if (!nav || !toggle) return;
  toggle.addEventListener('click', function () {
    var open = nav.classList.toggle('is-open');
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  });
})();
</script>
</body>
</html>
