-- AEGIS Platform — PostgreSQL schema (Phase 2 scope)
--
-- STATUS: PLANNED. The schema is defined and ready for the production
-- deployment; the tested Phase 2 runtime uses the JSON file store that
-- implements the identical DataStore contract (packages/core/src/store).
-- A PostgreSQL repository adapter will be introduced when the platform runs
-- with a database service (docker-compose profile "full"). Until that
-- adapter is implemented AND covered by tests, the platform does not claim
-- PostgreSQL support (integration honesty rule).

CREATE TABLE IF NOT EXISTS strategies (
  strategy_id   TEXT NOT NULL,
  version       TEXT NOT NULL,
  name          TEXT NOT NULL,
  description   TEXT,
  market_classes TEXT[] NOT NULL,
  timeframes    TEXT[] NOT NULL,
  params        JSONB NOT NULL DEFAULT '{}',
  source        TEXT NOT NULL CHECK (source IN ('builtin','user','ai')),
  lifecycle     TEXT NOT NULL CHECK (lifecycle IN
    ('DRAFT','BACKTESTED','VALIDATED','RISK_REVIEWED','PAPER_TRADING','APPROVED','RETIRED')),
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  PRIMARY KEY (strategy_id, version)
);

CREATE TABLE IF NOT EXISTS strategy_lifecycle_history (
  id         BIGSERIAL PRIMARY KEY,
  strategy_id TEXT NOT NULL,
  version    TEXT NOT NULL,
  from_state TEXT,
  to_state   TEXT NOT NULL,
  reason     TEXT,
  changed_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  FOREIGN KEY (strategy_id, version) REFERENCES strategies (strategy_id, version)
);

CREATE TABLE IF NOT EXISTS backtests (
  id         UUID PRIMARY KEY,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  strategy_id TEXT NOT NULL,
  strategy_version TEXT NOT NULL,
  request    JSONB NOT NULL,       -- full request incl. symbol/timeframe/costs
  provenance JSONB NOT NULL,       -- data source, synthetic flag, range
  metrics    JSONB NOT NULL,       -- BacktestMetrics
  equity_curve JSONB NOT NULL,
  trades     JSONB NOT NULL,
  warnings   JSONB NOT NULL DEFAULT '[]'
);

CREATE INDEX IF NOT EXISTS idx_backtests_strategy ON backtests (strategy_id, created_at DESC);

CREATE TABLE IF NOT EXISTS trade_journal (
  id           UUID PRIMARY KEY,
  source       TEXT NOT NULL CHECK (source IN ('backtest','manual','paper','live')),
  symbol       TEXT NOT NULL,
  market       TEXT NOT NULL,
  strategy     TEXT,
  strategy_version TEXT,
  direction    TEXT NOT NULL CHECK (direction IN ('LONG','SHORT')),
  entry_time   TIMESTAMPTZ NOT NULL,
  entry_price  NUMERIC NOT NULL,
  exit_time    TIMESTAMPTZ,
  exit_price   NUMERIC,
  position_size NUMERIC,
  stop_loss    NUMERIC,
  take_profit  NUMERIC,
  fees         NUMERIC,
  slippage     NUMERIC,
  pnl          NUMERIC,
  pnl_pct      NUMERIC,
  r_multiple   NUMERIC,
  reason       TEXT,
  ai_confidence NUMERIC,
  confidence_source TEXT,
  agent_consensus TEXT,
  risk_score   NUMERIC,
  execution_time TIMESTAMPTZ NOT NULL DEFAULT now(),
  backtest_id  UUID REFERENCES backtests (id),
  analysis_run_id UUID,
  notes        TEXT
);

CREATE INDEX IF NOT EXISTS idx_journal_symbol ON trade_journal (symbol, execution_time DESC);
CREATE INDEX IF NOT EXISTS idx_journal_strategy ON trade_journal (strategy, execution_time DESC);
CREATE INDEX IF NOT EXISTS idx_journal_confidence ON trade_journal (ai_confidence);

-- Append-only audit trail for critical actions (already emitted by the event bus).
CREATE TABLE IF NOT EXISTS audit_logs (
  id      BIGSERIAL PRIMARY KEY,
  type    TEXT NOT NULL,
  at      TIMESTAMPTZ NOT NULL DEFAULT now(),
  actor   TEXT NOT NULL,
  summary TEXT NOT NULL,
  detail  JSONB
);
