# AEGIS — Standalone AI Trading Intelligence Platform

**CodeIgniter 3.1.13 · PHP 8.x · MySQL/MariaDB · Traditional MVC**

A modular trading infrastructure that analyzes markets with a multi-agent AI
stack, runs versioned strategies through an evidence-gated lifecycle, and
governs every broker-bound order through the full execution-supervisor
pipeline (**Phase 4/5: MT5 trading surface + execution governance + portfolio
risk monitoring**).

> **Core principle:** AI can analyze, recommend, and automate within approved
> rules, but it must never bypass market-data validation, risk controls,
> execution governance, broker safeguards, or the kill switch.

```text
MARKET DATA  →  ANALYSIS ENGINES  →  SPECIALIZED AI AGENTS  →  TRADING INTELLIGENCE / CONSENSUS
      →  STRATEGY ENGINE (backtested, validated, risk-reviewed, paper-proven)  →  RISK ENGINE
      →  PAPER TRADING ENGINE  →  TRADE EXECUTION SUPERVISOR (15 steps, human approval / automation envelope)
      →  BROKER CONNECTOR (MT5 bridge, demo-gated)  →  PORTFOLIO + PERFORMANCE MONITORING
```

---

## Current state: Phases 1–3 complete; Phase 4/5 automation core complete

| Area | Status |
|---|---|
| CodeIgniter 3.1.13 MVC (controllers / models / views / libraries) | **TESTED** |
| **MySQL / MariaDB** persistence — canonical schema + mysqli config (`application/database/schema.mysql.sql`) | **IMPLEMENTED** |
| Market-data abstraction (health checks, retry, timeout, circuit breaker, cache, fallback, provenance) | **TESTED** |
| Binance + Frankfurter/ECB real providers; labeled synthetic demo provider | **TESTED** |
| Multi-agent analysis (technical, market-structure, forex, crypto, sentiment, consensus) | **TESTED** |
| Regime detection + trade setup generator + Risk Engine (independent veto, actual-volume checks) | **TESTED** |
| Strategy framework: 4 built-ins, evidence-gated lifecycle; **live APPROVED gate requires ≥10 paper trades with PF>1** | **TESTED** |
| Backtester: next-bar fills, cost model, pessimistic stop rule, look-ahead guard | **TESTED** |
| Paper Trading Engine: accounts, orders, fills, positions, ticks, strategy deployments | **TESTED** |
| Trade journal + analytics + confidence calibration | **TESTED** |
| **Trade Execution Supervisor — full 15-step pipeline** with durable auditable proposals | **TESTED** |
| **HUMAN_APPROVAL / SEMI_AUTONOMOUS / FULLY_AUTOMATED modes** (automation envelope: notional, daily cap, risk %, approved symbols) | **TESTED** |
| **MT5 connector — full trading surface** (account/quote/candles/positions/orders/history + place/modify/cancel/close) | **TESTED** (simulated bridge; not yet verified against a real MetaTrader terminal) |
| **Python MT5 bridge service** (`python-services/mt5-bridge`, FastAPI + MetaTrader5, demo-only default) | **IMPLEMENTED** (contract unit-tested; requires deployment on a Windows MT5 host) |
| **Portfolio Risk Monitor**: HIGH_EXPOSURE, EXCESSIVE_LEVERAGE, CORRELATED_POSITIONS, MAX_DRAWDOWN_WARNING, DAILY_LOSS_WARNING, BROKER_DISCONNECTED | **TESTED** |
| Kill switch, audit trail, ANALYSIS_ONLY default | **TESTED** |
| **RBAC on the trading API**: trading.view / trading.control / trading.execute (+ CSRF); approval decisions record the deciding operator | **TESTED** |
| **Notifications**: risk alerts, approval requests, execution outcomes, broker disconnects, kill switch — deduped until acknowledged | **TESTED** |
| **Scheduled operations worker** (`php index.php tools cron`): portfolio scan, broker transitions, proposal expiry | **TESTED** |
| MT4 / crypto-exchange / stock-broker connectors | **PLANNED** (added one at a time after MT5 is verified) |

