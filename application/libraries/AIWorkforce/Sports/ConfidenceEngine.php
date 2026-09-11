<?php
namespace AIWorkforce\Sports;

/**
 * Transparent candidate confidence (spec §10) — a weighted blend of the
 * evidence that is ACTUALLY stored for the candidate, never a single fake
 * percentage and never a fabricated input.
 *
 * WHY THIS WAS REWRITTEN
 * ----------------------
 * The previous blend was
 *
 *     0.50 · dataQuality + 0.30 · calibrationQuality + 0.20 · separation
 *
 * with `calibrationQuality = 0` whenever the approved calibration carried no
 * measured ECE (samples < 20). Every deployment that runs on the IDENTITY
 * BOOTSTRAP calibration — which by definition has no settled history yet, so
 * no ECE and 0 samples — therefore lost 30 points for an absence. The
 * arithmetic ceiling of such a run was
 *
 *     0.5 · 100 + 0.3 · 0 + 0.2 · 100 = 70 %
 *
 * i.e. a PERFECT fixture could not reach the configured 75 % floor. That is
 * the whole reason a day could report "7 predictions → 0 confidence-qualified"
 * while four of the same candidates were positive-value and risk-qualified.
 *
 * THE RULE THAT FIXES IT (and the one the old blend broke)
 * --------------------------------------------------------
 * **A component that cannot be measured is EXCLUDED, not scored zero.** The
 * remaining weights are renormalised over what is known and every exclusion is
 * listed on the record. Scoring an unknown as 0 punishes a candidate for the
 * absence of a record; scoring it as "average" would invent one. This is the
 * same rule the shared IntelligenceScore already applies on the football
 * board, so both screens read one candidate identically.
 *
 * The threshold itself is untouched: the configured 75 % floor still applies.
 * What changed is that the number now measures the evidence instead of
 * measuring which optional feeds a provider happened to expose.
 *
 * COMPONENTS (each 0–100, each read off a stored measurement)
 * -----------------------------------------------------------
 *   dataQuality          the transparent DataQualityEngine score
 *   calibration          100·(1−ECE) with a measured ECE; LIMITED (40) for an
 *                        approved identity/bootstrap mapping with no settled
 *                        history yet; EXCLUDED when no calibration is attached
 *   modelProbability     how far the model's own calibrated probability sits
 *                        from a coin flip (sharpness of the read)
 *   teamForm             verified recent-form coverage: the four venue rates
 *                        plus the number of matches behind them when stored
 *   recentResults        stored recent results / W-D-L run, when supplied
 *   homeAwayPerformance  the venue split actually supports the selection
 *   goalsScoredConceded  the stored goal rates actually support the selection
 *   headToHead           stored H2H record, when supplied
 *   leaguePosition       stored standings position/points, when supplied
 *   injuriesNews         stored injury/lineup/news feed, when supplied
 *   marketAgreement      model probability vs the bookmaker implied
 *                        probability (agreement is evidence, disagreement is
 *                        not scored as certainty)
 *   marketConsistency    the quoted market's own coherence (overround of the
 *                        complete price sheet), when the whole market is known
 *
 * Score = Σ(value × weight) / Σ(weight) over the AVAILABLE components, capped
 * at 95 (the system never claims certainty) and floored by a minimum evidence
 * requirement: a read with too little independent evidence reports its
 * confidence as unmeasured rather than as a high number resting on one input.
 */
class ConfidenceEngine
{
    public const CAP = 95.0;

    /**
     * Minimum number of AVAILABLE components (and minimum share of the total
     * weight) before a confidence figure means anything. Below it the engine
     * reports INSUFFICIENT_CONFIDENCE_EVIDENCE instead of a number — it never
     * pads the gap with assumed values.
     */
    public const MIN_COMPONENTS = 4;
    public const MIN_WEIGHT_SHARE = 0.5;

    /** Weight of every component. Only the available ones are used. */
    public const WEIGHTS = [
        'dataQuality' => 0.18,
        'calibration' => 0.12,
        'modelProbability' => 0.16,
        'teamForm' => 0.10,
        'recentResults' => 0.06,
        'homeAwayPerformance' => 0.07,
        'goalsScoredConceded' => 0.07,
        'headToHead' => 0.05,
        'leaguePosition' => 0.05,
        'injuriesNews' => 0.04,
        'marketAgreement' => 0.06,
        'marketConsistency' => 0.04,
    ];

