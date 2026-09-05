-- PostgreSQL schema — generated from admin_inbox.mysql.sql by tools/generate_pgsql_schema.mjs.
-- Column sets are identical to the canonical MySQL schema; types are the closest
-- PostgreSQL equivalents (TINYINT→SMALLINT, DATETIME→TIMESTAMP, LONGTEXT→TEXT, JSON→JSONB).

CREATE TABLE IF NOT EXISTS "contact_messages" (
  "id" SERIAL PRIMARY KEY,
  "uid" CHARACTER(12) NOT NULL,
  "sender_name" VARCHAR(120) NOT NULL,
  "sender_email" VARCHAR(190) NOT NULL,
  "sender_phone" VARCHAR(40) NULL,
  "sender_address" VARCHAR(255) NULL,
  "subject" VARCHAR(200) NOT NULL DEFAULT 'Contact form inquiry',
  "body" TEXT NOT NULL,
  "source" VARCHAR(40) NOT NULL DEFAULT 'contact_form',
  "ip" VARCHAR(45) NULL,
  "user_agent" VARCHAR(255) NULL,
  "user_id" INTEGER NULL,
  "status" VARCHAR(20) NOT NULL DEFAULT 'new',
  "is_starred" SMALLINT NOT NULL DEFAULT 0,
  "is_read" SMALLINT NOT NULL DEFAULT 0,
  "assigned_to" INTEGER NULL,
  "last_reply_at" VARCHAR(32) NULL,
  "last_reply_by" INTEGER NULL,
  "created_at" VARCHAR(32) NOT NULL,
  "updated_at" VARCHAR(32) NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_cm_created" ON "contact_messages" ("created_at");
CREATE INDEX IF NOT EXISTS "idx_cm_status" ON "contact_messages" ("status", "created_at");
CREATE INDEX IF NOT EXISTS "idx_cm_email" ON "contact_messages" ("sender_email");
CREATE INDEX IF NOT EXISTS "idx_cm_uid" ON "contact_messages" ("uid");
CREATE INDEX IF NOT EXISTS "idx_cm_assigned" ON "contact_messages" ("assigned_to", "status");
CREATE INDEX IF NOT EXISTS "idx_cm_unread" ON "contact_messages" ("is_read", "created_at");
CREATE TABLE IF NOT EXISTS "contact_message_replies" (
  "id" SERIAL PRIMARY KEY,
  "message_id" INTEGER NOT NULL,
  "template_id" INTEGER NULL,
  "author_id" INTEGER NULL,
  "author_label" VARCHAR(190) NOT NULL,
  "direction" VARCHAR(10) NOT NULL DEFAULT 'outbound',
  "to_email" VARCHAR(190) NULL,
  "subject" VARCHAR(200) NOT NULL,
  "body" TEXT NOT NULL,
  "body_text" TEXT NULL,
  "sent_at" VARCHAR(32) NOT NULL,
  "delivery_status" VARCHAR(20) NOT NULL DEFAULT 'sent',
  "delivery_message" VARCHAR(255) NULL,
  "ip" VARCHAR(45) NULL
);
CREATE INDEX IF NOT EXISTS "idx_cmr_message" ON "contact_message_replies" ("message_id", "sent_at");
CREATE TABLE IF NOT EXISTS "email_templates" (
  "id" SERIAL PRIMARY KEY,
  "code" VARCHAR(60) NOT NULL,
  "name" VARCHAR(120) NOT NULL,
  "category" VARCHAR(40) NOT NULL DEFAULT 'general',
  "description" VARCHAR(255) NULL,
  "subject" VARCHAR(200) NOT NULL,
  "body_html" TEXT NOT NULL,
  "body_text" TEXT NULL,
  "variables_json" TEXT NOT NULL DEFAULT '{}',
  "is_system" SMALLINT NOT NULL DEFAULT 0,
  "is_active" SMALLINT NOT NULL DEFAULT 1,
  "created_by" INTEGER NULL,
  "updated_by" INTEGER NULL,
  "created_at" VARCHAR(32) NOT NULL,
  "updated_at" VARCHAR(32) NOT NULL,
  CONSTRAINT "uq_et_code" UNIQUE ("code")
);
CREATE INDEX IF NOT EXISTS "idx_et_category" ON "email_templates" ("category", "is_active");
