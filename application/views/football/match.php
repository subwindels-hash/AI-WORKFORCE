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
 * @var array $caps
 * @var string $csrfToken
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
$caps = $caps ?? ['sync' => false, 'calibrate' => false, 'approve' => false, 'settle' => false];
$matchId = (int) ($fixtureId ?? 0);

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
$windelsModelId = 'Windels Model id: 1520863';
?>
<div class="page-head">
  <div>
    <h2><?= e((string) ($fixture['homeTeam'] ?? '—')) ?> vs <?= e((string) ($fixture['awayTeam'] ?? '—')) ?></h2>
    <p>
      <?= e($state($fixture['competition'] ?? null)) ?><?= !empty($fixture['country']) ? ' · ' . e((string) $fixture['country']) : '' ?>
      · kickoff <?= e(!empty($fixture['kickoff']) ? gmdate('D M j, H:i', (int) strtotime((string) $fixture['kickoff'])) . ' UTC' : 'DATA_UNAVAILABLE') ?>
      · status <b><?= e((string) ($fixture['status'] ?? 'UNKNOWN')) ?></b>
      <?php if (isset($fixture['minute']) && is_numeric($fixture['minute'])): ?><?= (int) $fixture['minute'] ?>'+<?php endif; ?>
      · <span class="mono dim"><?= e($windelsModelId) ?></span>
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
          <p class="dim" style="font-size:12px">Analyzing runs the engine over this match's stored data and produces a usable odds prediction — probabilities, predicted score, confidence and WINDELS fair odds. A match whose stored data falls below the quality floor is refused with its reason instead.</p>
          <?php if ($matchId > 0): ?>
            <form method="post" action="/football/match/<?= $matchId ?>/analyze" style="margin-top:8px" onsubmit="return confirm('Analyze this match now? The engine reads its stored data and writes one prediction row.') ">
              <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
              <?php if (!empty($caps['sync'])): ?>
                <button class="btn primary">Analyze this match — generate odds prediction</button>
              <?php else: ?>
                <button class="btn" disabled title="Requires the sports.manage permission">Analyze this match — generate odds prediction</button>
              <?php endif; ?>
            </form>
          <?php endif; ?>
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
          <div class="table-scroll">
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
          </div>
          <div class="table-scroll">
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
          </div>
        <?php endif; ?>
      </div>
    </div>

    <?php
    // ── WINDELS Intelligence ───────────────────────────────────────────────
    // Three questions, three answers, never merged into one number: what the
    // model believes (probability), what the market charges (price) and whether
    // the gap between them is worth anything (value). Confidence and data quality
    // hang off the first; the price hangs off none of them.
    $intel = is_array($prediction['intelligence'] ?? null) ? $prediction['intelligence'] : [];
    $marketView = is_array($prediction['market'] ?? null) ? $prediction['market'] : [];
    $scoreBlock = is_array($intel['score'] ?? null) ? $intel['score'] : [];
    $valueBlock = is_array($intel['fairValue'] ?? null) ? $intel['fairValue'] : [];
    $stabilityBlock = is_array($intel['stability'] ?? null) ? $intel['stability'] : [];
    $qualityBlock = is_array($intel['quality'] ?? null) ? $intel['quality'] : [];
    $driverBlock = is_array($intel['drivers'] ?? null) ? $intel['drivers'] : [];
    $freshBlock = is_array($intel['freshness'] ?? null) ? $intel['freshness'] : [];
    $money = static fn(mixed $v, int $dp = 2): string => is_numeric($v) ? number_format((float) $v, $dp) : '—';
    $signed = static fn(mixed $v): string => is_numeric($v) ? ($v >= 0 ? '+' : '') . number_format((float) $v * 100, 1) . '%' : '—';
    ?>
    <div class="panel">
      <h3>WINDELS Intelligence</h3>
      <div class="body" style="padding-top:12px">
        <div style="display:flex;gap:18px;flex-wrap:wrap;align-items:flex-start">
          <div style="min-width:150px">
            <div class="dim" style="font-size:11px">WINDELS Intelligence Score</div>
            <div style="font-size:30px;font-weight:800;line-height:1">
              <?php if (is_numeric($scoreBlock['score'] ?? null)): ?>
                <?= (int) $scoreBlock['score'] ?><span class="dim" style="font-size:15px;font-weight:600">/100</span>
              <?php else: ?>
                <span class="dim" style="font-size:18px">no score</span>
              <?php endif; ?>
            </div>
            <div class="dim" style="font-size:11px"><?= e((string) ($scoreBlock['label'] ?? $scoreBlock['note'] ?? '')) ?></div>
          </div>
          <div class="table-scroll" style="flex:1;min-width:230px">
            <table class="tbl">
              <tbody>
                <tr><td class="dim" style="width:170px">WINDELS probability</td>
                  <td class="mono"><b><?= $pct($marketView['probability'] ?? ($contract['prediction']['probabilities']['home'] ?? null)) ?></b>
                    <span class="dim" style="font-size:11px"><?= e((string) ($marketView['selectionLabel'] ?? 'selected outcome')) ?></span></td></tr>
                <tr><td class="dim">Market odds</td>
                  <?php if (is_numeric($marketView['odds'] ?? null)): ?>
                    <td class="mono"><?= $money($marketView['odds']) ?>
                      <span class="dim" style="font-size:11px">implied <?= $pct($marketView['impliedProbability'] ?? null) ?></span></td>
                  <?php elseif (is_numeric($valueBlock['windelsFairOdds'] ?? null)): ?>
                    <td class="mono"><?= $money($valueBlock['windelsFairOdds']) ?>
                      <span class="dim" style="font-size:11px">WINDELS fair odds — no provider price quoted, so the model's own price (1 ÷ probability) is the usable figure</span></td>
                  <?php else: ?>
                    <td class="mono">— <span class="dim" style="font-size:11px">no price quoted and no model estimate to price from</span></td>
                  <?php endif; ?></tr>
                <tr><td class="dim">WINDELS fair odds</td>
                  <td class="mono"><?= $money($valueBlock['windelsFairOdds'] ?? null) ?>
                    <?php if (is_numeric($valueBlock['fairOdds'] ?? null)): ?><span class="dim" style="font-size:11px">margin-removed <?= $money($valueBlock['fairOdds']) ?></span><?php endif; ?></td></tr>
                <tr><td class="dim">Potential Edge</td>
                  <td class="mono <?= (float) ($valueBlock['expectedValue'] ?? 0) >= 0 ? 'up' : 'down' ?>"><b><?= $signed($valueBlock['expectedValue'] ?? null) ?></b>
                    <span class="dim" style="font-size:11px"><?= e((string) ($valueBlock['valueLabel'] ?? 'UNPRICED')) ?><?= is_numeric($valueBlock['edgePoints'] ?? null) ? ' · ' . number_format((float) $valueBlock['edgePoints'], 1) . 'pp' : '' ?></span></td></tr>
                <tr><td class="dim">Confidence</td>
                  <td class="mono"><?= $dash($contract['prediction']['confidence'] ?? null, 1) ?>%
                    <span class="dim" style="font-size:11px"><?= e((string) ($contract['prediction']['confidenceBasis'] ?? 'RAW')) ?></span></td></tr>
                <tr><td class="dim">Risk</td>
                  <td class="mono"><?= e((string) ($intel['risk']['level'] ?? 'UNKNOWN')) ?>
                    <span class="dim" style="font-size:11px">data quality <?= (int) ($qualityBlock['score'] ?? 0) ?>/100</span></td></tr>
              </tbody>
            </table>
          </div>
        </div>
        <p class="dim" style="font-size:11px;margin:10px 0 0"><?= e((string) ($valueBlock['note'] ?? '')) ?></p>

        <!-- Prediction ≠ Confidence ≠ Value, spelled out because a single number
             would flatten three different judgements into one false one. -->
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:8px;font-size:11px">
          <span class="badge b-gray">Prediction — the outcome the model selects</span>
          <span class="badge b-gray">Confidence — how sure it is of that outcome</span>
          <span class="badge b-gray">Value — whether the price is worth taking</span>
        </div>

        <?php $withheldBlock = is_array($intel['withheld'] ?? null) ? $intel['withheld'] : []; ?>
        <?php if (!empty($withheldBlock['withheld'])): ?>
          <!-- The refusal is the finding. A match whose data does not clear the
               floor is not dressed up with a low-confidence number: it is said to
               be withheld, with the score that caused it. -->
          <div class="notice warnbox" style="margin-top:12px">
            <b><?= e((string) ($withheldBlock['headline'] ?? 'Prediction withheld — insufficient verified data')) ?></b>
            <?= e((string) ($withheldBlock['reason'] ?? '')) ?>
            <div class="dim" style="font-size:11px;margin-top:4px">Code: <?= e((string) ($withheldBlock['code'] ?? 'WITHHELD')) ?></div>
          </div>
        <?php elseif (!empty($withheldBlock['limitedEvidence'])): ?>
          <!-- Limited evidence is published, not withheld: the probabilities and
               fair odds above are usable, with the thinner basis stated here. -->
          <div class="notice info" style="margin-top:12px">
            <b><?= e((string) ($withheldBlock['headline'] ?? 'Limited evidence — usable with caution')) ?></b>
            <?= e((string) ($withheldBlock['reason'] ?? '')) ?>
            <div class="dim" style="font-size:11px;margin-top:4px">Code: <?= e((string) ($withheldBlock['code'] ?? 'DATA_QUALITY_LIMITED')) ?></div>
          </div>
        <?php elseif (!empty($withheldBlock['needsAnalysis']) && $contract === null): ?>
          <!-- Never analyzed is not withheld: the match simply has no row yet,
               and the Analyze action above is what creates one. -->
          <div class="notice info" style="margin-top:12px">
            <b><?= e((string) ($withheldBlock['headline'] ?? 'Not analyzed yet — no prediction stored')) ?></b>
            <?= e((string) ($withheldBlock['reason'] ?? '')) ?>
          </div>
        <?php endif; ?>

        <?php if (($stabilityBlock['state'] ?? '') === \AIWorkforce\Football\StabilityMonitor::UNSTABLE): ?>
          <div class="notice err" style="margin-top:12px"><b>Prediction unstable — significant model movement.</b>
            <?= e((string) ($stabilityBlock['reason'] ?? '')) ?></div>
        <?php elseif (($stabilityBlock['state'] ?? '') === \AIWorkforce\Football\StabilityMonitor::MOVED): ?>
          <div class="notice info" style="margin-top:12px"><b>Prediction moved.</b> <?= e((string) ($stabilityBlock['reason'] ?? '')) ?></div>
        <?php endif; ?>

        <?php if (!empty($stabilityBlock['revisions'])): ?>
          <div class="table-scroll" style="margin-top:10px">
            <table class="tbl">
              <thead><tr><th>Re-reading</th><th class="num">Was</th><th class="num">Now</th><th class="num">Move</th><th>Why</th></tr></thead>
              <tbody>
                <?php foreach ((array) $stabilityBlock['revisions'] as $revision): ?>
                  <tr>
                    <td class="dim mono" style="font-size:11px"><?= e((string) ($revision['recordedAt'] ?? '—')) ?></td>
                    <td class="num mono"><?= $pct($revision['previousProbability'] ?? null) ?></td>
                    <td class="num mono"><?= $pct($revision['probability'] ?? null) ?></td>
                    <td class="num mono <?= abs((float) ($revision['movementPoints'] ?? 0)) >= (float) ($stabilityBlock['thresholds']['unstablePoints'] ?? 8) ? 'down' : '' ?>">
                      <?= is_numeric($revision['movementPoints'] ?? null) ? ($revision['movementPoints'] >= 0 ? '+' : '') . number_format((float) $revision['movementPoints'], 1) . 'pp' : '—' ?></td>
                    <td style="font-size:11px"><?php $why = \AIWorkforce\Football\StabilityMonitor::triggerLabels((array) ($revision['triggerCodes'] ?? [])); ?><?= $why === [] ? '<span class="dim">no cause recorded — the calculation was not gated by a change</span>' : e(implode(', ', $why)) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

        <!-- Why the model selected it. Each row names its own source, so every
             sentence below can be checked against a stored number. -->
        <?php if ((array) ($driverBlock['drivers'] ?? []) !== []): ?>
          <div class="table-scroll" style="margin-top:12px">
            <table class="tbl">
              <thead><tr><th style="width:190px">Why WINDELS selected it</th><th>What the data says</th><th class="num">Verdict</th></tr></thead>
              <tbody>
                <?php foreach ((array) $driverBlock['drivers'] as $driver): ?>
                  <tr>
                    <td class="dim"><?= e((string) ($driver['label'] ?? '')) ?></td>
                    <td><?= e((string) ($driver['detail'] ?? '')) ?>
                      <?php if (!empty($driver['source'])): ?><div class="dim mono" style="font-size:10px"><?= e((string) $driver['source']) ?></div><?php endif; ?></td>
                    <td class="num"><span class="badge <?= ($driver['verdict'] ?? '') === 'STRONG' ? 'b-green' : 'b-gray' ?>"><?= e((string) ($driver['verdict'] ?? '—')) ?></span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <p class="dim" style="font-size:10px;margin:6px 0 0"><?= e((string) ($driverBlock['disclaimer'] ?? '')) ?></p>
        <?php endif; ?>

        <!-- The quality checklist: the reader sees which inputs existed, not only
             a score that summarised them. -->
        <?php if ((array) ($qualityBlock['checklist'] ?? []) !== []): ?>
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;font-size:11px">
            <?php foreach ((array) $qualityBlock['checklist'] as $item): ?>
              <span class="badge <?= !empty($item['present']) ? 'b-green' : 'b-gray' ?>"
                    title="<?= e((string) ($item['meaning'] ?? '')) ?>"><?= e((string) ($item['label'] ?? '')) ?><?= !empty($item['present']) ? '' : ' · missing' ?></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <!-- Last updated, as three clocks. -->
        <?php if (!empty($freshBlock['clocks'])): ?>
          <div class="table-scroll" style="margin-top:12px">
            <table class="tbl">
              <thead><tr><th>Last updated</th><th>Value</th><th class="num">Age</th><th class="num">Window</th><th>Source</th></tr></thead>
              <tbody>
                <?php foreach ((array) $freshBlock['clocks'] as $clock): ?>
                  <tr>
                    <td class="dim"><?= e((string) ($clock['label'] ?? '')) ?></td>
                    <td class="mono"><?= e((string) ($clock['at'] ?? '—')) ?> <span class="badge <?= ($clock['state'] ?? '') === 'STALE' ? 'b-amber' : 'b-green' ?>"><?= e((string) ($clock['state'] ?? '')) ?></span></td>
                    <td class="num mono"><?= e((string) ($clock['ageLabel'] ?? '—')) ?></td>
                    <td class="num mono dim"><?= e((string) ($clock['windowLabel'] ?? '—')) ?></td>
                    <td style="font-size:11px"><?= e((string) ($clock['source'] ?? '')) ?><?= !empty($clock['note']) ? ' — ' . e((string) $clock['note']) : '' ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
        <p class="dim" style="font-size:10px;margin:8px 0 0"><?= e((string) ($scoreBlock['disclaimer'] ?? '')) ?></p>
      </div>
    </div>

    <?php if ($settlement !== null && $settlement !== []): ?>
      <div class="panel">
        <h3>Settlement (stored, immutable)</h3>
        <div class="body" style="padding-top:12px">
          <div class="table-scroll">
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
          </div>
          <p class="dim" style="font-size:11px;margin-top:8px">The settlement row is appended next to the frozen prediction; the original prediction row is never rewritten.</p>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($liveEstimates !== []): ?>
      <div class="panel">
        <h3>Live model estimates</h3>
        <div class="body" style="padding-top:12px">
          <div class="table-scroll">
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
          </div>
          <p class="dim" style="font-size:11px;margin-top:8px">Stored as separate LIVE rows. The pre-match prediction above is untouched by them.</p>
        </div>
      </div>
    <?php endif; ?>

    <div class="panel">
      <h3>Teams — form and goal profile</h3>
      <div class="body" style="padding-top:12px">
        <div class="table-scroll">
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
  </div>

  <div class="stack">
    <div class="panel">
      <h3>Data quality — <?= (int) ($quality['score'] ?? 0) ?>/100</h3>
      <div class="body" style="padding-top:12px">
        <p style="margin-top:0"><span class="badge <?= $bandClass((string) ($quality['band'] ?? 'REJECTED')) ?>"><?= e((string) ($quality['band'] ?? 'REJECTED')) ?></span> <span class="dim" style="font-size:11px">qualified ≥70 · limited 50–69 · rejected &lt;50</span></p>
        <div class="table-scroll">
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
        </div>
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
        <div class="table-scroll">
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
    </div>

    <?php if ($inMatch !== null && $inMatch !== []): ?>
      <div class="panel">
        <h3>Match state as stored</h3>
        <div class="body" style="padding-top:12px">
          <div class="table-scroll">
            <table class="tbl">
              <tbody>
                <?php foreach ($inMatch as $key => $value): ?>
                  <tr><td class="dim"><?= e($pretty((string) $key)) ?></td><td class="mono"><?= is_array($value) ? e(json_encode($value)) : e((string) $value) ?></td></tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <div class="panel">
      <h3>Provenance</h3>
      <div class="body" style="padding-top:12px">
        <p class="dim" style="margin-top:0;font-size:11px">Football odds prediction is shown under <b><?= e($windelsModelId) ?></b>. Provider identifiers are hidden from the operator view.</p>
        <div class="table-scroll">
          <table class="tbl">
            <thead><tr><th>Input</th><th>Coverage</th><th>Source</th></tr></thead>
            <tbody>
              <?php foreach ($coverage as $key => $value): ?>
                <tr>
                  <td><?= e($pretty((string) $key)) ?></td>
                  <td class="mono"><?= is_bool($value) ? ($value ? 'yes' : 'no') : (is_numeric($value) ? (int) $value : e($state($value))) ?></td>
                  <td class="dim mono" style="font-size:11px"><?= e($windelsModelId) ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if ($coverage === []): ?><tr><td colspan="3" class="dim">no coverage record stored</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
