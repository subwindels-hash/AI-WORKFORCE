<?php
namespace AIWorkforce\Sports;

use AIWorkforce\Sports\Providers\ApiFootballProvider;
use AIWorkforce\Sports\Providers\SportsDataProvider;
use AIWorkforce\Sports\Providers\SportMonksProvider;

/**
 * Resolves recent form data from provider APIs to enrich fixture payloads.
 *
 * The prediction pipeline requires four form stats per match
 * (homeGoalsPerMatch, awayGoalsPerMatch, homeConcededPerMatch,
 * awayConcededPerMatch). Only the sandbox provider produces these natively;
 * real providers expose team statistics via separate endpoints.
 *
 * This service fetches team-level stats and attaches them as `recentForm`
 * context to fixture payloads so the FeatureEngineeringEngine can build
 * prediction features.
 *
 * Form data is **sourced from actual provider data** — never fabricated.
 * When the provider cannot supply form, the fixture stays without context
 * and the pipeline handles it honestly (explicit INSUFFICIENT_DATA with the
 * missing fields, counted once — never silently degraded).
 *
 * Request efficiency:
 *   • SportMonks standings are fetched ONCE per (league, season) and serve
 *     every team in that league — previously each team in the same league
 *     triggered an identical standings request.
 *   • api-football team statistics stay per (team, league, season), cached.
 *   • Everything shares one lookup budget per run (constructor / env), so a
 *     big fixture pull cannot burn the daily quota before odds/results sync.
 */
class FormResolver
{
    public const ENV_BUDGET = 'WINDELS_SPORTS_FORM_LOOKUPS';
    public const DEFAULT_BUDGET = 30;

    /** @var array<string,array> (provider:league:season) → standings rows — one request serves a whole league */
    private array $standingsByLeague = [];

    /** Run observability: why did (or didn't) enrichment happen. */
    private int $lookupsUsed = 0;
    private int $lookupFailures = 0;
    private int $budgetSkips = 0;
    private bool $providerCapable = true;

    /**
     * @param int|null $maxTeamLookups per-enrich() budget of team-statistics
     *        API calls (null = env WINDELS_SPORTS_FORM_LOOKUPS or 30). A
     *        14-day worldwide fixture pull can hold hundreds of unique teams;
     *        uncapped enrichment burns the whole daily quota (api-football
     *        free = 100 req/day) before odds/results sync. Fixtures past the
     *        budget keep no recentForm context and the pipeline handles them
     *        honestly (no prediction, explicit rejection) instead of failing
     *        the sync.
     */
    public function __construct(private ?int $maxTeamLookups = null)
    {
        $this->maxTeamLookups = $this->resolveBudget($maxTeamLookups);
    }

    private function resolveBudget(?int $explicit): int
    {
        if ($explicit !== null && $explicit >= 0) return $explicit;
        $env = getenv(self::ENV_BUDGET);
        if (is_string($env) && $env !== '' && is_numeric($env) && (int) $env >= 0) return (int) $env;
        return self::DEFAULT_BUDGET;
    }

    /**
     * Enrich a list of fixtures with recentForm context using provider APIs.
     *
     * @param SportsDataProvider $provider The provider that originally supplied the fixtures
     * @param array $fixtures List of normalized fixture arrays
     * @return array The same fixtures with `context.recentForm` populated where available
     */
    public function enrich(SportsDataProvider $provider, array $fixtures): array
    {
        // Only native providers have team statistics endpoints. When the active
        // fixture provider has none, recentForm can never be attached and every
        // fixture downstream is rejected INSUFFICIENT_DATA — record that so the
        // diagnostics funnel can say WHY instead of leaving it invisible.
        if (!method_exists($provider, 'teamStatistics') && !method_exists($provider, 'standings')) {
            $this->providerCapable = false;
            return $fixtures;
        }

        // Build a cache of team stats we've already fetched to minimize API calls
        $teamStatsCache = [];
        $lookups = 0;

        foreach ($fixtures as &$fixture) {
            $homeTeamId = $fixture['homeTeamId'] ?? null;
            $awayTeamId = $fixture['awayTeamId'] ?? null;
            $leagueId = $fixture['leagueId'] ?? null;
            $season = $fixture['season'] ?? (string) date('Y');

            // Skip if we already have form data (e.g. from sandbox)
            if (!empty($fixture['context']['recentForm'])) continue;
            // Skip if no team IDs or league to fetch from
            if (!$homeTeamId || !$awayTeamId) continue;

            $homeForm = $this->fetchTeamForm($provider, $homeTeamId, $leagueId, $season, $teamStatsCache, $lookups);
            $awayForm = $this->fetchTeamForm($provider, $awayTeamId, $leagueId, $season, $teamStatsCache, $lookups);

            if ($homeForm !== null && $awayForm !== null) {
                $fixture['context'] = array_merge($fixture['context'] ?? [], [
                    'recentForm' => [
                        'homeGoalsPerMatch' => $homeForm['goalsPerMatch'],
                        'awayGoalsPerMatch' => $awayForm['goalsPerMatch'],
                        'homeConcededPerMatch' => $homeForm['concededPerMatch'],
                        'awayConcededPerMatch' => $awayForm['concededPerMatch'],
                        'source' => $provider->id() . ':team-statistics',
                        'timestamp' => gmdate('c'),
                    ],
                ]);
            }
        }
        unset($fixture);

        $this->lookupsUsed = $lookups;
        return $fixtures;
    }

