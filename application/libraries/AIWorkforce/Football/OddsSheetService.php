<?php
namespace AIWorkforce\Football;

use AIWorkforce\Persistence\FootballRepository;
use AIWorkforce\Sports\OddsFreshnessEngine;
use AIWorkforce\Sports\SportsDataNormalizer;
use AIWorkforce\Sports\OddsBounds;

/**
 * The complete bookmaker odds surface for one stored fixture — pre-match,
 * in-play, and the vendor's reference catalogs — on top of the SAME provider
 * gateway and stores the rest of the football module already uses.
 *
 * Four rules shape every method here, and they are the module's own rules:
 *
 *  1. **Stored first.** `sheet()` reads the odds rows the sync jobs already
 *     persisted (`sports_odds`, matched through the fixture's provider code
 *     and external id) and never touches the network. A page view costs
 *     nothing.
 *  2. **A refresh is billed, and says so.** `refresh()` spends provider quota
 *     through the gateway (budget, backoff, quota pre-check, redaction all
 *     apply) and persists what arrived through the same normalizer the sports
 *     sync uses — a price that fails validation is counted, not hidden.
 *  3. **Live odds are a snapshot, not history.** The vendor stores no
 *     in-play history, so `live()` persists each snapshot as a
 *     `LIVE_ODDS` fixture-statistics row with the provider's own flags
 *     (`suspended`, `stopped`, `blocked`) carried through, and re-reads that
 *     row until the live refresh interval has passed instead of hammering
 *     the feed from every open page.
 *  4. **Nothing is invented.** No stored rows ⇒ DATA_UNAVAILABLE with the
 *     reason. A provider without the capability ⇒ the failure map, verbatim.
 */
final class OddsSheetService
{
    /** Where the rarely-changing vendor catalogs (bookmakers / bet types) are cached. */
    private const CATALOG_TTL_SECONDS = 7 * 86400;
    /** Hard cap on live-odds rows persisted per snapshot (a full in-play board can be enormous). */
    private const LIVE_ROWS_CAP = 2000;

    /** Sports store (the CI sports repository object) — odds persistence + bookmaker-grouped reads. */
    private ?object $store = null;
    private string $cacheDir;

    public function __construct(
        private FootballRepository $repo,
        private ProviderGateway $gateway,
        private FootballConfiguration $config,
        ?string $cacheDir = null,
    ) {
        $this->cacheDir = $cacheDir ?? dirname(__DIR__, 3) . '/cache';
    }

    /** Bind the sports repository so refreshed odds persist into sports_odds (Platform wiring). */
    public function bindSportsStore(?object $store): void
    {
        $this->store = $store;
    }

    // ── the stored sheet ─────────────────────────────────────────────────────

    /**
     * Every stored bookmaker price for one fixture, grouped by canonical
     * market → selection → quotes. Zero provider requests.
     *
     * @return array<string,mixed>
     */
    public function sheet(int $fixtureId): array
    {
        $fixture = $this->repo->findFixtureById($fixtureId);
        if ($fixture === null) {
            return ['state' => DataState::UNAVAILABLE, 'fixtureId' => $fixtureId,
                'reason' => 'Fixture ' . $fixtureId . ' is not stored, so there are no odds to show for it.',
                'markets' => [], 'generatedAt' => gmdate('c')];
        }
        $rows = $this->storedRows($fixture);
        if ($rows === []) {
            return ['state' => DataState::UNAVAILABLE, 'fixtureId' => $fixtureId,
                'fixture' => PredictionService::fixtureSummary($fixture),
                'reason' => 'No bookmaker odds are stored for this fixture yet. Refresh the odds from the provider '
                    . '(sports.manage) or wait for the scheduled odds sync — no price is ever invented.',
                'markets' => [], 'generatedAt' => gmdate('c')];
        }
        return [
            'state' => DataState::AVAILABLE,
            'fixtureId' => $fixtureId,
            'fixture' => PredictionService::fixtureSummary($fixture),
            'markets' => $this->groupRows($rows),
            'totalQuotes' => count($rows),
            'generatedAt' => gmdate('c'),
        ];
    }

