<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Football Intelligence JSON API (spec §17).
 *
 * Every response is assembled from stored football rows through the same
 * FootballIntelligence facade the console page uses, so a number can never
 * exist in one surface and not the other. Nothing here synthesizes a fixture,
 * a score, a probability, a model version or a performance figure: when the
 * provider has not delivered the underlying data the response says
 * DATA_UNAVAILABLE / LIMITED_DATA and the metric is null, never 0.
 *
 * Authorization follows the existing WINDELS RBAC matrix (the sports
 * capabilities govern the football feed because both read the same provider
 * registry):
 *   - provider/status           public (no secrets, no PII, no keys)
 *   - read endpoints            sports.view
 *   - sync / jobs / calibration sports.manage  (+ native session + CSRF)
 *   - model approve / activate  sports.approve
 *   - settlement                sports.settle
 */
class Api_football extends Api_controller
{
    private function football(): \AIWorkforce\Football\FootballIntelligence
    {
        return $this->platform->football;
    }

    // ------------------------------------------------------------ diagnostics

    /**
     * Public health summary: provider state, stored-data state, engine state and
     * model/calibration state. Contains no credentials — only statuses, counts and
     * the operator-facing reason strings.
     */
    public function provider_status()
    {
        $this->json($this->football()->providerStatus());
    }

    public function status()
    {
        $this->json($this->football()->providerStatus());
    }

    /** Full dashboard payload (board + diagnostics + performance + models). */
    public function dashboard()
    {
        if (!$this->requirePermission('sports.view', false)) return;
        $g = $this->input->get(NULL, true) ?: [];
        $notes = [];
        $date = \AIWorkforce\Football\RequestParams::date($g, 'date', gmdate('Y-m-d'), $notes);
        $refresh = !empty($g['refresh']);
        $payload = $this->football()->dashboard($date, $refresh);
        $payload['request'] = ['date' => $date, 'refresh' => $refresh, 'notes' => array_values($notes)];
        $this->json($payload);
    }

    // ---------------------------------------------------------------- fixtures

    /** Fixtures for a window: date, or from/to. No rows ⇒ DATA_UNAVAILABLE. */
    public function fixtures()
    {
        if (!$this->requirePermission('sports.view', false)) return;
        $g = $this->input->get(NULL, true) ?: [];
        $notes = [];
        $filter = array_intersect_key($g, array_flip(['providerId', 'status', 'matchState', 'competition', 'team']));
        $date = \AIWorkforce\Football\RequestParams::optionalDate($g, 'date', $notes);
        if ($date !== null) $filter['date'] = $date;
        // A window bound is only useful if the database can read it: an
        // unparseable `from` would silently match nothing and look like an empty
        // feed, so it is dropped with a note instead of being passed through.
        foreach (['from', 'to'] as $bound) {
            $value = \AIWorkforce\Football\RequestParams::timestamp($g, $bound, $notes);
            if ($value !== null) $filter[$bound] = $value;
        }
        $limit = \AIWorkforce\Football\RequestParams::int($g, 'limit', 200, 1, 500, $notes);
        $rows = $this->AIWorkforce_model->football->listFixtures($filter, $limit);
        $this->json([
            'state' => $rows === [] ? \AIWorkforce\Football\DataState::UNAVAILABLE : 'AVAILABLE',
            'requestedDate' => $date,
            'count' => count($rows),
            'fixtures' => array_map([$this, 'fixtureSummary'], $rows),
            'message' => $rows === [] ? 'No fixtures are stored for this window. Run a sync or wait for the scheduled refresh — nothing is invented here.' : null,
            // What the endpoint actually did with the query string. Without this a
            // typo'd parameter is indistinguishable from a deliberate one.
            'request' => ['filter' => $filter, 'limit' => $limit, 'notes' => array_values($notes)],
            'generatedAt' => gmdate('c'),
        ]);
    }

