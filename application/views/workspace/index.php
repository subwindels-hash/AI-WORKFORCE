<?php defined('BASEPATH') or exit('No direct script access allowed');
/** @var array $user @var array $status @var array $inbox @var array $history @var int $paperAccounts @var int $languageProfiles @var bool $admin */
$first = explode(' ', trim((string)($user['display_name'] ?? 'Member')))[0];
$unread = (int)($inbox['unread'] ?? 0);
$notes = $inbox['notifications'] ?? [];
$ic = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">';
$ks = $status['killSwitch'] ?? null;
$mode = $status['tradingMode'] ?? null;
?>
<div class="dash-hero">
  <div class="dash-hero-copy">
    <p class="eyebrow">WINDELS AI WORKFORCE</p>
    <h2>Welcome back, <?= e($first) ?></h2>
    <p>Here is the current state of your workspace. Pick a main action below, or continue from a recent activity.</p>
    <div class="dash-status">
      <?php if (!empty($ks['active'])): ?><span class="statuspill warn"><i class="pill-dot"></i>Kill switch active</span>
      <?php else: ?><span class="statuspill"><i class="pill-dot"></i>Mode <?= e($mode) ?></span><?php endif; ?>
      <span class="statuspill"><i class="pill-dot"></i><?= (int)$paperAccounts ?> paper account<?= $paperAccounts === 1 ? '' : 's' ?></span>
      <span class="statuspill"><i class="pill-dot"></i><?= (int)$languageProfiles ?> language profile<?= $languageProfiles === 1 ? '' : 's' ?></span>
    </div>
  </div>
  <div class="dash-hero-actions">
    <a class="btn primary" href="/analysis" data-dashboard-link><?= $ic ?><rect x="4" y="6" width="16" height="13" rx="2"/><path d="M9 12h.01M15 12h.01M9 16h6"/></svg> Run AI analysis</a>
    <a class="btn" href="/app/languages/teacher" data-dashboard-link><?= $ic ?><circle cx="12" cy="12" r="9"/><path d="M3 12h18"/></svg> Learn a language</a>
    <a class="btn" href="/paper" data-dashboard-link><?= $ic ?><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 7h8M8 11h5"/></svg> Paper trading</a>
    <a class="btn" href="/messages" data-dashboard-link><?= $ic ?><path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"/></svg> Message support<?= (int)($messagesUnread ?? 0) > 0 ? ' (' . (int) $messagesUnread . ')' : '' ?></a>
  </div>
</div>
<?php if (!empty($notice)): ?><div class="notice ok"><?= e($notice) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="notice err"><?= e($error) ?></div><?php endif; ?>

<h2 class="section-title">Workspace summary</h2>
<div class="grid four">
  <a class="kp-card" href="/analysis">
    <div class="kp-top"><div class="k">AI workforce runs</div><div class="kp-ic"><?= $ic ?><rect x="4" y="6" width="16" height="13" rx="2"/><path d="M9 12h.01M15 12h.01M9 16h6"/></svg></div></div>
    <div class="v"><?= count($history) ?></div>
    <div class="trend">Recent analyses stored</div>
  </a>
  <a class="kp-card" href="/app/languages">
    <div class="kp-top"><div class="k">Language profiles</div><div class="kp-ic"><?= $ic ?><circle cx="12" cy="12" r="9"/><path d="M3 12h18"/></svg></div></div>
    <div class="v"><?= (int)$languageProfiles ?></div>
    <div class="trend">Active learning paths</div>
  </a>
  <a class="kp-card" href="/paper">
    <div class="kp-top"><div class="k">Paper accounts</div><div class="kp-ic"><?= $ic ?><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 7h8M8 11h5"/></svg></div></div>
    <div class="v"><?= (int)$paperAccounts ?></div>
    <div class="trend">Simulation accounts</div>
  </a>
  <a class="kp-card" href="/notifications">
    <div class="kp-top"><div class="k">Unread alerts</div><div class="kp-ic"><?= $ic ?><path d="M6 9a6 6 0 0 1 12 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 19a2 2 0 0 0 4 0"/></svg></div></div>
    <div class="v"><?= $unread ?></div>
    <div class="trend">Awaiting your review</div>
  </a>
</div>

