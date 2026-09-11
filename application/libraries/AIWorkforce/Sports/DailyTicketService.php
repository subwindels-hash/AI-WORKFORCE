<?php
namespace AIWorkforce\Sports;

use AIWorkforce\Backtest\Backtester;
use AIWorkforce\Football\CanonicalMatch;
use AIWorkforce\Persistence\AuditRepository;
use AIWorkforce\Persistence\SportsRepository;
use AIWorkforce\Sports\Providers\SportsDataProvider;
use AIWorkforce\Sports\Providers\SportsProviderManager;

/**
 * AI Ticket Engine (spec §16/§17/§19) — the daily end-to-end pipeline:
 *
 *   fixtures sync → odds sync (freshness TTL + provider fallback) →
 *   fixture eligibility → data normalization → odds availability/freshness →
 *   prediction (mandatory market data + calibration) → probability →
 *   confidence → data quality → value/edge → risk → correlation →
 *   ticket optimization → governance (user approval by default)
 *
 * Fixtures that fail a SHARED upstream stage (eligibility, odds, mandatory
 * data, calibration, quality floor) are rejected ONCE at the fixture level
 * with a single primary reason — the engine never generates hundreds of
 * per-market predictions just to reject them all for the same upstream
 * problem. Per-market predictions are only produced when the model can
 * actually compute them, and each rejected candidate counts exactly one
 * primary blocking reason (all reasons stay on the decision record).
 *
 * The run result always carries a `diagnostics` funnel (fixtures with fresh
 * odds, sufficient-data candidates, confidence ≥ floor, positive value,
 * risk-qualified, correlation-qualified, final) plus the top rejection
 * reasons with the provider that caused each failure.
 *
 * Idempotent per (date, configuration version). When nothing qualifies the
 * engine stores NO_QUALIFIED_TICKET with the exact rejection summary — an
 * expected, first-class outcome (spec §3).
 *
 * A provider outage is NOT that outcome. When every configured data provider
 * fails (quota exhausted, 400/404 misconfiguration, offline) the run stores
 * DATA_UNAVAILABLE with the per-provider status codes, and the run's execution
 * key is released so the next sweep can retry once a provider recovers — a
 * blocked engine must never masquerade as "no qualified games today".
 */
class DailyTicketService
{
    /** Never generate more than this many fixtures' predictions in one run (design §9: MAXIMUM GENERATION = 50). */
    public const DEFAULT_MAX_GENERATION = 50;

    /** Hard ceiling for the operator-tunable generation cap — still bounded, never 500/1000/5000. */
    public const MAX_GENERATION_CEILING = 500;

    public const ENV_MAX_GENERATION = 'WINDELS_SPORTS_MAX_GENERATION';

    /** Verified recentForm stays usable for this long before it must be re-read. */
    public const DEFAULT_FORM_MAX_AGE_SECONDS = 7 * 86400;

    public const ENV_FORM_MAX_AGE = 'WINDELS_SPORTS_FORM_MAX_AGE';

    /** Travels with every Top WINDELS Picks list. */
    public const TOP_PICKS_DISCLAIMER = 'Top WINDELS Picks are model-based selections ranked by evidence — not guarantees. '
        . 'A value class is not a probability of winning, and no pick is certain.';

    private FormResolver $formResolver;
    private OddsFreshnessEngine $oddsFreshness;
    private ?\AIWorkforce\Football\FootballConfiguration $fairConfig = null;

    /** @var array<string,array> provider id → health, cached per run (one health probe per provider, not per fixture) */
    private array $healthCache = [];
    /** @var array<int,string> provider row id → provider code, cached per run */
    private array $providerCodes = [];

    public function __construct(
        private SportsRepository $repo,
        private AuditRepository $audit,
        private SportsProviderManager $providers,
        private ConfigurationService $config,
        private DataQualityEngine $quality,
        private PredictionPipeline $pipeline,
        private TicketOptimizer $optimizer,
        private TicketGovernance $governance,
        private DecisionRecorder $decisions,
        ?FormResolver $formResolver = null,
        ?OddsFreshnessEngine $oddsFreshness = null,
    ) {
        $this->formResolver = $formResolver ?? new FormResolver();
        $this->oddsFreshness = $oddsFreshness ?? new OddsFreshnessEngine();
    }

