<?php defined('BASEPATH') or exit('No direct script access allowed'); if (!function_exists('e')) { function e($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); } } ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <?php $ci = get_instance(); $ci->config->load('seo', true); $seoAuth = $ci->config->item('settings', 'seo') ?: []; ?>
  <title><?= e($title . ($seoAuth['title_suffix'] ?? ' · AEGIS')) ?></title>
  <meta name="description" content="<?= e((string) ($seoAuth['description'] ?? '')) ?>"><meta name="robots" content="noindex,nofollow">
  <link rel="icon" href="/assets/images/aegis-mark.png">
  <link rel="stylesheet" href="/assets/css/aegis.css">
</head>
<body class="auth-page">
  <main class="auth-shell">
    <section class="auth-art">
      <img src="/assets/images/aegis-mark.png" alt="AEGIS" class="auth-logo">
      <p class="eyebrow">Africa Mobility</p>
      <h1><?= $admin ? 'Control every decision.' : 'Make better decisions with evidence.' ?></h1>
      <p><?= $admin ? 'Administrator access for identity, risk, provider and platform controls.' : 'One secure workspace for market analysis, language learning, sports intelligence and lead discovery.' ?></p>
      <div class="auth-art-note"><span class="dot up"></span> Evidence-first · audited · fail-closed</div>
    </section>
    <section class="auth-card">
      <div class="auth-brand"><img src="/assets/images/aegis-mark.png" alt="" class="auth-brand-mark"><div><strong>Africa Mobility</strong><small>Secure workspace</small></div></div>
      <p class="eyebrow"><?= $admin ? 'Administrator portal' : 'User portal' ?></p>
      <h2><?= $admin ? 'Administrator sign in' : 'Welcome back' ?></h2>
      <p class="dim">Use your platform account to continue. Your session is protected by the configured encryption key.</p>
      <?php if (!empty($error)): ?><div class="notice err" role="alert"><?= e($error) ?></div><?php endif; ?>
      <form method="post" action="/login/submit" class="auth-form" autocomplete="on">
        <input type="hidden" name="admin" value="<?= $admin ? '1' : '0' ?>">
        <label>Email address<input type="email" name="email" required autocomplete="username" placeholder="you@example.com"></label>
        <label>Password<input type="password" name="password" required autocomplete="current-password" placeholder="Your password"></label>
        <button class="btn primary auth-submit" type="submit"><?= $admin ? 'Enter admin control' : 'Sign in' ?></button>
      </form>
      <div class="auth-links">
        <?php if ($admin): ?><a href="/login">User sign in</a><?php else: ?><a href="/register">Create account</a><?php endif; ?>
        <a href="/forgot-password">Forgot password</a>
      </div>
      <div class="auth-links">
        <?php if ($admin): ?><a href="/register">Create account</a><?php else: ?><a href="/admin/login">Administrator sign in</a><?php endif; ?>
        <a href="/">Back to website</a>
      </div>
      <p class="auth-footnote">Initial deployments include a documented administrator account. Change its password immediately after the first sign in.</p>
    </section>
  </main>
  <?php $this->load->view('partials/chat_widget'); ?>
</body>
</html>
