-- =============================================================
-- SyncCU Database Setup
-- Run once on a fresh MySQL/MariaDB installation.
-- =============================================================

CREATE DATABASE IF NOT EXISTS synccu
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE synccu;

-- ── Users & auth ─────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS users (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username            VARCHAR(50)  NOT NULL UNIQUE,
    password            VARCHAR(255) NOT NULL,
    full_name           VARCHAR(100) NOT NULL,
    email               VARCHAR(150),
    role                ENUM('admin','manager','teller','user') NOT NULL DEFAULT 'user',
    is_active           TINYINT(1)   NOT NULL DEFAULT 1,
    must_change_password TINYINT(1)  NOT NULL DEFAULT 0,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login          DATETIME
) ENGINE=InnoDB;

-- ── Permissions & RBAC ───────────────────────────────────────

CREATE TABLE IF NOT EXISTS permissions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    permission_name VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS role_permissions (
    role            ENUM('admin','manager','teller','user') NOT NULL,
    permission_id   INT UNSIGNED NOT NULL,
    PRIMARY KEY (role, permission_id),
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Seed permissions ─────────────────────────────────────────

INSERT IGNORE INTO permissions (permission_name) VALUES
    ('transactions'),
    ('lending'),
    ('accounts'),
    ('automatic'),
    ('reports'),
    ('manager'),
    ('inquiry'),
    ('password'),
    ('ledger');

-- admin: all permissions
INSERT IGNORE INTO role_permissions (role, permission_id)
    SELECT 'admin', id FROM permissions;

-- manager: all except manager menu itself
INSERT IGNORE INTO role_permissions (role, permission_id)
    SELECT 'manager', id FROM permissions
    WHERE permission_name IN ('transactions','lending','accounts','automatic','reports','inquiry','password','ledger');

-- teller: day-to-day operations
INSERT IGNORE INTO role_permissions (role, permission_id)
    SELECT 'teller', id FROM permissions
    WHERE permission_name IN ('transactions','inquiry','password');

-- user: only change own password
INSERT IGNORE INTO role_permissions (role, permission_id)
    SELECT 'user', id FROM permissions
    WHERE permission_name = 'password';

-- ── Seed default admin user ───────────────────────────────────
-- Default password: Admin1234  (bcrypt hash, change immediately!)
INSERT IGNORE INTO users (username, password, full_name, role, must_change_password)
VALUES (
    'admin',
    '$2y$12$Q/V3qiTBfnXE4D6ZWt5oEOuNLrKFN1pqBMcVjJbAfL2.YzAqSFdpG',
    'System Administrator',
    'admin',
    1
);

-- ── Members ───────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS members (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_number   VARCHAR(20)  NOT NULL UNIQUE,
    member_type     ENUM('individual','company') NOT NULL DEFAULT 'individual',
    first_name      VARCHAR(60)  NOT NULL,
    last_name       VARCHAR(60)  NOT NULL,
    middle_name     VARCHAR(60),
    date_of_birth   DATE,
    ssn_last4       CHAR(4)      COMMENT 'Last 4 digits of SSN only',
    address         VARCHAR(200),
    city            VARCHAR(80),
    state           CHAR(2),
    zip             VARCHAR(10),
    phone           VARCHAR(20),
    email           VARCHAR(150),
    employer        VARCHAR(100),
    date_joined     DATE         NOT NULL DEFAULT (CURDATE()),
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    notes           TEXT,
    created_by      INT UNSIGNED,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ── Member accounts (savings, checking, share, loan) ──────────

CREATE TABLE IF NOT EXISTS member_accounts (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id       INT UNSIGNED NOT NULL,
    account_number  VARCHAR(20)  NOT NULL UNIQUE,
    account_type    ENUM('savings','checking','share','loan','certificate') NOT NULL,
    balance         DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    interest_rate   DECIMAL(6,4)  NOT NULL DEFAULT 0.0000,
    date_opened     DATE          NOT NULL DEFAULT (CURDATE()),
    date_closed     DATE,
    is_active       TINYINT(1)    NOT NULL DEFAULT 1,
    notes           TEXT,
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Loans ─────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS loans (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id           INT UNSIGNED NOT NULL,
    account_id          INT UNSIGNED NOT NULL,
    loan_type           VARCHAR(50)  NOT NULL,
    principal_amount    DECIMAL(14,2) NOT NULL,
    interest_rate       DECIMAL(6,4)  NOT NULL,
    term_months         INT          NOT NULL,
    monthly_payment     DECIMAL(14,2) NOT NULL,
    outstanding_balance DECIMAL(14,2) NOT NULL,
    date_issued         DATE         NOT NULL,
    date_due            DATE         NOT NULL,
    last_payment_date   DATE,
    is_active           TINYINT(1)   NOT NULL DEFAULT 1,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id)  REFERENCES members(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES member_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Transactions ──────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS transactions (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id       INT UNSIGNED NOT NULL,
    transaction_type ENUM('deposit','withdrawal','transfer_in','transfer_out','loan_payment','interest','fee','adjustment') NOT NULL,
    amount           DECIMAL(14,2) NOT NULL,
    balance_after    DECIMAL(14,2) NOT NULL,
    reference_number VARCHAR(20),
    description      VARCHAR(255),
    teller_id        INT UNSIGNED,
    transaction_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (account_id) REFERENCES member_accounts(id) ON DELETE CASCADE,
    FOREIGN KEY (teller_id)  REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_account_date (account_id, transaction_date),
    INDEX idx_reference (reference_number)
) ENGINE=InnoDB;

-- ── Automatic / periodic transactions ─────────────────────────

CREATE TABLE IF NOT EXISTS automatic_transactions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id       INT UNSIGNED NOT NULL,
    from_account_id INT UNSIGNED NOT NULL,
    to_account_id   INT UNSIGNED NOT NULL,
    amount          DECIMAL(14,2) NOT NULL,
    frequency       ENUM('weekly','biweekly','monthly','quarterly','annually') NOT NULL,
    next_run_date   DATE         NOT NULL,
    description     VARCHAR(255),
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id)       REFERENCES members(id) ON DELETE CASCADE,
    FOREIGN KEY (from_account_id) REFERENCES member_accounts(id) ON DELETE CASCADE,
    FOREIGN KEY (to_account_id)   REFERENCES member_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── General ledger ────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS ledger_entries (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entry_date      DATE         NOT NULL,
    account_code    VARCHAR(20)  NOT NULL,
    account_name    VARCHAR(100) NOT NULL,
    debit           DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    credit          DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    description     VARCHAR(255),
    reference       VARCHAR(50),
    created_by      INT UNSIGNED,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
