<?php
namespace AIWorkforce\Football;

/**
 * Which provider answers which part of a request.
 *
 * The prediction engine never talks to a provider directly, and never knows
 * which one it is: it asks for fixtures, statistics and odds and gets a
 * normalized answer. This class decides who to ask.
 *
 * Three modes:
 *
 *  - **Auto / Smart** — one provider is chosen for everything by a score built
 *    from what is actually observable: health, circuit-breaker and backoff
 *    state, quota head-room, capabilities, reliability and response time. The
 *    score and its components are returned, so "why this provider" is a
 *    readable answer rather than a coin flip.
 *  - **A named provider** — API-Football, TheSportsDB or SportMonks, used for
 *    everything it can serve. A data class it cannot serve falls back to
 *    another provider instead of being left empty.
 *  - **Multi-Provider** — one provider per data class: fixtures from whoever
 *    covers the competition, statistics and odds from whoever has them, team
 *    and league metadata from whoever supplies it. Nothing is requested twice:
 *    a class is fetched from the best provider that has it, and the plan
 *    records the source of each class so the provenance can be displayed.
 *
 * No mode calls a provider that is in backoff, circuit-open or out of quota,
 * and none of them calls three feeds to obtain the same fixture — the rule is
 * one request per piece of information, from the source most likely to have it.
 */
final class ProviderSelector
{
    /** Mode keywords a caller may send. */
    public const AUTO = 'AUTO';
    public const MULTI = 'MULTI';

    /**
     * In multi-provider mode, how much a provider's score is reduced for each
     * data class it already holds. Enough to hand the next class to another
     * feed, never enough to prefer a worse provider for a class only it can
     * serve.
     */
    public const MULTI_LOAD_PENALTY = 0.15;

    /** The pieces of information a request may need, in the order they are asked for. */
    public const DATA_CLASSES = ['fixtures', 'statistics', 'odds', 'headToHead', 'live', 'metadata'];

    /** Data class → the provider capability (and its method) that serves it. */
    private const CAPABILITY_FOR = [
        'fixtures' => 'fixtures',
        'statistics' => 'teamStatistics',
        'headToHead' => 'headToHead',
        'live' => 'live',
        'odds' => 'odds',
        'metadata' => 'standings',
    ];

    public function __construct(
        private ProviderGateway $gateway,
        private FootballConfiguration $config,
    ) {}

    /**
     * Resolve a requested provider into a plan.
     *
     * @param list<string> $notes
     * @return array{mode:string, requested:?string, providers:list<string>,
     *               plan:array<string,?string>, scores:array<string,array<string,mixed>>,
     *               state:string, reason:string}
     */
    public function resolve(?string $requested, array &$notes = []): array
    {
        $status = $this->gateway->status();
        $capabilities = $this->gateway->capabilities();
        $providers = array_keys($capabilities);
        $wanted = strtoupper(trim((string) $requested));
        if ($wanted === '') $wanted = self::AUTO;

        if ($providers === []) {
            return ['mode' => $wanted, 'requested' => $requested, 'providers' => [], 'plan' => $this->emptyPlan(),
                'scores' => [], 'state' => 'NOT_CONFIGURED',
                'reason' => 'No football data provider is configured. Fixtures, statistics and odds are all DATA_UNAVAILABLE until one is connected.'];
        }
        if ($wanted === self::MULTI) return $this->multi($providers, $capabilities, $status, $requested);
        if ($wanted === self::AUTO || $wanted === 'SMART') return $this->auto($providers, $capabilities, $status, $requested);

        return $this->named($wanted, $providers, $capabilities, $status, $requested, $notes);
    }

    /**
     * Auto: the best-scoring provider serves everything it can, and every class
     * it cannot serve falls back to the next provider that can.
     */
    private function auto(array $providers, array $capabilities, array $status, ?string $requested): array
    {
        $scores = $this->scores($providers, $capabilities, $status);
        $best = null;
        foreach ($scores as $id => $score) {
            if ($best === null || $score['total'] > $scores[$best]['total']) $best = (string) $id;
        }
        if ($best === null || (float) $scores[$best]['total'] <= 0.0) {
            return ['mode' => self::AUTO, 'requested' => $requested, 'providers' => [], 'plan' => $this->emptyPlan(),
                'scores' => $scores, 'state' => 'NO_USABLE_PROVIDER',
                'reason' => 'No configured provider is currently callable: every one is offline, in backoff or out of quota. Nothing was requested from any of them.'];
        }
        $plan = [];
        foreach (self::DATA_CLASSES as $class) {
            $plan[$class] = $this->pickFor($class, [$best], $capabilities, $status)
                ?? $this->pickFor($class, $providers, $capabilities, $status);
        }
        $used = array_values(array_unique(array_filter($plan)));
        return ['mode' => self::AUTO, 'requested' => $requested, 'providers' => $used, 'plan' => $plan,
            'scores' => $scores, 'state' => 'SELECTED',
            'reason' => 'Auto selected ' . $best . ' (score ' . $scores[$best]['total'] . ')'
                . ($used !== [$best] ? '; ' . implode(', ', array_diff($used, [$best])) . ' cover the data it cannot serve' : '') . '.'];
    }

