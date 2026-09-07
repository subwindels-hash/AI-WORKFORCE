# The Automatic Kill Switch — scope, states and configuration

**Status:** IMPLEMENTED (tested by `tests/cases/117-kill-switch-scope.php` and
`tests/cases/118-automatic-protection.php`).

The platform has an **Automatic Kill Switch and no manual one**. There is no
ON/OFF button, no manual toggle and no manual emergency switch anywhere in the
product — not in the console, not on the trading pages, not in the API, not in
the admin panel. Protection is *derived* from market and account conditions
every minute by `AIWorkforce\TradingProtection\AutomaticProtection`, which
protects the account when nobody is watching.

Administrators configure **thresholds** (`/admin/protection`); they never
command the switch itself.

## Why it is automatic

The protection layer outranks every trading component. It has higher priority
than AI trading agents, EAs, automated strategies, trading signals, manual
trading requests and market-data-driven recommendations, and **no trading
component may bypass an active Automatic Kill Switch** (§11):

```text
AI Trading Agent → Risk Engine → Automatic Kill Switch Check → Broker
                                    │
                    ACTIVE (PAUSED/KILL/RECOVERY) → REJECT TRADE
                    NORMAL / WARNING              → ALLOW (subject to
                                                    every other risk control)
```

The engine drives the platform's existing order gate (`state.killSwitch`), so
there is exactly one mechanism instead of a second parallel one. Every order
path already consults it — execution supervisor, broker routing, MT5/MT4,
crypto, forex and paper orders.

## The seven states (§7)

| State | Meaning | New trades |
|---|---|---|
| `NORMAL` | No risk condition detected | allowed |
| `WARNING` | Approaching a threshold (nothing blocked yet) | allowed |
| `AUTOMATIC_PAUSED` | A temporary, self-clearing block (news window, spread, stale data, broker down) | **blocked** |
| `AUTOMATIC_KILL` | A critical threshold was breached (daily loss, max drawdown, or an escalated technical failure) | **blocked** |
| `RECOVERY` | Conditions have cleared; confirming they stay clear | **blocked** |
| `RESUMED` | Automatically restarted; settles to `NORMAL` on the next scan | allowed |

Transitions are automatic in both directions. A kill is never downgraded to a
pause while anything still blocks, and trading never resumes straight from a
blocking state: `… → RECOVERY → (N consecutive clear scans) → RESUMED → NORMAL`.
`RECOVERY` requires `recovery.consecutiveClearScans` (default 2) confirmation
scans, and — when `recovery.requireAllClear` is on — every configured monitor
must be clear.

## What it monitors

| § | Monitor | Trigger | Default |
|---|---|---|---|
| 1 | High-impact news (NFP, CPI, FOMC, rate decisions, central banks) | `NEWS_EVENT`, `NEWS_APPROACHING`, `NEWS_NOT_CONFIGURED`, `NEWS_FEED_UNAVAILABLE` | 5 min before / 30 min after, pause |
| 2 | Daily loss — **percentage and/or fixed amount** | `DAILY_LOSS_LIMIT` (KILL), `DAILY_LOSS_APPROACHING` (WARNING) | 3% of equity |
| 3 | Maximum drawdown from the equity high-water mark | `MAX_DRAWDOWN` (KILL), `DRAWDOWN_APPROACHING` (WARNING) | 10% |
| 4 | Broker / MT4 / MT5 connectivity | `BROKER_DISCONNECTED` | pause (escalate-to-kill is configurable) |
| 4 | Market-data availability and staleness | `DATA_FEED_UNAVAILABLE`, `DATA_FEED_STALE` | 60 s feed timeout |
| 4 | Repeated order-execution failures | `ORDER_FAILURES` | 3 consecutive failures |
| 5 | Spread above the maximum | `SPREAD_EXCEEDED`, `SPREAD_UNREADABLE` | 30 points |
| 6 | Slippage above the maximum | `SLIPPAGE_EXCEEDED` | 10 points over the last 20 fills |
| 12 | Anything that cannot be verified | `METRICS_UNAVAILABLE`, `PROTECTION_UNVERIFIED` | **pause** |

**Points** follow the MT4/MT5 convention — the smallest price increment on the
feed: `0.00001` on 5-digit FX (so the 30-point default is 3 pips), `0.001` on
JPY crosses, `0.01` on metals, `0.1` on indices and 0.01% of price on crypto and
equities. Per-symbol overrides are supported (`EURUSD=12, XAUUSD=40`).

Spread is also checked **at order time**, not only on the scan, so a widening
quote blocks the next entry immediately and restores itself when the spread
normalises.

## Fail-safe (§12)

If safety cannot be determined — missing market data, lost connection, broken
news feed, broker failure, unreadable account metrics, invalid account
information, a risk-engine failure or a critical API failure — the default is
**pause new trading**. The engine never assumes it is safe:

* a configured but unreadable economic calendar pauses trading
  (`news.onFeedFailure = 'pause'`; administrators may downgrade it to `warn`);
* platform state boots with `killSwitch.active = true`, so a fresh install stays
  blocked until the first scans clear;
* a status older than `recovery.maxStatusAgeSeconds` (default 300 s) is
  re-evaluated before any order decision, and an evaluation that throws is
  treated as unverifiable.

The one deliberate exception: with **no** economic-calendar provider configured
at all, the default is a `WARNING` rather than a freeze, so a fresh install does
not silently stop trading. Set `news.pauseWhenNoProvider` to make it fail
closed.

