-- SyncCU Core Banking Platform - Database Schema
-- Multi-tenant architecture with tenant_id isolation

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

CREATE DATABASE IF NOT EXISTS `synccu` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `synccu`;

-- Tenants table
CREATE TABLE IF NOT EXISTS `tenants` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `slug` VARCHAR(100) NOT NULL UNIQUE,
  `domain` VARCHAR(255) DEFAULT NULL,
  `logo_url` VARCHAR(500) DEFAULT NULL,
  `primary_color` VARCHAR(7) DEFAULT '#1a56db',
  `secondary_color` VARCHAR(7) DEFAULT '#7c3aed',
  `settings` JSON DEFAULT NULL,
  `status` ENUM('active','suspended','pending') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Users table with RBAC
CREATE TABLE IF NOT EXISTS `users` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `tenant_id` CHAR(36) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `phone` VARCHAR(20) DEFAULT NULL,
  `role` ENUM('super_admin','admin','manager','teller','member') NOT NULL DEFAULT 'member',
  `status` ENUM('active','inactive','locked','pending_verification') DEFAULT 'pending_verification',
  `email_verified_at` TIMESTAMP NULL DEFAULT NULL,
  `two_factor_enabled` TINYINT(1) DEFAULT 0,
  `two_factor_secret` VARCHAR(255) DEFAULT NULL,
  `failed_login_attempts` INT DEFAULT 0,
  `locked_until` TIMESTAMP NULL DEFAULT NULL,
  `password_changed_at` TIMESTAMP NULL DEFAULT NULL,
  `force_password_change` TINYINT(1) DEFAULT 0,
  `last_login_at` TIMESTAMP NULL DEFAULT NULL,
  `last_login_ip` VARCHAR(45) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_tenant_email` (`tenant_id`, `email`),
  FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
  INDEX `idx_users_tenant` (`tenant_id`),
  INDEX `idx_users_role` (`role`),
  INDEX `idx_users_status` (`status`)
) ENGINE=InnoDB;

-- Refresh tokens
CREATE TABLE IF NOT EXISTS `refresh_tokens` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `user_id` CHAR(36) NOT NULL,
  `token_hash` VARCHAR(255) NOT NULL UNIQUE,
  `expires_at` TIMESTAMP NOT NULL,
  `revoked` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_refresh_tokens_user` (`user_id`)
) ENGINE=InnoDB;

