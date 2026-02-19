-- =============================================================
-- Migration 004: Loan Terms & Deposit Terms
-- Creates configurable term templates for loans and deposits.
-- =============================================================

USE synccu;

-- ── Loan Terms ──────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS loan_terms (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    loan_type       VARCHAR(50)  NOT NULL,
    interest_rate   DECIMAL(6,4) NOT NULL COMMENT 'Annual rate as decimal, e.g. 0.1200 = 12%',
    term_months     INT          NOT NULL,
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Link loans to their originating term (nullable for legacy loans)
ALTER TABLE loans
    ADD COLUMN loan_term_id INT UNSIGNED NULL AFTER id,
    ADD CONSTRAINT fk_loans_loan_term
        FOREIGN KEY (loan_term_id) REFERENCES loan_terms(id) ON DELETE SET NULL;

-- ── Deposit Terms ───────────────────────────────────────────

CREATE TABLE IF NOT EXISTS deposit_terms (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(100) NOT NULL,
    interest_rate   DECIMAL(6,4) NOT NULL COMMENT 'Annual rate as decimal, e.g. 0.0250 = 2.50%',
    payout_month    TINYINT      NOT NULL COMMENT 'Month of year (1-12)',
    payout_day      TINYINT      NOT NULL COMMENT 'Day of month (1-31)',
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Link deposit/savings accounts to their term (nullable for legacy accounts)
ALTER TABLE member_accounts
    ADD COLUMN deposit_term_id INT UNSIGNED NULL AFTER share_type_code,
    ADD CONSTRAINT fk_member_accounts_deposit_term
        FOREIGN KEY (deposit_term_id) REFERENCES deposit_terms(id) ON DELETE SET NULL;
