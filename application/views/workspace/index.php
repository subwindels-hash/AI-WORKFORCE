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
    <a class="btn" href="/command-center" data-dashboard-link><?= $ic ?><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg> AI Command Center</a>
    <a class="btn" href="/multiplier" data-dashboard-link><?= $ic ?><path d="M12 2L2 22h20L12 2z"/></svg> Multiplier AI</a>
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



<?php
// Calculate AI system health
$_aiHealth = 0;
$_aiTotal = count($aiModules ?? []);
$_aiHealthy = 0;
foreach ($aiModules ?? [] as $_m) {
  if (($_m['status'] ?? '') === 'healthy') $_aiHealthy++;
}
$_aiHealth = $_aiTotal > 0 ? round(($_aiHealthy / $_aiTotal) * 100, 0) : 0;
?>

<h2 class="section-title">AI system overview <a href="/command-center" style="font-size:12px;font-weight:400;color:var(--dim);text-decoration:none;margin-left:8px">Open Command Center →</a></h2>
<section class="panel" style="margin-bottom:18px">
  <div class="body">
    <!-- Health Score Bar -->
    <div style="display:flex;align-items:center;gap:20px;margin-bottom:20px;padding:16px;background:linear-gradient(135deg,rgba(16,185,129,.1),rgba(34,197,94,.05));border:1px solid rgba(16,185,129,.2);border-radius:var(--radius)">
      <div style="text-align:center;min-width:80px">
        <div style="font-size:36px;font-weight:800;color:var(--green)"><?= (int)$_aiHealth ?>%</div>
        <div style="font-size:10px;color:var(--dim);text-transform:uppercase;letter-spacing:.06em">System Health</div>
      </div>
      <div style="flex:1">
        <div style="font-size:13px;font-weight:600;color:#fff;margin-bottom:6px">All AI Modules Operational</div>
        <div style="height:6px;background:var(--panel2);border-radius:3px;overflow:hidden">
          <div style="height:100%;width:<?= (int)$_aiHealth ?>%;background:linear-gradient(90deg,var(--green),#22c55e);transition:width .3s"></div>
        </div>
        <div style="font-size:11px;color:var(--dim);margin-top:6px"><?= (int)$_aiHealthy ?> of <?= (int)$_aiTotal ?> modules healthy</div>
      </div>
    </div>
    
    <!-- Module Grid -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px">
      <?php foreach ($aiModules ?? [] as $key => $module): ?>
        <?php $_link = '/command-center';
        if ($key === 'windelsai') $_link = '/app/agent-platform';
        elseif ($key === 'multiplier') $_link = '/multiplier';
        elseif ($key === 'lottery') $_link = '/lottery';
        elseif ($key === 'trading') $_link = '/app/trading';
        elseif ($key === 'language') $_link = '/app/languages';
        elseif ($key === 'sports') $_link = '/sports';
        elseif ($key === 'leads') $_link = '/leads';
        ?>
        <a href="<?= e($_link) ?>" style="text-decoration:none;color:inherit;background:var(--panel2);border:1px solid var(--line);border-radius:var(--radius-sm);padding:14px;transition:all .2s;display:block" onmouseover="this.style.borderColor='var(--brand)';this.style.transform='translateY(-2px)'" onmouseout="this.style.borderColor='var(--line)';this.style.transform='none'">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px">
            <span style="font-size:24px"><?= e($module['icon'] ?? '🔧') ?></span>
            <span style="display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;
              <?= ($module['status'] ?? '') === 'healthy' ? 'background:rgba(16,185,129,.15);color:var(--green)' : (($module['status'] ?? '') === 'warning' ? 'background:rgba(245,158,11,.15);color:var(--amber)' : 'background:rgba(239,68,68,.15);color:var(--red)') ?>">
              <span style="width:5px;height:5px;border-radius:50%;background:currentColor;box-shadow:0 0 6px currentColor"></span>
              <?= e(strtoupper($module['status'] ?? 'unknown')) ?>
            </span>
          </div>
          <div style="font-size:12px;font-weight:600;color:#fff;margin-bottom:4px"><?= e($module['name'] ?? '') ?></div>
          <?php if (!empty($module['agents'])): ?>
            <div style="font-size:10px;color:var(--dim)"><?= (int)$module['agents'] ?> agents<?= !empty($module['tools']) ? ' · ' . (int)$module['tools'] . ' tools' : '' ?></div>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<h2 class="section-title">Multiplier intelligence</h2>
