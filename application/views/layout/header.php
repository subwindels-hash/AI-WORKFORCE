<?php
defined('BASEPATH') or exit('No direct script access allowed');
/** @var string $title @var string $active @var array $status */
if (!function_exists('e')) {
    function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
$status = $status ?? null;
$mode = $status['tradingMode'] ?? '…';
$ks = $status['killSwitch'] ?? null;
$ci = get_instance();
$identity = $ci->session->userdata('identity');
$identity = is_array($identity) ? $identity : null;
$ci->config->load('seo', true);
$seo = $ci->config->item('settings', 'seo') ?: [];
$pageTitle = (string) ($title ?? 'Africa Mobility');
$isAdmin = $identity && in_array('system.super_admin', $identity['permissions'] ?? [], true);
$active = $active ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle . ($seo['title_suffix'] ?? ' · Africa Mobility')) ?></title>
<meta name="robots" content="noindex,nofollow">
<link rel="icon" type="image/png" href="/assets/images/aegis-mark.png">
<link rel="stylesheet" href="/assets/css/aegis.css">
</head>
<body class="app-shell">
<button class="sidebar-toggle" type="button" aria-expanded="false">Menu</button>
<aside class="sidebar" id="app-sidebar">
  <a class="sidebar-brand" href="/dashboard">
    <img src="/assets/images/aegis-mark.png" alt="">
    <span>Africa Mobility</span>
  </a>
  <p class="sidebar-label">Workspace</p>
  <a href="/dashboard" class="<?= $active === 'home' ? 'active' : '' ?>">Overview</a>
  <a href="/analysis" class="<?= $active === 'dashboard' ? 'active' : '' ?>">Analysis</a>
  <p class="sidebar-label">My activity</p>
  <a href="/paper" class="<?= $active === 'paper' ? 'active' : '' ?>">Paper trading</a>
  <a href="/strategy" class="<?= $active === 'strategy' ? 'active' : '' ?>">Strategy lab</a>
  <a href="/journal" class="<?= $active === 'journal' ? 'active' : '' ?>">Journal</a>
  <a href="/app/languages" class="<?= $active === 'languages' ? 'active' : '' ?>">Languages</a>
  <a href="/sports" class="<?= $active === 'sports' ? 'active' : '' ?>">Sports</a>
  <a href="/leads" class="<?= $active === 'leads' ? 'active' : '' ?>">Leads</a>
  <p class="sidebar-label">Operations</p>
  <a href="/execution" class="<?= $active === 'execution' ? 'active' : '' ?>">Execution</a>
  <a href="/brokers" class="<?= $active === 'brokers' ? 'active' : '' ?>">Brokers</a>
  <a href="/risk" class="<?= $active === 'risk' ? 'active' : '' ?>">Risk</a>
  <a href="/notifications" class="<?= $active === 'notifications' ? 'active' : '' ?>">Alerts<?php $unread = Aegis_NotificationsHelper::unreadCount(); if ($unread > 0): ?> <span class="badge b-red"><?= (int) $unread ?></span><?php endif; ?></a>
  <p class="sidebar-label">Account</p>
  <a href="/account" class="<?= $active === 'account' ? 'active' : '' ?>">Profile</a>
  <?php if ($isAdmin): ?><a href="/admin" class="<?= $active === 'admin' ? 'active' : '' ?>">Admin</a><?php endif; ?>
  <a href="/">Public site</a>
  <?php if ($identity): ?>
    <form method="post" action="/logout" class="sidebar-logout">
      <input type="hidden" name="csrf_token" value="<?= e((string) $ci->session->userdata('csrf_token')) ?>">
      <button class="btn small" type="submit">Logout</button>
    </form>
  <?php endif; ?>
</aside>
<div class="app-main">
<header class="topbar">
  <div class="brand">
    <div>
      <h1><?= e($pageTitle) ?></h1>
      <div class="sub">Signed-in workspace · <?= e((string) ($identity['email'] ?? '')) ?></div>
    </div>
  </div>
  <div class="top-right">
    <span class="badge b-violet">MODE: <?= e($mode) ?></span>
    <?php if (!empty($ks['active'])): ?>
      <span class="badge b-red">KILL SWITCH ACTIVE</span>
    <?php else: ?>
      <span class="badge b-green">kill switch off</span>
    <?php endif; ?>
  </div>
</header>
<main class="wrap">
