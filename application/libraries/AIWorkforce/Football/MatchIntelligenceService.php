<?php
namespace AIWorkforce\Football;

use AIWorkforce\Persistence\FootballRepository;
use AIWorkforce\Sports\SportsDataNormalizer;

/**
 * The Match Intelligence Engine: provider-agnostic match retrieval.
 *
 * Sits between the provider abstraction layer and the prediction engine:
 *
 *   provider adapters → normalization → **Match Intelligence Engine** →
 *   prediction engine → database/cache → 50 matches per page
 *
 * The engine owns three jobs, and no caller bypasses them:
 *
 *  1. **Selection** — which provider answers which part of the request
 *     (`ProviderSelector`), including multi-provider fan-out.
 *  2. **Normalization** — every row becomes the one `FootballMatch` model, so
 *     the prediction engine never sees a provider-specific shape.
 *  3. **Identity** — the same match from two feeds is resolved to one canonical
 *     record (`CanonicalMatch` + `football_provider_matches`), so a match is
 *     never generated twice because a second provider also carries it.
 *
 * Calls are spent deliberately: nothing is requested that is already stored
 * (cache/database first), one provider call per data class, and a provider that
 * is in backoff, out of quota or offline is skipped rather than retried.
 */
final class MatchIntelligenceService
{
    /** New predictions one generation request may create. Hard, not configurable. */
    public const MAX_GENERATION_BATCH = 50;

    /** @var array<string,int|null> provider code => its internal id, resolved once */
    private array $providerIds = [];

    public function __construct(
        private ProviderGateway $gateway,
        private ProviderSelector $selector,
        private FootballRepository $repo,
        private FootballConfiguration $config,
    ) {}

    /**
     * The provider catalogue behind the "Data Provider" dropdown.
     *
     * @return array{providers:list<array<string,mixed>>, options:list<array<string,mixed>>,
     *               state:string, message:?string}
     */
    public function providers(): array
    {
        $status = $this->gateway->status();
        $capabilities = $this->gateway->capabilities();
        $ids = array_keys($capabilities);
        // No modes at all when nothing is connected: a selector full of choices
        // nobody can honour is a lie dressed as a control.
        $options = $ids === [] ? [] : [
            ['value' => ProviderSelector::AUTO, 'label' => 'Auto / Smart Provider',
                'detail' => 'Pick the best available provider per request from health, coverage, odds and rate limits.'],
        ];
        foreach ($ids as $id) {
            $options[] = ['value' => (string) $id, 'label' => $this->displayName((string) $id),
                'detail' => 'Use ' . $this->displayName((string) $id) . ' for everything it can serve.'];
        }
        // Only meaningful with more than one feed connected: with one provider
        // there is nothing to combine, and offering it would be a lie.
        if (count($ids) > 1) {
            $options[] = ['value' => ProviderSelector::MULTI, 'label' => 'Multi-Provider',
                'detail' => 'Take each piece of data from the provider that has it and combine them into one match.'];
        }
        $providers = [];
        foreach ($ids as $id) {
            $health = (array) ($status['providers'][$id] ?? []);
            $providers[] = [
                'id' => (string) $id,
                'name' => $this->displayName((string) $id),
                'status' => (string) ($health['status'] ?? 'UNKNOWN'),
                'detail' => (string) ($health['detail'] ?? ''),
                'callable' => !$this->gateway->inBackoff((string) $id),
                'backoffUntil' => $health['backoffUntil'] ?? null,
                'reliability' => $health['reliability'] ?? null,
                'requestsToday' => $health['requestsToday'] ?? null,
                'limitDaily' => $health['limitDaily'] ?? null,
                'capabilities' => array_keys(array_filter((array) ($capabilities[$id] ?? []))),
            ];
        }
        return [
            'providers' => $providers,
            'options' => $options,
            'default' => ProviderSelector::AUTO,
            'state' => $ids === [] ? DataState::UNAVAILABLE : DataState::AVAILABLE,
            'message' => $ids === []
                ? 'No football data provider is connected. Connect API-Football, TheSportsDB or SportMonks to list matches and generate predictions.'
                : null,
        ];
    }