## What it gates (unchanged scope)

Engaging the kill switch blocks these four surfaces immediately:

| Surface | Where | Effect while engaged |
|---|---|---|
| `execution_supervisor` | `ExecutionSupervisor::propose()` — step 1 of the 15-step pipeline | Every proposal is rejected before it is persisted |
| `broker_orders` | `ExecutionSupervisor::route()` re-check + `Trading::submit_order()` | No order reaches MT5 / MT4 / crypto exchange / OANDA / Alpaca / IBKR |
| `paper_orders` | `PaperTradingEngine::submitOrder()` | Simulated order placement is vetoed (closing a position is still allowed — that is the unwind path) |
| `automation_modes` | `Platform::automationModeGate()` | `FULLY_AUTOMATED` cannot be enabled |

The single source of truth is `AIWorkforce\KillSwitchScope`. It is now a
**read-only scope description**: it says which surfaces are in scope and where
the indicator may render, and it never commands the flag.

```php
KillSwitchScope::gates('broker_orders');            // true
KillSwitchScope::blocks('paper_orders', $state);    // true only while engaged
KillSwitchScope::blocks('sports_tickets', $state);  // always false — out of scope
```

`blocks()` is fail-closed in both directions: it returns `true` for a gated
surface whose switch state is missing or malformed (never "allowed by
default"), and `false` for anything outside the four surfaces no matter what
the state says.

## What it deliberately does not gate

* **Market data keeps streaming.** Crypto / Forex / Stock Market Data
  (Binance, Bybit, OKX, Coinbase, Kraken, Alpaca, OANDA, Frankfurter, Yahoo,
  IBKR) continue to serve quotes and candles while protection is active. An
  engaged switch must not blind the operator: charts, analysis, provenance and
  provider health stay observable so the situation that caused the halt can
  still be diagnosed.
* **Non-trading modules are never gated** — sports odds-prediction ticket
  approval *and* settlement, EuroMillions/lottery, language learning, lead
  discovery, multiplier, messaging, notifications, admin. These have their own
  RBAC and, in the sports case, no broker order and no money movement in this
  deployment. See [`SPORTS_PRODUCTION_REVIEW.md`](SPORTS_PRODUCTION_REVIEW.md)
  finding 2.

## Emergency actions (§2, §3)

On `AUTOMATIC_KILL` the engine always blocks new trades. Closing open positions
and cancelling pending orders are **opt-in policies, off by default**, because
they move money and cannot be verified against a live terminal from this
environment. Both are configurable, and every action is audited individually
(`PROTECTION_EMERGENCY_CLOSE`, `PROTECTION_EMERGENCY_CANCEL`,
`PROTECTION_EMERGENCY_FAILED`, `PROTECTION_EMERGENCY_POLICY`).

The policy is applied once per kill episode, keyed on the moment the kill began
and the configured actions — so an administrator who enables closing *while* a
kill is already active still gets the action on the next scan.

## Where the status appears (§8)

Instead of a manual control, every broker/trading surface shows **status and
reason**:

```text
🟢 NORMAL
🟠 PAUSED — High-impact NFP event in 8 minutes
🔴 ACTIVE — Daily loss limit reached (3.0%)
```

`KillSwitchScope::uiVisible()` limits that indicator to broker +
trading-intelligence routes:

```
/app/trading   /trading   /brokers   /execution
/paper         /risk      /strategy  /analysis
```

Every other console route — dashboard, AI language teacher, sports, lottery,
leads, multiplier, messages, notifications, admin, command center — carries no
kill-switch UI at all.

## Configuration (§9)

Administrators with `admin.settings.manage` configure every threshold at
**`/admin/protection`** (stored in platform state, applied to every account and
EA):

* news protection on/off, minutes before/after, impact levels, feed failure
  behaviour, warning lead time;
* daily loss — percentage **and** fixed monetary limit;
* maximum drawdown percentage;
* connection-loss, stale-data and order-failure limits, escalate-to-kill;
* maximum spread (global + per-symbol) and whether an unreadable spread blocks;
* maximum slippage and the sample window;
* emergency close / cancel behaviour;
* automatic recovery: on/off, require all clear, consecutive clear scans,
  maximum status age.

The economic calendar is an ordinary managed service: add an **Economic
Calendar** feed under Admin → API like any other provider, and its events drive
news protection. No vendor is hard-coded and no licence ships with the platform.

## Audit trail (§13)

Every transition is written to `audit_logs` with the previous state, the new
state, the trigger and the full risk picture (equity, balance, daily P&L,
drawdown, spread, slippage, open positions, accounts, brokers) plus a
notification at the matching severity:

```text
2026-09-07 10:30 UTC — AUTOMATIC KILL ACTIVATED — Daily loss reached 3%.
```

## API

* `GET /api/system/status` → `killSwitch`, `killSwitchScope` and
  `automaticProtection` (state, reason, metrics, triggers).
* `GET /api/system/protection` → read-only status + policy.
* `GET /api/system/protection/policy` → policy (admin only).
  `POST /api/system/protection/policy` → update thresholds (admin only, CSRF).
* There is deliberately **no** endpoint that engages or releases the switch.

## MT4/MT5 Expert Advisors (§10)

The EA-side implementation (Trade Manager, Equity Protector, News Filter EA,
Automatic Risk Manager) is a **follow-up pass**. The platform engine is the
authoritative layer today: because every order path routes through it, an EA-
placed order cannot bypass an active kill switch once the connector is wired to
this platform.
