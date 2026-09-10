<?php
namespace AIWorkforce\Football;

use AIWorkforce\Persistence\FootballRepository;

/**
 * Prediction stability: whether a re-read of the same match says the same thing.
 *
 * The question this answers is not "is the number high" but "is the number
 * *settled*". A home win at 61% that became 60% after new data is a prediction
 * reading the same match from a slightly better angle; one that became 48% is a
 * prediction that has not yet found its level, and publishing the second one
 * without saying so would present a moving figure as a firm one.
 *
 * How movement is measured:
 *
 *  - **Against the previous stored reading of the same fixture and model
 *    version**, not against an in-memory guess. `PredictionService` writes one
 *    revision row per calculation (`football_prediction_revisions`), so the
 *    comparison is between two figures the module actually published, each with
 *    its own timestamp. A regeneration that was justified by `RegenerationPolicy`
 *    carries the reason codes into the new revision, which is what lets the panel
 *    say *why* the number moved, not only that it did.
 *  - **On the selection WINDELS is showing**, plus the whole 1X2 triple as
 *    context. Movement of an outcome nobody is recommending is reported in
 *    `probabilities` but does not change the verdict on the pick.
 *  - **Never inferred from a missing record.** A fixture with one reading is
 *    `BASELINE`: there is nothing to compare with, and "no movement observed"
 *    would be a claim about data the module does not have. A fixture with no
 *    stored prediction has no stability block at all.
 *
 * The three thresholds are configuration, and the state is a statement about the
 * *model's* behaviour: an UNSTABLE prediction is still the best estimate the
 * stored data supports right now — it is simply one that a reader should expect
 * to change again before kickoff.
 */
final class StabilityMonitor
{
    public const BASELINE = 'BASELINE';
    public const STABLE = 'STABLE';
    public const MOVED = 'MOVED';
    public const UNSTABLE = 'UNSTABLE';
    /** No revision history exists at all (a prediction that was never stored). */
    public const UNKNOWN = 'DATA_UNAVAILABLE';

    /** The movement trail is a diagnostic, not a record of record. */
    public const DISCLAIMER = 'Stability describes how far WINDELS\' own estimate has moved between re-reads of the same '
        . 'match. It says nothing about whether the prediction is right, and an unstable figure is still '
        . 'the best estimate the stored data supports.';

    public function __construct(private FootballRepository $repo, private FootballConfiguration $config) {}

    /**
     * Record one calculation of a fixture's prediction and measure it against the
     * reading before it. Idempotent per prediction id: re-storing the same
     * prediction (the same sweep running twice) does not add a second point.
     *
     * @param array<string,mixed> $prediction the row as stored
     * @param array<string,mixed> $fixture the fixture the prediction belongs to
     * @param list<string> $triggerCodes why this calculation happened, when it was a regeneration
     * @return array<string,mixed> the stability verdict attached to this revision
     */
    public function record(array $prediction, array $fixture, array $triggerCodes = [], string $kind = PredictionService::KIND_PRE_MATCH): array
    {
        $predictionId = (string) ($prediction['id'] ?? '');
        $fixtureId = (int) ($prediction['fixture_id'] ?? $fixture['id'] ?? 0);
        if ($predictionId === '' || $fixtureId <= 0) {
            return ['state' => self::UNKNOWN, 'reason' => 'The prediction has no stored identity, so no revision was recorded for it.'];
        }
        $previous = $this->previous($fixtureId, $predictionId, $kind);
        $measured = $this->compare($previous, $prediction, array_values(array_map('strval', $triggerCodes)));
        $stored = $this->repo->savePredictionRevision([
            'prediction_id' => $predictionId,
            'fixture_id' => $fixtureId,
            'provider_id' => $prediction['provider_id'] ?? ($fixture['provider_id'] ?? null),
            'model_version_id' => $prediction['model_version_id'] ?? null,
            'prediction_kind' => $kind,
            'probability_home' => $prediction['probability_home'] ?? null,
            'probability_draw' => $prediction['probability_draw'] ?? null,
            'probability_away' => $prediction['probability_away'] ?? null,
            'predicted_result' => $prediction['predicted_result'] ?? null,
            'predicted_home_score' => $prediction['predicted_home_score'] ?? null,
            'predicted_away_score' => $prediction['predicted_away_score'] ?? null,
            'confidence' => $prediction['confidence'] ?? null,
            'data_quality_score' => $prediction['data_quality_score'] ?? null,
            'data_quality_band' => $prediction['data_quality_band'] ?? null,
            'movement_points' => $measured['movementPoints'],
            'movement_selection' => $measured['movementSelection'],
            'previous_prediction_id' => $previous['prediction_id'] ?? null,
            'stability_state' => $measured['state'],
            'trigger_codes' => array_values(array_map('strval', $triggerCodes)),
            'kickoff_at' => $prediction['kickoff_at'] ?? ($fixture['kickoff_at'] ?? null),
            'recorded_at' => (string) ($prediction['generated_at'] ?? gmdate('c')),
        ]);
        return $measured + ['recorded' => (bool) ($stored['created'] ?? false), 'revisionId' => $stored['row']['id'] ?? null];
    }