    /**
     * Per-provider health for `GET /api/football/providers/health`.
     *
     * Every field is what the provider itself reported; a value nobody reported
     * is stated as unknown rather than filled in as healthy.
     *
     * @return array<string,mixed>
     */
    public function health(): array
    {
        $status = $this->gateway->status();
        $capabilities = $this->gateway->capabilities();
        $providers = [];
        foreach (array_keys($capabilities) as $id) {
            $id = (string) $id;
            $health = (array) ($status['providers'][$id] ?? []);
            $mapped = $this->repo->listCompetitionMappings(['providerCode' => $id], 1000);
            $providers[] = [
                'id' => $id,
                'name' => $this->displayName($id),
                'status' => (string) ($health['status'] ?? 'UNKNOWN'),
                'detail' => (string) ($health['detail'] ?? ''),
                // The adapters report `responseMs` / `lastSuccessAt`; a feed that
                // reports it another way is shown as unknown rather than as fast.
                'responseTimeMs' => $health['responseTimeMs'] ?? $health['responseMs'] ?? null,
                'lastSuccessfulRequest' => $health['lastSuccessfulRequest'] ?? $health['lastSuccessAt'] ?? null,
                'lastError' => $health['lastError'] ?? null,
                'rateLimit' => [
                    'requestsToday' => $health['requestsToday'] ?? null,
                    'limitDaily' => $health['limitDaily'] ?? null,
                    'status' => $this->rateLimitStatus($id, $health),
                    'backoffUntil' => $health['backoffUntil'] ?? null,
                    'inBackoff' => $this->gateway->inBackoff($id),
                ],
                'reliability' => $health['reliability'] ?? null,
                'capabilities' => $capabilities[$id] ?? [],
                'oddsAvailable' => !empty($capabilities[$id]['odds']),
                'statisticsAvailable' => !empty($capabilities[$id]['teamStatistics']),
                'availableCompetitions' => count($mapped),
                'premiumCompetitions' => count(array_filter($mapped, static fn(array $row): bool => !empty($row['premium']))),
                // What this provider cannot supply is stated, not implied by a
                // missing key: the interface shows it as unavailable on purpose.
                'missingData' => array_values(array_filter(ProviderSelector::DATA_CLASSES,
                    static fn(string $class): bool => empty($capabilities[$id][$class]))),
            ];
        }
        $online = count(array_filter($providers, static fn(array $row): bool => $row['status'] === 'ONLINE'));
        return [
            'state' => $providers === [] ? 'NOT_CONFIGURED' : ($online > 0 ? 'CONNECTED' : 'DEGRADED'),
            'providers' => $providers,
            'total' => count($providers),
            'online' => $online,
            'generatedAt' => gmdate('c'),
        ];
    }

    /**
     * Competitions for a provider or for all of them, joined to the internal
     * competition mapping so the same league is one entry even when three
     * providers number it differently — and so "premium" is the deployment's
     * own classification, not a provider league id.
     *
     * @param list<string> $notes
     * @return array<string,mixed>
     */
    public function competitions(?string $provider = null, ?string $date = null, array &$notes = []): array
    {
        $selection = $this->selector->resolve($provider, $notes);
        $filter = [];
        if (is_string($provider) && strtoupper(trim($provider)) !== ProviderSelector::AUTO
            && strtoupper(trim($provider)) !== ProviderSelector::MULTI && trim($provider) !== '') {
            $filter['providerCode'] = strtolower(trim($provider));
        }
        $mappings = $this->repo->listCompetitionMappings($filter, 500);
        $rows = [];
        foreach ($mappings as $mapping) {
            $rows[] = [
                'internalId' => (string) ($mapping['internal_id'] ?? ''),
                'provider' => (string) ($mapping['provider_code'] ?? ''),
                'providerCompetitionId' => (string) ($mapping['provider_competition_id'] ?? ''),
                'name' => (string) ($mapping['competition_name'] ?? ''),
                'country' => $mapping['country'] ?? null,
                'tier' => (string) ($mapping['tier'] ?? 'STANDARD'),
                'premium' => !empty($mapping['premium']),
                'active' => !empty($mapping['active']),
            ];
        }
        usort($rows, static fn(array $a, array $b) => [(int) !$a['premium'], strcmp($a['name'], $b['name'])]
            <=> [(int) !$b['premium'], strcmp($b['name'], $a['name'])]);
        return [
            'competitions' => $rows,
            'premium' => array_values(array_filter($rows, static fn(array $row): bool => $row['premium'])),
            'total' => count($rows),
            'selection' => $selection,
            'state' => $rows === [] ? DataState::UNAVAILABLE : DataState::AVAILABLE,
            'message' => $rows === []
                ? 'No competition is mapped for this provider yet. Competitions are recorded when the provider is read; a competition list is never invented.'
                : null,
            'source' => 'COMPETITION_MAPPING',
        ];
    }