    /**
     * Why enrichment did or did not produce recentForm this run: the lookup
     * budget spent vs configured, provider API failures (quota, errors) that
     * were swallowed per fixture, budget skips, and whether the active
     * fixture provider even exposes a team-statistics endpoint. Surfaced in
     * the daily-ticket diagnostics funnel — never a gate itself.
     */
    public function stats(): array
    {
        return [
            'lookupsUsed' => $this->lookupsUsed,
            'lookupFailures' => $this->lookupFailures,
            'budgetSkips' => $this->budgetSkips,
            'budget' => $this->maxTeamLookups,
            'providerCapable' => $this->providerCapable,
        ];
    }

    /**
     * Fetch team form from the provider's team statistics endpoint.
     *
     * @return array{goalsPerMatch: float, concededPerMatch: float}|null
     */
    private function fetchTeamForm(SportsDataProvider $provider, string $teamId, ?string $leagueId, ?string $season, array &$cache, int &$lookups): ?array
    {
        if ($teamId === '' || $teamId === '0') return null;

        $cacheKey = $teamId . ':' . ($leagueId ?? '') . ':' . ($season ?? '');
        if (isset($cache[$cacheKey])) return $cache[$cacheKey];
        // Quota budget: cache hits are free, live lookups stop at the cap so a
        // big fixture pull cannot burn the daily quota before odds/results sync.
        if ($lookups >= $this->maxTeamLookups) { $this->budgetSkips++; return null; }

        try {
            if ($provider instanceof ApiFootballProvider && $leagueId && $season) {
                $lookups++;
                $stats = $provider->teamStatistics($teamId, $leagueId, $season);
                $played = (int) ($stats['played'] ?? 0);
                if ($played < 1) return null;
                $goalsFor = (int) ($stats['goalsForTotal'] ?? 0);
                $goalsAgainst = (int) ($stats['goalsAgainstTotal'] ?? 0);
                $form = [
                    'goalsPerMatch' => round($goalsFor / $played, 3),
                    'concededPerMatch' => round($goalsAgainst / $played, 3),
                ];
                $cache[$cacheKey] = $form;
                return $form;
            }

            if ($provider instanceof SportMonksProvider && $leagueId) {
                // One standings request per (league, season) serves EVERY team
                // in the league — the fetched table is cached and reused for
                // all of the league's fixtures in this run.
                $standingsKey = $provider->id() . ':' . $leagueId . ':' . ($season ?? '');
                if (!isset($this->standingsByLeague[$standingsKey])) {
                    if ($lookups >= $this->maxTeamLookups) { $this->budgetSkips++; return null; }
                    $lookups++;
                    $this->standingsByLeague[$standingsKey] = $provider->standings($leagueId, $season ?? '');
                }
                foreach ($this->standingsByLeague[$standingsKey] as $entry) {
                    if ((string) ($entry['teamId'] ?? '') === $teamId) {
                        $played = (int) ($entry['played'] ?? 0);
                        if ($played < 1) return null;
                        $form = [
                            'goalsPerMatch' => round((int) ($entry['goalsFor'] ?? 0) / $played, 3),
                            'concededPerMatch' => round((int) ($entry['goalsAgainst'] ?? 0) / $played, 3),
                        ];
                        $cache[$cacheKey] = $form;
                        return $form;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Form resolution is best-effort; failures don't break the pipeline.
            // Counted so the diagnostics funnel can distinguish "provider could
            // not supply form" (quota exhausted, 4xx/5xx) from "never asked".
            $this->lookupFailures++;
        }

        return null;
    }
}
