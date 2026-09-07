<?php
namespace AIWorkforce\TradingProtection;

use AIWorkforce\Brokers\BrokerManager;
use AIWorkforce\Brokers\TradingConnector;
use AIWorkforce\Notifications\Notifier;
use AIWorkforce\Paper\PaperTradingEngine;
use AIWorkforce\Persistence\AuditRepository;
use AIWorkforce\Persistence\PaperRepository;
use AIWorkforce\Persistence\PlatformStateRepository;
use AIWorkforce\ProviderManager;

/**
 * AUTOMATIC KILL SWITCH (no manual switch exists anywhere in the product).
 *
 * Continuously monitors trading conditions and stops new trading by itself the
 * moment a configured risk or market condition is met (§1–§6), moves through
 * the seven automatic states (§7), refuses every new trade while protection is
 * active (§11), fails safe when conditions cannot be verified (§12) and writes
 * an audit record for every transition (§13).
 *
 * There is deliberately NO public "engage" or "release" method: the kill
 * switch state is derived, never commanded. The engine drives the platform's
 * existing order gate (`state.killSwitch`) so every order path — execution
 * supervisor, paper engine, broker routing and the trading API — is covered by
 * one mechanism instead of a second parallel one.
 */
final class AutomaticProtection
{
    public const NORMAL = 'NORMAL';
    public const WARNING = 'WARNING';
    public const PAUSED = 'AUTOMATIC_PAUSED';
    public const KILL = 'AUTOMATIC_KILL';
    public const RECOVERY = 'RECOVERY';
    public const RESUMED = 'RESUMED';

    /** Lowest → highest. Used to pick the worst condition of a scan. */
    private const SEVERITY = [
        self::NORMAL => 0,
        self::RESUMED => 1,
        self::WARNING => 2,
        self::RECOVERY => 3,
        self::PAUSED => 4,
        self::KILL => 5,
    ];

    /** States in which no new trade may be opened. */
    public const BLOCKING = [self::PAUSED, self::KILL, self::RECOVERY];

    public const STATE_KEY = 'automaticProtection';

    public function __construct(
        private PlatformStateRepository $state,
        private PaperRepository $paperRepo,
        private PaperTradingEngine $paper,
        private BrokerManager $brokers,
        private AuditRepository $audit,
        private ?Notifier $notifier,
        private EconomicCalendar $calendar,
        private ?ProviderManager $providers = null,
        /** @var (callable(): int)|null */
        private $clock = null,
    ) {}

    /** Inject a fixed clock (tests, deterministic news windows). */
    public function setClock(?callable $clock): void
    {
        $this->clock = $clock;
    }

    /** Inject a calendar source (tests, and swapping the feed implementation). */
    public function setCalendar(EconomicCalendar $calendar): void
    {
        $this->calendar = $calendar;
    }

    private function now(): int
    {
        return (int) ($this->clock ? call_user_func($this->clock) : time());
    }

    // ─── Policy ──────────────────────────────────────────────────────

    public function policy(): array
    {
        $state = $this->state->load();
        return ProtectionPolicy::normalize(null, $state[self::STATE_KEY]['policy'] ?? []);
    }

    /** Validate + persist an administrator patch; returns the full policy. */
    public function updatePolicy(array $patch): array
    {
        $state = $this->state->load();
        $current = ProtectionPolicy::normalize(null, $state[self::STATE_KEY]['policy'] ?? []);
        $next = ProtectionPolicy::normalize($patch, $current);
        $state[self::STATE_KEY]['policy'] = $next;
        $this->state->save($state);
        $this->audit->emit('PROTECTION_POLICY_UPDATED', 'Automatic Kill Switch policy updated', ['policy' => $next], 'system');
        return $next;
    }

    // ─── Status ──────────────────────────────────────────────────────

    /** Current persisted status without rescanning. */
    public function status(): array
    {
        $state = $this->state->load();
        return $state[self::STATE_KEY]['status'] ?? self::unverifiedStatus('Protection has not been evaluated yet.');
    }

    /** @return array<string,mixed> */
    public static function unverifiedStatus(string $reason): array
    {
        return [
            'state' => self::PAUSED,
            'reason' => $reason,
            'code' => 'PROTECTION_UNVERIFIED',
            'since' => gmdate('c'),
            'previousState' => null,
            'triggers' => [],
            'metrics' => [],
            'evaluatedAt' => null,
            'evaluatedAtTs' => null,
            'clearScans' => 0,
            'unverified' => true,
        ];
    }

    /** Calendar source visibility for the admin screen (§1). */
    public function calendarStatus(): array
    {
        $policy = $this->policy();
        $calendar = $this->calendar->upcoming((int) ($policy['news']['feedMaxAgeMinutes'] ?? 180));
        $events = array_values(array_filter((array) ($calendar['events'] ?? []), fn(array $e): bool => (int) ($e['atTs'] ?? 0) >= $this->now()));
        usort($events, fn(array $a, array $b): int => (int) ($a['atTs'] ?? 0) <=> (int) ($b['atTs'] ?? 0));
        return [
            'enabled' => (bool) ($policy['news']['enabled'] ?? false),
            'configured' => (bool) ($calendar['configured'] ?? false),
            'ok' => (bool) ($calendar['ok'] ?? false),
            'source' => $calendar['source'] ?? null,
            'error' => $calendar['error'] ?? null,
            'fetchedAt' => $calendar['fetchedAt'] ?? null,
            'ageSeconds' => $calendar['ageSeconds'] ?? null,
            'stale' => !empty($calendar['stale']),
            'nextEvents' => array_slice($events, 0, 10),
        ];
    }