    /**
     * The paginated match feed (50 matches per page).
     *
     * `GET /api/football/matches?page=1&limit=50` reads the stored fixtures for
     * a date and returns one page with the predictions that already exist — it
     * never runs the engine and never touches the provider, so moving between
     * pages costs nothing. `?generate=1` (or the POST form below) asks for the
     * page's *missing* predictions to be created, up to a hard maximum of 50
     * new predictions per request; matches that already have one are returned,
     * not regenerated.
     *
     * `limit` is clamped server-side to `MatchFeed::MAX_PAGE_SIZE` (50) and the
     * clamp is reported in `request.notes`; no caller can ask for a thousand.
     */
    public function matches()
    {
        if (!$this->requirePermission('sports.view', false)) return;
        $g = $this->input->get(NULL, true) ?: [];
        $notes = [];
        $date = \AIWorkforce\Football\RequestParams::date($g, 'date', gmdate('Y-m-d'), $notes);
        $page = \AIWorkforce\Football\RequestParams::int($g, 'page', 1, 1, \AIWorkforce\Football\MatchFeed::MAX_PAGE, $notes);
        $limit = \AIWorkforce\Football\RequestParams::int($g, 'limit', \AIWorkforce\Football\MatchFeed::DEFAULT_PAGE_SIZE,
            1, \AIWorkforce\Football\MatchFeed::MAX_PAGE_SIZE, $notes);
        $generate = in_array(strtolower((string) ($g['generate'] ?? '')), ['1', 'true', 'yes'], true);
        // Generation writes prediction rows, so it is a managed action even
        // though it rides on a read endpoint — the same rule
        // `/matches/:id/prediction?generate=1` already follows.
        if ($generate && !$this->requirePermission('sports.manage', false)) return;
        $options = $this->feedOptions($g, $notes);
        $payload = $this->football()->feed()->page($date, $page, $limit, $generate, $options);
        $payload['request']['notes'] = array_values(array_merge($notes, (array) ($payload['request']['notes'] ?? [])));
        $payload['request']['limitMaximum'] = \AIWorkforce\Football\MatchFeed::MAX_PAGE_SIZE;
        $this->json($payload);
    }

    /**
     * The competitions a date can be narrowed to, listed from the rows the
     * provider sent, with the premium (featured) competition marked. Available
     * to any identity that may read the board: a league list is not a
     * privileged fact.
     */
    public function competitions()
    {
        if (!$this->requirePermission('sports.view', false)) return;
        $g = $this->input->get(NULL, true) ?: [];
        $notes = [];
        $date = \AIWorkforce\Football\RequestParams::date($g, 'date', gmdate('Y-m-d'), $notes);
        $providerId = \AIWorkforce\Football\RequestParams::int($g, 'providerId', 0, 0, 1000000, $notes) ?: null;
        $payload = $this->football()->competitions($date, $providerId);
        $payload['request'] = ['date' => $date, 'providerId' => $providerId, 'notes' => array_values($notes)];
        $payload['generatedAt'] = gmdate('c');
        $this->json($payload);
    }

    /**
     * The odds-prediction markets, with whether each one can currently be
     * answered. A market that has no stored input and no quoted price is
     * reported as DATA_UNAVAILABLE rather than left off the list.
     */
    public function markets()
    {
        if (!$this->requirePermission('sports.view', false)) return;
        $g = $this->input->get(NULL, true) ?: [];
        $notes = [];
        $date = \AIWorkforce\Football\RequestParams::date($g, 'date', gmdate('Y-m-d'), $notes);
        $available = $this->football()->markets()->available([]);
        $selected = $this->football()->markets()->resolve($g['market'] ?? null, $notes);
        $this->json([
            'status' => 'OK',
            'date' => $date,
            'defaultMarket' => $this->football()->config()->defaultMarket(),
            'selected' => $selected['key'],
            'markets' => $available,
            'total' => count($available),
            'note' => 'Selecting a market is a view over the stored prediction: it never regenerates a match and never costs a provider call.',
            'request' => ['date' => $date, 'market' => $g['market'] ?? null, 'notes' => array_values($notes)],
            'generatedAt' => gmdate('c'),
        ]);
    }

