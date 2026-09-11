<?php
namespace AIWorkforce\Sports;

/**
 * Transparent, market-aware Data Quality Score (pipeline spec §9).
 *
 * Two things are evaluated, and they are kept strictly separate:
 *
 *   1. MANDATORY fields for the prediction market(s) being evaluated —
 *      defined per market in MARKET_MANDATORY_FIELDS. When one is missing
 *      the model cannot compute a probability for that market at all and
 *      the candidate is not predictable (eligibleForPrediction = false).
 *      For every supported market the mandatory set is the WINDELS core
 *      input — verified recent form — NOT "every possible statistic".
 *
 *   2. QUALITY of everything else — a weighted, auditable 0–100 score.
 *      Optional enrichment (injuries, lineups, H2H, liquidity, rest days)
 *      ADDS to the score but never blocks a prediction. The ticket floor
 *      (min_data_quality, configurable) is applied to the score, not as an
 *      all-or-nothing data gate.
 *
 * Score components (total 100, all visible in `checks`):
 *   fixture identity fields  5 × 6  = 30  (externalId, homeTeam, awayTeam, competition, kickoff)
 *   market-mandatory fields 30 shared across the mandatory set
 *   odds availability              15
 *   odds freshness (proportional)  10  (100·(1 − age/TTL) inside the TTL)
 *   provider reliability           10  (proportional)
 *   optional enrichment       5 × 1 =  5
 */
class DataQualityEngine
{
    /** Mandatory data fields per prediction market (the model's core inputs). */
    public const MARKET_MANDATORY_FIELDS = [
        'MATCH_RESULT' => ['recentForm'],
        'TOTAL_GOALS' => ['recentForm'],
        'BTTS' => ['recentForm'],
        'DOUBLE_CHANCE' => ['recentForm'],
    ];

    /** Optional enrichment: improves the score, never blocks a prediction. */
    public const OPTIONAL_ENRICHMENT_FIELDS = ['injuries', 'lineups', 'historical', 'marketLiquidity', 'restDays'];

    public const DEFAULT_MIN_DATA_QUALITY = 80;

    /**
     * Mandatory fields for one market (unknown markets fall back to the
     * strictest known set — the union — because an unsupported market is
     * filtered elsewhere and must never lower the bar).
     */
    public static function mandatoryFieldsForMarket(?string $market): array
    {
        $market = strtoupper(trim((string) $market));
        return self::MARKET_MANDATORY_FIELDS[$market] ?? self::mandatoryFieldsForMarkets(array_keys(self::MARKET_MANDATORY_FIELDS));
    }

    /** Union of the mandatory fields across the given markets. */
    public static function mandatoryFieldsForMarkets(array $markets): array
    {
        $all = [];
        foreach ($markets as $market) {
            foreach (self::MARKET_MANDATORY_FIELDS[strtoupper(trim((string) $market))] ?? [] as $field) $all[$field] = true;
        }
        return array_keys($all !== [] ? $all : ['recentForm']);
    }

