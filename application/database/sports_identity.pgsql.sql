-- PostgreSQL schema — generated from sports_identity.mysql.sql by tools/generate_pgsql_schema.mjs.
-- Column sets are identical to the canonical MySQL schema; types are the closest
-- PostgreSQL equivalents (TINYINT→SMALLINT, DATETIME→TIMESTAMP, LONGTEXT→TEXT, JSON→JSONB).

CREATE TABLE IF NOT EXISTS "users" (
  "id" SERIAL PRIMARY KEY,
  "email" VARCHAR(190) NOT NULL UNIQUE,
  "password_hash" VARCHAR(255) NOT NULL,
  "display_name" VARCHAR(120) NOT NULL,
  "active" SMALLINT NOT NULL DEFAULT 1,
  "created_at" VARCHAR(32) NOT NULL,
  "updated_at" VARCHAR(32) NOT NULL,
  "last_login_at" VARCHAR(32) NULL,
  "username" VARCHAR(64) NULL,
  "user_uid" CHARACTER(6) NULL,
  "profile_image" VARCHAR(255) NULL,
  "phone" VARCHAR(40) NULL,
  "address" VARCHAR(255) NULL,
  "security_pin" CHARACTER(4) NULL,
  "security_question" VARCHAR(255) NULL,
  "security_answer" VARCHAR(255) NULL,
  CONSTRAINT "uq_users_username" UNIQUE ("username"),
  CONSTRAINT "uq_users_user_uid" UNIQUE ("user_uid")
);
CREATE INDEX IF NOT EXISTS "idx_users_active" ON "users" ("active");
CREATE TABLE IF NOT EXISTS "roles" (
  "id" SERIAL PRIMARY KEY,
  "code" VARCHAR(64) NOT NULL UNIQUE,
  "name" VARCHAR(120) NOT NULL
);
CREATE TABLE IF NOT EXISTS "permissions" (
  "id" SERIAL PRIMARY KEY,
  "code" VARCHAR(96) NOT NULL UNIQUE,
  "name" VARCHAR(160) NOT NULL
);
CREATE TABLE IF NOT EXISTS "user_roles" (
  "user_id" INTEGER NOT NULL,
  "role_id" INTEGER NOT NULL,
  PRIMARY KEY ("(user_id", "role_id)")
);
CREATE INDEX IF NOT EXISTS "idx_ur_role" ON "user_roles" ("role_id");
CREATE TABLE IF NOT EXISTS "role_permissions" (
  "role_id" INTEGER NOT NULL,
  "permission_id" INTEGER NOT NULL,
  PRIMARY KEY ("(role_id", "permission_id)")
);
CREATE INDEX IF NOT EXISTS "idx_rp_permission" ON "role_permissions" ("permission_id");
CREATE TABLE IF NOT EXISTS "auth_events" (
  "id" BIGSERIAL PRIMARY KEY,
  "user_id" INTEGER NOT NULL,
  "type" VARCHAR(64) NOT NULL,
  "detail" TEXT NULL,
  "at" VARCHAR(32) NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_auth_events_user" ON "auth_events" ("user_id", "at");
