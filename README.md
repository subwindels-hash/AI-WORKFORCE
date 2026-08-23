# AEGIS — Standalone AI Trading Intelligence Platform

A modular trading infrastructure that analyzes markets with a multi-agent AI stack, runs versioned
strategies through an evidence-gated pipeline, and generates structured, risk-reviewed trade
proposals. **This is not a chatbot giving trading advice** — it is a pipeline with hard separation
of concerns:

```text
MARKET DATA  →  ANALYSIS ENGINES  →  SPECIALIZED AI AGENTS  →  TRADING INTELLIGENCE / CONSENSUS
      →  STRATEGY ENGINE (backtested, validated, risk-reviewed)  →  RISK ENGINE
      →  EXECUTION SUPERVISOR (Phase 5)  →  BROKER CONNECTORS (Phase 4)
      →  PORTFOLIO + PERFORMANCE MONITORING
```

> **Core principle:** AI can analyze, recommend, and automate within approved rules, but it must
> never bypass market-data validation, risk controls, execution governance, broker safeguards, or
> the kill switch.

---

## Current state: Phases 1–2 complete (ANALYSIS_ONLY + Strategy Lab)

Phase 1 (analysis vertical slice) and Phase 2 (strategy framework, backtesting, trade journal)
are built, tested end-to-end and running. **No orders can be placed by any component.** The
status of every integration is reported honestly at `GET /api/system/features` and rendered in
the dashboard ("Integration Status" panel). Summary:

| Area | Status |
|---|---|
| Market-data abstraction (health checks, retry, timeout, circuit breaker, cache, fallback, provenance) | **TESTED** |
| Binance public market data (crypto) | **IMPLEMENTED** (reports DOWN + falls back when the host has no egress) |
| Frankfurter / ECB reference rates (forex, daily) | **IMPLEMENTED** (daily-only, honest about coverage) |
| Synthetic demo provider | **TESTED** — always labeled `SIMULATION / SYNTHETIC DATA`, never silent |
| Multi-agent analysis stack (technical, market-structure, forex, crypto, sentiment, consensus) | **TESTED** |
| Regime detection + Trade Setup Generator (zone entries, ATR/structure stops, R-ladders) | **TESTED** |
| Risk Engine (independent veto: per-trade + portfolio limits, sizing math, kill-switch participation) | **TESTED** |
| Event & audit trail (append-only JSONL + in-memory ring) | **TESTED** |
| **Strategy Engine + versioning** (4 built-ins: trend / mean-reversion / breakout / momentum; gated lifecycle `DRAFT → BACKTESTED → VALIDATED → RISK_REVIEWED → PAPER_TRADING → APPROVED`) | **TESTED** |
| **Backtesting Engine** (next-bar-open fills, fee/spread/slippage cost model, pessimistic stop-first rule, look-ahead guard, R-based sizing) | **TESTED** |
| **Performance metrics** (return, win rate, profit factor, expectancy, Sharpe, Sortino, drawdown, streaks, exposure) | **TESTED** |
| **Trade journal + analytics** (per-trade journal incl. confidence; groupings by strategy/market/symbol + confidence calibration) | **TESTED** |
| Grid / DCA strategies, AI-generated strategies | **PLANNED** (AI strategies are lifecycle-blocked from auto-advancement by design) |
| PostgreSQL persistence (`db/schema.sql` defined) / Redis | **PLANNED** — the tested runtime uses the JSON-file store behind the same `DataStore` contract |
| Paper trading, brokers (MT5 first), execution supervisor | **PLANNED** (Phases 3–5) |
| REST API (Fastify) + Next.js dashboard (Intelligence · Strategy Lab · Journal & Analytics) | **TESTED / running** |

**168 automated tests** (`packages/core`: 137, `apps/api`: 31) cover the indicator math, provider
fallback/circuit-breakers, agent honesty rules, the full analysis pipeline, risk-engine veto
paths, strategy signals, backtest fill/cost/anti-look-ahead mechanics, metrics fixtures,
lifecycle gates, journal analytics and the REST API.

---

