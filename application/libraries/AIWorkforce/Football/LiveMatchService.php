<?php
namespace AIWorkforce\Football;

use AIWorkforce\Persistence\AuditRepository;
use AIWorkforce\Persistence\FootballRepository;

/**
 * Live match view.
 *
 * Two separate things are shown and never conflated:
 *  - `preMatchPrediction`: the frozen row that was written before kickoff, with
 *    the score/probabilities as they stood then. This class reads it and does not
 *    touch it (prediction persistence for a started match is refused upstream);
 *  - `liveModelEstimate`: a freshly computed estimate from the current stored
 *    state (score, minute, red cards, in-match statistics when the provider
 *    supplies them), stored as its own `LIVE` prediction row so the two can be
 *    compared afterwards.
 *
 * When the provider reports no live detail, the response says DATA_UNAVAILABLE —
 * the pre-match prediction is not silently re-labelled as a live estimate.
 */
final class LiveMatchService
{
    public function __construct(
        private FootballRepository $repo,
        private FeatureBuilder $features,
        private OutcomePredictor $predictor,
        private ModelRegistry $models,
        private PredictionService $predictions,
        private ?FixtureSyncService $sync = null,
        private ?AuditRepository $audit = null,
        private ?FootballConfiguration $config = null,
        private ?RefreshPolicy $policy = null,
    ) {}

    /**
     * The live board: every fixture the module currently believes is in play,
     * with its frozen pre-match prediction beside the live estimate.
     *
     * `$autoSweep` is what makes an open page self-sufficient: the read first
     * asks the provider for the current live snapshot **when that sweep is due**
     * (see `sweepIfDue()`), so a goal reaches the panel on the module's own live
     * cadence instead of waiting for an external scheduler that may not be
     * installed at all. It is deliberately separate from `$refresh`: `$refresh`
     * is the heavy operator view that also recomputes every live estimate.
     *
     * @return array{status:string, state:string, matches:list<array>, errors:list<string>, refreshed:?array, refreshIntervalSeconds:int, staleThresholdSeconds:int}
     */
    public function board(bool $refresh = true, bool $autoSweep = false): array
    {
        $errors = [];
        $refreshed = null;
        $sweep = null;
        if ($refresh && $this->sync !== null) {
            try {
                $refreshed = $this->sync->syncLive('live:' . gmdate('Ymd\TH:i'));
                if (($refreshed['status'] ?? '') === 'DEFERRED' || ($refreshed['status'] ?? '') === 'FAILED') {
                    $errors = array_merge($errors, array_map(static fn($e) => 'live sync: ' . (string) $e, (array) ($refreshed['errors'] ?? [])));
                }
            } catch (\Throwable $e) {
                $errors[] = 'live sync: ' . mb_substr($e->getMessage(), 0, 160);
            }
        } elseif ($autoSweep) {
            $sweep = $this->sweepIfDue();
            if (!empty($sweep['ran'])) {
                $refreshed = ['status' => $sweep['status'], 'processed' => $sweep['processed'] ?? 0,
                    'requests' => $sweep['requests'] ?? 0, 'expiredLive' => $sweep['expiredLive'] ?? 0];
            }
            // A sweep that could not speak to the provider is reported, not
            // hidden: the rows below are then the last confirmed ones, and the
            // page must be able to say so rather than implying they are current.
            foreach ((array) ($sweep['errors'] ?? []) as $error) {
                $errors[] = 'live sync: ' . (string) $error;
            }
        }
        $sweep ??= $this->sweepState();
        $fixtures = $this->currentLiveFixtures(200);
        if ($fixtures === []) {
            return [
                'status' => DataState::UNAVAILABLE,
                'state' => 'NO_LIVE_FIXTURES',
                'matches' => [],
                'errors' => $errors,
                'refreshed' => $refreshed === null ? null : ['status' => $refreshed['status'] ?? null, 'processed' => $refreshed['processed'] ?? 0, 'requests' => $refreshed['requests'] ?? 0, 'expiredLive' => $refreshed['expiredLive'] ?? 0],
                'refreshIntervalSeconds' => $this->refreshIntervalSeconds(),
                'staleThresholdSeconds' => $this->staleThresholdSeconds(),
                'provider' => $this->providerFreshness(),
                'sweep' => $sweep,
                'generatedAt' => gmdate('c'),
            ];
        }
        // Batch pre-load predictions: 1 query for all pre-match + 1 for live
        // instead of N*2 queries. On a 10-live-fixture dashboard this is
        // 2 queries vs 20, and avoids waking FeatureBuilder per row when
        // refresh==false (dashboard).
        $fixtureIds = array_map(static fn(array $f): int => (int) ($f['id'] ?? 0), $fixtures);
        $preMap = $this->repo->listPredictionsForFixtures($fixtureIds, PredictionService::KIND_PRE_MATCH, null);
        $liveMap = $this->repo->listPredictionsForFixtures($fixtureIds, PredictionService::KIND_LIVE, null);
        $matches = [];
        foreach ($fixtures as $fixture) {
            $fid = (int) ($fixture['id'] ?? 0);
            $pre = $preMap[$fid] ?? null;
            $liveRow = $liveMap[$fid] ?? null;
            if ($refresh) {
                // Full view (dedicated /football/live) — compute fresh estimate
                $matches[] = $this->matchView($fixture, $errors);
            } else {
                // Dashboard: no provider sync, no re-estimate — show stored rows
                $matches[] = $this->matchViewFast($fixture, $pre, $liveRow, $errors);
            }
        }
        return [
            'status' => $matches === [] ? DataState::UNAVAILABLE : 'OK',
            'state' => $matches === [] ? 'NO_LIVE_FIXTURES' : 'LIVE',
            'matches' => $matches,
            'errors' => $errors,
            'refreshed' => $refreshed === null ? null : ['status' => $refreshed['status'] ?? null, 'processed' => $refreshed['processed'] ?? 0, 'requests' => $refreshed['requests'] ?? 0, 'expiredLive' => $refreshed['expiredLive'] ?? 0],
            'refreshIntervalSeconds' => $this->refreshIntervalSeconds(),
            'staleThresholdSeconds' => $this->staleThresholdSeconds(),
            'provider' => $this->providerFreshness(),
            'sweep' => $sweep,
            'generatedAt' => gmdate('c'),
        ];
    }