    /** An approved calibration with no settled history yet (identity/bootstrap). */
    public const BOOTSTRAP_CALIBRATION_VALUE = 40.0;

    /**
     * Distance from a coin flip at which the model-probability component is
     * worth full marks: a 0.90 (or 0.10) read. Nothing above it scores more —
     * the system never treats a probability as certainty.
     */
    public const SHARPNESS_CEILING = 0.40;

    /** ECE is only trusted as a measurement from this many settled samples. */
    public const MIN_CALIBRATION_SAMPLES = 20;

    /**
     * @param array      $prediction  PredictionEngine output
     * @param array      $quality     DataQualityEngine assessment
     * @param array|null $calibration approved calibration (intercept/slope/ece/samples)
     * @param array      $evidence    the candidate's stored evidence:
     *   features        FeatureEngineeringEngine features (venue goal rates)
     *   inputs          MatchIntelligenceEngine inputs (recentForm, injuries,
     *                   lineups, historical, standings…) — nulls are absences
     *   impliedProbability  bookmaker implied probability for THIS selection
     *   marketPrices    the complete quoted market (selection → odds), used
     *                   only to measure the market's own coherence
     *   market/selection  what is being priced
     */
    public function assess(array $prediction, array $quality, ?array $calibration, array $evidence = []): array
    {
        if (($prediction['decision'] ?? '') !== 'PREDICTION_READY') {
            return ['confidence' => null, 'breakdown' => null, 'components' => [], 'excluded' => [], 'reason' => 'NO_PREDICTION'];
        }

        $components = [];
        $excluded = [];
        $add = function (string $key, ?float $value, string $note) use (&$components, &$excluded): void {
            if ($value === null) {
                $excluded[] = ['component' => $key, 'weight' => self::WEIGHTS[$key] ?? 0.0, 'note' => $note];
                return;
            }
            $components[$key] = ['value' => round(max(0.0, min(100.0, $value)), 2), 'weight' => self::WEIGHTS[$key] ?? 0.0, 'note' => $note];
        };

        $p = is_numeric($prediction['calibratedProbability'] ?? null) ? (float) $prediction['calibratedProbability'] : null;
        $inputs = is_array($evidence['inputs'] ?? null) ? $evidence['inputs'] : [];
        $features = is_array($evidence['features'] ?? null) ? $evidence['features'] : [];
        $form = is_array($inputs['recentForm'] ?? null) ? $inputs['recentForm'] : [];
        $market = strtoupper(trim((string) ($evidence['market'] ?? $prediction['market'] ?? '')));
        $selection = strtoupper(trim((string) ($evidence['selection'] ?? $prediction['selection'] ?? '')));

        // ── Data quality — the transparent, already-audited score. ────────
        $dq = is_numeric($quality['score'] ?? null) ? (float) $quality['score'] : null;
        $add('dataQuality', $dq, $dq === null
            ? 'no data-quality assessment is stored for this fixture'
            : 'weighted evidence coverage ' . round($dq) . '/100 (band ' . (string) ($quality['band'] ?? 'UNKNOWN') . ')');

        // ── Calibration — a state, and an absence is NOT a zero. ──────────
        [$calValue, $calNote] = $this->calibrationComponent($calibration);
        $add('calibration', $calValue, $calNote);

        // ── Model probability — sharpness of the model's own read. ────────
        // A 50/50 read carries no confidence; a 0.90 read carries a lot. The
        // mapping is linear in the distance from the coin flip so nobody has
        // to guess what the figure is a fraction of.
        // Full marks at SHARPNESS_CEILING points from the coin flip (a 0.90 /
        // 0.10 read). Demanding certainty for full marks would make the
        // component unreachable and drag every honest read down, which is the
        // same "punish an absence" mistake the calibration term used to make.
        $add('modelProbability', $p === null ? null : 100.0 * min(1.0, abs($p - 0.5) / self::SHARPNESS_CEILING),
            $p === null ? 'the model produced no probability' : 'calibrated model probability ' . round($p * 100, 1) . '% (' . round(abs($p - 0.5) * 100, 1) . ' points from a coin flip)');

        // ── Team form — verified recent-form coverage and its sample size. ─
        [$formValue, $formNote] = $this->formComponent($form);
        $add('teamForm', $formValue, $formNote);

        // ── Recent results — the stored W-D-L run, when a provider gave one.
        [$resultsValue, $resultsNote] = $this->recentResultsComponent($form, $inputs);
        $add('recentResults', $resultsValue, $resultsNote);

        // ── Home/away performance — does the venue split back the selection?
        [$venueValue, $venueNote] = $this->homeAwayComponent($features, $market, $selection);
        $add('homeAwayPerformance', $venueValue, $venueNote);

        // ── Goals scored/conceded — does goal expectancy back the selection?
        [$goalsValue, $goalsNote] = $this->goalsComponent($features, $market, $selection);
        $add('goalsScoredConceded', $goalsValue, $goalsNote);

        // ── Optional feeds. Absent → excluded, never invented. ────────────
        [$h2hValue, $h2hNote] = $this->headToHeadComponent($inputs['historical'] ?? null);
        $add('headToHead', $h2hValue, $h2hNote);
        [$posValue, $posNote] = $this->leaguePositionComponent($inputs);
        $add('leaguePosition', $posValue, $posNote);
        [$injValue, $injNote] = $this->injuriesComponent($inputs);
        $add('injuriesNews', $injValue, $injNote);

        // ── Market agreement — the bookmaker's implied probability. ───────
        $implied = is_numeric($evidence['impliedProbability'] ?? null) ? (float) $evidence['impliedProbability'] : null;
        if ($implied === null && is_numeric($evidence['odds'] ?? null) && (float) $evidence['odds'] > 1.0) {
            $implied = 1.0 / (float) $evidence['odds'];
        }
        if ($p === null || $implied === null) {
            $add('marketAgreement', null, 'no bookmaker implied probability is stored beside the model probability');
        } else {
            // Agreement, not approval: 0 points of divergence is full marks,
            // 25 points of divergence is none. A price that disagrees with the
            // model is a value signal (measured in ValueEngine) — it is not
            // extra certainty, so it lowers this component rather than raising it.
            $divergence = abs($p - $implied);
            $add('marketAgreement', max(0.0, 100.0 * (1.0 - $divergence / 0.25)),
                'model ' . round($p * 100, 1) . '% vs market implied ' . round($implied * 100, 1) . '% (' . round($divergence * 100, 1) . ' points apart)');
        }

        // ── Market consistency — the quoted sheet's own coherence. ────────
        [$consValue, $consNote] = $this->marketConsistencyComponent($evidence['marketPrices'] ?? null);
        $add('marketConsistency', $consValue, $consNote);

        // ── Compose: Σ(value × weight) / Σ(weight) over the available set. ─
        $usedWeight = 0.0;
        $weighted = 0.0;
        foreach ($components as $component) {
            $usedWeight += (float) $component['weight'];
            $weighted += (float) $component['value'] * (float) $component['weight'];
        }
        $totalWeight = array_sum(self::WEIGHTS);
        $share = $totalWeight > 0 ? $usedWeight / $totalWeight : 0.0;

        if (count($components) < self::MIN_COMPONENTS || $share < self::MIN_WEIGHT_SHARE || $usedWeight <= 0.0) {
            // Too little independent evidence to publish a number. Reported
            // honestly — never replaced by a default or an average.
            return [
                'confidence' => null,
                'reason' => 'INSUFFICIENT_CONFIDENCE_EVIDENCE',
                'breakdown' => $this->legacyBreakdown($components),
                'components' => $components,
                'excluded' => $excluded,
                'weightShare' => round($share, 4),
                'method' => 'Σ(value × weight) / Σ(weight) over available components, capped at ' . self::CAP,
            ];
        }

        $confidence = min(self::CAP, $weighted / $usedWeight);

        return [
            'confidence' => round($confidence, 2),
            // Legacy shape kept so stored decision factors, the dashboard and
            // older assertions keep reading the same three headline numbers.
            'breakdown' => $this->legacyBreakdown($components),
            'components' => $components,
            'excluded' => $excluded,
            'weightShare' => round($share, 4),
            'componentCount' => count($components),
            'method' => 'Σ(value × weight) / Σ(weight) over available components, capped at ' . self::CAP,
        ];
    }