    public function runDaily(?string $date = null, ?string $executionKey = null, array $options = []): array
    {
        $date = $date ?? gmdate('Y-m-d');
        $config = $this->config->active();
        // Resolved ONCE with the same default the per-fixture gate applies
        // (see the MODEL_NOT_CALIBRATED check in the screening loop): an
        // older stored configuration row that predates the require_calibration
        // column must not mean "enforce calibration but never run the
        // cold-start bootstrap" — that combination is a hard lock-out.
        $requireCalibration = (int) (bool) ($config['require_calibration'] ?? 1);
        $config['require_calibration'] = $requireCalibration; // pipeline sees the same resolved truth

        // Force / reset: invalidate the ACTIVE candidate state for the day
        // BEFORE any fixture is read, so a previous pass (predictions, the
        // pending ticket, the daily slot, unquotable odds) can never be
        // carried into the new run. Historical/settled records and verified
        // results are preserved by the repository (audit trail).
        $invalidated = null;
        // Same horizon the sweep syncs and the engine evaluates: the run
        // date and the following UTC day (early kickoffs), so a candidate
        // prepared for any fixture the run is about to score is in scope.
        $windowTo = gmdate('Y-m-d', strtotime($date . ' +1 day'));
        if (!empty($options['force'])) {
            try {
                $invalidated = $this->repo->invalidateActiveCandidates($date, $windowTo, true);
                $this->audit->emit('SPORTS_CANDIDATES_INVALIDATED',
                    'Active odds-prediction candidates for ' . $date . '..' . $windowTo . ' invalidated before a forced fresh generation (settled/historical records preserved)',
                    ['from' => $date, 'to' => $windowTo, 'invalidated' => $invalidated]);
            } catch (\Throwable $e) {
                // Never silently half-reset: surface the failure and stop.
                return ['status' => 'RESET_FAILED', 'date' => $date, 'message' => 'candidate reset failed: ' . mb_substr($e->getMessage(), 0, 300), 'invalidated' => null];
            }
        }

        $key = $executionKey ?? ('daily-ticket:' . $date . ':v' . $config['version'] . (!empty($options['force']) ? ':force:' . gmdate('YmdHis') . ':' . substr(uniqid(), -4) : ''));
        $run = $this->repo->startJobRun(['id' => Backtester::uuid(), 'jobType' => 'DAILY_TICKET', 'executionKey' => $key]);
        if ($run === null) return ['status' => 'DUPLICATE_SKIPPED', 'executionKey' => $key];
        // Prediction ids written by THIS run — the only rows the intelligent
        // refresh may never reuse as "previous" readings.
        $recordedThisRun = [];

        $errors = [];
        $status = 'NO_QUALIFIED_TICKET';
        $message = '';
        $ticketId = null;
        $evaluated = 0;
        $recorded = 0;
        $rejections = 0;
        $rejectionSummary = [];
        $provider = null;
        $modelVersionId = null;
        $providerFailures = [];   // providerId → "STATUS: detail" (redacted)
        $providerStatuses = [];   // providerId → STATUS
        $dataState = 'OK';        // OK | DATA_UNAVAILABLE | NO_PROVIDER | DISABLED
        $funnel = $this->emptyFunnel();

        try {
            if (!(bool) $config['module_enabled']) {
                $message = 'Sports Intelligence module is disabled';
                $dataState = 'DISABLED';
            } elseif (!(bool) $config['ticket_engine_enabled']) {
                $message = 'AI Ticket Engine is disabled';
                $dataState = 'DISABLED';
            } elseif (!in_array($config['engine_mode'], ['AI_TICKET_GENERATION', 'USER_APPROVAL_REQUIRED', 'AUTOMATED_EXECUTION'], true)) {
                $message = 'engine mode ' . $config['engine_mode'] . ' does not generate tickets';
                $dataState = 'DISABLED';
            } elseif (!$this->providers->configured()) {
                $message = 'NO VALUE TICKET TODAY — no sports provider configured (DISABLED_NO_PROVIDER); nothing is fabricated';
                $dataState = 'NO_PROVIDER';
            } else {
                // All-provider intake: every registered feed is asked once (one
                // health probe per provider per run, the circuit breaker
                // honoured for each), and every answer is kept. A single
                // provider behaves exactly as before; with several, the same
                // real match arriving under several ids is merged below and
                // evaluated once through its most complete row.
                $sources = $this->fetchFixtureSources($date, $errors);
                if (!$sources['ok']) {
                    // Every provider failed. This is a DATA outage, not a
                    // prediction outcome: report it as such, keep the
                    // per-provider status codes, and do not claim "no
                    // qualified games" for a day nobody could look at.
                    $status = 'DATA_UNAVAILABLE';
                    $dataState = 'DATA_UNAVAILABLE';
                    $providerFailures = $sources['failures'];
                    $providerStatuses = $sources['failureStatuses'] ?? [];
                    $message = 'NO VALUE TICKET TODAY — all configured sports-data providers failed; no data was fabricated — ' . ($sources['summary'] ?: SportsProviderManager::summarize('fixtures', $providerStatuses));
                    $errors[] = 'provider failure: ' . json_encode($sources['failures']);
                    $funnel['providersConfigured'] = count($this->providers->all());
                } else {
                    $sourceCodes = array_map(fn(array $s): string => $s['provider'], $sources['sources']);
                    $provider = count($sourceCodes) === 1 ? $sourceCodes[0] : implode(',', $sourceCodes);
                    $funnel['providersConfigured'] = count($this->providers->all());
                    $funnel['fixtureProviders'] = $sourceCodes;
                    $runtimeNow = time();

                    // ── Calibration cold start ────────────────────────────
                    // The engine predicts nothing without an APPROVED
                    // calibration, and a fitted Platt calibration can only be
                    // fitted from 20+ SETTLED predictions — which only THIS
                    // engine writes. Break the deadlock with the documented
                    // identity calibration (intercept 0 / slope 1: the raw
                    // model probability, the same mapping the backtester
                    // uses). Tickets remain gated by confidence / quality /
                    // value / risk and — in the default engine mode — by
                    // user approval before anything happens.
                    if ($requireCalibration) $this->ensureIdentityCalibration($funnel);

                    // ── Form enrichment, spent where it can still win a ticket.
                    //
                    // The lookup budget (WINDELS_SPORTS_FORM_LOOKUPS, 30 by
                    // default) used to be spent walking the WHOLE provider
                    // response in arrival order — a worldwide pull is mostly
                    // matches that already kicked off or start within two
                    // hours, so the quota was exhausted on fixtures the very
                    // next gate throws away and every ticket-eligible fixture
                    // was left without recentForm → INSUFFICIENT_DATA.
                    //
                    // Enrichment now runs ONLY on fixtures that already passed
                    // the fixture-eligibility gate, and form verified by an
                    // earlier run is carried forward instead of re-fetched.
                    // ── Canonical merge across providers ────────────────────────
                    // Every source's rows are normalized under their OWN
                    // provider id, then grouped by canonical identity
                    // (normalized teams + kickoff date + competition). One real
                    // match arriving from three feeds is saved three times
                    // (provider ids stay separate) but evaluated ONCE, through
                    // the most complete row: eligible beats ineligible (a status
                    // disagreement must never lose the match), id-carrying rows
                    // beat bare ones, and registration order breaks ties.
                    $primaries = [];
                    $duplicates = [];
                    $screenedInvalid = [];
                    $arrival = 0;
                    $groups = [];
                    foreach ($sources['sources'] as $source) {
                        $sourceCode = $source['provider'];
                        $sourceProviderId = (int) $source['providerId'];
                        foreach ($source['fixtures'] as $rawFixture) {
                            $order = $arrival++;
                            if (!is_array($rawFixture)) {
                                $screenedInvalid[] = ['provider' => $sourceCode, 'providerId' => $sourceProviderId, 'raw' => [], 'duplicateOf' => null, 'order' => $order];
                                continue;
                            }
                            $rawFixture = $this->carryForwardStoredForm($sourceProviderId, $rawFixture, $runtimeNow, $funnel);
                            try { $probe = SportsDataNormalizer::fixture($rawFixture, $sourceCode); }
                            catch (\Throwable $e) { $screenedInvalid[] = ['provider' => $sourceCode, 'providerId' => $sourceProviderId, 'raw' => $rawFixture, 'duplicateOf' => null, 'order' => $order]; continue; }
                            $groups[$this->canonicalGroupKey($probe)][] = [
                                'provider' => $sourceCode, 'providerId' => $sourceProviderId, 'raw' => $rawFixture,
                                'probe' => $probe, 'eligible' => $this->fixtureEligibleForDailyTicket($probe, $runtimeNow),
                                'duplicateOf' => null, 'order' => $order,
                            ];
                        }
                    }
                    foreach ($groups as $groupKey => $entries) {
                        $best = null;
                        $bestScore = null;
                        foreach ($entries as $entry) {
                            $score = $this->canonicalEntryScore($entry);
                            if ($best === null || $score > $bestScore) { $best = $entry; $bestScore = $score; }
                        }
                        $primaries[] = $best;
                        if (count($entries) === 1) continue;
                        $primaryRef = $best['provider'] . ':' . $best['probe']['externalId'];
                        $dropped = [];
                        foreach ($entries as $entry) {
                            if ($entry['order'] === $best['order']) continue;
                            $entry['duplicateOf'] = $primaryRef;
                            $duplicates[] = $entry;
                            $dropped[] = $entry['provider'] . ':' . $entry['probe']['externalId'];
                        }
                        $dupRows = &$funnel['duplicateFixtures']['rows'];
                        if (count($dupRows) < 50) $dupRows[] = ['canonical' => $groupKey, 'kept' => $primaryRef, 'dropped' => $dropped];
                        else $funnel['duplicateFixtures']['truncated'] = true;
                        unset($dupRows);
                    }
                    usort($primaries, fn(array $a, array $b) => $a['order'] <=> $b['order']);

                    // ── Form enrichment, spent where it can still win a ticket,
                    // per fixture provider (team ids are provider-specific). Only
                    // eligible PRIMARIES are enriched — duplicates never reach a
                    // gate, so spending lookups on them would burn quota for
                    // rows the engine throws away.
                    $eligiblePrimaryIndexes = [];
                    foreach ($primaries as $i => $entry) {
                        if ($entry['eligible']) $eligiblePrimaryIndexes[$entry['provider']][] = $i;
                    }
                    $funnel['formEnrichmentCandidates'] = array_sum(array_map('count', $eligiblePrimaryIndexes));
                    $instanceByCode = [];
                    foreach ($sources['sources'] as $source) $instanceByCode[$source['provider']] = $source['instance'];
                    $formStats = ['lookupsUsed' => 0, 'lookupFailures' => 0, 'budgetSkips' => 0, 'budget' => 0, 'providerCapable' => true];
                    foreach ($eligiblePrimaryIndexes as $code => $indexes) {
                        $raws = [];
                        foreach ($indexes as $i) $raws[] = $primaries[$i]['raw'];
                        $enriched = $this->formResolver->enrich($instanceByCode[$code], $raws);
                        foreach ($indexes as $k => $i) $primaries[$i]['raw'] = $enriched[$k] ?? $primaries[$i]['raw'];
                        $stats = $this->formResolver->stats();
                        $formStats['lookupsUsed'] += (int) ($stats['lookupsUsed'] ?? 0);
                        $formStats['lookupFailures'] += (int) ($stats['lookupFailures'] ?? 0);
                        $formStats['budgetSkips'] += (int) ($stats['budgetSkips'] ?? 0);
                        $formStats['budget'] = (int) ($stats['budget'] ?? 0);
                        $formStats['providerCapable'] = $formStats['providerCapable'] && !empty($stats['providerCapable']);
                    }
                    // The screening queue keeps the established shape — enriched
                    // eligible rows first, everything else in arrival order — so
                    // evaluation counts and rejection reasons stay exactly as
                    // honest as before.
                    $screeningQueue = [];
                    foreach ($primaries as $entry) {
                        if ($entry['eligible']) $screeningQueue[] = $entry;
                    }
                    $tail = [];
                    foreach ($primaries as $entry) {
                        if (!$entry['eligible']) $tail[] = $entry;
                    }
                    foreach ($duplicates as $entry) $tail[] = $entry;
                    foreach ($screenedInvalid as $entry) $tail[] = $entry;
                    usort($tail, fn(array $a, array $b) => $a['order'] <=> $b['order']);
                    foreach ($tail as $entry) $screeningQueue[] = $entry;
                    // Form enrichment is the single most common INSUFFICIENT_DATA
                    // cause, and it used to be invisible. Count what enrichment
                    // actually delivered and why, and carry both on the funnel.
                    $formEnriched = 0;
                    foreach ($screeningQueue as $queued) {
                        if (!empty($queued['raw']['context']['recentForm'])) $formEnriched++;
                    }
                    $funnel['fixturesWithRecentForm'] = $formEnriched;
                    $funnel['formResolver'] = $formStats;
                    // Bulk-fetch the day's odds in one round() call per
                    // matchday when a fixture provider exposes the round
                    // endpoint (round ids are provider-specific — each provider
                    // is asked only for its own ELIGIBLE primaries' rounds, so
                    // no request is spent on fixtures the gates throw away).
                    $roundOdds = [];
                    foreach ($sources['sources'] as $source) {
                        $code = $source['provider'];
                        $eligibleRaws = [];
                        foreach ($primaries as $entry) {
                            if ($entry['eligible'] && $entry['provider'] === $code) $eligibleRaws[] = $entry['raw'];
                        }
                        if ($eligibleRaws === []) continue;
                        $roundOdds[$code] = $this->fetchRoundOdds($source['instance'], $eligibleRaws, $errors);
                    }
                    $candidates = [];
                    $allCandidates = [];        // every generated candidate, qualified or not (Top Picks pool)
                    $minConfidence = (float) $config['min_confidence'];
                    $minQuality = (int) $config['min_data_quality'];
                    $minEv = (float) ($config['min_expected_value'] ?? 0.02);
                    $reasonProviders = [];  // primary reason → provider → count
                    $predictable = [];      // fixtures that passed every shared upstream gate

                    // ── Screening: every shared upstream gate, cheap, no model.
                    // A fixture that fails here is rejected ONCE with one
                    // primary reason — never per market:selection.
                    foreach ($screeningQueue as $queued) {
                        $itemProvider = (string) $queued['provider'];
                        $itemProviderId = (int) $queued['providerId'];
                        try {
                            $match = SportsDataNormalizer::fixture($queued['raw'], $itemProvider);
                            $saved = $this->repo->saveMatch($itemProviderId, $match);
                        } catch (\Throwable $e) {
                            $errors[] = 'fixture rejected: ' . mb_substr($e->getMessage(), 0, 200);
                            continue;
                        }
                        $evaluated++;

                        // Merged duplicate: saved under its own provider (ids
                        // stay separate) but never evaluated — its canonical
                        // primary carries the match through the gates exactly
                        // once. A duplicate is neither a rejection nor a
                        // deferral; it is counted, never hidden.
                        if (!empty($queued['duplicateOf'])) {
                            $funnel['fixturesDeduped']++;
                            continue;
                        }

                        // ── Stage 1: fixture eligibility ─────────────────────
                        if (!$this->fixtureEligibleForDailyTicket($match, $runtimeNow)) {
                            $rejections++;
                            $this->countRejection($rejectionSummary, 'FIXTURE_NOT_NS_OR_TOO_SOON', $reasonProviders, $itemProvider);
                            continue;
                        }
                        $funnel['eligibleFixtures']++;
                        $matchRow = $this->repo->findMatchById((int) $saved['id']);
                        if ($matchRow === null) continue;

                        // ── Stage 2: data normalization (payload context) ───
                        $contextFields = $this->contextFields($matchRow);

                        // ── Stage 3: odds availability / freshness ──────────
                        $oddsStage = $this->resolveUsableOdds($matchRow, $itemProvider, $roundOdds[$itemProvider] ?? [], $errors, $runtimeNow, $funnel);
                        if (!$oddsStage['ok']) {
                            $rejections++;
                            $this->countRejection($rejectionSummary, $oddsStage['reason'], $reasonProviders, $oddsStage['provider'] ?? $itemProvider);
                            continue;
                        }
                        $usableOdds = $oddsStage['rows'];
                        $funnel['fixturesWithFreshOdds']++;

                        // ── Stage 4: data quality (market-aware, transparent) ─
                        $markets = array_values(array_unique(array_map(fn($r) => $r['market'], $usableOdds)));
                        $reliability = (float) ($this->providerHealth($itemProvider)['reliability'] ?? 0);
                        $qualityAssessment = $this->quality->assess($match, $this->qualityContext($contextFields, $oddsStage, $reliability, $markets, $minQuality));
                        $this->repo->saveQuality((int) $saved['id'], $qualityAssessment);

                        // ── Stage 5: prediction feasibility (shared upstream) ─
                        // Mandatory market data missing, or no approved
                        // calibration, or below the quality floor → the model
                        // cannot honestly compute ANY market for this fixture:
                        // one rejection, not one per market. Every requirement
                        // is evaluated (not just the first failure) and recorded
                        // per fixture so "0 sufficient-data fixtures" is never a
                        // black box — the gate diagnostic names the blocker.
                        $calibration = $this->calibrationFor($matchRow);
                        $missingMandatory = array_values((array) ($qualityAssessment['missingMandatory'] ?? []));
                        $mandatoryOk = $missingMandatory === [];
                        $calibrationOk = !$requireCalibration || $calibration !== null;
                        $qualityOk = ((int) ($qualityAssessment['score'] ?? 0) >= $minQuality && !empty($qualityAssessment['eligibleForTicket']));
                        if ($mandatoryOk && $calibrationOk && $qualityOk) {
                            $failedRequirement = null;
                            $primaryReason = null;
                        } elseif (!$mandatoryOk) {
                            $failedRequirement = 'MANDATORY_MODEL_DATA';
                            $primaryReason = 'INSUFFICIENT_DATA';
                        } elseif (!$calibrationOk) {
                            $failedRequirement = 'APPROVED_CALIBRATION';
                            $primaryReason = 'MODEL_NOT_CALIBRATED';
                        } else {
                            $failedRequirement = 'DATA_QUALITY_FLOOR';
                            $primaryReason = 'LOW_DATA_QUALITY';
                        }
                        $this->recordSufficientDataGate($funnel, [
                            'matchId' => (int) $saved['id'],
                            'externalId' => (string) ($match['externalId'] ?? ''),
                            'homeTeam' => (string) ($match['homeTeam'] ?? ''),
                            'awayTeam' => (string) ($match['awayTeam'] ?? ''),
                            'competition' => (string) ($match['competition'] ?? ''),
                            'kickoff' => (string) ($match['kickoff'] ?? ''),
                            'provider' => $itemProvider,
                            'oddsMarkets' => $markets,
                            'passed' => $failedRequirement === null,
                            'failedRequirement' => $failedRequirement,
                            'primaryReason' => $primaryReason,
                            'requirements' => [
                                'MANDATORY_MODEL_DATA' => ['ok' => $mandatoryOk, 'missingMandatory' => $missingMandatory, 'mandatoryFields' => array_values((array) ($qualityAssessment['mandatoryFields'] ?? ['recentForm']))],
                                'APPROVED_CALIBRATION' => ['ok' => $calibrationOk, 'required' => (bool) $requireCalibration, 'calibrationId' => $calibration['id'] ?? null, 'method' => $calibration['method'] ?? null, 'bootstrapState' => $funnel['calibrationBootstrap'] ?? null],
                                'DATA_QUALITY_FLOOR' => ['ok' => $qualityOk, 'score' => (int) ($qualityAssessment['score'] ?? 0), 'minScore' => (int) $minQuality, 'band' => (string) ($qualityAssessment['band'] ?? 'UNKNOWN'), 'eligibleForTicket' => (bool) ($qualityAssessment['eligibleForTicket'] ?? false)],
                            ],
                        ]);
                        if ($failedRequirement !== null) {
                            $rejections++;
                            $this->countRejection($rejectionSummary, $primaryReason, $reasonProviders, $itemProvider);
                            if ($failedRequirement === 'MANDATORY_MODEL_DATA') $funnel['fixturesMissingMandatoryData']++;
                            elseif ($failedRequirement === 'APPROVED_CALIBRATION') $funnel['fixturesWithoutCalibration']++;
                            else $funnel['fixturesBelowQualityFloor']++;
                            continue;
                        }
                        $funnel['sufficientDataFixtures']++;

                        $predictable[] = [
                            'match' => $match,
                            'matchRow' => $matchRow,
                            'provider' => $itemProvider,
                            'quality' => $qualityAssessment,
                            'calibration' => $calibration,
                            'odds' => $usableOdds,
                            'marketPrices' => $oddsStage['marketPrices'],
                            'kickoff' => (string) $match['kickoff'],
                            'externalId' => (string) $match['externalId'],
                        ];
                    }

                    // ── Generation: bounded, deterministic, never regenerating
                    // a stored prediction. MAXIMUM GENERATION is capped (50 by
                    // default); the fixtures most in need of fresh predictions
                    // are generated first, and fixtures past the cap whose
                    // markets already have reusable stored predictions are
                    // still EVALUATED (linked, never re-recorded) so the
                    // ticket pool covers the full stored pool. Fixtures past
                    // the cap with nothing reusable are DEFERRED — honestly
                    // reported and named, never silently dropped — and the
                    // deterministic order keeps pages stable.
                    $funnel['generationCap'] = $this->generationCap();
                    if ($predictable !== []) {
                        // The model version is constant for the whole run (the
                        // lookup ignores the calibration label), so resolve it
                        // once instead of per candidate — and read every
                        // fixture's reusable stored predictions BEFORE slicing,
                        // so the capped slice covers the fixtures that need
                        // generation most. On a fresh run every fixture ties
                        // and the order is exactly (kickoff, externalId).
                        $modelVersionId = $this->modelVersionIdFor([
                            'modelName' => PredictionEngine::MODEL_NAME,
                            'modelVersion' => PredictionEngine::MODEL_VERSION,
                            'featureVersion' => FeatureEngineeringEngine::VERSION,
                        ]);
                        foreach ($predictable as &$predictableItem) {
                            $predictableItem['previousByKey'] = $this->previousPredictionsFor((int) $predictableItem['matchRow']['id'], $recordedThisRun);
                            $missing = 0;
                            foreach ($predictableItem['odds'] as $oddsRow) {
                                $prev = $predictableItem['previousByKey'][$oddsRow['market'] . ':' . $oddsRow['selection']] ?? null;
                                if (!$this->reusablePrevious($prev, $modelVersionId, $oddsRow['decimalOdds'] ?? null, $oddsRow['observedAt'] ?? null)) $missing++;
                            }
                            $predictableItem['missingCoverage'] = $missing;
                        }
                        unset($predictableItem);
                    }
                    usort($predictable, fn(array $a, array $b) => [$b['missingCoverage'] ?? 0, $a['kickoff'], $a['externalId']] <=> [$a['missingCoverage'] ?? 0, $b['kickoff'], $b['externalId']]);
                    $generate = array_slice($predictable, 0, $funnel['generationCap']);
                    $beyondCap = array_slice($predictable, $funnel['generationCap']);
                    $fixturesDeferred = 0;

                    foreach ($generate as $item) {
                        $matchRow = $item['matchRow'];
                        $previousByKey = $item['previousByKey'];

                        // ── Stages 6–10: per-market prediction → probability →
                        // confidence → data quality → value/edge → risk ──────
                        foreach ($item['odds'] as $odds) {
                            $candidate = $this->pipeline->evaluate($matchRow, $odds, $item['quality'], $item['calibration'], $config, $runtimeNow, [
                                // the WHOLE market's fresh prices (companion
                                // selections included) for margin removal
                                'marketPrices' => $item['marketPrices'][$odds['market']] ?? [],
                                'previousPrediction' => $previousByKey[$odds['market'] . ':' . $odds['selection']] ?? null,
                                'matchUpdatedAt' => $matchRow['updated_at'] ?? null,
                            ]);

                            $factors = array_merge(['market' => $candidate['market'], 'selection' => $candidate['selection']], $candidate['factors']);
                            // WINDELS model numbers vs bookmaker market numbers,
                            // kept side by side and clearly separated on the
                            // immutable decision record (spec §28/§29).
                            $factors['model'] = [
                                'rawProbability' => $candidate['prediction']['rawModelProbability'] ?? null,
                                'probability' => $candidate['prediction']['calibratedProbability'] ?? null,
                                'fairOdds' => $candidate['prediction']['fairOdds'] ?? null,
                            ];
                            $factors['value'] = [
                                'marketOdds' => $candidate['value']['marketOdds'] ?? null,
                                'impliedProbability' => $candidate['value']['impliedProbability'] ?? null,
                                'fairOdds' => $candidate['value']['fairOdds'] ?? null,
                                'marketFairOdds' => $candidate['value']['marketFairOdds'] ?? null,
                                'edge' => $candidate['value']['edge'] ?? null,
                                'edgePoints' => $candidate['value']['edgePoints'] ?? null,
                                'expectedValue' => $candidate['value']['expectedValue'] ?? null,
                                'valueClass' => $candidate['value']['valueClass'] ?? null,
                            ];

                            // Intelligent refresh: a stored prediction of the
                            // same selection, model version and identical odds
                            // is REUSED, never duplicated by paging or re-runs.
                            $previous = $previousByKey[$odds['market'] . ':' . $odds['selection']] ?? null;
                            if ($this->reusablePrevious($previous, $modelVersionId, $candidate['odds'], $candidate['oddsTimestamp'])) {
                                $funnel['predictionsReused']++;
                                $candidate['predictionId'] = $previous['id'] ?? null;
                            } else {
                                $predictionId = $this->decisions->recordPrediction(
                                    (int) $matchRow['id'],
                                    $candidate['prediction'] + ['market' => $candidate['market'], 'selection' => $candidate['selection']],
                                    $candidate['value'],
                                    $candidate['risk'],
                                    $item['quality'],
                                    $factors,
                                    is_numeric($candidate['confidence']['confidence'] ?? null) ? (float) $candidate['confidence']['confidence'] : null,
                                    $candidate['odds'],
                                    $candidate['oddsTimestamp'],
                                    'LOW'
                                );
                                $candidate['predictionId'] = $predictionId;
                                $recordedThisRun[] = $predictionId;
                                $recorded++;
                                $funnel['predictionsGenerated']++;
                            }
                            $this->trackCandidateFunnel($candidate, $funnel, $minConfidence, $minEv);
                            $allCandidates[] = $candidate;
                            $funnel['marketsEvaluated']++;

                            if ($candidate['decision'] === 'REJECTED') {
                                $rejections++;
                                $primary = $candidate['primaryReason'] ?? ($candidate['rejectionReasons'][0] ?? 'NO_PREDICTION');
                                $this->countRejection($rejectionSummary, $primary, $reasonProviders, $odds['oddsSource'] ?? $item['provider']);
                            } else {
                                $candidates[] = $candidate;
                            }
                        }
                    }

                    // Past the cap: no new predictions are RECORDED, but every
                    // market with a reusable stored prediction is still
                    // evaluated (the stored id is linked, the pipeline re-runs
                    // on the same inputs) so a NO_QUALIFIED_TICKET verdict and
                    // the Top Picks pool reflect the whole stored pool — not
                    // just the capped slice. A fixture with nothing reusable
                    // stays deferred: named on the funnel, never evaluated.
                    foreach ($beyondCap as $item) {
                        $evaluatedMarkets = 0;
                        foreach ($item['odds'] as $odds) {
                            $previous = $item['previousByKey'][$odds['market'] . ':' . $odds['selection']] ?? null;
                            if (!$this->reusablePrevious($previous, $modelVersionId, $odds['decimalOdds'] ?? null, $odds['observedAt'] ?? null)) continue;
                            $candidate = $this->pipeline->evaluate($item['matchRow'], $odds, $item['quality'], $item['calibration'], $config, $runtimeNow, [
                                'marketPrices' => $item['marketPrices'][$odds['market']] ?? [],
                                'previousPrediction' => $previous,
                                'matchUpdatedAt' => $item['matchRow']['updated_at'] ?? null,
                            ]);
                            $candidate['predictionId'] = $previous['id'] ?? null;
                            $funnel['predictionsReused']++;
                            $this->trackCandidateFunnel($candidate, $funnel, $minConfidence, $minEv);
                            $allCandidates[] = $candidate;
                            $funnel['marketsEvaluated']++;
                            if ($candidate['decision'] === 'REJECTED') {
                                $rejections++;
                                $primary = $candidate['primaryReason'] ?? ($candidate['rejectionReasons'][0] ?? 'NO_PREDICTION');
                                $this->countRejection($rejectionSummary, $primary, $reasonProviders, $odds['oddsSource'] ?? $item['provider']);
                            } else {
                                $candidates[] = $candidate;
                            }
                            $evaluatedMarkets++;
                        }
                        if ($evaluatedMarkets === 0) {
                            $fixturesDeferred++;
                            $deferredRows = &$funnel['deferredFixtures']['rows'];
                            if (count($deferredRows) < 50) {
                                $deferredRows[] = ['matchId' => (int) $item['matchRow']['id'], 'externalId' => (string) $item['externalId'], 'provider' => (string) $item['provider'], 'kickoff' => (string) $item['kickoff']];
                            } else $funnel['deferredFixtures']['truncated'] = true;
                            unset($deferredRows);
                        }
                    }
                    $funnel['fixturesDeferred'] = $fixturesDeferred;

                    // ── Stage 11: correlation → final ticket ────────────────
                    if (count($candidates) > 0) {
                        $optimized = $this->optimizer->optimize($candidates, [
                            'targetOddsMin' => (float) $config['target_odds_min'],
                            'targetOddsMax' => (float) $config['target_odds_max'],
                            'maxSelections' => (int) $config['max_selections'],
                            'minConfidence' => (float) $config['min_confidence'],
                            'minDataQuality' => (int) $config['min_data_quality'],
                            'maxCorrelation' => $config['max_correlation'],
                            'allowedMarkets' => $config['allowed_markets'],
                            'allowedLeagues' => $config['allowed_leagues'],
                        ]);
                        $funnel['correlationQualifiedCandidates'] = (int) ($optimized['poolSize'] ?? 0);
                        if ($optimized['status'] === 'QUALIFIED') {
                            $rec = $this->governance->record($optimized, (string) $config['version'], $modelVersionId, $config);
                            if (($rec['status'] ?? '') !== 'NO_QUALIFIED_TICKET') {
                                $status = $rec['status'] === 'APPROVED_NOT_EXECUTED' ? 'APPROVED' : 'PENDING_USER_APPROVAL';
                                $ticketId = $rec['ticketId'];
                                $funnel['finalQualifiedCandidates'] = (int) ($optimized['selectionCount'] ?? 0);
                                $message = $status === 'APPROVED' ? 'ticket generated and auto-approved (AUTOMATED_EXECUTION); no external execution' : 'odds prediction ticket generated; awaiting user approval';
                                // A new live ticket replaces any UNDECIDED
                                // ticket a previous pass left pending for the
                                // same fixture window — the old pass can never
                                // remain the live candidate (requirement #1).
                                // Decided/settled tickets and prediction
                                // history are preserved; the superseded ticket
                                // keeps its legs as an audit record.
                                try {
                                    $superseded = $this->repo->supersedePendingTicketsForWindow($date, $windowTo, (string) $ticketId);
                                    $funnel['ticketsSuperseded'] = (int) ($superseded['ticketsSuperseded'] ?? 0);
                                    if ($funnel['ticketsSuperseded'] > 0) {
                                        $this->audit->emit('SPORTS_TICKET_SUPERSEDED', 'A fresh generation superseded ' . $funnel['ticketsSuperseded'] . ' pending ticket(s) for ' . $date . '..' . $windowTo, ['date' => $date, 'to' => $windowTo, 'newTicketId' => $ticketId, 'superseded' => $superseded]);
                                    }
                                } catch (\Throwable $e) {
                                    // A failed supersede must not strand an
                                    // operator with two live pending tickets
                                    // silently: record it but keep the new
                                    // ticket valid.
                                    $errors[] = 'pending ticket supersede failed: ' . mb_substr($e->getMessage(), 0, 200);
                                }
                            }
                        } else {
                            // No compliant combination: an honest no-ticket day,
                            // with the optimizer's reason kept as the diagnosis.
                            $message = 'NO QUALIFIED TICKET — ' . "Today's available matches did not meet the configured prediction requirements"
                                . ' (' . ($optimized['reason'] ?? 'no compliant combination') . ')';
                        }
                    }
                    if ($ticketId === null && $message === '') {
                        $confidenceFloor = number_format((float) ($config['min_confidence'] ?? 75.0), 0);
                        $message = $evaluated === 0
                            ? 'NO VALUE TICKET TODAY — no verified fixtures received for ' . $date
                            : 'NO QUALIFIED TICKET — ' . "Today's available matches did not meet the configured prediction requirements"
                                . ' (no candidate passed the eligibility, odds, ' . $confidenceFloor . '%+ confidence, quality, risk/value and correlation gates)';
                    }
                    if ($ticketId === null) {
                        // Targeted diagnosis of the two most common upstream dead
                        // ends, so the message says what to FIX, not only what
                        // failed.
                        $withForm = (int) ($funnel['fixturesWithRecentForm'] ?? 0);
                        $freshOdds = (int) ($funnel['fixturesWithFreshOdds'] ?? 0);
                        $formCandidates = (int) ($funnel['formEnrichmentCandidates'] ?? 0);
                        $fr = $funnel['formResolver'] ?? [];
                        if ($freshOdds > 0 && $withForm < max(1, $formCandidates)) {
                            $systemic = $withForm === 0
                                || (int) ($fr['budgetSkips'] ?? 0) > 0
                                || (int) ($fr['lookupFailures'] ?? 0) > 0
                                || (array_key_exists('providerCapable', $fr) && !$fr['providerCapable']);
                            // A fixture left without recentForm is an honest
                            // INSUFFICIENT_DATA rejection — say so when the
                            // cause is systematic (starved budget, failing
                            // lookups, provider without statistics), not when
                            // teams simply had no games to resolve yet.
                            if ($systemic) {
                                $why = empty($fr['providerCapable']) && $fr !== []
                                    ? 'the fixture provider exposes no team-statistics endpoint'
                                    : ((int) ($fr['lookupFailures'] ?? 0) > 0
                                        ? (int) $fr['lookupFailures'] . ' team-statistics lookup(s) failed (quota/auth/errors)'
                                        : ((int) ($fr['budgetSkips'] ?? 0) > 0
                                            ? 'the form lookup budget (WINDELS_SPORTS_FORM_LOOKUPS = ' . (int) ($fr['budget'] ?? 0) . ') ran out over ' . $formCandidates . ' ticket-eligible fixture(s) — raise it or narrow the fixture window'
                                            : 'no provider team statistics were available'));
                                $message .= $withForm === 0
                                    ? ' — recent form could not be resolved for ANY fixture (' . $why . '); without verified recentForm the model computes no probabilities'
                                    : ' — recent form was resolved for only ' . $withForm . ' of ' . $formCandidates . ' ticket-eligible fixtures (' . $why . '); every fixture left without verified recentForm is an INSUFFICIENT_DATA rejection';
                            }
                        }
                        if (($funnel['fixturesWithoutCalibration'] ?? 0) > 0) {
                            $bootstrapState = (string) ($funnel['calibrationBootstrap'] ?? '');
                            $dbError = !empty($funnel['calibrationBootstrapError']) ? ' — database error: ' . $funnel['calibrationBootstrapError'] : '';
                            $message .= $bootstrapState === 'REJECTED_BY_ADMIN'
                                ? ' — the identity bootstrap calibration was REJECTED by an administrator, so no APPROVED calibration exists for the deployed model version (re-approve it, or fit and approve a real calibration, to unblock prediction)'
                                : (in_array($bootstrapState, ['BOOTSTRAP_UNREADABLE', 'CALIBRATION_PERSIST_FAILED', 'APPROVE_PERSIST_FAILED'], true)
                                    ? ' — the engine created its identity bootstrap calibration but the row did not survive the database round-trip (' . $bootstrapState . '): check the sports_calibrations table (the method column must fit the bootstrap marker) and the DB error log, then re-run' . $dbError
                                    : ' — no APPROVED calibration for the deployed model version (create one via POST /api/sports/calibrations/bootstrap-identity, then approve it)');
                        }
                    }
                    if ($ticketId === null) $message .= ' ' . $this->funnelSummary($funnel, $evaluated, $recorded, $rejections);
                    $funnel['topRejectionReasons'] = $this->topRejectionReasons($rejectionSummary);
                    $funnel['rejectionReasonsByProvider'] = $reasonProviders;
                    $funnel['topPicks'] = $this->topPicks($allCandidates);
                    $funnel['topPicksDisclaimer'] = self::TOP_PICKS_DISCLAIMER;
                    $fairConfig = $this->fairValueConfiguration();
                    $funnel['thresholds'] = [
                        'minConfidence' => $minConfidence,
                        'minDataQuality' => $minQuality,
                        'minExpectedValue' => $minEv,
                        'oddsMaxAgeSeconds' => $this->oddsFreshness->maxAge(),
                        'targetOdds' => [(float) $config['target_odds_min'], (float) $config['target_odds_max']],
                        'maxCorrelation' => $config['max_correlation'],
                        'generationCap' => $funnel['generationCap'],
                        'valueThresholdsPoints' => (function (array $t): array {
                            return ['strong' => round($t['strong'] * 100, 2), 'positive' => round($t['positive'] * 100, 2), 'avoid' => round($t['avoid'] * 100, 2)];
                        })($fairConfig->valueThresholds()),
                        'stabilityThresholdsPoints' => (function (array $t): array {
                            return ['moved' => round($t['moved'] * 100, 2), 'unstable' => round($t['unstable'] * 100, 2)];
                        })($fairConfig->stabilityThresholds()),
                    ];
                    $funnel['pipeline'] = 'fixture eligibility → data normalization → odds availability/freshness → prediction → probability → confidence → data quality → value/edge (margin removed) → risk → correlation → ticket';
                }
            }
        } catch (\Throwable $e) {
            $message = 'unexpected failure: ' . $e->getMessage();
            $errors[] = $message;
        }

        // A complete evaluation that produced NO ticket is the fresh verdict
        // for the window: the previous pass's undecided ticket must not remain
        // an actionable stale candidate (the daily slot is about to point at
        // no ticket). Disabled/no-provider/outage runs are NOT verdicts and
        // leave the existing pending ticket untouched for the retry.
        if ($ticketId === null && $dataState === 'OK') {
            try {
                $superseded = $this->repo->supersedePendingTicketsForWindow($date, $windowTo);
                $funnel['ticketsSuperseded'] = (int) ($funnel['ticketsSuperseded'] ?? 0) + (int) ($superseded['ticketsSuperseded'] ?? 0);
                if (($superseded['ticketsSuperseded'] ?? 0) > 0) {
                    $this->audit->emit('SPORTS_TICKET_SUPERSEDED', 'A fresh ' . $status . ' generation for ' . $date . '..' . $windowTo . ' superseded ' . (int) $superseded['ticketsSuperseded'] . ' pending ticket(s) from an earlier pass', ['date' => $date, 'to' => $windowTo, 'newStatus' => $status, 'superseded' => $superseded]);
                }
            } catch (\Throwable $e) {
                $errors[] = 'pending ticket supersede failed: ' . mb_substr($e->getMessage(), 0, 200);
            }
        }

        $diagnostics = $this->buildDiagnostics($funnel, $date, $evaluated, $recorded, $rejections);

        // The rejection summary doubles as the provider-failure ledger on a
        // DATA_UNAVAILABLE day: PROVIDER:<id> → status, so the stored row, the
        // dashboard and the API all show WHICH feed failed and WHY. The
        // diagnostics funnel is stored under a reserved key.
        $storedSummary = $rejectionSummary;
        if ($dataState === 'DATA_UNAVAILABLE') {
            foreach ($providerStatuses as $pid => $st) $storedSummary['PROVIDER:' . $pid] = $st;
        }
        $storedSummary['_diagnostics'] = $diagnostics;
        $this->repo->saveDailyTicket([
            'date' => $date, 'ticket_id' => $ticketId, 'status' => $status,
            'configuration_version' => (int) $config['version'],
            'candidates_evaluated' => $evaluated, 'predictions_recorded' => $recorded,
            'rejections' => $rejections, 'rejection_summary' => json_encode($storedSummary),
            'message' => mb_substr($message, 0, 500), 'provider' => $provider, 'run_id' => $run['id'],
            'created_at' => gmdate('c'), 'updated_at' => gmdate('c'),
        ]);
        // A data outage is a FAILED run, and its execution key must not block
        // the retry: the next sweep (after the quota reset / config fix) gets
        // a fresh idempotency slot instead of DUPLICATE_SKIPPED all day.
        $runStatus = $dataState === 'DATA_UNAVAILABLE' ? 'FAILED' : 'COMPLETED';
        $this->repo->finishJobRun($run['id'], ['status' => $runStatus, 'processed' => $evaluated, 'created' => $recorded, 'updated' => 0, 'errors' => $errors]);
        if ($dataState === 'DATA_UNAVAILABLE' && method_exists($this->repo, 'releaseJobRun')) {
            try { $this->repo->releaseJobRun($run['id']); } catch (\Throwable $e) { /* best effort */ }
        }
        // Same principle for a day that stored NOTHING: no predictions, no
        // reused predictions, no ticket. That is a BLOCKED day (missing form
        // data, missing calibration), not a verdict — the operator fixes the
        // upstream problem and retries the same date without having to bump
        // the configuration version just to get a fresh execution key. A run
        // that stored any prediction or ticket keeps its idempotency slot.
        $nothingStored = $ticketId === null && $recorded === 0 && (int) ($funnel['predictionsReused'] ?? 0) === 0;
        if ($dataState === 'OK' && $nothingStored && method_exists($this->repo, 'releaseJobRun')) {
            try { $this->repo->releaseJobRun($run['id']); } catch (\Throwable $e) { /* best effort */ }
        }
        $this->audit->emit($dataState === 'DATA_UNAVAILABLE' ? 'SPORTS_DAILY_TICKET_BLOCKED' : 'SPORTS_DAILY_TICKET_RUN', 'Daily ticket run ' . $date . ' → ' . $status, [
            'date' => $date, 'status' => $status, 'dataState' => $dataState, 'ticketId' => $ticketId, 'evaluated' => $evaluated,
            'rejections' => $rejections, 'rejectionSummary' => $rejectionSummary, 'diagnostics' => $diagnostics, 'message' => $message, 'provider' => $provider,
            'providerFailures' => $providerFailures, 'providerStatuses' => $providerStatuses, 'errors' => $errors,
        ]);
        return [
            'status' => $status, 'dataState' => $dataState, 'ticketId' => $ticketId, 'date' => $date, 'message' => $message,
            'evaluated' => $evaluated, 'predictionsRecorded' => $recorded, 'rejections' => $rejections, 'rejectionSummary' => $rejectionSummary,
            'diagnostics' => $diagnostics, 'invalidated' => $invalidated,
            'provider' => $provider, 'providerFailures' => $providerFailures, 'providerStatuses' => $providerStatuses,
            'runId' => $run['id'], 'errors' => $errors,
        ];
    }

