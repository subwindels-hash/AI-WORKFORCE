<?php defined('BASEPATH') or exit('No direct script access allowed'); if (!function_exists('e')) { function e($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); } } ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Reset password · Africa Mobility</title>
  <meta name="robots" content="noindex,nofollow">
  <link rel="icon" href="/assets/images/aegis-mark.png">
  <link rel="stylesheet" href="/assets/css/aegis.css">
</head>
<body class="auth-page">
  <main class="auth-shell">
    <section class="auth-card" style="grid-column:1/-1;max-width:520px;margin:0 auto">
      <h2>Password reset</h2>
      <p class="dim">This installation does not invent email reset tokens. Ask an administrator to issue a new password or create a replacement account.</p>
      <?php if (!empty($notice)): ?><div class="notice ok"><?= e($notice) ?></div><?php endif; ?>
      <form method="post" action="/forgot-password/submit" class="auth-form">
        <label>Email<input type="email" name="email" required></label>
        <button class="btn primary auth-submit" type="submit">Send request</button>
      </form>
      <div class="auth-links"><a href="/login">Back to login</a><a href="/contact">Contact</a></div>
    </section>
  </main>
</body>
</html>
