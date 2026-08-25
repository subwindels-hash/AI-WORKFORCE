<?php
defined('BASEPATH') or exit('No direct script access allowed');
/** @var string $title @var string $active @var array $status */
if (!function_exists('e')) {
    function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('admin_can')) {
    function admin_can(string $permission): bool {
        $ci = get_instance();
        $user = $ci->session->userdata('identity');
        return is_array($user) && $ci->platform->identity->can($user, $permission);
    }
}
if (!function_exists('admin_dt')) {
    function admin_dt(?string $iso, string $empty = '—'): string {
        if ($iso === null || $iso === '') return $empty;
        return e(str_replace('T', ' ', substr($iso, 0, 16))) . ' UTC';
    }
}
$ci = get_instance();
$identity = is_array($ci->session->userdata('identity')) ? $ci->session->userdata('identity') : null;
$ci->config->load('seo', true);
$seo = $ci->config->item('settings', 'seo') ?: [];
$pageTitle = (string) ($title ?? 'Admin');
$product = (string) ($productName ?? 'WINDELS AI WORKFORCE');
$active = $active ?? '';
$userName = (string) ($identity['display_name'] ?? $identity['email'] ?? 'Admin');
$userInitials = strtoupper(mb_substr(preg_replace('/[^A-Za-z0-9 ]/', '', $userName), 0, 1) ?: 'A');
$userProfileImage = (string) ($identity['profile_image'] ?? '');
$ic = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle . ' · Admin · ' . $product) ?></title>
<meta name="robots" content="noindex,nofollow">
<link rel="icon" type="image/png" href="/assets/images/windels-mark.png">
<link rel="stylesheet" href="/assets/css/ai_workforce.css">
</head>
<body class="app-shell admin-shell">
<?php $this->load->view('partials/announcement_bar'); ?>
<aside class="sidebar" id="app-sidebar" aria-label="Administrator navigation">
  <a class="sidebar-brand" href="/admin">
    <img src="/assets/images/windels-mark.png" alt="<?= e($product) ?>" onerror="this.onerror=null;this.src='/assets/images/ai_workforce-mark.png'">
    <span><?= e($product) ?><small>Admin portal</small></span>
  </a>

  <p class="sidebar-label">Overview</p>
  <a href="/admin" class="<?= $active === 'dashboard' ? 'active' : '' ?>"><?= $ic ?><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/></svg><span>Dashboard</span></a>
  <?php if (admin_can('admin.users.view')): ?>
  <a href="/admin/users" class="<?= $active === 'users' ? 'active' : '' ?>"><?= $ic ?><circle cx="9" cy="8" r="3"/><path d="M3 19c0-3 3-5 6-5s6 2 6 5"/><path d="M16 11a3 3 0 1 0 0-6"/><path d="M21 19c0-2.5-2-4.2-4.5-4.8"/></svg><span>Users</span></a>
  <?php endif; ?>

  <p class="sidebar-label">Platform</p>
  <a href="/admin/workforce" class="<?= $active === 'workforce' ? 'active' : '' ?>"><?= $ic ?><rect x="4" y="6" width="16" height="13" rx="2"/><path d="M12 2v4M8.5 12h.01M15.5 12h.01M9 16h6"/></svg><span>AI Workforce</span></a>
  <a href="/admin/languages" class="<?= $active === 'languages' ? 'active' : '' ?>"><?= $ic ?><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.5 2.5 15 0 18M12 3c-2.5 2.5-2.5 15 0 18"/></svg><span>Language Learning</span></a>
  <a href="/admin/conversations" class="<?= $active === 'conversations' ? 'active' : '' ?>"><?= $ic ?><path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"/></svg><span>Conversations</span></a>
  <?php if (admin_can('admin.analytics.view')): ?>
  <a href="/admin/analytics" class="<?= $active === 'analytics' ? 'active' : '' ?>"><?= $ic ?><path d="M3 3v18h18"/><path d="m7 14 3-3 3 2 5-6"/></svg><span>Analytics</span></a>
  <?php endif; ?>
  <a href="/admin/notifications" class="<?= $active === 'notifications' ? 'active' : '' ?>"><?= $ic ?><path d="M6 9a6 6 0 0 1 12 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 19a2 2 0 0 0 4 0"/></svg><span>Notifications</span></a>
  <?php if (admin_can('admin.analytics.view')): ?>
  <a href="/admin/reports" class="<?= $active === 'reports' ? 'active' : '' ?>"><?= $ic ?><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 7h8M8 11h8M8 15h5"/></svg><span>Reports</span></a>
  <?php endif; ?>

  <p class="sidebar-label">Administration</p>
  <?php if (admin_can('admin.api.view')): ?>
  <a href="/admin/api" class="<?= $active === 'api' ? 'active' : '' ?>"><?= $ic ?><rect x="3" y="4" width="18" height="14" rx="2"/><path d="M7 8h10M7 12h6"/></svg><span>API Management</span></a>
  <?php endif; ?>
  <?php if (admin_can('admin.settings.manage')): ?>
  <a href="/admin/settings" class="<?= $active === 'settings' ? 'active' : '' ?>"><?= $ic ?><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9c.3.6.9 1 1.6 1.1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/></svg><span>System Settings</span></a>
  <?php endif; ?>
  <?php if (admin_can('admin.admins.manage')): ?>
  <a href="/admin/admins" class="<?= $active === 'admins' ? 'active' : '' ?>"><?= $ic ?><path d="M12 2 4 5v6c0 5 3.5 8.5 8 11 4.5-2.5 8-6 8-11V5z"/></svg><span>Admin Accounts</span></a>
  <?php endif; ?>
  <?php if (admin_can('admin.logs.view')): ?>
  <a href="/admin/logs" class="<?= $active === 'logs' ? 'active' : '' ?>"><?= $ic ?><path d="M8 6h13M8 12h13M8 18h13"/><path d="M3 6h.01M3 12h.01M3 18h.01"/></svg><span>Activity Logs</span></a>
  <?php endif; ?>
  <?php if (admin_can('admin.security.view')): ?>
  <a href="/admin/security" class="<?= $active === 'security' ? 'active' : '' ?>"><?= $ic ?><rect x="4" y="10" width="16" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg><span>Security</span></a>
  <?php endif; ?>

  <?php if ($identity): ?>
    <form method="post" action="/logout" class="sidebar-logout">
      <input type="hidden" name="csrf_token" value="<?= e((string) $ci->session->userdata('csrf_token')) ?>">
      <button class="btn small ghost" type="submit"><?= $ic ?><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/></svg> Logout</button>
    </form>
  <?php endif; ?>
