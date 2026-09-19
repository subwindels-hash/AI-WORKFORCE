<?php
defined('BASEPATH') or exit('No direct script access allowed');

// Social destinations are deployment-owned. Never guess a brand handle: a
// channel is rendered only when its official HTTPS URL is configured, either
// through its VP_SOCIAL_* environment variable (highest precedence) or through
// application/config/social.php. Marks are the official brand glyphs, drawn on
// a 24x24 grid and rendered at 16x16. They are sized twice over: the stylesheet
// rule `.pub-social-button svg` owns the appearance, and each svg also carries
// an intrinsic width/height="16" floor. Without that floor an svg with a
// viewBox but no dimensions falls back to the browser's default
// replaced-element box (~150-300px), so one stale or missing public.css (it is
// served with a 7-day max-age) painted all seven marks giant. CSS beats
// presentation attributes, so the floor only bounds the failure mode.
$ci = get_instance();
$ci->config->load('social', true);
$socialConfigured = (array) ($ci->config->item('channels', 'social') ?: []);

$socialDefinitions = [
    [
        'key' => 'facebook',
        'label' => 'Facebook',
        'env' => 'VP_SOCIAL_FACEBOOK',
        'icon' => '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path d="M9.101 23.691v-7.98H6.627v-3.667h2.474v-1.58c0-4.085 1.848-5.978 5.858-5.978.401 0 .955.042 1.468.103a8.68 8.68 0 0 1 1.141.195v3.325a8.623 8.623 0 0 0-.653-.036 26.805 26.805 0 0 0-.733-.009c-.707 0-1.259.096-1.675.309a1.686 1.686 0 0 0-.679.622c-.258.42-.374.995-.374 1.752v1.297h3.919l-.386 2.103-.287 1.564h-3.246v8.245C19.396 23.238 24 18.179 24 12.044c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.628 3.874 10.35 9.101 11.647Z"/></svg>',
    ],
    [
        'key' => 'instagram',
        'label' => 'Instagram',
        'env' => 'VP_SOCIAL_INSTAGRAM',
        'icon' => '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><defs><linearGradient id="pub-ig-gradient" x1="0" y1="24" x2="24" y2="0" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#ffdd55"/><stop offset=".25" stop-color="#ff543e"/><stop offset=".6" stop-color="#c837ab"/><stop offset="1" stop-color="#3771c8"/></linearGradient></defs><path fill="url(#pub-ig-gradient)" d="M12 2.163c3.204 0 3.584.012 4.85.07 1.17.053 1.805.249 2.227.415.56.217.96.477 1.382.896.419.42.679.819.896 1.381.164.422.36 1.057.413 2.227.058 1.266.07 1.646.07 4.85s-.012 3.584-.07 4.85c-.053 1.17-.249 1.805-.413 2.227a3.71 3.71 0 0 1-.896 1.382c-.42.419-.822.679-1.382.896-.422.164-1.06.36-2.227.413-1.265.058-1.645.07-4.85.07s-3.585-.012-4.85-.07c-1.17-.053-1.805-.249-2.227-.413a3.716 3.716 0 0 1-1.38-.896 3.72 3.72 0 0 1-.9-1.382c-.163-.422-.36-1.057-.412-2.227-.058-1.266-.07-1.646-.07-4.85s.012-3.584.07-4.85c.052-1.17.249-1.805.413-2.227.217-.562.477-.96.896-1.381.42-.42.82-.68 1.381-.896.422-.166 1.057-.362 2.227-.415C8.416 2.175 8.796 2.163 12 2.163ZM12 0C8.741 0 8.332.014 7.052.072 5.775.132 4.903.333 4.14.63a5.88 5.88 0 0 0-2.126 1.384A5.884 5.884 0 0 0 .63 4.14C.333 4.903.131 5.775.072 7.052.014 8.332 0 8.741 0 12s.014 3.668.072 4.948c.06 1.277.261 2.149.558 2.913a5.885 5.885 0 0 0 1.384 2.126A5.868 5.868 0 0 0 4.14 23.37c.764.297 1.636.499 2.913.558C8.333 23.986 8.741 24 12 24s3.668-.014 4.948-.072c1.277-.06 2.148-.261 2.913-.558a5.898 5.898 0 0 0 2.126-1.384 5.86 5.86 0 0 0 1.384-2.126c.296-.765.499-1.636.558-2.913C23.986 15.668 24 15.259 24 12s-.014-3.668-.072-4.948c-.059-1.277-.262-2.149-.558-2.912a5.892 5.892 0 0 0-1.384-2.126A5.872 5.872 0 0 0 19.861.63c-.764-.297-1.636-.498-2.913-.558C15.668.014 15.259 0 12 0Zm0 5.838a6.162 6.162 0 1 0 0 12.324 6.162 6.162 0 0 0 0-12.324ZM12 16a4 4 0 1 1 0-8 4 4 0 0 1 0 8Zm7.846-10.405a1.441 1.441 0 1 1-2.883 0 1.441 1.441 0 0 1 2.883 0Z"/></svg>',
    ],
    [
        'key' => 'x',
        'label' => 'X',
        'env' => 'VP_SOCIAL_X',
        'icon' => '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path d="M18.901 1.153h3.68l-8.04 9.19L24 22.846h-7.406l-5.8-7.584-6.638 7.584H.474l8.6-9.83L0 1.154h7.594l5.243 6.932ZM17.61 20.644h2.039L6.486 3.24H4.298Z"/></svg>',
    ],
    [
        'key' => 'linkedin',
        'label' => 'LinkedIn',
        'env' => 'VP_SOCIAL_LINKEDIN',
        'icon' => '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286ZM5.337 7.433a2.062 2.062 0 1 1 0-4.124 2.062 2.062 0 0 1 0 4.124Zm1.782 13.019H3.555V9h3.564v11.452ZM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003Z"/></svg>',
    ],
    [
        'key' => 'telegram',
        'label' => 'Telegram',
        'env' => 'VP_SOCIAL_TELEGRAM',
        'icon' => '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0Zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635Z"/></svg>',
    ],
    [
        'key' => 'whatsapp',
        'label' => 'WhatsApp',
        'env' => 'VP_SOCIAL_WHATSAPP',
        'icon' => '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413Z"/></svg>',
    ],
    [
        'key' => 'youtube',
        'label' => 'YouTube',
        'env' => 'VP_SOCIAL_YOUTUBE',
        'icon' => '<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814ZM9.545 15.568V8.432L15.818 12l-6.273 3.568Z"/></svg>',
    ],
];
$socialChannels = [];
foreach ($socialDefinitions as $channel) {
    $url = trim((string) getenv($channel['env']));
    if ($url === '') $url = trim((string) ($socialConfigured[$channel['key']] ?? ''));
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
          <?php /* Icon-only mark: the channel name is carried by aria-label/title,
                   never painted as text, so the row stays a single landscape
                   strip of round glyph buttons instead of wrapping name pills. */ ?>
          <a class="pub-social-button is-<?= e($channel['key']) ?>" href="<?= e($channel['url']) ?>" target="_blank" rel="noopener noreferrer" aria-label="Follow WINDELS on <?= e($channel['label']) ?>" title="<?= e($channel['label']) ?>">
            <?= $channel['icon'] ?>
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