**140 automated tests** run through the real CodeIgniter stack
(`php index.php tools tests` on any host; `node run-tests.mjs` in the offline
sandbox — see below), plus 9 contract tests for the Python bridge
(`python-services/mt5-bridge/.venv/bin/python -m pytest test_bridge.py`).

---
---|---|
| CodeIgniter 3.1.13 MVC (controllers / models / views / libraries) | **TESTED** |
| **MySQL / MariaDB** persistence — canonical schema + mysqli config (`application/database/schema.mysql.sql`) | **IMPLEMENTED** |
| Market-data abstraction (health checks, retry, timeout, circuit breaker, cache, fallback, provenance) | **TESTED** |
| Binance + Frankfurter/ECB real providers; labeled synthetic demo provider | **TESTED** |
| Multi-agent analysis (technical, market-structure, forex, crypto, sentiment, consensus) | **TESTED** |
| Regime detection + trade setup generator + Risk Engine (independent veto) | **TESTED** |
| Strategy framework: 4 built-ins, evidence-gated lifecycle through PAPER_TRADING | **TESTED** |
| Backtester: next-bar fills, cost model, pessimistic stop rule, look-ahead guard | **TESTED** |
| **Paper Trading Engine (Phase 3): accounts, orders, fills, positions, ticks, strategy deployments** | **TESTED** |
| Trade journal + analytics + confidence calibration | **TESTED** |
| ANALYSIS_ONLY + PAPER_TRADING modes, kill switch, audit trail | **TESTED** |
| MT5 bridge health + read-only account/quote contracts | **IMPLEMENTED** (Phase 4 foundation) |
| Execution supervisor preflight + persistent HUMAN_APPROVAL review workflow | **IMPLEMENTED** (Phase 5 foundation; never routes orders) |
| Broker order routing and live trading | **PLANNED** (Phase 5) |

**57 automated tests** run through the real CodeIgniter stack
(`php index.php tools tests` on any host; `node run-tests.mjs` in the offline
sandbox — see below).

---

## Production deployment (the normal path)

Requirements: PHP 7.4–8.3 with `mysqli` + `mbstring`, MySQL 5.7+/MariaDB 10.3+,
any web server (Apache/nginx + php-fpm).

```bash
# 1. Create the database + user, then:
export AEGIS_DB_HOST=127.0.0.1 AEGIS_DB_USER=aegis AEGIS_DB_PASS=... AEGIS_DB_NAME=aegis_trading
php tools/install.php          # creates all tables and RBAC defaults
# Create the first operator once; values are environment-only and never committed:
export AEGIS_BOOTSTRAP_ADMIN_EMAIL=admin@example.com AEGIS_BOOTSTRAP_ADMIN_PASSWORD='use-a-long-unique-password'
php index.php tools bootstrap_admin

# 2. Point the vhost at the repo root (index.php is the front controller),
#    set ENVIRONMENT=production, done:
php index.php tools tests      # verify the full stack against your MariaDB
```

Configuration is environment-driven (`application/config/database.php`):
`AEGIS_DB_DRIVER` (default `mysqli`), `AEGIS_DB_HOST/USER/PASS/NAME`.

## Offline dev / demo runtime (this repository's live preview)

The development sandbox has **no package mirrors and no MySQL server
egress** — it cannot run native PHP or MariaDB. The demo therefore runs the
**same CodeIgniter application** unmodified inside a WebAssembly PHP runtime
(`php-wasm` 8.2, host filesystem mounted) using CodeIgniter's built-in
`pdo_sqlite` driver with a schema that mirrors the MySQL DDL:

```bash
cd runtime && npm install
AEGIS_ALLOW_SYNTHETIC_PAPER=1 node server.mjs   # CI3 app on :8080
node run-tests.mjs                              # full test suite
```

