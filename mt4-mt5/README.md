# MT4 / MT5 Expert Advisor protection (spec §10)

Automatic Kill Switch protection that runs **inside the terminal**.

The platform protects its own trading surfaces. An Expert Advisor runs on a
Windows host, in a terminal, often with nobody watching it — so it needs the
same protection where it lives, plus a way to be told about the platform's
decision. That is what this directory contains.

```
MT5 terminal                      bridge (python-services/mt5-bridge)        platform (PHP)
┌───────────────┐                 ┌───────────────────────────────┐          ┌──────────────────┐
│  EA + library │──heartbeat────▶ │ POST /v1/ea/heartbeat         │          │                  │
│  local gate   │                 │ GET  /v1/ea/heartbeats  ◀─────│──pull────│ EaProtection     │
│  blocks orders│◀──decision───── │ POST /v1/ea/decisions   ◀─────│──push────│ (policy + audit) │
└───────────────┘                 └───────────────────────────────┘          └──────────────────┘
```

Two independent layers, by design:

1. **Local enforcement** — the library evaluates 14 conditions on every tick and
   `AIWF_AllowNewTrades()` gates every order. This works with no network at all.
2. **The platform's decision** — the same administrator policy, evaluated with
   the platform's view (calendar feed, broker health, cross-account exposure)
   and published back. When the two disagree, **the stricter wins** (§11).

Neither layer is optional and neither can be switched off from the terminal by
accident: there is no manual override anywhere in this package.

## What is in here

| Path | What it is |
|---|---|
| `MQL5/Include/AIWorkforceProtection.mqh` | MT5 protection library — the engine every MT5 EA includes |
| `MQL5/Experts/AIWorkforceTradeManager.mq5` | Break-even / trailing management, entries gated by protection |
| `MQL5/Experts/AIWorkforceEquityProtector.mq5` | Daily loss (percent **and** fixed) + drawdown + margin |
| `MQL5/Experts/AIWorkforceNewsFilter.mq5` | High-impact news windows (NFP, CPI, FOMC, rate decisions) |
| `MQL5/Experts/AIWorkforceRiskManager.mq5` | Position sizing from the risk budget, spread/slippage gating |
| `MQL4/…` | The same four EAs and the library for MT4 (build 600+) |

## Install

1. Copy `Include/AIWorkforceProtection.mqh` into
   `MQL5/Include/` (MT5) or `MQL4/Include/` (MT4) — *not* into `Experts/`.
2. Copy the `Experts/*.mq5` (or `.mq4`) files you want into
   `MQL5/Experts/` (or `MQL4/Experts/`).
3. In MetaEditor, open each EA and compile (F7). Attach it to a chart.
4. Set the inputs. The defaults match the platform's documented defaults
   (§14): daily loss 3%, drawdown 10%, 5 minutes before / 30 minutes after
   high-impact news, spread 30 points, slippage 10 points.
5. For reporting, allow the bridge URL in the terminal:
   **Tools → Options → Expert Advisors → Allow WebRequest for listed URL** →
   add `http://127.0.0.1:8787` (or wherever the bridge runs). Without this the
   EA still protects the account locally, and prints a warning telling you why
   it is not reporting.

## The 14 conditions

Evaluated on every tick, in this order — the first blocking condition decides:

| # | Condition | Default | Effect |
|---|---|---|---|
| 1 | Terminal not connected | — | PAUSE |
| 2 | Broker / trade server unavailable, or automated trading disabled | — | PAUSE |
| 3 | No tick (market data unavailable) | — | PAUSE |
| 4 | Quote stale | > 60 s | PAUSE |
| 5 | Abnormal price move in one tick | > 5% | PAUSE |
| 6 | Spread above maximum | > 30 points | PAUSE |
| 7 | Slippage above maximum | > 10 points | PAUSE |
| 8 | Consecutive order failures | ≥ 3 | PAUSE |
| 9 | Daily loss — percentage of equity | ≥ 3% | KILL |
| 10 | Daily loss — fixed amount (account currency) | off (0) | KILL |
| 11 | Drawdown from the equity high-water mark | ≥ 10% | KILL |
| 12 | Margin level below floor | < 150% | PAUSE |
| 13 | High-impact news window | 5 before / 30 after | PAUSE |
| 13b | News list configured but **unreadable** (typo, wrong format) | — | PAUSE |
| 14 | Platform decision missing or stale | > 180 s | PAUSE (only when `InpRequirePlatformDecision`) |

A **point** is the terminal's `SYMBOL_POINT`, not a pip: 30 points is 3 pips on
a 5-digit EURUSD feed, $0.30 on gold, 3 index points on US30. The platform uses
the same convention (`ProtectionPolicy::pointSize()`), so one number means one
thing everywhere.