    /**
     * Multi: one provider per data class, chosen by who can actually serve it.
     * The same fixture is never fetched from two feeds.
     */
    private function multi(array $providers, array $capabilities, array $status, ?string $requested): array
    {
        $plan = [];
        $missing = [];
        $load = [];
        foreach (self::DATA_CLASSES as $class) {
            $plan[$class] = $this->pickSpread($class, $providers, $capabilities, $status, $load);
            if ($plan[$class] !== null) $load[$plan[$class]][] = $class;
            else $missing[] = $class;
        }
        $used = array_values(array_unique(array_filter($plan)));
        return ['mode' => self::MULTI, 'requested' => $requested, 'providers' => $used, 'plan' => $plan,
            'scores' => $this->scores($providers, $capabilities, $status), 'state' => $used === [] ? 'NO_USABLE_PROVIDER' : 'SELECTED',
            'reason' => $used === []
                ? 'No configured provider can serve any part of this request.'
                : 'Multi-provider: ' . implode(', ', array_map(static fn(string $class): string =>
                    $class . '←' . (string) $plan[$class], array_keys(array_filter($plan))))
                    . ($missing === [] ? '.' : '; no provider offers ' . implode(', ', $missing) . '.')];
    }

    /**
     * Multi-provider pick: the best provider for the class, with a penalty for
     * every class it already holds.
     *
     * The penalty is what makes multi-provider multi. Without it one feed that
     * can do everything takes every class, which is just "Auto" with extra
     * steps — and it would spend that provider's whole quota and leave the
     * deployment dependent on a single vendor. Spreading the classes across
     * feeds is deliberate: the fixtures come from the best fixture feed, the
     * odds from another, and one outage or exhausted quota degrades the
     * combination instead of emptying the page.
     */
    private function pickSpread(string $class, array $providers, array $capabilities, array $status, array $load): ?string
    {
        $capability = self::CAPABILITY_FOR[$class] ?? $class;
        $best = null; $bestScore = -1.0;
        foreach ($providers as $id) {
            $id = (string) $id;
            if (empty($capabilities[$id][$capability])) continue;
            $health = (array) ($status['providers'][$id] ?? []);
            if ($this->unavailable($id, $health)) continue;
            $score = $this->scoreOne($id, $capabilities, $status)['total']
                - (self::MULTI_LOAD_PENALTY * count($load[$id] ?? []));
            if ($score > $bestScore) { $bestScore = $score; $best = $id; }
        }
        return $best;
    }

    /**
     * A named provider, with fallback for what it cannot serve. An unknown name
     * is reported; it is never silently treated as Auto, because that would
     * answer with a different provider than the operator chose.
     */
    private function named(string $wanted, array $providers, array $capabilities, array $status, ?string $requested, array &$notes): array
    {
        $id = null;
        foreach ($providers as $provider) {
            if (strtolower((string) $provider) === strtolower($wanted)) { $id = (string) $provider; break; }
        }
        if ($id === null) {
            $notes[] = 'provider=' . RequestParams::preview($requested) . ' is not one of the configured providers ('
                . implode(', ', $providers) . '); auto-selection was used instead.';
            $auto = $this->auto($providers, $capabilities, $status, $requested);
            $auto['mode'] = self::AUTO;
            return $auto;
        }
        $plan = [];
        $borrowed = [];
        foreach (self::DATA_CLASSES as $class) {
            $plan[$class] = $this->pickFor($class, [$id], $capabilities, $status);
            if ($plan[$class] === null) {
                $fallback = $this->pickFor($class, $providers, $capabilities, $status);
                $plan[$class] = $fallback;
                if ($fallback !== null) $borrowed[] = $class . '←' . $fallback;
            }
        }
        return ['mode' => $id, 'requested' => $requested,
            'providers' => array_values(array_unique(array_filter($plan))), 'plan' => $plan,
            'scores' => $this->scores($providers, $capabilities, $status), 'state' => 'SELECTED',
            'reason' => $borrowed === []
                ? $id . ' serves every part of this request.'
                : $id . ' serves this request; ' . implode(', ', $borrowed) . ' because ' . $id . ' has no data for them.'];
    }

