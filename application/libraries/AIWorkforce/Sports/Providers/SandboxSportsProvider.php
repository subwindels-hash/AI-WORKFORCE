<?php
namespace AIWorkforce\Sports\Providers;

/**
 * SANDBOX-mode simulation provider.
 *
 * Honesty contract (spec §3 / §38):
 *  - Every record it emits is explicitly marked `simulated: true`.
 *  - It is only ONLINE when WINDELS_SPORTS_MODE=SANDBOX AND
 *    WINDELS_SPORTS_SANDBOX=1 (explicit opt-in). In PAPER/PRODUCTION mode it
 *    reports OFFLINE so sandbox data can never leak into real statistics.
 *  - Generation is fully deterministic (seeded PRNG): the same date range
 *    always produces the same fixtures, odds, and results — reproducible but
 *    never presented as real-world data.
 *  - Injuries/lineups are intentionally NEVER produced: the sandbox simulates
 *    form, goals and markets only, so missing critical data is handled the
 *    same way it is for real providers.
 */
class SandboxSportsProvider implements SportsDataProvider
{
    private const LEAGUES = [
        'Premier Simulation League' => ['Alpha United', 'Bolt City', 'Comet Rangers', 'Delta Athletic', 'Ember Rovers', 'Falcon SC', 'Granite Town', 'Harbor Wanderers'],
        'Continental Simulation Cup' => ['Apex FC', 'Bronze Valley', 'Cinder Sports', 'Drake United', 'Eagle Grove', 'Foxes Athletic', 'Glacier Town', 'Horizon FC'],
    ];

    private int $enabled = 0;

    public function __construct(private string $seed = 'windels-sandbox-v1')
    {
        $this->enabled = (getenv('WINDELS_SPORTS_MODE') ?: 'SANDBOX') === 'SANDBOX' && getenv('WINDELS_SPORTS_SANDBOX') === '1' ? 1 : 0;
    }

    public function id(): string { return 'sandbox-sim'; }

    public function health(): array
    {
        if (!$this->enabled) {
            return ['status' => 'OFFLINE', 'reliability' => 0.0, 'detail' => 'SANDBOX_NOT_ENABLED — set WINDELS_SPORTS_MODE=SANDBOX and WINDELS_SPORTS_SANDBOX=1'];
        }
        return ['status' => 'ONLINE', 'reliability' => 0.9, 'responseMs' => 2, 'lastSuccessAt' => gmdate('c'), 'lastFailureAt' => null, 'errorRate' => 0.0];
    }

    public function fixtures(array $query): array
    {
        if (!$this->enabled) throw new ProviderException('sandbox provider is not enabled in this mode', ProviderException::OFFLINE);
        $from = (string) ($query['from'] ?? gmdate('Y-m-d'));
        $to = (string) ($query['to'] ?? $from);
        $out = [];
        $today = gmdate('Y-m-d');
        $day = $from;
        $guard = 0;
        while ($day <= $to && $guard++ < 62) {
            foreach (self::LEAGUES as $league => $teams) {
                $count = 3; // three fixtures per league per day (deterministic)
                for ($i = 0; $i < $count; $i++) {
                    $ext = $this->externalId($league, $day, $i);
                    [$home, $away] = $this->pairing($league, $day, $i);
                    $status = $day < $today ? 'FINISHED' : ($day === $today ? 'SCHEDULED' : 'SCHEDULED');
                    $out[] = $this->fixturePayload($ext, $league, $home, $away, $day, $i, $status, $teams);
                }
            }
            $day = gmdate('Y-m-d', strtotime($day . ' +1 day'));
        }
        return $out;
    }

