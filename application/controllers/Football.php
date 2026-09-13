<?php
defined('BASEPATH') or exit('No direct script access allowed');
require_once APPPATH . 'core/MY_Controller.php';

/**
 * Football Intelligence console.
 *
 * Renders exactly what the football repository stores — one panel per concern,
 * no metric duplicated across pages — and refuses to render a figure that has no
 * stored source. The JSON API (Api_football) reads through the same facade, so a
 * number on the page and a number in the API are literally the same call.
 *
 * Read pages follow the console's normal visibility; the mutations (sync, board
 * rebuild, settlement, calibration, model approval) enforce the RBAC matrix on
 * every request, plus the session CSRF token, because platform-wide CSRF is off
 * and privileged actions guard themselves.
 */
class Football extends MY_Controller
{
    /** Signed-in identity for this request, or null for a logged-out visitor. */
    protected ?array $identity = null;

    public function __construct()
    {
        parent::__construct();
        // Read pages render for everyone (logged-out visitors see the page
        // shell plus an in-page sign-in prompt); mutation actions still enforce
        // the football RBAC matrix per request.
        $this->identity = $this->optionalLogin();
    }

    /**
     * True and renders the gate when nobody is signed in. The page shell (the
     * global header + sidebar) is already emitted around the gate, so the
     * visitor lands on the correct destination with a clear sign-in call.
     */
    private function gateGuest(string $title): bool
    {
        if ($this->identity !== null) return false;
        $data = $this->base($title, 'football');
        $data['signInUrl'] = $this->signInUrl('/football');
        $data['gateTitle'] = 'Sign in to view Football Intelligence';
        $this->load->view('layout/header', $data);
        $this->load->view('partials/signin_gate', $data);
        $this->load->view('layout/footer');
        return true;
    }

