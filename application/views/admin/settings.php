<?php defined('BASEPATH') or exit('No direct script access allowed');
$set = $settings ?? [];
$general = $set['general'] ?? [];
$ai = $set['ai'] ?? [];
$security = $set['security'] ?? [];
$accounts = $set['accounts'] ?? [];
$seo = $set['seo'] ?? [];
$smtp = $smtp ?? [];
?>
<div class="page-head">
  <div>
    <p class="eyebrow">Administration</p>
    <h2>System Settings</h2>
    <p>Settings are grouped by category. SMTP passwords and API keys stay in the server environment and are never shown here.</p>
  </div>
</div>

<section class="panel" id="general">
  <h3>General</h3>
  <div class="body">
    <form method="post" action="/admin/settings/save" class="admin-form">
      <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
      <input type="hidden" name="category" value="general">
      <label>Product name<input name="product_name" maxlength="120" value="<?= e($general['product_name'] ?? 'WINDELS AI WORKFORCE') ?>"></label>
      <label>Contact name<input name="contact_name" maxlength="120" value="<?= e($general['contact_name'] ?? '') ?>"></label>
      <label>Contact email<input type="email" name="contact_email" maxlength="190" value="<?= e($general['contact_email'] ?? '') ?>"></label>
      <button class="btn primary" type="submit">Save general</button>
    </form>
  </div>
</section>

<section class="panel" id="ai" style="margin-top:14px">
  <h3>AI</h3>
  <div class="body">
    <p class="dim">Feature flags stored for operators. They do not invent usage numbers.</p>
    <form method="post" action="/admin/settings/save" class="admin-form">
      <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
      <input type="hidden" name="category" value="ai">
      <label class="choice"><input type="checkbox" name="ai_analysis_enabled" value="1" <?= ($ai['ai_analysis_enabled'] ?? '1') === '1' ? 'checked' : '' ?>> AI analysis marked available</label>
      <label class="choice"><input type="checkbox" name="language_learning_enabled" value="1" <?= ($ai['language_learning_enabled'] ?? '1') === '1' ? 'checked' : '' ?>> Language learning marked available</label>
      <button class="btn primary" type="submit">Save AI flags</button>
    </form>
  </div>
</section>

<section class="panel" id="email" style="margin-top:14px">
  <h3>Email</h3>
  <div class="body">
    <p class="dim">Outbound mail is configured on the server only. Credentials are never stored in this form or returned to the browser.</p>
    <div class="stat-grid" style="margin:10px 0">
      <div class="stat"><div class="k">Status</div><div class="v"><span class="badge <?= !empty($smtp['enabled']) ? 'b-green' : 'b-gray' ?>"><?= !empty($smtp['enabled']) ? 'ENABLED' : 'DISABLED' ?></span></div></div>
      <div class="stat"><div class="k">Host</div><div class="v" style="font-size:13px"><?= e($smtp['host'] ?? '—') ?></div></div>
      <div class="stat"><div class="k">Port / TLS</div><div class="v" style="font-size:13px"><?= e((string) ($smtp['port'] ?? '—')) ?> · <?= e(strtoupper((string) ($smtp['crypto'] ?? '')) ?: '—') ?></div></div>
      <div class="stat"><div class="k">Auth</div><div class="v"><span class="badge <?= !empty($smtp['usernameConfigured']) && !empty($smtp['passwordConfigured']) ? 'b-green' : 'b-gray' ?>"><?= (!empty($smtp['usernameConfigured']) && !empty($smtp['passwordConfigured'])) ? 'READY' : 'MISSING' ?></span></div></div>
    </div>
    <?php if (!empty($smtpOk)): ?><div class="notice ok"><?= e($smtpOk) ?></div><?php endif; ?>
    <?php if (!empty($smtpError)): ?><div class="notice err"><?= e($smtpError) ?></div><?php endif; ?>
    <form method="post" action="/admin/test-email" class="admin-form">
      <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
      <label>Send a test email to<input type="email" name="to" required placeholder="you@example.com"></label>
      <label>Format<select name="variant"><option value="html">HTML</option><option value="plain">Plain text</option></select></label>
      <button class="btn primary" type="submit">Send test email</button>
    </form>
  </div>
