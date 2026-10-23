<?php
/**
 * Page front controller:  index.php?page=<name>
 */
require __DIR__ . '/app/bootstrap.php';

if (!is_installed()) {
    redirect('install.php');
}
if (!Auth::check()) {
    redirect('login.php');
}

// name => [title, permission, nav key, uses layout]
$pages = [
    'dashboard'     => ['Dashboard', 'dashboard.view'],
    'members'       => ['Members', 'members.view'],
    'member'        => ['Member Profile', 'members.view', 'members'],
    'savings'       => ['Savings', 'savings.view'],
    'shares'        => ['Share Capital', 'shares.view'],
    'loans'         => ['Loans', 'loans.view'],
    'loan_products' => ['Loan Products', 'loans.view'],
    'transactions'  => ['Transactions', 'transactions.view'],
    'expenses'      => ['Expenses', 'expenses.view'],
    'reports'       => ['Reports', 'reports.view'],
    'accounts'      => ['Chart of Accounts', 'accounting.view'],
    'journals'      => ['Journal Entries', 'accounting.view'],
    'statements'    => ['Financial Statements', 'accounting.view'],
    'distributions' => ['Interest & Dividends', 'distributions.prepare'],
    'year_end'      => ['Year-End Close', 'accounting.view'],
    'counter'       => ['Teller Counter', 'tills.use'],
    'tills'         => ['Teller Tills', 'tills.use'],
    'till_report'   => ['Till Report', 'tills.use', 'tills', false],
    'receipt'       => ['Receipt', 'tills.use', 'transactions', false],
    'approvals'     => ['Approvals', 'dashboard.view'],
    'imports'       => ['Imports', 'imports.checkoff'],
    'backups'       => ['Backups', 'backups.manage'],
    'branches'      => ['Branches', 'branches.manage'],
    'users'         => ['Users & Roles', 'users.manage'],
    'settings'      => ['Settings', 'settings.manage'],
    'audit'         => ['Audit Trail', 'audit.view'],
    'statement'     => ['Member Statement', 'members.view', 'members', false],
];

// Tellers land on their counter; everyone else on the dashboard.
$page = (string) ($_GET['page'] ?? (Auth::role() === 'teller' ? 'counter' : 'dashboard'));
$status = 200;

if (!isset($pages[$page])) {
    [$page, $status, $def] = ['error', 404, ['Page not found', null]];
} else {
    $def = $pages[$page];
    if (!Auth::can($def[1])) {
        [$page, $status, $def] = ['error', 403, ['Access denied', null]];
    }
}

http_response_code($status);
$pageTitle = $def[0];
$activeNav = $def[2] ?? $page;
$useLayout = $def[3] ?? true;
$pageScripts = '';
$needsCharts = false;

try {
    ob_start();
    require APP_PATH . "/views/pages/$page.php";
    $content = ob_get_clean();
} catch (AppError $e) {
    ob_end_clean();
    http_response_code($status = $e->status);
    $pageTitle = $e->status === 404 ? 'Not found' : 'Error';
    ob_start();
    $errorMessage = $e->getMessage();
    require APP_PATH . '/views/pages/error.php';
    $content = ob_get_clean();
    $useLayout = true;
}

if ($useLayout) {
    require APP_PATH . '/views/layout.php';
} else {
    echo $content;
}
