# SaccoPro — SACCO Management System

A premium, multi-branch SACCO management system built with **PHP 8 + MySQL/MariaDB**.
Green & navy design, dark mode with neon-glow cards, and every form opens as a pop-up.

## Features

| Area | What you get |
|---|---|
| **Members** | 4-step registration wizard (KYC, contacts, next of kin, membership), auto member numbers, status lifecycle (active → dormant → suspended → exited), profile page with savings, loans, transactions, guarantees and a printable statement |
| **Savings** | Multiple savings products (ordinary, holiday, fixed/locked), deposits, withdrawals with minimum balance and fees, freeze/close accounts, printable receipts |
| **Share capital** | Share register, buy shares (cash or from savings), member-to-member transfers at book value |
| **Loans** | Product catalogue (flat or reducing balance, monthly/annual rates), live quote and schedule preview, savings-multiplier eligibility, guarantor validation, approve → disburse (cash/bank/mobile/to savings) → repay (cash or savings), arrears ageing, write-offs |
| **Penalties** | Nightly job marks overdue installments and charges a one-time late penalty after a grace period |
| **Ledger** | Every money movement is an immutable `transactions` row. Corrections are reversals, never edits |
| **Expenses** | Categorised operating costs posted to the ledger, editable and voidable with automatic reversal |
| **Reports** | Income statement, cash flow by type, portfolio at risk (PAR) ageing, product performance, top savers, membership; CSV export |
| **Administration** | Branches, users and roles, settings, full audit trail |
| **Accounting** | SACCO chart of accounts, automatic double-entry journal for every transaction, manual and opening-balance journals, account statements, trial balance, income statement, balance sheet, and a reconciliation check against member balances |
| **Interest & dividends** | Savings interest (product rate, pro-rated) and share dividend runs on average daily or closing balance, with withholding tax, reviewed as drafts and posted to members' savings in resumable batches |
| **Year-end** | Close a financial year (surplus to retained earnings, period locked) and reopen the latest closed year |
| **Teller counter** | Teller home screen: fast member lookup, photo/signature/ID check, balances, one-key actions (F2–F8), today's postings with receipt reprint |
| **Tills & branch vault** | Floats and top-ups released from the vault by a supervisor, cash drops, end-of-day surrender with a denomination count, vault ⇄ bank transfers and vault counts. Shortages and overages post to Cash Over / Short; Cash on Hand always equals vaults + open tills |
| **Counter controls** | ID and signature confirmation on withdrawals, duplicate-posting warning (same amount, same account, within 2 minutes), drawer cash limit |
| **Receipts & reports** | Printable receipts (80 mm roll or A5) with amount in words and signature lines; later prints are stamped REPRINT. Signed end-of-day teller cash report |
| **Approvals** | Maker-checker for large withdrawals, reversals, loan write-offs and large manual journals. A second user must approve, and the maker stays the poster |
| **Imports** | Payroll deduction files (savings, shares, loans) and member migration with opening balances: validated row by row, reviewed, then posted in batches |
| **KYC documents** | Photo, ID front and back, signature, tax PIN and payslip uploads, content-checked and stored privately, with a KYC completeness indicator |
| **Two-step login** | Authenticator-app codes (TOTP) with single-use recovery codes; administrators can reset a lost phone |
| **Backups** | Compressed full-database dumps on demand or nightly, with retention, download and restore instructions |
| **Corrections** | Loan repayments are reversed newest-first: installments reopen exactly and money taken from savings is returned |
| **Security** | bcrypt passwords, login lockout, CSRF on every write, prepared statements only, branch-scoped data access, role-based permissions, idle session timeout |

## Requirements