## Repository layout

```text
packages/core        Domain library (no framework deps):
  src/types.ts         single source of truth for all contracts
  src/marketdata/      provider interface, manager (cache/retry/breaker/fallback), providers
  src/indicators/      technical-analysis engine (pure functions)
  src/agents/          technical, market-structure, forex, crypto, sentiment, intelligence
  src/analysis/        regime detection, setup generator
  src/strategies/      strategy contract, look-ahead-guarded SeriesView, 4 built-ins, registry
  src/backtesting/     event-driven simulator + metrics
  src/journal/         journal analytics + confidence calibration
  src/store/           DataStore contract, in-memory + JSON-file stores
  src/risk/            RiskEngine (independent veto authority)
  src/engine/          TradingIntelligenceEngine (the orchestrating pipeline)
  src/events/          event bus + append-only audit sink
  src/status/          integration honesty matrix (single source for /api/system/features)
apps/api             Fastify REST API (zod-validated routes; core + phase-2 route modules)
apps/dashboard       Next.js 14 + Tailwind command center (SVG charting, no chart lib)
db/schema.sql        PostgreSQL DDL for the production deployment (status: PLANNED)
data/                runtime store + audit log (gitignored)
```

## Quickstart

```bash
npm install
npm run build        # builds core + api
npm test             # 168 tests: core engines + API integration
npm run dev:api      # API on :4000  (ANALYSIS_ONLY, kill switch ON by default)
npm run dev:dashboard # dashboard on :3000 (proxies /api → :4000)
```

Docker Compose files are included for deployment convenience (see note in `docker-compose.yml`).

## The analysis pipeline (`POST /api/analysis/run`)

1. **Market data** — `ProviderManager` resolves candles through the provider chain
   (priority order, health/circuit-breaker gated, cached, retried) with full provenance.
   *Synthetic data is never silent* — it appears as a `SIMULATION / SYNTHETIC DATA` banner in the
   UI and forces a Risk-Engine veto.
2. **Normalization/validation** — sort, de-duplicate, envelope-repair, gap-check.
3. **Agents** — structured JSON with directional votes, weights, data quality and explicit
   `dataLimitations`; agents without data abstain. Agents hold no broker references (Rule 1).
4. **Consensus** — weighted net score, agreement, confluence, confidence, conflicts, and a
   `BULLISH / BEARISH / NEUTRAL / NO_TRADE` bias.
5. **Regime detection** with evidence, volatility percentile and ADX.
6. **Scenarios** and a structured **trade setup** (zone entry, ATR-padded invalidation stop,
   R-ladder targets, expiry, invalidation reasons).
7. **Risk Engine** — mandatory pass-through with veto power (Rule 6).

## The strategy pipeline (`POST /api/backtesting/run`)

Strategies are versioned and pass through an **evidence-gated lifecycle**:

```text
DRAFT → BACKTESTED → VALIDATED → RISK_REVIEWED → PAPER_TRADING (Phase 3) → APPROVED (Phase 5)
```

- `BACKTESTED` requires a completed backtest of that exact version.
- `VALIDATED` requires statistical evidence (sample size ≥ 10, profit factor > 1, drawdown
  ceiling, positive expectancy; over-fitting sentinels produce warnings).
- `RISK_REVIEWED` requires the risk review to pass — and **AI-generated strategies are blocked
  here without manual human sign-off** (spec: never auto-deploy AI strategies).
- `PAPER_TRADING`/`APPROVED` are refused honestly until Phases 3/5 ship.

The backtester is deliberately conservative:

- **No look-ahead** — strategies receive a `SeriesView` that throws on any future-bar access;
  the run fails loudly rather than continuing with biased results.
- **Realistic fills** — signals on closed bars fill at the *next bar's open*, adjusted for
  half-spread + adverse slippage; commissions are charged per side on notional.
- **Pessimistic ambiguity** — when a bar touches both stop and target, the stop fills first.
- **Full cost accounting** — every trade reports commission, spread and slippage components;
  net P&L reconciles exactly with the equity curve (unit-tested).
