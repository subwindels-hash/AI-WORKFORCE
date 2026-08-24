<?php defined('BASEPATH') or exit('No direct script access allowed'); if (!function_exists('e')) { function e($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); } } ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <?php $ci = get_instance(); $ci->config->load('seo', true); $seoAuth = $ci->config->item('settings', 'seo') ?: []; ?>
  <title><?= e($title . ($seoAuth['title_suffix'] ?? ' · WINDELS AI WORKFORCE')) ?></title>
  <meta name="description" content="<?= e((string) ($seoAuth['description'] ?? '')) ?>"><meta name="robots" content="noindex,nofollow">
  <link rel="icon" href="/assets/images/windels-mark.png">
  <link rel="stylesheet" href="/assets/css/aegis.css">
</head>
<body class="auth-page">
  <?php $this->load->view('partials/announcement_bar'); ?>
  <main class="auth-shell">
    <section class="auth-art">
      <img src="/assets/images/windels-mark.png" alt="WINDELS AI WORKFORCE" class="auth-logo" onerror="this.onerror=null;this.src='/assets/images/aegis-mark.png'">
      <p class="eyebrow">WINDELS AI WORKFORCE</p>
      <h1><?= $admin ? 'Control every decision.' : 'Your AI-powered workforce workspace.' ?></h1>
      <p><?= $admin ? 'Administrator access for identity, risk, provider and platform controls.' : 'One secure workspace for the AI language teacher, market analysis, sports intelligence and lead discovery.' ?></p>
      <div class="auth-art-note"><span class="dot up"></span> Evidence-first · audited · fail-closed</div>
    </section>
    <section class="auth-card">
      <div class="auth-brand"><img src="/assets/images/windels-mark.png" alt="WINDELS AI WORKFORCE" class="auth-brand-mark" onerror="this.onerror=null;this.src='/assets/images/aegis-mark.png'"><div><strong>WINDELS AI WORKFORCE</strong><small>Secure workspace</small></div></div>
      <p class="eyebrow"><?= $admin ? 'Restricted access' : 'User portal' ?></p>
      <h2><?= $admin ? 'Restricted sign in' : 'Welcome back' ?></h2>
      <p class="dim">Use your platform account to continue. Your session is protected by the configured encryption key.</p>
      <?php if (!empty($error)): ?><div class="notice err" role="alert"><?= e($error) ?></div><?php endif; ?>
      <form method="post" action="/login/submit" class="auth-form" autocomplete="on">
        <input type="hidden" name="admin" value="<?= $admin ? '1' : '0' ?>">
        <label>Email address<input type="email" name="email" required autocomplete="username" placeholder="you@example.com"></label>
        <label>Password<input type="password" name="password" required autocomplete="current-password" placeholder="Your password"></label>
        <button class="btn primary auth-submit" type="submit"><?= $admin ? 'Enter' : 'Sign in' ?></button>
      </form>
      <div class="auth-links">
        <a href="/register">Create account</a>
        <a href="/forgot-password">Forgot password</a>
      </div>
      <div class="auth-links">
        <a href="/">Back to website</a>
      </div>
      <p class="auth-footnote">New to WINDELS AI WORKFORCE? Create a member account to open your workspace. Administrator access is handled separately and is never advertised on the public site.</p>
    </section>
  </main>
  <?php $this->load->view('partials/chat_widget'); ?>
</body>
</html>
