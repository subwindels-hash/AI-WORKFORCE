<?php defined('BASEPATH') or exit('No direct script access allowed');
/** @var array $limits @var ?array $lastReport @var array $events */ ?>
<div class="page-head">
  <div>
    <h2>Risk Center</h2>
    <p>Independent limits with veto power over every trade, plus portfolio monitoring. Correlation alerts use static disclosed groups — a heuristic, not a statistical model.</p>
  </div>
</div>
<?php if (!empty($notice)): ?><div class="notice ok"><?= e($notice) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="notice err"><?= e($error) ?></div><?php endif; ?>

<div class="grid cols-main">
  <div class="panel">
    <h3>Risk limits</h3>
    <div class="body" style="padding-top:12px">
      <form method="post" action="/risk/limits" class="mono" style="display:grid;grid-template-columns:repeat(2,1fr);gap:6px">
        <?php
        $fields = [
            'riskPerTradePct' => ['Risk per trade (%)', 100], 'maxRiskPerTradePct' => ['Hard risk cap/trade (%)', 100],
            'minRiskReward' => ['Min risk/reward', 1], 'maxPositionNotionalUsd' => ['Max position notional ($)', 1],
            'maxLeverage' => ['Max leverage (x)', 1], 'maxOpenPositions' => ['Max open positions', 1],
            'maxDailyLossPct' => ['Max daily loss (%)', 100], 'maxWeeklyLossPct' => ['Max weekly loss (%)', 100],
            'maxDrawdownPct' => ['Max drawdown (%)', 100], 'maxSymbolExposurePct' => ['Max symbol exposure (%)', 100],
            'maxPortfolioExposurePct' => ['Max portfolio exposure (%)', 100],
        ];
        foreach ($fields as $key => [$label, $scale]): ?>
          <label class="dim"><?= e($label) ?></label>
          <input name="<?= e($key) ?>" value="<?= e((string) ($limits[$key] * $scale)) ?>">
        <?php endforeach; ?>
        <button class="btn primary" type="submit" style="grid-column:span 2">Update risk limits</button>
      </form>
      <p class="dim" style="margin-top:8px">Exposure is capital-at-risk (stop-distance basis), not notional. Synthetic and stale data are blocked for live decisions.</p>
    </div>
  </div>

  <div class="panel">
    <h3>Portfolio Risk Monitor</h3>
    <div class="body" style="padding-top:12px">
      <form method="post" action="/risk/scan"><button class="btn primary">Run portfolio risk scan</button></form>
      <p class="dim" style="margin:8px 0 0">Continuous checks: HIGH_EXPOSURE · EXCESSIVE_LEVERAGE · CORRELATED_POSITIONS · MAX_DRAWDOWN_WARNING · DAILY_LOSS_WARNING · BROKER_DISCONNECTED. Only alert <i>transitions</i> are audited.</p>
      <?php if (empty($lastReport)): ?>
        <p class="dim" style="margin-top:10px">No scan yet.</p>
      <?php else: ?>
        <p class="dim" style="margin-top:10px">Last scan: <?= e($lastReport['scannedAt']) ?></p>
        <?php if (empty($lastReport['alerts'])): ?>
          <div class="notice ok" style="margin-top:8px">No active risk alerts.</div>
        <?php else: ?>
          <table class="tbl mono" style="margin-top:8px">
            <thead><tr><th>Severity</th><th>Code</th><th>Scope</th><th>Detail</th></tr></thead>
            <tbody>
              <?php foreach ($lastReport['alerts'] as $a): ?>
                <tr>
                  <td><span class="badge <?= $a['severity'] === 'critical' ? 'b-red' : 'b-amber' ?>"><?= e($a['severity']) ?></span></td>
                  <td><?= e($a['code']) ?></td>
                  <td class="dim"><?= e($a['scope']) ?></td>
                  <td><?= e($a['detail']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php /* Kill switch control — scoped to broker + trading-intelligence
         surfaces. It lives here and on My Trading / the analysis console. */ ?>
<div class="panel" style="margin-top:14px">
  <h3>Kill switch <span class="statuspill <?= !empty($status['killSwitch']['active']) ? 'warn' : '' ?>"><i class="pill-dot"></i><?= !empty($status['killSwitch']['active']) ? 'ACTIVE' : 'RELEASED' ?></span></h3>
  <div class="body" style="padding-top:12px">
    <p class="dim" style="margin:0 0 10px">
      Scoped to order-bound surfaces: the execution supervisor, broker orders
      (MT5, MT4, crypto exchanges, OANDA, Alpaca, IBKR), paper orders and the
      automation envelopes. Market data keeps streaming while it is engaged, so
      charts, analysis and provider health stay observable. Sports, lottery,
      language learning and lead discovery are never gated by it.
    </p>
    <?php if (!empty($status['killSwitch']['active'])): ?>
      <div class="notice err" style="margin-bottom:10px">
        <b>Engaged</b><?= !empty($status['killSwitch']['activatedAt']) ? ' at ' . e((string) $status['killSwitch']['activatedAt']) : '' ?>
        <?= !empty($status['killSwitch']['reason']) ? ' — ' . e((string) $status['killSwitch']['reason']) : '' ?>.
        Every order-bound surface stays blocked until it is released.
      </div>
    <?php endif; ?>
    <?php if (ai_workforce_kill_switch_can_control()): ?>
      <?php if (!empty($status['killSwitch']['active'])): ?>
        <form method="post" action="/kill-switch">
          <input type="hidden" name="active" value="0">
          <input type="hidden" name="return" value="risk">
          <button class="btn small danger" type="submit">Release kill switch</button>
        </form>
      <?php else: ?>
        <form method="post" action="/kill-switch" onsubmit="return confirm('Engage the kill switch? All broker and paper orders will be blocked.');">
          <input type="hidden" name="active" value="1">
          <input type="hidden" name="return" value="risk">
          <button class="btn small danger" type="submit">Engage kill switch</button>
        </form>
      <?php endif; ?>
    <?php else: ?>
      <p class="dim" style="margin:0">Engaging or releasing it requires the <span class="mono">trading.control</span> permission.</p>
    <?php endif; ?>
  </div>
</div>

<div class="panel" style="margin-top:14px">
  <h3>Audit trail (latest)</h3>
  <div class="body scroll" style="padding-top:12px">
    <table class="tbl mono">
      <thead><tr><th>At</th><th>Type</th><th>Summary</th><th>Actor</th></tr></thead>
      <tbody>
        <?php foreach ($events as $ev): ?>
          <tr>
            <td class="dim"><?= e(substr((string) $ev['at'], 5, 14)) ?></td>
            <td><span class="badge b-gray"><?= e($ev['type']) ?></span></td>
            <td><?= e($ev['summary']) ?></td>
            <td class="dim"><?= e($ev['actor']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