    /**
     * Pull the provider's live snapshot **if the live cadence says it is due**,
     * and report what happened either way.
     *
     * This is the piece that makes "updates appear immediately after the
     * provider reports them" true for a page that is simply open. Before this
     * existed the browser polled an endpoint that only re-read stored rows, so
     * a goal was invisible until the external `football-live` cron ticked — and
     * on a host where that cron was never installed, invisible for good.
     *
     * Cost is bounded by exactly the same gates the scheduled job uses, so a
     * page open in fifty tabs cannot turn into fifty provider requests:
     *
     *  - RefreshPolicy decides due/not-due (live interval, provider backoff,
     *    deferral from the last run, whether any match can even be in play, and
     *    the request budget). A not-due read is a few indexed lookups;
     *  - the sweep claims an execution key bucketed to the live interval, and
     *    that key is UNIQUE, so of all the readers arriving in the same window
     *    exactly one performs the provider call and the rest are told
     *    DUPLICATE_SKIPPED by the database itself — no lock, no race;
     *  - a provider error is reported, never thrown at the reader.
     *
     * @return array{ran:bool, status:string, reason:string, ...}
     */
    public function sweepIfDue(): array
    {
        $state = $this->sweepState();
        if ($this->sync === null) return $state + ['ran' => false, 'status' => 'UNAVAILABLE', 'reason' => 'NO_SYNC_SERVICE'];
        if ($this->policy === null) return $state + ['ran' => false, 'status' => 'SKIPPED', 'reason' => 'NO_REFRESH_POLICY'];
        // Cheap cadence gate first. The browser polls the stored board every few
        // seconds, and the full policy evaluation counts live and soon-to-start
        // fixtures; running that on every poll would be real work to answer a
        // question one indexed lookup already settles. Only when the live window
        // has actually elapsed is the full evaluation (backoff, deferral, work,
        // budget) worth doing.
        $freshness = $this->providerFreshness();
        $age = $freshness['ageSeconds'];
        if (is_int($age) && $age < max(30, $this->refreshIntervalSeconds())) {
            return $state + ['ran' => false, 'status' => 'SKIPPED', 'reason' => 'CADENCE',
                'lastSweepAt' => $freshness['lastSweepAt']];
        }
        try {
            $evaluation = $this->policy->evaluate('football-live');
        } catch (\Throwable $e) {
            return $state + ['ran' => false, 'status' => 'SKIPPED', 'reason' => 'POLICY_ERROR',
                'errors' => [mb_substr($e->getMessage(), 0, 160)]];
        }
        if (empty($evaluation['due'])) {
            return $state + ['ran' => false, 'status' => 'SKIPPED', 'reason' => (string) ($evaluation['reason'] ?? 'NOT_DUE'),
                'nextRunAt' => $evaluation['nextRunAt'] ?? null];
        }
        // One key per live window. Identical to the scheduled job's key shape
        // (FootballCronService::run), so a browser-driven sweep and a cron tick
        // inside the same window dedupe against each other instead of doubling
        // the provider spend.
        $interval = max(30, $this->refreshIntervalSeconds());
        $key = 'live:' . intdiv(time(), $interval);
        try {
            $result = $this->sync->syncLive($key);
        } catch (\Throwable $e) {
            // A provider failure must never break the page that is reading the
            // board: the stored rows below are still served, with the error.
            return $this->sweepState() + ['ran' => false, 'status' => 'FAILED', 'reason' => 'SYNC_THREW',
                'errors' => [mb_substr($e->getMessage(), 0, 160)]];
        }
        $status = (string) ($result['status'] ?? 'UNKNOWN');
        return $this->sweepState() + [
            // DUPLICATE_SKIPPED means another reader (or the cron) already did
            // this window's work — the rows are current, this reader just did
            // not pay for them.
            'ran' => $status !== 'DUPLICATE_SKIPPED',
            'status' => $status,
            'reason' => $status === 'DUPLICATE_SKIPPED' ? 'ALREADY_SWEPT_THIS_WINDOW' : 'DUE',
            'processed' => (int) ($result['processed'] ?? 0),
            'requests' => (int) ($result['requests'] ?? 0),
            'expiredLive' => (int) ($result['expiredLive'] ?? 0),
            'errors' => array_values((array) ($result['errors'] ?? [])),
        ];
    }

