<?php
namespace AIWorkforce\Football;

use AIWorkforce\Sports\OddsBounds;

/**
 * The odds-prediction markets the Football Intelligence Engine can answer.
 *
 * A market is a *view* over one stored prediction, never a second prediction.
 * That distinction is what keeps the module's central promise intact: choosing
 * "Over 2.5 Goals" instead of "Match Winner" re-reads the same persisted
 * match row and the same score grid, so **changing market never regenerates a
 * match and never costs a provider call**.
 *
 * Where the numbers come from:
 *
 *  - `SCORE_GRID` — the joint distribution over (home goals, away goals) that
 *    the engine already stores in `football_score_probabilities`. Totals,
 *    both-teams-to-score, double chance, draw-no-bet, correct score and Asian
 *    handicap are sums over that grid: arithmetic on stored numbers, not a new
 *    model and not an invented figure. The same grid answers the wider sheet —
 *    odd/even, goal bands, per-team goals, clean sheets, winning margin and
 *    result-and-BTTS are each a different predicate over the same cells, which
 *    is why adding them costs no extra model, query or provider call.
 *  - `STORED_1X2` — the 1X2 row stored with the prediction.
 *  - `FIRST_HALF_SHARE` / `SECOND_HALF_SHARE` — the same score model run on the
 *    configured share of the match goal expectancy (and, for the second half,
 *    on the share the first half does not claim). The share is an assumption,
 *    so it is named in the market's `basis` and configurable, never hidden.
 *
 * Every market added to the catalogue must be *exhaustive*: its legs are
 * mutually exclusive and cover the whole distribution. That is what lets the
 * bookmaker margin be removed honestly, and it is asserted per market by the
 * test suite rather than assumed here.
 *  - `NOT_MODELLED` — corners, cards and half-time/full-time. The module has no
 *    stored input for these, so they are reported as `DATA_UNAVAILABLE` unless
 *    the connected odds provider supplied the price itself.
 *
 * Odds are never manufactured either. A market shows a price only when an odds
 * row exists for that match; otherwise the odds field is `DATA_UNAVAILABLE`
 * with the reason stated. The model's own probability is always shown, because
 * that one is computed from stored data.
 */
final class PredictionMarkets
{
    /** The market a caller gets when it does not name one. */
    public const DEFAULT_MARKET = 'MATCH_WINNER';

    /** Who produced the number that is being shown. */
    public const SOURCE_GRID = 'MODEL_DERIVED';
    public const SOURCE_ASSUMED = 'MODEL_DERIVED_ASSUMED';
    public const SOURCE_ODDS = 'PROVIDER_ODDS';

    public const STATE_AVAILABLE = 'AVAILABLE';
    public const STATE_UNAVAILABLE = 'DATA_UNAVAILABLE';

    /**
     * Risk levels attached to every prediction result. They are a reading of
     * the inputs, not a mood: each level names the factors that produced it, so
     * "why is this high risk" has an answer in the payload itself.
     */
    public const RISK_LOW = 'LOW';
    public const RISK_MEDIUM = 'MEDIUM';
    public const RISK_HIGH = 'HIGH';

    /** Bands that make a prediction a weaker basis for a selection. */
    private const WEAK_BANDS = [QualityBand::REJECTED];

    /**
     * Correct score lists every scoreline the grid meaningfully reaches. A cell
     * below this probability is noise rather than a quotable scoreline, but the
     * leaders are always kept even in a wide-open match.
     */
    private const CORRECT_SCORE_MIN_PROBABILITY = 0.001;
    private const CORRECT_SCORE_MIN_ROWS = 12;

