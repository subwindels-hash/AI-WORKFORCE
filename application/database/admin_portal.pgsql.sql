-- PostgreSQL schema — generated from admin_portal.mysql.sql by tools/generate_pgsql_schema.mjs.
-- Column sets are identical to the canonical MySQL schema; types are the closest
-- PostgreSQL equivalents (TINYINT→SMALLINT, DATETIME→TIMESTAMP, LONGTEXT→TEXT, JSON→JSONB).

CREATE TABLE IF NOT EXISTS "admin_activity_logs" (
  "id" SERIAL PRIMARY KEY,
  "admin_id" INTEGER NOT NULL,
  "admin_label" VARCHAR(190) NOT NULL,
  "action" VARCHAR(64) NOT NULL,
  "target_type" VARCHAR(32) NULL,
  "target_id" VARCHAR(64) NULL,
  "target_label" VARCHAR(190) NULL,
  "result" VARCHAR(16) NOT NULL DEFAULT 'ok',
  "ip" VARCHAR(45) NULL,
  "detail" TEXT NULL,
  "created_at" VARCHAR(32) NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_admin_logs_created" ON "admin_activity_logs" ("created_at");
CREATE INDEX IF NOT EXISTS "idx_admin_logs_admin" ON "admin_activity_logs" ("admin_id", "created_at");
CREATE INDEX IF NOT EXISTS "idx_admin_logs_action" ON "admin_activity_logs" ("action");
CREATE TABLE IF NOT EXISTS "impersonation_sessions" (
  "id" SERIAL PRIMARY KEY,
  "admin_id" INTEGER NOT NULL,
  "target_user_id" INTEGER NOT NULL,
  "started_at" VARCHAR(32) NOT NULL,
  "ended_at" VARCHAR(32) NULL,
  "ip" VARCHAR(45) NULL
);
CREATE INDEX IF NOT EXISTS "idx_impersonation_admin" ON "impersonation_sessions" ("admin_id", "started_at");
CREATE INDEX IF NOT EXISTS "idx_impersonation_target" ON "impersonation_sessions" ("target_user_id", "started_at");
CREATE TABLE IF NOT EXISTS "platform_settings" (
  "k" VARCHAR(80) NOT NULL PRIMARY KEY,
  "v" TEXT NOT NULL,
  "category" VARCHAR(32) NOT NULL DEFAULT 'general',
  "updated_at" VARCHAR(32) NOT NULL,
  "updated_by" INTEGER NULL
);
CREATE TABLE IF NOT EXISTS "api_providers" (
  "id" SERIAL PRIMARY KEY,
  "service" VARCHAR(64) NOT NULL,
  "driver" VARCHAR(64) NOT NULL,
  "label" VARCHAR(190) NOT NULL,
  "enabled" SMALLINT NOT NULL DEFAULT 0,
  "role" VARCHAR(16) NOT NULL DEFAULT 'unused',
  "environment" VARCHAR(16) NOT NULL DEFAULT 'live',
  "base_url" VARCHAR(500) NULL,
  "account_id" VARCHAR(190) NULL,
  "extra_json" TEXT NULL,
  "secret_blob" TEXT NULL,
  "last_test_at" VARCHAR(32) NULL,
  "last_test_ok" SMALLINT NULL,
  "last_test_ms" INTEGER NULL,
  "last_test_message" VARCHAR(255) NULL,
  "created_at" VARCHAR(32) NOT NULL,
  "updated_at" VARCHAR(32) NOT NULL,
  "updated_by" INTEGER NULL
);
CREATE INDEX IF NOT EXISTS "idx_api_providers_service" ON "api_providers" ("service", "enabled", "role");