    /** The three headline numbers the older record shape carried. */
    private function legacyBreakdown(array $components): array
    {
        return [
            'dataQuality' => $components['dataQuality']['value'] ?? null,
            'calibrationQuality' => $components['calibration']['value'] ?? null,
            'probabilitySeparation' => $components['modelProbability']['value'] ?? null,
        ];
    }

    /**
     * Calibration as a STATE:
     *   • measured ECE over enough settled samples → 100·(1−ECE);
     *   • an approved mapping with no settled history yet (the identity
     *     bootstrap) → LIMITED, worth what an uncorrected figure is worth —
     *     NOT zero, which is what locked whole deployments below the floor;
     *   • no calibration attached at all → excluded from the score.
     *
     * @return array{0:?float,1:string}
     */
    private function calibrationComponent(?array $calibration): array
    {
        if ($calibration === null) return [null, 'no calibration is attached to this prediction'];
        $samples = is_numeric($calibration['samples'] ?? null) ? (float) $calibration['samples'] : 0.0;
        $ece = $calibration['ece'] ?? null;
        if (is_numeric($ece) && $samples >= self::MIN_CALIBRATION_SAMPLES) {
            return [100.0 * max(0.0, min(1.0, 1.0 - (float) $ece)),
                'calibrated against settled history: ECE ' . round((float) $ece, 4) . ' over ' . (int) $samples . ' samples'];
        }
        return [self::BOOTSTRAP_CALIBRATION_VALUE,
            'an approved calibration is in force but has no measured ECE yet ('
            . (int) $samples . ' settled samples, ' . self::MIN_CALIBRATION_SAMPLES . ' required): scored as LIMITED evidence, not as an absence'];
    }

