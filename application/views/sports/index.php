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
?>
<div class="page-head">
  <div>
    <h2>Sports Intelligence — daily ticket engine</h2>
    <p>Daily ticket research from stored fixtures, odds and settled results. With no sports data connected the module stays off and fabricates nothing.</p>
    <form method="post" action="/sports/sync" style="margin-top:10px" onsubmit="return confirm('Pull fresh fixtures, odds and results from the configured providers now?')">
      <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
      <button class="btn primary small">Sync now (sports.manage)</button>
    </form>
  </div>
</div>
<?php if (!empty($notice)): ?><div class="notice ok"><?= e($notice) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="notice err"><?= e($error) ?></div><?php endif; ?>
<?php if (!empty($sys['isDemoData'])): ?>
  <div class="notice warnbox"><b>SANDBOX / DEMO DATA</b> — sports figures are simulated, not real-world performance.</div>
<?php endif; ?>
<?php if ($disabled): ?>
  <div class="notice warnbox"><b>No sports data connected</b> — no fixtures, odds, predictions or tickets are fabricated until a feed is available.</div>
<?php endif; ?>

<div class="grid cols-main">
  <div class="stack">
    <div class="panel">
      <h3>Today's intelligence — <?= e((string) ($today['date'] ?? gmdate('Y-m-d'))) ?></h3>
      <div class="body" style="padding-top:12px">
        <div class="stat-grid">
          <div class="stat"><div class="k">Scheduled</div><div class="v"><?= (int) ($today['upcomingCount'] ?? 0) ?></div></div>
          <div class="stat"><div class="k">Live</div><div class="v"><?= count($today['live'] ?? []) ?></div></div>
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
        <?php else: ?>
          <p class="dim" style="margin-top:12px">No scheduled fixtures stored for today.</p>
        <?php endif; ?>
      </div>
    </div>

    <div class="panel">
      <h3>30-day performance (stored settlements only)</h3>
      <div class="body" style="padding-top:12px">
        <?php if (!empty($perf['demoBanner'])): ?><div class="notice warnbox"><?= e((string) $perf['demoBanner']) ?></div><?php endif; ?>
        <div class="stat-grid">
          <div class="stat"><div class="k">Settled tickets</div><div class="v"><?= (int) ($perf['settledTickets'] ?? 0) ?></div></div>
          <div class="stat"><div class="k">Win rate</div><div class="v"><?= ($perf['winRate'] ?? null) !== null ? e(number_format((float) $perf['winRate'] * 100, 1)) . '%' : '—' ?></div></div>
          <div class="stat"><div class="k">ROI</div><div class="v <?= ($perf['roi'] ?? null) !== null && (float) $perf['roi'] >= 0 ? 'up' : 'down' ?>"><?= ($perf['roi'] ?? null) !== null ? e(number_format((float) $perf['roi'] * 100, 1)) . '%' : '—' ?></div></div>
          <div class="stat"><div class="k">Profit / loss</div><div class="v <?= ($perf['profitLoss'] ?? null) !== null && (float) $perf['profitLoss'] >= 0 ? 'up' : 'down' ?>"><?= ($perf['profitLoss'] ?? null) !== null ? e(number_format((float) $perf['profitLoss'], 2)) : '—' ?></div></div>
          <div class="stat"><div class="k">Max drawdown</div><div class="v"><?= ($perf['maxDrawdown'] ?? null) !== null ? e(number_format((float) $perf['maxDrawdown'], 2)) : '—' ?></div></div>
          <div class="stat"><div class="k">Avg odds</div><div class="v"><?= ($perf['averageOdds'] ?? null) !== null ? e(number_format((float) $perf['averageOdds'], 2)) : '—' ?></div></div>
          <div class="stat"><div class="k">Model accuracy</div><div class="v"><?= ($perf['modelAccuracy'] ?? null) !== null ? e(number_format((float) $perf['modelAccuracy'] * 100, 1)) . '%' : '—' ?></div></div>
          <div class="stat"><div class="k">Brier</div><div class="v mono"><?= ($perf['calibration']['brier'] ?? null) !== null ? e(number_format((float) $perf['calibration']['brier'], 3)) : '—' ?></div></div>
          <div class="stat"><div class="k">ECE</div><div class="v mono"><?= ($perf['calibration']['ece'] ?? null) !== null ? e(number_format((float) $perf['calibration']['ece'], 3)) : '—' ?></div></div>
        </div>
        <?php if (empty($perf['dataAvailable'])): ?>
          <p class="dim" style="margin-top:12px">No settled tickets or selections yet — metrics are intentionally unavailable rather than invented.</p>
        <?php endif; ?>
      </div>
    </div>

    <div class="panel">
      <h3>Models &amp; calibration</h3>
      <div class="body scroll" style="padding-top:12px">
        <?php if (empty($models['versions'])): ?>
          <p class="dim">No model versions recorded yet.</p>
        <?php else: ?>
          <table class="tbl mono">
            <thead><tr><th>Model</th><th>Version</th><th>Features</th><th>Calibration</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($models['versions'] as $v): ?>
                <tr>
                  <td style="font-weight:700"><?= e((string) ($v['modelName'] ?? '')) ?></td>
                  <td><?= e((string) ($v['modelVersion'] ?? '')) ?></td>
                  <td class="dim"><?= e((string) ($v['featureVersion'] ?? '')) ?></td>
                  <td class="dim"><?= e((string) ($v['calibrationVersion'] ?? '—')) ?></td>
                  <td><span class="badge b-gray"><?= e((string) ($v['status'] ?? '')) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
        <p class="dim" style="margin-top:10px;font-size:11px"><?= count($models['approvedCalibrations'] ?? []) ?> approved calibration version(s). A calibration is only usable after administrator approval — until then ticket-grade decisions report MODEL_NOT_CALIBRATED.</p>
      </div>
    </div>
  </div>

  <div class="stack">
    <div class="panel">
      <h3>System</h3>
      <div class="body" style="padding-top:12px">
        <div class="stat-grid">
          <div class="stat"><div class="k">Mode</div><div class="v"><?= e((string) ($sys['mode'] ?? 'SANDBOX')) ?></div></div>
          <div class="stat"><div class="k">Ticket engine</div><div class="v" style="font-size:12px"><?= e((string) ($sys['ticketEngine'] ?? '—')) ?></div></div>
        </div>
        <table class="tbl" style="margin-top:12px">
          <thead><tr><th>Feed</th><th>Health</th><th class="num">Reliability</th></tr></thead>
          <tbody>
            <?php if (empty($sys['providers'])): ?>
              <tr><td colspan="3" class="dim">No sports data connected.</td></tr>
            <?php else: ?>
              <?php foreach (array_values($sys['providers']) as $i => $p): $st = (string) ($p['derivedStatus'] ?? 'UNKNOWN'); ?>
                <tr>
                  <td style="font-weight:700">Feed <?= (int) $i + 1 ?></td>
                  <td><span class="dot <?= $st === 'ONLINE' ? 'up' : ($st === 'DEGRADED' ? 'synth' : 'down') ?>"></span> <?= e($st) ?></td>
                  <td class="num"><?= ($p['reliability'] ?? null) !== null ? e(number_format((float) $p['reliability'], 2)) : '—' ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
        <?php if (!empty($sys['lastSyncs'])): ?>
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
        <?php endif; ?>
      </div>
    </div>

    <div class="panel">
      <h3>Data feed</h3>
      <div class="body" style="padding-top:12px">
        <?php $configuredIds = $sys['configuredIds'] ?? []; $live = $sys['liveHealth'] ?? []; ?>
        <?php if (empty($configuredIds)): ?>
          <p class="dim"><b>No providers registered.</b> Add a provider key (API-Football, TheSportsDB or SportMonks) via Admin → API or the <span class="mono">WINDELS_*_KEY</span> variables in <span class="mono">.env</span>, then press <b>Sync now</b> above.</p>
        <?php else: ?>
          <table class="tbl">
            <thead><tr><th>Provider</th><th>Health</th></tr></thead>
            <tbody>
              <?php foreach ($configuredIds as $pid): $h = is_array($live[$pid] ?? null) ? $live[$pid] : []; $st = (string) ($h['status'] ?? 'UNKNOWN'); ?>
                <tr>
                  <td class="mono" style="font-weight:700"><?= e((string) $pid) ?></td>
                  <td><span class="dot <?= $st === 'ONLINE' ? 'up' : 'down' ?>"></span> <?= e($st) ?><?php if (!empty($h['detail'])): ?> <span class="dim" style="font-size:10px"><?= e(mb_substr((string) $h['detail'], 0, 120)) ?></span><?php endif; ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
        <?php $recent = $sys['recentSyncs'] ?? []; ?>
        <?php if (!empty($recent)): ?>
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
        <?php elseif (!empty($configuredIds)): ?>
          <p class="dim" style="margin-top:10px">No sync runs recorded yet — press <b>Sync now</b> to pull the first fixtures.</p>
        <?php endif; ?>
      </div>
    </div>

    <div class="panel">
      <h3>Today's ticket</h3>
      <div class="body" style="padding-top:12px">
        <?php $daily = $engine['today'] ?? null; ?>
        <?php if ($daily === null || $ticket === null): ?>
          <p class="dim"><?= $daily !== null ? e((string) ($daily['message'] ?? 'No ticket today.')) : 'No daily run recorded for today yet.' ?></p>
        <?php else: ?>
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
            <span class="badge <?= (string) ($daily['status'] ?? '') === 'PENDING_USER_APPROVAL' ? 'b-violet' : 'b-green' ?>"><?= e((string) ($daily['status'] ?? '')) ?></span>
            <span class="badge b-gray">engine <?= e((string) ($engine['configuration']['engine_mode'] ?? '')) ?></span>
          </div>
          <div class="stat-grid">
            <div class="stat"><div class="k">Total odds</div><div class="v"><?= e(number_format((float) ($ticket['total_odds'] ?? 0), 2)) ?></div></div>
            <div class="stat"><div class="k">Selections</div><div class="v"><?= (int) ($ticket['selection_count'] ?? 0) ?></div></div>
            <div class="stat"><div class="k">Confidence</div><div class="v"><?= ($ticket['confidence'] ?? null) !== null ? e(number_format((float) $ticket['confidence'], 0)) . '%' : '—' ?></div></div>
            <div class="stat"><div class="k">Stake</div><div class="v"><?= ($ticket['stake'] ?? null) !== null ? e(number_format((float) $ticket['stake'], 2)) : '—' ?></div></div>
          </div>
          <?php if (!empty($engine['ticketSelections'])): ?>
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
          <?php endif; ?>
          <?php if ((string) ($ticket['approval_status'] ?? '') === 'PENDING_USER_APPROVAL'): ?>
            <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">
              <form method="post" action="/sports/<?= e((string) $ticket['id']) ?>/decide">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
                <input type="hidden" name="approve" value="1">
                <button class="btn primary small">Approve (sports.approve)</button>
              </form>
              <form method="post" action="/sports/<?= e((string) $ticket['id']) ?>/decide" onsubmit="return confirm('Reject this ticket?')">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
                <input type="hidden" name="approve" value="0">
                <button class="btn danger small">Reject</button>
              </form>
            </div>
            <p class="dim" style="font-size:10px;margin-top:8px">Approval is recorded with the acting identity. There is no external execution connector — approval never places a bet.</p>
          <?php endif; ?>
          <?php if ((string) ($ticket['settlement_status'] ?? '') === 'PENDING'): ?>
            <form method="post" action="/sports/<?= e((string) $ticket['id']) ?>/settle" style="margin-top:12px">
              <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
              <button class="btn small">Settle from verified results (sports.settle)</button>
            </form>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <p style="font-size:11px"><a class="btn small" href="/sports/tickets">Ticket console &amp; history →</a></p>
  </div>
</div>
