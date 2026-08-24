<?php defined('BASEPATH') or exit('No direct script access allowed'); if (!function_exists('e')) { function e($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); } } ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title . ' · WINDELS AI WORKFORCE') ?></title>
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
      <h1><?= $admin ? 'Administrator sign in' : 'Sign in' ?></h1>
      <p class="auth-sub"><?= $admin ? 'Restricted access for platform administrators.' : 'Sign in to open your WINDELS workspace.' ?></p>

      <?php if (!empty($error)): ?><div class="notice err auth-notice" role="alert"><?= e($error) ?></div><?php endif; ?>
      <?php if (!empty($notice)): ?><div class="notice ok auth-notice" role="status"><?= e($notice) ?></div><?php endif; ?>

      <form method="post" action="/login/submit" class="auth-form" id="login-form" autocomplete="on" novalidate>
        <input type="hidden" name="admin" value="<?= $admin ? '1' : '0' ?>">
        <input type="hidden" name="csrf_token" value="<?= e((string) ($csrfToken ?? '')) ?>">

        <label class="auth-field">
          <span>Email address</span>
          <span class="auth-control">
            <input type="email" name="email" id="login-email" required autocomplete="username" placeholder="you@example.com" inputmode="email">
          </span>
        </label>

        <label class="auth-field">
          <span>Password</span>
          <span class="auth-control has-toggle">
            <input type="password" name="password" id="login-password" required autocomplete="current-password" placeholder="Your password">
            <button type="button" class="pw-toggle" data-toggle="login-password" aria-label="Show password" title="Show password">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-6.5 10-6.5S22 12 22 12s-3.5 6.5-10 6.5S2 12 2 12z"/><circle cx="12" cy="12" r="2.6"/></svg>
            </button>
          </span>
        </label>

        <div class="auth-row">
          <label class="auth-check"><input type="checkbox" name="remember" value="1"> Remember me</label>
          <a href="/forgot-password">Forgot password?</a>
        </div>

        <div id="login-inline-error" class="notice err" role="alert" hidden></div>

        <button class="btn primary auth-submit" type="submit" id="login-submit"><?= $admin ? 'Enter' : 'Sign in' ?></button>
      </form>

      <div class="auth-foot">
        Don't have an account? <a href="/register"><b>Create an account</b></a>
      </div>
      <a class="auth-back" href="/">← Back to website</a>
    </section>
  </main>
  <script>
  (function () {
    'use strict';
    var form = document.getElementById('login-form');
    var submit = document.getElementById('login-submit');
    var inlineError = document.getElementById('login-inline-error');

    // Password visibility toggle.
    document.querySelectorAll('.pw-toggle').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var input = document.getElementById(btn.getAttribute('data-toggle'));
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        btn.title = show ? 'Hide password' : 'Show password';
      });
    });

    // Client-side validation + loading state.
    form.addEventListener('submit', function (event) {
      var email = document.getElementById('login-email');
      var password = document.getElementById('login-password');
      if (!email.value.trim() || !password.value) {
        event.preventDefault();
        inlineError.hidden = false;
        inlineError.textContent = !email.value.trim() ? 'Enter your email address.' : 'Enter your password.';
        (!email.value.trim() ? email : password).focus();
        return;
      }
      inlineError.hidden = true;
      submit.disabled = true;
      submit.classList.add('is-loading');
      submit.innerHTML = '<span class="auth-spinner"></span> Signing in…';
    });
  })();
  </script>
</body>
</html>