    /**
     * `competition` and `market` from a request. Both are selections over rows
     * that are already stored; an unusable value is reported through `$notes`
     * rather than silently becoming a different selection.
     *
     * @param array<string,mixed> $input
     * @param list<string> $notes
     * @return array<string,mixed>
     */
    private function feedOptions(array $input, array &$notes): array
    {
        $options = [];
        $competition = trim((string) ($input['competition'] ?? ''));
        if ($competition !== '') $options['competition'] = $competition;
        $market = trim((string) ($input['market'] ?? ''));
        if ($market !== '') $options['market'] = $market;
        if (isset($input['line']) && trim((string) $input['line']) !== '') {
            if (is_numeric($input['line'])) {
                $options['line'] = max(-10.0, min(10.0, (float) $input['line']));
            } else {
                $notes[] = 'line=' . \AIWorkforce\Football\RequestParams::preview($input['line'])
                    . ' is not a number; the market\'s own default line was used.';
            }
        }
        $providerId = \AIWorkforce\Football\RequestParams::int($input, 'providerId', 0, 0, 1000000, $notes);
        if ($providerId > 0) $options['providerId'] = $providerId;
        return $options;
    }

    /**
     * Generate the missing predictions for one page (sports.manage + CSRF) and
     * return that page. At most 50 new predictions are written per call, and a
     * match that already has one is never regenerated.
     */
    public function generate_matches()
    {
        if (!$this->requirePermission('sports.manage')) return;
        $body = array_merge($this->input->post() ?: [], $this->jsonBody());
        $notes = [];
        $date = \AIWorkforce\Football\RequestParams::date($body, 'date', gmdate('Y-m-d'), $notes);
        $page = \AIWorkforce\Football\RequestParams::int($body, 'page', 1, 1, \AIWorkforce\Football\MatchFeed::MAX_PAGE, $notes);
        $limit = \AIWorkforce\Football\RequestParams::int($body, 'limit', \AIWorkforce\Football\MatchFeed::DEFAULT_PAGE_SIZE,
            1, \AIWorkforce\Football\MatchFeed::MAX_PAGE_SIZE, $notes);
        $options = $this->feedOptions($body, $notes);
        $payload = $this->football()->feed()->generate($date, $page, $limit, $options);
        $payload['request']['notes'] = array_values(array_merge($notes, (array) ($payload['request']['notes'] ?? [])));
        $this->json($payload);
    }

    public function fixtures_today()
    {
        if (!$this->requirePermission('sports.view', false)) return;
        $this->listForDate(gmdate('Y-m-d'), 'today');
    }

    public function fixtures_tomorrow()
    {
        if (!$this->requirePermission('sports.view', false)) return;
        $this->listForDate(gmdate('Y-m-d', strtotime('+1 day')), 'tomorrow');
    }

    /**
     * The providers behind the "Data Provider" selector.
     *
     * `GET /api/football/providers` lists Auto / Smart, every configured feed
     * and — when more than one feed is connected — Multi-Provider, each with
     * the capabilities and health the choice was derived from. A mode nobody
     * can honour (Multi with one provider) is not offered.
     */
    public function providers()
    {
        if (!$this->requirePermission('sports.view', false)) return;
        $payload = $this->football()->intelligence()->providers();
        $payload['generatedAt'] = gmdate('c');
        $payload['note'] = 'Auto / Smart picks the provider per request from health, competition coverage, fixture availability, odds availability and rate limits. Multi-Provider takes each piece of data from the feed that has it and combines them into one match.';
        $this->json($payload);
    }

