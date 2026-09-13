<?php
namespace AIWorkforce\Sports;

/**
 * CANONICAL SPORTS MARKET REGISTRY — the one vocabulary the platform speaks.
 *
 * WHY THIS EXISTS
 * ---------------
 * Every provider names the same market differently. API-Football calls it
 * "Match Winner", Sportmonks "Full Time Result", another feed "1X2". Before
 * this registry each provider mapped names inside its own private helper, so
 * the prediction engine, the odds sheet and the ticket engine could each end
 * up holding a different string for the same thing — and two of them already
 * disagreed (`MATCH_RESULT` in the ticket engine vs `MATCH_WINNER` on the
 * football board). A provider's proprietary spelling must never reach the
 * frontend or the prediction engine.
 *
 *      Provider Market → Market Normalizer → Canonical Market
 *                                          → Prediction Engine
 *                                          → Odds Prediction Ticket
 *
 * WHAT IT IS NOT
 * --------------
 * This registry describes market STRUCTURE — what a market is, what outcomes
 * it can have, what entity it attaches to. It holds no prices, no
 * probabilities and no opinions, and it never invents a market: resolving a
 * name that no provider supplied simply reports that it is unavailable. A
 * market appearing here is a statement that the platform CAN understand the
 * shape of that data, not that any fixture has it.
 *
 * This is sports-market intelligence data used for analysis, modelling and
 * prediction. It is not betting functionality: nothing here places, prices,
 * settles or brokers a wager.
 *
 * EXTENSIBILITY
 * -------------
 * Adding a market is adding one row to MARKETS plus its provider spellings in
 * ALIASES. No engine needs redesigning, because consumers ask this registry
 * what a market is rather than hard-coding a list of their own.
 */
final class SportsMarketRegistry
{
    // ── what the market attaches to ──────────────────────────────────────────
    /** Resolved at the match level (1X2, totals, corners…). */
    public const SCOPE_MATCH = 'MATCH';
    /** Resolved for one named team (team totals, team cards…). */
    public const SCOPE_TEAM = 'TEAM';
    /** Resolved for one named player (goalscorer, shots, saves…). */
    public const SCOPE_PLAYER = 'PLAYER';

    // ── what the market measures ─────────────────────────────────────────────
    public const UNIT_RESULT = 'RESULT';
    public const UNIT_GOALS = 'GOALS';
    public const UNIT_CARDS = 'CARDS';
    public const UNIT_CORNERS = 'CORNERS';
    public const UNIT_SHOTS = 'SHOTS';
    public const UNIT_ASSISTS = 'ASSISTS';
    public const UNIT_TACKLES = 'TACKLES';
    public const UNIT_FOULS = 'FOULS';
    public const UNIT_OFFSIDES = 'OFFSIDES';
    public const UNIT_SAVES = 'SAVES';

    /**
     * Whether the platform can currently MODEL the market, or only carry a
     * provider's data for it.
     *
     * MODELLED      the prediction engine derives its own probability.
     * PROVIDER_ONLY the structure is understood and provider data is stored,
     *               displayed and traceable, but no probability is generated.
     *               This is an honest "we hold the data, we do not forecast
     *               it" — never a placeholder to be filled with a guess.
     */
    public const SUPPORT_MODELLED = 'MODELLED';
    public const SUPPORT_PROVIDER_ONLY = 'PROVIDER_ONLY';

