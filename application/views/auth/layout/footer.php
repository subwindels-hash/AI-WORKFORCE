<?php
defined('BASEPATH') or exit('No direct script access allowed');
if (!function_exists('e')) {
    function e($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
}
$ci = get_instance();
?>
<footer class="auth-foot-site">
  <div class="auth-foot-grid">
    <div>
      <strong>WINDELS AI WORKFORCE</strong>
      <p>An evidence-first AI-powered platform for language learning, market analysis, sports research, lottery study and lead discovery. Analysis and simulation software — not investment advice.</p>
    </div>
    <div>
      <span>Explore</span>
      <a href="/about">About</a>
      <a href="/services">Services</a>
      <a href="/how-it-works">How it works</a>
      <a href="/safety">Safety</a>
    </div>
    <div>
      <span>Account</span>
      <a href="/login">Login</a>
      <a href="/register">Register</a>
      <a href="/contact">Contact</a>
      <a href="/faq">FAQ</a>
    </div>
    <div>
      <span>Workspace</span>
      <a href="/dashboard">User dashboard</a>
      <a href="/services">Modules</a>
    </div>
  </div>
  <p class="auth-foot-legal">© <?= date('Y') ?> WINDELS AI WORKFORCE. Dashboards require a signed-in account. Synthetic or sandbox data is always labelled.</p>
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
