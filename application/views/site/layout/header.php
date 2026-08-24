<?php
defined('BASEPATH') or exit('No direct script access allowed');
if (!function_exists('e')) {
    function e($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
}
$ci = get_instance();
$ci->config->load('seo', true);
$seo = $ci->config->item('settings', 'seo') ?: [];
$pageTitle = (string) ($title ?? 'Africa Mobility');
$user = $user ?? null;
$active = $active ?? 'home';
$nav = [
    'home' => ['/', 'Home'],
    'about' => ['/about', 'About'],
    'services' => ['/services', 'Services'],
    'how' => ['/how-it-works', 'How it works'],
    'locations' => ['/locations', 'Coverage'],
    'safety' => ['/safety', 'Safety'],
    'faq' => ['/faq', 'FAQ'],
    'contact' => ['/contact', 'Contact'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle . ($seo['title_suffix'] ?? ' · Africa Mobility')) ?></title>
<meta name="description" content="<?= e((string) ($seo['description'] ?? '')) ?>">
<meta name="robots" content="<?= e((string) ($seo['robots'] ?? 'index,follow')) ?>">
<link rel="icon" type="image/png" href="/assets/images/aegis-mark.png">
<link rel="stylesheet" href="/assets/css/public.css">
</head>
<body class="public">
<header class="pub-nav">
  <a class="pub-brand" href="/">
    <img src="/assets/images/aegis-mark.png" alt="">
    <span>Africa Mobility</span>
  </a>
  <button class="pub-toggle" type="button" aria-expanded="false" aria-controls="pub-menu">Menu</button>
  <nav id="pub-menu" class="pub-links">
    <?php foreach ($nav as $key => [$href, $label]): ?>
      <a href="<?= e($href) ?>" class="<?= $active === $key ? 'is-active' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="pub-actions">
    <?php if ($user): ?>
      <a class="btn ghost" href="/dashboard">Workspace</a>
      <?php if (!empty($user['permissions']) && in_array('system.super_admin', $user['permissions'], true)): ?>
        <a class="btn ghost" href="/admin">Admin</a>
      <?php endif; ?>
    <?php else: ?>
      <a class="btn ghost" href="/login">Login</a>
      <a class="btn solid" href="/register">Get started</a>
    <?php endif; ?>
  </div>
</header>
