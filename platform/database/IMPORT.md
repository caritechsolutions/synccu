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

## Importing loans (TEMP01.DAT)

Run **after** the member/shares import (loans attach to members by member
number). Each row becomes a `loans` record **and** a linked loan account
(`account_type=loan`, numbered with the legacy loan number e.g. `1011A`);
balance/outstanding = the file's current balance; status `active` (balance > 0)
or `paid_off` ($0). The loan type code A–O maps to a platform category plus a
human description stored in `loans.purpose` (see the `loan_types` table, seeded
by migration 009). A loan whose member is not in `users` is skipped (e.g. 1347,
whose account was closed when the loan was paid off).

```bash
php platform/database/import_legacy_loans.php /root/TEMP01.DAT            # dry run
php platform/database/import_legacy_loans.php /root/TEMP01.DAT --commit   # import
```

Idempotent (skips loans whose `loan_number` already exists). Expected for this
file: 99 parsed, 98 imported (1347 skipped), 30 active / 68 paid-off.

Pass `--as-of=YYYY-MM-DD` (default: today) for the date the file's balances are
current to. Interest on imported loans accrues from that date, and the
amortisation schedule is generated from it the first time the loan is viewed or
paid. Without it, the platform would count interest from the original loan date.

## Loading a single loan's full ledger (payment history)

`import_loan_ledger.php` takes the legacy "Loan Ledger" print-out for one
loan, resets the loan to its note terms, posts every payment at its date and
replays the history through the platform's interest engine (balance × APR ÷
365 × days since the previous payment, interest first). It prints a row-by-row
comparison against the ledger's own interest/principal/balance columns and only
writes with `--commit`.

```bash
# ledger file: one line per printed row (date first, then the numbers)
php platform/database/import_loan_ledger.php --member=1011 --loan=1011A --ledger=/root/1011A.txt \
    --principal=25995.99 --rate=12 --term=96 --payment=425.68 \
    --loan-date=2024-08-08 --first-payment=2024-09-30 --type=A \
    --refinanced-balance=20435.99 --refinanced-last-payment=2024-07-30 \
    --pledged=8578.68 --pledged-account=1011            # dry run
php platform/database/import_loan_ledger.php ... --commit
```

When the note refinanced an older one, the legacy system carries the interest
accrued on the old balance (from its last payment to the loan date) into the
first payment; `--refinanced-balance` / `--refinanced-last-payment` reproduce
that (or set `--opening-interest` directly). Payments already posted in this
system for the loan are replaced; the script refuses if any were paid from a
member account, since those moved money.

## Loading the accountant's Loan Register & Transaction History (all loans)

`import_loan_register.php` reads the "LOAN REGISTER & TRANSACTION HISTORY"
report (as text) and loads every loan with a balance owing: the latest note's
terms from the header line, and every payment from the journal **as posted**
(the register's own principal/interest split and balance), so the platform
matches the accountant's position to the cent. Reversals are netted out,
same-day principal/interest rows are merged, the schedule is rebuilt, and each
loan is its own transaction. Dry run by default.

```bash
apt-get install -y poppler-utils                       # once, for pdftotext
mkdir -p /root/register && cd /root/register           # copy the PDFs here (scp)
for f in *.pdf; do pdftotext -layout "$f" "${f%.pdf}.txt"; done
cat *.txt > all.txt
cd /var/www/synccu
php platform/database/import_loan_register.php --text=/root/register/all.txt            # dry run
php platform/database/import_loan_register.php --text=/root/register/all.txt --commit
```

Options: `--only=1212B,1263A`, `--skip=...`, `--include-paid-off` (also load the
closed loans' history), `--parse-only` (no database). Members must already
exist (`users.member_number`); loans are created when missing. The "Formula"
column says whether the platform's daily-interest formula would have produced
the same figures; loans marked "diff" carry manual allocations from the legacy
system and are loaded exactly as the register shows them.

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