    /**
     * Fetch matches: select → retrieve → normalize → deduplicate → resolve
     * canonical identity. Returns the canonical matches in kickoff order.
     *
     * Query keys: provider, competition, date / dateFrom / dateTo, limit.
     *
     * @param array<string,mixed> $query
     * @param list<string> $notes
     * @return array{matches:list<FootballMatch>, selection:array<string,mixed>,
     *               counts:array<string,int>, calls:array<string,mixed>, state:string, message:?string}
     */
    public function matches(array $query, array &$notes = []): array
    {
        $limit = isset($query['limit']) && (int) $query['limit'] > 0
            ? min(self::MAX_GENERATION_BATCH, (int) $query['limit']) : self::MAX_GENERATION_BATCH;
        $selection = $this->selector->resolve($query['provider'] ?? null, $notes);
        $calls = ['made' => 0, 'providers' => [], 'failures' => []];
        if ($selection['state'] !== 'SELECTED') {
            return ['matches' => [], 'selection' => $selection, 'counts' => $this->counts(0, 0, 0),
                'calls' => $calls, 'state' => $selection['state'],
                'message' => $selection['reason']];
        }
        // Cache → database → provider. A date that is already stored and still
        // inside the fixture freshness window is served from those rows: the
        // same page, the same canonical identities and no provider call at all.
        // `refresh=1` is how an operator asks for the feed to be read anyway.
        if (empty($query['refresh'])) {
            $stored = $this->storedMatches($query, $notes);
            if ($stored !== null) {
                return [
                    'matches' => array_slice($stored, 0, max(1, $limit)),
                    'selection' => $selection,
                    'counts' => $this->counts(count($stored), 0, 0),
                    'calls' => ['made' => 0, 'providers' => [], 'failures' => [],
                        'source' => 'STORED_FIXTURES',
                        'note' => 'Served from the fixtures already stored for this window — they are inside the '
                            . $this->config->maxDataAgeSeconds('fixtures') . 's freshness window, so no provider was called.'],
                    'state' => $stored === [] ? DataState::UNAVAILABLE : DataState::AVAILABLE,
                    'message' => $stored === [] ? 'No match is stored for this window.' : null,
                ];
            }
        }
        $this->gateway->beginSweep($this->config->requestBudget('upcoming'));
        $matches = [];
        $duplicates = 0; $unusable = 0;
        foreach ($this->fixtureOrder($selection) as $providerId) {
            $parameters = $this->fixtureQuery($query);
            $outcome = $this->gateway->call('fixtures', fn($provider) => $provider->fixtures($parameters), $providerId);
            if (!$outcome['ok']) {
                $calls['failures'][$providerId] = is_array($outcome['failures'][$providerId] ?? null)
                    ? $outcome['failures'][$providerId] : (string) ($outcome['failures'][$providerId] ?? 'PROVIDER_CALL_FAILED');
                $notes[] = 'provider ' . $providerId . ' could not be read: '
                    . (string) ($outcome['failures'][$providerId] ?? 'PROVIDER_CALL_FAILED') . '.';
                continue;
            }
            $calls['made']++;
            $calls['providers'][(string) $outcome['provider']] = ($calls['providers'][(string) $outcome['provider']] ?? 0) + 1;
            $rows = is_array($outcome['result']) ? $outcome['result'] : [];
            foreach ($rows as $raw) {
                if (!is_array($raw)) { $unusable++; continue; }
                $resolved = $this->ingest($raw, (string) $outcome['provider'], $notes);
                if ($resolved === null) { $unusable++; continue; }
                [$canonicalId, $match] = $resolved;
                if (isset($matches[$canonicalId])) {
                    // Same match, second feed: merged, never duplicated.
                    $duplicates++;
                    $matches[$canonicalId] = $matches[$canonicalId]->merge($match, ['fixtures'], CanonicalMatch::MATCH_TEAMS_DATE, 1.0);
                    continue;
                }
                $matches[$canonicalId] = $match;
            }
            // Outside multi-provider mode the next feed is only asked while the
            // page is short: topping up is worth a call, re-fetching what is
            // already resolved is not.
            if ($selection['mode'] !== ProviderSelector::MULTI && count($matches) >= $limit) break;
        }
        $matches = $this->order($matches, $limit);
        $matches = $this->attachExtras($matches, $query, $selection, $notes);
        return [
            'matches' => $matches,
            'selection' => $selection,
            'counts' => $this->counts(count($matches), $duplicates, $unusable),
            'calls' => $calls,
            'state' => $matches === [] ? DataState::UNAVAILABLE : DataState::AVAILABLE,
            'message' => $matches === []
                ? 'No match came back from the selected provider. Nothing was substituted for a feed that returned no fixtures.'
                : null,
        ];
    }

