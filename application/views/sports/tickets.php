<?php defined('BASEPATH') or exit('No direct script access allowed');
/** @var array $tickets @var array $dailyRuns @var array $performance @var array $caps */
$perf = $performance ?? [];
// Capabilities of the signed-in identity (fresh from the database, see
// Sports::sportsCaps). Missing capabilities render as a reason, not a button.
$caps = $caps ?? ['sync' => false, 'approve' => false, 'settle' => false];
$ticketDateIso = (string) ($todayIso ?? gmdate('Y-m-d'));
$ticketDateShown = gmdate('m/d/Y', (int) strtotime($ticketDateIso . ' 00:00:00 UTC'));
?>
<div class="page-head">
  <div>
    <h2>Odds prediction tickets</h2>
    <p>Generated odds prediction tickets, approval state and stored settlements. Each ticket records the offered odds, model probability, expected value, confidence, risk and result; approve, reject and settle stay permission-gated. This deployment is odds analysis only and has no external bookmaker.</p>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:12px">
      <?php if (!empty($caps['sync'])): ?>
        <form method="post" action="/sports/generate-ticket" style="display:flex;gap:6px;align-items:center" onsubmit="return confirm('Generate odds prediction ticket for today from stored fixtures & odds?')">
          <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
          <input type="date" name="date" value="<?= e($ticketDateIso) ?>" aria-label="<?= e($ticketDateShown) ?>" style="padding:6px 8px;border:1px solid var(--line);border-radius:6px;font-size:12px" title="Ticket date (UTC) <?= e($ticketDateShown) ?>">
          <span class="mono" style="font-size:12px;font-weight:700"><?= e($ticketDateShown) ?></span>
          <button class="btn small" style="background:var(--violet,#6d28d9);color:#fff;border-color:var(--violet,#6d28d9);font-weight:700;letter-spacing:0.02em">
            🎯 Odds Prediction Ticket
          </button>
        </form>
        <a class="btn small" href="/sports">Sports Intelligence →</a>
      <?php else: ?>
        <span class="mono" style="padding:6px 8px;border:1px solid var(--line);border-radius:6px;font-size:12px;font-weight:700" title="Ticket date (UTC)"><?= e($ticketDateShown) ?></span>
        <button class="btn small" disabled title="Requires the sports.manage permission" style="font-weight:700">🎯 Odds Prediction Ticket</button>
        <a class="btn small" href="/sports">Sports Intelligence →</a>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php if (!empty($notice)): ?><div class="notice ok"><?= e($notice) ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="notice err"><?= e($error) ?></div><?php endif; ?>

