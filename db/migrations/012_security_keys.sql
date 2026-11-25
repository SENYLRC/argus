-- Two-step sign-in with security keys (YubiKey and other FIDO2/WebAuthn
-- authenticators), alongside authenticator-app codes. One row per key.
CREATE TABLE IF NOT EXISTS user_security_keys (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL,
  name          VARCHAR(80) NOT NULL,             -- "YubiKey 5 NFC (blue)" — the person's own label
  credential_id VARCHAR(512) NOT NULL,            -- base64 of the authenticator's credential id
  public_key    TEXT NOT NULL,                    -- PEM; verifies each sign-in signature
  sign_count    INT UNSIGNED NOT NULL DEFAULT 0,  -- authenticator counter (clone detection)
  aaguid        VARCHAR(64) DEFAULT NULL,         -- authenticator model id, informational
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at  DATETIME DEFAULT NULL,
  UNIQUE KEY uq_credential (credential_id(255)),
  KEY idx_user (user_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
