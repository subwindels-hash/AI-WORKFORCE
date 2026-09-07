<?php
namespace AIWorkforce\TradingProtection;

use AIWorkforce\Persistence\AuditRepository;
use AIWorkforce\Notifications\Notifier;
use AIWorkforce\Persistence\PlatformStateRepository;

/**
 * AUTOMATIC KILL SWITCH — MT4/MT5 Expert Advisor integration (§10).
 *
 * An EA runs inside the terminal, on a Windows host, often with no operator in
 * front of it. This class is the platform side of that integration: it keeps a
 * registry of EA deployments, accepts their heartbeats (pulled from the MT5
 * bridge, so the terminal never has to reach the platform), evaluates every
 * condition the EA reports against the administrator's policy, and returns the
 * decision the EA must obey.
 *
 * The EA enforces locally too (it blocks its own orders before they reach the
 * broker) because protection must still work if the platform is unreachable.
 * That is deliberate defence in depth, and neither layer is optional:
 *
 *   EA (local gate)  ──►  terminal  ──►  broker
 *          ▲                                 ▲
 *          │                                 │
 *   bridge decision  ◄──  platform policy  ──┘   (ExecutionSupervisor step 1b)
 *
 * The 14 conditions every deployment is evaluated against:
 *
 *   1  terminal connection lost          8  repeated order-execution failures
 *   2  broker / trade server lost        9  daily loss — percentage
 *   3  market data unavailable          10  daily loss — fixed amount
 *   4  quote stale (tick age)           11  maximum drawdown
 *   5  abnormal price feed              12  margin level / free margin floor
 *   6  spread above maximum             13  high-impact news window
 *   7  slippage above maximum           14  decision or heartbeat unavailable
 *
 * §12 applies without exception: if the EA stops reporting, or the decision
 * cannot be computed, the deployment pauses. It is never assumed safe.
 */
final class EaProtection
{
    public const STATE_KEY = 'eaProtection';

    /** Default: an EA that has not reported for two minutes is not verifiable. */
    public const DEFAULT_HEARTBEAT_TIMEOUT_SECONDS = 120;

    /** @var array<array<string,mixed>> */
    public const DEFAULT_LIMITS = [
        'enabled' => true,
        'heartbeatTimeoutSeconds' => self::DEFAULT_HEARTBEAT_TIMEOUT_SECONDS,
        'marginLevelFloorPercent' => 150.0,
        'maxTickAgeSeconds' => 60,
        'abnormalPriceMovePercent' => 5.0,
        'requirePlatformDecision' => false,
    ];

    private const LIMIT_RULES = [
        'enabled' => ['bool'],
        'heartbeatTimeoutSeconds' => ['int', 10, 3600],
        'marginLevelFloorPercent' => ['float', 0.0, 100000.0],
        'maxTickAgeSeconds' => ['int', 1, 3600],
        'abnormalPriceMovePercent' => ['float', 0.0, 1000.0],
        'requirePlatformDecision' => ['bool'],
    ];

    public function __construct(
        private PlatformStateRepository $state,
        private AuditRepository $audit,
        private ?Notifier $notifier,
        /** @var (callable(): int)|null */
        private $clock = null,
    ) {}

    /** Inject a fixed clock (tests). */
    public function setClock(?callable $clock): void
    {
        $this->clock = $clock;
    }

    private function now(): int
    {
        return (int) ($this->clock ? call_user_func($this->clock) : time());
    }

    // ─── Registry ────────────────────────────────────────────────────

    /** @return array<string,mixed> every known deployment, keyed by EA id. */
    public function deployments(): array
    {
        $state = $this->state->load();
        return (array) ($state[self::STATE_KEY]['deployments'] ?? []);
    }