    /**
     * How the live sweep is being driven, so the page can explain itself.
     *
     * `mode` answers the question a reader actually has when a panel says it is
     * automatic: is anything actually fetching? PAGE means this request will
     * pull when due; SCHEDULER means only the external job does.
     *
     * @return array{mode:string, intervalSeconds:int}
     */
    private function sweepState(): array
    {
        return [
            'mode' => ($this->sync !== null && $this->policy !== null) ? 'PAGE' : 'SCHEDULER',
            'intervalSeconds' => $this->refreshIntervalSeconds(),
        ];
    }

    /**
     * Whether the provider live sweep itself is current.
     *
     * The panel polls stored rows, so "no live match" is only trustworthy when
     * the sweep behind those rows actually ran. If it last ran hours ago, the
     * page must say the feed is behind rather than quietly implying that
     * nothing is being played anywhere.
     *
     * @return array{lastSweepAt:?string, ageSeconds:?int, state:string, status:?string}
     */
    private function providerFreshness(): array
    {
        try {
            $run = $this->repo->lastSyncRun('LIVE');
        } catch (\Throwable $e) {
            $run = null;
        }
        $startedAt = is_array($run) ? (string) ($run['started_at'] ?? '') : '';
        $started = $startedAt !== '' ? strtotime($startedAt) : false;
        if ($started === false) {
            return ['lastSweepAt' => null, 'ageSeconds' => null, 'state' => 'NEVER_RUN', 'status' => null];
        }
        $age = max(0, time() - $started);
        // One missed cadence is normal jitter; the stale window is the point at
        // which a card would no longer be trusted, so the sweep is judged the
        // same way the rows it writes are.
        $state = $age <= $this->staleThresholdSeconds() ? 'CURRENT' : 'BEHIND';
        return [
            'lastSweepAt' => gmdate('c', $started),
            'ageSeconds' => $age,
            'state' => $state,
            'status' => is_array($run) ? (string) ($run['status'] ?? '') : null,
        ];
    }

