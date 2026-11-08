-- BillGuard simplified registration migration (run ONCE after backing up billguard_dev)
-- DO NOT re-import schema.sql. This ALTER keeps existing users and bills intact.
-- Current ID remains the internal primary key; simplified registration generates it server-side.

USE billguard_dev;

ALTER TABLE users
    ADD COLUMN home_address VARCHAR(180) NULL AFTER birthdate,
    MODIFY COLUMN birthdate DATE NULL,
    MODIFY COLUMN street VARCHAR(50) NULL,
    MODIFY COLUMN barangay VARCHAR(50) NULL,
    MODIFY COLUMN city VARCHAR(50) NULL,
    MODIFY COLUMN province VARCHAR(50) NULL,
    MODIFY COLUMN country VARCHAR(50) NULL,
    MODIFY COLUMN zip_code CHAR(4) NULL,
    MODIFY COLUMN utility_account_ciphertext VARBINARY(512) NULL,
    MODIFY COLUMN utility_account_iv VARBINARY(16) NULL,
    MODIFY COLUMN utility_account_tag VARBINARY(16) NULL,
    MODIFY COLUMN primary_utility_type ENUM('Electricity', 'Water', 'Internet') NULL,
    MODIFY COLUMN service_start_date DATE NULL,
    MODIFY COLUMN monthly_utility_budget DECIMAL(12,2) NULL;

-- Preserve a readable consolidated address for previously registered users.
UPDATE users
SET home_address = NULLIF(CONCAT_WS(', ', street, barangay, city, province, country, zip_code), '')
WHERE home_address IS NULL;
