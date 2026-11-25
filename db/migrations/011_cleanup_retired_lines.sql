-- Retired budget lines that only *removed* recurring lines still pointed at
-- were kept by migration 008 (it counted every recurring line). Removed
-- recurring lines are hidden for good, so they no longer keep a line alive:
-- unlink them, then delete every retired line nothing real uses (no request
-- line, no active recurring line). Safe to re-run.
UPDATE recurring_templates rt
  JOIN budget_lines bl ON bl.id = rt.budget_line_id
   SET rt.budget_line_id = NULL
 WHERE rt.active = 0 AND bl.active = 0
   AND NOT EXISTS (SELECT 1 FROM request_lines rl WHERE rl.budget_line_id = bl.id);

DELETE FROM budget_lines
 WHERE active = 0
   AND NOT EXISTS (SELECT 1 FROM request_lines rl WHERE rl.budget_line_id = budget_lines.id)
   AND NOT EXISTS (SELECT 1 FROM recurring_templates rt WHERE rt.budget_line_id = budget_lines.id AND rt.active = 1);