**Warnings** (`🟠`) are reported for conditions approaching a threshold — daily
loss at 80% of its limit, drawdown at 80%, a news event inside the warning
lead. A warning **never** blocks trading; it exists so an operator can see the
edge coming.

## States (§7)

`NORMAL → WARNING → AUTOMATIC_PAUSED / AUTOMATIC_KILL → RECOVERY → RESUMED → NORMAL`

* `AUTOMATIC_KILL` is a one-way door per episode: it is never downgraded to a
  pause by a later, milder reading.
* Coming back needs `InpRecoveryScansRequired` consecutive clear evaluations
  (default 2). A condition that flickers cannot flap the account in and out of
  the market.
* The first evaluation after a restart is **not** gated by those scans — there
  is nothing to recover from yet.

## Emergency actions

New trades are **always** blocked while a PAUSE or KILL is active. Closing open
positions and cancelling pending orders are **opt-in**, both here
(`InpClosePositionsOnKill`, `InpCancelPendingOnKill`) and in the platform policy
(Admin → Automatic Kill Switch → *Emergency actions*). Either switch being on
is enough, because closing is a money-moving action and the safer default is to
do nothing beyond stopping the bleeding of new risk.

## Reporting

### Heartbeat (EA → bridge → platform)

Every `InpHeartbeatSeconds` the EA posts what it sees:

```json
{
  "eaId": "5123456-EURUSD-900001-TradeManager",
  "name": "TradeManager", "terminal": "MT5",
  "account": "5123456", "broker": "Demo Broker Ltd", "symbol": "EURUSD", "magic": 900001,
  "at": "2026-09-07T10:30:00Z", "atTs": 1780000000,
  "metrics": { "equity": 10000.0, "balance": 10000.0, "dailyPnl": -120.0, "drawdownPct": 4.5,
               "peakEquity": 10470.0, "openPositions": 1, "pendingOrders": 0,
               "marginLevelPct": 820.0, "freeMargin": 9800.0, "spreadPoints": 1.2,
               "slippagePoints": 0.0, "orderFailures": 0, "symbol": "EURUSD" },
  "connection": { "terminal": true, "broker": true, "dataFeed": true, "lastTickAgeSeconds": 1 },
  "news": { "configured": false, "ok": true, "minutesToNextHighImpact": null },
  "actions": { "closedPositions": 0, "cancelledOrders": 0, "blockedOrders": 0 }
}
```

`actions` counts what protection did since the EA started: positions closed and
pending orders cancelled by the emergency policy, and entries refused by the
gate (`AIWF_RecordBlockedOrder()`). `metrics.priceMovePercent` is the last
tick's move, reset every tick, so a stale spike cannot pin the account in a
pause.

The `eaId` is `account-symbol-magic-EA name`, so two copies of the same EA on
two accounts are two deployments.

An EA that stops reporting is **paused, not trusted** (§12). The platform's
default heartbeat timeout is 120 s; raising it per deployment is an
administrator decision (Admin → Automatic Kill Switch → *Per-EA limits*).

If an EA can reach the platform directly, it can post the same heartbeat to
`POST /api/system/protection/ea` with the `X-EA-Token` header (set
`AI_WORKFORCE_EA_TOKEN` on the host) and receives its decision in the response.

### Decision (platform → bridge → EA)

MQL has no JSON parser, so the bridge serves the decision as flat text:

```
eaId=5123456-EURUSD-900001-TradeManager
state=AUTOMATIC_PAUSED
code=EA_NEWS_EVENT
reason=High-impact event in 3 minute(s).
allowNewTrades=0
closePositions=0
cancelPendingOrders=0
evaluatedAt=2026-09-07T10:30:00Z
policy.dailyLossPercent=3.00
policy.dailyLossFixedUsd=100
policy.drawdownPercent=10.00
policy.maxSpreadPoints=30
policy.maxSlippagePoints=10
policy.newsEnabled=1
policy.newsMinutesBefore=5
policy.newsMinutesAfter=30
policy.closePositionsOnKill=0
policy.cancelPendingOrdersOnKill=0
```

`state` uses the platform's names: `NORMAL`, `WARNING`, `AUTOMATIC_PAUSED`,
`AUTOMATIC_KILL`, `RECOVERY`, `RESUMED`. Anything unrecognised is treated as
`AUTOMATIC_PAUSED` — an unknown state blocks, it never permits.

### The `policy.*` lines: the limits that actually govern this EA (§9)

The platform resolves a policy per deployment — **platform policy ← account
override ← deployment override** — and sends the result with every decision.
The EA obeys those numbers instead of its own inputs, so an override set in the
admin console reaches the terminal rather than living only in the platform's
audit trail.