This is a **dev bridge only** — `runtime/` is not part of the production
stack. Every honesty rule still applies: synthetic market prices are labeled
`SIMULATION` everywhere, and the `allowSyntheticPaperData` switch (which the
demo sets) is a persisted, audited platform-state flag that production leaves
off — with it off, the Risk Engine vetoes any synthetic-data trade.

---

## Repository layout (traditional CI3 MVC)

```text
index.php                       CI3 front controller (+ dev-bridge URI adapter)
system/                         CodeIgniter 3.1.13 core (unmodified)
application/
  config/                       config.php, database.php (mysqli/pdo_sqlite), routes.php
  controllers/                  Welcome (dashboard), Strategy_lab, Paper, Journal,
                                Execution (supervisor console), Brokers (broker center),
                                Risk_center (limits + monitor), Api_system, Api_analysis,
                                Api_marketdata, Api_strategies, Api_paper, Api_journal,
                                Tools (CLI: install/tests)
  models/Aegis_model.php        THE only place SQL lives — repository interfaces
                                implemented over CI3's query builder (mysqli/sqlite)
  libraries/Aegis/              domain layer (no framework dependency):
    Indicators, MathUtils, CandleNormalizer, Timeframes
    ProviderManager + Providers/ (Binance, Frankfurter, Synthetic)
    Agents/ (Technical, MarketStructure, Forex, Crypto, Sentiment, Intelligence)
    Analysis (regime + setup generator), RiskEngine
    Strategies/ (SeriesView w/ look-ahead guard, 4 built-ins, StrategyRegistry)
    Backtest/ (Backtester + Metrics), Journal/Analytics
    Paper/PaperTradingEngine    Phase 3: accounts/orders/fills/ticks/deployments
    Brokers/ (TradingConnector, Mt5BridgeConnector, BrokerDataNormalizer)
    ExecutionSupervisor         Phase 5: 15-step pipeline, proposals, routing
    Portfolio/PortfolioRiskMonitor  continuous portfolio risk alerts
    Platform                    service container wired from the model layer
  views/                        server-rendered dashboard (layout, welcome, strategy,
                                paper, execution, brokers, risk, journal) + SVG chart
  database/                     schema.mysql.sql (canonical) + schema.sqlite.sql (dev)
python-services/mt5-bridge/     Phase 4 bridge: FastAPI + MetaTrader5 service,
                                contract-tested with a simulated terminal
  helpers/aegis_helper.php      view-safe platform-state access
tests/                          framework.php + cases/*.php (57 tests)
tools/install.php               schema installer (mysqli or sqlite by driver)
runtime/                        offline WASM-PHP bridge (dev only, not production)
assets/css/aegis.css            dashboard styles (no CDN dependency)
```

## Phase 3 — Paper Trading (how it works)

Every paper order passes the **full governance chain before simulation**:

```text
kill switch → trading mode (PAPER_TRADING required) → duplicate check →
mandatory stop-loss → sizing (risk% × equity ÷ stop distance, notional-capped) →
Risk Engine (exposure, drawdown, daily/weekly loss) → fill
```

- **Market orders** fill instantly at the quoted price (spread + slippage +
  commission per side). **Limit orders** queue until a tick crosses them.
- **Ticks** (`POST /api/accounts/:id/tick`): fill pending limits, evaluate
  SL/TP on the latest candle with the **pessimistic stop-first rule**, and
  run **deployed strategies** on the latest closed bar — each signal is a
  fresh risk-checked paper order.
- **Strategy deployment** to a paper account is the `PAPER_TRADING`
  lifecycle stage (requires `RISK_REVIEWED`; AI-source strategies blocked
  without human sign-off).
- Closed positions land in the **journal** (source=paper) with fees, reason
  and decision confidence — feeding the confidence-calibration analytics.