    /**
     * Fetch the fixture's pre-match odds from the connected provider NOW,
     * persist every valid row, and return the updated sheet. This spends
     * provider quota — callers gate it behind sports.manage.
     *
     * @return array<string,mixed>
     */
    public function refresh(int $fixtureId): array
    {
        $fixture = $this->repo->findFixtureById($fixtureId);
        if ($fixture === null) {
            return ['state' => DataState::UNAVAILABLE, 'fixtureId' => $fixtureId,
                'reason' => 'Fixture ' . $fixtureId . ' is not stored; nothing was requested from the provider.',
                'refreshed' => ['fetched' => 0, 'stored' => 0, 'invalid' => 0], 'generatedAt' => gmdate('c')];
        }
        if ($this->store === null) {
            return ['state' => DataState::UNAVAILABLE, 'fixtureId' => $fixtureId,
                'reason' => 'No odds store is bound in this runtime, so fetched prices could not be persisted. '
                    . 'No provider request was made.',
                'refreshed' => ['fetched' => 0, 'stored' => 0, 'invalid' => 0], 'generatedAt' => gmdate('c')];
        }
        $external = (string) ($fixture['external_id'] ?? '');
        if ($external === '') {
            return ['state' => DataState::UNAVAILABLE, 'fixtureId' => $fixtureId,
                'reason' => 'This stored fixture carries no provider match id, so no feed can be asked for its odds.',
                'refreshed' => ['fetched' => 0, 'stored' => 0, 'invalid' => 0], 'generatedAt' => gmdate('c')];
        }
        $this->gateway->beginSweep($this->config->requestBudget('upcoming'));
        $outcome = $this->fetchAndPersist($fixture);
        if (!$outcome['ok']) {
            return ['state' => DataState::UNAVAILABLE, 'fixtureId' => $fixtureId,
                'fixture' => PredictionService::fixtureSummary($fixture),
                'reason' => 'No connected provider answered the odds request.',
                'failures' => (array) $outcome['failures'],
                'refreshed' => ['fetched' => 0, 'stored' => 0, 'invalid' => 0],
                'markets' => $this->groupRows($this->storedRows($fixture)),
                'generatedAt' => gmdate('c')];
        }
        $providerCode = (string) $outcome['provider'];
        $sheet = $this->sheet($fixtureId);
        $sheet['refreshed'] = ['provider' => $providerCode, 'fetched' => (int) $outcome['fetched']]
            + (array) $outcome['persisted'];
        if ((int) $outcome['fetched'] === 0) {
            $sheet['reason'] = ($sheet['reason'] ?? '') !== '' ? $sheet['reason']
                : $providerCode . ' returned no odds for this fixture (bookmakers may not have priced it yet).';
        }
        return $sheet;
    }