    /**
     * A COMPLETE simulated price sheet for the fixture.
     *
     * The engine is required to evaluate every supported market that has a
     * real price (requirement #5) and to reject a fixture only when NO market
     * is usable (requirement #6). A sandbox that quoted a single Over 1.5
     * price could never exercise either rule, so it now prices the same
     * market set a real bookmaker feed would: 1X2, Double Chance, Draw No
     * Bet, the goal lines 0.5-3.5 (both sides) and BTTS.
     *
     * Every price comes from ONE Poisson score grid built on this fixture's
     * simulated form, so the sheet is internally consistent (Over 1.5 can
     * never contradict Over 2.5) and every quote carries the same honest
     * bookmaker margin. It is simulation math, deterministic and labeled
     * `simulated: true` on every row — never presented as a real market.
     */
    public function odds(string $fixtureExternalId): array
    {
        if (!$this->enabled) throw new ProviderException('sandbox provider is not enabled in this mode', ProviderException::OFFLINE);
        $ctx = $this->parseExternalId($fixtureExternalId);
        if ($ctx === null) return [];
        [$home, $away] = $this->pairing($ctx['league'], $ctx['day'], $ctx['slot']);
        $form = $this->form($ctx['league'], $home, $away);

        // Expected goals per side from the simulated attack/defence rates.
        $homeLambda = max(0.15, ($form['homeGoalsPerMatch'] + $form['awayConcededPerMatch']) / 2 + 0.18);
        $awayLambda = max(0.15, ($form['awayGoalsPerMatch'] + $form['homeConcededPerMatch']) / 2);

        // Independent-Poisson score grid — the single source of every price.
        $grid = [];
        for ($h = 0; $h <= 8; $h++) {
            for ($a = 0; $a <= 8; $a++) {
                $grid[$h][$a] = $this->poissonPmf($homeLambda, $h) * $this->poissonPmf($awayLambda, $a);
            }
        }
        $sum = static function (callable $predicate) use ($grid): float {
            $total = 0.0;
            foreach ($grid as $h => $row) foreach ($row as $a => $probability) if ($predicate((int) $h, (int) $a)) $total += $probability;
            return $total;
        };

        $probabilities = [
            'MATCH_RESULT:HOME' => $sum(fn(int $h, int $a): bool => $h > $a),
            'MATCH_RESULT:DRAW' => $sum(fn(int $h, int $a): bool => $h === $a),
            'MATCH_RESULT:AWAY' => $sum(fn(int $h, int $a): bool => $a > $h),
            'DOUBLE_CHANCE:HOME_OR_DRAW' => $sum(fn(int $h, int $a): bool => $h >= $a),
            'DOUBLE_CHANCE:AWAY_OR_DRAW' => $sum(fn(int $h, int $a): bool => $a >= $h),
            'DOUBLE_CHANCE:HOME_OR_AWAY' => $sum(fn(int $h, int $a): bool => $h !== $a),
            'BTTS:YES' => $sum(fn(int $h, int $a): bool => $h > 0 && $a > 0),
            'BTTS:NO' => $sum(fn(int $h, int $a): bool => $h === 0 || $a === 0),
        ];
        // Draw No Bet: the draw refunds, so the book is over the decisive
        // outcomes only — priced from the renormalised 1X2 probabilities.
        $decisive = $probabilities['MATCH_RESULT:HOME'] + $probabilities['MATCH_RESULT:AWAY'];
        if ($decisive > 0) {
            $probabilities['DRAW_NO_BET:HOME'] = $probabilities['MATCH_RESULT:HOME'] / $decisive;
            $probabilities['DRAW_NO_BET:AWAY'] = $probabilities['MATCH_RESULT:AWAY'] / $decisive;
        }
        foreach ([0.5, 1.5, 2.5, 3.5] as $line) {
            $suffix = str_replace('.', '_', (string) $line);
            $over = $sum(fn(int $h, int $a): bool => $h + $a > $line);
            $probabilities['TOTAL_GOALS:OVER_' . $suffix] = $over;
            $probabilities['TOTAL_GOALS:UNDER_' . $suffix] = 1 - $over;
        }

        // One honest bookmaker margin plus a small deterministic per-selection
        // shading, exactly as a real feed would price it.
        $margin = 0.04;
        $kickoffTs = $this->kickoffTs($ctx['day'], $ctx['slot']);
        $observed = gmdate('c', min(time(), $kickoffTs - 7200));
        $rows = [];
        foreach ($probabilities as $key => $probability) {
            [$market, $selection] = explode(':', $key, 2);
            $noise = ($this->randValue($fixtureExternalId . ':' . $key, 0, 1) - 0.5) * 0.02;
            $priced = min(0.97, max(0.02, $probability * (1 - $margin) + $noise));
            $decimal = round(1 / $priced, 2);
            // A quote at or below evens on the wrong side of rounding is not
            // a usable decimal price; the sandbox drops it rather than
            // emitting something a real book would never show.
            if ($decimal <= 1.01) continue;
            $rows[] = [
                'market' => $market, 'selection' => $selection, 'decimalOdds' => $decimal,
                'observedAt' => $observed, 'provider' => $this->id(), 'simulated' => true,
            ];
        }
        return $rows;
    }

    /** Poisson probability mass, for the simulated score grid. */
    private function poissonPmf(float $lambda, int $k): float
    {
        $logFactorial = 0.0;
        for ($i = 2; $i <= $k; $i++) $logFactorial += log($i);
        return exp(-$lambda + $k * log($lambda) - $logFactorial);
    }

