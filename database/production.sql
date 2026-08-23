-- AEGIS portable cPanel production database.
-- Import this single file into an EMPTY MySQL/MariaDB database through phpMyAdmin.
-- No installer, migration, seed, Composer, Node, or CLI command is required.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

-- AEGIS — canonical MySQL / MariaDB schema (Phase 3 scope)
-- Install: php tools/install.php  (with AEGIS_DB_* env vars set)
-- Notes: JSON documents are stored as LONGTEXT for MySQL 5.7 / MariaDB 10.x
-- portability; monetary values use DECIMAL(18,8) to cover crypto units.

CREATE TABLE IF NOT EXISTS platform_state (
  k  VARCHAR(32) NOT NULL PRIMARY KEY,
  v  LONGTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS strategies (
  strategy_id   VARCHAR(60)  NOT NULL,
  version       VARCHAR(20)  NOT NULL,
  name          VARCHAR(120) NOT NULL,
  description   TEXT         NOT NULL,
  market_classes LONGTEXT    NOT NULL,
  timeframes    LONGTEXT     NOT NULL,
  params        LONGTEXT     NOT NULL,
  source        VARCHAR(10)  NOT NULL DEFAULT 'builtin',
  lifecycle     VARCHAR(20)  NOT NULL DEFAULT 'DRAFT',
  created_at    VARCHAR(32)  NOT NULL,
  updated_at    VARCHAR(32)  NOT NULL,
  lifecycle_history LONGTEXT NOT NULL,
  PRIMARY KEY (strategy_id, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS backtests (
  id               VARCHAR(36) NOT NULL PRIMARY KEY,
  created_at       VARCHAR(32) NOT NULL,
  strategy_id      VARCHAR(60) NOT NULL,
  strategy_version VARCHAR(20) NOT NULL,
  symbol           VARCHAR(20) NOT NULL,
  timeframe        VARCHAR(5)  NOT NULL,
  synthetic        TINYINT(1)  NOT NULL DEFAULT 0,
  payload          LONGTEXT    NOT NULL,
  INDEX idx_backtests_strategy (strategy_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS analysis_runs (
  id            VARCHAR(36) NOT NULL PRIMARY KEY,
  symbol        VARCHAR(20) NOT NULL,
  timeframe     VARCHAR(5)  NOT NULL,
  bias          VARCHAR(10) NOT NULL,
  confidence    DECIMAL(5,4) NOT NULL,
  regime        VARCHAR(20) NOT NULL,
  recommendation VARCHAR(10) NOT NULL,
  synthetic     TINYINT(1)  NOT NULL DEFAULT 0,
  source        VARCHAR(40) NOT NULL,
  completed_at  VARCHAR(32) NOT NULL,
  payload       LONGTEXT    NOT NULL,
  INDEX idx_analysis_completed (completed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS journal_entries (
  id                VARCHAR(36) NOT NULL PRIMARY KEY,
  source            VARCHAR(10) NOT NULL,          -- backtest | manual | paper | live
  symbol            VARCHAR(20) NOT NULL,
  market            VARCHAR(12) NOT NULL,
  strategy          VARCHAR(60) NULL,
  strategy_version  VARCHAR(20) NULL,
  direction         VARCHAR(5)  NOT NULL,
  entry_time        VARCHAR(32) NOT NULL,
  entry_price       DECIMAL(20,8) NOT NULL,
  exit_time         VARCHAR(32) NULL,
  exit_price        DECIMAL(20,8) NULL,
  position_size     DECIMAL(20,8) NOT NULL,
  stop_loss         DECIMAL(20,8) NULL,
  take_profit       DECIMAL(20,8) NULL,
  fees              DECIMAL(18,6) NOT NULL DEFAULT 0,
  slippage          DECIMAL(18,6) NOT NULL DEFAULT 0,
  pnl               DECIMAL(18,6) NULL,
  pnl_pct           DECIMAL(12,6) NULL,
  r_multiple        DECIMAL(12,6) NULL,
  reason            TEXT NULL,
  ai_confidence     DECIMAL(5,4) NULL,
  confidence_source VARCHAR(16) NULL,
  agent_consensus   VARCHAR(120) NULL,
  risk_score        DECIMAL(8,6) NULL,
  execution_time    VARCHAR(32) NOT NULL,
  backtest_id       VARCHAR(36) NULL,
  paper_position_id INT NULL,
  INDEX idx_journal_symbol (symbol, execution_time),
  INDEX idx_journal_strategy (strategy, execution_time),
  INDEX idx_journal_confidence (ai_confidence)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS paper_accounts (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  name             VARCHAR(60) NOT NULL,
  currency         VARCHAR(3)  NOT NULL DEFAULT 'USD',
  starting_balance DECIMAL(18,2) NOT NULL,
  balance          DECIMAL(18,2) NOT NULL,
  peak_equity      DECIMAL(18,2) NOT NULL,
  created_at       VARCHAR(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS paper_orders (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  account_id    INT NOT NULL,
  symbol        VARCHAR(20) NOT NULL,
  market_class  VARCHAR(12) NOT NULL,
  side          VARCHAR(4)  NOT NULL,
  type          VARCHAR(6)  NOT NULL,              -- MARKET | LIMIT
  units         DECIMAL(20,8) NOT NULL,
  price         DECIMAL(20,8) NULL,
  stop_loss     DECIMAL(20,8) NULL,
  take_profit   DECIMAL(20,8) NULL,
  status        VARCHAR(10) NOT NULL,              -- PENDING | FILLED | REJECTED | CANCELLED
  reject_reason TEXT NULL,
  risk_amount   DECIMAL(18,6) NULL,
  reason        TEXT NULL,
  ai_confidence DECIMAL(5,4) NULL,
  strategy      VARCHAR(60) NULL,
  created_at    VARCHAR(32) NOT NULL,
  filled_at     VARCHAR(32) NULL,
  fill_price    DECIMAL(20,8) NULL,
  INDEX idx_orders_account (account_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS paper_positions (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  account_id    INT NOT NULL,
  symbol        VARCHAR(20) NOT NULL,
  market_class  VARCHAR(12) NOT NULL,
  direction     VARCHAR(5)  NOT NULL,
  units         DECIMAL(20,8) NOT NULL,
  entry_price   DECIMAL(20,8) NOT NULL,
  stop_loss     DECIMAL(20,8) NOT NULL,
  take_profit   DECIMAL(20,8) NOT NULL,
  entry_fee     DECIMAL(18,6) NOT NULL DEFAULT 0,
  risk_amount   DECIMAL(18,6) NULL,
  strategy      VARCHAR(60) NULL,
  reason        TEXT NULL,
  ai_confidence DECIMAL(5,4) NULL,
  opened_at     VARCHAR(32) NOT NULL,
  status        VARCHAR(8)  NOT NULL DEFAULT 'OPEN',
  closed_at     VARCHAR(32) NULL,
  exit_price    DECIMAL(20,8) NULL,
  realized_pnl  DECIMAL(18,6) NULL,
  exit_reason   VARCHAR(16) NULL,
  INDEX idx_positions_account (account_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS paper_trades (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  account_id  INT NOT NULL,
  order_id    INT NULL,
  position_id INT NOT NULL,
  leg         VARCHAR(5) NOT NULL,                 -- ENTRY | EXIT
  symbol      VARCHAR(20) NOT NULL,
  price       DECIMAL(20,8) NOT NULL,
  units       DECIMAL(20,8) NOT NULL,
  fee         DECIMAL(18,6) NOT NULL DEFAULT 0,
  time        VARCHAR(32) NOT NULL,
  synthetic   TINYINT(1) NOT NULL DEFAULT 0,
  INDEX idx_paper_trades_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS paper_deployments (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  account_id        INT NOT NULL,
  strategy_id       VARCHAR(60) NOT NULL,
  strategy_version  VARCHAR(20) NOT NULL,
  symbol            VARCHAR(20) NOT NULL,
  market_class      VARCHAR(12) NOT NULL,
  timeframe         VARCHAR(5)  NOT NULL,
  active            TINYINT(1) NOT NULL DEFAULT 1,
  deployed_at       VARCHAR(32) NOT NULL,
  last_evaluated_at VARCHAR(32) NULL,
  last_signal       VARCHAR(8) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_logs (
  id      INT AUTO_INCREMENT PRIMARY KEY,
  type    VARCHAR(32) NOT NULL,
  at      VARCHAR(32) NOT NULL,
  actor   VARCHAR(8)  NOT NULL DEFAULT 'system',
  summary VARCHAR(500) NOT NULL,
  detail  LONGTEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Lead Discovery module (organization-scoped permanent PostgreSQL-compatible design).
CREATE TABLE IF NOT EXISTS leads (id VARCHAR(36) PRIMARY KEY, organization_id VARCHAR(80) NOT NULL, source VARCHAR(40) NOT NULL, source_id VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, category VARCHAR(255), address TEXT, city VARCHAR(120), region VARCHAR(120), country VARCHAR(120), phone VARCHAR(80), website TEXT, latitude DECIMAL(10,7), longitude DECIMAL(10,7), status VARCHAR(20) NOT NULL DEFAULT 'new', owner_id INT NULL, metadata LONGTEXT NOT NULL, created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL, UNIQUE KEY uq_lead_source (organization_id,source,source_id), KEY idx_leads_org_status(organization_id,status), KEY idx_leads_owner(organization_id,owner_id), KEY idx_leads_created(organization_id,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS lead_notes (id VARCHAR(36) PRIMARY KEY, lead_id VARCHAR(36) NOT NULL, organization_id VARCHAR(80) NOT NULL, author_id INT NOT NULL, body TEXT NOT NULL, created_at VARCHAR(32) NOT NULL, KEY idx_notes_lead(organization_id,lead_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS lead_activities (id VARCHAR(36) PRIMARY KEY, lead_id VARCHAR(36) NULL, organization_id VARCHAR(80) NOT NULL, actor_id INT NULL, type VARCHAR(50) NOT NULL, detail LONGTEXT NOT NULL, created_at VARCHAR(32) NOT NULL, KEY idx_activity_lead(organization_id,lead_id,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS collections (id VARCHAR(36) PRIMARY KEY, organization_id VARCHAR(80) NOT NULL, name VARCHAR(150) NOT NULL, created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL, UNIQUE KEY uq_collection(organization_id,name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS collection_leads (collection_id VARCHAR(36) NOT NULL, lead_id VARCHAR(36) NOT NULL, PRIMARY KEY(collection_id,lead_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS search_history (id VARCHAR(36) PRIMARY KEY, organization_id VARCHAR(80) NOT NULL, user_id INT NOT NULL, query TEXT NOT NULL, provider VARCHAR(40) NOT NULL, filters LONGTEXT NOT NULL, results_returned INT NOT NULL, new_leads_created INT NOT NULL, duplicates_detected INT NOT NULL, errors TEXT NULL, duration_ms INT NOT NULL, created_at VARCHAR(32) NOT NULL, KEY idx_history_org(organization_id,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS duplicate_candidates (id VARCHAR(36) PRIMARY KEY, organization_id VARCHAR(80) NOT NULL, lead_a_id VARCHAR(36) NOT NULL, lead_b_id VARCHAR(36) NOT NULL, rule_name VARCHAR(80) NOT NULL, confidence DECIMAL(4,3) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'open', created_at VARCHAR(32) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS duplicate_resolutions (id VARCHAR(36) PRIMARY KEY, candidate_id VARCHAR(36) NOT NULL, organization_id VARCHAR(80) NOT NULL, resolver_id INT NOT NULL, action VARCHAR(30) NOT NULL, created_at VARCHAR(32) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS export_history (id VARCHAR(36) PRIMARY KEY, organization_id VARCHAR(80) NOT NULL, user_id INT NOT NULL, format VARCHAR(10) NOT NULL, filters LONGTEXT NOT NULL, lead_count INT NOT NULL, created_at VARCHAR(32) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS lead_organizations (id VARCHAR(80) PRIMARY KEY, name VARCHAR(160) NOT NULL, created_at VARCHAR(32) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS lead_organization_members (organization_id VARCHAR(80) NOT NULL, user_id INT NOT NULL, role VARCHAR(20) NOT NULL DEFAULT 'member', created_at VARCHAR(32) NOT NULL, PRIMARY KEY(organization_id,user_id), KEY idx_lead_org_members_user(user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- Identity, access control, sports, decisions, and verified results.
-- Install alongside canonical schema.mysql.sql. Kept separate temporarily because
-- existing deployments need an explicit reviewed migration.
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(120) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at VARCHAR(32) NOT NULL,
  updated_at VARCHAR(32) NOT NULL,
  last_login_at VARCHAR(32) NULL,
  INDEX idx_users_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS roles (
  id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(64) NOT NULL UNIQUE, name VARCHAR(120) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS permissions (
  id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(96) NOT NULL UNIQUE, name VARCHAR(160) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS user_roles (user_id INT NOT NULL, role_id INT NOT NULL, PRIMARY KEY(user_id, role_id), INDEX idx_ur_role(role_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS role_permissions (role_id INT NOT NULL, permission_id INT NOT NULL, PRIMARY KEY(role_id, permission_id), INDEX idx_rp_permission(permission_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS auth_events (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, type VARCHAR(64) NOT NULL, detail LONGTEXT NULL, at VARCHAR(32) NOT NULL, INDEX idx_auth_events_user(user_id, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Sports Intelligence persistence: provider-neutral, source-attributed records.
CREATE TABLE IF NOT EXISTS sports_data_sources (
 id INT AUTO_INCREMENT PRIMARY KEY, provider_code VARCHAR(64) NOT NULL UNIQUE, display_name VARCHAR(120) NOT NULL,
 enabled TINYINT(1) NOT NULL DEFAULT 0, created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS sports_provider_health (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, provider_id INT NOT NULL, status VARCHAR(32) NOT NULL, response_ms INT NULL,
 error_rate DECIMAL(8,5) NULL, rate_limit_remaining INT NULL, last_success_at VARCHAR(32) NULL, last_failure_at VARCHAR(32) NULL,
 last_fixture_sync_at VARCHAR(32) NULL, last_odds_sync_at VARCHAR(32) NULL, last_result_sync_at VARCHAR(32) NULL,
 data_freshness_seconds INT NULL, records_received INT NOT NULL DEFAULT 0, invalid_records INT NOT NULL DEFAULT 0,
 missing_fields LONGTEXT NULL, observed_at VARCHAR(32) NOT NULL, INDEX idx_provider_health(provider_id, observed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS sports_matches (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, provider_id INT NOT NULL, external_id VARCHAR(128) NOT NULL, sport VARCHAR(32) NOT NULL,
 competition VARCHAR(160) NOT NULL, home_team VARCHAR(160) NOT NULL, away_team VARCHAR(160) NOT NULL, kickoff_at VARCHAR(32) NOT NULL,
 status VARCHAR(32) NOT NULL, source_timestamp VARCHAR(32) NOT NULL, payload LONGTEXT NOT NULL, created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL,
 UNIQUE KEY uq_sports_match_provider_external(provider_id, external_id), INDEX idx_sports_matches_kickoff(kickoff_at), INDEX idx_sports_matches_status(status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS sports_odds (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, match_id BIGINT NOT NULL, provider_id INT NOT NULL, market VARCHAR(96) NOT NULL,
 selection VARCHAR(160) NOT NULL, decimal_odds DECIMAL(12,6) NOT NULL, observed_at VARCHAR(32) NOT NULL, payload LONGTEXT NOT NULL,
 INDEX idx_sports_odds_match_market(match_id, market, observed_at), INDEX idx_sports_odds_provider(provider_id, observed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS sports_data_quality_assessments (
 id BIGINT AUTO_INCREMENT PRIMARY KEY, match_id BIGINT NOT NULL, score INT NOT NULL, band VARCHAR(16) NOT NULL,
 freshness_score INT NOT NULL, provider_reliability_score INT NOT NULL, eligible_prediction TINYINT(1) NOT NULL, eligible_ticket TINYINT(1) NOT NULL,
 missing_fields LONGTEXT NOT NULL, checks_payload LONGTEXT NOT NULL, assessed_at VARCHAR(32) NOT NULL, INDEX idx_sports_quality_match(match_id, assessed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS sports_sync_runs (
 id VARCHAR(36) PRIMARY KEY, provider_id INT NULL, job_type VARCHAR(48) NOT NULL, status VARCHAR(24) NOT NULL,
 started_at VARCHAR(32) NOT NULL, ended_at VARCHAR(32) NULL, records_processed INT NOT NULL DEFAULT 0, records_created INT NOT NULL DEFAULT 0,
 records_updated INT NOT NULL DEFAULT 0, errors LONGTEXT NULL, execution_key VARCHAR(128) NOT NULL UNIQUE, INDEX idx_sports_sync_runs_job(job_type, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Versioned, reproducible sports decision and ticket records.
CREATE TABLE IF NOT EXISTS sports_model_versions (id INT AUTO_INCREMENT PRIMARY KEY, model_name VARCHAR(120) NOT NULL, model_version VARCHAR(64) NOT NULL, feature_version VARCHAR(64) NOT NULL, calibration_version VARCHAR(64) NULL, status VARCHAR(24) NOT NULL, created_at VARCHAR(32) NOT NULL, UNIQUE KEY uq_sports_model_version(model_name, model_version));
CREATE TABLE IF NOT EXISTS sports_predictions (id VARCHAR(36) PRIMARY KEY, match_id BIGINT NOT NULL, model_version_id INT NOT NULL, market VARCHAR(96) NOT NULL, selection VARCHAR(160) NOT NULL, raw_probability DECIMAL(10,8) NULL, calibrated_probability DECIMAL(10,8) NULL, implied_probability DECIMAL(10,8) NULL, expected_value DECIMAL(12,8) NULL, confidence DECIMAL(10,8) NULL, risk VARCHAR(16) NOT NULL, correlation VARCHAR(16) NOT NULL, data_quality_score INT NOT NULL, decision VARCHAR(48) NOT NULL, rejection_reasons LONGTEXT NULL, factors LONGTEXT NOT NULL, input_version VARCHAR(64) NOT NULL, created_at VARCHAR(32) NOT NULL, INDEX idx_sports_predictions_match(match_id, created_at), INDEX idx_sports_predictions_model(model_version_id, created_at));
CREATE TABLE IF NOT EXISTS sports_tickets (id VARCHAR(36) PRIMARY KEY, created_at VARCHAR(32) NOT NULL, model_version_id INT NULL, configuration_version VARCHAR(64) NOT NULL, total_odds DECIMAL(14,6) NULL, selection_count INT NOT NULL, combined_probability DECIMAL(10,8) NULL, confidence DECIMAL(10,8) NULL, risk VARCHAR(16) NOT NULL, correlation VARCHAR(16) NOT NULL, data_quality_score INT NULL, status VARCHAR(32) NOT NULL, approval_status VARCHAR(32) NOT NULL, settlement_status VARCHAR(32) NOT NULL, reason TEXT NULL, INDEX idx_sports_tickets_status(status, created_at));
CREATE TABLE IF NOT EXISTS sports_ticket_selections (id BIGINT AUTO_INCREMENT PRIMARY KEY, ticket_id VARCHAR(36) NOT NULL, prediction_id VARCHAR(36) NOT NULL, match_id BIGINT NOT NULL, market VARCHAR(96) NOT NULL, selection VARCHAR(160) NOT NULL, odds DECIMAL(14,6) NOT NULL, odds_timestamp VARCHAR(32) NOT NULL, model_probability DECIMAL(10,8) NULL, calibrated_probability DECIMAL(10,8) NULL, expected_value DECIMAL(12,8) NULL, risk VARCHAR(16) NOT NULL, result VARCHAR(24) NULL, status VARCHAR(32) NOT NULL, INDEX idx_sports_ticket_selections_ticket(ticket_id), INDEX idx_sports_ticket_selections_prediction(prediction_id));
CREATE TABLE IF NOT EXISTS sports_results (id BIGINT AUTO_INCREMENT PRIMARY KEY, match_id BIGINT NOT NULL, provider_id INT NOT NULL, home_score INT NULL, away_score INT NULL, status VARCHAR(24) NOT NULL, verified TINYINT(1) NOT NULL DEFAULT 0, source_timestamp VARCHAR(32) NOT NULL, verified_at VARCHAR(32) NULL, payload LONGTEXT NOT NULL, UNIQUE KEY uq_sports_result_provider_match(provider_id, match_id), INDEX idx_sports_results_match(match_id, verified));

-- Required application state and RBAC defaults.
INSERT INTO platform_state (k, v) VALUES
('state', '{"tradingMode":"ANALYSIS_ONLY","killSwitch":{"active":true,"activatedAt":null,"reason":"Default state at boot — orders blocked until explicitly released"},"allowSyntheticPaperData":false}')
ON DUPLICATE KEY UPDATE v=VALUES(v);

INSERT INTO roles (id, code, name) VALUES
(1, 'super_admin', 'Super administrator'),
(2, 'sports_admin', 'Sports administrator'),
(3, 'sports_viewer', 'Sports viewer')
ON DUPLICATE KEY UPDATE name=VALUES(name);
INSERT INTO permissions (id, code, name) VALUES
(1, 'system.super_admin', 'Full platform administration'),
(2, 'sports.view', 'View sports intelligence'),
(3, 'sports.manage', 'Manage sports providers and configuration'),
(4, 'sports.approve', 'Approve sports tickets'),
(5, 'sports.settle', 'Override sports settlements')
ON DUPLICATE KEY UPDATE name=VALUES(name);
INSERT INTO role_permissions (role_id, permission_id) VALUES
(1,1),(1,2),(1,3),(1,4),(1,5),(2,2),(2,3),(2,4),(2,5),(3,2)
ON DUPLICATE KEY UPDATE role_id=VALUES(role_id);

-- Initial administrator. CHANGE THIS PASSWORD IMMEDIATELY AFTER FIRST LOGIN.
-- Email: admin@example.com  Password: ChangeMe!2026
INSERT INTO users (id, email, password_hash, display_name, active, created_at, updated_at, last_login_at) VALUES
(1, 'admin@example.com', '$2y$10$HAHKZ9rxRYLC3Zd2rJiykex19ZmZybcyXgzfHBJRcMB55VmU8Ti4O', 'Platform Administrator', 1, '2026-08-23T00:00:00Z', '2026-08-23T00:00:00Z', NULL)
ON DUPLICATE KEY UPDATE email=VALUES(email);
INSERT INTO user_roles (user_id, role_id) VALUES (1,1)
ON DUPLICATE KEY UPDATE user_id=VALUES(user_id);

-- Known provider reference records are disabled until credentials are configured.
INSERT INTO sports_data_sources (provider_code, display_name, enabled, created_at, updated_at) VALUES
('manual', 'Manual / approved source', 0, '2026-08-23T00:00:00Z', '2026-08-23T00:00:00Z')
ON DUPLICATE KEY UPDATE display_name=VALUES(display_name);
SET FOREIGN_KEY_CHECKS=1;