<?php
// Today's AI ticket hero — the focused daily view: status, headline numbers,
// selections, approval. Everything else on this page stays as the
// history/details layer underneath.
$heroRun = $todayRun ?? null;
$heroTicket = $todayTicket ?? null;
$heroSelections = $todaySelections ?? [];
$heroDate = isset($todayIso) ? gmdate('m/d/Y', (int) strtotime((string) $todayIso . ' 00:00:00 UTC')) : $ticketDateShown;
$heroStatus = 'NOT GENERATED';
$heroBadge = 'b-gray';
$heroGenerationStatus = is_array($heroRun) ? (string) ($heroRun['generation_status'] ?? (!empty($heroRun['ticket_id']) ? 'GENERATED' : 'PENDING')) : 'PENDING';
$heroDiag = is_array($heroRun) && is_array($heroRun['rejection_summary']['_diagnostics'] ?? null) ? $heroRun['rejection_summary']['_diagnostics'] : [];
if ($heroTicket !== null) {
    // Approval is a separate governance state. The generation state is exactly
    // GENERATED once the persisted ticket and legs can be loaded.
    $heroStatus = 'GENERATED';
    $heroGenerationStatus = 'GENERATED';
    $heroBadge = 'b-violet';
} elseif (is_array($heroRun)) {
    $runStatus = (string) ($heroRun['status'] ?? '');
    if ($runStatus === 'NO_QUALIFIED_TICKET') { $heroStatus = 'NO_QUALIFIED_TICKET'; $heroBadge = 'b-red'; }
    elseif ($heroGenerationStatus === 'RUNNING' || $heroGenerationStatus === 'RETRYING') { $heroStatus = $heroGenerationStatus; $heroBadge = 'b-gray'; }
    elseif ($runStatus !== '') { $heroStatus = $runStatus; $heroBadge = 'b-gray'; }
}
$heroMarketLabel = function (string $market, string $selection): string {
    static $labels = [
        'MATCH_RESULT:HOME' => 'Home Win', 'MATCH_RESULT:DRAW' => 'Draw', 'MATCH_RESULT:AWAY' => 'Away Win',
        'DOUBLE_CHANCE:HOME_OR_DRAW' => 'Double Chance (Home/Draw)', 'DOUBLE_CHANCE:AWAY_OR_DRAW' => 'Double Chance (Away/Draw)', 'DOUBLE_CHANCE:HOME_OR_AWAY' => 'Double Chance (Home/Away)',
        'TOTAL_GOALS:OVER_1_5' => 'Over 1.5 Goals', 'TOTAL_GOALS:OVER_2_5' => 'Over 2.5 Goals', 'TOTAL_GOALS:OVER_3_5' => 'Over 3.5 Goals',
        'TOTAL_GOALS:UNDER_2_5' => 'Under 2.5 Goals', 'TOTAL_GOALS:UNDER_3_5' => 'Under 3.5 Goals',
        'BTTS:YES' => 'Both Teams To Score',
    ];
    return $labels[$market . ':' . $selection] ?? ($market . ' / ' . $selection);
};
?>
<div class="panel" style="margin-bottom:16px;border-color:var(--violet,#6d28d9)">
  <h3>AI Daily Ticket <span class="dim mono" style="font-weight:400;font-size:12px"><?= e((string) $heroDate) ?></span></h3>
  <div class="body" style="padding-top:12px">
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px">
      <span style="font-size:12px" class="dim">Status:</span>
      <span class="badge <?= $heroBadge ?>" style="font-size:13px"><?= e($heroStatus) ?></span>
      <?php if ($heroTicket !== null): ?>
        <span class="dim mono" style="font-size:12px">Ticket ID: <b><?= e((string) ($heroTicket['id'] ?? '—')) ?></b></span>
        <span class="dim mono" style="font-size:12px">Total Odds: <b><?= e(number_format((float) ($heroTicket['total_odds'] ?? 0), 2)) ?></b></span>
        <span class="dim mono" style="font-size:12px">Selections: <b><?= (int) ($heroTicket['selection_count'] ?? count($heroSelections)) ?></b></span>
        <span class="dim mono" style="font-size:12px">Overall Confidence: <b><?= ($heroTicket['confidence'] ?? null) !== null ? e(number_format((float) $heroTicket['confidence'], 0)) . '%' : '—' ?></b></span>
        <span class="dim mono" style="font-size:12px">Risk: <b><?= e((string) ($heroTicket['risk'] ?? '—')) ?></b></span>
        <span class="dim mono" style="font-size:12px">Generated: <b><?= e((string) ($heroRun['generated_at'] ?? $heroTicket['created_at'] ?? '—')) ?></b></span>
        <span class="dim mono" style="font-size:12px">Approval: <b><?= e((string) ($heroTicket['approval_status'] ?? '—')) ?></b></span>
      <?php endif; ?>
    </div>
    <?php if (is_array($heroRun)): ?>
      <div class="stat-grid" style="margin-bottom:12px">
        <div class="stat"><div class="k">Generation status</div><div class="v" style="font-size:13px"><?= e($heroGenerationStatus) ?></div></div>
        <div class="stat"><div class="k">Eligible fixtures</div><div class="v"><?= (int) ($heroDiag['eligibleFixtures'] ?? 0) ?></div></div>
        <div class="stat"><div class="k">Candidates evaluated</div><div class="v"><?= (int) ($heroDiag['marketsEvaluated'] ?? $heroRun['candidates_evaluated'] ?? 0) ?></div></div>
        <div class="stat"><div class="k">Predictions generated</div><div class="v"><?= (int) ($heroDiag['predictionsGenerated'] ?? $heroRun['predictions_recorded'] ?? 0) ?></div></div>
        <div class="stat"><div class="k">Fresh odds</div><div class="v"><?= (int) ($heroDiag['fixturesWithFreshOdds'] ?? 0) ?></div></div>
        <div class="stat"><div class="k">Stale odds</div><div class="v"><?= (int) ($heroDiag['fixturesRejectedStaleOdds'] ?? 0) ?></div></div>
        <div class="stat"><div class="k">Qualified candidates</div><div class="v"><?= (int) ($heroDiag['correlationQualifiedCandidates'] ?? 0) ?></div></div>
        <div class="stat"><div class="k">Selected picks</div><div class="v"><?= (int) ($heroDiag['finalQualifiedCandidates'] ?? count($heroSelections)) ?></div></div>
      </div>
      <?php if ($heroTicket === null && in_array($heroGenerationStatus, ['FAILED', 'RETRYING'], true)): ?>
        <div class="notice err" style="margin-bottom:12px"><b>Reason:</b> <?= e((string) ($heroRun['last_error_code'] ?? $heroRun['status'] ?? 'GENERATION_FAILED')) ?><br><b>Retry:</b> <?= !empty($heroRun['next_retry_at']) ? 'SCHEDULED — ' . e((string) $heroRun['next_retry_at']) : 'AVAILABLE' ?></div>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($heroTicket !== null && !empty($heroSelections)): ?>
      <div class="table-scroll">
        <table class="tbl">
          <thead><tr><th>Match · competition · kickoff</th><th>Market · prediction</th><th class="num" title="Real bookmaker price from the named odds source, with the time it was last updated">Real market odds · source</th><th class="num" title="WINDELS model probability and fair odds — derived by the model, never the bookmaker price">WINDELS probability · fair</th><th class="num">Confidence</th><th class="num">Data quality</th><th class="num">Value / edge</th><th>Risk</th></tr></thead>
          <tbody>
            <?php foreach ($heroSelections as $sel): ?>
              <tr>
                <td style="font-weight:700">
                  <?= e(trim((string) (($sel['home_team'] ?? '') . ' vs ' . ($sel['away_team'] ?? '')))) ?>
                  <span class="dim" style="display:block;font-size:10px;font-weight:400"><?= e((string) ($sel['competition'] ?? '—')) ?> · <?= e((string) ($sel['kickoff_time'] ?? '—')) ?></span>
                </td>
                <td><span class="dim" style="display:block;font-size:10px"><?= e((string) ($sel['market'] ?? '')) ?></span><?= e($heroMarketLabel((string) ($sel['market'] ?? ''), (string) ($sel['selection'] ?? ''))) ?></td>
                <td class="num mono">
                  <?= e(number_format((float) ($sel['odds'] ?? 0), 2)) ?>
                  <span class="dim" style="display:block;font-size:10px;font-weight:400" title="Odds source and the provider's last-update timestamp (UTC)">
                    <?= e((string) ($sel['odds_source'] ?? '—')) ?> · <?= e(substr((string) ($sel['odds_timestamp'] ?? ''), 0, 16)) ?>
                  </span>
                </td>
                <td class="num mono">
                  <?= ($sel['calibrated_probability'] ?? null) !== null ? e(number_format((float) $sel['calibrated_probability'] * 100, 1)) . '%' : '—' ?>
                  <span class="dim" style="display:block;font-size:10px;font-weight:400" title="WINDELS fair odds (1 ÷ model probability), not the bookmaker price">
                    fair <?= ($sel['fair_odds'] ?? null) !== null ? e(number_format((float) $sel['fair_odds'], 2)) : '—' ?>
                  </span>
                </td>
                <td class="num"><?= ($sel['confidence'] ?? null) !== null ? e(number_format((float) $sel['confidence'], 0)) . '%' : '—' ?></td>
                <td class="num"><?= ($sel['data_quality'] ?? null) !== null ? e(number_format((float) $sel['data_quality'], 0)) : '—' ?></td>
                <td class="num mono">
                  <?= ($sel['expected_value'] ?? null) !== null ? e(number_format((float) $sel['expected_value'] * 100, 2)) . '%' : '—' ?>
                  <span class="dim" style="display:block;font-size:10px;font-weight:400">model edge / EV</span>
                </td>
                <td><span class="badge <?= (string) ($sel['risk'] ?? '') === 'LOW' ? 'b-green' : ((string) ($sel['risk'] ?? '') === 'HIGH' ? 'b-red' : 'b-violet') ?>"><?= e((string) ($sel['risk'] ?? '—')) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="dim" style="font-size:11px;margin:6px 0 0">Bookmaker odds are the provider's real quoted price with its source and last-update time. WINDELS probability/fair odds are the model's own numbers, kept in separate columns; expected value compares the two.</p>
      <?php if ((string) ($heroTicket['approval_status'] ?? '') === 'PENDING_USER_APPROVAL'): ?>
        <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">
          <?php if (!empty($caps['approve'])): ?>
            <form method="post" action="/sports/<?= e((string) $heroTicket['id']) ?>/decide" style="display:inline">
              <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>"><input type="hidden" name="approve" value="1"><button class="btn small primary">Approve ticket</button>
            </form>
            <form method="post" action="/sports/<?= e((string) $heroTicket['id']) ?>/decide" style="display:inline" onsubmit="return confirm('Reject this record?')">
              <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>"><input type="hidden" name="approve" value="0"><button class="btn small danger">Reject</button>
            </form>
          <?php else: ?>
            <span class="dim" style="font-size:11px">Approval requires the sports.approve permission. Analysis only — no real-money bets are placed.</span>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    <?php elseif ($heroStatus === 'NO_QUALIFIED_TICKET'): ?>
      <p style="margin:0 0 6px;font-weight:700">NO_QUALIFIED_TICKET — today&apos;s available matches did not meet the configured safety requirements.</p>
      <?php if (is_array($heroRun) && trim((string) ($heroRun['message'] ?? '')) !== ''): ?>
        <p class="dim" style="margin:0;font-size:12px"><?= e((string) $heroRun['message']) ?></p>
      <?php endif; ?>
    <?php else: ?>
      <p class="dim" style="margin:0">Automatic daily generation is pending. When Odds Prediction is enabled, the scheduler syncs eligible fixtures, refreshes stale odds in controlled batches, evaluates the safety gates and persists today&apos;s result. The button above is an optional manual retry.</p>
    <?php endif; ?>
  </div>