-- Accounts (savings, checking, loan)
CREATE TABLE IF NOT EXISTS `accounts` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `tenant_id` CHAR(36) NOT NULL,
  `user_id` CHAR(36) NOT NULL,
  `account_number` VARCHAR(20) NOT NULL,
  `account_type` ENUM('savings','checking','loan','certificate') NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `balance` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `available_balance` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `currency` CHAR(3) DEFAULT 'USD',
  `status` ENUM('active','frozen','closed','dormant') DEFAULT 'active',
  `interest_rate` DECIMAL(5,4) DEFAULT 0.0000,
  `opened_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `closed_at` TIMESTAMP NULL DEFAULT NULL,
  `metadata` JSON DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_tenant_account` (`tenant_id`, `account_number`),
  FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_accounts_tenant` (`tenant_id`),
  INDEX `idx_accounts_user` (`user_id`),
  INDEX `idx_accounts_type` (`account_type`),
  INDEX `idx_accounts_status` (`status`)
) ENGINE=InnoDB;

-- Transactions
CREATE TABLE IF NOT EXISTS `transactions` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `tenant_id` CHAR(36) NOT NULL,
  `account_id` CHAR(36) DEFAULT NULL,
  `type` ENUM('deposit','withdrawal','transfer','payment','fee','late_fee','interest','adjustment','loan_disbursement','loan_payment','expense') NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `balance_after` DECIMAL(15,2) DEFAULT NULL,
  `description` VARCHAR(500) DEFAULT NULL,
  `reference_number` VARCHAR(50) NOT NULL,
  `related_transaction_id` CHAR(36) DEFAULT NULL,
  `related_account_id` CHAR(36) DEFAULT NULL,
  `status` ENUM('pending','completed','failed','reversed','cancelled') DEFAULT 'pending',
  `processed_by` CHAR(36) DEFAULT NULL,
  `metadata` JSON DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`account_id`) REFERENCES `accounts`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`processed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_transactions_tenant` (`tenant_id`),
  INDEX `idx_transactions_account` (`account_id`),
  INDEX `idx_transactions_type` (`type`),
  INDEX `idx_transactions_status` (`status`),
  INDEX `idx_transactions_reference` (`reference_number`),
  INDEX `idx_transactions_created` (`created_at`)
) ENGINE=InnoDB;

-- Loans
CREATE TABLE IF NOT EXISTS `loans` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `tenant_id` CHAR(36) NOT NULL,
  `user_id` CHAR(36) NOT NULL,
  `account_id` CHAR(36) DEFAULT NULL,
  `loan_number` VARCHAR(20) NOT NULL,
  `loan_type` ENUM('personal','auto','mortgage','business','education','credit_line') NOT NULL,
  `principal_amount` DECIMAL(15,2) NOT NULL,
  `interest_rate` DECIMAL(5,4) NOT NULL,
  `term_months` INT NOT NULL,
  `monthly_payment` DECIMAL(15,2) NOT NULL,
  `outstanding_balance` DECIMAL(15,2) NOT NULL,
  `disbursed_amount` DECIMAL(15,2) DEFAULT 0.00,
  `total_paid` DECIMAL(15,2) DEFAULT 0.00,
  `status` ENUM('application','approved','active','delinquent','default','paid_off','cancelled') DEFAULT 'application',
  `approved_by` CHAR(36) DEFAULT NULL,
  `approved_at` TIMESTAMP NULL DEFAULT NULL,
  `disbursed_at` TIMESTAMP NULL DEFAULT NULL,
  `next_payment_date` DATE DEFAULT NULL,
  `maturity_date` DATE DEFAULT NULL,
  `collateral` JSON DEFAULT NULL,
  `metadata` JSON DEFAULT NULL,
  `grace_period_days` INT DEFAULT 15,
  `late_fee_rate` DECIMAL(5,2) DEFAULT 5.00,
  `purpose` TEXT DEFAULT NULL,
  `denial_reason` TEXT DEFAULT NULL,
  `co_signer_id` CHAR(36) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_tenant_loan` (`tenant_id`, `loan_number`),
  FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`account_id`) REFERENCES `accounts`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_loans_tenant` (`tenant_id`),
  INDEX `idx_loans_user` (`user_id`),
  INDEX `idx_loans_status` (`status`)
) ENGINE=InnoDB;

-- Loan payment schedule
CREATE TABLE IF NOT EXISTS `loan_schedules` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `loan_id` CHAR(36) NOT NULL,
  `payment_number` INT NOT NULL,
  `due_date` DATE NOT NULL,
  `principal_amount` DECIMAL(15,2) NOT NULL,
  `interest_amount` DECIMAL(15,2) NOT NULL,
  `total_amount` DECIMAL(15,2) NOT NULL,
  `paid_amount` DECIMAL(15,2) DEFAULT 0.00,
  `late_fee` DECIMAL(15,2) DEFAULT 0.00,
  `status` ENUM('upcoming','due','paid','overdue','partial') DEFAULT 'upcoming',
  `paid_at` TIMESTAMP NULL DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`loan_id`) REFERENCES `loans`(`id`) ON DELETE CASCADE,
  INDEX `idx_schedule_loan` (`loan_id`),
  INDEX `idx_schedule_due_date` (`due_date`),
  INDEX `idx_schedule_status` (`status`)
) ENGINE=InnoDB;

-- Audit logs
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `tenant_id` CHAR(36) DEFAULT NULL,
  `user_id` CHAR(36) DEFAULT NULL,
  `action` VARCHAR(100) NOT NULL,
  `entity_type` VARCHAR(50) NOT NULL,
  `entity_id` CHAR(36) DEFAULT NULL,
  `old_values` JSON DEFAULT NULL,
  `new_values` JSON DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(500) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE SET NULL,
  INDEX `idx_audit_tenant` (`tenant_id`),
  INDEX `idx_audit_user` (`user_id`),
  INDEX `idx_audit_action` (`action`),
  INDEX `idx_audit_entity` (`entity_type`, `entity_id`),
  INDEX `idx_audit_created` (`created_at`)
) ENGINE=InnoDB;

-- API keys
CREATE TABLE IF NOT EXISTS `api_keys` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `tenant_id` CHAR(36) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `key_hash` VARCHAR(255) NOT NULL UNIQUE,
  `key_prefix` VARCHAR(10) NOT NULL,
  `permissions` JSON DEFAULT NULL,
  `rate_limit` INT DEFAULT 1000,
  `last_used_at` TIMESTAMP NULL DEFAULT NULL,
  `expires_at` TIMESTAMP NULL DEFAULT NULL,
  `revoked` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
  INDEX `idx_api_keys_tenant` (`tenant_id`),
  INDEX `idx_api_keys_prefix` (`key_prefix`)
) ENGINE=InnoDB;

-- Notifications
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `tenant_id` CHAR(36) NOT NULL,
  `user_id` CHAR(36) NOT NULL,
  `type` VARCHAR(50) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `message` TEXT NOT NULL,
  `read_at` TIMESTAMP NULL DEFAULT NULL,
  `metadata` JSON DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_notifications_user` (`user_id`),
  INDEX `idx_notifications_read` (`read_at`)
) ENGINE=InnoDB;

