-- WINDELS AI WORKFORCE — Direct member ⇄ administrator messages (SQLite dev mirror).
-- One thread per member; sender_role distinguishes the two sides. Read flags
-- are per side so the member's badge and the admin console badge are independent.

CREATE TABLE IF NOT EXISTS direct_messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  sender_id INTEGER NOT NULL,
  sender_role TEXT NOT NULL DEFAULT 'user',
  sender_label TEXT NOT NULL DEFAULT '',
  body TEXT NOT NULL,
  read_by_user INTEGER NOT NULL DEFAULT 0,
  read_by_admin INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_dm_thread ON direct_messages(user_id, created_at);
CREATE INDEX IF NOT EXISTS idx_dm_admin_unread ON direct_messages(sender_role, read_by_admin);
CREATE INDEX IF NOT EXISTS idx_dm_user_unread ON direct_messages(user_id, sender_role, read_by_user);