    /**
     * The verdict for one stored prediction, read from the revision trail alone —
     * no provider call, no recomputation. This is what a board page asks for
     * every row it displays.
     *
     * @param array<int,list<array<string,mixed>>> $history revisions keyed by fixture id
     */
    public function read(array $prediction, array $history, ?array $previousOverride = null): array
    {
        $fixtureId = (int) ($prediction['fixture_id'] ?? 0);
        $rows = $previousOverride !== null ? [$previousOverride] : (array) ($history[$fixtureId] ?? []);
        if ($rows === []) {
            return $this->state(self::UNKNOWN, null, null,
                'No revision is stored for this prediction, so there is nothing to measure movement against. '
                    . 'This is reported as unknown, never as stable.');
        }
        $newest = $rows[0];
        if ((string) ($newest['prediction_id'] ?? '') === (string) ($prediction['id'] ?? '')) {
            // The newest revision *is* this prediction. Stability is its distance
            // from the one before it, which is the comparison the reader is
            // asking about.
            $newest = $rows[0];
            $previous = $rows[1] ?? null;
            $verdict = $this->compare($previous, $newest);
            $verdict['revisionCount'] = count($rows);
            $verdict['revisions'] = $this->trail($rows);
            return $verdict;
        }
        // The stored prediction has no revision of its own (it predates the
        // history table): compare it with the newest revision that does.
        $verdict = $this->compare($newest, $prediction);
        $verdict['revisionCount'] = count($rows);
        $verdict['revisions'] = $this->trail($rows);
        return $verdict;
    }