    public function index()
    {
        if ($this->gateGuest("Today's Football Predictions")) return;
        $data = $this->base("Today's Football Predictions", 'football');
        $get = $this->input->get(NULL, true) ?: [];
        $notes = [];
        $date = \AIWorkforce\Football\RequestParams::date($get, 'date', gmdate('Y-m-d'), $notes);
        // The board is paged 50 matches at a time. `page` is clamped server-side
        // and the clamp is reported rather than applied silently — a pager that
        // quietly showed a different page is the same lie as a silently
        // reinterpreted date.
        $page = \AIWorkforce\Football\RequestParams::int($get, 'page', 1, 1, \AIWorkforce\Football\MatchFeed::MAX_PAGE, $notes);
        // A typo'd date must not be answered with a silently different day: the
        // page shows today, and says that is what it did and why.
        if ($notes !== []) $data['notice'] = trim(implode(' ', array_filter([(string) ($data['notice'] ?? ''), ...$notes])));
        $data['date'] = $date;
        $data['page'] = $page;
        $data['pageSize'] = $this->platform->football->config()->matchPageSize();
        $data['yesterday'] = gmdate('Y-m-d', strtotime($date . ' -1 day'));
        $data['tomorrow'] = gmdate('Y-m-d', strtotime($date . ' +1 day'));
        // `refresh=1` fills in the missing predictions for the page on screen,
        // from the rows already stored. It never pulls the provider: that stays
        // an explicit, permission-checked action.
        $data['refresh'] = !empty($get['refresh']);
        // Competition and market are selections over stored rows: narrowing the
        // page to one league or reading it in another market re-reads the same
        // predictions, so neither one regenerates a match.
        $competition = isset($get['competition']) ? trim((string) $get['competition']) : null;
        // The premium-league dropdown is a convenience view of the same
        // competition filter. Honour it even without JavaScript so the control
        // is a real form field rather than a visual-only selector.
        if (($competition === null || $competition === '') && isset($get['premium'])) {
            $premium = trim((string) $get['premium']);
            if ($premium !== '') $competition = $premium;
        }
        $market = isset($get['market']) ? trim((string) $get['market']) : null;
        $line = null;
        if (isset($get['line']) && trim((string) $get['line']) !== '') {
            if (is_numeric($get['line'])) {
                $line = max(-10.0, min(10.0, (float) $get['line']));
            } else {
                $notes[] = 'line=' . \AIWorkforce\Football\RequestParams::preview($get['line'])
                    . ' is not a number; the market\'s own default line was used.';
            }
        }
        // The data provider is a selection over the feeds that are connected:
        // Auto / Smart reads the provider whose health, coverage and quota say
        // it should answer, a named provider pins the whole request to it, and
        // Multi-Provider lets each piece of data come from the feed that has
        // it. The catalogue is what the dropdown is populated from — no mode is
        // offered that no connected feed can honour.
        //
        // Admin-controlled mode (Admin → System Settings → Football, AUTO by
        // default): in AUTO the selector is locked to Auto / Smart and any
        // operator-supplied provider is ignored; in MANUAL the operator may
        // choose, with the admin's manual default pre-selected.
        $providerMode = $this->platform->football->config()->providerMode();
        $providerLocked = $providerMode !== 'MANUAL';
        $provider = isset($get['provider']) ? trim((string) $get['provider']) : null;
        $providerRequested = (string) $provider;
        if ($providerLocked) {
            if ($provider !== null && $provider !== '' && strtoupper($provider) !== 'AUTO' && strtoupper($provider) !== 'SMART') {
                $lockNote = 'provider=' . \AIWorkforce\Football\RequestParams::preview($provider)
                    . ' was ignored: the administrator locked provider selection to Auto / Smart.';
                $notes[] = $lockNote;
                // The notice line was already assembled above; extend it so the
                // ignored override is reported rather than applied silently.
                $data['notice'] = trim((string) ($data['notice'] ?? '') . ' ' . $lockNote);
            }
            $provider = \AIWorkforce\Football\ProviderSelector::AUTO;
        } elseif (strtoupper($providerRequested) === \AIWorkforce\Football\ProviderSelector::ALL_PROVIDERS) {
            // All providers: no feed is pinned, so the board reads the stored
            // rows of every connected feed and each row names the feed behind
            // it. Distinct from AUTO, which is the routing mode a live sync or
            // fetch would use — on this read page both show the full board.
            $provider = \AIWorkforce\Football\ProviderSelector::ALL_PROVIDERS;
        } elseif ($provider === null || $provider === '') {
            $manualDefault = $this->platform->football->config()->manualProvider();
            $provider = $manualDefault !== '' ? $manualDefault : \AIWorkforce\Football\ProviderSelector::AUTO;
        }
        $data['provider'] = $provider;
        // The raw query value, kept apart from the resolved one: the view marks
        // the "All providers" option selected only when the operator actually
        // asked for no pinning — an absent parameter may still resolve to the
        // administrator's manual default provider.
        $data['providerRequested'] = $providerRequested;
        $data['providerMode'] = $providerMode;
        $data['providerLocked'] = $providerLocked;
        $providers = $this->platform->football->intelligence()->providers();
        if ($providerLocked && ($providers['options'] ?? []) !== []) {
            // Locked: offer only what is honoured — a dropdown full of modes
            // the backend would silently discard is a lie dressed as a choice.
            $providers['options'] = array_values(array_filter(
                (array) $providers['options'],
                static fn($o): bool => strtoupper((string) ($o['value'] ?? '')) === \AIWorkforce\Football\ProviderSelector::AUTO));
            $providers['default'] = \AIWorkforce\Football\ProviderSelector::AUTO;
        }
        $providers['mode'] = $providerMode;
        $providers['locked'] = $providerLocked;
        $data['providers'] = $providers;
        // The selection panel (Data Provider → Select Competition → Premium
        // League → Select Odds Prediction → Date) is an administrator concern.
        // Operators see the board only; the AUTO · managed by admin selectors
        // stay visible to administrators, who are the ones the backend honours.
        $data['isAdmin'] = $this->isAdmin($this->refreshIdentityPermissions($this->identity));
        $data['competition'] = $competition;
        $data['market'] = $market;
        $data['line'] = $line;
        $data['dashboard'] = $this->platform->football->dashboard($date, $data['refresh'], $page, (int) $data['pageSize'],
            ['competition' => $competition, 'market' => $market, 'line' => $line]);
        $this->render('football/index', $data);
    }

