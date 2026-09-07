<?php defined('BASEPATH') or exit('No direct script access allowed');
/** @var array $policy @var array $protection @var array $calendar @var string $csrfToken */
$p = $policy;
$chip = ai_workforce_protection_chip($protection);
?>
<div class="page-head">
  <div>
    <h2>Automatic Kill Switch</h2>
    <p>Protection is automatic and continuous. There is no manual on or off switch anywhere in the platform — only these thresholds, and only administrators can change them.</p>
  </div>
  <span class="statuspill <?= $chip['tone'] === 'ok' ? '' : 'warn' ?>"><i class="pill-dot"></i><?= e($chip['icon'] . ' ' . $chip['label']) ?></span>
</div>

<?php if (!empty($notice)): ?><div class="notice ok"><?= e($notice) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="notice err"><?= e($error) ?></div><?php endif; ?>

<div class="panel" style="margin-bottom:14px">
  <h3>Current state</h3>
  <div class="body" style="padding-top:12px">
    <p style="margin:0 0 10px"><b><?= e((string) ($protection['reason'] ?? '')) ?></b></p>
    <div class="bc-row"><span>New trades</span><b><?= $chip['blocking'] ? 'BLOCKED' : 'Allowed' ?></b></div>
    <div class="bc-row"><span>Since</span><b><?= e((string) ($protection['since'] ?? '—')) ?></b></div>
    <div class="bc-row"><span>Last evaluated</span><b><?= e((string) ($protection['evaluatedAt'] ?? 'never')) ?></b></div>
    <p class="dim" style="margin:10px 0 0">Scanned every minute by the <span class="mono">protection</span> cron job, and inline before every order when the snapshot is older than <?= (int) ($p['recovery']['maxStatusAgeSeconds'] ?? 300) ?> seconds.</p>
  </div>
</div>

<?php if (empty($calendar['configured'])): ?>
  <div class="notice warnbox">News protection is enabled but no Economic Calendar provider is configured. Add one under <a href="/admin/api">Admin → API</a> (service: <b>Economic Calendar</b>) to activate high-impact event protection. Until then the calendar contributes a WARNING, not a pause — enable <i>Pause when no calendar is configured</i> below for the strictest fail-safe behaviour.</div>
<?php elseif (empty($calendar['ok'])): ?>
  <div class="notice err">Economic calendar unavailable: <?= e((string) ($calendar['error'] ?? 'unknown error')) ?>. A configured but unreadable calendar pauses trading (fail-safe), because high-impact events cannot be ruled out.</div>
<?php else: ?>
  <div class="notice ok">Economic calendar reachable (<?= e((string) ($calendar['source'] ?? 'feed')) ?>) — <?= count($calendar['nextEvents'] ?? []) ?> upcoming high-impact event(s) loaded.</div>
<?php endif; ?>