    /**
     * Register (or update) a deployment. Deployments are normally created by
     * the first heartbeat; this is the explicit path for administrators.
     */
    public function register(array $deployment): array
    {
        $id = $this->identifier($deployment);
        if ($id === '') {
            throw new \InvalidArgumentException('an EA deployment needs an id');
        }

        $state = $this->state->load();
        $existing = $state[self::STATE_KEY]['deployments'][$id] ?? [];
        $row = array_merge($existing, $this->normalizeDeployment($deployment, $id));
        $row['updatedAt'] = gmdate('c');
        $row['createdAt'] = $existing['createdAt'] ?? gmdate('c');
        $state[self::STATE_KEY]['deployments'][$id] = $row;
        $this->state->save($state);

        $this->audit->emit('EA_PROTECTION_REGISTERED', sprintf('Expert Advisor %s registered for protection (%s, account %s)', $id, (string) ($row['terminal'] ?? 'MT5'), (string) ($row['account'] ?? '—')), $row, 'system');
        return $row;
    }

    /** Stop tracking a deployment (the EA was removed from the terminal). */
    public function unregister(string $id): void
    {
        $state = $this->state->load();
        if (!isset($state[self::STATE_KEY]['deployments'][$id])) return;
        unset($state[self::STATE_KEY]['deployments'][$id], $state[self::STATE_KEY]['decisions'][$id], $state[self::STATE_KEY]['heartbeats'][$id]);
        $this->state->save($state);
        $this->audit->emit('EA_PROTECTION_UNREGISTERED', sprintf('Expert Advisor %s is no longer protected', $id), ['eaId' => $id], 'system');
    }

    /** Per-deployment policy override (merged over the platform policy). */
    public function setOverride(string $id, array $patch): array
    {
        $state = $this->state->load();
        if (!isset($state[self::STATE_KEY]['deployments'][$id])) {
            throw new \InvalidArgumentException("unknown Expert Advisor deployment: {$id}");
        }
        $limits = $this->normalizeLimits($patch, (array) ($state[self::STATE_KEY]['deployments'][$id]['limits'] ?? []));
        $state[self::STATE_KEY]['deployments'][$id]['limits'] = $limits;
        $state[self::STATE_KEY]['deployments'][$id]['updatedAt'] = gmdate('c');
        $this->state->save($state);
        $this->audit->emit('EA_PROTECTION_LIMITS_UPDATED', sprintf('Protection limits overridden for Expert Advisor %s', $id), ['eaId' => $id, 'limits' => $limits], 'system');
        return $limits;
    }

    // ─── Heartbeats ──────────────────────────────────────────────────

    /**
     * Ingest heartbeats pulled from the MT5 bridge (or posted by an EA).
     *
     * Unknown deployments are registered on first contact so a new EA is
     * protected from its first heartbeat instead of silently unmanaged.
     *
     * @param array<int,array<string,mixed>> $heartbeats
     * @return array{accepted:int, registered:array<int,string>, ids:array<int,string>}
     */
    public function ingest(array $heartbeats): array
    {
        $registered = [];
        $ids = [];
        $accepted = 0;
        $now = $this->now();

        foreach ($heartbeats as $heartbeat) {
            if (!is_array($heartbeat)) continue;
            $id = $this->identifier($heartbeat);
            if ($id === '') continue;

            $state = $this->state->load();
            $known = isset($state[self::STATE_KEY]['deployments'][$id]);
            if (!$known) {
                $this->register($heartbeat);
                $registered[] = $id;
            }
            $state = $this->state->load();
            $row = $this->normalizeHeartbeat($heartbeat, $id);
            $row['receivedAt'] = gmdate('c');
            $row['receivedAtTs'] = $now;
            $state[self::STATE_KEY]['heartbeats'][$id] = $row;
            $this->state->save($state);
            $ids[] = $id;
            $accepted++;
        }

        if ($registered !== []) {
            $this->audit->emit('EA_PROTECTION_DISCOVERED', sprintf('%d Expert Advisor(s) registered from their first heartbeat: %s', count($registered), implode(', ', $registered)), ['eaIds' => $registered], 'system');
        }
        return ['accepted' => $accepted, 'registered' => $registered, 'ids' => $ids];
    }

    /** @return array<string,mixed>|null the last heartbeat for a deployment */
    public function heartbeat(string $id): ?array
    {
        $state = $this->state->load();
        $row = $state[self::STATE_KEY]['heartbeats'][$id] ?? null;
        return is_array($row) ? $row : null;
    }

    // ─── Evaluation ──────────────────────────────────────────────────

