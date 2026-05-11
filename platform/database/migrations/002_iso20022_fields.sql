-- ISO 20022 compliance fields for transactions and network_transfers
-- Run this on existing databases to add structured party and traceability columns

-- ─── Transactions table ─────────────────────────────────────────────
ALTER TABLE `transactions`
  ADD COLUMN `end_to_end_id` VARCHAR(35) DEFAULT NULL COMMENT 'ISO 20022 EndToEndId for cross-system traceability' AFTER `reference_number`,
  ADD COLUMN `instruction_id` VARCHAR(35) DEFAULT NULL COMMENT 'ISO 20022 InstructionId assigned by initiating party' AFTER `end_to_end_id`,
  ADD COLUMN `purpose_code` VARCHAR(10) DEFAULT NULL COMMENT 'ISO 20022 purpose code e.g. SALA, LOAN, CASH, TRAD' AFTER `instruction_id`,
  ADD COLUMN `debtor_name` VARCHAR(140) DEFAULT NULL COMMENT 'ISO 20022 structured debtor name' AFTER `purpose_code`,
  ADD COLUMN `debtor_account` VARCHAR(34) DEFAULT NULL COMMENT 'ISO 20022 debtor IBAN or account identifier' AFTER `debtor_name`,
  ADD COLUMN `debtor_agent_bic` VARCHAR(11) DEFAULT NULL COMMENT 'ISO 20022 debtor agent BIC/SWIFT code' AFTER `debtor_account`,
  ADD COLUMN `creditor_name` VARCHAR(140) DEFAULT NULL COMMENT 'ISO 20022 structured creditor name' AFTER `debtor_agent_bic`,
  ADD COLUMN `creditor_account` VARCHAR(34) DEFAULT NULL COMMENT 'ISO 20022 creditor IBAN or account identifier' AFTER `creditor_name`,
  ADD COLUMN `creditor_agent_bic` VARCHAR(11) DEFAULT NULL COMMENT 'ISO 20022 creditor agent BIC/SWIFT code' AFTER `creditor_account`,
  ADD COLUMN `remittance_info` VARCHAR(140) DEFAULT NULL COMMENT 'ISO 20022 unstructured remittance information' AFTER `creditor_agent_bic`,
  ADD COLUMN `charge_bearer` ENUM('DEBT','CRED','SHAR','SLEV') DEFAULT NULL COMMENT 'ISO 20022 charge bearer type' AFTER `remittance_info`,
  ADD COLUMN `settlement_date` DATE DEFAULT NULL COMMENT 'ISO 20022 settlement/value date' AFTER `charge_bearer`,
  ADD COLUMN `iso_status_code` VARCHAR(10) DEFAULT NULL COMMENT 'ISO 20022 status reason code e.g. AC01, AM04, RJCT' AFTER `settlement_date`,
  ADD INDEX `idx_transactions_e2e` (`end_to_end_id`);

-- ─── Network transfers table ────────────────────────────────────────
ALTER TABLE `network_transfers`
  ADD COLUMN `end_to_end_id` VARCHAR(35) DEFAULT NULL COMMENT 'ISO 20022 EndToEndId' AFTER `remote_reference`,
  ADD COLUMN `instruction_id` VARCHAR(35) DEFAULT NULL COMMENT 'ISO 20022 InstructionId' AFTER `end_to_end_id`,
  ADD COLUMN `purpose_code` VARCHAR(10) DEFAULT NULL COMMENT 'ISO 20022 purpose code' AFTER `instruction_id`,
  ADD COLUMN `charge_bearer` ENUM('DEBT','CRED','SHAR','SLEV') DEFAULT 'SHAR' COMMENT 'ISO 20022 charge bearer' AFTER `purpose_code`,
  ADD COLUMN `settlement_date` DATE DEFAULT NULL COMMENT 'ISO 20022 settlement date' AFTER `charge_bearer`,
  ADD COLUMN `sender_agent_bic` VARCHAR(11) DEFAULT NULL COMMENT 'Sender institution BIC/SWIFT' AFTER `settlement_date`,
  ADD COLUMN `recipient_agent_bic` VARCHAR(11) DEFAULT NULL COMMENT 'Recipient institution BIC/SWIFT' AFTER `sender_agent_bic`,
  ADD COLUMN `iso_status_code` VARCHAR(10) DEFAULT NULL COMMENT 'ISO 20022 status reason code' AFTER `failure_reason`,
  ADD INDEX `idx_network_transfers_e2e` (`end_to_end_id`);