    /**
     * The canonical market catalogue.
     *
     * `equivalentTo` records that two engines historically used different
     * spellings for the identical market, so a consumer can translate without
     * either vocabulary being declared wrong.
     *
     * @var array<string,array{label:string,group:string,scope:string,unit:string,support:string,outcomes:list<string>,lined:bool,equivalentTo?:string}>
     */
    private const MARKETS = [
        // ── Result ───────────────────────────────────────────────────────────
        'MATCH_RESULT' => ['label' => 'Match Result — 1X2', 'group' => 'Result', 'scope' => self::SCOPE_MATCH,
            'unit' => self::UNIT_RESULT, 'support' => self::SUPPORT_MODELLED,
            'outcomes' => ['HOME', 'DRAW', 'AWAY'], 'lined' => false, 'equivalentTo' => 'MATCH_WINNER'],
        'DOUBLE_CHANCE' => ['label' => 'Double Chance', 'group' => 'Result', 'scope' => self::SCOPE_MATCH,
            'unit' => self::UNIT_RESULT, 'support' => self::SUPPORT_MODELLED,
            'outcomes' => ['HOME_OR_DRAW', 'AWAY_OR_DRAW', 'HOME_OR_AWAY'], 'lined' => false],
        'DRAW_NO_BET' => ['label' => 'Draw No Bet', 'group' => 'Result', 'scope' => self::SCOPE_MATCH,
            'unit' => self::UNIT_RESULT, 'support' => self::SUPPORT_MODELLED,
            'outcomes' => ['HOME', 'AWAY'], 'lined' => false],
        'ASIAN_HANDICAP' => ['label' => 'Asian Handicap', 'group' => 'Handicap', 'scope' => self::SCOPE_MATCH,
            'unit' => self::UNIT_GOALS, 'support' => self::SUPPORT_MODELLED,
            'outcomes' => ['HOME', 'AWAY'], 'lined' => true],
        'CORRECT_SCORE' => ['label' => 'Correct Score', 'group' => 'Score', 'scope' => self::SCOPE_MATCH,
            'unit' => self::UNIT_GOALS, 'support' => self::SUPPORT_MODELLED,
            'outcomes' => [], 'lined' => false],
        'HALF_TIME_FULL_TIME' => ['label' => 'Half Time / Full Time', 'group' => 'First half', 'scope' => self::SCOPE_MATCH,
            'unit' => self::UNIT_RESULT, 'support' => self::SUPPORT_MODELLED,
            'outcomes' => ['HOME_HOME', 'HOME_DRAW', 'HOME_AWAY', 'DRAW_HOME', 'DRAW_DRAW', 'DRAW_AWAY',
                'AWAY_HOME', 'AWAY_DRAW', 'AWAY_AWAY'], 'lined' => false],
        'FIRST_HALF_WINNER' => ['label' => 'First Half Winner', 'group' => 'First half', 'scope' => self::SCOPE_MATCH,
            'unit' => self::UNIT_RESULT, 'support' => self::SUPPORT_MODELLED,
            'outcomes' => ['HOME', 'DRAW', 'AWAY'], 'lined' => false],

        // ── Goals ────────────────────────────────────────────────────────────
        'TOTAL_GOALS' => ['label' => 'Total Goals — Over/Under', 'group' => 'Goals', 'scope' => self::SCOPE_MATCH,
            'unit' => self::UNIT_GOALS, 'support' => self::SUPPORT_MODELLED,
            'outcomes' => ['OVER', 'UNDER'], 'lined' => true],
        'BTTS' => ['label' => 'Both Teams To Score', 'group' => 'Goals', 'scope' => self::SCOPE_MATCH,
            'unit' => self::UNIT_GOALS, 'support' => self::SUPPORT_MODELLED,
            'outcomes' => ['YES', 'NO'], 'lined' => false],
        'HALF_TIME_GOALS' => ['label' => 'First Half Over/Under', 'group' => 'First half', 'scope' => self::SCOPE_MATCH,
            'unit' => self::UNIT_GOALS, 'support' => self::SUPPORT_MODELLED,
            'outcomes' => ['OVER', 'UNDER'], 'lined' => true],
        'TEAM_TOTAL_GOALS' => ['label' => 'Team Total Goals', 'group' => 'Team goals', 'scope' => self::SCOPE_TEAM,
            'unit' => self::UNIT_GOALS, 'support' => self::SUPPORT_MODELLED,
            'outcomes' => ['OVER', 'UNDER'], 'lined' => true],
        'SCORE_BOTH_HALVES' => ['label' => 'Score In Both Halves', 'group' => 'Goals', 'scope' => self::SCOPE_TEAM,
            'unit' => self::UNIT_GOALS, 'support' => self::SUPPORT_PROVIDER_ONLY,
            'outcomes' => ['YES', 'NO'], 'lined' => false],

        // ── Match events ─────────────────────────────────────────────────────
        'CORNERS' => ['label' => 'Corners', 'group' => 'Match events', 'scope' => self::SCOPE_MATCH,
            'unit' => self::UNIT_CORNERS, 'support' => self::SUPPORT_PROVIDER_ONLY,
            'outcomes' => ['OVER', 'UNDER'], 'lined' => true],
        'CARDS' => ['label' => 'Cards', 'group' => 'Match events', 'scope' => self::SCOPE_MATCH,
            'unit' => self::UNIT_CARDS, 'support' => self::SUPPORT_PROVIDER_ONLY,
            'outcomes' => ['OVER', 'UNDER'], 'lined' => true],
        'OFFSIDES' => ['label' => 'Offsides', 'group' => 'Match events', 'scope' => self::SCOPE_MATCH,
            'unit' => self::UNIT_OFFSIDES, 'support' => self::SUPPORT_PROVIDER_ONLY,
            'outcomes' => ['OVER', 'UNDER'], 'lined' => true],
        'TEAM_CARDS' => ['label' => 'Team Cards', 'group' => 'Match events', 'scope' => self::SCOPE_TEAM,
            'unit' => self::UNIT_CARDS, 'support' => self::SUPPORT_PROVIDER_ONLY,
            'outcomes' => ['OVER', 'UNDER'], 'lined' => true],
        'TEAM_CORNERS' => ['label' => 'Team Corners', 'group' => 'Match events', 'scope' => self::SCOPE_TEAM,
            'unit' => self::UNIT_CORNERS, 'support' => self::SUPPORT_PROVIDER_ONLY,
            'outcomes' => ['OVER', 'UNDER'], 'lined' => true],

        // ── Player markets ───────────────────────────────────────────────────
        // Structure only: these are carried and displayed where a provider
        // supplies them. The platform does not model player outcomes, and a
        // fixture without player data reports the market unavailable rather
        // than inventing a player, a statistic or a price.
        'PLAYER_GOALSCORER' => ['label' => 'Goalscorer', 'group' => 'Player', 'scope' => self::SCOPE_PLAYER,
            'unit' => self::UNIT_GOALS, 'support' => self::SUPPORT_PROVIDER_ONLY,
            'outcomes' => ['ANYTIME', 'FIRST', 'LAST'], 'lined' => false],
        'PLAYER_GOALS' => ['label' => 'Player Goals', 'group' => 'Player', 'scope' => self::SCOPE_PLAYER,
            'unit' => self::UNIT_GOALS, 'support' => self::SUPPORT_PROVIDER_ONLY,
            'outcomes' => ['OVER', 'UNDER'], 'lined' => true],
        'PLAYER_SHOTS' => ['label' => 'Player Shots', 'group' => 'Player', 'scope' => self::SCOPE_PLAYER,
            'unit' => self::UNIT_SHOTS, 'support' => self::SUPPORT_PROVIDER_ONLY,
            'outcomes' => ['OVER', 'UNDER'], 'lined' => true],
        'PLAYER_SHOTS_ON_TARGET' => ['label' => 'Player Shots On Target', 'group' => 'Player', 'scope' => self::SCOPE_PLAYER,
            'unit' => self::UNIT_SHOTS, 'support' => self::SUPPORT_PROVIDER_ONLY,
            'outcomes' => ['OVER', 'UNDER'], 'lined' => true],
        'PLAYER_ASSISTS' => ['label' => 'Player Assists', 'group' => 'Player', 'scope' => self::SCOPE_PLAYER,
            'unit' => self::UNIT_ASSISTS, 'support' => self::SUPPORT_PROVIDER_ONLY,
            'outcomes' => ['OVER', 'UNDER'], 'lined' => true],
        'PLAYER_TACKLES' => ['label' => 'Player Tackles', 'group' => 'Player', 'scope' => self::SCOPE_PLAYER,
            'unit' => self::UNIT_TACKLES, 'support' => self::SUPPORT_PROVIDER_ONLY,
            'outcomes' => ['OVER', 'UNDER'], 'lined' => true],
        'PLAYER_FOULS' => ['label' => 'Player Fouls', 'group' => 'Player', 'scope' => self::SCOPE_PLAYER,
            'unit' => self::UNIT_FOULS, 'support' => self::SUPPORT_PROVIDER_ONLY,
            'outcomes' => ['OVER', 'UNDER'], 'lined' => true],
        'PLAYER_OFFSIDES' => ['label' => 'Player Offsides', 'group' => 'Player', 'scope' => self::SCOPE_PLAYER,
            'unit' => self::UNIT_OFFSIDES, 'support' => self::SUPPORT_PROVIDER_ONLY,
            'outcomes' => ['OVER', 'UNDER'], 'lined' => true],
        'PLAYER_CARDS' => ['label' => 'Player Cards', 'group' => 'Player', 'scope' => self::SCOPE_PLAYER,
            'unit' => self::UNIT_CARDS, 'support' => self::SUPPORT_PROVIDER_ONLY,
            'outcomes' => ['YES', 'NO'], 'lined' => false],
        'PLAYER_SAVES' => ['label' => 'Goalkeeper Saves', 'group' => 'Player', 'scope' => self::SCOPE_PLAYER,
            'unit' => self::UNIT_SAVES, 'support' => self::SUPPORT_PROVIDER_ONLY,
            'outcomes' => ['OVER', 'UNDER'], 'lined' => true],
    ];