    /**
     * Per-provider health.
     *
     * `GET /api/football/providers/health` reports, for each feed: status,
     * response time, the last successful request, rate-limit state, available
     * competitions, whether odds and statistics are available, and what the
     * provider cannot supply. It is the record behind automatic fallback — a
     * feed that is offline or in backoff is skipped, not retried.
     */
    public function providers_health()
    {
        if (!$this->requirePermission('sports.view', false)) return;
        $this->json($this->football()->intelligence()->health());
    }

    /**
     * Matches read straight from the selected provider.
     *
     * `GET /api/football/matches/fetch?provider=AUTO&competition=39&dateFrom=…&dateTo=…&limit=50`
     * runs the documented pipeline — validate → load provider config →
     * retrieve fixtures → normalize → deduplicate → resolve the canonical match
     * — and returns the canonical matches with the provider (or providers) each
     * one came from. The same match seen by two feeds is returned once.
     *
     * This is the only football endpoint that spends provider calls; the paged
     * feed at `/api/football/matches` reads stored rows and costs none.
     */
    public function fetch_matches()
    {
        if (!$this->requirePermission('sports.view', false)) return;
        $g = $this->input->get(NULL, true) ?: [];
        $notes = [];
        $query = [
            'provider' => trim((string) ($g['provider'] ?? '')),
            'competition' => trim((string) ($g['competition'] ?? '')),
            'date' => trim((string) ($g['date'] ?? '')),
            'dateFrom' => trim((string) ($g['dateFrom'] ?? '')),
            'dateTo' => trim((string) ($g['dateTo'] ?? '')),
            'limit' => \AIWorkforce\Football\RequestParams::int($g, 'limit',
                \AIWorkforce\Football\MatchIntelligenceService::MAX_GENERATION_BATCH, 1,
                \AIWorkforce\Football\MatchIntelligenceService::MAX_GENERATION_BATCH, $notes),
        ];
        $result = $this->football()->intelligence()->matches($query, $notes);
        $this->json([
            'state' => $result['state'],
            'message' => $result['message'],
            'matches' => array_map(static fn($match): array => $match->toArray(), $result['matches']),
            'count' => $result['counts']['returned'],
            'counts' => $result['counts'],
            'selection' => $result['selection'],
            'calls' => $result['calls'],
            'request' => array_merge($query, ['notes' => array_values($notes)]),
            'limitMaximum' => \AIWorkforce\Football\MatchIntelligenceService::MAX_GENERATION_BATCH,
            'generatedAt' => gmdate('c'),
        ]);
    }

    /** Fixtures currently in play, with live score/minute/red cards as stored. */
    public function fixtures_live()
    {
        if (!$this->requirePermission('sports.view', false)) return;
        $board = $this->football()->live()->board(!empty($this->input->get('refresh')));
        $this->json($board);
    }

    /** One fixture: stored facts + derived analysis inputs. */
    public function show_match(string $id)
    {
        if (!$this->requirePermission('sports.view', false)) return;
        $fixture = $this->AIWorkforce_model->football->findFixtureById((int) $id);
        if ($fixture === null) {
            $this->json(['status' => 'NOT_FOUND', 'fixtureId' => (int) $id, 'dataState' => \AIWorkforce\Football\DataState::UNAVAILABLE,
                'message' => 'No fixture with that identifier is stored.'], 404);
            return;
        }
        $this->json([
            'status' => 'OK',
            'fixture' => $this->fixtureSummary($fixture),
            'statistics' => $this->AIWorkforce_model->football->findFixtureStatistics((int) $id),
            'prediction' => $this->football()->predictionFor((int) $id)['prediction'] ?? null,
            'generatedAt' => gmdate('c'),
        ]);
    }

    /** Feature + data-quality breakdown behind a prediction (§4/§5). */
    public function analysis(string $id)
    {
        if (!$this->requirePermission('sports.view', false)) return;
        $payload = $this->football()->analysis((int) $id);
        $this->json($payload, $payload['status'] === 'OK' ? 200 : 404);
    }

