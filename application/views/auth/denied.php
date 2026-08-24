<?php defined('BASEPATH') or exit('No direct script access allowed'); if (!function_exists('e')) { function e($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); } } ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Access denied · WINDELS AI WORKFORCE</title>
  <meta name="robots" content="noindex,nofollow">
  <link rel="icon" href="/assets/images/windels-mark.png">
  <link rel="stylesheet" href="/assets/css/aegis.css">
</head>
<body class="auth-page">
  <?php $this->load->view('partials/announcement_bar'); ?>
  <main class="auth-shell">
    <section class="auth-card" style="grid-column:1/-1;max-width:560px;margin:0 auto">
      <p class="eyebrow">Restricted</p>
      <h2>Access denied</h2>
      <p class="dim">Your signed-in account does not have administrator permission. The admin dashboard stays closed.</p>
      <div class="auth-links"><a href="/dashboard">User dashboard</a><a href="/">Public site</a></div>
    </section>
  </main>
</body>
</html>
