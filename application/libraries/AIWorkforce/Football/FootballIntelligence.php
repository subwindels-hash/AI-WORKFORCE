<?php
namespace AIWorkforce\Football;

use AIWorkforce\Persistence\AuditRepository;
use AIWorkforce\Persistence\FootballRepository;
use AIWorkforce\Sports\Providers\SportsProviderManager;

/**
 * Football intelligence: the single entry point both the console page and the
 * JSON API use, so a figure can never exist in one surface and not the other.
 *
 * Wiring lives here and nowhere else: gateway → sync → statistics → features →
 * quality gate → score model → calibration → storage → settlement → performance →
 * board. Construction is lazy so a page that only reads the board never pays for
 * a provider sweep, and every service is replaceable (the test harness injects
 * an in-memory repository through `fromParts()`).
 */
final class FootballIntelligence
{
    /** A fixture the engine has not produced a prediction for. */
    public const INTELLIGENCE_WITHHELD = 'PREDICTION_WITHHELD';

    private ?ProviderGateway $gateway = null;
    private ?FixtureSyncService $fixtures = null;
    private ?StatisticsCollector $statistics = null;
    private ?FeatureBuilder $features = null;
    private ?ExpectedGoalsResolver $expectedGoals = null;
    private ?ScoreProbabilityModel $scores = null;
    private ?OutcomePredictor $predictor = null;
    private ?CalibrationService $calibration = null;
    private ?ModelRegistry $models = null;
    private ?PredictionService $predictions = null;
    private ?LiveMatchService $live = null;
    private ?SettlementService $settlements = null;
    private ?PerformanceService $performance = null;
    private ?PredictionBoard $board = null;
    private ?MatchFeed $feed = null;
    private ?MatchIntelligenceService $intelligence = null;
    private ?PredictionMarkets $markets = null;
    private ?RefreshPolicy $refresh = null;
    private ?OddsIntelligence $fairValue = null;
    private ?OddsSheetService $oddsSheet = null;
    /** Optional sports store (odds persistence) bound by the Platform. */
    private ?object $sportsStore = null;
    private ?StabilityMonitor $stability = null;
    private ?IntelligenceScore $scoreEngine = null;
    private ?PredictionDrivers $drivers = null;
    private ?FreshnessTracker $freshness = null;
    private ?IntelligenceReport $report = null;
    private ?FootballDiagnostics $diagnostics = null;
    private ?FootballCronService $cron = null;

    public function __construct(
        private FootballRepository $repo,
        private ?SportsProviderManager $providers,
        private ?AuditRepository $audit = null,
        private ?FootballConfiguration $config = null,
    ) {
        $this->config = $config ?? new FootballConfiguration();
    }

    /** Explicit assembly — used by the test harness and by callers with their own gateway. */
    public static function fromParts(FootballRepository $repo, ?ProviderGateway $gateway, ?AuditRepository $audit = null, ?FootballConfiguration $config = null): self
    {
        $self = new self($repo, null, $audit, $config);
        $self->gateway = $gateway;
        return $self;
    }

    public function config(): FootballConfiguration
    {
        return $this->config;
    }

    public function repository(): FootballRepository
    {
        return $this->repo;
    }

    public function gateway(): ProviderGateway
    {
        return $this->gateway ??= new ProviderGateway($this->providers ?? new SportsProviderManager(), $this->config, $this->repo);
    }

    public function providerManager(): SportsProviderManager
    {
        $this->gateway();
        return $this->providers ?? new SportsProviderManager();
    }

    public function fixtures(): FixtureSyncService
    {
        return $this->fixtures ??= new FixtureSyncService($this->repo, $this->gateway(), $this->config, $this->audit);
    }

    public function statistics(): StatisticsCollector
    {
        return $this->statistics ??= new StatisticsCollector($this->repo, $this->gateway(), $this->config, $this->audit);
    }

    public function features(): FeatureBuilder
    {
        return $this->features ??= new FeatureBuilder($this->repo, $this->statistics(), $this->config);
    }

    public function expectedGoals(): ExpectedGoalsResolver
    {
        return $this->expectedGoals ??= new ExpectedGoalsResolver($this->config);
    }

    public function scores(): ScoreProbabilityModel
    {
        return $this->scores ??= new ScoreProbabilityModel($this->config);
    }

    public function calibration(): CalibrationService
    {
        return $this->calibration ??= new CalibrationService($this->repo, $this->config, $this->audit);
    }

    public function predictor(): OutcomePredictor
    {
        return $this->predictor ??= new OutcomePredictor($this->expectedGoals(), $this->scores(), $this->calibration(), $this->config);
    }

    public function models(): ModelRegistry
    {
        return $this->models ??= new ModelRegistry($this->repo, $this->config, $this->audit);
    }

    public function predictions(): PredictionService
    {
        return $this->predictions ??= new PredictionService(
            $this->repo, $this->features(), $this->predictor(), $this->models(), $this->config, $this->audit, $this->stability()
        );
    }

    public function live(): LiveMatchService
    {
        // RefreshPolicy is passed in so the live board can pull the provider on
        // its own cadence when a reader is watching (board(autoSweep: true)),
        // under exactly the same due/backoff/budget gates the scheduled job
        // uses — the panel stays current on a host with no cron installed,
        // without ever becoming a second, ungoverned source of provider traffic.
        return $this->live ??= new LiveMatchService($this->repo, $this->features(), $this->predictor(), $this->models(), $this->predictions(), $this->fixtures(), $this->audit, $this->config, $this->refresh());
    }

    public function settlements(): SettlementService
    {
        return $this->settlements ??= new SettlementService($this->repo, $this->audit, $this->fixtures());
    }

    public function performance(): PerformanceService
    {
        return $this->performance ??= new PerformanceService($this->repo, $this->calibration(), $this->models());
    }

    public function board(): PredictionBoard
    {
        return $this->board ??= new PredictionBoard($this->repo, $this->predictions(), $this->models(), $this->config,
            $this->feed(), $this->report());
    }

    /**
     * The paginated match feed: 50 matches per page, and at most 50 new
     * predictions per generation request.
     */
    public function feed(): MatchFeed
    {
        return $this->feed ??= new MatchFeed($this->repo, $this->predictions(), $this->models(), $this->config,
            $this->markets(), $this->report());
    }

    /**
     * The Match Intelligence Engine: provider selection, normalization and
     * canonical identity across API-Football, TheSportsDB and SportMonks.
     *
     * This is the only door to a provider for the football module — the
     * prediction engine reads matches, never feeds.
     */
    public function intelligence(): MatchIntelligenceService
    {
        return $this->intelligence ??= new MatchIntelligenceService(
            $this->gateway(), new ProviderSelector($this->gateway(), $this->config), $this->repo, $this->config);
    }

    /**
     * The odds-prediction markets: the catalogue, and the evaluation of one
     * market over a stored prediction. Choosing a market is a view, never a
     * regeneration.
     */
    public function markets(): PredictionMarkets
    {
        return $this->markets ??= new PredictionMarkets($this->config, $this->fairValue());
    }

    /**
     * The Odds Intelligence Engine: normalises what the market charged, takes the
     * bookmaker's margin out where the market allows it, and compares the result
     * with WINDELS' own estimate.
     *
     * It reads prices; it does not fetch them. The odds rows it consumes are the
     * ones the connected feed already stored, and an unpriced market is answered
     * with `UNPRICED` rather than with a price invented from the model.
     */
    public function fairValue(): OddsIntelligence
    {
        return $this->fairValue ??= new OddsIntelligence($this->config);
    }