    /**
     * Legacy live-view shortcut.
     *
     * Viewing a page must never spend a provider request. The provider-aware
     * scheduler owns live sweeps, while the football page polls the stored live
     * endpoint automatically. Keep old bookmarks useful by taking them straight
     * to that panel without mutating state.
     */
    public function live()
    {
        redirect('/football#football-live-panel');
    }

    /** One fixture: stored facts, features, data quality and the prediction. */
    public function match(string $id)
    {
        if ($this->gateGuest('Football Match Analysis')) return;
        if (!ctype_digit($id)) { show_404(); return; }
        $fixtureId = (int) $id;
        $analysis = $this->platform->football->analysis($fixtureId);
        if (($analysis['status'] ?? '') !== 'OK') { show_404(); return; }
        $data = $this->base($this->headline($analysis), 'football');
        $data['analysis'] = $analysis;
        $data['prediction'] = $this->platform->football->predictionFor($fixtureId);
        $data['fixtureId'] = $fixtureId;
        $this->render('football/match', $data);
    }

    /**
     * Analyze one fixture now: run the prediction engine over its stored data
     * and store the result, then return to the match page (sports.manage).
     *
     * This is the per-match half of "Generate this page": the same engine, the
     * same stored row, the same contract — bounded to one match the operator
     * asked about. A match that already has a prediction keeps it (the engine
     * is never re-run over it here); a refusal comes back with its reason
     * instead of a silent redirect.
     */
    public function analyze(string $id)
    {
        if (!ctype_digit($id)) { show_404(); return; }
        $fixtureId = (int) $id;
        if ($this->input->method(true) !== 'POST') { redirect('/football/match/' . $fixtureId); return; }
        if (!$this->requireFootballPermission('sports.manage', 'match analysis')) return;
        try {
            // A match that already has a prediction keeps it: this action only
            // ever writes the missing row, so a stored (possibly frozen)
            // prediction is never rewritten by pressing Analyze twice.
            $existing = $this->platform->football->predictionFor($fixtureId, false);
            if (($existing['status'] ?? '') === 'NOT_FOUND') { show_404(); return; }
            if (($existing['prediction'] ?? null) !== null) {
                $this->flash('notice', 'This match already has a stored prediction — it was reused, not regenerated.');
                redirect('/football/match/' . $fixtureId);
                return;
            }
            $payload = $this->platform->football->predictionFor($fixtureId, true);
        } catch (Throwable $e) {
            $this->flash('error', 'Analysis refused: ' . $e->getMessage());
            redirect('/football/match/' . $fixtureId);
            return;
        }
        $status = (string) ($payload['status'] ?? 'NO_PREDICTION');
        if ($status === 'NOT_FOUND') { show_404(); return; }
        if ($status === 'OK' && ($payload['prediction'] ?? null) !== null) {
            $contract = $payload['prediction'];
            $p = $contract['prediction'] ?? [];
            $this->flash('notice', sprintf('Analyzed: %s %d–%d at %s%% confidence (%s, data quality %d/100). The odds prediction below is usable.',
                (string) ($p['result'] ?? '—'),
                (int) ($p['predictedScore']['home'] ?? 0), (int) ($p['predictedScore']['away'] ?? 0),
                is_numeric($p['confidence'] ?? null) ? number_format((float) $p['confidence'], 1) : '—',
                (string) ($p['confidenceBasis'] ?? 'RAW'),
                (int) ($contract['dataQuality']['score'] ?? 0)));
        } else {
            $reason = trim((string) ($payload['reason'] ?? 'the engine refused this match.'));
            $this->flash('error', 'No prediction was stored for this match — ' . rtrim($reason, '.') . '.');
        }
        redirect('/football/match/' . $fixtureId);
    }

    /** Model lifecycle + calibration state, straight from stored rows. */
    public function models()
    {
        if ($this->gateGuest('Football Models & Calibration')) return;
        $data = $this->base('Football Models & Calibration', 'football');
        $data['models'] = $this->platform->football->modelSummary();
        $data['performance'] = $this->platform->football->performance()->report(30);
        $this->render('football/models', $data);
    }

