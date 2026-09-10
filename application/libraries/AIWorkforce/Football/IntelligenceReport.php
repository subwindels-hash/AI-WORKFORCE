<?php
namespace AIWorkforce\Football;

use AIWorkforce\Persistence\FootballRepository;

/**
 * The intelligence layer: the block every surface shows for one match, and the
 * ranked "Top WINDELS Picks" reading of a page.
 *
 * This class owns no arithmetic of its own. It places five measured things next
 * to each other in one structure — the model's estimate (`prediction`), how well
 * evidenced it is (`quality` and `score`), what the market charges for it and
 * whether that is attractive (`fairValue`), whether the estimate has been steady
 * (`stability`) and how old each input is (`freshness`) — because the difference
 * between a football intelligence product and an odds mirror is precisely that
 * those five are *separate, labelled columns* rather than one blended verdict.
 *
 * Three properties the whole module depends on:
 *
 *  - **One path, both surfaces.** The console board and `/api/football/*` call
 *    this, so a figure cannot exist in the API and be missing from the page, or
 *    — worse — be computed differently by each. There is no second implementation
 *    to drift.
 *  - **Reads only.** Everything here is assembled from stored rows: the
 *    prediction, its feature snapshot, the score grid, the quoted odds, the
 *    revision trail, the sync log. A page of fifty matches costs the same one
 *    batched revision read whether it is browsed once or a hundred times, and
 *    never makes a provider call.
 *  - **Silence where data is absent.** A match with no prediction is
 *    `NOT_ANALYZED`, not zero-scored; a market nobody priced is `UNPRICED`, not
 *    "fair"; a first reading has no movement and is `BASELINE`, not "stable". The
 *    withheld state is a real answer and is stated as one.
 */
final class IntelligenceReport
{
    /** What the block can say about one match. */
    public const STATE_SCORED = 'SCORED';
    public const STATE_PARTIAL = 'PARTIALLY_EVIDENCED';
    public const STATE_WITHHELD = 'PREDICTION_WITHHELD';
    public const STATE_NOT_ANALYZED = 'NOT_ANALYZED';

    /** The sentence every ranked list of picks must carry. */
    public const PICKS_DISCLAIMER = 'These are model-based selections ranked by how well evidenced they are, not '
        . 'guarantees and not a staking instruction. A pick is a reading of stored football data; matches are '
        . 'won and lost regardless of it.';

    /**
     * The risk label a reader is shown next to a prediction.
     *
     * It is deliberately derived from the two things the reader has already been
     * told — the data-quality band and the model's confidence — and never from the
     * price. A cheap price is not a safe bet; a match with thin evidence and a
     * 52% estimate is, whatever it pays. The thresholds are the board's published
     * cut-offs, kept in one place so a row cannot read `MEDIUM` here and `HIGH`
     * there.
     */
    public static function risk(string $band, ?float $confidence): array
    {
        // Cut lines mirror the board's football-realistic confidence tiers
        // (Standard ≥45, Highest ≥60): LOW is well-evidenced and confident,
        // MEDIUM is an ordinary analyzed match, HIGH is thin evidence or a
        // confidence below the lowest tier.
        if ($confidence === null) return ['level' => 'UNKNOWN', 'basis' => 'no prediction is stored for this match'];
        if ($band !== QualityBand::QUALIFIED || $confidence < 45.0) {
            return ['level' => 'HIGH', 'basis' => 'data quality ' . $band . ' and model confidence ' . number_format($confidence, 1) . '%'];
        }
        if ($confidence < 60.0) return ['level' => 'MEDIUM', 'basis' => 'model confidence ' . number_format($confidence, 1) . '%'];
        return ['level' => 'LOW', 'basis' => 'qualified data and model confidence ' . number_format($confidence, 1) . '%'];
    }

    public function __construct(
        private FootballRepository $repo,
        private FootballConfiguration $config,
        private StabilityMonitor $stability,
        private IntelligenceScore $scores,
        private PredictionDrivers $drivers,
        private FreshnessTracker $freshness,
    ) {}

