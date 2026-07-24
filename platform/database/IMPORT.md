# Legacy REPORT.TXT import

Imports the old core-system member/account dump into the platform's `users`
(role=member) and `accounts` tables, preserving the `{member_number}-{suffix}`
account-numbering pattern.

> **Do not commit the data file.** `REPORT.TXT` contains member PII (names,
> national IDs, DOBs, balances). Keep it off the repo; copy it to the server
> only for the run.

## Mapping (as confirmed)

| Source | Target |
|---|---|
| Base number `1011` | `users.member_number`; also its own account `1011` |
| Row `1011-05` | account, `account_number = "1011-05"` |
| Suffix `05` | `account_type = permanent_shares` |
| Every other suffix (and the base row) | `account_type = regular_shares` |
| Balance | `accounts.balance` and `available_balance` |
| Name `LAST,FIRST` | `last_name` / `first_name` |
| National ID `YYMMDD-XXXX` | `users.national_id` (dash optional) |
| DOB `MM/DD/YYYY` | `users.date_of_birth` |

Every member is created with online banking **enabled**: a placeholder email
(`m<member_number>@import.local` when none exists), a **standard password**, and
`force_password_change = 1` (changed on first login).

## Steps

1. Apply migration `007_member_identity.sql` (adds `member_number`,
   `national_id`, `date_of_birth` to `users`). Your normal `update.sh` run
   applies it automatically.
2. Copy `REPORT.TXT` to the server (e.g. `/root/REPORT.TXT`).
3. **Dry run first** (writes nothing — prints counts + warnings):
   ```bash
   php platform/database/import_legacy_report.php /root/REPORT.TXT
   ```
4. When the counts look right, import for real:
   ```bash
   php platform/database/import_legacy_report.php /root/REPORT.TXT --commit
   ```

The importer is **idempotent** — re-running skips members/accounts that already
exist (matched by `member_number` / `account_number`), so a partial run is safe
to repeat.

## Wiping test data before a clean re-import

To empty all member/client data (keeping staff logins) and re-import from
scratch, use `reset_member_data.php` — it **defaults to a dry run** and only
deletes with `--confirm`:

```bash
# 0. back up first
mysqldump synccu | gzip > /root/synccu-before-reset.sql.gz

# 1. see exactly what would be removed (nothing is deleted)
php platform/database/reset_member_data.php

# 2. wipe member data (KEEPS super_admin/admin/manager/teller logins)
php platform/database/reset_member_data.php --confirm

# 3. re-import
php platform/database/import_legacy_report.php /root/REPORT.TXT --commit
```

It deletes, for the tenant: all accounts, transactions, loans, loan_schedules,
bill_payments, payees, applications, member_messages, documents, notifications,
member refresh_tokens, and all `role='member'` users. It refuses to run if no
staff login would remain.

## Options

| Flag | Default | Purpose |
|---|---|---|
| `--commit` | off (dry run) | actually write to the DB |
| `--tenant=UUID` | seeded default tenant | target tenant |
| `--password=SECRET` | `ChangeMe#2024` | standard initial password |
| `--currency=USD` | `USD` | account currency (set e.g. `BBD` if needed) |
| `--email-domain=host` | `import.local` | placeholder email domain |
| `--env=/path/.env` | `platform/backend/.env` | where to read `DB_*` creds |

## Expected result for this dump

376 members, 1,138 accounts (846 regular_shares, 292 permanent_shares).
Warnings (non-fatal): a few negative balances (overdrawn — imported as-is) and a
few rows whose national-ID column held a name/date instead of an ID (imported
with `national_id = NULL` for staff follow-up). Names are imported verbatim
(mixed case in the source is preserved).
