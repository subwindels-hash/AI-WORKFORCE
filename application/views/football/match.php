<?php defined('BASEPATH') or exit('No direct script access allowed');
/** One stored fixture, organized as prediction → full odds sheet → evidence. */
$analysis = $analysis ?? [];
$fixture = $analysis['fixture'] ?? [];
$teams = $analysis['teams'] ?? [];
$quality = $analysis['dataQuality'] ?? [];
$coverage = $analysis['coverage'] ?? [];
$provenance = $analysis['provenance'] ?? [];
$h2h = $analysis['headToHead'] ?? [];
$inMatch = $analysis['inMatch'] ?? [];
$contract = $prediction['prediction'] ?? null;
$settlement = $prediction['settlement'] ?? null;
$liveEstimates = $prediction['liveEstimates'] ?? [];
$markets = is_array($prediction['markets'] ?? null) ? $prediction['markets'] : [];
// The catalogue keeps OVER_* and UNDER_* aliases for filtering, but a complete
// match sheet should show each two-sided bookmaker family/line only once.
$displayMarkets = [];
$seenMarketFamilies = [];
foreach ($markets as $candidateMarket) {
    $candidatePricing = is_array($candidateMarket['pricing'] ?? null) ? $candidateMarket['pricing'] : [];
    $family = (string) ($candidatePricing['family'] ?? $candidateMarket['key'] ?? '');
    $lineKey = isset($candidatePricing['line']) && is_numeric($candidatePricing['line'])
        ? number_format((float) $candidatePricing['line'], 2, '.', '') : '';
    $displayKey = $family . '|' . $lineKey;
    if ($displayKey !== '|' && isset($seenMarketFamilies[$displayKey])) continue;
    $seenMarketFamilies[$displayKey] = true;
    $displayMarkets[] = $candidateMarket;
}
$markets = $displayMarkets;
$selectedMarket = is_array($prediction['market'] ?? null) ? $prediction['market'] : [];
$intel = is_array($prediction['intelligence'] ?? null) ? $prediction['intelligence'] : [];
$caps = $caps ?? ['sync' => false, 'calibrate' => false, 'approve' => false, 'settle' => false];
$matchId = (int) ($fixtureId ?? 0);

