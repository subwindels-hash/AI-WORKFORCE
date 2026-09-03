<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<div class="page-head">
  <div>
    <h2>Brokers</h2>
    <p>Connector health and capability. Untested integrations stay marked planned. MT5 routing needs an enabled bridge, tradingEnabled=true and a demo account unless live is explicitly allowed.</p>
  </div>
</div>
<?php if (!empty($notice)): ?><div class="notice ok"><?= e($notice) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="notice err"><?= e($error) ?></div><?php endif; ?>

<div class="notice <?= $routable ? 'ok' : 'warnbox' ?>">
  <?php if ($routable && !empty($connectors['mt5-bridge']['simulated'])): ?>
    <b>Order routing ACTIVE — SIMULATED BRIDGE.</b> The connector is READY against the in-process mock (demo marker file). The Execution Supervisor can route approved proposals and receive <b>simulated</b> fills. <b>No real broker is involved and no real order exists.</b>
  <?php elseif ($routable): ?><b>Order routing ACTIVE</b> — a connector is READY with verified order submission. The Execution Supervisor may route approved proposals.
  <?php else: ?><b>Order routing DISABLED</b> — no connector is READY with verified order submission. Proposals can be evaluated and approved, but routing is audited as ROUTING_BLOCKED and no order is created.<?php endif; ?>
</div>

<div class="panel" style="margin-top:14px">
  <h3>Connect a trading platform</h3>
  <div class="body" style="padding-top:12px">
    <p class="dim" style="margin:0 0 12px">Connect your own broker account so AI Trading Intelligence can read live quotes and (after you opt in + enable demo/live gates) route approved orders through the supervised pipeline. Credentials are encrypted at rest and scoped to your account; other users never see them.</p>
    <?php if (!empty($myConnections)): ?>
      <table class="tbl mono" style="margin-bottom:14px">
        <thead><tr><th>Broker</th><th>Label</th><th>URL</th><th>Status</th><th>Trading</th><th>Live</th><th>Last test</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($myConnections as $mc):
            $bid = $mc['broker']; $meta = $supportedBrokers[$bid] ?? ['label'=>$bid]; ?>
          <tr>
            <td><b><?= e($meta['label']) ?></b></td>
            <td><?= e($mc['label'] ?? '') ?></td>
            <td class="dim"><?= e($mc['base_url']) ?></td>
            <td>
              <?php if (empty($mc['enabled'])): ?><span class="badge b-gray">disabled</span>
              <?php elseif ($mc['last_test_ok'] === true): ?><span class="badge b-green">connected</span>
              <?php elseif ($mc['last_test_ok'] === false): ?><span class="badge b-red">failed</span>
              <?php else: ?><span class="badge b-amber">untested</span><?php endif; ?>
            </td>
            <td><?= !empty($mc['trading_enabled']) ? '<span class="badge b-amber">writes on</span>' : '<span class="dim">read-only</span>' ?></td>
            <td><?= !empty($mc['live_allowed']) ? '<span class="badge b-red">live</span>' : '<span class="dim">demo</span>' ?></td>
            <td class="dim">
              <?php if ($mc['last_test_at']): ?>
                <?= e($mc['last_test_at']) ?><br><span class="dim"><?= e($mc['last_test_message'] ?? '') ?></span>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td style="white-space:nowrap">
              <a class="btn" href="/brokers/connect/<?= e($bid) ?>">edit</a>
              <form method="post" action="/brokers/test/<?= e($bid) ?>" style="display:inline"><button class="btn">test</button></form>
              <form method="post" action="/brokers/disconnect/<?= e($bid) ?>" style="display:inline" data-confirm="Disconnect <?= e($meta['label']) ?>?"><button class="btn danger">remove</button></form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <div class="grid" style="grid-template-columns:repeat(auto-fill, minmax(220px,1fr));gap:10px">
      <?php foreach ($supportedBrokers as $bid => $meta): ?>
        <a class="card-link" href="/brokers/connect/<?= e($bid) ?>" style="border:1px solid var(--border);border-radius:8px;padding:12px;display:block;text-decoration:none;color:inherit">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px"><b><?= e($meta['label']) ?></b>
            <?php if (in_array($bid, array_column($myConnections ?: [], 'broker'))): ?><span class="badge b-green">added</span><?php endif; ?>
          </div>
          <div class="dim" style="font-size:12px"><?= e($meta['market']) ?></div>
        </a>
      <?php endforeach; ?>
    </div>
    <p class="dim" style="margin-top:12px">Connection is read-only by default. Enabling <i>Order submission</i> allows the Execution Supervisor to send orders, but every order still passes the kill switch, risk veto and the human approval pipeline. <i>Live trading</i> must be explicitly allowed and requires a verified non-demo gate.</p>
  </div>
</div>

<div class="panel" style="margin-top:14px">
  <h3>Simulated MT5 bridge <span class="badge b-amber">DEMO · SIMULATION</span></h3>
  <div class="body" style="padding-top:12px">
    <?php if (!empty($sim['enabled'])): ?>
      <div class="notice warnbox" style="margin-bottom:10px"><b>SIMULATION RUNNING</b> on 127.0.0.1:<?= e((string) $sim['port']) ?> — an in-process mock that speaks the documented bridge contract (health, quotes, candles, positions, orders, place/modify/cancel/close) with in-memory state. The PHP connector applies its normal gates (demo account, trading flags). <b>This is not a MetaTrader terminal and cannot reach one.</b></div>
      <form method="post" action="/brokers/sim-toggle"><input type="hidden" name="enable" value="0"><button class="btn danger">Stop simulated bridge</button></form>
    <?php else: ?>
      <p class="dim">This sandbox has no MetaTrader terminal and no bridge deployed, so routing is honestly blocked. To <i>demo</i> the full propose → approve → route → fill chain, start the in-process <b>simulated</b> bridge below — every fill is clearly labeled SIMULATION.</p>
      <form method="post" action="/brokers/sim-toggle"><input type="hidden" name="enable" value="1"><button class="btn primary">Start simulated bridge (demo)</button></form>
    <?php endif; ?>
  </div>
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
          <span class="mono">AI_WORKFORCE_MT5_BRIDGE_ENABLED=1</span>, <span class="mono">AI_WORKFORCE_MT5_BRIDGE_URL</span> and
          <span class="mono">AI_WORKFORCE_MT5_BRIDGE_TOKEN</span>. This page can never place an order.</p>
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
  <h3>Not production-verified (PLANNED) — do not treat as working</h3>
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