<section class="panel" style="margin-bottom:18px">
  <div class="body">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:14px">
      <div>
        <h3 style="margin:0 0 4px">🚀 AI Multiplier Intelligence</h3>
        <p class="dim" style="margin:0">9 specialist AI agents analyzing crash-game patterns with transparent accuracy tracking.</p>
      </div>
      <div style="display:flex;gap:8px">
        <a class="btn primary" href="/multiplier">Open Command Center</a>
        <?php if (!empty($admin)): ?>
          <a class="btn" href="/multiplier/admin">Admin</a>
        <?php endif; ?>
      </div>
    </div>
    
    <!-- Multiplier Stats Grid -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin-bottom:16px">
      <div style="background:var(--panel2);border-radius:var(--radius-sm);padding:12px 14px">
        <div style="font-size:11px;color:var(--dim);text-transform:uppercase;letter-spacing:.04em">Specialist Agents</div>
        <div style="font-size:20px;font-weight:700;color:#fff">9</div>
      </div>
      <div style="background:var(--panel2);border-radius:var(--radius-sm);padding:12px 14px">
        <div style="font-size:11px;color:var(--dim);text-transform:uppercase;letter-spacing:.04em">Rounds Analyzed</div>
        <div style="font-size:20px;font-weight:700;color:#fff"><?= (int)($multiplierWidget['historyCount'] ?? 0) ?></div>
      </div>
      <div style="background:var(--panel2);border-radius:var(--radius-sm);padding:12px 14px">
        <div style="font-size:11px;color:var(--dim);text-transform:uppercase;letter-spacing:.04em">Accuracy (±20%)</div>
        <?php $acc = $multiplierWidget['accuracy20'] ?? null; ?>
        <div style="font-size:20px;font-weight:700;color:<?= $acc !== null ? 'var(--green)' : 'var(--dim)' ?>"><?= $acc !== null ? number_format($acc, 1) . '%' : 'N/A' ?></div>
      </div>
      <div style="background:var(--panel2);border-radius:var(--radius-sm);padding:12px 14px">
        <div style="font-size:11px;color:var(--dim);text-transform:uppercase;letter-spacing:.04em">Predictions</div>
        <div style="font-size:20px;font-weight:700;color:#fff"><?= (int)($multiplierWidget['totalPredictions'] ?? 0) ?></div>
      </div>
    </div>
    
    <!-- Last Signal -->
    <?php $signal = $multiplierWidget['lastSignal'] ?? null; ?>
    <?php if ($signal): ?>
    <div style="background:linear-gradient(135deg,rgba(99,102,241,.1),rgba(139,92,246,.05));border:1px solid rgba(99,102,241,.2);border-radius:var(--radius-sm);padding:16px">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <div style="font-size:12px;font-weight:600;color:var(--brand);text-transform:uppercase;letter-spacing:.06em">Latest AI Signal</div>
        <span class="statuspill" style="font-size:10px"><i class="pill-dot"></i><?= e(strtoupper($signal['risk'] ?? 'MEDIUM')) ?> Risk</span>
      </div>
      <div style="display:flex;align-items:baseline;gap:16px;flex-wrap:wrap">
        <div>
          <div style="font-size:11px;color:var(--dim)">Predicted Multiplier</div>
          <div style="font-size:28px;font-weight:800;color:#fff"><?= e(number_format($signal['predicted'] ?? 0, 2)) ?>x</div>
        </div>
        <div>
          <div style="font-size:11px;color:var(--dim)">Confidence</div>
          <div style="font-size:28px;font-weight:800;color:var(--brand)"><?= e(number_format(($signal['confidence'] ?? 0) * 100, 0)) ?>%</div>
        </div>
        <div style="flex:1"></div>
        <div style="font-size:11px;color:var(--dim);text-align:right">
          Generated <?= e(date('H:i:s', strtotime($signal['generatedAt'] ?? 'now'))) ?>
        </div>
      </div>
      <div style="font-size:10px;color:var(--dim);margin-top:10px;padding-top:10px;border-top:1px solid var(--line)">
        ⚠️ Educational purpose only — crash games are random. No prediction is guaranteed.
      </div>
      <?php $mIntg = $multiplierWidget['integration'] ?? []; ?>
      <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:10px;padding-top:10px;border-top:1px solid var(--line)">
        <span class="statuspill" style="font-size:10px;<?= !empty($mIntg['cloudflare']) ? 'border-color:var(--green)' : '' ?>">
          <i class="pill-dot" style="<?= !empty($mIntg['cloudflare']) ? 'background:var(--green)' : 'background:var(--dim)' ?>"></i>
          AI Agents <?= !empty($mIntg['cloudflare']) ? 'Active' : 'Standby' ?>
        </span>
        <span class="statuspill" style="font-size:10px;<?= !empty($mIntg['llm']) ? 'border-color:var(--green)' : '' ?>">
          <i class="pill-dot" style="<?= !empty($mIntg['llm']) ? 'background:var(--green)' : 'background:var(--dim)' ?>"></i>
          LLM <?= !empty($mIntg['llm']) ? 'Enhanced' : 'Standby' ?>
        </span>
        <span class="statuspill" style="font-size:10px;<?= !empty($mIntg['sports']) ? 'border-color:var(--green)' : '' ?>">
          <i class="pill-dot" style="<?= !empty($mIntg['sports']) ? 'background:var(--green)' : 'background:var(--dim)' ?>"></i>
          Sports Intel <?= !empty($mIntg['sports']) ? 'Enriching' : 'Awaiting' ?>
        </span>
      </div>
    </div>
    <?php else: ?>
    <p class="dim">No signals generated yet. <a href="/multiplier">Open Multiplier Intelligence →</a></p>
    <?php endif; ?>
  </div>