    /**
     * The stored prediction contract for a fixture (§20 shape). `generate=1`
     * asks the engine to analyze the fixture now — allowed only while kickoff is
     * in the future, and the refusal reason is returned instead of a prediction.
     */
    public function prediction(string $id)
    {
        if (!$this->requirePermission('sports.view', false)) return;
        $generate = ($this->input->get('generate') === '1' || $this->input->get('generate') === 'true');
        if ($generate && !$this->requirePermission('sports.manage')) return;
        $payload = $this->football()->predictionFor((int) $id, $generate);
        $this->json($payload, ($payload['status'] ?? '') === 'NOT_FOUND' ? 404 : 200);
    }

    // ------------------------------------------------------------- predictions

    /** Today's board: tiers, counts and per-fixture cards (§10/§11). */
    public function predictions_today()
    {
        if (!$this->requirePermission('sports.view', false)) return;
        $g = $this->input->get(NULL, true) ?: [];
        $notes = [];
        $date = \AIWorkforce\Football\RequestParams::date($g, 'date', gmdate('Y-m-d'), $notes);
        $refresh = !empty($g['refresh']);
        $board = $this->football()->board()->forDate($date, $refresh);
        $board['request'] = ['date' => $date, 'refresh' => $refresh, 'notes' => array_values($notes)];
        $this->json($board);
    }

    /** Settled prediction history (graded rows only, newest first). */
    public function predictions_history()
    {
        if (!$this->requirePermission('sports.view', false)) return;
        $g = $this->input->get(NULL, true) ?: [];
        $notes = [];
        $limit = \AIWorkforce\Football\RequestParams::int($g, 'limit', 50, 1, 200, $notes);
        $modelVersionId = \AIWorkforce\Football\RequestParams::int($g, 'modelVersionId', 0, 0, 1000000, $notes) ?: null;
        $payload = $this->football()->history($limit, $modelVersionId);
        $payload['request'] = ['limit' => $limit, 'modelVersionId' => $modelVersionId, 'notes' => array_values($notes)];
        $this->json($payload);
    }

    // -------------------------------------------------------------- performance

    /**
     * Rolling performance window computed by the database over settled
     * predictions. Empty history is an explicit state, not a zero (§2/§15).
     */
    public function performance()
    {
        if (!$this->requirePermission('sports.view', false)) return;
        $g = $this->input->get(NULL, true) ?: [];
        $notes = [];
        $days = \AIWorkforce\Football\RequestParams::int($g, 'days', 30, 1, 365, $notes);
        $modelVersionId = \AIWorkforce\Football\RequestParams::int($g, 'modelVersionId', 0, 0, 1000000, $notes) ?: null;
        $report = $this->football()->performance()->report($days, $modelVersionId);
        $report['request'] = ['days' => $days, 'modelVersionId' => $modelVersionId, 'notes' => array_values($notes)];
        $this->json($report);
    }

    // ---------------------------------------------------- models / calibration

    /** All stored model versions with their lifecycle state and metrics (§8). */
    public function models()
    {
        if (!$this->requirePermission('sports.view', false)) return;
        $summary = $this->football()->modelSummary();
        $this->json([
            'state' => $summary['state'],
            'label' => $summary['label'],
            'reason' => $summary['reason'],
            'active' => $summary['activeModel'],
            'models' => $summary['versions'],
            'generatedAt' => gmdate('c'),
        ]);
    }

    /** The model version a prediction would use right now, or why there isn't one. */
    public function models_active()
    {
        if (!$this->requirePermission('sports.view', false)) return;
        $usable = $this->football()->models()->usable();
        $this->json($usable);
    }

