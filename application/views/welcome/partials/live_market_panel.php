<?php
defined('BASEPATH') or exit('No direct script access allowed');
/**
 * LIVE market-data status strip.
 *
 * Renders the connection state that answers "did connecting actually work?" —
 * LIVE vs CONNECTED·NOT ENABLED vs NOT CONNECTED per market-data service. This
 * is the state that used to be invisible: a provider saved with Enable unticked
 * leaves the service configured but dark, and the chart silently falls back to
 * the labelled simulation.
 *
 * Included ABOVE the chart partial (which renders its own panel), never around
 * it, so panels do not nest.
 *
 * @var array $marketState  code => ApiProviders::serviceState()
 */
if (empty($marketState) || !is_array($marketState)) {
    return;
}
?>
<div class="livemarket-state">
  <?php foreach ($marketState as $code => $svc): ?>
    <?php
      if (!empty($svc['live'])) { $cls = 'b-green'; $txt = 'LIVE'; }
      elseif (!empty($svc['configured'])) { $cls = 'b-amber'; $txt = 'CONNECTED · NOT ENABLED'; }
      else { $cls = 'b-gray'; $txt = 'NOT CONNECTED'; }
    ?>
    <span class="livemarket-chip">
      <span class="livemarket-label"><?= e($svc['label'] ?? $code) ?></span>
      <span class="badge <?= $cls ?>"><?= e($txt) ?></span>
      <?php if (!empty($svc['driver'])): ?>
        <span class="dim mono" style="font-size:10px"><?= e($svc['driver']) ?></span>
      <?php endif; ?>
    </span>
  <?php endforeach; ?>
  <span class="livemarket-hint dim">
    Connected but not enabled? Tick <b>Enable</b> in <a href="/admin/api">Admin → API</a>,
    or press <b>Go live</b> on the chart.
  </span>
</div>
