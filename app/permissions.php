<?php
/**
 * Role-based permissions. The `admin` role implicitly has every permission.
 * Add a role to a list to grant it that capability.
 */
defined('SACCO') or exit;

return [
    'dashboard.view'       => ['manager', 'loan_officer', 'teller', 'accountant'],

    'members.view'         => ['manager', 'loan_officer', 'teller', 'accountant'],
    'members.manage'       => ['manager', 'loan_officer', 'teller'],
    'members.delete'       => [],

    'savings.view'         => ['manager', 'loan_officer', 'teller', 'accountant'],
    'savings.transact'     => ['manager', 'teller'],
    'savings.products'     => ['manager'],

    'shares.view'          => ['manager', 'loan_officer', 'teller', 'accountant'],
    'shares.transact'      => ['manager', 'teller'],

    'loans.view'           => ['manager', 'loan_officer', 'teller', 'accountant'],
    'loans.apply'          => ['manager', 'loan_officer'],
    'loans.approve'        => ['manager'],
    'loans.disburse'       => ['manager', 'accountant'],
    'loans.repay'          => ['manager', 'loan_officer', 'teller'],
    'loans.products'       => ['manager'],
    'loans.writeoff'       => [],
    'loans.reverse'        => [],

    'transactions.view'    => ['manager', 'teller', 'accountant'],
    'transactions.reverse' => [],

    'expenses.view'        => ['manager', 'accountant'],
    'expenses.manage'      => ['manager', 'accountant'],

    'reports.view'         => ['manager', 'accountant'],

    'accounting.view'      => ['manager', 'accountant'],
    'accounting.journal'   => ['accountant'],
    'accounting.close'     => [],
    'distributions.prepare' => ['manager', 'accountant'],
    'distributions.post'   => ['manager'],

    'tills.use'            => ['manager', 'teller', 'loan_officer', 'accountant'],
    'tills.supervise'      => ['manager'],
    'approvals.decide'     => ['manager'],
    'imports.members'      => ['manager'],
    'imports.checkoff'     => ['manager', 'accountant'],
    'backups.manage'       => [],

    'branches.manage'      => [],
    'users.manage'         => [],
    'settings.manage'      => [],
    'audit.view'           => [],
    'system.jobs'          => ['manager'],
];