    /**
     * Measure one calculation against the calculation before it.
     *
     * @param array<string,mixed>|null $previous
     * @param array<string,mixed> $current
     * @param list<string> $triggerCodes why this calculation was made, when a
     *        regeneration policy had to justify it — the movement and its cause
     *        are published together so "it moved" is never left as a bare fact
     */
    public function compare(?array $previous, array $current, array $triggerCodes = []): array
    {
        if ($previous === null) {
            return $this->state(self::BASELINE, null, null,
                'This is the first stored reading of the match, so movement cannot be measured yet. '
                    . 'It will be reported as soon as a second calculation exists.', 0);
        }
        $thresholds = $this->config->stabilityThresholds();
        $selection = strtoupper((string) ($current['predicted_result'] ?? ''));
        $side = in_array($selection, ['HOME', 'DRAW', 'AWAY'], true) ? strtolower($selection) : null;
        $currentProbabilities = ['home' => $current['probability_home'] ?? null,
            'draw' => $current['probability_draw'] ?? null, 'away' => $current['probability_away'] ?? null];
        $previousProbabilities = ['home' => $previous['probability_home'] ?? null,
            'draw' => $previous['probability_draw'] ?? null, 'away' => $previous['probability_away'] ?? null];
        $moves = [];
        foreach (['home', 'draw', 'away'] as $leg) {
            $now = $currentProbabilities[$leg];
            $was = $previousProbabilities[$leg];
            if (!is_numeric($now) || !is_numeric($was)) continue;
            $moves[$leg] = round(((float) $now - (float) $was) * 100.0, 2);
        }
        if ($moves === []) {
            return $this->state(self::UNKNOWN, null, null,
                'Either reading is missing a probability, so no movement can be measured between them.');
        }
        // The headline figure is the selection's own move; the largest of the
        // three is published beside it, because a swing that lands on an outcome
        // nobody selected still says the model is not settled.
        $own = $side !== null && isset($moves[$side]) ? $moves[$side] : null;
        $largestSide = '';
        foreach ($moves as $leg => $value) {
            if ($largestSide === '' || abs((float) $value) > abs((float) $moves[$largestSide])) $largestSide = (string) $leg;
        }
        $movement = $own ?? $moves[$largestSide];
        $absolute = abs($movement);
        $state = $absolute >= $thresholds['unstable'] * 100.0 ? self::UNSTABLE
            : ($absolute >= $thresholds['moved'] * 100.0 ? self::MOVED : self::STABLE);
        // A reading that picks a different outcome than the one before it is not a
        // stable reading, however small the arithmetic move: the recommendation
        // itself changed. One point of movement on a flipped selection is worth
        // saying out loud, so it is escalated to MOVED — and stays below UNSTABLE
        // only because the size of the movement, not the flip, sets that level.
        $flipped = strtoupper((string) ($previous['predicted_result'] ?? '')) !== ''
            && $selection !== '' && strtoupper((string) ($previous['predicted_result'] ?? '')) !== $selection;
        if ($flipped && $state === self::STABLE) $state = self::MOVED;
        $reason = match ($state) {
            self::UNSTABLE => 'WINDELS\' estimate for ' . ($selection !== '' ? self::resultWord($selection) : 'this match')
                . ' moved ' . $this->signed($movement) . ' points between the two stored readings — past the '
                . round($thresholds['unstable'] * 100, 1) . '-point line. The prediction is unstable: significant '
                . 'model movement, and a reader should expect it to change again before kickoff.',
            self::MOVED => 'WINDELS\' estimate for ' . ($selection !== '' ? self::resultWord($selection) : 'this match')
                . ' moved ' . $this->signed($movement) . ' points between the two stored readings, past the '
                . round($thresholds['moved'] * 100, 1) . '-point notice line but short of the '
                . round($thresholds['unstable'] * 100, 1) . '-point instability line.',
            default => 'WINDELS\' estimate for ' . ($selection !== '' ? self::resultWord($selection) : 'this match')
                . ' moved ' . $this->signed($movement) . ' points between the two stored readings — inside the '
                . round($thresholds['moved'] * 100, 1) . '-point band that counts as the same reading.',
        };
        // A movement measured across a model-version change is not the same fact as
        // a movement in the data. Both are worth telling the reader, but only one
        // of them is a warning about this match, so the version is checked and the
        // reason says which kind of movement it is describing.
        $previousVersion = (int) ($previous['model_version_id'] ?? 0);
        $currentVersion = (int) ($current['model_version_id'] ?? 0);
        $modelChanged = $previousVersion > 0 && $currentVersion > 0 && $previousVersion !== $currentVersion;
        $resultChanged = $flipped;
        if ($modelChanged) {
            $reason .= ' The two readings also come from different model versions (v' . $previousVersion . ' → v'
                . $currentVersion . '), so part or all of this movement is the model changing, not the match.';
            if ($state === self::STABLE) $state = self::MOVED;
        }
        if ($resultChanged) {
            $reason .= ' The recommended outcome also changed: '
                . self::resultWord((string) $previous['predicted_result']) . ' → ' . self::resultWord($selection) . '.';
        }
        return [
            'state' => $state,
            'label' => self::label($state),
            // In percentage points, signed: positive means the model raised its
            // estimate of this outcome since the last reading.
            'movementPoints' => $movement,
            'movementSelection' => $selection !== '' ? $selection : null,
            'largestMovementPoints' => abs((float) $moves[$largestSide]),
            'largestMovementSide' => $largestSide,
            'probabilities' => ['current' => $currentProbabilities, 'previous' => $previousProbabilities,
                'movement' => $moves],
            'resultChanged' => $resultChanged,
            'modelChanged' => $modelChanged,
            'modelVersions' => ['previous' => $previousVersion > 0 ? $previousVersion : null,
                'current' => $currentVersion > 0 ? $currentVersion : null],
            'previousRecordedAt' => $previous['recorded_at'] ?? null,
            'previousPredictionId' => (string) ($previous['prediction_id'] ?? ''),
            'thresholds' => ['movedPoints' => round($thresholds['moved'] * 100, 2),
                'unstablePoints' => round($thresholds['unstable'] * 100, 2)],
            'triggerCodes' => $triggerCodes !== [] ? $triggerCodes : array_values((array) ($current['trigger_codes'] ?? [])),
            'reason' => $reason,
            'disclaimer' => self::DISCLAIMER,
            'revisionCount' => 2,
        ];
    }

    /**
     * The revisions of a whole page in one read, so a fifty-match board costs one
     * query rather than fifty — the same rule the score grids and the prices
     * follow.
     *
     * @param list<int> $fixtureIds
     * @return array<int,list<array<string,mixed>>>
     */
    public function history(array $fixtureIds, string $kind = PredictionService::KIND_PRE_MATCH, int $limitPerFixture = 5): array
    {
        return $this->repo->listPredictionRevisions($fixtureIds, $kind, $limitPerFixture);
    }

