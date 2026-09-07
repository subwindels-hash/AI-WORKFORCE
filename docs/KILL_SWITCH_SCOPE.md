# Kill switch scope — broker + trading intelligence only

**Status:** IMPLEMENTED (tested by `tests/cases/117-kill-switch-scope.php` and
case 51).

The platform kill switch is an **order-bound safeguard, not a global platform
freeze**. It is scoped to broker and trading-intelligence surfaces. Everywhere
else it is neither enforced nor advertised.

## What it gates

Engaging the kill switch blocks these four surfaces immediately:

| Surface | Where | Effect while engaged |
|---|---|---|
| `execution_supervisor` | `ExecutionSupervisor::propose()` — step 1 of the 15-step pipeline | Every proposal is rejected before it is persisted |
| `broker_orders` | `ExecutionSupervisor::route()` re-check + `Trading::submit_order()` | No order reaches MT5 / MT4 / crypto exchange / OANDA / Alpaca / IBKR |
| `paper_orders` | `PaperTradingEngine::submitOrder()` | Simulated order placement is vetoed (closing a position is still allowed — that is the unwind path) |
| `automation_modes` | `Platform::automationModeGate()` | `FULLY_AUTOMATED` cannot be enabled |

The single source of truth is `AIWorkforce\KillSwitchScope`:

```php
KillSwitchScope::gates('broker_orders');            // true
KillSwitchScope::blocks('paper_orders', $state);    // true only while engaged
KillSwitchScope::blocks('sports_tickets', $state);  // always false — out of scope
```

Every call site asks the scope, so a new order path cannot re-introduce a
global freeze by reading raw platform state, and an unknown surface is never
blocked by a trading safeguard.

`blocks()` is fail-closed in both directions: it returns `true` for a gated
surface whose switch state is missing or malformed (never "allowed by
default"), and `false` for anything outside the four surfaces no matter what
the state says.

## What it deliberately does not gate

* **Market data keeps streaming.** Crypto / Forex / Stock Market Data
  (Binance, Bybit, OKX, Coinbase, Kraken, Alpaca, OANDA, Frankfurter, Yahoo,
  IBKR) continue to serve quotes and candles while the switch is engaged. An
  engaged switch must not blind the operator: charts, analysis, provenance and
  provider health stay observable so the situation that caused the halt can
  still be diagnosed.
* **Non-trading modules are never gated** — sports odds-prediction ticket
  approval *and* settlement, EuroMillions/lottery, language learning, lead
  discovery, multiplier, messaging, notifications, admin. These have their own
  RBAC and, in the sports case, no broker order and no money movement in this
  deployment. See [`SPORTS_PRODUCTION_REVIEW.md`](SPORTS_PRODUCTION_REVIEW.md)
  finding 2.

## It still fails closed

Nothing about the default posture changed. Platform state boots with
`killSwitch.active = true`, so every gated surface starts blocked and stays
blocked until an authorised operator releases it.

Because engaging the switch blocks every order-bound surface platform-wide, it
is an **operator action**: both the console toggle (`POST /kill-switch`) and the
API (`POST /api/trading/kill-switch`) require `trading.control`, re-read from
the database rather than the session snapshot. The button only renders for
identities that hold it (`ai_workforce_kill_switch_can_control()`).

## Where the control and indicator appear

`KillSwitchScope::uiVisible()` limits the header pill, the dashboard-style
release buttons and the Risk Center control to broker + trading-intelligence
routes:

```
/app/trading   /trading   /brokers   /execution
/paper         /risk      /strategy  /analysis
```

Every other console route — dashboard, AI language teacher, sports, lottery,
leads, multiplier, messages, notifications, admin, command center — carries no
kill-switch UI at all.

## API

* `GET /api/system/status` → `killSwitch` plus
  `killSwitchScope: {gates: [...], marketDataUninterrupted: true}`
* `POST /api/trading/kill-switch` (`trading.control`) → `{killSwitch, scope}`
