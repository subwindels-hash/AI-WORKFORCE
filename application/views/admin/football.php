<?php defined('BASEPATH') or exit('No direct script access allowed');
/**
 * Football Intelligence administration.
 *
 * The page renders the EFFECTIVE configuration — admin-saved platform_settings
 * override the environment, which overrides the defaults — so the numbers an
 * operator reads are the numbers the engine actually uses.
 *
 * All settings (module switch, auto-predict, A/B/C rules, model settings,
 * league scope) live in ONE form so a single Save persists the whole module
 * configuration atomically; nothing is clobbered by a partial submission.
 *
 * @var array $football
 */
$fb = $football ?? [];
$knobs = $fb['adminKnobs'] ?? [];
$rules = $fb['categoryRules'] ?? [];
$effective = $rules['effective'] ?? [];
$ruleBy = [];
foreach ((array) ($rules['rows'] ?? []) as $row) $ruleBy[(string) ($row['category_key'] ?? '')] = $row;
$perf = $fb['performance'] ?? [];
$provider = $fb['providerStatus'] ?? [];
$credentials = (array) ($fb['credentials'] ?? []);
$competitions = (array) ($fb['competitions'] ?? []);
$scope = (array) ($fb['leagueScope'] ?? []);
$inScope = static function (?string $providerCode, ?string $externalId, ?string $name) use ($scope): bool {
    if ($scope === []) return true;
    foreach ($scope as $entry) {
        $needle = strtolower(trim((string) $entry));
        if ($needle === '') continue;
        if ($providerCode !== null && $externalId !== null && strtolower($providerCode . '|' . $externalId) === $needle) return true;
        if ($externalId !== null && strtolower((string) $externalId) === $needle) return true;
        if ($name !== null && stripos($name, $entry) !== false) return true;
    }
    return false;
};
$lastBacktest = $fb['lastBacktest'] ?? null;
?>
<div class="page-head">
  <div>
    <p class="eyebrow">Administration</p>
    <h2>Football Prediction Module</h2>
    <p>Odds prediction ticket, A/B/C classification and model controls for the football intelligence engine. Values shown are the effective configuration the engine currently runs on.</p>
  </div>
</div>

<?php if (!empty($notice)): ?><div class="notice ok"><?= e($notice) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="notice err"><?= e($error) ?></div><?php endif; ?>

