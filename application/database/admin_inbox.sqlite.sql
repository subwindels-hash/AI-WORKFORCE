-- WINDELS AI WORKFORCE — Admin Inbox: contact messages, replies, email templates (SQLite dev mirror).

CREATE TABLE IF NOT EXISTS contact_messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  uid TEXT NOT NULL,
  sender_name TEXT NOT NULL,
  sender_email TEXT NOT NULL,
  sender_phone TEXT,
  sender_address TEXT,
  subject TEXT NOT NULL DEFAULT 'Contact form inquiry',
  body TEXT NOT NULL,
  source TEXT NOT NULL DEFAULT 'contact_form',
  ip TEXT,
  user_agent TEXT,
  user_id INTEGER,
  status TEXT NOT NULL DEFAULT 'new',
  is_starred INTEGER NOT NULL DEFAULT 0,
  is_read INTEGER NOT NULL DEFAULT 0,
  assigned_to INTEGER,
  last_reply_at TEXT,
  last_reply_by INTEGER,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_cm_created ON contact_messages(created_at);
CREATE INDEX IF NOT EXISTS idx_cm_status ON contact_messages(status, created_at);
CREATE INDEX IF NOT EXISTS idx_cm_email ON contact_messages(sender_email);
CREATE INDEX IF NOT EXISTS idx_cm_uid ON contact_messages(uid);
CREATE INDEX IF NOT EXISTS idx_cm_assigned ON contact_messages(assigned_to, status);
CREATE INDEX IF NOT EXISTS idx_cm_unread ON contact_messages(is_read, created_at);

CREATE TABLE IF NOT EXISTS contact_message_replies (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  message_id INTEGER NOT NULL,
  template_id INTEGER,
  author_id INTEGER,
  author_label TEXT NOT NULL,
  direction TEXT NOT NULL DEFAULT 'outbound',
  to_email TEXT,
  subject TEXT NOT NULL,
  body TEXT NOT NULL,
  body_text TEXT,
  sent_at TEXT NOT NULL,
  delivery_status TEXT NOT NULL DEFAULT 'sent',
  delivery_message TEXT,
  ip TEXT
);
CREATE INDEX IF NOT EXISTS idx_cmr_message ON contact_message_replies(message_id, sent_at);

CREATE TABLE IF NOT EXISTS email_templates (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code TEXT NOT NULL UNIQUE,
  name TEXT NOT NULL,
  category TEXT NOT NULL DEFAULT 'general',
  description TEXT,
  subject TEXT NOT NULL,
  body_html TEXT NOT NULL,
  body_text TEXT,
  variables_json TEXT NOT NULL DEFAULT '{}',
  is_system INTEGER NOT NULL DEFAULT 0,
  is_active INTEGER NOT NULL DEFAULT 1,
  created_by INTEGER,
  updated_by INTEGER,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_et_category ON email_templates(category, is_active);