<form method="post" action="/admin/protection/save">
  <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

  <div class="panel" style="margin-bottom:14px">
    <h3>Global</h3>
    <div class="body" style="padding-top:12px">
      <label class="choice"><input type="checkbox" name="enabled" value="1" <?= !empty($p['enabled']) ? 'checked' : '' ?>> Automatic protection enabled</label>
      <p class="dim" style="margin:6px 0 0">Disabling this removes every automatic safeguard. Not recommended.</p>
    </div>
  </div>

  <div class="panel" style="margin-bottom:14px">
    <h3>1 — High-impact news protection</h3>
    <div class="body" style="padding-top:12px">
      <label class="choice"><input type="checkbox" name="news_enabled" value="1" <?= !empty($p['news']['enabled']) ? 'checked' : '' ?>> Enabled (NFP, CPI, FOMC, rate decisions, red/high-impact events)</label>
      <div class="grid two" style="margin-top:10px">
        <label>Minutes before event
          <input name="news_minutes_before" type="number" min="0" max="240" value="<?= e((string) ($p['news']['minutesBefore'] ?? 5)) ?>">
        </label>
        <label>Minutes after event
          <input name="news_minutes_after" type="number" min="0" max="480" value="<?= e((string) ($p['news']['minutesAfter'] ?? 30)) ?>">
        </label>
        <label>Warning lead (minutes before the window)
          <input name="news_lead" type="number" min="0" max="240" value="<?= e((string) ($p['news']['warningLeadMinutes'] ?? 15)) ?>">
        </label>
        <label>Impacts that trigger (comma separated)
          <input name="news_impacts" value="<?= e(implode(',', (array) ($p['news']['impacts'] ?? ['high']))) ?>">
        </label>
        <label>Feed max age (minutes)
          <input name="news_feed_max_age" type="number" min="5" max="1440" value="<?= e((string) ($p['news']['feedMaxAgeMinutes'] ?? 180)) ?>">
        </label>
        <label>On feed failure
          <select name="news_on_failure">
            <option value="pause" <?= ($p['news']['onFeedFailure'] ?? 'pause') === 'pause' ? 'selected' : '' ?>>Pause trading (fail-safe)</option>
            <option value="warn" <?= ($p['news']['onFeedFailure'] ?? '') === 'warn' ? 'selected' : '' ?>>Warn only</option>
          </select>
        </label>
      </div>
      <label class="choice" style="margin-top:10px"><input type="checkbox" name="news_pause_no_provider" value="1" <?= !empty($p['news']['pauseWhenNoProvider']) ? 'checked' : '' ?>> Pause when no calendar provider is configured at all</label>
    </div>
  </div>

  <div class="panel" style="margin-bottom:14px">
    <h3>2 — Daily loss protection</h3>
    <div class="body" style="padding-top:12px">
      <label class="choice"><input type="checkbox" name="loss_enabled" value="1" <?= !empty($p['dailyLoss']['enabled']) ? 'checked' : '' ?>> Enabled</label>
      <div class="grid two" style="margin-top:10px">
        <label>Daily loss limit (% of equity)
          <input name="loss_pct" type="number" step="0.01" min="0" max="50" value="<?= e((string) round(\AIWorkforce\TradingProtection\ProtectionPolicy::toPercent($p['dailyLoss']['percentLimit'] ?? 0.03), 2)) ?>">
        </label>
        <label>Fixed daily loss limit (USD, blank = off)
          <input name="loss_fixed" type="number" step="0.01" min="0" value="<?= ($p['dailyLoss']['fixedLimitUsd'] ?? null) === null ? '' : e((string) $p['dailyLoss']['fixedLimitUsd']) ?>">
        </label>
        <label>Warn at (fraction of limit)
          <input name="loss_warn" type="number" step="0.05" min="0.1" max="1" value="<?= e((string) ($p['dailyLoss']['warnAtFraction'] ?? 0.8)) ?>">
        </label>
      </div>
    </div>
  </div>

  <div class="panel" style="margin-bottom:14px">
    <h3>3 — Maximum drawdown</h3>
    <div class="body" style="padding-top:12px">
      <label class="choice"><input type="checkbox" name="dd_enabled" value="1" <?= !empty($p['drawdown']['enabled']) ? 'checked' : '' ?>> Enabled</label>
      <div class="grid two" style="margin-top:10px">
        <label>Maximum drawdown (%)
          <input name="dd_pct" type="number" step="0.1" min="0" max="90" value="<?= e((string) round(\AIWorkforce\TradingProtection\ProtectionPolicy::toPercent($p['drawdown']['percentLimit'] ?? 0.10), 2)) ?>">
        </label>
        <label>Warn at (fraction of limit)
          <input name="dd_warn" type="number" step="0.05" min="0.1" max="1" value="<?= e((string) ($p['drawdown']['warnAtFraction'] ?? 0.8)) ?>">
        </label>
      </div>
      <p class="dim" style="margin:8px 0 0">Measured against the highest equity ever recorded (high-water mark).</p>
    </div>
  </div>

  <div class="panel" style="margin-bottom:14px">
    <h3>4 — Technical protection</h3>
    <div class="body" style="padding-top:12px">
      <label class="choice"><input type="checkbox" name="tech_broker" value="1" <?= !empty($p['technical']['brokerDisconnect']) ? 'checked' : '' ?>> Broker / MT4 / MT5 connection loss</label>
      <label class="choice"><input type="checkbox" name="tech_stale" value="1" <?= !empty($p['technical']['staleData']) ? 'checked' : '' ?>> Market data unavailable or stale</label>
      <label class="choice"><input type="checkbox" name="tech_escalate" value="1" <?= !empty($p['technical']['escalateToKill']) ? 'checked' : '' ?>> Escalate technical faults to AUTOMATIC KILL (default: pause)</label>
      <div class="grid two" style="margin-top:10px">
        <label>Data feed timeout (seconds)
          <input name="tech_feed_timeout" type="number" min="5" max="3600" value="<?= e((string) ($p['technical']['dataFeedTimeoutSeconds'] ?? 60)) ?>">
        </label>
        <label>Max consecutive order failures
          <input name="tech_order_failures" type="number" min="1" max="50" value="<?= e((string) ($p['technical']['maxConsecutiveOrderFailures'] ?? 3)) ?>">
        </label>
      </div>
    </div>
  </div>

  <div class="panel" style="margin-bottom:14px">
    <h3>5–6 — Spread and slippage</h3>
    <div class="body" style="padding-top:12px">
      <label class="choice"><input type="checkbox" name="spread_enabled" value="1" <?= !empty($p['spread']['enabled']) ? 'checked' : '' ?>> Spread protection</label>
      <label class="choice"><input type="checkbox" name="spread_require" value="1" <?= !empty($p['spread']['requireReading']) ? 'checked' : '' ?>> Block when the spread cannot be read (strict; simulated feeds have no spread)</label>
      <p class="dim" style="margin:6px 0 0">A point is the smallest price increment on the feed: 0.00001 on 5-digit FX (30 points = 3 pips), 0.001 on JPY crosses, 0.01 on metals, 0.1 on indices and 0.01% of price on crypto and equities.</p>
      <div class="grid two" style="margin-top:10px">
        <label>Maximum spread (points)
          <input name="spread_points" type="number" step="0.1" min="0" value="<?= e((string) ($p['spread']['maxPoints'] ?? 30)) ?>">
        </label>
        <label>Per-symbol overrides (EURUSD=12, XAUUSD=40)
          <input name="spread_per_symbol" value="<?= e(implode(', ', array_map(fn($s, $v) => $s . '=' . $v, array_keys((array) ($p['spread']['perSymbol'] ?? [])), array_values((array) ($p['spread']['perSymbol'] ?? []))))) ?>">
        </label>
      </div>
      <hr style="margin:14px 0;border:none;border-top:1px solid var(--line)">
      <label class="choice"><input type="checkbox" name="slip_enabled" value="1" <?= !empty($p['slippage']['enabled']) ? 'checked' : '' ?>> Slippage protection</label>
      <div class="grid two" style="margin-top:10px">
        <label>Maximum slippage (points)
          <input name="slip_points" type="number" step="0.1" min="0" value="<?= e((string) ($p['slippage']['maxPoints'] ?? 10)) ?>">
        </label>
        <label>Sample window (fills)
          <input name="slip_window" type="number" min="1" max="200" value="<?= e((string) ($p['slippage']['sampleWindow'] ?? 20)) ?>">
        </label>
      </div>
    </div>
  </div>

  <div class="panel" style="margin-bottom:14px">
    <h3>Emergency actions on AUTOMATIC KILL</h3>
    <div class="body" style="padding-top:12px">
      <label class="choice"><input type="checkbox" name="emergency_close" value="1" <?= !empty($p['emergency']['closePositionsOnKill']) ? 'checked' : '' ?>> Close open positions (money-moving — off by default)</label>
      <label class="choice"><input type="checkbox" name="emergency_cancel" value="1" <?= !empty($p['emergency']['cancelPendingOrdersOnKill']) ? 'checked' : '' ?>> Cancel pending orders</label>
      <p class="dim" style="margin:6px 0 0">New trades are always blocked while protection is active, regardless of these options.</p>
    </div>
  </div>

  <div class="panel" style="margin-bottom:14px">
    <h3>7 — Recovery and resume</h3>
    <div class="body" style="padding-top:12px">
      <label class="choice"><input type="checkbox" name="recovery_enabled" value="1" <?= !empty($p['recovery']['enabled']) ? 'checked' : '' ?>> Automatic recovery</label>
      <label class="choice"><input type="checkbox" name="recovery_all_clear" value="1" <?= !empty($p['recovery']['requireAllClear']) ? 'checked' : '' ?>> Resume only when every configured condition is clear</label>
      <div class="grid two" style="margin-top:10px">
        <label>Consecutive clear scans before resuming
          <input name="recovery_scans" type="number" min="1" max="60" value="<?= e((string) ($p['recovery']['consecutiveClearScans'] ?? 2)) ?>">
        </label>
        <label>Status max age before re-evaluating (seconds)
          <input name="recovery_max_age" type="number" min="30" max="3600" value="<?= e((string) ($p['recovery']['maxStatusAgeSeconds'] ?? 300)) ?>">
        </label>
      </div>
    </div>
  </div>

  <button class="btn primary" type="submit">Save policy</button>
