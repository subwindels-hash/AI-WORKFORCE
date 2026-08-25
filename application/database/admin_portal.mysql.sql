-- WINDELS AI WORKFORCE — Admin portal persistence (MySQL / MariaDB).
-- Idempotent. Credentials and password hashes are never stored here.

CREATE TABLE IF NOT EXISTS admin_activity_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  admin_id INT NOT NULL,
  admin_label VARCHAR(190) NOT NULL,
  action VARCHAR(64) NOT NULL,
  target_type VARCHAR(32) NULL,
  target_id VARCHAR(64) NULL,
  target_label VARCHAR(190) NULL,
  result VARCHAR(16) NOT NULL DEFAULT 'ok',
  ip VARCHAR(45) NULL,
  detail LONGTEXT NULL,
  created_at VARCHAR(32) NOT NULL,
  INDEX idx_admin_logs_created (created_at),
  INDEX idx_admin_logs_admin (admin_id, created_at),
  INDEX idx_admin_logs_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS impersonation_sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  admin_id INT NOT NULL,
  target_user_id INT NOT NULL,
  started_at VARCHAR(32) NOT NULL,
  ended_at VARCHAR(32) NULL,
  ip VARCHAR(45) NULL,
  INDEX idx_impersonation_admin (admin_id, started_at),
  INDEX idx_impersonation_target (target_user_id, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS platform_settings (
  k VARCHAR(80) NOT NULL PRIMARY KEY,
  v LONGTEXT NOT NULL,
  category VARCHAR(32) NOT NULL DEFAULT 'general',
  updated_at VARCHAR(32) NOT NULL,
  updated_by INT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS api_providers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  service VARCHAR(64) NOT NULL,
  driver VARCHAR(64) NOT NULL,
  label VARCHAR(190) NOT NULL,
  enabled TINYINT NOT NULL DEFAULT 0,
  role VARCHAR(16) NOT NULL DEFAULT 'unused',
  environment VARCHAR(16) NOT NULL DEFAULT 'live',
  base_url VARCHAR(500) NULL,
  account_id VARCHAR(190) NULL,
  extra_json LONGTEXT NULL,
  secret_blob LONGTEXT NULL,
  last_test_at VARCHAR(32) NULL,
  last_test_ok TINYINT NULL,
  last_test_ms INT NULL,
  last_test_message VARCHAR(255) NULL,
  created_at VARCHAR(32) NOT NULL,
  updated_at VARCHAR(32) NOT NULL,
  updated_by INT NULL,
  INDEX idx_api_providers_service (service, enabled, role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