    /** Provider poll interval mirrored in the live API so the browser can display the cadence. */
    public function refreshIntervalSeconds(): int
    {
        return $this->config?->refreshInterval('live') ?? 90;
    }

    /**
     * How long a live row may remain visible without fresh provider confirmation.
     * A stale row is not deleted — it is taken out of the in-play status and is
     * no longer allowed to fill the Live Match section until a provider live
     * snapshot reports it live again.
     *
     * Measured against `live_confirmed_at` (the last live snapshot that actually
     * listed the fixture), never against `updated_at`.
     */
    public function staleThresholdSeconds(): int
    {
        return max(300, min(600, $this->refreshIntervalSeconds() * 3));
    }

    /**
     * @return list<array<string,mixed>> live-status fixtures still confirmed recently
     */
    private function currentLiveFixtures(int $limit): array
    {
        $now = time();
        $threshold = $this->staleThresholdSeconds();
        $fixtures = [];
        $stale = false;
        foreach ($this->repo->listFixtures(['status' => FixtureSyncService::LIVE_STATUSES], max(1, min(500, $limit))) as $fixture) {
            $status = strtoupper((string) ($fixture['status'] ?? ''));
            if (!in_array($status, FixtureSyncService::LIVE_STATUSES, true)) continue;
            // Freshness is measured from the last provider LIVE confirmation and
            // from nothing else. `updated_at` moves whenever any unrelated write
            // touches the row — a day sweep, a statistics collection, a
            // competition link — so a match that ended hours ago could keep
            // renewing its own place on the panel. A row that was never
            // confirmed live has no business filling Live Match at all.
            $confirmedAt = (string) ($fixture['live_confirmed_at'] ?? '');
            $confirmed = $confirmedAt !== '' ? strtotime($confirmedAt) : false;
            if ($confirmed === false || ($now - $confirmed) > $threshold) { $stale = true; continue; }
            $fixtures[] = $fixture;
            if (count($fixtures) >= $limit) break;
        }
        // Hiding a stale card is not enough: the row itself must leave the live
        // set, otherwise it keeps costing the live sweep and the settlement
        // queue work, and reappears the moment the freshness maths shifts.
        if ($stale) {
            try {
                $this->repo->expireStaleLiveFixtures(gmdate('c', $now - $threshold), gmdate('c'));
            } catch (\Throwable $e) {
                // Read paths never fail on a housekeeping write: the card is
                // already withheld above, which is what the reader sees.
            }
        }
        return $fixtures;
    }

