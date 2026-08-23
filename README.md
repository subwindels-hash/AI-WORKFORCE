# AEGIS — Standalone AI Trading Intelligence Platform

A modular trading infrastructure that analyzes markets with a multi-agent AI stack and generates
structured, risk-reviewed trade proposals. **This is not a chatbot giving trading advice** — it is a
pipeline with hard separation of concerns:

```text
MARKET DATA  →  ANALYSIS ENGINES  →  SPECIALIZED AI AGENTS  →  TRADING INTELLIGENCE / CONSENSUS
      →  STRATEGY ENGINE (Phase 2)  →  RISK ENGINE  →  EXECUTION SUPERVISOR (Phase 5)
      →  BROKER CONNECTORS (Phase 4)  →  PORTFOLIO + PERFORMANCE MONITORING
```

> **Core principle:** AI can analyze, recommend, and automate within approved rules, but it must
> never bypass market-data validation, risk controls, execution governance, broker safeguards, or
> the kill switch.

---

## Current state: Phase 1 complete (ANALYSIS_ONLY)

Built and tested end-to-end as a working vertical slice — **no orders can be placed by any
component.** The status of every integration is reported honestly at `GET /api/system/features`
and rendered in the dashboard ("Integration Status" panel). Summary:

| Area | Status |
|---|---|
| Market-data abstraction (health checks, retry, timeout, circuit breaker, cache, fallback, provenance) | **TESTED** |
| Binance public market data (crypto) | **IMPLEMENTED** (reports DOWN + falls back when the host has no egress) |
| Frankfurter / ECB reference rates (forex, daily) | **IMPLEMENTED** (daily-only, honest about coverage) |
| Synthetic demo provider | **TESTED** — always labeled `SIMULATION / SYNTHETIC DATA`, never silent |
| Technical Analysis Agent (SMA/EMA/RSI/MACD/BB/ATR/ADX/VWAP/Stochastic/S-R/pivots/volume profile) | **TESTED** |
| Market Structure Agent (swings, BOS/CHoCH close-confirmation, liquidity, S/D zones, order blocks, FVG) | **TESTED** |
| Forex Agent (classification, volatility, sessions, price-momentum currency strength) | **TESTED** |
| Crypto Agent (price/volume/volatility; on-chain/derivatives/dominance honestly "unavailable") | **TESTED** |
| Sentiment Agent (abstains — no news/social provider configured yet) | **TESTED** |
| Trading Intelligence Agent (confluence, confidence, conflict detection, BUY/SELL/HOLD/NO_TRADE) | **TESTED** |
| Regime detection (TRENDING_UP/DOWN, RANGING, HIGH/LOW_VOLATILITY, BREAKOUT, UNKNOWN) | **TESTED** |
| Trade Setup Generator (zone entry, ATR/structure stop, R-ladder targets, expiry, invalidation) | **TESTED** |
| Risk Engine (independent veto: per-trade + portfolio limits, sizing math, kill-switch participation) | **TESTED** |
| Event & audit trail (append-only JSONL + in-memory ring) | **TESTED** |
| REST API (Fastify) + Next.js dashboard | **TESTED / running** |
| Strategy engine, backtesting, paper trading, brokers (MT5 first), execution supervisor, options/fundamental intelligence | **PLANNED** (Phases 2–6) |

**94 automated tests** (`packages/core`: 78, `apps/api`: 16) cover the indicator math against
hand-computed fixtures, provider fallback/circuit-breakers, agent honesty rules, the full
analysis pipeline, risk-engine veto paths, and the REST API.

---

## Repository layout

```text
packages/core        Domain library (no framework deps):
  src/types.ts         single source of truth for all contracts
  src/marketdata/      provider interface, manager (cache/retry/breaker/fallback), providers
  src/indicators/      technical-analysis engine (pure functions)
  src/agents/          technical, market-structure, forex, crypto, sentiment, intelligence
  src/analysis/        regime detection, setup generator
  src/risk/            RiskEngine (independent veto authority)
  src/engine/          TradingIntelligenceEngine (the orchestrating pipeline)
  src/events/          event bus + append-only audit sink
  src/status/          integration honesty matrix (single source for /api/system/features)
apps/api             Fastify REST API (zod-validated routes)
apps/dashboard       Next.js 14 + Tailwind command center (SVG charting, no chart lib)
data/                runtime audit-log JSONL (gitignored)
```

## Quickstart

```bash
npm install
npm run build        # builds core + api
npm test             # 94 tests: core engine + API integration
npm run dev:api      # API on :4000  (ANALYSIS_ONLY, kill switch ON by default)
npm run dev:dashboard # dashboard on :3000 (proxies /api → :4000)
```

Docker Compose files are included for deployment convenience (see note in `docker-compose.yml`).

## The pipeline (what happens on `POST /api/analysis/run`)

