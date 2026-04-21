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
INSERT IGNORE INTO `tenants` (`id`, `name`, `slug`, `domain`, `primary_color`, `secondary_color`, `status`, `settings`) VALUES
('00000000-0000-0000-0000-000000000002', 'Riverside Community CU', 'riverside', 'riverside.synccu.local', '#0d6efd', '#6610f2', 'active',
  '{"timezone": "America/Chicago", "currency": "USD", "overdraft_limit": 500.00, "minimum_savings_balance": 5.00}');

-- ============================================================
-- Users
-- ============================================================
-- bcrypt hash of 'password123': $2b$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy

-- Production admin (Password!10)
INSERT IGNORE INTO `users` (`id`, `tenant_id`, `email`, `password_hash`, `first_name`, `last_name`, `phone`, `role`, `status`, `email_verified_at`, `password_changed_at`) VALUES
('10000000-0000-0000-0000-000000000099', '00000000-0000-0000-0000-000000000001', 'rrawlins@caritech.net', '$2b$12$FFeNqC2TTzD5tubCOisn6u.2bkXWQjgYggb2odjt8Gos7KHzBwo2a', 'Rawle', 'Rawlins', NULL, 'super_admin', 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);

-- Default tenant users
INSERT IGNORE INTO `users` (`id`, `tenant_id`, `email`, `password_hash`, `first_name`, `last_name`, `phone`, `role`, `status`, `email_verified_at`, `password_changed_at`) VALUES
('10000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000001', 'admin@synccu.local', '$2b$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy', 'System', 'Administrator', '+15551000001', 'super_admin', 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('10000000-0000-0000-0000-000000000002', '00000000-0000-0000-0000-000000000001', 'manager@synccu.local', '$2b$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy', 'Jane', 'Manager', '+15551000002', 'manager', 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('10000000-0000-0000-0000-000000000003', '00000000-0000-0000-0000-000000000001', 'teller@synccu.local', '$2b$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy', 'Tom', 'Teller', '+15551000003', 'teller', 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('10000000-0000-0000-0000-000000000010', '00000000-0000-0000-0000-000000000001', 'alice.johnson@example.com', '$2b$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy', 'Alice', 'Johnson', '+15552000001', 'member', 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('10000000-0000-0000-0000-000000000011', '00000000-0000-0000-0000-000000000001', 'bob.smith@example.com', '$2b$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy', 'Bob', 'Smith', '+15552000002', 'member', 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
('10000000-0000-0000-0000-000000000012', '00000000-0000-0000-0000-000000000001', 'carol.davis@example.com', '$2b$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LJZdL17lhWy', 'Carol', 'Davis', '+15552000003', 'member', 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP);

-- Riverside tenant users
INSERT IGNORE INTO `users` (`id`, `tenant_id`, `email`, `password_hash`, `first_name`, `last_name`, `phone`, `role`, `status`, `email_verified_at`, `password_changed_at`) VALUES
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
