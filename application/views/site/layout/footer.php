<?php
defined('BASEPATH') or exit('No direct script access allowed');

// Social destinations are deployment-owned. Never guess a brand handle: only
// render a channel when its official HTTPS URL has been configured.
$socialDefinitions = [
    [
        'key' => 'facebook',
        'label' => 'Facebook',
        'env' => 'VP_SOCIAL_FACEBOOK',
        'icon' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 21v-8h3l.5-4H14V7c0-1.2.4-2 2.2-2H18V1.5c-.8-.1-1.8-.2-3-.2-3 0-5 1.8-5 5.2V9H7v4h3v8h4Z"/></svg>',
    ],
    [
        'key' => 'instagram',
        'label' => 'Instagram',
        'env' => 'VP_SOCIAL_INSTAGRAM',
        'icon' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle class="social-icon-dot" cx="17.4" cy="6.7" r="1"/></svg>',
    ],
    [
        'key' => 'x',
        'label' => 'X',
        'env' => 'VP_SOCIAL_X',
        'icon' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 4 14 16M19 4 5 20"/></svg>',
    ],
    [
        'key' => 'linkedin',
        'label' => 'LinkedIn',
        'env' => 'VP_SOCIAL_LINKEDIN',
        'icon' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M8 10v7m0-10v.1M12 17v-7m0 3.2c.6-2 4-2.2 4 1V17"/></svg>',
    ],
    [
        'key' => 'telegram',
        'label' => 'Telegram',
        'env' => 'VP_SOCIAL_TELEGRAM',
        'icon' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 11 17-7-4 16-5-5-3 2 .5-4.5L17 7 8.5 12.5 3 11Z"/></svg>',
    ],
    [
        'key' => 'youtube',
        'label' => 'YouTube',
        'env' => 'VP_SOCIAL_YOUTUBE',
        'icon' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12c0-3-.4-5-1-5.6S16.8 5.5 12 5.5s-7.4.3-8 .9S3 9 3 12s.4 5 1 5.6 3.2.9 8 .9 7.4-.3 8-.9 1-2.6 1-5.6Z"/><path d="m10 9 5 3-5 3V9Z"/></svg>',
    ],
];
$socialChannels = [];
foreach ($socialDefinitions as $channel) {
    $url = trim((string) getenv($channel['env']));
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if ($url === '' || $scheme !== 'https' || filter_var($url, FILTER_VALIDATE_URL) === false) continue;
    $channel['url'] = $url;
    $socialChannels[] = $channel;
}
?>
<footer class="pub-foot">
  <div class="pub-foot-grid">
    <div>
      <strong>WINDELS AI WORKFORCE</strong>
      <p>An evidence-first AI-powered platform for language learning, market analysis, sports research, lottery study and lead discovery. Analysis and simulation software — not investment advice. We never hold client deposits or process withdrawals: your funds stay with your own broker.</p>
      <?php /* Contact phone/address intentionally not shown here — see /contact. */ ?>
    </div>
    <div>
      <span>Explore</span>
      <a href="/about">About</a>
      <a href="/services">Services</a>
      <a href="/how-it-works">How it works</a>
      <a href="/reviews">User reviews</a>
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
      <a href="/services">Modules</a>
    </div>
  </div>
  <?php if ($socialChannels): ?>
    <section class="pub-social" aria-labelledby="pub-social-title">
      <div class="pub-social-copy">
        <h2 id="pub-social-title">Follow WINDELS</h2>
        <p>News, product updates and community conversations.</p>
      </div>
      <div class="pub-social-links">
        <?php foreach ($socialChannels as $channel): ?>
          <a class="pub-social-button is-<?= e($channel['key']) ?>" href="<?= e($channel['url']) ?>" target="_blank" rel="noopener noreferrer" aria-label="Follow WINDELS on <?= e($channel['label']) ?>">
            <?= $channel['icon'] ?>
            <span><?= e($channel['label']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>
  <p class="pub-legal">© <?= date('Y') ?> WINDELS AI WORKFORCE. Dashboards require a signed-in account. Synthetic or sandbox data is always labelled.</p>
</footer>
<script src="/assets/js/public.js" defer></script>
<?php $this->load->view('partials/chat_widget'); ?>
</body>
</html>
