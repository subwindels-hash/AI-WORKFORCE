<?php
namespace AIWorkforce\Football;

use AIWorkforce\Persistence\FootballRepository;

/**
 * The Odds Prediction Ticket: the structured, per-match read model an operator
 * prints, shares or checks. Every entry is a stored, quality-gated prediction
 * rendered in the ticket layout the console and the JSON API share:
 *
 *   match · league · home · away · outcome · category · H/D/A probabilities ·
 *   predicted score · expected goals · confidence score
 *
 * The ticket never adds a match the board does not already carry, and an empty
 * category is a valid outcome — the ticket says "none in this category" rather
 * than promoting a borderline fixture into one. League scope (admin config)
 * and the A/B/C filter are display filters: they narrow what is shown, never
 * what was predicted.
 */
final class TicketService
{
    public const DISCLAIMER = 'Probabilities are statistical estimates from stored provider data. No prediction is a guaranteed outcome.';

    public function __construct(
        private FootballRepository $repo,
        private PredictionBoard $board,
        private ModelRegistry $models,
        private FootballConfiguration $config,
    ) {}

    /**
     * @param string|null $category  A | B | C | null (all)
     * @param list<string>|null $leagueScope explicit scope; null = the configured one
     * @return array{state:string, date:string, dateLabel:string, categoryFilter:?string,
     *               leagueScope:list<string>, summary:array, entries:list<array>,
     *               disclaimer:string, model:array, message:?string, generatedAt:string}
     */
    public function ticket(string $date, ?string $category = null, ?array $leagueScope = null): array
    {
        $valid = true;
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            $date = gmdate('Y-m-d');
            $valid = false;
        }
        $filterKey = $category === null ? null : (in_array(strtoupper($category), CategoryClassifier::KEYS, true) ? strtoupper($category) : null);
        $scope = $leagueScope === null ? $this->config->leagueScope() : array_values(array_filter(array_map('strval', $leagueScope)));
        $classifier = $this->board->categories();
        $rows = $this->repo->listPredictions(['date' => $date, 'kind' => PredictionService::KIND_PRE_MATCH], max(1, $this->config->analysisLimit()));
        $fixtures = $this->repo->listFixtures(['date' => $date], max(1, $this->config->analysisLimit()));
        $model = $this->models->usable();

        $entries = [];
        $counts = ['A' => 0, 'B' => 0, 'C' => 0, 'UNCLASSIFIED' => 0, 'outsideScope' => 0, 'filteredOut' => 0];
        $confidence = [];
        foreach ($rows as $row) {
            $fixture = $this->repo->findFixtureById((int) $row['fixture_id']) ?? [];
            if (!$this->inScope($fixture, $scope)) {
                $counts['outsideScope']++;
                continue;
            }
            $card = $this->board->card($row, $fixture, $model);
            $key = $card['category'] ?? null;
            $counts[$key ?? 'UNCLASSIFIED'] = ($counts[$key ?? 'UNCLASSIFIED'] ?? 0) + 1;
            if ($card['confidence'] !== null) $confidence[] = (float) $card['confidence'];
            if ($filterKey !== null && $key !== $filterKey) {
                $counts['filteredOut']++;
                continue;
            }
            $entries[] = [
                'entryNumber' => count($entries) + 1,
                'fixtureId' => (int) ($card['fixtureId'] ?? 0),
                'match' => (string) ($card['homeTeam'] ?? DataState::UNAVAILABLE) . ' vs ' . (string) ($card['awayTeam'] ?? DataState::UNAVAILABLE),
                'league' => (string) ($card['competition'] ?? DataState::UNAVAILABLE),
                'country' => $card['country'] ?? null,
                'kickoff' => $card['kickoff'] ?? null,
                'kickoffLabel' => $card['kickoffLabel'] ?? DataState::UNAVAILABLE,
                'status' => (string) ($card['status'] ?? 'UNKNOWN'),
                'matchState' => (string) ($card['matchState'] ?? 'PRE_MATCH'),
                'homeTeam' => (string) ($card['homeTeam'] ?? DataState::UNAVAILABLE),
                'awayTeam' => (string) ($card['awayTeam'] ?? DataState::UNAVAILABLE),
                'predictedOutcome' => (string) ($card['predictedResultLabel'] ?? DataState::UNAVAILABLE),
                'predictedResult' => (string) ($card['predictedResult'] ?? ''),
                'category' => $key,
                'categoryLabel' => (string) ($card['categoryLabel'] ?? 'UNCLASSIFIED'),
                'probabilities' => [
                    'home' => $card['probabilities']['home'] ?? null,
                    'draw' => $card['probabilities']['draw'] ?? null,
                    'away' => $card['probabilities']['away'] ?? null,
                ],
                'predictedScore' => $card['predictedScore'] ?? null,
                'expectedGoals' => [
                    'home' => $card['expectedGoals']['home'] ?? null,
                    'away' => $card['expectedGoals']['away'] ?? null,
                    'total' => $card['expectedTotalGoals'] ?? null,
                    'method' => $card['expectedGoals']['method'] ?? null,
                ],
                'alternativeScores' => array_slice((array) ($card['alternativeScores'] ?? []), 0, 3),
                'confidence' => $card['confidence'] ?? null,
                'confidenceLabel' => (string) ($card['confidenceLabel'] ?? DataState::UNAVAILABLE),
                'confidenceBasis' => (string) ($card['confidenceBasis'] ?? 'RAW'),
                'dataQuality' => $card['dataQuality'] ?? ['score' => 0, 'status' => QualityBand::REJECTED],
                'band' => (string) ($card['band'] ?? QualityBand::REJECTED),
                'settlementState' => (string) ($card['settlementState'] ?? 'OPEN'),
                'modelVersion' => (string) ($card['model']['version'] ?? DataState::UNAVAILABLE),
            ];
        }
        // Ticket order is the fixture order, not a confidence ranking: an
        // operator reading top-to-bottom follows the day, not a leaderboard.
        usort($entries, static fn(array $a, array $b) => strcmp((string) ($a['kickoff'] ?? ''), (string) ($b['kickoff'] ?? '')));
        foreach (array_keys($entries) as $index) $entries[$index]['entryNumber'] = $index + 1;

