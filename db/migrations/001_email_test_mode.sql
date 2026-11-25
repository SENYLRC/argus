-- Email test mode (after the linx/SEAL ILL "Email test mode" setting).
-- Applied once by deploy/install.sh, which records it in schema_migrations.

CREATE TABLE IF NOT EXISTS settings (
  name       VARCHAR(64) PRIMARY KEY,
  value      TEXT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Starts in "off" (log only, nothing sent): the seed accounts use real-looking
-- senylrc.org addresses, so nothing should reach a real inbox until an admin
-- deliberately switches it on from Administration > Email test mode.
INSERT IGNORE INTO settings (name, value) VALUES
  ('email_mode', 'off'),      -- '' send normally | 'redirect' all to email_test_to | 'off' send nothing
  ('email_test_to', '');      -- one or more addresses, comma-separated

-- What actually happened to each message: NULL = delivered normally,
-- otherwise e.g. "Not sent (email test mode: off)".
ALTER TABLE email_log ADD COLUMN delivery VARCHAR(255) DEFAULT NULL AFTER error;