    /**
     * Evaluate every deployment and persist its decision. Called by the
     * protection cron job and on demand by the admin screen.
     *
     * @return array<string,array<string,mixed>> decisions keyed by EA id
     */
    public function evaluateAll(): array
    {
        $state = $this->state->load();
        $policy = ProtectionPolicy::normalize(null, (array) ($state[AutomaticProtection::STATE_KEY]['policy'] ?? []));
        $decisions = [];

        foreach ($this->deployments() as $id => $deployment) {
            $decisions[$id] = $this->evaluateOne((string) $id, (array) $deployment, $policy);
        }

        $state = $this->state->load();
        $state[self::STATE_KEY]['decisions'] = $decisions;
        $state[self::STATE_KEY]['evaluatedAt'] = gmdate('c');
        $state[self::STATE_KEY]['evaluatedAtTs'] = $this->now();
        $this->state->save($state);

        return $decisions;
    }

    /**
     * Evaluate one deployment.
     *
     * @return array<string,mixed> the decision the EA must obey
     */
    public function evaluateOne(string $id, array $deployment, array $policy): array
    {
        $limits = $this->normalizeLimits([], (array) ($deployment['limits'] ?? []));
        $heartbeat = $this->heartbeat($id);
        $previous = $this->decision($id);
        $now = $this->now();

        // ── 14 — no heartbeat, or a stale one: protection cannot be verified.
        $timeout = (int) $limits['heartbeatTimeoutSeconds'];
        $age = $heartbeat === null ? null : $now - (int) ($heartbeat['receivedAtTs'] ?? 0);
        if ($heartbeat === null || $age === null || $age > $timeout) {
            return $this->publish($id, $deployment, $policy, [
                'state' => AutomaticProtection::PAUSED,
                'code' => 'EA_HEARTBEAT_STALE',
                'reason' => $heartbeat === null
                    ? 'This Expert Advisor has never reported — its conditions cannot be verified, so new trades are paused.'
                    : sprintf('No heartbeat for %d s (limit %d s) — the terminal or bridge stopped reporting, so new trades are paused.', $age, $timeout),
                'heartbeatAgeSeconds' => $age,
                'conditions' => [],
            ], $previous);
        }

        if (!($limits['enabled'] ?? true) || !($policy['enabled'] ?? true)) {
            return $this->publish($id, $deployment, $policy, [
                'state' => AutomaticProtection::NORMAL,
                'code' => 'PROTECTION_DISABLED',
                'reason' => 'Automatic protection is disabled for this deployment.',
                'heartbeatAgeSeconds' => $age,
                'conditions' => [],
            ], $previous);
        }

        $conditions = $this->conditions($heartbeat, $policy, $limits, $now);
        $worst = $this->worst($conditions);
        $target = $worst['state'] ?? AutomaticProtection::NORMAL;

        $previousState = (string) ($previous['state'] ?? AutomaticProtection::NORMAL);
        $unverified = !empty($previous['unverified']);

        // The very first look at a deployment is unverified, so there is no
        // breach to recover from: confirmation scans must never gate it (they
        // would refuse the first minutes of every restart). A clear first look
        // resumes; a first look that already sees a warning reports WARNING —
        // a warning states a fact, it never blocks trading.
        if ($unverified && !in_array($target, AutomaticProtection::BLOCKING, true)) {
            $next = $target === AutomaticProtection::NORMAL ? AutomaticProtection::RESUMED : $target;
        } else {
            $next = AutomaticProtection::resolveNextState($previousState, $target, $policy, (int) ($previous['clearScans'] ?? 0));
        }

        $decision = [
            'state' => $next,
            'code' => $worst['code'] ?? null,
            'reason' => $worst['reason'] ?? 'No risk condition reported by the terminal.',
            'heartbeatAgeSeconds' => $age,
            'conditions' => $conditions,
        ];
        return $this->publish($id, $deployment, $policy, $decision, $previous);
    }

