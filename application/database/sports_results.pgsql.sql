-- PostgreSQL schema — generated from sports_results.mysql.sql by tools/generate_pgsql_schema.mjs.
-- Column sets are identical to the canonical MySQL schema; types are the closest
-- PostgreSQL equivalents (TINYINT→SMALLINT, DATETIME→TIMESTAMP, LONGTEXT→TEXT, JSON→JSONB).

CREATE TABLE IF NOT EXISTS "sports_results" (
  "id" BIGSERIAL PRIMARY KEY,
  "match_id" BIGINT NOT NULL,
  "provider_id" INTEGER NOT NULL,
  "home_score" INTEGER NULL,
  "away_score" INTEGER NULL,
  "status" VARCHAR(24) NOT NULL,
  "verified" SMALLINT NOT NULL DEFAULT 0,
  "source_timestamp" VARCHAR(32) NOT NULL,
  "verified_at" VARCHAR(32) NULL,
  "payload" TEXT NOT NULL,
  CONSTRAINT "uq_sports_result_provider_match" UNIQUE ("provider_id", "match_id")
);
CREATE INDEX IF NOT EXISTS "idx_sports_results_match" ON "sports_results" ("match_id", "verified");