    /** Refresh fixtures for a date from the provider (sports.manage). */
    public function sync()
    {
        if ($this->input->method(true) !== 'POST') { redirect('/football'); return; }
        if (!$this->requireFootballPermission('sports.manage', 'sync')) return;
        @set_time_limit(180);
        $supplied = $this->input->post('date');
        // A refresh costs provider quota. A date that cannot be read must stop the
        // action, not quietly become "today": the operator asked for one day and
        // would otherwise watch a different one be billed for it.
        if (\AIWorkforce\Football\RequestParams::suppliedButInvalidDate(['date' => $supplied])) {
            $this->flash('error', 'Sync refused: date=' . \AIWorkforce\Football\RequestParams::preview($supplied)
                . ' is not a real YYYY-MM-DD calendar date. No provider request was made.');
            redirect('/football');
            return;
        }
        $date = \AIWorkforce\Football\RequestParams::date(['date' => $supplied], 'date', gmdate('Y-m-d'));
        try {
            $result = $this->platform->football->syncDate($date);
            $count = (int) ($result['processed'] ?? 0);
            $errors = (array) ($result['errors'] ?? []);
            if ($count === 0) {
                $this->flash('error', $this->emptySyncMessage($result));
            } else {
                $this->flash('notice', sprintf('Sync complete for %s: %d fixture(s) processed, %d provider request(s).%s',
                    $date, $count, (int) ($result['requests'] ?? 0),
                    $errors ? ' ' . count($errors) . ' provider warning(s) — see the Data feed panel.' : ''));
            }
        } catch (Throwable $e) {
            $this->flash('error', 'Sync refused: ' . $e->getMessage());
        }
        redirect('/football?date=' . $date);
    }

    /**
     * Generate the predictions that are missing for one page of matches — the
     * console form behind the "Generate this page" button.
     *
     * Three rules shape it, and they are the reason the module pages at all:
     * analysis reads only the rows the sync jobs already stored (no provider
     * call), only matches without a stored prediction are sent to the engine,
     * and one request never writes more than one page — 50 new predictions.
     * A match that already has one keeps it, so pressing the button twice in a
     * row does nothing the second time and says so.
     */
    public function predict()
    {
        if ($this->input->method(true) !== 'POST') { redirect('/football'); return; }
        if (!$this->requireFootballPermission('sports.manage', 'prediction generation')) return;
        $date = $this->postedDate();
        if ($date === null) return;
        $page = max(1, min(\AIWorkforce\Football\MatchFeed::MAX_PAGE, (int) $this->input->post('page')));
        $limit = (int) $this->platform->football->config()->matchPageSize();
        // The competition the page is narrowed to is part of the request: the
        // 50-match budget is spent inside the selected league, never across
        // every league the provider happens to have sent.
        $competition = trim((string) $this->input->post('competition')) ?: null;
        $market = trim((string) $this->input->post('market')) ?: null;
        try {
            $result = $this->platform->football->feed()->generate($date, $page, $limit,
                ['competition' => $competition, 'market' => $market]);
            $generation = (array) ($result['generation'] ?? []);
            $generated = (int) ($generation['generated'] ?? 0);
            $reused = (int) ($generation['reused'] ?? 0);
            $remaining = (int) ($generation['remainingOnDate'] ?? 0);
            $refused = (int) ($generation['refused'] ?? 0) + (int) ($generation['frozen'] ?? 0);
            $this->flash('notice', $generated === 0
                ? sprintf('Page %d of %s already had predictions for all %d of its matches — nothing was regenerated. %d match(es) on this date still have no prediction.',
                    $page, $date, $reused, $remaining)
                : sprintf('Generated %d new prediction(s) for page %d of %s; %d already-stored prediction(s) were reused, not regenerated. %d match(es) on this date still have no prediction%s.',
                    $generated, $page, $date, $reused, $remaining,
                    $refused > 0 ? ' (' . $refused . ' on this page were refused on data quality or kickoff — see the cards below)' : ''));
        } catch (Throwable $e) {
            $this->flash('error', 'Prediction generation refused: ' . $e->getMessage());
        }
        $query = ['date' => $date, 'page' => $page];
        if ($competition !== null) $query['competition'] = $competition;
        if ($market !== null) $query['market'] = $market;
        redirect('/football?' . http_build_query($query));
    }

