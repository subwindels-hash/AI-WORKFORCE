<?php defined('BASEPATH') or exit('No direct script access allowed');
/**
 * Models &amp; calibration (§8/§9) — rendered once, here.
 *
 * Nothing on this page can promote a model by itself: an approval is refused
 * unless the lifecycle guards in ModelRegistry are satisfied, and a calibration
 * exists only if enough settled predictions were stored to fit one.
 *
 * @var array $models
 * @var array $performance
 * @var array $caps
 */
$models = $models ?? [];
$perf = $performance ?? [];
$active = $models['activeModel'] ?? null;
$calibration = $models['calibration'] ?? [];
$versions = $models['versions'] ?? [];
$calibrationVersions = $models['calibrationVersions'] ?? [];
$caps = $caps ?? ['sync' => false, 'calibrate' => false, 'approve' => false, 'settle' => false];
$windelsModelId = '1520863';

$dash = static fn(mixed $v, int $dp = 4): string => is_numeric($v) ? number_format((float) $v, $dp) : '—';
$pct = static fn(mixed $v): string => is_numeric($v) ? number_format((float) $v * 100, 1) . '%' : '—';
$when = static fn(mixed $iso): string => is_string($iso) && $iso !== '' ? gmdate('Y-m-d H:i', (int) strtotime($iso)) . ' UTC' : '—';
$stateClass = static fn(string $state): string => match (strtoupper($state)) {
    'ACTIVE', 'APPROVED', 'CALIBRATED', 'MEASURED' => 'b-green',
    'VALIDATED', 'TRAINED' => 'b-violet',
    'RETIRED' => 'b-gray',
    default => 'b-amber',
};
?>
<div class="football-console">
  <section class="football-hero" aria-labelledby="models-heading">
    <div class="football-hero__intro">
      <p class="football-eyebrow">Model governance</p>
      <h2 id="models-heading">Football models &amp; calibration</h2>
      <p class="football-hero__copy">Lifecycle state, measured performance and calibration history — every figure read from stored rows. Sections 1 to 3 follow the model's own order: which version is live, how its confidence was calibrated, and what it actually scored. The rail on the right holds the full version register.</p>
    </div>
    <div class="football-hero__actions"><a class="btn small" href="/football">← Back to today's predictions</a></div>
  </section>

  <?php if (!empty($notice)): ?><div class="notice ok"><?= e($notice) ?></div><?php endif; ?>
  <?php if (!empty($error)): ?><div class="notice err"><?= e($error) ?></div><?php endif; ?>

  <div class="football-layout">
    <div class="football-main stack">
      <section class="panel football-section" aria-labelledby="active-model-heading">
        <div class="football-section__heading">
          <div class="football-section__title">
            <span class="football-step" aria-hidden="true">1</span>
            <div>
              <p class="football-eyebrow">Live version</p>
              <h3 id="active-model-heading">Model used by the prediction engine</h3>
            </div>
          </div>
        </div>
        <div class="body">
          <p class="football-section-intro">The version currently answering predictions, with the lifecycle timestamps and measured figures stored against it. A model is only ACTIVE once an operator approved it.</p>
          <p>
          <span class="badge <?= $stateClass((string) ($models['state'] ?? 'NONE')) ?>"><?= e((string) ($models['label'] ?? 'MODEL_NOT_LOADED')) ?></span>
          <?php if (($models['state'] ?? '') !== 'ACTIVE'): ?>
            <span class="football-inline-note dim">— predictions continue to run and are labelled uncalibrated/experimental; an empty settlement history never blocks them.</span>
          <?php endif; ?>
        </p>
        <?php if (!empty($models['reason'])): ?><p class="football-help"><?= e((string) $models['reason']) ?></p><?php endif; ?>
        <?php if ($active === null): ?>
          <p class="dim">No model version is registered yet. The engine registers the deployed scoring configuration as <b>DRAFT</b> the first time it analyzes a fixture — never as an approved model.</p>
        <?php else: ?>
          <div class="table-scroll">
            <table class="tbl">
              <tbody>
                <?php foreach ([
                  ['Windels Model id', $windelsModelId], ['Model name', $active['name'] ?? null], ['Model version', $active['version'] ?? null],
                  ['Algorithm', $active['algorithm'] ?? null], ['Feature version', $active['featureVersion'] ?? null],
                  ['Training dataset version', $active['trainingDatasetVersion'] ?? null],
                  ['Status', $active['status'] ?? null],
                  ['Created', $active['createdAt'] ?? null], ['Trained', $active['trainedAt'] ?? null],
                  ['Validated', $active['validatedAt'] ?? null], ['Calibrated', $active['calibratedAt'] ?? null],
                  ['Approved', $active['approvedAt'] ?? null], ['Approved by', $active['approvedBy'] ?? null],
                  ['Activated', $active['activatedAt'] ?? null], ['Last evaluated', $active['lastEvaluatedAt'] ?? null],
                ] as [$label, $value]): ?>
                  <tr><td class="dim football-cell-label"><?= e($label) ?></td><td class="mono"><?= $value === null ? '—' : e(is_string($value) && (str_contains((string) $value, 'T') && strlen((string) $value) > 15) ? $when($value) : (string) $value) ?></td></tr>
                <?php endforeach; ?>
                <tr><td class="dim">Validation sample size</td><td class="mono"><?= $active['validationSampleSize'] === null ? '—' : (int) $active['validationSampleSize'] ?> settled prediction(s)</td></tr>
                <tr><td class="dim">Accuracy</td><td class="mono"><?= is_numeric($active['accuracy'] ?? null) ? $pct($active['accuracy']) : '—' ?></td></tr>
                <tr><td class="dim">Log loss</td><td class="mono"><?= $dash($active['logLoss'] ?? null) ?></td></tr>
                <tr><td class="dim">Brier score</td><td class="mono"><?= $dash($active['brierScore'] ?? null) ?></td></tr>
                <tr><td class="dim">ECE</td><td class="mono"><?= $dash($active['ece'] ?? null) ?></td></tr>
                <tr><td class="dim">Calibration version</td><td class="mono"><?= e((string) ($active['calibrationVersion'] ?? '—')) ?> <span class="badge <?= $stateClass((string) ($active['calibrationStatus'] ?? 'CALIBRATION_PENDING')) ?>"><?= e((string) ($active['calibrationStatus'] ?? 'CALIBRATION_PENDING')) ?></span></td></tr>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
        <?php if (!empty($caps['calibrate'])): ?>
          <form method="post" action="/football/calibrate" class="football-inline-form">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
            <button class="btn small primary">Fit calibration from stored settlements</button>
          </form>
        <?php else: ?>
          <button class="btn small" disabled title="Requires the sports.manage permission">Fit calibration (needs sports.manage)</button>
        <?php endif; ?>
        </div>
      </section>

      <section class="panel football-section" aria-labelledby="calibration-heading">
        <div class="football-section__heading">
          <div class="football-section__title">
            <span class="football-step" aria-hidden="true">2</span>
            <div>
              <p class="football-eyebrow">Confidence calibration</p>
              <h3 id="calibration-heading">Calibration versions</h3>
            </div>
          </div>
          <span class="football-section__meta"><?= count($calibrationVersions) ?> for this model · <?= (int) ($models['approvedCalibrationCount'] ?? 0) ?> usable</span>
        </div>
        <div class="body scroll">
        <p class="football-section-intro">Calibration adjusts displayed confidence to match observed outcomes. Until a fit exists, confidence is published raw and labelled CALIBRATION_PENDING — it is never quietly adjusted.</p>
        <?php if ($calibrationVersions === []): ?>
          <p class="dim">No calibration has been fitted yet. A temperature is only estimated once at least the configured minimum of settled predictions with stored probabilities exist — until then displayed confidence is labelled <b>CALIBRATION_PENDING</b> (raw), never silently adjusted.</p>
        <?php else: ?>
          <table class="tbl">
            <thead><tr><th>Version</th><th>Method</th><th>Status</th><th class="num">Samples</th><th class="num">T</th><th class="num">ECE</th><th class="num">Brier</th><th>Window</th><th>Approved</th></tr></thead>
            <tbody>
              <?php foreach ($calibrationVersions as $row): ?>
                <tr>
                  <td class="mono"><?= e((string) ($row['calibrationVersion'] ?? '')) ?></td>
                  <td class="dim"><?= e((string) ($row['method'] ?? '')) ?></td>
                  <td><span class="badge <?= $stateClass((string) ($row['status'] ?? '')) ?>"><?= e((string) ($row['status'] ?? '')) ?></span></td>
                  <td class="num"><?= (int) ($row['samples'] ?? 0) ?></td>
                  <td class="num mono"><?= $dash($row['temperature'] ?? null, 3) ?></td>
                  <td class="num mono"><?= $dash($row['ece'] ?? null) ?></td>
                  <td class="num mono"><?= $dash($row['brier'] ?? null) ?></td>
                  <td class="dim mono football-cell-note"><?= e($when($row['windowStart'] ?? null)) ?> → <?= e($when($row['windowEnd'] ?? null)) ?></td>
                  <td class="dim football-cell-small"><?= e($when($row['approvedAt'] ?? null)) ?><?= !empty($row['approvedBy']) ? ' · ' . e((string) $row['approvedBy']) : '' ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php $reason = $calibrationVersions[0]['reason'] ?? null; if (is_string($reason) && $reason !== ''): ?>
            <p class="football-help"><b>Fitting note:</b> <?= e($reason) ?></p>
          <?php endif; ?>
        <?php endif; ?>
        </div>
      </section>

      <section class="panel football-section" aria-labelledby="model-performance-heading">
        <div class="football-section__heading">
          <div class="football-section__title">
            <span class="football-step" aria-hidden="true">3</span>
            <div>
              <p class="football-eyebrow">Measured results</p>
              <h3 id="model-performance-heading">30-day performance by model version</h3>
            </div>
          </div>
        </div>
        <div class="body">
        <p class="football-section-intro">What each version actually scored over the last 30 days of settled predictions. These are the stored aggregates the board reports; no figure is recomputed here.</p>
        <?php if (($perf['state'] ?? '') !== 'MEASURED'): ?>
          <p class="dim">No settled predictions yet. Historical performance metrics will appear after predicted matches have completed.</p>
        <?php else: ?>
          <div class="table-scroll">
            <table class="tbl">
              <thead><tr><th>Version</th><th>Status</th><th class="num">Evaluated</th><th class="num">Result acc.</th><th class="num">Exact-score acc.</th><th class="num">Avg conf.</th><th class="num">Brier</th><th class="num">Log loss</th></tr></thead>
              <tbody>
                <?php foreach (($perf['byModel'] ?? []) as $row): ?>
                  <tr>
                    <td class="mono"><?= e((string) ($row['modelVersion'] ?? '')) ?></td>
                    <td><span class="badge <?= $stateClass((string) ($row['status'] ?? '')) ?>"><?= e((string) ($row['status'] ?? '')) ?></span></td>
                    <td class="num"><?= (int) ($row['evaluated'] ?? 0) ?></td>
                    <td class="num"><?= $pct($row['resultAccuracy'] ?? null) ?></td>
                    <td class="num"><?= $pct($row['exactScoreAccuracy'] ?? null) ?></td>
                    <td class="num"><?= $dash($row['averageConfidence'] ?? null, 1) ?>%</td>
                    <td class="num mono"><?= $dash($row['brier'] ?? null) ?></td>
                    <td class="num mono"><?= $dash($row['logLoss'] ?? null) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <p class="football-help">These are the numbers an approval is judged on; the board's own 30-day panel shows the same stored aggregates, never a second calculation.</p>
        <?php endif; ?>
        </div>
      </section>
    </div>

    <aside class="football-side stack" aria-label="Model version register">
      <section class="panel football-section" aria-labelledby="all-versions-heading">
        <div class="football-section__heading">
          <div class="football-section__title">
            <div>
              <p class="football-eyebrow">Register</p>
              <h3 id="all-versions-heading">All model versions</h3>
            </div>
          </div>
        </div>
        <div class="body scroll">
        <p class="football-section-intro">Every registered version and its lifecycle state.</p>
        <?php if ($versions === []): ?>
          <p class="dim">No model versions recorded.</p>
        <?php else: ?>
          <table class="tbl">
            <thead><tr><th>Model</th><th>Version</th><th>Status</th><th class="num">Samples</th><th class="num">Acc.</th><th class="num">ECE</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($versions as $row): ?>
                <tr>
                  <td class="dim football-cell-small"><?= e((string) ($row['name'] ?? '')) ?></td>
                  <td class="mono"><?= e((string) ($row['version'] ?? '')) ?></td>
                  <td><span class="badge <?= $stateClass((string) ($row['status'] ?? '')) ?>"><?= e((string) ($row['status'] ?? '')) ?></span></td>
                  <td class="num"><?= $row['validationSampleSize'] === null ? '—' : (int) $row['validationSampleSize'] ?></td>
                  <td class="num"><?= $pct($row['accuracy'] ?? null) ?></td>
                  <td class="num mono"><?= $dash($row['ece'] ?? null, 3) ?></td>
                  <td class="num football-cell-actions">
                    <?php if (!empty($caps['approve'])): ?>
                      <?php if (in_array((string) ($row['status'] ?? ''), ['VALIDATED', 'CALIBRATED'], true)): ?>
                        <form method="post" action="/football/models/<?= (int) $row['id'] ?>/decide" class="football-inline-form">
                          <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>"><input type="hidden" name="activate" value="0">
                          <button class="btn small primary">approve</button>
                        </form>
                      <?php endif; ?>
                      <?php if (in_array((string) ($row['status'] ?? ''), ['APPROVED'], true)): ?>
                        <form method="post" action="/football/models/<?= (int) $row['id'] ?>/decide" class="football-inline-form" onsubmit="return confirm('Make this the ACTIVE model version? The previous ACTIVE version is retired by the same action.')">
                          <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>"><input type="hidden" name="activate" value="1">
                          <button class="btn small primary">activate</button>
                        </form>
                      <?php endif; ?>
                    <?php else: ?>
                      <span class="football-inline-note dim">needs sports.approve</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
        <p class="football-help">Allowed transitions: DRAFT → TRAINED → VALIDATED → CALIBRATED → APPROVED → ACTIVE (RETIRE from any state). Approving a model that has not been validated against stored settlements, or activating one that no operator approved, is refused by the registry.</p>
        </div>
      </section>
    </aside>
  </div>
</div>