$number = static fn(mixed $value, int $places = 2): string => is_numeric($value) ? number_format((float) $value, $places) : '—';
$pct = static fn(mixed $value, int $places = 1): string => is_numeric($value) ? number_format((float) $value * 100, $places) . '%' : '—';
$odds = static fn(mixed $value): string => is_numeric($value) ? number_format((float) $value, 2) : '—';
$signedPct = static fn(mixed $value): string => is_numeric($value) ? ((float) $value >= 0 ? '+' : '') . number_format((float) $value * 100, 1) . '%' : '—';
$state = static fn(mixed $value): string => $value === null || $value === '' || $value === [] ? 'DATA_UNAVAILABLE' : (string) $value;
$pretty = static fn(string $key): string => trim(preg_replace('/(?<!^)[A-Z]/', ' $0', $key) ?? $key);
$bandClass = static fn(string $band): string => match (strtoupper($band)) {
    'QUALIFIED', 'AVAILABLE', 'FRESH', 'LOW', 'CALIBRATED' => 'b-green',
    'LIMITED', 'LIMITED_DATA', 'PARTIAL_MARKET', 'STALE', 'MEDIUM' => 'b-amber',
    'HIGH', 'REJECTED', 'DATA_UNAVAILABLE', 'UNPRICED' => 'b-red',
    default => 'b-gray',
};
$stamp = static function (mixed $value, string $format = 'D j M Y · H:i'): string {
    $time = is_string($value) && trim($value) !== '' ? strtotime($value) : false;
    return $time === false ? 'DATA_UNAVAILABLE' : gmdate($format, $time) . ' UTC';
};
$score = static fn(mixed $row, string $side): string => is_array($row) && isset($row[$side]) && is_numeric($row[$side]) ? (string) (int) $row[$side] : '—';
$scoreBlock = is_array($intel['score'] ?? null) ? $intel['score'] : [];
$valueBlock = is_array($intel['fairValue'] ?? null) ? $intel['fairValue'] : [];
$stabilityBlock = is_array($intel['stability'] ?? null) ? $intel['stability'] : [];
$qualityBlock = is_array($intel['quality'] ?? null) ? $intel['quality'] : [];
$driverBlock = is_array($intel['drivers'] ?? null) ? $intel['drivers'] : [];
$freshBlock = is_array($intel['freshness'] ?? null) ? $intel['freshness'] : [];
$withheldBlock = is_array($intel['withheld'] ?? null) ? $intel['withheld'] : [];
?>
<div class="football-match-page">
  <section class="football-match-hero" aria-labelledby="match-heading">
    <div>
      <p class="football-eyebrow">Match intelligence</p>
      <h2 id="match-heading"><?= crest($fixture['homeTeamLogo'] ?? null, 24) ?><?= e((string) ($fixture['homeTeam'] ?? '—')) ?> vs <?= crest($fixture['awayTeamLogo'] ?? null, 24) ?><?= e((string) ($fixture['awayTeam'] ?? '—')) ?></h2>
      <p><?= e($state($fixture['competition'] ?? null)) ?><?= !empty($fixture['country']) ? ' · ' . e((string) $fixture['country']) : '' ?> · kickoff <?= e($stamp($fixture['kickoff'] ?? null)) ?> · <b><?= e((string) ($fixture['status'] ?? 'UNKNOWN')) ?></b><?php if (isset($fixture['minute']) && is_numeric($fixture['minute'])): ?> · <?= (int) $fixture['minute'] ?>'<?php endif; ?></p>
    </div>
    <div class="football-match-hero__actions"><a class="btn small" href="/football">← Back to football board</a><a class="btn small football-ticket-link" href="/sports">Odds prediction tickets</a></div>
  </section>

  <?php if (!empty($notice)): ?><div class="notice ok"><?= e($notice) ?></div><?php endif; ?>
  <?php if (!empty($error)): ?><div class="notice err"><?= e($error) ?></div><?php endif; ?>

  <div class="football-match-layout">
    <div class="stack">
      <section class="panel">
        <h3>Prediction overview</h3>
        <div class="body">
          <?php if ($contract === null): ?>
            <p><?= e((string) ($prediction['message'] ?? 'No prediction row is stored for this fixture.')) ?></p>
            <?php if (!empty($prediction['reason'])): ?><p class="football-help"><?= e((string) $prediction['reason']) ?></p><?php endif; ?>
            <?php if ($matchId > 0): ?>
              <form method="post" action="/football/match/<?= $matchId ?>/analyze" style="margin-top:12px" onsubmit="return confirm('Analyze this match from its stored data now?')">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
                <button class="btn primary" <?= empty($caps['sync']) ? 'disabled title="Requires the sports.manage permission"' : '' ?>>Analyze this match — generate odds prediction</button>
              </form>
            <?php endif; ?>
          <?php else: ?>
            <?php $p = is_array($contract['prediction'] ?? null) ? $contract['prediction'] : []; $probabilities = is_array($p['probabilities'] ?? null) ? $p['probabilities'] : []; $raw = is_array($contract['rawProbabilities'] ?? null) ? $contract['rawProbabilities'] : []; ?>
            <div class="football-outcome-grid">
              <div class="football-outcome"><span>Model outcome</span><b><?= e((string) ($p['result'] ?? '—')) ?></b><small>most likely stored score distribution</small></div>
              <div class="football-outcome"><span>Predicted score</span><b class="mono"><?= $score($p['predictedScore'] ?? null, 'home') ?>–<?= $score($p['predictedScore'] ?? null, 'away') ?></b><small>pre-match prediction</small></div>
              <div class="football-outcome"><span>Confidence</span><b class="mono"><?= $number($p['confidence'] ?? null, 1) ?>%</b><small><?= e((string) ($p['confidenceBasis'] ?? 'RAW')) ?></small></div>
            </div>
            <div class="table-scroll" style="margin-top:12px">
              <table class="tbl">
                <thead><tr><th>Result</th><th class="num">WINDELS probability</th><th class="num">Raw model</th><th class="num">Fair odds</th></tr></thead>
                <tbody>
                  <?php foreach (['home' => ['Home win', $fixture['homeTeam'] ?? ''], 'draw' => ['Draw', ''], 'away' => ['Away win', $fixture['awayTeam'] ?? '']] as $key => $info): ?>
                    <?php $modelP = $probabilities[$key] ?? null; ?>
                    <tr><td><b><?= e((string) $info[0]) ?></b><?= $info[1] !== '' ? ' · ' . e((string) $info[1]) : '' ?></td><td class="num mono"><?= $pct($modelP) ?></td><td class="num mono dim"><?= $pct($raw[$key] ?? null) ?></td><td class="num mono"><?= is_numeric($modelP) && (float) $modelP > 0 ? $odds(1 / (float) $modelP) : '—' ?></td></tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <dl class="football-key-values" style="margin-top:14px">
              <div><dt>Expected total goals</dt><dd class="mono"><?= $number($p['expectedTotalGoals'] ?? null) ?></dd></div>
              <div><dt>Data quality</dt><dd><span class="badge <?= $bandClass((string) ($contract['dataQuality']['status'] ?? '')) ?>"><?= e((string) ($contract['dataQuality']['status'] ?? '—')) ?> · <?= (int) ($contract['dataQuality']['score'] ?? 0) ?>/100</span></dd></div>
              <div><dt>Model version</dt><dd class="mono"><?= e((string) ($contract['model']['version'] ?? '—')) ?> · <?= e((string) ($p['calibrationState'] ?? 'CALIBRATION_PENDING')) ?></dd></div>
              <div><dt>Generated</dt><dd class="mono"><?= e($stamp($contract['generatedAt'] ?? null)) ?></dd></div>
            </dl>
            <?php if (!empty($contract['reason'])): ?><p class="football-help"><b>Model reasoning:</b> <?= e((string) $contract['reason']) ?></p><?php endif; ?>
          <?php endif; ?>
        </div>
      </section>

      <section class="panel football-match-odds" aria-labelledby="all-odds-heading">
        <h3 id="all-odds-heading">All market odds and information</h3>
        <div class="body">
          <p class="football-help" style="margin-top:0">Every modelled market is shown below. Where the provider quoted a price, the sheet includes its timestamp and source. Where it did not, the market remains <b>UNPRICED</b>. Provider-price-only markets such as corners, cards and HT/FT never receive a made-up WINDELS probability.</p>
          <?php if ($markets === []): ?>
            <div class="empty-state"><p>Analyze this fixture first to build its market sheet. No odds or probabilities are invented before a stored prediction exists.</p></div>
          <?php else: ?>
            <?php foreach ($markets as $market): ?>
              <?php
              $outcomes = is_array($market['outcomes'] ?? null) ? $market['outcomes'] : [];
              $pricing = is_array($market['pricing'] ?? null) ? $market['pricing'] : [];
              $providerOnly = (string) ($market['source'] ?? '') === \AIWorkforce\Football\PredictionMarkets::SOURCE_ODDS;
              ?>
              <section class="football-market-card">
                <header>
                  <div><p><?= e((string) ($market['group'] ?? 'Market')) ?></p><h5><?= e((string) ($market['label'] ?? $market['key'] ?? 'Market')) ?></h5><small><?= count($outcomes) ?> selection<?= count($outcomes) === 1 ? '' : 's' ?> · <?= e((string) ($market['basis'] ?? 'stored market data')) ?></small></div>
                  <div class="football-market-card__badges"><span class="badge <?= $providerOnly ? 'b-amber' : 'b-blue' ?>"><?= $providerOnly ? 'Provider price only' : 'WINDELS modelled' ?></span><span class="badge <?= $bandClass((string) ($pricing['state'] ?? 'DATA_UNAVAILABLE')) ?>"><?= e((string) ($pricing['state'] ?? 'UNPRICED')) ?></span><?php if (!empty($pricing['priceStale'])): ?><span class="badge b-red">STALE PRICE</span><?php endif; ?><span class="badge <?= $bandClass((string) ($market['riskLevel'] ?? '')) ?>"><?= e((string) ($market['riskLevel'] ?? 'UNKNOWN')) ?> risk</span></div>
                </header>
                <?php if ($outcomes === []): ?>
                  <div class="empty-state"><p><?= e((string) ($market['reason'] ?? 'DATA_UNAVAILABLE')) ?></p></div>
                <?php else: ?>
                  <div class="table-scroll">
                    <table class="tbl football-odds-table football-odds-table--complete">
                      <thead><tr><th>Selection</th><th>WINDELS estimate</th><th>Bookmaker quote &amp; information</th><th>Margin-free market</th><th>Value, Potential Edge &amp; Expected return</th></tr></thead>
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
                            <td class="football-odds-metric"><b class="mono"><?= $pct($outcome['probability'] ?? null) ?></b><small>WINDELS probability</small><span class="mono">WINDELS fair odds <?= $odds($outcome['windelsFairOdds'] ?? null) ?></span></td>
                            <td class="football-quote football-odds-metric">
                              <?php if ($hasQuote): ?><b class="mono"><?= $odds($outcome['odds']) ?></b><small>Market odds · Implied probability <?= $pct($outcome['impliedProbability'] ?? null) ?></small><span><?= e((string) ($outcome['oddsSource'] ?? 'Source unavailable')) ?></span><small><?= e($stamp($outcome['oddsObservedAt'] ?? null, 'Y-m-d H:i')) ?></small><small><?= max(1, (int) ($outcome['quoteCount'] ?? 1)) ?> quote<?= (int) ($outcome['quoteCount'] ?? 1) === 1 ? '' : 's' ?> · range <?= $odds($outcome['oddsLow'] ?? $outcome['odds']) ?>–<?= $odds($outcome['oddsHigh'] ?? $outcome['odds']) ?></small>
                              <?php else: ?><span class="badge b-gray">UNPRICED</span><small>No bookmaker quote stored</small><?php endif; ?>
                            </td>
                            <td class="football-odds-metric"><?php if (is_numeric($outcome['fairProbability'] ?? null)): ?><b class="mono"><?= $pct($outcome['fairProbability']) ?></b><small>probability after margin removal</small><span class="mono">market fair odds <?= $odds($outcome['fairOdds'] ?? null) ?></span><?php else: ?><b class="mono">—</b><small>Needs a complete price sheet</small><span>Break-even <?= $pct($outcome['breakEvenProbability'] ?? null) ?></span><?php endif; ?></td>
                            <td class="football-value-cell"><span class="badge <?= $valueTone ?>"><?= e((string) ($outcome['valueLabel'] ?? 'No price to compare')) ?></span><b class="mono <?= is_numeric($expected) && (float) $expected >= 0 ? 'up' : (is_numeric($expected) ? 'down' : 'dim') ?>">Expected return <?= $signedPct($expected) ?></b><small class="mono">Potential Edge vs quote <?= is_numeric($outcome['edgePoints'] ?? null) ? (((float) $outcome['edgePoints'] >= 0 ? '+' : '') . $number($outcome['edgePoints'], 2) . 'pp') : '—' ?></small><small class="mono">Edge after margin <?= is_numeric($outcome['edgeAgainstFairPoints'] ?? null) ? (((float) $outcome['edgeAgainstFairPoints'] >= 0 ? '+' : '') . $number($outcome['edgeAgainstFairPoints'], 2) . 'pp') : '—' ?></small><?php if (!empty($outcome['valueReason'])): ?><details class="football-value-explanation"><summary>Why this rating</summary><p><?= e((string) $outcome['valueReason']) ?></p></details><?php endif; ?></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
                <footer><span>Coverage <?= $pct($market['coverage'] ?? null) ?> · <?= (int) ($pricing['legsPriced'] ?? 0) ?>/<?= (int) ($pricing['legsExpected'] ?? 0) ?> expected legs priced</span><span><?php if (is_numeric($pricing['overround'] ?? null)): ?>Overround <?= $pct($pricing['overround']) ?> · market margin <?= $number($pricing['marginPoints'] ?? null, 2) ?>pp · <?= e((string) ($pricing['marginMethod'] ?? '')) ?><?php else: ?>Margin removal DATA_UNAVAILABLE<?php endif; ?><?php if (!empty($pricing['pricedAt'])): ?> · latest <?= e($stamp($pricing['pricedAt'], 'Y-m-d H:i')) ?><?php endif; ?></span></footer>
              </section>
            <?php endforeach; ?>
          <?php endif; ?>
          <p class="football-odds-sheet__note">WINDELS fair odds = 1 ÷ the model probability. Expected return and Potential Edge appear only when a valid provider price and a model probability can be compared. The provider’s margin is reported only for a complete market family.</p>
        </div>
      </section>

      <section class="panel" aria-labelledby="intelligence-heading">
        <h3 id="intelligence-heading">WINDELS Intelligence</h3>
        <div class="body">
          <div class="football-outcome-grid">
            <div class="football-outcome"><div>WINDELS Intelligence Score</div><?php if (is_numeric($scoreBlock['score'] ?? null)): ?><b class="mono"><span><?= (int) $scoreBlock['score'] ?>/100</span></b><?php else: ?><div><span class="dim">no score</span></div><?php endif; ?><small><?= e((string) ($scoreBlock['label'] ?? $scoreBlock['note'] ?? 'Insufficient evidence')) ?></small></div>
            <div class="football-outcome"><span>Selected market odds</span><b class="mono"><?= $odds($selectedMarket['odds'] ?? null) ?></b><small><?= is_numeric($selectedMarket['impliedProbability'] ?? null) ? 'implied ' . $pct($selectedMarket['impliedProbability']) : 'no provider quote' ?></small></div>
            <div class="football-outcome"><span>Potential Edge</span><b class="mono <?= is_numeric($valueBlock['expectedValue'] ?? null) && (float) $valueBlock['expectedValue'] >= 0 ? 'up' : 'down' ?>"><?= $signedPct($valueBlock['expectedValue'] ?? null) ?></b><small><?= e((string) ($valueBlock['valueLabel'] ?? 'UNPRICED')) ?> · Risk <?= e((string) ($intel['risk']['level'] ?? 'UNKNOWN')) ?></small></div>
          </div>
          <div class="football-section__divider"></div>
          <p class="football-help" style="margin-top:0"><b>Prediction ≠ Confidence ≠ Value.</b> A prediction is the selected outcome; confidence measures model certainty; value compares that model estimate with a provider price. These are separate readings.</p>
          <?php if (!empty($withheldBlock['withheld']) || !empty($withheldBlock['limitedEvidence'])): ?><div class="notice <?= !empty($withheldBlock['withheld']) ? 'warnbox' : 'info' ?>" style="margin-top:12px"><b><?= e((string) ($withheldBlock['headline'] ?? 'Prediction withheld — insufficient verified data')) ?></b><?= e((string) ($withheldBlock['reason'] ?? '')) ?></div><?php endif; ?>
          <?php if (($stabilityBlock['state'] ?? '') === \AIWorkforce\Football\StabilityMonitor::UNSTABLE): ?><div class="notice err" style="margin-top:12px"><b>Prediction unstable — significant model movement.</b><?= e((string) ($stabilityBlock['reason'] ?? '')) ?></div><?php endif; ?>
          <?php $drivers = is_array($driverBlock['drivers'] ?? null) ? $driverBlock['drivers'] : []; ?>
          <?php if ($drivers !== []): ?>
            <div class="table-scroll" style="margin-top:12px"><table class="tbl"><thead><tr><th>Why WINDELS selected it</th><th>What the data says</th><th>Verdict</th></tr></thead><tbody><?php foreach ($drivers as $driver): ?><tr><td class="dim"><?= e((string) ($driver['label'] ?? '')) ?></td><td><?= e((string) ($driver['detail'] ?? '')) ?><?php if (!empty($driver['source'])): ?><div class="football-cell-note dim mono"><?= e((string) $driver['source']) ?></div><?php endif; ?></td><td><span class="badge <?= ($driver['verdict'] ?? '') === 'STRONG' ? 'b-green' : 'b-gray' ?>"><?= e((string) ($driver['verdict'] ?? '—')) ?></span></td></tr><?php endforeach; ?></tbody></table></div>
          <?php endif; ?>
          <?php $clocks = is_array($freshBlock['clocks'] ?? null) ? $freshBlock['clocks'] : []; ?>
          <?php if ($clocks !== []): ?><div class="table-scroll" style="margin-top:12px"><table class="tbl"><thead><tr><th>Last updated</th><th>State</th><th>Age</th></tr></thead><tbody><?php foreach ($clocks as $clock): ?><tr><td><?= e((string) ($clock['label'] ?? '')) ?></td><td><span class="badge <?= $bandClass((string) ($clock['state'] ?? '')) ?>"><?= e((string) ($clock['state'] ?? '—')) ?></span></td><td class="mono"><?= e((string) ($clock['ageLabel'] ?? '—')) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
        </div>
      </section>

      <section class="panel">
        <h3>Teams — form and goal profile</h3>
        <div class="body">
          <?php $home = is_array($teams['HOME'] ?? null) ? $teams['HOME'] : []; $away = is_array($teams['AWAY'] ?? null) ? $teams['AWAY'] : []; ?>
          <div class="table-scroll"><table class="tbl"><thead><tr><th>Input</th><th><?= e((string) ($fixture['homeTeam'] ?? 'Home')) ?></th><th><?= e((string) ($fixture['awayTeam'] ?? 'Away')) ?></th></tr></thead><tbody>
            <?php
            $teamRows = [
              'Recent form (last 5)' => static fn(array $team): string => isset($team['form']['last5']['string']) ? (string) $team['form']['last5']['string'] . ' (' . (int) ($team['form']['last5']['played'] ?? 0) . ' played)' : 'DATA_UNAVAILABLE',
              'Recent form (last 10)' => static fn(array $team): string => isset($team['form']['last10']['string']) ? (string) $team['form']['last10']['string'] . ' (' . (int) ($team['form']['last10']['played'] ?? 0) . ' played)' : 'DATA_UNAVAILABLE',
              'Wins-draws-losses' => static fn(array $team): string => isset($team['form']['last10']) ? (int) ($team['form']['last10']['wins'] ?? 0) . '-' . (int) ($team['form']['last10']['draws'] ?? 0) . '-' . (int) ($team['form']['last10']['losses'] ?? 0) : 'DATA_UNAVAILABLE',
              'Average goals scored' => static fn(array $team): string => is_numeric($team['avgGoalsScored'] ?? null) ? number_format((float) $team['avgGoalsScored'], 2) : 'DATA_UNAVAILABLE',
              'Average goals conceded' => static fn(array $team): string => is_numeric($team['avgGoalsConceded'] ?? null) ? number_format((float) $team['avgGoalsConceded'], 2) : 'DATA_UNAVAILABLE',
              'Attack strength (league average = 1.00)' => static fn(array $team): string => is_numeric($team['attackStrength'] ?? null) ? number_format((float) $team['attackStrength'], 2) : 'DATA_UNAVAILABLE',
              'Defensive weakness (league average = 1.00)' => static fn(array $team): string => is_numeric($team['defenseWeakness'] ?? null) ? number_format((float) $team['defenseWeakness'], 2) : 'DATA_UNAVAILABLE',
              'Clean-sheet frequency' => static fn(array $team): string => is_numeric($team['cleanSheetRate'] ?? null) ? number_format((float) $team['cleanSheetRate'] * 100, 0) . '%' : 'DATA_UNAVAILABLE',
              'Failed-to-score frequency' => static fn(array $team): string => is_numeric($team['failedToScoreRate'] ?? null) ? number_format((float) $team['failedToScoreRate'] * 100, 0) . '%' : 'DATA_UNAVAILABLE',
            ];
            foreach ($teamRows as $label => $format): ?>
              <tr><td class="dim"><?= e($label) ?></td><td class="mono"><?= e($format($home)) ?></td><td class="mono"><?= e($format($away)) ?></td></tr>
            <?php endforeach; ?>
          </tbody></table></div>
        </div>
      </section>
    </div>

    <aside class="football-match-side stack" aria-label="Match evidence and state">
      <section class="panel">
        <h3>Data quality — <?= (int) ($quality['score'] ?? 0) ?>/100</h3>
        <div class="body">
          <p><span class="badge <?= $bandClass((string) ($quality['band'] ?? 'REJECTED')) ?>"><?= e((string) ($quality['band'] ?? 'REJECTED')) ?></span></p>
          <div class="table-scroll"><table class="tbl"><thead><tr><th>Component</th><th class="num">Value</th><th class="num">Weight</th><th class="num">Contribution</th></tr></thead><tbody><?php foreach ((array) ($quality['components'] ?? []) as $key => $component): ?><tr><td><?= e($pretty((string) $key)) ?></td><td class="num mono"><?= $number($component['value'] ?? null, 1) ?></td><td class="num mono dim"><?= isset($component['weight']) ? $number((float) $component['weight'] * 100, 0) . '%' : '—' ?></td><td class="num mono"><?= $number($component['contribution'] ?? null, 1) ?></td></tr><?php endforeach; ?></tbody></table></div>
          <?php if (!empty($quality['reasons'])): ?><p class="football-help"><b>Available:</b> <?= e(implode(' · ', array_slice((array) $quality['reasons'], 0, 6))) ?></p><?php endif; ?>
          <?php if (!empty($quality['reasonsAbsent'])): ?><p class="football-help"><b>Missing:</b> <?= e(implode(' · ', array_slice((array) $quality['reasonsAbsent'], 0, 6))) ?></p><?php endif; ?>
        </div>
      </section>

      <section class="panel">
        <h3>Head to head &amp; competition</h3>
        <div class="body"><dl class="football-key-values"><div><dt>Stored meetings</dt><dd><?= (int) ($h2h['meetings'] ?? 0) ?></dd></div><div><dt>Summary</dt><dd><?= e($state($h2h['summary'] ?? null)) ?></dd></div><div><dt>Weight applied</dt><dd class="mono"><?= $number($h2h['weight'] ?? null) ?></dd></div><div><dt>Competition strength</dt><dd class="mono"><?= $number($analysis['competition']['strength'] ?? null, 3) ?></dd></div></dl></div>
      </section>

      <?php if ($settlement !== null): ?>
        <section class="panel"><h3>Settlement (stored, immutable)</h3><div class="body"><dl class="football-key-values"><div><dt>State</dt><dd><?= e((string) ($settlement['status'] ?? $settlement['state'] ?? '—')) ?></dd></div><div><dt>Actual score</dt><dd class="mono"><?= $score($settlement['actualScore'] ?? null, 'home') ?>–<?= $score($settlement['actualScore'] ?? null, 'away') ?></dd></div><div><dt>Settled at</dt><dd class="mono"><?= e($stamp($settlement['settledAt'] ?? null)) ?></dd></div></dl></div></section>
      <?php endif; ?>

      <?php if ($liveEstimates !== []): ?>
        <section class="panel"><h3>Live model estimates</h3><div class="body"><div class="table-scroll"><table class="tbl"><thead><tr><th>Generated</th><th>Result</th><th class="num">Confidence</th></tr></thead><tbody><?php foreach ($liveEstimates as $estimate): ?><tr><td class="mono dim"><?= e($stamp($estimate['generatedAt'] ?? null, 'M j H:i')) ?></td><td><?= e((string) ($estimate['prediction']['result'] ?? '—')) ?> <?= $score($estimate['prediction']['predictedScore'] ?? null, 'home') ?>–<?= $score($estimate['prediction']['predictedScore'] ?? null, 'away') ?></td><td class="num mono"><?= $number($estimate['prediction']['confidence'] ?? null, 1) ?>%</td></tr><?php endforeach; ?></tbody></table></div><p class="football-help">Stored as separate LIVE rows. Live estimates never rewrite the pre-match prediction; the frozen prediction is never rewritten.</p></div></section>
      <?php endif; ?>

      <?php if ($inMatch !== null && $inMatch !== []): ?>
        <section class="panel"><h3>Match state as stored</h3><div class="body"><div class="table-scroll"><table class="tbl"><tbody><?php foreach ($inMatch as $key => $value): ?><tr><td class="dim"><?= e($pretty((string) $key)) ?></td><td class="mono"><?= is_array($value) ? e((string) json_encode($value)) : e((string) $value) ?></td></tr><?php endforeach; ?></tbody></table></div></div></section>
      <?php endif; ?>

      <section class="panel"><h3>Provenance</h3><div class="body"><p class="football-help" style="margin-top:0">The coverage records below identify whether a stored input existed. They do not fabricate missing data.</p><div class="table-scroll"><table class="tbl"><thead><tr><th>Input</th><th>Coverage</th></tr></thead><tbody><?php foreach ((array) $coverage as $key => $value): ?><tr><td><?= e($pretty((string) $key)) ?></td><td class="mono"><?= is_bool($value) ? ($value ? 'yes' : 'no') : (is_numeric($value) ? (string) $value : e($state($value))) ?></td></tr><?php endforeach; ?><?php if ($coverage === []): ?><tr><td colspan="2" class="dim">No coverage record stored</td></tr><?php endif; ?></tbody></table></div></div></section>
    </aside>
  </div>
</div>
