-- Starter data: the budget classes and a few example budget lines.
-- No accounts are seeded: create the first admin with
--   php bin/console user:create-admin you@example.org "Your Name"
-- then add everyone else from Accounts. Budget lines are managed in
-- Administration > Overview (or synced from QuickBooks Online).

SET NAMES utf8mb4;

INSERT INTO budget_classes (id, name, sort) VALUES
 (1,'Operating',1),(2,'State Operating',2),(3,'HLSP',3),(4,'EmpireADC',4),(5,'Other / grant',5);

INSERT INTO budget_lines (id, class_id, code, label) VALUES
 (1,1,'84.32','Internet & Telecom'),
 (2,1,'84.33','Telephone, Office'),
 (3,2,'85.212','Proxy service'),
 (4,1,'84.26','Non-Capital Computer Equipment'),
 (5,1,'84.28','Technology Supplies'),
 (6,3,'85.220','Cloud Hosting');
