<?php
namespace AIWorkforce\Football;

use AIWorkforce\Persistence\FootballRepository;

/**
 * The paginated match feed — 50 matches per page, 50 per generation request.
 *
 * This class is the answer to two different questions that used to be the same
 * question, and the split is the whole point:
 *
 *  1. **Reading** (`page()` without generation) is a pure database read. It
 *     slices the *persisted* fixtures for a date and attaches the predictions
 *     that are already stored. Moving between pages never runs the engine,
 *     never calls the provider and never costs a token.
 *  2. **Generating** (`page()` with `generate`, or `generate()`) is bounded:
 *     it looks at the matches on the requested page, asks the repository which
 *     of them already carry a prediction for the model version in use, and
 *     hands only the difference to the prediction engine — never more than
 *     `MAX_PAGE_SIZE` at a time.
 *
 * The two stages the module was specified with, in order:
 *
 * ```text
 * fixtures stored for a date   →  check every match_id against the database
 *                              →  new matches only
 *                              →  at most 50 sent for prediction generation
 *                              →  saved with model version + timestamp
 *                              →  the page is rendered from those stored rows
 * ```
 *
 * A match is identified by its `matchId` (`providerCode:externalId`), which the
 * provider guarantees unique and the fixture table enforces with
 * `UNIQUE(provider_id, external_id)`. A prediction is identified by that match
 * plus the kind and the model version — `UNIQUE(fixture_id, prediction_kind,
 * model_version_id)` in every schema — so the same match cannot be stored
 * twice, and an existing prediction is *returned* rather than recomputed.
 *
 * Nothing here invents a match to fill a short page: the last page holds
 * whatever is left, and an empty page says so.
 */
final class MatchFeed
{
    /**
     * Hard server-side ceiling. `?limit=5000` is clamped to this, and a
     * generation request can never produce more than this many new predictions
     * — the number is enforced here, not only in configuration, so no caller
     * can ask for a thousand predictions in one call.
     */
    public const MAX_PAGE_SIZE = 50;

    /** Matches per page when the caller does not name one. */
    public const DEFAULT_PAGE_SIZE = 50;

    /** A page number is at least 1; the upper bound only stops absurd input. */
    public const MAX_PAGE = 100000;

    /** Why a match on a page has no prediction attached to it. */
    public const SOURCE_STORED = 'STORED';
    public const SOURCE_GENERATED = 'GENERATED';
    public const SOURCE_DEFERRED = 'DEFERRED';
    public const SOURCE_REFUSED = 'REFUSED';
    public const SOURCE_FAILED = 'FAILED';

    public function __construct(
        private FootballRepository $repo,
        private PredictionService $predictions,
        private ModelRegistry $models,
        private FootballConfiguration $config,
    ) {}

    /**
     * Clamp a requested page/limit into the range that is actually allowed,
     * recording why a value was changed. A caller that asked for 1,000 matches
     * is told it got 50 — it is never silently served a different request.
     *
     * @param list<string> $notes
     * @return array{0:int,1:int} [page, limit]
     */
    public function resolve(int $page, int $limit, array &$notes = []): array
    {
        if ($limit < 1) {
            $notes[] = 'limit=' . $limit . ' is not a usable page size; ' . self::DEFAULT_PAGE_SIZE . ' was used.';
            $limit = self::DEFAULT_PAGE_SIZE;
        } elseif ($limit > self::MAX_PAGE_SIZE) {
            $notes[] = 'limit=' . $limit . ' exceeds the hard maximum of ' . self::MAX_PAGE_SIZE
                . ' matches per page; the page was served with ' . self::MAX_PAGE_SIZE . '.';
            $limit = self::MAX_PAGE_SIZE;
        }
        if ($page < 1) {
            $notes[] = 'page=' . $page . ' is below the first page; page 1 was served.';
            $page = 1;
        } elseif ($page > self::MAX_PAGE) {
            $notes[] = 'page=' . $page . ' is beyond the last addressable page; page ' . self::MAX_PAGE . ' was served.';
            $page = self::MAX_PAGE;
        }
        return [$page, $limit];
    }

