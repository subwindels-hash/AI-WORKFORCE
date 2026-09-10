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
$isAdmin = $isAdmin ?? false;
$summary = $board['summary'] ?? ['fixtures' => 0, 'analyzed' => 0, 'qualified' => 0, 'limited' => 0, 'rejected' => 0];

// The selection the operator made — competition, premium league and market. It
// is computed once, at the top, because every pager link and the generate form
// carry it: paging and generating stay inside the league and market chosen.
$filters = is_array($board['filters'] ?? null) ? $board['filters'] : [];
$competitions = is_array($filters['competitions']['competitions'] ?? null) ? $filters['competitions']['competitions'] : [];
$premium = is_array($filters['competitions']['premium'] ?? null) ? $filters['competitions']['premium'] : null;
// Every competition on this date the deployment classifies as premium, not just
// the featured one: the Premium League selector offers the leagues that were
// classified, in the order the feed sent them.
$premiumOptions = array_values(array_filter(
    is_array($filters['competitions']['premiumCompetitions'] ?? null) ? $filters['competitions']['premiumCompetitions'] : [],
    static fn($entry): bool => is_array($entry) && (string) ($entry['externalId'] ?? '') !== ''));
$selectedCompetition = is_array($filters['competition'] ?? null) ? $filters['competition'] : null;
$selectedExternal = (string) ($selectedCompetition['externalId'] ?? '');
// The "All premium leagues" scope: the board is narrowed to the date's premium
// leagues as a group (resolved to their external ids) instead of to one league.
// The Premium League selector offers it, and the Competition selector reports
// it, because a page that is narrowed to a group must never display itself as
// narrowed to nothing.
$resolvedCompetitionState = (string) (is_array($selectedCompetition) ? ($selectedCompetition['state'] ?? '') : '');
$premiumAllKeyword = \AIWorkforce\Football\MatchFeed::PREMIUM_LEAGUES;
$premiumAllScope = $resolvedCompetitionState === $premiumAllKeyword;
$premiumAllMatches = $premiumAllScope && is_array($selectedCompetition) ? (int) ($selectedCompetition['matches'] ?? 0) : 0;
$marketBlock = is_array($board['market'] ?? null) ? $board['market'] : [];
$marketList = is_array($marketBlock['available'] ?? null) ? $marketBlock['available'] : [];
$selectedMarket = (string) ($marketBlock['key'] ?? 'MATCH_WINNER');
// The raw query values, kept apart from the resolved ones: the "All" options
// are marked selected only when the operator actually asked for no narrowing,
// while the resolved values keep the locked (admin-managed) controls honest.
$marketRequested = trim((string) ($market ?? ''));
$providerRequestedRaw = trim((string) ($providerRequested ?? ''));
// The data provider, offered only when the catalogue has something to offer:
// with no feed connected the dropdown is replaced by the reason, because a
// selector full of modes nobody can honour is a lie dressed as a choice.
$providerOptions = is_array($providers['options'] ?? null) ? $providers['options'] : [];
$selectedProvider = strtoupper(trim((string) ($provider ?? '')));
// Admin-controlled mode (AUTO by default): when locked, the selector offers
// Auto / Smart only and is disabled, because the backend ignores overrides.
$providerLocked = !empty($providerLocked) || !empty($providers['locked']);
$providerMode = (string) ($providerMode ?? ($providers['mode'] ?? 'AUTO'));
// MANUAL mode explicitly unlocks the complete selection flow. AUTO keeps the
// provider locked, but must not unnecessarily disable competition, market, or
// date filters (those are read-only stored-data filters).
$selectorsLockedByAdmin = strtoupper($providerMode) !== 'MANUAL';
$selectorDisabled = $selectorsLockedByAdmin ? ' disabled' : '';
$adminManagedBadge = $selectorsLockedByAdmin
    ? '<span class="badge b-green" title="AUTO: this selector is managed by the administrator.">AUTO · managed by admin</span>'
    : '<span class="badge b-amber" title="MANUAL: administrator enabled operator selection.">MANUAL · selectable</span>';