    public function ageSeconds(): ?int
    {
        $status = $this->status();
        return $status['evaluatedAtTs'] === null ? null : $this->now() - (int) $status['evaluatedAtTs'];
    }

    // ─── Enforcement gate (called before every new trade, §11) ───────

    /**
     * @return array{allowed:bool, state:string, reason:string, code:?string, unverified:bool, checkedAt:string}
     */
    public function gate(?string $symbol = null, ?float $price = null): array
    {
        $policy = $this->policy();
        $checkedAt = gmdate('c');
        if (!($policy['enabled'] ?? true)) {
            return ['allowed' => true, 'state' => self::NORMAL, 'reason' => 'Automatic protection is disabled.', 'code' => 'PROTECTION_DISABLED', 'unverified' => false, 'checkedAt' => $checkedAt];
        }

        $status = $this->status();
        $maxAge = (int) ($policy['recovery']['maxStatusAgeSeconds'] ?? 300);
        $age = $this->ageSeconds();
        if ($age === null || $age > $maxAge) {
            try {
                $status = $this->evaluate()['status'];
            } catch (\Throwable $e) {
                return $this->deny(self::PAUSED, 'Automatic protection could not verify trading conditions: ' . $e->getMessage(), 'PROTECTION_UNVERIFIABLE', $checkedAt);
            }
        }

        if (in_array($status['state'], self::BLOCKING, true)) {
            return $this->deny($status['state'], $status['reason'], $status['code'] ?? null, $checkedAt);
        }

        // Per-symbol spread is evaluated at order time, not only on the scan.
        if ($symbol !== null) {
            $spread = $this->spreadGate($policy, $symbol, $price);
            if ($spread !== null) return array_merge($spread, ['checkedAt' => $checkedAt]);
        }

        return [
            'allowed' => true,
            'state' => $status['state'],
            'reason' => $status['reason'],
            'code' => $status['code'] ?? null,
            'unverified' => false,
            'checkedAt' => $checkedAt,
        ];
    }

