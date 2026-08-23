<?php
namespace Aegis\Sports;

/** Builds an attributable match snapshot and explicitly preserves unavailable inputs. */
class MatchIntelligenceEngine
{
    public function __construct(private OddsFreshnessEngine $freshness = new OddsFreshnessEngine()) {}
    public function analyze(array $match, ?array $latestOdds, array $verifiedContext = [], ?int $now = null): array
    {
        $odds = $this->freshness->assess($latestOdds, (int) ($verifiedContext['maxOddsAgeSeconds'] ?? 900), $now);
        $fields = [
            'recentForm' => $verifiedContext['recentForm'] ?? null,
            'injuries' => $verifiedContext['injuries'] ?? null,
            'lineups' => $verifiedContext['lineups'] ?? null,
            'historical' => $verifiedContext['historical'] ?? null,
            'marketLiquidity' => $verifiedContext['marketLiquidity'] ?? null,
        ];
        $unavailable = array_keys(array_filter($fields, fn($v) => $v === null));
        $status = strtoupper((string) ($match['status'] ?? 'UNKNOWN'));
        $rejections = [];
        if (!in_array($status, ['SCHEDULED', 'LIVE'], true)) $rejections[] = 'MATCH_STATUS_INVALID';
        if (!$odds['available']) $rejections[] = 'ODDS_UNAVAILABLE';
        elseif (!$odds['fresh']) $rejections[] = $odds['reason'];
        if (!$fields['recentForm']) $rejections[] = 'INSUFFICIENT_DATA';
        return [
            'match' => ['id' => $match['id'] ?? null, 'homeTeam' => $match['home_team'] ?? $match['homeTeam'] ?? null, 'awayTeam' => $match['away_team'] ?? $match['awayTeam'] ?? null, 'competition' => $match['competition'] ?? null, 'kickoff' => $match['kickoff_at'] ?? $match['kickoff'] ?? null, 'status' => $status],
            'odds' => $latestOdds, 'oddsFreshness' => $odds, 'inputs' => $fields,
            'unavailableInputs' => $unavailable, 'rejectionReasons' => array_values(array_unique($rejections)),
            'decision' => $rejections ? 'NO_QUALIFIED_TICKET' : 'INTELLIGENCE_READY',
            'generatedAt' => gmdate('c', $now ?? time()),
        ];
    }
}