    /**
     * The 14 conditions, evaluated from what the terminal reported.
     *
     * @return array<int,array<string,mixed>>
     */
    private function conditions(array $heartbeat, array $policy, array $limits, int $now): array
    {
        $conditions = [];
        $metrics = (array) ($heartbeat['metrics'] ?? []);
        $connection = (array) ($heartbeat['connection'] ?? []);
        $news = (array) ($heartbeat['news'] ?? []);
        $technical = $policy['technical'] ?? [];
        $escalate = (bool) ($technical['escalateToKill'] ?? false);
        $pause = AutomaticProtection::PAUSED;
        $kill = AutomaticProtection::KILL;

        $add = function (string $state, string $code, string $reason) use (&$conditions): void {
            $conditions[] = ['state' => $state, 'code' => $code, 'reason' => $reason];
        };

        // 1 — terminal connection (§4)
        if (array_key_exists('terminal', $connection) && !$connection['terminal']) {
            $add($escalate ? $kill : $pause, 'EA_TERMINAL_DISCONNECTED', 'MT4/MT5 terminal is not connected.');
        }
        // 2 — broker / trade server (§4)
        if (array_key_exists('broker', $connection) && !$connection['broker']) {
            $add($escalate ? $kill : $pause, 'EA_BROKER_DISCONNECTED', 'Trade server connection is down.');
        }
        // 3 — market data unavailable (§4)
        if (array_key_exists('dataFeed', $connection) && !$connection['dataFeed']) {
            $add($escalate ? $kill : $pause, 'EA_DATA_FEED_UNAVAILABLE', 'Market data feed is unavailable in the terminal.');
        }
        // 4 — stale quote (§4)
        $tickAge = (int) ($connection['lastTickAgeSeconds'] ?? -1);
        $maxTickAge = (int) $limits['maxTickAgeSeconds'];
        if (($technical['staleData'] ?? false) && $tickAge >= 0 && $tickAge > $maxTickAge) {
            $add($escalate ? $kill : $pause, 'EA_QUOTE_STALE', sprintf('Last tick is %d s old (limit %d s).', $tickAge, $maxTickAge));
        }
        // 5 — abnormal price feed (§4)
        $move = (float) ($metrics['priceMovePercent'] ?? 0.0);
        $maxMove = (float) $limits['abnormalPriceMovePercent'];
        if ($maxMove > 0.0 && abs($move) > $maxMove) {
            $add($pause, 'EA_ABNORMAL_PRICE', sprintf('Price moved %.2f%% in one tick (limit %.2f%%).', abs($move), $maxMove));
        }
        // 6 — spread (§5)
        if (($policy['spread']['enabled'] ?? false)) {
            $symbol = (string) ($metrics['symbol'] ?? $heartbeat['symbol'] ?? '');
            $points = $metrics['spreadPoints'] ?? null;
            if ($points === null || !is_numeric($points)) {
                if (!empty($policy['spread']['requireReading'])) {
                    $add($pause, 'EA_SPREAD_UNREADABLE', 'Spread could not be read for ' . ($symbol !== '' ? $symbol : 'the symbol') . '.');
                }
            } else {
                $max = ProtectionPolicy::maxSpreadPoints($policy, $symbol);
                if ($max > 0 && (float) $points > $max) {
                    $add($pause, 'EA_SPREAD_EXCEEDED', sprintf('Spread %.1f points exceeds the %.1f point limit.', (float) $points, $max));
                }
            }
        }
        // 7 — slippage (§6)
        if (($policy['slippage']['enabled'] ?? false)) {
            $points = $metrics['slippagePoints'] ?? null;
            $max = (float) ($policy['slippage']['maxPoints'] ?? 0.0);
            if ($max > 0 && is_numeric($points) && (float) $points > $max) {
                $add($pause, 'EA_SLIPPAGE_EXCEEDED', sprintf('Slippage %.1f points exceeds the %.1f point limit.', (float) $points, $max));
            }
        }
        // 8 — repeated order failures (§4)
        $failures = (int) ($metrics['orderFailures'] ?? 0);
        $maxFailures = (int) ($technical['maxConsecutiveOrderFailures'] ?? 0);
        if ($maxFailures > 0 && $failures >= $maxFailures) {
            $add($escalate ? $kill : $pause, 'EA_ORDER_FAILURES', sprintf('%d consecutive order failure(s).', $failures));
        }

        // 9/10 — daily loss, percentage and fixed amount (§2)
        if ($policy['dailyLoss']['enabled'] ?? false) {
            $equity = (float) ($metrics['equity'] ?? 0.0);
            $loss = -((float) ($metrics['dailyPnl'] ?? 0.0));
            if ($loss > 0) {
                $pctLimit = (float) ($policy['dailyLoss']['percentLimit'] ?? 0.0);
                $fixedLimit = $policy['dailyLoss']['fixedLimitUsd'] ?? null;
                $lossPct = $equity > 0 ? $loss / $equity : 0.0;
                $breachPct = $pctLimit > 0 && $lossPct >= $pctLimit;
                $breachFixed = $fixedLimit !== null && $loss >= (float) $fixedLimit;
                if ($breachPct || $breachFixed) {
                    $add($kill, 'EA_DAILY_LOSS_LIMIT', $breachFixed
                        ? sprintf('Daily loss $%s reached the fixed limit of $%s.', number_format($loss, 2), number_format((float) $fixedLimit, 2))
                        : sprintf('Daily loss reached %.2f%% of equity (limit %.2f%%).', $lossPct * 100, $pctLimit * 100));
                } else {
                    $warnAt = (float) ($policy['dailyLoss']['warnAtFraction'] ?? 0.8);
                    $nearest = $pctLimit > 0 ? $lossPct / $pctLimit : 0.0;
                    if ($fixedLimit !== null && (float) $fixedLimit > 0) $nearest = max($nearest, $loss / (float) $fixedLimit);
                    if ($warnAt > 0 && $nearest >= $warnAt) {
                        $add(AutomaticProtection::WARNING, 'EA_DAILY_LOSS_APPROACHING', sprintf('Daily loss at %.0f%% of the configured limit.', min(100.0, $nearest * 100)));
                    }
                }
            }
        }

        // 11 — maximum drawdown (§3)
        if ($policy['drawdown']['enabled'] ?? false) {
            $limit = (float) ($policy['drawdown']['percentLimit'] ?? 0.0);
            $drawdown = (float) ($metrics['drawdownPct'] ?? 0.0);      // percent, as the EA reports it
            if ($limit > 0 && $drawdown > 0) {
                $fraction = $drawdown / 100.0;
                if ($fraction >= $limit) {
                    $add($kill, 'EA_MAX_DRAWDOWN', sprintf('Drawdown %.2f%% reached the %.2f%% limit.', $drawdown, $limit * 100));
                } else {
                    $warnAt = (float) ($policy['drawdown']['warnAtFraction'] ?? 0.8);
                    if ($warnAt > 0 && ($fraction / $limit) >= $warnAt) {
                        $add(AutomaticProtection::WARNING, 'EA_DRAWDOWN_APPROACHING', sprintf('Drawdown at %.0f%% of the configured maximum.', min(100.0, ($fraction / $limit) * 100)));
                    }
                }
            }
        }

        // 12 — margin level / free margin floor (stop-out protection)
        $marginLevel = $metrics['marginLevelPct'] ?? null;
        $floor = (float) $limits['marginLevelFloorPercent'];
        if ($floor > 0 && is_numeric($marginLevel) && (float) $marginLevel > 0 && (float) $marginLevel < $floor) {
            $add($pause, 'EA_MARGIN_LEVEL_LOW', sprintf('Margin level %.0f%% is below the %.0f%% floor.', (float) $marginLevel, $floor));
        }

        // 13 — high-impact news window (§1)
        if ($policy['news']['enabled'] ?? false) {
            $minutes = $news['minutesToNextHighImpact'] ?? null;
            if (is_numeric($minutes)) {
                $before = (int) ($policy['news']['minutesBefore'] ?? 5);
                $after = (int) ($policy['news']['minutesAfter'] ?? 30);
                $lead = (int) ($policy['news']['warningLeadMinutes'] ?? 15);
                $minutes = (float) $minutes;
                if ($minutes <= $before && $minutes >= -$after) {
                    $add($pause, 'EA_NEWS_EVENT', sprintf('High-impact event %s.', $minutes >= 0
                        ? sprintf('in %.0f minute(s)', ceil($minutes))
                        : sprintf('%.0f minute(s) ago', abs($minutes))));
                } elseif ($minutes > $before && $minutes <= $before + $lead) {
                    $add(AutomaticProtection::WARNING, 'EA_NEWS_APPROACHING', sprintf('High-impact event in %.0f minute(s).', ceil($minutes)));
                }
            } elseif (($news['ok'] ?? true) === false) {
                if (($policy['news']['onFeedFailure'] ?? 'pause') === 'pause') {
                    $add($pause, 'EA_NEWS_FEED_UNAVAILABLE', 'The terminal cannot read the economic calendar, so an event cannot be ruled out.');
                } else {
                    $add(AutomaticProtection::WARNING, 'EA_NEWS_FEED_UNAVAILABLE', 'The terminal cannot read the economic calendar.');
                }
            }
        }

        return $conditions;
    }