-- Tenant Settings (key-value per tenant)
CREATE TABLE IF NOT EXISTS `tenant_settings` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `tenant_id` CHAR(36) NOT NULL,
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_value` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `unique_tenant_setting` (`tenant_id`, `setting_key`)
) ENGINE=InnoDB;

-- Documents / Attachments
CREATE TABLE IF NOT EXISTS `documents` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `tenant_id` CHAR(36) NOT NULL,
  `uploaded_by` CHAR(36) DEFAULT NULL,
  `entity_type` VARCHAR(50) NOT NULL COMMENT 'transaction, loan, member, account',
  `entity_id` CHAR(36) NOT NULL,
  `category` VARCHAR(50) DEFAULT NULL COMMENT 'sof, id_verification, proof_of_address, etc',
  `original_name` VARCHAR(255) NOT NULL,
  `mime_type` VARCHAR(100) NOT NULL,
  `file_size` INT UNSIGNED NOT NULL,
  `file_data` LONGBLOB NOT NULL COMMENT 'Raw file content stored in database',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`uploaded_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_documents_entity` (`entity_type`, `entity_id`),
  INDEX `idx_documents_tenant` (`tenant_id`)
) ENGINE=InnoDB;

-- Network nodes (peer CU registry)
CREATE TABLE IF NOT EXISTS `network_nodes` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `tenant_id` CHAR(36) NOT NULL,
  `node_code` VARCHAR(20) NOT NULL COMMENT 'Short unique code e.g. SYNCU-001',
  `name` VARCHAR(255) NOT NULL,
  `endpoint_url` VARCHAR(512) NOT NULL COMMENT 'Base URL of peer instance API',
  `api_key_hash` VARCHAR(255) NOT NULL COMMENT 'Hashed shared secret for auth',
  `api_key_prefix` VARCHAR(10) NOT NULL COMMENT 'First 8 chars for identification',
  `status` ENUM('active','inactive','pending','unreachable') DEFAULT 'pending',
  `is_router` TINYINT(1) DEFAULT 0 COMMENT 'Whether this peer acts as a router',
  `last_heartbeat_at` TIMESTAMP NULL DEFAULT NULL,
  `metadata` JSON DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_tenant_node_code` (`tenant_id`, `node_code`),
  FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
  INDEX `idx_network_nodes_tenant` (`tenant_id`),
  INDEX `idx_network_nodes_status` (`status`)
) ENGINE=InnoDB;