- **Provenance** — the run records its data source; synthetic runs are labeled as simulation.

Every simulated trade lands in the **trade journal** with fees, slippage, reason, and the
strategy's signal confidence — which powers the analytics: win rate / profit factor /
expectancy by strategy, market, symbol, and **AI-confidence bucket**
(`GET /api/analytics/confidence-calibration`) — the direct answer to "do high-confidence
decisions actually perform better?", with an honest sample-size guard.

## API surface

```text
GET  /api/system/status          platform state, kill switch, provider health, cache stats
GET  /api/system/features        integration honesty matrix
GET  /api/events                 audit trail
GET  /api/market-data/{providers,candles,quote}
POST /api/analysis/run           full AnalysisRun (agents → consensus → setup → risk)
GET  /api/analysis/history · /api/analysis/:id
GET  /api/agents · POST /api/agents/consensus
GET  /api/risk/limits · POST /api/risk/limits
POST /api/trading/kill-switch · POST /api/trading/mode (only ANALYSIS_ONLY implemented → 409 otherwise)
GET  /api/strategies · GET /api/strategies/:id · POST /api/strategies/:id/status
POST /api/backtesting/run · GET /api/backtesting/results · GET /api/backtesting/results/:id
GET  /api/journal · POST /api/journal (manual entries, validated)
GET  /api/analytics/summary?groupBy=strategy|market|symbol|source|confidence
GET  /api/analytics/confidence-calibration
```

## Critical rules enforcement

| Rule | Where it is enforced |
|---|---|
| 1. Agents never call brokers | Agents receive only an `AnalysisContext`; no broker type exists yet; the engine routes proposals to `RiskEngine`, never to a broker |
| 2. Never silently use fake data | `provenance.synthetic` flows through analysis, backtests and journal; UI shows mandatory banners; Risk Engine vetoes synthetic setups |
| 3. No integration claimed unless tested | `src/status/integrations.ts` is the single source; UI renders it verbatim |
| 4. Live trading disabled by default | Boot state: `ANALYSIS_ONLY` + kill switch ACTIVE; unimplemented modes return honest 409s |
| 5. Every trade auditable | Append-only event bus + JSONL sink; strategies/backtests/journal carry ids and full provenance |
| 6. Risk Engine veto power | `RiskEngine.evaluate` is the only path from setup → approval |
| 7. Kill switch blocks orders | Participates in every risk evaluation; vetoes all proposals while active |
| §12 strategy pipeline | Lifecycle gates: backtesting → validation → risk review → paper → approval; AI strategies blocked from auto-advancement |

## Safety defaults (`packages/core/src/config/defaults.ts` — all configurable)

```text
Risk per trade            1%   (hard cap 2%)
Min risk/reward           1.5        Stop loss        mandatory
Max leverage              5×         Max notional     $50,000
Max symbol risk           5% of equity (capital-at-risk basis)
Max portfolio risk        15% of equity
Max daily loss            3%   Max weekly loss 6%   Max drawdown 10%
Synthetic data            blocked from trade approval
Stale data                blocked
Kill switch               ships ACTIVE
```

## Roadmap

- **Phase 3** — paper trading: simulated accounts/orders/fills against live prices, portfolio &
  P&L tracking, `PAPER_TRADING` lifecycle stage unlocked.
- **Phase 4** — broker integration, **MT5 first** (Python bridge), then crypto exchanges one at
  a time. Connectors are only marked SUPPORTED once implemented and tested.
- **Phase 5** — Trade Execution Supervisor (15-step pipeline), human approval, semi-autonomous
  and fully-automated modes with all safety gates, duplicate protection, continuous portfolio
  risk monitoring.
- **Phase 6** — fundamentals, news/social sentiment, on-chain intelligence, options intelligence
  (Greeks/IV/max-pain only with real chain data), multi-agent debate, strategy & portfolio
  optimization.

## Disclaimer

Analysis-only software for research and education. Nothing here is investment advice. The demo
synthetic provider exists so the platform can run offline; synthetic analyses and backtests are
simulations, not market performance.