$adminManagedTitle = $selectorsLockedByAdmin
    ? 'AUTO: this selector is managed by the administrator.'
    : 'MANUAL: administrator enabled this selector.';
$windelsModelId = 'Windels Model id: 1520863';
// The selection the pager and the generate form carry. It is the *raw*
// requested value — an empty one means "not narrowed" and must stay empty, so
// choosing "All" in a selector cannot silently flip the next page to the
// resolved default (that would show a different dropdown selection on reload).
$carry = [];
$competitionRequested = trim((string) (is_array($selectedCompetition) ? ($selectedCompetition['requested'] ?? '') : ''));
if ($competitionRequested !== '') $carry['competition'] = $competitionRequested;
if ($marketRequested !== '') $carry['market'] = $marketRequested;
if ($providerRequestedRaw !== '') $carry['provider'] = $providerRequestedRaw;
// "All providers" is selected when nothing is pinned: either the operator asked
// for ALL_PROVIDERS, or the request fell back to Auto/Smart (no administrator
// manual default) — Auto reads every feed, so the page is already the mixed,
// best-data-per-fixture board that "All providers" describes.
$providerAllSelected = !$selectorsLockedByAdmin
    && ($selectedProvider === \AIWorkforce\Football\ProviderSelector::ALL_PROVIDERS
        || ($selectedProvider === 'AUTO' && $providerRequestedRaw === ''));
$premiumHiddenValue = $premiumAllScope ? $premiumAllKeyword : $selectedExternal;
// The text of the Premium League selector's neutral first option. It must
// never claim the page is showing every competition while the Competition
// selector has narrowed it to a non-premium league.
$premiumNoneLabel = $premiumOptions === []
    ? 'No premium league stored for this date — every competition shown'
    : ($selectedExternal !== ''
        ? 'No premium filter — the competition in Select Competition applies'
        : 'All competitions — no premium-only filter');

