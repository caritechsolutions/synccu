-- Loan type reference table (mirrors the old core system's editable
-- "Loan Type Description" codes A-O). Each loan stores the human description in
-- loans.purpose and a platform category in loans.loan_type; this table lets
-- staff manage the code->description list and drives new-loan pickers.
-- Idempotent.

CREATE TABLE IF NOT EXISTS `loan_types` (
  `id` CHAR(36) NOT NULL PRIMARY KEY,
  `tenant_id` CHAR(36) NOT NULL,
  `code` VARCHAR(2) NOT NULL,
  `description` VARCHAR(100) NOT NULL,
  `category` ENUM('personal','auto','mortgage','business','education','credit_line') NOT NULL DEFAULT 'personal',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `unique_tenant_loan_code` (`tenant_id`, `code`),
  FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Seed the A-O codes for the default tenant.
INSERT IGNORE INTO `loan_types` (`id`, `tenant_id`, `code`, `description`, `category`) VALUES
(UUID(), '00000000-0000-0000-0000-000000000001', 'A', 'Home Improvement',   'personal'),
(UUID(), '00000000-0000-0000-0000-000000000001', 'B', 'Car Purchase',       'auto'),
(UUID(), '00000000-0000-0000-0000-000000000001', 'C', 'Debt Consolidation', 'personal'),
(UUID(), '00000000-0000-0000-0000-000000000001', 'D', 'Small Business',     'business'),
(UUID(), '00000000-0000-0000-0000-000000000001', 'E', 'Car Repairs',        'personal'),
(UUID(), '00000000-0000-0000-0000-000000000001', 'F', 'Travel',             'personal'),
(UUID(), '00000000-0000-0000-0000-000000000001', 'G', 'Home Furnishing',    'personal'),
(UUID(), '00000000-0000-0000-0000-000000000001', 'H', 'Miscellaneous',      'personal'),
(UUID(), '00000000-0000-0000-0000-000000000001', 'I', 'Insurance',          'personal'),
(UUID(), '00000000-0000-0000-0000-000000000001', 'J', 'Land Purchase',      'mortgage'),
(UUID(), '00000000-0000-0000-0000-000000000001', 'K', 'Educational',        'education'),
(UUID(), '00000000-0000-0000-0000-000000000001', 'L', 'Medical',            'personal'),
(UUID(), '00000000-0000-0000-0000-000000000001', 'M', 'Xmas Loan',          'personal'),
(UUID(), '00000000-0000-0000-0000-000000000001', 'N', 'Back to School',     'education'),
(UUID(), '00000000-0000-0000-0000-000000000001', 'O', 'Other',              'personal');
