-- Phase 3: customer management (additive; existing customers, codes and interactions are untouched)

-- Idempotency key so a double-clicked / re-sent registration can never create two customers.
ALTER TABLE customers ADD COLUMN submission_token CHAR(32) NULL AFTER customer_code, ADD UNIQUE KEY uq_customers_submission_token (submission_token);

-- Atomic customer-code sequence (safe under concurrent registrations).
CREATE TABLE customer_code_counter (
  id TINYINT UNSIGNED PRIMARY KEY,
  next_value INT UNSIGNED NOT NULL
) ENGINE=InnoDB;
INSERT INTO customer_code_counter (id, next_value)
  SELECT 1, COALESCE(MAX(CAST(SUBSTRING(customer_code, 5) AS UNSIGNED)), 0) FROM customers WHERE customer_code REGEXP '^BDC-[0-9]+$';

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('phone_country_code', '251'), ('search_page_size', '10');
