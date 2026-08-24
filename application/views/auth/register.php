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
  <?php $this->load->view('partials/announcement_bar'); ?>
  <main class="auth-shell">
    <section class="auth-art">
      <img src="/assets/images/windels-mark.png" alt="WINDELS AI WORKFORCE" class="auth-logo" onerror="this.onerror=null;this.src='/assets/images/aegis-mark.png'">
      <p class="eyebrow">WINDELS AI WORKFORCE</p>
      <h1>Open your AI workforce workspace.</h1>
      <p>New accounts receive the platform_member role. Dashboards stay closed until this form succeeds.</p>
    </section>
    <section class="auth-card">
      <div class="auth-brand"><img src="/assets/images/windels-mark.png" alt="WINDELS AI WORKFORCE" class="auth-brand-mark" onerror="this.onerror=null;this.src='/assets/images/aegis-mark.png'"><div><strong>WINDELS AI WORKFORCE</strong><small>Create account</small></div></div>
      <h2>Register</h2>
      <?php if (!empty($error)): ?><div class="notice err" role="alert"><?= e($error) ?></div><?php endif; ?>
      <form method="post" action="/register/submit" class="auth-form">
        <label>Full name<input name="display_name" required maxlength="120" autocomplete="name"></label>
        <label>Email<input type="email" name="email" required maxlength="190" autocomplete="email"></label>
        <label>Password<input type="password" name="password" required minlength="12" autocomplete="new-password"></label>
        <label>Confirm password<input type="password" name="password_confirm" required minlength="12" autocomplete="new-password"></label>
        <button class="btn primary auth-submit" type="submit">Create account</button>
      </form>
      <div class="auth-links"><a href="/login">Already have an account</a><a href="/">Back to site</a></div>
    </section>
  </main>
</body>
</html>