- All events audited: `ORDER_SUBMITTED`, `ORDER_FILLED`, `POSITION_OPENED`,
  `POSITION_CLOSED`, `STOP_LOSS_TRIGGERED`, `KILL_SWITCH_*`, …
- Paper trading is **simulation**: no order ever leaves the process; broker
  connectors arrive in Phase 4.

### Quick demo flow (also in the Paper Trading console UI)

```bash
curl -X POST :8080/api/trading/mode -d '{"mode":"PAPER_TRADING"}' -H 'Content-Type: application/json'
curl -X POST :8080/api/trading/kill-switch -d '{"active":false}' -H 'Content-Type: application/json'
curl -X POST :8080/api/accounts/create -d '{"name":"Demo","startingBalance":25000}' -H 'Content-Type: application/json'
# -> account id 1
curl -X POST :8080/api/backtesting/run -d '{"strategyId":"trend-following","symbol":"BTCUSDT","marketClass":"crypto","timeframe":"1h","limit":1500}' -H 'Content-Type: application/json'
# lifecycle: BACKTESTED -> VALIDATED -> RISK_REVIEWED (POST /api/strategies/trend-following/status)
curl -X POST :8080/api/accounts/1/order -d '{"symbol":"BTCUSDT","side":"BUY","stopLoss":<2%below>,"reason":"...","confidence":0.72}' -H 'Content-Type: application/json'
curl -X POST :8080/api/accounts/1/deploy -d '{"strategyId":"trend-following","symbol":"ETHUSDT","timeframe":"1h","marketClass":"crypto"}' -H 'Content-Type: application/json'
curl -X POST :8080/api/accounts/1/tick
```

## Phase 4/5 — Execution governance (how it works)

Every broker-bound intent is a **durable, auditable proposal** that runs the
15-step pipeline inside `TradeExecutionSupervisor`:

```text
1 kill switch → 2 trading mode → 3 strategy (APPROVED lifecycle required for
automated intents) → 4 broker connection (bridge-VERIFIED order submission) →
5 market session → 6 data freshness → 7 duplicate orders → 8 symbol
permissions → 9 margin estimate → 10 Risk Engine (actual order volume:
notional / leverage / risk% / RR / exposure / daily+weekly loss / drawdown) →
automation envelope (SEMI/FULLY: max notional, max daily trades, max risk %,
approved symbols) → 11 human approval (HUMAN_APPROVAL mode) → 12 place order
→ 13 confirm execution → 14 audit log → 15 portfolio snapshot
```

- **Routing only happens through a connector whose bridge-verified status
  reports effective order submission** — otherwise the attempt is audited as
  `ROUTING_BLOCKED` and no order exists.
- The MT5 connector refuses orders unless `AEGIS_MT5_TRADING_ENABLED=1` AND
  the deployed bridge reports `tradingEnabled=true` AND the account is
  **demo** (unless `AEGIS_MT5_LIVE_ALLOWED=1`).
- The **Portfolio Risk Monitor** scans every paper account and connector;
  only alert *transitions* are audited (no spam). Correlation warnings use
  static disclosed groups — explicitly labeled heuristic, not statistical.
- Strategy lifecycle now includes the **live-approval gate**: `APPROVED`
  requires the PAPER_TRADING stage plus ≥10 closed paper trades with
  profit factor > 1 and positive expectancy.

### Operator access control, notifications, scheduled operations

- **RBAC** (seeded by the installer, shared matrix in `tools/rbac.php`):
  `trading.view` (read status/proposals/executions), `trading.control` (kill
  switch, mode, risk/automation limits), `trading.execute` (propose/decide/
  route). Mutating trading endpoints require session auth + `X-CSRF-Token`.
  The operator console stays server-rendered; API integrations authenticate
  via `POST /api/auth/login`.
- **Notifications** (`notifications` table + `/api/notifications` + the
  Alerts page): portfolio risk transitions, `TRADE_APPROVAL_REQUESTED`,
  `ORDER_FILLED`, `EXECUTION_FAILED`, `ROUTING_BLOCKED`, `BROKER_DISCONNECTED`
  / `BROKER_CONNECTED`, kill-switch activation, `PROPOSAL_EXPIRED`. Unread
  dedupe: one badge per active issue until acknowledged.