    /** Pull final results and settle the fixtures that reported them (sports.settle). */
    public function settle()
    {
        if ($this->input->method(true) !== 'POST') { redirect('/football'); return; }
        if (!$this->requireFootballPermission('sports.settle', 'settlement')) return;
        try {
            $result = $this->platform->football->cron()->run('settle', null, true);
            $this->flash('notice', sprintf('Settlement sweep: %s — %d settled, %d waiting on a final score.',
                (string) ($result['status'] ?? 'SKIPPED'), (int) ($result['settled'] ?? 0), (int) ($result['waiting'] ?? 0)));
        } catch (Throwable $e) {
            $this->flash('error', 'Settlement refused: ' . $e->getMessage());
        }
        redirect('/football');
    }

    /** Fit a calibration from stored settlements (sports.manage). Refuses politely. */
    public function calibrate()
    {
        if ($this->input->method(true) !== 'POST') { redirect('/football/models'); return; }
        if (!$this->requireFootballPermission('sports.manage', 'calibration')) return;
        try {
            $result = $this->platform->football->calibrate(null, $this->actor());
            $this->flash(($result['status'] ?? '') === 'CALIBRATED' ? 'notice' : 'error',
                rtrim((string) ($result['reason'] ?? ($result['status'] ?? 'refused')), '.') . '.');
        } catch (Throwable $e) {
            $this->flash('error', 'Calibration refused: ' . $e->getMessage());
        }
        redirect('/football/models');
    }