<h2 class="section-title">Trading intelligence</h2>
<section class="panel" style="margin-bottom:18px"><div class="body">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:14px">
    <div>
      <h3 style="margin:0 0 4px">My Trading Dashboard</h3>
      <p class="dim" style="margin:0">Connect your trading platforms and execute trades with AI intelligence — all under risk controls and approval governance.</p>
    </div>
    <div style="display:flex;gap:8px">
      <a class="btn primary" href="/app/trading">Open My Trading</a>
      <a class="btn" href="/brokers">Manage brokers</a>
    </div>
  </div>
  <?php $tw = $tradingWidget ?? []; ?>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:14px">
    <div style="background:var(--panel2);border-radius:var(--radius-sm);padding:12px 14px">
      <div style="font-size:11px;color:var(--dim);text-transform:uppercase;letter-spacing:.04em">Portfolio Equity</div>
      <div style="font-size:20px;font-weight:700;color:#fff">$<?= number_format((float)($tw['totalEquity'] ?? 0), 2) ?></div>
    </div>
    <div style="background:var(--panel2);border-radius:var(--radius-sm);padding:12px 14px">
      <div style="font-size:11px;color:var(--dim);text-transform:uppercase;letter-spacing:.04em">Unrealized P&amp;L</div>
      <?php $pnl = (float)($tw['totalPnl'] ?? 0); ?>
      <div style="font-size:20px;font-weight:700;color:<?= $pnl > 0 ? 'var(--green)' : ($pnl < 0 ? 'var(--red)' : 'var(--dim)') ?>"><?= $pnl >= 0 ? '+' : '' ?>$<?= number_format($pnl, 2) ?></div>
    </div>
    <div style="background:var(--panel2);border-radius:var(--radius-sm);padding:12px 14px">
      <div style="font-size:11px;color:var(--dim);text-transform:uppercase;letter-spacing:.04em">Open Positions</div>
      <div style="font-size:20px;font-weight:700;color:#fff"><?= (int)($tw['openPositions'] ?? 0) ?></div>
    </div>
    <div style="background:var(--panel2);border-radius:var(--radius-sm);padding:12px 14px">
      <div style="font-size:11px;color:var(--dim);text-transform:uppercase;letter-spacing:.04em">Connected Brokers</div>
      <div style="font-size:20px;font-weight:700;color:#fff"><?= (int)($tw['connectedBrokers'] ?? 0) ?> / <?= (int)($tw['totalBrokers'] ?? 0) ?></div>
    </div>
  </div>
  <?php if (!empty($tw['brokers'])): ?>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php foreach ($tw['brokers'] as $b): ?>
      <span class="statuspill" style="<?= !empty($b['connected']) ? '' : 'opacity:.5' ?>">
        <i class="pill-dot" style="<?= !empty($b['connected']) ? 'background:var(--green)' : 'background:var(--dim)' ?>"></i>
        <?= e(ucfirst($b['broker'])) ?><?= !empty($b['label']) ? ' — ' . e($b['label']) : '' ?>
      </span>
    <?php endforeach; ?>
  </div>
  <?php elseif (($tw['totalBrokers'] ?? 0) === 0): ?>
  <p class="dim">No brokers connected yet. <a href="/brokers">Connect a platform →</a></p>
  <?php endif; ?>
</div></section>

