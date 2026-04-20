-- SyncCU Core Banking Platform - Seed Data
-- Sample tenant, users, accounts, transactions, and chart of accounts
-- Password hash is bcrypt of 'password123' (cost 10)

USE `synccu`;

SET AUTOCOMMIT = 0;
START TRANSACTION;

-- ============================================================
-- Tenants
-- ============================================================
-- Default tenant already created in schema.sql
-- Add a second demo tenant
INSERT INTO `tenants` (`id`, `name`, `slug`, `domain`, `primary_color`, `secondary_color`, `status`, `settings`) VALUES
('00000000-0000-0000-0000-000000000002', 'Riverside Community CU', 'riverside', 'riverside.synccu.local', '#0d6efd', '#6610f2', 'active',
  '{"timezone": "America/Chicago", "currency": "USD", "overdraft_limit": 500.00, "minimum_savings_balance": 5.00}');

-- ============================================================
-- Users
-- ============================================================
-- bcrypt hash of 'password123': $2b$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy

-- Default tenant users
INSERT INTO `users` (`id`, `tenant_id`, `email`, `password_hash`, `first_name`, `last_name`, `phone`, `role`, `status`, `email_verified_at`, `password_changed_at`) VALUES
('10000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000001', 'admin@synccu.local', '$2b$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy', 'System', 'Administrator', '+15551000001', 'super_admin', 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('10000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000001', 'manager@synccu.local', '$2b$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy', 'Jane', 'Manager', '+15551000002', 'manager', 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('10000000-0000-0000-0000-000000000003', '00000000-0000-0000-0000-000000000001', 'teller@synccu.local', '$2b$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy', 'Tom', 'Teller', '+15551000003', 'teller', 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('10000000-0000-0000-0000-000000000010', '00000000-0000-0000-0000-000000000001', 'alice.johnson@example.com', '$2b$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy', 'Alice', 'Johnson', '+15552000001', 'member', 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('10000000-0000-0000-0000-000000000011', '00000000-0000-0000-0000-000000000001', 'bob.smith@example.com', '$2b$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy', 'Bob', 'Smith', '+15552000002', 'member', 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('10000000-0000-0000-0000-000000000012', '00000000-0000-0000-0000-000000000001', 'carol.davis@example.com', '$2b$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy', 'Carol', 'Davis', '+15552000003', 'member', 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);

-- Riverside tenant users
INSERT INTO `users` (`id`, `tenant_id`, `email`, `password_hash`, `first_name`, `last_name`, `phone`, `role`, `status`, `email_verified_at`, `password_changed_at`) VALUES
('20000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000002', 'admin@riverside.synccu.local', '$2b$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy', 'River', 'Admin', '+15553000001', 'admin', 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('20000000-0000-0000-0000-000000000010', '00000000-0000-0000-0000-000000000002', 'dave.wilson@example.com', '$2b$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy', 'Dave', 'Wilson', '+15553000002', 'member', 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);

-- ============================================================
-- Accounts
-- ============================================================

-- Alice Johnson's accounts (Default tenant)
INSERT INTO `accounts` (`id`, `tenant_id`, `user_id`, `account_number`, `account_type`, `name`, `balance`, `available_balance`, `currency`, `status`, `interest_rate`) VALUES
('a0000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000001', '10000000-0000-0000-0000-000000000010', '1000000001', 'savings', 'Primary Savings', 15250.75, 15250.75, 'USD', 'active', 0.0250),
('a0000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000001', '10000000-0000-0000-0000-000000000010', '1000000002', 'checking', 'Daily Checking', 3420.50, 3420.50, 'USD', 'active', 0.0010),
('a0000000-0000-0000-0000-000000000003', '00000000-0000-0000-0000-000000000001', '10000000-0000-0000-0000-000000000010', '1000000003', 'loan', 'Auto Loan - LN-2025-000001', 18500.00, 18500.00, 'USD', 'active', 0.0425);

-- Bob Smith's accounts (Default tenant)
INSERT INTO `accounts` (`id`, `tenant_id`, `user_id`, `account_number`, `account_type`, `name`, `balance`, `available_balance`, `currency`, `status`, `interest_rate`) VALUES
('a0000000-0000-0000-0000-000000000004', '00000000-0000-0000-0000-000000000001', '10000000-0000-0000-0000-000000000011', '1000000004', 'savings', 'Primary Savings', 8750.00, 8750.00, 'USD', 'active', 0.0250),
('a0000000-0000-0000-0000-000000000005', '00000000-0000-0000-0000-000000000001', '10000000-0000-0000-0000-000000000011', '1000000005', 'checking', 'Daily Checking', 1205.33, 1205.33, 'USD', 'active', 0.0010),
('a0000000-0000-0000-0000-000000000006', '00000000-0000-0000-0000-000000000001', '10000000-0000-0000-0000-000000000011', '1000000006', 'certificate', 'CD 12-Month', 10000.00, 0.00, 'USD', 'active', 0.0475);

-- Carol Davis's accounts (Default tenant)
INSERT INTO `accounts` (`id`, `tenant_id`, `user_id`, `account_number`, `account_type`, `name`, `balance`, `available_balance`, `currency`, `status`, `interest_rate`) VALUES
('a0000000-0000-0000-0000-000000000007', '00000000-0000-0000-0000-000000000001', '10000000-0000-0000-0000-000000000012', '1000000007', 'savings', 'Primary Savings', 42100.00, 42100.00, 'USD', 'active', 0.0250),
('a0000000-0000-0000-0000-000000000008', '00000000-0000-0000-0000-000000000001', '10000000-0000-0000-0000-000000000012', '1000000008', 'checking', 'Daily Checking', 5680.90, 5680.90, 'USD', 'active', 0.0010);

-- Dave Wilson's accounts (Riverside tenant)
INSERT INTO `accounts` (`id`, `tenant_id`, `user_id`, `account_number`, `account_type`, `name`, `balance`, `available_balance`, `currency`, `status`, `interest_rate`) VALUES
('a0000000-0000-0000-0000-000000000009', '00000000-0000-0000-0000-000000000002', '20000000-0000-0000-0000-000000000010', '2000000001', 'savings', 'Primary Savings', 6320.00, 6320.00, 'USD', 'active', 0.0300),
('a0000000-0000-0000-0000-000000000010', '00000000-0000-0000-0000-000000000002', '20000000-0000-0000-0000-000000000010', '2000000002', 'checking', 'Daily Checking', 2150.75, 2150.75, 'USD', 'active', 0.0015);

-- ============================================================
-- Sample Transactions (Default tenant - Alice's savings)
-- ============================================================

INSERT INTO `transactions` (`id`, `tenant_id`, `account_id`, `type`, `amount`, `balance_after`, `description`, `reference_number`, `status`, `processed_by`, `created_at`) VALUES
('t0000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000001', 'a0000000-0000-0000-0000-000000000001', 'deposit', 5000.00, 5000.00, 'Initial deposit', 'TXN-2026-000001', 'completed', '10000000-0000-0000-0000-000000000003', '2026-01-05 10:00:00'),
('t0000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000001', 'a0000000-0000-0000-0000-000000000001', 'deposit', 3200.00, 8200.00, 'Payroll direct deposit', 'TXN-2026-000002', 'completed', NULL, '2026-01-15 08:30:00'),
('t0000000-0000-0000-0000-000000000003', '00000000-0000-0000-0000-000000000001', 'a0000000-0000-0000-0000-000000000001', 'withdrawal', 500.00, 7700.00, 'ATM withdrawal', 'TXN-2026-000003', 'completed', NULL, '2026-01-20 14:15:00'),
('t0000000-0000-0000-0000-000000000004', '00000000-0000-0000-0000-000000000001', 'a0000000-0000-0000-0000-000000000001', 'deposit', 3200.00, 10900.00, 'Payroll direct deposit', 'TXN-2026-000004', 'completed', NULL, '2026-02-01 08:30:00'),
('t0000000-0000-0000-0000-000000000005', '00000000-0000-0000-0000-000000000001', 'a0000000-0000-0000-0000-000000000001', 'transfer', 1000.00, 9900.00, 'Transfer to checking', 'TXN-2026-000005', 'completed', NULL, '2026-02-10 11:00:00'),
('t0000000-0000-0000-0000-000000000006', '00000000-0000-0000-0000-000000000001', 'a0000000-0000-0000-0000-000000000001', 'deposit', 3200.00, 13100.00, 'Payroll direct deposit', 'TXN-2026-000006', 'completed', NULL, '2026-03-01 08:30:00'),
('t0000000-0000-0000-0000-000000000007', '00000000-0000-0000-0000-000000000001', 'a0000000-0000-0000-0000-000000000001', 'interest', 27.25, 13127.25, 'Quarterly interest credit', 'TXN-2026-000007', 'completed', NULL, '2026-03-15 00:00:00'),
('t0000000-0000-0000-0000-000000000008', '00000000-0000-0000-0000-000000000001', 'a0000000-0000-0000-0000-000000000001', 'deposit', 2123.50, 15250.75, 'Tax refund deposit', 'TXN-2026-000008', 'completed', NULL, '2026-03-22 09:45:00');

-- Alice's checking transactions
INSERT INTO `transactions` (`id`, `tenant_id`, `account_id`, `type`, `amount`, `balance_after`, `description`, `reference_number`, `related_account_id`, `status`, `created_at`) VALUES
('t0000000-0000-0000-0000-000000000009', '00000000-0000-0000-0000-000000000001', 'a0000000-0000-0000-0000-000000000002', 'transfer', 1000.00, 1000.00, 'Transfer from savings', 'TXN-2026-000009', 'a0000000-0000-0000-0000-000000000001', 'completed', '2026-02-10 11:00:00'),
('t0000000-0000-0000-0000-000000000010', '00000000-0000-0000-0000-000000000001', 'a0000000-0000-0000-0000-000000000002', 'deposit', 3200.00, 4200.00, 'Payroll direct deposit', 'TXN-2026-000010', NULL, 'completed', '2026-03-01 08:30:00'),
('t0000000-0000-0000-0000-000000000011', '00000000-0000-0000-0000-000000000001', 'a0000000-0000-0000-0000-000000000002', 'payment', 779.50, 3420.50, 'Utility payment - Electric Co', 'TXN-2026-000011', NULL, 'completed', '2026-03-05 16:20:00');

-- ============================================================
-- Loans
-- ============================================================

INSERT INTO `loans` (`id`, `tenant_id`, `user_id`, `account_id`, `loan_number`, `loan_type`, `principal_amount`, `interest_rate`, `term_months`, `monthly_payment`, `outstanding_balance`, `disbursed_amount`, `total_paid`, `status`, `approved_by`, `approved_at`, `disbursed_at`, `next_payment_date`, `maturity_date`) VALUES
('l0000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000001', '10000000-0000-0000-0000-000000000010', 'a0000000-0000-0000-0000-000000000003', 'LN-2025-000001', 'auto', 25000.00, 0.0425, 60, 463.58, 18500.00, 25000.00, 6500.00, 'active', '10000000-0000-0000-0000-000000000002', '2025-06-15 10:00:00', '2025-06-20 14:00:00', '2026-04-20', '2030-06-20'),
('l0000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000001', '10000000-0000-0000-0000-000000000012', NULL, 'LN-2026-000001', 'personal', 5000.00, 0.0850, 24, 226.14, 5000.00, 0.00, 0.00, 'application', NULL, NULL, NULL, NULL, NULL);

-- ============================================================
-- Loan Schedules (first 3 payments for Alice's auto loan)
-- ============================================================

INSERT INTO `loan_schedules` (`id`, `loan_id`, `payment_number`, `due_date`, `principal_amount`, `interest_amount`, `total_amount`, `paid_amount`, `status`, `paid_at`) VALUES
('ls000000-0000-0000-0000-000000000001', 'l0000000-0000-0000-0000-000000000001', 1, '2025-07-20', 375.08, 88.50, 463.58, 463.58, 'paid', '2025-07-18 10:00:00'),
('ls000000-0000-0000-0000-000000000002', 'l0000000-0000-0000-0000-000000000001', 2, '2025-08-20', 376.41, 87.17, 463.58, 463.58, 'paid', '2025-08-19 10:00:00'),
('ls000000-0000-0000-0000-000000000003', 'l0000000-0000-0000-0000-000000000001', 3, '2025-09-20', 377.74, 85.84, 463.58, 463.58, 'paid', '2025-09-20 10:00:00'),
('ls000000-0000-0000-0000-000000000004', 'l0000000-0000-0000-0000-000000000001', 4, '2025-10-20', 379.08, 84.50, 463.58, 463.58, 'paid', '2025-10-17 10:00:00'),
('ls000000-0000-0000-0000-000000000005', 'l0000000-0000-0000-0000-000000000001', 5, '2025-11-20', 380.43, 83.15, 463.58, 463.58, 'paid', '2025-11-20 10:00:00'),
('ls000000-0000-0000-0000-000000000006', 'l0000000-0000-0000-0000-000000000001', 6, '2025-12-20', 381.78, 81.80, 463.58, 463.58, 'paid', '2025-12-19 10:00:00'),
('ls000000-0000-0000-0000-000000000007', 'l0000000-0000-0000-0000-000000000001', 7, '2026-01-20', 383.13, 80.45, 463.58, 463.58, 'paid', '2026-01-18 10:00:00'),
('ls000000-0000-0000-0000-000000000008', 'l0000000-0000-0000-0000-000000000001', 8, '2026-02-20', 384.49, 79.09, 463.58, 463.58, 'paid', '2026-02-20 10:00:00'),
('ls000000-0000-0000-0000-000000000009', 'l0000000-0000-0000-0000-000000000001', 9, '2026-03-20', 385.85, 77.73, 463.58, 463.58, 'paid', '2026-03-20 10:00:00'),
('ls000000-0000-0000-0000-000000000010', 'l0000000-0000-0000-0000-000000000001', 10, '2026-04-20', 387.22, 76.36, 463.58, 0.00, 'upcoming', NULL);

-- ============================================================
-- Double-Entry Ledger Entries (sample for initial deposit)
-- ============================================================

INSERT INTO `ledger_entries` (`id`, `tenant_id`, `transaction_id`, `account_id`, `entry_type`, `amount`, `balance_after`, `gl_code`, `description`, `created_at`) VALUES
-- Initial deposit: debit cash, credit member savings
('le000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000001', 't0000000-0000-0000-0000-000000000001', 'a0000000-0000-0000-0000-000000000001', 'debit', 5000.00, 5000.00, '1010', 'Cash received - initial deposit', '2026-01-05 10:00:00'),
('le000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000001', 't0000000-0000-0000-0000-000000000001', 'a0000000-0000-0000-0000-000000000001', 'credit', 5000.00, 5000.00, '2010', 'Member savings - initial deposit', '2026-01-05 10:00:00'),
-- Interest credit: debit interest expense, credit member savings
('le000000-0000-0000-0000-000000000003', '00000000-0000-0000-0000-000000000001', 't0000000-0000-0000-0000-000000000007', 'a0000000-0000-0000-0000-000000000001', 'debit', 27.25, 27.25, '5010', 'Interest expense - quarterly', '2026-03-15 00:00:00'),
('le000000-0000-0000-0000-000000000004', '00000000-0000-0000-0000-000000000001', 't0000000-0000-0000-0000-000000000007', 'a0000000-0000-0000-0000-000000000001', 'credit', 27.25, 13127.25, '2010', 'Interest credit - quarterly', '2026-03-15 00:00:00'),
-- Transfer: debit savings, credit checking
('le000000-0000-0000-0000-000000000005', '00000000-0000-0000-0000-000000000001', 't0000000-0000-0000-0000-000000000005', 'a0000000-0000-0000-0000-000000000001', 'debit', 1000.00, 9900.00, '2010', 'Internal transfer out - savings', '2026-02-10 11:00:00'),
('le000000-0000-0000-0000-000000000006', '00000000-0000-0000-0000-000000000001', 't0000000-0000-0000-0000-000000000009', 'a0000000-0000-0000-0000-000000000002', 'credit', 1000.00, 1000.00, '2020', 'Internal transfer in - checking', '2026-02-10 11:00:00');

-- ============================================================
-- Chart of Accounts (GL Codes)
-- Stored as a reference table for the ledger
-- ============================================================

CREATE TABLE IF NOT EXISTS `chart_of_accounts` (
  `gl_code` VARCHAR(20) NOT NULL PRIMARY KEY,
  `tenant_id` CHAR(36) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `category` ENUM('asset','liability','equity','revenue','expense') NOT NULL,
  `subcategory` VARCHAR(100) DEFAULT NULL,
  `parent_gl_code` VARCHAR(20) DEFAULT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `description` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
  INDEX `idx_coa_tenant` (`tenant_id`),
  INDEX `idx_coa_category` (`category`)
) ENGINE=InnoDB;

-- Default tenant Chart of Accounts
INSERT INTO `chart_of_accounts` (`gl_code`, `tenant_id`, `name`, `category`, `subcategory`, `parent_gl_code`, `description`) VALUES
-- ASSETS (1000-1999)
('1000', '00000000-0000-0000-0000-000000000001', 'Total Assets', 'asset', 'header', NULL, 'Top-level asset category'),
('1010', '00000000-0000-0000-0000-000000000001', 'Cash on Hand', 'asset', 'cash', '1000', 'Physical cash in vaults and teller drawers'),
('1020', '00000000-0000-0000-0000-000000000001', 'Cash at Banks', 'asset', 'cash', '1000', 'Deposits held at correspondent banks'),
('1030', '00000000-0000-0000-0000-000000000001', 'Federal Reserve Account', 'asset', 'cash', '1000', 'Balances at Federal Reserve'),
('1100', '00000000-0000-0000-0000-000000000001', 'Investments - Short Term', 'asset', 'investments', '1000', 'Investments maturing within one year'),
('1110', '00000000-0000-0000-0000-000000000001', 'Investments - Long Term', 'asset', 'investments', '1000', 'Investments maturing beyond one year'),
('1200', '00000000-0000-0000-0000-000000000001', 'Member Loans - Consumer', 'asset', 'loans', '1000', 'Personal and auto loans to members'),
('1210', '00000000-0000-0000-0000-000000000001', 'Member Loans - Mortgage', 'asset', 'loans', '1000', 'Real estate secured loans'),
('1220', '00000000-0000-0000-0000-000000000001', 'Member Loans - Business', 'asset', 'loans', '1000', 'Business and commercial loans'),
('1230', '00000000-0000-0000-0000-000000000001', 'Member Loans - Credit Lines', 'asset', 'loans', '1000', 'Revolving credit facilities'),
('1300', '00000000-0000-0000-0000-000000000001', 'Allowance for Loan Losses', 'asset', 'contra', '1000', 'Reserve for potential loan defaults'),
('1400', '00000000-0000-0000-0000-000000000001', 'Accrued Interest Receivable', 'asset', 'receivables', '1000', 'Interest earned but not yet collected'),
('1500', '00000000-0000-0000-0000-000000000001', 'Fixed Assets', 'asset', 'fixed', '1000', 'Property, equipment, and furniture'),
('1510', '00000000-0000-0000-0000-000000000001', 'Accumulated Depreciation', 'asset', 'contra', '1000', 'Depreciation of fixed assets'),
('1600', '00000000-0000-0000-0000-000000000001', 'Other Assets', 'asset', 'other', '1000', 'Miscellaneous assets'),

-- LIABILITIES (2000-2999)
('2000', '00000000-0000-0000-0000-000000000001', 'Total Liabilities', 'liability', 'header', NULL, 'Top-level liability category'),
('2010', '00000000-0000-0000-0000-000000000001', 'Member Savings Deposits', 'liability', 'deposits', '2000', 'Regular share/savings accounts'),
('2020', '00000000-0000-0000-0000-000000000001', 'Member Checking Deposits', 'liability', 'deposits', '2000', 'Share draft/checking accounts'),
('2030', '00000000-0000-0000-0000-000000000001', 'Member Certificates', 'liability', 'deposits', '2000', 'Certificates of deposit / share certificates'),
('2040', '00000000-0000-0000-0000-000000000001', 'Money Market Deposits', 'liability', 'deposits', '2000', 'Money market account balances'),
('2100', '00000000-0000-0000-0000-000000000001', 'Accrued Dividends Payable', 'liability', 'accrued', '2000', 'Dividends declared but not yet paid'),
('2200', '00000000-0000-0000-0000-000000000001', 'Borrowed Funds', 'liability', 'borrowings', '2000', 'Funds borrowed from FHLB or other sources'),
('2300', '00000000-0000-0000-0000-000000000001', 'Accounts Payable', 'liability', 'payables', '2000', 'Amounts owed to vendors and suppliers'),
('2400', '00000000-0000-0000-0000-000000000001', 'Other Liabilities', 'liability', 'other', '2000', 'Miscellaneous liabilities'),

-- EQUITY (3000-3999)
('3000', '00000000-0000-0000-0000-000000000001', 'Total Equity', 'equity', 'header', NULL, 'Top-level equity category'),
('3010', '00000000-0000-0000-0000-000000000001', 'Regular Reserves', 'equity', 'reserves', '3000', 'Regulatory required reserves'),
('3020', '00000000-0000-0000-0000-000000000001', 'Undivided Earnings', 'equity', 'retained', '3000', 'Accumulated net income not distributed'),
('3030', '00000000-0000-0000-0000-000000000001', 'Other Comprehensive Income', 'equity', 'other', '3000', 'Unrealized gains/losses on investments'),

-- REVENUE (4000-4999)
('4000', '00000000-0000-0000-0000-000000000001', 'Total Revenue', 'revenue', 'header', NULL, 'Top-level revenue category'),
('4010', '00000000-0000-0000-0000-000000000001', 'Interest on Loans', 'revenue', 'interest', '4000', 'Interest earned on member loans'),
('4020', '00000000-0000-0000-0000-000000000001', 'Interest on Investments', 'revenue', 'interest', '4000', 'Interest earned on investments'),
('4030', '00000000-0000-0000-0000-000000000001', 'Fee Income', 'revenue', 'fees', '4000', 'Service charges, NSF fees, late fees, etc.'),
('4040', '00000000-0000-0000-0000-000000000001', 'Other Non-Interest Income', 'revenue', 'other', '4000', 'Interchange, insurance commissions, etc.'),

-- EXPENSES (5000-5999)
('5000', '00000000-0000-0000-0000-000000000001', 'Total Expenses', 'expense', 'header', NULL, 'Top-level expense category'),
('5010', '00000000-0000-0000-0000-000000000001', 'Dividend Expense - Savings', 'expense', 'interest', '5000', 'Dividends paid on savings accounts'),
('5020', '00000000-0000-0000-0000-000000000001', 'Dividend Expense - Checking', 'expense', 'interest', '5000', 'Dividends paid on checking accounts'),
('5030', '00000000-0000-0000-0000-000000000001', 'Dividend Expense - Certificates', 'expense', 'interest', '5000', 'Dividends paid on share certificates'),
('5040', '00000000-0000-0000-0000-000000000001', 'Interest on Borrowed Funds', 'expense', 'interest', '5000', 'Interest paid on borrowings'),
('5100', '00000000-0000-0000-0000-000000000001', 'Provision for Loan Losses', 'expense', 'provision', '5000', 'Additions to loan loss allowance'),
('5200', '00000000-0000-0000-0000-000000000001', 'Compensation and Benefits', 'expense', 'operating', '5000', 'Salaries, wages, and employee benefits'),
('5300', '00000000-0000-0000-0000-000000000001', 'Office Occupancy', 'expense', 'operating', '5000', 'Rent, utilities, maintenance'),
('5400', '00000000-0000-0000-0000-000000000001', 'Office Operations', 'expense', 'operating', '5000', 'Supplies, postage, telecommunications'),
('5500', '00000000-0000-0000-0000-000000000001', 'Professional Services', 'expense', 'operating', '5000', 'Legal, audit, consulting fees'),
('5600', '00000000-0000-0000-0000-000000000001', 'Technology Expense', 'expense', 'operating', '5000', 'Core system, software, hardware'),
('5700', '00000000-0000-0000-0000-000000000001', 'Other Operating Expenses', 'expense', 'operating', '5000', 'Insurance, marketing, miscellaneous');

-- ============================================================
-- Sample Audit Log Entries
-- ============================================================

INSERT INTO `audit_logs` (`id`, `tenant_id`, `user_id`, `action`, `entity_type`, `entity_id`, `new_values`, `ip_address`, `created_at`) VALUES
('al000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000001', '10000000-0000-0000-0000-000000000001', 'user.login', 'user', '10000000-0000-0000-0000-000000000001', '{"method": "password"}', '192.168.1.100', '2026-03-27 08:00:00'),
('al000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000001', '10000000-0000-0000-0000-000000000002', 'loan.approve', 'loan', 'l0000000-0000-0000-0000-000000000002', '{"loan_number": "LN-2026-000001", "amount": 5000.00}', '192.168.1.101', '2026-03-25 11:30:00'),
('al000000-0000-0000-0000-000000000003', '00000000-0000-0000-0000-000000000001', '10000000-0000-0000-0000-000000000010', 'account.transfer', 'transaction', 't0000000-0000-0000-0000-000000000005', '{"from": "1000000001", "to": "1000000002", "amount": 1000.00}', '10.0.0.50', '2026-02-10 11:00:00');

-- ============================================================
-- Sample Notifications
-- ============================================================

INSERT INTO `notifications` (`id`, `tenant_id`, `user_id`, `type`, `title`, `message`, `metadata`, `created_at`) VALUES
('n0000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000001', '10000000-0000-0000-0000-000000000010', 'transaction', 'Deposit Received', 'Your tax refund deposit of $2,123.50 has been credited to your Primary Savings account.', '{"transaction_id": "t0000000-0000-0000-0000-000000000008", "amount": 2123.50}', '2026-03-22 09:45:00'),
('n0000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000001', '10000000-0000-0000-0000-000000000010', 'loan', 'Payment Reminder', 'Your auto loan payment of $463.58 is due on April 20, 2026.', '{"loan_id": "l0000000-0000-0000-0000-000000000001", "due_date": "2026-04-20"}', '2026-03-27 08:00:00'),
('n0000000-0000-0000-0000-000000000003', '00000000-0000-0000-0000-000000000001', '10000000-0000-0000-0000-000000000012', 'loan', 'Loan Approved', 'Your personal loan application for $5,000.00 has been approved. Please visit the branch to complete disbursement.', '{"loan_id": "l0000000-0000-0000-0000-000000000002"}', '2026-03-25 11:35:00');

COMMIT;
