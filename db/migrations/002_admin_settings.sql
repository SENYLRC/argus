-- Administration > Email, Files & branding, Site banner settings.
-- Applied once by deploy/install.sh (recorded in schema_migrations).

INSERT IGNORE INTO settings (name, value) VALUES
  -- Site banner: on while the system is being tested; shown to everyone,
  -- sign-in page included. Turn off at go-live from Administration > Site banner.
  ('site_banner_on',   '1'),
  ('site_banner_text', 'TEST SITE — requests, accounts and emails here are for testing and may be reset.'),
  -- Email options. Blank from_* falls back to config/config.php.
  ('mail_from_name',  ''),
  ('mail_from_email', ''),
  ('mail_reply_to_approver', '1'),   -- rejection notices: Reply-To the approver who rejected
  -- Per-notice switches ('1' on, '0' off). Set-password links are always sent.
  ('notify_rejected',           '1'),
  ('notify_weekly_digest',      '1'),
  ('notify_recurring_reminder', '1');
-- Custom logos are stored as brand_<slot> = storage-relative path (none by default).