    /** Count one rejection under its single primary reason, with provider attribution. */
    private function countRejection(array &$summary, string $reason, array &$reasonProviders, string $provider): void
    {
        $summary[$reason] = ($summary[$reason] ?? 0) + 1;
        $reasonProviders[$reason][$provider] = ($reasonProviders[$reason][$provider] ?? 0) + 1;
    }

    /**
     * Record one fresh-odds fixture's evaluation against the sufficient-data
     * gate: mandatory model inputs (e.g. verified recentForm), an APPROVED
     * calibration, and the data-quality floor. Every requirement's result is
     * kept — the first failed one is flagged as the primary blocker — so a
     * "0 sufficient-data fixtures" day shows the exact requirement (and the
     * concrete missing fields) for each fixture instead of an aggregate zero.
     */
    private function recordSufficientDataGate(array &$funnel, array $row): void
    {
        $gate = &$funnel['sufficientDataGate'];
        if ($row['passed']) $gate['passed']++;
        else {
            $gate['failed']++;
            $requirement = (string) ($row['failedRequirement'] ?? 'UNKNOWN');
            $funnel['sufficientDataFailuresByRequirement'][$requirement]
                = ($funnel['sufficientDataFailuresByRequirement'][$requirement] ?? 0) + 1;
        }
        if (count($gate['fixtures']) < (int) $gate['limit']) {
            $gate['fixtures'][] = $row;
        } else {
            $gate['truncated'] = true;
        }
    }

