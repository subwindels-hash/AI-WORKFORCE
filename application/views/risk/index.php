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

<?php /* §8 — Automatic Kill Switch status. There is deliberately no manual
         switch here: the state is derived by the protection engine. */ ?>
<?php $prot = ai_workforce_protection_status(); $chip = ai_workforce_protection_chip($prot); $pm = $prot['metrics'] ?? []; ?>
<div class="panel" style="margin-top:14px">
  <h3>Automatic Kill Switch
    <span class="statuspill <?= $chip['tone'] === 'ok' ? '' : 'warn' ?>"><i class="pill-dot"></i><?= e($chip['icon'] . ' ' . $chip['label']) ?></span>
  </h3>
  <div class="body" style="padding-top:12px">
    <p style="margin:0 0 10px;font-weight:600;color:<?= $chip['tone'] === 'ok' ? 'var(--text)' : 'var(--red)' ?>"><?= e((string) ($prot['reason'] ?? '')) ?></p>
    <div class="bc-row"><span>New trades</span><b><?= $chip['blocking'] ? 'BLOCKED' : 'Allowed' ?></b></div>
    <div class="bc-row"><span>Since</span><b><?= e((string) ($prot['since'] ?? '—')) ?></b></div>
    <div class="bc-row"><span>Last evaluated</span><b><?= e((string) ($prot['evaluatedAt'] ?? 'never')) ?></b></div>
    <div class="bc-row"><span>Previous state</span><b><?= e((string) ($prot['previousState'] ?? '—')) ?></b></div>
    <div class="bc-row"><span>Equity / balance</span><b><?= e(number_format((float) ($pm['equity'] ?? 0), 2)) ?> / <?= e(number_format((float) ($pm['balance'] ?? 0), 2)) ?></b></div>
    <div class="bc-row"><span>Daily P&amp;L</span><b><?= e(number_format((float) ($pm['dailyPnl'] ?? 0), 2)) ?></b></div>
    <div class="bc-row"><span>Drawdown</span><b><?= e(number_format((float) ($pm['drawdownPct'] ?? 0), 2)) ?>% (peak <?= e(number_format((float) ($pm['peakEquity'] ?? 0), 2)) ?>)</b></div>
    <div class="bc-row"><span>Open positions</span><b><?= (int) ($pm['openPositions'] ?? 0) ?></b></div>

    <?php if (!empty($prot['triggers'])): ?>
      <h4 style="margin:14px 0 6px">Active conditions</h4>
      <table class="tbl mono">
        <thead><tr><th>State</th><th>Trigger</th><th>Reason</th></tr></thead>
        <tbody>
          <?php foreach ($prot['triggers'] as $t): ?>
            <tr>
              <td><span class="badge <?= ($t['state'] ?? '') === 'AUTOMATIC_KILL' ? 'b-red' : 'b-amber' ?>"><?= e((string) ($t['state'] ?? '')) ?></span></td>
              <td><?= e((string) ($t['code'] ?? '')) ?></td>
              <td><?= e((string) ($t['reason'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <p class="dim" style="margin:12px 0 0">
      Continuously monitored: high-impact news, daily loss, maximum drawdown, broker
      and MT4/MT5 connectivity, market-data availability, spread, slippage and repeated
      order failures. Protection overrides AI agents, strategies, signals and manual
      requests. There is no manual on/off switch — only an administrator can change
      the thresholds.
    </p>
    <?php if (!empty($isAdmin)): ?>
      <a class="btn" href="/admin/protection" style="margin-top:10px">Configure protection</a>
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
