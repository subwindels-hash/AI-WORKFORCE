<?php
namespace AIWorkforce\Sports;

use AIWorkforce\Sports\Providers\SportsProviderManager;

/**
 * Live smoke test for configured sports providers — the "does it actually
 * work?" diagnostic.
 *
 * For each registered provider it exercises the real API, layer by layer:
 *   1. health()      — reachability + authentication + quota usage
 *   2. fixtures()    — one day of fixtures (the sync pipeline's input)
 *   3. odds()        — for the first fixture, when any exist
 *   4. topPlayers()  — where the provider supports it (league 39, current
 *                      season — a stable reference competition)
 *
 * Read-only and bounded: a handful of requests per provider. Odds and
 * top-players failures are reported as non-fatal (they may require a paid
 * plan or add-on); health + fixtures must pass for the provider to pass.
 */
class SportsLiveSmoke
{
    /** @var string League used for the top-players probe (EPL id 39 in api-football). */
    public const PROBE_LEAGUE = '39';

    public function run(SportsProviderManager $providers, ?string $only = null, string $date = ''): array
    {
        $day = $date !== '' ? $date : gmdate('Y-m-d');
        $season = (string) gmdate('Y');
        $all = $providers->all();
        if ($only !== null && $only !== '') {
            if (!isset($all[$only])) {
                return ['configured' => false, 'error' => 'provider not registered: ' . $only, 'registered' => array_values(array_keys($all))];
            }
            $all = [$only => $all[$only]];
        }
        if ($all === []) {
            return [
                'configured' => false,
                'hint' => 'No sports providers configured. Set WINDELS_API_FOOTBALL_KEY (an api-football.com key) or add credentials in Admin → API, then re-run.',
            ];
        }

        $report = [];
        $pass = true;
        foreach ($all as $id => $provider) {
            $steps = [];

            // 1. health — auth + reachability + quota
            try {
                $health = $provider->health();
                $steps['health'] = [
                    'ok' => (($health['status'] ?? '') === 'ONLINE'),
                    'status' => $health['status'] ?? 'UNKNOWN',
                ];
                if (isset($health['requestsToday'])) $steps['health']['requestsToday'] = $health['requestsToday'];
                if (isset($health['limitDaily'])) $steps['health']['limitDaily'] = $health['limitDaily'];
                if (empty($steps['health']['ok']) && !empty($health['detail'])) $steps['health']['error'] = $health['detail'];
            } catch (\Throwable $e) {
                $steps['health'] = ['ok' => false, 'status' => 'ERROR', 'error' => $e->getMessage()];
            }

            // 2. fixtures for the day — the sync pipeline's input
            $fixtures = [];
            if (method_exists($provider, 'resetPaginationNotes')) {
                try { $provider->resetPaginationNotes(); } catch (\Throwable $e) { /* diagnostics only */ }
            }
            try {
                $fixtures = $provider->fixtures(['from' => $day, 'to' => $day]);
                $steps['fixtures'] = ['ok' => true, 'count' => count($fixtures)];
                // A pull that could not follow every page is still a pull —
                // report what was left unread rather than passing as complete.
                if (method_exists($provider, 'paginationNotes')) {
                    $notes = $provider->paginationNotes();
                    if (!empty($notes)) $steps['fixtures']['paginationNotes'] = $notes;
                }
            } catch (\Throwable $e) {
                $steps['fixtures'] = ['ok' => false, 'error' => $e->getMessage()];
            }

            // 3. odds for the first fixture (informational — plan-gated)
            if (!empty($fixtures) && !empty($fixtures[0]['externalId'])) {
                try {
                    $odds = $provider->odds((string) $fixtures[0]['externalId']);
                    $steps['odds'] = ['ok' => true, 'rows' => count($odds)];
                } catch (\Throwable $e) {
                    $steps['odds'] = ['ok' => false, 'error' => $e->getMessage(), 'note' => 'odds may require a paid plan or add-on'];
                }
            }

            // 4. top players (only providers that support it)
            if (method_exists($provider, 'topPlayers')) {
                try {
                    $top = $provider->topPlayers(self::PROBE_LEAGUE, $season, 'yellow_cards');
                    $steps['topPlayers'] = ['ok' => true, 'players' => count($top['players'] ?? [])];
                } catch (\Throwable $e) {
                    $steps['topPlayers'] = ['ok' => false, 'error' => $e->getMessage(), 'note' => 'top players may require a paid plan or add-on'];
                }
            }

            $ok = ($steps['health']['ok'] ?? false) && ($steps['fixtures']['ok'] ?? false);
            $pass = $pass && $ok;
            $report[$id] = ['steps' => $steps, 'pass' => $ok];
        }

        return ['configured' => true, 'pass' => $pass, 'providers' => $report];
    }
}
