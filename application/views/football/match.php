<?php defined('BASEPATH') or exit('No direct script access allowed');
/**
 * One fixture, fully described (§4/§11/§12/§14/§20).
 *
 * Every number on this page is either a stored provider value or a stored model
 * output. Where the provider did not deliver, the field prints its state
 * (DATA_UNAVAILABLE / LIMITED_DATA) instead of a number.
 *
 * @var array $analysis
 * @var array $prediction
 * @var int $fixtureId
 */
$analysis = $analysis ?? [];
$fixture = $analysis['fixture'] ?? [];
$teams = $analysis['teams'] ?? [];
$quality = $analysis['dataQuality'] ?? [];
$coverage = $analysis['coverage'] ?? [];
$provenance = $analysis['provenance'] ?? [];
$h2h = $analysis['headToHead'] ?? [];
$inMatch = $analysis['inMatch'] ?? [];
$contract = $prediction['prediction'] ?? null;
$settlement = $prediction['settlement'] ?? null;
$liveEstimates = $prediction['liveEstimates'] ?? [];

$dash = static fn(mixed $v, int $dp = 2): string => is_numeric($v) ? number_format((float) $v, $dp) : '—';
$state = static fn(mixed $v): string => $v === null || $v === '' || $v === [] ? 'DATA_UNAVAILABLE' : (string) $v;
$pct = static fn(mixed $v, int $dp = 1): string => is_numeric($v) ? number_format((float) $v * 100, $dp) . '%' : '—';
$pretty = static fn(string $key): string => trim(preg_replace('/(?<!^)[A-Z]/', ' $0', $key) ?? $key);
$bandClass = static fn(string $band): string => match (strtoupper($band)) {
    'QUALIFIED' => 'b-green',
    'LIMITED', 'LIMITED_DATA' => 'b-amber',
    default => 'b-gray',
};
$score = static fn(mixed $row, string $side): string => is_array($row) && isset($row[$side]) && is_numeric($row[$side])
    ? (string) (int) $row[$side] : '—';
?>
<div class="page-head">
  <div>
    <h2><?= e((string) ($fixture['homeTeam'] ?? '—')) ?> vs <?= e((string) ($fixture['awayTeam'] ?? '—')) ?></h2>
    <p>
      <?= e($state($fixture['competition'] ?? null)) ?><?= !empty($fixture['country']) ? ' · ' . e((string) $fixture['country']) : '' ?>
      · kickoff <?= e(!empty($fixture['kickoff']) ? gmdate('D M j, H:i', (int) strtotime((string) $fixture['kickoff'])) . ' UTC' : 'DATA_UNAVAILABLE') ?>
      · status <b><?= e((string) ($fixture['status'] ?? 'UNKNOWN')) ?></b>
      <?php if (isset($fixture['minute']) && is_numeric($fixture['minute'])): ?><?= (int) $fixture['minute'] ?>'+<?php endif; ?>
      · external id <span class="mono dim"><?= e($state($fixture['externalId'] ?? null)) ?></span>
    </p>
    <p style="margin-top:6px"><a class="btn small" href="/football">← Back to the board</a></p>
  </div>
</div>
<?php if (!empty($notice)): ?><div class="notice ok"><?= e($notice) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="notice err"><?= e($error) ?></div><?php endif; ?>

