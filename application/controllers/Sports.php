<?php
defined('BASEPATH') or exit('No direct script access allowed');
require_once APPPATH . 'core/App_Controller.php';

/**
 * Sports Intelligence console (integration plan step 6 — dashboards,
 * responsive UI).
 *
 * Traditional MVC pages that render the same stored data the permissioned
 * JSON API exposes (no data fabrication in either path). Read pages are open
 * like the rest of the console; mutation actions enforce the sports RBAC
 * matrix (sports.approve / sports.settle) from the signed-in identity — the
 * same permission checks the API enforces, and the odds prediction ticket stays audited with
 * the acting user.
 */
class Sports extends App_Controller
{
    public function index()
    {
        $data = $this->base('Sports Intelligence', 'sports');
        $get = $this->input->get(NULL, true) ?: [];
        $notes = [];
        // The day the console reports (?date=YYYY-MM-DD, default today). A
        // typo'd date must not be answered with a silently different day: the
        // page shows the fallback day, and says that is what it did and why.
        $date = \AIWorkforce\Football\RequestParams::date($get, 'date', $this->ticketToday(), $notes);
        if ($notes !== []) $data['notice'] = trim(implode(' ', array_filter([(string) ($data['notice'] ?? ''), ...$notes])));
        $data['date'] = $date;
        $data['yesterday'] = gmdate('Y-m-d', strtotime($date . ' -1 day'));
        $data['tomorrow'] = gmdate('Y-m-d', strtotime($date . ' +1 day'));
        $data['isToday'] = ($date === $this->ticketToday());
        $data['dashboard'] = $this->platform->sports->dashboard($date);
        $this->render('sports/index', $data);
    }

    public function tickets()
    {
        $data = $this->base('🎯 Odds Prediction Tickets', 'sports');
        $data['tickets'] = $this->platform->model->sports->listTickets([], 100);
        $data['dailyRuns'] = $this->platform->model->sports->listDailyTickets(30);
        $data['performance'] = $this->platform->sports->performanceReport([]);
        // Today's AI ticket hero: the stored daily run for today plus its
        // ticket and selections. Viewing never generates anything — when no
        // run or ticket exists the hero degrades to an honest empty state.
        $today = $this->ticketToday();
        $todayRun = null;
        $todayTicket = null;
        $todaySelections = [];
        try {
            $todayRun = $this->platform->model->sports->findDailyTicket($today);
            $ticketId = is_array($todayRun) ? (string) ($todayRun['ticket_id'] ?? '') : '';
            if ($ticketId !== '') {
                $todayTicket = $this->platform->model->sports->findTicket($ticketId);
                if ($todayTicket !== null) {
                    $todaySelections = $this->platform->model->sports->ticketSelections($ticketId);
                    foreach ($todaySelections as &$selection) {
                        $match = $this->platform->model->sports->findMatchById((int) ($selection['match_id'] ?? 0));
                        if ($match !== null) {
                            $selection['competition'] = $match['competition'] ?? null;
                            $selection['kickoff_time'] = $selection['kickoff_time'] ?? $match['kickoff_at'] ?? null;
                            $selection['home_team'] = $selection['home_team'] ?? $match['home_team'] ?? null;
                            $selection['away_team'] = $selection['away_team'] ?? $match['away_team'] ?? null;
                        }
                    }
                    unset($selection);
                }
            }
        } catch (Throwable $e) { /* hero degrades to "not generated" */ }
        $data['todayIso'] = $today;
        $data['todayRun'] = $todayRun;
        $data['todayTicket'] = $todayTicket;
        $data['todaySelections'] = $todaySelections;
        $this->render('sports/tickets', $data);
    }

    /** Configured-local calendar date used by the daily ticket scheduler. */
    private function ticketToday(): string
    {
        try {
            $config = $this->platform->sports->configuration->active();
            return \AIWorkforce\Sports\DailyTicketDate::today((string) ($config['system_timezone'] ?? 'UTC'));
        } catch (Throwable $e) {
            return \AIWorkforce\Sports\DailyTicketDate::today('UTC');
        }
    }

    /**
     * Sports capabilities of the signed-in identity, read fresh from the
     * database. The console uses these to show only the controls the identity
     * can actually use, instead of offering a button that fails on submit.
     *
     * @return array{sync: bool, approve: bool, settle: bool}
     */
    private function sportsCaps(): array
    {
        $user = $this->refreshIdentityPermissions($this->identity);
        $can = fn(string $permission): bool => $user !== null && $this->platform->identity->can($user, $permission);
        return ['sync' => $can('sports.manage'), 'approve' => $can('sports.approve'), 'settle' => $can('sports.settle')];
    }

