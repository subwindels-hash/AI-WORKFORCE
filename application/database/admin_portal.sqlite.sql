-- WINDELS AI WORKFORCE — Admin portal persistence (SQLite dev mirror).

CREATE TABLE IF NOT EXISTS admin_activity_logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  admin_id INTEGER NOT NULL,
  admin_label TEXT NOT NULL,
  action TEXT NOT NULL,
  target_type TEXT,
  target_id TEXT,
  target_label TEXT,
  result TEXT NOT NULL DEFAULT 'ok',
  ip TEXT,
  detail TEXT,
  created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_admin_logs_created ON admin_activity_logs (created_at);
CREATE INDEX IF NOT EXISTS idx_admin_logs_admin ON admin_activity_logs (admin_id, created_at);
CREATE INDEX IF NOT EXISTS idx_admin_logs_action ON admin_activity_logs (action);

CREATE TABLE IF NOT EXISTS impersonation_sessions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  admin_id INTEGER NOT NULL,
  target_user_id INTEGER NOT NULL,
  started_at TEXT NOT NULL,
  ended_at TEXT,
  ip TEXT
);
CREATE INDEX IF NOT EXISTS idx_impersonation_admin ON impersonation_sessions (admin_id, started_at);
CREATE INDEX IF NOT EXISTS idx_impersonation_target ON impersonation_sessions (target_user_id, started_at);

CREATE TABLE IF NOT EXISTS platform_settings (
  k TEXT NOT NULL PRIMARY KEY,
  v TEXT NOT NULL,
  category TEXT NOT NULL DEFAULT 'general',
  updated_at TEXT NOT NULL,
  updated_by INTEGER
);

CREATE TABLE IF NOT EXISTS api_providers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  service TEXT NOT NULL,
  driver TEXT NOT NULL,
  label TEXT NOT NULL,
  enabled INTEGER NOT NULL DEFAULT 0,
  role TEXT NOT NULL DEFAULT 'unused',
  environment TEXT NOT NULL DEFAULT 'live',
  base_url TEXT,
  account_id TEXT,
  extra_json TEXT,
  secret_blob TEXT,
  last_test_at TEXT,
  last_test_ok INTEGER,
  last_test_ms INTEGER,
  last_test_message TEXT,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  updated_by INTEGER
);
CREATE INDEX IF NOT EXISTS idx_api_providers_service ON api_providers(service, enabled, role);