$dash = static fn(mixed $v, int $dp = 1): string => is_numeric($v) ? number_format((float) $v, $dp) : '—';
$percent = static fn(mixed $v, int $dp = 1): string => is_numeric($v) ? number_format((float) $v * 100, $dp) . '%' : '—';
$bandClass = static fn(string $band): string => match (strtoupper($band)) {
    'QUALIFIED' => 'b-green',
    'LIMITED', 'LIMITED_DATA' => 'b-amber',
    default => 'b-gray',
};
$stateClass = static fn(string $state): string => match (strtoupper($state)) {
    'READY', 'CONNECTED', 'AVAILABLE', 'ACTIVE', 'MEASURED', 'CALIBRATED', 'ONLINE', 'POPULATED' => 'up',
    'DEGRADED', 'LIMITED_DATA', 'LIMITED', 'PENDING', 'CADENCE', 'DRAFT', 'TRAINED', 'VALIDATED', 'CALIBRATED', 'APPROVED' => 'synth',
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
        <input type="hidden" name="provider" value="<?= e((string) ($carry['provider'] ?? '')) ?>">
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
          <?php $modelNoticeState = (string) ($board['model']['state'] ?? 'MODEL') === 'ACTIVE' ? 'ACTIVE MODEL' : 'AUTO MODEL'; ?>
          <div class="notice info" style="margin-top:10px"><b><?= e($modelNoticeState) ?></b> — <?= e((string) $board['model']['note']) ?></div>
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
        <?php if (!empty($isAdmin)): ?>
        <div style="margin-top:14px;padding:12px;border:1px solid var(--line);border-radius:10px;background:rgba(127,127,127,.04)">
          <form method="get" action="/football" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
            <input type="hidden" name="page" value="1">
            <div>
              <label class="dim" style="font-size:11px;display:block">Data Provider <?= $adminManagedBadge ?></label>
              <?php if ($providerOptions === []): ?>
                <select disabled style="min-width:200px"><option>No feed connected</option></select>
              <?php elseif ($providerLocked || $selectorsLockedByAdmin): ?>
                <select name="provider" disabled style="min-width:200px" title="<?= e($adminManagedTitle) ?>">
                  <option value="AUTO" selected>Windels Smart Model</option>
                </select>
                <input type="hidden" name="provider" value="AUTO">
              <?php else: ?>
                <select name="provider" style="min-width:240px" title="Which feed answers this request. All providers lists every stored fixture and takes each match's data from the feed that has it; Auto / Smart picks from health, coverage, odds availability and rate limits; Multi-Provider takes each piece of data from the feed that has it.">
                  <option value="<?= e(\AIWorkforce\Football\ProviderSelector::ALL_PROVIDERS) ?>"<?= $providerAllSelected ? ' selected' : '' ?>>
                    All providers — every stored fixture
                  </option>
                  <?php $providerNumber = 0; ?>
                  <?php foreach ($providerOptions as $option): ?>
                    <?php
                    $value = strtoupper((string) ($option['value'] ?? ''));
                    $isAuto = in_array($value, ['AUTO', 'SMART'], true);
                    if (!$isAuto) $providerNumber++;
                    $providerLabel = $isAuto ? 'Windels Smart Model' : 'Model ' . $providerNumber;
                    // The AUTO entry is only marked when the operator pinned it
                    // explicitly; an unpinned request already reads as the
                    // "All providers" option above it.
                    $isSelected = $selectedProvider === $value && !($providerAllSelected && $isAuto);
                    ?>
                    <option value="<?= e((string) ($option['value'] ?? '')) ?>"<?= $isSelected ? ' selected' : '' ?>>
                      <?= e($providerLabel) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              <?php endif; ?>
            </div>
            <div>
              <label class="dim" style="font-size:11px;display:block">Select Competition <?= $adminManagedBadge ?></label>
              <?php if ($selectorsLockedByAdmin): ?><input type="hidden" name="competition" value="<?= e((string) ($carry['competition'] ?? '')) ?>"><?php endif; ?>
              <select name="competition"<?= $selectorDisabled ?> style="min-width:230px" title="<?= e($adminManagedTitle) ?>">
                <?php if ($premiumOptions !== [] || $premiumAllScope): ?>
                  <option value="<?= e($premiumAllKeyword) ?>"<?= $premiumAllScope ? ' selected' : '' ?>>All premium leagues (combined<?= $premiumAllMatches > 0 ? ', ' . $premiumAllMatches . ' matches' : '' ?>)</option>
                <?php endif; ?>
                <option value=""<?= !$premiumAllScope && $selectedExternal === '' ? ' selected' : '' ?>>All competitions (<?= (int) ($summary['fixtures'] ?? 0) ?> matches)</option>
                <?php foreach ($competitions as $competition): ?>
                  <?php $external = (string) ($competition['externalId'] ?? ''); ?>
                  <option value="<?= e($external) ?>"<?= $selectedExternal === $external ? ' selected' : '' ?>>
                    <?= e((string) ($competition['name'] ?? 'Unnamed competition')) ?><?= !empty($competition['country']) ? ' · ' . e((string) $competition['country']) : '' ?> (<?= (int) ($competition['matches'] ?? 0) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="dim" style="font-size:11px;display:block">Premium League <?= $adminManagedBadge ?></label>
              <?php if ($selectorsLockedByAdmin): ?><input type="hidden" name="premium" value="<?= e($premiumHiddenValue) ?>"><?php endif; ?>
              <select name="premium"<?= $selectorDisabled ?> style="min-width:240px" title="<?= e($adminManagedTitle) ?>" onchange="this.form.competition.value=this.value;this.form.submit();">
                <option value=""<?= !$premiumAllScope && $selectedExternal === '' ? ' selected' : '' ?>><?= e($premiumNoneLabel) ?></option>
                <?php if ($premiumOptions !== [] || $premiumAllScope): ?>
                <option value="<?= e($premiumAllKeyword) ?>"<?= $premiumAllScope ? ' selected' : '' ?>>All premium leagues (combined<?= $premiumAllMatches > 0 ? ', ' . $premiumAllMatches . ' matches' : '' ?>)</option>
                <?php endif; ?>
                <?php foreach ($premiumOptions as $entry): ?>
                  <?php $externalId = (string) ($entry['externalId'] ?? ''); ?>
                  <option value="<?= e($externalId) ?>"<?= !$premiumAllScope && $selectedExternal === $externalId ? ' selected' : '' ?>>
                    <?= e((string) ($entry['name'] ?? 'Premium League')) ?> (<?= (int) ($entry['matches'] ?? 0) ?> matches)<?= $externalId === (string) ($premium['externalId'] ?? '') ? ' · featured' : '' ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="dim" style="font-size:11px;display:block">Select Odds Prediction <?= $adminManagedBadge ?></label>
              <?php if ($selectorsLockedByAdmin): ?><input type="hidden" name="market" value="<?= e($selectedMarket) ?>"><?php endif; ?>
              <select name="market"<?= $selectorDisabled ?> style="min-width:250px" title="<?= e($adminManagedTitle) ?>">
                <option value=""<?= $marketRequested === '' ? ' selected' : '' ?>>All markets — every fixture, default odds view</option>
                <?php foreach ($marketList as $entry): ?>
                  <?php $key = (string) ($entry['key'] ?? ''); ?>
                  <option value="<?= e($key) ?>"<?= $marketRequested !== '' && $selectedMarket === $key ? ' selected' : '' ?>>
                    <?= e((string) ($entry['label'] ?? $key)) ?><?= empty($entry['oddsAvailable']) && (string) ($entry['derivation'] ?? '') === 'NOT_MODELLED' ? ' — no stored data' : '' ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div>
              <label class="dim" style="font-size:11px;display:block">Date <?= $adminManagedBadge ?></label>
              <?php if ($selectorsLockedByAdmin): ?><input type="hidden" name="date" value="<?= e($dateParam) ?>"><?php endif; ?>
              <input type="date" name="date" value="<?= e($dateParam) ?>"<?= $selectorDisabled ?> title="<?= e($adminManagedTitle) ?>">
            </div>
            <button class="btn small primary">Apply</button>
          </form>
          <p class="dim" style="font-size:11px;margin:8px 0 0">
            <?= $selectorsLockedByAdmin ? 'These selections are set to AUTO and managed by the administrator; the visible controls are locked for operators.' : 'MANUAL mode is enabled by the administrator; operators may choose the provider, competition, premium league, market, and date.' ?>
            Every selector also offers its all-value, so the page can list <b>every fixture at once</b> instead of one league or market at a time: <i>All providers</i> reads every connected feed's stored rows (each row names the feed behind it), <i>All competitions</i> and the Premium League selector's first option clear the league narrowing, <i>All premium leagues (combined)</i> narrows to every premium league on the date together, and <i>All markets</i> shows every fixture in the default odds view.
            Competitions are listed from the provider feed — no league is offered that has no stored match.
            The market is a view over the predictions already stored: changing it never regenerates a match.
            The provider chosen here is the one a sync or a fetch reads; paging and market changes read stored rows and cost no provider call.
          </p>
        </div>
        <?php endif; ?>

        <!-- ── Top WINDELS Picks (the ranked reading of this page) ────────── -->
        <?php $picksBlock = is_array($board['picks'] ?? null) ? $board['picks'] : []; ?>
        <?php $picks = is_array($picksBlock['picks'] ?? null) ? $picksBlock['picks'] : []; ?>
        <?php if ($picks !== []): ?>
          <div style="margin-top:16px;padding:12px;border:1px solid var(--line);border-left:3px solid var(--violet,#6d28d9);border-radius:10px">
            <h4 style="margin:0 0 4px">⭐ Top WINDELS Picks
              <span class="dim" style="font-weight:400;font-size:11px">(<?= count($picks) ?> of <?= (int) ($picksBlock['eligible'] ?? 0) ?> eligible on this page · ranked by intelligence score, then value, then confidence)</span>
            </h4>
            <table style="width:100%;border-collapse:collapse;font-size:12px">
              <thead>
                <tr style="text-align:left;border-bottom:1px solid var(--line)">
                  <th style="padding:4px 6px">#</th>
                  <th style="padding:4px 6px">Match</th>
                  <th style="padding:4px 6px">Pick</th>
                  <th style="padding:4px 6px" class="mono">Score</th>
                  <th style="padding:4px 6px" class="mono">WINDELS</th>
                  <th style="padding:4px 6px" class="mono">Odds</th>
                  <th style="padding:4px 6px" class="mono">Fair</th>
                  <th style="padding:4px 6px" class="mono">Value</th>
                  <th style="padding:4px 6px" class="mono">DQ</th>
                  <th style="padding:4px 6px">Movement</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($picks as $pick): ?>
                  <tr style="border-bottom:1px solid var(--line)">
                    <td style="padding:4px 6px" class="mono"><?= (int) ($pick['rank'] ?? 0) ?></td>
                    <td style="padding:4px 6px">
                      <?php $pickId = (int) ($pick['fixtureId'] ?? 0); ?>
                      <?php if ($pickId > 0): ?>
                        <a href="/football/match/<?= $pickId ?>" style="font-weight:600"><?= e((string) ($pick['homeTeam'] ?? '—')) ?> vs <?= e((string) ($pick['awayTeam'] ?? '—')) ?></a>
                      <?php else: ?>
                        <span style="font-weight:600"><?= e((string) ($pick['homeTeam'] ?? '—')) ?> vs <?= e((string) ($pick['awayTeam'] ?? '—')) ?></span>
                      <?php endif; ?>
                      <div class="dim" style="font-size:10px"><?= e((string) ($pick['kickoffLabel'] ?? '')) ?></div>
                    </td>
                    <td style="padding:4px 6px"><?= e((string) ($pick['selectionLabel'] ?? '—')) ?></td>
                    <td style="padding:4px 6px" class="mono"><b><?= (int) ($pick['score'] ?? 0) ?></b>/100</td>
                    <td style="padding:4px 6px" class="mono"><?= is_numeric($pick['probability'] ?? null) ? number_format((float) $pick['probability'] * 100, 1) . '%' : '—' ?></td>
                    <td style="padding:4px 6px" class="mono"><?= is_numeric($pick['odds'] ?? null) ? number_format((float) $pick['odds'], 2) : '—' ?></td>
                    <td style="padding:4px 6px" class="mono"><?= is_numeric($pick['fairOdds'] ?? null) ? number_format((float) $pick['fairOdds'], 2) : '—' ?></td>
                    <td style="padding:4px 6px" class="mono <?= (float) ($pick['expectedValue'] ?? 0) >= 0 ? 'up' : 'down' ?>"
                        title="<?= e((string) ($pick['classificationMeaning'] ?? '')) ?>"><?= e((string) ($pick['valueLabel'] ?? '')) ?> · 
                      <?= is_numeric($pick['expectedValue'] ?? null) ? ($pick['expectedValue'] >= 0 ? '+' : '') . number_format((float) $pick['expectedValue'] * 100, 1) . '%' : e((string) ($pick['valueLabel'] ?? '—')) ?>
                      <div class="dim" style="font-size:10px"><?= e((string) ($pick['valueLabel'] ?? '')) ?></div>
                    </td>
                    <td style="padding:4px 6px" class="mono"><?= (int) ($pick['dataQuality'] ?? 0) ?></td>
                    <td style="padding:4px 6px">
                      <?php $movement = (string) ($pick['stabilityState'] ?? ''); $movementWhy = (string) ($pick['stabilityReason'] ?? ''); ?>
                      <?php if ($movement === \AIWorkforce\Football\StabilityMonitor::UNSTABLE): ?>
                        <span class="down" title="Excluded from the list when the movement crosses the unstable threshold; shown here only if the row was ranked before that check.">unstable</span>
                      <?php elseif ($movement === \AIWorkforce\Football\StabilityMonitor::MOVED): ?>
                        <span class="dim">moved</span>
                      <?php elseif ($movement === \AIWorkforce\Football\StabilityMonitor::STABLE): ?>
                        <span class="dim">stable</span>
                      <?php else: ?>
                        <span class="dim">first reading</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <p class="dim" style="font-size:10px;margin:8px 0 0"><?= e((string) ($picksBlock['disclaimer'] ?? '')) ?></p>
            <?php if (!empty($picksBlock['excluded'])): ?>
              <p class="dim" style="font-size:10px;margin:4px 0 0">
                Not on the list, and why:
                <?php foreach (array_slice((array) $picksBlock['excluded'], 0, 6) as $skipped): ?>
                  <?= e(trim((string) ($skipped['homeTeam'] ?? '') . ' vs ' . (string) ($skipped['awayTeam'] ?? ''))) ?>
                  — <?= e((string) ($skipped['reason'] ?? 'excluded')) ?><?= e('; ') ?>
                <?php endforeach; ?>
                <?php if (count((array) $picksBlock['excluded']) > 6): ?>+<?= count((array) $picksBlock['excluded']) - 6 ?> more<?php endif; ?>
              </p>
            <?php endif; ?>
          </div>
        <?php endif; ?>

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
                  <th style="padding:6px" title="WINDELS' own grade of this prediction: how sure the model is, how good the data behind it is, how far the evidence reaches and how much it has moved. It is not the market's opinion and it is not the stake.">Intelligence</th>
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
                      <?php $rowPageId = (int) ($row['fixtureId'] ?? 0); ?>
                      <?php if ($rowPageId > 0): ?>
                        <a href="/football/match/<?= $rowPageId ?>" style="font-weight:600"><?= e((string) ($row['homeTeam'] ?? '—')) ?> vs <?= e((string) ($row['awayTeam'] ?? '—')) ?></a>
                      <?php else: ?>
                        <span style="font-weight:600" title="This row has no stored fixture, so it has no match page."><?= e((string) ($row['homeTeam'] ?? '—')) ?> vs <?= e((string) ($row['awayTeam'] ?? '—')) ?></span>
                      <?php endif; ?>
                      <div class="dim mono" style="font-size:10px"><?= e($windelsModelId) ?></div>
                      <?php /* Match ID is the match's own stored id — the same number its page is served under (/football/match/<fixtureId>) — so every generated match row carries one, never a blank. */ ?>
                      <div class="dim mono" style="font-size:10px">Match ID: <?= $rowPageId > 0 ? $rowPageId : '—' ?><?php $rowFeedKey = (string) ($row['matchId'] ?? ''); if ($rowFeedKey !== '' && !str_starts_with($rowFeedKey, 'fixture:')): ?> · feed <?= e($rowFeedKey) ?><?php endif; ?></div>
                      <div class="dim mono" style="font-size:10px"><?= e((string) ($row['providerLabel'] ?? 'Model unavailable')) ?> · Provider ID: <?= e((string) ($row['providerId'] ?? '—')) ?> · Provider Match ID: <?= e((string) ($row['providerMatchId'] ?? '—')) ?></div>
                    </td>
                    <td style="padding:6px"><?= e((string) ($row['competition'] ?? '—')) ?></td>
                    <td style="padding:6px" class="mono"><?= e((string) ($row['kickoffLabel'] ?? '—')) ?></td>
                    <td style="padding:6px">
                              <?php if (($row['analysisState'] ?? '') !== 'ANALYZED'): ?>
                        <?php $rowWithheld = (array) ((array) ($row['intelligence'] ?? []))['withheld'] ?? []; ?>
                        <?php if (!empty($rowWithheld['withheld'])): ?>
                          <span class="down" title="<?= e((string) ($rowWithheld['reason'] ?? '')) ?>"><?= e((string) ($rowWithheld['headline'] ?? 'Prediction withheld — insufficient verified data')) ?></span>
                        <?php else: ?>
                          <span class="dim">Not analyzed</span>
                        <?php endif; ?>
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
                    <?php $intel = is_array($row['intelligence'] ?? null) ? $row['intelligence'] : []; ?>
                    <?php $scoreBlock = is_array($intel['score'] ?? null) ? $intel['score'] : []; ?>
                    <?php $stabBlock = is_array($intel['stability'] ?? null) ? $intel['stability'] : []; ?>
                    <?php $freshBlock = is_array($intel['freshness'] ?? null) ? $intel['freshness'] : []; ?>
                    <td style="padding:6px" class="mono">
                      <?php if (is_numeric($scoreBlock['score'] ?? null)): ?>
                        <b title="<?= e((string) ($scoreBlock['label'] ?? '')) ?>"><?= (int) $scoreBlock['score'] ?><span class="dim">/100</span></b>
                        <span class="dim" style="font-size:10px"><?= e((string) ($scoreBlock['band'] ?? '')) ?></span>
                      <?php else: ?>
                        <span class="dim" title="<?= e((string) ($scoreBlock['note'] ?? 'No grade is published when the inputs that would form it are not stored.')) ?>">no score</span>
                      <?php endif; ?>
                      <?php if (($stabBlock['state'] ?? '') === \AIWorkforce\Football\StabilityMonitor::UNSTABLE): ?>
                        <div class="down" style="font-size:10px" title="<?= e((string) ($stabBlock['reason'] ?? '')) ?>">⚠ Prediction unstable — significant model movement</div>
                      <?php elseif (($stabBlock['state'] ?? '') === \AIWorkforce\Football\StabilityMonitor::MOVED): ?>
                        <div class="dim" style="font-size:10px" title="<?= e((string) ($stabBlock['reason'] ?? '')) ?>">moved <?= e(number_format((float) ($stabBlock['movementPoints'] ?? 0), 1)) ?>pp</div>
                      <?php elseif (($stabBlock['state'] ?? '') === \AIWorkforce\Football\StabilityMonitor::STABLE): ?>
                        <div class="dim" style="font-size:10px">stable</div>
                      <?php endif; ?>
                      <?php if (!empty($freshBlock['verdict'])): ?>
                        <div class="dim" style="font-size:10px" title="prediction · data · odds, each with its own clock"><?= e((string) $freshBlock['verdict']) ?></div>
                      <?php endif; ?>
                    </td>
                    <td style="padding:6px" class="mono">
                      <?= is_numeric($row['confidence'] ?? null) ? number_format((float) $row['confidence'], 1) . '%' : '—' ?>
                      <div class="dim" style="font-size:10px">DQ <?= (int) ($row['dataQuality'] ?? 0) ?>/100 · risk <?= e(strtolower((string) ($row['risk']['level'] ?? 'unknown'))) ?></div>
                    </td>
                  </tr>
                  <?php if (($row['analysisState'] ?? '') === 'ANALYZED'): ?>
                    <tr style="border-bottom:1px solid var(--line)">
                      <td colspan="7" style="padding:0 6px 8px">
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
                              <div class="dim">Model source</div>
                              <div class="mono"><?= e($windelsModelId) ?></div>
                              <div class="dim"><?= e((string) ($m['basis'] ?? '')) ?></div>
                            </div>
                            <div>
                              <div class="dim">Windels model</div>
                              <div class="mono"><?= e($windelsModelId) ?></div>
                              <div class="dim" style="margin-top:2px">Provider match identifiers are hidden from the operator view.</div>
                            </div>
                            <div>
                              <div class="dim">Valid until</div>
                              <div><?= e($kickoffStamp($m['expiresAt'] ?? null)) ?></div>
                              <div class="dim">frozen at kickoff · model v<?= e((string) ($m['modelVersion'] ?? '—')) ?></div>
                            </div>
                          </div>
                          <!-- The three separate questions, kept apart on purpose. -->
                          <?php $fairBlock = is_array($intel['fairValue'] ?? null) ? $intel['fairValue'] : []; ?>
                          <div style="display:flex;gap:18px;flex-wrap:wrap;margin-top:10px;padding-top:10px;border-top:1px solid var(--line);font-size:11px">
                            <div>
                              <div class="dim">WINDELS probability</div>
                              <div class="mono"><b><?= is_numeric($row['confidence'] ?? null) ? number_format((float) $row['confidence'], 1) . '%' : '—' ?></b> <span class="dim">what the data suggests</span></div>
                              <div class="dim">Data quality <?= (int) ($intel['quality']['score'] ?? 0) ?>/100 — <?= e((string) ($intel['quality']['band'] ?? '—')) ?></div>
                            </div>
                            <div>
                              <div class="dim">Market odds</div>
                              <div class="mono"><b><?= is_numeric($odds ?? null) ? number_format((float) $odds, 2) : 'no price quoted' ?></b> <span class="dim">the price offered</span></div>
                              <?php if (is_numeric($fairBlock['fairOdds'] ?? null)): ?>
                                <div class="dim">fair (margin removed) <?= number_format((float) $fairBlock['fairOdds'], 2) ?></div>
                              <?php else: ?>
                                <div class="dim"><?= e((string) ($fairBlock['note'] ?? 'no margin estimate')) ?></div>
                              <?php endif; ?>
                            </div>
                            <div>
                              <div class="dim">WINDELS Edge / Value</div>
                              <?php if (($fairBlock['state'] ?? '') === 'AVAILABLE' && is_numeric($fairBlock['expectedValue'] ?? null)): ?>
                                <b class="<?= (float) $fairBlock['expectedValue'] >= 0 ? 'up' : 'down' ?>"
                                   title="expected value: model probability × the price − 1"><?= ($fairBlock['expectedValue'] >= 0 ? '+' : '') . number_format((float) $fairBlock['expectedValue'] * 100, 1) ?>%</b>
                                <div class="mono"><?= e((string) ($fairBlock['valueLabel'] ?? '')) ?></div>
                                <div class="dim"><?= e((string) ($fairBlock['valueReason'] ?? '')) ?></div>
                              <?php else: ?>
                                <span class="dim"><?= e((string) ($fairBlock['state'] ?? 'UNPRICED')) ?></span>
                                <div class="dim"><?= e((string) ($fairBlock['note'] ?? 'no price to compare against')) ?></div>
                              <?php endif; ?>
                            </div>
                            <div>
                              <div class="dim">Why this selection</div>
                              <?php $driverRows = (array) ($intel['drivers']['drivers'] ?? []); ?>
                              <?php if ($driverRows === []): ?>
                                <div class="dim">No drivers are published for this match.</div>
                              <?php else: ?>
                                <ul style="margin:2px 0 0;padding-left:14px">
                                <?php foreach ($driverRows as $driver): ?>
                                  <li><?= e((string) ($driver['label'] ?? '')) ?>: <span class="dim" style="font-size:11px"><?= e((string) ($driver['detail'] ?? '')) ?></span></li>
                                <?php endforeach; ?>
                                </ul>
                                <div class="dim" style="margin-top:4px"><?= e((string) ($intel['drivers']['headline'] ?? '')) ?></div>
                              <?php endif; ?>
                            </div>
                          </div>
                          <!-- Last updated, as three clocks. One timestamp would answer a
                               question this panel is not asking: a prediction can be
                               current while the odds beside it are half an hour old. -->
                          <?php if (!empty($freshBlock['clocks'])): ?>
                            <div style="display:flex;gap:18px;flex-wrap:wrap;margin-top:10px;padding-top:10px;border-top:1px solid var(--line);font-size:11px">
                              <?php foreach ($freshBlock['clocks'] as $clock): ?>
                                <div>
                                  <div class="dim"><?= e((string) ($clock['label'] ?? '')) ?></div>
                                  <div><?= e((string) ($clock['ageLabel'] ?? '—')) ?> <span class="dim"><?= e((string) ($clock['state'] ?? '')) ?></span></div>
                                  <div class="dim"><?= e((string) ($clock['note'] ?? ($clock['meaning'] ?? ''))) ?></div>
                                </div>
                              <?php endforeach; ?>
                            </div>
                          <?php endif; ?>
                          <p class="dim" style="font-size:10px;margin:8px 0 0"><?= e((string) ($fairBlock['disclaimer'] ?? '')) ?></p>
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
                          <?php $cardPageId = (int) ($card['fixtureId'] ?? 0); ?>
                          <?php if ($cardPageId > 0): ?>
                            <a href="/football/match/<?= $cardPageId ?>" style="font-weight:700;font-size:15px"><?= e((string) ($card['predictedResultLabel'] ?? 'No prediction')) ?></a>
                          <?php else: ?>
                            <span style="font-weight:700;font-size:15px" title="This card has no stored fixture, so it has no match page."><?= e((string) ($card['predictedResultLabel'] ?? 'No prediction')) ?></span>
                          <?php endif; ?>
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