    /**
     * Price a whole date's stored fixtures in ONE budgeted sweep.
     *
     * This is the job the board was missing. `refresh()` prices one fixture an
     * operator opened; nothing priced the day, so every fixture the fixtures
     * sweep stored arrived with zero quotes and the board's own odds panel
     * ("Bookmaker odds — no quote", "Available odds 0 quotes across 0 markets")
     * was reporting an absence the module had never actually gone and looked
     * for.
     *
     * The same rules the rest of the module runs on apply here:
     *
     *  - **Budgeted.** One sweep, `requestBudget('odds')` requests. When the
     *    budget runs out the remainder is reported as deferred, not dropped —
     *    the next tick picks it up.
     *  - **Freshness first.** A fixture whose newest stored quote is younger
     *    than `maxDataAgeSeconds('odds')` is reused, not re-requested, so the
     *    sweep spends quota only on prices that are actually missing or aged.
     *  - **Nothing is invented.** A fixture the provider does not price keeps
     *    zero quotes and the board keeps saying so.
     *
     * @return array<string,mixed>
     */
    public function refreshDay(string $date, ?int $limit = null, bool $force = false): array
    {
        $generatedAt = gmdate('c');
        if ($this->store === null) {
            return ['status' => 'SKIPPED', 'date' => $date, 'reason' => 'NO_ODDS_STORE',
                'detail' => 'No odds store is bound in this runtime, so fetched prices could not be persisted. '
                    . 'No provider request was made.',
                'fixtures' => 0, 'priced' => 0, 'stored' => 0, 'invalid' => 0, 'freshReused' => 0,
                'deferred' => 0, 'unpriced' => 0, 'requests' => 0, 'errors' => [], 'generatedAt' => $generatedAt];
        }
        if (!$this->gateway->configured()) {
            return ['status' => 'SKIPPED', 'date' => $date, 'reason' => 'FOOTBALL_PROVIDER_NOT_CONFIGURED',
                'detail' => 'No football data provider is registered, so no price could be requested. '
                    . 'The board keeps reporting its quotes as unavailable rather than inventing one.',
                'fixtures' => 0, 'priced' => 0, 'stored' => 0, 'invalid' => 0, 'freshReused' => 0,
                'deferred' => 0, 'unpriced' => 0, 'requests' => 0, 'errors' => [], 'generatedAt' => $generatedAt];
        }
        if (!$this->gateway->supports('odds')) {
            return ['status' => 'SKIPPED', 'date' => $date, 'reason' => 'UNSUPPORTED_CAPABILITY',
                'detail' => 'No connected provider exposes an odds endpoint, so no bookmaker price can be stored '
                    . 'for this date. This is a capability gap, not a missing market.',
                'fixtures' => 0, 'priced' => 0, 'stored' => 0, 'invalid' => 0, 'freshReused' => 0,
                'deferred' => 0, 'unpriced' => 0, 'requests' => 0, 'errors' => [], 'generatedAt' => $generatedAt];
        }

        $budget = $this->config->requestBudget('odds');
        $this->gateway->beginSweep($budget);
        $maxAge = $this->config->maxDataAgeSeconds('odds');
        // Only fixtures that can still be bet on are worth a price: a finished
        // or abandoned match is the settlement job's business, and paying for
        // its odds would be quota spent on a market nobody can enter.
        $fixtures = $this->repo->listFixtures(['date' => $date], max(1, min(500, $limit ?? 200)));
        $priced = 0; $stored = 0; $invalid = 0; $freshReused = 0; $unpriced = 0; $deferred = 0;
        $skippedClosed = 0; $errors = []; $providers = [];
        foreach ($fixtures as $fixture) {
            $status = strtoupper((string) ($fixture['status'] ?? ''));
            if (in_array($status, ['FINISHED', 'CANCELLED', 'POSTPONED', FixtureSyncService::STALE_LIVE_STATUS], true)) {
                $skippedClosed++;
                continue;
            }
            if ((string) ($fixture['external_id'] ?? '') === '') {
                $skippedClosed++;
                continue;
            }
            // A price that is still inside its freshness window is reused. This
            // is what keeps a 15-minute cadence affordable on a 200-fixture day.
            if (!$force && $this->quotesAreFresh($fixture, $maxAge)) {
                $freshReused++;
                continue;
            }
            if ($this->gateway->requestsRemaining() <= 0) {
                $deferred++;
                continue;
            }
            $outcome = $this->fetchAndPersist($fixture);
            if (!$outcome['ok']) {
                // A provider that deferred (budget/backoff/quota) has not
                // refused the fixture — it has not been asked yet.
                if (!empty($outcome['deferred'])) { $deferred++; continue; }
                $label = (string) ($fixture['home_team'] ?? '?') . ' vs ' . (string) ($fixture['away_team'] ?? '?');
                if (count($errors) < 10) {
                    $errors[] = $label . ': ' . implode('; ', array_map(
                        static fn($k, $v): string => $k . '=' . $v,
                        array_keys((array) $outcome['failures']),
                        array_values((array) $outcome['failures'])
                    ));
                }
                continue;
            }
            $persisted = (array) $outcome['persisted'];
            $providers[(string) $outcome['provider']] = true;
            $stored += (int) ($persisted['stored'] ?? 0);
            $invalid += (int) ($persisted['invalid'] ?? 0);
            foreach ((array) ($persisted['errors'] ?? []) as $error) {
                if (count($errors) < 10) $errors[] = (string) $error;
            }
            // "Priced" counts fixtures a bookmaker actually quoted. A fixture
            // the feed answered with an empty list is counted as unpriced, and
            // the board keeps showing it as unpriced — which is the truth.
            if ((int) ($persisted['stored'] ?? 0) > 0) $priced++;
            else $unpriced++;
        }
        $requests = $this->gateway->requestsMade();
        return [
            'status' => $errors !== [] && $priced === 0 && $stored === 0 ? 'FAILED' : 'COMPLETED',
            'date' => $date,
            'fixtures' => count($fixtures),
            'considered' => count($fixtures) - $skippedClosed,
            'priced' => $priced,
            'unpriced' => $unpriced,
            'stored' => $stored,
            'invalid' => $invalid,
            'freshReused' => $freshReused,
            'deferred' => $deferred,
            'skippedClosed' => $skippedClosed,
            'requests' => $requests,
            'budget' => $budget,
            'maxAgeSeconds' => $maxAge,
            'providers' => array_keys($providers),
            'errors' => $errors,
            'note' => $deferred > 0
                ? $deferred . ' fixture(s) were not requested in this sweep (request budget ' . $budget
                    . ' reached); the next scheduled run continues from there.'
                : 'every eligible fixture for ' . $date . ' was offered to the odds feed in this sweep.',
            'generatedAt' => $generatedAt,
        ];
    }

