<?php
namespace AIWorkforce\Football;

use AIWorkforce\Persistence\FootballRepository;

/**
 * Today's football predictions board.
 *
 * Replaces the ticket-centric screen: a date, a summary of what was found and
 * analyzed, and the predictions grouped by confidence tier. Categories are
 * allowed to be empty — nothing is moved up a tier, and nothing is invented to
 * fill a heading.
 */
final class PredictionBoard
{
    public const EMPTY_QUALIFIERS = 'No fixtures currently satisfy the required prediction and data-quality thresholds.';
    public const NO_PROVIDER = 'Football data provider not connected. Live fixtures and predictions are unavailable until a verified data source is configured.';

    public function __construct(
        private FootballRepository $repo,
        private PredictionService $predictions,
        private ModelRegistry $models,
        private FootballConfiguration $config,
        private ?MatchFeed $feed = null,
    ) {
        $this->feed ??= new MatchFeed($repo, $predictions, $models, $config);
    }

    /**
     * The board for one page of a date: 50 matches at a time, read from the
     * rows that are already stored.
     *
     * Pagination is the difference between "the board" and "the feed": the
     * summary counts describe the *whole* date (they are `COUNT`s, not the
     * length of a page), while the cards are the page's matches only. `refresh`
     * generates at most one page's worth of missing predictions — it never
     * rebuilds matches that already have one.
     *
     * @return array{heading:string, date:string, dateLabel:string, status:string, state:string,
     *               summary:array<string,int>, categories:list<array>, emptyReason:?string,
     *               message:?string, model:array, performance:array, generatedAt:string,
     *               pagination:array<string,mixed>}
     */
    public function forDate(string $date, bool $refresh = false, int $page = 1, int $limit = MatchFeed::MAX_PAGE_SIZE): array
    {
        $date = $this->validDate($date);
        $notes = [];
        [$page, $limit] = $this->feed->resolve($page, $limit, $notes);

        $filter = ['date' => $date];
        $totalFixtures = $this->repo->countFixtures($filter);
        $totalPages = max(1, (int) ceil($totalFixtures / $limit));
        $fixtures = $this->repo->listFixtures($filter, $limit, ($page - 1) * $limit);
        if ($page > $totalPages && $totalFixtures > 0) {
            $notes[] = 'page=' . $page . ' is past the last page (' . $totalPages . '); no matches were returned.';
        }

        $model = $this->models->usable();
        $modelVersionId = (int) ($model['model']['id'] ?? 0);
        // One query answers "which of these 50 matches already exist?" — the
        // lookups below are then answered from memory.
        $this->predictions->prime(
            array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $fixtures),
            $modelVersionId,
            PredictionService::KIND_PRE_MATCH,
        );
        if ($refresh) {
            // Bounded to the page on screen: at most `limit` NEW predictions,
            // and only for matches that have none.
            $this->predictions->predictMissing($fixtures, $limit, PredictionService::KIND_PRE_MATCH);
        }

        // Date-wide counts, so the panel describes the day while the page shows
        // 50 rows. `eligibility` stores the band the prediction was written with.
        $dateWide = ['date' => $date, 'kind' => PredictionService::KIND_PRE_MATCH];
        $analyzed = $this->repo->countPredictions($dateWide);
        $qualified = $this->repo->countPredictions($dateWide + ['eligibility' => QualityBand::QUALIFIED]);
        $limited = $this->repo->countPredictions($dateWide + ['eligibility' => QualityBand::LIMITED]);
        $counts = ['fixtures' => $totalFixtures, 'analyzed' => $analyzed, 'qualified' => $qualified, 'limited' => $limited,
            'rejected' => max(0, $analyzed - $qualified - $limited)];