    /**
     * One match, fully described. Public so `/matches/:id` can reuse it for a
     * fixture that is no longer live (then `live` reports what is missing).
     *
     * @return array<string,mixed>
     */
    public function matchView(array $fixture, array &$errors = []): array
    {
        $fixtureId = (int) $fixture['id'];
        $preMatch = $this->repo->listPredictions(['fixtureId' => $fixtureId, 'kind' => PredictionService::KIND_PRE_MATCH], 1)[0] ?? null;
        $liveRow = $this->repo->listPredictions(['fixtureId' => $fixtureId, 'kind' => PredictionService::KIND_LIVE], 1)[0] ?? null;
        $state = $this->matchState($fixture);
        $view = [
            'fixture' => PredictionService::fixtureSummary($fixture),
            'live' => $state,
            'preMatchPrediction' => $preMatch === null ? null : $this->predictions->contract($preMatch, $fixture),
            'preMatchPredictionState' => $preMatch === null ? 'NOT_STORED' : (string) ($preMatch['settlement_state'] ?? 'OPEN'),
            'liveModelEstimate' => null,
            'providers' => ['code' => (string) ($fixture['provider_code'] ?? 'DATA_UNAVAILABLE')],
        ];
        if ($liveRow !== null) $view['liveModelEstimate'] = $this->predictions->contract($liveRow, $fixture);
        if ($state['state'] === DataState::UNAVAILABLE) {
            $view['liveModelEstimate'] = ['state' => DataState::UNAVAILABLE, 'reason' => $state['reason']];
            return $view;
        }
        if ($state['state'] === 'PRE_MATCH' || $state['state'] === 'COMPLETED') {
            // Nothing to estimate: the match either has not started or is final,
            // and in both cases the frozen pre-match prediction above is the
            // authoritative record. Recomputing here would put a scoreless label
            // on numbers that were never conditioned on the current state.
            $view['liveModelEstimate'] = ['state' => $state['state'] === 'PRE_MATCH' ? 'MATCH_NOT_STARTED' : 'MATCH_COMPLETED',
                'reason' => $state['state'] === 'PRE_MATCH'
                    ? 'The match has not kicked off, so there is no live state to estimate from.'
                    : 'The match is final; settlement holds the comparison with the pre-match prediction.',
                'score' => $state['score']];
            return $view;
        }
        $estimate = $this->estimate($fixture);
        if (($estimate['status'] ?? '') === 'PREDICTED') {
            $view['liveModelEstimate'] = [
                'result' => $estimate['result'],
                'resultLabel' => $estimate['resultLabel'],
                'probabilities' => $estimate['probabilities'],
                'rawProbabilities' => $estimate['rawProbabilities'],
                'mostLikelyScore' => $estimate['predictedScore'],
                'alternativeScores' => $estimate['alternativeScores'],
                'expectedTotalGoals' => $estimate['expectedTotalGoals'],
                'confidence' => $estimate['confidence'],
                'confidenceBasis' => $estimate['confidenceBasis'],
                'calibrationState' => $estimate['calibrationState'],
                'dataQuality' => $estimate['dataQuality'],
                'reasoning' => $estimate['reasoning'],
                'generatedAt' => $estimate['generatedAt'],
                'state' => 'ESTIMATE',
            ];
            $this->store($fixture, $estimate, $liveRow);
        } else {
            $view['liveModelEstimate'] = ['state' => (string) ($estimate['code'] ?? 'NO_ESTIMATE'), 'reason' => (string) ($estimate['reason'] ?? 'the live estimate could not be computed from stored data')];
        }
        return $view;
    }

    /**
     * Dashboard fast path: same shape as matchView but without FeatureBuilder
     * or predictor work — reads only the stored pre-match and live rows.
     * Used by FootballIntelligence::dashboard (refresh=false) to avoid N
     * FeatureBuilder::build calls on every page view.
     */
    public function matchViewFast(array $fixture, ?array $preMatch, ?array $liveRow, array &$errors = []): array
    {
        $state = $this->matchState($fixture);
        $view = [
            'fixture' => PredictionService::fixtureSummary($fixture),
            'live' => $state,
            'preMatchPrediction' => $preMatch === null ? null : $this->predictions->contract($preMatch, $fixture),
            'preMatchPredictionState' => $preMatch === null ? 'NOT_STORED' : (string) ($preMatch['settlement_state'] ?? 'OPEN'),
            'liveModelEstimate' => null,
            'providers' => ['code' => (string) ($fixture['provider_code'] ?? 'DATA_UNAVAILABLE')],
        ];
        if ($liveRow !== null) $view['liveModelEstimate'] = $this->predictions->contract($liveRow, $fixture);
        if ($state['state'] === DataState::UNAVAILABLE) {
            $view['liveModelEstimate'] = ['state' => DataState::UNAVAILABLE, 'reason' => $state['reason']];
            return $view;
        }
        if ($state['state'] === 'PRE_MATCH' || $state['state'] === 'COMPLETED') {
            $view['liveModelEstimate'] = ['state' => $state['state'] === 'PRE_MATCH' ? 'MATCH_NOT_STARTED' : 'MATCH_COMPLETED',
                'reason' => $state['state'] === 'PRE_MATCH'
                    ? 'The match has not kicked off, so there is no live state to estimate from.'
                    : 'The match is final; settlement holds the comparison with the pre-match prediction.',
                'score' => $state['score']];
            return $view;
        }
        // IN_PLAY but dashboard refresh==false: return stored estimate if present,
        // otherwise report that no estimate is stored without building a new one.
        if ($view['liveModelEstimate'] !== null) return $view;
        $view['liveModelEstimate'] = ['state' => DataState::UNAVAILABLE, 'reason' => $state['reason'] ?? 'no live estimate is stored'];
        return $view;
    }