    /** Funnel counters for one evaluated candidate (per market:selection). */
    private function trackCandidateFunnel(array $candidate, array &$funnel, float $minConfidence, float $minEv): void
    {
        $ready = ($candidate['prediction']['decision'] ?? '') === 'PREDICTION_READY';
        if ($ready) $funnel['sufficientDataCandidates']++;
        if (is_numeric($candidate['confidence']['confidence'] ?? null) && (float) $candidate['confidence']['confidence'] >= $minConfidence) $funnel['confidenceQualifiedCandidates']++;
        if (!empty($candidate['value']['qualified']) && (float) ($candidate['value']['expectedValue'] ?? -1) > 0) $funnel['positiveValueCandidates']++;
        if (!empty($candidate['value']['qualified']) && (float) ($candidate['value']['expectedValue'] ?? -1) >= $minEv) $funnel['minEdgeMetCandidates']++;
        $riskClass = (string) ($candidate['risk']['classification'] ?? 'REJECTED');
        if (!empty($candidate['risk']['approved']) && $riskClass !== 'HIGH' && $riskClass !== 'REJECTED') $funnel['riskQualifiedCandidates']++;
    }

    private function emptyFunnel(): array
    {
        return [
            'providersConfigured' => 0,
            // Every provider that delivered fixtures this run (all-provider
            // intake), in registration order.
            'fixtureProviders' => [],
            // Canonical duplicates merged away: saved under their own provider,
            // evaluated once through the primary. Rows are capped; the count
            // never is.
            'fixturesDeduped' => 0,
            'duplicateFixtures' => ['truncated' => false, 'rows' => []],
            'eligibleFixtures' => 0,
            'fixturesWithRecentForm' => 0,
            'fixturesWithCarriedForwardForm' => 0,
            'formEnrichmentCandidates' => 0,
            'formResolver' => [],
            'fixturesWithSupportedOdds' => 0,
            'fixturesWithFreshOdds' => 0,
            'fixturesRejectedStaleOdds' => 0,
            'fixturesRejectedNoOdds' => 0,
            'fixturesMissingMandatoryData' => 0,
            'fixturesWithoutCalibration' => 0,
            'calibrationBootstrap' => null,
            'calibrationBootstrapError' => null,
            'calibrationId' => null,
            'fixturesBelowQualityFloor' => 0,
            'sufficientDataFixtures' => 0,
            // One explicit row per fresh-odds fixture, naming exactly which
            // sufficient-data requirement it failed (no black-box zero).
            'sufficientDataFailuresByRequirement' => [],
            'sufficientDataGate' => [
                'requirements' => ['MANDATORY_MODEL_DATA', 'APPROVED_CALIBRATION', 'DATA_QUALITY_FLOOR'],
                'limit' => 100,
                'truncated' => false,
                'passed' => 0,
                'failed' => 0,
                'fixtures' => [],
            ],
            'generationCap' => 0,
            'fixturesDeferred' => 0,
            // Fixtures past the generation cap with no reusable stored
            // prediction — honestly unevaluated for this run's ticket, named
            // here instead of vanishing. Rows are capped; the count never is.
            'deferredFixtures' => ['truncated' => false, 'rows' => []],
            'predictionsGenerated' => 0,
            'predictionsReused' => 0,
            // market:selection candidates scored across the full stored pool.
            'marketsEvaluated' => 0,
            'sufficientDataCandidates' => 0,
            'confidenceQualifiedCandidates' => 0,
            'positiveValueCandidates' => 0,
            'minEdgeMetCandidates' => 0,
            'riskQualifiedCandidates' => 0,
            'correlationQualifiedCandidates' => 0,
            'finalQualifiedCandidates' => 0,
            // Undecided tickets from earlier passes that this generation
            // superseded for the same fixture window (never decided/settled).
            'ticketsSuperseded' => 0,
            'oddsRefreshAttempts' => 0,
            'oddsRefreshedFixtures' => 0,
            'oddsProvidersUsed' => [],
            'oddsProviderFailures' => [],
            'oddsProviderFailureStatuses' => [],
            'oddsProvidersNoCoverage' => [],
            'topRejectionReasons' => [],
            'rejectionReasonsByProvider' => [],
            'topPicks' => [],
            'topPicksDisclaimer' => self::TOP_PICKS_DISCLAIMER,
            'thresholds' => [],
            'pipeline' => '',
        ];
    }