<div class="grid cols-main">
  <div class="stack">
    <div class="panel">
      <h3>Prediction (§20 contract)</h3>
      <div class="body" style="padding-top:12px">
        <?php if ($contract === null): ?>
          <p class="dim"><?= e((string) ($prediction['message'] ?? 'No prediction row is stored for this fixture.')) ?></p>
          <?php if (!empty($prediction['reason'])): ?><p class="dim" style="font-size:12px"><?= e((string) $prediction['reason']) ?></p><?php endif; ?>
        <?php else: ?>
          <?php $p = $contract['prediction'] ?? []; $prob = $p['probabilities'] ?? []; ?>
          <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap">
            <div>
              <div style="font-size:18px;font-weight:700"><?= e((string) ($p['result'] ?? '—')) ?> · <?= (int) ($p['predictedScore']['home'] ?? 0) ?>–<?= (int) ($p['predictedScore']['away'] ?? 0) ?></div>
              <div class="dim" style="font-size:11px">most likely scoreline from the stored goal distribution</div>
            </div>
            <div style="text-align:right">
              <div class="mono" style="font-size:18px;font-weight:700"><?= $dash($p['confidence'] ?? null, 1) ?>%</div>
              <div class="dim" style="font-size:11px">confidence · <?= e((string) ($p['confidenceBasis'] ?? 'RAW')) ?></div>
            </div>
          </div>
          <table class="tbl" style="margin-top:10px">
            <thead><tr><th>Outcome</th><th class="num">Probability</th><th class="num">Raw model</th></tr></thead>
            <tbody>
              <?php $raw = $contract['rawProbabilities'] ?? []; ?>
              <?php foreach (['home' => 'Home win', 'draw' => 'Draw', 'away' => 'Away win'] as $key => $label): ?>
                <tr><td><?= e($label) ?> — <?= e((string) ($key === 'home' ? ($fixture['homeTeam'] ?? '') : ($key === 'away' ? ($fixture['awayTeam'] ?? '') : ''))) ?></td>
                  <td class="num mono"><?= $pct($prob[$key] ?? null) ?></td>
                  <td class="num mono dim"><?= $pct($raw[$key] ?? null) ?><?= is_numeric($raw[$key] ?? null) && is_numeric($prob[$key] ?? null) && abs((float) $raw[$key] - (float) $prob[$key]) > 0.0005 ? ' (calibrated)' : '' ?></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <table class="tbl" style="margin-top:10px">
            <tbody>
              <tr><td class="dim" style="width:150px">Expected total goals</td><td class="mono"><?= $dash($p['expectedTotalGoals'] ?? null, 2) ?></td></tr>
              <tr><td class="dim">Alternative scores</td><td class="mono">
                <?php $alts = (array) ($contract['alternativeScores'] ?? []); ?>
                <?php if ($alts === []): ?><span class="dim">—</span><?php endif; ?>
                <?php foreach (array_slice($alts, 0, 5) as $alt): ?>
                  <?= (int) ($alt['home'] ?? 0) ?>–<?= (int) ($alt['away'] ?? 0) ?> (<?= $pct($alt['probability'] ?? null) ?>)&nbsp;&nbsp;
                <?php endforeach; ?>
              </td></tr>
              <tr><td class="dim">Data quality</td><td><span class="badge <?= $bandClass((string) ($contract['dataQuality']['status'] ?? '')) ?>"><?= e((string) ($contract['dataQuality']['status'] ?? '—')) ?> · <?= (int) ($contract['dataQuality']['score'] ?? 0) ?>/100</span></td></tr>
              <tr><td class="dim">Model version</td><td class="mono dim"><?= e((string) ($contract['model']['version'] ?? '—')) ?> · calibration <?= e((string) ($p['calibrationState'] ?? 'CALIBRATION_PENDING')) ?><?= !empty($contract['model']['calibrationVersion']) ? ' (' . e((string) $contract['model']['calibrationVersion']) . ')' : '' ?></td></tr>
              <tr><td class="dim">Generated at</td><td class="mono dim"><?= e(!empty($contract['generatedAt']) ? gmdate('D M j, H:i:s', (int) strtotime((string) $contract['generatedAt'])) . ' UTC' : '—') ?></td></tr>
              <tr><td class="dim">Reasoning</td><td><?= e((string) ($contract['reason'] ?? '—')) ?></td></tr>
              <tr><td class="dim">Settlement state</td><td class="mono"><?= e((string) ($contract['settlementState'] ?? 'OPEN')) ?></td></tr>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($settlement !== null && $settlement !== []): ?>
      <div class="panel">
        <h3>Settlement (stored, immutable)</h3>
        <div class="body" style="padding-top:12px">
          <table class="tbl">
            <tbody>
              <tr><td class="dim" style="width:170px">Final score</td><td class="mono"><b><?= $score($settlement, 'actual_home_score') ?>–<?= $score($settlement, 'actual_away_score') ?></b> · result <?= e(strtoupper((string) ($settlement['actual_result'] ?? '—'))) ?> · source <?= e((string) ($settlement['result_source'] ?? 'PROVIDER')) ?></td></tr>
              <tr><td class="dim">Predicted score</td><td class="mono"><?= $score($settlement, 'predicted_home_score') ?>–<?= $score($settlement, 'predicted_away_score') ?> · result <?= e(strtoupper((string) ($settlement['predicted_result'] ?? '—'))) ?></td></tr>
              <tr><td class="dim">Correct result</td><td><span class="badge <?= (int) ($settlement['correct_result'] ?? 0) === 1 ? 'b-green' : 'b-red' ?>"><?= (int) ($settlement['correct_result'] ?? 0) === 1 ? 'YES' : 'NO' ?></span></td></tr>
              <tr><td class="dim">Correct exact score</td><td><span class="badge <?= (int) ($settlement['correct_exact_score'] ?? 0) === 1 ? 'b-green' : 'b-red' ?>"><?= (int) ($settlement['correct_exact_score'] ?? 0) === 1 ? 'YES' : 'NO' ?></span></td></tr>
              <tr><td class="dim">Goal error / Brier / log loss</td><td class="mono"><?= $dash($settlement['absolute_goal_error'] ?? null, 2) ?> / <?= $dash($settlement['brier'] ?? null, 4) ?> / <?= $dash($settlement['log_loss'] ?? null, 4) ?></td></tr>
              <tr><td class="dim">Probabilities at prediction</td><td class="mono dim">home <?= $pct($settlement['probability_home'] ?? null) ?> · draw <?= $pct($settlement['probability_draw'] ?? null) ?> · away <?= $pct($settlement['probability_away'] ?? null) ?></td></tr>
              <tr><td class="dim">Confidence / data quality</td><td class="mono dim"><?= $dash($settlement['confidence'] ?? null, 1) ?>% · <?= (int) ($settlement['data_quality_score'] ?? 0) ?>/100</td></tr>
              <tr><td class="dim">Settled at</td><td class="mono dim"><?= e(!empty($settlement['settled_at']) ? gmdate('D M j, H:i:s', (int) strtotime((string) $settlement['settled_at'])) . ' UTC' : '—') ?></td></tr>
            </tbody>
          </table>
          <p class="dim" style="font-size:11px;margin-top:8px">The settlement row is appended next to the frozen prediction; the original prediction row is never rewritten.</p>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($liveEstimates !== []): ?>
      <div class="panel">
        <h3>Live model estimates</h3>
        <div class="body" style="padding-top:12px">
          <table class="tbl">
            <thead><tr><th>Generated</th><th>State</th><th>Result</th><th class="num">Confidence</th></tr></thead>
            <tbody>
              <?php foreach ($liveEstimates as $row): ?>
                <tr>
                  <td class="mono dim"><?= e(!empty($row['generatedAt']) ? gmdate('M j, H:i:s', (int) strtotime((string) $row['generatedAt'])) : '—') ?></td>
                  <td class="dim">LIVE</td>
                  <td class="mono"><?= e((string) ($row['prediction']['result'] ?? '—')) ?> <?= (int) ($row['prediction']['predictedScore']['home'] ?? 0) ?>–<?= (int) ($row['prediction']['predictedScore']['away'] ?? 0) ?></td>
                  <td class="num mono"><?= $dash($row['prediction']['confidence'] ?? null, 1) ?>% <span class="dim"><?= e((string) ($row['prediction']['confidenceBasis'] ?? 'RAW')) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <p class="dim" style="font-size:11px;margin-top:8px">Stored as separate LIVE rows. The pre-match prediction above is untouched by them.</p>
        </div>
      </div>
    <?php endif; ?>

    <div class="panel">
      <h3>Teams — form and goal profile</h3>
      <div class="body" style="padding-top:12px">
        <table class="tbl">
          <thead><tr><th>Input</th><th><?= e((string) ($fixture['homeTeam'] ?? 'Home')) ?></th><th><?= e((string) ($fixture['awayTeam'] ?? 'Away')) ?></th></tr></thead>
          <tbody>
            <?php
            $home = $teams['HOME'] ?? [];
            $away = $teams['AWAY'] ?? [];
            $rows = [
              'Recent form (last 5)' => static fn(array $t): string => isset($t['form']['last5']['string']) ? (string) $t['form']['last5']['string'] . ' (' . (int) ($t['form']['last5']['played'] ?? 0) . ' played)' : 'DATA_UNAVAILABLE',
              'Recent form (last 10)' => static fn(array $t): string => isset($t['form']['last10']['string']) ? (string) $t['form']['last10']['string'] . ' (' . (int) ($t['form']['last10']['played'] ?? 0) . ' played)' : 'DATA_UNAVAILABLE',
              'Home / away split' => static fn(array $t): string => isset($t['form']['home']['string'])
                  ? (string) $t['form']['home']['string'] . ' at home'
                  : (isset($t['form']['away']['string']) ? (string) $t['form']['away']['string'] . ' away' : 'DATA_UNAVAILABLE'),
              'Wins-draws-losses' => static fn(array $t): string => isset($t['form']['last10']) ? (int) ($t['form']['last10']['wins'] ?? 0) . '-' . (int) ($t['form']['last10']['draws'] ?? 0) . '-' . (int) ($t['form']['last10']['losses'] ?? 0) : 'DATA_UNAVAILABLE',
              'Avg goals scored' => static fn(array $t): string => is_numeric($t['avgGoalsScored'] ?? null) ? number_format((float) $t['avgGoalsScored'], 2) : 'DATA_UNAVAILABLE',
              'Avg goals conceded' => static fn(array $t): string => is_numeric($t['avgGoalsConceded'] ?? null) ? number_format((float) $t['avgGoalsConceded'], 2) : 'DATA_UNAVAILABLE',
              'Attack strength (league avg = 1.00)' => static fn(array $t): string => is_numeric($t['attackStrength'] ?? null) ? number_format((float) $t['attackStrength'], 2) : 'DATA_UNAVAILABLE',
              'Defensive weakness (league avg = 1.00)' => static fn(array $t): string => is_numeric($t['defenseWeakness'] ?? null) ? number_format((float) $t['defenseWeakness'], 2) : 'DATA_UNAVAILABLE',
              'Clean-sheet frequency' => static fn(array $t): string => is_numeric($t['cleanSheetRate'] ?? null) ? number_format((float) $t['cleanSheetRate'] * 100, 0) . '%' : 'DATA_UNAVAILABLE',
              'Failed-to-score frequency' => static fn(array $t): string => is_numeric($t['failedToScoreRate'] ?? null) ? number_format((float) $t['failedToScoreRate'] * 100, 0) . '%' : 'DATA_UNAVAILABLE',
              'xG tendency' => static fn(array $t): string => is_numeric($t['expectedGoalsTendency'] ?? null) ? number_format((float) $t['expectedGoalsTendency'], 2) : 'DATA_UNAVAILABLE',
              'Strength source' => static fn(array $t): string => (string) ($t['attackSource'] ?? 'DATA_UNAVAILABLE'),
            ];
            foreach ($rows as $label => $fn): ?>
              <tr><td class="dim"><?= e((string) $label) ?></td><td class="mono"><?= e($fn($home)) ?></td><td class="mono"><?= e($fn($away)) ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="stack">
    <div class="panel">
      <h3>Data quality — <?= (int) ($quality['score'] ?? 0) ?>/100</h3>
      <div class="body" style="padding-top:12px">
        <p style="margin-top:0"><span class="badge <?= $bandClass((string) ($quality['band'] ?? 'REJECTED')) ?>"><?= e((string) ($quality['band'] ?? 'REJECTED')) ?></span> <span class="dim" style="font-size:11px">qualified ≥70 · limited 50–69 · rejected &lt;50</span></p>
        <table class="tbl">
          <thead><tr><th>Component</th><th class="num">Value</th><th class="num">Weight</th><th class="num">Contribution</th></tr></thead>
          <tbody>
            <?php foreach (($quality['components'] ?? []) as $key => $component): ?>
              <tr>
                <td><?= e($pretty((string) $key)) ?></td>
                <td class="num mono"><?= $dash($component['value'] ?? null, 1) ?></td>
                <td class="num mono dim"><?= $dash(isset($component['weight']) ? (float) $component['weight'] * 100 : null, 0) ?>%</td>
                <td class="num mono"><?= $dash($component['contribution'] ?? null, 1) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php if (!empty($quality['reasons'])): ?>
          <p class="dim" style="font-size:11px;margin-bottom:0">Credits: <?= e(implode(' · ', array_slice((array) $quality['reasons'], 0, 6))) ?></p>
        <?php endif; ?>
        <?php if (!empty($quality['reasonsAbsent'])): ?>
          <p class="dim" style="font-size:11px">Missing: <?= e(implode(' · ', array_slice((array) $quality['reasonsAbsent'], 0, 6))) ?></p>
        <?php endif; ?>
      </div>
    </div>

    <div class="panel">
      <h3>Head to head &amp; competition</h3>
      <div class="body" style="padding-top:12px">
        <table class="tbl">
          <tbody>
            <tr><td class="dim" style="width:130px">Sample</td><td><?= (int) ($h2h['meetings'] ?? 0) ?> stored meeting<?= (int) ($h2h['meetings'] ?? 0) === 1 ? '' : 's' ?></td></tr>
            <tr><td class="dim">Summary</td><td><?= e($state($h2h['summary'] ?? null)) ?></td></tr>
            <tr><td class="dim">Weight applied</td><td class="mono"><?= $dash($h2h['weight'] ?? null, 2) ?></td></tr>
            <?php if (!empty($h2h['oldest'])): ?><tr><td class="dim">Oldest</td><td class="mono dim"><?= e((string) $h2h['oldest']) ?></td></tr><?php endif; ?>
            <tr><td class="dim">Competition strength</td><td class="mono"><?= $dash($analysis['competition']['strength'] ?? null, 3) ?> <span class="dim"><?= e($state($analysis['competition']['label'] ?? null)) ?></span></td></tr>
            <?php if (!empty($analysis['competition']['dataState'])): ?>
              <tr><td class="dim">League table state</td><td class="mono"><?= e((string) $analysis['competition']['dataState']) ?></td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <?php if ($inMatch !== null && $inMatch !== []): ?>
      <div class="panel">
        <h3>Match state as stored</h3>
        <div class="body" style="padding-top:12px">
          <table class="tbl">
            <tbody>
              <?php foreach ($inMatch as $key => $value): ?>
                <tr><td class="dim"><?= e($pretty((string) $key)) ?></td><td class="mono"><?= is_array($value) ? e(json_encode($value)) : e((string) $value) ?></td></tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <div class="panel">
      <h3>Provenance</h3>
      <div class="body" style="padding-top:12px">
        <p class="dim" style="margin-top:0;font-size:11px">Provider <b><?= e((string) ($analysis['provider']['name'] ?? 'DATA_UNAVAILABLE')) ?></b> (<?= e((string) ($analysis['provider']['status'] ?? 'NOT_CONFIGURED')) ?>).</p>
        <table class="tbl">
          <thead><tr><th>Input</th><th>Coverage</th><th>Source</th></tr></thead>
          <tbody>
            <?php foreach ($coverage as $key => $value): ?>
              <tr>
                <td><?= e($pretty((string) $key)) ?></td>
                <td class="mono"><?= is_bool($value) ? ($value ? 'yes' : 'no') : (is_numeric($value) ? (int) $value : e($state($value))) ?></td>
                <td class="dim" style="font-size:11px"><?= e($state($provenance[$key] ?? null)) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if ($coverage === []): ?><tr><td colspan="3" class="dim">no coverage record stored</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
