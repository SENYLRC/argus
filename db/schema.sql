-- Expenditure & Encumbrance Request System
-- Schema per design_handoff_expense_requests/build-notes.md §2, plus password_resets
-- (referenced in build-notes §2b but not given as SQL there) and login/PIN rate-limit
-- columns (build-notes §6: "rate-limit to 5 attempts").
--
-- Apply with:  mysql -u root -p senylrc_expenses < db/schema.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE users (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name                  VARCHAR(120) NOT NULL,
  title                 VARCHAR(160) DEFAULT NULL,
  email                 VARCHAR(190) NOT NULL UNIQUE,
  role                  ENUM('admin','ed','bookkeeper','staff') NOT NULL DEFAULT 'staff',
  password_hash         VARCHAR(255) DEFAULT NULL,      -- NULL until the set-password link is used
  signoff_pin           VARCHAR(255) DEFAULT NULL,      -- hashed separately; re-entered at sign-off
  signature_img         VARCHAR(255) DEFAULT NULL,      -- unused by the app: superseded by signature_images.is_default
                                                         -- (build-notes §2 vs §2c); kept only for schema fidelity
  active                TINYINT(1) NOT NULL DEFAULT 1,
  failed_login_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  login_locked_until    DATETIME DEFAULT NULL,
  failed_pin_attempts   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  pin_locked_until      DATETIME DEFAULT NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE password_resets (              -- single-use, 48h set-password / reset link (build-notes §2b)
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL,
  token_hash    VARCHAR(255) NOT NULL,      -- sha256 of the token; the raw token is only ever in the emailed link
  expires_at    DATETIME NOT NULL,
  used_at       DATETIME DEFAULT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_user (user_id),
  KEY idx_token_hash (token_hash),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE budget_classes (               -- EmpireADC, HLSP, Operating, State Operating, Other/grant
  id    TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name  VARCHAR(60) NOT NULL UNIQUE,
  sort  TINYINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE budget_lines (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  class_id  TINYINT UNSIGNED NOT NULL,
  code      VARCHAR(20)  NOT NULL,            -- '84.32'
  label     VARCHAR(160) NOT NULL,            -- 'Internet & Telecom'
  fiscal_year VARCHAR(9) NOT NULL DEFAULT '2026-27',
  verified  TINYINT(1) NOT NULL DEFAULT 1,    -- 0 = staff free-texted it; admin promotes or merges
  active    TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_line (class_id, code, fiscal_year),
  FOREIGN KEY (class_id) REFERENCES budget_classes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE requests (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  number        VARCHAR(20) NOT NULL UNIQUE,  -- 'R-2026-041'
  kind          ENUM('expenditure','encumbrance') NOT NULL DEFAULT 'expenditure',
  status        ENUM('pending','approved','rejected','complete') NOT NULL DEFAULT 'pending',
  requester_id  INT UNSIGNED NOT NULL,
  payable_to    VARCHAR(190) NOT NULL,
  remit_address TEXT,
  payment_method ENUM('check','card','ach','reimbursement') NOT NULL DEFAULT 'check',
  date_needed   DATE DEFAULT NULL,
  justification TEXT,
  is_recurring  TINYINT(1) NOT NULL DEFAULT 0,
  recurring_id  INT UNSIGNED DEFAULT NULL,    -- template it came from
  batch_id      INT UNSIGNED DEFAULT NULL,
  total_cents   INT NOT NULL DEFAULT 0,       -- always store money in cents
  -- ED action
  approved_by   INT UNSIGNED DEFAULT NULL,
  approved_at   DATETIME DEFAULT NULL,
  reject_reason TEXT,
  -- bookkeeper action
  posted_date   DATE DEFAULT NULL,
  check_number  VARCHAR(40) DEFAULT NULL,     -- or Enc. # for encumbrances
  date_ordered  DATE DEFAULT NULL,
  completed_by  INT UNSIGNED DEFAULT NULL,
  completed_at  DATETIME DEFAULT NULL,
  notes         TEXT,
  record_pdf_path VARCHAR(255) DEFAULT NULL,  -- immutable, written once when status first reaches 'complete'
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_status (status), KEY idx_requester (requester_id),
  FOREIGN KEY (requester_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE request_lines (                  -- paper form allows 4; no hard cap needed
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id    INT UNSIGNED NOT NULL,
  budget_line_id INT UNSIGNED DEFAULT NULL,   -- NULL when free-texted
  free_text     VARCHAR(190) DEFAULT NULL,
  class_id      TINYINT UNSIGNED NOT NULL,
  amount_cents  INT NOT NULL,
  sort          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
  FOREIGN KEY (budget_line_id) REFERENCES budget_lines(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE attachments (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id  INT UNSIGNED NOT NULL,
  orig_name   VARCHAR(255) NOT NULL,
  stored_path VARCHAR(255) NOT NULL,          -- /storage/attachments/2026/09/<uuid>.pdf
  mime        VARCHAR(80) NOT NULL,
  bytes       INT UNSIGNED NOT NULL,
  description VARCHAR(255) DEFAULT NULL,      -- text alternative (WCAG 1.1.1) for scanned/image attachments
  uploaded_by INT UNSIGNED NOT NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE signature_images (            -- a user may keep several: full signature, initials
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  label       VARCHAR(80) NOT NULL,          -- 'Full signature', 'Initials'
  stored_path VARCHAR(255) NOT NULL,         -- /storage/signatures/<uuid>.png (outside web root)
  is_default  TINYINT(1) NOT NULL DEFAULT 0,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  retired_at  DATETIME DEFAULT NULL,         -- never hard-delete: past records reference it
  KEY idx_user (user_id),
  FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE signoffs (                       -- immutable; one row per signature
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id   INT UNSIGNED NOT NULL,
  user_id      INT UNSIGNED NOT NULL,
  role_at_time ENUM('admin','ed','bookkeeper') NOT NULL,
  action       ENUM('approve','reject','complete') NOT NULL,
  typed_name   VARCHAR(120) NOT NULL,
  signature_image_id INT UNSIGNED DEFAULT NULL,  -- which one they picked at sign time
  signature_copy_path VARCHAR(255) DEFAULT NULL, -- immutable copy taken at sign time
  pin_verified TINYINT(1) NOT NULL DEFAULT 0,
  ip           VARCHAR(45) NOT NULL,
  geo_city     VARCHAR(80)  DEFAULT NULL,
  geo_region   VARCHAR(80)  DEFAULT NULL,
  geo_country  CHAR(2)      DEFAULT NULL,
  geo_isp      VARCHAR(120) DEFAULT NULL,
  geo_lat      DECIMAL(8,5) DEFAULT NULL,
  geo_lon      DECIMAL(8,5) DEFAULT NULL,
  user_agent   VARCHAR(255) NOT NULL,
  signed_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (request_id) REFERENCES requests(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE recurring_templates (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_id      INT UNSIGNED NOT NULL,
  payable_to    VARCHAR(190) NOT NULL,
  budget_line_id INT UNSIGNED DEFAULT NULL,
  class_id      TINYINT UNSIGNED NOT NULL,
  default_cents INT NOT NULL DEFAULT 0,
  last_cents    INT NOT NULL DEFAULT 0,
  payment_method ENUM('check','card','ach','reimbursement') NOT NULL DEFAULT 'card',
  active        TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (owner_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE recurring_batches (              -- one sheet per user per month
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_id   INT UNSIGNED NOT NULL,
  period     CHAR(7) NOT NULL,                -- '2026-09'
  submitted_at DATETIME DEFAULT NULL,
  UNIQUE KEY uq_batch (owner_id, period),
  FOREIGN KEY (owner_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE audit_log (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_id   INT UNSIGNED DEFAULT NULL,       -- NULL = cron/system
  actor_email VARCHAR(190) NOT NULL,
  entity     VARCHAR(40) NOT NULL,            -- 'request','user','budget_line'
  entity_id  INT UNSIGNED DEFAULT NULL,
  action     VARCHAR(60) NOT NULL,            -- 'create','edit','approve','reject','complete','delete','login'
  detail     TEXT,
  before_json JSON DEFAULT NULL,
  after_json  JSON DEFAULT NULL,
  ip         VARCHAR(45) NOT NULL,
  geo_city   VARCHAR(80)  DEFAULT NULL,
  geo_region VARCHAR(80)  DEFAULT NULL,
  geo_country CHAR(2)     DEFAULT NULL,
  geo_isp    VARCHAR(120) DEFAULT NULL,
  geo_lat    DECIMAL(8,5) DEFAULT NULL,
  geo_lon    DECIMAL(8,5) DEFAULT NULL,
  user_agent VARCHAR(255) DEFAULT NULL,
  at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_entity (entity, entity_id), KEY idx_at (at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE email_log (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  template   VARCHAR(40) NOT NULL,            -- 'rejected','weekly_digest','recurring_reminder'
  to_email   VARCHAR(190) NOT NULL,
  request_id INT UNSIGNED DEFAULT NULL,
  subject    VARCHAR(255) NOT NULL,
  sent_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ok         TINYINT(1) NOT NULL DEFAULT 1,
  error      VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