    /**
     * Live fixtures (SANDBOX simulation). A fixture is in play while wall-clock
     * time is within [kickoff, kickoff + 105 min]; its minute and goal score
     * advance with real time along deterministic simulated goal minutes, so
     * the live board visibly auto-updates shortly after each simulated goal.
     * Everything stays labeled simulated; matches outside the window are never
     * reported live.
     */
    public function liveFixtures(): array
    {
        if (!$this->enabled) throw new ProviderException('sandbox provider is not enabled in this mode', ProviderException::OFFLINE);
        $now = time();
        $out = [];
        foreach ([gmdate('Y-m-d', $now - 86400), gmdate('Y-m-d', $now), gmdate('Y-m-d', $now + 86400)] as $day) {
            foreach (self::LEAGUES as $league => $teams) {
                for ($i = 0; $i < 3; $i++) {
                    $kickoff = $this->kickoffTs($day, $i);
                    if ($now < $kickoff || $now > $kickoff + 6300) continue;   // 105-minute match window
                    $elapsed = intdiv($now - $kickoff, 60);
                    $ext = $this->externalId($league, $day, $i);
                    [$home, $away] = $this->pairing($league, $day, $i);
                    $row = $this->fixturePayload($ext, $league, $home, $away, $day, $i, 'LIVE', $teams);
                    $row['minute'] = min(90, $elapsed);
                    $row['extraMinute'] = $elapsed > 90 ? min(15, $elapsed - 90) : null;
                    [$row['homeScore'], $row['awayScore']] = $this->liveScore($league, $ext, $home, $away, $elapsed);
                    $out[] = $row;
                }
            }
        }
        return $out;
    }

    /**
     * Deterministic in-play score: the simulated goals whose goal-minute has
     * already elapsed. Same (fixture, elapsed) always yields the same score,
     * so repeated live sweeps agree with each other and goals appear one at a
     * time as the clock passes each goal-minute.
     *
     * @return array{0:int,1:int}
     */
    private function liveScore(string $league, string $ext, string $home, string $away, int $elapsed): array
    {
        $form = $this->form($league, $home, $away);
        $scored = function (float $lambda, string $key) use ($ext, $elapsed): int {
            $goals = $this->poisson(min(4.0, $lambda), $ext . ':' . $key);
            $count = 0;
            for ($k = 0; $k < $goals; $k++) {
                $minute = $this->randInt($ext . ':' . $key . ':m' . $k, 3, 92);
                if ($minute <= $elapsed) $count++;
            }
            return $count;
        };
        return [$scored($form['homeGoalsPerMatch'], 'lh'), $scored($form['awayGoalsPerMatch'], 'la')];
    }

    public function results(string $fixtureExternalId): array
    {
        if (!$this->enabled) throw new ProviderException('sandbox provider is not enabled in this mode', ProviderException::OFFLINE);
        $ctx = $this->parseExternalId($fixtureExternalId);
        if ($ctx === null) return [];
        if ($ctx['day'] >= gmdate('Y-m-d')) return []; // not finished — nothing fabricated
        [$home, $away] = $this->pairing($ctx['league'], $ctx['day'], $ctx['slot']);
        $form = $this->form($ctx['league'], $home, $away);
        $lHome = $form['homeGoalsPerMatch'] * 0.95 + 0.15;
        $lAway = $form['awayGoalsPerMatch'] * 0.95;
        $hGoals = $this->poisson($lHome, $fixtureExternalId . ':h');
        $aGoals = $this->poisson($lAway, $fixtureExternalId . ':a');
        return [[
            'externalId' => $fixtureExternalId, 'status' => 'FINISHED',
            'homeScore' => $hGoals, 'awayScore' => $aGoals,
            'sourceTimestamp' => gmdate('c', strtotime($ctx['day'] . 'T23:59:00Z')),
            'provider' => $this->id(), 'simulated' => true,
        ]];
    }

    // ---- deterministic internals ------------------------------------------

    private function externalId(string $league, string $day, int $slot): string
    {
        return sprintf('sim-%s-%s-%d', substr(hash('crc32', $league), 0, 6), $day, $slot);
    }

    /**
     * Deterministic placeholder crest for a simulated team — same team name
     * always yields the same image, so it behaves like a real provider logo
     * without ever claiming to be one. Uses the team's initials on a fixed
     * background derived from its name, never a photo of a real club.
     */
    private function crestUrl(string $team): string
    {
        $initials = strtoupper(preg_replace('/[^A-Za-z]/', '', $team));
        $initials = substr($initials !== '' ? $initials : 'FC', 0, 2);
        $bg = substr(hash('crc32', $team), 0, 6);
        return 'https://ui-avatars.com/api/?name=' . rawurlencode($initials)
            . '&background=' . $bg . '&color=fff&bold=true&format=png&size=64';
    }

