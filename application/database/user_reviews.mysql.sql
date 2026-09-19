-- Public user reviews and comments.
CREATE TABLE IF NOT EXISTS user_reviews (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  rating TINYINT UNSIGNED NOT NULL,
  body TEXT NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'published',
  created_at VARCHAR(32) NOT NULL,
  updated_at VARCHAR(32) NOT NULL,
  INDEX idx_user_reviews_public (status, created_at),
  INDEX idx_user_reviews_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