    /**
     * Odds for one match and market, with fallback.
     *
     * The selected provider is asked first; when it has no price for this
     * competition or market, a second provider is asked — and the winner is
     * named in `source`, so the interface can show where a price came from
     * instead of implying the selected provider quoted it.
     *
     * @param array<string,mixed> $match
     * @param list<string> $notes
     * @return array{state:string, odds:array<string,mixed>, source:?string, attempted:list<string>, reason:?string}
     */
    public function odds(array $match, string $market, ?array $selection = null, array &$notes = []): array
    {
        $selection ??= $this->selector->resolve(null, $notes);
        $plan = (array) ($selection['plan'] ?? []);
        $order = array_values(array_filter([$plan['odds'] ?? null, ...array_keys((array) ($selection['scores'] ?? []))]));
        $attempted = [];
        $this->gateway->beginSweep($this->config->requestBudget('upcoming'));
        foreach ($order as $providerId) {
            $providerId = (string) $providerId;
            if (in_array($providerId, $attempted, true)) continue;
            if (!$this->gateway->supports('odds')) break;
            $attempted[] = $providerId;
            $external = (string) ($match['providers'][$providerId] ?? '');
            if ($external === '') continue;
            $outcome = $this->gateway->call('odds', fn($provider) => $provider->odds($external), $providerId);
            if (!$outcome['ok']) continue;
            $rows = is_array($outcome['result']) ? $outcome['result'] : [];
            $prices = $this->selectMarket($rows, $market);
            if ($prices === []) {
                $notes[] = $providerId . ' has no ' . $market . ' price for this match; another provider was tried.';
                continue;
            }
            return ['state' => DataState::AVAILABLE, 'odds' => $prices, 'source' => $providerId,
                'attempted' => $attempted, 'reason' => null];
        }
        return ['state' => DataState::UNAVAILABLE, 'odds' => [], 'source' => null, 'attempted' => $attempted,
            'reason' => $attempted === []
                ? 'No connected provider offers odds for this match.'
                : 'None of the providers tried (' . implode(', ', $attempted) . ') quoted ' . $market . ' for this match.'];
    }

