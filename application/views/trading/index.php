<?php defined('BASEPATH') or exit('No direct script access allowed');
/** @var array $accounts $positions $executions $proposals $connections $brokerStatus $history $analysis $riskLimits $supportedBrokers */
$ic = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">';
$ks = !empty($killSwitchActive);
$totalEq = (float)($totalEquity ?? 0);
$totalPnl = (float)($totalUnrealizedPnl ?? 0);
$fmt = fn(float $v) => number_format($v, 2, '.', ',');
?>
<style>
/* ─── Trading page ───────────────────────────────────────────── */
.trading-tabs{display:flex;gap:2px;border-bottom:1px solid var(--line);margin:0 0 18px;overflow-x:auto}
.trading-tabs button{background:none;border:none;color:var(--muted);padding:10px 18px;font:600 13px/1 inherit;cursor:pointer;border-bottom:2px solid transparent;white-space:nowrap;transition:all .15s}
.trading-tabs button:hover{color:var(--text)}
.trading-tabs button.active{color:var(--brand);border-bottom-color:var(--brand)}
.tab-panel{display:none}.tab-panel.active{display:block}
/* Stat cards */
.trading-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px;margin-bottom:20px}
.trading-stat{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:16px 18px}
.trading-stat .label{font-size:12px;color:var(--dim);text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px}
.trading-stat .value{font-size:22px;font-weight:700;color:#fff}
.trading-stat .sub{font-size:12px;margin-top:2px}
.pnl-pos{color:var(--green)}.pnl-neg{color:var(--red)}.pnl-zero{color:var(--dim)}
/* Broker cards */
.broker-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px}
.broker-card{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:16px;transition:border-color .15s}
.broker-card:hover{border-color:var(--line2)}
.broker-card .bc-head{display:flex;align-items:center;gap:10px;margin-bottom:10px}
.broker-card .bc-icon{width:36px;height:36px;border-radius:8px;background:var(--panel2);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;color:var(--brand);flex:none}
.broker-card .bc-name{font-weight:600;font-size:14px}
.broker-card .bc-label{font-size:12px;color:var(--dim)}
.broker-card .bc-row{display:flex;justify-content:space-between;padding:4px 0;font-size:13px}
.broker-card .bc-row span:first-child{color:var(--dim)}
/* Trade form */
.trade-form{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.trade-form .full{grid-column:1/-1}
.trade-form label{display:block;font-size:12px;color:var(--dim);margin-bottom:4px;text-transform:uppercase;letter-spacing:.03em}
.trade-form input,.trade-form select,.trade-form textarea{width:100%;background:var(--surface);border:1px solid var(--line);border-radius:var(--radius-sm);padding:10px 12px;color:var(--text);font:inherit}
.trade-form input:focus,.trade-form select:focus,.trade-form textarea:focus{outline:none;border-color:var(--brand)}
.trade-form textarea{resize:vertical;min-height:60px}
/* Position table */
.pos-table{width:100%;border-collapse:collapse}
.pos-table th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--dim);padding:8px 12px;border-bottom:1px solid var(--line)}
.pos-table td{padding:10px 12px;border-bottom:1px solid var(--line);font-size:13px}
.pos-table tr:hover{background:var(--elevated)}
/* AI card */
.ai-card{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:16px;margin-bottom:12px;transition:border-color .15s}
.ai-card:hover{border-color:var(--line2)}
.ai-card .ai-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
.ai-card .ai-symbol{font-weight:700;font-size:15px}
.ai-card .ai-bias{font-size:12px;padding:3px 10px;border-radius:20px;font-weight:600}
.ai-card .ai-body{font-size:13px;color:var(--muted)}
.ai-card .ai-meta{display:flex;gap:16px;margin-top:8px;font-size:12px;color:var(--dim)}
/* Status badges */
.badge-connected{background:#22c55e22;color:var(--green);padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600}
.badge-disconnected{background:#fb5d6b22;color:var(--red);padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600}
.badge-pending{background:#f5a62322;color:var(--amber);padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600}
/* Action buttons */
.trade-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
/* Quick action panel */
.quick-actions{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:20px}
.quick-action{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:14px 16px;display:flex;align-items:center;gap:12px;cursor:pointer;transition:all .15s;text-decoration:none;color:var(--text)}
.quick-action:hover{border-color:var(--brand);text-decoration:none;background:var(--brand-soft)}
.quick-action .qa-icon{width:38px;height:38px;border-radius:10px;background:var(--brand-soft);display:flex;align-items:center;justify-content:center;flex:none;color:var(--brand)}
.quick-action .qa-icon svg{width:20px;height:20px}
.quick-action .qa-text{font-size:13px;font-weight:600}
.quick-action .qa-desc{font-size:11px;color:var(--dim);font-weight:400}
/* Kill switch banner */
.ks-banner{background:var(--red);color:#fff;padding:12px 18px;border-radius:var(--radius);margin-bottom:16px;display:flex;align-items:center;gap:10px;font-size:13px;font-weight:600}
/* Responsive */
@media(max-width:768px){.trade-form{grid-template-columns:1fr}.trading-stats{grid-template-columns:1fr 1fr}.broker-grid{grid-template-columns:1fr}}
</style>

<div class="page-head">
  <div>
    <h2>My Trading</h2>
    <p>Connect your trading platforms and execute trades with AI intelligence — all under risk controls and approval governance.</p>
  </div>
  <div style="display:flex;gap:8px">
    <a class="btn primary" href="/brokers"><?= $ic ?><path d="M12 5v14M5 12h14"/></svg> Connect broker</a>
  </div>
</div>

<?php if ($ks): ?>
<div class="ks-banner">
  <?= $ic ?><path d="M12 3 4 6v6c0 4 3.5 7.5 8 9 4.5-1.5 8-5 8-9V6z"/></svg>
  <span>Kill switch is ACTIVE — all order placement is blocked until released.</span>
</div>
<?php endif; ?>

<?php if (!empty($notice)): ?><div class="notice ok"><?= e($notice) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="notice err"><?= e($error) ?></div><?php endif; ?>

<!-- Mode & Status Banner -->
<div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;align-items:center">
  <span class="statuspill <?= $ks ? 'warn' : '' ?>"><i class="pill-dot"></i><?= $ks ? 'Kill switch active' : 'Safeguards active' ?></span>
  <span class="statuspill"><i class="pill-dot"></i>Mode: <?= e($tradingMode ?? 'ANALYSIS_ONLY') ?></span>
  <span class="statuspill"><i class="pill-dot"></i><?= count($connections ?? []) ?> broker connection(s)</span>
  <span class="statuspill"><i class="pill-dot"></i><?= $openPositions ?? 0 ?> open position(s)</span>
</div>

<!-- Tab Navigation -->
<nav class="trading-tabs" id="trading-tabs">
  <button class="active" data-tab="overview"><?= $ic ?><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/></svg> Overview</button>
  <button data-tab="trade"><?= $ic ?><path d="M3 3v18h18"/><path d="m7 14 3-3 3 2 5-6"/></svg> Trade</button>
  <button data-tab="positions"><?= $ic ?><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 9h6M9 13h6M9 17h3"/></svg> Positions</button>
  <button data-tab="accounts"><?= $ic ?><path d="M3 21h18M5 21V8l7-4 7 4v13"/><path d="M9 21v-6h6v6"/></svg> Accounts</button>
  <button data-tab="ai"><?= $ic ?><rect x="4" y="6" width="16" height="13" rx="2"/><path d="M9 12h.01M15 12h.01M9 16h6"/></svg> AI Analysis</button>
</nav>

<!-- ═══════════════════════ TAB 1: OVERVIEW ═══════════════════════ -->
<section class="tab-panel active" id="tab-overview">
  <!-- Stats Row -->
  <div class="trading-stats">
    <div class="trading-stat">
      <div class="label">Portfolio Equity</div>
      <div class="value">$<?= $fmt($totalEq) ?></div>
      <div class="sub dim">Across <?= count($accounts ?? []) ?> account(s)</div>
    </div>
    <div class="trading-stat">
      <div class="label">Unrealized P&amp;L</div>
      <div class="value <?= $totalPnl > 0 ? 'pnl-pos' : ($totalPnl < 0 ? 'pnl-neg' : 'pnl-zero') ?>">
        <?= $totalPnl >= 0 ? '+' : '' ?>$<?= $fmt($totalPnl) ?>
      </div>
      <div class="sub dim"><?= $openPositions ?? 0 ?> open position(s)</div>
    </div>
    <div class="trading-stat">
      <div class="label">Today's Executions</div>
      <div class="value"><?= $todayExecutions ?? 0 ?></div>
      <div class="sub dim">Orders processed</div>
    </div>
    <div class="trading-stat">
      <div class="label">Pending Approval</div>
      <div class="value"><?= $pendingProposals ?? 0 ?></div>
      <div class="sub dim"><?= $approvedProposals ?? 0 ?> approved · <?= $rejectedProposals ?? 0 ?> rejected</div>
    </div>
  </div>

  <!-- Quick Actions -->
  <div class="quick-actions">
    <a class="quick-action" href="#" onclick="document.querySelector('[data-tab=trade]').click();return false">
      <span class="qa-icon"><?= $ic ?><path d="M3 3v18h18"/><path d="m7 14 3-3 3 2 5-6"/></svg></span>
      <span><span class="qa-text">New Trade</span><br><span class="qa-desc">Submit a trade proposal</span></span>
    </a>
    <a class="quick-action" href="#" onclick="document.querySelector('[data-tab=ai]').click();return false">
      <span class="qa-icon"><?= $ic ?><rect x="4" y="6" width="16" height="13" rx="2"/><path d="M12 2v4"/></svg></span>
      <span><span class="qa-text">AI Analysis</span><br><span class="qa-desc">Get trading recommendations</span></span>
    </a>
    <a class="quick-action" href="/brokers">
      <span class="qa-icon"><?= $ic ?><path d="M12 5v14M5 12h14"/></svg></span>
      <span><span class="qa-text">Connect Broker</span><br><span class="qa-desc">MT4, MT5, Binance & more</span></span>
    </a>
    <a class="quick-action" href="/risk">
      <span class="qa-icon"><?= $ic ?><path d="M12 3 4 6v6c0 4 3.5 7.5 8 9 4.5-1.5 8-5 8-9V6z"/></svg></span>
      <span><span class="qa-text">Risk Center</span><br><span class="qa-desc">Monitor risk controls</span></span>
    </a>
  </div>

  <!-- Connected Brokers -->
  <h3 style="margin-bottom:10px">Connected Broker Accounts</h3>
  <?php if (empty($brokerStatus)): ?>
    <div class="panel"><div class="body">
      <p class="dim">No broker connections yet.</p>
      <a class="btn primary" href="/brokers" style="margin-top:8px">Connect your first broker</a>
    </div></div>
  <?php else: ?>
    <div class="broker-grid">
      <?php foreach ($brokerStatus as $bs): ?>
        <div class="broker-card">
          <div class="bc-head">
            <div class="bc-icon"><?= strtoupper(mb_substr($bs['broker'] ?? '', 0, 2)) ?></div>
            <div>
              <div class="bc-name"><?= e($supportedBrokers[$bs['broker']]['label'] ?? ucfirst($bs['broker'])) ?></div>
              <div class="bc-label"><?= e($bs['label'] ?? 'Default') ?></div>
            </div>
          </div>
          <div class="bc-row"><span>Status</span><span class="<?= !empty($bs['enabled']) ? 'badge-connected' : 'badge-disconnected' ?>"><?= !empty($bs['enabled']) ? ($bs['status'] ?? 'connected') : 'disconnected' ?></span></div>
          <?php if (!empty($bs['latencyMs'])): ?><div class="bc-row"><span>Latency</span><span><?= (int)$bs['latencyMs'] ?>ms</span></div><?php endif; ?>
          <div class="bc-row"><span>Trading</span><span><?= !empty($bs['trading']) ? '<span class="badge-connected">enabled</span>' : '<span class="badge-disconnected">read-only</span>' ?></span></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- Recent Executions -->
  <h3 style="margin:20px 0 10px">Recent Executions</h3>
  <div class="panel"><div class="body">
    <?php if (empty($executions)): ?>
      <p class="dim">No executions recorded yet.</p>
    <?php else: ?>
      <table class="tbl">
        <thead><tr><th>Symbol</th><th>Side</th><th>Status</th><th>Time</th></tr></thead>
        <tbody>
          <?php foreach (array_slice($executions, 0, 8) as $x): ?>
            <tr>
              <td class="mono"><?= e($x['symbol'] ?? '—') ?></td>
              <td><?= e($x['side'] ?? $x['orderSide'] ?? '—') ?></td>
              <td><span class="badge <?= ($x['status'] ?? '')==='FILLED'?'b-green':(($x['status']??'')==='REJECTED'?'b-red':'b-amber') ?>"><?= e($x['status'] ?? '—') ?></span></td>
              <td class="dim"><?= e(substr((string)($x['created_at'] ?? $x['createdAt'] ?? '—'), 0, 16)) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div></div>
</section>

<!-- ═══════════════════════ TAB 2: TRADE ═══════════════════════ -->
<section class="tab-panel" id="tab-trade">
  <div class="panel" style="margin-bottom:18px"><div class="body">
    <p class="dim" style="margin-bottom:12px">Submit a trade proposal. The AI risk engine, kill switch, account gates and approval workflow all run before any order reaches a broker.</p>
    <?php if ($ks): ?>
      <div class="ks-banner" style="margin-bottom:14px">
        <?= $ic ?><path d="M12 3 4 6v6c0 4 3.5 7.5 8 9 4.5-1.5 8-5 8-9V6z"/></svg>
        Trading is blocked — the kill switch is active. Release it to resume trading.
      </div>
    <?php endif; ?>
    <form id="trade-form" class="trade-form" onsubmit="return submitTrade(event)">
      <div>
        <label for="tf-symbol">Symbol</label>
        <input id="tf-symbol" name="symbol" placeholder="EURUSD, BTCUSD, AAPL…" required autocomplete="off">
      </div>
      <div>
        <label for="tf-side">Side</label>
        <select id="tf-side" name="side" required>
          <option value="BUY">Buy (Long)</option>
          <option value="SELL">Sell (Short)</option>
        </select>
      </div>
      <div>
        <label for="tf-volume">Volume / Lots</label>
        <input id="tf-volume" name="volume" type="number" step="0.01" min="0.01" value="0.10" required>
      </div>
      <div>
        <label for="tf-broker">Broker</label>
        <select id="tf-broker" name="broker">
          <option value="">Any connected broker</option>
          <?php foreach (($connections ?? []) as $c): if (empty($c['enabled'])) continue; ?>
            <option value="<?= e($c['broker']) ?>"><?= e(($supportedBrokers[$c['broker']]['label'] ?? ucfirst($c['broker']))) ?> — <?= e($c['label'] ?? 'Default') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="tf-sl">Stop Loss</label>
        <input id="tf-sl" name="stopLoss" type="number" step="any" placeholder="Optional">
      </div>
      <div>
        <label for="tf-tp">Take Profit</label>
        <input id="tf-tp" name="takeProfit" type="number" step="any" placeholder="Optional">
      </div>
      <div>
        <label for="tf-confidence">AI Confidence</label>
        <input id="tf-confidence" name="confidence" type="number" step="0.05" min="0" max="1" value="0.50">
      </div>
      <div>
        <label for="tf-reason">Reason / Notes</label>
        <input id="tf-reason" name="reason" placeholder="Why this trade?">
      </div>
      <div class="full trade-actions">
        <button type="submit" class="btn primary" <?= $ks ? 'disabled' : '' ?>>
          <?= $ic ?><path d="M5 12h14M13 6l6 6-6 6"/></svg> Submit Trade Proposal
        </button>
        <a class="btn" href="/analysis">Get AI recommendation first</a>
      </div>
    </form>
    <div id="trade-result" style="margin-top:14px"></div>
  </div></div>

  <!-- Pending Proposals -->
  <h3>Pending Proposals</h3>
  <div class="panel"><div class="body">
    <?php $pendingList = array_filter($proposals ?? [], fn($p) => in_array($p['status'] ?? '', ['PENDING', 'PENDING_APPROVAL'], true)); ?>
    <?php if (empty($pendingList)): ?>
      <p class="dim">No pending proposals.</p>
    <?php else: ?>
      <table class="tbl">
        <thead><tr><th>Symbol</th><th>Side</th><th>Confidence</th><th>Status</th><th>Created</th></tr></thead>
        <tbody>
          <?php foreach ($pendingList as $p): ?>
            <tr>
              <td class="mono"><?= e($p['symbol'] ?? '—') ?></td>
              <td><?= e($p['side'] ?? '—') ?></td>
              <td><?= isset($p['confidence']) ? e(number_format((float)$p['confidence'] * 100, 0) . '%') : '—' ?></td>
              <td><span class="badge b-amber"><?= e($p['status'] ?? 'PENDING') ?></span></td>
              <td class="dim"><?= e(substr((string)($p['created_at'] ?? '—'), 0, 16)) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div></div>
</section>

<!-- ═══════════════════════ TAB 3: POSITIONS ═══════════════════════ -->
<section class="tab-panel" id="tab-positions">
  <div class="panel"><div class="body">
    <?php if (empty($positions)): ?>
      <div style="text-align:center;padding:30px 0">
        <p class="dim" style="font-size:15px">No open positions.</p>
        <p class="dim">Connect a broker and submit a trade to see positions here.</p>
        <a class="btn primary" href="#" onclick="document.querySelector('[data-tab=trade]').click();return false" style="margin-top:10px">Open Trade tab</a>
      </div>
    <?php else: ?>
      <table class="pos-table">
        <thead><tr><th>Broker</th><th>Symbol</th><th>Side</th><th>Volume</th><th>Entry</th><th>Current</th><th>P&amp;L</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($positions as $p): ?>
            <?php $pnl = (float)($p['unrealizedPnl'] ?? $p['pnl'] ?? 0); ?>
            <tr>
              <td><?= e($p['broker'] ?? '') ?></td>
              <td class="mono"><?= e($p['symbol'] ?? '') ?></td>
              <td><span class="badge <?= ($p['side'] ?? '')==='BUY'?'b-green':'b-red' ?>"><?= e($p['side'] ?? '') ?></span></td>
              <td><?= e((string)($p['volume'] ?? '')) ?></td>
              <td><?= e((string)($p['entryPrice'] ?? $p['openPrice'] ?? '—')) ?></td>
              <td><?= e((string)($p['currentPrice'] ?? $p['closePrice'] ?? '—')) ?></td>
              <td class="<?= $pnl >= 0 ? 'pnl-pos' : 'pnl-neg' ?>"><?= $pnl >= 0 ? '+' : '' ?>$<?= $fmt($pnl) ?></td>
              <td>
                <button class="btn small" onclick="closePosition('<?= e($p['broker'] ?? '') ?>','<?= e($p['id'] ?? $p['ticket'] ?? '') ?>')">Close</button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div></div>
</section>

<!-- ═══════════════════════ TAB 4: ACCOUNTS ═══════════════════════ -->
<section class="tab-panel" id="tab-accounts">
  <!-- Account Summary -->
  <div class="trading-stats" style="margin-bottom:18px">
    <div class="trading-stat">
      <div class="label">Total Equity</div>
      <div class="value">$<?= $fmt($totalEq) ?></div>
    </div>
    <div class="trading-stat">
      <div class="label">Connected Brokers</div>
      <div class="value"><?= count(array_filter($brokerStatus ?? [], fn($b) => !empty($b['enabled']))) ?></div>
    </div>
    <div class="trading-stat">
      <div class="label">Total Positions</div>
      <div class="value"><?= $openPositions ?? 0 ?></div>
    </div>
    <div class="trading-stat">
      <div class="label">Trading Mode</div>
      <div class="value" style="font-size:15px"><?= e($tradingMode ?? 'ANALYSIS_ONLY') ?></div>
    </div>
  </div>

  <!-- Account Cards -->
  <h3 style="margin-bottom:10px">Broker Accounts</h3>
  <?php if (empty($accounts)): ?>
    <div class="panel"><div class="body">
      <p class="dim">No connected accounts yet. Connect a broker to start trading.</p>
      <a class="btn primary" href="/brokers" style="margin-top:10px">Connect a broker</a>
    </div></div>
  <?php else: ?>
    <div class="broker-grid">
      <?php foreach ($accounts as $a): ?>
        <?php if (!empty($a['error'])): ?>
          <div class="broker-card" style="border-color:var(--red)">
            <div class="bc-head">
              <div class="bc-icon" style="color:var(--red)"><?= strtoupper(mb_substr($a['broker'] ?? '', 0, 2)) ?></div>
              <div><div class="bc-name"><?= e($a['broker']) ?> — <?= e($a['label'] ?? '') ?></div></div>
            </div>
            <p style="color:var(--red);font-size:13px"><?= e($a['error']) ?></p>
          </div>
        <?php else: ?>
          <div class="broker-card">
            <div class="bc-head">
              <div class="bc-icon"><?= strtoupper(mb_substr($a['broker'] ?? '', 0, 2)) ?></div>
              <div>
                <div class="bc-name"><?= e($a['broker']) ?></div>
                <div class="bc-label"><?= e($a['label'] ?? '') ?></div>
              </div>
            </div>
            <div class="bc-row"><span>Account</span><span class="mono"><?= e((string)($a['accountId'] ?? $a['account'] ?? '—')) ?></span></div>
            <div class="bc-row"><span>Balance</span><span>$<?= $fmt((float)($a['balance'] ?? 0)) ?></span></div>
            <div class="bc-row"><span>Equity</span><strong>$<?= $fmt((float)($a['equity'] ?? $a['balance'] ?? 0)) ?></strong></div>
            <?php if (isset($a['margin'])): ?><div class="bc-row"><span>Margin</span><span>$<?= $fmt((float)$a['margin']) ?></span></div><?php endif; ?>
            <?php if (isset($a['freeMargin'])): ?><div class="bc-row"><span>Free margin</span><span>$<?= $fmt((float)$a['freeMargin']) ?></span></div><?php endif; ?>
            <div class="bc-row"><span>Currency</span><span><?= e($a['currency'] ?? '') ?></span></div>
            <div style="margin-top:8px"><a class="btn small" href="/brokers/connect/<?= e($a['broker']) ?>">Manage</a></div>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- Supported Platforms -->
  <h3 style="margin:20px 0 10px">Supported Platforms</h3>
  <div class="broker-grid">
    <?php foreach ($supportedBrokers as $id => $info): ?>
      <?php $isConnected = !empty(array_filter($connections ?? [], fn($c) => $c['broker'] === $id)); ?>
      <div class="broker-card" style="<?= $isConnected ? 'border-color:var(--green)' : '' ?>">
        <div class="bc-head">
          <div class="bc-icon"><?= strtoupper(mb_substr($id, 0, 2)) ?></div>
          <div>
            <div class="bc-name"><?= e($info['label'] ?? ucfirst($id)) ?></div>
            <div class="bc-label"><?= $isConnected ? '<span class="badge-connected">connected</span>' : 'not connected' ?></div>
          </div>
        </div>
        <p class="dim" style="font-size:12px;margin:4px 0"><?= e($info['description'] ?? '') ?></p>
        <a class="btn small" href="/brokers/connect/<?= e($id) ?>"><?= $isConnected ? 'Manage' : 'Connect' ?></a>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<!-- ═══════════════════════ TAB 5: AI ANALYSIS ═══════════════════════ -->
<section class="tab-panel" id="tab-ai">
  <div style="display:flex;gap:10px;margin-bottom:16px;align-items:center;flex-wrap:wrap">
    <span class="statuspill"><i class="pill-dot"></i><?= count($analysis ?? []) ?> AI provider(s) active</span>
    <a class="btn primary" href="/analysis"><?= $ic ?><rect x="4" y="6" width="16" height="13" rx="2"/><path d="M12 2v4"/></svg> Run new analysis</a>
  </div>

  <!-- AI Providers Status -->
  <h3 style="margin-bottom:10px">AI Provider Health</h3>
  <div class="broker-grid" style="margin-bottom:20px">
    <?php foreach ($analysis ?? [] as $name => $health): ?>
      <div class="broker-card">
        <div class="bc-head">
          <div class="bc-icon" style="font-size:11px"><?= strtoupper(mb_substr($name, 0, 3)) ?></div>
          <div>
            <div class="bc-name"><?= e(ucfirst(str_replace(['_','-'], ' ', $name))) ?></div>
            <div class="bc-label"><span class="<?= ($health['status'] ?? '')==='LIVE'?'badge-connected':'badge-disconnected' ?>"><?= e($health['status'] ?? 'UNKNOWN') ?></span></div>
          </div>
        </div>
        <?php if (!empty($health['responseMs'])): ?><div class="bc-row"><span>Latency</span><span><?= (int)$health['responseMs'] ?>ms</span></div><?php endif; ?>
        <?php if (!empty($health['symbols'])): ?><div class="bc-row"><span>Symbols</span><span><?= count((array)$health['symbols']) ?></span></div><?php endif; ?>
      </div>
    <?php endforeach; ?>
    <?php if (empty($analysis)): ?>
      <p class="dim">No AI providers active.</p>
    <?php endif; ?>
  </div>

  <!-- Recent AI Signals -->
  <h3 style="margin-bottom:10px">Recent AI Signals & Recommendations</h3>
  <?php if (empty($history)): ?>
    <div class="panel"><div class="body">
      <p class="dim">No analysis runs stored yet. Run your first AI analysis to see trading signals here.</p>
      <a class="btn primary" href="/analysis" style="margin-top:8px">Run AI analysis</a>
    </div></div>
  <?php else: ?>
    <?php foreach ($history as $h): ?>
      <div class="ai-card">
        <div class="ai-head">
          <span class="ai-symbol"><?= e($h['symbol'] ?? '—') ?></span>
          <?php $biasClass = match(strtolower($h['bias'] ?? '')) { 'bullish','long','buy' => 'background:#22c55e22;color:var(--green)', 'bearish','short','sell' => 'background:#fb5d6b22;color:var(--red)', default => 'background:#f5a62322;color:var(--amber)' }; ?>
          <span class="ai-bias" style="<?= $biasClass ?>"><?= e(ucfirst($h['bias'] ?? 'NEUTRAL')) ?></span>
        </div>
        <div class="ai-body">
          <?= e($h['reasoning'] ?? $h['summary'] ?? 'Multi-agent consensus analysis') ?>
        </div>
        <div class="ai-meta">
          <span>Timeframe: <?= e($h['timeframe'] ?? '—') ?></span>
          <?php if (!empty($h['confidence'])): ?><span>Confidence: <?= e(number_format((float)$h['confidence'] * 100, 0)) ?>%</span><?php endif; ?>
          <span><?= e(substr((string)($h['completed_at'] ?? ''), 0, 16)) ?></span>
        </div>
        <?php if (!empty($h['setup'])): ?>
          <div style="margin-top:8px;padding-top:8px;border-top:1px solid var(--line);font-size:12px;color:var(--dim)">
            <?php $setup = is_array($h['setup']) ? $h['setup'] : []; ?>
            <?php if (!empty($setup['entry'])): ?>Entry: <?= e((string)$setup['entry']) ?><?php endif; ?>
            <?php if (!empty($setup['stopLoss'])): ?> · SL: <?= e((string)$setup['stopLoss']) ?><?php endif; ?>
            <?php if (!empty($setup['takeProfit'])): ?> · TP: <?= e(is_array($setup['takeProfit']) ? implode(', ', $setup['takeProfit']) : (string)$setup['takeProfit']) ?><?php endif; ?>
          </div>
        <?php endif; ?>
        <div class="trade-actions" style="margin-top:10px">
          <button class="btn small primary" onclick="useSignal(<?= e(json_encode($h)) ?>)">Trade this signal</button>
          <a class="btn small" href="/analysis">Details</a>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</section>

<script>
/* ─── Tab switching ──────────────────────────────────────────── */
document.querySelectorAll('#trading-tabs button').forEach(function(btn){
  btn.addEventListener('click', function(){
    document.querySelectorAll('#trading-tabs button').forEach(function(b){b.classList.remove('active')});
    document.querySelectorAll('.tab-panel').forEach(function(p){p.classList.remove('active')});
    btn.classList.add('active');
    var panel = document.getElementById('tab-' + btn.dataset.tab);
    if(panel) panel.classList.add('active');
  });
});

/* ─── Submit trade ──────────────────────────────────────────── */
function submitTrade(ev){
  ev.preventDefault();
  var form = ev.target;
  var data = {
    symbol: form.symbol.value, side: form.side.value,
    volume: parseFloat(form.volume.value), broker: form.broker.value,
    stopLoss: form.stopLoss.value ? parseFloat(form.stopLoss.value) : null,
    takeProfit: form.takeProfit.value ? [parseFloat(form.takeProfit.value)] : null,
    confidence: parseFloat(form.confidence.value || 0.5),
    reason: form.reason.value || 'Manual trade from My Trading'
  };
  var resultEl = document.getElementById('trade-result');
  resultEl.innerHTML = '<div class="notice ok">Submitting trade proposal…</div>';
  fetch('/app/trading/submit_order', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify(data)
  }).then(function(r){return r.json()}).then(function(res){
    if(res.ok){
      resultEl.innerHTML = '<div class="notice ok"><b>Trade proposal submitted!</b> '+escapeHtml(res.message||'')+'</div>';
      form.reset();
    } else {
      resultEl.innerHTML = '<div class="notice err"><b>Error:</b> '+escapeHtml(res.error||'Unknown error')+'</div>';
    }
  }).catch(function(err){
    resultEl.innerHTML = '<div class="notice err"><b>Network error:</b> '+escapeHtml(err.message)+'</div>';
  });
  return false;
}

/* ─── Close position ────────────────────────────────────────── */
function closePosition(broker, positionId){
  if(!confirm('Close this position?')) return;
  var fd = new FormData();
  fd.append('broker', broker);
  fd.append('positionId', positionId);
  fetch('/app/trading/close_position', {method:'POST', body: fd})
    .then(function(r){return r.json()})
    .then(function(res){
      if(res.ok) { alert('Position close requested.'); location.reload(); }
      else { alert('Error: '+(res.error||'Unknown')); }
    }).catch(function(e){ alert('Network error: '+e.message); });
}

/* ─── Use signal from AI analysis ───────────────────────────── */
function useSignal(signal){
  document.querySelector('[data-tab=trade]').click();
  var f = document.getElementById('trade-form');
  if(f.symbol) f.symbol.value = signal.symbol || '';
  if(f.side) f.side.value = (signal.bias||'').match(/bull|long|buy/i) ? 'BUY' : 'SELL';
  if(f.confidence && signal.confidence) f.confidence.value = signal.confidence;
  if(f.reason) f.reason.value = 'From AI analysis: '+(signal.reasoning||signal.summary||'').substring(0,100);
  var setup = signal.setup || {};
  if(f.stopLoss && setup.stopLoss) f.stopLoss.value = setup.stopLoss;
  if(f.takeProfit && setup.takeProfit){
    var tp = Array.isArray(setup.takeProfit) ? setup.takeProfit[0] : setup.takeProfit;
    if(tp) f.takeProfit.value = tp;
  }
  f.symbol.focus();
}

/* ─── Escape HTML ────────────────────────────────────────────── */
function escapeHtml(s){var d=document.createElement('div');d.textContent=s||'';return d.innerHTML;}

/* ─── Auto-refresh widget data every 30s ─────────────────────── */
(function(){
  var timer = setInterval(function(){
    fetch('/app/trading/widget_data').then(function(r){return r.json()}).then(function(d){
      // Update overview stats if on that tab
      var eqEl = document.querySelector('#tab-overview .trading-stat:first-child .value');
      if(eqEl && d.totalEquity !== undefined) eqEl.textContent = '$'+Number(d.totalEquity).toLocaleString('en-US',{minimumFractionDigits:2});
    }).catch(function(){});
  }, 30000);
})();
</script>
