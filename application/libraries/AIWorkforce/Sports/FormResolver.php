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
 * (the pipeline handles it honestly: no prediction, explicit rejection).
 */
class FormResolver
{
    /**
     * @param int $maxTeamLookups per-enrich() budget of team-statistics API
     *        calls. A 14-day worldwide fixture pull can hold hundreds of
     *        unique teams; uncapped enrichment burns the whole daily quota
     *        (api-football free = 100 req/day) before odds/results sync.
     *        Fixtures past the budget keep no recentForm context and the
     *        pipeline handles them honestly (no prediction, explicit
     *        rejection) instead of failing the sync.
     */
    public function __construct(private int $maxTeamLookups = 30) {}

    /**
     * Enrich a list of fixtures with recentForm context using provider APIs.
     *
     * @param SportsDataProvider $provider The provider that originally supplied the fixtures
     * @param array $fixtures List of normalized fixture arrays
     * @return array The same fixtures with `context.recentForm` populated where available
     */
    public function enrich(SportsDataProvider $provider, array $fixtures): array
    {
        // Only native providers have team statistics endpoints
        if (!method_exists($provider, 'teamStatistics') && !method_exists($provider, 'standings')) {
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

        return $fixtures;
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
        if ($lookups >= $this->maxTeamLookups) return null;

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
                // Use standings as a proxy for team form
                $lookups++;
                $standings = $provider->standings($leagueId, $season ?? '');
                foreach ($standings as $entry) {
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
            // Form resolution is best-effort; failures don't break the pipeline
        }

        return null;
    }
}