<!-- ══ ONE form for every setting, so a Save persists the whole module ══ -->
<form method="post" action="/admin/football/save" class="admin-form">
  <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">

  <div class="grid cols-2">
    <div class="stack">
      <!-- ── module + auto-predict ────────────────────────────────────── -->
      <section class="panel">
        <h3>Module &amp; automation</h3>
        <div class="body">
          <div class="stat-grid">
            <div class="stat">
              <div class="k">Module enabled</div>
              <div class="v">
                <label class="auth-check" style="justify-content:flex-start">
                  <input type="checkbox" name="football_enabled" <?= !empty($knobs['enabled']) ? 'checked' : '' ?>>
                  <span class="badge <?= !empty($knobs['enabled']) ? 'b-green' : 'b-gray' ?>"><?= !empty($knobs['enabled']) ? 'ON' : 'OFF' ?></span>
                </label>
              </div>
            </div>
            <div class="stat">
              <div class="k">Auto-predict (scheduled)</div>
              <div class="v">
                <label class="auth-check" style="justify-content:flex-start">
                  <input type="checkbox" name="football_auto_predict" <?= !empty($knobs['autoPredict']) ? 'checked' : '' ?>>
                  <span class="badge <?= !empty($knobs['autoPredict']) ? 'b-green' : 'b-gray' ?>"><?= !empty($knobs['autoPredict']) ? 'ON' : 'OFF' ?></span>
                </label>
              </div>
            </div>
          </div>
          <p class="dim" style="font-size:12px;margin-top:10px">Disabling the module stops fixture sync, scheduled predictions and settlements. Disabling auto-predict only stops the scheduled sweep — manual board rebuilds and the ticket still work.</p>
        </div>
      </section>

      <!-- ── A/B/C classification rules ───────────────────────────────── -->
      <section class="panel">
        <h3>Category rules (A / B / C)</h3>
        <div class="body">
          <div style="display:flex;gap:14px;flex-wrap:wrap">
            <label style="flex:1;min-width:150px">
              <span style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);font-weight:700">Edge line for A / C (%)</span>
              <input type="number" step="0.5" min="0" max="50" name="category_edge_pct" value="<?= e((string) ($knobs['categoryEdgePct'] ?? 5.0)) ?>" style="margin-top:6px;width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px;background:var(--bg)">
              <span class="dim" style="font-size:11px">Win-argmax margin (percentage points) required to call Home Advantage (A) or Away Advantage (C). At or above the line the category is that side; below it falls to Balanced (B).</span>
            </label>
            <label style="flex:1;min-width:150px">
              <span style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);font-weight:700">Draw significance line (%)</span>
              <input type="number" step="0.5" min="5" max="90" name="category_draw_pct" value="<?= e((string) ($knobs['categoryDrawPct'] ?? 30.0)) ?>" style="margin-top:6px;width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px;background:var(--bg)">
              <span class="dim" style="font-size:11px">When the draw is a top outcome at or above this share the match is Balanced / Competitive (B) — the "1–1, draw or close" case.</span>
            </label>
          </div>
          <table class="tbl" style="margin-top:12px">
            <thead><tr><th>Key</th><th>Label</th><th>Rule</th><th>Active</th></tr></thead>
            <tbody>
              <?php foreach (['A' => 'violet', 'B' => 'amber', 'C' => 'green'] as $key => $tone): ?>
                <?php $row = $ruleBy[$key] ?? []; $enabled = !isset($row['enabled']) || (bool) $row['enabled']; ?>
                <tr>
                  <td><span class="badge b-<?= $tone ?>"><?= $key ?></span></td>
                  <td><input type="text" name="category_label_<?= strtolower($key) ?>" value="<?= e((string) ($row['label'] ?? ($effective[$key]['label'] ?? $key))) ?>" style="width:100%;padding:6px 8px;border:1px solid var(--line);border-radius:6px;background:var(--bg)"></td>
                  <td class="dim" style="font-size:12px"><?= e((string) ($row['description'] ?? ($effective[$key]['description'] ?? ''))) ?></td>
                  <td><label class="auth-check"><input type="checkbox" name="category_enabled_<?= strtolower($key) ?>" <?= $enabled ? 'checked' : '' ?>></label></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <p class="dim" style="font-size:12px;margin-top:10px">A disabled category never labels a prediction — its matches fall through to Balanced (B). Rules are stored in <span class="mono">football_category_rules</span> and applied from the next classification.</p>
        </div>
      </section>

      <!-- ── model settings ───────────────────────────────────────────── -->
      <section class="panel">
        <h3>Model settings</h3>
        <div class="body">
          <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px">
            <label><span style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);font-weight:700">Max goals per side (4–12)</span>
              <input type="number" min="4" max="12" step="1" name="model_max_goals" value="<?= e((string) ($knobs['modelMaxGoals'] ?? 8)) ?>" style="margin-top:6px;width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px;background:var(--bg)">
              <span class="dim" style="font-size:11px">Poisson goal range the score distribution sums over.</span>
            </label>
            <label><span style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);font-weight:700">DC correlation rho (−0.25…0.25)</span>
              <input type="number" min="-0.25" max="0.25" step="0.01" name="model_dc_rho" value="<?= e((string) ($knobs['modelDcRho'] ?? -0.06)) ?>" style="margin-top:6px;width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px;background:var(--bg)">
              <span class="dim" style="font-size:11px">Dependence-copula parameter for the two Poissons.</span>
            </label>
            <label><span style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);font-weight:700">Market blend (0…0.6)</span>
              <input type="number" min="0" max="0.6" step="0.05" name="model_market_blend" value="<?= e((string) ($knobs['modelMarketBlend'] ?? 0.35)) ?>" style="margin-top:6px;width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px;background:var(--bg)">
              <span class="dim" style="font-size:11px">Weight of stored market odds in the probability mix (0 = model only).</span>
            </label>
            <label><span style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);font-weight:700">H2H max weight (0…0.25)</span>
              <input type="number" min="0" max="0.25" step="0.01" name="model_h2h_max_weight" value="<?= e((string) ($knobs['modelH2hMaxWeight'] ?? 0.12)) ?>" style="margin-top:6px;width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px;background:var(--bg)">
              <span class="dim" style="font-size:11px">Ceiling for the head-to-head contribution to expected goals.</span>
            </label>
            <label><span style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);font-weight:700">Min calibration samples</span>
              <input type="number" min="10" max="10000" step="1" name="model_min_calibration_samples" value="<?= e((string) ($knobs['modelMinCalibrationSamples'] ?? 50)) ?>" style="margin-top:6px;width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px;background:var(--bg)">
              <span class="dim" style="font-size:11px">Settled samples needed before a calibration is eligible.</span>
            </label>
            <label><span style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);font-weight:700">Analysis limit per date</span>
              <input type="number" min="1" max="500" step="1" name="model_analysis_limit" value="<?= e((string) ($knobs['modelAnalysisLimit'] ?? 120)) ?>" style="margin-top:6px;width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px;background:var(--bg)">
              <span class="dim" style="font-size:11px">Fixtures analyzed per date when rebuilding the board.</span>
            </label>
          </div>
          <p class="dim" style="font-size:11px;margin-top:10px">Model hyper-parameters feed the model-version hash — saving them mints a new version, tracked in the model ledger.</p>
        </div>
      </section>
    </div>

    <div class="stack">
      <!-- ── league scope ─────────────────────────────────────────────── -->
      <section class="panel">
        <h3>Supported leagues</h3>
        <div class="body">
          <?php if ($competitions === []): ?>
            <p class="dim" style="font-size:12px">No competition is stored yet. Sync a date (below) and the leagues present in the provider data appear here for selection.</p>
            <input type="hidden" name="football_leagues" value="">
          <?php else: ?>
            <p class="dim" style="font-size:12px">Tick the leagues the football module should analyze. Unticked leagues are still stored and listed on the board — they simply get no prediction until ticked.</p>
            <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px;margin-top:8px">
              <?php foreach ($competitions as $competition): ?>
                <?php
                $id = (string) ($competition['external_id'] ?? '');
                $code = (string) ($competition['provider_code'] ?? '');
                $value = $code !== '' && $id !== '' ? $code . '|' . $id : ($id !== '' ? $id : (string) ($competition['name'] ?? ''));
                $checked = $inScope($code !== '' ? $code : null, $id !== '' ? $id : null, $competition['name'] ?? null);
                ?>
                <label class="auth-check" style="font-size:13px">
                  <input type="checkbox" name="football_leagues[]" value="<?= e($value) ?>" <?= $checked ? 'checked' : '' ?>>
                  <?= e((string) ($competition['name'] ?? '—')) ?><?= !empty($competition['country']) ? ' <span class="dim">(' . e((string) $competition['country']) . ')</span>' : '' ?>
                </label>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <p class="dim" style="font-size:11px;margin-top:10px">An empty selection means "all stored leagues" — nothing is scoped out by default.</p>
        </div>
      </section>
    </div>
  </div>

  <div style="margin-top:14px;display:flex;gap:10px;align-items:center">
    <button class="btn primary">Save module settings</button>
    <span class="dim" style="font-size:12px">Saved values override the environment and take effect from the next request.</span>
  </div>