    private function buildDiagnostics(array $funnel, string $date, int $evaluated, int $recorded, int $rejections): array
    {
        $funnel['date'] = $date;
        $funnel['fixturesEvaluated'] = $evaluated;
        $funnel['predictionsRecorded'] = $recorded;
        $funnel['totalRejections'] = $rejections;
        return $funnel;
    }

    /** Compact one-line funnel for the human-readable message. */
    private function funnelSummary(array $funnel, int $evaluated, int $recorded, int $rejections): string
    {
        $extra = '';
        if (!empty($funnel['fixturesDeduped'])) $extra .= sprintf(', %d merged duplicates evaluated once', (int) $funnel['fixturesDeduped']);
        if (!empty($funnel['fixturesDeferred'])) $extra .= sprintf(', %d deferred by the %d-generation cap', (int) $funnel['fixturesDeferred'], (int) ($funnel['generationCap'] ?? 0));
        if (!empty($funnel['predictionsReused'])) $extra .= sprintf(', %d reused', (int) $funnel['predictionsReused']);
        if (!empty($funnel['fixturesWithCarriedForwardForm'])) $extra .= sprintf(', %d form carried forward', (int) $funnel['fixturesWithCarriedForwardForm']);
        if (!empty($funnel['formResolver']['budgetSkips'])) {
            $extra .= sprintf(', %d form lookups skipped at the %d-lookup budget (WINDELS_SPORTS_FORM_LOOKUPS)', (int) $funnel['formResolver']['budgetSkips'], (int) ($funnel['formResolver']['budget'] ?? 0));
        }
        return sprintf(
            '(%d evaluated, %d predictions, %d rejections%s; funnel: %d eligible → %d with-form → %d fresh-odds → %d sufficient-data fixtures → %d predictions → %d confidence-qualified → %d positive-value → %d risk-qualified → %d final)',
            $evaluated,
            $recorded,
            $rejections,
            $extra,
            $funnel['eligibleFixtures'],
            $funnel['fixturesWithRecentForm'],
            $funnel['fixturesWithFreshOdds'],
            $funnel['sufficientDataFixtures'],
            $funnel['predictionsGenerated'],
            $funnel['confidenceQualifiedCandidates'],
            $funnel['positiveValueCandidates'],
            $funnel['riskQualifiedCandidates'],
            $funnel['finalQualifiedCandidates']
        );
    }

    /**
     * Top WINDELS Picks: the best-evidenced generated candidates of the run,
     * ranked (qualified first, then intelligence score, then edge). A reading
     * of the day's evidence — never a guarantee, and never a second ticket.
     *
     * @param array $candidates every candidate generated this run
     */
    /**
     * Why a pick was picked: the shared driver headline when the selection has
     * a predicted side (match-winner markets), otherwise the driver rows
     * restated as "reading: verdict" pairs — the stored figures behind the
     * prediction, never a manufactured narrative.
     */
    private function whyOf(?array $drivers): ?string
    {
        if ($drivers === null) return null;
        $headline = $drivers['headline'] ?? null;
        if (is_string($headline) && $headline !== '') return $headline;
        $parts = [];
        foreach ((array) ($drivers['drivers'] ?? []) as $row) {
            if (!is_array($row) || !isset($row['label'], $row['verdict'])) continue;
            $parts[] = $row['label'] . ': ' . $row['verdict'];
        }
        return $parts === [] ? null : implode('; ', $parts) . '.';
    }

