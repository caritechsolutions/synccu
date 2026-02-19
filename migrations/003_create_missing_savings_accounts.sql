-- Create a base savings account for every member that doesn't already have one.
--
-- The savings account number equals the member number directly
-- (e.g. member 2405 → savings account 2405).
--
-- Safe to re-run: INSERT IGNORE skips any that already exist.

INSERT IGNORE INTO member_accounts (member_id, account_number, account_type)
SELECT id, member_number, 'savings'
FROM   members;
