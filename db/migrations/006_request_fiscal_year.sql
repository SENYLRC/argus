-- Each request records which fiscal year (July 1 – June 30) it's charged to,
-- chosen on the request form; its budget lines are that FY's lines.
ALTER TABLE requests ADD COLUMN fiscal_year VARCHAR(9) DEFAULT NULL AFTER kind,
                     ADD KEY idx_fiscal_year (fiscal_year);

-- Existing requests: the FY they were created in.
UPDATE requests
   SET fiscal_year = CONCAT(
         IF(MONTH(created_at) >= 7, YEAR(created_at), YEAR(created_at) - 1), '-',
         LPAD(MOD(IF(MONTH(created_at) >= 7, YEAR(created_at) + 1, YEAR(created_at)), 100), 2, '0'))
 WHERE fiscal_year IS NULL;
