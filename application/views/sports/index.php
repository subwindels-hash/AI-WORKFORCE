<?php defined('BASEPATH') or exit('No direct script access allowed');
/** @var array $dashboard */
$d = $dashboard ?? [];
$sys = $d['systemStatus'] ?? [];
$today = $d['todayIntelligence'] ?? [];
$engine = $d['ticketEngine'] ?? [];
$perf = $d['performance'] ?? [];
$models = $d['models'] ?? [];
$ticket = $engine['ticket'] ?? null;
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
$ticketDateIso = (string) ($today['date'] ?? gmdate('Y-m-d'));
$ticketDateTs = strtotime($ticketDateIso);
$ticketDateShown = gmdate('m/d/Y', $ticketDateTs !== false ? $ticketDateTs : time());
// Match date + time as one UTC stamp (`YYYY-MM-DD HH:MM`) from the stored
// kickoff. A match the provider gave no kickoff for prints — : never a blank
// cell, never 00:00, because a guessed time would read as a real one.
$kickoffStamp = static function (mixed $iso): string {
    $ts = is_string($iso) && trim($iso) !== '' ? strtotime($iso) : false;
    return $ts === false ? '—' : gmdate('Y-m-d H:i', $ts);
};
?>
<div class="page-head">
  <div>
    <h2>Sports Intelligence — odds prediction ticket engine</h2>
    <p>Daily odds prediction tickets from stored fixtures and provider odds. Each ticket does what odds analysis needs to do: compare the offered price with the model probability, calculate expected value, check confidence/risk/correlation, and keep a settlement trail — no bookmaker bet is placed.</p>
    <?php if (!empty($caps['sync'])): ?>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:12px">
        <form method="post" action="/sports/sync" onsubmit="return confirm('Pull fresh fixtures, odds and results from the configured providers now?')">
          <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
          <button class="btn primary small">Sync now</button>
        </form>
        <form method="post" action="/sports/generate-ticket" style="display:flex;gap:6px;align-items:center" onsubmit="return confirm('Generate odds prediction ticket for the selected date from stored fixtures & odds? This runs the AI odds prediction ticket engine (value, probability, confidence, risk, correlation) and creates a reviewable odds prediction ticket.')">
          <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
          <input type="date" name="date" value="<?= e($ticketDateIso) ?>" aria-label="<?= e($ticketDateShown) ?>" style="padding:6px 8px;border:1px solid var(--line);border-radius:6px;font-size:12px" title="Ticket date (UTC) <?= e($ticketDateShown) ?>">
          <span class="mono" style="font-size:12px;font-weight:700"><?= e($ticketDateShown) ?></span>
          <button class="btn small" style="background:var(--violet,#6d28d9);color:#fff;border-color:var(--violet,#6d28d9);font-weight:700;letter-spacing:0.02em">
            🎯 Odds Prediction Ticket
          </button>
        </form>
      </div>
      <p class="dim" style="font-size:11px;margin-top:6px">Sync pulls fixtures/odds from providers. <b>🎯 Odds Prediction Ticket</b> turns stored odds into a reviewable ticket — market, selection, offered odds, fair probability, expected value and risk — no external call, idempotent per day/config version.</p>
    <?php else: ?>
      <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <button class="btn small" disabled title="Requires the sports.manage permission">Sync now</button>
        <span class="mono" style="padding:6px 8px;border:1px solid var(--line);border-radius:6px;font-size:12px;font-weight:700" title="Ticket date (UTC)"><?= e($ticketDateShown) ?></span>
        <button class="btn small" disabled title="Requires the sports.manage permission" style="font-weight:700">🎯 Odds Prediction Ticket</button>
      </div>
      <p class="dim" style="font-size:11px;margin-top:6px">Your account is read-only here (sports.view). Ask an administrator to assign the <b>Sports administrator</b> role — the console picks the new permission up on your next page load, no sign-out needed.</p>
    <?php endif; ?>
  </div>