<h2 class="section-title">Lottery intelligence</h2>
<section class="panel lottery-widget" style="margin-bottom:18px">
  <div class="body">
    <!-- Header with Jackpot & Quick Actions -->
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:18px">
      <div>
        <h3 style="margin:0 0 6px">🎰 EuroMillions Lottery Intelligence</h3>
        <p class="dim" style="margin:0">AI-powered lottery analysis, number generation, and verified draw data — all in one place.</p>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a class="btn primary" href="/lottery">Open Lottery Intel</a>
        <a class="btn" href="/lottery#generator">Generate Numbers</a>
        <a class="btn" href="/lottery#draws">View All Draws</a>
        <?php if (($lotteryWidget['myTicketsCount'] ?? 0) > 0): ?>
          <a class="btn" href="/lottery#tickets">My Tickets (<?= (int)$lotteryWidget['myTicketsCount'] ?>)</a>
        <?php endif; ?>
      </div>
    </div>
    
    <!-- Jackpot Display with Countdown -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:18px">
      <!-- Jackpot Card -->
      <div style="background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);border-radius:var(--radius);padding:20px;color:#fff">
        <div style="font-size:11px;text-transform:uppercase;letter-spacing:.08em;opacity:.9;margin-bottom:6px">Current Jackpot</div>
        <div style="font-size:32px;font-weight:700;margin-bottom:4px"><?= e($lotteryWidget['jackpotFormatted'] ?? '—') ?></div>
        <div style="font-size:12px;opacity:.9"><?= (int)($lotteryWidget['imported'] ?? 0) ?> verified draws imported</div>
      </div>
      
      <!-- Countdown Card -->
      <div style="background:var(--panel2);border:1px solid var(--line);border-radius:var(--radius);padding:20px">
        <div style="font-size:11px;color:var(--dim);text-transform:uppercase;letter-spacing:.08em;margin-bottom:6px">Next Draw</div>
        <div id="lottery-next-draw-full" style="font-size:14px;color:var(--text);margin-bottom:12px"><?= e($lotteryWidget['nextEstimated'] ?? 'Not available') ?></div>
        <div style="display:flex;gap:12px" id="lottery-countdown-cards">
          <div style="flex:1;text-align:center">
            <div id="lc-days" style="font-size:28px;font-weight:700;color:var(--brand)">—</div>
            <div style="font-size:10px;color:var(--dim);text-transform:uppercase">Days</div>
          </div>
          <div style="flex:1;text-align:center">
            <div id="lc-hours" style="font-size:28px;font-weight:700;color:var(--brand)">—</div>
            <div style="font-size:10px;color:var(--dim);text-transform:uppercase">Hours</div>
          </div>
          <div style="flex:1;text-align:center">
            <div id="lc-mins" style="font-size:28px;font-weight:700;color:var(--brand)">—</div>
            <div style="font-size:10px;color:var(--dim);text-transform:uppercase">Minutes</div>
          </div>
        </div>
      </div>
    </div>
    
    <!-- Recent Results -->
    <?php if (!empty($lotteryWidget['recentDraws'])): ?>
    <div style="margin-bottom:18px">
      <h4 style="margin:0 0 10px;font-size:14px;font-weight:600">Recent Draw Results</h4>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px">
        <?php foreach (array_slice($lotteryWidget['recentDraws'], 0, 3) as $draw): ?>
          <?php 
            $mainNums = $draw['numbers']['main'] ?? $draw['mainNumbers'] ?? [];
            $starNums = $draw['numbers']['stars'] ?? $draw['stars'] ?? $draw['bonusNumbers'] ?? [];
            $drawDate = substr($draw['draw_date'] ?? $draw['drawDate'] ?? '', 0, 10);
          ?>
          <div style="background:var(--panel2);border:1px solid var(--line);border-radius:var(--radius-sm);padding:12px">
            <div style="font-size:11px;color:var(--dim);margin-bottom:8px"><?= e($drawDate) ?></div>
            <div style="display:flex;gap:4px;margin-bottom:6px;flex-wrap:wrap">
              <?php foreach ($mainNums as $n): ?>
                <span style="background:#f5a62322;color:var(--amber);padding:3px 7px;border-radius:4px;font-size:12px;font-weight:600"><?= e((string)$n) ?></span>
              <?php endforeach; ?>
            </div>
            <?php if (!empty($starNums)): ?>
            <div style="display:flex;gap:4px;flex-wrap:wrap">
              <?php foreach ($starNums as $n): ?>
                <span style="background:#3b82f622;color:var(--brand);padding:3px 7px;border-radius:4px;font-size:12px;font-weight:600">★ <?= e((string)$n) ?></span>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
    
    <!-- Latest Result (if available) -->
    <?php $lastDraw = $lotteryWidget['lastDraw'] ?? null; ?>
    <?php if (is_array($lastDraw)): ?>
      <?php 
        $mainNums = $lastDraw['numbers']['main'] ?? $lastDraw['mainNumbers'] ?? [];
        $starNums = $lastDraw['numbers']['stars'] ?? $lastDraw['stars'] ?? $lastDraw['bonusNumbers'] ?? [];
        $drawDate = substr($lastDraw['draw_date'] ?? $lastDraw['drawDate'] ?? '', 0, 10);
      ?>
      <div style="background:var(--panel2);border-left:3px solid var(--brand);border-radius:var(--radius-sm);padding:14px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <span style="font-size:12px;color:var(--dim)">Latest verified result (<?= e($drawDate) ?>)</span>
        </div>
        <div style="display:flex;gap:4px;margin-bottom:6px;flex-wrap:wrap">
          <?php foreach ($mainNums as $n): ?>
            <span class="badge b-amber" style="margin-right:4px"><?= e((string)$n) ?></span>
          <?php endforeach; ?>
          <?php foreach ($starNums as $n): ?>
            <span class="badge b-blue" style="margin-right:4px">★ <?= e((string)$n) ?></span>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</section>

