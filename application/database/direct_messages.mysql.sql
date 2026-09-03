-- WINDELS AI WORKFORCE — Direct member ⇄ administrator messages (MySQL / MariaDB).
-- Idempotent. Used by SchemaInstaller.

CREATE TABLE IF NOT EXISTS direct_messages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  sender_id INT NOT NULL,
  sender_role VARCHAR(10) NOT NULL DEFAULT 'user',
  sender_label VARCHAR(190) NOT NULL DEFAULT '',
  body MEDIUMTEXT NOT NULL,
  read_by_user TINYINT NOT NULL DEFAULT 0,
  read_by_admin TINYINT NOT NULL DEFAULT 0,
  created_at VARCHAR(32) NOT NULL,
  INDEX idx_dm_thread (user_id, created_at),
  INDEX idx_dm_admin_unread (sender_role, read_by_admin),
  INDEX idx_dm_user_unread (user_id, sender_role, read_by_user)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