    private function topPicks(array $candidates): array
    {
        $ready = array_values(array_filter($candidates, fn(array $c) => ($c['prediction']['decision'] ?? '') === 'PREDICTION_READY'));
        // usort's callback must return an INT — returning the tuple directly
        // would coerce a non-empty array to 1 on every call and scramble the
        // order, so each key is compared until one differs.
        usort($ready, function (array $a, array $b): int {
            $cmp = ((($b['decision'] ?? '') === 'QUALIFIED') <=> (($a['decision'] ?? '') === 'QUALIFIED'));
            if ($cmp !== 0) return $cmp;
            $cmp = (int) ($b['intelligenceScore']['score'] ?? -1) <=> (int) ($a['intelligenceScore']['score'] ?? -1);
            if ($cmp !== 0) return $cmp;
            $cmp = (float) ($b['value']['edgePoints'] ?? -999.0) <=> (float) ($a['value']['edgePoints'] ?? -999.0);
            if ($cmp !== 0) return $cmp;
            return (float) ($b['confidence']['confidence'] ?? -1.0) <=> (float) ($a['confidence']['confidence'] ?? -1.0);
        });
        $picks = [];
        foreach (array_slice($ready, 0, $this->fairValueConfiguration()->picksLimit()) as $c) {
            // The transparent output row: internal match id, league, teams,
            // kickoff, odds provider, market, selection, price + timestamp,
            // confidence, quality, value/edge, risk and decision status — every
            // field the row behind a pick must answer, none invented.
            $picks[] = [
                'matchId' => $c['matchId'] ?? null,
                'match' => ($c['match']['homeTeam'] ?? '?') . ' vs ' . ($c['match']['awayTeam'] ?? '?'),
                'homeTeam' => $c['match']['homeTeam'] ?? null,
                'awayTeam' => $c['match']['awayTeam'] ?? null,
                'kickoff' => $c['match']['kickoff'] ?? null,
                'competition' => $c['match']['competition'] ?? null,
                'provider' => $c['oddsSource'] ?? null,
                'market' => $c['market'],
                'selection' => $c['selection'],
                'modelProbability' => $c['prediction']['calibratedProbability'] ?? null,
                'windelsFairOdds' => $c['value']['fairOdds'] ?? null,
                'marketOdds' => $c['value']['marketOdds'] ?? null,
                'oddsTimestamp' => $c['oddsTimestamp'] ?? null,
                'marketFairOdds' => $c['value']['marketFairOdds'] ?? null,
                'marginPoints' => $c['value']['marginPoints'] ?? null,
                'edgePoints' => $c['value']['edgePoints'] ?? null,
                'expectedValue' => $c['value']['expectedValue'] ?? null,
                'valueClass' => $c['value']['valueClass'] ?? 'UNPRICED',
                'valueLabel' => $c['value']['valueLabel'] ?? null,
                'valueReason' => $c['value']['valueReason'] ?? null,
                'confidence' => $c['confidence']['confidence'] ?? null,
                'dataQuality' => $c['quality']['score'] ?? null,
                'risk' => ['classification' => $c['risk']['classification'] ?? null, 'approved' => !empty($c['risk']['approved'])],
                'intelligenceScore' => ['score' => $c['intelligenceScore']['score'] ?? null, 'band' => $c['intelligenceScore']['band'] ?? null],
                'stability' => ['state' => $c['stability']['state'] ?? null, 'movementPoints' => $c['stability']['movementPoints'] ?? null],
                'why' => $this->whyOf($c['drivers'] ?? null),
                'qualified' => ($c['decision'] ?? '') === 'QUALIFIED',
                'status' => $c['decision'] ?? null,
                'primaryReason' => $c['primaryReason'] ?? null,
            ];
        }
        return $picks;
    }

    /**
     * The newest stored prediction per (market, selection) of a match from
     * BEFORE this run — the reuse and stability input. Rows written by this
     * run itself are never "previous".
     *
     * @return array<string,array> "MARKET:SELECTION" → prediction row
     */
    /**
     * The stored predictions a fixture can reuse: the newest row per
     * market:selection that was NOT written by this run. Exclusion is by id,
     * not by timestamp — second-granularity created_at values would make
     * back-to-back runs (a retry seconds later, or two cron sweeps) miss
     * perfectly reusable stored predictions.
     *
     * @param array<int,string> $excludeIds prediction ids recorded by this run
     */
    private function previousPredictionsFor(int $matchId, array $excludeIds): array
    {
        try {
            $rows = $this->repo->listPredictions(['matchId' => $matchId], 500);
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (in_array((string) ($row['id'] ?? ''), $excludeIds, true)) continue;
            $created = strtotime((string) ($row['created_at'] ?? ''));
            if ($created === false) continue;
            $key = strtoupper((string) ($row['market'] ?? '')) . ':' . strtoupper((string) ($row['selection'] ?? ''));
            $existing = $out[$key] ?? null;
            if ($existing === null || $created > (int) strtotime((string) ($existing['created_at'] ?? ''))) $out[$key] = $row;
        }
        return $out;
    }

    /**
     * Fixture intake across every registered provider: each feed is asked once
     * (circuit breaker and one health probe per provider per run), and every
     * answer is kept with its own provider row id. A failed provider is
     * recorded and skipped; only when NO provider answered is the day a data
     * outage.
     *
     * @return array{ok:bool, sources?:list<array{provider:string, providerId:int, instance:?SportsDataProvider, fixtures:array}>, failures:array<string,string>, failureStatuses:array<string,string>, summary:string}
     */
    private function fetchFixtureSources(string $date, array &$errors): array
    {
        $collected = $this->providers->collectAll('fixtures', fn(SportsDataProvider $p) => $p->fixtures(['from' => $date, 'to' => $date]));
        $sources = [];
        foreach ($collected['results'] as $code => $fixtures) {
            if (!is_array($fixtures)) {
                $collected['failures'][$code] = 'DATA_ERROR: provider returned no fixture list';
                $collected['failureStatuses'][$code] = 'DATA_ERROR';
                $errors[] = 'provider failure: ' . $code . ' returned no fixture list';
                continue;
            }
            try {
                $providerId = (int) $this->repo->ensureProvider($code, $code)['id'];
            } catch (\Throwable $e) {
                $collected['failures'][$code] = 'DATA_ERROR: ' . mb_substr($e->getMessage(), 0, 160);
                $collected['failureStatuses'][$code] = 'DATA_ERROR';
                $errors[] = 'provider failure: ' . $code . ' provider row unavailable';
                continue;
            }
            $sources[] = ['provider' => $code, 'providerId' => $providerId, 'instance' => $this->providers->provider($code), 'fixtures' => $fixtures];
        }
        if ($sources === []) {
            return ['ok' => false, 'failures' => $collected['failures'], 'failureStatuses' => $collected['failureStatuses'], 'summary' => $collected['summary']];
        }
        return ['ok' => true, 'sources' => $sources, 'failures' => $collected['failures'], 'failureStatuses' => $collected['failureStatuses'], 'summary' => ''];
    }

    /**
     * Canonical group key for cross-provider merging: normalized teams +
     * kickoff date + normalized competition. Rows that group together are one
     * real match under several provider ids; rows that do not are evaluated
     * separately. The competition is part of the key so same-club fixtures in
     * different competitions (men/women, league/cup) never merge.
     */
    private function canonicalGroupKey(array $probe): string
    {
        $identity = CanonicalMatch::identity((string) ($probe['homeTeam'] ?? ''), (string) ($probe['awayTeam'] ?? ''), (string) ($probe['kickoff'] ?? ''));
        $competition = CanonicalMatch::slug((string) ($probe['competition'] ?? ''));
        return $identity . '|' . $competition;
    }

    /**
     * Which row of a canonical group carries the match through the gates.
     * Eligible beats ineligible (a cross-provider status disagreement must
     * never lose the match), id-carrying rows beat bare ones (form is only
     * resolvable with ids), then season, then already-present form. Ties keep
     * registration order — deterministic, operator-controlled priority.
     */
    private function canonicalEntryScore(array $entry): int
    {
        $raw = $entry['raw'];
        $score = !empty($entry['eligible']) ? 8 : 0;
        if (!empty($raw['homeTeamId']) && !empty($raw['awayTeamId']) && (!empty($raw['leagueId']) || !empty($entry['probe']['leagueId']))) $score += 4;
        if (trim((string) ($raw['season'] ?? '')) !== '') $score += 2;
        if (!empty($raw['context']['recentForm'])) $score += 1;
        return $score;
    }

    /**
     * Whether a stored prediction row is reusable for a market price: same
     * model version, identical decimal odds and identical observed timestamp.
     * The pipeline still re-runs on reuse (a fresh evaluation); only the
     * duplicate RECORD is skipped — paging and re-runs never duplicate one.
     */
    private function reusablePrevious(?array $previous, ?int $modelVersionId, mixed $decimalOdds, mixed $observedAt): bool
    {
        return $previous !== null
            && $modelVersionId !== null
            && (int) ($previous['model_version_id'] ?? 0) === $modelVersionId
            && is_numeric($previous['odds'] ?? null) && (float) $previous['odds'] === (float) $decimalOdds
            && (string) ($previous['odds_timestamp'] ?? '') === (string) $observedAt;
    }

    /** The bounded per-run generation cap (MAXIMUM GENERATION, design §9). */
    private function generationCap(): int
    {
        $env = getenv(self::ENV_MAX_GENERATION);
        if (is_string($env) && $env !== '' && is_numeric($env)) {
            $value = (int) $env;
            if ($value >= 1) return min(self::MAX_GENERATION_CEILING, $value);
        }
        return self::DEFAULT_MAX_GENERATION;
    }

    /** Shared value/stability/picks configuration (identical to the Football board's). */
    private function fairValueConfiguration(): \AIWorkforce\Football\FootballConfiguration
    {
        if ($this->fairConfig === null) $this->fairConfig = new \AIWorkforce\Football\FootballConfiguration();
        return $this->fairConfig;
    }


    /** Top rejection reasons (primary-reason counts), largest first. */
    private function topRejectionReasons(array $summary, int $limit = 6): array
    {
        $reasons = array_filter($summary, fn($v) => is_int($v));
        arsort($reasons);
        return array_slice($reasons, 0, $limit, true);
    }

    /**
     * Daily odds-prediction ticket eligibility: football only, provider state
     * must be NS (or the provider's explicit NS→SCHEDULED mapping preserved in
     * payload/sourceStatus), and kickoff must be strictly more than two hours
     * from the runtime clock.
     */
    private function fixtureEligibleForDailyTicket(array $match, int $now): bool
    {
        if (strtolower((string) ($match['sport'] ?? '')) !== 'football') return false;
        $sourceStatus = strtoupper((string) ($match['sourceStatus'] ?? $match['status'] ?? ''));
        $canonical = strtoupper((string) ($match['status'] ?? ''));
        if ($sourceStatus !== 'NS' && !($sourceStatus === 'SCHEDULED' && $canonical === 'SCHEDULED')) return false;
        try { $kickoff = (new \DateTimeImmutable((string) ($match['kickoff'] ?? '')))->getTimestamp(); }
        catch (\Throwable $e) { return false; }
        return $kickoff > ($now + 2 * 3600);
    }