</aside>
<div class="app-main" id="app-main">
<header class="topbar" id="dashboard-header">
  <div class="topbar-left">
    <button class="sidebar-toggle topbar-menu" id="sidebar-toggle" type="button" aria-expanded="false" aria-controls="app-sidebar" aria-label="Open navigation menu" title="Menu">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
    </button>
    <div class="brand">
      <span class="mark"><img src="/assets/images/windels-mark.png" alt="" onerror="this.onerror=null;this.src='/assets/images/ai_workforce-mark.png'"></span>
      <h1 id="page-title"><?= e($pageTitle) ?></h1>
    </div>
  </div>
  <div class="top-right">
    <?php if (admin_can('admin.users.view')): ?>
    <form class="admin-search" method="get" action="/admin/search" role="search">
      <input type="search" name="q" placeholder="User ID, username or email" value="<?= e((string) ($q ?? '')) ?>" maxlength="80" aria-label="Search users">
      <button class="btn small" type="submit">Search</button>
    </form>
    <?php endif; ?>
    <span class="admin-badge"><img src="/assets/images/ai-agent-avatar.png" alt="" width="28" height="28"><span>Admin</span></span>
    <div class="profile-wrap">
      <button class="profile" type="button" id="profile-btn" aria-haspopup="true" aria-expanded="false">
        <?php if ($userProfileImage !== ''): ?>
          <span class="avatar avatar--img"><img src="<?= e($userProfileImage) ?>" alt=""></span>
        <?php else: ?>
          <span class="avatar"><?= e($userInitials) ?></span>
        <?php endif; ?>
        <span class="who"><b><?= e($userName) ?></b><span>Administrator</span></span>
      </button>
      <div class="profile-menu" id="profile-menu" role="menu">
        <a href="/admin" role="menuitem"><?= $ic ?><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/></svg> Admin dashboard</a>
        <a href="/dashboard" role="menuitem"><?= $ic ?><rect x="4" y="6" width="16" height="13" rx="2"/></svg> User workspace</a>
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
<main class="wrap" id="page-content">
<?php if (!empty($notice)): ?><div class="notice ok"><?= e($notice) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="notice err"><?= e($error) ?></div><?php endif; ?>
