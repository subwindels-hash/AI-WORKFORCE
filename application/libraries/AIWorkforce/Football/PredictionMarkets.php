<?php
namespace AIWorkforce\Football;

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
 *    model and not an invented figure.
 *  - `STORED_1X2` — the 1X2 row stored with the prediction.
 *  - `FIRST_HALF_SHARE` — the same score model run on the configured share of
 *    the match goal expectancy. The share is an assumption, so it is named in
 *    the market's `basis` and configurable, never hidden.
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
     * @return array{state:string,family:string,line:?float,expected:list<string>,quotes:array<string,array{odds:float,observedAt:?string,low:float,high:float,quotes:int}>,ignored:int,note:?string}
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
            $quotedLine = self::lineOf((string) ($row['selection'] ?? ''));
            if ($lineChecked && ($quotedLine === null || abs($quotedLine - (float) $line) > 1e-9)) { $ignored++; continue; }
            $price = is_numeric($row['decimalOdds'] ?? null) ? (float) $row['decimalOdds'] : null;
            if ($price === null || $price <= 1.0) { $ignored++; continue; }
            $observed = (string) ($row['observedAt'] ?? '');
            $seen = (array) ($quotes[$selection] ?? null);
            $newest = $seen === null || $observed >= (string) ($seen['observedAt'] ?? '');
            $quotes[$selection] = [
                'odds' => $newest ? $price : (float) $seen['odds'],
                'observedAt' => $newest && $observed !== '' ? $observed : ($seen['observedAt'] ?? null),
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
            $note = $ignored . ' quoted leg' . ($ignored === 1 ? ' was' : 's were') . ' outside this market'
                . ($lineChecked ? ' or off the ' . self::lineLabel($line) . ' line' : '') . ' and was not used.';
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
        return match ($key) {
            'OVER_0_5', 'OVER_1_5', 'OVER_2_5', 'OVER_3_5', 'UNDER_1_5', 'UNDER_2_5', 'UNDER_3_5' => 'OVER_UNDER',
            'FIRST_HALF_OVER_UNDER' => 'FIRST_HALF_OVER_UNDER',
            'FIRST_HALF_WINNER' => 'FIRST_HALF_WINNER',
            default => $key,
        };
    }

    /** Markets whose price belongs to a stated line: an "Over 3.5" quote is not an "Over 2.5" quote. */
    private static function familyCarriesLine(string $family): bool
    {
        return in_array($family, ['OVER_UNDER', 'FIRST_HALF_OVER_UNDER', 'ASIAN_HANDICAP'], true);
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
        'ASIAN_HANDICAP' => ['HOME', 'AWAY'],
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
        $state = $rows === [] ? self::STATE_UNAVAILABLE : self::STATE_AVAILABLE;
        $basis = $rows === [] ? null : $this->basis($key, (string) $market['derivation'], $gridBasis);
        $source = $market['derivation'] === 'NOT_MODELLED' ? self::SOURCE_ODDS
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
            $row['edge'] = $row['impliedProbability'] !== null ? round((float) $row['probability'] - $row['impliedProbability'], 6) : null;
            $row['oddsObservedAt'] = $quoted['observedAt'];
            $row['oddsState'] = $quoted['decimalOdds'] === null ? self::STATE_UNAVAILABLE : self::STATE_AVAILABLE;
            $value = $this->fairValue()->assess($sheet, (string) $row['selection'],
                is_numeric($row['probability'] ?? null) ? (float) $row['probability'] : null);
            $row['windelsFairOdds'] = $value['windelsFairOdds'];
            $row['fairOdds'] = $value['fairOdds'];
            $row['fairProbability'] = $value['fairProbability'];
            $row['expectedValue'] = $value['expectedValue'];
            $row['valueClass'] = $value['valueClass'];
            $row['valueLabel'] = $value['valueLabel'];
        }
        unset($row);

        $best = null;
        foreach ($rows as $row) {
            if (!is_numeric($row['probability'])) continue;
            if ($best === null || (float) $row['probability'] > (float) $best['probability']) $best = $row;
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
            'impliedProbability' => $best['impliedProbability'] ?? null,
            'edge' => $best['edge'] ?? null,
            'value' => $value,
            'pricing' => [
                'state' => (string) $sheet['state'],
                'family' => (string) $sheet['family'],
                'line' => $sheet['line'],
                'legsPriced' => count((array) $sheet['quotes']),
                'legsExpected' => count((array) $sheet['expected']),
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

        switch ($key) {
            case 'OVER_0_5':
            case 'OVER_1_5':
            case 'OVER_2_5':
            case 'OVER_3_5':
                $over = $this->totals($grid, static fn(int $total): bool => $total > (float) $line);
                return $this->combine(['OVER' => ['Over ' . self::lineLabel($line) . ' goals', $over],
                    'UNDER' => ['Under ' . self::lineLabel($line) . ' goals', 1 - $over]]);
            case 'UNDER_1_5':
            case 'UNDER_2_5':
            case 'UNDER_3_5':
                $under = $this->totals($grid, static fn(int $total): bool => $total < (float) $line);
                return $this->combine(['UNDER' => ['Under ' . self::lineLabel($line) . ' goals', $under],
                    'OVER' => ['Over ' . self::lineLabel($line) . ' goals', 1 - $under]]);
            case 'BTTS':
                $yes = $this->totals($grid, null, static fn(int $h, int $a): bool => $h > 0 && $a > 0);
                return $this->combine(['YES' => ['Both teams score', $yes], 'NO' => ['One side fails to score', 1 - $yes]]);
            case 'BTTS_AND_OVER_2_5':
                $yes = $this->totals($grid, null, static fn(int $h, int $a): bool => $h > 0 && $a > 0 && ($h + $a) > 2.5);
                return $this->combine(['YES' => ['Both teams score and over 2.5 goals', $yes],
                    'NO' => ['Anything else', 1 - $yes]]);
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
            default:
                return [];
        }
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

    /** The top scorelines of the grid — correct score is a grid market, not a guess. */
    private function correctScore(array $grid): array
    {
        $rows = $grid;
        usort($rows, static fn(array $a, array $b) => (float) $b['probability'] <=> (float) $a['probability']);
        $out = [];
        foreach (array_slice($rows, 0, 6) as $row) {
            $label = (int) $row['home'] . '–' . (int) $row['away'];
            $out[] = ['selection' => (int) $row['home'] . '-' . (int) $row['away'], 'label' => $label,
                'probability' => round((float) $row['probability'], 6), 'note' => null];
        }
        return $out;
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
        $snapshot = is_array($prediction['feature_snapshot'] ?? null) ? $prediction['feature_snapshot']
            : json_decode((string) ($prediction['feature_snapshot'] ?? '{}'), true);
        $expected = is_array($snapshot['expectedGoals'] ?? null) ? $snapshot['expectedGoals'] : [];
        $home = is_numeric($expected['home'] ?? null) ? (float) $expected['home'] : null;
        $away = is_numeric($expected['away'] ?? null) ? (float) $expected['away'] : null;
        if ($home === null || $away === null || $home < 0 || $away < 0) return [];
        $share = $this->config->firstHalfGoalShare();
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
     * @return array{decimalOdds:?float,observedAt:?string}
     */
    private function quoted(array $odds, string $marketKey, string $selection, ?float $line): array
    {
        $empty = ['decimalOdds' => null, 'observedAt' => null];
        if ($odds === []) return $empty;
        $newest = null;
        foreach ($odds as $row) {
            $normalizedMarket = self::normalizeProviderMarket((string) ($row['market'] ?? ''));
            if (!self::providerMarketMatches($normalizedMarket, $marketKey)) continue;
            $normalizedSelection = self::normalizeProviderSelection((string) ($row['market'] ?? ''), (string) ($row['selection'] ?? ''));
            if ($normalizedSelection !== $selection) continue;
            // A totals market is only the right quote when the line agrees: an
            // "Over 3.5" price is not the price of "Over 2.5".
            $quotedLine = self::lineOf((string) ($row['selection'] ?? ''));
            if (str_starts_with($marketKey, 'OVER_') || str_starts_with($marketKey, 'UNDER_')) {
                if ($quotedLine === null || abs($quotedLine - (float) $line) > 1e-9) continue;
            }
            $price = is_numeric($row['decimalOdds'] ?? null) ? (float) $row['decimalOdds'] : null;
            if ($price === null || $price <= 0) continue;
            $observed = (string) ($row['observedAt'] ?? '');
            if ($newest === null || $observed >= (string) $newest['observedAt']) $newest = ['decimalOdds' => $price, 'observedAt' => $observed];
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
        if (preg_match('/asian.?handicap|handicap/', $r)) return 'ASIAN_HANDICAP';
        if (preg_match('/draw.?no.?bet|dnb/', $r)) return 'DRAW_NO_BET';
        if (preg_match('/double.?chance/', $r)) return 'DOUBLE_CHANCE';
        if (preg_match('/btts.?and|both.?teams.*over|goal.*goal.*over/', $r)) return 'BTTS_AND_OVER_2_5';
        if (preg_match('/both.?teams|btts|goal.*goal/', $r)) return 'BTTS';
        if (preg_match('/correct.?score|exact.?score/', $r)) return 'CORRECT_SCORE';
        if (preg_match('/half.?time.?result|ht.?ft|half.?time.*full.?time/', $r)) return 'HALF_TIME_FULL_TIME';
        if (preg_match('/over.?under|total.?goals|goals.?over|goals.?total/', $r)) return 'OVER_UNDER';
        if (preg_match('/first.?half|1st.?half/', $r) && preg_match('/result|winner|1x2/', $r)) return 'FIRST_HALF_WINNER';
        if (preg_match('/first.?half|1st.?half|half.?time/', $r) && preg_match('/over|under|goal/', $r)) return 'FIRST_HALF_OVER_UNDER';
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
        if (in_array($code, ['OVER_UNDER', 'FIRST_HALF_OVER_UNDER'], true)) {
            if (preg_match('/over/', $r)) return 'OVER';
            if (preg_match('/under/', $r)) return 'UNDER';
        }
        if ($code === 'MATCH_WINNER' || $code === 'FIRST_HALF_WINNER' || $code === 'DRAW_NO_BET' || $code === 'ASIAN_HANDICAP') {
            if (preg_match('/^home|\bhome\b|\b1\b|^1$/', $r)) return 'HOME';
            if (preg_match('/draw|\bx\b/', $r)) return 'DRAW';
            if (preg_match('/away|\b2\b/', $r)) return 'AWAY';
        }
        if ($code === 'BTTS' || $code === 'BTTS_AND_OVER_2_5') {
            if (preg_match('/yes|\b1\b/', $r)) return 'YES';
            if (preg_match('/no|\b0\b/', $r)) return 'NO';
        }
        if ($code === 'DOUBLE_CHANCE') {
            $compact = str_replace([' ', '-', '_', '/'], '', strtoupper($raw));
            if (in_array($compact, ['1X', 'HOMEDRAW', 'HOMEORDRAW'], true)) return 'HOME_OR_DRAW';
            if (in_array($compact, ['X2', 'DRAWAWAY', 'DRAWORAWAY', 'AWAYORDRAW'], true)) return 'AWAY_OR_DRAW';
            if (in_array($compact, ['12', 'HOMEAWAY', 'HOMEORAWAY'], true)) return 'HOME_OR_AWAY';
        }
        if ($code === 'CORRECT_SCORE' && preg_match('/(\d+)\D+(\d+)/', $r, $m)) return $m[1] . '-' . $m[2];
        return strtoupper(preg_replace('/[^A-Za-z0-9_.-]/', '_', $raw));
    }

    /** The goal/handicap line named inside a provider's selection string. */
    private static function lineOf(string $raw): ?float
    {
        if (preg_match('/(\d+(?:[.,]\d+)?)/', $raw, $m)) return (float) str_replace(',', '.', $m[1]);
        return null;
    }

    /** @return list<string> */
    private function selectionLabels(string $key): array
    {
        return match ($key) {
            'MATCH_WINNER', 'FIRST_HALF_WINNER' => ['Home', 'Draw', 'Away'],
            'DOUBLE_CHANCE' => ['Home or Draw', 'Home or Away', 'Draw or Away'],
            'DRAW_NO_BET' => ['Home', 'Away'],
            'BTTS', 'BTTS_AND_OVER_2_5' => ['Yes', 'No'],
            'CORRECT_SCORE' => ['Top scorelines'],
            'ASIAN_HANDICAP' => ['Home', 'Away'],
            default => ['Over', 'Under'],
        };
    }

    private function basis(string $key, string $derivation, string $gridBasis): string
    {
        if ($derivation === 'STORED_1X2') return 'STORED_1X2';
        if ($key === 'FIRST_HALF_WINNER' || $key === 'FIRST_HALF_OVER_UNDER') {
            return 'FIRST_HALF_SHARE_' . $this->config->firstHalfGoalShare();
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
        ['key' => 'BTTS', 'label' => 'Both Teams To Score — BTTS', 'group' => 'Goals', 'derivation' => 'SCORE_GRID'],
        ['key' => 'BTTS_AND_OVER_2_5', 'label' => 'BTTS + Over 2.5', 'group' => 'Goals', 'derivation' => 'SCORE_GRID'],
        ['key' => 'FIRST_HALF_WINNER', 'label' => 'First Half Winner', 'group' => 'First half', 'derivation' => 'SCORE_GRID', 'assumed' => true],
        ['key' => 'FIRST_HALF_OVER_UNDER', 'label' => 'First Half Over/Under 1.5', 'group' => 'First half',
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
