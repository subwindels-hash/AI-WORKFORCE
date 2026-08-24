<?php defined('BASEPATH') or exit('No direct script access allowed'); if (!function_exists('e')) { function e($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); } } ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e(($title ?? 'Register') . ' · WINDELS AI WORKFORCE') ?></title>
  <meta name="robots" content="noindex,nofollow">
  <link rel="icon" href="/assets/images/windels-mark.png">
  <link rel="stylesheet" href="/assets/css/aegis.css">
</head>
<body class="auth-page">
  <main class="auth-shell">
    <section class="auth-card">
      <div class="auth-brand">
        <img src="/assets/images/windels-mark.png" alt="" class="auth-brand-mark" onerror="this.onerror=null;this.src='/assets/images/aegis-mark.png'">
        <span class="auth-brand-text">WINDELS AI Workforce</span>
      </div>
      <h1>Create your account</h1>
      <p class="auth-sub">Create your account to get started.</p>

      <?php if (!empty($error)): ?><div class="notice err auth-notice" role="alert"><?= e($error) ?></div><?php endif; ?>
      <?php if (!empty($notice)): ?><div class="notice ok auth-notice" role="status"><?= e($notice) ?></div><?php endif; ?>

      <form method="post" action="/register/submit" class="auth-form" id="register-form" autocomplete="on" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e((string) ($csrfToken ?? '')) ?>">

        <label class="auth-field">
          <span>Full name</span>
          <span class="auth-control">
            <input name="display_name" id="reg-name" required maxlength="120" autocomplete="name" placeholder="Your full name">
          </span>
        </label>

        <label class="auth-field">
          <span>Email address</span>
          <span class="auth-control">
            <input type="email" name="email" id="reg-email" required maxlength="190" autocomplete="email" placeholder="you@example.com" inputmode="email">
          </span>
        </label>

        <label class="auth-field">
          <span>Password</span>
          <span class="auth-control has-toggle">
            <input type="password" name="password" id="reg-password" required minlength="12" autocomplete="new-password" placeholder="At least 12 characters">
            <button type="button" class="pw-toggle" data-toggle="reg-password" aria-label="Show password" title="Show password">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-6.5 10-6.5S22 12 22 12s-3.5 6.5-10 6.5S2 12 2 12z"/><circle cx="12" cy="12" r="2.6"/></svg>
            </button>
          </span>
          <span class="auth-hint">Use at least 12 characters.</span>
        </label>

        <label class="auth-field">
          <span>Confirm password</span>
          <span class="auth-control has-toggle">
            <input type="password" name="password_confirm" id="reg-confirm" required minlength="12" autocomplete="new-password" placeholder="Repeat your password">
            <button type="button" class="pw-toggle" data-toggle="reg-confirm" aria-label="Show password" title="Show password">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-6.5 10-6.5S22 12 22 12s-3.5 6.5-10 6.5S2 12 2 12z"/><circle cx="12" cy="12" r="2.6"/></svg>
            </button>
          </span>
        </label>

        <label class="auth-check">
          <input type="checkbox" name="terms" id="reg-terms" value="1" required>
          <span>I agree to the Terms and Privacy Policy</span>
        </label>

        <div id="register-inline-error" class="notice err" role="alert" hidden></div>

        <button class="btn primary auth-submit" type="submit" id="register-submit">Create account</button>
      </form>

      <div class="auth-foot">
        Already have an account? <a href="/login"><b>Sign in</b></a>
      </div>
      <a class="auth-back" href="/">← Back to website</a>
    </section>
  </main>
  <script>
  (function () {
    'use strict';
    var form = document.getElementById('register-form');
    var submit = document.getElementById('register-submit');
    var inlineError = document.getElementById('register-inline-error');

    // Password visibility toggles.
    document.querySelectorAll('.pw-toggle').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var input = document.getElementById(btn.getAttribute('data-toggle'));
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        btn.title = show ? 'Hide password' : 'Show password';
      });
    });

    function fail(message, focusEl) {
      inlineError.hidden = false;
      inlineError.textContent = message;
      if (focusEl) focusEl.focus();
      return false;
    }

    // Client-side validation + loading state.
    form.addEventListener('submit', function (event) {
      var name = document.getElementById('reg-name');
      var email = document.getElementById('reg-email');
      var password = document.getElementById('reg-password');
      var confirm = document.getElementById('reg-confirm');
      var terms = document.getElementById('reg-terms');
      inlineError.hidden = true;
      var ok = true;
      if (!name.value.trim()) ok = fail('Enter your full name.', name);
      else if (!email.value.trim()) ok = fail('Enter your email address.', email);
      else if (password.value.length < 12) ok = fail('Your password must be at least 12 characters.', password);
      else if (password.value !== confirm.value) ok = fail('The two passwords do not match.', confirm);
      else if (!terms.checked) ok = fail('Please accept the Terms and Privacy Policy.', terms);
      if (!ok) { event.preventDefault(); return; }
      submit.disabled = true;
      submit.classList.add('is-loading');
      submit.innerHTML = '<span class="auth-spinner"></span> Creating account…';
    });
  })();
  </script>
</body>
</html>
