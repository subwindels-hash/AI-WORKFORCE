<?php defined('BASEPATH') or exit('No direct script access allowed');
/** @var array $dashboard */
$d = $dashboard ?? [];
$sys = $d['systemStatus'] ?? [];
$today = $d['todayIntelligence'] ?? [];
$engine = $d['ticketEngine'] ?? [];
$perf = $d['performance'] ?? [];
$models = $d['models'] ?? [];
$ticket = $engine['ticket'] ?? null;
$daily = is_array($engine['today'] ?? null) ? $engine['today'] : null;
$generationStatus = $daily !== null
    ? (string) ($daily['generation_status'] ?? (!empty($daily['ticket_id']) ? 'GENERATED' : 'PENDING'))
    : 'PENDING';
$runRejectionSummary = is_array($daily['rejection_summary'] ?? null) ? $daily['rejection_summary'] : [];
$runDiag = $daily !== null && is_array($runRejectionSummary['_diagnostics'] ?? null)
    ? $runRejectionSummary['_diagnostics'] : [];
$dashboardRunMetrics = is_array($engine['runMetrics'] ?? null) ? $engine['runMetrics'] : [];
// The rail reports the latest stored generation funnel for the viewed date.
// A missing run remains unavailable; a recorded run with no rows is truthfully 0.
$runMetrics = $daily === null ? [
    'eligibleFixtures' => null,
    'fixturesEvaluated' => null,
    'predictionsGenerated' => null,
    'fixturesWithFreshOdds' => null,
    'fixturesRejectedStaleOdds' => null,
    'correlationQualifiedCandidates' => null,
    'finalQualifiedCandidates' => null,
] : [
    'eligibleFixtures' => $dashboardRunMetrics['eligibleFixtures'] ?? (int) ($runDiag['eligibleFixtures'] ?? 0),
    'fixturesEvaluated' => $dashboardRunMetrics['fixturesEvaluated'] ?? (int) ($runDiag['fixturesEvaluated'] ?? $daily['candidates_evaluated'] ?? 0),
    'predictionsGenerated' => $dashboardRunMetrics['predictionsGenerated'] ?? (int) ($runDiag['predictionsGenerated'] ?? $daily['predictions_recorded'] ?? 0),
    'fixturesWithFreshOdds' => $dashboardRunMetrics['fixturesWithFreshOdds'] ?? (int) ($runDiag['fixturesWithFreshOdds'] ?? 0),
    'fixturesRejectedStaleOdds' => $dashboardRunMetrics['fixturesRejectedStaleOdds'] ?? (int) ($runDiag['fixturesRejectedStaleOdds'] ?? 0),
    'correlationQualifiedCandidates' => $dashboardRunMetrics['correlationQualifiedCandidates'] ?? (int) ($runDiag['correlationQualifiedCandidates'] ?? 0),
    'finalQualifiedCandidates' => $dashboardRunMetrics['finalQualifiedCandidates'] ?? (int) ($runDiag['finalQualifiedCandidates'] ?? count((array) ($engine['ticketSelections'] ?? []))),
];
$runCount = static fn(mixed $value): string => is_numeric($value) ? number_format((int) $value) : '—';
$windelsModelId = 'Windels Model id: 1520863';
$selByName = [];
foreach (array_merge($today['upcoming'] ?? [], $today['live'] ?? []) as $m) {
    $selByName[(int) ($m['id'] ?? 0)] = ($m['home_team'] ?? '?') . ' vs ' . ($m['away_team'] ?? '?');
}
$disabled = ($sys['ticketEngine'] ?? '') === 'DISABLED_NO_PROVIDER';
// Provider readiness: how many feeds can serve data right now, and whether
// the prediction engine is READY (≥1) or BLOCKED (0). Computed server-side
// from live health + circuit-breaker state; never guessed here.
$readiness = is_array($sys['readiness'] ?? null) ? $sys['readiness'] : ['operational' => 0, 'total' => 0, 'engine' => $disabled ? 'DISABLED_NO_PROVIDER' : 'UNKNOWN', 'providers' => []];
$statusDot = static function (string $st): string {
    return match ($st) {
        'ONLINE' => 'up',
        'DEGRADED', 'RATE_LIMITED', 'TIMEOUT' => 'synth',
        default => 'down',
    };
};
$statusLabel = static function (string $st): string {
    return match ($st) {
        'DAILY_QUOTA_EXHAUSTED' => 'Daily quota exhausted',
        'RATE_LIMITED' => 'Rate limited',
        'AUTHENTICATION_ERROR' => 'Auth failed',
        'BAD_REQUEST' => 'HTTP 400 (bad request)',
        'NOT_FOUND' => 'HTTP 404 (not found)',
        'TIMEOUT' => 'Timeout',
        'OFFLINE' => 'Offline',
        'DATA_ERROR' => 'Data error',
        'DEGRADED' => 'Degraded',
        'ONLINE' => 'Online',
        default => $st,
    };
};
// Capabilities of the signed-in identity (controller reads them fresh from the
// database). Without them the console shows a disabled control plus the reason,
// instead of a button that is refused after the click.
$caps = $caps ?? ['sync' => false, 'approve' => false, 'settle' => false];
// Provider identities, vendor health, circuit state and quota figures are
// operator diagnostics (sports.manage). A read-only user sees only whether
// data is available — never which vendor, key tier or endpoint is behind it.
$operator = !empty($caps['sync']);
// The day being viewed (?date=YYYY-MM-DD, default today). The controller
// validates it; the fallbacks keep direct renders (tests, embeds) working when
// the navigation variables are absent.
$viewDateIso = (string) ($date ?? $today['date'] ?? gmdate('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $viewDateIso)) $viewDateIso = gmdate('Y-m-d');
$viewYesterday = (string) ($yesterday ?? gmdate('Y-m-d', strtotime($viewDateIso . ' -1 day')));
$viewTomorrow = (string) ($tomorrow ?? gmdate('Y-m-d', strtotime($viewDateIso . ' +1 day')));
$viewIsToday = (bool) ($isToday ?? ($viewDateIso === gmdate('Y-m-d')));
// Ticket generation uses the day being viewed; the value rides along as a
// hidden field so the button row stays clean (Sync now · 🎯 Odds Prediction
// Ticket). Other days can still be generated by first viewing them.
$ticketDateIso = $viewDateIso;
// Same configured-local calendar day as the operator reads it off the ticket
// (MM/DD/YYYY). It sits next to the button so the intended day is explicit.
$ticketDateStamp = strtotime($viewDateIso . ' 00:00:00 UTC');
$ticketDateShown = $ticketDateStamp === false ? $viewDateIso : gmdate('m/d/Y', $ticketDateStamp);
// Match date + time as one UTC stamp (`YYYY-MM-DD HH:MM`) from the stored
// kickoff. A match the provider gave no kickoff for prints — : never a blank
// cell, never 00:00, because a guessed time would read as a real one.
$kickoffStamp = static function (mixed $iso): string {
    $ts = is_string($iso) && trim($iso) !== '' ? strtotime($iso) : false;
    return $ts === false ? '—' : gmdate('Y-m-d H:i', $ts);
};
?>
<div class="sports-console">
  <section class="sports-hero" aria-labelledby="sports-heading">
    <div class="sports-hero__intro">
      <p class="sports-eyebrow">Daily sports intelligence</p>
      <h2 id="sports-heading">Sports Intelligence — odds prediction ticket engine</h2>
      <p class="sports-hero__copy">One workflow, read top to bottom: the fixtures stored for the selected day, the ticket the engine built from them, the matches in play, and the measured result of everything already settled. Bookmaker prices and WINDELS probabilities are always reported side by side and never mixed. No bet is placed from this screen.</p>
    </div>
    <nav class="sports-hero__actions" aria-label="Sports date navigation">
      <a class="btn small" href="/sports?date=<?= e($viewYesterday) ?>">← Previous day</a>
      <?php if (!$viewIsToday): ?><a class="btn small" href="/sports">Today</a><?php endif; ?>
      <a class="btn small" href="/sports?date=<?= e($viewTomorrow) ?>">Next day →</a>
    </nav>
  </section>

  <section class="sports-actionbar" aria-label="Sports dashboard controls">
    <form method="get" action="/sports" class="sports-date-form">
      <label for="sports-view-date">Viewing date (UTC)</label>
      <input class="sel" type="date" id="sports-view-date" name="date" value="<?= e($viewDateIso) ?>" onchange="this.form.submit()" title="Viewing date (UTC)">
      <noscript><button class="btn small" type="submit">View</button></noscript>
    </form>
    <div class="sports-actionbar__links">
      <?php if (!empty($caps['sync'])): ?>
        <form method="post" action="/sports/sync" onsubmit="return confirm('Pull fresh fixtures, odds and results from the configured providers now?')">
          <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
          <button class="btn primary small">Sync now</button>
        </form>
      <?php else: ?>
        <button class="btn small" disabled title="Requires the sports.manage permission">Sync now</button>
      <?php endif; ?>
      <a class="btn small" href="/football">Football match &amp; full odds board</a>
      <a class="btn small" href="/sports/odds-prediction-ticket">Ticket history</a>
    </div>
  </section>

  <div class="sports-notes">
    <p class="sports-context-note">The selected date drives the day overview, the prediction run and the ticket below; the live board always reports current play. Automatic daily generation stays active — the ticket section only adds an optional immediate run or a clean retry.</p>
    <?php if (empty($caps['sync'])): ?><p class="sports-context-note">This account has read-only access. Ask an administrator for the Sports administrator role to sync data or generate a ticket.</p><?php endif; ?>
  </div>

<?php if (!empty($notice)): ?><div class="notice ok"><?= e($notice) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="notice err"><?= e($error) ?></div><?php endif; ?>
<?php if (!empty($sys['isDemoData'])): ?>
  <div class="notice warnbox"><b>SANDBOX / DEMO DATA</b> — sports figures are simulated, not real-world performance.</div>
<?php endif; ?>
<?php if ($disabled): ?>
  <?php $setup = is_array($sys['providerSetup'] ?? null) ? $sys['providerSetup'] : []; ?>
  <div class="notice warnbox">
    <b>No sports data provider connected.</b> Live fixtures and predictions are unavailable until a verified data source is configured — nothing is fabricated in the meantime.
    <?php if ($operator && $setup): ?>
      <br><b>Next step:</b> <?= e((string) ($setup['nextStep'] ?? '')) ?>
      <?php if (!empty($setup['options'])): ?>
        <div class="sports-setup-options">Connect any one of these in <a href="/admin/api"><b>Admin → API</b></a> (Service: <span class="mono">sports</span>):
          <ul>
          <?php foreach ((array) $setup['options'] as $opt): ?>
            <li><b><?= e((string) ($opt['label'] ?? '')) ?></b>
              — <?= e((string) ($opt['note'] ?? '')) ?>
              <span class="dim">(or set <span class="mono"><?= e((string) ($opt['primaryEnvKey'] ?? '')) ?></span><?= !empty($opt['environmentConfigured']) ? ' — already present in this environment' : '' ?>)</span>
            </li>
          <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
<?php elseif (($readiness['engine'] ?? '') === 'BLOCKED'): ?>
  <?php if ($operator): ?>
  <div class="notice err"><b>Prediction engine BLOCKED — 0/<?= (int) ($readiness['total'] ?? 0) ?> sports data providers operational.</b>
    Every configured feed is currently failing (see <i>Data feed</i>). Odds prediction tickets cannot be generated until at least one provider recovers; an empty day in this state is a <b>data outage</b>, not "no qualified games".
    <?php foreach (array_values((array) ($readiness['providers'] ?? [])) as $i => $pr): ?><br><span class="mono sports-cell-detail"><?= e($windelsModelId) ?> · feed <?= (int) $i + 1 ?> → <?= e((string) ($pr['status'] ?? 'UNKNOWN')) ?><?php if (!empty($pr['retryAt'])): ?> (retry after <?= e(substr((string) $pr['retryAt'], 0, 16)) ?>Z)<?php endif; ?></span><?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="notice err"><b>Sports data temporarily unavailable.</b> Odds prediction tickets cannot be generated until the data feed recovers — an empty day in this state is a data outage, not "no qualified games".</div>
  <?php endif; ?>
<?php endif; ?>

<div class="sports-layout">
  <div class="sports-main stack">
    <section class="panel sports-section" id="sports-overview" aria-labelledby="sports-overview-heading">
      <div class="sports-section__heading">
        <div class="sports-section__title">
          <span class="sports-step" aria-hidden="true">1</span>
          <div>
            <p class="sports-eyebrow">Day overview</p>
            <h3 id="sports-overview-heading"><?= $viewIsToday ? "Today's intelligence" : 'Intelligence' ?> — <?= e((string) ($today['date'] ?? $viewDateIso)) ?></h3>
          </div>
        </div>
        <span class="sports-section__meta">stored fixtures · UTC</span>
      </div>
      <div class="body">
        <p class="sports-section-intro">What is actually stored for this day before any ticket is built: how many fixtures are scheduled, how many are in play, how many predictions passed or failed the engine's gates, and how the qualified ones are spread across risk bands.</p>
        <div class="stat-grid">
          <div class="stat"><div class="k">Scheduled</div><div class="v"><?= (int) ($today['upcomingCount'] ?? 0) ?></div></div>
          <div class="stat"><div class="k">Live</div><div class="v" id="live-count-stat"><?= count($today['live'] ?? []) ?></div></div>
          <div class="stat"><div class="k">Qualified predictions</div><div class="v up"><?= (int) ($today['qualifiedPredictions'] ?? 0) ?></div></div>
          <div class="stat"><div class="k">Rejected predictions</div><div class="v down"><?= (int) ($today['rejectedPredictions'] ?? 0) ?></div></div>
          <div class="stat"><div class="k">Avg confidence</div><div class="v"><?= ($today['averageConfidence'] ?? null) !== null ? e(number_format((float) $today['averageConfidence'], 1)) . '%' : '—' ?></div></div>
        </div>
        <?php $risk = $today['riskDistribution'] ?? []; if (array_sum($risk) > 0): ?>
          <div class="sports-subhead"><h4>Risk distribution</h4><span class="dim">predictions scored for this day</span></div>
          <div class="sports-meters">
            <?php foreach ([['LOW', 'var(--green)'], ['MEDIUM', 'var(--amber)'], ['HIGH', 'var(--red)'], ['REJECTED', 'var(--muted)']] as [$k, $c]): ?>
              <div class="meter">
                <div class="row"><span>Risk <?= e($k) ?></span><span class="mono dim"><?= (int) ($risk[$k] ?? 0) ?></span></div>
                <div class="bar"><div style="width:<?= round(100 * (($risk[$k] ?? 0) / max(1, array_sum($risk)))) ?>%;background:<?= $c ?>"></div></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <?php if (!empty($today['upcoming'])): ?>
          <div class="sports-subhead"><h4>Scheduled fixtures</h4><span class="dim"><?= (int) ($today['upcomingCount'] ?? count($today['upcoming'])) ?> stored for this day</span></div>
          <div class="table-scroll">
            <table class="tbl">
              <thead><tr><th>Kickoff (UTC)</th><th>Match</th><th>Competition</th><th class="num">Data quality</th></tr></thead>
              <tbody>
                <?php $qByMatch = []; foreach ($today['dataQuality'] ?? [] as $q) $qByMatch[(int) $q['matchId']] = $q; ?>
                <?php foreach ($today['upcoming'] as $m): $q = $qByMatch[(int) ($m['id'] ?? 0)] ?? null; ?>
                  <tr>
                    <td class="mono dim"><?= e(substr((string) ($m['kickoff_at'] ?? ''), 0, 16)) ?></td>
                    <td class="sports-cell-strong"><?= e(($m['home_team'] ?? '?') . ' vs ' . ($m['away_team'] ?? '?')) ?></td>
                    <td class="dim"><?= e((string) ($m['competition'] ?? '')) ?></td>
                    <td class="num"><?= $q ? e($q['band'] . ' · ' . (int) $q['score']) : '<span class="dim">not assessed</span>' ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <p class="sports-empty">No scheduled fixtures stored for <?= $viewIsToday ? 'today' : e($viewDateIso) ?>.</p>
        <?php endif; ?>
      </div>
    </section>

    <section class="panel sports-section sports-ticket-panel" id="sports-ticket" aria-labelledby="sports-ticket-heading">
      <div class="sports-section__heading">
        <div class="sports-section__title">
          <span class="sports-step" aria-hidden="true">2</span>
          <div>
            <p class="sports-eyebrow">Engine output</p>
            <h3 id="sports-ticket-heading"><?= $viewIsToday ? "Today's odds prediction ticket" : 'Odds prediction ticket — ' . e($viewDateIso) ?></h3>
          </div>
        </div>
        <span class="badge <?= $generationStatus === 'GENERATED' ? 'b-violet' : ($generationStatus === 'FAILED' ? 'b-red' : 'b-gray') ?>">generation <?= e($generationStatus) ?></span>
      </div>
      <div class="body">
        <p class="sports-section-intro">The engine scores every supported market and quoted selection on each eligible match, then keeps only independently qualified, low-correlation picks. Every selected row states the match, the market, the real bookmaker price with its source, the implied chance, the WINDELS probability and fair odds, the value, confidence, data quality and risk.</p>
        <?php if (is_array($daily) && (!empty($daily['attempt_count']) || !empty($daily['next_retry_at']) || !empty($daily['last_error_code']))): ?>
          <div class="sports-chips">
            <?php if (!empty($daily['attempt_count'])): ?><span class="dim mono">attempt <?= (int) $daily['attempt_count'] ?></span><?php endif; ?>
            <?php if (!empty($daily['next_retry_at'])): ?><span class="dim mono">next retry <?= e((string) $daily['next_retry_at']) ?></span><?php endif; ?>
            <?php if (!empty($daily['last_error_code'])): ?><span class="badge b-red"><?= e((string) $daily['last_error_code']) ?></span><?php endif; ?>
          </div>
        <?php endif; ?>
        <div class="sports-controls">
          <?php if (!empty($caps['sync'])): ?>
            <form method="post" action="/sports/generate-ticket" class="sports-controls__form" onsubmit="return confirm('Generate odds prediction ticket for <?= e($viewDateIso) ?> from stored data?')">
              <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
              <input type="hidden" name="date" value="<?= e($ticketDateIso) ?>">
              <button class="btn small sports-generate-btn">
                🎯 Odds Prediction Ticket
              </button>
              <label class="sports-controls__check" title="First delete this day's active candidates (old pass predictions, the pending ticket, daily slot, unquotable odds), then generate from the current stored pool. Settled/historical records are kept.">
                <input type="checkbox" name="force" value="1"> Force fresh run
              </label>
            </form>
            <form method="post" action="/sports/reset-candidates" onsubmit="return confirm('Clear the ACTIVE candidate state for <?= e($viewDateIso) ?>? Settled/historical records are preserved.')">
              <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
              <input type="hidden" name="date" value="<?= e($ticketDateIso) ?>">
              <button class="btn small" title="Delete active (not historical) candidates for the selected date">♻️ Clear</button>
            </form>
            <span class="mono sports-controls__date" title="Configured-local ticket date <?= e($ticketDateIso) ?>"><?= e($ticketDateShown) ?></span>
            <span class="sports-controls__note">optional manual run/retry — automatic daily generation remains active</span>
          <?php else: ?>
            <button class="btn small sports-generate-btn" disabled title="Requires the sports.manage permission">🎯 Odds Prediction Ticket</button>
            <span class="mono sports-controls__date" title="Configured-local ticket date <?= e($ticketDateIso) ?>"><?= e($ticketDateShown) ?></span>
          <?php endif; ?>
        </div>
        <?php if ($daily !== null && (string) ($daily['status'] ?? '') === 'DATA_UNAVAILABLE'): ?>
          <div class="sports-subhead"><h4>Result</h4><span class="badge b-red">NO TICKET — DATA_UNAVAILABLE</span></div>
          <?php if ($operator): ?>
          <p class="sports-empty"><b>All configured sports-data providers failed</b> for this run — this is a data outage, not a day without qualifying games. <?= e((string) ($daily['message'] ?? '')) ?></p>
          <?php $ledger = is_array($daily['rejection_summary'] ?? null) ? $daily['rejection_summary'] : []; ?>
          <div class="table-scroll">
            <table class="tbl">
              <thead><tr><th>Provider</th><th>Status</th></tr></thead>
              <tbody>
                <?php foreach ($ledger as $k => $v): if (!str_starts_with((string) $k, 'PROVIDER:')) continue; ?>
                  <tr><td class="mono"><?= e(substr((string) $k, 9)) ?></td><td><span class="dot down"></span> <?= e($statusLabel((string) $v)) ?></td></tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <p class="sports-note">Matches evaluated: <?= (int) ($daily['candidates_evaluated'] ?? 0) ?> · Predictions generated: <?= (int) ($daily['predictions_recorded'] ?? 0) ?>. The run stays retryable: once a provider recovers, Sync and generate again.</p>
          <?php else: ?>
          <p class="sports-empty"><b>Sports data was unavailable</b> for this run — this is a data outage, not a day without qualifying games. No odds prediction ticket is fabricated; one will be built once the data feed recovers.</p>
          <?php endif; ?>
        <?php elseif ($daily === null || $ticket === null): ?>
          <div class="sports-subhead"><h4>Result</h4><span class="dim">no ticket for this day</span></div>
          <p class="sports-empty"><?= $daily !== null ? e((string) ($daily['message'] ?? 'No odds prediction ticket for ' . $viewDateIso . '.')) : 'No daily run recorded for ' . e($viewDateIso) . ' yet. Automatic daily generation is pending; the scheduler will sync eligible data and generate without a manual click, while 🎯 remains an optional immediate retry.' ?></p>
          <?php
            $diag = [];
            if ($daily !== null && is_array($daily['rejection_summary'] ?? null) && is_array($daily['rejection_summary']['_diagnostics'] ?? null)) {
                $diag = $daily['rejection_summary']['_diagnostics'];
            }
          ?>
          <?php if (!empty($diag['fixturesEvaluated']) && (int) $diag['fixturesEvaluated'] > 0): ?>
            <details class="sports-diagnostics">
              <summary><span><b>Generation funnel &amp; rejection details</b><small><?= (int) ($diag['fixturesEvaluated'] ?? 0) ?> fixtures · <?= (int) ($diag['marketsEvaluated'] ?? 0) ?> market selections evaluated · <?= (int) ($diag['finalQualifiedCandidates'] ?? 0) ?> selected</small></span><span>Open diagnostics</span></summary>
            <div class="stat-grid">
              <div class="stat"><div class="k">Fixtures evaluated</div><div class="v"><?= (int) ($diag['fixturesEvaluated'] ?? 0) ?></div></div>
              <div class="stat"><div class="k">Eligible</div><div class="v"><?= (int) ($diag['eligibleFixtures'] ?? 0) ?></div></div>
              <div class="stat"><div class="k">Fresh odds</div><div class="v"><?= (int) ($diag['fixturesWithFreshOdds'] ?? 0) ?></div></div>
              <div class="stat" title="Fixtures carrying verified recentForm after form enrichment<?= is_array($diag['formResolver'] ?? null) ? ' — lookups ' . (int) ($diag['formResolver']['lookupsUsed'] ?? 0) . '/' . (int) ($diag['formResolver']['budget'] ?? 0) . ', failures ' . (int) ($diag['formResolver']['lookupFailures'] ?? 0) . ', budget skips ' . (int) ($diag['formResolver']['budgetSkips'] ?? 0) . (empty($diag['formResolver']['providerCapable']) ? ', provider has no team-statistics endpoint' : '') : '' ?>"><div class="k">Form resolved</div><div class="v"><?= (int) ($diag['fixturesWithRecentForm'] ?? 0) ?><?= (int) ($diag['formEnrichmentCandidates'] ?? 0) > 0 ? ' / ' . (int) $diag['formEnrichmentCandidates'] : '' ?></div></div>
              <?php if ((int) ($diag['fixturesWithCarriedForwardForm'] ?? 0) > 0): ?>
                <div class="stat" title="Fixtures reusing recentForm a previous run already verified (inside the form TTL, original source and timestamp kept) — no provider request was spent on them"><div class="k">Form carried forward</div><div class="v"><?= (int) $diag['fixturesWithCarriedForwardForm'] ?></div></div>
              <?php endif; ?>
              <div class="stat"><div class="k">Sufficient data</div><div class="v"><?= (int) ($diag['sufficientDataFixtures'] ?? 0) ?></div></div>
              <div class="stat" title="Distinct market:selection candidates scored across the full stored pool (one real odds row each)"><div class="k">Markets evaluated</div><div class="v"><?= (int) ($diag['marketsEvaluated'] ?? 0) ?></div></div>
              <div class="stat" title="FEED gap: no price at all was held or fetched for the fixture — worth a retry"><div class="k">No real odds</div><div class="v"><?= (int) ($diag['fixturesRejectedNoOdds'] ?? 0) ?></div></div>
              <?php if ((int) ($diag['fixturesRejectedMarketUnavailable'] ?? 0) > 0): ?>
                <?php
                  $unsupported = (array) ($diag['unsupportedMarketsQuoted'] ?? []);
                  arsort($unsupported);
                  $topUnsupported = array_slice(array_keys($unsupported), 0, 4);
                ?>
                <div class="stat" title="COVERAGE gap, not a feed problem: the bookmaker priced these fixtures but not a market this engine can price&#10;&#10;Quoted instead: <?= e($topUnsupported ? implode(', ', $topUnsupported) : 'companion prices only') ?>"><div class="k">Market not offered</div><div class="v"><?= (int) $diag['fixturesRejectedMarketUnavailable'] ?></div></div>
              <?php endif; ?>
              <div class="stat" title="Fixtures with real odds older than the configured TTL that no provider could refresh"><div class="k">Stale odds</div><div class="v"><?= (int) ($diag['fixturesRejectedStaleOdds'] ?? 0) ?></div></div>
              <div class="stat"><div class="k">Predictions</div><div class="v"><?= (int) ($diag['predictionsGenerated'] ?? 0) ?></div></div>
              <div class="stat"><div class="k">Confidence ≥ floor</div><div class="v"><?= (int) ($diag['confidenceQualifiedCandidates'] ?? 0) ?></div></div>
              <div class="stat"><div class="k">Positive value</div><div class="v"><?= (int) ($diag['positiveValueCandidates'] ?? 0) ?></div></div>
              <div class="stat"><div class="k">Risk qualified</div><div class="v"><?= (int) ($diag['riskQualifiedCandidates'] ?? 0) ?></div></div>
              <div class="stat"><div class="k">Qualified candidates</div><div class="v"><?= (int) ($diag['correlationQualifiedCandidates'] ?? 0) ?></div></div>
              <div class="stat"><div class="k">Selected picks</div><div class="v"><?= (int) ($diag['finalQualifiedCandidates'] ?? 0) ?></div></div>
              <?php if ((int) ($diag['fixturesDeferred'] ?? 0) > 0): ?>
                <div class="stat" title="Fixtures past the <?= (int) ($diag['generationCap'] ?? 50) ?>-per-generation cap with no reusable stored prediction; named below, never silently dropped"><div class="k">Deferred (cap <?= (int) ($diag['generationCap'] ?? 50) ?>)</div><div class="v"><?= (int) $diag['fixturesDeferred'] ?></div></div>
              <?php endif; ?>
            </div>
            <?php
              // Fixtures that failed the sufficient-data gate: the concrete
              // requirement, the missing/stale field and the provider — one
              // primary blocker per fixture, named instead of a bare zero.
              $gateFixtures = is_array($diag['sufficientDataGate']['fixtures'] ?? null) ? $diag['sufficientDataGate']['fixtures'] : [];
              $gateFailures = array_values(array_filter($gateFixtures, fn($g) => empty($g['passed'])));
            ?>
            <?php if ($gateFailures !== []): ?>
            <div class="table-scroll">
              <table class="tbl">
                <thead><tr><th>Fixture</th><th>Provider</th><th>Primary blocker</th><th>Missing / failed field</th></tr></thead>
                <tbody>
                  <?php foreach (array_slice($gateFailures, 0, 25) as $g):
                    $reqs = is_array($g['requirements'] ?? null) ? $g['requirements'] : [];
                    $detail = '—';
                    $failedKey = (string) ($g['failedRequirement'] ?? '');
                    if (isset($reqs[$failedKey])) {
                        $rq = $reqs[$failedKey];
                        if ($failedKey === 'MANDATORY_MODEL_DATA') $detail = implode(', ', array_map('e', (array) ($rq['missingMandatory'] ?? []))) ?: 'recentForm';
                        elseif ($failedKey === 'DATA_QUALITY_FLOOR') $detail = 'quality ' . (int) ($rq['score'] ?? 0) . ' / floor ' . (int) ($rq['minScore'] ?? 0) . ' (' . e((string) ($rq['band'] ?? '?')) . ')';
                        elseif ($failedKey === 'APPROVED_CALIBRATION') $detail = 'no APPROVED model calibration';
                    }
                  ?>
                    <tr>
                      <td class="sports-cell-strong"><?= e((string) ($g['homeTeam'] ?? '?')) ?> vs <?= e((string) ($g['awayTeam'] ?? '?')) ?><small class="dim"><?= e((string) ($g['competition'] ?? '')) ?></small></td>
                      <td class="mono"><?= e((string) ($g['provider'] ?? '—')) ?></td>
                      <td class="mono"><?= e((string) ($g['primaryReason'] ?? $failedKey)) ?></td>
                      <td class="mono sports-cell-detail"><?= $detail /* pre-escaped above / integers */ ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
              <?php if ((int) ($diag['sufficientDataGate']['limit'] ?? 0) > 0 && count($gateFailures) > 25): ?><p class="sports-note">Showing 25 of <?= count($gateFailures) ?> blocked fixtures; the funnel counts above cover the full pool.</p><?php endif; ?>
            </div>
            <?php endif; ?>
            <?php $deferredRows = is_array($diag['deferredFixtures']['rows'] ?? null) ? $diag['deferredFixtures']['rows'] : []; if ($deferredRows !== []): ?>
            <p class="sports-note">Deferred past the generation cap (evaluated in later runs; not rejected):
              <?php
                $parts = [];
                foreach (array_slice($deferredRows, 0, 15) as $d) $parts[] = e((string) ($d['provider'] ?? '?')) . ' ' . e((string) ($d['externalId'] ?? '?'));
                echo implode('; ', $parts);
                if (!empty($diag['deferredFixtures']['truncated'])) echo '; …';
              ?>
            </p>
            <?php endif; ?>
            <?php $reasons = is_array($diag['topRejectionReasons'] ?? null) ? $diag['topRejectionReasons'] : []; ?>
            <?php $byProvider = is_array($diag['rejectionReasonsByProvider'] ?? null) ? $diag['rejectionReasonsByProvider'] : []; ?>
            <?php if ($reasons): ?>
            <div class="table-scroll">
              <table class="tbl">
                <thead><tr><th>Rejection reason (primary)</th><th class="num">Count</th><th>Caused by</th></tr></thead>
                <tbody>
                  <?php foreach ($reasons as $reason => $count): ?>
                    <tr>
                      <td class="mono"><?= e((string) $reason) ?></td>
                      <td class="num mono"><?= (int) $count ?></td>
                      <td class="mono sports-cell-detail"><?php
                        $prov = $byProvider[(string) $reason] ?? [];
                        $provParts = [];
                        foreach ((array) $prov as $p => $n) $provParts[] = e((string) $p) . ' ×' . (int) $n;
                        echo $provParts ? implode(', ', $provParts) : '—';
                      ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <p class="sports-note">Odds TTL: <?= (int) ($diag['thresholds']['oddsMaxAgeSeconds'] ?? 0) ?>s · refresh attempts: <?= (int) ($diag['oddsRefreshAttempts'] ?? 0) ?> · refreshed fixtures: <?= (int) ($diag['oddsRefreshedFixtures'] ?? 0) ?><?php if (!empty($diag['oddsProviderFailureStatuses'])): ?> · odds provider failures: <?= e(implode(', ', array_map(fn($p, $s) => $p . ' ' . $s, array_keys($diag['oddsProviderFailureStatuses']), $diag['oddsProviderFailureStatuses']))) ?><?php endif; ?></p>
            <?php endif; ?>
            </details>
          <?php endif; ?>
        <?php else: ?>
          <div class="sports-subhead">
            <h4>Ticket</h4>
            <span class="sports-chips">
              <span class="badge <?= (string) ($daily['status'] ?? '') === 'PENDING_USER_APPROVAL' ? 'b-violet' : 'b-green' ?>"><?= e((string) ($daily['status'] ?? '')) ?></span>
              <span class="badge b-gray">engine <?= e((string) ($engine['configuration']['engine_mode'] ?? '')) ?></span>
            </span>
          </div>
          <div class="stat-grid">
            <div class="stat"><div class="k">Ticket ID</div><div class="v mono v-sm"><?= e((string) ($ticket['id'] ?? '—')) ?></div></div>
            <div class="stat"><div class="k">Generated at</div><div class="v mono v-sm"><?= e((string) ($daily['generated_at'] ?? $ticket['created_at'] ?? '—')) ?></div></div>
            <div class="stat"><div class="k">Total odds</div><div class="v"><?= e(number_format((float) ($ticket['total_odds'] ?? 0), 2)) ?></div></div>
            <div class="stat"><div class="k">Selections</div><div class="v"><?= (int) ($ticket['selection_count'] ?? 0) ?></div></div>
            <div class="stat"><div class="k">Confidence</div><div class="v"><?= ($ticket['confidence'] ?? null) !== null ? e(number_format((float) $ticket['confidence'], 0)) . '%' : '—' ?></div></div>
            <div class="stat"><div class="k">Stake / unit</div><div class="v"><?= ($ticket['stake'] ?? null) !== null ? e(number_format((float) $ticket['stake'], 2)) : '—' ?></div></div>
          </div>
          <?php if (!empty($engine['ticketSelections'])): ?>
            <div class="sports-subhead"><h4>Selected picks</h4><span class="dim"><?= count((array) $engine['ticketSelections']) ?> selection(s) · bookmaker price and model number kept in separate columns</span></div>
            <div class="table-scroll">
              <table class="tbl sports-ticket-table">
                <thead><tr><th>Match · competition · kickoff</th><th>Market · prediction</th><th title="Real bookmaker price, implied probability, source and provider timestamp">Real odds · source</th><th title="WINDELS model probability and fair odds — the model's own numbers, never the bookmaker price">WINDELS probability · fair</th><th>Confidence · quality</th><th>Edge / value</th><th>Risk</th></tr></thead>
                <tbody>
                  <?php foreach ($engine['ticketSelections'] as $s): ?>
                    <?php
                      $selectionOdds = is_numeric($s['odds'] ?? null) ? (float) $s['odds'] : null;
                      $selectionProbability = is_numeric($s['calibrated_probability'] ?? null) ? (float) $s['calibrated_probability'] : null;
                      $selectionImplied = $selectionOdds !== null && $selectionOdds > 1 ? 1 / $selectionOdds : null;
                      $selectionEdgePoints = $selectionProbability !== null && $selectionImplied !== null
                          ? ($selectionProbability - $selectionImplied) * 100 : null;
                    ?>
                    <tr>
                      <td class="sports-ticket-match">
                        <b><?= e(trim((string) (($s['home_team'] ?? '?') . ' vs ' . ($s['away_team'] ?? '?')))) ?></b>
                        <small><?= e((string) ($s['competition'] ?? '—')) ?></small>
                        <small class="mono"><?= e($kickoffStamp($s['kickoff_time'] ?? null)) ?> UTC</small>
                      </td>
                      <td><small><?= e((string) ($s['market'] ?? '')) ?></small><b><?= e((string) ($s['selection'] ?? '?')) ?></b></td>
                      <td class="sports-ticket-metric">
                        <b class="mono"><?= $selectionOdds !== null ? e(number_format($selectionOdds, 2)) : '—' ?></b>
                        <small class="mono">implied / break-even <?= $selectionImplied !== null ? e(number_format($selectionImplied * 100, 1)) . '%' : '—' ?></small>
                        <small><?= e((string) ($s['odds_source'] ?? 'Source unavailable')) ?></small>
                        <small class="mono"><?= e(substr((string) ($s['odds_timestamp'] ?? ''), 0, 16)) ?> UTC</small>
                      </td>
                      <td class="sports-ticket-metric">
                        <b class="mono"><?= $selectionProbability !== null ? e(number_format($selectionProbability * 100, 1)) . '%' : '—' ?></b>
                        <small class="mono">fair odds <?= ($s['fair_odds'] ?? null) !== null ? e(number_format((float) $s['fair_odds'], 2)) : '—' ?></small>
                        <?php if (is_numeric($s['model_probability'] ?? null) && (float) $s['model_probability'] !== $selectionProbability): ?><small class="mono">raw <?= e(number_format((float) $s['model_probability'] * 100, 1)) ?>%</small><?php endif; ?>
                      </td>
                      <td class="sports-ticket-metric"><b class="mono"><?= ($s['confidence'] ?? null) !== null ? e(number_format((float) $s['confidence'], 0)) . '%' : '—' ?></b><small>data quality <?= ($s['data_quality'] ?? null) !== null ? e(number_format((float) $s['data_quality'], 0)) . '/100' : '—' ?></small></td>
                      <td class="sports-ticket-metric <?= ($s['expected_value'] ?? 0) >= 0 ? 'up' : 'down' ?>"><b class="mono"><?= ($s['expected_value'] ?? null) !== null ? e(number_format((float) $s['expected_value'] * 100, 2)) . '% EV' : '—' ?></b><small class="mono">model edge <?= $selectionEdgePoints !== null ? e(($selectionEdgePoints >= 0 ? '+' : '') . number_format($selectionEdgePoints, 2)) . 'pp' : '—' ?></small></td>
                      <td><span class="badge <?= (string) ($s['risk'] ?? '') === 'LOW' ? 'b-green' : ((string) ($s['risk'] ?? '') === 'HIGH' ? 'b-red' : 'b-gray') ?>"><?= e((string) ($s['risk'] ?? '—')) ?></span><small>status <?= e((string) ($s['status'] ?? 'PENDING')) ?></small></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
          <?php if ((string) ($ticket['approval_status'] ?? '') === 'PENDING_USER_APPROVAL'): ?>
            <div class="sports-subhead"><h4>Review</h4><span class="dim">recorded against the acting identity</span></div>
            <?php if (!empty($caps['approve'])): ?>
              <div class="sports-actions">
                <form method="post" action="/sports/<?= e((string) $ticket['id']) ?>/decide">
                  <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
                  <input type="hidden" name="approve" value="1">
                  <button class="btn primary small">Approve (sports.approve)</button>
                </form>
                <form method="post" action="/sports/<?= e((string) $ticket['id']) ?>/decide" onsubmit="return confirm('Reject this record?')">
                  <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
                  <input type="hidden" name="approve" value="0">
                  <button class="btn danger small">Reject</button>
                </form>
              </div>
              <p class="sports-note">Approval is recorded with the acting identity. There is no external execution connector — approval never places a bet.</p>
            <?php else: ?>
              <div class="sports-actions">
                <button class="btn small" disabled title="Requires the sports.approve permission">Approve / reject (needs sports.approve)</button>
              </div>
              <p class="sports-note">Your account cannot approve records — ask an administrator for the <b>sports.approve</b> permission (Sports administrator role).</p>
            <?php endif; ?>
          <?php endif; ?>
          <?php if ((string) ($ticket['settlement_status'] ?? '') === 'PENDING'): ?>
            <?php if (!empty($caps['settle'])): ?>
              <div class="sports-actions">
                <form method="post" action="/sports/<?= e((string) $ticket['id']) ?>/settle">
                  <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
                  <button class="btn small">Settle from verified results (sports.settle)</button>
                </form>
              </div>
            <?php else: ?>
              <div class="sports-actions">
                <button class="btn small" disabled title="Requires the sports.settle permission">Settle (needs sports.settle)</button>
              </div>
              <p class="sports-note">Settlement stays with identities holding <b>sports.settle</b>.</p>
            <?php endif; ?>
          <?php endif; ?>
        <?php endif; ?>
        <p class="sports-note"><a href="/sports/odds-prediction-ticket">Odds prediction tickets &amp; history →</a> — every generated ticket, its approval trail and its settlement.</p>
      </div>
    </section>

    <section class="panel sports-section" id="live-scores-panel" aria-labelledby="sports-live-heading">
      <div class="sports-section__heading">
        <div class="sports-section__title">
          <span class="sports-step" aria-hidden="true">3</span>
          <div>
            <p class="sports-eyebrow">In play</p>
            <h3 id="sports-live-heading">Live scores — auto-updating</h3>
          </div>
        </div>
        <span class="sports-section__meta">current play, any date</span>
      </div>
      <div class="body">
        <p class="sports-section-intro">Matches in play right now, refreshed from the provider's live endpoint. This board always reports current play and ignores the viewing date above.</p>
        <div class="sports-livebar">
          <span class="dot synth" id="live-poll-dot" title="Auto-refresh status"></span>
          <span class="dim" id="live-poll-note">Auto-refresh on — the goal score updates here automatically, immediately after the provider reports it.</span>
          <button class="btn small" type="button" id="live-refresh-toggle">Pause</button>
        </div>
        <div id="live-goal-flash" class="sports-goal-flash" hidden></div>
        <div class="table-scroll">
          <table class="tbl">
            <thead><tr><th class="sports-col-minute">Minute</th><th class="sports-col-kickoff">Kickoff (UTC)</th><th>Match</th><th>Competition</th><th class="num">Score</th><th class="sports-col-updated">Updated (UTC)</th></tr></thead>
            <tbody id="live-scores-body">
              <?php $liveRows = $today['live'] ?? []; ?>
              <?php if ($liveRows): foreach ($liveRows as $m): $ls = is_array($m['liveState'] ?? null) ? $m['liveState'] : []; $known = isset($ls['homeScore'], $ls['awayScore']); ?>
                <tr data-match-id="<?= (int) ($m['id'] ?? 0) ?>">
                  <td class="mono dim"><?= isset($ls['minute']) ? e((string) (int) $ls['minute']) . "'" : '—' ?></td>
                  <td class="mono dim live-kickoff-cell"><?= e($kickoffStamp($m['kickoff_at'] ?? null)) ?></td>
                  <td class="sports-cell-strong"><?= e(($m['home_team'] ?? '?') . ' vs ' . ($m['away_team'] ?? '?')) ?><?php if (!empty($m['simulated'])): ?> <span class="badge b-gray">sim</span><?php endif; ?></td>
                  <td class="dim"><?= e((string) ($m['competition'] ?? '')) ?></td>
                  <td class="num mono live-score-cell"><?= $known ? e((int) $ls['homeScore'] . ' – ' . (int) $ls['awayScore']) : '—' ?></td>
                  <td class="mono dim sports-cell-detail"><?= e(substr((string) ($m['updated_at'] ?? ''), 11, 5)) ?></td>
                </tr>
              <?php endforeach; else: ?>
                <tr><td colspan="6" class="dim" id="live-scores-empty">No matches currently live</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <p class="sports-note">Scores come from the provider's live endpoint (one shared, self-gated request — <span class="mono">WINDELS_SPORTS_LIVE_REFRESH_SECONDS</span>, default 60, skipped entirely while nothing is in play). <b>Kickoff (UTC)</b> is the stored match date and time; a match with no stored kickoff shows <b>—</b>, never a guessed one. A match the provider gives no score for shows <b>—</b>, never 0-0. Goal events are audited as <span class="mono">SPORTS_GOAL_SCORED</span>.</p>
      </div>
    </section>

    <section class="panel sports-section" id="sports-performance" aria-labelledby="sports-performance-heading">
      <div class="sports-section__heading">
        <div class="sports-section__title">
          <span class="sports-step" aria-hidden="true">4</span>
          <div>
            <p class="sports-eyebrow">Measured results</p>
            <h3 id="sports-performance-heading">30-day odds prediction ticket performance (stored settlements only)<?= $viewIsToday ? '' : ' — ending ' . e($viewDateIso) ?></h3>
          </div>
        </div>
        <span class="sports-section__meta">settled tickets only</span>
      </div>
      <div class="body">
        <p class="sports-section-intro">Outcomes of tickets that have actually been settled from verified results over the 30 days ending on the viewed date. Nothing here is projected: an unsettled ticket contributes no win, no ROI and no profit figure.</p>
        <?php if (!empty($perf['demoBanner'])): ?><div class="notice warnbox"><?= e((string) $perf['demoBanner']) ?></div><?php endif; ?>
        <div class="stat-grid">
          <div class="stat"><div class="k">Settled tickets</div><div class="v"><?= (int) ($perf['settledTickets'] ?? 0) ?></div></div>
          <div class="stat"><div class="k">Win rate</div><div class="v"><?= ($perf['winRate'] ?? null) !== null ? e(number_format((float) $perf['winRate'] * 100, 1)) . '%' : '—' ?></div></div>
          <div class="stat"><div class="k">ROI</div><div class="v <?= ($perf['roi'] ?? null) !== null && (float) $perf['roi'] >= 0 ? 'up' : 'down' ?>"><?= ($perf['roi'] ?? null) !== null ? e(number_format((float) $perf['roi'] * 100, 1)) . '%' : '—' ?></div></div>
          <div class="stat"><div class="k">Profit / loss</div><div class="v <?= ($perf['profitLoss'] ?? null) !== null && (float) $perf['profitLoss'] >= 0 ? 'up' : 'down' ?>"><?= ($perf['profitLoss'] ?? null) !== null ? e(number_format((float) $perf['profitLoss'], 2)) : '—' ?></div></div>
          <div class="stat"><div class="k">Max drawdown</div><div class="v"><?= ($perf['maxDrawdown'] ?? null) !== null ? e(number_format((float) $perf['maxDrawdown'], 2)) : '—' ?></div></div>
          <div class="stat"><div class="k">Avg odds</div><div class="v"><?= ($perf['averageOdds'] ?? null) !== null ? e(number_format((float) $perf['averageOdds'], 2)) : '—' ?></div></div>
        </div>
        <?php if (empty($perf['dataAvailable'])): ?>
          <p class="sports-empty">No settled records or selections yet — metrics are intentionally unavailable rather than invented.</p>
        <?php endif; ?>
        <p class="sports-note">Prediction accuracy, Brier, ECE and model/calibration state are reported once, on <a href="/football">Football Intelligence</a> and <a href="/football/models">Models &amp; calibration</a>.</p>
      </div>
    </section>

  </div>

  <aside class="sports-side stack" aria-label="Sports generation, system and data-feed status">
    <section class="panel sports-section sports-run-panel" id="sports-run-summary" aria-labelledby="sports-run-heading">
      <div class="sports-section__heading">
        <div class="sports-section__title">
          <div>
            <p class="sports-eyebrow">Generation funnel</p>
            <h3 id="sports-run-heading">Generation run</h3>
          </div>
        </div>
        <span class="sports-section__meta"><?= e($viewDateIso) ?></span>
      </div>
      <div class="body">
        <p class="sports-section-intro">What the latest stored ticket run saw for this date, from eligible fixtures through the final selected picks. A date with no recorded run remains unavailable rather than being reported as zero.</p>
        <dl class="sports-run-list">
          <div><dt>Eligible fixtures</dt><dd class="mono"><?= $runCount($runMetrics['eligibleFixtures']) ?></dd></div>
          <div><dt>Fixtures evaluated</dt><dd class="mono"><?= $runCount($runMetrics['fixturesEvaluated']) ?></dd></div>
          <div><dt>Predictions generated</dt><dd class="mono"><?= $runCount($runMetrics['predictionsGenerated']) ?></dd></div>
          <div><dt>Fresh odds</dt><dd class="mono"><?= $runCount($runMetrics['fixturesWithFreshOdds']) ?></dd></div>
          <div><dt>Stale odds</dt><dd class="mono"><?= $runCount($runMetrics['fixturesRejectedStaleOdds']) ?></dd></div>
          <div><dt>Qualified candidates</dt><dd class="mono"><?= $runCount($runMetrics['correlationQualifiedCandidates']) ?></dd></div>
          <div><dt>Selected picks</dt><dd class="mono"><?= $runCount($runMetrics['finalQualifiedCandidates']) ?></dd></div>
        </dl>
        <?php if ($daily === null): ?><p class="sports-empty">No generation run is stored for <?= e($viewDateIso) ?> yet.</p><?php endif; ?>
      </div>
    </section>

    <section class="panel sports-section sports-reading-guide" aria-labelledby="sports-guide-heading">
      <div class="sports-section__heading">
        <div class="sports-section__title">
          <div>
            <p class="sports-eyebrow">Reading the numbers</p>
            <h3 id="sports-guide-heading">How to read the odds</h3>
          </div>
        </div>
      </div>
      <div class="body">
        <dl>
          <div><dt>Real odds</dt><dd>The decimal bookmaker quote stored with its source and timestamp.</dd></div>
          <div><dt>Implied / break-even</dt><dd>The chance required for that bookmaker price to break even: 1 ÷ odds.</dd></div>
          <div><dt>WINDELS fair odds</dt><dd>The model’s probability expressed as a price. It is not a bookmaker quote.</dd></div>
          <div><dt>Expected value</dt><dd>The model/price comparison per unit. It is not a guarantee or an instruction to bet.</dd></div>
        </dl>
      </div>
    </section>

    <section class="panel sports-section" aria-labelledby="sports-system-heading">
      <div class="sports-section__heading">
        <div class="sports-section__title">
          <div>
            <p class="sports-eyebrow">Platform state</p>
            <h3 id="sports-system-heading">System</h3>
          </div>
        </div>
      </div>
      <div class="body">
        <div class="stat-grid sports-stat-grid--rail">
          <div class="stat"><div class="k">Mode</div><div class="v"><?= e((string) ($sys['mode'] ?? 'SANDBOX')) ?></div></div>
          <div class="stat"><div class="k">Odds prediction ticket engine</div><div class="v v-sm"><?= e((string) ($sys['ticketEngine'] ?? '—')) ?></div></div>
          <?php if ($operator): ?>
          <div class="stat"><div class="k">Operational providers</div><div class="v"><?= (int) ($readiness['operational'] ?? 0) ?>/<?= (int) ($readiness['total'] ?? 0) ?></div></div>
          <?php else: ?>
          <div class="stat"><div class="k">Sports data</div><div class="v v-sm"><span class="dot <?= ($readiness['engine'] ?? '') === 'READY' ? 'up' : 'down' ?>"></span> <?= ($readiness['engine'] ?? '') === 'READY' ? 'Available' : 'Unavailable' ?></div></div>
          <?php endif; ?>
          <div class="stat"><div class="k">Prediction engine</div><div class="v v-sm"><span class="dot <?= ($readiness['engine'] ?? '') === 'READY' ? 'up' : 'down' ?>"></span> <?= e((string) ($readiness['engine'] ?? '—')) ?></div></div>
        </div>
        <?php if ($operator): ?>
        <div class="sports-subhead"><h4>Feed health</h4></div>
        <div class="table-scroll">
          <table class="tbl">
            <thead><tr><th>Feed</th><th>Health</th><th class="num">Reliability</th></tr></thead>
            <tbody>
              <?php if (empty($sys['providers'])): ?>
                <tr><td colspan="3" class="dim">No sports data connected.</td></tr>
              <?php else: ?>
                <?php foreach (array_values($sys['providers']) as $i => $p): $st = (string) ($p['derivedStatus'] ?? 'UNKNOWN'); ?>
                  <tr>
                    <td class="sports-cell-strong">Feed <?= (int) $i + 1 ?></td>
                    <td><span class="dot <?= $statusDot($st) ?>"></span> <?= e($statusLabel($st)) ?></td>
                    <td class="num"><?= ($p['reliability'] ?? null) !== null ? e(number_format((float) $p['reliability'], 2)) : '—' ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
        <?php if (!empty($sys['lastSyncs'])): ?>
          <div class="sports-subhead"><h4>Last jobs</h4></div>
          <div class="table-scroll">
            <table class="tbl">
              <thead><tr><th>Job</th><th>Status</th></tr></thead>
              <tbody>
                <?php foreach ($sys['lastSyncs'] as $j): ?>
                  <tr>
                    <td class="mono dim" title="<?= e((string) ($j['executionKey'] ?? '')) ?>"><?= e((string) ($j['jobType'] ?? $j['job_type'] ?? '?')) ?><small><?= e(substr((string) ($j['started_at'] ?? $j['created_at'] ?? ''), 0, 16)) ?></small></td>
                    <td><span class="badge b-gray"><?= e((string) ($j['status'] ?? 'RUNNING')) ?></span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <?php if ($operator): ?>
    <section class="panel sports-section" aria-labelledby="sports-feed-heading">
      <div class="sports-section__heading">
        <div class="sports-section__title">
          <div>
            <p class="sports-eyebrow">Data health</p>
            <h3 id="sports-feed-heading">Data feed</h3>
          </div>
        </div>
      </div>
      <div class="body">
        <?php $configuredIds = $sys['configuredIds'] ?? []; $live = $sys['liveHealth'] ?? []; ?>
        <?php if (empty($configuredIds)): ?>
          <p class="sports-empty"><b>No providers registered.</b> Add a provider key (API-Football, TheSportsDB or SportMonks) via Admin → API or the <span class="mono">WINDELS_*_KEY</span> variables in <span class="mono">.env</span>, then press <b>Sync now</b> above.</p>
        <?php else: ?>
          <p class="sports-note sports-note--lead">Operational providers: <b><?= (int) ($readiness['operational'] ?? 0) ?>/<?= (int) ($readiness['total'] ?? 0) ?></b> · Prediction engine: <b><?= e((string) ($readiness['engine'] ?? '—')) ?></b></p>
          <div class="table-scroll">
            <table class="tbl">
              <thead><tr><th>Model</th><th>Health</th><th>Circuit</th></tr></thead>
              <tbody>
                <?php foreach (array_values($configuredIds) as $i => $pid): $h = is_array($live[$pid] ?? null) ? $live[$pid] : []; $st = (string) ($h['status'] ?? 'UNKNOWN'); $c = is_array($h['circuit'] ?? null) ? $h['circuit'] : []; ?>
                  <tr>
                    <td class="mono sports-cell-strong"><?= e($windelsModelId) ?><small class="dim">feed <?= (int) $i + 1 ?><?php if (isset($h['rateLimitRemaining']) || isset($h['requestsToday'])): ?> · quota <?= isset($h['requestsToday']) ? e((string) $h['requestsToday']) . '/' . e((string) ($h['limitDaily'] ?? '?')) . ' used' : e((string) $h['rateLimitRemaining']) . ' left' ?><?php endif; ?></small></td>
                    <td><span class="dot <?= $statusDot($st) ?>"></span> <?= e($statusLabel($st)) ?><?php if (!empty($h['detail'])): ?> <small class="dim">provider detail hidden</small><?php endif; ?></td>
                    <td class="sports-cell-detail"><?php $cs = (string) ($c['state'] ?? 'CLOSED'); ?><span class="badge <?= $cs === 'OPEN' ? 'b-red' : ($cs === 'HALF_OPEN' ? 'b-gray' : 'b-green') ?>"><?= e($cs) ?></span><?php if ($cs === 'OPEN' && !empty($c['retryAt'])): ?><small class="dim">retry <?= e(substr((string) $c['retryAt'], 11, 5)) ?>Z</small><?php endif; ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
        <?php $recent = $sys['recentSyncs'] ?? []; ?>
        <?php if (!empty($recent)): ?>
          <div class="sports-subhead"><h4>Recent syncs</h4></div>
          <div class="table-scroll">
            <table class="tbl">
              <thead><tr><th>Sync</th><th>Status</th><th class="num">New</th></tr></thead>
              <tbody>
                <?php foreach ($recent as $s): $errs = is_array($s['errors'] ?? null) ? $s['errors'] : []; ?>
                  <tr>
                    <td class="mono dim"><?= e((string) ($s['job_type'] ?? '?')) ?><small><?= e(substr((string) ($s['started_at'] ?? ''), 0, 16)) ?></small></td>
                    <td><span class="badge b-gray"><?= e((string) ($s['status'] ?? 'RUNNING')) ?></span><?php if (!empty($errs[0])): ?><small class="dim"><?= e(mb_substr((string) $errs[0], 0, 140)) ?></small><?php endif; ?></td>
                    <td class="num mono"><?= (int) ($s['records_created'] ?? 0) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php elseif (!empty($configuredIds)): ?>
          <p class="sports-empty">No sync runs recorded yet — press <b>Sync now</b> to pull the first fixtures.</p>
        <?php endif; ?>
      </div>
    </section>
    <?php endif; ?>

  </aside>
</div>
</div>

<script id="generate-ticket-btn-js">
(function(){
  // Enhance GENERATE buttons: show generating state, prevent double-click
  document.querySelectorAll('form[action$="/generate-ticket"], form[action$="/sports/generate-ticket"]').forEach(function(form){
    form.addEventListener('submit', function(){
      var btn = form.querySelector('button');
      if(!btn) return;
      if(btn.dataset.generating === '1') return;
      btn.dataset.generating = '1';
      btn.dataset.originalText = btn.innerHTML;
      btn.innerHTML = '⏳ Generating odds prediction ticket...';
      btn.disabled = true;
      // allow form to submit, but re-enable after 10s if still on page (e.g. validation fail)
      setTimeout(function(){
        if(btn.dataset.generating === '1'){
          btn.innerHTML = btn.dataset.originalText;
          btn.disabled = false;
          delete btn.dataset.generating;
        }
      }, 10000);
    });
  });
  // Also offer API-driven generation for operators who prefer no page reload
  // (uses the same RBAC — requires sports.manage + CSRF header)
  var apiBtn = document.getElementById('api-generate-ticket');
  if(apiBtn){
    apiBtn.addEventListener('click', async function(e){
      e.preventDefault();
      var dateInput = document.getElementById('api-generate-date');
      var date = dateInput ? dateInput.value : new Date().toISOString().slice(0,10);
      var csrf = document.querySelector('meta[name="csrf-token"]')?.content || document.querySelector('input[name=csrf_token]')?.value || '';
      apiBtn.disabled = true;
      var orig = apiBtn.textContent;
      apiBtn.textContent = '⏳ Generating...';
      try{
        var res = await fetch('/api/sports/ticket-engine/run', {
          method: 'POST',
          headers: {'Content-Type':'application/json','X-CSRF-Token': csrf},
          body: JSON.stringify({date: date})
        });
        var data = await res.json();
        if(res.ok){
          alert('Odds prediction ticket engine: ' + (data.status||'') + (data.ticketId ? '  — ticket ' + data.ticketId : '') + '\n' + (data.message||''));
          location.href = '/sports/odds-prediction-ticket';
        } else {
          alert('Generate failed: ' + (data.message||data.error||res.status));
          apiBtn.disabled = false;
          apiBtn.textContent = orig;
        }
      }catch(err){
        alert('Generate failed: ' + err.message);
        apiBtn.disabled = false;
        apiBtn.textContent = orig;
      }
    });
  }
})();
</script>

<script id="live-scores-js">
(function(){
  // Live scores auto-update: poll the throttled /api/sports/live endpoint.
  // The server shares ONE provider request per refresh interval across all
  // viewers, so polling here is cheap between sweeps. New SPORTS_GOAL_SCORED
  // events flash a GOAL banner and highlight the row.
  var body = document.getElementById('live-scores-body');
  if(!body) return;
  var dot = document.getElementById('live-poll-dot');
  var note = document.getElementById('live-poll-note');
  var toggleBtn = document.getElementById('live-refresh-toggle');
  var flash = document.getElementById('live-goal-flash');
  var countStat = document.getElementById('live-count-stat');
  var since = new Date().toISOString();     // only events after page load flash
  var seenGoals = {};                        // dedupe by matchId:score:minute
  var intervalSec = 60;                      // server's provider-poll interval
  var pollTimer = null;
  var paused = false;

  function esc(s){
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }

  function kickoffStamp(v){
    // Match date + time in UTC, matching the server-rendered cell. A match with
    // no stored kickoff prints — rather than an epoch date.
    var t = v ? Date.parse(v) : NaN;
    return isNaN(t) ? '—' : new Date(t).toISOString().substring(0, 16).replace('T', ' ');
  }

  function rowHtml(m){
    var score = m.scoreKnown ? esc(m.homeScore) + ' – ' + esc(m.awayScore) : '—';
    var minute = (m.minute !== null && m.minute !== undefined) ? esc(m.minute) + "'" : '—';
    var sim = m.simulated ? ' <span class="badge b-gray">sim</span>' : '';
    var updated = (m.updatedAt || '').substring(11, 16);
    return '<tr data-match-id="' + esc(m.id) + '">'
      + '<td class="mono dim">' + minute + '</td>'
      + '<td class="mono dim live-kickoff-cell">' + esc(kickoffStamp(m.kickoff)) + '</td>'
      + '<td class="sports-cell-strong">' + esc(m.homeTeam) + ' vs ' + esc(m.awayTeam) + sim + '</td>'
      + '<td class="dim">' + esc(m.competition) + '</td>'
      + '<td class="num mono live-score-cell">' + score + '</td>'
      + '<td class="mono dim sports-cell-detail">' + esc(updated) + '</td>'
      + '</tr>';
  }

  function render(matches){
    if(!matches.length){
      body.innerHTML = '<tr><td colspan="6" class="dim" id="live-scores-empty">No matches currently live</td></tr>';
    } else {
      body.innerHTML = matches.map(rowHtml).join('');
    }
    if(countStat) countStat.textContent = String(matches.length);
  }

  function goalKey(ev){
    var score = ev.score || {};
    return (ev.matchId || 0) + ':' + score.home + '-' + score.away + ':' + (ev.minute === null || ev.minute === undefined ? '' : ev.minute);
  }

  function flashGoals(events){
    var fresh = (events || []).filter(function(ev){
      var key = goalKey(ev);
      if(seenGoals[key]) return false;
      seenGoals[key] = true;
      return true;
    });
    if(!fresh.length) return;
    var lines = fresh.map(function(ev){
      var score = ev.score || {};
      return '⚽ GOAL — ' + esc(ev.homeTeam) + ' ' + score.home + '-' + score.away + ' ' + esc(ev.awayTeam)
        + (ev.minute !== null && ev.minute !== undefined ? " (" + ev.minute + "')" : '') + ' · ' + esc(ev.competition);
    });
    flash.innerHTML = lines.join('<br>');
    flash.hidden = false;
    fresh.forEach(function(ev){
      var row = body.querySelector('tr[data-match-id="' + ev.matchId + '"]');
      if(row){
        row.classList.add('sports-goal-row');
        setTimeout(function(){ row.classList.remove('sports-goal-row'); }, 6000);
      }
    });
    clearTimeout(flash._t);
    flash._t = setTimeout(function(){ flash.hidden = true; }, 8000);
  }

  function setNote(text, cls){
    if(note) note.textContent = text;
    if(dot) dot.className = 'dot ' + (cls || 'synth');
  }

  function schedule(){
    // Poll a little faster than the provider interval so a fresh sweep is
    // picked up quickly; the endpoint itself stays storage-only between
    // sweeps, so this costs no provider request.
    var ms = Math.max(10, Math.min(15, intervalSec)) * 1000;
    clearTimeout(pollTimer);
    pollTimer = setTimeout(poll, ms);
  }

  function poll(){
    if(paused || document.hidden){ schedule(); return; }
    fetch('/api/sports/live?since=' + encodeURIComponent(since), {credentials: 'same-origin'})
      .then(function(res){ if(!res.ok) throw new Error('HTTP ' + res.status); return res.json(); })
      .then(function(data){
        if(data.refreshIntervalSeconds) intervalSec = data.refreshIntervalSeconds;
        if(data.serverTime) since = data.serverTime;
        render(data.matches || []);
        flashGoals(data.goalEvents || []);
        var waited = data.refreshed && data.refreshed.retryInSeconds ? data.refreshed.retryInSeconds : intervalSec;
        setNote('Auto-refresh on — live board updated' + (data.refreshed && data.refreshed.status === 'THROTTLED' ? ' (next provider sweep in ~' + waited + 's)' : '') + '.', 'up');
      })
      .catch(function(err){
        var forbidden = String(err && err.message || '').indexOf('403') >= 0;
        setNote(forbidden
          ? 'Live auto-refresh needs the sports.view permission.'
          : 'Auto-refresh interrupted — retrying.', 'down');
      })
      .then(schedule);
  }

  if(toggleBtn){
    toggleBtn.addEventListener('click', function(){
      paused = !paused;
      toggleBtn.textContent = paused ? 'Resume' : 'Pause';
      setNote(paused ? 'Auto-refresh paused — scores resume on Resume.' : 'Auto-refresh on — the goal score updates here automatically.', paused ? 'down' : 'up');
      if(!paused){ clearTimeout(pollTimer); poll(); }
    });
  }
  document.addEventListener('visibilitychange', function(){
    if(!document.hidden && !paused){ clearTimeout(pollTimer); poll(); }
  });
  setNote('Auto-refresh on — the goal score updates here automatically, immediately after the provider reports it.', 'synth');
  poll();
})();
</script>

