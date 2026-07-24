-- Member identity fields on users: member_number (base account number),
-- national_id, and date_of_birth. Supports the legacy import and the
-- {member_number}-{suffix} account numbering scheme.
-- Idempotent: guarded by information_schema checks.

DELIMITER //

DROP PROCEDURE IF EXISTS `_migrate_007_member_identity`//

CREATE PROCEDURE `_migrate_007_member_identity`()
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
                   AND COLUMN_NAME = 'member_number') THEN
    ALTER TABLE `users` ADD COLUMN `member_number` VARCHAR(20) DEFAULT NULL AFTER `id`;
    ALTER TABLE `users` ADD UNIQUE KEY `unique_tenant_member_number` (`tenant_id`, `member_number`);
  END IF;

  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
                   AND COLUMN_NAME = 'national_id') THEN
    ALTER TABLE `users` ADD COLUMN `national_id` VARCHAR(30) DEFAULT NULL AFTER `last_name`;
  END IF;

  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
                   AND COLUMN_NAME = 'date_of_birth') THEN
    ALTER TABLE `users` ADD COLUMN `date_of_birth` DATE DEFAULT NULL AFTER `national_id`;
  END IF;
END//

DELIMITER ;

CALL `_migrate_007_member_identity`();
DROP PROCEDURE IF EXISTS `_migrate_007_member_identity`;
