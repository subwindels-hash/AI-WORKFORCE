<?php defined('BASEPATH') or exit('No direct script access allowed');
/**
 * Football Intelligence console (§10/§11/§16).
 *
 * One panel per concern, rendered from the same payload the JSON API returns:
 * the board, live matches, the data-feed diagnostics, and one 30-day
 * performance window. Every figure is read from a stored row; an absent figure
 * prints — with its state named, never 0.
 *
 * @var array $dashboard
 * @var string $date
 * @var array $caps
 */
$d = $dashboard ?? [];
$board = $d['board'] ?? [];
$diag = $d['diagnostics'] ?? [];
$perf = $d['performance'] ?? [];
$live = $d['live'] ?? [];
$models = $d['models'] ?? [];
$caps = $caps ?? ['sync' => false, 'calibrate' => false, 'approve' => false, 'settle' => false];
$summary = $board['summary'] ?? ['fixtures' => 0, 'analyzed' => 0, 'qualified' => 0, 'limited' => 0, 'rejected' => 0];

// The selection the operator made — competition, premium league and market. It
// is computed once, at the top, because every pager link and the generate form
// carry it: paging and generating stay inside the league and market chosen.
$filters = is_array($board['filters'] ?? null) ? $board['filters'] : [];
$competitions = is_array($filters['competitions']['competitions'] ?? null) ? $filters['competitions']['competitions'] : [];
$premium = is_array($filters['competitions']['premium'] ?? null) ? $filters['competitions']['premium'] : null;
$selectedCompetition = is_array($filters['competition'] ?? null) ? $filters['competition'] : null;
$selectedExternal = (string) ($selectedCompetition['externalId'] ?? '');
$marketBlock = is_array($board['market'] ?? null) ? $board['market'] : [];
$marketList = is_array($marketBlock['available'] ?? null) ? $marketBlock['available'] : [];
$selectedMarket = (string) ($marketBlock['key'] ?? 'MATCH_WINNER');
$carry = [];
if ($selectedExternal !== '') $carry['competition'] = (string) ($selectedCompetition['requested'] ?? $selectedExternal);
if ($selectedMarket !== '') $carry['market'] = $selectedMarket;

$dash = static fn(mixed $v, int $dp = 1): string => is_numeric($v) ? number_format((float) $v, $dp) : '—';
$percent = static fn(mixed $v, int $dp = 1): string => is_numeric($v) ? number_format((float) $v * 100, $dp) . '%' : '—';
$bandClass = static fn(string $band): string => match (strtoupper($band)) {
    'QUALIFIED' => 'b-green',
    'LIMITED', 'LIMITED_DATA' => 'b-amber',
    default => 'b-gray',
};
$stateClass = static fn(string $state): string => match (strtoupper($state)) {
    'READY', 'CONNECTED', 'AVAILABLE', 'ACTIVE', 'MEASURED', 'CALIBRATED', 'ONLINE', 'POPULATED' => 'up',
    'DEGRADED', 'LIMITED_DATA', 'LIMITED', 'PENDING', 'CADENCE' => 'synth',
    default => 'down',
};
$kickoffLabel = static fn(?string $iso): string => $iso === null || $iso === '' ? '—' : gmdate('M j, H:i', (int) strtotime($iso)) . ' UTC';
// Kickoff date and time, printed separately so a live card can say when the
// match actually started. Both come from the stored fixture; a fixture the
// provider gave no kickoff for prints the state token, never a guessed time.
$kickoffStamp = static function (mixed $iso): string {
    $ts = is_string($iso) && trim($iso) !== '' ? strtotime($iso) : false;
    return $ts === false ? 'DATA_UNAVAILABLE' : gmdate('D j M Y · H:i', $ts) . ' UTC';
};
// The pager moves through the matches that are already stored. It is rendered
// above and below the list from one function so the two cannot drift, and every
// link carries the date — a page number without a date is a different request.
$pager = static function (array $pagination, string $date, array $carry = []): string {
    $total = (int) ($pagination['totalMatches'] ?? 0);
    if ($total === 0) return '';
    $page = (int) ($pagination['page'] ?? 1);
    $pages = (int) ($pagination['totalPages'] ?? 1);
    $size = (int) ($pagination['pageSize'] ?? 50);
    // Every link carries the competition and market the page is narrowed to: a
    // page number alone would silently drop the selection the operator made.
    $href = static function (int $target) use ($date, $carry): string {
        $query = array_merge(['date' => $date, 'page' => $target], $carry);
        return '/football?' . http_build_query($query);
    };
    $previous = !empty($pagination['hasPrevious'])
        ? '<a class="btn small" href="' . e($href((int) $pagination['previousPage'])) . '">&larr; Previous</a>'
        : '<span class="btn small" aria-disabled="true" style="opacity:.45;cursor:default">&larr; Previous</span>';
    $next = !empty($pagination['hasNext'])
        ? '<a class="btn small" href="' . e($href((int) $pagination['nextPage'])) . '">Next &rarr;</a>'
        : '<span class="btn small" aria-disabled="true" style="opacity:.45;cursor:default">Next &rarr;</span>';
    return '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">'
        . '<div>' . $previous . '</div>'
        . '<div style="text-align:center">'
        . '<div style="font-weight:700">Page ' . $page . ' of ' . $pages . '</div>'
        . '<div class="dim" style="font-size:11px">' . $size . ' matches per page &middot; showing '
        . (int) ($pagination['from'] ?? 0) . '&ndash;' . (int) ($pagination['to'] ?? 0) . ' of ' . $total . '</div>'
        . '</div>'
        . '<div>' . $next . '</div>'
        . '</div>';
};
?>
<div class="page-head">
  <div>
    <h2>TODAY'S FOOTBALL PREDICTIONS</h2>
    <p>
      <?= e((string) ($board['dateLabel'] ?? $date ?? gmdate('Y-m-d'))) ?> · fixtures, probabilities and scores reported from the connected football data provider only.
      A match is shown as a prediction only when its data quality clears the threshold — otherwise its state is reported instead.
    </p>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:10px">
      <a class="btn small" href="/football?date=<?= e($yesterday ?? gmdate('Y-m-d', time() - 86400)) ?>">← Previous day</a>
      <a class="btn small" href="/football">Today</a>
      <a class="btn small" href="/football?date=<?= e($tomorrow ?? gmdate('Y-m-d', time() + 86400)) ?>">Next day →</a>
      <form method="post" action="/football/sync" style="display:inline" onsubmit="return confirm('Pull fixtures for this date from the connected provider now? The provider\'s own rate limits and daily quota are respected.')">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <input type="hidden" name="date" value="<?= e((string) ($date ?? gmdate('Y-m-d'))) ?>">
        <?php if (!empty($caps['sync'])): ?>
          <button class="btn small primary">Sync this date</button>
        <?php else: ?>
          <button class="btn small" disabled title="Requires the sports.manage permission">Sync this date</button>
        <?php endif; ?>
      </form>
      <form method="post" action="/football/predict" style="display:inline" onsubmit="return confirm('Generate predictions for the matches on this page that do not have one yet? At most 50 new predictions are created, and matches that already have one are reused, not regenerated.')">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <input type="hidden" name="date" value="<?= e((string) ($date ?? gmdate('Y-m-d'))) ?>">
        <input type="hidden" name="page" value="<?= (int) ($page ?? 1) ?>">
        <input type="hidden" name="competition" value="<?= e((string) ($carry['competition'] ?? '')) ?>">
        <input type="hidden" name="market" value="<?= e((string) ($carry['market'] ?? '')) ?>">
        <?php if (!empty($caps['sync'])): ?>
          <button class="btn small">Generate this page (max 50)</button>
        <?php else: ?>
          <button class="btn small" disabled title="Requires the sports.manage permission">Generate this page (max 50)</button>
        <?php endif; ?>
      </form>
      <a class="btn small" href="/football/live">Live view</a>
      <a class="btn small" href="/football/models">Models &amp; calibration</a>
      <a class="btn small" href="/sports" style="background:var(--violet,#6d28d9);color:#fff;border-color:var(--violet,#6d28d9);font-weight:700">🎯 Odds Prediction Ticket →</a>
    </div>
  </div>