    /**
     * The complete bookmaker odds surface: the stored per-fixture odds sheet,
     * a billed pre-match refresh, in-play (live) odds snapshots and the
     * vendor's bookmaker / bet-type reference catalogs. Reads are free;
     * anything that spends provider quota says so and is permission-gated at
     * the controller.
     */
    public function oddsSheet(): OddsSheetService
    {
        if ($this->oddsSheet === null) {
            $this->oddsSheet = new OddsSheetService($this->repo, $this->gateway(), $this->config);
            $this->oddsSheet->bindSportsStore($this->sportsStore);
        }
        return $this->oddsSheet;
    }

    /** Bind the sports repository so a billed odds refresh can persist rows (Platform wiring). */
    public function bindSportsStore(?object $store): void
    {
        $this->sportsStore = $store;
        $this->oddsSheet?->bindSportsStore($store);
    }

    /** The movement history of a prediction, and the verdict on its stability. */
    public function stability(): StabilityMonitor
    {
        return $this->stability ??= new StabilityMonitor($this->repo, $this->config);
    }

    /** The 0–100 WINDELS Intelligence Score, composed from stored measurements. */
    public function intelligenceScores(): IntelligenceScore
    {
        return $this->scoreEngine ??= new IntelligenceScore($this->config);
    }

    /** Why a prediction was selected, as rows the reader can check. */
    public function drivers(): PredictionDrivers
    {
        return $this->drivers ??= new PredictionDrivers();
    }

    /** The three clocks behind "last updated". */
    public function freshness(): FreshnessTracker
    {
        return $this->freshness ??= new FreshnessTracker($this->config);
    }

    /**
     * The intelligence layer every surface reads: the per-match block (score,
     * quality checklist, drivers, fair value, stability, freshness) and the
     * ranked pick list built from it.
     */
    public function report(): IntelligenceReport
    {
        return $this->report ??= new IntelligenceReport(
            $this->repo, $this->config, $this->stability(), $this->intelligenceScores(), $this->drivers(), $this->freshness()
        );
    }

    /**
     * The competitions a date can be narrowed to, with the premium (featured)
     * competition marked. Listed from the provider's own rows — the module never
     * offers a league it has no data for.
     */
    public function competitions(string $date, ?int $providerId = null): array
    {
        return $this->feed()->competitions($date, $providerId);
    }

    public function refresh(): RefreshPolicy
    {
        return $this->refresh ??= new RefreshPolicy($this->repo, $this->config, $this->gateway());
    }

    /**
     * The scheduled jobs behind `php index.php tools football-cron` and the
     * platform cron sweep. Built here so `CronRunner` reaches it the same way it
     * reaches every other module (`platform->football->cron()`).
     */
    public function cron(): FootballCronService
    {
        return $this->cron ??= new FootballCronService($this, $this->repo, $this->audit);
    }

    public function diagnostics(): FootballDiagnostics
    {
        return $this->diagnostics ??= new FootballDiagnostics($this->repo, $this->gateway(), $this->config, $this->models(), $this->calibration(), $this->refresh(), $this->statistics());
    }

    // ── read models ───────────────────────────────────────────────────────────

    /**
     * The console payload: board + diagnostics + performance + models, assembled
     * once so the view renders each panel exactly one time.
     *
     * @return array<string,mixed>
     */
    public function dashboard(?string $date = null, bool $refresh = false, int $page = 1, int $limit = MatchFeed::MAX_PAGE_SIZE, array $options = []): array
    {
        $date = $date ?? gmdate('Y-m-d');
        $diagnostics = $this->diagnostics()->snapshot();
        $board = $this->board()->forDate($date, $refresh, $page, $limit, $options);
        return [
            'date' => $date,
            // One page of the board. The pager moves through stored matches; the
            // summary counts above the cards still describe the whole date.
            // `options` narrow it (`competition`, `market`) without generating
            // anything — both are selections over rows that are already stored.
            'board' => $board,
            // The pipeline state behind the Day overview's counters: which
            // absence the six tiles are in (no feed / sweep never ran / feed
            // returned nothing / generation off / nothing published), computed
            // from the board payload it describes plus the last recorded
            // FIXTURES sweep. A pure read — no provider request.
            'dayStatus' => $this->dayStatus($date, $board, $refresh),
            'diagnostics' => $diagnostics,
            'performance' => $this->performance()->report(30),
            'live' => $this->live()->board(false),
            'models' => $this->modelSummary(),
            'generatedAt' => gmdate('c'),
        ];
    }

    /**
     * The day-overview pipeline state — what the console's "What is stored
     * for <date>" section explains its six counters with instead of six bare
     * zeros. The counters describe stored rows, and when the rows are absent
     * the state names WHICH absence the tiles are in, because they are
     * different facts with different remedies:
     *
     *  - NO_PROVIDER          — no feed connected; no fixture can ever be stored
     *  - NEVER_SYNCED         — feed connected, but no fixtures sweep recorded
     *                           (the cron has not run / nobody clicked Sync)
     *  - SYNCED_NO_FIXTURES   — the sweep ran and the feed returned no fixture
     *                           for this date (empty match day, or a league
     *                           package that does not cover these competitions)
     *  - GENERATION_OFF       — fixtures are stored but no prediction row
     *                           exists and this read did not generate
     *                           (?refresh=0 / WINDELS_FOOTBALL_GENERATE_ON_READ=false)
     *  - ANALYZED_NONE        — this read ran the engine and nothing was
     *                           published: withheld / closed / failed — the
     *                           split and the rows carry the reasons
     *  - ALL_CLOSED           — every stored fixture for the date is past
     *                           kickoff / postponed / cancelled: no pre-match
     *                           prediction can ever be created; nothing is
     *                           back-filled (a historical board)
     *  - POPULATED            — the counts speak for themselves
     *
     * It also publishes the date-wide durable split of the unanalyzed
     * fixtures — `closed` (past kickoff or void, by the engine's own rule),
     * `withheld` (a stored assessment for the current model) and `awaiting`
     * (open, no assessment) — so the Day overview tiles can name their
     * exclusions instead of approximating them from one page.
     *
     * Purely read-only: the board payload it describes plus the last recorded
     * FIXTURES sync run. No provider request is ever made.
     *
     * @param array<string,mixed> $board a board payload from PredictionBoard::forDate()
     * @return array<string,mixed>
     */
    public function dayStatus(string $date, array $board, bool $refresh = false): array
    {
        $summary = is_array($board['summary'] ?? null) ? $board['summary'] : [];
        $fixtures = (int) ($summary['fixtures'] ?? 0);
        $analyzed = (int) ($summary['analyzed'] ?? 0);
        $qualified = (int) ($summary['qualified'] ?? 0);
        $limited = (int) ($summary['limited'] ?? 0);
        $configured = $this->gateway()->configured();
        $lastRun = null;
        try { $lastRun = $this->repo->lastSyncRun('FIXTURES'); } catch (\Throwable $e) { $lastRun = null; }
        $sweepAt = (string) ($lastRun['started_at'] ?? '');
        $sweepStamp = substr($sweepAt, 11, 5);
        $sweepRequests = (int) ($lastRun['requests_made'] ?? 0);

        // The date-wide durable split of the unanalyzed fixtures — the same
        // question the board's `page` block answers for the page in view,
        // answered for the WHOLE selection so the Day overview tiles can name
        // their exclusions ("4 past kickoff") instead of approximating them
        // from one page ("excludes 4 answered on this page"). Each unanalyzed
        // fixture is exactly one of: withheld (a stored assessment for the
        // current model), closed (the engine's own rule: kickoff passed or the
        // fixture is postponed/cancelled), or awaiting (open, no assessment).
        $closed = 0; $withheld = 0; $awaiting = 0;
        $filter = ['date' => $date];
        $selection = is_array(($board['filters'] ?? [])['competition'] ?? null) ? $board['filters']['competition'] : [];
        if (is_array($selection['externalIds'] ?? null)) $filter['competitionExternalIds'] = $selection['externalIds'];
        elseif (isset($selection['externalId'])) $filter['competitionExternalId'] = $selection['externalId'];
        if ($fixtures > 0) {
            $rows = $this->repo->listFixtures($filter, 500);
            $modelVersionId = (int) ($this->models()->usable()['model']['id'] ?? 0);
            $predicted = [];
            try {
                foreach ($this->repo->listPredictions($filter + ['kind' => PredictionService::KIND_PRE_MATCH], 500) as $row) {
                    $predicted[(int) ($row['fixture_id'] ?? 0)] = true;
                }
            } catch (\Throwable $e) { $predicted = []; }
            $assessed = [];
            try {
                foreach ($this->repo->listFixtureStatisticsFor(
                    array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $rows),
                    PredictionService::ASSESSMENT_KIND
                ) as $fixtureId => $row) {
                    $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
                    if ($payload !== [] && (string) ($payload['status'] ?? '') === 'ASSESSED_NO_PREDICTION'
                        && (int) ($payload['modelVersionId'] ?? 0) === $modelVersionId) $assessed[(int) $fixtureId] = true;
                }
            } catch (\Throwable $e) { $assessed = []; }
            foreach ($rows as $row) {
                $fixtureId = (int) ($row['id'] ?? 0);
                if (isset($predicted[$fixtureId])) continue;
                if (isset($assessed[$fixtureId])) { $withheld++; continue; }
                if ($this->predictions()->refusal($row) !== null) { $closed++; continue; }
                $awaiting++;
            }
        }
        $pageFailed = (int) ((is_array($board['page'] ?? null) ? $board['page'] : [])['failed'] ?? 0);
        // The split sentence every empty-tile state shares: what the unanalyzed
        // fixtures actually are, in the words the tiles use.
        $split = [];
        if ($closed > 0) $split[] = $closed . ' past kickoff or void';
        if ($withheld > 0) $split[] = $withheld . ' withheld by the data-quality gate';
        if ($awaiting > 0) $split[] = $awaiting . ' still without an assessment';
        if ($pageFailed > 0) $split[] = $pageFailed . ' generation attempt(s) failed on this page';
        $splitSentence = $split === [] ? 'none are awaiting anything' : implode(', ', $split);

