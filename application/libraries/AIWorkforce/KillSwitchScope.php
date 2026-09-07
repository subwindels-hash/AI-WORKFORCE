<?php
namespace AIWorkforce;

/**
 * Scope of the platform kill switch: BROKER + TRADING INTELLIGENCE only.
 *
 * The kill switch is an order-bound safeguard, not a global platform freeze.
 * It has NO manual control anywhere: the Automatic Kill Switch engine
 * (`TradingProtection\AutomaticProtection`) engages the `state.killSwitch`
 * flag when protection blocks trading and releases it when conditions clear.
 * This class answers "what does that flag gate?" — read-only scope, never
 * command.
 * It gates the surfaces where money or a broker connection is at stake:
 *
 *   execution_supervisor — 15-step proposal pipeline (step 1) + routing
 *   broker_orders        — MT5/MT4, crypto exchange, OANDA/Alpaca/IBKR orders
 *   paper_orders         — simulated order placement (Paper Trading Engine)
 *   automation_modes     — SEMI_AUTONOMOUS / FULLY_AUTOMATED envelopes
 *
 * Everything else is deliberately OUT OF SCOPE:
 *   - Market data keeps streaming (Crypto / Forex / Stock Market Data,
 *     Binance, Bybit, OKX, Coinbase, Kraken, Alpaca, OANDA, Frankfurter,
 *     Yahoo, IBKR). An engaged kill switch must not blind the operator —
 *     charts, analysis, provenance and provider health stay observable so
 *     the situation that caused the halt can still be diagnosed.
 *   - Non-trading modules are never gated: sports odds-prediction ticket
 *     approval/settlement, lottery, language learning, lead discovery,
 *     messaging, notifications and admin.
 *
 * It still FAILS CLOSED. Every gated surface boots blocked and stays blocked
 * until an authorised operator releases the switch; unknown surfaces are
 * treated as gated by no one (they are simply not in scope) but gated
 * surfaces never default to "allowed".
 */
final class KillSwitchScope
{
    /** Order-bound surfaces the kill switch blocks while it is engaged. */
    public const GATED_SURFACES = [
        'execution_supervisor',
        'broker_orders',
        'paper_orders',
        'automation_modes',
    ];

    /**
     * Surfaces that are explicitly out of scope. Kept as documentation and
     * asserted by the test suite so a future change cannot silently widen the
     * kill switch back into a global freeze.
     */
    public const UNGATED_SURFACES = [
        'market_data',
        'analysis',
        'sports_tickets',
        'lottery',
        'language_learning',
        'lead_discovery',
        'messaging',
    ];

    /**
     * Console routes that surface the Automatic Protection indicator:
     * broker + trading-intelligence pages (My Trading, Brokers, Execution,
     * Paper Trading, Risk Center, Strategy Lab and the market-data /
     * analysis console). Everywhere else — dashboard, language learning,
     * sports, lottery, leads, multiplier, messages, admin — protection is
     * neither advertised nor enforced.
     */
    public const UI_ROUTES = [
        'app/trading',
        'trading',
        'brokers',
        'execution',
        'paper',
        'risk',
        'strategy',
        'analysis',
    ];

    /** True when $surface is an order-bound surface the kill switch governs. */
    public static function gates(string $surface): bool
    {
        return in_array($surface, self::GATED_SURFACES, true);
    }

    /**
     * True when the kill switch blocks $surface right now.
     *
     * Out-of-scope surfaces always return false, so callers in non-trading
     * modules cannot accidentally re-introduce a global freeze.
     *
     * Fail closed: a gated surface whose switch state is missing or unreadable
     * is treated as blocked, never as allowed.
     *
     * @param array<string, mixed> $state Platform state (see Platform::state()).
     */
    public static function blocks(string $surface, array $state): bool
    {
        if (!self::gates($surface)) return false;
        $ks = $state['killSwitch'] ?? null;
        if (!is_array($ks) || !array_key_exists('active', $ks)) return true;
        return (bool) $ks['active'];
    }

    /** Human-readable reason used in rejections, proposals and audit trails. */
    public static function reason(string $surface): string
    {
        return 'kill switch is active — ' . $surface . ' is blocked until it is released';
    }

    /**
     * True when the current console route should render the kill switch
     * indicator / release control. Broker + trading-intelligence pages only.
     */
    public static function uiVisible(?string $uri = null): bool
    {
        $path = strtolower(trim((string) $uri, '/'));
        if ($path === '') return false;
        foreach (self::UI_ROUTES as $route) {
            if ($path === $route || str_starts_with($path, $route . '/')) return true;
        }
        return false;
    }
}
