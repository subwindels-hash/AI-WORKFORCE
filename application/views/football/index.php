<?php defined('BASEPATH') or exit('No direct script access allowed');
/**
 * Football Intelligence board.
 *
 * This page is intentionally arranged as one clear workflow:
 * date and actions → day overview → optional filters → ranked picks → every
 * fixture with its complete priced-market sheet → operational context. Numbers
 * are read from the stored board payload. A missing provider quote is named as
 * UNPRICED / DATA_UNAVAILABLE; it is never replaced with a model price.
 */
$d = $dashboard ?? [];
$board = $d['board'] ?? [];
$diagnostics = $d['diagnostics'] ?? [];
$diag = $diagnostics;
$perf = $d['performance'] ?? [];
// Categories remain part of the stored board contract. The unified odds board
// presents every fixture once rather than duplicating it into several sections.
$categories = $board['categories'] ?? [];
$live = $d['live'] ?? [];
$models = $d['models'] ?? [];
$caps = $caps ?? ['sync' => false, 'calibrate' => false, 'approve' => false, 'settle' => false];
$isAdmin = !empty($isAdmin);
$summary = $board['summary'] ?? ['fixtures' => 0, 'analyzed' => 0, 'qualified' => 0, 'limited' => 0, 'rejected' => 0];
$filters = is_array($board['filters'] ?? null) ? $board['filters'] : [];
$competitionBlock = is_array($filters['competitions'] ?? null) ? $filters['competitions'] : [];
$competitions = is_array($competitionBlock['competitions'] ?? null) ? $competitionBlock['competitions'] : [];
$premiumOptions = array_values(array_filter((array) ($competitionBlock['premiumCompetitions'] ?? []), static fn($entry): bool => is_array($entry) && (string) ($entry['externalId'] ?? '') !== ''));
$selectedCompetition = is_array($filters['competition'] ?? null) ? $filters['competition'] : [];
$selectedExternal = (string) ($selectedCompetition['externalId'] ?? '');
$premiumAllKeyword = \AIWorkforce\Football\MatchFeed::PREMIUM_LEAGUES;
$premiumAllScope = (string) ($selectedCompetition['state'] ?? '') === $premiumAllKeyword;
$marketBlock = is_array($board['market'] ?? null) ? $board['market'] : [];
$marketList = is_array($marketBlock['available'] ?? null) ? $marketBlock['available'] : [];
$selectedMarket = (string) ($marketBlock['key'] ?? 'MATCH_WINNER');
$marketRequested = trim((string) ($market ?? ''));
$providerOptions = is_array($providers['options'] ?? null) ? $providers['options'] : [];
$providerLocked = !empty($providerLocked) || !empty($providers['locked']);
$providerMode = (string) ($providerMode ?? ($providers['mode'] ?? 'AUTO'));
$providerRequestedRaw = trim((string) ($providerRequested ?? ''));
$selectedProvider = strtoupper(trim((string) ($provider ?? 'AUTO')));
$premium = is_array($competitionBlock['premium'] ?? null) ? $competitionBlock['premium'] : [];

$carry = [];
$competitionRequested = trim((string) ($selectedCompetition['requested'] ?? ''));
if ($competitionRequested !== '') $carry['competition'] = $competitionRequested;
if ($marketRequested !== '') $carry['market'] = $marketRequested;
if ($providerRequestedRaw !== '') $carry['provider'] = $providerRequestedRaw;

