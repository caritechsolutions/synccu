-- Member portal: bill pay (saved payees + payments)
-- Idempotent: uses CREATE TABLE IF NOT EXISTS

-- Saved payees / billers, owned by a member (users.id, role=member)
CREATE TABLE IF NOT EXISTS `payees` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `tenant_id` CHAR(36) NOT NULL,
  `member_id` CHAR(36) NOT NULL,
  `name` VARCHAR(120) NOT NULL,
  `category` VARCHAR(50) DEFAULT NULL,
  `account_reference` VARCHAR(80) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`member_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_payees_member` (`tenant_id`, `member_id`, `is_active`)
) ENGINE=InnoDB;

-- Bill payments made through the portal (debit a member account -> biller)
CREATE TABLE IF NOT EXISTS `bill_payments` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `tenant_id` CHAR(36) NOT NULL,
  `member_id` CHAR(36) NOT NULL,
  `payee_id` CHAR(36) DEFAULT NULL,
  `from_account_id` CHAR(36) NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `reference_number` VARCHAR(50) DEFAULT NULL,
  `transaction_id` CHAR(36) DEFAULT NULL,
  `status` ENUM('completed','pending','failed') NOT NULL DEFAULT 'completed',
  `memo` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`member_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`payee_id`) REFERENCES `payees`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`from_account_id`) REFERENCES `accounts`(`id`) ON DELETE CASCADE,
  INDEX `idx_billpay_member` (`tenant_id`, `member_id`, `created_at`)
) ENGINE=InnoDB;