    /**
     * The board-level odds status for a date — what the console's "Fixture
     * odds board" section explains itself with instead of leaving every cell
     * to promise "prices arrive with the scheduled odds sweep".
     *
     * Purely read-only: stored quotes, the last recorded ODDS sweep and the
     * gateway's capability table. No provider request is ever made. The state
     * names WHICH absence the board is in, because they are different facts:
     *
     *  - NO_PROVIDER          — no feed connected; nothing can be requested
     *  - NO_ODDS_CAPABILITY   — a feed is connected but exposes no odds
     *                           endpoint, so no price can EVER be stored
     *                           (a capability gap, not a missing market)
     *  - NO_OPEN_FIXTURES     — the date holds no still-priceable fixture
     *  - NEVER_SWEPT          — no odds sweep recorded yet (cron not due /
     *                           never run, operator never clicked)
     *  - SWEPT_NO_QUOTES      — a sweep ran and the feed stored no quote:
     *                           the bookmakers had not priced these fixtures
     *                           (or the feed's plan does not include odds)
     *  - PRICED               — quotes exist; report how many and how fresh
     *
     * @return array<string,mixed>
     */
    public function boardStatus(string $date): array
    {
        $generatedAt = gmdate('c');
        $configured = $this->gateway->configured();
        $supports = $configured && $this->gateway->supports('odds');
        $lastRun = null;
        try { $lastRun = $this->repo->lastSyncRun('ODDS'); } catch (\Throwable $e) { $lastRun = null; }

        $fixtures = $this->repo->listFixtures(['date' => $date], 500);
        $openFixtures = 0; $quotedFixtures = 0; $quotes = 0; $newestQuoteAt = null;
        foreach ($fixtures as $fixture) {
            $status = strtoupper((string) ($fixture['status'] ?? ''));
            if (in_array($status, ['FINISHED', 'CANCELLED', 'POSTPONED', FixtureSyncService::STALE_LIVE_STATUS], true)) continue;
            $openFixtures++;
            $rows = $this->storedRows($fixture);
            if ($rows === []) continue;
            $quotedFixtures++;
            $quotes += count($rows);
            foreach ($rows as $row) {
                $observed = (string) ($row['observedAt'] ?? '');
                if ($observed !== '' && ($newestQuoteAt === null || $observed > $newestQuoteAt)) $newestQuoteAt = $observed;
            }
        }

        $sweepAt = (string) ($lastRun['started_at'] ?? '');
        $sweepStamp = substr($sweepAt, 11, 5);
        $sweepRequests = (int) ($lastRun['requests_made'] ?? 0);
        $sweepErrors = (array) ($lastRun['errors'] ?? []);

        $state = match (true) {
            !$configured => 'NO_PROVIDER',
            !$supports => 'NO_ODDS_CAPABILITY',
            $openFixtures === 0 => 'NO_OPEN_FIXTURES',
            $lastRun === null => 'NEVER_SWEPT',
            $quotes === 0 => 'SWEPT_NO_QUOTES',
            default => 'PRICED',
        };
        $detail = match ($state) {
            'NO_PROVIDER' => 'No football data provider is connected, so no bookmaker price can be requested or stored for this board.',
            'NO_ODDS_CAPABILITY' => 'The connected provider exposes no odds endpoint, so no bookmaker price can be stored for this board. That is a capability gap of the feed, not a missing market — connect a provider with an odds capability to price it. No price is invented in the meantime.',
            'NO_OPEN_FIXTURES' => 'No open (still priceable) fixtures are stored for this date — finished, postponed and abandoned matches are the settlement job\'s business, and their odds are never re-bought.',
            'NEVER_SWEPT' => 'No odds sweep has been recorded yet. The sweep prices every open fixture on this board and runs automatically on the football odds job (every 15 minutes while a provider is connected), or on demand via Refresh odds for this date.',
            'SWEPT_NO_QUOTES' => 'The last odds sweep ran at ' . ($sweepStamp !== '' ? $sweepStamp . ' UTC' : 'an unknown time')
                . ' and spent ' . $sweepRequests . ' provider request(s), but no bookmaker quote is stored for this date: the bookmakers had not priced these fixtures, or the feed\'s plan does not include odds.'
                . ($sweepErrors !== [] ? ' ' . count($sweepErrors) . ' provider warning(s) from that sweep are in the sync log.' : '')
                . ' No price is invented to fill the gap.',
            default => $quotes . ' bookmaker quote(s) stored across ' . $quotedFixtures . ' of ' . $openFixtures . ' open fixture(s)'
                . ($newestQuoteAt !== null ? ' · newest quote ' . substr((string) $newestQuoteAt, 11, 5) . ' UTC' : '')
                . ($sweepAt !== '' ? ' · last sweep ' . $sweepStamp . ' UTC (' . $sweepRequests . ' request(s))' : '') . '.',
        };

        return [
            'state' => $state,
            'detail' => $detail,
            'date' => $date,
            'providerConfigured' => $configured,
            'supportsOdds' => $supports,
            'openFixtures' => $openFixtures,
            'quotedFixtures' => $quotedFixtures,
            'quotes' => $quotes,
            'newestQuoteAt' => $newestQuoteAt,
            'lastSweep' => $lastRun === null ? null : [
                'status' => (string) ($lastRun['status'] ?? ''),
                'startedAt' => $sweepAt,
                'requests' => $sweepRequests,
                'errors' => count($sweepErrors),
            ],
            'generatedAt' => $generatedAt,
        ];
    }