</div>
<p style="margin-top:8px"><a class="btn small" href="/football">Today's football predictions, settlement history and model state →</a></p>
<?php if (!empty($notice)): ?><div class="notice ok"><?= e($notice) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="notice err"><?= e($error) ?></div><?php endif; ?>
<?php if (!empty($sys['isDemoData'])): ?>
  <div class="notice warnbox"><b>SANDBOX / DEMO DATA</b> — sports figures are simulated, not real-world performance.</div>
<?php endif; ?>
<?php if ($disabled): ?>
  <div class="notice warnbox"><b>No sports data provider connected.</b> Live fixtures and predictions are unavailable until a verified data source is configured — nothing is fabricated in the meantime.</div>
<?php elseif (($readiness['engine'] ?? '') === 'BLOCKED'): ?>
  <?php if ($operator): ?>
  <div class="notice err"><b>Prediction engine BLOCKED — 0/<?= (int) ($readiness['total'] ?? 0) ?> sports data providers operational.</b>
    Every configured feed is currently failing (see <i>Data feed</i>). Odds prediction tickets cannot be generated until at least one provider recovers; an empty day in this state is a <b>data outage</b>, not "no qualified games".
    <?php foreach (($readiness['providers'] ?? []) as $pid => $pr): ?><br><span class="mono" style="font-size:11px"><?= e((string) $pid) ?> → <?= e((string) ($pr['status'] ?? 'UNKNOWN')) ?><?php if (!empty($pr['retryAt'])): ?> (retry after <?= e(substr((string) $pr['retryAt'], 0, 16)) ?>Z)<?php endif; ?></span><?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="notice err"><b>Sports data temporarily unavailable.</b> Odds prediction tickets cannot be generated until the data feed recovers — an empty day in this state is a data outage, not "no qualified games".</div>
  <?php endif; ?>
<?php endif; ?>

