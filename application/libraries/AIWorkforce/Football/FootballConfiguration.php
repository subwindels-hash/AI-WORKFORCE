<?php
namespace AIWorkforce\Football;

/**
 * Environment-derived configuration for the football module.
 *
 * Two rules matter for production honesty:
 *
 *  1. DEMO_DATA gating. Demo/simulated fixtures are only ever reachable when
 *     DEMO_MODE=true (WINDELS_FOOTBALL_DEMO_MODE=true is an explicit alias). Production
 *     defaults to false, and nothing in the football path falls back to a
 *     simulated provider, a seeded fixture list or a canned score when the
 *     provider is missing — the module reports unavailable instead.
 *  2. Refresh cadence is configuration, not a hard-coded loop: each bucket
 *     (upcoming / live / results) has its own interval and provider budget that
 *     RefreshPolicy combines with what the provider actually reported back
 *     (rate-limit headers, 429 retry-after).
 */
final class FootballConfiguration
{
    /**
     * @param array<string,mixed> $overrides per-key test pins, always win
     * @param callable(string):string|null $settingsSource operator-saved
     *        overrides from the admin panel (platform_settings, category
     *        'football'), keyed by the SAME environment name the flag would
     *        otherwise be read from. Precedence: overrides > settings source
     *        > environment > default — so an admin save behaves exactly like
     *        exporting the value into the environment, and an environment
     *        value the operator never touched in the panel still wins over
     *        the built-in default.
     */
    /** @var array<string,mixed> */
    private $overrides = [];

    /** @var (callable(string):?string)|null */
    private $settingsSource = null;

    public function __construct(array $overrides = [], $settingsSource = null)
    {
        $this->overrides = $overrides;
        $this->settingsSource = $settingsSource;
    }

    /** The operator-saved value for one environment name, or null when unset. */
    private function saved(string $name): ?string
    {
        if ($this->settingsSource === null) return null;
        try {
            $value = (string) ($this->settingsSource)($name);
        } catch (\Throwable $e) {
            return null;   // a broken settings store must never take the engine down
        }
        return $value === '' ? null : $value;
    }

    public function enabled(): bool
    {
        return $this->flag('WINDELS_FOOTBALL_ENABLED', true);
    }

    /**
     * Demo/simulated data switch. Production default is false, and no code path
     * in this module falls back to simulated fixtures when the provider is
     * missing — the flag only *permits* a demo provider that an operator wired in
     * explicitly (WINDELS_FOOTBALL_DEMO_MODE, or the platform-wide DEMO_MODE).
     */
    public function demoMode(): bool
    {
        return $this->flag('DEMO_MODE', false) || $this->flag('WINDELS_FOOTBALL_DEMO_MODE', false);
    }

    /** Interval (seconds) between refresh sweeps per freshness bucket. */
    public function refreshInterval(string $bucket): int
    {
        $defaults = [
            'fixtures' => 6 * 3600,     // scheduled fixtures: a few times a day, more often near kickoff
            'upcoming' => 3600,         // tomorrow's board: hourly
            'live' => 90,               // live scores: bounded by provider limits, not by a fixed 5-minute loop
            'results' => 15 * 60,      // finished matches: check for a final score, settle once
            'statistics' => 12 * 3600,  // team/league statistics and head-to-head
            'predict' => 1800,          // (re)build today's board for not-yet-kicked-off fixtures
            'settle' => 900,            // settlement sweep
            'performance' => 3600,      // performance snapshot
            'cleanup' => 86400,
        ];
        // Read through num(), not getenv(): a case (or a test) that pins an
        // interval must get the value it pinned, the same way every other knob
        // in this class behaves.
        $value = (int) $this->num('WINDELS_FOOTBALL_REFRESH_' . strtoupper($bucket), (int) ($defaults[$bucket] ?? 3600));
        return max(30, min(86400, $value));
    }

    /** Minimum spacing between provider requests so a sweep cannot outrun a quota. */
    public function minRequestSpacingMs(): int
    {
        return max(0, (int) $this->num('WINDELS_FOOTBALL_MIN_REQUEST_SPACING_MS', 250));
    }

    /**
     * Provider requests one sweep may spend before deferring the rest to the next
     * run. 0 means the job must not call the provider at all (analysis,
     * settlement and performance read the database only); -1 means unbounded, for
     * an operator-triggered sync.
     */
    public function requestBudget(string $job): int
    {
        $defaults = [
            'fixtures' => 4, 'upcoming' => 8, 'live' => 6, 'results' => 12, 'statistics' => 20,
            'predict' => 0, 'settle' => 0, 'performance' => 0, 'cleanup' => 0,
        ];
        return max(-1, (int) $this->num('WINDELS_FOOTBALL_BUDGET_' . strtoupper($job), $defaults[$job] ?? 10));
    }

