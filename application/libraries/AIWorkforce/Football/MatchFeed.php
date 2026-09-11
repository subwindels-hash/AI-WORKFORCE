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
 * `UNIQUE(provider_id, external_id)`. Every match additionally carries its own
 * stored id — the fixture row's database id, served as `/football/match/<id>` —
 * and a row whose feed supplied no external id falls back to that id
 * (`fixture:<id>`), so a match that exists is never keyed by a blank. A
 * prediction is identified by the match plus the kind and the model version —
 * `UNIQUE(fixture_id, prediction_kind, model_version_id)` in every schema — so
 * the same match cannot be stored twice, and an existing prediction is
 * *returned* rather than recomputed.
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

    /**
     * The `competition` keyword that narrows a page to *every* premium league
     * on the date together — the selection behind the Premium League selector's
     * "All premium leagues" option. `all_premium` (and any spelling with
     * separators instead of underscores) is accepted as an alias.
     */
    public const PREMIUM_LEAGUES = 'PREMIUM_LEAGUES';

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
        private ?PredictionMarkets $markets = null,
        private ?IntelligenceReport $report = null,
    ) {
        $this->markets ??= new PredictionMarkets($config);
    }

    /** The market catalogue the feed can answer a page in. */
    public function markets(): PredictionMarkets
    {
        return $this->markets;
    }

    /**
     * The intelligence layer — WINDELS' score, the data-quality checklist, the
     * drivers behind the selection, the fair-value comparison, the stability
     * verdict and the three freshness clocks.
     *
     * Built here as well as in the facade so a feed assembled by hand still
     * decorates its rows: the block must never depend on which door was used.
     */
    public function report(): IntelligenceReport
    {
        return $this->report ??= new IntelligenceReport(
            $this->repo,
            $this->config,
            new StabilityMonitor($this->repo, $this->config),
            new IntelligenceScore($this->config),
            new PredictionDrivers(),
            new FreshnessTracker($this->config),
        );
    }

    /**
     * The intelligence block for every match on a page, in page order.
     *
     * One batched revision read for the page, one for the last fixture sweep —
     * the same rule that keeps market selection free of per-match queries.
     * Decorating never generates, never refetches and never re-reads a provider.
     *
     * @param list<array<string,mixed>> $fixtures
     * @param list<array<string,mixed>|null> $predictions one row (or null) per fixture
     * @param list<array<string,mixed>> $markets one evaluated market per fixture
     * @param list<array<string,mixed>> $refusals why an unanalyzed match has no row
     * @return list<array<string,mixed>>
     */
    public function decorate(array $fixtures, array $predictions, array $markets, array $refusals = []): array
    {
        $entries = [];
        foreach ($fixtures as $index => $fixture) {
            $entries[] = [
                'fixture' => (array) $fixture,
                'prediction' => is_array($predictions[$index] ?? null) ? (array) $predictions[$index] : null,
                'market' => (array) ($markets[$index] ?? []),
                'predictionRefusal' => (array) ($refusals[$index] ?? []),
            ];
        }
        return $this->report()->forPage($entries);
    }

    /**
     * The competitions a date can be narrowed to, straight from the rows the
     * provider sent, with the premium (featured) competition marked.
     *
     * The list is not a constant: the module names the leagues it actually has
     * data for, and says so when a provider cannot list them.
     *
     * @return array{competitions:list<array<string,mixed>>, premium:array<string,mixed>, total:int, state:string, message:?string}
     */
    public function competitions(string $date, ?int $providerId = null): array
    {
        $filter = ['date' => $date];
        if ($providerId !== null && $providerId > 0) $filter['providerId'] = $providerId;
        $competitions = $this->repo->listCompetitions($filter);
        $premium = $this->premiumCompetition($competitions);
        foreach ($competitions as &$competition) {
            // Premium is this deployment's classification of a league, not a
            // provider league id, so every competition on the date is judged
            // against the configured premium list — not only the featured one.
            // That is what makes the Premium League selector a list of leagues
            // (Premier League, Champions League, La Liga…) rather than a single
            // featured competition standing in for the whole idea.
            $competition['premium'] = $this->config->isPremiumCompetition(
                (string) ($competition['name'] ?? ''), (string) ($competition['externalId'] ?? ''));
        }
        unset($competition);
        $premiumList = array_values(array_filter($competitions, static fn(array $c): bool => !empty($c['premium'])));
        return [
            'competitions' => $competitions,
            'premium' => $premium,
            'premiumCompetitions' => $premiumList,
            'total' => count($competitions),
            'state' => $competitions === [] ? DataState::UNAVAILABLE : 'AVAILABLE',
            'message' => $competitions === []
                ? 'No competition is stored for ' . $date . '. Competitions are listed from the provider feed; syncing the date is what puts them here.'
                : null,
            'source' => 'STORED_COMPETITIONS',
            'note' => 'Listed from the competitions the provider has sent for this date. A provider that exposes a competition endpoint is read directly; a provider that does not is never filled in with a guessed league list.',
        ];
    }

    /**
     * The featured competition: the configured premium league when the stored
     * set contains it, otherwise the competition with the most matches on the
     * date — named either way, never silently substituted.
     *
     * @param list<array<string,mixed>> $competitions
     * @return array<string,mixed>|null
     */
    private function premiumCompetition(array $competitions): ?array
    {
        if ($competitions === []) return null;
        $wanted = $this->config->premiumCompetition();
        foreach ($competitions as $competition) {
            if ($wanted['externalId'] !== null && (string) $competition['externalId'] === (string) $wanted['externalId']) {
                return ['externalId' => (string) $competition['externalId'], 'name' => (string) $competition['name'],
                    'matches' => (int) $competition['matches'], 'source' => 'CONFIGURED_PREMIUM'];
            }
        }
        // Name matching is containment rather than equality: a provider that
        // sends "Premier League" is the same league as the configured
        // "English Premier League", and insisting on an exact string would make
        // the premium league silently fall back to whichever league happens to
        // have the most matches.
        $normalize = static fn(string $name): string => strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', $name));
        $wantedName = $normalize($wanted['name']);
        foreach ($competitions as $competition) {
            $name = $normalize((string) $competition['name']);
            if ($wantedName !== '' && $name !== '' && (str_contains($name, $wantedName) || str_contains($wantedName, $name))) {
                return ['externalId' => (string) $competition['externalId'], 'name' => (string) $competition['name'],
                    'matches' => (int) $competition['matches'], 'source' => 'CONFIGURED_PREMIUM'];
            }
        }
        $top = $competitions[0];
        return ['externalId' => (string) $top['externalId'], 'name' => (string) $top['name'],
            'matches' => (int) $top['matches'], 'source' => 'MOST_MATCHES_ON_DATE'];
    }

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
     * Options:
     *
     *  - `providerId`  narrow the page to one provider
     *  - `competition` a competition external id, its name, `premium` for the
     *    configured featured league, or `premium_leagues` / `all_premium` for
     *    every premium league on the date together. Narrowing the page narrows
     *    generation with it: only the selected competition(s) are processed.
     *  - `market`      a market key from `PredictionMarkets::catalog()`
     *  - `line`        an explicit goal/handicap line where the market has one
     *
     * A market is a *view* over the stored prediction. Selecting another one
     * re-reads the same rows, so it cannot regenerate a match and cannot cost a
     * provider call.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function page(string $date, int $page = 1, int $limit = self::DEFAULT_PAGE_SIZE, bool $generate = false, array $options = []): array
    {
        $notes = [];
        $date = $this->validDate($date, $notes);
        [$page, $limit] = $this->resolve($page, $limit, $notes);

        $providerId = isset($options['providerId']) && (int) $options['providerId'] > 0 ? (int) $options['providerId'] : null;
        $competition = $this->resolveCompetition($options['competition'] ?? null, $date, $providerId, $notes);
        $market = $this->markets->resolve($options['market'] ?? $this->config->defaultMarket(), $notes);
        $line = isset($options['line']) && is_numeric($options['line'])
            ? max(-10.0, min(10.0, (float) $options['line'])) : null;

        $filter = ['date' => $date];
        if ($providerId !== null) $filter['providerId'] = $providerId;
        if ($competition['externalIds'] !== null) $filter['competitionExternalIds'] = $competition['externalIds'];
        elseif ($competition['externalId'] !== null) $filter['competitionExternalId'] = $competition['externalId'];

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
        $entries = [];
        $pagePredictions = [];
        $refusals = [];
        foreach ($fixtures as $index => $fixture) {
            $prediction = $this->predictions->existing($fixture, $modelVersionId, PredictionService::KIND_PRE_MATCH);
            $outcome = $generation['matches'][$index] ?? [];
            $pagePredictions[$index] = $prediction;
            // Why an empty slot is empty — carried into the intelligence block so
            // "not analyzed" names its own reason instead of being a blank row.
            if ($prediction === null) {
                $refusals[$index] = ['code' => (string) ($outcome['code'] ?? 'NO_PREDICTION'),
                    'reason' => (string) ($outcome['reason'] ?? '')];
            }
            $source = $prediction !== null
                ? ($outcome['state'] === PredictionService::MISSING_GENERATED ? self::SOURCE_GENERATED : self::SOURCE_STORED)
                : (string) ($outcome['source'] ?? self::SOURCE_REFUSED);
            if ($source === self::SOURCE_STORED) $reused++;
            $matches[] = $this->match($fixture, $prediction, $source, $outcome, $modelVersionId);
            $entries[] = ['prediction' => $prediction, 'matchId' => self::matchId($fixture)];
        }
        // The selected market is attached to every match on the page from two
        // batched reads — the score grids and the quoted prices — so choosing a
        // market costs two queries for the page, not one query per match.
        $markets = $this->attachMarkets($entries, $market['market'], $line);
        // Multiple markets per fixture where verified provider odds exist
        // (1X2, Over 1.5, BTTS, Double Chance) — each candidate must have
        // real provider odds, never invented.
        $multiMarkets = $this->attachMultipleMarkets($entries, self::MULTI_MARKET_CANDIDATES);
        // Provenance is a third batched read: which provider (or providers)
        // this match came from is part of the prediction result, and it is
        // answered for the whole page in one query.
        $sources = $this->dataSources($fixtures);
        foreach ($matches as $index => $match) {
            $matches[$index]['market'] = $this->predictionResult($markets[$index], $match, $fixtures[$index] ?? [],
                $sources[(string) ($match['matchId'] ?? '')] ?? []);
            // Attach multiple market candidates per fixture (only those with verified odds)
            $candidates = [];
            foreach (($multiMarkets[$index] ?? []) as $mm) {
                $candidates[] = $this->predictionResult($mm, $match, $fixtures[$index] ?? [],
                    $sources[(string) ($match['matchId'] ?? '')] ?? []);
            }
            $matches[$index]['marketCandidates'] = $candidates;
        }
        // The intelligence block rides on the same rows the page is already
        // holding: the score, the quality checklist, the drivers, the fair-value
        // comparison, the stability verdict and the three clocks. It costs one
        // batched revision read and one sync-log read for the whole page — never
        // a provider call, and never a per-match query.
        $intelligence = $this->decorate($fixtures, $pagePredictions, $markets, $refusals);
        foreach ($matches as $index => $match) $matches[$index]['intelligence'] = $intelligence[$index] ?? null;
        $summary = $this->report()->summary(array_map(
            static fn(array $match): array => [
                'intelligence' => (array) ($match['intelligence'] ?? []),
                'analysisState' => (string) ($match['analysisState'] ?? ''),
                'market' => (array) ($match['market'] ?? []),
                'matchId' => (string) ($match['matchId'] ?? ''),
                'fixtureId' => (int) ($match['fixtureId'] ?? 0),
                'homeTeam' => (string) ($match['homeTeam'] ?? ''),
                'awayTeam' => (string) ($match['awayTeam'] ?? ''),
                'kickoffLabel' => (string) ($match['kickoffLabel'] ?? ''),
                'confidence' => $match['prediction']['confidence'] ?? null,
            ],
            $matches
        ));

        // Counted with the same filter as the page: with a competition selected,
        // "how many are analyzed" means analyzed in that competition (or group
        // of premium leagues), not on the date as a whole.
        $countFilter = ['date' => $date, 'kind' => PredictionService::KIND_PRE_MATCH];
        if ($competition['externalIds'] !== null) $countFilter['competitionExternalIds'] = $competition['externalIds'];
        elseif ($competition['externalId'] !== null) $countFilter['competitionExternalId'] = $competition['externalId'];
        $analyzed = $this->repo->countPredictions($countFilter);
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
                // A prediction that was replaced because a stated reason
                // justified it — counted apart from generation, because it is
                // not a second prediction for the match, it is a new one.
                'refreshed' => (int) ($generation['refreshed'] ?? 0),
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
            // How the intelligence layer reads this page: how much of it is
            // priced, judged, settled or withheld. Computed over the page on
            // screen and labelled as such, never over the date silently.
            'intelligenceSummary' => $summary,
            'model' => [
                'state' => (string) $model['state'],
                'label' => (string) $model['label'],
                'version' => $model['model']['model_version'] ?? null,
                'note' => $model['reason'],
            ],
            // What the page was narrowed to. A competition that is named but
            // absent from the stored set is reported, not quietly widened to
            // every league.
            'filters' => [
                'date' => $date,
                'providerId' => $providerId,
                'competition' => $competition,
                'competitions' => $this->competitions($date, $providerId),
            ],
            'market' => [
                'key' => (string) $market['key'],
                'label' => (string) $market['market']['label'],
                'group' => (string) $market['market']['group'],
                'line' => $line ?? ($market['market']['line'] ?? null),
                'source' => (string) $market['market']['derivation'] === 'NOT_MODELLED'
                    ? PredictionMarkets::SOURCE_ODDS : PredictionMarkets::SOURCE_GRID,
                'available' => $this->markets->available($this->quotedMarketCodes($matches)),
            ],
            'request' => ['date' => $date, 'page' => $page, 'limit' => $limit, 'generate' => $generate,
                'providerId' => $providerId, 'competition' => $competition['requested'], 'market' => $market['key'],
                'line' => $line, 'notes' => array_values($notes)],
            'generatedAt' => gmdate('c'),
        ];
    }

    /**
     * The prediction result for one match, in the shape the console and the API
     * both return.
     *
     * Alongside the market answer it carries what an operator needs to judge
     * it: the match it belongs to, who supplied the data (`dataSources`), the
     * model version, when it was produced, and when it stops being valid — a
     * prediction is frozen at kickoff, so that is its expiry. Nothing here is
     * recomputed: every field is read off the stored prediction.
     *
     * @param array<string,mixed> $market
     * @param array<string,mixed> $match
     * @param array<string,mixed> $fixture
     * @param list<array<string,mixed>> $providerRows
     * @return array<string,mixed>
     */
    private function predictionResult(array $market, array $match, array $fixture, array $providerRows): array
    {
        $kickoff = (string) ($fixture['kickoff_at'] ?? '');
        $prediction = $match['prediction'] ?? null;
        return $market + [
            'matchId' => (string) ($match['matchId'] ?? ''),
            // Keep match identity and provider identity separate. matchId is the
            // canonical match key; providerId/providerMatchId identify the feed
            // row and must never be treated as the same identifier.
            'providerId' => isset($fixture['provider_id']) ? (int) $fixture['provider_id'] : null,
            'providerMatchId' => ((string) ($fixture['external_id'] ?? '')) ?: null,
            'modelVersion' => is_array($prediction) ? ($prediction['modelVersionId'] ?? null) : null,
            'generatedAt' => is_array($prediction) ? ($prediction['generatedAt'] ?? null) : null,
            // A prediction is frozen at kickoff: past that moment the numbers
            // describe a match that has started, so kickoff is the expiry.
            'expiresAt' => $kickoff !== '' ? $kickoff : null,
            'expiryBasis' => $kickoff !== '' ? 'FROZEN_AT_KICKOFF' : 'KICKOFF_UNKNOWN',
            'dataSources' => $this->matchSources($fixture, $providerRows),
        ];
    }

    /**
     * Where the data behind one match came from.
     *
     * The provider that supplied the fixture is always named. When a match has
     * been recognized across feeds, every feed behind it is named with the id
     * it uses and the rule that matched — "this is SportMonks 88012, matched to
     * the record API-Football calls 1201 by teams and kickoff".
     *
     * @param array<string,mixed> $fixture
     * @param list<array<string,mixed>> $providerRows
     * @return list<array<string,mixed>>
     */
    private function matchSources(array $fixture, array $providerRows): array
    {
        $out = [];
        foreach ($providerRows as $row) {
            $out[] = [
                'provider' => (string) ($row['provider_code'] ?? DataState::UNAVAILABLE),
                'providerMatchId' => ((string) ($row['provider_match_id'] ?? '')) ?: null,
                'matchedBy' => ((string) ($row['matched_by'] ?? '')) ?: null,
                'confidence' => isset($row['confidence']) ? round((float) $row['confidence'], 3) : null,
            ];
        }
        if ($out === []) {
            $provider = (string) ($fixture['provider_code'] ?? '');
            if ($provider === '') return [];
            $out[] = ['provider' => $provider, 'providerMatchId' => ((string) ($fixture['external_id'] ?? '')) ?: null,
                'matchedBy' => null, 'confidence' => null];
        }
        return $out;
    }

    /**
     * The provider rows for a whole page, keyed by the match identity the feed
     * returns — one read for the page.
     *
     * @param list<array<string,mixed>> $fixtures
     * @return array<string,list<array<string,mixed>>>
     */
    private function dataSources(array $fixtures): array
    {
        $ids = [];
        foreach ($fixtures as $fixture) {
            $canonical = CanonicalMatch::identity((string) ($fixture['home_team'] ?? ''),
                (string) ($fixture['away_team'] ?? ''), (string) ($fixture['kickoff_at'] ?? ''));
            if ($canonical !== '') $ids[self::matchId($fixture)] = $canonical;
        }
        if ($ids === []) return [];
        $rows = $this->repo->listProviderMatchesFor(array_values(array_unique($ids)));
        $out = [];
        foreach ($ids as $matchId => $canonical) {
            if (!isset($rows[$canonical])) continue;
            $out[$matchId] = $rows[$canonical];
        }
        return $out;
    }

    /**
     * The market codes the connected odds feed has priced on this page, so the
     * market list can say which markets have a real price behind them.
     *
     * @param list<array<string,mixed>> $matches
     * @return list<string>
     */
    private function quotedMarketCodes(array $matches): array
    {
        $codes = [];
        foreach ($matches as $match) {
            foreach ((array) ($match['market']['outcomes'] ?? []) as $outcome) {
                if (($outcome['oddsState'] ?? '') === PredictionMarkets::STATE_AVAILABLE) $codes[] = (string) $match['market']['key'];
            }
        }
        return array_values(array_unique($codes));
    }

    /**
     * Markets that should be evaluated as multiple candidates per fixture
     * when provider odds exist. These are the core markets the ticket engine
     * and the board must surface together, not one at a time.
     */
    public const MULTI_MARKET_CANDIDATES = ['MATCH_WINNER', 'OVER_1_5', 'BTTS', 'DOUBLE_CHANCE'];

    /**
     * Evaluate the selected market for a whole set of entries in two batched
     * reads. This is what makes market selection free: the probabilities are
     * summed from grids that are already stored, and the prices come from rows
     * the odds feed already sent.
     *
     * @param list<array{prediction:array<string,mixed>|null,matchId:string}> $entries
     * @param array<string,mixed> $market the catalogue entry
     * @return list<array<string,mixed>> one block per entry, in the same order
     */
    public function attachMarkets(array $entries, array $market, ?float $line = null): array
    {
        $ids = [];
        $matchIds = [];
        foreach ($entries as $entry) {
            $prediction = $entry['prediction'] ?? null;
            if (is_array($prediction)) $ids[] = (string) ($prediction['id'] ?? '');
            if ((string) ($entry['matchId'] ?? '') !== '') $matchIds[] = (string) $entry['matchId'];
        }
        $grids = $this->repo->listScoreProbabilitiesFor($ids);
        $odds = $this->repo->listMarketOdds($matchIds);
        $out = [];
        foreach ($entries as $entry) {
            $prediction = $entry['prediction'] ?? null;
            if (!is_array($prediction)) {
                // The shape is identical to an evaluated market — an empty slot
                // is the same object with an empty answer, not another format.
                $out[] = ['key' => (string) $market['key'], 'label' => (string) $market['label'],
                    'state' => DataState::UNAVAILABLE, 'reason' => 'No prediction is stored for this match, so no market can be derived from it.',
                    'selection' => null, 'selectionLabel' => null, 'probability' => null, 'odds' => null,
                    'impliedProbability' => null, 'edge' => null, 'outcomes' => [], 'coverage' => 0.0,
                    'riskLevel' => PredictionMarkets::RISK_HIGH,
                    'riskFactors' => ['No prediction is stored, so there is nothing to assess.']];
                continue;
            }
            $out[] = $this->markets->evaluate($prediction, $grids[(string) ($prediction['id'] ?? '')] ?? [],
                $odds[(string) ($entry['matchId'] ?? '')] ?? [], $market, $line);
        }
        return $out;
    }

    /**
     * Evaluate MULTIPLE markets per fixture where verified provider odds exist.
     * Returns per-fixture candidates: each candidate is a market evaluation
     * that came from real provider odds (never invented), with full transparency.
     *
     * @param list<array{prediction:array<string,mixed>|null,matchId:string}> $entries
     * @param list<string>|null $marketKeys which markets to evaluate (default: MULTI_MARKET_CANDIDATES)
     * @return list<list<array<string,mixed>>> per-fixture list of market evaluations (only those with provider odds)
     */
    public function attachMultipleMarkets(array $entries, ?array $marketKeys = null): array
    {
        $marketKeys = $marketKeys ?? self::MULTI_MARKET_CANDIDATES;
        $ids = [];
        $matchIds = [];
        foreach ($entries as $entry) {
            $prediction = $entry['prediction'] ?? null;
            if (is_array($prediction)) $ids[] = (string) ($prediction['id'] ?? '');
            if ((string) ($entry['matchId'] ?? '') !== '') $matchIds[] = (string) $entry['matchId'];
        }
        $grids = $this->repo->listScoreProbabilitiesFor($ids);
        $odds = $this->repo->listMarketOdds($matchIds);

        $out = [];
        foreach ($entries as $entry) {
            $prediction = $entry['prediction'] ?? null;
            if (!is_array($prediction)) {
                $out[] = [];
                continue;
            }
            $fixtureOdds = $odds[(string) ($entry['matchId'] ?? '')] ?? [];
            $grid = $grids[(string) ($prediction['id'] ?? '')] ?? [];
            $candidates = [];

            foreach ($marketKeys as $key) {
                $catalogEntry = $this->markets->market($key);
                if ($catalogEntry === null) continue;

                // Only evaluate markets where verified provider odds exist
                $hasOdds = false;
                foreach ($fixtureOdds as $oddsRow) {
                    $normalized = PredictionMarkets::normalizeProviderMarket((string) ($oddsRow['market'] ?? ''));
                    if (PredictionMarkets::providerMarketMatches($normalized, $key)) {
                        $hasOdds = true;
                        break;
                    }
                }
                if (!$hasOdds) continue;

                $evaluated = $this->markets->evaluate($prediction, $grid, $fixtureOdds, $catalogEntry, $catalogEntry['line'] ?? null);

                // Only keep candidates where odds are from verified provider and market is valid
                if (($evaluated['state'] ?? '') === DataState::UNAVAILABLE) continue;
                if (empty($evaluated['odds'])) continue;
                if (empty($evaluated['key']) || empty($evaluated['selection'])) continue;

                $candidates[] = $evaluated;
            }
            $out[] = $candidates;
        }
        return $out;
    }

    /**
     * A requested competition onto a stored one.
     *
     * `premium` names the configured featured league; `premium_leagues` (also
     * spelled `all_premium`) names *every* premium league on the date at once —
     * the selection behind the Premium League selector's "All premium leagues"
     * option — and resolves to the group of their external ids. Anything else
     * is matched against the provider's own competition ids and names. A name
     * that matches nothing is reported with the competitions that are
     * available — the page is narrowed to it (and is therefore empty) rather
     * than silently widened to every league.
     *
     * @param list<string> $notes
     * @return array{requested:?string,externalId:?string,externalIds:?list<string>,name:?string,matches:?int,premium:bool,state:string,note:?string}
     */
    public function resolveCompetition(?string $requested, string $date, ?int $providerId, array &$notes): array
    {
        $empty = ['requested' => $requested, 'externalId' => null, 'externalIds' => null, 'name' => null,
            'matches' => null, 'premium' => false, 'state' => 'ALL_COMPETITIONS', 'note' => null];
        if ($requested === null || trim($requested) === '') return $empty;
        $wanted = trim($requested);
        $available = $this->competitions($date, $providerId);
        // Premium scope keywords are compared with separators ignored, so
        // premium_leagues, premium-leagues, all_premium and "all premium" all
        // land on the same selection.
        $scopeKey = (string) preg_replace('/[^a-z0-9]/', '', strtolower($wanted));
        if (in_array($scopeKey, ['premiumleagues', 'allpremium', 'allpremiumleagues', 'premiumall', 'premiumleaguesall'], true)) {
            $externalIds = [];
            $matches = 0;
            foreach ($available['premiumCompetitions'] as $premiumLeague) {
                $external = (string) ($premiumLeague['externalId'] ?? '');
                if ($external !== '') $externalIds[] = $external;
                $matches += (int) ($premiumLeague['matches'] ?? 0);
            }
            $externalIds = array_values(array_unique($externalIds));
            if ($externalIds === []) {
                $notes[] = 'competition=' . RequestParams::preview($wanted) . ' asks for every premium league, but no premium league'
                    . ' is stored for ' . $date . '; the page holds no match.';
            }
            return ['requested' => $wanted, 'externalId' => null, 'externalIds' => $externalIds,
                'name' => 'All premium leagues', 'matches' => $matches, 'premium' => true,
                'state' => 'PREMIUM_LEAGUES', 'note' => null];
        }
        $isPremiumKeyword = in_array(strtolower($wanted), ['premium', 'premium_league', 'premium-league'], true);
        if ($isPremiumKeyword && $available['premium'] !== null) {
            $premium = $available['premium'];
            return ['requested' => $wanted, 'externalId' => (string) $premium['externalId'], 'externalIds' => null, 'name' => (string) $premium['name'],
                'matches' => (int) $premium['matches'], 'premium' => true, 'state' => 'NARROWED',
                'note' => $premium['source'] === 'MOST_MATCHES_ON_DATE'
                    ? 'The configured premium competition has no match on this date; the competition with the most matches was featured instead.'
                    : null];
        }
        foreach ($available['competitions'] as $competition) {
            if ((string) $competition['externalId'] === $wanted || strtolower((string) $competition['name']) === strtolower($wanted)) {
                return ['requested' => $wanted, 'externalId' => (string) $competition['externalId'], 'externalIds' => null, 'name' => (string) $competition['name'],
                    'matches' => (int) $competition['matches'], 'premium' => !empty($competition['premium']), 'state' => 'NARROWED', 'note' => null];
            }
        }
        $names = implode(', ', array_map(static fn(array $c): string => (string) $c['name'], array_slice($available['competitions'], 0, 8)));
        $notes[] = 'competition=' . RequestParams::preview($wanted) . ' is not one of the competitions stored for ' . $date
            . ($names !== '' ? ' (available: ' . $names . ')' : ' (no competition is stored for this date)')
            . '; the page was narrowed to it and holds no match.';
        return ['requested' => $wanted, 'externalId' => '__none__', 'externalIds' => null, 'name' => $wanted, 'matches' => 0,
            'premium' => false, 'state' => 'NOT_FOUND', 'note' => 'No stored competition matches this selection.'];
    }

    /**
     * Generate the missing predictions for one page and return that page — the
     * mutation half of `page()`, kept separate so the console form and the JSON
     * endpoint can be permission-checked without the read path paying for it.
     *
     * The options are the same as `page()`: when a competition is selected,
     * generation is bounded to that competition and to at most
     * `MAX_PAGE_SIZE` new predictions inside it.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function generate(string $date, int $page = 1, int $limit = self::DEFAULT_PAGE_SIZE, array $options = []): array
    {
        return $this->page($date, $page, $limit, true, $options);
    }

    /**
     * The identity a stored prediction is keyed by: the provider's own
     * `match_id`, scoped so two providers cannot collide.
     *
     * Every stored match also owns a second, deployment-local identity — the
     * fixture row's own database id, the number its page is served under
     * (`/football/match/<fixtureId>`). A row whose feed supplied no external id
     * (legacy rows, or a feed that omitted one) falls back to that id, so a
     * match that exists is never returned with a blank identity: the key is
     * `fixture:<id>` only in that case, and stays `providerCode:externalId`
     * whenever the provider's own id is present.
     */
    public static function matchId(array $fixture): string
    {
        $provider = (string) ($fixture['provider_code'] ?? '');
        $external = (string) ($fixture['external_id'] ?? '');
        if ($external === '') {
            $id = (int) ($fixture['id'] ?? 0);
            return $id > 0 ? 'fixture:' . $id : '';
        }
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
        // Why a stored prediction was kept or replaced, when the engine had to
        // decide. Reuse is the default, so the block is only carried when the
        // decision was actually taken — an absent decision is not a blank one.
        $regeneration = isset($outcome['regeneration']) && is_array($outcome['regeneration'])
            ? $outcome['regeneration'] : null;
        $kickoff = (string) ($fixture['kickoff_at'] ?? '');
        return [
            'matchId' => self::matchId($fixture),
            'fixtureId' => (int) ($fixture['id'] ?? 0),
            'externalId' => (string) ($fixture['external_id'] ?? ''),
            'provider' => $fixture['provider_code'] ?? null,
            // Provider identity is numeric and independent from the match ID.
            // The UI presents this as Model 1, Model 2, etc.
            'providerId' => isset($fixture['provider_id']) ? (int) $fixture['provider_id'] : null,
            'providerLabel' => isset($fixture['provider_id']) && (int) $fixture['provider_id'] > 0
                ? 'Model ' . (int) $fixture['provider_id'] : 'Model unavailable',
            'providerMatchId' => ((string) ($fixture['external_id'] ?? '')) ?: null,
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
            'regeneration' => $regeneration,
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
