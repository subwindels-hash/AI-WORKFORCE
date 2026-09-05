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
<div class="page-head">
  <div>
    <h2>Football models &amp; calibration</h2>
    <p>Lifecycle state, measured performance and calibration history — every figure read from stored rows.</p>
    <p style="margin-top:6px"><a class="btn small" href="/football">← Back to today's predictions</a></p>
  </div>
</div>
<?php if (!empty($notice)): ?><div class="notice ok"><?= e($notice) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="notice err"><?= e($error) ?></div><?php endif; ?>

<div class="grid cols-main">
  <div class="stack">
    <div class="panel">
      <h3>Model used by the prediction engine</h3>
      <div class="body" style="padding-top:12px">
        <p style="margin-top:0">
          <span class="badge <?= $stateClass((string) ($models['state'] ?? 'NONE')) ?>"><?= e((string) ($models['label'] ?? 'MODEL_NOT_LOADED')) ?></span>
          <?php if (($models['state'] ?? '') !== 'ACTIVE'): ?>
            <span class="dim" style="font-size:11px">— predictions continue to run and are labelled uncalibrated/experimental; an empty settlement history never blocks them.</span>
          <?php endif; ?>
        </p>
        <?php if (!empty($models['reason'])): ?><p class="dim" style="font-size:12px"><?= e((string) $models['reason']) ?></p><?php endif; ?>
        <?php if ($active === null): ?>
          <p class="dim">No model version is registered yet. The engine registers the deployed scoring configuration as <b>DRAFT</b> the first time it analyzes a fixture — never as an approved model.</p>
        <?php else: ?>
          <table class="tbl">
            <tbody>
              <?php foreach ([
                ['Model id', $active['modelId'] ?? null], ['Model name', $active['name'] ?? null], ['Model version', $active['version'] ?? null],
                ['Algorithm', $active['algorithm'] ?? null], ['Feature version', $active['featureVersion'] ?? null],
                ['Training dataset version', $active['trainingDatasetVersion'] ?? null],
                ['Status', $active['status'] ?? null],
                ['Created', $active['createdAt'] ?? null], ['Trained', $active['trainedAt'] ?? null],
                ['Validated', $active['validatedAt'] ?? null], ['Calibrated', $active['calibratedAt'] ?? null],
                ['Approved', $active['approvedAt'] ?? null], ['Approved by', $active['approvedBy'] ?? null],
                ['Activated', $active['activatedAt'] ?? null], ['Last evaluated', $active['lastEvaluatedAt'] ?? null],
              ] as [$label, $value]): ?>
                <tr><td class="dim" style="width:180px"><?= e($label) ?></td><td class="mono"><?= $value === null ? '—' : e(is_string($value) && (str_contains((string) $value, 'T') && strlen((string) $value) > 15) ? $when($value) : (string) $value) ?></td></tr>
              <?php endforeach; ?>
              <tr><td class="dim">Validation sample size</td><td class="mono"><?= $active['validationSampleSize'] === null ? '—' : (int) $active['validationSampleSize'] ?> settled prediction(s)</td></tr>
              <tr><td class="dim">Accuracy</td><td class="mono"><?= is_numeric($active['accuracy'] ?? null) ? $pct($active['accuracy']) : '—' ?></td></tr>
              <tr><td class="dim">Log loss</td><td class="mono"><?= $dash($active['logLoss'] ?? null) ?></td></tr>
              <tr><td class="dim">Brier score</td><td class="mono"><?= $dash($active['brierScore'] ?? null) ?></td></tr>
              <tr><td class="dim">ECE</td><td class="mono"><?= $dash($active['ece'] ?? null) ?></td></tr>
              <tr><td class="dim">Calibration version</td><td class="mono"><?= e((string) ($active['calibrationVersion'] ?? '—')) ?> <span class="badge <?= $stateClass((string) ($active['calibrationStatus'] ?? 'CALIBRATION_PENDING')) ?>"><?= e((string) ($active['calibrationStatus'] ?? 'CALIBRATION_PENDING')) ?></span></td></tr>
            </tbody>
          </table>
        <?php endif; ?>
        <?php if (!empty($caps['calibrate'])): ?>
          <form method="post" action="/football/calibrate" style="margin-top:10px">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
            <button class="btn small primary">Fit calibration from stored settlements</button>
          </form>
        <?php else: ?>
          <button class="btn small" disabled title="Requires the sports.manage permission" style="margin-top:10px">Fit calibration (needs sports.manage)</button>
        <?php endif; ?>
      </div>
    </div>

    <div class="panel">
      <h3>Calibration versions (<?= count($calibrationVersions) ?> for this model · <?= (int) ($models['approvedCalibrationCount'] ?? 0) ?> usable)</h3>
      <div class="body scroll" style="padding-top:12px">
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
                  <td class="dim mono" style="font-size:10px"><?= e($when($row['windowStart'] ?? null)) ?> → <?= e($when($row['windowEnd'] ?? null)) ?></td>
                  <td class="dim" style="font-size:11px"><?= e($when($row['approvedAt'] ?? null)) ?><?= !empty($row['approvedBy']) ? ' · ' . e((string) $row['approvedBy']) : '' ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php $reason = $calibrationVersions[0]['reason'] ?? null; if (is_string($reason) && $reason !== ''): ?>
            <p class="dim" style="font-size:11px;margin-top:8px"><b>Fitting note:</b> <?= e($reason) ?></p>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="panel">
      <h3>30-day performance by model version</h3>
      <div class="body" style="padding-top:12px">
        <?php if (($perf['state'] ?? '') !== 'MEASURED'): ?>
          <p class="dim">No settled predictions yet. Historical performance metrics will appear after predicted matches have completed.</p>
        <?php else: ?>
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
          <p class="dim" style="font-size:11px;margin-top:8px">These are the numbers an approval is judged on; the board's own 30-day panel shows the same stored aggregates, never a second calculation.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="stack">
    <div class="panel">
      <h3>All model versions</h3>
      <div class="body scroll" style="padding-top:12px">
        <?php if ($versions === []): ?>
          <p class="dim">No model versions recorded.</p>
        <?php else: ?>
          <table class="tbl">
            <thead><tr><th>Model</th><th>Version</th><th>Status</th><th class="num">Samples</th><th class="num">Acc.</th><th class="num">ECE</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($versions as $row): ?>
                <tr>
                  <td class="dim" style="font-size:11px"><?= e((string) ($row['name'] ?? '')) ?></td>
                  <td class="mono"><?= e((string) ($row['version'] ?? '')) ?></td>
                  <td><span class="badge <?= $stateClass((string) ($row['status'] ?? '')) ?>"><?= e((string) ($row['status'] ?? '')) ?></span></td>
                  <td class="num"><?= $row['validationSampleSize'] === null ? '—' : (int) $row['validationSampleSize'] ?></td>
                  <td class="num"><?= $pct($row['accuracy'] ?? null) ?></td>
                  <td class="num mono"><?= $dash($row['ece'] ?? null, 3) ?></td>
                  <td class="num" style="white-space:nowrap">
                    <?php if (!empty($caps['approve'])): ?>
                      <?php if (in_array((string) ($row['status'] ?? ''), ['VALIDATED', 'CALIBRATED'], true)): ?>
                        <form method="post" action="/football/models/<?= (int) $row['id'] ?>/decide" style="display:inline">
                          <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>"><input type="hidden" name="activate" value="0">
                          <button class="btn small primary">approve</button>
                        </form>
                      <?php endif; ?>
                      <?php if (in_array((string) ($row['status'] ?? ''), ['APPROVED'], true)): ?>
                        <form method="post" action="/football/models/<?= (int) $row['id'] ?>/decide" style="display:inline" onsubmit="return confirm('Make this the ACTIVE model version? The previous ACTIVE version is retired by the same action.')">
                          <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>"><input type="hidden" name="activate" value="1">
                          <button class="btn small primary">activate</button>
                        </form>
                      <?php endif; ?>
                    <?php else: ?>
                      <span class="dim" style="font-size:10px">needs sports.approve</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
        <p class="dim" style="font-size:11px;margin-top:8px">Allowed transitions: DRAFT → TRAINED → VALIDATED → CALIBRATED → APPROVED → ACTIVE (RETIRE from any state). Approving a model that has not been validated against stored settlements, or activating one that no operator approved, is refused by the registry.</p>
      </div>
    </div>
  </div>
</div>