    /**
     * Provider spellings → canonical key, tried as ordered regular expressions.
     *
     * Order matters: a specific family must win before a broad word. "First
     * half goals" contains "goals", and "player shots on target" contains
     * "shots", so the narrower pattern is listed first. Anything unmatched is
     * preserved verbatim (see normalize()) rather than guessed at.
     *
     * @var list<array{0:string,1:string}>
     */
    private const ALIASES = [
        // Player markets first: they are the most specific and their names
        // contain words that broader match-level patterns would otherwise claim.
        ['/(?:goal ?keeper|keeper|\bgk\b).*saves?|saves?.*(?:goal ?keeper|keeper|\bgk\b)|player.*saves?|\bsaves?\b/', 'PLAYER_SAVES'],
        ['/player.*shots?.*(?:on ?target|on ?goal)|shots? ?on ?target/', 'PLAYER_SHOTS_ON_TARGET'],
        ['/player.*shots?|shots?.*player|total ?shots?/', 'PLAYER_SHOTS'],
        ['/player.*assists?|assists?.*player|anytime ?assist/', 'PLAYER_ASSISTS'],
        ['/player.*tackles?|tackles?.*player/', 'PLAYER_TACKLES'],
        ['/player.*fouls?|fouls?.*(?:player|committed)/', 'PLAYER_FOULS'],
        ['/player.*offsides?|offsides?.*player/', 'PLAYER_OFFSIDES'],
        ['/player.*cards?|(?:to be )?(?:booked|carded)|player.*booking/', 'PLAYER_CARDS'],
        ['/(?:first|last|anytime).*(?:goal ?scorer|scorer)|goal ?scorer/', 'PLAYER_GOALSCORER'],
        ['/player.*goals?|goals?.*player|to score \d/', 'PLAYER_GOALS'],

        // Team-scoped markets before their match-level counterparts.
        ['/(?:home|away)? ?team.*(?:total ?goals|goals ?over|goals ?total)/', 'TEAM_TOTAL_GOALS'],
        ['/(?:home|away) ?team.*cards?|team ?cards?/', 'TEAM_CARDS'],
        ['/(?:home|away) ?team.*corners?|team ?corners?/', 'TEAM_CORNERS'],
        ['/score ?in ?both ?halves|both ?halves/', 'SCORE_BOTH_HALVES'],

        // Match-level specifics.
        ['/asian ?handicap|handicap/', 'ASIAN_HANDICAP'],
        ['/draw ?no ?bet|\bdnb\b/', 'DRAW_NO_BET'],
        ['/double ?chance/', 'DOUBLE_CHANCE'],
        ['/correct ?score|exact ?score/', 'CORRECT_SCORE'],
        ['/half ?time.*full ?time|\bht ?\/? ?ft\b/', 'HALF_TIME_FULL_TIME'],
        ['/(?:first|1st) ?half|half ?time/', 'HALF_TIME_GOALS', 'requires' => '/over|under|goal/'],
        ['/(?:first|1st) ?half|half ?time/', 'FIRST_HALF_WINNER', 'requires' => '/result|winner|1x2/'],
        ['/both ?teams|\bbtts\b|goal ?goal|\bgg\b/', 'BTTS'],
        ['/corners?/', 'CORNERS'],
        ['/cards?|booking|booked/', 'CARDS'],
        ['/offsides?/', 'OFFSIDES'],
        ['/over ?\/? ?under|total ?goals|goals ?over|goals ?total|\bo\/u\b/', 'TOTAL_GOALS'],
        ['/match ?result|match ?winner|1x2|full ?time ?result|\bresult\b|\bwinner\b/', 'MATCH_RESULT'],
    ];

