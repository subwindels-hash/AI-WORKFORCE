-- PostgreSQL schema — generated from lottery.mysql.sql by tools/generate_pgsql_schema.mjs.
-- Column sets are identical to the canonical MySQL schema; types are the closest
-- PostgreSQL equivalents (TINYINT→SMALLINT, DATETIME→TIMESTAMP, LONGTEXT→TEXT, JSON→JSONB).

CREATE TABLE IF NOT EXISTS "lotteries" (
  "id" SERIAL PRIMARY KEY,
  "code" VARCHAR(32) NOT NULL UNIQUE,
  "name" VARCHAR(120) NOT NULL,
  "enabled" SMALLINT NOT NULL DEFAULT 1,
  "rules_version" VARCHAR(16) NOT NULL,
  "created_at" VARCHAR(32) NOT NULL,
  "updated_at" VARCHAR(32) NOT NULL
);
CREATE TABLE IF NOT EXISTS "lottery_rules" (
  "id" SERIAL PRIMARY KEY,
  "lottery_code" VARCHAR(32) NOT NULL,
  "version" VARCHAR(16) NOT NULL,
  "main_count" INTEGER NOT NULL,
  "main_min" INTEGER NOT NULL,
  "main_max" INTEGER NOT NULL,
  "star_count" INTEGER NOT NULL,
  "star_min" INTEGER NOT NULL,
  "star_max" INTEGER NOT NULL,
  "schedule" VARCHAR(255) NOT NULL,
  "active" SMALLINT NOT NULL DEFAULT 1,
  "created_at" VARCHAR(32) NOT NULL,
  CONSTRAINT "uq_lottery_rules" UNIQUE ("lottery_code", "version")
);
CREATE TABLE IF NOT EXISTS "lottery_data_sources" (
  "id" SERIAL PRIMARY KEY,
  "provider_code" VARCHAR(64) NOT NULL UNIQUE,
  "display_name" VARCHAR(120) NOT NULL,
  "enabled" SMALLINT NOT NULL DEFAULT 0,
  "synthetic" SMALLINT NOT NULL DEFAULT 0,
  "created_at" VARCHAR(32) NOT NULL,
  "updated_at" VARCHAR(32) NOT NULL
);
CREATE TABLE IF NOT EXISTS "lottery_provider_health" (
  "id" BIGSERIAL PRIMARY KEY,
  "provider_id" INTEGER NOT NULL,
  "status" VARCHAR(32) NOT NULL,
  "response_ms" INTEGER NULL,
  "records_received" INTEGER NOT NULL DEFAULT 0,
  "invalid_records" INTEGER NOT NULL DEFAULT 0,
  "error_rate" DECIMAL(8,5) NULL,
  "last_success_at" VARCHAR(32) NULL,
  "last_failure_at" VARCHAR(32) NULL,
  "last_draw_retrieved" VARCHAR(32) NULL,
  "data_freshness_seconds" INTEGER NULL,
  "synthetic" SMALLINT NOT NULL DEFAULT 0,
  "observed_at" VARCHAR(32) NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_lottery_provider_health" ON "lottery_provider_health" ("provider_id", "observed_at");
CREATE TABLE IF NOT EXISTS "lottery_draws" (
  "id" BIGSERIAL PRIMARY KEY,
  "lottery_code" VARCHAR(32) NOT NULL,
  "provider_id" INTEGER NULL,
  "external_id" VARCHAR(64) NOT NULL,
  "draw_date" DATE NOT NULL,
  "jackpot" VARCHAR(32) NULL,
  "rollover" SMALLINT NOT NULL DEFAULT 0,
  "source" VARCHAR(120) NOT NULL,
  "source_timestamp" VARCHAR(40) NOT NULL,
  "retrieved_at" VARCHAR(32) NOT NULL,
  "verification_status" VARCHAR(32) NOT NULL,
  "payload" TEXT NOT NULL,
  "created_at" VARCHAR(32) NOT NULL,
  "updated_at" VARCHAR(32) NOT NULL,
  CONSTRAINT "uq_lottery_draws" UNIQUE ("lottery_code", "external_id")
);
CREATE INDEX IF NOT EXISTS "idx_lottery_draws_date" ON "lottery_draws" ("lottery_code", "draw_date");
CREATE TABLE IF NOT EXISTS "lottery_draw_numbers" (
  "id" BIGSERIAL PRIMARY KEY,
  "draw_id" BIGINT NOT NULL,
  "kind" VARCHAR(8) NOT NULL,
  "position" INTEGER NOT NULL,
  "number" INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_lottery_draw_numbers_draw" ON "lottery_draw_numbers" ("draw_id", "kind", "position");
CREATE TABLE IF NOT EXISTS "lottery_sync_runs" (
  "id" VARCHAR(64) PRIMARY KEY,
  "provider_id" INTEGER NULL,
  "job_type" VARCHAR(40) NOT NULL,
  "status" VARCHAR(32) NOT NULL,
  "started_at" VARCHAR(32) NOT NULL,
  "ended_at" VARCHAR(32) NULL,
  "records_processed" INTEGER NOT NULL DEFAULT 0,
  "records_created" INTEGER NOT NULL DEFAULT 0,
  "records_updated" INTEGER NOT NULL DEFAULT 0,
  "errors" TEXT NULL,
  "payload" TEXT NULL,
  "execution_key" VARCHAR(128) NOT NULL UNIQUE
);
CREATE INDEX IF NOT EXISTS "idx_lottery_sync_runs_job" ON "lottery_sync_runs" ("job_type", "started_at");
CREATE TABLE IF NOT EXISTS "lottery_combinations" (
  "id" BIGSERIAL PRIMARY KEY,
  "lottery_code" VARCHAR(32) NOT NULL,
  "mode" VARCHAR(32) NOT NULL,
  "model_version" VARCHAR(64) NOT NULL,
  "seed" VARCHAR(32) NULL,
  "line_count" INTEGER NOT NULL DEFAULT 0,
  "lines" TEXT NOT NULL,
  "constraints" TEXT NOT NULL,
  "score_summary" TEXT NOT NULL,
  "created_by" INTEGER NULL,
  "created_at" VARCHAR(32) NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_lottery_combinations_code" ON "lottery_combinations" ("lottery_code", "created_at");
CREATE TABLE IF NOT EXISTS "lottery_ai_decisions" (
  "id" BIGSERIAL PRIMARY KEY,
  "lottery_code" VARCHAR(32) NOT NULL,
  "combination_id" BIGINT NULL,
  "model_version" VARCHAR(64) NOT NULL,
  "mode" VARCHAR(32) NULL,
  "decision" TEXT NOT NULL,
  "created_at" VARCHAR(32) NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_lottery_ai_decisions_comb" ON "lottery_ai_decisions" ("combination_id");
CREATE TABLE IF NOT EXISTS "lottery_tickets" (
  "id" BIGSERIAL PRIMARY KEY,
  "user_id" INTEGER NOT NULL,
  "lottery_code" VARCHAR(32) NOT NULL,
  "name" VARCHAR(120) NOT NULL,
  "draw_date" DATE NULL,
  "generation_method" VARCHAR(32) NOT NULL,
  "model_version" VARCHAR(64) NOT NULL,
  "configuration" TEXT NOT NULL,
  "status" VARCHAR(16) NOT NULL DEFAULT 'OPEN',
  "result" TEXT NULL,
  "created_at" VARCHAR(32) NOT NULL,
  "updated_at" VARCHAR(32) NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_lottery_tickets_user" ON "lottery_tickets" ("user_id", "status");
CREATE TABLE IF NOT EXISTS "lottery_ticket_lines" (
  "id" BIGSERIAL PRIMARY KEY,
  "ticket_id" BIGINT NOT NULL,
  "position" INTEGER NOT NULL,
  "mains" TEXT NOT NULL,
  "stars" TEXT NOT NULL,
  "created_at" VARCHAR(32) NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_lottery_ticket_lines_ticket" ON "lottery_ticket_lines" ("ticket_id", "position");
CREATE TABLE IF NOT EXISTS "lottery_backtests" (
  "id" BIGSERIAL PRIMARY KEY,
  "lottery_code" VARCHAR(32) NOT NULL,
  "strategy" VARCHAR(40) NOT NULL,
  "model_version" VARCHAR(64) NOT NULL,
  "lines_per_draw" INTEGER NOT NULL DEFAULT 1,
  "draws_tested" INTEGER NOT NULL DEFAULT 0,
  "period_from" DATE NULL,
  "period_to" DATE NULL,
  "dataset_version" VARCHAR(128) NULL,
  "report" TEXT NOT NULL,
  "created_at" VARCHAR(32) NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_lottery_backtests_strategy" ON "lottery_backtests" ("strategy", "created_at");
CREATE TABLE IF NOT EXISTS "lottery_model_versions" (
  "id" BIGSERIAL PRIMARY KEY,
  "model_name" VARCHAR(64) NOT NULL,
  "model_version" VARCHAR(16) NOT NULL,
  "config" TEXT NOT NULL,
  "dataset_version" VARCHAR(128) NULL,
  "status" VARCHAR(16) NOT NULL DEFAULT 'ACTIVE',
  "created_at" VARCHAR(32) NOT NULL,
  CONSTRAINT "uq_lottery_model_versions" UNIQUE ("model_name", "model_version")
);