    /**
     * One page of matches for a date, optionally generating the page's missing
     * predictions first.
     *
     * @return array<string,mixed>
     */
    public function page(string $date, int $page = 1, int $limit = self::DEFAULT_PAGE_SIZE, bool $generate = false, ?string $providerId = null): array
    {
        $notes = [];
        $date = $this->validDate($date, $notes);
        [$page, $limit] = $this->resolve($page, $limit, $notes);

        $filter = ['date' => $date];
        if ($providerId !== null && $providerId !== '') $filter['providerId'] = (int) $providerId;

        $model = $this->models->usable();
        $modelVersionId = (int) ($model['model']['id'] ?? 0);

        // Stage 1 — the fixture page. This is a paged read over stored rows:
        // how many matches exist is a COUNT, never the size of one page.
        $totalMatches = $this->repo->countFixtures($filter);
        $totalPages = max(1, (int) ceil($totalMatches / $limit));
        $fixtures = $this->repo->listFixtures($filter, $limit, ($page - 1) * $limit);
        if ($page > $totalPages && $totalMatches > 0) {
            $notes[] = 'page=' . $page . ' is past the last page (' . $totalPages . '); no matches were returned.';
        }

        // Stage 1b — check every match_id against the database, in one query.
        $this->predictions->prime(
            array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $fixtures),
            $modelVersionId,
            PredictionService::KIND_PRE_MATCH,
        );

        // Stage 2 — generate at most one page's worth of NEW matches.
        $generation = $generate
            ? $this->predictions->predictMissing($fixtures, $limit, PredictionService::KIND_PRE_MATCH)
            : $this->predictions->reportOnly($fixtures, $limit, PredictionService::KIND_PRE_MATCH);

        $matches = [];
        $reused = 0;
        foreach ($fixtures as $index => $fixture) {
            $prediction = $this->predictions->existing($fixture, $modelVersionId, PredictionService::KIND_PRE_MATCH);
            $outcome = $generation['matches'][$index] ?? [];
            $source = $prediction !== null
                ? ($outcome['state'] === PredictionService::MISSING_GENERATED ? self::SOURCE_GENERATED : self::SOURCE_STORED)
                : (string) ($outcome['source'] ?? self::SOURCE_REFUSED);
            if ($source === self::SOURCE_STORED) $reused++;
            $matches[] = $this->match($fixture, $prediction, $source, $outcome, $modelVersionId);
        }

        $analyzed = $this->repo->countPredictions(['date' => $date, 'kind' => PredictionService::KIND_PRE_MATCH]);
        $missing = max(0, $totalMatches - $analyzed);

        $first = $totalMatches === 0 ? 0 : (($page - 1) * $limit) + 1;
        $returned = count($matches);

