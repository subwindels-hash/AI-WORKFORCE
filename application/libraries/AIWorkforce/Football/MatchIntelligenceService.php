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
     * Normalize one provider row and resolve it to a canonical match.
     *
     * @param array<string,mixed> $raw
     * @param list<string> $notes
     * @return array{0:string,1:FootballMatch}|null
     */
    private function ingest(array $raw, string $providerCode, array &$notes = []): ?array
    {
        $match = FootballMatch::fromProviderRow($providerCode, $raw);
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
        return [$canonicalId, $match];
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
