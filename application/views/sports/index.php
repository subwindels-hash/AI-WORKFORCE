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
// Capabilities of the signed-in identity (controller reads them fresh from the
// database). Without them the console shows a disabled control plus the reason,
// instead of a button that is refused after the click.
$caps = $caps ?? ['sync' => false, 'approve' => false, 'settle' => false];
?>
<div class="page-head">
  <div>
    <h2>Sports Intelligence — daily ticket engine</h2>
    <p>Daily ticket research from stored fixtures, odds and settled results. Football fixtures, predictions, settlement history and model calibration are reported once, on the Football Intelligence console.</p>
    <?php if (!empty($caps['sync'])): ?>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:12px">
        <form method="post" action="/sports/sync" onsubmit="return confirm('Pull fresh fixtures, odds and results from the configured providers now?')">
          <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
          <button class="btn primary small">Sync now</button>
        </form>
        <form method="post" action="/sports/generate-ticket" style="display:flex;gap:6px;align-items:center" onsubmit="return confirm('Generate odds prediction ticket for the selected date from stored fixtures & odds? This runs the AI ticket engine (value, confidence, risk, correlation) and creates a ticket awaiting approval.')">
          <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
          <input type="date" name="date" value="<?= e((string) ($today['date'] ?? gmdate('Y-m-d'))) ?>" style="padding:6px 8px;border:1px solid var(--line);border-radius:6px;font-size:12px" title="Ticket date (UTC)">
          <button class="btn small" style="background:var(--violet,#6d28d9);color:#fff;border-color:var(--violet,#6d28d9);font-weight:700;letter-spacing:0.02em">
            🎯 GENERATE (odds <b>prediction</b> ticket)
          </button>
        </form>
      </div>
      <p class="dim" style="font-size:11px;margin-top:6px">Sync pulls fixtures/odds from providers. <b>GENERATE</b> builds the ticket from stored data — no external call, idempotent per day/config version.</p>
    <?php else: ?>
      <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <button class="btn small" disabled title="Requires the sports.manage permission">Sync now (needs sports.manage)</button>
        <button class="btn small" disabled title="Requires the sports.manage permission" style="font-weight:700">🎯 GENERATE (odds <b>prediction</b> ticket) — needs sports.manage</button>
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
      <h3>30-day ticket performance (stored settlements only)</h3>
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
          <p class="dim" style="margin-top:12px">No settled tickets or selections yet — metrics are intentionally unavailable rather than invented.</p>
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
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px">
          <?php if (!empty($caps['sync'])): ?>
            <form method="post" action="/sports/generate-ticket" style="display:flex;gap:6px;align-items:center" onsubmit="return confirm('Generate odds prediction ticket for today from stored data?')">
              <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
              <input type="hidden" name="date" value="<?= e((string) ($today['date'] ?? gmdate('Y-m-d'))) ?>">
              <button class="btn small" style="background:var(--violet,#6d28d9);color:#fff;border-color:var(--violet,#6d28d9);font-weight:700">
                🎯 GENERATE (odds <b>prediction</b> ticket)
              </button>
            </form>
            <span class="dim" style="font-size:11px">from stored fixtures & odds — no external call</span>
          <?php else: ?>
            <button class="btn small" disabled title="Requires the sports.manage permission" style="font-weight:700">🎯 GENERATE (odds <b>prediction</b> ticket)</button>
          <?php endif; ?>
        </div>
        <?php if ($daily === null || $ticket === null): ?>
          <p class="dim"><?= $daily !== null ? e((string) ($daily['message'] ?? 'No ticket today.')) : 'No daily run recorded for today yet. Press GENERATE to build one from stored fixtures & odds.' ?></p>
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
            <?php if (!empty($caps['approve'])): ?>
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
            <?php else: ?>
              <div style="margin-top:12px">
                <button class="btn small" disabled title="Requires the sports.approve permission">Approve / reject (needs sports.approve)</button>
                <p class="dim" style="font-size:10px;margin-top:6px">Your account cannot approve tickets — ask an administrator for the <b>sports.approve</b> permission (Sports administrator role).</p>
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

    <p style="font-size:11px"><a class="btn small" href="/sports/tickets">Ticket console &amp; history →</a></p>
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
      var csrf = document.querySelector('meta[name="csrf-token"]')?.content || document.querySelector('input[name="csrf_token"]')?.value || '';
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
          alert('Ticket engine: ' + (data.status||'') + (data.ticketId ? ' — ticket ' + data.ticketId : '') + '\n' + (data.message||''));
          location.href = '/sports/tickets';
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

