-- Administration > Security: require two-step sign-in, and the password policy.
INSERT IGNORE INTO settings (name, value) VALUES
  ('require_2fa', ''),                -- '' optional · 'privileged' ED, bookkeeper, Admin / Tech · 'all' everyone
  ('password_min_length', '12'),      -- characters
  ('password_min_score', '3'),        -- zxcvbn 0 (very weak) … 4 (very strong)
  ('password_check_breached', '1');   -- Have I Been Pwned range check (k-anonymity; skipped if unreachable)
