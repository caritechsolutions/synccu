-- ─────────────────────────────────────────────────────────────────────────────
-- Migration 001: Share Account Type Definitions
--
-- Concept:
--   Base accounts (e.g. 1007)       → account_type = 'savings'  (deposits / withdrawals)
--   Share sub-accounts (e.g. 1007-05) → account_type = 'share'   (deposits / withdrawals)
--   Loan sub-accounts  (e.g. 1007A)   → account_type = 'loan'
--
-- This migration:
--   1. Creates the share_account_types lookup table
--   2. Pre-populates codes 00–19 using known definitions from the legacy system
--   3. Adds share_type_code to member_accounts and migrates existing data
-- ─────────────────────────────────────────────────────────────────────────────

-- 1. Lookup table ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS share_account_types (
    code          CHAR(2)      NOT NULL,
    name          VARCHAR(100) DEFAULT NULL    COMMENT 'NULL = undefined / not yet assigned',
    special_class CHAR(1)      DEFAULT NULL    COMMENT 'I=Traditional IRA, E=Education IRA, C=Roth Contribution IRA, R=Roth Rollover IRA, U=Unavailable for withdrawal, NULL=No special class',
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (code)
) ENGINE=InnoDB;

-- 2. Pre-populate codes 00–19 ──────────────────────────────────────────────────
INSERT IGNORE INTO share_account_types (code, name, special_class) VALUES
('00', 'Ordinary Deposits',  NULL),
('01', 'Holding Account',    NULL),
('02', NULL,                 NULL),
('03', NULL,                 NULL),
('04', NULL,                 NULL),
('05', 'Qualifying Shares',  NULL),
('06', NULL,                 NULL),
('07', NULL,                 NULL),
('08', NULL,                 NULL),
('09', NULL,                 NULL),
('10', NULL,                 NULL),
('11', NULL,                 NULL),
('12', NULL,                 NULL),
('13', NULL,                 NULL),
('14', NULL,                 NULL),
('15', NULL,                 NULL),
('16', NULL,                 NULL),
('17', NULL,                 NULL),
('18', NULL,                 NULL),
('19', NULL,                 NULL);

-- 3. Add share_type_code to member_accounts ────────────────────────────────────
ALTER TABLE member_accounts
    ADD COLUMN share_type_code CHAR(2) DEFAULT NULL
        COMMENT 'Share sub-account type code — references share_account_types.code';

-- 4. Migrate existing share accounts: extract code stored in notes field ────────
--    import_accounts.php stored notes as e.g. "Share Code: 05"
--    SUBSTRING(notes, 13, 2) extracts the 2-char code after "Share Code: "
UPDATE member_accounts
SET    share_type_code = SUBSTRING(notes, 13, 2)
WHERE  account_type    = 'share'
  AND  notes LIKE      'Share Code: __'
  AND  share_type_code IS NULL;
