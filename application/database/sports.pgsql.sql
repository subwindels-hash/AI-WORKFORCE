-- PostgreSQL schema — generated from sports.mysql.sql by tools/generate_pgsql_schema.mjs.
-- Column sets are identical to the canonical MySQL schema; types are the closest
-- PostgreSQL equivalents (TINYINT→SMALLINT, DATETIME→TIMESTAMP, LONGTEXT→TEXT, JSON→JSONB).

CREATE TABLE IF NOT EXISTS "sports_data_sources" (
  "id" SERIAL PRIMARY KEY,
  "provider_code" VARCHAR(64) NOT NULL UNIQUE,
  "display_name" VARCHAR(120) NOT NULL,
  "enabled" SMALLINT NOT NULL DEFAULT 0,
  "created_at" VARCHAR(32) NOT NULL,
  "updated_at" VARCHAR(32) NOT NULL
);
CREATE TABLE IF NOT EXISTS "sports_provider_health" (
  "id" BIGSERIAL PRIMARY KEY,
  "provider_id" INTEGER NOT NULL,
  "status" VARCHAR(32) NOT NULL,
  "response_ms" INTEGER NULL,
  "error_rate" DECIMAL(8,5) NULL,
  "rate_limit_remaining" INTEGER NULL,
  "last_success_at" VARCHAR(32) NULL,
  "last_failure_at" VARCHAR(32) NULL,
  "last_fixture_sync_at" VARCHAR(32) NULL,
  "last_odds_sync_at" VARCHAR(32) NULL,
  "last_result_sync_at" VARCHAR(32) NULL,
  "data_freshness_seconds" INTEGER NULL,
  "records_received" INTEGER NOT NULL DEFAULT 0,
  "invalid_records" INTEGER NOT NULL DEFAULT 0,
  "missing_fields" TEXT NULL,
  "observed_at" VARCHAR(32) NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_provider_health" ON "sports_provider_health" ("provider_id", "observed_at");
CREATE TABLE IF NOT EXISTS "sports_matches" (
  "id" BIGSERIAL PRIMARY KEY,
  "provider_id" INTEGER NOT NULL,
  "external_id" VARCHAR(128) NOT NULL,
  "sport" VARCHAR(32) NOT NULL,
  "competition" VARCHAR(160) NOT NULL,
  "home_team" VARCHAR(160) NOT NULL,
  "away_team" VARCHAR(160) NOT NULL,
  "kickoff_at" VARCHAR(32) NOT NULL,
  "status" VARCHAR(32) NOT NULL,
  "source_timestamp" VARCHAR(32) NOT NULL,
  "round_id" VARCHAR(64) NULL,
  "payload" TEXT NOT NULL,
  "created_at" VARCHAR(32) NOT NULL,
  "updated_at" VARCHAR(32) NOT NULL,
  CONSTRAINT "uq_sports_match_provider_external" UNIQUE ("provider_id", "external_id")
);
CREATE INDEX IF NOT EXISTS "idx_sports_matches_kickoff" ON "sports_matches" ("kickoff_at");
CREATE INDEX IF NOT EXISTS "idx_sports_matches_status" ON "sports_matches" ("status");
CREATE INDEX IF NOT EXISTS "idx_sports_matches_round" ON "sports_matches" ("provider_id", "round_id");
CREATE TABLE IF NOT EXISTS "sports_odds" (
  "id" BIGSERIAL PRIMARY KEY,
  "match_id" BIGINT NOT NULL,
  "provider_id" INTEGER NOT NULL,
  "market" VARCHAR(96) NOT NULL,
  "selection" VARCHAR(160) NOT NULL,
  "decimal_odds" DECIMAL(12,6) NOT NULL,
  "observed_at" VARCHAR(32) NOT NULL,
  "payload" TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_sports_odds_match_market" ON "sports_odds" ("match_id", "market", "observed_at");
CREATE INDEX IF NOT EXISTS "idx_sports_odds_provider" ON "sports_odds" ("provider_id", "observed_at");
CREATE TABLE IF NOT EXISTS "sports_data_quality_assessments" (
  "id" BIGSERIAL PRIMARY KEY,
  "match_id" BIGINT NOT NULL,
  "score" INTEGER NOT NULL,
  "band" VARCHAR(16) NOT NULL,
  "freshness_score" INTEGER NOT NULL,
  "provider_reliability_score" INTEGER NOT NULL,
  "eligible_prediction" SMALLINT NOT NULL,
  "eligible_ticket" SMALLINT NOT NULL,
  "missing_fields" TEXT NOT NULL,
  "checks_payload" TEXT NOT NULL,
  "assessed_at" VARCHAR(32) NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_sports_quality_match" ON "sports_data_quality_assessments" ("match_id", "assessed_at");
CREATE TABLE IF NOT EXISTS "sports_sync_runs" (
  "id" VARCHAR(36) PRIMARY KEY,
  "provider_id" INTEGER NULL,
  "job_type" VARCHAR(48) NOT NULL,
  "status" VARCHAR(24) NOT NULL,
  "started_at" VARCHAR(32) NOT NULL,
  "ended_at" VARCHAR(32) NULL,
  "records_processed" INTEGER NOT NULL DEFAULT 0,
  "records_created" INTEGER NOT NULL DEFAULT 0,
  "records_updated" INTEGER NOT NULL DEFAULT 0,
  "errors" TEXT NULL,
  "execution_key" VARCHAR(128) NOT NULL UNIQUE
);
CREATE INDEX IF NOT EXISTS "idx_sports_sync_runs_job" ON "sports_sync_runs" ("job_type", "started_at");