    /**
     * One intelligence block per entry, with the page's shared reads taken once:
     * the revision trail for every fixture on the page, and the last fixture
     * sweep for the data clock.
     *
     * @param list<array{fixture:array<string,mixed>,prediction:?array<string,mixed>,market:array<string,mixed>}> $entries
     * @return list<array<string,mixed>>
     */
    public function forPage(array $entries): array
    {
        $fixtureIds = [];
        foreach ($entries as $entry) {
            $id = (int) (($entry['prediction']['fixture_id'] ?? $entry['fixture']['id'] ?? 0));
            if ($id > 0) $fixtureIds[] = $id;
        }
        $history = $fixtureIds === [] ? [] : $this->stability->history($fixtureIds);
        $lastSweep = $fixtureIds === [] ? null : $this->repo->lastSyncRun('football-fixtures');
        $out = [];
        foreach ($entries as $entry) {
            $prediction = is_array($entry['prediction'] ?? null) ? (array) $entry['prediction'] : null;
            $out[] = $this->forMatch(
                (array) ($entry['fixture'] ?? []),
                $prediction,
                (array) ($entry['market'] ?? []),
                $prediction === null ? null : $this->stability->read($prediction, $history),
                $lastSweep,
                (array) ($entry['predictionRefusal'] ?? []),
            );
        }
        return $out;
    }