</section>

<h2 class="section-title">Language learning</h2>
<section class="panel" style="margin-bottom:18px">
  <div class="body">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:14px">
      <div>
        <h3 style="margin:0 0 4px">🗣️ AI Language Teacher</h3>
        <p class="dim" style="margin:0">Learn any of 20+ languages with AI-powered translation, listening, and speaking practice.</p>
      </div>
      <div style="display:flex;gap:8px">
        <a class="btn primary" href="/app/languages">Open Language Hub</a>
        <a class="btn" href="/app/languages/teacher">Start Lesson</a>
      </div>
    </div>
    
    <?php $langProfiles = $languageWidget['profiles'] ?? []; ?>
    <?php if (!empty($langProfiles)): ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:14px">
      <?php foreach ($langProfiles as $profile): ?>
        <div style="background:var(--panel2);border:1px solid var(--line);border-radius:var(--radius-sm);padding:14px">
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
            <div style="width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--brand),#8b5cf6);display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:700;color:#fff">
              <?= e(strtoupper(substr($profile['target_language'] ?? $profile['targetLanguage'] ?? '?', 0, 2))) ?>
            </div>
            <div>
              <div style="font-size:13px;font-weight:600;color:#fff"><?= e(ucfirst($profile['target_language'] ?? $profile['targetLanguage'] ?? 'Language')) ?></div>
              <div style="font-size:11px;color:var(--dim)"><?= e($profile['native_language'] ?? $profile['nativeLanguage'] ?? '') ?> → <?= e($profile['target_language'] ?? $profile['targetLanguage'] ?? '') ?></div>
            </div>
          </div>
          <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--dim)">
            <span>Level: <?= e($profile['level'] ?? 'Beginner') ?></span>
            <span>Profile #<?= e($profile['id'] ?? '?') ?></span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if (($languageWidget['totalProfiles'] ?? 0) > 3): ?>
      <p class="dim">+ <?= (int)$languageWidget['totalProfiles'] - 3 ?> more language profile<?= (int)$languageWidget['totalProfiles'] - 3 === 1 ? '' : 's' ?>. <a href="/app/languages">View all →</a></p>
    <?php endif; ?>
    <?php else: ?>
    <div style="text-align:center;padding:20px">
      <p class="dim" style="margin-bottom:12px">No language profiles yet. Start learning today!</p>
      <a class="btn primary" href="/app/languages/teacher">Create your first profile</a>
    </div>
    <?php endif; ?>
  </div>
</section>

