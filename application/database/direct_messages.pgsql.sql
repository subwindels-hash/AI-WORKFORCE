-- PostgreSQL schema — generated from direct_messages.mysql.sql by tools/generate_pgsql_schema.mjs.
-- Column sets are identical to the canonical MySQL schema; types are the closest
-- PostgreSQL equivalents (TINYINT→SMALLINT, DATETIME→TIMESTAMP, LONGTEXT→TEXT, JSON→JSONB).

CREATE TABLE IF NOT EXISTS "direct_messages" (
  "id" SERIAL PRIMARY KEY,
  "user_id" INTEGER NOT NULL,
  "sender_id" INTEGER NOT NULL,
  "sender_role" VARCHAR(10) NOT NULL DEFAULT 'user',
  "sender_label" VARCHAR(190) NOT NULL DEFAULT '',
  "body" TEXT NOT NULL,
  "read_by_user" SMALLINT NOT NULL DEFAULT 0,
  "read_by_admin" SMALLINT NOT NULL DEFAULT 0,
  "created_at" VARCHAR(32) NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_dm_thread" ON "direct_messages" ("user_id", "created_at");
CREATE INDEX IF NOT EXISTS "idx_dm_admin_unread" ON "direct_messages" ("sender_role", "read_by_admin");
CREATE INDEX IF NOT EXISTS "idx_dm_user_unread" ON "direct_messages" ("user_id", "sender_role", "read_by_user");
