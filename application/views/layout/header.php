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
$pageTitle = (string) ($title ?? 'WINDELS AI WORKFORCE');
$isAdmin = $identity && in_array('system.super_admin', $identity['permissions'] ?? [], true);
$active = $active ?? '';
$userName = (string) ($identity['display_name'] ?? $identity['email'] ?? 'Member');
$userInitials = strtoupper(mb_substr(preg_replace('/[^A-Za-z0-9 ]/', '', $userName), 0, 1) ?: 'W');
$unread = Aegis_NotificationsHelper::unreadCount();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle . ($seo['title_suffix'] ?? ' · WINDELS AI WORKFORCE')) ?></title>
<meta name="robots" content="noindex,nofollow">
<link rel="icon" type="image/png" href="/assets/images/windels-mark.png">
<link rel="stylesheet" href="/assets/css/aegis.css">
</head>
<body class="app-shell">
<?php $this->load->view('partials/announcement_bar'); ?>
<button class="sidebar-toggle" type="button" aria-expanded="false" aria-label="Open menu"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 6h18M3 12h18M3 18h18"/></svg> Menu</button>
<aside class="sidebar" id="app-sidebar">
  <a class="sidebar-brand" href="/dashboard">
    <img src="/assets/images/windels-mark.png" alt="WINDELS AI WORKFORCE" onerror="this.onerror=null;this.src='/assets/images/aegis-mark.png'">
    <span>WINDELS<small>AI Workforce</small></span>
  </a>
  <p class="sidebar-label">Workspace</p>
  <a href="/dashboard" class="<?= $active === 'home' ? 'active' : '' ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/></svg><span>Dashboard</span></a>
  <a href="/analysis" class="<?= $active === 'dashboard' ? 'active' : '' ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="6" width="16" height="13" rx="2"/><path d="M12 2v4M8.5 12h.01M15.5 12h.01M9 16h6"/></svg><span>AI Workforce</span></a>
  <p class="sidebar-label">Language learning</p>
  <a href="/app/languages/teacher" class="<?= $active === 'teacher' ? 'active' : '' ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 4h13a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H3z"/><path d="m3 4 1 7 1-7M19 5l3 2-3 2"/></svg><span>AI Teacher</span></a>
  <a href="/app/languages" class="<?= $active === 'languages' ? 'active' : '' ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.5 2.5 15 0 18M12 3c-2.5 2.5-2.5 15 0 18"/></svg><span>Languages</span></a>
  <p class="sidebar-label">Trading &amp; research</p>
  <a href="/paper" class="<?= $active === 'paper' ? 'active' : '' ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 7h8M8 11h8M8 15h5"/></svg><span>Paper trading</span></a>
  <a href="/strategy" class="<?= $active === 'strategy' ? 'active' : '' ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m7 14 3-3 3 2 5-6"/></svg><span>Strategy lab</span></a>
  <a href="/journal" class="<?= $active === 'journal' ? 'active' : '' ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M8 17V9M12 17v-5M16 17v-8"/></svg><span>Analytics</span></a>
  <p class="sidebar-label">Operations</p>
  <a href="/execution" class="<?= $active === 'execution' ? 'active' : '' ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg><span>Execution</span></a>
  <a href="/brokers" class="<?= $active === 'brokers' ? 'active' : '' ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M5 21V8l7-4 7 4v13"/><path d="M9 21v-6h6v6"/></svg><span>Brokers</span></a>
  <a href="/risk" class="<?= $active === 'risk' ? 'active' : '' ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 4 6v6c0 4 3.5 7.5 8 9 4.5-1.5 8-5 8-9V6z"/><path d="M9 12l2 2 4-4"/></svg><span>Risk center</span></a>
  <a href="/sports" class="<?= $active === 'sports' ? 'active' : '' ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M3.5 9h17M3.5 15h17M12 3c3 3 3 15 0 18M12 3c-3 3-3 15 0 18"/></svg><span>Sports intel</span></a>
  <a href="/leads" class="<?= $active === 'leads' ? 'active' : '' ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg><span>Lead discovery</span></a>
  <p class="sidebar-label">Account</p>
  <a href="/notifications" class="<?= $active === 'notifications' ? 'active' : '' ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9a6 6 0 0 1 12 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 19a2 2 0 0 0 4 0"/></svg><span>Alerts</span><?php if ($unread > 0): ?> <span class="badge b-red"><?= (int)$unread ?></span><?php endif; ?></a>
  <a href="/account" class="<?= $active === 'account' ? 'active' : '' ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg><span>Account &amp; settings</span></a>
  <?php if ($isAdmin): ?><a href="/admin" class="<?= $active === 'admin' ? 'active' : '' ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 4 5v6c0 5 3.5 8.5 8 11 4.5-2.5 8-6 8-11V5z"/></svg><span>Admin control</span></a><?php endif; ?>
  <?php if ($identity): ?>
    <form method="post" action="/logout" class="sidebar-logout">
      <input type="hidden" name="csrf_token" value="<?= e((string) $ci->session->userdata('csrf_token')) ?>">
      <button class="btn small ghost" type="submit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/></svg> Sign out</button>
    </form>
  <?php endif; ?>
</aside>
<div class="app-main">
<header class="topbar">
  <div class="brand">
    <div>
      <h1><?= e($pageTitle) ?></h1>
      <div class="sub">Signed-in workspace · <?= e($pageTitle === 'Your workspace' ? 'Overview' : 'WINDELS AI WORKFORCE') ?></div>
    </div>
  </div>
  <div class="top-right">
    <span class="statuspill"><?= $ks && !empty($ks['active']) ? '● Kill switch on' : '● Mode ' . e($mode) ?></span>
    <a class="icon-btn" href="/notifications" title="Notifications" aria-label="Notifications">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9a6 6 0 0 1 12 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 19a2 2 0 0 0 4 0"/></svg>
      <?php if ($unread > 0): ?><span class="dot"></span><?php endif; ?>
    </a>
    <div class="profile-wrap">
      <button class="profile" type="button" id="profile-btn" aria-haspopup="true" aria-expanded="false">
        <span class="avatar"><?= e($userInitials) ?></span>
        <span class="who"><b><?= e($userName) ?></b><span><?= $isAdmin ? 'Administrator' : 'Member' ?></span></span>
      </button>
      <div class="profile-menu" id="profile-menu" role="menu">
        <a href="/account" role="menuitem"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg> Account &amp; settings</a>
        <a href="/notifications" role="menuitem"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9a6 6 0 0 1 12 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 19a2 2 0 0 0 4 0"/></svg> Notifications<?php if ($unread > 0): ?> (<?= (int)$unread ?>)<?php endif; ?></a>
        <?php if ($isAdmin): ?><a href="/admin" role="menuitem"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 4 5v6c0 5 3.5 8.5 8 11 4.5-2.5 8-6 8-11V5z"/></svg> Admin control</a><?php endif; ?>
        <div class="sep"></div>
        <?php if ($identity): ?>
          <form method="post" action="/logout">
            <input type="hidden" name="csrf_token" value="<?= e((string) $ci->session->userdata('csrf_token')) ?>">
            <button type="submit" role="menuitem"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/></svg> Sign out</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</header>
<main class="wrap">