1. **Market data** — `ProviderManager` resolves candles through the provider chain
   (priority order, health/circuit-breaker gated, cached, retried). Every result carries
   **provenance**: source, data timestamp, age, live/delayed/synthetic flags, and the failed
   fallback chain. *Synthetic data is never silent* — it appears as a `SIMULATION / SYNTHETIC DATA`
   banner in the UI and forces a Risk-Engine veto.
2. **Normalization/validation** — candles are sorted, de-duplicated, envelope-repaired, gap-checked.
3. **Agents** — applicable agents run and return structured JSON with a directional vote
   (`[-1,1]`), weight, data-quality score, and explicit `dataLimitations`. Agents that lack data
   (e.g. sentiment with no provider) **abstain** rather than fabricate. Agents hold no broker
   references and cannot place orders (Rule 1).
4. **Consensus** — the Trading Intelligence Agent computes the weighted net score, agreement,
   confluence, confidence, conflicts, and a `BULLISH / BEARISH / NEUTRAL / NO_TRADE` bias.
5. **Regime detection** with evidence, volatility percentile and ADX.
6. **Scenarios** — bullish / bearish / neutral with triggers, targets, invalidations.
7. **Trade setup** (only when bias is directional and confidence clears the threshold) —
   entry ZONE anchored to structure, ATR-padded invalidation stop, 1.5R/2.5R/3.5R target ladder
   snapped to S/R, expiry, invalidation reasons.
8. **Risk Engine** — mandatory pass-through with veto power (Rule 6): data governance
   (kill switch, synthetic/stale/quality gates), per-trade checks (stop mandatory, R:R floor,
   risk %, hard cap, notional, leverage) and portfolio checks (risk concentration, exposure,
   daily/weekly loss, drawdown, open positions), plus exact sizing math.

Every step emits audit events (`TRADE_ANALYZED`, `SIGNAL_GENERATED`, `RISK_APPROVED` /
`RISK_REJECTED`, `PROVIDER_FALLBACK`, `KILL_SWITCH_*`, …) visible at `GET /api/events`.

## API surface (Phase 1)

```text
GET  /api/system/status          platform state, kill switch, provider health, cache stats
GET  /api/system/features        integration honesty matrix (IMPLEMENTED/TESTED/PLANNED/…)
GET  /api/events                 audit trail
GET  /api/market-data/providers  registry + capabilities + health
GET  /api/market-data/candles    normalized candles + provenance + validation
GET  /api/market-data/quote      last quote + source
POST /api/analysis/run           {symbol, marketClass, timeframe} → full AnalysisRun
GET  /api/analysis/history       recent run summaries
GET  /api/analysis/:id           full stored run
GET  /api/agents                 agent registry
POST /api/agents/consensus       multi-symbol consensus (watchlist)
GET  /api/risk/limits            risk limits + paper-portfolio baseline
POST /api/risk/limits            update limits (validated, clamped to hard caps)
POST /api/trading/kill-switch    {active, reason}
POST /api/trading/mode           only ANALYSIS_ONLY is implemented; others → 409 (honest)
```

## Critical rules enforcement

| Rule | Where it is enforced |
|---|---|
| 1. Agents never call brokers | Agents receive only an `AnalysisContext`; no broker type exists in Phase 1; the engine routes proposals to `RiskEngine`, never to a broker |
| 2. Never silently use fake data | `provenance.synthetic` flows from provider → series → analysis → risk veto; dashboard shows a mandatory banner |
| 3. No integration claimed unless tested | `src/status/integrations.ts` is the single source; UI renders it verbatim |
| 4. Live trading disabled by default | Boot state: `ANALYSIS_ONLY` + kill switch ACTIVE; the API refuses unimplemented modes with 409 |
| 5. Every trade auditable | Append-only event bus + JSONL sink; every proposal carries a run id and risk decision |
| 6. Risk Engine veto power | `RiskEngine.evaluate` is the only path from setup → approval; it cannot be bypassed by the AI stack |
| 7. Kill switch blocks orders | Participates in every risk evaluation; while active all proposals are vetoed with an explicit reason |

## Safety defaults (`packages/core/src/config/defaults.ts` — all configurable)

```text
Risk per trade            1%   (hard cap 2%)
Min risk/reward           1.5
Stop loss                 mandatory
Max leverage              5×
Max position notional     $50,000
Max symbol risk           5% of equity (capital-at-risk basis)
Max portfolio risk        15% of equity
Max daily loss            3%      Max weekly loss 6%    Max drawdown 10%
Synthetic data            blocked from trade approval
Stale data                blocked
Kill switch               ships ACTIVE
```

## Roadmap (per the platform specification)

- **Phase 2** — strategy framework + versioning, historical data store, backtesting engine
  (anti-look-ahead, fees/spread/slippage), performance metrics, trade journal. PostgreSQL +
  Redis join the stack.
- **Phase 3** — paper trading: simulated accounts/orders/fills against live prices, portfolio &
  P&L tracking.
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
synthetic provider exists so the platform can run offline; synthetic analyses are simulations,
not market reality.
