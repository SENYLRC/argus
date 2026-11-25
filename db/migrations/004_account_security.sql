-- My account: self-service password change and optional two-step sign-in
-- (authenticator-app codes, RFC 6238 TOTP) with one-time recovery codes.

ALTER TABLE users
  ADD COLUMN totp_secret         VARCHAR(255) DEFAULT NULL AFTER pin_locked_until,  -- encrypted (sodium secretbox, APP_KEY in .env)
  ADD COLUMN totp_enabled_at     DATETIME     DEFAULT NULL AFTER totp_secret,
  ADD COLUMN totp_last_step      BIGINT       DEFAULT NULL AFTER totp_enabled_at,   -- last accepted 30-second step: blocks code replay
  ADD COLUMN session_version     INT UNSIGNED NOT NULL DEFAULT 0 AFTER totp_last_step, -- bumped to sign out every other session
  ADD COLUMN password_changed_at DATETIME     DEFAULT NULL AFTER session_version;

CREATE TABLE IF NOT EXISTS user_recovery_codes (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  code_hash  VARCHAR(255) NOT NULL,          -- password_hash() of the code; the code itself is shown once
  used_at    DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user (user_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