</form>

<div class="panel" style="margin-bottom:14px">
  <h3>10 — MT4 / MT5 Expert Advisors</h3>
  <div class="body" style="padding-top:12px">
    <p class="dim" style="margin:0 0 10px">Expert Advisors install from <span class="mono">mt4-mt5/</span> and report to the platform through the MT5 bridge. Each deployment is evaluated against the policy above and receives a decision it must obey. An EA enforces the same rules locally, so protection keeps working even if the platform is unreachable.</p>

    <?php if (empty($eaBridge['configured'])): ?>
      <div class="notice warnbox">No EA bridge configured. Set <span class="mono">AI_WORKFORCE_MT5_BRIDGE_URL</span> and <span class="mono">AI_WORKFORCE_MT5_BRIDGE_TOKEN</span> so the platform can pull heartbeats and publish decisions; without them the EAs still enforce the configured limits locally inside the terminal.</div>
    <?php else: ?>
      <form method="post" action="/admin/protection/ea/sync" style="margin-bottom:12px">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <button class="btn small" type="submit">Sync Expert Advisors now</button>
      </form>
    <?php endif; ?>

    <?php if (empty($ea['deployments'])): ?>
      <p class="dim" style="margin:0">No Expert Advisor has reported yet. Deployments appear here automatically on their first heartbeat (or on the first sync above).</p>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr><th>Expert Advisor</th><th>Terminal</th><th>Account</th><th>State</th><th>Reason</th><th>Last heartbeat</th><th>New trades</th></tr>
        </thead>
        <tbody>
          <?php foreach ($ea['deployments'] as $d): $c = ai_workforce_protection_chip(['state' => $d['state']]); ?>
            <tr>
              <td><b><?= e((string) $d['name']) ?></b><br><span class="dim mono" style="font-size:12px"><?= e((string) $d['id']) ?></span></td>
              <td><?= e((string) $d['terminal']) ?><?= $d['symbol'] !== '' ? ' · ' . e((string) $d['symbol']) : '' ?></td>
              <td class="mono"><?= e((string) ($d['account'] !== '' ? $d['account'] : '—')) ?><?= $d['broker'] !== '' ? '<br><span class="dim">' . e((string) $d['broker']) . '</span>' : '' ?></td>
              <td><span class="statuspill <?= $c['tone'] === 'ok' ? '' : 'warn' ?>"><i class="pill-dot"></i><?= e($c['icon'] . ' ' . str_replace('AUTOMATIC_', '', (string) $d['state'])) ?></span></td>
              <td style="max-width:320px"><?= e((string) $d['reason']) ?></td>
              <td class="mono" style="font-size:12px"><?= e((string) ($d['heartbeatAt'] ?? 'never')) ?><?= $d['heartbeatAgeSeconds'] !== null ? '<br><span class="dim">' . (int) $d['heartbeatAgeSeconds'] . 's ago</span>' : '' ?></td>
              <td><?= $d['allowNewTrades'] ? 'Allowed' : '<b>BLOCKED</b>' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <p class="dim" style="margin:10px 0 0"><?= (int) ($ea['blocked'] ?? 0) ?> of <?= (int) ($ea['total'] ?? 0) ?> deployment(s) blocked. Last evaluated: <?= e((string) ($ea['evaluatedAt'] ?? 'never')) ?>.</p>
    <?php endif; ?>
  </div>
