-- Special-purpose tag for regular_shares accounts (e.g. "Christmas Savings",
-- "Birthday Plan"). A member can have several regular_shares accounts, each
-- with its own purpose. Idempotent.

DELIMITER //

DROP PROCEDURE IF EXISTS `_migrate_008_account_purpose`//

CREATE PROCEDURE `_migrate_008_account_purpose`()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'accounts'
                   AND COLUMN_NAME = 'purpose') THEN
    ALTER TABLE `accounts` ADD COLUMN `purpose` VARCHAR(100) DEFAULT NULL AFTER `name`;
  END IF;
END//

DELIMITER ;

CALL `_migrate_008_account_purpose`();
DROP PROCEDURE IF EXISTS `_migrate_008_account_purpose`;