    /**
     * Reuse recentForm that a PREVIOUS run already verified for this fixture.
     *
     * A provider's fixtures() response never carries form, so before this the
     * engine threw away perfectly good form the moment a lookup budget ran
     * out or a quota died, and asked the API for it again. Team season
     * averages move by a fraction of a goal per week, so form stored inside
     * the TTL is reused as-is — with its original source and timestamp intact
     * so the decision record still says exactly where the numbers came from
     * and when they were read. Older or absent form is left absent: nothing
     * is extrapolated, and the fixture is enriched or honestly rejected.
     */
    private function carryForwardStoredForm(int $providerId, array $rawFixture, int $now, array &$funnel): array
    {
        if (!empty($rawFixture['context']['recentForm'])) return $rawFixture;
        $externalId = trim((string) ($rawFixture['externalId'] ?? ''));
        if ($externalId === '') return $rawFixture;
        try { $stored = $this->repo->findMatch($providerId, $externalId); }
        catch (\Throwable $e) { return $rawFixture; }
        // The stored document, in whichever shape the repository read gave it
        // back (decoded array or raw JSON text) — a text payload is still the
        // verified reading of the previous run, never "no stored form".
        $payload = SportsDataNormalizer::document($stored['payload'] ?? null);
        $form = $payload['context']['recentForm'] ?? null;
        if (!is_array($form)) return $rawFixture;
        foreach (FeatureEngineeringEngine::REQUIRED_FORM_FIELDS as $field) {
            if (!isset($form[$field]) || !is_numeric($form[$field])) return $rawFixture;
        }
        $stamp = is_string($form['timestamp'] ?? null) ? $form['timestamp'] : null;
        if ($stamp === null) return $rawFixture;   // unmeasurable age → never reused
        try { $age = $now - (new \DateTimeImmutable($stamp))->getTimestamp(); }
        catch (\Throwable $e) { return $rawFixture; }
        if ($age < 0 || $age > $this->formMaxAgeSeconds()) return $rawFixture;
        $rawFixture['context'] = array_merge($rawFixture['context'] ?? [], ['recentForm' => $form]);
        $funnel['fixturesWithCarriedForwardForm'] = (int) ($funnel['fixturesWithCarriedForwardForm'] ?? 0) + 1;
        return $rawFixture;
    }

    /** How long verified recentForm stays usable (env-tunable, default 7 days). */
    private function formMaxAgeSeconds(): int
    {
        $env = getenv(self::ENV_FORM_MAX_AGE);
        if (is_string($env) && $env !== '' && is_numeric($env) && (int) $env > 0) return (int) $env;
        return self::DEFAULT_FORM_MAX_AGE_SECONDS;
    }

    /** Data fields present in the stored match context (quality + gating inputs). */
    private function contextFields(array $matchRow): array
    {
        $payload = SportsDataNormalizer::document($matchRow['payload'] ?? null);
        $context = is_array($payload['context'] ?? null) ? $payload['context'] : [];
        $present = [];
        foreach ($context as $key => $value) {
            if ($value !== null && $value !== []) $present[] = (string) $key;
        }
        return $present;
    }

    /**
     * Resolve the fixture's usable (supported AND fresh) odds rows.
     *
     * Order: stored rows → bulk round rows (already fetched, one request per
     * matchday) → per-fixture refresh across every configured provider that
     * has a VERIFIED fixture id (cross-references included). Odds are only
     * refreshed when no fresh row exists — a once-a-day sync that is still
     * inside the TTL is used as-is, never re-fetched, never marked stale
     * merely because it was not refreshed during this run.
     *
     * @param array $roundOdds the fixture provider's OWN bulk rows, keyed by
     * that provider's external fixture id (round namespaces never mix).
     * @return array{ok:bool, reason?:string, provider?:string, rows:array, staleCount:int, refreshTried:bool}
     */
    private function resolveUsableOdds(array $matchRow, string $fixtureProvider, array $roundOdds, array &$errors, int $now, array &$funnel): array
    {
        $matchId = (int) $matchRow['id'];
        $externalId = (string) $matchRow['external_id'];

        // Select the newest row per market:selection, judge freshness once per
        // row, and build the WHOLE-market price sheets used for margin removal
        // (companion selections — UNDER_1_5, BTTS NO — are prices, never
        // candidates: the overround needs the complete market).
        $select = function () use ($matchId, $now): array {
            $rows = method_exists($this->repo, 'listOdds') ? $this->repo->listOdds($matchId, 200) : [];
            $latest = $this->latestOddsRows($rows, $this->providerCodeMap());
            $marketPrices = [];
            $supported = 0;
            $usable = [];
            $staleCount = 0;
            foreach ($latest as $row) {
                $assessment = $this->oddsFreshness->assess($row, null, $now);
                $fresh = !empty($assessment['fresh']);
                if ($fresh) {
                    $marketPrices[$row['market']][$row['selection']] = ['odds' => $row['decimalOdds'], 'observedAt' => $row['observedAt']];
                }
                if (!PredictionEngine::isSupportedMarketSelection($row['market'], $row['selection'])) continue;
                $supported++;
                $row['oddsStatus'] = $assessment['oddsStatus'];
                $row['oddsUpdatedAt'] = $assessment['oddsUpdatedAt'];
                $row['oddsAgeSeconds'] = $assessment['oddsAgeSeconds'];
                $row['maxOddsAgeSeconds'] = $assessment['maxAgeSeconds'];
                if (!empty($row['oddsSource'])) $row['provider'] = $row['oddsSource'];
                if ($fresh) $usable[] = $row;
                else $staleCount++;
            }
            return [$supported, $usable, $staleCount, $marketPrices];
        };

        [$supported, $usable, $staleCount, $marketPrices] = $select();

        if ($usable === []) {
            // Refresh only when needed. Bulk round rows first (one request per
            // matchday), then the per-fixture cross-provider fallback.
            $rawOdds = $roundOdds[$externalId] ?? null;
            $saved = 0;
            if ($rawOdds !== null) {
                $funnel['oddsRefreshAttempts']++;
                $saved = $this->persistOdds($matchId, $rawOdds, $fixtureProvider, $externalId, $errors);
                if ($saved > 0) {
                    $funnel['oddsRefreshedFixtures']++;
                    $funnel['oddsProvidersUsed'][$fixtureProvider] = ($funnel['oddsProvidersUsed'][$fixtureProvider] ?? 0) + 1;
                }
            }
            if ($saved === 0) {
                // Walk every configured provider that has a VERIFIED fixture
                // id (fixture provider first, then cross-referenced ones).
                // A provider answering "no odds for this fixture" (empty, not
                // an error) is not a failure — the next provider is tried
                // before the candidate is rejected.
                $idsByProvider = $this->fixtureIdsByProvider($matchRow, $fixtureProvider);
                foreach ($idsByProvider as $pid => $fid) {
                    $oddsAttempt = $this->providers->withFallbackIds('odds', [$pid => $fid], fn(SportsDataProvider $p, string $id) => $p->odds($id));
                    $funnel['oddsRefreshAttempts']++;
                    foreach ($oddsAttempt['failureStatuses'] ?? [] as $failedPid => $st) {
                        $funnel['oddsProviderFailures'][$failedPid] = ($funnel['oddsProviderFailures'][$failedPid] ?? 0) + 1;
                        $funnel['oddsProviderFailureStatuses'][$failedPid] = $st;
                    }
                    if (!$oddsAttempt['ok'] || !is_array($oddsAttempt['result'] ?? null)) continue;
                    if ($oddsAttempt['result'] === []) {
                        $funnel['oddsProvidersNoCoverage'][$pid] = ($funnel['oddsProvidersNoCoverage'][$pid] ?? 0) + 1;
                        continue;
                    }
                    $saved = $this->persistOdds($matchId, $oddsAttempt['result'], (string) $oddsAttempt['provider'], (string) $fid, $errors);
                    if ($saved > 0) {
                        $funnel['oddsRefreshedFixtures']++;
                        $funnel['oddsProvidersUsed'][(string) $oddsAttempt['provider']] = ($funnel['oddsProvidersUsed'][(string) $oddsAttempt['provider']] ?? 0) + 1;
                        break;
                    }
                }
            }
            [$supported, $usable, $staleCount, $marketPrices] = $select();
        }

        if ($supported > 0) $funnel['fixturesWithSupportedOdds']++;
        if ($supported === 0) {
            $funnel['fixturesRejectedNoOdds']++;
            return ['ok' => false, 'reason' => 'SUPPORTED_ODDS_UNAVAILABLE', 'provider' => $fixtureProvider, 'rows' => [], 'staleCount' => 0, 'marketPrices' => []];
        }
        if ($usable === []) {
            // Real rows exist but every one exceeded the configured TTL and no
            // provider could refresh them. One fixture-level rejection —
            // never one per market:selection row.
            $funnel['fixturesRejectedStaleOdds']++;
            return ['ok' => false, 'reason' => 'STALE_ODDS', 'provider' => $fixtureProvider, 'rows' => [], 'staleCount' => $staleCount, 'marketPrices' => []];
        }
        return ['ok' => true, 'rows' => $usable, 'staleCount' => $staleCount, 'marketPrices' => $marketPrices];
    }

    /** Persist freshly fetched raw odds rows (never invented, fixture id verified per provider namespace). Returns rows stored. */
    private function persistOdds(int $matchId, array $rawOdds, string $oddsProvider, string $requestedFixtureId, array &$errors): int
    {
        $saved = 0;
        foreach ($rawOdds as $rawOddsRow) {
            try {
                if (!is_array($rawOddsRow)) continue;
                if (!empty($rawOddsRow['fixtureId']) && (string) $rawOddsRow['fixtureId'] !== $requestedFixtureId) {
                    throw new \InvalidArgumentException('odds fixture id mismatch');
                }
                $oddsProviderId = (int) $this->repo->ensureProvider($oddsProvider, $oddsProvider)['id'];
                $this->providerCodes[$oddsProviderId] = $oddsProvider;
                $this->repo->saveOdds($matchId, $oddsProviderId, SportsDataNormalizer::odds($rawOddsRow, $oddsProvider));
                $saved++;
            } catch (\Throwable $e) {
                $errors[] = 'odds rejected: ' . mb_substr($e->getMessage(), 0, 200);
            }
        }
        return $saved;
    }

    /** Provider row id → provider code, loaded once per run. */
    private function providerCodeMap(): array
    {
        if ($this->providerCodes === []) {
            try {
                foreach ($this->repo->listProviders() as $p) {
                    $this->providerCodes[(int) $p['id']] = (string) ($p['provider_code'] ?? '');
                }
            } catch (\Throwable $e) { /* best effort */ }
        }
        return $this->providerCodes;
    }