    /** The condition that decides the state: highest severity wins. */
    private function worst(array $conditions): ?array
    {
        $worst = null;
        foreach ($conditions as $condition) {
            if ($worst === null || $this->severity($condition['state']) >= $this->severity($worst['state'])) {
                $worst = $condition;
            }
        }
        return $worst;
    }

    private function severity(string $state): int
    {
        return match ($state) {
            AutomaticProtection::NORMAL => 0,
            AutomaticProtection::RESUMED => 1,
            AutomaticProtection::WARNING => 2,
            AutomaticProtection::RECOVERY => 3,
            AutomaticProtection::PAUSED => 4,
            AutomaticProtection::KILL => 5,
            default => 0,
        };
    }

    // ─── Decisions ───────────────────────────────────────────────────

    /** @return array<string,mixed> the last published decision (paused when unknown). */
    public function decision(string $id): array
    {
        $state = $this->state->load();
        $decision = $state[self::STATE_KEY]['decisions'][$id] ?? null;
        if (is_array($decision)) return $decision;

        return [
            'eaId' => $id,
            'state' => AutomaticProtection::PAUSED,
            'code' => 'EA_DECISION_UNAVAILABLE',
            'reason' => 'No protection decision has been published yet — new trades are paused until one exists.',
            'allowNewTrades' => false,
            'closePositions' => false,
            'cancelPendingOrders' => false,
            'unverified' => true,
            'since' => gmdate('c'),
            'evaluatedAt' => null,
            'heartbeatAgeSeconds' => null,
            'clearScans' => 0,
        ];
    }

