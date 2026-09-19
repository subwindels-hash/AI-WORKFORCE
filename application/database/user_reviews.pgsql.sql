-- Public user reviews and comments.
CREATE TABLE IF NOT EXISTS user_reviews (
  id BIGSERIAL PRIMARY KEY,
  user_id INTEGER NOT NULL,
  rating SMALLINT NOT NULL CHECK (rating BETWEEN 1 AND 5),
  body TEXT NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'published',
  created_at VARCHAR(32) NOT NULL,
  updated_at VARCHAR(32) NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_user_reviews_public ON user_reviews(status, created_at);
CREATE INDEX IF NOT EXISTS idx_user_reviews_user ON user_reviews(user_id, created_at);
