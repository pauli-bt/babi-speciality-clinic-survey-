-- Phase 4: interaction questionnaires. The Phase 1 schema already holds every interaction/appointment field,
-- so this migration only adds a business-rule setting (how far back an interaction may be dated).
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('interaction_max_backdate_days', '30');