</div>

<?php foreach (($ea['deployments'] ?? []) as $d): ?>
  <details class="panel" style="margin-bottom:14px">
    <summary style="cursor:pointer"><h3 style="display:inline-block">Per-EA limits — <?= e((string) $d['name']) ?></h3></summary>
    <div class="body" style="padding-top:12px">
      <p class="dim" style="margin:0 0 10px">Terminal-side limits only. The risk policy above (news, daily loss, drawdown, spread, slippage) is shared — an EA cannot be given more room than the platform allows.</p>
      <form method="post" action="/admin/protection/ea/limits">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <input type="hidden" name="ea_id" value="<?= e((string) $d['id']) ?>">
        <div class="grid two">
          <label class="choice"><input type="checkbox" name="ea_enabled" value="1" <?= !empty($d['limits']['enabled']) ? 'checked' : '' ?>> Protection enabled for this EA</label>
          <label>Heartbeat timeout (seconds)
            <input name="ea_heartbeat_timeout" type="number" min="10" max="3600" value="<?= e((string) ($d['limits']['heartbeatTimeoutSeconds'] ?? 120)) ?>">
          </label>
          <label>Margin level floor (%)
            <input name="ea_margin_floor" type="number" min="0" max="100000" step="0.1" value="<?= e((string) ($d['limits']['marginLevelFloorPercent'] ?? 150)) ?>">
          </label>
          <label>Max tick age (seconds)
            <input name="ea_tick_age" type="number" min="1" max="3600" value="<?= e((string) ($d['limits']['maxTickAgeSeconds'] ?? 60)) ?>">
          </label>
          <label>Abnormal price move (%)
            <input name="ea_price_move" type="number" min="0" max="1000" step="0.1" value="<?= e((string) ($d['limits']['abnormalPriceMovePercent'] ?? 5)) ?>">
          </label>
          <label class="choice"><input type="checkbox" name="ea_require_decision" value="1" <?= !empty($d['limits']['requirePlatformDecision']) ? 'checked' : '' ?>> Require a fresh platform decision (block when unreachable)</label>
        </div>
        <button class="btn small" type="submit" style="margin-top:10px">Save limits for <?= e((string) $d['name']) ?></button>
      </form>
      <form method="post" action="/admin/protection/ea/remove" style="margin-top:10px" onsubmit="return confirm('Stop protecting this Expert Advisor deployment?');">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <input type="hidden" name="ea_id" value="<?= e((string) $d['id']) ?>">
        <button class="btn small danger" type="submit">Remove deployment</button>
      </form>
    </div>
  </details>
<?php endforeach; ?>

<form method="post" action="/admin/protection/reset-peak" style="margin-top:14px" onsubmit="return confirm('Reset the drawdown high-water mark to current equity?');">
  <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
  <button class="btn small" type="submit">Reset drawdown high-water mark</button>
</form>
