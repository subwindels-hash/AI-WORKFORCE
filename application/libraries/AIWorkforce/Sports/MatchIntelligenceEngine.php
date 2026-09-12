<?php
namespace AIWorkforce\Sports;

/**
 * Builds an attributable match snapshot. Every input keeps its source and
 * timestamp; unavailable inputs are explicitly preserved (never fabricated).
 * Context may arrive from the persisted provider payload (match['payload'])
 * or be overridden by $verifiedContext (tests / operator-supplied data).
 *
 * Honesty rules (pipeline spec §8):
 *   • odds availability and freshness are REPORTED (oddsFreshness), not
 *     gated here — the prediction pipeline gates them, in stage order,
 *     before any prediction is generated;
 *   • optional enrichment inputs (injuries, lineups, H2H, rest days…)
 *     that a provider did not supply are REPORTED as unavailableInputs —
 *     they lower the Data Quality Score but never invalidate the match.
 *     Only a field that is mandatory for the selected market can block a
 *     prediction, and that check is made per market downstream;
 *   • the only hard rejections left here are match-level facts that make
 *     ANY prediction impossible (invalid status, explicit zero liquidity).
 */
class MatchIntelligenceEngine
{
    public function __construct(private OddsFreshnessEngine $freshness = new OddsFreshnessEngine()) {}

    public function analyze(array $match, ?array $latestOdds, array $verifiedContext = [], ?int $now = null): array
    {
        $payload = SportsDataNormalizer::document($match['payload'] ?? null);
        $storedContext = is_array($payload['context'] ?? null) ? $payload['context'] : [];
        $context = array_merge($storedContext, $verifiedContext);
        $odds = $this->freshness->assess(
            $latestOdds,
            isset($context['maxOddsAgeSeconds']) && (int) $context['maxOddsAgeSeconds'] > 0 ? (int) $context['maxOddsAgeSeconds'] : null,
            $now
        );
        $fields = [
            'recentForm' => $context['recentForm'] ?? null,
            'injuries' => $context['injuries'] ?? null,
            'lineups' => $context['lineups'] ?? null,
            'historical' => $context['historical'] ?? null,
            'marketLiquidity' => $context['marketLiquidity'] ?? null,
            'restDays' => $context['restDays'] ?? null,
        ];
        $unavailable = array_keys(array_filter($fields, fn($v) => $v === null));
        $status = strtoupper((string) ($match['status'] ?? 'UNKNOWN'));
        $rejections = [];
        if (!in_array($status, ['SCHEDULED', 'LIVE'], true)) $rejections[] = 'MATCH_STATUS_INVALID';
        if ($fields['marketLiquidity'] !== null && (float) $fields['marketLiquidity'] < 1) $rejections[] = 'INSUFFICIENT_LIQUIDITY';
        return [
            'match' => ['id' => $match['id'] ?? null, 'fixtureId' => $match['external_id'] ?? $match['externalId'] ?? null, 'homeTeam' => $match['home_team'] ?? $match['homeTeam'] ?? null, 'awayTeam' => $match['away_team'] ?? $match['awayTeam'] ?? null, 'competition' => $match['competition'] ?? null, 'kickoff' => $match['kickoff_at'] ?? $match['kickoff'] ?? null, 'status' => $status, 'simulated' => !empty($payload['simulated']),
                // Provider-supplied crest URLs, carried through from the
                // stored fixture payload. Absent for a provider that never
                // sent one — never a guessed/broken image.
                'homeTeamLogo' => $payload['homeTeamLogo'] ?? null, 'awayTeamLogo' => $payload['awayTeamLogo'] ?? null],
            'odds' => $latestOdds, 'oddsFreshness' => $odds, 'inputs' => $fields,
            'unavailableInputs' => $unavailable, 'rejectionReasons' => array_values(array_unique($rejections)),
            'decision' => $rejections ? 'MATCH_DATA_INVALID' : 'INTELLIGENCE_READY',
            'generatedAt' => gmdate('c', $now ?? time()),
        ];
    }
}