</div>
<?php if (!empty($notice)): ?><div class="notice ok"><?= e($notice) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="notice err"><?= e($error) ?></div><?php endif; ?>

<?php if (!empty($diag['demoMode'])): ?>
  <div class="notice warnbox"><b>DEMO / SANDBOX DATA</b> — the football rows in this deployment come from a simulated source. They are labelled and never mixed into real performance figures.</div>
<?php endif; ?>
<?php if (!empty($diag['message'])): ?>
  <div class="notice warnbox"><b>Football data provider not connected.</b> Live fixtures and predictions are unavailable until a verified data source is configured. Nothing below is invented to fill the gap.</div>
<?php endif; ?>

<div class="grid cols-main">
  <div class="stack">
    <!-- ── the board (§10) ─────────────────────────────────────────────── -->
    <div class="panel">
      <h3>Today's football predictions — <?= e((string) ($board['date'] ?? '')) ?></h3>
      <div class="body" style="padding-top:12px">
        <div class="stat-grid">
          <div class="stat"><div class="k">Fixtures found</div><div class="v"><?= (int) ($summary['fixtures'] ?? 0) ?></div></div>
          <div class="stat"><div class="k">Analyzed</div><div class="v"><?= (int) ($summary['analyzed'] ?? 0) ?></div></div>
          <div class="stat"><div class="k">Qualified</div><div class="v up"><?= (int) ($summary['qualified'] ?? 0) ?></div></div>
          <div class="stat"><div class="k">Limited data</div><div class="v"><?= (int) ($summary['limited'] ?? 0) ?></div></div>
          <div class="stat"><div class="k">Rejected</div><div class="v down"><?= (int) ($summary['rejected'] ?? 0) ?></div></div>
        </div>
        <p class="dim" style="font-size:11px;margin-top:8px">
          Data-quality thresholds: qualified ≥ <?= (int) ($board['thresholds']['dataQualityQualified'] ?? 70) ?>/100, limited <?= (int) ($board['thresholds']['dataQualityLimited'] ?? 50) ?>–<?= (int) ($board['thresholds']['dataQualityQualified'] ?? 70) - 1 ?>, rejected below <?= (int) ($board['thresholds']['dataQualityLimited'] ?? 50) ?>.
          Model in use: <b><?= e((string) ($board['model']['label'] ?? 'none')) ?></b><?= !empty($board['model']['version']) ? ' · ' . e((string) $board['model']['version']) : '' ?>.
          Generated <?= e($kickoffLabel($d['generatedAt'] ?? null)) ?>.
        </p>
        <?php if (!empty($board['model']['note'])): ?>
          <div class="notice warnbox" style="margin-top:10px"><b><?= e((string) ($board['model']['state'] ?? 'MODEL')) ?></b> — <?= e((string) $board['model']['note']) ?></div>
        <?php endif; ?>
        <?php if (in_array((string) ($board['state'] ?? ''), ['NO_FIXTURES_STORED', 'NO_PREDICTIONS_STORED', 'PAGE_BEYOND_LAST'], true)): ?>
          <p class="dim" style="margin-top:12px"><?= e((string) ($board['message'] ?? '')) ?></p>
        <?php endif; ?>

        <?php
        $pagination = is_array($board['pagination'] ?? null) ? $board['pagination'] : [];
        $dateParam = (string) ($date ?? gmdate('Y-m-d'));
        ?>
        <?php if ($pagination !== []): ?>
          <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--line)">
            <?= $pager($pagination, $dateParam, $carry) ?>
            <p class="dim" style="font-size:11px;margin:8px 0 0">
              <?= (int) ($summary['analyzed'] ?? 0) ?> of <?= (int) ($summary['fixtures'] ?? 0) ?> matches on this date have a stored prediction ·
              <?= (int) ($pagination['awaiting'] ?? 0) ?> of the <?= (int) ($pagination['returned'] ?? 0) ?> on this page are still unanalyzed.
              Paging reads stored rows: it never regenerates a prediction, and generating a page creates at most <?= (int) ($pagination['maxLimit'] ?? 50) ?> new ones.
            </p>
          </div>
        <?php endif; ?>

        <!-- ── the selection (competition → premium league → market) ───────── -->
        <div style="margin-top:14px;padding:12px;border:1px solid var(--line);border-radius:10px;background:rgba(127,127,127,.04)">
          <form method="get" action="/football" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
            <input type="hidden" name="page" value="1">
            <div>
              <label class="dim" style="font-size:11px;display:block">Select Competition</label>
              <select name="competition" style="min-width:210px">
                <option value=""<?= $selectedExternal === '' ? ' selected' : '' ?>>All competitions (<?= (int) ($summary['fixtures'] ?? 0) ?> matches)</option>
                <?php foreach ($competitions as $competition): ?>
                  <?php $external = (string) ($competition['externalId'] ?? ''); ?>
                  <option value="<?= e($external) ?>"<?= $selectedExternal === $external ? ' selected' : '' ?>>
                    <?= e((string) ($competition['name'] ?? 'Unnamed competition')) ?><?= !empty($competition['country']) ? ' · ' . e((string) $competition['country']) : '' ?> (<?= (int) ($competition['matches'] ?? 0) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="dim" style="font-size:11px;display:block">Premium League</label>
              <select name="premium" style="min-width:200px" onchange="if(this.value!==''){this.form.competition.value=this.value;this.form.submit();}">
                <option value=""><?= $premium === null ? 'No premium competition stored' : '— featured —' ?></option>
                <?php if ($premium !== null): ?>
                  <option value="<?= e((string) ($premium['externalId'] ?? '')) ?>"<?= $selectedExternal === (string) ($premium['externalId'] ?? '') ? ' selected' : '' ?>>
                    <?= e((string) ($premium['name'] ?? 'Premium League')) ?> (<?= (int) ($premium['matches'] ?? 0) ?> matches)
                  </option>
                <?php endif; ?>
              </select>
            </div>
            <div>
              <label class="dim" style="font-size:11px;display:block">Select Odds Prediction</label>
              <select name="market" style="min-width:230px">
                <?php foreach ($marketList as $entry): ?>
                  <?php $key = (string) ($entry['key'] ?? ''); ?>
                  <option value="<?= e($key) ?>"<?= $selectedMarket === $key ? ' selected' : '' ?>>
                    <?= e((string) ($entry['label'] ?? $key)) ?><?= empty($entry['oddsAvailable']) && (string) ($entry['derivation'] ?? '') === 'NOT_MODELLED' ? ' — no stored data' : '' ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="dim" style="font-size:11px;display:block">Date</label>
              <input type="date" name="date" value="<?= e($dateParam) ?>">
            </div>
            <button class="btn small primary">Apply</button>
          </form>
          <p class="dim" style="font-size:11px;margin:8px 0 0">
            Competitions are listed from the provider feed — no league is offered that has no stored match.
            The market is a view over the predictions already stored: changing it never regenerates a match.
          </p>
        </div>

        <!-- ── the page as a market table ─────────────────────────────────── -->
        <?php $rows = is_array($board['rows'] ?? null) ? $board['rows'] : []; ?>
        <?php if ($rows !== []): ?>
          <div style="margin-top:16px;overflow-x:auto">
            <h4 style="margin:0 0 8px"><?= e((string) ($marketBlock['label'] ?? 'Market')) ?>
              <span class="dim" style="font-weight:400;font-size:11px">(<?= count($rows) ?> match<?= count($rows) === 1 ? '' : 'es' ?> on this page)</span>
            </h4>
            <table style="width:100%;border-collapse:collapse;font-size:12px">
              <thead>
                <tr style="text-align:left;border-bottom:1px solid var(--line)">
                  <th style="padding:6px">Match</th>
                  <th style="padding:6px">Competition</th>
                  <th style="padding:6px">Kickoff</th>
                  <th style="padding:6px">Prediction</th>
                  <th style="padding:6px">Odds</th>
                  <th style="padding:6px">Confidence</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $row): ?>
                  <?php
                  $m = is_array($row['market'] ?? null) ? $row['market'] : [];
                  $odds = $m['odds'] ?? null;
                  $implied = $m['impliedProbability'] ?? null;
                  $edge = $m['edge'] ?? null;
                  ?>
                  <tr style="border-bottom:1px solid var(--line)">
                    <td style="padding:6px">
                      <a href="/football/match/<?= (int) ($row['fixtureId'] ?? 0) ?>" style="font-weight:600"><?= e((string) ($row['homeTeam'] ?? '—')) ?> vs <?= e((string) ($row['awayTeam'] ?? '—')) ?></a>
                      <div class="dim" style="font-size:10px"><?= e((string) ($row['matchId'] ?? '')) ?></div>
                    </td>
                    <td style="padding:6px"><?= e((string) ($row['competition'] ?? '—')) ?></td>
                    <td style="padding:6px" class="mono"><?= e((string) ($row['kickoffLabel'] ?? '—')) ?></td>
                    <td style="padding:6px">
                      <?php if (($row['analysisState'] ?? '') !== 'ANALYZED'): ?>
                        <span class="dim">Not analyzed</span>
                      <?php else: ?>
                        <b><?= e((string) ($m['selectionLabel'] ?? '—')) ?></b>
                        <div class="dim" style="font-size:10px"><?= e((string) ($row['resultLabel'] ?? '—')) ?><?= !empty($row['prediction']['predictedScore']['label']) ? ' · ' . e((string) $row['prediction']['predictedScore']['label']) : '' ?></div>
                      <?php endif; ?>
                    </td>
                    <td style="padding:6px" class="mono">
                      <?php if ($odds === null): ?>
                        <span class="dim" title="The connected odds provider has quoted no price for this selection.">DATA_UNAVAILABLE</span>
                      <?php else: ?>
                        <?= number_format((float) $odds, 2) ?>
                        <div class="dim" style="font-size:10px">implied <?= $implied === null ? '—' : number_format((float) $implied * 100, 1) . '%' ?></div>
                      <?php endif; ?>
                    </td>
                    <td style="padding:6px" class="mono">
                      <?= is_numeric($row['confidence'] ?? null) ? number_format((float) $row['confidence'], 1) . '%' : '—' ?>
                      <div class="dim" style="font-size:10px">DQ <?= (int) ($row['dataQuality'] ?? 0) ?>/100 · risk <?= e(strtolower((string) ($row['risk']['level'] ?? 'unknown'))) ?></div>
                    </td>
                  </tr>
                  <?php if (($row['analysisState'] ?? '') === 'ANALYZED'): ?>
                    <tr style="border-bottom:1px solid var(--line)">
                      <td colspan="6" style="padding:0 6px 8px">
                        <details>
                          <summary class="dim" style="font-size:11px;cursor:pointer">Model detail, price and risk</summary>
                          <div style="display:flex;gap:18px;flex-wrap:wrap;margin-top:6px;font-size:11px">
                            <div>
                              <div class="dim">Market outcomes</div>
                              <?php foreach ((array) ($m['outcomes'] ?? []) as $outcome): ?>
                                <div><?= e((string) ($outcome['label'] ?? '')) ?> —
                                  <span class="mono"><?= is_numeric($outcome['probability'] ?? null) ? number_format((float) $outcome['probability'] * 100, 1) . '%' : '—' ?></span>
                                  <?php if (($outcome['oddsState'] ?? '') === 'AVAILABLE'): ?>
                                    · <span class="mono"><?= number_format((float) $outcome['odds'], 2) ?></span>
                                    <span class="dim">(edge <?= is_numeric($outcome['edge'] ?? null) ? number_format((float) $outcome['edge'] * 100, 1) : '—' ?>pp)</span>
                                  <?php else: ?>
                                    <span class="dim">· no price quoted</span>
                                  <?php endif; ?>
                                </div>
                              <?php endforeach; ?>
                            </div>
                            <div>
                              <div class="dim">Risk</div>
                              <div><?= e((string) ($row['risk']['level'] ?? 'UNKNOWN')) ?></div>
                              <div class="dim"><?= e((string) ($row['risk']['basis'] ?? '')) ?></div>
                            </div>
                            <div>
                              <div class="dim">Prediction</div>
                              <div><?= e((string) ($row['prediction']['predictionDate'] ?? '—')) ?> · <?= e((string) ($board['model']['version'] ?? '—')) ?></div>
                              <div class="dim"><?= e((string) ($row['prediction']['generatedAt'] ?? '')) ?></div>
                            </div>
                            <div>
                              <div class="dim">Source</div>
                              <div><?= e((string) ($m['source'] ?? '—')) ?></div>
                              <div class="dim"><?= e((string) ($m['basis'] ?? '')) ?></div>
                            </div>
                          </div>
                        </details>
                      </td>
                    </tr>
                  <?php endif; ?>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

        <?php foreach ($board['categories'] ?? [] as $category): ?>
          <?php $items = $category['items'] ?? []; ?>
          <div style="margin-top:16px">
            <h4 style="margin:0 0 8px"><?= e((string) ($category['label'] ?? '')) ?>
              <span class="dim" style="font-weight:400;font-size:11px">(confidence <?= e((string) ($category['range'] ?? '')) ?> · <?= count($items) ?> fixture<?= count($items) === 1 ? '' : 's' ?>)</span>
            </h4>
            <?php if ($items === []): ?>
              <p class="dim" style="font-size:12px;margin:0">No fixtures fall into this category. An empty category is a valid outcome — nothing is promoted into it.</p>
            <?php else: ?>
              <div class="stack" style="gap:10px">
                <?php foreach ($items as $card): ?>
                  <?php
                  $prob = $card['probabilities'] ?? ['home' => null, 'draw' => null, 'away' => null];
                  $probTotal = array_sum(array_map(static fn($v) => is_numeric($v) ? (float) $v * 100 : 0, $prob));
                  ?>
                  <div class="panel" style="box-shadow:none;border:1px solid var(--line)">
                    <div class="body" style="padding:12px">
                      <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:baseline">
                        <div>
                          <a href="/football/match/<?= (int) ($card['fixtureId'] ?? 0) ?>" style="font-weight:700;font-size:15px"><?= e((string) ($card['predictedResultLabel'] ?? 'No prediction')) ?></a>
                          <div class="dim" style="font-size:11px">
                            <?= e((string) ($card['competition'] ?? '—')) ?><?= !empty($card['country']) ? ' · ' . e((string) $card['country']) : '' ?> ·
                            <?= e((string) ($card['kickoff'] ? gmdate('M j, H:i', (int) strtotime((string) $card['kickoff'])) : '—')) ?> UTC ·
                            <?= e((string) ($card['status'] ?? 'UNKNOWN')) ?><?= $card['minute'] !== null ? ' · ' . (int) $card['minute'] . "'" : '' ?>
                          </div>
                        </div>
                        <div style="text-align:right">
                          <div class="mono" style="font-size:15px;font-weight:700"><?= e((string) ($card['predictedScore']['label'] ?? '—')) ?></div>
                          <div class="dim" style="font-size:11px">predicted score</div>
                        </div>
                      </div>
                      <div style="display:flex;gap:10px;margin-top:8px;flex-wrap:wrap;font-size:12px">
                        <span><?= e((string) ($card['homeTeam'] ?? '—')) ?></span>
                        <span class="dim">vs</span>
                        <span><?= e((string) ($card['awayTeam'] ?? '—')) ?></span>
                        <?php if (!empty($card['score'])): ?><span class="badge b-violet">live <?= (int) $card['score']['home'] ?>–<?= (int) $card['score']['away'] ?></span><?php endif; ?>
                        <?php if (!empty($card['highConfidence'])): ?><span class="badge b-green"><?= e((string) $card['highConfidence']) ?></span><?php endif; ?>
                        <span class="badge <?= $bandClass((string) ($card['band'] ?? '')) ?>"><?= e((string) ($card['band'] ?? 'REJECTED')) ?> · <?= (int) ($card['dataQuality']['score'] ?? 0) ?>/100</span>
                      </div>
                      <div style="margin-top:10px">
                        <div class="meter">
                          <div class="row"><span class="dim">Home / Draw / Away</span><span class="mono dim"><?= $probTotal > 0 ? number_format($probTotal, 1) . '% covered' : '—' ?></span></div>
                          <div class="bar" style="display:flex;overflow:hidden">
                            <?php foreach (['home' => 'var(--violet)', 'draw' => 'var(--muted)', 'away' => 'var(--green)'] as $side => $colour): ?>
                              <?php $width = is_numeric($prob[$side] ?? null) ? round((float) $prob[$side] * 100, 1) : 0; ?>
                              <div style="width:<?= $width ?>%;background:<?= $colour ?>" title="<?= e($side) ?> <?= $width ?>%"></div>
                            <?php endforeach; ?>
                          </div>
                          <div class="row" style="margin-top:6px;font-size:12px">
                            <span>Home <b class="mono"><?= is_numeric($prob['home'] ?? null) ? number_format((float) $prob['home'] * 100, 1) . '%' : '—' ?></b></span>
                            <span>Draw <b class="mono"><?= is_numeric($prob['draw'] ?? null) ? number_format((float) $prob['draw'] * 100, 1) . '%' : '—' ?></b></span>
                            <span>Away <b class="mono"><?= is_numeric($prob['away'] ?? null) ? number_format((float) $prob['away'] * 100, 1) . '%' : '—' ?></b></span>
                          </div>
                        </div>
                      </div>
                      <div class="stat-grid" style="margin-top:10px">
                        <div class="stat"><div class="k">Confidence</div><div class="v" style="font-size:14px"><?= e((string) ($card['confidenceLabel'] ?? '—')) ?></div></div>
                        <div class="stat"><div class="k">Confidence basis</div><div class="v" style="font-size:12px"><?= e((string) ($card['confidenceBasis'] ?? 'RAW')) ?></div></div>
                        <div class="stat"><div class="k">Expected total goals</div><div class="v" style="font-size:14px"><?= $dash($card['expectedTotalGoals'] ?? null, 2) ?></div></div>
                        <div class="stat"><div class="k">Data quality</div><div class="v" style="font-size:14px"><?= (int) ($card['dataQuality']['score'] ?? 0) ?>/100</div></div>
                      </div>
                      <div class="table-scroll">
                        <table class="tbl" style="margin-top:8px">
                          <tbody>
                            <?php foreach (['home' => 'Home form', 'away' => 'Away form'] as $side => $label): ?>
                              <?php $form = $card['form'][$side] ?? null; $trend = $card['goalTrend'][$side] ?? []; ?>
                              <tr>
                                <td class="dim" style="width:120px"><?= e($label) ?></td>
                                <td>
                                  <?php if (empty($form) || ($form['state'] ?? '') === 'DATA_UNAVAILABLE'): ?>
                                    <span class="dim">recent form unavailable</span>
                                  <?php else: ?>
                                    <span class="mono"><?= e((string) ($form['string'] ?? '—')) ?></span>
                                    <span class="dim">(<?= (int) ($form['played'] ?? 0) ?> played, <?= (int) ($form['points'] ?? 0) ?> pts, <?= (int) ($form['goalsFor'] ?? 0) ?> scored / <?= (int) ($form['goalsAgainst'] ?? 0) ?> conceded)</span>
                                    <span class="dim">· attack <?= $dash($trend['scored'] ?? null, 2) ?> · defence <?= $dash($trend['conceded'] ?? null, 2) ?> · clean sheets <?= $percent($trend['cleanSheetRate'] ?? null, 0) ?></span>
                                  <?php endif; ?>
                                </td>
                              </tr>
                            <?php endforeach; ?>
                            <tr>
                              <td class="dim">Head to head</td>
                              <td>
                                <?php if (empty($card['headToHead']) || ($card['headToHead']['state'] ?? '') === 'DATA_UNAVAILABLE'): ?>
                                  <span class="dim">no head-to-head history stored</span>
                                <?php else: ?>
                                  <?= e((string) ($card['headToHead']['summary'] ?? '—')) ?>
                                  <span class="dim">(weight <?= $dash($card['headToHead']['weight'] ?? null, 2) ?>)</span>
                                <?php endif; ?>
                              </td>
                            </tr>
                            <?php if (!empty($card['alternativeScores'])): ?>
                              <tr>
                                <td class="dim">Alternative scores</td>
                                <td class="mono">
                                  <?php foreach (array_slice((array) $card['alternativeScores'], 0, 3) as $alt): ?>
                                    <?= e((string) ($alt['home'] ?? '?')) ?>–<?= e((string) ($alt['away'] ?? '?')) ?><?= isset($alt['probability']) ? ' (' . number_format((float) $alt['probability'] * 100, 1) . '%)' : '' ?>&nbsp;&nbsp;
                                  <?php endforeach; ?>
                                </td>
                              </tr>
                            <?php endif; ?>
                            <tr>
                              <td class="dim">Model</td>
                              <td class="mono dim">
                                <?= e((string) ($card['model']['version'] ?? '—')) ?> · <?= e((string) ($card['model']['status'] ?? 'DRAFT')) ?> ·
                                calibration <?= e((string) ($card['model']['calibrationState'] ?? 'CALIBRATION_PENDING')) ?><?= !empty($card['model']['calibrationVersion']) ? ' (' . e((string) $card['model']['calibrationVersion']) . ')' : '' ?>
                              </td>
                            </tr>
                            <tr>
                              <td class="dim">Reasoning</td>
                              <td><?= e((string) ($card['reason'] ?? '—')) ?></td>
                            </tr>
                            <tr>
                              <td class="dim">Predicted at</td>
                              <td class="mono dim"><?= e($kickoffLabel($card['generatedAt'] ?? null)) ?> · settlement <?= e((string) ($card['settlementState'] ?? 'OPEN')) ?></td>
                            </tr>
                          </tbody>
                        </table>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>

        <?php if (empty($board['categories']) || (int) ($summary['qualified'] ?? 0) === 0): ?>
          <div class="notice warnbox" style="margin-top:14px">
            <b>No fixtures currently satisfy the required prediction and data-quality thresholds.</b>
            <?= $board['message'] !== null && (string) ($board['state'] ?? '') === 'NONE_QUALIFIED' ? '' : 'Fixtures below the threshold stay listed as limited data or rejected instead of being promoted into a prediction tier.' ?>
          </div>
        <?php endif; ?>

        <?php if ($pagination !== []): ?>
          <div style="margin-top:16px;padding-top:12px;border-top:1px solid var(--line)">
            <?= $pager($pagination, $dateParam, $carry) ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── live matches (§12) ──────────────────────────────────────────── -->
    <div class="panel">
      <h3>Live now</h3>
      <div class="body" style="padding-top:12px">
        <?php if (empty($live['matches'])): ?>
          <?php $liveState = (string) ($live['state'] ?? 'NO_LIVE_FIXTURES'); ?>
          <p class="dim"><?= $liveState === 'NO_LIVE_FIXTURES'
              ? 'No match is in play in the stored data. The live sweep runs only while a fixture is reported live, so no provider request is wasted overnight.'
              : e($liveState === 'DATA_UNAVAILABLE' ? 'Live state unavailable: the provider did not report any in-play fixture.' : $liveState) ?></p>
        <?php else: ?>
          <?php foreach ($live['matches'] as $match): $fx = $match['fixture'] ?? []; $lv = $match['live'] ?? []; $estimate = $match['liveModelEstimate'] ?? []; ?>
            <div class="panel" style="box-shadow:none;border:1px solid var(--line);margin-bottom:10px">
              <div class="body" style="padding:12px">
                <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap">
                  <div>
                    <b><?= e((string) ($fx['homeTeam'] ?? '—')) ?></b>
                    <span class="dim">vs</span>
                    <b><?= e((string) ($fx['awayTeam'] ?? '—')) ?></b>
                    <div class="dim" style="font-size:11px"><?= e((string) ($fx['competition'] ?? '—')) ?> · <?= e((string) ($lv['state'] ?? 'UNKNOWN')) ?><?= isset($lv['minute']) && $lv['minute'] !== null ? ' · ' . (int) $lv['minute'] . "'" : '' ?></div>
                    <div class="dim mono" style="font-size:11px">Kickoff <?= e($kickoffStamp($fx['kickoff'] ?? null)) ?></div>
                  </div>
                  <div style="text-align:right">
                    <div class="mono" style="font-size:18px;font-weight:700">
                      <?php if (isset($lv['score']['home'], $lv['score']['away']) && is_numeric($lv['score']['home']) && is_numeric($lv['score']['away'])): ?>
                        <?= (int) $lv['score']['home'] ?>–<?= (int) $lv['score']['away'] ?>
                      <?php else: ?>
                        <span class="dim">DATA_UNAVAILABLE</span>
                      <?php endif; ?>
                    </div>
                    <div class="dim" style="font-size:11px">
                      red cards
                      <?= isset($lv['redCards']['home']) && is_numeric($lv['redCards']['home']) ? (int) $lv['redCards']['home'] : '—' ?>/<?= isset($lv['redCards']['away']) && is_numeric($lv['redCards']['away']) ? (int) $lv['redCards']['away'] : '—' ?>
                    </div>
                  </div>
                </div>
                <div class="table-scroll">
                  <table class="tbl" style="margin-top:8px">
                    <tbody>
                      <tr>
                        <td class="dim" style="width:150px">Pre-match prediction</td>
                        <td>
                          <?php if (empty($match['preMatchPrediction'])): ?>
                            <span class="dim"><?= e((string) ($match['preMatchPredictionState'] ?? 'NOT_STORED')) ?> — no pre-match prediction is stored for this fixture. It is never written after kickoff.</span>
                          <?php else: ?>
                            <?php $pm = $match['preMatchPrediction']; ?>
                            <span class="mono"><?= e((string) ($pm['prediction']['result'] ?? '—')) ?> <?= (int) ($pm['prediction']['predictedScore']['home'] ?? 0) ?>–<?= (int) ($pm['prediction']['predictedScore']['away'] ?? 0) ?></span>
                            <span class="dim">· <?= $dash($pm['prediction']['confidence'] ?? null, 1) ?>% (<?= e((string) ($pm['prediction']['confidenceBasis'] ?? 'RAW')) ?>) · stored <?= e($kickoffLabel($pm['generatedAt'] ?? null)) ?></span>
                          <?php endif; ?>
                        </td>
                      </tr>
                      <tr>
                        <td class="dim">Live model estimate</td>
                        <td>
                          <?php if (($estimate['state'] ?? '') === 'ESTIMATE'): ?>
                            <span class="mono"><?= e((string) ($estimate['resultLabel'] ?? '—')) ?></span>
                            <span class="dim">· <?= $dash($estimate['confidence'] ?? null, 1) ?>% (<?= e((string) ($estimate['confidenceBasis'] ?? 'RAW')) ?>) · most likely <?= (int) ($estimate['mostLikelyScore']['home'] ?? 0) ?>–<?= (int) ($estimate['mostLikelyScore']['away'] ?? 0) ?></span>
                          <?php else: ?>
                            <span class="dim"><?= e((string) ($estimate['state'] ?? 'NO_ESTIMATE')) ?> — <?= e((string) ($estimate['reason'] ?? 'no live estimate is stored')) ?></span>
                          <?php endif; ?>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
                <p class="dim" style="font-size:11px;margin:6px 0 0">The pre-match prediction and the live estimate are separate stored rows: a kickoff never rewrites the original prediction.</p>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
        <?php if (!empty($live['errors'])): ?>
          <p class="dim" style="font-size:11px">Live refresh: <?= e(implode(' · ', array_slice((array) $live['errors'], 0, 3))) ?></p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="stack">
    <!-- ── data feed + refresh diagnostics (§13/§16) ───────────────────── -->
    <div class="panel">
      <h3>Data feed</h3>
      <div class="body" style="padding-top:12px">
        <div class="table-scroll">
          <table class="tbl">
            <thead><tr><th>Check</th><th>State</th><th>Detail</th></tr></thead>
            <tbody>
              <?php foreach ($diag['checks'] ?? [] as $check): ?>
                <tr>
                  <td style="font-weight:700"><?= e((string) ($check['key'] ?? '')) ?></td>
                  <td><span class="dot <?= $stateClass((string) ($check['state'] ?? '')) ?>"></span> <?= e((string) ($check['value'] ?? '—')) ?></td>
                  <td class="dim" style="font-size:11px"><?= e((string) ($check['detail'] ?? '')) ?><?= !empty($check['action']) ? '<br><i>' . e((string) $check['action']) . '</i>' : '' ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php if (empty($diag['checks'])): ?>
          <p class="dim">Diagnostics unavailable: the module could not read its own state.</p>
        <?php endif; ?>
        <?php if (!empty($diag['blockers'])): ?>
          <p style="font-size:12px;margin-top:10px"><b>Blockers:</b> <span class="mono"><?= e(implode(', ', (array) $diag['blockers'])) ?></span></p>
        <?php endif; ?>
        <?php if (!empty($diag['warnings'])): ?>
          <p class="dim" style="font-size:12px;margin-top:6px"><b>Warnings:</b> <span class="mono"><?= e(implode(', ', (array) $diag['warnings'])) ?></span> — a warning never blocks a prediction by itself.</p>
        <?php endif; ?>
        <?php if (!empty($caps['settle'])): ?>
          <form method="post" action="/football/settle" style="margin-top:10px">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
            <button class="btn small">Run settlement sweep</button>
          </form>
        <?php else: ?>
          <button class="btn small" disabled title="Requires the sports.settle permission" style="margin-top:10px">Run settlement sweep (needs sports.settle)</button>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── 30-day performance, once (§15) ──────────────────────────────── -->
    <div class="panel">
      <h3>30-day performance (settled predictions)</h3>
      <div class="body" style="padding-top:12px">
        <div class="stat-grid">
          <div class="stat"><div class="k">Predictions evaluated</div><div class="v"><?= (int) ($perf['evaluatedPredictions'] ?? 0) ?></div></div>
          <div class="stat"><div class="k">Correct results</div><div class="v"><?= (int) ($perf['correctResults'] ?? 0) ?></div></div>
          <div class="stat"><div class="k">Result accuracy</div><div class="v"><?= $percent($perf['resultAccuracy'] ?? null) ?></div></div>
          <div class="stat"><div class="k">Correct exact scores</div><div class="v"><?= (int) ($perf['correctScores'] ?? 0) ?></div></div>
          <div class="stat"><div class="k">Correct-score accuracy</div><div class="v"><?= $percent($perf['exactScoreAccuracy'] ?? null, 2) ?></div></div>
          <div class="stat"><div class="k">Avg confidence</div><div class="v"><?= $dash($perf['averageConfidence'] ?? null) ?>%</div></div>
          <div class="stat"><div class="k">Brier score</div><div class="v mono"><?= $dash($perf['brier'] ?? null, 4) ?></div></div>
          <div class="stat"><div class="k">Log loss</div><div class="v mono"><?= $dash($perf['logLoss'] ?? null, 4) ?></div></div>
          <div class="stat"><div class="k">ECE</div><div class="v mono"><?= $dash($perf['ece'] ?? null, 4) ?></div></div>
          <div class="stat"><div class="k">Avg data quality</div><div class="v"><?= $dash($perf['averageDataQuality'] ?? null) ?>/100</div></div>
          <div class="stat"><div class="k">Avg goal error</div><div class="v mono"><?= $dash($perf['averageGoalError'] ?? null, 2) ?></div></div>
          <div class="stat"><div class="k">Window</div><div class="v" style="font-size:12px"><?= (int) ($perf['windowDays'] ?? 30) ?> days</div></div>
        </div>
        <?php if (($perf['state'] ?? '') !== 'MEASURED'): ?>
          <p class="dim" style="margin-top:10px">No settled predictions yet. Historical performance metrics will appear after predicted matches have completed.</p>
        <?php endif; ?>
        <?php if (!empty($perf['note'])): ?>
          <p class="dim" style="font-size:11px;margin-top:6px"><?= e((string) $perf['note']) ?></p>
        <?php endif; ?>
        <?php if (!empty($perf['byModel'])): ?>
          <div class="table-scroll">
            <table class="tbl" style="margin-top:10px">
              <thead><tr><th>Model version</th><th class="num">Evaluated</th><th class="num">Result acc.</th><th class="num">Exact-score acc.</th><th class="num">Brier</th></tr></thead>
              <tbody>
                <?php foreach ($perf['byModel'] as $row): ?>
                  <tr>
                    <td class="mono"><?= e((string) ($row['modelVersion'] ?? '')) ?> <span class="dim"><?= e((string) ($row['status'] ?? '')) ?></span></td>
                    <td class="num"><?= (int) ($row['evaluated'] ?? 0) ?></td>
                    <td class="num"><?= $percent($row['resultAccuracy'] ?? null) ?></td>
                    <td class="num"><?= $percent($row['exactScoreAccuracy'] ?? null, 2) ?></td>
                    <td class="num mono"><?= $dash($row['brier'] ?? null, 4) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── model + calibration summary (details on /football/models) ───── -->
    <div class="panel">
      <h3>Model &amp; calibration</h3>
      <div class="body" style="padding-top:12px">
        <div class="table-scroll">
          <table class="tbl">
            <tbody>
              <tr><td class="dim" style="width:130px">State</td><td><span class="dot <?= $stateClass((string) ($models['state'] ?? '')) ?>"></span> <?= e((string) ($models['label'] ?? 'no model loaded')) ?></td></tr>
              <tr><td class="dim">Active version</td><td class="mono"><?= e((string) ($models['activeModel']['version'] ?? '—')) ?></td></tr>
              <tr><td class="dim">Calibration</td><td class="mono"><?= e((string) ($models['calibration']['calibrationVersion'] ?? ($models['calibration']['status'] ?? 'CALIBRATION_PENDING'))) ?> · <?= (int) ($models['calibration']['samples'] ?? 0) ?> samples</td></tr>
              <tr><td class="dim">Approved calibrations</td><td class="mono"><?= (int) ($models['approvedCalibrationCount'] ?? 0) ?></td></tr>
            </tbody>
          </table>
        </div>
        <?php if (!empty($models['reason'])): ?><p class="dim" style="font-size:11px;margin-top:8px"><?= e((string) $models['reason']) ?></p><?php endif; ?>
        <p style="margin-top:8px"><a class="btn small" href="/football/models">Models &amp; calibration — full state, history and approvals</a></p>
      </div>
    </div>

    <!-- ── refresh schedule (§13) ───────────────────────────────────────── -->
    <div class="panel">
      <h3>Refresh schedule</h3>
      <div class="body" style="padding-top:12px">
        <p class="dim" style="font-size:11px;margin-top:0">Cadence is provider-aware: each job runs only when its interval has elapsed, the provider is not in backoff, it was not asked to defer, and there is work waiting. Next wake: <span class="mono"><?= e($kickoffLabel($diag['cadence']['nextWakeAt'] ?? null)) ?></span></p>
        <div class="table-scroll">
          <table class="tbl">
            <thead><tr><th>Job</th><th class="num">Interval</th><th>Status</th><th class="num">Requests</th></tr></thead>
            <tbody>
              <?php foreach (($diag['cadence']['jobs'] ?? []) as $job): ?>
                <tr>
                  <td class="mono"><?= e(str_replace('football-', '', (string) ($job['job'] ?? ''))) ?></td>
                  <td class="num dim"><?= (int) ($job['interval'] ?? 0) ?>s</td>
                  <td>
                    <?php if (!empty($job['due'])): ?><span class="badge b-green">DUE</span>
                    <?php else: ?><span class="badge b-gray"><?= e((string) ($job['reason'] ?? 'DUE')) ?></span><?php endif; ?>
                    <?php if (!empty($job['nextRunAt'])): ?><div class="dim" style="font-size:10px">next <?= e(gmdate('H:i', (int) strtotime((string) $job['nextRunAt']))) ?> UTC</div><?php endif; ?>
                  </td>
                  <td class="num mono"><?= $job['requests'] === null ? '—' : (int) $job['requests'] ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