    private function parseExternalId(string $id): ?array
    {
        if (!preg_match('/^sim-([0-9a-f]{6})-(\d{4}-\d{2}-\d{2})-(\d+)$/', $id, $m)) return null;
        $league = null;
        foreach (array_keys(self::LEAGUES) as $name) {
            if (substr(hash('crc32', $name), 0, 6) === $m[1]) { $league = $name; break; }
        }
        if ($league === null) return null;
        return ['league' => $league, 'day' => $m[2], 'slot' => (int) $m[3]];
    }

    /** @return array{0:string,1:string} */
    private function pairing(string $league, string $day, int $slot): array
    {
        $teams = self::LEAGUES[$league];
        $n = count($teams);
        $offset = ($this->randInt('off:' . $league . ':' . $day, 0, $n - 1) + $slot) % $n;
        $home = $teams[$offset];
        $awayIdx = ($offset + 1 + intdiv($slot, max(1, $n - 1))) % $n;
        if ($awayIdx === $offset) $awayIdx = ($awayIdx + 1) % $n;
        return [$home, $teams[$awayIdx]];
    }

    private function kickoffTs(string $day, int $slot): int
    {
        $hours = [14, 16, 19][$slot % 3];
        return strtotime($day . 'T' . sprintf('%02d:00:00', $hours) . 'Z');
    }

    private function fixturePayload(string $ext, string $league, string $home, string $away, string $day, int $slot, string $status, array $teams): array
    {
        $form = $this->form($league, $home, $away);
        return [
            'externalId' => $ext, 'homeTeam' => $home, 'awayTeam' => $away,
            'competition' => $league, 'kickoff' => gmdate('c', $this->kickoffTs($day, $slot)),
            'status' => $status, 'sport' => 'football',
            'sourceTimestamp' => gmdate('c'), 'provider' => $this->id(),
            'simulated' => true,
            'homeTeamLogo' => $this->crestUrl($home),
            'awayTeamLogo' => $this->crestUrl($away),
            'context' => [
                'recentForm' => [
                    'homeGoalsPerMatch' => $form['homeGoalsPerMatch'],
                    'awayGoalsPerMatch' => $form['awayGoalsPerMatch'],
                    'homeConcededPerMatch' => $form['homeConcededPerMatch'],
                    'awayConcededPerMatch' => $form['awayConcededPerMatch'],
                    'source' => $this->id() . ':simulated-form',
                    'timestamp' => gmdate('c'),
                ],
                'marketLiquidity' => 20000 + $this->randInt('liq:' . $ext, 0, 80000),
                'restDays' => 2 + ($this->randInt('rest:' . $ext, 0, 2)),
            ],
        ];
    }

    /** Simulated recent form, derived from a fixed per-team strength. */
    private function form(string $league, string $home, string $away): array
    {
        $sHome = $this->strength($home);
        $sAway = $this->strength($away);
        $j = fn(string $k) => ($this->randValue($league . ':' . $k, 0, 1) - 0.5) * 0.3;
        return [
            'homeGoalsPerMatch' => round(0.6 + 0.75 * $sHome + $j('hg' . $home), 3),
            'awayGoalsPerMatch' => round(0.6 + 0.75 * $sAway + $j('ag' . $away), 3),
            'homeConcededPerMatch' => round(max(0.3, 1.7 - 0.55 * $sHome + $j('hc' . $home)), 3),
            'awayConcededPerMatch' => round(max(0.3, 1.7 - 0.55 * $sAway + $j('ac' . $away)), 3),
        ];
    }

    private function strength(string $team): float
    {
        // Fixed in [0.75, 1.35] per team name — stable across runs.
        return 0.75 + (crc32('strength:' . $team) & 0xFFFF) / 0xFFFF * 0.6;
    }

    private function randInt(string $key, int $min, int $max): int
    {
        return $min + (int) ($this->randValue($key, 0, 1) * ($max - $min + 1)) % ($max - $min + 1);
    }

    private function randValue(string $key, float $min, float $max): float
    {
        $h = hash('sha256', $this->seed . '|' . $key);
        $v = hexdec(substr($h, 0, 12)) / hexdec('ffffffffffff');
        return $min + $v * ($max - $min);
    }

    /** Deterministic Poisson sample (Knuth) from a seeded stream. */
    private function poisson(float $lambda, string $key): int
    {
        if ($lambda <= 0) return 0;
        $L = exp(-$lambda);
        $k = 0; $p = 1.0;
        do {
            $k++;
            $p *= $this->randValue($key . ':' . $k, 0, 1);
        } while ($p > $L && $k < 12);
        return $k - 1;
    }
}