    /**
     * @param array $fixture normalized fixture (externalId, homeTeam, awayTeam, competition, kickoff)
     * @param array $context quality inputs:
     *   mandatoryFields  list — market-mandatory data fields (default ['recentForm'])
     *   availableFields  list — data fields currently available on the match
     *   oddsAvailable    bool — supported odds rows exist for the match
     *   oddsFresh        bool — at least one usable (fresh) supported odds row
     *   oddsAgeSeconds   int|null — age of the freshest odds row
     *   maxOddsAgeSeconds int — odds TTL used for the freshness component
     *   providerReliability float 0..1
     *   minDataQuality   int — configurable ticket floor for this assessment
     */
    public function assess(array $fixture, array $context = []): array
    {
        $mandatory = $context['mandatoryFields'] ?? ['recentForm'];
        $mandatory = is_array($mandatory) && $mandatory !== [] ? array_values($mandatory) : ['recentForm'];
        $minQuality = isset($context['minDataQuality']) && (int) $context['minDataQuality'] > 0
            ? (int) $context['minDataQuality']
            : self::DEFAULT_MIN_DATA_QUALITY;

        // Which data fields are present. Legacy boolean context keys are
        // honoured alongside the explicit availableFields list.
        $available = [];
        foreach ((array) ($context['availableFields'] ?? []) as $field) $available[] = (string) $field;
        if (!empty($context['recentFormAvailable'])) $available[] = 'recentForm';
        if (!empty($context['oddsAvailable'])) $available[] = 'odds';
        $available = array_unique($available);

        $checks = [];
        $addField = function (string $field, string $kind, bool $ok, float $weight) use (&$checks): void {
            $checks[] = ['field' => $field, 'kind' => $kind, 'ok' => $ok, 'weight' => $weight];
        };

        // 1. Fixture identity (always mandatory — the normalizer enforces it upstream).
        foreach (['externalId', 'homeTeam', 'awayTeam', 'competition', 'kickoff'] as $field) {
            $addField($field, 'fixture', !empty($fixture[$field]), 6.0);
        }

        // 2. Market-mandatory data fields.
        $mandatoryWeight = count($mandatory) > 0 ? 30.0 / count($mandatory) : 30.0;
        foreach ($mandatory as $field) {
            $addField($field, 'mandatory', in_array($field, $available, true), $mandatoryWeight);
        }

        // 3. Odds availability + freshness.
        $oddsAvailable = !empty($context['oddsAvailable']);
        $addField('odds', 'odds', $oddsAvailable, 15.0);
        $age = isset($context['oddsAgeSeconds']) && is_numeric($context['oddsAgeSeconds']) ? max(0, (int) $context['oddsAgeSeconds']) : null;
        if ($age === null && isset($context['dataAgeSeconds']) && is_numeric($context['dataAgeSeconds'])) $age = max(0, (int) $context['dataAgeSeconds']);
        $maxAge = isset($context['maxOddsAgeSeconds']) && (int) $context['maxOddsAgeSeconds'] > 0
            ? (int) $context['maxOddsAgeSeconds']
            : (isset($context['maxAgeSeconds']) && (int) $context['maxAgeSeconds'] > 0 ? (int) $context['maxAgeSeconds'] : OddsFreshnessEngine::DEFAULT_MAX_AGE_SECONDS);
        // Freshness is explicit when the caller states it; otherwise it is
        // derived from the measured age against the TTL (legacy callers pass
        // dataAgeSeconds of a just-completed sync, which reads as fresh).
        $oddsFresh = array_key_exists('oddsFresh', $context)
            ? (bool) $context['oddsFresh']
            : ($oddsAvailable && $age !== null ? $age <= $maxAge : $oddsAvailable);
        $freshnessScore = $this->freshnessScore($age, $maxAge, $oddsFresh);
        $checks[] = ['field' => 'oddsFreshness', 'kind' => 'odds', 'ok' => $freshnessScore > 0, 'weight' => 10.0, 'score' => $freshnessScore];
        $reliability = min(1.0, max(0.0, (float) ($context['providerReliability'] ?? 0)));
        $checks[] = ['field' => 'providerReliability', 'kind' => 'provider', 'ok' => $reliability >= 0.75, 'weight' => 10.0, 'score' => (int) round(100 * $reliability)];

        // 4. Optional enrichment — quality boost only, never a gate.
        foreach (self::OPTIONAL_ENRICHMENT_FIELDS as $field) {
            $addField($field, 'optional', in_array($field, $available, true), 1.0);
        }

        $earned = 0.0;
        foreach ($checks as $check) {
            if (!$check['ok']) continue;
            $earned += isset($check['score']) ? $check['weight'] * $check['score'] / 100.0 : $check['weight'];
        }
        $score = (int) round(min(100.0, 100.0 * $earned / 100.0));

        $missing = array_values(array_map(fn($c) => $c['field'], array_filter($checks, fn($c) => !$c['ok'] && $c['kind'] !== 'optional')));
        $missingOptional = array_values(array_map(fn($c) => $c['field'], array_filter($checks, fn($c) => !$c['ok'] && $c['kind'] === 'optional')));
        $missingMandatory = array_values(array_map(fn($c) => $c['field'], array_filter($checks, fn($c) => !$c['ok'] && $c['kind'] === 'mandatory')));
        $fixtureMissing = array_values(array_map(fn($c) => $c['field'], array_filter($checks, fn($c) => !$c['ok'] && $c['kind'] === 'fixture')));
        $band = $score >= 90 ? 'EXCELLENT' : ($score >= 75 ? 'GOOD' : ($score >= 60 ? 'LIMITED' : 'REJECT'));

        return [
            'score' => $score,
            'band' => $band,
            'mandatoryFields' => $mandatory,
            'missingMandatory' => $missingMandatory,
            'missingOptional' => $missingOptional,
            'missing' => array_values(array_unique(array_merge($fixtureMissing, $missingMandatory, $missing))),
            // Requirement #13: a rejection must show what WAS there as well as
            // what was not. Without this an operator reading "Missing: H2H,
            // injuries" cannot tell whether the fixture had solid form data or
            // nothing at all — the two demand completely different fixes.
            'available' => array_values(array_map(fn($c) => $c['field'], array_filter($checks, fn($c) => !empty($c['ok'])))),
            'availableMandatory' => array_values(array_map(fn($c) => $c['field'], array_filter($checks, fn($c) => !empty($c['ok']) && $c['kind'] === 'mandatory'))),
            'availableOptional' => array_values(array_map(fn($c) => $c['field'], array_filter($checks, fn($c) => !empty($c['ok']) && $c['kind'] === 'optional'))),
            'freshnessScore' => $freshnessScore,
            'providerReliabilityScore' => (int) round(100 * $reliability),
            'minDataQuality' => $minQuality,
            // Predictable: the model's mandatory inputs exist (it can compute a probability).
            'eligibleForPrediction' => $missingMandatory === [] && $fixtureMissing === [],
            // Ticket-eligible: predictable AND real usable odds AND the configured quality floor.
            'eligibleForTicket' => $missingMandatory === [] && $fixtureMissing === [] && $oddsAvailable && $score >= $minQuality,
            'checks' => $checks,
        ];
    }

    /** 100·(1 − age/TTL) inside the TTL, 0 outside; no age known → treat as not fresh evidence. */
    private function freshnessScore(?int $age, int $maxAge, bool $oddsFresh): int
    {
        if (!$oddsFresh) return 0;
        if ($age === null) return 100; // fresh but unmeasured (e.g. point-in-time replay) — availability attested by caller
        if ($age > $maxAge) return 0;
        return max(1, (int) round(100 * (1 - $age / max(1, $maxAge))));
    }
}
