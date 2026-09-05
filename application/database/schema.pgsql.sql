-- PostgreSQL schema — generated from schema.mysql.sql by tools/generate_pgsql_schema.mjs.
-- Column sets are identical to the canonical MySQL schema; types are the closest
-- PostgreSQL equivalents (TINYINT→SMALLINT, DATETIME→TIMESTAMP, LONGTEXT→TEXT, JSON→JSONB).

CREATE TABLE IF NOT EXISTS "platform_state" (
  "k" VARCHAR(32) NOT NULL PRIMARY KEY,
  "v" TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS "strategies" (
  "strategy_id" VARCHAR(60) NOT NULL,
  "version" VARCHAR(20) NOT NULL,
  "name" VARCHAR(120) NOT NULL,
  "description" TEXT NOT NULL,
  "market_classes" TEXT NOT NULL,
  "timeframes" TEXT NOT NULL,
  "params" TEXT NOT NULL,
  "source" VARCHAR(10) NOT NULL DEFAULT 'builtin',
  "lifecycle" VARCHAR(20) NOT NULL DEFAULT 'DRAFT',
  "created_at" VARCHAR(32) NOT NULL,
  "updated_at" VARCHAR(32) NOT NULL,
  "lifecycle_history" TEXT NOT NULL,
  PRIMARY KEY ("(strategy_id", "version)")
);
CREATE TABLE IF NOT EXISTS "backtests" (
  "id" VARCHAR(36) NOT NULL PRIMARY KEY,
  "created_at" VARCHAR(32) NOT NULL,
  "strategy_id" VARCHAR(60) NOT NULL,
  "strategy_version" VARCHAR(20) NOT NULL,
  "symbol" VARCHAR(20) NOT NULL,
  "timeframe" VARCHAR(5) NOT NULL,
  "synthetic" SMALLINT NOT NULL DEFAULT 0,
  "payload" TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_backtests_strategy" ON "backtests" ("strategy_id", "created_at");
CREATE TABLE IF NOT EXISTS "analysis_runs" (
  "id" VARCHAR(36) NOT NULL PRIMARY KEY,
  "symbol" VARCHAR(20) NOT NULL,
  "timeframe" VARCHAR(5) NOT NULL,
  "bias" VARCHAR(10) NOT NULL,
  "confidence" DECIMAL(5,4) NOT NULL,
  "regime" VARCHAR(20) NOT NULL,
  "recommendation" VARCHAR(10) NOT NULL,
  "synthetic" SMALLINT NOT NULL DEFAULT 0,
  "source" VARCHAR(40) NOT NULL,
  "completed_at" VARCHAR(32) NOT NULL,
  "payload" TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_analysis_completed" ON "analysis_runs" ("completed_at");
CREATE TABLE IF NOT EXISTS "journal_entries" (
  "id" VARCHAR(36) NOT NULL PRIMARY KEY,
  "source" VARCHAR(10) NOT NULL,
  "symbol" VARCHAR(20) NOT NULL,
  "market" VARCHAR(12) NOT NULL,
  "strategy" VARCHAR(60) NULL,
  "strategy_version" VARCHAR(20) NULL,
  "direction" VARCHAR(5) NOT NULL,
  "entry_time" VARCHAR(32) NOT NULL,
  "entry_price" DECIMAL(20,8) NOT NULL,
  "exit_time" VARCHAR(32) NULL,
  "exit_price" DECIMAL(20,8) NULL,
  "position_size" DECIMAL(20,8) NOT NULL,
  "stop_loss" DECIMAL(20,8) NULL,
  "take_profit" DECIMAL(20,8) NULL,
  "fees" DECIMAL(18,6) NOT NULL DEFAULT 0,
  "slippage" DECIMAL(18,6) NOT NULL DEFAULT 0,
  "pnl" DECIMAL(18,6) NULL,
  "pnl_pct" DECIMAL(12,6) NULL,
  "r_multiple" DECIMAL(12,6) NULL,
  "reason" TEXT NULL,
  "ai_confidence" DECIMAL(5,4) NULL,
  "confidence_source" VARCHAR(16) NULL,
  "agent_consensus" VARCHAR(120) NULL,
  "risk_score" DECIMAL(8,6) NULL,
  "execution_time" VARCHAR(32) NOT NULL,
  "backtest_id" VARCHAR(36) NULL,
  "paper_position_id" INTEGER NULL
);
CREATE INDEX IF NOT EXISTS "idx_journal_symbol" ON "journal_entries" ("symbol", "execution_time");
CREATE INDEX IF NOT EXISTS "idx_journal_strategy" ON "journal_entries" ("strategy", "execution_time");
CREATE INDEX IF NOT EXISTS "idx_journal_confidence" ON "journal_entries" ("ai_confidence");
CREATE TABLE IF NOT EXISTS "paper_accounts" (
  "id" SERIAL PRIMARY KEY,
  "name" VARCHAR(60) NOT NULL,
  "currency" VARCHAR(3) NOT NULL DEFAULT 'USD',
  "starting_balance" DECIMAL(18,2) NOT NULL,
  "balance" DECIMAL(18,2) NOT NULL,
  "peak_equity" DECIMAL(18,2) NOT NULL,
  "created_at" VARCHAR(32) NOT NULL
);
CREATE TABLE IF NOT EXISTS "paper_orders" (
  "id" SERIAL PRIMARY KEY,
  "account_id" INTEGER NOT NULL,
  "symbol" VARCHAR(20) NOT NULL,
  "market_class" VARCHAR(12) NOT NULL,
  "side" VARCHAR(4) NOT NULL,
  "type" VARCHAR(6) NOT NULL,
  "units" DECIMAL(20,8) NOT NULL,
  "price" DECIMAL(20,8) NULL,
  "stop_loss" DECIMAL(20,8) NULL,
  "take_profit" DECIMAL(20,8) NULL,
  "status" VARCHAR(10) NOT NULL,
  "reject_reason" TEXT NULL,
  "risk_amount" DECIMAL(18,6) NULL,
  "reason" TEXT NULL,
  "ai_confidence" DECIMAL(5,4) NULL,
  "strategy" VARCHAR(60) NULL,
  "created_at" VARCHAR(32) NOT NULL,
  "filled_at" VARCHAR(32) NULL,
  "fill_price" DECIMAL(20,8) NULL
);
CREATE INDEX IF NOT EXISTS "idx_orders_account" ON "paper_orders" ("account_id", "status");
CREATE TABLE IF NOT EXISTS "paper_positions" (
  "id" SERIAL PRIMARY KEY,
  "account_id" INTEGER NOT NULL,
  "symbol" VARCHAR(20) NOT NULL,
  "market_class" VARCHAR(12) NOT NULL,
  "direction" VARCHAR(5) NOT NULL,
  "units" DECIMAL(20,8) NOT NULL,
  "entry_price" DECIMAL(20,8) NOT NULL,
  "stop_loss" DECIMAL(20,8) NOT NULL,
  "take_profit" DECIMAL(20,8) NOT NULL,
  "entry_fee" DECIMAL(18,6) NOT NULL DEFAULT 0,
  "risk_amount" DECIMAL(18,6) NULL,
  "strategy" VARCHAR(60) NULL,
  "reason" TEXT NULL,
  "ai_confidence" DECIMAL(5,4) NULL,
  "opened_at" VARCHAR(32) NOT NULL,
  "status" VARCHAR(8) NOT NULL DEFAULT 'OPEN',
  "closed_at" VARCHAR(32) NULL,
  "exit_price" DECIMAL(20,8) NULL,
  "realized_pnl" DECIMAL(18,6) NULL,
  "exit_reason" VARCHAR(16) NULL
);
CREATE INDEX IF NOT EXISTS "idx_positions_account" ON "paper_positions" ("account_id", "status");
CREATE TABLE IF NOT EXISTS "paper_trades" (
  "id" SERIAL PRIMARY KEY,
  "account_id" INTEGER NOT NULL,
  "order_id" INTEGER NULL,
  "position_id" INTEGER NOT NULL,
  "leg" VARCHAR(5) NOT NULL,
  "symbol" VARCHAR(20) NOT NULL,
  "price" DECIMAL(20,8) NOT NULL,
  "units" DECIMAL(20,8) NOT NULL,
  "fee" DECIMAL(18,6) NOT NULL DEFAULT 0,
  "time" VARCHAR(32) NOT NULL,
  "synthetic" SMALLINT NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS "idx_paper_trades_account" ON "paper_trades" ("account_id");
CREATE TABLE IF NOT EXISTS "paper_deployments" (
  "id" SERIAL PRIMARY KEY,
  "account_id" INTEGER NOT NULL,
  "strategy_id" VARCHAR(60) NOT NULL,
  "strategy_version" VARCHAR(20) NOT NULL,
  "symbol" VARCHAR(20) NOT NULL,
  "market_class" VARCHAR(12) NOT NULL,
  "timeframe" VARCHAR(5) NOT NULL,
  "active" SMALLINT NOT NULL DEFAULT 1,
  "deployed_at" VARCHAR(32) NOT NULL,
  "last_evaluated_at" VARCHAR(32) NULL,
  "last_signal" VARCHAR(8) NULL
);
CREATE TABLE IF NOT EXISTS "audit_logs" (
  "id" SERIAL PRIMARY KEY,
  "type" VARCHAR(32) NOT NULL,
  "at" VARCHAR(32) NOT NULL,
  "actor" VARCHAR(8) NOT NULL DEFAULT 'system',
  "summary" VARCHAR(500) NOT NULL,
  "detail" TEXT NULL
);
CREATE TABLE IF NOT EXISTS "trade_proposals" (
  "id" VARCHAR(40) PRIMARY KEY,
  "created_at" VARCHAR(32) NOT NULL,
  "actor" VARCHAR(80) NOT NULL DEFAULT 'user',
  "broker" VARCHAR(40) NOT NULL,
  "symbol" VARCHAR(32) NOT NULL,
  "market_class" VARCHAR(20) NOT NULL,
  "side" VARCHAR(4) NOT NULL,
  "order_type" VARCHAR(10) NOT NULL,
  "volume" DECIMAL(18,6) NOT NULL,
  "price" DECIMAL(18,8) NULL,
  "stop_loss" DECIMAL(18,8) NOT NULL,
  "take_profit" DECIMAL(18,8) NULL,
  "strategy_id" VARCHAR(60) NULL,
  "reason" VARCHAR(500) NULL,
  "status" VARCHAR(24) NOT NULL,
  "intent" TEXT NOT NULL,
  "checks" TEXT NOT NULL,
  "risk_decision" TEXT NULL,
  "decision_by" VARCHAR(80) NULL,
  "decided_at" VARCHAR(32) NULL,
  "updated_at" VARCHAR(32) NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_proposals_status" ON "trade_proposals" ("status", "created_at");
CREATE TABLE IF NOT EXISTS "trade_executions" (
  "id" VARCHAR(40) PRIMARY KEY,
  "proposal_id" VARCHAR(40) NOT NULL,
  "broker" VARCHAR(40) NOT NULL,
  "broker_order_id" VARCHAR(64) NULL,
  "automated" SMALLINT NOT NULL DEFAULT 0,
  "submitted_at" VARCHAR(32) NOT NULL,
  "status" VARCHAR(24) NOT NULL,
  "result" TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_executions_proposal" ON "trade_executions" ("proposal_id");
CREATE TABLE IF NOT EXISTS "ci_sessions" (
  "id" VARCHAR(128) NOT NULL PRIMARY KEY,
  "ip_address" VARCHAR(45) NOT NULL,
  "timestamp" INTEGER NOT NULL DEFAULT 0,
  "data" TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS "notifications" (
  "id" VARCHAR(36) PRIMARY KEY,
  "user_id" INTEGER NULL,
  "type" VARCHAR(40) NOT NULL,
  "severity" VARCHAR(10) NOT NULL DEFAULT 'info',
  "title" VARCHAR(200) NOT NULL,
  "detail" TEXT NOT NULL,
  "dedupe_key" VARCHAR(120) NULL,
  "read_at" VARCHAR(32) NULL,
  "created_at" VARCHAR(32) NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_notifications_unread" ON "notifications" ("user_id", "read_at", "created_at");
CREATE TABLE IF NOT EXISTS "leads" (
  "id" VARCHAR(36) PRIMARY KEY,
  "organization_id" VARCHAR(80) NOT NULL,
  "source" VARCHAR(40) NOT NULL,
  "source_id" VARCHAR(255) NOT NULL,
  "name" VARCHAR(255) NOT NULL,
  "category" VARCHAR(255),
  "address" TEXT,
  "city" VARCHAR(120),
  "region" VARCHAR(120),
  "country" VARCHAR(120),
  "phone" VARCHAR(80),
  "website" TEXT,
  "email" VARCHAR(255),
  "job_title" VARCHAR(255),
  "company_name" VARCHAR(255),
  "linkedin_url" TEXT,
  "lead_kind" VARCHAR(20) NOT NULL DEFAULT 'business',
  "latitude" DECIMAL(10,7),
  "longitude" DECIMAL(10,7),
  "status" VARCHAR(20) NOT NULL DEFAULT 'new',
  "owner_id" INTEGER NULL,
  "metadata" TEXT NOT NULL,
  "created_at" VARCHAR(32) NOT NULL,
  "updated_at" VARCHAR(32) NOT NULL,
  CONSTRAINT "uq_lead_source" UNIQUE ("organization_id", "source", "source_id")
);
CREATE INDEX IF NOT EXISTS "idx_leads_org_status" ON "leads" ("organization_id", "status");
CREATE INDEX IF NOT EXISTS "idx_leads_owner" ON "leads" ("organization_id", "owner_id");
CREATE INDEX IF NOT EXISTS "idx_leads_created" ON "leads" ("organization_id", "created_at");
CREATE TABLE IF NOT EXISTS "lead_notes" (
  "id" VARCHAR(36) PRIMARY KEY,
  "lead_id" VARCHAR(36) NOT NULL,
  "organization_id" VARCHAR(80) NOT NULL,
  "author_id" INTEGER NOT NULL,
  "body" TEXT NOT NULL,
  "created_at" VARCHAR(32) NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_notes_lead" ON "lead_notes" ("organization_id", "lead_id");
CREATE TABLE IF NOT EXISTS "lead_activities" (
  "id" VARCHAR(36) PRIMARY KEY,
  "lead_id" VARCHAR(36) NULL,
  "organization_id" VARCHAR(80) NOT NULL,
  "actor_id" INTEGER NULL,
  "type" VARCHAR(50) NOT NULL,
  "detail" TEXT NOT NULL,
  "created_at" VARCHAR(32) NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_activity_lead" ON "lead_activities" ("organization_id", "lead_id", "created_at");
CREATE TABLE IF NOT EXISTS "collections" (
  "id" VARCHAR(36) PRIMARY KEY,
  "organization_id" VARCHAR(80) NOT NULL,
  "name" VARCHAR(150) NOT NULL,
  "created_at" VARCHAR(32) NOT NULL,
  "updated_at" VARCHAR(32) NOT NULL,
  CONSTRAINT "uq_collection" UNIQUE ("organization_id", "name")
);
CREATE TABLE IF NOT EXISTS "collection_leads" (
  "collection_id" VARCHAR(36) NOT NULL,
  "lead_id" VARCHAR(36) NOT NULL,
  PRIMARY KEY ("(collection_id", "lead_id)")
);
CREATE TABLE IF NOT EXISTS "search_history" (
  "id" VARCHAR(36) PRIMARY KEY,
  "organization_id" VARCHAR(80) NOT NULL,
  "user_id" INTEGER NOT NULL,
  "query" TEXT NOT NULL,
  "provider" VARCHAR(40) NOT NULL,
  "filters" TEXT NOT NULL,
  "results_returned" INTEGER NOT NULL,
  "new_leads_created" INTEGER NOT NULL,
  "duplicates_detected" INTEGER NOT NULL,
  "errors" TEXT NULL,
  "duration_ms" INTEGER NOT NULL,
  "created_at" VARCHAR(32) NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_history_org" ON "search_history" ("organization_id", "created_at");
CREATE TABLE IF NOT EXISTS "duplicate_candidates" (
  "id" VARCHAR(36) PRIMARY KEY,
  "organization_id" VARCHAR(80) NOT NULL,
  "lead_a_id" VARCHAR(36) NOT NULL,
  "lead_b_id" VARCHAR(36) NOT NULL,
  "rule_name" VARCHAR(80) NOT NULL,
  "confidence" DECIMAL(4,3) NOT NULL,
  "status" VARCHAR(20) NOT NULL DEFAULT 'open',
  "created_at" VARCHAR(32) NOT NULL
);
CREATE TABLE IF NOT EXISTS "duplicate_resolutions" (
  "id" VARCHAR(36) PRIMARY KEY,
  "candidate_id" VARCHAR(36) NOT NULL,
  "organization_id" VARCHAR(80) NOT NULL,
  "resolver_id" INTEGER NOT NULL,
  "action" VARCHAR(30) NOT NULL,
  "created_at" VARCHAR(32) NOT NULL
);
CREATE TABLE IF NOT EXISTS "export_history" (
  "id" VARCHAR(36) PRIMARY KEY,
  "organization_id" VARCHAR(80) NOT NULL,
  "user_id" INTEGER NOT NULL,
  "format" VARCHAR(10) NOT NULL,
  "filters" TEXT NOT NULL,
  "lead_count" INTEGER NOT NULL,
  "created_at" VARCHAR(32) NOT NULL
);
CREATE TABLE IF NOT EXISTS "lead_outreach" (
  "id" VARCHAR(36) PRIMARY KEY,
  "organization_id" VARCHAR(80) NOT NULL,
  "lead_id" VARCHAR(36) NOT NULL,
  "actor_id" INTEGER NULL,
  "channel" VARCHAR(20) NOT NULL,
  "subject" VARCHAR(200) NULL,
  "body" TEXT NOT NULL,
  "status" VARCHAR(20) NOT NULL,
  "detail" TEXT NOT NULL,
  "created_at" VARCHAR(32) NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_outreach_lead" ON "lead_outreach" ("organization_id", "lead_id", "created_at");
CREATE TABLE IF NOT EXISTS "lead_organizations" (
  "id" VARCHAR(80) PRIMARY KEY,
  "name" VARCHAR(160) NOT NULL,
  "created_at" VARCHAR(32) NOT NULL
);
CREATE TABLE IF NOT EXISTS "lead_organization_members" (
  "organization_id" VARCHAR(80) NOT NULL,
  "user_id" INTEGER NOT NULL,
  "role" VARCHAR(20) NOT NULL DEFAULT 'member',
  "created_at" VARCHAR(32) NOT NULL,
  PRIMARY KEY ("(organization_id", "user_id)")
);
CREATE INDEX IF NOT EXISTS "idx_lead_org_members_user" ON "lead_organization_members" ("user_id");
CREATE TABLE IF NOT EXISTS "user_broker_connections" (
  "id" SERIAL PRIMARY KEY,
  "user_id" INTEGER NOT NULL,
  "broker" VARCHAR(40) NOT NULL,
  "label" VARCHAR(120) NULL,
  "base_url" VARCHAR(255) NOT NULL,
  "extra_url" VARCHAR(255) NULL,
  "token_ciphertext" TEXT NULL,
  "token_nonce" VARCHAR(64) NULL,
  "account_hint" VARCHAR(120) NULL,
  "enabled" SMALLINT NOT NULL DEFAULT 0,
  "trading_enabled" SMALLINT NOT NULL DEFAULT 0,
  "live_allowed" SMALLINT NOT NULL DEFAULT 0,
  "last_test_ok" SMALLINT NULL,
  "last_test_message" VARCHAR(255) NULL,
  "last_test_at" VARCHAR(32) NULL,
  "created_at" VARCHAR(32) NOT NULL,
  "updated_at" VARCHAR(32) NOT NULL,
  CONSTRAINT "uq_user_broker" UNIQUE ("user_id", "broker")
);
CREATE INDEX IF NOT EXISTS "idx_user_enabled" ON "user_broker_connections" ("user_id", "enabled");