        $state = 'POPULATED';
        $message = null;
        if ($valid === false) {
            $state = 'INVALID_DATE';
            $message = 'The requested date could not be read as YYYY-MM-DD; the ticket shows today (' . $date . ') instead.';
        } elseif ($fixtures === []) {
            $state = 'NO_FIXTURES_STORED';
            $message = 'No fixture has been stored for ' . $date . '. The ticket shows no matches — nothing is invented to fill a blank day.';
        } elseif ($rows === []) {
            $state = 'NO_PREDICTIONS_STORED';
            $message = 'Fixtures are stored for ' . $date . ' but none has been analyzed yet. Run the analysis (or wait for the scheduled job) and the ticket will populate.';
        } elseif ($entries === []) {
            $state = 'NONE_MATCH_FILTER';
            $message = $filterKey !== null
                ? 'No prediction for ' . $date . ' carries category ' . $filterKey . ' under the current classification rules.'
                : 'No prediction for ' . $date . ' falls inside the configured league scope.';
        }
        $classifierRules = $classifier->rules();
        return [
            'state' => $state,
            'date' => $date,
            'dateLabel' => $date === gmdate('Y-m-d') ? 'Today' : gmdate('l, j F Y', (int) strtotime($date . 'T00:00:00+00:00')),
            'categoryFilter' => $filterKey,
            'leagueScope' => $scope,
            'summary' => [
                'fixtures' => count($fixtures),
                'predicted' => count($rows),
                'entries' => count($entries),
                'byCategory' => ['A' => $counts['A'], 'B' => $counts['B'], 'C' => $counts['C'], 'UNCLASSIFIED' => $counts['UNCLASSIFIED']],
                'outsideScope' => $counts['outsideScope'],
                'filteredOut' => $counts['filteredOut'],
                'averageConfidence' => $confidence === [] ? null : round(array_sum($confidence) / count($confidence), 1),
            ],
            'classification' => [
                'edgePct' => $classifierRules['edgePct'],
                'drawSignificantPct' => $classifierRules['drawSignificantPct'],
                'labels' => $classifierRules['labels'],
                'source' => $classifierRules['source'],
            ],
            'entries' => $entries,
            'disclaimer' => self::DISCLAIMER,
            'model' => [
                'state' => (string) $model['state'],
                'label' => (string) $model['label'],
                'version' => $model['model']['model_version'] ?? null,
            ],
            'message' => $message,
            'generatedAt' => gmdate('c'),
        ];
    }

    /**
     * Scope check: empty scope admits every league. An entry matches on
     * "provider|externalId", a bare externalId, or the competition name —
     * the same matcher the prediction pass uses, so a scoped ticket and a
     * scoped board can never disagree.
     */
    private function inScope(array $fixture, array $scope): bool
    {
        if ($scope === []) return true;
        return $this->config->inLeagueScope(
            $fixture['provider_code'] ?? null,
            $fixture['competition_external_id'] ?? null,
            $fixture['competition'] ?? null
        );
    }
}
