<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<div class="page-head">
  <div>
    <h2>Connect <?= e($broker['label']) ?></h2>
    <p><?= e($broker['market']) ?> — enter your adapter base URL and credentials. Start with a demo/paper account; live routing requires an explicit gate.</p>
  </div>
  <div><a class="btn" href="/brokers">← back</a></div>
</div>
<?php if (!empty($notice)): ?><div class="notice ok"><?= e($notice) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="notice err"><?= e($error) ?></div><?php endif; ?>

<div class="panel">
  <div class="body" style="padding-top:12px;max-width:720px">
    <form method="post" action="/brokers/save/<?= e($brokerId) ?>" class="form-grid">
      <div class="field">
        <label>Label</label>
        <input name="label" value="<?= e($connection['label'] ?? $broker['label']) ?>" placeholder="e.g. My FX demo">
        <div class="help">Optional display name for this connection.</div>
      </div>

      <div class="field">
        <label>Base URL</label>
        <input name="base_url" value="<?= e($connection['base_url'] ?? $broker['defaultUrl']) ?>" required>
        <div class="help">
          <?php if ($brokerId === 'mt5-bridge' || $brokerId === 'mt4-bridge'): ?>
            URL of the locally deployed bridge (see <span class="mono">python-services/mt5-bridge</span>). Default for MT5 is <span class="mono">http://localhost:8765</span>, MT4 <span class="mono">http://localhost:8764</span>.
          <?php elseif ($brokerId === 'ib'): ?>
            Client Portal Gateway URL. Must be running and SSO-authenticated. Self-signed certs are allowed only on localhost.
          <?php else: ?>
            HTTPS REST root. Use the demo/sandbox host first (e.g. Alpaca paper: <span class="mono">https://paper-api.alpaca.markets</span>, OANDA: <span class="mono">https://api-fxpractice.oanda.com</span>).
          <?php endif; ?>
        </div>
      </div>

      <?php if (!empty($broker['dataUrl'])): ?>
      <div class="field">
        <label>Data API URL (optional)</label>
        <input name="extra_url" value="<?= e($connection['extra_url'] ?? $broker['dataUrl']) ?>">
        <div class="help">Some brokers (Alpaca) split trading and market data. Defaults to <?= e($broker['dataUrl']) ?>.</div>
      </div>
      <?php endif; ?>

      <?php if (!empty($broker['needsToken'])): ?>
      <div class="field">
        <label>API token / bridge password <?= !empty($connection['has_token']) ? '<span class="dim">(leave blank to keep the existing value)</span>' : '' ?></label>
        <input type="password" name="token" placeholder="<?= !empty($connection['has_token']) ? '(unchanged)' : 'paste token here' ?>" autocomplete="off">
        <div class="help">Stored encrypted server-side; never shown back in the UI after save.</div>
      </div>
      <?php endif; ?>

      <div class="field">
        <label>Account hint (optional)</label>
        <input name="account_hint" value="<?= e($connection['account_hint'] ?? '') ?>" placeholder="e.g. 12345678 / Demo">
        <div class="help">A private reminder for you — not used by the connector.</div>
      </div>

      <div class="field checkbox">
        <label><input type="checkbox" name="enabled" value="1" <?= !empty($connection['enabled']) ? 'checked' : '' ?>>
          Enable connection (read-only quotes/candles/account/positions)
        </label>
      </div>

      <div class="field checkbox">
        <label><input type="checkbox" name="trading_enabled" value="1" <?= !empty($connection['trading_enabled']) ? 'checked' : '' ?>>
          Allow order submission for this connection
        </label>
        <div class="help">Orders still require the platform kill switch to be OFF, risk-engine veto pass and either human approval or an approved automation envelope. Starts read-only.</div>
      </div>

      <div class="field checkbox">
        <label><input type="checkbox" name="live_allowed" value="1" <?= !empty($connection['live_allowed']) ? 'checked' : '' ?>>
          Allow live (non-demo) accounts
        </label>
        <div class="help">DANGER: only tick this after confirming the broker reports a funded live account you are willing to trade. By default demo accounts are required.</div>
      </div>

      <div style="display:flex;gap:8px;margin-top:6px">
        <button class="btn primary">Save &amp; test connection</button>
        <a class="btn" href="/brokers">cancel</a>
        <?php if (!empty($connection)): ?>
          <form method="post" action="/brokers/disconnect/<?= e($brokerId) ?>" data-confirm="Remove this connection?">
            <button class="btn danger" type="submit">Remove</button>
          </form>
        <?php endif; ?>
      </div>
    </form>

    <div class="dim" style="margin-top:16px;font-size:12px">
      <b>Safety.</b>
      Saving credentials does not place any order. The Execution Supervisor will never route to a connector that is DISABLED/DOWN, and it always tags fills with the connector id so your journal makes it unambiguous which broker filled a trade.
      Kill switch and ANALYSIS_ONLY default still apply account-wide.
    </div>
  </div>
</div>