    /**
     * The best provider for one data class: callable, capable and healthy.
     * Health comes first — a provider that is in backoff is not asked, however
     * good its coverage looks on paper.
     */
    private function pickFor(string $class, array $providers, array $capabilities, array $status): ?string
    {
        $capability = self::CAPABILITY_FOR[$class] ?? $class;
        $best = null; $bestScore = -1.0;
        foreach ($providers as $id) {
            $id = (string) $id;
            if (empty($capabilities[$id][$capability])) continue;
            $health = (array) ($status['providers'][$id] ?? []);
            if ($this->unavailable($id, $health)) continue;
            $score = $this->scoreOne($id, $capabilities, $status)['total'];
            if ($score > $bestScore) { $bestScore = $score; $best = $id; }
        }
        return $best;
    }

    /** @param list<string> $providers @return array<string,array<string,mixed>> */
    private function scores(array $providers, array $capabilities, array $status): array
    {
        $out = [];
        foreach ($providers as $id) $out[(string) $id] = $this->scoreOne((string) $id, $capabilities, $status);
        uasort($out, static fn(array $a, array $b) => $b['total'] <=> $a['total']);
        return $out;
    }

    /**
     * One provider's fitness, as named components so the choice can be
     * explained. Every component is measured from what the provider itself
     * reported; a value that was never reported scores neutral and is recorded
     * as unknown rather than as a good sign.
     *
     * @return array<string,mixed>
     */
    private function scoreOne(string $id, array $capabilities, array $status): array
    {
        $health = (array) ($status['providers'][$id] ?? []);
        $state = strtoupper((string) ($health['status'] ?? 'UNKNOWN'));
        $components = [
            'health' => match ($state) { 'ONLINE' => 1.0, 'DEGRADED' => 0.5, default => 0.0 },
            'quota' => $this->quota($health),
            'reliability' => is_numeric($health['reliability'] ?? null) ? round((float) $health['reliability'], 4) : 0.5,
            'latency' => $this->latency($health),
            'coverage' => $this->coverage($id, $capabilities),
            'odds' => !empty($capabilities[$id]['odds']) ? 1.0 : 0.0,
            'statistics' => !empty($capabilities[$id]['teamStatistics']) ? 1.0 : 0.0,
        ];
        $weights = ['health' => 0.30, 'quota' => 0.15, 'reliability' => 0.10, 'latency' => 0.10,
            'coverage' => 0.20, 'odds' => 0.075, 'statistics' => 0.075];
        $total = 0.0;
        foreach ($components as $key => $value) $total += (float) $value * $weights[$key];
        return [
            'status' => $state,
            'components' => $components,
            'weights' => $weights,
            'total' => round($total, 4),
            'callable' => !$this->unavailable($id, $health),
            'backoffUntil' => $health['backoffUntil'] ?? null,
            'requestsToday' => $health['requestsToday'] ?? null,
            'limitDaily' => $health['limitDaily'] ?? null,
        ];
    }

    /** Head-room against the provider's own daily limit; neutral when unknown. */
    private function quota(array $health): float
    {
        $limit = is_numeric($health['limitDaily'] ?? null) ? (float) $health['limitDaily'] : null;
        $used = is_numeric($health['requestsToday'] ?? null) ? (float) $health['requestsToday'] : null;
        if ($limit === null || $limit <= 0 || $used === null) return 0.5;
        return round(max(0.0, min(1.0, 1 - ($used / $limit))), 4);
    }

    /**
     * Response time turned into a score. A provider that has never been timed
     * is neutral, not fast: an unknown is not a good sign.
     */
    private function latency(array $health): float
    {
        // The adapters report `responseMs`; `responseTimeMs` is accepted from a
        // feed that names it that way. Neither present means unknown, and an
        // unknown is neutral, not fast.
        $ms = is_numeric($health['responseTimeMs'] ?? null) ? (float) $health['responseTimeMs']
            : (is_numeric($health['responseMs'] ?? null) ? (float) $health['responseMs'] : null);
        if ($ms === null) return 0.5;
        if ($ms <= 400) return 1.0;
        if ($ms >= 3000) return 0.0;
        return round(1 - (($ms - 400) / 2600), 4);
    }

    /** Share of the data classes this provider can serve. */
    private function coverage(string $id, array $capabilities): float
    {
        $total = 0; $have = 0;
        foreach (self::CAPABILITY_FOR as $capability) {
            $total++;
            if (!empty($capabilities[$id][$capability])) $have++;
        }
        return $total > 0 ? round($have / $total, 4) : 0.0;
    }

    /** A provider that is circuit-open, backed off or offline is not asked. */
    private function unavailable(string $id, array $health): bool
    {
        if ($this->gateway->inBackoff($id)) return true;
        $state = strtoupper((string) ($health['status'] ?? 'UNKNOWN'));
        return in_array($state, ['OFFLINE', 'AUTH', 'AUTHENTICATION_ERROR', 'BAD_REQUEST', 'NOT_FOUND', 'TIMEOUT'], true);
    }

    /** @return array<string,?string> */
    private function emptyPlan(): array
    {
        return array_fill_keys(self::DATA_CLASSES, null);
    }
}
