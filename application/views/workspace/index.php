<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<div class="page-head">
  <div>
    <p class="eyebrow">User dashboard</p>
    <h2>Welcome back, <?= e((string) ($user['display_name'] ?? $user['email'] ?? 'member')) ?></h2>
    <p>This overview only counts records that already exist. Empty modules stay empty.</p>
  </div>
</div>
<?php if (!empty($notice)): ?><div class="notice ok"><?= e($notice) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="notice err"><?= e($error) ?></div><?php endif; ?>

<div class="stat-grid" style="margin-bottom:14px">
  <div class="stat"><div class="k">Unread alerts</div><div class="v"><?= (int) ($inbox['unread'] ?? 0) ?></div></div>
  <div class="stat"><div class="k">Paper accounts</div><div class="v"><?= (int) $paperAccounts ?></div></div>
  <div class="stat"><div class="k">Language profiles</div><div class="v"><?= (int) $languageProfiles ?></div></div>
  <div class="stat"><div class="k">Recent analyses</div><div class="v"><?= count($history) ?></div></div>
</div>

<section class="panel" style="margin-bottom:14px">
  <h3>Quick actions</h3>
  <div class="body" style="display:flex;flex-wrap:wrap;gap:8px">
    <a class="btn primary" href="/analysis">Run analysis</a>
    <a class="btn" href="/paper">Paper trading</a>
    <a class="btn" href="/app/languages">Languages</a>
    <a class="btn" href="/sports">Sports</a>
    <a class="btn" href="/leads">Lead discovery</a>
    <a class="btn" href="/notifications">Alerts</a>
    <a class="btn" href="/account">Account</a>
    <?php if (!empty($admin)): ?><a class="btn" href="/admin">Admin control centre</a><?php endif; ?>
  </div>
</section>

<div class="grid cols-main">
  <section class="panel">
    <h3>My activity · latest analysis</h3>
    <div class="body">
      <?php if (!$history): ?><p class="dim">No analysis runs stored yet.</p>
      <?php else: ?>
        <table class="tbl">
          <?php foreach ($history as $h): ?>
            <tr>
              <td class="mono"><?= e($h['symbol']) ?></td>
              <td class="dim"><?= e($h['timeframe']) ?></td>
              <td><?= e($h['bias']) ?></td>
              <td class="num dim"><?= e(substr((string) $h['completed_at'], 0, 16)) ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
    </div>
  </section>
  <section class="panel">
    <h3>Notifications</h3>
    <div class="body">
      <?php $notes = $inbox['notifications'] ?? []; ?>
      <?php if (!$notes): ?><p class="dim">Nothing in your inbox yet.</p>
      <?php else: foreach (array_slice($notes, 0, 6) as $n): ?>
        <div style="padding:6px 0;border-bottom:1px solid var(--line)">
          <b><?= e($n['title'] ?? $n['type'] ?? 'Notice') ?></b>
          <div class="dim"><?= e(substr((string) ($n['created_at'] ?? ''), 0, 16)) ?></div>
        </div>
      <?php endforeach; endif; ?>
      <p style="margin-top:10px"><a href="/notifications">Open alerts</a></p>
    </div>
  </section>
</div>
