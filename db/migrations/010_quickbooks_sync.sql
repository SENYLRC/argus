-- QuickBooks Online sync (Administration > QuickBooks): pull classes, the
-- chart of accounts and each fiscal year's budget into budget_lines.
-- Connection tokens live encrypted in `settings` (qbo_* keys), not here.

-- Which QuickBooks object a row came from. Lines/classes with NULL here were
-- entered by hand and are never changed or retired by a sync.
ALTER TABLE budget_lines   ADD COLUMN qbo_account_id VARCHAR(32) DEFAULT NULL AFTER budget_cents,
                           ADD KEY idx_qbo_account (qbo_account_id);
ALTER TABLE budget_classes ADD COLUMN qbo_class_id   VARCHAR(32) DEFAULT NULL AFTER sort;

INSERT IGNORE INTO settings (name, value) VALUES
  ('qbo_series', '83,84,85'),   -- account series to import (numbers before the dot)
  ('qbo_auto_sync', '0');       -- nightly sync of the current FY: '1' on, '0' off