</form>

<!-- ══ Provider status + sync/recalc: separate sibling panels, no nested
     forms (they post to their own endpoints and never touch the settings). ══ -->
<div class="grid cols-2" style="margin-top:14px">
  <div class="stack">
      <!-- ── provider credentials + status (read-only) ────────────────── -->
      <section class="panel">
        <h3>Data provider</h3>
        <div class="body">
          <p class="dim" style="font-size:12px">Credentials come from environment variables (server side) or the central Admin → API store — never from this form. This panel only shows which source is active and its live state; no secret value is ever displayed.</p>
          <div style="margin-top:10px">
            <div class="dim" style="font-size:11px;letter-spacing:.06em;margin-bottom:6px">CONNECTED FEEDS (LIVE STATE)</div>
            <?php if (empty($provider['providers'])): ?>
              <p class="dim" style="font-size:12px"><?= e((string) ($provider['detail'] ?? 'No feed is connected. Live fixtures and predictions are unavailable until a verified data source is configured. Nothing is fabricated to fill the gap.')) ?></p>
            <?php else: ?>
              <table class="tbl">
                <thead><tr><th>Provider</th><th>State</th><th>Reliability</th><th>Requests today</th><th>Backoff until</th></tr></thead>
                <tbody>
                  <?php foreach ($provider['providers'] as $pid => $p): ?>
                    <tr>
                      <td class="mono"><?= e((string) $pid) ?></td>
                      <td><span class="badge <?= strtoupper((string) ($p['status'] ?? '')) === 'ONLINE' ? 'b-green' : 'b-amber' ?>"><?= e((string) ($p['status'] ?? 'UNKNOWN')) ?></span></td>
                      <td class="mono dim"><?= is_numeric($p['reliability'] ?? null) ? number_format((float) $p['reliability'], 3) : '—' ?></td>
                      <td class="mono dim"><?= $p['requestsToday'] ?? '—' ?><?= !empty($p['limitDaily']) ? ' / ' . (int) $p['limitDaily'] : '' ?></td>
                      <td class="dim" style="font-size:11px"><?= !empty($p['backoffUntil']) ? e((string) $p['backoffUntil']) : '—' ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
          <div style="margin-top:12px">
            <div class="dim" style="font-size:11px;letter-spacing:.06em;margin-bottom:6px">CREDENTIAL SOURCES</div>
            <table class="tbl">
              <tbody>
                <?php foreach ($credentials as $credential): ?>
                  <tr>
                    <td style="width:35%"><?= e((string) ($credential['name'] ?? '')) ?></td>
                    <td class="dim" style="font-size:11px"><?= !empty($credential['env']) ? '<span class="mono">' . e((string) $credential['env']) . '</span>' : '' ?></td>
                    <td>
                      <?php if ($credential['env'] !== null && $credential['env'] !== ''): ?>
                        <span class="badge <?= !empty($credential['envConfigured']) ? 'b-green' : 'b-gray' ?>"><?= !empty($credential['envConfigured']) ? 'configured' : 'not set' ?></span>
                      <?php elseif (!empty($credential['storeRows'])): ?>
                        <?php foreach ($credential['storeRows'] as $storeRow): ?>
                          <span class="badge b-blue"><?= e((string) ($storeRow['driver'] ?? 'provider')) ?> · <?= e((string) ($storeRow['status'] ?? '')) ?></span>
                        <?php endforeach; ?>
                      <?php else: ?>
                        <span class="badge b-gray">none</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <p style="margin-top:8px"><a class="btn small" href="/admin/api">Manage API providers →</a></p>
          </div>
        </div>
      </section>

      <!-- ── sync + recalculate (separate actions) ────────────────────── -->
      <section class="panel">
        <h3>Sync &amp; recalculate</h3>
        <div class="body">
          <!-- These post to their own endpoints, outside the settings form, so
               running a sync never touches the module configuration. -->
          <form method="post" action="/admin/football/sync">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
            <span style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);font-weight:700">Sync fixtures for date</span>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px">
              <input type="date" name="date" value="<?= e(gmdate('Y-m-d')) ?>" style="padding:8px 10px;border:1px solid var(--line);border-radius:8px;background:var(--bg)">
              <button class="btn small primary">Pull from provider</button>
            </div>
            <p class="dim" style="font-size:11px;margin-top:8px">Fetches fixtures, results and team statistics for the date. Refuses when no verified provider is configured.</p>
          </form>
          <form method="post" action="/admin/football/recalculate" style="margin-top:14px">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
            <button class="btn small">Rebuild today's board from stored data</button>
            <span class="dim" style="font-size:11px;margin-left:8px">No provider request — re-runs the engine over stored rows.</span>
          </form>
        </div>
      </section>
  </div>