    /**
     * Lineups for one match, with fallback.
     *
     * Lineups are per-match data, so this is called for a match that asked for
     * them, never for a whole page. The selected provider is tried first; when
     * it has no lineup for the match another feed is tried and named. A match
     * with no confirmed lineup anywhere comes back DATA_UNAVAILABLE — eleven
     * names are not invented from a squad list, and "no lineup announced yet"
     * is a real state.
     *
     * @param array<string,mixed> $match
     * @param list<string> $notes
     * @return array{state:string, lineups:list<array<string,mixed>>, source:?string, attempted:list<string>, reason:?string}
     */
    public function lineups(array $match, ?array $selection = null, array &$notes = []): array
    {
        $selection ??= $this->selector->resolve(null, $notes);
        $plan = (array) ($selection['plan'] ?? []);
        $order = array_values(array_filter([$plan['lineups'] ?? null, ...array_keys((array) ($selection['scores'] ?? []))]));
        $attempted = [];
        $this->gateway->beginSweep($this->config->requestBudget('upcoming'));
        foreach ($order as $providerId) {
            $providerId = (string) $providerId;
            if (in_array($providerId, $attempted, true)) continue;
            if (!$this->gateway->supports('lineups')) break;
            $attempted[] = $providerId;
            $external = (string) ($match['providers'][$providerId] ?? '');
            if ($external === '') continue;
            $outcome = $this->gateway->call('lineups', fn($provider) => $provider->lineups($external), $providerId);
            if (!$outcome['ok']) continue;
            $rows = is_array($outcome['result']) ? array_values(array_filter($outcome['result'], 'is_array')) : [];
            if ($rows === []) continue;
            return ['state' => DataState::AVAILABLE, 'lineups' => $rows, 'source' => $providerId,
                'attempted' => $attempted, 'reason' => null];
        }
        return ['state' => DataState::UNAVAILABLE, 'lineups' => [], 'source' => null, 'attempted' => $attempted,
            'reason' => $attempted === []
                ? 'No connected provider offers lineups.'
                : 'None of the providers tried (' . implode(', ', $attempted) . ') has a confirmed lineup for this match.'];
    }

    /**
     * Attach the per-match extras a caller asked for (`?with=lineups`).
     *
     * These cost one call per match, so they are opt-in, they are bounded by
     * the provider budget, and each match records whether it got one — a match
     * the budget or the feed could not cover says so instead of looking like a
     * match with no lineup announced.
     *
     * @param list<FootballMatch> $matches @param array<string,mixed> $query
     * @param list<string> $notes
     * @return list<FootballMatch>
     */
    private function attachExtras(array $matches, array $query, array $selection, array &$notes): array
    {
        $wanted = [];
        foreach ((array) ($query['with'] ?? []) as $extra) {
            if (is_string($extra) && trim($extra) !== '') $wanted[strtolower(trim($extra))] = true;
        }
        if (!isset($wanted['lineups']) || $matches === []) return $matches;
        $this->gateway->beginSweep($this->config->requestBudget('upcoming'));
        $out = [];
        $filled = 0;
        foreach ($matches as $match) {
            if ($this->gateway->requestsRemaining() <= 0) {
                $out[] = $match->withSource('none', ['lineups'], 'lineups', 'NOT_REQUESTED: the provider budget for this request was spent before this match was reached.');
                continue;
            }
            $row = $match->toArray();
            $result = $this->lineups($row, $selection, $notes);
            if ($result['state'] === DataState::AVAILABLE) {
                $filled++;
                $out[] = $match->withLineups($result['lineups'], (string) $result['source']);
                continue;
            }
            $out[] = $match->withSource('none', ['lineups'], 'lineups', $result['reason']);
        }
        $notes[] = 'lineups were requested for ' . count($matches) . ' match(es); '
            . $filled . ' had a confirmed lineup and ' . (count($matches) - $filled)
            . ' did not (no provider quoted one, or the request budget was spent).';
        return $out;
    }

