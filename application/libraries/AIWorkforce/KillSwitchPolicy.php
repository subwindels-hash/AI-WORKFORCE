<?php
namespace AIWorkforce;

/**
 * KILL-SWITCH SCOPE POLICY — the single source of truth for what the platform
 * kill switch governs.
 *
 * The kill switch is a TRADING control, not a platform-wide mute button. It
 * governs the ORDER PATHS of the broker and trading-intelligence surfaces:
 *
 *   • broker connectors — MT5 bridge, MT4 bridge, cryptocurrency exchanges
 *     (Binance / Bybit / OKX / Coinbase / Kraken), forex and stock brokers
 *     (OANDA, Alpaca, Interactive Brokers) and per-user connections
 *   • Trade Execution Supervisor — propose, approve and route
 *   • Trading Intelligence Engine — the risk decision attached to an analysis
 *   • Paper Trading engine — simulated order placement
 *   • trading-mode automation gate (SEMI_AUTONOMOUS / FULLY_AUTOMATED)
 *
 * Everything else is NEVER blocked by it: Sports Intelligence, Football
 * Predictions, EuroMillions, Language Learning, Lead Discovery, Multiplier AI,
 * Messages, Notifications, the AI Workforce agents and account/admin pages all
 * keep working while the switch is engaged. Read-only market data (Crypto
 * Market Data, Forex Market Data, Stock/ETF/Futures Market Data, charts and
 * quotes) also keeps flowing — the switch stops ORDERS, not analysis.
 *
 * Unwind/finalize actions stay available inside the governed scope too
 * (position close, order cancel, settlement), because reducing exposure must
 * never be blocked by the same control that is meant to stop new exposure.
 *
 * Boot default: RELEASED (`active = false`). Trading remains fail-closed
 * through the gates that are always on: `tradingMode` boots ANALYSIS_ONLY,
 * broker routing needs a verified order-capable connector plus explicit
 * demo/live authorization, and the automated modes need a configured
 * automation envelope. Hosts that want the previous posture can set
 * `AI_WORKFORCE_KILL_SWITCH_BOOT_ACTIVE=1` to install the switch ENGAGED —
 * that changes the boot flag only, never the scope.
 */
final class KillSwitchPolicy
{
    /** Recorded on a fresh install (and on states migrated from the old boot default). */
    public const BOOT_REASON = 'Released at boot — the kill switch is scoped to broker + trading-intelligence order paths';

    /** Recorded when a host opts back into the engaged boot posture by environment. */
    public const BOOT_ENGAGED_REASON = 'Engaged at boot by AI_WORKFORCE_KILL_SWITCH_BOOT_ACTIVE=1 — scoped to broker + trading-intelligence order paths';

    /**
     * Opt-in env gate for hosts that want the pre-scope posture back: set it to
     * 1 and a fresh install boots with the switch ENGAGED. It never widens the
     * scope — the switch still governs broker + trading-intelligence order
     * paths only, so non-trading modules keep working either way.
     */
    public const BOOT_ACTIVE_ENV = 'AI_WORKFORCE_KILL_SWITCH_BOOT_ACTIVE';

    /**
     * Boot reason used before the switch was scoped. A stored state that still
     * carries it was written by the installer, never by an operator, so it is
     * migrated to the scoped default instead of blocking trading forever.
     */
    public const LEGACY_BOOT_REASON = 'Default state at boot — orders blocked until explicitly released';

    /**
     * Surface prefixes the switch governs. A surface is governed when it equals
     * one of these or starts with "<prefix>." — e.g. broker.mt5-bridge,
     * execution.propose, paper.submit_order, trading.submit_order.
     *
     * Surface ids name ORDER PATHS, not configuration screens: 'risk' covers
     * the Risk Engine's veto on a trade proposal (risk.trade_veto), never
     * risk-limit configuration, and 'broker' covers order submission, never the
     * read-only account/quote/positions surface (see ALWAYS_AVAILABLE_ACTIONS).
     */
    public const GOVERNED_SURFACES = ['broker', 'execution', 'paper', 'risk', 'trading'];

    /**
     * Surfaces that are explicitly out of scope. Documented (and asserted in
     * tests) so a future refactor cannot silently widen the switch back to a
     * platform-wide mute.
     */
    public const UNGOVERNED_SURFACES = [
        'account', 'admin', 'agents', 'analysis_read', 'chat', 'football', 'language',
        'leads', 'lottery', 'market_data', 'messages', 'multiplier', 'notifications',
        'sports', 'workforce',
    ];

    /**
     * Actions that stay available even inside a governed surface: they unwind
     * or finalize exposure instead of opening it, or they are read-only.
     */
    public const ALWAYS_AVAILABLE_ACTIONS = [
        'account', 'cancel', 'cancel_order', 'close', 'close_position', 'health',
        'history', 'positions', 'quote', 'read', 'settle', 'settlement', 'status',
    ];