    /** @return array<string,array<string,mixed>> */
    public function decisions(): array
    {
        $state = $this->state->load();
        return (array) ($state[self::STATE_KEY]['decisions'] ?? []);
    }

    /**
     * Persist a decision and notify on transitions.
     *
     * @return array<string,mixed> the decision, ready to be sent to the bridge
     */
    private function publish(string $id, array $deployment, array $policy, array $decision, array $previous): array
    {
        $blocking = in_array($decision['state'], AutomaticProtection::BLOCKING, true);
        $emergency = $policy['emergency'] ?? [];
        $close = $decision['state'] === AutomaticProtection::KILL && !empty($emergency['closePositionsOnKill']);
        $cancel = $decision['state'] === AutomaticProtection::KILL && !empty($emergency['cancelPendingOrdersOnKill']);

        $published = [
            'eaId' => $id,
            'name' => (string) ($deployment['name'] ?? $id),
            'state' => $decision['state'],
            'code' => $decision['code'] ?? null,
            'reason' => $decision['reason'] ?? '',
            'allowNewTrades' => !$blocking,
            'closePositions' => $close,
            'cancelPendingOrders' => $cancel,
            'unverified' => false,
            'since' => ($decision['state'] === ($previous['state'] ?? null)) ? ($previous['since'] ?? gmdate('c')) : gmdate('c'),
            'previousState' => $previous['state'] ?? null,
            'evaluatedAt' => gmdate('c'),
            'evaluatedAtTs' => $this->now(),
            'heartbeatAgeSeconds' => $decision['heartbeatAgeSeconds'] ?? null,
            'conditions' => $decision['conditions'] ?? [],
            'clearScans' => $decision['state'] === AutomaticProtection::RECOVERY ? ((int) ($previous['clearScans'] ?? 0) + 1) : 0,
        ];

        $state = $this->state->load();
        $state[self::STATE_KEY]['decisions'][$id] = $published;
        $this->state->save($state);

        if ($published['state'] !== ($previous['state'] ?? null)) {
            $this->audit->emit('EA_PROTECTION_' . $published['state'],
                sprintf('Expert Advisor %s → %s: %s', $id, $published['state'], $published['reason']),
                [
                    'eaId' => $id, 'terminal' => $deployment['terminal'] ?? null, 'account' => $deployment['account'] ?? null,
                    'broker' => $deployment['broker'] ?? null, 'symbol' => $deployment['symbol'] ?? null,
                    'from' => $previous['state'] ?? null, 'to' => $published['state'], 'code' => $published['code'],
                    'reason' => $published['reason'],
                    'conditions' => array_map(fn(array $c): array => ['code' => $c['code'], 'state' => $c['state'], 'reason' => $c['reason']], $published['conditions']),
                ],
                'system');

            $this->notifier?->notify('EA_PROTECTION',
                $published['state'] === AutomaticProtection::KILL ? 'critical' : ($blocking ? 'warning' : 'info'),
                sprintf('Expert Advisor %s — %s: %s', (string) ($deployment['name'] ?? $id), $published['state'], $published['reason']),
                ['eaId' => $id, 'state' => $published['state'], 'code' => $published['code']],
                'ea-protection:' . $id . ':' . $published['state']);
        }

        return $published;
    }