    /**
     * One fixture's billed odds call + persistence, with NO budget reset — the
     * caller owns the sweep. Shared by the single-fixture refresh and the
     * day sweep so both bill, validate and store a price identically.
     *
     * @param array<string,mixed> $fixture
     * @return array{ok:bool, provider:?string, fetched:int, persisted:array<string,mixed>, failures:array<string,string>, deferred:bool}
     */
    private function fetchAndPersist(array $fixture): array
    {
        $external = (string) ($fixture['external_id'] ?? '');
        $preferred = trim((string) ($fixture['provider_code'] ?? '')) ?: null;
        $outcome = $this->gateway->call('odds', fn($provider) => $provider->odds($external), $preferred);
        if (!$outcome['ok']) {
            return ['ok' => false, 'provider' => null, 'fetched' => 0,
                'persisted' => ['stored' => 0, 'invalid' => 0, 'errors' => []],
                'failures' => (array) $outcome['failures'], 'deferred' => (bool) ($outcome['deferred'] ?? false)];
        }
        $providerCode = (string) $outcome['provider'];
        $rows = is_array($outcome['result']) ? $outcome['result'] : [];
        return ['ok' => true, 'provider' => $providerCode, 'fetched' => count($rows),
            'persisted' => $this->persistRows($fixture, $providerCode, $rows),
            'failures' => [], 'deferred' => false];
    }

    /**
     * True when this fixture already has a stored quote inside the odds
     * freshness window, so re-requesting it would buy nothing.
     *
     * @param array<string,mixed> $fixture
     */
    private function quotesAreFresh(array $fixture, int $maxAgeSeconds): bool
    {
        $newest = null;
        foreach ($this->storedRows($fixture) as $row) {
            $observed = (string) ($row['observedAt'] ?? '');
            if ($observed === '') continue;
            $stamp = strtotime($observed);
            if ($stamp === false) continue;
            $newest = $newest === null ? $stamp : max($newest, $stamp);
        }
        if ($newest === null) return false;
        return (time() - $newest) < $maxAgeSeconds;
    }

    // ── in-play odds ─────────────────────────────────────────────────────────

    /**
     * The in-play odds snapshot for a live fixture.
     *
     * Reads the last stored `LIVE_ODDS` snapshot and only asks the provider
     * again once the live refresh interval has passed — the same cadence
     * discipline the live score board follows. A fixture that is not in play
     * is answered with its state, never with stale prices dressed as live.
     *
     * @return array<string,mixed>
     */
    public function live(int $fixtureId, bool $force = false): array
    {
        $fixture = $this->repo->findFixtureById($fixtureId);
        if ($fixture === null) {
            return ['state' => DataState::UNAVAILABLE, 'fixtureId' => $fixtureId,
                'reason' => 'Fixture ' . $fixtureId . ' is not stored.', 'markets' => [], 'generatedAt' => gmdate('c')];
        }
        $status = strtoupper((string) ($fixture['status'] ?? ''));
        $inPlay = in_array($status, FixtureSyncService::LIVE_STATUSES, true);
        $stored = $this->repo->findFixtureStatistics($fixtureId, 'LIVE_ODDS');
        $interval = max(30, $this->config->refreshInterval('live'));

        if (!$inPlay) {
            return [
                'state' => 'NOT_IN_PLAY', 'fixtureId' => $fixtureId,
                'fixture' => PredictionService::fixtureSummary($fixture),
                'reason' => 'This fixture is ' . ($status ?: 'UNKNOWN') . ', not in play — the in-play odds feed only '
                    . 'quotes live matches. The last stored snapshot (if any) is returned for reference.',
                'snapshot' => $this->decodeSnapshot($stored),
                'generatedAt' => gmdate('c'),
            ];
        }

        $age = $this->snapshotAgeSeconds($stored);
        if (!$force && $stored !== null && $age !== null && $age < $interval) {
            $snapshot = $this->decodeSnapshot($stored);
            return [
                'state' => DataState::AVAILABLE, 'fixtureId' => $fixtureId,
                'fixture' => PredictionService::fixtureSummary($fixture),
                'fetched' => false, 'ageSeconds' => $age, 'refreshIntervalSeconds' => $interval,
                'snapshot' => $snapshot,
                'generatedAt' => gmdate('c'),
            ];
        }

        $external = (string) ($fixture['external_id'] ?? '');
        $preferred = trim((string) ($fixture['provider_code'] ?? '')) ?: null;
        $this->gateway->beginSweep($this->config->requestBudget('live'));
        $outcome = $this->gateway->call('liveOdds', fn($provider) => $provider->liveOdds($external), $preferred);
        if (!$outcome['ok']) {
            return [
                'state' => $stored !== null ? DataState::LIMITED : DataState::UNAVAILABLE,
                'fixtureId' => $fixtureId,
                'fixture' => PredictionService::fixtureSummary($fixture),
                'reason' => 'No connected provider answered the in-play odds request; '
                    . ($stored !== null ? 'the last stored snapshot is returned.' : 'no snapshot is stored.'),
                'failures' => (array) $outcome['failures'],
                'fetched' => false,
                'snapshot' => $this->decodeSnapshot($stored),
                'generatedAt' => gmdate('c'),
            ];
        }
        $rows = is_array($outcome['result']) ? array_slice($outcome['result'], 0, self::LIVE_ROWS_CAP) : [];
        $snapshot = [
            'provider' => (string) $outcome['provider'],
            'observedAt' => gmdate('c'),
            'rows' => count($rows),
            // Match-level betting state as the feed reported it on any row.
            'stopped' => $this->anyFlag($rows, 'stopped'),
            'blocked' => $this->anyFlag($rows, 'blocked'),
            'markets' => $this->groupLiveRows($rows),
        ];
        $this->repo->saveFixtureStatistics($fixtureId, (int) ($fixture['provider_id'] ?? 0), 'LIVE_ODDS', $snapshot,
            ['state' => $rows === [] ? 'LIMITED_DATA' : 'AVAILABLE', 'rows' => count($rows)]);
        return [
            'state' => $rows === [] ? DataState::LIMITED : DataState::AVAILABLE,
            'fixtureId' => $fixtureId,
            'fixture' => PredictionService::fixtureSummary($fixture),
            'fetched' => true, 'ageSeconds' => 0, 'refreshIntervalSeconds' => $interval,
            'snapshot' => $snapshot,
            'reason' => $rows === [] ? (string) $outcome['provider'] . ' currently quotes no in-play odds for this fixture (betting may be suspended).' : null,
            'generatedAt' => gmdate('c'),
        ];
    }