    /**
     * The block for one match.
     *
     * @param array<string,mixed> $fixture
     * @param array<string,mixed>|null $prediction the stored prediction row, or null
     * @param array<string,mixed> $market the evaluated market block for the chosen market
     * @param array<string,mixed>|null $stability the pre-read stability verdict
     * @param array<string,mixed> $refusal why this match has no prediction, when
     *        the feed already knows (it is passed through, never invented here)
     * @return array<string,mixed>
     */
    public function forMatch(array $fixture, ?array $prediction, array $market = [], ?array $stability = null,
        ?array $lastSweep = null, array $refusal = []): array
    {
        if ($prediction === null) {
            // No row means no estimate to score. "Not analyzed" and "withheld"
            // are different states and are reported as such: the former has
            // simply never been run through the engine (generating the page or
            // opening the match analyzes it), while the latter was analyzed and
            // refused by the quality gate. Conflating the two is what used to
            // print "withheld" on matches nobody had asked about yet.
            $refusal = $refusal === [] ? (array) ($market['predictionRefusal'] ?? []) : $refusal;
            $code = (string) ($refusal['code'] ?? 'NOT_ANALYZED');
            $wasRefused = $code !== '' && $code !== 'NOT_ANALYZED' && $code !== 'NO_PREDICTION' && $code !== 'BATCH_LIMIT_REACHED';
            $reason = (string) ($refusal['reason'] ?? '');
            if ($reason === '') {
                $reason = 'This match has not been analyzed yet. Generating the '
                    . 'page analyzes at most ' . MatchFeed::MAX_PAGE_SIZE . ' matches, or open the match and choose '
                    . 'Analyze — a match whose stored data falls below the ' . QualityBand::LIMITED_MIN
                    . '-point quality floor is then left without a prediction rather than given a thin one.';
            }
            return [
                'state' => self::STATE_NOT_ANALYZED,
                'score' => $this->scores->compute([]),
                'quality' => ['score' => null, 'band' => QualityBand::REJECTED, 'checklist' => [],
                    'note' => 'No data-quality assessment is stored for this match: the prediction engine has not '
                        . 'run over it, so there is nothing to grade.'],
                'drivers' => ['drivers' => [], 'headline' => null, 'counts' => [],
                    'disclaimer' => PredictionDrivers::DISCLAIMER],
                'fairValue' => self::unpricedValue('No prediction is stored for this match, so there is no estimate '
                    . 'to compare with a price.'),
                'stability' => ['state' => StabilityMonitor::UNKNOWN, 'label' => StabilityMonitor::label(StabilityMonitor::UNKNOWN),
                    'reason' => 'No prediction row exists, so there is nothing to have moved.', 'disclaimer' => StabilityMonitor::DISCLAIMER],
                'freshness' => $this->freshness->stamp([], $fixture, $market, $lastSweep),
                'withheld' => [
                    'withheld' => $wasRefused,
                    'needsAnalysis' => !$wasRefused,
                    'headline' => $wasRefused
                        ? 'Prediction withheld — insufficient verified data'
                        : 'Not analyzed yet — no prediction stored',
                    'reason' => $reason,
                    'code' => $code,
                ],
            ];
        }

        $quality = self::decode($prediction['quality_components'] ?? null, []);
        $band = (string) ($prediction['data_quality_band'] ?? QualityBand::REJECTED);
        $qualityScore = (int) ($prediction['data_quality_score'] ?? 0);
        $coverage = is_numeric($market['coverage'] ?? null) ? (float) $market['coverage'] : null;
        $grid = self::decode($prediction['probabilities_matrix'] ?? null, []);
        $gridCoverage = is_numeric($grid['gridCoverage'] ?? null) ? (float) $grid['gridCoverage'] : $coverage;
        $stability ??= $this->stability->read($prediction, $this->stability->history([(int) ($prediction['fixture_id'] ?? 0)]));
        $score = $this->scores->compute([
            'confidence' => is_numeric($prediction['confidence'] ?? null) ? (float) $prediction['confidence'] : null,
            'confidenceBasis' => (string) ($prediction['confidence_basis'] ?? 'RAW'),
            'dataQuality' => $qualityScore,
            'band' => $band,
            'coverage' => $gridCoverage,
            'calibrationState' => (string) ($prediction['calibration_state'] ?? CalibrationService::PENDING),
            'stability' => $stability,
        ]);

        $checklist = self::checklist($quality, $prediction, $market);
        $value = self::valueBlock($market);
        $drivers = $this->drivers->describe($prediction, $market, $fixture);
        // Only REJECTED is withheld: LIMITED evidence is published and usable,
        // with its capped confidence and thinner basis stated on the face of it.
        // Withholding every LIMITED match is what left analyzed pages with
        // nothing a user could act on.
        if ($band === QualityBand::REJECTED) {
            $withheld = ['withheld' => true, 'limitedEvidence' => false,
                'code' => 'DATA_QUALITY_REJECTED',
                // The sentence the module was specified to print. It is published
                // as its own field so a surface can show it verbatim instead of
                // paraphrasing a refusal into something softer.
                'headline' => 'Prediction withheld — insufficient verified data',
                'reason' => 'Prediction withheld — the stored data for this match scored ' . $qualityScore
                    . '/100, below the ' . QualityBand::LIMITED_MIN . '-point floor the module will publish on. '
                    . 'The row is kept for audit, not offered as a pick.'];
        } elseif ($band === QualityBand::LIMITED) {
            $withheld = ['withheld' => false, 'limitedEvidence' => true,
                'code' => 'DATA_QUALITY_LIMITED',
                'headline' => 'Limited evidence — usable with caution',
                'reason' => 'Published with limited evidence: the data quality of ' . $qualityScore . '/100 clears the '
                    . QualityBand::LIMITED_MIN . '-point floor but not the ' . QualityBand::QUALIFIED_MIN
                    . '-point one, so confidence is capped below the Highest tier and the pick list ranks this '
                    . 'match after fully evidenced ones. The probabilities and WINDELS fair odds below are usable.'];
        } else {
            $withheld = ['withheld' => false, 'limitedEvidence' => false, 'code' => null, 'reason' => ''];
        }

        $state = $withheld['withheld'] ? self::STATE_WITHHELD
            : ($score['score'] === null || ($withheld['limitedEvidence'] ?? false) ? self::STATE_PARTIAL
                : (count($checklist['missing']) > 0 ? self::STATE_PARTIAL : self::STATE_SCORED));

        return [
            'state' => $state,
            'predictionId' => (string) ($prediction['id'] ?? ''),
            'score' => $score,
            'probability' => [
                'home' => $prediction['probability_home'] ?? null,
                'draw' => $prediction['probability_draw'] ?? null,
                'away' => $prediction['probability_away'] ?? null,
                'basis' => (string) ($prediction['confidence_basis'] ?? 'RAW'),
                'note' => 'WINDELS\' own reading of the stored football data. The market\'s price is reported '
                    . 'separately, and the two are never averaged.',
            ],
            'quality' => ['score' => $qualityScore, 'band' => $band, 'checklist' => $checklist['rows'],
                'missing' => $checklist['missing'], 'thresholds' => ['qualified' => QualityBand::QUALIFIED_MIN,
                    'limited' => QualityBand::LIMITED_MIN],
                'note' => $checklist['note']],
            'drivers' => $drivers,
            // Risk is the reader's third question after "what" and "how sure":
            // how exposed this is. Derived from the band and the confidence only —
            // never from the price — and by the same function the board's risk
            // column calls, so a match cannot be LOW here and MEDIUM there.
            'risk' => self::risk($band, is_numeric($prediction['confidence'] ?? null) ? (float) $prediction['confidence'] : null),
            'fairValue' => $value,
            'stability' => $stability,
            'freshness' => $this->freshness->stamp($prediction, $fixture, $market, $lastSweep),
            'withheld' => $withheld,
        ];
    }

