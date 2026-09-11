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
 * Idempotent per (ticket type, configured-local date). Only a complete
 * persisted ticket is terminal; attempts and honest no-ticket outcomes remain
 * retryable with diagnostics and controlled backoff.
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

    /** Hard ceiling per prediction-generation batch (50-match product contract). */
    public const MAX_GENERATION_CEILING = 50;

    public const ENV_MAX_GENERATION = 'WINDELS_SPORTS_MAX_GENERATION';
    public const TICKET_TYPE = 'ODDS_PREDICTION';
    public const RUN_STALE_AFTER_SECONDS = 900;
    public const RETRY_BASE_SECONDS = 300;
    public const RETRY_MAX_SECONDS = 3600;

    /**
     * A fixture must kick off strictly more than this many seconds from the
     * runtime clock to be ticket-eligible. It is also the intake cut-off: a
     * fixture below it can NEVER pass the first gate, so it must never consume
     * a slot of the 50-fixture generation batch.
     */
    public const ELIGIBILITY_LEAD_SECONDS = 2 * 3600;

    /**
     * Every provider spelling of "this match has not kicked off yet".
     *
     * The eligibility gate used to demand the literal short code `NS`, so a
     * feed that says "Not Started", "TBD", "PENDING" or "DELAYED" had its
     * whole page rejected as FIXTURE_NOT_NS_OR_TOO_SOON even though the
     * canonical status stored on the same row said SCHEDULED and kickoff was
     * hours away. The list is an explicit ALLOWLIST on purpose: an
     * unrecognised code is never assumed to mean "not started".
     */
    public const NOT_STARTED_SOURCE_STATUSES = [
        'NS', 'NOT STARTED', 'NOT_STARTED', 'NOTSTARTED',
        'SCHEDULED', 'SCHED', 'TBA', 'TBD', 'PENDING', 'UPCOMING',
        'DELAYED', 'PRE', 'PREMATCH', 'PRE-MATCH', 'PRE_MATCH', 'FIXTURE', 'AU',
    ];

    /**
     * A bounded fixture-discovery read may be wider than the 50-fixture
     * prediction cap. Provider pages are often ordered by kickoff and, in an
     * afternoon run, their first 50 rows can all be already started or inside
     * the two-hour lead. Reading this small, bounded buffer lets the engine
     * find later NS fixtures; it never increases MAX_GENERATION_CEILING.
     */
    public const FIXTURE_DISCOVERY_CEILING = self::MAX_GENERATION_CEILING * 4;

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
        $config = $this->config->active();
        $timezone = DailyTicketDate::configuredTimezone((string) ($config['system_timezone'] ?? ''));
        $date = DailyTicketDate::normalize($date, $timezone);
        $window = DailyTicketDate::utcWindow($date, $timezone);
        $windowTo = (new \DateTimeImmutable($date . ' 00:00:00', new \DateTimeZone($timezone)))->modify('+1 day')->format('Y-m-d');

        // Idempotency is ticket-state based, never attempt based. This check is
        // deliberately first: even if somebody deletes/marks the job attempt
        // FAILED, the persisted ticket remains today's authoritative result.
        $existing = $this->existingTicketResult($date);
        if ($existing !== null) return $existing;
        $recovered = $this->recoverUnlinkedDailyTicket($date, $config, $timezone, $window);
        if ($recovered !== null) return $recovered;

        $dailyBeforeClaim = $this->repo->findDailyTicket($date);
        // Heal an impossible/legacy state: GENERATED without a readable ticket
        // (or without persisted legs) must be retried, never skipped forever.
        if (is_array($dailyBeforeClaim)
            && strtoupper((string) ($dailyBeforeClaim['generation_status'] ?? '')) === 'GENERATED') {
            $this->repo->updateDailyTicket($date, [
                'ticket_id' => null, 'generation_status' => 'FAILED',
                'last_error_code' => 'GENERATED_TICKET_MISSING',
                'message' => 'Generated state had no valid persisted ticket; generation will retry',
            ]);
            $dailyBeforeClaim = $this->repo->findDailyTicket($date);
        }

        // Scheduled workers obey controlled backoff. Manual/API requests are
        // an explicit retry and may proceed immediately after a failed attempt.
        if (!empty($options['scheduled']) && is_array($dailyBeforeClaim) && !empty($dailyBeforeClaim['next_retry_at'])) {
            $retryAt = strtotime((string) $dailyBeforeClaim['next_retry_at']);
            if ($retryAt !== false && $retryAt > time()) {
                return [
                    'status' => 'RETRY_SCHEDULED', 'generationStatus' => (string) ($dailyBeforeClaim['generation_status'] ?? 'RETRYING'),
                    'ticketId' => null, 'date' => $date, 'nextRetryAt' => gmdate('c', $retryAt),
                    'message' => 'Daily ticket retry is scheduled for ' . gmdate('c', $retryAt), 'errors' => [],
                ];
            }
        }

        // Force/reset applies only when no valid ticket exists. A valid daily
        // ticket is always returned above: force is not a loophole for creating
        // a duplicate date/type ticket.
        $invalidated = null;
        if (!empty($options['force'])) {
            // A force request is explicitly a fresh provider cycle, not merely
            // a reset followed by reusing the very rows it just invalidated.
            $options['refreshFixtures'] = true;
            try {
                $invalidated = $this->repo->invalidateActiveCandidates($date, $windowTo, true);
                $this->audit->emit('SPORTS_CANDIDATES_INVALIDATED',
                    'Active odds-prediction candidates for ' . $date . '..' . $windowTo . ' invalidated before a forced fresh generation (settled/historical records preserved)',
                    ['from' => $date, 'to' => $windowTo, 'invalidated' => $invalidated]);
            } catch (\Throwable $e) {
                // Never silently half-reset: surface the failure and stop.
                return ['status' => 'RESET_FAILED', 'generationStatus' => 'FAILED', 'date' => $date, 'message' => 'candidate reset failed: ' . mb_substr($e->getMessage(), 0, 300), 'invalidated' => null];
            }
        }

        $runId = Backtester::uuid();
        // Job rows are attempt telemetry, not the idempotency authority. Create
        // the parent row before claiming the daily slot: production dumps may
        // retain fk_sports_daily_run(run_id), and a child row cannot reference
        // a job that is created afterwards.
        $attemptCount = max(1, (int) ($dailyBeforeClaim['attempt_count'] ?? 0) + 1);
        // When a complete 50-match page honestly produced no ticket, the next
        // retry advances to the next stored/provider page. Transient failures
        // and partial pages retry the same fixtures. One exception matters for
        // an upgrade/recovery: an all-timing page (0 eligible, every row
        // FIXTURE_NOT_NS_OR_TOO_SOON) must be replayed once through the newer
        // NS-filtered 200-row discovery buffer. Advancing it first would skip
        // the only page on providers whose day endpoint does not paginate —
        // exactly the reported "50 evaluated → 0 eligible" dead end.
        $priorSummary = is_array($dailyBeforeClaim['rejection_summary'] ?? null) ? $dailyBeforeClaim['rejection_summary'] : [];
        $priorDiagnostics = is_array($priorSummary['_diagnostics'] ?? null) ? $priorSummary['_diagnostics'] : [];
        $replayTimingOnlyPage = (int) ($priorDiagnostics['eligibleFixtures'] ?? 0) === 0
            && (int) ($priorSummary['FIXTURE_NOT_NS_OR_TOO_SOON'] ?? 0) > 0;
        $options['batchOffset'] = ((string) ($dailyBeforeClaim['status'] ?? '') === 'NO_QUALIFIED_TICKET'
            && !empty($priorDiagnostics['fixturePageFull']) && !$replayTimingOnlyPage)
            ? max(0, (int) ($priorDiagnostics['batchOffset'] ?? 0) + self::MAX_GENERATION_CEILING)
            : 0;
        $baseKey = $executionKey ?? ('daily-ticket:' . self::TICKET_TYPE . ':' . $date . ':v' . $config['version']);
        $key = mb_substr($baseKey, 0, 135) . ':attempt:' . $attemptCount;
        $run = $this->repo->startJobRun(['id' => $runId, 'jobType' => 'DAILY_TICKET', 'executionKey' => $key]);
        if ($run === null) {
            // A manually supplied key may collide with an old attempt number;
            // use a unique telemetry key while retaining the daily claim.
            $key = mb_substr($baseKey, 0, 105) . ':retry:' . gmdate('YmdHis') . ':' . substr($runId, 0, 12);
            $run = $this->repo->startJobRun(['id' => $runId, 'jobType' => 'DAILY_TICKET', 'executionKey' => $key]);
        }
        if ($run === null) {
            $nextRetryAt = gmdate('c', time() + self::RETRY_BASE_SECONDS);
            if ($dailyBeforeClaim !== null) {
                $this->repo->updateDailyTicket($date, ['generation_status' => 'RETRYING', 'next_retry_at' => $nextRetryAt, 'last_error_code' => 'JOB_CLAIM_CONFLICT']);
            }
            return ['status' => 'GENERATION_IN_PROGRESS', 'generationStatus' => 'RETRYING', 'ticketId' => null, 'date' => $date, 'nextRetryAt' => $nextRetryAt, 'message' => 'Generation telemetry is already being recorded; retry scheduled', 'errors' => []];
        }

        $claim = $this->repo->claimDailyTicketGeneration(
            $date, self::TICKET_TYPE, $runId, (int) $config['version'], $timezone,
            $window['start'], $window['endExclusive'], self::RUN_STALE_AFTER_SECONDS
        );
        if (empty($claim['claimed'])) {
            // A worker may have completed between our initial read and claim.
            // The telemetry parent was created first so this path is also valid
            // on installations that enforce the daily-row foreign keys.
            $this->repo->finishJobRun($runId, ['status' => 'SKIPPED', 'processed' => 0, 'created' => 0, 'updated' => 0, 'errors' => ['daily generation claim not acquired']]);
            try { $this->repo->releaseJobRun($runId); } catch (\Throwable $e) { /* telemetry cleanup is best effort */ }
            $winner = $this->existingTicketResult($date);
            if ($winner !== null) return $winner;
            return [
                'status' => 'GENERATION_IN_PROGRESS', 'generationStatus' => 'RUNNING',
                'ticketId' => null, 'date' => $date,
                'message' => 'Another worker is generating this daily ticket', 'invalidated' => $invalidated, 'errors' => [],
            ];
        }
        $attemptCount = max(1, (int) (($claim['row']['attempt_count'] ?? $attemptCount)));
        $this->healthCache = [];
        $this->providerCodes = [];
        // Resolved ONCE with the same default the per-fixture gate applies.
        $requireCalibration = (int) (bool) ($config['require_calibration'] ?? 1);
        $config['require_calibration'] = $requireCalibration;

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
        $dataState = 'OK';        // OK | DATA_UNAVAILABLE | NO_PROVIDER | DISABLED | ERROR
        $unexpectedFailure = null;
        $funnel = $this->emptyFunnel();
        $funnel['systemTimezone'] = $timezone;
        $funnel['windowStartUtc'] = $window['start'];
        $funnel['windowEndUtc'] = $window['endExclusive'];
        $funnel['attempt'] = $attemptCount;
        $funnel['batchOffset'] = (int) ($options['batchOffset'] ?? 0);

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
            } else {
                // Stored fixtures and their persisted provider identity are a
                // valid input even when a live provider is temporarily absent.
                // A provider is only required when storage has no eligible rows.
                // All-provider intake: every registered feed is asked once (one
                // health probe per provider per run, the circuit breaker
                // honoured for each), and every answer is kept. A single
                // provider behaves exactly as before; with several, the same
                // real match arriving under several ids is merged below and
                // evaluated once through its most complete row.
                $sources = $this->fetchFixtureSources($date, $timezone, $errors, $options);
                $funnel['fixtureInput'] = (string) ($sources['input'] ?? 'PROVIDER');
                $funnel['fixturePageFull'] = !empty($sources['pageFull']);
                $funnel['batchOffset'] = (int) ($sources['batchOffset'] ?? $options['batchOffset'] ?? 0);
                if (!$sources['ok']) {
                    $funnel['providersConfigured'] = count($this->providers->all());
                    if (!$this->providers->configured()) {
                        $message = 'NO VALUE TICKET TODAY — no stored eligible fixtures and no sports provider configured (DISABLED_NO_PROVIDER); nothing is fabricated';
                        $dataState = 'NO_PROVIDER';
                    } else {
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
                    }
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
                    // One generation worker never walks more than 50 canonical
                    // matches. Prefer earliest kickoff deterministically, then
                    // provider arrival order. Remaining rows are explicitly
                    // deferred instead of triggering hundreds of odds calls.
                    // Ticket-ELIGIBLE fixtures are ordered first: a provider
                    // page is mostly matches that already kicked off or start
                    // inside the eligibility lead, and letting them consume the
                    // 50-fixture batch is what produced "50 evaluated → 0
                    // eligible" days. Within each group, earliest kickoff wins
                    // deterministically, then provider arrival order.
                    usort($primaries, static function (array $a, array $b): int {
                        $eligible = ((int) !empty($b['eligible'])) <=> ((int) !empty($a['eligible']));
                        if ($eligible !== 0) return $eligible;
                        $kickoff = strcmp((string) ($a['probe']['kickoff'] ?? ''), (string) ($b['probe']['kickoff'] ?? ''));
                        return $kickoff !== 0 ? $kickoff : ($a['order'] <=> $b['order']);
                    });
                    if (count($primaries) > self::MAX_GENERATION_CEILING) {
                        $funnel['fixtureBatchDeferred'] = count($primaries) - self::MAX_GENERATION_CEILING;
                        $primaries = array_slice($primaries, 0, self::MAX_GENERATION_CEILING);
                    }

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
                        // Stored context remains usable without a live provider;
                        // unresolved form simply fails the normal sufficiency
                        // gate rather than causing a nullable-provider crash.
                        if (!isset($instanceByCode[$code]) || !$instanceByCode[$code] instanceof SportsDataProvider) {
                            $formStats['providerCapable'] = false;
                            continue;
                        }
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
                    // The adaptive confidence ladder for this run, resolved
                    // once from configuration and used by every gate below so
                    // the same policy governs screening, the pipeline, the
                    // optimizer and the reported funnel.
                    $confidencePolicy = ConfidencePolicy::fromConfiguration($config);
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
                        // The fixture-level floor is the lowest band the
                        // ADAPTIVE policy still accepts, not the top tier's
                        // requirement: a fixture with quality 78 is a GOOD-tier
                        // candidate, and screening it out here would be exactly
                        // the fixed-threshold bug requirement #1 removes.
                        $adaptiveFloor = $confidencePolicy->minimumDataQuality();
                        $qualityAssessment = $this->quality->assess($match, $this->qualityContext($contextFields, $oddsStage, $reliability, $markets, $adaptiveFloor));
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
                        // Requirement #3/#7: the gate is PER MARKET, not per
                        // fixture. A fixture is only rejected INSUFFICIENT_DATA
                        // when NO supported market it has odds for can be
                        // computed from the data that actually exists. If even
                        // one market has all of its own mandatory inputs, that
                        // market is evaluated and the fixture survives — a
                        // missing OPTIONAL feed never rejects anything, it
                        // only lowers the data-quality score.
                        $marketFeasibility = $this->marketFeasibility($matchRow, $contextFields, $markets);
                        $usableMarkets = array_keys(array_filter($marketFeasibility, fn(array $m): bool => $m['ok']));
                        $missingMandatory = $usableMarkets === []
                            ? array_values(array_unique(array_merge(...array_values(array_map(fn(array $m): array => $m['missing'], $marketFeasibility)) ?: [[]])))
                            : [];
                        $mandatoryOk = $usableMarkets !== [];
                        // Only the odds of markets the model can actually
                        // compute stay in play (requirement #4: one market's
                        // missing inputs never eliminates the others).
                        if ($mandatoryOk) {
                            $usableOdds = array_values(array_filter($usableOdds, fn(array $r): bool => in_array(strtoupper((string) $r['market']), $usableMarkets, true)));
                        }
                        $calibrationOk = !$requireCalibration || $calibration !== null;
                        $qualityOk = ((int) ($qualityAssessment['score'] ?? 0) >= $adaptiveFloor && !empty($qualityAssessment['eligibleForTicket']));
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
                                'MANDATORY_MODEL_DATA' => ['ok' => $mandatoryOk, 'missingMandatory' => $missingMandatory, 'mandatoryFields' => array_values((array) ($qualityAssessment['mandatoryFields'] ?? ['recentForm'])), 'marketFeasibility' => $marketFeasibility, 'usableMarkets' => $usableMarkets],
                                'APPROVED_CALIBRATION' => ['ok' => $calibrationOk, 'required' => (bool) $requireCalibration, 'calibrationId' => $calibration['id'] ?? null, 'method' => $calibration['method'] ?? null, 'bootstrapState' => $funnel['calibrationBootstrap'] ?? null],
                                // Requirement #13: the score AND the minimum it
                                // was judged against, plus the adaptive tier
                                // that minimum came from.
                                'DATA_QUALITY_FLOOR' => ['ok' => $qualityOk, 'score' => (int) ($qualityAssessment['score'] ?? 0), 'minScore' => (int) $adaptiveFloor, 'configuredMinScore' => (int) $minQuality, 'tier' => (string) ($confidencePolicy->tierFor((int) ($qualityAssessment['score'] ?? 0))['tier'] ?? ConfidencePolicy::TIER_REJECT), 'requiredConfidence' => $confidencePolicy->requiredConfidence((int) ($qualityAssessment['score'] ?? 0)), 'band' => (string) ($qualityAssessment['band'] ?? 'UNKNOWN'), 'eligibleForTicket' => (bool) ($qualityAssessment['eligibleForTicket'] ?? false)],
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

                            $this->collectCandidate($candidate, $candidates, $rejections, $rejectionSummary, $reasonProviders, (string) ($odds['oddsSource'] ?? $item['provider']), $funnel);
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
                            $this->collectCandidate($candidate, $candidates, $rejections, $rejectionSummary, $reasonProviders, (string) ($odds['oddsSource'] ?? $item['provider']), $funnel);
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
                    $funnel['fixturesDeferred'] = $fixturesDeferred + (int) ($funnel['fixtureBatchDeferred'] ?? 0);

                    // ── Stage 11: correlation → final ticket ────────────────
                    if (count($candidates) > 0) {
                        $optimized = $this->optimizer->optimize($candidates, [
                            'targetOddsMin' => (float) $config['target_odds_min'],
                            'targetOddsMax' => (float) $config['target_odds_max'],
                            'maxSelections' => (int) $config['max_selections'],
                            // ADAPTIVE, not fixed: each candidate is judged
                            // against the requirement its own data quality
                            // earned (the policy travels with the pool), so a
                            // legitimate 71% on good data is a preferred leg
                            // instead of a fallback-only one.
                            'confidencePolicy' => $confidencePolicy,
                            'minConfidence' => $confidencePolicy->highestConfidenceRequirement(),
                            'minDataQuality' => $confidencePolicy->minimumDataQuality(),
                            'maxCorrelation' => $config['max_correlation'],
                            'allowedMarkets' => $config['allowed_markets'],
                            'allowedLeagues' => $config['allowed_leagues'],
                            // Requirement #1/#10: one missed threshold must
                            // never cost the whole day. When nothing clears
                            // every preferred criterion the optimizer ranks
                            // the real candidates and takes the strongest
                            // non-correlated combination inside the configured
                            // odds range — declared as fallback, never faked.
                            'allowFallback' => true,
                        ]);
                        $funnel['correlationQualifiedCandidates'] = (int) ($optimized['poolSize'] ?? 0);
                        // Requirement #14: the per-candidate decision trace
                        // (fixture → market → model probability → confidence →
                        // data quality → odds → value → risk → correlation →
                        // final decision) and the tier the engine settled on.
                        $funnel['candidateDecisions'] = array_slice((array) ($optimized['candidateDecisions'] ?? []), 0, 100);
                        $funnel['selectionAttempts'] = (array) ($optimized['attempts'] ?? []);
                        $funnel['selectionTier'] = $optimized['selectionTier'] ?? null;
                        $funnel['fallbackUsed'] = (bool) ($optimized['fallbackUsed'] ?? false);
                        $funnel['fallbackReason'] = $optimized['fallbackReason'] ?? null;
                        $funnel['eligiblePoolSize'] = (int) ($optimized['eligiblePoolSize'] ?? 0);
                        $funnel['preferredPoolSize'] = (int) ($optimized['preferredPoolSize'] ?? 0);
                        if ($optimized['status'] === 'QUALIFIED') {
                            // A deterministic daily ID makes a crash after the
                            // ticket/legs write but before linking the daily row
                            // recoverable. It also lets a retry safely finish a
                            // partial write without violating the daily FK.
                            $optimized['ticketId'] = $this->dailyTicketId($date);
                            $ticketId = $optimized['ticketId'];
                            $rec = $this->governance->record($optimized, (string) $config['version'], $modelVersionId, $config);
                            if (($rec['status'] ?? '') !== 'NO_QUALIFIED_TICKET') {
                                $status = $rec['status'] === 'APPROVED_NOT_EXECUTED' ? 'APPROVED' : 'PENDING_USER_APPROVAL';
                                $ticketId = $rec['ticketId'];
                                $funnel['finalQualifiedCandidates'] = (int) ($optimized['selectionCount'] ?? 0);
                                $message = $status === 'APPROVED' ? 'ticket generated and auto-approved (AUTOMATED_EXECUTION); no external execution' : 'odds prediction ticket generated; awaiting user approval';
                                // Fallback mode is always declared: a ticket
                                // that did not clear every preferred criterion
                                // must never read like one that did.
                                if (!empty($optimized['fallbackUsed'])) {
                                    $message .= ' — ' . (string) $optimized['fallbackReason'];
                                }
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
                            // with the optimizer's reason kept as the diagnosis
                            // AND the exact per-tier failure, so "0 final" after
                            // "4 risk-qualified" can never be a black box again.
                            $message = 'NO QUALIFIED TICKET — ' . "Today's available matches did not meet the configured prediction requirements"
                                . ' (' . ($optimized['reason'] ?? 'no compliant combination') . ')';
                            $tierNotes = [];
                            foreach ((array) ($optimized['attempts'] ?? []) as $attempt) {
                                if (!is_array($attempt) || !empty($attempt['found'])) continue;
                                $tierNotes[] = sprintf('%s [%d candidate(s), correlation cap %s]: %s',
                                    (string) ($attempt['tier'] ?? '?'), (int) ($attempt['poolSize'] ?? 0),
                                    (string) ($attempt['correlationCap'] ?? '?'), (string) ($attempt['reason'] ?? 'no combination'));
                            }
                            if ($tierNotes) $message .= ' — selection tiers tried: ' . implode('; ', $tierNotes);
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
                        // Targeted diagnosis of the most common upstream dead
                        // ends, so the message says what to FIX, not only what
                        // failed.
                        //
                        // Every fixture the day offered had already kicked off
                        // or starts inside the eligibility lead: that is a
                        // TIMING dead end, not a modelling one, and the message
                        // must say so instead of blaming confidence/value gates
                        // that never ran.
                        $tooSoon = (int) ($rejectionSummary['FIXTURE_NOT_NS_OR_TOO_SOON'] ?? 0);
                        if ((int) ($funnel['eligibleFixtures'] ?? 0) === 0 && $tooSoon > 0 && $evaluated > 0) {
                            $leadHours = (int) round(self::ELIGIBILITY_LEAD_SECONDS / 3600);
                            $message = 'NO QUALIFIED TICKET — none of the ' . $evaluated . ' fixture(s) read for ' . $date
                                . ' was still ticket-eligible: all ' . $tooSoon . ' had already started, were not in the NS/SCHEDULED state, or kick off within the '
                                . $leadHours . '-hour lead the engine requires. No prediction gate (confidence, quality, value, risk, correlation) was reached'
                                . (!empty($funnel['fixturePageFull'])
                                    ? '; a further page of fixtures is available and the next run advances to it'
                                    : '; run again when later fixtures for the window are published');
                        }
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
                    $funnel['confidencePolicy'] = $confidencePolicy->toArray();
                    $funnel['thresholds'] = [
                        // The top tier's requirement — the bar at EXCELLENT
                        // evidence. Candidates on thinner data are held to the
                        // tier their quality earned (confidencePolicy above),
                        // never to one fixed figure.
                        'minConfidence' => $confidencePolicy->highestConfidenceRequirement(),
                        'confidencePolicy' => $confidencePolicy->toArray(),
                        'configuredMinConfidence' => $minConfidence,
                        'minDataQuality' => $confidencePolicy->minimumDataQuality(),
                        'configuredMinDataQuality' => $minQuality,
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
            // An exception is a failed generation attempt, never an honest
            // NO_QUALIFIED_TICKET verdict. It remains retryable and visible.
            $unexpectedFailure = $e;
            $status = 'FAILED';
            $dataState = 'ERROR';
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

        // GENERATED is earned only after both the ticket and its persisted legs
        // can be read back. A swallowed/partial database write is a retryable
        // failure, never a successful daily state.
        $generatedAt = null;
        if ($ticketId !== null) {
            $persisted = $this->repo->findTicket((string) $ticketId);
            $persistedSelections = $persisted !== null ? $this->repo->ticketSelections((string) $ticketId) : [];
            if ($persisted === null || $persistedSelections === []) {
                $errors[] = 'ticket persistence verification failed for ' . $ticketId;
                $unexpectedFailure = new \RuntimeException('generated ticket did not survive the database round-trip');
                $status = 'FAILED';
                $dataState = 'ERROR';
                $message = 'ticket persistence verification failed; retry scheduled';
                $ticketId = null;
            } else {
                $generatedAt = gmdate('c');
            }
        }

        $generationStatus = $ticketId !== null
            ? 'GENERATED'
            : (in_array($dataState, ['DATA_UNAVAILABLE', 'ERROR'], true) ? 'RETRYING'
                : (in_array($dataState, ['DISABLED', 'NO_PROVIDER'], true) ? 'FAILED' : 'PENDING'));
        $errorCode = $ticketId !== null ? null : $this->failureCode($dataState, $status, $message, $providerStatuses);
        $nextRetryAt = null;
        if (in_array($generationStatus, ['RETRYING', 'PENDING'], true)) {
            $delay = min(self::RETRY_MAX_SECONDS, self::RETRY_BASE_SECONDS * (2 ** min(4, max(0, $attemptCount - 1))));
            // A genuine no-qualified verdict is not hammered every five minutes;
            // it remains retryable but waits at least one normal sports sweep.
            if ($status === 'NO_QUALIFIED_TICKET') $delay = max(900, $delay);
            $nextRetryAt = gmdate('c', time() + $delay);
        }

        // The rejection summary doubles as the provider-failure ledger on a
        // DATA_UNAVAILABLE day. Diagnostics are persisted with the daily row so
        // a page refresh renders the completed funnel without rerunning it.
        $storedSummary = $rejectionSummary;
        if ($dataState === 'DATA_UNAVAILABLE') {
            foreach ($providerStatuses as $pid => $st) $storedSummary['PROVIDER:' . $pid] = $st;
        }
        $storedSummary['_diagnostics'] = $diagnostics;
        $this->repo->saveDailyTicket([
            'date' => $date, 'ticket_type' => self::TICKET_TYPE,
            'ticket_id' => $ticketId, 'status' => $status, 'generation_status' => $generationStatus,
            'configuration_version' => (int) $config['version'],
            'candidates_evaluated' => $evaluated, 'predictions_recorded' => $recorded,
            'rejections' => $rejections, 'rejection_summary' => json_encode($storedSummary),
            'message' => mb_substr($message, 0, 500), 'provider' => $provider, 'run_id' => $runId,
            'attempt_count' => $attemptCount, 'next_retry_at' => $nextRetryAt,
            'last_error_code' => $errorCode, 'generated_at' => $generatedAt,
            'system_timezone' => $timezone, 'window_start_utc' => $window['start'], 'window_end_utc' => $window['endExclusive'],
            'created_at' => gmdate('c'), 'updated_at' => gmdate('c'),
        ]);

        // Attempt records are telemetry only. No-ticket attempts are FAILED and
        // their execution keys are released; only the verified ticket above is
        // a completed daily generation.
        $runStatus = $ticketId !== null ? 'COMPLETED' : 'FAILED';
        $this->repo->finishJobRun($runId, ['status' => $runStatus, 'processed' => $evaluated, 'created' => $recorded, 'updated' => $ticketId !== null ? 1 : 0, 'errors' => $errors]);
        if ($ticketId === null) {
            try { $this->repo->releaseJobRun($runId); } catch (\Throwable $e) { $errors[] = 'job release failed: ' . mb_substr($e->getMessage(), 0, 120); }
        }
        $this->audit->emit($ticketId === null ? 'SPORTS_DAILY_TICKET_BLOCKED' : 'SPORTS_DAILY_TICKET_RUN', 'Daily ticket run ' . $date . ' → ' . $generationStatus . ' / ' . $status, [
            'date' => $date, 'ticketType' => self::TICKET_TYPE, 'status' => $status, 'generationStatus' => $generationStatus,
            'dataState' => $dataState, 'ticketId' => $ticketId, 'evaluated' => $evaluated,
            'rejections' => $rejections, 'rejectionSummary' => $rejectionSummary, 'diagnostics' => $diagnostics,
            'message' => $message, 'provider' => $provider, 'attempt' => $attemptCount,
            'nextRetryAt' => $nextRetryAt, 'errorCode' => $errorCode,
            'providerFailures' => $providerFailures, 'providerStatuses' => $providerStatuses, 'errors' => $errors,
        ]);
        return [
            'status' => $status, 'generationStatus' => $generationStatus, 'outcomeStatus' => $status,
            'dataState' => $dataState, 'ticketId' => $ticketId, 'date' => $date, 'ticketType' => self::TICKET_TYPE,
            'timezone' => $timezone, 'message' => $message, 'generatedAt' => $generatedAt,
            'nextRetryAt' => $nextRetryAt, 'errorCode' => $errorCode, 'attempt' => $attemptCount,
            'evaluated' => $evaluated, 'predictionsRecorded' => $recorded, 'rejections' => $rejections, 'rejectionSummary' => $rejectionSummary,
            'diagnostics' => $diagnostics, 'invalidated' => $invalidated,
            'provider' => $provider, 'providerFailures' => $providerFailures, 'providerStatuses' => $providerStatuses,
            'runId' => $runId, 'executionKey' => $key, 'errors' => $errors,
        ];
    }

    /** Stable UUID-shaped identity for one ticket type + configured-local date. */
    private function dailyTicketId(string $date): string
    {
        $hex = hash('sha256', self::TICKET_TYPE . '|' . $date);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-5' . substr($hex, 13, 3)
            . '-a' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
    }

    /**
     * Recover a ticket committed immediately before a worker crash. The daily
     * row may still be RUNNING/FAILED or may not yet reference the ticket, but
     * deterministic identity plus persisted legs proves the successful write.
     */
    private function recoverUnlinkedDailyTicket(string $date, array $config, string $timezone, array $window): ?array
    {
        $ticketId = $this->dailyTicketId($date);
        $ticket = $this->repo->findTicket($ticketId);
        if ($ticket === null || $this->repo->ticketSelections($ticketId) === []) return null;
        if (in_array(strtoupper((string) ($ticket['status'] ?? '')), ['CANCELLED'], true)
            || in_array(strtoupper((string) ($ticket['approval_status'] ?? '')), ['SUPERSEDED'], true)) return null;
        $generatedAt = (string) ($ticket['created_at'] ?? gmdate('c'));
        $this->repo->saveDailyTicket([
            'date' => $date, 'ticket_type' => self::TICKET_TYPE, 'ticket_id' => $ticketId,
            'status' => (string) ($ticket['approval_status'] ?? $ticket['status'] ?? 'GENERATED'),
            'generation_status' => 'GENERATED', 'configuration_version' => (int) ($config['version'] ?? 0),
            'candidates_evaluated' => 0, 'predictions_recorded' => 0, 'rejections' => 0,
            // MUST be an encoded scalar: the column is TEXT and an array here
            // stringifies to the literal 'Array' in the generated SQL (MySQL
            // 1054 "Unknown column 'Array'").
            'rejection_summary' => json_encode(['_diagnostics' => ['recoveredAfterInterruptedLink' => true]]),
            'message' => 'Recovered persisted daily ticket after interrupted finalization',
            'provider' => null, 'run_id' => null, 'attempt_count' => 0,
            'next_retry_at' => null, 'last_error_code' => null, 'generated_at' => $generatedAt,
            'system_timezone' => $timezone, 'window_start_utc' => $window['start'],
            'window_end_utc' => $window['endExclusive'], 'created_at' => $generatedAt, 'updated_at' => gmdate('c'),
        ]);
        return $this->existingTicketResult($date);
    }

    /**
     * Return the persisted daily ticket when it is complete and still valid.
     * The job-attempt status is intentionally ignored. This is the sole normal
     * idempotency terminal: a real ticket row plus at least one persisted leg.
     */
    private function existingTicketResult(string $date): ?array
    {
        $daily = $this->repo->findDailyTicket($date);
        $ticketId = is_array($daily) ? trim((string) ($daily['ticket_id'] ?? '')) : '';
        if ($ticketId === '') return null;
        $ticket = $this->repo->findTicket($ticketId);
        if ($ticket === null
            || strtoupper((string) ($ticket['status'] ?? '')) === 'CANCELLED'
            || strtoupper((string) ($ticket['approval_status'] ?? '')) === 'SUPERSEDED'
            || strtoupper((string) ($ticket['settlement_status'] ?? '')) === 'SUPERSEDED') return null;
        $selections = $this->repo->ticketSelections($ticketId);
        if ($selections === []) return null;

        $generatedAt = (string) ($daily['generated_at'] ?? $ticket['created_at'] ?? gmdate('c'));
        if (strtoupper((string) ($daily['generation_status'] ?? '')) !== 'GENERATED') {
            // A failed/deleted attempt must never hide a ticket that did persist.
            $this->repo->updateDailyTicket($date, [
                'generation_status' => 'GENERATED', 'next_retry_at' => null,
                'last_error_code' => null, 'generated_at' => $generatedAt,
                'message' => 'Existing persisted daily ticket returned',
            ]);
        }
        $summary = is_array($daily['rejection_summary'] ?? null) ? $daily['rejection_summary'] : [];
        return [
            'status' => 'GENERATED', 'generationStatus' => 'GENERATED',
            'outcomeStatus' => (string) ($daily['status'] ?? $ticket['approval_status'] ?? 'GENERATED'),
            'existing' => true, 'ticketId' => $ticketId, 'ticket' => $ticket,
            'selections' => $selections, 'date' => $date, 'ticketType' => self::TICKET_TYPE,
            'generatedAt' => $generatedAt, 'nextRetryAt' => null, 'errorCode' => null,
            'evaluated' => (int) ($daily['candidates_evaluated'] ?? 0),
            'predictionsRecorded' => (int) ($daily['predictions_recorded'] ?? 0),
            'rejections' => (int) ($daily['rejections'] ?? 0),
            'diagnostics' => (array) ($summary['_diagnostics'] ?? []),
            'message' => 'Existing persisted daily ticket returned; no duplicate was created',
            'runId' => $daily['run_id'] ?? null, 'provider' => $daily['provider'] ?? null,
            'providerStatuses' => [], 'errors' => [],
        ];
    }

    /** Stable, dashboard-safe reason code for retry diagnostics. */
    private function failureCode(string $dataState, string $status, string $message, array $providerStatuses): string
    {
        $upper = strtoupper($message);
        if (str_contains($upper, 'TIMEOUT') || str_contains($upper, 'TIMED OUT')) {
            return str_contains($upper, 'ODDS') ? 'ODDS_PROVIDER_TIMEOUT' : 'PROVIDER_TIMEOUT';
        }
        foreach ($providerStatuses as $providerStatus) {
            $providerStatus = strtoupper((string) $providerStatus);
            if (str_contains($providerStatus, 'TIMEOUT')) return 'PROVIDER_TIMEOUT';
            if (in_array($providerStatus, ['RATE_LIMITED', 'DAILY_QUOTA_EXHAUSTED'], true)) return 'PROVIDER_' . $providerStatus;
        }
        if ($dataState === 'DATA_UNAVAILABLE') return 'SPORTS_PROVIDER_UNAVAILABLE';
        if ($dataState === 'NO_PROVIDER') return 'NO_PROVIDER_CONFIGURED';
        if ($dataState === 'DISABLED') return 'ODDS_PREDICTION_DISABLED';
        if ($dataState === 'ERROR') return 'GENERATION_FAILED';
        if ($status === 'NO_QUALIFIED_TICKET') return 'NO_QUALIFIED_TICKET';
        return preg_replace('/[^A-Z0-9_]+/', '_', strtoupper($status)) ?: 'GENERATION_FAILED';
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

    /**
     * Route one evaluated candidate into the ticket pool or the rejection
     * ledger (requirements #1, #6 and #9).
     *
     * THE BUG THIS FIXES. The pool used to take only candidates whose every
     * pipeline stage PASSED, which meant a leg that missed the confidence
     * floor by a fraction was thrown away here — before the optimizer ever
     * saw it. That is precisely how a day could report
     *
     *     7 predictions -> 0 confidence-qualified -> 4 positive-value
     *                   -> 4 risk-qualified -> 0 final
     *
     * The four value- and risk-qualified candidates were REAL, but they had
     * already been discarded at this line for a soft reason, so the final
     * selection stage had an empty pool and could only answer
     * NO_QUALIFIED_TICKET.
     *
     * A SOFT reason (confidence or data quality below a configured floor) is
     * a RANKING signal — it belongs to the optimizer, which applies the
     * preferred criteria first and only then falls back, declaring it. A HARD
     * reason (no prediction, no usable odds, no positive value, rejected
     * risk, unsupported/disallowed market) means the candidate can never be a
     * ticket leg and is counted as a rejection here, exactly as before.
     *
     * Every candidate is still counted exactly once, under one primary reason.
     */
    private const SOFT_REJECTION_REASONS = ['LOW_CONFIDENCE', 'CONFIDENCE_UNMEASURED', 'LOW_DATA_QUALITY'];

    private function collectCandidate(array $candidate, array &$candidates, int &$rejections, array &$rejectionSummary, array &$reasonProviders, string $provider, ?array &$funnel = null): void
    {
        if (($candidate['decision'] ?? '') !== 'REJECTED') {
            $candidates[] = $candidate;
            return;
        }
        $reasons = array_values((array) ($candidate['rejectionReasons'] ?? []));
        $hard = array_values(array_diff($reasons, self::SOFT_REJECTION_REASONS));
        if ($hard === []) {
            // Soft-only: a real prediction with a real price that simply did
            // not clear a preferred floor. It stays a ranked candidate — the
            // optimizer decides, and says so when it uses the fallback.
            $candidate['softRejectionReasons'] = $reasons;
            $candidates[] = $candidate;
            return;
        }
        $rejections++;
        $primary = in_array((string) ($candidate['primaryReason'] ?? ''), $hard, true)
            ? (string) $candidate['primaryReason']
            : $hard[0];
        $this->countRejection($rejectionSummary, $primary, $reasonProviders, $provider);
        if ($funnel !== null) $this->recordRejectionAudit($funnel, $candidate, $primary, $provider);
    }

    /**
     * One auditable row per hard-rejected candidate (requirement #13):
     * Reason, Missing, Available, Data Quality and the minimum allowed.
     * Rows are capped so a 200-fixture day cannot bloat the stored
     * diagnostics; the COUNT in topRejectionReasons is never capped.
     */
    private function recordRejectionAudit(array &$funnel, array $candidate, string $primary, string $provider): void
    {
        $ledger = &$funnel['rejectionAudit'];
        if (count($ledger['rows']) >= (int) $ledger['limit']) { $ledger['truncated'] = true; unset($ledger); return; }
        $detail = is_array($candidate['rejectionDetail'] ?? null) ? $candidate['rejectionDetail'] : [];
        $ledger['rows'][] = [
            'fixture' => trim((string) ($candidate['match']['homeTeam'] ?? '?') . ' vs ' . (string) ($candidate['match']['awayTeam'] ?? '?')),
            'matchId' => $candidate['matchId'] ?? null,
            'competition' => $candidate['match']['competition'] ?? null,
            'kickoff' => $candidate['match']['kickoff'] ?? null,
            'market' => $candidate['market'] ?? null,
            'selection' => $candidate['selection'] ?? null,
            'provider' => $provider,
            'reason' => $primary,
            'allReasons' => array_values((array) ($detail['allReasons'] ?? [])),
            // The two lists an operator needs side by side to tell "thin data"
            // from "no data" — capped per row, never summarised away.
            'missing' => array_slice(array_values((array) ($detail['missingFields'] ?? [])), 0, 20),
            'available' => array_slice(array_values((array) ($detail['availableFields'] ?? [])), 0, 20),
            'dataQuality' => $detail['dataQuality'] ?? null,
            'minDataQuality' => $detail['minDataQuality'] ?? null,
            'confidence' => $detail['confidence'] ?? null,
            'minConfidence' => $detail['minConfidence'] ?? null,
            'dataTier' => $detail['dataTier'] ?? null,
            'explanation' => $detail['policyExplanation'] ?? null,
        ];
        unset($ledger);
    }

    /**
     * Confidence distribution buckets (requirement #14). Fixed, readable
     * bands so "average 71%" can never hide a bimodal day.
     */
    public const CONFIDENCE_BUCKETS = [
        ['label' => '>=85', 'min' => 85.0, 'max' => 100.01],
        ['label' => '75-84', 'min' => 75.0, 'max' => 85.0],
        ['label' => '70-74', 'min' => 70.0, 'max' => 75.0],
        ['label' => '65-69', 'min' => 65.0, 'max' => 70.0],
        ['label' => '60-64', 'min' => 60.0, 'max' => 65.0],
        ['label' => '<60', 'min' => -0.01, 'max' => 60.0],
    ];

    /** Data-quality distribution buckets, aligned to the adaptive tiers. */
    public const DATA_QUALITY_BUCKETS = [
        ['label' => '>=85 (EXCELLENT)', 'min' => 85.0, 'max' => 100.01],
        ['label' => '75-84 (GOOD)', 'min' => 75.0, 'max' => 85.0],
        ['label' => '65-74 (LIMITED)', 'min' => 65.0, 'max' => 75.0],
        ['label' => '<65 (REJECT)', 'min' => -0.01, 'max' => 65.0],
    ];

    /** Empty bucket map, so a zero band is reported as 0 rather than absent. */
    private static function emptyBuckets(array $buckets): array
    {
        $out = [];
        foreach ($buckets as $bucket) $out[$bucket['label']] = 0;
        $out['unmeasured'] = 0;
        return $out;
    }

    /** Increment the band a score falls into (null → 'unmeasured'). */
    private static function bucket(array &$counts, array $buckets, $score): void
    {
        if (!is_numeric($score)) { $counts['unmeasured'] = ($counts['unmeasured'] ?? 0) + 1; return; }
        $value = (float) $score;
        foreach ($buckets as $bucket) {
            if ($value >= $bucket['min'] && $value < $bucket['max']) {
                $counts[$bucket['label']] = ($counts[$bucket['label']] ?? 0) + 1;
                return;
            }
        }
        $counts['unmeasured'] = ($counts['unmeasured'] ?? 0) + 1;
    }

    /** Funnel counters for one evaluated candidate (per market:selection). */
    private function trackCandidateFunnel(array $candidate, array &$funnel, float $minConfidence, float $minEv): void
    {
        $ready = ($candidate['prediction']['decision'] ?? '') === 'PREDICTION_READY';
        if ($ready) $funnel['sufficientDataCandidates']++;

        // Requirement #14: the real spread of confidence and data quality
        // behind the day, and the average of the confidences actually
        // measured (never of substituted zeros).
        $measured = is_numeric($candidate['confidence']['confidence'] ?? null) ? (float) $candidate['confidence']['confidence'] : null;
        self::bucket($funnel['confidenceDistribution'], self::CONFIDENCE_BUCKETS, $measured);
        self::bucket($funnel['dataQualityDistribution'], self::DATA_QUALITY_BUCKETS, $candidate['dataQuality']['score'] ?? ($candidate['quality']['score'] ?? null));
        if ($measured !== null) {
            $funnel['confidenceSum'] = round(((float) ($funnel['confidenceSum'] ?? 0)) + $measured, 4);
            $funnel['confidenceMeasuredCount'] = (int) ($funnel['confidenceMeasuredCount'] ?? 0) + 1;
        }
        // The adaptive requirement this candidate actually faced, tallied by
        // tier so the funnel shows WHICH bar each leg had to clear.
        $tier = (string) ($candidate['confidencePolicy']['tier'] ?? 'UNKNOWN');
        $funnel['candidatesByDataTier'][$tier] = ($funnel['candidatesByDataTier'][$tier] ?? 0) + 1;
        if (!empty($candidate['confidencePolicy']) && empty($candidate['confidencePolicy']['marketAllowed'])) {
            $funnel['marketsRestrictedByDataTier']++;
        }

        // Confidence qualification is judged against the candidate's OWN
        // adaptive requirement when one was resolved, falling back to the
        // configured floor for candidates that never reached the stage.
        $required = is_numeric($candidate['confidencePolicy']['requiredConfidence'] ?? null)
            ? (float) $candidate['confidencePolicy']['requiredConfidence']
            : $minConfidence;
        if ($measured !== null && $measured + 1e-9 >= $required && !empty($candidate['confidencePolicy']['marketAllowed'] ?? true)) $funnel['confidenceQualifiedCandidates']++;
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
            'fixtureInput' => null,
            'fixturePageFull' => false,
            'batchOffset' => 0,
            // Canonical duplicates merged away: saved under their own provider,
            // evaluated once through the primary. Rows are capped; the count
            // never is.
            'fixturesDeduped' => 0,
            'duplicateFixtures' => ['truncated' => false, 'rows' => []],
            'eligibleFixtures' => 0,
            'fixtureBatchDeferred' => 0,
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
            // Requirement #14: the day's real spread, not just its averages.
            'confidenceDistribution' => self::emptyBuckets(self::CONFIDENCE_BUCKETS),
            'dataQualityDistribution' => self::emptyBuckets(self::DATA_QUALITY_BUCKETS),
            'confidenceSum' => 0.0,
            'confidenceMeasuredCount' => 0,
            'averageConfidence' => null,
            // How many candidates faced each adaptive tier's requirement, and
            // how many were held back because their market is not permitted
            // at the tier their evidence earned.
            'candidatesByDataTier' => [],
            'marketsRestrictedByDataTier' => 0,
            'confidencePolicy' => null,
            'confidenceQualifiedCandidates' => 0,
            'positiveValueCandidates' => 0,
            'minEdgeMetCandidates' => 0,
            'riskQualifiedCandidates' => 0,
            'correlationQualifiedCandidates' => 0,
            'finalQualifiedCandidates' => 0,
            // Final-selection transparency (requirements #10 and #14).
            'eligiblePoolSize' => 0,
            'preferredPoolSize' => 0,
            'selectionTier' => null,
            'fallbackUsed' => false,
            'fallbackReason' => null,
            'selectionAttempts' => [],
            'candidateDecisions' => [],
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
            // Requirement #13: one auditable row per hard-rejected candidate —
            // Reason, Missing, Available, Data Quality and the minimum allowed.
            // Rows are capped; the counts above never are.
            'rejectionAudit' => ['limit' => 100, 'truncated' => false, 'rows' => []],
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
        // The average of the confidences that were actually MEASURED. With no
        // measured candidate it stays null — an average of nothing is not 0.
        $measuredCount = (int) ($funnel['confidenceMeasuredCount'] ?? 0);
        $funnel['averageConfidence'] = $measuredCount > 0
            ? round(((float) $funnel['confidenceSum']) / $measuredCount, 2)
            : null;
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
    private function fetchFixtureSources(string $date, string $timezone, array &$errors, array $options = []): array
    {
        // Stored-first is the normal scheduler/manual path. A preceding fixture
        // sync may report "0 new" while hundreds of useful rows already exist;
        // those rows are inputs, not a reason to call the provider again.
        if (empty($options['refreshFixtures'])) {
            $window = DailyTicketDate::utcWindow($date, $timezone);
            // Ticket date is the generation calendar date; fixture kickoffs
            // may sit in the next local day (for example an evening ticket
            // built before a next-day match). Keep the look-ahead bounded and
            // request at most one generation batch from storage.
            $horizonDate = (new \DateTimeImmutable($date . ' 00:00:00', new \DateTimeZone($timezone)))->modify('+2 days')->format('Y-m-d');
            $horizon = DailyTicketDate::utcWindow($horizonDate, $timezone);
            $toInclusive = gmdate('Y-m-d\TH:i:sP', $horizon['startTimestamp'] - 1);
            // Stored rows are read kickoff-ASC and capped at one generation
            // batch. Reading from the ticket day's 00:00 meant that an
            // afternoon/evening run spent the whole 50-row page on fixtures
            // that already kicked off or start within the eligibility lead —
            // every one of them rejected FIXTURE_NOT_NS_OR_TOO_SOON, so the
            // engine reported "0 eligible" while perfectly good later
            // fixtures sat unread behind the page. The intake floor is now the
            // earliest kickoff that can still pass the eligibility gate.
            $eligibleFrom = time() + self::ELIGIBILITY_LEAD_SECONDS;
            // Clamp: when the whole window is already in the past (a historical
            // or expired date), the floor would exclude every row and send the
            // run to a pointless live provider cycle. Keep reading the stored
            // day so the engine still produces its honest "nothing eligible"
            // verdict from data it already has.
            $from = ($eligibleFrom > $window['startTimestamp'] && $eligibleFrom < $horizon['startTimestamp'])
                ? gmdate('Y-m-d\TH:i:sP', $eligibleFrom)
                : $window['start'];
            $stored = $this->repo->listMatches([
                'from' => $from, 'to' => $toInclusive, 'status' => 'SCHEDULED',
                'offset' => max(0, (int) ($options['batchOffset'] ?? 0)),
            ], self::MAX_GENERATION_CEILING);
            if ($stored !== []) {
                $providerCodes = [];
                foreach ($this->repo->listProviders() as $source) {
                    $providerCodes[(int) $source['id']] = (string) ($source['provider_code'] ?? '');
                }
                $grouped = [];
                foreach ($stored as $row) {
                    $providerId = (int) ($row['provider_id'] ?? 0);
                    $code = (string) ($providerCodes[$providerId] ?? '');
                    if ($code === '') continue;
                    // When live providers are configured, scope stored input
                    // to those enabled feeds. If none is configured at all,
                    // preserved stored rows remain usable without a provider
                    // pull (the offline/stored-first recovery path).
                    if ($this->providers->configured() && $this->providers->provider($code) === null) continue;
                    $payload = SportsDataNormalizer::document($row['payload'] ?? null);
                    $raw = $payload;
                    $raw['externalId'] = (string) ($raw['externalId'] ?? $row['external_id'] ?? '');
                    $raw['sport'] = (string) ($raw['sport'] ?? $row['sport'] ?? 'football');
                    $raw['competition'] = (string) ($raw['competition'] ?? $row['competition'] ?? '');
                    $raw['homeTeam'] = (string) ($raw['homeTeam'] ?? $row['home_team'] ?? '');
                    $raw['awayTeam'] = (string) ($raw['awayTeam'] ?? $row['away_team'] ?? '');
                    $raw['kickoff'] = (string) ($raw['kickoff'] ?? $row['kickoff_at'] ?? '');
                    $raw['status'] = (string) ($raw['status'] ?? $row['status'] ?? 'SCHEDULED');
                    $raw['sourceTimestamp'] = (string) ($raw['sourceTimestamp'] ?? $row['source_timestamp'] ?? $row['updated_at'] ?? '');
                    if (empty($raw['roundId']) && !empty($row['round_id'])) $raw['roundId'] = (string) $row['round_id'];
                    $raw['_windelsStoredFixture'] = true;
                    $grouped[$code]['providerId'] = $providerId;
                    $grouped[$code]['fixtures'][] = $raw;
                }
                $sources = [];
                foreach ($grouped as $code => $group) {
                    $sources[] = [
                        'provider' => $code, 'providerId' => (int) $group['providerId'],
                        'instance' => $this->providers->provider($code), 'fixtures' => $group['fixtures'],
                    ];
                }
                // A stored page that contains NOT ONE fixture able to pass the
                // first gate is not an input — it is a stale cache. Returning
                // it produced exactly the reported dead end: 50 evaluated,
                // 50 × FIXTURE_NOT_NS_OR_TOO_SOON, 0 eligible, while the
                // provider had fresh not-started fixtures nobody asked for.
                // When a live feed is configured, fall through to it instead;
                // offline/stored-only deployments keep the stored rows and
                // still get their honest timing verdict.
                if ($sources !== [] && $this->providers->configured()
                    && !$this->anyFixtureEligible($sources, time())) {
                    // Ask the live feed instead. If it cannot do better (every
                    // provider failed, or its rows are equally expired), the
                    // stored page is restored so the run still reports its
                    // honest timing verdict from real data rather than a
                    // fabricated provider outage.
                    // array_merge, not `+`: an existing falsy refreshFixtures
                    // key must be OVERRIDDEN, otherwise this path would call
                    // itself forever.
                    $live = $this->fetchFixtureSources($date, $timezone, $errors, array_merge($options, ['refreshFixtures' => true]));
                    if (!empty($live['ok']) && $this->anyFixtureEligible((array) $live['sources'], time())) return $live;
                }
                if ($sources !== []) {
                    return [
                        'ok' => true, 'input' => 'STORED', 'sources' => $sources,
                        'pageFull' => count($stored) === self::MAX_GENERATION_CEILING,
                        'batchOffset' => max(0, (int) ($options['batchOffset'] ?? 0)),
                        'failures' => [], 'failureStatuses' => [], 'summary' => 'stored fixtures reused',
                    ];
                }
            }
        }

        // Empty cache (or an explicit refresh): ask each configured feed once.
        //
        // Do not make the provider's first 50 *calendar-day* rows the ticket
        // universe. That was the production cause of a misleading
        // "50 evaluated → 0 eligible" verdict: at an afternoon run a feed
        // returned 50 finished/in-play or imminent fixtures, while its later
        // NS fixtures were behind the provider's response cap. Discover a
        // bounded 200-row candidate buffer across this local day and the next
        // one, ask providers that support it for NS rows, then apply the same
        // local eligibility rule below. The prediction/generation hard cap is
        // still 50; this only makes that cap spendable on viable fixtures.
        //
        // The stored-input path above already uses this two-local-day horizon.
        // Keep live intake aligned with it so a run late in the day can use
        // verified next-day fixtures rather than declaring a timing failure.
        $localStart = new \DateTimeImmutable($date . ' 00:00:00', new \DateTimeZone($timezone));
        $lookAheadDate = $localStart->modify('+1 day')->format('Y-m-d');
        $collected = $this->providers->collectAll('fixtures', fn(SportsDataProvider $p) => $p->fixtures([
            'from' => $date, 'to' => $lookAheadDate, 'timezone' => $timezone,
            // API-Football accepts NS server-side. Other adapters may ignore
            // it, which is safe because fixtureEligibleForDailyTicket() is the
            // authoritative provider-neutral gate.
            'status' => 'NS',
            // This is deliberately distinct from `limit`: 50 remains the
            // public/generation page limit, while native adapters use this
            // opt-in bounded discovery buffer for the ticket engine.
            'candidateLimit' => self::FIXTURE_DISCOVERY_CEILING,
            'page' => intdiv(max(0, (int) ($options['batchOffset'] ?? 0)), self::MAX_GENERATION_CEILING) + 1,
        ]));
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
            return ['ok' => false, 'input' => 'PROVIDER', 'failures' => $collected['failures'], 'failureStatuses' => $collected['failureStatuses'], 'summary' => $collected['summary']];
        }
        $pageFull = false;
        foreach ($sources as $source) if (count($source['fixtures']) >= self::MAX_GENERATION_CEILING) { $pageFull = true; break; }
        return [
            'ok' => true, 'input' => 'PROVIDER', 'sources' => $sources,
            'pageFull' => $pageFull, 'batchOffset' => max(0, (int) ($options['batchOffset'] ?? 0)),
            'failures' => $collected['failures'], 'failureStatuses' => $collected['failureStatuses'], 'summary' => '',
        ];
    }

    /**
     * Does ANY row of these intake sources still pass the first gate?
     *
     * A page with no eligible fixture cannot produce a ticket no matter what
     * the later gates decide, so the engine uses this to tell a usable input
     * apart from a stale one instead of spending the run proving it.
     *
     * @param array<int,array{provider:string, fixtures:array}> $sources
     */
    private function anyFixtureEligible(array $sources, int $now): bool
    {
        foreach ($sources as $source) {
            foreach ((array) ($source['fixtures'] ?? []) as $rawFixture) {
                if (!is_array($rawFixture)) continue;
                try { $probe = SportsDataNormalizer::fixture($rawFixture, (string) ($source['provider'] ?? '')); }
                catch (\Throwable $e) { continue; }
                if ($this->fixtureEligibleForDailyTicket($probe, $now)) return true;
            }
        }
        return false;
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
        // A not-started fixture is recognised by its MEANING, not by one
        // provider's spelling of it. Requiring the literal short code "NS"
        // rejected every feed that says "Not Started", "TBD", "PENDING",
        // "DELAYED" or simply "SCHEDULED" — a whole page of perfectly
        // predictable matches counted as FIXTURE_NOT_NS_OR_TOO_SOON while the
        // canonical status on the very same row said SCHEDULED. Both the
        // provider's own status and the canonical one are mapped through the
        // shared normalizer, and the fixture is eligible when they agree that
        // the match has not kicked off.
        $canonical = SportsDataNormalizer::canonicalStatus((string) ($match['status'] ?? ''));
        if ($canonical !== 'SCHEDULED') return false;
        // The provider's own wording must AGREE, and it is only allowed to
        // agree with a recognised not-started token. canonicalStatus() falls
        // back to SCHEDULED for anything it does not know, so an unrecognised
        // in-play code must never be waved through by that default: unknown
        // stays ineligible.
        $rawSourceStatus = strtoupper(trim((string) ($match['sourceStatus'] ?? '')));
        if ($rawSourceStatus !== ''
            && !in_array($rawSourceStatus, self::NOT_STARTED_SOURCE_STATUSES, true)) return false;
        try { $kickoff = (new \DateTimeImmutable((string) ($match['kickoff'] ?? '')))->getTimestamp(); }
        catch (\Throwable $e) { return false; }
        return $kickoff > ($now + self::ELIGIBILITY_LEAD_SECONDS);
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
        $fromStoredFixture = !empty($rawFixture['_windelsStoredFixture']);
        unset($rawFixture['_windelsStoredFixture']);
        if (!empty($rawFixture['context']['recentForm']) && !$fromStoredFixture) return $rawFixture;

        if ($fromStoredFixture) {
            // Stored-first intake already carries the previous document. It
            // must still pass the same field/timestamp/TTL checks and be counted
            // as carried forward; stale form is removed before enrichment.
            $form = $rawFixture['context']['recentForm'] ?? null;
        } else {
            $externalId = trim((string) ($rawFixture['externalId'] ?? ''));
            if ($externalId === '') return $rawFixture;
            try { $stored = $this->repo->findMatch($providerId, $externalId); }
            catch (\Throwable $e) { return $rawFixture; }
            // The stored document, in whichever shape the repository read
            // returned (decoded array or raw JSON text).
            $payload = SportsDataNormalizer::document($stored['payload'] ?? null);
            $form = $payload['context']['recentForm'] ?? null;
        }

        $valid = is_array($form);
        if ($valid) {
            foreach (FeatureEngineeringEngine::REQUIRED_FORM_FIELDS as $field) {
                if (!isset($form[$field]) || !is_numeric($form[$field])) { $valid = false; break; }
            }
        }
        $stamp = $valid && is_string($form['timestamp'] ?? null) ? $form['timestamp'] : null;
        if ($stamp === null) $valid = false; // unmeasurable age → never reused
        if ($valid) {
            try { $age = $now - (new \DateTimeImmutable($stamp))->getTimestamp(); }
            catch (\Throwable $e) { $valid = false; $age = PHP_INT_MAX; }
            if ($age < 0 || $age > $this->formMaxAgeSeconds()) $valid = false;
        }
        if (!$valid) {
            unset($rawFixture['context']['recentForm']);
            return $rawFixture;
        }
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

    /**
     * Per-market data feasibility (requirements #3, #4 and #7).
     *
     * The old gate asked one fixture-wide question — "is every mandatory
     * field present?" — and rejected the whole fixture as INSUFFICIENT_DATA
     * when the answer was no, even though a market whose OWN inputs were all
     * present could have been priced honestly. That is what produced
     * "INSUFFICIENT_DATA: 30" on a day with usable data.
     *
     * This asks the question once PER MARKET the fixture has real odds for,
     * against that market's own mandatory inputs (DataQualityEngine) and the
     * model features it actually consumes (PredictionEngine). A market is
     * feasible when all of ITS inputs exist. Optional enrichment is never
     * consulted here — it only moves the data-quality score.
     *
     * @param array $markets markets the fixture has fresh, supported odds for
     * @return array<string,array{ok:bool,missing:list<string>,mandatory:list<string>}>
     */
    private function marketFeasibility(array $matchRow, array $contextFields, array $markets): array
    {
        $intel = (new MatchIntelligenceEngine($this->oddsFreshness))->analyze($matchRow, null, [], null);
        $features = (new FeatureEngineeringEngine())->build(['decision' => 'INTELLIGENCE_READY'] + $intel);
        $built = is_array($features['features'] ?? null) ? $features['features'] : [];

        $out = [];
        foreach ($markets as $rawMarket) {
            $market = strtoupper(trim((string) $rawMarket));
            if ($market === '') continue;
            $missing = [];
            // 1. The market's own mandatory DATA fields.
            foreach (DataQualityEngine::mandatoryFieldsForMarket($market) as $field) {
                if (!in_array($field, $contextFields, true)) $missing[] = $field;
            }
            // 2. The model FEATURES this specific market consumes. TOTAL_GOALS
            //    needs only the goal-expectancy proxy; the head-to-head markets
            //    need both sides' attack/defence rates.
            foreach (PredictionEngine::requiredFeatures($market) as $feature) {
                if (!isset($built[$feature]) || !is_numeric($built[$feature])) $missing[] = 'feature.' . $feature;
            }
            $out[$market] = [
                'ok' => $missing === [],
                'missing' => array_values(array_unique($missing)),
                'mandatory' => DataQualityEngine::mandatoryFieldsForMarket($market),
            ];
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
