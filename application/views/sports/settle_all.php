<?php defined('BASEPATH') or exit('No direct script access allowed');
/**
 * Settlement review for /sports/settle-all.
 *
 * This view is deliberately read-only until its POST form is submitted. Every
 * readiness label comes from stored tickets/results prepared by Sports::
 * settlementPageData(); no outcome is projected and opening the page never
 * verifies a result.
 */
$summary = is_array($settlementSummary ?? null) ? $settlementSummary : [];
$tickets = is_array($settlementTickets ?? null) ? $settlementTickets : [];
$rows = is_array($settlementRows ?? null) ? $settlementRows : [];
$caps = is_array($caps ?? null) ? $caps : ['settle' => false];
$pendingTickets = (int) ($summary['tickets'] ?? count($tickets));
$pendingSelections = (int) ($summary['pendingSelections'] ?? 0);
$readySelections = (int) ($summary['readySelections'] ?? 0);
$corroborationSeconds = (int) ($corroborationSeconds ?? 600);
$corroborationLabel = $corroborationSeconds === 0
    ? 'no waiting period'
    : ($corroborationSeconds % 60 === 0
        ? number_format($corroborationSeconds / 60) . ' minute' . ($corroborationSeconds === 60 ? '' : 's')
        : number_format($corroborationSeconds) . ' seconds');