</section>

<section class="panel" id="security" style="margin-top:14px">
  <h3>Security</h3>
  <div class="body">
    <form method="post" action="/admin/settings/save" class="admin-form">
      <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
      <input type="hidden" name="category" value="security">
      <label>Failed login attempts before lockout<input type="number" name="login_max_attempts" min="3" max="20" value="<?= e($security['login_max_attempts'] ?? '5') ?>"></label>
      <label>Lockout duration (seconds)<input type="number" name="login_lockout_seconds" min="60" max="86400" value="<?= e($security['login_lockout_seconds'] ?? '900') ?>"></label>
      <button class="btn primary" type="submit">Save security</button>
    </form>
    <p class="dim" style="margin-top:12px">Session cookie: <?= (int) ($session['expiration'] ?? 0) ?>s · HttpOnly <?= !empty($session['httponly']) ? 'on' : 'off' ?> · SameSite <?= e($session['samesite'] ?? '') ?> · Secure <?= !empty($session['secure']) ? 'on' : 'off' ?>. Those values come from server configuration and are not edited here.</p>
  </div>
</section>

<section class="panel" id="accounts" style="margin-top:14px">
  <h3>User Accounts</h3>
  <div class="body">
    <form method="post" action="/admin/settings/save" class="admin-form">
      <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
      <input type="hidden" name="category" value="accounts">
      <label class="choice"><input type="checkbox" name="registration_enabled" value="1" <?= ($accounts['registration_enabled'] ?? '1') === '1' ? 'checked' : '' ?>> Allow public registration</label>
      <button class="btn primary" type="submit">Save account settings</button>
    </form>
  </div>
</section>

<section class="panel" id="seo" style="margin-top:14px">
  <h3>SEO</h3>
  <div class="body">
    <p class="dim">Public-site search settings. Any field left blank falls back to the server default (config/env). These drive the page titles, meta tags, robots.txt and sitemap.xml.</p>
    <form method="post" action="/admin/settings/save" class="admin-form">
      <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
      <input type="hidden" name="category" value="seo">
      <label>Site name<input name="seo_site_name" maxlength="120" placeholder="WINDELS AI WORKFORCE" value="<?= e($seo['seo_site_name'] ?? '') ?>"></label>
      <label>Title suffix (appended to every page title)<input name="seo_title_suffix" maxlength="120" placeholder=" · WINDELS AI WORKFORCE" value="<?= e($seo['seo_title_suffix'] ?? '') ?>"></label>
      <label>Meta description<input name="seo_description" maxlength="500" placeholder="Shown on search result pages (about 150 characters)" value="<?= e($seo['seo_description'] ?? '') ?>"></label>
      <label>Meta keywords (comma separated)<input name="seo_keywords" maxlength="500" placeholder="Leave blank to use the server default" value="<?= e($seo['seo_keywords'] ?? '') ?>"></label>
      <label>Crawl directive<select name="seo_robots">
        <option value="">Server default</option>
        <?php foreach (['index,follow' => 'index,follow — index everything', 'noindex,follow' => 'noindex,follow — hide from search, follow links', 'index,nofollow' => 'index,nofollow — index, ignore links', 'noindex,nofollow' => 'noindex,nofollow — hide completely'] as $v => $label): ?>
        <option value="<?= e($v) ?>" <?= ($seo['seo_robots'] ?? '') === $v ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select></label>
      <label>Canonical base URL (also used for sitemap.xml)<input name="seo_canonical" maxlength="190" placeholder="https://example.com/" value="<?= e($seo['seo_canonical'] ?? '') ?>"></label>
      <label>Social share image URL (og:image)<input name="seo_og_image" maxlength="500" placeholder="https://example.com/assets/images/share.png" value="<?= e($seo['seo_og_image'] ?? '') ?>"></label>
      <label>Browser theme color<input name="seo_theme_color" maxlength="20" placeholder="#07090e" value="<?= e($seo['seo_theme_color'] ?? '') ?>"></label>
      <button class="btn primary" type="submit">Save SEO</button>
    </form>
  </div>
</section>
