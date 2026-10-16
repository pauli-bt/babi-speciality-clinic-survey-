-- Load with the mysql CLI (uses DELIMITER, which PDO cannot parse):
--   mysql -u root -p babi_dental < database/triggers.sql
DELIMITER $$

-- Original acquisition fields are write-once. The only way to change them is the audited
-- correction service, which sets @babi_allow_source_correction = 1 inside its transaction.
CREATE TRIGGER trg_customers_protect_acquisition
BEFORE UPDATE ON customers FOR EACH ROW
BEGIN
  IF COALESCE(@babi_allow_source_correction, 0) <> 1 AND (
       NOT (OLD.original_source_id <=> NEW.original_source_id)
    OR NOT (OLD.original_social_platform_id <=> NEW.original_social_platform_id)
    OR NOT (OLD.original_source_other <=> NEW.original_source_other)
    OR NOT (OLD.original_campaign_id <=> NEW.original_campaign_id)
    OR NOT (OLD.first_acquisition_channel <=> NEW.first_acquisition_channel)
    OR NOT (OLD.first_acquisition_at <=> NEW.first_acquisition_at)
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Original acquisition data is write-once; use the audited correction process';
  END IF;
END$$

-- The channel and customer of an interaction can never be rewritten (prevents report manipulation).
CREATE TRIGGER trg_interactions_immutable
BEFORE UPDATE ON interactions FOR EACH ROW
BEGIN
  IF NOT (OLD.channel <=> NEW.channel)
     OR NOT (OLD.customer_id <=> NEW.customer_id)
     OR NOT (OLD.recorded_by_user_id <=> NEW.recorded_by_user_id)
     OR NOT (OLD.is_first_contact <=> NEW.is_first_contact) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Interaction channel, customer, recorder and first-contact flag are immutable';
  END IF;
END$$

DELIMITER ;
