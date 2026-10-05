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
| **Security** | bcrypt passwords, login lockout, CSRF on every write, prepared statements only, branch-scoped data access, role-based permissions, idle session timeout |

## Requirements

* PHP 8.1+ with `pdo_mysql` and `mbstring` (XAMPP's PHP is fine)
* MySQL 5.7+ or MariaDB 10.3+
* Internet access for Google Fonts, Bootstrap Icons and Chart.js (loaded from CDNs)

## Installation

1. **Database connection.** Edit `app/config.php` (defaults: `127.0.0.1`, user `root`, no password, database `sacco_pro`), or set the
   environment variables `SACCO_DB_HOST`, `SACCO_DB_NAME`, `SACCO_DB_USER` and `SACCO_DB_PASS`.
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
  config.php         settings and DB credentials
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

* Set `SACCO_DEBUG=0` (or `'debug' => false` in `app/config.php`) so errors are logged, not displayed.
* Use a dedicated MySQL user with a strong password; never use `root`.
* Serve over HTTPS. Session cookies switch to `Secure` automatically.
* Make sure `app/`, `database/`, `cron/` and `storage/` are not web-accessible. `.htaccess` files are included for Apache; on Nginx, deny them in the server block.
* Schedule `cron/daily.php` and set up regular database backups.