</div>

<div class="stack">
    <p class="dim" style="margin:0 0 12px;font-size:12px">Ticket P/L is below; prediction accuracy, Brier, ECE and the 30-day settlement window are reported once, on <a href="/football">Football Intelligence</a>.<?php if (!empty($perf['demoBanner'])): ?> <b><?= e((string) $perf['demoBanner']) ?></b><?php endif; ?></p>
  <div class="panel">
    <h3>Odds prediction tickets</h3>
    <div class="body scroll" style="padding-top:12px">
      <div style="display:flex;gap:8px;align-items:center;margin-bottom:12px;flex-wrap:wrap">
        <?php if (!empty($caps['sync'])): ?>
          <form method="post" action="/sports/generate-ticket" style="display:flex;gap:6px;align-items:center" onsubmit="return confirm('Generate odds prediction ticket now?')">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
            <input type="hidden" name="date" value="<?= e(gmdate('Y-m-d')) ?>">
            <button class="btn small primary" style="font-weight:700">🎯 Odds Prediction Ticket</button>
          </form>
          <span class="dim" style="font-size:11px">Optional manual run/retry. Existing tickets are returned; missing tickets are generated without duplicates.</span>
        <?php endif; ?>
      </div>
      <?php if (empty($tickets)): ?>
        <p class="dim">No odds prediction tickets have been persisted yet. Automatic generation runs whenever the configured system is enabled; the button above is an optional immediate retry.</p>
      <?php else: ?>
        <div class="table-scroll">
          <table class="tbl">
            <thead><tr><th>Ticket</th><th>Created (UTC)</th><th class="num">Odds</th><th class="num">Sel.</th><th class="num">Conf.</th><th>Risk</th><th>Approval</th><th>Settlement</th><th class="num">P/L</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($tickets as $t): $pnl = $t['pnl'] ?? null; ?>
                <tr>
                  <td class="mono" style="font-weight:700"><?= e((string) ($t['id'] ?? '')) ?></td>
                  <td class="mono dim"><?= e(substr((string) ($t['created_at'] ?? ''), 0, 16)) ?></td>
                  <td class="num mono"><?= e(number_format((float) ($t['total_odds'] ?? 0), 2)) ?></td>
                  <td class="num"><?= (int) ($t['selection_count'] ?? 0) ?></td>
                  <td class="num"><?= ($t['confidence'] ?? null) !== null ? e(number_format((float) $t['confidence'], 0)) : '—' ?></td>
                  <td><span class="badge <?= (string) ($t['risk'] ?? '') === 'LOW' ? 'b-green' : ((string) ($t['risk'] ?? '') === 'HIGH' ? 'b-red' : 'b-violet') ?>"><?= e((string) ($t['risk'] ?? '—')) ?></span></td>
                  <td><span class="badge b-gray"><?= e((string) ($t['approval_status'] ?? '—')) ?></span></td>
                  <td><span class="badge <?= in_array(($t['settlement_status'] ?? ''), ['WON'], true) ? 'b-green' : (in_array(($t['settlement_status'] ?? ''), ['LOST'], true) ? 'b-red' : 'b-gray') ?>"><?= e((string) ($t['settlement_status'] ?? 'PENDING')) ?></span></td>
                  <td class="num mono <?= $pnl !== null && (float) $pnl >= 0 ? 'up' : 'down' ?>"><?= $pnl !== null ? e(number_format((float) $pnl, 2)) : '—' ?></td>
                  <td class="num" style="white-space:nowrap">
                    <?php if ((string) ($t['approval_status'] ?? '') === 'PENDING_USER_APPROVAL'): ?>
                      <?php if (!empty($caps['approve'])): ?>
                        <form method="post" action="/sports/<?= e((string) $t['id']) ?>/decide" style="display:inline">
                          <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>"><input type="hidden" name="approve" value="1"><button class="btn small primary">approve</button>
                        </form>
                        <form method="post" action="/sports/<?= e((string) $t['id']) ?>/decide" style="display:inline" onsubmit="return confirm('Reject this record?')">
                          <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>"><input type="hidden" name="approve" value="0"><button class="btn small danger">reject</button>
                        </form>
                      <?php else: ?>
                        <span class="dim" style="font-size:10px" title="Requires the sports.approve permission">needs sports.approve</span>
                      <?php endif; ?>
                    <?php endif; ?>
                    <?php if ((string) ($t['settlement_status'] ?? '') === 'PENDING'): ?>
                      <?php if (!empty($caps['settle'])): ?>
                        <form method="post" action="/sports/<?= e((string) $t['id']) ?>/settle" style="display:inline">
                          <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>"><button class="btn small">settle</button>
                        </form>
                      <?php else: ?>
                        <span class="dim" style="font-size:10px" title="Requires the sports.settle permission">needs sports.settle</span>
                      <?php endif; ?>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="panel">
    <h3>Daily odds prediction ticket runs</h3>
    <div class="body scroll" style="padding-top:12px">
      <?php if (empty($dailyRuns)): ?>
        <p class="dim">No daily runs recorded yet.</p>
      <?php else: ?>
        <table class="tbl">
          <thead><tr><th>Date</th><th>Generation</th><th>Outcome</th><th>Ticket</th><th class="num">Evaluated</th><th class="num">Recorded</th><th class="num">Rejected</th><th>Message / retry</th></tr></thead>
          <tbody>
            <?php foreach ($dailyRuns as $r): ?>
              <tr>
                <td class="mono" style="font-weight:700"><?= e((string) ($r['date'] ?? '')) ?></td>
                <td><span class="badge <?= (string) ($r['generation_status'] ?? '') === 'GENERATED' ? 'b-violet' : ((string) ($r['generation_status'] ?? '') === 'FAILED' ? 'b-red' : 'b-gray') ?>"><?= e((string) ($r['generation_status'] ?? (!empty($r['ticket_id']) ? 'GENERATED' : 'PENDING'))) ?></span></td>
                <td><span class="badge <?= in_array(($r['status'] ?? ''), ['PENDING_USER_APPROVAL', 'APPROVED'], true) ? 'b-violet' : 'b-gray' ?>"><?= e((string) ($r['status'] ?? '')) ?></span></td>
                <td class="mono dim"><?= $r['ticket_id'] ? e((string) $r['ticket_id']) : '—' ?></td>
                <td class="num"><?= (int) ($r['candidates_evaluated'] ?? 0) ?></td>
                <td class="num"><?= (int) ($r['predictions_recorded'] ?? 0) ?></td>
                <td class="num"><?= (int) ($r['rejections'] ?? 0) ?></td>
                <td class="dim"><?= e(mb_substr((string) ($r['message'] ?? ''), 0, 120)) ?><?php if (!empty($r['next_retry_at'])): ?><br><span class="mono">retry <?= e((string) $r['next_retry_at']) ?></span><?php endif; ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
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