        $state = match (true) {
            !$configured => 'NO_PROVIDER',
            $fixtures === 0 && $lastRun === null => 'NEVER_SYNCED',
            $fixtures === 0 => 'SYNCED_NO_FIXTURES',
            $analyzed === 0 && $closed === $fixtures && $fixtures > 0 => 'ALL_CLOSED',
            $analyzed === 0 && !$refresh => 'GENERATION_OFF',
            $analyzed === 0 => 'ANALYZED_NONE',
            default => 'POPULATED',
        };
        $detail = match ($state) {
            'NO_PROVIDER' => 'No football data provider is connected, so no fixture can be stored for this board and every count in this overview is zero. Connect a feed on the Data feed panel; nothing is invented to fill the gap.',
            'NEVER_SYNCED' => 'The connected feed\'s fixtures sweep has not stored anything yet. It runs automatically on the football fixtures job (every 6 hours while a provider is connected), or on demand via Sync this date — that sweep is what fills "Fixtures found".',
            'SYNCED_NO_FIXTURES' => 'The fixtures sweep last ran at ' . ($sweepStamp !== '' ? $sweepStamp . ' UTC' : 'an unknown time')
                . ' (' . $sweepRequests . ' provider request(s)) and no fixture is stored for this date: either the feed returned none for it — an empty match day, or a league package that does not cover these competitions — or this date was outside that sweep\'s window. Sync this date asks the feed for exactly this day. No fixture is invented to fill the board.',
            'ALL_CLOSED' => 'All ' . $fixtures . ' fixture(s) stored for this date are past kickoff, postponed or cancelled, so no pre-match prediction can be created for any of them — Analyzed stays 0 and nothing is back-filled. Matches still in play carry live estimates on the Live match panel; finished ones are graded by the settlement pipeline once their results are stored. Sync this date re-reads the feed\'s current statuses, and the day navigation moves to upcoming dates.',
            'GENERATION_OFF' => $fixtures . ' fixture(s) are stored for this date but no prediction row exists yet (' . $splitSentence
                . '). Generation on read is off (?refresh=0 or WINDELS_FOOTBALL_GENERATE_ON_READ=false), so the Generate this page action is how "Analyzed" fills — at most 50 new predictions per request, stored ones reused.'
                . ($closed > 0 ? ' The past-kickoff ones can never receive one.' : ''),
            'ANALYZED_NONE' => $fixtures . ' fixture(s) are stored and this read ran the engine for the page in view, but no prediction was published: ' . $splitSentence
                . '. The Withheld tile and each fixture row below carry the specific reason.',
            default => $fixtures . ' fixture(s) stored · ' . $analyzed . ' analyzed — ' . $qualified . ' qualified, '
                . $limited . ' on limited evidence' . ($awaiting > 0 ? ' · ' . $awaiting . ' awaiting analysis' : '')
                . ' — for this selection. The counts below describe exactly those rows.',
        };