    /**
     * The canonical matches a window already has stored, or null when the
     * stored rows cannot answer the window and the provider must be read.
     *
     * Three conditions, all of them checkable: the window resolves to dates,
     * at least one fixture is stored for it, and the newest stored row is still
     * inside the fixture freshness window. A stored row that is older than that
     * is not used as a cache — it is stale, and reading the feed is the honest
     * answer.
     *
     * @param array<string,mixed> $query @param list<string> $notes
     * @return list<FootballMatch>|null
     */
    private function storedMatches(array $query, array &$notes): ?array
    {
        $filter = [];
        if (!empty($query['date'])) $filter['date'] = (string) $query['date'];
        if (!empty($query['dateFrom'])) $filter['from'] = (string) $query['dateFrom'];
        if (!empty($query['dateTo'])) $filter['to'] = (string) $query['dateTo'];
        if (!empty($query['competition'])) $filter['competitionExternalId'] = (string) $query['competition'];
        if ($filter === []) return null;
        $rows = $this->repo->listFixtures($filter, self::MAX_GENERATION_BATCH * 4);
        if ($rows === []) return null;
        $newest = 0;
        foreach ($rows as $row) {
            $updated = strtotime((string) ($row['updated_at'] ?? ''));
            if ($updated !== false && $updated > $newest) $newest = $updated;
        }
        if ($newest <= 0) return null;
        $age = time() - $newest;
        if ($age > $this->config->maxDataAgeSeconds('fixtures')) return null;

        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            $canonical = CanonicalMatch::identity((string) ($row['home_team'] ?? ''), (string) ($row['away_team'] ?? ''),
                (string) ($row['kickoff_at'] ?? ''));
            if ($canonical === '' || isset($seen[$canonical])) continue;
            $seen[$canonical] = true;
            $match = FootballMatch::fromNormalized([
                'provider' => (string) ($row['provider_code'] ?? ''),
                'externalId' => (string) ($row['external_id'] ?? ''),
                'homeTeam' => (string) ($row['home_team'] ?? ''),
                'awayTeam' => (string) ($row['away_team'] ?? ''),
                'competition' => (string) ($row['competition'] ?? ''),
                'leagueId' => (string) ($row['competition_id'] ?? ''),
                'kickoff' => (string) ($row['kickoff_at'] ?? ''),
                'status' => (string) ($row['status'] ?? 'SCHEDULED'),
                'venue' => $row['venue'] ?? null,
                'country' => $row['country'] ?? null,
                'season' => $row['season'] ?? null,
            ], $canonical);
            if ($match === null) continue;
            $out[] = $match;
        }
        if ($out === []) return null;
        $notes[] = 'Served from the ' . count($out) . ' fixture(s) already stored for this window (newest row is '
            . $this->age($age) . ' old); no provider was called. Use refresh=1 to read the feed anyway.';
        return $this->order($out, self::MAX_GENERATION_BATCH * 4);
    }

    private function age(int $seconds): string
    {
        if ($seconds < 3600) return max(0, (int) round($seconds / 60)) . ' minutes';
        if ($seconds < 172800) return round($seconds / 3600, 1) . ' hours';
        return round($seconds / 86400, 1) . ' days';
    }

    /**
     * Normalize one provider row and resolve it to a canonical match.
     *
     * @param array<string,mixed> $raw
     * @param list<string> $notes
     * @return array{0:string,1:FootballMatch}|null
     */
    private function ingest(array $raw, string $providerCode, array &$notes = []): ?array
    {
        try {
            $normalized = SportsDataNormalizer::fixture($raw, $providerCode);
        } catch (\InvalidArgumentException $e) {
            return null;
        }
        $match = FootballMatch::fromNormalized($normalized);
        if ($match === null) return null;
        $candidate = [
            'providerCode' => $providerCode,
            'providerMatchId' => (string) ($match->providers[$providerCode] ?? ''),
            'homeTeam' => (string) ($match->homeTeam['name'] ?? ''),
            'awayTeam' => (string) ($match->awayTeam['name'] ?? ''),
            'kickoff' => $match->kickoffTime,
        ];
        $existing = $this->repo->resolveCanonicalMatch($candidate);
        $canonicalId = $match->id;
        $matchedBy = CanonicalMatch::MATCH_NEW;
        if ($existing !== null) {
            $canonicalId = (string) ($existing['row']['internal_match_id'] ?? $match->id);
            $matchedBy = (string) $existing['matchedBy'];
            if ($canonicalId !== $match->id) {
                $match = FootballMatch::fromNormalized(array_merge($raw, ['provider' => $providerCode]), $canonicalId);
            }
            $notes[] = 'merged ' . $providerCode . ' match ' . $candidate['providerMatchId'] . ' into ' . $canonicalId
                . ' (' . $matchedBy . ').';
        }
        $this->repo->saveProviderMatch([
            'internalMatchId' => $canonicalId,
            'providerCode' => $providerCode,
            'providerMatchId' => $candidate['providerMatchId'],
            'homeTeam' => $candidate['homeTeam'],
            'awayTeam' => $candidate['awayTeam'],
            'kickoff' => $candidate['kickoff'],
            'competitionInternalId' => $this->internalCompetitionId($match),
            'matchedBy' => $matchedBy,
            'confidence' => (float) ($existing['score'] ?? 1.0),
        ]);
        $this->recordCompetition($providerCode, $match);
        // What was read is cached in the fixtures table, so the next request
        // for the same window is answered from the database instead of the
        // feed. It is the provider's own row, stored as it arrived.
        $this->storeFixture($providerCode, $normalized);
        return [$canonicalId, $match];
    }

    /**
     * Cache one normalized fixture row in the fixtures table.
     *
     * Only a provider that a sync has already registered is written for: the
     * registry is what gives a provider its internal id, and the intelligence
     * engine does not invent provider rows on a read path.
     */
    private function storeFixture(string $providerCode, array $normalized): void
    {
        $providerId = $this->providerIdFor($providerCode);
        if ($providerId === null) return;
        $normalized['dataState'] = DataState::AVAILABLE;
        try {
            $this->repo->saveFixture($providerId, $normalized);
        } catch (\Throwable $e) {
            // A row the repository refuses is not worth failing a page for: the
            // match is still returned, it just is not cached.
        }
    }

    /**
     * The internal id of a registered provider, or null when a sync has not
     * registered one yet.
     */
    private function providerIdFor(string $providerCode): ?int
    {
        if (array_key_exists($providerCode, $this->providerIds)) return $this->providerIds[$providerCode];
        $id = null;
        foreach ($this->repo->listProviders() as $provider) {
            $code = (string) ($provider['provider_code'] ?? $provider['code'] ?? '');
            if ($code !== '' && strtolower($code) === strtolower($providerCode)) { $id = (int) ($provider['id'] ?? 0) ?: null; break; }
        }
        return $this->providerIds[$providerCode] = $id;
    }

    /**
     * Record the competition a provider row belongs to.
     *
     * The competition dropdown has to be populated from the provider that was
     * selected, and the same league is a different number in every feed — 39 in
     * API-Football, 1 in SportMonks, 4328 in TheSportsDB. Each row is therefore
     * mapped as it is read: the provider's own id, the name it uses, and this
     * deployment's classification (tier, premium, active). Premium is decided
     * here, by configuration, not by a provider league id.
     *
     * A row the provider gave no competition id for is not mapped: a mapping
     * invented from a name alone would merge two different leagues.
     */
    private function recordCompetition(string $providerCode, FootballMatch $match): void
    {
        $external = (string) ($match->competitionId ?? '');
        $name = (string) ($match->competitionName ?? '');
        if ($external === '' || $name === '') return;
        $premium = $this->config->isPremiumCompetition($name, $external);
        $this->repo->saveCompetitionMapping([
            'internalId' => $this->internalCompetitionId($match),
            'providerCode' => $providerCode,
            'providerCompetitionId' => $external,
            'competitionName' => $name,
            'country' => $match->country ?? null,
            'tier' => $premium ? 'PREMIUM' : 'STANDARD',
            'premium' => $premium,
            'active' => true,
        ]);
    }

    /**
     * The internal id of a competition: the normalized name, so the league is
     * the same entity whichever provider's id was read. "English Premier
     * League" and "Premier League" collapse to the same internal competition
     * because the mapping — not the provider's number — is what identifies it.
     */
    private function internalCompetitionId(FootballMatch $match): ?string
    {
        $name = (string) ($match->competitionName ?? '');
        if ($name === '') return null;
        // A premium league is identified by the name this deployment gave it,
        // so one feed's "English Premier League" and another's "Premier
        // League" are the same internal competition instead of two.
        $premium = $this->config->matchedPremium($name, (string) ($match->competitionId ?? ''));
        $slug = strtoupper(CanonicalMatch::slug($premium ?? $name));
        return $slug !== '' ? $slug : null;
    }

    /** @param array<int,array<string,mixed>> $rows @return array<string,mixed> */
    private function selectMarket(array $rows, string $market): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $label = strtoupper((string) ($row['market'] ?? $row['name'] ?? ''));
            if ($label !== '' && strtoupper($market) !== '' && $label !== strtoupper($market)) continue;
            $selection = (string) ($row['selection'] ?? $row['label'] ?? '');
            $price = $row['odds'] ?? $row['price'] ?? $row['value'] ?? null;
            if ($selection === '' || !is_numeric($price)) continue;
            $out[$selection] = round((float) $price, 3);
        }
        return $out;
    }

    /**
     * Which providers to ask for fixtures, in the order to ask them.
     *
     * - Auto / named: the selected provider first, and the others only if the
     *   page is still short — one call unless a top-up is actually needed.
     * - Multi: every provider the plan gave a data class to, once each. Their
     *   fixture lists are what let a match be recognized across feeds — the
     *   SportMonks id of a match API-Football calls 1201 can only be known by
     *   reading SportMonks — so this is the one case where more than one feed
     *   is read, and it is still one request per provider, never per match.
     */
    private function fixtureOrder(array $selection): array
    {
        $plan = (array) ($selection['plan'] ?? []);
        $scores = array_map('strval', array_keys((array) ($selection['scores'] ?? [])));
        if ($selection['mode'] === ProviderSelector::MULTI) {
            $contributing = array_values(array_unique(array_filter(array_map(
                static fn($id): string => is_string($id) ? $id : '', $plan))));
            $order = array_values(array_filter($scores, static fn(string $id): bool => in_array($id, $contributing, true)));
            return $order;
        }
        $order = [];
        if (($plan['fixtures'] ?? null) !== null) $order[] = (string) $plan['fixtures'];
        foreach ($scores as $id) $order[] = (string) $id;
        return array_values(array_unique(array_filter($order)));
    }

    /** @param array<string,mixed> $query @return array<string,mixed> */
    private function fixtureQuery(array $query): array
    {
        $parameters = [];
        if (!empty($query['date'])) $parameters['date'] = (string) $query['date'];
        if (!empty($query['dateFrom'])) $parameters['from'] = (string) $query['dateFrom'];
        if (!empty($query['dateTo'])) $parameters['to'] = (string) $query['dateTo'];
        if (!empty($query['competition'])) $parameters['league'] = (string) $query['competition'];
        if (!empty($query['season'])) $parameters['season'] = (string) $query['season'];
        if (!empty($query['team'])) $parameters['team'] = (string) $query['team'];
        return $parameters;
    }

    /** @param array<string,FootballMatch> $matches @return list<FootballMatch> */
    private function order(array $matches, int $limit): array
    {
        $matches = array_values($matches);
        usort($matches, static function (FootballMatch $a, FootballMatch $b): int {
            return [strtotime($a->kickoffTime) ?: PHP_INT_MAX, $a->id] <=> [strtotime($b->kickoffTime) ?: PHP_INT_MAX, $b->id];
        });
        return array_slice($matches, 0, max(1, $limit));
    }

    /** @return array<string,int> */
    private function counts(int $returned, int $duplicates, int $unusable): array
    {
        return ['returned' => $returned, 'duplicatesMerged' => $duplicates, 'unusable' => $unusable];
    }

    private function rateLimitStatus(string $id, array $health): string
    {
        if ($this->gateway->inBackoff($id)) return 'BACKOFF';
        $used = is_numeric($health['requestsToday'] ?? null) ? (float) $health['requestsToday'] : null;
        $limit = is_numeric($health['limitDaily'] ?? null) ? (float) $health['limitDaily'] : null;
        if ($used === null || $limit === null || $limit <= 0) return 'UNKNOWN';
        return $used >= $limit ? 'EXHAUSTED' : 'OK';
    }

    public static function displayNameStatic(string $id): string
    {
        return match (strtolower($id)) {
            'api-football', 'apifootball' => 'API-Football',
            'thesportsdb' => 'TheSportsDB',
            'sportmonks' => 'SportMonks',
            default => strtoupper($id),
        };
    }

    private function displayName(string $id): string
    {
        return self::displayNameStatic($id);
    }
}