    /**
     * The "Top WINDELS Picks" reading of a page.
     *
     * Eligibility comes first and is a stated filter, not a mood: a match enters
     * the list when its data is QUALIFIED or LIMITED, the chosen market has an
     * actual selection to offer, and the prediction is not sitting on an unstable
     * reading. Only REJECTED/withheld rows are excluded for quality. Ranking is
     * by evidence band first (QUALIFIED before LIMITED), then by the intelligence
     * score, then by the edge against the quoted price, then by confidence — so
     * the list rewards evidence and never lets a big price gap promote a thin
     * football read above a well-evidenced one.
     *
     * @param list<array<string,mixed>> $rows board rows, each with `intelligence`
     * @return array{state:string,picks:list<array<string,mixed>>,considered:int,eligible:int,excluded:list<array<string,mixed>>,limit:int,rule:list<string>,disclaimer:string,generatedAt:string}
     */
    public function picks(array $rows, ?string $marketLabel = null): array
    {
        $limit = $this->config->picksLimit();
        $picks = [];
        $excluded = [];
        foreach ($rows as $row) {
            $intelligence = (array) ($row['intelligence'] ?? []);
            $identity = ['matchId' => (string) ($row['matchId'] ?? ''), 'fixtureId' => (int) ($row['fixtureId'] ?? 0),
                'homeTeam' => (string) ($row['homeTeam'] ?? DataState::UNAVAILABLE),
                'awayTeam' => (string) ($row['awayTeam'] ?? DataState::UNAVAILABLE),
                'kickoffLabel' => (string) ($row['kickoffLabel'] ?? DataState::UNAVAILABLE)];
            $market = (array) ($row['market'] ?? []);
            if (($row['analysisState'] ?? '') !== 'ANALYZED' || $intelligence === []) {
                $excluded[] = $identity + ['reason' => 'Not analyzed — no stored prediction to rank.'];
                continue;
            }
            $band = (string) ($intelligence['quality']['band'] ?? QualityBand::REJECTED);
            if ($band !== QualityBand::QUALIFIED && $band !== QualityBand::LIMITED) {
                $excluded[] = $identity + ['reason' => 'Data quality band ' . $band . '; the pick list admits '
                    . QualityBand::QUALIFIED . ' and ' . QualityBand::LIMITED . ' only.'];
                continue;
            }
            if (($intelligence['withheld']['withheld'] ?? false) === true) {
                $excluded[] = $identity + ['reason' => (string) ($intelligence['withheld']['reason'] ?? 'Withheld.')];
                continue;
            }
            if (($market['state'] ?? '') !== PredictionMarkets::STATE_AVAILABLE || ($market['selection'] ?? null) === null) {
                $excluded[] = $identity + ['reason' => 'The selected market has no answer for this match: '
                    . (string) ($market['reason'] ?? 'no stored input models it') . '.'];
                continue;
            }
            $stabilityState = (string) ($intelligence['stability']['state'] ?? StabilityMonitor::UNKNOWN);
            if ($stabilityState === StabilityMonitor::UNSTABLE) {
                $excluded[] = $identity + ['reason' => 'Prediction unstable — '
                    . (string) ($intelligence['stability']['reason'] ?? 'significant model movement since the last re-read') . '.'];
                continue;
            }
            $value = (array) ($intelligence['fairValue'] ?? []);
            $risk = (array) ($row['risk'] ?? []);
            $picks[] = array_merge($identity, [
                'band' => $band,
                'limitedEvidence' => $band === QualityBand::LIMITED,
                'market' => (string) ($market['label'] ?? ''),
                // Both spellings of the pick: the code a machine filters on, and
                // the label a reader sees. A surface that prints the code has
                // published `HOME` where it meant `Arsenal win`.
                'selection' => (string) ($market['selection'] ?? ''),
                'selectionLabel' => (string) ($market['selectionLabel'] ?? ''),
                'probability' => is_numeric($market['probability'] ?? null) ? round((float) $market['probability'], 6) : null,
                'confidence' => is_numeric($row['confidence'] ?? null) ? round((float) $row['confidence'], 1) : null,
                'dataQuality' => (int) ($intelligence['quality']['score'] ?? 0),
                'score' => $intelligence['score']['score'] ?? null,
                'scoreBand' => (string) ($intelligence['score']['band'] ?? IntelligenceScore::BAND_INSUFFICIENT),
                'valueClass' => (string) ($value['valueClass'] ?? OddsIntelligence::CLASS_UNPRICED),
                'valueLabel' => (string) ($value['valueLabel'] ?? OddsIntelligence::LABELS[OddsIntelligence::CLASS_UNPRICED]),
                'edgePoints' => $value['edgePoints'] ?? null,
                // The class and its sentence, under the names the pick list uses.
                // They are aliases of `valueLabel`/`valueReason` rather than new
                // figures: one value judged by two names is how a table and a
                // panel end up disagreeing about the same price.
                'classificationLabel' => (string) ($value['valueLabel'] ?? OddsIntelligence::LABELS[OddsIntelligence::CLASS_UNPRICED]),
                'classificationMeaning' => (string) ($value['valueReason'] ?? ''),
                'risk' => (string) ($risk['level'] ?? 'UNKNOWN'),
                'expectedValue' => $value['expectedValue'] ?? null,
                'odds' => $value['odds'] ?? null,
                'fairOdds' => $value['windelsFairOdds'] ?? null,
                'stabilityState' => $stabilityState,
                'stabilityLabel' => StabilityMonitor::label($stabilityState),
                'stabilityReason' => (string) ($intelligence['stability']['reason'] ?? ''),
                'warnings' => array_values(array_filter([
                    $stabilityState === StabilityMonitor::MOVED ? 'Prediction moved since the last re-read.' : null,
                    ($intelligence['freshness']['state'] ?? '') === FreshnessTracker::STALE ? 'An input is past its freshness window.' : null,
                    ($value['odds'] ?? null) === null ? 'No market price to compare with; value is unjudged.' : null,
                ], static fn(?string $v): bool => $v !== null && $v !== '')),
            ]);
        }
        // The comparator is a tuple of the four documented sort keys — evidence
        // band first, so a LIMITED pick can never outrank a QUALIFIED one — and
        // the order is total: two picks with the same score cannot swap between
        // page loads because `usort` had no reason to prefer one.
        $bandRank = static fn(array $pick): int => (string) ($pick['band'] ?? QualityBand::REJECTED) === QualityBand::QUALIFIED ? 0 : 1;
        usort($picks, static fn(array $a, array $b): int => [
            $bandRank($a), (int) ($b['score'] ?? 0), round((float) ($b['edgePoints'] ?? 0), 6), round((float) ($b['confidence'] ?? 0), 1),
        ] <=> [
            $bandRank($b), (int) ($a['score'] ?? 0), round((float) ($a['edgePoints'] ?? 0), 6), round((float) ($a['confidence'] ?? 0), 1),
        ]);
        $ranked = [];
        foreach (array_slice($picks, 0, $limit) as $index => $pick) $ranked[] = ['rank' => $index + 1] + $pick;
        return [
            'state' => $ranked === [] ? DataState::UNAVAILABLE : 'AVAILABLE',
            'market' => $marketLabel,
            'picks' => $ranked,
            'considered' => count($rows),
            'eligible' => count($picks),
            'shown' => count($ranked),
            // Qualifying matches the page holds but the limit does not show. They
            // are counted, not hidden: "5 of 9 eligible" is the honest caption.
            'beyondList' => max(0, count($picks) - $limit),
            'excluded' => $excluded,
            'limit' => $limit,
            'rule' => [
                'eligibility' => 'Only matches on this page whose data quality is ' . QualityBand::QUALIFIED . ' or '
                    . QualityBand::LIMITED . ', whose selected market has an actual selection, which are not withheld '
                    . 'and whose prediction is not flagged unstable.',
                'ranking' => 'Evidence band first (QUALIFIED before LIMITED), then the intelligence score, then the edge against the quoted price, then model confidence.',
                'limit' => 'At most ' . $limit . ' picks are listed, whatever the page holds.',
            ],
            'disclaimer' => self::PICKS_DISCLAIMER,
            'generatedAt' => gmdate('c'),
        ];
    }

