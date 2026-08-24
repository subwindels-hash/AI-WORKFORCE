-- AI Language Learning module (Phase 1). Canonical MySQL schema; the
-- sqlite dev mirror lives in langlearn.sqlite.sql. No fake progress: every
-- row is produced by real user activity (answers, attempts, sessions).

CREATE TABLE IF NOT EXISTS languages (
  code           VARCHAR(8) PRIMARY KEY,          -- ISO 639-1/3 code
  name           VARCHAR(60) NOT NULL,
  native_name    VARCHAR(120) NOT NULL,
  iso_code       VARCHAR(8) NOT NULL,
  writing_system VARCHAR(40) NOT NULL,            -- latin | cyrillic | devanagari | arabic | han | kana | hangul | ...
  direction      VARCHAR(3) NOT NULL DEFAULT 'ltr',
  features       LONGTEXT NOT NULL,               -- JSON: assessment, listening, speaking, writing …
  active         TINYINT(1) NOT NULL DEFAULT 1,
  updated_at     VARCHAR(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS user_language_profiles (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  user_id              INT NOT NULL,
  language_code        VARCHAR(8) NOT NULL,
  level                VARCHAR(10) NOT NULL DEFAULT 'Beginner',   -- Beginner|A1..C2 (set only by assessment)
  goal                 VARCHAR(300) NULL,
  explanation_language VARCHAR(8) NOT NULL DEFAULT 'en',
  status               VARCHAR(16) NOT NULL DEFAULT 'ACTIVE',
  created_at           VARCHAR(32) NOT NULL,
  updated_at           VARCHAR(32) NOT NULL,
  UNIQUE KEY uq_profile_user_language (user_id, language_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS language_assessments (
  id           VARCHAR(36) PRIMARY KEY,
  profile_id   INT NOT NULL,
  user_id      INT NOT NULL,
  language_code VARCHAR(8) NOT NULL,
  status       VARCHAR(12) NOT NULL DEFAULT 'IN_PROGRESS',        -- IN_PROGRESS|COMPLETED
  state        LONGTEXT NOT NULL,                 -- adaptive engine state (queue, position, per-skill stats)
  result       LONGTEXT NULL,                     -- final verdict: per-skill levels, overall, strengths, weaknesses
  started_at   VARCHAR(32) NOT NULL,
  completed_at VARCHAR(32) NULL,
  KEY idx_assessments_profile (profile_id, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS learning_paths (
  id            VARCHAR(36) PRIMARY KEY,
  profile_id    INT NOT NULL,
  language_code VARCHAR(8) NOT NULL,
  from_level    VARCHAR(10) NOT NULL,
  target_level  VARCHAR(10) NOT NULL,
  status        VARCHAR(12) NOT NULL DEFAULT 'ACTIVE',
  created_at    VARCHAR(32) NOT NULL,
  KEY idx_paths_profile (profile_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS learning_modules (
  id               VARCHAR(36) PRIMARY KEY,
  path_id          VARCHAR(36) NOT NULL,
  profile_id       INT NOT NULL,
  language_code    VARCHAR(8) NOT NULL,
  sequence         INT NOT NULL,
  code             VARCHAR(60) NOT NULL,
  title            VARCHAR(160) NOT NULL,
  focus_skill      VARCHAR(12) NOT NULL,          -- vocabulary|grammar|reading
  level            VARCHAR(10) NOT NULL,
  status           VARCHAR(12) NOT NULL DEFAULT 'LOCKED',  -- LOCKED|AVAILABLE|IN_PROGRESS|COMPLETED
  attempts_count   INT NOT NULL DEFAULT 0,
  completed_at     VARCHAR(32) NULL,
  KEY idx_modules_path (path_id, sequence),
  KEY idx_modules_profile (profile_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS lesson_attempts (
  id             VARCHAR(36) PRIMARY KEY,
  profile_id     INT NOT NULL,
  user_id        INT NOT NULL,
  language_code  VARCHAR(8) NOT NULL,
  module_id      VARCHAR(36) NULL,
  kind           VARCHAR(16) NOT NULL,            -- assessment|checkpoint|lesson (Phase 2)
  score_pct      DECIMAL(5,2) NULL,
  passed         TINYINT(1) NULL,
  detail         LONGTEXT NOT NULL,               -- items, answers, explanations (audit-grade)
  created_at     VARCHAR(32) NOT NULL,
  KEY idx_attempts_profile (profile_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS study_sessions (
  id             VARCHAR(36) PRIMARY KEY,
  profile_id     INT NOT NULL,
  user_id        INT NOT NULL,
  language_code  VARCHAR(8) NOT NULL,
  activity       VARCHAR(24) NOT NULL,            -- assessment|checkpoint|review (Phase 3)…
  day            VARCHAR(10) NOT NULL,            -- UTC date, for streak math
  created_at     VARCHAR(32) NOT NULL,
  KEY idx_sessions_profile_day (profile_id, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS language_progress (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  profile_id         INT NOT NULL,
  user_id            INT NOT NULL,
  language_code      VARCHAR(8) NOT NULL,
  skill              VARCHAR(12) NOT NULL,        -- vocabulary|grammar|reading|listening|writing|speaking|overall
  level              VARCHAR(10) NULL,            -- from real assessment data only
  value_pct          DECIMAL(5,2) NULL,           -- derived from real events only, never invented
  source             VARCHAR(24) NOT NULL,        -- assessment|path_completion|activity
  updated_at         VARCHAR(32) NOT NULL,
  UNIQUE KEY uq_progress (profile_id, skill, source),
  KEY idx_progress_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