<div class="grid cols-main">
  <div class="stack">
    <div class="panel">
      <h3>Today's intelligence — <?= e((string) ($today['date'] ?? gmdate('Y-m-d'))) ?></h3>
      <div class="body" style="padding-top:12px">
        <div class="stat-grid">
          <div class="stat"><div class="k">Scheduled</div><div class="v"><?= (int) ($today['upcomingCount'] ?? 0) ?></div></div>
          <div class="stat"><div class="k">Live</div><div class="v" id="live-count-stat"><?= count($today['live'] ?? []) ?></div></div>
          <div class="stat"><div class="k">Qualified predictions</div><div class="v up"><?= (int) ($today['qualifiedPredictions'] ?? 0) ?></div></div>
          <div class="stat"><div class="k">Rejected predictions</div><div class="v down"><?= (int) ($today['rejectedPredictions'] ?? 0) ?></div></div>
          <div class="stat"><div class="k">Avg confidence</div><div class="v"><?= ($today['averageConfidence'] ?? null) !== null ? e(number_format((float) $today['averageConfidence'], 1)) . '%' : '—' ?></div></div>
        </div>
        <?php $risk = $today['riskDistribution'] ?? []; if (array_sum($risk) > 0): ?>
          <div style="margin-top:12px;display:grid;gap:6px">
            <?php foreach ([['LOW', 'var(--green)'], ['MEDIUM', 'var(--amber)'], ['HIGH', 'var(--red)'], ['REJECTED', 'var(--muted)']] as [$k, $c]): ?>
              <div class="meter">
                <div class="row"><span>Risk <?= e($k) ?></span><span class="mono dim"><?= (int) ($risk[$k] ?? 0) ?></span></div>
                <div class="bar"><div style="width:<?= round(100 * (($risk[$k] ?? 0) / max(1, array_sum($risk)))) ?>%;background:<?= $c ?>"></div></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <?php if (!empty($today['upcoming'])): ?>
          <div class="table-scroll">
            <table class="tbl" style="margin-top:12px">
              <thead><tr><th>Kickoff (UTC)</th><th>Match</th><th>Competition</th><th class="num">Data quality</th></tr></thead>
              <tbody>
                <?php $qByMatch = []; foreach ($today['dataQuality'] ?? [] as $q) $qByMatch[(int) $q['matchId']] = $q; ?>
                <?php foreach ($today['upcoming'] as $m): $q = $qByMatch[(int) ($m['id'] ?? 0)] ?? null; ?>
                  <tr>
                    <td class="mono dim"><?= e(substr((string) ($m['kickoff_at'] ?? ''), 0, 16)) ?></td>
                    <td style="font-weight:700"><?= e(($m['home_team'] ?? '?') . ' vs ' . ($m['away_team'] ?? '?')) ?></td>
                    <td class="dim"><?= e((string) ($m['competition'] ?? '')) ?></td>
                    <td class="num"><?= $q ? e($q['band'] . ' · ' . (int) $q['score']) : '<span class="dim">not assessed</span>' ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <p class="dim" style="margin-top:12px">No scheduled fixtures stored for today.</p>
        <?php endif; ?>
      </div>
    </div>

    <div class="panel" id="live-scores-panel">
      <h3>Live scores — auto-updating</h3>
      <div class="body" style="padding-top:12px">
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:10px">
          <span class="dot synth" id="live-poll-dot" title="Auto-refresh status"></span>
          <span class="dim" style="font-size:11px" id="live-poll-note">Auto-refresh on — the goal score updates here automatically, immediately after the provider reports it.</span>
          <button class="btn small" type="button" id="live-refresh-toggle">Pause</button>
        </div>
        <div id="live-goal-flash" style="display:none;background:var(--violet,#6d28d9);color:#fff;border-radius:8px;padding:8px 12px;font-weight:700;margin-bottom:10px"></div>
        <div class="table-scroll">
          <table class="tbl">
            <thead><tr><th style="width:70px">Minute</th><th style="width:118px">Kickoff (UTC)</th><th>Match</th><th>Competition</th><th class="num">Score</th><th style="width:90px">Updated (UTC)</th></tr></thead>
            <tbody id="live-scores-body">
              <?php $liveRows = $today['live'] ?? []; ?>
              <?php if ($liveRows): foreach ($liveRows as $m): $ls = is_array($m['liveState'] ?? null) ? $m['liveState'] : []; $known = isset($ls['homeScore'], $ls['awayScore']); ?>
                <tr data-match-id="<?= (int) ($m['id'] ?? 0) ?>">
                  <td class="mono dim"><?= isset($ls['minute']) ? e((string) (int) $ls['minute']) . "'" : '—' ?></td>
                  <td class="mono dim live-kickoff-cell"><?= e($kickoffStamp($m['kickoff_at'] ?? null)) ?></td>
                  <td style="font-weight:700"><?= e(($m['home_team'] ?? '?') . ' vs ' . ($m['away_team'] ?? '?')) ?><?php if (!empty($m['simulated'])): ?> <span class="badge b-gray">sim</span><?php endif; ?></td>
                  <td class="dim"><?= e((string) ($m['competition'] ?? '')) ?></td>
                  <td class="num mono live-score-cell" style="font-weight:700;font-size:14px"><?= $known ? e((int) $ls['homeScore'] . ' – ' . (int) $ls['awayScore']) : '—' ?></td>
                  <td class="mono dim" style="font-size:11px"><?= e(substr((string) ($m['updated_at'] ?? ''), 11, 5)) ?></td>
                </tr>
              <?php endforeach; else: ?>
                <tr><td colspan="6" class="dim" id="live-scores-empty">No live matches right now — the board refreshes automatically while matches are in play.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <p class="dim" style="font-size:11px;margin-top:8px">Scores come from the provider's live endpoint (one shared, self-gated request — <span class="mono">WINDELS_SPORTS_LIVE_REFRESH_SECONDS</span>, default 60, skipped entirely while nothing is in play). <b>Kickoff (UTC)</b> is the stored match date and time; a match with no stored kickoff shows <b>—</b>, never a guessed one. A match the provider gives no score for shows <b>—</b>, never 0-0. Goal events are audited as <span class="mono">SPORTS_GOAL_SCORED</span>.</p>
      </div>
    </div>

    <div class="panel">
      <h3>30-day odds prediction ticket performance (stored settlements only)</h3>
      <div class="body" style="padding-top:12px">
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
          <p class="dim" style="margin-top:12px">No settled records or selections yet — metrics are intentionally unavailable rather than invented.</p>
        <?php endif; ?>
        <p class="dim" style="margin-top:10px;font-size:11px">Prediction accuracy, Brier, ECE and model/calibration state are reported once, on <a href="/football">Football Intelligence</a> and <a href="/football/models">Models &amp; calibration</a>.</p>
      </div>
    </div>

  </div>

  <div class="stack">
    <div class="panel">
      <h3>System</h3>
      <div class="body" style="padding-top:12px">
        <div class="stat-grid">
          <div class="stat"><div class="k">Mode</div><div class="v"><?= e((string) ($sys['mode'] ?? 'SANDBOX')) ?></div></div>
          <div class="stat"><div class="k">Odds prediction ticket engine</div><div class="v" style="font-size:12px"><?= e((string) ($sys['ticketEngine'] ?? '—')) ?></div></div>
          <?php if ($operator): ?>
          <div class="stat"><div class="k">Operational providers</div><div class="v"><?= (int) ($readiness['operational'] ?? 0) ?>/<?= (int) ($readiness['total'] ?? 0) ?></div></div>
          <?php else: ?>
          <div class="stat"><div class="k">Sports data</div><div class="v" style="font-size:12px"><span class="dot <?= ($readiness['engine'] ?? '') === 'READY' ? 'up' : 'down' ?>"></span> <?= ($readiness['engine'] ?? '') === 'READY' ? 'Available' : 'Unavailable' ?></div></div>
          <?php endif; ?>
          <div class="stat"><div class="k">Prediction engine</div><div class="v" style="font-size:12px"><span class="dot <?= ($readiness['engine'] ?? '') === 'READY' ? 'up' : 'down' ?>"></span> <?= e((string) ($readiness['engine'] ?? '—')) ?></div></div>
        </div>
        <?php if ($operator): ?>
        <div class="table-scroll">
          <table class="tbl" style="margin-top:12px">
            <thead><tr><th>Feed</th><th>Health</th><th class="num">Reliability</th></tr></thead>
            <tbody>
              <?php if (empty($sys['providers'])): ?>
                <tr><td colspan="3" class="dim">No sports data connected.</td></tr>
              <?php else: ?>
                <?php foreach (array_values($sys['providers']) as $i => $p): $st = (string) ($p['derivedStatus'] ?? 'UNKNOWN'); ?>
                  <tr>
                    <td style="font-weight:700">Feed <?= (int) $i + 1 ?></td>
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
          <div class="table-scroll">
            <table class="tbl" style="margin-top:12px">
              <thead><tr><th>Job</th><th>Status</th></tr></thead>
              <tbody>
                <?php foreach ($sys['lastSyncs'] as $j): ?>
                  <tr>
                    <td class="mono dim" title="<?= e((string) ($j['executionKey'] ?? '')) ?>"><?= e((string) ($j['jobType'] ?? $j['job_type'] ?? '?')) ?><br><span style="font-size:10px"><?= e(substr((string) ($j['started_at'] ?? $j['created_at'] ?? ''), 0, 16)) ?></span></td>
                    <td><span class="badge b-gray"><?= e((string) ($j['status'] ?? 'RUNNING')) ?></span></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($operator): ?>
    <div class="panel">
      <h3>Data feed</h3>
      <div class="body" style="padding-top:12px">
        <?php $configuredIds = $sys['configuredIds'] ?? []; $live = $sys['liveHealth'] ?? []; ?>
        <?php if (empty($configuredIds)): ?>
          <p class="dim"><b>No providers registered.</b> Add a provider key (API-Football, TheSportsDB or SportMonks) via Admin → API or the <span class="mono">WINDELS_*_KEY</span> variables in <span class="mono">.env</span>, then press <b>Sync now</b> above.</p>
        <?php else: ?>
          <p class="dim" style="font-size:11px;margin:0 0 8px">Operational providers: <b><?= (int) ($readiness['operational'] ?? 0) ?>/<?= (int) ($readiness['total'] ?? 0) ?></b> · Prediction engine: <b><?= e((string) ($readiness['engine'] ?? '—')) ?></b></p>
          <div class="table-scroll">
            <table class="tbl">
              <thead><tr><th>Provider</th><th>Health</th><th>Circuit</th></tr></thead>
              <tbody>
                <?php foreach ($configuredIds as $pid): $h = is_array($live[$pid] ?? null) ? $live[$pid] : []; $st = (string) ($h['status'] ?? 'UNKNOWN'); $c = is_array($h['circuit'] ?? null) ? $h['circuit'] : []; ?>
                  <tr>
                    <td class="mono" style="font-weight:700"><?= e((string) $pid) ?><?php if (isset($h['rateLimitRemaining']) || isset($h['requestsToday'])): ?><br><span class="dim" style="font-size:10px;font-weight:400">quota <?= isset($h['requestsToday']) ? e((string) $h['requestsToday']) . '/' . e((string) ($h['limitDaily'] ?? '?')) . ' used' : e((string) $h['rateLimitRemaining']) . ' left' ?></span><?php endif; ?></td>
                    <td><span class="dot <?= $statusDot($st) ?>"></span> <?= e($statusLabel($st)) ?><?php if (!empty($h['detail'])): ?> <span class="dim" style="font-size:10px"><?= e(mb_substr((string) $h['detail'], 0, 140)) ?></span><?php endif; ?><?php if (!empty($h['endpoint']) && in_array($st, ['BAD_REQUEST', 'NOT_FOUND'], true)): ?><br><span class="mono dim" style="font-size:10px"><?= e(mb_substr((string) $h['endpoint'], 0, 120)) ?></span><?php endif; ?></td>
                    <td style="font-size:11px"><?php $cs = (string) ($c['state'] ?? 'CLOSED'); ?><span class="badge <?= $cs === 'OPEN' ? 'b-red' : ($cs === 'HALF_OPEN' ? 'b-gray' : 'b-green') ?>"><?= e($cs) ?></span><?php if ($cs === 'OPEN' && !empty($c['retryAt'])): ?><br><span class="dim" style="font-size:10px">retry <?= e(substr((string) $c['retryAt'], 11, 5)) ?>Z</span><?php endif; ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
        <?php $recent = $sys['recentSyncs'] ?? []; ?>
        <?php if (!empty($recent)): ?>
          <div class="table-scroll">
            <table class="tbl" style="margin-top:12px">
              <thead><tr><th>Sync</th><th>Status</th><th class="num">New</th></tr></thead>
              <tbody>
                <?php foreach ($recent as $s): $errs = is_array($s['errors'] ?? null) ? $s['errors'] : []; ?>
                  <tr>
                    <td class="mono dim"><?= e((string) ($s['job_type'] ?? '?')) ?><br><span style="font-size:10px"><?= e(substr((string) ($s['started_at'] ?? ''), 0, 16)) ?></span></td>
                    <td><span class="badge b-gray"><?= e((string) ($s['status'] ?? 'RUNNING')) ?></span><?php if (!empty($errs[0])): ?><br><span class="dim" style="font-size:10px"><?= e(mb_substr((string) $errs[0], 0, 140)) ?></span><?php endif; ?></td>
                    <td class="num mono"><?= (int) ($s['records_created'] ?? 0) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php elseif (!empty($configuredIds)): ?>
          <p class="dim" style="margin-top:10px">No sync runs recorded yet — press <b>Sync now</b> to pull the first fixtures.</p>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="panel">
      <h3>Today's odds prediction ticket</h3>
      <div class="body" style="padding-top:12px">
        <?php $daily = $engine['today'] ?? null; ?>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px">
          <?php if (!empty($caps['sync'])): ?>
            <form method="post" action="/sports/generate-ticket" style="display:flex;gap:6px;align-items:center" onsubmit="return confirm('Generate odds prediction ticket for today from stored data?')">
              <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
              <input type="hidden" name="date" value="<?= e((string) ($today['date'] ?? gmdate('Y-m-d'))) ?>">
              <button class="btn small" style="background:var(--violet,#6d28d9);color:#fff;border-color:var(--violet,#6d28d9);font-weight:700">
                🎯 Odds Prediction Ticket
              </button>
            </form>
            <span class="dim" style="font-size:11px">from stored fixtures & odds — no external call</span>
          <?php else: ?>
            <button class="btn small" disabled title="Requires the sports.manage permission" style="font-weight:700">🎯 Odds Prediction Ticket</button>
          <?php endif; ?>
        </div>
        <?php if ($daily !== null && (string) ($daily['status'] ?? '') === 'DATA_UNAVAILABLE'): ?>
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
            <span class="badge b-red">NO TICKET — DATA_UNAVAILABLE</span>
          </div>
          <?php if ($operator): ?>
          <p class="dim" style="margin:0 0 8px"><b>All configured sports-data providers failed</b> for this run — this is a data outage, not a day without qualifying games. <?= e((string) ($daily['message'] ?? '')) ?></p>
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
          <p class="dim" style="font-size:11px;margin-top:8px">Matches evaluated: <?= (int) ($daily['candidates_evaluated'] ?? 0) ?> · Predictions generated: <?= (int) ($daily['predictions_recorded'] ?? 0) ?>. The run stays retryable: once a provider recovers, Sync and generate again.</p>
          <?php else: ?>
          <p class="dim" style="margin:0 0 8px"><b>Sports data was unavailable</b> for this run — this is a data outage, not a day without qualifying games. No odds prediction ticket is fabricated; one will be built once the data feed recovers.</p>
          <?php endif; ?>
        <?php elseif ($daily === null || $ticket === null): ?>
          <p class="dim"><?= $daily !== null ? e((string) ($daily['message'] ?? 'No odds prediction ticket today.')) : 'No daily run recorded for today yet. Select 🎯 Odds Prediction Ticket to build one from stored fixtures & odds.' ?></p>
        <?php else: ?>
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
            <span class="badge <?= (string) ($daily['status'] ?? '') === 'PENDING_USER_APPROVAL' ? 'b-violet' : 'b-green' ?>"><?= e((string) ($daily['status'] ?? '')) ?></span>
            <span class="badge b-gray">engine <?= e((string) ($engine['configuration']['engine_mode'] ?? '')) ?></span>
          </div>
          <div class="stat-grid">
            <div class="stat"><div class="k">Total odds</div><div class="v"><?= e(number_format((float) ($ticket['total_odds'] ?? 0), 2)) ?></div></div>
            <div class="stat"><div class="k">Selections</div><div class="v"><?= (int) ($ticket['selection_count'] ?? 0) ?></div></div>
            <div class="stat"><div class="k">Confidence</div><div class="v"><?= ($ticket['confidence'] ?? null) !== null ? e(number_format((float) $ticket['confidence'], 0)) . '%' : '—' ?></div></div>
            <div class="stat"><div class="k">Stake / unit</div><div class="v"><?= ($ticket['stake'] ?? null) !== null ? e(number_format((float) $ticket['stake'], 2)) : '—' ?></div></div>
          </div>
          <?php if (!empty($engine['ticketSelections'])): ?>
            <div class="table-scroll">
              <table class="tbl" style="margin-top:12px">
                <thead><tr><th>Selection</th><th class="num">Odds</th><th class="num">P(cal)</th><th class="num">EV</th><th>Status</th></tr></thead>
                <tbody>
                  <?php foreach ($engine['ticketSelections'] as $s): ?>
                    <tr>
                      <td>
                        <?php if (isset($selByName[(int) $s['match_id']])): ?><span class="dim" style="font-size:10px;display:block"><?= e($selByName[(int) $s['match_id']]) ?></span><?php endif; ?>
                        <b><?= e((string) ($s['selection'] ?? '?')) ?></b> <span class="dim"><?= e((string) ($s['market'] ?? '')) ?></span>
                      </td>
                      <td class="num mono"><?= e(number_format((float) ($s['odds'] ?? 0), 2)) ?></td>
                      <td class="num mono"><?= ($s['calibrated_probability'] ?? null) !== null ? e(number_format((float) $s['calibrated_probability'], 3)) : '—' ?></td>
                      <td class="num mono <?= ($s['expected_value'] ?? 0) >= 0 ? 'up' : 'down' ?>"><?= ($s['expected_value'] ?? null) !== null ? e(number_format((float) $s['expected_value'], 3)) : '—' ?></td>
                      <td><span class="badge b-gray"><?= e((string) ($s['status'] ?? 'PENDING')) ?></span></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
          <?php if ((string) ($ticket['approval_status'] ?? '') === 'PENDING_USER_APPROVAL'): ?>
            <?php if (!empty($caps['approve'])): ?>
              <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">
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
              <p class="dim" style="font-size:10px;margin-top:8px">Approval is recorded with the acting identity. There is no external execution connector — approval never places a bet.</p>
            <?php else: ?>
              <div style="margin-top:12px">
                <button class="btn small" disabled title="Requires the sports.approve permission">Approve / reject (needs sports.approve)</button>
                <p class="dim" style="font-size:10px;margin-top:6px">Your account cannot approve records — ask an administrator for the <b>sports.approve</b> permission (Sports administrator role).</p>
              </div>
            <?php endif; ?>
          <?php endif; ?>
          <?php if ((string) ($ticket['settlement_status'] ?? '') === 'PENDING'): ?>
            <?php if (!empty($caps['settle'])): ?>
              <form method="post" action="/sports/<?= e((string) $ticket['id']) ?>/settle" style="margin-top:12px">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
                <button class="btn small">Settle from verified results (sports.settle)</button>
              </form>
            <?php else: ?>
              <div style="margin-top:12px">
                <button class="btn small" disabled title="Requires the sports.settle permission">Settle (needs sports.settle)</button>
                <p class="dim" style="font-size:10px;margin-top:6px">Settlement stays with identities holding <b>sports.settle</b>.</p>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <p style="font-size:11px"><a class="btn small" href="/sports/odds-prediction-ticket">Odds prediction tickets &amp; history →</a></p>
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
  // events flash a GOAL banner and highlight the row.  var body = document.getElementById('live-scores-body');
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
      + '<td style="font-weight:700">' + esc(m.homeTeam) + ' vs ' + esc(m.awayTeam) + sim + '</td>'
      + '<td class="dim">' + esc(m.competition) + '</td>'
      + '<td class="num mono live-score-cell" style="font-weight:700;font-size:14px">' + score + '</td>'
      + '<td class="mono dim" style="font-size:11px">' + esc(updated) + '</td>'
      + '</tr>';
  }

  function render(matches){
    if(!matches.length){
      body.innerHTML = '<tr><td colspan="6" class="dim" id="live-scores-empty">No live matches right now — the board refreshes automatically while matches are in play.</td></tr>';
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
    flash.style.display = 'block';
    fresh.forEach(function(ev){
      var row = body.querySelector('tr[data-match-id="' + ev.matchId + '"]');
      if(row){
        row.style.transition = 'background 1.5s ease';
        row.style.background = 'rgba(109,40,217,0.18)';
        setTimeout(function(){ row.style.background = ''; }, 6000);
      }
    });
    clearTimeout(flash._t);
    flash._t = setTimeout(function(){ flash.style.display = 'none'; }, 8000);
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

