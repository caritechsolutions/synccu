-- Member portal: secure messaging between members and staff, and member-submitted applications
-- Idempotent: uses CREATE TABLE IF NOT EXISTS

-- Member <-> staff messaging
CREATE TABLE IF NOT EXISTS `member_messages` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `tenant_id` CHAR(36) NOT NULL,
  `member_id` CHAR(36) NOT NULL,
  `sender` ENUM('member','staff') NOT NULL,
  `staff_user_id` CHAR(36) DEFAULT NULL,
  `subject` VARCHAR(150) NOT NULL,
  `body` TEXT NOT NULL,
  `is_read_by_member` TINYINT(1) NOT NULL DEFAULT 0,
  `is_read_by_staff` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`member_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`staff_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_mm_tenant` (`tenant_id`),
  INDEX `idx_mm_member` (`member_id`, `created_at`),
  INDEX `idx_mm_staff_unread` (`tenant_id`, `is_read_by_staff`)
) ENGINE=InnoDB;

-- Member-submitted applications (loan / service)
CREATE TABLE IF NOT EXISTS `applications` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `tenant_id` CHAR(36) NOT NULL,
  `member_id` CHAR(36) NOT NULL,
  `app_type` ENUM('loan','service') NOT NULL,
  `product` VARCHAR(120) NOT NULL,
  `amount` DECIMAL(15,2) DEFAULT NULL,
  `term_months` INT DEFAULT NULL,
  `details` TEXT DEFAULT NULL,
  `status` ENUM('pending','in_review','approved','declined','cancelled') NOT NULL DEFAULT 'pending',
  `reviewed_by` CHAR(36) DEFAULT NULL,
  `reviewed_at` TIMESTAMP NULL DEFAULT NULL,
  `staff_notes` VARCHAR(500) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`member_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`reviewed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_app_status` (`tenant_id`, `status`, `created_at`)
) ENGINE=InnoDB;
