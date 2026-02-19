-- Fix savings account numbers created by the web UI's add.php.
--
-- Previously add.php stored the savings account number as 'SAV-{member_number}'
-- (e.g. 'SAV-2025001234'). The correct convention — matching legacy imports and
-- the fundamental rule that a member's base account number IS their member number —
-- is to store the account number as just the member_number (e.g. '2025001234').
--
-- This migration strips the 'SAV-' prefix from any savings accounts that were
-- created with it. Safe to re-run (UPDATE affects 0 rows when already correct).

UPDATE member_accounts ma
JOIN   members m ON m.id = ma.member_id
SET    ma.account_number = m.member_number
WHERE  ma.account_type   = 'savings'
  AND  ma.account_number = CONCAT('SAV-', m.member_number);
