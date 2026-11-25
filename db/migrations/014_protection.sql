-- Ported from the SENYLRC WordPress security plugin: IP lockout, file
-- integrity monitor, forced password reset. Applied once by deploy/install.sh.

-- Failed sign-ins and (for visitors) 404s per IP, counted inside a time window.
CREATE TABLE IF NOT EXISTS ip_hits (
  id   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip   VARCHAR(45) NOT NULL,
  kind ENUM('login','404') NOT NULL,
  at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ip_kind_at (ip, kind, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Active lockouts. expires_at NULL + permanent = 1 stays until an admin lifts it.
CREATE TABLE IF NOT EXISTS ip_bans (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip         VARCHAR(45) NOT NULL,
  kind       ENUM('login','404') NOT NULL,
  permanent  TINYINT(1) NOT NULL DEFAULT 0,
  expires_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ip (ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- SHA-256 of every watched code file at the last approved baseline.
CREATE TABLE IF NOT EXISTS file_baseline (
  path   VARCHAR(255) NOT NULL PRIMARY KEY,
  sha256 CHAR(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Administrators can require a new password at next sign-in.
ALTER TABLE users ADD COLUMN force_pw_reset TINYINT(1) NOT NULL DEFAULT 0;

INSERT IGNORE INTO settings (name, value) VALUES
  -- IP protection (Administration > IP protection).
  ('lp_enabled',        '1'),
  ('lp_failed_logins',  '10'),    -- failed sign-ins / two-step codes from one IP …
  ('lp_timeframe',      '600'),   -- … within this many seconds lock the IP out of signing in
  ('lp_duration',       '15'),    -- minutes
  ('lp_permanent',      '0'),     -- 1 = stay locked until an admin lifts it
  ('lp_404_enabled',    '0'),     -- scanner lockout: many "not found" hits by someone not signed in
  ('lp_404_hits',       '20'),
  ('lp_404_timeframe',  '300'),
  ('lp_allowlist',      ''),      -- one address or CIDR per line; never locked
  ('lp_blocklist',      ''),      -- one address or CIDR per line; always blocked
  -- File integrity monitor (Administration > File integrity).
  ('fm_enabled',        '1'),
  ('fm_extensions',     'php,twig,js,css,sql,sh,conf,cron,json,lock,htaccess'),
  ('fm_exclude',        ''),      -- path prefixes to skip, one per line
  ('notify_file_changes', '1');
