-- ISO 20022 compliance fields for transactions and network_transfers
-- Idempotent: checks for column existence before adding

DELIMITER //

DROP PROCEDURE IF EXISTS `_migrate_002_iso20022`//

CREATE PROCEDURE `_migrate_002_iso20022`()
BEGIN
  -- ─── Transactions table ─────────────────────────────────────────
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'transactions' AND column_name = 'end_to_end_id'
  ) THEN
    ALTER TABLE `transactions`
      ADD COLUMN `end_to_end_id` VARCHAR(35) DEFAULT NULL AFTER `reference_number`,
      ADD COLUMN `instruction_id` VARCHAR(35) DEFAULT NULL AFTER `end_to_end_id`,
      ADD COLUMN `purpose_code` VARCHAR(10) DEFAULT NULL AFTER `instruction_id`,
      ADD COLUMN `debtor_name` VARCHAR(140) DEFAULT NULL AFTER `purpose_code`,
      ADD COLUMN `debtor_account` VARCHAR(34) DEFAULT NULL AFTER `debtor_name`,
      ADD COLUMN `debtor_agent_bic` VARCHAR(11) DEFAULT NULL AFTER `debtor_account`,
      ADD COLUMN `creditor_name` VARCHAR(140) DEFAULT NULL AFTER `debtor_agent_bic`,
      ADD COLUMN `creditor_account` VARCHAR(34) DEFAULT NULL AFTER `creditor_name`,
      ADD COLUMN `creditor_agent_bic` VARCHAR(11) DEFAULT NULL AFTER `creditor_account`,
      ADD COLUMN `remittance_info` VARCHAR(140) DEFAULT NULL AFTER `creditor_agent_bic`,
      ADD COLUMN `charge_bearer` ENUM('DEBT','CRED','SHAR','SLEV') DEFAULT NULL AFTER `remittance_info`,
      ADD COLUMN `settlement_date` DATE DEFAULT NULL AFTER `charge_bearer`,
      ADD COLUMN `iso_status_code` VARCHAR(10) DEFAULT NULL AFTER `settlement_date`;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'transactions' AND index_name = 'idx_transactions_e2e'
  ) THEN
    ALTER TABLE `transactions` ADD INDEX `idx_transactions_e2e` (`end_to_end_id`);
  END IF;

  -- ─── Network transfers table ──────────────────────────────────────
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'network_transfers' AND column_name = 'end_to_end_id'
  ) THEN
    ALTER TABLE `network_transfers`
      ADD COLUMN `end_to_end_id` VARCHAR(35) DEFAULT NULL AFTER `remote_reference`,
      ADD COLUMN `instruction_id` VARCHAR(35) DEFAULT NULL AFTER `end_to_end_id`,
      ADD COLUMN `purpose_code` VARCHAR(10) DEFAULT NULL AFTER `instruction_id`,
      ADD COLUMN `charge_bearer` ENUM('DEBT','CRED','SHAR','SLEV') DEFAULT 'SHAR' AFTER `purpose_code`,
      ADD COLUMN `settlement_date` DATE DEFAULT NULL AFTER `charge_bearer`,
      ADD COLUMN `sender_agent_bic` VARCHAR(11) DEFAULT NULL AFTER `settlement_date`,
      ADD COLUMN `recipient_agent_bic` VARCHAR(11) DEFAULT NULL AFTER `sender_agent_bic`,
      ADD COLUMN `iso_status_code` VARCHAR(10) DEFAULT NULL AFTER `failure_reason`;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'network_transfers' AND index_name = 'idx_network_transfers_e2e'
  ) THEN
    ALTER TABLE `network_transfers` ADD INDEX `idx_network_transfers_e2e` (`end_to_end_id`);
  END IF;
END//

DELIMITER ;

CALL `_migrate_002_iso20022`();
DROP PROCEDURE IF EXISTS `_migrate_002_iso20022`;
