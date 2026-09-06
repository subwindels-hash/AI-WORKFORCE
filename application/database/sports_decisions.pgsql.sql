-- PostgreSQL schema — generated from sports_decisions.mysql.sql by tools/generate_pgsql_schema.mjs.
-- Column sets are identical to the canonical MySQL schema; types are the closest
-- PostgreSQL equivalents (TINYINT→SMALLINT, DATETIME→TIMESTAMP, LONGTEXT→TEXT, JSON→JSONB).

CREATE TABLE IF NOT EXISTS "sports_model_versions" (
  "id" SERIAL PRIMARY KEY,
  "model_name" VARCHAR(120) NOT NULL,
  "model_version" VARCHAR(64) NOT NULL,
  "feature_version" VARCHAR(64) NOT NULL,
  "calibration_version" VARCHAR(64) NULL,
  "status" VARCHAR(24) NOT NULL,
  "created_at" VARCHAR(32) NOT NULL,
  CONSTRAINT "uq_sports_model_version" UNIQUE ("model_name", "model_version")
);
CREATE TABLE IF NOT EXISTS "sports_predictions" (
  "id" VARCHAR(36) PRIMARY KEY,
  "match_id" BIGINT NOT NULL,
  "model_version_id" INTEGER NOT NULL,
  "market" VARCHAR(96) NOT NULL,
  "selection" VARCHAR(160) NOT NULL,
  "raw_probability" DECIMAL(10,8) NULL,
  "calibrated_probability" DECIMAL(10,8) NULL,
  "implied_probability" DECIMAL(10,8) NULL,
  "expected_value" DECIMAL(12,8) NULL,
  "confidence" DECIMAL(10,8) NULL,
  "risk" VARCHAR(16) NOT NULL,
  "correlation" VARCHAR(16) NOT NULL,
  "data_quality_score" INTEGER NOT NULL,
  "decision" VARCHAR(48) NOT NULL,
  "rejection_reasons" TEXT NULL,
  "factors" TEXT NOT NULL,
  "input_version" VARCHAR(64) NOT NULL,
  "odds" DECIMAL(14,6) NULL,
  "odds_timestamp" VARCHAR(32) NULL,
  "created_at" VARCHAR(32) NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_sports_predictions_match" ON "sports_predictions" ("match_id", "created_at");
CREATE INDEX IF NOT EXISTS "idx_sports_predictions_model" ON "sports_predictions" ("model_version_id", "created_at");
CREATE TABLE IF NOT EXISTS "sports_tickets" (
  "id" VARCHAR(36) PRIMARY KEY,
  "created_at" VARCHAR(32) NOT NULL,
  "model_version_id" INTEGER NULL,
  "configuration_version" VARCHAR(64) NOT NULL,
  "total_odds" DECIMAL(14,6) NULL,
  "selection_count" INTEGER NOT NULL,
  "combined_probability" DECIMAL(10,8) NULL,
  "confidence" DECIMAL(10,8) NULL,
  "average_confidence" DECIMAL(10,4) NULL,
  "risk" VARCHAR(16) NOT NULL,
  "correlation" VARCHAR(16) NOT NULL,
  "data_quality_score" INTEGER NULL,
  "average_data_quality" DECIMAL(10,4) NULL,
  "odds_calculation" TEXT NULL,
  "status" VARCHAR(32) NOT NULL,
  "approval_status" VARCHAR(32) NOT NULL,
  "settlement_status" VARCHAR(32) NOT NULL,
  "reason" TEXT NULL,
  "stake" DECIMAL(12,2) NULL,
  "pnl" DECIMAL(14,4) NULL
);
CREATE INDEX IF NOT EXISTS "idx_sports_tickets_status" ON "sports_tickets" ("status", "created_at");
CREATE TABLE IF NOT EXISTS "sports_ticket_selections" (
  "id" BIGSERIAL PRIMARY KEY,
  "ticket_id" VARCHAR(36) NOT NULL,
  "prediction_id" VARCHAR(36) NOT NULL,
  "match_id" BIGINT NOT NULL,
  "market" VARCHAR(96) NOT NULL,
  "selection" VARCHAR(160) NOT NULL,
  "odds" DECIMAL(14,6) NOT NULL,
  "odds_timestamp" VARCHAR(32) NOT NULL,
  "model_probability" DECIMAL(10,8) NULL,
  "calibrated_probability" DECIMAL(10,8) NULL,
  "expected_value" DECIMAL(12,8) NULL,
  "risk" VARCHAR(16) NOT NULL,
  "result" VARCHAR(24) NULL,
  "status" VARCHAR(32) NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_sports_ticket_selections_ticket" ON "sports_ticket_selections" ("ticket_id");
CREATE INDEX IF NOT EXISTS "idx_sports_ticket_selections_prediction" ON "sports_ticket_selections" ("prediction_id");
