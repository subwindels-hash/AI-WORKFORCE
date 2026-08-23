-- AEGIS — SQLite schema for the offline dev runtime (pdo_sqlite).
-- The canonical production schema is schema.mysql.sql; both are installed
-- by tools/install.php which picks by driver. Column sets are identical.

CREATE TABLE IF NOT EXISTS platform_state (
  k TEXT NOT NULL PRIMARY KEY,
  v TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS strategies (
  strategy_id TEXT NOT NULL,
  version TEXT NOT NULL,
  name TEXT NOT NULL,
  description TEXT NOT NULL,
  market_classes TEXT NOT NULL,
  timeframes TEXT NOT NULL,
  params TEXT NOT NULL,
  source TEXT NOT NULL DEFAULT 'builtin',
  lifecycle TEXT NOT NULL DEFAULT 'DRAFT',
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  lifecycle_history TEXT NOT NULL,
  PRIMARY KEY (strategy_id, version)
);
CREATE TABLE IF NOT EXISTS backtests (
  id TEXT PRIMARY KEY,
  created_at TEXT NOT NULL,
  strategy_id TEXT NOT NULL,
  strategy_version TEXT NOT NULL,
  symbol TEXT NOT NULL,
  timeframe TEXT NOT NULL,
  synthetic INTEGER NOT NULL DEFAULT 0,
  payload TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_backtests_strategy ON backtests (strategy_id, created_at);
CREATE TABLE IF NOT EXISTS analysis_runs (
  id TEXT PRIMARY KEY,
  symbol TEXT NOT NULL,
  timeframe TEXT NOT NULL,
  bias TEXT NOT NULL,
  confidence REAL NOT NULL,
  regime TEXT NOT NULL,
  recommendation TEXT NOT NULL,
  synthetic INTEGER NOT NULL DEFAULT 0,
  source TEXT NOT NULL,
  completed_at TEXT NOT NULL,
  payload TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_analysis_completed ON analysis_runs (completed_at);
CREATE TABLE IF NOT EXISTS journal_entries (
  id TEXT PRIMARY KEY,
  source TEXT NOT NULL,
  symbol TEXT NOT NULL,
  market TEXT NOT NULL,
  strategy TEXT,
  strategy_version TEXT,
  direction TEXT NOT NULL,
  entry_time TEXT NOT NULL,
  entry_price REAL NOT NULL,
  exit_time TEXT,
  exit_price REAL,
  position_size REAL NOT NULL,
  stop_loss REAL,
  take_profit REAL,
  fees REAL NOT NULL DEFAULT 0,
  slippage REAL NOT NULL DEFAULT 0,
  pnl REAL,
  pnl_pct REAL,
  r_multiple REAL,
  reason TEXT,
  ai_confidence REAL,
  confidence_source TEXT,
  agent_consensus TEXT,
  risk_score REAL,
  execution_time TEXT NOT NULL,
  backtest_id TEXT,
  paper_position_id INTEGER
);
CREATE INDEX IF NOT EXISTS idx_journal_symbol ON journal_entries (symbol, execution_time);
CREATE INDEX IF NOT EXISTS idx_journal_strategy ON journal_entries (strategy, execution_time);
CREATE TABLE IF NOT EXISTS paper_accounts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  currency TEXT NOT NULL DEFAULT 'USD',
  starting_balance REAL NOT NULL,
  balance REAL NOT NULL,
  peak_equity REAL NOT NULL,
  created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS paper_orders (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  account_id INTEGER NOT NULL,
  symbol TEXT NOT NULL,
  market_class TEXT NOT NULL,
  side TEXT NOT NULL,
  type TEXT NOT NULL,
  units REAL NOT NULL,
  price REAL,
  stop_loss REAL,
  take_profit REAL,
  status TEXT NOT NULL,
  reject_reason TEXT,
  risk_amount REAL,
  reason TEXT,
  ai_confidence REAL,
  strategy TEXT,
  created_at TEXT NOT NULL,
  filled_at TEXT,
  fill_price REAL
);
CREATE INDEX IF NOT EXISTS idx_orders_account ON paper_orders (account_id, status);
CREATE TABLE IF NOT EXISTS paper_positions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  account_id INTEGER NOT NULL,
  symbol TEXT NOT NULL,
  market_class TEXT NOT NULL,
  direction TEXT NOT NULL,
  units REAL NOT NULL,
  entry_price REAL NOT NULL,
  stop_loss REAL NOT NULL,
  take_profit REAL NOT NULL,
  entry_fee REAL NOT NULL DEFAULT 0,
  risk_amount REAL,
  strategy TEXT,
  reason TEXT,
  ai_confidence REAL,
  opened_at TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'OPEN',
  closed_at TEXT,
  exit_price REAL,
  realized_pnl REAL,
  exit_reason TEXT
);
CREATE INDEX IF NOT EXISTS idx_positions_account ON paper_positions (account_id, status);
CREATE TABLE IF NOT EXISTS paper_trades (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  account_id INTEGER NOT NULL,
  order_id INTEGER,
  position_id INTEGER NOT NULL,
  leg TEXT NOT NULL,
  symbol TEXT NOT NULL,
  price REAL NOT NULL,
  units REAL NOT NULL,
  fee REAL NOT NULL DEFAULT 0,
  time TEXT NOT NULL,
  synthetic INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS paper_deployments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  account_id INTEGER NOT NULL,
  strategy_id TEXT NOT NULL,
  strategy_version TEXT NOT NULL,
  symbol TEXT NOT NULL,
  market_class TEXT NOT NULL,
  timeframe TEXT NOT NULL,
  active INTEGER NOT NULL DEFAULT 1,
  deployed_at TEXT NOT NULL,
  last_evaluated_at TEXT,
  last_signal TEXT
);
CREATE TABLE IF NOT EXISTS audit_logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  type TEXT NOT NULL,
  at TEXT NOT NULL,
  actor TEXT NOT NULL DEFAULT 'system',
  summary TEXT NOT NULL,
  detail TEXT
);