    // ── vendor reference catalogs ────────────────────────────────────────────

    /**
     * The bookmaker catalog (`/odds/bookmakers`) — reference data, cached for
     * a week because the vendor updates it rarely.
     */
    public function bookmakers(bool $force = false): array
    {
        return $this->catalog('bookmakers', 'oddsBookmakers', fn($p) => $p->oddsBookmakers(), $force);
    }

    /**
     * A bet-type catalog. The vendor keeps two SEPARATE id systems —
     * `/odds/bets` ids filter only pre-match `/odds`, `/odds/live/bets` ids
     * filter only `/odds/live` — so the scope is explicit in the request and
     * in every returned row.
     */
    public function betTypes(string $scope = 'prematch', bool $force = false): array
    {
        $live = strtolower(trim($scope)) === 'live';
        return $this->catalog($live ? 'bets-live' : 'bets-prematch', 'oddsBetTypes',
            fn($p) => $p->oddsBetTypes($live), $force);
    }

    // ── internals ────────────────────────────────────────────────────────────

    /**
     * The stored odds rows for a fixture, with bookmaker detail when the
     * sports store is bound, degrading to the repository's bookmaker-less
     * market read when it is not.
     *
     * @return list<array<string,mixed>>
     */
    private function storedRows(array $fixture): array
    {
        $viaStore = $this->storeRows($fixture);
        if ($viaStore !== null) return $viaStore;
        $matchId = MatchFeed::matchId($fixture);
        $byMatch = $this->repo->listMarketOdds([$matchId]);
        $out = [];
        foreach ($byMatch[$matchId] ?? [] as $row) {
            $out[] = ['market' => (string) $row['market'], 'selection' => (string) $row['selection'],
                'decimalOdds' => (float) $row['decimalOdds'], 'observedAt' => $row['observedAt'] ?? null,
                'bookmaker' => null, 'provider' => $row['provider'] ?? null];
        }
        return $out;
    }