    /**
     * Fixture ids in each provider's OWN namespace: the supplying provider's
     * external id plus any recorded cross-references (e.g. TheSportsDB →
     * api-football). Used for cross-provider odds fallback without id
     * collisions — providers without a verified id are skipped upstream.
     *
     * @return array<string,string>
     */
    private function fixtureIdsByProvider(array $matchRow, string $fixtureProvider): array
    {
        $ids = [$fixtureProvider => (string) $matchRow['external_id']];
        $payload = SportsDataNormalizer::document($matchRow['payload'] ?? null);
        foreach ((array) ($payload['crossReferences'] ?? []) as $providerCode => $extId) {
            $providerCode = (string) $providerCode;
            $extId = trim((string) $extId);
            if ($extId !== '' && $this->providers->provider($providerCode) !== null) $ids[$providerCode] = $extId;
        }
        return array_filter($ids, fn($v) => $v !== '');
    }

    /** Provider health, probed once per provider per run (not per fixture). */
    private function providerHealth(string $providerId): array
    {
        if (!isset($this->healthCache[$providerId])) {
            try { $this->healthCache[$providerId] = $this->providers->provider($providerId)?->health() ?? []; }
            catch (\Throwable $e) { $this->healthCache[$providerId] = []; }
        }
        return $this->healthCache[$providerId];
    }

    /**
     * The newest stored odds row per market:selection — for EVERY selection,
     * not only the supported ticket markets. A market's overround needs all of
     * its mutually exclusive outcomes priced, so companion selections
     * (UNDER_1_5, BTTS NO) are returned as prices; the candidate filter (what
     * may be predicted) is applied by the caller, never here.
     */
    private function latestOddsRows(array $rows, array $providerCodes = []): array
    {
        $latest = [];
        foreach ($rows as $row) {
            $market = strtoupper(trim((string) ($row['market'] ?? '')));
            $selection = strtoupper(trim((string) ($row['selection'] ?? '')));
            $decimal = $row['decimalOdds'] ?? $row['decimal_odds'] ?? null;
            $observed = $row['observedAt'] ?? $row['observed_at'] ?? null;
            if ($market === '' || $selection === '') continue;
            // Stored rows are re-validated on the way out: a corrupted price
            // written before the ingestion guard existed (zero, absurd, or
            // above the market's plausibility ceiling) is skipped here — a
            // legacy row must never become a ticket leg.
            if (!OddsBounds::validDecimalOdds($decimal, $market)) continue;
            if (!$observed) continue;
            $key = $market . ':' . $selection;
            if (!isset($latest[$key]) || strcmp((string) $observed, (string) $latest[$key]['observedAt']) > 0) {
                $source = (string) ($providerCodes[(int) ($row['provider_id'] ?? 0)] ?? '');
                // Normalise the document once: the freshness and value stages
                // downstream read it as a document, not as column text.
                $payload = SportsDataNormalizer::document($row['payload'] ?? null);
                if ($source === '') $source = (string) ($payload['provider'] ?? '');
                $latest[$key] = ['market' => $market, 'selection' => $selection, 'decimalOdds' => (float) $decimal, 'observedAt' => (string) $observed, 'payload' => $payload] + ($source !== '' ? ['oddsSource' => $source] : []);
            }
        }
        return array_values($latest);
    }

    /**
     * Bulk-fetch the day's odds when the fixture provider exposes the round
     * endpoint (SportMonks): one request per matchday instead of one per
     * fixture. Only the fixture's OWN provider is asked — round ids are
     * provider-specific and a foreign round id could return another
     * matchday's odds. Fixtures without a roundId, or a failed round fetch,
     * are simply absent — the per-match loop falls back to the per-fixture
     * odds() call for them (no fabricated odds, ever).
     */
    private function fetchRoundOdds(?SportsDataProvider $provider, array $rawFixtures, array &$errors): array
    {
        if ($provider === null || !method_exists($provider, 'round')) return [];
        $roundIds = [];
        foreach ($rawFixtures as $raw) {
            $roundId = (string) ($raw['roundId'] ?? '');
            if ($roundId !== '') $roundIds[$roundId] = true;
        }
        $out = [];
        foreach (array_keys($roundIds) as $roundId) {
            $attempt = $this->providers->withFallbackIds('round', [$provider->id() => (string) $roundId], fn(SportsDataProvider $p, string $id) => $p->round($id));
            if (!$attempt['ok']) {
                $errors[] = 'round ' . $roundId . ' bulk odds fetch failed: ' . json_encode($attempt['failures']);
                continue;
            }
            foreach ((is_array($attempt['result'] ?? null) ? $attempt['result']['odds'] : []) ?? [] as $row) {
                if (is_array($row) && !empty($row['fixtureId'])) $out[(string) $row['fixtureId']][] = $row;
            }
        }
        return $out;
    }

    /** Market-aware quality context for the DataQualityEngine. */
    private function qualityContext(array $contextFields, array $oddsStage, float $reliability, array $markets, int $minQuality): array
    {
        $freshestAge = null;
        foreach ($oddsStage['rows'] as $row) {
            if (is_numeric($row['oddsAgeSeconds'] ?? null)) {
                $age = (int) $row['oddsAgeSeconds'];
                $freshestAge = $freshestAge === null ? $age : min($freshestAge, $age);
            }
        }
        return [
            'mandatoryFields' => DataQualityEngine::mandatoryFieldsForMarkets($markets),
            'availableFields' => $contextFields,
            'oddsAvailable' => true,
            'oddsFresh' => true,
            'oddsAgeSeconds' => $freshestAge,
            'maxOddsAgeSeconds' => $this->oddsFreshness->maxAge(),
            'providerReliability' => $reliability,
            'minDataQuality' => $minQuality,
        ];
    }

    /**
     * Break the calibration cold start (see the call site in runDaily).
     *
     * Bootstrap the identity calibration and auto-approve it as an audited
     * SYSTEM act — never a human act, and never a fitted calibration:
     *   • an existing APPROVED calibration (fitted or bootstrap) → no-op;
     *   • an existing PENDING identity bootstrap → reused, never duplicated;
     *   • a REJECTED identity bootstrap → an explicit operator veto: the
     *     engine does not resurrect it and stays blocked, honestly reported.
     * Once an operator fits and approves a real Platt calibration it is the
     * newest APPROVED row and the identity bootstrap retires itself.
     */
    private function ensureIdentityCalibration(array &$funnel): void
    {
        $actor = 'system:daily-ticket';
        $modelId = $this->repo->ensureModelVersion([
            'modelName' => PredictionEngine::MODEL_NAME,
            'modelVersion' => PredictionEngine::MODEL_VERSION,
            'featureVersion' => FeatureEngineeringEngine::VERSION,
        ]);
        if ($this->repo->activeCalibration($modelId) !== null) return;

        // Prefix recognition (CalibrationBootstrap::isIdentityMethod), never
        // exact equality: a legacy row may carry the old marker stored whole
        // or truncated to a narrow method column, and both are the same
        // bootstrap the engine must reuse and heal.
        $identityRows = function (string $status) use ($modelId): array {
            return array_values(array_filter(
                $this->repo->listCalibrations($modelId, $status, 50),
                fn(array $c): bool => CalibrationBootstrap::isIdentityMethod((string) ($c['method'] ?? ''))
            ));
        };

        $pending = $identityRows('PENDING');
        if ($pending === []) {
            if ($identityRows('REJECTED') !== []) {
                $funnel['calibrationBootstrap'] = 'REJECTED_BY_ADMIN'; // explicit operator veto — honoured
                return;
            }
            $result = (new CalibrationBootstrap($this->repo, $this->audit))->bootstrapIdentity($actor);
            if (empty($result['ok'])) {
                $funnel['calibrationBootstrap'] = (string) ($result['reason'] ?? 'UNAVAILABLE');
                if (!empty($result['dbError'])) $funnel['calibrationBootstrapError'] = (string) $result['dbError'];
                return;
            }
            $pending = $identityRows('PENDING');
            if ($pending === []) {
                $funnel['calibrationBootstrap'] = 'BOOTSTRAP_UNREADABLE';
                return;
            }
        }

        $id = (int) ($pending[0]['id'] ?? 0);
        if ($id === 0) {
            $funnel['calibrationBootstrap'] = 'BOOTSTRAP_UNREADABLE';
            return;
        }
        // The APPROVE write is verified on the SAME record it targeted
        // (requirement: insert/update round-trip verified by read-back). A
        // swallowed UPDATE failure used to leave a PENDING row while the
        // engine believed it was approved — the model then died on
        // MODEL_NOT_CALIBRATED for no visible reason. Surface the real
        // database error instead.
        try {
            $this->repo->updateCalibrationStatus($id, 'APPROVED', $actor);
        } catch (\Throwable $e) {
            $funnel['calibrationBootstrap'] = 'CALIBRATION_PERSIST_FAILED';
            $funnel['calibrationBootstrapError'] = mb_substr($e->getMessage(), 0, 500);
            return;
        }
        $approvedRow = $this->repo->findCalibration($id);
        $active = $this->repo->activeCalibration($modelId);
        if ($approvedRow === null
            || strtoupper((string) ($approvedRow['status'] ?? '')) !== 'APPROVED'
            || !CalibrationBootstrap::isIdentityMethod((string) ($approvedRow['method'] ?? ''))
            || $active === null
            || (int) ($active['id'] ?? 0) !== $id) {
            $funnel['calibrationBootstrap'] = 'APPROVE_PERSIST_FAILED';
            $funnel['calibrationBootstrapError'] = 'the APPROVED update did not survive the database round-trip on calibration id ' . $id
                . ' (stored status: ' . var_export($approvedRow['status'] ?? null, true)
                . ', active calibration id: ' . ($active['id'] ?? 'null') . ')';
            return;
        }
        $this->audit->emit(
            'SPORTS_CALIBRATION_AUTO_APPROVED',
            'Identity bootstrap calibration auto-approved by the daily ticket engine (intercept 0 / slope 1 — no-op baseline mapping; a fitted Platt calibration supersedes it once approved)',
            ['calibrationId' => $id, 'modelVersionId' => $modelId],
            $actor
        );
        $funnel['calibrationBootstrap'] = 'IDENTITY_AUTO_APPROVED';
        $funnel['calibrationId'] = $id;
    }

    /** Approved calibration for the candidate's model version, or null (never invented). */
    private function calibrationFor(array $matchRow): ?array
    {
        $model = ['modelName' => PredictionEngine::MODEL_NAME, 'modelVersion' => PredictionEngine::MODEL_VERSION, 'featureVersion' => FeatureEngineeringEngine::VERSION];
        $modelId = $this->repo->ensureModelVersion($model);
        $cal = $this->repo->activeCalibration($modelId);
        if ($cal === null) return null;
        $cal['calibrationVersion'] = $this->calibrationVersionLabel($cal);
        return $cal;
    }

    private function calibrationVersionLabel(array $cal): string
    {
        return sprintf('cal-platt-i%s-s%s-n%d', $cal['intercept'] ?? '?', $cal['slope'] ?? '?', (int) ($cal['samples'] ?? 0));
    }

    private function modelVersionIdFor(array $prediction): ?int
    {
        $model = ['modelName' => $prediction['modelName'] ?? PredictionEngine::MODEL_NAME, 'modelVersion' => $prediction['modelVersion'] ?? PredictionEngine::MODEL_VERSION, 'featureVersion' => $prediction['featureVersion'] ?? FeatureEngineeringEngine::VERSION, 'calibrationVersion' => $prediction['calibrationVersion'] ?? null];
        return $this->repo->ensureModelVersion($model);
    }
}
