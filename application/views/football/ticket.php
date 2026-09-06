<?php defined('BASEPATH') or exit('No direct script access allowed');
/**
 * The Odds Prediction Ticket (spec §6).
 *
 * One professional ticket: every entry is a stored prediction for the date,
 * ordered by kickoff, carrying the match, league, teams, H/D/A percentages,
 * the predicted outcome and score, expected goals, confidence and its
 * A/B/C category. The footer states, unmissably, that nothing here is a
 * guarantee.
 *
 * @var array $ticket
 * @var string $date
 * @var string|null $category
 */
$ticket = $ticket ?? [];
$entries = (array) ($ticket['entries'] ?? []);
$summary = $ticket['summary'] ?? [];
$disclaimer = (string) ($ticket['disclaimer'] ?? 'Probabilities are statistical estimates from stored provider data. No prediction is a guaranteed outcome.');
$dash = static fn(mixed $v, int $dp = 2): string => is_numeric($v) ? number_format((float) $v, $dp) : '—';
$pct = static fn(mixed $v, int $dp = 1): string => is_numeric($v) ? number_format((float) $v * 100, $dp) . '%' : '—';
$kickoffLabel = static fn(?string $iso): string => $iso === null || $iso === '' ? '—' : gmdate('D M j, H:i', (int) strtotime($iso)) . ' UTC';
$catClass = static fn(?string $key): string => match ($key) { 'A' => 'b-violet', 'C' => 'b-green', default => 'b-amber', };
$catName = static fn(?string $key): string => match ($key) { 'A' => 'HOME ADVANTAGE', 'C' => 'AWAY ADVANTAGE', 'B' => 'BALANCED', default => 'UNCLASSIFIED', };
?>
<div class="page-head">
  <div>
    <h2>ODDS PREDICTION TICKET</h2>
    <p>
      <?= e((string) ($ticket['date'] ?? $date)) ?> · <?= (int) ($summary['entries'] ?? 0) ?> entr<?= (int) ($summary['entries'] ?? 0) === 1 ? 'y' : 'ies' ?>
      <?php if (!empty($category)): ?> · filtered to category <b><?= e($category) ?></b><?php endif; ?>
      · all entries are stored predictions from the connected football data provider — nothing is estimated on the page.
    </p>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:10px">
      <a class="btn small" href="/football/ticket?date=<?= e($yesterday) ?>">← Previous day</a>
      <a class="btn small" href="/football/ticket">Today</a>
      <a class="btn small" href="/football/ticket?date=<?= e($tomorrow) ?>">Next day →</a>
      <?php if (!empty($category)): ?>
        <a class="btn small" href="/football/ticket?date=<?= e($date) ?>">Clear category filter</a>
      <?php endif; ?>
      <a class="btn small" href="/football?date=<?= e($date) ?><?= $category ? '&category=' . e($category) : '' ?>">Open the analysis board</a>
    </div>
  </div>
</div>
<?php if (!empty($notice)): ?><div class="notice ok"><?= e($notice) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="notice err"><?= e($error) ?></div><?php endif; ?>
<?php if (!empty($moduleDisabled)): ?>
  <div class="notice err"><b>Football module disabled.</b> The ticket below is read-only over stored predictions; new predictions are not being generated until the module is re-enabled in the admin settings.</div>
<?php endif; ?>

<?php $state = (string) ($ticket['state'] ?? 'INVALID_DATE'); ?>
<?php if ($state !== 'POPULATED'): ?>
  <div class="panel">
    <div class="body" style="padding-top:16px">
      <p style="font-weight:700;font-size:15px"><?= e(str_replace('_', ' ', $state)) ?></p>
      <p class="dim"><?= e((string) ($ticket['message'] ?? 'No ticket can be issued for this date.')) ?></p>
      <p style="margin-top:10px"><a class="btn small" href="/football?date=<?= e($date) ?>">Back to the board</a></p>
    </div>
  </div>
