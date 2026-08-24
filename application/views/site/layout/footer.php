<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<footer class="pub-foot">
  <div class="pub-foot-grid">
    <div>
      <strong>Africa Mobility</strong>
      <p>Evidence-first intelligence for markets, language learning, sports research, lottery study and lead discovery. Analysis and simulation software — not investment advice.</p>
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
      <a href="/login">User dashboard</a>
      <a href="/admin/login">Admin dashboard</a>
    </div>
  </div>
  <p class="pub-legal">© <?= date('Y') ?> Africa Mobility. Dashboards require a signed-in account. Synthetic or sandbox data is always labelled.</p>
</footer>
<script src="/assets/js/public.js" defer></script>
<?php $this->load->view('partials/chat_widget'); ?>
</body>
</html>
