<?php
defined('BASEPATH') or exit('No direct script access allowed');
require_once APPPATH . 'core/App_Controller.php';

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
class Football extends App_Controller
{
    public function index()
    {
        $data = $this->base("Today's Football Predictions", 'football');
        $get = $this->input->get(NULL, true) ?: [];
        $notes = [];
        $date = \AIWorkforce\Football\RequestParams::date($get, 'date', gmdate('Y-m-d'), $notes);
        // A typo'd date must not be answered with a silently different day: the
        // page shows today, and says that is what it did and why.
        if ($notes !== []) $data['notice'] = trim(implode(' ', array_filter([(string) ($data['notice'] ?? ''), ...$notes])));
        $data['date'] = $date;
        $data['yesterday'] = gmdate('Y-m-d', strtotime($date . ' -1 day'));
        $data['tomorrow'] = gmdate('Y-m-d', strtotime($date . ' +1 day'));
        // `refresh=1` rebuilds the board from the rows already stored. It never
        // pulls the provider: that stays an explicit, permission-checked action.
        $data['refresh'] = !empty($get['refresh']);
        $data['dashboard'] = $this->platform->football->dashboard($date, $data['refresh']);
        $this->render('football/index', $data);
    }

    /**
     * Live view: the same board page, after one bounded live-score sweep.
     * It renders the identical view on purpose — a second "live" template would
     * be a second copy of the same figures, and the two would drift.
     */
    public function live()
    {
        if (!empty($this->footballCaps()['sync'])) {
            try {
                $this->platform->football->syncLive(true);
            } catch (Throwable $e) {
                $this->flash('error', 'Live refresh refused: ' . $e->getMessage());
            }
        } else {
            $this->flash('notice', 'Showing the stored live state. A live-score refresh needs sports.manage.');
        }
        redirect('/football');
    }

    /** One fixture: stored facts, features, data quality and the prediction. */
    public function match(string $id)
    {
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

    /** Model lifecycle + calibration state, straight from stored rows. */
    public function models()
    {
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
     * Rebuild today's board from stored data only. This never calls a provider:
     * analysis reads what the sync jobs stored, and fixtures that already kicked
     * off are refused rather than rewritten.
     */
    public function predict()
    {
        if ($this->input->method(true) !== 'POST') { redirect('/football'); return; }
        if (!$this->requireFootballPermission('sports.manage', 'board rebuild')) return;
        $supplied = $this->input->post('date');
        // Rebuilding writes prediction rows for the date it is given, so an
        // unreadable one must not be answered by picking a different day.
        if (\AIWorkforce\Football\RequestParams::suppliedButInvalidDate(['date' => $supplied])) {
            $this->flash('error', 'Board rebuild refused: date=' . \AIWorkforce\Football\RequestParams::preview($supplied)
                . ' is not a real YYYY-MM-DD calendar date, and no other day will be predicted in its place.');
            redirect('/football');
            return;
        }
        $date = \AIWorkforce\Football\RequestParams::date(['date' => $supplied], 'date', gmdate('Y-m-d'));
        try {
            $result = $this->platform->football->predictions()->predictDay($date);
            if (($result['status'] ?? '') === \AIWorkforce\Football\DataState::UNAVAILABLE) {
                $this->flash('error', (string) ($result['reason'] ?? 'No stored fixture exists for this date, so nothing can be analyzed.'));
            } else {
                $this->flash('notice', sprintf('Board rebuilt from stored data: %d fixture(s) analyzed — %d qualified, %d limited data, %d rejected on data quality (see Data feed).',
                    (int) ($result['analyzed'] ?? 0), (int) ($result['qualified'] ?? 0), (int) ($result['limited'] ?? 0), (int) ($result['rejected'] ?? 0)));
            }
        } catch (Throwable $e) {
            $this->flash('error', 'Prediction run refused: ' . $e->getMessage());
        }
        redirect('/football?date=' . $date);
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
