<?php defined('BASEPATH') or exit('No direct script access allowed');
/** @var array $accounts $positions $executions $proposals $connections $brokerStatus $history $analysis $riskLimits $supportedBrokers $journalEntries $perfSummary $calibration $riskAlerts */
$ic = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">';
$ks = !empty($killSwitchActive);
$totalEq = (float)($totalEquity ?? 0);
$totalPnl = (float)($totalUnrealizedPnl ?? 0);
$fmt = fn(float $v) => number_format($v, 2, '.', ',');
$overall = $perfSummary['overall'] ?? [];
?>
<style>
/* ─── Tabs ──────────────────────────────────────────────────────── */
.trading-tabs{display:flex;gap:2px;border-bottom:1px solid var(--line);margin:0 0 18px;overflow-x:auto}
.trading-tabs button{background:none;border:none;color:var(--muted);padding:10px 16px;font:600 13px/1 inherit;cursor:pointer;border-bottom:2px solid transparent;white-space:nowrap;transition:all .15s}
.trading-tabs button:hover{color:var(--text)}.trading-tabs button.active{color:var(--brand);border-bottom-color:var(--brand)}
.tab-panel{display:none}.tab-panel.active{display:block}
/* ─── Stats ─────────────────────────────────────────────────────── */
.t-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:18px}
.t-stat{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:14px 16px}
.t-stat .lbl{font-size:11px;color:var(--dim);text-transform:uppercase;letter-spacing:.04em;margin-bottom:3px}
.t-stat .val{font-size:20px;font-weight:700;color:#fff}
.t-stat .sub{font-size:11px;margin-top:2px}
.pnl-pos{color:var(--green)}.pnl-neg{color:var(--red)}.pnl-zero{color:var(--dim)}
/* ─── Chart ─────────────────────────────────────────────────────── */
.chart-wrap{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:16px;margin-bottom:18px}
.chart-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px;flex-wrap:wrap}
.chart-controls{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.chart-controls select,.chart-controls button{background:var(--surface);border:1px solid var(--line);border-radius:6px;padding:5px 10px;color:var(--text);font:12px/1 inherit;cursor:pointer}
.chart-controls button.active{border-color:var(--brand);color:var(--brand)}
.chart-controls button:hover{border-color:var(--line2)}
.chart-svg{width:100%;height:240px;background:var(--bg);border-radius:8px}
.chart-quote{display:flex;gap:20px;font-size:13px;margin-top:8px;flex-wrap:wrap}
.chart-quote span b{font-size:15px}
/* ─── Signals ───────────────────────────────────────────────────── */
.signal-card{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:14px 16px;margin-bottom:10px;transition:border-color .15s}
.signal-card:hover{border-color:var(--line2)}
.signal-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px}
.signal-symbol{font-weight:700;font-size:15px}
.signal-dir{font-size:11px;padding:3px 10px;border-radius:20px;font-weight:700}
.dir-buy{background:#22c55e22;color:var(--green)}.dir-sell{background:#fb5d6b22;color:var(--red)}.dir-neutral{background:#f5a62322;color:var(--amber)}
.conf-high{color:var(--green)}.conf-med{color:var(--amber)}.conf-low{color:var(--red)}
.signal-body{font-size:12px;color:var(--muted);margin-bottom:8px}
.signal-meta{display:flex;gap:14px;font-size:11px;color:var(--dim);flex-wrap:wrap;margin-bottom:8px}
.signal-meta b{color:var(--text)}
.signal-risk{display:flex;gap:8px;font-size:11px;flex-wrap:wrap}
.risk-pass{color:var(--green)}.risk-fail{color:var(--red)}
.signal-actions{display:flex;gap:6px;margin-top:10px}
.signal-filter{display:flex;gap:6px;margin-bottom:12px;flex-wrap:wrap}
.signal-filter button{background:var(--surface);border:1px solid var(--line);border-radius:6px;padding:4px 12px;color:var(--muted);font:600 11px/1 inherit;cursor:pointer}
.signal-filter button.active{border-color:var(--brand);color:var(--brand)}
/* ─── Risk ──────────────────────────────────────────────────────── */
.risk-alert{display:flex;align-items:flex-start;gap:10px;padding:10px 14px;border-radius:var(--radius-sm);margin-bottom:8px;font-size:13px}
.risk-alert.critical{background:#fb5d6b15;border:1px solid #fb5d6b33}.risk-alert.warning{background:#f5a62315;border:1px solid #f5a62333}.risk-alert.info{background:#3b82f615;border:1px solid #3b82f633}
.risk-alert .ra-icon{flex:none;width:20px;height:20px;margin-top:1px}
.risk-toggle{display:flex;align-items:center;gap:10px;padding:10px 0}
.risk-toggle label{font-size:13px;font-weight:500}
.toggle-switch{position:relative;width:44px;height:24px;cursor:pointer}
.toggle-switch input{opacity:0;width:0;height:0}
.toggle-slider{position:absolute;inset:0;background:var(--line2);border-radius:24px;transition:.2s}
.toggle-slider:before{content:'';position:absolute;height:18px;width:18px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.2s}
.toggle-switch input:checked+.toggle-slider{background:var(--red)}
.toggle-switch input:checked+.toggle-slider:before{transform:translateX(20px)}
.toggle-switch.green input:checked+.toggle-slider{background:var(--green)}
/* ─── Performance ───────────────────────────────────────────────── */
.perf-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:18px}
.perf-card{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:14px}
.perf-card .pc-label{font-size:11px;color:var(--dim);text-transform:uppercase;letter-spacing:.04em}
.perf-card .pc-val{font-size:22px;font-weight:700;color:#fff;margin:4px 0}
.perf-card .pc-sub{font-size:11px;color:var(--dim)}
.equity-curve{width:100%;height:160px;background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);margin-bottom:18px}
/* ─── Broker cards ──────────────────────────────────────────────── */
.broker-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px}
.broker-card{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:16px;transition:border-color .15s}
.broker-card:hover{border-color:var(--line2)}
.broker-card .bc-head{display:flex;align-items:center;gap:10px;margin-bottom:10px}
.broker-card .bc-icon{width:36px;height:36px;border-radius:8px;background:var(--panel2);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;color:var(--brand);flex:none}
.broker-card .bc-name{font-weight:600;font-size:14px}.broker-card .bc-label{font-size:12px;color:var(--dim)}
.broker-card .bc-row{display:flex;justify-content:space-between;padding:4px 0;font-size:13px}
.broker-card .bc-row span:first-child{color:var(--dim)}
/* ─── Trade form ────────────────────────────────────────────────── */
.trade-form{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.trade-form .full{grid-column:1/-1}
.trade-form label{display:block;font-size:11px;color:var(--dim);margin-bottom:3px;text-transform:uppercase;letter-spacing:.03em}
.trade-form input,.trade-form select,.trade-form textarea{width:100%;background:var(--surface);border:1px solid var(--line);border-radius:var(--radius-sm);padding:9px 11px;color:var(--text);font:inherit}
.trade-form input:focus,.trade-form select:focus{outline:none;border-color:var(--brand)}
/* ─── Position table ────────────────────────────────────────────── */
.pos-table{width:100%;border-collapse:collapse}
.pos-table th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--dim);padding:8px 10px;border-bottom:1px solid var(--line)}
.pos-table td{padding:9px 10px;border-bottom:1px solid var(--line);font-size:13px}
.pos-table tr:hover{background:var(--elevated)}
/* ─── AI cards ──────────────────────────────────────────────────── */
.ai-card{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:16px;margin-bottom:12px;transition:border-color .15s}
.ai-card:hover{border-color:var(--line2)}
.ai-card .ai-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
.ai-card .ai-symbol{font-weight:700;font-size:15px}
.ai-card .ai-bias{font-size:12px;padding:3px 10px;border-radius:20px;font-weight:600}
.ai-card .ai-body{font-size:13px;color:var(--muted)}
.ai-card .ai-meta{display:flex;gap:16px;margin-top:8px;font-size:12px;color:var(--dim)}
/* ─── Badges ────────────────────────────────────────────────────── */
.badge-connected{background:#22c55e22;color:var(--green);padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600}
.badge-disconnected{background:#fb5d6b22;color:var(--red);padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600}
.badge-pending{background:#f5a62322;color:var(--amber);padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600}
/* ─── Quick actions ─────────────────────────────────────────────── */
.quick-actions{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-bottom:18px}
.quick-action{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:12px 14px;display:flex;align-items:center;gap:10px;cursor:pointer;transition:all .15s;text-decoration:none;color:var(--text)}
.quick-action:hover{border-color:var(--brand);text-decoration:none;background:var(--brand-soft)}
.quick-action .qa-icon{width:34px;height:34px;border-radius:8px;background:var(--brand-soft);display:flex;align-items:center;justify-content:center;flex:none;color:var(--brand)}
.quick-action .qa-icon svg{width:18px;height:18px}
.quick-action .qa-text{font-size:13px;font-weight:600}
.quick-action .qa-desc{font-size:10px;color:var(--dim);font-weight:400}
/* ─── Kill switch ───────────────────────────────────────────────── */
.ks-banner{background:var(--red);color:#fff;padding:12px 18px;border-radius:var(--radius);margin-bottom:16px;display:flex;align-items:center;gap:10px;font-size:13px;font-weight:600}
/* ─── Responsive ────────────────────────────────────────────────── */
@media(max-width:768px){.trade-form{grid-template-columns:1fr}.t-stats{grid-template-columns:1fr 1fr}.broker-grid{grid-template-columns:1fr}.chart-head{flex-direction:column;align-items:flex-start}}
</style>

<div class="page-head">
  <div>
    <h2>My Trading</h2>
    <p>Connect your platforms, view AI signals with live charts, execute trades, and monitor performance — all under enterprise risk controls.</p>
  </div>
  <div style="display:flex;gap:8px">
    <a class="btn primary" href="/brokers"><?= $ic ?><path d="M12 5v14M5 12h14"/></svg> Connect broker</a>
  </div>
</div>

<?php if ($ks): ?>
<div class="ks-banner"><?= $ic ?><path d="M12 3 4 6v6c0 4 3.5 7.5 8 9 4.5-1.5 8-5 8-9V6z"/></svg><span>Kill switch is ACTIVE — all order placement is blocked until released.</span></div>
<?php endif; ?>
<?php if (!empty($notice)): ?><div class="notice ok"><?= e($notice) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="notice err"><?= e($error) ?></div><?php endif; ?>

<div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;align-items:center">
  <span class="statuspill <?= $ks ? 'warn' : '' ?>"><i class="pill-dot"></i><?= $ks ? 'Kill switch active' : 'Safeguards active' ?></span>
  <span class="statuspill"><i class="pill-dot"></i>Mode: <?= e($tradingMode ?? 'ANALYSIS_ONLY') ?></span>
  <span class="statuspill"><i class="pill-dot"></i><?= count($connections ?? []) ?> connection(s)</span>
  <span class="statuspill"><i class="pill-dot"></i><?= $openPositions ?? 0 ?> position(s)</span>
</div>

<!-- ═══════════ TAB NAV ═══════════ -->
<nav class="trading-tabs" id="trading-tabs">
  <button class="active" data-tab="overview"><?= $ic ?><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/></svg> Overview</button>
  <button data-tab="trade"><?= $ic ?><path d="M3 3v18h18"/><path d="m7 14 3-3 3 2 5-6"/></svg> Trade</button>
  <button data-tab="positions"><?= $ic ?><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 9h6M9 13h6M9 17h3"/></svg> Positions</button>
  <button data-tab="accounts"><?= $ic ?><path d="M3 21h18M5 21V8l7-4 7 4v13"/><path d="M9 21v-6h6v6"/></svg> Accounts</button>
  <button data-tab="ai"><?= $ic ?><rect x="4" y="6" width="16" height="13" rx="2"/><path d="M9 12h.01M15 12h.01M9 16h6"/></svg> AI Analysis</button>
  <button data-tab="performance"><?= $ic ?><path d="M18 20V10M12 20V4M6 20v-6"/></svg> Performance &amp; Risk</button>
</nav>

<!-- ═══════════ TAB 1: OVERVIEW (Chart + Signals + Stats) ═══════════ -->
<section class="tab-panel active" id="tab-overview">
  <!-- Quick stats -->
  <div class="t-stats">
    <div class="t-stat"><div class="lbl">Portfolio Equity</div><div class="val" id="ov-equity">$<?= $fmt($totalEq) ?></div><div class="sub dim"><?= count($accounts ?? []) ?> account(s)</div></div>
    <div class="t-stat"><div class="lbl">Unrealized P&amp;L</div><div class="val <?= $totalPnl>0?'pnl-pos':($totalPnl<0?'pnl-neg':'pnl-zero') ?>" id="ov-pnl"><?= $totalPnl>=0?'+':'' ?>$<?= $fmt($totalPnl) ?></div><div class="sub dim"><?= $openPositions??0 ?> open</div></div>
    <div class="t-stat"><div class="lbl">Today's Trades</div><div class="val"><?= $todayExecutions??0 ?></div><div class="sub dim"><?= $pendingProposals??0 ?> pending</div></div>
    <div class="t-stat"><div class="lbl">Win Rate</div><div class="val"><?= $overall['winRate']!==null?e(number_format($overall['winRate']*100,1)).'%':'—' ?></div><div class="sub dim"><?= $overall['closedTrades']??0 ?> closed trades</div></div>
  </div>

  <!-- Quick actions -->
  <div class="quick-actions">
    <a class="quick-action" href="#" onclick="document.querySelector('[data-tab=trade]').click();return false"><span class="qa-icon"><?= $ic ?><path d="M3 3v18h18"/><path d="m7 14 3-3 3 2 5-6"/></svg></span><span><span class="qa-text">New Trade</span><br><span class="qa-desc">Submit a proposal</span></span></a>
    <a class="quick-action" href="#" onclick="document.querySelector('[data-tab=performance]').click();return false"><span class="qa-icon"><?= $ic ?><path d="M18 20V10M12 20V4M6 20v-6"/></svg></span><span><span class="qa-text">Performance</span><br><span class="qa-desc">View analytics & risk</span></span></a>
    <a class="quick-action" href="/brokers"><span class="qa-icon"><?= $ic ?><path d="M12 5v14M5 12h14"/></svg></span><span><span class="qa-text">Connect Broker</span><br><span class="qa-desc">MT4, MT5, Binance…</span></span></a>
    <a class="quick-action" href="/risk"><span class="qa-icon"><?= $ic ?><path d="M12 3 4 6v6c0 4 3.5 7.5 8 9 4.5-1.5 8-5 8-9V6z"/></svg></span><span><span class="qa-text">Risk Center</span><br><span class="qa-desc">Full risk controls</span></span></a>
  </div>

  <!-- ═══ TradingChart Component ═══ -->
  <div class="chart-wrap">
    <div class="chart-head">
      <div style="display:flex;align-items:center;gap:10px">
        <h3 style="margin:0" id="chart-title">EURUSD</h3>
        <span class="badge b-gray" id="chart-badge" style="font-size:10px">LOADING</span>
      </div>
      <div class="chart-controls">
        <select id="chart-symbol-select"><option>EURUSD</option><option>GBPUSD</option><option>BTCUSD</option><option>ETHUSD</option><option>XAUUSD</option><option>AAPL</option><option>SPY</option><option>QQQ</option></select>
        <button data-tf="5m">5m</button><button data-tf="15m">15m</button><button data-tf="30m">30m</button><button class="active" data-tf="1h">1H</button><button data-tf="4h">4H</button><button data-tf="1d">1D</button><button data-tf="1w">1W</button>
      </div>
    </div>
    <svg id="trading-chart-svg" class="chart-svg" viewBox="0 0 1000 240" preserveAspectRatio="none"></svg>
    <div class="chart-quote" id="chart-quote"></div>
  </div>

  <!-- ═══ TradingSignals Component ═══ -->
  <h3 style="margin-bottom:8px">AI Trading Signals</h3>
  <div class="signal-filter" id="signal-filter">
    <button class="active" data-filter="all">All</button>
    <button data-filter="active">Active</button>
    <button data-filter="high">High confidence</button>
    <button data-filter="buy">Buy only</button>
    <button data-filter="sell">Sell only</button>
  </div>
  <div id="signals-container">
    <p class="dim">Loading signals…</p>
  </div>
  <div style="margin-top:8px;display:flex;gap:8px;align-items:center">
    <span class="dim" style="font-size:11px" id="signals-refresh">Auto-refresh: 30s</span>
    <a class="btn small" href="/analysis">Run new analysis</a>
  </div>
</section>

<!-- ═══════════ TAB 2: TRADE ═══════════ -->
<section class="tab-panel" id="tab-trade">
  <div class="panel" style="margin-bottom:16px"><div class="body">
    <p class="dim" style="margin-bottom:10px">Submit a trade proposal. The AI risk engine, kill switch, account gates and approval workflow all run before any order reaches a broker.</p>
    <?php if ($ks): ?><div class="ks-banner" style="margin-bottom:12px"><?= $ic ?><path d="M12 3 4 6v6c0 4 3.5 7.5 8 9 4.5-1.5 8-5 8-9V6z"/></svg> Trading blocked — kill switch active.</div><?php endif; ?>
    <form id="trade-form" class="trade-form" onsubmit="return submitTrade(event)">
      <div><label>Symbol</label><input name="symbol" id="tf-symbol" placeholder="EURUSD, BTCUSD, AAPL…" required></div>
      <div><label>Side</label><select name="side" id="tf-side" required><option value="BUY">Buy (Long)</option><option value="SELL">Sell (Short)</option></select></div>
      <div><label>Volume / Lots</label><input name="volume" type="number" step="0.01" min="0.01" value="0.10" required></div>
      <div><label>Broker</label><select name="broker"><option value="">Any connected broker</option><?php foreach(($connections??[]) as $c):if(empty($c['enabled']))continue;?><option value="<?=e($c['broker'])?>"><?=e(($supportedBrokers[$c['broker']]['label']??ucfirst($c['broker'])))?> — <?=e($c['label']??'Default')?></option><?php endforeach;?></select></div>
      <div><label>Stop Loss</label><input name="stopLoss" type="number" step="any" placeholder="Optional"></div>
      <div><label>Take Profit</label><input name="takeProfit" type="number" step="any" placeholder="Optional"></div>
      <div><label>AI Confidence</label><input name="confidence" type="number" step="0.05" min="0" max="1" value="0.50"></div>
      <div><label>Reason</label><input name="reason" placeholder="Why this trade?"></div>
      <div class="full" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
        <button type="submit" class="btn primary" <?=$ks?'disabled':''?>><?= $ic ?><path d="M5 12h14M13 6l6 6-6 6"/></svg> Submit Proposal</button>
        <a class="btn" href="/analysis">Get AI recommendation</a>
      </div>
    </form>
    <div id="trade-result" style="margin-top:12px"></div>
  </div></div>
  <h3>Pending Proposals</h3>
  <div class="panel"><div class="body">
    <?php $pendingList = array_filter($proposals??[],fn($p)=>in_array($p['status']??'',['PENDING','PENDING_APPROVAL'],true));?>
    <?php if(empty($pendingList)):?><p class="dim">No pending proposals.</p><?php else:?>
    <table class="tbl"><thead><tr><th>Symbol</th><th>Side</th><th>Confidence</th><th>Status</th><th>Created</th></tr></thead><tbody>
    <?php foreach($pendingList as $p):?><tr><td class="mono"><?=e($p['symbol']??'—')?></td><td><?=e($p['side']??'—')?></td><td><?=isset($p['confidence'])?e(number_format((float)$p['confidence']*100,0).'%'):'—'?></td><td><span class="badge b-amber"><?=e($p['status']??'PENDING')?></span></td><td class="dim"><?=e(substr((string)($p['created_at']??'—'),0,16))?></td></tr><?php endforeach;?>
    </tbody></table><?php endif;?>
  </div></div>
</section>

<!-- ═══════════ TAB 3: POSITIONS ═══════════ -->
<section class="tab-panel" id="tab-positions">
  <div class="panel"><div class="body">
    <?php if(empty($positions)):?>
      <div style="text-align:center;padding:30px 0"><p class="dim" style="font-size:15px">No open positions.</p><a class="btn primary" href="#" onclick="document.querySelector('[data-tab=trade]').click();return false" style="margin-top:10px">Open Trade tab</a></div>
    <?php else:?>
    <table class="pos-table"><thead><tr><th>Broker</th><th>Symbol</th><th>Side</th><th>Volume</th><th>Entry</th><th>Current</th><th>P&amp;L</th><th></th></tr></thead><tbody>
    <?php foreach($positions as $p):$pnl=(float)($p['unrealizedPnl']??$p['pnl']??0);?>
      <tr><td><?=e($p['broker']??'')?></td><td class="mono"><?=e($p['symbol']??'')?></td><td><span class="badge <?=($p['side']??'')==='BUY'?'b-green':'b-red'?>"><?=e($p['side']??'')?></span></td><td><?=e((string)($p['volume']??''))?></td><td><?=e((string)($p['entryPrice']??$p['openPrice']??'—'))?></td><td><?=e((string)($p['currentPrice']??'—'))?></td><td class="<?=$pnl>=0?'pnl-pos':'pnl-neg'?>"><?=$pnl>=0?'+':''?>$<?=number_format($pnl,2)?></td><td><button class="btn small" onclick="closePosition('<?=e($p['broker']??'')?>','<?=e($p['id']??$p['ticket']??'')?>')">Close</button></td></tr>
    <?php endforeach;?></tbody></table>
    <?php endif;?>
  </div></div>
</section>

<!-- ═══════════ TAB 4: ACCOUNTS ═══════════ -->
<section class="tab-panel" id="tab-accounts">
  <div class="t-stats" style="margin-bottom:16px">
    <div class="t-stat"><div class="lbl">Total Equity</div><div class="val">$<?=$fmt($totalEq)?></div></div>
    <div class="t-stat"><div class="lbl">Connected</div><div class="val"><?=count(array_filter($brokerStatus??[],fn($b)=>!empty($b['enabled'])))?></div></div>
    <div class="t-stat"><div class="lbl">Positions</div><div class="val"><?=$openPositions??0?></div></div>
    <div class="t-stat"><div class="lbl">Mode</div><div class="val" style="font-size:14px"><?=e($tradingMode??'ANALYSIS_ONLY')?></div></div>
  </div>
  <h3 style="margin-bottom:10px">Broker Accounts</h3>
  <?php if(empty($accounts)):?><div class="panel"><div class="body"><p class="dim">No connected accounts yet.</p><a class="btn primary" href="/brokers" style="margin-top:8px">Connect a broker</a></div></div><?php else:?>
  <div class="broker-grid">
    <?php foreach($accounts as $a):?>
      <?php if(!empty($a['error'])):?><div class="broker-card" style="border-color:var(--red)"><div class="bc-head"><div class="bc-icon" style="color:var(--red)"><?=strtoupper(mb_substr($a['broker']??'',0,2))?></div><div><div class="bc-name"><?=e($a['broker'])?> — <?=e($a['label']??'')?></div></div></div><p style="color:var(--red);font-size:13px"><?=e($a['error'])?></p></div>
      <?php else:?>
      <div class="broker-card"><div class="bc-head"><div class="bc-icon"><?=strtoupper(mb_substr($a['broker']??'',0,2))?></div><div><div class="bc-name"><?=e($a['broker'])?></div><div class="bc-label"><?=e($a['label']??'')?></div></div></div>
        <div class="bc-row"><span>Account</span><span class="mono"><?=e((string)($a['accountId']??$a['account']??'—'))?></span></div>
        <div class="bc-row"><span>Balance</span><span>$<?=$fmt((float)($a['balance']??0))?></span></div>
        <div class="bc-row"><span>Equity</span><strong>$<?=$fmt((float)($a['equity']??$a['balance']??0))?></strong></div>
        <?php if(isset($a['currency'])):?><div class="bc-row"><span>Currency</span><span><?=e($a['currency'])?></span></div><?php endif;?>
        <div style="margin-top:8px"><a class="btn small" href="/brokers/connect/<?=e($a['broker'])?>">Manage</a></div>
      </div><?php endif;?>
    <?php endforeach;?>
  </div><?php endif;?>
  <h3 style="margin:18px 0 10px">Supported Platforms</h3>
  <div class="broker-grid"><?php foreach($supportedBrokers as $id=>$info):$conn=!empty(array_filter($connections??[],fn($c)=>$c['broker']===$id));?>
    <div class="broker-card" style="<?=$conn?'border-color:var(--green)':''?>"><div class="bc-head"><div class="bc-icon"><?=strtoupper(mb_substr($id,0,2))?></div><div><div class="bc-name"><?=e($info['label']??ucfirst($id))?></div><div class="bc-label"><?=$conn?'<span class="badge-connected">connected</span>':'not connected'?></div></div></div><p class="dim" style="font-size:12px;margin:4px 0"><?=e($info['description']??'')?></p><a class="btn small" href="/brokers/connect/<?=e($id)?>"><?=$conn?'Manage':'Connect'?></a></div>
  <?php endforeach;?></div>
</section>

<!-- ═══════════ TAB 5: AI ANALYSIS ═══════════ -->
<section class="tab-panel" id="tab-ai">
  <div style="display:flex;gap:10px;margin-bottom:14px;align-items:center;flex-wrap:wrap">
    <span class="statuspill"><i class="pill-dot"></i><?=count($analysis??[])?> AI provider(s) active</span>
    <a class="btn primary" href="/analysis"><?= $ic ?><rect x="4" y="6" width="16" height="13" rx="2"/><path d="M12 2v4"/></svg> Run new analysis</a>
  </div>
  <h3 style="margin-bottom:10px">AI Provider Health</h3>
  <div class="broker-grid" style="margin-bottom:18px"><?php foreach($analysis??[] as $name=>$health):?>
    <div class="broker-card"><div class="bc-head"><div class="bc-icon" style="font-size:11px"><?=strtoupper(mb_substr($name,0,3))?></div><div><div class="bc-name"><?=e(ucfirst(str_replace(['_','-'],' ',$name)))?></div><div class="bc-label"><span class="<?=($health['status']??'')==='LIVE'?'badge-connected':'badge-disconnected'?>"><?=e($health['status']??'UNKNOWN')?></span></div></div></div>
    <?php if(!empty($health['responseMs'])):?><div class="bc-row"><span>Latency</span><span><?=e((string)(int)$health['responseMs'])?>ms</span></div><?php endif;?>
    </div><?php endforeach;?><?php if(empty($analysis)):?><p class="dim">No AI providers active.</p><?php endif;?></div>
  <h3 style="margin-bottom:10px">Recent AI Signals</h3>
  <?php if(empty($history)):?><div class="panel"><div class="body"><p class="dim">No analysis runs yet.</p><a class="btn primary" href="/analysis" style="margin-top:8px">Run AI analysis</a></div></div><?php else:?>
  <?php foreach($history as $h):$biasClass=match(strtolower($h['bias']??'')){'bullish','long','buy'=>'background:#22c55e22;color:var(--green)','bearish','short','sell'=>'background:#fb5d6b22;color:var(--red)',default=>'background:#f5a62322;color:var(--amber)'};?>
    <div class="ai-card"><div class="ai-head"><span class="ai-symbol"><?=e($h['symbol']??'—')?></span><span class="ai-bias" style="<?=$biasClass?>"><?=e(ucfirst($h['bias']??'NEUTRAL'))?></span></div>
      <div class="ai-body"><?=e($h['reasoning']??$h['summary']??'Multi-agent consensus analysis')?></div>
      <div class="ai-meta"><span>TF: <?=e($h['timeframe']??'—')?></span><?php if(!empty($h['confidence'])):?><span>Confidence: <?=e(number_format((float)$h['confidence']*100,0))?>%</span><?php endif;?> <span><?=e(substr((string)($h['completed_at']??''),0,16))?></span></div>
      <div style="margin-top:10px"><button class="btn small primary" onclick='useSignal(<?=e(json_encode($h))?>)'>Trade this signal</button> <a class="btn small" href="/analysis">Details</a></div>
    </div><?php endforeach;?><?php endif;?>
</section>

<!-- ═══════════ TAB 6: PERFORMANCE & RISK ═══════════ -->
<section class="tab-panel" id="tab-performance">
  <!-- Performance Metrics -->
  <h3 style="margin-bottom:10px">Performance Metrics</h3>
  <div class="perf-grid">
    <div class="perf-card"><div class="pc-label">Win Rate</div><div class="pc-val"><?=($overall['winRate']??null)!==null?e(number_format($overall['winRate']*100,1)).'%':'—'?></div><div class="pc-sub"><?=e((string)($overall['closedTrades']??0))?> trades</div></div>
    <div class="perf-card"><div class="pc-label">Profit Factor</div><div class="pc-val"><?=($overall['profitFactor']??null)!==null?e(number_format($overall['profitFactor'],2)):'—'?></div><div class="pc-sub">Gross win / gross loss</div></div>
    <div class="perf-card"><div class="pc-label">Total P&amp;L</div><div class="pc-val <?=($overall['totalPnl']??0)>=0?'pnl-pos':'pnl-neg'?>"><?=($overall['totalPnl']??0)>=0?'+':''?><?=e(number_format($overall['totalPnl']??0,0))?></div><div class="pc-sub">All closed trades</div></div>
    <div class="perf-card"><div class="pc-label">Expectancy</div><div class="pc-val"><?=($overall['expectancyPnl']??null)!==null?e(number_format($overall['expectancyPnl'],1)):'—'?></div><div class="pc-sub">E[P&amp;L] per trade</div></div>
    <div class="perf-card"><div class="pc-label">Max Drawdown</div><div class="pc-val pnl-neg"><?=e(number_format($overall['maxDrawdownAbs']??0,0))?></div><div class="pc-sub">Peak-to-trough</div></div>
    <div class="perf-card"><div class="pc-label">Avg R-Multiple</div><div class="pc-val"><?=($overall['avgRMultiple']??null)!==null?e(number_format($overall['avgRMultiple'],2)):'—'?></div><div class="pc-sub">Risk-adjusted returns</div></div>
  </div>

  <!-- Equity Curve -->
  <h3 style="margin-bottom:8px">Equity Curve</h3>
  <svg id="equity-curve-svg" class="equity-curve" viewBox="0 0 800 160" preserveAspectRatio="none"></svg>

  <div class="grid cols-main" style="margin-top:18px">
    <div>
      <!-- Risk Alerts -->
      <h3 style="margin-bottom:10px">Active Risk Alerts</h3>
      <div id="risk-alerts-container">
        <?php if(empty($riskAlerts)):?>
          <div class="risk-alert info"><span class="ra-icon"><?= $ic ?><path d="M12 3 4 6v6c0 4 3.5 7.5 8 9 4.5-1.5 8-5 8-9V6z"/><path d="M9 12l2 2 4-4"/></svg><div><b>No active risk alerts</b><br><span style="font-size:12px;color:var(--dim)">All risk controls are within normal parameters.</span></div></div>
        <?php else:?>
          <?php foreach($riskAlerts as $alert):$sev=strtolower($alert['severity']??'info');$cls=$sev==='critical'?'critical':($sev==='warning'?'warning':'info');?>
          <div class="risk-alert <?=$cls?>"><div><b><?=e($alert['code']??'Alert')?></b> — <?=e($alert['detail']??'')?><br><span style="font-size:11px;color:var(--dim)"><?=e($alert['scope']??'')?> · <?=e(ucfirst($sev))?></span></div></div>
          <?php endforeach;?>
        <?php endif;?>
      </div>

      <!-- Confidence Calibration -->
      <h3 style="margin:18px 0 10px">Confidence Calibration</h3>
      <div class="panel"><div class="body">
        <div class="notice <?=!empty($calibration['sufficientData'])?'ok':'warnbox'?>"><?=e($calibration['verdict']??'No data')?></div>
        <?php foreach(($calibration['buckets']??[]) as $b):?>
        <div style="margin:6px 0"><div style="display:flex;justify-content:space-between;font-size:12px"><span><?=e($b['key'])?></span><span class="dim"><?=e((string)$b['count'])?> trades · Win <?=($b['winRate']??null)!==null?e(number_format($b['winRate']*100,0)).'%':'—'?></span></div><div style="background:var(--line);border-radius:4px;height:6px;margin-top:3px"><div style="width:<?=round(($b['winRate']??0)*100)?>%;background:<?=($b['winRate']??0)>=0.5?'var(--green)':'var(--red)'?>;height:100%;border-radius:4px"></div></div></div>
        <?php endforeach;?>
        <?php if(empty($calibration['buckets'])):?><p class="dim" style="font-size:12px">No confidence-tagged closed trades yet.</p><?php endif;?>
      </div></div>
    </div>

    <div>
      <!-- Risk Controls -->
      <h3 style="margin-bottom:10px">Risk Controls</h3>
      <div class="panel"><div class="body">
        <div class="risk-toggle">
          <label class="toggle-switch"><input type="checkbox" id="ks-toggle" <?=$ks?'checked':''?> onchange="toggleKillSwitch(this.checked)"><span class="toggle-slider"></span></label>
          <label style="font-weight:600;color:<?=$ks?'var(--red)':'var(--text)'?>">Kill Switch <?= $ks ? '(ACTIVE)' : '' ?></label>
        </div>
        <div style="border-top:1px solid var(--line);margin:10px 0;padding-top:10px">
          <div class="bc-row"><span>Max daily trades</span><b><?=e((string)($riskLimits['maxDailyTrades']??'—'))?></b></div>
          <div class="bc-row"><span>Max notional / trade</span><b>$<?=e(number_format((float)($riskLimits['maxTradeNotionalUsd']??0),0))?></b></div>
          <div class="bc-row"><span>Max risk / trade</span><b><?=isset($riskLimits['maxRiskPerTradePct'])?e(number_format((float)$riskLimits['maxRiskPerTradePct']*100,2)).'%':'—'?></b></div>
          <div class="bc-row"><span>Approved symbols</span><b><?=count($riskLimits['approvedSymbols']??[])?:'All'?></b></div>
        </div>
        <a class="btn" href="/risk" style="margin-top:10px">Open Risk Center</a>
      </div></div>

      <!-- Portfolio Concentration -->
      <h3 style="margin:18px 0 10px">Portfolio Concentration</h3>
      <div class="panel"><div class="body" id="concentration-container">
        <p class="dim">Loading…</p>
      </div></div>

      <!-- Streaks -->
      <h3 style="margin:18px 0 10px">Streaks</h3>
      <div class="perf-grid" style="grid-template-columns:repeat(3,1fr)">
        <div class="perf-card"><div class="pc-label">Current</div><div class="pc-val" id="streak-current">—</div></div>
        <div class="perf-card"><div class="pc-label">Best Win</div><div class="pc-val pnl-pos" id="streak-best">—</div></div>
        <div class="perf-card"><div class="pc-label">Worst Losing</div><div class="pc-val pnl-neg" id="streak-worst">—</div></div>
      </div>
    </div>
  </div>

  <!-- Trade Journal -->
  <h3 style="margin:18px 0 10px">Trade Journal</h3>
  <div class="panel"><div class="body" style="max-height:400px;overflow-y:auto">
    <?php if(empty($journalEntries)):?><p class="dim">No journal entries yet.</p><?php else:?>
    <table class="tbl"><thead><tr><th>Time</th><th>Src</th><th>Symbol</th><th>Dir</th><th>Entry</th><th>Exit</th><th>P&amp;L</th><th>R</th><th>Conf</th></tr></thead><tbody>
    <?php foreach(array_slice($journalEntries,0,30) as $en):?>
      <tr><td class="dim"><?=e(substr($en['entry_time'],0,16))?></td><td><span class="badge <?=['backtest'=>'b-sky','paper'=>'b-violet','manual'=>'b-gray','live'=>'b-red'][$en['source']]??'b-gray'?>" style="padding:0 5px"><?=e($en['source'])?></span></td><td class="mono" style="font-weight:700"><?=e($en['symbol'])?></td><td class="<?=$en['direction']==='LONG'?'pnl-pos':'pnl-neg'?>"><?= $en['direction']==='LONG'?'▲':'▼'?></td><td><?=e(number_format($en['entry_price'],5))?></td><td><?=$en['exit_price']!==null?e(number_format($en['exit_price'],5)):'open'?></td><td class="<?=($en['pnl']??0)>=0?'pnl-pos':'pnl-neg'?>"><?= $en['pnl']!==null?e(number_format($en['pnl'],1)):'—'?></td><td class="dim"><?= $en['r_multiple']!==null?e(number_format($en['r_multiple'],2)):'—'?></td><td class="dim"><?= $en['ai_confidence']!==null?e(number_format($en['ai_confidence']*100,0)).'%':'—'?></td></tr>
    <?php endforeach;?></tbody></table><?php endif;?>
  </div><a class="panel-foot-link" href="/journal">Full analytics →</a></div>
</section>

<!-- ═══════════ JAVASCRIPT ═══════════ -->
<script>
(function(){
'use strict';
/* ─── Tab switching ────────────────────────────────────────── */
document.querySelectorAll('#trading-tabs button').forEach(function(btn){
  btn.addEventListener('click',function(){
    document.querySelectorAll('#trading-tabs button').forEach(function(b){b.classList.remove('active')});
    document.querySelectorAll('.tab-panel').forEach(function(p){p.classList.remove('active')});
    btn.classList.add('active');
    var panel=document.getElementById('tab-'+btn.dataset.tab);
    if(panel)panel.classList.add('active');
    if(btn.dataset.tab==='performance')loadPerformance();
  });
});

/* ═══════════════════════════════════════════════════════════════
   COMPONENT 1: TradingChart
   ═══════════════════════════════════════════════════════════════ */
var chartSvg=document.getElementById('trading-chart-svg');
var chartSymbol='EURUSD',chartTf='1h',chartData=[];
var tfBtns=document.querySelectorAll('.chart-controls button[data-tf]');
var symSelect=document.getElementById('chart-symbol-select');
tfBtns.forEach(function(b){b.addEventListener('click',function(){tfBtns.forEach(function(x){x.classList.remove('active')});b.classList.add('active');chartTf=b.dataset.tf;loadChart();})});
if(symSelect)symSelect.addEventListener('change',function(){chartSymbol=symSelect.value;loadChart()});

function loadChart(){
  document.getElementById('chart-title').textContent=chartSymbol;
  document.getElementById('chart-badge').textContent='LOADING';
  document.getElementById('chart-badge').className='badge b-gray';
  fetch('/app/trading/chart_data?symbol='+encodeURIComponent(chartSymbol)+'&timeframe='+chartTf+'&limit=120')
  .then(function(r){return r.json()})
  .then(function(d){
    chartData=d.candles||[];
    var badge=document.getElementById('chart-badge');
    if(d.error){badge.textContent='NO DATA';badge.className='badge b-red';return;}
    if(d.synthetic){badge.textContent='SIMULATION';badge.className='badge b-amber';}
    else if(d.delayed){badge.textContent='DELAYED';badge.className='badge b-gray';}
    else if(d.live){badge.textContent='LIVE';badge.className='badge b-green';}
    else{badge.textContent='READY';badge.className='badge b-sky';}
    renderChart(chartData);
    renderQuote(d.quote,chartSymbol);
  }).catch(function(){document.getElementById('chart-badge').textContent='ERROR';document.getElementById('chart-badge').className='badge b-red';});
}

function renderChart(candles){
  if(!chartSvg||!candles||!candles.length){if(chartSvg)chartSvg.innerHTML='<text x="500" y="120" text-anchor="middle" fill="#5e6b82" font-size="13">No chart data available</text>';return;}
  var W=1000,H=240,padL=10,padR=60,padT=8,padB=20,volH=40;
  var priceH=H-padT-padB-volH-8;
  var n=candles.length;var barW=Math.max(2,(W-padL-padR)/n);
  var hi=-Infinity,lo=Infinity,maxV=0;
  candles.forEach(function(c){if(c.h>hi)hi=c.h;if(c.l<lo)lo=c.l;if(c.v>maxV)maxV=c.v;});
  var range=hi-lo||1;var pScale=priceH/range;var vScale=volH/(maxV||1);
  var svg='';
  // Grid lines
  for(var i=0;i<5;i++){var y=padT+priceH*i/4;svg+='<line x1="'+padL+'" y1="'+y+'" x2="'+(W-padR)+'" y2="'+y+'" stroke="#1e2738" stroke-width="0.5"/>';}
  // Price labels
  for(var i=0;i<=4;i++){var price=hi-range*i/4;var y=padT+priceH*i/4;svg+='<text x="'+(W-padR+6)+'" y="'+(y+4)+'" fill="#5e6b82" font-size="10">'+price.toFixed(price>100?1:4)+'</text>';}
  // Candles
  candles.forEach(function(c,idx){
    var x=padL+idx*barW+barW/2;
    var yO=padT+(hi-c.o)*pScale;var yC=padT+(hi-c.c)*pScale;
    var yH=padT+(hi-c.h)*pScale;var yL=padT+(hi-c.l)*pScale;
    var up=c.c>=c.o;var col=up?'#26a69a':'#ef5350';
    svg+='<line x1="'+x+'" y1="'+yH+'" x2="'+x+'" y2="'+yL+'" stroke="'+col+'" stroke-width="1"/>';
    svg+='<rect x="'+(x-barW*0.35)+'" y="'+Math.min(yO,yC)+'" width="'+(barW*0.7)+'" height="'+Math.max(1,Math.abs(yC-yO))+'" fill="'+col+'" rx="0.5"/>';
    // Volume
    var vy=padT+priceH+8+volH-c.v*vScale;
    svg+='<rect x="'+(x-barW*0.3)+'" y="'+vy+'" width="'+(barW*0.6)+'" height="'+(c.v*vScale)+'" fill="'+(up?'#134e4a':'#4c1d24')+'" rx="0.5"/>';
  });
  chartSvg.innerHTML=svg;
}

function renderQuote(q,sym){
  var el=document.getElementById('chart-quote');if(!el)return;
  if(!q){el.innerHTML='<span class="dim">No quote data</span>';return;}
  var chg=q.c-(q.o||q.c);var pct=q.o?((chg/q.o)*100).toFixed(2):'0.00';
  var cls=chg>=0?'pnl-pos':'pnl-neg';
  el.innerHTML='<span>Price: <b class="'+cls+'">'+(q.c||0).toFixed(q.c>100?2:5)+'</b></span><span>Change: <b class="'+cls+'">'+(chg>=0?'+':'')+chg.toFixed(q.c>100?2:5)+' ('+pct+'%)</b></span><span>High: <b>'+q.h.toFixed(q.h>100?2:5)+'</b></span><span>Low: <b>'+q.l.toFixed(q.l>100?2:5)+'</b></span>';
}
loadChart();

/* ═══════════════════════════════════════════════════════════════
   COMPONENT 2: TradingSignals
   ═══════════════════════════════════════════════════════════════ */
var allSignals=[],activeFilter='all';
function loadSignals(){
  fetch('/app/trading/signals').then(function(r){return r.json()}).then(function(d){
    allSignals=d.signals||[];renderSignals();
    document.getElementById('signals-refresh').textContent='Auto-refresh: 30s · '+allSignals.length+' signal(s)';
  }).catch(function(){document.getElementById('signals-container').innerHTML='<p class="dim">Could not load signals.</p>';});
}
function renderSignals(){
  var el=document.getElementById('signals-container');
  var filtered=allSignals.filter(function(s){
    if(activeFilter==='all')return true;
    if(activeFilter==='active')return s.status==='ACTIVE';
    if(activeFilter==='high')return s.confidenceLevel==='HIGH';
    if(activeFilter==='buy')return s.direction==='BUY';
    if(activeFilter==='sell')return s.direction==='SELL';
    return true;
  });
  if(!filtered.length){el.innerHTML='<p class="dim">No signals match this filter. Run AI analysis to generate new signals.</p>';return;}
  var html='';
  filtered.forEach(function(s){
    var dirCls=s.direction==='BUY'?'dir-buy':(s.direction==='SELL'?'dir-sell':'dir-neutral');
    var confCls=s.confidenceLevel==='HIGH'?'conf-high':(s.confidenceLevel==='MEDIUM'?'conf-med':'conf-low');
    var riskHtml='';
    Object.keys(s.riskChecks||{}).forEach(function(k){var pass=s.riskChecks[k];riskHtml+='<span class="'+(pass?'risk-pass':'risk-fail')+'">'+(pass?'✓':'✗')+' '+k.replace(/([A-Z])/g,' $1').trim()+'</span>';});
    html+='<div class="signal-card"><div class="signal-head"><span class="signal-symbol">'+esc(s.symbol)+'</span><div style="display:flex;gap:6px;align-items:center"><span class="signal-dir '+dirCls+'">'+esc(s.direction)+'</span><span class="'+confCls+'" style="font-size:12px;font-weight:600">'+esc(s.confidenceLevel)+'</span></div></div>';
    html+='<div class="signal-body">'+esc(s.reasoning||'AI multi-agent analysis')+'</div>';
    html+='<div class="signal-meta"><span>TF: <b>'+esc(s.timeframe)+'</b></span><span>Confidence: <b class="'+confCls+'">'+(s.confidence*100).toFixed(0)+'%</b></span>';
    if(s.entry)html+='<span>Entry: <b>'+Number(s.entry).toFixed(s.entry>100?2:5)+'</b></span>';
    if(s.stopLoss)html+='<span>SL: <b>'+Number(s.stopLoss).toFixed(s.stopLoss>100?2:5)+'</b></span>';
    if(s.takeProfit){var tp=Array.isArray(s.takeProfit)?s.takeProfit[0]:s.takeProfit;html+='<span>TP: <b>'+Number(tp).toFixed(tp>100?2:5)+'</b></span>';}
    if(s.riskReward)html+='<span>R:R <b>1:'+s.riskReward+'</b></span>';
    html+='</div><div class="signal-risk">'+riskHtml+'</div>';
    html+='<div class="signal-actions"><button class="btn small primary" onclick=\'tradeFromSignal('+JSON.stringify(s).replace(/'/g,"&#39;")+')\'>Trade</button><button class="btn small" onclick="document.querySelector(\'[data-tab=trade]\').click();useSignalData('+JSON.stringify(s).replace(/'/g,'&quot;')+')">Edit</button></div></div>';
  });
  el.innerHTML=html;
}
document.querySelectorAll('#signal-filter button').forEach(function(btn){
  btn.addEventListener('click',function(){
    document.querySelectorAll('#signal-filter button').forEach(function(b){b.classList.remove('active')});
    btn.classList.add('active');activeFilter=btn.dataset.filter;renderSignals();
  });
});
loadSignals();

/* ═══════════════════════════════════════════════════════════════
   COMPONENT 3: RiskManagement
   ═══════════════════════════════════════════════════════════════ */
function loadRiskDashboard(){
  fetch('/app/trading/risk_dashboard').then(function(r){return r.json()}).then(function(d){
    // Update concentration
    var cc=document.getElementById('concentration-container');
    if(cc){
      var conc=d.concentrations||{};var keys=Object.keys(conc);
      if(!keys.length){cc.innerHTML='<p class="dim" style="font-size:12px">No open positions to analyze.</p>';}
      else{
        var total=0;keys.forEach(function(k){total+=conc[k];});
        var html='';keys.slice(0,8).forEach(function(k){
          var pct=total>0?((conc[k]/total)*100).toFixed(1):0;
          var warn=parseFloat(pct)>40;
          html+='<div style="margin:4px 0"><div style="display:flex;justify-content:space-between;font-size:12px"><span class="mono" style="font-weight:600">'+esc(k)+'</span><span'+(warn?' style="color:var(--red)"':'')+'>'+pct+'%</span></div>';
          html+='<div style="background:var(--line);border-radius:4px;height:5px;margin-top:2px"><div style="width:'+pct+'%;background:'+(warn?'var(--red)':'var(--brand)')+';height:100%;border-radius:4px"></div></div></div>';
        });
        cc.innerHTML=html;
      }
    }
    // Update risk alerts
    var ra=document.getElementById('risk-alerts-container');
    if(ra&&(d.alerts||[]).length){
      var html='';(d.alerts||[]).forEach(function(a){
        var sev=(a.severity||'info').toLowerCase();
        var cls=sev==='critical'?'critical':(sev==='warning'?'warning':'info');
        html+='<div class="risk-alert '+cls+'"><div><b>'+esc(a.code||'Alert')+'</b> — '+esc(a.detail||'')+'<br><span style="font-size:11px;color:var(--dim)">'+esc(a.scope||'')+'</span></div></div>';
      });
      ra.innerHTML=html;
    }
  }).catch(function(){});
}
loadRiskDashboard();

window.toggleKillSwitch=function(active){
  if(!confirm(active?'Activate kill switch? All trading will be blocked.':'Release kill switch? Trading will resume.'))return;
  fetch('/app/trading/toggle_kill_switch',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({active:active,reason:active?'Toggled from My Trading':'Released from My Trading'})})
  .then(function(r){return r.json()}).then(function(d){
    if(d.ok){location.reload();}else{alert('Error: '+(d.error||'Unknown'));}
  }).catch(function(e){alert('Network error: '+e.message);});
};

/* ═══════════════════════════════════════════════════════════════
   COMPONENT 4: TradingPerformance
   ═══════════════════════════════════════════════════════════════ */
function loadPerformance(){
  fetch('/app/trading/performance?groupBy=symbol').then(function(r){return r.json()}).then(function(d){
    // Update streaks
    var s=d.streaks||{};
    var curEl=document.getElementById('streak-current');if(curEl){var v=s.currentStreak||0;curEl.textContent=v>0?v+'W':(v<0?Math.abs(v)+'L':'—');curEl.className='pc-val '+(v>0?'pnl-pos':(v<0?'pnl-neg':''));}
    var bestEl=document.getElementById('streak-best');if(bestEl)bestEl.textContent=(s.bestWinStreak||0)+'W';
    var worstEl=document.getElementById('streak-worst');if(worstEl)worstEl.textContent=(s.worstLosingStreak||0)+'L';
    // Draw equity curve
    renderEquityCurve(d.entries||[]);
  }).catch(function(){});
}

function renderEquityCurve(entries){
  var svg=document.getElementById('equity-curve-svg');if(!svg)return;
  if(!entries||!entries.length){svg.innerHTML='<text x="400" y="80" text-anchor="middle" fill="#5e6b82" font-size="12">No trades to plot</text>';return;}
  var W=800,H=160,pad=20;var cum=0;var pts=[{x:0,y:0}];
  var closed=entries.filter(function(e){return e.pnl!==null;}).reverse();
  closed.forEach(function(e,i){cum+=parseFloat(e.pnl||0);pts.push({x:(i+1)/(closed.length-1||1),y:cum});});
  var maxP=Math.max(0,Math.max.apply(null,pts.map(function(p){return p.y;})));
  var minP=Math.min(0,Math.min.apply(null,pts.map(function(p){return p.y;})));
  var range=maxP-minP||1;var innerW=W-pad*2;var innerH=H-pad*2;
  var pathD='';pts.forEach(function(p,i){var x=pad+p.x*innerW;var y=pad+innerH-(p.y-minP)/range*innerH;pathD+=(i===0?'M':'L')+x.toFixed(1)+','+y.toFixed(1);});
  // Zero line
  var zeroY=pad+innerH-(0-minP)/range*innerH;
  var areaD=pathD+'L'+(pad+innerW)+','+zeroY+'L'+pad+','+zeroY+'Z';
  var color=cum>=0?'#22c55e':'#fb5d6b';var areaColor=cum>=0?'#22c55e15':'#fb5d6b15';
  svg.innerHTML='<line x1="'+pad+'" y1="'+zeroY+'" x2="'+(W-pad)+'" y2="'+zeroY+'" stroke="#1e2738" stroke-dasharray="4"/>'
    +'<path d="'+areaD+'" fill="'+areaColor+'"/>'
    +'<path d="'+pathD+'" fill="none" stroke="'+color+'" stroke-width="2"/>'
    +'<text x="'+(W-pad)+'" y="'+(pad+innerH-(cum-minP)/range*innerH-6)+'" text-anchor="end" fill="'+color+'" font-size="11" font-weight="600">'+cum.toFixed(0)+'</text>';
}

/* ═══════════════════════════════════════════════════════════════
   Trade submission / signal helpers
   ═══════════════════════════════════════════════════════════════ */
window.submitTrade=function(ev){
  ev.preventDefault();var form=ev.target;
  var data={symbol:form.symbol.value,side:form.side.value,volume:parseFloat(form.volume.value),broker:form.broker.value,stopLoss:form.stopLoss.value?parseFloat(form.stopLoss.value):null,takeProfit:form.takeProfit.value?[parseFloat(form.takeProfit.value)]:null,confidence:parseFloat(form.confidence.value||0.5),reason:form.reason.value||'Manual trade'};
  var r=document.getElementById('trade-result');r.innerHTML='<div class="notice ok">Submitting…</div>';
  fetch('/app/trading/submit_order',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)}).then(function(r){return r.json()}).then(function(res){
    r.innerHTML=res.ok?'<div class="notice ok"><b>Submitted!</b> '+esc(res.message||'')+'</div>':'<div class="notice err"><b>Error:</b> '+esc(res.error||'')+'</div>';
    if(res.ok)form.reset();
  }).catch(function(e){r.innerHTML='<div class="notice err">Network error: '+esc(e.message)+'</div>';});
  return false;
};
window.closePosition=function(broker,posId){
  if(!confirm('Close this position?'))return;
  var fd=new FormData();fd.append('broker',broker);fd.append('positionId',posId);
  fetch('/app/trading/close_position',{method:'POST',body:fd}).then(function(r){return r.json()}).then(function(res){if(res.ok){alert('Close requested.');location.reload();}else{alert('Error: '+(res.error||''));}}).catch(function(e){alert('Error: '+e.message);});
};
window.useSignal=function(sig){
  document.querySelector('[data-tab=trade]').click();
  var f=document.getElementById('trade-form');if(!f)return;
  if(f.symbol)f.symbol.value=sig.symbol||'';
  if(f.side)f.side.value=(sig.bias||sig.direction||'').match(/bull|long|buy|BUY/i)?'BUY':'SELL';
  if(f.confidence&&sig.confidence)f.confidence.value=sig.confidence;
  if(f.reason)f.reason.value='From AI signal: '+(sig.reasoning||'').substring(0,80);
  if(f.stopLoss&&sig.stopLoss)f.stopLoss.value=sig.stopLoss;
  if(f.takeProfit&&sig.takeProfit){var tp=Array.isArray(sig.takeProfit)?sig.takeProfit[0]:sig.takeProfit;if(tp)f.takeProfit.value=tp;}
  f.symbol.focus();
};
window.tradeFromSignal=function(sig){window.useSignal(sig);};
window.useSignalData=function(sig){window.useSignal(sig);};

function esc(s){var d=document.createElement('div');d.textContent=s||'';return d.innerHTML;}

/* ─── Auto-refresh ──────────────────────────────────────────── */
setInterval(function(){loadSignals();loadChart();},30000);
})();
</script>
