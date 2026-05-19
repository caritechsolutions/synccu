-- Account products, share account types, earning fields, and period low balance tracking
-- Idempotent: uses stored procedure with IF NOT EXISTS checks

DELIMITER //

DROP PROCEDURE IF EXISTS `_migrate_004_account_products`//

CREATE PROCEDURE `_migrate_004_account_products`()
BEGIN
  -- 1. Modify accounts.account_type ENUM to add share types and remove certificate
  --    First migrate any certificate accounts to savings
  UPDATE `accounts` SET `account_type` = 'savings' WHERE `account_type` = 'certificate';

  ALTER TABLE `accounts` MODIFY COLUMN `account_type`
    ENUM('savings','checking','permanent_shares','regular_shares','loan','certificate') NOT NULL;
  ALTER TABLE `accounts` MODIFY COLUMN `account_type`
    ENUM('savings','checking','permanent_shares','regular_shares','loan') NOT NULL;

  -- 2. Add new columns to accounts
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'accounts' AND column_name = 'account_product_id'
  ) THEN
    ALTER TABLE `accounts`
      ADD COLUMN `account_product_id` CHAR(36) DEFAULT NULL AFTER `account_type`,
      ADD COLUMN `earning_type` ENUM('interest','dividend','none') DEFAULT 'none' AFTER `interest_rate`,
      ADD COLUMN `earning_rate` DECIMAL(5,2) DEFAULT 0.00 AFTER `earning_type`,
      ADD COLUMN `earning_interval` ENUM('yearly','bi_annually','quarterly') DEFAULT NULL AFTER `earning_rate`,
      ADD INDEX `idx_accounts_product` (`account_product_id`);
  END IF;

  -- 3. Backfill earning fields for existing savings/checking accounts
  UPDATE `accounts` SET
    `earning_type` = 'interest',
    `earning_rate` = CASE WHEN `interest_rate` > 0 THEN `interest_rate` * 100 ELSE 0 END,
    `earning_interval` = 'yearly'
  WHERE `account_type` IN ('savings', 'checking')
    AND `earning_type` = 'none'
    AND `interest_rate` > 0;

  -- 4. Create account_products table
  CREATE TABLE IF NOT EXISTS `account_products` (
    `id` CHAR(36) NOT NULL PRIMARY KEY,
    `tenant_id` CHAR(36) NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `code` VARCHAR(20) NOT NULL,
    `category` ENUM('savings','checking','permanent_shares','regular_shares') NOT NULL,
    `earning_type` ENUM('interest','dividend') NOT NULL,
    `earning_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `earning_interval` ENUM('yearly','bi_annually','quarterly') NOT NULL DEFAULT 'yearly',
    `min_opening_balance` DECIMAL(15,2) DEFAULT 0.00,
    `description` TEXT DEFAULT NULL,
    `expires_at` DATETIME DEFAULT NULL,
    `status` ENUM('active','inactive') DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_product_code` (`tenant_id`, `code`),
    FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
    INDEX `idx_ap_tenant` (`tenant_id`),
    INDEX `idx_ap_category` (`tenant_id`, `category`),
    INDEX `idx_ap_status` (`tenant_id`, `status`)
  ) ENGINE=InnoDB;

  -- 5. Create period_low_balances table
  CREATE TABLE IF NOT EXISTS `period_low_balances` (
    `id` CHAR(36) NOT NULL PRIMARY KEY,
    `tenant_id` CHAR(36) NOT NULL,
    `account_id` CHAR(36) NOT NULL,
    `year` SMALLINT NOT NULL,
    `month` TINYINT NOT NULL,
    `lowest_balance` DECIMAL(15,2) NOT NULL,
    `lowest_balance_date` DATE NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_period` (`tenant_id`, `account_id`, `year`, `month`),
    FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`account_id`) REFERENCES `accounts`(`id`) ON DELETE CASCADE,
    INDEX `idx_plb_account` (`account_id`),
    INDEX `idx_plb_period` (`tenant_id`, `year`, `month`)
  ) ENGINE=InnoDB;
END//

DELIMITER ;

CALL `_migrate_004_account_products`();
DROP PROCEDURE IF EXISTS `_migrate_004_account_products`;
