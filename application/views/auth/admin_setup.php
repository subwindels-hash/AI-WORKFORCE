<?php
defined('BASEPATH') or exit('No direct script access allowed');
if (!function_exists('e')) {
    function e($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
}
$this->load->view('auth/layout/header', ['title' => 'Create the platform administrator', 'active' => 'login']);
?>
  <main class="auth-shell auth-split">
    <!-- LEFT — WINDELS AI WORKFORCE visual -->
    <section class="auth-visual" aria-hidden="true">
      <div class="auth-visual-frame">
        <img src="/assets/images/hero-windels.jpg" alt="WINDELS AI WORKFORCE — AI language teacher, market analysis and lead discovery" class="auth-visual-img" loading="lazy"
             onerror="this.style.display='none';this.nextElementSibling.style.display='grid';">
        <div class="auth-visual-fallback"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="6" width="16" height="13" rx="2"/><path d="M12 2v4M8.5 12h.01M15.5 12h.01M9 16h6"/></svg></div>
      </div>
      <div class="auth-visual-copy">
        <p class="eyebrow">WINDELS AI WORKFORCE</p>
        <h2>First-run setup</h2>
        <p>No administrator exists in this database yet. Create the first platform administrator right here in your browser — no terminal, no CLI command.</p>
      </div>
    </section>

    <!-- RIGHT — Setup form -->
    <section class="auth-card">
      <div class="auth-brand">
        <img src="/assets/images/windels-mark.png" alt="" class="auth-brand-mark" onerror="this.onerror=null;this.src='/assets/images/ai_workforce-mark.png'">
        <span class="auth-brand-text">WINDELS AI Workforce</span>
      </div>
      <h1>Create the platform administrator</h1>
      <p class="auth-sub">One-time setup — this page is shown only until the database has its first administrator.</p>

      <?php if (!empty($error)): ?><div class="notice err auth-notice" role="alert"><?= e($error) ?></div><?php endif; ?>
      <?php if (!empty($notice)): ?><div class="notice ok auth-notice" role="status"><?= e($notice) ?></div><?php endif; ?>

      <form method="post" action="/login/submit" class="auth-form" id="setup-form" autocomplete="on" novalidate>
        <input type="hidden" name="admin" value="1">
        <input type="hidden" name="setup" value="1">
        <input type="hidden" name="csrf_token" value="<?= e((string) ($csrfToken ?? '')) ?>">

        <label class="auth-field">
          <span>Full name <small style="font-weight:400">(optional)</small></span>
          <span class="auth-control">
            <input type="text" name="display_name" id="setup-name" autocomplete="name" placeholder="Platform Administrator">
          </span>
        </label>

        <label class="auth-field">
          <span>Administrator email</span>
          <span class="auth-control">
            <input type="email" name="email" id="setup-email" required autocomplete="email" placeholder="you@example.com">
          </span>
        </label>

        <label class="auth-field">
          <span>Password</span>
          <span class="auth-control has-toggle">
            <input type="password" name="password" id="setup-password" required autocomplete="new-password" minlength="12" placeholder="At least 12 characters">
            <button type="button" class="pw-toggle" data-toggle="setup-password" aria-label="Show password" title="Show password">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-6.5 10-6.5S22 12 22 12s-3.5 6.5-10 6.5S2 12 2 12z"/><circle cx="12" cy="12" r="2.6"/></svg>
            </button>
          </span>
        </label>

        <label class="auth-field">
          <span>Confirm password</span>
          <span class="auth-control has-toggle">
            <input type="password" name="password_confirm" id="setup-password-confirm" required autocomplete="new-password" minlength="12" placeholder="Repeat the password">
            <button type="button" class="pw-toggle" data-toggle="setup-password-confirm" aria-label="Show password" title="Show password">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-6.5 10-6.5S22 12 22 12s-3.5 6.5-10 6.5S2 12 2 12z"/><circle cx="12" cy="12" r="2.6"/></svg>
            </button>
          </span>
        </label>

        <div id="setup-inline-error" class="notice err" role="alert" hidden></div>

        <button class="btn primary auth-submit" type="submit" id="setup-submit">Create administrator</button>
      </form>

      <p class="auth-sub" style="margin-top:14px">After setup, sign in on the <a href="/admin/login">administrator login</a> with these credentials. You can manage further administrators, their roles and passwords from the Admin Portal — and from now on this setup page is no longer reachable.</p>

      <a class="auth-back" href="/" style="display:inline-block;margin-top:10px">← Back to website</a>
    </section>
  </main>
  <script>
  (function () {
    'use strict';
    var form = document.getElementById('setup-form');
    var submit = document.getElementById('setup-submit');
    var inlineError = document.getElementById('setup-inline-error');

    document.querySelectorAll('.pw-toggle').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var input = document.getElementById(btn.getAttribute('data-toggle'));
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        btn.title = show ? 'Hide password' : 'Show password';
      });
    });

    form.addEventListener('submit', function (event) {
      var password = document.getElementById('setup-password');
      var confirm = document.getElementById('setup-password-confirm');
      if (!password.value || password.value !== confirm.value) {
        event.preventDefault();
        inlineError.hidden = false;
        inlineError.textContent = 'The two passwords do not match.';
        confirm.focus();
        return;
      }
      if (password.value.length < 12) {
        event.preventDefault();
        inlineError.hidden = false;
        inlineError.textContent = 'Use a password of at least 12 characters.';
        password.focus();
        return;
      }
      inlineError.hidden = true;
      submit.disabled = true;
      submit.classList.add('is-loading');
      submit.innerHTML = '<span class="auth-spinner"></span> Creating…';
    });
  })();
  </script>
<?php $this->load->view('auth/layout/footer'); ?>
