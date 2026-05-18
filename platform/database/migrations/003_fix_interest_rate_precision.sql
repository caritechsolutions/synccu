-- Fix interest_rate column to allow whole-number percentages (e.g. 12.00 for 12%)
-- DECIMAL(5,4) only allows up to 9.9999; DECIMAL(5,2) allows up to 999.99

ALTER TABLE `accounts` MODIFY COLUMN `interest_rate` DECIMAL(5,2) DEFAULT 0.00;
ALTER TABLE `loans` MODIFY COLUMN `interest_rate` DECIMAL(5,2) NOT NULL;