    /**
     * Exact-string synonyms, applied before the pattern list. These are keys a
     * caller (not a provider) is likely to use — including the football
     * board's own catalogue spellings, so the two vocabularies interoperate.
     *
     * @var array<string,string>
     */
    private const EXACT = [
        'MATCH_WINNER' => 'MATCH_RESULT',
        '1X2' => 'MATCH_RESULT',
        'FULL_TIME_RESULT' => 'MATCH_RESULT',
        'DC' => 'DOUBLE_CHANCE',
        'DNB' => 'DRAW_NO_BET',
        'AH' => 'ASIAN_HANDICAP',
        'GG' => 'BTTS',
        'NG' => 'BTTS',
        'BOTH_TEAMS_TO_SCORE' => 'BTTS',
        'HT_FT' => 'HALF_TIME_FULL_TIME',
        'EXACT_SCORE' => 'CORRECT_SCORE',
        'OVER_UNDER' => 'TOTAL_GOALS',
        'HOME_TEAM_TOTAL_GOALS' => 'TEAM_TOTAL_GOALS',
        'AWAY_TEAM_TOTAL_GOALS' => 'TEAM_TOTAL_GOALS',
        'FIRST_HALF_OVER_UNDER' => 'HALF_TIME_GOALS',
        'ANYTIME_GOALSCORER' => 'PLAYER_GOALSCORER',
        'FIRST_GOALSCORER' => 'PLAYER_GOALSCORER',
        'LAST_GOALSCORER' => 'PLAYER_GOALSCORER',
        'GOALKEEPER_SAVES' => 'PLAYER_SAVES',
    ];

