<?php
defined('BASEPATH') or exit('No direct script access allowed');
/** @var string $title @var string $active @var array $status */
function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$status = $status ?? null;
$mode = $status['tradingMode'] ?? '…';
$ks = $status['killSwitch'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'AEGIS') ?> — AEGIS Trading Intelligence</title>
<link rel="stylesheet" href="/assets/css/aegis.css">
</head>
<body>
<header class="topbar">
  <div class="brand">
    <div class="mark">Æ</div>
    <div>
      <h1>AEGIS <span style="font-weight:400;color:var(--muted)">· AI Trading Intelligence</span></h1>
      <div class="sub">CodeIgniter 3 · MySQL/MariaDB · Phase 3: Paper Trading</div>
    </div>
  </div>
  <nav class="nav">
    <a href="/" class="<?= ($active ?? '') === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
    <a href="/strategy" class="<?= ($active ?? '') === 'strategy' ? 'active' : '' ?>">Strategy Lab</a>
    <a href="/paper" class="<?= ($active ?? '') === 'paper' ? 'active' : '' ?>">Paper Trading</a>
    <a href="/journal" class="<?= ($active ?? '') === 'journal' ? 'active' : '' ?>">Journal &amp; Analytics</a>
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
  </div>
</header>
<main class="wrap">