    /**
     * Verified recent form: the four venue goal rates the model consumes, plus
     * the sample size behind them when the provider stated one.
     *
     * @return array{0:?float,1:string}
     */
    private function formComponent(array $form): array
    {
        $required = FeatureEngineeringEngine::REQUIRED_FORM_FIELDS;
        $present = 0;
        foreach ($required as $field) if (isset($form[$field]) && is_numeric($form[$field])) $present++;
        if ($present === 0) return [null, 'no verified recent-form rates are stored'];
        $coverage = 100.0 * $present / max(1, count($required));

        // Sample size: a rate from 10+ matches is solid evidence, a rate from
        // two matches is thin. Unstated → coverage alone carries the component.
        $matches = null;
        foreach (['matchesPlayed', 'matches', 'sampleSize', 'homeMatchesPlayed'] as $key) {
            if (isset($form[$key]) && is_numeric($form[$key])) { $matches = (float) $form[$key]; break; }
        }
        if ($matches === null) {
            return [$coverage, $present . ' of ' . count($required) . ' verified venue form rates present (source '
                . (string) ($form['source'] ?? 'unknown') . '); no match count stated'];
        }
        $sample = 100.0 * min(1.0, $matches / 10.0);
        return [0.6 * $coverage + 0.4 * $sample,
            $present . ' of ' . count($required) . ' verified venue form rates over ' . (int) $matches . ' match(es), source '
            . (string) ($form['source'] ?? 'unknown')];
    }

    /**
     * Recent results (the W-D-L run) — a separate feed from the goal rates.
     * @return array{0:?float,1:string}
     */
    private function recentResultsComponent(array $form, array $inputs): array
    {
        $run = null;
        foreach ([$form['recentResults'] ?? null, $form['form'] ?? null, $inputs['recentResults'] ?? null] as $candidate) {
            if (is_string($candidate) && preg_match('/^[WDLwdl]+$/', trim($candidate))) { $run = strtoupper(trim($candidate)); break; }
            if (is_array($candidate) && $candidate !== []) { $run = $candidate; break; }
        }
        if ($run === null) return [null, 'no stored recent-results run for either side'];
        if (is_string($run)) {
            $n = strlen($run);
            $points = 0;
            for ($i = 0; $i < $n; $i++) $points += $run[$i] === 'W' ? 3 : ($run[$i] === 'D' ? 1 : 0);
            // Both the length of the run (evidence) and how decisive it is.
            $depth = 100.0 * min(1.0, $n / 5.0);
            $decisiveness = 100.0 * abs(($points / max(1, 3 * $n)) - 0.5) * 2.0;
            return [0.6 * $depth + 0.4 * $decisiveness, 'stored run ' . $run . ' (' . $n . ' matches, ' . $points . ' points)'];
        }
        $n = count($run);
        return [100.0 * min(1.0, $n / 5.0), $n . ' stored recent result row(s)'];
    }