</div>

<!-- ══ Backtest: standalone read-only action ══ -->
<section class="panel" style="margin-top:14px">
  <h3>Backtest over stored history</h3>
  <div class="body">
    <form method="post" action="/admin/football/backtest" class="admin-form">
      <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
        <label style="flex:1;min-width:140px">
          <span style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);font-weight:700">From</span>
          <input type="date" name="from" value="<?= e(gmdate('Y-m-01')) ?>" style="margin-top:6px;width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px;background:var(--bg)">
        </label>
        <label style="flex:1;min-width:140px">
          <span style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);font-weight:700">To</span>
          <input type="date" name="to" value="<?= e(gmdate('Y-m-d')) ?>" style="margin-top:6px;width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px;background:var(--bg)">
        </label>
        <button class="btn small">Run backtest</button>
      </div>
      <p class="dim" style="font-size:11px;margin-top:8px">Re-runs the prediction path over stored finished matches and grades the results. Read-only — it writes nothing to the prediction ledger.</p>
    </form>
    <?php if (is_array($lastBacktest)): ?>
      <div style="margin-top:12px;border-top:1px solid var(--line);padding-top:12px">
        <div class="dim" style="font-size:11px;letter-spacing:.06em">LAST BACKTEST — <?= e((string) ($lastBacktest['from'] ?? '')) ?>…<?= e((string) ($lastBacktest['to'] ?? '')) ?></div>
        <?php if (($lastBacktest['state'] ?? '') === 'MEASURED'): ?>
          <div class="stat-grid" style="margin-top:8px">
            <div class="stat"><div class="k">Evaluated</div><div class="v"><?= (int) ($lastBacktest['evaluated'] ?? 0) ?></div></div>
            <div class="stat"><div class="k">Result accuracy</div><div class="v"><?= is_numeric($lastBacktest['resultAccuracy'] ?? null) ? number_format((float) $lastBacktest['resultAccuracy'] * 100, 1) . '%' : '—' ?></div></div>
            <div class="stat"><div class="k">Exact-score accuracy</div><div class="v"><?= is_numeric($lastBacktest['exactScoreAccuracy'] ?? null) ? number_format((float) $lastBacktest['exactScoreAccuracy'] * 100, 2) . '%' : '—' ?></div></div>
            <div class="stat"><div class="k">Brier</div><div class="v mono"><?= is_numeric($lastBacktest['brier'] ?? null) ? number_format((float) $lastBacktest['brier'], 4) : '—' ?></div></div>
          </div>
          <?php if (!empty($lastBacktest['byCategory'])): ?>
            <table class="tbl" style="margin-top:10px">
              <thead><tr><th>Category</th><th class="num">Evaluated</th><th class="num">Result acc.</th><th class="num">Exact-score acc.</th></tr></thead>
              <tbody>
                <?php foreach ($lastBacktest['byCategory'] as $key => $row): ?>
                  <tr>
                    <td><span class="badge b-<?= $key === 'A' ? 'violet' : ($key === 'C' ? 'green' : 'amber') ?>"><?= e((string) $key) ?></span></td>
                    <td class="num"><?= (int) ($row['evaluated'] ?? 0) ?></td>
                    <td class="num"><?= is_numeric($row['accuracy'] ?? null) ? number_format((float) $row['accuracy'] * 100, 1) . '%' : '—' ?></td>
                    <td class="num"><?= is_numeric($row['exactScoreAccuracy'] ?? null) ? number_format((float) $row['exactScoreAccuracy'] * 100, 2) . '%' : '—' ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        <?php else: ?>
          <p class="dim" style="font-size:12px;margin-top:8px"><?= e((string) ($lastBacktest['message'] ?? 'No finished fixture in the window.')) ?></p>
        <?php endif; ?>
        <p class="dim" style="font-size:11px;margin-top:10px;border:1px solid var(--line);border-radius:8px;padding:8px 10px"><?= e((string) ($lastBacktest['caveat'] ?? '')) ?></p>
      </div>
    <?php endif; ?>
  </div>