    /** Approve / reject a PENDING_USER_APPROVAL odds prediction ticket (sports.approve). */
    public function decide(string $id)
    {
        if (!$this->requireSportsPermission('sports.approve', 'approve/reject')) return;
        // Kill switch scope: sports odds-prediction tickets are NOT an
        // order-bound surface (no broker, no money movement in this
        // deployment), so an engaged trading kill switch does not gate
        // approval or settlement here. Governance is RBAC (sports.approve).
        $approve = $this->input->post('approve') === '1';
        $reason = trim((string) $this->input->post('reason'));
        try {
            $this->platform->sports->governance->decide($id, $approve, $this->actor(), $reason);
            $this->flash('notice', 'Odds prediction ticket ' . ($approve ? 'approved' : 'rejected') . ' and audited — no external execution exists in this deployment.');
        } catch (Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        redirect('/sports/odds-prediction-ticket');
    }

    /** Settle an odds prediction ticket from stored verified results (sports.settle). */
    public function settle(string $id)
    {
        if (!$this->requireSportsPermission('sports.settle', 'settle')) return;
        try {
            $out = $this->platform->sports->settlement->settlePending($id);
            $this->flash('notice', sprintf('Odds prediction ticket settlement: %s (effective odds %s, P/L %s)',
                $out['status'] ?? 'PENDING', $out['effectiveOdds'] ?? 'n/a', $out['pnl'] ?? 'n/a'));
        } catch (Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        redirect('/sports/odds-prediction-ticket');
    }

    /**
     * Generate an odds prediction ticket from stored fixtures/odds (sports.manage).
     * Browser-accessible equivalent of POST /api/sports/ticket-engine/run for
     * operators without CLI/cron access. DailyTicketService reuses stored
     * fixtures/odds/predictions first and refreshes only missing or stale data,
     * then applies every calibration/value/confidence/risk/correlation gate.
     * Persisted ticket identity is idempotent per (ticket type, local date).
     */
    public function generate_ticket()
    {
        if ($this->input->method(true) !== 'POST') { redirect('/sports'); return; }
        if (!$this->requireSportsPermission('sports.manage', 'generate odds prediction ticket')) return;
        @set_time_limit(180);
        $date = trim((string) $this->input->post('date'));
        if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = $this->ticketToday();
        // force=1 clears the day's ACTIVE candidate state (no old pass odds can
        // be carried forward) before a clean regeneration runs.
        $force = (bool) $this->input->post('force');
        $sports = $this->platform->sports;
        try {
            $result = $sports->dailyTickets->runDaily($date, null, $force ? ['force' => true] : []);
            if (($result['status'] ?? '') === 'RESET_FAILED') {
                $this->flash('error', $result['message'] ?? 'Candidate reset failed');
                redirect('/sports?date=' . urlencode($date));
                return;
            }
        } catch (Throwable $e) {
            $this->flash('error', 'Odds prediction ticket generation failed: ' . mb_substr($e->getMessage(), 0, 300));
            redirect('/sports');
            return;
        }
        $status = (string) ($result['status'] ?? 'UNKNOWN');
        $ticketId = $result['ticketId'] ?? null;
        $evaluated = (int) ($result['evaluated'] ?? 0);
        $recorded = (int) ($result['predictionsRecorded'] ?? $result['predictions_recorded'] ?? 0);
        $rejections = (int) ($result['rejections'] ?? 0);
        $message = (string) ($result['message'] ?? '');

        if ($status === 'GENERATION_IN_PROGRESS') {
            $this->flash('notice', 'Odds prediction ticket generation is already in progress for ' . $date . '. Refresh shortly; no duplicate worker was started.');
            redirect('/sports/odds-prediction-ticket');
            return;
        }
        // DUPLICATE_SKIPPED is intentionally not a daily-ticket outcome. An
        // earlier attempt without a ticket is retryable; a valid ticket returns
        // GENERATED with its existing id.
        if ($ticketId) {
            $msg = sprintf('GENERATED odds prediction ticket %s for %s — status %s, %d evaluated, %d predictions, %d rejections. %s',
                $ticketId, $date, $status, $evaluated, $recorded, $rejections, $message);
            $this->flash('notice', $msg);
            redirect('/sports/odds-prediction-ticket');
            return;
        }
        if ($status === 'DATA_UNAVAILABLE') {
            // Every provider failed: a data outage, reported as such — never as "no qualified games".
            $ledger = [];
            foreach ((array) ($result['providerStatuses'] ?? []) as $pid => $st) $ledger[] = $pid . ': ' . $st;
            $this->flash('error', sprintf('NO TICKET for %s — STATUS: DATA_UNAVAILABLE. All configured sports-data providers failed (%s). Matches evaluated: 0, predictions generated: 0. Fix or wait for the providers (see Data feed), then run again — the day stays retryable.',
                $date, $ledger ? implode('; ', $ledger) : 'no detail'));
            redirect('/sports?date=' . $date);
            return;
        }
        // No record qualified — still a valid outcome (spec §3)
        $summary = '';
        if (!empty($result['rejectionSummary']) && is_array($result['rejectionSummary'])) {
            $parts = [];
            foreach ($result['rejectionSummary'] as $k => $v) if (is_int($v)) $parts[] = $k . ':' . $v;
            if ($parts) $summary = ' Rejections: ' . implode(', ', array_slice($parts, 0, 8)) . '.';
        }
        // Diagnostic funnel — which pipeline stage eliminated the candidates.
        $funnel = '';
        $diag = $result['diagnostics'] ?? [];
        if (is_array($diag) && !empty($diag['fixturesEvaluated'])) {
            $funnel = sprintf(
                ' Funnel: %d evaluated → %d eligible → %d fresh-odds → %d sufficient-data fixtures → %d predictions → %d confidence-qualified → %d positive-value → %d risk-qualified → %d final.',
                (int) ($diag['fixturesEvaluated'] ?? 0),
                (int) ($diag['eligibleFixtures'] ?? 0),
                (int) ($diag['fixturesWithFreshOdds'] ?? 0),
                (int) ($diag['sufficientDataFixtures'] ?? 0),
                (int) ($diag['predictionsGenerated'] ?? 0),
                (int) ($diag['confidenceQualifiedCandidates'] ?? 0),
                (int) ($diag['positiveValueCandidates'] ?? 0),
                (int) ($diag['riskQualifiedCandidates'] ?? 0),
                (int) ($diag['finalQualifiedCandidates'] ?? 0)
            );
        }
        $msg = sprintf('No qualified odds prediction ticket for %s — %s (%d evaluated, %d predictions, %d rejections).%s%s',
            $date, $status . ($message !== '' ? ': ' . $message : ''), $evaluated, $recorded, $rejections, $summary, $funnel);
        if ($status === 'NO_QUALIFIED_TICKET') {
            $this->flash('notice', $msg);
        } else {
            $this->flash('error', $msg);
        }
        // Land back on the day that was generated for, not on today.
        redirect('/sports?date=' . $date);
    }

    /**
     * Invalidate the day's ACTIVE odds-prediction candidate state without
     * regenerating (sports.manage): deletes un-settled predictions of upcoming
     * fixtures, supersedes the PENDING ticket and clears the daily slot, and
     * purges unquotable odds rows. Settled/historical records and verified
     * results are preserved. The next generation then starts from a clean
     * pool — an old pass can never leak through via cached candidates.
     */
    public function reset_candidates()
    {
        if ($this->input->method(true) !== 'POST') { redirect('/sports'); return; }
        if (!$this->requireSportsPermission('sports.manage', 'reset active candidates')) return;
        $date = trim((string) $this->input->post('date'));
        if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = $this->ticketToday();
        $to = gmdate('Y-m-d', strtotime($date . ' +1 day'));
        try {
            $counts = $this->platform->model->sports->invalidateActiveCandidates($date, $to, true);
            $this->flash('notice', sprintf(
                'Active candidate state cleared for %s..%s: %d predictions removed, %d pending ticket(s) superseded (%d legs), %d daily slot(s) cleared, %d unquotable odds row(s) purged. Settled/historical records preserved.',
                $date, $to, $counts['predictionsDeleted'], $counts['ticketsSuperseded'], $counts['selectionsDeleted'], $counts['dailySlotsCleared'], $counts['invalidOddsDeleted']));
        } catch (Throwable $e) {
            $this->flash('error', 'Active candidate reset failed: ' . mb_substr($e->getMessage(), 0, 300));
        }
        redirect('/sports?date=' . urlencode($date));
    }

    /**
     * Pull fresh data from the configured sports providers (sports.manage).
     * Browser-accessible equivalent of the cron sweep for operators without
     * CLI/cron access: fixtures for today+tomorrow, a bounded odds/results
     * refresh, quality recalc and the daily odds prediction ticket run. Bounded so a first
     * pull cannot exhaust a free-tier daily quota or PHP's time limit.
     */
    public function sync()
    {
        if ($this->input->method(true) !== 'POST') { redirect('/sports'); return; }
        if (!$this->requireSportsPermission('sports.manage', 'sync')) return;
        @set_time_limit(180);
        $sports = $this->platform->sports;
        $date = $this->ticketToday();
        $tomorrow = gmdate('Y-m-d', strtotime($date . ' +1 day'));
        $providers = $sports->providers->all();
        if (!$providers) {
            $this->flash('error', 'No sports provider is registered. Add a provider key (API-Football, TheSportsDB or SportMonks) via Admin → API or the WINDELS_*_KEY variables in .env, then sync again.');
            redirect('/sports');
            return;
        }
        $stamp = gmdate('YmdHis');
        $fixtures = 0; $created = 0; $errors = [];
        foreach ($providers as $provider) {
            try {
                $cfg = $sports->configuration->active();
                $r = $sports->sync->syncFixtures($provider, [
                    'from' => $date, 'to' => $tomorrow,
                    'timezone' => (string) ($cfg['system_timezone'] ?? 'UTC'),
                    'limit' => 50, 'page' => 1,
                ], 'web-sync:fixtures:' . $date . ':' . $provider->id() . ':' . $stamp);
            } catch (Throwable $e) {
                $r = ['status' => 'FAILED', 'errors' => [mb_substr($e->getMessage(), 0, 200)]];
            }
            $fixtures += (int) ($r['processed'] ?? 0);
            $created += (int) ($r['created'] ?? 0);
            foreach (array_slice((array) ($r['errors'] ?? []), 0, 3) as $err) $errors[] = $provider->id() . ': ' . mb_substr((string) $err, 0, 160);
        }
        $oddsDone = $this->syncWebOdds($sports, $date, $stamp, $errors);
        $resultsDone = $this->syncWebResults($sports, $date, $stamp, $errors);
        $ticketStatus = null;
        try {
            $cron = new \AIWorkforce\Sports\SportsCronService($this->AIWorkforce_model->sports, $this->AIWorkforce_model->audit, $sports);
            $cron->run('quality', $date);
            $ticket = $cron->run('ticket', $date);
            $ticketStatus = (string) ($ticket['status'] ?? '');
        } catch (Throwable $e) {
            $errors[] = 'record: ' . mb_substr($e->getMessage(), 0, 160);
        }
        if ($fixtures > 0 || $created > 0 || $oddsDone > 0 || $resultsDone > 0) {
            $msg = sprintf('Sync complete: %d fixture(s) pulled (%d new), odds refreshed for %d, results checked for %d.', $fixtures, $created, $oddsDone, $resultsDone);
            if ($ticketStatus !== null && $ticketStatus !== '') $msg .= ' Odds prediction ticket engine: ' . $ticketStatus . '.';
            if ($errors) $msg .= ' ' . count($errors) . ' warning(s) — see Data feed below.';
            $this->flash('notice', $msg);
        } else {
            $first = $errors ? ' First error: ' . mb_substr((string) $errors[0], 0, 200) : ' The providers returned no fixtures for ' . $date . '–' . $tomorrow . '.';
            $this->flash('error', 'Sync pulled nothing.' . $first);
        }
        redirect('/sports');
    }

    /** Refresh odds for today's scheduled matches, bounded for web use. */
    private function syncWebOdds(\AIWorkforce\Sports\SportsIntelligence $sports, string $date, string $stamp, array &$errors): int
    {
        $done = 0;
        $config = $sports->configuration->active();
        $window = \AIWorkforce\Sports\DailyTicketDate::utcWindow($date, (string) ($config['system_timezone'] ?? 'UTC'));
        $end = gmdate('Y-m-d\TH:i:sP', $window['endTimestamp'] - 1);
        $matches = $this->AIWorkforce_model->sports->listMatches(['from' => $window['start'], 'to' => $end, 'status' => 'SCHEDULED'], 1000);
        $sources = $this->AIWorkforce_model->sports->listProviders();
        $attempted = 0;
        foreach ($matches as $match) {
            if ($attempted >= \AIWorkforce\Sports\SportsCronService::ODDS_BATCH_SIZE) break;
            $provider = $this->webProviderById($sports, $sources, (int) $match['provider_id']);
            if ($provider === null) continue;
            // Reuse valid odds. The web action advances through stale/missing
            // rows in controlled 50-fixture batches instead of repeatedly
            // burning quota on the first arbitrary 40 rows.
            $latest = $this->AIWorkforce_model->sports->latestOdds((int) $match['id']);
            $maxAge = \AIWorkforce\Sports\OddsFreshnessEngine::maxAgeFor($latest['market'] ?? null, $provider->id());
            $observed = $latest ? strtotime((string) ($latest['observed_at'] ?? '')) : false;
            if ($observed !== false && time() - $observed <= $maxAge) continue;
            $attempted++;
            try {
                $r = $sports->sync->syncOdds($provider, (string) $match['external_id'], 'web-sync:odds:' . (int) $match['id'] . ':' . $date . ':' . $stamp);
                if (($r['status'] ?? '') === 'COMPLETED') $done++;
                elseif (!empty($r['errors'][0]) && count($errors) < 6) $errors[] = $provider->id() . ' odds: ' . mb_substr((string) $r['errors'][0], 0, 160);
            } catch (Throwable $e) {
                if (count($errors) < 6) $errors[] = $provider->id() . ' odds: ' . mb_substr((string) $e->getMessage(), 0, 160);
            }
        }
        return $done;
    }

    /** Check results for recent matches that have none stored, bounded for web use. */
    private function syncWebResults(\AIWorkforce\Sports\SportsIntelligence $sports, string $date, string $stamp, array &$errors): int
    {
        $done = 0;
        $since = gmdate('Y-m-d', strtotime($date . ' -2 days')) . 'T00:00:00+00:00';
        $matches = $this->AIWorkforce_model->sports->listMatches(['from' => $since, 'to' => $date . 'T23:59:59+00:00'], 200);
        $sources = $this->AIWorkforce_model->sports->listProviders();
        $checked = 0;
        foreach ($matches as $match) {
            if ($checked >= 40) break;
            if ($this->AIWorkforce_model->sports->findResultByMatch((int) $match['id']) !== null) continue;
            $provider = $this->webProviderById($sports, $sources, (int) $match['provider_id']);
            if ($provider === null) continue;
            $checked++;
            try {
                $r = $sports->sync->syncResults($provider, (string) $match['external_id'], 'web-sync:results:' . (int) $match['id'] . ':' . $date . ':' . $stamp);
                if (($r['status'] ?? '') === 'COMPLETED') $done++;
                elseif (!empty($r['errors'][0]) && count($errors) < 6) $errors[] = $provider->id() . ' results: ' . mb_substr((string) $r['errors'][0], 0, 160);
            } catch (Throwable $e) {
                if (count($errors) < 6) $errors[] = $provider->id() . ' results: ' . mb_substr((string) $e->getMessage(), 0, 160);
            }
        }
        return $done;
    }

    private function webProviderById(\AIWorkforce\Sports\SportsIntelligence $sports, array $sources, int $id): ?\AIWorkforce\Sports\Providers\SportsDataProvider
    {
        foreach ($sources as $p) if ((int) $p['id'] === $id) return $sports->providers->provider((string) $p['provider_code']);
        return null;
    }

    /**
     * Enforce the sports RBAC matrix + production guards for console
     * mutations (PRG flow). Plan step 6 (production review): form POSTs
     * self-guard with the session CSRF token issued at sign-in — the same
     * token the JSON API verifies as the X-CSRF-Token header, since
     * platform-wide csrf_protection is off and privileged endpoints guard
     * themselves.
     */
    private function requireSportsPermission(string $permission, string $action): bool
    {
        // Read permissions from the database: a role granted after sign-in
        // applies immediately instead of waiting for the next sign-in.
        $user = $this->refreshIdentityPermissions($this->identity);
        if (!is_array($user) || !$this->platform->identity->can($user, $permission)) {
            $this->flash('error', "Refused: signed-in identity lacks '{$permission}' — the {$action} action was not performed."
                . " Ask an administrator to assign a role that carries '{$permission}' (Sports administrator for the sports console), then retry — permissions are re-read from the database on every action, so no sign-out is needed.");
            redirect('/sports');
            return false;
        }
        $sent = (string) $this->input->post('csrf_token');
        $known = $this->session->userdata('csrf_token');
        if ($sent === '' || !is_string($known) || $known === '' || !hash_equals($known, $sent)) {
            $this->flash('error', "Refused: missing or invalid CSRF token — the {$action} action was not performed.");
            redirect('/sports');
            return false;
        }
        return true;
    }

    private function actor(): string
    {
        $user = $this->refreshIdentityPermissions($this->identity);
        return is_array($user) ? (string) $user['id'] : 'anonymous';
    }

    private function base(string $title, string $active): array
    {
        $state = $this->platform->state();
        return [
            'title' => $title, 'active' => $active,
            'csrfToken' => (string) $this->session->userdata('csrf_token'),
            'caps' => $this->sportsCaps(),
            'status' => ['tradingMode' => $state['tradingMode'], 'killSwitch' => $state['killSwitch'],
                'providers' => $this->platform->providers->getAllHealth()],
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
        return $v !== null ? rawurldecode($v) : null;
    }
}
