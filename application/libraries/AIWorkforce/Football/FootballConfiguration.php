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
    /** @param array<string,mixed> $overrides */
    public function __construct(private array $overrides = []) {}

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

    /**
     * Data-provider selection mode for the football console + API.
     *
     *  - AUTO (default): the Data Provider selector is locked to Auto / Smart —
     *    any operator-supplied `provider` is ignored and the engine picks the
     *    feed per request from health, coverage, odds and rate limits.
     *  - MANUAL: the operator may choose Auto / Smart, a named feed or
     *    Multi-Provider; manualProvider() is the pre-selected default.
     *
     * Admin-controlled (Admin → System Settings → Football, stored in
     * `platform_settings` and injected as an override by Platform), with
     * WINDELS_FOOTBALL_PROVIDER_MODE as the environment fallback. Any value
     * other than MANUAL is AUTO — an unreadable mode must fail closed to the
     * supervised default, never to an operator free-for-all nobody chose.
     */
    public function providerMode(): string
    {
        return strtoupper($this->text('WINDELS_FOOTBALL_PROVIDER_MODE', 'AUTO')) === 'MANUAL' ? 'MANUAL' : 'AUTO';
    }

    /** True when the admin locked provider selection to Auto / Smart. */
    public function providerLockedToAuto(): bool
    {
        return $this->providerMode() !== 'MANUAL';
    }

    /**
     * Default pre-selection for the Data Provider dropdown when the mode is
     * MANUAL: AUTO, MULTI, a feed id (api-football, thesportsdb, sportmonks,
     * http-provider) or '' (= Auto / Smart). Unknown values collapse to '' —
     * a default nobody can honour must not be offered as selected.
     */
    public function manualProvider(): string
    {
        $value = strtoupper(trim($this->text('WINDELS_FOOTBALL_MANUAL_PROVIDER', '')));
        if ($value === '' || $value === 'AUTO' || $value === 'SMART') return '';
        if ($value === 'MULTI') return 'MULTI';
        $lower = strtolower($value);
        foreach (['api-football', 'apifootball', 'thesportsdb', 'sportmonks', 'http-provider'] as $known) {
            if ($lower === $known) return $known === 'apifootball' ? 'api-football' : $known;
        }
        return '';
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

    /**
     * How long a stored prediction stays valid before new data may justify
     * replacing it. Regeneration is the exception, not the rule — this is what
     * puts a bound on the exception.
     */
    public function predictionTtlSeconds(): int
    {
        return max(0, (int) $this->num('WINDELS_FOOTBALL_PREDICTION_TTL_SECONDS', 6 * 3600));
    }

    /**
     * How far a price has to move, in implied-probability points, before the
     * move is material enough to warrant regenerating a prediction. A tick is
     * not a reason; a five-point swing is.
     */
    public function oddsMovementThreshold(): float
    {
        return max(0.0, min(1.0, (float) $this->num('WINDELS_FOOTBALL_ODDS_MOVEMENT_THRESHOLD', 0.05)));
    }

    /** How many fixtures one analysis pass may evaluate (bounded, never "all"). */
    public function analysisLimit(): int
    {
        return max(1, min(500, (int) $this->num('WINDELS_FOOTBALL_ANALYSIS_LIMIT', 120)));
    }

    // ── fair value, stability and the intelligence score ──────────────────────

    /**
     * The cut lines the value classification is made of, in *percentage points*
     * of implied probability (model minus market), not in price.
     *
     * Points of probability are the honest unit: a 4-point edge is worth the same
     * judgement at 1.20 and at 12.00, while a 4-point edge measured in *price*
     * would be enormous on a short price and meaningless on a long one.
     *
     * `strong` is the least a selection may show and still be called
     * STRONG_VALUE, `positive` is the floor of a real edge (anything smaller is
     * noise inside the vig), and `avoid` is how negative the model has to be
     * about a price before the panel says to stay away rather than merely
     * "negative". They are configuration because different leagues and feeds
     * carry different margins; they are *shared* by the board, the API and the
     * ticket layer so the same price cannot be "value" on one screen and
     * "fair" on another.
     *
     * @return array{strong:float,positive:float,avoid:float}
     */
    public function valueThresholds(): array
    {
        $strong = max(0.0, (float) $this->num('WINDELS_FOOTBALL_VALUE_STRONG_PP', 4.0)) / 100.0;
        $positive = max(0.0, (float) $this->num('WINDELS_FOOTBALL_VALUE_POSITIVE_PP', 1.0)) / 100.0;
        $avoid = max(0.0, (float) $this->num('WINDELS_FOOTBALL_VALUE_AVOID_PP', 4.0)) / 100.0;
        // A positive floor above the strong line would invert the scale; the
        // narrower of the two wins so a misconfiguration degrades rather than
        // mislabels.
        if ($positive > $strong) $positive = $strong;
        return ['strong' => $strong, 'positive' => $positive, 'avoid' => $avoid];
    }

    /**
     * How far the model's own probability has to move between two stored
     * revisions of the same prediction before the panel stops calling it stable.
     *
     * A point or two is arithmetic settling — a recalibrated temperature, a
     * truncated grid row. These are the lines past which a reader should know
     * the number moved, and past the second of which the number itself is not
     * yet trustworthy.
     *
     * @return array{moved:float,unstable:float}
     */
    public function stabilityThresholds(): array
    {
        $moved = max(0.1, (float) $this->num('WINDELS_FOOTBALL_STABILITY_MOVED_PP', 2.0)) / 100.0;
        $unstable = max($moved, (float) $this->num('WINDELS_FOOTBALL_STABILITY_UNSTABLE_PP', 8.0)) / 100.0;
        return ['moved' => $moved, 'unstable' => $unstable];
    }

    /**
     * How many matches the "Top WINDELS Picks" panel may list for a page.
     *
     * Bounded and small on purpose: a pick list is a reading of the page that is
     * on screen, and a list of fifty "picks" is the same undisciplined dump the
     * panel exists to prevent. A page with fewer qualifying matches shows fewer.
     */
    public function picksLimit(): int
    {
        return max(1, min(10, (int) $this->num('WINDELS_FOOTBALL_PICKS_LIMIT', 5)));
    }

    /**
     * The weights behind the WINDELS Intelligence Score, keyed by the component
     * that earns them.
     *
     * The score answers one question — *how good is our read of this match* — so
     * the market price is deliberately absent: value is a separate verdict and is
     * never allowed to raise a score. The weights sum to 1 when every component is
     * available; when one is missing the rest are renormalised over what is
     * present, and the missing one is listed as excluded rather than scored zero.
     */
    public function intelligenceWeights(): array
    {
        $defaults = ['confidence' => 0.30, 'dataQuality' => 0.30, 'coverage' => 0.15,
            'calibration' => 0.10, 'stability' => 0.15];
        $out = [];
        foreach ($defaults as $key => $weight) {
            $value = (float) $this->num('WINDELS_FOOTBALL_SCORE_W_' . strtoupper($key), $weight * 100);
            // A weight an operator has pinned to 0 is a decision, not a typo: it
            // is honoured, and the component then reports itself as excluded.
            $out[$key] = max(0.0, min(1.0, $value / 100.0));
        }
        return $out;
    }

    /** The bands the intelligence score is read against. */
    public function intelligenceBands(): array
    {
        return [
            'excellent' => (int) $this->num('WINDELS_FOOTBALL_SCORE_EXCELLENT', 85),
            'strong' => (int) $this->num('WINDELS_FOOTBALL_SCORE_STRONG', 70),
            'moderate' => (int) $this->num('WINDELS_FOOTBALL_SCORE_MODERATE', 55),
            'thin' => (int) $this->num('WINDELS_FOOTBALL_SCORE_THIN', 40),
        ];
    }

    /** How long the movement history of a prediction is kept for. */
    public function revisionRetentionDays(): int
    {
        return max(7, (int) $this->num('WINDELS_FOOTBALL_REVISION_RETENTION_DAYS', 90));
    }

    /**
     * Matches per page — and therefore matches per generation request.
     *
     * The page size is deliberately small and hard-capped: the module pages
     * through *persisted* matches 50 at a time, and a generation request may
     * never produce more than one page of new predictions. Raising it above
     * `MatchFeed::MAX_PAGE_SIZE` cannot happen here — the ceiling is enforced
     * again in the service, so an operator cannot talk the module into asking
     * for a thousand predictions in one call.
     */
    public function matchPageSize(): int
    {
        return max(1, min(MatchFeed::MAX_PAGE_SIZE, (int) $this->num('WINDELS_FOOTBALL_MATCH_PAGE_SIZE', MatchFeed::DEFAULT_PAGE_SIZE)));
    }

    /**
     * The premium (featured) competition — the league the console offers first
     * and processes by default. It is configuration, not a constant, so an
     * operator running a different flagship league does not have to fork the
     * module; the default is the English Premier League.
     *
     * @return array{name:string,externalId:?string}
     */
    public function premiumCompetition(): array
    {
        $name = $this->text('WINDELS_FOOTBALL_PREMIUM_COMPETITION', '');
        $external = $this->text('WINDELS_FOOTBALL_PREMIUM_COMPETITION_ID', '');
        return [
            'name' => $name !== '' ? $name : 'English Premier League',
            'externalId' => $external !== '' ? $external : null,
        ];
    }

    /**
     * The premium (featured) competitions — the leagues the Premium League
     * selector offers. Premium is an *application-level* classification: no
     * provider numbers competitions the same way, so a league is premium
     * because this deployment classified it, not because a feed says so.
     *
     * The list is configuration, and it is a list rather than a single value
     * because "premium" in football means a group of leagues — the Premier
     * League, the Champions League, La Liga, Serie A, the Bundesliga, Ligue 1
     * — not one flagship. Matching is by name or by provider competition id.
     *
     * @return list<string> names and/or provider competition ids, as configured
     */
    public function premiumCompetitions(): array
    {
        $configured = $this->text('WINDELS_FOOTBALL_PREMIUM_COMPETITIONS', '');
        $out = [];
        foreach (explode(',', $configured) as $entry) {
            $entry = trim($entry);
            if ($entry !== '') $out[] = $entry;
        }
        return $out === [] ? [$this->premiumCompetition()['name']] : $out;
    }

    /**
     * Is this competition premium, by name or by provider competition id?
     * Both are compared loosely (case, punctuation and accents aside) because
     * the same league is "Premier League" to one feed and "English Premier
     * League" to another.
     */
    public function isPremiumCompetition(?string $name, ?string $externalId = null): bool
    {
        return $this->matchedPremium($name, $externalId) !== null;
    }

    /**
     * The configured premium league this competition is, or null when it is
     * not premium. Returning the matching entry — not just a boolean — is what
     * lets two providers' different names for one league ("Premier League" and
     * "English Premier League") collapse onto one internal competition.
     */
    public function matchedPremium(?string $name, ?string $externalId = null): ?string
    {
        foreach ($this->premiumCompetitions() as $premium) {
            if ($externalId !== null && trim($externalId) !== '' && strtolower(trim($premium)) === strtolower(trim($externalId))) return $premium;
            if ($name === null || trim($name) === '') continue;
            if (strtolower(trim($premium)) === strtolower(trim($name))) return $premium;
            $loose = static fn(string $value): string => trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower($value)));
            $a = $loose($premium); $b = $loose($name);
            if ($a !== '' && $b !== '' && ($a === $b || str_contains($a, $b) || str_contains($b, $a))) return $premium;
        }
        return null;
    }

    /**
     * The default odds-prediction market, i.e. the market a request is answered
     * in when it does not name one. Every market in
     * `PredictionMarkets::catalog()` is accepted; an unknown name is reported
     * and falls back to this.
     */
    public function defaultMarket(): string
    {
        $value = strtoupper($this->text('WINDELS_FOOTBALL_DEFAULT_MARKET', ''));
        return $value !== '' ? $value : PredictionMarkets::DEFAULT_MARKET;
    }

    /**
     * Share of a match's goal expectancy the model attributes to the first
     * half, used only by the two first-half markets. It is an assumption rather
     * than a stored input, so it is configurable and named in the market's
     * `basis` (`FIRST_HALF_SHARE_0.45`) wherever it is used.
     */
    public function firstHalfGoalShare(): float
    {
        $value = (float) $this->num('WINDELS_FOOTBALL_FIRST_HALF_SHARE', 0.45);
        return max(0.2, min(0.8, $value));
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
        'odds' => 1800,               // a quoted price older than 30 min is shown as aged
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
            'providerMode' => $this->providerMode(),
            'manualProvider' => $this->manualProvider(),
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
            // Fair-value cut lines in probability points, the stability movement
            // lines, the picks cap and the score weights: every one of them is
            // read by the intelligence layer, so listing them here says what an
            // operator can actually change.
            'valueThresholdsPoints' => (function (array $t): array {
                return ['strong' => round($t['strong'] * 100, 2), 'positive' => round($t['positive'] * 100, 2),
                    'avoid' => round($t['avoid'] * 100, 2)];
            })($this->valueThresholds()),
            'stabilityThresholdsPoints' => (function (array $t): array {
                return ['moved' => round($t['moved'] * 100, 2), 'unstable' => round($t['unstable'] * 100, 2)];
            })($this->stabilityThresholds()),
            'picksLimit' => $this->picksLimit(),
            'intelligenceWeights' => $this->intelligenceWeights(),
            'intelligenceBands' => $this->intelligenceBands(),
            'revisionRetentionDays' => $this->revisionRetentionDays(),
            'analysisLimit' => $this->analysisLimit(),
            'matchPageSize' => $this->matchPageSize(),
            'maxMatchPageSize' => MatchFeed::MAX_PAGE_SIZE,
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

    /** A free-text setting: overrides win, then the environment, then the default. */
    private function text(string $name, string $default): string
    {
        if (array_key_exists($name, $this->overrides)) return trim((string) $this->overrides[$name]);
        $value = getenv($name);
        return $value === false ? $default : trim((string) $value);
    }

    private function num(string $name, int|float $default): int|float
    {
        if (array_key_exists($name, $this->overrides)) return $this->overrides[$name];
        $value = getenv($name);
        return is_numeric($value) ? $value + 0 : $default;
    }

    /** Overrides win over the environment so a test can pin a flag per case. */
    private function flag(string $name, bool $default): bool
    {
        if (array_key_exists($name, $this->overrides)) {
            $value = $this->overrides[$name];
            return is_bool($value) ? $value : in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
        }
        $value = getenv($name);
        if ($value === false || $value === '') return $default;
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
