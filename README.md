# AEGIS — Standalone AI Trading Intelligence Platform

**CodeIgniter 3.1.13 · PHP 8.x · MySQL/MariaDB · Traditional MVC**

A modular trading infrastructure that analyzes markets with a multi-agent AI
stack, runs versioned strategies through an evidence-gated pipeline, and
**simulates execution with full risk governance (Phase 3: Paper Trading)**.

> **Core principle:** AI can analyze, recommend, and automate within approved
> rules, but it must never bypass market-data validation, risk controls,
> execution governance, broker safeguards, or the kill switch.

```text
MARKET DATA  →  ANALYSIS ENGINES  →  SPECIALIZED AI AGENTS  →  TRADING INTELLIGENCE / CONSENSUS
      →  STRATEGY ENGINE (backtested, validated, risk-reviewed)  →  RISK ENGINE
      →  PAPER TRADING ENGINE (Phase 3)  →  [EXECUTION SUPERVISOR — Phase 5]  →  [BROKERS — Phase 4]
      →  PORTFOLIO + PERFORMANCE MONITORING
```

---

## Current state: Phases 1–3 complete; Phase 4 foundation underway

| Area | Status |
|---|---|
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
| MT5 bridge discovery / health status (environment-configured, no credentials exposed) | **IMPLEMENTED** (Phase 4 foundation) |
| Broker order routing, execution supervisor, live trading | **PLANNED** (Phases 4–5) |

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
php tools/install.php          # creates all tables (application/database/schema.mysql.sql)

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
                                Api_system, Api_analysis, Api_marketdata, Api_strategies,
                                Api_paper, Api_journal, Tools (CLI: install/tests)
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
    Platform                    service container wired from the model layer
  views/                        server-rendered dashboard (layout, welcome, strategy,
                                paper, journal) + SVG candlestick chart
  database/                     schema.mysql.sql (canonical) + schema.sqlite.sql (dev)
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

## API surface

`/api/system/{status,features}` · `/api/events` · `/api/trading/{kill-switch,mode,synthetic-paper}`
`/api/market-data/{candles,quote,providers}` · `/api/analysis/{run,history}` · `/api/agents/consensus`
`/api/strategies[/:id[/status]]` · `/api/backtesting/{run,results[/:id]}`
`/api/accounts[/create|/:id|/:id/order|/:id/positions|/:id/positions/:pid/close|/:id/tick|/:id/deploy|/:id/deployments]`
`/api/journal[/manual]` · `/api/analytics/{summary,confidence-calibration}` · `/api/risk/limits[/update]`

## Critical rules enforcement (unchanged from the platform spec)

| Rule | Enforcement |
|---|---|
| Agents never call brokers | Agents see only `AnalysisContext`; the paper engine routes every order through the Risk Engine; no broker code exists yet |
| Never silently use fake data | `provenance.synthetic` flows end-to-end; paper fills on synthetic prices require the explicit, audited `allowSyntheticPaperData` dev flag |
| No integration claimed unless tested | `GET /api/system/features` renders the same matrix |
| Live trading disabled by default | Boot state: `ANALYSIS_ONLY` + kill switch ACTIVE; only `ANALYSIS_ONLY` and `PAPER_TRADING` are implemented |
| Every trade auditable | `audit_logs` table + UI trail; every order/position/journal row is linked |
| Risk Engine veto power | `RiskEngine::evaluate()` sits in every order path |
| Kill switch blocks orders | Checked first in `submitOrder()` and in every Risk Engine context |

## Roadmap

- **Phase 4** — MT5 bridge discovery plus read-only account and quote reads
  are implemented through a separately deployed Python/MT5 bridge. Configure
  `AEGIS_MT5_BRIDGE_URL`, `AEGIS_MT5_BRIDGE_TOKEN`, and the explicit
  `AEGIS_MT5_BRIDGE_ENABLED=1` switch. It has no order-submission capability.
  Next: normalize broker account/quote contracts, then add crypto exchanges
  one at a time.
- **Phase 5** — Trade Execution Supervisor (15-step pipeline), human
  approval, semi/fully-automated modes, and only then broker order routing.
- **Phase 6** — fundamentals, sentiment, on-chain, options intelligence.

## Disclaimer

Analysis + simulation software for research and education. Nothing here is
investment advice. Synthetic demo data is always labeled as simulation.