    // ─── Operator surface ────────────────────────────────────────────

    /** Summary for the admin screen and the API: deployment + decision + age. */
    public function status(): array
    {
        $decisions = $this->decisions();
        $rows = [];
        foreach ($this->deployments() as $id => $deployment) {
            $decision = $decisions[$id] ?? $this->decision((string) $id);
            $heartbeat = $this->heartbeat((string) $id);
            $rows[] = [
                'id' => (string) $id,
                'name' => (string) ($deployment['name'] ?? $id),
                'terminal' => (string) ($deployment['terminal'] ?? 'MT5'),
                'account' => (string) ($deployment['account'] ?? ''),
                'broker' => (string) ($deployment['broker'] ?? ''),
                'symbol' => (string) ($deployment['symbol'] ?? ''),
                'magic' => $deployment['magic'] ?? null,
                'state' => $decision['state'],
                'code' => $decision['code'] ?? null,
                'reason' => $decision['reason'] ?? '',
                'allowNewTrades' => !empty($decision['allowNewTrades']),
                'blocking' => in_array($decision['state'], AutomaticProtection::BLOCKING, true),
                'since' => $decision['since'] ?? null,
                'evaluatedAt' => $decision['evaluatedAt'] ?? null,
                'heartbeatAt' => $heartbeat['at'] ?? null,
                'heartbeatAgeSeconds' => $decision['heartbeatAgeSeconds'] ?? null,
                'conditions' => $decision['conditions'] ?? [],
                'limits' => $this->normalizeLimits([], (array) ($deployment['limits'] ?? [])),
            ];
        }

        usort($rows, fn(array $a, array $b): int => ($b['blocking'] <=> $a['blocking']) ?: ($a['name'] <=> $b['name']));

        $state = $this->state->load();
        return [
            'deployments' => $rows,
            'total' => count($rows),
            'blocked' => count(array_filter($rows, fn(array $r): bool => $r['blocking'])),
            'evaluatedAt' => $state[self::STATE_KEY]['evaluatedAt'] ?? null,
        ];
    }

    // ─── Normalisation ───────────────────────────────────────────────

    private function identifier(array $row): string
    {
        $id = trim((string) ($row['eaId'] ?? $row['id'] ?? ''));
        if ($id === '') return '';
        return preg_replace('/[^A-Za-z0-9._:-]/', '', $id) ?? '';
    }

    private function normalizeDeployment(array $row, string $id): array
    {
        return [
            'eaId' => $id,
            'name' => mb_substr(trim((string) ($row['name'] ?? $id)), 0, 80),
            'terminal' => in_array(strtoupper((string) ($row['terminal'] ?? 'MT5')), ['MT4', 'MT5'], true) ? strtoupper((string) ($row['terminal'] ?? 'MT5')) : 'MT5',
            'account' => mb_substr(trim((string) ($row['account'] ?? '')), 0, 40),
            'broker' => mb_substr(trim((string) ($row['broker'] ?? '')), 0, 60),
            'symbol' => strtoupper(mb_substr(trim((string) ($row['symbol'] ?? '')), 0, 32)),
            'magic' => is_numeric($row['magic'] ?? null) ? (int) $row['magic'] : null,
            'version' => mb_substr(trim((string) ($row['version'] ?? '')), 0, 20),
            'limits' => $this->normalizeLimits((array) ($row['limits'] ?? []), []),
        ];
    }

