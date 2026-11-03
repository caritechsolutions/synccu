-- Member address fields on users (address lines, city, parish/state, postal
-- code). Installs created from an older schema never had these; the Register
-- of Members report and member profile need them. Idempotent.

DELIMITER //

DROP PROCEDURE IF EXISTS `_migrate_010_member_address`//

CREATE PROCEDURE `_migrate_010_member_address`()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
                   AND COLUMN_NAME = 'address_line_1') THEN
    ALTER TABLE `users` ADD COLUMN `address_line_1` VARCHAR(255) DEFAULT NULL AFTER `phone`;
  END IF;

  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
                   AND COLUMN_NAME = 'address_line_2') THEN
    ALTER TABLE `users` ADD COLUMN `address_line_2` VARCHAR(255) DEFAULT NULL AFTER `address_line_1`;
  END IF;

  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
                   AND COLUMN_NAME = 'city') THEN
    ALTER TABLE `users` ADD COLUMN `city` VARCHAR(100) DEFAULT NULL AFTER `address_line_2`;
  END IF;

  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
                   AND COLUMN_NAME = 'state') THEN
    ALTER TABLE `users` ADD COLUMN `state` VARCHAR(2) DEFAULT NULL AFTER `city`;
  END IF;

  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
                   AND COLUMN_NAME = 'zip_code') THEN
    ALTER TABLE `users` ADD COLUMN `zip_code` VARCHAR(10) DEFAULT NULL AFTER `state`;
  END IF;
END//

DELIMITER ;

CALL `_migrate_010_member_address`();
DROP PROCEDURE IF EXISTS `_migrate_010_member_address`;