    /**
     * The page-level reading of the intelligence layer: how much of what is on
     * screen is priced, judged, and settled. Counted over the page that is being
     * shown and labelled as such — a summary that silently describes the whole
     * date while the table shows fifty of its matches is the kind of drift this
     * panel exists to prevent.
     *
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    public function summary(array $rows): array
    {
        $scores = [];
        $values = [];
        $states = ['STABLE' => 0, 'MOVED' => 0, 'UNSTABLE' => 0, 'BASELINE' => 0, 'DATA_UNAVAILABLE' => 0];
        $priced = 0;
        $analyzed = 0;
        $withheld = 0;
        foreach ($rows as $row) {
            $intelligence = (array) ($row['intelligence'] ?? []);
            if ($intelligence === []) continue;
            if (($row['analysisState'] ?? '') === 'ANALYZED') $analyzed++;
            if (is_numeric($intelligence['score']['score'] ?? null)) $scores[] = (int) $intelligence['score']['score'];
            if (($intelligence['withheld']['withheld'] ?? false) === true) $withheld++;
            $class = (string) ($intelligence['fairValue']['valueClass'] ?? OddsIntelligence::CLASS_UNPRICED);
            $values[$class] = ($values[$class] ?? 0) + 1;
            if ($class !== OddsIntelligence::CLASS_UNPRICED) $priced++;
            $state = (string) ($intelligence['stability']['state'] ?? 'DATA_UNAVAILABLE');
            $states[$state] = ($states[$state] ?? 0) + 1;
        }
        sort($scores);
        $middle = $scores === [] ? null : (count($scores) % 2 === 1
            ? $scores[(int) (count($scores) / 2)]
            : (int) round(($scores[count($scores) / 2 - 1] + $scores[count($scores) / 2]) / 2));
        return [
            'scope' => 'the ' . count($rows) . ' matches on this page',
            'analyzed' => $analyzed,
            'withheld' => $withheld,
            'scored' => count($scores),
            'medianScore' => $middle,
            'averageScore' => $scores === [] ? null : round(array_sum($scores) / count($scores), 1),
            'highestScore' => $scores === [] ? null : max($scores),
            'lowestScore' => $scores === [] ? null : min($scores),
            'priced' => $priced,
            'unpriced' => max(0, count($rows) - $priced),
            'valueClasses' => $values,
            'stabilityStates' => $states,
            'unstable' => $states['UNSTABLE'] ?? 0,
            'note' => $scores === []
                ? 'Nothing on this page has a score yet: no match has a stored prediction to grade.'
                : 'Score statistics describe the page being shown, not the whole date.',
        ];
    }

    /**
     * The ✓ / ✗ list of what the prediction actually had.
     *
     * Each line is one weighted family of the data-quality assessment, shown at
     * the value it was scored with. A family at 0 is "no figure", not "no
     * problem" — which is the whole reason the list exists beside the number.
     *
     * @return array{rows:list<array<string,mixed>>,missing:list<string>,note:string}
     */
    private static function checklist(array $quality, array $prediction, array $market): array
    {
        $labels = [
            'fixtureCompleteness' => 'Fixture verified',
            'recentMatchCoverage' => 'Recent match results',
            'teamStatCoverage' => 'Team statistics',
            'leagueStatCoverage' => 'Current standings',
            'headToHead' => 'Head-to-head',
            'freshness' => 'Data freshness',
            'providerReliability' => 'Provider health',
        ];
        $rows = [];
        $missing = [];
        foreach ($labels as $key => $label) {
            $component = (array) ($quality[$key] ?? []);
            if ($component === []) {
                $rows[] = ['key' => $key, 'label' => $label, 'state' => DataState::UNAVAILABLE, 'mark' => '?',
                    'value' => null, 'note' => 'the assessment stored no component for ' . $label];
                $missing[] = $label;
                continue;
            }
            $value = (float) ($component['value'] ?? 0);
            $state = $value >= 70.0 ? DataState::AVAILABLE : ($value >= 40.0 ? DataState::LIMITED : DataState::UNAVAILABLE);
            $rows[] = ['key' => $key, 'label' => $label, 'state' => $state,
                'mark' => $state === DataState::AVAILABLE ? '✓' : ($state === DataState::LIMITED ? '◐' : '✗'),
                'value' => round($value, 1), 'weight' => $component['weight'] ?? null,
                'contribution' => $component['contribution'] ?? null,
                'note' => $state === DataState::AVAILABLE ? 'measured' : ($state === DataState::LIMITED ? 'partially measured' : 'no usable figure stored')];
            if ($state !== DataState::AVAILABLE) $missing[] = $label;
        }
        // Confirmed lineups are the one input the brief asks for by name, and the
        // one this module does not have: it is listed as absent rather than left
        // out, so the gap is visible in the checklist instead of invisible.
        $rows[] = ['key' => 'confirmedLineup', 'label' => 'Confirmed lineup', 'state' => DataState::UNAVAILABLE,
            'mark' => '✗', 'value' => 0.0, 'weight' => null, 'contribution' => null,
            'note' => 'No connected provider delivers a confirmed-lineup field to this module, so the prediction is '
                . 'made without one and says so.'];
        $missing[] = 'Confirmed lineup';
        return ['rows' => $rows, 'missing' => $missing,
            'note' => 'Every line is one weighted family of the stored data-quality assessment. '
                . (count($missing) === 0
                    ? 'Nothing the model wanted was missing.'
                    : count($missing) . ' famil' . (count($missing) === 1 ? 'y is' : 'ies are') . ' not fully measured.')];
    }

