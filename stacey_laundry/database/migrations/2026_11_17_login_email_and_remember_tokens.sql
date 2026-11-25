ALTER TABLE users
  ADD COLUMN email VARCHAR(254) NULL UNIQUE AFTER username;

CREATE TABLE IF NOT EXISTS user_remember_tokens (
  token_hash CHAR(64) PRIMARY KEY,
  user_id INT NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_remember_tokens_user_expiry (user_id, expires_at),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