    /**
     * Fallback daily request ceiling applied to a provider that does not report
     * its own limit (0 disables the guard; the stored per-provider budget wins).
     */
    public function dailyRequestCeiling(): int
    {
        return max(0, (int) $this->num('WINDELS_FOOTBALL_DAILY_REQUEST_CEILING', 0));
    }

    /** How many fixtures one analysis pass may evaluate (bounded, never "all"). */
    public function analysisLimit(): int
    {
        return max(1, min(500, (int) $this->num('WINDELS_FOOTBALL_ANALYSIS_LIMIT', 120)));
    }

    /** Scoreline grid: goals per team. 8 covers >99.9% of real football scores. */
    public function maxGoals(): int
    {
        return max(4, min(12, (int) $this->num('WINDELS_FOOTBALL_MAX_GOALS', 8)));
    }

    /** Minimum settled predictions before a calibration may be fitted at all. */
    public function minCalibrationSamples(): int
    {
        return max(10, (int) $this->num('WINDELS_FOOTBALL_MIN_CALIBRATION_SAMPLES', 50));
    }

    /** Confidence tiers of the daily board (percent, descending cut lines). */
    public function confidenceTiers(): array
    {
        return [
            ['key' => 'highest', 'label' => 'Highest Confidence', 'min' => 80.0, 'max' => 100.0],
            ['key' => 'strong', 'label' => 'Strong Predictions', 'min' => 75.0, 'max' => 79.99],
            ['key' => 'standard', 'label' => 'Standard Predictions', 'min' => 70.0, 'max' => 74.99],
        ];
    }

    /** A scoreline grid row is only worth storing when it clears this. */
    public function scoreRowMinProbability(): float
    {
        return max(0.001, min(0.05, (float) $this->num('WINDELS_FOOTBALL_SCORE_ROW_MIN', 0.01)));
    }

    /** Dixon-Coles low-score correlation. Negative favours draws/low-scoring ties. */
    public function dixonColesRho(): float
    {
        $rho = (float) $this->num('WINDELS_FOOTBALL_DC_RHO', -0.06);
        return max(-0.25, min(0.25, $rho));
    }

    /** How much of the final probability comes from a real market price, if one is stored. */
    public function marketBlendWeight(): float
    {
        return max(0.0, min(0.6, (float) $this->num('WINDELS_FOOTBALL_MARKET_BLEND', 0.35)));
    }

    /**
     * Freshness windows, one per bucket that is actually consulted: FeatureBuilder
     * judges a fixture row against `fixtures` / `results` / `live`, and the H2H
     * sample decays past `h2h`. A bucket nobody reads is a knob that does nothing,
     * so this list — not prose — is the definition of what is configurable.
     */
    public const MAX_AGE_SECONDS = [
        'fixtures' => 86400,          // scheduled fixture row: ~4 missed 6-hour sweeps
        'results' => 86400,           // finished fixture still waiting for its final score
        'live' => 300,                // in-play data older than five minutes is not live
        'h2h' => 1095 * 86400,        // three seasons, then the head-to-head weight halves
    ];

    /** Data age beyond which the numbers behind a fixture are treated as stale. */
    public function maxDataAgeSeconds(string $bucket): int
    {
        return max(60, (int) $this->num('WINDELS_FOOTBALL_MAX_AGE_' . strtoupper($bucket), (int) (self::MAX_AGE_SECONDS[$bucket] ?? 86400)));
    }

    /**
     * Whole days an head-to-head sample may be before its influence is halved.
     * Exposed as an accessor because the collector used to carry its own "three
     * seasons" constant, which left WINDELS_FOOTBALL_MAX_AGE_H2H documented and
     * unread — an operator tightening it would have changed nothing.
     */
    public function headToHeadStaleAfterDays(): int
    {
        return max(1, (int) round($this->maxDataAgeSeconds('h2h') / 86400));
    }

    /** @return array<string,int> every freshness window, keyed by the bucket that reads it */
    public function maxDataAgeSummary(): array
    {
        $out = [];
        foreach (array_keys(self::MAX_AGE_SECONDS) as $bucket) {
            $out[$bucket] = $this->maxDataAgeSeconds($bucket);
        }
        return $out;
    }

    /** Head-to-head is a weak signal: its influence shrinks with a small or old sample. */
    public function headToHeadMaxWeight(): float
    {
        return max(0.0, min(0.25, (float) $this->num('WINDELS_FOOTBALL_H2H_MAX_WEIGHT', 0.12)));
    }