    /**
     * The catalogue. `derivation` says which stored input answers the market,
     * `line` carries the goal line or handicap line where one applies, and
     * `assumed` marks a market that rests on a stated, configurable assumption
     * rather than on a stored per-half input.
     *
     * @return list<array{key:string,label:string,group:string,derivation:string,line:?float,assumed:bool,selections:list<string>}>
     */
    public function catalog(): array
    {
        $out = [];
        foreach (self::MARKETS as $market) {
            $out[] = [
                'key' => $market['key'],
                'label' => $market['label'],
                'group' => $market['group'],
                'derivation' => $market['derivation'],
                'line' => $market['line'] ?? null,
                'assumed' => (bool) ($market['assumed'] ?? false),
                'selections' => $this->selectionLabels($market['key']),
            ];
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    public function market(string $key): ?array
    {
        foreach ($this->catalog() as $market) {
            if ($market['key'] === $key) return $market;
        }
        return null;
    }

    /**
     * The lines a line-based market is quoted at.
     *
     * A bookmaker does not price "the" Asian handicap — it prices a ladder of
     * them, and "Home -1.5" is a different bet from "Home -0.5" with a
     * different probability and a different price. The catalogue advertises one
     * default line per market so that a market *selector* stays short; this is
     * the full ladder the all-odds sheet walks, so a match shows every line
     * rather than a single representative one.
     *
     * Every line here is priced by the same grid arithmetic as the default one
     * — a ladder adds coverage, never a new model. Quarter lines are included
     * for the handicap because `handicap()` settles them by splitting the stake
     * across the two bounding half lines, which is how they really settle.
     *
     * @return list<float>
     */
    public function linesFor(string $marketKey): array
    {
        return match (self::priceFamily($marketKey)) {
            'OVER_UNDER' => [0.5, 1.5, 2.5, 3.5, 4.5, 5.5, 6.5],
            'HOME_TEAM_TOTAL_GOALS', 'AWAY_TEAM_TOTAL_GOALS' => [0.5, 1.5, 2.5, 3.5],
            'FIRST_HALF_OVER_UNDER', 'SECOND_HALF_OVER_UNDER' => [0.5, 1.5, 2.5],
            'ASIAN_HANDICAP' => [-2.5, -2.0, -1.75, -1.5, -1.25, -1.0, -0.75, -0.5, -0.25, 0.0,
                0.25, 0.5, 0.75, 1.0, 1.25, 1.5, 1.75, 2.0, 2.5],
            default => [],
        };
    }

    /**
     * The complete per-match sheet: every catalogue market, expanded across
     * every line it is quoted at.
     *
     * Returned entries have the same shape as catalogue entries, so a caller
     * evaluates them through the existing batched pass. A line-based market
     * contributes one entry per line on its ladder; every other market
     * contributes itself unchanged.
     *
     * @return list<array<string,mixed>>
     */
    public function fullSheet(): array
    {
        $out = [];
        $walked = [];
        foreach ($this->catalog() as $market) {
            $key = (string) $market['key'];
            $lines = $this->linesFor($key);
            if ($lines === []) { $out[] = $market; continue; }
            // OVER_0_5 … OVER_6_5 and UNDER_1_5 … UNDER_3_5 are ten catalogue
            // keys describing ONE bookmaker family. The ladder belongs to the
            // family, so it is walked exactly once — expanding per key would
            // print every goal line ten times over.
            $family = self::priceFamily($key);
            if (isset($walked[$family])) continue;
            $walked[$family] = true;
            foreach ($lines as $line) {
                $entry = $market;
                $entry['key'] = self::ladderKey($family, $line);
                $entry['line'] = $line;
                $entry['label'] = self::lineLabelFor($key, $line);
                $out[] = $entry;
            }
        }
        return $out;
    }

    /**
     * The catalogue key of one rung of a ladder.
     *
     * Goal lines keep the canonical OVER_<line> spelling so that the rest of
     * the engine — `outcomes()`, `providerMarketMatches()`, the OddsBounds
     * ceiling — recognises them exactly as it recognises the fixed catalogue
     * keys. Every other family carries its line separately and keeps one key.
     */
    private static function ladderKey(string $family, float $line): string
    {
        if ($family !== 'OVER_UNDER') return $family;
        return 'OVER_' . str_replace('.', '_', self::lineLabel($line));
    }

    /** The display label of one rung of a ladder. */
    private static function lineLabelFor(string $marketKey, float $line): string
    {
        $label = self::lineLabel($line);
        return match (self::priceFamily($marketKey)) {
            'OVER_UNDER' => 'Total Goals — Over/Under ' . $label,
            'HOME_TEAM_TOTAL_GOALS' => 'Home Team Total Goals — ' . $label,
            'AWAY_TEAM_TOTAL_GOALS' => 'Away Team Total Goals — ' . $label,
            'FIRST_HALF_OVER_UNDER' => 'First Half Goals — ' . $label,
            'SECOND_HALF_OVER_UNDER' => 'Second Half Goals — ' . $label,
            'ASIAN_HANDICAP' => 'Asian Handicap — Home ' . self::handicapLabel($line),
            default => $label,
        };
    }

    /**
     * A requested market, with the documented default when it is absent or not
     * one this engine offers. An unknown market is *reported*, not silently
     * swapped for a plausible-looking neighbour.
     *
     * @param list<string> $notes
     * @return array{key:string,market:array<string,mixed>}
     */
    public function resolve(?string $key, array &$notes = [], string $param = 'market'): array
    {
        if ($key === null || trim($key) === '') return ['key' => self::DEFAULT_MARKET, 'market' => $this->market(self::DEFAULT_MARKET)];
        $wanted = strtoupper(trim($key));
        if ($wanted === 'PREMIUM' || $wanted === 'DEFAULT') $wanted = self::DEFAULT_MARKET;
        if (isset(self::ALIASES[$wanted])) $wanted = self::ALIASES[$wanted];
        $market = $this->market($wanted);
        if ($market === null) {
            $notes[] = $param . '=' . RequestParams::preview($key) . ' is not a market this engine offers; '
                . self::DEFAULT_MARKET . ' was analyzed instead.';
            return ['key' => self::DEFAULT_MARKET, 'market' => $this->market(self::DEFAULT_MARKET)];
        }
        return ['key' => $wanted, 'market' => $market];
    }

    /**
     * The catalogue annotated with whether each market can currently be
     * answered: every grid market is always derivable, an unmodelled market is
     * only available when the connected odds provider has priced it, and that
     * is stated per market rather than hidden behind a shorter list.
     *
     * @param list<string> $providerMarkets market codes the odds feed has rows for
     * @return list<array<string,mixed>>
     */
    public function available(array $providerMarkets = []): array
    {
        $supplied = [];
        foreach ($providerMarkets as $code) $supplied[self::normalizeProviderMarket((string) $code)] = true;
        $out = [];
        foreach ($this->catalog() as $market) {
            $priced = false;
            foreach (array_keys($supplied) as $code) {
                if (self::providerMarketMatches((string) $code, (string) $market['key'])) { $priced = true; break; }
            }
            $market['state'] = $market['derivation'] === 'NOT_MODELLED'
                ? ($priced ? self::STATE_AVAILABLE : self::STATE_UNAVAILABLE)
                : self::STATE_AVAILABLE;
            $market['oddsAvailable'] = $priced;
            $market['reason'] = $market['state'] === self::STATE_UNAVAILABLE
                ? 'No stored input models this market and the connected odds provider has not priced it.'
                : null;
            $out[] = $market;
        }
        return $out;
    }

    /**
     * Provider-supplied markets that are not represented by a catalogue slot.
     *
     * The fixed catalogue remains the stable model contract, but an odds feed
     * can legitimately quote another totals/first-half/handicap line or an
     * entirely provider-only family. Those real prices belong on an all-odds
     * sheet too. Returned entries have the same shape as catalogue entries, so
     * callers can evaluate them in the existing batched pass without creating
     * odds or probabilities the provider/model did not supply.
     *
     * @param list<array{market:string,selection:string}> $odds
     * @return list<array<string,mixed>>
     */
    public function additionalProviderMarkets(array $odds): array
    {
        $signature = static fn(string $family, ?float $line): string => $family . '|'
            . ($line === null ? 'NONE' : rtrim(rtrim(number_format($line, 4, '.', ''), '0'), '.'));
        $represented = [];
        // Every rung of every ladder counts as represented, so a provider line
        // the sheet already walks is not reported a second time as an "extra".
        foreach ($this->fullSheet() as $market) {
            $family = self::priceFamily((string) $market['key']);
            $line = self::familyCarriesLine($family) && is_numeric($market['line'] ?? null)
                ? (float) $market['line'] : null;
            $represented[$signature($family, $line)] = true;
        }

        $groups = [];
        foreach ($odds as $row) {
            $rawMarket = trim((string) ($row['market'] ?? ''));
            if ($rawMarket === '') continue;
            $normalized = self::normalizeProviderMarket($rawMarket);
            $family = in_array($normalized, ['TOTAL_GOALS', 'OVER_UNDER'], true) ? 'OVER_UNDER'
                : ($normalized === 'HALF_TIME_GOALS' ? 'FIRST_HALF_OVER_UNDER' : self::priceFamily($normalized));
            $line = null;
            if (self::familyCarriesLine($family)) {
                $selection = self::normalizeProviderSelection($rawMarket, (string) ($row['selection'] ?? ''));
                $line = self::lineOf((string) ($row['selection'] ?? ''));
                // Store Asian lines from the home perspective: Away +0.75 is
                // the companion leg of Home -0.75.
                if ($family === 'ASIAN_HANDICAP' && $selection === 'AWAY' && $line !== null) $line *= -1;
                if ($line === null) continue;
            }
            $sig = $signature($family, $line);
            if (isset($represented[$sig])) continue;
            $groups[$sig] = ['family' => $family, 'line' => $line, 'rawMarket' => $rawMarket];
        }

        $out = [];
        foreach ($groups as $group) {
            $family = (string) $group['family'];
            $line = is_numeric($group['line'] ?? null) ? (float) $group['line'] : null;
            $lineLabel = $line === null ? '' : self::lineLabel($line);
            if ($family === 'OVER_UNDER') {
                $out[] = ['key' => 'OVER_' . str_replace(['-', '.'], ['MINUS_', '_'], $lineLabel),
                    'label' => 'Total Goals — ' . $lineLabel, 'group' => 'Goals', 'derivation' => 'SCORE_GRID',
                    'line' => $line, 'assumed' => false, 'selections' => ['Over', 'Under']];
                continue;
            }
            if ($family === 'FIRST_HALF_OVER_UNDER') {
                $out[] = ['key' => 'FIRST_HALF_OVER_UNDER', 'label' => 'First Half Goals — ' . $lineLabel,
                    'group' => 'First half', 'derivation' => 'SCORE_GRID', 'line' => $line,
                    'assumed' => true, 'selections' => ['Over', 'Under']];
                continue;
            }
            if ($family === 'SECOND_HALF_OVER_UNDER') {
                $out[] = ['key' => 'SECOND_HALF_OVER_UNDER', 'label' => 'Second Half Goals — ' . $lineLabel,
                    'group' => 'Second half', 'derivation' => 'SCORE_GRID', 'line' => $line,
                    'assumed' => true, 'selections' => ['Over', 'Under']];
                continue;
            }
            if ($family === 'HOME_TEAM_TOTAL_GOALS' || $family === 'AWAY_TEAM_TOTAL_GOALS') {
                $side = $family === 'HOME_TEAM_TOTAL_GOALS' ? 'Home' : 'Away';
                $out[] = ['key' => $family, 'label' => $side . ' Team Total Goals — ' . $lineLabel,
                    'group' => 'Team goals', 'derivation' => 'SCORE_GRID', 'line' => $line,
                    'assumed' => false, 'selections' => ['Over', 'Under']];
                continue;
            }
            if ($family === 'ASIAN_HANDICAP') {
                $out[] = ['key' => 'ASIAN_HANDICAP', 'label' => 'Asian Handicap — Home ' . ($line > 0 ? '+' : '') . $lineLabel,
                    'group' => 'Handicap', 'derivation' => 'SCORE_GRID', 'line' => $line,
                    'assumed' => false, 'selections' => ['Home', 'Away']];
                continue;
            }
            $key = self::normalizeProviderMarket((string) $group['rawMarket']);
            if ($key === '') continue;
            $out[] = ['key' => $key, 'label' => ucwords(strtolower(str_replace('_', ' ', $key))),
                'group' => 'Additional provider markets', 'derivation' => 'NOT_MODELLED', 'line' => null,
                'assumed' => false, 'selections' => []];
        }
        return $out;
    }

    /**
     * Every price the odds feed quoted for the *market* a catalogue key belongs
     * to, not only for the selection being shown.
     *
     * A margin can only be taken out of a complete set: the 1X2 overround is the
     * sum of Home, Draw and Away, and a sheet holding two of those three has no
     * overround at all. `evaluate()` therefore reads the whole family, so
     * `OddsIntelligence` can say which of the three states it is looking at —
     * priced complete, priced partially, or not priced.
     *
     * @param list<array{market:string,selection:string,decimalOdds:float,observedAt:?string}> $odds
     * @return array{state:string,family:string,line:?float,expected:list<string>,quotes:array<string,array{odds:float,observedAt:?string,source:?string,low:float,high:float,quotes:int}>,ignored:int,note:?string}
     */
    public function priceSheet(array $odds, string $marketKey, ?float $line = null): array
    {
        $family = self::priceFamily($marketKey);
        $expected = self::FAMILY_SELECTIONS[$family] ?? [];
        $lineChecked = self::familyCarriesLine($family);
        $line = $line ?? self::catalogLine($marketKey);
        $quotes = [];
        $ignored = 0;
        foreach ($odds as $row) {
            $rawMarket = (string) ($row['market'] ?? '');
            if (!self::providerMarketMatches(self::normalizeProviderMarket($rawMarket), $marketKey)) continue;
            $selection = self::normalizeProviderSelection($rawMarket, (string) ($row['selection'] ?? ''));
            // A leg outside the priced set is not evidence about this market: a
            // draw price on Draw No Bet, or a scoreline on Correct Score, is
            // counted as ignored rather than folded into an overround.
            if ($expected !== [] && !in_array($selection, $expected, true)) { $ignored++; continue; }
            if ($lineChecked && !self::lineMatches($marketKey, $selection, (string) ($row['selection'] ?? ''), (float) $line)) {
                $ignored++;
                continue;
            }
            $price = is_numeric($row['decimalOdds'] ?? null) ? (float) $row['decimalOdds'] : null;
            // A leg is only evidence when its price is quotable: sub-stake,
            // non-finite and absurd (above the market's plausibility ceiling)
            // prices are counted as ignored, never folded into an overround.
            if ($price === null || !OddsBounds::validDecimalOdds($price, $marketKey)) { $ignored++; continue; }
            $observed = (string) ($row['observedAt'] ?? '');
            $seen = $quotes[$selection] ?? null;
            $newest = $seen === null || $observed >= (string) ($seen['observedAt'] ?? '');
            $rowSource = $row['provider'] ?? $row['oddsSource'] ?? null;
            $rowSource = is_string($rowSource) && $rowSource !== '' ? $rowSource : null;
            $quotes[$selection] = [
                'odds' => $newest ? $price : (float) $seen['odds'],
                'observedAt' => $newest && $observed !== '' ? $observed : ($seen['observedAt'] ?? null),
                'source' => $newest ? $rowSource : ($seen['source'] ?? null),
                'low' => $seen === null ? $price : min((float) $seen['low'], $price),
                'high' => $seen === null ? $price : max((float) $seen['high'], $price),
                'quotes' => (int) ($seen['quotes'] ?? 0) + 1,
            ];
        }
        $priced = array_keys($quotes);
        $complete = $expected !== [] && count(array_intersect($expected, $priced)) === count($expected);
        $state = $quotes === [] ? OddsIntelligence::PRICE_NONE
            : ($expected === [] ? OddsIntelligence::PRICE_NOT_EXHAUSTIVE
                : ($complete ? OddsIntelligence::PRICE_COMPLETE : OddsIntelligence::PRICE_PARTIAL));
        $note = null;
        if ($state === OddsIntelligence::PRICE_NOT_EXHAUSTIVE) {
            $note = 'This market does not price out a set of mutually exclusive outcomes, so no overround exists to remove '
                . 'and no margin-free probability can be derived from it.';
        } elseif ($state === OddsIntelligence::PRICE_PARTIAL) {
            $note = 'Only ' . count($priced) . ' of the ' . count($expected) . ' legs of this market are priced, so the margin '
                . 'cannot be removed; the comparison is made against the quoted price as it stands.';
        } elseif ($state === OddsIntelligence::PRICE_NONE) {
            $note = 'No odds row exists for this market, so WINDELS\' probability has no price to be compared with.';
        } elseif ($ignored > 0) {
            $note = $ignored . ' quoted leg' . ($ignored === 1 ? ' was' : 's were') . ' not usable for this market'
                . ' (outside the priced set' . ($lineChecked ? ', off the ' . self::lineLabel($line) . ' line' : '') . ' or failed price validation).';
        }
        return ['state' => $state, 'family' => $family, 'line' => $lineChecked ? $line : null,
            'expected' => $expected, 'quotes' => $quotes, 'ignored' => $ignored, 'note' => $note];
    }

    /**
     * Which quoted family a catalogue key belongs to. Over/Under and Under are
     * one market to a bookmaker and two keys here, so the family — not the key —
     * decides what a complete set of prices looks like.
     */
    private static function priceFamily(string $key): string
    {
        if (str_starts_with($key, 'OVER_') || str_starts_with($key, 'UNDER_')) return 'OVER_UNDER';
        // Every other key is its own family: a half market, a team-goals
        // market and a combination market each price out their own set of
        // mutually exclusive legs and must never be de-vigged against another.
        return $key;
    }

    /** Markets whose price belongs to a stated line: an "Over 3.5" quote is not an "Over 2.5" quote. */
    private static function familyCarriesLine(string $family): bool
    {
        return in_array($family, ['OVER_UNDER', 'FIRST_HALF_OVER_UNDER', 'SECOND_HALF_OVER_UNDER',
            'HOME_TEAM_TOTAL_GOALS', 'AWAY_TEAM_TOTAL_GOALS', 'ASIAN_HANDICAP'], true);
    }

    /** The catalogue's own line for a market key, when the market states one. */
    private static function catalogLine(string $marketKey): ?float
    {
        foreach (self::MARKETS as $market) {
            if ((string) ($market['key'] ?? '') === $marketKey) return isset($market['line']) ? (float) $market['line'] : null;
        }
        return null;
    }

    /**
     * The legs that make one market exhaustive. A family missing from this map
     * is one whose full set WINDELS cannot know (Correct Score, Corners, Cards),
     * and such a market is never de-vigged.
     */
    private const FAMILY_SELECTIONS = [
        'MATCH_WINNER' => ['HOME', 'DRAW', 'AWAY'],
        'FIRST_HALF_WINNER' => ['HOME', 'DRAW', 'AWAY'],
        'DOUBLE_CHANCE' => ['HOME_OR_DRAW', 'HOME_OR_AWAY', 'AWAY_OR_DRAW'],
        'DRAW_NO_BET' => ['HOME', 'AWAY'],
        'OVER_UNDER' => ['OVER', 'UNDER'],
        'FIRST_HALF_OVER_UNDER' => ['OVER', 'UNDER'],
        'BTTS' => ['YES', 'NO'],
        'BTTS_AND_OVER_2_5' => ['YES', 'NO'],
        'BTTS_AND_UNDER_2_5' => ['YES', 'NO'],
        'ASIAN_HANDICAP' => ['HOME', 'AWAY'],
        'TOTAL_GOALS_ODD_EVEN' => ['ODD', 'EVEN'],
        'TOTAL_GOALS_BAND' => ['BAND_0_1', 'BAND_2_3', 'BAND_4_6', 'BAND_7_PLUS'],
        'HOME_TEAM_TOTAL_GOALS' => ['OVER', 'UNDER'],
        'AWAY_TEAM_TOTAL_GOALS' => ['OVER', 'UNDER'],
        'HOME_CLEAN_SHEET' => ['YES', 'NO'],
        'AWAY_CLEAN_SHEET' => ['YES', 'NO'],
        'WINNING_MARGIN' => ['HOME_1', 'HOME_2', 'HOME_3_PLUS', 'DRAW', 'AWAY_1', 'AWAY_2', 'AWAY_3_PLUS'],
        'RESULT_AND_BTTS' => ['HOME_YES', 'HOME_NO', 'DRAW_YES', 'DRAW_NO', 'AWAY_YES', 'AWAY_NO'],
        'FIRST_HALF_DOUBLE_CHANCE' => ['HOME_OR_DRAW', 'HOME_OR_AWAY', 'AWAY_OR_DRAW'],
        'FIRST_HALF_BTTS' => ['YES', 'NO'],
        'SECOND_HALF_WINNER' => ['HOME', 'DRAW', 'AWAY'],
        'SECOND_HALF_OVER_UNDER' => ['OVER', 'UNDER'],
    ];

    /**
     * The risk level of one prediction result.
     *
     * Deterministic, and derived only from what is actually known about the
     * selection: whether the market could be answered at all, how much of the
     * score distribution it saw, the data-quality band the prediction was
     * admitted under, how likely the selection is, what the market is charging
     * for it, and whether the model sees value against that price. Each factor
     * that counted is named, so the level can be argued with.
     *
     * @return array{level:string, factors:list<string>}
     */
    public function risk(
        string $state,
        float $coverage,
        string $band,
        ?float $probability,
        ?float $odds,
        ?float $edge,
        string $source = self::SOURCE_GRID,
    ): array {
        if ($state === self::STATE_UNAVAILABLE) {
            return ['level' => self::RISK_HIGH,
                'factors' => ['The market could not be evaluated from stored data, so no selection is being offered.']];
        }
        if ($source === self::SOURCE_ODDS && $probability === null) {
            return ['level' => self::RISK_HIGH,
                'factors' => ['Provider price only — no WINDELS model probability exists for this selection.']];
        }
        $factors = [];
        $points = 0;
        if (in_array($band, self::WEAK_BANDS, true)) { $points += 3; $factors[] = 'data quality band ' . $band; }
        elseif ($band === QualityBand::LIMITED) { $points += 1; $factors[] = 'data quality band ' . $band; }
        if ($coverage < 0.9) { $points += 1; $factors[] = 'score-grid coverage ' . round($coverage * 100, 1) . '%'; }
        if ($probability !== null) {
            if ($probability < 0.45) { $points += 2; $factors[] = 'selection probability ' . round($probability * 100, 1) . '%'; }
            elseif ($probability < 0.55) { $points += 1; $factors[] = 'selection probability ' . round($probability * 100, 1) . '%'; }
        }
        if ($odds !== null) {
            if ($odds >= 5.0) { $points += 2; $factors[] = 'price ' . $odds; }
            elseif ($odds >= 3.0) { $points += 1; $factors[] = 'price ' . $odds; }
        }
        if ($edge !== null && $edge < 0) { $points += 1; $factors[] = 'model sees no edge against the quoted price'; }
        if ($source === self::SOURCE_ASSUMED) { $points += 1; $factors[] = 'the market rests on a stated assumption'; }
        if ($factors === []) $factors[] = 'stored data supports the selection and the price, if any, is not against it';
        return ['level' => $points >= 4 ? self::RISK_HIGH : ($points >= 2 ? self::RISK_MEDIUM : self::RISK_LOW),
            'factors' => $factors];
    }

    /**
     * Evaluate one market for one stored prediction.
     *
     * @param array<string,mixed> $prediction the stored prediction row
     * @param list<array{home:int,away:int,probability:float}> $grid the score grid
     * @param list<array{market:string,selection:string,decimalOdds:float,observedAt:?string}> $odds odds rows for this match
     * @param array<string,mixed> $market the catalogue entry (`resolve()`)
     * @param ?float $line an explicit goal/handicap line when the caller named one
     * @return array<string,mixed>
     */
    public function evaluate(array $prediction, array $grid, array $odds, array $market, ?float $line = null): array
    {
        $key = (string) $market['key'];
        $line = $line ?? (isset($market['line']) ? (float) $market['line'] : null);
        [$resolvedGrid, $gridBasis, $coverage] = $this->gridFor($prediction, $grid);
        $rows = $this->outcomes($prediction, $resolvedGrid, $key, $line);
        if ($key === 'CORRECT_SCORE') {
            $rows = $this->includeQuotedScores($rows, $resolvedGrid, $odds);
        }
        // Corners, cards, unknown provider families, and even familiar model
        // markets on an as-yet unanalyzed fixture still belong on an all-odds
        // sheet when real quotes exist. Keep those selections with null model
        // probability rather than hiding the price or inventing an estimate.
        $providerOnlyFallback = false;
        if ($rows === []) {
            $rows = $this->providerOnlyOutcomes($odds, $key, $line);
            $providerOnlyFallback = $rows !== [];
        } elseif (!array_reduce($rows, static fn(bool $has, array $row): bool => $has || is_numeric($row['probability'] ?? null), false)) {
            $providerOnlyFallback = true;
        }
        $state = $rows === [] ? self::STATE_UNAVAILABLE : self::STATE_AVAILABLE;
        $basis = $rows === [] ? null : ($providerOnlyFallback ? 'PROVIDER_QUOTES_ONLY'
            : $this->basis($key, (string) $market['derivation'], $gridBasis));
        $source = $providerOnlyFallback || $market['derivation'] === 'NOT_MODELLED' ? self::SOURCE_ODDS
            : ((bool) ($market['assumed'] ?? false) ? self::SOURCE_ASSUMED : self::SOURCE_GRID);

        // Odds are attached per selection from the rows the provider actually
        // sent. Nothing here derives a price from a probability: an implied
        // price would be a number the feed never quoted.
        //
        // The price *sheet* is read over the whole market rather than per
        // selection, because the margin is a property of the market: it is the
        // amount by which all its prices together exceed certainty, and it can
        // only be taken out of a set of mutually exclusive ones.
        $sheet = $this->fairValue()->withFairValues($this->priceSheet($odds, $key, $line));
        foreach ($rows as &$row) {
            $quoted = $this->quoted($odds, $key, (string) $row['selection'], $line);
            $row['odds'] = $quoted['decimalOdds'];
            $row['impliedProbability'] = $quoted['decimalOdds'] !== null && $quoted['decimalOdds'] > 0
                ? round(1 / $quoted['decimalOdds'], 6) : null;
            // A provider-price-only market deliberately has no model
            // probability. Its edge and expected value are unknown too — zero
            // is not an honest substitute for an unavailable comparison.
            $modelProbability = is_numeric($row['probability'] ?? null) ? (float) $row['probability'] : null;
            $row['edge'] = $modelProbability !== null && $row['impliedProbability'] !== null
                ? round($modelProbability - $row['impliedProbability'], 6) : null;
            $row['oddsObservedAt'] = $quoted['observedAt'];
            $row['oddsSource'] = $quoted['provider'];
            $row['oddsState'] = $quoted['decimalOdds'] === null ? self::STATE_UNAVAILABLE : self::STATE_AVAILABLE;
            // Keep the complete stored quote reading alongside the chosen
            // latest price. It tells the reader whether a price is one isolated
            // quote or a range of quotes without substituting a synthetic best
            // price for what the provider actually supplied.
            $quoteMeta = is_array($sheet['quotes'][(string) $row['selection']] ?? null)
                ? $sheet['quotes'][(string) $row['selection']] : [];
            $row['quoteCount'] = isset($quoteMeta['quotes']) ? (int) $quoteMeta['quotes'] : ($quoted['decimalOdds'] === null ? 0 : 1);
            $row['oddsLow'] = is_numeric($quoteMeta['low'] ?? null) ? round((float) $quoteMeta['low'], 4) : $quoted['decimalOdds'];
            $row['oddsHigh'] = is_numeric($quoteMeta['high'] ?? null) ? round((float) $quoteMeta['high'], 4) : $quoted['decimalOdds'];
            $row['oddsSpread'] = is_numeric($row['oddsLow']) && is_numeric($row['oddsHigh'])
                ? round((float) $row['oddsHigh'] - (float) $row['oddsLow'], 4) : null;
            $value = $this->fairValue()->assess($sheet, (string) $row['selection'], $modelProbability);
            $row['windelsFairOdds'] = $value['windelsFairOdds'];
            // Keep the complete price comparison on every outcome. The board
            // and the dedicated match page can now explain a quote without
            // re-running arithmetic in a template: bookmaker fair probability
            // and odds (after margin removal), break-even probability, both
            // edge readings, expected return and the reason for the verdict.
            $row['fairOdds'] = $value['fairOdds'];
            $row['fairProbability'] = $value['fairProbability'];
            $row['breakEvenProbability'] = $value['breakEvenProbability'];
            $row['edge'] = $value['edge'];
            $row['edgePoints'] = $value['edgePoints'];
            $row['edgeAgainstFair'] = $value['edgeAgainstFair'];
            $row['edgeAgainstFairPoints'] = $value['edgeAgainstFairPoints'];
            $row['expectedValue'] = $value['expectedValue'];
            $row['marginMethod'] = $value['marginMethod'];
            $row['valueClass'] = $value['valueClass'];
            $row['valueLabel'] = $value['valueLabel'];
            $row['valueReason'] = $value['valueReason'];
        }
        unset($row);

        $best = null;
        foreach ($rows as $row) {
            if (!is_numeric($row['probability'])) continue;
            if ($best === null || (float) $row['probability'] > (float) $best['probability']) $best = $row;
        }
        // Price-only markets do not have a model pick. Keep one of their real
        // quotes at market level for compact tables while the full `outcomes`
        // list continues to show every price and selection.
        if ($best === null && $rows !== []) {
            foreach ($rows as $row) {
                if (($row['oddsState'] ?? '') === self::STATE_AVAILABLE) { $best = $row; break; }
            }
            $best ??= $rows[0];
        }
        $risk = $this->risk($state, $coverage, (string) ($prediction['data_quality_band'] ?? QualityBand::REJECTED),
            $best['probability'] ?? null, $best['odds'] ?? null, $best['edge'] ?? null, $source);
        // The market-level answer to "is this price attractive": WINDELS' own
        // fair price, the market's price, the margin inside the market's price,
        // the gap in probability points, and the expected return per unit — with
        // the class the gap earns and the sentence that explains it.
        $value = $this->fairValue()->assess($sheet, (string) ($best['selection'] ?? ''),
            isset($best['probability']) && is_numeric($best['probability']) ? (float) $best['probability'] : null);
        return [
            'key' => $key,
            'label' => (string) $market['label'],
            'group' => (string) $market['group'],
            'line' => $line,
            'state' => $state,
            'reason' => $state === self::STATE_UNAVAILABLE ? $this->unavailableReason($key, $grid) : null,
            'source' => $source,
            'basis' => $basis,
            'riskLevel' => $risk['level'],
            'riskFactors' => $risk['factors'],
            // How much of the score distribution the market could see. A sum
            // over a partial grid would under-report draws, handicaps and
            // both-teams-to-score, so the share is stated rather than assumed.
            'coverage' => round($coverage, 6),
            'selection' => $best['selection'] ?? null,
            'selectionLabel' => $best['label'] ?? null,
            'probability' => isset($best['probability']) ? round((float) $best['probability'], 6) : null,
            'odds' => $best['odds'] ?? null,
            'oddsSource' => $best['oddsSource'] ?? null,
            'impliedProbability' => $best['impliedProbability'] ?? null,
            'edge' => $best['edge'] ?? null,
            'value' => $value,
            'pricing' => [
                'state' => (string) $sheet['state'],
                'family' => (string) $sheet['family'],
                'line' => $sheet['line'],
                'legsPriced' => count((array) $sheet['quotes']),
                'legsExpected' => count((array) $sheet['expected']),
                // The feeds behind the quoted legs — every price names its
                // source, so a quote can always be traced back to the feed.
                'sources' => array_values(array_unique(array_filter(array_map(
                    fn($q) => is_array($q) ? ($q['source'] ?? null) : null,
                    (array) ($sheet['quotes'] ?? [])
                )))),
                'overround' => $sheet['overround'] ?? null,
                'marginPoints' => $sheet['marginPoints'] ?? null,
                'marginMethod' => $sheet['marginMethod'] ?? null,
                'pricedAt' => $sheet['pricedAt'] ?? null,
                'pricedAgoSeconds' => $sheet['pricedAgoSeconds'] ?? null,
                'priceStale' => (bool) ($sheet['priceStale'] ?? false),
                'staleAfterSeconds' => $sheet['staleAfterSeconds'] ?? null,
                'note' => $sheet['note'],
                'disclaimer' => OddsIntelligence::DISCLAIMER,
            ],

            'confidence' => isset($prediction['confidence']) ? round((float) $prediction['confidence'], 1) : null,
            'dataQuality' => (int) ($prediction['data_quality_score'] ?? 0),
            'band' => (string) ($prediction['data_quality_band'] ?? QualityBand::REJECTED),
            'outcomes' => $rows,
        ];
    }

    /**
     * The selection outcomes of one market, summed over the stored grid.
     *
     * @param list<array{home:int,away:int,probability:float}> $grid
     * @return list<array{selection:string,label:string,probability:?float,note:?string}>
     */
    private function outcomes(array $prediction, array $grid, string $key, ?float $line): array
    {
        switch ($key) {
            case 'MATCH_WINNER':
                return $this->triple($prediction, 'Home', 'Draw', 'Away');
            case 'DOUBLE_CHANCE':
                $p = $this->triple($prediction, 'Home', 'Draw', 'Away');
                return $this->combine([
                    'HOME_OR_DRAW' => ['Home or Draw', $this->sum($p, ['HOME', 'DRAW'])],
                    'HOME_OR_AWAY' => ['Home or Away', $this->sum($p, ['HOME', 'AWAY'])],
                    'AWAY_OR_DRAW' => ['Draw or Away', $this->sum($p, ['AWAY', 'DRAW'])],
                ]);
            case 'DRAW_NO_BET':
                $p = $this->triple($prediction, 'Home', 'Draw', 'Away');
                $home = $this->at($p, 'HOME'); $away = $this->at($p, 'AWAY');
                $total = $home + $away;
                if ($total <= 0) return [];
                return $this->combine([
                    'HOME' => ['Home (draw no bet)', $home / $total],
                    'AWAY' => ['Away (draw no bet)', $away / $total],
                ]);
        }
        if ($grid === []) return [];

        // Totals lines are open-ended in provider feeds. The stable catalogue
        // advertises the common lines, while additionalProviderMarkets() can
        // pass any other quoted line through the same score-grid arithmetic.
        if (str_starts_with($key, 'OVER_')) {
            $over = $this->totals($grid, static fn(int $total): bool => $total > (float) $line);
            return $this->combine(['OVER' => ['Over ' . self::lineLabel($line) . ' goals', $over],
                'UNDER' => ['Under ' . self::lineLabel($line) . ' goals', 1 - $over]]);
        }
        if (str_starts_with($key, 'UNDER_')) {
            $under = $this->totals($grid, static fn(int $total): bool => $total < (float) $line);
            return $this->combine(['UNDER' => ['Under ' . self::lineLabel($line) . ' goals', $under],
                'OVER' => ['Over ' . self::lineLabel($line) . ' goals', 1 - $under]]);
        }

        switch ($key) {
            case 'BTTS':
                $yes = $this->totals($grid, null, static fn(int $h, int $a): bool => $h > 0 && $a > 0);
                return $this->combine(['YES' => ['Both teams score', $yes], 'NO' => ['One side fails to score', 1 - $yes]]);
            case 'BTTS_AND_OVER_2_5':
                $yes = $this->totals($grid, null, static fn(int $h, int $a): bool => $h > 0 && $a > 0 && ($h + $a) > 2.5);
                return $this->combine(['YES' => ['Both teams score and over 2.5 goals', $yes],
                    'NO' => ['Anything else', 1 - $yes]]);
            case 'BTTS_AND_UNDER_2_5':
                // The only scorelines that satisfy both legs are 1–1: any other
                // both-scored game already has three goals in it.
                $yes = $this->totals($grid, null, static fn(int $h, int $a): bool => $h > 0 && $a > 0 && ($h + $a) < 2.5);
                return $this->combine(['YES' => ['Both teams score and under 2.5 goals', $yes],
                    'NO' => ['Anything else', 1 - $yes]]);
            case 'TOTAL_GOALS_ODD_EVEN':
                // 0–0 is an even total: zero goals is an even number of them.
                $odd = $this->totals($grid, static fn(int $total): bool => $total % 2 === 1);
                return $this->combine(['ODD' => ['Odd number of goals', $odd],
                    'EVEN' => ['Even number of goals (0 counts as even)', 1 - $odd]]);
            case 'TOTAL_GOALS_BAND':
                // Disjoint, exhaustive goal bands: every grid cell lands in
                // exactly one of them, so the four probabilities sum to the
                // grid's coverage rather than overlapping like the Over lines.
                return $this->combine([
                    'BAND_0_1' => ['0–1 goals', $this->totals($grid, static fn(int $t): bool => $t <= 1)],
                    'BAND_2_3' => ['2–3 goals', $this->totals($grid, static fn(int $t): bool => $t === 2 || $t === 3)],
                    'BAND_4_6' => ['4–6 goals', $this->totals($grid, static fn(int $t): bool => $t >= 4 && $t <= 6)],
                    'BAND_7_PLUS' => ['7 or more goals', $this->totals($grid, static fn(int $t): bool => $t >= 7)],
                ]);
            case 'HOME_TEAM_TOTAL_GOALS':
                $over = $this->totals($grid, null, static fn(int $h, int $a): bool => $h > (float) $line);
                return $this->combine([
                    'OVER' => ['Home over ' . self::lineLabel($line) . ' goals', $over],
                    'UNDER' => ['Home under ' . self::lineLabel($line) . ' goals', 1 - $over],
                ]);
            case 'AWAY_TEAM_TOTAL_GOALS':
                $over = $this->totals($grid, null, static fn(int $h, int $a): bool => $a > (float) $line);
                return $this->combine([
                    'OVER' => ['Away over ' . self::lineLabel($line) . ' goals', $over],
                    'UNDER' => ['Away under ' . self::lineLabel($line) . ' goals', 1 - $over],
                ]);
            case 'HOME_CLEAN_SHEET':
                // A home clean sheet is the away side failing to score.
                $yes = $this->totals($grid, null, static fn(int $h, int $a): bool => $a === 0);
                return $this->combine(['YES' => ['Home keeps a clean sheet', $yes],
                    'NO' => ['Away scores at least once', 1 - $yes]]);
            case 'AWAY_CLEAN_SHEET':
                $yes = $this->totals($grid, null, static fn(int $h, int $a): bool => $h === 0);
                return $this->combine(['YES' => ['Away keeps a clean sheet', $yes],
                    'NO' => ['Home scores at least once', 1 - $yes]]);
            case 'WINNING_MARGIN':
                // Disjoint margins, with the open-ended 3+ buckets carrying the
                // tail so the set stays exhaustive over the grid.
                return $this->combine([
                    'HOME_1' => ['Home by 1', $this->totals($grid, null, static fn(int $h, int $a): bool => $h - $a === 1)],
                    'HOME_2' => ['Home by 2', $this->totals($grid, null, static fn(int $h, int $a): bool => $h - $a === 2)],
                    'HOME_3_PLUS' => ['Home by 3 or more', $this->totals($grid, null, static fn(int $h, int $a): bool => $h - $a >= 3)],
                    'DRAW' => ['Draw — no winning margin', $this->totals($grid, null, static fn(int $h, int $a): bool => $h === $a)],
                    'AWAY_1' => ['Away by 1', $this->totals($grid, null, static fn(int $h, int $a): bool => $a - $h === 1)],
                    'AWAY_2' => ['Away by 2', $this->totals($grid, null, static fn(int $h, int $a): bool => $a - $h === 2)],
                    'AWAY_3_PLUS' => ['Away by 3 or more', $this->totals($grid, null, static fn(int $h, int $a): bool => $a - $h >= 3)],
                ]);
            case 'RESULT_AND_BTTS':
                $both = static fn(int $h, int $a): bool => $h > 0 && $a > 0;
                return $this->combine([
                    'HOME_YES' => ['Home win & both score', $this->totals($grid, null, static fn(int $h, int $a): bool => $h > $a && $both($h, $a))],
                    'HOME_NO' => ['Home win & not both score', $this->totals($grid, null, static fn(int $h, int $a): bool => $h > $a && !$both($h, $a))],
                    'DRAW_YES' => ['Draw & both score', $this->totals($grid, null, static fn(int $h, int $a): bool => $h === $a && $both($h, $a))],
                    'DRAW_NO' => ['Draw & not both score', $this->totals($grid, null, static fn(int $h, int $a): bool => $h === $a && !$both($h, $a))],
                    'AWAY_YES' => ['Away win & both score', $this->totals($grid, null, static fn(int $h, int $a): bool => $a > $h && $both($h, $a))],
                    'AWAY_NO' => ['Away win & not both score', $this->totals($grid, null, static fn(int $h, int $a): bool => $a > $h && !$both($h, $a))],
                ]);
            case 'CORRECT_SCORE':
                return $this->correctScore($grid);
            case 'ASIAN_HANDICAP':
                return $this->handicap($grid, (float) $line);
            case 'FIRST_HALF_WINNER':
                $half = $this->halfGrid($prediction);
                if ($half === []) return [];
                return $this->gridTriple($half, 'Home', 'Draw', 'Away');
            case 'FIRST_HALF_OVER_UNDER':
                $half = $this->halfGrid($prediction);
                if ($half === []) return [];
                $over = $this->totals($half, static fn(int $total): bool => $total > (float) $line);
                return $this->combine(['OVER' => ['First half over ' . self::lineLabel($line) . ' goals', $over],
                    'UNDER' => ['First half under ' . self::lineLabel($line) . ' goals', 1 - $over]]);
            case 'FIRST_HALF_DOUBLE_CHANCE':
                $half = $this->halfGrid($prediction);
                if ($half === []) return [];
                $p = $this->gridTriple($half, 'Home', 'Draw', 'Away');
                return $this->combine([
                    'HOME_OR_DRAW' => ['Home or Draw at half time', $this->sum($p, ['HOME', 'DRAW'])],
                    'HOME_OR_AWAY' => ['Home or Away at half time', $this->sum($p, ['HOME', 'AWAY'])],
                    'AWAY_OR_DRAW' => ['Draw or Away at half time', $this->sum($p, ['AWAY', 'DRAW'])],
                ]);
            case 'FIRST_HALF_BTTS':
                $half = $this->halfGrid($prediction);
                if ($half === []) return [];
                $yes = $this->totals($half, null, static fn(int $h, int $a): bool => $h > 0 && $a > 0);
                return $this->combine(['YES' => ['Both teams score in the first half', $yes],
                    'NO' => ['One side fails to score in the first half', 1 - $yes]]);
            case 'SECOND_HALF_WINNER':
                $second = $this->secondHalfGrid($prediction);
                if ($second === []) return [];
                return $this->gridTriple($second, 'Home', 'Draw', 'Away');
            case 'SECOND_HALF_OVER_UNDER':
                $second = $this->secondHalfGrid($prediction);
                if ($second === []) return [];
                $over = $this->totals($second, static fn(int $total): bool => $total > (float) $line);
                return $this->combine(['OVER' => ['Second half over ' . self::lineLabel($line) . ' goals', $over],
                    'UNDER' => ['Second half under ' . self::lineLabel($line) . ' goals', 1 - $over]]);
            default:
                return [];
        }
    }

    /**
     * Provider-price-only outcomes for markets the score model does not answer.
     *
     * We preserve every valid selection the provider supplied (using its latest
     * quote if a selection appears more than once). `probability` is explicitly
     * null: this is an odds board, not a disguised model prediction for corners,
     * cards, or HT/FT. The normal quote attachment below supplies the decimal
     * price, source and timestamp in the same shape as every other market.
     *
     * @param list<array{market:string,selection:string,decimalOdds:float,observedAt:?string}> $odds
     * @return list<array{selection:string,label:string,probability:?float,note:?string}>
     */
    private function providerOnlyOutcomes(array $odds, string $marketKey, ?float $line): array
    {
        $out = [];
        foreach ($odds as $row) {
            $rawMarket = (string) ($row['market'] ?? '');
            if (!self::providerMarketMatches(self::normalizeProviderMarket($rawMarket), $marketKey)) continue;
            $rawSelection = trim((string) ($row['selection'] ?? ''));
            $selection = self::normalizeProviderSelection($rawMarket, $rawSelection);
            if ($selection === '') continue;
            $price = is_numeric($row['decimalOdds'] ?? null) ? (float) $row['decimalOdds'] : null;
            if ($price === null || !OddsBounds::validDecimalOdds($price, $marketKey)) continue;
            // For a line-based provider-only market, retain only the requested
            // line. The current catalogue's price-only markets are not line
            // based, but keeping this check here makes the shape safe if one is
            // added later.
            if ($line !== null && self::familyCarriesLine(self::priceFamily($marketKey))
                && !self::lineMatches($marketKey, $selection, $rawSelection, $line)) continue;
            $observed = (string) ($row['observedAt'] ?? '');
            $existing = $out[$selection] ?? null;
            if ($existing === null || $observed >= (string) ($existing['_observedAt'] ?? '')) {
                $out[$selection] = [
                    'selection' => $selection,
                    'label' => $rawSelection !== '' ? $rawSelection : $selection,
                    'probability' => null,
                    'note' => 'Provider price only — WINDELS does not model this market.',
                    '_observedAt' => $observed,
                ];
            }
        }
        foreach ($out as &$row) unset($row['_observedAt']);
        unset($row);
        return array_values($out);
    }

    /**
     * The 1X2 row stored with the prediction. It is read, not recomputed: the
     * grid would give the same answer to six decimals, and preferring the
     * stored figure keeps the market view identical to the match card.
     *
     * @return list<array{selection:string,label:string,probability:?float,note:?string}>
     */
    private function triple(array $prediction, string $homeLabel, string $drawLabel, string $awayLabel): array
    {
        $map = ['HOME' => $homeLabel, 'DRAW' => $drawLabel, 'AWAY' => $awayLabel];
        $values = ['HOME' => $prediction['probability_home'] ?? null, 'DRAW' => $prediction['probability_draw'] ?? null,
            'AWAY' => $prediction['probability_away'] ?? null];
        $out = [];
        foreach ($map as $key => $label) {
            $out[] = ['selection' => $key, 'label' => $label, 'probability' => is_numeric($values[$key]) ? (float) $values[$key] : null, 'note' => null];
        }
        return $out;
    }

    /** @param list<array{home:int,away:int,probability:float}> $grid */
    private function gridTriple(array $grid, string $homeLabel, string $drawLabel, string $awayLabel): array
    {
        $home = 0.0; $draw = 0.0; $away = 0.0;
        foreach ($grid as $row) {
            $h = (int) $row['home']; $a = (int) $row['away']; $p = (float) $row['probability'];
            if ($h > $a) $home += $p; elseif ($h === $a) $draw += $p; else $away += $p;
        }
        return $this->combine(['HOME' => [$homeLabel, $home], 'DRAW' => [$drawLabel, $draw], 'AWAY' => [$awayLabel, $away]]);
    }

    /**
     * The scorelines of the grid — correct score is a grid market, not a guess.
     *
     * A bookmaker prices a whole correct-score board, so the sheet lists every
     * scoreline the grid actually reaches, ordered most-likely first. Cells with
     * no meaningful mass are dropped rather than printed as a wall of zeroes:
     * they are not scorelines anyone prices, and listing them would bury the
     * ones that matter. `includeQuotedScores()` still adds any further scoreline
     * the provider quoted, so a real price is never hidden by this threshold.
     */
    private function correctScore(array $grid): array
    {
        $rows = $grid;
        usort($rows, static fn(array $a, array $b) => (float) $b['probability'] <=> (float) $a['probability']);
        $out = [];
        foreach ($rows as $row) {
            $probability = round((float) $row['probability'], 6);
            if ($probability < self::CORRECT_SCORE_MIN_PROBABILITY && count($out) >= self::CORRECT_SCORE_MIN_ROWS) continue;
            $label = (int) $row['home'] . '–' . (int) $row['away'];
            $out[] = ['selection' => (int) $row['home'] . '-' . (int) $row['away'], 'label' => $label,
                'probability' => $probability, 'note' => null];
        }
        return $out;
    }

    /**
     * Correct-score feeds often quote more scorelines than the six model leaders
     * used for a compact recommendation. Keep every quoted scoreline on the odds
     * sheet and attach its score-grid probability when that cell is available.
     *
     * @param list<array<string,mixed>> $rows
     * @param list<array{home:int,away:int,probability:float}> $grid
     * @param list<array<string,mixed>> $odds
     * @return list<array<string,mixed>>
     */
    private function includeQuotedScores(array $rows, array $grid, array $odds): array
    {
        $seen = [];
        foreach ($rows as $row) $seen[(string) ($row['selection'] ?? '')] = true;
        $probabilities = [];
        foreach ($grid as $cell) {
            $probabilities[(int) $cell['home'] . '-' . (int) $cell['away']] = round((float) $cell['probability'], 6);
        }
        foreach ($odds as $quote) {
            if (!self::providerMarketMatches(self::normalizeProviderMarket((string) ($quote['market'] ?? '')), 'CORRECT_SCORE')) continue;
            $rawSelection = trim((string) ($quote['selection'] ?? ''));
            $selection = self::normalizeProviderSelection((string) ($quote['market'] ?? ''), $rawSelection);
            if ($selection === '' || isset($seen[$selection])) continue;
            if (preg_match('/^(\d+)-(\d+)$/', $selection, $m)) {
                $label = (int) $m[1] . '–' . (int) $m[2];
                $probability = $probabilities[$selection] ?? null;
                $note = isset($probabilities[$selection]) ? null : 'Provider quoted this scoreline beyond the stored model grid.';
            } else {
                $label = $rawSelection !== '' ? $rawSelection : $selection;
                $probability = null;
                $note = 'Provider-quoted correct-score category without a corresponding score-grid cell.';
            }
            $rows[] = ['selection' => $selection, 'label' => $label, 'probability' => $probability, 'note' => $note];
            $seen[$selection] = true;
        }
        return $rows;
    }

    /**
     * Asian handicap. A quarter line splits the stake across the two bounding
     * half lines, which is exactly how the market settles, so the probability
     * is the same weighted average rather than an approximation of it.
     *
     * @param list<array{home:int,away:int,probability:float}> $grid
     * @return list<array{selection:string,label:string,probability:?float,note:?string}>
     */
    private function handicap(array $grid, float $line): array
    {
        $components = [];
        $doubled = $line * 2;
        if (abs($doubled - round($doubled)) < 1e-9) {
            $components = [$line];
        } else {
            $components = [(floor($doubled) / 2), (ceil($doubled) / 2)];
        }
        $win = 0.0; $push = 0.0; $lose = 0.0;
        foreach ($components as $component) {
            $weight = 1 / count($components);
            $w = 0.0; $p = 0.0; $l = 0.0;
            foreach ($grid as $row) {
                // The line is a float, so the settled difference is compared
                // with a tolerance: `=== 0` would silently count every push as
                // a loss on a level handicap, which is the one line where the
                // stake comes back most often.
                $diff = (int) $row['home'] + $component - (int) $row['away'];
                $probability = (float) $row['probability'];
                if ($diff > 1e-9) $w += $probability;
                elseif (abs($diff) <= 1e-9) $p += $probability;
                else $l += $probability;
            }
            $win += $weight * $w; $push += $weight * $p; $lose += $weight * $l;
        }
        return [
            ['selection' => 'HOME', 'label' => 'Home ' . self::handicapLabel($line), 'probability' => round($win, 6),
                'note' => $push > 0 ? round($push, 6) . ' of this stake is returned on a push' : null],
            ['selection' => 'AWAY', 'label' => 'Away ' . self::handicapLabel(-$line), 'probability' => round($lose, 6),
                'note' => $push > 0 ? round($push, 6) . ' of this stake is returned on a push' : null],
        ];
    }

    /**
     * The first-half grid: the same score model run on the configured share of
     * the match goal expectancy. The share is an assumption and is named in the
     * market's basis rather than presented as a stored half-time input.
     *
     * @return list<array{home:int,away:int,probability:float}>
     */
    private function halfGrid(array $prediction): array
    {
        return $this->shareGrid($prediction, $this->config->firstHalfGoalShare());
    }

    /**
     * The second-half grid: the same score model run on the share of the goal
     * expectancy the first half does not claim. It rests on exactly the same
     * stated assumption as the first-half markets — one share splits the match
     * — so these markets are marked `assumed` and carry the share in their
     * basis rather than pretending to be a stored per-half input.
     *
     * @return list<array{home:int,away:int,probability:float}>
     */
    private function secondHalfGrid(array $prediction): array
    {
        return $this->shareGrid($prediction, 1.0 - $this->config->firstHalfGoalShare());
    }

    /**
     * The score model run on a stated share of the match goal expectancy.
     *
     * @return list<array{home:int,away:int,probability:float}>
     */
    private function shareGrid(array $prediction, float $share): array
    {
        $snapshot = is_array($prediction['feature_snapshot'] ?? null) ? $prediction['feature_snapshot']
            : json_decode((string) ($prediction['feature_snapshot'] ?? '{}'), true);
        $expected = is_array($snapshot['expectedGoals'] ?? null) ? $snapshot['expectedGoals'] : [];
        $home = is_numeric($expected['home'] ?? null) ? (float) $expected['home'] : null;
        $away = is_numeric($expected['away'] ?? null) ? (float) $expected['away'] : null;
        if ($home === null || $away === null || $home < 0 || $away < 0) return [];
        if ($share <= 0.0) return [];
        $grid = (new ScoreProbabilityModel($this->config))->fullGrid($home * $share, $away * $share);
        $rows = [];
        foreach ((array) ($grid['rows'] ?? []) as $row) {
            $rows[] = ['home' => (int) ($row['homeGoals'] ?? 0), 'away' => (int) ($row['awayGoals'] ?? 0),
                'probability' => (float) ($row['probability'] ?? 0)];
        }
        return $rows;
    }

    /**
     * Sum the grid over a total-goals predicate, or over a per-score predicate
     * when the market cares about both sides (`$both`).
     *
     * @param list<array{home:int,away:int,probability:float}> $grid
     */
    private function totals(array $grid, ?callable $onTotal = null, ?callable $onScore = null): float
    {
        $sum = 0.0;
        foreach ($grid as $row) {
            $h = (int) $row['home']; $a = (int) $row['away']; $p = (float) $row['probability'];
            if ($onScore !== null) { if ($onScore($h, $a)) $sum += $p; continue; }
            if ($onTotal !== null && $onTotal($h + $a)) $sum += $p;
        }
        return $sum;
    }

    /** @param array<string,array{0:string,1:float}> $rows */
    private function combine(array $rows): array
    {
        $out = [];
        foreach ($rows as $key => $row) {
            $out[] = ['selection' => (string) $key, 'label' => (string) $row[0],
                'probability' => is_numeric($row[1]) ? round((float) $row[1], 6) : null, 'note' => null];
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $rows */
    private function sum(array $rows, array $keys): float
    {
        $total = 0.0;
        foreach ($rows as $row) {
            if (in_array((string) $row['selection'], $keys, true) && is_numeric($row['probability'])) $total += (float) $row['probability'];
        }
        return $total;
    }

    /** @param list<array<string,mixed>> $rows */
    private function at(array $rows, string $key): float
    {
        foreach ($rows as $row) {
            if ((string) $row['selection'] === $key && is_numeric($row['probability'])) return (float) $row['probability'];
        }
        return 0.0;
    }

    /**
     * The price the odds feed quoted for one selection, if it quoted one.
     *
     * @param list<array{market:string,selection:string,decimalOdds:float,observedAt:?string}> $odds
     * @return array{decimalOdds:?float,observedAt:?string,provider:?string}
     */
    private function quoted(array $odds, string $marketKey, string $selection, ?float $line): array
    {
        $empty = ['decimalOdds' => null, 'observedAt' => null, 'provider' => null];
        if ($odds === []) return $empty;
        $newest = null;
        foreach ($odds as $row) {
            $normalizedMarket = self::normalizeProviderMarket((string) ($row['market'] ?? ''));
            if (!self::providerMarketMatches($normalizedMarket, $marketKey)) continue;
            $normalizedSelection = self::normalizeProviderSelection((string) ($row['market'] ?? ''), (string) ($row['selection'] ?? ''));
            if ($normalizedSelection !== $selection) continue;
            // A totals market is only the right quote when the line agrees: an
            // "Over 3.5" price is not the price of "Over 2.5".
            if (self::familyCarriesLine(self::priceFamily($marketKey))
                && !self::lineMatches($marketKey, $normalizedSelection, (string) ($row['selection'] ?? ''), (float) $line)) {
                continue;
            }
            $price = is_numeric($row['decimalOdds'] ?? null) ? (float) $row['decimalOdds'] : null;
            // Same bar as the price sheet: a sub-stake, non-finite or absurd
            // price is not a quote — it must never be shown as one.
            if ($price === null || !OddsBounds::validDecimalOdds($price, $marketKey)) continue;
            $observed = (string) ($row['observedAt'] ?? '');
            $rowProvider = $row['provider'] ?? $row['oddsSource'] ?? null;
            $rowProvider = is_string($rowProvider) && $rowProvider !== '' ? $rowProvider : null;
            if ($newest === null || $observed >= (string) $newest['observedAt']) $newest = ['decimalOdds' => $price, 'observedAt' => $observed, 'provider' => $rowProvider];
        }
        return $newest ?? $empty;
    }

    /**
     * Does a provider market code answer this catalogue market? A totals feed
     * quotes one market for every line ("Over/Under", "Total Goals"), so it
     * answers whichever goal market is selected — the line is checked against
     * the individual selection instead.
     */
    public static function providerMarketMatches(string $code, string $marketKey): bool
    {
        if ($code === '' || $marketKey === '') return false;
        if ($code === $marketKey) return true;
        if (isset(self::ALIASES[$code]) && self::ALIASES[$code] === $marketKey) return true;
        $totals = str_starts_with($marketKey, 'OVER_') || str_starts_with($marketKey, 'UNDER_');
        return $totals && in_array($code, ['OVER_UNDER', 'TOTAL_GOALS'], true);
    }

    /**
     * A provider's own market name onto this catalogue. Providers spell these
     * differently — `1X2`, `Match Winner`, `Full Time Result` are one market —
     * and a market that cannot be mapped keeps its own name rather than being
     * forced into a slot it does not belong to.
     */
    public static function normalizeProviderMarket(string $raw): string
    {
        $r = strtolower(trim($raw));
        if ($r === '') return '';
        // Order matters. A qualified name ("First Half Both Teams To Score")
        // contains the unqualified one, so every half-specific and combination
        // market is matched BEFORE the plain family it would otherwise be
        // filed under.
        if (preg_match('/half.?time.?result.*full.?time|half.?time.*full.?time|ht.?ft/', $r)) return 'HALF_TIME_FULL_TIME';
        $firstHalf = (bool) preg_match('/first.?half|1st.?half|half.?time|^1h|\b1h\b/', $r);
        $secondHalf = (bool) preg_match('/second.?half|2nd.?half|^2h|\b2h\b/', $r);
        if ($firstHalf || $secondHalf) {
            $prefix = $firstHalf ? 'FIRST_HALF' : 'SECOND_HALF';
            if (preg_match('/both.?teams|btts|goal.*goal|\bgg\b/', $r)) return $prefix . '_BTTS';
            if (preg_match('/double.?chance/', $r)) return $prefix . '_DOUBLE_CHANCE';
            if (preg_match('/over|under|total|goal/', $r)) return $prefix . '_OVER_UNDER';
            if (preg_match('/result|winner|1x2/', $r)) return $prefix . '_WINNER';
        }
        if (preg_match('/asian.?handicap|handicap/', $r)) return 'ASIAN_HANDICAP';
        if (preg_match('/draw.?no.?bet|dnb/', $r)) return 'DRAW_NO_BET';
        if (preg_match('/\bodd\b.{0,6}\beven\b|\beven\b.{0,6}\bodd\b/', $r)) return 'TOTAL_GOALS_ODD_EVEN';
        if (preg_match('/goal.?range|total.?goals.?band|goals.?band/', $r)) return 'TOTAL_GOALS_BAND';
        if (preg_match('/winning.?margin|margin.?of.?victory/', $r)) return 'WINNING_MARGIN';
        if (preg_match('/home.*clean.?sheet|clean.?sheet.*home/', $r)) return 'HOME_CLEAN_SHEET';
        if (preg_match('/away.*clean.?sheet|clean.?sheet.*away/', $r)) return 'AWAY_CLEAN_SHEET';
        if (preg_match('/(?:result|match.?winner|1x2).*(?:both.?teams|btts)|(?:both.?teams|btts).*(?:result|match.?winner|1x2)/', $r)) return 'RESULT_AND_BTTS';
        if (preg_match('/(?:btts|both.?teams|goal.*goal).*under/', $r)) return 'BTTS_AND_UNDER_2_5';
        if (preg_match('/btts.?and|both.?teams.*over|goal.*goal.*over/', $r)) return 'BTTS_AND_OVER_2_5';
        if (preg_match('/both.?teams|btts|goal.*goal/', $r)) return 'BTTS';
        if (preg_match('/double.?chance/', $r)) return 'DOUBLE_CHANCE';
        if (preg_match('/correct.?score|exact.?score/', $r)) return 'CORRECT_SCORE';
        if (preg_match('/home.?team.*(?:total.?goals|goals.?over|goals.?total)/', $r)) return 'HOME_TEAM_TOTAL_GOALS';
        if (preg_match('/away.?team.*(?:total.?goals|goals.?over|goals.?total)/', $r)) return 'AWAY_TEAM_TOTAL_GOALS';
        if (preg_match('/over.?under|total.?goals|goals.?over|goals.?total/', $r)) return 'OVER_UNDER';
        if (preg_match('/corners?/', $r)) return 'CORNERS';
        if (preg_match('/cards?|booking|booked/', $r)) return 'CARDS';
        if (preg_match('/match.?result|match.?winner|1x2|full.?time.?result|result/', $r)) return 'MATCH_WINNER';
        return strtoupper(preg_replace('/[^A-Za-z0-9_]/', '_', $raw));
    }

    /** A provider's selection name onto this catalogue's selection keys. */
    public static function normalizeProviderSelection(string $market, string $raw): string
    {
        $code = self::normalizeProviderMarket($market);
        $r = strtolower(trim($raw));
        if (in_array($code, ['OVER_UNDER', 'FIRST_HALF_OVER_UNDER', 'SECOND_HALF_OVER_UNDER',
            'HOME_TEAM_TOTAL_GOALS', 'AWAY_TEAM_TOTAL_GOALS'], true)) {
            if (preg_match('/over/', $r)) return 'OVER';
            if (preg_match('/under/', $r)) return 'UNDER';
        }
        if ($code === 'TOTAL_GOALS_ODD_EVEN') {
            if (preg_match('/odd/', $r)) return 'ODD';
            if (preg_match('/even/', $r)) return 'EVEN';
        }
        if ($code === 'TOTAL_GOALS_BAND') {
            // Providers write these bands as "0-1", "0 to 1", "2-3", "7+".
            // Only the digits matter, and an open-ended band is the tail.
            $digits = preg_replace('/[^0-9+]/', '', $r);
            if (str_contains($digits, '+') || $digits === '7') return 'BAND_7_PLUS';
            if ($digits === '01') return 'BAND_0_1';
            if ($digits === '23') return 'BAND_2_3';
            if ($digits === '46') return 'BAND_4_6';
        }
        if ($code === 'WINNING_MARGIN') {
            if (preg_match('/draw|\bx\b|level|no.?winner/', $r)) return 'DRAW';
            $side = preg_match('/home/', $r) ? 'HOME' : (preg_match('/away/', $r) ? 'AWAY' : null);
            // "Home by 3+", "Home by 3 or more" and anything above 2 are the
            // same open-ended bucket; 1 and 2 are exact margins.
            if ($side !== null && preg_match('/(\d+)/', $r, $m)) {
                $by = (int) $m[1];
                $open = (bool) preg_match('/\+|or more|plus/', $r);
                if ($by >= 3 || ($open && $by >= 3)) return $side . '_3_PLUS';
                if (!$open && ($by === 1 || $by === 2)) return $side . '_' . $by;
            }
        }
        if ($code === 'RESULT_AND_BTTS') {
            $side = preg_match('/home|\b1\b/', $r) ? 'HOME' : (preg_match('/away|\b2\b/', $r) ? 'AWAY' : (preg_match('/draw|\bx\b/', $r) ? 'DRAW' : null));
            if ($side !== null) {
                if (preg_match('/\bno\b|not|\bng\b/', $r)) return $side . '_NO';
                if (preg_match('/yes|\bgg\b|both/', $r)) return $side . '_YES';
            }
        }
        if ($code === 'MATCH_WINNER' || $code === 'FIRST_HALF_WINNER' || $code === 'SECOND_HALF_WINNER'
            || $code === 'DRAW_NO_BET' || $code === 'ASIAN_HANDICAP') {
            if (preg_match('/^home|\bhome\b|\b1\b|^1$/', $r)) return 'HOME';
            if (preg_match('/draw|\bx\b/', $r)) return 'DRAW';
            if (preg_match('/away|\b2\b/', $r)) return 'AWAY';
        }
        if (in_array($code, ['BTTS', 'BTTS_AND_OVER_2_5', 'BTTS_AND_UNDER_2_5', 'FIRST_HALF_BTTS',
            'HOME_CLEAN_SHEET', 'AWAY_CLEAN_SHEET'], true)) {
            if (preg_match('/yes|\b1\b/', $r)) return 'YES';
            if (preg_match('/no|\b0\b/', $r)) return 'NO';
        }
        if ($code === 'DOUBLE_CHANCE' || $code === 'FIRST_HALF_DOUBLE_CHANCE') {
            $compact = str_replace([' ', '-', '_', '/'], '', strtoupper($raw));
            if (in_array($compact, ['1X', 'HOMEDRAW', 'HOMEORDRAW'], true)) return 'HOME_OR_DRAW';
            if (in_array($compact, ['X2', 'DRAWAWAY', 'DRAWORAWAY', 'AWAYORDRAW'], true)) return 'AWAY_OR_DRAW';
            if (in_array($compact, ['12', 'HOMEAWAY', 'HOMEORAWAY'], true)) return 'HOME_OR_AWAY';
        }
        if ($code === 'CORRECT_SCORE' && preg_match('/(\d+)\D+(\d+)/', $r, $m)) return $m[1] . '-' . $m[2];
        return strtoupper(preg_replace('/[^A-Za-z0-9_.-]/', '_', $raw));
    }

    /**
     * The goal/handicap line named inside a provider's selection string.
     *
     * Provider adapters persist canonical codes such as OVER_2_5 and
     * HOME_MINUS_0_75, while direct odds imports often retain "Over 2.5" or
     * "Home -0.75". Both forms must resolve to the same signed number or a real
     * quote can be attached to the wrong line.
     */
    private static function lineOf(string $raw): ?float
    {
        $upper = strtoupper(trim($raw));
        if (preg_match('/(?:^|[^A-Z0-9])(MINUS|PLUS)[ _]?(\d+)(?:[_. ,](\d+))?(?:$|[^0-9])/', $upper, $m)) {
            $number = (float) ($m[2] . (isset($m[3]) && $m[3] !== '' ? '.' . $m[3] : ''));
            return $m[1] === 'MINUS' ? -$number : $number;
        }
        if (preg_match('/([+-])\s*(\d+)(?:[.,](\d+))?/', $raw, $m)) {
            $number = (float) ($m[2] . (isset($m[3]) && $m[3] !== '' ? '.' . $m[3] : ''));
            return $m[1] === '-' ? -$number : $number;
        }
        // In canonical totals codes the underscore between digits is the
        // decimal separator (OVER_2_5), not a separator between two values.
        if (preg_match('/(?:^|[^0-9])(\d+)(?:[.,_](\d+))?(?:$|[^0-9])/', $raw, $m)) {
            return (float) ($m[1] . (isset($m[2]) && $m[2] !== '' ? '.' . $m[2] : ''));
        }
        return null;
    }

    /**
     * Compare one quoted line with the catalogue's home-side line. Asian
     * handicap feeds state the opposite sign on the away leg: Home -0.5 and
     * Away +0.5 are the same two-way market, not different lines.
     */
    private static function lineMatches(string $marketKey, string $selection, string $rawSelection, float $line): bool
    {
        $quoted = self::lineOf($rawSelection);
        if ($quoted === null) return false;
        $expected = $marketKey === 'ASIAN_HANDICAP' && $selection === 'AWAY' ? -$line : $line;
        return abs($quoted - $expected) <= 1e-9;
    }

    /** @return list<string> */
    private function selectionLabels(string $key): array
    {
        return match ($key) {
            'MATCH_WINNER', 'FIRST_HALF_WINNER', 'SECOND_HALF_WINNER' => ['Home', 'Draw', 'Away'],
            'DOUBLE_CHANCE', 'FIRST_HALF_DOUBLE_CHANCE' => ['Home or Draw', 'Home or Away', 'Draw or Away'],
            'DRAW_NO_BET' => ['Home', 'Away'],
            'BTTS', 'BTTS_AND_OVER_2_5', 'BTTS_AND_UNDER_2_5', 'FIRST_HALF_BTTS',
            'HOME_CLEAN_SHEET', 'AWAY_CLEAN_SHEET' => ['Yes', 'No'],
            'TOTAL_GOALS_ODD_EVEN' => ['Odd', 'Even'],
            'TOTAL_GOALS_BAND' => ['0-1', '2-3', '4-6', '7+'],
            'WINNING_MARGIN' => ['Home by 1', 'Home by 2', 'Home by 3+', 'Draw', 'Away by 1', 'Away by 2', 'Away by 3+'],
            'RESULT_AND_BTTS' => ['Home & Yes', 'Home & No', 'Draw & Yes', 'Draw & No', 'Away & Yes', 'Away & No'],
            'CORRECT_SCORE' => ['Top scorelines'],
            'ASIAN_HANDICAP' => ['Home', 'Away'],
            default => ['Over', 'Under'],
        };
    }

    private function basis(string $key, string $derivation, string $gridBasis): string
    {
        if ($derivation === 'STORED_1X2') return 'STORED_1X2';
        if (str_starts_with($key, 'FIRST_HALF_')) {
            return 'FIRST_HALF_SHARE_' . $this->config->firstHalfGoalShare();
        }
        if (str_starts_with($key, 'SECOND_HALF_')) {
            return 'SECOND_HALF_SHARE_' . round(1.0 - $this->config->firstHalfGoalShare(), 4);
        }
        return $gridBasis;
    }

    /**
     * The grid a market is summed over.
     *
     * The persisted grid is deliberately truncated — scorelines below the
     * configured minimum probability are not stored — and a truncated grid is
     * unfit for summing: a draw is spread across many small cells, so dropping
     * the tail under-reports draws, handicaps and both-teams-to-score at once.
     * When the stored grid is not complete, the distribution is recomputed from
     * the expected goals stored with the prediction, by the same model that
     * produced it. That is a recomputation of stored inputs, not a new figure,
     * and the basis names the source either way.
     *
     * @param list<array{home:int,away:int,probability:float}> $stored
     * @return array{0:list<array{home:int,away:int,probability:float}>,1:string,2:float} grid, basis, coverage
     */
    private function gridFor(array $prediction, array $stored): array
    {
        $coverage = 0.0;
        foreach ($stored as $row) $coverage += (float) $row['probability'];
        if ($stored !== [] && $coverage >= 0.9995) return [$stored, 'SCORE_GRID', min(1.0, $coverage)];

        $rebuilt = $this->rebuild($prediction);
        if ($rebuilt !== []) {
            $rebuiltCoverage = 0.0;
            foreach ($rebuilt as $row) $rebuiltCoverage += (float) $row['probability'];
            return [$rebuilt, 'SCORE_MODEL_FROM_STORED_EXPECTED_GOALS', min(1.0, $rebuiltCoverage)];
        }
        // No expected goals to recompute from: the stored grid is all there is,
        // so it is renormalised and the share it covers is reported.
        if ($stored !== [] && $coverage >= 0.5) {
            $normalised = [];
            foreach ($stored as $row) {
                $normalised[] = ['home' => (int) $row['home'], 'away' => (int) $row['away'],
                    'probability' => (float) $row['probability'] / $coverage];
            }
            return [$normalised, 'SCORE_GRID_NORMALISED_' . round($coverage, 3), $coverage];
        }
        return [[], 'GRID_TOO_SPARSE', $coverage];
    }

    /**
     * Recompute the full score distribution from the expected goals stored with
     * the prediction: the same model, run on stored inputs.
     *
     * @return list<array{home:int,away:int,probability:float}>
     */
    private function rebuild(array $prediction): array
    {
        $snapshot = is_array($prediction['feature_snapshot'] ?? null) ? $prediction['feature_snapshot']
            : json_decode((string) ($prediction['feature_snapshot'] ?? '{}'), true);
        $expected = is_array($snapshot['expectedGoals'] ?? null) ? $snapshot['expectedGoals'] : [];
        $home = is_numeric($expected['home'] ?? null) ? (float) $expected['home'] : null;
        $away = is_numeric($expected['away'] ?? null) ? (float) $expected['away'] : null;
        if ($home === null || $away === null || $home < 0 || $away < 0) return [];
        $grid = (new ScoreProbabilityModel($this->config))->fullGrid($home, $away);
        $rows = [];
        foreach ((array) ($grid['rows'] ?? []) as $row) {
            $rows[] = ['home' => (int) ($row['homeGoals'] ?? 0), 'away' => (int) ($row['awayGoals'] ?? 0),
                'probability' => (float) ($row['probability'] ?? 0)];
        }
        return $rows;
    }

    private function unavailableReason(string $key, array $grid): string
    {
        if ($grid === []) return 'No score grid is stored for this prediction, so no goal market can be summed.';
        return match ($key) {
            'HALF_TIME_FULL_TIME' => 'Half-time/full-time needs a stored half-time scoreline; the module does not model one and will not invent it.',
            'CORNERS' => 'Corners are not modelled. They appear here only when the connected odds provider prices them.',
            'CARDS' => 'Cards are not modelled. They appear here only when the connected odds provider prices them.',
            default => 'This market has no stored input to derive from and no quoted price.',
        };
    }

    private static function lineLabel(?float $line): string
    {
        return $line === null ? '?' : rtrim(rtrim(number_format($line, 2), '0'), '.');
    }

    private static function handicapLabel(float $line): string
    {
        $text = rtrim(rtrim(number_format($line, 2), '0'), '.');
        return $line > 0 ? '+' . $text : $text;
    }

    /**
     * The catalogue. `line` is the default goal/handicap line; a caller may
     * name another one and the evaluation honours it.
     */
    private const MARKETS = [
        ['key' => 'MATCH_WINNER', 'label' => 'Match Winner — 1X2', 'group' => 'Result', 'derivation' => 'STORED_1X2'],
        ['key' => 'DOUBLE_CHANCE', 'label' => 'Double Chance', 'group' => 'Result', 'derivation' => 'STORED_1X2'],
        ['key' => 'DRAW_NO_BET', 'label' => 'Draw No Bet', 'group' => 'Result', 'derivation' => 'STORED_1X2'],
        ['key' => 'OVER_0_5', 'label' => 'Over 0.5 Goals', 'group' => 'Goals', 'derivation' => 'SCORE_GRID', 'line' => 0.5],
        ['key' => 'OVER_1_5', 'label' => 'Over 1.5 Goals', 'group' => 'Goals', 'derivation' => 'SCORE_GRID', 'line' => 1.5],
        ['key' => 'OVER_2_5', 'label' => 'Over 2.5 Goals', 'group' => 'Goals', 'derivation' => 'SCORE_GRID', 'line' => 2.5],
        ['key' => 'OVER_3_5', 'label' => 'Over 3.5 Goals', 'group' => 'Goals', 'derivation' => 'SCORE_GRID', 'line' => 3.5],
        ['key' => 'UNDER_1_5', 'label' => 'Under 1.5 Goals', 'group' => 'Goals', 'derivation' => 'SCORE_GRID', 'line' => 1.5],
        ['key' => 'UNDER_2_5', 'label' => 'Under 2.5 Goals', 'group' => 'Goals', 'derivation' => 'SCORE_GRID', 'line' => 2.5],
        ['key' => 'UNDER_3_5', 'label' => 'Under 3.5 Goals', 'group' => 'Goals', 'derivation' => 'SCORE_GRID', 'line' => 3.5],
        ['key' => 'OVER_4_5', 'label' => 'Over 4.5 Goals', 'group' => 'Goals', 'derivation' => 'SCORE_GRID', 'line' => 4.5],
        ['key' => 'OVER_5_5', 'label' => 'Over 5.5 Goals', 'group' => 'Goals', 'derivation' => 'SCORE_GRID', 'line' => 5.5],
        ['key' => 'OVER_6_5', 'label' => 'Over 6.5 Goals', 'group' => 'Goals', 'derivation' => 'SCORE_GRID', 'line' => 6.5],
        ['key' => 'BTTS', 'label' => 'Both Teams To Score — BTTS', 'group' => 'Goals', 'derivation' => 'SCORE_GRID'],
        ['key' => 'BTTS_AND_OVER_2_5', 'label' => 'BTTS + Over 2.5', 'group' => 'Goals', 'derivation' => 'SCORE_GRID'],
        ['key' => 'BTTS_AND_UNDER_2_5', 'label' => 'BTTS + Under 2.5', 'group' => 'Goals', 'derivation' => 'SCORE_GRID'],
        ['key' => 'TOTAL_GOALS_ODD_EVEN', 'label' => 'Total Goals — Odd or Even', 'group' => 'Goals', 'derivation' => 'SCORE_GRID'],
        ['key' => 'TOTAL_GOALS_BAND', 'label' => 'Total Goals — Band', 'group' => 'Goals', 'derivation' => 'SCORE_GRID'],
        ['key' => 'HOME_TEAM_TOTAL_GOALS', 'label' => 'Home Team Total Goals — 1.5', 'group' => 'Team goals',
            'derivation' => 'SCORE_GRID', 'line' => 1.5],
        ['key' => 'AWAY_TEAM_TOTAL_GOALS', 'label' => 'Away Team Total Goals — 1.5', 'group' => 'Team goals',
            'derivation' => 'SCORE_GRID', 'line' => 1.5],
        ['key' => 'HOME_CLEAN_SHEET', 'label' => 'Home Clean Sheet', 'group' => 'Team goals', 'derivation' => 'SCORE_GRID'],
        ['key' => 'AWAY_CLEAN_SHEET', 'label' => 'Away Clean Sheet', 'group' => 'Team goals', 'derivation' => 'SCORE_GRID'],
        ['key' => 'WINNING_MARGIN', 'label' => 'Winning Margin', 'group' => 'Result', 'derivation' => 'SCORE_GRID'],
        ['key' => 'RESULT_AND_BTTS', 'label' => 'Result + Both Teams To Score', 'group' => 'Result', 'derivation' => 'SCORE_GRID'],
        ['key' => 'FIRST_HALF_WINNER', 'label' => 'First Half Winner', 'group' => 'First half', 'derivation' => 'SCORE_GRID', 'assumed' => true],
        ['key' => 'FIRST_HALF_OVER_UNDER', 'label' => 'First Half Over/Under 1.5', 'group' => 'First half',
            'derivation' => 'SCORE_GRID', 'line' => 1.5, 'assumed' => true],
        ['key' => 'FIRST_HALF_DOUBLE_CHANCE', 'label' => 'First Half Double Chance', 'group' => 'First half',
            'derivation' => 'SCORE_GRID', 'assumed' => true],
        ['key' => 'FIRST_HALF_BTTS', 'label' => 'First Half Both Teams To Score', 'group' => 'First half',
            'derivation' => 'SCORE_GRID', 'assumed' => true],
        ['key' => 'SECOND_HALF_WINNER', 'label' => 'Second Half Winner', 'group' => 'Second half',
            'derivation' => 'SCORE_GRID', 'assumed' => true],
        ['key' => 'SECOND_HALF_OVER_UNDER', 'label' => 'Second Half Over/Under 1.5', 'group' => 'Second half',
            'derivation' => 'SCORE_GRID', 'line' => 1.5, 'assumed' => true],
        ['key' => 'HALF_TIME_FULL_TIME', 'label' => 'Half Time / Full Time', 'group' => 'First half', 'derivation' => 'NOT_MODELLED'],
        ['key' => 'CORRECT_SCORE', 'label' => 'Correct Score', 'group' => 'Score', 'derivation' => 'SCORE_GRID'],
        ['key' => 'ASIAN_HANDICAP', 'label' => 'Asian Handicap', 'group' => 'Handicap', 'derivation' => 'SCORE_GRID', 'line' => -0.5],
        ['key' => 'CORNERS', 'label' => 'Corners', 'group' => 'Other markets', 'derivation' => 'NOT_MODELLED'],
        ['key' => 'CARDS', 'label' => 'Cards', 'group' => 'Other markets', 'derivation' => 'NOT_MODELLED'],
    ];

    /**
     * Spellings a caller or a provider may use for a catalogue key. The engine
     * accepts the common ones and reports anything else rather than guessing a
     * near neighbour.
     */
    private const ALIASES = [
        '1X2' => 'MATCH_WINNER', 'MATCH_RESULT' => 'MATCH_WINNER', 'MATCH_WINNER_1X2' => 'MATCH_WINNER',
        'DC' => 'DOUBLE_CHANCE', 'DNB' => 'DRAW_NO_BET', 'OVER_UNDER' => 'OVER_2_5', 'TOTAL_GOALS' => 'OVER_2_5',
        'GG' => 'BTTS', 'BOTH_TEAMS_TO_SCORE' => 'BTTS', 'BTTS_OVER_2_5' => 'BTTS_AND_OVER_2_5',
        'HT_FT' => 'HALF_TIME_FULL_TIME', 'HALFTIME_FULLTIME' => 'HALF_TIME_FULL_TIME',
        '1H_WINNER' => 'FIRST_HALF_WINNER', '1H_OVER_UNDER' => 'FIRST_HALF_OVER_UNDER',
        'AH' => 'ASIAN_HANDICAP', 'HANDICAP' => 'ASIAN_HANDICAP',
        'EXACT_SCORE' => 'CORRECT_SCORE',
        'BTTS_UNDER_2_5' => 'BTTS_AND_UNDER_2_5', 'GG_UNDER_2_5' => 'BTTS_AND_UNDER_2_5',
        'ODD_EVEN' => 'TOTAL_GOALS_ODD_EVEN', 'GOALS_ODD_EVEN' => 'TOTAL_GOALS_ODD_EVEN',
        'GOAL_RANGE' => 'TOTAL_GOALS_BAND', 'TOTAL_GOALS_RANGE' => 'TOTAL_GOALS_BAND',
        'HOME_GOALS' => 'HOME_TEAM_TOTAL_GOALS', 'TEAM_TOTAL_HOME' => 'HOME_TEAM_TOTAL_GOALS',
        'AWAY_GOALS' => 'AWAY_TEAM_TOTAL_GOALS', 'TEAM_TOTAL_AWAY' => 'AWAY_TEAM_TOTAL_GOALS',
        'CLEAN_SHEET_HOME' => 'HOME_CLEAN_SHEET', 'CLEAN_SHEET_AWAY' => 'AWAY_CLEAN_SHEET',
        'MARGIN' => 'WINNING_MARGIN', 'WINNING_MARGINS' => 'WINNING_MARGIN',
        'RESULT_BTTS' => 'RESULT_AND_BTTS', 'WIN_TO_NIL_COMBO' => 'RESULT_AND_BTTS',
        '1H_DC' => 'FIRST_HALF_DOUBLE_CHANCE', '1H_BTTS' => 'FIRST_HALF_BTTS', '1H_GG' => 'FIRST_HALF_BTTS',
        '2H_WINNER' => 'SECOND_HALF_WINNER', '2H_OVER_UNDER' => 'SECOND_HALF_OVER_UNDER',
        'SECOND_HALF_GOALS' => 'SECOND_HALF_OVER_UNDER',
    ];

    public function __construct(private FootballConfiguration $config, private ?OddsIntelligence $fairValue = null)
    {
        $this->fairValue = $fairValue ?? new OddsIntelligence($this->config);
    }

    /** The fair-value engine that turns a priced market into a verdict. */
    public function fairValue(): OddsIntelligence
    {
        return $this->fairValue;
    }
}
