-- BillGuard Phase 4: add a customizable Other utility category.
-- For EXISTING local billguard_dev databases only. Back up the database first.
-- Run this statement ONCE. Do not re-import schema.sql or delete existing bills.
USE billguard_dev;
ALTER TABLE utility_bills
    MODIFY COLUMN utility_type ENUM('Electricity','Water','Internet','Other') NOT NULL,
    ADD COLUMN custom_utility_name VARCHAR(80) NULL AFTER utility_type;
-- Existing bills/payments/owners remain unchanged.