        $cards = [];
        $onPagePredicted = 0;
        foreach ($fixtures as $fixture) {
            $row = $this->predictions->existing($fixture, $modelVersionId, PredictionService::KIND_PRE_MATCH);
            if ($row === null) continue;
            $onPagePredicted++;
            $cards[] = $this->card($row, $fixture, $model);
        }
        usort($cards, static fn(array $a, array $b) => [$b['confidence'], $b['dataQuality']['score']] <=> [$a['confidence'], $a['dataQuality']['score']]);
        $tiers = $this->config->confidenceTiers();
        $lowest = 70.0;
        foreach ($tiers as $tier) $lowest = min($lowest, (float) ($tier['min'] ?? 70));
        // Every card lands in exactly one category — including a card whose data
        // quality cleared the threshold while its confidence sits below the
        // lowest tier. Such a card is reported, not dropped: paging through a
        // long day must never make an analyzed match disappear from the board.
        $categories = [];
        $placed = [];
        foreach ($tiers as $tier) {
            $min = (float) ($tier['min'] ?? 0);
            $max = (float) ($tier['max'] ?? 100);
            $items = [];
            foreach ($cards as $index => $card) {
                if ($card['band'] === QualityBand::QUALIFIED && $card['confidence'] !== null
                    && $card['confidence'] >= $min && $card['confidence'] <= $max) {
                    $items[] = $card;
                    $placed[$index] = true;
                }
            }
            $categories[] = [
                'key' => (string) $tier['key'],
                'label' => (string) $tier['label'],
                'range' => $min . '–' . ($max >= 100 ? '100' : number_format($max, 0)),
                'min' => $min,
                'items' => $items,
            ];
        }
        $belowTiers = [];
        foreach ($cards as $index => $card) {
            if (!isset($placed[$index])) $belowTiers[] = $card;
        }
        $categories[] = [
            'key' => 'limitedData',
            'label' => 'Limited Data',
            'range' => 'below ' . number_format($lowest, 0),
            'min' => 0.0,
            'items' => $belowTiers,
        ];
        $qualified = count($categories[0]['items']) + count($categories[1]['items']) + count($categories[2]['items']);
        $emptyReason = null;
        $message = null;
        if ($totalFixtures === 0) {
            $emptyReason = 'NO_FIXTURES_STORED';
            $message = 'No fixture has been stored for ' . $date . '. This is a data-availability state, not a prediction result: the module will not name matches it has not received.';
        } elseif ($fixtures === []) {
            $emptyReason = 'PAGE_BEYOND_LAST';
            $message = 'Page ' . $page . ' is past the last page of ' . $totalPages . ' for ' . $date . '. Nothing is generated for a page that holds no match.';
        } elseif ($cards === []) {
            $emptyReason = 'NO_PREDICTIONS_STORED';
            $message = $analyzed === 0
                ? 'Fixtures are stored for ' . $date . ' but no prediction row exists yet. Generating this page creates at most ' . MatchFeed::MAX_PAGE_SIZE . ' new predictions.'
                : 'No match on page ' . $page . ' has a stored prediction yet. The other pages of this date do — generating this page analyzes only these ' . count($fixtures) . ' matches.';
        } elseif ($qualified === 0) {
            $emptyReason = 'NONE_QUALIFIED';
            $message = self::EMPTY_QUALIFIERS;
        }
        $first = $totalFixtures === 0 ? 0 : (($page - 1) * $limit) + 1;
        return [
            'heading' => "TODAY'S FOOTBALL PREDICTIONS",
            'date' => $date,
            'dateLabel' => $date === gmdate('Y-m-d') ? 'Today' : gmdate('l, j F Y', (int) strtotime($date . 'T00:00:00+00:00')),
            'status' => $cards === [] ? DataState::UNAVAILABLE : 'OK',
            'state' => $emptyReason ?? 'POPULATED',
            'summary' => $counts,
            'categories' => $categories,
            'cards' => $cards,
            'emptyReason' => $emptyReason,
            'message' => $message,
            'model' => [
                'state' => (string) $model['state'],
                'label' => (string) $model['label'],
                'version' => $model['model']['model_version'] ?? null,
                'note' => $model['reason'],
                'highConfidenceAllowed' => (bool) $model['highConfidenceAllowed'],
            ],
            'thresholds' => ['dataQualityQualified' => QualityBand::QUALIFIED_MIN, 'dataQualityLimited' => QualityBand::LIMITED_MIN,
                'tiers' => $tiers, 'calibrationMinimum' => $this->config->minCalibrationSamples()],
            // Pagination over the persisted matches. The pager moves between
            // pages of stored rows; it never re-generates what a previous page
            // already produced.
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'pageSize' => $limit,
                'maxLimit' => MatchFeed::MAX_PAGE_SIZE,
                'totalMatches' => $totalFixtures,
                'totalPages' => $totalPages,
                'returned' => count($fixtures),
                'predicted' => $onPagePredicted,
                'awaiting' => count($fixtures) - $onPagePredicted,
                'from' => count($fixtures) === 0 ? 0 : $first,
                'to' => count($fixtures) === 0 ? 0 : $first + count($fixtures) - 1,
                'hasPrevious' => $page > 1 && $totalFixtures > 0,
                'hasNext' => $page < $totalPages,
                'previousPage' => $page > 1 ? $page - 1 : null,
                'nextPage' => $page < $totalPages ? $page + 1 : null,
                'firstPage' => 1,
                'lastPage' => $totalPages,
            ],
            'request' => ['date' => $date, 'page' => $page, 'limit' => $limit, 'refresh' => $refresh, 'notes' => array_values($notes)],
            'generatedAt' => gmdate('c'),
        ];
    }

    /**
     * Match card (§11) — one flat structure the view and the API both render, so
     * a number can never appear in one place and not the other.
     */
    public function card(array $prediction, array $fixture, ?array $model = null): array
    {
        $model ??= $this->models->usable();
        $evidence = is_array($prediction['evidence'] ?? null) ? $prediction['evidence'] : json_decode((string) ($prediction['evidence'] ?? '[]'), true);
        $evidence = is_array($evidence) ? $evidence : [];
        $snapshot = is_array($prediction['feature_snapshot'] ?? null) ? $prediction['feature_snapshot'] : json_decode((string) ($prediction['feature_snapshot'] ?? '{}'), true);
        $snapshot = is_array($snapshot) ? $snapshot : [];
        $alternatives = is_array($prediction['alternative_scores'] ?? null) ? $prediction['alternative_scores'] : json_decode((string) ($prediction['alternative_scores'] ?? '[]'), true);
        $matrix = is_array($prediction['probabilities_matrix'] ?? null) ? $prediction['probabilities_matrix'] : json_decode((string) ($prediction['probabilities_matrix'] ?? '{}'), true);
        $bySide = [];
        foreach ($evidence as $row) {
            if (($row['kind'] ?? '') === 'TEAM_FORM') $bySide[(string) ($row['side'] ?? '')] = $row;
        }
        $headToHead = null;
        foreach ($evidence as $row) {
            if (($row['kind'] ?? '') === 'HEAD_TO_HEAD') $headToHead = $row;
        }
        $kickoff = (string) ($fixture['kickoff_at'] ?? '');
        $confidence = is_numeric($prediction['confidence'] ?? null) ? round((float) $prediction['confidence'], 1) : null;
        $band = (string) ($prediction['data_quality_band'] ?? QualityBand::REJECTED);
        $tiers = $this->config->confidenceTiers();
        $tierLabel = 'Limited Data';
        foreach ($tiers as $tier) {
            if ($band === QualityBand::QUALIFIED && $confidence !== null && $confidence >= (float) ($tier['min'] ?? 0) && $confidence <= (float) ($tier['max'] ?? 100)) {
                $tierLabel = (string) $tier['label'];
                break;
            }
        }
        $calibrated = (string) ($prediction['calibration_state'] ?? CalibrationService::PENDING) === CalibrationService::CALIBRATED;
        return [
            'predictionId' => (string) ($prediction['id'] ?? ''),
            'fixtureId' => (int) ($fixture['id'] ?? $prediction['fixture_id'] ?? 0),
            'externalId' => (string) ($fixture['external_id'] ?? ''),
            'competition' => (string) ($fixture['competition'] ?? DataState::UNAVAILABLE),
            'country' => $fixture['country'] ?? null,
            'kickoff' => $kickoff !== '' ? $kickoff : null,
            'kickoffLabel' => $kickoff !== '' ? gmdate('H:i', (int) strtotime($kickoff)) . ' UTC' : DataState::UNAVAILABLE,
            'status' => (string) ($fixture['status'] ?? 'UNKNOWN'),
            'matchState' => (string) ($fixture['match_state'] ?? 'PRE_MATCH'),
            'minute' => $fixture['minute'] ?? null,
            'homeTeam' => (string) ($fixture['home_team'] ?? DataState::UNAVAILABLE),
            'awayTeam' => (string) ($fixture['away_team'] ?? DataState::UNAVAILABLE),
            'score' => (isset($fixture['home_score'], $fixture['away_score']) && $fixture['home_score'] !== null)
                ? ['home' => (int) $fixture['home_score'], 'away' => (int) $fixture['away_score']] : null,
            'band' => $band,
            'predictedResult' => (string) ($prediction['predicted_result'] ?? ''),
            'predictedResultLabel' => self::resultLabel($prediction, $fixture),
            'predictedScore' => (isset($prediction['predicted_home_score'], $prediction['predicted_away_score']))
                ? ['home' => (int) $prediction['predicted_home_score'], 'away' => (int) $prediction['predicted_away_score'],
                    'label' => (int) $prediction['predicted_home_score'] . '–' . (int) $prediction['predicted_away_score']]
                : null,
            'probabilities' => ['home' => $prediction['probability_home'] ?? null, 'draw' => $prediction['probability_draw'] ?? null, 'away' => $prediction['probability_away'] ?? null],
            'rawProbabilities' => ['home' => $prediction['raw_home'] ?? null, 'draw' => $prediction['raw_draw'] ?? null, 'away' => $prediction['raw_away'] ?? null],
            'confidence' => $confidence,
            'confidenceBasis' => (string) ($prediction['confidence_basis'] ?? 'RAW'),
            'confidenceLabel' => $confidence === null ? DataState::UNAVAILABLE
                : ($calibrated ? number_format($confidence, 1) . '%' : number_format($confidence, 1) . '% (uncalibrated)'),
            'tier' => $tierLabel,
            'highConfidence' => $band === QualityBand::QUALIFIED && $calibrated && $confidence !== null && $confidence >= (float) ($tiers[0]['min'] ?? 80) ? 'HIGH_CONFIDENCE' : null,
            'expectedTotalGoals' => $prediction['expected_total_goals'] ?? null,
            'alternativeScores' => is_array($alternatives) ? array_slice($alternatives, 0, 3) : [],
            'matrixRows' => is_array($matrix['rows'] ?? null) ? array_slice($matrix['rows'], 0, 4) : [],
            'dataQuality' => ['score' => (int) ($prediction['data_quality_score'] ?? 0), 'status' => $band,
                'components' => is_array($prediction['quality_components'] ?? null) ? $prediction['quality_components'] : json_decode((string) ($prediction['quality_components'] ?? '{}'), true)],
            'form' => ['home' => $bySide['HOME'] ?? null, 'away' => $bySide['AWAY'] ?? null],
            'goalTrend' => [
                'home' => self::trend($snapshot['teams']['HOME'] ?? []),
                'away' => self::trend($snapshot['teams']['AWAY'] ?? []),
            ],
            'headToHead' => $headToHead,
            'model' => [
                'version' => (string) ($model['model']['model_version'] ?? DataState::UNAVAILABLE),
                'modelVersionId' => (int) ($prediction['model_version_id'] ?? 0) ?: null,
                'status' => (string) ($model['status'] ?? $model['state'] ?? ModelRegistry::DRAFT),
                'calibrationVersion' => (string) ($prediction['calibration_version'] ?? '') !== '' ? (string) $prediction['calibration_version'] : null,
                'calibrationState' => (string) ($prediction['calibration_state'] ?? CalibrationService::PENDING),
                'featureVersion' => (string) ($model['model']['feature_version'] ?? DataState::UNAVAILABLE),
            ],
            'reason' => (string) ($prediction['reason'] ?? ''),
            'settlementState' => (string) ($prediction['settlement_state'] ?? 'OPEN'),
            'generatedAt' => (string) ($prediction['generated_at'] ?? ''),
            'fixtureDataState' => (string) ($fixture['data_state'] ?? DataState::UNAVAILABLE),
        ];
    }

    /**
     * Fixtures stored for a date with whatever the module knows about them — the
     * "real data per date" list, including matches with no prediction.
     *
     * @return list<array<string,mixed>>
     */
    public function fixtures(string $date, ?string $status = null): array
    {
        $date = $this->validDate($date);
        $filter = ['date' => $date];
        if ($status !== null && $status !== '') $filter['status'] = strtoupper($status);
        $rows = $this->repo->listFixtures($filter, max(1, $this->config->analysisLimit()));
        $out = [];
        foreach ($rows as $fixture) {
            $prediction = $this->repo->listPredictions(['fixtureId' => (int) $fixture['id'], 'kind' => PredictionService::KIND_PRE_MATCH], 1)[0] ?? null;
            $coverage = is_array($fixture['coverage'] ?? null) ? $fixture['coverage'] : [];
            $out[] = [
                'id' => (int) $fixture['id'],
                'externalId' => (string) ($fixture['external_id'] ?? ''),
                'competition' => (string) ($fixture['competition'] ?? DataState::UNAVAILABLE),
                'country' => $fixture['country'] ?? DataState::UNAVAILABLE,
                'season' => $fixture['season'] ?? null,
                'kickoff' => $fixture['kickoff_at'] ?? null,
                'status' => (string) ($fixture['status'] ?? 'UNKNOWN'),
                'matchState' => (string) ($fixture['match_state'] ?? 'PRE_MATCH'),
                'minute' => $fixture['minute'] ?? null,
                'homeTeam' => (string) ($fixture['home_team'] ?? DataState::UNAVAILABLE),
                'awayTeam' => (string) ($fixture['away_team'] ?? DataState::UNAVAILABLE),
                'score' => (isset($fixture['home_score'], $fixture['away_score']) && $fixture['home_score'] !== null)
                    ? ['home' => (int) $fixture['home_score'], 'away' => (int) $fixture['away_score']] : null,
                'redCards' => ['home' => $fixture['home_red_cards'] ?? null, 'away' => $fixture['away_red_cards'] ?? null],
                'venue' => $fixture['venue'] ?? null,
                'dataState' => (string) ($fixture['data_state'] ?? DataState::UNAVAILABLE),
                'coverage' => $coverage,
                'sourceTimestamp' => $fixture['source_timestamp'] ?? null,
                'prediction' => $prediction === null ? null : ['id' => $prediction['id'], 'result' => $prediction['predicted_result'],
                    'score' => ['home' => $prediction['predicted_home_score'], 'away' => $prediction['predicted_away_score']],
                    'confidence' => $prediction['confidence'], 'confidenceBasis' => $prediction['confidence_basis'],
                    'calibrationState' => $prediction['calibration_state'], 'dataQuality' => $prediction['data_quality_score'],
                    'band' => $prediction['data_quality_band'], 'settlementState' => $prediction['settlement_state']],
                'analysisState' => $prediction === null ? 'NOT_ANALYZED' : 'ANALYZED',
            ];
        }
        return $out;
    }

    private static function resultLabel(array $prediction, array $fixture): string
    {
        return match (strtoupper((string) ($prediction['predicted_result'] ?? ''))) {
            'HOME' => (string) ($fixture['home_team'] ?? 'Home') . ' Win',
            'AWAY' => (string) ($fixture['away_team'] ?? 'Away') . ' Win',
            'DRAW' => 'Draw',
            default => DataState::UNAVAILABLE,
        };
    }

    /** Goal-trend lines for the card, read back from the stored feature snapshot. */
    private static function trend(array $team): array
    {
        return [
            'scored' => $team['attackStrength'] ?? DataState::UNAVAILABLE,
            'conceded' => $team['defenseWeakness'] ?? DataState::UNAVAILABLE,
            'scoredSource' => $team['attackSource'] ?? DataState::UNAVAILABLE,
            'concededSource' => $team['defenseSource'] ?? DataState::UNAVAILABLE,
            'cleanSheetRate' => $team['cleanSheetRate'] ?? DataState::UNAVAILABLE,
            'failedToScoreRate' => $team['failedToScoreRate'] ?? DataState::UNAVAILABLE,
            'tendency' => $team['expectedGoalsTendency'] ?? DataState::UNAVAILABLE,
        ];
    }

    private function validDate(string $date): string
    {
        $matches = [];
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches) && checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            return $date;
        }
        return gmdate('Y-m-d');
    }
}