    /**
     * Dashboard page keys (`$active`) where the kill-switch indicator is
     * rendered. Every other page shows the plain trading-mode pill or nothing
     * at all — the switch does not govern those modules, so its state is not
     * their business.
     *
     * Careful with the legacy page keys: 'dashboard' is the /analysis trading
     * console (Welcome controller) and 'home' is the cross-module /dashboard
     * overview, which is deliberately NOT listed — an aggregate page for every
     * module is not a trading surface.
     */
    public const TRADING_PAGES = [
        'analysis', 'brokers', 'dashboard', 'execution', 'journal',
        'paper', 'risk', 'strategy', 'trading',
    ];

    /** True when the kill switch governs the given surface id. */
    public static function governs(string $surface): bool
    {
        $surface = strtolower(trim($surface));
        if ($surface === '') return false;
        foreach (self::ALWAYS_AVAILABLE_ACTIONS as $action) {
            if ($surface === $action || str_ends_with($surface, '.' . $action)) return false;
        }
        foreach (self::GOVERNED_SURFACES as $prefix) {
            if ($surface === $prefix || str_starts_with($surface, $prefix . '.')) return true;
        }
        return false;
    }

    /** True when the switch is engaged AND governs the surface (fail closed on a missing state row). */
    public static function isActive(?array $killSwitch, string $surface): bool
    {
        if (!self::governs($surface)) return false;
        if (!is_array($killSwitch) || !array_key_exists('active', $killSwitch)) return true;
        return (bool) $killSwitch['active'];
    }

    /**
     * Convenience form of isActive() that takes the whole platform state
     * (`{tradingMode, killSwitch, …}`) — the shape every caller already has.
     */
    public static function blocks(?array $state, string $surface): bool
    {
        $killSwitch = is_array($state) ? ($state['killSwitch'] ?? null) : null;
        return self::isActive(is_array($killSwitch) ? $killSwitch : null, $surface);
    }

    /** True when the host asked for an ENGAGED boot default via the environment. */
    public static function bootsActive(): bool
    {
        return strtolower(trim((string) getenv(self::BOOT_ACTIVE_ENV))) === '1';
    }

    /** Fresh-install kill-switch state: released (unless the env gate says otherwise), with the scope recorded. */
    public static function defaultState(): array
    {
        $active = self::bootsActive();
        return [
            'active' => $active,
            'activatedAt' => $active ? gmdate('c') : null,
            'reason' => $active ? self::BOOT_ENGAGED_REASON : self::BOOT_REASON,
            'scope' => self::GOVERNED_SURFACES,
            'scopeLabel' => self::scopeLabel(),
        ];
    }

    /** State row written when an operator engages or releases the switch. */
    public static function stateFor(bool $active, ?string $reason = null): array
    {
        return [
            'active' => $active,
            'activatedAt' => gmdate('c'),
            'reason' => $reason ?? ($active ? 'engaged' : 'released'),
            'scope' => self::GOVERNED_SURFACES,
            'scopeLabel' => self::scopeLabel(),
        ];
    }

    /**
     * Fill in the scope fields on a stored kill-switch row and migrate the
     * pre-scope boot default. An operator engagement always records its own
     * reason, so it survives untouched.
     */
    public static function normalize(?array $killSwitch): array
    {
        $stored = is_array($killSwitch) ? $killSwitch : [];
        if (self::isLegacyBootDefault($stored)) $stored = [];
        return array_merge(self::defaultState(), $stored);
    }

    /** True when the row is the untouched pre-scope installer default. */
    public static function isLegacyBootDefault(?array $killSwitch): bool
    {
        if (!is_array($killSwitch)) return false;
        return !empty($killSwitch['active'])
            && trim((string) ($killSwitch['reason'] ?? '')) === self::LEGACY_BOOT_REASON;
    }

    /** True on pages where the kill-switch indicator belongs. */
    public static function governsPage(?string $page): bool
    {
        return in_array(strtolower(trim((string) $page)), self::TRADING_PAGES, true);
    }

    /** Human-readable scope, used in audit rows, notifications and the UI. */
    public static function scopeLabel(): string
    {
        return 'broker + trading intelligence order paths (MT5, MT4, cryptocurrency, forex, stock/ETF connectors, execution supervisor, trading intelligence engine, paper trading)';
    }

    /** Machine-readable scope for /api/system/status. */
    public static function scope(): array
    {
        return [
            'label' => self::scopeLabel(),
            'governs' => self::GOVERNED_SURFACES,
            'neverGoverns' => self::UNGOVERNED_SURFACES,
            'alwaysAvailableActions' => self::ALWAYS_AVAILABLE_ACTIONS,
            'bootDefault' => self::bootsActive() ? 'ENGAGED (' . self::BOOT_ACTIVE_ENV . '=1)' : 'RELEASED',
        ];
    }
}
