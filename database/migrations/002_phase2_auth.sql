-- Phase 2: authentication & account management (additive; no existing data is touched)
ALTER TABLE users
  ADD COLUMN password_changed_at DATETIME NULL AFTER must_change_password,
  ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER password_changed_at,
  ADD COLUMN created_by_user_id INT UNSIGNED NULL AFTER session_version,
  ADD CONSTRAINT fk_users_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE password_resets ADD INDEX idx_pr_user_open (user_id, used_at);

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
  ('session_absolute_hours', '8'),
  ('password_reset_minutes', '60');