        return [
            'state' => $state,
            'detail' => $detail,
            'date' => $date,
            'providerConfigured' => $configured,
            'fixtures' => $fixtures,
            'analyzed' => $analyzed,
            'qualified' => $qualified,
            'limited' => $limited,
            // The date-wide durable split of the unanalyzed fixtures.
            'closed' => $closed,
            'withheld' => $withheld,
            'awaiting' => $awaiting,
            'pageFailed' => $pageFailed,
            'lastFixturesSync' => $lastRun === null ? null : [
                'status' => (string) ($lastRun['status'] ?? ''),
                'startedAt' => $sweepAt,
                'requests' => $sweepRequests,
            ],
            'generatedOnRead' => $refresh,
            'generatedAt' => gmdate('c'),
        ];
    }

    /** Model + calibration panel data, sourced from stored rows only. */
    public function modelSummary(): array
    {
        $usable = $this->models()->usable();
        $model = $usable['model'];
        $modelVersionId = (int) ($model['id'] ?? 0);
        $calibrations = $modelVersionId > 0 ? $this->calibration()->versions($modelVersionId) : [];
        $calibrationAvailability = $modelVersionId > 0
            ? $this->calibration()->availability($modelVersionId)
            : ['settled' => 0, 'usable' => 0, 'missingProbabilities' => 0, 'minimum' => $this->config->minCalibrationSamples(), 'sources' => []];
        $active = null;
        foreach ($calibrations as $row) {
            if ((string) $row['status'] === CalibrationService::CALIBRATED) { $active = $row; break; }
        }
        $versions = $this->models()->list();
        return [
            'state' => (string) $usable['state'],
            'label' => (string) $usable['label'],
            'reason' => $usable['reason'],
            // Why the lifecycle fields are empty and how they fill — the state
            // the models screen's "Live version" section explains its dashes
            // with. Computed below from the same rows the table prints.
            'lifecycleStatus' => $this->lifecycleStatus($model, $calibrationAvailability, $active),
            'activeModel' => $model === null ? null : [
                'id' => $modelVersionId,
                'modelId' => (string) ($model['model_id'] ?? ''),
                'name' => (string) ($model['model_name'] ?? ''),
                'version' => (string) ($model['model_version'] ?? ''),
                'algorithm' => (string) ($model['algorithm'] ?? ''),
                'featureVersion' => (string) ($model['feature_version'] ?? ''),
                'status' => (string) ($model['status'] ?? ModelRegistry::DRAFT),
                'trainingDatasetVersion' => $model['training_dataset_version'] ?? null,
                'createdAt' => $model['created_at'] ?? null,
                'trainedAt' => $model['trained_at'] ?? null,
                'validatedAt' => $model['validated_at'] ?? null,
                'calibratedAt' => $model['calibrated_at'] ?? null,
                'approvedAt' => $model['approved_at'] ?? null,
                'approvedBy' => $model['approved_by'] ?? null,
                'activatedAt' => $model['activated_at'] ?? null,
                'lastEvaluatedAt' => $model['last_evaluated_at'] ?? null,
                'validationSampleSize' => isset($model['validation_sample_size']) ? (int) $model['validation_sample_size'] : null,
                'accuracy' => $model['accuracy'] ?? null,
                'logLoss' => $model['log_loss'] ?? null,
                'brierScore' => $model['brier_score'] ?? null,
                'ece' => $model['ece'] ?? null,
                'calibrationVersion' => $active['calibrationVersion'] ?? null,
                'calibrationStatus' => $active['status'] ?? CalibrationService::PENDING,
            ],
            // Why no calibration exists yet and how one appears — the state
            // the models screen's "Calibration versions" section explains
            // its "0 of N" with. Computed below from the same availability
            // block the section prints.
            'calibrationStatus' => $this->calibrationStatus($calibrationAvailability, $calibrations),
            'calibration' => $active ?? ['status' => CalibrationService::PENDING, 'samples' => $calibrationAvailability['usable'],
                'settledSamples' => $calibrationAvailability['settled'], 'minimum' => $calibrationAvailability['minimum'], 'calibrationVersion' => null],
            'calibrationAvailability' => $calibrationAvailability,
            'calibrationVersions' => $calibrations,
            'approvedCalibrationCount' => $this->calibration()->approvedCount(),
            'versions' => array_map(fn(array $row) => [
                'id' => (int) ($row['id'] ?? 0),
                'modelId' => (string) ($row['model_id'] ?? ''),
                'name' => (string) ($row['model_name'] ?? ''),
                'version' => (string) ($row['model_version'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'createdAt' => $row['created_at'] ?? null,
                'trainedAt' => $row['trained_at'] ?? null,
                'validatedAt' => $row['validated_at'] ?? null,
                'approvedAt' => $row['approved_at'] ?? null,
                'activatedAt' => $row['activated_at'] ?? null,
                'lastEvaluatedAt' => $row['last_evaluated_at'] ?? null,
                'validationSampleSize' => isset($row['validation_sample_size']) ? (int) $row['validation_sample_size'] : null,
                'accuracy' => $row['accuracy'] ?? null,
                'logLoss' => $row['log_loss'] ?? null,
                'brierScore' => $row['brier_score'] ?? null,
                'ece' => $row['ece'] ?? null,
                'trainingDatasetVersion' => $row['training_dataset_version'] ?? null,
                'calibrationVersionId' => $row['calibration_version_id'] ?? null,
                'approvedBy' => $row['approved_by'] ?? null,
                'states' => ModelRegistry::STATES,
            ], $versions),
        ];
    }

    /**
     * The calibration-evidence state behind the "Calibration versions"
     * section's "0 of N" — what the models screen explains a missing
     * calibration with. A calibration is fitted from settled predictions of
     * THIS model version (each graded prediction with a recoverable raw
     * probability vector is one sample); the fit is refused below the
     * configured minimum, and the hourly performance job retries it
     * automatically, so the states are:
     *
     *  - NO_SAMPLES           — nothing this version predicted has settled
     *                           yet: no evidence exists to fit from
     *  - INSUFFICIENT_SAMPLES — evidence exists but below the minimum
     *                           (N of M); fitting is refused and says so
     *  - FITTABLE_NOT_FITTED  — the minimum is met and no calibration is
     *                           stored yet: the Fit calibration action fits
     *                           one now, the hourly job also will
     *  - null                 — a calibration is stored: the section's table
     *                           is the information, no strip renders
     *
     * Purely read-only. @param array<string,mixed> $availability
     * @param list<array<string,mixed>> $calibrations
     * @return array<string,mixed>|null
     */
    private function calibrationStatus(array $availability, array $calibrations): ?array
    {
        if ($calibrations !== []) return null;
        $settled = (int) ($availability['settled'] ?? 0);
        $usable = (int) ($availability['usable'] ?? 0);
        $missing = (int) ($availability['missingProbabilities'] ?? 0);
        $minimum = (int) ($availability['minimum'] ?? 0);

        if ($settled === 0 && $usable === 0) {
            return ['state' => 'NO_SAMPLES',
                'detail' => 'No prediction made by this model version has been settled yet, so there is no evidence to fit from. Evidence accrues automatically: predictions are stored before kickoff, the match finishes, the football-results job stores the final score and the football-settle job grades the prediction (both run every 15 minutes) — each graded prediction of this version becomes one calibration sample. The hourly performance job retries the fit on its own. A fit is refused below ' . $minimum . ' usable samples (' . $minimum . ' is the WINDELS_FOOTBALL_MIN_CALIBRATION_SAMPLES setting, floor 10), and the Fit calibration from stored settlements button names the exact shortfall when it refuses. Until a fit exists, confidence is published raw and labelled CALIBRATION_PENDING — never quietly adjusted.',
                'settled' => $settled, 'usable' => $usable, 'missingProbabilities' => $missing, 'minimum' => $minimum];
        }
        if ($usable < $minimum) {
            return ['state' => 'INSUFFICIENT_SAMPLES',
                'detail' => $usable . ' of ' . $minimum . ' required settled predictions currently have recoverable raw probabilities (' . $settled . ' settled for this model in total'
                    . ($missing > 0 ? ', ' . $missing . ' without a safe raw vector — excluded rather than guessed' : '')
                    . '). The results and settlement jobs keep adding evidence as this version\'s predictions complete, and the hourly performance job retries the fit automatically; a fit stays refused below the minimum. Until then, displayed confidence is labelled CALIBRATION_PENDING (raw), never silently adjusted.',
                'settled' => $settled, 'usable' => $usable, 'missingProbabilities' => $missing, 'minimum' => $minimum];
        }
        return ['state' => 'FITTABLE_NOT_FITTED',
            'detail' => $usable . ' of ' . $minimum . ' usable settled samples — the minimum is met, so a calibration can be fitted now: the Fit calibration from stored settlements action (Live version section, sports.manage) fits one, and the hourly performance job also fits one automatically on its next run. The fitted temperature is only ever ≥ 1 (this module only softens confidence, never sharpens it), and the fit is stored with its measured ECE and Brier over the training window.',
            'settled' => $settled, 'usable' => $usable, 'missingProbabilities' => $missing, 'minimum' => $minimum];
    }

    /**
     * The model-lifecycle state behind the "Live version" table's dashes —
     * what the models screen explains its empty fields with. Every dash in
     * that table is one of two kinds, and they fill differently:
     *
     *  - measured figures (Training dataset version, Validation sample size,
     *    Accuracy, Log loss, Brier score, ECE, Last evaluated) are recorded
     *    automatically from settled predictions by the hourly performance
     *    job — with none settled yet there is nothing to measure;
     *  - lifecycle stamps (Trained, Validated, Calibrated, Approved,
     *    Approved by, Activated) are earned by operator actions in the
     *    version register, and the registry REFUSES Train/Validate until an
     *    evaluation is recorded — the chain cannot skip settled history.
     *
     *  - DRAFT_NO_EVIDENCE        — registered from the deployed scoring
     *                               configuration; nothing measured, nothing
     *                               transitioned (the fresh-board state)
     *  - DRAFT_EVIDENCE_RECORDED  — measured figures exist; the stamps wait
     *                               on operator transitions
     *  - LIFECYCLE_IN_PROGRESS    — TRAINED/VALIDATED/CALIBRATED; the
     *                               remaining stamps are named
     *  - APPROVED_NOT_ACTIVE      — approved; activation remains
     *  - ACTIVE_UNCALIBRATED      — answering predictions; the calibration
     *                               pair is what stays empty
     *  - null                     — ACTIVE and calibrated: the table is
     *                               full, the figures are the information
     *
     * Purely read-only. @param array<string,mixed>|null $model raw model row
     * @param array<string,mixed> $availability calibrationAvailability block
     * @param array<string,mixed>|null $calibration active calibration row
     * @return array<string,mixed>|null
     */
    private function lifecycleStatus(?array $model, array $availability, ?array $calibration): ?array
    {
        if ($model === null) return null;
        $status = strtoupper((string) ($model['status'] ?? ModelRegistry::DRAFT));
        $version = (string) ($model['model_version'] ?? '');
        $usableSamples = (int) ($availability['usable'] ?? 0);
        $minimum = (int) ($availability['minimum'] ?? 0);
        $calibrationPending = ($calibration['status'] ?? CalibrationService::PENDING) !== CalibrationService::CALIBRATED;

        if ($status === ModelRegistry::ACTIVE) {
            if (!$calibrationPending) return null;
            return ['state' => 'ACTIVE_UNCALIBRATED',
                'detail' => 'This is the version answering predictions. The remaining empty fields are the calibration pair: a calibration is fitted from settled history via Fit calibration ('
                    . $usableSamples . ' of ' . $minimum . ' usable settled samples so far) and approved on this screen; until then confidence is published raw and labelled CALIBRATION_PENDING — never silently adjusted.',
                'status' => $status, 'version' => $version, 'usableSamples' => $usableSamples, 'minimum' => $minimum];
        }

        $evidence = (int) ($model['validation_sample_size'] ?? 0) > 0;
        if ($status === ModelRegistry::DRAFT) {
            $state = $evidence ? 'DRAFT_EVIDENCE_RECORDED' : 'DRAFT_NO_EVIDENCE';
            $detail = $evidence
                ? 'The measured figures for this version are recorded (' . (int) ($model['validation_sample_size'] ?? 0) . ' settled prediction(s) evaluated). The remaining empty fields are the operator-earned lifecycle stamps: Trained, Validated, Calibrated, Approved and Activated each fill when that transition succeeds in the version register — approval requires measured accuracy, log loss, Brier and ECE over stored settlements, and CALIBRATED requires a fitted calibration. Until activation, predictions continue against the current live version.'
                : 'This version was registered automatically from the deployed scoring configuration' . ($version !== '' ? ' (' . $version . ')' : '')
                    . ' when the engine first analyzed a fixture — never as an approved model. The empty fields fill in a fixed order. First the measured figures (Training dataset version, Validation sample size, Accuracy, Log loss, Brier score, ECE, Last evaluated): the hourly performance job records them from settled predictions, and with none settled yet there is nothing to measure. Then the lifecycle stamps (Trained, Validated, Calibrated, Approved, Activated): each is earned by an operator action in the version register, and the registry refuses Train/Validate until an evaluation is recorded — the chain cannot skip settled history. The calibration version fills when a calibration is fitted from that history (Fit calibration; '
                    . $usableSamples . ' of ' . $minimum . ' usable settled samples so far). Meanwhile predictions run against this DRAFT version with raw confidence labelled CALIBRATION_PENDING.';
            return ['state' => $state, 'detail' => $detail, 'status' => $status, 'version' => $version,
                'usableSamples' => $usableSamples, 'minimum' => $minimum];
        }

        if ($status === ModelRegistry::APPROVED) {
            return ['state' => 'APPROVED_NOT_ACTIVE',
                'detail' => 'The version is approved' . (!empty($model['approved_by']) ? ' by ' . (string) $model['approved_by'] : '')
                    . '; activation — which retires the previous ACTIVE version — is the last operator step in the version register. Until then predictions run against the currently ACTIVE version.',
                'status' => $status, 'version' => $version, 'usableSamples' => $usableSamples, 'minimum' => $minimum];
        }

        // TRAINED / VALIDATED / CALIBRATED: mid-chain.
        $chain = [ModelRegistry::TRAINED, ModelRegistry::VALIDATED, ModelRegistry::CALIBRATED, ModelRegistry::APPROVED, ModelRegistry::ACTIVE];
        $position = array_search($status, $chain, true);
        $after = $position === false ? $chain : array_slice($chain, (int) $position + 1);
        return ['state' => 'LIFECYCLE_IN_PROGRESS',
            'detail' => 'The version has reached ' . $status . '. The remaining stamps (' . implode(', ', $after)
                . ') fill as an operator completes each transition in the version register: approval requires measured accuracy, log loss, Brier and ECE over stored settlements'
                . ($calibrationPending ? ', and CALIBRATED requires a fitted calibration (Fit calibration; ' . $usableSamples . ' of ' . $minimum . ' usable settled samples)' : '') . '.',
            'status' => $status, 'version' => $version, 'usableSamples' => $usableSamples, 'minimum' => $minimum];
    }

    /** @return array<string,mixed> */
    public function providerStatus(): array
    {
        $status = $this->gateway()->status();
        return $status + ['configured' => $this->gateway()->configured(), 'capabilities' => $this->gateway()->capabilities(), 'demoMode' => $this->config->demoMode()];
    }

    /**
     * Feature + quality view for one fixture (the `/matches/:id/analysis` read
     * model). Never predicts, so it is safe to call for any stored fixture.
     */
    public function analysis(int $fixtureId): array
    {
        $fixture = $this->repo->findFixtureById($fixtureId);
        if ($fixture === null) return ['status' => 'NOT_FOUND', 'fixtureId' => $fixtureId, 'dataState' => DataState::UNAVAILABLE];
        $features = $this->features()->build($fixture);
        $prediction = $this->repo->listPredictions(['fixtureId' => $fixtureId, 'kind' => PredictionService::KIND_PRE_MATCH], 1)[0] ?? null;
        return [
            'status' => 'OK',
            'fixture' => PredictionService::fixtureSummary($fixture),
            'teams' => $features['teams'],
            'competition' => $features['competition'],
            'headToHead' => $features['headToHead'],
            'inMatch' => $features['inMatch'] ?? null,
            'dataQuality' => $features['dataQuality'],
            'coverage' => $features['coverage'],
            'provenance' => $features['provenance'],
            'provider' => $features['provider'],
            'prediction' => $prediction === null ? null : $this->predictions()->contract($prediction, $fixture),
            // The same block the board and the match page read: the quality
            // checklist, the intelligence score and the drivers, assembled from
            // the stored row rather than recomputed from the fresh features — a
            // page that re-derived them would be able to disagree with itself.
            'intelligence' => $this->report()->forMatch($fixture, $prediction, []),
            'generatedAt' => gmdate('c'),
        ];
    }

    /**
     * The full intelligence block for one fixture: WINDELS' score, the data-quality
     * checklist, the drivers behind the selection, the fair-value comparison
     * against the quoted price, the stability verdict and the three clocks.
     *
     * It is a read model over stored rows — the same assembly the board uses for
     * its rows, so the match page and a page of fifty cannot disagree. Nothing
     * here fetches: an unpriced market stays unpriced and an unanalyzed fixture
     * stays unanalyzed, each stated as such.
     */
    public function intelligenceFor(int $fixtureId, ?string $marketKey = null, ?float $line = null, bool $generate = false): array
    {
        $fixture = $this->repo->findFixtureById($fixtureId);
        if ($fixture === null) {
            return ['state' => DataState::UNAVAILABLE, 'fixtureId' => $fixtureId,
                'message' => 'Fixture ' . $fixtureId . ' is not stored, so there is nothing to report on it.',
                'intelligence' => $this->report()->forMatch([], null, []), 'generatedAt' => gmdate('c')];
        }
        $model = $this->models()->usable();
        $modelVersionId = (int) ($model['model']['id'] ?? 0);
        $prediction = $this->repo->listPredictions(['fixtureId' => $fixtureId, 'kind' => PredictionService::KIND_PRE_MATCH], 1)[0] ?? null;
        if ($prediction === null && $generate) {
            $payload = $this->predictions()->predictFixture($fixtureId);
            if (($payload['status'] ?? '') === 'PREDICTED') {
                $prediction = $this->repo->listPredictions(['fixtureId' => $fixtureId, 'kind' => PredictionService::KIND_PRE_MATCH], 1)[0] ?? null;
            }
        }
        $notes = [];
        $market = $this->markets()->resolve($marketKey ?? $this->config->defaultMarket(), $notes);
        $block = $this->feed()->attachMarkets([['prediction' => $prediction,
            'matchId' => MatchFeed::matchId($fixture)]], $market['market'], $line)[0] ?? [];
        return [
            'state' => $prediction === null ? self::INTELLIGENCE_WITHHELD : 'AVAILABLE',
            'fixtureId' => $fixtureId,
            'matchId' => MatchFeed::matchId($fixture),
            'fixture' => PredictionService::fixtureSummary($fixture),
            // The evaluated market block, exactly as `attachMarkets` assembled it:
            // selection, probability, quoted price, implied probability, per-outcome
            // fair odds and edge. The view reads it rather than recomposing it, so a
            // price cannot be rounded one way in the table and another way in a card.
            'market' => $block + ['notes' => $notes, 'requestedLine' => $line],
            'prediction' => $prediction === null ? null : $this->predictions()->contract($prediction, $fixture),
            'intelligence' => $this->report()->forMatch($fixture, $prediction, (array) $block),
            'generatedAt' => gmdate('c'),
        ];
    }

    /**
     * The ranked "Top WINDELS Picks" reading of one page of a date, built by the
     * same pass that fills the board.
     *
     * Read-only by default, like every other read path. `$generate` is the
     * documented exception and follows the same rule as
     * `intelligenceFor(..., $generate)`: writing prediction rows is a managed
     * action even when it rides on a read endpoint, so the caller checks
     * `sports.manage` before passing it. Without it this method used to count
     * matches it had no way to analyze — reporting "considered 6 / eligible 0"
     * while being structurally unable to generate the six readings it had just
     * counted. The caller now chooses: report what is stored, or generate the
     * page's missing predictions (bounded by the configured batch) and rank
     * what that produced.
     */
    public function picks(string $date, int $page = 1, int $limit = MatchFeed::MAX_PAGE_SIZE, array $options = [],
        bool $generate = false): array
    {
        $board = $this->board()->forDate($date, $generate, $page, $limit, $options);
        return [
            'date' => (string) ($board['date'] ?? $date),
            'page' => (int) ($board['pagination']['page'] ?? $page),
            'market' => (array) ($board['market'] ?? []),
            'picks' => (array) ($board['picks']['picks'] ?? []),
            'rule' => (array) ($board['picks']['rule'] ?? []),
            'considered' => (int) ($board['picks']['considered'] ?? 0),
            'eligible' => (int) ($board['picks']['eligible'] ?? 0),
            'beyondList' => (int) ($board['picks']['beyondList'] ?? 0),
            'excluded' => (array) ($board['picks']['excluded'] ?? []),
            'state' => (string) ($board['picks']['state'] ?? DataState::UNAVAILABLE),
            // Whether this call generated anything, and what the page's
            // fixtures actually resolved to. A consumer reading "eligible 0"
            // can now tell a page nobody has analyzed (`generate=false`, every
            // slot notAttempted) from one the engine analyzed and refused
            // (withheld), instead of having to guess which of the two it got.
            'generated' => $generate,
            'pageOutcome' => (array) ($board['page'] ?? []),
            'disclaimer' => IntelligenceReport::PICKS_DISCLAIMER,
            'summary' => (array) ($board['intelligence'] ?? []),
            'generatedAt' => gmdate('c'),
        ];
    }

    /** Live + historical prediction rows for one fixture (§17 `/matches/:id/prediction`). */
    public function predictionFor(int $fixtureId, bool $generate = false): array
    {
        $fixture = $this->repo->findFixtureById($fixtureId);
        if ($fixture === null) return ['status' => 'NOT_FOUND', 'fixtureId' => $fixtureId, 'dataState' => DataState::UNAVAILABLE];
        if ($generate) {
            $payload = $this->predictions()->predictFixture($fixtureId);
            if (($payload['status'] ?? '') !== 'PREDICTED') {
                return ['status' => (string) ($payload['status'] ?? 'NO_PREDICTION'), 'code' => $payload['code'] ?? null,
                    'reason' => $payload['reason'] ?? null, 'reasoning' => $payload['reasoning'] ?? [],
                    'dataQuality' => $payload['dataQuality'] ?? null, 'model' => $payload['model'] ?? null,
                    'fixture' => PredictionService::fixtureSummary($fixture), 'generatedAt' => gmdate('c')];
            }
        }
        $rows = $this->repo->listPredictions(['fixtureId' => $fixtureId], 10);
        $preMatch = null; $preMatchRow = null; $liveRows = [];
        foreach ($rows as $row) {
            if ((string) ($row['prediction_kind'] ?? '') === PredictionService::KIND_LIVE) {
                $liveRows[] = $this->predictions()->contract($row, $fixture);
                continue;
            }
            $preMatch = $this->predictions()->contract($row, $fixture);
            // The raw stored row, kept alongside the contract: the intelligence
            // block reads the snapshot and evidence columns the contract does not
            // republish, and it must read them from the row that produced the
            // figures being explained.
            $preMatchRow = $row;
        }
        $settlement = $preMatch === null ? null : $this->repo->findSettlement((string) ($preMatch['predictionId'] ?? ''));
        $marketNotes = [];
        $marketEntry = [['prediction' => $preMatchRow, 'matchId' => MatchFeed::matchId($fixture)]];
        $marketBlock = $this->feed()->attachMarkets($marketEntry,
            $this->markets()->resolve($this->config->defaultMarket(), $marketNotes)['market'], null)[0] ?? [];
        // Match detail deliberately receives the full market catalogue. Unlike
        // the board's compact, priced-only preview, this makes every derivable
        // market visible and calls out missing provider prices as UNPRICED. It
        // is still one batched stored-data read — no match is regenerated and
        // no additional provider request is made.
        $allMarkets = $this->feed()->attachMultipleMarkets($marketEntry, null, false)[0] ?? [];
        return [
            'status' => $preMatch === null ? 'NO_PREDICTION' : 'OK',
            'fixture' => PredictionService::fixtureSummary($fixture),
            'prediction' => $preMatch,
            'liveEstimates' => $liveRows,
            'settlement' => $settlement,
            // The intelligence block for this one match, assembled by the same
            // report the board uses — not a second copy of the arithmetic.
            'intelligence' => $this->report()->forMatch($fixture, $preMatchRow, (array) $marketBlock),
            // The selected market shown in the at-a-glance intelligence block.
            'market' => (array) $marketBlock,
            // Every market on the dedicated match odds sheet. Modelled but
            // unpriced markets say so explicitly; provider-price-only markets
            // never pretend to carry a WINDELS probability.
            'markets' => array_values((array) $allMarkets),
            // Why the prediction is absent and how it can appear — the state
            // the match page's Prediction overview explains itself with
            // instead of a bare "no row is stored".
            'statusDetail' => $preMatch === null ? $this->predictionStatus($fixtureId, $fixture) : null,
            'message' => $preMatch === null ? 'No prediction row is stored for this fixture' . ($generate ? ' — it was analyzed and refused (see dataQuality)' : '.') : null,
            'generatedAt' => gmdate('c'),
        ];
    }

    /**
     * The prediction-overview state for one fixture — what the match page's
     * "Prediction overview" section explains its empty state with instead of
     * "No prediction row is stored for this fixture." Full stop. The row is
     * absent for exactly one of three reasons, and they are different facts:
     *
     *  - PREDICTED              — a stored pre-match row exists; it IS the
     *                             information (the caller renders it, not this)
     *  - WITHHELD_BY_QUALITY_GATE — the engine completed an assessment and its
     *                             evidence or model preconditions did not
     *                             support publishing (measured score + reason
     *                             carried). A finding, not an absence.
     *  - PRE_MATCH_CLOSED       — kickoff has passed or the fixture is
     *                             postponed/cancelled, so a pre-match
     *                             prediction can never be created again.
     *                             Nothing is back-filled.
     *  - AWAITING_ANALYSIS      — stored and still open, but no engine
     *                             assessment has run for it yet.
     *
     * Purely read-only: the stored rows and the engine's own closed-slot rule
     * (`PredictionService::refusal()`). No provider request, no generation.
     *
     * @param array<string,mixed>|null $fixture preloaded fixture row, when the caller already has it
     * @return array<string,mixed>
     */
    public function predictionStatus(int $fixtureId, ?array $fixture = null): array
    {
        $fixture ??= $this->repo->findFixtureById($fixtureId);
        if ($fixture === null) {
            return ['state' => 'NOT_FOUND', 'detail' => 'No fixture is stored under this id, so there is no prediction to read.',
                'fixtureId' => $fixtureId, 'generatedAt' => gmdate('c')];
        }
        $stored = $this->repo->listPredictions(['fixtureId' => $fixtureId, 'kind' => PredictionService::KIND_PRE_MATCH], 1);
        if ($stored !== []) {
            return ['state' => 'PREDICTED',
                'detail' => 'A pre-match prediction row is stored for this fixture and is rendered below, exactly as it was frozen.',
                'fixtureId' => $fixtureId, 'generatedAt' => gmdate('c')];
        }

        // The engine's own rule for a slot that can never be written.
        $refusal = $this->predictions()->refusal($fixture);
        // A durable assessment from an earlier generating request: the engine
        // answered and refused. Assessments belong to the model version that
        // made them — a stale refusal from an older model must not label the
        // current slot (same rule the board applies).
        $assessment = null;
        $modelVersionId = (int) ($this->models()->usable()['model']['id'] ?? 0);
        try {
            $row = $this->repo->listFixtureStatisticsFor([$fixtureId], PredictionService::ASSESSMENT_KIND)[$fixtureId] ?? null;
            $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
            if ($payload !== [] && (string) ($payload['status'] ?? '') === 'ASSESSED_NO_PREDICTION'
                && (int) ($payload['modelVersionId'] ?? 0) === $modelVersionId) {
                $assessment = $payload;
            }
        } catch (\Throwable $e) {
            $assessment = null;
        }

        $kickoff = (string) ($fixture['kickoff_at'] ?? '');
        $state = match (true) {
            $assessment !== null => 'WITHHELD_BY_QUALITY_GATE',
            $refusal !== null => 'PRE_MATCH_CLOSED',
            default => 'AWAITING_ANALYSIS',
        };
        $detail = match ($state) {
            'WITHHELD_BY_QUALITY_GATE' => 'This match WAS analyzed — the engine completed an assessment and published no prediction: '
                . (string) ($assessment['reason'] ?? 'its evidence or model preconditions did not support one.')
                . (is_numeric($assessment['dataQuality']['score'] ?? null)
                    ? ' Measured data quality: ' . (int) $assessment['dataQuality']['score'] . '/100'
                        . (($assessment['dataQuality']['band'] ?? '') !== '' ? ' (' . (string) $assessment['dataQuality']['band'] . ')' : '') . '.'
                    : '')
                . ($refusal !== null
                    ? ' The pre-match window has since closed, so this assessment stands as the record — nothing is back-filled.'
                    : ' Re-running the analysis after newer evidence is stored can publish a prediction; the button below re-asks the engine.'),
            'PRE_MATCH_CLOSED' => (string) ($refusal['reason'] ?? 'The pre-match window is closed for this fixture.')
                . ($kickoff !== '' ? ' Kickoff was ' . substr($kickoff, 0, 16) . ' UTC.' : '')
                . ' While the match is in play, live estimates are the in-play model\'s product (the Live match panel); once it finishes, the stored result is settlement\'s business.',
            default => 'This fixture is stored and still open, but no analysis has run for it yet. Analyze this match — generate odds prediction runs the model on the stored evidence (no provider request) and either publishes a prediction — qualified or on limited evidence — or withholds it with the measured reason. The scheduled predict job also picks up pending fixtures automatically.',
        };

        return [
            'state' => $state,
            'detail' => $detail,
            'fixtureId' => $fixtureId,
            'code' => $state === 'WITHHELD_BY_QUALITY_GATE' ? (string) ($assessment['code'] ?? 'NO_PREDICTION')
                : ($refusal['code'] ?? null),
            'dataQualityScore' => is_numeric($assessment['dataQuality']['score'] ?? null) ? (int) $assessment['dataQuality']['score'] : null,
            'kickoff' => $kickoff !== '' ? $kickoff : null,
            'assessmentAt' => $assessment !== null ? ($assessment['generatedAt'] ?? null) : null,
            'generatedAt' => gmdate('c'),
        ];
    }

    /**
     * Settlement-graded history for the history panel; graded rows only, and the
     * explicit empty state when there is nothing yet.
     */
    public function history(int $limit = 50, ?int $modelVersionId = null): array
    {
        $settled = $this->repo->listSettlements(array_filter(['modelVersionId' => $modelVersionId], static fn($v) => $v !== null), max(1, min(500, $limit)));
        $rows = [];
        foreach ($settled as $row) {
            $predictionId = (string) ($row['prediction_id'] ?? '');
            $prediction = $this->repo->findPrediction($predictionId) ?? [];
            $fixture = $this->repo->findFixtureById((int) ($row['fixture_id'] ?? 0)) ?? [];
            $rows[] = [
                'predictionId' => $predictionId,
                'fixture' => PredictionService::fixtureSummary($fixture),
                'predicted' => ['result' => (string) ($row['predicted_result'] ?? ''), 'score' => ['home' => $row['predicted_home_score'], 'away' => $row['predicted_away_score']],
                    'probabilities' => ['home' => $row['probability_home'], 'draw' => $row['probability_draw'], 'away' => $row['probability_away']],
                    'confidence' => $row['confidence'] ?? null, 'confidenceBasis' => $prediction['confidence_basis'] ?? 'RAW',
                    'dataQuality' => $row['data_quality_score'] ?? null,
                    'modelVersionId' => $row['model_version_id'] ?? null, 'calibrationVersionId' => $row['calibration_version_id'] ?? null],
                'actual' => ['score' => ['home' => $row['actual_home_score'], 'away' => $row['actual_away_score']], 'result' => (string) ($row['actual_result'] ?? ''), 'source' => (string) ($row['result_source'] ?? 'PROVIDER')],
                'correctResult' => $row['correct_result'] === null ? null : (int) $row['correct_result'] === 1,
                'correctExactScore' => $row['correct_exact_score'] === null ? null : (int) $row['correct_exact_score'] === 1,
                'brier' => $row['brier'] ?? null, 'logLoss' => $row['log_loss'] ?? null, 'absoluteGoalError' => $row['absolute_goal_error'] ?? null,
                'settledAt' => (string) ($row['settled_at'] ?? ''),
                'generatedAt' => (string) ($prediction['generated_at'] ?? ''),
            ];
        }
        return [
            'state' => $rows === [] ? PerformanceService::NO_DATA : 'MEASURED',
            'count' => count($rows),
            'rows' => $rows,
            'message' => $rows === [] ? PerformanceService::EMPTY_MESSAGE : null,
            'generatedAt' => gmdate('c'),
        ];
    }

    // ── write paths ───────────────────────────────────────────────────────────

    /**
     * Operator-triggered sync for a date. The request budget is deliberately
     * unbounded (-1) here — an explicit human action may walk the whole day —
     * while the minimum spacing between requests still applies, so even a
     * manual sweep cannot outrun the provider's rate limit.
     */
    public function syncDate(string $date, ?string $providerId = null): array
    {
        return $this->fixtures()->syncDay($date, 'manual:fixtures:' . $date . ':' . gmdate('Ymd\THis'), $providerId, -1);
    }

    public function syncLive(bool $force = true): array
    {
        return $this->fixtures()->syncLive(($force ? 'manual:live:' : 'live:') . gmdate('Ymd\THis'), null, $force ? -1 : null);
    }

    public function syncResults(int $limit = 120): array
    {
        return $this->fixtures()->syncResults('manual:results:' . gmdate('Ymd\THis'), null, $limit, -1);
    }

    /**
     * Operator-triggered odds sweep for a date: price every open fixture the
     * board is already holding, in one budgeted pass.
     *
     * `$force` re-requests even a price that is still inside its freshness
     * window, which is what an operator means by "refresh the prices now".
     * Without it the sweep reuses fresh quotes and spends nothing on them.
     */
    public function syncOddsForDay(string $date, bool $force = false, ?int $limit = null): array
    {
        // The operator-driven sweep is recorded exactly like the scheduled
        // one (jobType ODDS in the sync log), so the board's odds status can
        // say when a sweep last ran without caring which path triggered it.
        // A second click inside the same second still runs — it just shares
        // the run record, exactly like two concurrent cron ticks dedupe.
        $key = 'manual:ODDS:' . $date . ':' . gmdate('Ymd\This');
        $run = $this->repo->startSyncRun(['executionKey' => $key, 'jobType' => 'ODDS', 'windowStart' => $date, 'startedAt' => gmdate('c')]);
        $result = $this->oddsSheet()->refreshDay($date, $limit, $force);
        if ($run !== null) {
            $interval = $this->config()->refreshInterval('odds');
            $status = (string) ($result['status'] ?? 'COMPLETED');
            if ($status === 'FAILED') $interval = min($interval, RefreshPolicy::FAILED_RETRY_SECONDS);
            $this->repo->finishSyncRun($key, [
                'status' => $status,
                'processed' => (int) ($result['considered'] ?? $result['fixtures'] ?? 0),
                'requests' => (int) ($result['requests'] ?? 0),
                'errors' => (array) ($result['errors'] ?? []),
                'nextRunAt' => gmdate('c', time() + $interval),
            ]);
        }
        return $result;
    }

    /**
     * @param callable(object):array $fetch
     */
    public function syncWith(callable $fetch, string $jobType = 'FIXTURES', string $capability = 'fixtures', ?string $providerId = null): array
    {
        $date = gmdate('Y-m-d');
        return $this->fixtures()->sweep($jobType, $capability, $date, $date, 'manual:' . $jobType . ':' . gmdate('Ymd\THis'), $fetch, $providerId, -1);
    }

    public function collectStatisticsForDay(string $date, int $limit = 24): array
    {
        // The statistics job owns its provider budget. Without this the sweep
        // silently scavenged whatever the fixtures job had left in the same
        // process — and when statistics ran first (or alone, e.g. forced from
        // the console) the leftover budget was 0, so every standings, team and
        // head-to-head call died with REQUEST_BUDGET_EXHAUSTED and the job
        // reported DATA_UNAVAILABLE while the provider was perfectly healthy.
        $this->gateway()->beginSweep($this->config->requestBudget('statistics'));
        $fixtures = $this->repo->listFixtures(['date' => $date], max(1, min(200, $limit)));
        $errors = []; $leagues = []; $teams = 0; $h2h = 0; $requests = 0;
        foreach ($fixtures as $fixture) {
            $providerId = (int) ($fixture['provider_id'] ?? 0);
            $providerRow = null;
            foreach ($this->repo->listProviders() as $row) {
                if ((int) $row['id'] === $providerId) $providerRow = $row;
            }
            $providerCode = (string) ($providerRow['provider_code'] ?? '');
            if ($providerCode === '') continue;
            $league = (string) ($fixture['competition_external_id'] ?? '');
            $season = (string) ($fixture['season'] ?? $fixture['competition_season'] ?? '');
            if ($league !== '' && $season !== '') {
                $key = $providerCode . '|' . $league . '|' . $season;
                if (!isset($leagues[$key])) {
                    $result = $this->statistics()->collectLeagueStatistics($providerId, $providerCode, $league, $season);
                    $leagues[$key] = $result;
                    $teams += (int) ($result['teams'] ?? 0);
                    foreach ((array) ($result['errors'] ?? []) as $error) $errors[] = (string) $error;
                }
            }
            foreach (['home_team_id' => 'home_team', 'away_team_id' => 'away_team'] as $idKey => $nameKey) {
                $teamId = (string) ($fixture[$idKey] ?? '');
                if ($teamId === '' || $league === '' || $season === '') continue;
                $existing = $this->repo->findTeamStatistics($providerId, $teamId, $league, $season);
                if ($existing !== null && (string) ($existing['data_state'] ?? '') === DataState::AVAILABLE) continue;
                $fallback = $this->statistics()->collectTeamStatistics($providerId, $providerCode, $teamId, (string) ($fixture[$nameKey] ?? ''), $league, $season);
                if (($fallback['status'] ?? '') === 'COMPLETED') $teams++;
            }
            $headToHead = $this->statistics()->collectHeadToHead($providerId, $providerCode, $fixture);
            if (($headToHead['status'] ?? '') === 'COMPLETED') $h2h++;
        }
        $requests = $this->gateway()->requestsMade();
        return ['status' => $leagues === [] && $teams === 0 ? DataState::UNAVAILABLE : 'COMPLETED', 'date' => $date,
            'fixtures' => count($fixtures), 'leagues' => count($leagues), 'teamRows' => $teams, 'headToHead' => $h2h,
            'requests' => $requests, 'errors' => $errors, 'leagueResults' => $leagues];
    }

    public function settle(int $fixtureId): array
    {
        return $this->settlements()->settleFixture($fixtureId, 'manual:' . gmdate('Ymd\THi') . ':' . $fixtureId);
    }

    public function calibrate(?int $modelVersionId = null, string $actor = 'system'): array
    {
        if ($modelVersionId === null) {
            $usable = $this->models()->usable();
            $modelVersionId = (int) ($usable['model']['id'] ?? 0);
        }
        if ($modelVersionId <= 0) return ['status' => 'MODEL_NOT_LOADED', 'reason' => 'No model version is registered, so there is nothing to calibrate.'];
        return $this->calibration()->fit($modelVersionId, null, $actor);
    }

    public function approveModel(int $modelVersionId, string $actor, string $note = ''): array
    {
        return $this->models()->approve($modelVersionId, $actor, $note);
    }

    public function activateModel(int $modelVersionId, string $actor, string $note = ''): array
    {
        $result = $this->models()->activate($modelVersionId, $actor, $note);
        if (($result['status'] ?? '') === 'OK') {
            $report = $this->performance()->report(30, $modelVersionId);
            $this->performance()->snapshot(30, $modelVersionId);
            $result['report'] = $report;
        }
        return $result;
    }
}
