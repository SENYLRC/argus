-- Budgeted amount per line per fiscal year (cents). NULL = no budget entered.
-- Amounts are entered in Administration > Overview or synced from QuickBooks Online.
ALTER TABLE budget_lines ADD COLUMN budget_cents INT DEFAULT NULL AFTER label;