- **Cron worker** — run every minute:
  `* * * * * php /path/index.php tools cron`
  Executes the portfolio risk scan (with broker transition detection), expires
  undecided proposals after `proposalExpiryMinutes` (default 240, spec §5
  invalidation) and audits a `CRON_RUN` summary.

## API surface

`/api/auth/{login,me,logout}` · `/api/notifications[/read-all|/:id/read]`
`/api/system/{status,features}` · `/api/events` · `/api/trading/{kill-switch,mode,synthetic-paper}`
`/api/trading/limits[/update]` · `/api/trading/propose` · `/api/trading/execute` · `/api/trading/:id/{approve,route}`
`/api/execution/{preflight,proposals,executions}` · `/api/portfolio/risk-scan` · `/api/brokers` · `/api/brokers/mt5/{account,quote}`
`/api/market-data/{candles,quote,providers}` · `/api/analysis/{run,history}` · `/api/agents/consensus`
`/api/strategies[/:id[/status]]` · `/api/backtesting/{run,results[/:id]}`
`/api/accounts[/create|/:id|/:id/order|/:id/positions|/:id/positions/:pid/close|/:id/tick|/:id/deploy|/:id/deployments]`
`/api/journal[/manual]` · `/api/analytics/{summary,confidence-calibration}` · `/api/risk/limits[/update]`

## Critical rules enforcement (unchanged from the platform spec)

| Rule | Enforcement |
|---|---|
| Agents never call brokers | Agents see only `AnalysisContext`; every order path (paper AND broker) runs through the Risk Engine + Execution Supervisor; only the supervisor holds a TradingConnector |
| Never silently use fake data | `provenance.synthetic` flows end-to-end; paper fills on synthetic prices require the explicit, audited `allowSyntheticPaperData` dev flag |
| No integration claimed unless tested | `GET /api/system/features` renders the same matrix; unverified integrations are listed as PLANNED (Broker Center) |
| Live trading disabled by default | Boot state: `ANALYSIS_ONLY` + kill switch ACTIVE; broker routing needs an explicitly deployed bridge + `AEGIS_MT5_TRADING_ENABLED=1` + demo account; automated modes need a configured automation envelope |
| Every trade auditable | `audit_logs` table + UI trail; every order/position/journal row is linked |
| Risk Engine veto power | `RiskEngine::evaluate()` sits in every order path |
| Kill switch blocks orders | Checked first in `submitOrder()`, in the supervisor pipeline (step 1) and re-verified at routing time |

## Roadmap

- **Phase 4 (verification)** — the MT5 surface and the Python bridge are
  implemented and contract-tested with a simulated terminal. Remaining
  before any real-money consideration: deploy the bridge on a Windows host
  with a **demo** MT5 account, verify the PHP↔bridge path end-to-end, and
  only then review `AEGIS_MT5_LIVE_ALLOWED` (default stays off). Crypto
  exchanges are added **one at a time** after MT5 is verified.
- **Phase 5 (core + hardening done)** — supervisor pipeline, human approval,
  automation modes, kill switch, duplicate protection, broker health
  monitoring, portfolio risk monitoring, RBAC on the trading API,
  notifications and the scheduled-operations worker are implemented and
  tested. Next: a clearly-labeled SIMULATED bridge toggle for the offline
  demo (real routing still requires a deployed bridge).
- **Phase 6** — fundamentals agent boundary is implemented and explicitly
  abstains until a licensed, attributable feed is configured. Next: add
  licensed fundamentals, sentiment, on-chain, and options providers one at a
  time with provenance and freshness validation.

## Disclaimer

Analysis + simulation software for research and education. Nothing here is
investment advice. Synthetic demo data is always labeled as simulation.