    /**
     * Home/away performance: does the venue split actually support the
     * selection? Measured from the stored venue rates, never assumed.
     *
     * @return array{0:?float,1:string}
     */
    private function homeAwayComponent(array $features, string $market, string $selection): array
    {
        foreach (['homeAttack', 'awayAttack', 'homeDefenseConceded', 'awayDefenseConceded'] as $key) {
            if (!isset($features[$key]) || !is_numeric($features[$key])) {
                return [null, 'the venue split is not stored for both sides'];
            }
        }
        $homeStrength = ((float) $features['homeAttack'] + (float) $features['awayDefenseConceded']) / 2;
        $awayStrength = ((float) $features['awayAttack'] + (float) $features['homeDefenseConceded']) / 2;
        $edge = $homeStrength - $awayStrength;   // positive = home venue advantage in the data

        // How far the split leans, scaled: a full goal of venue separation is
        // a strong reading, a dead-level split is no reading at all.
        $magnitude = 100.0 * min(1.0, abs($edge) / 1.0);
        $supports = match (true) {
            $market === 'MATCH_RESULT' && $selection === 'HOME' => $edge > 0,
            $market === 'MATCH_RESULT' && $selection === 'AWAY' => $edge < 0,
            $market === 'DOUBLE_CHANCE' && $selection === 'HOME_OR_DRAW' => $edge > 0,
            $market === 'DOUBLE_CHANCE' && $selection === 'AWAY_OR_DRAW' => $edge < 0,
            // A level split is exactly what a draw-inclusive / goals market
            // wants to see, so those read the CLOSENESS of the two sides.
            default => null,
        };
        if ($supports === null) {
            $level = 100.0 - $magnitude;
            return [$level, sprintf('venue strengths %.2f (home) vs %.2f (away): %.2f apart', $homeStrength, $awayStrength, abs($edge))];
        }
        return [$supports ? $magnitude : max(0.0, 100.0 - 2.0 * $magnitude),
            sprintf('venue strengths %.2f (home) vs %.2f (away) %s %s', $homeStrength, $awayStrength, $supports ? 'support' : 'contradict', $selection)];
    }

    /**
     * Goals scored/conceded: the goal-expectancy proxy against the selected
     * market's own line. Only the stored rates are used.
     *
     * @return array{0:?float,1:string}
     */
    private function goalsComponent(array $features, string $market, string $selection): array
    {
        if (!isset($features['expectedGoalsProxy']) || !is_numeric($features['expectedGoalsProxy'])) {
            return [null, 'no stored goals scored/conceded rates for this fixture'];
        }
        $total = (float) $features['expectedGoalsProxy'];
        $line = PredictionEngine::totalsLine($selection);
        if ($market === 'TOTAL_GOALS' && $line !== null) {
            $distance = $total - $line[0];
            $supports = $line[1] === 'OVER' ? $distance > 0 : $distance < 0;
            $magnitude = 100.0 * min(1.0, abs($distance) / 1.0);
            return [$supports ? $magnitude : max(0.0, 100.0 - 2.0 * $magnitude),
                sprintf('goal expectancy %.2f vs the %.1f line (%s %s)', $total, $line[0], $supports ? 'supports' : 'contradicts', $selection)];
        }
        if ($market === 'BTTS') {
            $lowSide = min(
                ((float) ($features['homeAttack'] ?? 0) + (float) ($features['awayDefenseConceded'] ?? 0)) / 2,
                ((float) ($features['awayAttack'] ?? 0) + (float) ($features['homeDefenseConceded'] ?? 0)) / 2
            );
            $magnitude = 100.0 * min(1.0, abs($lowSide - 0.75) / 0.75);
            return [$lowSide >= 0.75 ? $magnitude : max(0.0, 100.0 - 2.0 * $magnitude),
                sprintf('the weaker attack/defence pairing scores %.2f goals per match against the 0.75 both-teams-score threshold', $lowSide)];
        }
        // Result markets: a high-scoring game separates the sides less
        // reliably, so the reading is how far from a goalless stalemate the
        // stored rates put the fixture.
        return [100.0 * min(1.0, $total / 3.0), sprintf('stored goal expectancy %.2f goals per match', $total)];
    }