        return [
            'state' => $totalMatches === 0 ? DataState::UNAVAILABLE : 'AVAILABLE',
            'date' => $date,
            'message' => $totalMatches === 0
                ? 'No fixture is stored for ' . $date . '. This is a data-availability state: sync the date first — the feed does not invent matches to page through.'
                : ($returned === 0 ? 'Page ' . $page . ' is past the last page of ' . $totalPages . '. Nothing was generated for it.' : null),
            'matches' => $matches,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'maxLimit' => self::MAX_PAGE_SIZE,
                'pageSize' => $limit,
                'totalMatches' => $totalMatches,
                'totalPages' => $totalPages,
                'returned' => $returned,
                'from' => $returned === 0 ? 0 : $first,
                'to' => $returned === 0 ? 0 : $first + $returned - 1,
                'hasPrevious' => $page > 1 && $totalMatches > 0,
                'hasNext' => $page < $totalPages,
                'previousPage' => $page > 1 ? $page - 1 : null,
                'nextPage' => $page < $totalPages ? $page + 1 : null,
                'firstPage' => 1,
                'lastPage' => $totalPages,
            ],
            'generation' => [
                'requested' => $generate,
                'batchLimit' => $generation['limit'],
                'generated' => (int) $generation['generated'],
                'reused' => $reused,
                'skippedStored' => (int) $generation['skipped'],
                'deferred' => (int) ($generation['deferred'] ?? 0),
                'refused' => (int) ($generation['refused'] ?? 0),
                'frozen' => (int) $generation['frozen'],
                'failed' => (int) $generation['failed'],
                'errors' => array_values($generation['errors']),
                'remainingOnDate' => $missing,
                'modelVersionId' => $modelVersionId ?: null,
                'predictionModelVersion' => $model['model']['model_version'] ?? null,
                'predictionDate' => gmdate('Y-m-d'),
                'generatedAt' => gmdate('c'),
            ],
            'summary' => [
                'fixtures' => $totalMatches,
                'analyzed' => $analyzed,
                'missing' => $missing,
                'returned' => $returned,
            ],
            'model' => [
                'state' => (string) $model['state'],
                'label' => (string) $model['label'],
                'version' => $model['model']['model_version'] ?? null,
                'note' => $model['reason'],
            ],
            'request' => ['date' => $date, 'page' => $page, 'limit' => $limit, 'generate' => $generate, 'notes' => array_values($notes)],
            'generatedAt' => gmdate('c'),
        ];
    }

    /**
     * Generate the missing predictions for one page and return that page — the
     * mutation half of `page()`, kept separate so the console form and the JSON
     * endpoint can be permission-checked without the read path paying for it.
     *
     * @return array<string,mixed>
     */
    public function generate(string $date, int $page = 1, int $limit = self::DEFAULT_PAGE_SIZE, ?string $providerId = null): array
    {
        return $this->page($date, $page, $limit, true, $providerId);
    }

    /**
     * The identity a stored prediction is keyed by: the provider's own
     * `match_id`, scoped so two providers cannot collide.
     */
    public static function matchId(array $fixture): string
    {
        $provider = (string) ($fixture['provider_code'] ?? '');
        $external = (string) ($fixture['external_id'] ?? '');
        if ($external === '') return '';
        return $provider !== '' ? $provider . ':' . $external : $external;
    }

    /** @param array<string,mixed> $prediction */
    public static function predictionSummary(array $prediction): array
    {
        $generatedAt = (string) ($prediction['generated_at'] ?? '');
        return [
            'predictionId' => (string) ($prediction['id'] ?? ''),
            'result' => (string) ($prediction['predicted_result'] ?? ''),
            'predictedScore' => isset($prediction['predicted_home_score'], $prediction['predicted_away_score'])
                ? ['home' => (int) $prediction['predicted_home_score'], 'away' => (int) $prediction['predicted_away_score'],
                    'label' => (int) $prediction['predicted_home_score'] . '–' . (int) $prediction['predicted_away_score']]
                : null,
            'probabilities' => ['home' => $prediction['probability_home'] ?? null, 'draw' => $prediction['probability_draw'] ?? null,
                'away' => $prediction['probability_away'] ?? null],
            'confidence' => isset($prediction['confidence']) ? round((float) $prediction['confidence'], 1) : null,
            'confidenceBasis' => (string) ($prediction['confidence_basis'] ?? 'RAW'),
            'calibrationState' => (string) ($prediction['calibration_state'] ?? CalibrationService::PENDING),
            'dataQuality' => (int) ($prediction['data_quality_score'] ?? 0),
            'band' => (string) ($prediction['data_quality_band'] ?? QualityBand::REJECTED),
            'settlementState' => (string) ($prediction['settlement_state'] ?? 'OPEN'),
            'modelVersionId' => (int) ($prediction['model_version_id'] ?? 0) ?: null,
            // A prediction is reused or refreshed by these three together: which
            // match, which model version, and when it was produced.
            'predictionDate' => $generatedAt !== '' ? substr($generatedAt, 0, 10) : null,
            'generatedAt' => $generatedAt !== '' ? $generatedAt : null,
        ];
    }

    /**
     * One row of a page: the stored fixture facts, plus the prediction that
     * already existed (or was just written), plus — when there is none — the
     * reason, so an empty slot is a stated state rather than a blank.
     *
     * @param array<string,mixed> $fixture
     * @param array<string,mixed>|null $prediction
     * @param array<string,mixed> $outcome
     * @return array<string,mixed>
     */
    private function match(array $fixture, ?array $prediction, string $source, array $outcome, int $modelVersionId): array
    {
        $kickoff = (string) ($fixture['kickoff_at'] ?? '');
        return [
            'matchId' => self::matchId($fixture),
            'fixtureId' => (int) ($fixture['id'] ?? 0),
            'externalId' => (string) ($fixture['external_id'] ?? ''),
            'provider' => $fixture['provider_code'] ?? null,
            'competition' => (string) ($fixture['competition'] ?? DataState::UNAVAILABLE),
            'country' => $fixture['country'] ?? null,
            'season' => $fixture['season'] ?? null,
            'kickoff' => $kickoff !== '' ? $kickoff : null,
            'kickoffLabel' => $kickoff !== '' ? gmdate('H:i', (int) strtotime($kickoff)) . ' UTC' : DataState::UNAVAILABLE,
            'status' => (string) ($fixture['status'] ?? 'UNKNOWN'),
            'matchState' => (string) ($fixture['match_state'] ?? 'PRE_MATCH'),
            'minute' => $fixture['minute'] ?? null,
            'homeTeam' => (string) ($fixture['home_team'] ?? DataState::UNAVAILABLE),
            'awayTeam' => (string) ($fixture['away_team'] ?? DataState::UNAVAILABLE),
            'score' => (isset($fixture['home_score'], $fixture['away_score']) && $fixture['home_score'] !== null)
                ? ['home' => (int) $fixture['home_score'], 'away' => (int) $fixture['away_score']] : null,
            'dataState' => (string) ($fixture['data_state'] ?? DataState::UNAVAILABLE),
            'analysisState' => $prediction === null ? 'NOT_ANALYZED' : 'ANALYZED',
            'predictionSource' => $source,
            'prediction' => $prediction === null ? null : self::predictionSummary($prediction),
            'predictionRefusal' => $prediction === null ? [
                'code' => (string) ($outcome['code'] ?? ($source === self::SOURCE_DEFERRED ? 'BATCH_LIMIT_REACHED' : 'NO_PREDICTION')),
                'reason' => (string) ($outcome['reason'] ?? $this->refusalReason($source)),
            ] : null,
            'modelVersionId' => $modelVersionId ?: null,
        ];
    }

    private function refusalReason(string $source): string
    {
        return match ($source) {
            self::SOURCE_DEFERRED => 'The generation batch for this request was already full ('
                . self::MAX_PAGE_SIZE . ' new matches); this match is analyzed on the next request.',
            self::SOURCE_FAILED => 'The prediction engine failed for this match; the stored reason is in generation.errors.',
            default => 'No prediction is stored for this match yet. Generating this page creates at most '
                . self::MAX_PAGE_SIZE . ' new predictions.',
        };
    }

    /** @param list<string> $notes */
    private function validDate(string $date, array &$notes = []): string
    {
        $matches = [];
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $matches) && checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            return $date;
        }
        $notes[] = 'date=' . RequestParams::preview($date) . ' is not a real YYYY-MM-DD calendar date; ' . gmdate('Y-m-d') . ' was paged instead.';
        return gmdate('Y-m-d');
    }
}