    /**
     * Live estimate for the current state of a match: the remaining-minute
     * residual of the score model, conditioned on the goals already scored. This
     * is deliberately a *state estimate*, not a re-prediction, and it is never
     * written over the pre-match row.
     */
    public function estimate(array $fixture): array
    {
        $model = $this->models->usable();
        $features = $this->features->build($fixture);
        $payload = $this->predictor->predict($features, $model['state'] === 'NONE' ? null : $model['model'], true);
        $payload['fixture'] = PredictionService::fixtureSummary($fixture);
        $payload['model']['state'] = (string) $model['state'];
        $payload['model']['label'] = (string) $model['label'];
        return $payload;
    }

    /**
     * @return array{state:string, reason:?string, minute:?int, score:array<string,mixed>, redCards:array<string,mixed>, elapsed:bool}
     */
    private function matchState(array $fixture): array
    {
        $status = strtoupper((string) ($fixture['status'] ?? ''));
        $minute = $fixture['minute'] ?? null;
        $hasScore = isset($fixture['home_score'], $fixture['away_score']) && $fixture['home_score'] !== null;
        if ($status === 'UNKNOWN') {
            return ['state' => DataState::UNAVAILABLE, 'reason' => 'The provider did not report a match status for this fixture.', 'minute' => null, 'score' => null, 'redCards' => null, 'elapsed' => false];
        }
        if ($status === 'FINISHED') {
            return ['state' => 'COMPLETED', 'reason' => null, 'minute' => is_numeric($minute) ? (int) $minute : 90,
                'score' => $hasScore ? ['home' => (int) $fixture['home_score'], 'away' => (int) $fixture['away_score']] : null,
                'redCards' => ['home' => $fixture['home_red_cards'] ?? null, 'away' => $fixture['away_red_cards'] ?? null], 'elapsed' => true];
        }
        if (!in_array($status, FixtureSyncService::LIVE_STATUSES, true)) {
            return ['state' => 'PRE_MATCH', 'reason' => 'The match has not started (status ' . $status . ').', 'minute' => is_numeric($minute) ? (int) $minute : null,
                'score' => $hasScore ? ['home' => (int) $fixture['home_score'], 'away' => (int) $fixture['away_score']] : null,
                'redCards' => ['home' => $fixture['home_red_cards'] ?? null, 'away' => $fixture['away_red_cards'] ?? null], 'elapsed' => false];
        }
        $liveLabel = $status === 'LIVE' ? 'IN_PLAY' : $status;
        if (!is_numeric($minute)) {
            return ['state' => DataState::LIMITED, 'reason' => 'The match is live but the provider reported no minute, so elapsed time is unavailable.',
                'minute' => null, 'score' => $hasScore ? ['home' => (int) $fixture['home_score'], 'away' => (int) $fixture['away_score']] : ['home' => DataState::UNAVAILABLE, 'away' => DataState::UNAVAILABLE],
                'redCards' => ['home' => $fixture['home_red_cards'] ?? null, 'away' => $fixture['away_red_cards'] ?? null], 'elapsed' => false];
        }
        return ['state' => $liveLabel, 'reason' => null, 'minute' => (int) $minute,
            'score' => $hasScore ? ['home' => (int) $fixture['home_score'], 'away' => (int) $fixture['away_score']] : ['home' => DataState::UNAVAILABLE, 'away' => DataState::UNAVAILABLE],
            'redCards' => ['home' => $fixture['home_red_cards'] ?? null, 'away' => $fixture['away_red_cards'] ?? null],
            'elapsed' => in_array($status, ['EXTRA_TIME', 'PENALTIES'], true) || (int) $minute >= 90];
    }