    /** Stored calibration versions (fitted parameters + reliability table). */
    public function calibrations()
    {
        if (!$this->requirePermission('sports.view', false)) return;
        $g = $this->input->get(NULL, true) ?: [];
        $notes = [];
        $modelVersionId = \AIWorkforce\Football\RequestParams::int($g, 'modelVersionId', 0, 0, 1000000, $notes) ?: null;
        if ($modelVersionId === null) {
            $modelVersionId = (int) ($this->football()->models()->usable()['model']['id'] ?? 0);
        }
        $versions = $modelVersionId > 0 ? $this->football()->calibration()->versions($modelVersionId) : [];
        $usable = null;
        foreach ($versions as $row) {
            if ((string) $row['status'] === \AIWorkforce\Football\CalibrationService::CALIBRATED) { $usable = $row; break; }
        }
        $this->json([
            'modelVersionId' => $modelVersionId > 0 ? $modelVersionId : null,
            'state' => $usable === null
                ? \AIWorkforce\Football\CalibrationService::PENDING
                : \AIWorkforce\Football\CalibrationService::CALIBRATED,
            'active' => $usable,
            'approvedCount' => $this->football()->calibration()->approvedCount(),
            'minimumSamples' => $this->football()->config()->minCalibrationSamples(),
            'samplesAvailable' => $versions[0]['samples'] ?? 0,
            'versions' => $versions,
            'message' => $usable === null
                ? 'Calibration is pending: it is fitted only once enough settled predictions with stored probabilities exist. Predictions made before that are labelled uncalibrated.'
                : null,
            'request' => ['modelVersionId' => $modelVersionId, 'notes' => array_values($notes)],
            'generatedAt' => gmdate('c'),
        ]);
    }

    // ── mutations (session + CSRF + permission) ─────────────────────────────────

    /** Refresh stored fixtures for a date from the provider (sports.manage). */
    public function sync()
    {
        if (!$this->requirePermission('sports.manage')) return;
        $body = $this->jsonBody();
        // A refresh is billed against a rate-limited feed, so an unreadable date
        // refuses the call instead of silently refreshing today.
        if (\AIWorkforce\Football\RequestParams::suppliedButInvalidDate($body)) {
            $this->jsonError('date=' . \AIWorkforce\Football\RequestParams::preview($body['date'])
                . ' is not a real YYYY-MM-DD calendar date; no provider request was made', 422);
            return;
        }
        $provider = $body['provider'] ?? null;
        if ($provider !== null && !is_string($provider) && !is_int($provider)) {
            $this->jsonError('provider must name one registered feed as a string', 422);
            return;
        }
        $notes = [];
        $date = \AIWorkforce\Football\RequestParams::date($body, 'date', gmdate('Y-m-d'), $notes);
        $providerId = $provider === null ? null : (string) $provider;
        $this->json([
            'sync' => $this->football()->syncDate($date, $providerId),
            'date' => $date,
            'request' => ['provider' => $providerId, 'notes' => array_values($notes)],
        ]);
    }

    /** Refresh live scores now (sports.manage). */
    public function sync_live()
    {
        if (!$this->requirePermission('sports.manage')) return;
        $this->json(['sync' => $this->football()->syncLive(true)]);
    }

    /** Pull final results and settle the fixtures that reported them (sports.settle). */
    public function settle()
    {
        if (!$this->requirePermission('sports.settle')) return;
        $body = $this->jsonBody();
        if (isset($body['fixtureId']) && is_numeric($body['fixtureId'])) {
            $this->json(['settlement' => $this->football()->settle((int) $body['fixtureId'])]);
            return;
        }
        $this->json(['sweep' => $this->football()->cron()->run('settle', null, true)]);
    }

    /** Fit a temperature from stored settlements (sports.manage). */
    public function calibrate()
    {
        $user = $this->requirePermission('sports.manage');
        if (!$user) return;
        $body = $this->jsonBody();
        $actor = (string) ($user['id'] ?? 'system');
        $modelVersionId = isset($body['modelVersionId']) && is_numeric($body['modelVersionId']) ? (int) $body['modelVersionId'] : null;
        $this->json(['calibration' => $this->football()->calibrate($modelVersionId, $actor)]);
    }

