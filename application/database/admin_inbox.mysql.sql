-- WINDELS AI WORKFORCE — Admin Inbox: contact messages, replies, email templates (MySQL / MariaDB).
-- Idempotent. Used by SchemaInstaller.

CREATE TABLE IF NOT EXISTS contact_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid CHAR(12) NOT NULL,
  sender_name VARCHAR(120) NOT NULL,
  sender_email VARCHAR(190) NOT NULL,
  sender_phone VARCHAR(40) NULL,
  sender_address VARCHAR(255) NULL,
  subject VARCHAR(200) NOT NULL DEFAULT 'Contact form inquiry',
  body MEDIUMTEXT NOT NULL,
  source VARCHAR(40) NOT NULL DEFAULT 'contact_form',
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  user_id INT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'new',
  is_starred TINYINT NOT NULL DEFAULT 0,
  is_read TINYINT NOT NULL DEFAULT 0,
  assigned_to INT NULL,
  last_reply_at VARCHAR(32) NULL,
  last_reply_by INT NULL,
  created_at VARCHAR(32) NOT NULL,
  updated_at VARCHAR(32) NOT NULL,
  INDEX idx_cm_created (created_at),
  INDEX idx_cm_status (status, created_at),
  INDEX idx_cm_email (sender_email),
  INDEX idx_cm_uid (uid),
  INDEX idx_cm_assigned (assigned_to, status),
  INDEX idx_cm_unread (is_read, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS contact_message_replies (
  id INT AUTO_INCREMENT PRIMARY KEY,
  message_id INT NOT NULL,
  template_id INT NULL,
  author_id INT NULL,
  author_label VARCHAR(190) NOT NULL,
  direction VARCHAR(10) NOT NULL DEFAULT 'outbound',
  to_email VARCHAR(190) NULL,
  subject VARCHAR(200) NOT NULL,
  body MEDIUMTEXT NOT NULL,
  body_text MEDIUMTEXT NULL,
  sent_at VARCHAR(32) NOT NULL,
  delivery_status VARCHAR(20) NOT NULL DEFAULT 'sent',
  delivery_message VARCHAR(255) NULL,
  ip VARCHAR(45) NULL,
  INDEX idx_cmr_message (message_id, sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS email_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(60) NOT NULL,
  name VARCHAR(120) NOT NULL,
  category VARCHAR(40) NOT NULL DEFAULT 'general',
  description VARCHAR(255) NULL,
  subject VARCHAR(200) NOT NULL,
  body_html MEDIUMTEXT NOT NULL,
  body_text MEDIUMTEXT NULL,
  variables_json LONGTEXT NOT NULL DEFAULT '{}',
  is_system TINYINT NOT NULL DEFAULT 0,
  is_active TINYINT NOT NULL DEFAULT 1,
  created_by INT NULL,
  updated_by INT NULL,
  created_at VARCHAR(32) NOT NULL,
  updated_at VARCHAR(32) NOT NULL,
  UNIQUE KEY uq_et_code (code),
  INDEX idx_et_category (category, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
