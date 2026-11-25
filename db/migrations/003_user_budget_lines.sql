-- Budget lines assigned to each staff member (Accounts > Edit). Staff see only
-- their assigned lines on the request form and their recurring sheet; the
-- Executive Director, bookkeeper and admin always see every line. A staff
-- member with no assignments yet still sees every line, so nobody is locked
-- out while assignments are being set up.
CREATE TABLE IF NOT EXISTS user_budget_lines (
  user_id        INT UNSIGNED NOT NULL,
  budget_line_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, budget_line_id),
  KEY idx_line (budget_line_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (budget_line_id) REFERENCES budget_lines(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Lines every staff member can use (travel, building, supplies…) without being
-- assigned one by one. Toggled from Administration > Budget lines.
ALTER TABLE budget_lines ADD COLUMN shared TINYINT(1) NOT NULL DEFAULT 0;

-- Starting point: lines everyone typically charges to. Adjust per line afterwards.
UPDATE budget_lines SET shared = 1
 WHERE LOWER(label) REGEXP 'travel|mileage|postage|shipping|building|facilit';
