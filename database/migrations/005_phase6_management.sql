-- Phase 6: service, campaign and appointment management. Additive only: no table or data changes.
-- Marketing may VIEW campaigns (names, dates, status, aggregate counts; no customer data). Only administrators manage them.
INSERT IGNORE INTO permissions (code, description) VALUES ('campaigns.view', 'View campaigns (no customer data)');
INSERT IGNORE INTO role_permissions (role_id, permission_id)
  SELECT r.id, p.id FROM roles r JOIN permissions p ON p.code = 'campaigns.view' WHERE r.code IN ('admin', 'marketing');