<?php else: ?>
  <div class="panel" style="border:2px solid var(--violet)">
    <div class="body" style="padding-top:16px">
      <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:baseline">
        <div>
          <div style="font-size:16px;font-weight:800;letter-spacing:.04em">WINDELS ODDS PREDICTION TICKET — <?= e((string) ($ticket['date'] ?? '')) ?></div>
          <div class="dim" style="font-size:12px;margin-top:2px">
            <?= (int) ($summary['fixtures'] ?? 0) ?> stored fixture(s) on the date ·
            <?= (int) ($summary['predicted'] ?? 0) ?> predicted ·
            entries ordered by kickoff ·
            model <?= e((string) (($ticket['model']['label'] ?? 'none'))) ?><?= !empty($ticket['model']['version']) ? ' · ' . e((string) $ticket['model']['version']) : '' ?>
          </div>
        </div>
        <div style="text-align:right">
          <div class="mono" style="font-size:14px;font-weight:700">average confidence <?= is_numeric($summary['averageConfidence'] ?? null) ? number_format((float) $summary['averageConfidence'], 1) . '%' : '—' ?></div>
          <div class="dim" style="font-size:11px">confidence is a data-quality + model-agreement score, never a promise</div>
        </div>
      </div>

      <?php if ($entries === []): ?>
        <p class="dim" style="margin-top:14px"><?= e((string) ($ticket['message'] ?? 'No stored prediction matches this ticket.')) ?></p>
      <?php endif; ?>

      <?php foreach ($entries as $entry): ?>
        <?php
        $prob = (array) ($entry['probabilities'] ?? []);
        $score = (array) ($entry['predictedScore'] ?? []);
        $goals = (array) ($entry['expectedGoals'] ?? []);
        $quality = (array) ($entry['dataQuality'] ?? []);
        $entryCat = ($entry['category'] ?? null) !== null ? (string) $entry['category'] : '';
        ?>
        <div style="border-top:1px dashed var(--line);margin-top:14px;padding-top:14px">
          <div style="display:flex;gap:14px;align-items:flex-start;flex-wrap:wrap">
            <div style="width:52px;flex:none;text-align:center">
              <div class="mono" style="font-size:22px;font-weight:800;color:var(--violet)"><?= (int) ($entry['entryNumber'] ?? 0) ?></div>
              <div class="dim" style="font-size:10px;letter-spacing:.06em">ENTRY</div>
            </div>
            <div style="flex:1;min-width:260px">
              <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:baseline">
                <div style="font-weight:700;font-size:15px">
                  <a href="/football/match/<?= (int) ($entry['fixtureId'] ?? 0) ?>"><?= e((string) ($entry['homeTeam'] ?? '—')) ?> vs <?= e((string) ($entry['awayTeam'] ?? '—')) ?></a>
                </div>
                <div class="mono" style="font-size:15px;font-weight:800" title="Most likely scoreline from the stored goal distribution"><?= e((string) ($score['label'] ?? '—')) ?></div>
              </div>
              <div class="dim" style="font-size:12px;margin-top:2px">
                <?= e((string) ($entry['league'] ?? '—')) ?><?= !empty($entry['country']) ? ' · ' . e((string) $entry['country']) : '' ?>
                · kick-off <?= e($kickoffLabel($entry['kickoff'] ?? null)) ?>
                · status <?= e((string) ($entry['status'] ?? 'SCHEDULED')) ?>
              </div>
              <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;font-size:12px;align-items:center">
                <span class="badge <?= $catClass($entryCat !== '' ? $entryCat : null) ?>">
                  <?= $entryCat !== '' ? $entryCat . ' — ' . e((string) ($entry['categoryLabel'] ?? $catName($entryCat))) : 'UNCLASSIFIED' ?>
                </span>
                <span class="badge <?= $entryCat === 'A' ? 'b-green' : ($entryCat === 'C' ? 'b-red' : 'b-blue') ?>">
                  <?= e((string) ($entry['predictedOutcome'] ?? '—')) ?>
                </span>
                <span class="dim">expected goals <?= $dash($goals['home'] ?? null) ?>–<?= $dash($goals['away'] ?? null) ?><?= !empty($goals['method']) ? ' (' . e((string) $goals['method']) . ')' : '' ?></span>
                <span class="badge b-amber" title="Data-quality band of the stored feature set"><?= e((string) ($entry['band'] ?? '—')) ?> · <?= (int) ($quality['score'] ?? 0) ?>/100</span>
              </div>
            </div>
            <div style="width:220px;flex:none">
              <div class="meter">
                <?php foreach (['home' => ['var(--violet)', 'Home'], 'draw' => ['var(--muted)', 'Draw'], 'away' => ['var(--green)', 'Away']] as $side => [$colour, $label]): ?>
                  <?php $value = is_numeric($prob[$side] ?? null) ? (float) $prob[$side] : 0.0; ?>
                  <div class="row"><span class="dim"><?= e($label) ?> win</span><span class="mono"><?= $pct($prob[$side] ?? null) ?></span></div>
                  <div class="bar"><div style="width:<?= round($value * 100, 1) ?>%;background:<?= $colour ?>"></div></div>
                <?php endforeach; ?>
                <div class="row" style="margin-top:4px"><span class="dim">confidence</span><span class="mono" style="font-weight:700"><?= e((string) ($entry['confidenceLabel'] ?? '—')) ?><?= is_numeric($entry['confidence'] ?? null) ? ' · ' . number_format((float) $entry['confidence'], 1) . '/100' : '' ?></span></div>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>

      <?php $byCategory = (array) ($summary['byCategory'] ?? []); ?>
      <div style="border-top:1px solid var(--line);margin-top:14px;padding-top:12px">
        <div style="display:flex;gap:10px;flex-wrap:wrap;font-size:12px;align-items:center">
          <span class="dim" style="font-size:11px;letter-spacing:.06em">TICKET SUMMARY</span>
          <?php foreach ($byCategory as $key => $value): ?>
            <?php if ($key === 'UNCLASSIFIED') continue; ?>
            <span class="badge <?= $catClass((string) $key) ?>"><?= e((string) $key) ?> × <?= (int) $value ?></span>
          <?php endforeach; ?>
          <?php if (!empty($byCategory['UNCLASSIFIED'])): ?><span class="dim">+ <?= (int) $byCategory['UNCLASSIFIED'] ?> unclassified</span><?php endif; ?>
          <?php if (!empty($summary['outsideScope'])): ?><span class="dim">· <?= (int) $summary['outsideScope'] ?> outside the configured league scope</span><?php endif; ?>
          <span class="dim">· generated <?= e(gmdate('D M j, H:i', (int) strtotime((string) ($ticket['generatedAt'] ?? gmdate('c'))))) ?> UTC</span>
        </div>
        <p style="margin-top:10px;padding:10px 12px;border:1px solid #fb5d6b44;background:#fb5d6b14;border-radius:8px;font-size:12px;color:var(--red)">
          <b>⚠ <?= e($disclaimer) ?></b>
          Results may differ. Historical performance is available on the board and in the admin panel.
        </p>
      </div>
    </div>
  </div>
<?php endif; ?>