    /** Every canonical market key. @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::MARKETS);
    }

    /** The full catalogue, each row including its own key. @return list<array> */
    public static function catalog(): array
    {
        $out = [];
        foreach (self::MARKETS as $key => $row) $out[] = ['key' => $key] + $row;
        return $out;
    }

    /** One market's definition, or null when the platform does not know it. */
    public static function describe(?string $market): ?array
    {
        $key = self::normalize($market)['market'];
        return isset(self::MARKETS[$key]) ? ['key' => $key] + self::MARKETS[$key] : null;
    }

    public static function known(?string $market): bool
    {
        return self::describe($market) !== null;
    }

    /** Whether the prediction engine derives its own probability for this market. */
    public static function isModelled(?string $market): bool
    {
        $row = self::describe($market);
        return $row !== null && $row['support'] === self::SUPPORT_MODELLED;
    }

    public static function scopeOf(?string $market): ?string
    {
        return self::describe($market)['scope'] ?? null;
    }

    /**
     * Resolve a provider's market name to the canonical vocabulary.
     *
     * An unrecognised name is NOT forced into a near neighbour: it is returned
     * upper-cased and flagged `recognized => false`, so it can still be stored
     * and shown with full provenance while never being mistaken for a market
     * the engine understands. Guessing here would silently attach one market's
     * price to another market's probability.
     *
     * @return array{market:string, recognized:bool, scope:?string, raw:string}
     */
    public static function normalize(?string $raw): array
    {
        $original = trim((string) $raw);
        if ($original === '') {
            return ['market' => '', 'recognized' => false, 'scope' => null, 'raw' => ''];
        }
        $upper = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $original) ?? $original);
        $upper = trim($upper, '_');
        if (isset(self::MARKETS[$upper])) {
            return ['market' => $upper, 'recognized' => true, 'scope' => self::MARKETS[$upper]['scope'], 'raw' => $original];
        }
        if (isset(self::EXACT[$upper])) {
            $key = self::EXACT[$upper];
            return ['market' => $key, 'recognized' => true, 'scope' => self::MARKETS[$key]['scope'], 'raw' => $original];
        }
        $needle = strtolower(str_replace('_', ' ', $original));
        foreach (self::ALIASES as $entry) {
            [$pattern, $key] = $entry;
            if (!preg_match($pattern, $needle)) continue;
            // Some families are only distinguishable with a second condition
            // ("first half" is a winner market or a goals market depending on
            // the rest of the name).
            if (isset($entry['requires']) && !preg_match($entry['requires'], $needle)) continue;
            return ['market' => $key, 'recognized' => true, 'scope' => self::MARKETS[$key]['scope'], 'raw' => $original];
        }
        return ['market' => $upper, 'recognized' => false, 'scope' => null, 'raw' => $original];
    }

    /** Convenience: just the canonical key. */
    public static function canonical(?string $raw): string
    {
        return self::normalize($raw)['market'];
    }

    /**
     * The goal/handicap line a market name or selection carries, if any.
     *
     * "Over 2.5", "Over 2,5" and "O 2.5" all mean 2.5. Returns null when the
     * text carries no line, which is itself meaningful: a lined market with no
     * line is incomplete data, not a zero.
     */
    public static function lineFrom(?string $text): ?float
    {
        $value = strtolower(trim((string) $text));
        if ($value === '') return null;
        if (!preg_match('/([+-]?\d+(?:[.,]\d+)?)/', $value, $m)) return null;
        $line = (float) str_replace(',', '.', $m[1]);
        return is_finite($line) ? $line : null;
    }

    /**
     * Canonicalise a selection within an already-canonical market.
     *
     * Returns the selection plus the line it implied, so a caller stores
     * "OVER" + 2.5 rather than a hundred distinct "OVER_2_5" strings that no
     * two providers spell the same way.
     *
     * @return array{selection:string, line:?float, recognized:bool, raw:string}
     */
    public static function normalizeSelection(?string $market, ?string $raw): array
    {
        $original = trim((string) $raw);
        $definition = self::describe($market);
        $value = strtolower($original);
        $line = $definition !== null && $definition['lined'] ? self::lineFrom($original) : null;
        $out = static fn(string $selection, bool $ok = true): array =>
            ['selection' => $selection, 'line' => $line, 'recognized' => $ok, 'raw' => $original];

        if ($original === '') return $out('', false);
        if ($definition === null) {
            return $out(strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $original) ?? $original), false);
        }

        // Over/under families.
        if (in_array('OVER', $definition['outcomes'], true)) {
            if (preg_match('/\bover\b|^o[\s\d]|\bmore\b|\+$/', $value)) return $out('OVER');
            if (preg_match('/\bunder\b|^u[\s\d]|\bless\b/', $value)) return $out('UNDER');
        }
        // Yes/no families.
        if (in_array('YES', $definition['outcomes'], true)) {
            if (preg_match('/\byes\b|\bgg\b|^1$/', $value)) return $out('YES');
            if (preg_match('/\bno\b|\bng\b|^0$/', $value)) return $out('NO');
        }
        // Result families.
        if (in_array('HOME', $definition['outcomes'], true)) {
            if (preg_match('/\bhome\b|^1$/', $value)) return $out('HOME');
            if (preg_match('/\bdraw\b|\btie\b|^x$/', $value) && in_array('DRAW', $definition['outcomes'], true)) return $out('DRAW');
            if (preg_match('/\baway\b|^2$/', $value)) return $out('AWAY');
        }
        // Double chance.
        if ($definition['key'] ?? self::canonical($market) === 'DOUBLE_CHANCE') {
            $compact = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $original) ?? '');
            if (in_array($compact, ['1X', 'HOMEDRAW', 'HOMEORDRAW', 'DRAWHOME'], true)) return $out('HOME_OR_DRAW');
            if (in_array($compact, ['X2', 'DRAWAWAY', 'DRAWORAWAY', 'AWAYORDRAW'], true)) return $out('AWAY_OR_DRAW');
            if (in_array($compact, ['12', 'HOMEAWAY', 'HOMEORAWAY'], true)) return $out('HOME_OR_AWAY');
        }
        // Goalscorer variants.
        if (self::canonical($market) === 'PLAYER_GOALSCORER') {
            if (preg_match('/\bfirst\b|\b1st\b/', $value)) return $out('FIRST');
            if (preg_match('/\blast\b/', $value)) return $out('LAST');
            if (preg_match('/anytime|any ?time/', $value)) return $out('ANYTIME');
        }
        // Correct score keeps its scoreline.
        if (self::canonical($market) === 'CORRECT_SCORE' && preg_match('/(\d+)\D+(\d+)/', $value, $m)) {
            return $out('SCORE_' . (int) $m[1] . '_' . (int) $m[2]);
        }

        $fallback = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $original) ?? $original);
        return $out(trim($fallback, '_'), false);
    }
}