| Decision key | Overrides input | Units |
| --- | --- | --- |
| `policy.dailyLossPercent` | `InpDailyLossPercent` | percent (`3.00` = 3%) |
| `policy.dailyLossFixedUsd` | `InpDailyLossFixedUsd` | account currency |
| `policy.drawdownPercent` | `InpMaxDrawdownPercent` | percent |
| `policy.maxSpreadPoints` | `InpMaxSpreadPoints` | MT4/MT5 points |
| `policy.maxSlippagePoints` | `InpMaxSlippagePoints` | MT4/MT5 points |
| `policy.newsEnabled` | — (0 skips the local news window) | 0/1 |
| `policy.newsMinutesBefore` / `After` | `InpNewsMinutes*` | minutes |
| `policy.closePositionsOnKill` | `InpClosePositionsOnKill` | 0/1 |
| `policy.cancelPendingOrdersOnKill` | `InpCancelPendingOnKill` | 0/1 |

Two rules keep this safe:

* **Until the first decision arrives, the inputs apply.** Every `policy.*` value
  starts at `-1` ("not published"), so a terminal that has never heard from the
  platform is still protected by whatever the operator typed into the EA.
* **A platform decision can only tighten, never release.** The decision's own
  `closePositions=1` / `cancelPendingOrders=1` still force the emergency
  actions even when the policy says off, and blocking new trades is never
  optional.

The effective values are logged once per decision
(`effective policy: loss 3.00%/… in EaProtection`), so the terminal's log shows
which numbers applied.

An EA that requires a fresh platform decision sets
`InpRequirePlatformDecision = true`; it then refuses to trade whenever the
decision is missing or older than `InpDecisionMaxAgeSeconds`. That is the
strictest reading of §12 and is **off by default**, because a terminal on a
flaky link would otherwise stop trading every time the platform is briefly
unreachable — the local engine is the one that must keep working.

## Per-account and per-EA configuration on the platform

*Admin → Protection → Expert Advisors* has two override layers:

* **Account** (`mt5:5123456`) — applies to every EA reporting on that terminal
  account. Configure a prop-firm account once and all of its EAs follow.
* **Deployment** (one `eaId`) — the narrowest scope, wins over the account.

Blank fields **inherit** the wider scope, so an override only pins what differs
and keeps tracking the platform policy for everything else. Values are
validated and clamped exactly like the platform form, in percent / points /
minutes. `tools/check_mql.php` fails if a policy value the platform publishes
is not read by both terminal flavours.

## News events in the terminal

The platform reads real calendars (Admin → API → *Economic Calendar*). The EA
cannot, so `InpNewsEvents` is the fallback — a `;`-separated list of event times
in the terminal's own timezone:

```
2026.09.04 12:30;2026.09.10 12:30;2026.09.17 18:00
```

Leave it empty and no local window is enforced (the EA says so in the log); the
platform's calendar still applies whenever the EA is reporting. Set it but get
the format wrong and the EA **pauses** with `EA_NEWS_FEED_UNAVAILABLE` — a typo
must not silently switch news protection off (§12). When the EA
knows about a window, it also sends `news.minutesToNextHighImpact` so the
platform can pause it from the platform side too.

## Verifying the sources without MetaEditor

MetaEditor runs on Windows and needs a terminal, so the MQL cannot be compiled
here. What *can* silently break is drift: a condition added to the PHP engine,
or a field renamed, leaving an EA quietly enforcing yesterday's rules.
`tools/check_mql.php` pins the contract between the three languages:

```bash
php tools/check_mql.php          # exit 0 = clean, 1 = drift
```

It reads the condition codes out of `EaProtection::conditions()` and the
heartbeat fields out of `EaProtection::normalizeHeartbeat()` — both derived
from the code, not from a hand-written list — and checks that:

1. every source is balanced (braces, parentheses, brackets; strings and
   comments ignored);
2. both libraries expose the API the EAs call (20 entry points);
3. **every** condition the platform can raise is implemented in both terminals
   (16 today) — add one to PHP and this fails until the EAs catch up;
4. every order-sending function asks `AIWF_AllowNewTrades()` first, and every
   EA that can refuse an entry records it with `AIWF_RecordBlockedOrder()`;
5. every heartbeat field the platform ingests is produced by both libraries;
6. every decision key the MQL parses is written by the Python bridge.

It is dependency-free — no CodeIgniter, no database, no MQL toolchain.

## Status

**Implemented and unit-tested on the platform side
(`tests/cases/119-expert-advisor-protection.php`, 21 cases) and contract-checked
against the engine by `php tools/check_mql.php` (27 checks). The MQL sources
are NOT compiled or run against a live terminal in this repository** — that
needs MetaEditor on Windows and a demo account. Before trusting them with
money: compile each EA, watch the chart comment show `🟢 NORMAL`, attach to a
demo account, and confirm that a forced breach (e.g. set the daily loss input
to 0.1%) blocks a new order and writes the reason on the chart.