    /** @return list<array<string,mixed>>|null null = store not usable for this fixture */
    private function storeRows(array $fixture): ?array
    {
        if ($this->store === null) return null;
        $code = trim((string) ($fixture['provider_code'] ?? ''));
        $external = (string) ($fixture['external_id'] ?? '');
        if ($code === '' || $external === '') return null;
        try {
            $sourceId = $this->storeProviderId($code);
            if ($sourceId === null) return null;
            $match = $this->store->findMatch($sourceId, $external);
            if (!$match) return null;
            $out = [];
            foreach ($this->store->listOdds((int) $match['id'], 500) as $row) {
                $price = is_numeric($row['decimal_odds'] ?? null) ? (float) $row['decimal_odds'] : null;
                $market = trim((string) ($row['market'] ?? ''));
                if ($price === null || $market === '' || !OddsBounds::validDecimalOdds($price, $market)) continue;
                $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
                $out[] = [
                    'market' => $market,
                    'selection' => (string) ($row['selection'] ?? ''),
                    'decimalOdds' => $price,
                    'observedAt' => $row['observed_at'] ?? null,
                    'bookmaker' => isset($payload['bookmaker']) && trim((string) $payload['bookmaker']) !== '' ? (string) $payload['bookmaker'] : null,
                    'updatedAt' => $payload['updatedAt'] ?? null,
                    'provider' => $code,
                    'providerMarket' => $payload['providerMarket'] ?? null,
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            // A broken store read degrades to the repository read, not to a 500.
            return null;
        }
    }

    /** sports_data_sources id for a provider code (read-only — never creates on a view). */
    private function storeProviderId(string $code): ?int
    {
        foreach ((array) $this->store->listProviders() as $row) {
            if ((string) ($row['provider_code'] ?? '') === $code) return (int) $row['id'];
        }
        return null;
    }

    /**
     * Persist fetched pre-match rows through the SAME normalizer the sports
     * sync uses — validation identical, provenance identical.
     *
     * @param list<array<string,mixed>> $rows
     * @return array{stored:int, invalid:int, errors:list<string>}
     */
    private function persistRows(array $fixture, string $providerCode, array $rows): array
    {
        $stored = 0; $invalid = 0; $errors = [];
        try {
            $source = $this->store->ensureProvider($providerCode, $providerCode);
            $sourceId = (int) $source['id'];
            $external = (string) ($fixture['external_id'] ?? '');
            $match = $this->store->findMatch($sourceId, $external);
            if (!$match) {
                // Mirror the stored football fixture into the sports match store so
                // the odds rows have a row to hang off — every field comes from the
                // fixture the provider already delivered, nothing is invented.
                $match = $this->store->saveMatch($sourceId, [
                    'externalId' => $external,
                    'sport' => 'football',
                    'competition' => (string) ($fixture['competition'] ?? 'DATA_UNAVAILABLE'),
                    'homeTeam' => (string) ($fixture['home_team'] ?? ''),
                    'awayTeam' => (string) ($fixture['away_team'] ?? ''),
                    'kickoff' => (string) ($fixture['kickoff_at'] ?? gmdate('c')),
                    'status' => (string) ($fixture['status'] ?? 'SCHEDULED'),
                    'sourceTimestamp' => (string) ($fixture['source_timestamp'] ?? gmdate('c')),
                ]);
            }
            foreach ($rows as $raw) {
                try {
                    $this->store->saveOdds((int) $match['id'], $sourceId, SportsDataNormalizer::odds($raw, $providerCode));
                    $stored++;
                } catch (\Throwable $e) {
                    $invalid++;
                    if (count($errors) < 5) $errors[] = mb_substr($e->getMessage(), 0, 160);
                }
            }
        } catch (\Throwable $e) {
            $errors[] = 'odds persistence failed: ' . mb_substr($e->getMessage(), 0, 200);
        }
        return ['stored' => $stored, 'invalid' => $invalid, 'errors' => $errors];
    }

    /**
     * Group flat stored rows into market → selection → quotes, with the best
     * price, the quote count and a per-market freshness verdict.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function groupRows(array $rows): array
    {
        $byMarket = [];
        foreach ($rows as $row) {
            $market = (string) $row['market'];
            $selection = (string) $row['selection'];
            $byMarket[$market][$selection][] = $row;
        }
        $out = [];
        foreach ($byMarket as $market => $selections) {
            $maxAge = OddsFreshnessEngine::maxAgeFor($market);
            $marketNewest = null;
            $selOut = [];
            foreach ($selections as $selection => $quotes) {
                usort($quotes, static fn(array $a, array $b): int => strcmp((string) ($b['observedAt'] ?? ''), (string) ($a['observedAt'] ?? '')));
                // One row per bookmaker: the newest quote each book gave for this
                // selection. Books that never quoted it simply do not appear.
                $seen = [];
                $latest = [];
                foreach ($quotes as $q) {
                    $book = (string) ($q['bookmaker'] ?? '');
                    $key = $book !== '' ? $book : '·' . count($seen);
                    if (isset($seen[$key])) continue;
                    $seen[$key] = true;
                    $latest[] = $q;
                }
                $prices = array_map(static fn(array $q): float => (float) $q['decimalOdds'], $latest);
                $newest = (string) ($latest[0]['observedAt'] ?? '');
                if ($newest !== '' && ($marketNewest === null || strcmp($newest, $marketNewest) > 0)) $marketNewest = $newest;
                $selOut[] = [
                    'selection' => $selection,
                    'bestOdds' => $prices === [] ? null : max($prices),
                    'lowOdds' => $prices === [] ? null : min($prices),
                    'quoteCount' => count($latest),
                    'observedAt' => $latest[0]['observedAt'] ?? null,
                    'quotes' => array_map(static fn(array $q): array => [
                        'bookmaker' => $q['bookmaker'] ?? null,
                        'odds' => (float) $q['decimalOdds'],
                        'observedAt' => $q['observedAt'] ?? null,
                        'updatedAt' => $q['updatedAt'] ?? null,
                    ], $latest),
                ];
            }
            $age = $marketNewest !== null ? max(0, time() - (int) (strtotime($marketNewest) ?: time())) : null;
            $out[] = [
                'market' => $market,
                'selections' => $selOut,
                'observedAt' => $marketNewest,
                'ageSeconds' => $age,
                'maxAgeSeconds' => $maxAge,
                'freshness' => $age === null ? 'UNKNOWN' : ($age <= $maxAge ? 'FRESH' : 'STALE'),
            ];
        }
        usort($out, static fn(array $a, array $b): int => strcmp($a['market'], $b['market']));
        return $out;
    }

    /**
     * Group a live snapshot's rows per market, keeping the vendor's own
     * suspended / handicap / main flags on every price.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function groupLiveRows(array $rows): array
    {
        $byMarket = [];
        foreach ($rows as $row) {
            if (!is_numeric($row['decimalOdds'] ?? null)) continue;
            $market = (string) ($row['market'] ?? 'UNKNOWN');
            $entry = [
                'selection' => (string) ($row['selection'] ?? ''),
                'providerSelection' => (string) ($row['providerSelection'] ?? ''),
                'odds' => (float) $row['decimalOdds'],
                'suspended' => (bool) ($row['suspended'] ?? false),
            ];
            if (isset($row['handicap'])) $entry['handicap'] = (string) $row['handicap'];
            if (isset($row['main'])) $entry['main'] = (bool) $row['main'];
            $byMarket[$market]['values'][] = $entry;
            $byMarket[$market]['providerMarket'] = (string) ($row['providerMarket'] ?? $market);
            if (!empty($row['updatedAt'])) $byMarket[$market]['updatedAt'] = (string) $row['updatedAt'];
        }
        $out = [];
        foreach ($byMarket as $market => $data) {
            $values = $data['values'];
            $suspendedCount = count(array_filter($values, static fn(array $v): bool => $v['suspended']));
            $out[] = [
                'market' => $market,
                'providerMarket' => $data['providerMarket'] ?? $market,
                'updatedAt' => $data['updatedAt'] ?? null,
                'values' => $values,
                'quoted' => count($values),
                'suspended' => $suspendedCount,
            ];
        }
        usort($out, static fn(array $a, array $b): int => strcmp($a['market'], $b['market']));
        return $out;
    }

    /** @param list<array<string,mixed>> $rows */
    private function anyFlag(array $rows, string $flag): bool
    {
        foreach ($rows as $row) if (!empty($row[$flag])) return true;
        return false;
    }

    private function decodeSnapshot(?array $stored): ?array
    {
        if ($stored === null) return null;
        $payload = $stored['payload'] ?? null;
        if (is_string($payload)) $payload = json_decode($payload, true);
        if (!is_array($payload)) return null;
        $payload['storedAt'] = $stored['fetched_at'] ?? null;
        $payload['ageSeconds'] = $this->snapshotAgeSeconds($stored);
        return $payload;
    }

    private function snapshotAgeSeconds(?array $stored): ?int
    {
        $at = is_array($stored) ? (string) ($stored['fetched_at'] ?? '') : '';
        $time = $at !== '' ? strtotime($at) : false;
        return $time === false ? null : max(0, time() - $time);
    }

    /** Fetch-or-cache one vendor reference catalog. */
    private function catalog(string $kind, string $capability, callable $fn, bool $force): array
    {
        $file = rtrim($this->cacheDir, '/') . '/football_odds_catalog_' . $kind . '.json';
        if (!$force && is_file($file)) {
            $cached = json_decode((string) @file_get_contents($file), true);
            $at = is_array($cached) ? strtotime((string) ($cached['fetchedAt'] ?? '')) : false;
            if (is_array($cached) && $at !== false && (time() - $at) < self::CATALOG_TTL_SECONDS) {
                return ['state' => DataState::AVAILABLE, 'cached' => true, 'fetchedAt' => $cached['fetchedAt'],
                    'rows' => (array) ($cached['rows'] ?? []), 'count' => count((array) ($cached['rows'] ?? [])),
                    'generatedAt' => gmdate('c')];
            }
        }
        $this->gateway->beginSweep($this->config->requestBudget('statistics'));
        $outcome = $this->gateway->call($capability, $fn);
        if (!$outcome['ok']) {
            // Serve an expired cache honestly rather than nothing at all.
            $cached = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
            if (is_array($cached) && is_array($cached['rows'] ?? null)) {
                return ['state' => DataState::LIMITED, 'cached' => true, 'stale' => true,
                    'fetchedAt' => $cached['fetchedAt'] ?? null, 'rows' => $cached['rows'], 'count' => count($cached['rows']),
                    'reason' => 'The provider did not answer; an expired cached catalog is shown.',
                    'failures' => (array) $outcome['failures'], 'generatedAt' => gmdate('c')];
            }
            return ['state' => DataState::UNAVAILABLE, 'cached' => false, 'rows' => [], 'count' => 0,
                'reason' => 'No connected provider answered the catalog request and no cache exists.',
                'failures' => (array) $outcome['failures'], 'generatedAt' => gmdate('c')];
        }
        $rows = is_array($outcome['result']) ? $outcome['result'] : [];
        $fetchedAt = gmdate('c');
        @file_put_contents($file, json_encode(['fetchedAt' => $fetchedAt, 'provider' => $outcome['provider'], 'rows' => $rows]));
        return ['state' => DataState::AVAILABLE, 'cached' => false, 'fetchedAt' => $fetchedAt,
            'provider' => $outcome['provider'], 'rows' => $rows, 'count' => count($rows), 'generatedAt' => gmdate('c')];
    }
}