    /** @return array<string,mixed> a redacted view safe for the admin diagnostics panel */
    public function describe(): array
    {
        return [
            'enabled' => $this->enabled(),
            'demoMode' => $this->demoMode(),
            'refreshIntervals' => [
                'fixtures' => $this->refreshInterval('fixtures'),
                'upcoming' => $this->refreshInterval('upcoming'),
                'live' => $this->refreshInterval('live'),
                'results' => $this->refreshInterval('results'),
                'statistics' => $this->refreshInterval('statistics'),
                'predict' => $this->refreshInterval('predict'),
                'settle' => $this->refreshInterval('settle'),
                'performance' => $this->refreshInterval('performance'),
                'cleanup' => $this->refreshInterval('cleanup'),
            ],
            // 0 means "this job must not call the provider"; -1 means unbounded
            // (an operator-triggered sync). See ProviderGateway::beginSweep().
            'requestBudget' => [
                'fixtures' => $this->requestBudget('fixtures'),
                'upcoming' => $this->requestBudget('upcoming'),
                'live' => $this->requestBudget('live'),
                'results' => $this->requestBudget('results'),
                'statistics' => $this->requestBudget('statistics'),
                'predict' => $this->requestBudget('predict'),
                'settle' => $this->requestBudget('settle'),
                'performance' => $this->requestBudget('performance'),
                'cleanup' => $this->requestBudget('cleanup'),
            ],
            'maxDataAgeSeconds' => $this->maxDataAgeSummary(),
            'minRequestSpacingMs' => $this->minRequestSpacingMs(),
            'analysisLimit' => $this->analysisLimit(),
            'model' => [
                'maxGoals' => $this->maxGoals(),
                'dixonColesRho' => $this->dixonColesRho(),
                'marketBlendWeight' => $this->marketBlendWeight(),
                'minCalibrationSamples' => $this->minCalibrationSamples(),
            ],
            'thresholds' => [
                'qualifiedDataQuality' => QualityBand::QUALIFIED_MIN,
                'limitedDataQuality' => QualityBand::LIMITED_MIN,
            ],
        ];
    }

    private function num(string $name, int|float $default): int|float
    {
        if (array_key_exists($name, $this->overrides)) return $this->overrides[$name];
        $saved = $this->saved($name);
        if ($saved !== null && is_numeric($saved)) return $saved + 0;
        $value = getenv($name);
        return is_numeric($value) ? $value + 0 : $default;
    }

    /** Plain-text setting with the same precedence as num()/flag(). */
    private function str(string $name, string $default): string
    {
        if (array_key_exists($name, $this->overrides)) return (string) $this->overrides[$name];
        $saved = $this->saved($name);
        if ($saved !== null) return $saved;
        $value = getenv($name);
        return $value === false || $value === '' ? $default : (string) $value;
    }

    /** Overrides win over saved values and the environment so a test can pin a flag per case. */
    private function flag(string $name, bool $default): bool
    {
        if (array_key_exists($name, $this->overrides)) {
            $value = $this->overrides[$name];
            return is_bool($value) ? $value : in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
        }
        $saved = $this->saved($name);
        if ($saved !== null) return in_array(strtolower(trim($saved)), ['1', 'true', 'yes', 'on'], true);
        $value = getenv($name);
        if ($value === false || $value === '') return $default;
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    // ── A/B/C classification + admin-panel knobs ─────────────────────────────

    /**
     * Minimum margin (percentage points) between the two winning probabilities
     * for a match to count as a home/away advantage instead of balanced. The
     * admin panel persists this under the same environment name.
     */
    public function categoryEdgePct(): float
    {
        return max(0.0, min(50.0, (float) $this->num('WINDELS_FOOTBALL_CATEGORY_EDGE_PCT', 5.0)));
    }

    /**
     * A draw probability at or above this line is "significant" and forces the
     * balanced category even when one side leads on raw points.
     */
    public function categoryDrawSignificantPct(): float
    {
        return max(5.0, min(90.0, (float) $this->num('WINDELS_FOOTBALL_CATEGORY_DRAW_PCT', 30.0)));
    }

    /** Automatic prediction runs (cron `predict` job) can be switched off. */
    public function autoPredictEnabled(): bool
    {
        return $this->flag('WINDELS_FOOTBALL_AUTO_PREDICT', true);
    }

    /**
     * League scope: a JSON list of competition identities ("provider|externalId"
     * or a bare externalId) the module should analyse. Empty = every stored
     * league. Persisted by the admin panel under the same environment name.
     *
     * @return list<string>
     */
    public function leagueScope(): array
    {
        $raw = trim($this->str('WINDELS_FOOTBALL_LEAGUE_SCOPE', ''));
        if ($raw === '') return [];
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) return [];
        $out = [];
        foreach ($decoded as $item) {
            $item = trim((string) $item);
            if ($item !== '') $out[] = $item;
        }
        return array_values(array_unique($out));
    }

