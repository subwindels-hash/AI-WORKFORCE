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
 * same permission checks the API enforces, and the ticket stays audited with
 * the acting user.
 */
class Sports extends App_Controller
{
    public function index()
    {
        $data = $this->base('Sports Intelligence', 'sports');
        $data['dashboard'] = $this->platform->sports->dashboard();
        $this->render('sports/index', $data);
    }

    public function tickets()
    {
        $data = $this->base('Sports Tickets', 'sports');
        $data['tickets'] = $this->platform->model->sports->listTickets([], 100);
        $data['dailyRuns'] = $this->platform->model->sports->listDailyTickets(30);
        $data['performance'] = $this->platform->sports->performanceReport([]);
        $this->render('sports/tickets', $data);
    }

    /** Approve / reject a PENDING_USER_APPROVAL ticket (sports.approve). */
    public function decide(string $id)
    {
        if (!$this->requireSportsPermission('sports.approve', 'approve/reject')) return;
        if ($this->killSwitchActive()) {
            $this->flash('error', 'Refused: platform kill switch is ACTIVE — release it before approving sports tickets (settlement remains available).');
            redirect('/sports/tickets');
            return;
        }
        $approve = $this->input->post('approve') === '1';
        $reason = trim((string) $this->input->post('reason'));
        try {
            $this->platform->sports->governance->decide($id, $approve, $this->actor(), $reason);
            $this->flash('notice', 'Ticket ' . ($approve ? 'approved' : 'rejected') . ' and audited — no external execution exists in this deployment.');
        } catch (Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        redirect('/sports/tickets');
    }

    /** Settle a ticket from stored verified results (sports.settle). */
    public function settle(string $id)
    {
        if (!$this->requireSportsPermission('sports.settle', 'settle')) return;
        try {
            $out = $this->platform->sports->settlement->settlePending($id);
            $this->flash('notice', sprintf('Ticket settlement: %s (effective odds %s, P/L %s)',
                $out['status'] ?? 'PENDING', $out['effectiveOdds'] ?? 'n/a', $out['pnl'] ?? 'n/a'));
        } catch (Throwable $e) {
            $this->flash('error', $e->getMessage());
        }
        redirect('/sports/tickets');
    }

    /**
     * Pull fresh data from the configured sports providers (sports.manage).
     * Browser-accessible equivalent of the cron sweep for operators without
     * CLI/cron access: fixtures for today+tomorrow, a bounded odds/results
     * refresh, quality recalc and the daily ticket run. Bounded so a first
     * pull cannot exhaust a free-tier daily quota or PHP's time limit.
     */
    public function sync()
    {
        if ($this->input->method(true) !== 'POST') { redirect('/sports'); return; }
        if (!$this->requireSportsPermission('sports.manage', 'sync')) return;
        @set_time_limit(180);
        $sports = $this->platform->sports;
        $date = gmdate('Y-m-d');
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
                $r = $sports->sync->syncFixtures($provider, ['from' => $date, 'to' => $tomorrow], 'web-sync:fixtures:' . $date . ':' . $provider->id() . ':' . $stamp);
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
            $errors[] = 'ticket: ' . mb_substr($e->getMessage(), 0, 160);
        }
        if ($fixtures > 0 || $created > 0 || $oddsDone > 0 || $resultsDone > 0) {
            $msg = sprintf('Sync complete: %d fixture(s) pulled (%d new), odds refreshed for %d, results checked for %d.', $fixtures, $created, $oddsDone, $resultsDone);
            if ($ticketStatus !== null && $ticketStatus !== '') $msg .= ' Ticket engine: ' . $ticketStatus . '.';
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
        $end = gmdate('Y-m-d', strtotime($date . ' +1 day')) . 'T00:00:00+00:00';
        $matches = $this->AIWorkforce_model->sports->listMatches(['from' => $date . 'T00:00:00+00:00', 'to' => $end, 'status' => 'SCHEDULED'], 40);
        $sources = $this->AIWorkforce_model->sports->listProviders();
        foreach ($matches as $match) {
            $provider = $this->webProviderById($sports, $sources, (int) $match['provider_id']);
            if ($provider === null) continue;
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
        $user = $this->session->userdata('identity');
        if (!is_array($user) || !$this->platform->identity->can($user, $permission)) {
            $this->flash('error', "Refused: signed-in identity lacks '{$permission}' — the {$action} action was not performed.");
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

    /** True while the platform kill switch is ACTIVE (it boots ACTIVE — fail closed). */
    private function killSwitchActive(): bool
    {
        $ks = $this->platform->state()['killSwitch'] ?? [];
        return !empty($ks['active']);
    }

    private function actor(): string
    {
        $user = $this->session->userdata('identity');
        return is_array($user) ? (string) $user['id'] : 'anonymous';
    }

    private function base(string $title, string $active): array
    {
        $state = $this->platform->state();
        return [
            'title' => $title, 'active' => $active,
            'csrfToken' => (string) $this->session->userdata('csrf_token'),
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
