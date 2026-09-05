-- PostgreSQL schema — generated from langlearn.mysql.sql by tools/generate_pgsql_schema.mjs.
-- Column sets are identical to the canonical MySQL schema; types are the closest
-- PostgreSQL equivalents (TINYINT→SMALLINT, DATETIME→TIMESTAMP, LONGTEXT→TEXT, JSON→JSONB).

CREATE TABLE IF NOT EXISTS "languages" (
  "code" VARCHAR(8) PRIMARY KEY,
  "name" VARCHAR(60) NOT NULL,
  "native_name" VARCHAR(120) NOT NULL,
  "iso_code" VARCHAR(8) NOT NULL,
  "writing_system" VARCHAR(40) NOT NULL,
  "direction" VARCHAR(3) NOT NULL DEFAULT 'ltr',
  "features" TEXT NOT NULL,
  "active" SMALLINT NOT NULL DEFAULT 1,
  "updated_at" VARCHAR(32) NOT NULL
);
CREATE TABLE IF NOT EXISTS "user_language_profiles" (
  "id" SERIAL PRIMARY KEY,
  "user_id" INTEGER NOT NULL,
  "language_code" VARCHAR(8) NOT NULL,
  "level" VARCHAR(10) NOT NULL DEFAULT 'Beginner',
  "goal" VARCHAR(300) NULL,
  "explanation_language" VARCHAR(8) NOT NULL DEFAULT 'en',
  "status" VARCHAR(16) NOT NULL DEFAULT 'ACTIVE',
  "daily_minutes" INTEGER NOT NULL DEFAULT 20,
  "created_at" VARCHAR(32) NOT NULL,
  "updated_at" VARCHAR(32) NOT NULL,
  CONSTRAINT "uq_profile_user_language" UNIQUE ("user_id", "language_code")
);
CREATE TABLE IF NOT EXISTS "user_language_preferences" (
  "id" SERIAL PRIMARY KEY,
  "user_id" INTEGER NOT NULL,
  "language_code" VARCHAR(8) NOT NULL,
  "native_language" VARCHAR(8) NOT NULL,
  "created_at" VARCHAR(32) NOT NULL,
  "updated_at" VARCHAR(32) NOT NULL,
  CONSTRAINT "uq_ulpref_user" UNIQUE ("user_id")
);
CREATE INDEX IF NOT EXISTS "idx_ulpref_lang" ON "user_language_preferences" ("language_code");
CREATE TABLE IF NOT EXISTS "language_assessments" (
  "id" VARCHAR(36) PRIMARY KEY,
  "profile_id" INTEGER NOT NULL,
  "user_id" INTEGER NOT NULL,
  "language_code" VARCHAR(8) NOT NULL,
  "status" VARCHAR(12) NOT NULL DEFAULT 'IN_PROGRESS',
  "state" TEXT NOT NULL,
  "result" TEXT NULL,
  "started_at" VARCHAR(32) NOT NULL,
  "completed_at" VARCHAR(32) NULL
);
CREATE INDEX IF NOT EXISTS "idx_assessments_profile" ON "language_assessments" ("profile_id", "started_at");
CREATE TABLE IF NOT EXISTS "learning_paths" (
  "id" VARCHAR(36) PRIMARY KEY,
  "profile_id" INTEGER NOT NULL,
  "language_code" VARCHAR(8) NOT NULL,
  "from_level" VARCHAR(10) NOT NULL,
  "target_level" VARCHAR(10) NOT NULL,
  "status" VARCHAR(12) NOT NULL DEFAULT 'ACTIVE',
  "created_at" VARCHAR(32) NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_paths_profile" ON "learning_paths" ("profile_id");
CREATE TABLE IF NOT EXISTS "learning_modules" (
  "id" VARCHAR(36) PRIMARY KEY,
  "path_id" VARCHAR(36) NOT NULL,
  "profile_id" INTEGER NOT NULL,
  "language_code" VARCHAR(8) NOT NULL,
  "sequence" INTEGER NOT NULL,
  "code" VARCHAR(60) NOT NULL,
  "title" VARCHAR(160) NOT NULL,
  "focus_skill" VARCHAR(12) NOT NULL,
  "level" VARCHAR(10) NOT NULL,
  "status" VARCHAR(12) NOT NULL DEFAULT 'LOCKED',
  "attempts_count" INTEGER NOT NULL DEFAULT 0,
  "completed_at" VARCHAR(32) NULL
);
CREATE INDEX IF NOT EXISTS "idx_modules_path" ON "learning_modules" ("path_id", "sequence");
CREATE INDEX IF NOT EXISTS "idx_modules_profile" ON "learning_modules" ("profile_id");
CREATE TABLE IF NOT EXISTS "lesson_attempts" (
  "id" VARCHAR(36) PRIMARY KEY,
  "profile_id" INTEGER NOT NULL,
  "user_id" INTEGER NOT NULL,
  "language_code" VARCHAR(8) NOT NULL,
  "module_id" VARCHAR(36) NULL,
  "kind" VARCHAR(16) NOT NULL,
  "score_pct" DECIMAL(5,2) NULL,
  "passed" SMALLINT NULL,
  "detail" TEXT NOT NULL,
  "created_at" VARCHAR(32) NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_attempts_profile" ON "lesson_attempts" ("profile_id", "created_at");
CREATE TABLE IF NOT EXISTS "study_sessions" (
  "id" VARCHAR(36) PRIMARY KEY,
  "profile_id" INTEGER NOT NULL,
  "user_id" INTEGER NOT NULL,
  "language_code" VARCHAR(8) NOT NULL,
  "activity" VARCHAR(24) NOT NULL,
  "day" VARCHAR(10) NOT NULL,
  "created_at" VARCHAR(32) NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_sessions_profile_day" ON "study_sessions" ("profile_id", "day");
CREATE TABLE IF NOT EXISTS "language_progress" (
  "id" SERIAL PRIMARY KEY,
  "profile_id" INTEGER NOT NULL,
  "user_id" INTEGER NOT NULL,
  "language_code" VARCHAR(8) NOT NULL,
  "skill" VARCHAR(12) NOT NULL,
  "level" VARCHAR(10) NULL,
  "value_pct" DECIMAL(5,2) NULL,
  "source" VARCHAR(24) NOT NULL,
  "updated_at" VARCHAR(32) NOT NULL,
  CONSTRAINT "uq_progress" UNIQUE ("profile_id", "skill", "source")
);
CREATE INDEX IF NOT EXISTS "idx_progress_user" ON "language_progress" ("user_id");
CREATE TABLE IF NOT EXISTS "conversation_sessions" (
  "id" VARCHAR(36) PRIMARY KEY,
  "profile_id" INTEGER NOT NULL,
  "user_id" INTEGER NOT NULL,
  "language_code" VARCHAR(8) NOT NULL,
  "scenario" VARCHAR(40) NOT NULL,
  "mode" VARCHAR(20) NOT NULL DEFAULT 'casual',
  "correction" VARCHAR(24) NOT NULL DEFAULT 'important',
  "status" VARCHAR(12) NOT NULL DEFAULT 'ACTIVE',
  "state" TEXT NOT NULL,
  "turn_count" INTEGER NOT NULL DEFAULT 0,
  "started_at" VARCHAR(32) NOT NULL,
  "completed_at" VARCHAR(32) NULL
);
CREATE INDEX IF NOT EXISTS "idx_conv_profile" ON "conversation_sessions" ("profile_id", "started_at");
CREATE TABLE IF NOT EXISTS "writing_attempts" (
  "id" VARCHAR(36) PRIMARY KEY,
  "profile_id" INTEGER NOT NULL,
  "user_id" INTEGER NOT NULL,
  "language_code" VARCHAR(8) NOT NULL,
  "task_code" VARCHAR(40) NOT NULL,
  "original_text" TEXT NOT NULL,
  "feedback" TEXT NOT NULL,
  "score_pct" DECIMAL(5,2) NULL,
  "created_at" VARCHAR(32) NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_writing_profile" ON "writing_attempts" ("profile_id", "created_at");
CREATE TABLE IF NOT EXISTS "vocabulary" (
  "id" SERIAL PRIMARY KEY,
  "language_code" VARCHAR(8) NOT NULL,
  "word" VARCHAR(120) NOT NULL,
  "translation" VARCHAR(160) NOT NULL,
  "pronunciation" VARCHAR(160) NULL,
  "example_sentence" VARCHAR(300) NULL,
  "category" VARCHAR(24) NOT NULL,
  "level" VARCHAR(4) NOT NULL,
  "active" SMALLINT NOT NULL DEFAULT 1,
  CONSTRAINT "uq_vocabulary_word" UNIQUE ("language_code", "word")
);
CREATE TABLE IF NOT EXISTS "user_vocabulary" (
  "id" SERIAL PRIMARY KEY,
  "profile_id" INTEGER NOT NULL,
  "user_id" INTEGER NOT NULL,
  "vocabulary_id" INTEGER NOT NULL,
  "stage" INTEGER NOT NULL DEFAULT 0,
  "familiarity" DECIMAL(4,3) NOT NULL DEFAULT 0.000,
  "next_review_at" VARCHAR(32) NOT NULL,
  "review_count" INTEGER NOT NULL DEFAULT 0,
  "lapse_count" INTEGER NOT NULL DEFAULT 0,
  "last_result" VARCHAR(8) NULL,
  "last_reviewed_at" VARCHAR(32) NULL,
  "added_at" VARCHAR(32) NOT NULL,
  CONSTRAINT "uq_user_vocabulary" UNIQUE ("profile_id", "vocabulary_id")
);
CREATE INDEX IF NOT EXISTS "idx_user_vocabulary_due" ON "user_vocabulary" ("profile_id", "next_review_at");
CREATE TABLE IF NOT EXISTS "listening_attempts" (
  "id" VARCHAR(36) PRIMARY KEY,
  "profile_id" INTEGER NOT NULL,
  "user_id" INTEGER NOT NULL,
  "language_code" VARCHAR(8) NOT NULL,
  "exercise_item_id" VARCHAR(20) NOT NULL,
  "mode" VARCHAR(14) NOT NULL,
  "score_pct" DECIMAL(5,2) NULL,
  "passed" SMALLINT NULL,
  "detail" TEXT NOT NULL,
  "created_at" VARCHAR(32) NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_listening_profile" ON "listening_attempts" ("profile_id", "created_at");
CREATE TABLE IF NOT EXISTS "speaking_attempts" (
  "id" VARCHAR(36) PRIMARY KEY,
  "profile_id" INTEGER NOT NULL,
  "user_id" INTEGER NOT NULL,
  "language_code" VARCHAR(8) NOT NULL,
  "prompt_text" VARCHAR(400) NOT NULL,
  "transcript" TEXT NULL,
  "word_accuracy_pct" DECIMAL(5,2) NULL,
  "exact_match" SMALLINT NOT NULL DEFAULT 0,
  "provider" VARCHAR(24) NOT NULL DEFAULT 'none',
  "detail" TEXT NOT NULL,
  "created_at" VARCHAR(32) NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_speaking_profile" ON "speaking_attempts" ("profile_id", "created_at");
CREATE TABLE IF NOT EXISTS "daily_learning_plans" (
  "id" VARCHAR(36) PRIMARY KEY,
  "profile_id" INTEGER NOT NULL,
  "user_id" INTEGER NOT NULL,
  "language_code" VARCHAR(8) NOT NULL,
  "day" VARCHAR(10) NOT NULL,
  "plan" TEXT NOT NULL,
  "est_minutes" INTEGER NOT NULL DEFAULT 0,
  "created_at" VARCHAR(32) NOT NULL,
  CONSTRAINT "uq_daily_plan" UNIQUE ("profile_id", "day")
);
CREATE TABLE IF NOT EXISTS "ai_learning_recommendations" (
  "id" VARCHAR(36) PRIMARY KEY,
  "profile_id" INTEGER NOT NULL,
  "user_id" INTEGER NOT NULL,
  "language_code" VARCHAR(8) NOT NULL,
  "kind" VARCHAR(24) NOT NULL,
  "message" VARCHAR(400) NOT NULL,
  "evidence" TEXT NOT NULL,
  "status" VARCHAR(12) NOT NULL DEFAULT 'ACTIVE',
  "created_at" VARCHAR(32) NOT NULL
);
CREATE INDEX IF NOT EXISTS "idx_reco_profile" ON "ai_learning_recommendations" ("profile_id", "created_at");
