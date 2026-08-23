<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<div class="page-head">
  <div>
    <h2>Broker Center</h2>
    <p>Phase 4/5: connector health and capability. The MT5 connector routes orders only when
      <span class="mono">AEGIS_MT5_TRADING_ENABLED=1</span> AND the deployed bridge reports
      <span class="mono">tradingEnabled=true</span> AND the account is <b>demo</b> (unless live is explicitly allowed).
      Untested integrations are listed as PLANNED — never as working.</p>
  </div>
</div>
<?php if (!empty($notice)): ?><div class="notice ok"><?= e($notice) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="notice err"><?= e($error) ?></div><?php endif; ?>

<div class="notice <?= $routable ? 'ok' : 'warnbox' ?>">
  <?php if ($routable): ?><b>Order routing ACTIVE</b> — a connector is READY with verified order submission. The Execution Supervisor may route approved proposals.
  <?php else: ?><b>Order routing DISABLED</b> — no connector is READY with verified order submission. Proposals can be evaluated and approved, but routing is audited as ROUTING_BLOCKED and no order is created.<?php endif; ?>
</div>

<div class="grid">
  <?php foreach ($connectors as $id => $c): ?>
    <div class="panel">
      <h3><?= e($id) ?> <span class="badge <?= ['READY' => 'b-green', 'DOWN' => 'b-red', 'DISABLED' => 'b-gray', 'NOT_CONFIGURED' => 'b-amber'][$c['state']] ?? 'b-gray' ?>"><?= e($c['state']) ?></span></h3>
      <div class="body" style="padding-top:12px">
        <table class="tbl mono">
          <tr><td class="dim">message</td><td><?= e($c['message']) ?></td></tr>
          <tr><td class="dim">configured</td><td><?= $c['configured'] ? 'yes' : 'no' ?></td></tr>
          <?php foreach (['bridgeVersion', 'bridgeTradingEnabled', 'accountType', 'orderSubmissionEffective'] as $k): ?>
            <?php if (isset($c[$k])): ?><tr><td class="dim"><?= e($k) ?></td><td><?= e(is_bool($c[$k]) ? ($c[$k] ? 'true' : 'false') : (string) $c[$k]) ?></td></tr><?php endif; ?>
          <?php endforeach; ?>
          <?php $caps = $c['capabilities'] ?? []; ?>
          <tr><td class="dim">account read</td><td class="<?= !empty($caps['accountRead']) ? 'up' : 'down' ?>"><?= !empty($caps['accountRead']) ? 'yes' : 'no' ?></td></tr>
          <tr><td class="dim">market data</td><td class="<?= !empty($caps['marketData']) ? 'up' : 'down' ?>"><?= !empty($caps['marketData']) ? 'yes' : 'no' ?></td></tr>
          <tr><td class="dim">order submission</td><td class="<?= !empty($caps['orderSubmission']) ? 'up' : 'down' ?>"><?= !empty($caps['orderSubmission']) ? 'enabled' : 'disabled' ?></td></tr>
          <tr><td class="dim">live trading</td><td class="<?= !empty($caps['liveTrading']) ? 'up' : 'down' ?>"><?= !empty($caps['liveTrading']) ? 'allowed' : 'demo only' ?></td></tr>
        </table>
        <?php if (!empty($caps['reason'])): ?><p class="dim" style="margin-top:8px"><?= e($caps['reason']) ?></p><?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="grid cols-main" style="margin-top:14px">
  <div class="panel">
    <h3>MT5 account (read-only)</h3>
    <div class="body" style="padding-top:12px">
      <?php if (empty($account)): ?>
        <p class="dim">No MT5 account available — deploy the authenticated bridge
          (<span class="mono">python-services/mt5-bridge</span>) and set
          <span class="mono">AEGIS_MT5_BRIDGE_ENABLED=1</span>, <span class="mono">AEGIS_MT5_BRIDGE_URL</span> and
          <span class="mono">AEGIS_MT5_BRIDGE_TOKEN</span>. This page can never place an order.</p>
      <?php else: ?>
        <table class="tbl mono">
          <tr><td class="dim">account</td><td><?= e($account['accountId']) ?></td></tr>
          <tr><td class="dim">currency</td><td><?= e($account['currency']) ?></td></tr>
          <tr><td class="dim">balance / equity</td><td class="num"><?= e(number_format($account['balance'], 2)) ?> / <?= e(number_format($account['equity'], 2)) ?></td></tr>
          <tr><td class="dim">free margin</td><td class="num"><?= e(number_format($account['freeMargin'], 2)) ?></td></tr>
          <tr><td class="dim">as of</td><td><?= e($account['timestamp']) ?></td></tr>
        </table>
      <?php endif; ?>
    </div>
  </div>
  <div class="panel">
    <h3>MT5 quote (read-only)</h3>
    <div class="body" style="padding-top:12px">
      <form method="get" action="/brokers" class="mono" style="display:flex;gap:6px">
        <input name="symbol" value="<?= e($quoteSymbol ?: 'EURUSD') ?>" placeholder="SYMBOL">
        <button class="btn">fetch quote</button>
      </form>
      <?php if (!empty($quote)): ?>
        <table class="tbl mono" style="margin-top:8px">
          <tr><td class="dim">symbol</td><td><?= e($quote['symbol']) ?></td></tr>
          <tr><td class="dim">bid / ask</td><td class="num"><?= e(number_format($quote['bid'], 5)) ?> / <?= e(number_format($quote['ask'], 5)) ?></td></tr>
          <tr><td class="dim">spread</td><td class="num"><?= e(number_format($quote['spread'], 5)) ?></td></tr>
          <tr><td class="dim">timestamp</td><td><?= e($quote['timestamp']) ?></td></tr>
        </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="panel" style="margin-top:14px">
  <h3>Not implemented (PLANNED) — do not treat as working</h3>
  <div class="body" style="padding-top:12px">
    <table class="tbl mono">
      <thead><tr><th>Connector</th><th>Status</th><th>Notes</th></tr></thead>
      <tbody>
        <?php foreach ($planned as $p): ?>
          <tr><td><?= e($p['name']) ?></td><td><span class="badge b-gray"><?= e($p['status']) ?></span></td><td class="dim"><?= e($p['detail']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