<!-- Countdown Timer Script -->
<script>
(function(){
  var raw = <?= json_encode($lotteryWidget['nextEstimated'] ?? null) ?>;
  if (!raw) return;
  var target = Date.parse(raw);
  if (!isFinite(target)) {
    document.getElementById('lottery-next-draw-full').textContent = 'Draw time unavailable';
    return;
  }
  
  function updateCountdown() {
    var now = Date.now();
    var diff = Math.max(0, target - now);
    var days = Math.floor(diff / (1000 * 60 * 60 * 24));
    var hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
    var mins = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
    
    document.getElementById('lc-days').textContent = days;
    document.getElementById('lc-hours').textContent = hours;
    document.getElementById('lc-mins').textContent = mins;
    
    if (diff === 0) {
      document.getElementById('lottery-next-draw-full').textContent = 'Draw time reached!';
    }
  }
  
  updateCountdown();
  setInterval(updateCountdown, 60000); // Update every minute
})();
</script>

<h2 class="section-title">Recent activity &amp; alerts</h2>
<div class="grid cols-main">
  <section class="panel">
    <h3><?= $ic ?><path d="M3 3v18h18"/><path d="m7 14 3-3 3 2 5-6"/></svg> Recent activity</h3>
    <div class="body">
      <?php if (!$history): ?>
        <div class="empty-state">
          <?= $ic ?><rect x="4" y="6" width="16" height="13" rx="2"/><path d="M12 2v4"/></svg>
          <p>No analysis runs stored yet.</p>
          <p style="margin-top:12px"><a class="btn primary" href="/analysis">Run your first analysis</a></p>
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
    <a class="panel-foot-link" href="/journal">View analytics →</a>
  </section>

  <div class="stack">
  <section class="panel">
    <h3><?= $ic ?><path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"/></svg> Messages</h3>
    <div class="body">
      <?php $latest = $latestMessage ?? null; ?>
      <?php if ($latest): ?>
        <div class="feed">
          <div class="row">
            <span class="t"><?= e($latest['sender_label'] !== '' ? $latest['sender_label'] : 'Support team') ?></span>
            <span class="d"><?= e(substr((string)($latest['created_at'] ?? ''), 5, 11)) ?></span>
          </div>
          <div class="row"><span class="t" style="font-weight:400"><?= e(\AIWorkforce\Messaging\DirectMessages::preview((string) ($latest['body'] ?? ''), 110)) ?></span></div>
        </div>
      <?php else: ?>
        <div class="empty-state" style="padding:20px">
          <p>No support messages yet.</p>
        </div>
      <?php endif; ?>
      <?php if ((int)($messagesUnread ?? 0) > 0): ?>
        <p style="margin:10px 0 0"><span class="badge b-red"><?= (int) $messagesUnread ?> unread</span></p>
      <?php endif; ?>
    </div>
    <a class="panel-foot-link" href="/messages">Open messages →</a>
  </section>

  <section class="panel">
    <h3><?= $ic ?><path d="M6 9a6 6 0 0 1 12 0c0 5 2 6 2 6H4s2-1 2-6"/><path d="M10 19a2 2 0 0 0 4 0"/></svg> Notifications</h3>
    <div class="body">
      <?php if (!$notes): ?>
        <div class="empty-state" style="padding:20px">
          <p>Nothing in your inbox yet.</p>
        </div>
      <?php else: ?>
        <div class="feed">
          <?php foreach (array_slice($notes, 0, 6) as $n): ?>
            <div class="row">
              <span class="t"><?= e($n['title'] ?? $n['type'] ?? 'Notice') ?></span>
              <span class="d"><?= e(substr((string)($n['created_at'] ?? ''), 0, 16)) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <a class="panel-foot-link" href="/notifications">Open all alerts →</a>
  </section>
  </div>
</div>