    /** @return array{allowed:false,state:string,reason:string,code:string,unverified:false}|null */
    private function spreadGate(array $policy, string $symbol, ?float $price): ?array
    {
        if (!($policy['spread']['enabled'] ?? false)) return null;
        $quote = $this->quoteFor($symbol);
        $raw = $quote === null ? null : ($quote['spread'] ?? null);
        if ($raw === null && isset($quote['ask'], $quote['bid'])) {
            $raw = (float) $quote['ask'] - (float) $quote['bid'];
        }
        if ($raw === null || !is_numeric($raw)) {
            // Unreadable spread: block only when the administrator opted in,
            // otherwise there is no reading to protect against.
            if (!($policy['spread']['requireReading'] ?? false)) return null;
            return $this->deny(self::PAUSED, 'Spread could not be read for ' . $symbol . ' — trading paused until it is measurable.', 'SPREAD_UNREADABLE', gmdate('c'));
        }
        $last = isset($quote['last']) ? (float) $quote['last'] : (float) ($price ?? 0.0);
        $points = ProtectionPolicy::toPoints((float) $raw, $symbol, $last);
        if ($points === null) return null;
        $max = ProtectionPolicy::maxSpreadPoints($policy, $symbol);
        if ($max > 0 && $points > $max) {
            return $this->deny(self::PAUSED, sprintf('Spread %.1f points on %s exceeds the %.1f point limit.', $points, $symbol, $max), 'SPREAD_EXCEEDED', gmdate('c'));
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    private function quoteFor(string $symbol): ?array
    {
        foreach ($this->brokers->allStatus() as $id => $status) {
            if (($status['state'] ?? '') !== 'READY') continue;
            $connector = $this->brokers->get((string) $id);
            if (!$connector) continue;
            try {
                $quote = $connector->quote($symbol);
            } catch (\Throwable) {
                continue;
            }
            if (is_array($quote)) return $quote;
        }
        return null;
    }

    /** @return array{allowed:false,state:string,reason:string,code:?string,unverified:bool} */
    private function deny(string $state, string $reason, ?string $code, string $checkedAt): array
    {
        return ['allowed' => false, 'state' => $state, 'reason' => $reason, 'code' => $code, 'unverified' => $state === self::PAUSED && $code === 'PROTECTION_UNVERIFIABLE', 'checkedAt' => $checkedAt];
    }

    // ─── Evaluation (the scan) ───────────────────────────────────────

    /**
     * Run every monitor, resolve the resulting state and apply it.
     *
     * @return array{status:array<string,mixed>, triggers:array<int,array<string,mixed>>,
     *               metrics:array<string,mixed>, policy:array<string,mixed>}
     */
    public function evaluate(): array
    {
        $policy = $this->policy();
        $triggers = [];
        $metrics = ['accounts' => [], 'equity' => 0.0, 'balance' => 0.0, 'dailyPnl' => 0.0,
            'dailyLossPct' => 0.0, 'peakEquity' => 0.0, 'drawdownPct' => 0.0, 'openPositions' => 0,
            'brokers' => [], 'unavailable' => []];

        try {
            $metrics = $this->collectMetrics($metrics);
        } catch (\Throwable $e) {
            $triggers[] = $this->trigger(self::PAUSED, 'METRICS_UNAVAILABLE', 'Account metrics unavailable: ' . $e->getMessage());
        }

        if (!($policy['enabled'] ?? true)) {
            $triggers = [];
        } else {
            $triggers = array_merge($triggers,
                $this->monitorNews($policy),
                $this->monitorDailyLoss($policy, $metrics),
                $this->monitorDrawdown($policy, $metrics),
                $this->monitorBrokers($policy, $metrics),
                $this->monitorDataFeed($policy),
                $this->monitorOrderFailures($policy),
                $this->monitorSlippage($policy),
            );
        }

        $status = $this->apply($triggers, $metrics, $policy);
        return ['status' => $status, 'triggers' => $triggers, 'metrics' => $metrics, 'policy' => $policy];
    }

    /** @return array<string,mixed> */
    private function trigger(string $state, string $code, string $reason, array $detail = []): array
    {
        return ['state' => $state, 'code' => $code, 'reason' => $reason, 'detail' => $detail];
    }

    /**
     * The trigger that decides the state: highest severity wins. On a tie the
     * later monitor wins, and monitors are ordered so that an account-risk
     * condition (loss, drawdown) outranks a configuration notice.
     */
    private function worst(array $triggers): ?array
    {
        $worst = null;
        foreach ($triggers as $trigger) {
            if ($worst === null || (self::SEVERITY[$trigger['state']] ?? 0) >= (self::SEVERITY[$worst['state']] ?? 0)) {
                $worst = $trigger;
            }
        }
        return $worst;
    }

    // ─── Monitors ────────────────────────────────────────────────────

    /** §1 — high-impact news protection. */
    private function monitorNews(array $policy): array
    {
        $news = $policy['news'] ?? [];
        if (!($news['enabled'] ?? false)) return [];

        $calendar = $this->calendar->upcoming((int) ($news['feedMaxAgeMinutes'] ?? 180));

        if (!$calendar['configured']) {
            if (!($news['pauseWhenNoProvider'] ?? false)) {
                return [$this->trigger(self::WARNING, 'NEWS_NOT_CONFIGURED',
                    'News protection is enabled but no Economic Calendar provider is configured (Admin → API).')];
            }
            return [$this->trigger(self::PAUSED, 'NEWS_NOT_CONFIGURED',
                'News protection is enabled and no Economic Calendar provider is configured — trading paused until conditions can be verified.')];
        }

        if (!$calendar['ok']) {
            $error = $calendar['error'] ?? 'calendar unavailable';
            if (($news['onFeedFailure'] ?? 'pause') === 'pause') {
                return [$this->trigger(self::PAUSED, 'NEWS_FEED_UNAVAILABLE',
                    'Economic calendar cannot be read — trading paused because high-impact events cannot be ruled out. (' . $error . ')')];
            }
            return [$this->trigger(self::WARNING, 'NEWS_FEED_UNAVAILABLE', 'Economic calendar unavailable: ' . $error)];
        }

        $before = (int) ($news['minutesBefore'] ?? 5) * 60;
        $after = (int) ($news['minutesAfter'] ?? 30) * 60;
        $lead = (int) ($news['warningLeadMinutes'] ?? 15) * 60;
        $impacts = array_map('strval', (array) ($news['impacts'] ?? ['high']));
        $now = $this->now();

        foreach ($calendar['events'] as $event) {
            if (!in_array((string) ($event['impact'] ?? 'high'), $impacts, true)) continue;
            $at = (int) ($event['atTs'] ?? 0);
            if ($at <= 0) continue;
            $delta = $at - $now;                       // > 0 = upcoming
            if ($delta <= $before && $delta >= -$after) {
                $when = $delta >= 0 ? sprintf('in %d minute(s)', (int) ceil($delta / 60)) : sprintf('%d minute(s) ago', (int) abs(round($delta / 60)));
                return [$this->trigger(self::PAUSED, 'NEWS_EVENT',
                    sprintf('High-impact event %s %s — %s.', (string) ($event['name'] ?? 'economic event'), $when, gmdate('H:i', $at) . ' UTC'),
                    ['event' => $event, 'minutesBefore' => (int) ($news['minutesBefore'] ?? 5), 'minutesAfter' => (int) ($news['minutesAfter'] ?? 30)])];
            }
            if ($delta > $before && $delta <= $before + $lead) {
                return [$this->trigger(self::WARNING, 'NEWS_APPROACHING',
                    sprintf('High-impact event %s in %d minute(s) — approaching the protection window.', (string) ($event['name'] ?? 'economic event'), (int) ceil($delta / 60)),
                    ['event' => $event])];
            }
        }
        return [];
    }

    /** §2 — daily loss protection (percentage and/or fixed amount). */
    private function monitorDailyLoss(array $policy, array $metrics): array
    {
        $cfg = $policy['dailyLoss'] ?? [];
        if (!($cfg['enabled'] ?? false)) return [];
        $equity = (float) ($metrics['equity'] ?? 0.0);
        $loss = -((float) ($metrics['dailyPnl'] ?? 0.0));   // positive = losing
        if ($loss <= 0) return [];

        $pctLimit = (float) ($cfg['percentLimit'] ?? 0.0);
        $fixedLimit = $cfg['fixedLimitUsd'] ?? null;
        $lossPct = $equity > 0 ? $loss / $equity : 0.0;

        $breachPct = $pctLimit > 0 && $lossPct >= $pctLimit;
        $breachFixed = $fixedLimit !== null && $loss >= (float) $fixedLimit;

        if ($breachPct || $breachFixed) {
            $reason = $breachFixed
                ? sprintf('Daily loss %s reached the fixed limit of %s.', self::money($loss), self::money((float) $fixedLimit))
                : sprintf('Daily loss reached %.2f%% of equity (limit %.2f%%).', $lossPct * 100, $pctLimit * 100);
            return [$this->trigger(self::KILL, 'DAILY_LOSS_LIMIT', $reason,
                ['dailyPnl' => round(-$loss, 2), 'dailyLossPct' => round($lossPct * 100, 2), 'equity' => $equity, 'limitPct' => $pctLimit, 'limitUsd' => $fixedLimit])];
        }

        $warnAt = (float) ($cfg['warnAtFraction'] ?? 0.8);
        $nearest = $pctLimit > 0 ? ($lossPct / $pctLimit) : 0.0;
        if ($fixedLimit !== null && (float) $fixedLimit > 0) {
            $nearest = max($nearest, $loss / (float) $fixedLimit);
        }
        if ($warnAt > 0 && $nearest >= $warnAt) {
            return [$this->trigger(self::WARNING, 'DAILY_LOSS_APPROACHING',
                sprintf('Daily loss at %.0f%% of the configured limit.', min(100.0, $nearest * 100)),
                ['dailyPnl' => round(-$loss, 2), 'fractionOfLimit' => round($nearest, 3)])];
        }
        return [];
    }

    /** §3 — maximum drawdown protection. */
    private function monitorDrawdown(array $policy, array $metrics): array
    {
        $cfg = $policy['drawdown'] ?? [];
        if (!($cfg['enabled'] ?? false)) return [];
        $limit = (float) ($cfg['percentLimit'] ?? 0.0);
        $drawdown = (float) ($metrics['drawdownPct'] ?? 0.0);
        if ($limit <= 0 || $drawdown <= 0) return [];

        if ($drawdown >= $limit) {
            return [$this->trigger(self::KILL, 'MAX_DRAWDOWN',
                sprintf('Maximum drawdown %.2f%% reached (limit %.2f%%).', $drawdown * 100, $limit * 100),
                ['drawdownPct' => round($drawdown * 100, 2), 'peakEquity' => $metrics['peakEquity'] ?? null, 'equity' => $metrics['equity'] ?? null])];
        }
        $warnAt = (float) ($cfg['warnAtFraction'] ?? 0.8);
        $fraction = $limit > 0 ? $drawdown / $limit : 0.0;
        if ($warnAt > 0 && $fraction >= $warnAt) {
            return [$this->trigger(self::WARNING, 'DRAWDOWN_APPROACHING',
                sprintf('Drawdown at %.0f%% of the configured maximum.', min(100.0, $fraction * 100)),
                ['drawdownPct' => round($drawdown * 100, 2), 'fractionOfLimit' => round($fraction, 3)])];
        }
        return [];
    }

    /** §4 — broker / MT4 / MT5 connectivity. */
    private function monitorBrokers(array $policy, array $metrics): array
    {
        $cfg = $policy['technical'] ?? [];
        if (!($cfg['brokerDisconnect'] ?? false)) return [];
        $state = ($cfg['escalateToKill'] ?? false) ? self::KILL : self::PAUSED;
        $triggers = [];
        foreach (($metrics['brokers'] ?? []) as $broker) {
            if (($broker['state'] ?? '') !== 'DOWN') continue;
            $triggers[] = $this->trigger($state, 'BROKER_DISCONNECTED',
                sprintf('Broker connection lost: %s (%s).', (string) ($broker['id'] ?? 'connector'), (string) ($broker['message'] ?? 'unreachable')),
                ['broker' => $broker]);
        }
        return $triggers;
    }

    /** §4 — market-data availability and staleness. */
    private function monitorDataFeed(array $policy): array
    {
        $cfg = $policy['technical'] ?? [];
        if (!($cfg['staleData'] ?? false) || $this->providers === null) return [];
        $timeout = (int) ($cfg['dataFeedTimeoutSeconds'] ?? 60);
        $state = ($cfg['escalateToKill'] ?? false) ? self::KILL : self::PAUSED;
        $triggers = [];
        $now = $this->now();
        try {
            $health = $this->providers->getAllHealth();
        } catch (\Throwable $e) {
            return [$this->trigger($state, 'DATA_FEED_UNAVAILABLE', 'Market-data health could not be read: ' . $e->getMessage())];
        }
        foreach ($health as $entry) {
            if (!is_array($entry)) continue;
            if (!empty($entry['synthetic'])) continue;             // simulated feed is not a feed failure
            $status = strtoupper((string) ($entry['status'] ?? ''));
            if ($status === 'DOWN') {
                $triggers[] = $this->trigger($state, 'DATA_FEED_UNAVAILABLE',
                    sprintf('Market-data provider %s is down.', (string) ($entry['name'] ?? 'provider')), ['provider' => $entry]);
                continue;
            }
            $checkedAt = (int) ($entry['checkedAt'] ?? 0);
            if ($checkedAt > 0 && ($now - $checkedAt) > $timeout) {
                $triggers[] = $this->trigger($state, 'DATA_FEED_STALE',
                    sprintf('Market data from %s is stale (%d s old).', (string) ($entry['name'] ?? 'provider'), $now - $checkedAt),
                    ['provider' => $entry, 'ageSeconds' => $now - $checkedAt]);
            }
        }
        return $triggers;
    }

    /** §4 — repeated order-execution failures. */
    private function monitorOrderFailures(array $policy): array
    {
        $cfg = $policy['technical'] ?? [];
        $max = (int) ($cfg['maxConsecutiveOrderFailures'] ?? 0);
        if ($max <= 0) return [];
        $failures = (int) ($this->raw()[self::STATE_KEY]['orderFailures'] ?? 0);
        if ($failures < $max) return [];
        $state = ($cfg['escalateToKill'] ?? false) ? self::KILL : self::PAUSED;
        return [$this->trigger($state, 'ORDER_FAILURES',
            sprintf('%d consecutive order failure(s) — order execution is unsafe.', $failures), ['failures' => $failures])];
    }

    /** §6 — slippage protection (samples recorded by the execution layer). */
    private function monitorSlippage(array $policy): array
    {
        $cfg = $policy['slippage'] ?? [];
        if (!($cfg['enabled'] ?? false)) return [];
        $max = (float) ($cfg['maxPoints'] ?? 0.0);
        $window = (int) ($cfg['sampleWindow'] ?? 20);
        if ($max <= 0) return [];
        $samples = array_slice((array) ($this->raw()[self::STATE_KEY]['slippageSamples'] ?? []), -max(1, $window));
        if ($samples === []) return [];
        $worst = 0.0;
        foreach ($samples as $sample) $worst = max($worst, (float) $sample);
        if ($worst <= $max) return [];
        return [$this->trigger(self::PAUSED, 'SLIPPAGE_EXCEEDED',
            sprintf('Slippage reached %.1f points (limit %.1f) in the last %d fill(s).', $worst, $max, count($samples)),
            ['worstPoints' => $worst, 'limit' => $max, 'samples' => count($samples)])];
    }

    // ─── Metrics ─────────────────────────────────────────────────────

    private function collectMetrics(array $metrics): array
    {
        $equity = 0.0;
        $balance = 0.0;
        $dailyPnl = 0.0;
        $openPositions = 0;
        foreach ($this->paperRepo->listAccounts() as $account) {
            $id = (int) ($account['id'] ?? 0);
            if ($id <= 0) continue;
            try {
                $summary = $this->paper->accountSummary($id);
            } catch (\Throwable $e) {
                $metrics['unavailable'][] = ['account' => $id, 'error' => $e->getMessage()];
                continue;
            }
            $metrics['accounts'][] = [
                'id' => $id, 'name' => (string) ($account['name'] ?? ('paper:' . $id)),
                'equity' => (float) ($summary['equity'] ?? 0.0), 'balance' => (float) ($summary['balance'] ?? 0.0),
                'dailyPnl' => (float) ($summary['dailyPnl'] ?? 0.0), 'openPositions' => (int) ($summary['openPositions'] ?? 0),
            ];
            $equity += (float) ($summary['equity'] ?? 0.0);
            $balance += (float) ($summary['balance'] ?? 0.0);
            $dailyPnl += (float) ($summary['dailyPnl'] ?? 0.0);
            $openPositions += (int) ($summary['openPositions'] ?? 0);
        }

        // Broker accounts contribute equity when a connector is reachable.
        foreach ($this->brokers->allStatus() as $id => $status) {
            $metrics['brokers'][] = [
                'id' => (string) $id, 'state' => (string) ($status['state'] ?? 'UNKNOWN'),
                'message' => (string) ($status['message'] ?? ''), 'configured' => !empty($status['configured']),
            ];
            if (($status['state'] ?? '') !== 'READY') continue;
            $connector = $this->brokers->get((string) $id);
            if (!$connector) continue;
            try {
                $account = $connector->account();
                $equity += (float) ($account['equity'] ?? $account['balance'] ?? 0.0);
                $balance += (float) ($account['balance'] ?? 0.0);
                $openPositions += count((array) ($connector instanceof TradingConnector ? $connector->positions() : []));
            } catch (\Throwable $e) {
                $metrics['unavailable'][] = ['broker' => (string) $id, 'error' => $e->getMessage()];
            }
        }

        $metrics['equity'] = $equity;
        $metrics['balance'] = $balance;
        $metrics['dailyPnl'] = $dailyPnl;
        $metrics['openPositions'] = $openPositions;
        $metrics['dailyLossPct'] = $equity > 0 ? max(0.0, -$dailyPnl / $equity) : 0.0;

        // Drawdown against the highest equity ever recorded (high-water mark).
        $storedPeak = (float) ($this->raw()[self::STATE_KEY]['peakEquity'] ?? 0.0);
        $peak = max($storedPeak, $equity);
        $metrics['peakEquity'] = $peak;
        $metrics['drawdownPct'] = $peak > 0 ? max(0.0, ($peak - $equity) / $peak) : 0.0;
        if ($peak > $storedPeak) $this->storePeak($peak);

        return $metrics;
    }

    // ─── State machine (§7) ──────────────────────────────────────────

    private function apply(array $triggers, array $metrics, array $policy): array
    {
        $previous = $this->status();
        $worst = $this->worst($triggers);
        $target = $worst['state'] ?? self::NORMAL;

        if (!($policy['enabled'] ?? true)) {
            // Switched off by an administrator: no recovery scan is needed —
            // trading is simply allowed again and that decision is audited.
            $next = self::NORMAL;
        } else {
            $next = self::resolveNextState(
                (string) ($previous['state'] ?? self::NORMAL),
                $target,
                $policy,
                (int) ($previous['clearScans'] ?? 0)
            );

            // The boot state is deliberately unverified (never assume safe), so
            // the very first scan starts from a blocking state. Confirmation
            // scans exist to stop a real breach from flapping back to trading,
            // so they do NOT apply to that first look: one clean read of every
            // monitor is the best information available, and making the first
            // orders wait for a second scan would only add latency — the
            // operator would be told "conditions are clear" while being
            // refused for another minute.
            if ($next === self::RECOVERY && !empty($previous['unverified'])) {
                $next = self::RESUMED;
            }
        }

        $status = [
            'state' => $next,
            'reason' => $this->reasonFor($next, $worst, $previous, $policy),
            'code' => $worst['code'] ?? null,
            'since' => $next === ($previous['state'] ?? null) ? ($previous['since'] ?? gmdate('c')) : gmdate('c'),
            'previousState' => $previous['state'] ?? null,
            'triggers' => $triggers,
            'metrics' => $this->publicMetrics($metrics),
            'evaluatedAt' => gmdate('c'),
            'evaluatedAtTs' => $this->now(),
            'clearScans' => $next === self::RECOVERY ? ((int) ($previous['clearScans'] ?? 0) + 1) : 0,
            'unverified' => false,
        ];

        $this->persist($status);

        if ($next !== ($previous['state'] ?? null)) {
            $this->onTransition($previous['state'] ?? self::NORMAL, $status, $policy, $metrics);
        }
        if ($next === self::KILL) {
            $this->runEmergencyActions($status, $policy, $metrics);
        }
        $this->driveKillSwitch($status);

        return $status;
    }

    /**
     * The §7 state machine. Pure and side-effect free so it can be tested
     * without a database:
     *
     *   NORMAL ──(risk condition approaching)─────────────► WARNING
     *   NORMAL/WARNING ──(pause-level condition)──────────► AUTOMATIC_PAUSED
     *   any ──(critical threshold: daily loss, drawdown)──► AUTOMATIC_KILL
     *   blocking ──(conditions clear)─────────────────────► RECOVERY
     *   RECOVERY ──(N consecutive clear scans)────────────► RESUMED
     *   RESUMED ──(next scan)─────────────────────────────► NORMAL
     *
     * A KILL is never downgraded to PAUSED while anything still blocks, and
     * trading is never resumed from a blocking state without passing through
     * RECOVERY (unless recovery is disabled by policy).
     */
    public static function resolveNextState(string $previous, string $target, array $policy, int $clearScans = 0): string
    {
        if (in_array($target, self::BLOCKING, true)) {
            return ($previous === self::KILL && $target === self::PAUSED) ? self::KILL : $target;
        }

        // RECOVERY is itself a blocking state, so it must be handled before the
        // generic blocking branch — otherwise the confirmation-scan counter is
        // unreachable and trading can never resume on its own.
        $recoveryEnabled = (bool) ($policy['recovery']['enabled'] ?? true);
        if (!$recoveryEnabled) {
            if (in_array($previous, self::BLOCKING, true)) return self::RESUMED;
            if ($previous === self::RESUMED) return self::NORMAL;
            return $target;
        }
        if ($previous === self::RECOVERY) {
            $needed = max(1, (int) ($policy['recovery']['consecutiveClearScans'] ?? 2));
            return ($clearScans + 1) >= $needed ? self::RESUMED : self::RECOVERY;
        }
        if (in_array($previous, self::BLOCKING, true)) return self::RECOVERY;
        if ($previous === self::RESUMED) return self::NORMAL;
        return $target;
    }

    private function reasonFor(string $next, ?array $worst, array $previous, array $policy): string
    {
        if ($next === self::RECOVERY) {
            $needed = (int) ($policy['recovery']['consecutiveClearScans'] ?? 2);
            $have = (int) ($previous['clearScans'] ?? 0) + 1;
            return sprintf('Conditions are clear (%d/%d confirmation scans) — monitoring before trading resumes.', $have, $needed);
        }
        if ($next === self::RESUMED) return 'All configured safety conditions are clear — trading resumed automatically.';
        if ($next === self::NORMAL) return 'No risk conditions detected.';
        if ($worst !== null) return (string) $worst['reason'];
        if ($next === self::KILL) return (string) ($previous['reason'] ?? 'Critical protection condition active.');
        return 'Automatic protection active.';
    }

    private function onTransition(string $from, array $status, array $policy, array $metrics): void
    {
        $detail = [
            'from' => $from, 'to' => $status['state'], 'code' => $status['code'],
            'reason' => $status['reason'], 'metrics' => $status['metrics'],
            'triggers' => array_map(fn(array $t): array => ['code' => $t['code'], 'state' => $t['state'], 'reason' => $t['reason']], $status['triggers']),
        ];
        $this->audit->emit('AUTOMATIC_PROTECTION_' . $status['state'],
            sprintf('Automatic Kill Switch %s → %s: %s', $from, $status['state'], $status['reason']), $detail, 'system');

        $severity = match ($status['state']) {
            self::KILL => 'critical',
            self::PAUSED => 'warning',
            self::RECOVERY, self::WARNING => 'info',
            default => 'info',
        };
        $this->notifier?->notify('AUTOMATIC_PROTECTION', $severity,
            sprintf('Automatic protection %s — %s', $status['state'], $status['reason']), $detail,
            'automatic-protection:' . $status['state'] . ':' . ($status['code'] ?? 'none'));

    }

    /**
     * Emergency policy (§2/§3). Both actions are OFF by default: blocking new
     * trades is the default protection; closing or cancelling is opt-in and
     * every action is audited individually.
     */
    /**
     * Run the emergency policy once per kill episode (§2/§3). Keyed on the
     * moment the kill began, so an administrator who enables closing or
     * cancelling while a kill is already active still gets the action on the
     * next scan instead of waiting for the next episode.
     */
    private function runEmergencyActions(array $status, array $policy, array $metrics): void
    {
        $emergency = $policy['emergency'] ?? [];
        $signature = ($status['since'] ?? '') . '|'
            . (($emergency['closePositionsOnKill'] ?? false) ? '1' : '0')
            . (($emergency['cancelPendingOrdersOnKill'] ?? false) ? '1' : '0');

        $state = $this->state->load();
        if ((string) ($state[self::STATE_KEY]['emergencyFor'] ?? '') === $signature) return;

        $state[self::STATE_KEY]['emergencyFor'] = $signature;
        $this->state->save($state);
        $this->emergencyActions($status, $policy, $metrics);
    }

    private function emergencyActions(array $status, array $policy, array $metrics): void
    {
        $emergency = $policy['emergency'] ?? [];
        if (!($emergency['closePositionsOnKill'] ?? false) && !($emergency['cancelPendingOrdersOnKill'] ?? false)) {
            $this->audit->emit('PROTECTION_EMERGENCY_POLICY', 'Automatic Kill activated — emergency close/cancel policy is not enabled; new trading is blocked.',
                ['policy' => $emergency], 'system');
            return;
        }

        if ($emergency['closePositionsOnKill'] ?? false) {
            foreach ((array) ($metrics['accounts'] ?? []) as $account) {
                $id = (int) ($account['id'] ?? 0);
                if ($id <= 0) continue;
                try {
                    foreach ($this->paperRepo->listOpenPositions($id) as $position) {
                        $this->paper->closePosition($id, (int) ($position['id'] ?? 0), 'AUTOMATIC_KILL');
                    }
                    $this->audit->emit('PROTECTION_EMERGENCY_CLOSE', "Automatic Kill closed paper positions on account {$id}.", ['account' => $id], 'system');
                } catch (\Throwable $e) {
                    $this->audit->emit('PROTECTION_EMERGENCY_FAILED', "Automatic Kill could not close positions on account {$id}: " . $e->getMessage(), ['account' => $id], 'system');
                }
            }
            foreach ($this->brokers->allStatus() as $id => $brokerStatus) {
                if (($brokerStatus['state'] ?? '') !== 'READY') continue;
                $connector = $this->brokers->get((string) $id);
                if (!$connector instanceof TradingConnector) continue;
                try {
                    foreach ($connector->positions() as $position) {
                        $connector->closePosition((int) ($position['ticket'] ?? $position['id'] ?? 0));
                    }
                    $this->audit->emit('PROTECTION_EMERGENCY_CLOSE', "Automatic Kill closed broker positions on {$id}.", ['broker' => (string) $id], 'system');
                } catch (\Throwable $e) {
                    $this->audit->emit('PROTECTION_EMERGENCY_FAILED', "Automatic Kill could not close broker positions on {$id}: " . $e->getMessage(), ['broker' => (string) $id], 'system');
                }
            }
        }

        if ($emergency['cancelPendingOrdersOnKill'] ?? false) {
            foreach ($this->brokers->allStatus() as $id => $brokerStatus) {
                if (($brokerStatus['state'] ?? '') !== 'READY') continue;
                $connector = $this->brokers->get((string) $id);
                if (!$connector instanceof TradingConnector) continue;
                try {
                    foreach ($connector->pendingOrders() as $order) {
                        $connector->cancelOrder((int) ($order['ticket'] ?? $order['id'] ?? 0));
                    }
                    $this->audit->emit('PROTECTION_EMERGENCY_CANCEL', "Automatic Kill cancelled pending orders on {$id}.", ['broker' => (string) $id], 'system');
                } catch (\Throwable $e) {
                    $this->audit->emit('PROTECTION_EMERGENCY_FAILED', "Automatic Kill could not cancel pending orders on {$id}: " . $e->getMessage(), ['broker' => (string) $id], 'system');
                }
            }
        }
    }

    /**
     * The automatic engine owns the order gate: while protection blocks
     * trading the platform's kill switch is engaged, and it is released only
     * when the engine itself returns trading to a clear state.
     */
    private function driveKillSwitch(array $status): void
    {
        $blocking = in_array($status['state'], self::BLOCKING, true);
        $state = $this->state->load();
        $active = !empty($state['killSwitch']['active']);
        if ($blocking === $active) return;

        $state['killSwitch'] = [
            'active' => $blocking,
            'activatedAt' => $blocking ? gmdate('c') : null,
            'reason' => $status['reason'],
        ];
        $this->state->save($state);
        $this->audit->emit($blocking ? 'KILL_SWITCH_ACTIVATED' : 'KILL_SWITCH_DEACTIVATED',
            'Automatic Kill Switch ' . ($blocking ? 'ACTIVATED' : 'released') . ': ' . $status['reason'],
            ['state' => $status['state'], 'code' => $status['code'], 'automatic' => true], 'system');
        if ($blocking) {
            $this->notifier?->notify('KILL_SWITCH', 'critical', 'AUTOMATIC KILL SWITCH ACTIVATED — ' . $status['reason'],
                ['state' => $status['state'], 'code' => $status['code']], 'kill-switch:active');
        }
    }

    // ─── Execution feedback (§4 order failures, §6 slippage) ─────────

    public function recordOrderFailure(string $reason = ''): void
    {
        $state = $this->state->load();
        $state[self::STATE_KEY]['orderFailures'] = (int) ($state[self::STATE_KEY]['orderFailures'] ?? 0) + 1;
        $state[self::STATE_KEY]['lastOrderFailure'] = ['at' => gmdate('c'), 'reason' => $reason];
        $this->state->save($state);
    }

    public function recordOrderSuccess(): void
    {
        $state = $this->state->load();
        if ((int) ($state[self::STATE_KEY]['orderFailures'] ?? 0) === 0) return;
        $state[self::STATE_KEY]['orderFailures'] = 0;
        $this->state->save($state);
    }

    /** Record one fill's slippage in points (rolling window, §6). */
    public function recordSlippage(float $points): void
    {
        $state = $this->state->load();
        $samples = (array) ($state[self::STATE_KEY]['slippageSamples'] ?? []);
        $samples[] = round($points, 4);
        if (count($samples) > 50) $samples = array_slice($samples, -50);
        $state[self::STATE_KEY]['slippageSamples'] = $samples;
        $this->state->save($state);
    }

    public function resetPeakEquity(): void
    {
        $this->storePeak(0.0);
        $this->audit->emit('PROTECTION_PEAK_RESET', 'Drawdown high-water mark reset by an administrator.', [], 'system');
    }

    // ─── Persistence helpers ─────────────────────────────────────────

    private function raw(): array
    {
        return $this->state->load();
    }

    private function storePeak(float $peak): void
    {
        $state = $this->state->load();
        $state[self::STATE_KEY]['peakEquity'] = $peak;
        $this->state->save($state);
    }

    private function persist(array $status): void
    {
        $state = $this->state->load();
        $state[self::STATE_KEY]['status'] = $status;
        $this->state->save($state);
    }

    /** Metrics safe to render in a view or JSON response. */
    private function publicMetrics(array $metrics): array
    {
        return [
            'equity' => round((float) ($metrics['equity'] ?? 0.0), 2),
            'balance' => round((float) ($metrics['balance'] ?? 0.0), 2),
            'dailyPnl' => round((float) ($metrics['dailyPnl'] ?? 0.0), 2),
            'dailyLossPct' => round((float) ($metrics['dailyLossPct'] ?? 0.0) * 100, 2),
            'peakEquity' => round((float) ($metrics['peakEquity'] ?? 0.0), 2),
            'drawdownPct' => round((float) ($metrics['drawdownPct'] ?? 0.0) * 100, 2),
            'openPositions' => (int) ($metrics['openPositions'] ?? 0),
            'accounts' => count((array) ($metrics['accounts'] ?? [])),
            'brokers' => (array) ($metrics['brokers'] ?? []),
        ];
    }

    private static function money(float $value): string
    {
        return '$' . number_format($value, 2);
    }
}
