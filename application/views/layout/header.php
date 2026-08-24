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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'AEGIS') ?> — AEGIS Trading Intelligence</title>
<link rel="icon" type="image/png" href="/assets/images/aegis-mark.png">
<link rel="stylesheet" href="/assets/css/aegis.css">
</head>
<body>
<header class="topbar">
  <div class="brand">
    <div class="mark"><img src="/assets/images/aegis-mark.png" alt="AEGIS"></div>
    <div>
      <h1>AEGIS <span style="font-weight:400;color:var(--muted)">· AI Trading Intelligence</span></h1>
      <div class="sub">CodeIgniter 3 · MySQL/MariaDB · Phase 5: Execution Governance</div>
    </div>
  </div>
  <nav class="nav">
    <a href="/" class="<?= ($active ?? '') === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
    <a href="/strategy" class="<?= ($active ?? '') === 'strategy' ? 'active' : '' ?>">Strategy Lab</a>
    <a href="/paper" class="<?= ($active ?? '') === 'paper' ? 'active' : '' ?>">Paper Trading</a>
    <a href="/execution" class="<?= ($active ?? '') === 'execution' ? 'active' : '' ?>">Execution</a>
    <a href="/brokers" class="<?= ($active ?? '') === 'brokers' ? 'active' : '' ?>">Brokers</a>
    <a href="/risk" class="<?= ($active ?? '') === 'risk' ? 'active' : '' ?>">Risk Center</a>
    <a href="/notifications" class="<?= ($active ?? '') === 'notifications' ? 'active' : '' ?>">Alerts<?php $unread = Aegis_NotificationsHelper::unreadCount(); if ($unread > 0): ?> <span class="badge b-red"><?= (int) $unread ?></span><?php endif; ?></a>
    <a href="/journal" class="<?= ($active ?? '') === 'journal' ? 'active' : '' ?>">Journal &amp; Analytics</a>
    <a href="/sports" class="<?= ($active ?? '') === 'sports' ? 'active' : '' ?>">Sports</a>
    <a href="/app/languages" class="<?= ($active ?? '') === 'languages' ? 'active' : '' ?>">Languages</a>
    <?php if ($identity): ?><a href="/account" class="<?= ($active ?? '') === 'account' ? 'active' : '' ?>">Account</a><?php endif; ?>
    <?php if ($identity && !empty($identity['permissions']) && in_array('system.super_admin', $identity['permissions'], true)): ?><a href="/admin" class="<?= ($active ?? '') === 'admin' ? 'active' : '' ?>">Admin</a><?php endif; ?>
  </nav>
  <div class="top-right">
    <span class="badge b-violet">MODE: <?= e($mode) ?></span>
    <?php if (!empty($ks['active'])): ?>
      <span class="badge b-red">⛔ KILL SWITCH ACTIVE</span>
    <?php else: ?>
      <span class="badge b-green">kill switch off</span>
    <?php endif; ?>
    <?php foreach (($status['providers'] ?? []) as $p): ?>
      <span class="prov" title="<?= e($p['name'] . ': ' . $p['status'] . ($p['detail'] ?? '')) ?>">
        <span class="dot <?= $p['status'] === 'UP' ? ($p['synthetic'] ? 'synth' : 'up') : 'down' ?>"></span>
        <?= e($p['name']) ?>
      </span>
    <?php endforeach; ?>
    <?php if ($identity): ?>
      <span class="top-user"><img class="avatar" src="/assets/images/ai-agent-avatar.png" alt=""><a href="/account"><?= e((string) ($identity['display_name'] ?? $identity['email'] ?? 'Account')) ?></a><form method="post" action="/logout" style="display:inline"><input type="hidden" name="csrf_token" value="<?= e((string) $ci->session->userdata('csrf_token')) ?>"><button class="btn small" type="submit">Sign out</button></form></span>
    <?php else: ?>
      <a class="btn small" href="/login">Sign in</a>
    <?php endif; ?>
  </div>
</header>
<main class="wrap">