    /** @return array{0:?float,1:string} */
    private function headToHeadComponent($historical): array
    {
        if (!is_array($historical) || $historical === []) return [null, 'no head-to-head record is stored'];
        $meetings = null;
        foreach (['meetings', 'matches', 'played', 'count'] as $key) {
            if (isset($historical[$key]) && is_numeric($historical[$key])) { $meetings = (float) $historical[$key]; break; }
        }
        if ($meetings === null) $meetings = (float) count(array_filter($historical, 'is_array'));
        if ($meetings <= 0) return [null, 'the stored head-to-head record contains no meetings'];
        return [100.0 * min(1.0, $meetings / 5.0), (int) $meetings . ' stored head-to-head meeting(s)'];
    }

    /** @return array{0:?float,1:string} */
    private function leaguePositionComponent(array $inputs): array
    {
        $sources = [$inputs['standings'] ?? null, $inputs['leaguePosition'] ?? null, $inputs['table'] ?? null];
        $recentForm = is_array($inputs['recentForm'] ?? null) ? $inputs['recentForm'] : [];
        $sources[] = $recentForm['standings'] ?? null;
        $home = null; $away = null;
        foreach ($sources as $source) {
            if (!is_array($source)) continue;
            foreach (['home', 'homePosition', 'homeRank'] as $key) if (isset($source[$key]) && is_numeric($source[$key])) { $home = (float) $source[$key]; break; }
            foreach (['away', 'awayPosition', 'awayRank'] as $key) if (isset($source[$key]) && is_numeric($source[$key])) { $away = (float) $source[$key]; break; }
            if ($home !== null && $away !== null) break;
        }
        foreach (['homeLeaguePosition' => 'home', 'awayLeaguePosition' => 'away'] as $key => $slot) {
            if (isset($recentForm[$key]) && is_numeric($recentForm[$key])) {
                if ($slot === 'home') $home ??= (float) $recentForm[$key]; else $away ??= (float) $recentForm[$key];
            }
        }
        if ($home === null || $away === null) return [null, 'no league standings position is stored for both sides'];
        // A wide table gap is a clear reading; neighbours in the table are not.
        return [100.0 * min(1.0, abs($home - $away) / 10.0),
            'league positions ' . (int) $home . ' vs ' . (int) $away . ' (' . (int) abs($home - $away) . ' places apart)'];
    }

    /** @return array{0:?float,1:string} */
    private function injuriesComponent(array $inputs): array
    {
        $injuries = $inputs['injuries'] ?? null;
        $lineups = $inputs['lineups'] ?? null;
        if (!is_array($injuries) && !is_array($lineups)) return [null, 'no injury or team-news feed is stored'];
        // A CONFIRMED team sheet is the strongest pre-match news there is; an
        // injury list that exists at all is worth less but is still evidence.
        if (is_array($lineups) && $lineups !== []) return [100.0, 'a stored lineup/team sheet is available'];
        if (is_array($injuries)) {
            // An empty-but-present injury feed is an attested "nothing to
            // report", which is weaker evidence than a populated one.
            return [$injuries === [] ? 60.0 : 85.0, $injuries === [] ? 'an injury feed is attached and reports nothing' : count($injuries) . ' stored injury/news row(s)'];
        }
        return [60.0, 'a partial team-news feed is stored'];
    }

    /**
     * Market consistency: the quoted sheet's own coherence. A complete market
     * whose implied probabilities sum close to 1 is a healthy, liquid price;
     * a wildly overrounded sheet is a thin one. Nothing here is invented — a
     * partial sheet is simply not scored.
     *
     * @return array{0:?float,1:string}
     */
    private function marketConsistencyComponent($marketPrices): array
    {
        if (!is_array($marketPrices) || count($marketPrices) < 2) {
            return [null, 'the complete market price sheet is not stored (overround cannot be measured)'];
        }
        $sum = 0.0;
        $quotes = 0;
        foreach ($marketPrices as $price) {
            $odds = is_array($price) ? ($price['odds'] ?? null) : $price;
            if (!is_numeric($odds) || (float) $odds <= 1.0) continue;
            $sum += 1.0 / (float) $odds;
            $quotes++;
        }
        if ($quotes < 2 || $sum <= 0) return [null, 'fewer than two quotable prices in the stored market'];
        $overround = $sum - 1.0;
        // 0 % overround = a perfectly fair sheet (100), 20 % = none left.
        return [max(0.0, 100.0 * (1.0 - abs($overround) / 0.20)),
            $quotes . ' quoted prices, overround ' . round($overround * 100, 2) . '%'];
    }
}