    /** Approve / activate a model version (sports.approve). */
    public function decide(string $id)
    {
        if ($this->input->method(true) !== 'POST') { redirect('/football/models'); return; }
        if (!$this->requireFootballPermission('sports.approve', 'model approval')) return;
        if (!ctype_digit($id)) { redirect('/football/models'); return; }
        $modelVersionId = (int) $id;
        $activate = $this->input->post('activate') === '1';
        $note = trim((string) $this->input->post('note'));
        try {
            $result = $activate
                ? $this->platform->football->activateModel($modelVersionId, $this->actor(), $note)
                : $this->platform->football->approveModel($modelVersionId, $this->actor(), $note);
            $ok = (string) ($result['status'] ?? '') === 'OK';
            $this->flash($ok ? 'notice' : 'error', $ok
                ? sprintf('Model version #%d is now %s (audited against %s).', $modelVersionId, $activate ? 'ACTIVE' : 'APPROVED', $this->actor())
                : 'Refused: ' . (string) ($result['reason'] ?? 'the model lifecycle rejected this transition.'));
        } catch (Throwable $e) {
            $this->flash('error', 'Refused: ' . $e->getMessage());
        }
        redirect('/football/models');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * The date a POST asked for. An unreadable one refuses the action: writing
     * predictions for a different day than the operator named is worse than
     * writing none.
     */
    private function postedDate(): ?string
    {
        $supplied = $this->input->post('date');
        if (\AIWorkforce\Football\RequestParams::suppliedButInvalidDate(['date' => $supplied])) {
            $this->flash('error', 'Refused: date=' . \AIWorkforce\Football\RequestParams::preview($supplied)
                . ' is not a real YYYY-MM-DD calendar date, and no other day will be predicted in its place.');
            redirect('/football');
            return null;
        }
        return \AIWorkforce\Football\RequestParams::date(['date' => $supplied], 'date', gmdate('Y-m-d'));
    }

    private function headline(array $analysis): string
    {
        $fixture = $analysis['fixture'] ?? [];
        $home = (string) ($fixture['homeTeam'] ?? 'Away');
        $away = (string) ($fixture['awayTeam'] ?? 'Home');
        return $home . ' vs ' . $away;
    }

    /**
     * Why a sync returned nothing, in operator language. The provider was asked;
     * the answer comes back verbatim rather than as a generic failure.
     */
    private function emptySyncMessage(array $result): string
    {
        $reason = (string) ($result['reason'] ?? '');
        $status = (string) ($result['status'] ?? '');
        $errors = (array) ($result['errors'] ?? []);
        return match (true) {
            $reason === 'FOOTBALL_PROVIDER_NOT_CONFIGURED' => 'Football data provider not connected. Live fixtures and predictions are unavailable until a verified data source is configured.',
            $status === 'DUPLICATE_SKIPPED' => 'This sync was already run for the current window and its result is stored. Use a different date, or force the job from the Data feed panel.',
            $errors !== [] => 'Nothing was stored for this date — ' . mb_substr((string) $errors[0], 0, 180) . (count($errors) > 1 ? ' (' . count($errors) . ' provider messages total; see the Data feed panel.)' : '.'),
            default => 'The connected provider reported no fixtures for this date. Nothing is invented: the board stays empty until real fixtures arrive.',
        };
    }

    /**
     * Enforce the football RBAC matrix for console mutations (PRG flow). Form
     * POSTs carry the session CSRF token — the same token the JSON API verifies
     * as X-CSRF-Token — because platform-wide csrf_protection is off and
     * privileged endpoints guard themselves.
     */
    private function requireFootballPermission(string $permission, string $action): bool
    {
        // A logged-out visitor cannot perform a mutation: send them to sign in
        // and return to the football console afterwards, rather than showing a
        // permission-refusal that implies they merely lack a role.
        if ($this->identity === null && $this->currentUser() === null) {
            $this->session->set_userdata('return_to', '/football');
            redirect('/login');
            return false;
        }
        $user = $this->refreshIdentityPermissions($this->identity);
        if (!is_array($user) || !$this->platform->identity->can($user, $permission)) {
            $this->flash('error', "Refused: signed-in identity lacks '{$permission}' — the {$action} action was not performed."
                . " Ask an administrator to assign a role that carries '{$permission}' (the Sports administrator role grants it for the football console as well),"
                . " then retry — permissions are re-read from the database on every action, so no sign-out is needed.");
            redirect('/football');
            return false;
        }
        $sent = (string) $this->input->post('csrf_token');
        $known = $this->session->userdata('csrf_token');
        if ($sent === '' || !is_string($known) || $known === '' || !hash_equals($known, $sent)) {
            $this->flash('error', "Refused: missing or invalid CSRF token — the {$action} action was not performed.");
            redirect('/football');
            return false;
        }
        return true;
    }

    /** @return array{sync:bool,calibrate:bool,approve:bool,settle:bool} */
    private function footballCaps(): array
    {
        $user = $this->refreshIdentityPermissions($this->identity);
        $can = fn(string $permission): bool => $user !== null && $this->platform->identity->can($user, $permission);
        return ['sync' => $can('sports.manage'), 'calibrate' => $can('sports.manage'), 'approve' => $can('sports.approve'), 'settle' => $can('sports.settle')];
    }

    private function actor(): string
    {
        $user = $this->refreshIdentityPermissions($this->identity);
        return is_array($user) ? (string) $user['id'] : 'anonymous';
    }

    private function base(string $title, string $active): array
    {
        return [
            'title' => $title, 'active' => $active,
            'csrfToken' => (string) $this->session->userdata('csrf_token'),
            'caps' => $this->footballCaps(),
            'notice' => $this->flashGet('notice'), 'error' => $this->flashGet('error'),
        ];
    }

    private function render(string $view, array $data): void
    {
        $this->load->view('layout/header', $data);
        $this->load->view($view, $data);
        $this->load->view('layout/footer');
    }

    private function flash(string $key, string $msg): void
    {
        setcookie("flash_{$key}", rawurlencode($msg), time() + 30, '/');
    }

    private function flashGet(string $key): ?string
    {
        $v = $_COOKIE["flash_{$key}"] ?? null;
        if ($v !== null) setcookie("flash_{$key}", '', time() - 3600, '/');
        return $v === null ? null : rawurldecode($v);
    }
}