    /**
     * Persist a live estimate as its own row. It references the pre-match row it
     * superseded for display purposes only — the pre-match row is never modified,
     * and a settled pre-match row is immutable anyway.
     */
    private function store(array $fixture, array $estimate, ?array $existingLive): void
    {
        if (($fixture['match_state'] ?? '') === 'COMPLETED') return;   // settlement owns the final record
        $id = $this->predictions->predictionId($fixture, PredictionService::KIND_LIVE, (int) ($estimate['model']['modelVersionId'] ?? 0));
        if ($existingLive !== null && (string) ($existingLive['id'] ?? '') === $id) {
            // A live row is only rewritten while the match is still in play and
            // not yet settled; savePrediction refuses settled rows regardless.
            if ((string) ($existingLive['settlement_state'] ?? 'OPEN') !== 'OPEN') return;
        }
        $preMatch = $this->repo->listPredictions(['fixtureId' => (int) $fixture['id'], 'kind' => PredictionService::KIND_PRE_MATCH], 1)[0] ?? null;
        $this->repo->savePrediction([
            'id' => $id,
            'fixture_id' => (int) $fixture['id'],
            'provider_id' => (int) ($fixture['provider_id'] ?? 0),
            'model_version_id' => (int) ($estimate['model']['modelVersionId'] ?? 0) ?: null,
            'calibration_version_id' => $estimate['calibrationVersionId'] ?: null,
            'calibration_state' => (string) ($estimate['calibrationState'] ?? CalibrationService::PENDING),
            'prediction_kind' => PredictionService::KIND_LIVE,
            'supersedes_prediction_id' => $preMatch['id'] ?? null,
            'generated_at' => (string) ($estimate['generatedAt'] ?? gmdate('c')),
            'kickoff_at' => (string) ($fixture['kickoff_at'] ?? gmdate('c')),
            'status_at_prediction' => (string) ($fixture['status'] ?? 'LIVE'),
            'predicted_result' => (string) ($estimate['result'] ?? ''),
            'predicted_home_score' => (int) ($estimate['predictedScore']['home'] ?? 0),
            'predicted_away_score' => (int) ($estimate['predictedScore']['away'] ?? 0),
            'probability_home' => $estimate['probabilities']['home'] ?? null,
            'probability_draw' => $estimate['probabilities']['draw'] ?? null,
            'probability_away' => $estimate['probabilities']['away'] ?? null,
            'raw_home' => $estimate['rawProbabilities']['home'] ?? null,
            'raw_draw' => $estimate['rawProbabilities']['draw'] ?? null,
            'raw_away' => $estimate['rawProbabilities']['away'] ?? null,
            'expected_total_goals' => $estimate['expectedTotalGoals'] ?? null,
            'confidence' => $estimate['confidence'] ?? null,
            'confidence_basis' => (string) ($estimate['confidenceBasis'] ?? 'RAW'),
            'data_quality_score' => (int) ($estimate['dataQuality']['score'] ?? 0),
            'data_quality_band' => (string) ($estimate['dataQuality']['status'] ?? QualityBand::REJECTED),
            'quality_components' => json_encode($estimate['dataQuality']['components'] ?? []),
            'feature_snapshot' => json_encode(['liveMinute' => $fixture['minute'] ?? null, 'score' => ['home' => $fixture['home_score'] ?? null, 'away' => $fixture['away_score'] ?? null]]),
            'probabilities_matrix' => json_encode(['rows' => array_slice($estimate['matrix']['rows'] ?? [], 0, 10)]),
            'alternative_scores' => json_encode($estimate['alternativeScores'] ?? []),
            'reason' => mb_substr((string) ($estimate['reasoning'][0] ?? ''), 0, 600),
            'eligibility' => (string) ($estimate['dataQuality']['status'] ?? QualityBand::REJECTED),
            'settlement_state' => 'OPEN',
        ]);
        $this->audit?->emit('FOOTBALL_LIVE_ESTIMATE', 'Football live estimate updated for fixture ' . ($fixture['external_id'] ?? $fixture['id']), [
            'minute' => $fixture['minute'] ?? null, 'confidence' => $estimate['confidence'] ?? null, 'predictionId' => $id,
        ], 'system');
    }
}