    /** The market comparison, pulled out of the evaluated market block. */
    private static function valueBlock(array $market): array
    {
        $value = (array) ($market['value'] ?? []);
        if ($value === []) return self::unpricedValue('The selected market carries no fair-value assessment.');
        $pricing = (array) ($market['pricing'] ?? []);
        return $value + [
            // Whether there is a price to read at all. A consumer that shows the
            // value block must branch on this, not on the absence of a number —
            // `odds: null` and "no market was priced" are different statements.
            'state' => ($value['odds'] ?? null) !== null ? DataState::AVAILABLE : DataState::UNAVAILABLE,
            'marketKey' => (string) ($market['key'] ?? ''),
            'marketLabel' => (string) ($market['label'] ?? ''),
            'selectionLabel' => (string) ($market['selectionLabel'] ?? ''),
            'overround' => $pricing['overround'] ?? null,
            'marginPoints' => $pricing['marginPoints'] ?? null,
            'marginMethod' => $pricing['marginMethod'] ?? null,
            'pricingState' => (string) ($pricing['state'] ?? OddsIntelligence::PRICE_NONE),
            'pricingNote' => $pricing['note'] ?? null,
            'pricedAt' => $pricing['pricedAt'] ?? null,
            'priceStale' => (bool) ($pricing['priceStale'] ?? false),
            'priceBook' => $pricing['family'] ?? null,
        ];
    }

    /** @return array<string,mixed> */
    private static function unpricedValue(string $reason): array
    {
        return [
            'state' => DataState::UNAVAILABLE,
            'selection' => null, 'odds' => null, 'impliedProbability' => null, 'edge' => null, 'edgePoints' => null,
            'expectedValue' => null, 'windelsFairOdds' => null, 'fairOdds' => null, 'fairProbability' => null,
            'edgeAgainstFairPoints' => null, 'marginPoints' => null, 'valueClass' => OddsIntelligence::CLASS_UNPRICED,
            'valueLabel' => OddsIntelligence::LABELS[OddsIntelligence::CLASS_UNPRICED], 'valueReason' => $reason,
            'disclaimer' => OddsIntelligence::DISCLAIMER,
        ];
    }

    /** @return array<string,mixed>|list<mixed> */
    private static function decode(mixed $value, array $default): array
    {
        if (is_array($value)) return $value;
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) return $decoded;
        }
        return $default;
    }
}