$stateLabels = [
    'VERIFIED' => 'Verified · ready',
    'READY_TO_VERIFY' => 'Corroborated · ready',
    'CORROBORATING' => 'Corroborating',
    'WAITING_FOR_RESULT' => 'Waiting for result',
    'WAITING_FOR_FINAL' => 'Match not final',
    'INVALID_RESULT' => 'Invalid result',
    'INVALID_TIMESTAMP' => 'Source time missing',
    'RESOLVED' => 'Already resolved',
];
$stateClasses = [
    'VERIFIED' => 'b-green',
    'READY_TO_VERIFY' => 'b-green',
    'CORROBORATING' => 'b-violet',
    'WAITING_FOR_RESULT' => 'b-gray',
    'WAITING_FOR_FINAL' => 'b-gray',
    'INVALID_RESULT' => 'b-red',
    'INVALID_TIMESTAMP' => 'b-red',
    'RESOLVED' => 'b-gray',
];
?>
<div class="sports-console">
  <section class="sports-hero" aria-labelledby="settlement-heading">
    <div class="sports-hero__intro">
      <p class="sports-eyebrow">Verified results settlement</p>
      <h2 id="settlement-heading">Settle all pending tickets</h2>
      <p class="sports-hero__copy">Review the stored result behind every pending pick, then run the same audited sweep used by the hourly sports cron. The sweep never invents a score: missing, live, invalid or not-yet-corroborated results remain pending.</p>
    </div>
    <nav class="sports-hero__actions" aria-label="Settlement page navigation">
      <a class="btn small" href="/sports">← Sports overview</a>
      <a class="btn small" href="/sports/odds-prediction-ticket">Ticket history</a>
    </nav>
  </section>

  <?php if (!empty($notice)): ?><div class="notice ok" role="status"><?= e((string) $notice) ?></div><?php endif; ?>
  <?php if (!empty($error)): ?><div class="notice err" role="alert"><?= e((string) $error) ?></div><?php endif; ?>

  <section class="sports-actionbar" aria-label="Settlement controls">
    <div>
      <b>Settlement queue</b>
      <span class="dim"> · read from storage now · corroboration window <?= e($corroborationLabel) ?></span>
    </div>
    <div class="sports-actionbar__links">
      <a class="btn small" href="/sports/settle-all">Refresh status</a>
      <?php if ($pendingTickets > 0 && !empty($caps['settle'])): ?>
        <form method="post" action="/sports/settle-all" onsubmit="return confirm('Run the settlement sweep now? Only verified or corroborated final results will be applied.');">
          <input type="hidden" name="csrf_token" value="<?= e((string) ($csrfToken ?? '')) ?>">
          <button class="btn primary small" type="submit">Run settlement sweep</button>
        </form>
      <?php elseif ($pendingTickets > 0): ?>
        <button class="btn small" type="button" disabled aria-disabled="true" title="Requires the sports.settle permission">Run settlement sweep</button>
      <?php endif; ?>
    </div>
  </section>

  <div class="sports-layout">
    <div class="sports-main stack">
      <section class="panel sports-section sports-section--feature" aria-labelledby="settlement-summary-heading">
        <div class="sports-section__heading">
          <div class="sports-section__title">
            <span class="sports-step" aria-hidden="true">1</span>
            <div>
              <p class="sports-eyebrow">Queue status</p>
              <h3 id="settlement-summary-heading">What the next sweep can process</h3>
            </div>
          </div>
          <span class="sports-section__meta">stored results only</span>
        </div>
        <div class="body">
          <p class="sports-section-intro">“Ready now” counts pending picks with an already verified result or a valid terminal result that has passed the configured corroboration window. A ticket is finalized only when all of its picks have a settleable result.</p>
          <div class="stat-grid">
            <div class="stat"><div class="k">Pending tickets</div><div class="v"><?= number_format($pendingTickets) ?></div></div>
            <div class="stat"><div class="k">Pending picks</div><div class="v"><?= number_format($pendingSelections) ?></div></div>
            <div class="stat"><div class="k">Ready now</div><div class="v up"><?= number_format($readySelections) ?></div></div>
            <div class="stat"><div class="k">Already verified</div><div class="v"><?= number_format((int) ($summary['verifiedSelections'] ?? 0)) ?></div></div>
            <div class="stat"><div class="k">No result stored</div><div class="v"><?= number_format((int) ($summary['waitingResults'] ?? 0)) ?></div></div>
            <div class="stat"><div class="k">Not final / invalid</div><div class="v"><?= number_format((int) ($summary['waitingFinal'] ?? 0)) ?></div></div>
            <div class="stat"><div class="k">Corroborating</div><div class="v"><?= number_format((int) ($summary['corroborating'] ?? 0)) ?></div></div>
          </div>

          <?php if ($pendingTickets === 0): ?>
            <div class="notice ok"><b>Settlement queue clear.</b> There are no pending sports tickets in the current queue.</div>
          <?php elseif (!empty($caps['settle'])): ?>
            <div class="sports-actions">
              <form method="post" action="/sports/settle-all" onsubmit="return confirm('Settle all eligible pending tickets from stored verified results now?');">
                <input type="hidden" name="csrf_token" value="<?= e((string) ($csrfToken ?? '')) ?>">
                <button class="btn primary" type="submit">Settle all pending tickets from verified results (sports.settle)</button>
              </form>
            </div>
            <p class="sports-note">This action may partially advance a ticket while other picks remain pending. Result promotions are audited as <span class="mono">SPORTS_RESULT_VERIFIED</span>; ticket updates are audited as <span class="mono">SPORTS_TICKET_SETTLED</span>. Refreshing this page does not run the action again.</p>
          <?php else: ?>
            <p class="sports-empty"><b>Review only.</b> Your identity can see the queue but cannot run it. Settlement requires <span class="mono">sports.settle</span> (included in the Sports administrator role).</p>
          <?php endif; ?>
        </div>
      </section>

      <section class="panel sports-section" aria-labelledby="settlement-tickets-heading">
        <div class="sports-section__heading">
          <div class="sports-section__title">
            <span class="sports-step" aria-hidden="true">2</span>
            <div>
              <p class="sports-eyebrow">Pending records</p>
              <h3 id="settlement-tickets-heading">Tickets in this sweep</h3>
            </div>
          </div>
          <span class="sports-section__meta"><?= number_format($pendingTickets) ?> ticket<?= $pendingTickets === 1 ? '' : 's' ?></span>
        </div>
        <div class="body">
          <p class="sports-section-intro">The queue is capped at 200 tickets per manual sweep. Approval and settlement are separate: this page reports the approval state but only changes settlement fields.</p>
          <?php if ($tickets): ?>
            <div class="table-scroll">
              <table class="tbl">
                <thead><tr><th>Ticket</th><th>Created (UTC)</th><th>Approval</th><th class="num">Picks</th><th class="num">Pending</th><th class="num">Ready now</th></tr></thead>
                <tbody>
                  <?php foreach ($tickets as $ticketRow): $ticket = is_array($ticketRow['ticket'] ?? null) ? $ticketRow['ticket'] : []; ?>
                    <tr>
                      <td class="mono sports-cell-strong"><?= e((string) ($ticket['id'] ?? '—')) ?></td>
                      <td class="mono dim"><?= e(substr((string) ($ticket['created_at'] ?? '—'), 0, 16)) ?></td>
                      <td><span class="badge b-gray"><?= e((string) ($ticket['approval_status'] ?? '—')) ?></span></td>
                      <td class="num mono"><?= number_format((int) ($ticketRow['selectionCount'] ?? 0)) ?></td>
                      <td class="num mono"><?= number_format((int) ($ticketRow['pendingSelections'] ?? 0)) ?></td>
                      <td class="num mono <?= (int) ($ticketRow['readySelections'] ?? 0) > 0 ? 'up' : '' ?>"><?= number_format((int) ($ticketRow['readySelections'] ?? 0)) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <p class="sports-empty">No pending tickets are waiting for settlement.</p>
          <?php endif; ?>
        </div>
      </section>

      <section class="panel sports-section" aria-labelledby="settlement-results-heading">
        <div class="sports-section__heading">
          <div class="sports-section__title">
            <span class="sports-step" aria-hidden="true">3</span>
            <div>
              <p class="sports-eyebrow">Result checks</p>
              <h3 id="settlement-results-heading">Readiness by pick</h3>
            </div>
          </div>
          <span class="sports-section__meta">no projected outcomes</span>
        </div>
        <div class="body">
          <p class="sports-section-intro">Each row joins a persisted ticket pick to its latest stored match result. “Corroborated · ready” means the sweep can promote that result and settle from it; opening this page alone does not promote anything.</p>
          <?php if ($rows): ?>
            <div class="table-scroll">
              <table class="tbl">
                <thead><tr><th>Ticket · pick</th><th>Match</th><th>Stored result</th><th>Readiness</th><th>Why / next step</th></tr></thead>
                <tbody>
                  <?php foreach ($rows as $row):
                    $selection = is_array($row['selection'] ?? null) ? $row['selection'] : [];
                    $match = is_array($row['match'] ?? null) ? $row['match'] : [];
                    $result = is_array($row['result'] ?? null) ? $row['result'] : null;
                    $state = (string) ($row['state'] ?? 'WAITING_FOR_RESULT');
                    $homeScore = $result['home_score'] ?? null;
                    $awayScore = $result['away_score'] ?? null;
                    $hasScore = $homeScore !== null && $awayScore !== null;
                  ?>
                    <tr>
                      <td class="sports-cell-strong"><span class="mono"><?= e((string) ($row['ticketId'] ?? '—')) ?></span><small><?= e((string) ($selection['market'] ?? '—')) ?> · <?= e((string) ($selection['selection'] ?? '—')) ?></small></td>
                      <td class="sports-cell-strong"><?= e((string) ($match['home_team'] ?? 'Unknown home')) ?> vs <?= e((string) ($match['away_team'] ?? 'Unknown away')) ?><small><?= e((string) ($match['competition'] ?? '')) ?><?= !empty($match['kickoff_at']) ? ' · ' . e(substr((string) $match['kickoff_at'], 0, 16)) . ' UTC' : '' ?></small></td>
                      <td><?= $result === null ? '<span class="dim">Not stored</span>' : '<span class="mono">' . e((string) ($result['status'] ?? 'UNKNOWN')) . ($hasScore ? ' · ' . (int) $homeScore . '–' . (int) $awayScore : '') . '</span><small class="dim">source ' . e(substr((string) ($result['source_timestamp'] ?? '—'), 0, 19)) . '</small>' ?></td>
                      <td><span class="badge <?= e($stateClasses[$state] ?? 'b-gray') ?>"><?= e($stateLabels[$state] ?? $state) ?></span></td>
                      <td class="sports-cell-detail"><?= e((string) ($row['reason'] ?? '')) ?><?php if (!empty($row['retryAt'])): ?><small class="dim">eligible after approximately <?= e(substr((string) $row['retryAt'], 0, 19)) ?> UTC</small><?php endif; ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php else: ?>
            <p class="sports-empty">There are no pending ticket picks to check.</p>
          <?php endif; ?>
        </div>
      </section>
    </div>

    <aside class="sports-side stack" aria-label="Settlement rules">
      <section class="panel sports-section" aria-labelledby="settlement-rules-heading">
        <div class="sports-section__heading">
          <div class="sports-section__title"><div><p class="sports-eyebrow">Safety rules</p><h3 id="settlement-rules-heading">What the sweep will do</h3></div></div>
        </div>
        <div class="body">
          <dl class="sports-defs">
            <div><dt>Use stored evidence only</dt><dd>No provider score is guessed or entered by this page.</dd></div>
            <div><dt>Verify before settlement</dt><dd>A result must be terminal and valid. An unverified result also waits for <?= e($corroborationLabel) ?> from its provider source timestamp.</dd></div>
            <div><dt>Leave incomplete tickets pending</dt><dd>If even one pick still lacks a settleable result, the ticket remains pending and contributes nothing to performance.</dd></div>
            <div><dt>Safe to retry</dt><dd>The sweep is idempotent. Already resolved picks are not settled a second time.</dd></div>
          </dl>
        </div>
      </section>

      <section class="panel sports-section" aria-labelledby="settlement-states-heading">
        <div class="sports-section__heading">
          <div class="sports-section__title"><div><p class="sports-eyebrow">Readiness</p><h3 id="settlement-states-heading">State guide</h3></div></div>
        </div>
        <div class="body">
          <dl class="sports-defs">
            <div><dt><span class="badge b-green">Verified · ready</span></dt><dd>The result is verified now.</dd></div>
            <div><dt><span class="badge b-green">Corroborated · ready</span></dt><dd>The sweep will audit verification, then apply the result.</dd></div>
            <div><dt><span class="badge b-violet">Corroborating</span></dt><dd>The result is final but too recent to auto-verify.</dd></div>
            <div><dt><span class="badge b-gray">Waiting</span></dt><dd>The result is missing or the match is not final.</dd></div>
          </dl>
        </div>
      </section>
    </aside>
  </div>
</div>