$dash = static fn(mixed $value, int $places = 1): string => is_numeric($value) ? number_format((float) $value, $places) : '—';
$pct = static fn(mixed $value, int $places = 1): string => is_numeric($value) ? number_format((float) $value * 100, $places) . '%' : '—';
$odds = static fn(mixed $value): string => is_numeric($value) ? number_format((float) $value, 2) : '—';
$signedPct = static fn(mixed $value): string => is_numeric($value) ? ((float) $value >= 0 ? '+' : '') . number_format((float) $value * 100, 1) . '%' : '—';
$bandClass = static fn(string $band): string => match (strtoupper($band)) {
    'QUALIFIED', 'AVAILABLE', 'FRESH', 'CALIBRATED', 'LOW' => 'b-green',
    'LIMITED', 'LIMITED_DATA', 'PARTIAL_MARKET', 'STALE', 'MEDIUM' => 'b-amber',
    'HIGH', 'REJECTED', 'DATA_UNAVAILABLE', 'UNPRICED' => 'b-red',
    default => 'b-gray',
};
$stateClass = static fn(string $state): string => match (strtoupper($state)) {
    'READY', 'CONNECTED', 'AVAILABLE', 'ACTIVE', 'MEASURED', 'CALIBRATED', 'ONLINE', 'POPULATED' => 'up',
    'DEGRADED', 'LIMITED_DATA', 'LIMITED', 'PENDING', 'CADENCE', 'DRAFT', 'TRAINED', 'VALIDATED', 'APPROVED' => 'synth',
    default => 'down',
};
$kickoff = static function (mixed $iso, string $format = 'D j M · H:i'): string {
    $stamp = is_string($iso) && trim($iso) !== '' ? strtotime($iso) : false;
    return $stamp === false ? 'DATA_UNAVAILABLE' : gmdate($format, $stamp) . ' UTC';
};
$shortTime = static function (mixed $iso): string {
    $stamp = is_string($iso) && trim($iso) !== '' ? strtotime($iso) : false;
    return $stamp === false ? '—' : gmdate('H:i', $stamp) . ' UTC';
};
// Used by live fixtures specifically: it prints the fixture's stored kickoff,
// not the time the page was rendered.
$kickoffStamp = static function (mixed $iso): string {
    $stamp = is_string($iso) && trim($iso) !== '' ? strtotime($iso) : false;
    return $stamp === false ? 'DATA_UNAVAILABLE' : gmdate('D j M Y · H:i', $stamp) . ' UTC';
};
$pager = static function (array $pagination, string $viewDate, array $carry): string {
    $total = (int) ($pagination['totalMatches'] ?? 0);
    if ($total === 0) return '';
    $page = (int) ($pagination['page'] ?? 1);
    $pages = (int) ($pagination['totalPages'] ?? 1);
    $href = static function (int $target) use ($viewDate, $carry): string {
        return '/football?' . http_build_query(array_merge(['date' => $viewDate, 'page' => $target], $carry));
    };
    $previous = !empty($pagination['hasPrevious'])
        ? '<a class="btn small" href="' . e($href((int) $pagination['previousPage'])) . '">&larr; Previous</a>'
        : '<span class="btn small" aria-disabled="true" style="opacity:.45">&larr; Previous</span>';
    $next = !empty($pagination['hasNext'])
        ? '<a class="btn small" href="' . e($href((int) $pagination['nextPage'])) . '">Next &rarr;</a>'
        : '<span class="btn small" aria-disabled="true" style="opacity:.45">Next &rarr;</span>';
    return '<nav class="football-pager" aria-label="Match pages">'
        . '<div>' . $previous . '</div>'
        . '<div class="football-pager__status"><b>Page ' . $page . ' of ' . $pages . '</b><span>'
        . (int) ($pagination['from'] ?? 0) . '–' . (int) ($pagination['to'] ?? 0) . ' of ' . $total
        . ' matches · ' . (int) ($pagination['pageSize'] ?? 50) . ' matches per page</span></div>'
        . '<div>' . $next . '</div></nav>';
};
?>
<div class="football-console">
  <section class="football-hero" aria-labelledby="football-heading">
    <div>
      <p class="football-eyebrow">TODAY'S FOOTBALL PREDICTIONS</p>
      <h2 id="football-heading">Match predictions and odds, in one place</h2>
      <p class="football-hero__copy">
        <?= e((string) ($board['dateLabel'] ?? $date ?? gmdate('Y-m-d'))) ?>. Review each fixture’s model probability, verified bookmaker price, fair odds and potential edge. Prices are always separated from WINDELS estimates; no bet is placed from this screen.
      </p>
    </div>
    <div class="football-hero__actions" aria-label="Football date navigation">
      <a class="btn small" href="/football?date=<?= e($yesterday ?? gmdate('Y-m-d', time() - 86400)) ?>">← Previous day</a>
      <a class="btn small" href="/football">Today</a>
      <a class="btn small" href="/football?date=<?= e($tomorrow ?? gmdate('Y-m-d', time() + 86400)) ?>">Next day →</a>
    </div>
  </section>

  <section class="football-actionbar" aria-label="Football actions">
    <div class="football-actionbar__group">
      <form method="post" action="/football/sync" onsubmit="return confirm('Pull fixtures for this date from the connected provider now? Provider rate limits and daily quotas are respected.')">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <input type="hidden" name="date" value="<?= e((string) ($date ?? gmdate('Y-m-d'))) ?>">
        <button class="btn small primary" <?= empty($caps['sync']) ? 'disabled title="Requires the sports.manage permission"' : '' ?>>Sync this date</button>
      </form>
      <form method="post" action="/football/predict" onsubmit="return confirm('Generate predictions for unmatched fixtures on this page? At most 50 new predictions are created; stored predictions are reused.')">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <input type="hidden" name="date" value="<?= e((string) ($date ?? gmdate('Y-m-d'))) ?>">
        <input type="hidden" name="page" value="<?= (int) ($page ?? 1) ?>">
        <input type="hidden" name="competition" value="<?= e((string) ($carry['competition'] ?? '')) ?>">
        <input type="hidden" name="market" value="<?= e((string) ($carry['market'] ?? '')) ?>">
        <input type="hidden" name="provider" value="<?= e((string) ($carry['provider'] ?? '')) ?>">
        <button class="btn small" <?= empty($caps['sync']) ? 'disabled title="Requires the sports.manage permission"' : '' ?>>Generate this page (max 50)</button>
      </form>
    </div>
    <div class="football-actionbar__group">
      <a class="btn small" href="/football/live">Live view</a>
      <a class="btn small" href="/football/models">Models &amp; calibration</a>
      <a class="btn small football-ticket-link" href="/sports">🎯 Odds prediction tickets</a>
    </div>
  </section>

  <?php if (!empty($notice)): ?><div class="notice ok"><?= e($notice) ?></div><?php endif; ?>
  <?php if (!empty($error)): ?><div class="notice err"><?= e($error) ?></div><?php endif; ?>
  <?php if (!empty($diag['demoMode'])): ?><div class="notice warnbox"><b>DEMO / SANDBOX DATA</b> — these football rows are simulated and are never mixed into real-world performance figures.</div><?php endif; ?>
  <?php if (!empty($diag['message'])): ?><div class="notice warnbox"><b>Football data provider not connected.</b> Live fixtures and prices are unavailable until a verified source is configured. Nothing below is fabricated to fill the gap.</div><?php endif; ?>

  <div class="football-layout">
    <div class="football-main stack">
      <section class="panel football-section" aria-labelledby="day-overview-heading">
        <div class="football-section__heading">
          <div><p class="football-eyebrow">Day overview</p><h3 id="day-overview-heading"><?= e((string) ($board['date'] ?? $date ?? 'Fixtures')) ?></h3></div>
          <span class="dim football-updated">Board read <?= e($kickoff($d['generatedAt'] ?? null, 'H:i')) ?></span>
        </div>
        <div class="body">
          <div class="stat-grid football-stat-grid">
            <div class="stat"><div class="k">Fixtures found</div><div class="v"><?= (int) ($summary['fixtures'] ?? 0) ?></div><div class="trend">stored for this date</div></div>
            <div class="stat"><div class="k">Analyzed</div><div class="v"><?= (int) ($summary['analyzed'] ?? 0) ?></div><div class="trend">prediction rows saved</div></div>
            <div class="stat"><div class="k">Qualified</div><div class="v up"><?= (int) ($summary['qualified'] ?? 0) ?></div><div class="trend">verified data quality</div></div>
            <div class="stat"><div class="k">Limited evidence</div><div class="v warn"><?= (int) ($summary['limited'] ?? 0) ?></div><div class="trend">usable with caution</div></div>
            <div class="stat"><div class="k">Withheld</div><div class="v down"><?= (int) ($summary['rejected'] ?? 0) ?></div><div class="trend">below evidence floor</div></div>
          </div>
          <?php $pagination = is_array($board['pagination'] ?? null) ? $board['pagination'] : []; ?>
          <?php if ($pagination !== []): ?>
            <div class="football-section__divider"></div>
            <?= $pager($pagination, (string) ($date ?? gmdate('Y-m-d')), $carry) ?>
            <p class="football-help">This page contains <?= (int) ($pagination['returned'] ?? 0) ?> stored fixture<?= (int) ($pagination['returned'] ?? 0) === 1 ? '' : 's' ?>. Paging only reads saved rows; it never refreshes prices or regenerates a prediction.</p>
          <?php endif; ?>
          <?php if (in_array((string) ($board['state'] ?? ''), ['NO_FIXTURES_STORED', 'NO_PREDICTIONS_STORED', 'PAGE_BEYOND_LAST'], true)): ?>
            <div class="empty-state"><p><?= e((string) ($board['message'] ?? 'No stored fixtures are available for this selection.')) ?></p></div>
          <?php endif; ?>
        </div>
      </section>

      <?php if ($isAdmin): ?>
        <section class="panel football-section" aria-labelledby="football-filters-heading">
          <div class="football-section__heading">
            <div><p class="football-eyebrow">Board view</p><h3 id="football-filters-heading">Filter stored fixtures and markets</h3></div>
            <span class="badge <?= strtoupper($providerMode) === 'MANUAL' ? 'b-amber' : 'b-green' ?>"><?= e($providerMode) ?> · <?= strtoupper($providerMode) === 'MANUAL' ? 'operator selection' : 'admin managed' ?></span>
          </div>
          <div class="body">
            <form method="get" action="/football" class="football-filter-form">
              <input type="hidden" name="page" value="1">
              <label class="fld">Data provider
                <select class="sel" name="provider" <?= $providerLocked ? 'disabled' : '' ?> title="Provider choice controls synced data; reading this board only uses stored rows.">
                  <?php if ($providerOptions === []): ?><option value="">No feed connected</option><?php endif; ?>
                  <?php foreach ($providerOptions as $option): ?>
                    <?php $value = (string) ($option['value'] ?? ''); ?>
                    <option value="<?= e($value) ?>" <?= strtoupper($value) === $selectedProvider ? 'selected' : '' ?>><?= e((string) ($option['label'] ?? $value)) ?></option>
                  <?php endforeach; ?>
                </select>
                <?php if ($providerLocked): ?><input type="hidden" name="provider" value="AUTO"><?php endif; ?>
              </label>
              <label class="fld">Competition
                <select class="sel" name="competition">
                  <option value="">All competitions</option>
                  <option value="<?= e($premiumAllKeyword) ?>" <?= $premiumAllScope ? 'selected' : '' ?>>All premium leagues</option>
                  <?php foreach ($competitions as $entry): ?>
                    <?php $value = (string) ($entry['externalId'] ?? ''); ?>
                    <option value="<?= e($value) ?>" <?= !$premiumAllScope && $selectedExternal === $value ? 'selected' : '' ?>><?= e((string) ($entry['name'] ?? 'Competition')) ?> · <?= (int) ($entry['matches'] ?? 0) ?> matches</option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="fld">Premium league
                <select class="sel" name="premium" onchange="this.form.competition.value=this.value">
                  <option value="">No premium-only filter</option>
                  <?php foreach ($premiumOptions as $entry): ?>
                    <?php $value = (string) ($entry['externalId'] ?? ''); ?>
                    <option value="<?= e($value) ?>" <?= !$premiumAllScope && $selectedExternal === $value ? 'selected' : '' ?>><?= e((string) ($entry['name'] ?? 'Premium league')) ?><?= $value === (string) ($premium['externalId'] ?? '') ? ' · featured' : '' ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="fld">Odds market
                <select class="sel" name="market">
                  <option value="">All markets — match winner overview</option>
                  <?php foreach ($marketList as $entry): ?>
                    <?php $key = (string) ($entry['key'] ?? ''); ?>
                    <option value="<?= e($key) ?>" <?= $marketRequested !== '' && $selectedMarket === $key ? 'selected' : '' ?>><?= e((string) ($entry['label'] ?? $key)) ?><?= empty($entry['oddsAvailable']) && (string) ($entry['derivation'] ?? '') === 'NOT_MODELLED' ? ' · price not stored' : '' ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="fld">Date <input class="sel" type="date" name="date" value="<?= e((string) ($date ?? gmdate('Y-m-d'))) ?>"></label>
              <button class="btn primary" type="submit">Apply view</button>
            </form>
            <p class="football-help">Filters only reorganize saved fixtures and stored odds. They do not spend a provider request or create a new prediction. The complete odds sheet remains available for every match below.</p>
          </div>
        </section>
      <?php endif; ?>

      <?php $picksBlock = is_array($board['picks'] ?? null) ? $board['picks'] : []; $picks = is_array($picksBlock['picks'] ?? null) ? $picksBlock['picks'] : []; ?>
      <section class="panel football-section" aria-labelledby="top-picks-heading">
        <div class="football-section__heading">
          <div><p class="football-eyebrow">Ranked reading</p><h3 id="top-picks-heading">Top WINDELS Picks</h3></div>
          <span class="dim"><?= (int) ($picksBlock['eligible'] ?? 0) ?> eligible on this page</span>
        </div>
        <div class="body">
          <?php if ($picks === []): ?>
            <p class="football-help">No fixtures currently satisfy the required prediction and data-quality thresholds. This is a finding, not a gap filled with a forced selection.</p>
          <?php else: ?>
            <div class="table-scroll">
              <table class="tbl football-table">
                <thead><tr><th>#</th><th>Match &amp; pick</th><th class="num">WINDELS probability</th><th class="num">Market odds</th><th class="num">WINDELS fair odds</th><th class="num">Value</th><th class="num">Intelligence</th><th>Movement</th></tr></thead>
                <tbody>
                  <?php foreach ($picks as $pick): ?>
                    <?php $cardPageId = (int) ($pick['fixtureId'] ?? 0); $move = (string) ($pick['stabilityState'] ?? ''); ?>
                    <tr>
                      <td class="mono"><?= (int) ($pick['rank'] ?? 0) ?></td>
                      <td><?php if ($cardPageId > 0): ?><a href="/football/match/<?= $cardPageId ?>" class="football-match-link"><?php endif; ?><?= crest($pick['homeTeamLogo'] ?? null) ?><?= e((string) ($pick['homeTeam'] ?? '—')) ?> vs <?= crest($pick['awayTeamLogo'] ?? null) ?><?= e((string) ($pick['awayTeam'] ?? '—')) ?><?php if ($cardPageId > 0): ?></a><?php endif; ?><div class="dim football-cell-note"><?= e((string) ($pick['selectionLabel'] ?? '—')) ?> · <?= e((string) ($pick['kickoffLabel'] ?? '')) ?></div></td>
                      <td class="num mono"><?= $pct($pick['probability'] ?? null) ?></td>
                      <td class="num mono"><?= $odds($pick['odds'] ?? null) ?></td>
                      <td class="num mono"><?= $odds($pick['fairOdds'] ?? null) ?></td>
                      <td class="num mono <?= (float) ($pick['expectedValue'] ?? -1) >= 0 ? 'up' : 'down' ?>"><?= $signedPct($pick['expectedValue'] ?? null) ?></td>
                      <td class="num mono"><?= is_numeric($pick['score'] ?? null) ? (int) $pick['score'] . '/100' : '—' ?></td>
                      <td><?php if ($move === \AIWorkforce\Football\StabilityMonitor::UNSTABLE): ?><span class="badge b-red" title="This prediction moved materially between stored readings.">Prediction unstable — significant model movement</span><?php elseif ($move === \AIWorkforce\Football\StabilityMonitor::MOVED): ?><span class="badge b-amber">Moved</span><?php else: ?><span class="badge b-gray">Stable / first reading</span><?php endif; ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
          <p class="football-help"><?= e((string) ($picksBlock['disclaimer'] ?? 'Rankings are analytical comparisons, not a promise of a result.')) ?></p>
        </div>
      </section>

      <?php $rows = is_array($board['rows'] ?? null) ? $board['rows'] : []; ?>
      <section class="panel football-section" aria-labelledby="fixtures-heading">
        <div class="football-section__heading">
          <div><p class="football-eyebrow">Fixture odds board</p><h3 id="fixtures-heading">Every match and its available odds</h3></div>
          <span class="dim"><?= count($rows) ?> match<?= count($rows) === 1 ? '' : 'es' ?> on this page</span>
        </div>
        <div class="body">
          <p class="football-help">Open any fixture for its complete market sheet. Every modelled selection includes the WINDELS probability and fair odds; every real bookmaker quote adds decimal odds, implied and margin-free probability, market fair odds, break-even point, model edge, expected return, quote range, source and timestamp. Missing prices stay clearly marked <b>UNPRICED</b>.</p>
          <?php if ($rows === []): ?>
            <div class="empty-state"><p>No fixtures match this page and filter selection.</p></div>
          <?php else: ?>
            <div class="football-fixture-list">
              <?php foreach ($rows as $row): ?>
                <?php
                $rowPageId = (int) ($row['fixtureId'] ?? $row['fixtureDatabaseId'] ?? 0);
                if ($rowPageId <= 0) continue;
                $fixtureId = $rowPageId;
                $primary = is_array($row['market'] ?? null) ? $row['market'] : [];
                $allMarkets = is_array($row['marketCandidates'] ?? null) ? $row['marketCandidates'] : [];
                // OVER_1_5 and UNDER_1_5 are useful filter aliases, but both
                // evaluate the same two-sided bookmaker market. Show that
                // family once in the all-odds sheet so a match never repeats
                // identical Over/Under rows under two headings.
                $marketSheet = [];
                $seenFamilies = [];
                foreach ($allMarkets as $candidate) {
                    $candidatePricing = is_array($candidate['pricing'] ?? null) ? $candidate['pricing'] : [];
                    $family = (string) ($candidatePricing['family'] ?? $candidate['key'] ?? '');
                    $lineKey = isset($candidatePricing['line']) && is_numeric($candidatePricing['line'])
                        ? number_format((float) $candidatePricing['line'], 2, '.', '') : '';
                    $displayKey = $family . '|' . $lineKey;
                    if ($displayKey !== '|' && isset($seenFamilies[$displayKey])) continue;
                    $seenFamilies[$displayKey] = true;
                    $marketSheet[] = $candidate;
                }
                $pricedSelections = 0;
                $modelledSelections = 0;
                $pricedMarkets = 0;
                $latestOddsAt = null;
                foreach ($marketSheet as $candidate) {
                    $candidatePriced = false;
                    $candidatePricing = is_array($candidate['pricing'] ?? null) ? $candidate['pricing'] : [];
                    $candidatePricedAt = (string) ($candidatePricing['pricedAt'] ?? '');
                    if ($candidatePricedAt !== '' && ($latestOddsAt === null || $candidatePricedAt > $latestOddsAt)) $latestOddsAt = $candidatePricedAt;
                    foreach ((array) ($candidate['outcomes'] ?? []) as $outcome) {
                        if (is_numeric($outcome['probability'] ?? null)) $modelledSelections++;
                        if (($outcome['oddsState'] ?? '') === \AIWorkforce\Football\PredictionMarkets::STATE_AVAILABLE) {
                            $pricedSelections++;
                            $candidatePriced = true;
                        }
                    }
                    if ($candidatePriced) $pricedMarkets++;
                }
                $primaryValue = is_array($primary['value'] ?? null) ? $primary['value'] : [];
                $primaryPricing = is_array($primary['pricing'] ?? null) ? $primary['pricing'] : [];
                ?>
                <article class="football-fixture" id="fixture-<?= $fixtureId ?>">
                  <header class="football-fixture__header">
                    <div class="football-fixture__identity">
                      <div class="football-fixture__meta"><span><?= e((string) ($row['league'] ?? $row['competition'] ?? '—')) ?></span><span>·</span><span><?= e($kickoff($row['kickoffAt'] ?? $row['kickoff'] ?? null)) ?></span><span>·</span><span><?= e((string) ($row['status'] ?? 'UNKNOWN')) ?></span></div>
                      <h4><a href="/football/match/<?= $rowPageId ?>"><?= crest($row['homeTeamLogo'] ?? null, 22) ?><?= e((string) ($row['homeTeam'] ?? '—')) ?> <span>vs</span> <?= crest($row['awayTeamLogo'] ?? null, 22) ?><?= e((string) ($row['awayTeam'] ?? '—')) ?></a></h4>
                    </div>
                    <div class="football-fixture__badges">
                      <span class="badge <?= $bandClass((string) ($row['band'] ?? '')) ?>">DQ <?= (int) ($row['dataQualityScore'] ?? $row['dataQuality'] ?? 0) ?>/100</span>
                      <span class="badge <?= $bandClass((string) ($row['riskStatus'] ?? $row['risk']['level'] ?? '')) ?>"><?= e((string) ($row['riskStatus'] ?? $row['risk']['level'] ?? 'UNKNOWN')) ?> risk</span>
                      <span class="badge <?= ($row['predictionStatus'] ?? '') === 'ANALYZED' ? 'b-green' : 'b-gray' ?>"><?= e((string) ($row['predictionStatus'] ?? 'NOT_ANALYZED')) ?></span>
                    </div>
                  </header>
                  <div class="football-fixture__summary">
                    <div><span class="football-summary-label">Overview market</span><b><?= e((string) ($primary['label'] ?? $marketBlock['label'] ?? 'Match Winner')) ?></b><span><?= e((string) ($primary['selectionLabel'] ?? 'No selection')) ?></span></div>
                    <div><span class="football-summary-label">WINDELS probability</span><b class="mono"><?= $pct($primary['probability'] ?? null) ?></b><span><?= e((string) ($primary['source'] ?? '')) ?></span></div>
                    <div><span class="football-summary-label">Bookmaker odds</span><b class="mono"><?= $odds($primary['odds'] ?? null) ?></b><span><?= is_numeric($primary['odds'] ?? null) ? 'verified provider price' : 'UNPRICED' ?></span></div>
                    <div><span class="football-summary-label">Potential edge</span><b class="mono <?= (float) ($primaryValue['expectedValue'] ?? -1) >= 0 ? 'up' : 'down' ?>"><?= $signedPct($primaryValue['expectedValue'] ?? null) ?></b><span><?= e((string) ($primaryValue['valueLabel'] ?? 'No value verdict')) ?></span></div>
                    <div><span class="football-summary-label">Confidence</span><b class="mono"><?= $dash($row['confidence'] ?? null) ?>%</b><span><?= e((string) ($row['band'] ?? '')) ?></span></div>
                  </div>
                  <details class="football-odds-disclosure">
                    <summary>
                      <span><b>Full odds &amp; fair-price sheet</b> <span class="dim">· <?= count($marketSheet) ?> market<?= count($marketSheet) === 1 ? '' : 's' ?> · <?= $modelledSelections ?> modelled selection<?= $modelledSelections === 1 ? '' : 's' ?> · <?= $pricedSelections ?> bookmaker quote<?= $pricedSelections === 1 ? '' : 's' ?> across <?= $pricedMarkets ?> market<?= $pricedMarkets === 1 ? '' : 's' ?></span></span>
                      <span class="football-disclosure-action">Open sheet</span>
                    </summary>
                    <div class="football-odds-sheet">
                      <?php if ($marketSheet === []): ?>
                        <p class="football-help">Analyze this fixture to build its market sheet. No probability or price is invented before a stored prediction exists.</p>
                      <?php elseif ($pricedSelections === 0): ?>
                        <div class="football-odds-status"><span class="badge b-amber">BOOKMAKER ODDS UNAVAILABLE</span><p>The model-derived probabilities and WINDELS fair odds remain available below. No provider price is substituted for the missing quotes.</p></div>
                      <?php endif; ?>
                      <?php foreach ($marketSheet as $candidate): ?>
                        <?php
                        $outcomes = is_array($candidate['outcomes'] ?? null) ? $candidate['outcomes'] : [];
                        $pricing = is_array($candidate['pricing'] ?? null) ? $candidate['pricing'] : [];
                        $providerOnly = (string) ($candidate['source'] ?? '') === \AIWorkforce\Football\PredictionMarkets::SOURCE_ODDS;
                        ?>
                        <section class="football-market-card">
                          <header>
                            <div>
                              <p><?= e((string) ($candidate['group'] ?? 'Market')) ?></p>
                              <h5><?= e((string) ($candidate['label'] ?? $candidate['key'] ?? 'Market')) ?></h5>
                              <small><?= count($outcomes) ?> selection<?= count($outcomes) === 1 ? '' : 's' ?> · <?= e((string) ($candidate['basis'] ?? 'stored market data')) ?></small>
                            </div>
                            <div class="football-market-card__badges">
                              <span class="badge <?= $providerOnly ? 'b-amber' : 'b-blue' ?>"><?= $providerOnly ? 'Provider price only' : 'WINDELS modelled' ?></span>
                              <span class="badge <?= $bandClass((string) ($pricing['state'] ?? 'DATA_UNAVAILABLE')) ?>"><?= e((string) ($pricing['state'] ?? 'UNPRICED')) ?></span>
                              <?php if (!empty($pricing['priceStale'])): ?><span class="badge b-red">STALE PRICE</span><?php endif; ?>
                              <span class="badge <?= $bandClass((string) ($candidate['riskLevel'] ?? '')) ?>"><?= e((string) ($candidate['riskLevel'] ?? 'UNKNOWN')) ?> risk</span>
                            </div>
                          </header>
                          <div class="table-scroll">
                            <table class="tbl football-odds-table football-odds-table--complete">
                              <thead><tr><th>Selection</th><th>WINDELS estimate</th><th>Bookmaker quote &amp; information</th><th>Margin-free market</th><th>Value &amp; edge</th></tr></thead>
                              <tbody>
                                <?php foreach ($outcomes as $outcome): ?>
                                  <?php
                                  $expected = $outcome['expectedValue'] ?? null;
                                  $valueClass = (string) ($outcome['valueClass'] ?? 'UNPRICED');
                                  $valueTone = in_array($valueClass, ['STRONG_VALUE', 'POSITIVE_VALUE'], true) ? 'b-green'
                                      : (in_array($valueClass, ['NEGATIVE_VALUE', 'AVOID'], true) ? 'b-red' : 'b-gray');
                                  $hasQuote = is_numeric($outcome['odds'] ?? null);
                                  ?>
                                  <tr>
                                    <td class="football-selection-cell"><b><?= e((string) ($outcome['label'] ?? $outcome['selection'] ?? '—')) ?></b><small class="mono"><?= e((string) ($outcome['selection'] ?? '')) ?></small><?php if (!empty($outcome['note'])): ?><small><?= e((string) $outcome['note']) ?></small><?php endif; ?></td>
                                    <td class="football-odds-metric"><b class="mono"><?= $pct($outcome['probability'] ?? null) ?></b><small>WINDELS probability</small><span class="mono">fair odds <?= $odds($outcome['windelsFairOdds'] ?? null) ?></span></td>
                                    <td class="football-quote football-odds-metric">
                                      <?php if ($hasQuote): ?>
                                        <b class="mono"><?= $odds($outcome['odds']) ?></b><small>Bookmaker odds · implied <?= $pct($outcome['impliedProbability'] ?? null) ?></small>
                                        <span><?= e((string) ($outcome['oddsSource'] ?? 'Source unavailable')) ?></span>
                                        <small><?= e($kickoff($outcome['oddsObservedAt'] ?? null, 'Y-m-d H:i')) ?></small>
                                        <small><?= max(1, (int) ($outcome['quoteCount'] ?? 1)) ?> quote<?= (int) ($outcome['quoteCount'] ?? 1) === 1 ? '' : 's' ?> · range <?= $odds($outcome['oddsLow'] ?? $outcome['odds']) ?>–<?= $odds($outcome['oddsHigh'] ?? $outcome['odds']) ?></small>
                                      <?php else: ?>
                                        <span class="badge b-gray">UNPRICED</span><small>No bookmaker quote stored</small>
                                      <?php endif; ?>
                                    </td>
                                    <td class="football-odds-metric">
                                      <?php if (is_numeric($outcome['fairProbability'] ?? null)): ?>
                                        <b class="mono"><?= $pct($outcome['fairProbability']) ?></b><small>fair probability after margin</small><span class="mono">market fair odds <?= $odds($outcome['fairOdds'] ?? null) ?></span>
                                      <?php else: ?>
                                        <b class="mono">—</b><small>Needs a complete price sheet</small><span>Break-even <?= $pct($outcome['breakEvenProbability'] ?? null) ?></span>
                                      <?php endif; ?>
                                    </td>
                                    <td class="football-value-cell">
                                      <span class="badge <?= $valueTone ?>"><?= e((string) ($outcome['valueLabel'] ?? 'No price to compare')) ?></span>
                                      <b class="mono <?= is_numeric($expected) && (float) $expected >= 0 ? 'up' : (is_numeric($expected) ? 'down' : 'dim') ?>">Expected return <?= $signedPct($expected) ?></b>
                                      <small class="mono">Edge vs quote <?= is_numeric($outcome['edgePoints'] ?? null) ? (((float) $outcome['edgePoints'] >= 0 ? '+' : '') . $dash($outcome['edgePoints'], 2) . 'pp') : '—' ?></small>
                                      <small class="mono">Edge after margin <?= is_numeric($outcome['edgeAgainstFairPoints'] ?? null) ? (((float) $outcome['edgeAgainstFairPoints'] >= 0 ? '+' : '') . $dash($outcome['edgeAgainstFairPoints'], 2) . 'pp') : '—' ?></small>
                                      <?php if (!empty($outcome['valueReason'])): ?><details class="football-value-explanation"><summary>Why this rating</summary><p><?= e((string) $outcome['valueReason']) ?></p></details><?php endif; ?>
                                    </td>
                                  </tr>
                                <?php endforeach; ?>
                              </tbody>
                            </table>
                          </div>
                          <footer>
                            <span>Coverage <?= $pct($candidate['coverage'] ?? null) ?> · <?= (int) ($pricing['legsPriced'] ?? 0) ?>/<?= (int) ($pricing['legsExpected'] ?? 0) ?> expected legs priced</span>
                            <span><?php if (is_numeric($pricing['overround'] ?? null)): ?>Overround <?= $pct($pricing['overround']) ?> · market margin <?= $dash($pricing['marginPoints'] ?? null, 2) ?>pp · <?= e((string) ($pricing['marginMethod'] ?? '')) ?><?php else: ?>Margin removal DATA_UNAVAILABLE<?php endif; ?><?php if (!empty($pricing['pricedAt'])): ?> · latest <?= e($kickoff($pricing['pricedAt'], 'Y-m-d H:i')) ?><?php endif; ?></span>
                          </footer>
                        </section>
                      <?php endforeach; ?>
                      <p class="football-odds-sheet__note">Bookmaker odds are provider quotes. WINDELS fair odds are 1 ÷ model probability; expected return is shown only where a model probability and a valid price can be compared. Market margin is calculated only for a complete mutually exclusive price sheet.</p>
                    </div>
                  </details>
                  <footer class="football-fixture__footer"><span>Match ID: <?= $rowPageId > 0 ? $rowPageId : '—' ?></span><span>Provider <b class="mono"><?= e((string) ($row['providerCode'] ?? '—')) ?></b></span><span>Latest odds <b class="mono"><?= e($kickoff($latestOddsAt, 'Y-m-d H:i')) ?></b></span><a href="/football/match/<?= $fixtureId ?>">Open full match analysis →</a></footer>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <?php if ($pagination !== []): ?><div class="football-section__divider"></div><?= $pager($pagination, (string) ($date ?? gmdate('Y-m-d')), $carry) ?><?php endif; ?>
        </div>
      </section>

      <section class="panel football-section" aria-labelledby="performance-heading">
        <div class="football-section__heading"><div><p class="football-eyebrow">Measured results</p><h3>30-day performance (settled predictions)</h3></div><span class="dim">settled predictions only</span></div>
        <div class="body">
          <div class="stat-grid football-stat-grid football-stat-grid--compact">
            <div class="stat"><div class="k">Evaluated</div><div class="v"><?= (int) ($perf['evaluatedPredictions'] ?? 0) ?></div></div>
            <div class="stat"><div class="k">Result accuracy</div><div class="v"><?= $pct($perf['resultAccuracy'] ?? null) ?></div></div>
            <div class="stat"><div class="k">Exact-score accuracy</div><div class="v"><?= $pct($perf['exactScoreAccuracy'] ?? null, 2) ?></div></div>
            <div class="stat"><div class="k">Brier score</div><div class="v mono"><?= $dash($perf['brier'] ?? null, 4) ?></div></div>
            <div class="stat"><div class="k">Avg. confidence</div><div class="v"><?= $dash($perf['averageConfidence'] ?? null) ?>%</div></div>
            <div class="stat"><div class="k">Avg. data quality</div><div class="v"><?= $dash($perf['averageDataQuality'] ?? null) ?>/100</div></div>
          </div>
          <?php if (($perf['state'] ?? '') !== 'MEASURED'): ?><p class="football-help">No settled predictions yet. Historical accuracy and calibration metrics will appear after predicted matches complete.</p><?php endif; ?>
          <?php if (!empty($perf['note'])): ?><p class="football-help"><?= e((string) $perf['note']) ?></p><?php endif; ?>
        </div>
      </section>
    </div>

    <aside class="football-side stack" aria-label="Football operational context">
      <section class="panel football-section football-reading-guide">
        <div class="football-section__heading"><div><p class="football-eyebrow">Reading the board</p><h3>Keep the numbers separate</h3></div></div>
        <div class="body">
          <dl>
            <div><dt>WINDELS probability</dt><dd>The model’s estimated chance from stored match data.</dd></div>
            <div><dt>Market odds</dt><dd>The decimal bookmaker price supplied by the provider.</dd></div>
            <div><dt>WINDELS fair odds</dt><dd>1 ÷ model probability. This is not a bookmaker offer.</dd></div>
            <div><dt>Potential edge</dt><dd>The model/price comparison, not a guarantee and not an instruction to bet. No selection is guaranteed.</dd></div>
          </dl>
        </div>
      </section>

      <section class="panel football-section" aria-labelledby="live-heading">
        <div class="football-section__heading"><div><p class="football-eyebrow">In play</p><h3>Live now</h3></div><a class="btn small" href="/football/live">Refresh live</a></div>
        <div class="body">
          <?php $liveMatches = is_array($live['matches'] ?? null) ? $live['matches'] : []; ?>
          <?php if ($liveMatches === []): ?><p class="football-help">No match is in play in the stored data. The live sweep runs only while a fixture is reported live.</p><?php else: ?>
            <div class="football-live-list">
              <?php foreach ($liveMatches as $liveMatch): ?>
                <?php $fx = is_array($liveMatch['fixture'] ?? null) ? $liveMatch['fixture'] : []; $liveState = is_array($liveMatch['live'] ?? null) ? $liveMatch['live'] : []; ?>
                <div>
                  <b><?= crest($fx['homeTeamLogo'] ?? null) ?><?= e((string) ($fx['homeTeam'] ?? '—')) ?> <?= isset($liveState['score']['home']) ? (int) $liveState['score']['home'] : '—' ?>–<?= isset($liveState['score']['away']) ? (int) $liveState['score']['away'] : '—' ?> <?= crest($fx['awayTeamLogo'] ?? null) ?><?= e((string) ($fx['awayTeam'] ?? '—')) ?></b>
                  <span><?= e((string) ($fx['competition'] ?? '—')) ?> · <?= e((string) ($liveState['state'] ?? 'LIVE')) ?><?= isset($liveState['minute']) && $liveState['minute'] !== null ? ' · ' . (int) $liveState['minute'] . "'" : '' ?></span>
                  <span class="mono">Kickoff <?= e($kickoffStamp($fx['kickoff'] ?? null)) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
            <p class="football-help">The pre-match prediction and live estimate are separate stored rows; the original prediction is never rewritten after kickoff.</p>
          <?php endif; ?>
          <?php if (!empty($live['errors'])): ?><p class="football-help">Live refresh: <?= e(implode(' · ', array_slice((array) $live['errors'], 0, 3))) ?></p><?php endif; ?>
        </div>
      </section>

      <section class="panel football-section" aria-labelledby="feed-heading">
        <div class="football-section__heading"><div><p class="football-eyebrow">Data health</p><h3 id="feed-heading">Data feed</h3></div></div>
        <div class="body">
          <?php if (empty($diag['checks'])): ?><p class="football-help">Diagnostics are unavailable; the module could not read its own stored state.</p><?php else: ?>
            <div class="football-check-list">
              <?php foreach ($diag['checks'] as $check): ?><div><span class="dot <?= $stateClass((string) ($check['state'] ?? '')) ?>"></span><div><b><?= e((string) ($check['key'] ?? 'Check')) ?></b><small><?= e((string) ($check['detail'] ?? $check['value'] ?? '—')) ?></small></div></div><?php endforeach; ?>
            </div>
          <?php endif; ?>
          <?php if (!empty($diag['blockers'])): ?><p class="football-help"><b>Blockers:</b> <?= e(implode(', ', (array) $diag['blockers'])) ?></p><?php endif; ?>
          <?php if (!empty($caps['settle'])): ?><form method="post" action="/football/settle" style="margin-top:12px"><input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>"><button class="btn small">Run settlement sweep</button></form><?php endif; ?>
        </div>
      </section>

      <section class="panel football-section" aria-labelledby="model-heading">
        <div class="football-section__heading"><div><p class="football-eyebrow">Governance</p><h3 id="model-heading">Model &amp; calibration</h3></div></div>
        <div class="body">
          <dl class="football-key-values">
            <div><dt>State</dt><dd><span class="dot <?= $stateClass((string) ($models['state'] ?? '')) ?>"></span> <?= e((string) ($models['label'] ?? 'No model loaded')) ?></dd></div>
            <div><dt>Active version</dt><dd class="mono"><?= e((string) ($models['activeModel']['version'] ?? '—')) ?></dd></div>
            <div><dt>Calibration</dt><dd class="mono"><?= e((string) ($models['calibration']['calibrationVersion'] ?? ($models['calibration']['status'] ?? 'CALIBRATION_PENDING'))) ?></dd></div>
          </dl>
          <?php if (!empty($models['reason'])): ?><p class="football-help"><?= e((string) $models['reason']) ?></p><?php endif; ?>
          <a class="btn small" href="/football/models">Open models &amp; calibration</a>
        </div>
      </section>

      <section class="panel football-section" aria-labelledby="schedule-heading">
        <div class="football-section__heading"><div><p class="football-eyebrow">Automation</p><h3 id="schedule-heading">Refresh schedule</h3></div></div>
        <div class="body">
          <p class="football-help">Jobs are provider-aware and run only when due, data is available and the provider is not in backoff.</p>
          <div class="football-schedule-list">
            <?php foreach ((array) ($diag['cadence']['jobs'] ?? []) as $job): ?><div><span class="mono"><?= e(str_replace('football-', '', (string) ($job['job'] ?? 'job'))) ?></span><span><?= !empty($job['due']) ? '<b class="up">Due</b>' : e((string) ($job['reason'] ?? 'Waiting')) ?></span><small><?= (int) ($job['interval'] ?? 0) ?>s</small></div><?php endforeach; ?>
          </div>
        </div>
      </section>
    </aside>
  </div>
</div>
