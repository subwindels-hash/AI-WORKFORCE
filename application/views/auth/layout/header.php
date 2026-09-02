<?php
defined('BASEPATH') or exit('No direct script access allowed');
/**
 * Shared chrome for the standalone auth pages (login, register, forgot,
 * denied, goodbye). Renders the same primary menu as the public site so the
 * navigation and footer are present on every page of the application.
 *
 * Self-contained: it resolves the signed-in user itself, so controllers do
 * not have to pass extra data.
 */
if (!function_exists('e')) {
    function e($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
}
$ci = get_instance();
$authUser = $ci->session->userdata('identity');
$authUser = is_array($authUser) ? $authUser : null;
$pageTitle = (string) ($title ?? 'WINDELS AI WORKFORCE');
$bodyClass = trim('auth-page ' . (string) ($bodyClass ?? ''));
$authNav = [
    'home' => ['/', 'Home'],
    'about' => ['/about', 'About'],
    'services' => ['/services', 'Services'],
    'how' => ['/how-it-works', 'How it works'],
    'locations' => ['/locations', 'Coverage'],
    'safety' => ['/safety', 'Safety'],
    'faq' => ['/faq', 'FAQ'],
    'contact' => ['/contact', 'Contact'],
];
$authActive = (string) ($active ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle . ' · WINDELS AI WORKFORCE') ?></title>
<meta name="robots" content="noindex,nofollow">
<link rel="icon" type="image/png" href="/assets/images/windels-mark.png">
<link rel="stylesheet" href="/assets/css/ai_workforce.css">
</head>
<body class="<?= e($bodyClass) ?>">
<?php $ci->load->view('partials/announcement_bar'); ?>
<header class="auth-nav" id="auth-nav">
  <a class="auth-nav-brand" href="/">
    <img src="/assets/images/windels-mark.png" alt="WINDELS AI WORKFORCE logo" onerror="this.onerror=null;this.src='/assets/images/ai_workforce-mark.png'">
    <span>WINDELS AI WORKFORCE</span>
  </a>
  <button class="auth-nav-toggle" type="button" aria-expanded="false" aria-controls="auth-nav-menu">Menu</button>
  <nav id="auth-nav-menu" class="auth-nav-links" aria-label="Primary">
    <?php foreach ($authNav as $key => [$href, $label]): ?>
      <a href="<?= e($href) ?>" class="<?= $authActive === $key ? 'is-active' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="auth-nav-actions">
    <?php if ($authUser): ?>
      <a class="btn primary" href="/dashboard">My workspace</a>
    <?php else: ?>
      <a class="btn ghost<?= $authActive === 'login' ? ' is-active' : '' ?>" href="/login">Login</a>
      <a class="btn primary<?= $authActive === 'register' ? ' is-active' : '' ?>" href="/register">Get started</a>
    <?php endif; ?>
  </div>
</header>