    /**
     * The trail as a reader sees it: each stored re-reading with the figure it
     * replaced and the cause that authorised it. Published next to the verdict so
     * a banner like "prediction unstable" can always be opened and checked — the
     * verdict is a summary of these rows, never a replacement for them.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function trail(array $rows): array
    {
        $trail = [];
        foreach ($rows as $index => $row) {
            $selection = strtoupper((string) ($row['predicted_result'] ?? ''));
            $side = in_array($selection, ['HOME', 'DRAW', 'AWAY'], true) ? strtolower($selection) : null;
            $own = $side === null ? null : (is_numeric($row['probability_' . $side] ?? null)
                ? (float) $row['probability_' . $side] : null);
            $movement = is_numeric($row['movement_points'] ?? null) ? (float) $row['movement_points'] : null;
            // The revision row stores its own three probabilities and the movement
            // it measured, not a copy of the previous row. So "was" is stated only
            // where the arithmetic is exact — when the movement was measured on
            // this same selection — and left null otherwise.
            $exact = $movement !== null && (string) ($row['movement_selection'] ?? '') === $selection;
            $trail[] = [
                'revision' => count($rows) - $index,
                'recordedAt' => (string) ($row['recorded_at'] ?? ''),
                'selection' => $selection !== '' ? $selection : null,
                'probability' => $own,
                'previousProbability' => $exact && $own !== null ? round($own - $movement / 100.0, 6) : null,
                'movementPoints' => $movement,
                'movementSelection' => (string) ($row['movement_selection'] ?? '') !== '' ? (string) $row['movement_selection'] : null,
                'state' => (string) ($row['stability_state'] ?? ''),
                'confidence' => is_numeric($row['confidence'] ?? null) ? (float) $row['confidence'] : null,
                'dataQuality' => is_numeric($row['data_quality_score'] ?? null) ? (int) $row['data_quality_score'] : null,
                'triggerCodes' => array_values((array) ($row['trigger_codes'] ?? [])),
                'fromPredictionId' => (string) ($row['previous_prediction_id'] ?? ''),
            ];
        }
        return $trail;
    }

    /** The reading before this one, for the same fixture — and never this one itself. */
    private function previous(int $fixtureId, string $predictionId, string $kind): ?array
    {
        foreach ($this->repo->listPredictionRevisions([$fixtureId], $kind, 5) as $row) {
            if ((string) ($row['prediction_id'] ?? '') === $predictionId) continue;
            return $row;
        }
        return null;
    }

    /** @return array<string,mixed> */
    private function state(string $state, ?float $movement, ?string $selection, string $reason, int $revisions = 1): array
    {
        return ['state' => $state, 'label' => self::label($state), 'movementPoints' => $movement,
            'movementSelection' => $selection, 'resultChanged' => false, 'reason' => $reason,
            'disclaimer' => self::DISCLAIMER, 'revisionCount' => $revisions];
    }

    /**
     * A regeneration code in the words a reader uses.
     *
     * The codes themselves stay the payload's contract — they are the same strings
     * `RegenerationPolicy` decided on and the same ones the sync log carries — but
     * a page that prints `MAJOR_INJURY_OR_NEWS` is asking the reader to learn a
     * vocabulary to understand a warning. Unknown codes fall through unchanged, so
     * a code added later is shown rather than swallowed.
     */
    public static function triggerLabel(string $code): string
    {
        return match (strtoupper($code)) {
            RegenerationPolicy::R_MODEL_VERSION => 'the model version changed',
            RegenerationPolicy::R_EXPIRED => 'the previous reading passed its validity window',
            RegenerationPolicy::R_ODDS_MOVEMENT => 'the market price moved',
            RegenerationPolicy::R_LINEUP => 'a confirmed lineup change',
            RegenerationPolicy::R_INJURY => 'injury or team news',
            RegenerationPolicy::R_STATUS => 'the match status changed',
            RegenerationPolicy::R_STATISTICS => 'new statistics arrived',
            RegenerationPolicy::R_FROZEN => 'kickoff',
            default => $code,
        };
    }

    /** @param list<string> $codes @return list<string> */
    public static function triggerLabels(array $codes): array
    {
        return array_values(array_map([self::class, 'triggerLabel'], array_values($codes)));
    }

    public static function label(string $state): string
    {
        return match (strtoupper($state)) {
            self::STABLE => 'Stable',
            self::MOVED => 'Moved',
            self::UNSTABLE => 'Unstable',
            self::BASELINE => 'First reading',
            default => 'No history',
        };
    }

    /** Is this state a warning a reader must see? `MOVED` is a notice, not an alarm. */
    public static function isWarning(string $state): bool
    {
        return in_array(strtoupper($state), [self::UNSTABLE, self::MOVED], true);
    }

    private static function resultWord(string $result): string
    {
        return match (strtoupper($result)) {
            'HOME' => 'the home win',
            'AWAY' => 'the away win',
            'DRAW' => 'the draw',
            default => strtoupper($result),
        };
    }

    private function signed(float $value): string
    {
        return ($value >= 0 ? '+' : '') . round($value, 2);
    }
}
