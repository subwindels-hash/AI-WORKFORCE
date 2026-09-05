-- PostgreSQL schema — generated from multiplier_intelligence.mysql.sql by tools/generate_pgsql_schema.mjs.
-- Column sets are identical to the canonical MySQL schema; types are the closest
-- PostgreSQL equivalents (TINYINT→SMALLINT, DATETIME→TIMESTAMP, LONGTEXT→TEXT, JSON→JSONB).

CREATE TABLE IF NOT EXISTS "crash_game_providers" (
  "id" SERIAL PRIMARY KEY,
  "code" VARCHAR(64) UNIQUE NOT NULL,
  "name" VARCHAR(128) NOT NULL,
  "enabled" SMALLINT NOT NULL DEFAULT 0,
  "config_json" TEXT,
  "created_at" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  "updated_at" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS "idx_enabled" ON "crash_game_providers" ("enabled");
CREATE TABLE IF NOT EXISTS "crash_game_provider_health" (
  "id" SERIAL PRIMARY KEY,
  "provider_id" INTEGER NOT NULL,
  "status" VARCHAR(32) NOT NULL,
  "latency_ms" INTEGER,
  "detail" TEXT,
  "checked_at" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (provider_id) REFERENCES crash_game_providers(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS "idx_provider" ON "crash_game_provider_health" ("provider_id");
CREATE INDEX IF NOT EXISTS "idx_checked" ON "crash_game_provider_health" ("checked_at");
CREATE TABLE IF NOT EXISTS "crash_game_rounds" (
  "id" SERIAL PRIMARY KEY,
  "provider_id" INTEGER NOT NULL,
  "round_id" VARCHAR(128) NOT NULL,
  "game_code" VARCHAR(64) NOT NULL DEFAULT 'aviator',
  "multiplier" DECIMAL(12,4) NOT NULL,
  "started_at" TIMESTAMP NOT NULL,
  "crashed_at" TIMESTAMP,
  "duration_ms" INTEGER,
  "verified" SMALLINT NOT NULL DEFAULT 0,
  "raw_data_json" TEXT,
  "created_at" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (provider_id) REFERENCES crash_game_providers(id) ON DELETE CASCADE,
  CONSTRAINT "uk_provider_round" UNIQUE ("provider_id", "round_id")
);
CREATE INDEX IF NOT EXISTS "idx_game" ON "crash_game_rounds" ("game_code");
CREATE INDEX IF NOT EXISTS "idx_started" ON "crash_game_rounds" ("started_at");
CREATE INDEX IF NOT EXISTS "idx_multiplier" ON "crash_game_rounds" ("multiplier");
CREATE INDEX IF NOT EXISTS "idx_verified" ON "crash_game_rounds" ("verified");
CREATE TABLE IF NOT EXISTS "crash_game_models" (
  "id" SERIAL PRIMARY KEY,
  "code" VARCHAR(64) UNIQUE NOT NULL,
  "name" VARCHAR(128) NOT NULL,
  "version" VARCHAR(32) NOT NULL DEFAULT '1.0',
  "description" TEXT,
  "config_json" TEXT,
  "enabled" SMALLINT NOT NULL DEFAULT 1,
  "created_at" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  "updated_at" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS "idx_enabled" ON "crash_game_models" ("enabled");
CREATE TABLE IF NOT EXISTS "crash_game_predictions" (
  "id" SERIAL PRIMARY KEY,
  "model_id" INTEGER NOT NULL,
  "provider_id" INTEGER NOT NULL,
  "round_id" VARCHAR(128),
  "predicted_multiplier" DECIMAL(12,4) NOT NULL,
  "predicted_min" DECIMAL(12,4),
  "predicted_max" DECIMAL(12,4),
  "confidence" DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  "risk_level" VARCHAR(8) NOT NULL DEFAULT 'MEDIUM',
  "signal_type" VARCHAR(32) NOT NULL DEFAULT 'MULTIPLIER',
  "agents_json" TEXT,
  "features_json" TEXT,
  "predicted_at" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  "actual_multiplier" DECIMAL(12,4),
  "actual_at" TIMESTAMP,
  "error_value" DECIMAL(12,4),
  "error_pct" DECIMAL(8,2),
  "validated" SMALLINT NOT NULL DEFAULT 0,
  "validated_at" TIMESTAMP,
  FOREIGN KEY (model_id) REFERENCES crash_game_models(id),
  FOREIGN KEY (provider_id) REFERENCES crash_game_providers(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS "idx_model" ON "crash_game_predictions" ("model_id");
CREATE INDEX IF NOT EXISTS "idx_provider" ON "crash_game_predictions" ("provider_id");
CREATE INDEX IF NOT EXISTS "idx_predicted" ON "crash_game_predictions" ("predicted_at");
CREATE INDEX IF NOT EXISTS "idx_validated" ON "crash_game_predictions" ("validated");
CREATE INDEX IF NOT EXISTS "idx_confidence" ON "crash_game_predictions" ("confidence");
CREATE TABLE IF NOT EXISTS "crash_game_agent_executions" (
  "id" SERIAL PRIMARY KEY,
  "prediction_id" INTEGER,
  "agent_type" VARCHAR(64) NOT NULL,
  "agent_name" VARCHAR(128) NOT NULL,
  "input_json" TEXT,
  "output_json" TEXT,
  "confidence" DECIMAL(5,2),
  "latency_ms" INTEGER,
  "status" VARCHAR(32) NOT NULL DEFAULT 'COMPLETED',
  "executed_at" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (prediction_id) REFERENCES crash_game_predictions(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS "idx_prediction" ON "crash_game_agent_executions" ("prediction_id");
CREATE INDEX IF NOT EXISTS "idx_agent" ON "crash_game_agent_executions" ("agent_type");
CREATE INDEX IF NOT EXISTS "idx_executed" ON "crash_game_agent_executions" ("executed_at");
CREATE TABLE IF NOT EXISTS "crash_game_accuracy_snapshots" (
  "id" SERIAL PRIMARY KEY,
  "model_id" INTEGER NOT NULL,
  "window_size" INTEGER NOT NULL,
  "window_type" VARCHAR(10) NOT NULL DEFAULT 'LAST_N',
  "total_predictions" INTEGER NOT NULL DEFAULT 0,
  "validated_predictions" INTEGER NOT NULL DEFAULT 0,
  "accuracy_pct" DECIMAL(5,2),
  "avg_error" DECIMAL(12,4),
  "avg_confidence" DECIMAL(5,2),
  "best_confidence" DECIMAL(5,2),
  "worst_error" DECIMAL(12,4),
  "snapshot_at" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (model_id) REFERENCES crash_game_models(id)
);
CREATE INDEX IF NOT EXISTS "idx_model" ON "crash_game_accuracy_snapshots" ("model_id");
CREATE INDEX IF NOT EXISTS "idx_snapshot" ON "crash_game_accuracy_snapshots" ("snapshot_at");
CREATE TABLE IF NOT EXISTS "crash_game_active_signals" (
  "id" SERIAL PRIMARY KEY,
  "prediction_id" INTEGER NOT NULL,
  "provider_id" INTEGER NOT NULL,
  "status" VARCHAR(9) NOT NULL DEFAULT 'ANALYZING',
  "signal_json" TEXT,
  "created_at" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  "expires_at" TIMESTAMP,
  "updated_at" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (prediction_id) REFERENCES crash_game_predictions(id),
  FOREIGN KEY (provider_id) REFERENCES crash_game_providers(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS "idx_status" ON "crash_game_active_signals" ("status");
CREATE INDEX IF NOT EXISTS "idx_expires" ON "crash_game_active_signals" ("expires_at");
INSERT INTO crash_game_models (code, name, version, description) VALUES
('STATISTICAL-BASELINE-v1', 'Statistical Baseline', '1.0', 'Pure statistical analysis using historical distributions'),
('PATTERN-ENSEMBLE-v1', 'Pattern Ensemble', '1.0', 'Ensemble of pattern detection agents'),
('SEQUENCE-LSTM-v1', 'Sequence LSTM', '1.0', 'Sequence analysis with LSTM-style patterns'),
('MIXED-ENSEMBLE-v1', 'Mixed Ensemble', '1.0', 'Combined output from all specialist agents'),
('ANOMALY-AWARE-v1', 'Anomaly Aware', '1.0', 'Model that adjusts for detected anomalies')
INSERT INTO crash_game_providers (code, name, enabled) VALUES
('bustabit', 'Bustabit (Live)', 1),
('simulation', 'Simulation (Demo Data)', 0),
('aviator_demo', 'Aviator Demo Adapter', 0)