* PHP 8.1+ with `pdo_mysql` and `mbstring` (XAMPP's PHP is fine)
* MySQL 5.7+ or MariaDB 10.3+
* Internet access for Google Fonts, Bootstrap Icons and Chart.js (loaded from CDNs)

## Installation

1. **Configuration.** Copy `.env.example` to `.env` and set the database connection (`DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`).
   The web installer can also write `.env` for you. Real server environment variables override the file.
   **Hosting on cPanel?** Follow [DEPLOY-CPANEL.md](DEPLOY-CPANEL.md).
2. **Serve the folder.** Pick one:
   * **XAMPP:** copy this folder to `C:\xampp\htdocs\sacco` and open `http://localhost/sacco/`, or
   * **Apache alias** (keeps the code here): add to `C:\xampp\apache\conf\extra\httpd-xampp.conf`
     ```apache
     Alias /sacco "D:/SACCO MANAGEMENT SYSTEM"
     <Directory "D:/SACCO MANAGEMENT SYSTEM">
         AllowOverride All
         Require all granted
     </Directory>
     ```
     then restart Apache and open `http://localhost/sacco/`, or
   * **PHP built-in server** (development): `php -S 127.0.0.1:8077 -t .` and open `http://127.0.0.1:8077`.
3. **Run the installer.** Open `install.php` in the browser, or from the command line:
   ```bash
   php install.php --name="System Administrator" --email=admin@sacco.local --password=Admin@12345 --sacco="My SACCO" --demo=120
   ```
   `--demo=N` (or the checkbox on the web installer) loads N members with 18 months of realistic history.
   The installer refuses to run again once a user exists.

Demo staff accounts created with demo data (password `Password@123`):
`manager@sacco.local`, `teller@sacco.local` (branch-restricted), `loans@sacco.local`, `accounts@sacco.local`.

## Upgrading an existing installation

After pulling new code, run the migration once (it is safe to re-run):

```bash
php database/migrate.php
```

It adds new tables and columns, seeds the chart of accounts, and posts every historical transaction to the general ledger.
Administrators see a banner in the app until it has been run.

## Accounting model

* Every money movement posts one balanced journal automatically. The posting rules live in `app/services/Accounting.php`.
* **Control accounts** (Member Savings, Share Capital, Loans to Members, Internal Transfers Clearing) change only through member transactions, never through manual journals. **Financial statements → Reconciliation** proves they agree with the member records.
* Loan interest and penalties are recognised when paid (cash basis), so Loans to Members always equals outstanding principal.
* Expenses post to the expense account chosen on the expense form. Add new expense accounts under **Chart of Accounts**.
* Closing a year locks every date up to 31 December. Postings into a closed year are refused until it is reopened.

## Nightly job

Marks overdue installments and charges penalties. Schedule it once a day:

```bash
# Linux
5 0 * * *  php /var/www/sacco/cron/daily.php >> /var/www/sacco/storage/logs/cron.log 2>&1
```
```bat
:: Windows
schtasks /create /sc daily /st 00:05 /tn "SaccoPro daily" /tr "C:\xampp\php\php.exe \"D:\SACCO MANAGEMENT SYSTEM\cron\daily.php\""
```
It can also be run on demand from **Settings → Run daily jobs**.

## Nightly backup

```bash
30 1 * * *  php /var/www/sacco/cron/backup.php
```
```bat
schtasks /create /sc daily /st 01:30 /tn "SaccoPro backup" /tr "C:\xampp\php\php.exe \"D:\SACCO MANAGEMENT SYSTEM\cron\backup.php\""
```
Backups go to `storage/backups` (newest 14 kept by default; see **Settings → Controls**). Copy them off the server regularly.
Restore with `gunzip < FILE.sql.gz | mysql -u USER -p sacco_pro`.

## Controls (Settings → Controls)

* **Cash needs an open teller till.** This is on by default. Anyone posting cash must have an open till. A teller requests a float (**Operations → Tills & Vault**) and a supervisor releases it from the branch vault, which opens the till. Mobile, bank and internal postings are not affected.
* **Teller day.** Request float → serve members at the **Teller Counter** → top-up or drop cash as needed → close with a count (the cash goes back to the vault) → print the signed cash report. Supervisors bank excess vault cash, bring cash from the bank and count the vault from the same page.
* **ID check and cash limit.** *Settings → Controls* turns the withdrawal ID/signature confirmation on or off and sets the drawer cash limit (0 = none) above which the teller is told to drop cash.
* **Approval limits.** Withdrawals and manual journals at or above the limit (default KES 100,000) wait for a second user with the `approvals.decide` permission (managers by default). Reversals and write-offs need approval when their switches are on. Set a limit to 0 to switch that check off.
* **Migrated balances.** Member opening savings and shares post against *Opening Balance Equity*. Post your opening bank, cash and loan balances as **Opening balance** journals against the same account, so it nets to zero.

## Built for large SACCOs

* **Server-side everything.** Every list is paginated, sorted and filtered in SQL. The UI never loads a whole table, and member pickers search as you type.
* **Index-friendly search.** Prefix matching (`term%`) on member number, ID, phone, names and email is served from indexes.
* **Covering indexes** on every filter and sort path, e.g. `(member_id, created_at)`, `(type, created_at)` and `(due_date, status)`.
* **Denormalised balances.** Accounts carry their balance, which is updated with `UPDATE … SET balance = balance ± ?` inside a DB transaction with `SELECT … FOR UPDATE` row locks, so concurrent tellers can't double-spend.
* **No hot counters.** Ledger, account and loan numbers are derived from auto-increment IDs. Only member numbers use a sequence row.
* **Streaming exports.** CSV exports use unbuffered queries, so memory stays flat with millions of rows.
* **Cached charts.** The 12-month dashboard series are cached for two minutes (`storage/cache`).
* **Batched jobs.** The penalty job runs in batches of 500 and can safely run more than once.
* **Covering report index.** `idx_txn_report` lets dashboard, statistics and report aggregates scan the index only, never the ledger rows.
* **Load-test data.** `php database/seed_demo.php --members=50000` generates a large dataset.

**Measured** on a laptop with XAMPP's *default* MariaDB settings (16 MB buffer pool), with 15,000 members and 1.2 million ledger rows:

| Operation | Time |
|---|---|
| Member / savings / share / loan lists, any page | 0.03 – 0.4 s |
| Member search (name, number, ID, phone) | ~0.03 s |
| Ledger, last 30 days (26k rows) + totals | ~0.05 s |
| Ledger search by member number | ~0.03 s |
| Dashboard (cached / cold) | 0.14 s / 1.2 s |
| 9-month financial report | ~0.8 s |

**Before going live, size the database server.** XAMPP ships MariaDB with `innodb_buffer_pool_size=16M`, which is far too small for a big SACCO.
In `my.ini` / `my.cnf`, set it to roughly 50–70% of RAM on a dedicated database server (e.g. `innodb_buffer_pool_size=4G`).
Once the ledger passes tens of millions of rows, consider partitioning `transactions` by year.

## Roles

| Role | Typical access |
|---|---|
| Administrator | Everything |
| Manager | Operations, loan approval and disbursement, products, reports |
| Loan officer | Members, loan applications, repayments |
| Teller | Members, deposits, withdrawals, shares, repayments |
| Accountant | Ledger, disbursement, expenses, reports |

Users attached to a branch only see that branch's members, loans and transactions. Users without a branch (head office) see everything and can filter by branch.
Fine-tune permissions in `app/permissions.php`.

## Project structure

```
index.php            page router (index.php?page=…)
api.php              JSON API router (api.php?r=module/action)
login.php · logout.php · install.php
app/
  config.php         reads settings from .env (see .env.example)
  permissions.php    role → permission map
  core/              DB, Auth, Validator, helpers
  services/          Ledger, MemberService, SavingsService, ShareService, LoanService
  api/               one file per module, returning [action => handler]
  views/             layout, shared pop-ups (partials/modals.php), pages/
assets/css/app.css   design system (tokens, light/dark, neon glow)
assets/js/app.js     UI framework (modals, forms, wizard, picker, data tables, toasts, charts)
assets/js/sacco.js   pop-up behaviours (accounts, loan quote, loan details)
database/            schema.sql, DemoSeeder.php, seed_demo.php
cron/daily.php       nightly jobs
storage/             logs and cache (must be writable)
```

## Production checklist

* Set `APP_DEBUG=false` in `.env` so errors are logged to `storage/logs`, not displayed.
* Set `INSTALLER_ENABLED=false` once installed.
* Use a dedicated MySQL user with a strong password; never use `root`.
* Serve over HTTPS. Session cookies switch to `Secure` automatically.
* Make sure `app/`, `database/`, `cron/` and `storage/` are not web-accessible. `.htaccess` files are included for Apache; on Nginx, deny them in the server block.
* Schedule `cron/daily.php` and set up regular database backups.
