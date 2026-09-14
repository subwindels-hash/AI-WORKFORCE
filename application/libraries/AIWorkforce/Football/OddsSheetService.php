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
        $preferred = trim((string) ($fixture['provider_code'] ?? '')) ?: null;
        $this->gateway->beginSweep($this->config->requestBudget('upcoming'));
        $outcome = $this->gateway->call('odds', fn($provider) => $provider->odds($external), $preferred);
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
        $rows = is_array($outcome['result']) ? $outcome['result'] : [];
        $persisted = $this->persistRows($fixture, $providerCode, $rows);
        $sheet = $this->sheet($fixtureId);
        $sheet['refreshed'] = ['provider' => $providerCode, 'fetched' => count($rows)] + $persisted;
        if ($rows === []) {
            $sheet['reason'] = ($sheet['reason'] ?? '') !== '' ? $sheet['reason']
                : $providerCode . ' returned no odds for this fixture (bookmakers may not have priced it yet).';
        }
        return $sheet;
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