<h2 class="section-title">Football &amp; sports intelligence</h2>
<?php
// Compact pointer card only: today's football predictions, live scores, the
// 30-day performance window and the model/calibration state all live on
// /football, and the ticket engine lives on /sports. Nothing is repeated here —
// one panel per figure, one source for each — and only real stored counts are
// shown (no marketing numbers for coverage the platform cannot verify).
$sportsProviders = $sportsWidget['providers'] ?? [];
$sportsFeeds = (int) ($sportsWidget['totalProviders'] ?? 0);
?>
<section class="panel" style="margin-bottom:18px">
  <div class="body">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap">
      <div>
        <h3 style="margin:0 0 4px">⚽ Football Intelligence</h3>
        <p class="dim" style="margin:0">
          <?php if ($sportsFeeds > 0): ?>
            <?= $sportsFeeds ?> connected data feed<?= $sportsFeeds === 1 ? '' : 's' ?> · fixtures, predictions, settlement and performance reported from stored data only.
          <?php else: ?>
            Football data provider not connected. Live fixtures and predictions are unavailable until a verified data source is configured.
          <?php endif; ?>
        </p>
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a class="btn primary" href="/football">Today's predictions</a>
        <a class="btn" href="/football/models">Models &amp; calibration</a>
        <a class="btn" href="/sports">Sports ticket engine</a>
      </div>
    </div>
  </div>
</section>

<h2 class="section-title">Windels AI Agents</h2>
<section class="panel" style="margin-bottom:18px">
  <div class="body">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:14px">
      <div>
        <h3 style="margin:0 0 4px">⚡ Windels AI Agents</h3>
        <p class="dim" style="margin:0">Specialized AI agents working together with tools, workflows, and multi-model routing.</p>
      </div>
      <div style="display:flex;gap:8px">
        <a class="btn primary" href="/app/agent-platform">Open Platform</a>
        <a class="btn" href="/app/workforce">AI Workforce</a>
      </div>
    </div>
    
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:14px">
      <div style="background:var(--panel2);border-radius:var(--radius-sm);padding:12px 14px">
        <div style="font-size:11px;color:var(--dim);text-transform:uppercase;letter-spacing:.04em">Windels AI Agents</div>
        <div style="font-size:20px;font-weight:700;color:#fff"><?= (int)($windelsAI['totalAgents'] ?? 0) ?></div>
      </div>
      <div style="background:var(--panel2);border-radius:var(--radius-sm);padding:12px 14px">
        <div style="font-size:11px;color:var(--dim);text-transform:uppercase;letter-spacing:.04em">MCP Tools</div>
        <div style="font-size:20px;font-weight:700;color:#fff"><?= (int)($windelsAI['totalTools'] ?? 0) ?></div>
      </div>
      <div style="background:var(--panel2);border-radius:var(--radius-sm);padding:12px 14px">
        <div style="font-size:11px;color:var(--dim);text-transform:uppercase;letter-spacing:.04em">Model Providers</div>
        <div style="font-size:20px;font-weight:700;color:#fff"><?= count($windelsAI['modelProviders'] ?? []) ?></div>
      </div>
      <div style="background:var(--panel2);border-radius:var(--radius-sm);padding:12px 14px">
        <div style="font-size:11px;color:var(--dim);text-transform:uppercase;letter-spacing:.04em">Platform Status</div>
        <div style="font-size:20px;font-weight:700;color:var(--green)"><?= !empty($windelsAI['totalAgents']) ? 'LIVE' : '—' ?></div>
      </div>
    </div>
    
    <?php if (!empty($windelsAI['agents'])): ?>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <?php foreach (array_slice($windelsAI['agents'], 0, 6) as $agent): ?>
        <span class="statuspill" style="font-size:10px">
          <i class="pill-dot" style="background:var(--green)"></i>
          <?= e(is_array($agent) ? ($agent['name'] ?? $agent['id'] ?? 'Agent') : (string)$agent) ?>
        </span>
      <?php endforeach; ?>
      <?php if (($windelsAI['totalAgents'] ?? 0) > 6): ?>
        <span class="statuspill" style="font-size:10px;opacity:.6">+<?= (int)$windelsAI['totalAgents'] - 6 ?> more</span>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</section>

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
