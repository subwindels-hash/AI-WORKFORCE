<?php defined('BASEPATH') or exit('No direct script access allowed');
/** @var array $user @var array $status @var array $inbox @var array $history @var int $paperAccounts @var int $languageProfiles @var bool $admin */
$first = explode(' ', trim((string)($user['display_name'] ?? 'Member')))[0];
$providers = $status['providers'] ?? [];
$upProviders = 0; $totalProviders = count($providers);
foreach ($providers as $p) { if (($p['status'] ?? '') === 'UP') $upProviders++; }
$unread = (int)($inbox['unread'] ?? 0);
$notes = $inbox['notifications'] ?? [];
$ic = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">';
?>
<div class="page-head">
  <div>
    <h2>Welcome back, <?= e($first) ?></h2>
    <p>Your WINDELS AI WORKFORCE workspace — real activity only. Empty modules stay empty; nothing is fabricated.</p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a class="btn primary" href="/app/languages/teacher"><?= $ic ?><path d="M3 4h13a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2H3z"/><path d="m3 4 1 7 1-7M19 5l3 2-3 2"/></svg> Open AI Teacher</a>
    <a class="btn" href="/analysis"><?= $ic ?><rect x="4" y="6" width="16" height="13" rx="2"/><path d="M12 2v4M9 12h.01M15 12h.01M9 16h6"/></svg> Run analysis</a>
  </div>
</div>
<?php if (!empty($notice)): ?><div class="notice ok"><?= e($notice) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="notice err"><?= e($error) ?></div><?php endif; ?>

<!-- KPI row: every value reflects real records -->
<div class="grid four" style="grid-template-columns:repeat(4,minmax(0,1fr));margin-bottom:16px">
  <div class="kp-card">
    <div class="kp-top"><div class="k">AI workforce runs</div><div class="kp-ic"><?= $ic ?><rect x="4" y="6" width="16" height="13" rx="2"/><path d="M9 12h.01M15 12h.01M9 16h6"/></svg></div></div>
    <div class="v"><?= count($history) ?></div>
    <div class="trend">Recent analyses stored</div>
  </div>
  <div class="kp-card">
    <div class="kp-top"><div class="k">Language profiles</div><div class="kp-ic"><?= $ic ?><circle cx="12" cy="12" r="9"/><path d="M3 12h18"/></svg></div></div>
    <div class="v"><?= (int)$languageProfiles ?></div>
    <div class="trend">Active learning paths</div>
  </div>
  <div class="kp-card">
    <div class="kp-top"><div class="k">Paper accounts</div><div class="kp-ic"><?= $ic ?><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 7h8M8 11h5"/></svg></div></div>
    <div class="v"><?= (int)$paperAccounts ?></div>
    <div class="trend">Simulation accounts</div>
  </div>
  <div class="kp-card">
    <div class="kp-top"><div class="k">Unread alerts</div><div class="kp-ic" style="color:var(--amber);background:#f5a6231f"><?= $ic ?><path d="M6 9a6 6 0 0 1 12 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 19a2 2 0 0 0 4 0"/></svg></div></div>
    <div class="v"><?= $unread ?></div>
    <div class="trend"><?= $totalProviders ? $upProviders . '/' . $totalProviders . ' data providers up' : 'No providers configured' ?></div>
  </div>
</div>

<div class="grid cols-main">
  <section class="panel">
    <h3><?= $ic ?><path d="m3 3 18 0 0 18-18 0z" style="display:none"/><path d="M3 3v18h18"/><path d="m7 14 3-3 3 2 5-6"/></svg> Recent AI workforce activity</h3>
    <div class="body">
      <?php if (!$history): ?>
        <div class="empty-state">
          <?= $ic ?><rect x="4" y="6" width="16" height="13" rx="2"/><path d="M12 2v4"/></svg>
          <p>No analysis runs stored yet. Run your first analysis to see multi-agent consensus here.</p>
          <p style="margin-top:8px"><a class="btn primary" href="/analysis">Run analysis</a></p>
        </div>
      <?php else: ?>
        <table class="tbl">
          <thead><tr><th>Symbol</th><th>Timeframe</th><th>Bias</th><th class="num">Completed</th></tr></thead>
          <tbody>
            <?php foreach ($history as $h): ?>
              <tr>
                <td class="mono"><?= e($h['symbol']) ?></td>
                <td class="dim"><?= e($h['timeframe']) ?></td>
                <td><?= e($h['bias']) ?></td>
                <td class="num dim"><?= e(substr((string)$h['completed_at'], 0, 16)) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </section>

  <section class="panel">
    <h3><?= $ic ?><path d="M6 9a6 6 0 0 1 12 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 19a2 2 0 0 0 4 0"/></svg> Notifications</h3>
    <div class="body">
      <?php if (!$notes): ?>
        <div class="empty-state" style="padding:18px">
          <p style="margin:0">Nothing in your inbox yet.</p>
        </div>
      <?php else: foreach (array_slice($notes, 0, 6) as $n): ?>
        <div style="padding:9px 0;border-bottom:1px solid var(--line)">
          <b><?= e($n['title'] ?? $n['type'] ?? 'Notice') ?></b>
          <div class="dim" style="font-size:11px;margin-top:2px"><?= e(substr((string)($n['created_at'] ?? ''), 0, 16)) ?></div>
        </div>
      <?php endforeach; endif; ?>
      <p style="margin-top:12px"><a href="/notifications">Open all alerts →</a></p>
    </div>
  </section>
</div>

<section class="panel" style="margin-top:16px">
  <h3><?= $ic ?><path d="M13 2 3 14h7l-1 8 10-12h-7z"/></svg> Quick actions</h3>
  <div class="body" style="display:flex;flex-wrap:wrap;gap:8px;padding-top:14px">
    <a class="btn" href="/app/languages/teacher">AI Language Teacher</a>
    <a class="btn" href="/analysis">AI Workforce analysis</a>
    <a class="btn" href="/paper">Paper trading</a>
    <a class="btn" href="/app/languages">Languages</a>
    <a class="btn" href="/strategy">Strategy lab</a>
    <a class="btn" href="/journal">Analytics</a>
    <a class="btn" href="/leads">Lead discovery</a>
    <a class="btn" href="/notifications">Notifications</a>
    <?php if (!empty($admin)): ?><a class="btn" href="/admin">Admin control centre</a><?php endif; ?>
  </div>
</section>

<section class="panel" style="margin-top:16px">
  <h3>Platform status</h3>
  <div class="body" style="display:flex;gap:18px;flex-wrap:wrap;align-items:center;padding-top:14px">
    <span class="statuspill">Mode: <?= e((string)($status['tradingMode'] ?? '—')) ?></span>
    <?php if (!empty($status['killSwitch']['active'])): ?><span class="badge b-red">KILL SWITCH ACTIVE</span><?php else: ?><span class="badge b-green">Kill switch off</span><?php endif; ?>
    <?php foreach (array_slice($providers, 0, 6) as $p): ?>
      <span class="prov"><span class="dot <?= ($p['status'] ?? '') === 'UP' ? 'up' : (($p['status'] ?? '') === 'SYNTHETIC' ? 'synth' : 'down') ?>"></span><?= e($p['name'] ?? $p['id'] ?? 'Provider') ?></span>
    <?php endforeach; ?>
  </div>
</section>