    /**
     * Does one fixture fall inside the configured league scope? An empty scope
     * admits everything; an entry matches on "provider|externalId", on the
     * bare externalId, or as a case-insensitive competition name.
     */
    public function inLeagueScope(?string $providerCode, ?string $competitionExternalId, ?string $competitionName): bool
    {
        $scope = $this->leagueScope();
        if ($scope === []) return true;
        $identity = $providerCode !== null && $providerCode !== ''
            ? $providerCode . '|' . (string) ($competitionExternalId ?? '')
            : null;
        foreach ($scope as $entry) {
            if ($competitionExternalId !== null && $competitionExternalId !== '' && $entry === (string) $competitionExternalId) return true;
            if ($identity !== null && $entry === $identity) return true;
            if ($competitionName !== null && $competitionName !== '' && strcasecmp($entry, (string) $competitionName) === 0) return true;
        }
        return false;
    }

    /**
     * The admin-panel view of every knob this module honours: the environment
     * name (the storage key), the built-in default, and the value currently in
     * effect after overrides, saved settings and the environment are combined.
     * Rendering the panel from this list — instead of hard-coded field values —
     * is what keeps the form and the engine from drifting apart.
     *
     * @return list<array{key:string, label:string, type:string, default:mixed, value:mixed, hint:string}>
     */
    public function adminView(): array
    {
        return [
            ['key' => 'WINDELS_FOOTBALL_ENABLED', 'label' => 'Football Prediction Module', 'type' => 'bool',
                'default' => true, 'value' => $this->enabled(), 'hint' => 'Master switch. When off, the console and ticket show the disabled state and scheduled jobs do nothing.'],
            ['key' => 'WINDELS_FOOTBALL_AUTO_PREDICT', 'label' => 'Automatic predictions', 'type' => 'bool',
                'default' => true, 'value' => $this->autoPredictEnabled(), 'hint' => 'The scheduled predict job rebuilds the board from stored data. Manual rebuilds always work.'],
            ['key' => 'WINDELS_FOOTBALL_CATEGORY_EDGE_PCT', 'label' => 'Category A/C edge margin (%)', 'type' => 'number',
                'default' => 5.0, 'value' => $this->categoryEdgePct(), 'hint' => 'Minimum margin between the two winning probabilities for Category A (home) or C (away). Below it, the match is Category B.'],
            ['key' => 'WINDELS_FOOTBALL_CATEGORY_DRAW_PCT', 'label' => 'Significant draw probability (%)', 'type' => 'number',
                'default' => 30.0, 'value' => $this->categoryDrawSignificantPct(), 'hint' => 'A draw probability at or above this line forces Category B (balanced / competitive).'],
            ['key' => 'WINDELS_FOOTBALL_MAX_GOALS', 'label' => 'Score grid width (goals per team)', 'type' => 'int',
                'default' => 8, 'value' => $this->maxGoals(), 'hint' => 'The scoreline matrix covers 0…N per side. 8 covers >99.9% of real football scores. Changing it creates a new model version.'],
            ['key' => 'WINDELS_FOOTBALL_DC_RHO', 'label' => 'Dixon–Coles rho (ρ)', 'type' => 'number',
                'default' => -0.06, 'value' => $this->dixonColesRho(), 'hint' => 'Low-score correlation. Negative favours draws and low-scoring ties. Changing it creates a new model version.'],
            ['key' => 'WINDELS_FOOTBALL_MARKET_BLEND', 'label' => 'Market blend weight', 'type' => 'number',
                'default' => 0.35, 'value' => $this->marketBlendWeight(), 'hint' => 'How much of the final probability comes from a stored market price, when one exists.'],
            ['key' => 'WINDELS_FOOTBALL_H2H_MAX_WEIGHT', 'label' => 'Head-to-head max weight', 'type' => 'number',
                'default' => 0.12, 'value' => $this->headToHeadMaxWeight(), 'hint' => 'Maximum share of the model input a head-to-head sample may carry.'],
            ['key' => 'WINDELS_FOOTBALL_MIN_CALIBRATION_SAMPLES', 'label' => 'Minimum calibration samples', 'type' => 'int',
                'default' => 50, 'value' => $this->minCalibrationSamples(), 'hint' => 'Settled predictions required before a calibration may be fitted.'],
            ['key' => 'WINDELS_FOOTBALL_ANALYSIS_LIMIT', 'label' => 'Analysis limit (fixtures per pass)', 'type' => 'int',
                'default' => 120, 'value' => $this->analysisLimit(), 'hint' => 'How many fixtures one analysis pass may evaluate.'],
        ];
    }
}