</section>

<div class="stack" style="margin-top:14px">
  <!-- ── model performance ────────────────────────────────────────────── -->
  <section class="panel">
    <h3>Model performance (last 30 days, settled)</h3>
    <div class="body">
      <?php if (($perf['state'] ?? '') !== 'MEASURED'): ?>
        <p class="dim" style="font-size:12px">No settled predictions yet. Performance appears once predicted matches have completed and been graded.</p>
      <?php else: ?>
        <div class="stat-grid">
          <div class="stat"><div class="k">Evaluated</div><div class="v"><?= (int) ($perf['evaluatedPredictions'] ?? 0) ?></div></div>
          <div class="stat"><div class="k">Result accuracy</div><div class="v"><?= is_numeric($perf['resultAccuracy'] ?? null) ? number_format((float) $perf['resultAccuracy'] * 100, 1) . '%' : '—' ?></div></div>
          <div class="stat"><div class="k">Exact-score accuracy</div><div class="v"><?= is_numeric($perf['exactScoreAccuracy'] ?? null) ? number_format((float) $perf['exactScoreAccuracy'] * 100, 2) . '%' : '—' ?></div></div>
          <div class="stat"><div class="k">Avg confidence</div><div class="v"><?= is_numeric($perf['averageConfidence'] ?? null) ? number_format((float) $perf['averageConfidence'], 1) . '%' : '—' ?></div></div>
          <div class="stat"><div class="k">Brier</div><div class="v mono"><?= is_numeric($perf['brier'] ?? null) ? number_format((float) $perf['brier'], 4) : '—' ?></div></div>
          <div class="stat"><div class="k">ECE</div><div class="v mono"><?= is_numeric($perf['ece'] ?? null) ? number_format((float) $perf['ece'], 4) : '—' ?></div></div>
        </div>
        <?php if (!empty($perf['byCategory'])): ?>
          <table class="tbl" style="margin-top:12px">
            <thead><tr><th>Category</th><th class="num">Evaluated</th><th class="num">Result acc.</th><th class="num">Exact-score acc.</th><th class="num">Avg confidence</th></tr></thead>
            <tbody>
              <?php foreach ($perf['byCategory'] as $row): ?>
                <tr>
                  <td><span class="badge b-<?= (string) ($row['category'] ?? '') === 'A' ? 'violet' : ((string) ($row['category'] ?? '') === 'C' ? 'green' : 'amber') ?>"><?= e((string) ($row['category'] ?? '')) ?></span></td>
                  <td class="num"><?= (int) ($row['evaluated'] ?? 0) ?></td>
                  <td class="num"><?= is_numeric($row['accuracy'] ?? null) ? number_format((float) $row['accuracy'] * 100, 1) . '%' : '—' ?></td>
                  <td class="num"><?= is_numeric($row['exactScoreAccuracy'] ?? null) ? number_format((float) $row['exactScoreAccuracy'] * 100, 2) . '%' : '—' ?></td>
                  <td class="num mono"><?= is_numeric($row['averageConfidence'] ?? null) ? number_format((float) $row['averageConfidence'], 1) . '%' : '—' ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </section>

  <!-- ── prediction history ───────────────────────────────────────────── -->
  <section class="panel">
    <h3>Prediction history (settled, newest first)</h3>
    <div class="body">
      <?php
      $historyEnvelope = (array) ($fb['history'] ?? []);
      $historyRows = (array) ($historyEnvelope['rows'] ?? []);
      $resultLabel = static fn(?string $r): string => match (strtoupper((string) $r)) {
          'HOME' => 'Home win', 'AWAY' => 'Away win', 'DRAW' => 'Draw', default => (string) ($r ?? '—'),
      };
      ?>
      <?php if ($historyRows === []): ?>
        <p class="dim" style="font-size:12px">No settled predictions yet. History appears once predicted matches have completed and been graded.</p>
      <?php else: ?>
        <table class="tbl">
          <thead><tr><th>Kickoff</th><th>Match</th><th>Predicted</th><th>Category</th><th>Actual</th><th>Result</th><th>Confidence</th></tr></thead>
          <tbody>
            <?php foreach ($historyRows as $row): ?>
              <?php
              $fixture = (array) ($row['fixture'] ?? []);
              $predicted = (array) ($row['predicted'] ?? []);
              $actual = (array) ($row['actual'] ?? []);
              $cat = (string) ($row['category'] ?? '');
              $scoreH = is_numeric($predicted['score']['home'] ?? null) ? (int) $predicted['score']['home'] : null;
              $scoreA = is_numeric($predicted['score']['away'] ?? null) ? (int) $predicted['score']['away'] : null;
              ?>
              <tr>
                <td class="dim" style="font-size:12px;white-space:nowrap"><?= !empty($fixture['kickoff']) ? gmdate('M j, H:i', (int) strtotime((string) $fixture['kickoff'])) : '—' ?></td>
                <td><?= e((string) ($fixture['homeTeam'] ?? '—')) ?> <span class="dim">vs</span> <?= e((string) ($fixture['awayTeam'] ?? '—')) ?><div class="dim" style="font-size:11px"><?= e((string) ($fixture['competition'] ?? '—')) ?></div></td>
                <td class="mono"><?= e($resultLabel($predicted['result'] ?? null)) ?><?= $scoreH !== null && $scoreA !== null ? ' · ' . $scoreH . '–' . $scoreA : '' ?></td>
                <td><span class="badge b-<?= $cat === 'A' ? 'violet' : ($cat === 'C' ? 'green' : ($cat !== '' ? 'amber' : 'gray')) ?>"><?= $cat !== '' ? e($cat) : '—' ?></span></td>
                <td class="mono"><?= e($resultLabel($actual['result'] ?? null)) ?></td>
                <td>
                  <?php if ($row['correctResult'] === true): ?><span class="badge b-green">correct</span>
                  <?php elseif ($row['correctResult'] === false): ?><span class="badge b-red">missed</span>
                  <?php else: ?><span class="badge b-gray">—</span><?php endif; ?>
                </td>
                <td class="mono dim"><?= is_numeric($predicted['confidence'] ?? null) ? number_format((float) $predicted['confidence'], 1) . '%' : '—' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </section>
</div>
