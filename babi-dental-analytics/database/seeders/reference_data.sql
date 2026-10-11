-- Reference data only. No users and no credentials: the first administrator is created by bin/create-admin.php (Phase 2).
INSERT INTO roles (code, name) VALUES
 ('admin','Administrator'),('receptionist','Receptionist'),('call_operator','Call Operator'),
 ('social_tracker','Social Media DM Tracker'),('marketing','Marketing Team');

INSERT INTO permissions (code, description) VALUES
 ('dashboard.admin','Administrative dashboard'),
 ('dashboard.marketing','Marketing dashboard (aggregate only)'),
 ('dashboard.entry','Own channel entry dashboard'),
 ('customers.search','Search customers'),
 ('customers.create','Register new customers'),
 ('customers.view_contact','View customer name/phone'),
 ('customers.manage','Edit/archive customer records'),
 ('customers.correct_source','Audited correction of original acquisition source'),
 ('interactions.create','Record interactions in the user''s own channel'),
 ('interactions.view_own','View/correct own recent interactions'),
 ('interactions.view_all','View all interactions'),
 ('interactions.manage','Edit any interaction'),
 ('appointments.manage','Create/update appointments'),
 ('users.manage','Manage users and roles'),
 ('sources.manage','Manage acquisition sources and platforms'),
 ('campaigns.manage','Manage campaigns'),
 ('services.manage','Manage services and reasons'),
 ('settings.manage','Application settings'),
 ('auditlogs.view','View audit logs'),
 ('analytics.full','Full analytics incl. data quality'),
 ('analytics.marketing','Aggregate marketing analytics'),
 ('exports.aggregate','Export aggregate reports'),
 ('exports.identifiable','Export identifiable records'),
 ('dataquality.review','Review data-quality issues');

-- Admin: everything
INSERT INTO role_permissions SELECT r.id, p.id FROM roles r, permissions p WHERE r.code='admin';

-- Three entry roles share the same base permissions; the channel is bound to the ROLE in code.
INSERT INTO role_permissions SELECT r.id, p.id FROM roles r JOIN permissions p
 ON p.code IN ('dashboard.entry','customers.search','customers.create','customers.view_contact',
               'interactions.create','interactions.view_own','appointments.manage')
 WHERE r.code IN ('receptionist','call_operator','social_tracker');

-- Marketing: aggregate analytics only, no contact details, no records
INSERT INTO role_permissions SELECT r.id, p.id FROM roles r JOIN permissions p
 ON p.code IN ('dashboard.marketing','analytics.marketing','exports.aggregate')
 WHERE r.code='marketing';

INSERT INTO social_platforms (name, sort_order) VALUES
 ('Instagram',10),('Facebook',20),('TikTok',30),('YouTube',40),('Telegram',50);

INSERT INTO acquisition_sources (name, category, requires_platform, allows_other_text, sort_order) VALUES
 ('Social media','social_media',1,0,10),
 ('Google Search','search_online',0,0,20),
 ('Google Maps','search_online',0,0,30),
 ('Clinic website','search_online',0,0,40),
 ('Online advertisement','search_online',0,0,50),
 ('Friend or family referral','offline_referral',0,0,60),
 ('Existing customer recommendation','offline_referral',0,0,70),
 ('Healthcare professional referral','offline_referral',0,0,80),
 ('Walk-in / physical discovery','offline_referral',0,0,90),
 ('Banner or poster','offline_referral',0,0,100),
 ('Other','other',0,1,900),
 ('Unknown / does not remember','unknown',0,0,999);

-- Placeholder services: replace with the clinic's real list in Settings.
INSERT INTO services (name) VALUES
 ('General check-up'),('Teeth cleaning'),('Filling'),('Root canal'),('Extraction'),
 ('Braces / orthodontics'),('Teeth whitening'),('Dental implants'),('Other');

INSERT INTO non_conversion_reasons (name, applies_to, allows_other_text, sort_order) VALUES
 ('Pricing','both',0,10),('No suitable service','both',0,20),('Undecided','booking',0,30),
 ('Postponed decision','both',0,40),('Other','both',1,900);

INSERT INTO settings (setting_key, setting_value) VALUES
 ('session_timeout_minutes','30'),('login_max_attempts','5'),('login_lockout_minutes','15'),
 ('entry_edit_window_hours','24'),('app_timezone','Africa/Addis_Ababa');