    private function normalizeHeartbeat(array $row, string $id): array
    {
        $metrics = (array) ($row['metrics'] ?? []);
        return [
            'eaId' => $id,
            'at' => is_string($row['at'] ?? null) ? $row['at'] : gmdate('c'),
            'atTs' => is_numeric($row['atTs'] ?? null) ? (int) $row['atTs'] : $this->now(),
            'metrics' => [
                'equity' => (float) ($metrics['equity'] ?? 0.0),
                'balance' => (float) ($metrics['balance'] ?? 0.0),
                'dailyPnl' => (float) ($metrics['dailyPnl'] ?? 0.0),
                'drawdownPct' => (float) ($metrics['drawdownPct'] ?? 0.0),
                'peakEquity' => (float) ($metrics['peakEquity'] ?? 0.0),
                'openPositions' => (int) ($metrics['openPositions'] ?? 0),
                'pendingOrders' => (int) ($metrics['pendingOrders'] ?? 0),
                'marginLevelPct' => isset($metrics['marginLevelPct']) ? (float) $metrics['marginLevelPct'] : null,
                'freeMargin' => isset($metrics['freeMargin']) ? (float) $metrics['freeMargin'] : null,
                'spreadPoints' => isset($metrics['spreadPoints']) ? (float) $metrics['spreadPoints'] : null,
                'slippagePoints' => isset($metrics['slippagePoints']) ? (float) $metrics['slippagePoints'] : null,
                'priceMovePercent' => isset($metrics['priceMovePercent']) ? (float) $metrics['priceMovePercent'] : null,
                'orderFailures' => (int) ($metrics['orderFailures'] ?? 0),
                'symbol' => strtoupper((string) ($metrics['symbol'] ?? $row['symbol'] ?? '')),
            ],
            'connection' => [
                'terminal' => (bool) ($row['connection']['terminal'] ?? true),
                'broker' => (bool) ($row['connection']['broker'] ?? true),
                'dataFeed' => (bool) ($row['connection']['dataFeed'] ?? true),
                'lastTickAgeSeconds' => isset($row['connection']['lastTickAgeSeconds']) ? (int) $row['connection']['lastTickAgeSeconds'] : null,
            ],
            'news' => [
                'configured' => (bool) ($row['news']['configured'] ?? false),
                'ok' => (bool) ($row['news']['ok'] ?? true),
                'minutesToNextHighImpact' => isset($row['news']['minutesToNextHighImpact']) ? (float) $row['news']['minutesToNextHighImpact'] : null,
            ],
            'actions' => [
                'closedPositions' => (int) ($row['actions']['closedPositions'] ?? 0),
                'cancelledOrders' => (int) ($row['actions']['cancelledOrders'] ?? 0),
                'blockedOrders' => (int) ($row['actions']['blockedOrders'] ?? 0),
            ],
            'version' => mb_substr(trim((string) ($row['version'] ?? '')), 0, 20),
        ];
    }

    private function normalizeLimits(array $patch, array $base = []): array
    {
        $limits = array_merge(self::DEFAULT_LIMITS, $base);
        foreach ($patch as $key => $value) {
            if (!isset(self::LIMIT_RULES[$key])) continue;
            $rule = self::LIMIT_RULES[$key];
            $limits[$key] = match ($rule[0]) {
                'bool' => (bool) filter_var($value, FILTER_VALIDATE_BOOLEAN),
                'int' => (int) max($rule[1], min($rule[2], (float) (is_numeric($value) ? $value : 0))),
                'float' => (float) max($rule[1], min($rule[2], (float) (is_numeric($value) ? $value : 0))),
                default => $limits[$key],
            };
        }
        return $limits;
    }
}