-- Network transfers (inter-CU transfers)
CREATE TABLE IF NOT EXISTS `network_transfers` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `tenant_id` CHAR(36) NOT NULL,
  `direction` ENUM('outbound','inbound') NOT NULL,
  `peer_node_id` CHAR(36) DEFAULT NULL COMMENT 'References network_nodes.id',
  `peer_node_code` VARCHAR(20) NOT NULL,
  `local_account_id` CHAR(36) DEFAULT NULL,
  `local_transaction_id` CHAR(36) DEFAULT NULL,
  `remote_reference` VARCHAR(50) DEFAULT NULL COMMENT 'Reference number on the peer side',
  `sender_name` VARCHAR(255) NOT NULL,
  `sender_account` VARCHAR(50) NOT NULL,
  `sender_institution` VARCHAR(255) NOT NULL,
  `recipient_name` VARCHAR(255) NOT NULL,
  `recipient_account` VARCHAR(50) NOT NULL,
  `recipient_institution` VARCHAR(255) NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `fee` DECIMAL(15,2) DEFAULT 0.00,
  `currency` CHAR(3) DEFAULT 'USD',
  `status` ENUM('pending','sent','received','confirmed','failed','reversed','settled') DEFAULT 'pending',
  `settled_in_batch` CHAR(36) DEFAULT NULL COMMENT 'References network_settlements.id',
  `failure_reason` VARCHAR(500) DEFAULT NULL,
  `metadata` JSON DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`local_account_id`) REFERENCES `accounts`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`local_transaction_id`) REFERENCES `transactions`(`id`) ON DELETE SET NULL,
  INDEX `idx_network_transfers_tenant` (`tenant_id`),
  INDEX `idx_network_transfers_peer` (`peer_node_code`),
  INDEX `idx_network_transfers_status` (`status`),
  INDEX `idx_network_transfers_settled` (`settled_in_batch`),
  INDEX `idx_network_transfers_created` (`created_at`)
) ENGINE=InnoDB;

-- Network settlements (month-end reconciliation batches)
CREATE TABLE IF NOT EXISTS `network_settlements` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `tenant_id` CHAR(36) NOT NULL,
  `peer_node_id` CHAR(36) DEFAULT NULL,
  `peer_node_code` VARCHAR(20) NOT NULL,
  `period_start` DATE NOT NULL,
  `period_end` DATE NOT NULL,
  `total_outbound` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `total_inbound` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `net_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'Positive = we owe peer, negative = peer owes us',
  `transfer_count` INT NOT NULL DEFAULT 0,
  `status` ENUM('draft','pending_approval','approved','invoiced','paid','disputed') DEFAULT 'draft',
  `approved_by` CHAR(36) DEFAULT NULL,
  `approved_at` TIMESTAMP NULL DEFAULT NULL,
  `notes` TEXT DEFAULT NULL,
  `metadata` JSON DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_network_settlements_tenant` (`tenant_id`),
  INDEX `idx_network_settlements_peer` (`peer_node_code`),
  INDEX `idx_network_settlements_period` (`period_start`, `period_end`),
  INDEX `idx_network_settlements_status` (`status`)
) ENGINE=InnoDB;

-- Seed default tenant and super admin
INSERT IGNORE INTO `tenants` (`id`, `name`, `slug`, `status`) VALUES
('00000000-0000-0000-0000-000000000001', 'Default Credit Union', 'default', 'active');

COMMIT;