    /** Run one football refresh job now, bypassing cadence (sports.manage). */
    public function run_job(string $job)
    {
        if (!$this->requirePermission('sports.manage')) return;
        if (!in_array($job, \AIWorkforce\Football\FootballCronService::JOBS, true)) {
            $this->jsonError('unknown job. Valid: ' . implode(', ', \AIWorkforce\Football\FootballCronService::JOBS), 422);
            return;
        }
        $this->json(['result' => $this->football()->cron()->run($job, null, true)]);
    }

    /**
     * Approve a model version for use (sports.approve). The lifecycle guard in
     * ModelRegistry refuses an approval that has not been validated first — the
     * status is never written by fiat.
     */
    public function approve_model(string $id)
    {
        $this->transition((int) $id, 'approve');
    }

    public function activate_model(string $id)
    {
        $this->transition((int) $id, 'activate');
    }

    private function transition(int $modelVersionId, string $action)
    {
        $user = $this->requirePermission('sports.approve');
        if (!$user) return;
        $actor = (string) ($user['id'] ?? 'system');
        $note = (string) ($this->jsonBody()['note'] ?? '');
        try {
            $result = $action === 'approve'
                ? $this->football()->approveModel($modelVersionId, $actor, $note)
                : $this->football()->activateModel($modelVersionId, $actor, $note);
        } catch (\InvalidArgumentException $e) {
            $this->jsonError($e->getMessage(), 404);
            return;
        } catch (\Throwable $e) {
            $this->jsonError($e->getMessage(), 409);
            return;
        }
        $this->json(['result' => $result, 'modelVersionId' => $modelVersionId, 'action' => $action]);
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function listForDate(string $date, string $label)
    {
        $rows = $this->AIWorkforce_model->football->listFixtures(['date' => $date], 500);
        $this->json([
            'state' => $rows === [] ? \AIWorkforce\Football\DataState::UNAVAILABLE : 'AVAILABLE',
            'window' => $label,
            'date' => $date,
            'count' => count($rows),
            'fixtures' => array_map([$this, 'fixtureSummary'], $rows),
            'message' => $rows === [] ? 'No ' . $label . ' fixtures are stored yet. The refresh sweep will pull them once a provider is connected; nothing is invented here.' : null,
            'generatedAt' => gmdate('c'),
        ]);
    }

    /**
     * Stored fixture facts, keyed the way the contract keys them. Nulls stay null
     * — a missing score is not 0-0 and a missing league is not "Unknown".
     */
    private function fixtureSummary(array $fixture): array
    {
        return [
            'id' => (int) ($fixture['id'] ?? 0),
            'externalId' => $fixture['external_id'] ?? null,
            'provider' => $fixture['provider_code'] ?? null,
            'league' => $fixture['competition'] ?? null,
            'country' => $fixture['country'] ?? null,
            'season' => $fixture['season'] ?? $fixture['competition_season'] ?? null,
            'kickoff' => $fixture['kickoff_at'] ?? null,
            'homeTeam' => $fixture['home_team'] ?? null,
            'awayTeam' => $fixture['away_team'] ?? null,
            'status' => $fixture['status'] ?? null,
            'matchState' => $fixture['match_state'] ?? null,
            'minute' => $fixture['minute'] ?? null,
            'score' => isset($fixture['home_score']) || isset($fixture['away_score'])
                ? ['home' => isset($fixture['home_score']) ? (int) $fixture['home_score'] : null,
                    'away' => isset($fixture['away_score']) ? (int) $fixture['away_score'] : null]
                : null,
            'redCards' => ['home' => isset($fixture['home_red_cards']) ? (int) $fixture['home_red_cards'] : null,
                'away' => isset($fixture['away_red_cards']) ? (int) $fixture['away_red_cards'] : null],
            'extraMinute' => isset($fixture['extra_minute']) ? (int) $fixture['extra_minute'] : null,
            'venue' => $fixture['venue'] ?? null,
            'dataState' => $fixture['data_state'] ?? null,
            'fetchedAt' => $fixture['fetched_at'] ?? null,
            'settledAt' => $fixture['settled_at'] ?? null,
        ];
    }
}
