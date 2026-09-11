-- PostgreSQL schema — generated from sports_intelligence.mysql.sql by tools/generate_pgsql_schema.mjs.
-- Column sets are identical to the canonical MySQL schema; types are the closest
-- PostgreSQL equivalents (TINYINT→SMALLINT, DATETIME→TIMESTAMP, LONGTEXT→TEXT, JSON→JSONB).

CREATE TABLE IF NOT EXISTS "sports_configurations" (
  "id" SERIAL PRIMARY KEY,
  "version" INTEGER NOT NULL UNIQUE,
  "module_enabled" SMALLINT NOT NULL DEFAULT 0,
  "ticket_engine_enabled" SMALLINT NOT NULL DEFAULT 0,
  "platform_mode" VARCHAR(16) NOT NULL DEFAULT 'SANDBOX',
  "engine_mode" VARCHAR(32) NOT NULL DEFAULT 'USER_APPROVAL_REQUIRED',
  "target_odds_min" DECIMAL(10,4) NOT NULL DEFAULT 5.0,
  "target_odds_max" DECIMAL(10,4) NOT NULL DEFAULT 8.0,
  "max_selections" INTEGER NOT NULL DEFAULT 5,
  "risk_level" VARCHAR(16) NOT NULL DEFAULT 'CONSERVATIVE',
  "min_confidence" DECIMAL(5,2) NOT NULL DEFAULT 75,
  "min_expected_value" DECIMAL(8,5) NOT NULL DEFAULT 0.02,
  "max_correlation" VARCHAR(8) NOT NULL DEFAULT 'LOW',
  "min_data_quality" SMALLINT NOT NULL DEFAULT 80,
  "min_liquidity" DECIMAL(10,4) NULL,
  "allowed_markets" TEXT NOT NULL,
  "allowed_leagues" TEXT NOT NULL,
  "max_exposure" DECIMAL(12,2) NOT NULL DEFAULT 100,
  "stake_amount" DECIMAL(12,2) NOT NULL DEFAULT 10,
  "void_policy" VARCHAR(16) NOT NULL DEFAULT 'RESTITUTE_ODDS',
  "require_calibration" SMALLINT NOT NULL DEFAULT 1,
  "updated_by" VARCHAR(64) NOT NULL DEFAULT 'system',
  "reason" VARCHAR(500) NULL,
  "created_at" TIMESTAMP NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_sports_config_created" ON "sports_configurations" ("created_at");
CREATE TABLE IF NOT EXISTS "sports_calibrations" (
  "id" SERIAL PRIMARY KEY,
  "model_version_id" INTEGER NOT NULL,
  "method" VARCHAR(32) NOT NULL DEFAULT 'platt',
  "intercept" DECIMAL(8,6) NOT NULL,
  "slope" DECIMAL(8,6) NOT NULL,
  "brier" DECIMAL(8,6) NULL,
  "ece" DECIMAL(8,6) NULL,
  "samples" INTEGER NOT NULL DEFAULT 0,
  "bins" TEXT NULL,
  "status" VARCHAR(16) NOT NULL DEFAULT 'PENDING',
  "created_by" VARCHAR(64) NULL,
  "approved_by" VARCHAR(64) NULL,
  "approved_at" TIMESTAMP NULL,
  "created_at" TIMESTAMP NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_sports_calibrations_model" ON "sports_calibrations" ("model_version_id", "status", "created_at");
CREATE TABLE IF NOT EXISTS "sports_job_runs" (
  "id" VARCHAR(40) PRIMARY KEY,
  "job_type" VARCHAR(48) NOT NULL,
  "status" VARCHAR(16) NOT NULL,
  "started_at" TIMESTAMP NOT NULL,
  "ended_at" TIMESTAMP NULL,
  "records_processed" INTEGER NOT NULL DEFAULT 0,
  "records_created" INTEGER NOT NULL DEFAULT 0,
  "records_updated" INTEGER NOT NULL DEFAULT 0,
  "errors" TEXT NULL,
  "provider" VARCHAR(64) NULL,
  "execution_key" VARCHAR(160) NOT NULL UNIQUE
);
CREATE INDEX IF NOT EXISTS "idx_sports_job_runs_type" ON "sports_job_runs" ("job_type", "started_at");
CREATE TABLE IF NOT EXISTS "sports_backtests" (
  "id" VARCHAR(40) PRIMARY KEY,
  "created_at" TIMESTAMP NOT NULL,
  "created_by" VARCHAR(64) NULL,
  "params" TEXT NOT NULL,
  "report" TEXT NOT NULL,
  "status" VARCHAR(16) NOT NULL DEFAULT 'COMPLETED'
);
CREATE INDEX IF NOT EXISTS "idx_sports_backtests_created" ON "sports_backtests" ("created_at");
CREATE TABLE IF NOT EXISTS "sports_model_metrics" (
  "id" SERIAL PRIMARY KEY,
  "model_version_id" INTEGER NOT NULL,
  "window_days" INTEGER NOT NULL,
  "sample_type" VARCHAR(16) NOT NULL DEFAULT 'live',
  "predictions" INTEGER NOT NULL DEFAULT 0,
  "settled" INTEGER NOT NULL DEFAULT 0,
  "accuracy" DECIMAL(8,5) NULL,
  "brier" DECIMAL(8,5) NULL,
  "ece" DECIMAL(8,5) NULL,
  "win_rate" DECIMAL(8,5) NULL,
  "roi" DECIMAL(8,5) NULL,
  "max_drawdown" DECIMAL(12,4) NULL,
  "computed_at" TIMESTAMP NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_sports_model_metrics" ON "sports_model_metrics" ("model_version_id", "window_days", "computed_at");
CREATE TABLE IF NOT EXISTS "sports_daily_tickets" (
  "id" SERIAL PRIMARY KEY,
  "date" DATE NOT NULL UNIQUE,
  "ticket_id" VARCHAR(36) NULL,
  "status" VARCHAR(32) NOT NULL,
  "configuration_version" INTEGER NULL,
  "candidates_evaluated" INTEGER NOT NULL DEFAULT 0,
  "predictions_recorded" INTEGER NOT NULL DEFAULT 0,
  "rejections" INTEGER NOT NULL DEFAULT 0,
  "rejection_summary" TEXT NULL,
  "message" VARCHAR(500) NULL,
  "provider" VARCHAR(64) NULL,
  "run_id" VARCHAR(40) NULL,
  "created_at" TIMESTAMP NOT NULL,
  "updated_at" TIMESTAMP NOT NULL
);
CREATE TABLE IF NOT EXISTS "sports_performance_snapshots" (
  "id" SERIAL PRIMARY KEY,
  "as_of" TIMESTAMP NOT NULL,
  "window" VARCHAR(8) NOT NULL,
  "payload" TEXT NOT NULL,
  CONSTRAINT "uq_sports_perf_snapshot" UNIQUE ("as_of", "window")
);
