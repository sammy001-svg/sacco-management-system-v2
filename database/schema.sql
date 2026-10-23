-- =====================================================================
--  SaccoPro — SACCO Management System
--  Database schema (MySQL 5.7+ / MariaDB 10.3+)
--
--  Design notes for large SACCOs:
--   * InnoDB everywhere, BIGINT keys on high-volume tables.
--   * Every money movement is an immutable row in `transactions`
--     (corrections are reversals, never edits/deletes).
--   * Account balances are denormalised onto the account rows and are
--     only changed inside DB transactions with row locks (FOR UPDATE).
--   * Indexes cover every list filter / sort used by the UI so pages
--     stay fast with millions of rows.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Organisation
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS branches (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        VARCHAR(20)  NOT NULL,
  name        VARCHAR(120) NOT NULL,
  location    VARCHAR(190) NULL,
  phone       VARCHAR(30)  NULL,
  email       VARCHAR(190) NULL,
  status      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_branches_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  branch_id      INT UNSIGNED NULL COMMENT 'NULL = head office (all branches)',
  name           VARCHAR(120) NOT NULL,
  email          VARCHAR(190) NOT NULL,
  phone          VARCHAR(30)  NULL,
  password_hash  VARCHAR(255) NOT NULL,
  role           ENUM('admin','manager','loan_officer','teller','accountant') NOT NULL DEFAULT 'teller',
  status         ENUM('active','inactive') NOT NULL DEFAULT 'active',
  failed_logins  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until   DATETIME NULL,
  last_login_at  DATETIME NULL,
  totp_secret    VARCHAR(64) NULL,
  totp_enabled   TINYINT(1) NOT NULL DEFAULT 0,
  recovery_codes TEXT NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_branch (branch_id),
  CONSTRAINT fk_users_branch FOREIGN KEY (branch_id) REFERENCES branches (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  `key`       VARCHAR(80) NOT NULL,
  `value`     TEXT NULL,
  updated_at  DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sequences (
  name   VARCHAR(40) NOT NULL,
  value  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Members
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS members (
  id                        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_no                 VARCHAR(30)  NOT NULL,
  branch_id                 INT UNSIGNED NOT NULL,
  first_name                VARCHAR(80)  NOT NULL,
  last_name                 VARCHAR(80)  NOT NULL,
  other_names               VARCHAR(80)  NULL,
  gender                    ENUM('male','female','other') NULL,
  date_of_birth             DATE NULL,
  national_id               VARCHAR(40)  NOT NULL,
  tax_pin                   VARCHAR(30)  NULL,
  phone                     VARCHAR(30)  NOT NULL,
  email                     VARCHAR(190) NULL,
  address                   VARCHAR(255) NULL,
  occupation                VARCHAR(120) NULL,
  employer                  VARCHAR(160) NULL,
  monthly_income            DECIMAL(15,2) NULL,
  next_of_kin_name          VARCHAR(160) NULL,
  next_of_kin_phone         VARCHAR(30)  NULL,
  next_of_kin_relationship  VARCHAR(60)  NULL,
  status                    ENUM('pending','active','dormant','suspended','exited') NOT NULL DEFAULT 'active',
  joined_at                 DATE NOT NULL,
  created_by                INT UNSIGNED NULL,
  created_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_members_no (member_no),
  UNIQUE KEY uq_members_national_id (national_id),
  KEY idx_members_branch_status (branch_id, status),
  KEY idx_members_status (status),
  KEY idx_members_last_name (last_name, first_name),
  KEY idx_members_first_name (first_name),
  KEY idx_members_phone (phone),
  KEY idx_members_email (email),
  KEY idx_members_joined (joined_at),
  CONSTRAINT fk_members_branch FOREIGN KEY (branch_id) REFERENCES branches (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Savings
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS savings_products (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code              VARCHAR(20)  NOT NULL,
  name              VARCHAR(120) NOT NULL,
  description       VARCHAR(255) NULL,
  interest_rate     DECIMAL(6,3)  NOT NULL DEFAULT 0 COMMENT 'annual %',
  min_balance       DECIMAL(15,2) NOT NULL DEFAULT 0,
  allow_withdrawal  TINYINT(1)    NOT NULL DEFAULT 1,
  withdrawal_fee    DECIMAL(15,2) NOT NULL DEFAULT 0,
  is_default        TINYINT(1)    NOT NULL DEFAULT 0,
  status            ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_savings_products_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS savings_accounts (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_no  VARCHAR(30) NULL,
  member_id   BIGINT UNSIGNED NOT NULL,
  product_id  INT UNSIGNED NOT NULL,
  balance     DECIMAL(18,2) NOT NULL DEFAULT 0,
  status      ENUM('active','frozen','closed') NOT NULL DEFAULT 'active',
  opened_at   DATE NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_savings_accounts_no (account_no),
  KEY idx_savings_member (member_id),
  KEY idx_savings_product_status (product_id, status),
  KEY idx_savings_balance (balance),
  CONSTRAINT fk_savings_member  FOREIGN KEY (member_id)  REFERENCES members (id),
  CONSTRAINT fk_savings_product FOREIGN KEY (product_id) REFERENCES savings_products (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Shares
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS share_accounts (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id   BIGINT UNSIGNED NOT NULL,
  shares      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  balance     DECIMAL(18,2) NOT NULL DEFAULT 0 COMMENT 'share capital value',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_share_accounts_member (member_id),
  KEY idx_share_balance (balance),
  CONSTRAINT fk_share_member FOREIGN KEY (member_id) REFERENCES members (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Loans
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS loan_products (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code                 VARCHAR(20)  NOT NULL,
  name                 VARCHAR(120) NOT NULL,
  description          VARCHAR(255) NULL,
  interest_rate        DECIMAL(6,3)  NOT NULL,
  rate_period          ENUM('monthly','annual') NOT NULL DEFAULT 'annual',
  interest_method      ENUM('flat','reducing')  NOT NULL DEFAULT 'reducing',
  min_amount           DECIMAL(15,2) NOT NULL DEFAULT 0,
  max_amount           DECIMAL(15,2) NOT NULL DEFAULT 0,
  min_term             SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  max_term             SMALLINT UNSIGNED NOT NULL DEFAULT 12,
  processing_fee_rate  DECIMAL(6,3) NOT NULL DEFAULT 0 COMMENT '% of principal',
  penalty_rate         DECIMAL(6,3) NOT NULL DEFAULT 0 COMMENT '% of overdue installment',
  savings_multiplier   DECIMAL(5,2) NOT NULL DEFAULT 0 COMMENT '0 = no savings limit',
  min_guarantors       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  status               ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_loan_products_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loans (
  id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  loan_no              VARCHAR(30) NULL,
  member_id            BIGINT UNSIGNED NOT NULL,
  product_id           INT UNSIGNED NOT NULL,
  branch_id            INT UNSIGNED NOT NULL,
  principal            DECIMAL(18,2) NOT NULL,
  interest_rate        DECIMAL(6,3)  NOT NULL,
  rate_period          ENUM('monthly','annual') NOT NULL,
  interest_method      ENUM('flat','reducing')  NOT NULL,
  term_months          SMALLINT UNSIGNED NOT NULL,
  processing_fee       DECIMAL(15,2) NOT NULL DEFAULT 0,
  total_interest       DECIMAL(18,2) NOT NULL DEFAULT 0,
  total_payable        DECIMAL(18,2) NOT NULL DEFAULT 0 COMMENT 'principal + interest',
  installment_amount   DECIMAL(18,2) NOT NULL DEFAULT 0,
  amount_paid          DECIMAL(18,2) NOT NULL DEFAULT 0,
  penalty_accrued      DECIMAL(18,2) NOT NULL DEFAULT 0,
  balance              DECIMAL(18,2) NOT NULL DEFAULT 0 COMMENT 'outstanding incl. penalties',
  purpose              VARCHAR(255) NULL,
  status               ENUM('pending','approved','rejected','active','closed','written_off') NOT NULL DEFAULT 'pending',
  applied_at           DATETIME NOT NULL,
  approved_by          INT UNSIGNED NULL,
  approved_at          DATETIME NULL,
  approval_notes       VARCHAR(255) NULL,
  rejected_by          INT UNSIGNED NULL,
  rejected_at          DATETIME NULL,
  rejection_reason     VARCHAR(255) NULL,
  disbursed_by         INT UNSIGNED NULL,
  disbursed_at         DATETIME NULL,
  disbursement_method  VARCHAR(20) NULL,
  first_due_date       DATE NULL,
  maturity_date        DATE NULL,
  closed_at            DATETIME NULL,
  created_by           INT UNSIGNED NULL,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_loans_no (loan_no),
  KEY idx_loans_member_status (member_id, status),
  KEY idx_loans_status_branch (status, branch_id),
  KEY idx_loans_branch (branch_id),
  KEY idx_loans_product (product_id),
  KEY idx_loans_applied (applied_at),
  KEY idx_loans_disbursed (disbursed_at),
  CONSTRAINT fk_loans_member  FOREIGN KEY (member_id)  REFERENCES members (id),
  CONSTRAINT fk_loans_product FOREIGN KEY (product_id) REFERENCES loan_products (id),
  CONSTRAINT fk_loans_branch  FOREIGN KEY (branch_id)  REFERENCES branches (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loan_schedules (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  loan_id         BIGINT UNSIGNED NOT NULL,
  installment_no  SMALLINT UNSIGNED NOT NULL,
  due_date        DATE NOT NULL,
  principal_due   DECIMAL(18,2) NOT NULL DEFAULT 0,
  interest_due    DECIMAL(18,2) NOT NULL DEFAULT 0,
  penalty_due     DECIMAL(18,2) NOT NULL DEFAULT 0,
  principal_paid  DECIMAL(18,2) NOT NULL DEFAULT 0,
  interest_paid   DECIMAL(18,2) NOT NULL DEFAULT 0,
  penalty_paid    DECIMAL(18,2) NOT NULL DEFAULT 0,
  status          ENUM('pending','partial','paid','overdue') NOT NULL DEFAULT 'pending',
  paid_at         DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_schedule_loan_no (loan_id, installment_no),
  KEY idx_schedule_due_status (due_date, status),
  KEY idx_schedule_status (status),
  CONSTRAINT fk_schedule_loan FOREIGN KEY (loan_id) REFERENCES loans (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loan_guarantors (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  loan_id     BIGINT UNSIGNED NOT NULL,
  member_id   BIGINT UNSIGNED NOT NULL,
  amount      DECIMAL(18,2) NOT NULL,
  status      ENUM('active','released') NOT NULL DEFAULT 'active',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_guarantors_loan (loan_id),
  KEY idx_guarantors_member_status (member_id, status),
  CONSTRAINT fk_guarantors_loan   FOREIGN KEY (loan_id)   REFERENCES loans (id) ON DELETE CASCADE,
  CONSTRAINT fk_guarantors_member FOREIGN KEY (member_id) REFERENCES members (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loan_repayments (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  loan_id         BIGINT UNSIGNED NOT NULL,
  transaction_id  BIGINT UNSIGNED NULL,
  amount          DECIMAL(18,2) NOT NULL,
  principal_paid  DECIMAL(18,2) NOT NULL DEFAULT 0,
  interest_paid   DECIMAL(18,2) NOT NULL DEFAULT 0,
  penalty_paid    DECIMAL(18,2) NOT NULL DEFAULT 0,
  payment_method  VARCHAR(20) NOT NULL,
  payment_ref     VARCHAR(80) NULL,
  status          ENUM('posted','reversed') NOT NULL DEFAULT 'posted',
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_repayments_loan (loan_id, created_at),
  KEY idx_repayments_date (created_at),
  CONSTRAINT fk_repayments_loan FOREIGN KEY (loan_id) REFERENCES loans (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Exactly how each repayment was spread over the schedule (makes reversals exact)
CREATE TABLE IF NOT EXISTS loan_repayment_allocations (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  repayment_id  BIGINT UNSIGNED NOT NULL,
  schedule_id   BIGINT UNSIGNED NOT NULL,
  principal     DECIMAL(18,2) NOT NULL DEFAULT 0,
  interest      DECIMAL(18,2) NOT NULL DEFAULT 0,
  penalty       DECIMAL(18,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_alloc_repayment (repayment_id),
  KEY idx_alloc_schedule (schedule_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- General ledger of every money movement
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS transactions (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  txn_ref         VARCHAR(30) NULL,
  branch_id       INT UNSIGNED NULL,
  member_id       BIGINT UNSIGNED NULL,
  account_type    ENUM('savings','shares','loan','income','expense') NOT NULL,
  account_id      BIGINT UNSIGNED NULL,
  type            ENUM('deposit','withdrawal','share_purchase','share_transfer_in','share_transfer_out',
                       'loan_disbursement','loan_repayment','processing_fee','registration_fee',
                       'withdrawal_fee','penalty','expense','reversal','interest','dividend','write_off') NOT NULL,
  amount          DECIMAL(18,2) NOT NULL,
  units           BIGINT NULL COMMENT 'share units for share transactions',
  balance_after   DECIMAL(18,2) NULL,
  flow           ENUM('in','out','none') NOT NULL DEFAULT 'none' COMMENT 'cash movement from the SACCO perspective',
  payment_method  ENUM('cash','mobile_money','bank','cheque','internal') NOT NULL DEFAULT 'cash',
  payment_ref     VARCHAR(80)  NULL,
  narration       VARCHAR(255) NULL,
  status          ENUM('posted','reversed') NOT NULL DEFAULT 'posted',
  reversal_of     BIGINT UNSIGNED NULL,
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_transactions_ref (txn_ref),
  KEY idx_txn_member_date (member_id, created_at),
  KEY idx_txn_type_date (type, created_at),
  KEY idx_txn_date (created_at),
  KEY idx_txn_account (account_type, account_id, created_at),
  KEY idx_txn_branch_date (branch_id, created_at),
  KEY idx_txn_payment_ref (payment_ref),
  KEY idx_txn_user_date (created_by, created_at),
  -- Covering index: dashboard, statistics and report aggregates over date
  -- ranges are answered from the index alone, never touching table rows.
  KEY idx_txn_report (created_at, status, type, flow, payment_method, branch_id, amount)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Operating expenses
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS expenses (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  branch_id       INT UNSIGNED NOT NULL,
  category        VARCHAR(60)  NOT NULL,
  gl_account_id   INT UNSIGNED NULL,
  payee           VARCHAR(120) NULL,
  amount          DECIMAL(18,2) NOT NULL,
  description     VARCHAR(255) NULL,
  payment_method  VARCHAR(20)  NOT NULL DEFAULT 'cash',
  payment_ref     VARCHAR(80)  NULL,
  expense_date    DATE NOT NULL,
  transaction_id  BIGINT UNSIGNED NULL,
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_expenses_branch_date (branch_id, expense_date),
  KEY idx_expenses_date (expense_date),
  KEY idx_expenses_category (category),
  CONSTRAINT fk_expenses_branch FOREIGN KEY (branch_id) REFERENCES branches (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Double-entry accounting
--   Every row in `transactions` produces one balanced journal entry.
--   Control accounts (savings, share capital, loans) always reconcile to
--   the member sub-ledgers.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS gl_accounts (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code         VARCHAR(20)  NOT NULL,
  name         VARCHAR(120) NOT NULL,
  type         ENUM('asset','liability','equity','income','expense') NOT NULL,
  system_key   VARCHAR(40)  NULL COMMENT 'used by automatic posting rules',
  description  VARCHAR(255) NULL,
  status       ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_gl_code (code),
  UNIQUE KEY uq_gl_system_key (system_key),
  KEY idx_gl_type (type, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS journal_entries (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  entry_no        VARCHAR(30) NULL,
  entry_date      DATE NOT NULL,
  source          ENUM('transaction','manual','opening','interest','dividend','closing') NOT NULL DEFAULT 'transaction',
  transaction_id  BIGINT UNSIGNED NULL,
  branch_id       INT UNSIGNED NULL,
  reference       VARCHAR(80)  NULL,
  narration       VARCHAR(255) NULL,
  total           DECIMAL(18,2) NOT NULL DEFAULT 0,
  status          ENUM('posted','reversed') NOT NULL DEFAULT 'posted',
  reversal_of     BIGINT UNSIGNED NULL,
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_journal_no (entry_no),
  KEY idx_journal_date (entry_date, id),
  KEY idx_journal_source_date (source, entry_date),
  KEY idx_journal_txn (transaction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS journal_lines (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  entry_id    BIGINT UNSIGNED NOT NULL,
  account_id  INT UNSIGNED NOT NULL,
  entry_date  DATE NOT NULL,
  branch_id   INT UNSIGNED NULL,
  debit       DECIMAL(18,2) NOT NULL DEFAULT 0,
  credit      DECIMAL(18,2) NOT NULL DEFAULT 0,
  is_closing  TINYINT(1) NOT NULL DEFAULT 0,
  memo        VARCHAR(255) NULL,
  PRIMARY KEY (id),
  KEY idx_lines_entry (entry_id),
  -- covering index: balances, trial balance and statements never read rows
  KEY idx_lines_account_date (account_id, entry_date, is_closing, branch_id, debit, credit),
  CONSTRAINT fk_lines_entry   FOREIGN KEY (entry_id)   REFERENCES journal_entries (id),
  CONSTRAINT fk_lines_account FOREIGN KEY (account_id) REFERENCES gl_accounts (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS financial_years (
  year              SMALLINT UNSIGNED NOT NULL,
  status            ENUM('open','closed') NOT NULL DEFAULT 'open',
  closing_entry_id  BIGINT UNSIGNED NULL,
  income            DECIMAL(18,2) NULL,
  expenses          DECIMAL(18,2) NULL,
  surplus           DECIMAL(18,2) NULL,
  closed_by         INT UNSIGNED NULL,
  closed_at         DATETIME NULL,
  PRIMARY KEY (year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Savings interest and share dividend runs (computed and posted in batches)
CREATE TABLE IF NOT EXISTS distribution_runs (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind            ENUM('savings_interest','dividend') NOT NULL,
  title           VARCHAR(120) NOT NULL,
  period_from     DATE NOT NULL,
  period_to       DATE NOT NULL,
  method          ENUM('average','closing') NOT NULL DEFAULT 'average',
  rate            DECIMAL(6,3) NULL COMMENT 'dividend % — savings interest uses each product rate',
  product_id      INT UNSIGNED NULL,
  wht_rate        DECIMAL(6,3) NOT NULL DEFAULT 0,
  posting_date    DATE NOT NULL,
  status          ENUM('computing','draft','posting','posted') NOT NULL DEFAULT 'computing',
  cursor_id       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  scanned         INT UNSIGNED NOT NULL DEFAULT 0,
  total_accounts  INT UNSIGNED NOT NULL DEFAULT 0,
  line_count      INT UNSIGNED NOT NULL DEFAULT 0,
  posted_count    INT UNSIGNED NOT NULL DEFAULT 0,
  skipped_count   INT UNSIGNED NOT NULL DEFAULT 0,
  gross           DECIMAL(18,2) NOT NULL DEFAULT 0,
  tax             DECIMAL(18,2) NOT NULL DEFAULT 0,
  net             DECIMAL(18,2) NOT NULL DEFAULT 0,
  created_by      INT UNSIGNED NULL,
  posted_by       INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  posted_at       DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_runs_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS distribution_lines (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_id             INT UNSIGNED NOT NULL,
  member_id          BIGINT UNSIGNED NOT NULL,
  source_account_id  BIGINT UNSIGNED NOT NULL COMMENT 'savings or share account the basis came from',
  credit_account_id  BIGINT UNSIGNED NULL COMMENT 'savings account credited',
  basis              DECIMAL(18,2) NOT NULL,
  rate               DECIMAL(6,3) NOT NULL,
  gross              DECIMAL(18,2) NOT NULL,
  tax                DECIMAL(18,2) NOT NULL DEFAULT 0,
  net                DECIMAL(18,2) NOT NULL,
  status             ENUM('pending','posted','skipped') NOT NULL DEFAULT 'pending',
  transaction_id     BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_dlines_run (run_id, status, id),
  KEY idx_dlines_member (member_id),
  CONSTRAINT fk_dlines_run FOREIGN KEY (run_id) REFERENCES distribution_runs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Operations & controls
-- ---------------------------------------------------------------------

-- Teller cash drawers: opened with a float, closed with a physical count.
CREATE TABLE IF NOT EXISTS till_sessions (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id            INT UNSIGNED NOT NULL,
  branch_id          INT UNSIGNED NULL,
  opened_at          DATETIME NOT NULL,
  opening_float      DECIMAL(18,2) NOT NULL DEFAULT 0,
  status             ENUM('open','closed') NOT NULL DEFAULT 'open',
  closed_at          DATETIME NULL,
  closed_by          INT UNSIGNED NULL,
  expected_cash      DECIMAL(18,2) NULL,
  counted_cash       DECIMAL(18,2) NULL,
  variance           DECIMAL(18,2) NULL,
  denominations      TEXT NULL,
  notes              VARCHAR(255) NULL,
  variance_entry_id  BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_till_user_status (user_id, status),
  KEY idx_till_branch_date (branch_id, opened_at),
  KEY idx_till_opened (opened_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Branch vault (strong room). Cash on Hand in the ledger = vaults + open tills.
CREATE TABLE IF NOT EXISTS vaults (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  branch_id   INT UNSIGNED NOT NULL,
  balance     DECIMAL(18,2) NOT NULL DEFAULT 0,
  updated_at  DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_vault_branch (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every physical cash movement: floats, top-ups, drops, surrenders,
-- bank transfers, vault counts and counter cash taken without a till.
CREATE TABLE IF NOT EXISTS cash_movements (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  branch_id        INT UNSIGNED NOT NULL,
  type             ENUM('float','top_up','drop','surrender','from_bank','to_bank','adjust','direct') NOT NULL,
  amount           DECIMAL(18,2) NOT NULL,
  vault_effect     DECIMAL(18,2) NOT NULL COMMENT '+ into the vault, − out of it',
  vault_after      DECIMAL(18,2) NULL,
  till_session_id  BIGINT UNSIGNED NULL,
  user_id          INT UNSIGNED NULL COMMENT 'teller concerned',
  status           ENUM('pending','completed','rejected','cancelled') NOT NULL DEFAULT 'completed',
  requested_by     INT UNSIGNED NULL,
  approved_by      INT UNSIGNED NULL,
  transaction_id   BIGINT UNSIGNED NULL,
  journal_id       BIGINT UNSIGNED NULL,
  reference        VARCHAR(80)  NULL,
  denominations    TEXT NULL,
  note             VARCHAR(255) NULL,
  created_at       DATETIME NOT NULL,
  completed_at     DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_cash_branch_date (branch_id, created_at),
  KEY idx_cash_status (status, branch_id),
  KEY idx_cash_session (till_session_id, type, status),
  KEY idx_cash_user (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Maker-checker: sensitive actions wait here until a second user approves.
CREATE TABLE IF NOT EXISTS approval_requests (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type          VARCHAR(30)  NOT NULL,
  summary       VARCHAR(255) NOT NULL,
  amount        DECIMAL(18,2) NULL,
  payload       MEDIUMTEXT NOT NULL,
  branch_id     INT UNSIGNED NULL,
  member_id     BIGINT UNSIGNED NULL,
  requested_by  INT UNSIGNED NOT NULL,
  status        ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  decided_by    INT UNSIGNED NULL,
  decided_at    DATETIME NULL,
  note          VARCHAR(255) NULL,
  result        VARCHAR(255) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_approvals_status (status, created_at),
  KEY idx_approvals_requester (requested_by, status),
  KEY idx_approvals_branch (branch_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bulk imports (member migration, payroll check-off): validated, then processed in chunks.
CREATE TABLE IF NOT EXISTS imports (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind            ENUM('members','checkoff') NOT NULL,
  filename        VARCHAR(190) NOT NULL,
  meta            TEXT NULL,
  total_rows      INT UNSIGNED NOT NULL DEFAULT 0,
  valid_rows      INT UNSIGNED NOT NULL DEFAULT 0,
  error_rows      INT UNSIGNED NOT NULL DEFAULT 0,
  processed_rows  INT UNSIGNED NOT NULL DEFAULT 0,
  failed_rows     INT UNSIGNED NOT NULL DEFAULT 0,
  amount          DECIMAL(18,2) NOT NULL DEFAULT 0,
  status          ENUM('validated','processing','completed','cancelled') NOT NULL DEFAULT 'validated',
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at    DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_imports_kind (kind, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS import_rows (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  import_id  INT UNSIGNED NOT NULL,
  row_no     INT UNSIGNED NOT NULL,
  data       TEXT NOT NULL,
  status     ENUM('valid','error','done','failed') NOT NULL,
  message    VARCHAR(255) NULL,
  member_id  BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_import_rows (import_id, status, id),
  CONSTRAINT fk_import_rows FOREIGN KEY (import_id) REFERENCES imports (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- KYC documents and photos (files live in storage/uploads, outside the web root rules).
CREATE TABLE IF NOT EXISTS member_documents (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id      BIGINT UNSIGNED NOT NULL,
  type           VARCHAR(30)  NOT NULL,
  original_name  VARCHAR(190) NOT NULL,
  stored_name    VARCHAR(120) NOT NULL,
  mime           VARCHAR(60)  NOT NULL,
  size           INT UNSIGNED NOT NULL,
  uploaded_by    INT UNSIGNED NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_docs_member (member_id, type),
  CONSTRAINT fk_docs_member FOREIGN KEY (member_id) REFERENCES members (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Audit trail
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_logs (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      INT UNSIGNED NULL,
  action       VARCHAR(60)  NOT NULL,
  entity       VARCHAR(60)  NULL,
  entity_id    BIGINT UNSIGNED NULL,
  description  VARCHAR(255) NULL,
  ip_address   VARCHAR(45)  NULL,
  user_agent   VARCHAR(255) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_date (created_at),
  KEY idx_audit_user_date (user_id, created_at),
  KEY idx_audit_entity (entity, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- Reference data
-- ---------------------------------------------------------------------
INSERT IGNORE INTO sequences (name, value) VALUES ('member', 0);

INSERT IGNORE INTO branches (id, code, name, location, phone, email) VALUES
  (1, 'HQ', 'Head Office', 'Nairobi CBD', '+254 700 000 000', 'hq@sacco.local');

INSERT IGNORE INTO settings (`key`, `value`) VALUES
  ('sacco_name',         'Demo SACCO Ltd'),
  ('sacco_tagline',      'Saving together, growing together'),
  ('sacco_email',        'info@sacco.local'),
  ('sacco_phone',        '+254 700 000 000'),
  ('sacco_address',      'P.O. Box 00000, Nairobi'),
  ('currency',           'KES'),
  ('member_no_prefix',   'MBR'),
  ('share_price',        '100'),
  ('min_share_capital',  '5000'),
  ('registration_fee',   '1000'),
  ('penalty_grace_days', '3'),
  ('wht_interest_rate',  '0'),
  ('wht_dividend_rate',  '0'),
  ('books_closed_through', ''),
  ('require_till', '1'),
  ('approval_withdrawal_limit', '100000'),
  ('approval_journal_limit', '100000'),
  ('approve_reversals', '1'),
  ('approve_write_offs', '1'),
  ('backup_keep', '14'),
  ('till_cash_limit', '500000'),
  ('require_id_check', '1');

INSERT IGNORE INTO savings_products (id, code, name, description, interest_rate, min_balance, allow_withdrawal, withdrawal_fee, is_default) VALUES
  (1, 'ORD', 'Ordinary Savings', 'Main member deposits account. Used for loan eligibility.', 4.000, 500.00, 1, 50.00, 1),
  (2, 'HOL', 'Holiday Savings',  'Goal-based savings for holidays and festive seasons.',     6.000,   0.00, 1, 0.00,  0),
  (3, 'FXD', 'Fixed Deposit',    'Locked term deposit earning a premium rate.',              9.500,   0.00, 0, 0.00,  0);

INSERT IGNORE INTO loan_products (id, code, name, description, interest_rate, rate_period, interest_method, min_amount, max_amount, min_term, max_term, processing_fee_rate, penalty_rate, savings_multiplier, min_guarantors) VALUES
  (1, 'NRM', 'Normal Loan',      'Development and personal needs at a reducing-balance rate.', 12.000, 'annual',  'reducing', 10000,  3000000, 3, 48, 1.000, 5.000, 3.00, 2),
  (2, 'EMG', 'Emergency Loan',   'Quick cash for medical bills and urgent needs.',              1.500, 'monthly', 'flat',      2000,   200000, 1, 12, 0.500, 5.000, 1.00, 0),
  (3, 'SCH', 'School Fees Loan', 'Education financing timed around the school calendar.',      10.000, 'annual',  'reducing',  5000,   500000, 1, 12, 0.500, 3.000, 2.00, 1),
  (4, 'ASSET','Asset Finance',   'Finance land, vehicles and equipment over a longer term.',   14.000, 'annual',  'reducing', 50000, 10000000, 6, 72, 1.500, 5.000, 4.00, 3);
