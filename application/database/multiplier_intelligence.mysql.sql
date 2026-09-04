-- AI Multiplier Intelligence Schema
-- WINDELS AI OS - Crash Game Analytics Module

-- Provider registry
CREATE TABLE IF NOT EXISTS crash_game_providers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(64) UNIQUE NOT NULL,
    name VARCHAR(128) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    config_json TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Provider health tracking
CREATE TABLE IF NOT EXISTS crash_game_provider_health (
    id INT AUTO_INCREMENT PRIMARY KEY,
    provider_id INT NOT NULL,
    status VARCHAR(32) NOT NULL,
    latency_ms INT,
    detail TEXT,
    checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (provider_id) REFERENCES crash_game_providers(id) ON DELETE CASCADE,
    INDEX idx_provider (provider_id),
    INDEX idx_checked (checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Game rounds (historical data)
CREATE TABLE IF NOT EXISTS crash_game_rounds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    provider_id INT NOT NULL,
    round_id VARCHAR(128) NOT NULL,
    game_code VARCHAR(64) NOT NULL DEFAULT 'aviator',
    multiplier DECIMAL(12,4) NOT NULL,
    started_at DATETIME NOT NULL,
    crashed_at DATETIME,
    duration_ms INT,
    verified TINYINT(1) NOT NULL DEFAULT 0,
    raw_data_json TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (provider_id) REFERENCES crash_game_providers(id) ON DELETE CASCADE,
    UNIQUE KEY uk_provider_round (provider_id, round_id),
    INDEX idx_game (game_code),
    INDEX idx_started (started_at),
    INDEX idx_multiplier (multiplier),
    INDEX idx_verified (verified)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- AI Models registry
CREATE TABLE IF NOT EXISTS crash_game_models (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(64) UNIQUE NOT NULL,
    name VARCHAR(128) NOT NULL,
    version VARCHAR(32) NOT NULL DEFAULT '1.0',
    description TEXT,
    config_json TEXT,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_enabled (enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Predictions
CREATE TABLE IF NOT EXISTS crash_game_predictions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    model_id INT NOT NULL,
    provider_id INT NOT NULL,
    round_id VARCHAR(128),
    predicted_multiplier DECIMAL(12,4) NOT NULL,
    predicted_min DECIMAL(12,4),
    predicted_max DECIMAL(12,4),
    confidence DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    risk_level ENUM('LOW','MEDIUM','HIGH','EXTREME') NOT NULL DEFAULT 'MEDIUM',
    signal_type VARCHAR(32) NOT NULL DEFAULT 'MULTIPLIER',
    agents_json TEXT,
    features_json TEXT,
    predicted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actual_multiplier DECIMAL(12,4),
    actual_at DATETIME,
    error_value DECIMAL(12,4),
    error_pct DECIMAL(8,2),
    validated TINYINT(1) NOT NULL DEFAULT 0,
    validated_at DATETIME,
    FOREIGN KEY (model_id) REFERENCES crash_game_models(id),
    FOREIGN KEY (provider_id) REFERENCES crash_game_providers(id) ON DELETE CASCADE,
    INDEX idx_model (model_id),
    INDEX idx_provider (provider_id),
    INDEX idx_predicted (predicted_at),
    INDEX idx_validated (validated),
    INDEX idx_confidence (confidence)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Agent executions (audit trail)
CREATE TABLE IF NOT EXISTS crash_game_agent_executions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    prediction_id INT,
    agent_type VARCHAR(64) NOT NULL,
    agent_name VARCHAR(128) NOT NULL,
    input_json TEXT,
    output_json TEXT,
    confidence DECIMAL(5,2),
    latency_ms INT,
    status VARCHAR(32) NOT NULL DEFAULT 'COMPLETED',
    executed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (prediction_id) REFERENCES crash_game_predictions(id) ON DELETE SET NULL,
    INDEX idx_prediction (prediction_id),
    INDEX idx_agent (agent_type),
    INDEX idx_executed (executed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Accuracy snapshots (for performance tracking)
CREATE TABLE IF NOT EXISTS crash_game_accuracy_snapshots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    model_id INT NOT NULL,
    window_size INT NOT NULL,
    window_type ENUM('LAST_N','TIME_RANGE') NOT NULL DEFAULT 'LAST_N',
    total_predictions INT NOT NULL DEFAULT 0,
    validated_predictions INT NOT NULL DEFAULT 0,
    accuracy_pct DECIMAL(5,2),
    avg_error DECIMAL(12,4),
    avg_confidence DECIMAL(5,2),
    best_confidence DECIMAL(5,2),
    worst_error DECIMAL(12,4),
    snapshot_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (model_id) REFERENCES crash_game_models(id),
    INDEX idx_model (model_id),
    INDEX idx_snapshot (snapshot_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Real-time signals (current active signal)
CREATE TABLE IF NOT EXISTS crash_game_active_signals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    prediction_id INT NOT NULL,
    provider_id INT NOT NULL,
    status ENUM('ANALYZING','ACTIVE','EXPIRED','HIT','MISSED') NOT NULL DEFAULT 'ANALYZING',
    signal_json TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (prediction_id) REFERENCES crash_game_predictions(id),
    FOREIGN KEY (provider_id) REFERENCES crash_game_providers(id) ON DELETE CASCADE,
    INDEX idx_status (status),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default models
INSERT IGNORE INTO crash_game_models (code, name, version, description) VALUES
('STATISTICAL-BASELINE-v1', 'Statistical Baseline', '1.0', 'Pure statistical analysis using historical distributions'),
('PATTERN-ENSEMBLE-v1', 'Pattern Ensemble', '1.0', 'Ensemble of pattern detection agents'),
('SEQUENCE-LSTM-v1', 'Sequence LSTM', '1.0', 'Sequence analysis with LSTM-style patterns'),
('MIXED-ENSEMBLE-v1', 'Mixed Ensemble', '1.0', 'Combined output from all specialist agents'),
('ANOMALY-AWARE-v1', 'Anomaly Aware', '1.0', 'Model that adjusts for detected anomalies');

-- Insert default providers (simulation only until configured)
INSERT IGNORE INTO crash_game_providers (code, name, enabled) VALUES
('bustabit', 'Bustabit (Live)', 1),
('simulation', 'Simulation (Demo Data)', 0),
('aviator_demo', 'Aviator Demo Adapter', 0);
